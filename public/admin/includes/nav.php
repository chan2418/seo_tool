<?php
$activePage = $activePage ?? 'dashboard';
$pageTitle = $pageTitle ?? 'Admin';
$navRole = strtolower((string) ($adminRole ?? 'admin'));

$menu = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard.php', 'permission' => 'admin.dashboard.view'],
    ['key' => 'users', 'label' => 'Users', 'href' => 'users.php', 'permission' => 'admin.users.view'],
    ['key' => 'subscriptions', 'label' => 'Subscriptions', 'href' => 'subscriptions.php', 'permission' => 'admin.subscriptions.view'],
    ['key' => 'revenue', 'label' => 'Revenue', 'href' => 'revenue.php', 'permission' => 'admin.revenue.view'],
    ['key' => 'system', 'label' => 'System Logs', 'href' => 'system.php', 'permission' => 'admin.system.view'],
    ['key' => 'security', 'label' => 'Security', 'href' => 'security.php', 'permission' => 'admin.security.view'],
    ['key' => 'plans', 'label' => 'Plans', 'href' => 'plans.php', 'permission' => 'admin.plans.manage'],
    ['key' => 'feature_flags', 'label' => 'Feature Flags', 'href' => 'feature-flags.php', 'permission' => 'admin.feature_flags.manage'],
];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Admin Control Center</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .admin-link.active {
            background: linear-gradient(135deg, #4F46E5 0%, #6366F1 100%);
            color: #fff;
            box-shadow: 0 14px 30px -18px rgba(79, 70, 229, 0.75);
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
<div class="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(99,102,241,0.25),_rgba(15,23,42,0.95)_40%)]">
    <div class="mx-auto flex w-full max-w-[1600px] gap-4 px-4 py-4 sm:px-6 lg:px-8">
        <aside class="sticky top-4 hidden h-[calc(100vh-2rem)] w-72 shrink-0 rounded-2xl border border-slate-800 bg-slate-900/80 p-4 shadow-2xl lg:block">
            <div class="mb-6 rounded-xl bg-gradient-to-br from-indigo-600 to-indigo-500 p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-100">SEO Suite</p>
                <p class="mt-1 text-xl font-bold text-white">Admin Core</p>
            </div>
            <nav class="space-y-1 text-sm">
                <?php foreach ($menu as $item): ?>
                    <?php if (!RoleMiddleware::hasPermission($navRole, (string) ($item['permission'] ?? ''))): ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <?php $isActive = $activePage === (string) ($item['key'] ?? ''); ?>
                    <a href="<?php echo htmlspecialchars((string) ($item['href'] ?? '#')); ?>" class="admin-link flex items-center justify-between rounded-xl px-3 py-2.5 transition hover:bg-slate-800 <?php echo $isActive ? 'active' : 'text-slate-300'; ?>">
                        <span><?php echo htmlspecialchars((string) ($item['label'] ?? '')); ?></span>
                        <?php if ($isActive): ?><span class="text-xs text-indigo-100">Live</span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="mt-6 space-y-2 border-t border-slate-800 pt-4 text-xs text-slate-400">
                <p>Signed in as <span class="font-semibold text-slate-200"><?php echo htmlspecialchars((string) ($adminUserName ?? 'Admin')); ?></span></p>
                <p>Role: <span class="font-semibold text-indigo-300"><?php echo htmlspecialchars(admin_role_label((string) ($adminRole ?? 'admin'))); ?></span></p>
                <div class="pt-2">
                    <a href="../dashboard.php" class="inline-flex w-full items-center justify-center rounded-lg border border-slate-700 px-3 py-2 font-semibold text-slate-200 hover:bg-slate-800">Switch to User App</a>
                </div>
                <div class="pt-1">
                    <a href="../logout.php" class="inline-flex w-full items-center justify-center rounded-lg border border-red-500/40 px-3 py-2 font-semibold text-red-300 hover:bg-red-500/10">Logout</a>
                </div>
            </div>
        </aside>
        <div class="min-w-0 flex-1">
            <header class="mb-4 rounded-2xl border border-slate-800 bg-slate-900/70 px-5 py-4 shadow-lg">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Enterprise Admin Panel</p>
                        <h1 class="text-xl font-bold text-white sm:text-2xl"><?php echo htmlspecialchars($pageTitle); ?></h1>
                    </div>
                    <div class="rounded-xl border border-indigo-400/30 bg-indigo-500/10 px-3 py-2 text-xs font-semibold text-indigo-200">
                        <?php echo htmlspecialchars(admin_role_label((string) ($adminRole ?? 'admin'))); ?> access
                    </div>
                </div>
            </header>
            <nav class="mb-4 grid gap-2 lg:hidden">
                <?php foreach ($menu as $item): ?>
                    <?php if (!RoleMiddleware::hasPermission($navRole, (string) ($item['permission'] ?? ''))): ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <?php $isActive = $activePage === (string) ($item['key'] ?? ''); ?>
                    <a href="<?php echo htmlspecialchars((string) ($item['href'] ?? '#')); ?>" class="rounded-xl border border-slate-800 px-3 py-2 text-sm font-semibold <?php echo $isActive ? 'bg-indigo-600 text-white' : 'bg-slate-900/70 text-slate-200'; ?>">
                        <?php echo htmlspecialchars((string) ($item['label'] ?? '')); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
