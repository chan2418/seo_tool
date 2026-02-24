<?php

require_once __DIR__ . '/../models/TrackedKeywordModel.php';
require_once __DIR__ . '/../models/RankingModel.php';
require_once __DIR__ . '/RankingFetchService.php';
require_once __DIR__ . '/AlertDetectionService.php';
require_once __DIR__ . '/PlanEnforcementService.php';
require_once __DIR__ . '/UsageMonitoringService.php';

class RankTrackerService
{
    private const PLAN_LIMITS = [
        'free' => 5,
        'pro' => 25,
        'agency' => 100,
    ];

    private const MAX_ADD_REQUESTS_PER_MINUTE = 20;
    private const MAX_RUN_REQUESTS_PER_MINUTE = 4;
    private const MAX_KEYWORD_LENGTH = 100;
    private const MAX_ROWS_PER_REQUEST = 500;

    private TrackedKeywordModel $trackedKeywordModel;
    private RankingModel $rankingModel;
    private RankingFetchService $rankingFetchService;
    private PlanEnforcementService $planEnforcementService;
    private UsageMonitoringService $usageMonitoringService;

    public function __construct(
        ?TrackedKeywordModel $trackedKeywordModel = null,
        ?RankingModel $rankingModel = null,
        ?RankingFetchService $rankingFetchService = null,
        ?PlanEnforcementService $planEnforcementService = null,
        ?UsageMonitoringService $usageMonitoringService = null
    ) {
        $this->trackedKeywordModel = $trackedKeywordModel ?? new TrackedKeywordModel();
        $this->rankingModel = $rankingModel ?? new RankingModel();
        $this->rankingFetchService = $rankingFetchService ?? new RankingFetchService();
        $this->planEnforcementService = $planEnforcementService ?? new PlanEnforcementService();
        $this->usageMonitoringService = $usageMonitoringService ?? new UsageMonitoringService();
    }

    public function getTrackerData(int $userId, string $planType, array $filters = []): array
    {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        $planType = $this->normalizePlanType($planType);
        $access = $this->planEnforcementService->assertFeatureAccess($userId, 'rank_tracker');
        if (empty($access['allowed'])) {
            return $this->errorResponse('FEATURE_LOCKED', 403, (string) ($access['message'] ?? 'Feature not available.'));
        }
        $apiLimitCheck = $this->planEnforcementService->assertDailyApiLimit($userId, 'api_call.rank_tracker_load', 1);
        if (empty($apiLimitCheck['allowed'])) {
            return $this->errorResponse('API_LIMIT', 429, (string) ($apiLimitCheck['message'] ?? 'Daily API limit reached.'));
        }

        $projects = $this->trackedKeywordModel->getProjects($userId);

        $selectedProjectId = isset($filters['project_id']) ? (int) $filters['project_id'] : 0;
        $projectIds = array_map(static fn (array $project): int => (int) ($project['id'] ?? 0), $projects);
        if ($selectedProjectId <= 0 || !in_array($selectedProjectId, $projectIds, true)) {
            $selectedProjectId = $projectIds[0] ?? 0;
        }

        $statusFilter = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        if (!in_array($statusFilter, ['active', 'paused'], true)) {
            $statusFilter = null;
        }

        $sortBy = strtolower(trim((string) ($filters['sort_by'] ?? 'current_rank')));
        $sortDir = strtolower(trim((string) ($filters['sort_dir'] ?? 'asc')));
        if (!in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'asc';
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(25, (int) ($filters['per_page'] ?? 10)));

        if ($selectedProjectId <= 0) {
            return [
                'success' => true,
                'projects' => [],
                'selected_project_id' => null,
                'keywords' => [],
                'pagination' => [
                    'page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'total_pages' => 0,
                    'has_next' => false,
                    'has_prev' => false,
                ],
                'summary' => $this->emptySummary(),
                'limits' => [
                    'plan_type' => $planType,
                    'keyword_limit' => $this->planKeywordLimit($planType),
                    'used_keywords' => 0,
                ],
                'filters' => $this->buildFilterMeta($sortBy, $sortDir, $statusFilter),
                'alerts' => [],
                'countries' => $this->countryOptions(),
                'devices' => ['desktop', 'mobile'],
            ];
        }

        $trackedKeywords = $this->trackedKeywordModel->getTrackedKeywords($userId, $selectedProjectId, $statusFilter);
        if (count($trackedKeywords) > self::MAX_ROWS_PER_REQUEST) {
            $trackedKeywords = array_slice($trackedKeywords, 0, self::MAX_ROWS_PER_REQUEST);
        }

        $keywordIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $trackedKeywords);
        $latestPreviousMap = $this->rankingModel->getLatestAndPreviousByKeywordIds($keywordIds);
        $bestRankMap = $this->rankingModel->getBestRanksByKeywordIds($keywordIds);

        $rows = [];
        foreach ($trackedKeywords as $keywordRow) {
            $trackedId = (int) ($keywordRow['id'] ?? 0);
            $latest = $latestPreviousMap[$trackedId]['latest'] ?? null;
            $previous = $latestPreviousMap[$trackedId]['previous'] ?? null;

            $currentRank = (int) ($latest['rank_position'] ?? 101);
            $previousRank = $previous ? (int) ($previous['rank_position'] ?? 101) : null;
            $change = $previousRank !== null ? ($previousRank - $currentRank) : 0;

            $rows[] = [
                'id' => $trackedId,
                'project_id' => (int) ($keywordRow['project_id'] ?? 0),
                'keyword' => (string) ($keywordRow['keyword'] ?? ''),
                'country' => strtoupper((string) ($keywordRow['country'] ?? 'US')),
                'device_type' => strtolower((string) ($keywordRow['device_type'] ?? 'desktop')),
                'status' => (string) ($keywordRow['status'] ?? 'active'),
                'current_rank' => $currentRank,
                'current_rank_label' => $currentRank <= 100 ? (string) $currentRank : '100+',
                'previous_rank' => $previousRank,
                'change' => $change,
                'change_direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
                'best_rank' => (int) ($bestRankMap[$trackedId] ?? $currentRank),
                'search_volume' => $this->estimateSearchVolume((string) ($keywordRow['keyword'] ?? '')),
                'last_updated' => (string) ($latest['checked_date'] ?? $keywordRow['created_at'] ?? ''),
                'source' => (string) ($latest['source'] ?? 'pending'),
                'project_name' => (string) ($keywordRow['project_name'] ?? ''),
                'project_domain' => (string) ($keywordRow['project_domain'] ?? ''),
            ];
        }

        $rows = $this->sortRows($rows, $sortBy, $sortDir);
        $total = count($rows);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        $pagedRows = array_slice($rows, $offset, $perPage);
        $summary = $this->buildSummary($rows);

        $this->usageMonitoringService->logApiCall($userId, 'rank_tracker_load', $selectedProjectId > 0 ? $selectedProjectId : null);

        return [
            'success' => true,
            'projects' => $projects,
            'selected_project_id' => $selectedProjectId,
            'keywords' => $pagedRows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1,
            ],
            'summary' => $summary,
            'limits' => [
                'plan_type' => $planType,
                'keyword_limit' => $this->planKeywordLimit($planType),
                'used_keywords' => $this->trackedKeywordModel->countTrackedKeywordsForProject($userId, $selectedProjectId),
            ],
            'filters' => $this->buildFilterMeta($sortBy, $sortDir, $statusFilter),
            'alerts' => $this->rankingModel->getRecentAlerts($userId, 8),
            'countries' => $this->countryOptions(),
            'devices' => ['desktop', 'mobile'],
        ];
    }

    public function addKeyword(int $userId, string $planType, array $input): array
    {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        $planType = $this->normalizePlanType($planType);
        $access = $this->planEnforcementService->assertFeatureAccess($userId, 'rank_tracker');
        if (empty($access['allowed'])) {
            return $this->errorResponse('FEATURE_LOCKED', 403, (string) ($access['message'] ?? 'Feature not available.'));
        }
        if ($this->trackedKeywordModel->countRecentRequests($userId, 'rank_tracker_add', 60) >= self::MAX_ADD_REQUESTS_PER_MINUTE) {
            return $this->errorResponse('RATE_LIMIT', 429, 'Too many keyword updates. Please wait one minute.');
        }

        $apiLimitCheck = $this->planEnforcementService->assertDailyApiLimit($userId, 'api_call.rank_tracker_add', 1);
        if (empty($apiLimitCheck['allowed'])) {
            return $this->errorResponse('API_LIMIT', 429, (string) ($apiLimitCheck['message'] ?? 'Daily API usage limit reached.'));
        }

        $projectId = (int) ($input['project_id'] ?? 0);
        $project = $this->trackedKeywordModel->getProjectById($userId, $projectId);
        if (!$project) {
            return $this->errorResponse('PROJECT_NOT_FOUND', 404, 'Please select a valid project.');
        }

        $keyword = $this->sanitizeKeyword((string) ($input['keyword'] ?? ''));
        if (!$this->isValidKeyword($keyword)) {
            return $this->errorResponse('VALIDATION_ERROR', 422, 'Keyword must be 2-100 chars and use letters, numbers, spaces, or hyphen.');
        }

        $country = strtoupper((string) ($input['country'] ?? 'US'));
        if (!isset($this->countryOptions()[$country])) {
            $country = 'US';
        }

        $deviceType = strtolower((string) ($input['device_type'] ?? 'desktop'));
        if (!in_array($deviceType, ['desktop', 'mobile'], true)) {
            $deviceType = 'desktop';
        }

        $limitCheck = $this->planEnforcementService->assertKeywordLimit($userId, 1);
        $limit = (int) ($limitCheck['limit'] ?? $this->planKeywordLimit($planType));
        $used = (int) ($limitCheck['used'] ?? count($this->trackedKeywordModel->getTrackedKeywords($userId)));
        if (empty($limitCheck['allowed'])) {
            return [
                'success' => false,
                'status' => 403,
                'error_code' => 'PLAN_LIMIT_REACHED',
                'error' => 'Keyword limit reached for this project.',
                'upgrade_required' => true,
                'limits' => [
                    'plan_type' => $planType,
                    'keyword_limit' => $limit,
                    'used_keywords' => $used,
                ],
            ];
        }

        $added = $this->trackedKeywordModel->addTrackedKeyword(
            $userId,
            $projectId,
            $keyword,
            $country,
            $deviceType,
            'active'
        );

        if (empty($added['success'])) {
            $status = ($added['error_code'] ?? '') === 'DUPLICATE_KEYWORD' ? 409 : 422;
            return $this->errorResponse((string) ($added['error_code'] ?? 'ADD_FAILED'), $status, (string) ($added['error'] ?? 'Unable to add keyword.'));
        }

        $keywordRow = (array) ($added['keyword'] ?? []);
        $this->trackedKeywordModel->logRequest($userId, 'rank_tracker_add', $keyword . '|' . $projectId, 'add', 200);

        $check = $this->runSingleKeywordCheck($userId, $keywordRow, date('Y-m-d'));
        $this->usageMonitoringService->logKeywordAdded($userId, $projectId);
        $this->usageMonitoringService->logApiCall($userId, 'rank_tracker_add', $projectId);

        return [
            'success' => true,
            'message' => 'Keyword added to tracker.',
            'keyword' => $keywordRow,
            'initial_check' => $check,
        ];
    }

    public function deleteKeyword(int $userId, int $trackedKeywordId): array
    {
        $keywordRow = $this->trackedKeywordModel->getTrackedKeywordById($userId, $trackedKeywordId);
        if (!$keywordRow) {
            return $this->errorResponse('NOT_FOUND', 404, 'Tracked keyword not found.');
        }

        $deleted = $this->trackedKeywordModel->deleteTrackedKeyword($userId, $trackedKeywordId);
        if (!$deleted) {
            return $this->errorResponse('DELETE_FAILED', 500, 'Unable to delete tracked keyword.');
        }

        $this->rankingModel->deleteByTrackedKeywordId($trackedKeywordId);
        $this->trackedKeywordModel->logRequest($userId, 'rank_tracker_delete', (string) $trackedKeywordId, 'delete', 200);

        return [
            'success' => true,
            'message' => 'Tracked keyword deleted.',
        ];
    }

    public function setKeywordStatus(int $userId, int $trackedKeywordId, string $status): array
    {
        $updated = $this->trackedKeywordModel->updateTrackedKeywordStatus($userId, $trackedKeywordId, $status);
        if (!$updated) {
            return $this->errorResponse('UPDATE_FAILED', 422, 'Unable to update keyword status.');
        }

        $this->trackedKeywordModel->logRequest($userId, 'rank_tracker_status', (string) $trackedKeywordId, 'status', 200);
        return [
            'success' => true,
            'message' => 'Keyword status updated.',
        ];
    }

    public function getKeywordHistory(int $userId, int $trackedKeywordId, int $days = 30): array
    {
        $keywordRow = $this->trackedKeywordModel->getTrackedKeywordById($userId, $trackedKeywordId);
        if (!$keywordRow) {
            return $this->errorResponse('NOT_FOUND', 404, 'Tracked keyword not found.');
        }

        $history = $this->rankingModel->getHistory($trackedKeywordId, $days);
        $best = 999;
        foreach ($history as $entry) {
            $best = min($best, (int) ($entry['rank_position'] ?? 999));
        }
        if ($best === 999) {
            $best = 101;
        }

        return [
            'success' => true,
            'keyword' => [
                'id' => (int) ($keywordRow['id'] ?? 0),
                'keyword' => (string) ($keywordRow['keyword'] ?? ''),
                'project_name' => (string) ($keywordRow['project_name'] ?? ''),
                'project_domain' => (string) ($keywordRow['project_domain'] ?? ''),
            ],
            'history' => $history,
            'best_rank' => $best <= 100 ? $best : 101,
        ];
    }

    public function runDailyChecks(int $userId, string $planType, array $options = []): array
    {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        $planType = $this->normalizePlanType($planType);
        $access = $this->planEnforcementService->assertFeatureAccess($userId, 'rank_tracker');
        if (empty($access['allowed'])) {
            return $this->errorResponse('FEATURE_LOCKED', 403, (string) ($access['message'] ?? 'Feature not available.'));
        }
        $projectId = isset($options['project_id']) ? (int) $options['project_id'] : null;
        if ($projectId !== null && $projectId <= 0) {
            $projectId = null;
        }
        $force = !empty($options['force']);
        $limit = max(1, min(500, (int) ($options['limit'] ?? 200)));
        $checkedDate = isset($options['checked_date']) ? (string) $options['checked_date'] : date('Y-m-d');

        if (empty($options['from_cron']) && $this->trackedKeywordModel->countRecentRequests($userId, 'rank_tracker_run', 60) >= self::MAX_RUN_REQUESTS_PER_MINUTE) {
            return $this->errorResponse('RATE_LIMIT', 429, 'Too many rank check requests. Please wait one minute.');
        }

        if (empty($options['from_cron'])) {
            $apiLimitCheck = $this->planEnforcementService->assertDailyApiLimit($userId, 'api_call.rank_tracker_run', 1);
            if (empty($apiLimitCheck['allowed'])) {
                return $this->errorResponse('API_LIMIT', 429, (string) ($apiLimitCheck['message'] ?? 'Daily API usage limit reached.'));
            }
        }

        $statusFilter = 'active';
        $trackedKeywords = $this->trackedKeywordModel->getTrackedKeywords($userId, $projectId, $statusFilter);
        if (empty($trackedKeywords)) {
            return [
                'success' => true,
                'processed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'checked_date' => $checkedDate,
                'message' => 'No active tracked keywords found.',
            ];
        }

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($trackedKeywords as $keywordRow) {
            if (($processed + $failed) >= $limit) {
                break;
            }

            $trackedKeywordId = (int) ($keywordRow['id'] ?? 0);
            if ($trackedKeywordId <= 0) {
                $failed++;
                continue;
            }

            if (!$force && $this->rankingModel->hasRankingForDate($trackedKeywordId, $checkedDate)) {
                $skipped++;
                continue;
            }

            $save = $this->runSingleKeywordCheck($userId, $keywordRow, $checkedDate);
            if (!empty($save['success'])) {
                $processed++;
            } else {
                $failed++;
            }
        }

        $this->trackedKeywordModel->logRequest($userId, 'rank_tracker_run', (string) ($projectId ?? 'all'), 'daily_check', 200);

        if ($processed > 0 || !empty($options['from_cron'])) {
            $this->triggerAlertMonitoring($userId, $planType);
        }

        return [
            'success' => true,
            'processed' => $processed,
            'skipped' => $skipped,
            'failed' => $failed,
            'checked_date' => $checkedDate,
            'plan_type' => $planType,
        ];
    }

    public function runScheduledDailyChecksForAllUsers(int $limitPerUser = 250): array
    {
        $userIds = $this->trackedKeywordModel->getUsersWithActiveTrackedKeywords();
        $summary = [
            'success' => true,
            'checked_date' => date('Y-m-d'),
            'users_total' => count($userIds),
            'users_processed' => 0,
            'processed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($userIds as $userId) {
            $planType = $this->planEnforcementService->getEffectivePlan((int) $userId, $this->trackedKeywordModel->getUserPlanType((int) $userId));
            $result = $this->runDailyChecks((int) $userId, $planType, [
                'limit' => $limitPerUser,
                'from_cron' => true,
            ]);

            if (!empty($result['success'])) {
                $summary['users_processed']++;
                $summary['processed'] += (int) ($result['processed'] ?? 0);
                $summary['skipped'] += (int) ($result['skipped'] ?? 0);
                $summary['failed'] += (int) ($result['failed'] ?? 0);
            } else {
                $summary['failed']++;
            }

            $summary['details'][] = [
                'user_id' => (int) $userId,
                'plan_type' => $planType,
                'result' => $result,
            ];
        }

        return $summary;
    }

    private function runSingleKeywordCheck(int $userId, array $keywordRow, string $checkedDate): array
    {
        $trackedKeywordId = (int) ($keywordRow['id'] ?? 0);
        $keyword = (string) ($keywordRow['keyword'] ?? '');
        $projectDomain = (string) ($keywordRow['project_domain'] ?? '');
        $country = (string) ($keywordRow['country'] ?? 'US');
        $device = (string) ($keywordRow['device_type'] ?? 'desktop');

        if ($trackedKeywordId <= 0 || $keyword === '' || $projectDomain === '') {
            return [
                'success' => false,
                'error' => 'Invalid tracked keyword row.',
            ];
        }

        $beforeMap = $this->rankingModel->getLatestAndPreviousByKeywordIds([$trackedKeywordId]);
        $previousLatest = $beforeMap[$trackedKeywordId]['latest'] ?? null;

        $fetch = $this->rankingFetchService->fetchKeywordRank(
            $keyword,
            $projectDomain,
            $country,
            $device,
            $userId,
            $checkedDate
        );

        if (empty($fetch['success'])) {
            return [
                'success' => false,
                'error' => (string) ($fetch['error'] ?? 'Failed to fetch rank'),
            ];
        }

        $currentRank = (int) ($fetch['rank_position'] ?? 101);
        $saveSuccess = $this->rankingModel->saveDailyRanking(
            $trackedKeywordId,
            $currentRank,
            $checkedDate,
            (string) ($fetch['source'] ?? 'simulated'),
            [
                'checked_results' => (int) ($fetch['checked_results'] ?? 0),
                'found_url' => (string) ($fetch['found_url'] ?? ''),
                'found' => !empty($fetch['found']),
            ]
        );

        if (!$saveSuccess) {
            return [
                'success' => false,
                'error' => 'Unable to save ranking result.',
            ];
        }

        $previousRank = $previousLatest ? (int) ($previousLatest['rank_position'] ?? 101) : null;
        if ($previousRank !== null) {
            $this->evaluateAlerts($userId, $keywordRow, $previousRank, $currentRank);
        }

        return [
            'success' => true,
            'rank_position' => $currentRank,
            'source' => (string) ($fetch['source'] ?? 'simulated'),
            'checked_date' => $checkedDate,
        ];
    }

    private function evaluateAlerts(int $userId, array $keywordRow, int $previousRank, int $currentRank): void
    {
        $keyword = (string) ($keywordRow['keyword'] ?? 'Keyword');
        $projectName = (string) ($keywordRow['project_name'] ?? 'Project');
        $projectId = (int) ($keywordRow['project_id'] ?? 0);
        $trackedKeywordId = (int) ($keywordRow['id'] ?? 0);
        if ($projectId <= 0 || $trackedKeywordId <= 0) {
            return;
        }

        if ($previousRank <= 100 && $currentRank <= 100 && ($currentRank - $previousRank) >= 10) {
            $this->rankingModel->createAlert(
                $userId,
                $projectId,
                $trackedKeywordId,
                'drop_10',
                $keyword . ' dropped sharply for ' . $projectName . ' (' . $previousRank . ' to ' . $currentRank . ').',
                $previousRank,
                $currentRank
            );
        }

        if ($previousRank > 10 && $currentRank <= 10) {
            $this->rankingModel->createAlert(
                $userId,
                $projectId,
                $trackedKeywordId,
                'entered_top10',
                $keyword . ' entered Top 10 for ' . $projectName . '.',
                $previousRank,
                $currentRank
            );
        }

        if ($previousRank <= 50 && $currentRank > 50) {
            $this->rankingModel->createAlert(
                $userId,
                $projectId,
                $trackedKeywordId,
                'out_top50',
                $keyword . ' fell out of Top 50 for ' . $projectName . '.',
                $previousRank,
                $currentRank
            );
        }
    }

    private function buildSummary(array $rows): array
    {
        if (empty($rows)) {
            return $this->emptySummary();
        }

        $total = count($rows);
        $sum = 0;
        $lastUpdated = '';
        $gainers = [];
        $losers = [];
        $distribution = [
            'top3' => 0,
            'top10' => 0,
            'top50' => 0,
            'above50' => 0,
        ];

        foreach ($rows as $row) {
            $rank = (int) ($row['current_rank'] ?? 101);
            $normalizedRank = $rank <= 100 ? $rank : 101;
            $sum += $normalizedRank;

            if ($normalizedRank <= 3) {
                $distribution['top3']++;
            }
            if ($normalizedRank <= 10) {
                $distribution['top10']++;
            }
            if ($normalizedRank <= 50) {
                $distribution['top50']++;
            } else {
                $distribution['above50']++;
            }

            $change = (int) ($row['change'] ?? 0);
            if ($change > 0) {
                $gainers[] = $row;
            } elseif ($change < 0) {
                $losers[] = $row;
            }

            $rowUpdated = (string) ($row['last_updated'] ?? '');
            if ($rowUpdated !== '' && $rowUpdated > $lastUpdated) {
                $lastUpdated = $rowUpdated;
            }
        }

        usort($gainers, static fn (array $a, array $b): int => ((int) ($b['change'] ?? 0)) <=> ((int) ($a['change'] ?? 0)));
        usort($losers, static fn (array $a, array $b): int => ((int) ($a['change'] ?? 0)) <=> ((int) ($b['change'] ?? 0)));

        $gainers = array_slice(array_map(function (array $row): array {
            return [
                'keyword' => (string) ($row['keyword'] ?? ''),
                'change' => (int) ($row['change'] ?? 0),
                'current_rank' => (string) ($row['current_rank_label'] ?? '100+'),
            ];
        }, $gainers), 0, 5);

        $losers = array_slice(array_map(function (array $row): array {
            return [
                'keyword' => (string) ($row['keyword'] ?? ''),
                'change' => (int) ($row['change'] ?? 0),
                'current_rank' => (string) ($row['current_rank_label'] ?? '100+'),
            ];
        }, $losers), 0, 5);

        return [
            'total_tracked_keywords' => $total,
            'average_position' => (float) round($sum / max(1, $total), 1),
            'top_gainers' => $gainers,
            'top_losers' => $losers,
            'distribution' => $distribution,
            'last_updated' => $lastUpdated,
        ];
    }

    private function sortRows(array $rows, string $sortBy, string $sortDir): array
    {
        $allowedSort = ['keyword', 'current_rank', 'change', 'best_rank', 'search_volume', 'last_updated'];
        if (!in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'current_rank';
        }

        usort($rows, static function (array $a, array $b) use ($sortBy, $sortDir): int {
            $valueA = $a[$sortBy] ?? null;
            $valueB = $b[$sortBy] ?? null;

            if ($sortBy === 'keyword') {
                $cmp = strcasecmp((string) $valueA, (string) $valueB);
            } elseif ($sortBy === 'last_updated') {
                $cmp = strcmp((string) $valueA, (string) $valueB);
            } else {
                $cmp = (float) $valueA <=> (float) $valueB;
            }

            if ($cmp === 0) {
                $cmp = strcasecmp((string) ($a['keyword'] ?? ''), (string) ($b['keyword'] ?? ''));
            }

            return $sortDir === 'desc' ? ($cmp * -1) : $cmp;
        });

        return $rows;
    }

    private function buildFilterMeta(string $sortBy, string $sortDir, ?string $statusFilter): array
    {
        return [
            'sort_by' => $sortBy,
            'sort_dir' => $sortDir,
            'status' => $statusFilter,
        ];
    }

    private function emptySummary(): array
    {
        return [
            'total_tracked_keywords' => 0,
            'average_position' => 0,
            'top_gainers' => [],
            'top_losers' => [],
            'distribution' => [
                'top3' => 0,
                'top10' => 0,
                'top50' => 0,
                'above50' => 0,
            ],
            'last_updated' => '',
        ];
    }

    private function sanitizeKeyword(string $keyword): string
    {
        $keyword = trim($keyword);
        $keyword = preg_replace('/[^a-zA-Z0-9\s\-]/', ' ', $keyword);
        $keyword = preg_replace('/\s+/', ' ', (string) $keyword);
        return trim((string) $keyword);
    }

    private function isValidKeyword(string $keyword): bool
    {
        if ($keyword === '') {
            return false;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($keyword) : strlen($keyword);
        if ($length < 2 || $length > self::MAX_KEYWORD_LENGTH) {
            return false;
        }

        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\s\-]*$/', $keyword);
    }

    private function estimateSearchVolume(string $keyword): int
    {
        $seed = abs(crc32(strtolower(trim($keyword))));
        return 80 + ($seed % 85000);
    }

    private function planKeywordLimit(string $planType): int
    {
        return self::PLAN_LIMITS[$planType] ?? self::PLAN_LIMITS['free'];
    }

    private function normalizePlanType(string $planType): string
    {
        $planType = strtolower(trim($planType));
        if (!isset(self::PLAN_LIMITS[$planType])) {
            return 'free';
        }

        return $planType;
    }

    private function countryOptions(): array
    {
        return [
            'US' => 'United States',
            'IN' => 'India',
            'GB' => 'United Kingdom',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'SG' => 'Singapore',
            'AE' => 'UAE',
        ];
    }

    private function errorResponse(string $code, int $status, string $message): array
    {
        return [
            'success' => false,
            'status' => $status,
            'error_code' => $code,
            'error' => $message,
        ];
    }

    private function triggerAlertMonitoring(int $userId, string $planType): void
    {
        try {
            $detector = new AlertDetectionService();
            $detector->runForUser($userId, $planType);
        } catch (Throwable $error) {
            error_log('RankTracker alert trigger failed: ' . $error->getMessage());
        }
    }
}
