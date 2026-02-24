<?php

require_once __DIR__ . '/../models/BacklinkModel.php';
require_once __DIR__ . '/PlanEnforcementService.php';
require_once __DIR__ . '/../utils/SecurityValidator.php';
require_once __DIR__ . '/AlertDetectionService.php';
require_once __DIR__ . '/UsageMonitoringService.php';

class BacklinkService
{
    private const CACHE_HOURS = 24;
    private const RATE_LIMIT_PER_MINUTE = 6;

    private BacklinkModel $model;
    private PlanEnforcementService $planEnforcementService;
    private UsageMonitoringService $usageMonitoringService;
    private array $config;

    public function __construct(
        ?BacklinkModel $model = null,
        ?PlanEnforcementService $planEnforcementService = null,
        ?UsageMonitoringService $usageMonitoringService = null
    )
    {
        $this->model = $model ?? new BacklinkModel();
        $this->planEnforcementService = $planEnforcementService ?? new PlanEnforcementService();
        $this->usageMonitoringService = $usageMonitoringService ?? new UsageMonitoringService();
        $this->config = require __DIR__ . '/../config/config.php';
    }

    public function overview(string $domainInput, int $userId, string $planType): array
    {
        $requestKey = trim($domainInput);
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);

        $access = $this->planEnforcementService->assertFeatureAccess($userId, 'backlink_overview');
        if (empty($access['allowed'])) {
            $this->model->logRequest($userId, $requestKey, 'feature_locked', 403);
            return $this->errorResponse(
                'FEATURE_LOCKED',
                403,
                (string) ($access['message'] ?? 'Feature not available on your current plan.'),
                true,
                (string) ($access['required_plan'] ?? 'agency')
            );
        }

        $apiLimitCheck = $this->planEnforcementService->assertDailyApiLimit($userId, 'api_call.backlinks', 1);
        if (empty($apiLimitCheck['allowed'])) {
            $this->model->logRequest($userId, $requestKey, 'api_limit', 429);
            return $this->errorResponse('API_LIMIT', 429, (string) ($apiLimitCheck['message'] ?? 'Daily API usage limit reached.'));
        }

        $domain = SecurityValidator::sanitizeDomain($domainInput);
        if (!SecurityValidator::isValidDomain($domain)) {
            $this->model->logRequest($userId, $requestKey, 'invalid_domain', 422);
            return $this->errorResponse('INVALID_DOMAIN', 422, 'Enter a valid domain like example.com');
        }

        if ($this->model->countRecentRequests($userId, 60) >= self::RATE_LIMIT_PER_MINUTE) {
            $this->model->logRequest($userId, $domain, 'rate_limited', 429);
            return $this->errorResponse('RATE_LIMIT', 429, 'Too many backlink requests. Please wait one minute.');
        }

        $cached = $this->model->getCachedOverview($domain, self::CACHE_HOURS);
        if (!empty($cached)) {
            $this->model->logRequest($userId, $domain, 'cache', 200);
            $this->usageMonitoringService->logApiCall($userId, 'backlinks', null);
            return [
                'success' => true,
                'source' => 'cache',
                'domain' => $domain,
                'data' => $cached,
            ];
        }

        $data = $this->fetchFromApi($domain);
        $source = 'api';

        if (empty($data)) {
            $data = $this->generateSimulatedData($domain);
            $source = 'simulated';
        }

        $this->model->saveOverview($userId, $domain, $data, $source);
        $this->model->logRequest($userId, $domain, $source, 200);
        $this->usageMonitoringService->logApiCall($userId, 'backlinks', null);

        $this->triggerAlertMonitoring($userId, $planType);

        return [
            'success' => true,
            'source' => $source,
            'domain' => $domain,
            'data' => $data,
        ];
    }

    private function fetchFromApi(string $domain): array
    {
        $apiKey = trim((string) ($this->config['dataforseo_api_key'] ?? ''));
        if ($apiKey === '') {
            return [];
        }

        // Placeholder for real provider integration; keep fallback if parsing fails.
        return [];
    }

    private function generateSimulatedData(string $domain): array
    {
        $seed = abs(crc32($domain));

        $totalBacklinks = 1200 + ($seed % 300000);
        $refDomains = max(20, (int) round($totalBacklinks / (8 + ($seed % 9))));
        $dofollowPct = 48 + ($seed % 42);
        $nofollowPct = 100 - $dofollowPct;

        $anchorSamples = [
            ['text' => $domain, 'count' => 120 + ($seed % 2000)],
            ['text' => 'click here', 'count' => 50 + ($seed % 650)],
            ['text' => 'learn more', 'count' => 40 + ($seed % 480)],
            ['text' => 'official website', 'count' => 20 + ($seed % 350)],
            ['text' => 'read more', 'count' => 30 + ($seed % 330)],
            ['text' => 'brand page', 'count' => 15 + ($seed % 280)],
        ];

        $topLinkingDomains = [];
        for ($i = 1; $i <= 10; $i++) {
            $topLinkingDomains[] = [
                'domain' => 'ref' . $i . '.' . $domain,
                'backlinks' => max(10, (int) round(($totalBacklinks / ($i + 2)) * 0.22)),
                'authority' => 20 + (($seed + $i * 11) % 80),
            ];
        }

        $topBacklinks = [];
        for ($i = 1; $i <= 10; $i++) {
            $sourceDomain = 'source' . $i . '.example.net';
            $targetPath = $i % 2 === 0 ? '/blog/post-' . $i : '/features';
            $topBacklinks[] = [
                'source_url' => 'https://' . $sourceDomain . '/article-' . $i,
                'target_url' => 'https://' . $domain . $targetPath,
                'anchor' => $anchorSamples[$i % count($anchorSamples)]['text'],
                'link_type' => $i % 4 === 0 ? 'nofollow' : 'dofollow',
            ];
        }

        return [
            'summary' => [
                'total_backlinks' => $totalBacklinks,
                'referring_domains' => $refDomains,
                'dofollow_pct' => $dofollowPct,
                'nofollow_pct' => $nofollowPct,
            ],
            'top_anchor_texts' => array_slice($anchorSamples, 0, 5),
            'top_linking_domains' => array_slice($topLinkingDomains, 0, 10),
            'top_backlinks' => array_slice($topBacklinks, 0, 10),
            'link_type_distribution' => [
                'dofollow' => $dofollowPct,
                'nofollow' => $nofollowPct,
            ],
        ];
    }

    private function errorResponse(string $code, int $status, string $message, bool $upgradeRequired = false, string $requiredPlan = 'agency'): array
    {
        return [
            'success' => false,
            'status' => $status,
            'error_code' => $code,
            'error' => $message,
            'upgrade_required' => $upgradeRequired,
            'required_plan' => $requiredPlan,
        ];
    }

    private function triggerAlertMonitoring(int $userId, string $planType): void
    {
        try {
            $detector = new AlertDetectionService();
            $detector->runForUser($userId, $planType);
        } catch (Throwable $error) {
            error_log('Backlink alert trigger failed: ' . $error->getMessage());
        }
    }
}
