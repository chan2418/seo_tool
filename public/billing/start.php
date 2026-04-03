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
    $_SESSION['billing_flash_error'] = 'Invalid plan selected.';
    header('Location: ../subscription');
    exit;
}

if (empty($_SESSION['user_id'])) {
    $next = 'billing/start?plan=' . rawurlencode($plan) . '&cycle=' . rawurlencode($cycle) . '&token=' . rawurlencode($token);
    header('Location: ../login?next=' . urlencode($next));
    exit;
}

$auth = AuthMiddleware::requireLogin(false);
AccountStatusMiddleware::ensureActive(false);
$userId = (int) ($auth['user_id'] ?? ($_SESSION['user_id'] ?? 0));

$csrfKey = 'billing_start_' . $plan . '_' . $cycle;
if (!CsrfMiddleware::validateToken($token, $csrfKey)) {
    $_SESSION['billing_flash_error'] = 'Request session expired. Please select your plan again.';
    header('Location: ../subscription');
    exit;
}

RateLimitMiddleware::enforce('billing_start_checkout', 12, 180, $userId, false);

$billingService = new BillingService();
$result = $billingService->requestManualContactForPlan($userId, $plan, $cycle);
if (empty($result['success'])) {
    $_SESSION['billing_flash_error'] = (string) ($result['error'] ?? 'Unable to submit your plan request right now.');
    header('Location: ../subscription');
    exit;
}

if (!empty($result['email_sent'])) {
    $_SESSION['billing_flash_success'] = 'For security purposes, online payment is not available for your account right now. Our security system will verify your account once, then we will proceed. A confirmation email has been sent to your registered email.';
} else {
    $_SESSION['billing_flash_success'] = 'For security purposes, online payment is not available for your account right now. Our security system will verify your account once, then we will proceed. We could not send the confirmation email right now.';
}
$_SESSION['billing_contact_message'] = (string) ($result['message'] ?? 'For security purposes, online payment is not available for your account right now. Our security system will verify your account once, then we will proceed. Our team will contact you soon.');
$_SESSION['billing_contact_plan'] = (string) ($result['plan_type'] ?? $plan);
$_SESSION['billing_contact_cycle'] = (string) ($result['billing_cycle'] ?? $cycle);
$_SESSION['billing_contact_email_sent'] = !empty($result['email_sent']) ? 1 : 0;
if (!empty($result['effective_plan'])) {
    $_SESSION['plan_type'] = strtolower((string) $result['effective_plan']);
}

$usageService = new UsageMonitoringService();
$usageService->logApiCall($userId, 'billing.manual_contact_request');

header('Location: contact-soon');
exit;
