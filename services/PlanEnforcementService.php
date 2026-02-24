<?php

require_once __DIR__ . '/../models/TrackedKeywordModel.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../models/SubscriptionModel.php';
require_once __DIR__ . '/../models/UsageLogModel.php';
require_once __DIR__ . '/../config/database.php';

class PlanEnforcementService
{
    private const DEFAULT_LIMITS = [
        'free' => [
            'projects_limit' => 1,
            'keywords_limit' => 5,
            'api_calls_daily' => 250,
            'insights_limit' => 3,
            'ai_monthly_limit' => 3,
            'can_export' => false,
            'can_manual_refresh' => false,
        ],
        'pro' => [
            'projects_limit' => 5,
            'keywords_limit' => 50,
            'api_calls_daily' => 2500,
            'insights_limit' => 80,
            'ai_monthly_limit' => 20,
            'can_export' => true,
            'can_manual_refresh' => true,
        ],
        'agency' => [
            'projects_limit' => 1000000,
            'keywords_limit' => 200,
            'api_calls_daily' => 10000,
            'insights_limit' => 200,
            'ai_monthly_limit' => 100,
            'can_export' => true,
            'can_manual_refresh' => true,
        ],
    ];

    private const FEATURE_PLAN_REQUIREMENTS = [
        'export_reports' => 'pro',
        'manual_refresh' => 'pro',
        'white_label_reports' => 'agency',
        'client_portal' => 'agency',
        'backlink_overview' => 'agency',
        'multi_page_crawler' => 'agency',
        'keyword_tool' => 'free',
        'audit_history' => 'pro',
        'competitor_basic' => 'pro',
        'rank_tracker' => 'free',
        'insight_engine_full' => 'pro',
    ];

    private const PLAN_RANK = [
        'free' => 1,
        'pro' => 2,
        'agency' => 3,
    ];

    private TrackedKeywordModel $trackedKeywordModel;
    private UserModel $userModel;
    private SubscriptionModel $subscriptionModel;
    private UsageLogModel $usageLogModel;
    private ?PDO $conn = null;

    public function __construct(
        ?TrackedKeywordModel $trackedKeywordModel = null,
        ?UserModel $userModel = null,
        ?SubscriptionModel $subscriptionModel = null,
        ?UsageLogModel $usageLogModel = null
    ) {
        $this->trackedKeywordModel = $trackedKeywordModel ?? new TrackedKeywordModel();
        $this->userModel = $userModel ?? new UserModel();
        $this->subscriptionModel = $subscriptionModel ?? new SubscriptionModel();
        $this->usageLogModel = $usageLogModel ?? new UsageLogModel();

        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;
    }

    public function getEffectiveRole(int $userId): string
    {
        $user = $this->userModel->getUserById($userId);
        $role = strtolower((string) ($user['role'] ?? 'user'));
        if (!in_array($role, ['user', 'agency', 'admin', 'super_admin', 'support_admin', 'billing_admin'], true)) {
            return 'user';
        }
        return $role;
    }

    public function getEffectivePlan(int $userId, ?string $sessionPlan = null): string
    {
        $role = $this->getEffectiveRole($userId);
        if (in_array($role, ['super_admin', 'admin'], true)) {
            return 'agency';
        }

        $user = $this->userModel->getUserById($userId);
        $plan = $this->normalizePlan((string) ($user['plan_type'] ?? ($sessionPlan ?? 'free')));

        $subscription = $this->subscriptionModel->getCurrentByUser($userId);
        if (!$subscription) {
            return $plan;
        }

        $status = strtolower((string) ($subscription['status'] ?? 'incomplete'));
        if (in_array($status, ['active', 'trialing'], true)) {
            return $this->normalizePlan((string) ($subscription['plan_type'] ?? $plan));
        }

        if ($status === 'past_due') {
            $graceEndsAt = (string) ($subscription['grace_ends_at'] ?? '');
            if ($graceEndsAt !== '' && strtotime($graceEndsAt) !== false && strtotime($graceEndsAt) > time()) {
                return $this->normalizePlan((string) ($subscription['plan_type'] ?? $plan));
            }
        }

        return $plan;
    }

    public function getLimitsForUser(int $userId): array
    {
        $role = $this->getEffectiveRole($userId);
        $plan = $this->getEffectivePlan($userId);
        $limits = $this->fetchPlanLimits($plan);

        return [
            'user_id' => $userId,
            'role' => $role,
            'plan_type' => $plan,
            'limits' => $limits,
            'admin_bypass' => in_array($role, ['super_admin', 'admin'], true),
        ];
    }

    public function assertFeatureAccess(int $userId, string $feature): array
    {
        $info = $this->getLimitsForUser($userId);
        if (!empty($info['admin_bypass'])) {
            return ['allowed' => true, 'required_plan' => 'admin', 'plan_type' => (string) ($info['plan_type'] ?? 'agency')];
        }

        $feature = strtolower(trim($feature));
        $requiredPlan = self::FEATURE_PLAN_REQUIREMENTS[$feature] ?? 'free';
        $plan = (string) ($info['plan_type'] ?? 'free');
        if ((self::PLAN_RANK[$plan] ?? 1) >= (self::PLAN_RANK[$requiredPlan] ?? 1)) {
            return ['allowed' => true, 'required_plan' => $requiredPlan, 'plan_type' => $plan];
        }

        return [
            'allowed' => false,
            'required_plan' => $requiredPlan,
            'plan_type' => $plan,
            'message' => 'This feature requires the ' . ucfirst($requiredPlan) . ' plan.',
        ];
    }

    public function assertProjectLimit(int $userId, int $additionalProjects = 1): array
    {
        $additionalProjects = max(1, $additionalProjects);
        $info = $this->getLimitsForUser($userId);
        if (!empty($info['admin_bypass'])) {
            return ['allowed' => true, 'used' => 0, 'limit' => PHP_INT_MAX];
        }

        $limits = (array) ($info['limits'] ?? []);
        $projectLimit = (int) ($limits['projects_limit'] ?? 1);
        $usedProjects = count($this->trackedKeywordModel->getProjects($userId));

        if ($usedProjects + $additionalProjects <= $projectLimit) {
            return ['allowed' => true, 'used' => $usedProjects, 'limit' => $projectLimit];
        }

        return [
            'allowed' => false,
            'used' => $usedProjects,
            'limit' => $projectLimit,
            'message' => 'Project limit reached for your current plan.',
        ];
    }

    public function assertKeywordLimit(int $userId, int $additionalKeywords = 1): array
    {
        $additionalKeywords = max(1, $additionalKeywords);
        $info = $this->getLimitsForUser($userId);
        if (!empty($info['admin_bypass'])) {
            return ['allowed' => true, 'used' => 0, 'limit' => PHP_INT_MAX];
        }

        $limits = (array) ($info['limits'] ?? []);
        $keywordLimit = (int) ($limits['keywords_limit'] ?? 5);
        $usedKeywords = count($this->trackedKeywordModel->getTrackedKeywords($userId));

        if ($usedKeywords + $additionalKeywords <= $keywordLimit) {
            return ['allowed' => true, 'used' => $usedKeywords, 'limit' => $keywordLimit];
        }

        return [
            'allowed' => false,
            'used' => $usedKeywords,
            'limit' => $keywordLimit,
            'message' => 'Keyword limit reached for your current plan.',
        ];
    }

    public function assertDailyApiLimit(int $userId, string $metric = 'api_call', int $additional = 1): array
    {
        $additional = max(1, $additional);
        $info = $this->getLimitsForUser($userId);
        if (!empty($info['admin_bypass'])) {
            return ['allowed' => true, 'used' => 0, 'limit' => PHP_INT_MAX];
        }

        $limits = (array) ($info['limits'] ?? []);
        $apiLimit = (int) ($limits['api_calls_daily'] ?? 250);
        $used = $this->usageLogModel->countForUserMetric($userId, $metric, 86400);

        if ($used + $additional <= $apiLimit) {
            return ['allowed' => true, 'used' => $used, 'limit' => $apiLimit];
        }

        return [
            'allowed' => false,
            'used' => $used,
            'limit' => $apiLimit,
            'message' => 'Daily API usage limit reached for your current plan.',
        ];
    }

    public function normalizePlan(string $plan): string
    {
        $plan = strtolower(trim($plan));
        if (!isset(self::PLAN_RANK[$plan])) {
            return 'free';
        }
        return $plan;
    }

    private function fetchPlanLimits(string $plan): array
    {
        $plan = $this->normalizePlan($plan);
        $defaults = self::DEFAULT_LIMITS[$plan];
        if (!$this->conn) {
            return $defaults;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT projects_limit, keywords_limit, api_calls_daily, insights_limit, ai_monthly_limit, can_export, can_manual_refresh
                 FROM plan_limits
                 WHERE plan_type = :plan_type
                 LIMIT 1'
            );
            $stmt->execute([':plan_type' => $plan]);
            $row = $stmt->fetch();
            if (!$row) {
                return $defaults;
            }

            return [
                'projects_limit' => (int) ($row['projects_limit'] ?? $defaults['projects_limit']),
                'keywords_limit' => (int) ($row['keywords_limit'] ?? $defaults['keywords_limit']),
                'api_calls_daily' => (int) ($row['api_calls_daily'] ?? $defaults['api_calls_daily']),
                'insights_limit' => (int) ($row['insights_limit'] ?? $defaults['insights_limit']),
                'ai_monthly_limit' => (int) ($row['ai_monthly_limit'] ?? $defaults['ai_monthly_limit']),
                'can_export' => !empty($row['can_export']),
                'can_manual_refresh' => !empty($row['can_manual_refresh']),
            ];
        } catch (Throwable $error) {
            return $defaults;
        }
    }
}
