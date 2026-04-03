<?php
session_start();

require_once __DIR__ . '/../services/AdminTwoFactorService.php';
require_once __DIR__ . '/../models/UserModel.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userModel = new UserModel();
$user = $userModel->getUserById($userId);
$role = strtolower((string) ($user['role'] ?? ($_SESSION['role'] ?? 'user')));

$twoFactorService = new AdminTwoFactorService();
$required = $twoFactorService->isRequiredForRole($role);
if (!$required) {
    $_SESSION['admin_2fa_pending'] = false;
    $_SESSION['admin_2fa_verified'] = true;
    $next = (string) ($_SESSION['post_auth_next'] ?? '');
    unset($_SESSION['post_auth_next']);
    header('Location: ' . ($next !== '' ? $next : 'dashboard'));
    exit;
}

if (!empty($_SESSION['admin_2fa_verified']) && empty($_SESSION['admin_2fa_pending'])) {
    $next = (string) ($_SESSION['post_auth_next'] ?? '');
    unset($_SESSION['post_auth_next']);
    header('Location: ' . ($next !== '' ? $next : 'admin/dashboard'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim((string) ($_POST['code'] ?? ''));
    if ($twoFactorService->verifyCode($code)) {
        $_SESSION['admin_2fa_pending'] = false;
        $_SESSION['admin_2fa_verified'] = true;
        $_SESSION['admin_2fa_verified_at'] = time();
        $next = (string) ($_SESSION['post_auth_next'] ?? '');
        unset($_SESSION['post_auth_next']);
        header('Location: ' . ($next !== '' ? $next : 'admin/dashboard'));
        exit;
    }
    $error = 'Invalid verification code.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="assets/images/favicon-180.png">
    <title>Admin 2FA Verification</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center px-4">
    <div class="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900/80 p-6 shadow-2xl">
        <h1 class="text-2xl font-bold text-white">Admin Verification</h1>
        <p class="mt-2 text-sm text-slate-400">Enter the 6-digit code from your authenticator app.</p>

        <?php if ($error !== ''): ?>
            <div class="mt-4 rounded-lg border border-red-400/40 bg-red-500/10 px-3 py-2 text-sm text-red-200">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" class="mt-5 space-y-3">
            <input
                type="text"
                name="code"
                inputmode="numeric"
                pattern="[0-9]{6}"
                maxlength="6"
                required
                class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-lg tracking-[0.35em] text-slate-100"
                placeholder="000000"
            >
            <button type="submit" class="w-full rounded-xl bg-indigo-600 py-3 text-sm font-semibold text-white hover:bg-indigo-500">Verify</button>
        </form>

        <a href="logout" class="mt-4 inline-flex w-full items-center justify-center rounded-xl border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-slate-800">Logout</a>
    </div>
</body>
</html>
