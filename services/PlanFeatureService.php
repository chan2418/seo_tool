<?php

class PlanFeatureService
{
    public const PLAN_FREE = 'free';
    public const PLAN_PRO = 'pro';
    public const PLAN_AGENCY = 'agency';

    private const PLAN_RANK = [
        self::PLAN_FREE => 1,
        self::PLAN_PRO => 2,
        self::PLAN_AGENCY => 3,
    ];

    private const FEATURE_MAP = [
        'homepage_audit' => self::PLAN_FREE,
        'keyword_tool' => self::PLAN_FREE,
        'audit_history' => self::PLAN_PRO,
        'competitor_basic' => self::PLAN_PRO,
        'competitor_comparison' => self::PLAN_AGENCY,
        'backlink_overview' => self::PLAN_AGENCY,
        'multi_page_crawler' => self::PLAN_AGENCY,
        'white_label_report' => self::PLAN_AGENCY,
        'export_reports' => self::PLAN_PRO,
        'manual_refresh' => self::PLAN_PRO,
    ];

    public static function normalizePlan(?string $plan): string
    {
        $normalized = strtolower(trim((string) $plan));
        if (!isset(self::PLAN_RANK[$normalized])) {
            return self::PLAN_FREE;
        }

        return $normalized;
    }

    public static function minimumPlanForFeature(string $feature): string
    {
        return self::FEATURE_MAP[$feature] ?? self::PLAN_FREE;
    }

    public static function canUseFeature(?string $plan, string $feature): bool
    {
        $normalizedPlan = self::normalizePlan($plan);
        $requiredPlan = self::minimumPlanForFeature($feature);

        return self::PLAN_RANK[$normalizedPlan] >= self::PLAN_RANK[$requiredPlan];
    }

    public static function accessResponse(?string $plan, string $feature): array
    {
        $normalizedPlan = self::normalizePlan($plan);
        $requiredPlan = self::minimumPlanForFeature($feature);

        if (self::canUseFeature($normalizedPlan, $feature)) {
            return [
                'allowed' => true,
                'plan' => $normalizedPlan,
                'required_plan' => $requiredPlan,
            ];
        }

        return [
            'allowed' => false,
            'plan' => $normalizedPlan,
            'required_plan' => $requiredPlan,
            'message' => 'This feature requires the ' . ucfirst($requiredPlan) . ' plan.',
        ];
    }

    public static function plans(): array
    {
        return [self::PLAN_FREE, self::PLAN_PRO, self::PLAN_AGENCY];
    }
}
