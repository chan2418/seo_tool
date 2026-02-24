<?php

require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/SecuritySettingsMiddleware.php';
require_once __DIR__ . '/../services/AdminTwoFactorService.php';
require_once __DIR__ . '/RoleMiddleware.php';

class AuthMiddleware
{
    public static function requireLogin(bool $jsonResponse = false): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        SecuritySettingsMiddleware::enforceIpNotBlocked($jsonResponse);
        SecuritySettingsMiddleware::enforceMaintenanceMode($jsonResponse);

        if (!isset($_SESSION['user_id'])) {
            if ($jsonResponse) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error_code' => 'UNAUTHORIZED',
                    'error' => 'Please login first.',
                ]);
                exit;
            }

            header('Location: ' . self::loginUrl());
            exit;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            self::unauthorized($jsonResponse);
        }

        $userModel = new UserModel();
        $user = $userModel->getUserById($userId);
        if (!$user) {
            self::unauthorized($jsonResponse);
        }

        if (!empty($user['is_deleted'])) {
            self::unauthorized($jsonResponse);
        }

        $forceLogoutAfter = (string) ($user['force_logout_after'] ?? '');
        if ($forceLogoutAfter !== '') {
            $forceLogoutAtTs = strtotime($forceLogoutAfter);
            $authAt = (int) ($_SESSION['auth_at'] ?? 0);
            if ($forceLogoutAtTs !== false && $authAt > 0 && $authAt <= $forceLogoutAtTs) {
                self::unauthorized($jsonResponse);
            }
        }

        // Always refresh server-side profile from source of truth.
        $_SESSION['user_name'] = (string) ($user['name'] ?? 'User');
        $_SESSION['plan_type'] = strtolower((string) ($user['plan_type'] ?? 'free'));
        $_SESSION['role'] = strtolower((string) ($user['role'] ?? 'user'));
        $_SESSION['account_status'] = strtolower((string) ($user['status'] ?? 'active'));
        $_SESSION['force_password_reset'] = !empty($user['force_password_reset']);

        if (!empty($_SESSION['force_password_reset'])) {
            $script = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
            if ($script !== 'force-password-reset.php') {
                if ($jsonResponse) {
                    http_response_code(428);
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'error_code' => 'PASSWORD_RESET_REQUIRED',
                        'error' => 'Password reset is required before continuing.',
                    ]);
                    exit;
                }
                header('Location: ' . self::forcePasswordResetUrl());
                exit;
            }
        }

        $role = RoleMiddleware::currentRole();
        if (RoleMiddleware::isPrivilegedAdmin($role)) {
            $twoFactorService = new AdminTwoFactorService();
            if ($twoFactorService->isRequiredForRole($role)) {
                $verified = !empty($_SESSION['admin_2fa_verified']);
                $pending = !empty($_SESSION['admin_2fa_pending']);
                if (!$verified || $pending) {
                    if ($jsonResponse) {
                        http_response_code(428);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => false,
                            'error_code' => 'ADMIN_2FA_REQUIRED',
                            'error' => 'Admin two-factor verification is required.',
                        ]);
                        exit;
                    }

                    $script = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
                    if ($script !== 'admin-2fa.php') {
                        header('Location: ' . self::adminTwoFactorUrl());
                        exit;
                    }
                }
            }
        }

        return [
            'user_id' => $userId,
            'user_name' => (string) ($_SESSION['user_name'] ?? 'User'),
            'plan_type' => strtolower((string) ($_SESSION['plan_type'] ?? 'free')),
            'role' => strtolower((string) ($_SESSION['role'] ?? 'user')),
            'status' => strtolower((string) ($_SESSION['account_status'] ?? 'active')),
        ];
    }

    private static function unauthorized(bool $jsonResponse): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        if ($jsonResponse) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error_code' => 'UNAUTHORIZED',
                'error' => 'Please login first.',
            ]);
            exit;
        }

        header('Location: ' . self::loginUrl());
        exit;
    }

    private static function loginUrl(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $marker = '/public/';
        $pos = strpos($script, $marker);
        if ($pos !== false) {
            return substr($script, 0, $pos) . '/public/login.php';
        }
        return 'login.php';
    }

    private static function adminTwoFactorUrl(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $marker = '/public/';
        $pos = strpos($script, $marker);
        if ($pos !== false) {
            return substr($script, 0, $pos) . '/public/admin-2fa.php';
        }
        return 'admin-2fa.php';
    }

    private static function forcePasswordResetUrl(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $marker = '/public/';
        $pos = strpos($script, $marker);
        if ($pos !== false) {
            return substr($script, 0, $pos) . '/public/force-password-reset.php';
        }
        return 'force-password-reset.php';
    }
}
