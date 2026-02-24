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

require_once __DIR__ . '/../services/SearchConsoleService.php';
require_once __DIR__ . '/../services/GoogleAuthService.php';

$searchConsoleService = new SearchConsoleService();
$googleAuthService = new GoogleAuthService();
$disconnectCsrf = $googleAuthService->createFormCsrfToken('gsc_disconnect_csrf');
$projectContext = $searchConsoleService->getProjectsAndConnections($userId);
$projects = (array) ($projectContext['projects'] ?? []);
$gscConnectionMap = (array) ($projectContext['connection_map'] ?? []);

$gscFlash = $_SESSION['gsc_flash'] ?? null;
unset($_SESSION['gsc_flash']);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - SEO Audit SaaS</title>
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

        .settings-block {
            transition: transform 160ms ease, box-shadow 160ms ease;
        }

        .settings-block:hover {
            transform: translateY(-2px);
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
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Workspace Settings</p>
                        <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Settings</h1>
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

                    <span class="rounded-xl px-3 py-2 text-xs font-bold tracking-wide <?php echo $planType === 'pro' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'; ?>">
                        <?php echo htmlspecialchars($planLabel); ?> Plan
                    </span>

                    <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-3 py-2 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-400 text-sm font-bold text-white">
                            <?php echo htmlspecialchars(strtoupper(substr($userName, 0, 1))); ?>
                        </div>
                        <div class="hidden sm:block">
                            <p class="text-xs text-slate-500 dark:text-slate-400">Account</p>
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($userName); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="space-y-6 px-4 pb-10 pt-6 sm:px-6 lg:px-10">
            <section class="surface-card p-6 shadow-soft sm:p-8">
                <h2 class="text-xl font-bold text-slate-900 dark:text-slate-100">Account Overview</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="settings-block rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Name</p>
                        <p class="mt-2 text-sm font-bold text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($userName); ?></p>
                    </article>
                    <article class="settings-block rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">User ID</p>
                        <p class="mt-2 text-sm font-bold text-slate-900 dark:text-slate-100">#<?php echo $userId; ?></p>
                    </article>
                    <article class="settings-block rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Plan</p>
                        <p class="mt-2 text-sm font-bold text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($planLabel); ?></p>
                    </article>
                    <article class="settings-block rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Theme</p>
                        <p class="mt-2 text-sm font-bold text-slate-900 dark:text-slate-100">System + Toggle</p>
                    </article>
                </div>
            </section>

            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">Google Search Console - Project Settings</h3>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Connect each project with its own Search Console property for real performance data.</p>
                    </div>
                    <a href="performance.php" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                        Open Performance ->
                    </a>
                </div>

                <?php if (is_array($gscFlash)): ?>
                    <?php
                        $flashType = (string) ($gscFlash['type'] ?? 'error');
                        $flashClasses = $flashType === 'success'
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300'
                            : 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300';
                    ?>
                    <div class="mt-4 rounded-xl border px-4 py-3 text-sm font-semibold <?php echo $flashClasses; ?>">
                        <?php echo htmlspecialchars((string) ($gscFlash['message'] ?? '')); ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($projects)): ?>
                    <div class="mt-4 rounded-xl border border-dashed border-slate-300 px-4 py-5 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                        No projects found. Run an audit or add tracked keywords to create projects first.
                    </div>
                <?php else: ?>
                    <div class="mt-5 overflow-x-auto">
                        <table class="w-full min-w-[760px] text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-[0.12em] text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                    <th class="pb-3 pr-2">Project</th>
                                    <th class="pb-3 pr-2">Domain</th>
                                    <th class="pb-3 pr-2">Status</th>
                                    <th class="pb-3 pr-2">Connected Property</th>
                                    <th class="pb-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($projects as $project): ?>
                                    <?php
                                        $pid = (int) ($project['id'] ?? 0);
                                        $connection = $gscConnectionMap[$pid] ?? null;
                                        $connected = is_array($connection);
                                    ?>
                                    <tr class="border-b border-slate-100 dark:border-slate-800">
                                        <td class="py-3 pr-2 font-semibold text-slate-800 dark:text-slate-100"><?php echo htmlspecialchars((string) ($project['name'] ?? 'Project')); ?></td>
                                        <td class="py-3 pr-2 text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars((string) ($project['domain'] ?? '')); ?></td>
                                        <td class="py-3 pr-2">
                                            <span class="rounded-lg px-2 py-1 text-xs font-bold <?php echo $connected ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200'; ?>">
                                                <?php echo $connected ? 'Connected' : 'Not Connected'; ?>
                                            </span>
                                        </td>
                                        <td class="py-3 pr-2 text-xs text-slate-500 dark:text-slate-400">
                                            <?php echo $connected ? htmlspecialchars((string) ($connection['google_property'] ?? '')) : '-'; ?>
                                        </td>
                                        <td class="py-3">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <?php if (!$connected): ?>
                                                    <a href="connect-gsc.php?project_id=<?php echo $pid; ?>&return_to=<?php echo urlencode('settings.php'); ?>" class="rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-3 py-2 text-xs font-semibold text-white shadow-soft">
                                                        Connect GSC
                                                    </a>
                                                <?php else: ?>
                                                    <form method="post" action="connect-gsc.php" onsubmit="return confirm('Disconnect Search Console from this project?');">
                                                        <input type="hidden" name="action" value="disconnect">
                                                        <input type="hidden" name="project_id" value="<?php echo $pid; ?>">
                                                        <input type="hidden" name="return_to" value="settings.php">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($disconnectCsrf); ?>">
                                                        <button type="submit" class="rounded-xl border border-red-300 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-100 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                                                            Disconnect
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <a href="performance.php?project_id=<?php echo $pid; ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                                                    View Data
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="grid gap-6 xl:grid-cols-2">
                <article class="surface-card p-6 shadow-soft sm:p-8">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">Interface Preferences</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Use dark/light mode toggle from the top bar. Preference is saved locally in this browser.</p>
                    <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Current visual style</p>
                        <p id="theme-state" class="mt-1 text-sm text-slate-500 dark:text-slate-400">Detecting...</p>
                    </div>
                </article>

                <article class="surface-card p-6 shadow-soft sm:p-8">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">Workflow Shortcuts</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Fast actions for your day-to-day SEO workflow.</p>
                    <div class="mt-5 grid gap-3">
                        <a href="index.php#run-audit" class="inline-flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                            <span>Run New Audit</span>
                            <span>-></span>
                        </a>
                        <a href="history.php" class="inline-flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                            <span>Open Audit History</span>
                            <span>-></span>
                        </a>
                        <a href="keyword.php" class="inline-flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                            <span>Go to Keyword Lab</span>
                            <span>-></span>
                        </a>
                    </div>
                </article>
            </section>
        </main>
    </div>

    <script>
        (function () {
            var sidebar = document.getElementById('app-sidebar');
            var overlay = document.getElementById('sidebar-overlay');
            var openButton = document.getElementById('sidebar-open');
            var themeButton = document.getElementById('theme-toggle');
            var themeState = document.getElementById('theme-state');

            function openSidebar() {
                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('hidden');
            }

            function closeSidebar() {
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
            }

            function refreshThemeLabel() {
                if (!themeState) {
                    return;
                }
                themeState.textContent = document.documentElement.classList.contains('dark')
                    ? 'Dark mode active'
                    : 'Light mode active';
            }

            openButton && openButton.addEventListener('click', openSidebar);
            overlay && overlay.addEventListener('click', closeSidebar);

            themeButton && themeButton.addEventListener('click', function () {
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('seo-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
                refreshThemeLabel();
            });

            refreshThemeLabel();
        })();
    </script>
</body>
</html>
