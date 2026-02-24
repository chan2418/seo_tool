<?php

require_once __DIR__ . '/../config/database.php';

class SubscriptionModel
{
    private ?PDO $conn;
    private bool $useFileStorage = false;
    private string $file;

    public function __construct()
    {
        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $this->file = $storageDir . '/subscriptions_core.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function upsertByGatewaySubscriptionId(array $data): array
    {
        $normalized = $this->normalizeInput($data);
        if ((int) ($normalized['user_id'] ?? 0) <= 0) {
            return ['success' => false, 'error' => 'Invalid user id.'];
        }
        if ((string) ($normalized['plan_type'] ?? '') === '') {
            return ['success' => false, 'error' => 'Invalid plan type.'];
        }

        if ($this->useFileStorage) {
            return $this->upsertByGatewaySubscriptionIdFile($normalized);
        }

        try {
            $existing = null;
            if ((string) ($normalized['razorpay_subscription_id'] ?? '') !== '') {
                $existing = $this->getByGatewaySubscriptionId((string) $normalized['razorpay_subscription_id']);
            }
            if (!$existing) {
                $existing = $this->getCurrentByUser((int) $normalized['user_id']);
            }

            $now = date('Y-m-d H:i:s');
            if ($existing) {
                $stmt = $this->conn->prepare(
                    'UPDATE subscriptions
                     SET razorpay_customer_id = :razorpay_customer_id,
                         razorpay_subscription_id = :razorpay_subscription_id,
                         plan_type = :plan_type,
                         status = :status,
                         next_billing_date = :next_billing_date,
                         grace_ends_at = :grace_ends_at,
                         current_period_start = :current_period_start,
                         current_period_end = :current_period_end,
                         cancel_at_period_end = :cancel_at_period_end,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':razorpay_customer_id' => $normalized['razorpay_customer_id'],
                    ':razorpay_subscription_id' => $normalized['razorpay_subscription_id'],
                    ':plan_type' => $normalized['plan_type'],
                    ':status' => $normalized['status'],
                    ':next_billing_date' => $normalized['next_billing_date'],
                    ':grace_ends_at' => $normalized['grace_ends_at'],
                    ':current_period_start' => $normalized['current_period_start'],
                    ':current_period_end' => $normalized['current_period_end'],
                    ':cancel_at_period_end' => $normalized['cancel_at_period_end'],
                    ':updated_at' => $now,
                    ':id' => (int) ($existing['id'] ?? 0),
                ]);
                $fresh = $this->getById((int) ($existing['id'] ?? 0));
                return ['success' => true, 'subscription' => $fresh];
            }

            $stmt = $this->conn->prepare(
                'INSERT INTO subscriptions
                    (user_id, razorpay_customer_id, razorpay_subscription_id, plan_type, status, next_billing_date,
                     grace_ends_at, current_period_start, current_period_end, cancel_at_period_end, created_at, updated_at,
                     start_date, end_date)
                 VALUES
                    (:user_id, :razorpay_customer_id, :razorpay_subscription_id, :plan_type, :status, :next_billing_date,
                     :grace_ends_at, :current_period_start, :current_period_end, :cancel_at_period_end, :created_at, :updated_at,
                     :start_date, :end_date)'
            );
            $startDate = $normalized['current_period_start'] ?: $now;
            $endDate = $normalized['current_period_end'] ?: ($normalized['next_billing_date'] ?: date('Y-m-d H:i:s', strtotime('+1 month')));
            $stmt->execute([
                ':user_id' => $normalized['user_id'],
                ':razorpay_customer_id' => $normalized['razorpay_customer_id'],
                ':razorpay_subscription_id' => $normalized['razorpay_subscription_id'],
                ':plan_type' => $normalized['plan_type'],
                ':status' => $normalized['status'],
                ':next_billing_date' => $normalized['next_billing_date'],
                ':grace_ends_at' => $normalized['grace_ends_at'],
                ':current_period_start' => $normalized['current_period_start'],
                ':current_period_end' => $normalized['current_period_end'],
                ':cancel_at_period_end' => $normalized['cancel_at_period_end'],
                ':created_at' => $now,
                ':updated_at' => $now,
                ':start_date' => $startDate,
                ':end_date' => $endDate,
            ]);
            $fresh = $this->getById((int) $this->conn->lastInsertId());
            return ['success' => true, 'subscription' => $fresh];
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'upsertByGatewaySubscriptionId');
            return $this->upsertByGatewaySubscriptionId($normalized);
        }
    }

    public function getByGatewaySubscriptionId(string $gatewaySubscriptionId): ?array
    {
        $gatewaySubscriptionId = trim($gatewaySubscriptionId);
        if ($gatewaySubscriptionId === '') {
            return null;
        }

        if ($this->useFileStorage) {
            foreach ($this->readRows() as $row) {
                if ((string) ($row['razorpay_subscription_id'] ?? '') === $gatewaySubscriptionId) {
                    return $this->normalizeRow($row);
                }
            }
            return null;
        }

        try {
            $stmt = $this->conn->prepare('SELECT * FROM subscriptions WHERE razorpay_subscription_id = :id LIMIT 1');
            $stmt->execute([':id' => $gatewaySubscriptionId]);
            $row = $stmt->fetch();
            return $row ? $this->normalizeRow($row) : null;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getByGatewaySubscriptionId');
            return $this->getByGatewaySubscriptionId($gatewaySubscriptionId);
        }
    }

    public function getCurrentByUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        if ($this->useFileStorage) {
            $rows = array_values(array_filter($this->readRows(), static fn (array $row): bool => (int) ($row['user_id'] ?? 0) === $userId));
            if (empty($rows)) {
                return null;
            }
            usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
            return $this->normalizeRow($rows[0]);
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT * FROM subscriptions
                 WHERE user_id = :user_id
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 1'
            );
            $stmt->execute([':user_id' => $userId]);
            $row = $stmt->fetch();
            return $row ? $this->normalizeRow($row) : null;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getCurrentByUser');
            return $this->getCurrentByUser($userId);
        }
    }

    public function listActive(int $limit = 100): array
    {
        $limit = max(1, min(5000, $limit));

        if ($this->useFileStorage) {
            $rows = array_values(array_filter($this->readRows(), static function (array $row): bool {
                return in_array(strtolower((string) ($row['status'] ?? '')), ['active', 'trialing'], true);
            }));
            usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
            return array_slice(array_map(fn (array $row): array => $this->normalizeRow($row), $rows), 0, $limit);
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT * FROM subscriptions
                 WHERE status IN ("active", "trialing")
                 ORDER BY updated_at DESC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute();
            return array_map(fn (array $row): array => $this->normalizeRow($row), $stmt->fetchAll() ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'listActive');
            return $this->listActive($limit);
        }
    }

    public function getPastDueExpired(string $currentDateTime): array
    {
        $currentDateTime = $this->normalizeDateTime($currentDateTime);

        if ($this->useFileStorage) {
            $result = [];
            foreach ($this->readRows() as $row) {
                if (strtolower((string) ($row['status'] ?? '')) !== 'past_due') {
                    continue;
                }
                $grace = (string) ($row['grace_ends_at'] ?? '');
                if ($grace === '' || $grace > $currentDateTime) {
                    continue;
                }
                $result[] = $this->normalizeRow($row);
            }
            return $result;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT * FROM subscriptions
                 WHERE status = "past_due"
                   AND grace_ends_at IS NOT NULL
                   AND grace_ends_at <= :now'
            );
            $stmt->execute([':now' => $currentDateTime]);
            return array_map(fn (array $row): array => $this->normalizeRow($row), $stmt->fetchAll() ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getPastDueExpired');
            return $this->getPastDueExpired($currentDateTime);
        }
    }

    public function getStats(): array
    {
        $stats = [
            'active_total' => 0,
            'new_this_month' => 0,
            'churned_this_month' => 0,
            'by_plan' => [
                'free' => 0,
                'pro' => 0,
                'agency' => 0,
            ],
        ];
        $monthStart = date('Y-m-01 00:00:00');

        if ($this->useFileStorage) {
            foreach ($this->readRows() as $row) {
                $normalized = $this->normalizeRow($row);
                $plan = (string) ($normalized['plan_type'] ?? 'free');
                if (!isset($stats['by_plan'][$plan])) {
                    $stats['by_plan'][$plan] = 0;
                }

                if (in_array((string) ($normalized['status'] ?? ''), ['active', 'trialing'], true)) {
                    $stats['active_total']++;
                    $stats['by_plan'][$plan]++;
                }

                $createdAt = (string) ($normalized['created_at'] ?? '');
                if ($createdAt >= $monthStart) {
                    $stats['new_this_month']++;
                }

                if ((string) ($normalized['status'] ?? '') === 'canceled') {
                    $updatedAt = (string) ($normalized['updated_at'] ?? '');
                    if ($updatedAt >= $monthStart) {
                        $stats['churned_this_month']++;
                    }
                }
            }
            return $stats;
        }

        try {
            $activeStmt = $this->conn->query('SELECT COUNT(*) AS total FROM subscriptions WHERE status IN ("active", "trialing")');
            $stats['active_total'] = (int) (($activeStmt->fetch()['total'] ?? 0));

            $newStmt = $this->conn->prepare('SELECT COUNT(*) AS total FROM subscriptions WHERE created_at >= :month_start');
            $newStmt->execute([':month_start' => $monthStart]);
            $stats['new_this_month'] = (int) (($newStmt->fetch()['total'] ?? 0));

            $churnStmt = $this->conn->prepare(
                'SELECT COUNT(*) AS total
                 FROM subscriptions
                 WHERE status = "canceled"
                   AND updated_at >= :month_start'
            );
            $churnStmt->execute([':month_start' => $monthStart]);
            $stats['churned_this_month'] = (int) (($churnStmt->fetch()['total'] ?? 0));

            $planStmt = $this->conn->query(
                'SELECT plan_type, COUNT(*) AS total
                 FROM subscriptions
                 WHERE status IN ("active", "trialing")
                 GROUP BY plan_type'
            );
            foreach ($planStmt->fetchAll() as $row) {
                $plan = strtolower((string) ($row['plan_type'] ?? 'free'));
                if (!isset($stats['by_plan'][$plan])) {
                    $stats['by_plan'][$plan] = 0;
                }
                $stats['by_plan'][$plan] = (int) ($row['total'] ?? 0);
            }

            return $stats;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getStats');
            return $this->getStats();
        }
    }

    public function getById(int $subscriptionId): ?array
    {
        if ($subscriptionId <= 0) {
            return null;
        }
        if ($this->useFileStorage) {
            foreach ($this->readRows() as $row) {
                if ((int) ($row['id'] ?? 0) === $subscriptionId) {
                    return $this->normalizeRow($row);
                }
            }
            return null;
        }

        try {
            $stmt = $this->conn->prepare('SELECT * FROM subscriptions WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $subscriptionId]);
            $row = $stmt->fetch();
            return $row ? $this->normalizeRow($row) : null;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getById');
            return $this->getById($subscriptionId);
        }
    }

    private function upsertByGatewaySubscriptionIdFile(array $normalized): array
    {
        $rows = $this->readRows();
        $matchIndex = null;

        $gatewayId = (string) ($normalized['razorpay_subscription_id'] ?? '');
        if ($gatewayId !== '') {
            foreach ($rows as $index => $row) {
                if ((string) ($row['razorpay_subscription_id'] ?? '') === $gatewayId) {
                    $matchIndex = $index;
                    break;
                }
            }
        }
        if ($matchIndex === null) {
            foreach ($rows as $index => $row) {
                if ((int) ($row['user_id'] ?? 0) === (int) ($normalized['user_id'] ?? 0)) {
                    $matchIndex = $index;
                    break;
                }
            }
        }

        $now = date('Y-m-d H:i:s');
        if ($matchIndex !== null) {
            $rows[$matchIndex] = array_merge($rows[$matchIndex], $normalized, ['updated_at' => $now]);
            $this->writeRows($rows);
            return ['success' => true, 'subscription' => $this->normalizeRow($rows[$matchIndex])];
        }

        $newRow = $normalized;
        $newRow['id'] = $this->nextId($rows);
        $newRow['created_at'] = $now;
        $newRow['updated_at'] = $now;
        $newRow['start_date'] = $normalized['current_period_start'] ?: $now;
        $newRow['end_date'] = $normalized['current_period_end'] ?: ($normalized['next_billing_date'] ?: date('Y-m-d H:i:s', strtotime('+1 month')));
        $rows[] = $newRow;
        $this->writeRows($rows);
        return ['success' => true, 'subscription' => $this->normalizeRow($newRow)];
    }

    private function normalizeInput(array $input): array
    {
        $plan = strtolower(trim((string) ($input['plan_type'] ?? 'free')));
        if (!in_array($plan, ['free', 'pro', 'agency'], true)) {
            $plan = 'free';
        }

        $status = strtolower(trim((string) ($input['status'] ?? 'incomplete')));
        if (!in_array($status, ['active', 'canceled', 'past_due', 'trialing', 'incomplete'], true)) {
            $status = 'incomplete';
        }

        return [
            'user_id' => (int) ($input['user_id'] ?? 0),
            'razorpay_customer_id' => $this->normalizeNullable((string) ($input['razorpay_customer_id'] ?? ''), 120),
            'razorpay_subscription_id' => $this->normalizeNullable((string) ($input['razorpay_subscription_id'] ?? ''), 120),
            'plan_type' => $plan,
            'status' => $status,
            'next_billing_date' => $this->normalizeDateTime((string) ($input['next_billing_date'] ?? '')),
            'grace_ends_at' => $this->normalizeDateTime((string) ($input['grace_ends_at'] ?? '')),
            'current_period_start' => $this->normalizeDateTime((string) ($input['current_period_start'] ?? '')),
            'current_period_end' => $this->normalizeDateTime((string) ($input['current_period_end'] ?? '')),
            'cancel_at_period_end' => !empty($input['cancel_at_period_end']) ? 1 : 0,
        ];
    }

    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'razorpay_customer_id' => $this->normalizeNullable((string) ($row['razorpay_customer_id'] ?? ''), 120),
            'razorpay_subscription_id' => $this->normalizeNullable((string) ($row['razorpay_subscription_id'] ?? ''), 120),
            'plan_type' => strtolower((string) ($row['plan_type'] ?? 'free')),
            'status' => strtolower((string) ($row['status'] ?? 'incomplete')),
            'next_billing_date' => $this->normalizeDateTime((string) ($row['next_billing_date'] ?? '')),
            'grace_ends_at' => $this->normalizeDateTime((string) ($row['grace_ends_at'] ?? '')),
            'current_period_start' => $this->normalizeDateTime((string) ($row['current_period_start'] ?? '')),
            'current_period_end' => $this->normalizeDateTime((string) ($row['current_period_end'] ?? '')),
            'cancel_at_period_end' => (int) (!empty($row['cancel_at_period_end'])),
            'created_at' => $this->normalizeDateTime((string) ($row['created_at'] ?? '')),
            'updated_at' => $this->normalizeDateTime((string) ($row['updated_at'] ?? '')),
            'start_date' => $this->normalizeDateTime((string) ($row['start_date'] ?? '')),
            'end_date' => $this->normalizeDateTime((string) ($row['end_date'] ?? '')),
        ];
    }

    private function normalizeNullable(string $value, int $maxLength): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $maxLength);
    }

    private function normalizeDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function readRows(): array
    {
        $raw = file_get_contents($this->file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeRows(array $rows): void
    {
        file_put_contents($this->file, json_encode($rows, JSON_PRETTY_PRINT), LOCK_EX);
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

    private function switchToFileStorage(Throwable $error, string $context): void
    {
        error_log('SubscriptionModel fallback (' . $context . '): ' . $error->getMessage());
        $this->useFileStorage = true;
    }
}

