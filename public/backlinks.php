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
$featureAccess = $planService->assertFeatureAccess($userId, 'backlink_overview');
$isAgency = !empty($featureAccess['allowed']);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="assets/images/favicon-180.png">
    <title>Backlink Overview - SEO SaaS</title>
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
                    colors: { brand: { 500: '#4F46E5', 400: '#6366F1' } },
                    fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
                    boxShadow: { soft: '0 18px 45px -25px rgba(15,23,42,0.35)' }
                }
            }
        };
    </script>
    <style>
        .surface-card { border-radius: 1rem; border: 1px solid rgba(255,255,255,.65); background: rgba(255,255,255,.86); backdrop-filter: blur(10px); }
        .dark .surface-card { border-color: rgba(51,65,85,.8); background: rgba(30,41,59,.82); }
        .skeleton { background: linear-gradient(90deg, rgba(226,232,240,.35) 25%, rgba(241,245,249,.8) 50%, rgba(226,232,240,.35) 75%); background-size: 200% 100%; animation: shimmer 1.2s infinite; }
        .dark .skeleton { background: linear-gradient(90deg, rgba(51,65,85,.35) 25%, rgba(71,85,105,.65) 50%, rgba(51,65,85,.35) 75%); background-size: 200% 100%; }
        @keyframes shimmer { to { background-position: -200% 0; } }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="lg:pl-72">
        <header class="sticky top-0 z-20 border-b border-white/60 bg-slate-100/80 px-4 py-4 backdrop-blur-xl dark:border-slate-700 dark:bg-slate-950/70 sm:px-6 lg:px-10">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <button id="sidebar-open" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 lg:hidden" aria-label="Open sidebar">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                        </svg>
                    </button>
                    <div><p class="text-xs uppercase tracking-[0.2em] text-slate-500">Authority Intelligence</p><h1 class="text-xl font-bold">Backlink Overview</h1></div>
                </div>
                <div class="flex items-center gap-3"><button id="theme-toggle" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">🌓</button><span class="rounded-xl px-3 py-2 text-xs font-bold <?php echo $isAgency ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'; ?>"><?php echo htmlspecialchars($planLabel); ?></span><span class="hidden text-sm font-semibold sm:block"><?php echo htmlspecialchars($userName); ?></span></div>
            </div>
        </header>

        <main class="space-y-6 px-4 pb-10 pt-6 sm:px-6 lg:px-10">
            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="grid gap-5 lg:grid-cols-[1.1fr_0.9fr]"><div><h2 class="text-2xl font-extrabold">Backlink profile summary</h2><p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Track referring domains, anchor text distribution, and link quality mix at a glance.</p></div><div class="rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 p-5 text-white"><p class="text-xs uppercase tracking-[0.2em] text-indigo-100">Access Tier</p><p class="mt-2 text-lg font-bold"><?php echo $isAgency ? 'Agency Active' : 'Agency Required'; ?></p><p class="mt-2 text-xs text-indigo-100">Backlink intelligence is available on Agency plan.</p></div></div>
                <form id="backlink-form" class="mt-6 flex flex-col gap-3 sm:flex-row">
                    <input id="domain-input" type="text" maxlength="100" placeholder="example.com" class="w-full flex-1 rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-medium outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30" <?php echo $isAgency ? '' : 'disabled'; ?>>
                    <button id="analyze-btn" type="submit" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 px-5 py-3 text-sm font-semibold text-white shadow-soft transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60" <?php echo $isAgency ? '' : 'disabled'; ?>><svg id="spinner" class="hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 12a8 8 0 0 1 8-8m0 16a8 8 0 0 1-8-8"/></svg>Analyze Backlinks</button>
                </form>
                <div id="message" class="mt-4 hidden rounded-2xl border p-4 text-sm font-medium"></div>
            </section>

            <?php if (!$isAgency): ?>
            <section class="surface-card p-6 shadow-soft sm:p-8">
                <h3 class="text-lg font-bold">Upgrade to Agency</h3>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Backlink overview, crawler, and white-label reports are available on the Agency plan.</p>
                <div class="mt-4 flex gap-3"><a href="subscription" class="rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-4 py-2 text-sm font-semibold text-white">View Plans</a></div>
            </section>
            <?php endif; ?>

            <section id="skeleton" class="hidden grid gap-4 md:grid-cols-2 xl:grid-cols-4"><div class="skeleton h-28 rounded-2xl"></div><div class="skeleton h-28 rounded-2xl"></div><div class="skeleton h-28 rounded-2xl"></div><div class="skeleton h-28 rounded-2xl"></div></section>

            <section id="summary-grid" class="hidden grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="surface-card p-5 shadow-soft"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Total Backlinks</p><p id="metric-total" class="mt-2 text-2xl font-extrabold">0</p></article>
                <article class="surface-card p-5 shadow-soft"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Referring Domains</p><p id="metric-domains" class="mt-2 text-2xl font-extrabold">0</p></article>
                <article class="surface-card p-5 shadow-soft"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Do-follow %</p><p id="metric-dofollow" class="mt-2 text-2xl font-extrabold">0%</p></article>
                <article class="surface-card p-5 shadow-soft"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">No-follow %</p><p id="metric-nofollow" class="mt-2 text-2xl font-extrabold">0%</p></article>
            </section>

            <section class="grid gap-6 xl:grid-cols-2">
                <article class="surface-card p-6 shadow-soft sm:p-8"><h3 class="text-lg font-bold">Link Type Distribution</h3><div class="mt-4 h-72"><canvas id="linktype-chart"></canvas></div></article>
                <article class="surface-card p-6 shadow-soft sm:p-8"><h3 class="text-lg font-bold">Top Referring Domains</h3><div class="mt-4 h-72"><canvas id="refdomain-chart"></canvas></div></article>
            </section>

            <section class="grid gap-6 xl:grid-cols-2">
                <article class="surface-card overflow-hidden shadow-soft"><div class="border-b border-slate-200 p-5 dark:border-slate-700"><h3 class="text-lg font-bold">Top Anchor Texts</h3></div><div id="anchor-list" class="flex flex-wrap gap-2 p-5"></div></article>
                <article class="surface-card overflow-hidden shadow-soft"><div class="border-b border-slate-200 p-5 dark:border-slate-700"><h3 class="text-lg font-bold">Top Backlinks</h3></div><div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/60 dark:text-slate-400"><tr><th class="px-4 py-3 text-left">Source</th><th class="px-4 py-3 text-left">Target</th><th class="px-4 py-3 text-left">Anchor</th><th class="px-4 py-3 text-left">Type</th></tr></thead><tbody id="backlink-table" class="divide-y divide-slate-200 dark:divide-slate-700"></tbody></table></div></article>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        (function () {
            var state = { pie: null, bar: null };
            var form = document.getElementById('backlink-form');
            var input = document.getElementById('domain-input');
            var button = document.getElementById('analyze-btn');
            var spinner = document.getElementById('spinner');
            var message = document.getElementById('message');
            var skeleton = document.getElementById('skeleton');
            var summaryGrid = document.getElementById('summary-grid');
            var hasAccess = <?php echo $isAgency ? 'true' : 'false'; ?>;

            function setLoading(loading) {
                button.disabled = loading;
                if (loading) { spinner.classList.remove('hidden'); skeleton.classList.remove('hidden'); }
                else { spinner.classList.add('hidden'); skeleton.classList.add('hidden'); }
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

            function formatNumber(v) { return new Intl.NumberFormat().format(Number(v || 0)); }
            function escapeHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

            function renderCharts(data) {
                var linkTypeCtx = document.getElementById('linktype-chart').getContext('2d');
                var refCtx = document.getElementById('refdomain-chart').getContext('2d');

                if (state.pie) state.pie.destroy();
                if (state.bar) state.bar.destroy();

                var distribution = data.link_type_distribution || { dofollow: 0, nofollow: 0 };
                state.pie = new Chart(linkTypeCtx, {
                    type: 'pie',
                    data: {
                        labels: ['Do-follow', 'No-follow'],
                        datasets: [{ data: [Number(distribution.dofollow || 0), Number(distribution.nofollow || 0)], backgroundColor: ['#22C55E', '#F59E0B'] }]
                    },
                    options: { responsive: true, maintainAspectRatio: false }
                });

                var domains = data.top_linking_domains || [];
                state.bar = new Chart(refCtx, {
                    type: 'bar',
                    data: {
                        labels: domains.slice(0, 8).map(function (d) { return d.domain; }),
                        datasets: [{ label: 'Backlinks', data: domains.slice(0, 8).map(function (d) { return Number(d.backlinks || 0); }), backgroundColor: '#6366F1' }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { maxRotation: 45, minRotation: 45 } } } }
                });
            }

            function renderAnchors(data) {
                var container = document.getElementById('anchor-list');
                container.innerHTML = '';
                (data.top_anchor_texts || []).forEach(function (item) {
                    var chip = document.createElement('span');
                    chip.className = 'rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-200';
                    chip.textContent = (item.text || '-') + ' (' + formatNumber(item.count || 0) + ')';
                    container.appendChild(chip);
                });
            }

            function renderBacklinks(data) {
                var body = document.getElementById('backlink-table');
                body.innerHTML = '';
                (data.top_backlinks || []).forEach(function (row) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td class="px-4 py-3"><span class="block max-w-[240px] truncate" title="' + escapeHtml(row.source_url || '') + '">' + escapeHtml(row.source_url || '') + '</span></td>' +
                        '<td class="px-4 py-3"><span class="block max-w-[200px] truncate" title="' + escapeHtml(row.target_url || '') + '">' + escapeHtml(row.target_url || '') + '</span></td>' +
                        '<td class="px-4 py-3">' + escapeHtml(row.anchor || '') + '</td>' +
                        '<td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-semibold ' + ((row.link_type || '').toLowerCase() === 'dofollow' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300') + '">' + escapeHtml(row.link_type || '-') + '</span></td>';
                    body.appendChild(tr);
                });
            }

            function updateMetrics(summary) {
                summaryGrid.classList.remove('hidden');
                document.getElementById('metric-total').textContent = formatNumber(summary.total_backlinks || 0);
                document.getElementById('metric-domains').textContent = formatNumber(summary.referring_domains || 0);
                document.getElementById('metric-dofollow').textContent = Number(summary.dofollow_pct || 0).toFixed(1) + '%';
                document.getElementById('metric-nofollow').textContent = Number(summary.nofollow_pct || 0).toFixed(1) + '%';
            }

            async function analyze(domain) {
                setLoading(true);
                message.classList.add('hidden');

                try {
                    var response = await fetch('backlinks-data.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ domain: domain })
                    });

                    var payload = await response.json();
                    if (!response.ok || !payload.success) {
                        throw payload;
                    }

                    var data = payload.data || {};
                    updateMetrics(data.summary || {});
                    renderCharts(data);
                    renderAnchors(data);
                    renderBacklinks(data);
                    showMessage('success', 'Backlink overview loaded successfully.');
                } catch (error) {
                    showMessage('error', error.error || 'Unable to load backlink overview.');
                } finally {
                    setLoading(false);
                }
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();

                if (!hasAccess) {
                    showMessage('info', 'Backlink overview requires Agency plan. Upgrade to continue.');
                    return;
                }

                var domain = input.value.trim();
                if (domain.length < 3) {
                    showMessage('error', 'Please enter a valid domain.');
                    return;
                }
                analyze(domain);
            });

            document.getElementById('theme-toggle').addEventListener('click', function () {
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('seo-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
            });
        })();
    </script>
</body>
</html>
