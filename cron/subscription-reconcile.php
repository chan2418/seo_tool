<?php

require_once __DIR__ . '/../services/BillingService.php';
require_once __DIR__ . '/../services/UsageMonitoringService.php';
require_once __DIR__ . '/../utils/Env.php';

Env::load(__DIR__ . '/../.env');
$cronStartedAt = date('Y-m-d H:i:s');

$isCli = php_sapi_name() === 'cli';
$cronToken = trim((string) Env::get('SUBSCRIPTION_CRON_TOKEN', ''));

if (!$isCli) {
    if ($cronToken === '') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'SUBSCRIPTION_CRON_TOKEN is not configured.',
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

$billingService = new BillingService();
$usageMonitoring = new UsageMonitoringService();
$result = $billingService->reconcilePastDueSubscriptions();

$storageDir = __DIR__ . '/../storage';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0777, true);
}
$logLine = date('Y-m-d H:i:s') . ' ' . json_encode([
    'processed' => (int) ($result['processed'] ?? 0),
    'downgraded' => (int) ($result['downgraded'] ?? 0),
    'failed' => (int) ($result['failed'] ?? 0),
], JSON_UNESCAPED_SLASHES);
file_put_contents($storageDir . '/subscription_reconcile.log', $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);

$usageMonitoring->logCronExecution('subscription-reconcile', true, [
    'processed' => (int) ($result['processed'] ?? 0),
    'downgraded' => (int) ($result['downgraded'] ?? 0),
    'failed' => (int) ($result['failed'] ?? 0),
    'started_at' => $cronStartedAt,
    'finished_at' => date('Y-m-d H:i:s'),
]);

if ($isCli) {
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

header('Content-Type: application/json');
echo json_encode($result);
