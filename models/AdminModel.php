<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/UserModel.php';
require_once __DIR__ . '/SubscriptionModel.php';
require_once __DIR__ . '/UsageLogModel.php';
require_once __DIR__ . '/SystemLogModel.php';
require_once __DIR__ . '/SearchConsoleAccountModel.php';

class AdminModel
{
    private ?PDO $conn;
    private bool $useFileStorage = false;
    private UserModel $userModel;
    private SubscriptionModel $subscriptionModel;
    private UsageLogModel $usageLogModel;
    private SystemLogModel $systemLogModel;
    private SearchConsoleAccountModel $searchConsoleAccountModel;

    public function __construct()
    {
        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;
        $this->userModel = new UserModel();
        $this->subscriptionModel = new SubscriptionModel();
        $this->usageLogModel = new UsageLogModel();
        $this->systemLogModel = new SystemLogModel();
        $this->searchConsoleAccountModel = new SearchConsoleAccountModel();

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function getDashboardMetrics(): array
    {
        $subscriptionStats = $this->subscriptionModel->getStats();
        $planDistribution = $this->getUserPlanDistribution();
        $revenueByPlan = $this->calculateRevenueByPlan($subscriptionStats['by_plan'] ?? []);
        $systemSummary = $this->systemLogModel->summaryLastDays(30);

        $metrics = [
            'total_users' => $this->userModel->countUsers(),
            'active_subscriptions' => (int) ($subscriptionStats['active_total'] ?? 0),
            'new_subscriptions_this_month' => (int) ($subscriptionStats['new_this_month'] ?? 0),
            'churned_users_this_month' => (int) ($subscriptionStats['churned_this_month'] ?? 0),
            'plan_distribution' => $planDistribution,
            'mrr' => (float) ($revenueByPlan['total'] ?? 0),
            'revenue_by_plan' => $revenueByPlan,
            'arpu' => 0.0,
            'growth_rate' => $this->estimateGrowthRate(
                (int) ($subscriptionStats['new_this_month'] ?? 0),
                (int) ($subscriptionStats['churned_this_month'] ?? 0),
                (int) ($subscriptionStats['active_total'] ?? 0)
            ),
            'system_errors_last_30_days' => (int) (($systemSummary['error'] ?? 0) + ($systemSummary['critical'] ?? 0)),
            'connected_gsc_accounts' => $this->countConnectedGscAccounts(),
        ];

        $activeUsersForArpu = max(1, (int) ($metrics['active_subscriptions'] ?? 0));
        $metrics['arpu'] = round(((float) ($metrics['mrr'] ?? 0)) / $activeUsersForArpu, 2);
        return $metrics;
    }

    public function getAdminUsers(int $limit = 30, int $offset = 0, string $search = ''): array
    {
        $users = $this->userModel->listUsers($limit, $offset, $search);
        $result = [];

        foreach ($users as $user) {
            $userId = (int) ($user['id'] ?? 0);
            $usageSummary = $this->usageLogModel->getSummaryByUser($userId, 30);
            $projectCount = $this->countProjectsByUser($userId);
            $keywordCount = $this->countKeywordsByUser($userId);
            $currentSubscription = $this->subscriptionModel->getCurrentByUser($userId);
            $result[] = [
                'user' => $user,
                'project_count' => $projectCount,
                'keyword_count' => $keywordCount,
                'usage_summary' => $usageSummary,
                'subscription' => $currentSubscription,
            ];
        }

        return $result;
    }

    public function getCronLogs(int $linesPerFile = 80): array
    {
        $linesPerFile = max(1, min(500, $linesPerFile));
        $storageDir = __DIR__ . '/../storage';
        $targets = [
            'rank' => $storageDir . '/rank_cron.log',
            'alert' => $storageDir . '/alert_cron.log',
            'gsc_sync' => $storageDir . '/search_console_sync.log',
            'insights' => $storageDir . '/insight_cron.log',
        ];
        $result = [];
        foreach ($targets as $key => $path) {
            $result[$key] = $this->tailFile($path, $linesPerFile);
        }
        return $result;
    }

    public function getRecentSystemLogs(int $limit = 100): array
    {
        return $this->systemLogModel->getRecent($limit);
    }

    public function updateUserPlan(int $userId, string $planType): bool
    {
        return $this->userModel->updatePlanType($userId, $planType);
    }

    public function updateUserStatus(int $userId, string $status, ?string $reason = null): bool
    {
        return $this->userModel->updateStatus($userId, $status, $reason);
    }

    public function updateUserRole(int $userId, string $role): bool
    {
        return $this->userModel->updateRole($userId, $role);
    }

    public function getRevenueTrendLastMonths(int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $plans = ['pro' => 999.0, 'agency' => 2999.0];
        $trend = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = date('Y-m-01 00:00:00', strtotime('-' . $i . ' months'));
            $monthEnd = date('Y-m-t 23:59:59', strtotime($monthStart));
            $monthLabel = date('M Y', strtotime($monthStart));
            $totals = ['pro' => 0, 'agency' => 0];

            if (!$this->useFileStorage && $this->conn) {
                try {
                    $stmt = $this->conn->prepare(
                        'SELECT plan_type, COUNT(*) AS total
                         FROM subscriptions
                         WHERE status IN ("active", "trialing")
                           AND created_at <= :month_end
                         GROUP BY plan_type'
                    );
                    $stmt->execute([':month_end' => $monthEnd]);
                    foreach ($stmt->fetchAll() as $row) {
                        $plan = strtolower((string) ($row['plan_type'] ?? ''));
                        if (isset($totals[$plan])) {
                            $totals[$plan] = (int) ($row['total'] ?? 0);
                        }
                    }
                } catch (Throwable $error) {
                    $this->useFileStorage = true;
                }
            }

            if ($this->useFileStorage) {
                $stats = $this->subscriptionModel->getStats();
                $totals['pro'] = (int) (($stats['by_plan']['pro'] ?? 0));
                $totals['agency'] = (int) (($stats['by_plan']['agency'] ?? 0));
            }

            $trend[] = [
                'month' => $monthLabel,
                'pro' => $totals['pro'],
                'agency' => $totals['agency'],
                'revenue' => round($totals['pro'] * $plans['pro'] + $totals['agency'] * $plans['agency'], 2),
            ];
        }

        return $trend;
    }

    private function getUserPlanDistribution(): array
    {
        $distribution = ['free' => 0, 'pro' => 0, 'agency' => 0];
        if ($this->useFileStorage || !$this->conn) {
            foreach ($this->userModel->listUsers(10000, 0, '') as $user) {
                $plan = strtolower((string) ($user['plan_type'] ?? 'free'));
                if (!isset($distribution[$plan])) {
                    $distribution[$plan] = 0;
                }
                $distribution[$plan]++;
            }
            return $distribution;
        }

        try {
            $stmt = $this->conn->query(
                'SELECT plan_type, COUNT(*) AS total
                 FROM users
                 GROUP BY plan_type'
            );
            foreach ($stmt->fetchAll() as $row) {
                $plan = strtolower((string) ($row['plan_type'] ?? 'free'));
                if (!isset($distribution[$plan])) {
                    $distribution[$plan] = 0;
                }
                $distribution[$plan] = (int) ($row['total'] ?? 0);
            }
            return $distribution;
        } catch (Throwable $error) {
            $this->useFileStorage = true;
            return $this->getUserPlanDistribution();
        }
    }

    private function calculateRevenueByPlan(array $activeByPlan): array
    {
        $prices = ['free' => 0.0, 'pro' => 999.0, 'agency' => 2999.0];
        $revenue = [
            'free' => round((float) (($activeByPlan['free'] ?? 0) * $prices['free']), 2),
            'pro' => round((float) (($activeByPlan['pro'] ?? 0) * $prices['pro']), 2),
            'agency' => round((float) (($activeByPlan['agency'] ?? 0) * $prices['agency']), 2),
            'total' => 0.0,
        ];
        $revenue['total'] = round($revenue['free'] + $revenue['pro'] + $revenue['agency'], 2);
        return $revenue;
    }

    private function estimateGrowthRate(int $newSubscriptions, int $churned, int $active): float
    {
        if ($active <= 0) {
            return 0.0;
        }
        return round((($newSubscriptions - $churned) / $active) * 100, 2);
    }

    private function countProjectsByUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        if ($this->useFileStorage || !$this->conn) {
            $projectsFile = __DIR__ . '/../storage/projects.json';
            if (!file_exists($projectsFile)) {
                return 0;
            }
            $rows = json_decode((string) file_get_contents($projectsFile), true);
            if (!is_array($rows)) {
                return 0;
            }
            $count = 0;
            foreach ($rows as $row) {
                if ((int) ($row['user_id'] ?? 0) === $userId) {
                    $count++;
                }
            }
            return $count;
        }

        try {
            $stmt = $this->conn->prepare('SELECT COUNT(*) AS total FROM projects WHERE user_id = :user_id');
            $stmt->execute([':user_id' => $userId]);
            $row = $stmt->fetch();
            return (int) ($row['total'] ?? 0);
        } catch (Throwable $error) {
            $this->useFileStorage = true;
            return $this->countProjectsByUser($userId);
        }
    }

    private function countKeywordsByUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        if ($this->useFileStorage || !$this->conn) {
            $keywordsFile = __DIR__ . '/../storage/tracked_keywords.json';
            $projectsFile = __DIR__ . '/../storage/projects.json';
            if (!file_exists($keywordsFile) || !file_exists($projectsFile)) {
                return 0;
            }
            $keywords = json_decode((string) file_get_contents($keywordsFile), true);
            $projects = json_decode((string) file_get_contents($projectsFile), true);
            if (!is_array($keywords) || !is_array($projects)) {
                return 0;
            }
            $projectIds = [];
            foreach ($projects as $project) {
                if ((int) ($project['user_id'] ?? 0) === $userId) {
                    $projectIds[(int) ($project['id'] ?? 0)] = true;
                }
            }
            $count = 0;
            foreach ($keywords as $keyword) {
                $projectId = (int) ($keyword['project_id'] ?? 0);
                if (isset($projectIds[$projectId])) {
                    $count++;
                }
            }
            return $count;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT COUNT(*) AS total
                 FROM tracked_keywords tk
                 INNER JOIN projects p ON p.id = tk.project_id
                 WHERE p.user_id = :user_id'
            );
            $stmt->execute([':user_id' => $userId]);
            $row = $stmt->fetch();
            return (int) ($row['total'] ?? 0);
        } catch (Throwable $error) {
            $this->useFileStorage = true;
            return $this->countKeywordsByUser($userId);
        }
    }

    private function countConnectedGscAccounts(): int
    {
        return count($this->searchConsoleAccountModel->getAllConnections(5000));
    }

    private function tailFile(string $path, int $lines): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $allLines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($allLines)) {
            return [];
        }
        return array_slice($allLines, -$lines);
    }
}
