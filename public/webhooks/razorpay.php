<?php

require_once __DIR__ . '/../../services/RazorpayWebhookService.php';
require_once __DIR__ . '/../../middleware/RateLimitMiddleware.php';

RateLimitMiddleware::enforce('webhook_razorpay', 120, 60, null, true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error_code' => 'METHOD_NOT_ALLOWED',
        'error' => 'Only POST is supported.',
    ]);
    exit;
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody)) {
    $rawBody = '';
}

$signature = '';
if (!empty($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'])) {
    $signature = (string) $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'];
} elseif (!empty($_SERVER['REDIRECT_HTTP_X_RAZORPAY_SIGNATURE'])) {
    $signature = (string) $_SERVER['REDIRECT_HTTP_X_RAZORPAY_SIGNATURE'];
}

$service = new RazorpayWebhookService();
$result = $service->handle($rawBody, $signature);

$status = (int) ($result['status'] ?? 200);
unset($result['status']);

http_response_code($status);
header('Content-Type: application/json');
echo json_encode($result);
