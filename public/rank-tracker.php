<?php
session_start();
require_once __DIR__ . '/../services/PlanEnforcementService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';

$auth = AuthMiddleware::requireLogin(false);
AccountStatusMiddleware::ensureActive(false);

$userId = (int) ($auth['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$planService = new PlanEnforcementService();
$userName = (string) ($auth['user_name'] ?? ($_SESSION['user_name'] ?? 'User'));
$planType = $planService->getEffectivePlan($userId, (string) ($_SESSION['plan_type'] ?? 'free'));
$planLabel = ucfirst($planType);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="assets/images/favicon-180.png">
    <title>Rank Tracker - SEO Audit SaaS</title>
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

        .rank-row {
            transition: background-color 160ms ease;
        }

        .rank-row:hover {
            background: rgba(99, 102, 241, 0.08);
        }

        .trend-up {
            color: #22C55E;
        }

        .trend-down {
            color: #EF4444;
        }

        .trend-flat {
            color: #64748B;
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
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"></path>
                        </svg>
                    </button>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Rank Intelligence</p>
                        <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Project Rank Tracker</h1>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button id="theme-toggle" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:text-brand-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300" aria-label="Toggle theme">🌓</button>
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
            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
                    <div>
                        <span class="inline-flex items-center gap-2 rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">
                            Daily Position Tracking
                        </span>
                        <h2 class="mt-4 text-3xl font-extrabold text-slate-900 dark:text-slate-100">Track rankings by project, country, and device</h2>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Add keywords, monitor daily movement, and detect drops before they hurt traffic.</p>
                    </div>
                    <div class="rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 p-5 text-white shadow-soft">
                        <p class="text-xs uppercase tracking-[0.18em] text-indigo-100">Plan Capacity</p>
                        <p id="plan-limit-text" class="mt-2 text-xl font-bold">Loading...</p>
                        <p class="mt-2 text-xs text-indigo-100">Server-side limits enforced per project.</p>
                    </div>
                </div>

                <div id="message-box" class="mt-5 hidden rounded-2xl border p-4 text-sm font-medium"></div>

                <form id="add-keyword-form" class="mt-6 grid gap-3 md:grid-cols-5">
                    <div class="md:col-span-2">
                        <label for="keyword-input" class="mb-2 block text-sm font-semibold">Keyword</label>
                        <input id="keyword-input" type="text" maxlength="100" placeholder="e.g. seo audit tool" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30" required>
                    </div>
                    <div>
                        <label for="project-select" class="mb-2 block text-sm font-semibold">Project</label>
                        <select id="project-select" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30"></select>
                    </div>
                    <div>
                        <label for="country-select" class="mb-2 block text-sm font-semibold">Country</label>
                        <select id="country-select" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30"></select>
                    </div>
                    <div>
                        <label for="device-select" class="mb-2 block text-sm font-semibold">Device</label>
                        <select id="device-select" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30">
                            <option value="desktop">Desktop</option>
                            <option value="mobile">Mobile</option>
                        </select>
                    </div>
                    <div class="md:col-span-5 flex flex-wrap items-center gap-3">
                        <button id="add-keyword-btn" type="submit" class="rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-5 py-3 text-sm font-semibold text-white shadow-soft transition hover:opacity-90">Add Keyword</button>
                        <button id="run-check-btn" type="button" class="rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Run Daily Check</button>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Daily automation is supported via cron script.</p>
                    </div>
                </form>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="surface-card p-5 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Total Tracked</p>
                    <p id="metric-total" class="mt-2 text-3xl font-extrabold">0</p>
                </article>
                <article class="surface-card p-5 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Average Position</p>
                    <p id="metric-avg" class="mt-2 text-3xl font-extrabold">0</p>
                </article>
                <article class="surface-card p-5 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Top 10 Keywords</p>
                    <p id="metric-top10" class="mt-2 text-3xl font-extrabold">0</p>
                </article>
                <article class="surface-card p-5 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Last Updated</p>
                    <p id="metric-updated" class="mt-2 text-sm font-bold">-</p>
                </article>
            </section>

            <section class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                <article class="surface-card p-6 shadow-soft">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h3 class="text-lg font-bold">Ranking Distribution</h3>
                        <div class="grid grid-cols-2 gap-2 text-xs font-semibold sm:flex">
                            <span id="dist-top3" class="rounded-lg bg-emerald-100 px-2 py-1 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">Top 3: 0</span>
                            <span id="dist-top10" class="rounded-lg bg-sky-100 px-2 py-1 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300">Top 10: 0</span>
                            <span id="dist-top50" class="rounded-lg bg-amber-100 px-2 py-1 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">Top 50: 0</span>
                            <span id="dist-above50" class="rounded-lg bg-red-100 px-2 py-1 text-red-700 dark:bg-red-500/20 dark:text-red-300">50+: 0</span>
                        </div>
                    </div>
                    <div class="h-72">
                        <canvas id="distribution-chart"></canvas>
                    </div>
                </article>

                <article class="surface-card p-6 shadow-soft">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h3 class="text-lg font-bold">Keyword History (30 Days)</h3>
                        <select id="history-keyword-select" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold outline-none dark:border-slate-700 dark:bg-slate-900"></select>
                    </div>
                    <div class="h-72">
                        <canvas id="history-chart"></canvas>
                    </div>
                </article>
            </section>

            <section class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                <article class="surface-card p-6 shadow-soft">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <h3 class="text-lg font-bold">Tracked Keywords</h3>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <select id="status-filter" class="rounded-xl border border-slate-300 bg-white px-3 py-2 outline-none dark:border-slate-700 dark:bg-slate-900">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="paused">Paused</option>
                            </select>
                            <select id="sort-select" class="rounded-xl border border-slate-300 bg-white px-3 py-2 outline-none dark:border-slate-700 dark:bg-slate-900">
                                <option value="current_rank|asc">Rank (Best First)</option>
                                <option value="current_rank|desc">Rank (Worst First)</option>
                                <option value="change|desc">Change (Best First)</option>
                                <option value="change|asc">Change (Worst First)</option>
                                <option value="keyword|asc">Keyword A-Z</option>
                                <option value="search_volume|desc">Volume High-Low</option>
                            </select>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[860px] text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-[0.14em] text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                    <th class="pb-3 pr-3">Keyword</th>
                                    <th class="pb-3 pr-3">Current</th>
                                    <th class="pb-3 pr-3">Change</th>
                                    <th class="pb-3 pr-3">Best</th>
                                    <th class="pb-3 pr-3">Volume</th>
                                    <th class="pb-3 pr-3">Last Updated</th>
                                    <th class="pb-3 pr-3">Status</th>
                                    <th class="pb-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="keyword-table-body"></tbody>
                        </table>
                    </div>

                    <div id="table-empty" class="hidden rounded-xl border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                        No tracked keywords yet for this project.
                    </div>

                    <div class="mt-4 flex items-center justify-between text-sm">
                        <button id="prev-page" class="rounded-xl border border-slate-300 bg-white px-4 py-2 font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Previous</button>
                        <p id="pagination-label" class="text-slate-600 dark:text-slate-300">Page 1</p>
                        <button id="next-page" class="rounded-xl border border-slate-300 bg-white px-4 py-2 font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Next</button>
                    </div>
                </article>

                <article class="space-y-6">
                    <div class="surface-card p-6 shadow-soft">
                        <h3 class="text-lg font-bold">Top Gaining Keywords</h3>
                        <ul id="gainers-list" class="mt-3 space-y-3 text-sm"></ul>
                    </div>
                    <div class="surface-card p-6 shadow-soft">
                        <h3 class="text-lg font-bold">Top Losing Keywords</h3>
                        <ul id="losers-list" class="mt-3 space-y-3 text-sm"></ul>
                    </div>
                    <div class="surface-card p-6 shadow-soft">
                        <h3 class="text-lg font-bold">Rank Alerts</h3>
                        <ul id="alerts-list" class="mt-3 space-y-3 text-sm"></ul>
                    </div>
                </article>
            </section>
        </main>
    </div>

    <script>
        (function () {
            var state = {
                page: 1,
                perPage: 10,
                projectId: null,
                status: '',
                sortBy: 'current_rank',
                sortDir: 'asc',
                projects: [],
                countries: {},
                keywords: [],
                pagination: null,
                distributionChart: null,
                historyChart: null
            };

            var form = document.getElementById('add-keyword-form');
            var keywordInput = document.getElementById('keyword-input');
            var projectSelect = document.getElementById('project-select');
            var countrySelect = document.getElementById('country-select');
            var deviceSelect = document.getElementById('device-select');
            var runCheckButton = document.getElementById('run-check-btn');
            var statusFilter = document.getElementById('status-filter');
            var sortSelect = document.getElementById('sort-select');
            var tableBody = document.getElementById('keyword-table-body');
            var tableEmpty = document.getElementById('table-empty');
            var prevPageButton = document.getElementById('prev-page');
            var nextPageButton = document.getElementById('next-page');
            var paginationLabel = document.getElementById('pagination-label');
            var messageBox = document.getElementById('message-box');
            var historyKeywordSelect = document.getElementById('history-keyword-select');
            var gainersList = document.getElementById('gainers-list');
            var losersList = document.getElementById('losers-list');
            var alertsList = document.getElementById('alerts-list');

            function showMessage(type, message) {
                var classMap = {
                    success: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
                    warning: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
                    error: 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300',
                    info: 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-600 dark:bg-slate-800/60 dark:text-slate-200'
                };

                messageBox.className = 'mt-5 rounded-2xl border p-4 text-sm font-medium';
                messageBox.classList.add('block');
                (classMap[type] || classMap.info).split(' ').forEach(function (name) {
                    messageBox.classList.add(name);
                });
                messageBox.textContent = message;
            }

            function clearMessage() {
                messageBox.classList.add('hidden');
                messageBox.textContent = '';
            }

            function api(action, payload) {
                var body = Object.assign({ action: action }, payload || {});
                return fetch('rank-tracker-data.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                }).then(function (response) {
                    return response.text().then(function (text) {
                        var json;
                        try {
                            json = JSON.parse(text);
                        } catch (error) {
                            json = { success: false, error: 'Invalid server response.' };
                        }

                        if (!response.ok || !json.success) {
                            var err = new Error(json.error || 'Request failed');
                            err.payload = json;
                            throw err;
                        }
                        return json;
                    });
                });
            }

            function formatDate(value) {
                if (!value) {
                    return '-';
                }
                var date = new Date(value);
                if (isNaN(date.getTime())) {
                    return value;
                }
                return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
            }

            function applyPlanText(limits) {
                if (!limits) {
                    return;
                }
                document.getElementById('plan-limit-text').textContent =
                    (limits.plan_type || '<?php echo htmlspecialchars($planType, ENT_QUOTES, 'UTF-8'); ?>').toUpperCase()
                    + ' · ' + (limits.used_keywords || 0) + '/' + (limits.keyword_limit || 0) + ' keywords';
            }

            function renderProjects(projects, selectedProjectId) {
                state.projects = projects || [];
                projectSelect.innerHTML = '';
                if (state.projects.length === 0) {
                    projectSelect.innerHTML = '<option value="">No projects found</option>';
                    return;
                }

                state.projects.forEach(function (project) {
                    var option = document.createElement('option');
                    option.value = String(project.id || '');
                    option.textContent = (project.name || project.domain || 'Project') + ' (' + (project.domain || '-') + ')';
                    if (Number(project.id) === Number(selectedProjectId)) {
                        option.selected = true;
                    }
                    projectSelect.appendChild(option);
                });
            }

            function renderCountries(countries) {
                state.countries = countries || {};
                countrySelect.innerHTML = '';
                Object.keys(state.countries).forEach(function (code) {
                    var option = document.createElement('option');
                    option.value = code;
                    option.textContent = state.countries[code];
                    if (code === 'US') {
                        option.selected = true;
                    }
                    countrySelect.appendChild(option);
                });
            }

            function renderSummary(summary) {
                var distribution = summary.distribution || {};
                document.getElementById('metric-total').textContent = String(summary.total_tracked_keywords || 0);
                document.getElementById('metric-avg').textContent = String(summary.average_position || 0);
                document.getElementById('metric-top10').textContent = String(distribution.top10 || 0);
                document.getElementById('metric-updated').textContent = formatDate(summary.last_updated || '');
                document.getElementById('dist-top3').textContent = 'Top 3: ' + (distribution.top3 || 0);
                document.getElementById('dist-top10').textContent = 'Top 10: ' + (distribution.top10 || 0);
                document.getElementById('dist-top50').textContent = 'Top 50: ' + (distribution.top50 || 0);
                document.getElementById('dist-above50').textContent = '50+: ' + (distribution.above50 || 0);

                renderSimpleList(gainersList, summary.top_gainers || [], 'No gaining keywords yet.', true);
                renderSimpleList(losersList, summary.top_losers || [], 'No losing keywords yet.', false);

                var chartData = [
                    distribution.top3 || 0,
                    Math.max(0, (distribution.top10 || 0) - (distribution.top3 || 0)),
                    Math.max(0, (distribution.top50 || 0) - (distribution.top10 || 0)),
                    distribution.above50 || 0
                ];

                var ctx = document.getElementById('distribution-chart').getContext('2d');
                if (state.distributionChart) {
                    state.distributionChart.destroy();
                }

                state.distributionChart = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Top 3', '4-10', '11-50', '50+'],
                        datasets: [{
                            data: chartData,
                            backgroundColor: ['#22C55E', '#0EA5E9', '#F59E0B', '#EF4444'],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { color: document.documentElement.classList.contains('dark') ? '#CBD5E1' : '#334155' }
                            }
                        },
                        cutout: '68%'
                    }
                });
            }

            function renderSimpleList(container, list, emptyText, positive) {
                container.innerHTML = '';
                if (!list.length) {
                    container.innerHTML = '<li class="rounded-xl border border-dashed border-slate-300 px-3 py-2 text-slate-500 dark:border-slate-700 dark:text-slate-400">' + emptyText + '</li>';
                    return;
                }

                list.forEach(function (item) {
                    var li = document.createElement('li');
                    li.className = 'rounded-xl border border-slate-200 bg-white/80 px-3 py-2 dark:border-slate-700 dark:bg-slate-800/70';
                    var changeValue = Number(item.change || 0);
                    var changeLabel = (changeValue > 0 ? '+' : '') + String(changeValue);
                    li.innerHTML =
                        '<div class="flex items-center justify-between gap-2">' +
                        '<p class="font-semibold text-slate-800 dark:text-slate-100">' + escapeHtml(item.keyword || '-') + '</p>' +
                        '<span class="text-xs font-bold ' + (positive ? 'text-emerald-600 dark:text-emerald-300' : 'text-red-600 dark:text-red-300') + '">' + changeLabel + '</span>' +
                        '</div>' +
                        '<p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Current Rank: ' + escapeHtml(item.current_rank || '100+') + '</p>';
                    container.appendChild(li);
                });
            }

            function renderAlerts(alerts) {
                alertsList.innerHTML = '';
                if (!alerts || !alerts.length) {
                    alertsList.innerHTML = '<li class="rounded-xl border border-dashed border-slate-300 px-3 py-2 text-slate-500 dark:border-slate-700 dark:text-slate-400">No alerts yet.</li>';
                    return;
                }

                alerts.forEach(function (alert) {
                    var li = document.createElement('li');
                    li.className = 'rounded-xl border border-slate-200 bg-white/80 px-3 py-2 dark:border-slate-700 dark:bg-slate-800/70';
                    li.innerHTML =
                        '<p class="font-semibold text-slate-800 dark:text-slate-100">' + escapeHtml(alert.message || 'Rank alert') + '</p>' +
                        '<p class="mt-1 text-xs text-slate-500 dark:text-slate-400">' + formatDate(alert.created_at || '') + '</p>';
                    alertsList.appendChild(li);
                });
            }

            function renderTable(rows) {
                tableBody.innerHTML = '';
                state.keywords = rows || [];

                if (!state.keywords.length) {
                    tableEmpty.classList.remove('hidden');
                    historyKeywordSelect.innerHTML = '';
                    clearHistoryChart();
                    return;
                }
                tableEmpty.classList.add('hidden');

                state.keywords.forEach(function (row) {
                    var tr = document.createElement('tr');
                    tr.className = 'rank-row border-b border-slate-200 dark:border-slate-700';
                    var changeValue = Number(row.change || 0);
                    var changeClass = changeValue > 0 ? 'trend-up' : (changeValue < 0 ? 'trend-down' : 'trend-flat');
                    var changeArrow = changeValue > 0 ? '↑' : (changeValue < 0 ? '↓' : '→');

                    tr.innerHTML =
                        '<td class="py-3 pr-3"><p class="font-semibold text-slate-800 dark:text-slate-100">' + escapeHtml(row.keyword || '-') + '</p><p class="text-xs text-slate-500 dark:text-slate-400">' + escapeHtml((row.country || 'US') + ' · ' + (row.device_type || 'desktop')) + '</p></td>' +
                        '<td class="py-3 pr-3 font-bold text-slate-900 dark:text-slate-100">' + escapeHtml(row.current_rank_label || '100+') + '</td>' +
                        '<td class="py-3 pr-3 font-semibold ' + changeClass + '">' + changeArrow + ' ' + (changeValue > 0 ? '+' : '') + changeValue + '</td>' +
                        '<td class="py-3 pr-3">' + escapeHtml(String(row.best_rank || '100+')) + '</td>' +
                        '<td class="py-3 pr-3">' + Number(row.search_volume || 0).toLocaleString() + '</td>' +
                        '<td class="py-3 pr-3">' + escapeHtml(formatDate(row.last_updated || '')) + '</td>' +
                        '<td class="py-3 pr-3"><span class="rounded-lg px-2 py-1 text-xs font-bold ' + (row.status === 'active' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200') + '">' + escapeHtml(row.status || 'active') + '</span></td>' +
                        '<td class="py-3 text-right">' +
                        '<div class="flex justify-end gap-2">' +
                        '<button data-action="history" data-id="' + row.id + '" class="rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">History</button>' +
                        '<button data-action="toggle" data-id="' + row.id + '" data-status="' + (row.status === 'active' ? 'paused' : 'active') + '" class="rounded-lg border border-amber-300 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700 hover:bg-amber-100 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">' + (row.status === 'active' ? 'Pause' : 'Resume') + '</button>' +
                        '<button data-action="delete" data-id="' + row.id + '" class="rounded-lg border border-red-300 bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-100 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">Delete</button>' +
                        '</div>' +
                        '</td>';

                    tableBody.appendChild(tr);
                });

                renderHistorySelector(state.keywords);
            }

            function renderHistorySelector(rows) {
                historyKeywordSelect.innerHTML = '';
                rows.forEach(function (row) {
                    var option = document.createElement('option');
                    option.value = String(row.id);
                    option.textContent = row.keyword + ' (' + row.current_rank_label + ')';
                    historyKeywordSelect.appendChild(option);
                });

                if (rows.length) {
                    loadKeywordHistory(Number(rows[0].id));
                } else {
                    clearHistoryChart();
                }
            }

            function clearHistoryChart() {
                var ctx = document.getElementById('history-chart').getContext('2d');
                if (state.historyChart) {
                    state.historyChart.destroy();
                }

                state.historyChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: ['-'],
                        datasets: [{
                            label: 'Rank Position',
                            data: [100],
                            borderColor: '#94A3B8',
                            backgroundColor: 'rgba(148,163,184,0.25)',
                            fill: true,
                            tension: 0.3
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        scales: {
                            y: { reverse: true, min: 1, max: 110, ticks: { color: document.documentElement.classList.contains('dark') ? '#CBD5E1' : '#334155' } },
                            x: { ticks: { color: document.documentElement.classList.contains('dark') ? '#CBD5E1' : '#334155' } }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }

            function renderPagination(pagination) {
                state.pagination = pagination || {};
                var page = Number(state.pagination.page || 1);
                var totalPages = Number(state.pagination.total_pages || 1);
                paginationLabel.textContent = 'Page ' + page + ' of ' + Math.max(1, totalPages);
                prevPageButton.disabled = !state.pagination.has_prev;
                nextPageButton.disabled = !state.pagination.has_next;
            }

            function escapeHtml(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function loadTrackerData() {
                clearMessage();
                return api('load', {
                    project_id: state.projectId,
                    status: state.status || null,
                    sort_by: state.sortBy,
                    sort_dir: state.sortDir,
                    page: state.page,
                    per_page: state.perPage
                }).then(function (data) {
                    renderProjects(data.projects || [], data.selected_project_id || null);
                    if (!state.projectId) {
                        state.projectId = Number(data.selected_project_id || projectSelect.value || 0) || null;
                    }
                    renderCountries(data.countries || {});
                    renderSummary(data.summary || {});
                    renderAlerts(data.alerts || []);
                    renderTable(data.keywords || []);
                    renderPagination(data.pagination || {});
                    applyPlanText(data.limits || null);
                }).catch(function (error) {
                    showMessage('error', (error.payload && error.payload.error) || error.message || 'Unable to load rank tracker.');
                });
            }

            function addKeyword(event) {
                event.preventDefault();
                clearMessage();

                var keyword = keywordInput.value.trim();
                if (!keyword) {
                    showMessage('warning', 'Enter a keyword to track.');
                    return;
                }

                api('add_keyword', {
                    project_id: Number(projectSelect.value || 0),
                    keyword: keyword,
                    country: countrySelect.value,
                    device_type: deviceSelect.value
                }).then(function (response) {
                    keywordInput.value = '';
                    showMessage('success', response.message || 'Keyword added.');
                    state.page = 1;
                    state.projectId = Number(projectSelect.value || 0);
                    loadTrackerData();
                }).catch(function (error) {
                    showMessage('error', (error.payload && error.payload.error) || error.message || 'Unable to add keyword.');
                });
            }

            function runDailyCheck() {
                clearMessage();
                runCheckButton.disabled = true;

                api('run_check', {
                    project_id: projectSelect.value ? Number(projectSelect.value) : null,
                    force: false,
                    limit: 250
                }).then(function (response) {
                    showMessage('success', 'Daily check complete. Processed: ' + (response.processed || 0) + ', skipped: ' + (response.skipped || 0) + '.');
                    loadTrackerData();
                }).catch(function (error) {
                    showMessage('error', (error.payload && error.payload.error) || error.message || 'Unable to run rank check.');
                }).finally(function () {
                    runCheckButton.disabled = false;
                });
            }

            function handleTableAction(event) {
                var button = event.target.closest('button[data-action]');
                if (!button) {
                    return;
                }

                var action = button.getAttribute('data-action');
                var trackedKeywordId = Number(button.getAttribute('data-id') || 0);
                if (!trackedKeywordId) {
                    return;
                }

                if (action === 'history') {
                    loadKeywordHistory(trackedKeywordId);
                    return;
                }

                if (action === 'delete') {
                    if (!window.confirm('Delete this tracked keyword?')) {
                        return;
                    }
                    api('delete_keyword', { tracked_keyword_id: trackedKeywordId })
                        .then(function () {
                            showMessage('success', 'Keyword deleted.');
                            loadTrackerData();
                        })
                        .catch(function (error) {
                            showMessage('error', (error.payload && error.payload.error) || error.message || 'Delete failed.');
                        });
                    return;
                }

                if (action === 'toggle') {
                    var nextStatus = button.getAttribute('data-status') || 'active';
                    api('toggle_status', {
                        tracked_keyword_id: trackedKeywordId,
                        status: nextStatus
                    }).then(function () {
                        showMessage('success', 'Keyword status updated.');
                        loadTrackerData();
                    }).catch(function (error) {
                        showMessage('error', (error.payload && error.payload.error) || error.message || 'Status update failed.');
                    });
                }
            }

            function loadKeywordHistory(trackedKeywordId) {
                if (!trackedKeywordId) {
                    clearHistoryChart();
                    return;
                }

                api('history', {
                    tracked_keyword_id: trackedKeywordId,
                    days: 30
                }).then(function (data) {
                    var history = data.history || [];
                    var labels = history.map(function (item) { return item.checked_date; });
                    var ranks = history.map(function (item) {
                        var rank = Number(item.rank_position || 101);
                        return rank > 100 ? 101 : rank;
                    });

                    if (!labels.length) {
                        labels = ['No Data'];
                        ranks = [101];
                    }

                    var ctx = document.getElementById('history-chart').getContext('2d');
                    if (state.historyChart) {
                        state.historyChart.destroy();
                    }

                    state.historyChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Rank Position',
                                data: ranks,
                                borderColor: '#4F46E5',
                                backgroundColor: 'rgba(99,102,241,0.16)',
                                fill: true,
                                tension: 0.32,
                                pointRadius: 2
                            }]
                        },
                        options: {
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false }
                            },
                            scales: {
                                y: {
                                    reverse: true,
                                    min: 1,
                                    max: 110,
                                    ticks: {
                                        stepSize: 10,
                                        color: document.documentElement.classList.contains('dark') ? '#CBD5E1' : '#334155'
                                    },
                                    grid: { color: document.documentElement.classList.contains('dark') ? 'rgba(148,163,184,0.2)' : 'rgba(148,163,184,0.18)' }
                                },
                                x: {
                                    ticks: { color: document.documentElement.classList.contains('dark') ? '#CBD5E1' : '#334155' },
                                    grid: { display: false }
                                }
                            }
                        }
                    });

                    historyKeywordSelect.value = String(trackedKeywordId);
                }).catch(function () {
                    clearHistoryChart();
                });
            }

            function bindEvents() {
                form.addEventListener('submit', addKeyword);
                runCheckButton.addEventListener('click', runDailyCheck);
                tableBody.addEventListener('click', handleTableAction);
                historyKeywordSelect.addEventListener('change', function () {
                    loadKeywordHistory(Number(historyKeywordSelect.value || 0));
                });

                projectSelect.addEventListener('change', function () {
                    state.projectId = Number(projectSelect.value || 0);
                    state.page = 1;
                    loadTrackerData();
                });

                statusFilter.addEventListener('change', function () {
                    state.status = statusFilter.value;
                    state.page = 1;
                    loadTrackerData();
                });

                sortSelect.addEventListener('change', function () {
                    var parts = String(sortSelect.value || 'current_rank|asc').split('|');
                    state.sortBy = parts[0] || 'current_rank';
                    state.sortDir = parts[1] || 'asc';
                    state.page = 1;
                    loadTrackerData();
                });

                prevPageButton.addEventListener('click', function () {
                    if (!state.pagination || !state.pagination.has_prev) {
                        return;
                    }
                    state.page = Math.max(1, Number(state.page || 1) - 1);
                    loadTrackerData();
                });

                nextPageButton.addEventListener('click', function () {
                    if (!state.pagination || !state.pagination.has_next) {
                        return;
                    }
                    state.page = Number(state.page || 1) + 1;
                    loadTrackerData();
                });

                document.getElementById('theme-toggle').addEventListener('click', function () {
                    document.documentElement.classList.toggle('dark');
                    localStorage.setItem('seo-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
                });
            }

            bindEvents();
            clearHistoryChart();
            loadTrackerData();
        })();
    </script>
</body>
</html>
