<?php

require_once __DIR__ . '/../models/AdminControlModel.php';
require_once __DIR__ . '/AuditLogService.php';
require_once __DIR__ . '/SystemLogService.php';

class AdminControlService
{
    private AdminControlModel $model;
    private AuditLogService $auditLogService;
    private SystemLogService $systemLogService;

    public function __construct(
        ?AdminControlModel $model = null,
        ?AuditLogService $auditLogService = null,
        ?SystemLogService $systemLogService = null
    ) {
        $this->model = $model ?? new AdminControlModel();
        $this->auditLogService = $auditLogService ?? new AuditLogService();
        $this->systemLogService = $systemLogService ?? new SystemLogService();
    }

    public function hasDatabase(): bool
    {
        return $this->model->hasConnection();
    }

    public function getDashboardData(): array
    {
        return [
            'metrics' => $this->model->getDashboardMetrics(),
            'revenue_trend' => $this->model->getRevenueTrend(6),
            'db_health' => $this->model->getDatabaseHealth(),
            'cron_logs' => $this->model->getCronLogs(40),
            'system_logs' => $this->model->getSystemLogs(40),
            'audit_logs' => $this->model->getAuditLogs(40),
            'api_usage_summary' => $this->model->getApiUsageSummary(7),
        ];
    }

    public function getUsersPageData(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $items = $this->model->getUsers($filters, $perPage, $offset);
        $total = $this->model->countUsers($filters);
        $pages = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $pages,
            ],
            'filters' => [
                'search' => trim((string) ($filters['search'] ?? '')),
                'plan' => trim((string) ($filters['plan'] ?? '')),
                'role' => trim((string) ($filters['role'] ?? '')),
                'status' => trim((string) ($filters['status'] ?? '')),
                'subscription_status' => trim((string) ($filters['subscription_status'] ?? '')),
            ],
        ];
    }

    public function getUserActivityData(int $userId): array
    {
        $userId = max(0, $userId);
        return [
            'user' => $this->model->getUserById($userId),
            'activity_logs' => $this->model->getUserActivityLogs($userId, 200),
            'usage_summary' => $this->model->getUserUsageSummary($userId, 30),
        ];
    }

    public function handleUserAction(int $adminUserId, string $action, array $payload): array
    {
        $action = strtolower(trim($action));
        $targetUserId = (int) ($payload['target_user_id'] ?? 0);
        $actor = $this->model->getUserById($adminUserId);
        $actorRole = strtolower((string) ($actor['role'] ?? 'admin'));

        if (in_array($action, ['update_plan', 'update_role', 'update_status', 'soft_delete', 'force_logout', 'force_password_reset', 'reset_password'], true) && $targetUserId <= 0) {
            return $this->error('INVALID_USER', 'Invalid target user.', 422);
        }

        if ($action === 'create_admin') {
            $name = trim((string) ($payload['name'] ?? ''));
            $email = strtolower(trim((string) ($payload['email'] ?? '')));
            $password = (string) ($payload['password'] ?? '');
            $role = trim((string) ($payload['role'] ?? 'admin'));
            $plan = trim((string) ($payload['plan_type'] ?? 'agency'));

            if (!$this->canAssignRole($actorRole, $role)) {
                return $this->error('FORBIDDEN_ROLE_ASSIGN', 'You cannot assign this role.', 403);
            }

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
                return $this->error('INVALID_INPUT', 'Provide valid name, email, and password (min 8 chars).', 422);
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $newUserId = $this->model->createAdminUser($name, $email, $hash, $role, $plan);
            if ($newUserId <= 0) {
                return $this->error('CREATE_FAILED', 'Could not create admin user.', 500);
            }
            $this->model->setUserRole($newUserId, $role, $adminUserId);

            $this->auditLogService->log($adminUserId, 'admin.user.create_admin', [
                'new_user_id' => $newUserId,
                'email' => $email,
                'role' => $role,
                'plan_type' => $plan,
            ], $newUserId);

            return ['success' => true, 'message' => 'Admin user created.', 'new_user_id' => $newUserId];
        }

        if ($action === 'update_plan') {
            $plan = (string) ($payload['plan_type'] ?? 'free');
            if (!$this->model->setUserPlan($targetUserId, $plan)) {
                return $this->error('UPDATE_FAILED', 'Could not update plan.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.user.plan_changed', ['plan_type' => $plan], $targetUserId);
            return ['success' => true, 'message' => 'Plan updated.'];
        }

        if ($action === 'update_role') {
            $role = (string) ($payload['role'] ?? 'user');
            if (!$this->canAssignRole($actorRole, $role)) {
                return $this->error('FORBIDDEN_ROLE_ASSIGN', 'You cannot assign this role.', 403);
            }

            $target = $this->model->getUserById($targetUserId);
            $targetRole = strtolower((string) ($target['role'] ?? 'user'));
            if ($targetRole === 'super_admin' && $actorRole !== 'super_admin') {
                return $this->error('FORBIDDEN_ROLE_ASSIGN', 'Only super admin can modify another super admin.', 403);
            }

            if (!$this->model->setUserRole($targetUserId, $role, $adminUserId)) {
                return $this->error('UPDATE_FAILED', 'Could not update role.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.user.role_changed', ['role' => $role], $targetUserId);
            return ['success' => true, 'message' => 'Role updated.'];
        }

        if ($action === 'update_status') {
            $status = (string) ($payload['status'] ?? 'active');
            $reason = (string) ($payload['reason'] ?? '');
            if (!$this->model->setUserStatus($targetUserId, $status, $reason)) {
                return $this->error('UPDATE_FAILED', 'Could not update user status.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.user.status_changed', ['status' => $status, 'reason' => $reason], $targetUserId);
            return ['success' => true, 'message' => 'User status updated.'];
        }

        if ($action === 'soft_delete') {
            $reason = (string) ($payload['reason'] ?? 'Soft-deleted by admin');
            if (!$this->model->softDeleteUser($targetUserId, $reason)) {
                return $this->error('DELETE_FAILED', 'Could not soft-delete user.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.user.soft_deleted', ['reason' => $reason], $targetUserId);
            return ['success' => true, 'message' => 'User soft-deleted.'];
        }

        if ($action === 'force_logout') {
            if (!$this->model->forceLogoutUser($targetUserId)) {
                return $this->error('UPDATE_FAILED', 'Could not force logout user.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.user.force_logout', [], $targetUserId);
            return ['success' => true, 'message' => 'User will be logged out on next request.'];
        }

        if ($action === 'force_password_reset') {
            $required = !empty($payload['required']);
            if (!$this->model->forcePasswordReset($targetUserId, $required)) {
                return $this->error('UPDATE_FAILED', 'Could not update force password reset state.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.user.force_password_reset', ['required' => $required], $targetUserId);
            return ['success' => true, 'message' => 'Password reset enforcement updated.'];
        }

        if ($action === 'reset_password') {
            $password = (string) ($payload['password'] ?? '');
            if (strlen($password) < 8) {
                return $this->error('INVALID_PASSWORD', 'Password must be at least 8 characters.', 422);
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            if (!$this->model->resetUserPasswordHash($targetUserId, $hash)) {
                return $this->error('RESET_FAILED', 'Could not reset password.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.user.password_reset', [], $targetUserId);
            return ['success' => true, 'message' => 'Password reset successfully.'];
        }

        return $this->error('INVALID_ACTION', 'Unsupported user action.', 400);
    }

    public function getSubscriptionsPageData(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $items = $this->model->getSubscriptions($filters, $perPage, $offset);
        $total = $this->model->countSubscriptions($filters);
        $pages = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $pages,
            ],
            'filters' => [
                'search' => trim((string) ($filters['search'] ?? '')),
                'status' => trim((string) ($filters['status'] ?? '')),
                'plan' => trim((string) ($filters['plan'] ?? '')),
            ],
            'payment_logs' => $this->model->getPaymentLogs(80),
        ];
    }

    public function handleSubscriptionAction(int $adminUserId, string $action, array $payload): array
    {
        $action = strtolower(trim($action));
        $subscriptionId = (int) ($payload['subscription_id'] ?? 0);

        if ($subscriptionId <= 0) {
            return $this->error('INVALID_SUBSCRIPTION', 'Invalid subscription id.', 422);
        }

        if ($action === 'update_status') {
            $status = (string) ($payload['status'] ?? 'active');
            if (!$this->model->setSubscriptionStatus($subscriptionId, $status)) {
                return $this->error('UPDATE_FAILED', 'Could not update subscription status.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.subscription.status_changed', ['subscription_id' => $subscriptionId, 'status' => $status]);
            return ['success' => true, 'message' => 'Subscription status updated.'];
        }

        if ($action === 'extend_days') {
            $days = (int) ($payload['days'] ?? 0);
            if ($days <= 0) {
                return $this->error('INVALID_DAYS', 'Days must be greater than 0.', 422);
            }
            if (!$this->model->extendSubscriptionDays($subscriptionId, $days, 'Extended by admin')) {
                return $this->error('UPDATE_FAILED', 'Could not extend subscription.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.subscription.extended', ['subscription_id' => $subscriptionId, 'days' => $days]);
            return ['success' => true, 'message' => 'Subscription extended.'];
        }

        if ($action === 'promo_days') {
            $days = (int) ($payload['days'] ?? 0);
            if ($days <= 0) {
                return $this->error('INVALID_DAYS', 'Promotional days must be greater than 0.', 422);
            }
            if (!$this->model->addPromotionalDays($subscriptionId, $days)) {
                return $this->error('UPDATE_FAILED', 'Could not add promotional days.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.subscription.promo_days_added', ['subscription_id' => $subscriptionId, 'days' => $days]);
            return ['success' => true, 'message' => 'Promotional days added.'];
        }

        if ($action === 'lifetime_access') {
            $enabled = !empty($payload['enabled']);
            if (!$this->model->setSubscriptionLifetime($subscriptionId, $enabled)) {
                return $this->error('UPDATE_FAILED', 'Could not update lifetime access.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.subscription.lifetime_changed', ['subscription_id' => $subscriptionId, 'enabled' => $enabled]);
            return ['success' => true, 'message' => 'Lifetime access updated.'];
        }

        if ($action === 'refund') {
            $amount = max(0, (float) ($payload['amount'] ?? 0));
            $eventType = (string) ($payload['event_type'] ?? 'manual_refund');
            $ok = $this->model->createPaymentLog([
                'user_id' => isset($payload['user_id']) ? (int) $payload['user_id'] : null,
                'subscription_id' => $subscriptionId,
                'gateway' => 'razorpay',
                'gateway_transaction_id' => (string) ($payload['gateway_transaction_id'] ?? ''),
                'event_type' => $eventType,
                'amount' => $amount,
                'currency' => (string) ($payload['currency'] ?? 'INR'),
                'payment_status' => 'refunded',
                'notes' => ['reason' => (string) ($payload['reason'] ?? 'Manual refund by admin')],
            ]);
            if (!$ok) {
                return $this->error('LOG_FAILED', 'Could not log refund action.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.subscription.refund_logged', ['subscription_id' => $subscriptionId, 'amount' => $amount]);
            return ['success' => true, 'message' => 'Refund logged successfully.'];
        }

        return $this->error('INVALID_ACTION', 'Unsupported subscription action.', 400);
    }

    public function getRevenuePageData(): array
    {
        $metrics = $this->model->getDashboardMetrics();
        return [
            'metrics' => $metrics,
            'revenue_trend' => $this->model->getRevenueTrend(12),
            'payment_logs' => $this->model->getPaymentLogs(200),
            'plans' => $this->model->getPlans(),
        ];
    }

    public function getSystemPageData(): array
    {
        $month = date('Y-m');
        return [
            'db_health' => $this->model->getDatabaseHealth(),
            'cron_logs' => $this->model->getCronLogs(200),
            'system_logs' => $this->model->getSystemLogs(200),
            'api_usage_summary' => $this->model->getApiUsageSummary(30),
            'audit_logs' => $this->model->getAuditLogs(200),
            'ai_queue_stats' => $this->model->getAiQueueStats(),
            'ai_cost_summary' => $this->model->getAiCostSummary($month),
            'ai_usage_by_user' => $this->model->getAiUsageByUserMonth($month, 120),
            'ai_month' => $month,
        ];
    }

    public function getSecurityPageData(): array
    {
        return [
            'settings' => $this->model->getSecuritySettings(),
            'blocked_ips' => $this->model->getBlockedIps(200),
            'failed_logins' => $this->model->getFailedLogins(200),
        ];
    }

    public function handleSecurityAction(int $adminUserId, string $action, array $payload): array
    {
        $action = strtolower(trim($action));

        if ($action === 'save_setting') {
            $key = (string) ($payload['setting_key'] ?? '');
            $value = (string) ($payload['setting_value'] ?? '');
            if ($key === '') {
                return $this->error('INVALID_KEY', 'Setting key is required.', 422);
            }
            if (!$this->model->setSecuritySetting($key, $value, $adminUserId)) {
                return $this->error('UPDATE_FAILED', 'Could not save setting.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.security.setting_updated', ['setting_key' => $key, 'setting_value' => $value]);
            return ['success' => true, 'message' => 'Security setting updated.'];
        }

        if ($action === 'block_ip') {
            $ip = (string) ($payload['ip_address'] ?? '');
            $reason = (string) ($payload['reason'] ?? 'Blocked by admin');
            $expiresAt = !empty($payload['expires_at']) ? (string) $payload['expires_at'] : null;
            if (!$this->model->upsertBlockedIp($ip, $reason, $adminUserId, $expiresAt, true)) {
                return $this->error('UPDATE_FAILED', 'Could not block IP.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.security.ip_blocked', ['ip_address' => $ip, 'reason' => $reason, 'expires_at' => $expiresAt]);
            return ['success' => true, 'message' => 'IP blocked.'];
        }

        if ($action === 'unblock_ip') {
            $ip = (string) ($payload['ip_address'] ?? '');
            if (!$this->model->unblockIp($ip)) {
                return $this->error('UPDATE_FAILED', 'Could not unblock IP.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.security.ip_unblocked', ['ip_address' => $ip]);
            return ['success' => true, 'message' => 'IP unblocked.'];
        }

        return $this->error('INVALID_ACTION', 'Unsupported security action.', 400);
    }

    public function getPlansPageData(): array
    {
        return [
            'plans' => $this->model->getPlans(),
            'plan_limits' => $this->model->getPlanLimits(),
            'coupons' => $this->model->getCoupons(200),
        ];
    }

    public function handlePlansAction(int $adminUserId, string $action, array $payload): array
    {
        $action = strtolower(trim($action));

        if ($action === 'update_plan_limit') {
            $planType = (string) ($payload['plan_type'] ?? 'free');
            $ok = $this->model->updatePlanLimit($planType, [
                'projects_limit' => (int) ($payload['projects_limit'] ?? 1),
                'keywords_limit' => (int) ($payload['keywords_limit'] ?? 5),
                'api_calls_daily' => (int) ($payload['api_calls_daily'] ?? 250),
                'insights_limit' => (int) ($payload['insights_limit'] ?? 3),
                'ai_monthly_limit' => (int) ($payload['ai_monthly_limit'] ?? 3),
                'can_export' => !empty($payload['can_export']),
                'can_manual_refresh' => !empty($payload['can_manual_refresh']),
            ]);
            if (!$ok) {
                return $this->error('UPDATE_FAILED', 'Could not update plan limit.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.plan.limit_updated', ['plan_type' => $planType]);
            return ['success' => true, 'message' => 'Plan limit updated.'];
        }

        if ($action === 'update_plan_pricing') {
            $planCode = (string) ($payload['plan_code'] ?? 'free');
            $ok = $this->model->updatePlan($planCode, [
                'display_name' => (string) ($payload['display_name'] ?? ucfirst($planCode)),
                'price_monthly' => (float) ($payload['price_monthly'] ?? 0),
                'price_yearly' => (float) ($payload['price_yearly'] ?? 0),
                'is_active' => !empty($payload['is_active']),
                'description' => (string) ($payload['description'] ?? ''),
            ]);
            if (!$ok) {
                return $this->error('UPDATE_FAILED', 'Could not update plan pricing.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.plan.pricing_updated', ['plan_code' => $planCode]);
            return ['success' => true, 'message' => 'Plan pricing updated.'];
        }

        if ($action === 'save_coupon') {
            $ok = $this->model->createCoupon([
                'code' => (string) ($payload['code'] ?? ''),
                'discount_type' => (string) ($payload['discount_type'] ?? 'percent'),
                'discount_value' => (float) ($payload['discount_value'] ?? 0),
                'max_uses' => isset($payload['max_uses']) ? (int) $payload['max_uses'] : null,
                'expires_at' => !empty($payload['expires_at']) ? (string) $payload['expires_at'] : null,
                'plan_scope' => (string) ($payload['plan_scope'] ?? 'all'),
                'is_active' => !empty($payload['is_active']),
                'metadata' => ['note' => (string) ($payload['note'] ?? '')],
            ]);
            if (!$ok) {
                return $this->error('SAVE_FAILED', 'Could not save coupon.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.coupon.saved', ['code' => (string) ($payload['code'] ?? '')]);
            return ['success' => true, 'message' => 'Coupon saved.'];
        }

        if ($action === 'toggle_coupon') {
            $couponId = (int) ($payload['coupon_id'] ?? 0);
            $isActive = !empty($payload['is_active']);
            if ($couponId <= 0 || !$this->model->setCouponStatus($couponId, $isActive)) {
                return $this->error('UPDATE_FAILED', 'Could not update coupon status.', 500);
            }
            $this->auditLogService->log($adminUserId, 'admin.coupon.status_changed', ['coupon_id' => $couponId, 'is_active' => $isActive]);
            return ['success' => true, 'message' => 'Coupon status updated.'];
        }

        return $this->error('INVALID_ACTION', 'Unsupported plans action.', 400);
    }

    public function getFeatureFlagsPageData(): array
    {
        return [
            'feature_flags' => $this->model->getFeatureFlags(),
        ];
    }

    public function handleFeatureFlagAction(int $adminUserId, string $action, array $payload): array
    {
        $action = strtolower(trim($action));

        if (!in_array($action, ['save_feature_flag', 'toggle_feature_flag'], true)) {
            return $this->error('INVALID_ACTION', 'Unsupported feature flag action.', 400);
        }

        $flagKey = (string) ($payload['flag_key'] ?? '');
        if ($flagKey === '') {
            return $this->error('INVALID_FLAG', 'Feature flag key is required.', 422);
        }

        $ok = $this->model->upsertFeatureFlag($flagKey, [
            'flag_name' => (string) ($payload['flag_name'] ?? $flagKey),
            'description' => (string) ($payload['description'] ?? ''),
            'is_enabled' => !empty($payload['is_enabled']),
            'rollout_plan' => (string) ($payload['rollout_plan'] ?? 'all'),
        ]);

        if (!$ok) {
            return $this->error('SAVE_FAILED', 'Could not save feature flag.', 500);
        }

        $this->auditLogService->log($adminUserId, 'admin.feature_flag.updated', [
            'flag_key' => $flagKey,
            'is_enabled' => !empty($payload['is_enabled']),
            'rollout_plan' => (string) ($payload['rollout_plan'] ?? 'all'),
        ]);

        return ['success' => true, 'message' => 'Feature flag saved.'];
    }

    public function enforceSecurityOnLogin(?string $email, ?string $ipAddress, ?string $userAgent): array
    {
        $settings = $this->model->getSecuritySettings();
        $limit = max(1, (int) ($settings['failed_login_limit'] ?? 5));
        $ipAddress = $ipAddress !== null ? trim($ipAddress) : '';
        $ipBlockingEnabled = !array_key_exists('ip_blocking_enabled', $settings)
            || in_array(strtolower(trim((string) $settings['ip_blocking_enabled'])), ['1', 'true', 'yes', 'on'], true);

        if ($ipBlockingEnabled && $ipAddress !== '' && $this->model->isIpBlocked($ipAddress)) {
            $this->model->logFailedLogin($email, $ipAddress, $userAgent, true);
            return [
                'allowed' => false,
                'error' => 'Your IP is blocked. Contact support.',
                'status' => 403,
            ];
        }

        $failedLogins = $this->model->getFailedLogins(200);
        $recentCount = 0;
        $cutoff = strtotime('-15 minutes');
        foreach ($failedLogins as $row) {
            if (($row['ip_address'] ?? null) !== $ipAddress) {
                continue;
            }
            $attemptedAt = strtotime((string) ($row['attempted_at'] ?? ''));
            if ($attemptedAt === false || $attemptedAt < $cutoff) {
                continue;
            }
            $recentCount++;
        }

        if ($ipBlockingEnabled && $recentCount >= $limit && $ipAddress !== '') {
            $this->model->upsertBlockedIp($ipAddress, 'Auto blocked due to failed login threshold', null, date('Y-m-d H:i:s', strtotime('+1 hour')), true);
            $this->model->logFailedLogin($email, $ipAddress, $userAgent, true);
            return [
                'allowed' => false,
                'error' => 'Too many failed login attempts. Try again later.',
                'status' => 429,
            ];
        }

        return ['allowed' => true];
    }

    public function trackFailedLogin(?string $email, ?string $ipAddress, ?string $userAgent): void
    {
        $this->model->logFailedLogin($email, $ipAddress, $userAgent, false);
    }

    private function error(string $code, string $message, int $status = 400): array
    {
        return [
            'success' => false,
            'error_code' => $code,
            'error' => $message,
            'status' => $status,
        ];
    }

    private function canAssignRole(string $actorRole, string $targetRole): bool
    {
        $actorRole = strtolower(trim($actorRole));
        $targetRole = strtolower(trim($targetRole));

        $rank = [
            'user' => 1,
            'agency' => 2,
            'support_admin' => 3,
            'billing_admin' => 3,
            'admin' => 4,
            'super_admin' => 5,
        ];

        if (!isset($rank[$actorRole]) || !isset($rank[$targetRole])) {
            return false;
        }
        if ($actorRole !== 'super_admin' && $targetRole === 'super_admin') {
            return false;
        }

        return $rank[$actorRole] >= $rank[$targetRole];
    }
}
