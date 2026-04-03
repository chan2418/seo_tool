<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../admin/UserController.php';

RoleMiddleware::requirePermission('admin.users.view', false);

$controller = new UserController($adminControlService);
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CsrfMiddleware::requirePostToken('csrf_token', 'admin_users_csrf', false);

    $action = strtolower(trim((string) ($_POST['action'] ?? '')));
    $needsManage = in_array($action, ['create_admin', 'update_plan', 'update_role', 'soft_delete'], true);
    $needsSuspend = in_array($action, ['update_status'], true);
    $needsPassword = in_array($action, ['reset_password', 'force_password_reset'], true);
    $needsForceLogout = in_array($action, ['force_logout'], true);

    $allowed = false;
    if ($needsManage && RoleMiddleware::hasPermission($adminRole, 'admin.users.manage')) {
        $allowed = true;
    }
    if ($needsSuspend && (RoleMiddleware::hasPermission($adminRole, 'admin.users.suspend') || RoleMiddleware::hasPermission($adminRole, 'admin.users.manage'))) {
        $allowed = true;
    }
    if ($needsPassword && (RoleMiddleware::hasPermission($adminRole, 'admin.users.reset_password') || RoleMiddleware::hasPermission($adminRole, 'admin.users.manage'))) {
        $allowed = true;
    }
    if ($needsForceLogout && (RoleMiddleware::hasPermission($adminRole, 'admin.users.force_logout') || RoleMiddleware::hasPermission($adminRole, 'admin.users.manage'))) {
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

$csrfToken = CsrfMiddleware::generateToken('admin_users_csrf');

$filters = [
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
    'per_page' => 20,
    'search' => trim((string) ($_GET['search'] ?? '')),
    'plan' => trim((string) ($_GET['plan'] ?? '')),
    'role' => trim((string) ($_GET['role'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'subscription_status' => trim((string) ($_GET['subscription_status'] ?? '')),
];

$data = $controller->index($filters);
$items = (array) ($data['items'] ?? []);
$pagination = (array) ($data['pagination'] ?? []);

$activePage = 'users';
$pageTitle = 'User Management';
include __DIR__ . '/includes/nav.php';
?>

<?php if ($message !== ''): ?>
    <div class="mb-4 rounded-xl border px-4 py-3 text-sm font-medium <?php echo $messageType === 'success' ? 'border-emerald-400/40 bg-emerald-500/10 text-emerald-200' : 'border-red-400/40 bg-red-500/10 text-red-200'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<section class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
    <form method="get" class="grid gap-3 md:grid-cols-6">
        <input type="text" name="search" value="<?php echo htmlspecialchars((string) $filters['search']); ?>" placeholder="Search name/email" class="rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-100 md:col-span-2">
        <select name="plan" class="rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-100">
            <option value="">All Plans</option>
            <option value="free" <?php echo $filters['plan'] === 'free' ? 'selected' : ''; ?>>Free</option>
            <option value="pro" <?php echo $filters['plan'] === 'pro' ? 'selected' : ''; ?>>Pro</option>
            <option value="agency" <?php echo $filters['plan'] === 'agency' ? 'selected' : ''; ?>>Agency</option>
        </select>
        <select name="role" class="rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-100">
            <option value="">All Roles</option>
            <?php foreach (['user','agency','support_admin','billing_admin','admin','super_admin'] as $roleOption): ?>
                <option value="<?php echo $roleOption; ?>" <?php echo $filters['role'] === $roleOption ? 'selected' : ''; ?>><?php echo htmlspecialchars(admin_role_label($roleOption)); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-100">
            <option value="">All Status</option>
            <option value="active" <?php echo $filters['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="suspended" <?php echo $filters['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
        </select>
        <select name="subscription_status" class="rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-100">
            <option value="">Sub Status</option>
            <?php foreach (['active','trialing','past_due','canceled','incomplete'] as $statusOption): ?>
                <option value="<?php echo $statusOption; ?>" <?php echo $filters['subscription_status'] === $statusOption ? 'selected' : ''; ?>><?php echo strtoupper($statusOption); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Apply Filters</button>
    </form>
</section>

<?php if (RoleMiddleware::hasPermission($adminRole, 'admin.users.manage')): ?>
<section class="mt-4 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
    <h2 class="text-lg font-bold text-white">Create Admin User</h2>
    <form method="post" class="mt-3 grid gap-3 md:grid-cols-5">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <input type="hidden" name="action" value="create_admin">
        <input type="text" name="name" placeholder="Full name" required class="rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-100">
        <input type="email" name="email" placeholder="Email" required class="rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-100">
        <input type="password" name="password" placeholder="Temporary password" required class="rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-100">
        <select name="role" class="rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-100">
            <option value="admin">Admin</option>
            <option value="support_admin">Support Admin</option>
            <option value="billing_admin">Billing Admin</option>
            <option value="super_admin">Super Admin</option>
        </select>
        <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Create</button>
    </form>
</section>
<?php endif; ?>

<section class="mt-4 overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/70">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[1280px] text-sm">
            <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                <tr>
                    <th class="px-3 py-3 text-left">User</th>
                    <th class="px-3 py-3 text-left">Role</th>
                    <th class="px-3 py-3 text-left">Plan</th>
                    <th class="px-3 py-3 text-left">Status</th>
                    <th class="px-3 py-3 text-left">Sub Status</th>
                    <th class="px-3 py-3 text-left">Projects</th>
                    <th class="px-3 py-3 text-left">Keywords</th>
                    <th class="px-3 py-3 text-left">Last Login</th>
                    <th class="w-[460px] px-3 py-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="9" class="px-3 py-5 text-center text-slate-500">No users found.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $row): ?>
                        <?php
                        $uid = (int) ($row['id'] ?? 0);
                        $subStatus = strtolower((string) ($row['subscription_status'] ?? 'none'));
                        ?>
                        <tr class="border-t border-slate-800">
                            <td class="px-3 py-3">
                                <p class="font-semibold text-slate-100"><?php echo htmlspecialchars((string) ($row['name'] ?? '')); ?></p>
                                <p class="text-xs text-slate-400"><?php echo htmlspecialchars((string) ($row['email'] ?? '')); ?></p>
                                <p class="text-xs text-slate-500">ID: <?php echo $uid; ?></p>
                            </td>
                            <td class="px-3 py-3 text-slate-200"><?php echo htmlspecialchars(admin_role_label((string) ($row['role'] ?? 'user'))); ?></td>
                            <td class="px-3 py-3 text-slate-200"><?php echo htmlspecialchars(strtoupper((string) ($row['plan_type'] ?? 'free'))); ?></td>
                            <td class="px-3 py-3">
                                <span class="rounded-full px-2 py-1 text-xs font-semibold <?php echo ((string) ($row['status'] ?? 'active')) === 'active' ? 'bg-emerald-500/15 text-emerald-300' : 'bg-red-500/15 text-red-300'; ?>">
                                    <?php echo htmlspecialchars(strtoupper((string) ($row['status'] ?? 'active'))); ?>
                                </span>
                            </td>
                            <td class="px-3 py-3">
                                <span class="rounded-full px-2 py-1 text-xs font-semibold <?php echo in_array($subStatus, ['active', 'trialing'], true) ? 'bg-indigo-500/15 text-indigo-200' : ($subStatus === 'past_due' ? 'bg-amber-500/15 text-amber-200' : 'bg-slate-700 text-slate-200'); ?>">
                                    <?php echo htmlspecialchars(strtoupper($subStatus)); ?>
                                </span>
                            </td>
                            <td class="px-3 py-3 text-slate-200"><?php echo (int) ($row['project_count'] ?? 0); ?></td>
                            <td class="px-3 py-3 text-slate-200"><?php echo (int) ($row['keyword_count'] ?? 0); ?></td>
                            <td class="px-3 py-3 text-xs text-slate-400">
                                <p><?php echo htmlspecialchars((string) ($row['last_login_at'] ?? '-')); ?></p>
                                <p><?php echo htmlspecialchars((string) ($row['last_login_ip'] ?? '-')); ?></p>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <div class="grid min-w-[360px] gap-2 xl:grid-cols-2">
                                    <a href="user-activity?user_id=<?php echo $uid; ?>" class="inline-flex items-center justify-center rounded-lg border border-slate-700 px-2 py-1 text-xs font-semibold text-slate-200 hover:bg-slate-800 lg:col-span-2">
                                        View Activity
                                    </a>
                                    <?php if (RoleMiddleware::hasPermission($adminRole, 'admin.users.manage')): ?>
                                        <form method="post" class="grid w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-2">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="update_plan">
                                            <input type="hidden" name="target_user_id" value="<?php echo $uid; ?>">
                                            <select name="plan_type" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                                                <option value="free" <?php echo strtolower((string) ($row['plan_type'] ?? 'free')) === 'free' ? 'selected' : ''; ?>>Free</option>
                                                <option value="pro" <?php echo strtolower((string) ($row['plan_type'] ?? 'free')) === 'pro' ? 'selected' : ''; ?>>Pro</option>
                                                <option value="agency" <?php echo strtolower((string) ($row['plan_type'] ?? 'free')) === 'agency' ? 'selected' : ''; ?>>Agency</option>
                                            </select>
                                            <button class="rounded-lg bg-slate-700 px-2 py-1 text-xs font-semibold text-slate-100">Save Plan</button>
                                        </form>
                                        <form method="post" class="grid w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-2">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="update_role">
                                            <input type="hidden" name="target_user_id" value="<?php echo $uid; ?>">
                                            <select name="role" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                                                <option value="user" <?php echo strtolower((string) ($row['role'] ?? 'user')) === 'user' ? 'selected' : ''; ?>>User</option>
                                                <option value="agency" <?php echo strtolower((string) ($row['role'] ?? 'user')) === 'agency' ? 'selected' : ''; ?>>Agency</option>
                                                <option value="support_admin" <?php echo strtolower((string) ($row['role'] ?? 'user')) === 'support_admin' ? 'selected' : ''; ?>>Support</option>
                                                <option value="billing_admin" <?php echo strtolower((string) ($row['role'] ?? 'user')) === 'billing_admin' ? 'selected' : ''; ?>>Billing</option>
                                                <option value="admin" <?php echo strtolower((string) ($row['role'] ?? 'user')) === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                <option value="super_admin" <?php echo strtolower((string) ($row['role'] ?? 'user')) === 'super_admin' ? 'selected' : ''; ?>>Super</option>
                                            </select>
                                            <button class="rounded-lg bg-indigo-600 px-2 py-1 text-xs font-semibold text-white">Save Role</button>
                                        </form>
                                        <form method="post" class="flex w-full items-center gap-2 xl:col-span-2" onsubmit="return confirm('Soft delete this account?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="soft_delete">
                                            <input type="hidden" name="target_user_id" value="<?php echo $uid; ?>">
                                            <input type="text" name="reason" placeholder="Soft delete reason" class="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                                            <button class="rounded-lg bg-red-700 px-2 py-1 text-xs font-semibold text-white">Soft Delete</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (RoleMiddleware::hasPermission($adminRole, 'admin.users.suspend') || RoleMiddleware::hasPermission($adminRole, 'admin.users.manage')): ?>
                                        <form method="post" class="flex w-full items-center gap-2 xl:col-span-2">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="target_user_id" value="<?php echo $uid; ?>">
                                            <select name="status" class="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                                                <option value="active" <?php echo strtolower((string) ($row['status'] ?? 'active')) === 'active' ? 'selected' : ''; ?>>Active</option>
                                                <option value="suspended" <?php echo strtolower((string) ($row['status'] ?? 'active')) === 'suspended' ? 'selected' : ''; ?>>Suspend</option>
                                            </select>
                                            <input type="text" name="reason" placeholder="Reason" class="min-w-[96px] flex-1 rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                                            <button class="rounded-lg bg-amber-600 px-2 py-1 text-xs font-semibold text-white">Save Status</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (RoleMiddleware::hasPermission($adminRole, 'admin.users.reset_password') || RoleMiddleware::hasPermission($adminRole, 'admin.users.manage')): ?>
                                        <form method="post" class="flex w-full items-center gap-2">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="force_password_reset">
                                            <input type="hidden" name="target_user_id" value="<?php echo $uid; ?>">
                                            <input type="hidden" name="required" value="1">
                                            <button class="rounded-lg bg-fuchsia-600 px-2 py-1 text-xs font-semibold text-white">Force Reset</button>
                                        </form>
                                        <form method="post" class="flex w-full items-center gap-2 xl:col-span-2">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="target_user_id" value="<?php echo $uid; ?>">
                                            <input type="password" name="password" placeholder="New password" minlength="8" required class="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100">
                                            <button class="rounded-lg bg-cyan-600 px-2 py-1 text-xs font-semibold text-white">Set Password</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (RoleMiddleware::hasPermission($adminRole, 'admin.users.force_logout') || RoleMiddleware::hasPermission($adminRole, 'admin.users.manage')): ?>
                                        <form method="post" class="flex w-full items-center gap-2">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="force_logout">
                                            <input type="hidden" name="target_user_id" value="<?php echo $uid; ?>">
                                            <button class="rounded-lg bg-rose-600 px-2 py-1 text-xs font-semibold text-white">Force Logout</button>
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
        <p>Page <?php echo (int) ($pagination['page'] ?? 1); ?> / <?php echo max(1, (int) ($pagination['total_pages'] ?? 1)); ?> (<?php echo (int) ($pagination['total'] ?? 0); ?> users)</p>
        <div class="flex gap-2">
            <?php $prev = max(1, (int) ($pagination['page'] ?? 1) - 1); ?>
            <?php $next = min(max(1, (int) ($pagination['total_pages'] ?? 1)), (int) ($pagination['page'] ?? 1) + 1); ?>
            <?php $queryBase = '&search=' . urlencode((string) $filters['search']) . '&plan=' . urlencode((string) $filters['plan']) . '&role=' . urlencode((string) $filters['role']) . '&status=' . urlencode((string) $filters['status']) . '&subscription_status=' . urlencode((string) $filters['subscription_status']); ?>
            <a href="?page=<?php echo $prev . $queryBase; ?>" class="rounded-lg border border-slate-700 px-3 py-1 hover:bg-slate-800">Prev</a>
            <a href="?page=<?php echo $next . $queryBase; ?>" class="rounded-lg border border-slate-700 px-3 py-1 hover:bg-slate-800">Next</a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
