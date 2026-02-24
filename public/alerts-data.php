<?php

session_start();

require_once __DIR__ . '/../services/AlertService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';
require_once __DIR__ . '/../middleware/RateLimitMiddleware.php';

header('Content-Type: application/json');

$auth = AuthMiddleware::requireLogin(true);
AccountStatusMiddleware::ensureActive(true);
$userId = (int) ($auth['user_id'] ?? 0);
RateLimitMiddleware::enforce('alerts_data', 80, 60, $userId, true);

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

$service = new AlertService();

try {
    switch ($action) {
        case 'load':
            $isRead = null;
            if (array_key_exists('is_read', $input) && $input['is_read'] !== null && $input['is_read'] !== '') {
                $isRead = ((int) $input['is_read'] === 1) ? 1 : 0;
            }

            $result = $service->getAlertsForPage($userId, $planType, [
                'page' => isset($input['page']) ? (int) $input['page'] : 1,
                'per_page' => isset($input['per_page']) ? (int) $input['per_page'] : 15,
                'project_id' => isset($input['project_id']) ? (int) $input['project_id'] : null,
                'severity' => isset($input['severity']) ? (string) $input['severity'] : null,
                'search' => isset($input['search']) ? (string) $input['search'] : '',
                'is_read' => $isRead,
            ]);
            break;

        case 'bell':
            $result = $service->getBellData($userId, $planType);
            break;

        case 'mark_read':
            $result = $service->markRead($userId, (int) ($input['alert_id'] ?? 0));
            break;

        case 'mark_all_read':
            $projectId = isset($input['project_id']) ? (int) $input['project_id'] : null;
            if ($projectId !== null && $projectId <= 0) {
                $projectId = null;
            }
            $result = $service->markAllRead($userId, $projectId);
            break;

        case 'get_settings':
            $result = $service->getSettings(
                $userId,
                (int) ($input['project_id'] ?? 0),
                $planType
            );
            break;

        case 'save_settings':
            $result = $service->saveSettings(
                $userId,
                (int) ($input['project_id'] ?? 0),
                $planType,
                [
                    'rank_drop_threshold' => (int) ($input['rank_drop_threshold'] ?? 10),
                    'seo_score_drop_threshold' => (int) ($input['seo_score_drop_threshold'] ?? 5),
                    'email_notifications_enabled' => !empty($input['email_notifications_enabled']),
                ]
            );
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
    error_log('alerts-data fatal: ' . $error->getMessage());
    $result = [
        'success' => false,
        'status' => 500,
        'error_code' => 'ALERTS_FATAL',
        'error' => 'Unexpected alerts error occurred.',
    ];
}

$status = (int) ($result['status'] ?? 200);
unset($result['status']);

http_response_code($status);
echo json_encode($result);
