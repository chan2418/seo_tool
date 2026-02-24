<?php

require_once __DIR__ . '/../config/database.php';

class AdminControlModel
{
    private ?PDO $conn;

    public function __construct()
    {
        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;
    }

    public function hasConnection(): bool
    {
        return $this->conn instanceof PDO;
    }

    public function getDashboardMetrics(): array
    {
        if (!$this->hasConnection()) {
            return [
                'total_users' => 0,
                'active_subscriptions' => 0,
                'mrr' => 0.0,
                'new_signups_today' => 0,
                'new_signups_week' => 0,
                'new_signups_month' => 0,
                'churn_rate' => 0.0,
                'arpu' => 0.0,
                'revenue_by_plan' => ['free' => 0.0, 'pro' => 0.0, 'agency' => 0.0, 'total' => 0.0],
                'plan_distribution' => ['free' => 0, 'pro' => 0, 'agency' => 0],
                'api_usage' => ['total' => 0, 'rank_api' => 0, 'gsc_api' => 0],
                'system_errors_24h' => 0,
                'connected_gsc_accounts' => 0,
                'server_load' => [],
            ];
        }

        $pdo = $this->conn;

        $totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE COALESCE(is_deleted, 0) = 0')->fetchColumn();
        $activeSubscriptions = (int) $pdo->query('SELECT COUNT(*) FROM subscriptions WHERE status IN ("active","trialing") OR COALESCE(lifetime_access,0)=1')->fetchColumn();

        $todayStart = date('Y-m-d 00:00:00');
        $weekStart = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $monthStart = date('Y-m-01 00:00:00');

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE created_at >= :start AND COALESCE(is_deleted,0)=0');
        $stmt->execute([':start' => $todayStart]);
        $newToday = (int) $stmt->fetchColumn();

        $stmt->execute([':start' => $weekStart]);
        $newWeek = (int) $stmt->fetchColumn();

        $stmt->execute([':start' => $monthStart]);
        $newMonth = (int) $stmt->fetchColumn();

        $canceledThisMonthStmt = $pdo->prepare('SELECT COUNT(*) FROM subscriptions WHERE status = "canceled" AND updated_at >= :start');
        $canceledThisMonthStmt->execute([':start' => $monthStart]);
        $canceledThisMonth = (int) $canceledThisMonthStmt->fetchColumn();

        $planDistribution = ['free' => 0, 'pro' => 0, 'agency' => 0];
        foreach ($pdo->query('SELECT plan_type, COUNT(*) AS total FROM users WHERE COALESCE(is_deleted,0)=0 GROUP BY plan_type')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $plan = strtolower((string) ($row['plan_type'] ?? 'free'));
            if (isset($planDistribution[$plan])) {
                $planDistribution[$plan] = (int) ($row['total'] ?? 0);
            }
        }

        $planPrices = $this->getPlanPriceMap();
        $revenueByPlan = ['free' => 0.0, 'pro' => 0.0, 'agency' => 0.0, 'total' => 0.0];
        foreach ($pdo->query('SELECT plan_type, COUNT(*) AS total FROM subscriptions WHERE status IN ("active","trialing") OR COALESCE(lifetime_access,0)=1 GROUP BY plan_type')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $plan = strtolower((string) ($row['plan_type'] ?? 'free'));
            if (!isset($revenueByPlan[$plan])) {
                continue;
            }
            $count = (int) ($row['total'] ?? 0);
            $revenueByPlan[$plan] = round($count * (float) ($planPrices[$plan] ?? 0.0), 2);
        }
        $revenueByPlan['total'] = round($revenueByPlan['free'] + $revenueByPlan['pro'] + $revenueByPlan['agency'], 2);

        $apiUsage = [
            'total' => (int) $this->singleInt('SELECT COALESCE(SUM(units),0) FROM api_usage_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)'),
            'rank_api' => (int) $this->singleInt('SELECT COALESCE(SUM(units),0) FROM api_usage_logs WHERE provider IN ("serpapi","dataforseo","rank_api") AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)'),
            'gsc_api' => (int) $this->singleInt('SELECT COALESCE(SUM(units),0) FROM api_usage_logs WHERE provider IN ("gsc","google_search_console") AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)'),
        ];

        $systemErrors24h = (int) $this->singleInt('SELECT COUNT(*) FROM system_logs WHERE level IN ("error","critical") AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $connectedGsc = (int) $this->singleInt('SELECT COUNT(*) FROM search_console_accounts');

        $mrr = (float) ($revenueByPlan['total'] ?? 0.0);
        $arpu = $activeSubscriptions > 0 ? round($mrr / $activeSubscriptions, 2) : 0.0;
        $churnRate = $activeSubscriptions > 0 ? round(($canceledThisMonth / $activeSubscriptions) * 100, 2) : 0.0;

        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: []) : [];

        return [
            'total_users' => $totalUsers,
            'active_subscriptions' => $activeSubscriptions,
            'mrr' => $mrr,
            'new_signups_today' => $newToday,
            'new_signups_week' => $newWeek,
            'new_signups_month' => $newMonth,
            'churn_rate' => $churnRate,
            'arpu' => $arpu,
            'revenue_by_plan' => $revenueByPlan,
            'plan_distribution' => $planDistribution,
            'api_usage' => $apiUsage,
            'system_errors_24h' => $systemErrors24h,
            'connected_gsc_accounts' => $connectedGsc,
            'server_load' => $load,
        ];
    }

    public function getRevenueTrend(int $months = 6): array
    {
        $months = max(1, min(24, $months));
        if (!$this->hasConnection()) {
            return [];
        }

        $planPrices = $this->getPlanPriceMap();
        $result = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = date('Y-m-01 00:00:00', strtotime('-' . $i . ' months'));
            $monthEnd = date('Y-m-t 23:59:59', strtotime($monthStart));
            $label = date('M Y', strtotime($monthStart));

            $stmt = $this->conn->prepare(
                'SELECT plan_type, COUNT(*) AS total
                 FROM subscriptions
                 WHERE (status IN ("active","trialing") OR COALESCE(lifetime_access,0)=1)
                   AND created_at <= :month_end
                 GROUP BY plan_type'
            );
            $stmt->execute([':month_end' => $monthEnd]);

            $revenue = 0.0;
            $plans = ['free' => 0, 'pro' => 0, 'agency' => 0];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $plan = strtolower((string) ($row['plan_type'] ?? 'free'));
                $total = (int) ($row['total'] ?? 0);
                if (isset($plans[$plan])) {
                    $plans[$plan] = $total;
                }
                $revenue += $total * (float) ($planPrices[$plan] ?? 0.0);
            }

            $result[] = [
                'month' => $label,
                'revenue' => round($revenue, 2),
                'free' => $plans['free'],
                'pro' => $plans['pro'],
                'agency' => $plans['agency'],
            ];
        }

        return $result;
    }

    public function getUsers(array $filters, int $limit, int $offset): array
    {
        if (!$this->hasConnection()) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $where = ['COALESCE(u.is_deleted, 0) = 0'];
        $params = [];
        $this->applyUserFilters($filters, $where, $params);

        $sql = 'SELECT
                    u.id,
                    u.name,
                    u.email,
                    u.role,
                    u.plan_type,
                    u.status,
                    u.last_login_at,
                    u.last_login_ip,
                    u.force_password_reset,
                    u.created_at,
                    (SELECT s.status FROM subscriptions s WHERE s.user_id = u.id ORDER BY s.updated_at DESC, s.id DESC LIMIT 1) AS subscription_status,
                    (SELECT s.razorpay_subscription_id FROM subscriptions s WHERE s.user_id = u.id ORDER BY s.updated_at DESC, s.id DESC LIMIT 1) AS razorpay_subscription_id,
                    (SELECT COUNT(*) FROM projects p WHERE p.user_id = u.id) AS project_count,
                    (SELECT COUNT(*) FROM tracked_keywords tk INNER JOIN projects p2 ON p2.id = tk.project_id WHERE p2.user_id = u.id) AS keyword_count
                FROM users u';

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY u.created_at DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countUsers(array $filters): int
    {
        if (!$this->hasConnection()) {
            return 0;
        }

        $where = ['COALESCE(u.is_deleted, 0) = 0'];
        $params = [];
        $this->applyUserFilters($filters, $where, $params);

        $sql = 'SELECT COUNT(*) FROM users u';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function getUserById(int $userId): ?array
    {
        if (!$this->hasConnection() || $userId <= 0) {
            return null;
        }

        $stmt = $this->conn->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function createAdminUser(string $name, string $email, string $passwordHash, string $role, string $planType = 'agency'): int
    {
        if (!$this->hasConnection()) {
            return 0;
        }

        $name = trim($name);
        $email = strtolower(trim($email));
        $role = $this->normalizeRole($role);
        $planType = $this->normalizePlan($planType);
        if ($name === '' || $email === '' || $passwordHash === '') {
            return 0;
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO users
                (name, email, password, plan_type, role, status, created_at, updated_at)
             VALUES
                (:name, :email, :password, :plan_type, :role, "active", NOW(), NOW())'
        );
        $stmt->execute([
            ':name' => mb_substr($name, 0, 255),
            ':email' => mb_substr($email, 0, 255),
            ':password' => $passwordHash,
            ':plan_type' => $planType,
            ':role' => $role,
        ]);

        return (int) $this->conn->lastInsertId();
    }

    public function setUserRole(int $userId, string $role, ?int $assignedBy = null): bool
    {
        if (!$this->hasConnection() || $userId <= 0) {
            return false;
        }
        $role = $this->normalizeRole($role);

        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare('UPDATE users SET role = :role, updated_at = NOW() WHERE id = :id');
            $stmt->execute([':role' => $role, ':id' => $userId]);

            $stmt = $this->conn->prepare('UPDATE user_roles SET is_active = 0, updated_at = NOW() WHERE user_id = :user_id');
            $stmt->execute([':user_id' => $userId]);

            $stmt = $this->conn->prepare(
                'INSERT INTO user_roles (user_id, role, assigned_by, is_active, created_at, updated_at)
                 VALUES (:user_id, :role, :assigned_by, 1, NOW(), NOW())'
            );
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':role', $role, PDO::PARAM_STR);
            $stmt->bindValue(':assigned_by', $assignedBy, $assignedBy !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->execute();

            $this->conn->commit();
            return true;
        } catch (Throwable $error) {
            $this->conn->rollBack();
            return false;
        }
    }

    public function setUserPlan(int $userId, string $planType): bool
    {
        if (!$this->hasConnection() || $userId <= 0) {
            return false;
        }
        $planType = $this->normalizePlan($planType);
        $stmt = $this->conn->prepare('UPDATE users SET plan_type = :plan, updated_at = NOW() WHERE id = :id');
        return $stmt->execute([':plan' => $planType, ':id' => $userId]);
    }

    public function setUserStatus(int $userId, string $status, ?string $reason = null): bool
    {
        if (!$this->hasConnection() || $userId <= 0) {
            return false;
        }
        $status = strtolower(trim($status)) === 'suspended' ? 'suspended' : 'active';
        $blockedAt = $status === 'suspended' ? date('Y-m-d H:i:s') : null;
        $stmt = $this->conn->prepare(
            'UPDATE users
             SET status = :status,
                 suspended_reason = :reason,
                 blocked_at = :blocked_at,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':reason', $status === 'suspended' ? mb_substr((string) ($reason ?? ''), 0, 255) : null, $status === 'suspended' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':blocked_at', $blockedAt, $blockedAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function softDeleteUser(int $userId, ?string $reason = null): bool
    {
        if (!$this->hasConnection() || $userId <= 0) {
            return false;
        }

        $stmt = $this->conn->prepare(
            'UPDATE users
             SET is_deleted = 1,
                 deleted_at = NOW(),
                 deleted_reason = :reason,
                 status = "suspended",
                 updated_at = NOW()
             WHERE id = :id'
        );
        return $stmt->execute([
            ':reason' => mb_substr((string) ($reason ?? 'Soft-deleted by admin'), 0, 255),
            ':id' => $userId,
        ]);
    }

    public function forceLogoutUser(int $userId): bool
    {
        if (!$this->hasConnection() || $userId <= 0) {
            return false;
        }
        $stmt = $this->conn->prepare('UPDATE users SET force_logout_after = NOW(), updated_at = NOW() WHERE id = :id');
        return $stmt->execute([':id' => $userId]);
    }

    public function forcePasswordReset(int $userId, bool $required = true): bool
    {
        if (!$this->hasConnection() || $userId <= 0) {
            return false;
        }
        $stmt = $this->conn->prepare('UPDATE users SET force_password_reset = :flag, updated_at = NOW() WHERE id = :id');
        return $stmt->execute([':flag' => $required ? 1 : 0, ':id' => $userId]);
    }

    public function resetUserPasswordHash(int $userId, string $passwordHash): bool
    {
        if (!$this->hasConnection() || $userId <= 0 || $passwordHash === '') {
            return false;
        }

        $stmt = $this->conn->prepare(
            'UPDATE users
             SET password = :password,
                 force_password_reset = 0,
                 updated_at = NOW()
             WHERE id = :id'
        );
        return $stmt->execute([':password' => $passwordHash, ':id' => $userId]);
    }

    public function getUserActivityLogs(int $userId, int $limit = 50): array
    {
        if (!$this->hasConnection() || $userId <= 0) {
            return [];
        }
        $limit = max(1, min(500, $limit));

        $stmt = $this->conn->prepare(
            'SELECT id, user_id, action_type, ip_address, user_agent, metadata_json, created_at
             FROM user_activity_logs
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getUserUsageSummary(int $userId, int $days = 30): array
    {
        if (!$this->hasConnection() || $userId <= 0) {
            return [];
        }
        $days = max(1, min(365, $days));

        $stmt = $this->conn->prepare(
            'SELECT metric, COALESCE(SUM(qty), 0) AS total
             FROM usage_logs
             WHERE user_id = :user_id
               AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             GROUP BY metric
             ORDER BY metric ASC'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();

        $summary = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $summary[(string) ($row['metric'] ?? 'generic')] = (int) ($row['total'] ?? 0);
        }
        return $summary;
    }

    public function getSubscriptions(array $filters, int $limit, int $offset): array
    {
        if (!$this->hasConnection()) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $where = [];
        $params = [];
        $this->applySubscriptionFilters($filters, $where, $params);

        $sql = 'SELECT
                    s.id,
                    s.user_id,
                    u.email,
                    u.name,
                    s.razorpay_subscription_id,
                    s.plan_type,
                    s.status,
                    s.next_billing_date,
                    s.grace_ends_at,
                    s.current_period_start,
                    s.current_period_end,
                    s.cancel_at_period_end,
                    COALESCE(s.lifetime_access, 0) AS lifetime_access,
                    COALESCE(s.promotional_days, 0) AS promotional_days,
                    s.manual_override_until,
                    s.updated_at,
                    s.created_at
                FROM subscriptions s
                INNER JOIN users u ON u.id = s.user_id';

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY s.updated_at DESC, s.id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countSubscriptions(array $filters): int
    {
        if (!$this->hasConnection()) {
            return 0;
        }

        $where = [];
        $params = [];
        $this->applySubscriptionFilters($filters, $where, $params);

        $sql = 'SELECT COUNT(*) FROM subscriptions s INNER JOIN users u ON u.id = s.user_id';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function setSubscriptionStatus(int $subscriptionId, string $status): bool
    {
        if (!$this->hasConnection() || $subscriptionId <= 0) {
            return false;
        }

        $status = $this->normalizeSubscriptionStatus($status);
        $stmt = $this->conn->prepare('UPDATE subscriptions SET status = :status, updated_at = NOW() WHERE id = :id');
        return $stmt->execute([':status' => $status, ':id' => $subscriptionId]);
    }

    public function extendSubscriptionDays(int $subscriptionId, int $days, ?string $reason = null): bool
    {
        if (!$this->hasConnection() || $subscriptionId <= 0) {
            return false;
        }

        $days = max(1, min(3650, $days));
        $stmt = $this->conn->prepare(
            'UPDATE subscriptions
             SET next_billing_date = DATE_ADD(COALESCE(next_billing_date, NOW()), INTERVAL :days DAY),
                 current_period_end = DATE_ADD(COALESCE(current_period_end, NOW()), INTERVAL :days DAY),
                 admin_notes = :reason,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->bindValue(':reason', mb_substr((string) ($reason ?? 'Extended by admin'), 0, 255), PDO::PARAM_STR);
        $stmt->bindValue(':id', $subscriptionId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function setSubscriptionLifetime(int $subscriptionId, bool $enabled = true): bool
    {
        if (!$this->hasConnection() || $subscriptionId <= 0) {
            return false;
        }

        $stmt = $this->conn->prepare('UPDATE subscriptions SET lifetime_access = :flag, status = "active", updated_at = NOW() WHERE id = :id');
        return $stmt->execute([':flag' => $enabled ? 1 : 0, ':id' => $subscriptionId]);
    }

    public function addPromotionalDays(int $subscriptionId, int $days): bool
    {
        if (!$this->hasConnection() || $subscriptionId <= 0) {
            return false;
        }

        $days = max(1, min(3650, $days));
        $stmt = $this->conn->prepare(
            'UPDATE subscriptions
             SET promotional_days = COALESCE(promotional_days, 0) + :days,
                 next_billing_date = DATE_ADD(COALESCE(next_billing_date, NOW()), INTERVAL :days DAY),
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->bindValue(':id', $subscriptionId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function createPaymentLog(array $data): bool
    {
        if (!$this->hasConnection()) {
            return false;
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO payment_logs
                (user_id, subscription_id, gateway, gateway_transaction_id, event_type, amount, currency, payment_status, notes_json, created_at)
             VALUES
                (:user_id, :subscription_id, :gateway, :gateway_transaction_id, :event_type, :amount, :currency, :payment_status, :notes_json, NOW())'
        );
        $stmt->bindValue(':user_id', isset($data['user_id']) ? (int) $data['user_id'] : null, isset($data['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':subscription_id', isset($data['subscription_id']) ? (int) $data['subscription_id'] : null, isset($data['subscription_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':gateway', mb_substr(strtolower(trim((string) ($data['gateway'] ?? 'razorpay'))), 0, 30), PDO::PARAM_STR);
        $stmt->bindValue(':gateway_transaction_id', mb_substr((string) ($data['gateway_transaction_id'] ?? ''), 0, 120), PDO::PARAM_STR);
        $stmt->bindValue(':event_type', mb_substr((string) ($data['event_type'] ?? 'manual_update'), 0, 80), PDO::PARAM_STR);
        $stmt->bindValue(':amount', (float) ($data['amount'] ?? 0), PDO::PARAM_STR);
        $stmt->bindValue(':currency', mb_substr((string) ($data['currency'] ?? 'INR'), 0, 10), PDO::PARAM_STR);
        $stmt->bindValue(':payment_status', mb_substr((string) ($data['payment_status'] ?? 'pending'), 0, 30), PDO::PARAM_STR);
        $stmt->bindValue(':notes_json', json_encode($data['notes'] ?? []), PDO::PARAM_STR);
        return $stmt->execute();
    }

    public function getPaymentLogs(int $limit = 100): array
    {
        if (!$this->hasConnection()) {
            return [];
        }
        $limit = max(1, min(1000, $limit));

        $stmt = $this->conn->prepare(
            'SELECT id, user_id, subscription_id, gateway, gateway_transaction_id, event_type, amount, currency, payment_status, notes_json, created_at
             FROM payment_logs
             ORDER BY created_at DESC, id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getSecuritySettings(): array
    {
        if (!$this->hasConnection()) {
            return [];
        }

        $rows = $this->conn->query('SELECT setting_key, setting_value FROM security_settings')->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows ?: [] as $row) {
            $result[(string) ($row['setting_key'] ?? '')] = (string) ($row['setting_value'] ?? '');
        }
        return $result;
    }

    public function setSecuritySetting(string $key, string $value, ?int $updatedBy = null): bool
    {
        if (!$this->hasConnection()) {
            return false;
        }

        $key = mb_substr(strtolower(trim($key)), 0, 80);
        if ($key === '') {
            return false;
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO security_settings (setting_key, setting_value, updated_by, created_at, updated_at)
             VALUES (:setting_key, :setting_value, :updated_by, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = NOW()'
        );
        $stmt->bindValue(':setting_key', $key, PDO::PARAM_STR);
        $stmt->bindValue(':setting_value', mb_substr(trim($value), 0, 255), PDO::PARAM_STR);
        $stmt->bindValue(':updated_by', $updatedBy, $updatedBy !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        return $stmt->execute();
    }

    public function getBlockedIps(int $limit = 200): array
    {
        if (!$this->hasConnection()) {
            return [];
        }

        $limit = max(1, min(1000, $limit));
        $stmt = $this->conn->prepare(
            'SELECT id, ip_address, reason, blocked_by, expires_at, is_active, created_at, updated_at
             FROM blocked_ips
             ORDER BY created_at DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function upsertBlockedIp(string $ipAddress, ?string $reason = null, ?int $blockedBy = null, ?string $expiresAt = null, bool $isActive = true): bool
    {
        if (!$this->hasConnection()) {
            return false;
        }

        $ipAddress = mb_substr(trim($ipAddress), 0, 45);
        if ($ipAddress === '') {
            return false;
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO blocked_ips (ip_address, reason, blocked_by, expires_at, is_active, created_at, updated_at)
             VALUES (:ip_address, :reason, :blocked_by, :expires_at, :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                reason = VALUES(reason),
                blocked_by = VALUES(blocked_by),
                expires_at = VALUES(expires_at),
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );
        $stmt->bindValue(':ip_address', $ipAddress, PDO::PARAM_STR);
        $stmt->bindValue(':reason', $reason !== null ? mb_substr(trim($reason), 0, 255) : null, $reason !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':blocked_by', $blockedBy, $blockedBy !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':expires_at', $expiresAt, $expiresAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':is_active', $isActive ? 1 : 0, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function unblockIp(string $ipAddress): bool
    {
        if (!$this->hasConnection()) {
            return false;
        }
        $stmt = $this->conn->prepare('UPDATE blocked_ips SET is_active = 0, updated_at = NOW() WHERE ip_address = :ip_address');
        return $stmt->execute([':ip_address' => mb_substr(trim($ipAddress), 0, 45)]);
    }

    public function isIpBlocked(string $ipAddress): bool
    {
        if (!$this->hasConnection()) {
            return false;
        }

        $stmt = $this->conn->prepare(
            'SELECT COUNT(*)
             FROM blocked_ips
             WHERE ip_address = :ip_address
               AND is_active = 1
               AND (expires_at IS NULL OR expires_at > NOW())'
        );
        $stmt->execute([':ip_address' => mb_substr(trim($ipAddress), 0, 45)]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function logFailedLogin(?string $email, ?string $ipAddress, ?string $userAgent, bool $isBlocked = false): bool
    {
        if (!$this->hasConnection()) {
            return false;
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO failed_logins (email, ip_address, user_agent, attempted_at, is_blocked)
             VALUES (:email, :ip_address, :user_agent, NOW(), :is_blocked)'
        );
        $stmt->bindValue(':email', $email !== null ? mb_substr(strtolower(trim($email)), 0, 255) : null, $email !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':ip_address', $ipAddress !== null ? mb_substr(trim($ipAddress), 0, 45) : null, $ipAddress !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':user_agent', $userAgent !== null ? mb_substr(trim($userAgent), 0, 255) : null, $userAgent !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':is_blocked', $isBlocked ? 1 : 0, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function getFailedLogins(int $limit = 200): array
    {
        if (!$this->hasConnection()) {
            return [];
        }

        $limit = max(1, min(1000, $limit));
        $stmt = $this->conn->prepare(
            'SELECT id, email, ip_address, user_agent, attempted_at, is_blocked
             FROM failed_logins
             ORDER BY attempted_at DESC, id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getPlanLimits(): array
    {
        if (!$this->hasConnection()) {
            return [];
        }

        $stmt = $this->conn->query(
            'SELECT plan_type, projects_limit, keywords_limit, api_calls_daily, insights_limit, ai_monthly_limit, can_export, can_manual_refresh
             FROM plan_limits
             ORDER BY FIELD(plan_type, "free", "pro", "agency")'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updatePlanLimit(string $planType, array $data): bool
    {
        if (!$this->hasConnection()) {
            return false;
        }

        $planType = $this->normalizePlan($planType);
        $stmt = $this->conn->prepare(
            'UPDATE plan_limits
             SET projects_limit = :projects_limit,
                 keywords_limit = :keywords_limit,
                 api_calls_daily = :api_calls_daily,
                 insights_limit = :insights_limit,
                 ai_monthly_limit = :ai_monthly_limit,
                 can_export = :can_export,
                 can_manual_refresh = :can_manual_refresh,
                 updated_at = NOW()
             WHERE plan_type = :plan_type'
        );
        return $stmt->execute([
            ':projects_limit' => max(1, (int) ($data['projects_limit'] ?? 1)),
            ':keywords_limit' => max(1, (int) ($data['keywords_limit'] ?? 5)),
            ':api_calls_daily' => max(1, (int) ($data['api_calls_daily'] ?? 250)),
            ':insights_limit' => max(1, (int) ($data['insights_limit'] ?? 3)),
            ':ai_monthly_limit' => max(1, (int) ($data['ai_monthly_limit'] ?? 3)),
            ':can_export' => !empty($data['can_export']) ? 1 : 0,
            ':can_manual_refresh' => !empty($data['can_manual_refresh']) ? 1 : 0,
            ':plan_type' => $planType,
        ]);
    }

    public function getPlans(): array
    {
        if (!$this->hasConnection()) {
            return [];
        }
        return $this->conn->query('SELECT * FROM plans ORDER BY FIELD(plan_code, "free", "pro", "agency")')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updatePlan(string $planCode, array $data): bool
    {
        if (!$this->hasConnection()) {
            return false;
        }

        $planCode = $this->normalizePlan($planCode);
        $stmt = $this->conn->prepare(
            'UPDATE plans
             SET display_name = :display_name,
                 price_monthly = :price_monthly,
                 price_yearly = :price_yearly,
                 is_active = :is_active,
                 description = :description,
                 updated_at = NOW()
             WHERE plan_code = :plan_code'
        );

        return $stmt->execute([
            ':display_name' => mb_substr(trim((string) ($data['display_name'] ?? ucfirst($planCode))), 0, 80),
            ':price_monthly' => max(0, (float) ($data['price_monthly'] ?? 0)),
            ':price_yearly' => max(0, (float) ($data['price_yearly'] ?? 0)),
            ':is_active' => !empty($data['is_active']) ? 1 : 0,
            ':description' => mb_substr((string) ($data['description'] ?? ''), 0, 255),
            ':plan_code' => $planCode,
        ]);
    }

    public function getFeatureFlags(): array
    {
        if (!$this->hasConnection()) {
            return [];
        }
        return $this->conn->query('SELECT * FROM feature_flags ORDER BY flag_key ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function upsertFeatureFlag(string $flagKey, array $data): bool
    {
        if (!$this->hasConnection()) {
            return false;
        }

        $flagKey = $this->sanitizeFlag($flagKey);
        if ($flagKey === '') {
            return false;
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO feature_flags (flag_key, flag_name, description, is_enabled, rollout_plan, created_at, updated_at)
             VALUES (:flag_key, :flag_name, :description, :is_enabled, :rollout_plan, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                flag_name = VALUES(flag_name),
                description = VALUES(description),
                is_enabled = VALUES(is_enabled),
                rollout_plan = VALUES(rollout_plan),
                updated_at = NOW()'
        );

        return $stmt->execute([
            ':flag_key' => $flagKey,
            ':flag_name' => mb_substr(trim((string) ($data['flag_name'] ?? $flagKey)), 0, 120),
            ':description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 255),
            ':is_enabled' => !empty($data['is_enabled']) ? 1 : 0,
            ':rollout_plan' => mb_substr(trim((string) ($data['rollout_plan'] ?? 'all')), 0, 30),
        ]);
    }

    public function getCoupons(int $limit = 200): array
    {
        if (!$this->hasConnection()) {
            return [];
        }

        $limit = max(1, min(1000, $limit));
        $stmt = $this->conn->prepare('SELECT * FROM coupons ORDER BY created_at DESC LIMIT ' . (int) $limit);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createCoupon(array $data): bool
    {
        if (!$this->hasConnection()) {
            return false;
        }

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '') {
            return false;
        }

        $discountType = strtolower(trim((string) ($data['discount_type'] ?? 'percent')));
        if (!in_array($discountType, ['percent', 'fixed'], true)) {
            $discountType = 'percent';
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO coupons
                (code, discount_type, discount_value, max_uses, used_count, expires_at, plan_scope, is_active, metadata_json, created_at, updated_at)
             VALUES
                (:code, :discount_type, :discount_value, :max_uses, 0, :expires_at, :plan_scope, :is_active, :metadata_json, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                discount_type = VALUES(discount_type),
                discount_value = VALUES(discount_value),
                max_uses = VALUES(max_uses),
                expires_at = VALUES(expires_at),
                plan_scope = VALUES(plan_scope),
                is_active = VALUES(is_active),
                metadata_json = VALUES(metadata_json),
                updated_at = NOW()'
        );

        return $stmt->execute([
            ':code' => mb_substr($code, 0, 50),
            ':discount_type' => $discountType,
            ':discount_value' => max(0, (float) ($data['discount_value'] ?? 0)),
            ':max_uses' => isset($data['max_uses']) && (int) $data['max_uses'] > 0 ? (int) $data['max_uses'] : null,
            ':expires_at' => !empty($data['expires_at']) ? (string) $data['expires_at'] : null,
            ':plan_scope' => mb_substr(strtolower((string) ($data['plan_scope'] ?? 'all')), 0, 30),
            ':is_active' => !empty($data['is_active']) ? 1 : 0,
            ':metadata_json' => json_encode($data['metadata'] ?? []),
        ]);
    }

    public function setCouponStatus(int $couponId, bool $isActive): bool
    {
        if (!$this->hasConnection() || $couponId <= 0) {
            return false;
        }
        $stmt = $this->conn->prepare('UPDATE coupons SET is_active = :active, updated_at = NOW() WHERE id = :id');
        return $stmt->execute([':active' => $isActive ? 1 : 0, ':id' => $couponId]);
    }

    public function getSystemLogs(int $limit = 100): array
    {
        if (!$this->hasConnection()) {
            return [];
        }

        $limit = max(1, min(1000, $limit));
        $stmt = $this->conn->prepare('SELECT * FROM system_logs ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getCronLogs(int $limit = 100): array
    {
        if (!$this->hasConnection()) {
            return [];
        }

        $limit = max(1, min(1000, $limit));
        $stmt = $this->conn->prepare('SELECT * FROM cron_logs ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createCronLog(string $cronName, string $runStatus, string $startedAt, ?string $finishedAt = null, int $durationMs = 0, ?string $message = null, array $stats = []): bool
    {
        if (!$this->hasConnection()) {
            return false;
        }

        $runStatus = in_array($runStatus, ['success', 'warning', 'failed'], true) ? $runStatus : 'success';
        $stmt = $this->conn->prepare(
            'INSERT INTO cron_logs (cron_name, run_status, started_at, finished_at, duration_ms, message, stats_json, created_at)
             VALUES (:cron_name, :run_status, :started_at, :finished_at, :duration_ms, :message, :stats_json, NOW())'
        );
        $stmt->bindValue(':cron_name', mb_substr(trim($cronName), 0, 100), PDO::PARAM_STR);
        $stmt->bindValue(':run_status', $runStatus, PDO::PARAM_STR);
        $stmt->bindValue(':started_at', $startedAt, PDO::PARAM_STR);
        $stmt->bindValue(':finished_at', $finishedAt, $finishedAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':duration_ms', max(0, $durationMs), PDO::PARAM_INT);
        $stmt->bindValue(':message', $message !== null ? mb_substr(trim($message), 0, 600) : null, $message !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':stats_json', json_encode($stats), PDO::PARAM_STR);
        return $stmt->execute();
    }

    public function createApiUsageLog(?int $userId, ?int $projectId, string $provider, string $endpoint, int $units = 1, int $statusCode = 200, int $responseTimeMs = 0): bool
    {
        if (!$this->hasConnection()) {
            return false;
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO api_usage_logs (user_id, project_id, provider, endpoint, units, status_code, response_time_ms, created_at)
             VALUES (:user_id, :project_id, :provider, :endpoint, :units, :status_code, :response_time_ms, NOW())'
        );
        $stmt->bindValue(':user_id', $userId, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':project_id', $projectId, $projectId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':provider', mb_substr(strtolower(trim($provider)), 0, 50), PDO::PARAM_STR);
        $stmt->bindValue(':endpoint', mb_substr(trim($endpoint), 0, 120), PDO::PARAM_STR);
        $stmt->bindValue(':units', max(1, $units), PDO::PARAM_INT);
        $stmt->bindValue(':status_code', $statusCode, PDO::PARAM_INT);
        $stmt->bindValue(':response_time_ms', max(0, $responseTimeMs), PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function getApiUsageSummary(int $days = 7): array
    {
        if (!$this->hasConnection()) {
            return [];
        }

        $days = max(1, min(365, $days));
        $stmt = $this->conn->prepare(
            'SELECT provider, COALESCE(SUM(units),0) AS total_units, COUNT(*) AS calls
             FROM api_usage_logs
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             GROUP BY provider
             ORDER BY total_units DESC'
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getAiQueueStats(): array
    {
        if (!$this->hasConnection()) {
            return [
                'pending' => 0,
                'processing' => 0,
                'completed_today' => 0,
                'failed_today' => 0,
            ];
        }

        return [
            'pending' => (int) $this->singleInt('SELECT COUNT(*) FROM ai_request_queue WHERE status = "pending"'),
            'processing' => (int) $this->singleInt('SELECT COUNT(*) FROM ai_request_queue WHERE status = "processing"'),
            'completed_today' => (int) $this->singleInt('SELECT COUNT(*) FROM ai_request_queue WHERE status = "completed" AND DATE(updated_at) = CURDATE()'),
            'failed_today' => (int) $this->singleInt('SELECT COUNT(*) FROM ai_request_queue WHERE status = "failed" AND DATE(updated_at) = CURDATE()'),
        ];
    }

    public function getAiCostSummary(string $month): array
    {
        if (!$this->hasConnection()) {
            return [
                'month' => $month,
                'tokens_total' => 0,
                'cost_total' => 0.0,
                'requests_total' => 0,
                'users_active' => 0,
            ];
        }

        if (!preg_match('/^\d{4}\-(0[1-9]|1[0-2])$/', $month)) {
            $month = date('Y-m');
        }

        $stmt = $this->conn->prepare(
            'SELECT
                COALESCE(SUM(tokens_used), 0) AS tokens_total,
                COALESCE(SUM(cost_estimate), 0) AS cost_total,
                COUNT(*) AS requests_total,
                COUNT(DISTINCT user_id) AS users_active
             FROM ai_cost_logs
             WHERE DATE_FORMAT(created_at, "%Y-%m") = :month'
        );
        $stmt->execute([':month' => $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'month' => $month,
            'tokens_total' => (int) ($row['tokens_total'] ?? 0),
            'cost_total' => round((float) ($row['cost_total'] ?? 0), 4),
            'requests_total' => (int) ($row['requests_total'] ?? 0),
            'users_active' => (int) ($row['users_active'] ?? 0),
        ];
    }

    public function getAiUsageByUserMonth(string $month, int $limit = 100): array
    {
        if (!$this->hasConnection()) {
            return [];
        }

        if (!preg_match('/^\d{4}\-(0[1-9]|1[0-2])$/', $month)) {
            $month = date('Y-m');
        }
        $limit = max(1, min(500, $limit));

        $stmt = $this->conn->prepare(
            'SELECT
                au.user_id,
                u.email,
                u.name,
                u.plan_type,
                au.month,
                au.request_count,
                au.last_request_at,
                COALESCE(SUM(acl.tokens_used), 0) AS tokens_used,
                COALESCE(SUM(acl.cost_estimate), 0) AS cost_estimate
             FROM ai_usage au
             INNER JOIN users u ON u.id = au.user_id
             LEFT JOIN ai_cost_logs acl
                ON acl.user_id = au.user_id
               AND DATE_FORMAT(acl.created_at, "%Y-%m") = au.month
             WHERE au.month = :month
             GROUP BY au.user_id, u.email, u.name, u.plan_type, au.month, au.request_count, au.last_request_at
             ORDER BY au.request_count DESC, au.last_request_at DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute([':month' => $month]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getAuditLogs(int $limit = 100): array
    {
        if (!$this->hasConnection()) {
            return [];
        }

        $limit = max(1, min(1000, $limit));
        $stmt = $this->conn->prepare(
            'SELECT al.*, u1.email AS actor_email, u2.email AS target_email
             FROM audit_logs al
             LEFT JOIN users u1 ON u1.id = al.actor_user_id
             LEFT JOIN users u2 ON u2.id = al.target_user_id
             ORDER BY al.created_at DESC, al.id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getDatabaseHealth(): array
    {
        if (!$this->hasConnection()) {
            return ['status' => 'down', 'latency_ms' => null, 'checked_at' => date('Y-m-d H:i:s')];
        }

        $start = microtime(true);
        $ok = false;
        try {
            $this->conn->query('SELECT 1');
            $ok = true;
        } catch (Throwable $error) {
            $ok = false;
        }

        return [
            'status' => $ok ? 'ok' : 'error',
            'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            'checked_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function getPlanPriceMap(): array
    {
        $prices = ['free' => 0.0, 'pro' => 999.0, 'agency' => 2999.0];
        if (!$this->hasConnection()) {
            return $prices;
        }

        foreach ($this->conn->query('SELECT plan_code, price_monthly FROM plans')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = strtolower((string) ($row['plan_code'] ?? ''));
            if (isset($prices[$code])) {
                $prices[$code] = (float) ($row['price_monthly'] ?? $prices[$code]);
            }
        }
        return $prices;
    }

    private function applyUserFilters(array $filters, array &$where, array &$params): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(u.name LIKE :search OR u.email LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $plan = strtolower(trim((string) ($filters['plan'] ?? '')));
        if (in_array($plan, ['free', 'pro', 'agency'], true)) {
            $where[] = 'u.plan_type = :plan';
            $params[':plan'] = $plan;
        }

        $role = $this->normalizeRole((string) ($filters['role'] ?? ''));
        if ($role !== 'user' || trim((string) ($filters['role'] ?? '')) === 'user') {
            if (trim((string) ($filters['role'] ?? '')) !== '') {
                $where[] = 'u.role = :role';
                $params[':role'] = $role;
            }
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if (in_array($status, ['active', 'suspended'], true)) {
            $where[] = 'u.status = :status';
            $params[':status'] = $status;
        }

        $subscriptionStatus = strtolower(trim((string) ($filters['subscription_status'] ?? '')));
        if ($subscriptionStatus !== '') {
            $where[] = 'EXISTS (
                SELECT 1 FROM subscriptions sx
                WHERE sx.user_id = u.id
                  AND sx.status = :subscription_status
            )';
            $params[':subscription_status'] = $this->normalizeSubscriptionStatus($subscriptionStatus);
        }
    }

    private function applySubscriptionFilters(array $filters, array &$where, array &$params): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(u.name LIKE :search OR u.email LIKE :search OR s.razorpay_subscription_id LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status !== '') {
            $where[] = 's.status = :status';
            $params[':status'] = $this->normalizeSubscriptionStatus($status);
        }

        $plan = strtolower(trim((string) ($filters['plan'] ?? '')));
        if (in_array($plan, ['free', 'pro', 'agency'], true)) {
            $where[] = 's.plan_type = :plan';
            $params[':plan'] = $plan;
        }
    }

    private function normalizePlan(string $plan): string
    {
        $plan = strtolower(trim($plan));
        if (!in_array($plan, ['free', 'pro', 'agency'], true)) {
            return 'free';
        }
        return $plan;
    }

    private function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        if (!in_array($role, ['super_admin', 'admin', 'support_admin', 'billing_admin', 'agency', 'user'], true)) {
            return 'user';
        }
        return $role;
    }

    private function normalizeSubscriptionStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $allowed = ['incomplete', 'trialing', 'active', 'past_due', 'canceled'];
        if (!in_array($status, $allowed, true)) {
            return 'incomplete';
        }
        return $status;
    }

    private function sanitizeFlag(string $flag): string
    {
        $flag = strtolower(trim($flag));
        $flag = preg_replace('/[^a-z0-9_\-\.]/', '_', $flag);
        return mb_substr((string) $flag, 0, 80);
    }

    private function singleInt(string $sql): int
    {
        if (!$this->hasConnection()) {
            return 0;
        }
        try {
            return (int) $this->conn->query($sql)->fetchColumn();
        } catch (Throwable $error) {
            return 0;
        }
    }
}
