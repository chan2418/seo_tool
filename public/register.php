<?php
require_once __DIR__ . '/../auth/AuthController.php';
require_once __DIR__ . '/../services/GoogleSignInService.php';
require_once __DIR__ . '/../middleware/SecuritySettingsMiddleware.php';
require_once __DIR__ . '/../middleware/RateLimitMiddleware.php';
require_once __DIR__ . '/../utils/Env.php';

SecuritySettingsMiddleware::enforceIpNotBlocked(false);
SecuritySettingsMiddleware::enforceMaintenanceMode(false);
SecuritySettingsMiddleware::enforceRegistrationEnabled();

Env::load(__DIR__ . '/../.env');

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

$nextRedirect = auth_sanitize_next_path((string) ($_POST['next'] ?? $_GET['next'] ?? ''));
$nextQuery = $nextRedirect !== '' ? '&next=' . urlencode($nextRedirect) : '';

if (isset($_SESSION['user_id'])) {
    header("Location: " . ($nextRedirect !== '' ? $nextRedirect : 'dashboard'));
    exit;
}

$googleSignInService = new GoogleSignInService();
$googleSignInEnabled = $googleSignInService->isConfigured();

$flashError = '';
if (!empty($_SESSION['auth_flash_error'])) {
    $flashError = (string) $_SESSION['auth_flash_error'];
    unset($_SESSION['auth_flash_error']);
}

$flashSuccess = '';
if (!empty($_SESSION['auth_flash_success'])) {
    $flashSuccess = (string) $_SESSION['auth_flash_success'];
    unset($_SESSION['auth_flash_success']);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    RateLimitMiddleware::enforce('auth_register', 12, 3600, null, false);
    $auth = new AuthController();
    $result = $auth->register($_POST['name'], $_POST['email'], $_POST['password']);
    if (isset($result['success'])) {
        header("Location: " . ($nextRedirect !== '' ? $nextRedirect : 'dashboard'));
        exit;
    } else {
        $error = $result['error'];
    }
}

$seoScheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$seoHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
$seoBaseUrl = trim((string) Env::get('APP_URL', ''));
if ($seoBaseUrl === '' && $seoHost !== '') {
    $seoBaseUrl = $seoScheme . '://' . $seoHost;
}
$seoBaseUrl = rtrim($seoBaseUrl, '/');
$seoCanonical = $seoBaseUrl !== '' ? $seoBaseUrl . '/register' : '/register';
$seoTitle = 'Create Your Serponiq Account | Start Free SEO Audits';
$seoDescription = 'Create a Serponiq account to run AI-powered SEO audits, analyze keywords, and track technical SEO performance.';
$seoImage = $seoBaseUrl !== '' ? $seoBaseUrl . '/assets/images/logo-256.png' : 'assets/images/logo-256.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="assets/images/favicon-180.png">
    <title><?php echo htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($seoDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="robots" content="noindex,follow">
    <link rel="canonical" href="<?php echo htmlspecialchars($seoCanonical, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Serponiq">
    <meta property="og:title" content="<?php echo htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($seoDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($seoCanonical, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($seoImage, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($seoDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($seoImage, ENT_QUOTES, 'UTF-8'); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full bg-white p-8 rounded-lg shadow-md">
        <h2 class="text-2xl font-bold text-center mb-6">Create Account</h2>
        
        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4 text-sm">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4 text-sm">
                <?php echo htmlspecialchars($flashError); ?>
            </div>
        <?php endif; ?>
        <?php if ($flashSuccess): ?>
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded mb-4 text-sm">
                <?php echo htmlspecialchars($flashSuccess); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="next" value="<?php echo htmlspecialchars($nextRedirect, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Full Name</label>
                <input type="text" name="name" required class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Email</label>
                <input type="email" name="email" required class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="mb-6">
                <label class="block text-gray-700 text-sm font-bold mb-2">Password</label>
                <input type="password" name="password" required class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <button type="submit" class="w-full bg-green-600 text-white py-2 rounded hover:bg-green-700 transition">Register</button>
        </form>

        <div class="my-4 flex items-center gap-3">
            <div class="h-px flex-1 bg-gray-200"></div>
            <span class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-400">or</span>
            <div class="h-px flex-1 bg-gray-200"></div>
        </div>

        <?php if ($googleSignInEnabled): ?>
            <a href="/google-auth?mode=register<?php echo htmlspecialchars($nextQuery, ENT_QUOTES, 'UTF-8'); ?>" class="w-full inline-flex items-center justify-center gap-3 border border-gray-300 py-2 rounded hover:bg-gray-50 transition text-sm font-semibold text-gray-700">
                <svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.3-1.5 3.8-5.5 3.8-3.3 0-6-2.7-6-6s2.7-6 6-6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.7 3 14.6 2 12 2 6.9 2 2.8 6.1 2.8 11.2S6.9 20.4 12 20.4c6.9 0 9.2-4.8 9.2-7.3 0-.5-.1-.9-.1-1.3H12z"></path>
                </svg>
                Sign up with Google
            </a>
        <?php else: ?>
            <div class="w-full rounded border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">
                Google Sign-In not configured yet.
            </div>
        <?php endif; ?>
        <p class="text-center mt-4 text-sm">
            Already have an account? <a href="/login<?php echo $nextRedirect !== '' ? '?next=' . urlencode($nextRedirect) : ''; ?>" class="text-blue-600 hover:underline">Login</a>
        </p>
    </div>
</body>
</html>
