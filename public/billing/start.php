<?php
session_start();

require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/AccountStatusMiddleware.php';
require_once __DIR__ . '/../../middleware/RateLimitMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../../services/BillingService.php';
require_once __DIR__ . '/../../services/UsageMonitoringService.php';

function billing_normalize_plan(string $plan): string
{
    $plan = strtolower(trim($plan));
    if (!in_array($plan, ['pro', 'agency'], true)) {
        return '';
    }
    return $plan;
}

function billing_normalize_cycle(string $cycle): string
{
    $cycle = strtolower(trim($cycle));
    if (!in_array($cycle, ['monthly', 'annual'], true)) {
        return 'monthly';
    }
    return $cycle;
}

$plan = billing_normalize_plan((string) ($_GET['plan'] ?? ''));
$cycle = billing_normalize_cycle((string) ($_GET['cycle'] ?? 'monthly'));
$token = trim((string) ($_GET['token'] ?? ''));

if ($plan === '') {
    $_SESSION['billing_flash_error'] = 'Invalid plan selected for checkout.';
    header('Location: ../subscription.php');
    exit;
}

if (empty($_SESSION['user_id'])) {
    $next = 'billing/start.php?plan=' . rawurlencode($plan) . '&cycle=' . rawurlencode($cycle) . '&token=' . rawurlencode($token);
    header('Location: ../login.php?next=' . urlencode($next));
    exit;
}

$auth = AuthMiddleware::requireLogin(false);
AccountStatusMiddleware::ensureActive(false);
$userId = (int) ($auth['user_id'] ?? ($_SESSION['user_id'] ?? 0));

$csrfKey = 'billing_start_' . $plan . '_' . $cycle;
if (!CsrfMiddleware::validateToken($token, $csrfKey)) {
    $_SESSION['billing_flash_error'] = 'Checkout session expired. Please select your plan again.';
    header('Location: ../subscription.php');
    exit;
}

RateLimitMiddleware::enforce('billing_start_checkout', 12, 180, $userId, false);

$billingService = new BillingService();
$result = $billingService->createSubscriptionForPlan($userId, $plan, $cycle);
if (empty($result['success'])) {
    $_SESSION['billing_flash_error'] = (string) ($result['error'] ?? 'Unable to start checkout right now.');
    header('Location: ../subscription.php');
    exit;
}

$shortUrl = trim((string) ($result['gateway']['short_url'] ?? ''));
if ($shortUrl === '' || !filter_var($shortUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $shortUrl)) {
    $_SESSION['billing_flash_error'] = 'Checkout URL was not returned by billing provider.';
    header('Location: ../subscription.php');
    exit;
}

$usageService = new UsageMonitoringService();
$usageService->logApiCall($userId, 'billing.start_redirect');

header('Location: ' . $shortUrl);
exit;
