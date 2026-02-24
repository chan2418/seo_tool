<?php

require_once __DIR__ . '/../utils/Env.php';

class GoogleSignInService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const SCOPE = 'openid email profile';
    private const STATE_TTL_SECONDS = 900;
    private const SESSION_STATE_KEY = 'google_oauth_state';

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct()
    {
        Env::load(dirname(__DIR__) . '/.env');
        $config = require __DIR__ . '/../config/config.php';

        $this->clientId = trim((string) (Env::get('GOOGLE_AUTH_CLIENT_ID', '') ?: ($config['google_auth_client_id'] ?? '')));
        if ($this->clientId === '') {
            $this->clientId = trim((string) (Env::get('GSC_CLIENT_ID', '') ?: ($config['gsc_client_id'] ?? '')));
        }

        $this->clientSecret = trim((string) (Env::get('GOOGLE_AUTH_CLIENT_SECRET', '') ?: ($config['google_auth_client_secret'] ?? '')));
        if ($this->clientSecret === '') {
            $this->clientSecret = trim((string) (Env::get('GSC_CLIENT_SECRET', '') ?: ($config['gsc_client_secret'] ?? '')));
        }

        $redirect = trim((string) (Env::get('GOOGLE_AUTH_REDIRECT_URI', '') ?: ($config['google_auth_redirect_uri'] ?? '')));
        if ($redirect === '') {
            $redirect = $this->buildCallbackUrlFromRequest();
        }
        $this->redirectUri = $this->normalizeAuthRedirectUri($redirect);
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->redirectUri !== '';
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function buildAuthorizationUrl(string $mode = 'login'): array
    {
        $mode = $this->normalizeMode($mode);
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Google OAuth is not configured. Set GOOGLE_AUTH_CLIENT_ID, GOOGLE_AUTH_CLIENT_SECRET, and GOOGLE_AUTH_REDIRECT_URI.',
            ];
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $csrf = bin2hex(random_bytes(24));
        $_SESSION[self::SESSION_STATE_KEY] = [
            'csrf' => $csrf,
            'mode' => $mode,
            'created_at' => time(),
        ];

        $statePayload = json_encode([
            'csrf' => $csrf,
            'mode' => $mode,
            'iat' => time(),
        ]);
        $state = rtrim(strtr(base64_encode((string) $statePayload), '+/', '-_'), '=');

        $query = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'online',
            'include_granted_scopes' => 'true',
            'prompt' => 'select_account',
            'state' => $state,
        ]);

        return [
            'success' => true,
            'url' => self::AUTH_URL . '?' . $query,
        ];
    }

    public function validateState(string $state): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $sessionState = $_SESSION[self::SESSION_STATE_KEY] ?? null;
        unset($_SESSION[self::SESSION_STATE_KEY]);

        if (!is_array($sessionState)) {
            return [
                'success' => false,
                'error' => 'OAuth session expired. Please try again.',
            ];
        }

        $decoded = $this->decodeStatePayload($state);
        if (!$decoded) {
            return [
                'success' => false,
                'error' => 'Invalid OAuth state.',
            ];
        }

        $sessionCsrf = (string) ($sessionState['csrf'] ?? '');
        $sessionMode = $this->normalizeMode((string) ($sessionState['mode'] ?? 'login'));
        $createdAt = (int) ($sessionState['created_at'] ?? 0);

        $payloadCsrf = (string) ($decoded['csrf'] ?? '');
        $payloadMode = $this->normalizeMode((string) ($decoded['mode'] ?? 'login'));

        if ($sessionCsrf === '' || $payloadCsrf === '' || !hash_equals($sessionCsrf, $payloadCsrf)) {
            return [
                'success' => false,
                'error' => 'OAuth CSRF validation failed.',
            ];
        }

        if ($payloadMode !== $sessionMode) {
            return [
                'success' => false,
                'error' => 'OAuth flow mismatch.',
            ];
        }

        if ($createdAt <= 0 || (time() - $createdAt) > self::STATE_TTL_SECONDS) {
            return [
                'success' => false,
                'error' => 'OAuth request timed out. Please retry.',
            ];
        }

        return [
            'success' => true,
            'mode' => $payloadMode,
        ];
    }

    public function exchangeCodeForTokens(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return [
                'success' => false,
                'error' => 'Missing OAuth authorization code.',
            ];
        }

        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Google OAuth configuration is missing.',
            ];
        }

        $payload = [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ];

        $response = $this->postForm(self::TOKEN_URL, $payload);
        if (empty($response['success'])) {
            return $response;
        }

        $body = (array) ($response['payload'] ?? []);
        $accessToken = trim((string) ($body['access_token'] ?? ''));
        if ($accessToken === '') {
            return [
                'success' => false,
                'error' => 'Google OAuth token exchange did not return access token.',
            ];
        }

        return [
            'success' => true,
            'access_token' => $accessToken,
            'id_token' => trim((string) ($body['id_token'] ?? '')),
            'expires_in' => max(60, (int) ($body['expires_in'] ?? 3600)),
            'raw' => $body,
        ];
    }

    public function fetchUserProfile(string $accessToken): array
    {
        $accessToken = trim($accessToken);
        if ($accessToken === '') {
            return [
                'success' => false,
                'error' => 'Missing Google access token.',
            ];
        }

        $ch = curl_init(self::USERINFO_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            return [
                'success' => false,
                'error' => 'Failed to fetch Google profile: ' . ($curlError !== '' ? $curlError : 'No response.'),
            ];
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return [
                'success' => false,
                'error' => 'Google profile response was invalid.',
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = (string) ($payload['error_description'] ?? $payload['error'] ?? 'Failed to fetch Google account info.');
            return [
                'success' => false,
                'error' => $message,
            ];
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $sub = trim((string) ($payload['sub'] ?? ''));
        if ($email === '' || $sub === '') {
            return [
                'success' => false,
                'error' => 'Google profile is missing email or user id.',
            ];
        }

        $emailVerified = !empty($payload['email_verified']);
        if (!$emailVerified) {
            return [
                'success' => false,
                'error' => 'Google email is not verified. Please verify your Google account email first.',
            ];
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($payload['given_name'] ?? ''));
        }
        if ($name === '') {
            $name = strtok($email, '@') ?: 'User';
        }

        return [
            'success' => true,
            'profile' => [
                'google_id' => mb_substr($sub, 0, 191),
                'email' => mb_substr($email, 0, 255),
                'name' => mb_substr($name, 0, 255),
                'picture' => mb_substr(trim((string) ($payload['picture'] ?? '')), 0, 2048),
                'email_verified' => true,
            ],
        ];
    }

    private function buildCallbackUrlFromRequest(): string
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return '';
        }

        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $scheme = $https ? 'https' : 'http';

        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = rtrim(dirname($script), '/');
        if ($dir === '' || $dir === '.') {
            $dir = '';
        }

        return $scheme . '://' . $host . $dir . '/google-auth-callback.php';
    }

    private function normalizeAuthRedirectUri(string $redirectUri): string
    {
        $redirectUri = trim($redirectUri);
        if ($redirectUri === '') {
            return '';
        }

        $parts = parse_url($redirectUri);
        if (!is_array($parts)) {
            return $redirectUri;
        }

        $path = strtolower((string) ($parts['path'] ?? ''));
        if ($path === '' || !str_ends_with($path, '/gsc-callback.php')) {
            return $redirectUri;
        }

        $scheme = (string) ($parts['scheme'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        if ($scheme === '' || $host === '') {
            return $redirectUri;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $newPath = preg_replace('~/gsc-callback\.php$~i', '/google-auth-callback.php', (string) ($parts['path'] ?? ''));
        if (!is_string($newPath) || $newPath === '') {
            return $redirectUri;
        }

        error_log('GoogleSignInService: GOOGLE_AUTH_REDIRECT_URI pointed to gsc-callback.php and was auto-corrected.');
        return $scheme . '://' . $host . $port . $newPath;
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
            return [
                'success' => false,
                'error' => 'Unable to reach Google OAuth endpoint: ' . $error,
            ];
        }

        $payload = json_decode($response, true);
        if (!is_array($payload)) {
            return [
                'success' => false,
                'error' => 'Google OAuth returned invalid JSON.',
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = (string) ($payload['error_description'] ?? $payload['error'] ?? 'Google OAuth request failed.');
            return [
                'success' => false,
                'error' => $message,
            ];
        }

        return [
            'success' => true,
            'payload' => $payload,
        ];
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['login', 'register'], true)) {
            return 'login';
        }
        return $mode;
    }
}
