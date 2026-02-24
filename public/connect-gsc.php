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
$authService = new GoogleAuthService();
$searchConsoleService = new SearchConsoleService();

$returnTo = trim((string) ($_REQUEST['return_to'] ?? 'settings.php'));
if ($returnTo === '' || preg_match('/^https?:\/\//i', $returnTo)) {
    $returnTo = 'settings.php';
}

$setFlash = static function (string $type, string $message): void {
    $_SESSION['gsc_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
};

$redirectBack = static function (string $target) use ($setFlash): void {
    if (strpos($target, '.php') !== 0 && strpos($target, '/') !== 0) {
        $target = $target;
    }
    header('Location: ' . $target);
    exit;
};

$action = strtolower(trim((string) ($_REQUEST['action'] ?? 'connect')));
$projectId = (int) ($_REQUEST['project_id'] ?? 0);

if ($projectId <= 0) {
    $setFlash('error', 'Select a project before connecting Search Console.');
    $redirectBack($returnTo);
}

if ($action === 'disconnect') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $setFlash('error', 'Disconnect requires a secure POST request.');
        $redirectBack($returnTo);
    }

    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    if (!$authService->validateFormCsrfToken($csrfToken, 'gsc_disconnect_csrf')) {
        $setFlash('error', 'Security check failed. Please try again.');
        $redirectBack($returnTo);
    }

    $disconnected = $searchConsoleService->disconnectProject($userId, $projectId);
    if (empty($disconnected['success'])) {
        $setFlash('error', (string) ($disconnected['error'] ?? 'Unable to disconnect Search Console.'));
        $redirectBack($returnTo);
    }

    $setFlash('success', 'Search Console disconnected successfully.');
    $redirectBack($returnTo);
}

$projectContext = $searchConsoleService->getProjectsAndConnections($userId);
$projects = (array) ($projectContext['projects'] ?? []);
$projectFound = false;
foreach ($projects as $project) {
    if ((int) ($project['id'] ?? 0) === $projectId) {
        $projectFound = true;
        break;
    }
}

if (!$projectFound) {
    $setFlash('error', 'Project not found or you do not have access.');
    $redirectBack($returnTo);
}

if (!$authService->isConfigured()) {
    $setFlash('error', 'Google OAuth is not configured. Add GSC_CLIENT_ID, GSC_CLIENT_SECRET and GSC_REDIRECT_URI in .env.');
    $redirectBack($returnTo);
}

$authUrl = $authService->buildAuthorizationUrl($userId, $projectId);
header('Location: ' . $authUrl);
exit;
