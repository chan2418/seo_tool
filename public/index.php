<?php
session_start();

require_once __DIR__ . '/../utils/CurrencyFormatter.php';
require_once __DIR__ . '/../middleware/CsrfMiddleware.php';

$isLoggedIn = isset($_SESSION['user_id']);
$userName = (string) ($_SESSION['user_name'] ?? 'User');
$primaryHref = $isLoggedIn ? 'dashboard.php' : 'register.php';
$heroPrimaryHref = $isLoggedIn ? '#run-audit' : 'register.php';
$heroPrimaryLabel = $isLoggedIn ? 'Run New Audit' : 'Start Free';
$availableProjects = [];

if ($isLoggedIn) {
    require_once __DIR__ . '/../models/TrackedKeywordModel.php';
    try {
        $trackedKeywordModel = new TrackedKeywordModel();
        $availableProjects = $trackedKeywordModel->getProjects((int) ($_SESSION['user_id'] ?? 0));
    } catch (Throwable $error) {
        $availableProjects = [];
        error_log('Landing audit projects load failed: ' . $error->getMessage());
    }
}

$pricingFreeMonthly = 0.0;
$pricingProMonthly = 999.0;
$pricingAgencyMonthly = 2999.0;
$pricingProAnnual = 9990.0;
$pricingAgencyAnnual = 29990.0;

$demoBudgetOptions = [
    'under_25k' => 'Under Rs 25,000 / month',
    '25k_75k' => 'Rs 25,000 - Rs 75,000 / month',
    '75k_2l' => 'Rs 75,000 - Rs 2,00,000 / month',
    '2l_plus' => 'Above Rs 2,00,000 / month',
];

$demoForm = [
    'full_name' => '',
    'email' => '',
    'company_name' => '',
    'website_url' => '',
    'seo_budget' => '',
    'message' => '',
];
$demoErrors = [];
$demoSuccess = '';
$demoFlashKey = 'landing_demo_flash';

if (!empty($_SESSION[$demoFlashKey])) {
    $demoSuccess = (string) $_SESSION[$demoFlashKey];
    unset($_SESSION[$demoFlashKey]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['form_action'] ?? '') === 'request_demo') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!CsrfMiddleware::validateToken($token, 'landing_demo_csrf')) {
        $demoErrors[] = 'Security validation failed. Please submit the form again.';
    }

    $demoForm['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
    $demoForm['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
    $demoForm['company_name'] = trim((string) ($_POST['company_name'] ?? ''));
    $demoForm['website_url'] = trim((string) ($_POST['website_url'] ?? ''));
    $demoForm['seo_budget'] = trim((string) ($_POST['seo_budget'] ?? ''));
    $demoForm['message'] = trim((string) ($_POST['message'] ?? ''));

    $demoForm['full_name'] = preg_replace('/\s+/', ' ', $demoForm['full_name']) ?? '';
    $demoForm['company_name'] = preg_replace('/\s+/', ' ', $demoForm['company_name']) ?? '';

    if ($demoForm['full_name'] === '' || mb_strlen($demoForm['full_name']) < 2 || mb_strlen($demoForm['full_name']) > 120) {
        $demoErrors[] = 'Enter a valid full name (2-120 characters).';
    }

    if (!filter_var($demoForm['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($demoForm['email']) > 255) {
        $demoErrors[] = 'Enter a valid email address.';
    }

    if ($demoForm['company_name'] === '' || mb_strlen($demoForm['company_name']) > 140) {
        $demoErrors[] = 'Enter a valid company name (max 140 characters).';
    }

    if ($demoForm['website_url'] === '' || mb_strlen($demoForm['website_url']) > 255) {
        $demoErrors[] = 'Enter your website URL.';
    } else {
        if (!preg_match('/^https?:\/\//i', $demoForm['website_url'])) {
            $demoForm['website_url'] = 'https://' . $demoForm['website_url'];
        }
        if (!filter_var($demoForm['website_url'], FILTER_VALIDATE_URL)) {
            $demoErrors[] = 'Enter a valid website URL (example: https://example.com).';
        }
    }

    if (!array_key_exists($demoForm['seo_budget'], $demoBudgetOptions)) {
        $demoErrors[] = 'Select a valid SEO budget range.';
    }

    if ($demoForm['message'] === '' || mb_strlen($demoForm['message']) < 10 || mb_strlen($demoForm['message']) > 2000) {
        $demoErrors[] = 'Message should be between 10 and 2000 characters.';
    }

    if (empty($demoErrors)) {
        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            $demoErrors[] = 'Unable to process request right now. Please try again in a few minutes.';
        } else {
            $payload = [
                'submitted_at' => date('c'),
                'full_name' => $demoForm['full_name'],
                'email' => $demoForm['email'],
                'company_name' => $demoForm['company_name'],
                'website_url' => $demoForm['website_url'],
                'seo_budget' => $demoForm['seo_budget'],
                'seo_budget_label' => $demoBudgetOptions[$demoForm['seo_budget']] ?? '',
                'message' => $demoForm['message'],
                'ip_address' => (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
                'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ];

            $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (!is_string($line) || $line === '' || file_put_contents($storageDir . '/demo_requests.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
                $demoErrors[] = 'Unable to save your request. Please try again shortly.';
            } else {
                $_SESSION[$demoFlashKey] = 'Thanks. Your demo request has been received. Our team will contact you shortly.';
                header('Location: index.php#request-demo');
                exit;
            }
        }
    }
}

$demoCsrfToken = CsrfMiddleware::generateToken('landing_demo_csrf');

$pricingFreeHref = $isLoggedIn ? 'dashboard.php' : 'register.php';
$pricingFreeLabel = $isLoggedIn ? 'Go to Dashboard' : 'Start Free';

$pricingProMonthlyCheckoutPath = 'billing/start.php?plan=pro&cycle=monthly&token=' . urlencode(CsrfMiddleware::generateToken('billing_start_pro_monthly'));
$pricingProAnnualCheckoutPath = 'billing/start.php?plan=pro&cycle=annual&token=' . urlencode(CsrfMiddleware::generateToken('billing_start_pro_annual'));
$pricingAgencyMonthlyCheckoutPath = 'billing/start.php?plan=agency&cycle=monthly&token=' . urlencode(CsrfMiddleware::generateToken('billing_start_agency_monthly'));
$pricingAgencyAnnualCheckoutPath = 'billing/start.php?plan=agency&cycle=annual&token=' . urlencode(CsrfMiddleware::generateToken('billing_start_agency_annual'));

$pricingProMonthlyHref = $isLoggedIn ? $pricingProMonthlyCheckoutPath : 'register.php?next=' . urlencode($pricingProMonthlyCheckoutPath);
$pricingProAnnualHref = $isLoggedIn ? $pricingProAnnualCheckoutPath : 'register.php?next=' . urlencode($pricingProAnnualCheckoutPath);
$pricingAgencyMonthlyHref = $isLoggedIn ? $pricingAgencyMonthlyCheckoutPath : 'register.php?next=' . urlencode($pricingAgencyMonthlyCheckoutPath);
$pricingAgencyAnnualHref = $isLoggedIn ? $pricingAgencyAnnualCheckoutPath : 'register.php?next=' . urlencode($pricingAgencyAnnualCheckoutPath);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SEO Suite - AI-Powered SEO Intelligence Platform</title>
    <meta name="description" content="AI-powered SEO intelligence platform combining Google Search Console data, rank tracking, and actionable optimization insights.">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        ink: {
                            950: '#060B1A',
                            900: '#0C1429',
                            800: '#121C34'
                        },
                        brand: {
                            500: '#4F46E5',
                            400: '#6366F1',
                            300: '#818CF8'
                        }
                    },
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                        heading: ['Sora', 'ui-sans-serif', 'system-ui', 'sans-serif']
                    },
                    boxShadow: {
                        card: '0 24px 55px -30px rgba(8, 15, 35, 0.95)',
                        glow: '0 24px 65px -35px rgba(99, 102, 241, 0.6)'
                    }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-16px); }
        }

        .floating {
            animation: float 11s ease-in-out infinite;
        }

        [data-reveal] {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity .7s ease, transform .7s ease;
        }

        [data-reveal].revealed {
            opacity: 1;
            transform: translateY(0px);
        }

        .billing-cycle-btn {
            border-radius: 0.75rem;
            border: 1px solid transparent;
            padding: 0.5rem 0.95rem;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgb(148 163 184);
            transition: all .18s ease;
        }

        .billing-cycle-btn:hover {
            color: rgb(226 232 240);
        }

        .billing-cycle-btn.is-active {
            border-color: rgba(129, 140, 248, 0.45);
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.92), rgba(99, 102, 241, 0.92));
            color: #fff;
            box-shadow: 0 16px 32px -22px rgba(99, 102, 241, 0.9);
        }
    </style>
</head>
<body class="bg-ink-950 text-slate-100 antialiased selection:bg-brand-400/25">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="floating absolute -left-24 -top-28 h-80 w-80 rounded-full bg-indigo-500/20 blur-3xl"></div>
        <div class="floating absolute -right-24 top-20 h-96 w-96 rounded-full bg-blue-500/20 blur-3xl" style="animation-delay: 1.8s;"></div>
        <div class="floating absolute bottom-[-11rem] left-1/3 h-96 w-96 rounded-full bg-sky-500/10 blur-3xl" style="animation-delay: 3.2s;"></div>
    </div>

    <header class="sticky top-0 z-40 border-b border-white/10 bg-ink-950/80 backdrop-blur-xl">
        <div class="mx-auto flex w-full max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <a href="index.php" class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-400 font-heading text-lg font-bold text-white shadow-glow">S</div>
                <div>
                    <p class="font-heading text-base font-semibold text-white">SEO Suite</p>
                    <p class="text-xs text-slate-400">AI SEO Intelligence Platform</p>
                </div>
            </a>

            <nav class="hidden items-center gap-7 text-sm font-semibold text-slate-300 lg:flex">
                <a href="#run-audit" class="transition hover:text-white">Run Audit</a>
                <a href="#features" class="transition hover:text-white">Features</a>
                <a href="#how-it-works" class="transition hover:text-white">How It Works</a>
                <a href="#pricing" class="transition hover:text-white">Pricing</a>
                <a href="#request-demo" class="transition hover:text-white">Request Demo</a>
                <a href="#faq" class="transition hover:text-white">FAQ</a>
            </nav>

            <div class="flex items-center gap-3">
                <?php if ($isLoggedIn): ?>
                    <span class="hidden rounded-lg border border-white/15 px-3 py-2 text-xs font-semibold text-slate-300 sm:inline-flex">
                        <?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <a href="dashboard.php" class="rounded-xl border border-white/20 px-4 py-2 text-sm font-semibold text-slate-100 transition hover:border-white/35 hover:text-white">Dashboard</a>
                <?php else: ?>
                    <a href="login.php" class="rounded-xl border border-white/20 px-4 py-2 text-sm font-semibold text-slate-100 transition hover:border-white/35">Login</a>
                    <a href="register.php" class="rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-5 py-2 text-sm font-bold text-white shadow-glow transition hover:brightness-110">Start Free</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main>
        <section class="mx-auto grid w-full max-w-7xl gap-10 px-4 pb-16 pt-14 sm:px-6 lg:grid-cols-[1.06fr_0.94fr] lg:px-8 lg:pt-20">
            <div class="space-y-8" data-reveal>
                <div class="inline-flex items-center gap-2 rounded-full border border-indigo-300/30 bg-indigo-500/10 px-4 py-2 text-xs font-semibold text-indigo-200">
                    <span class="inline-flex h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                    Rated 4.9/5 by SEO teams and agencies
                </div>

                <div class="space-y-5">
                    <h1 class="font-heading text-4xl font-extrabold leading-tight text-white sm:text-5xl lg:text-6xl">
                        AI-Powered SEO Intelligence for Modern Teams
                    </h1>
                    <p class="max-w-2xl text-lg leading-relaxed text-slate-300">
                        Combine Google Search Console data, rank tracking, and AI-driven optimization insights — all in one powerful platform.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <a href="<?php echo htmlspecialchars($heroPrimaryHref, ENT_QUOTES, 'UTF-8'); ?>" class="rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-6 py-3 text-sm font-bold text-white shadow-glow transition hover:brightness-110">
                        <?php echo htmlspecialchars($heroPrimaryLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <a href="#request-demo" class="rounded-xl border border-white/20 bg-white/5 px-6 py-3 text-sm font-semibold text-slate-100 transition hover:bg-white/10">
                        Request Demo
                    </a>
                </div>

                <p class="text-sm font-medium text-slate-300">No credit card required • Built for agencies & growing teams</p>

                <div class="grid max-w-2xl gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 shadow-card">
                        <p class="text-2xl font-extrabold text-white">12,000+</p>
                        <p class="text-xs text-slate-400">Websites tracked</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 shadow-card">
                        <p class="text-2xl font-extrabold text-white">4.9/5</p>
                        <p class="text-xs text-slate-400">User rating</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 shadow-card">
                        <p class="text-2xl font-extrabold text-white">94%</p>
                        <p class="text-xs text-slate-400">Faster prioritization</p>
                    </div>
                </div>
            </div>

            <div class="relative" data-reveal>
                <div class="absolute -inset-1 rounded-3xl bg-gradient-to-r from-indigo-500/45 via-blue-500/25 to-sky-500/30 blur-2xl"></div>
                <div class="relative overflow-hidden rounded-3xl border border-white/10 bg-ink-900/80 p-5 shadow-card">
                    <div class="mb-4 flex items-center justify-between">
                        <p class="text-sm font-semibold text-slate-200">Unified SEO Dashboard</p>
                        <span class="rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-semibold text-emerald-300">Live</span>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                            <p class="text-xs uppercase tracking-[0.12em] text-slate-400">SEO Score</p>
                            <p class="mt-1 text-2xl font-bold text-white">84</p>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                            <p class="text-xs uppercase tracking-[0.12em] text-slate-400">Keywords</p>
                            <p class="mt-1 text-2xl font-bold text-white">642</p>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                            <p class="text-xs uppercase tracking-[0.12em] text-slate-400">Alerts</p>
                            <p class="mt-1 text-2xl font-bold text-amber-300">6</p>
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="mb-2 flex items-center justify-between text-xs text-slate-300">
                            <span>Clicks & Impressions</span>
                            <span>Last 28 days</span>
                        </div>
                        <div class="flex h-24 items-end gap-1.5">
                            <span class="w-full rounded-t-md bg-indigo-300/35" style="height:30%"></span>
                            <span class="w-full rounded-t-md bg-indigo-300/40" style="height:38%"></span>
                            <span class="w-full rounded-t-md bg-indigo-300/45" style="height:46%"></span>
                            <span class="w-full rounded-t-md bg-indigo-300/55" style="height:44%"></span>
                            <span class="w-full rounded-t-md bg-indigo-300/60" style="height:57%"></span>
                            <span class="w-full rounded-t-md bg-indigo-300/65" style="height:66%"></span>
                            <span class="w-full rounded-t-md bg-indigo-300/80" style="height:74%"></span>
                            <span class="w-full rounded-t-md bg-sky-300/85" style="height:81%"></span>
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-indigo-300/30 bg-indigo-500/10 p-4">
                        <p class="text-xs uppercase tracking-[0.15em] text-indigo-200">AI Insight</p>
                        <p class="mt-2 text-sm text-indigo-100">"Keyword ranks #7 with high impressions but low CTR. Refresh title and meta description to unlock clicks."</p>
                    </div>
                </div>
            </div>
        </section>

        <section id="run-audit" class="mx-auto w-full max-w-7xl px-4 pb-16 sm:px-6 lg:px-8">
            <div class="rounded-3xl border border-indigo-300/35 bg-gradient-to-br from-indigo-500/18 via-ink-900/70 to-sky-500/12 p-6 shadow-glow sm:p-8" data-reveal>
                <div class="grid gap-6 lg:grid-cols-[0.95fr_1.05fr] lg:items-center">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-200">Run New Audit</p>
                        <h2 class="mt-2 font-heading text-3xl font-bold text-white">Audit your project now</h2>
                        <p class="mt-3 text-sm text-slate-200">
                            Enter a website URL and run an SEO check instantly. If you select a project, we use it as context.
                        </p>
                        <?php if ($isLoggedIn && empty($availableProjects)): ?>
                            <p class="mt-3 text-xs text-indigo-100">
                                No projects found yet. Run your first audit and the domain will be available in project-based modules.
                            </p>
                        <?php endif; ?>
                        <?php if (!$isLoggedIn): ?>
                            <p class="mt-3 text-xs text-indigo-100">
                                Sign in to auto-save audit history and connect this domain with other project tools.
                            </p>
                        <?php endif; ?>
                    </div>

                    <form id="audit-run-form" class="grid gap-4 rounded-2xl border border-white/10 bg-ink-950/55 p-4 sm:p-5">
                        <?php if ($isLoggedIn): ?>
                            <label class="block">
                                <span class="mb-1.5 block text-sm font-semibold text-slate-200">Project (optional)</span>
                                <select id="audit-project" class="w-full rounded-xl border border-white/15 bg-ink-900/70 px-3 py-2.5 text-sm text-slate-100 outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-400/20">
                                    <option value="">Auto-detect from URL</option>
                                    <?php foreach ($availableProjects as $project): ?>
                                        <?php
                                            $projectId = (int) ($project['id'] ?? 0);
                                            $projectName = (string) ($project['name'] ?? 'Project');
                                            $projectDomain = (string) ($project['domain'] ?? '');
                                            if ($projectId <= 0 || $projectDomain === '') {
                                                continue;
                                            }
                                        ?>
                                        <option value="<?php echo $projectId; ?>" data-domain="<?php echo htmlspecialchars($projectDomain, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($projectName . ' (' . $projectDomain . ')', ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>

                        <label class="block">
                            <span class="mb-1.5 block text-sm font-semibold text-slate-200">Website URL</span>
                            <input id="audit-url" type="text" maxlength="255" placeholder="https://example.com" class="w-full rounded-xl border border-white/15 bg-ink-900/70 px-3 py-2.5 text-sm text-slate-100 outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-400/20" required>
                        </label>

                        <div class="flex flex-wrap items-center gap-3">
                            <button id="audit-run-button" type="submit" class="inline-flex items-center rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-5 py-2.5 text-sm font-bold text-white shadow-glow transition hover:brightness-110">
                                Run Audit Now
                            </button>
                            <span id="audit-run-status" class="hidden text-xs font-semibold text-indigo-100">Analyzing... please wait</span>
                        </div>

                        <div id="audit-run-message" class="hidden rounded-xl border px-3 py-2 text-sm"></div>
                    </form>
                </div>
            </div>
        </section>

        <section class="border-y border-white/10 bg-ink-900/55">
            <div class="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                <div class="grid gap-6 lg:grid-cols-[0.86fr_1.14fr] lg:items-center" data-reveal>
                    <div>
                        <p class="font-heading text-2xl font-bold text-white">Trusted by teams focused on measurable SEO growth</p>
                        <p class="mt-2 text-sm text-slate-300">4.9+ average rating from consultants, founders, and agency operators.</p>
                        <div class="mt-3 flex items-center gap-2 text-amber-300">
                            <span class="tracking-widest">★★★★★</span>
                            <span class="text-sm font-semibold text-slate-200">4.9/5 from 1,900+ reviews</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-center sm:grid-cols-5">
                        <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-300">NovaGrowth</div>
                        <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-300">WebForge</div>
                        <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-300">CloudPeak</div>
                        <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-300">RankNest</div>
                        <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-300">ScaleHub</div>
                    </div>
                </div>
            </div>
        </section>

        <section id="features" class="mx-auto w-full max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
            <div class="mb-12 text-center" data-reveal>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-300">Features</p>
                <h2 class="mt-3 font-heading text-3xl font-bold text-white sm:text-4xl">Everything You Need to Dominate Search</h2>
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                <article class="rounded-3xl border border-white/10 bg-white/5 p-6 shadow-card" data-reveal>
                    <div class="mb-4 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-500/20 text-indigo-200">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 18h16M7 14l3-3 2 2 5-5"/></svg>
                    </div>
                    <p class="text-xs font-semibold uppercase tracking-[0.15em] text-indigo-200">Layer 1</p>
                    <h3 class="mt-1 font-heading text-xl font-bold text-white">Core SEO Engine</h3>
                    <ul class="mt-4 space-y-2 text-sm text-slate-300">
                        <li>Technical SEO Audit</li>
                        <li>GSC Performance Tracking</li>
                        <li>Rank Monitoring</li>
                        <li>Automated Alerts</li>
                    </ul>
                </article>

                <article class="rounded-3xl border border-indigo-300/35 bg-gradient-to-b from-indigo-500/14 to-white/5 p-6 shadow-glow" data-reveal>
                    <div class="mb-4 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-500/25 text-indigo-100">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3l2.8 5.7L21 9.6l-4.5 4.3 1.1 6.1L12 17l-5.6 3 1.1-6.1L3 9.6l6.2-.9L12 3z"/></svg>
                    </div>
                    <p class="text-xs font-semibold uppercase tracking-[0.15em] text-indigo-100">Layer 2</p>
                    <h3 class="mt-1 font-heading text-xl font-bold text-white">AI Intelligence</h3>
                    <ul class="mt-4 space-y-2 text-sm text-indigo-50">
                        <li>AI SEO Advisor</li>
                        <li>AI Meta Generator</li>
                        <li>AI Content Optimizer</li>
                        <li>Weekly AI Strategy Reports</li>
                    </ul>
                </article>

                <article class="rounded-3xl border border-white/10 bg-white/5 p-6 shadow-card" data-reveal>
                    <div class="mb-4 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-500/20 text-indigo-200">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16M4 12h16M4 17h16"/><path d="M8 5v14"/></svg>
                    </div>
                    <p class="text-xs font-semibold uppercase tracking-[0.15em] text-indigo-200">Layer 3</p>
                    <h3 class="mt-1 font-heading text-xl font-bold text-white">Business & Agency Tools</h3>
                    <ul class="mt-4 space-y-2 text-sm text-slate-300">
                        <li>Multi-project dashboard</li>
                        <li>Role-based access control</li>
                        <li>Admin control panel</li>
                        <li>Subscription management</li>
                        <li>Usage monitoring</li>
                    </ul>
                </article>
            </div>
        </section>

        <section id="how-it-works" class="border-y border-white/10 bg-ink-900/55">
            <div class="mx-auto w-full max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
                <div class="mb-10 text-center" data-reveal>
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-300">How It Works</p>
                    <h2 class="mt-3 font-heading text-3xl font-bold text-white sm:text-4xl">Launch your SEO workflow in four steps</h2>
                </div>

                <div class="grid gap-4 md:grid-cols-4">
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-6" data-reveal>
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-indigo-500/25 text-sm font-bold text-indigo-100">1</span>
                        <h3 class="mt-4 font-heading text-lg font-bold text-white">Add your website</h3>
                        <p class="mt-2 text-sm text-slate-300">Create a project and run your first technical audit.</p>
                    </article>
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-6" data-reveal>
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-indigo-500/25 text-sm font-bold text-indigo-100">2</span>
                        <h3 class="mt-4 font-heading text-lg font-bold text-white">Connect Google Search Console</h3>
                        <p class="mt-2 text-sm text-slate-300">Bring in real clicks, impressions, CTR, and positions.</p>
                    </article>
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-6" data-reveal>
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-indigo-500/25 text-sm font-bold text-indigo-100">3</span>
                        <h3 class="mt-4 font-heading text-lg font-bold text-white">Track performance</h3>
                        <p class="mt-2 text-sm text-slate-300">Monitor rankings, movement trends, and issue alerts daily.</p>
                    </article>
                    <article class="rounded-2xl border border-indigo-300/35 bg-indigo-500/12 p-6" data-reveal>
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-indigo-500/35 text-sm font-bold text-indigo-100">4</span>
                        <h3 class="mt-4 font-heading text-lg font-bold text-white">Use AI to optimize</h3>
                        <p class="mt-2 text-sm text-indigo-100">Get structured actions from your ranking + GSC signals.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="mx-auto w-full max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
            <div class="mb-10 text-center" data-reveal>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-300">Testimonials</p>
                <h2 class="mt-3 font-heading text-3xl font-bold text-white sm:text-4xl">Trusted by teams that execute fast</h2>
            </div>

            <div class="grid gap-5 md:grid-cols-3">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-6 shadow-card" data-reveal>
                    <p class="text-amber-300">★★★★★</p>
                    <p class="mt-3 text-sm text-slate-200">"I get immediate next actions from ranking and GSC data instead of spending hours in spreadsheets."</p>
                    <p class="mt-5 text-sm font-semibold text-white">Anita Rao</p>
                    <p class="text-xs text-slate-400">SEO Consultant</p>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-6 shadow-card" data-reveal>
                    <p class="text-amber-300">★★★★★</p>
                    <p class="mt-3 text-sm text-slate-200">"The AI strategy layer helps our growth team focus on impact without hiring a full SEO ops team."</p>
                    <p class="mt-5 text-sm font-semibold text-white">Michael Tan</p>
                    <p class="text-xs text-slate-400">SaaS Founder</p>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-6 shadow-card" data-reveal>
                    <p class="text-amber-300">★★★★★</p>
                    <p class="mt-3 text-sm text-slate-200">"Agency reporting and monitoring are clean, and clients understand the strategy instantly."</p>
                    <p class="mt-5 text-sm font-semibold text-white">Rohit Menon</p>
                    <p class="text-xs text-slate-400">Agency Owner</p>
                </article>
            </div>
        </section>

        <section id="pricing" class="border-y border-white/10 bg-ink-900/55">
            <div class="mx-auto w-full max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
                <div class="mb-10 text-center" data-reveal>
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-300">Pricing</p>
                    <h2 class="mt-3 font-heading text-3xl font-bold text-white sm:text-4xl">Plans built for every SEO stage</h2>
                    <div class="mt-6 flex justify-center">
                        <div id="landing-pricing-toggle" class="inline-flex items-center gap-1 rounded-2xl border border-white/15 bg-white/5 p-1">
                            <button type="button" class="billing-cycle-btn is-active" data-cycle="monthly">Monthly</button>
                            <button type="button" class="billing-cycle-btn" data-cycle="annual">Annual</button>
                        </div>
                    </div>
                </div>

                <div class="grid gap-5 lg:grid-cols-3">
                    <article class="rounded-3xl border border-white/10 bg-white/5 p-7 shadow-card" data-reveal>
                        <p class="text-sm font-semibold text-slate-300">Free</p>
                        <p class="mt-2 text-4xl font-extrabold text-white"><?php echo format_inr($pricingFreeMonthly, 0); ?></p>
                        <p class="mt-2 text-sm text-slate-400">Great for getting started.</p>
                        <ul class="mt-6 space-y-2 text-sm text-slate-300">
                            <li>1 project</li>
                            <li>Core audit + tracking essentials</li>
                            <li>Limited insights</li>
                            <li>AI usage limit: 3 requests / month</li>
                        </ul>
                        <a href="<?php echo htmlspecialchars($pricingFreeHref, ENT_QUOTES, 'UTF-8'); ?>" class="mt-7 inline-flex w-full justify-center rounded-xl border border-white/20 px-4 py-3 text-sm font-semibold text-slate-100 transition hover:bg-white/10"><?php echo htmlspecialchars($pricingFreeLabel, ENT_QUOTES, 'UTF-8'); ?></a>
                    </article>

                    <article class="relative rounded-3xl border border-indigo-300/45 bg-gradient-to-b from-indigo-500/16 to-white/5 p-7 shadow-glow" data-reveal>
                        <span class="absolute -top-3 left-6 rounded-full bg-gradient-to-r from-brand-500 to-brand-400 px-3 py-1 text-xs font-bold text-white">Most Popular</span>
                        <p class="text-sm font-semibold text-indigo-200">Pro</p>
                        <p class="mt-2 text-4xl font-extrabold text-white"><span id="landing-pro-price" data-monthly="<?php echo htmlspecialchars(format_inr($pricingProMonthly, 0), ENT_QUOTES, 'UTF-8'); ?>" data-annual="<?php echo htmlspecialchars(format_inr($pricingProAnnual, 0), ENT_QUOTES, 'UTF-8'); ?>"><?php echo format_inr($pricingProMonthly, 0); ?></span><span id="landing-pro-period" class="text-lg text-slate-300">/mo</span></p>
                        <p class="mt-2 text-sm text-slate-300">Best for growth teams.</p>
                        <ul class="mt-6 space-y-2 text-sm text-slate-100">
                            <li>Up to 5 projects</li>
                            <li>Full rank + GSC intelligence</li>
                            <li>Actionable insight engine</li>
                            <li>AI usage limit: 20 requests / month</li>
                        </ul>
                        <a id="landing-pro-cta" href="<?php echo htmlspecialchars($pricingProMonthlyHref, ENT_QUOTES, 'UTF-8'); ?>" data-monthly-href="<?php echo htmlspecialchars($pricingProMonthlyHref, ENT_QUOTES, 'UTF-8'); ?>" data-annual-href="<?php echo htmlspecialchars($pricingProAnnualHref, ENT_QUOTES, 'UTF-8'); ?>" class="mt-7 inline-flex w-full justify-center rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-4 py-3 text-sm font-bold text-white transition hover:brightness-110">Choose Pro</a>
                    </article>

                    <article class="rounded-3xl border border-white/10 bg-white/5 p-7 shadow-card" data-reveal>
                        <p class="text-sm font-semibold text-slate-300">Agency</p>
                        <p class="mt-2 text-4xl font-extrabold text-white"><span id="landing-agency-price" data-monthly="<?php echo htmlspecialchars(format_inr($pricingAgencyMonthly, 0), ENT_QUOTES, 'UTF-8'); ?>" data-annual="<?php echo htmlspecialchars(format_inr($pricingAgencyAnnual, 0), ENT_QUOTES, 'UTF-8'); ?>"><?php echo format_inr($pricingAgencyMonthly, 0); ?></span><span id="landing-agency-period" class="text-lg text-slate-300">/mo</span></p>
                        <p class="mt-2 text-sm text-slate-400">For multi-client operations.</p>
                        <ul class="mt-6 space-y-2 text-sm text-slate-300">
                            <li>Unlimited projects</li>
                            <li>White-label and admin controls</li>
                            <li>Advanced monitoring and usage analytics</li>
                            <li>AI usage limit: 100 requests / month</li>
                        </ul>
                        <a id="landing-agency-cta" href="<?php echo htmlspecialchars($pricingAgencyMonthlyHref, ENT_QUOTES, 'UTF-8'); ?>" data-monthly-href="<?php echo htmlspecialchars($pricingAgencyMonthlyHref, ENT_QUOTES, 'UTF-8'); ?>" data-annual-href="<?php echo htmlspecialchars($pricingAgencyAnnualHref, ENT_QUOTES, 'UTF-8'); ?>" class="mt-7 inline-flex w-full justify-center rounded-xl border border-white/20 px-4 py-3 text-sm font-semibold text-slate-100 transition hover:bg-white/10">Choose Agency</a>
                    </article>
                </div>
            </div>
        </section>

        <section id="request-demo" class="mx-auto w-full max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
            <div class="grid gap-8 lg:grid-cols-[0.9fr_1.1fr] lg:items-start">
                <div data-reveal>
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-300">Request Demo</p>
                    <h2 class="mt-3 font-heading text-3xl font-bold text-white sm:text-4xl">Request a Personalized Demo</h2>
                    <p class="mt-4 text-slate-300">Tell us about your website and goals. We will walk you through your use-case with a tailored SEO intelligence workflow.</p>
                    <div class="mt-6 space-y-3 text-sm text-slate-300">
                        <p>• Live product walkthrough for your website.</p>
                        <p>• Plan recommendation based on project size.</p>
                        <p>• Q&A on setup, data flow, and team onboarding.</p>
                    </div>
                </div>

                <div class="rounded-3xl border border-white/10 bg-white/5 p-6 shadow-card" data-reveal>
                    <?php if (!empty($demoSuccess)): ?>
                        <div class="mb-5 rounded-xl border border-emerald-300/35 bg-emerald-500/10 px-4 py-3 text-sm font-medium text-emerald-200">
                            <?php echo htmlspecialchars($demoSuccess, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($demoErrors)): ?>
                        <div class="mb-5 rounded-xl border border-red-300/35 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                            <p class="font-semibold">Please fix the following:</p>
                            <ul class="mt-2 list-disc pl-5">
                                <?php foreach ($demoErrors as $error): ?>
                                    <li><?php echo htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="grid gap-4">
                        <input type="hidden" name="form_action" value="request_demo">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($demoCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <label class="block">
                            <span class="mb-1.5 block text-sm font-semibold text-slate-200">Full Name</span>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($demoForm['full_name'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="120" required class="w-full rounded-xl border border-white/15 bg-ink-900/60 px-3 py-2.5 text-sm text-slate-100 outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-400/20">
                        </label>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1.5 block text-sm font-semibold text-slate-200">Email</span>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($demoForm['email'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="255" required class="w-full rounded-xl border border-white/15 bg-ink-900/60 px-3 py-2.5 text-sm text-slate-100 outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-400/20">
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-sm font-semibold text-slate-200">Company Name</span>
                                <input type="text" name="company_name" value="<?php echo htmlspecialchars($demoForm['company_name'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="140" required class="w-full rounded-xl border border-white/15 bg-ink-900/60 px-3 py-2.5 text-sm text-slate-100 outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-400/20">
                            </label>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1.5 block text-sm font-semibold text-slate-200">Website URL</span>
                                <input type="text" name="website_url" value="<?php echo htmlspecialchars($demoForm['website_url'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://example.com" maxlength="255" required class="w-full rounded-xl border border-white/15 bg-ink-900/60 px-3 py-2.5 text-sm text-slate-100 outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-400/20">
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-sm font-semibold text-slate-200">SEO Budget</span>
                                <select name="seo_budget" required class="w-full rounded-xl border border-white/15 bg-ink-900/60 px-3 py-2.5 text-sm text-slate-100 outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-400/20">
                                    <option value="">Select budget</option>
                                    <?php foreach ($demoBudgetOptions as $value => $label): ?>
                                        <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $demoForm['seo_budget'] === $value ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>

                        <label class="block">
                            <span class="mb-1.5 block text-sm font-semibold text-slate-200">Message</span>
                            <textarea name="message" rows="5" maxlength="2000" required class="w-full rounded-xl border border-white/15 bg-ink-900/60 px-3 py-2.5 text-sm text-slate-100 outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-400/20" placeholder="Tell us your SEO goals, current challenges, and what you want to improve."><?php echo htmlspecialchars($demoForm['message'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </label>

                        <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-5 py-3 text-sm font-bold text-white shadow-glow transition hover:brightness-110">
                            Request Demo
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <section id="faq" class="border-y border-white/10 bg-ink-900/55">
            <div class="mx-auto w-full max-w-4xl px-4 py-20 sm:px-6 lg:px-8">
                <div class="mb-8 text-center" data-reveal>
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-300">FAQ</p>
                    <h2 class="mt-3 font-heading text-3xl font-bold text-white sm:text-4xl">Common questions</h2>
                </div>

                <div class="space-y-3">
                    <details class="rounded-2xl border border-white/10 bg-white/5 p-5" data-reveal>
                        <summary class="cursor-pointer list-none font-semibold text-white">Is my data secure?</summary>
                        <p class="mt-3 text-sm text-slate-300">Yes. We use secure OAuth flows, project-level isolation, and role-based controls across the platform.</p>
                    </details>
                    <details class="rounded-2xl border border-white/10 bg-white/5 p-5" data-reveal>
                        <summary class="cursor-pointer list-none font-semibold text-white">Can I cancel anytime?</summary>
                        <p class="mt-3 text-sm text-slate-300">Yes. You can upgrade, downgrade, or cancel at any time from your account settings.</p>
                    </details>
                    <details class="rounded-2xl border border-white/10 bg-white/5 p-5" data-reveal>
                        <summary class="cursor-pointer list-none font-semibold text-white">Do I need Google Search Console?</summary>
                        <p class="mt-3 text-sm text-slate-300">No. You can use core SEO modules without GSC, but connecting it unlocks richer insights and AI recommendations.</p>
                    </details>
                    <details class="rounded-2xl border border-white/10 bg-white/5 p-5" data-reveal>
                        <summary class="cursor-pointer list-none font-semibold text-white">Is there a free plan?</summary>
                        <p class="mt-3 text-sm text-slate-300">Yes. The Free plan includes essential SEO features and limited AI usage so you can validate fit before upgrading.</p>
                    </details>
                </div>
            </div>
        </section>

        <section class="mx-auto w-full max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
            <div class="rounded-3xl border border-indigo-300/35 bg-gradient-to-r from-indigo-500/20 via-blue-500/10 to-sky-500/20 p-8 text-center shadow-glow sm:p-12" data-reveal>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-200">Final CTA</p>
                <h2 class="mt-3 font-heading text-3xl font-extrabold text-white sm:text-4xl">Ready to Take Control of Your SEO?</h2>
                <p class="mx-auto mt-4 max-w-2xl text-slate-200">Launch your SEO intelligence workspace and turn complex signals into clear weekly actions.</p>
                <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                    <a href="<?php echo htmlspecialchars($primaryHref, ENT_QUOTES, 'UTF-8'); ?>" class="rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-7 py-3 text-sm font-bold text-white shadow-glow transition hover:brightness-110">
                        Start Free Now
                    </a>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-white/10 bg-ink-950">
        <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-8 text-sm text-slate-400 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
            <p>&copy; <?php echo date('Y'); ?> SEO Suite. All rights reserved.</p>
            <div class="flex items-center gap-5">
                <a href="#features" class="transition hover:text-slate-200">Features</a>
                <a href="#pricing" class="transition hover:text-slate-200">Pricing</a>
                <a href="#request-demo" class="transition hover:text-slate-200">Request Demo</a>
                <a href="login.php" class="transition hover:text-slate-200">Login</a>
            </div>
        </div>
    </footer>

    <script>
        (function () {
            var nodes = document.querySelectorAll('[data-reveal]');
            if (!('IntersectionObserver' in window)) {
                nodes.forEach(function (node) { node.classList.add('revealed'); });
                return;
            }

            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('revealed');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1, rootMargin: '0px 0px -20px 0px' });

            nodes.forEach(function (node) {
                observer.observe(node);
            });
        })();

        (function () {
            var toggleRoot = document.getElementById('landing-pricing-toggle');
            if (!toggleRoot) {
                return;
            }

            var buttons = toggleRoot.querySelectorAll('[data-cycle]');
            var proPrice = document.getElementById('landing-pro-price');
            var proPeriod = document.getElementById('landing-pro-period');
            var proCta = document.getElementById('landing-pro-cta');
            var agencyPrice = document.getElementById('landing-agency-price');
            var agencyPeriod = document.getElementById('landing-agency-period');
            var agencyCta = document.getElementById('landing-agency-cta');

            function setCycle(cycle) {
                var useAnnual = cycle === 'annual';
                buttons.forEach(function (button) {
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
                }

                if (agencyPrice) {
                    agencyPrice.textContent = useAnnual ? String(agencyPrice.dataset.annual || '') : String(agencyPrice.dataset.monthly || '');
                }
                if (agencyPeriod) {
                    agencyPeriod.textContent = useAnnual ? '/yr' : '/mo';
                }
                if (agencyCta) {
                    agencyCta.setAttribute('href', useAnnual ? String(agencyCta.dataset.annualHref || '#') : String(agencyCta.dataset.monthlyHref || '#'));
                }
            }

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    setCycle(String(button.getAttribute('data-cycle') || 'monthly'));
                });
            });

            setCycle('monthly');
        })();

        (function () {
            var form = document.getElementById('audit-run-form');
            if (!form) {
                return;
            }

            var urlInput = document.getElementById('audit-url');
            var projectSelect = document.getElementById('audit-project');
            var button = document.getElementById('audit-run-button');
            var status = document.getElementById('audit-run-status');
            var message = document.getElementById('audit-run-message');

            function showMessage(type, text) {
                if (!message) {
                    return;
                }

                var classes = 'rounded-xl border px-3 py-2 text-sm ';
                if (type === 'success') {
                    classes += 'border-emerald-300/35 bg-emerald-500/10 text-emerald-200';
                } else if (type === 'error') {
                    classes += 'border-red-300/35 bg-red-500/10 text-red-200';
                } else {
                    classes += 'border-indigo-300/35 bg-indigo-500/10 text-indigo-100';
                }

                message.className = classes;
                message.textContent = text;
                message.classList.remove('hidden');
            }

            function hideMessage() {
                if (!message) {
                    return;
                }
                message.classList.add('hidden');
                message.textContent = '';
            }

            function normalizeUrl(raw) {
                var value = String(raw || '').trim();
                if (!value) {
                    return '';
                }
                if (!/^https?:\/\//i.test(value)) {
                    value = 'https://' + value;
                }
                return value;
            }

            function setLoading(isLoading) {
                if (button) {
                    button.disabled = isLoading;
                    button.classList.toggle('opacity-60', isLoading);
                    button.classList.toggle('cursor-not-allowed', isLoading);
                }
                if (status) {
                    status.classList.toggle('hidden', !isLoading);
                }
            }

            if (projectSelect) {
                projectSelect.addEventListener('change', function () {
                    var selected = projectSelect.options[projectSelect.selectedIndex];
                    if (!selected) {
                        return;
                    }

                    var domain = String(selected.getAttribute('data-domain') || '').trim();
                    var current = String(urlInput && urlInput.value ? urlInput.value : '').trim();
                    if (domain && current === '') {
                        urlInput.value = 'https://' + domain;
                    }
                });
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                hideMessage();

                var normalized = normalizeUrl(urlInput ? urlInput.value : '');
                if (!normalized) {
                    showMessage('error', 'Enter a valid website URL.');
                    return;
                }

                try {
                    new URL(normalized);
                } catch (err) {
                    showMessage('error', 'Enter a valid website URL (example: https://example.com).');
                    return;
                }

                var payload = { url: normalized };
                if (projectSelect && projectSelect.value) {
                    payload.project_id = Number(projectSelect.value || 0);
                }

                setLoading(true);
                fetch('analyze.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(payload)
                })
                .then(function (response) {
                    return response.text().then(function (raw) {
                        var parsed;
                        try {
                            parsed = JSON.parse(raw);
                        } catch (err) {
                            parsed = {};
                        }
                        return { ok: response.ok, data: parsed };
                    });
                })
                .then(function (result) {
                    var data = result.data || {};
                    if (!result.ok || data.error) {
                        showMessage('error', String(data.error || 'Unable to run audit right now. Please try again.'));
                        return;
                    }

                    if (data.success && data.id) {
                        showMessage('success', 'Audit completed. Opening report...');
                        window.location.href = 'results.php?id=' + encodeURIComponent(String(data.id));
                        return;
                    }

                    showMessage('error', 'Audit finished but report ID was missing. Please retry.');
                })
                .catch(function () {
                    showMessage('error', 'Network error while running audit. Please try again.');
                })
                .finally(function () {
                    setLoading(false);
                });
            });
        })();
    </script>
</body>
</html>
