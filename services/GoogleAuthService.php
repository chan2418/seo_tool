<?php

require_once __DIR__ . '/../utils/Env.php';

class GoogleAuthService
{
    private const OAUTH_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const OAUTH_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const STATE_TTL_SECONDS = 900;
    private const CIPHER = 'AES-256-CBC';

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $encryptionKey;

    public function __construct()
    {
        Env::load(dirname(__DIR__) . '/.env');
        $config = require __DIR__ . '/../config/config.php';

        $this->clientId = trim((string) (Env::get('GSC_CLIENT_ID', '') ?: ($config['gsc_client_id'] ?? '')));
        $this->clientSecret = trim((string) (Env::get('GSC_CLIENT_SECRET', '') ?: ($config['gsc_client_secret'] ?? '')));

        $redirect = trim((string) Env::get('GSC_REDIRECT_URI', ''));
        if ($redirect === '' && !empty($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $redirect = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/gsc-callback.php';
        }
        $this->redirectUri = $redirect;

        $keySource = trim((string) Env::get('GSC_TOKEN_ENCRYPTION_KEY', ''));
        if ($keySource === '') {
            $keySource = trim((string) Env::get('APP_KEY', ''));
        }
        $this->encryptionKey = $keySource;
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->redirectUri !== '';
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function buildAuthorizationUrl(int $userId, int $projectId): string
    {
        $state = $this->createOAuthState($userId, $projectId);
        $query = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => self::OAUTH_SCOPE,
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return self::OAUTH_AUTH_URL . '?' . $query;
    }

    public function createFormCsrfToken(string $key = 'gsc_form_csrf'): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $token = bin2hex(random_bytes(24));
        $_SESSION[$key] = [
            'token' => $token,
            'created_at' => time(),
        ];
        return $token;
    }

    public function validateFormCsrfToken(string $token, string $key = 'gsc_form_csrf'): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $state = $_SESSION[$key] ?? null;
        if (!is_array($state)) {
            return false;
        }
        $expected = (string) ($state['token'] ?? '');
        $createdAt = (int) ($state['created_at'] ?? 0);
        unset($_SESSION[$key]);

        if ($expected === '' || $createdAt <= 0) {
            return false;
        }
        if ((time() - $createdAt) > self::STATE_TTL_SECONDS) {
            return false;
        }
        return hash_equals($expected, $token);
    }

    public function validateAndParseState(string $state, int $currentUserId): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $decoded = $this->decodeStatePayload($state);
        if (!$decoded) {
            return ['success' => false, 'error' => 'Invalid OAuth state.'];
        }

        $sessionState = $_SESSION['gsc_oauth_state'] ?? null;
        unset($_SESSION['gsc_oauth_state']);
        if (!is_array($sessionState)) {
            return ['success' => false, 'error' => 'OAuth session expired.'];
        }

        $expectedCsrf = (string) ($sessionState['csrf'] ?? '');
        $createdAt = (int) ($sessionState['created_at'] ?? 0);
        if ($expectedCsrf === '' || $createdAt <= 0) {
            return ['success' => false, 'error' => 'OAuth session invalid.'];
        }
        if ((time() - $createdAt) > self::STATE_TTL_SECONDS) {
            return ['success' => false, 'error' => 'OAuth session timed out.'];
        }

        $csrf = (string) ($decoded['csrf'] ?? '');
        $userId = (int) ($decoded['user_id'] ?? 0);
        $projectId = (int) ($decoded['project_id'] ?? 0);
        if (!hash_equals($expectedCsrf, $csrf)) {
            return ['success' => false, 'error' => 'OAuth CSRF check failed.'];
        }
        if ($currentUserId <= 0 || $userId !== $currentUserId) {
            return ['success' => false, 'error' => 'OAuth user mismatch.'];
        }
        if ($projectId <= 0) {
            return ['success' => false, 'error' => 'Invalid project in OAuth state.'];
        }

        return [
            'success' => true,
            'project_id' => $projectId,
        ];
    }

    public function exchangeCodeForTokens(string $code): array
    {
        $code = trim($code);
        if (!$this->isConfigured() || $code === '') {
            return ['success' => false, 'error' => 'Google OAuth configuration is missing.'];
        }

        $response = $this->postForm(self::OAUTH_TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ]);
        if (empty($response['success'])) {
            return $response;
        }

        $payload = (array) ($response['payload'] ?? []);
        $accessToken = trim((string) ($payload['access_token'] ?? ''));
        $refreshToken = trim((string) ($payload['refresh_token'] ?? ''));
        $expiresIn = (int) ($payload['expires_in'] ?? 3600);
        if ($accessToken === '') {
            return ['success' => false, 'error' => 'Google token exchange did not return access token.'];
        }

        return [
            'success' => true,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => max(60, $expiresIn),
            'raw' => $payload,
        ];
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $refreshToken = trim($refreshToken);
        if (!$this->isConfigured() || $refreshToken === '') {
            return ['success' => false, 'error' => 'Refresh token not available.'];
        }

        $response = $this->postForm(self::OAUTH_TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
        ]);
        if (empty($response['success'])) {
            return $response;
        }

        $payload = (array) ($response['payload'] ?? []);
        $accessToken = trim((string) ($payload['access_token'] ?? ''));
        $expiresIn = (int) ($payload['expires_in'] ?? 3600);
        if ($accessToken === '') {
            return ['success' => false, 'error' => 'Google did not return refreshed access token.'];
        }

        return [
            'success' => true,
            'access_token' => $accessToken,
            'refresh_token' => trim((string) ($payload['refresh_token'] ?? '')),
            'expires_in' => max(60, $expiresIn),
            'raw' => $payload,
        ];
    }

    public function encryptToken(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $key = $this->resolvedEncryptionKey();
        if ($key === '') {
            throw new RuntimeException('Token encryption key is not configured.');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $cipherRaw = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if (!is_string($cipherRaw) || $cipherRaw === '') {
            throw new RuntimeException('Failed to encrypt OAuth token.');
        }

        return 'v1:' . base64_encode($iv . $cipherRaw);
    }

    public function decryptToken(string $encrypted): string
    {
        $encrypted = trim($encrypted);
        if ($encrypted === '') {
            return '';
        }

        if (!str_starts_with($encrypted, 'v1:')) {
            return '';
        }
        $blob = base64_decode(substr($encrypted, 3), true);
        if (!is_string($blob) || $blob === '') {
            return '';
        }

        $key = $this->resolvedEncryptionKey();
        if ($key === '') {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if (strlen($blob) <= $ivLength) {
            return '';
        }
        $iv = substr($blob, 0, $ivLength);
        $cipher = substr($blob, $ivLength);

        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        return is_string($plain) ? $plain : '';
    }

    private function createOAuthState(int $userId, int $projectId): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $csrf = bin2hex(random_bytes(24));
        $_SESSION['gsc_oauth_state'] = [
            'csrf' => $csrf,
            'created_at' => time(),
            'user_id' => $userId,
            'project_id' => $projectId,
        ];

        $payload = json_encode([
            'csrf' => $csrf,
            'user_id' => $userId,
            'project_id' => $projectId,
            'iat' => time(),
        ]);
        return rtrim(strtr(base64_encode((string) $payload), '+/', '-_'), '=');
    }

    private function decodeStatePayload(string $state): ?array
    {
        $state = trim($state);
        if ($state === '') {
            return null;
        }
        $padding = strlen($state) % 4;
        if ($padding > 0) {
            $state .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($state, '-_', '+/'), true);
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }
        $json = json_decode($decoded, true);
        return is_array($json) ? $json : null;
    }

    private function postForm(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            return ['success' => false, 'error' => 'Unable to reach Google OAuth endpoint: ' . $error];
        }

        $payload = json_decode($response, true);
        if (!is_array($payload)) {
            return ['success' => false, 'error' => 'Google OAuth returned invalid JSON.'];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = (string) ($payload['error_description'] ?? $payload['error'] ?? 'Google OAuth request failed.');
            return ['success' => false, 'error' => $message];
        }

        return ['success' => true, 'payload' => $payload];
    }

    private function resolvedEncryptionKey(): string
    {
        $key = trim($this->encryptionKey);
        if ($key === '') {
            return '';
        }
        return hash('sha256', $key, true);
    }
}

