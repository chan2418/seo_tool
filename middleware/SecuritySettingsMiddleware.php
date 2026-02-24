<?php

require_once __DIR__ . '/../models/AdminControlModel.php';
require_once __DIR__ . '/RoleMiddleware.php';

class SecuritySettingsMiddleware
{
    private static ?array $settingsCache = null;

    public static function enforceIpNotBlocked(bool $jsonResponse = false): void
    {
        $model = new AdminControlModel();
        if (!$model->hasConnection()) {
            return;
        }

        $settings = self::settings();
        $ipBlockingEnabled = !array_key_exists('ip_blocking_enabled', $settings)
            || in_array(strtolower(trim((string) $settings['ip_blocking_enabled'])), ['1', 'true', 'yes', 'on'], true);
        if (!$ipBlockingEnabled) {
            return;
        }

        $ip = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
        if ($ip === '') {
            return;
        }

        if (!$model->isIpBlocked($ip)) {
            return;
        }

        if ($jsonResponse) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error_code' => 'IP_BLOCKED',
                'error' => 'Your IP address is blocked.',
            ]);
            exit;
        }

        http_response_code(403);
        echo 'Your IP address is blocked.';
        exit;
    }

    public static function enforceMaintenanceMode(bool $jsonResponse = false): void
    {
        $settings = self::settings();
        $enabled = !empty($settings['maintenance_mode']) && $settings['maintenance_mode'] !== '0';
        if (!$enabled) {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $role = RoleMiddleware::currentRole();
        if (RoleMiddleware::isPrivilegedAdmin($role)) {
            return;
        }

        if ($jsonResponse) {
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error_code' => 'MAINTENANCE_MODE',
                'error' => 'Service is temporarily under maintenance.',
            ]);
            exit;
        }

        http_response_code(503);
        echo 'Service is temporarily under maintenance.';
        exit;
    }

    public static function enforceRegistrationEnabled(): void
    {
        $settings = self::settings();
        $enabled = !array_key_exists('registration_enabled', $settings) || $settings['registration_enabled'] === '1';
        if ($enabled) {
            return;
        }

        http_response_code(503);
        echo 'New registration is temporarily disabled by admin.';
        exit;
    }

    private static function settings(): array
    {
        if (is_array(self::$settingsCache)) {
            return self::$settingsCache;
        }

        $model = new AdminControlModel();
        if (!$model->hasConnection()) {
            self::$settingsCache = [];
            return self::$settingsCache;
        }

        self::$settingsCache = (array) $model->getSecuritySettings();
        return self::$settingsCache;
    }
}
