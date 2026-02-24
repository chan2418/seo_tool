<?php

require_once __DIR__ . '/../services/PlanEnforcementService.php';

class PlanGateMiddleware
{
    public static function requireFeature(int $userId, string $feature, bool $jsonResponse = true): void
    {
        $service = new PlanEnforcementService();
        $result = $service->assertFeatureAccess($userId, $feature);
        if (!empty($result['allowed'])) {
            return;
        }

        if ($jsonResponse) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error_code' => 'PLAN_RESTRICTED',
                'error' => (string) ($result['message'] ?? 'This feature is not available on your current plan.'),
                'required_plan' => (string) ($result['required_plan'] ?? 'pro'),
            ]);
            exit;
        }

        http_response_code(403);
        echo (string) ($result['message'] ?? 'Plan restriction.');
        exit;
    }
}

