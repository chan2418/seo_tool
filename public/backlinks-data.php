<?php

session_start();

require_once __DIR__ . '/../services/BacklinkService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';
require_once __DIR__ . '/../middleware/RateLimitMiddleware.php';

header('Content-Type: application/json');

$auth = AuthMiddleware::requireLogin(true);
AccountStatusMiddleware::ensureActive(true);
$userId = (int) ($auth['user_id'] ?? 0);
RateLimitMiddleware::enforce('backlinks_data', 10, 60, $userId, true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed.',
        'error_code' => 'METHOD_NOT_ALLOWED',
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON payload.',
        'error_code' => 'INVALID_JSON',
    ]);
    exit;
}
$domain = (string) ($input['domain'] ?? '');

$service = new BacklinkService();
$result = $service->overview($domain, $userId, (string) ($auth['plan_type'] ?? 'free'));

$status = (int) ($result['status'] ?? 200);
unset($result['status']);

http_response_code($status);
echo json_encode($result);
