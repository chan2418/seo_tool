<?php

session_start();

require_once __DIR__ . '/../services/InsightEngineService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';
require_once __DIR__ . '/../middleware/RateLimitMiddleware.php';

header('Content-Type: application/json');

$auth = AuthMiddleware::requireLogin(true);
AccountStatusMiddleware::ensureActive(true);
$userId = (int) ($auth['user_id'] ?? 0);
RateLimitMiddleware::enforce('insights_data', 40, 60, $userId, true);

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

$service = new InsightEngineService();

try {
    switch ($action) {
        case 'load':
            $result = $service->getInsightsPageData(
                $userId,
                $planType,
                [
                    'project_id' => isset($input['project_id']) ? (int) $input['project_id'] : null,
                ]
            );
            break;

        case 'generate':
            $projectId = (int) ($input['project_id'] ?? 0);
            $generation = $service->generateInsightsForProject(
                $userId,
                $planType,
                $projectId,
                ['source' => 'manual']
            );

            if (empty($generation['success'])) {
                $result = $generation;
                break;
            }

            $pageData = $service->getInsightsPageData(
                $userId,
                $planType,
                ['project_id' => $projectId]
            );

            $result = [
                'success' => !empty($pageData['success']),
                'generation' => $generation,
                'data' => $pageData,
            ];
            if (empty($pageData['success'])) {
                $result['status'] = (int) ($pageData['status'] ?? 500);
                $result['error_code'] = (string) ($pageData['error_code'] ?? 'INSIGHTS_LOAD_FAILED');
                $result['error'] = (string) ($pageData['error'] ?? 'Failed to reload insights after generation.');
            }
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
    error_log('insights-data fatal: ' . $error->getMessage());
    $result = [
        'success' => false,
        'status' => 500,
        'error_code' => 'INSIGHTS_FATAL',
        'error' => 'Unexpected insights error occurred.',
    ];
}

$status = (int) ($result['status'] ?? 200);
unset($result['status']);

http_response_code($status);
echo json_encode($result);
