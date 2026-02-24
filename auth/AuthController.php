<?php

require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../services/UserActivityService.php';
require_once __DIR__ . '/../services/AuditLogService.php';
require_once __DIR__ . '/../services/AdminControlService.php';
require_once __DIR__ . '/../services/AdminTwoFactorService.php';
session_start();

class AuthController
{
    private UserModel $userModel;
    private UserActivityService $userActivityService;
    private AuditLogService $auditLogService;
    private AdminControlService $adminControlService;
    private AdminTwoFactorService $adminTwoFactorService;

    public function __construct()
    {
        $this->userModel = new UserModel();
        $this->userActivityService = new UserActivityService();
        $this->auditLogService = new AuditLogService();
        $this->adminControlService = new AdminControlService();
        $this->adminTwoFactorService = new AdminTwoFactorService();
    }

    public function register($name, $email, $password)
    {
        if (empty($name) || empty($email) || empty($password)) {
            return ['error' => 'All fields are required.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Invalid email address.'];
        }

        if (strlen((string) $password) < 6) {
            return ['error' => 'Password must be at least 6 characters.'];
        }

        $userId = $this->userModel->createUser((string) $name, (string) $email, (string) $password);
        if (!$userId) {
            return ['error' => 'Email already exists or database error.'];
        }

        $user = $this->userModel->getUserById((int) $userId);
        if (!$user) {
            return ['error' => 'Unable to load newly created account.'];
        }

        $this->userModel->updateLastLogin((int) ($user['id'] ?? 0));
        $sessionResult = $this->establishSessionFromUser($user);
        if (empty($sessionResult['success'])) {
            return ['error' => (string) ($sessionResult['error'] ?? 'Unable to complete login session.')];
        }

        $emailNormalized = strtolower(trim((string) $email));
        $this->userActivityService->log((int) ($user['id'] ?? 0), 'auth.register', ['email' => $emailNormalized]);
        $this->auditLogService->log((int) ($user['id'] ?? 0), 'auth.register', ['email' => $emailNormalized], (int) ($user['id'] ?? 0));

        return [
            'success' => true,
            'requires_2fa' => !empty($sessionResult['requires_2fa']),
        ];
    }

    public function login($email, $password)
    {
        $email = strtolower(trim((string) $email));
        $requestIp = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        $securityCheck = $this->adminControlService->enforceSecurityOnLogin($email, $requestIp !== '' ? $requestIp : null, $userAgent !== '' ? $userAgent : null);
        if (empty($securityCheck['allowed'])) {
            return ['error' => (string) ($securityCheck['error'] ?? 'Login is currently blocked.')];
        }

        $user = $this->userModel->getUserByEmail($email);

        if ($user && password_verify((string) $password, (string) ($user['password'] ?? ''))) {
            $status = strtolower((string) ($user['status'] ?? 'active'));
            if ($status !== 'active') {
                $this->userActivityService->log((int) ($user['id'] ?? 0), 'auth.login_blocked_status', ['status' => $status]);
                $this->adminControlService->trackFailedLogin($email, $requestIp !== '' ? $requestIp : null, $userAgent !== '' ? $userAgent : null);
                return ['error' => 'Your account is suspended. Contact support.'];
            }

            $this->userModel->updateLastLogin((int) ($user['id'] ?? 0));
            $sessionResult = $this->establishSessionFromUser($user);
            if (empty($sessionResult['success'])) {
                return ['error' => (string) ($sessionResult['error'] ?? 'Unable to complete login session.')];
            }

            $this->userActivityService->log((int) ($user['id'] ?? 0), 'auth.login_success', [
                'email' => $email,
                'role' => strtolower((string) ($user['role'] ?? 'user')),
            ]);
            $this->auditLogService->log((int) ($user['id'] ?? 0), 'auth.login_success', [
                'email' => $email,
            ], (int) ($user['id'] ?? 0));

            return [
                'success' => true,
                'requires_2fa' => !empty($sessionResult['requires_2fa']),
            ];
        }

        $this->userActivityService->log(isset($user['id']) ? (int) $user['id'] : null, 'auth.login_failed', [
            'email' => $email,
        ]);
        $this->adminControlService->trackFailedLogin($email, $requestIp !== '' ? $requestIp : null, $userAgent !== '' ? $userAgent : null);
        $this->auditLogService->log(isset($user['id']) ? (int) $user['id'] : null, 'auth.login_failed', [
            'email' => $email,
        ], isset($user['id']) ? (int) $user['id'] : null);

        return ['error' => 'Invalid credentials.'];
    }

    public function loginWithGoogle(array $googleProfile, string $mode = 'login'): array
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['login', 'register'], true)) {
            $mode = 'login';
        }

        $email = strtolower(trim((string) ($googleProfile['email'] ?? '')));
        $name = trim((string) ($googleProfile['name'] ?? ''));
        $googleId = trim((string) ($googleProfile['google_id'] ?? ''));
        $avatar = trim((string) ($googleProfile['picture'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $googleId === '') {
            return ['error' => 'Google account data is invalid. Please retry sign-in.'];
        }
        if ($name === '') {
            $name = strtok($email, '@') ?: 'User';
        }

        $requestIp = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $securityCheck = $this->adminControlService->enforceSecurityOnLogin($email, $requestIp !== '' ? $requestIp : null, $userAgent !== '' ? $userAgent : null);
        if (empty($securityCheck['allowed'])) {
            return ['error' => (string) ($securityCheck['error'] ?? 'Login is currently blocked.')];
        }

        $upsert = $this->userModel->upsertGoogleUser($name, $email, $googleId, $avatar !== '' ? $avatar : null);
        if (empty($upsert['success'])) {
            return ['error' => (string) ($upsert['error'] ?? 'Google sign-in failed. Please try again.')];
        }

        $user = (array) ($upsert['user'] ?? []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return ['error' => 'Unable to load Google account user.'];
        }

        $status = strtolower((string) ($user['status'] ?? 'active'));
        if ($status !== 'active') {
            $this->userActivityService->log($userId, 'auth.google_blocked_status', ['status' => $status]);
            $this->adminControlService->trackFailedLogin($email, $requestIp !== '' ? $requestIp : null, $userAgent !== '' ? $userAgent : null);
            return ['error' => 'Your account is suspended. Contact support.'];
        }

        $freshUser = $this->userModel->getUserById($userId);
        if ($freshUser) {
            $user = $freshUser;
        }

        $this->userModel->updateLastLogin($userId);
        $sessionResult = $this->establishSessionFromUser($user);
        if (empty($sessionResult['success'])) {
            return ['error' => (string) ($sessionResult['error'] ?? 'Unable to complete login session.')];
        }

        $created = !empty($upsert['created']);
        $event = $created ? 'auth.register_google' : 'auth.login_google';
        $this->userActivityService->log($userId, $event, [
            'email' => $email,
            'mode' => $mode,
        ]);
        $this->auditLogService->log($userId, $event, [
            'email' => $email,
            'mode' => $mode,
        ], $userId);

        return [
            'success' => true,
            'requires_2fa' => !empty($sessionResult['requires_2fa']),
            'created' => $created,
        ];
    }

    public function logout()
    {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        if ($userId !== null && $userId > 0) {
            $this->userActivityService->log($userId, 'auth.logout', []);
            $this->auditLogService->log($userId, 'auth.logout', [], $userId);
        }
        session_destroy();
        header('Location: login.php');
        exit;
    }

    private function establishSessionFromUser(array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return ['success' => false, 'error' => 'Invalid user session data.'];
        }

        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = (string) ($user['name'] ?? 'User');
        $_SESSION['plan_type'] = (string) ($user['plan_type'] ?? 'free');
        $_SESSION['role'] = strtolower((string) ($user['role'] ?? 'user'));
        $_SESSION['account_status'] = strtolower((string) ($user['status'] ?? 'active'));
        $_SESSION['auth_at'] = time();
        $_SESSION['force_password_reset'] = !empty($user['force_password_reset']);

        $role = strtolower((string) ($user['role'] ?? 'user'));
        if ($this->adminTwoFactorService->isRequiredForRole($role)) {
            if (!$this->adminTwoFactorService->hasSecretConfigured()) {
                session_unset();
                session_destroy();
                return ['success' => false, 'error' => 'Admin 2FA is required but not configured. Set admin_totp_secret in security settings.'];
            }
            $_SESSION['admin_2fa_pending'] = true;
            $_SESSION['admin_2fa_verified'] = false;
        } else {
            $_SESSION['admin_2fa_pending'] = false;
            $_SESSION['admin_2fa_verified'] = true;
        }

        return [
            'success' => true,
            'requires_2fa' => !empty($_SESSION['admin_2fa_pending']),
        ];
    }
}
