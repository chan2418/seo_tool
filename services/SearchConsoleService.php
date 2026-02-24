<?php

require_once __DIR__ . '/../models/SearchConsoleAccountModel.php';
require_once __DIR__ . '/../models/SearchConsoleDataModel.php';
require_once __DIR__ . '/../models/TrackedKeywordModel.php';
require_once __DIR__ . '/GoogleAuthService.php';
require_once __DIR__ . '/PlanEnforcementService.php';
require_once __DIR__ . '/UsageMonitoringService.php';
require_once __DIR__ . '/../utils/Env.php';

class SearchConsoleService
{
    private const CACHE_TTL_HOURS = 24;
    private const FREE_MAX_PROJECTS = 1;
    private const FREE_MAX_DAYS = 28;
    private const PRO_MAX_DAYS = 90;
    private const AGENCY_MAX_DAYS = 180;
    private const PRO_MANUAL_REFRESH_LIMIT_PER_DAY = 10;
    private const AGENCY_MANUAL_REFRESH_LIMIT_PER_DAY = 50;

    private SearchConsoleAccountModel $accountModel;
    private SearchConsoleDataModel $dataModel;
    private TrackedKeywordModel $trackedKeywordModel;
    private GoogleAuthService $googleAuthService;
    private PlanEnforcementService $planEnforcementService;
    private UsageMonitoringService $usageMonitoringService;
    private string $manualRefreshLogFile;
    private string $errorLogFile;

    public function __construct(
        ?SearchConsoleAccountModel $accountModel = null,
        ?SearchConsoleDataModel $dataModel = null,
        ?TrackedKeywordModel $trackedKeywordModel = null,
        ?GoogleAuthService $googleAuthService = null,
        ?PlanEnforcementService $planEnforcementService = null,
        ?UsageMonitoringService $usageMonitoringService = null
    ) {
        Env::load(dirname(__DIR__) . '/.env');
        $this->accountModel = $accountModel ?? new SearchConsoleAccountModel();
        $this->dataModel = $dataModel ?? new SearchConsoleDataModel();
        $this->trackedKeywordModel = $trackedKeywordModel ?? new TrackedKeywordModel();
        $this->googleAuthService = $googleAuthService ?? new GoogleAuthService();
        $this->planEnforcementService = $planEnforcementService ?? new PlanEnforcementService();
        $this->usageMonitoringService = $usageMonitoringService ?? new UsageMonitoringService();

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }
        $this->manualRefreshLogFile = $storageDir . '/search_console_manual_refresh_logs.json';
        $this->errorLogFile = $storageDir . '/search_console_errors.log';

        if (!file_exists($this->manualRefreshLogFile)) {
            file_put_contents($this->manualRefreshLogFile, json_encode([]));
        }
        if (!file_exists($this->errorLogFile)) {
            file_put_contents($this->errorLogFile, '');
        }
    }

    public function getProjectsAndConnections(int $userId): array
    {
        $this->trackedKeywordModel->syncProjectsFromAudits($userId);
        $projects = $this->trackedKeywordModel->getProjects($userId);
        $connections = $this->accountModel->getConnectionsByUser($userId);
        $map = [];
        foreach ($connections as $connection) {
            $map[(int) ($connection['project_id'] ?? 0)] = $connection;
        }

        return [
            'projects' => $projects,
            'connection_map' => $map,
            'connections_total' => count($connections),
        ];
    }

    public function getProjectConnection(int $userId, int $projectId): ?array
    {
        return $this->accountModel->getByProject($userId, $projectId);
    }

    public function saveConnectionFromOauth(
        int $userId,
        string $planType,
        int $projectId,
        string $googleProperty,
        string $accessToken,
        string $refreshToken,
        int $expiresIn
    ): array {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        $project = $this->trackedKeywordModel->getProjectById($userId, $projectId);
        if (!$project) {
            return $this->errorResponse('PROJECT_NOT_FOUND', 404, 'Project not found or access denied.');
        }

        $googleProperty = $this->sanitizeProperty($googleProperty);
        if ($googleProperty === '') {
            return $this->errorResponse('INVALID_PROPERTY', 422, 'Select a valid Google Search Console property.');
        }
        $projectDomain = $this->normalizeDomainHost((string) ($project['domain'] ?? ''));
        if ($projectDomain !== '' && !$this->isPropertyCompatibleWithProject($projectDomain, $googleProperty)) {
            return $this->errorResponse(
                'PROPERTY_MISMATCH',
                422,
                'Selected property does not match this project domain (' . $projectDomain . '). Choose the correct property.'
            );
        }
        if ($accessToken === '') {
            return $this->errorResponse('MISSING_TOKEN', 422, 'Google access token was not returned. Try reconnecting.');
        }

        $existing = $this->accountModel->getByProject($userId, $projectId);
        if ($planType === 'free' && !$existing) {
            $connectionsCount = $this->accountModel->countConnectionsByUser($userId);
            if ($connectionsCount >= self::FREE_MAX_PROJECTS) {
                return $this->errorResponse(
                    'PLAN_LIMIT',
                    403,
                    'Free plan supports only 1 connected project. Upgrade to connect more projects.',
                    true
                );
            }
        }

        try {
            $encryptedAccess = $this->googleAuthService->encryptToken($accessToken);
            $encryptedRefresh = '';
            if ($refreshToken !== '') {
                $encryptedRefresh = $this->googleAuthService->encryptToken($refreshToken);
            }
        } catch (Throwable $error) {
            $this->logApiError('token_encrypt', $error->getMessage(), $projectId);
            return $this->errorResponse('ENCRYPTION_ERROR', 500, 'Token encryption failed. Check GSC_TOKEN_ENCRYPTION_KEY.');
        }

        $expiry = date('Y-m-d H:i:s', time() + max(60, $expiresIn - 60));
        $saved = $this->accountModel->saveConnection(
            $userId,
            $projectId,
            $googleProperty,
            $encryptedAccess,
            $encryptedRefresh,
            $expiry
        );
        if (empty($saved['success'])) {
            return $this->errorResponse('SAVE_FAILED', 500, (string) ($saved['error'] ?? 'Unable to save Google Search Console connection.'));
        }

        return [
            'success' => true,
            'message' => 'Google Search Console connected successfully.',
            'account' => (array) ($saved['account'] ?? []),
        ];
    }

    public function disconnectProject(int $userId, int $projectId): array
    {
        $project = $this->trackedKeywordModel->getProjectById($userId, $projectId);
        if (!$project) {
            return $this->errorResponse('PROJECT_NOT_FOUND', 404, 'Project not found or access denied.');
        }

        $deleted = $this->accountModel->deleteByProject($userId, $projectId);
        if (!$deleted) {
            return $this->errorResponse('NOT_CONNECTED', 404, 'This project is not currently connected to Search Console.');
        }

        return [
            'success' => true,
            'message' => 'Search Console disconnected from project.',
        ];
    }

    public function listPropertiesFromAccessToken(string $accessToken): array
    {
        $accessToken = trim($accessToken);
        if ($accessToken === '') {
            return $this->errorResponse('MISSING_ACCESS_TOKEN', 422, 'Missing Google access token.');
        }

        $request = $this->apiRequest('https://www.googleapis.com/webmasters/v3/sites', $accessToken, 'GET');
        if (empty($request['success'])) {
            return $request;
        }

        $entries = (array) (($request['payload']['siteEntry'] ?? []));
        $properties = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $siteUrl = $this->sanitizeProperty((string) ($entry['siteUrl'] ?? ''));
            if ($siteUrl === '') {
                continue;
            }
            $permission = (string) ($entry['permissionLevel'] ?? '');
            if (stripos($permission, 'unverified') !== false) {
                continue;
            }
            $properties[] = [
                'property' => $siteUrl,
                'permission' => $permission,
            ];
        }

        usort($properties, static fn (array $a, array $b): int => strcmp($a['property'], $b['property']));
        return [
            'success' => true,
            'properties' => $properties,
        ];
    }

    public function fetchProjectPerformance(
        int $userId,
        string $planType,
        int $projectId,
        int $rangeDays = 28,
        bool $forceRefresh = false
    ): array {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        $project = $this->trackedKeywordModel->getProjectById($userId, $projectId);
        if (!$project) {
            return $this->errorResponse('PROJECT_NOT_FOUND', 404, 'Project not found or access denied.');
        }

        $connection = $this->accountModel->getByProject($userId, $projectId);
        if (!$connection) {
            return $this->errorResponse('NOT_CONNECTED', 404, 'Connect Google Search Console to this project first.');
        }
        $projectDomain = $this->normalizeDomainHost((string) ($project['domain'] ?? ''));
        $connectedProperty = $this->sanitizeProperty((string) ($connection['google_property'] ?? ''));
        if ($projectDomain !== '' && $connectedProperty !== '' && !$this->isPropertyCompatibleWithProject($projectDomain, $connectedProperty)) {
            return $this->errorResponse(
                'PROPERTY_PROJECT_MISMATCH',
                422,
                'Connected Search Console property does not match this project domain. Disconnect and reconnect the correct property.'
            );
        }

        $rangeDays = $this->sanitizeRangeDays($rangeDays, $planType);
        $dateRange = 'last_' . $rangeDays . '_days';

        if ($forceRefresh) {
            if (!$this->canManualRefresh($planType)) {
                return $this->errorResponse('REFRESH_NOT_ALLOWED', 403, 'Manual refresh is available on Pro and Agency plans.', true);
            }
            if (!$this->allowManualRefresh($userId, $planType)) {
                return $this->errorResponse('REFRESH_LIMIT', 429, 'Manual refresh limit reached for today. Try again later.');
            }
        }

        $existingCache = $this->dataModel->getLatestCache($projectId, $dateRange);
        $cacheFresh = $this->dataModel->isCacheFresh($projectId, $dateRange, self::CACHE_TTL_HOURS);
        $cacheIsEmpty = false;
        if ($existingCache) {
            $cacheIsEmpty = ((float) ($existingCache['total_clicks'] ?? 0) <= 0)
                && ((float) ($existingCache['total_impressions'] ?? 0) <= 0)
                && empty($existingCache['trend']);
        }
        $connectionChangedAfterCache = false;
        if ($existingCache) {
            $cacheFetchedTs = strtotime((string) ($existingCache['fetched_at'] ?? ''));
            $connectionUpdatedTs = strtotime((string) ($connection['updated_at'] ?? ''));
            if ($cacheFetchedTs !== false && $connectionUpdatedTs !== false && $connectionUpdatedTs > ($cacheFetchedTs + 30)) {
                $connectionChangedAfterCache = true;
            }
        }
        $emptyCacheRetryDue = false;
        if ($cacheIsEmpty) {
            $fetchedAtTs = strtotime((string) ($existingCache['fetched_at'] ?? ''));
            $emptyCacheRetryDue = ($fetchedAtTs === false) || ($fetchedAtTs <= (time() - 3600));
        }

        $needsRefresh = $forceRefresh || !$cacheFresh || $emptyCacheRetryDue || $connectionChangedAfterCache;
        if ($needsRefresh) {
            $sync = $this->syncConnectionData($connection, $planType, $rangeDays);
            if (empty($sync['success'])) {
                return $sync;
            }
        }

        return $this->buildPerformanceResponse($projectId, $project, $connection, $planType, $dateRange);
    }

    public function getDashboardSnapshot(int $userId, string $planType): array
    {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        $connections = $this->accountModel->getConnectionsByUser($userId);
        if (empty($connections)) {
            return [
                'connected' => false,
                'message' => 'Google Search Console not connected yet.',
            ];
        }

        $best = null;
        $bestConnection = null;
        foreach ($connections as $connection) {
            $projectId = (int) ($connection['project_id'] ?? 0);
            if ($projectId <= 0) {
                continue;
            }
            $cache = $this->dataModel->getLatestCache($projectId, 'last_28_days');
            if (!$cache) {
                continue;
            }
            if ($best === null || (string) ($cache['fetched_at'] ?? '') > (string) ($best['fetched_at'] ?? '')) {
                $best = $cache;
                $bestConnection = $connection;
            }
        }

        if (!$best || !$bestConnection) {
            return [
                'connected' => true,
                'has_data' => false,
                'message' => 'Connected, waiting for first sync.',
            ];
        }

        $project = $this->trackedKeywordModel->getProjectById($userId, (int) ($bestConnection['project_id'] ?? 0));
        $queries = $planType === 'free' ? [] : $this->dataModel->getQueries((int) ($bestConnection['project_id'] ?? 0), 'last_28_days', 5);

        return [
            'connected' => true,
            'has_data' => true,
            'project_id' => (int) ($bestConnection['project_id'] ?? 0),
            'project_name' => (string) ($project['name'] ?? 'Project'),
            'google_property' => (string) ($bestConnection['google_property'] ?? ''),
            'total_clicks' => (float) ($best['total_clicks'] ?? 0),
            'total_impressions' => (float) ($best['total_impressions'] ?? 0),
            'avg_ctr' => (float) ($best['avg_ctr'] ?? 0),
            'avg_position' => (float) ($best['avg_position'] ?? 0),
            'trend' => (array) ($best['trend'] ?? []),
            'top_queries' => $queries,
            'last_updated' => (string) ($best['fetched_at'] ?? ''),
        ];
    }

    public function syncAllConnectedProjects(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $connections = $this->accountModel->getAllConnections($limit);

        $summary = [
            'success' => true,
            'checked_date' => date('Y-m-d'),
            'connections_total' => count($connections),
            'processed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($connections as $connection) {
            $projectId = (int) ($connection['project_id'] ?? 0);
            $userId = (int) ($connection['user_id'] ?? 0);
            $planType = $this->planEnforcementService->getEffectivePlan($userId, (string) ($connection['plan_type'] ?? 'free'));
            if ($projectId <= 0 || $userId <= 0) {
                $summary['skipped']++;
                continue;
            }

            if ($this->dataModel->isCacheFresh($projectId, 'last_28_days', self::CACHE_TTL_HOURS)) {
                $summary['skipped']++;
                continue;
            }

            $result = $this->syncConnectionData($connection, $planType, 28);
            if (!empty($result['success'])) {
                $summary['processed']++;
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

    private function syncConnectionData(array $connection, string $planType, int $rangeDays): array
    {
        $accountId = (int) ($connection['id'] ?? 0);
        $projectId = (int) ($connection['project_id'] ?? 0);
        $googleProperty = $this->sanitizeProperty((string) ($connection['google_property'] ?? ''));
        if ($accountId <= 0 || $projectId <= 0 || $googleProperty === '') {
            return $this->errorResponse('INVALID_CONNECTION', 422, 'Google Search Console connection is invalid.');
        }

        $token = $this->resolveAccessToken($connection);
        if (empty($token['success'])) {
            return $token;
        }
        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === '') {
            return $this->errorResponse('TOKEN_ERROR', 401, 'Unable to authorize Search Console API request.');
        }

        $rangeDays = max(1, min($rangeDays, self::AGENCY_MAX_DAYS));
        $dateRange = 'last_' . $rangeDays . '_days';
        $startDate = date('Y-m-d', strtotime('-' . $rangeDays . ' days'));
        $endDate = date('Y-m-d', strtotime('-1 day'));
        if ($startDate > $endDate) {
            $startDate = $endDate;
        }
        $projectForConnection = $this->trackedKeywordModel->getProjectById(
            (int) ($connection['user_id'] ?? 0),
            (int) ($connection['project_id'] ?? 0)
        );
        $connectionProjectDomain = $this->normalizeDomainHost((string) ($projectForConnection['domain'] ?? ''));
        if ($connectionProjectDomain !== '' && !$this->isPropertyCompatibleWithProject($connectionProjectDomain, $googleProperty)) {
            return $this->errorResponse(
                'PROPERTY_PROJECT_MISMATCH',
                422,
                'Connected Search Console property does not match project domain. Reconnect the correct property.'
            );
        }

        $scope = $this->resolveProjectScope($connection, $googleProperty);
        $pageFilterExpression = (string) ($scope['page_filter_expression'] ?? '');

        $overview = $this->fetchOverviewAndTrend($googleProperty, $accessToken, $startDate, $endDate, $pageFilterExpression);
        if (empty($overview['success'])) {
            return $overview;
        }

        $queries = [];
        $pages = [];
        if ($planType !== 'free') {
            $queriesResponse = $this->fetchDimensionRows($googleProperty, $accessToken, $startDate, $endDate, 'query', 50, $pageFilterExpression);
            if (empty($queriesResponse['success'])) {
                return $queriesResponse;
            }
            $pagesResponse = $this->fetchDimensionRows($googleProperty, $accessToken, $startDate, $endDate, 'page', 50, $pageFilterExpression);
            if (empty($pagesResponse['success'])) {
                return $pagesResponse;
            }
            $queries = (array) ($queriesResponse['rows'] ?? []);
            $pages = (array) ($pagesResponse['rows'] ?? []);
        }

        $savedCache = $this->dataModel->saveCache(
            $projectId,
            $dateRange,
            (float) ($overview['total_clicks'] ?? 0),
            (float) ($overview['total_impressions'] ?? 0),
            (float) ($overview['avg_ctr'] ?? 0),
            (float) ($overview['avg_position'] ?? 0),
            (array) ($overview['trend'] ?? [])
        );
        if (!$savedCache) {
            return $this->errorResponse('CACHE_SAVE_FAILED', 500, 'Unable to cache Search Console overview data.');
        }

        if ($planType !== 'free') {
            $this->dataModel->saveQueries($projectId, $dateRange, $queries);
            $this->dataModel->savePages($projectId, $dateRange, $pages);
        }
        $this->usageMonitoringService->logGscSync((int) ($connection['user_id'] ?? 0), $projectId);
        $this->usageMonitoringService->logApiCall((int) ($connection['user_id'] ?? 0), 'search_console_sync', $projectId);

        return [
            'success' => true,
            'message' => 'Search Console data synchronized.',
        ];
    }

    private function buildPerformanceResponse(
        int $projectId,
        array $project,
        array $connection,
        string $planType,
        string $dateRange
    ): array {
        $cache = $this->dataModel->getLatestCache($projectId, $dateRange);
        if (!$cache) {
            return [
                'success' => true,
                'project' => $project,
                'connection' => [
                    'connected' => true,
                    'google_property' => (string) ($connection['google_property'] ?? ''),
                ],
                'date_range' => $dateRange,
                'overview' => [
                    'total_clicks' => 0,
                    'total_impressions' => 0,
                    'avg_ctr' => 0,
                    'avg_position' => 0,
                ],
                'trend' => [],
                'top_queries' => [],
                'top_pages' => [],
                'last_updated' => null,
                'message' => 'No Search Console data available yet. Run a sync to fetch first data.',
                'detailed_data_available' => $planType !== 'free',
            ];
        }

        $topQueries = $planType === 'free' ? [] : $this->dataModel->getQueries($projectId, $dateRange, 50);
        $topPages = $planType === 'free' ? [] : $this->dataModel->getPages($projectId, $dateRange, 50);
        $hasData = ((float) ($cache['total_clicks'] ?? 0) > 0)
            || ((float) ($cache['total_impressions'] ?? 0) > 0)
            || !empty($cache['trend']);
        $scope = $this->resolveProjectScope($connection, (string) ($connection['google_property'] ?? ''));

        return [
            'success' => true,
            'project' => $project,
            'connection' => [
                'connected' => true,
                'google_property' => (string) ($connection['google_property'] ?? ''),
            ],
            'scope' => $scope,
            'date_range' => $dateRange,
            'overview' => [
                'total_clicks' => (float) ($cache['total_clicks'] ?? 0),
                'total_impressions' => (float) ($cache['total_impressions'] ?? 0),
                'avg_ctr' => (float) ($cache['avg_ctr'] ?? 0),
                'avg_position' => (float) ($cache['avg_position'] ?? 0),
            ],
            'trend' => (array) ($cache['trend'] ?? []),
            'top_queries' => $topQueries,
            'top_pages' => $topPages,
            'last_updated' => (string) ($cache['fetched_at'] ?? ''),
            'detailed_data_available' => $planType !== 'free',
            'has_data' => $hasData,
            'message' => $hasData ? '' : 'No Search Console data found for this property in the selected range yet.',
        ];
    }

    private function resolveAccessToken(array $connection): array
    {
        $accountId = (int) ($connection['id'] ?? 0);
        $encryptedAccess = (string) ($connection['access_token'] ?? '');
        $encryptedRefresh = (string) ($connection['refresh_token'] ?? '');
        $tokenExpiry = (string) ($connection['token_expiry'] ?? '');

        try {
            $accessToken = $this->googleAuthService->decryptToken($encryptedAccess);
            $refreshToken = $this->googleAuthService->decryptToken($encryptedRefresh);
        } catch (Throwable $error) {
            $this->logApiError('token_decrypt', $error->getMessage(), (int) ($connection['project_id'] ?? 0));
            return $this->errorResponse('TOKEN_DECRYPT_FAILED', 500, 'Unable to decrypt stored Google tokens.');
        }

        if ($accessToken === '' && $refreshToken === '') {
            return $this->errorResponse('TOKEN_MISSING', 401, 'Stored Google tokens are invalid. Reconnect Search Console.');
        }

        $expiryTs = strtotime($tokenExpiry);
        if ($accessToken !== '' && $expiryTs !== false && $expiryTs > (time() + 120)) {
            return ['success' => true, 'access_token' => $accessToken];
        }
        if ($refreshToken === '') {
            return $this->errorResponse('REFRESH_TOKEN_MISSING', 401, 'Google refresh token is missing. Reconnect Search Console.');
        }

        $refreshed = $this->googleAuthService->refreshAccessToken($refreshToken);
        if (empty($refreshed['success'])) {
            $this->logApiError('token_refresh', (string) ($refreshed['error'] ?? 'unknown'), (int) ($connection['project_id'] ?? 0));
            return $this->errorResponse('TOKEN_REFRESH_FAILED', 401, 'Google authorization expired. Please reconnect Search Console.');
        }

        try {
            $newEncryptedAccess = $this->googleAuthService->encryptToken((string) ($refreshed['access_token'] ?? ''));
            $newEncryptedRefresh = '';
            if (!empty($refreshed['refresh_token'])) {
                $newEncryptedRefresh = $this->googleAuthService->encryptToken((string) $refreshed['refresh_token']);
            }
            $newExpiry = date('Y-m-d H:i:s', time() + max(60, ((int) ($refreshed['expires_in'] ?? 3600) - 60)));
            $this->accountModel->updateTokensById($accountId, $newEncryptedAccess, $newEncryptedRefresh, $newExpiry);
        } catch (Throwable $error) {
            $this->logApiError('token_store_refresh', $error->getMessage(), (int) ($connection['project_id'] ?? 0));
            return $this->errorResponse('TOKEN_STORE_FAILED', 500, 'Failed to update refreshed token in storage.');
        }

        return [
            'success' => true,
            'access_token' => (string) ($refreshed['access_token'] ?? ''),
        ];
    }

    private function fetchOverviewAndTrend(
        string $property,
        string $accessToken,
        string $startDate,
        string $endDate,
        string $pageFilterExpression = ''
    ): array
    {
        // Fetch overview totals separately so cards can still show values even when date rows are sparse.
        $overviewPayload = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'rowLimit' => 1,
            'dataState' => 'all',
        ];
        $overviewResponse = $this->searchAnalyticsQuery($property, $accessToken, $overviewPayload, $pageFilterExpression);
        if (empty($overviewResponse['success'])) {
            return $overviewResponse;
        }
        $overviewRows = is_array($overviewResponse['rows'] ?? null) ? $overviewResponse['rows'] : [];

        $totalClicks = 0.0;
        $totalImpressions = 0.0;
        $avgCtr = 0.0;
        $avgPosition = 0.0;
        if (!empty($overviewRows[0]) && is_array($overviewRows[0])) {
            $totalClicks = (float) ($overviewRows[0]['clicks'] ?? 0);
            $totalImpressions = (float) ($overviewRows[0]['impressions'] ?? 0);
            $avgCtr = (float) ($overviewRows[0]['ctr'] ?? 0);
            $avgPosition = (float) ($overviewRows[0]['position'] ?? 0);
        }

        $trendPayload = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['date'],
            'rowLimit' => 5000,
            'dataState' => 'all',
        ];
        $response = $this->searchAnalyticsQuery($property, $accessToken, $trendPayload, $pageFilterExpression);
        if (empty($response['success'])) {
            return $response;
        }

        $rows = is_array($response['rows'] ?? null) ? $response['rows'] : [];
        $trend = [];
        $trendTotalClicks = 0.0;
        $trendTotalImpressions = 0.0;
        $trendPositionWeighted = 0.0;
        $trendPositionFallback = 0.0;
        $trendPositionCount = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $keys = is_array($row['keys'] ?? null) ? $row['keys'] : [];
            $date = (string) ($keys[0] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $clicks = (float) ($row['clicks'] ?? 0);
            $impressions = (float) ($row['impressions'] ?? 0);
            $position = (float) ($row['position'] ?? 0);

            $trendTotalClicks += $clicks;
            $trendTotalImpressions += $impressions;
            $trendPositionWeighted += ($position * $impressions);
            $trendPositionFallback += $position;
            $trendPositionCount++;

            $trend[] = [
                'date' => $date,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'position' => $position,
            ];
        }

        usort($trend, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
        if ($totalClicks <= 0 && $totalImpressions <= 0 && !empty($trend)) {
            // Fallback to trend rollup when aggregated row is missing.
            $totalClicks = $trendTotalClicks;
            $totalImpressions = $trendTotalImpressions;
            $avgCtr = $trendTotalImpressions > 0 ? ($trendTotalClicks / $trendTotalImpressions) : 0.0;
            $avgPosition = $trendTotalImpressions > 0
                ? ($trendPositionWeighted / $trendTotalImpressions)
                : ($trendPositionCount > 0 ? ($trendPositionFallback / $trendPositionCount) : 0.0);
        }

        return [
            'success' => true,
            'total_clicks' => round($totalClicks, 2),
            'total_impressions' => round($totalImpressions, 2),
            'avg_ctr' => round($avgCtr, 6),
            'avg_position' => round($avgPosition, 4),
            'trend' => $trend,
        ];
    }

    private function fetchDimensionRows(
        string $property,
        string $accessToken,
        string $startDate,
        string $endDate,
        string $dimension,
        int $limit,
        string $pageFilterExpression = ''
    ): array {
        $dimension = strtolower(trim($dimension));
        if (!in_array($dimension, ['query', 'page'], true)) {
            return $this->errorResponse('INVALID_DIMENSION', 422, 'Invalid Search Console dimension requested.');
        }

        $payload = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => [$dimension],
            'rowLimit' => max(1, min(200, $limit)),
        ];
        $response = $this->searchAnalyticsQuery($property, $accessToken, $payload, $pageFilterExpression);
        if (empty($response['success'])) {
            return $response;
        }

        $rows = is_array($response['rows'] ?? null) ? $response['rows'] : [];
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $keys = is_array($row['keys'] ?? null) ? $row['keys'] : [];
            $value = trim((string) ($keys[0] ?? ''));
            if ($value === '') {
                continue;
            }
            $result[] = [
                $dimension === 'query' ? 'query' : 'page_url' => $value,
                'clicks' => (float) ($row['clicks'] ?? 0),
                'impressions' => (float) ($row['impressions'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0),
                'position' => (float) ($row['position'] ?? 0),
            ];
        }

        return [
            'success' => true,
            'rows' => $result,
        ];
    }

    private function searchAnalyticsQuery(string $property, string $accessToken, array $payload, string $pageFilterExpression = ''): array
    {
        $property = $this->sanitizeProperty($property);
        if ($property === '') {
            return $this->errorResponse('INVALID_PROPERTY', 422, 'Invalid Google property selected.');
        }
        $pageFilterExpression = trim($pageFilterExpression);
        if ($pageFilterExpression !== '') {
            $payload['dimensionFilterGroups'] = [
                [
                    'groupType' => 'and',
                    'filters' => [
                        [
                            'dimension' => 'page',
                            'operator' => 'contains',
                            'expression' => $pageFilterExpression,
                        ],
                    ],
                ],
            ];
        }

        $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/searchAnalytics/query';
        return $this->apiRequest($url, $accessToken, 'POST', $payload);
    }

    private function apiRequest(string $url, string $accessToken, string $method = 'GET', ?array $payload = null): array
    {
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers,
        ];
        $method = strtoupper(trim($method));
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload ?? []);
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            $this->logApiError('curl_error', $curlError, 0);
            return $this->errorResponse('API_TIMEOUT', 502, 'Google API request timeout. Please try again.');
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $this->logApiError('api_invalid_json', substr($response, 0, 400), 0);
            return $this->errorResponse('API_INVALID_RESPONSE', 502, 'Google API returned an invalid response.');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $this->extractGoogleErrorMessage($decoded);
            $friendly = $this->friendlyErrorMessage($httpCode, $message);
            $this->logApiError('google_api_' . $httpCode, $message, 0);
            return $this->errorResponse('GOOGLE_API_ERROR', $httpCode, $friendly);
        }

        return [
            'success' => true,
            'payload' => $decoded,
            'rows' => (array) ($decoded['rows'] ?? []),
        ];
    }

    private function sanitizeRangeDays(int $rangeDays, string $planType): int
    {
        $max = $this->maxDaysForPlan($planType);
        $rangeDays = max(7, $rangeDays);
        return min($max, $rangeDays);
    }

    private function maxDaysForPlan(string $planType): int
    {
        $planType = $this->normalizePlan($planType);
        if ($planType === 'agency') {
            return self::AGENCY_MAX_DAYS;
        }
        if ($planType === 'pro') {
            return self::PRO_MAX_DAYS;
        }
        return self::FREE_MAX_DAYS;
    }

    private function canManualRefresh(string $planType): bool
    {
        return in_array($this->normalizePlan($planType), ['pro', 'agency'], true);
    }

    private function allowManualRefresh(int $userId, string $planType): bool
    {
        $planType = $this->normalizePlan($planType);
        $limit = $planType === 'agency' ? self::AGENCY_MANUAL_REFRESH_LIMIT_PER_DAY : self::PRO_MANUAL_REFRESH_LIMIT_PER_DAY;

        $rows = $this->readJsonFile($this->manualRefreshLogFile);
        $today = date('Y-m-d');
        $count = 0;
        foreach ($rows as $row) {
            if ((int) ($row['user_id'] ?? 0) !== $userId) {
                continue;
            }
            if (strpos((string) ($row['created_at'] ?? ''), $today) !== 0) {
                continue;
            }
            $count++;
        }
        if ($count >= $limit) {
            return false;
        }

        $rows[] = [
            'user_id' => $userId,
            'plan_type' => $planType,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        if (count($rows) > 20000) {
            $rows = array_slice($rows, -20000);
        }
        $this->writeJsonFile($this->manualRefreshLogFile, $rows);
        return true;
    }

    private function sanitizeProperty(string $googleProperty): string
    {
        $googleProperty = trim($googleProperty);
        if ($googleProperty === '') {
            return '';
        }
        if (str_starts_with($googleProperty, 'sc-domain:')) {
            return mb_substr($googleProperty, 0, 255);
        }
        if (preg_match('/^https?:\/\/[^\s]+$/i', $googleProperty) === 1) {
            $parts = parse_url($googleProperty);
            if (!is_array($parts)) {
                return '';
            }
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                return '';
            }
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            $path = (string) ($parts['path'] ?? '/');
            if ($path === '') {
                $path = '/';
            }
            $normalized = $scheme . '://' . $host . $port . $path;
            if (!empty($parts['query'])) {
                $normalized .= '?' . (string) $parts['query'];
            }
            return mb_substr($normalized, 0, 2048);
        }
        return '';
    }

    private function resolveProjectScope(array $connection, string $googleProperty): array
    {
        $projectId = (int) ($connection['project_id'] ?? 0);
        $userId = (int) ($connection['user_id'] ?? 0);
        $projectDomain = '';
        if ($projectId > 0 && $userId > 0) {
            $project = $this->trackedKeywordModel->getProjectById($userId, $projectId);
            if (is_array($project)) {
                $projectDomain = $this->normalizeDomainHost((string) ($project['domain'] ?? ''));
            }
        }

        $propertyMeta = $this->parseGoogleProperty($googleProperty);
        $propertyHost = (string) ($propertyMeta['host'] ?? '');
        $propertyPath = (string) ($propertyMeta['path'] ?? '/');
        $scopeHost = $projectDomain !== '' ? $projectDomain : $propertyHost;

        $pageFilterExpression = '';
        if ($propertyMeta['type'] === 'url-prefix' && !empty($propertyMeta['prefix'])) {
            $pageFilterExpression = (string) $propertyMeta['prefix'];
        } elseif ($scopeHost !== '') {
            $pageFilterExpression = '://' . $scopeHost . '/';
        }

        return [
            'project_domain' => $projectDomain,
            'property_type' => (string) ($propertyMeta['type'] ?? 'unknown'),
            'property_host' => $propertyHost,
            'property_path' => $propertyPath,
            'page_filter_expression' => $pageFilterExpression,
        ];
    }

    private function parseGoogleProperty(string $googleProperty): array
    {
        $googleProperty = trim($googleProperty);
        if ($googleProperty === '') {
            return [
                'type' => 'unknown',
                'host' => '',
                'path' => '/',
                'prefix' => '',
            ];
        }

        if (str_starts_with($googleProperty, 'sc-domain:')) {
            $domain = $this->normalizeDomainHost(substr($googleProperty, strlen('sc-domain:')));
            return [
                'type' => 'domain',
                'host' => $domain,
                'path' => '/',
                'prefix' => '',
            ];
        }

        $parts = parse_url($googleProperty);
        if (!is_array($parts)) {
            return [
                'type' => 'unknown',
                'host' => '',
                'path' => '/',
                'prefix' => '',
            ];
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $this->normalizeDomainHost((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $prefix = '';
        if (in_array($scheme, ['http', 'https'], true) && $host !== '') {
            $prefix = $scheme . '://' . $host . $port . $path;
        }

        return [
            'type' => 'url-prefix',
            'host' => $host,
            'path' => $path,
            'prefix' => $prefix,
        ];
    }

    private function normalizeDomainHost(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/^https?:\/\//i', '', $value);
        $value = preg_replace('/\/.*$/', '', (string) $value);
        $value = preg_replace('/:\d+$/', '', (string) $value);
        $value = trim((string) $value);
        return $value;
    }

    private function isPropertyCompatibleWithProject(string $projectDomain, string $googleProperty): bool
    {
        $projectDomain = $this->normalizeDomainHost($projectDomain);
        if ($projectDomain === '') {
            return true;
        }
        $property = $this->parseGoogleProperty($googleProperty);
        $propertyHost = $this->normalizeDomainHost((string) ($property['host'] ?? ''));
        if ($propertyHost === '') {
            return true;
        }
        $propertyType = (string) ($property['type'] ?? 'unknown');

        if ($propertyType === 'url-prefix') {
            if ($propertyHost === $projectDomain) {
                return true;
            }
            return $this->stripLeadingWww($propertyHost) === $this->stripLeadingWww($projectDomain);
        }

        if ($propertyHost === $projectDomain) {
            return true;
        }

        return str_ends_with($propertyHost, '.' . $projectDomain)
            || str_ends_with($projectDomain, '.' . $propertyHost);
    }

    private function stripLeadingWww(string $host): string
    {
        $host = $this->normalizeDomainHost($host);
        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }
        return $host;
    }

    private function normalizePlan(string $planType): string
    {
        $planType = strtolower(trim($planType));
        if (!in_array($planType, ['free', 'pro', 'agency'], true)) {
            return 'free';
        }
        return $planType;
    }

    private function extractGoogleErrorMessage(array $payload): string
    {
        $error = $payload['error'] ?? null;
        if (is_string($error)) {
            return $error;
        }
        if (is_array($error)) {
            $message = (string) ($error['message'] ?? '');
            if ($message !== '') {
                return $message;
            }
            $errors = $error['errors'] ?? null;
            if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
                $candidate = (string) ($errors[0]['message'] ?? '');
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }
        return 'Google API request failed.';
    }

    private function friendlyErrorMessage(int $statusCode, string $message): string
    {
        $lower = strtolower($message);
        if ($statusCode === 401 || str_contains($lower, 'invalid credentials')) {
            return 'Google authorization expired. Please reconnect Search Console.';
        }
        if ($statusCode === 403 && (str_contains($lower, 'permission') || str_contains($lower, 'insufficient'))) {
            return 'Permission denied for this property. Ensure your Google account has access.';
        }
        if ($statusCode === 403 && str_contains($lower, 'quota')) {
            return 'Google API quota exceeded. Please try again later.';
        }
        if ($statusCode === 404 || str_contains($lower, 'not found')) {
            return 'Selected Search Console property was not found.';
        }
        if (str_contains($lower, 'timeout')) {
            return 'Google API timeout. Please try again in a few minutes.';
        }
        if (str_contains($lower, 'no data')) {
            return 'No Search Console data available for the selected date range.';
        }
        return 'Google Search Console request failed: ' . $message;
    }

    private function errorResponse(string $code, int $status, string $message, bool $upgradeRequired = false): array
    {
        return [
            'success' => false,
            'status' => $status,
            'error_code' => $code,
            'error' => $message,
            'upgrade_required' => $upgradeRequired,
        ];
    }

    private function logApiError(string $context, string $message, int $projectId): void
    {
        $line = date('Y-m-d H:i:s') . ' [' . $context . '] project=' . $projectId . ' :: ' . preg_replace('/\s+/', ' ', $message);
        file_put_contents($this->errorLogFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function readJsonFile(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeJsonFile(string $path, array $rows): void
    {
        file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
