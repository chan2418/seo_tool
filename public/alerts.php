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
    <title>Alerts - SEO Audit SaaS</title>
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

        .alert-item {
            transition: transform 150ms ease, box-shadow 150ms ease;
        }

        .alert-item:hover {
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
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Monitoring Center</p>
                        <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Alerts & Notifications</h1>
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
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-400 text-sm font-bold text-white">
                            <?php echo htmlspecialchars(strtoupper(substr($userName, 0, 1))); ?>
                        </div>
                        <p class="hidden text-sm font-semibold sm:block"><?php echo htmlspecialchars($userName); ?></p>
                    </div>
                </div>
            </div>
        </header>

        <main class="space-y-6 px-4 pb-10 pt-6 sm:px-6 lg:px-10">
            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-extrabold">Real-time SEO alert feed</h2>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Track rank drops, score changes, backlink movement, and crawl risk from one panel.</p>
                    </div>
                    <div class="rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 px-5 py-4 text-white shadow-soft">
                        <p class="text-xs uppercase tracking-[0.18em] text-indigo-100">Unread Alerts</p>
                        <p id="unread-count" class="mt-1 text-3xl font-extrabold">0</p>
                    </div>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-[1.3fr_0.7fr]">
                <article class="surface-card p-6 shadow-soft">
                    <div id="message-box" class="mb-4 hidden rounded-2xl border p-3 text-sm font-medium"></div>

                    <form id="filter-form" class="grid gap-3 md:grid-cols-5">
                        <div>
                            <label for="project-filter" class="mb-2 block text-sm font-semibold">Project</label>
                            <select id="project-filter" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900"></select>
                        </div>
                        <div>
                            <label for="severity-filter" class="mb-2 block text-sm font-semibold">Severity</label>
                            <select id="severity-filter" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900">
                                <option value="">All</option>
                                <option value="info">Info</option>
                                <option value="warning">Warning</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                        <div>
                            <label for="read-filter" class="mb-2 block text-sm font-semibold">Read Status</label>
                            <select id="read-filter" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900">
                                <option value="">All</option>
                                <option value="0">Unread</option>
                                <option value="1">Read</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label for="search-filter" class="mb-2 block text-sm font-semibold">Search</label>
                            <div class="flex items-center gap-2">
                                <input id="search-filter" type="text" maxlength="120" placeholder="Search message or alert type" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900">
                                <button type="submit" class="rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-4 py-2 text-sm font-semibold text-white shadow-soft">Filter</button>
                            </div>
                        </div>
                    </form>

                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                        <p id="alerts-meta" class="text-sm text-slate-600 dark:text-slate-300">Loading alerts...</p>
                        <button id="mark-all-read" type="button" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Mark All Read</button>
                    </div>

                    <div id="alerts-list" class="mt-4 space-y-3"></div>
                    <div id="alerts-empty" class="mt-4 hidden rounded-xl border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                        No alerts for the selected filters.
                    </div>

                    <div class="mt-4 flex items-center justify-between text-sm">
                        <button id="prev-page" class="rounded-xl border border-slate-300 bg-white px-4 py-2 font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Previous</button>
                        <p id="pagination-label" class="text-slate-600 dark:text-slate-300">Page 1</p>
                        <button id="next-page" class="rounded-xl border border-slate-300 bg-white px-4 py-2 font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Next</button>
                    </div>
                </article>

                <aside class="space-y-6">
                    <article class="surface-card p-6 shadow-soft">
                        <h3 class="text-lg font-bold">Alert Settings</h3>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Thresholds are project-based and enforced server-side.</p>

                        <form id="settings-form" class="mt-4 space-y-4">
                            <div>
                                <label for="settings-project" class="mb-2 block text-sm font-semibold">Project</label>
                                <select id="settings-project" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900"></select>
                            </div>
                            <div>
                                <label for="rank-threshold" class="mb-2 block text-sm font-semibold">Rank Drop Threshold</label>
                                <input id="rank-threshold" type="number" min="2" max="100" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900">
                            </div>
                            <div>
                                <label for="seo-threshold" class="mb-2 block text-sm font-semibold">SEO Score Drop Threshold</label>
                                <input id="seo-threshold" type="number" min="1" max="50" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900">
                            </div>
                            <label class="flex items-center gap-2 text-sm font-semibold">
                                <input id="email-enabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                                Enable email summaries
                            </label>
                            <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-4 py-2 text-sm font-semibold text-white shadow-soft">Save Settings</button>
                        </form>
                    </article>

                    <article class="surface-card p-6 shadow-soft">
                        <h3 class="text-lg font-bold">Severity Guide</h3>
                        <ul class="mt-3 space-y-2 text-sm">
                            <li class="rounded-lg bg-sky-100 px-3 py-2 font-semibold text-sky-700 dark:bg-sky-500/20 dark:text-sky-300">Info: positive or neutral updates</li>
                            <li class="rounded-lg bg-amber-100 px-3 py-2 font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">Warning: needs attention soon</li>
                            <li class="rounded-lg bg-red-100 px-3 py-2 font-semibold text-red-700 dark:bg-red-500/20 dark:text-red-300">Critical: immediate SEO risk</li>
                        </ul>
                    </article>
                </aside>
            </section>
        </main>
    </div>

    <script>
        (function () {
            var state = {
                page: 1,
                perPage: 12,
                projectId: null,
                severity: '',
                isRead: '',
                search: '',
                projects: [],
                pagination: {
                    page: 1,
                    total_pages: 1,
                    has_next: false,
                    has_prev: false
                }
            };

            var messageBox = document.getElementById('message-box');
            var unreadCount = document.getElementById('unread-count');
            var alertsMeta = document.getElementById('alerts-meta');
            var alertsList = document.getElementById('alerts-list');
            var alertsEmpty = document.getElementById('alerts-empty');
            var projectFilter = document.getElementById('project-filter');
            var severityFilter = document.getElementById('severity-filter');
            var readFilter = document.getElementById('read-filter');
            var searchFilter = document.getElementById('search-filter');
            var filterForm = document.getElementById('filter-form');
            var markAllReadButton = document.getElementById('mark-all-read');
            var prevPageButton = document.getElementById('prev-page');
            var nextPageButton = document.getElementById('next-page');
            var paginationLabel = document.getElementById('pagination-label');

            var settingsForm = document.getElementById('settings-form');
            var settingsProject = document.getElementById('settings-project');
            var rankThreshold = document.getElementById('rank-threshold');
            var seoThreshold = document.getElementById('seo-threshold');
            var emailEnabled = document.getElementById('email-enabled');

            function showMessage(type, message) {
                var classMap = {
                    success: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
                    warning: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
                    error: 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300',
                    info: 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-600 dark:bg-slate-800/60 dark:text-slate-200'
                };

                messageBox.className = 'mb-4 rounded-2xl border p-3 text-sm font-medium';
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

            function escapeHtml(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function formatDate(value) {
                if (!value) {
                    return '-';
                }
                var date = new Date(value);
                if (isNaN(date.getTime())) {
                    return value;
                }
                return date.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            }

            function api(action, payload) {
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
                            data = { success: false, error: 'Invalid server response.' };
                        }
                        if (!response.ok || !data.success) {
                            var err = new Error(data.error || 'Request failed');
                            err.payload = data;
                            throw err;
                        }
                        return data;
                    });
                });
            }

            function severityBadge(severity) {
                if (severity === 'critical') {
                    return {
                        card: 'border-red-200 bg-red-50 dark:border-red-500/30 dark:bg-red-500/10',
                        tag: 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300',
                        icon: 'C'
                    };
                }
                if (severity === 'warning') {
                    return {
                        card: 'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10',
                        tag: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
                        icon: 'W'
                    };
                }
                return {
                    card: 'border-sky-200 bg-sky-50 dark:border-sky-500/30 dark:bg-sky-500/10',
                    tag: 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300',
                    icon: 'I'
                };
            }

            function detailLink(alertType, projectId) {
                if (!projectId) {
                    return 'dashboard.php';
                }
                if ((alertType || '').indexOf('rank_') === 0) {
                    return 'rank-tracker.php?project=' + encodeURIComponent(projectId);
                }
                if ((alertType || '').indexOf('backlink_') === 0 || alertType === 'ref_domains_drop') {
                    return 'backlinks.php';
                }
                if ((alertType || '').indexOf('crawl_') === 0) {
                    return 'crawl.php';
                }
                return 'history.php';
            }

            function renderProjects(projects) {
                state.projects = Array.isArray(projects) ? projects : [];
                var options = ['<option value="">All Projects</option>'];
                state.projects.forEach(function (project) {
                    options.push('<option value="' + Number(project.id || 0) + '">' + escapeHtml((project.name || 'Project') + ' (' + (project.domain || '-') + ')') + '</option>');
                });
                projectFilter.innerHTML = options.join('');

                var settingsOptions = ['<option value="">Select Project</option>'];
                state.projects.forEach(function (project) {
                    settingsOptions.push('<option value="' + Number(project.id || 0) + '">' + escapeHtml((project.name || 'Project') + ' (' + (project.domain || '-') + ')') + '</option>');
                });
                settingsProject.innerHTML = settingsOptions.join('');

                if (state.projects.length === 0) {
                    settingsForm.querySelector('button[type="submit"]').disabled = true;
                    rankThreshold.value = '';
                    seoThreshold.value = '';
                    emailEnabled.checked = false;
                    showMessage('info', 'No projects found yet. Run an audit or add a project in Rank Tracker, then reload Alerts.');
                }
            }

            function renderAlerts(items) {
                alertsList.innerHTML = '';

                if (!Array.isArray(items) || items.length === 0) {
                    alertsEmpty.classList.remove('hidden');
                    return;
                }

                alertsEmpty.classList.add('hidden');
                items.forEach(function (item) {
                    var badge = severityBadge(String(item.severity || 'info').toLowerCase());
                    var card = document.createElement('article');
                    card.className = 'alert-item rounded-2xl border p-4 ' + badge.card + (Number(item.is_read || 0) === 0 ? ' shadow-soft' : '');
                    card.innerHTML =
                        '<div class="flex flex-wrap items-start justify-between gap-3">' +
                            '<div class="max-w-[85%]">' +
                                '<div class="flex items-center gap-2">' +
                                    '<span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-200 text-[10px] font-bold text-slate-700 dark:bg-slate-700 dark:text-slate-200">' + badge.icon + '</span>' +
                                    '<span class="rounded-lg px-2 py-1 text-xs font-bold ' + badge.tag + '">' + escapeHtml(String(item.severity || 'info').toUpperCase()) + '</span>' +
                                    (Number(item.is_read || 0) === 0 ? '<span class="rounded-lg bg-brand-500 px-2 py-1 text-[10px] font-bold text-white">NEW</span>' : '') +
                                '</div>' +
                                '<p class="mt-2 text-sm font-semibold text-slate-800 dark:text-slate-100">' + escapeHtml(item.message || 'Alert triggered.') + '</p>' +
                                '<p class="mt-1 text-xs text-slate-500 dark:text-slate-400">' + escapeHtml(item.project_name || 'Project') + ' - ' + escapeHtml(formatDate(item.created_at || '')) + '</p>' +
                            '</div>' +
                            '<div class="flex items-center gap-2">' +
                                '<a class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800" href="' + detailLink(item.alert_type || '', Number(item.project_id || 0)) + '">View Details</a>' +
                                (Number(item.is_read || 0) === 0 ? '<button data-action="mark-read" data-id="' + Number(item.id || 0) + '" class="rounded-lg bg-gradient-to-r from-brand-500 to-brand-400 px-3 py-2 text-xs font-semibold text-white">Mark Read</button>' : '') +
                            '</div>' +
                        '</div>';
                    alertsList.appendChild(card);
                });
            }

            function renderPagination(pagination) {
                state.pagination = pagination || state.pagination;
                var page = Number(state.pagination.page || 1);
                var totalPages = Math.max(1, Number(state.pagination.total_pages || 1));
                paginationLabel.textContent = 'Page ' + page + ' of ' + totalPages;
                prevPageButton.disabled = !state.pagination.page || page <= 1;
                nextPageButton.disabled = !state.pagination.total_pages || page >= totalPages;
            }

            function applyFilterState() {
                state.projectId = projectFilter.value === '' ? null : Number(projectFilter.value);
                state.severity = severityFilter.value || '';
                state.isRead = readFilter.value;
                state.search = searchFilter.value.trim();
            }

            function loadAlerts() {
                clearMessage();
                return api('load', {
                    page: state.page,
                    per_page: state.perPage,
                    project_id: state.projectId,
                    severity: state.severity || null,
                    search: state.search || '',
                    is_read: state.isRead === '' ? null : Number(state.isRead)
                }).then(function (data) {
                    renderProjects(data.projects || []);

                    if (state.projectId !== null) {
                        projectFilter.value = String(state.projectId);
                    } else {
                        projectFilter.value = '';
                    }

                    unreadCount.textContent = String(data.unread_count || 0);
                    alertsMeta.textContent = (data.pagination && data.pagination.total ? data.pagination.total : 0) + ' alerts found';
                    renderAlerts(data.alerts || []);
                    renderPagination(data.pagination || {});

                    if (settingsProject.value === '' && state.projects.length > 0) {
                        settingsProject.value = String(state.projects[0].id || '');
                        loadSettings();
                    }
                }).catch(function (error) {
                    showMessage('error', (error.payload && error.payload.error) || error.message || 'Unable to load alerts.');
                });
            }

            function loadSettings() {
                var projectId = Number(settingsProject.value || 0);
                if (!projectId) {
                    rankThreshold.value = '';
                    seoThreshold.value = '';
                    emailEnabled.checked = false;
                    settingsForm.querySelector('button[type="submit"]').disabled = true;
                    return;
                }

                settingsForm.querySelector('button[type="submit"]').disabled = false;
                api('get_settings', { project_id: projectId }).then(function (data) {
                    var settings = data.settings || {};
                    rankThreshold.value = Number(settings.rank_drop_threshold || 10);
                    seoThreshold.value = Number(settings.seo_score_drop_threshold || 5);
                    emailEnabled.checked = Number(settings.email_notifications_enabled || 0) === 1;

                    if ('<?php echo htmlspecialchars($planType, ENT_QUOTES, 'UTF-8'); ?>' === 'free') {
                        emailEnabled.checked = false;
                        emailEnabled.disabled = true;
                    } else {
                        emailEnabled.disabled = false;
                    }
                }).catch(function (error) {
                    showMessage('error', (error.payload && error.payload.error) || error.message || 'Unable to load alert settings.');
                });
            }

            function saveSettings(event) {
                event.preventDefault();
                var projectId = Number(settingsProject.value || 0);
                if (!projectId) {
                    showMessage('warning', 'Select a project before saving settings.');
                    return;
                }

                api('save_settings', {
                    project_id: projectId,
                    rank_drop_threshold: Number(rankThreshold.value || 10),
                    seo_score_drop_threshold: Number(seoThreshold.value || 5),
                    email_notifications_enabled: emailEnabled.checked
                }).then(function () {
                    showMessage('success', 'Alert settings updated.');
                }).catch(function (error) {
                    showMessage('error', (error.payload && error.payload.error) || error.message || 'Unable to save alert settings.');
                });
            }

            function markAllRead() {
                api('mark_all_read', { project_id: state.projectId }).then(function (data) {
                    showMessage('success', 'Marked ' + Number(data.updated || 0) + ' alerts as read.');
                    loadAlerts();
                }).catch(function (error) {
                    showMessage('error', (error.payload && error.payload.error) || error.message || 'Unable to mark all alerts as read.');
                });
            }

            function handleAlertActions(event) {
                var markButton = event.target.closest('button[data-action="mark-read"]');
                if (!markButton) {
                    return;
                }

                var alertId = Number(markButton.getAttribute('data-id') || 0);
                if (!alertId) {
                    return;
                }

                api('mark_read', { alert_id: alertId }).then(function () {
                    loadAlerts();
                }).catch(function (error) {
                    showMessage('error', (error.payload && error.payload.error) || error.message || 'Unable to mark alert as read.');
                });
            }

            filterForm.addEventListener('submit', function (event) {
                event.preventDefault();
                state.page = 1;
                applyFilterState();
                loadAlerts();
            });

            projectFilter.addEventListener('change', function () {
                state.page = 1;
                applyFilterState();
                loadAlerts();
            });
            severityFilter.addEventListener('change', function () {
                state.page = 1;
                applyFilterState();
                loadAlerts();
            });
            readFilter.addEventListener('change', function () {
                state.page = 1;
                applyFilterState();
                loadAlerts();
            });

            settingsProject.addEventListener('change', loadSettings);
            settingsForm.addEventListener('submit', saveSettings);
            markAllReadButton.addEventListener('click', markAllRead);
            alertsList.addEventListener('click', handleAlertActions);

            prevPageButton.addEventListener('click', function () {
                if (state.page <= 1) {
                    return;
                }
                state.page -= 1;
                loadAlerts();
            });

            nextPageButton.addEventListener('click', function () {
                var totalPages = Number(state.pagination.total_pages || 1);
                if (state.page >= totalPages) {
                    return;
                }
                state.page += 1;
                loadAlerts();
            });

            var themeButton = document.getElementById('theme-toggle');
            themeButton && themeButton.addEventListener('click', function () {
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('seo-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
            });

            applyFilterState();
            loadAlerts();
        })();
    </script>
</body>
</html>
