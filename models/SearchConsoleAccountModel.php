<?php

require_once __DIR__ . '/../config/database.php';

class SearchConsoleAccountModel
{
    private ?PDO $conn;
    private bool $useFileStorage = false;
    private string $accountsFile;
    private string $usersFile;

    public function __construct()
    {
        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $this->accountsFile = $storageDir . '/search_console_accounts.json';
        $this->usersFile = $storageDir . '/users.json';

        if (!file_exists($this->accountsFile)) {
            file_put_contents($this->accountsFile, json_encode([]));
        }
        if (!file_exists($this->usersFile)) {
            file_put_contents($this->usersFile, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function getByProject(int $userId, int $projectId): ?array
    {
        if ($userId <= 0 || $projectId <= 0) {
            return null;
        }

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->accountsFile);
            foreach ($rows as $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if ((int) ($row['project_id'] ?? 0) !== $projectId) {
                    continue;
                }
                return $this->normalizeRow($row);
            }
            return null;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT id, user_id, project_id, google_property, access_token, refresh_token, token_expiry, created_at, updated_at
                 FROM search_console_accounts
                 WHERE user_id = :user_id
                   AND project_id = :project_id
                 LIMIT 1'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':project_id' => $projectId,
            ]);
            $row = $stmt->fetch();
            return $row ? $this->normalizeRow($row) : null;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getByProject');
            return $this->getByProject($userId, $projectId);
        }
    }

    public function getConnectionsByUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->accountsFile);
            $result = [];
            foreach ($rows as $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                $result[] = $this->normalizeRow($row);
            }
            usort($result, static function (array $a, array $b): int {
                return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
            });
            return $result;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT id, user_id, project_id, google_property, access_token, refresh_token, token_expiry, created_at, updated_at
                 FROM search_console_accounts
                 WHERE user_id = :user_id
                 ORDER BY updated_at DESC'
            );
            $stmt->execute([':user_id' => $userId]);
            $rows = $stmt->fetchAll();
            return array_map(fn (array $row): array => $this->normalizeRow($row), $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getConnectionsByUser');
            return $this->getConnectionsByUser($userId);
        }
    }

    public function countConnectionsByUser(int $userId): int
    {
        return count($this->getConnectionsByUser($userId));
    }

    public function saveConnection(
        int $userId,
        int $projectId,
        string $googleProperty,
        string $encryptedAccessToken,
        string $encryptedRefreshToken,
        string $tokenExpiry
    ): array {
        if ($userId <= 0 || $projectId <= 0) {
            return ['success' => false, 'error' => 'Invalid user or project.'];
        }

        $googleProperty = trim($googleProperty);
        if ($googleProperty === '') {
            return ['success' => false, 'error' => 'Google property is required.'];
        }

        $now = date('Y-m-d H:i:s');
        $tokenExpiry = $this->normalizeDateTime($tokenExpiry, date('Y-m-d H:i:s', strtotime('+45 minutes')));
        $existing = $this->getByProject($userId, $projectId);

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->accountsFile);
            if ($existing) {
                foreach ($rows as $index => $row) {
                    if ((int) ($row['id'] ?? 0) !== (int) $existing['id']) {
                        continue;
                    }
                    $rows[$index]['google_property'] = $googleProperty;
                    $rows[$index]['access_token'] = $encryptedAccessToken;
                    $rows[$index]['refresh_token'] = $encryptedRefreshToken !== '' ? $encryptedRefreshToken : (string) ($rows[$index]['refresh_token'] ?? '');
                    $rows[$index]['token_expiry'] = $tokenExpiry;
                    $rows[$index]['updated_at'] = $now;
                    $this->writeJsonFile($this->accountsFile, $rows);
                    return ['success' => true, 'account' => $this->normalizeRow($rows[$index])];
                }
            }

            $newRow = [
                'id' => $this->nextId($rows),
                'user_id' => $userId,
                'project_id' => $projectId,
                'google_property' => $googleProperty,
                'access_token' => $encryptedAccessToken,
                'refresh_token' => $encryptedRefreshToken,
                'token_expiry' => $tokenExpiry,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $rows[] = $newRow;
            $this->writeJsonFile($this->accountsFile, $rows);
            return ['success' => true, 'account' => $this->normalizeRow($newRow)];
        }

        try {
            if ($existing) {
                $stmt = $this->conn->prepare(
                    'UPDATE search_console_accounts
                     SET google_property = :google_property,
                         access_token = :access_token,
                         refresh_token = :refresh_token,
                         token_expiry = :token_expiry,
                         updated_at = :updated_at
                     WHERE id = :id
                       AND user_id = :user_id'
                );
                $stmt->execute([
                    ':google_property' => $googleProperty,
                    ':access_token' => $encryptedAccessToken,
                    ':refresh_token' => $encryptedRefreshToken !== '' ? $encryptedRefreshToken : (string) ($existing['refresh_token'] ?? ''),
                    ':token_expiry' => $tokenExpiry,
                    ':updated_at' => $now,
                    ':id' => (int) ($existing['id'] ?? 0),
                    ':user_id' => $userId,
                ]);
            } else {
                $stmt = $this->conn->prepare(
                    'INSERT INTO search_console_accounts
                        (user_id, project_id, google_property, access_token, refresh_token, token_expiry, created_at, updated_at)
                     VALUES
                        (:user_id, :project_id, :google_property, :access_token, :refresh_token, :token_expiry, :created_at, :updated_at)'
                );
                $stmt->execute([
                    ':user_id' => $userId,
                    ':project_id' => $projectId,
                    ':google_property' => $googleProperty,
                    ':access_token' => $encryptedAccessToken,
                    ':refresh_token' => $encryptedRefreshToken,
                    ':token_expiry' => $tokenExpiry,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            }

            $latest = $this->getByProject($userId, $projectId);
            return ['success' => true, 'account' => $latest];
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'saveConnection');
            return $this->saveConnection($userId, $projectId, $googleProperty, $encryptedAccessToken, $encryptedRefreshToken, $tokenExpiry);
        }
    }

    public function updateTokensById(int $id, string $accessTokenEncrypted, ?string $refreshTokenEncrypted, string $tokenExpiry): bool
    {
        if ($id <= 0 || $accessTokenEncrypted === '') {
            return false;
        }
        $tokenExpiry = $this->normalizeDateTime($tokenExpiry, date('Y-m-d H:i:s', strtotime('+45 minutes')));
        $now = date('Y-m-d H:i:s');

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->accountsFile);
            foreach ($rows as $index => $row) {
                if ((int) ($row['id'] ?? 0) !== $id) {
                    continue;
                }
                $rows[$index]['access_token'] = $accessTokenEncrypted;
                if ($refreshTokenEncrypted !== null && $refreshTokenEncrypted !== '') {
                    $rows[$index]['refresh_token'] = $refreshTokenEncrypted;
                }
                $rows[$index]['token_expiry'] = $tokenExpiry;
                $rows[$index]['updated_at'] = $now;
                $this->writeJsonFile($this->accountsFile, $rows);
                return true;
            }
            return false;
        }

        try {
            $refreshTokenSql = '';
            $params = [
                ':id' => $id,
                ':access_token' => $accessTokenEncrypted,
                ':token_expiry' => $tokenExpiry,
                ':updated_at' => $now,
            ];
            if ($refreshTokenEncrypted !== null && $refreshTokenEncrypted !== '') {
                $refreshTokenSql = ', refresh_token = :refresh_token';
                $params[':refresh_token'] = $refreshTokenEncrypted;
            }

            $stmt = $this->conn->prepare(
                'UPDATE search_console_accounts
                 SET access_token = :access_token,
                     token_expiry = :token_expiry,
                     updated_at = :updated_at'
                . $refreshTokenSql .
                ' WHERE id = :id'
            );
            $stmt->execute($params);
            return $stmt->rowCount() > 0;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'updateTokensById');
            return $this->updateTokensById($id, $accessTokenEncrypted, $refreshTokenEncrypted, $tokenExpiry);
        }
    }

    public function deleteByProject(int $userId, int $projectId): bool
    {
        if ($userId <= 0 || $projectId <= 0) {
            return false;
        }

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->accountsFile);
            $before = count($rows);
            $rows = array_values(array_filter($rows, static function (array $row) use ($userId, $projectId): bool {
                return !((int) ($row['user_id'] ?? 0) === $userId && (int) ($row['project_id'] ?? 0) === $projectId);
            }));
            if (count($rows) !== $before) {
                $this->writeJsonFile($this->accountsFile, $rows);
                return true;
            }
            return false;
        }

        try {
            $stmt = $this->conn->prepare(
                'DELETE FROM search_console_accounts
                 WHERE user_id = :user_id
                   AND project_id = :project_id'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':project_id' => $projectId,
            ]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'deleteByProject');
            return $this->deleteByProject($userId, $projectId);
        }
    }

    public function getAllConnections(int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->accountsFile);
            $rows = array_values(array_map(fn (array $row): array => $this->normalizeRow($row), $rows));
            $userPlans = $this->userPlanMapFromFile();
            foreach ($rows as $index => $row) {
                $userId = (int) ($row['user_id'] ?? 0);
                $rows[$index]['plan_type'] = $userPlans[$userId] ?? 'free';
            }
            usort($rows, static function (array $a, array $b): int {
                return strcmp((string) ($a['updated_at'] ?? ''), (string) ($b['updated_at'] ?? ''));
            });
            return array_slice($rows, 0, $limit);
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT a.id, a.user_id, a.project_id, a.google_property, a.access_token, a.refresh_token, a.token_expiry, a.created_at, a.updated_at,
                        COALESCE(u.plan_type, "free") AS plan_type
                 FROM search_console_accounts a
                 LEFT JOIN users u ON u.id = a.user_id
                 ORDER BY a.updated_at ASC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return array_map(function (array $row): array {
                $normalized = $this->normalizeRow($row);
                $normalized['plan_type'] = $this->normalizePlan((string) ($row['plan_type'] ?? 'free'));
                return $normalized;
            }, $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getAllConnections');
            return $this->getAllConnections($limit);
        }
    }

    private function userPlanMapFromFile(): array
    {
        $rows = $this->readJsonFile($this->usersFile);
        $map = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $map[$userId] = $this->normalizePlan((string) ($row['plan_type'] ?? 'free'));
        }
        return $map;
    }

    private function normalizePlan(string $plan): string
    {
        $plan = strtolower(trim($plan));
        if (!in_array($plan, ['free', 'pro', 'agency'], true)) {
            return 'free';
        }
        return $plan;
    }

    private function normalizeDateTime(string $dateTime, string $fallback): string
    {
        $dateTime = trim($dateTime);
        if ($dateTime === '') {
            return $fallback;
        }
        $ts = strtotime($dateTime);
        if ($ts === false) {
            return $fallback;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'project_id' => (int) ($row['project_id'] ?? 0),
            'google_property' => (string) ($row['google_property'] ?? ''),
            'access_token' => (string) ($row['access_token'] ?? ''),
            'refresh_token' => (string) ($row['refresh_token'] ?? ''),
            'token_expiry' => (string) ($row['token_expiry'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
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

    private function writeJsonFile(string $path, array $rows): void
    {
        file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function switchToFileStorage(Throwable $error, string $context): void
    {
        error_log('SearchConsoleAccountModel fallback (' . $context . '): ' . $error->getMessage());
        $this->useFileStorage = true;
    }
}

