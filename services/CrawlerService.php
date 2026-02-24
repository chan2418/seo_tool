<?php

require_once __DIR__ . '/../models/CrawlModel.php';
require_once __DIR__ . '/PlanEnforcementService.php';
require_once __DIR__ . '/../utils/SecurityValidator.php';
require_once __DIR__ . '/AdvancedScoringService.php';
require_once __DIR__ . '/AlertDetectionService.php';
require_once __DIR__ . '/../models/AlertModel.php';
require_once __DIR__ . '/UsageMonitoringService.php';

class CrawlerService
{
    private const MAX_PAGES = 10;
    private const MAX_LINK_CHECKS_PER_PAGE = 10;
    private const FETCH_TIMEOUT = 8;
    private const LINK_TIMEOUT = 4;
    private const RATE_LIMIT_PER_MINUTE = 4;

    private CrawlModel $model;
    private AdvancedScoringService $scoringService;
    private PlanEnforcementService $planEnforcementService;
    private UsageMonitoringService $usageMonitoringService;

    public function __construct(
        ?CrawlModel $model = null,
        ?AdvancedScoringService $scoringService = null,
        ?PlanEnforcementService $planEnforcementService = null,
        ?UsageMonitoringService $usageMonitoringService = null
    )
    {
        $this->model = $model ?? new CrawlModel();
        $this->scoringService = $scoringService ?? new AdvancedScoringService();
        $this->planEnforcementService = $planEnforcementService ?? new PlanEnforcementService();
        $this->usageMonitoringService = $usageMonitoringService ?? new UsageMonitoringService();
    }

    public function start(string $urlInput, int $userId, string $planType): array
    {
        $requestKey = trim($urlInput);
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);

        $access = $this->planEnforcementService->assertFeatureAccess($userId, 'multi_page_crawler');
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

        $apiLimitCheck = $this->planEnforcementService->assertDailyApiLimit($userId, 'api_call.crawler_start', 1);
        if (empty($apiLimitCheck['allowed'])) {
            $this->model->logRequest($userId, $requestKey, 'api_limit', 429);
            return $this->errorResponse('API_LIMIT', 429, (string) ($apiLimitCheck['message'] ?? 'Daily API usage limit reached.'));
        }

        if ($this->model->countRecentRequests($userId, 60) >= self::RATE_LIMIT_PER_MINUTE) {
            $this->model->logRequest($userId, $requestKey, 'rate_limited', 429);
            return $this->errorResponse('RATE_LIMIT', 429, 'Too many crawl requests. Please wait one minute.');
        }

        $normalizedUrl = SecurityValidator::normalizeUrl($urlInput);
        if ($normalizedUrl === null || !SecurityValidator::isSafeUrlForCrawl($normalizedUrl)) {
            $this->model->logRequest($userId, $requestKey, 'invalid_url', 422);
            return $this->errorResponse('INVALID_URL', 422, 'Provide a valid public URL (private and local hosts are blocked).');
        }

        $host = (string) (parse_url($normalizedUrl, PHP_URL_HOST) ?? '');
        if ($host === '') {
            $this->model->logRequest($userId, $requestKey, 'invalid_url', 422);
            return $this->errorResponse('INVALID_URL', 422, 'Unable to resolve host for crawling.');
        }

        $queue = $this->discoverUrls($normalizedUrl, $host, self::MAX_PAGES);
        if (empty($queue)) {
            $queue = [$normalizedUrl];
        }

        try {
            $runId = $this->model->createRun($userId, $normalizedUrl, $host, $queue);
        } catch (Throwable $error) {
            error_log('Crawler start failed: ' . $error->getMessage());
            $this->model->logRequest($userId, $host, 'start_failed', 500);
            return $this->errorResponse(
                'CRAWL_INIT_FAILED',
                500,
                'Unable to start crawl run. Run Phase 3 DB setup and try again.'
            );
        }

        if ($runId <= 0) {
            $this->model->logRequest($userId, $host, 'start_failed', 500);
            return $this->errorResponse(
                'CRAWL_INIT_FAILED',
                500,
                'Unable to start crawl run. Run Phase 3 DB setup and try again.'
            );
        }

        $this->model->logRequest($userId, $host, 'start', 200);
        $this->usageMonitoringService->logApiCall($userId, 'crawler_start');

        return [
            'success' => true,
            'run_id' => $runId,
            'status' => 'running',
            'progress' => 0,
            'total_pages' => count($queue),
        ];
    }

    public function status(int $runId, int $userId, bool $processStep = true): array
    {
        $run = $this->model->getRun($runId, $userId);
        if (!$run) {
            return $this->errorResponse('NOT_FOUND', 404, 'Crawl run not found.');
        }

        if ($processStep && $run['status'] === 'running') {
            $this->processNextStep($run, $userId);
            $run = $this->model->getRun($runId, $userId) ?? $run;
        }

        $pages = $this->model->getPageResults($runId);
        $summary = is_array($run['summary_json'] ?? null) ? $run['summary_json'] : [];
        $issues = is_array($summary['issues'] ?? null) ? $summary['issues'] : [];

        return [
            'success' => true,
            'run' => [
                'id' => $run['id'],
                'status' => $run['status'],
                'progress' => $run['progress'],
                'total_pages' => $run['total_pages'],
                'pages_completed' => $run['pages_completed'],
                'technical_score' => $run['technical_score'],
                'content_score' => $run['content_score'],
                'authority_score' => $run['authority_score'],
                'keyword_score' => $run['keyword_score'],
                'final_score' => $run['final_score'],
                'issues' => [
                    'duplicate_titles' => (int) ($issues['duplicate_titles'] ?? 0),
                    'missing_h1' => (int) ($issues['missing_h1'] ?? 0),
                    'missing_meta_description' => (int) ($issues['missing_meta_description'] ?? 0),
                    'broken_links' => (int) ($issues['broken_links'] ?? 0),
                    'thin_content' => (int) ($issues['thin_content'] ?? 0),
                ],
                'error_message' => $run['error_message'],
            ],
            'pages' => array_map(static function (array $page): array {
                return [
                    'url' => (string) ($page['url'] ?? ''),
                    'title' => (string) ($page['title'] ?? ''),
                    'word_count' => (int) ($page['word_count'] ?? 0),
                    'broken_links' => (int) ($page['broken_links'] ?? 0),
                    'issues' => is_array($page['issues'] ?? null) ? $page['issues'] : [],
                ];
            }, $pages),
        ];
    }

    private function processNextStep(array $run, int $userId): void
    {
        $queue = is_array($run['queue_json']) ? $run['queue_json'] : [];
        $summary = is_array($run['summary_json'] ?? null) ? $run['summary_json'] : [];
        $summaryIssues = is_array($summary['issues'] ?? null) ? $summary['issues'] : [];
        $titleRegistry = is_array($summary['title_registry'] ?? null) ? $summary['title_registry'] : [];

        if (empty($queue)) {
            $this->finalizeRun($run, $userId, $summary);
            return;
        }

        $nextUrl = (string) array_shift($queue);
        $pageResult = $this->analyzePage($nextUrl, (string) $run['domain']);

        $titleKey = strtolower(trim((string) ($pageResult['title'] ?? '')));
        if ($titleKey !== '') {
            $titleRegistry[$titleKey] = (int) ($titleRegistry[$titleKey] ?? 0) + 1;
        }

        if (!empty($pageResult['has_missing_h1'])) {
            $summaryIssues['missing_h1'] = (int) ($summaryIssues['missing_h1'] ?? 0) + 1;
        }

        if (!empty($pageResult['has_missing_meta'])) {
            $summaryIssues['missing_meta_description'] = (int) ($summaryIssues['missing_meta_description'] ?? 0) + 1;
        }

        if (!empty($pageResult['is_thin_content'])) {
            $summaryIssues['thin_content'] = (int) ($summaryIssues['thin_content'] ?? 0) + 1;
        }

        $summaryIssues['broken_links'] = (int) ($summaryIssues['broken_links'] ?? 0) + (int) ($pageResult['broken_links'] ?? 0);

        $this->model->addPageResult((int) $run['id'], $pageResult);

        $pagesCompleted = (int) $run['pages_completed'] + 1;
        $totalPages = max(1, (int) $run['total_pages']);
        $progress = (int) min(100, round(($pagesCompleted / $totalPages) * 100));

        $summary['issues'] = $summaryIssues;
        $summary['title_registry'] = $titleRegistry;

        $this->model->updateRun((int) $run['id'], $userId, [
            'queue_json' => $queue,
            'summary_json' => $summary,
            'pages_completed' => $pagesCompleted,
            'progress' => $progress,
        ]);

        if ($pagesCompleted >= $totalPages || empty($queue)) {
            $updatedRun = $this->model->getRun((int) $run['id'], $userId);
            if ($updatedRun) {
                $this->finalizeRun($updatedRun, $userId, $summary);
            }
        }
    }

    private function finalizeRun(array $run, int $userId, array $summary): void
    {
        $pages = $this->model->getPageResults((int) $run['id']);

        if (empty($pages)) {
            $this->model->updateRun((int) $run['id'], $userId, [
                'status' => 'failed',
                'progress' => 100,
                'error_message' => 'No pages could be crawled.',
            ]);
            return;
        }

        $titleCounts = [];
        foreach ($pages as $page) {
            $title = strtolower(trim((string) ($page['title'] ?? '')));
            if ($title === '') {
                continue;
            }
            $titleCounts[$title] = (int) ($titleCounts[$title] ?? 0) + 1;
        }

        $duplicatePages = 0;
        foreach ($titleCounts as $count) {
            if ($count > 1) {
                $duplicatePages += $count;
            }
        }

        $issues = is_array($summary['issues'] ?? null) ? $summary['issues'] : [];
        $issues['duplicate_titles'] = $duplicatePages;
        $summary['issues'] = $issues;
        unset($summary['title_registry']);

        $scores = $this->scoringService->calculateFromCrawlerSummary($summary, count($pages));

        $this->model->updateRun((int) $run['id'], $userId, [
            'status' => 'completed',
            'progress' => 100,
            'technical_score' => (int) $scores['technical'],
            'content_score' => (int) $scores['content'],
            'authority_score' => (int) $scores['authority'],
            'keyword_score' => (int) $scores['keyword_optimization'],
            'final_score' => (int) $scores['final_score'],
            'summary_json' => $summary,
            'queue_json' => [],
            'error_message' => '',
        ]);

        $this->triggerAlertMonitoring($userId);
    }

    private function discoverUrls(string $startUrl, string $host, int $limit): array
    {
        $urls = [$this->stripFragment($startUrl)];
        $html = $this->fetchHtml($startUrl);
        if ($html === null) {
            return $urls;
        }

        $links = $this->extractLinks($html, $startUrl);
        foreach ($links as $link) {
            $normalized = $this->normalizeDiscoveredUrl($link, $startUrl);
            if ($normalized === null) {
                continue;
            }

            $linkHost = strtolower((string) (parse_url($normalized, PHP_URL_HOST) ?? ''));
            if ($linkHost !== strtolower($host)) {
                continue;
            }

            if (!in_array($normalized, $urls, true)) {
                $urls[] = $normalized;
            }

            if (count($urls) >= $limit) {
                break;
            }
        }

        return array_slice($urls, 0, $limit);
    }

    private function analyzePage(string $url, string $domain): array
    {
        $html = $this->fetchHtml($url);
        if ($html === null) {
            return [
                'url' => $url,
                'title' => '',
                'meta_description' => '',
                'h1_count' => 0,
                'word_count' => 0,
                'broken_links' => 0,
                'is_thin_content' => true,
                'has_missing_meta' => true,
                'has_missing_h1' => true,
                'content_hash' => sha1($url . '-fetch-error'),
                'issues' => ['fetch_error'],
            ];
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();

        $titleNodes = $dom->getElementsByTagName('title');
        $title = $titleNodes->length > 0 ? trim((string) $titleNodes->item(0)->textContent) : '';

        $metaDescription = '';
        $metaNodes = $dom->getElementsByTagName('meta');
        foreach ($metaNodes as $meta) {
            if (strtolower((string) $meta->getAttribute('name')) === 'description') {
                $metaDescription = trim((string) $meta->getAttribute('content'));
                break;
            }
        }

        $h1Count = $dom->getElementsByTagName('h1')->length;

        $bodyText = '';
        $bodyNodes = $dom->getElementsByTagName('body');
        if ($bodyNodes->length > 0) {
            $bodyText = trim((string) $bodyNodes->item(0)->textContent);
        }

        $wordCount = str_word_count(preg_replace('/\s+/', ' ', $bodyText));
        $isThinContent = $wordCount < 300;
        $hasMissingMeta = $metaDescription === '';
        $hasMissingH1 = $h1Count === 0;

        $links = $this->extractLinks($html, $url);
        $brokenLinks = $this->countBrokenLinks($links, $domain);

        $issues = [];
        if ($hasMissingMeta) {
            $issues[] = 'missing_meta_description';
        }
        if ($hasMissingH1) {
            $issues[] = 'missing_h1';
        }
        if ($isThinContent) {
            $issues[] = 'thin_content';
        }
        if ($brokenLinks > 0) {
            $issues[] = 'broken_links';
        }

        return [
            'url' => $url,
            'title' => $title,
            'meta_description' => $metaDescription,
            'h1_count' => $h1Count,
            'word_count' => $wordCount,
            'broken_links' => $brokenLinks,
            'is_thin_content' => $isThinContent,
            'has_missing_meta' => $hasMissingMeta,
            'has_missing_h1' => $hasMissingH1,
            'content_hash' => sha1($title . '|' . $metaDescription . '|' . $wordCount),
            'issues' => $issues,
        ];
    }

    private function triggerAlertMonitoring(int $userId): void
    {
        try {
            $alertModel = new AlertModel();
            $profile = $alertModel->getUserEmailAndPlan($userId);
            $planType = (string) ($profile['plan_type'] ?? 'free');

            $detector = new AlertDetectionService();
            $detector->runForUser($userId, $planType);
        } catch (Throwable $error) {
            error_log('Crawler alert trigger failed: ' . $error->getMessage());
        }
    }

    private function countBrokenLinks(array $links, string $domain): int
    {
        $broken = 0;
        $checked = 0;

        foreach ($links as $link) {
            if ($checked >= self::MAX_LINK_CHECKS_PER_PAGE) {
                break;
            }

            $normalized = $this->normalizeDiscoveredUrl($link, 'https://' . $domain);
            if ($normalized === null) {
                continue;
            }

            $host = strtolower((string) (parse_url($normalized, PHP_URL_HOST) ?? ''));
            if ($host !== strtolower($domain)) {
                continue;
            }

            if (!SecurityValidator::isSafeHost($host)) {
                continue;
            }

            $checked++;
            $statusCode = $this->checkHttpStatus($normalized);
            if ($statusCode >= 400 || $statusCode === 0) {
                $broken++;
            }
        }

        return $broken;
    }

    private function checkHttpStatus(string $url): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::LINK_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'SEOPhase3Crawler/1.0',
        ]);

        curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            return 0;
        }

        return $statusCode;
    }

    private function fetchHtml(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::FETCH_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'SEOPhase3Crawler/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400 || $error !== '') {
            if ($error !== '') {
                error_log('Crawler fetch failed for ' . $url . ': ' . $error);
            }
            return null;
        }

        return is_string($response) ? $response : null;
    }

    private function extractLinks(string $html, string $baseUrl): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();

        $links = [];
        $anchorNodes = $dom->getElementsByTagName('a');

        foreach ($anchorNodes as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }

            $links[] = $href;
        }

        return $links;
    }

    private function normalizeDiscoveredUrl(string $candidate, string $baseUrl): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            $baseScheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            $candidate = $baseScheme . ':' . $candidate;
        }

        if (!preg_match('~^https?://~i', $candidate)) {
            $baseParts = parse_url($baseUrl);
            if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
                return null;
            }

            $basePath = $baseParts['path'] ?? '/';
            if ($candidate[0] === '/') {
                $candidate = $baseParts['scheme'] . '://' . $baseParts['host'] . $candidate;
            } else {
                $dir = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
                $dir = $dir === '' || $dir === '.' ? '' : $dir;
                $candidate = $baseParts['scheme'] . '://' . $baseParts['host'] . '/' . ($dir !== '' ? $dir . '/' : '') . $candidate;
            }
        }

        $normalized = SecurityValidator::normalizeUrl($candidate);
        if ($normalized === null) {
            return null;
        }

        return $this->stripFragment($normalized);
    }

    private function stripFragment(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        $clean = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $clean .= ':' . (int) $parts['port'];
        }

        $path = $parts['path'] ?? '/';
        $clean .= $path === '' ? '/' : $path;

        if (!empty($parts['query'])) {
            $clean .= '?' . $parts['query'];
        }

        return $clean;
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
}
