<?php
session_start();
require_once __DIR__ . '/../services/PlanEnforcementService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';

$auth = AuthMiddleware::requireLogin(false);
AccountStatusMiddleware::ensureActive(false);

require_once __DIR__ . '/../models/AuditModel.php';
require_once __DIR__ . '/../models/BacklinkModel.php';
require_once __DIR__ . '/../models/CompetitorModel.php';
require_once __DIR__ . '/../services/AlertService.php';
require_once __DIR__ . '/../services/SearchConsoleService.php';
require_once __DIR__ . '/../config/database.php';

$userName = (string) ($auth['user_name'] ?? ($_SESSION['user_name'] ?? 'User'));
$userId = (int) ($auth['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$planService = new PlanEnforcementService();
$planType = $planService->getEffectivePlan($userId, (string) ($_SESSION['plan_type'] ?? 'free'));
$planLabel = ucfirst($planType);

$auditModel = new AuditModel();
$audits = $auditModel->getUserAudits($userId);
$auditCount = count($audits);
$recentAudits = array_slice($audits, 0, 6);

$totalScore = 0;
foreach ($audits as $audit) {
    $totalScore += (int) ($audit['seo_score'] ?? 0);
}
$avgScore = $auditCount > 0 ? (int) round($totalScore / $auditCount) : 0;

$latestAudit = $audits[0] ?? null;
$latestScore = $latestAudit ? (int) ($latestAudit['seo_score'] ?? 0) : 82;
$showingDemoScore = !$latestAudit;
$previousScore = isset($audits[1]) ? (int) ($audits[1]['seo_score'] ?? $latestScore) : $latestScore;
$scoreDelta = $latestScore - $previousScore;
$scoreDeltaPrefix = $scoreDelta > 0 ? '+' : '';
$scoreTrendLabel = $auditCount > 1
    ? $scoreDeltaPrefix . $scoreDelta . ' vs previous audit'
    : 'Baseline score ready';

$lastAuditDate = $latestAudit
    ? date('M d, Y', strtotime($latestAudit['created_at']))
    : 'No audits yet';

$projectHosts = [];
foreach ($audits as $audit) {
    $host = parse_url((string) ($audit['url'] ?? ''), PHP_URL_HOST);
    if (!is_string($host) || trim($host) === '') {
        continue;
    }
    $projectHosts[strtolower($host)] = true;
}
$totalProjects = count($projectHosts);

$keywordsChecked = $auditCount * 5;
$totalBacklinks = 0;
$trafficTrendLabels = [];
$trafficTrendData = [];
$keywordGrowthLabels = [];
$keywordGrowthData = [];
try {
    $db = new Database();
    $conn = $db->connect();
    if ($conn) {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM keyword_results WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();
        if ($row && isset($row['total'])) {
            $keywordsChecked = (int) $row['total'];
        }

        $trafficStmt = $conn->prepare(
            'SELECT DATE(created_at) AS day, MAX(organic_traffic) AS traffic
             FROM competitor_snapshots
             WHERE user_id = :user_id
               AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
             GROUP BY DATE(created_at)
             ORDER BY DATE(created_at) ASC
             LIMIT 12'
        );
        $trafficStmt->execute([':user_id' => $userId]);
        $trafficRows = $trafficStmt->fetchAll();
        foreach ($trafficRows as $trafficRow) {
            $trafficTrendLabels[] = date('M d', strtotime((string) $trafficRow['day']));
            $trafficTrendData[] = (int) ($trafficRow['traffic'] ?? 0);
        }

        $keywordGrowthStmt = $conn->prepare(
            'SELECT DATE(created_at) AS day, COUNT(*) AS total
             FROM keyword_results
             WHERE user_id = :user_id
               AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
             GROUP BY DATE(created_at)
             ORDER BY DATE(created_at) ASC
             LIMIT 12'
        );
        $keywordGrowthStmt->execute([':user_id' => $userId]);
        $keywordGrowthRows = $keywordGrowthStmt->fetchAll();
        foreach ($keywordGrowthRows as $keywordGrowthRow) {
            $keywordGrowthLabels[] = date('M d', strtotime((string) $keywordGrowthRow['day']));
            $keywordGrowthData[] = (int) ($keywordGrowthRow['total'] ?? 0);
        }
    }
} catch (Throwable $error) {
    $keywordsChecked = $auditCount * 5;
}

try {
    $backlinkModel = new BacklinkModel();
    $totalBacklinks = $backlinkModel->getLatestBacklinkTotalByUser($userId);
} catch (Throwable $error) {
    $totalBacklinks = 0;
}

$chartAudits = array_reverse(array_slice($audits, 0, 12));
$chartLabels = [];
$chartData = [];
foreach ($chartAudits as $audit) {
    $chartLabels[] = date('M d', strtotime($audit['created_at']));
    $chartData[] = (int) ($audit['seo_score'] ?? 0);
}

if (empty($trafficTrendLabels)) {
    foreach ($chartAudits as $audit) {
        $trafficTrendLabels[] = date('M d', strtotime($audit['created_at']));
        $trafficTrendData[] = max(500, (int) (($audit['seo_score'] ?? 0) * 220 + 1000));
    }
}

if (empty($keywordGrowthLabels)) {
    foreach ($chartAudits as $audit) {
        $keywordGrowthLabels[] = date('M d', strtotime($audit['created_at']));
        $keywordGrowthData[] = max(1, (int) round(($audit['seo_score'] ?? 0) / 6));
    }
}

$topDomain = 'Run your first audit';
if ($latestAudit && !empty($latestAudit['url'])) {
    $host = parse_url($latestAudit['url'], PHP_URL_HOST);
    $topDomain = $host ?: $latestAudit['url'];
}

function scoreBadgeClass(int $score): string
{
    if ($score >= 80) {
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300';
    }

    if ($score >= 50) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300';
    }

    return 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300';
}

function scoreTextClass(int $score): string
{
    if ($score >= 80) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if ($score >= 50) {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-red-600 dark:text-red-400';
}

$heroScore = max(0, min(100, $latestScore));
$scoreToGoal = max(0, 100 - $heroScore);

$alertService = new AlertService();
$bellData = [
    'unread_count' => 0,
    'recent' => [],
];
try {
    $bellData = $alertService->getBellData($userId, $planType);
} catch (Throwable $error) {
    error_log('Dashboard bell load failed: ' . $error->getMessage());
}
$unreadAlerts = (int) ($bellData['unread_count'] ?? 0);
$recentAlerts = is_array($bellData['recent'] ?? null) ? $bellData['recent'] : [];

$searchConsoleSnapshot = [
    'connected' => false,
    'has_data' => false,
];
try {
    $searchConsoleService = new SearchConsoleService();
    $searchConsoleSnapshot = $searchConsoleService->getDashboardSnapshot($userId, $planType);
} catch (Throwable $error) {
    error_log('Dashboard GSC snapshot failed: ' . $error->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - SEO Audit SaaS</title>
    <script>
        (function () {
            var storedTheme = localStorage.getItem('seo-theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (storedTheme === 'dark' || (!storedTheme && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            500: '#4F46E5',
                            600: '#4338CA',
                            400: '#6366F1'
                        }
                    },
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'ui-sans-serif', 'system-ui', 'sans-serif']
                    },
                    boxShadow: {
                        soft: '0 18px 45px -25px rgba(15, 23, 42, 0.35)'
                    }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .surface-card {
            border-radius: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.6);
            background: rgba(255, 255, 255, 0.84);
            backdrop-filter: blur(12px);
        }

        .dark .surface-card {
            border-color: rgba(51, 65, 85, 0.8);
            background: rgba(30, 41, 59, 0.82);
        }

        .metric-card,
        .audit-card {
            transition: transform 180ms ease, box-shadow 180ms ease;
        }

        .metric-card:hover,
        .audit-card:hover {
            transform: translateY(-3px);
        }

        .skeleton {
            background: linear-gradient(90deg, rgba(226, 232, 240, 0.35) 25%, rgba(241, 245, 249, 0.8) 50%, rgba(226, 232, 240, 0.35) 75%);
            background-size: 200% 100%;
            animation: shimmer 1.4s infinite;
        }

        .dark .skeleton {
            background: linear-gradient(90deg, rgba(51, 65, 85, 0.35) 25%, rgba(71, 85, 105, 0.65) 50%, rgba(51, 65, 85, 0.35) 75%);
            background-size: 200% 100%;
        }

        @keyframes shimmer {
            to {
                background-position: -200% 0;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute -left-24 top-[-9rem] h-80 w-80 rounded-full bg-indigo-300/40 blur-3xl dark:bg-indigo-500/20"></div>
        <div class="absolute right-[-6rem] top-20 h-72 w-72 rounded-full bg-sky-200/60 blur-3xl dark:bg-sky-500/15"></div>
        <div class="absolute bottom-[-8rem] left-1/2 h-72 w-72 -translate-x-1/2 rounded-full bg-violet-200/60 blur-3xl dark:bg-violet-500/15"></div>
    </div>

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="lg:pl-72">
        <header class="sticky top-0 z-20 border-b border-white/50 bg-slate-100/70 px-4 py-4 backdrop-blur-xl dark:border-slate-700/70 dark:bg-slate-950/70 sm:px-6 lg:px-10">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <button id="sidebar-open" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 lg:hidden" aria-label="Open sidebar">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                        </svg>
                    </button>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">SEO Command Center</p>
                        <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Dashboard</h1>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <div class="relative">
                        <button id="alerts-bell-btn" type="button" class="relative inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:text-brand-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300" aria-label="Open alerts">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082A23.89 23.89 0 0 0 18 17.5c1.107 0 2-.895 2-2v-.168c0-.593-.214-1.166-.602-1.614L18 12.25V9a6 6 0 1 0-12 0v3.25l-1.398 1.468A2.347 2.347 0 0 0 4 15.332V15.5c0 1.105.893 2 2 2 1.06 0 2.076-.143 3.143-.418M9 17.5a3 3 0 0 0 6 0" />
                            </svg>
                            <span id="alerts-bell-badge" class="<?php echo $unreadAlerts > 0 ? '' : 'hidden '; ?>absolute -right-1 -top-1 inline-flex min-h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white <?php echo $unreadAlerts > 0 ? 'animate-pulse' : ''; ?>">
                                <?php echo $unreadAlerts > 99 ? '99+' : (string) $unreadAlerts; ?>
                            </span>
                        </button>

                        <div id="alerts-bell-dropdown" class="absolute right-0 z-30 mt-2 hidden w-80 rounded-2xl border border-slate-200 bg-white p-3 shadow-soft dark:border-slate-700 dark:bg-slate-900">
                            <div class="mb-3 flex items-center justify-between">
                                <p class="text-sm font-bold text-slate-900 dark:text-slate-100">Notifications</p>
                                <a href="alerts.php" class="text-xs font-semibold text-brand-500 hover:text-brand-600">View all</a>
                            </div>
                            <div id="alerts-bell-list" class="max-h-80 space-y-2 overflow-y-auto pr-1">
                                <?php if (empty($recentAlerts)): ?>
                                    <p class="rounded-xl border border-dashed border-slate-300 px-3 py-4 text-center text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">No alerts yet.</p>
                                <?php else: ?>
                                    <?php foreach ($recentAlerts as $alert): ?>
                                        <?php
                                            $severity = strtolower((string) ($alert['severity'] ?? 'info'));
                                            $severityClass = $severity === 'critical'
                                                ? 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300'
                                                : ($severity === 'warning'
                                                    ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'
                                                    : 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300');
                                        ?>
                                        <article class="rounded-xl border border-slate-200 bg-slate-50/80 p-3 text-xs dark:border-slate-700 dark:bg-slate-800/70">
                                            <div class="mb-1 flex items-center justify-between gap-2">
                                                <span class="rounded-lg px-2 py-1 font-bold <?php echo $severityClass; ?>">
                                                    <?php echo htmlspecialchars(strtoupper($severity)); ?>
                                                </span>
                                                <span class="text-[10px] text-slate-500 dark:text-slate-400"><?php echo htmlspecialchars(date('M d, H:i', strtotime((string) ($alert['created_at'] ?? 'now')))); ?></span>
                                            </div>
                                            <p class="font-semibold text-slate-800 dark:text-slate-100"><?php echo htmlspecialchars((string) ($alert['message'] ?? 'Alert')); ?></p>
                                            <div class="mt-2 flex items-center justify-between">
                                                <span class="text-[10px] text-slate-500 dark:text-slate-400"><?php echo htmlspecialchars((string) ($alert['project_name'] ?? 'Project')); ?></span>
                                                <?php if ((int) ($alert['is_read'] ?? 0) === 0): ?>
                                                    <button type="button" data-alert-read-id="<?php echo (int) ($alert['id'] ?? 0); ?>" class="rounded-lg bg-gradient-to-r from-brand-500 to-brand-400 px-2 py-1 text-[10px] font-bold text-white">Mark read</button>
                                                <?php endif; ?>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <button id="theme-toggle" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:text-brand-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300" aria-label="Toggle theme">
                        <svg class="h-5 w-5 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="12" cy="12" r="4"></circle>
                            <path stroke-linecap="round" d="M12 3v2.2M12 18.8V21M3 12h2.2M18.8 12H21M5.64 5.64l1.55 1.55M16.81 16.81l1.55 1.55M5.64 18.36l1.55-1.55M16.81 7.19l1.55-1.55" />
                        </svg>
                        <svg class="hidden h-5 w-5 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1 1 11.21 3c.11 0 .23 0 .34.01a1 1 0 0 1 .54 1.82A7 7 0 0 0 19.17 12a1 1 0 0 1 1.83.79Z" />
                        </svg>
                    </button>

                    <span class="hidden rounded-xl px-3 py-2 text-xs font-bold tracking-wide sm:inline-flex <?php echo in_array($planType, ['pro', 'agency'], true) ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'; ?>">
                        <?php echo htmlspecialchars($planLabel); ?> Plan
                    </span>

                    <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-3 py-2 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-400 text-sm font-bold text-white">
                            <?php echo htmlspecialchars(strtoupper(substr($userName, 0, 1))); ?>
                        </div>
                        <div class="hidden sm:block">
                            <p class="text-xs text-slate-500 dark:text-slate-400">Welcome back</p>
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($userName); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="space-y-6 px-4 pb-10 pt-6 sm:px-6 lg:px-10">
            <section class="grid gap-6 xl:grid-cols-[1.35fr_1fr]">
                <article class="surface-card shadow-soft">
                    <div class="flex flex-col gap-8 p-6 sm:p-8 lg:flex-row lg:items-center lg:justify-between">
                        <div class="space-y-4">
                            <div class="inline-flex items-center gap-2 rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">
                                <span class="h-2 w-2 rounded-full bg-indigo-500"></span>
                                Live SEO Performance
                            </div>
                            <div>
                                <h2 class="text-3xl font-extrabold text-slate-900 dark:text-slate-100">SEO Score: <?php echo $heroScore; ?> / 100</h2>
                                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                                    <?php echo $showingDemoScore ? 'Demo score is shown until your first audit is completed.' : 'Latest audit for ' . htmlspecialchars($topDomain) . ' is now available.'; ?>
                                </p>
                            </div>
                            <div class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?php echo $scoreDelta >= 0 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300'; ?>">
                                <span><?php echo $scoreDelta >= 0 ? 'UP' : 'DOWN'; ?></span>
                                <span><?php echo htmlspecialchars($scoreTrendLabel); ?></span>
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <a href="index.php#run-audit" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-5 py-3 text-sm font-semibold text-white shadow-soft transition hover:opacity-90">Run New Audit</a>
                                <a href="history.php" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">View History</a>
                            </div>
                        </div>

                        <div class="relative mx-auto h-48 w-48">
                            <svg class="h-48 w-48 -rotate-90" viewBox="0 0 180 180" fill="none">
                                <defs>
                                    <linearGradient id="dashboardScoreGradient" x1="0" y1="0" x2="1" y2="1">
                                        <stop offset="0%" stop-color="#4F46E5" />
                                        <stop offset="100%" stop-color="#6366F1" />
                                    </linearGradient>
                                </defs>
                                <circle cx="90" cy="90" r="68" stroke="currentColor" stroke-width="14" class="text-slate-200 dark:text-slate-700" />
                                <circle id="dashboard-score-progress" data-score="<?php echo $heroScore; ?>" cx="90" cy="90" r="68" stroke="url(#dashboardScoreGradient)" stroke-width="14" stroke-linecap="round" stroke-dasharray="427" stroke-dashoffset="427" />
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
                                <p id="dashboard-score-value" class="text-4xl font-extrabold text-slate-900 dark:text-slate-100">0</p>
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Score</p>
                                <p class="mt-2 text-xs <?php echo $scoreToGoal === 0 ? 'text-emerald-600 dark:text-emerald-300' : 'text-slate-500 dark:text-slate-300'; ?>">
                                    <?php echo $scoreToGoal === 0 ? 'Goal achieved' : $scoreToGoal . ' points to 100'; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </article>

                <article class="surface-card p-6 shadow-soft sm:p-8">
                    <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Performance Snapshot</h3>
                    <div class="mt-5 space-y-4">
                        <div class="rounded-2xl bg-gradient-to-r from-slate-900 to-slate-700 p-4 text-white dark:from-slate-800 dark:to-slate-700">
                            <p class="text-xs uppercase tracking-[0.18em] text-slate-300">Last Audited Domain</p>
                            <p class="mt-2 text-lg font-bold"><?php echo htmlspecialchars($topDomain); ?></p>
                            <p class="mt-1 text-xs text-slate-300"><?php echo htmlspecialchars($lastAuditDate); ?></p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="rounded-2xl bg-emerald-50 p-4 dark:bg-emerald-500/10">
                                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Best Score</p>
                                <p class="mt-1 text-2xl font-extrabold text-emerald-700 dark:text-emerald-300"><?php echo $auditCount > 0 ? max(array_map(static fn ($a) => (int) ($a['seo_score'] ?? 0), $audits)) : '--'; ?></p>
                            </div>
                            <div class="rounded-2xl bg-amber-50 p-4 dark:bg-amber-500/10">
                                <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">Average</p>
                                <p class="mt-1 text-2xl font-extrabold text-amber-700 dark:text-amber-300"><?php echo $auditCount > 0 ? $avgScore : '--'; ?></p>
                            </div>
                        </div>
                        <p class="text-sm text-slate-600 dark:text-slate-300">
                            Keep improving technical fixes and on-page metadata to push your next report into the green zone.
                        </p>
                    </div>
                </article>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="metric-card surface-card p-6 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Total Projects</p>
                    <p class="mt-3 text-3xl font-extrabold text-slate-900 dark:text-slate-100"><?php echo $totalProjects; ?></p>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Unique tracked domains</p>
                </article>

                <article class="metric-card surface-card p-6 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Total Keywords Tracked</p>
                    <p class="mt-3 text-3xl font-extrabold text-slate-900 dark:text-slate-100"><?php echo $keywordsChecked; ?></p>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Across keyword intelligence</p>
                </article>

                <article class="metric-card surface-card p-6 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Average SEO Score</p>
                    <p class="mt-3 text-3xl font-extrabold text-slate-900 dark:text-slate-100"><?php echo $auditCount > 0 ? $avgScore : '--'; ?></p>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">From recent audits</p>
                </article>

                <article class="metric-card surface-card p-6 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Total Backlinks</p>
                    <p class="mt-3 text-3xl font-extrabold text-slate-900 dark:text-slate-100"><?php echo number_format($totalBacklinks); ?></p>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Latest backlink snapshot</p>
                </article>
            </section>

            <section class="grid gap-6 xl:grid-cols-3">
                <article class="surface-card p-6 shadow-soft sm:p-8">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h3 class="text-xl font-bold text-slate-900 dark:text-slate-100">SEO Score Trend</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Your last 12 audits.</p>
                        </div>
                        <a href="history.php" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Open History</a>
                    </div>
                    <div class="mt-6 h-64">
                        <canvas id="score-trend-chart"></canvas>
                    </div>
                </article>

                <article class="surface-card p-6 shadow-soft sm:p-8">
                    <h3 class="text-xl font-bold text-slate-900 dark:text-slate-100">Traffic Trend</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Estimated organic traffic movement.</p>
                    <div class="mt-6 h-64">
                        <canvas id="traffic-trend-chart"></canvas>
                    </div>
                </article>

                <article class="surface-card p-6 shadow-soft sm:p-8">
                    <h3 class="text-xl font-bold text-slate-900 dark:text-slate-100">Keyword Growth</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Tracked keyword growth over time.</p>
                    <div class="mt-6 h-64">
                        <canvas id="keyword-growth-chart"></canvas>
                    </div>
                </article>
            </section>

            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-xl font-bold text-slate-900 dark:text-slate-100">Google Search Console Snapshot</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Latest cached performance data from connected property.</p>
                    </div>
                    <a href="performance.php<?php echo !empty($searchConsoleSnapshot['project_id']) ? '?project_id=' . (int) $searchConsoleSnapshot['project_id'] : ''; ?>" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                        Open Performance
                    </a>
                </div>

                <?php if (empty($searchConsoleSnapshot['connected'])): ?>
                    <div class="mt-4 rounded-xl border border-dashed border-slate-300 px-4 py-5 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                        Google Search Console is not connected yet. Connect from `Settings -> Google Search Console - Project Settings`.
                    </div>
                <?php elseif (empty($searchConsoleSnapshot['has_data'])): ?>
                    <div class="mt-4 rounded-xl border border-dashed border-slate-300 px-4 py-5 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                        Connected to <?php echo htmlspecialchars((string) ($searchConsoleSnapshot['google_property'] ?? 'property')); ?>. Waiting for first sync.
                    </div>
                <?php else: ?>
                    <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <article class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                            <p class="text-xs uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">Clicks</p>
                            <p class="mt-2 text-2xl font-extrabold"><?php echo number_format((float) ($searchConsoleSnapshot['total_clicks'] ?? 0)); ?></p>
                        </article>
                        <article class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                            <p class="text-xs uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">Impressions</p>
                            <p class="mt-2 text-2xl font-extrabold"><?php echo number_format((float) ($searchConsoleSnapshot['total_impressions'] ?? 0)); ?></p>
                        </article>
                        <article class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                            <p class="text-xs uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">Avg CTR</p>
                            <p class="mt-2 text-2xl font-extrabold"><?php echo number_format(((float) ($searchConsoleSnapshot['avg_ctr'] ?? 0)) * 100, 2); ?>%</p>
                        </article>
                        <article class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                            <p class="text-xs uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">Avg Position</p>
                            <p class="mt-2 text-2xl font-extrabold"><?php echo number_format((float) ($searchConsoleSnapshot['avg_position'] ?? 0), 2); ?></p>
                        </article>
                    </div>

                    <div class="mt-5 grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                        <article class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                            <h4 class="text-sm font-bold text-slate-800 dark:text-slate-100">Clicks Trend (Last 28 Days)</h4>
                            <div class="mt-3 h-52">
                                <canvas id="dashboard-gsc-trend"></canvas>
                            </div>
                            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                Project: <?php echo htmlspecialchars((string) ($searchConsoleSnapshot['project_name'] ?? 'Project')); ?><?php echo !empty($searchConsoleSnapshot['last_updated']) ? ' • Updated ' . htmlspecialchars(date('M d, H:i', strtotime((string) $searchConsoleSnapshot['last_updated']))) : ''; ?>
                            </p>
                        </article>
                        <article class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                            <h4 class="text-sm font-bold text-slate-800 dark:text-slate-100">Top Queries</h4>
                            <ul class="mt-3 space-y-2 text-sm">
                                <?php $snapshotQueries = (array) ($searchConsoleSnapshot['top_queries'] ?? []); ?>
                                <?php if (empty($snapshotQueries)): ?>
                                    <li class="rounded-xl border border-dashed border-slate-300 px-3 py-3 text-slate-500 dark:border-slate-700 dark:text-slate-400">Top queries available on Pro/Agency after sync.</li>
                                <?php else: ?>
                                    <?php foreach ($snapshotQueries as $row): ?>
                                        <li class="rounded-xl border border-slate-200 px-3 py-2 dark:border-slate-700">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="font-semibold text-slate-800 dark:text-slate-100"><?php echo htmlspecialchars((string) ($row['query'] ?? '')); ?></span>
                                                <span class="text-xs text-slate-500 dark:text-slate-400"><?php echo number_format((float) ($row['clicks'] ?? 0)); ?> clicks</span>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </article>
                    </div>
                <?php endif; ?>
            </section>

            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-bold text-slate-900 dark:text-slate-100">Recent Activity</h3>
                    <span class="rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-200"><?php echo count($recentAudits); ?> audits</span>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <?php if ($auditCount > 0): ?>
                        <?php foreach ($recentAudits as $audit): ?>
                            <?php
                                $score = (int) ($audit['seo_score'] ?? 0);
                                $domain = parse_url($audit['url'], PHP_URL_HOST);
                                $domain = $domain ?: $audit['url'];
                            ?>
                            <article class="audit-card rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-bold text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($domain); ?></p>
                                        <p class="mt-1 truncate text-xs text-slate-500 dark:text-slate-400" title="<?php echo htmlspecialchars($audit['url']); ?>"><?php echo htmlspecialchars($audit['url']); ?></p>
                                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400"><?php echo date('M d, Y', strtotime($audit['created_at'])); ?></p>
                                    </div>
                                    <span class="rounded-lg px-3 py-1 text-sm font-bold <?php echo scoreBadgeClass($score); ?>"><?php echo $score; ?></span>
                                </div>
                                <div class="mt-3 flex items-center justify-between">
                                    <span class="text-xs font-semibold <?php echo scoreTextClass($score); ?>"><?php echo $score >= 80 ? 'Strong' : ($score >= 50 ? 'Needs Work' : 'Critical'); ?></span>
                                    <a href="results.php?id=<?php echo (int) $audit['id']; ?>" class="text-xs font-semibold text-brand-500 transition hover:text-brand-600">View Report -></a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="space-y-3 md:col-span-2 xl:col-span-3">
                            <div class="skeleton h-24 rounded-2xl"></div>
                            <div class="skeleton h-24 rounded-2xl"></div>
                            <div class="rounded-2xl border border-dashed border-slate-300 p-4 text-sm text-slate-500 dark:border-slate-600 dark:text-slate-300">
                                No audits yet. Start with a new scan to fill your timeline.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        (function () {
            var themeButton = document.getElementById('theme-toggle');

            themeButton && themeButton.addEventListener('click', function () {
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('seo-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
            });

            var bellButton = document.getElementById('alerts-bell-btn');
            var bellDropdown = document.getElementById('alerts-bell-dropdown');
            var bellList = document.getElementById('alerts-bell-list');
            var bellBadge = document.getElementById('alerts-bell-badge');

            function bellEscape(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function bellDate(value) {
                if (!value) {
                    return '-';
                }
                var date = new Date(value);
                if (isNaN(date.getTime())) {
                    return value;
                }
                return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            }

            function bellSeverityTag(severity) {
                if (severity === 'critical') {
                    return 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300';
                }
                if (severity === 'warning') {
                    return 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300';
                }
                return 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300';
            }

            function alertApi(action, payload) {
                var body = Object.assign({ action: action }, payload || {});
                return fetch('alerts-data.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                }).then(function (response) {
                    return response.text().then(function (text) {
                        var data;
                        try {
                            data = JSON.parse(text);
                        } catch (error) {
                            data = { success: false, error: 'Invalid response' };
                        }
                        if (!response.ok || !data.success) {
                            throw new Error(data.error || 'Alerts request failed');
                        }
                        return data;
                    });
                });
            }

            function renderBellAlerts(data) {
                var unreadCount = Number(data.unread_count || 0);
                if (bellBadge) {
                    if (unreadCount > 0) {
                        bellBadge.classList.remove('hidden');
                        bellBadge.classList.add('animate-pulse');
                        bellBadge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
                    } else {
                        bellBadge.classList.add('hidden');
                        bellBadge.classList.remove('animate-pulse');
                    }
                }

                if (!bellList) {
                    return;
                }

                var items = Array.isArray(data.recent) ? data.recent : [];
                if (items.length === 0) {
                    bellList.innerHTML = '<p class="rounded-xl border border-dashed border-slate-300 px-3 py-4 text-center text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">No alerts yet.</p>';
                    return;
                }

                bellList.innerHTML = items.map(function (alert) {
                    var severity = String(alert.severity || 'info').toLowerCase();
                    var severityClass = bellSeverityTag(severity);
                    var unread = Number(alert.is_read || 0) === 0;
                    return '' +
                        '<article class="rounded-xl border border-slate-200 bg-slate-50/80 p-3 text-xs dark:border-slate-700 dark:bg-slate-800/70">' +
                            '<div class="mb-1 flex items-center justify-between gap-2">' +
                                '<span class="rounded-lg px-2 py-1 font-bold ' + severityClass + '">' + bellEscape(severity.toUpperCase()) + '</span>' +
                                '<span class="text-[10px] text-slate-500 dark:text-slate-400">' + bellEscape(bellDate(alert.created_at || '')) + '</span>' +
                            '</div>' +
                            '<p class="font-semibold text-slate-800 dark:text-slate-100">' + bellEscape(alert.message || 'Alert') + '</p>' +
                            '<div class="mt-2 flex items-center justify-between">' +
                                '<span class="text-[10px] text-slate-500 dark:text-slate-400">' + bellEscape(alert.project_name || 'Project') + '</span>' +
                                (unread ? '<button type="button" data-alert-read-id="' + Number(alert.id || 0) + '" class="rounded-lg bg-gradient-to-r from-brand-500 to-brand-400 px-2 py-1 text-[10px] font-bold text-white">Mark read</button>' : '') +
                            '</div>' +
                        '</article>';
                }).join('');
            }

            function refreshBellAlerts() {
                return alertApi('bell').then(function (data) {
                    renderBellAlerts(data);
                    return data;
                }).catch(function () {
                    return null;
                });
            }

            if (bellButton && bellDropdown) {
                bellButton.addEventListener('click', function (event) {
                    event.stopPropagation();
                    bellDropdown.classList.toggle('hidden');
                    if (!bellDropdown.classList.contains('hidden')) {
                        refreshBellAlerts();
                    }
                });

                bellDropdown.addEventListener('click', function (event) {
                    event.stopPropagation();
                });

                document.addEventListener('click', function () {
                    bellDropdown.classList.add('hidden');
                });

                bellList && bellList.addEventListener('click', function (event) {
                    var button = event.target.closest('button[data-alert-read-id]');
                    if (!button) {
                        return;
                    }
                    var alertId = Number(button.getAttribute('data-alert-read-id') || 0);
                    if (!alertId) {
                        return;
                    }

                    alertApi('mark_read', { alert_id: alertId }).then(function () {
                        refreshBellAlerts();
                    }).catch(function () {});
                });
            }

            var progressCircle = document.getElementById('dashboard-score-progress');
            var scoreValue = document.getElementById('dashboard-score-value');

            if (progressCircle && scoreValue) {
                var radius = Number(progressCircle.getAttribute('r')) || 68;
                var circumference = 2 * Math.PI * radius;
                var target = Number(progressCircle.dataset.score || 0);
                target = Math.max(0, Math.min(100, target));

                progressCircle.style.strokeDasharray = String(circumference);
                progressCircle.style.strokeDashoffset = String(circumference);
                progressCircle.style.transition = 'stroke-dashoffset 1.1s cubic-bezier(0.22, 1, 0.36, 1)';
                scoreValue.textContent = '0';

                requestAnimationFrame(function () {
                    progressCircle.style.strokeDashoffset = String(circumference * (1 - target / 100));
                    scoreValue.textContent = String(Math.round(target));
                });
            }

            var darkMode = document.documentElement.classList.contains('dark');
            var axisTextColor = darkMode ? '#CBD5E1' : '#475569';
            var gridColor = darkMode ? 'rgba(148, 163, 184, 0.18)' : 'rgba(148, 163, 184, 0.20)';

            function toRgba(rgbColor, alpha) {
                var matches = rgbColor.match(/rgb\((\d+),\s*(\d+),\s*(\d+)\)/i);
                if (!matches) {
                    return 'rgba(79, 70, 229, ' + alpha + ')';
                }
                return 'rgba(' + matches[1] + ', ' + matches[2] + ', ' + matches[3] + ', ' + alpha + ')';
            }

            function generateLastDaysLabels(count) {
                var labels = [];
                for (var i = count - 1; i >= 0; i--) {
                    var date = new Date();
                    date.setDate(date.getDate() - i);
                    labels.push(date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }));
                }
                return labels;
            }

            function normalizeSeries(labels, points, fallbackBase, fallbackJitter) {
                var normalizedLabels = Array.isArray(labels) ? labels.slice(0) : [];
                var normalizedPoints = Array.isArray(points) ? points.map(function (v) { return Number(v || 0); }) : [];

                if (normalizedLabels.length === 0 || normalizedPoints.length === 0) {
                    normalizedLabels = generateLastDaysLabels(7);
                    normalizedPoints = normalizedLabels.map(function (_, index) {
                        var wave = Math.round(Math.sin(index * 0.9) * fallbackJitter);
                        return Math.max(1, Math.round(fallbackBase + wave + index * 2));
                    });
                }

                if (normalizedLabels.length !== normalizedPoints.length) {
                    var min = Math.min(normalizedLabels.length, normalizedPoints.length);
                    normalizedLabels = normalizedLabels.slice(0, min);
                    normalizedPoints = normalizedPoints.slice(0, min);
                }

                return {
                    labels: normalizedLabels,
                    points: normalizedPoints
                };
            }

            function createAreaChart(canvasId, labels, points, strokeColor, yMax) {
                var canvas = document.getElementById(canvasId);
                if (!canvas || !window.Chart || labels.length === 0 || points.length === 0) {
                    return null;
                }

                var context = canvas.getContext('2d');
                var gradient = context.createLinearGradient(0, 0, 0, 280);
                gradient.addColorStop(0, toRgba(strokeColor, 0.30));
                gradient.addColorStop(1, toRgba(strokeColor, 0.03));

                return new Chart(context, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: points,
                            borderColor: strokeColor,
                            backgroundColor: gradient,
                            borderWidth: 3,
                            fill: true,
                            tension: 0.34,
                            pointRadius: 2.6,
                            pointHoverRadius: 5,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: yMax,
                                grid: { color: gridColor },
                                ticks: { color: axisTextColor }
                            },
                            x: {
                                grid: { display: false },
                                ticks: { color: axisTextColor }
                            }
                        }
                    }
                });
            }

            var scoreSeries = normalizeSeries(
                <?php echo json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                <?php echo json_encode($chartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                <?php echo (int) max(30, $heroScore); ?>,
                8
            );

            var trafficSeries = normalizeSeries(
                <?php echo json_encode($trafficTrendLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                <?php echo json_encode($trafficTrendData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                12000,
                2800
            );

            var keywordSeries = normalizeSeries(
                <?php echo json_encode($keywordGrowthLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                <?php echo json_encode($keywordGrowthData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                28,
                7
            );

            createAreaChart(
                'score-trend-chart',
                scoreSeries.labels,
                scoreSeries.points,
                'rgb(79, 70, 229)',
                100
            );

            createAreaChart(
                'traffic-trend-chart',
                trafficSeries.labels,
                trafficSeries.points,
                'rgb(14, 165, 233)',
                undefined
            );

            createAreaChart(
                'keyword-growth-chart',
                keywordSeries.labels,
                keywordSeries.points,
                'rgb(34, 197, 94)',
                undefined
            );

            var gscTrend = <?php echo json_encode((array) ($searchConsoleSnapshot['trend'] ?? []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            if (Array.isArray(gscTrend) && gscTrend.length > 0) {
                var gscLabels = gscTrend.map(function (row) { return row.date || ''; });
                var gscClicks = gscTrend.map(function (row) { return Number(row.clicks || 0); });
                var gscCanvas = document.getElementById('dashboard-gsc-trend');
                if (gscCanvas && window.Chart) {
                    new Chart(gscCanvas.getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: gscLabels,
                            datasets: [{
                                label: 'Clicks',
                                data: gscClicks,
                                borderColor: 'rgb(79, 70, 229)',
                                backgroundColor: 'rgba(79, 70, 229, 0.16)',
                                borderWidth: 2.5,
                                fill: true,
                                tension: 0.32,
                                pointRadius: 1.8
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: {
                                y: { beginAtZero: true, ticks: { color: axisTextColor }, grid: { color: gridColor } },
                                x: { ticks: { color: axisTextColor }, grid: { display: false } }
                            }
                        }
                    });
                }
            }
        })();
    </script>
</body>
</html>
