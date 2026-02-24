<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../admin/DashboardController.php';

RoleMiddleware::requirePermission('admin.dashboard.view', false);

$controller = new DashboardController($adminControlService);
$data = $controller->index();
$metrics = (array) ($data['metrics'] ?? []);
$revenueTrend = (array) ($data['revenue_trend'] ?? []);
$dbHealth = (array) ($data['db_health'] ?? []);
$cronLogs = (array) ($data['cron_logs'] ?? []);
$systemLogs = (array) ($data['system_logs'] ?? []);
$auditLogs = (array) ($data['audit_logs'] ?? []);
$apiUsageSummary = (array) ($data['api_usage_summary'] ?? []);

$activePage = 'dashboard';
$pageTitle = 'Admin Dashboard';
include __DIR__ . '/includes/nav.php';
?>

<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Total Users</p>
        <p class="mt-2 text-3xl font-extrabold text-white"><?php echo (int) ($metrics['total_users'] ?? 0); ?></p>
    </article>
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Active Subscriptions</p>
        <p class="mt-2 text-3xl font-extrabold text-emerald-300"><?php echo (int) ($metrics['active_subscriptions'] ?? 0); ?></p>
    </article>
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">MRR</p>
        <p class="mt-2 text-3xl font-extrabold text-indigo-300"><?php echo format_inr((float) ($metrics['mrr'] ?? 0)); ?></p>
    </article>
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">ARPU</p>
        <p class="mt-2 text-3xl font-extrabold text-amber-300"><?php echo format_inr((float) ($metrics['arpu'] ?? 0)); ?></p>
    </article>
</section>

<section class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Signups Today</p>
        <p class="mt-2 text-2xl font-bold text-white"><?php echo (int) ($metrics['new_signups_today'] ?? 0); ?></p>
    </article>
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Signups This Week</p>
        <p class="mt-2 text-2xl font-bold text-white"><?php echo (int) ($metrics['new_signups_week'] ?? 0); ?></p>
    </article>
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Churn Rate</p>
        <p class="mt-2 text-2xl font-bold text-red-300"><?php echo number_format((float) ($metrics['churn_rate'] ?? 0), 2); ?>%</p>
    </article>
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">GSC Connections</p>
        <p class="mt-2 text-2xl font-bold text-cyan-300"><?php echo (int) ($metrics['connected_gsc_accounts'] ?? 0); ?></p>
    </article>
</section>

<section class="mt-4 grid gap-4 xl:grid-cols-[2fr_1fr]">
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-bold text-white">Revenue Trend</h2>
            <span class="rounded-lg border border-slate-700 px-2 py-1 text-xs text-slate-300">Last 6 months</span>
        </div>
        <div class="h-72">
            <canvas id="revenueTrendChart"></canvas>
        </div>
    </article>

    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="text-lg font-bold text-white">Infrastructure Health</h2>
        <div class="mt-4 space-y-3 text-sm">
            <div class="flex items-center justify-between rounded-xl border border-slate-800 px-3 py-2">
                <span class="text-slate-300">Database</span>
                <span class="font-semibold <?php echo (($dbHealth['status'] ?? '') === 'ok') ? 'text-emerald-300' : 'text-red-300'; ?>">
                    <?php echo htmlspecialchars(strtoupper((string) ($dbHealth['status'] ?? 'unknown'))); ?>
                </span>
            </div>
            <div class="flex items-center justify-between rounded-xl border border-slate-800 px-3 py-2">
                <span class="text-slate-300">DB Latency</span>
                <span class="font-semibold text-slate-100"><?php echo (int) ($dbHealth['latency_ms'] ?? 0); ?> ms</span>
            </div>
            <div class="flex items-center justify-between rounded-xl border border-slate-800 px-3 py-2">
                <span class="text-slate-300">API Calls (24h)</span>
                <span class="font-semibold text-slate-100"><?php echo (int) (($metrics['api_usage']['total'] ?? 0)); ?></span>
            </div>
            <div class="flex items-center justify-between rounded-xl border border-slate-800 px-3 py-2">
                <span class="text-slate-300">Rank API (24h)</span>
                <span class="font-semibold text-slate-100"><?php echo (int) (($metrics['api_usage']['rank_api'] ?? 0)); ?></span>
            </div>
            <div class="flex items-center justify-between rounded-xl border border-slate-800 px-3 py-2">
                <span class="text-slate-300">GSC API (24h)</span>
                <span class="font-semibold text-slate-100"><?php echo (int) (($metrics['api_usage']['gsc_api'] ?? 0)); ?></span>
            </div>
        </div>
    </article>
</section>

<section class="mt-4 grid gap-4 xl:grid-cols-2">
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="mb-3 text-lg font-bold text-white">Recent Audit Actions</h2>
        <div class="max-h-80 overflow-auto rounded-xl border border-slate-800">
            <table class="w-full text-sm">
                <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                    <tr>
                        <th class="px-3 py-2 text-left">Time</th>
                        <th class="px-3 py-2 text-left">Action</th>
                        <th class="px-3 py-2 text-left">Actor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($auditLogs)): ?>
                        <tr><td colspan="3" class="px-3 py-4 text-center text-slate-500">No audit events yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($auditLogs as $log): ?>
                            <tr class="border-t border-slate-800">
                                <td class="px-3 py-2 text-slate-300"><?php echo htmlspecialchars((string) ($log['created_at'] ?? '')); ?></td>
                                <td class="px-3 py-2 text-slate-100"><?php echo htmlspecialchars((string) ($log['action_type'] ?? '')); ?></td>
                                <td class="px-3 py-2 text-slate-400"><?php echo htmlspecialchars((string) ($log['actor_email'] ?? 'system')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="mb-3 text-lg font-bold text-white">Recent Errors & Warnings</h2>
        <div class="max-h-80 overflow-auto rounded-xl border border-slate-800">
            <table class="w-full text-sm">
                <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                    <tr>
                        <th class="px-3 py-2 text-left">Time</th>
                        <th class="px-3 py-2 text-left">Level</th>
                        <th class="px-3 py-2 text-left">Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($systemLogs)): ?>
                        <tr><td colspan="3" class="px-3 py-4 text-center text-slate-500">No system logs found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($systemLogs as $log): ?>
                            <?php $level = strtolower((string) ($log['level'] ?? 'info')); ?>
                            <tr class="border-t border-slate-800">
                                <td class="px-3 py-2 text-slate-300"><?php echo htmlspecialchars((string) ($log['created_at'] ?? '')); ?></td>
                                <td class="px-3 py-2 font-semibold <?php echo $level === 'critical' || $level === 'error' ? 'text-red-300' : ($level === 'warning' ? 'text-amber-300' : 'text-cyan-300'); ?>"><?php echo htmlspecialchars(strtoupper($level)); ?></td>
                                <td class="px-3 py-2 text-slate-200"><?php echo htmlspecialchars((string) ($log['message'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<section class="mt-4 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
    <h2 class="mb-3 text-lg font-bold text-white">Cron Executions</h2>
    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <?php if (empty($cronLogs)): ?>
            <p class="text-sm text-slate-400">No cron logs yet.</p>
        <?php else: ?>
            <?php foreach ($cronLogs as $log): ?>
                <?php $status = strtolower((string) ($log['run_status'] ?? 'success')); ?>
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-3 text-sm">
                    <p class="font-semibold text-slate-200"><?php echo htmlspecialchars((string) ($log['cron_name'] ?? 'cron')); ?></p>
                    <p class="mt-1 text-xs <?php echo $status === 'failed' ? 'text-red-300' : ($status === 'warning' ? 'text-amber-300' : 'text-emerald-300'); ?>"><?php echo htmlspecialchars(strtoupper($status)); ?></p>
                    <p class="mt-1 text-xs text-slate-400">Started: <?php echo htmlspecialchars((string) ($log['started_at'] ?? '')); ?></p>
                    <p class="mt-1 text-xs text-slate-400">Duration: <?php echo (int) ($log['duration_ms'] ?? 0); ?> ms</p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<script>
(function () {
    var trend = <?php echo json_encode($revenueTrend); ?>;
    var labels = trend.map(function (row) { return row.month; });
    var values = trend.map(function (row) { return Number(row.revenue || 0); });
    var inrFormatter = new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: 'INR',
        maximumFractionDigits: 0
    });
    var ctx = document.getElementById('revenueTrendChart');
    if (!ctx || typeof Chart === 'undefined') {
        return;
    }

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'MRR (INR)',
                data: values,
                borderColor: '#818CF8',
                backgroundColor: 'rgba(129, 140, 248, 0.18)',
                fill: true,
                tension: 0.32,
                borderWidth: 2
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            return inrFormatter.format(Number(context.parsed.y || 0));
                        }
                    }
                }
            },
            scales: {
                x: { ticks: { color: '#CBD5E1' }, grid: { color: 'rgba(148,163,184,0.15)' } },
                y: {
                    ticks: {
                        color: '#CBD5E1',
                        callback: function (value) {
                            return inrFormatter.format(Number(value || 0));
                        }
                    },
                    grid: { color: 'rgba(148,163,184,0.15)' }
                }
            }
        }
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
