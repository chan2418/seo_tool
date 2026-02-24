<?php
session_start();

require_once __DIR__ . '/../services/PlanEnforcementService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';

$auth = AuthMiddleware::requireLogin(false);
AccountStatusMiddleware::ensureActive(false);

$userId = (int) ($auth['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$userName = (string) ($auth['user_name'] ?? ($_SESSION['user_name'] ?? 'User'));

$planService = new PlanEnforcementService();
$planType = $planService->getEffectivePlan($userId, (string) ($_SESSION['plan_type'] ?? 'free'));
$planLabel = ucfirst($planType);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Intelligence - SEO Suite</title>
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
            background: rgba(30, 41, 59, 0.84);
        }

        .module-chip {
            transition: transform 150ms ease, background-color 150ms ease, color 150ms ease;
        }

        .module-chip:hover {
            transform: translateY(-1px);
        }

        .module-chip.active {
            color: #ffffff;
            background: linear-gradient(90deg, #4F46E5 0%, #6366F1 100%);
            box-shadow: 0 18px 45px -25px rgba(15, 23, 42, 0.35);
        }

        .status-badge {
            border-radius: 9999px;
            padding: 0.25rem 0.6rem;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.14);
            color: #B45309;
        }

        .dark .status-pending {
            background: rgba(245, 158, 11, 0.22);
            color: #FCD34D;
        }

        .status-processing {
            background: rgba(79, 70, 229, 0.14);
            color: #4338CA;
        }

        .dark .status-processing {
            background: rgba(79, 70, 229, 0.24);
            color: #C7D2FE;
        }

        .status-completed {
            background: rgba(34, 197, 94, 0.14);
            color: #166534;
        }

        .dark .status-completed {
            background: rgba(34, 197, 94, 0.24);
            color: #86EFAC;
        }

        .status-failed {
            background: rgba(239, 68, 68, 0.15);
            color: #B91C1C;
        }

        .dark .status-failed {
            background: rgba(239, 68, 68, 0.24);
            color: #FCA5A5;
        }

        .loader-dot {
            animation: pulse-dot 1s infinite ease-in-out;
        }

        .loader-dot:nth-child(2) {
            animation-delay: 0.16s;
        }

        .loader-dot:nth-child(3) {
            animation-delay: 0.32s;
        }

        @keyframes pulse-dot {
            0%, 80%, 100% {
                opacity: 0.35;
                transform: scale(0.9);
            }
            40% {
                opacity: 1;
                transform: scale(1.06);
            }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute -left-28 top-[-9rem] h-80 w-80 rounded-full bg-indigo-300/40 blur-3xl dark:bg-indigo-500/20"></div>
        <div class="absolute right-[-8rem] top-24 h-72 w-72 rounded-full bg-sky-200/55 blur-3xl dark:bg-sky-500/15"></div>
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
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Phase 5</p>
                        <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">AI Intelligence Layer</h1>
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
                <div class="grid gap-6 lg:grid-cols-[1.15fr_0.85fr]">
                    <div>
                        <span class="inline-flex items-center rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">AI SEO Advisor</span>
                        <h2 class="mt-4 text-3xl font-extrabold text-slate-900 dark:text-slate-100">Data-backed SEO recommendations</h2>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">The AI module uses your project rank data, Search Console cache, and insights output. It does not pull your full database.</p>
                    </div>

                    <div class="rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 p-5 text-white shadow-soft">
                        <p class="text-xs uppercase tracking-[0.18em] text-indigo-100">Monthly AI Usage</p>
                        <p class="mt-2 text-4xl font-extrabold"><span id="usage-used">0</span> / <span id="usage-limit">0</span></p>
                        <p id="usage-remaining" class="mt-2 text-xs text-indigo-100">Remaining: 0</p>
                        <p id="usage-month" class="mt-1 text-xs text-indigo-100">Month: -</p>
                    </div>
                </div>

                <div id="message-box" class="mt-5 hidden rounded-2xl border p-4 text-sm font-medium"></div>

                <div class="mt-6 grid gap-3 md:grid-cols-4">
                    <div class="md:col-span-2">
                        <label for="project-select" class="mb-2 block text-sm font-semibold">Project</label>
                        <select id="project-select" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30"></select>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold">AI Status</label>
                        <div id="global-status" class="rounded-xl border border-slate-300 bg-slate-50 px-3 py-3 text-xs font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">Checking...</div>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold">Queue Active</label>
                        <div id="queue-processing" class="rounded-xl border border-slate-300 bg-slate-50 px-3 py-3 text-xs font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">0</div>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <button data-module="advisor" class="module-chip active rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">AI Advisor</button>
                    <button data-module="meta" class="module-chip rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">Meta Generator</button>
                    <button data-module="optimizer" class="module-chip rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">Content Optimizer</button>
                </div>
            </section>

            <section class="surface-card p-6 shadow-soft">
                <div id="module-advisor" class="module-panel">
                    <label for="advisor-question" class="mb-2 block text-sm font-semibold">Ask your SEO question</label>
                    <textarea id="advisor-question" rows="5" maxlength="600" placeholder="Example: Why did my top keyword drop in clicks even after ranking improved?" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30"></textarea>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Use focused questions for better recommendations.</p>
                </div>

                <div id="module-meta" class="module-panel hidden space-y-4">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label for="meta-keyword" class="mb-2 block text-sm font-semibold">Target Keyword</label>
                            <input id="meta-keyword" type="text" maxlength="100" placeholder="best digital marketing course" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30">
                        </div>
                        <div>
                            <label for="meta-page-url" class="mb-2 block text-sm font-semibold">Page URL</label>
                            <input id="meta-page-url" type="text" maxlength="2048" placeholder="https://example.com/page" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30">
                        </div>
                    </div>
                    <div>
                        <label for="meta-current-title" class="mb-2 block text-sm font-semibold">Current Title (optional)</label>
                        <input id="meta-current-title" type="text" maxlength="120" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30">
                    </div>
                    <div>
                        <label for="meta-current-description" class="mb-2 block text-sm font-semibold">Current Meta Description (optional)</label>
                        <textarea id="meta-current-description" rows="3" maxlength="220" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30"></textarea>
                    </div>
                </div>

                <div id="module-optimizer" class="module-panel hidden space-y-4">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label for="optimizer-keyword" class="mb-2 block text-sm font-semibold">Target Keyword</label>
                            <input id="optimizer-keyword" type="text" maxlength="100" placeholder="seo audit tool" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30">
                        </div>
                        <div>
                            <label for="optimizer-page-url" class="mb-2 block text-sm font-semibold">Page URL</label>
                            <input id="optimizer-page-url" type="text" maxlength="2048" placeholder="https://example.com/seo-audit" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30">
                        </div>
                    </div>
                    <div>
                        <label for="optimizer-headings" class="mb-2 block text-sm font-semibold">Current Headings (optional)</label>
                        <textarea id="optimizer-headings" rows="3" maxlength="700" placeholder="H1: ...\nH2: ..." class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30"></textarea>
                    </div>
                    <div>
                        <label for="optimizer-summary" class="mb-2 block text-sm font-semibold">Content Summary (optional)</label>
                        <textarea id="optimizer-summary" rows="5" maxlength="1400" placeholder="Add a brief summary of your current page content..." class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30"></textarea>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button id="submit-btn" type="button" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-5 py-3 text-sm font-semibold text-white shadow-soft transition hover:opacity-90">
                        <span id="submit-text">Generate AI Suggestion</span>
                        <span id="submit-loading" class="hidden items-center gap-1">
                            <span class="loader-dot h-1.5 w-1.5 rounded-full bg-white"></span>
                            <span class="loader-dot h-1.5 w-1.5 rounded-full bg-white"></span>
                            <span class="loader-dot h-1.5 w-1.5 rounded-full bg-white"></span>
                        </span>
                    </button>
                    <span class="rounded-xl bg-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-200">Plan: <?php echo htmlspecialchars($planLabel); ?></span>
                    <span id="input-limit-chip" class="rounded-xl bg-sky-100 px-3 py-2 text-xs font-semibold text-sky-700 dark:bg-sky-500/20 dark:text-sky-300">Max input: 600 chars</span>
                </div>
            </section>

            <section id="queue-card" class="hidden surface-card p-6 shadow-soft">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">Request Queued</h3>
                        <p class="text-sm text-slate-600 dark:text-slate-300">AI is currently busy. Your request will process automatically.</p>
                    </div>
                    <span id="queue-position-badge" class="rounded-full bg-amber-100 px-3 py-2 text-xs font-bold text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">Position #1</span>
                </div>
            </section>

            <section id="result-card" class="hidden surface-card p-6 shadow-soft">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">AI Result</h3>
                        <p id="result-meta" class="text-xs text-slate-500 dark:text-slate-400">-</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button id="copy-result-btn" type="button" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Copy</button>
                        <button id="download-result-btn" type="button" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Download</button>
                    </div>
                </div>

                <div id="result-body" class="space-y-4"></div>
            </section>

            <section class="surface-card p-6 shadow-soft">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">Recent AI Requests</h3>
                    <button id="refresh-history-btn" type="button" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Refresh</button>
                </div>
                <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-[0.14em] text-slate-500 dark:bg-slate-900/80 dark:text-slate-400">
                            <tr>
                                <th class="px-3 py-2 text-left">Type</th>
                                <th class="px-3 py-2 text-left">Status</th>
                                <th class="px-3 py-2 text-left">Created</th>
                                <th class="px-3 py-2 text-left">Tokens</th>
                                <th class="px-3 py-2 text-left">Cost (INR)</th>
                                <th class="px-3 py-2 text-left">Action</th>
                            </tr>
                        </thead>
                        <tbody id="history-body">
                            <tr>
                                <td colspan="6" class="px-3 py-4 text-center text-slate-500">Loading requests...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script>
        (function () {
            var state = {
                module: 'advisor',
                projectId: null,
                projects: [],
                activeRequestId: null,
                pollTimer: null,
                lastResult: null
            };

            var elements = {
                messageBox: document.getElementById('message-box'),
                projectSelect: document.getElementById('project-select'),
                moduleButtons: document.querySelectorAll('[data-module]'),
                modulePanels: document.querySelectorAll('.module-panel'),
                submitButton: document.getElementById('submit-btn'),
                submitText: document.getElementById('submit-text'),
                submitLoading: document.getElementById('submit-loading'),
                usageUsed: document.getElementById('usage-used'),
                usageLimit: document.getElementById('usage-limit'),
                usageRemaining: document.getElementById('usage-remaining'),
                usageMonth: document.getElementById('usage-month'),
                globalStatus: document.getElementById('global-status'),
                queueProcessing: document.getElementById('queue-processing'),
                inputLimitChip: document.getElementById('input-limit-chip'),
                queueCard: document.getElementById('queue-card'),
                queuePositionBadge: document.getElementById('queue-position-badge'),
                resultCard: document.getElementById('result-card'),
                resultMeta: document.getElementById('result-meta'),
                resultBody: document.getElementById('result-body'),
                historyBody: document.getElementById('history-body'),
                refreshHistoryButton: document.getElementById('refresh-history-btn'),
                copyResultButton: document.getElementById('copy-result-btn'),
                downloadResultButton: document.getElementById('download-result-btn')
            };

            function toggleTheme() {
                var root = document.documentElement;
                root.classList.toggle('dark');
                localStorage.setItem('seo-theme', root.classList.contains('dark') ? 'dark' : 'light');
            }

            var themeToggle = document.getElementById('theme-toggle');
            if (themeToggle) {
                themeToggle.addEventListener('click', toggleTheme);
            }

            function setLoading(loading) {
                elements.submitButton.disabled = loading;
                elements.projectSelect.disabled = loading;
                elements.submitButton.classList.toggle('opacity-70', loading);
                elements.submitButton.classList.toggle('cursor-not-allowed', loading);
                if (loading) {
                    elements.submitText.classList.add('hidden');
                    elements.submitLoading.classList.remove('hidden');
                    elements.submitLoading.classList.add('inline-flex');
                } else {
                    elements.submitText.classList.remove('hidden');
                    elements.submitLoading.classList.add('hidden');
                    elements.submitLoading.classList.remove('inline-flex');
                }
            }

            function showMessage(type, text) {
                if (!text) {
                    elements.messageBox.className = 'mt-5 hidden rounded-2xl border p-4 text-sm font-medium';
                    elements.messageBox.textContent = '';
                    return;
                }

                var base = 'mt-5 rounded-2xl border p-4 text-sm font-medium';
                if (type === 'success') {
                    elements.messageBox.className = base + ' border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300';
                } else if (type === 'warning') {
                    elements.messageBox.className = base + ' border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300';
                } else {
                    elements.messageBox.className = base + ' border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300';
                }
                elements.messageBox.textContent = text;
            }

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function api(action, payload) {
                var requestBody = Object.assign({ action: action }, payload || {});
                return fetch('ai-data.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(requestBody)
                }).then(function (response) {
                    return response.json().catch(function () {
                        return {
                            success: false,
                            error: 'Invalid server response.'
                        };
                    });
                });
            }

            function statusBadge(status) {
                var normalized = String(status || '').toLowerCase();
                if (!normalized) {
                    normalized = 'pending';
                }
                var className = 'status-pending';
                if (normalized === 'processing') {
                    className = 'status-processing';
                } else if (normalized === 'completed') {
                    className = 'status-completed';
                } else if (normalized === 'failed') {
                    className = 'status-failed';
                }
                return '<span class="status-badge ' + className + '">' + escapeHtml(normalized) + '</span>';
            }

            function populateProjects(projects, selectedProjectId) {
                state.projects = Array.isArray(projects) ? projects : [];
                if (state.projects.length === 0) {
                    state.projectId = null;
                    elements.projectSelect.innerHTML = '<option value="">No projects available</option>';
                    elements.projectSelect.disabled = true;
                    return;
                }

                var selected = Number(selectedProjectId || 0);
                var hasSelected = state.projects.some(function (project) {
                    return Number(project.id || 0) === selected;
                });
                if (!hasSelected) {
                    selected = Number(state.projects[0].id || 0);
                }

                state.projectId = selected;
                elements.projectSelect.disabled = false;
                elements.projectSelect.innerHTML = state.projects.map(function (project) {
                    var id = Number(project.id || 0);
                    var label = escapeHtml((project.name || 'Project') + ' (' + (project.domain || '-') + ')');
                    return '<option value="' + id + '"' + (id === selected ? ' selected' : '') + '>' + label + '</option>';
                }).join('');
            }

            function updateUsage(payload) {
                var usage = payload && payload.usage ? payload.usage : {};
                var used = Number(usage.used || 0);
                var limit = Number(usage.limit || 0);
                var remaining = Number(usage.remaining || 0);

                elements.usageUsed.textContent = used.toLocaleString();
                elements.usageLimit.textContent = limit.toLocaleString();
                elements.usageRemaining.textContent = 'Remaining: ' + remaining.toLocaleString();
                elements.usageMonth.textContent = 'Month: ' + String(usage.month || '-');
            }

            function updateGlobal(payload) {
                var globalInfo = payload && payload.global ? payload.global : {};
                var queue = payload && payload.queue ? payload.queue : {};
                var enabled = !!globalInfo.enabled;
                var concurrency = Number(globalInfo.concurrency_limit || 20);
                var maxInput = Number(globalInfo.max_input_chars || 600);

                elements.globalStatus.textContent = enabled
                    ? 'Enabled • Concurrency ' + concurrency
                    : 'Disabled by admin';
                elements.queueProcessing.textContent = String(Number(queue.processing || 0));
                elements.inputLimitChip.textContent = 'Max input: ' + maxInput + ' chars';
            }

            function renderAdvisor(result) {
                var summary = escapeHtml(result.answer_summary || '');
                var actions = Array.isArray(result.priority_actions) ? result.priority_actions : [];
                var watchouts = Array.isArray(result.watchouts) ? result.watchouts : [];
                var nextSteps = Array.isArray(result.next_steps) ? result.next_steps : [];

                var actionHtml = actions.map(function (action) {
                    var priority = escapeHtml(action.priority || 'Medium');
                    var color = priority === 'High' ? 'text-red-600 dark:text-red-300' : (priority === 'Low' ? 'text-emerald-600 dark:text-emerald-300' : 'text-amber-600 dark:text-amber-300');
                    return '<article class="rounded-xl border border-slate-200 bg-white/80 p-4 dark:border-slate-700 dark:bg-slate-900/70">'
                        + '<div class="mb-2 flex items-center justify-between gap-2">'
                        + '<h4 class="text-sm font-bold text-slate-900 dark:text-slate-100">' + escapeHtml(action.action || 'Action') + '</h4>'
                        + '<span class="text-xs font-bold ' + color + '">' + priority + '</span>'
                        + '</div>'
                        + '<p class="text-xs text-slate-600 dark:text-slate-300"><strong>Reason:</strong> ' + escapeHtml(action.reason || 'Data unavailable') + '</p>'
                        + '<p class="mt-1 text-xs text-slate-600 dark:text-slate-300"><strong>Evidence:</strong> ' + escapeHtml(action.evidence || 'Data unavailable') + '</p>'
                        + '</article>';
                }).join('');

                var watchoutsHtml = watchouts.length === 0 ? '<li>No watchouts.</li>' : watchouts.map(function (item) {
                    return '<li>' + escapeHtml(item) + '</li>';
                }).join('');
                var nextStepsHtml = nextSteps.length === 0 ? '<li>No next steps.</li>' : nextSteps.map(function (item) {
                    return '<li>' + escapeHtml(item) + '</li>';
                }).join('');

                return '<div class="rounded-xl border border-slate-200 bg-white/80 p-4 dark:border-slate-700 dark:bg-slate-900/70">'
                    + '<p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Summary</p>'
                    + '<p class="mt-2 text-sm text-slate-700 dark:text-slate-300">' + summary + '</p>'
                    + '</div>'
                    + '<div class="grid gap-3 md:grid-cols-2">' + actionHtml + '</div>'
                    + '<div class="grid gap-3 md:grid-cols-2">'
                    + '<div class="rounded-xl border border-slate-200 bg-white/80 p-4 dark:border-slate-700 dark:bg-slate-900/70">'
                    + '<p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Watchouts</p>'
                    + '<ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-slate-700 dark:text-slate-300">' + watchoutsHtml + '</ul>'
                    + '</div>'
                    + '<div class="rounded-xl border border-slate-200 bg-white/80 p-4 dark:border-slate-700 dark:bg-slate-900/70">'
                    + '<p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Next Steps</p>'
                    + '<ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-slate-700 dark:text-slate-300">' + nextStepsHtml + '</ul>'
                    + '</div>'
                    + '</div>';
            }

            function renderMeta(result) {
                var notes = Array.isArray(result.notes) ? result.notes : [];
                var notesHtml = notes.length === 0 ? '<li>No notes.</li>' : notes.map(function (note) {
                    return '<li>' + escapeHtml(note) + '</li>';
                }).join('');

                return '<div class="grid gap-3 md:grid-cols-2">'
                    + '<article class="rounded-xl border border-slate-200 bg-white/80 p-4 dark:border-slate-700 dark:bg-slate-900/70">'
                    + '<p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">Optimized Title</p>'
                    + '<p class="mt-2 text-sm font-semibold text-slate-900 dark:text-slate-100">' + escapeHtml(result.optimized_title || '') + '</p>'
                    + '</article>'
                    + '<article class="rounded-xl border border-slate-200 bg-white/80 p-4 dark:border-slate-700 dark:bg-slate-900/70">'
                    + '<p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">CTR Rewrite Title</p>'
                    + '<p class="mt-2 text-sm font-semibold text-slate-900 dark:text-slate-100">' + escapeHtml(result.ctr_rewrite_title || '') + '</p>'
                    + '</article>'
                    + '<article class="rounded-xl border border-slate-200 bg-white/80 p-4 dark:border-slate-700 dark:bg-slate-900/70">'
                    + '<p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">Optimized Meta Description</p>'
                    + '<p class="mt-2 text-sm text-slate-700 dark:text-slate-300">' + escapeHtml(result.optimized_meta_description || '') + '</p>'
                    + '</article>'
                    + '<article class="rounded-xl border border-slate-200 bg-white/80 p-4 dark:border-slate-700 dark:bg-slate-900/70">'
                    + '<p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">CTR Rewrite Description</p>'
                    + '<p class="mt-2 text-sm text-slate-700 dark:text-slate-300">' + escapeHtml(result.ctr_rewrite_description || '') + '</p>'
                    + '</article>'
                    + '</div>'
                    + '<div class="rounded-xl border border-slate-200 bg-white/80 p-4 dark:border-slate-700 dark:bg-slate-900/70">'
                    + '<p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Notes</p>'
                    + '<ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-slate-700 dark:text-slate-300">' + notesHtml + '</ul>'
                    + '</div>';
            }

            function renderOptimizer(result) {
                var headings = Array.isArray(result.missing_headings) ? result.missing_headings : [];
                var gaps = Array.isArray(result.keyword_gaps) ? result.keyword_gaps : [];
                var improvements = Array.isArray(result.content_improvements) ? result.content_improvements : [];
                var quickWins = Array.isArray(result.quick_wins) ? result.quick_wins : [];

                function list(items) {
                    if (!items.length) {
                        return '<li>Data unavailable</li>';
                    }
                    return items.map(function (item) { return '<li>' + escapeHtml(item) + '</li>'; }).join('');
                }

                var improvementsHtml = improvements.length === 0
                    ? '<li>Data unavailable</li>'
                    : improvements.map(function (item) {
                        return '<li><strong>' + escapeHtml(item.item || 'Improvement') + ':</strong> ' + escapeHtml(item.why || 'Data unavailable') + '</li>';
                    }).join('');

                return '<div class="grid gap-3 md:grid-cols-2">'
                    + '<article class="rounded-xl border border-slate-200 bg-white/80 p-4 dark:border-slate-700 dark:bg-slate-900/70">'
                    + '<p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Missing Headings</p>'
                    + '<ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-slate-700 dark:text-slate-300">' + list(headings) + '</ul>'
                    + '</article>'
                    + '<article class="rounded-xl border border-slate-200 bg-white/80 p-4 dark:border-slate-700 dark:bg-slate-900/70">'
                    + '<p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Keyword Gaps</p>'
                    + '<ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-slate-700 dark:text-slate-300">' + list(gaps) + '</ul>'
                    + '</article>'
                    + '</div>'
                    + '<article class="rounded-xl border border-slate-200 bg-white/80 p-4 dark:border-slate-700 dark:bg-slate-900/70">'
                    + '<p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Content Improvements</p>'
                    + '<ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-slate-700 dark:text-slate-300">' + improvementsHtml + '</ul>'
                    + '</article>'
                    + '<article class="rounded-xl border border-slate-200 bg-white/80 p-4 dark:border-slate-700 dark:bg-slate-900/70">'
                    + '<p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Quick Wins</p>'
                    + '<ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-slate-700 dark:text-slate-300">' + list(quickWins) + '</ul>'
                    + '</article>';
            }

            function renderResultPayload(payload) {
                if (!payload || typeof payload !== 'object') {
                    return;
                }

                state.lastResult = payload;
                elements.resultCard.classList.remove('hidden');
                elements.queueCard.classList.add('hidden');

                var module = String(payload.module || 'advisor');
                var meta = payload.meta || {};
                var generatedAt = payload.generated_at || '';
                var tokens = Number(meta.tokens_used || 0).toLocaleString();
                var cost = Number(meta.cost_estimate_inr || 0).toFixed(4);
                var model = String(meta.model || '-');

                elements.resultMeta.textContent = 'Generated: ' + (generatedAt || '-') + ' • Model: ' + model + ' • Tokens: ' + tokens + ' • Cost: ₹' + cost;

                var answer = payload.answer || {};
                if (module === 'meta') {
                    elements.resultBody.innerHTML = renderMeta(answer);
                } else if (module === 'optimizer') {
                    elements.resultBody.innerHTML = renderOptimizer(answer);
                } else {
                    elements.resultBody.innerHTML = renderAdvisor(answer);
                }
            }

            function getResultText(payload) {
                if (!payload || typeof payload !== 'object') {
                    return '';
                }
                var lines = [];
                lines.push('AI SEO Result');
                lines.push('Module: ' + String(payload.module || 'advisor'));
                lines.push('Generated: ' + String(payload.generated_at || '-'));

                var project = payload.project || {};
                lines.push('Project: ' + String(project.name || '-') + ' (' + String(project.domain || '-') + ')');

                var meta = payload.meta || {};
                lines.push('Model: ' + String(meta.model || '-'));
                lines.push('Tokens: ' + String(meta.tokens_used || 0));
                lines.push('Cost (INR): ₹' + Number(meta.cost_estimate_inr || 0).toFixed(4));
                lines.push('');

                lines.push(JSON.stringify(payload.answer || {}, null, 2));
                return lines.join('\n');
            }

            function renderHistory(rows) {
                rows = Array.isArray(rows) ? rows : [];
                if (!rows.length) {
                    elements.historyBody.innerHTML = '<tr><td colspan="6" class="px-3 py-4 text-center text-slate-500">No AI requests yet.</td></tr>';
                    return;
                }

                elements.historyBody.innerHTML = rows.map(function (row) {
                    var id = Number(row.id || 0);
                    var type = escapeHtml(String(row.request_type || 'advisor'));
                    var status = String(row.status || 'pending');
                    var createdAt = escapeHtml(String(row.created_at || '-'));
                    var tokens = Number(row.tokens_used || 0).toLocaleString();
                    var cost = Number(row.cost_estimate || 0).toFixed(4);
                    var canView = status === 'completed' && row.response_payload;

                    var actionButton = canView
                        ? '<button class="view-result-btn rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800" data-request-id="' + id + '">View</button>'
                        : '<span class="text-xs text-slate-400">-</span>';

                    return '<tr class="border-t border-slate-200 dark:border-slate-700">'
                        + '<td class="px-3 py-2 text-slate-700 dark:text-slate-200">' + type + '</td>'
                        + '<td class="px-3 py-2">' + statusBadge(status) + '</td>'
                        + '<td class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">' + createdAt + '</td>'
                        + '<td class="px-3 py-2 text-xs text-slate-600 dark:text-slate-300">' + tokens + '</td>'
                        + '<td class="px-3 py-2 text-xs text-slate-600 dark:text-slate-300">₹' + cost + '</td>'
                        + '<td class="px-3 py-2">' + actionButton + '</td>'
                        + '</tr>';
                }).join('');

                elements.historyBody.querySelectorAll('.view-result-btn').forEach(function (button) {
                    button.addEventListener('click', function () {
                        var requestId = Number(button.getAttribute('data-request-id') || 0);
                        if (!requestId) {
                            return;
                        }

                        var row = rows.find(function (item) {
                            return Number(item.id || 0) === requestId;
                        });
                        if (row && row.response_payload) {
                            renderResultPayload(row.response_payload);
                            window.scrollTo({ top: elements.resultCard.offsetTop - 90, behavior: 'smooth' });
                        }
                    });
                });
            }

            function switchModule(nextModule) {
                state.module = nextModule;
                elements.moduleButtons.forEach(function (button) {
                    var isActive = button.getAttribute('data-module') === nextModule;
                    button.classList.toggle('active', isActive);
                });

                elements.modulePanels.forEach(function (panel) {
                    var panelModule = panel.id.replace('module-', '');
                    panel.classList.toggle('hidden', panelModule !== nextModule);
                });
            }

            function collectPayload() {
                var payload = {
                    request_type: state.module,
                    project_id: state.projectId || null
                };

                if (state.module === 'meta') {
                    payload.target_keyword = document.getElementById('meta-keyword').value || '';
                    payload.page_url = document.getElementById('meta-page-url').value || '';
                    payload.current_title = document.getElementById('meta-current-title').value || '';
                    payload.current_meta_description = document.getElementById('meta-current-description').value || '';
                } else if (state.module === 'optimizer') {
                    payload.target_keyword = document.getElementById('optimizer-keyword').value || '';
                    payload.page_url = document.getElementById('optimizer-page-url').value || '';
                    payload.current_headings = document.getElementById('optimizer-headings').value || '';
                    payload.content_summary = document.getElementById('optimizer-summary').value || '';
                } else {
                    payload.question = document.getElementById('advisor-question').value || '';
                }

                return payload;
            }

            function stopPolling() {
                if (state.pollTimer) {
                    clearInterval(state.pollTimer);
                    state.pollTimer = null;
                }
            }

            function startPolling(requestId) {
                stopPolling();
                state.activeRequestId = requestId;

                state.pollTimer = setInterval(function () {
                    api('status', { request_id: requestId }).then(function (response) {
                        if (!response || response.success === false) {
                            return;
                        }

                        var request = response.request || {};
                        var status = String(request.status || 'pending');
                        if (status === 'pending') {
                            elements.queueCard.classList.remove('hidden');
                            elements.queuePositionBadge.textContent = 'Position #' + String(Number(response.queue_position || 1));
                            return;
                        }

                        if (status === 'processing') {
                            elements.queueCard.classList.remove('hidden');
                            elements.queuePositionBadge.textContent = 'Processing...';
                            return;
                        }

                        if (status === 'failed') {
                            stopPolling();
                            setLoading(false);
                            var errorText = String(request.error_message || 'AI request failed.');
                            showMessage('error', errorText);
                            loadData(state.projectId);
                            return;
                        }

                        if (status === 'completed') {
                            stopPolling();
                            setLoading(false);
                            if (request.response_payload) {
                                renderResultPayload(request.response_payload);
                            }
                            showMessage('success', 'AI result is ready.');
                            loadData(state.projectId);
                        }
                    });
                }, 3500);
            }

            function loadData(projectId) {
                return api('load', { project_id: projectId || null }).then(function (response) {
                    if (!response || response.success === false) {
                        showMessage('error', response && response.error ? response.error : 'Failed to load AI data.');
                        return;
                    }

                    populateProjects(response.projects || [], response.selected_project_id || null);
                    updateUsage(response);
                    updateGlobal(response);
                    renderHistory(response.history || []);

                    var globalInfo = response.global || {};
                    if (!globalInfo.enabled) {
                        showMessage('warning', 'AI is currently disabled by administrator settings.');
                    } else {
                        showMessage('', '');
                    }
                }).catch(function () {
                    showMessage('error', 'Unable to load AI data right now.');
                });
            }

            elements.moduleButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    switchModule(button.getAttribute('data-module') || 'advisor');
                });
            });

            elements.projectSelect.addEventListener('change', function () {
                state.projectId = Number(elements.projectSelect.value || 0) || null;
                loadData(state.projectId);
            });

            elements.submitButton.addEventListener('click', function () {
                if (!state.projectId) {
                    showMessage('error', 'Select a project first.');
                    return;
                }

                var payload = collectPayload();
                setLoading(true);
                showMessage('', '');

                api('submit', payload).then(function (response) {
                    if (!response || response.success === false) {
                        setLoading(false);
                        var message = response && response.error ? response.error : 'AI request failed.';
                        showMessage('error', message);
                        if (response && response.usage) {
                            updateUsage(response);
                        }
                        loadData(state.projectId);
                        return;
                    }

                    if (response.usage) {
                        updateUsage(response);
                    }

                    var requestId = Number(response.request_id || 0);
                    if (response.queued) {
                        elements.queueCard.classList.remove('hidden');
                        elements.queuePositionBadge.textContent = 'Position #' + String(Number(response.queue_position || 1));
                        showMessage('warning', response.message || 'AI is busy. Your request is queued.');
                        startPolling(requestId);
                        return;
                    }

                    setLoading(false);
                    if (response.result) {
                        renderResultPayload(response.result);
                    }
                    showMessage('success', 'AI suggestion generated successfully.');
                    loadData(state.projectId);
                }).catch(function () {
                    setLoading(false);
                    showMessage('error', 'Unable to submit AI request right now.');
                });
            });

            elements.refreshHistoryButton.addEventListener('click', function () {
                loadData(state.projectId);
            });

            elements.copyResultButton.addEventListener('click', function () {
                var text = getResultText(state.lastResult);
                if (!text) {
                    showMessage('warning', 'No result to copy yet.');
                    return;
                }

                navigator.clipboard.writeText(text).then(function () {
                    showMessage('success', 'Result copied to clipboard.');
                }).catch(function () {
                    showMessage('error', 'Copy failed.');
                });
            });

            elements.downloadResultButton.addEventListener('click', function () {
                var text = getResultText(state.lastResult);
                if (!text) {
                    showMessage('warning', 'No result to download yet.');
                    return;
                }

                var blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
                var url = URL.createObjectURL(blob);
                var link = document.createElement('a');
                link.href = url;
                link.download = 'ai-seo-result-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.txt';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
                showMessage('success', 'Result file downloaded.');
            });

            switchModule('advisor');
            loadData(null);

            window.addEventListener('beforeunload', stopPolling);
        })();
    </script>
</body>
</html>
