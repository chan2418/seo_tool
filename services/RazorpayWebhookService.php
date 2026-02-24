<?php

require_once __DIR__ . '/../models/PaymentEventModel.php';
require_once __DIR__ . '/../models/AdminControlModel.php';
require_once __DIR__ . '/BillingService.php';
require_once __DIR__ . '/SystemLogService.php';
require_once __DIR__ . '/../utils/Env.php';

class RazorpayWebhookService
{
    private PaymentEventModel $paymentEventModel;
    private AdminControlModel $adminControlModel;
    private BillingService $billingService;
    private SystemLogService $logService;
    private string $webhookSecret;

    public function __construct(
        ?PaymentEventModel $paymentEventModel = null,
        ?BillingService $billingService = null,
        ?SystemLogService $logService = null
    ) {
        Env::load(dirname(__DIR__) . '/.env');
        $this->paymentEventModel = $paymentEventModel ?? new PaymentEventModel();
        $this->adminControlModel = new AdminControlModel();
        $this->billingService = $billingService ?? new BillingService();
        $this->logService = $logService ?? new SystemLogService();
        $this->webhookSecret = trim((string) Env::get('RAZORPAY_WEBHOOK_SECRET', ''));
    }

    public function handle(string $rawBody, string $signature): array
    {
        if ($rawBody === '') {
            return $this->error('EMPTY_PAYLOAD', 'Webhook payload is empty.', 400);
        }
        if ($this->webhookSecret === '') {
            return $this->error('WEBHOOK_NOT_CONFIGURED', 'Razorpay webhook secret is missing.', 500);
        }

        if (!$this->isSignatureValid($rawBody, $signature)) {
            $this->logService->warning('billing_webhook', 'Invalid Razorpay webhook signature.', []);
            return $this->error('INVALID_SIGNATURE', 'Webhook signature validation failed.', 401);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $this->error('INVALID_JSON', 'Webhook payload is not valid JSON.', 400);
        }

        $eventType = trim((string) ($payload['event'] ?? ''));
        $gatewayEventId = trim((string) ($payload['payload']['payment']['entity']['id'] ?? $payload['payload']['subscription']['entity']['id'] ?? ''));
        if ($gatewayEventId === '') {
            $gatewayEventId = trim((string) ($payload['created_at'] ?? '')) . '|' . $eventType;
        }

        $entitySubscription = (array) ($payload['payload']['subscription']['entity'] ?? []);
        $entityPayment = (array) ($payload['payload']['payment']['entity'] ?? []);
        $gatewaySubId = trim((string) ($entitySubscription['id'] ?? $entityPayment['subscription_id'] ?? ''));
        $planFromNotes = trim((string) ($entitySubscription['notes']['plan_type'] ?? $entityPayment['notes']['plan_type'] ?? ''));
        $nextBillingDate = isset($entitySubscription['current_end'])
            ? date('Y-m-d H:i:s', (int) $entitySubscription['current_end'])
            : null;

        $eventInsert = $this->paymentEventModel->createUnique(
            'razorpay',
            $gatewayEventId,
            $eventType !== '' ? $eventType : 'unknown',
            $payload,
            null,
            null
        );
        if (empty($eventInsert['success'])) {
            return $this->error('EVENT_STORE_FAILED', (string) ($eventInsert['error'] ?? 'Unable to store webhook event.'), 500);
        }
        if (empty($eventInsert['created'])) {
            return [
                'success' => true,
                'message' => 'Duplicate webhook event ignored.',
                'event_type' => $eventType,
            ];
        }

        $this->logPaymentEventRow($eventType, $entityPayment, $entitySubscription);

        $result = ['success' => true, 'event_type' => $eventType, 'message' => 'Event received.'];

        if (in_array($eventType, ['subscription.activated', 'subscription.charged', 'subscription.resumed'], true)) {
            if ($gatewaySubId !== '') {
                $apply = $this->billingService->applyPaymentSuccess($gatewaySubId, $planFromNotes, $nextBillingDate);
                if (empty($apply['success'])) {
                    return $this->error('PAYMENT_SUCCESS_PROCESSING_FAILED', (string) ($apply['error'] ?? 'Unable to process payment success.'), (int) ($apply['status'] ?? 500));
                }
                $result['billing'] = $apply;
            }
        } elseif (in_array($eventType, ['payment.failed', 'subscription.pending', 'subscription.halted'], true)) {
            if ($gatewaySubId !== '') {
                $apply = $this->billingService->applyPaymentFailure($gatewaySubId, 5);
                if (empty($apply['success'])) {
                    return $this->error('PAYMENT_FAILURE_PROCESSING_FAILED', (string) ($apply['error'] ?? 'Unable to process payment failure.'), (int) ($apply['status'] ?? 500));
                }
                $result['billing'] = $apply;
            }
        } elseif ($eventType === 'subscription.cancelled') {
            if ($gatewaySubId !== '') {
                $apply = $this->billingService->applyPaymentFailure($gatewaySubId, 3);
                if (!empty($apply['success'])) {
                    $result['billing'] = $apply;
                }
            }
        }

        $this->logService->info('billing_webhook', 'Processed Razorpay webhook event.', [
            'event_type' => $eventType,
            'gateway_subscription_id' => $gatewaySubId,
        ]);

        return $result;
    }

    private function logPaymentEventRow(string $eventType, array $entityPayment, array $entitySubscription): void
    {
        if (!$this->adminControlModel->hasConnection()) {
            return;
        }

        $amountPaise = (float) ($entityPayment['amount'] ?? 0);
        $amount = $amountPaise > 0 ? round($amountPaise / 100, 2) : 0.0;
        $currency = (string) ($entityPayment['currency'] ?? 'INR');
        $status = strtolower((string) ($entityPayment['status'] ?? $entitySubscription['status'] ?? 'pending'));
        $gatewayTransactionId = (string) ($entityPayment['id'] ?? $entitySubscription['id'] ?? '');
        $gatewaySubscriptionId = (string) ($entityPayment['subscription_id'] ?? $entitySubscription['id'] ?? '');
        $subscriptionId = null;
        $userId = null;

        $this->adminControlModel->createPaymentLog([
            'user_id' => $userId,
            'subscription_id' => $subscriptionId,
            'gateway' => 'razorpay',
            'gateway_transaction_id' => $gatewayTransactionId,
            'event_type' => $eventType !== '' ? $eventType : 'webhook_event',
            'amount' => $amount,
            'currency' => $currency !== '' ? $currency : 'INR',
            'payment_status' => $status !== '' ? $status : 'pending',
            'notes' => [
                'gateway_subscription_id' => $gatewaySubscriptionId,
            ],
        ]);
    }

    private function isSignatureValid(string $rawBody, string $signature): bool
    {
        $signature = trim($signature);
        if ($signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);
        return hash_equals($expected, $signature);
    }

    private function error(string $code, string $message, int $status): array
    {
        return [
            'success' => false,
            'status' => $status,
            'error_code' => $code,
            'error' => $message,
        ];
    }
}
