<?php

session_start();

require_once __DIR__ . '/../services/GoogleSignInService.php';
require_once __DIR__ . '/../middleware/SecuritySettingsMiddleware.php';
require_once __DIR__ . '/../middleware/RateLimitMiddleware.php';

SecuritySettingsMiddleware::enforceIpNotBlocked(false);
SecuritySettingsMiddleware::enforceMaintenanceMode(false);

if (!function_exists('auth_sanitize_next_path')) {
    function auth_sanitize_next_path(string $next): string
    {
        $next = trim($next);
        if ($next === '' || str_contains($next, "\r") || str_contains($next, "\n")) {
            return '';
        }
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $next)) {
            return '';
        }
        $next = ltrim($next, '/');
        if ($next === '' || !preg_match('/^[a-zA-Z0-9_\\-\\/\\.\\?=&%#]+$/', $next)) {
            return '';
        }
        return $next;
    }
}

$nextRedirect = auth_sanitize_next_path((string) ($_GET['next'] ?? ''));
$nextQuery = $nextRedirect !== '' ? '?next=' . urlencode($nextRedirect) : '';
if ($nextRedirect !== '') {
    $_SESSION['oauth_next_redirect'] = $nextRedirect;
} else {
    unset($_SESSION['oauth_next_redirect']);
}

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . ($nextRedirect !== '' ? $nextRedirect : 'dashboard.php'));
    exit;
}

$mode = strtolower(trim((string) ($_GET['mode'] ?? 'login')));
if (!in_array($mode, ['login', 'register'], true)) {
    $mode = 'login';
}

if ($mode === 'register') {
    SecuritySettingsMiddleware::enforceRegistrationEnabled();
}

RateLimitMiddleware::enforce('auth_google_start', 30, 600, null, false);

$service = new GoogleSignInService();
$result = $service->buildAuthorizationUrl($mode);
if (empty($result['success']) || empty($result['url'])) {
    $_SESSION['auth_flash_error'] = (string) ($result['error'] ?? 'Google Sign-In is not configured.');
    header('Location: ' . ($mode === 'register' ? 'register.php' : 'login.php') . $nextQuery);
    exit;
}

header('Location: ' . (string) $result['url']);
exit;
