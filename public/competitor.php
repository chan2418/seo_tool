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
$featureAccess = $planService->assertFeatureAccess($userId, 'competitor_basic');
$isFree = empty($featureAccess['allowed']);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="assets/images/favicon-180.png">
    <title>Competitor Analysis - SEO SaaS</title>
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
                        brand: {500: '#4F46E5', 400: '#6366F1'},
                    },
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif']
                    },
                    boxShadow: {
                        soft: '0 18px 45px -25px rgba(15,23,42,0.35)'
                    }
                }
            }
        };
    </script>
    <style>
        .surface-card {
            border-radius: 1rem;
            border: 1px solid rgba(255,255,255,.65);
            background: rgba(255,255,255,.86);
            backdrop-filter: blur(10px);
        }
        .dark .surface-card {
            border-color: rgba(51,65,85,.8);
            background: rgba(30,41,59,.82);
        }
        .skeleton {
            background: linear-gradient(90deg, rgba(226,232,240,.35) 25%, rgba(241,245,249,.8) 50%, rgba(226,232,240,.35) 75%);
            background-size: 200% 100%;
            animation: shimmer 1.2s infinite;
        }
        .dark .skeleton {
            background: linear-gradient(90deg, rgba(51,65,85,.35) 25%, rgba(71,85,105,.65) 50%, rgba(51,65,85,.35) 75%);
            background-size: 200% 100%;
        }
        @keyframes shimmer { to { background-position: -200% 0; } }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute -left-20 -top-20 h-72 w-72 rounded-full bg-indigo-300/45 blur-3xl dark:bg-indigo-500/20"></div>
        <div class="absolute right-0 top-20 h-64 w-64 rounded-full bg-sky-200/50 blur-3xl dark:bg-sky-500/15"></div>
    </div>

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="lg:pl-72">
        <header class="sticky top-0 z-20 border-b border-white/60 bg-slate-100/80 px-4 py-4 backdrop-blur-xl dark:border-slate-700 dark:bg-slate-950/70 sm:px-6 lg:px-10">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <button id="sidebar-open" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900 lg:hidden">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
                    </button>
                    <div>
                        <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Competitive Intelligence</p>
                        <h1 class="text-xl font-bold">Competitor Analysis</h1>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button id="theme-toggle" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                        <svg class="h-5 w-5 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="4"></circle><path stroke-linecap="round" d="M12 3v2M12 19v2M3 12h2M19 12h2"/></svg>
                        <svg class="hidden h-5 w-5 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1 1 11.2 3a1 1 0 0 1 .6 1.8A7 7 0 0 0 19.2 12a1 1 0 0 1 1.8.8Z"/></svg>
                    </button>
                    <span class="rounded-xl px-3 py-2 text-xs font-bold <?php echo $isFree ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300'; ?>"><?php echo htmlspecialchars($planLabel); ?></span>
                    <span class="hidden text-sm font-semibold sm:block"><?php echo htmlspecialchars($userName); ?></span>
                </div>
            </div>
        </header>

        <main class="space-y-6 px-4 pb-10 pt-6 sm:px-6 lg:px-10">
            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="grid gap-5 lg:grid-cols-[1.1fr_0.9fr]">
                    <div>
                        <h2 class="text-2xl font-extrabold">Analyze competitor domain strength</h2>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Get estimated traffic, authority, ranking keywords, top pages, and trend data in one view.</p>
                    </div>
                    <div class="rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 p-5 text-white">
                        <p class="text-xs uppercase tracking-[0.2em] text-indigo-100">Module Access</p>
                        <p class="mt-2 text-lg font-bold"><?php echo $isFree ? 'Pro Required' : 'Active'; ?></p>
                        <p class="mt-2 text-xs text-indigo-100"><?php echo $isFree ? 'Upgrade to Pro or Agency to unlock competitor intelligence.' : 'Compare domains and track SEO market position.'; ?></p>
                    </div>
                </div>

                <form id="competitor-form" class="mt-6 flex flex-col gap-3 sm:flex-row">
                    <input
                        id="domain-input"
                        type="text"
                        maxlength="100"
                        placeholder="example.com"
                        class="w-full flex-1 rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-medium outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30"
                        <?php echo $isFree ? 'disabled' : ''; ?>
                    >
                    <button id="analyze-btn" type="submit" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 px-5 py-3 text-sm font-semibold text-white shadow-soft transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60" <?php echo $isFree ? 'disabled' : ''; ?>>
                        <svg id="analyze-spinner" class="hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 12a8 8 0 0 1 8-8m0 16a8 8 0 0 1-8-8"/></svg>
                        <span>Analyze Domain</span>
                    </button>
                </form>

                <div id="message" class="mt-4 hidden rounded-2xl border p-4 text-sm font-medium"></div>
            </section>

            <section id="upgrade-panel" class="<?php echo $isFree ? '' : 'hidden'; ?> surface-card p-6 shadow-soft sm:p-8">
                <h3 class="text-lg font-bold">Unlock competitor insights</h3>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Competitor analysis is available on Pro and Agency plans.</p>
                <div class="mt-4 flex gap-3">
                    <a href="subscription" class="rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-4 py-2 text-sm font-semibold text-white">View Plans</a>
                </div>
            </section>

            <section id="skeleton" class="hidden grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="skeleton h-28 rounded-2xl"></div>
                <div class="skeleton h-28 rounded-2xl"></div>
                <div class="skeleton h-28 rounded-2xl"></div>
                <div class="skeleton h-28 rounded-2xl"></div>
            </section>

            <section id="summary-grid" class="hidden grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <article class="surface-card p-5 shadow-soft"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Organic Traffic</p><p id="metric-traffic" class="mt-2 text-2xl font-extrabold">0</p></article>
                <article class="surface-card p-5 shadow-soft"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Authority</p><p id="metric-authority" class="mt-2 text-2xl font-extrabold">0</p></article>
                <article class="surface-card p-5 shadow-soft"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Ranking Keywords</p><p id="metric-keywords" class="mt-2 text-2xl font-extrabold">0</p></article>
                <article class="surface-card p-5 shadow-soft"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Health Score</p><p id="metric-health" class="mt-2 text-2xl font-extrabold">0</p></article>
                <article class="surface-card p-5 shadow-soft"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">PageSpeed</p><p id="metric-speed" class="mt-2 text-2xl font-extrabold">0</p></article>
            </section>

            <section class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                <article class="surface-card p-6 shadow-soft sm:p-8">
                    <div class="flex items-center justify-between"><h3 class="text-lg font-bold">Traffic Trend</h3><span id="source-badge" class="rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-200">-</span></div>
                    <div class="mt-4 h-72"><canvas id="traffic-chart"></canvas></div>
                </article>
                <article class="surface-card p-6 shadow-soft sm:p-8">
                    <h3 class="text-lg font-bold">Authority Badge</h3>
                    <div class="mt-6 flex items-center justify-center">
                        <div class="relative h-48 w-48">
                            <svg class="h-48 w-48 -rotate-90" viewBox="0 0 180 180" fill="none">
                                <defs>
                                    <linearGradient id="authorityGradient" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#4F46E5"/><stop offset="100%" stop-color="#6366F1"/></linearGradient>
                                </defs>
                                <circle cx="90" cy="90" r="68" stroke="currentColor" stroke-width="14" class="text-slate-200 dark:text-slate-700"></circle>
                                <circle id="authority-progress" cx="90" cy="90" r="68" stroke="url(#authorityGradient)" stroke-width="14" stroke-linecap="round" stroke-dasharray="427" stroke-dashoffset="427"></circle>
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <p id="authority-value" class="text-4xl font-extrabold">0</p>
                                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Domain Authority</p>
                            </div>
                        </div>
                    </div>
                </article>
            </section>

            <section class="grid gap-6 xl:grid-cols-2">
                <article class="surface-card overflow-hidden shadow-soft">
                    <div class="border-b border-slate-200 p-5 dark:border-slate-700"><h3 class="text-lg font-bold">Top 10 Organic Keywords</h3></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/60 dark:text-slate-400"><tr><th class="px-4 py-3 text-left">Keyword</th><th class="px-4 py-3 text-left">Position</th><th class="px-4 py-3 text-left">Volume</th></tr></thead>
                            <tbody id="keyword-table" class="divide-y divide-slate-200 dark:divide-slate-700"></tbody>
                        </table>
                    </div>
                </article>
                <article class="surface-card overflow-hidden shadow-soft">
                    <div class="border-b border-slate-200 p-5 dark:border-slate-700"><h3 class="text-lg font-bold">Top 5 Pages</h3></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/60 dark:text-slate-400"><tr><th class="px-4 py-3 text-left">Page URL</th><th class="px-4 py-3 text-left">Traffic</th><th class="px-4 py-3 text-left">Keywords</th></tr></thead>
                            <tbody id="page-table" class="divide-y divide-slate-200 dark:divide-slate-700"></tbody>
                        </table>
                    </div>
                </article>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        (function () {
            var state = { chart: null };
            var themeToggle = document.getElementById('theme-toggle');
            var form = document.getElementById('competitor-form');
            var domainInput = document.getElementById('domain-input');
            var analyzeButton = document.getElementById('analyze-btn');
            var spinner = document.getElementById('analyze-spinner');
            var message = document.getElementById('message');
            var skeleton = document.getElementById('skeleton');
            var summaryGrid = document.getElementById('summary-grid');
            var hasAccess = <?php echo $isFree ? 'false' : 'true'; ?>;

            function setLoading(loading) {
                analyzeButton.disabled = loading;
                if (loading) {
                    spinner.classList.remove('hidden');
                    skeleton.classList.remove('hidden');
                } else {
                    spinner.classList.add('hidden');
                    skeleton.classList.add('hidden');
                }
            }

            function showMessage(type, text) {
                var classes = {
                    success: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
                    error: 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300',
                    info: 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-600 dark:bg-slate-800/60 dark:text-slate-200'
                };
                message.className = 'mt-4 rounded-2xl border p-4 text-sm font-medium';
                message.classList.remove('hidden');
                (classes[type] || classes.info).split(' ').forEach(function (c) { message.classList.add(c); });
                message.textContent = text;
            }

            function clearMessage() {
                message.classList.add('hidden');
                message.textContent = '';
            }

            function formatNumber(value) {
                return new Intl.NumberFormat().format(Number(value || 0));
            }

            function updateAuthorityGauge(value) {
                value = Math.max(0, Math.min(100, Number(value || 0)));
                var circle = document.getElementById('authority-progress');
                var text = document.getElementById('authority-value');
                var radius = 68;
                var circumference = 2 * Math.PI * radius;
                circle.style.strokeDasharray = String(circumference);
                circle.style.strokeDashoffset = String(circumference * (1 - value / 100));
                text.textContent = String(Math.round(value));
            }

            function renderTables(data) {
                var keywordBody = document.getElementById('keyword-table');
                var pageBody = document.getElementById('page-table');
                keywordBody.innerHTML = '';
                pageBody.innerHTML = '';

                (data.top_keywords || []).forEach(function (row) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td class="px-4 py-3">' + escapeHtml(row.keyword || '') + '</td>' +
                        '<td class="px-4 py-3">#' + Number(row.position || 0) + '</td>' +
                        '<td class="px-4 py-3">' + formatNumber(row.volume || 0) + '</td>';
                    keywordBody.appendChild(tr);
                });

                (data.top_pages || []).forEach(function (row) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td class="px-4 py-3"><span class="truncate block max-w-[280px]" title="' + escapeHtml(row.url || '') + '">' + escapeHtml(row.url || '') + '</span></td>' +
                        '<td class="px-4 py-3">' + formatNumber(row.estimated_traffic || 0) + '</td>' +
                        '<td class="px-4 py-3">' + formatNumber(row.keywords || 0) + '</td>';
                    pageBody.appendChild(tr);
                });
            }

            function renderTrend(trend) {
                var labels = [];
                var points = [];
                (trend || []).forEach(function (item) {
                    labels.push(item.month || '');
                    points.push(Number(item.traffic || 0));
                });

                var ctx = document.getElementById('traffic-chart').getContext('2d');
                if (state.chart) {
                    state.chart.destroy();
                }

                var gradient = ctx.createLinearGradient(0, 0, 0, 260);
                gradient.addColorStop(0, 'rgba(79,70,229,0.32)');
                gradient.addColorStop(1, 'rgba(79,70,229,0.03)');

                state.chart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: points,
                            borderColor: '#4F46E5',
                            backgroundColor: gradient,
                            borderWidth: 3,
                            fill: true,
                            tension: 0.35,
                            pointRadius: 2.5
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: false, grid: { color: 'rgba(148,163,184,0.2)' } },
                            x: { grid: { display: false } }
                        }
                    }
                });
            }

            function updateMetrics(summary, source) {
                summaryGrid.classList.remove('hidden');
                document.getElementById('metric-traffic').textContent = formatNumber(summary.organic_traffic || 0);
                document.getElementById('metric-authority').textContent = String(summary.domain_authority || 0);
                document.getElementById('metric-keywords').textContent = formatNumber(summary.ranking_keywords || 0);
                document.getElementById('metric-health').textContent = String(summary.domain_health_score || 0);
                document.getElementById('metric-speed').textContent = String(summary.pagespeed_score || 0);
                document.getElementById('source-badge').textContent = source;
                updateAuthorityGauge(summary.domain_authority || 0);
            }

            function escapeHtml(text) {
                return String(text)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            async function analyze(domain) {
                setLoading(true);
                clearMessage();

                try {
                    var response = await fetch('competitor-data.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ domain: domain })
                    });

                    var payload = await response.json();
                    if (!response.ok || !payload.success) {
                        throw payload;
                    }

                    var data = payload.data || {};
                    updateMetrics(data.summary || {}, payload.source || '-');
                    renderTrend(data.traffic_trend || []);
                    renderTables(data);
                    showMessage('success', 'Competitor analysis loaded successfully.');
                } catch (error) {
                    showMessage('error', error.error || 'Unable to fetch competitor analysis.');
                } finally {
                    setLoading(false);
                }
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();

                if (!hasAccess) {
                    showMessage('info', 'Competitor analysis requires Pro or Agency plan. Upgrade to continue.');
                    return;
                }

                var domain = domainInput.value.trim();
                if (domain.length < 3) {
                    showMessage('error', 'Please enter a valid domain.');
                    return;
                }
                analyze(domain);
            });

            themeToggle && themeToggle.addEventListener('click', function () {
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('seo-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
            });
        })();
    </script>
</body>
</html>
