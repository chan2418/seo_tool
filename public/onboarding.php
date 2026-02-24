<?php
session_start();

require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';
require_once __DIR__ . '/../services/OnboardingService.php';

AuthMiddleware::requireLogin(false);
AccountStatusMiddleware::ensureActive(false);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$onboardingService = new OnboardingService();
$checklist = $onboardingService->getChecklist($userId);
$steps = (array) ($checklist['steps'] ?? []);
$summary = (array) ($checklist['summary'] ?? []);

$progress = (int) ($summary['progress_pct'] ?? 0);
$isFinished = !empty($summary['is_finished']);
$userName = (string) ($_SESSION['user_name'] ?? 'User');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Onboarding - SEO Suite</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <main class="mx-auto w-full max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <header class="rounded-2xl bg-white p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Welcome</p>
            <h1 class="mt-2 text-2xl font-extrabold">Hi <?php echo htmlspecialchars($userName); ?>, complete your SEO workspace setup</h1>
            <p class="mt-2 text-sm text-slate-600">Follow these 5 steps to unlock full value from the platform.</p>
            <div class="mt-4 h-3 rounded-full bg-slate-200">
                <div class="h-3 rounded-full bg-gradient-to-r from-indigo-600 to-blue-500" style="width: <?php echo max(0, min(100, $progress)); ?>%"></div>
            </div>
            <p class="mt-2 text-sm font-semibold text-slate-700"><?php echo max(0, min(100, $progress)); ?>% completed</p>
        </header>

        <section class="mt-5 space-y-3">
            <?php foreach ($steps as $step): ?>
                <article class="rounded-2xl bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-bold"><?php echo htmlspecialchars((string) ($step['title'] ?? 'Step')); ?></p>
                            <p class="mt-1 text-sm text-slate-600"><?php echo htmlspecialchars((string) ($step['description'] ?? '')); ?></p>
                        </div>
                        <?php if (!empty($step['is_completed'])): ?>
                            <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Completed</span>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars((string) ($step['url'] ?? 'dashboard.php')); ?>" class="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700">Complete</a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <footer class="mt-6 flex items-center justify-between rounded-2xl bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-600"><?php echo $isFinished ? 'Setup complete. You are ready to scale SEO operations.' : 'You can continue later. Progress saves automatically.'; ?></p>
            <a href="dashboard.php" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Go to Dashboard</a>
        </footer>
    </main>
</body>
</html>

