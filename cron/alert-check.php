<?php

require_once __DIR__ . '/../services/AlertDetectionService.php';
require_once __DIR__ . '/../services/AlertService.php';
require_once __DIR__ . '/../services/UsageMonitoringService.php';
require_once __DIR__ . '/../utils/Env.php';

Env::load(__DIR__ . '/../.env');
$cronStartedAt = date('Y-m-d H:i:s');

$isCli = php_sapi_name() === 'cli';
$cronToken = trim((string) Env::get('ALERT_CRON_TOKEN', ''));

if (!$isCli) {
    if ($cronToken === '') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'ALERT_CRON_TOKEN is not configured.',
        ]);
        exit;
    }

    $requestToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
    if ($requestToken === '' || !hash_equals($cronToken, $requestToken)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Invalid cron token.',
        ]);
        exit;
    }
}

$maxUsers = 500;
$emailBatchLimit = 40;

if ($isCli) {
    if (isset($argv[1])) {
        $maxUsers = max(1, min(2000, (int) $argv[1]));
    }
    if (isset($argv[2])) {
        $emailBatchLimit = max(1, min(400, (int) $argv[2]));
    }
} else {
    if (isset($_GET['max_users'])) {
        $maxUsers = max(1, min(2000, (int) $_GET['max_users']));
    }
    if (isset($_GET['email_limit'])) {
        $emailBatchLimit = max(1, min(400, (int) $_GET['email_limit']));
    }
}

$detector = new AlertDetectionService();
$service = new AlertService();
$usageMonitoring = new UsageMonitoringService();

$detectionResult = $detector->runForAllUsers($maxUsers);
$emailResult = $service->processEmailQueue($emailBatchLimit);

$result = [
    'success' => true,
    'date' => date('Y-m-d'),
    'detection' => $detectionResult,
    'email_queue' => $emailResult,
];

$storageDir = __DIR__ . '/../storage';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0777, true);
}

$logLine = date('Y-m-d H:i:s') . ' ' . json_encode([
    'users_total' => (int) (($detectionResult['users_total'] ?? 0)),
    'users_processed' => (int) (($detectionResult['users_processed'] ?? 0)),
    'alerts_created' => (int) (($detectionResult['alerts_created'] ?? 0)),
    'email_processed' => (int) (($emailResult['processed'] ?? 0)),
    'email_sent' => (int) (($emailResult['sent'] ?? 0)),
    'email_failed' => (int) (($emailResult['failed'] ?? 0)),
], JSON_UNESCAPED_SLASHES);

file_put_contents($storageDir . '/alert_cron.log', $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);
$usageMonitoring->logCronExecution('alert-check', true, [
    'users_processed' => (int) (($detectionResult['users_processed'] ?? 0)),
    'alerts_created' => (int) (($detectionResult['alerts_created'] ?? 0)),
    'started_at' => $cronStartedAt,
    'finished_at' => date('Y-m-d H:i:s'),
]);

if ($isCli) {
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

header('Content-Type: application/json');
echo json_encode($result);
