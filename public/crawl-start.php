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
RateLimitMiddleware::enforce('crawler_start', 6, 60, $userId, true);

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
$url = (string) ($input['url'] ?? '');

try {
    $service = new CrawlerService();
    $result = $service->start($url, $userId, (string) ($auth['plan_type'] ?? 'free'));

    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);

    http_response_code($status);
    echo json_encode($result);
} catch (Throwable $error) {
    error_log('crawl-start endpoint fatal: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Crawl failed to initialize on server. Please run Phase 3 DB setup and retry.',
        'error_code' => 'CRAWL_START_FATAL',
    ]);
}
