<?php
session_start();
require_once __DIR__ . '/../services/PlanEnforcementService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';
require_once __DIR__ . '/../middleware/CsrfMiddleware.php';

$auth = AuthMiddleware::requireLogin(false);
AccountStatusMiddleware::ensureActive(false);

$userId = (int) ($auth['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$planService = new PlanEnforcementService();
$userName = (string) ($auth['user_name'] ?? ($_SESSION['user_name'] ?? 'User'));
$planType = $planService->getEffectivePlan($userId, (string) ($_SESSION['plan_type'] ?? 'free'));
$planLabel = ucfirst($planType);
$isPro = $planType === 'pro';
$isAgency = $planType === 'agency';
$isPaid = in_array($planType, ['pro', 'agency'], true);
$billingFlashError = (string) ($_SESSION['billing_flash_error'] ?? '');
if ($billingFlashError !== '') {
    unset($_SESSION['billing_flash_error']);
}
$billingStartProMonthlyHref = 'billing/start.php?plan=pro&cycle=monthly&token=' . urlencode(CsrfMiddleware::generateToken('billing_start_pro_monthly'));
$billingStartProAnnualHref = 'billing/start.php?plan=pro&cycle=annual&token=' . urlencode(CsrfMiddleware::generateToken('billing_start_pro_annual'));
$billingStartAgencyMonthlyHref = 'billing/start.php?plan=agency&cycle=monthly&token=' . urlencode(CsrfMiddleware::generateToken('billing_start_agency_monthly'));
$billingStartAgencyAnnualHref = 'billing/start.php?plan=agency&cycle=annual&token=' . urlencode(CsrfMiddleware::generateToken('billing_start_agency_annual'));
$proMonthlyActionLabel = $isAgency ? 'Switch to Pro Monthly' : 'Upgrade Pro Monthly';
$proAnnualActionLabel = $isAgency ? 'Switch to Pro Annual' : 'Upgrade Pro Annual';
$agencyMonthlyActionLabel = 'Upgrade Agency Monthly';
$agencyAnnualActionLabel = 'Upgrade Agency Annual';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription - SEO Audit SaaS</title>
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

        .plan-card {
            transition: transform 160ms ease, box-shadow 160ms ease;
        }

        .plan-card:hover {
            transform: translateY(-3px);
        }

        .billing-cycle-btn {
            border-radius: 0.75rem;
            border: 1px solid transparent;
            padding: 0.45rem 0.9rem;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgb(100 116 139);
            transition: all 0.18s ease;
        }

        .billing-cycle-btn:hover {
            color: rgb(30 41 59);
        }

        .dark .billing-cycle-btn:hover {
            color: rgb(226 232 240);
        }

        .billing-cycle-btn.is-active {
            border-color: rgba(99, 102, 241, 0.4);
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.95), rgba(99, 102, 241, 0.95));
            color: #fff;
            box-shadow: 0 16px 30px -22px rgba(79, 70, 229, 0.85);
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
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Billing Center</p>
                        <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Subscription</h1>
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

                    <span class="rounded-xl px-3 py-2 text-xs font-bold tracking-wide <?php echo $isPaid ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'; ?>">
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
            <?php if ($billingFlashError !== ''): ?>
                <section class="rounded-2xl border border-red-300/35 bg-red-500/10 px-5 py-4 text-sm text-red-200">
                    <?php echo htmlspecialchars($billingFlashError); ?>
                </section>
            <?php endif; ?>

            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 p-5 text-white shadow-soft md:col-span-2">
                        <p class="text-xs uppercase tracking-[0.2em] text-indigo-100">Current Plan</p>
                        <p class="mt-2 text-2xl font-extrabold"><?php echo htmlspecialchars($planLabel); ?></p>
                        <p class="mt-2 text-xs text-indigo-100"><?php echo $isAgency ? 'All Agency features are active, including crawler and white-label reports.' : ($isPro ? 'Unlimited audits and full keyword lab are active.' : 'Free tier with limited daily audits and keyword lock.'); ?></p>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Keyword Lab</p>
                        <p class="mt-2 text-lg font-bold <?php echo $isPaid ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'; ?>"><?php echo $isPaid ? 'Unlocked' : 'Locked'; ?></p>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Audit Limits</p>
                        <p class="mt-2 text-lg font-bold text-slate-900 dark:text-slate-100"><?php echo $isPaid ? 'Unlimited' : 'Daily cap'; ?></p>
                    </article>
                </div>
            </section>

            <section class="surface-card p-5 shadow-soft sm:p-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Billing Cycle</p>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Switch monthly or annual checkout without leaving this page.</p>
                    </div>
                    <div id="subscription-cycle-toggle" class="inline-flex items-center gap-1 rounded-2xl border border-slate-200 bg-slate-100/80 p-1 dark:border-slate-700 dark:bg-slate-900/70">
                        <button type="button" class="billing-cycle-btn is-active" data-cycle="monthly">Monthly</button>
                        <button type="button" class="billing-cycle-btn" data-cycle="annual">Annual</button>
                    </div>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-3">
                <article class="plan-card surface-card p-6 shadow-soft sm:p-8 <?php echo !$isPaid ? 'ring-2 ring-brand-500/60' : ''; ?>">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Starter</p>
                            <h2 class="mt-2 text-2xl font-extrabold text-slate-900 dark:text-slate-100">Free Plan</h2>
                            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Great for early audits and testing.</p>
                        </div>
                        <?php if (!$isPaid): ?>
                            <span class="rounded-full bg-brand-100 px-3 py-1 text-xs font-semibold text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">Current</span>
                        <?php endif; ?>
                    </div>
                    <ul class="mt-5 space-y-2 text-sm text-slate-600 dark:text-slate-300">
                        <li>- Basic SEO audits</li>
                        <li>- Limited daily usage</li>
                        <li>- No keyword research lab</li>
                    </ul>
                </article>

                <article class="plan-card surface-card p-6 shadow-soft sm:p-8 <?php echo $isPro ? 'ring-2 ring-emerald-500/60' : ''; ?>">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Growth</p>
                            <h2 class="mt-2 text-2xl font-extrabold text-slate-900 dark:text-slate-100">Pro Plan</h2>
                            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">For teams that need full SEO workflow coverage.</p>
                            <p class="mt-3 text-3xl font-extrabold text-slate-900 dark:text-slate-100"><span id="subscription-pro-price" data-monthly="<?php echo htmlspecialchars(format_inr(999, 0), ENT_QUOTES, 'UTF-8'); ?>" data-annual="<?php echo htmlspecialchars(format_inr(9990, 0), ENT_QUOTES, 'UTF-8'); ?>"><?php echo format_inr(999, 0); ?></span><span id="subscription-pro-period" class="ml-1 text-base font-semibold text-slate-500 dark:text-slate-300">/mo</span></p>
                        </div>
                        <?php if ($isPro): ?>
                            <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">Current</span>
                        <?php endif; ?>
                    </div>
                    <ul class="mt-5 space-y-2 text-sm text-slate-600 dark:text-slate-300">
                        <li>- Unlimited audits</li>
                        <li>- Keyword research lab access</li>
                        <li>- Priority workflow for optimization</li>
                    </ul>
                    <?php if (!$isPro): ?>
                        <a id="subscription-pro-cta" href="<?php echo htmlspecialchars($billingStartProMonthlyHref, ENT_QUOTES, 'UTF-8'); ?>" data-monthly-href="<?php echo htmlspecialchars($billingStartProMonthlyHref, ENT_QUOTES, 'UTF-8'); ?>" data-annual-href="<?php echo htmlspecialchars($billingStartProAnnualHref, ENT_QUOTES, 'UTF-8'); ?>" data-monthly-label="<?php echo htmlspecialchars($proMonthlyActionLabel, ENT_QUOTES, 'UTF-8'); ?>" data-annual-label="<?php echo htmlspecialchars($proAnnualActionLabel, ENT_QUOTES, 'UTF-8'); ?>" class="mt-6 inline-flex w-full justify-center rounded-xl bg-gradient-to-r from-emerald-500 to-emerald-400 px-4 py-2.5 text-sm font-semibold text-white transition hover:brightness-110">
                            <?php echo htmlspecialchars($proMonthlyActionLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php endif; ?>
                </article>

                <article class="plan-card surface-card p-6 shadow-soft sm:p-8 <?php echo $isAgency ? 'ring-2 ring-indigo-500/60' : ''; ?>">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Agency</p>
                            <h2 class="mt-2 text-2xl font-extrabold text-slate-900 dark:text-slate-100">Agency Plan</h2>
                            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">For teams delivering client SEO services.</p>
                            <p class="mt-3 text-3xl font-extrabold text-slate-900 dark:text-slate-100"><span id="subscription-agency-price" data-monthly="<?php echo htmlspecialchars(format_inr(2999, 0), ENT_QUOTES, 'UTF-8'); ?>" data-annual="<?php echo htmlspecialchars(format_inr(29990, 0), ENT_QUOTES, 'UTF-8'); ?>"><?php echo format_inr(2999, 0); ?></span><span id="subscription-agency-period" class="ml-1 text-base font-semibold text-slate-500 dark:text-slate-300">/mo</span></p>
                        </div>
                        <?php if ($isAgency): ?>
                            <span class="rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">Current</span>
                        <?php endif; ?>
                    </div>
                    <ul class="mt-5 space-y-2 text-sm text-slate-600 dark:text-slate-300">
                        <li>- Backlink overview module</li>
                        <li>- Multi-page technical crawler</li>
                        <li>- White-label PDF reports</li>
                    </ul>
                    <?php if (!$isAgency): ?>
                        <a id="subscription-agency-cta" href="<?php echo htmlspecialchars($billingStartAgencyMonthlyHref, ENT_QUOTES, 'UTF-8'); ?>" data-monthly-href="<?php echo htmlspecialchars($billingStartAgencyMonthlyHref, ENT_QUOTES, 'UTF-8'); ?>" data-annual-href="<?php echo htmlspecialchars($billingStartAgencyAnnualHref, ENT_QUOTES, 'UTF-8'); ?>" data-monthly-label="<?php echo htmlspecialchars($agencyMonthlyActionLabel, ENT_QUOTES, 'UTF-8'); ?>" data-annual-label="<?php echo htmlspecialchars($agencyAnnualActionLabel, ENT_QUOTES, 'UTF-8'); ?>" class="mt-6 inline-flex w-full justify-center rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-4 py-2.5 text-sm font-semibold text-white transition hover:brightness-110">
                            <?php echo htmlspecialchars($agencyMonthlyActionLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php endif; ?>
                </article>
            </section>

            <section class="surface-card p-6 shadow-soft sm:p-8">
                <h3 class="text-xl font-bold text-slate-900 dark:text-slate-100">Secure Billing</h3>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Plan changes are now handled through the billing API and verified webhooks. Manual simulation is disabled in production.</p>
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Admin users can still run controlled local testing in development mode only.</p>
            </section>
        </main>
    </div>

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

            var cycleRoot = document.getElementById('subscription-cycle-toggle');
            if (!cycleRoot) {
                return;
            }

            var cycleButtons = cycleRoot.querySelectorAll('[data-cycle]');
            var proPrice = document.getElementById('subscription-pro-price');
            var proPeriod = document.getElementById('subscription-pro-period');
            var proCta = document.getElementById('subscription-pro-cta');
            var agencyPrice = document.getElementById('subscription-agency-price');
            var agencyPeriod = document.getElementById('subscription-agency-period');
            var agencyCta = document.getElementById('subscription-agency-cta');

            function setBillingCycle(cycle) {
                var useAnnual = cycle === 'annual';
                cycleButtons.forEach(function (button) {
                    var active = String(button.getAttribute('data-cycle') || '') === cycle;
                    button.classList.toggle('is-active', active);
                });

                if (proPrice) {
                    proPrice.textContent = useAnnual ? String(proPrice.dataset.annual || '') : String(proPrice.dataset.monthly || '');
                }
                if (proPeriod) {
                    proPeriod.textContent = useAnnual ? '/yr' : '/mo';
                }
                if (proCta) {
                    proCta.setAttribute('href', useAnnual ? String(proCta.dataset.annualHref || '#') : String(proCta.dataset.monthlyHref || '#'));
                    proCta.textContent = useAnnual ? String(proCta.dataset.annualLabel || '') : String(proCta.dataset.monthlyLabel || '');
                }

                if (agencyPrice) {
                    agencyPrice.textContent = useAnnual ? String(agencyPrice.dataset.annual || '') : String(agencyPrice.dataset.monthly || '');
                }
                if (agencyPeriod) {
                    agencyPeriod.textContent = useAnnual ? '/yr' : '/mo';
                }
                if (agencyCta) {
                    agencyCta.setAttribute('href', useAnnual ? String(agencyCta.dataset.annualHref || '#') : String(agencyCta.dataset.monthlyHref || '#'));
                    agencyCta.textContent = useAnnual ? String(agencyCta.dataset.annualLabel || '') : String(agencyCta.dataset.monthlyLabel || '');
                }
            }

            cycleButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    setBillingCycle(String(button.getAttribute('data-cycle') || 'monthly'));
                });
            });

            setBillingCycle('monthly');
        })();
    </script>
</body>
</html>
