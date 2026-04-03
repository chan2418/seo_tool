<?php

session_start();

require_once __DIR__ . '/../services/AIIntelligenceService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';
require_once __DIR__ . '/../middleware/RateLimitMiddleware.php';

header('Content-Type: application/json');

$auth = AuthMiddleware::requireLogin(true);
AccountStatusMiddleware::ensureActive(true);

$userId = (int) ($auth['user_id'] ?? 0);
$planType = (string) ($auth['plan_type'] ?? 'free');
$role = (string) ($auth['role'] ?? 'user');

RateLimitMiddleware::enforce('ai_data', 25, 60, $userId, true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error_code' => 'METHOD_NOT_ALLOWED',
        'error' => 'Only POST requests are supported.',
    ]);
    exit;
}

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

$action = strtolower(trim((string) ($input['action'] ?? 'load')));
$normalizedPlan = strtolower(trim($planType));

if ($action === 'submit' && $normalizedPlan === 'free') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error_code' => 'AI_UPGRADE_REQUIRED',
        'error' => 'AI requests are available on Pro or Agency plans. Free plan can view examples only.',
        'upgrade_required' => true,
    ]);
    exit;
}

$service = new AIIntelligenceService();

try {
    switch ($action) {
        case 'load':
            $result = $service->getPageData($userId, $planType, $role, [
                'project_id' => isset($input['project_id']) ? (int) $input['project_id'] : null,
            ]);
            break;

        case 'submit':
            $result = $service->submitRequest($userId, $planType, $role, $input);
            break;

        case 'status':
            $result = $service->getRequestStatusForUser($userId, (int) ($input['request_id'] ?? 0));
            break;

        default:
            $result = [
                'success' => false,
                'status' => 400,
                'error_code' => 'INVALID_ACTION',
                'error' => 'Unsupported action.',
            ];
            break;
    }
} catch (Throwable $error) {
    error_log('ai-data fatal: ' . $error->getMessage());
    $result = [
        'success' => false,
        'status' => 500,
        'error_code' => 'AI_FATAL',
        'error' => 'Unexpected AI error occurred.',
    ];
}

$status = (int) ($result['status'] ?? 200);
unset($result['status']);

http_response_code($status);
echo json_encode($result);
