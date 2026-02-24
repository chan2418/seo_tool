<?php

require_once __DIR__ . '/../models/AdminModel.php';
require_once __DIR__ . '/SystemLogService.php';
require_once __DIR__ . '/AuditLogService.php';

class AdminService
{
    private AdminModel $adminModel;
    private SystemLogService $logService;
    private AuditLogService $auditLogService;

    public function __construct(
        ?AdminModel $adminModel = null,
        ?SystemLogService $logService = null,
        ?AuditLogService $auditLogService = null
    )
    {
        $this->adminModel = $adminModel ?? new AdminModel();
        $this->logService = $logService ?? new SystemLogService();
        $this->auditLogService = $auditLogService ?? new AuditLogService();
    }

    public function getDashboardData(): array
    {
        $metrics = $this->adminModel->getDashboardMetrics();
        $cronLogs = $this->adminModel->getCronLogs(50);
        $systemLogs = $this->adminModel->getRecentSystemLogs(80);
        $revenueTrend = $this->adminModel->getRevenueTrendLastMonths(6);

        return [
            'success' => true,
            'metrics' => $metrics,
            'cron_logs' => $cronLogs,
            'system_logs' => $systemLogs,
            'revenue_trend' => $revenueTrend,
        ];
    }

    public function getUsersPageData(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $search = trim((string) ($filters['search'] ?? ''));
        $offset = ($page - 1) * $perPage;

        $rows = $this->adminModel->getAdminUsers($perPage, $offset, $search);
        $total = (new UserModel())->countUsers($search);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;

        return [
            'success' => true,
            'users' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1,
            ],
            'search' => $search,
        ];
    }

    public function updateUserPlan(int $adminUserId, int $targetUserId, string $planType): array
    {
        if ($targetUserId <= 0) {
            return $this->error('INVALID_USER', 'Invalid target user.', 422);
        }

        $planType = strtolower(trim($planType));
        if (!in_array($planType, ['free', 'pro', 'agency'], true)) {
            return $this->error('INVALID_PLAN', 'Invalid plan type.', 422);
        }

        $updated = $this->adminModel->updateUserPlan($targetUserId, $planType);
        if (!$updated) {
            return $this->error('UPDATE_FAILED', 'Unable to update user plan.', 500);
        }

        $this->logService->warning('admin', 'Admin changed user plan.', [
            'admin_user_id' => $adminUserId,
            'target_user_id' => $targetUserId,
            'plan_type' => $planType,
        ], $adminUserId);
        $this->auditLogService->log(
            $adminUserId,
            'admin.user.plan_changed',
            ['plan_type' => $planType],
            $targetUserId
        );

        return ['success' => true, 'message' => 'User plan updated.'];
    }

    public function updateUserStatus(int $adminUserId, int $targetUserId, string $status, ?string $reason = null): array
    {
        if ($targetUserId <= 0) {
            return $this->error('INVALID_USER', 'Invalid target user.', 422);
        }

        $status = strtolower(trim($status));
        if (!in_array($status, ['active', 'suspended'], true)) {
            return $this->error('INVALID_STATUS', 'Invalid account status.', 422);
        }

        $updated = $this->adminModel->updateUserStatus($targetUserId, $status, $reason);
        if (!$updated) {
            return $this->error('UPDATE_FAILED', 'Unable to update user status.', 500);
        }

        $this->logService->warning('admin', 'Admin changed user status.', [
            'admin_user_id' => $adminUserId,
            'target_user_id' => $targetUserId,
            'status' => $status,
            'reason' => $reason,
        ], $adminUserId);
        $this->auditLogService->log(
            $adminUserId,
            'admin.user.status_changed',
            ['status' => $status, 'reason' => $reason],
            $targetUserId
        );

        return ['success' => true, 'message' => 'User status updated.'];
    }

    public function updateUserRole(int $adminUserId, int $targetUserId, string $role): array
    {
        if ($targetUserId <= 0) {
            return $this->error('INVALID_USER', 'Invalid target user.', 422);
        }
        $role = strtolower(trim($role));
        if (!in_array($role, ['user', 'agency', 'support_admin', 'billing_admin', 'admin', 'super_admin'], true)) {
            return $this->error('INVALID_ROLE', 'Invalid role.', 422);
        }

        $updated = $this->adminModel->updateUserRole($targetUserId, $role);
        if (!$updated) {
            return $this->error('UPDATE_FAILED', 'Unable to update user role.', 500);
        }

        $this->logService->warning('admin', 'Admin changed user role.', [
            'admin_user_id' => $adminUserId,
            'target_user_id' => $targetUserId,
            'role' => $role,
        ], $adminUserId);
        $this->auditLogService->log(
            $adminUserId,
            'admin.user.role_changed',
            ['role' => $role],
            $targetUserId
        );

        return ['success' => true, 'message' => 'User role updated.'];
    }

    private function error(string $code, string $message, int $status): array
    {
        return [
            'success' => false,
            'status' => $status,
            'error_code' => $code,
            'error' => $message,
        ];
    }
}
