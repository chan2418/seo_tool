<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../admin/SubscriptionController.php';

RoleMiddleware::requirePermission('admin.subscriptions.view', false);

$controller = new SubscriptionController($adminControlService);
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CsrfMiddleware::requirePostToken('csrf_token', 'admin_subscriptions_csrf', false);

    $action = strtolower(trim((string) ($_POST['action'] ?? '')));
    $needsManage = in_array($action, ['update_status', 'extend_days', 'promo_days', 'lifetime_access'], true);
    $needsRefund = $action === 'refund';

    $allowed = false;
    if ($needsManage && RoleMiddleware::hasPermission($adminRole, 'admin.subscriptions.manage')) {
        $allowed = true;
    }
    if ($needsRefund && RoleMiddleware::hasPermission($adminRole, 'admin.payments.refund')) {
        $allowed = true;
    }

    if (!$allowed) {
        $result = ['success' => false, 'error' => 'You do not have permission for this action.'];
    } else {
        $result = $controller->mutate($adminUserId, $action, $_POST);
    }

    $message = (string) ($result['message'] ?? $result['error'] ?? '');
    $messageType = !empty($result['success']) ? 'success' : 'error';
}

$csrfToken = CsrfMiddleware::generateToken('admin_subscriptions_csrf');

$filters = [
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
    'per_page' => 20,
    'search' => trim((string) ($_GET['search'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'plan' => trim((string) ($_GET['plan'] ?? '')),
];

$data = $controller->index($filters);
$items = (array) ($data['items'] ?? []);
$pagination = (array) ($data['pagination'] ?? []);
$paymentLogs = (array) ($data['payment_logs'] ?? []);

$activePage = 'subscriptions';
$pageTitle = 'Subscription Management';
include __DIR__ . '/includes/nav.php';
?>

<?php if ($message !== ''): ?>
    <div class="mb-4 rounded-xl border px-4 py-3 text-sm font-medium <?php echo $messageType === 'success' ? 'border-emerald-400/40 bg-emerald-500/10 text-emerald-200' : 'border-red-400/40 bg-red-500/10 text-red-200'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<section class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
    <form method="get" class="grid gap-3 md:grid-cols-4">
        <input type="text" name="search" value="<?php echo htmlspecialchars((string) $filters['search']); ?>" placeholder="Search email/name/subscription" class="rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-100 md:col-span-2">
        <select name="status" class="rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-100">
            <option value="">All Status</option>
            <?php foreach (['active','trialing','past_due','canceled','incomplete'] as $status): ?>
                <option value="<?php echo $status; ?>" <?php echo $filters['status'] === $status ? 'selected' : ''; ?>><?php echo strtoupper($status); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="plan" class="rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-100">
            <option value="">All Plans</option>
            <option value="free" <?php echo $filters['plan'] === 'free' ? 'selected' : ''; ?>>Free</option>
            <option value="pro" <?php echo $filters['plan'] === 'pro' ? 'selected' : ''; ?>>Pro</option>
            <option value="agency" <?php echo $filters['plan'] === 'agency' ? 'selected' : ''; ?>>Agency</option>
        </select>
        <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Apply</button>
    </form>
</section>

<section class="mt-4 overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/70">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[1500px] text-sm">
            <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                <tr>
                    <th class="px-3 py-3 text-left">User</th>
                    <th class="px-3 py-3 text-left">Plan</th>
                    <th class="px-3 py-3 text-left">Status</th>
                    <th class="px-3 py-3 text-left">Gateway ID</th>
                    <th class="px-3 py-3 text-left">Billing</th>
                    <th class="px-3 py-3 text-left">Lifetime</th>
                    <th class="px-3 py-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="7" class="px-3 py-5 text-center text-slate-500">No subscriptions found.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $row): ?>
                        <?php
                        $sid = (int) ($row['id'] ?? 0);
                        $status = strtolower((string) ($row['status'] ?? 'incomplete'));
                        ?>
                        <tr class="border-t border-slate-800">
                            <td class="px-3 py-3">
                                <p class="font-semibold text-slate-100"><?php echo htmlspecialchars((string) ($row['name'] ?? '')); ?></p>
                                <p class="text-xs text-slate-400"><?php echo htmlspecialchars((string) ($row['email'] ?? '')); ?></p>
                            </td>
                            <td class="px-3 py-3 text-slate-200"><?php echo htmlspecialchars(strtoupper((string) ($row['plan_type'] ?? 'free'))); ?></td>
                            <td class="px-3 py-3">
                                <span class="rounded-full px-2 py-1 text-xs font-semibold <?php echo in_array($status, ['active','trialing'], true) ? 'bg-emerald-500/20 text-emerald-200' : ($status === 'past_due' ? 'bg-amber-500/20 text-amber-200' : 'bg-slate-700 text-slate-200'); ?>">
                                    <?php echo htmlspecialchars(strtoupper($status)); ?>
                                </span>
                            </td>
                            <td class="px-3 py-3 text-xs text-slate-300"><?php echo htmlspecialchars((string) ($row['razorpay_subscription_id'] ?? '-')); ?></td>
                            <td class="px-3 py-3 text-xs text-slate-400">
                                <p>Next: <?php echo htmlspecialchars((string) ($row['next_billing_date'] ?? '-')); ?></p>
                                <p>Period End: <?php echo htmlspecialchars((string) ($row['current_period_end'] ?? '-')); ?></p>
                                <p>Promo Days: <?php echo (int) ($row['promotional_days'] ?? 0); ?></p>
                            </td>
                            <td class="px-3 py-3 text-slate-200"><?php echo !empty($row['lifetime_access']) ? 'Yes' : 'No'; ?></td>
                            <td class="px-3 py-3">
                                <div class="grid gap-2 lg:grid-cols-2">
                                    <?php if (RoleMiddleware::hasPermission($adminRole, 'admin.subscriptions.manage')): ?>
                                        <form method="post" class="flex items-center gap-1">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="subscription_id" value="<?php echo $sid; ?>">
                                            <select name="status" class="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                                                <?php foreach (['active','trialing','past_due','canceled','incomplete'] as $statusOption): ?>
                                                    <option value="<?php echo $statusOption; ?>"><?php echo strtoupper($statusOption); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="rounded-lg bg-slate-700 px-2 py-1 text-xs font-semibold text-slate-100">Status</button>
                                        </form>

                                        <form method="post" class="flex items-center gap-1">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="extend_days">
                                            <input type="hidden" name="subscription_id" value="<?php echo $sid; ?>">
                                            <input type="number" name="days" min="1" max="365" value="7" class="w-16 rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                                            <button class="rounded-lg bg-indigo-600 px-2 py-1 text-xs font-semibold text-white">Extend</button>
                                        </form>

                                        <form method="post" class="flex items-center gap-1">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="promo_days">
                                            <input type="hidden" name="subscription_id" value="<?php echo $sid; ?>">
                                            <input type="number" name="days" min="1" max="365" value="3" class="w-16 rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                                            <button class="rounded-lg bg-amber-600 px-2 py-1 text-xs font-semibold text-white">Promo</button>
                                        </form>

                                        <form method="post" class="flex items-center gap-1">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="lifetime_access">
                                            <input type="hidden" name="subscription_id" value="<?php echo $sid; ?>">
                                            <input type="hidden" name="enabled" value="1">
                                            <button class="rounded-lg bg-emerald-600 px-2 py-1 text-xs font-semibold text-white">Lifetime</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (RoleMiddleware::hasPermission($adminRole, 'admin.payments.refund')): ?>
                                        <form method="post" class="flex items-center gap-1 lg:col-span-2">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="refund">
                                            <input type="hidden" name="subscription_id" value="<?php echo $sid; ?>">
                                            <input type="hidden" name="user_id" value="<?php echo (int) ($row['user_id'] ?? 0); ?>">
                                            <input type="number" step="0.01" min="0" name="amount" value="0" class="w-24 rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100" placeholder="₹ Amt">
                                            <input type="text" name="reason" placeholder="Refund reason" class="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                                            <button class="rounded-lg bg-rose-600 px-2 py-1 text-xs font-semibold text-white">Refund</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="flex items-center justify-between border-t border-slate-800 px-4 py-3 text-sm text-slate-400">
        <p>Page <?php echo (int) ($pagination['page'] ?? 1); ?> / <?php echo max(1, (int) ($pagination['total_pages'] ?? 1)); ?> (<?php echo (int) ($pagination['total'] ?? 0); ?> subscriptions)</p>
        <div class="flex gap-2">
            <?php $prev = max(1, (int) ($pagination['page'] ?? 1) - 1); ?>
            <?php $next = min(max(1, (int) ($pagination['total_pages'] ?? 1)), (int) ($pagination['page'] ?? 1) + 1); ?>
            <?php $queryBase = '&search=' . urlencode((string) $filters['search']) . '&status=' . urlencode((string) $filters['status']) . '&plan=' . urlencode((string) $filters['plan']); ?>
            <a href="?page=<?php echo $prev . $queryBase; ?>" class="rounded-lg border border-slate-700 px-3 py-1 hover:bg-slate-800">Prev</a>
            <a href="?page=<?php echo $next . $queryBase; ?>" class="rounded-lg border border-slate-700 px-3 py-1 hover:bg-slate-800">Next</a>
        </div>
    </div>
</section>

<section class="mt-4 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
    <h2 class="mb-3 text-lg font-bold text-white">Recent Payment Logs</h2>
    <div class="max-h-80 overflow-auto rounded-xl border border-slate-800">
        <table class="w-full text-sm">
            <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                <tr>
                    <th class="px-3 py-2 text-left">Time</th>
                    <th class="px-3 py-2 text-left">Event</th>
                    <th class="px-3 py-2 text-left">Amount</th>
                    <th class="px-3 py-2 text-left">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($paymentLogs)): ?>
                    <tr><td colspan="4" class="px-3 py-4 text-center text-slate-500">No payment logs yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($paymentLogs as $log): ?>
                        <tr class="border-t border-slate-800">
                            <td class="px-3 py-2 text-slate-300"><?php echo htmlspecialchars((string) ($log['created_at'] ?? '')); ?></td>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
