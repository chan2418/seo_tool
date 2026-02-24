<?php

session_start();

require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../utils/Env.php';

Env::load(__DIR__ . '/../.env');
$auth = AuthMiddleware::requireLogin(false);
AccountStatusMiddleware::ensureActive(false);

$appEnv = strtolower(trim((string) Env::get('APP_ENV', 'production')));
$isDevEnv = in_array($appEnv, ['local', 'development', 'dev'], true);
$role = strtolower((string) ($auth['role'] ?? 'user'));

if (!$isDevEnv || !in_array($role, ['super_admin', 'admin'], true)) {
    http_response_code(403);
    echo 'Plan simulation is disabled in production.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['toggle_plan'])) {
    header('Location: subscription.php');
    exit;
}

$currentPlan = strtolower((string) ($_SESSION['plan_type'] ?? 'free'));
if ($currentPlan === 'free') {
    $newPlan = 'pro';
} elseif ($currentPlan === 'pro') {
    $newPlan = 'agency';
} else {
    $newPlan = 'free';
}

$userId = (int) ($auth['user_id'] ?? 0);
$userModel = new UserModel();
$userModel->updatePlanType($userId, $newPlan);
$_SESSION['plan_type'] = $newPlan;

$redirectTo = $_SERVER['HTTP_REFERER'] ?? 'subscription.php';
header('Location: ' . $redirectTo);
exit;
