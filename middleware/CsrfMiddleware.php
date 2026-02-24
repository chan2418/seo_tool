<?php

class CsrfMiddleware
{
    public static function generateToken(string $key = 'csrf_token'): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $token = bin2hex(random_bytes(24));
        $_SESSION[$key] = [
            'token' => $token,
            'created_at' => time(),
        ];

        return $token;
    }

    public static function validateToken(string $token, string $key = 'csrf_token', int $ttlSeconds = 7200): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $stored = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        if (!is_array($stored)) {
            return false;
        }

        $expected = (string) ($stored['token'] ?? '');
        $createdAt = (int) ($stored['created_at'] ?? 0);
        if ($expected === '' || $createdAt <= 0 || $token === '') {
            return false;
        }
        if ((time() - $createdAt) > max(60, $ttlSeconds)) {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public static function requirePostToken(
        string $fieldName = 'csrf_token',
        string $sessionKey = 'csrf_token',
        bool $jsonResponse = false
    ): void {
        $token = trim((string) ($_POST[$fieldName] ?? ''));
        if (self::validateToken($token, $sessionKey)) {
            return;
        }

        if ($jsonResponse) {
            http_response_code(419);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error_code' => 'CSRF_FAILED',
                'error' => 'Security token validation failed.',
            ]);
            exit;
        }

        http_response_code(419);
        echo 'Security token validation failed.';
        exit;
    }
}

