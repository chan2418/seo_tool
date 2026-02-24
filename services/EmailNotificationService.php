<?php

class EmailNotificationService
{
    private string $queueFile;
    private string $sentLogFile;
    private string $fromEmail;
    private string $fromName;

    public function __construct()
    {
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

        $this->fromEmail = trim((string) getenv('ALERTS_FROM_EMAIL'));
        if ($this->fromEmail === '') {
            $this->fromEmail = 'no-reply@example.com';
        }
        $this->fromName = trim((string) getenv('ALERTS_FROM_NAME'));
        if ($this->fromName === '') {
            $this->fromName = 'SEO SaaS Alerts';
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

            $mailSent = false;
            if (function_exists('mail')) {
                $headers = [];
                $headers[] = 'MIME-Version: 1.0';
                $headers[] = 'Content-type: text/plain; charset=UTF-8';
                $headers[] = 'From: ' . $this->fromName . ' <' . $this->fromEmail . '>';
                $mailSent = @mail($to, $subject, $body, implode("\r\n", $headers));
            }

            if ($mailSent) {
                $queue[$index]['status'] = 'sent';
                $queue[$index]['sent_at'] = date('Y-m-d H:i:s');
                $sent++;
                $this->appendSentLog('SENT', $to, $subject);
            } else {
                $queue[$index]['status'] = 'failed';
                $queue[$index]['failed_at'] = date('Y-m-d H:i:s');
                $queue[$index]['error'] = 'mail() returned false';
                $failed++;
                $this->appendSentLog('FAILED', $to, $subject);
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

    private function appendSentLog(string $status, string $to, string $subject): void
    {
        $line = date('Y-m-d H:i:s') . ' [' . $status . '] ' . $to . ' :: ' . $subject;
        file_put_contents($this->sentLogFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
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
