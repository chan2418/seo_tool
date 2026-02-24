<?php

require_once __DIR__ . '/../models/OnboardingModel.php';
require_once __DIR__ . '/../models/TrackedKeywordModel.php';
require_once __DIR__ . '/../models/SearchConsoleAccountModel.php';
require_once __DIR__ . '/../models/AuditModel.php';

class OnboardingService
{
    private const STEPS = [
        'create_project' => [
            'title' => 'Create Project',
            'description' => 'Create your first project workspace.',
            'url' => 'dashboard.php',
        ],
        'add_domain' => [
            'title' => 'Add Domain',
            'description' => 'Add your website domain to the project.',
            'url' => 'dashboard.php',
        ],
        'connect_gsc' => [
            'title' => 'Connect Google Search Console',
            'description' => 'Connect GSC to unlock performance insights.',
            'url' => 'performance.php',
        ],
        'add_keywords' => [
            'title' => 'Add Keywords',
            'description' => 'Start tracking your important keywords.',
            'url' => 'rank-tracker.php',
        ],
        'run_audit' => [
            'title' => 'Run First Audit',
            'description' => 'Run an SEO audit and get your baseline score.',
            'url' => 'dashboard.php',
        ],
    ];

    private OnboardingModel $onboardingModel;
    private TrackedKeywordModel $trackedKeywordModel;
    private SearchConsoleAccountModel $searchConsoleAccountModel;
    private AuditModel $auditModel;

    public function __construct(
        ?OnboardingModel $onboardingModel = null,
        ?TrackedKeywordModel $trackedKeywordModel = null,
        ?SearchConsoleAccountModel $searchConsoleAccountModel = null,
        ?AuditModel $auditModel = null
    ) {
        $this->onboardingModel = $onboardingModel ?? new OnboardingModel();
        $this->trackedKeywordModel = $trackedKeywordModel ?? new TrackedKeywordModel();
        $this->searchConsoleAccountModel = $searchConsoleAccountModel ?? new SearchConsoleAccountModel();
        $this->auditModel = $auditModel ?? new AuditModel();
    }

    public function getChecklist(int $userId): array
    {
        $projects = $this->trackedKeywordModel->getProjects($userId);
        $trackedKeywords = $this->trackedKeywordModel->getTrackedKeywords($userId);
        $connections = $this->searchConsoleAccountModel->getConnectionsByUser($userId);
        $audits = $this->auditModel->getUserAudits($userId);

        $runtimeStatus = [
            'create_project' => count($projects) > 0,
            'add_domain' => $this->hasDomain($projects),
            'connect_gsc' => count($connections) > 0,
            'add_keywords' => count($trackedKeywords) > 0,
            'run_audit' => count($audits) > 0,
        ];

        foreach ($runtimeStatus as $stepKey => $completed) {
            $this->onboardingModel->setStep($userId, $stepKey, $completed);
        }

        $saved = $this->onboardingModel->getSteps($userId);
        $steps = [];
        $completedCount = 0;
        foreach (self::STEPS as $key => $meta) {
            $done = !empty($saved[$key]['is_completed']);
            if ($done) {
                $completedCount++;
            }
            $steps[] = [
                'key' => $key,
                'title' => (string) ($meta['title'] ?? $key),
                'description' => (string) ($meta['description'] ?? ''),
                'url' => (string) ($meta['url'] ?? 'dashboard.php'),
                'is_completed' => $done,
                'completed_at' => isset($saved[$key]['completed_at']) ? (string) $saved[$key]['completed_at'] : null,
            ];
        }

        $total = count(self::STEPS);
        $progress = $total > 0 ? (int) round(($completedCount / $total) * 100) : 0;
        return [
            'success' => true,
            'steps' => $steps,
            'summary' => [
                'completed' => $completedCount,
                'total' => $total,
                'progress_pct' => $progress,
                'is_finished' => $completedCount >= $total,
            ],
        ];
    }

    private function hasDomain(array $projects): bool
    {
        foreach ($projects as $project) {
            if (trim((string) ($project['domain'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }
}

