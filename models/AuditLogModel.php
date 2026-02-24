<?php

require_once __DIR__ . '/../config/database.php';

class AuditLogModel
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

        $this->file = $storageDir . '/audit_logs.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function create(
        ?int $actorUserId,
        ?int $targetUserId,
        ?int $projectId,
        string $actionType,
        ?string $ipAddress = null,
        array $metadata = []
    ): bool {
        $actionType = $this->sanitizeAction($actionType);
        if ($actionType === '') {
            return false;
        }
        $ipAddress = $this->sanitizeIp($ipAddress);
        $metadata = $this->sanitizeMetadata($metadata);
        $now = date('Y-m-d H:i:s');

        if ($this->useFileStorage) {
            $rows = $this->readRows();
            $rows[] = [
                'id' => $this->nextId($rows),
                'actor_user_id' => $actorUserId,
                'target_user_id' => $targetUserId,
                'project_id' => $projectId,
                'action_type' => $actionType,
                'ip_address' => $ipAddress,
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
                'INSERT INTO audit_logs
                    (actor_user_id, target_user_id, project_id, action_type, ip_address, metadata_json, created_at)
                 VALUES
                    (:actor_user_id, :target_user_id, :project_id, :action_type, :ip_address, :metadata_json, :created_at)'
            );
            $stmt->bindValue(':actor_user_id', $actorUserId, $actorUserId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':target_user_id', $targetUserId, $targetUserId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':project_id', $projectId, $projectId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':action_type', $actionType, PDO::PARAM_STR);
            $stmt->bindValue(':ip_address', $ipAddress, $ipAddress !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':metadata_json', json_encode($metadata), PDO::PARAM_STR);
            $stmt->bindValue(':created_at', $now, PDO::PARAM_STR);
            $stmt->execute();
            return true;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'create');
            return $this->create($actorUserId, $targetUserId, $projectId, $actionType, $ipAddress, $metadata);
        }
    }

    public function getRecent(int $limit = 100, ?string $actionType = null): array
    {
        $limit = max(1, min(1000, $limit));
        $actionType = $actionType !== null ? $this->sanitizeAction($actionType) : null;

        if ($this->useFileStorage) {
            $rows = $this->readRows();
            if ($actionType !== null && $actionType !== '') {
                $rows = array_values(array_filter($rows, static function (array $row) use ($actionType): bool {
                    return (string) ($row['action_type'] ?? '') === $actionType;
                }));
            }
            usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
            $rows = array_slice($rows, 0, $limit);
            return array_map(fn (array $row): array => $this->normalizeRow($row), $rows);
        }

        try {
            $params = [];
            $where = '';
            if ($actionType !== null && $actionType !== '') {
                $where = 'WHERE action_type = :action_type';
                $params[':action_type'] = $actionType;
            }

            $sql = 'SELECT id, actor_user_id, target_user_id, project_id, action_type, ip_address, metadata_json, created_at
                    FROM audit_logs
                    ' . $where . '
                    ORDER BY created_at DESC, id DESC
                    LIMIT ' . (int) $limit;

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            return array_map(fn (array $row): array => $this->normalizeRow($row), $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getRecent');
            return $this->getRecent($limit, $actionType);
        }
    }

    private function sanitizeAction(string $actionType): string
    {
        $actionType = strtolower(trim($actionType));
        $actionType = preg_replace('/[^a-z0-9_\.\-]/', '_', $actionType);
        return mb_substr((string) $actionType, 0, 80);
    }

    private function sanitizeIp(?string $ipAddress): ?string
    {
        if ($ipAddress === null) {
            return null;
        }
        $ipAddress = trim($ipAddress);
        if ($ipAddress === '') {
            return null;
        }
        return mb_substr($ipAddress, 0, 45);
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
            'actor_user_id' => isset($row['actor_user_id']) ? (int) $row['actor_user_id'] : null,
            'target_user_id' => isset($row['target_user_id']) ? (int) $row['target_user_id'] : null,
            'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : null,
            'action_type' => (string) ($row['action_type'] ?? ''),
            'ip_address' => isset($row['ip_address']) ? (string) $row['ip_address'] : null,
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
        error_log('AuditLogModel fallback (' . $context . '): ' . $error->getMessage());
        $this->useFileStorage = true;
    }
}
