<?php
require_once __DIR__ . '/includes/bootstrap.php';

RoleMiddleware::requirePermission('admin.feature_flags.manage', false);

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CsrfMiddleware::requirePostToken('csrf_token', 'admin_feature_flags_csrf', false);
    $result = $adminControlService->handleFeatureFlagAction($adminUserId, (string) ($_POST['action'] ?? ''), $_POST);
    $message = (string) ($result['message'] ?? $result['error'] ?? '');
    $messageType = !empty($result['success']) ? 'success' : 'error';
}

$csrfToken = CsrfMiddleware::generateToken('admin_feature_flags_csrf');

$data = $adminControlService->getFeatureFlagsPageData();
$flags = (array) ($data['feature_flags'] ?? []);

$activePage = 'feature_flags';
$pageTitle = 'Feature Flags';
include __DIR__ . '/includes/nav.php';
?>

<?php if ($message !== ''): ?>
    <div class="mb-4 rounded-xl border px-4 py-3 text-sm font-medium <?php echo $messageType === 'success' ? 'border-emerald-400/40 bg-emerald-500/10 text-emerald-200' : 'border-red-400/40 bg-red-500/10 text-red-200'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<section class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
    <h2 class="text-lg font-bold text-white">Create / Update Flag</h2>
    <form method="post" class="mt-3 grid gap-3 md:grid-cols-6">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <input type="hidden" name="action" value="save_feature_flag">
        <input type="text" name="flag_key" placeholder="flag_key" required class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
        <input type="text" name="flag_name" placeholder="Display name" required class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
        <input type="text" name="description" placeholder="Description" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
        <select name="rollout_plan" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
            <option value="all">All</option>
            <option value="free">Free</option>
            <option value="pro">Pro</option>
            <option value="agency">Agency</option>
        </select>
        <label class="flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200">
            <input type="checkbox" name="is_enabled" value="1">
            Enabled
        </label>
        <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Save Flag</button>
    </form>
</section>

<section class="mt-4 overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/70">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[1100px] text-sm">
            <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                <tr>
                    <th class="px-3 py-3 text-left">Key</th>
                    <th class="px-3 py-3 text-left">Name</th>
                    <th class="px-3 py-3 text-left">Description</th>
                    <th class="px-3 py-3 text-left">Rollout</th>
                    <th class="px-3 py-3 text-left">Status</th>
                    <th class="px-3 py-3 text-left">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($flags)): ?>
                    <tr><td colspan="6" class="px-3 py-5 text-center text-slate-500">No feature flags found.</td></tr>
                <?php else: ?>
                    <?php foreach ($flags as $flag): ?>
                        <tr class="border-t border-slate-800">
                            <td class="px-3 py-3 text-slate-200"><?php echo htmlspecialchars((string) ($flag['flag_key'] ?? '')); ?></td>
                            <td class="px-3 py-3 text-slate-200"><?php echo htmlspecialchars((string) ($flag['flag_name'] ?? '')); ?></td>
                            <td class="px-3 py-3 text-slate-400"><?php echo htmlspecialchars((string) ($flag['description'] ?? '')); ?></td>
                            <td class="px-3 py-3 text-slate-300"><?php echo htmlspecialchars(strtoupper((string) ($flag['rollout_plan'] ?? 'ALL'))); ?></td>
                            <td class="px-3 py-3 <?php echo !empty($flag['is_enabled']) ? 'text-emerald-300' : 'text-red-300'; ?>"><?php echo !empty($flag['is_enabled']) ? 'Enabled' : 'Disabled'; ?></td>
                            <td class="px-3 py-3">
                                <form method="post" class="flex items-center gap-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="toggle_feature_flag">
                                    <input type="hidden" name="flag_key" value="<?php echo htmlspecialchars((string) ($flag['flag_key'] ?? '')); ?>">
                                    <input type="hidden" name="flag_name" value="<?php echo htmlspecialchars((string) ($flag['flag_name'] ?? '')); ?>">
                                    <input type="hidden" name="description" value="<?php echo htmlspecialchars((string) ($flag['description'] ?? '')); ?>">
                                    <input type="hidden" name="rollout_plan" value="<?php echo htmlspecialchars((string) ($flag['rollout_plan'] ?? 'all')); ?>">
                                    <input type="hidden" name="is_enabled" value="<?php echo !empty($flag['is_enabled']) ? '0' : '1'; ?>">
                                    <button class="rounded-lg <?php echo !empty($flag['is_enabled']) ? 'bg-red-600 hover:bg-red-500' : 'bg-emerald-600 hover:bg-emerald-500'; ?> px-2 py-1 text-xs font-semibold text-white">
                                        <?php echo !empty($flag['is_enabled']) ? 'Disable' : 'Enable'; ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
