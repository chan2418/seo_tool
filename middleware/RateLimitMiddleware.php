<?php

class RateLimitMiddleware
{
    private const STORAGE_FILE = __DIR__ . '/../storage/rate_limits.json';

    public static function enforce(
        string $bucket,
        int $maxRequests,
        int $windowSeconds = 60,
        ?int $userId = null,
        bool $jsonResponse = true
    ): void {
        $bucket = trim($bucket);
        if ($bucket === '') {
            $bucket = 'default';
        }

        $maxRequests = max(1, $maxRequests);
        $windowSeconds = max(1, $windowSeconds);

        $clientKey = self::resolveClientKey($bucket, $userId);
        $now = time();
        $cutoff = $now - $windowSeconds;

        $rows = self::readRows();
        $hits = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['key'] ?? '') !== $clientKey) {
                continue;
            }
            $ts = (int) ($row['ts'] ?? 0);
            if ($ts >= $cutoff) {
                $hits[] = $row;
            }
        }

        if (count($hits) >= $maxRequests) {
            if ($jsonResponse) {
                http_response_code(429);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error_code' => 'RATE_LIMIT',
                    'error' => 'Too many requests. Please try again shortly.',
                ]);
                exit;
            }

            http_response_code(429);
            echo 'Too many requests.';
            exit;
        }

        $rows[] = [
            'key' => $clientKey,
            'ts' => $now,
        ];

        if (count($rows) > 30000) {
            $rows = array_slice($rows, -30000);
        }
        self::writeRows($rows);
    }

    private static function resolveClientKey(string $bucket, ?int $userId): string
    {
        if ($userId !== null && $userId > 0) {
            return $bucket . '|u:' . $userId;
        }

        $ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return $bucket . '|ip:' . $ip;
    }

    private static function readRows(): array
    {
        $path = self::STORAGE_FILE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        if (!file_exists($path)) {
            file_put_contents($path, json_encode([]));
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function writeRows(array $rows): void
    {
        file_put_contents(self::STORAGE_FILE, json_encode($rows, JSON_PRETTY_PRINT), LOCK_EX);
    }
}

