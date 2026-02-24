<?php

require_once __DIR__ . '/../models/AlertModel.php';
require_once __DIR__ . '/../models/AlertSettingModel.php';
require_once __DIR__ . '/../models/TrackedKeywordModel.php';
require_once __DIR__ . '/EmailNotificationService.php';
require_once __DIR__ . '/PlanEnforcementService.php';

class AlertService
{
    private const PLAN_ALERT_TYPES = [
        'free' => [
            'rank_drop',
            'rank_top10',
            'rank_out_top50',
        ],
        'pro' => [
            'rank_drop',
            'rank_top10',
            'rank_out_top50',
            'seo_drop',
            'technical_score_drop',
            'crawl_critical',
        ],
        'agency' => [
            'rank_drop',
            'rank_top10',
            'rank_out_top50',
            'seo_drop',
            'technical_score_drop',
            'crawl_critical',
            'backlink_new',
            'backlink_lost',
            'ref_domains_drop',
            'crawl_broken_links_up',
            'crawl_duplicate_titles_up',
            'crawl_thin_content_up',
        ],
    ];

    private AlertModel $alertModel;
    private AlertSettingModel $alertSettingModel;
    private EmailNotificationService $emailNotificationService;
    private PlanEnforcementService $planEnforcementService;

    public function __construct(
        ?AlertModel $alertModel = null,
        ?AlertSettingModel $alertSettingModel = null,
        ?EmailNotificationService $emailNotificationService = null,
        ?PlanEnforcementService $planEnforcementService = null
    ) {
        $this->alertModel = $alertModel ?? new AlertModel();
        $this->alertSettingModel = $alertSettingModel ?? new AlertSettingModel();
        $this->emailNotificationService = $emailNotificationService ?? new EmailNotificationService();
        $this->planEnforcementService = $planEnforcementService ?? new PlanEnforcementService();
    }

    public function createAlert(
        int $userId,
        string $planType,
        int $projectId,
        string $alertType,
        string $referenceId,
        string $message,
        string $severity = 'info',
        ?string $createdDate = null
    ): array {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        if (!$this->isTypeAllowedForPlan($planType, $alertType)) {
            return [
                'success' => true,
                'created' => false,
                'ignored' => true,
                'reason' => 'PLAN_RESTRICTED',
            ];
        }

        $project = $this->alertModel->getProjectById($userId, $projectId);
        if (!$project) {
            return [
                'success' => false,
                'created' => false,
                'error' => 'Project not found for alert.',
            ];
        }

        $result = $this->alertModel->createAlertUnique(
            $userId,
            $projectId,
            $alertType,
            $referenceId,
            $message,
            $severity,
            $createdDate ?? date('Y-m-d')
        );

        if (empty($result['success'])) {
            return $result;
        }

        return [
            'success' => true,
            'created' => !empty($result['created']),
            'id' => (int) ($result['id'] ?? 0),
        ];
    }

    public function getBellData(int $userId, string $planType): array
    {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        $this->syncProjectsFromAudits($userId);
        $types = $this->allowedTypesForPlan($this->normalizePlanType($planType));

        $unread = $this->alertModel->countUnread($userId, [
            'alert_types' => $types,
        ]);
        $recent = $this->alertModel->getRecentAlerts($userId, 8, [
            'alert_types' => $types,
        ]);

        return [
            'success' => true,
            'unread_count' => $unread,
            'recent' => $recent,
        ];
    }

    public function getAlertsForPage(int $userId, string $planType, array $filters = []): array
    {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        $this->syncProjectsFromAudits($userId);
        $planType = $this->normalizePlanType($planType);
        $types = $this->allowedTypesForPlan($planType);

        $filters['alert_types'] = $types;
        $result = $this->alertModel->getAlerts($userId, $filters);

        return [
            'success' => true,
            'alerts' => (array) ($result['items'] ?? []),
            'pagination' => [
                'page' => (int) ($result['page'] ?? 1),
                'per_page' => (int) ($result['per_page'] ?? 15),
                'total' => (int) ($result['total'] ?? 0),
                'total_pages' => (int) ($result['total_pages'] ?? 0),
            ],
            'unread_count' => $this->alertModel->countUnread($userId, ['alert_types' => $types]),
            'projects' => $this->alertModel->getProjectsByUser($userId),
            'allowed_alert_types' => $types,
        ];
    }

    public function markRead(int $userId, int $alertId): array
    {
        $ok = $this->alertModel->markRead($userId, $alertId);
        if (!$ok) {
            return [
                'success' => false,
                'status' => 404,
                'error' => 'Alert not found.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Alert marked as read.',
        ];
    }

    public function markAllRead(int $userId, ?int $projectId = null): array
    {
        $count = $this->alertModel->markAllRead($userId, $projectId);
        return [
            'success' => true,
            'updated' => $count,
        ];
    }

    public function getSettings(int $userId, int $projectId, string $planType): array
    {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        $this->syncProjectsFromAudits($userId);
        $project = $this->alertModel->getProjectById($userId, $projectId);
        if (!$project) {
            return [
                'success' => false,
                'status' => 404,
                'error' => 'Project not found.',
            ];
        }

        $setting = $this->alertSettingModel->getSettings($userId, $projectId);
        if ($this->normalizePlanType($planType) === 'free') {
            $setting['email_notifications_enabled'] = 0;
        }

        return [
            'success' => true,
            'settings' => $setting,
        ];
    }

    public function saveSettings(int $userId, int $projectId, string $planType, array $input): array
    {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        $this->syncProjectsFromAudits($userId);
        $project = $this->alertModel->getProjectById($userId, $projectId);
        if (!$project) {
            return [
                'success' => false,
                'status' => 404,
                'error' => 'Project not found.',
            ];
        }

        $rankDrop = (int) ($input['rank_drop_threshold'] ?? 10);
        $seoDrop = (int) ($input['seo_score_drop_threshold'] ?? 5);
        $emailEnabled = !empty($input['email_notifications_enabled']);

        $planType = $this->normalizePlanType($planType);
        if ($planType === 'free') {
            $emailEnabled = false;
        }

        $saved = $this->alertSettingModel->saveSettings($userId, $projectId, $rankDrop, $seoDrop, $emailEnabled);
        return [
            'success' => true,
            'settings' => $saved,
        ];
    }

    public function queueDailySummaryEmailIfEnabled(int $userId, string $planType, string $period = 'daily'): array
    {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        if (!in_array($planType, ['pro', 'agency'], true)) {
            return ['queued' => false, 'reason' => 'PLAN_RESTRICTED'];
        }

        $profile = $this->alertModel->getUserEmailAndPlan($userId);
        $email = trim((string) ($profile['email'] ?? ''));
        if ($email === '') {
            return ['queued' => false, 'reason' => 'NO_EMAIL'];
        }

        $settings = $this->alertSettingModel->getEmailEnabledSettingsByUser($userId);
        if (empty($settings)) {
            return ['queued' => false, 'reason' => 'EMAIL_DISABLED'];
        }

        $types = $this->allowedTypesForPlan($planType);
        $alerts = $this->alertModel->getAlerts($userId, [
            'page' => 1,
            'per_page' => 50,
            'alert_types' => $types,
        ]);
        $items = (array) ($alerts['items'] ?? []);
        if (empty($items)) {
            return ['queued' => false, 'reason' => 'NO_ALERTS'];
        }

        if (strtolower($period) === 'daily') {
            $today = date('Y-m-d');
            $items = array_values(array_filter($items, static function (array $item) use ($today): bool {
                return strpos((string) ($item['created_at'] ?? ''), $today) === 0;
            }));
        } elseif (strtolower($period) === 'weekly') {
            $start = date('Y-m-d', strtotime('-6 days'));
            $items = array_values(array_filter($items, static function (array $item) use ($start): bool {
                $date = substr((string) ($item['created_at'] ?? ''), 0, 10);
                return $date >= $start;
            }));
        }

        if (empty($items)) {
            return ['queued' => false, 'reason' => 'NO_ALERTS_FOR_PERIOD'];
        }

        $subject = (strtolower($period) === 'weekly' ? 'Weekly' : 'Daily') . ' SEO Alerts Summary';
        $queued = $this->emailNotificationService->queueSummaryEmail(
            $userId,
            $email,
            (string) ($profile['name'] ?? 'User'),
            $subject,
            $items,
            strtolower($period) === 'weekly' ? 'weekly' : 'daily'
        );

        return [
            'queued' => $queued,
            'items' => count($items),
        ];
    }

    public function processEmailQueue(int $limit = 20): array
    {
        return $this->emailNotificationService->processQueue($limit);
    }

    public function allowedTypesForPlan(string $planType): array
    {
        $planType = $this->normalizePlanType($planType);
        return self::PLAN_ALERT_TYPES[$planType] ?? self::PLAN_ALERT_TYPES['free'];
    }

    public function isTypeAllowedForPlan(string $planType, string $alertType): bool
    {
        $alertType = strtolower(trim($alertType));
        return in_array($alertType, $this->allowedTypesForPlan($planType), true);
    }

    public function normalizePlanType(string $planType): string
    {
        $planType = strtolower(trim($planType));
        if (!isset(self::PLAN_ALERT_TYPES[$planType])) {
            return 'free';
        }
        return $planType;
    }

    private function syncProjectsFromAudits(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            $trackedKeywordModel = new TrackedKeywordModel();
            $trackedKeywordModel->syncProjectsFromAudits($userId);
        } catch (Throwable $error) {
            error_log('AlertService project sync failed: ' . $error->getMessage());
        }
    }
}
