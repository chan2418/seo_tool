<?php

require_once __DIR__ . '/../config/database.php';

class AlertModel
{
    private ?PDO $conn;
    private bool $useFileStorage = false;
    private string $alertsFile;
    private string $usersCacheFile;
    private string $projectsFile;

    public function __construct()
    {
        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $this->alertsFile = $storageDir . '/alerts.json';
        $this->usersCacheFile = $storageDir . '/users_cache.json';
        $this->projectsFile = $storageDir . '/projects.json';

        if (!file_exists($this->alertsFile)) {
            file_put_contents($this->alertsFile, json_encode([]));
        }
        if (!file_exists($this->usersCacheFile)) {
            file_put_contents($this->usersCacheFile, json_encode([]));
        }
        if (!file_exists($this->projectsFile)) {
            file_put_contents($this->projectsFile, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function createAlertUnique(
        int $userId,
        int $projectId,
        string $alertType,
        string $referenceId,
        string $message,
        string $severity,
        string $createdDate
    ): array {
        if ($userId <= 0 || $projectId <= 0) {
            return ['success' => false, 'created' => false, 'error' => 'Invalid user or project.'];
        }

        $alertType = $this->sanitizeToken($alertType, 60, 'unknown');
        $referenceId = $this->sanitizeToken($referenceId, 120, 'ref:0');
        $severity = $this->sanitizeSeverity($severity);
        $message = $this->sanitizeMessage($message);
        $date = $this->normalizeDate($createdDate);
        $now = $date . ' ' . date('H:i:s');

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->alertsFile);
            foreach ($rows as $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if ((int) ($row['project_id'] ?? 0) !== $projectId) {
                    continue;
                }
                if ((string) ($row['alert_type'] ?? '') !== $alertType) {
                    continue;
                }
                if ((string) ($row['reference_id'] ?? '') !== $referenceId) {
                    continue;
                }
                if (strpos((string) ($row['created_at'] ?? ''), $date) !== 0) {
                    continue;
                }

                return ['success' => true, 'created' => false, 'id' => (int) ($row['id'] ?? 0)];
            }

            $newRow = [
                'id' => $this->nextId($rows),
                'user_id' => $userId,
                'project_id' => $projectId,
                'alert_type' => $alertType,
                'reference_id' => $referenceId,
                'message' => $message,
                'severity' => $severity,
                'is_read' => 0,
                'created_at' => $now,
            ];
            $rows[] = $newRow;
            if (count($rows) > 18000) {
                $rows = array_slice($rows, -18000);
            }
            $this->writeJsonFile($this->alertsFile, $rows);

            return ['success' => true, 'created' => true, 'id' => (int) $newRow['id']];
        }

        try {
            $checkStmt = $this->conn->prepare(
                'SELECT id
                 FROM alerts
                 WHERE user_id = :user_id
                   AND project_id = :project_id
                   AND alert_type = :alert_type
                   AND reference_id = :reference_id
                   AND DATE(created_at) = :created_date
                 LIMIT 1'
            );
            $checkStmt->execute([
                ':user_id' => $userId,
                ':project_id' => $projectId,
                ':alert_type' => $alertType,
                ':reference_id' => $referenceId,
                ':created_date' => $date,
            ]);
            $existing = $checkStmt->fetch();
            if ($existing) {
                return ['success' => true, 'created' => false, 'id' => (int) ($existing['id'] ?? 0)];
            }

            $insertStmt = $this->conn->prepare(
                'INSERT INTO alerts
                    (user_id, project_id, alert_type, reference_id, message, severity, is_read, created_at)
                 VALUES
                    (:user_id, :project_id, :alert_type, :reference_id, :message, :severity, :is_read, :created_at)'
            );
            $insertStmt->execute([
                ':user_id' => $userId,
                ':project_id' => $projectId,
                ':alert_type' => $alertType,
                ':reference_id' => $referenceId,
                ':message' => $message,
                ':severity' => $severity,
                ':is_read' => 0,
                ':created_at' => $now,
            ]);

            return ['success' => true, 'created' => true, 'id' => (int) $this->conn->lastInsertId()];
        } catch (Throwable $error) {
            $sqlState = (string) $error->getCode();
            if ($sqlState === '23000' || stripos($error->getMessage(), 'duplicate') !== false) {
                return ['success' => true, 'created' => false, 'id' => 0];
            }
            $this->switchToFileStorage($error, 'createAlertUnique');
            return $this->createAlertUnique($userId, $projectId, $alertType, $referenceId, $message, $severity, $date);
        }
    }

    public function getAlerts(int $userId, array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 15)));
        $projectId = isset($filters['project_id']) ? (int) $filters['project_id'] : null;
        $severity = isset($filters['severity']) ? strtolower(trim((string) $filters['severity'])) : null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        $isRead = null;
        if (array_key_exists('is_read', $filters) && $filters['is_read'] !== null && $filters['is_read'] !== '') {
            $isRead = ((int) $filters['is_read'] === 1) ? 1 : 0;
        }
        $alertTypes = isset($filters['alert_types']) && is_array($filters['alert_types']) ? array_values(array_filter(array_map('strval', $filters['alert_types']))) : [];

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->alertsFile);
            $projectMap = $this->projectMap($userId);

            $filtered = [];
            foreach ($rows as $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if ($projectId !== null && $projectId > 0 && (int) ($row['project_id'] ?? 0) !== $projectId) {
                    continue;
                }
                if ($severity !== null && $severity !== '' && strtolower((string) ($row['severity'] ?? 'info')) !== $severity) {
                    continue;
                }
                if ($isRead !== null && (int) ($row['is_read'] ?? 0) !== $isRead) {
                    continue;
                }
                if (!empty($alertTypes) && !in_array((string) ($row['alert_type'] ?? ''), $alertTypes, true)) {
                    continue;
                }
                $message = (string) ($row['message'] ?? '');
                if ($search !== '' && stripos($message, $search) === false && stripos((string) ($row['alert_type'] ?? ''), $search) === false) {
                    continue;
                }

                $row['project_name'] = (string) ($projectMap[(int) ($row['project_id'] ?? 0)]['name'] ?? 'Project');
                $row['project_domain'] = (string) ($projectMap[(int) ($row['project_id'] ?? 0)]['domain'] ?? '');
                $filtered[] = $this->normalizeAlertRow($row);
            }

            usort($filtered, static function (array $a, array $b): int {
                return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
            });

            $total = count($filtered);
            $offset = ($page - 1) * $perPage;
            $items = array_slice($filtered, $offset, $perPage);

            return [
                'items' => $items,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ];
        }

        try {
            $where = ['a.user_id = :user_id'];
            $params = [':user_id' => $userId];

            if ($projectId !== null && $projectId > 0) {
                $where[] = 'a.project_id = :project_id';
                $params[':project_id'] = $projectId;
            }
            if ($severity !== null && $severity !== '') {
                $where[] = 'a.severity = :severity';
                $params[':severity'] = $severity;
            }
            if ($isRead !== null) {
                $where[] = 'a.is_read = :is_read';
                $params[':is_read'] = $isRead;
            }
            if ($search !== '') {
                $where[] = '(a.message LIKE :search OR a.alert_type LIKE :search)';
                $params[':search'] = '%' . $search . '%';
            }
            if (!empty($alertTypes)) {
                $inParams = [];
                foreach ($alertTypes as $index => $type) {
                    $key = ':alert_type_' . $index;
                    $inParams[] = $key;
                    $params[$key] = $type;
                }
                $where[] = 'a.alert_type IN (' . implode(', ', $inParams) . ')';
            }

            $whereSql = implode(' AND ', $where);

            $countStmt = $this->conn->prepare('SELECT COUNT(*) AS total FROM alerts a WHERE ' . $whereSql);
            $countStmt->execute($params);
            $countRow = $countStmt->fetch();
            $total = (int) ($countRow['total'] ?? 0);

            $offset = ($page - 1) * $perPage;
            $listSql = 'SELECT a.id, a.user_id, a.project_id, a.alert_type, a.reference_id, a.message, a.severity, a.is_read, a.created_at,
                               p.name AS project_name, p.domain AS project_domain
                        FROM alerts a
                        LEFT JOIN projects p ON p.id = a.project_id
                        WHERE ' . $whereSql . '
                        ORDER BY a.created_at DESC
                        LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;

            $listStmt = $this->conn->prepare($listSql);
            $listStmt->execute($params);
            $rows = $listStmt->fetchAll();

            return [
                'items' => array_map(fn (array $row): array => $this->normalizeAlertRow($row), $rows ?: []),
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ];
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getAlerts');
            return $this->getAlerts($userId, $filters);
        }
    }

    public function countUnread(int $userId, array $filters = []): int
    {
        $filters['is_read'] = 0;
        $result = $this->getAlerts($userId, array_merge($filters, ['page' => 1, 'per_page' => 1]));
        return (int) ($result['total'] ?? 0);
    }

    public function getRecentAlerts(int $userId, int $limit = 8, array $filters = []): array
    {
        $result = $this->getAlerts($userId, array_merge($filters, [
            'page' => 1,
            'per_page' => max(1, min(50, $limit)),
        ]));
        return (array) ($result['items'] ?? []);
    }

    public function markRead(int $userId, int $alertId): bool
    {
        if ($userId <= 0 || $alertId <= 0) {
            return false;
        }

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->alertsFile);
            $updated = false;
            foreach ($rows as $index => $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if ((int) ($row['id'] ?? 0) !== $alertId) {
                    continue;
                }
                $rows[$index]['is_read'] = 1;
                $updated = true;
                break;
            }
            if ($updated) {
                $this->writeJsonFile($this->alertsFile, $rows);
            }
            return $updated;
        }

        try {
            $stmt = $this->conn->prepare(
                'UPDATE alerts
                 SET is_read = 1
                 WHERE id = :id
                   AND user_id = :user_id'
            );
            $stmt->execute([
                ':id' => $alertId,
                ':user_id' => $userId,
            ]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'markRead');
            return $this->markRead($userId, $alertId);
        }
    }

    public function markAllRead(int $userId, ?int $projectId = null): int
    {
        if ($userId <= 0) {
            return 0;
        }

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->alertsFile);
            $count = 0;
            foreach ($rows as $index => $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if ($projectId !== null && $projectId > 0 && (int) ($row['project_id'] ?? 0) !== $projectId) {
                    continue;
                }
                if ((int) ($row['is_read'] ?? 0) === 1) {
                    continue;
                }
                $rows[$index]['is_read'] = 1;
                $count++;
            }
            if ($count > 0) {
                $this->writeJsonFile($this->alertsFile, $rows);
            }
            return $count;
        }

        try {
            $sql = 'UPDATE alerts SET is_read = 1 WHERE user_id = :user_id AND is_read = 0';
            $params = [':user_id' => $userId];
            if ($projectId !== null && $projectId > 0) {
                $sql .= ' AND project_id = :project_id';
                $params[':project_id'] = $projectId;
            }

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'markAllRead');
            return $this->markAllRead($userId, $projectId);
        }
    }

    public function getUserEmailAndPlan(int $userId): array
    {
        if ($userId <= 0) {
            return ['email' => '', 'plan_type' => 'free', 'name' => 'User'];
        }

        if ($this->useFileStorage) {
            $cache = $this->readJsonFile($this->usersCacheFile);
            if (isset($cache[$userId]) && is_array($cache[$userId])) {
                return [
                    'email' => (string) ($cache[$userId]['email'] ?? ''),
                    'plan_type' => strtolower((string) ($cache[$userId]['plan_type'] ?? 'free')),
                    'name' => (string) ($cache[$userId]['name'] ?? 'User'),
                ];
            }

            return ['email' => '', 'plan_type' => 'free', 'name' => 'User'];
        }

        try {
            $stmt = $this->conn->prepare('SELECT name, email, plan_type FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch();
            if (!$row) {
                return ['email' => '', 'plan_type' => 'free', 'name' => 'User'];
            }

            return [
                'email' => (string) ($row['email'] ?? ''),
                'plan_type' => strtolower((string) ($row['plan_type'] ?? 'free')),
                'name' => (string) ($row['name'] ?? 'User'),
            ];
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getUserEmailAndPlan');
            return $this->getUserEmailAndPlan($userId);
        }
    }

    public function getProjectById(int $userId, int $projectId): ?array
    {
        if ($userId <= 0 || $projectId <= 0) {
            return null;
        }

        if ($this->useFileStorage) {
            $map = $this->projectMap($userId);
            return $map[$projectId] ?? null;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT id, user_id, name, domain, created_at, updated_at
                 FROM projects
                 WHERE id = :id
                   AND user_id = :user_id
                 LIMIT 1'
            );
            $stmt->execute([
                ':id' => $projectId,
                ':user_id' => $userId,
            ]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            return [
                'id' => (int) ($row['id'] ?? 0),
                'user_id' => (int) ($row['user_id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'domain' => (string) ($row['domain'] ?? ''),
            ];
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getProjectById');
            return $this->getProjectById($userId, $projectId);
        }
    }

    public function getProjectsByUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        if ($this->useFileStorage) {
            return array_values($this->projectMap($userId));
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT id, user_id, name, domain, created_at, updated_at
                 FROM projects
                 WHERE user_id = :user_id
                 ORDER BY created_at DESC'
            );
            $stmt->execute([':user_id' => $userId]);
            $rows = $stmt->fetchAll();
            return array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'user_id' => (int) ($row['user_id'] ?? 0),
                    'name' => (string) ($row['name'] ?? ''),
                    'domain' => (string) ($row['domain'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                ];
            }, $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getProjectsByUser');
            return $this->getProjectsByUser($userId);
        }
    }

    public function getUserIdsWithProjects(): array
    {
        if ($this->useFileStorage) {
            $projects = $this->readJsonFile($this->projectsFile);
            $userMap = [];
            foreach ($projects as $project) {
                $userId = (int) ($project['user_id'] ?? 0);
                if ($userId > 0) {
                    $userMap[$userId] = true;
                }
            }
            return array_map('intval', array_keys($userMap));
        }

        try {
            $stmt = $this->conn->query('SELECT DISTINCT user_id FROM projects ORDER BY user_id ASC');
            $rows = $stmt->fetchAll();
            return array_map(static fn (array $row): int => (int) ($row['user_id'] ?? 0), $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getUserIdsWithProjects');
            return $this->getUserIdsWithProjects();
        }
    }

    private function normalizeAlertRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'project_id' => (int) ($row['project_id'] ?? 0),
            'alert_type' => (string) ($row['alert_type'] ?? ''),
            'reference_id' => (string) ($row['reference_id'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
            'severity' => $this->sanitizeSeverity((string) ($row['severity'] ?? 'info')),
            'is_read' => (int) (($row['is_read'] ?? 0) ? 1 : 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'project_name' => (string) ($row['project_name'] ?? 'Project'),
            'project_domain' => (string) ($row['project_domain'] ?? ''),
        ];
    }

    private function sanitizeToken(string $value, int $maxLength, string $default): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9:_-]/', '', $value);
        $value = (string) $value;
        if ($value === '') {
            return $default;
        }
        return substr($value, 0, $maxLength);
    }

    private function sanitizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));
        if (!in_array($severity, ['info', 'warning', 'critical'], true)) {
            return 'info';
        }
        return $severity;
    }

    private function sanitizeMessage(string $message): string
    {
        $message = trim(strip_tags($message));
        $message = preg_replace('/\s+/', ' ', $message);
        if ($message === '') {
            return 'Alert triggered.';
        }
        return mb_substr((string) $message, 0, 600);
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return date('Y-m-d');
        }
        return date('Y-m-d', $ts);
    }

    private function projectMap(int $userId): array
    {
        $projects = $this->readJsonFile($this->projectsFile);
        $map = [];
        foreach ($projects as $project) {
            if ((int) ($project['user_id'] ?? 0) !== $userId) {
                continue;
            }
            $projectId = (int) ($project['id'] ?? 0);
            if ($projectId <= 0) {
                continue;
            }
            $map[$projectId] = [
                'id' => $projectId,
                'name' => (string) ($project['name'] ?? 'Project'),
                'domain' => (string) ($project['domain'] ?? ''),
            ];
        }
        return $map;
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
        error_log('AlertModel fallback (' . $context . '): ' . $error->getMessage());
        $this->useFileStorage = true;
    }
}
