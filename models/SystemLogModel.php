<?php

require_once __DIR__ . '/../config/database.php';

class SystemLogModel
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

        $this->file = $storageDir . '/system_logs.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function create(
        string $level,
        string $source,
        string $message,
        array $context = [],
        ?int $userId = null,
        ?int $projectId = null
    ): bool {
        $level = $this->sanitizeLevel($level);
        $source = mb_substr(trim($source) !== '' ? trim($source) : 'system', 0, 60);
        $message = mb_substr(trim($message), 0, 800);
        if ($message === '') {
            $message = 'System event';
        }
        $context = $this->sanitizeContext($context);
        $now = date('Y-m-d H:i:s');

        if ($this->useFileStorage) {
            $rows = $this->readRows();
            $rows[] = [
                'id' => $this->nextId($rows),
                'level' => $level,
                'source' => $source,
                'user_id' => $userId,
                'project_id' => $projectId,
                'message' => $message,
                'context_json' => $context,
                'created_at' => $now,
            ];
            if (count($rows) > 50000) {
                $rows = array_slice($rows, -50000);
            }
            $this->writeRows($rows);
            return true;
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO system_logs (level, source, user_id, project_id, message, context_json, created_at)
                 VALUES (:level, :source, :user_id, :project_id, :message, :context_json, :created_at)'
            );
            $stmt->bindValue(':level', $level);
            $stmt->bindValue(':source', $source);
            $stmt->bindValue(':user_id', $userId, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':project_id', $projectId, $projectId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':message', $message);
            $stmt->bindValue(':context_json', json_encode($context));
            $stmt->bindValue(':created_at', $now);
            $stmt->execute();
            return true;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'create');
            return $this->create($level, $source, $message, $context, $userId, $projectId);
        }
    }

    public function getRecent(int $limit = 50, ?string $level = null, ?string $source = null): array
    {
        $limit = max(1, min(500, $limit));
        $level = $level !== null ? $this->sanitizeLevel($level) : null;
        $source = $source !== null ? trim($source) : null;

        if ($this->useFileStorage) {
            $rows = $this->readRows();
            $rows = array_values(array_filter($rows, static function (array $row) use ($level, $source): bool {
                if ($level !== null && strtolower((string) ($row['level'] ?? '')) !== $level) {
                    return false;
                }
                if ($source !== null && $source !== '' && stripos((string) ($row['source'] ?? ''), $source) === false) {
                    return false;
                }
                return true;
            }));
            usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
            return array_slice(array_map(fn (array $row): array => $this->normalizeRow($row), $rows), 0, $limit);
        }

        try {
            $where = [];
            $params = [];
            if ($level !== null) {
                $where[] = 'level = :level';
                $params[':level'] = $level;
            }
            if ($source !== null && $source !== '') {
                $where[] = 'source LIKE :source';
                $params[':source'] = '%' . $source . '%';
            }

            $sql = 'SELECT id, level, source, user_id, project_id, message, context_json, created_at FROM system_logs';
            if (!empty($where)) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit;

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            return array_map(fn (array $row): array => $this->normalizeRow($row), $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getRecent');
            return $this->getRecent($limit, $level, $source);
        }
    }

    public function summaryLastDays(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        $summary = [
            'info' => 0,
            'warning' => 0,
            'error' => 0,
            'critical' => 0,
            'total' => 0,
        ];

        if ($this->useFileStorage) {
            foreach ($this->readRows() as $row) {
                $createdAt = (string) ($row['created_at'] ?? '');
                if ($createdAt !== '' && $createdAt < $cutoff) {
                    continue;
                }
                $level = $this->sanitizeLevel((string) ($row['level'] ?? 'info'));
                $summary[$level] = (int) ($summary[$level] ?? 0) + 1;
                $summary['total']++;
            }
            return $summary;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT level, COUNT(*) AS total
                 FROM system_logs
                 WHERE created_at >= :cutoff
                 GROUP BY level'
            );
            $stmt->execute([':cutoff' => $cutoff]);
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                $level = $this->sanitizeLevel((string) ($row['level'] ?? 'info'));
                $summary[$level] = (int) ($row['total'] ?? 0);
                $summary['total'] += (int) ($row['total'] ?? 0);
            }
            return $summary;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'summaryLastDays');
            return $this->summaryLastDays($days);
        }
    }

    private function sanitizeLevel(string $level): string
    {
        $level = strtolower(trim($level));
        if (!in_array($level, ['info', 'warning', 'error', 'critical'], true)) {
            return 'info';
        }
        return $level;
    }

    private function sanitizeContext(array $context): array
    {
        $encoded = json_encode($context);
        if (!is_string($encoded) || $encoded === false) {
            return [];
        }
        $decoded = json_decode($encoded, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeRow(array $row): array
    {
        $context = $row['context_json'] ?? [];
        if (is_string($context)) {
            $decoded = json_decode($context, true);
            $context = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($context)) {
            $context = [];
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'level' => $this->sanitizeLevel((string) ($row['level'] ?? 'info')),
            'source' => (string) ($row['source'] ?? 'system'),
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : null,
            'message' => (string) ($row['message'] ?? ''),
            'context' => $context,
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
        error_log('SystemLogModel fallback (' . $context . '): ' . $error->getMessage());
        $this->useFileStorage = true;
    }
}

