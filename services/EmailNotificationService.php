<?php

require_once __DIR__ . '/../utils/Env.php';

class EmailNotificationService
{
    private string $queueFile;
    private string $sentLogFile;
    private string $fromEmail;
    private string $fromName;
    private string $replyToEmail;
    private string $mailTransport;
    private string $smtpHost;
    private int $smtpPort;
    private string $smtpUsername;
    private string $smtpPassword;
    private string $smtpEncryption;
    private int $smtpTimeout;
    private bool $allowSmtpFallbackToMail;
    private string $mailLogoUrl;
    private bool $inlineLogoResolved;
    private ?array $inlineLogoPart;

    public function __construct()
    {
        Env::load(dirname(__DIR__) . '/.env');

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $this->queueFile = $storageDir . '/email_notification_queue.json';
        $this->sentLogFile = $storageDir . '/email_notification_sent.log';

        if (!file_exists($this->queueFile)) {
            file_put_contents($this->queueFile, json_encode([]));
        }
        if (!file_exists($this->sentLogFile)) {
            file_put_contents($this->sentLogFile, '');
        }

        $this->fromEmail = $this->readEnvFirstNonEmpty(['MAIL_FROM_EMAIL', 'BILLING_FROM_EMAIL', 'ALERTS_FROM_EMAIL']);
        if ($this->fromEmail === '') {
            $this->fromEmail = 'no-reply@example.com';
        }
        $this->fromName = $this->readEnvFirstNonEmpty(['MAIL_FROM_NAME', 'BILLING_FROM_NAME', 'ALERTS_FROM_NAME']);
        if ($this->fromName === '') {
            $this->fromName = 'SEO SaaS Alerts';
        }
        $this->replyToEmail = $this->readEnvFirstNonEmpty(['MAIL_REPLY_TO_EMAIL', 'BILLING_REPLY_TO_EMAIL', 'ALERTS_REPLY_TO_EMAIL']);
        if ($this->isNoReplyAddress($this->replyToEmail)) {
            $this->replyToEmail = '';
        }
        $this->mailLogoUrl = $this->readEnvFirstNonEmpty(['MAIL_LOGO_URL']);
        if ($this->mailLogoUrl === '') {
            $appUrl = trim((string) Env::get('APP_URL', ''));
            if ($appUrl !== '') {
                $this->mailLogoUrl = rtrim($appUrl, '/') . '/assets/images/logo-256.png';
            } else {
                $atPos = strrpos($this->fromEmail, '@');
                if ($atPos !== false) {
                    $domain = trim(substr($this->fromEmail, $atPos + 1));
                    if ($domain !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain) === 1) {
                        $this->mailLogoUrl = 'https://' . strtolower($domain) . '/assets/images/logo-256.png';
                    }
                }
            }
        }
        if ($this->mailLogoUrl !== '' && preg_match('#^https?://#i', $this->mailLogoUrl) !== 1) {
            $this->mailLogoUrl = '';
        }

        $this->mailTransport = strtolower(trim((string) Env::get('MAIL_TRANSPORT', 'mail')));
        if (!in_array($this->mailTransport, ['mail', 'smtp'], true)) {
            $this->mailTransport = 'mail';
        }

        $this->smtpHost = trim((string) Env::get('SMTP_HOST', ''));
        $this->smtpUsername = trim((string) Env::get('SMTP_USERNAME', ''));
        $this->smtpPassword = trim((string) Env::get('SMTP_PASSWORD', ''));
        $this->smtpEncryption = strtolower(trim((string) Env::get('SMTP_ENCRYPTION', 'tls')));
        if (!in_array($this->smtpEncryption, ['tls', 'ssl', 'none'], true)) {
            $this->smtpEncryption = 'tls';
        }
        $configuredPort = (int) Env::get('SMTP_PORT', '0');
        if ($configuredPort > 0) {
            $this->smtpPort = $configuredPort;
        } elseif ($this->smtpEncryption === 'ssl') {
            $this->smtpPort = 465;
        } elseif ($this->smtpEncryption === 'tls') {
            $this->smtpPort = 587;
        } else {
            $this->smtpPort = 25;
        }
        $this->smtpTimeout = max(5, min(60, (int) Env::get('SMTP_TIMEOUT', '20')));
        $fallbackRaw = strtolower(trim((string) Env::get('SMTP_FALLBACK_TO_MAIL', '1')));
        $this->allowSmtpFallbackToMail = !in_array($fallbackRaw, ['0', 'false', 'no', 'off'], true);
        $this->inlineLogoResolved = false;
        $this->inlineLogoPart = null;

        if ($this->mailTransport === 'mail' && $this->hasSmtpConfig()) {
            $this->mailTransport = 'smtp';
        }
    }

    public function queueSummaryEmail(
        int $userId,
        string $email,
        string $userName,
        string $subject,
        array $alerts,
        string $period = 'daily'
    ): bool {
        $email = trim($email);
        if ($userId <= 0 || $email === '' || empty($alerts)) {
            return false;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $period = strtolower(trim($period));
        if (!in_array($period, ['daily', 'weekly'], true)) {
            $period = 'daily';
        }

        $queue = $this->readJsonFile($this->queueFile);
        $key = sha1($userId . '|' . $email . '|' . date('Y-m-d') . '|' . $period);

        foreach ($queue as $item) {
            if ((string) ($item['dedupe_key'] ?? '') === $key) {
                return false;
            }
        }

        $queue[] = [
            'id' => $this->nextId($queue),
            'dedupe_key' => $key,
            'user_id' => $userId,
            'email' => $email,
            'user_name' => $userName !== '' ? $userName : 'User',
            'subject' => trim($subject) !== '' ? trim($subject) : 'SEO Alerts Summary',
            'period' => $period,
            'alerts' => array_slice($alerts, 0, 50),
            'status' => 'queued',
            'queued_at' => date('Y-m-d H:i:s'),
            'attempts' => 0,
        ];

        if (count($queue) > 5000) {
            $queue = array_slice($queue, -5000);
        }
        $this->writeJsonFile($this->queueFile, $queue);
        return true;
    }

    public function processQueue(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $queue = $this->readJsonFile($this->queueFile);
        if (empty($queue)) {
            return [
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
            ];
        }

        $processed = 0;
        $sent = 0;
        $failed = 0;

        foreach ($queue as $index => $item) {
            if ($processed >= $limit) {
                break;
            }
            if ((string) ($item['status'] ?? 'queued') !== 'queued') {
                continue;
            }

            $processed++;
            $queue[$index]['attempts'] = (int) ($queue[$index]['attempts'] ?? 0) + 1;
            $to = (string) ($item['email'] ?? '');
            $subject = (string) ($item['subject'] ?? 'SEO Alerts Summary');
            $body = $this->buildSummaryBody($item);

            $mailSent = $this->sendRawMail($to, $subject, $body, 'SENT', 'FAILED');

            if ($mailSent) {
                $queue[$index]['status'] = 'sent';
                $queue[$index]['sent_at'] = date('Y-m-d H:i:s');
                $sent++;
            } else {
                $queue[$index]['status'] = 'failed';
                $queue[$index]['failed_at'] = date('Y-m-d H:i:s');
                $queue[$index]['error'] = 'mail() returned false';
                $failed++;
            }
        }

        $queue = array_values(array_filter($queue, static function (array $item): bool {
            return !in_array((string) ($item['status'] ?? ''), ['sent'], true);
        }));
        $this->writeJsonFile($this->queueFile, $queue);

        return [
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    public function sendPlainEmail(string $to, string $subject, string $body): bool
    {
        $to = trim($to);
        $subject = trim($subject);
        $body = trim($body);

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->appendSentLog('PLAIN_FAILED', $to !== '' ? $to : '[empty]', $subject !== '' ? $subject : '[empty subject]', 'Invalid recipient email');
            return false;
        }
        if ($subject === '') {
            $subject = 'SEO SaaS Notification';
        }
        if ($body === '') {
            $this->appendSentLog('PLAIN_FAILED', $to, $subject, 'Email body is empty');
            return false;
        }

        return $this->sendRawMail($to, $subject, $body, 'PLAIN_SENT', 'PLAIN_FAILED');
    }

    private function buildSummaryBody(array $item): string
    {
        $userName = (string) ($item['user_name'] ?? 'User');
        $period = strtoupper((string) ($item['period'] ?? 'daily'));
        $alerts = is_array($item['alerts'] ?? null) ? $item['alerts'] : [];

        $lines = [];
        $lines[] = 'Hello ' . $userName . ',';
        $lines[] = '';
        $lines[] = 'Your ' . $period . ' SEO alerts summary:';
        $lines[] = '';

        foreach ($alerts as $alert) {
            $severity = strtoupper((string) ($alert['severity'] ?? 'INFO'));
            $message = (string) ($alert['message'] ?? 'Alert');
            $project = (string) ($alert['project_name'] ?? 'Project');
            $time = (string) ($alert['created_at'] ?? '');
            $lines[] = '- [' . $severity . '] ' . $project . ': ' . $message . ($time !== '' ? ' (' . $time . ')' : '');
        }

        $lines[] = '';
        $lines[] = 'Open your dashboard to review details and mark alerts as read.';
        $lines[] = '';
        $lines[] = 'SEO SaaS Alerts';

        return implode("\n", $lines);
    }

    private function sendRawMail(string $to, string $subject, string $body, string $successStatus, string $failureStatus): bool
    {
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? $subject);
        $body = $this->normalizeBody($body);
        $inlineLogoPart = $this->getInlineLogoPart();
        $htmlBody = $this->buildBrandedHtmlBody($subject, $body);

        if ($this->mailTransport === 'smtp') {
            $smtpReason = '';
            $smtpSent = $this->sendViaSmtp($to, $subject, $body, $htmlBody, $inlineLogoPart, $smtpReason);
            if ($smtpSent) {
                $this->appendSentLog($successStatus, $to, $subject, 'transport=smtp');
                return true;
            }

            if ($this->allowSmtpFallbackToMail) {
                $mailReason = '';
                $mailSent = $this->sendViaPhpMail($to, $subject, $body, $htmlBody, $inlineLogoPart, $mailReason);
                if ($mailSent) {
                    $this->appendSentLog(
                        $successStatus,
                        $to,
                        $subject,
                        'transport=mail (fallback) :: smtp_failed=' . $smtpReason
                    );
                    return true;
                }

                $combinedReason = 'transport=smtp failed :: ' . $smtpReason . ' :: mail fallback failed :: ' . $mailReason;
                $this->appendSentLog($failureStatus, $to, $subject, $combinedReason);
                return false;
            }

            $this->appendSentLog($failureStatus, $to, $subject, 'transport=smtp :: ' . $smtpReason);
            return false;
        }

        $mailReason = '';
        $mailSent = $this->sendViaPhpMail($to, $subject, $body, $htmlBody, $inlineLogoPart, $mailReason);
        if ($mailSent) {
            $this->appendSentLog($successStatus, $to, $subject, 'transport=mail');
            return true;
        }

        $this->appendSentLog($failureStatus, $to, $subject, $mailReason);
        return false;
    }

    private function sendViaPhpMail(
        string $to,
        string $subject,
        string $body,
        string $htmlBody,
        ?array $inlineLogoPart,
        string &$failureReason
    ): bool
    {
        $failureReason = '';
        if (!function_exists('mail')) {
            $failureReason = 'mail() function is unavailable';
            return false;
        }

        $boundary = $this->createMimeBoundary();
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: ' . ($inlineLogoPart !== null ? 'multipart/related' : 'multipart/alternative') . '; boundary="' . $boundary . '"';
        $headers[] = 'From: ' . $this->formatFromHeader();
        if ($this->replyToEmail !== '' && filter_var($this->replyToEmail, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $this->replyToEmail;
        }

        $message = $this->buildMultipartBody($boundary, $body, $htmlBody, $inlineLogoPart);
        $mailSent = @mail($to, $subject, $message, implode("\r\n", $headers));
        if ($mailSent) {
            return true;
        }

        $lastError = error_get_last();
        $failureReason = 'mail() returned false';
        if (is_array($lastError) && !empty($lastError['message'])) {
            $failureReason .= ' :: ' . (string) $lastError['message'];
        }

        return false;
    }

    private function sendViaSmtp(
        string $to,
        string $subject,
        string $body,
        string $htmlBody,
        ?array $inlineLogoPart,
        string &$failureReason
    ): bool
    {
        $failureReason = '';
        if (!$this->hasSmtpConfig()) {
            $failureReason = 'SMTP config is incomplete';
            return false;
        }

        $remoteHost = $this->smtpHost;
        if ($this->smtpEncryption === 'ssl') {
            $remoteHost = 'ssl://' . $remoteHost;
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remoteHost . ':' . $this->smtpPort,
            $errno,
            $errstr,
            $this->smtpTimeout
        );

        if (!is_resource($socket)) {
            $failureReason = 'Connection failed: ' . ($errstr !== '' ? $errstr : ('errno=' . $errno));
            return false;
        }

        stream_set_timeout($socket, $this->smtpTimeout);

        try {
            if (!$this->smtpExpect($socket, [220], $failureReason)) {
                return false;
            }

            $clientHost = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
            if (!$this->smtpCommand($socket, 'EHLO ' . $clientHost, [250], $failureReason)) {
                if (!$this->smtpCommand($socket, 'HELO ' . $clientHost, [250], $failureReason)) {
                    return false;
                }
            }

            if ($this->smtpEncryption === 'tls') {
                if (!$this->smtpCommand($socket, 'STARTTLS', [220], $failureReason)) {
                    return false;
                }

                $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($cryptoEnabled !== true) {
                    $failureReason = 'Unable to enable TLS encryption';
                    return false;
                }

                if (!$this->smtpCommand($socket, 'EHLO ' . $clientHost, [250], $failureReason)) {
                    return false;
                }
            }

            if (!$this->smtpCommand($socket, 'AUTH LOGIN', [334], $failureReason)) {
                return false;
            }
            if (!$this->smtpCommand($socket, base64_encode($this->smtpUsername), [334], $failureReason, true)) {
                return false;
            }
            if (!$this->smtpCommand($socket, base64_encode($this->smtpPassword), [235], $failureReason, true)) {
                return false;
            }

            $from = $this->fromEmail;
            if (!$this->smtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250], $failureReason)) {
                return false;
            }
            if (!$this->smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251], $failureReason)) {
                return false;
            }
            if (!$this->smtpCommand($socket, 'DATA', [354], $failureReason)) {
                return false;
            }

            $boundary = $this->createMimeBoundary();
            $headers = [];
            $headers[] = 'Date: ' . date('r');
            $headers[] = 'From: ' . $this->formatFromHeader();
            $headers[] = 'To: <' . $to . '>';
            if ($this->replyToEmail !== '' && filter_var($this->replyToEmail, FILTER_VALIDATE_EMAIL)) {
                $headers[] = 'Reply-To: ' . $this->replyToEmail;
            }
            $headers[] = 'Subject: ' . $this->encodeHeader($subject);
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: ' . ($inlineLogoPart !== null ? 'multipart/related' : 'multipart/alternative') . '; boundary="' . $boundary . '"';

            $mimeBody = $this->buildMultipartBody($boundary, $body, $htmlBody, $inlineLogoPart);
            $message = implode("\r\n", $headers) . "\r\n\r\n" . $this->dotStuff($mimeBody);
            $message .= "\r\n.\r\n";

            if (@fwrite($socket, $message) === false) {
                $failureReason = 'Failed to write DATA payload';
                return false;
            }
            if (!$this->smtpExpect($socket, [250], $failureReason)) {
                return false;
            }

            $this->smtpCommand($socket, 'QUIT', [221], $failureReason);
            return true;
        } finally {
            @fclose($socket);
        }
    }

    private function smtpCommand($socket, string $command, array $expectedCodes, string &$failureReason, bool $sensitive = false): bool
    {
        $payload = $command . "\r\n";
        if (@fwrite($socket, $payload) === false) {
            $failureReason = 'SMTP write failed for command: ' . ($sensitive ? '[redacted]' : $command);
            return false;
        }
        return $this->smtpExpect($socket, $expectedCodes, $failureReason);
    }

    private function smtpExpect($socket, array $expectedCodes, string &$failureReason): bool
    {
        $response = '';
        while (($line = @fgets($socket, 515)) !== false) {
            $response .= $line;
            if (preg_match('/^\d{3}\s/', $line) === 1) {
                break;
            }
        }

        if ($response === '') {
            $failureReason = 'Empty SMTP response';
            return false;
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            $failureReason = 'Unexpected SMTP response [' . $code . ']: ' . trim($response);
            return false;
        }

        return true;
    }

    private function dotStuff(string $body): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $body));
        foreach ($lines as $index => $line) {
            $line = rtrim($line, "\r");
            if ($line !== '' && $line[0] === '.') {
                $line = '.' . $line;
            }
            $lines[$index] = $line;
        }
        return implode("\r\n", $lines);
    }

    private function normalizeBody(string $body): string
    {
        return str_replace(["\r\n", "\r"], "\n", $body);
    }

    private function buildBrandedHtmlBody(string $subject, string $plainBody): string
    {
        $title = $this->htmlEscape($subject !== '' ? $subject : 'Notification');
        $brand = $this->htmlEscape($this->fromName !== '' ? $this->fromName : 'Serponiq');
        $contentHtml = $this->renderPlainBodyAsHtml($plainBody);
        $inlineLogoPart = $this->getInlineLogoPart();
        $logoBlock = '';
        if ($inlineLogoPart !== null) {
            $logoBlock = '<img src="cid:' . $this->htmlEscape((string) ($inlineLogoPart['cid'] ?? 'serponiq-logo')) . '" alt="' . $brand . ' logo" width="120" style="display:block;width:120px;max-width:120px;height:auto;border:0;outline:none;text-decoration:none;">';
        } elseif ($this->mailLogoUrl !== '') {
            $logoBlock = '<img src="' . $this->htmlEscape($this->mailLogoUrl) . '" alt="' . $brand . ' logo" width="120" style="display:block;width:120px;max-width:120px;height:auto;border:0;outline:none;text-decoration:none;">';
        } else {
            $logoBlock = '<div style="display:inline-block;padding:10px 14px;border-radius:10px;background:#2563eb;color:#ffffff;font:700 16px/1 Arial,sans-serif;">' . $brand . '</div>';
        }

        return '<!doctype html>'
            . '<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>'
            . '<body style="margin:0;padding:0;background:#f1f5f9;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f1f5f9;padding:24px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="640" style="max-width:640px;width:100%;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;">'
            . '<tr><td style="background:linear-gradient(135deg,#0f172a,#1e293b);padding:20px 24px;">' . $logoBlock . '</td></tr>'
            . '<tr><td style="padding:24px 24px 8px 24px;"><h1 style="margin:0;font:700 22px/1.35 Arial,sans-serif;color:#0f172a;">' . $title . '</h1></td></tr>'
            . '<tr><td style="padding:8px 24px 24px 24px;font:400 15px/1.75 Arial,sans-serif;color:#334155;">' . $contentHtml . '</td></tr>'
            . '<tr><td style="padding:16px 24px;background:#f8fafc;border-top:1px solid #e2e8f0;font:400 12px/1.6 Arial,sans-serif;color:#64748b;">'
            . '&copy; ' . date('Y') . ' ' . $brand . '. This is an automated message.'
            . '</td></tr>'
            . '</table>'
            . '</td></tr></table>'
            . '</body></html>';
    }

    private function renderPlainBodyAsHtml(string $plainBody): string
    {
        $lines = explode("\n", $this->normalizeBody($plainBody));
        $parts = [];
        $listItems = [];

        $flushList = static function (array &$items, array &$output, EmailNotificationService $service): void {
            if (empty($items)) {
                return;
            }
            $lis = [];
            foreach ($items as $item) {
                $lis[] = '<li style="margin:0 0 8px 0;">' . $service->htmlEscape($item) . '</li>';
            }
            $output[] = '<ul style="margin:8px 0 14px 20px;padding:0;color:#334155;">' . implode('', $lis) . '</ul>';
            $items = [];
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $flushList($listItems, $parts, $this);
                continue;
            }

            if (str_starts_with($trimmed, '- ')) {
                $listItems[] = trim(substr($trimmed, 2));
                continue;
            }

            $flushList($listItems, $parts, $this);
            $parts[] = '<p style="margin:0 0 12px 0;">' . $this->htmlEscape($trimmed) . '</p>';
        }

        $flushList($listItems, $parts, $this);
        if (empty($parts)) {
            return '<p style="margin:0;">&nbsp;</p>';
        }
        return implode('', $parts);
    }

    private function buildMultipartBody(string $boundary, string $plainBody, string $htmlBody, ?array $inlineLogoPart = null): string
    {
        $plainEncoded = rtrim(chunk_split(base64_encode($plainBody), 76, "\r\n"));
        $htmlEncoded = rtrim(chunk_split(base64_encode($htmlBody), 76, "\r\n"));

        if ($inlineLogoPart === null) {
            $lines = [];
            $lines[] = '--' . $boundary;
            $lines[] = 'Content-Type: text/plain; charset=UTF-8';
            $lines[] = 'Content-Transfer-Encoding: base64';
            $lines[] = '';
            $lines[] = $plainEncoded;
            $lines[] = '';
            $lines[] = '--' . $boundary;
            $lines[] = 'Content-Type: text/html; charset=UTF-8';
            $lines[] = 'Content-Transfer-Encoding: base64';
            $lines[] = '';
            $lines[] = $htmlEncoded;
            $lines[] = '';
            $lines[] = '--' . $boundary . '--';
            $lines[] = '';

            return implode("\r\n", $lines);
        }

        $altBoundary = $this->createMimeBoundary();
        $lines = [];
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';
        $lines[] = '';
        $lines[] = '--' . $altBoundary;
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: base64';
        $lines[] = '';
        $lines[] = $plainEncoded;
        $lines[] = '';
        $lines[] = '--' . $altBoundary;
        $lines[] = 'Content-Type: text/html; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: base64';
        $lines[] = '';
        $lines[] = $htmlEncoded;
        $lines[] = '';
        $lines[] = '--' . $altBoundary . '--';
        $lines[] = '';
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: ' . (string) ($inlineLogoPart['mime'] ?? 'image/png') . '; name="' . $this->sanitizeHeaderValue((string) ($inlineLogoPart['filename'] ?? 'logo.png')) . '"';
        $lines[] = 'Content-Transfer-Encoding: base64';
        $lines[] = 'Content-ID: <' . $this->sanitizeHeaderValue((string) ($inlineLogoPart['cid'] ?? 'serponiq-logo')) . '>';
        $lines[] = 'Content-Disposition: inline; filename="' . $this->sanitizeHeaderValue((string) ($inlineLogoPart['filename'] ?? 'logo.png')) . '"';
        $lines[] = '';
        $lines[] = (string) ($inlineLogoPart['data'] ?? '');
        $lines[] = '';
        $lines[] = '--' . $boundary . '--';
        $lines[] = '';

        return implode("\r\n", $lines);
    }

    private function createMimeBoundary(): string
    {
        try {
            return '=_seo_' . bin2hex(random_bytes(12));
        } catch (Throwable $error) {
            return '=_seo_' . str_replace('.', '', (string) microtime(true)) . '_' . mt_rand(1000, 9999);
        }
    }

    private function getInlineLogoPart(): ?array
    {
        if ($this->inlineLogoResolved) {
            return $this->inlineLogoPart;
        }

        $this->inlineLogoResolved = true;
        $configuredPath = trim((string) Env::get('MAIL_LOGO_PATH', ''));
        if ($configuredPath === '') {
            $configuredPath = dirname(__DIR__) . '/public/assets/images/logo-256.png';
        } elseif (!str_starts_with($configuredPath, '/')) {
            $configuredPath = dirname(__DIR__) . '/' . ltrim($configuredPath, '/');
        }

        if (!is_file($configuredPath) || !is_readable($configuredPath)) {
            $this->inlineLogoPart = null;
            return null;
        }

        $size = filesize($configuredPath);
        if (!is_int($size) || $size <= 0 || $size > 2 * 1024 * 1024) {
            $this->inlineLogoPart = null;
            return null;
        }

        $raw = file_get_contents($configuredPath);
        if (!is_string($raw) || $raw === '') {
            $this->inlineLogoPart = null;
            return null;
        }

        $extension = strtolower(pathinfo($configuredPath, PATHINFO_EXTENSION));
        $mime = $this->detectImageMimeByExtension($extension);
        $filename = 'logo.' . ($extension !== '' ? $extension : 'png');
        $this->inlineLogoPart = [
            'cid' => 'serponiq-logo',
            'mime' => $mime,
            'filename' => $filename,
            'data' => rtrim(chunk_split(base64_encode($raw), 76, "\r\n")),
        ];

        return $this->inlineLogoPart;
    }

    private function detectImageMimeByExtension(string $extension): string
    {
        if ($extension === 'jpg' || $extension === 'jpeg') {
            return 'image/jpeg';
        }
        if ($extension === 'gif') {
            return 'image/gif';
        }
        if ($extension === 'webp') {
            return 'image/webp';
        }
        if ($extension === 'svg') {
            return 'image/svg+xml';
        }

        return 'image/png';
    }

    private function sanitizeHeaderValue(string $value): string
    {
        return trim((string) preg_replace('/[\r\n"]+/', ' ', $value));
    }

    private function isNoReplyAddress(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $local = (string) strtok($email, '@');
        return $local === 'noreply' || $local === 'no-reply' || $local === 'no_reply';
    }

    private function formatFromHeader(): string
    {
        $name = trim((string) preg_replace('/[\r\n]+/', ' ', $this->fromName));
        if ($name === '') {
            return $this->fromEmail;
        }
        return $this->encodeHeader($name) . ' <' . $this->fromEmail . '>';
    }

    private function htmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function encodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/[^\x20-\x7E]/', $value) === 1) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private function appendSentLog(string $status, string $to, string $subject, string $note = ''): void
    {
        $line = date('Y-m-d H:i:s') . ' [' . $status . '] ' . $to . ' :: ' . $subject;
        if ($note !== '') {
            $line .= ' :: ' . $note;
        }
        file_put_contents($this->sentLogFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function readEnvFirstNonEmpty(array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) Env::get((string) $key, ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function hasSmtpConfig(): bool
    {
        return $this->smtpHost !== '' && $this->smtpUsername !== '' && $this->smtpPassword !== '';
    }

    private function readJsonFile(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeJsonFile(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function nextId(array $rows): int
    {
        $max = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > $max) {
                $max = $id;
            }
        }
        return $max + 1;
    }
}
