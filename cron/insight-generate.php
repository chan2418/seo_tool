<?php

require_once __DIR__ . '/../services/InsightEngineService.php';
require_once __DIR__ . '/../services/UsageMonitoringService.php';
require_once __DIR__ . '/../utils/Env.php';

Env::load(__DIR__ . '/../.env');
$cronStartedAt = date('Y-m-d H:i:s');

$isCli = php_sapi_name() === 'cli';
$cronToken = trim((string) Env::get('INSIGHT_CRON_TOKEN', Env::get('GSC_SYNC_CRON_TOKEN', '')));

if (!$isCli) {
    if ($cronToken === '') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'INSIGHT_CRON_TOKEN is not configured.',
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

$limit = 500;
if ($isCli && isset($argv[1])) {
    $limit = max(1, min(2500, (int) $argv[1]));
} elseif (isset($_GET['limit'])) {
    $limit = max(1, min(2500, (int) $_GET['limit']));
}

$service = new InsightEngineService();
$usageMonitoring = new UsageMonitoringService();
$result = $service->runScheduledGenerationForAllProjects($limit);

$storageDir = __DIR__ . '/../storage';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0777, true);
}

$logLine = date('Y-m-d H:i:s') . ' ' . json_encode([
    'connections_total' => (int) ($result['connections_total'] ?? 0),
    'processed' => (int) ($result['processed'] ?? 0),
    'skipped' => (int) ($result['skipped'] ?? 0),
    'failed' => (int) ($result['failed'] ?? 0),
    'insights_created' => (int) ($result['insights_created'] ?? 0),
], JSON_UNESCAPED_SLASHES);

file_put_contents($storageDir . '/insight_cron.log', $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);
$usageMonitoring->logCronExecution('insight-generate', true, [
    'processed' => (int) ($result['processed'] ?? 0),
    'insights_created' => (int) ($result['insights_created'] ?? 0),
    'started_at' => $cronStartedAt,
    'finished_at' => date('Y-m-d H:i:s'),
]);

if ($isCli) {
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

header('Content-Type: application/json');
echo json_encode($result);
