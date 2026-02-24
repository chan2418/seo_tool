<?php
require_once __DIR__ . '/includes/bootstrap.php';

RoleMiddleware::requirePermission('admin.users.view', false);

$userId = max(1, (int) ($_GET['user_id'] ?? 0));
$data = $adminControlService->getUserActivityData($userId);
$user = (array) ($data['user'] ?? []);
$activityLogs = (array) ($data['activity_logs'] ?? []);
$usageSummary = (array) ($data['usage_summary'] ?? []);

$activePage = 'users';
$pageTitle = 'User Activity';
include __DIR__ . '/includes/nav.php';
?>

<section class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-lg font-bold text-white"><?php echo htmlspecialchars((string) ($user['name'] ?? 'Unknown User')); ?></h2>
            <p class="text-sm text-slate-400"><?php echo htmlspecialchars((string) ($user['email'] ?? '')); ?> | ID: <?php echo $userId; ?></p>
        </div>
        <a href="users.php" class="rounded-xl border border-slate-700 px-3 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-800">Back to Users</a>
    </div>
</section>

<section class="mt-4 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
    <h3 class="text-sm font-semibold uppercase tracking-[0.14em] text-slate-400">Usage Summary (30 days)</h3>
    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        <?php if (empty($usageSummary)): ?>
            <p class="text-sm text-slate-500">No usage data available.</p>
        <?php else: ?>
            <?php foreach ($usageSummary as $metric => $total): ?>
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-3">
                    <p class="text-xs uppercase tracking-[0.12em] text-slate-500"><?php echo htmlspecialchars((string) $metric); ?></p>
                    <p class="mt-1 text-lg font-bold text-white"><?php echo (int) $total; ?></p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<section class="mt-4 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
    <h3 class="mb-3 text-sm font-semibold uppercase tracking-[0.14em] text-slate-400">Activity Logs</h3>
    <div class="max-h-96 overflow-auto rounded-xl border border-slate-800">
        <table class="w-full text-sm">
            <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                <tr>
                    <th class="px-3 py-2 text-left">Time</th>
                    <th class="px-3 py-2 text-left">Action</th>
                    <th class="px-3 py-2 text-left">IP</th>
                    <th class="px-3 py-2 text-left">User Agent</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($activityLogs)): ?>
                    <tr><td colspan="4" class="px-3 py-4 text-center text-slate-500">No activity logs found.</td></tr>
                <?php else: ?>
                    <?php foreach ($activityLogs as $log): ?>
                        <tr class="border-t border-slate-800">
                            <td class="px-3 py-2 text-slate-300"><?php echo htmlspecialchars((string) ($log['created_at'] ?? '')); ?></td>
                            <td class="px-3 py-2 text-slate-200"><?php echo htmlspecialchars((string) ($log['action_type'] ?? '')); ?></td>
                            <td class="px-3 py-2 text-slate-400"><?php echo htmlspecialchars((string) ($log['ip_address'] ?? '-')); ?></td>
                            <td class="px-3 py-2 text-slate-500"><?php echo htmlspecialchars((string) ($log['user_agent'] ?? '-')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
