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
    <title>Insights - SEO Audit SaaS</title>
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
            border: 1px solid rgba(255, 255, 255, 0.62);
            background: rgba(255, 255, 255, 0.86);
            backdrop-filter: blur(12px);
        }

        .dark .surface-card {
            border-color: rgba(51, 65, 85, 0.82);
            background: rgba(30, 41, 59, 0.84);
        }

        .insight-card {
            transition: transform 150ms ease, box-shadow 150ms ease, border-color 150ms ease;
        }

        .insight-card:hover {
            transform: translateY(-2px);
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
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">SEO Insight Engine</p>
                        <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Actionable Insights</h1>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button id="theme-toggle" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:text-brand-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300" aria-label="Toggle theme">
                        <svg class="h-5 w-5 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="12" cy="12" r="4"></circle>
                            <path stroke-linecap="round" d="M12 3v2.2M12 18.8V21M3 12h2.2M18.8 12H21M5.64 5.64l1.55 1.55M16.81 16.81l1.55 1.55M5.64 18.36l1.55-1.55M16.81 7.19l1.55-1.55"></path>
                        </svg>
                        <svg class="hidden h-5 w-5 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1 1 11.21 3c.11 0 .23 0 .34.01a1 1 0 0 1 .54 1.82A7 7 0 0 0 19.17 12a1 1 0 0 1 1.83.79Z"></path>
                        </svg>
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
            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
                    <div>
                        <span class="inline-flex items-center rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">Decision Intelligence</span>
                        <h2 class="mt-4 text-3xl font-extrabold text-slate-900 dark:text-slate-100">Turn ranking and Search Console data into actions</h2>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Insights are generated from rank movement, clicks, impressions, CTR, and average position. Use them to prioritize SEO work with impact.</p>
                    </div>
                    <div class="rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 p-5 text-white shadow-soft">
                        <p class="text-xs uppercase tracking-[0.18em] text-indigo-100">Total Insights</p>
                        <p id="summary-total" class="mt-2 text-4xl font-extrabold">0</p>
                        <p id="summary-updated" class="mt-2 text-xs text-indigo-100">Last generated: -</p>
                    </div>
                </div>

                <div id="message-box" class="mt-5 hidden rounded-2xl border p-4 text-sm font-medium"></div>

                <div class="mt-6 grid gap-3 md:grid-cols-4">
                    <div class="md:col-span-2">
                        <label for="project-select" class="mb-2 block text-sm font-semibold">Project</label>
                        <select id="project-select" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30"></select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-2 block text-sm font-semibold">Actions</label>
                        <div class="flex flex-wrap items-center gap-2">
                            <button id="refresh-btn" type="button" class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                                Refresh View
                            </button>
                            <button id="generate-btn" type="button" class="rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-4 py-3 text-sm font-semibold text-white shadow-soft transition hover:opacity-90">
                                Generate Insights
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
                    <span id="plan-chip" class="rounded-xl bg-slate-200 px-3 py-1 font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-200">Plan: <?php echo htmlspecialchars($planLabel); ?></span>
                    <span id="limit-chip" class="rounded-xl bg-sky-100 px-3 py-1 font-semibold text-sky-700 dark:bg-sky-500/20 dark:text-sky-300">Limit: -</span>
                    <span id="gsc-chip" class="rounded-xl bg-amber-100 px-3 py-1 font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">GSC: checking...</span>
                </div>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="surface-card p-5 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Opportunity</p>
                    <p id="summary-opportunity" class="mt-2 text-3xl font-extrabold text-emerald-600 dark:text-emerald-400">0</p>
                </article>
                <article class="surface-card p-5 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Optimization</p>
                    <p id="summary-optimization" class="mt-2 text-3xl font-extrabold text-sky-600 dark:text-sky-400">0</p>
                </article>
                <article class="surface-card p-5 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Warning</p>
                    <p id="summary-warning" class="mt-2 text-3xl font-extrabold text-red-600 dark:text-red-400">0</p>
                </article>
                <article class="surface-card p-5 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Selected Project</p>
                    <p id="summary-project" class="mt-2 text-sm font-bold text-slate-700 dark:text-slate-200">-</p>
                </article>
            </section>

            <section class="surface-card p-6 shadow-soft">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h3 class="text-lg font-bold text-emerald-700 dark:text-emerald-300">Opportunity Insights</h3>
                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">Growth Focus</span>
                </div>
                <div id="section-opportunity" class="grid gap-3 md:grid-cols-2"></div>
                <div id="empty-opportunity" class="hidden rounded-xl border border-dashed border-emerald-300 bg-emerald-50 px-4 py-5 text-sm text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                    No opportunity insights right now.
                </div>
            </section>

            <section class="surface-card p-6 shadow-soft">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h3 class="text-lg font-bold text-sky-700 dark:text-sky-300">Optimization Insights</h3>
                    <span class="rounded-full bg-sky-100 px-3 py-1 text-xs font-semibold text-sky-700 dark:bg-sky-500/20 dark:text-sky-300">Improve Snippets</span>
                </div>
                <div id="section-optimization" class="grid gap-3 md:grid-cols-2"></div>
                <div id="empty-optimization" class="hidden rounded-xl border border-dashed border-sky-300 bg-sky-50 px-4 py-5 text-sm text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300">
                    No optimization insights right now.
                </div>
            </section>

            <section class="surface-card p-6 shadow-soft">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h3 class="text-lg font-bold text-red-700 dark:text-red-300">Warning Alerts</h3>
                    <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700 dark:bg-red-500/20 dark:text-red-300">Risk Signals</span>
                </div>
                <div id="section-warning" class="grid gap-3 md:grid-cols-2"></div>
                <div id="empty-warning" class="hidden rounded-xl border border-dashed border-red-300 bg-red-50 px-4 py-5 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                    No warning insights right now.
                </div>
            </section>
        </main>
    </div>

    <script>
        (function () {
            var state = {
                projectId: null,
                projects: [],
                limits: {
                    max_insights: 0,
                    plan_type: 'free',
                    page_level_enabled: false
                }
            };

            var projectSelect = document.getElementById('project-select');
            var refreshButton = document.getElementById('refresh-btn');
            var generateButton = document.getElementById('generate-btn');
            var messageBox = document.getElementById('message-box');

            var summaryTotal = document.getElementById('summary-total');
            var summaryOpportunity = document.getElementById('summary-opportunity');
            var summaryOptimization = document.getElementById('summary-optimization');
            var summaryWarning = document.getElementById('summary-warning');
            var summaryProject = document.getElementById('summary-project');
            var summaryUpdated = document.getElementById('summary-updated');
            var limitChip = document.getElementById('limit-chip');
            var gscChip = document.getElementById('gsc-chip');

            var sectionOpportunity = document.getElementById('section-opportunity');
            var sectionOptimization = document.getElementById('section-optimization');
            var sectionWarning = document.getElementById('section-warning');
            var emptyOpportunity = document.getElementById('empty-opportunity');
            var emptyOptimization = document.getElementById('empty-optimization');
            var emptyWarning = document.getElementById('empty-warning');

            function setLoading(loading) {
                refreshButton.disabled = loading;
                generateButton.disabled = loading;
                projectSelect.disabled = loading;
                if (loading) {
                    refreshButton.classList.add('opacity-70', 'cursor-not-allowed');
                    generateButton.classList.add('opacity-70', 'cursor-not-allowed');
                } else {
                    refreshButton.classList.remove('opacity-70', 'cursor-not-allowed');
                    generateButton.classList.remove('opacity-70', 'cursor-not-allowed');
                }
            }

            function showMessage(type, text) {
                if (!text) {
                    messageBox.className = 'mt-5 hidden rounded-2xl border p-4 text-sm font-medium';
                    messageBox.textContent = '';
                    return;
                }

                var base = 'mt-5 rounded-2xl border p-4 text-sm font-medium';
                if (type === 'success') {
                    messageBox.className = base + ' border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300';
                } else {
                    messageBox.className = base + ' border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300';
                }
                messageBox.textContent = text;
            }

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function formatNumber(value, digits) {
                var number = Number(value || 0);
                if (!isFinite(number)) {
                    number = 0;
                }
                if (typeof digits === 'number') {
                    return number.toLocaleString(undefined, {
                        minimumFractionDigits: digits,
                        maximumFractionDigits: digits
                    });
                }
                return number.toLocaleString();
            }

            function formatPercent(value) {
                var number = Number(value || 0) * 100;
                if (!isFinite(number)) {
                    number = 0;
                }
                return number.toFixed(2) + '%';
            }

            function formatDateTime(value) {
                if (!value) {
                    return '-';
                }
                var date = new Date(String(value).replace(' ', 'T'));
                if (isNaN(date.getTime())) {
                    return String(value);
                }
                return date.toLocaleString();
            }

            function rankChangeLabel(currentRank, previousRank) {
                if (previousRank === null || previousRank === undefined || currentRank === null || currentRank === undefined) {
                    return '<span class="text-xs font-semibold text-slate-500 dark:text-slate-400">N/A</span>';
                }
                var delta = Number(previousRank) - Number(currentRank);
                if (delta > 0) {
                    return '<span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">+' + delta + ' improved</span>';
                }
                if (delta < 0) {
                    return '<span class="text-xs font-semibold text-red-600 dark:text-red-400">' + delta + ' dropped</span>';
                }
                return '<span class="text-xs font-semibold text-slate-500 dark:text-slate-400">No change</span>';
            }

            function renderCard(item, tone) {
                var toneClass = tone === 'warning'
                    ? 'border-red-200 dark:border-red-500/25'
                    : (tone === 'opportunity'
                        ? 'border-emerald-200 dark:border-emerald-500/25'
                        : 'border-sky-200 dark:border-sky-500/25');

                var labelClass = tone === 'warning'
                    ? 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300'
                    : (tone === 'opportunity'
                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300'
                        : 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300');

                var targetLabel = item.keyword ? ('Keyword: ' + escapeHtml(item.keyword)) : ('Page: ' + escapeHtml(item.page_url || '-'));
                var currentRank = item.current_rank !== null && item.current_rank !== undefined ? formatNumber(item.current_rank) : 'N/A';
                var previousRank = item.previous_rank !== null && item.previous_rank !== undefined ? formatNumber(item.previous_rank) : 'N/A';

                return '' +
                    '<article class="insight-card rounded-2xl border ' + toneClass + ' bg-white/90 p-4 shadow-sm dark:bg-slate-900/70">' +
                        '<div class="flex items-start justify-between gap-3">' +
                            '<div class="space-y-2">' +
                                '<span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ' + labelClass + '">' + escapeHtml(item.label || 'SEO Insight') + '</span>' +
                                '<p class="text-sm font-semibold text-slate-800 dark:text-slate-100">' + targetLabel + '</p>' +
                            '</div>' +
                            '<div class="text-right">' +
                                '<p class="text-xs text-slate-500 dark:text-slate-400">Priority</p>' +
                                '<p class="text-sm font-bold text-slate-700 dark:text-slate-200">' + formatNumber(item.priority_score || 0) + '</p>' +
                            '</div>' +
                        '</div>' +
                        '<p class="mt-3 text-sm text-slate-700 dark:text-slate-200">' + escapeHtml(item.message || '') + '</p>' +
                        '<p class="mt-2 text-xs font-semibold text-slate-600 dark:text-slate-300">Action: ' + escapeHtml(item.suggested_action || '-') + '</p>' +
                        '<div class="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-600 dark:text-slate-300">' +
                            '<span class="rounded-lg bg-slate-100 px-2 py-1 dark:bg-slate-800">Current Rank: <strong>' + currentRank + '</strong></span>' +
                            '<span class="rounded-lg bg-slate-100 px-2 py-1 dark:bg-slate-800">Previous Rank: <strong>' + previousRank + '</strong></span>' +
                            '<span class="rounded-lg bg-slate-100 px-2 py-1 dark:bg-slate-800">Clicks: <strong>' + formatNumber(item.clicks || 0) + '</strong></span>' +
                            '<span class="rounded-lg bg-slate-100 px-2 py-1 dark:bg-slate-800">Impressions: <strong>' + formatNumber(item.impressions || 0) + '</strong></span>' +
                            '<span class="rounded-lg bg-slate-100 px-2 py-1 dark:bg-slate-800">CTR: <strong>' + formatPercent(item.ctr || 0) + '</strong></span>' +
                            '<span class="rounded-lg bg-slate-100 px-2 py-1 dark:bg-slate-800">Avg Position: <strong>' + formatNumber(item.position || 0, 2) + '</strong></span>' +
                        '</div>' +
                        '<div class="mt-3 flex items-center justify-between gap-2">' +
                            rankChangeLabel(item.current_rank, item.previous_rank) +
                            '<span class="text-[11px] text-slate-500 dark:text-slate-400">' + escapeHtml(formatDateTime(item.created_at)) + '</span>' +
                        '</div>' +
                    '</article>';
            }

            function renderSection(items, container, emptyState, tone) {
                if (!Array.isArray(items) || items.length === 0) {
                    container.innerHTML = '';
                    emptyState.classList.remove('hidden');
                    return;
                }
                emptyState.classList.add('hidden');
                container.innerHTML = items.map(function (item) {
                    return renderCard(item, tone);
                }).join('');
            }

            function populateProjects(projects, selectedProjectId) {
                state.projects = Array.isArray(projects) ? projects : [];

                if (state.projects.length === 0) {
                    projectSelect.innerHTML = '<option value="">No projects available</option>';
                    projectSelect.disabled = true;
                    state.projectId = null;
                    return;
                }

                projectSelect.disabled = false;
                var selected = Number(selectedProjectId || 0);
                if (!selected) {
                    selected = Number(state.projects[0].id || 0);
                }
                state.projectId = selected;

                projectSelect.innerHTML = state.projects.map(function (project) {
                    var id = Number(project.id || 0);
                    var label = escapeHtml((project.name || 'Project') + ' (' + (project.domain || '-') + ')');
                    var selectedAttr = id === selected ? ' selected' : '';
                    return '<option value="' + id + '"' + selectedAttr + '>' + label + '</option>';
                }).join('');
            }

            function applyData(payload) {
                populateProjects(payload.projects || [], payload.selected_project_id || null);

                var sections = payload.sections || {};
                var summary = payload.summary || {};
                var selectedProject = payload.selected_project || {};
                state.limits = payload.limits || state.limits;

                summaryTotal.textContent = formatNumber(summary.total || 0);
                summaryOpportunity.textContent = formatNumber(summary.opportunity || 0);
                summaryOptimization.textContent = formatNumber(summary.optimization || 0);
                summaryWarning.textContent = formatNumber(summary.warning || 0);
                summaryProject.textContent = selectedProject.name ? (selectedProject.name + ' (' + (selectedProject.domain || '-') + ')') : '-';
                summaryUpdated.textContent = 'Last generated: ' + (summary.last_generated_at ? formatDateTime(summary.last_generated_at) : '-');

                limitChip.textContent = 'Limit: ' + formatNumber(state.limits.max_insights || 0) + ' insights';

                if (summary.gsc_connected && summary.has_gsc_cache) {
                    gscChip.className = 'rounded-xl bg-emerald-100 px-3 py-1 font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300';
                    gscChip.textContent = 'GSC: connected and cached';
                } else if (summary.gsc_connected) {
                    gscChip.className = 'rounded-xl bg-amber-100 px-3 py-1 font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-300';
                    gscChip.textContent = 'GSC: connected, waiting for cache';
                } else {
                    gscChip.className = 'rounded-xl bg-red-100 px-3 py-1 font-semibold text-red-700 dark:bg-red-500/20 dark:text-red-300';
                    gscChip.textContent = 'GSC: not connected';
                }

                renderSection(sections.opportunity || [], sectionOpportunity, emptyOpportunity, 'opportunity');
                renderSection(sections.optimization || [], sectionOptimization, emptyOptimization, 'optimization');
                renderSection(sections.warning || [], sectionWarning, emptyWarning, 'warning');
            }

            async function postJson(payload) {
                var response = await fetch('insights-data.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                var data = {};
                try {
                    data = await response.json();
                } catch (error) {
                    data = {};
                }

                if (!response.ok || !data.success) {
                    var message = data.error || 'Request failed.';
                    throw new Error(message);
                }

                return data;
            }

            async function loadInsights() {
                setLoading(true);
                try {
                    var data = await postJson({
                        action: 'load',
                        project_id: state.projectId || null
                    });
                    applyData(data);
                    showMessage('', '');
                } catch (error) {
                    showMessage('error', error.message || 'Unable to load insights.');
                } finally {
                    setLoading(false);
                }
            }

            async function generateInsights() {
                if (!state.projectId) {
                    showMessage('error', 'Select a project before generating insights.');
                    return;
                }

                setLoading(true);
                try {
                    var data = await postJson({
                        action: 'generate',
                        project_id: state.projectId
                    });
                    var generation = data.generation || {};
                    applyData(data.data || {});
                    if (generation.message) {
                        showMessage('success', generation.message);
                        return;
                    }
                    showMessage(
                        'success',
                        'Insights generated. Created: ' + formatNumber(generation.created || 0) + ', total loaded: ' + formatNumber((data.data && data.data.summary && data.data.summary.total) || 0) + '.'
                    );
                } catch (error) {
                    showMessage('error', error.message || 'Unable to generate insights.');
                } finally {
                    setLoading(false);
                }
            }

            projectSelect.addEventListener('change', function () {
                state.projectId = Number(projectSelect.value || 0) || null;
                loadInsights();
            });

            refreshButton.addEventListener('click', function () {
                loadInsights();
            });

            generateButton.addEventListener('click', function () {
                generateInsights();
            });

            var themeToggle = document.getElementById('theme-toggle');
            if (themeToggle) {
                themeToggle.addEventListener('click', function () {
                    var root = document.documentElement;
                    var enableDark = !root.classList.contains('dark');
                    root.classList.toggle('dark', enableDark);
                    localStorage.setItem('seo-theme', enableDark ? 'dark' : 'light');
                });
            }

            loadInsights();
        })();
    </script>
</body>
</html>
