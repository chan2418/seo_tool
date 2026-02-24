<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/../../models/UserModel.php';
    $sidebarUserModel = new UserModel();
    $sidebarUser = $sidebarUserModel->getUserById((int) $_SESSION['user_id']);
    if (is_array($sidebarUser) && !empty($sidebarUser)) {
        $_SESSION['plan_type'] = strtolower((string) ($sidebarUser['plan_type'] ?? ($_SESSION['plan_type'] ?? 'free')));
        $_SESSION['role'] = strtolower((string) ($sidebarUser['role'] ?? ($_SESSION['role'] ?? 'user')));
        $_SESSION['account_status'] = strtolower((string) ($sidebarUser['status'] ?? ($_SESSION['account_status'] ?? 'active')));
    }
}

$currentPage = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
$currentScriptPath = str_replace('\\', '/', (string) ($_SERVER['PHP_SELF'] ?? ''));
$currentPlan = strtoupper((string) ($planType ?? ($_SESSION['plan_type'] ?? 'free')));
$currentRole = strtolower((string) ($_SESSION['role'] ?? 'user'));

$menuItems = [
    ['href' => 'dashboard.php', 'page' => 'dashboard.php', 'label' => 'Dashboard', 'tag' => 'Live'],
    ['href' => 'alerts.php', 'page' => 'alerts.php', 'label' => 'Alerts', 'tag' => 'Bell'],
    ['href' => 'performance.php', 'page' => 'performance.php', 'label' => 'Performance', 'tag' => 'GSC'],
    ['href' => 'insights.php', 'page' => 'insights.php', 'label' => 'Insights', 'tag' => 'AI'],
    ['href' => 'ai.php', 'page' => 'ai.php', 'label' => 'AI Assistant', 'tag' => 'Copilot'],
    ['href' => 'history.php', 'page' => 'history.php', 'label' => 'Audit History', 'tag' => 'Graph'],
    ['href' => 'keyword.php', 'page' => 'keyword.php', 'label' => 'Keyword Tool', 'tag' => 'Lab'],
    ['href' => 'rank-tracker.php', 'page' => 'rank-tracker.php', 'label' => 'Rank Tracker', 'tag' => 'Daily'],
    ['href' => 'competitor.php', 'page' => 'competitor.php', 'label' => 'Competitor', 'tag' => 'Intel'],
    ['href' => 'backlinks.php', 'page' => 'backlinks.php', 'label' => 'Backlinks', 'tag' => 'Links'],
    ['href' => 'crawl.php', 'page' => 'crawl.php', 'label' => 'Crawler', 'tag' => '10 URLs'],
    ['href' => 'report.php', 'page' => 'report.php', 'label' => 'Reports', 'tag' => 'PDF'],
    ['href' => 'subscription.php', 'page' => 'subscription.php', 'label' => 'Subscription', 'tag' => $currentPlan],
    ['href' => 'settings.php', 'page' => 'settings.php', 'label' => 'Settings', 'tag' => 'Prefs'],
];

if (in_array($currentRole, ['super_admin', 'admin', 'support_admin', 'billing_admin'], true)) {
    $menuItems[] = ['href' => 'admin/dashboard.php', 'page' => 'admin/dashboard.php', 'label' => 'Admin Panel', 'tag' => 'Core'];
}
?>
<style>
    .sidebar-link {
        transition: transform 160ms ease, background-color 160ms ease, color 160ms ease, box-shadow 160ms ease;
    }

    .sidebar-link:hover {
        transform: translateX(2px);
    }

    .sidebar-link.active {
        background: linear-gradient(90deg, #4F46E5 0%, #6366F1 100%);
        color: #ffffff;
        box-shadow: 0 18px 45px -25px rgba(15, 23, 42, 0.35);
    }

    .sidebar-link.active .sidebar-tag {
        color: #E0E7FF;
    }

    .sidebar-scroll {
        scrollbar-width: thin;
        scrollbar-color: rgba(148, 163, 184, 0.65) transparent;
    }

    .sidebar-scroll::-webkit-scrollbar {
        width: 8px;
    }

    .sidebar-scroll::-webkit-scrollbar-track {
        background: transparent;
    }

    .sidebar-scroll::-webkit-scrollbar-thumb {
        background: rgba(148, 163, 184, 0.55);
        border-radius: 999px;
    }

    .dark .sidebar-scroll::-webkit-scrollbar-thumb {
        background: rgba(100, 116, 139, 0.75);
    }
</style>

<aside id="app-sidebar" class="fixed inset-y-0 left-0 z-40 flex h-full w-72 flex-col -translate-x-full border-r border-white/60 bg-white/85 p-6 shadow-soft backdrop-blur-xl transition-transform duration-300 dark:border-slate-700/80 dark:bg-slate-900/85 lg:translate-x-0">
    <div class="mb-8 flex items-center gap-3">
        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-brand-400 text-lg font-bold text-white shadow-soft">S</div>
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500">SEO Suite</p>
            <p class="text-lg font-bold text-slate-900 dark:text-slate-100">Phase 5</p>
        </div>
    </div>

    <div class="flex min-h-0 flex-1 flex-col">
        <nav class="sidebar-scroll min-h-0 flex-1 space-y-2 overflow-y-auto pr-1 text-sm font-semibold">
            <?php foreach ($menuItems as $item): ?>
                <?php
                    $itemPage = (string) ($item['page'] ?? '');
                    $isActive = $currentPage === $itemPage
                        || ($itemPage !== '' && str_ends_with($currentScriptPath, '/' . $itemPage));
                ?>
                <a
                    href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>"
                    class="sidebar-link flex items-center justify-between rounded-xl px-4 py-3 <?php echo $isActive ? 'active' : 'text-slate-600 hover:bg-slate-200/70 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-slate-100'; ?>"
                >
                    <span><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="sidebar-tag text-xs <?php echo $isActive ? 'text-indigo-100' : 'text-slate-400'; ?>">
                        <?php echo htmlspecialchars($item['tag'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </nav>

        <a href="logout.php" class="mt-4 inline-flex w-full items-center justify-center rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-600 transition hover:bg-red-100 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300 dark:hover:bg-red-500/20">
            Logout
        </a>
    </div>
</aside>

<div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-slate-950/40 backdrop-blur-sm lg:hidden"></div>

<script src="assets/js/sidebar.js"></script>
