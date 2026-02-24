<?php

require_once __DIR__ . '/../models/AdminControlModel.php';
require_once __DIR__ . '/../utils/Env.php';

class AdminTwoFactorService
{
    private AdminControlModel $model;
    private array $settings;

    public function __construct(?AdminControlModel $model = null)
    {
        Env::load(dirname(__DIR__) . '/.env');
        $this->model = $model ?? new AdminControlModel();
        $this->settings = $this->model->hasConnection() ? $this->model->getSecuritySettings() : [];
    }

    public function isRequiredForRole(string $role): bool
    {
        $role = strtolower(trim($role));
        if (!in_array($role, ['super_admin', 'admin', 'support_admin', 'billing_admin'], true)) {
            return false;
        }

        $required = (string) ($this->settings['admin_2fa_required'] ?? Env::get('ADMIN_2FA_REQUIRED', '0'));
        return in_array($required, ['1', 'true', 'yes', 'on'], true);
    }

    public function hasSecretConfigured(): bool
    {
        return $this->getSecret() !== '';
    }

    public function verifyCode(string $code): bool
    {
        $code = trim($code);
        if (!preg_match('/^[0-9]{6}$/', $code)) {
            return false;
        }

        $secret = $this->getSecret();
        if ($secret === '') {
            return false;
        }

        $timeSlice = (int) floor(time() / 30);
        for ($i = -1; $i <= 1; $i++) {
            $generated = $this->totp($secret, $timeSlice + $i);
            if ($generated !== null && hash_equals($generated, $code)) {
                return true;
            }
        }

        return false;
    }

    private function getSecret(): string
    {
        $secret = trim((string) ($this->settings['admin_totp_secret'] ?? ''));
        if ($secret === '') {
            $secret = trim((string) Env::get('ADMIN_2FA_SECRET', ''));
        }
        return strtoupper(str_replace(' ', '', $secret));
    }

    private function totp(string $base32Secret, int $timeSlice): ?string
    {
        $secretKey = $this->base32Decode($base32Secret);
        if ($secretKey === null || $secretKey === '') {
            return null;
        }

        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        if (!is_string($hash) || strlen($hash) < 20) {
            return null;
        }

        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        $otp = $binary % 1000000;
        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $input): ?string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper(trim($input));
        $input = rtrim($input, '=');
        if ($input === '') {
            return null;
        }

        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        $length = strlen($input);
        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];
            $value = strpos($alphabet, $char);
            if ($value === false) {
                return null;
            }

            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
