<?php

require_once __DIR__ . '/../models/UserModel.php';

class AccountStatusMiddleware
{
    public static function ensureActive(bool $jsonResponse = false): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $model = new UserModel();
        $user = $model->getUserById($userId);
        $status = strtolower((string) ($user['status'] ?? 'active'));
        $_SESSION['account_status'] = $status;

        if ($status === 'active') {
            return;
        }

        if ($jsonResponse) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error_code' => 'ACCOUNT_SUSPENDED',
                'error' => 'Your account is suspended. Contact support.',
            ]);
            exit;
        }

        session_destroy();
        header('Location: ' . self::loginUrl('suspended'));
        exit;
    }

    private static function loginUrl(string $error = ''): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $marker = '/public/';
        $url = 'login.php';
        $pos = strpos($script, $marker);
        if ($pos !== false) {
            $url = substr($script, 0, $pos) . '/public/login.php';
        }

        if ($error !== '') {
            $url .= '?error=' . rawurlencode($error);
        }
        return $url;
    }
}
