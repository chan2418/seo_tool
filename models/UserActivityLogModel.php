<?php

require_once __DIR__ . '/../config/database.php';

class UserActivityLogModel
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
        $this->file = $storageDir . '/user_activity_logs.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function create(?int $userId, string $actionType, array $metadata = [], ?string $ipAddress = null, ?string $userAgent = null): bool
    {
        $actionType = $this->sanitizeAction($actionType);
        if ($actionType === '') {
            return false;
        }
        $ipAddress = $this->sanitizeText($ipAddress, 45);
        $userAgent = $this->sanitizeText($userAgent, 255);
        $metadata = $this->sanitizeMetadata($metadata);
        $now = date('Y-m-d H:i:s');

        if ($this->useFileStorage) {
            $rows = $this->readRows();
            $rows[] = [
                'id' => $this->nextId($rows),
                'user_id' => $userId,
                'action_type' => $actionType,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata_json' => $metadata,
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
                'INSERT INTO user_activity_logs (user_id, action_type, ip_address, user_agent, metadata_json, created_at)
                 VALUES (:user_id, :action_type, :ip_address, :user_agent, :metadata_json, :created_at)'
            );
            $stmt->bindValue(':user_id', $userId, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':action_type', $actionType, PDO::PARAM_STR);
            $stmt->bindValue(':ip_address', $ipAddress, $ipAddress !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':user_agent', $userAgent, $userAgent !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':metadata_json', json_encode($metadata), PDO::PARAM_STR);
            $stmt->bindValue(':created_at', $now, PDO::PARAM_STR);
            $stmt->execute();
            return true;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'create');
            return $this->create($userId, $actionType, $metadata, $ipAddress, $userAgent);
        }
    }

    public function getRecentByUser(int $userId, int $limit = 30): array
    {
        $limit = max(1, min(500, $limit));
        if ($userId <= 0) {
            return [];
        }

        if ($this->useFileStorage) {
            $rows = array_values(array_filter($this->readRows(), static fn (array $row): bool => (int) ($row['user_id'] ?? 0) === $userId));
            usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
            return array_map(fn (array $row): array => $this->normalizeRow($row), array_slice($rows, 0, $limit));
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT id, user_id, action_type, ip_address, user_agent, metadata_json, created_at
                 FROM user_activity_logs
                 WHERE user_id = :user_id
                 ORDER BY created_at DESC, id DESC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute([':user_id' => $userId]);
            return array_map(fn (array $row): array => $this->normalizeRow($row), $stmt->fetchAll() ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getRecentByUser');
            return $this->getRecentByUser($userId, $limit);
        }
    }

    private function sanitizeAction(string $actionType): string
    {
        $actionType = strtolower(trim($actionType));
        $actionType = preg_replace('/[^a-z0-9_\.\-]/', '_', $actionType);
        return mb_substr((string) $actionType, 0, 80);
    }

    private function sanitizeText(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $max);
    }

    private function sanitizeMetadata(array $metadata): array
    {
        $encoded = json_encode($metadata);
        if (!is_string($encoded) || $encoded === false) {
            return [];
        }
        $decoded = json_decode($encoded, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeRow(array $row): array
    {
        $metadata = $row['metadata_json'] ?? [];
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($metadata)) {
            $metadata = [];
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'action_type' => (string) ($row['action_type'] ?? ''),
            'ip_address' => isset($row['ip_address']) ? (string) $row['ip_address'] : null,
            'user_agent' => isset($row['user_agent']) ? (string) $row['user_agent'] : null,
            'metadata' => $metadata,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
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
        error_log('UserActivityLogModel fallback (' . $context . '): ' . $error->getMessage());
        $this->useFileStorage = true;
    }
}
