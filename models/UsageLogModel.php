<?php

require_once __DIR__ . '/../config/database.php';

class UsageLogModel
{
    private ?PDO $conn;
    private bool $useFileStorage = false;
    private string $file;

    public function __construct()
    {
        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $this->file = $storageDir . '/usage_logs.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function log(int $userId, string $metric, int $qty = 1, ?int $projectId = null, ?string $context = null): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $metric = $this->sanitizeMetric($metric);
        $qty = max(1, min(100000, $qty));
        $context = $context !== null ? mb_substr(trim($context), 0, 100) : null;
        $now = date('Y-m-d H:i:s');

        if ($this->useFileStorage) {
            $rows = $this->readRows();
            $rows[] = [
                'id' => $this->nextId($rows),
                'user_id' => $userId,
                'project_id' => $projectId,
                'metric' => $metric,
                'qty' => $qty,
                'context' => $context,
                'created_at' => $now,
            ];
            if (count($rows) > 120000) {
                $rows = array_slice($rows, -120000);
            }
            $this->writeRows($rows);
            return true;
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO usage_logs (user_id, project_id, metric, qty, context, created_at)
                 VALUES (:user_id, :project_id, :metric, :qty, :context, :created_at)'
            );
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':project_id', $projectId, $projectId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':metric', $metric);
            $stmt->bindValue(':qty', $qty, PDO::PARAM_INT);
            $stmt->bindValue(':context', $context, $context !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':created_at', $now);
            $stmt->execute();
            return true;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'log');
            return $this->log($userId, $metric, $qty, $projectId, $context);
        }
    }

    public function countForUserMetric(int $userId, string $metric, int $windowSeconds = 86400): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $metric = $this->sanitizeMetric($metric);
        $cutoff = date('Y-m-d H:i:s', time() - max(1, $windowSeconds));

        if ($this->useFileStorage) {
            $total = 0;
            foreach ($this->readRows() as $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if ((string) ($row['metric'] ?? '') !== $metric) {
                    continue;
                }
                $createdAt = (string) ($row['created_at'] ?? '');
                if ($createdAt !== '' && $createdAt < $cutoff) {
                    continue;
                }
                $total += (int) ($row['qty'] ?? 0);
            }
            return $total;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT COALESCE(SUM(qty), 0) AS total
                 FROM usage_logs
                 WHERE user_id = :user_id
                   AND metric = :metric
                   AND created_at >= :cutoff'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':metric' => $metric,
                ':cutoff' => $cutoff,
            ]);
            $row = $stmt->fetch();
            return (int) ($row['total'] ?? 0);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'countForUserMetric');
            return $this->countForUserMetric($userId, $metric, $windowSeconds);
        }
    }

    public function getSummaryByUser(int $userId, int $days = 30): array
    {
        if ($userId <= 0) {
            return [];
        }
        $days = max(1, min(365, $days));
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

        $summary = [];
        if ($this->useFileStorage) {
            foreach ($this->readRows() as $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                $createdAt = (string) ($row['created_at'] ?? '');
                if ($createdAt !== '' && $createdAt < $cutoff) {
                    continue;
                }
                $metric = $this->sanitizeMetric((string) ($row['metric'] ?? 'generic'));
                $summary[$metric] = (int) ($summary[$metric] ?? 0) + (int) ($row['qty'] ?? 0);
            }
            ksort($summary);
            return $summary;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT metric, COALESCE(SUM(qty), 0) AS total
                 FROM usage_logs
                 WHERE user_id = :user_id
                   AND created_at >= :cutoff
                 GROUP BY metric
                 ORDER BY metric ASC'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':cutoff' => $cutoff,
            ]);
            foreach ($stmt->fetchAll() as $row) {
                $summary[$this->sanitizeMetric((string) ($row['metric'] ?? 'generic'))] = (int) ($row['total'] ?? 0);
            }
            return $summary;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getSummaryByUser');
            return $this->getSummaryByUser($userId, $days);
        }
    }

    public function topUsersByMetric(string $metric, int $days = 30, int $limit = 10): array
    {
        $metric = $this->sanitizeMetric($metric);
        $days = max(1, min(365, $days));
        $limit = max(1, min(100, $limit));
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

        if ($this->useFileStorage) {
            $totals = [];
            foreach ($this->readRows() as $row) {
                if ((string) ($row['metric'] ?? '') !== $metric) {
                    continue;
                }
                $createdAt = (string) ($row['created_at'] ?? '');
                if ($createdAt !== '' && $createdAt < $cutoff) {
                    continue;
                }
                $userId = (int) ($row['user_id'] ?? 0);
                if ($userId <= 0) {
                    continue;
                }
                $totals[$userId] = (int) ($totals[$userId] ?? 0) + (int) ($row['qty'] ?? 0);
            }
            arsort($totals);
            $result = [];
            foreach (array_slice($totals, 0, $limit, true) as $userId => $total) {
                $result[] = ['user_id' => (int) $userId, 'total' => (int) $total];
            }
            return $result;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT user_id, COALESCE(SUM(qty), 0) AS total
                 FROM usage_logs
                 WHERE metric = :metric
                   AND created_at >= :cutoff
                 GROUP BY user_id
                 ORDER BY total DESC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute([
                ':metric' => $metric,
                ':cutoff' => $cutoff,
            ]);
            $rows = $stmt->fetchAll();
            return array_map(static fn (array $row): array => [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'total' => (int) ($row['total'] ?? 0),
            ], $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'topUsersByMetric');
            return $this->topUsersByMetric($metric, $days, $limit);
        }
    }

    private function sanitizeMetric(string $metric): string
    {
        $metric = strtolower(trim($metric));
        $metric = preg_replace('/[^a-z0-9_\-\.]/', '_', $metric);
        if ($metric === '') {
            $metric = 'generic';
        }
        return mb_substr($metric, 0, 50);
    }

    private function readRows(): array
    {
        $raw = file_get_contents($this->file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeRows(array $rows): void
    {
        file_put_contents($this->file, json_encode($rows, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function nextId(array $rows): int
    {
        $max = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > $max) {
                $max = $id;
            }
        }
        return $max + 1;
    }

    private function switchToFileStorage(Throwable $error, string $context): void
    {
        error_log('UsageLogModel fallback (' . $context . '): ' . $error->getMessage());
        $this->useFileStorage = true;
    }
}

