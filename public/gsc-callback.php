<?php
session_start();
require_once __DIR__ . '/../services/PlanEnforcementService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';

$auth = AuthMiddleware::requireLogin(false);
AccountStatusMiddleware::ensureActive(false);

require_once __DIR__ . '/../services/GoogleAuthService.php';
require_once __DIR__ . '/../services/SearchConsoleService.php';

$userId = (int) ($auth['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$planService = new PlanEnforcementService();
$planType = $planService->getEffectivePlan($userId, (string) ($_SESSION['plan_type'] ?? 'free'));
$userName = (string) ($auth['user_name'] ?? ($_SESSION['user_name'] ?? 'User'));

$authService = new GoogleAuthService();
$searchConsoleService = new SearchConsoleService();
$errorMessage = '';
$successMessage = '';
$properties = [];
$pending = $_SESSION['gsc_pending_connection'] ?? null;

$redirectToSettings = static function (string $type, string $message): void {
    $_SESSION['gsc_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
    header('Location: settings');
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && strtolower((string) ($_POST['action'] ?? '')) === 'save_property') {
    $pending = $_SESSION['gsc_pending_connection'] ?? null;
    if (!is_array($pending) || (int) ($pending['user_id'] ?? 0) !== $userId) {
        $redirectToSettings('error', 'OAuth session expired. Please reconnect Google Search Console.');
    }
    $createdAt = (int) ($pending['created_at'] ?? 0);
    if ($createdAt <= 0 || (time() - $createdAt) > 900) {
        $redirectToSettings('error', 'OAuth session timed out. Please reconnect Google Search Console.');
    }

    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!$authService->validateFormCsrfToken($csrf, 'gsc_property_select_csrf')) {
        $redirectToSettings('error', 'Security validation failed. Please try connecting again.');
    }

    $projectId = (int) ($pending['project_id'] ?? 0);
    $selectedProperty = trim((string) ($_POST['google_property'] ?? ''));
    if ($selectedProperty === '') {
        $selectedProperty = trim((string) ($_POST['google_property_manual'] ?? ''));
    }

    $saved = $searchConsoleService->saveConnectionFromOauth(
        $userId,
        $planType,
        $projectId,
        $selectedProperty,
        (string) ($pending['access_token'] ?? ''),
        (string) ($pending['refresh_token'] ?? ''),
        (int) ($pending['expires_in'] ?? 3600)
    );

    if (empty($saved['success'])) {
        $errorMessage = (string) ($saved['error'] ?? 'Unable to save Search Console connection.');
        $properties = (array) ($pending['properties'] ?? []);
    } else {
        unset($_SESSION['gsc_pending_connection']);
        $_SESSION['gsc_flash'] = [
            'type' => 'success',
            'message' => 'Google Search Console connected successfully.',
        ];
        header('Location: performance?project_id=' . $projectId);
        exit;
    }
} elseif (isset($_GET['error'])) {
    $message = (string) ($_GET['error_description'] ?? $_GET['error']);
    $redirectToSettings('error', 'Google authorization failed: ' . $message);
} elseif (isset($_GET['code']) && isset($_GET['state'])) {
    if (!$authService->isConfigured()) {
        $redirectToSettings('error', 'Google OAuth is not configured on server.');
    }

    $stateValidation = $authService->validateAndParseState((string) $_GET['state'], $userId);
    if (empty($stateValidation['success'])) {
        $redirectToSettings('error', (string) ($stateValidation['error'] ?? 'Invalid OAuth state.'));
    }

    $projectId = (int) ($stateValidation['project_id'] ?? 0);
    $tokens = $authService->exchangeCodeForTokens((string) $_GET['code']);
    if (empty($tokens['success'])) {
        $redirectToSettings('error', (string) ($tokens['error'] ?? 'Unable to exchange OAuth code.'));
    }

    $propertyResponse = $searchConsoleService->listPropertiesFromAccessToken((string) ($tokens['access_token'] ?? ''));
    if (empty($propertyResponse['success'])) {
        $errorMessage = (string) ($propertyResponse['error'] ?? 'Unable to fetch Search Console properties.');
    } else {
        $properties = (array) ($propertyResponse['properties'] ?? []);
    }

    $_SESSION['gsc_pending_connection'] = [
        'user_id' => $userId,
        'project_id' => $projectId,
        'access_token' => (string) ($tokens['access_token'] ?? ''),
        'refresh_token' => (string) ($tokens['refresh_token'] ?? ''),
        'expires_in' => (int) ($tokens['expires_in'] ?? 3600),
        'properties' => $properties,
        'created_at' => time(),
    ];

    $pending = $_SESSION['gsc_pending_connection'];
    $successMessage = 'Google account connected. Select the property to link with this project.';
} else {
    $redirectToSettings('error', 'Invalid callback request.');
}

$csrfToken = $authService->createFormCsrfToken('gsc_property_select_csrf');
$projectId = (int) (($pending['project_id'] ?? 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="assets/images/favicon-180.png">
    <title>Link Search Console Property</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
    <main class="mx-auto max-w-2xl px-4 py-10">
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-lg sm:p-8">
            <h1 class="text-2xl font-extrabold">Connect Google Search Console</h1>
            <p class="mt-2 text-sm text-slate-600">Hi <?php echo htmlspecialchars($userName); ?>, select the property to connect to your project.</p>

            <?php if ($successMessage !== ''): ?>
                <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                    <?php echo htmlspecialchars($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                    <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>

            <form method="post" class="mt-6 space-y-4">
                <input type="hidden" name="action" value="save_property">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <label class="block">
                    <span class="mb-2 block text-sm font-semibold">Project ID</span>
                    <input type="text" value="<?php echo (int) $projectId; ?>" disabled class="w-full rounded-xl border border-slate-300 bg-slate-100 px-3 py-2 text-sm">
                </label>

                <label class="block">
                    <span class="mb-2 block text-sm font-semibold">Google Property</span>
                    <?php if (!empty($properties)): ?>
                        <select name="google_property" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" required>
                            <option value="">Select property</option>
                            <?php foreach ($properties as $property): ?>
                                <option value="<?php echo htmlspecialchars((string) ($property['property'] ?? '')); ?>">
                                    <?php echo htmlspecialchars((string) ($property['property'] ?? '')); ?><?php echo !empty($property['permission']) ? ' (' . htmlspecialchars((string) $property['permission']) . ')' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input name="google_property_manual" type="text" placeholder="https://example.com/ or sc-domain:example.com" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" required>
                        <p class="mt-1 text-xs text-slate-500">Could not auto-load properties. Enter property manually.</p>
                    <?php endif; ?>
                </label>

                <div class="flex flex-wrap items-center gap-3 pt-2">
                    <button type="submit" class="rounded-xl bg-gradient-to-r from-indigo-600 to-indigo-500 px-5 py-2 text-sm font-semibold text-white">
                        Save Property
                    </button>
                    <a href="settings" class="rounded-xl border border-slate-300 px-5 py-2 text-sm font-semibold text-slate-700">Cancel</a>
                </div>
            </form>
        </section>
    </main>
</body>
</html>
