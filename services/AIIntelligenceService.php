<?php

require_once __DIR__ . '/../models/AIRequestModel.php';
require_once __DIR__ . '/../models/TrackedKeywordModel.php';
require_once __DIR__ . '/../models/RankingModel.php';
require_once __DIR__ . '/../models/SearchConsoleDataModel.php';
require_once __DIR__ . '/../models/SeoInsightModel.php';
require_once __DIR__ . '/PlanEnforcementService.php';
require_once __DIR__ . '/UsageMonitoringService.php';
require_once __DIR__ . '/SystemLogService.php';
require_once __DIR__ . '/../utils/Env.php';

class AIIntelligenceService
{
    private const MAX_QUESTION_LENGTH = 600;
    private const MAX_KEYWORD_LENGTH = 100;
    private const MAX_TITLE_LENGTH = 120;
    private const MAX_META_LENGTH = 220;
    private const MAX_CONTENT_SUMMARY_LENGTH = 1400;
    private const MAX_HEADING_INPUT_LENGTH = 700;

    private const DEFAULT_CONCURRENCY_LIMIT = 20;
    private const DEFAULT_MAX_INPUT_CHARS = 600;
    private const DEFAULT_MAX_RESPONSE_TOKENS = 550;
    private const DEFAULT_AI_TIMEOUT = 28;
    private const DEFAULT_COST_PER_1K_INR = 0.18;
    private const DEFAULT_MODEL = 'gpt-4.1-mini';

    private const MAX_LOAD_HISTORY = 30;
    private const MAX_QUEUE_BATCH = 6;
    private const PROCESSING_TIMEOUT_MINUTES = 20;

    private AIRequestModel $requestModel;
    private TrackedKeywordModel $trackedKeywordModel;
    private RankingModel $rankingModel;
    private SearchConsoleDataModel $searchConsoleDataModel;
    private SeoInsightModel $seoInsightModel;
    private PlanEnforcementService $planEnforcementService;
    private UsageMonitoringService $usageMonitoringService;
    private SystemLogService $systemLogService;

    public function __construct(
        ?AIRequestModel $requestModel = null,
        ?TrackedKeywordModel $trackedKeywordModel = null,
        ?RankingModel $rankingModel = null,
        ?SearchConsoleDataModel $searchConsoleDataModel = null,
        ?SeoInsightModel $seoInsightModel = null,
        ?PlanEnforcementService $planEnforcementService = null,
        ?UsageMonitoringService $usageMonitoringService = null,
        ?SystemLogService $systemLogService = null
    ) {
        Env::load(dirname(__DIR__) . '/.env');
        $this->requestModel = $requestModel ?? new AIRequestModel();
        $this->trackedKeywordModel = $trackedKeywordModel ?? new TrackedKeywordModel();
        $this->rankingModel = $rankingModel ?? new RankingModel();
        $this->searchConsoleDataModel = $searchConsoleDataModel ?? new SearchConsoleDataModel();
        $this->seoInsightModel = $seoInsightModel ?? new SeoInsightModel();
        $this->planEnforcementService = $planEnforcementService ?? new PlanEnforcementService();
        $this->usageMonitoringService = $usageMonitoringService ?? new UsageMonitoringService();
        $this->systemLogService = $systemLogService ?? new SystemLogService();
    }

    public function getPageData(int $userId, string $sessionPlanType, string $role, array $filters = []): array
    {
        $effectivePlan = $this->planEnforcementService->getEffectivePlan($userId, $sessionPlanType);
        $limitsInfo = $this->planEnforcementService->getLimitsForUser($userId);
        $monthlyLimit = (int) (($limitsInfo['limits']['ai_monthly_limit'] ?? 0));
        if ($monthlyLimit <= 0) {
            $monthlyLimit = max(1, $this->requestModel->getPlanAiMonthlyLimit($effectivePlan));
        }

        $month = date('Y-m');
        try {
            $usage = $this->requestModel->getMonthlyUsage($userId, $month);
        } catch (Throwable $error) {
            $usage = ['request_count' => 0, 'last_request_at' => null];
        }
        $used = (int) ($usage['request_count'] ?? 0);
        $remaining = max(0, $monthlyLimit - $used);

        $this->trackedKeywordModel->syncProjectsFromAudits($userId);
        $projects = $this->trackedKeywordModel->getProjects($userId);

        $selectedProjectId = isset($filters['project_id']) ? (int) $filters['project_id'] : 0;
        $projectIds = array_values(array_map(static fn (array $project): int => (int) ($project['id'] ?? 0), $projects));
        if ($selectedProjectId <= 0 || !in_array($selectedProjectId, $projectIds, true)) {
            $selectedProjectId = (int) ($projectIds[0] ?? 0);
        }

        $selectedProject = null;
        if ($selectedProjectId > 0) {
            $selectedProject = $this->trackedKeywordModel->getProjectById($userId, $selectedProjectId);
        }

        try {
            $recentRequests = $this->requestModel->getRecentRequestsByUser($userId, self::MAX_LOAD_HISTORY);
        } catch (Throwable $error) {
            $recentRequests = [];
        }

        $payload = [
            'success' => true,
            'projects' => $projects,
            'selected_project_id' => $selectedProjectId > 0 ? $selectedProjectId : null,
            'selected_project' => $selectedProject ? [
                'id' => (int) ($selectedProject['id'] ?? 0),
                'name' => (string) ($selectedProject['name'] ?? 'Project'),
                'domain' => (string) ($selectedProject['domain'] ?? ''),
            ] : null,
            'usage' => [
                'month' => $month,
                'used' => $used,
                'limit' => $monthlyLimit,
                'remaining' => $remaining,
                'last_request_at' => (string) ($usage['last_request_at'] ?? ''),
            ],
            'plan' => [
                'type' => $effectivePlan,
                'role' => strtolower(trim($role)),
                'admin_bypass' => !empty($limitsInfo['admin_bypass']),
            ],
            'global' => [
                'enabled' => $this->isAiEnabledGlobally(),
                'concurrency_limit' => $this->getConcurrencyLimit(),
                'max_input_chars' => $this->getMaxInputChars(),
            ],
            'queue' => [
                'processing' => $this->safeCountProcessing(),
            ],
            'history' => $recentRequests,
        ];

        return $payload;
    }

    public function submitRequest(int $userId, string $sessionPlanType, string $role, array $input): array
    {
        if (!$this->requestModel->hasConnection()) {
            return $this->errorResponse('AI_DB_UNAVAILABLE', 500, 'AI storage tables are unavailable. Run Phase 5 DB patch first.');
        }

        if (!$this->isAiEnabledGlobally()) {
            return $this->errorResponse('AI_DISABLED', 503, 'AI is currently disabled by administrator settings.');
        }

        $normalizedInput = $this->normalizeIncomingInput($input);
        if (empty($normalizedInput['valid'])) {
            return $this->errorResponse(
                (string) ($normalizedInput['error_code'] ?? 'VALIDATION_ERROR'),
                (int) ($normalizedInput['status'] ?? 422),
                (string) ($normalizedInput['error'] ?? 'Invalid AI request.')
            );
        }

        $requestType = (string) ($normalizedInput['request_type'] ?? 'advisor');
        $projectId = (int) ($normalizedInput['project_id'] ?? 0);
        $payload = (array) ($normalizedInput['payload'] ?? []);

        $project = $this->trackedKeywordModel->getProjectById($userId, $projectId);
        if (!$project) {
            return $this->errorResponse('PROJECT_NOT_FOUND', 404, 'Project not found or access denied.');
        }

        if ($this->looksLikePromptInjection($payload)) {
            $this->systemLogService->warning('ai_security', 'Potential prompt injection blocked.', [
                'user_id' => $userId,
                'project_id' => $projectId,
                'request_type' => $requestType,
            ], $userId, $projectId);

            return $this->errorResponse(
                'PROMPT_INJECTION_BLOCKED',
                422,
                'Request blocked for security reasons. Remove instruction-overriding text and retry.'
            );
        }

        $effectivePlan = $this->planEnforcementService->getEffectivePlan($userId, $sessionPlanType);
        $limitsInfo = $this->planEnforcementService->getLimitsForUser($userId);
        $adminBypass = !empty($limitsInfo['admin_bypass']);

        $month = date('Y-m');
        try {
            $usage = $this->requestModel->getMonthlyUsage($userId, $month);
        } catch (Throwable $error) {
            return $this->errorResponse('AI_TABLES_MISSING', 500, 'AI tables are not ready. Run fix_phase5_db.php.');
        }
        $used = (int) ($usage['request_count'] ?? 0);

        $monthlyLimit = (int) ($limitsInfo['limits']['ai_monthly_limit'] ?? 0);
        if ($monthlyLimit <= 0) {
            $monthlyLimit = max(1, $this->requestModel->getPlanAiMonthlyLimit($effectivePlan));
        }

        if (!$adminBypass && $used >= $monthlyLimit) {
            return [
                'success' => false,
                'status' => 403,
                'error_code' => 'AI_MONTHLY_LIMIT',
                'error' => 'Monthly AI request limit reached for your plan.',
                'upgrade_required' => true,
                'usage' => [
                    'month' => $month,
                    'used' => $used,
                    'limit' => $monthlyLimit,
                    'remaining' => 0,
                ],
            ];
        }

        $queuePayload = [
            'input' => $payload,
            'project_snapshot' => [
                'id' => (int) ($project['id'] ?? $projectId),
                'name' => (string) ($project['name'] ?? 'Project'),
                'domain' => (string) ($project['domain'] ?? ''),
            ],
            'submitted_at' => date('Y-m-d H:i:s'),
            'submitted_from' => 'web',
        ];

        try {
            $requestId = $this->requestModel->createQueueRequest($userId, $projectId, $requestType, $queuePayload, 'pending');
        } catch (Throwable $error) {
            $this->systemLogService->logException('ai_request', $error, [
                'request_type' => $requestType,
                'project_id' => $projectId,
                'stage' => 'queue_insert',
            ], $userId, $projectId);
            return $this->errorResponse('QUEUE_INSERT_FAILED', 500, 'Failed to enqueue AI request.');
        }
        if ($requestId <= 0) {
            return $this->errorResponse('QUEUE_INSERT_FAILED', 500, 'Failed to enqueue AI request.');
        }

        try {
            $this->requestModel->incrementMonthlyUsage($userId, $month);
        } catch (Throwable $error) {
            $this->systemLogService->warning('ai_request', 'Unable to increment AI usage counter.', [
                'request_id' => $requestId,
                'month' => $month,
            ], $userId, $projectId);
        }
        $this->usageMonitoringService->logApiCall($userId, 'ai_request_submit', $projectId);

        $concurrencyLimit = $this->getConcurrencyLimit();
        try {
            $claimed = $this->requestModel->claimPendingRequestById($requestId, $concurrencyLimit);
        } catch (Throwable $error) {
            $claimed = false;
        }

        if (!$claimed) {
            try {
                $queuePosition = $this->requestModel->countPendingBeforeRequest($requestId);
            } catch (Throwable $error) {
                $queuePosition = 1;
            }
            return [
                'success' => true,
                'queued' => true,
                'request_id' => $requestId,
                'status' => 'pending',
                'message' => 'AI is busy. Your request is queued.',
                'queue_position' => max(1, $queuePosition),
                'usage' => [
                    'month' => $month,
                    'used' => $used + 1,
                    'limit' => $monthlyLimit,
                    'remaining' => max(0, $monthlyLimit - ($used + 1)),
                ],
            ];
        }

        $processed = $this->processQueuedRequestById($requestId, false);
        if (empty($processed['success'])) {
            return [
                'success' => false,
                'status' => (int) ($processed['status'] ?? 500),
                'error_code' => (string) ($processed['error_code'] ?? 'AI_PROCESS_FAILED'),
                'error' => (string) ($processed['error'] ?? 'AI processing failed.'),
                'request_id' => $requestId,
                'queued' => false,
                'usage' => [
                    'month' => $month,
                    'used' => $used + 1,
                    'limit' => $monthlyLimit,
                    'remaining' => max(0, $monthlyLimit - ($used + 1)),
                ],
            ];
        }

        return [
            'success' => true,
            'queued' => false,
            'request_id' => $requestId,
            'status' => 'completed',
            'result' => (array) ($processed['response_payload'] ?? []),
            'usage' => [
                'month' => $month,
                'used' => $used + 1,
                'limit' => $monthlyLimit,
                'remaining' => max(0, $monthlyLimit - ($used + 1)),
            ],
        ];
    }

    public function getRequestStatusForUser(int $userId, int $requestId): array
    {
        $row = $this->requestModel->getQueueRequestByIdForUser($requestId, $userId);
        if (!$row) {
            return $this->errorResponse('REQUEST_NOT_FOUND', 404, 'AI request not found.');
        }

        $queuePosition = null;
        if ((string) ($row['status'] ?? '') === 'pending') {
            $queuePosition = max(1, $this->requestModel->countPendingBeforeRequest($requestId));
        }

        return [
            'success' => true,
            'request' => $row,
            'queue_position' => $queuePosition,
        ];
    }

    public function processQueueBatch(int $maxToProcess = 3): array
    {
        if (!$this->requestModel->hasConnection()) {
            return $this->errorResponse('AI_DB_UNAVAILABLE', 500, 'AI storage tables are unavailable.');
        }

        if (!$this->isAiEnabledGlobally()) {
            return [
                'success' => true,
                'disabled' => true,
                'processed' => 0,
                'failed' => 0,
                'message' => 'AI system is disabled.',
            ];
        }

        $maxToProcess = max(1, min(self::MAX_QUEUE_BATCH, $maxToProcess));
        try {
            $stuckRecovered = $this->requestModel->recoverStuckProcessing(self::PROCESSING_TIMEOUT_MINUTES);
        } catch (Throwable $error) {
            $stuckRecovered = 0;
        }
        $concurrencyLimit = $this->getConcurrencyLimit();

        $processed = 0;
        $failed = 0;
        $details = [];

        while ($processed < $maxToProcess) {
            try {
                $nextRequestId = $this->requestModel->claimNextPendingRequest($concurrencyLimit);
            } catch (Throwable $error) {
                $nextRequestId = null;
            }
            if ($nextRequestId === null) {
                break;
            }

            $result = $this->processQueuedRequestById((int) $nextRequestId, false);
            $processed++;
            if (empty($result['success'])) {
                $failed++;
            }

            $details[] = [
                'request_id' => (int) $nextRequestId,
                'success' => !empty($result['success']),
                'error_code' => (string) ($result['error_code'] ?? ''),
            ];
        }

        return [
            'success' => true,
            'processed' => $processed,
            'failed' => $failed,
            'stuck_recovered' => $stuckRecovered,
            'processing_now' => $this->safeCountProcessing(),
            'details' => $details,
        ];
    }

    public function processQueuedRequestById(int $requestId, bool $allowClaimPending = false): array
    {
        if ($requestId <= 0) {
            return $this->errorResponse('INVALID_REQUEST_ID', 422, 'Invalid request id.');
        }

        $row = $this->requestModel->getQueueRequestById($requestId);
        if (!$row) {
            return $this->errorResponse('REQUEST_NOT_FOUND', 404, 'Queue request was not found.');
        }

        if ((string) ($row['status'] ?? '') === 'pending') {
            if (!$allowClaimPending) {
                return $this->errorResponse('REQUEST_NOT_CLAIMED', 409, 'Request is pending and not claimed for processing.');
            }

            $claimed = $this->requestModel->claimPendingRequestById($requestId, $this->getConcurrencyLimit());
            if (!$claimed) {
                return $this->errorResponse('CONCURRENCY_BUSY', 429, 'Concurrency slots are full.');
            }
            $row = $this->requestModel->getQueueRequestById($requestId) ?: $row;
        }

        if ((string) ($row['status'] ?? '') !== 'processing') {
            if ((string) ($row['status'] ?? '') === 'completed') {
                return [
                    'success' => true,
                    'request_id' => $requestId,
                    'response_payload' => (array) ($row['response_payload'] ?? []),
                    'status' => 200,
                ];
            }
            return $this->errorResponse('REQUEST_NOT_PROCESSING', 409, 'Request is not in processing state.');
        }

        $userId = (int) ($row['user_id'] ?? 0);
        $projectId = (int) ($row['project_id'] ?? 0);
        $requestType = (string) ($row['request_type'] ?? 'advisor');
        $requestPayload = (array) ($row['request_payload'] ?? []);
        $inputPayload = is_array($requestPayload['input'] ?? null) ? (array) $requestPayload['input'] : [];

        try {
            $context = $this->buildProjectContext($userId, $projectId, $inputPayload);
            $aiResult = $this->executeAiRequest($requestType, $inputPayload, $context);

            if (empty($aiResult['success'])) {
                $errorMessage = (string) ($aiResult['error'] ?? 'AI request failed.');
                $this->requestModel->markRequestFailed($requestId, $errorMessage);
                $this->systemLogService->warning('ai_request', $errorMessage, [
                    'request_id' => $requestId,
                    'request_type' => $requestType,
                ], $userId, $projectId);

                return $this->errorResponse(
                    (string) ($aiResult['error_code'] ?? 'AI_REQUEST_FAILED'),
                    (int) ($aiResult['status'] ?? 502),
                    $errorMessage
                );
            }

            $normalizedOutput = $this->normalizeAiOutput($requestType, (array) ($aiResult['output'] ?? []), $inputPayload, $context);
            $modelName = (string) ($aiResult['model'] ?? $this->defaultModel());
            $tokensUsed = max(0, (int) ($aiResult['tokens_used'] ?? 0));
            $costEstimate = max(0.0, (float) ($aiResult['cost_estimate'] ?? 0.0));

            $responsePayload = [
                'module' => $requestType,
                'generated_at' => date('Y-m-d H:i:s'),
                'project' => [
                    'id' => (int) ($context['project']['id'] ?? $projectId),
                    'name' => (string) ($context['project']['name'] ?? 'Project'),
                    'domain' => (string) ($context['project']['domain'] ?? ''),
                ],
                'answer' => $normalizedOutput,
                'meta' => [
                    'model' => $modelName,
                    'tokens_used' => $tokensUsed,
                    'cost_estimate_inr' => $costEstimate,
                ],
            ];

            $this->requestModel->markRequestCompleted($requestId, $responsePayload, $tokensUsed, $costEstimate);
            $this->requestModel->logCost($userId, $requestId, $tokensUsed, $costEstimate, $modelName);

            $this->usageMonitoringService->logApiCall($userId, 'ai_' . $requestType, $projectId);

            return [
                'success' => true,
                'request_id' => $requestId,
                'response_payload' => $responsePayload,
                'tokens_used' => $tokensUsed,
                'cost_estimate' => $costEstimate,
                'status' => 200,
            ];
        } catch (Throwable $error) {
            $message = 'AI processing failed unexpectedly: ' . $error->getMessage();
            $this->requestModel->markRequestFailed($requestId, $message);
            $this->systemLogService->logException('ai_request', $error, [
                'request_id' => $requestId,
                'request_type' => $requestType,
            ], $userId, $projectId);

            return $this->errorResponse('AI_RUNTIME_ERROR', 500, 'AI processing failed unexpectedly.');
        }
    }

    private function normalizeIncomingInput(array $input): array
    {
        $requestType = strtolower(trim((string) ($input['request_type'] ?? 'advisor')));
        if (!in_array($requestType, ['advisor', 'meta', 'optimizer'], true)) {
            return [
                'valid' => false,
                'status' => 422,
                'error_code' => 'INVALID_REQUEST_TYPE',
                'error' => 'Invalid AI request type.',
            ];
        }

        $projectId = (int) ($input['project_id'] ?? 0);
        if ($projectId <= 0) {
            return [
                'valid' => false,
                'status' => 422,
                'error_code' => 'PROJECT_REQUIRED',
                'error' => 'Select a valid project before requesting AI suggestions.',
            ];
        }

        $maxChars = $this->getMaxInputChars();

        if ($requestType === 'advisor') {
            $question = $this->sanitizePlainText((string) ($input['question'] ?? ''), min($maxChars, self::MAX_QUESTION_LENGTH));
            if ($question === '') {
                return [
                    'valid' => false,
                    'status' => 422,
                    'error_code' => 'QUESTION_REQUIRED',
                    'error' => 'Ask an SEO question to use AI Advisor.',
                ];
            }

            return [
                'valid' => true,
                'request_type' => 'advisor',
                'project_id' => $projectId,
                'payload' => [
                    'question' => $question,
                ],
            ];
        }

        if ($requestType === 'meta') {
            $targetKeyword = $this->sanitizeKeyword((string) ($input['target_keyword'] ?? ''));
            $pageUrl = $this->sanitizeUrl((string) ($input['page_url'] ?? ''));
            $currentTitle = $this->sanitizePlainText((string) ($input['current_title'] ?? ''), self::MAX_TITLE_LENGTH);
            $currentMeta = $this->sanitizePlainText((string) ($input['current_meta_description'] ?? ''), self::MAX_META_LENGTH);

            if ($targetKeyword === '' && $pageUrl === '') {
                return [
                    'valid' => false,
                    'status' => 422,
                    'error_code' => 'META_INPUT_REQUIRED',
                    'error' => 'Provide at least a target keyword or page URL for meta generation.',
                ];
            }

            return [
                'valid' => true,
                'request_type' => 'meta',
                'project_id' => $projectId,
                'payload' => [
                    'target_keyword' => $targetKeyword,
                    'page_url' => $pageUrl,
                    'current_title' => $currentTitle,
                    'current_meta_description' => $currentMeta,
                ],
            ];
        }

        $targetKeyword = $this->sanitizeKeyword((string) ($input['target_keyword'] ?? ''));
        $pageUrl = $this->sanitizeUrl((string) ($input['page_url'] ?? ''));
        $headings = $this->sanitizePlainText((string) ($input['current_headings'] ?? ''), self::MAX_HEADING_INPUT_LENGTH);
        $contentSummary = $this->sanitizePlainText((string) ($input['content_summary'] ?? ''), self::MAX_CONTENT_SUMMARY_LENGTH);

        if ($targetKeyword === '' && $pageUrl === '' && $contentSummary === '') {
            return [
                'valid' => false,
                'status' => 422,
                'error_code' => 'OPTIMIZER_INPUT_REQUIRED',
                'error' => 'Provide keyword, page URL, or content summary for AI Content Optimizer.',
            ];
        }

        return [
            'valid' => true,
            'request_type' => 'optimizer',
            'project_id' => $projectId,
            'payload' => [
                'target_keyword' => $targetKeyword,
                'page_url' => $pageUrl,
                'current_headings' => $headings,
                'content_summary' => $contentSummary,
            ],
        ];
    }

    private function buildProjectContext(int $userId, int $projectId, array $inputPayload): array
    {
        $project = $this->trackedKeywordModel->getProjectById($userId, $projectId) ?? [
            'id' => $projectId,
            'name' => 'Project',
            'domain' => '',
        ];

        $trackedKeywords = $this->trackedKeywordModel->getTrackedKeywords($userId, $projectId, 'active');
        if (count($trackedKeywords) > 140) {
            $trackedKeywords = array_slice($trackedKeywords, 0, 140);
        }

        $keywordIds = array_values(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $trackedKeywords));
        $latestPreviousMap = $this->rankingModel->getLatestAndPreviousByKeywordIds($keywordIds);
        $bestRankMap = $this->rankingModel->getBestRanksByKeywordIds($keywordIds);

        $rankRows = [];
        $rankImproved = 0;
        $rankDropped = 0;
        $rankFlat = 0;
        $rankSum = 0.0;
        $rankCount = 0;

        foreach ($trackedKeywords as $keywordRow) {
            $trackedId = (int) ($keywordRow['id'] ?? 0);
            if ($trackedId <= 0) {
                continue;
            }

            $latest = $latestPreviousMap[$trackedId]['latest'] ?? null;
            $previous = $latestPreviousMap[$trackedId]['previous'] ?? null;

            $currentRank = (int) ($latest['rank_position'] ?? 101);
            $previousRank = $previous ? (int) ($previous['rank_position'] ?? 101) : null;
            $change = $previousRank !== null ? ($previousRank - $currentRank) : 0;

            if ($change > 0) {
                $rankImproved++;
            } elseif ($change < 0) {
                $rankDropped++;
            } else {
                $rankFlat++;
            }

            if ($currentRank <= 100) {
                $rankSum += $currentRank;
                $rankCount++;
            }

            $rankRows[] = [
                'tracked_keyword_id' => $trackedId,
                'keyword' => (string) ($keywordRow['keyword'] ?? ''),
                'country' => (string) ($keywordRow['country'] ?? 'US'),
                'device_type' => (string) ($keywordRow['device_type'] ?? 'desktop'),
                'current_rank' => $currentRank,
                'previous_rank' => $previousRank,
                'change' => $change,
                'best_rank' => (int) ($bestRankMap[$trackedId] ?? $currentRank),
                'checked_date' => (string) ($latest['checked_date'] ?? ''),
            ];
        }

        usort($rankRows, static function (array $a, array $b): int {
            $aRank = (int) ($a['current_rank'] ?? 101);
            $bRank = (int) ($b['current_rank'] ?? 101);
            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }
            return strcmp((string) ($a['keyword'] ?? ''), (string) ($b['keyword'] ?? ''));
        });

        $gscCache = $this->searchConsoleDataModel->getLatestCache($projectId, 'last_28_days');
        $gscQueries = $this->searchConsoleDataModel->getQueries($projectId, 'last_28_days', 50);
        $gscPages = $this->searchConsoleDataModel->getPages($projectId, 'last_28_days', 50);

        $insights = $this->seoInsightModel->getInsightsByProject($projectId, [
            'days' => 28,
            'limit' => 40,
        ]);

        $insightSummary = [
            'opportunity' => 0,
            'info' => 0,
            'warning' => 0,
        ];
        foreach ($insights as $insightRow) {
            $severity = strtolower(trim((string) ($insightRow['severity'] ?? 'info')));
            if (!isset($insightSummary[$severity])) {
                $severity = 'info';
            }
            $insightSummary[$severity]++;
        }

        $targetKeyword = strtolower(trim((string) ($inputPayload['target_keyword'] ?? '')));
        $matchedQuery = null;
        if ($targetKeyword !== '') {
            foreach ($gscQueries as $queryRow) {
                $query = strtolower(trim((string) ($queryRow['query'] ?? '')));
                if ($query === $targetKeyword) {
                    $matchedQuery = $queryRow;
                    break;
                }
            }
            if ($matchedQuery === null) {
                foreach ($gscQueries as $queryRow) {
                    $query = strtolower(trim((string) ($queryRow['query'] ?? '')));
                    if ($query !== '' && str_contains($query, $targetKeyword)) {
                        $matchedQuery = $queryRow;
                        break;
                    }
                }
            }
        }

        $pageUrl = strtolower(trim((string) ($inputPayload['page_url'] ?? '')));
        $matchedPage = null;
        if ($pageUrl !== '') {
            foreach ($gscPages as $pageRow) {
                $candidate = strtolower(trim((string) ($pageRow['page_url'] ?? '')));
                if ($candidate === '') {
                    continue;
                }
                if ($candidate === $pageUrl || str_contains($candidate, $pageUrl) || str_contains($pageUrl, $candidate)) {
                    $matchedPage = $pageRow;
                    break;
                }
            }
        }

        return [
            'project' => [
                'id' => (int) ($project['id'] ?? 0),
                'name' => (string) ($project['name'] ?? 'Project'),
                'domain' => (string) ($project['domain'] ?? ''),
            ],
            'ranking' => [
                'keywords_total' => count($rankRows),
                'avg_rank' => $rankCount > 0 ? round($rankSum / $rankCount, 2) : null,
                'improved' => $rankImproved,
                'dropped' => $rankDropped,
                'flat' => $rankFlat,
                'rows' => $rankRows,
            ],
            'gsc' => [
                'has_cache' => is_array($gscCache) && !empty($gscCache),
                'overview' => [
                    'total_clicks' => (float) ($gscCache['total_clicks'] ?? 0),
                    'total_impressions' => (float) ($gscCache['total_impressions'] ?? 0),
                    'avg_ctr' => (float) ($gscCache['avg_ctr'] ?? 0),
                    'avg_position' => (float) ($gscCache['avg_position'] ?? 0),
                    'fetched_at' => (string) ($gscCache['fetched_at'] ?? ''),
                ],
                'top_queries' => $gscQueries,
                'top_pages' => $gscPages,
                'matched_query' => $matchedQuery,
                'matched_page' => $matchedPage,
            ],
            'insights' => [
                'summary' => $insightSummary,
                'rows' => $insights,
            ],
        ];
    }

    private function executeAiRequest(string $requestType, array $inputPayload, array $context): array
    {
        $apiKey = trim((string) Env::get('OPENAI_API_KEY', ''));
        if ($apiKey === '') {
            return $this->errorResponse('OPENAI_KEY_MISSING', 503, 'OPENAI_API_KEY is not configured.');
        }

        $model = trim((string) Env::get('OPENAI_MODEL', self::DEFAULT_MODEL));
        if ($model === '') {
            $model = self::DEFAULT_MODEL;
        }

        $maxTokens = (int) Env::get('AI_MAX_RESPONSE_TOKENS', (string) self::DEFAULT_MAX_RESPONSE_TOKENS);
        if ($maxTokens <= 0) {
            $maxTokens = self::DEFAULT_MAX_RESPONSE_TOKENS;
        }
        $maxTokens = min(900, max(180, $maxTokens));

        $timeoutSeconds = (int) Env::get('AI_HTTP_TIMEOUT', (string) self::DEFAULT_AI_TIMEOUT);
        if ($timeoutSeconds <= 0) {
            $timeoutSeconds = self::DEFAULT_AI_TIMEOUT;
        }
        $timeoutSeconds = min(55, max(10, $timeoutSeconds));

        $messages = $this->buildPromptMessages($requestType, $inputPayload, $context);

        $requestBody = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.2,
            'max_tokens' => $maxTokens,
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($requestBody),
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false || $rawResponse === '') {
            return $this->errorResponse('AI_TIMEOUT', 504, 'AI request timed out. Please retry.');
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            return $this->errorResponse('AI_INVALID_RESPONSE', 502, 'AI returned an invalid response payload.');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $apiMessage = (string) ($decoded['error']['message'] ?? 'AI request failed.');
            $friendly = 'AI request failed: ' . $apiMessage;
            if (str_contains(strtolower($apiMessage), 'rate limit')) {
                $friendly = 'AI provider rate limit reached. Please retry after a minute.';
            }
            return $this->errorResponse('AI_API_ERROR', $httpCode > 0 ? $httpCode : 502, $friendly);
        }

        $content = $this->extractChatContent($decoded);
        if ($content === '') {
            return $this->errorResponse('AI_EMPTY_CONTENT', 502, 'AI response was empty.');
        }

        $output = $this->decodeJsonObject($content);
        if (!is_array($output)) {
            return [
                'success' => true,
                'output' => [
                    'fallback_text' => $this->sanitizePlainText($content, 1600),
                ],
                'tokens_used' => $this->estimateTokens($content, $messages),
                'cost_estimate' => $this->estimateCostInr($this->estimateTokens($content, $messages)),
                'model' => (string) ($decoded['model'] ?? $model),
            ];
        }

        $tokensUsed = (int) ($decoded['usage']['total_tokens'] ?? 0);
        if ($tokensUsed <= 0) {
            $tokensUsed = $this->estimateTokens($content, $messages);
        }

        return [
            'success' => true,
            'output' => $output,
            'tokens_used' => $tokensUsed,
            'cost_estimate' => $this->estimateCostInr($tokensUsed),
            'model' => (string) ($decoded['model'] ?? $model),
        ];
    }

    private function buildPromptMessages(string $requestType, array $inputPayload, array $context): array
    {
        $systemPrompt = [
            'role' => 'system',
            'content' => implode("\n", [
                'You are an SEO intelligence assistant for a SaaS dashboard.',
                'Use only the provided data context. Never invent metrics, rankings, clicks, impressions, or URLs.',
                'If a required value is missing, explicitly use the phrase "Data unavailable".',
                'Return strict JSON only. No markdown. No commentary before or after JSON.',
            ]),
        ];

        $contextForPrompt = $this->summarizeContextForPrompt($context, $requestType, $inputPayload);
        $schemaHint = $this->schemaHintForRequestType($requestType);

        $userPrompt = [
            'role' => 'user',
            'content' => json_encode([
                'task' => $this->taskTextForRequestType($requestType),
                'schema' => $schemaHint,
                'input' => $inputPayload,
                'context' => $contextForPrompt,
                'rules' => [
                    'Use short, clear recommendations.',
                    'Cite metric evidence from context in rationale fields where applicable.',
                    'Do not include HTML tags.',
                ],
            ], JSON_UNESCAPED_SLASHES),
        ];

        return [$systemPrompt, $userPrompt];
    }

    private function summarizeContextForPrompt(array $context, string $requestType, array $inputPayload): array
    {
        $rankRows = is_array($context['ranking']['rows'] ?? null) ? (array) $context['ranking']['rows'] : [];
        $rankRows = array_slice($rankRows, 0, 12);

        $topQueries = is_array($context['gsc']['top_queries'] ?? null) ? (array) $context['gsc']['top_queries'] : [];
        $topQueries = array_slice($topQueries, 0, 12);

        $topPages = is_array($context['gsc']['top_pages'] ?? null) ? (array) $context['gsc']['top_pages'] : [];
        $topPages = array_slice($topPages, 0, 12);

        $insights = is_array($context['insights']['rows'] ?? null) ? (array) $context['insights']['rows'] : [];
        $insights = array_slice(array_map(function (array $row): array {
            return [
                'type' => (string) ($row['insight_type'] ?? ''),
                'severity' => (string) ($row['severity'] ?? 'info'),
                'keyword' => (string) ($row['keyword'] ?? ''),
                'page_url' => (string) ($row['page_url'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
            ];
        }, $insights), 0, 14);

        $summary = [
            'project' => [
                'name' => (string) ($context['project']['name'] ?? 'Project'),
                'domain' => (string) ($context['project']['domain'] ?? ''),
            ],
            'ranking_summary' => [
                'keywords_total' => (int) ($context['ranking']['keywords_total'] ?? 0),
                'avg_rank' => $context['ranking']['avg_rank'] ?? null,
                'improved' => (int) ($context['ranking']['improved'] ?? 0),
                'dropped' => (int) ($context['ranking']['dropped'] ?? 0),
                'flat' => (int) ($context['ranking']['flat'] ?? 0),
                'top_keywords' => $rankRows,
            ],
            'gsc_summary' => [
                'has_cache' => !empty($context['gsc']['has_cache']),
                'overview' => (array) ($context['gsc']['overview'] ?? []),
                'top_queries' => $topQueries,
                'top_pages' => $topPages,
                'matched_query' => $context['gsc']['matched_query'] ?? null,
                'matched_page' => $context['gsc']['matched_page'] ?? null,
            ],
            'insights_summary' => [
                'counts' => (array) ($context['insights']['summary'] ?? []),
                'rows' => $insights,
            ],
        ];

        if ($requestType === 'meta') {
            $summary['focus'] = [
                'target_keyword' => (string) ($inputPayload['target_keyword'] ?? ''),
                'page_url' => (string) ($inputPayload['page_url'] ?? ''),
                'current_title' => (string) ($inputPayload['current_title'] ?? ''),
                'current_meta_description' => (string) ($inputPayload['current_meta_description'] ?? ''),
            ];
        } elseif ($requestType === 'optimizer') {
            $summary['focus'] = [
                'target_keyword' => (string) ($inputPayload['target_keyword'] ?? ''),
                'page_url' => (string) ($inputPayload['page_url'] ?? ''),
                'current_headings' => (string) ($inputPayload['current_headings'] ?? ''),
                'content_summary' => (string) ($inputPayload['content_summary'] ?? ''),
            ];
        } else {
            $summary['focus'] = [
                'question' => (string) ($inputPayload['question'] ?? ''),
            ];
        }

        return $summary;
    }

    private function normalizeAiOutput(string $requestType, array $output, array $inputPayload, array $context): array
    {
        if ($requestType === 'meta') {
            $targetKeyword = (string) ($inputPayload['target_keyword'] ?? '');
            $projectName = (string) ($context['project']['name'] ?? 'Project');

            $optimizedTitle = $this->sanitizePlainText((string) ($output['optimized_title'] ?? ''), 120);
            if ($optimizedTitle === '') {
                $optimizedTitle = $targetKeyword !== ''
                    ? ucfirst($targetKeyword) . ' | ' . $projectName
                    : $projectName . ' - SEO Optimized Page';
            }

            $optimizedMeta = $this->sanitizePlainText((string) ($output['optimized_meta_description'] ?? ''), 220);
            if ($optimizedMeta === '') {
                $optimizedMeta = $targetKeyword !== ''
                    ? 'Explore ' . $targetKeyword . ' with practical tips, clear comparisons, and actionable next steps.'
                    : 'Discover actionable SEO insights, performance trends, and practical optimization recommendations.';
            }

            $ctrTitle = $this->sanitizePlainText((string) ($output['ctr_rewrite_title'] ?? ''), 120);
            if ($ctrTitle === '') {
                $ctrTitle = $optimizedTitle;
            }

            $ctrMeta = $this->sanitizePlainText((string) ($output['ctr_rewrite_description'] ?? ''), 220);
            if ($ctrMeta === '') {
                $ctrMeta = $optimizedMeta;
            }

            $notes = $this->sanitizeList($output['notes'] ?? [], 4, 180);
            if (empty($notes)) {
                $notes = [
                    'Used available ranking and GSC context for CTR-focused rewrite.',
                    'If impressions are high with low CTR, test the rewrite for 14 days.',
                ];
            }

            return [
                'optimized_title' => $optimizedTitle,
                'optimized_meta_description' => $optimizedMeta,
                'ctr_rewrite_title' => $ctrTitle,
                'ctr_rewrite_description' => $ctrMeta,
                'notes' => $notes,
            ];
        }

        if ($requestType === 'optimizer') {
            $missingHeadings = $this->sanitizeList($output['missing_headings'] ?? [], 8, 140);
            $keywordGaps = $this->sanitizeList($output['keyword_gaps'] ?? [], 8, 140);
            $quickWins = $this->sanitizeList($output['quick_wins'] ?? [], 8, 180);

            $contentImprovements = [];
            if (is_array($output['content_improvements'] ?? null)) {
                foreach ((array) $output['content_improvements'] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $point = $this->sanitizePlainText((string) ($item['item'] ?? ''), 180);
                    $why = $this->sanitizePlainText((string) ($item['why'] ?? ''), 220);
                    if ($point === '' && $why === '') {
                        continue;
                    }
                    $contentImprovements[] = [
                        'item' => $point !== '' ? $point : 'Content improvement',
                        'why' => $why !== '' ? $why : 'Data unavailable',
                    ];
                    if (count($contentImprovements) >= 8) {
                        break;
                    }
                }
            }

            if (empty($contentImprovements)) {
                $contentImprovements[] = [
                    'item' => 'Improve topical depth for primary intent.',
                    'why' => 'Data unavailable for detailed gap mapping. Start with competitor comparison and internal links.',
                ];
            }

            if (empty($quickWins)) {
                $quickWins = [
                    'Add missing section headings aligned with search intent.',
                    'Improve internal linking from related high-authority pages.',
                ];
            }

            return [
                'missing_headings' => $missingHeadings,
                'keyword_gaps' => $keywordGaps,
                'content_improvements' => $contentImprovements,
                'quick_wins' => $quickWins,
            ];
        }

        $answerSummary = $this->sanitizePlainText((string) ($output['answer_summary'] ?? ''), 500);
        if ($answerSummary === '') {
            $answerSummary = 'Use the prioritized actions below to improve rankings and click-through rate with available project data.';
        }

        $priorityActions = [];
        if (is_array($output['priority_actions'] ?? null)) {
            foreach ((array) $output['priority_actions'] as $action) {
                if (!is_array($action)) {
                    continue;
                }

                $title = $this->sanitizePlainText((string) ($action['action'] ?? $action['title'] ?? ''), 140);
                $reason = $this->sanitizePlainText((string) ($action['reason'] ?? ''), 220);
                $evidence = $this->sanitizePlainText((string) ($action['evidence'] ?? ''), 220);
                $priority = ucfirst(strtolower(trim((string) ($action['priority'] ?? 'Medium'))));
                if (!in_array($priority, ['High', 'Medium', 'Low'], true)) {
                    $priority = 'Medium';
                }

                if ($title === '' && $reason === '') {
                    continue;
                }

                $priorityActions[] = [
                    'action' => $title !== '' ? $title : 'SEO action',
                    'reason' => $reason !== '' ? $reason : 'Data unavailable',
                    'evidence' => $evidence !== '' ? $evidence : 'Data unavailable',
                    'priority' => $priority,
                ];

                if (count($priorityActions) >= 6) {
                    break;
                }
            }
        }

        if (empty($priorityActions)) {
            $priorityActions = [
                [
                    'action' => 'Review top keywords near page 1 and strengthen those pages.',
                    'reason' => 'Near-page-one terms can move with smaller optimization effort.',
                    'evidence' => 'Data unavailable',
                    'priority' => 'High',
                ],
                [
                    'action' => 'Improve title and meta copy for queries with low CTR.',
                    'reason' => 'Better SERP snippets can increase clicks without ranking gains.',
                    'evidence' => 'Data unavailable',
                    'priority' => 'Medium',
                ],
            ];
        }

        $watchouts = $this->sanitizeList($output['watchouts'] ?? [], 6, 180);
        $nextSteps = $this->sanitizeList($output['next_steps'] ?? [], 6, 180);

        return [
            'answer_summary' => $answerSummary,
            'priority_actions' => $priorityActions,
            'watchouts' => $watchouts,
            'next_steps' => $nextSteps,
        ];
    }

    private function decodeJsonObject(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $jsonBlock = $this->extractFirstJsonObject($content);
        if ($jsonBlock === null) {
            return null;
        }

        $decoded = json_decode($jsonBlock, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function extractFirstJsonObject(string $text): ?string
    {
        $length = strlen($text);
        $start = -1;
        $depth = 0;
        $inString = false;
        $escape = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($char === '\\') {
                    $escape = true;
                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
                continue;
            }

            if ($char === '}') {
                if ($depth > 0) {
                    $depth--;
                    if ($depth === 0 && $start >= 0) {
                        return substr($text, $start, $i - $start + 1);
                    }
                }
            }
        }

        return null;
    }

    private function extractChatContent(array $payload): string
    {
        $content = $payload['choices'][0]['message']['content'] ?? '';
        if (is_string($content)) {
            return trim($content);
        }

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $item) {
                if (is_array($item)) {
                    $partText = (string) ($item['text'] ?? '');
                    if ($partText !== '') {
                        $parts[] = $partText;
                    }
                } elseif (is_string($item) && trim($item) !== '') {
                    $parts[] = trim($item);
                }
            }
            return trim(implode("\n", $parts));
        }

        return '';
    }

    private function estimateTokens(string $content, array $messages): int
    {
        $chars = strlen($content);
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $chars += strlen((string) ($message['content'] ?? ''));
        }

        return max(1, (int) ceil($chars / 4));
    }

    private function estimateCostInr(int $tokensUsed): float
    {
        $rate = (float) Env::get('AI_COST_PER_1K_INR', (string) self::DEFAULT_COST_PER_1K_INR);
        if ($rate < 0) {
            $rate = self::DEFAULT_COST_PER_1K_INR;
        }

        $cost = ($tokensUsed / 1000) * $rate;
        return round(max(0.0, $cost), 4);
    }

    private function taskTextForRequestType(string $requestType): string
    {
        return match ($requestType) {
            'meta' => 'Generate SEO title and meta description variants focused on CTR uplift.',
            'optimizer' => 'Generate content optimization recommendations using only summarized metrics.',
            default => 'Answer the user SEO question with prioritized, data-backed actions.',
        };
    }

    private function schemaHintForRequestType(string $requestType): array
    {
        return match ($requestType) {
            'meta' => [
                'optimized_title' => 'string (<=60 recommended)',
                'optimized_meta_description' => 'string (<=160 recommended)',
                'ctr_rewrite_title' => 'string',
                'ctr_rewrite_description' => 'string',
                'notes' => ['string'],
            ],
            'optimizer' => [
                'missing_headings' => ['string'],
                'keyword_gaps' => ['string'],
                'content_improvements' => [
                    ['item' => 'string', 'why' => 'string'],
                ],
                'quick_wins' => ['string'],
            ],
            default => [
                'answer_summary' => 'string',
                'priority_actions' => [
                    ['action' => 'string', 'reason' => 'string', 'evidence' => 'string', 'priority' => 'High|Medium|Low'],
                ],
                'watchouts' => ['string'],
                'next_steps' => ['string'],
            ],
        };
    }

    private function sanitizePlainText(string $value, int $maxLength): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/\s+/', ' ', $value ?? '');
        $value = trim((string) $value);
        $value = preg_replace('/[^a-zA-Z0-9\s\-\.,:;!?\/(\)#@%&\'"\+]/', '', $value ?? '');
        if ($value === '') {
            return '';
        }

        return mb_substr($value, 0, max(1, $maxLength));
    }

    private function sanitizeKeyword(string $value): string
    {
        $value = $this->sanitizePlainText($value, self::MAX_KEYWORD_LENGTH);
        if ($value === '') {
            return '';
        }

        $value = strtolower($value);
        return trim($value);
    }

    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return '';
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }

        $normalized = $scheme . '://' . $host;
        if (!empty($parts['port'])) {
            $normalized .= ':' . (int) $parts['port'];
        }
        $normalized .= $path;
        if (!empty($parts['query'])) {
            $normalized .= '?' . (string) $parts['query'];
        }

        return mb_substr($normalized, 0, 2048);
    }

    private function sanitizeList($rows, int $maxItems, int $maxItemLength): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $text = $this->sanitizePlainText((string) $row, $maxItemLength);
            if ($text === '') {
                continue;
            }
            $result[] = $text;
            if (count($result) >= $maxItems) {
                break;
            }
        }

        return $result;
    }

    private function looksLikePromptInjection(array $payload): bool
    {
        $haystack = strtolower(trim(json_encode($payload)));
        if ($haystack === '') {
            return false;
        }

        $patterns = [
            '/ignore\s+(all\s+)?(previous|prior)\s+instructions/i',
            '/reveal\s+(the\s+)?(system|developer)\s+prompt/i',
            '/bypass\s+(security|guardrails|restrictions)/i',
            '/jailbreak/i',
            '/<\s*script/i',
            '/javascript:/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isAiEnabledGlobally(): bool
    {
        $value = strtolower(trim((string) $this->requestModel->getSecuritySetting('ai_global_enabled', '1')));
        if ($value === '0' || $value === 'false' || $value === 'off' || $value === 'no') {
            return false;
        }
        return true;
    }

    private function getConcurrencyLimit(): int
    {
        $value = (int) $this->requestModel->getSecuritySetting('ai_global_concurrency_limit', (string) self::DEFAULT_CONCURRENCY_LIMIT);
        return max(1, min(200, $value));
    }

    private function getMaxInputChars(): int
    {
        $value = (int) $this->requestModel->getSecuritySetting('ai_max_input_chars', (string) self::DEFAULT_MAX_INPUT_CHARS);
        return max(120, min(2000, $value));
    }

    private function defaultModel(): string
    {
        $model = trim((string) Env::get('OPENAI_MODEL', self::DEFAULT_MODEL));
        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    private function safeCountProcessing(): int
    {
        try {
            return $this->requestModel->countProcessing();
        } catch (Throwable $error) {
            return 0;
        }
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
