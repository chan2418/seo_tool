<?php
session_start();
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';

$auth = AuthMiddleware::requireLogin(false);
AccountStatusMiddleware::ensureActive(false);

require_once __DIR__ . '/../services/SearchConsoleService.php';
require_once __DIR__ . '/../services/GoogleAuthService.php';
require_once __DIR__ . '/../services/PlanEnforcementService.php';

$userId = (int) ($auth['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$userName = (string) ($auth['user_name'] ?? ($_SESSION['user_name'] ?? 'User'));
$planService = new PlanEnforcementService();
$planType = $planService->getEffectivePlan($userId, (string) ($_SESSION['plan_type'] ?? 'free'));
$planLabel = ucfirst($planType);

$searchConsoleService = new SearchConsoleService();
$authService = new GoogleAuthService();
$disconnectCsrf = $authService->createFormCsrfToken('gsc_disconnect_csrf');

$projectContext = $searchConsoleService->getProjectsAndConnections($userId);
$projects = (array) ($projectContext['projects'] ?? []);
$connectionMap = (array) ($projectContext['connection_map'] ?? []);

$selectedProjectId = (int) ($_GET['project_id'] ?? 0);
if ($selectedProjectId <= 0 && !empty($projects)) {
    $selectedProjectId = (int) ($projects[0]['id'] ?? 0);
}

$allowedRangeByPlan = $planType === 'agency' ? [7, 28, 90, 180] : ($planType === 'pro' ? [7, 28, 90] : [7, 28]);
$days = (int) ($_GET['days'] ?? 28);
if (!in_array($days, $allowedRangeByPlan, true)) {
    $days = in_array(28, $allowedRangeByPlan, true) ? 28 : $allowedRangeByPlan[0];
}

$flash = $_SESSION['gsc_flash'] ?? null;
unset($_SESSION['gsc_flash']);

$errorMessage = '';
$successMessage = '';
if (is_array($flash)) {
    if (($flash['type'] ?? '') === 'success') {
        $successMessage = (string) ($flash['message'] ?? '');
    } else {
        $errorMessage = (string) ($flash['message'] ?? '');
    }
}

$forceRefresh = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = strtolower(trim((string) ($_POST['action'] ?? '')));
    if ($postedAction === 'refresh') {
        $selectedProjectId = (int) ($_POST['project_id'] ?? $selectedProjectId);
        $days = (int) ($_POST['days'] ?? $days);
        if (!in_array($days, $allowedRangeByPlan, true)) {
            $days = in_array(28, $allowedRangeByPlan, true) ? 28 : $allowedRangeByPlan[0];
        }
        $forceRefresh = true;
    }
}

$selectedProject = null;
foreach ($projects as $project) {
    if ((int) ($project['id'] ?? 0) === $selectedProjectId) {
        $selectedProject = $project;
        break;
    }
}

$performance = null;
$isConnected = false;
if ($selectedProject) {
    $isConnected = isset($connectionMap[(int) ($selectedProject['id'] ?? 0)]);
    if ($isConnected) {
        $performance = $searchConsoleService->fetchProjectPerformance($userId, $planType, (int) $selectedProject['id'], $days, $forceRefresh);
        if (empty($performance['success'])) {
            $errorMessage = (string) ($performance['error'] ?? 'Unable to fetch Search Console data.');
            $performance = null;
        } elseif ($forceRefresh) {
            $successMessage = 'Search Console data refreshed successfully.';
        }
    }
}

$overview = is_array($performance['overview'] ?? null) ? $performance['overview'] : [
    'total_clicks' => 0,
    'total_impressions' => 0,
    'avg_ctr' => 0,
    'avg_position' => 0,
];
$trend = is_array($performance['trend'] ?? null) ? $performance['trend'] : [];
$topQueries = is_array($performance['top_queries'] ?? null) ? $performance['top_queries'] : [];
$topPages = is_array($performance['top_pages'] ?? null) ? $performance['top_pages'] : [];
$lastUpdated = (string) ($performance['last_updated'] ?? '');
$googleProperty = (string) ($performance['connection']['google_property'] ?? ($connectionMap[$selectedProjectId]['google_property'] ?? ''));
$detailedDataAvailable = !empty($performance['detailed_data_available']) && $planType !== 'free';
$gscHasData = !empty($performance['has_data']);
$gscInfoMessage = trim((string) ($performance['message'] ?? ''));
$scope = is_array($performance['scope'] ?? null) ? $performance['scope'] : [];
$scopeProjectDomain = trim((string) ($scope['project_domain'] ?? ''));
$scopePropertyType = trim((string) ($scope['property_type'] ?? ''));
$scopePropertyHost = trim((string) ($scope['property_host'] ?? ''));
$scopePropertyPath = trim((string) ($scope['property_path'] ?? '/'));
$scopePageFilter = trim((string) ($scope['page_filter_expression'] ?? ''));

$trendLabels = [];
$trendClicks = [];
$trendImpressions = [];
$trendPosition = [];
foreach ($trend as $point) {
    $trendLabels[] = (string) ($point['date'] ?? '');
    $trendClicks[] = (float) ($point['clicks'] ?? 0);
    $trendImpressions[] = (float) ($point['impressions'] ?? 0);
    $trendPosition[] = (float) ($point['position'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="assets/images/favicon-180.png">
    <title>GSC Performance - SEO Audit SaaS</title>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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
            border: 1px solid rgba(255, 255, 255, 0.62);
            background: rgba(255, 255, 255, 0.86);
            backdrop-filter: blur(12px);
        }
        .dark .surface-card {
            border-color: rgba(51, 65, 85, 0.82);
            background: rgba(30, 41, 59, 0.84);
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute left-[-8rem] top-[-9rem] h-80 w-80 rounded-full bg-indigo-300/40 blur-3xl dark:bg-indigo-500/20"></div>
        <div class="absolute right-[-7rem] top-16 h-72 w-72 rounded-full bg-sky-200/55 blur-3xl dark:bg-sky-500/15"></div>
        <div class="absolute bottom-[-8rem] left-1/2 h-72 w-72 -translate-x-1/2 rounded-full bg-violet-200/55 blur-3xl dark:bg-violet-500/15"></div>
    </div>

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="lg:pl-72">
        <header class="sticky top-0 z-20 border-b border-white/50 bg-slate-100/75 px-4 py-4 backdrop-blur-xl dark:border-slate-700/70 dark:bg-slate-950/70 sm:px-6 lg:px-10">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <button id="sidebar-open" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 lg:hidden" aria-label="Open sidebar">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"></path></svg>
                    </button>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Google Search Console</p>
                        <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Performance Analytics</h1>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button id="theme-toggle" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:text-brand-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300" aria-label="Toggle theme">
                        <svg class="h-5 w-5 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="4"></circle><path stroke-linecap="round" d="M12 3v2.2M12 18.8V21M3 12h2.2M18.8 12H21M5.64 5.64l1.55 1.55M16.81 16.81l1.55 1.55M5.64 18.36l1.55-1.55M16.81 7.19l1.55-1.55"></path></svg>
                        <svg class="hidden h-5 w-5 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1 1 11.21 3c.11 0 .23 0 .34.01a1 1 0 0 1 .54 1.82A7 7 0 0 0 19.17 12a1 1 0 0 1 1.83.79Z"></path></svg>
                    </button>
                    <span class="hidden rounded-xl px-3 py-2 text-xs font-bold tracking-wide sm:inline-flex <?php echo in_array($planType, ['pro', 'agency'], true) ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'; ?>">
                        <?php echo htmlspecialchars($planLabel); ?> Plan
                    </span>
                    <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-3 py-2 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <div class="flex h-9 w-9 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"><img src="assets/images/logo-256.png" alt="Serponiq logo" class="h-full w-full object-contain p-1"></div>
                        <p class="hidden text-sm font-semibold sm:block"><?php echo htmlspecialchars($userName); ?></p>
                    </div>
                </div>
            </div>
        </header>

        <main class="space-y-6 px-4 pb-10 pt-6 sm:px-6 lg:px-10">
            <?php if ($successMessage !== ''): ?>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"><?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if ($errorMessage !== ''): ?>
                <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300"><?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
                    <div>
                        <h2 class="text-2xl font-extrabold">Track real Google Search Console performance</h2>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Overview cards, trend chart, top queries, and top pages are cached and refreshed once every 24 hours.</p>
                    </div>
                    <div class="rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 p-4 text-white shadow-soft">
                        <p class="text-xs uppercase tracking-[0.18em] text-indigo-100">Connection</p>
                        <p class="mt-2 text-sm font-bold"><?php echo $isConnected ? 'Connected' : 'Not connected'; ?></p>
                        <p class="mt-1 text-xs text-indigo-100"><?php echo $googleProperty !== '' ? htmlspecialchars($googleProperty) : 'Select project and connect to start'; ?></p>
                        <?php if ($isConnected && ($scopePageFilter !== '' || $scopeProjectDomain !== '')): ?>
                            <p class="mt-2 text-[11px] text-indigo-100/95">
                                Scope: <?php echo htmlspecialchars($scopeProjectDomain !== '' ? $scopeProjectDomain : ($scopePropertyHost !== '' ? $scopePropertyHost : 'property')); ?>
                                <?php if ($scopePropertyType !== ''): ?>(<?php echo htmlspecialchars($scopePropertyType); ?>)<?php endif; ?>
                            </p>
                            <?php if ($scopePropertyType === 'url-prefix' && $scopePropertyPath !== ''): ?>
                                <p class="text-[11px] text-indigo-100/95">Path: <?php echo htmlspecialchars($scopePropertyPath); ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="get" class="mt-6 grid gap-3 md:grid-cols-4">
                    <div class="md:col-span-2">
                        <label class="mb-2 block text-sm font-semibold">Project</label>
                        <select name="project_id" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900">
                            <?php if (empty($projects)): ?>
                                <option value="">No projects found</option>
                            <?php else: ?>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo (int) ($project['id'] ?? 0); ?>" <?php echo (int) ($project['id'] ?? 0) === $selectedProjectId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) ($project['name'] ?? 'Project') . ' (' . (string) ($project['domain'] ?? '-') . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold">Date Range</label>
                        <select name="days" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900">
                            <?php foreach ($allowedRangeByPlan as $allowedDays): ?>
                                <option value="<?php echo (int) $allowedDays; ?>" <?php echo $allowedDays === $days ? 'selected' : ''; ?>>Last <?php echo (int) $allowedDays; ?> days</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Apply</button>
                    </div>
                </form>

                <?php if ($selectedProject && !$isConnected): ?>
                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        <a href="connect-gsc?project_id=<?php echo (int) $selectedProjectId; ?>&return_to=<?php echo urlencode('performance?project_id=' . $selectedProjectId . '&days=' . $days); ?>" class="rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-5 py-2 text-sm font-semibold text-white shadow-soft">
                            Connect Google Search Console
                        </a>
                        <p class="text-xs text-slate-500 dark:text-slate-400">You will be redirected to Google OAuth and then choose the property.</p>
                    </div>
                <?php elseif ($selectedProject && $isConnected): ?>
                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        <?php if (in_array($planType, ['pro', 'agency'], true)): ?>
                            <form method="post">
                                <input type="hidden" name="action" value="refresh">
                                <input type="hidden" name="project_id" value="<?php echo (int) $selectedProjectId; ?>">
                                <input type="hidden" name="days" value="<?php echo (int) $days; ?>">
                                <button type="submit" class="rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-5 py-2 text-sm font-semibold text-white shadow-soft">Refresh Data</button>
                            </form>
                        <?php else: ?>
                            <span class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-2 text-xs font-semibold text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">Manual refresh available on Pro and Agency</span>
                        <?php endif; ?>

                        <form method="post" action="connect-gsc" onsubmit="return confirm('Disconnect Search Console from this project?');">
                            <input type="hidden" name="action" value="disconnect">
                            <input type="hidden" name="project_id" value="<?php echo (int) $selectedProjectId; ?>">
                            <input type="hidden" name="return_to" value="<?php echo htmlspecialchars('performance?project_id=' . $selectedProjectId . '&days=' . $days); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($disconnectCsrf); ?>">
                            <button type="submit" class="rounded-xl border border-red-300 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-100 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">Disconnect</button>
                        </form>

                        <?php if ($lastUpdated !== ''): ?>
                            <span class="text-xs text-slate-500 dark:text-slate-400">Last updated: <?php echo htmlspecialchars(date('M d, Y H:i', strtotime($lastUpdated))); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($selectedProject && $isConnected): ?>
                <?php if (!$gscHasData): ?>
                    <section class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                        <?php echo htmlspecialchars($gscInfoMessage !== '' ? $gscInfoMessage : 'No Search Console metrics available yet for this range.'); ?>
                        Check that the selected property matches exactly (`sc-domain:` or URL-prefix), and note that Google can delay fresh data by 24-72 hours.
                    </section>
                <?php endif; ?>

                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="surface-card p-5 shadow-soft">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Total Clicks</p>
                        <p class="mt-2 text-3xl font-extrabold"><?php echo number_format((float) ($overview['total_clicks'] ?? 0)); ?></p>
                    </article>
                    <article class="surface-card p-5 shadow-soft">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Impressions</p>
                        <p class="mt-2 text-3xl font-extrabold"><?php echo number_format((float) ($overview['total_impressions'] ?? 0)); ?></p>
                    </article>
                    <article class="surface-card p-5 shadow-soft">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Average CTR</p>
                        <p class="mt-2 text-3xl font-extrabold"><?php echo number_format(((float) ($overview['avg_ctr'] ?? 0)) * 100, 2); ?>%</p>
                    </article>
                    <article class="surface-card p-5 shadow-soft">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Avg Position</p>
                        <p class="mt-2 text-3xl font-extrabold"><?php echo number_format((float) ($overview['avg_position'] ?? 0), 2); ?></p>
                    </article>
                </section>

                <section class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
                    <article class="surface-card p-6 shadow-soft sm:p-8">
                        <h3 class="text-xl font-bold">Clicks & Impressions Trend</h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Daily trend for selected range.</p>
                        <?php if (!empty($trend)): ?>
                            <div class="mt-4 h-80">
                                <canvas id="gsc-trend-chart"></canvas>
                            </div>
                        <?php else: ?>
                            <div class="mt-4 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-300">
                                Trend data is not available yet for this project.
                            </div>
                        <?php endif; ?>
                    </article>

                    <article class="surface-card p-6 shadow-soft sm:p-8">
                        <h3 class="text-xl font-bold">Position Trend</h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Lower values are better.</p>
                        <?php if (!empty($trend)): ?>
                            <div class="mt-4 h-80">
                                <canvas id="gsc-position-chart"></canvas>
                            </div>
                        <?php else: ?>
                            <div class="mt-4 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-300">
                                Position trend will appear once Search Console returns daily rows.
                            </div>
                        <?php endif; ?>
                    </article>
                </section>

                <section class="grid gap-6 xl:grid-cols-2">
                    <article class="surface-card p-6 shadow-soft sm:p-8">
                        <h3 class="text-lg font-bold">Top Queries</h3>
                        <?php if ($detailedDataAvailable): ?>
                            <div class="mt-4 overflow-x-auto">
                                <table class="w-full min-w-[560px] text-sm">
                                    <thead>
                                        <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-[0.12em] text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                            <th class="pb-3 pr-2">Query</th>
                                            <th class="pb-3 pr-2">Clicks</th>
                                            <th class="pb-3 pr-2">Impr.</th>
                                            <th class="pb-3 pr-2">CTR</th>
                                            <th class="pb-3">Pos.</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($topQueries)): ?>
                                            <?php foreach ($topQueries as $row): ?>
                                                <tr class="border-b border-slate-100 dark:border-slate-800">
                                                    <td class="py-3 pr-2 font-semibold"><?php echo htmlspecialchars((string) ($row['query'] ?? '')); ?></td>
                                                    <td class="py-3 pr-2"><?php echo number_format((float) ($row['clicks'] ?? 0)); ?></td>
                                                    <td class="py-3 pr-2"><?php echo number_format((float) ($row['impressions'] ?? 0)); ?></td>
                                                    <td class="py-3 pr-2"><?php echo number_format(((float) ($row['ctr'] ?? 0)) * 100, 2); ?>%</td>
                                                    <td class="py-3"><?php echo number_format((float) ($row['position'] ?? 0), 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="5" class="py-4 text-sm text-slate-500 dark:text-slate-400">No query data available.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                                Top queries are available on Pro and Agency plans.
                            </div>
                        <?php endif; ?>
                    </article>

                    <article class="surface-card p-6 shadow-soft sm:p-8">
                        <h3 class="text-lg font-bold">Top Pages</h3>
                        <?php if ($detailedDataAvailable): ?>
                            <div class="mt-4 overflow-x-auto">
                                <table class="w-full min-w-[560px] text-sm">
                                    <thead>
                                        <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-[0.12em] text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                            <th class="pb-3 pr-2">Page URL</th>
                                            <th class="pb-3 pr-2">Clicks</th>
                                            <th class="pb-3 pr-2">Impr.</th>
                                            <th class="pb-3 pr-2">CTR</th>
                                            <th class="pb-3">Pos.</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($topPages)): ?>
                                            <?php foreach ($topPages as $row): ?>
                                                <tr class="border-b border-slate-100 dark:border-slate-800">
                                                    <td class="py-3 pr-2">
                                                        <?php
                                                            $url = (string) ($row['page_url'] ?? '');
                                                            $short = strlen($url) > 70 ? substr($url, 0, 67) . '...' : $url;
                                                        ?>
                                                        <span title="<?php echo htmlspecialchars($url); ?>" class="font-semibold"><?php echo htmlspecialchars($short); ?></span>
                                                    </td>
                                                    <td class="py-3 pr-2"><?php echo number_format((float) ($row['clicks'] ?? 0)); ?></td>
                                                    <td class="py-3 pr-2"><?php echo number_format((float) ($row['impressions'] ?? 0)); ?></td>
                                                    <td class="py-3 pr-2"><?php echo number_format(((float) ($row['ctr'] ?? 0)) * 100, 2); ?>%</td>
                                                    <td class="py-3"><?php echo number_format((float) ($row['position'] ?? 0), 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="5" class="py-4 text-sm text-slate-500 dark:text-slate-400">No page data available.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                                Top pages are available on Pro and Agency plans.
                            </div>
                        <?php endif; ?>
                    </article>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <script>
        (function () {
            var themeButton = document.getElementById('theme-toggle');
            if (themeButton) {
                themeButton.addEventListener('click', function () {
                    document.documentElement.classList.toggle('dark');
                    localStorage.setItem('seo-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
                });
            }

            var trendLabels = <?php echo json_encode($trendLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            var trendClicks = <?php echo json_encode($trendClicks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            var trendImpressions = <?php echo json_encode($trendImpressions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            var trendPosition = <?php echo json_encode($trendPosition, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

            if (!Array.isArray(trendLabels) || trendLabels.length === 0) {
                return;
            }

            var darkMode = document.documentElement.classList.contains('dark');
            var axisColor = darkMode ? '#CBD5E1' : '#334155';
            var gridColor = darkMode ? 'rgba(148, 163, 184, 0.18)' : 'rgba(148, 163, 184, 0.2)';

            var trendCanvas = document.getElementById('gsc-trend-chart');
            if (trendCanvas) {
                new Chart(trendCanvas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: trendLabels,
                        datasets: [
                            {
                                label: 'Clicks',
                                data: trendClicks,
                                borderColor: '#4F46E5',
                                backgroundColor: 'rgba(79, 70, 229, 0.18)',
                                borderWidth: 2.5,
                                fill: true,
                                tension: 0.3
                            },
                            {
                                label: 'Impressions',
                                data: trendImpressions,
                                borderColor: '#0EA5E9',
                                backgroundColor: 'rgba(14, 165, 233, 0.12)',
                                borderWidth: 2.5,
                                fill: true,
                                tension: 0.3
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend: { labels: { color: axisColor } } },
                        scales: {
                            x: { ticks: { color: axisColor }, grid: { display: false } },
                            y: { ticks: { color: axisColor }, grid: { color: gridColor }, beginAtZero: true }
                        }
                    }
                });
            }

            var positionCanvas = document.getElementById('gsc-position-chart');
            if (positionCanvas) {
                new Chart(positionCanvas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: trendLabels,
                        datasets: [
                            {
                                label: 'Average Position',
                                data: trendPosition,
                                borderColor: '#F59E0B',
                                backgroundColor: 'rgba(245, 158, 11, 0.16)',
                                borderWidth: 2.5,
                                fill: true,
                                tension: 0.3
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { labels: { color: axisColor } } },
                        scales: {
                            x: { ticks: { color: axisColor }, grid: { display: false } },
                            y: { ticks: { color: axisColor }, grid: { color: gridColor }, reverse: true }
                        }
                    }
                });
            }
        })();
    </script>
</body>
</html>
