<?php

require_once __DIR__ . '/../config/database.php';

class CompetitorModel
{
    private ?PDO $conn;
    private bool $useFileStorage = false;
    private string $snapshotFile;
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

        $this->snapshotFile = $storageDir . '/competitor_snapshots.json';
        $this->logFile = $storageDir . '/phase3_request_logs.json';

        if (!file_exists($this->snapshotFile)) {
            file_put_contents($this->snapshotFile, json_encode([]));
        }

        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function getCachedSnapshot(string $domain, int $maxAgeHours = 24): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return [];
        }

        if ($this->useFileStorage) {
            $snapshots = $this->readJsonFile($this->snapshotFile);
            $entry = $snapshots[$domain] ?? null;
            if (!is_array($entry)) {
                return [];
            }

            $createdAt = strtotime((string) ($entry['created_at'] ?? ''));
            if ($createdAt === false || $createdAt < (time() - ($maxAgeHours * 3600))) {
                return [];
            }

            $payload = $entry['payload'] ?? [];
            return is_array($payload) ? $payload : [];
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT payload
                 FROM competitor_snapshots
                 WHERE domain = :domain
                   AND created_at >= :cutoff
                 ORDER BY created_at DESC
                 LIMIT 1'
            );

            $stmt->execute([
                ':domain' => $domain,
                ':cutoff' => date('Y-m-d H:i:s', time() - ($maxAgeHours * 3600)),
            ]);

            $row = $stmt->fetch();
            if (!$row || empty($row['payload'])) {
                return [];
            }

            $decoded = json_decode((string) $row['payload'], true);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $error) {
            error_log('Competitor cache read failed: ' . $error->getMessage());
            return [];
        }
    }

    public function saveSnapshot(int $userId, string $domain, array $payload, string $source = 'api'): void
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || empty($payload)) {
            return;
        }

        $summary = $payload['summary'] ?? [];

        if ($this->useFileStorage) {
            $snapshots = $this->readJsonFile($this->snapshotFile);
            $snapshots[$domain] = [
                'user_id' => $userId,
                'source' => $source,
                'created_at' => date('Y-m-d H:i:s'),
                'payload' => $payload,
            ];
            $this->writeJsonFile($this->snapshotFile, $snapshots);
            return;
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO competitor_snapshots
                    (user_id, domain, organic_traffic, domain_authority, ranking_keywords, domain_health_score, pagespeed_score, source, payload, created_at)
                 VALUES
                    (:user_id, :domain, :organic_traffic, :domain_authority, :ranking_keywords, :domain_health_score, :pagespeed_score, :source, :payload, :created_at)'
            );

            $stmt->execute([
                ':user_id' => $userId,
                ':domain' => $domain,
                ':organic_traffic' => (int) ($summary['organic_traffic'] ?? 0),
                ':domain_authority' => (int) ($summary['domain_authority'] ?? 0),
                ':ranking_keywords' => (int) ($summary['ranking_keywords'] ?? 0),
                ':domain_health_score' => (int) ($summary['domain_health_score'] ?? 0),
                ':pagespeed_score' => (int) ($summary['pagespeed_score'] ?? 0),
                ':source' => $source,
                ':payload' => json_encode($payload),
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $error) {
            error_log('Competitor snapshot save failed: ' . $error->getMessage());
        }
    }

    public function countRecentRequests(int $userId, int $windowSeconds = 60): int
    {
        return $this->countRecentModuleRequests($userId, 'competitor', $windowSeconds);
    }

    public function logRequest(int $userId, string $requestKey, string $source, int $statusCode): void
    {
        $this->logModuleRequest($userId, 'competitor', $requestKey, $source, $statusCode);
    }

    public function getLatestOrganicTrafficByUser(int $userId): int
    {
        if ($this->useFileStorage) {
            $snapshots = $this->readJsonFile($this->snapshotFile);
            $latest = 0;
            foreach ($snapshots as $entry) {
                if (!is_array($entry) || (int) ($entry['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                $traffic = (int) ($entry['payload']['summary']['organic_traffic'] ?? 0);
                if ($traffic > $latest) {
                    $latest = $traffic;
                }
            }
            return $latest;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT organic_traffic
                 FROM competitor_snapshots
                 WHERE user_id = :user_id
                 ORDER BY created_at DESC
                 LIMIT 1'
            );
            $stmt->execute([':user_id' => $userId]);
            $row = $stmt->fetch();

            return (int) ($row['organic_traffic'] ?? 0);
        } catch (Throwable $error) {
            return 0;
        }
    }

    private function countRecentModuleRequests(int $userId, string $module, int $windowSeconds): int
    {
        $cutoffTimestamp = time() - $windowSeconds;

        if ($this->useFileStorage) {
            $logs = $this->readJsonFile($this->logFile);
            $count = 0;
            foreach ($logs as $log) {
                if ((int) ($log['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if (($log['module'] ?? '') !== $module) {
                    continue;
                }
                $createdAt = strtotime((string) ($log['created_at'] ?? ''));
                if ($createdAt !== false && $createdAt >= $cutoffTimestamp) {
                    $count++;
                }
            }
            return $count;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT COUNT(*) AS total
                 FROM phase3_request_logs
                 WHERE user_id = :user_id
                   AND module = :module
                   AND created_at >= :cutoff'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':module' => $module,
                ':cutoff' => date('Y-m-d H:i:s', $cutoffTimestamp),
            ]);
            $row = $stmt->fetch();

            return (int) ($row['total'] ?? 0);
        } catch (Throwable $error) {
            return 0;
        }
    }

    private function logModuleRequest(int $userId, string $module, string $requestKey, string $source, int $statusCode): void
    {
        if ($this->useFileStorage) {
            $logs = $this->readJsonFile($this->logFile);
            $logs[] = [
                'user_id' => $userId,
                'module' => $module,
                'request_key' => $requestKey,
                'source' => $source,
                'status_code' => $statusCode,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            if (count($logs) > 4000) {
                $logs = array_slice($logs, -4000);
            }
            $this->writeJsonFile($this->logFile, $logs);
            return;
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO phase3_request_logs
                    (user_id, module, request_key, source, status_code, created_at)
                 VALUES
                    (:user_id, :module, :request_key, :source, :status_code, :created_at)'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':module' => $module,
                ':request_key' => $requestKey,
                ':source' => $source,
                ':status_code' => $statusCode,
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $error) {
            error_log('Phase3 request log failed: ' . $error->getMessage());
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
