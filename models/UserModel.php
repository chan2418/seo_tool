<?php

require_once __DIR__ . '/../config/database.php';

class UserModel
{
    private const VALID_ROLES = [
        'user',
        'agency',
        'admin',
        'super_admin',
        'support_admin',
        'billing_admin',
    ];

    private Database $db;
    private ?PDO $conn;
    private bool $useFileStorage = false;
    private string $storageFile;

    public function __construct()
    {
        $this->db = new Database();
        $connection = $this->db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }
        $this->storageFile = $storageDir . '/users.json';
        if (!file_exists($this->storageFile)) {
            file_put_contents($this->storageFile, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function createUser(string $name, string $email, string $password): int|false
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || $email === '' || $password === '') {
            return false;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $now = date('Y-m-d H:i:s');

        if ($this->useFileStorage) {
            $users = $this->readUsers();
            foreach ($users as $user) {
                if (strtolower((string) ($user['email'] ?? '')) === $email) {
                    return false;
                }
            }

            $newUser = [
                'id' => $this->nextId($users),
                'name' => $name,
                'email' => $email,
                'password' => $hash,
                'plan_type' => 'free',
                'auth_provider' => 'local',
                'google_id' => null,
                'google_avatar' => null,
                'role' => 'user',
                'status' => 'active',
                'suspended_reason' => null,
                'created_at' => $now,
                'last_login_at' => null,
            ];
            $users[] = $newUser;
            $this->writeUsers($users);
            return (int) $newUser['id'];
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO users (name, email, password, plan_type, role, status, created_at)
                 VALUES (:name, :email, :password, :plan_type, :role, :status, :created_at)'
            );
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':password' => $hash,
                ':plan_type' => 'free',
                ':role' => 'user',
                ':status' => 'active',
                ':created_at' => $now,
            ]);
            return (int) $this->conn->lastInsertId();
        } catch (Throwable $error) {
            return false;
        }
    }

    public function upsertGoogleUser(string $name, string $email, string $googleId, ?string $avatar = null): array
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        $googleId = trim($googleId);
        $avatar = $avatar !== null ? trim($avatar) : null;

        if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid Google profile data.'];
        }

        $existing = $this->getUserByEmail($email);
        if ($existing) {
            $this->syncGoogleIdentity((int) ($existing['id'] ?? 0), $googleId, $avatar);
            $fresh = $this->getUserById((int) ($existing['id'] ?? 0));
            return [
                'success' => true,
                'created' => false,
                'user' => $fresh ?: $existing,
            ];
        }

        try {
            $randomPassword = bin2hex(random_bytes(16)) . 'Aa1!';
        } catch (Throwable $error) {
            $randomPassword = sha1($email . microtime(true)) . 'Aa1!';
        }
        $newUserId = $this->createUser($name, $email, $randomPassword);
        if (!$newUserId) {
            $retry = $this->getUserByEmail($email);
            if ($retry) {
                $this->syncGoogleIdentity((int) ($retry['id'] ?? 0), $googleId, $avatar);
                return [
                    'success' => true,
                    'created' => false,
                    'user' => $retry,
                ];
            }
            return ['success' => false, 'error' => 'Unable to create account from Google profile.'];
        }

        $this->syncGoogleIdentity((int) $newUserId, $googleId, $avatar);
        $createdUser = $this->getUserById((int) $newUserId);

        return [
            'success' => true,
            'created' => true,
            'user' => $createdUser ?: [
                'id' => (int) $newUserId,
                'name' => $name,
                'email' => $email,
                'plan_type' => 'free',
                'role' => 'user',
                'status' => 'active',
            ],
        ];
    }

    public function getUserByEmail(string $email): array|false
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        if ($this->useFileStorage) {
            foreach ($this->readUsers() as $user) {
                if (strtolower((string) ($user['email'] ?? '')) === $email) {
                    $normalized = $this->normalizeUserRow($user);
                    if (!empty($normalized['is_deleted'])) {
                        return false;
                    }
                    return $normalized;
                }
            }
            return false;
        }

        try {
            $stmt = $this->conn->prepare('SELECT * FROM users WHERE email = :email AND COALESCE(is_deleted,0) = 0 LIMIT 1');
            $stmt->execute([':email' => $email]);
            $row = $stmt->fetch();
            return $row ? $this->normalizeUserRow($row) : false;
        } catch (Throwable $error) {
            return false;
        }
    }

    public function getUserById(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        if ($this->useFileStorage) {
            foreach ($this->readUsers() as $user) {
                if ((int) ($user['id'] ?? 0) === $userId) {
                    $normalized = $this->normalizeUserRow($user);
                    if (!empty($normalized['is_deleted'])) {
                        return null;
                    }
                    return $normalized;
                }
            }
            return null;
        }

        try {
            $stmt = $this->conn->prepare('SELECT * FROM users WHERE id = :id AND COALESCE(is_deleted,0) = 0 LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch();
            return $row ? $this->normalizeUserRow($row) : null;
        } catch (Throwable $error) {
            return null;
        }
    }

    public function listUsers(int $limit = 50, int $offset = 0, string $search = ''): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $search = trim($search);

        if ($this->useFileStorage) {
            $users = $this->readUsers();
            $normalized = array_filter(array_map(fn (array $row): array => $this->normalizeUserRow($row), $users), static fn (array $row): bool => empty($row['is_deleted']));
            $normalized = array_values($normalized);
            if ($search !== '') {
                $needle = strtolower($search);
                $normalized = array_values(array_filter($normalized, static function (array $user) use ($needle): bool {
                    $name = strtolower((string) ($user['name'] ?? ''));
                    $email = strtolower((string) ($user['email'] ?? ''));
                    return str_contains($name, $needle) || str_contains($email, $needle);
                }));
            }
            usort($normalized, static function (array $a, array $b): int {
                return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
            });
            return array_slice($normalized, $offset, $limit);
        }

        try {
            $params = [];
            $whereSql = ' WHERE COALESCE(is_deleted,0) = 0';
            if ($search !== '') {
                $whereSql .= ' AND (name LIKE :search OR email LIKE :search)';
                $params[':search'] = '%' . $search . '%';
            }

            $sql = 'SELECT * FROM users' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
            $stmt = $this->conn->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return array_map(fn (array $row): array => $this->normalizeUserRow($row), $rows ?: []);
        } catch (Throwable $error) {
            return [];
        }
    }

    public function countUsers(string $search = ''): int
    {
        $search = trim($search);
        if ($this->useFileStorage) {
            if ($search === '') {
                $count = 0;
                foreach ($this->readUsers() as $user) {
                    $normalized = $this->normalizeUserRow($user);
                    if (empty($normalized['is_deleted'])) {
                        $count++;
                    }
                }
                return $count;
            }
            $needle = strtolower($search);
            $count = 0;
            foreach ($this->readUsers() as $user) {
                $normalized = $this->normalizeUserRow($user);
                if (!empty($normalized['is_deleted'])) {
                    continue;
                }
                $name = strtolower((string) ($user['name'] ?? ''));
                $email = strtolower((string) ($user['email'] ?? ''));
                if (str_contains($name, $needle) || str_contains($email, $needle)) {
                    $count++;
                }
            }
            return $count;
        }

        try {
            if ($search === '') {
                $stmt = $this->conn->query('SELECT COUNT(*) AS total FROM users WHERE COALESCE(is_deleted,0) = 0');
            } else {
                $stmt = $this->conn->prepare('SELECT COUNT(*) AS total FROM users WHERE COALESCE(is_deleted,0) = 0 AND (name LIKE :search OR email LIKE :search)');
                $stmt->execute([':search' => '%' . $search . '%']);
            }
            $row = $stmt->fetch();
            return (int) ($row['total'] ?? 0);
        } catch (Throwable $error) {
            return 0;
        }
    }

    public function updatePlanType(int $userId, string $planType): bool
    {
        $planType = strtolower(trim($planType));
        if (!in_array($planType, ['free', 'pro', 'agency'], true) || $userId <= 0) {
            return false;
        }

        if ($this->useFileStorage) {
            $users = $this->readUsers();
            foreach ($users as $idx => $user) {
                if ((int) ($user['id'] ?? 0) !== $userId) {
                    continue;
                }
                $users[$idx]['plan_type'] = $planType;
                $this->writeUsers($users);
                return true;
            }
            return false;
        }

        try {
            $stmt = $this->conn->prepare('UPDATE users SET plan_type = :plan_type WHERE id = :id');
            $stmt->execute([':plan_type' => $planType, ':id' => $userId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $error) {
            return false;
        }
    }

    public function updateRole(int $userId, string $role): bool
    {
        $role = strtolower(trim($role));
        if (!in_array($role, self::VALID_ROLES, true) || $userId <= 0) {
            return false;
        }

        if ($this->useFileStorage) {
            $users = $this->readUsers();
            foreach ($users as $idx => $user) {
                if ((int) ($user['id'] ?? 0) !== $userId) {
                    continue;
                }
                $users[$idx]['role'] = $role;
                $this->writeUsers($users);
                return true;
            }
            return false;
        }

        try {
            $stmt = $this->conn->prepare('UPDATE users SET role = :role WHERE id = :id');
            $stmt->execute([':role' => $role, ':id' => $userId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $error) {
            return false;
        }
    }

    public function updateStatus(int $userId, string $status, ?string $reason = null): bool
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['active', 'suspended'], true) || $userId <= 0) {
            return false;
        }
        $reason = $reason !== null ? trim($reason) : null;
        if ($reason === '') {
            $reason = null;
        }

        if ($this->useFileStorage) {
            $users = $this->readUsers();
            foreach ($users as $idx => $user) {
                if ((int) ($user['id'] ?? 0) !== $userId) {
                    continue;
                }
                $users[$idx]['status'] = $status;
                $users[$idx]['suspended_reason'] = $status === 'suspended' ? $reason : null;
                $this->writeUsers($users);
                return true;
            }
            return false;
        }

        try {
            $stmt = $this->conn->prepare(
                'UPDATE users
                 SET status = :status,
                     suspended_reason = :suspended_reason
                 WHERE id = :id'
            );
            $stmt->bindValue(':status', $status);
            $stmt->bindValue(':suspended_reason', $status === 'suspended' ? $reason : null, $status === 'suspended' && $reason !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (Throwable $error) {
            return false;
        }
    }

    public function updateLastLogin(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            $ip = null;
        } else {
            $ip = mb_substr($ip, 0, 45);
        }
        if ($this->useFileStorage) {
            $users = $this->readUsers();
            foreach ($users as $idx => $user) {
                if ((int) ($user['id'] ?? 0) !== $userId) {
                    continue;
                }
                $users[$idx]['last_login_at'] = $now;
                $users[$idx]['last_login_ip'] = $ip;
                $this->writeUsers($users);
                return;
            }
            return;
        }

        try {
            $stmt = $this->conn->prepare('UPDATE users SET last_login_at = :last_login_at, last_login_ip = :last_login_ip WHERE id = :id');
            $stmt->execute([
                ':last_login_at' => $now,
                ':last_login_ip' => $ip,
                ':id' => $userId,
            ]);
        } catch (Throwable $error) {
        }
    }

    public function changePassword(int $userId, string $newPassword, bool $clearForceReset = true): bool
    {
        if ($userId <= 0 || trim($newPassword) === '' || strlen($newPassword) < 6) {
            return false;
        }
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);

        if ($this->useFileStorage) {
            $users = $this->readUsers();
            foreach ($users as $idx => $user) {
                if ((int) ($user['id'] ?? 0) !== $userId) {
                    continue;
                }
                $users[$idx]['password'] = $hash;
                if ($clearForceReset) {
                    $users[$idx]['force_password_reset'] = 0;
                }
                $this->writeUsers($users);
                return true;
            }
            return false;
        }

        try {
            $stmt = $this->conn->prepare(
                'UPDATE users
                 SET password = :password,
                     force_password_reset = :force_password_reset,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->bindValue(':password', $hash);
            $stmt->bindValue(':force_password_reset', $clearForceReset ? 0 : 1, PDO::PARAM_INT);
            $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (Throwable $error) {
            return false;
        }
    }

    private function syncGoogleIdentity(int $userId, string $googleId, ?string $avatar = null): void
    {
        if ($userId <= 0) {
            return;
        }

        $googleId = mb_substr(trim($googleId), 0, 191);
        $avatar = $avatar !== null ? mb_substr(trim($avatar), 0, 2048) : null;

        if ($this->useFileStorage) {
            $users = $this->readUsers();
            foreach ($users as $idx => $user) {
                if ((int) ($user['id'] ?? 0) !== $userId) {
                    continue;
                }
                $users[$idx]['auth_provider'] = 'google';
                if ($googleId !== '' && empty($users[$idx]['google_id'])) {
                    $users[$idx]['google_id'] = $googleId;
                }
                if ($avatar !== null && $avatar !== '') {
                    $users[$idx]['google_avatar'] = $avatar;
                }
                $this->writeUsers($users);
                return;
            }
            return;
        }

        try {
            $stmt = $this->conn->prepare(
                'UPDATE users
                 SET auth_provider = :auth_provider,
                     google_id = CASE
                        WHEN COALESCE(google_id, "") = "" AND :google_id <> "" THEN :google_id
                        ELSE google_id
                     END,
                     google_avatar = CASE
                        WHEN :google_avatar IS NOT NULL AND :google_avatar <> "" THEN :google_avatar
                        ELSE google_avatar
                     END,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':auth_provider' => 'google',
                ':google_id' => $googleId,
                ':google_avatar' => $avatar,
                ':id' => $userId,
            ]);
        } catch (Throwable $error) {
            // Keep sign-in flow functional even if optional Google identity columns are not migrated yet.
        }
    }

    private function normalizeUserRow(array $row): array
    {
        $plan = strtolower((string) ($row['plan_type'] ?? 'free'));
        if (!in_array($plan, ['free', 'pro', 'agency'], true)) {
            $plan = 'free';
        }

        $role = strtolower((string) ($row['role'] ?? 'user'));
        if (!in_array($role, self::VALID_ROLES, true)) {
            $role = 'user';
        }

        $status = strtolower((string) ($row['status'] ?? 'active'));
        if (!in_array($status, ['active', 'suspended'], true)) {
            $status = 'active';
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'password' => (string) ($row['password'] ?? ''),
            'plan_type' => $plan,
            'auth_provider' => (string) ($row['auth_provider'] ?? 'local'),
            'google_id' => isset($row['google_id']) ? (string) $row['google_id'] : null,
            'google_avatar' => isset($row['google_avatar']) ? (string) $row['google_avatar'] : null,
            'role' => $role,
            'status' => $status,
            'suspended_reason' => isset($row['suspended_reason']) ? (string) $row['suspended_reason'] : null,
            'last_login_ip' => isset($row['last_login_ip']) ? (string) $row['last_login_ip'] : null,
            'force_password_reset' => !empty($row['force_password_reset']),
            'force_logout_after' => isset($row['force_logout_after']) ? (string) $row['force_logout_after'] : null,
            'is_deleted' => !empty($row['is_deleted']),
            'deleted_at' => isset($row['deleted_at']) ? (string) $row['deleted_at'] : null,
            'deleted_reason' => isset($row['deleted_reason']) ? (string) $row['deleted_reason'] : null,
            'blocked_at' => isset($row['blocked_at']) ? (string) $row['blocked_at'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'last_login_at' => isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
        ];
    }

    private function readUsers(): array
    {
        $raw = file_get_contents($this->storageFile);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeUsers(array $users): void
    {
        file_put_contents($this->storageFile, json_encode($users, JSON_PRETTY_PRINT), LOCK_EX);
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
}
