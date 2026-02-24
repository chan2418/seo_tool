<?php

session_start();

require_once __DIR__ . '/../services/KeywordService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';
require_once __DIR__ . '/../middleware/RateLimitMiddleware.php';

header('Content-Type: application/json');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

$auth = AuthMiddleware::requireLogin(true);
AccountStatusMiddleware::ensureActive(true);
$userId = (int) ($auth['user_id'] ?? 0);
RateLimitMiddleware::enforce('keyword_search', 20, 60, $userId, true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error_code' => 'METHOD_NOT_ALLOWED',
        'error' => 'Only POST requests are supported.',
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput ?: '{}', true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error_code' => 'INVALID_JSON',
        'error' => 'Invalid JSON payload.',
    ]);
    exit;
}

$keyword = (string) ($input['keyword'] ?? '');
$page = isset($input['page']) ? (int) $input['page'] : 1;
$perPage = isset($input['per_page']) ? (int) $input['per_page'] : 10;

$service = new KeywordService();
$result = $service->search(
    $keyword,
    $userId,
    (string) ($auth['plan_type'] ?? 'free'),
    $page,
    $perPage
);

$status = isset($result['status']) ? (int) $result['status'] : 200;
unset($result['status']);

http_response_code($status);
echo json_encode($result);
