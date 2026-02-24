<?php

require_once __DIR__ . '/../config/database.php';

class PaymentEventModel
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

        $this->file = $storageDir . '/payment_events.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function createUnique(
        string $gateway,
        string $gatewayEventId,
        string $eventType,
        array $payload,
        ?int $userId = null,
        ?int $subscriptionId = null
    ): array {
        $gateway = mb_substr(strtolower(trim($gateway) !== '' ? trim($gateway) : 'razorpay'), 0, 30);
        $gatewayEventId = mb_substr(trim($gatewayEventId), 0, 120);
        $eventType = mb_substr(trim($eventType), 0, 80);
        if ($gatewayEventId === '' || $eventType === '') {
            return ['success' => false, 'created' => false, 'error' => 'Invalid event id or type.'];
        }

        $payloadJson = json_encode($payload);
        if (!is_string($payloadJson) || $payloadJson === false) {
            $payloadJson = '{}';
        }
        $now = date('Y-m-d H:i:s');

        if ($this->useFileStorage) {
            $rows = $this->readRows();
            foreach ($rows as $row) {
                if ((string) ($row['gateway'] ?? '') === $gateway && (string) ($row['gateway_event_id'] ?? '') === $gatewayEventId) {
                    return ['success' => true, 'created' => false, 'id' => (int) ($row['id'] ?? 0)];
                }
            }

            $newRow = [
                'id' => $this->nextId($rows),
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'gateway' => $gateway,
                'gateway_event_id' => $gatewayEventId,
                'event_type' => $eventType,
                'payload_json' => json_decode($payloadJson, true),
                'processed_at' => $now,
            ];
            $rows[] = $newRow;
            if (count($rows) > 50000) {
                $rows = array_slice($rows, -50000);
            }
            $this->writeRows($rows);
            return ['success' => true, 'created' => true, 'id' => (int) $newRow['id']];
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO payment_events
                    (user_id, subscription_id, gateway, gateway_event_id, event_type, payload_json, processed_at)
                 VALUES
                    (:user_id, :subscription_id, :gateway, :gateway_event_id, :event_type, :payload_json, :processed_at)'
            );
            $stmt->bindValue(':user_id', $userId, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':subscription_id', $subscriptionId, $subscriptionId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':gateway', $gateway);
            $stmt->bindValue(':gateway_event_id', $gatewayEventId);
            $stmt->bindValue(':event_type', $eventType);
            $stmt->bindValue(':payload_json', $payloadJson);
            $stmt->bindValue(':processed_at', $now);
            $stmt->execute();
            return ['success' => true, 'created' => true, 'id' => (int) $this->conn->lastInsertId()];
        } catch (Throwable $error) {
            $message = strtolower((string) $error->getMessage());
            $code = (string) $error->getCode();
            if ($code === '23000' || str_contains($message, 'duplicate')) {
                return ['success' => true, 'created' => false, 'id' => 0];
            }
            $this->switchToFileStorage($error, 'createUnique');
            return $this->createUnique($gateway, $gatewayEventId, $eventType, $payload, $userId, $subscriptionId);
        }
    }

    public function getRecent(int $limit = 30): array
    {
        $limit = max(1, min(500, $limit));
        if ($this->useFileStorage) {
            $rows = $this->readRows();
            usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['processed_at'] ?? ''), (string) ($a['processed_at'] ?? '')));
            $rows = array_slice($rows, 0, $limit);
            return array_map(fn (array $row): array => $this->normalizeRow($row), $rows);
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT id, user_id, subscription_id, gateway, gateway_event_id, event_type, payload_json, processed_at
                 FROM payment_events
                 ORDER BY processed_at DESC, id DESC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return array_map(fn (array $row): array => $this->normalizeRow($row), $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getRecent');
            return $this->getRecent($limit);
        }
    }

    private function normalizeRow(array $row): array
    {
        $payload = $row['payload_json'] ?? [];
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($payload)) {
            $payload = [];
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'subscription_id' => isset($row['subscription_id']) ? (int) $row['subscription_id'] : null,
            'gateway' => (string) ($row['gateway'] ?? 'razorpay'),
            'gateway_event_id' => (string) ($row['gateway_event_id'] ?? ''),
            'event_type' => (string) ($row['event_type'] ?? ''),
            'payload' => $payload,
            'processed_at' => (string) ($row['processed_at'] ?? ''),
        ];
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
        error_log('PaymentEventModel fallback (' . $context . '): ' . $error->getMessage());
        $this->useFileStorage = true;
    }
}

