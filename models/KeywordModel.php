<?php

require_once __DIR__ . '/../config/database.php';

class KeywordModel
{
    private ?PDO $conn;
    private bool $useFileStorage = false;
    private string $cacheFile;
    private string $logFile;

    public function __construct()
    {
        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $this->cacheFile = $storageDir . '/keyword_cache.json';
        $this->logFile = $storageDir . '/keyword_search_logs.json';

        if (!file_exists($this->cacheFile)) {
            file_put_contents($this->cacheFile, json_encode([]));
        }

        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function getCachedResults(string $seedKeyword, int $maxAgeHours = 24): array
    {
        $normalizedSeed = mb_strtolower(trim($seedKeyword));

        if ($this->useFileStorage) {
            $cache = $this->readJsonFile($this->cacheFile);
            if (!isset($cache[$normalizedSeed])) {
                return [];
            }

            $entry = $cache[$normalizedSeed];
            $createdAt = strtotime((string) ($entry['created_at'] ?? ''));
            if ($createdAt === false || $createdAt < (time() - ($maxAgeHours * 3600))) {
                return [];
            }

            $results = $entry['results'] ?? [];
            return is_array($results) ? $results : [];
        }

        try {
            $cutoff = date('Y-m-d H:i:s', time() - ($maxAgeHours * 3600));

            $stmt = $this->conn->prepare(
                'SELECT keyword, search_volume, difficulty_score, difficulty_label, intent, created_at, position
                 FROM keyword_results
                 WHERE seed_keyword = :seed_keyword
                   AND created_at >= :cutoff
                 ORDER BY created_at DESC, position ASC
                 LIMIT 400'
            );

            $stmt->execute([
                ':seed_keyword' => $normalizedSeed,
                ':cutoff' => $cutoff,
            ]);

            $rows = $stmt->fetchAll();
            if (!$rows) {
                return [];
            }

            $results = [];
            $seen = [];

            foreach ($rows as $row) {
                $keyword = trim((string) ($row['keyword'] ?? ''));
                if ($keyword === '') {
                    continue;
                }

                $key = mb_strtolower($keyword);
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $results[] = [
                    'keyword' => $keyword,
                    'volume' => (int) ($row['search_volume'] ?? 0),
                    'difficulty' => (int) ($row['difficulty_score'] ?? 0),
                    'difficulty_label' => (string) ($row['difficulty_label'] ?? 'Medium'),
                    'intent' => (string) ($row['intent'] ?? 'Informational'),
                ];
            }

            return $results;
        } catch (Throwable $error) {
            error_log('Keyword cache fetch failed: ' . $error->getMessage());
            return [];
        }
    }

    public function saveKeywordBatch(int $userId, string $seedKeyword, array $results): void
    {
        $normalizedSeed = mb_strtolower(trim($seedKeyword));
        if ($normalizedSeed === '' || empty($results)) {
            return;
        }

        if ($this->useFileStorage) {
            $cache = $this->readJsonFile($this->cacheFile);
            $cache[$normalizedSeed] = [
                'created_at' => date('Y-m-d H:i:s'),
                'user_id' => $userId,
                'results' => array_values($results),
            ];
            $this->writeJsonFile($this->cacheFile, $cache);
            return;
        }

        try {
            $this->conn->beginTransaction();

            $insertStmt = $this->conn->prepare(
                'INSERT INTO keyword_results
                    (user_id, seed_keyword, keyword, search_volume, difficulty_score, difficulty_label, intent, position, created_at)
                 VALUES
                    (:user_id, :seed_keyword, :keyword, :search_volume, :difficulty_score, :difficulty_label, :intent, :position, :created_at)'
            );

            $createdAt = date('Y-m-d H:i:s');

            foreach (array_values($results) as $index => $row) {
                $insertStmt->execute([
                    ':user_id' => $userId,
                    ':seed_keyword' => $normalizedSeed,
                    ':keyword' => (string) ($row['keyword'] ?? ''),
                    ':search_volume' => (int) ($row['volume'] ?? 0),
                    ':difficulty_score' => (int) ($row['difficulty'] ?? 0),
                    ':difficulty_label' => (string) ($row['difficulty_label'] ?? 'Medium'),
                    ':intent' => (string) ($row['intent'] ?? 'Informational'),
                    ':position' => $index + 1,
                    ':created_at' => $createdAt,
                ]);
            }

            $cleanupStmt = $this->conn->prepare(
                'DELETE FROM keyword_results WHERE created_at < :retention_cutoff'
            );
            $cleanupStmt->execute([
                ':retention_cutoff' => date('Y-m-d H:i:s', time() - (7 * 24 * 3600)),
            ]);

            $this->conn->commit();
        } catch (Throwable $error) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Keyword batch save failed: ' . $error->getMessage());
        }
    }

    public function logSearchRequest(
        int $userId,
        string $seedKeyword,
        string $planType,
        int $resultCount,
        string $source,
        bool $countedForLimit
    ): void {
        $normalizedSeed = mb_strtolower(trim($seedKeyword));

        if ($this->useFileStorage) {
            $logs = $this->readJsonFile($this->logFile);
            $logs[] = [
                'user_id' => $userId,
                'seed_keyword' => $normalizedSeed,
                'plan_type' => $planType,
                'result_count' => $resultCount,
                'source' => $source,
                'counted_for_limit' => $countedForLimit ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s'),
            ];

            if (count($logs) > 2000) {
                $logs = array_slice($logs, -2000);
            }

            $this->writeJsonFile($this->logFile, $logs);
            return;
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO keyword_search_logs
                    (user_id, seed_keyword, plan_type, result_count, source, counted_for_limit, created_at)
                 VALUES
                    (:user_id, :seed_keyword, :plan_type, :result_count, :source, :counted_for_limit, :created_at)'
            );

            $stmt->execute([
                ':user_id' => $userId,
                ':seed_keyword' => $normalizedSeed,
                ':plan_type' => $planType,
                ':result_count' => $resultCount,
                ':source' => $source,
                ':counted_for_limit' => $countedForLimit ? 1 : 0,
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $error) {
            error_log('Keyword search log failed: ' . $error->getMessage());
        }
    }

    public function countDailySearches(int $userId): int
    {
        if ($this->useFileStorage) {
            $today = date('Y-m-d');
            $logs = $this->readJsonFile($this->logFile);
            $count = 0;
            foreach ($logs as $log) {
                if ((int) ($log['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if ((int) ($log['counted_for_limit'] ?? 0) !== 1) {
                    continue;
                }
                $createdAt = (string) ($log['created_at'] ?? '');
                if (strpos($createdAt, $today) === 0) {
                    $count++;
                }
            }
            return $count;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT COUNT(*) AS total
                 FROM keyword_search_logs
                 WHERE user_id = :user_id
                   AND counted_for_limit = 1
                   AND DATE(created_at) = CURDATE()'
            );
            $stmt->execute([':user_id' => $userId]);
            $row = $stmt->fetch();

            return (int) ($row['total'] ?? 0);
        } catch (Throwable $error) {
            error_log('Keyword daily count failed: ' . $error->getMessage());
            return 0;
        }
    }

    public function hasCountedSearchToday(int $userId, string $seedKeyword): bool
    {
        $normalizedSeed = mb_strtolower(trim($seedKeyword));

        if ($this->useFileStorage) {
            $today = date('Y-m-d');
            $logs = $this->readJsonFile($this->logFile);
            foreach ($logs as $log) {
                if ((int) ($log['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if ((int) ($log['counted_for_limit'] ?? 0) !== 1) {
                    continue;
                }
                if (($log['seed_keyword'] ?? '') !== $normalizedSeed) {
                    continue;
                }
                $createdAt = (string) ($log['created_at'] ?? '');
                if (strpos($createdAt, $today) === 0) {
                    return true;
                }
            }
            return false;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT id
                 FROM keyword_search_logs
                 WHERE user_id = :user_id
                   AND seed_keyword = :seed_keyword
                   AND counted_for_limit = 1
                   AND DATE(created_at) = CURDATE()
                 LIMIT 1'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':seed_keyword' => $normalizedSeed,
            ]);

            return (bool) $stmt->fetch();
        } catch (Throwable $error) {
            error_log('Keyword daily existence check failed: ' . $error->getMessage());
            return false;
        }
    }

    public function countRecentRequests(int $userId, int $windowSeconds = 60): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);

        if ($this->useFileStorage) {
            $logs = $this->readJsonFile($this->logFile);
            $count = 0;
            foreach ($logs as $log) {
                if ((int) ($log['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                $createdAt = strtotime((string) ($log['created_at'] ?? ''));
                if ($createdAt !== false && $createdAt >= strtotime($cutoff)) {
                    $count++;
                }
            }
            return $count;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT COUNT(*) AS total
                 FROM keyword_search_logs
                 WHERE user_id = :user_id
                   AND created_at >= :cutoff'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':cutoff' => $cutoff,
            ]);
            $row = $stmt->fetch();

            return (int) ($row['total'] ?? 0);
        } catch (Throwable $error) {
            error_log('Keyword recent count failed: ' . $error->getMessage());
            return 0;
        }
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
