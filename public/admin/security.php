<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../admin/SecurityController.php';

RoleMiddleware::requirePermission('admin.security.view', false);

$controller = new SecurityController($adminControlService);
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CsrfMiddleware::requirePostToken('csrf_token', 'admin_security_csrf', false);

    if (!RoleMiddleware::hasPermission($adminRole, 'admin.security.manage')) {
        $result = ['success' => false, 'error' => 'You do not have permission to change security controls.'];
    } else {
        $result = $controller->mutate($adminUserId, (string) ($_POST['action'] ?? ''), $_POST);
    }

    $message = (string) ($result['message'] ?? $result['error'] ?? '');
    $messageType = !empty($result['success']) ? 'success' : 'error';
}

$csrfToken = CsrfMiddleware::generateToken('admin_security_csrf');

$data = $controller->index();
$settings = (array) ($data['settings'] ?? []);
$blockedIps = (array) ($data['blocked_ips'] ?? []);
$failedLogins = (array) ($data['failed_logins'] ?? []);

$activePage = 'security';
$pageTitle = 'Security Controls';
include __DIR__ . '/includes/nav.php';
?>

<?php if ($message !== ''): ?>
    <div class="mb-4 rounded-xl border px-4 py-3 text-sm font-medium <?php echo $messageType === 'success' ? 'border-emerald-400/40 bg-emerald-500/10 text-emerald-200' : 'border-red-400/40 bg-red-500/10 text-red-200'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<section class="grid gap-4 xl:grid-cols-2">
    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="text-lg font-bold text-white">Core Security Settings</h2>
        <div class="mt-4 grid gap-3 md:grid-cols-2">
            <?php
            $settingKeys = [
                'ip_blocking_enabled' => 'IP Blocking Enabled (0/1)',
                'maintenance_mode' => 'Maintenance Mode (0/1)',
                'registration_enabled' => 'Registration Enabled (0/1)',
                'admin_2fa_required' => 'Admin 2FA Required (0/1)',
                'admin_totp_secret' => 'Admin TOTP Secret (Base32)',
                'failed_login_limit' => 'Failed Login Limit',
                'rate_limit_admin_login_per_10m' => 'Admin Login Rate Limit',
                'ai_global_enabled' => 'AI Global Enabled (0/1)',
                'ai_global_concurrency_limit' => 'AI Concurrency Limit',
                'ai_max_input_chars' => 'AI Max Input Chars',
            ];
            ?>
            <?php foreach ($settingKeys as $key => $label): ?>
                <form method="post" class="rounded-xl border border-slate-800 bg-slate-950/60 p-3">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="save_setting">
                    <input type="hidden" name="setting_key" value="<?php echo htmlspecialchars($key); ?>">
                    <label class="block text-xs font-semibold uppercase tracking-[0.12em] text-slate-400"><?php echo htmlspecialchars($label); ?></label>
                    <div class="mt-2 flex gap-2">
                        <input type="text" name="setting_value" value="<?php echo htmlspecialchars((string) ($settings[$key] ?? '')); ?>" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
                        <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500">Save</button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    </article>

    <article class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="text-lg font-bold text-white">Block IP Address</h2>
        <form method="post" class="mt-4 grid gap-3 md:grid-cols-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="block_ip">
            <input type="text" name="ip_address" placeholder="IP address" required class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
            <input type="text" name="reason" placeholder="Reason" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
            <input type="datetime-local" name="expires_at" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
            <button type="submit" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500">Block</button>
        </form>

        <h3 class="mt-6 text-sm font-semibold uppercase tracking-[0.14em] text-slate-400">Blocked IP List</h3>
        <div class="mt-2 max-h-72 overflow-auto rounded-xl border border-slate-800">
            <table class="w-full text-sm">
                <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                    <tr>
                        <th class="px-3 py-2 text-left">IP</th>
                        <th class="px-3 py-2 text-left">Reason</th>
                        <th class="px-3 py-2 text-left">Expires</th>
                        <th class="px-3 py-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($blockedIps)): ?>
                        <tr><td colspan="4" class="px-3 py-4 text-center text-slate-500">No blocked IPs.</td></tr>
                    <?php else: ?>
                        <?php foreach ($blockedIps as $row): ?>
                            <tr class="border-t border-slate-800">
                                <td class="px-3 py-2 text-slate-200"><?php echo htmlspecialchars((string) ($row['ip_address'] ?? '')); ?></td>
                                <td class="px-3 py-2 text-slate-300"><?php echo htmlspecialchars((string) ($row['reason'] ?? '')); ?></td>
                                <td class="px-3 py-2 text-slate-400"><?php echo htmlspecialchars((string) ($row['expires_at'] ?? '-')); ?></td>
                                <td class="px-3 py-2">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="unblock_ip">
                                        <input type="hidden" name="ip_address" value="<?php echo htmlspecialchars((string) ($row['ip_address'] ?? '')); ?>">
                                        <button class="rounded-lg bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-500">Unblock</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<section class="mt-4 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
    <h2 class="mb-3 text-lg font-bold text-white">Failed Login Attempts</h2>
    <div class="max-h-96 overflow-auto rounded-xl border border-slate-800">
        <table class="w-full text-sm">
            <thead class="bg-slate-900 text-xs uppercase tracking-[0.14em] text-slate-400">
                <tr>
                    <th class="px-3 py-2 text-left">Time</th>
                    <th class="px-3 py-2 text-left">Email</th>
                    <th class="px-3 py-2 text-left">IP</th>
                    <th class="px-3 py-2 text-left">Blocked</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($failedLogins)): ?>
                    <tr><td colspan="4" class="px-3 py-4 text-center text-slate-500">No failed logins recorded.</td></tr>
                <?php else: ?>
                    <?php foreach ($failedLogins as $row): ?>
                        <tr class="border-t border-slate-800">
                            <td class="px-3 py-2 text-slate-300"><?php echo htmlspecialchars((string) ($row['attempted_at'] ?? '')); ?></td>
                            <td class="px-3 py-2 text-slate-200"><?php echo htmlspecialchars((string) ($row['email'] ?? '-')); ?></td>
                            <td class="px-3 py-2 text-slate-400"><?php echo htmlspecialchars((string) ($row['ip_address'] ?? '-')); ?></td>
                            <td class="px-3 py-2 <?php echo !empty($row['is_blocked']) ? 'text-red-300' : 'text-slate-400'; ?>"><?php echo !empty($row['is_blocked']) ? 'YES' : 'NO'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
