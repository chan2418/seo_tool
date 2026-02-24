<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../../middleware/AccountStatusMiddleware.php';
require_once __DIR__ . '/../../../middleware/RoleMiddleware.php';
require_once __DIR__ . '/../../../middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../../../services/AdminControlService.php';
require_once __DIR__ . '/../../../utils/CurrencyFormatter.php';

AuthMiddleware::requireLogin(false);
AccountStatusMiddleware::ensureActive(false);
RoleMiddleware::requireRole(['super_admin', 'admin', 'support_admin', 'billing_admin'], false);

$adminUserId = (int) ($_SESSION['user_id'] ?? 0);
$adminUserName = (string) ($_SESSION['user_name'] ?? 'Admin User');
$adminRole = RoleMiddleware::currentRole();
$adminControlService = new AdminControlService();

if (!function_exists('admin_role_label')) {
    function admin_role_label(string $role): string
    {
        return ucwords(str_replace('_', ' ', strtolower(trim($role))));
    }
}
