<?php

class RoleMiddleware
{
    private const VALID_ROLES = [
        'super_admin',
        'admin',
        'support_admin',
        'billing_admin',
        'agency',
        'user',
    ];

    private const ROLE_PERMISSIONS = [
        'super_admin' => ['*'],
        'admin' => [
            'admin.dashboard.view',
            'admin.users.view',
            'admin.users.manage',
            'admin.subscriptions.view',
            'admin.subscriptions.manage',
            'admin.revenue.view',
            'admin.system.view',
            'admin.logs.view',
            'admin.plans.manage',
            'admin.feature_flags.manage',
            'admin.security.view',
            'admin.security.manage',
        ],
        'support_admin' => [
            'admin.dashboard.view',
            'admin.users.view',
            'admin.users.suspend',
            'admin.users.reset_password',
            'admin.users.force_logout',
            'admin.logs.view',
            'admin.system.view',
            'admin.security.view',
        ],
        'billing_admin' => [
            'admin.dashboard.view',
            'admin.subscriptions.view',
            'admin.subscriptions.manage',
            'admin.payments.view',
            'admin.payments.refund',
            'admin.revenue.view',
            'admin.logs.view',
        ],
        'agency' => [],
        'user' => [],
    ];

    public static function requireRole($roles, bool $jsonResponse = false): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $currentRole = self::currentRole();
        $roles = is_array($roles) ? $roles : [$roles];
        $allowedRoles = [];
        foreach ($roles as $role) {
            $allowedRoles[] = self::normalizeRole((string) $role);
        }

        if (!in_array($currentRole, $allowedRoles, true)) {
            self::forbidden($jsonResponse, 'You do not have permission to access this resource.');
        }
    }

    public static function requirePermission($permissions, bool $jsonResponse = false): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $permissions = is_array($permissions) ? $permissions : [$permissions];
        $role = self::currentRole();
        foreach ($permissions as $permission) {
            if (self::hasPermission($role, (string) $permission)) {
                return;
            }
        }

        self::forbidden($jsonResponse, 'You do not have sufficient permission for this action.');
    }

    public static function hasPermission(string $role, string $permission): bool
    {
        $role = self::normalizeRole($role);
        $permission = trim(strtolower($permission));
        if ($permission === '') {
            return false;
        }

        $rolePermissions = self::ROLE_PERMISSIONS[$role] ?? [];
        if (in_array('*', $rolePermissions, true)) {
            return true;
        }

        return in_array($permission, $rolePermissions, true);
    }

    public static function currentRole(): string
    {
        $role = strtolower((string) ($_SESSION['role'] ?? 'user'));
        return self::normalizeRole($role);
    }

    public static function isPrivilegedAdmin(?string $role = null): bool
    {
        $role = self::normalizeRole($role ?? self::currentRole());
        return in_array($role, ['super_admin', 'admin', 'support_admin', 'billing_admin'], true);
    }

    public static function isAdminBypassRole(?string $role = null): bool
    {
        $role = self::normalizeRole($role ?? self::currentRole());
        return in_array($role, ['super_admin', 'admin'], true);
    }

    public static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        if (!in_array($role, self::VALID_ROLES, true)) {
            return 'user';
        }
        return $role;
    }

    public static function listValidRoles(): array
    {
        return self::VALID_ROLES;
    }

    private static function forbidden(bool $jsonResponse, string $message): void
    {
        if ($jsonResponse) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error_code' => 'FORBIDDEN',
                'error' => $message,
            ]);
            exit;
        }

        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
}
