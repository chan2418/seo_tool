<?php

require_once __DIR__ . '/../services/AIIntelligenceService.php';
require_once __DIR__ . '/../services/UsageMonitoringService.php';
require_once __DIR__ . '/../utils/Env.php';

Env::load(__DIR__ . '/../.env');
$cronStartedAt = date('Y-m-d H:i:s');

$isCli = php_sapi_name() === 'cli';
$cronToken = trim((string) Env::get('AI_CRON_TOKEN', ''));

if (!$isCli) {
    if ($cronToken === '') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'AI_CRON_TOKEN is not configured.',
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

$batch = 3;
if ($isCli && isset($argv[1])) {
    $batch = max(1, min(6, (int) $argv[1]));
} elseif (isset($_GET['batch'])) {
    $batch = max(1, min(6, (int) $_GET['batch']));
}

$service = new AIIntelligenceService();
$usageMonitoring = new UsageMonitoringService();
$result = $service->processQueueBatch($batch);

$storageDir = __DIR__ . '/../storage';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0777, true);
}

$logLine = date('Y-m-d H:i:s') . ' ' . json_encode([
    'processed' => (int) ($result['processed'] ?? 0),
    'failed' => (int) ($result['failed'] ?? 0),
    'stuck_recovered' => (int) ($result['stuck_recovered'] ?? 0),
    'processing_now' => (int) ($result['processing_now'] ?? 0),
], JSON_UNESCAPED_SLASHES);

file_put_contents($storageDir . '/ai_queue_cron.log', $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);
$usageMonitoring->logCronExecution('process-ai-queue', true, [
    'processed' => (int) ($result['processed'] ?? 0),
    'failed' => (int) ($result['failed'] ?? 0),
    'stuck_recovered' => (int) ($result['stuck_recovered'] ?? 0),
    'started_at' => $cronStartedAt,
    'finished_at' => date('Y-m-d H:i:s'),
]);

if ($isCli) {
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

header('Content-Type: application/json');
echo json_encode($result);
