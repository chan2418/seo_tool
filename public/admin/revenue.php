<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../admin/RevenueController.php';

RoleMiddleware::requirePermission('admin.revenue.view', false);

$controller = new RevenueController($adminControlService);
$data = $controller->index();
$metrics = (array) ($data['metrics'] ?? []);
$revenueTrend = (array) ($data['revenue_trend'] ?? []);
$paymentLogs = (array) ($data['payment_logs'] ?? []);
$plans = (array) ($data['plans'] ?? []);

$activePage = 'revenue';
$pageTitle = 'Revenue Analytics';
include __DIR__ . '/includes/nav.php';
?>

<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">MRR</p>
        <p class="mt-2 text-3xl font-extrabold text-indigo-300"><?php echo format_inr((float) ($metrics['mrr'] ?? 0)); ?></p>
    </article>
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">ARPU</p>
        <p class="mt-2 text-3xl font-extrabold text-amber-300"><?php echo format_inr((float) ($metrics['arpu'] ?? 0)); ?></p>
    </article>
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Churn Rate</p>
        <p class="mt-2 text-3xl font-extrabold text-red-300"><?php echo number_format((float) ($metrics['churn_rate'] ?? 0), 2); ?>%</p>
    </article>
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Active Subscriptions</p>
        <p class="mt-2 text-3xl font-extrabold text-emerald-300"><?php echo (int) ($metrics['active_subscriptions'] ?? 0); ?></p>
    </article>
</section>

<section class="mt-4 grid gap-4 xl:grid-cols-[2fr_1fr]">
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="mb-3 text-lg font-bold text-white">Revenue Trend (12 Months)</h2>
        <div class="h-80"><canvas id="revenueAnalyticsChart"></canvas></div>
    </article>
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="mb-3 text-lg font-bold text-white">Revenue by Plan</h2>
        <ul class="space-y-2 text-sm">
            <li class="flex justify-between rounded-lg border border-slate-800 px-3 py-2"><span>Free</span><strong><?php echo format_inr((float) ($metrics['revenue_by_plan']['free'] ?? 0)); ?></strong></li>
            <li class="flex justify-between rounded-lg border border-slate-800 px-3 py-2"><span>Pro</span><strong><?php echo format_inr((float) ($metrics['revenue_by_plan']['pro'] ?? 0)); ?></strong></li>
            <li class="flex justify-between rounded-lg border border-slate-800 px-3 py-2"><span>Agency</span><strong><?php echo format_inr((float) ($metrics['revenue_by_plan']['agency'] ?? 0)); ?></strong></li>
            <li class="flex justify-between rounded-lg border border-indigo-500/50 bg-indigo-500/10 px-3 py-2 text-indigo-200"><span>Total</span><strong><?php echo format_inr((float) ($metrics['revenue_by_plan']['total'] ?? 0)); ?></strong></li>
        </ul>

        <h3 class="mt-5 mb-2 text-sm font-semibold uppercase tracking-[0.14em] text-slate-400">Pricing Config</h3>
        <ul class="space-y-2 text-xs text-slate-300">
            <?php foreach ($plans as $plan): ?>
                <li class="flex justify-between rounded-lg border border-slate-800 px-3 py-2">
                    <span><?php echo htmlspecialchars(strtoupper((string) ($plan['plan_code'] ?? ''))); ?></span>
                    <span><?php echo format_inr((float) ($plan['price_monthly'] ?? 0)); ?>/mo</span>
                </li>
            <?php endforeach; ?>
        </ul>
    </article>
</section>

<section class="mt-4 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
    <h2 class="mb-3 text-lg font-bold text-white">Recent Payment Events</h2>
    <div class="max-h-96 overflow-auto rounded-xl border border-slate-800">
        <table class="w-full text-sm">
            <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                <tr>
                    <th class="px-3 py-2 text-left">Time</th>
                    <th class="px-3 py-2 text-left">Gateway</th>
                    <th class="px-3 py-2 text-left">Event</th>
                    <th class="px-3 py-2 text-left">Amount</th>
                    <th class="px-3 py-2 text-left">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($paymentLogs)): ?>
                    <tr><td colspan="5" class="px-3 py-4 text-center text-slate-500">No payment logs available.</td></tr>
                <?php else: ?>
                    <?php foreach ($paymentLogs as $log): ?>
                        <tr class="border-t border-slate-800">
                            <td class="px-3 py-2 text-slate-300"><?php echo htmlspecialchars((string) ($log['created_at'] ?? '')); ?></td>
                            <td class="px-3 py-2 text-slate-200"><?php echo htmlspecialchars(strtoupper((string) ($log['gateway'] ?? ''))); ?></td>
                            <td class="px-3 py-2 text-slate-200"><?php echo htmlspecialchars((string) ($log['event_type'] ?? '')); ?></td>
                            <td class="px-3 py-2 text-slate-200"><?php echo format_currency_amount((float) ($log['amount'] ?? 0), (string) ($log['currency'] ?? 'INR')); ?></td>
                            <td class="px-3 py-2 text-slate-200"><?php echo htmlspecialchars(strtoupper((string) ($log['payment_status'] ?? 'pending'))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
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
    var ctx = document.getElementById('revenueAnalyticsChart');
    if (!ctx || typeof Chart === 'undefined') {
        return;
    }

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Revenue (INR)',
                data: values,
                backgroundColor: 'rgba(129, 140, 248, 0.45)',
                borderColor: '#818CF8',
                borderWidth: 1,
                borderRadius: 8
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
                x: { ticks: { color: '#CBD5E1' }, grid: { color: 'rgba(148,163,184,0.12)' } },
                y: {
                    ticks: {
                        color: '#CBD5E1',
                        callback: function (value) {
                            return inrFormatter.format(Number(value || 0));
                        }
                    },
                    grid: { color: 'rgba(148,163,184,0.12)' }
                }
            }
        }
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
