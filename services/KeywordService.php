<?php

require_once __DIR__ . '/../models/KeywordModel.php';
require_once __DIR__ . '/PlanEnforcementService.php';
require_once __DIR__ . '/UsageMonitoringService.php';

class KeywordService
{
    private const MAX_INPUT_LENGTH = 100;
    private const FREE_DAILY_LIMIT = 3;
    private const FREE_MIN_RESULTS = 10;
    private const FREE_MAX_RESULTS = 10;
    private const PRO_MIN_RESULTS = 20;
    private const PRO_TARGET_RESULTS = 25;
    private const PRO_MAX_RESULTS = 30;
    private const MAX_REQUESTS_PER_MINUTE = 15;

    private KeywordModel $keywordModel;
    private PlanEnforcementService $planEnforcementService;
    private UsageMonitoringService $usageMonitoringService;

    public function __construct(
        ?KeywordModel $keywordModel = null,
        ?PlanEnforcementService $planEnforcementService = null,
        ?UsageMonitoringService $usageMonitoringService = null
    )
    {
        $this->keywordModel = $keywordModel ?? new KeywordModel();
        $this->planEnforcementService = $planEnforcementService ?? new PlanEnforcementService();
        $this->usageMonitoringService = $usageMonitoringService ?? new UsageMonitoringService();
    }

    public function search(string $keyword, int $userId, string $planType = 'free', int $page = 1, int $perPage = 10): array
    {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        $isPaidPlan = in_array($planType, ['pro', 'agency'], true);

        $access = $this->planEnforcementService->assertFeatureAccess($userId, 'keyword_tool');
        if (empty($access['allowed'])) {
            return [
                'success' => false,
                'status' => 403,
                'error_code' => 'FEATURE_LOCKED',
                'error' => (string) ($access['message'] ?? 'Feature not available on this plan.'),
                'upgrade_required' => true,
                'required_plan' => (string) ($access['required_plan'] ?? 'pro'),
                'limits' => [
                    'plan' => $planType,
                    'daily_limit' => self::FREE_DAILY_LIMIT,
                    'daily_used' => $this->keywordModel->countDailySearches($userId),
                    'max_results' => 0,
                ],
            ];
        }

        $apiLimitCheck = $this->planEnforcementService->assertDailyApiLimit($userId, 'api_call.keyword_search', 1);
        if (empty($apiLimitCheck['allowed'])) {
            return $this->errorResponse(
                (string) ($apiLimitCheck['message'] ?? 'Daily API usage limit reached.'),
                'API_LIMIT',
                429
            );
        }

        $sanitizedKeyword = $this->sanitizeKeyword($keyword);
        if (!$this->isValidKeyword($sanitizedKeyword)) {
            return $this->errorResponse(
                'Enter a keyword between 2 and 100 characters using letters, numbers, spaces, or hyphens.',
                'VALIDATION_ERROR',
                422
            );
        }

        if ($this->keywordModel->countRecentRequests($userId, 60) >= self::MAX_REQUESTS_PER_MINUTE) {
            return $this->errorResponse(
                'Too many keyword requests. Please wait 60 seconds and try again.',
                'RATE_LIMIT',
                429
            );
        }

        $dailyUsed = $this->keywordModel->countDailySearches($userId);
        $alreadyCountedToday = $this->keywordModel->hasCountedSearchToday($userId, $sanitizedKeyword);

        if (!$isPaidPlan && !$alreadyCountedToday && $dailyUsed >= self::FREE_DAILY_LIMIT) {
            return [
                'success' => false,
                'status' => 403,
                'error_code' => 'DAILY_LIMIT_REACHED',
                'error' => 'Free plan limit reached (3 searches/day). Upgrade to Pro for unlimited keyword research.',
                'upgrade_required' => true,
                'limits' => [
                    'plan' => 'free',
                    'daily_limit' => self::FREE_DAILY_LIMIT,
                    'daily_used' => $dailyUsed,
                    'max_results' => self::FREE_MAX_RESULTS,
                ],
            ];
        }

        $maxResults = $isPaidPlan ? self::PRO_MAX_RESULTS : self::FREE_MAX_RESULTS;
        $minResults = $isPaidPlan ? self::PRO_MIN_RESULTS : self::FREE_MIN_RESULTS;
        $targetResults = $isPaidPlan ? self::PRO_TARGET_RESULTS : self::FREE_MIN_RESULTS;

        $cachedResults = $this->keywordModel->getCachedResults($sanitizedKeyword, 24);
        $usedCache = count($cachedResults) >= $minResults;

        if ($usedCache) {
            $fullResults = array_slice($cachedResults, 0, $maxResults);
            $source = 'cache';
        } else {
            $suggestions = $this->fetchExpandedSuggestions($sanitizedKeyword);
            $fullResults = $this->enrichSuggestions($sanitizedKeyword, $suggestions, $targetResults, $maxResults);
            $this->keywordModel->saveKeywordBatch($userId, $sanitizedKeyword, $fullResults);
            $source = 'api';
        }

        $perPage = max(1, min($perPage, $isPaidPlan ? 10 : 5));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $pagedResults = array_slice($fullResults, $offset, $perPage);
        $hasMore = ($offset + count($pagedResults)) < count($fullResults);

        $countedForLimit = !$isPaidPlan && !$alreadyCountedToday;
        $this->keywordModel->logSearchRequest(
            $userId,
            $sanitizedKeyword,
            $planType,
            count($pagedResults),
            $source,
            $countedForLimit
        );
        $this->usageMonitoringService->logApiCall($userId, 'keyword_search');

        return [
            'success' => true,
            'keyword' => $sanitizedKeyword,
            'results' => $pagedResults,
            'page' => $page,
            'per_page' => $perPage,
            'total_results' => count($fullResults),
            'has_more' => $hasMore,
            'next_page' => $hasMore ? $page + 1 : null,
            'cached' => $usedCache,
            'source' => $source,
            'limits' => [
                'plan' => $planType,
                'daily_limit' => $isPaidPlan ? null : self::FREE_DAILY_LIMIT,
                'daily_used' => $isPaidPlan ? null : $this->keywordModel->countDailySearches($userId),
                'max_results' => $maxResults,
            ],
        ];
    }

    // Backward-compatible method used by older flows.
    public function research(string $keyword): array
    {
        $sanitizedKeyword = $this->sanitizeKeyword($keyword);
        if (!$this->isValidKeyword($sanitizedKeyword)) {
            return [];
        }

        $suggestions = $this->fetchExpandedSuggestions($sanitizedKeyword);
        return $this->enrichSuggestions($sanitizedKeyword, $suggestions, 10, 10);
    }

    private function fetchExpandedSuggestions(string $keyword): array
    {
        $queries = [$keyword];
        foreach (range('a', 'e') as $letter) {
            $queries[] = $keyword . ' ' . $letter;
        }

        $multiHandle = curl_multi_init();
        $handles = [];

        foreach ($queries as $query) {
            $url = 'https://suggestqueries.google.com/complete/search?client=firefox&q=' . rawurlencode($query);
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'SEOKeywordResearchBot/2.0',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);

            curl_multi_add_handle($multiHandle, $handle);
            $handles[] = [
                'handle' => $handle,
                'query' => $query,
            ];
        }

        $running = null;
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($running > 0) {
                curl_multi_select($multiHandle, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $suggestions = [];
        $this->pushUniqueSuggestion($suggestions, $keyword);

        foreach ($handles as $entry) {
            /** @var resource $handle */
            $handle = $entry['handle'];
            $query = (string) $entry['query'];

            $response = curl_multi_getcontent($handle);
            $curlError = curl_error($handle);
            $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

            if ($curlError !== '' || $response === false || $httpCode !== 200) {
                error_log('Keyword autocomplete fetch failed for [' . $query . '], HTTP ' . $httpCode . ', error: ' . $curlError);
            } else {
                $decoded = json_decode($response, true);
                if (!is_array($decoded) || !isset($decoded[1]) || !is_array($decoded[1])) {
                    error_log('Keyword autocomplete JSON parsing failed for [' . $query . ']');
                } else {
                    foreach ($decoded[1] as $suggestion) {
                        if (is_string($suggestion)) {
                            $this->pushUniqueSuggestion($suggestions, $suggestion);
                        }
                    }
                }
            }

            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
        }

        curl_multi_close($multiHandle);

        return $suggestions;
    }

    private function enrichSuggestions(string $seedKeyword, array $suggestions, int $targetCount, int $maxCount): array
    {
        $cleanSuggestions = [];
        foreach ($suggestions as $suggestion) {
            $candidate = $this->sanitizeKeyword((string) $suggestion);
            if ($candidate !== '') {
                $cleanSuggestions[] = $candidate;
            }
        }

        $cleanSuggestions = $this->deduplicateKeywords($cleanSuggestions);

        if (count($cleanSuggestions) < $targetCount) {
            $fallbackSuggestions = $this->generateFallbackSuggestions($seedKeyword);
            $cleanSuggestions = $this->deduplicateKeywords(array_merge($cleanSuggestions, $fallbackSuggestions));
        }

        if (count($cleanSuggestions) < $targetCount) {
            $supplemental = $this->generateSupplementalSuggestions($seedKeyword, $targetCount);
            $cleanSuggestions = $this->deduplicateKeywords(array_merge($cleanSuggestions, $supplemental));
        }

        if (count($cleanSuggestions) < $targetCount) {
            $cleanSuggestions[] = $seedKeyword;
            $cleanSuggestions = $this->deduplicateKeywords($cleanSuggestions);
        }

        $enriched = [];
        foreach ($cleanSuggestions as $keyword) {
            $difficulty = $this->calculateDifficulty($keyword);
            $enriched[] = [
                'keyword' => $keyword,
                'volume' => $this->simulateSearchVolume($keyword),
                'difficulty' => $difficulty,
                'difficulty_label' => $this->difficultyLabel($difficulty),
                'intent' => $this->detectIntent($keyword),
            ];

            if (count($enriched) >= $maxCount) {
                break;
            }
        }

        usort($enriched, static function (array $a, array $b): int {
            return $b['volume'] <=> $a['volume'];
        });

        return array_slice($enriched, 0, $maxCount);
    }

    private function calculateDifficulty(string $keyword): int
    {
        $normalized = mb_strtolower($keyword);
        $words = preg_split('/\s+/', trim($normalized));
        $words = is_array($words) ? array_filter($words, static fn ($word) => $word !== '') : [];
        $wordCount = max(1, count($words));
        $length = mb_strlen($normalized);

        $difficulty = 70;

        if ($wordCount === 1) {
            $difficulty += 20;
        } else {
            $difficulty -= min(30, ($wordCount - 1) * 6);
        }

        if ($length > 20) {
            $difficulty -= min(18, (int) floor(($length - 20) / 2));
        }

        $commercialWords = ['buy', 'best', 'top', 'review', 'compare', 'price', 'pricing', 'discount', 'order'];
        foreach ($commercialWords as $term) {
            if (str_contains($normalized, $term)) {
                $difficulty += 8;
            }
        }

        if (str_contains($normalized, 'near me')) {
            $difficulty += 5;
        }

        return max(0, min(100, $difficulty));
    }

    private function difficultyLabel(int $score): string
    {
        if ($score <= 30) {
            return 'Easy';
        }

        if ($score <= 60) {
            return 'Medium';
        }

        if ($score <= 80) {
            return 'Hard';
        }

        return 'Very Hard';
    }

    private function detectIntent(string $keyword): string
    {
        $normalized = mb_strtolower($keyword);

        $navigationalBrands = [
            'google', 'youtube', 'facebook', 'instagram', 'amazon',
            'semrush', 'ahrefs', 'notion', 'shopify', 'wordpress'
        ];

        foreach ($navigationalBrands as $brand) {
            if (str_contains($normalized, $brand)) {
                return 'Navigational';
            }
        }

        $transactionalPatterns = ['buy', 'price', 'discount', 'order', 'coupon', 'deal'];
        foreach ($transactionalPatterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return 'Transactional';
            }
        }

        $commercialPatterns = ['best', 'top', 'review', 'compare', 'vs', 'alternative'];
        foreach ($commercialPatterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return 'Commercial';
            }
        }

        $informationalPatterns = ['how', 'what', 'guide', 'tutorial', 'tips', 'learn'];
        foreach ($informationalPatterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return 'Informational';
            }
        }

        return 'Informational';
    }

    private function simulateSearchVolume(string $keyword): int
    {
        $normalized = mb_strtolower($keyword);
        $hash = abs(crc32($normalized));

        $words = preg_split('/\s+/', trim($normalized));
        $words = is_array($words) ? array_filter($words, static fn ($word) => $word !== '') : [];
        $wordCount = max(1, count($words));

        $volume = 600 + ($hash % 30000);

        if ($wordCount >= 5) {
            $volume = (int) round($volume * 0.45);
        } elseif ($wordCount >= 3) {
            $volume = (int) round($volume * 0.7);
        } elseif ($wordCount === 1) {
            $volume = (int) round($volume * 1.35);
        }

        if (str_contains($normalized, 'near me')) {
            $volume = (int) round($volume * 0.8);
        }

        return max(50, min(250000, $volume));
    }

    private function generateFallbackSuggestions(string $keyword): array
    {
        $year = (int) date('Y');
        $normalized = mb_strtolower($keyword);

        $templates = [
            '%s online',
            '%s for beginners',
            '%s tutorial',
            '%s guide',
            '%s pricing',
            !str_contains($normalized, 'best ') ? 'best %s' : null,
            !str_contains($normalized, 'top ') ? 'top %s' : null,
            '%s review',
            '%s checklist',
            '%s examples',
            '%s strategy',
            '%s tools',
            '%s for small business',
            '%s in ' . $year,
            !str_contains($normalized, 'how to ') ? 'how to ' . $keyword : null,
        ];

        $fallback = [];
        foreach ($templates as $template) {
            if (!is_string($template) || trim($template) === '') {
                continue;
            }

            if (str_contains($template, '%s')) {
                $candidate = sprintf($template, $keyword);
            } else {
                $candidate = $template;
            }

            // Collapse repeated adjacent tokens (example: "best best keyword").
            $candidate = preg_replace('/\\b([a-zA-Z0-9\\-]+)\\s+\\1\\b/i', '$1', $candidate);
            $fallback[] = trim((string) $candidate);
        }

        return $fallback;
    }

    private function generateSupplementalSuggestions(string $keyword, int $targetCount): array
    {
        $prefixes = ['best', 'top', 'free', 'advanced', 'affordable', 'local', 'online', 'expert'];
        $suffixes = [
            'tools',
            'software',
            'services',
            'course',
            'strategy',
            'tips',
            'examples',
            'checklist',
            'for beginners',
            'for startups',
            'for small business',
            'near me',
            'in ' . date('Y'),
        ];

        $candidates = [];
        foreach ($suffixes as $suffix) {
            $candidates[] = $keyword . ' ' . $suffix;
        }

        foreach ($prefixes as $prefix) {
            $candidates[] = $prefix . ' ' . $keyword;
        }

        foreach ($prefixes as $prefix) {
            foreach ($suffixes as $suffix) {
                $candidates[] = $prefix . ' ' . $keyword . ' ' . $suffix;
                if (count($candidates) >= ($targetCount * 3)) {
                    break 2;
                }
            }
        }

        return array_slice($this->deduplicateKeywords($candidates), 0, $targetCount * 2);
    }

    private function pushUniqueSuggestion(array &$suggestions, string $keyword): void
    {
        $candidate = $this->sanitizeKeyword($keyword);
        if ($candidate === '') {
            return;
        }

        $key = mb_strtolower($candidate);
        foreach ($suggestions as $existing) {
            if (mb_strtolower((string) $existing) === $key) {
                return;
            }
        }

        $suggestions[] = $candidate;
    }

    private function deduplicateKeywords(array $keywords): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($keywords as $keyword) {
            $candidate = trim((string) $keyword);
            if ($candidate === '') {
                continue;
            }

            $key = mb_strtolower($candidate);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduplicated[] = $candidate;
        }

        return $deduplicated;
    }

    private function sanitizeKeyword(string $keyword): string
    {
        $sanitized = trim($keyword);
        $sanitized = preg_replace('/[^a-zA-Z0-9\s\-]/', ' ', $sanitized);
        $sanitized = preg_replace('/\s+/', ' ', (string) $sanitized);

        return trim((string) $sanitized);
    }

    private function isValidKeyword(string $keyword): bool
    {
        $length = mb_strlen($keyword);
        return $length >= 2 && $length <= self::MAX_INPUT_LENGTH;
    }

    private function errorResponse(string $message, string $code, int $status): array
    {
        return [
            'success' => false,
            'status' => $status,
            'error_code' => $code,
            'error' => $message,
        ];
    }
}
