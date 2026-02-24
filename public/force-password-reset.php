<?php
session_start();

require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../models/UserModel.php';

AuthMiddleware::requireLogin(false);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userModel = new UserModel();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CsrfMiddleware::requirePostToken('csrf_token', 'force_password_reset_csrf', false);

    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Password and confirm password do not match.';
    } else {
        $updated = $userModel->changePassword($userId, $password, true);
        if ($updated) {
            $_SESSION['force_password_reset'] = false;
            $success = 'Password updated successfully. Redirecting...';
            header('Refresh: 1; URL=dashboard.php');
        } else {
            $error = 'Unable to update password. Try again.';
        }
    }

}

$csrfToken = CsrfMiddleware::generateToken('force_password_reset_csrf');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center px-4">
    <div class="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900/80 p-6 shadow-2xl">
        <h1 class="text-2xl font-bold text-white">Reset Password Required</h1>
        <p class="mt-2 text-sm text-slate-400">Your administrator requires a password reset before using the platform.</p>

        <?php if ($error !== ''): ?>
            <div class="mt-4 rounded-lg border border-red-400/40 bg-red-500/10 px-3 py-2 text-sm text-red-200">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="mt-4 rounded-lg border border-emerald-400/40 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-200">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="post" class="mt-5 space-y-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="password" name="password" minlength="8" required placeholder="New password" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100">
            <input type="password" name="confirm_password" minlength="8" required placeholder="Confirm password" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100">
            <button type="submit" class="w-full rounded-xl bg-indigo-600 py-3 text-sm font-semibold text-white hover:bg-indigo-500">Update Password</button>
        </form>

        <a href="logout.php" class="mt-4 inline-flex w-full items-center justify-center rounded-xl border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-slate-800">Logout</a>
    </div>
</body>
</html>
