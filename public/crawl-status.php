<?php

session_start();

require_once __DIR__ . '/../services/CrawlerService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';
require_once __DIR__ . '/../middleware/RateLimitMiddleware.php';

header('Content-Type: application/json');

$auth = AuthMiddleware::requireLogin(true);
AccountStatusMiddleware::ensureActive(true);
$userId = (int) ($auth['user_id'] ?? 0);
RateLimitMiddleware::enforce('crawler_status', 120, 60, $userId, true);

$runId = isset($_GET['run_id']) ? (int) $_GET['run_id'] : 0;
if ($runId <= 0) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'run_id is required.',
        'error_code' => 'INVALID_INPUT',
    ]);
    exit;
}

$service = new CrawlerService();
$result = $service->status($runId, $userId, true);

$status = (int) ($result['status'] ?? 200);
unset($result['status']);

http_response_code($status);
echo json_encode($result);
