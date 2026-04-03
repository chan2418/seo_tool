<?php
session_start();
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';

$auth = AuthMiddleware::requireLogin(false);
AccountStatusMiddleware::ensureActive(false);

require_once __DIR__ . '/../models/AuditModel.php';
require_once __DIR__ . '/../services/PlanEnforcementService.php';

$userName = (string) ($auth['user_name'] ?? ($_SESSION['user_name'] ?? 'User'));
$userId = (int) ($auth['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$planService = new PlanEnforcementService();
$planType = $planService->getEffectivePlan($userId, (string) ($_SESSION['plan_type'] ?? 'free'));
$planLabel = ucfirst($planType);
$historyAccess = $planService->assertFeatureAccess($userId, 'audit_history');
$hasHistoryAccess = (bool) ($historyAccess['allowed'] ?? false);

if (!$hasHistoryAccess) {
    http_response_code(403);
}

$audits = [];
$auditCount = 0;
$chartLabels = [];
$chartData = [];
$topDomain = 'No domain audited yet';
$avgScore = 0;

if ($hasHistoryAccess) {
    $auditModel = new AuditModel();
    $audits = $auditModel->getUserAudits($userId);
    $auditCount = count($audits);

    $chartAudits = array_reverse(array_slice($audits, 0, 24));
    foreach ($chartAudits as $audit) {
        $chartLabels[] = date('M d', strtotime($audit['created_at']));
        $chartData[] = (int) ($audit['seo_score'] ?? 0);
    }

    $latestAudit = $audits[0] ?? null;
    if ($latestAudit && !empty($latestAudit['url'])) {
        $host = parse_url($latestAudit['url'], PHP_URL_HOST);
        $topDomain = $host ?: $latestAudit['url'];
    }

    if ($auditCount > 0) {
        $total = 0;
        foreach ($audits as $audit) {
            $total += (int) ($audit['seo_score'] ?? 0);
        }
        $avgScore = (int) round($total / $auditCount);
    }
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
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="assets/images/favicon-180.png">
    <title>Audit History - SEO Audit SaaS</title>
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

        .history-card {
            transition: transform 160ms ease, box-shadow 160ms ease;
        }

        .history-card:hover {
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
        <div class="absolute left-[-10rem] top-[-9rem] h-80 w-80 rounded-full bg-indigo-300/40 blur-3xl dark:bg-indigo-500/20"></div>
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
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Analytics Timeline</p>
                        <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Audit History</h1>
                    </div>
                </div>

                <div class="flex items-center gap-3">
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
                        <div class="flex h-9 w-9 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"><img src="assets/images/logo-256.png" alt="Serponiq logo" class="h-full w-full object-contain p-1"></div>
                        <div class="hidden sm:block">
                            <p class="text-xs text-slate-500 dark:text-slate-400">Analyst</p>
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($userName); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="space-y-6 px-4 pb-10 pt-6 sm:px-6 lg:px-10">
            <?php if (!$hasHistoryAccess): ?>
                <section class="surface-card p-6 shadow-soft sm:p-8">
                    <h2 class="text-2xl font-extrabold text-slate-900 dark:text-slate-100">Audit history is locked on Free plan</h2>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Upgrade to Pro or Agency to unlock timeline charts and historical report views.</p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <a href="subscription" class="inline-flex items-center rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-4 py-2 text-sm font-semibold text-white shadow-soft transition hover:opacity-90">View Plans</a>
                    </div>
                </section>
            <?php else: ?>
            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 p-5 text-white shadow-soft md:col-span-2">
                        <p class="text-xs uppercase tracking-[0.2em] text-indigo-100">Top Domain</p>
                        <p class="mt-2 text-2xl font-extrabold"><?php echo htmlspecialchars($topDomain); ?></p>
                        <p class="mt-2 text-xs text-indigo-100">Most recent audited website</p>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Total Audits</p>
                        <p class="mt-2 text-3xl font-extrabold text-slate-900 dark:text-slate-100"><?php echo $auditCount; ?></p>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Average Score</p>
                        <p class="mt-2 text-3xl font-extrabold <?php echo $avgScore >= 80 ? 'text-emerald-600 dark:text-emerald-400' : ($avgScore >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400'); ?>"><?php echo $auditCount > 0 ? $avgScore : '--'; ?></p>
                    </article>
                </div>
            </section>

            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900 dark:text-slate-100">Score Trend</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Timeline of your latest 24 audits.</p>
                    </div>
                    <a href="/#run-audit" class="inline-flex items-center rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-4 py-2 text-sm font-semibold text-white shadow-soft transition hover:opacity-90">Run New Audit</a>
                </div>
                <div class="mt-6 h-72">
                    <canvas id="history-chart"></canvas>
                </div>
                <?php if (empty($chartData)): ?>
                    <div class="mt-4 rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-500 dark:border-slate-600 dark:text-slate-300">
                        No trend data yet. Complete an audit to populate this chart.
                    </div>
                <?php endif; ?>
            </section>

            <section class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold text-slate-900 dark:text-slate-100">Audit Cards by Date</h2>
                    <span class="rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-200"><?php echo $auditCount; ?> entries</span>
                </div>

                <?php if ($auditCount > 0): ?>
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <?php foreach ($audits as $audit): ?>
                            <?php
                                $score = (int) ($audit['seo_score'] ?? 0);
                                $domain = parse_url($audit['url'], PHP_URL_HOST);
                                $domain = $domain ?: $audit['url'];
                            ?>
                            <article class="history-card surface-card p-5 shadow-soft">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-bold text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($domain); ?></p>
                                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400"><?php echo date('M d, Y', strtotime($audit['created_at'])); ?></p>
                                    </div>
                                    <span class="rounded-full px-3 py-1 text-xs font-semibold <?php echo scoreBadgeClass($score); ?>">Score <?php echo $score; ?></span>
                                </div>

                                <p class="mt-4 truncate text-xs text-slate-500 dark:text-slate-400" title="<?php echo htmlspecialchars($audit['url']); ?>"><?php echo htmlspecialchars($audit['url']); ?></p>

                                <a href="results?id=<?php echo (int) $audit['id']; ?>" class="mt-4 inline-flex items-center text-sm font-semibold text-brand-500 transition hover:text-brand-600">
                                    View Report ->
                                </a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div class="skeleton h-36 rounded-2xl"></div>
                        <div class="skeleton h-36 rounded-2xl"></div>
                        <div class="rounded-2xl border border-dashed border-slate-300 p-5 text-sm text-slate-500 dark:border-slate-600 dark:text-slate-300">
                            No audits yet. Start your first scan to build history.
                        </div>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        (function () {
            var sidebar = document.getElementById('app-sidebar');
            var overlay = document.getElementById('sidebar-overlay');
            var openButton = document.getElementById('sidebar-open');
            var themeButton = document.getElementById('theme-toggle');

            function openSidebar() {
                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('hidden');
            }

            function closeSidebar() {
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
            }

            openButton && openButton.addEventListener('click', openSidebar);
            overlay && overlay.addEventListener('click', closeSidebar);

            themeButton && themeButton.addEventListener('click', function () {
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('seo-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
            });

            var chartCanvas = document.getElementById('history-chart');
            var labels = <?php echo json_encode($chartLabels); ?>;
            var points = <?php echo json_encode($chartData); ?>;

            if (chartCanvas && labels.length > 0 && points.length > 0) {
                var context = chartCanvas.getContext('2d');
                var gradient = context.createLinearGradient(0, 0, 0, 280);
                gradient.addColorStop(0, 'rgba(79, 70, 229, 0.30)');
                gradient.addColorStop(1, 'rgba(79, 70, 229, 0.03)');

                var darkMode = document.documentElement.classList.contains('dark');

                new Chart(context, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'SEO Score',
                            data: points,
                            borderColor: '#4F46E5',
                            backgroundColor: gradient,
                            borderWidth: 3,
                            fill: true,
                            tension: 0.35,
                            pointRadius: 2.8,
                            pointHoverRadius: 5,
                            pointBackgroundColor: '#6366F1'
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
                                max: 100,
                                grid: {
                                    color: darkMode ? 'rgba(148, 163, 184, 0.18)' : 'rgba(148, 163, 184, 0.20)'
                                },
                                ticks: {
                                    color: darkMode ? '#CBD5E1' : '#475569'
                                }
                            },
                            x: {
                                grid: { display: false },
                                ticks: {
                                    color: darkMode ? '#CBD5E1' : '#475569'
                                }
                            }
                        }
                    }
                });
            }
        })();
    </script>
</body>
</html>
