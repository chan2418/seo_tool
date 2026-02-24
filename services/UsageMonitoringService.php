<?php

require_once __DIR__ . '/../models/UsageLogModel.php';
require_once __DIR__ . '/../models/AdminControlModel.php';
require_once __DIR__ . '/SystemLogService.php';

class UsageMonitoringService
{
    private UsageLogModel $model;
    private AdminControlModel $adminControlModel;
    private SystemLogService $logService;

    public function __construct(?UsageLogModel $model = null, ?SystemLogService $logService = null)
    {
        $this->model = $model ?? new UsageLogModel();
        $this->adminControlModel = new AdminControlModel();
        $this->logService = $logService ?? new SystemLogService();
    }

    public function logMetric(int $userId, string $metric, int $qty = 1, ?int $projectId = null, ?string $context = null): void
    {
        $this->model->log($userId, $metric, $qty, $projectId, $context);
    }

    public function logApiCall(int $userId, string $module, ?int $projectId = null): void
    {
        $this->model->log($userId, 'api_call', 1, $projectId, $module);
        $this->model->log($userId, 'api_call.' . $this->sanitizeModule($module), 1, $projectId, $module);
        $provider = $this->inferProvider($module);
        $this->adminControlModel->createApiUsageLog($userId, $projectId, $provider, $this->sanitizeModule($module), 1, 200, 0);
    }

    public function logProjectCreated(int $userId, ?int $projectId = null): void
    {
        $this->model->log($userId, 'projects_created', 1, $projectId, 'project_create');
    }

    public function logKeywordAdded(int $userId, ?int $projectId = null): void
    {
        $this->model->log($userId, 'keywords_added', 1, $projectId, 'keyword_add');
    }

    public function logGscSync(int $userId, ?int $projectId = null): void
    {
        $this->model->log($userId, 'gsc_sync', 1, $projectId, 'sync');
    }

    public function logCronExecution(string $cronName, bool $success, array $context = []): void
    {
        $level = $success ? 'info' : 'warning';
        $startedAt = (string) ($context['started_at'] ?? date('Y-m-d H:i:s'));
        $finishedAt = (string) ($context['finished_at'] ?? date('Y-m-d H:i:s'));
        $durationMs = isset($context['duration_ms']) ? (int) $context['duration_ms'] : max(0, (int) ((strtotime($finishedAt) - strtotime($startedAt)) * 1000));
        $context['cron'] = $cronName;
        if ($success) {
            $this->logService->info('cron', 'Cron executed: ' . $cronName, $context);
        } else {
            $this->logService->warning('cron', 'Cron executed with warnings: ' . $cronName, $context);
        }

        $this->adminControlModel->createCronLog(
            $cronName,
            $success ? 'success' : 'warning',
            $startedAt,
            $finishedAt,
            $durationMs,
            $success ? 'Cron completed successfully.' : 'Cron completed with warnings.',
            $context
        );
    }

    public function dailyApiUsage(int $userId): int
    {
        return $this->model->countForUserMetric($userId, 'api_call', 86400);
    }

    public function usageSummary(int $userId, int $days = 30): array
    {
        return $this->model->getSummaryByUser($userId, $days);
    }

    private function sanitizeModule(string $module): string
    {
        $module = strtolower(trim($module));
        $module = preg_replace('/[^a-z0-9_\-]/', '_', $module);
        if ($module === '') {
            return 'generic';
        }
        return $module;
    }

    private function inferProvider(string $module): string
    {
        $module = strtolower(trim($module));
        if (str_contains($module, 'gsc') || str_contains($module, 'search_console')) {
            return 'gsc';
        }
        if (str_contains($module, 'rank') || str_contains($module, 'serp') || str_contains($module, 'dataforseo')) {
            return 'rank_api';
        }
        if (str_contains($module, 'backlink')) {
            return 'backlink_api';
        }
        if (str_contains($module, 'competitor')) {
            return 'competitor_api';
        }
        return 'internal';
    }
}
