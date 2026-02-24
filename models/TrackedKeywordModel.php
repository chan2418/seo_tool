<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditModel.php';
require_once __DIR__ . '/../utils/SecurityValidator.php';

class TrackedKeywordModel
{
    private ?PDO $conn;
    private bool $useFileStorage = false;
    private string $projectsFile;
    private string $trackedKeywordsFile;
    private string $logFile;
    private AuditModel $auditModel;

    public function __construct(?AuditModel $auditModel = null)
    {
        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $this->projectsFile = $storageDir . '/projects.json';
        $this->trackedKeywordsFile = $storageDir . '/tracked_keywords.json';
        $this->logFile = $storageDir . '/phase3_request_logs.json';
        $this->auditModel = $auditModel ?? new AuditModel();

        if (!file_exists($this->projectsFile)) {
            file_put_contents($this->projectsFile, json_encode([]));
        }

        if (!file_exists($this->trackedKeywordsFile)) {
            file_put_contents($this->trackedKeywordsFile, json_encode([]));
        }

        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function syncProjectsFromAudits(int $userId): void
    {
        $audits = $this->auditModel->getUserAudits($userId);
        foreach ($audits as $audit) {
            $url = (string) ($audit['url'] ?? '');
            $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
            $host = SecurityValidator::sanitizeDomain($host);
            if (!SecurityValidator::isValidDomain($host)) {
                continue;
            }

            $label = (string) ucwords(str_replace(['-', '_'], ' ', explode('.', $host)[0] ?? $host));
            $this->upsertProject($userId, $host, $label !== '' ? $label : $host);
        }
    }

    public function getProjects(int $userId): array
    {
        $this->syncProjectsFromAudits($userId);

        if ($this->useFileStorage) {
            return $this->getProjectsFromFile($userId);
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT p.id, p.user_id, p.name, p.domain, p.created_at, p.updated_at,
                        COUNT(tk.id) AS tracked_count
                 FROM projects p
                 LEFT JOIN tracked_keywords tk ON tk.project_id = p.id
                 WHERE p.user_id = :user_id
                 GROUP BY p.id
                 ORDER BY p.created_at DESC'
            );

            $stmt->execute([':user_id' => $userId]);
            $rows = $stmt->fetchAll();

            return array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'user_id' => (int) ($row['user_id'] ?? 0),
                    'name' => (string) ($row['name'] ?? ''),
                    'domain' => (string) ($row['domain'] ?? ''),
                    'tracked_count' => (int) ($row['tracked_count'] ?? 0),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                ];
            }, $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getProjects');
            return $this->getProjectsFromFile($userId);
        }
    }

    public function getProjectById(int $userId, int $projectId): ?array
    {
        if ($projectId <= 0) {
            return null;
        }

        if ($this->useFileStorage) {
            foreach ($this->getProjectsFromFile($userId) as $project) {
                if ((int) ($project['id'] ?? 0) === $projectId) {
                    return $project;
                }
            }
            return null;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT p.id, p.user_id, p.name, p.domain, p.created_at, p.updated_at,
                        COUNT(tk.id) AS tracked_count
                 FROM projects p
                 LEFT JOIN tracked_keywords tk ON tk.project_id = p.id
                 WHERE p.id = :project_id
                   AND p.user_id = :user_id
                 GROUP BY p.id
                 LIMIT 1'
            );
            $stmt->execute([
                ':project_id' => $projectId,
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
                'tracked_count' => (int) ($row['tracked_count'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getProjectById');
            return $this->getProjectById($userId, $projectId);
        }
    }

    public function upsertProject(int $userId, string $domain, string $name = ''): ?array
    {
        $domain = SecurityValidator::sanitizeDomain($domain);
        if (!SecurityValidator::isValidDomain($domain)) {
            return null;
        }

        $name = trim($name);
        if ($name === '') {
            $name = $domain;
        }

        if ($this->useFileStorage) {
            $projects = $this->readJsonFile($this->projectsFile);
            $existingIndex = null;

            foreach ($projects as $index => $project) {
                if ((int) ($project['user_id'] ?? 0) === $userId && strtolower((string) ($project['domain'] ?? '')) === $domain) {
                    $existingIndex = $index;
                    break;
                }
            }

            $now = date('Y-m-d H:i:s');
            if ($existingIndex !== null) {
                $projects[$existingIndex]['name'] = $name;
                $projects[$existingIndex]['updated_at'] = $now;
                $this->writeJsonFile($this->projectsFile, $projects);
                return $projects[$existingIndex];
            }

            $maxProjects = $this->maxProjectsForUser($userId);
            $currentCount = 0;
            foreach ($projects as $project) {
                if ((int) ($project['user_id'] ?? 0) === $userId) {
                    $currentCount++;
                }
            }
            if ($currentCount >= $maxProjects) {
                return null;
            }

            $newProject = [
                'id' => $this->nextId($projects),
                'user_id' => $userId,
                'name' => $name,
                'domain' => $domain,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $projects[] = $newProject;
            $this->writeJsonFile($this->projectsFile, $projects);

            return $newProject;
        }

        try {
            $existingStmt = $this->conn->prepare(
                'SELECT id
                 FROM projects
                 WHERE user_id = :user_id
                   AND domain = :domain
                 LIMIT 1'
            );
            $existingStmt->execute([
                ':user_id' => $userId,
                ':domain' => $domain,
            ]);
            $existingProject = $existingStmt->fetch();
            if (!$existingProject) {
                $maxProjects = $this->maxProjectsForUser($userId);
                $countStmt = $this->conn->prepare('SELECT COUNT(*) AS total FROM projects WHERE user_id = :user_id');
                $countStmt->execute([':user_id' => $userId]);
                $countRow = $countStmt->fetch();
                $currentCount = (int) ($countRow['total'] ?? 0);
                if ($currentCount >= $maxProjects) {
                    return null;
                }
            }

            $now = date('Y-m-d H:i:s');
            $stmt = $this->conn->prepare(
                'INSERT INTO projects (user_id, name, domain, created_at, updated_at)
                 VALUES (:user_id, :name, :domain, :created_at, :updated_at)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), updated_at = VALUES(updated_at)'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':name' => $name,
                ':domain' => $domain,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            $lookupStmt = $this->conn->prepare(
                'SELECT id, user_id, name, domain, created_at, updated_at
                 FROM projects
                 WHERE user_id = :user_id
                   AND domain = :domain
                 LIMIT 1'
            );
            $lookupStmt->execute([
                ':user_id' => $userId,
                ':domain' => $domain,
            ]);
            $row = $lookupStmt->fetch();
            if (!$row) {
                return null;
            }

            return [
                'id' => (int) ($row['id'] ?? 0),
                'user_id' => (int) ($row['user_id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'domain' => (string) ($row['domain'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'upsertProject');
            return $this->upsertProject($userId, $domain, $name);
        }
    }

    public function getTrackedKeywords(int $userId, ?int $projectId = null, ?string $status = null): array
    {
        $status = $status !== null ? strtolower(trim($status)) : null;
        if ($status !== null && !in_array($status, ['active', 'paused'], true)) {
            $status = null;
        }

        if ($this->useFileStorage) {
            $tracked = $this->readJsonFile($this->trackedKeywordsFile);
            $projects = $this->getProjectsFromFile($userId);
            $projectMap = [];
            foreach ($projects as $project) {
                $projectMap[(int) ($project['id'] ?? 0)] = $project;
            }

            $rows = [];
            foreach ($tracked as $row) {
                $rowProjectId = (int) ($row['project_id'] ?? 0);
                if (!isset($projectMap[$rowProjectId])) {
                    continue;
                }
                if ($projectId !== null && $rowProjectId !== $projectId) {
                    continue;
                }
                if ($status !== null && strtolower((string) ($row['status'] ?? 'active')) !== $status) {
                    continue;
                }

                $project = $projectMap[$rowProjectId];
                $rows[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'project_id' => $rowProjectId,
                    'keyword' => (string) ($row['keyword'] ?? ''),
                    'country' => (string) ($row['country'] ?? 'US'),
                    'device_type' => (string) ($row['device_type'] ?? 'desktop'),
                    'status' => (string) ($row['status'] ?? 'active'),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                    'project_name' => (string) ($project['name'] ?? ''),
                    'project_domain' => (string) ($project['domain'] ?? ''),
                    'user_id' => $userId,
                ];
            }

            usort($rows, static function (array $a, array $b): int {
                return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
            });

            return $rows;
        }

        try {
            $sql = 'SELECT tk.id, tk.project_id, tk.keyword, tk.country, tk.device_type, tk.status, tk.created_at, tk.updated_at,
                           p.name AS project_name, p.domain AS project_domain, p.user_id
                    FROM tracked_keywords tk
                    INNER JOIN projects p ON p.id = tk.project_id
                    WHERE p.user_id = :user_id';

            $params = [':user_id' => $userId];

            if ($projectId !== null) {
                $sql .= ' AND tk.project_id = :project_id';
                $params[':project_id'] = $projectId;
            }

            if ($status !== null) {
                $sql .= ' AND tk.status = :status';
                $params[':status'] = $status;
            }

            $sql .= ' ORDER BY tk.created_at DESC';

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            return array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'project_id' => (int) ($row['project_id'] ?? 0),
                    'keyword' => (string) ($row['keyword'] ?? ''),
                    'country' => (string) ($row['country'] ?? 'US'),
                    'device_type' => (string) ($row['device_type'] ?? 'desktop'),
                    'status' => (string) ($row['status'] ?? 'active'),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                    'project_name' => (string) ($row['project_name'] ?? ''),
                    'project_domain' => (string) ($row['project_domain'] ?? ''),
                    'user_id' => (int) ($row['user_id'] ?? 0),
                ];
            }, $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getTrackedKeywords');
            return $this->getTrackedKeywords($userId, $projectId, $status);
        }
    }

    public function getTrackedKeywordById(int $userId, int $trackedKeywordId): ?array
    {
        if ($trackedKeywordId <= 0) {
            return null;
        }

        $rows = $this->getTrackedKeywords($userId);
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $trackedKeywordId) {
                return $row;
            }
        }

        return null;
    }

    public function countTrackedKeywordsForProject(int $userId, int $projectId): int
    {
        if ($projectId <= 0) {
            return 0;
        }

        if ($this->useFileStorage) {
            $count = 0;
            foreach ($this->getTrackedKeywords($userId, $projectId) as $row) {
                if (in_array((string) ($row['status'] ?? 'active'), ['active', 'paused'], true)) {
                    $count++;
                }
            }
            return $count;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT COUNT(*) AS total
                 FROM tracked_keywords tk
                 INNER JOIN projects p ON p.id = tk.project_id
                 WHERE p.user_id = :user_id
                   AND tk.project_id = :project_id'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':project_id' => $projectId,
            ]);
            $row = $stmt->fetch();
            return (int) ($row['total'] ?? 0);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'countTrackedKeywordsForProject');
            return $this->countTrackedKeywordsForProject($userId, $projectId);
        }
    }

    public function addTrackedKeyword(
        int $userId,
        int $projectId,
        string $keyword,
        string $country,
        string $deviceType,
        string $status = 'active'
    ): array {
        $project = $this->getProjectById($userId, $projectId);
        if (!$project) {
            return [
                'success' => false,
                'error_code' => 'PROJECT_NOT_FOUND',
                'error' => 'Project not found.',
            ];
        }

        $keywordNormalized = trim((string) preg_replace('/\s+/', ' ', $keyword));
        $keywordLower = strtolower($keywordNormalized);

        if ($keywordNormalized === '') {
            return [
                'success' => false,
                'error_code' => 'INVALID_KEYWORD',
                'error' => 'Keyword is required.',
            ];
        }

        if ($this->useFileStorage) {
            $tracked = $this->readJsonFile($this->trackedKeywordsFile);
            foreach ($tracked as $item) {
                if ((int) ($item['project_id'] ?? 0) !== $projectId) {
                    continue;
                }
                if (strtolower((string) ($item['keyword'] ?? '')) !== $keywordLower) {
                    continue;
                }
                if (strtoupper((string) ($item['country'] ?? 'US')) !== strtoupper($country)) {
                    continue;
                }
                if (strtolower((string) ($item['device_type'] ?? 'desktop')) !== strtolower($deviceType)) {
                    continue;
                }

                return [
                    'success' => false,
                    'error_code' => 'DUPLICATE_KEYWORD',
                    'error' => 'Keyword already tracked for this project, country, and device.',
                ];
            }

            $now = date('Y-m-d H:i:s');
            $newRow = [
                'id' => $this->nextId($tracked),
                'project_id' => $projectId,
                'keyword' => $keywordNormalized,
                'country' => strtoupper($country),
                'device_type' => strtolower($deviceType),
                'status' => strtolower($status) === 'paused' ? 'paused' : 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $tracked[] = $newRow;
            $this->writeJsonFile($this->trackedKeywordsFile, $tracked);

            $newRow['project_name'] = (string) ($project['name'] ?? '');
            $newRow['project_domain'] = (string) ($project['domain'] ?? '');
            $newRow['user_id'] = $userId;

            return [
                'success' => true,
                'keyword' => $newRow,
            ];
        }

        try {
            $dupStmt = $this->conn->prepare(
                'SELECT tk.id
                 FROM tracked_keywords tk
                 INNER JOIN projects p ON p.id = tk.project_id
                 WHERE p.user_id = :user_id
                   AND tk.project_id = :project_id
                   AND LOWER(tk.keyword) = :keyword
                   AND tk.country = :country
                   AND tk.device_type = :device_type
                 LIMIT 1'
            );
            $dupStmt->execute([
                ':user_id' => $userId,
                ':project_id' => $projectId,
                ':keyword' => $keywordLower,
                ':country' => strtoupper($country),
                ':device_type' => strtolower($deviceType),
            ]);

            if ($dupStmt->fetch()) {
                return [
                    'success' => false,
                    'error_code' => 'DUPLICATE_KEYWORD',
                    'error' => 'Keyword already tracked for this project, country, and device.',
                ];
            }

            $now = date('Y-m-d H:i:s');
            $insertStmt = $this->conn->prepare(
                'INSERT INTO tracked_keywords
                    (project_id, keyword, country, device_type, status, created_at, updated_at)
                 VALUES
                    (:project_id, :keyword, :country, :device_type, :status, :created_at, :updated_at)'
            );
            $insertStmt->execute([
                ':project_id' => $projectId,
                ':keyword' => $keywordNormalized,
                ':country' => strtoupper($country),
                ':device_type' => strtolower($deviceType),
                ':status' => strtolower($status) === 'paused' ? 'paused' : 'active',
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            $keywordId = (int) $this->conn->lastInsertId();
            $keywordRow = $this->getTrackedKeywordById($userId, $keywordId);

            return [
                'success' => true,
                'keyword' => $keywordRow,
            ];
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'addTrackedKeyword');
            return $this->addTrackedKeyword($userId, $projectId, $keyword, $country, $deviceType, $status);
        }
    }

    public function updateTrackedKeywordStatus(int $userId, int $trackedKeywordId, string $status): bool
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['active', 'paused'], true)) {
            return false;
        }

        if ($this->useFileStorage) {
            $tracked = $this->readJsonFile($this->trackedKeywordsFile);
            $projectMap = [];
            foreach ($this->getProjectsFromFile($userId) as $project) {
                $projectMap[(int) ($project['id'] ?? 0)] = true;
            }

            $updated = false;
            foreach ($tracked as $index => $item) {
                if ((int) ($item['id'] ?? 0) !== $trackedKeywordId) {
                    continue;
                }
                if (!isset($projectMap[(int) ($item['project_id'] ?? 0)])) {
                    continue;
                }

                $tracked[$index]['status'] = $status;
                $tracked[$index]['updated_at'] = date('Y-m-d H:i:s');
                $updated = true;
                break;
            }

            if ($updated) {
                $this->writeJsonFile($this->trackedKeywordsFile, $tracked);
            }
            return $updated;
        }

        try {
            $stmt = $this->conn->prepare(
                'UPDATE tracked_keywords tk
                 INNER JOIN projects p ON p.id = tk.project_id
                 SET tk.status = :status,
                     tk.updated_at = :updated_at
                 WHERE tk.id = :id
                   AND p.user_id = :user_id'
            );
            $stmt->execute([
                ':status' => $status,
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id' => $trackedKeywordId,
                ':user_id' => $userId,
            ]);

            return $stmt->rowCount() > 0;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'updateTrackedKeywordStatus');
            return $this->updateTrackedKeywordStatus($userId, $trackedKeywordId, $status);
        }
    }

    public function deleteTrackedKeyword(int $userId, int $trackedKeywordId): bool
    {
        if ($trackedKeywordId <= 0) {
            return false;
        }

        if ($this->useFileStorage) {
            $tracked = $this->readJsonFile($this->trackedKeywordsFile);
            $projectMap = [];
            foreach ($this->getProjectsFromFile($userId) as $project) {
                $projectMap[(int) ($project['id'] ?? 0)] = true;
            }

            $kept = [];
            $deleted = false;
            foreach ($tracked as $item) {
                $belongsToUser = isset($projectMap[(int) ($item['project_id'] ?? 0)]);
                $isTarget = (int) ($item['id'] ?? 0) === $trackedKeywordId;
                if ($belongsToUser && $isTarget) {
                    $deleted = true;
                    continue;
                }
                $kept[] = $item;
            }

            if ($deleted) {
                $this->writeJsonFile($this->trackedKeywordsFile, $kept);
            }

            return $deleted;
        }

        try {
            $stmt = $this->conn->prepare(
                'DELETE tk
                 FROM tracked_keywords tk
                 INNER JOIN projects p ON p.id = tk.project_id
                 WHERE tk.id = :id
                   AND p.user_id = :user_id'
            );
            $stmt->execute([
                ':id' => $trackedKeywordId,
                ':user_id' => $userId,
            ]);

            return $stmt->rowCount() > 0;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'deleteTrackedKeyword');
            return $this->deleteTrackedKeyword($userId, $trackedKeywordId);
        }
    }

    public function getUsersWithActiveTrackedKeywords(): array
    {
        if ($this->useFileStorage) {
            $tracked = $this->readJsonFile($this->trackedKeywordsFile);
            $projects = $this->readJsonFile($this->projectsFile);
            $projectUserMap = [];
            foreach ($projects as $project) {
                $projectUserMap[(int) ($project['id'] ?? 0)] = (int) ($project['user_id'] ?? 0);
            }

            $users = [];
            foreach ($tracked as $item) {
                if (strtolower((string) ($item['status'] ?? 'active')) !== 'active') {
                    continue;
                }
                $projectId = (int) ($item['project_id'] ?? 0);
                if (!isset($projectUserMap[$projectId])) {
                    continue;
                }
                $users[$projectUserMap[$projectId]] = true;
            }

            return array_map('intval', array_keys($users));
        }

        try {
            $stmt = $this->conn->query(
                'SELECT DISTINCT p.user_id
                 FROM tracked_keywords tk
                 INNER JOIN projects p ON p.id = tk.project_id
                 WHERE tk.status = "active"
                 ORDER BY p.user_id ASC'
            );
            $rows = $stmt->fetchAll();
            return array_map(static fn (array $row): int => (int) ($row['user_id'] ?? 0), $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getUsersWithActiveTrackedKeywords');
            return $this->getUsersWithActiveTrackedKeywords();
        }
    }

    public function getUserPlanType(int $userId): string
    {
        if ($this->useFileStorage) {
            $usersFile = __DIR__ . '/../storage/users.json';
            if (file_exists($usersFile)) {
                $users = json_decode((string) file_get_contents($usersFile), true);
                if (is_array($users)) {
                    foreach ($users as $user) {
                        if ((int) ($user['id'] ?? 0) === $userId) {
                            $plan = strtolower((string) ($user['plan_type'] ?? 'free'));
                            if (in_array($plan, ['free', 'pro', 'agency'], true)) {
                                return $plan;
                            }
                        }
                    }
                }
            }
            return 'free';
        }

        try {
            $stmt = $this->conn->prepare('SELECT plan_type FROM users WHERE id = :user_id LIMIT 1');
            $stmt->execute([':user_id' => $userId]);
            $row = $stmt->fetch();
            $plan = strtolower((string) ($row['plan_type'] ?? 'free'));
            if (!in_array($plan, ['free', 'pro', 'agency'], true)) {
                return 'free';
            }
            return $plan;
        } catch (Throwable $error) {
            return 'free';
        }
    }

    public function getUserRole(int $userId): string
    {
        if ($this->useFileStorage) {
            $usersFile = __DIR__ . '/../storage/users.json';
            if (file_exists($usersFile)) {
                $users = json_decode((string) file_get_contents($usersFile), true);
                if (is_array($users)) {
                    foreach ($users as $user) {
                        if ((int) ($user['id'] ?? 0) === $userId) {
                            $role = strtolower((string) ($user['role'] ?? 'user'));
                            if (in_array($role, ['user', 'agency', 'admin', 'super_admin', 'support_admin', 'billing_admin'], true)) {
                                return $role;
                            }
                        }
                    }
                }
            }
            return 'user';
        }

        try {
            $stmt = $this->conn->prepare('SELECT role FROM users WHERE id = :user_id LIMIT 1');
            $stmt->execute([':user_id' => $userId]);
            $row = $stmt->fetch();
            $role = strtolower((string) ($row['role'] ?? 'user'));
            if (!in_array($role, ['user', 'agency', 'admin', 'super_admin', 'support_admin', 'billing_admin'], true)) {
                return 'user';
            }
            return $role;
        } catch (Throwable $error) {
            return 'user';
        }
    }

    public function countRecentRequests(int $userId, string $module = 'rank_tracker', int $windowSeconds = 60): int
    {
        $cutoff = time() - max(1, $windowSeconds);

        if ($this->useFileStorage) {
            $logs = $this->readJsonFile($this->logFile);
            $count = 0;
            foreach ($logs as $log) {
                if ((int) ($log['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if ((string) ($log['module'] ?? '') !== $module) {
                    continue;
                }
                $createdAt = strtotime((string) ($log['created_at'] ?? ''));
                if ($createdAt !== false && $createdAt >= $cutoff) {
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
                ':cutoff' => date('Y-m-d H:i:s', $cutoff),
            ]);
            $row = $stmt->fetch();
            return (int) ($row['total'] ?? 0);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'countRecentRequests');
            return $this->countRecentRequests($userId, $module, $windowSeconds);
        }
    }

    public function logRequest(int $userId, string $module, string $requestKey, string $source, int $statusCode): void
    {
        $module = trim($module);
        if ($module === '') {
            $module = 'rank_tracker';
        }

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

            if (count($logs) > 6000) {
                $logs = array_slice($logs, -6000);
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
            $this->switchToFileStorage($error, 'logRequest');
            $this->logRequest($userId, $module, $requestKey, $source, $statusCode);
        }
    }

    private function getProjectsFromFile(int $userId): array
    {
        $projects = $this->readJsonFile($this->projectsFile);
        $tracked = $this->readJsonFile($this->trackedKeywordsFile);
        $counts = [];

        foreach ($tracked as $row) {
            $projectId = (int) ($row['project_id'] ?? 0);
            if ($projectId <= 0) {
                continue;
            }
            $counts[$projectId] = (int) ($counts[$projectId] ?? 0) + 1;
        }

        $result = [];
        foreach ($projects as $project) {
            if ((int) ($project['user_id'] ?? 0) !== $userId) {
                continue;
            }
            $projectId = (int) ($project['id'] ?? 0);
            $project['tracked_count'] = (int) ($counts[$projectId] ?? 0);
            $result[] = $project;
        }

        usort($result, static function (array $a, array $b): int {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        return $result;
    }

    private function maxProjectsForUser(int $userId): int
    {
        $role = $this->getUserRole($userId);
        if (in_array($role, ['super_admin', 'admin'], true)) {
            return 1000000;
        }

        $plan = $this->getUserPlanType($userId);
        return match ($plan) {
            'agency' => 1000000,
            'pro' => 5,
            default => 1,
        };
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
        error_log('TrackedKeywordModel fallback (' . $context . '): ' . $error->getMessage());
        $this->useFileStorage = true;
    }
}
