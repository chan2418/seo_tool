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
$isPaidPlan = in_array($planType, ['pro', 'agency'], true);
$hasKeywordAccess = true;
$defaultPerPage = $isPaidPlan ? 10 : 5;
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Keyword Tool - SEO Audit SaaS</title>
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
            border-color: rgba(51, 65, 85, 0.82);
            background: rgba(30, 41, 59, 0.82);
        }

        .keyword-card {
            transition: transform 160ms ease, box-shadow 160ms ease;
        }

        .keyword-card:hover {
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
        <div class="absolute left-[-9rem] top-[-10rem] h-80 w-80 rounded-full bg-indigo-300/40 blur-3xl dark:bg-indigo-500/20"></div>
        <div class="absolute right-[-6rem] top-16 h-72 w-72 rounded-full bg-sky-200/60 blur-3xl dark:bg-sky-500/15"></div>
        <div class="absolute bottom-[-8rem] left-1/2 h-72 w-72 -translate-x-1/2 rounded-full bg-violet-200/55 blur-3xl dark:bg-violet-500/15"></div>
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
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Research Lab</p>
                        <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Keyword Tool</h1>
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

                    <span class="hidden rounded-xl px-3 py-2 text-xs font-bold tracking-wide sm:inline-flex <?php echo $isPaidPlan ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'; ?>">
                        <?php echo htmlspecialchars($planLabel); ?> Plan
                    </span>

                    <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-3 py-2 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-400 text-sm font-bold text-white">
                            <?php echo htmlspecialchars(strtoupper(substr($userName, 0, 1))); ?>
                        </div>
                        <div class="hidden sm:block">
                            <p class="text-xs text-slate-500 dark:text-slate-400">Analyst</p>
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($userName); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="space-y-6 px-4 pb-10 pt-6 sm:px-6 lg:px-10">
            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
                    <div>
                        <span class="inline-flex items-center gap-2 rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">
                            Autocomplete Expansion Engine
                        </span>
                        <h2 class="mt-4 text-3xl font-extrabold text-slate-900 dark:text-slate-100">Generate high-quality keyword ideas fast</h2>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">We expand your query with Google Autocomplete and score every keyword by volume, difficulty, and intent.</p>
                    </div>
                    <div class="rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 p-5 text-white shadow-soft">
                        <p class="text-xs uppercase tracking-[0.18em] text-indigo-100">Current Access</p>
                        <p class="mt-2 text-xl font-bold"><?php echo htmlspecialchars($planLabel); ?> plan</p>
                        <p class="mt-2 text-xs text-indigo-100">
                            <?php echo $isPaidPlan ? '20-30 suggestions, unlimited daily searches.' : 'Keyword Research is locked on Free. Upgrade to Pro or Agency.'; ?>
                        </p>
                    </div>
                </div>

                <form id="keyword-form" class="mt-6 space-y-4">
                    <label for="keyword" class="block text-sm font-semibold text-slate-700 dark:text-slate-200">Seed keyword</label>
                    <div class="flex flex-col gap-3 md:flex-row">
                            <input
                                id="keyword"
                                name="keyword"
                                type="text"
                                maxlength="100"
                                placeholder="e.g. best digital marketing course"
                                class="w-full flex-1 rounded-2xl border border-slate-300 bg-white px-5 py-4 text-base font-medium text-slate-900 shadow-sm outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus:border-brand-400 dark:focus:ring-brand-500/30"
                                <?php echo $hasKeywordAccess ? '' : 'disabled'; ?>
                            >
                        <button
                            id="search-button"
                            type="submit"
                            class="inline-flex items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 px-6 py-4 text-sm font-semibold text-white shadow-soft transition hover:opacity-90"
                            <?php echo $hasKeywordAccess ? '' : 'disabled'; ?>
                        >
                            <svg id="search-spinner" class="hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 12a8 8 0 0 1 8-8m0 16a8 8 0 0 1-8-8" />
                            </svg>
                            <span id="search-label">Search Keywords</span>
                        </button>
                    </div>
                </form>

                <div id="message-box" class="mt-4 hidden rounded-2xl border p-4 text-sm font-medium"></div>
            </section>

            <section id="stats-grid" class="hidden grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="surface-card p-5 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Total Results</p>
                    <p id="stat-total" class="mt-2 text-3xl font-extrabold text-slate-900 dark:text-slate-100">0</p>
                </article>
                <article class="surface-card p-5 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Average Difficulty</p>
                    <p id="stat-difficulty" class="mt-2 text-3xl font-extrabold text-slate-900 dark:text-slate-100">0</p>
                </article>
                <article class="surface-card p-5 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Top Volume</p>
                    <p id="stat-volume" class="mt-2 text-3xl font-extrabold text-slate-900 dark:text-slate-100">0</p>
                </article>
                <article class="surface-card p-5 shadow-soft">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Daily Usage</p>
                    <p id="stat-limit" class="mt-2 text-lg font-extrabold text-slate-900 dark:text-slate-100">-</p>
                </article>
            </section>

            <section id="upgrade-card" class="<?php echo $hasKeywordAccess ? 'hidden' : ''; ?> surface-card p-6 shadow-soft sm:p-8">
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">Upgrade to unlock keyword intelligence</h3>
                <p id="upgrade-text" class="mt-2 text-sm text-slate-600 dark:text-slate-300"><?php echo $hasKeywordAccess ? '' : 'Free plan includes keyword access with lower limits. Upgrade for higher limits and faster refresh.'; ?></p>
                <div class="mt-4 flex flex-wrap gap-3">
                    <a href="subscription.php" class="inline-flex items-center rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-4 py-2 text-sm font-semibold text-white shadow-soft transition hover:opacity-90">View Plans</a>
                </div>
            </section>

            <section id="keyword-skeleton" class="hidden grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div class="skeleton h-40 rounded-2xl"></div>
                <div class="skeleton h-40 rounded-2xl"></div>
                <div class="skeleton h-40 rounded-2xl"></div>
            </section>

            <section id="results-grid" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"></section>

            <div class="flex justify-center">
                <button id="load-more" type="button" class="hidden rounded-2xl border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                    Load More
                </button>
            </div>
        </main>
    </div>

    <script>
        (function () {
            var state = {
                keyword: '',
                page: 1,
                perPage: <?php echo (int) $defaultPerPage; ?>,
                results: [],
                totalResults: 0,
                hasMore: false,
                lastLimits: null,
                loading: false
            };

            var sidebar = document.getElementById('app-sidebar');
            var overlay = document.getElementById('sidebar-overlay');
            var openButton = document.getElementById('sidebar-open');
            var themeButton = document.getElementById('theme-toggle');
            var form = document.getElementById('keyword-form');
            var keywordInput = document.getElementById('keyword');
            var searchButton = document.getElementById('search-button');
            var searchLabel = document.getElementById('search-label');
            var searchSpinner = document.getElementById('search-spinner');
            var messageBox = document.getElementById('message-box');
            var statsGrid = document.getElementById('stats-grid');
            var skeleton = document.getElementById('keyword-skeleton');
            var resultsGrid = document.getElementById('results-grid');
            var loadMoreButton = document.getElementById('load-more');
            var upgradeCard = document.getElementById('upgrade-card');
            var upgradeText = document.getElementById('upgrade-text');

            function openSidebar() {
                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('hidden');
            }

            function closeSidebar() {
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
            }

            function toggleTheme() {
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('seo-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
            }

            function setLoading(loading) {
                state.loading = loading;
                searchButton.disabled = loading;
                loadMoreButton.disabled = loading;

                if (loading) {
                    searchSpinner.classList.remove('hidden');
                    searchLabel.textContent = 'Analyzing...';
                    skeleton.classList.remove('hidden');
                } else {
                    searchSpinner.classList.add('hidden');
                    searchLabel.textContent = 'Search Keywords';
                    skeleton.classList.add('hidden');
                }
            }

            function showMessage(type, message) {
                var typeClasses = {
                    success: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
                    info: 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-600 dark:bg-slate-800/60 dark:text-slate-200',
                    warning: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
                    error: 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300'
                };

                messageBox.className = 'mt-4 rounded-2xl border p-4 text-sm font-medium';
                messageBox.classList.add('block');
                messageBox.classList.remove('hidden');

                (typeClasses[type] || typeClasses.info).split(' ').forEach(function (className) {
                    messageBox.classList.add(className);
                });

                messageBox.textContent = message;
            }

            function clearMessage() {
                messageBox.classList.add('hidden');
                messageBox.textContent = '';
            }

            function difficultyBadgeClass(score) {
                if (score <= 30) {
                    return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300';
                }
                if (score <= 60) {
                    return 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300';
                }
                if (score <= 80) {
                    return 'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300';
                }
                return 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300';
            }

            function intentBadgeClass(intent) {
                var normalized = (intent || '').toLowerCase();
                if (normalized === 'transactional') {
                    return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300';
                }
                if (normalized === 'commercial') {
                    return 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300';
                }
                if (normalized === 'navigational') {
                    return 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-300';
                }
                return 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300';
            }

            function formatNumber(value) {
                return new Intl.NumberFormat().format(Number(value || 0));
            }

            function renderStats() {
                if (state.results.length === 0) {
                    statsGrid.classList.add('hidden');
                    return;
                }

                statsGrid.classList.remove('hidden');
                var total = state.totalResults || state.results.length;
                var topVolume = 0;
                var difficultySum = 0;

                state.results.forEach(function (item) {
                    var volume = Number(item.volume || 0);
                    var difficulty = Number(item.difficulty || 0);
                    if (volume > topVolume) {
                        topVolume = volume;
                    }
                    difficultySum += difficulty;
                });

                var avgDifficulty = Math.round(difficultySum / state.results.length);

                document.getElementById('stat-total').textContent = String(total);
                document.getElementById('stat-difficulty').textContent = String(avgDifficulty);
                document.getElementById('stat-volume').textContent = formatNumber(topVolume);

                if (state.lastLimits && state.lastLimits.plan === 'free') {
                    document.getElementById('stat-limit').textContent = String(state.lastLimits.daily_used || 0) + ' / ' + String(state.lastLimits.daily_limit || 3);
                } else {
                    document.getElementById('stat-limit').textContent = 'Unlimited';
                }
            }

            function renderResults() {
                resultsGrid.innerHTML = '';

                if (state.results.length === 0) {
                    var emptyCard = document.createElement('article');
                    emptyCard.className = 'surface-card p-6 shadow-soft sm:p-8 md:col-span-2 xl:col-span-3';

                    var title = document.createElement('h3');
                    title.className = 'text-lg font-bold text-slate-900 dark:text-slate-100';
                    title.textContent = 'Start with a seed keyword';

                    var text = document.createElement('p');
                    text.className = 'mt-2 text-sm text-slate-600 dark:text-slate-300';
                    text.textContent = 'Search now to generate autocomplete expansions, intent labels, and difficulty scoring.';

                    emptyCard.appendChild(title);
                    emptyCard.appendChild(text);
                    resultsGrid.appendChild(emptyCard);
                    return;
                }

                state.results.forEach(function (item) {
                    var card = document.createElement('article');
                    card.className = 'keyword-card surface-card p-5 shadow-soft';

                    var top = document.createElement('div');
                    top.className = 'flex items-start justify-between gap-3';

                    var left = document.createElement('div');
                    var keyword = document.createElement('p');
                    keyword.className = 'text-base font-bold text-slate-900 dark:text-slate-100';
                    keyword.textContent = item.keyword || '';
                    left.appendChild(keyword);

                    var subtitle = document.createElement('p');
                    subtitle.className = 'mt-1 text-xs text-slate-500 dark:text-slate-400';
                    subtitle.textContent = 'Keyword opportunity';
                    left.appendChild(subtitle);

                    var difficulty = document.createElement('span');
                    difficulty.className = 'rounded-full px-3 py-1 text-xs font-semibold ' + difficultyBadgeClass(Number(item.difficulty || 0));
                    difficulty.textContent = (item.difficulty_label || 'Medium') + ' (' + String(item.difficulty || 0) + ')';

                    top.appendChild(left);
                    top.appendChild(difficulty);

                    var meta = document.createElement('div');
                    meta.className = 'mt-4 flex items-center gap-2';

                    var intent = document.createElement('span');
                    intent.className = 'rounded-full px-3 py-1 text-xs font-semibold ' + intentBadgeClass(String(item.intent || 'Informational'));
                    intent.textContent = item.intent || 'Informational';

                    var volume = document.createElement('span');
                    volume.className = 'text-xs font-semibold text-slate-500 dark:text-slate-400';
                    volume.textContent = 'Volume ' + formatNumber(item.volume || 0);

                    meta.appendChild(intent);
                    meta.appendChild(volume);

                    var barWrap = document.createElement('div');
                    barWrap.className = 'mt-4';

                    var progressHeader = document.createElement('div');
                    progressHeader.className = 'mb-2 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400';

                    var progressLabel = document.createElement('span');
                    progressLabel.textContent = 'Search volume';

                    var volumeScore = Number(item.volume || 0);
                    var percent = Math.max(8, Math.min(100, Math.round((volumeScore / 30000) * 100)));

                    var progressValue = document.createElement('span');
                    progressValue.textContent = String(percent) + '%';

                    progressHeader.appendChild(progressLabel);
                    progressHeader.appendChild(progressValue);

                    var track = document.createElement('div');
                    track.className = 'h-2 rounded-full bg-slate-200 dark:bg-slate-700';

                    var bar = document.createElement('div');
                    bar.className = 'h-2 rounded-full bg-gradient-to-r from-brand-500 to-brand-400';
                    bar.style.width = String(percent) + '%';

                    track.appendChild(bar);
                    barWrap.appendChild(progressHeader);
                    barWrap.appendChild(track);

                    card.appendChild(top);
                    card.appendChild(meta);
                    card.appendChild(barWrap);

                    resultsGrid.appendChild(card);
                });
            }

            function setLoadMoreVisibility() {
                if (state.hasMore) {
                    loadMoreButton.classList.remove('hidden');
                } else {
                    loadMoreButton.classList.add('hidden');
                }
            }

            function setUpgradeCard(visible, message) {
                if (visible) {
                    upgradeCard.classList.remove('hidden');
                    upgradeText.textContent = message || 'Upgrade to a paid plan for more results and unlimited daily searches.';
                } else {
                    upgradeCard.classList.add('hidden');
                    upgradeText.textContent = '';
                }
            }

            async function requestKeywords(append) {
                if (!state.keyword) {
                    return;
                }

                setLoading(true);
                clearMessage();

                try {
                    var response = await fetch('keyword-search.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            keyword: state.keyword,
                            page: state.page,
                            per_page: state.perPage
                        })
                    });

                    var data = await response.json();

                    if (!response.ok || !data.success) {
                        throw data;
                    }

                    var incoming = Array.isArray(data.results) ? data.results : [];
                    state.results = append ? state.results.concat(incoming) : incoming;
                    state.totalResults = Number(data.total_results || state.results.length);
                    state.hasMore = Boolean(data.has_more);
                    state.lastLimits = data.limits || null;

                    renderResults();
                    renderStats();
                    setLoadMoreVisibility();
                    setUpgradeCard(false, '');

                    if (data.cached) {
                        showMessage('info', 'Showing cached results from the last 24 hours to save API requests.');
                    } else {
                        showMessage('success', 'Keyword research complete. Suggestions expanded using Google Autocomplete.');
                    }
                } catch (errorData) {
                    var message = (errorData && errorData.error) ? errorData.error : 'Keyword search failed. Please try again.';
                    showMessage('error', message);
                    if (errorData && errorData.upgrade_required) {
                        setUpgradeCard(true, message);
                    }
                    if (!append) {
                        state.results = [];
                        renderResults();
                        renderStats();
                        setLoadMoreVisibility();
                    }
                } finally {
                    setLoading(false);
                }
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var keyword = keywordInput.value.trim();

                if (keyword.length < 2 || keyword.length > 100) {
                    showMessage('warning', 'Keyword must be between 2 and 100 characters.');
                    return;
                }

                state.keyword = keyword;
                state.page = 1;
                state.results = [];
                state.totalResults = 0;
                state.hasMore = false;
                setUpgradeCard(false, '');

                requestKeywords(false);
            });

            loadMoreButton.addEventListener('click', function () {
                if (!state.hasMore || state.loading) {
                    return;
                }

                state.page += 1;
                requestKeywords(true);
            });

            openButton && openButton.addEventListener('click', openSidebar);
            overlay && overlay.addEventListener('click', closeSidebar);
            themeButton && themeButton.addEventListener('click', toggleTheme);

            renderResults();
        })();
    </script>
</body>
</html>
