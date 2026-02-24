<?php

require_once __DIR__ . '/../models/SubscriptionModel.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../utils/Env.php';
require_once __DIR__ . '/SystemLogService.php';

class BillingService
{
    private const PLAN_PRICES = [
        'free' => 0.0,
        'pro' => 39.0,
        'agency' => 99.0,
    ];

    private SubscriptionModel $subscriptionModel;
    private UserModel $userModel;
    private SystemLogService $logService;
    private string $keyId;
    private string $keySecret;
    private array $planIds;
    private string $envSourcePath;

    public function __construct(
        ?SubscriptionModel $subscriptionModel = null,
        ?UserModel $userModel = null,
        ?SystemLogService $logService = null
    ) {
        $this->envSourcePath = dirname(__DIR__) . '/.env';
        Env::load($this->envSourcePath);
        $this->subscriptionModel = $subscriptionModel ?? new SubscriptionModel();
        $this->userModel = $userModel ?? new UserModel();
        $this->logService = $logService ?? new SystemLogService();

        $this->keyId = $this->readEnvFirstNonEmpty(['RAZORPAY_KEY_ID', 'RAZORPAY_API_KEY', 'RAZORPAY_KEY']);
        $this->keySecret = $this->readEnvFirstNonEmpty(['RAZORPAY_KEY_SECRET', 'RAZORPAY_API_SECRET', 'RAZORPAY_SECRET']);
        $this->planIds = [
            'pro' => [
                'monthly' => $this->readEnvFirstNonEmpty(['RAZORPAY_PLAN_PRO', 'RAZORPAY_PRO_PLAN_ID', 'RAZORPAY_PLAN_ID_PRO']),
                'annual' => $this->readEnvFirstNonEmpty(['RAZORPAY_PLAN_PRO_ANNUAL', 'RAZORPAY_PRO_PLAN_ID_ANNUAL', 'RAZORPAY_PLAN_ID_PRO_ANNUAL']),
            ],
            'agency' => [
                'monthly' => $this->readEnvFirstNonEmpty(['RAZORPAY_PLAN_AGENCY', 'RAZORPAY_AGENCY_PLAN_ID', 'RAZORPAY_PLAN_ID_AGENCY']),
                'annual' => $this->readEnvFirstNonEmpty(['RAZORPAY_PLAN_AGENCY_ANNUAL', 'RAZORPAY_AGENCY_PLAN_ID_ANNUAL', 'RAZORPAY_PLAN_ID_AGENCY_ANNUAL']),
            ],
        ];
    }

    public function createSubscriptionForPlan(int $userId, string $planType, string $billingCycle = 'monthly'): array
    {
        $planType = $this->normalizePlan($planType);
        $billingCycle = $this->normalizeBillingCycle($billingCycle);
        if (!in_array($planType, ['pro', 'agency'], true)) {
            return $this->error('INVALID_PLAN', 'Only Pro and Agency plans require paid subscription.', 422);
        }

        if (!$this->isGatewayConfigured()) {
            $missing = $this->missingGatewayConfigVars($planType, $billingCycle);
            $message = 'Razorpay billing is not fully configured.';
            if (!empty($missing)) {
                $message .= ' Missing: ' . implode(', ', $missing) . '.';
            }
            $message .= $this->diagnosticsSuffix();
            return $this->error('BILLING_NOT_CONFIGURED', $message, 500);
        }

        $planId = (string) ($this->planIds[$planType][$billingCycle] ?? '');
        if ($planId === '') {
            $missing = $this->missingGatewayConfigVars($planType, $billingCycle);
            $message = 'Razorpay plan id is not configured for ' . $planType . ' (' . $billingCycle . ').';
            if (!empty($missing)) {
                $message .= ' Missing: ' . implode(', ', $missing) . '.';
            }
            $message .= $this->diagnosticsSuffix();
            return $this->error('MISSING_PLAN_ID', $message, 500);
        }

        $user = $this->userModel->getUserById($userId);
        if (!$user) {
            return $this->error('USER_NOT_FOUND', 'User not found.', 404);
        }

        $requestPayload = [
            'plan_id' => $planId,
            'total_count' => 120,
            'customer_notify' => 1,
            'notes' => [
                'user_id' => (string) $userId,
                'plan_type' => $planType,
                'billing_cycle' => $billingCycle,
                'email' => (string) ($user['email'] ?? ''),
            ],
        ];

        $response = $this->apiRequest('https://api.razorpay.com/v1/subscriptions', 'POST', $requestPayload);
        if (empty($response['success'])) {
            $this->logService->error('billing', 'Failed to create Razorpay subscription.', [
                'user_id' => $userId,
                'plan_type' => $planType,
                'billing_cycle' => $billingCycle,
                'response' => $response,
            ], $userId);
            return $this->error('RAZORPAY_ERROR', (string) ($response['error'] ?? 'Unable to create subscription.'), 502);
        }

        $payload = (array) ($response['payload'] ?? []);
        $gatewaySubId = trim((string) ($payload['id'] ?? ''));
        if ($gatewaySubId === '') {
            return $this->error('RAZORPAY_ERROR', 'Razorpay did not return subscription id.', 502);
        }

        $upsert = $this->subscriptionModel->upsertByGatewaySubscriptionId([
            'user_id' => $userId,
            'razorpay_customer_id' => isset($payload['customer_id']) ? (string) $payload['customer_id'] : null,
            'razorpay_subscription_id' => $gatewaySubId,
            'plan_type' => $planType,
            'status' => strtolower((string) ($payload['status'] ?? 'incomplete')),
            'next_billing_date' => isset($payload['charge_at']) ? date('Y-m-d H:i:s', (int) $payload['charge_at']) : null,
            'current_period_start' => isset($payload['current_start']) ? date('Y-m-d H:i:s', (int) $payload['current_start']) : null,
            'current_period_end' => isset($payload['current_end']) ? date('Y-m-d H:i:s', (int) $payload['current_end']) : null,
            'cancel_at_period_end' => !empty($payload['cancel_at_cycle_end']),
        ]);
        if (empty($upsert['success'])) {
            return $this->error('SAVE_FAILED', (string) ($upsert['error'] ?? 'Unable to save subscription locally.'), 500);
        }

        return [
            'success' => true,
            'subscription' => (array) ($upsert['subscription'] ?? []),
            'gateway' => [
                'id' => $gatewaySubId,
                'short_url' => (string) ($payload['short_url'] ?? ''),
                'status' => (string) ($payload['status'] ?? 'created'),
            ],
            'billing_cycle' => $billingCycle,
        ];
    }

    public function cancelUserSubscription(int $userId, bool $cancelImmediately = false): array
    {
        $current = $this->subscriptionModel->getCurrentByUser($userId);
        if (!$current || empty($current['razorpay_subscription_id'])) {
            return $this->error('NOT_FOUND', 'No active paid subscription found.', 404);
        }

        $gatewaySubId = (string) $current['razorpay_subscription_id'];
        if ($this->isGatewayConfigured()) {
            $endpoint = 'https://api.razorpay.com/v1/subscriptions/' . rawurlencode($gatewaySubId) . '/cancel';
            $response = $this->apiRequest($endpoint, 'POST', [
                'cancel_at_cycle_end' => $cancelImmediately ? 0 : 1,
            ]);
            if (empty($response['success'])) {
                $this->logService->warning('billing', 'Razorpay cancellation failed, updating local status only.', [
                    'user_id' => $userId,
                    'gateway_subscription_id' => $gatewaySubId,
                    'response' => $response,
                ], $userId);
            }
        }

        $status = $cancelImmediately ? 'canceled' : 'active';
        $upsert = $this->subscriptionModel->upsertByGatewaySubscriptionId([
            'user_id' => $userId,
            'razorpay_subscription_id' => $gatewaySubId,
            'plan_type' => (string) ($current['plan_type'] ?? 'pro'),
            'status' => $status,
            'cancel_at_period_end' => !$cancelImmediately,
            'next_billing_date' => isset($current['next_billing_date']) ? (string) $current['next_billing_date'] : null,
            'current_period_start' => isset($current['current_period_start']) ? (string) $current['current_period_start'] : null,
            'current_period_end' => isset($current['current_period_end']) ? (string) $current['current_period_end'] : null,
        ]);
        if (empty($upsert['success'])) {
            return $this->error('SAVE_FAILED', (string) ($upsert['error'] ?? 'Unable to cancel subscription.'), 500);
        }

        if ($cancelImmediately) {
            $this->userModel->updatePlanType($userId, 'free');
        }

        return [
            'success' => true,
            'message' => $cancelImmediately ? 'Subscription canceled immediately.' : 'Subscription will be canceled at period end.',
            'subscription' => (array) ($upsert['subscription'] ?? []),
        ];
    }

    public function applyPaymentSuccess(string $gatewaySubId, string $planType, ?string $nextBillingDate = null): array
    {
        $existing = $this->subscriptionModel->getByGatewaySubscriptionId($gatewaySubId);
        if (!$existing) {
            return $this->error('NOT_FOUND', 'Subscription not found for webhook event.', 404);
        }
        $planType = $this->normalizePlan($planType !== '' ? $planType : (string) ($existing['plan_type'] ?? 'free'));
        $userId = (int) ($existing['user_id'] ?? 0);

        $upsert = $this->subscriptionModel->upsertByGatewaySubscriptionId([
            'user_id' => $userId,
            'razorpay_subscription_id' => $gatewaySubId,
            'plan_type' => $planType,
            'status' => 'active',
            'next_billing_date' => $nextBillingDate,
            'grace_ends_at' => null,
            'current_period_start' => isset($existing['current_period_start']) ? (string) $existing['current_period_start'] : null,
            'current_period_end' => isset($existing['current_period_end']) ? (string) $existing['current_period_end'] : $nextBillingDate,
            'cancel_at_period_end' => 0,
        ]);
        if (empty($upsert['success'])) {
            return $this->error('SAVE_FAILED', (string) ($upsert['error'] ?? 'Unable to persist payment success.'), 500);
        }

        $this->userModel->updatePlanType($userId, $planType);
        return [
            'success' => true,
            'user_id' => $userId,
            'plan_type' => $planType,
            'subscription' => (array) ($upsert['subscription'] ?? []),
        ];
    }

    public function applyPaymentFailure(string $gatewaySubId, int $graceDays = 5): array
    {
        $existing = $this->subscriptionModel->getByGatewaySubscriptionId($gatewaySubId);
        if (!$existing) {
            return $this->error('NOT_FOUND', 'Subscription not found for payment failure.', 404);
        }

        $graceDays = max(3, min(7, $graceDays));
        $graceEndsAt = date('Y-m-d H:i:s', strtotime('+' . $graceDays . ' days'));
        $userId = (int) ($existing['user_id'] ?? 0);

        $upsert = $this->subscriptionModel->upsertByGatewaySubscriptionId([
            'user_id' => $userId,
            'razorpay_subscription_id' => $gatewaySubId,
            'plan_type' => (string) ($existing['plan_type'] ?? 'free'),
            'status' => 'past_due',
            'grace_ends_at' => $graceEndsAt,
            'next_billing_date' => isset($existing['next_billing_date']) ? (string) $existing['next_billing_date'] : null,
            'current_period_start' => isset($existing['current_period_start']) ? (string) $existing['current_period_start'] : null,
            'current_period_end' => isset($existing['current_period_end']) ? (string) $existing['current_period_end'] : null,
            'cancel_at_period_end' => isset($existing['cancel_at_period_end']) ? (int) $existing['cancel_at_period_end'] : 0,
        ]);
        if (empty($upsert['success'])) {
            return $this->error('SAVE_FAILED', (string) ($upsert['error'] ?? 'Unable to persist payment failure.'), 500);
        }

        return [
            'success' => true,
            'user_id' => $userId,
            'grace_ends_at' => $graceEndsAt,
            'subscription' => (array) ($upsert['subscription'] ?? []),
        ];
    }

    public function reconcilePastDueSubscriptions(): array
    {
        $expired = $this->subscriptionModel->getPastDueExpired(date('Y-m-d H:i:s'));
        $processed = 0;
        $downgraded = 0;
        $failed = 0;
        $details = [];

        foreach ($expired as $subscription) {
            $processed++;
            $userId = (int) ($subscription['user_id'] ?? 0);
            $gatewayId = (string) ($subscription['razorpay_subscription_id'] ?? '');
            if ($userId <= 0) {
                $failed++;
                continue;
            }

            $update = $this->subscriptionModel->upsertByGatewaySubscriptionId([
                'user_id' => $userId,
                'razorpay_subscription_id' => $gatewayId,
                'plan_type' => (string) ($subscription['plan_type'] ?? 'free'),
                'status' => 'canceled',
                'next_billing_date' => null,
                'grace_ends_at' => null,
                'current_period_start' => isset($subscription['current_period_start']) ? (string) $subscription['current_period_start'] : null,
                'current_period_end' => isset($subscription['current_period_end']) ? (string) $subscription['current_period_end'] : null,
                'cancel_at_period_end' => 1,
            ]);
            if (empty($update['success'])) {
                $failed++;
                continue;
            }

            $this->userModel->updatePlanType($userId, 'free');
            $downgraded++;
            $details[] = [
                'user_id' => $userId,
                'gateway_subscription_id' => $gatewayId,
            ];
        }

        return [
            'success' => true,
            'processed' => $processed,
            'downgraded' => $downgraded,
            'failed' => $failed,
            'details' => $details,
        ];
    }

    public function getBillingSummary(): array
    {
        $stats = $this->subscriptionModel->getStats();
        $revenueByPlan = [
            'free' => 0.0,
            'pro' => round((float) (($stats['by_plan']['pro'] ?? 0) * self::PLAN_PRICES['pro']), 2),
            'agency' => round((float) (($stats['by_plan']['agency'] ?? 0) * self::PLAN_PRICES['agency']), 2),
        ];
        $revenueByPlan['total'] = round($revenueByPlan['pro'] + $revenueByPlan['agency'], 2);

        return [
            'subscriptions' => $stats,
            'revenue' => $revenueByPlan,
        ];
    }

    private function isGatewayConfigured(): bool
    {
        return $this->keyId !== '' && $this->keySecret !== '';
    }

    private function missingGatewayConfigVars(?string $planType = null, ?string $billingCycle = null): array
    {
        $missing = [];
        if ($this->keyId === '') {
            $missing[] = 'RAZORPAY_KEY_ID';
        }
        if ($this->keySecret === '') {
            $missing[] = 'RAZORPAY_KEY_SECRET';
        }
        $cycles = ['monthly', 'annual'];
        if ($billingCycle !== null && in_array($billingCycle, $cycles, true)) {
            $cycles = [$billingCycle];
        }
        if ($planType === null || $planType === 'pro') {
            foreach ($cycles as $cycle) {
                if ((string) ($this->planIds['pro'][$cycle] ?? '') === '') {
                    $missing[] = $cycle === 'annual' ? 'RAZORPAY_PLAN_PRO_ANNUAL' : 'RAZORPAY_PLAN_PRO';
                }
            }
        }
        if ($planType === null || $planType === 'agency') {
            foreach ($cycles as $cycle) {
                if ((string) ($this->planIds['agency'][$cycle] ?? '') === '') {
                    $missing[] = $cycle === 'annual' ? 'RAZORPAY_PLAN_AGENCY_ANNUAL' : 'RAZORPAY_PLAN_AGENCY';
                }
            }
        }
        return $missing;
    }

    private function readEnvFirstNonEmpty(array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) Env::get((string) $key, ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function diagnosticsSuffix(): string
    {
        $pathHint = $this->envSourcePath !== '' ? $this->envSourcePath : '.env';
        return ' Loaded lengths: KEY_ID=' . strlen($this->keyId)
            . ', KEY_SECRET=' . strlen($this->keySecret)
            . ', PLAN_PRO_MONTHLY=' . strlen((string) ($this->planIds['pro']['monthly'] ?? ''))
            . ', PLAN_PRO_ANNUAL=' . strlen((string) ($this->planIds['pro']['annual'] ?? ''))
            . ', PLAN_AGENCY_MONTHLY=' . strlen((string) ($this->planIds['agency']['monthly'] ?? ''))
            . ', PLAN_AGENCY_ANNUAL=' . strlen((string) ($this->planIds['agency']['annual'] ?? ''))
            . '. Check file: ' . $pathHint;
    }

    private function normalizePlan(string $planType): string
    {
        $planType = strtolower(trim($planType));
        if (!in_array($planType, ['free', 'pro', 'agency'], true)) {
            return 'free';
        }
        return $planType;
    }

    private function normalizeBillingCycle(string $billingCycle): string
    {
        $billingCycle = strtolower(trim($billingCycle));
        if (!in_array($billingCycle, ['monthly', 'annual'], true)) {
            return 'monthly';
        }
        return $billingCycle;
    }

    private function apiRequest(string $url, string $method, array $payload = []): array
    {
        $ch = curl_init($url);
        $headers = ['Content-Type: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERPWD => $this->keyId . ':' . $this->keySecret,
        ]);
        if (!empty($payload)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => $curlError !== '' ? $curlError : 'Razorpay request failed.'];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'error' => 'Invalid Razorpay response payload.'];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'payload' => $decoded];
        }

        $errorMessage = (string) ($decoded['error']['description'] ?? $decoded['error']['reason'] ?? 'Razorpay API error.');
        return [
            'success' => false,
            'status' => $httpCode,
            'error' => $errorMessage,
            'payload' => $decoded,
        ];
    }

    private function error(string $code, string $message, int $status = 400): array
    {
        return [
            'success' => false,
            'status' => $status,
            'error_code' => $code,
            'error' => $message,
        ];
    }
}
