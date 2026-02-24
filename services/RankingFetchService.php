<?php

require_once __DIR__ . '/../utils/SecurityValidator.php';

class RankingFetchService
{
    private const HTTP_TIMEOUT = 10;
    private const CONNECT_TIMEOUT = 5;
    private const MAX_ORGANIC_RESULTS = 100;
    private const DEFAULT_DAILY_API_LIMIT = 2000;

    private array $config;
    private string $cacheFile;
    private string $logFile;
    private int $dailyApiLimit;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/config.php';

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $this->cacheFile = $storageDir . '/rank_api_cache.json';
        $this->logFile = $storageDir . '/rank_fetch_logs.json';
        if (!file_exists($this->cacheFile)) {
            file_put_contents($this->cacheFile, json_encode([]));
        }
        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, json_encode([]));
        }

        $configuredLimit = (int) (getenv('RANK_DAILY_API_LIMIT') ?: 0);
        $this->dailyApiLimit = $configuredLimit > 0 ? $configuredLimit : self::DEFAULT_DAILY_API_LIMIT;
    }

    public function fetchKeywordRank(
        string $keyword,
        string $projectDomain,
        string $country,
        string $deviceType,
        int $userId,
        string $checkedDate
    ): array {
        $keyword = trim((string) preg_replace('/\s+/', ' ', $keyword));
        $projectDomain = SecurityValidator::sanitizeDomain($projectDomain);
        $country = strtoupper(trim($country));
        $deviceType = strtolower(trim($deviceType));
        $checkedDate = $this->normalizeDate($checkedDate);

        if ($keyword === '' || !SecurityValidator::isValidDomain($projectDomain)) {
            return [
                'success' => false,
                'rank_position' => 101,
                'source' => 'invalid_input',
                'checked_results' => 0,
                'error' => 'Invalid keyword or project domain.',
            ];
        }

        $cacheKey = $this->cacheKey($keyword, $projectDomain, $country, $deviceType, $checkedDate);
        $cached = $this->getCacheEntry($cacheKey);
        if (is_array($cached)) {
            $this->logFetch($userId, $cacheKey, 'cache', 200, $checkedDate);
            return $cached;
        }

        $serpApiKey = trim((string) (getenv('SERPAPI_API_KEY') ?: ($this->config['serpapi_api_key'] ?? '')));

        if ($serpApiKey !== '' && $this->countDailyApiCalls($checkedDate) < $this->dailyApiLimit) {
            $apiResponse = $this->fetchFromSerpApi($keyword, $projectDomain, $country, $deviceType, $serpApiKey);
            if (!empty($apiResponse['success'])) {
                $this->setCacheEntry($cacheKey, $apiResponse);
                $this->logFetch($userId, $cacheKey, 'api', 200, $checkedDate);
                return $apiResponse;
            }

            $this->logFetch($userId, $cacheKey, 'api_error', 502, $checkedDate);
        }

        $simulated = $this->simulateRank($keyword, $projectDomain, $checkedDate);
        $this->setCacheEntry($cacheKey, $simulated);
        $this->logFetch($userId, $cacheKey, 'simulated', 200, $checkedDate);

        return $simulated;
    }

    private function fetchFromSerpApi(
        string $keyword,
        string $projectDomain,
        string $country,
        string $deviceType,
        string $apiKey
    ): array {
        $countryCode = $this->normalizeCountryCode($country);
        $device = in_array($deviceType, ['desktop', 'mobile'], true) ? $deviceType : 'desktop';

        $url = 'https://serpapi.com/search.json?engine=google'
            . '&q=' . rawurlencode($keyword)
            . '&num=' . self::MAX_ORGANIC_RESULTS
            . '&gl=' . rawurlencode(strtolower($countryCode))
            . '&hl=en'
            . '&device=' . rawurlencode($device)
            . '&api_key=' . rawurlencode($apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'SEORankTrackerBot/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            error_log('Rank fetch (SerpAPI) failed. HTTP ' . $httpCode . ' error: ' . $error);
            return [
                'success' => false,
                'rank_position' => 101,
                'source' => 'api',
                'checked_results' => 0,
                'error' => 'SerpAPI request failed',
            ];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            error_log('Rank fetch (SerpAPI) invalid JSON for keyword ' . $keyword);
            return [
                'success' => false,
                'rank_position' => 101,
                'source' => 'api',
                'checked_results' => 0,
                'error' => 'SerpAPI invalid JSON',
            ];
        }

        $organicResults = is_array($decoded['organic_results'] ?? null) ? $decoded['organic_results'] : [];
        $checked = min(self::MAX_ORGANIC_RESULTS, count($organicResults));

        $position = 101;
        $foundUrl = '';
        $counter = 0;

        foreach ($organicResults as $result) {
            if ($counter >= self::MAX_ORGANIC_RESULTS) {
                break;
            }
            $counter++;
            $link = (string) ($result['link'] ?? $result['url'] ?? '');
            if ($link === '') {
                continue;
            }

            $positionCandidate = (int) ($result['position'] ?? $counter);
            if ($this->matchesDomain($link, $projectDomain)) {
                $position = max(1, min(999, $positionCandidate));
                $foundUrl = $link;
                break;
            }
        }

        return [
            'success' => true,
            'rank_position' => $position,
            'source' => 'api',
            'checked_results' => $checked,
            'found' => $position <= 100,
            'found_url' => $foundUrl,
        ];
    }

    private function simulateRank(string $keyword, string $projectDomain, string $checkedDate): array
    {
        $seed = abs(crc32(strtolower($projectDomain . '|' . $keyword)));
        $dayIndex = (int) date('z', strtotime($checkedDate));
        $wave = (int) round(sin(($dayIndex + ($seed % 31)) / 4.6) * 10);
        $baseline = 15 + ($seed % 95);
        $position = max(1, min(130, $baseline - $wave));

        if ((($seed + $dayIndex) % 14) === 0) {
            $position = 101 + (($seed + $dayIndex) % 25);
        }

        return [
            'success' => true,
            'rank_position' => $position > 100 ? 101 : $position,
            'source' => 'simulated',
            'checked_results' => 100,
            'found' => $position <= 100,
            'found_url' => $position <= 100 ? 'https://' . $projectDomain . '/' : '',
        ];
    }

    private function matchesDomain(string $url, string $projectDomain): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = SecurityValidator::sanitizeDomain($host);

        if ($host === '') {
            return false;
        }

        $projectDomain = strtolower($projectDomain);
        if ($host === $projectDomain) {
            return true;
        }

        return str_ends_with($host, '.' . $projectDomain);
    }

    private function normalizeCountryCode(string $country): string
    {
        $country = strtoupper(trim($country));
        $allowed = ['US', 'IN', 'GB', 'CA', 'AU', 'SG', 'AE', 'DE', 'FR'];
        if (!in_array($country, $allowed, true)) {
            return 'US';
        }

        return $country;
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return date('Y-m-d');
        }

        return date('Y-m-d', $timestamp);
    }

    private function cacheKey(string $keyword, string $domain, string $country, string $device, string $date): string
    {
        return sha1(strtolower($keyword) . '|' . strtolower($domain) . '|' . strtoupper($country) . '|' . strtolower($device) . '|' . $date);
    }

    private function getCacheEntry(string $cacheKey): ?array
    {
        $cache = $this->readJsonFile($this->cacheFile);
        if (!isset($cache[$cacheKey]) || !is_array($cache[$cacheKey])) {
            return null;
        }

        return $cache[$cacheKey];
    }

    private function setCacheEntry(string $cacheKey, array $value): void
    {
        $cache = $this->readJsonFile($this->cacheFile);
        $cache[$cacheKey] = $value;

        if (count($cache) > 12000) {
            $cache = array_slice($cache, -12000, null, true);
        }

        $this->writeJsonFile($this->cacheFile, $cache);
    }

    private function countDailyApiCalls(string $date): int
    {
        $datePrefix = $this->normalizeDate($date);
        $logs = $this->readJsonFile($this->logFile);
        $count = 0;
        foreach ($logs as $log) {
            if (($log['source'] ?? '') !== 'api') {
                continue;
            }
            if (strpos((string) ($log['created_at'] ?? ''), $datePrefix) === 0) {
                $count++;
            }
        }

        return $count;
    }

    private function logFetch(int $userId, string $requestKey, string $source, int $statusCode, string $date): void
    {
        $logs = $this->readJsonFile($this->logFile);
        $logs[] = [
            'user_id' => $userId,
            'request_key' => $requestKey,
            'source' => $source,
            'status_code' => $statusCode,
            'created_at' => $this->normalizeDate($date) . ' ' . date('H:i:s'),
        ];

        if (count($logs) > 18000) {
            $logs = array_slice($logs, -18000);
        }
        $this->writeJsonFile($this->logFile, $logs);
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

    private function writeJsonFile(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
