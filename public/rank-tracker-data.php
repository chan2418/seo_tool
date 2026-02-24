<?php

session_start();

require_once __DIR__ . '/../services/RankTrackerService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';
require_once __DIR__ . '/../middleware/RateLimitMiddleware.php';

header('Content-Type: application/json');

$auth = AuthMiddleware::requireLogin(true);
AccountStatusMiddleware::ensureActive(true);
$userId = (int) ($auth['user_id'] ?? 0);
RateLimitMiddleware::enforce('rank_tracker_data', 60, 60, $userId, true);

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
$planType = (string) ($auth['plan_type'] ?? 'free');

$service = new RankTrackerService();

try {
    switch ($action) {
        case 'load':
            $result = $service->getTrackerData($userId, $planType, [
                'project_id' => isset($input['project_id']) ? (int) $input['project_id'] : null,
                'status' => isset($input['status']) ? (string) $input['status'] : null,
                'sort_by' => isset($input['sort_by']) ? (string) $input['sort_by'] : 'current_rank',
                'sort_dir' => isset($input['sort_dir']) ? (string) $input['sort_dir'] : 'asc',
                'page' => isset($input['page']) ? (int) $input['page'] : 1,
                'per_page' => isset($input['per_page']) ? (int) $input['per_page'] : 10,
            ]);
            break;

        case 'add_keyword':
            $result = $service->addKeyword($userId, $planType, [
                'project_id' => (int) ($input['project_id'] ?? 0),
                'keyword' => (string) ($input['keyword'] ?? ''),
                'country' => (string) ($input['country'] ?? 'US'),
                'device_type' => (string) ($input['device_type'] ?? 'desktop'),
            ]);
            break;

        case 'delete_keyword':
            $result = $service->deleteKeyword($userId, (int) ($input['tracked_keyword_id'] ?? 0));
            break;

        case 'toggle_status':
            $result = $service->setKeywordStatus(
                $userId,
                (int) ($input['tracked_keyword_id'] ?? 0),
                (string) ($input['status'] ?? 'active')
            );
            break;

        case 'history':
            $result = $service->getKeywordHistory(
                $userId,
                (int) ($input['tracked_keyword_id'] ?? 0),
                (int) ($input['days'] ?? 30)
            );
            break;

        case 'run_check':
            $result = $service->runDailyChecks($userId, $planType, [
                'project_id' => isset($input['project_id']) ? (int) $input['project_id'] : null,
                'force' => !empty($input['force']),
                'limit' => isset($input['limit']) ? (int) $input['limit'] : 250,
            ]);
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
    error_log('rank-tracker-data fatal: ' . $error->getMessage());
    $result = [
        'success' => false,
        'status' => 500,
        'error_code' => 'RANK_TRACKER_FATAL',
        'error' => 'Unexpected rank tracker error occurred.',
    ];
}

$status = (int) ($result['status'] ?? 200);
unset($result['status']);

http_response_code($status);
echo json_encode($result);
