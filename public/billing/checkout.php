<?php

session_start();

require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/AccountStatusMiddleware.php';
require_once __DIR__ . '/../../middleware/RateLimitMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../../services/BillingService.php';
require_once __DIR__ . '/../../services/UsageMonitoringService.php';

AuthMiddleware::requireLogin(true);
AccountStatusMiddleware::ensureActive(true);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error_code' => 'METHOD_NOT_ALLOWED',
        'error' => 'Only POST requests are supported.',
    ]);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
RateLimitMiddleware::enforce('billing_checkout', 20, 60, $userId, true);

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error_code' => 'INVALID_JSON',
        'error' => 'Invalid JSON payload.',
    ]);
    exit;
}

$csrf = trim((string) ($input['csrf_token'] ?? ''));
if (!CsrfMiddleware::validateToken($csrf, 'billing_csrf_token')) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'error_code' => 'CSRF_FAILED',
        'error' => 'Security token validation failed.',
    ]);
    exit;
}

$action = strtolower(trim((string) ($input['action'] ?? 'create')));
$billingService = new BillingService();
$usage = new UsageMonitoringService();

try {
    if ($action === 'create') {
        $planType = strtolower(trim((string) ($input['plan_type'] ?? 'pro')));
        $billingCycle = strtolower(trim((string) ($input['billing_cycle'] ?? 'monthly')));
        $result = $billingService->createSubscriptionForPlan($userId, $planType, $billingCycle);
        if (!empty($result['success'])) {
            $usage->logApiCall($userId, 'billing.create');
        }
    } elseif ($action === 'cancel') {
        $immediate = !empty($input['immediate']);
        $result = $billingService->cancelUserSubscription($userId, $immediate);
        if (!empty($result['success'])) {
            $usage->logApiCall($userId, 'billing.cancel');
        }
    } else {
        $result = [
            'success' => false,
            'status' => 400,
            'error_code' => 'INVALID_ACTION',
            'error' => 'Unsupported billing action.',
        ];
    }
} catch (Throwable $error) {
    $result = [
        'success' => false,
        'status' => 500,
        'error_code' => 'BILLING_FATAL',
        'error' => 'Unexpected billing error occurred.',
    ];
}

$status = (int) ($result['status'] ?? 200);
unset($result['status']);
http_response_code($status);
echo json_encode($result);
