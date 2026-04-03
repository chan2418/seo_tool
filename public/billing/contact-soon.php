<?php
session_start();

require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/AccountStatusMiddleware.php';

$auth = AuthMiddleware::requireLogin(false);
AccountStatusMiddleware::ensureActive(false);

$userName = (string) ($auth['user_name'] ?? ($_SESSION['user_name'] ?? 'User'));
$planType = strtolower(trim((string) ($_SESSION['billing_contact_plan'] ?? 'pro')));
$planCycle = strtolower(trim((string) ($_SESSION['billing_contact_cycle'] ?? 'monthly')));
$emailSent = !empty($_SESSION['billing_contact_email_sent']);
$message = trim((string) ($_SESSION['billing_contact_message'] ?? 'For security purposes, online payment is not available for your account right now. Our security system will verify your account once, then we will proceed. Our team will contact you soon.'));

unset($_SESSION['billing_contact_plan'], $_SESSION['billing_contact_cycle'], $_SESSION['billing_contact_email_sent'], $_SESSION['billing_contact_message']);

if (!in_array($planType, ['pro', 'agency'], true)) {
    $planType = 'pro';
}
$planLabel = ucfirst($planType);
$cycleLabel = $planCycle === 'annual' ? 'Annual' : 'Monthly';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="../assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="../assets/images/favicon-180.png">
    <title>Plan Request Received - SEO Suite</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
    <main class="mx-auto flex min-h-screen max-w-3xl items-center px-4 py-10">
        <section class="w-full rounded-2xl border border-slate-200 bg-white p-8 shadow-sm sm:p-10">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Subscription Request</p>
            <h1 class="mt-2 text-3xl font-extrabold text-slate-900">Thanks, <?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="mt-3 text-sm text-slate-600">
                Your <strong><?php echo htmlspecialchars($planLabel, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($cycleLabel, ENT_QUOTES, 'UTF-8'); ?>)</strong> request has been received.
            </p>
            <p class="mt-2 text-sm text-slate-700"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>

            <?php if ($emailSent): ?>
                <p class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    Confirmation email sent to your registered account email with security verification details.
                </p>
            <?php else: ?>
                <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                    Confirmation email could not be sent right now, but your request is saved and our team will contact you for security verification.
                </p>
            <?php endif; ?>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="../dashboard" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-indigo-600 to-indigo-500 px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110">
                    Dashboard
                </a>
                <a href="../subscription" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                    Back to Plans
                </a>
            </div>
        </section>
    </main>
</body>
</html>
