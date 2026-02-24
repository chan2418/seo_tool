<?php

require_once __DIR__ . '/../models/SeoInsightModel.php';
require_once __DIR__ . '/../models/TrackedKeywordModel.php';
require_once __DIR__ . '/../models/RankingModel.php';
require_once __DIR__ . '/../models/SearchConsoleDataModel.php';
require_once __DIR__ . '/../models/SearchConsoleAccountModel.php';
require_once __DIR__ . '/PlanEnforcementService.php';

class InsightEngineService
{
    private const PLAN_TOP_LIMIT = [
        'free' => 3,
        'pro' => 80,
        'agency' => 180,
    ];

    private const MAX_GENERATE_REQUESTS_PER_MINUTE = 4;

    private const MIN_IMPRESSIONS_HIGH_CTR_GAP = 500;
    private const MIN_IMPRESSIONS_NEAR_PAGE1 = 250;
    private const MIN_IMPRESSIONS_HIGH_POTENTIAL = 1200;
    private const MIN_IMPRESSIONS_UNDERPERFORMING_PAGE = 300;

    private const CTR_LOW_THRESHOLD = 0.03;
    private const CTR_POTENTIAL_THRESHOLD = 0.05;
    private const CTR_UNDERPERFORMING_PAGE = 0.02;
    private const RANK_CHANGE_SIGNIFICANT = 4;

    private SeoInsightModel $insightModel;
    private TrackedKeywordModel $trackedKeywordModel;
    private RankingModel $rankingModel;
    private SearchConsoleDataModel $searchConsoleDataModel;
    private SearchConsoleAccountModel $searchConsoleAccountModel;
    private PlanEnforcementService $planEnforcementService;

    public function __construct(
        ?SeoInsightModel $insightModel = null,
        ?TrackedKeywordModel $trackedKeywordModel = null,
        ?RankingModel $rankingModel = null,
        ?SearchConsoleDataModel $searchConsoleDataModel = null,
        ?SearchConsoleAccountModel $searchConsoleAccountModel = null,
        ?PlanEnforcementService $planEnforcementService = null
    ) {
        $this->insightModel = $insightModel ?? new SeoInsightModel();
        $this->trackedKeywordModel = $trackedKeywordModel ?? new TrackedKeywordModel();
        $this->rankingModel = $rankingModel ?? new RankingModel();
        $this->searchConsoleDataModel = $searchConsoleDataModel ?? new SearchConsoleDataModel();
        $this->searchConsoleAccountModel = $searchConsoleAccountModel ?? new SearchConsoleAccountModel();
        $this->planEnforcementService = $planEnforcementService ?? new PlanEnforcementService();
    }

    public function getInsightsPageData(int $userId, string $planType, array $filters = []): array
    {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        $planType = $this->normalizePlanType($planType);
        $this->trackedKeywordModel->syncProjectsFromAudits($userId);
        $projects = $this->trackedKeywordModel->getProjects($userId);
        $selectedProjectId = isset($filters['project_id']) ? (int) $filters['project_id'] : 0;

        if (empty($projects)) {
            return [
                'success' => true,
                'projects' => [],
                'selected_project_id' => null,
                'sections' => [
                    'opportunity' => [],
                    'optimization' => [],
                    'warning' => [],
                ],
                'summary' => [
                    'total' => 0,
                    'opportunity' => 0,
                    'optimization' => 0,
                    'warning' => 0,
                    'last_generated_at' => null,
                ],
                'limits' => [
                    'plan_type' => $planType,
                    'max_insights' => self::PLAN_TOP_LIMIT[$planType] ?? self::PLAN_TOP_LIMIT['free'],
                ],
            ];
        }

        $projectIds = array_map(static fn (array $project): int => (int) ($project['id'] ?? 0), $projects);
        if ($selectedProjectId <= 0 || !in_array($selectedProjectId, $projectIds, true)) {
            $selectedProjectId = (int) ($projectIds[0] ?? 0);
        }

        $project = $this->trackedKeywordModel->getProjectById($userId, $selectedProjectId);
        if (!$project) {
            return $this->errorResponse('PROJECT_NOT_FOUND', 404, 'Project not found.');
        }

        $insightDays = $planType === 'agency' ? 90 : 28;
        $rawInsights = $this->insightModel->getInsightsByProject($selectedProjectId, [
            'days' => $insightDays,
            'limit' => 220,
        ]);

        $context = $this->buildProjectContext($userId, $selectedProjectId, $planType);
        $enriched = [];
        foreach ($rawInsights as $insightRow) {
            $enriched[] = $this->enrichInsightRow($insightRow, $context);
        }

        usort($enriched, function (array $a, array $b): int {
            $scoreCmp = ((int) ($b['priority_score'] ?? 0)) <=> ((int) ($a['priority_score'] ?? 0));
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        $maxAllowed = self::PLAN_TOP_LIMIT[$planType] ?? self::PLAN_TOP_LIMIT['free'];
        if (count($enriched) > $maxAllowed) {
            $enriched = array_slice($enriched, 0, $maxAllowed);
        }

        $sections = [
            'opportunity' => [],
            'optimization' => [],
            'warning' => [],
        ];
        foreach ($enriched as $item) {
            $severity = (string) ($item['severity'] ?? 'info');
            if ($severity === 'warning') {
                $sections['warning'][] = $item;
            } elseif ($severity === 'opportunity') {
                $sections['opportunity'][] = $item;
            } else {
                $sections['optimization'][] = $item;
            }
        }

        return [
            'success' => true,
            'projects' => $projects,
            'selected_project_id' => $selectedProjectId,
            'selected_project' => [
                'id' => (int) ($project['id'] ?? 0),
                'name' => (string) ($project['name'] ?? 'Project'),
                'domain' => (string) ($project['domain'] ?? ''),
            ],
            'sections' => $sections,
            'summary' => [
                'total' => count($enriched),
                'opportunity' => count($sections['opportunity']),
                'optimization' => count($sections['optimization']),
                'warning' => count($sections['warning']),
                'last_generated_at' => $this->insightModel->getLatestCreatedAtByProject($selectedProjectId),
                'gsc_connected' => !empty($context['gsc_connected']),
                'has_gsc_cache' => !empty($context['has_gsc_cache']),
            ],
            'limits' => [
                'plan_type' => $planType,
                'max_insights' => $maxAllowed,
                'page_level_enabled' => $planType === 'agency',
            ],
        ];
    }

    public function generateInsightsForProject(int $userId, string $planType, int $projectId, array $options = []): array
    {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        $planType = $this->normalizePlanType($planType);
        $project = $this->trackedKeywordModel->getProjectById($userId, $projectId);
        if (!$project) {
            return $this->errorResponse('PROJECT_NOT_FOUND', 404, 'Project not found or access denied.');
        }

        $connection = $this->searchConsoleAccountModel->getByProject($userId, $projectId);
        if (!$connection) {
            return $this->errorResponse('GSC_NOT_CONNECTED', 404, 'Connect Google Search Console before generating insights.');
        }

        $fromCron = !empty($options['from_cron']);
        if (!$fromCron && $this->trackedKeywordModel->countRecentRequests($userId, 'insight_generate', 60) >= self::MAX_GENERATE_REQUESTS_PER_MINUTE) {
            return $this->errorResponse('RATE_LIMIT', 429, 'Too many insight generation requests. Please wait a minute.');
        }

        $context = $this->buildProjectContext($userId, $projectId, $planType);
        if (empty($context['has_gsc_cache'])) {
            return [
                'success' => true,
                'generated' => 0,
                'created' => 0,
                'deleted_today' => 0,
                'deleted_old' => 0,
                'message' => 'No cached Search Console data found yet for this project.',
            ];
        }

        $candidates = $this->buildInsightCandidates($context, $planType);
        usort($candidates, function (array $a, array $b): int {
            $scoreCmp = ((int) ($b['priority_score'] ?? 0)) <=> ((int) ($a['priority_score'] ?? 0));
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }
            $impressionsCmp = ((float) ($b['impressions'] ?? 0)) <=> ((float) ($a['impressions'] ?? 0));
            if ($impressionsCmp !== 0) {
                return $impressionsCmp;
            }
            return strcmp((string) ($a['insight_type'] ?? ''), (string) ($b['insight_type'] ?? ''));
        });

        $maxAllowed = self::PLAN_TOP_LIMIT[$planType] ?? self::PLAN_TOP_LIMIT['free'];
        if (count($candidates) > $maxAllowed) {
            $candidates = array_slice($candidates, 0, $maxAllowed);
        }

        $generatedDate = isset($options['generated_date']) ? (string) $options['generated_date'] : date('Y-m-d');
        $generatedDate = $this->normalizeDate($generatedDate);

        $deletedToday = $this->insightModel->deleteByProjectAndDate($projectId, $generatedDate);
        $created = 0;
        foreach ($candidates as $candidate) {
            $createdResult = $this->insightModel->createInsightUnique(
                $projectId,
                isset($candidate['keyword']) ? (string) $candidate['keyword'] : null,
                isset($candidate['page_url']) ? (string) $candidate['page_url'] : null,
                (string) ($candidate['insight_type'] ?? 'insight'),
                (string) ($candidate['message'] ?? 'SEO insight generated.'),
                (string) ($candidate['severity'] ?? 'info'),
                $generatedDate
            );
            if (!empty($createdResult['success']) && !empty($createdResult['created'])) {
                $created++;
            }
        }

        $retentionDays = $planType === 'agency' ? 180 : 90;
        $deletedOld = $this->insightModel->deleteOlderThanDays($retentionDays);

        $source = isset($options['source']) ? (string) $options['source'] : ($fromCron ? 'cron' : 'manual');
        $this->trackedKeywordModel->logRequest($userId, 'insight_generate', (string) $projectId, $source, 200);

        return [
            'success' => true,
            'generated' => count($candidates),
            'created' => $created,
            'deleted_today' => $deletedToday,
            'deleted_old' => $deletedOld,
            'project_id' => $projectId,
            'project_name' => (string) ($project['name'] ?? 'Project'),
            'plan_type' => $planType,
            'generated_date' => $generatedDate,
        ];
    }

    public function runScheduledGenerationForAllProjects(int $limitConnections = 500): array
    {
        $limitConnections = max(1, min(2000, $limitConnections));
        $connections = $this->searchConsoleAccountModel->getAllConnections($limitConnections);

        $summary = [
            'success' => true,
            'date' => date('Y-m-d'),
            'connections_total' => count($connections),
            'processed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'insights_created' => 0,
            'details' => [],
        ];

        foreach ($connections as $connection) {
            $userId = (int) ($connection['user_id'] ?? 0);
            $projectId = (int) ($connection['project_id'] ?? 0);
            $planType = $this->planEnforcementService->getEffectivePlan($userId, (string) ($connection['plan_type'] ?? 'free'));
            $planType = $this->normalizePlanType($planType);

            if ($userId <= 0 || $projectId <= 0) {
                $summary['skipped']++;
                continue;
            }

            $result = $this->generateInsightsForProject($userId, $planType, $projectId, [
                'from_cron' => true,
                'source' => 'cron',
            ]);

            if (!empty($result['success'])) {
                if ((int) ($result['generated'] ?? 0) > 0) {
                    $summary['processed']++;
                } else {
                    $summary['skipped']++;
                }
                $summary['insights_created'] += (int) ($result['created'] ?? 0);
            } else {
                $summary['failed']++;
            }

            $summary['details'][] = [
                'user_id' => $userId,
                'project_id' => $projectId,
                'plan_type' => $planType,
                'result' => $result,
            ];
        }

        return $summary;
    }

    private function buildProjectContext(int $userId, int $projectId, string $planType): array
    {
        $queries = $this->searchConsoleDataModel->getQueries($projectId, 'last_28_days', 200);
        $pages = $planType === 'agency' ? $this->searchConsoleDataModel->getPages($projectId, 'last_28_days', 200) : [];
        $cache = $this->searchConsoleDataModel->getLatestCache($projectId, 'last_28_days');

        $queryMap = [];
        foreach ($queries as $row) {
            $query = $this->normalizeKeyword((string) ($row['query'] ?? ''));
            if ($query === '') {
                continue;
            }
            if (!isset($queryMap[$query])) {
                $queryMap[$query] = $row;
            }
        }

        $pageMap = [];
        foreach ($pages as $row) {
            $url = trim((string) ($row['page_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            if (!isset($pageMap[$url])) {
                $pageMap[$url] = $row;
            }
        }

        $trackedKeywords = $this->trackedKeywordModel->getTrackedKeywords($userId, $projectId, 'active');
        $trackedKeywordIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $trackedKeywords);
        $latestPreviousMap = $this->rankingModel->getLatestAndPreviousByKeywordIds($trackedKeywordIds);

        $keywordSignals = [];
        foreach ($trackedKeywords as $keywordRow) {
            $trackedId = (int) ($keywordRow['id'] ?? 0);
            if ($trackedId <= 0) {
                continue;
            }
            $keyword = (string) ($keywordRow['keyword'] ?? '');
            $normalizedKeyword = $this->normalizeKeyword($keyword);
            if ($normalizedKeyword === '') {
                continue;
            }

            $latest = $latestPreviousMap[$trackedId]['latest'] ?? null;
            $previous = $latestPreviousMap[$trackedId]['previous'] ?? null;

            $currentRank = (int) ($latest['rank_position'] ?? 101);
            $previousRank = $previous ? (int) ($previous['rank_position'] ?? 101) : null;
            $rankChange = $previousRank !== null ? ($previousRank - $currentRank) : 0;

            $queryRow = $queryMap[$normalizedKeyword] ?? null;
            $impressions = (float) ($queryRow['impressions'] ?? 0);
            $clicks = (float) ($queryRow['clicks'] ?? 0);
            $ctr = (float) ($queryRow['ctr'] ?? 0);
            $gscPosition = (float) ($queryRow['position'] ?? 0);

            $keywordSignals[$normalizedKeyword] = [
                'tracked_keyword_id' => $trackedId,
                'keyword' => $keyword,
                'current_rank' => $currentRank,
                'previous_rank' => $previousRank,
                'rank_change' => $rankChange,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $ctr,
                'gsc_position' => $gscPosition,
            ];
        }

        $trend = is_array($cache['trend'] ?? null) ? $cache['trend'] : [];
        $trendSignals = $this->buildTrendSignals($trend);
        $connection = $this->searchConsoleAccountModel->getByProject($userId, $projectId);

        return [
            'user_id' => $userId,
            'project_id' => $projectId,
            'plan_type' => $planType,
            'gsc_connected' => $connection !== null,
            'has_gsc_cache' => $cache !== null,
            'cache' => $cache,
            'queries' => $queries,
            'pages' => $pages,
            'query_map' => $queryMap,
            'page_map' => $pageMap,
            'keyword_signals' => $keywordSignals,
            'trend_signals' => $trendSignals,
        ];
    }

    private function buildInsightCandidates(array $context, string $planType): array
    {
        $planType = $this->normalizePlanType($planType);
        $trendSignals = (array) ($context['trend_signals'] ?? []);
        $keywordSignals = (array) ($context['keyword_signals'] ?? []);

        $candidates = [];

        foreach ($keywordSignals as $signal) {
            $keyword = (string) ($signal['keyword'] ?? '');
            $currentRank = (int) ($signal['current_rank'] ?? 101);
            $previousRank = isset($signal['previous_rank']) ? (int) $signal['previous_rank'] : null;
            $rankChange = (int) ($signal['rank_change'] ?? 0);
            $impressions = (float) ($signal['impressions'] ?? 0);
            $clicks = (float) ($signal['clicks'] ?? 0);
            $ctr = (float) ($signal['ctr'] ?? 0);

            if (
                $impressions >= self::MIN_IMPRESSIONS_HIGH_CTR_GAP
                && $ctr < self::CTR_LOW_THRESHOLD
                && $currentRank >= 1
                && $currentRank <= 10
            ) {
                $candidates[] = [
                    'keyword' => $keyword,
                    'insight_type' => 'high_impressions_low_ctr',
                    'message' => 'This keyword ranks well but has low CTR. Optimize your title and meta description.',
                    'severity' => 'info',
                    'priority_score' => 78 + (int) min(20, floor($impressions / 500)),
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                ];
            }

            if (
                $impressions >= self::MIN_IMPRESSIONS_NEAR_PAGE1
                && $currentRank >= 11
                && $currentRank <= 15
            ) {
                $candidates[] = [
                    'keyword' => $keyword,
                    'insight_type' => 'near_page1_opportunity',
                    'message' => 'This keyword is close to page 1. Improve content depth and internal linking.',
                    'severity' => 'opportunity',
                    'priority_score' => 85 + (int) min(15, floor($impressions / 600)),
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                ];
            }

            if (
                $previousRank !== null
                && $rankChange >= self::RANK_CHANGE_SIGNIFICANT
                && (
                    ((float) ($trendSignals['click_growth_rate'] ?? 0) <= 0.05)
                    || $clicks <= max(5.0, $impressions * 0.01)
                )
            ) {
                $candidates[] = [
                    'keyword' => $keyword,
                    'insight_type' => 'rank_improved_clicks_flat',
                    'message' => 'Ranking improved but traffic did not increase. Improve snippet attractiveness.',
                    'severity' => 'info',
                    'priority_score' => 72 + min(18, max(0, $rankChange * 2)),
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                ];
            }

            if (
                $previousRank !== null
                && ($currentRank - $previousRank) >= self::RANK_CHANGE_SIGNIFICANT
                && ((float) ($trendSignals['click_growth_rate'] ?? 0) < -0.10)
            ) {
                $candidates[] = [
                    'keyword' => $keyword,
                    'insight_type' => 'rank_drop_traffic_loss',
                    'message' => 'Keyword lost position and traffic. Investigate competitors and content freshness.',
                    'severity' => 'warning',
                    'priority_score' => 92 + min(12, max(0, ($currentRank - $previousRank))),
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                ];
            }

            if (
                $impressions >= self::MIN_IMPRESSIONS_HIGH_POTENTIAL
                && $currentRank >= 4
                && $currentRank <= 8
                && $ctr < self::CTR_POTENTIAL_THRESHOLD
            ) {
                $candidates[] = [
                    'keyword' => $keyword,
                    'insight_type' => 'high_click_potential',
                    'message' => 'Improve CTR to gain significant traffic without ranking changes.',
                    'severity' => 'opportunity',
                    'priority_score' => 88 + (int) min(15, floor($impressions / 900)),
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                ];
            }
        }

        if ($planType === 'agency') {
            $positionVolatility = (float) ($trendSignals['position_volatility'] ?? 0);
            foreach ((array) ($context['pages'] ?? []) as $row) {
                $pageUrl = trim((string) ($row['page_url'] ?? ''));
                if ($pageUrl === '') {
                    continue;
                }
                $impressions = (float) ($row['impressions'] ?? 0);
                $ctr = (float) ($row['ctr'] ?? 0);
                if ($impressions < self::MIN_IMPRESSIONS_UNDERPERFORMING_PAGE) {
                    continue;
                }
                if ($ctr >= self::CTR_UNDERPERFORMING_PAGE) {
                    continue;
                }
                if ($positionVolatility < 1.2) {
                    continue;
                }

                $candidates[] = [
                    'page_url' => $pageUrl,
                    'insight_type' => 'underperforming_page',
                    'message' => 'Page is visible but underperforming. Consider content update or title optimization.',
                    'severity' => 'info',
                    'priority_score' => 74 + (int) min(14, floor($impressions / 800)),
                    'impressions' => $impressions,
                    'clicks' => (float) ($row['clicks'] ?? 0),
                ];
            }
        }

        return $this->dedupeCandidates($candidates);
    }

    private function enrichInsightRow(array $insightRow, array $context): array
    {
        $insightType = (string) ($insightRow['insight_type'] ?? 'insight');
        $keyword = isset($insightRow['keyword']) ? trim((string) $insightRow['keyword']) : '';
        $pageUrl = isset($insightRow['page_url']) ? trim((string) $insightRow['page_url']) : '';
        $normalizedKeyword = $this->normalizeKeyword($keyword);

        $keywordSignal = $normalizedKeyword !== '' ? ((array) ($context['keyword_signals'][$normalizedKeyword] ?? [])) : [];
        $pageSignal = $pageUrl !== '' ? ((array) ($context['page_map'][$pageUrl] ?? [])) : [];

        $currentRank = isset($keywordSignal['current_rank']) ? (int) $keywordSignal['current_rank'] : null;
        $previousRank = isset($keywordSignal['previous_rank']) ? (int) $keywordSignal['previous_rank'] : null;
        $impressions = isset($keywordSignal['impressions']) ? (float) $keywordSignal['impressions'] : (float) ($pageSignal['impressions'] ?? 0);
        $clicks = isset($keywordSignal['clicks']) ? (float) $keywordSignal['clicks'] : (float) ($pageSignal['clicks'] ?? 0);
        $ctr = isset($keywordSignal['ctr']) ? (float) $keywordSignal['ctr'] : (float) ($pageSignal['ctr'] ?? 0);
        $avgPosition = isset($keywordSignal['gsc_position']) ? (float) $keywordSignal['gsc_position'] : (float) ($pageSignal['position'] ?? 0);

        $priorityScore = $this->priorityFromSeverityAndMetrics(
            (string) ($insightRow['severity'] ?? 'info'),
            $impressions,
            $currentRank,
            $previousRank
        );

        return [
            'id' => (int) ($insightRow['id'] ?? 0),
            'project_id' => (int) ($insightRow['project_id'] ?? 0),
            'keyword' => $keyword !== '' ? $keyword : null,
            'page_url' => $pageUrl !== '' ? $pageUrl : null,
            'insight_type' => $insightType,
            'label' => $this->insightLabel($insightType),
            'severity' => (string) ($insightRow['severity'] ?? 'info'),
            'message' => (string) ($insightRow['message'] ?? ''),
            'suggested_action' => $this->suggestedAction($insightType),
            'current_rank' => $currentRank,
            'previous_rank' => $previousRank,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $ctr,
            'position' => $avgPosition,
            'priority_score' => $priorityScore,
            'created_at' => (string) ($insightRow['created_at'] ?? ''),
        ];
    }

    private function dedupeCandidates(array $candidates): array
    {
        $seen = [];
        $result = [];
        foreach ($candidates as $candidate) {
            $type = (string) ($candidate['insight_type'] ?? 'insight');
            $keyword = $this->normalizeKeyword((string) ($candidate['keyword'] ?? ''));
            $page = trim((string) ($candidate['page_url'] ?? ''));
            $key = $type . '|' . $keyword . '|' . $page;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $candidate;
        }
        return $result;
    }

    private function buildTrendSignals(array $trend): array
    {
        if (empty($trend)) {
            return [
                'recent_clicks' => 0.0,
                'previous_clicks' => 0.0,
                'click_growth_rate' => 0.0,
                'position_volatility' => 0.0,
            ];
        }

        usort($trend, static function (array $a, array $b): int {
            return strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
        });
        $count = count($trend);
        $recentChunk = array_slice($trend, max(0, $count - 7), 7);
        $previousChunk = array_slice($trend, max(0, $count - 14), min(7, max(0, $count - 7)));

        $recentClicks = 0.0;
        foreach ($recentChunk as $row) {
            $recentClicks += (float) ($row['clicks'] ?? 0);
        }

        $previousClicks = 0.0;
        foreach ($previousChunk as $row) {
            $previousClicks += (float) ($row['clicks'] ?? 0);
        }

        if ($previousClicks > 0) {
            $growthRate = ($recentClicks - $previousClicks) / $previousClicks;
        } else {
            $growthRate = $recentClicks > 0 ? 1.0 : 0.0;
        }

        $volatilityChunk = array_slice($trend, max(0, $count - 14), 14);
        $positions = [];
        foreach ($volatilityChunk as $row) {
            $position = (float) ($row['position'] ?? 0);
            if ($position > 0) {
                $positions[] = $position;
            }
        }

        $positionVolatility = $this->standardDeviation($positions);

        return [
            'recent_clicks' => round($recentClicks, 2),
            'previous_clicks' => round($previousClicks, 2),
            'click_growth_rate' => round($growthRate, 4),
            'position_volatility' => round($positionVolatility, 4),
        ];
    }

    private function standardDeviation(array $values): float
    {
        $values = array_values(array_filter($values, static fn ($value): bool => is_numeric($value)));
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += (float) $value;
        }
        $mean = $sum / $count;
        $varianceSum = 0.0;
        foreach ($values as $value) {
            $delta = (float) $value - $mean;
            $varianceSum += $delta * $delta;
        }
        $variance = $varianceSum / $count;
        return sqrt(max(0.0, $variance));
    }

    private function priorityFromSeverityAndMetrics(string $severity, float $impressions, ?int $currentRank, ?int $previousRank): int
    {
        $base = match (strtolower(trim($severity))) {
            'warning' => 90,
            'opportunity' => 80,
            default => 70,
        };
        $impressionBoost = (int) min(15, floor(max(0.0, $impressions) / 1000));
        $rankBoost = 0;
        if ($currentRank !== null && $currentRank > 0 && $currentRank <= 15) {
            $rankBoost += 4;
        }
        if ($previousRank !== null && $currentRank !== null) {
            $delta = $previousRank - $currentRank;
            $rankBoost += (int) min(8, max(-8, $delta));
        }
        return $base + $impressionBoost + $rankBoost;
    }

    private function insightLabel(string $insightType): string
    {
        return match ($insightType) {
            'high_impressions_low_ctr' => 'High Impressions, Low CTR',
            'near_page1_opportunity' => 'Near Page 1 Opportunity',
            'rank_improved_clicks_flat' => 'Rank Improved, Clicks Flat',
            'rank_drop_traffic_loss' => 'Ranking Dropped with Traffic Loss',
            'high_click_potential' => 'High Click Potential',
            'underperforming_page' => 'Underperforming Page',
            default => 'SEO Insight',
        };
    }

    private function suggestedAction(string $insightType): string
    {
        return match ($insightType) {
            'high_impressions_low_ctr' => 'Rewrite title/meta with stronger value proposition and intent match.',
            'near_page1_opportunity' => 'Strengthen on-page depth, add internal links, and improve topical coverage.',
            'rank_improved_clicks_flat' => 'Improve snippet attractiveness with clearer promise and structured data.',
            'rank_drop_traffic_loss' => 'Audit competitors, refresh content, and verify technical issues for this keyword.',
            'high_click_potential' => 'Test 2-3 title/meta variants to improve CTR without waiting for rank gains.',
            'underperforming_page' => 'Update page content, title hooks, and align intent with current SERP results.',
            default => 'Review this signal and optimize page relevance and snippet quality.',
        };
    }

    private function normalizeKeyword(string $keyword): string
    {
        $keyword = strtolower(trim($keyword));
        $keyword = preg_replace('/\s+/', ' ', $keyword);
        return trim((string) $keyword);
    }

    private function normalizePlanType(string $planType): string
    {
        $planType = strtolower(trim($planType));
        if (!isset(self::PLAN_TOP_LIMIT[$planType])) {
            return 'free';
        }
        return $planType;
    }

    private function normalizeDate(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($date)) === 1) {
            return trim($date);
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return date('Y-m-d');
        }
        return date('Y-m-d', $ts);
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
}
