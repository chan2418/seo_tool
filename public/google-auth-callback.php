<?php

session_start();

require_once __DIR__ . '/../services/GoogleSignInService.php';
require_once __DIR__ . '/../auth/AuthController.php';
require_once __DIR__ . '/../middleware/SecuritySettingsMiddleware.php';
require_once __DIR__ . '/../middleware/RateLimitMiddleware.php';

SecuritySettingsMiddleware::enforceIpNotBlocked(false);
SecuritySettingsMiddleware::enforceMaintenanceMode(false);
RateLimitMiddleware::enforce('auth_google_callback', 40, 600, null, false);

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

$nextRedirect = auth_sanitize_next_path((string) ($_SESSION['oauth_next_redirect'] ?? ''));
unset($_SESSION['oauth_next_redirect']);
$nextQuery = $nextRedirect !== '' ? '?next=' . urlencode($nextRedirect) : '';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . ($nextRedirect !== '' ? $nextRedirect : 'dashboard'));
    exit;
}

$service = new GoogleSignInService();

$defaultMode = strtolower(trim((string) ($_GET['mode'] ?? 'login')));
if (!in_array($defaultMode, ['login', 'register'], true)) {
    $defaultMode = 'login';
}

$redirectTarget = $defaultMode === 'register' ? 'register' : 'login';

if (!$service->isConfigured()) {
    $_SESSION['auth_flash_error'] = 'Google Sign-In is not configured. Set GOOGLE_AUTH_CLIENT_ID, GOOGLE_AUTH_CLIENT_SECRET, and GOOGLE_AUTH_REDIRECT_URI in .env.';
    header('Location: ' . $redirectTarget . $nextQuery);
    exit;
}

if (isset($_GET['error'])) {
    $errorMessage = trim((string) ($_GET['error_description'] ?? $_GET['error'] ?? 'Google authorization failed.'));
    $_SESSION['auth_flash_error'] = $errorMessage;
    header('Location: ' . $redirectTarget . $nextQuery);
    exit;
}

$state = (string) ($_GET['state'] ?? '');
$stateValidation = $service->validateState($state);
if (empty($stateValidation['success'])) {
    $_SESSION['auth_flash_error'] = (string) ($stateValidation['error'] ?? 'OAuth validation failed.');
    header('Location: ' . $redirectTarget . $nextQuery);
    exit;
}

$mode = strtolower(trim((string) ($stateValidation['mode'] ?? 'login')));
$redirectTarget = $mode === 'register' ? 'register' : 'login';

if ($mode === 'register') {
    SecuritySettingsMiddleware::enforceRegistrationEnabled();
}

$code = trim((string) ($_GET['code'] ?? ''));
if ($code === '') {
    $_SESSION['auth_flash_error'] = 'Google did not return authorization code.';
    header('Location: ' . $redirectTarget . $nextQuery);
    exit;
}

$tokenResult = $service->exchangeCodeForTokens($code);
if (empty($tokenResult['success'])) {
    $_SESSION['auth_flash_error'] = (string) ($tokenResult['error'] ?? 'Google token exchange failed.');
    header('Location: ' . $redirectTarget . $nextQuery);
    exit;
}

$profileResult = $service->fetchUserProfile((string) ($tokenResult['access_token'] ?? ''));
if (empty($profileResult['success'])) {
    $_SESSION['auth_flash_error'] = (string) ($profileResult['error'] ?? 'Failed to fetch Google account profile.');
    header('Location: ' . $redirectTarget . $nextQuery);
    exit;
}

$auth = new AuthController();
$loginResult = $auth->loginWithGoogle((array) ($profileResult['profile'] ?? []), $mode);
if (empty($loginResult['success'])) {
    $_SESSION['auth_flash_error'] = (string) ($loginResult['error'] ?? 'Unable to sign in with Google.');
    header('Location: ' . $redirectTarget . $nextQuery);
    exit;
}

if (!empty($loginResult['created'])) {
    $_SESSION['auth_flash_success'] = 'Account created with Google and signed in successfully.';
} else {
    $_SESSION['auth_flash_success'] = 'Signed in with Google successfully.';
}

if (!empty($loginResult['requires_2fa'])) {
    if ($nextRedirect !== '') {
        $_SESSION['post_auth_next'] = $nextRedirect;
    }
    header('Location: admin-2fa');
    exit;
}

header('Location: ' . ($nextRedirect !== '' ? $nextRedirect : 'dashboard'));
exit;
