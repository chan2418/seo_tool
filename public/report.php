<?php
session_start();
require_once __DIR__ . '/../services/PlanEnforcementService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';

$auth = AuthMiddleware::requireLogin(false);
AccountStatusMiddleware::ensureActive(false);

require_once __DIR__ . '/../services/ReportService.php';

$userName = (string) ($auth['user_name'] ?? ($_SESSION['user_name'] ?? 'User'));
$userId = (int) ($auth['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$planService = new PlanEnforcementService();
$planType = $planService->getEffectivePlan($userId, (string) ($_SESSION['plan_type'] ?? 'free'));
$planLabel = ucfirst($planType);
$isAgency = $planType === 'agency';

$error = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service = new ReportService();
    $result = $service->generateWhiteLabelPdf($userId, $planType, [
        'primary_domain' => (string) ($_POST['primary_domain'] ?? ''),
        'competitor_domain' => (string) ($_POST['competitor_domain'] ?? ''),
        'report_title' => (string) ($_POST['report_title'] ?? ''),
        'logo_file' => (string) ($_POST['logo_file'] ?? ''),
    ]);

    if (!empty($result['success'])) {
        $filename = (string) ($result['filename'] ?? 'seo-report.pdf');
        $content = (string) ($result['content'] ?? '');

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    $error = (string) ($result['error'] ?? 'Unable to generate report.');
}

$logosDir = __DIR__ . '/assets/logos';
$logoFiles = [];
if (is_dir($logosDir)) {
    $items = scandir($logosDir);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (preg_match('/\.(png|jpg|jpeg)$/i', $item)) {
                $logoFiles[] = $item;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>White-label Reports - SEO SaaS</title>
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
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="lg:pl-72">
        <header class="sticky top-0 z-20 border-b border-white/60 bg-slate-100/80 px-4 py-4 backdrop-blur-xl dark:border-slate-700 dark:bg-slate-950/70 sm:px-6 lg:px-10">
            <div class="flex items-center justify-between"><div class="flex items-center gap-3"><button id="sidebar-open" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 lg:hidden" aria-label="Open sidebar"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg></button><div><p class="text-xs uppercase tracking-[0.2em] text-slate-500">Agency Deliverables</p><h1 class="text-xl font-bold">White-label PDF Reports</h1></div></div><div class="flex items-center gap-3"><button id="theme-toggle" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">🌓</button><span class="rounded-xl px-3 py-2 text-xs font-bold <?php echo $isAgency ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'; ?>"><?php echo htmlspecialchars($planLabel); ?></span><span class="hidden text-sm font-semibold sm:block"><?php echo htmlspecialchars($userName); ?></span></div></div>
        </header>

        <main class="space-y-6 px-4 pb-10 pt-6 sm:px-6 lg:px-10">
            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="grid gap-5 lg:grid-cols-[1.1fr_0.9fr]"><div><h2 class="text-2xl font-extrabold">Generate branded SEO report</h2><p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Agency reports include SEO overview, competitor comparison, backlink summary, and recommendations.</p></div><div class="rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 p-5 text-white"><p class="text-xs uppercase tracking-[0.2em] text-indigo-100">Export Engine</p><p class="mt-2 text-lg font-bold">TCPDF</p><p class="mt-2 text-xs text-indigo-100">Place your logo files in <code>public/assets/logos</code>.</p></div></div>

                <?php if ($error !== ''): ?>
                    <div class="mt-4 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if (!$isAgency): ?>
                    <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-medium text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">White-label reporting requires the Agency plan.</div>
                <?php endif; ?>

                <form method="POST" class="mt-6 grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-sm font-semibold">Report Title</label>
                        <input name="report_title" type="text" maxlength="120" value="SEO Performance Report" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold">Primary Domain</label>
                        <input name="primary_domain" type="text" maxlength="100" placeholder="example.com" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold">Competitor Domain (optional)</label>
                        <input name="competitor_domain" type="text" maxlength="100" placeholder="competitor.com" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold">Logo File (optional)</label>
                        <input name="logo_file" list="logo-options" placeholder="logo.png" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30">
                        <datalist id="logo-options">
                            <?php foreach ($logoFiles as $logoFile): ?>
                                <option value="<?php echo htmlspecialchars($logoFile); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="md:col-span-2">
                        <button type="submit" class="rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 px-6 py-3 text-sm font-semibold text-white shadow-soft transition hover:opacity-90" <?php echo $isAgency ? '' : 'disabled'; ?>>Download Branded PDF</button>
                    </div>
                </form>
            </section>

            <section class="surface-card p-6 shadow-soft sm:p-8">
                <h3 class="text-lg font-bold">White-label Checklist</h3>
                <ul class="mt-3 space-y-2 text-sm text-slate-600 dark:text-slate-300">
                    <li>- Add your client logo into <code>public/assets/logos</code>.</li>
                    <li>- Use meaningful report titles for each client project.</li>
                    <li>- Include competitor domain for side-by-side benchmark section.</li>
                    <li>- Ensure TCPDF is installed under <code>vendor/</code> before export.</li>
                </ul>
            </section>
        </main>
    </div>

    <script>
        document.getElementById('theme-toggle').addEventListener('click', function () {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('seo-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        });
    </script>
</body>
</html>
