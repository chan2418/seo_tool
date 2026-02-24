<?php
session_start();
require_once __DIR__ . '/../models/AuditModel.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: index.php');
    exit;
}

$auditModel = new AuditModel();
$report = $auditModel->getAuditById($id);

if (!$report) {
    die('Audit not found.');
}

$isAuthenticated = isset($_SESSION['user_id']);
$userName = $_SESSION['user_name'] ?? 'Guest';
$planType = strtolower($_SESSION['plan_type'] ?? 'free');
$planLabel = ucfirst($planType);
$dashboardLink = $isAuthenticated ? 'dashboard.php' : 'index.php';

function decodeJsonField($value): array
{
    if (is_array($value)) {
        return $value;
    }

    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function normalizeSeverity(string $status): string
{
    $status = strtolower($status);

    if ($status === 'valid') {
        return 'passed';
    }

    if ($status === 'warning') {
        return 'warning';
    }

    return 'critical';
}

$details = $report['details'] ?? [];
if (is_string($details)) {
    $decodedDetails = json_decode($details, true);
    $details = is_array($decodedDetails) ? $decodedDetails : [];
}

$seo = is_array($details['seo'] ?? null) ? $details['seo'] : [];
$pagespeed = is_array($details['pagespeed'] ?? null) ? $details['pagespeed'] : [];
$recommendations = is_array($details['recommendations'] ?? null) ? $details['recommendations'] : [];

$metaTitleLegacy = decodeJsonField($report['meta_title_details'] ?? null);
$metaDescLegacy = decodeJsonField($report['meta_description_details'] ?? null);
$h1Legacy = decodeJsonField($report['h1_details'] ?? null);
$imagesLegacy = decodeJsonField($report['image_alt_details'] ?? null);

$metaTitle = $seo['meta_title'] ?? $metaTitleLegacy;
$metaDesc = $seo['meta_description'] ?? $metaDescLegacy;
$h1 = $seo['h1_tags'] ?? $h1Legacy;
$images = $seo['images'] ?? $imagesLegacy;

$metaTitle = array_merge([
    'value' => null,
    'length' => 0,
    'status' => 'missing',
    'message' => 'Meta title details are unavailable for this report.'
], is_array($metaTitle) ? $metaTitle : []);

$metaDesc = array_merge([
    'value' => null,
    'length' => 0,
    'status' => 'missing',
    'message' => 'Meta description details are unavailable for this report.'
], is_array($metaDesc) ? $metaDesc : []);

$h1 = array_merge([
    'count' => 0,
    'status' => 'missing',
    'message' => 'H1 details are unavailable for this report.'
], is_array($h1) ? $h1 : []);

$images = array_merge([
    'total' => 0,
    'missing_alt' => 0,
    'score' => 0
], is_array($images) ? $images : []);

$httpsStatus = isset($seo['https']) ? (bool) $seo['https'] : (bool) ($report['https_status'] ?? false);
$mobileStatus = isset($seo['mobile']) ? (bool) $seo['mobile'] : (bool) ($report['mobile_status'] ?? false);
$pageSpeedScore = isset($pagespeed['score']) ? (int) $pagespeed['score'] : (int) ($report['pagespeed_score'] ?? 0);

$seoScore = isset($report['seo_score']) ? (int) $report['seo_score'] : 0;
$seoScore = max(0, min(100, $seoScore));

$url = (string) ($report['url'] ?? '');
$domain = parse_url($url, PHP_URL_HOST) ?: $url;
$createdAt = !empty($report['created_at']) ? date('M d, Y', strtotime($report['created_at'])) : 'Unknown date';

$imageSeverity = 'critical';
if ((int) $images['missing_alt'] === 0) {
    $imageSeverity = 'passed';
} elseif ((int) $images['score'] >= 70) {
    $imageSeverity = 'warning';
}

$pageSpeedSeverity = $pageSpeedScore >= 90 ? 'passed' : ($pageSpeedScore >= 50 ? 'warning' : 'critical');

$checks = [
    [
        'title' => 'HTTPS Enabled',
        'summary' => $httpsStatus ? 'Secure HTTPS connection detected.' : 'HTTPS is missing on this domain.',
        'severity' => $httpsStatus ? 'passed' : 'critical',
        'suggestion' => $httpsStatus
            ? 'Keep SSL certificates auto-renewing and enforce HTTPS redirects site-wide.'
            : 'Install an SSL certificate and redirect all HTTP traffic to HTTPS.'
    ],
    [
        'title' => 'Mobile Friendly',
        'summary' => $mobileStatus ? 'Viewport configuration is present.' : 'Viewport meta tag not detected.',
        'severity' => $mobileStatus ? 'passed' : 'critical',
        'suggestion' => $mobileStatus
            ? 'Continue validating templates on small screens for strong UX metrics.'
            : 'Add a viewport tag: <meta name="viewport" content="width=device-width, initial-scale=1">.'
    ],
    [
        'title' => 'Meta Title',
        'summary' => $metaTitle['message'],
        'severity' => normalizeSeverity((string) $metaTitle['status']),
        'suggestion' => 'Target 50-60 characters, include the primary keyword, and keep the title unique.'
    ],
    [
        'title' => 'Meta Description',
        'summary' => $metaDesc['message'],
        'severity' => normalizeSeverity((string) $metaDesc['status']),
        'suggestion' => 'Write a 150-160 character summary with a clear value proposition and CTA.'
    ],
    [
        'title' => 'H1 Structure',
        'summary' => $h1['message'],
        'severity' => normalizeSeverity((string) $h1['status']),
        'suggestion' => 'Use one clear H1 that matches the search intent and page topic.'
    ],
    [
        'title' => 'Image Alt Text',
        'summary' => (int) $images['missing_alt'] . ' of ' . (int) $images['total'] . ' images need ALT attributes.',
        'severity' => $imageSeverity,
        'suggestion' => 'Add descriptive ALT text to all important images for accessibility and relevance.'
    ],
    [
        'title' => 'PageSpeed Performance',
        'summary' => 'Current PageSpeed score: ' . $pageSpeedScore . '/100.',
        'severity' => $pageSpeedSeverity,
        'suggestion' => 'Compress media, defer non-critical JS, and optimize server response times.'
    ]
];

$passedChecks = [];
$warningChecks = [];
$criticalChecks = [];

foreach ($checks as $check) {
    if ($check['severity'] === 'passed') {
        $passedChecks[] = $check;
    } elseif ($check['severity'] === 'warning') {
        $warningChecks[] = $check;
    } else {
        $criticalChecks[] = $check;
    }
}

$scoreClass = $seoScore >= 80
    ? 'text-emerald-600 dark:text-emerald-400'
    : ($seoScore >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400');

$scoreToneBadge = $seoScore >= 80
    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300'
    : ($seoScore >= 50 ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300' : 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300');

$scoreMessage = $seoScore >= 80
    ? 'Strong baseline. Keep iterating on performance and content quality.'
    : ($seoScore >= 50
        ? 'Good momentum. Fix warnings to push this into the strong zone.'
        : 'Priority fixes required. Address critical issues first for fast gains.');
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SEO Report - <?php echo htmlspecialchars($domain); ?></title>
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
            border: 1px solid rgba(255, 255, 255, 0.64);
            background: rgba(255, 255, 255, 0.84);
            backdrop-filter: blur(12px);
        }

        .dark .surface-card {
            border-color: rgba(51, 65, 85, 0.85);
            background: rgba(30, 41, 59, 0.82);
        }

        details > summary {
            list-style: none;
        }

        details > summary::-webkit-details-marker {
            display: none;
        }

        .issue-card {
            transition: transform 160ms ease, box-shadow 160ms ease;
        }

        .issue-card:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute left-[-8rem] top-[-8rem] h-72 w-72 rounded-full bg-indigo-300/40 blur-3xl dark:bg-indigo-500/20"></div>
        <div class="absolute right-[-4rem] top-10 h-64 w-64 rounded-full bg-sky-200/60 blur-3xl dark:bg-sky-500/15"></div>
        <div class="absolute bottom-[-8rem] left-1/2 h-72 w-72 -translate-x-1/2 rounded-full bg-violet-200/50 blur-3xl dark:bg-violet-500/15"></div>
    </div>

    <header class="sticky top-0 z-20 border-b border-white/60 bg-slate-100/75 px-4 py-4 backdrop-blur-xl dark:border-slate-700/70 dark:bg-slate-950/70 sm:px-6 lg:px-10">
        <div class="mx-auto flex w-full max-w-7xl items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="<?php echo htmlspecialchars($dashboardLink); ?>" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:text-brand-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6" />
                    </svg>
                </a>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Audit Report</p>
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($domain); ?></h1>
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

                <span class="hidden rounded-xl px-3 py-2 text-xs font-bold tracking-wide sm:inline-flex <?php echo $planType === 'pro' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'; ?>">
                    <?php echo htmlspecialchars($planLabel); ?> Plan
                </span>

                <?php if ($isAuthenticated): ?>
                    <a href="dashboard.php" class="inline-flex items-center rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-4 py-2 text-sm font-semibold text-white shadow-soft transition hover:opacity-90">Dashboard</a>
                <?php else: ?>
                    <a href="login.php" class="inline-flex items-center rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-4 py-2 text-sm font-semibold text-white shadow-soft transition hover:opacity-90">Sign In</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="mx-auto w-full max-w-7xl space-y-6 px-4 pb-10 pt-6 sm:px-6 lg:px-10">
        <section class="surface-card shadow-soft">
            <div class="flex flex-col gap-8 p-6 sm:p-8 lg:flex-row lg:items-center lg:justify-between">
                <div class="max-w-2xl space-y-4">
                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?php echo $scoreToneBadge; ?>">
                        SEO Score Health
                    </span>
                    <h2 class="text-3xl font-extrabold text-slate-900 dark:text-slate-100">SEO Score: <?php echo $seoScore; ?> / 100</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        <?php echo htmlspecialchars($scoreMessage); ?>
                    </p>
                    <div class="flex flex-wrap gap-3 text-xs text-slate-500 dark:text-slate-300">
                        <span class="rounded-full bg-slate-200 px-3 py-1 font-semibold dark:bg-slate-700">Audit ID #<?php echo (int) ($report['id'] ?? 0); ?></span>
                        <span class="rounded-full bg-slate-200 px-3 py-1 font-semibold dark:bg-slate-700"><?php echo htmlspecialchars($createdAt); ?></span>
                        <a href="<?php echo htmlspecialchars($url); ?>" target="_blank" rel="noopener noreferrer" class="rounded-full bg-slate-200 px-3 py-1 font-semibold text-brand-600 transition hover:text-brand-500 dark:bg-slate-700 dark:text-brand-300">
                            Open Domain
                        </a>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="index.php" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-5 py-3 text-sm font-semibold text-white shadow-soft transition hover:opacity-90">Re-run Audit</a>
                        <a href="<?php echo htmlspecialchars($dashboardLink); ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Back to Workspace</a>
                    </div>
                </div>

                <div class="relative mx-auto h-48 w-48">
                    <svg class="h-48 w-48 -rotate-90" viewBox="0 0 180 180" fill="none">
                        <defs>
                            <linearGradient id="resultScoreGradient" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="#4F46E5" />
                                <stop offset="100%" stop-color="#6366F1" />
                            </linearGradient>
                        </defs>
                        <circle cx="90" cy="90" r="68" stroke="currentColor" stroke-width="14" class="text-slate-200 dark:text-slate-700" />
                        <circle id="result-score-progress" data-score="<?php echo $seoScore; ?>" cx="90" cy="90" r="68" stroke="url(#resultScoreGradient)" stroke-width="14" stroke-linecap="round" stroke-dasharray="427" stroke-dashoffset="427" />
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
                        <p id="result-score-value" class="text-4xl font-extrabold <?php echo $scoreClass; ?>">0</p>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Total</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="surface-card p-5 shadow-soft">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Passed</p>
                <p class="mt-2 text-3xl font-extrabold text-emerald-600 dark:text-emerald-400"><?php echo count($passedChecks); ?></p>
            </article>
            <article class="surface-card p-5 shadow-soft">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Needs Improvement</p>
                <p class="mt-2 text-3xl font-extrabold text-amber-600 dark:text-amber-400"><?php echo count($warningChecks); ?></p>
            </article>
            <article class="surface-card p-5 shadow-soft">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Critical</p>
                <p class="mt-2 text-3xl font-extrabold text-red-600 dark:text-red-400"><?php echo count($criticalChecks); ?></p>
            </article>
            <article class="surface-card p-5 shadow-soft">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">PageSpeed</p>
                <p class="mt-2 text-3xl font-extrabold <?php echo $pageSpeedScore >= 90 ? 'text-emerald-600 dark:text-emerald-400' : ($pageSpeedScore >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400'); ?>"><?php echo $pageSpeedScore; ?></p>
            </article>
        </section>

        <section class="grid gap-6 xl:grid-cols-3">
            <article class="rounded-2xl border border-emerald-200 bg-emerald-50/80 p-6 shadow-soft backdrop-blur dark:border-emerald-500/30 dark:bg-emerald-500/10">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-emerald-800 dark:text-emerald-300">Passed Section</h3>
                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300"><?php echo count($passedChecks); ?></span>
                </div>
                <div class="space-y-3">
                    <?php if (!empty($passedChecks)): ?>
                        <?php foreach ($passedChecks as $index => $item): ?>
                            <details class="issue-card rounded-xl border border-emerald-200 bg-white/90 dark:border-emerald-500/30 dark:bg-slate-900/70">
                                <summary class="cursor-pointer p-4">
                                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($item['title']); ?></p>
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-300"><?php echo htmlspecialchars($item['summary']); ?></p>
                                </summary>
                                <div class="border-t border-emerald-100 px-4 pb-4 pt-3 text-sm text-slate-700 dark:border-emerald-500/20 dark:text-slate-200">
                                    <?php echo htmlspecialchars($item['suggestion']); ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="rounded-xl border border-dashed border-emerald-300 bg-white/70 p-4 text-sm text-emerald-800 dark:border-emerald-500/30 dark:bg-slate-900/40 dark:text-emerald-300">
                            No passed checks yet. Resolve critical blockers first.
                        </div>
                    <?php endif; ?>
                </div>
            </article>

            <article class="rounded-2xl border border-amber-200 bg-amber-50/85 p-6 shadow-soft backdrop-blur dark:border-amber-500/30 dark:bg-amber-500/10">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-amber-800 dark:text-amber-300">Needs Improvement</h3>
                    <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-300"><?php echo count($warningChecks); ?></span>
                </div>
                <div class="space-y-3">
                    <?php if (!empty($warningChecks)): ?>
                        <?php foreach ($warningChecks as $index => $item): ?>
                            <details class="issue-card rounded-xl border border-amber-200 bg-white/90 dark:border-amber-500/30 dark:bg-slate-900/70">
                                <summary class="cursor-pointer p-4">
                                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($item['title']); ?></p>
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-300"><?php echo htmlspecialchars($item['summary']); ?></p>
                                </summary>
                                <div class="border-t border-amber-100 px-4 pb-4 pt-3 text-sm text-slate-700 dark:border-amber-500/20 dark:text-slate-200">
                                    <?php echo htmlspecialchars($item['suggestion']); ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="rounded-xl border border-dashed border-amber-300 bg-white/70 p-4 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-slate-900/40 dark:text-amber-300">
                            No warning-level items in this report.
                        </div>
                    <?php endif; ?>
                </div>
            </article>

            <article class="rounded-2xl border border-red-200 bg-red-50/85 p-6 shadow-soft backdrop-blur dark:border-red-500/30 dark:bg-red-500/10">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-red-800 dark:text-red-300">Critical Issues</h3>
                    <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700 dark:bg-red-500/20 dark:text-red-300"><?php echo count($criticalChecks); ?></span>
                </div>
                <div class="space-y-3">
                    <?php if (!empty($criticalChecks)): ?>
                        <?php foreach ($criticalChecks as $index => $item): ?>
                            <details class="issue-card rounded-xl border border-red-200 bg-white/90 dark:border-red-500/30 dark:bg-slate-900/70">
                                <summary class="cursor-pointer p-4">
                                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($item['title']); ?></p>
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-300"><?php echo htmlspecialchars($item['summary']); ?></p>
                                </summary>
                                <div class="border-t border-red-100 px-4 pb-4 pt-3 text-sm text-slate-700 dark:border-red-500/20 dark:text-slate-200">
                                    <?php echo htmlspecialchars($item['suggestion']); ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="rounded-xl border border-dashed border-red-300 bg-white/70 p-4 text-sm text-red-800 dark:border-red-500/30 dark:bg-slate-900/40 dark:text-red-300">
                            No critical blockers found in this run.
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        </section>

        <?php if (!empty($recommendations)): ?>
            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-bold text-slate-900 dark:text-slate-100">Optimization Recommendations</h3>
                    <span class="rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-200"><?php echo count($recommendations); ?> items</span>
                </div>
                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    <?php foreach ($recommendations as $recommendation): ?>
                        <?php
                            $type = strtolower((string) ($recommendation['type'] ?? 'warning'));
                            $chipClass = $type === 'critical'
                                ? 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300'
                                : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300';
                        ?>
                        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-bold text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars((string) ($recommendation['category'] ?? 'SEO')); ?></p>
                                <span class="rounded-full px-3 py-1 text-xs font-semibold <?php echo $chipClass; ?>"><?php echo strtoupper($type); ?></span>
                            </div>
                            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars((string) ($recommendation['message'] ?? '')); ?></p>
                            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400"><?php echo htmlspecialchars((string) ($recommendation['action'] ?? '')); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <script>
        (function () {
            var themeButton = document.getElementById('theme-toggle');
            themeButton && themeButton.addEventListener('click', function () {
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('seo-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
            });

            var progressCircle = document.getElementById('result-score-progress');
            var scoreValue = document.getElementById('result-score-value');

            if (progressCircle && scoreValue) {
                var radius = Number(progressCircle.getAttribute('r')) || 68;
                var circumference = 2 * Math.PI * radius;
                var target = Number(progressCircle.dataset.score || 0);
                target = Math.max(0, Math.min(100, target));
                progressCircle.style.strokeDasharray = String(circumference);
                progressCircle.style.strokeDashoffset = String(circumference);

                var start = null;
                var duration = 1250;

                function animate(now) {
                    if (!start) {
                        start = now;
                    }

                    var progress = Math.min((now - start) / duration, 1);
                    var eased = 1 - Math.pow(1 - progress, 3);
                    var currentScore = Math.round(target * eased);
                    var offset = circumference * (1 - (target * eased) / 100);

                    scoreValue.textContent = String(currentScore);
                    progressCircle.style.strokeDashoffset = String(offset);

                    if (progress < 1) {
                        requestAnimationFrame(animate);
                    }
                }

                requestAnimationFrame(animate);
            }
        })();
    </script>
</body>
</html>
