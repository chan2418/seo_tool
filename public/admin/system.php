<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../admin/SystemController.php';

RoleMiddleware::requirePermission('admin.system.view', false);

$controller = new SystemController($adminControlService);
$data = $controller->index();
$dbHealth = (array) ($data['db_health'] ?? []);
$cronLogs = (array) ($data['cron_logs'] ?? []);
$systemLogs = (array) ($data['system_logs'] ?? []);
$apiUsage = (array) ($data['api_usage_summary'] ?? []);
$auditLogs = (array) ($data['audit_logs'] ?? []);
$aiQueueStats = (array) ($data['ai_queue_stats'] ?? []);
$aiCostSummary = (array) ($data['ai_cost_summary'] ?? []);
$aiUsageByUser = (array) ($data['ai_usage_by_user'] ?? []);
$aiMonth = (string) ($data['ai_month'] ?? date('Y-m'));

$activePage = 'system';
$pageTitle = 'System Monitoring';
include __DIR__ . '/includes/nav.php';
?>

<section class="grid gap-4 md:grid-cols-3">
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <p class="text-xs uppercase tracking-[0.18em] text-slate-400">Database Status</p>
        <p class="mt-2 text-2xl font-bold <?php echo (($dbHealth['status'] ?? '') === 'ok') ? 'text-emerald-300' : 'text-red-300'; ?>">
            <?php echo htmlspecialchars(strtoupper((string) ($dbHealth['status'] ?? 'unknown'))); ?>
        </p>
        <p class="mt-2 text-xs text-slate-400">Latency: <?php echo (int) ($dbHealth['latency_ms'] ?? 0); ?> ms</p>
    </article>
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <p class="text-xs uppercase tracking-[0.18em] text-slate-400">Cron Executions</p>
        <p class="mt-2 text-2xl font-bold text-indigo-300"><?php echo count($cronLogs); ?></p>
        <p class="mt-2 text-xs text-slate-400">Recent job runs tracked</p>
    </article>
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <p class="text-xs uppercase tracking-[0.18em] text-slate-400">System Log Entries</p>
        <p class="mt-2 text-2xl font-bold text-amber-300"><?php echo count($systemLogs); ?></p>
        <p class="mt-2 text-xs text-slate-400">Recent app and API events</p>
    </article>
</section>

<section class="mt-4 grid gap-4 xl:grid-cols-2">
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="mb-3 text-lg font-bold text-white">API Usage (Last 30 Days)</h2>
        <div class="overflow-x-auto rounded-xl border border-slate-800">
            <table class="w-full text-sm">
                <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                    <tr>
                        <th class="px-3 py-2 text-left">Provider</th>
                        <th class="px-3 py-2 text-left">Calls</th>
                        <th class="px-3 py-2 text-left">Units</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($apiUsage)): ?>
                        <tr><td colspan="3" class="px-3 py-4 text-center text-slate-500">No API usage rows available.</td></tr>
                    <?php else: ?>
                        <?php foreach ($apiUsage as $row): ?>
                            <tr class="border-t border-slate-800">
                                <td class="px-3 py-2 text-slate-200"><?php echo htmlspecialchars((string) ($row['provider'] ?? '')); ?></td>
                                <td class="px-3 py-2 text-slate-300"><?php echo (int) ($row['calls'] ?? 0); ?></td>
                                <td class="px-3 py-2 text-slate-300"><?php echo (int) ($row['total_units'] ?? 0); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="mb-3 text-lg font-bold text-white">Recent Cron Logs</h2>
        <div class="max-h-80 overflow-auto rounded-xl border border-slate-800">
            <table class="w-full text-sm">
                <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                    <tr>
                        <th class="px-3 py-2 text-left">Cron</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Started</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cronLogs)): ?>
                        <tr><td colspan="3" class="px-3 py-4 text-center text-slate-500">No cron entries available.</td></tr>
                    <?php else: ?>
                        <?php foreach ($cronLogs as $log): ?>
                            <?php $status = strtolower((string) ($log['run_status'] ?? 'success')); ?>
                            <tr class="border-t border-slate-800">
                                <td class="px-3 py-2 text-slate-200"><?php echo htmlspecialchars((string) ($log['cron_name'] ?? '')); ?></td>
                                <td class="px-3 py-2 <?php echo $status === 'failed' ? 'text-red-300' : ($status === 'warning' ? 'text-amber-300' : 'text-emerald-300'); ?>"><?php echo htmlspecialchars(strtoupper($status)); ?></td>
                                <td class="px-3 py-2 text-slate-400"><?php echo htmlspecialchars((string) ($log['started_at'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<section class="mt-4 grid gap-4 xl:grid-cols-[1fr_2fr]">
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="mb-3 text-lg font-bold text-white">AI Queue Status</h2>
        <div class="space-y-2 text-sm">
            <div class="flex items-center justify-between rounded-xl border border-slate-800 px-3 py-2">
                <span class="text-slate-300">Pending</span>
                <span class="font-semibold text-amber-300"><?php echo (int) ($aiQueueStats['pending'] ?? 0); ?></span>
            </div>
            <div class="flex items-center justify-between rounded-xl border border-slate-800 px-3 py-2">
                <span class="text-slate-300">Processing</span>
                <span class="font-semibold text-indigo-300"><?php echo (int) ($aiQueueStats['processing'] ?? 0); ?></span>
            </div>
            <div class="flex items-center justify-between rounded-xl border border-slate-800 px-3 py-2">
                <span class="text-slate-300">Completed (today)</span>
                <span class="font-semibold text-emerald-300"><?php echo (int) ($aiQueueStats['completed_today'] ?? 0); ?></span>
            </div>
            <div class="flex items-center justify-between rounded-xl border border-slate-800 px-3 py-2">
                <span class="text-slate-300">Failed (today)</span>
                <span class="font-semibold text-red-300"><?php echo (int) ($aiQueueStats['failed_today'] ?? 0); ?></span>
            </div>
        </div>

        <h3 class="mt-5 text-sm font-semibold uppercase tracking-[0.14em] text-slate-400">AI Cost (<?php echo htmlspecialchars($aiMonth); ?>)</h3>
        <div class="mt-2 space-y-2 text-sm">
            <div class="flex items-center justify-between rounded-xl border border-slate-800 px-3 py-2">
                <span class="text-slate-300">Requests</span>
                <span class="font-semibold text-slate-100"><?php echo (int) ($aiCostSummary['requests_total'] ?? 0); ?></span>
            </div>
            <div class="flex items-center justify-between rounded-xl border border-slate-800 px-3 py-2">
                <span class="text-slate-300">Tokens</span>
                <span class="font-semibold text-slate-100"><?php echo number_format((int) ($aiCostSummary['tokens_total'] ?? 0)); ?></span>
            </div>
            <div class="flex items-center justify-between rounded-xl border border-slate-800 px-3 py-2">
                <span class="text-slate-300">Est. Cost</span>
                <span class="font-semibold text-indigo-300"><?php echo format_inr((float) ($aiCostSummary['cost_total'] ?? 0), 4); ?></span>
            </div>
            <div class="flex items-center justify-between rounded-xl border border-slate-800 px-3 py-2">
                <span class="text-slate-300">Active Users</span>
                <span class="font-semibold text-slate-100"><?php echo (int) ($aiCostSummary['users_active'] ?? 0); ?></span>
            </div>
        </div>
    </article>

    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="mb-3 text-lg font-bold text-white">AI Usage by User (<?php echo htmlspecialchars($aiMonth); ?>)</h2>
        <div class="max-h-96 overflow-auto rounded-xl border border-slate-800">
            <table class="w-full text-sm">
                <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                    <tr>
                        <th class="px-3 py-2 text-left">User</th>
                        <th class="px-3 py-2 text-left">Plan</th>
                        <th class="px-3 py-2 text-left">Requests</th>
                        <th class="px-3 py-2 text-left">Tokens</th>
                        <th class="px-3 py-2 text-left">Cost</th>
                        <th class="px-3 py-2 text-left">Last Request</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($aiUsageByUser)): ?>
                        <tr><td colspan="6" class="px-3 py-4 text-center text-slate-500">No AI usage rows yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($aiUsageByUser as $row): ?>
                            <tr class="border-t border-slate-800">
                                <td class="px-3 py-2 text-slate-200">
                                    <p class="font-semibold"><?php echo htmlspecialchars((string) ($row['name'] ?? 'User')); ?></p>
                                    <p class="text-xs text-slate-400"><?php echo htmlspecialchars((string) ($row['email'] ?? '')); ?></p>
                                </td>
                                <td class="px-3 py-2 text-slate-300"><?php echo htmlspecialchars(strtoupper((string) ($row['plan_type'] ?? 'free'))); ?></td>
                                <td class="px-3 py-2 text-slate-300"><?php echo (int) ($row['request_count'] ?? 0); ?></td>
                                <td class="px-3 py-2 text-slate-300"><?php echo number_format((int) ($row['tokens_used'] ?? 0)); ?></td>
                                <td class="px-3 py-2 text-indigo-300"><?php echo format_inr((float) ($row['cost_estimate'] ?? 0), 4); ?></td>
                                <td class="px-3 py-2 text-slate-400"><?php echo htmlspecialchars((string) ($row['last_request_at'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<section class="mt-4 grid gap-4 xl:grid-cols-2">
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="mb-3 text-lg font-bold text-white">System Error Logs</h2>
        <div class="max-h-96 overflow-auto rounded-xl border border-slate-800">
            <table class="w-full text-sm">
                <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                    <tr>
                        <th class="px-3 py-2 text-left">Time</th>
                        <th class="px-3 py-2 text-left">Level</th>
                        <th class="px-3 py-2 text-left">Source</th>
                        <th class="px-3 py-2 text-left">Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($systemLogs)): ?>
                        <tr><td colspan="4" class="px-3 py-4 text-center text-slate-500">No system logs.</td></tr>
                    <?php else: ?>
                        <?php foreach ($systemLogs as $log): ?>
                            <?php $level = strtolower((string) ($log['level'] ?? 'info')); ?>
                            <tr class="border-t border-slate-800">
                                <td class="px-3 py-2 text-slate-300"><?php echo htmlspecialchars((string) ($log['created_at'] ?? '')); ?></td>
                                <td class="px-3 py-2 <?php echo in_array($level, ['error','critical'], true) ? 'text-red-300' : ($level === 'warning' ? 'text-amber-300' : 'text-cyan-300'); ?>"><?php echo htmlspecialchars(strtoupper($level)); ?></td>
                                <td class="px-3 py-2 text-slate-300"><?php echo htmlspecialchars((string) ($log['source'] ?? '')); ?></td>
                                <td class="px-3 py-2 text-slate-200"><?php echo htmlspecialchars((string) ($log['message'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="mb-3 text-lg font-bold text-white">Audit Trail</h2>
        <div class="max-h-96 overflow-auto rounded-xl border border-slate-800">
            <table class="w-full text-sm">
                <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                    <tr>
                        <th class="px-3 py-2 text-left">Time</th>
                        <th class="px-3 py-2 text-left">Action</th>
                        <th class="px-3 py-2 text-left">Actor</th>
                        <th class="px-3 py-2 text-left">Target</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($auditLogs)): ?>
                        <tr><td colspan="4" class="px-3 py-4 text-center text-slate-500">No audit logs.</td></tr>
                    <?php else: ?>
                        <?php foreach ($auditLogs as $log): ?>
                            <tr class="border-t border-slate-800">
                                <td class="px-3 py-2 text-slate-300"><?php echo htmlspecialchars((string) ($log['created_at'] ?? '')); ?></td>
                                <td class="px-3 py-2 text-slate-200"><?php echo htmlspecialchars((string) ($log['action_type'] ?? '')); ?></td>
                                <td class="px-3 py-2 text-slate-400"><?php echo htmlspecialchars((string) ($log['actor_email'] ?? 'system')); ?></td>
                                <td class="px-3 py-2 text-slate-400"><?php echo htmlspecialchars((string) ($log['target_email'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
