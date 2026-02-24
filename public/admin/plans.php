<?php
require_once __DIR__ . '/includes/bootstrap.php';

RoleMiddleware::requirePermission('admin.plans.manage', false);

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CsrfMiddleware::requirePostToken('csrf_token', 'admin_plans_csrf', false);

    $action = (string) ($_POST['action'] ?? '');
    $result = $adminControlService->handlePlansAction($adminUserId, $action, $_POST);

    $message = (string) ($result['message'] ?? $result['error'] ?? '');
    $messageType = !empty($result['success']) ? 'success' : 'error';
}

$csrfToken = CsrfMiddleware::generateToken('admin_plans_csrf');

$data = $adminControlService->getPlansPageData();
$plans = (array) ($data['plans'] ?? []);
$planLimits = (array) ($data['plan_limits'] ?? []);
$coupons = (array) ($data['coupons'] ?? []);

$limitsMap = [];
foreach ($planLimits as $row) {
    $limitsMap[(string) ($row['plan_type'] ?? '')] = $row;
}

$activePage = 'plans';
$pageTitle = 'Plans & Limits';
include __DIR__ . '/includes/nav.php';
?>

<?php if ($message !== ''): ?>
    <div class="mb-4 rounded-xl border px-4 py-3 text-sm font-medium <?php echo $messageType === 'success' ? 'border-emerald-400/40 bg-emerald-500/10 text-emerald-200' : 'border-red-400/40 bg-red-500/10 text-red-200'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<section class="grid gap-4 xl:grid-cols-2">
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="text-lg font-bold text-white">Plan Pricing (INR)</h2>
        <div class="mt-4 space-y-3">
            <?php foreach ($plans as $plan): ?>
                <?php $code = (string) ($plan['plan_code'] ?? 'free'); ?>
                <form method="post" class="rounded-xl border border-slate-800 bg-slate-950/60 p-3">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="update_plan_pricing">
                    <input type="hidden" name="plan_code" value="<?php echo htmlspecialchars($code); ?>">
                    <div class="grid gap-2 md:grid-cols-5">
                        <input type="text" name="display_name" value="<?php echo htmlspecialchars((string) ($plan['display_name'] ?? ucfirst($code))); ?>" class="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                        <input type="number" step="0.01" min="0" name="price_monthly" value="<?php echo htmlspecialchars((string) ($plan['price_monthly'] ?? '0')); ?>" class="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                        <input type="number" step="0.01" min="0" name="price_yearly" value="<?php echo htmlspecialchars((string) ($plan['price_yearly'] ?? '0')); ?>" class="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                        <input type="text" name="description" value="<?php echo htmlspecialchars((string) ($plan['description'] ?? '')); ?>" class="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                        <button class="rounded-lg bg-indigo-600 px-2 py-1 text-xs font-semibold text-white hover:bg-indigo-500">Update</button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    </article>

    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="text-lg font-bold text-white">Plan Limits</h2>
        <div class="mt-4 space-y-3">
            <?php foreach (['free', 'pro', 'agency'] as $planType): ?>
                <?php $limit = (array) ($limitsMap[$planType] ?? []); ?>
                <form method="post" class="rounded-xl border border-slate-800 bg-slate-950/60 p-3">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="update_plan_limit">
                    <input type="hidden" name="plan_type" value="<?php echo htmlspecialchars($planType); ?>">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-400"><?php echo strtoupper($planType); ?></p>
                    <div class="grid gap-2 md:grid-cols-7">
                        <input type="number" min="1" name="projects_limit" value="<?php echo (int) ($limit['projects_limit'] ?? 1); ?>" title="Projects" class="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                        <input type="number" min="1" name="keywords_limit" value="<?php echo (int) ($limit['keywords_limit'] ?? 5); ?>" title="Keywords" class="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                        <input type="number" min="1" name="api_calls_daily" value="<?php echo (int) ($limit['api_calls_daily'] ?? 250); ?>" title="API daily" class="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                        <input type="number" min="1" name="insights_limit" value="<?php echo (int) ($limit['insights_limit'] ?? 3); ?>" title="Insights" class="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                        <input type="number" min="1" name="ai_monthly_limit" value="<?php echo (int) ($limit['ai_monthly_limit'] ?? 3); ?>" title="AI / month" class="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                        <label class="flex items-center gap-1 text-xs text-slate-300"><input type="checkbox" name="can_export" value="1" <?php echo !empty($limit['can_export']) ? 'checked' : ''; ?>> Export</label>
                        <label class="flex items-center gap-1 text-xs text-slate-300"><input type="checkbox" name="can_manual_refresh" value="1" <?php echo !empty($limit['can_manual_refresh']) ? 'checked' : ''; ?>> Refresh</label>
                    </div>
                    <button class="mt-2 rounded-lg bg-emerald-600 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-500">Save Limits</button>
                </form>
            <?php endforeach; ?>
        </div>
    </article>
</section>

<section class="mt-4 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
    <h2 class="text-lg font-bold text-white">Coupons & Discounts</h2>
    <form method="post" class="mt-3 grid gap-3 md:grid-cols-7">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <input type="hidden" name="action" value="save_coupon">
        <input type="text" name="code" placeholder="Coupon code" required class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
        <select name="discount_type" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
            <option value="percent">Percent</option>
            <option value="fixed">Fixed</option>
        </select>
        <input type="number" step="0.01" min="0" name="discount_value" placeholder="Value" required class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
        <input type="number" min="1" name="max_uses" placeholder="Max uses" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
        <input type="datetime-local" name="expires_at" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
        <select name="plan_scope" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
            <option value="all">All Plans</option>
            <option value="pro">Pro</option>
            <option value="agency">Agency</option>
        </select>
        <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Save Coupon</button>
    </form>

    <div class="mt-4 max-h-80 overflow-auto rounded-xl border border-slate-800">
        <table class="w-full text-sm">
            <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                <tr>
                    <th class="px-3 py-2 text-left">Code</th>
                    <th class="px-3 py-2 text-left">Discount</th>
                    <th class="px-3 py-2 text-left">Uses</th>
                    <th class="px-3 py-2 text-left">Expiry</th>
                    <th class="px-3 py-2 text-left">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($coupons)): ?>
                    <tr><td colspan="5" class="px-3 py-4 text-center text-slate-500">No coupons available.</td></tr>
                <?php else: ?>
                    <?php foreach ($coupons as $coupon): ?>
                        <?php
                        $discountType = strtolower((string) ($coupon['discount_type'] ?? 'percent'));
                        $discountValue = (float) ($coupon['discount_value'] ?? 0);
                        $discountDisplay = $discountType === 'fixed'
                            ? format_inr($discountValue)
                            : number_format($discountValue, 2) . '%';
                        ?>
                        <tr class="border-t border-slate-800">
                            <td class="px-3 py-2 text-slate-200"><?php echo htmlspecialchars((string) ($coupon['code'] ?? '')); ?></td>
                            <td class="px-3 py-2 text-slate-300"><?php echo htmlspecialchars(strtoupper((string) ($coupon['discount_type'] ?? 'percent'))); ?> <?php echo htmlspecialchars($discountDisplay); ?></td>
                            <td class="px-3 py-2 text-slate-300"><?php echo (int) ($coupon['used_count'] ?? 0); ?> / <?php echo isset($coupon['max_uses']) ? (int) $coupon['max_uses'] : '-'; ?></td>
                            <td class="px-3 py-2 text-slate-400"><?php echo htmlspecialchars((string) ($coupon['expires_at'] ?? '-')); ?></td>
                            <td class="px-3 py-2 text-slate-300"><?php echo !empty($coupon['is_active']) ? 'Active' : 'Disabled'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
