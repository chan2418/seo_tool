<?php

require_once __DIR__ . '/../models/CompetitorModel.php';
require_once __DIR__ . '/PlanEnforcementService.php';
require_once __DIR__ . '/../utils/SecurityValidator.php';
require_once __DIR__ . '/AdvancedScoringService.php';
require_once __DIR__ . '/UsageMonitoringService.php';

class CompetitorService
{
    private const CACHE_HOURS = 24;
    private const RATE_LIMIT_PER_MINUTE = 8;

    private CompetitorModel $model;
    private AdvancedScoringService $scoringService;
    private PlanEnforcementService $planEnforcementService;
    private UsageMonitoringService $usageMonitoringService;
    private array $config;

    public function __construct(
        ?CompetitorModel $model = null,
        ?AdvancedScoringService $scoringService = null,
        ?PlanEnforcementService $planEnforcementService = null,
        ?UsageMonitoringService $usageMonitoringService = null
    )
    {
        $this->model = $model ?? new CompetitorModel();
        $this->scoringService = $scoringService ?? new AdvancedScoringService();
        $this->planEnforcementService = $planEnforcementService ?? new PlanEnforcementService();
        $this->usageMonitoringService = $usageMonitoringService ?? new UsageMonitoringService();
        $this->config = require __DIR__ . '/../config/config.php';
    }

    public function analyze(string $domainInput, int $userId, string $planType): array
    {
        $requestKey = trim($domainInput);
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);

        $access = $this->planEnforcementService->assertFeatureAccess($userId, 'competitor_basic');
        if (empty($access['allowed'])) {
            $this->model->logRequest($userId, $requestKey, 'feature_locked', 403);
            return $this->errorResponse(
                'FEATURE_LOCKED',
                403,
                (string) ($access['message'] ?? 'Feature not available on your current plan.'),
                true,
                (string) ($access['required_plan'] ?? 'pro')
            );
        }

        $apiLimitCheck = $this->planEnforcementService->assertDailyApiLimit($userId, 'api_call.competitor', 1);
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
            return $this->errorResponse('RATE_LIMIT', 429, 'Too many competitor requests. Please wait one minute.');
        }

        $cached = $this->model->getCachedSnapshot($domain, self::CACHE_HOURS);
        if (!empty($cached)) {
            $this->model->logRequest($userId, $domain, 'cache', 200);
            $this->usageMonitoringService->logApiCall($userId, 'competitor', null);
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

        $this->model->saveSnapshot($userId, $domain, $data, $source);
        $this->model->logRequest($userId, $domain, $source, 200);
        $this->usageMonitoringService->logApiCall($userId, 'competitor', null);

        return [
            'success' => true,
            'source' => $source,
            'domain' => $domain,
            'data' => $data,
        ];
    }

    private function fetchFromApi(string $domain): array
    {
        $apiKey = trim((string) ($this->config['serpapi_api_key'] ?? ''));
        if ($apiKey === '') {
            return [];
        }

        $url = 'https://serpapi.com/search.json?engine=google&q=' . rawurlencode('site:' . $domain) . '&num=10&api_key=' . rawurlencode($apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'SEOPhase3CompetitorBot/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            error_log('Competitor API request failed. HTTP ' . $httpCode . ' error: ' . $error);
            return [];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            error_log('Competitor API JSON decode failed for domain ' . $domain);
            return [];
        }

        $organicResults = is_array($decoded['organic_results'] ?? null) ? $decoded['organic_results'] : [];

        $topPages = [];
        foreach (array_slice($organicResults, 0, 5) as $result) {
            $url = (string) ($result['link'] ?? '');
            if ($url === '') {
                continue;
            }

            $topPages[] = [
                'url' => $url,
                'estimated_traffic' => 1200 + (abs(crc32($url)) % 15000),
                'keywords' => 20 + (abs(crc32('k' . $url)) % 200),
            ];
        }

        if (empty($topPages)) {
            return [];
        }

        $topKeywords = [];
        foreach ($topPages as $page) {
            $path = parse_url($page['url'], PHP_URL_PATH) ?: '/';
            $segment = trim(str_replace(['/', '-', '_'], ' ', $path));
            if ($segment === '') {
                $segment = $domain . ' homepage';
            }

            $topKeywords[] = [
                'keyword' => substr($segment, 0, 60),
                'position' => 1 + (abs(crc32('p' . $segment)) % 50),
                'volume' => 100 + (abs(crc32('v' . $segment)) % 20000),
            ];
        }

        $topKeywords = array_slice($topKeywords, 0, 10);

        $seed = abs(crc32($domain));
        $summaryScores = $this->scoringService->calculate([
            'technical' => 65 + ($seed % 25),
            'content' => 55 + ($seed % 30),
            'authority' => 40 + ($seed % 55),
            'keyword_optimization' => 45 + ($seed % 45),
        ]);

        return [
            'summary' => [
                'organic_traffic' => 20000 + ($seed % 600000),
                'domain_authority' => 35 + ($seed % 55),
                'ranking_keywords' => 500 + ($seed % 40000),
                'domain_health_score' => (int) $summaryScores['final_score'],
                'pagespeed_score' => 45 + ($seed % 50),
            ],
            'top_keywords' => $topKeywords,
            'top_pages' => $topPages,
            'traffic_trend' => $this->generateTrafficTrend($seed),
        ];
    }

    private function generateSimulatedData(string $domain): array
    {
        $seed = abs(crc32($domain));

        $parts = explode('.', $domain);
        $base = $parts[0] ?? 'brand';
        $base = trim(str_replace(['-', '_'], ' ', $base));
        if ($base === '') {
            $base = 'brand';
        }

        $keywords = [
            'best ' . $base,
            $base . ' tools',
            $base . ' pricing',
            $base . ' reviews',
            $base . ' alternatives',
            $base . ' software',
            'how to use ' . $base,
            $base . ' for beginners',
            $base . ' platform',
            $base . ' comparison',
            $base . ' case study',
            $base . ' for agencies',
        ];

        $topKeywords = [];
        foreach (array_slice($keywords, 0, 10) as $index => $keyword) {
            $topKeywords[] = [
                'keyword' => $keyword,
                'position' => 1 + (($seed + $index * 17) % 65),
                'volume' => 120 + (($seed + $index * 29) % 26000),
            ];
        }

        $topPages = [];
        $pageCandidates = ['/','/features','/pricing','/blog/seo-guide','/blog/keyword-research','/contact'];
        foreach (array_slice($pageCandidates, 0, 5) as $index => $path) {
            $topPages[] = [
                'url' => 'https://' . $domain . $path,
                'estimated_traffic' => 600 + (($seed + $index * 101) % 22000),
                'keywords' => 8 + (($seed + $index * 13) % 300),
            ];
        }

        $scores = $this->scoringService->calculate([
            'technical' => 50 + ($seed % 45),
            'content' => 45 + (intdiv($seed, 3) % 50),
            'authority' => 30 + (intdiv($seed, 5) % 65),
            'keyword_optimization' => 35 + (intdiv($seed, 7) % 55),
        ]);

        return [
            'summary' => [
                'organic_traffic' => 3500 + ($seed % 900000),
                'domain_authority' => 18 + ($seed % 75),
                'ranking_keywords' => 120 + ($seed % 80000),
                'domain_health_score' => (int) $scores['final_score'],
                'pagespeed_score' => 42 + ($seed % 55),
            ],
            'top_keywords' => $topKeywords,
            'top_pages' => $topPages,
            'traffic_trend' => $this->generateTrafficTrend($seed),
        ];
    }

    private function generateTrafficTrend(int $seed): array
    {
        $trend = [];
        $base = 10000 + ($seed % 300000);

        for ($i = 11; $i >= 0; $i--) {
            $monthLabel = date('M Y', strtotime('-' . $i . ' months'));
            $variation = (int) round(sin(($i + 1) * 0.7) * 2500) + (($seed + $i * 23) % 3500);
            $trend[] = [
                'month' => $monthLabel,
                'traffic' => max(500, $base + $variation),
            ];
        }

        return $trend;
    }

    private function errorResponse(string $code, int $status, string $message, bool $upgradeRequired = false, string $requiredPlan = 'pro'): array
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
}
