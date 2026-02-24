<?php

require_once __DIR__ . '/../config/database.php';

class AIRequestModel
{
    private ?PDO $conn;

    public function __construct()
    {
        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;
    }

    public function hasConnection(): bool
    {
        return $this->conn instanceof PDO;
    }

    public function getSecuritySetting(string $key, ?string $default = null): ?string
    {
        if (!$this->hasConnection()) {
            return $default;
        }

        $stmt = $this->conn->prepare(
            'SELECT setting_value
             FROM security_settings
             WHERE setting_key = :setting_key
             LIMIT 1'
        );
        $stmt->execute([
            ':setting_key' => mb_substr(strtolower(trim($key)), 0, 80),
        ]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }

    public function getPlanAiMonthlyLimit(string $planType): int
    {
        $planType = $this->normalizePlan($planType);
        $defaults = [
            'free' => 3,
            'pro' => 20,
            'agency' => 100,
        ];

        if (!$this->hasConnection()) {
            return $defaults[$planType] ?? 3;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT ai_monthly_limit
                 FROM plan_limits
                 WHERE plan_type = :plan_type
                 LIMIT 1'
            );
            $stmt->execute([':plan_type' => $planType]);
            $value = $stmt->fetchColumn();
            if ($value === false || $value === null) {
                return $defaults[$planType] ?? 3;
            }
            return max(1, (int) $value);
        } catch (Throwable $error) {
            return $defaults[$planType] ?? 3;
        }
    }

    public function getMonthlyUsage(int $userId, string $month): array
    {
        if (!$this->hasConnection() || $userId <= 0) {
            return ['request_count' => 0, 'last_request_at' => null];
        }

        $month = $this->normalizeMonth($month);
        $stmt = $this->conn->prepare(
            'SELECT request_count, last_request_at
             FROM ai_usage
             WHERE user_id = :user_id
               AND month = :month
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':month' => $month,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['request_count' => 0, 'last_request_at' => null];
        }
        return [
            'request_count' => (int) ($row['request_count'] ?? 0),
            'last_request_at' => $row['last_request_at'] ?? null,
        ];
    }

    public function incrementMonthlyUsage(int $userId, string $month): bool
    {
        if (!$this->hasConnection() || $userId <= 0) {
            return false;
        }

        $month = $this->normalizeMonth($month);
        $stmt = $this->conn->prepare(
            'INSERT INTO ai_usage (user_id, month, request_count, last_request_at, created_at, updated_at)
             VALUES (:user_id, :month, 1, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                request_count = request_count + 1,
                last_request_at = NOW(),
                updated_at = NOW()'
        );
        return $stmt->execute([
            ':user_id' => $userId,
            ':month' => $month,
        ]);
    }

    public function createQueueRequest(int $userId, int $projectId, string $requestType, array $payload, string $status = 'pending'): int
    {
        if (!$this->hasConnection() || $userId <= 0 || $projectId <= 0) {
            return 0;
        }

        $requestType = $this->normalizeRequestType($requestType);
        $status = $this->normalizeStatus($status);
        $stmt = $this->conn->prepare(
            'INSERT INTO ai_request_queue
                (user_id, project_id, request_type, request_payload, status, started_at, created_at, updated_at)
             VALUES
                (:user_id, :project_id, :request_type, :request_payload, :status, :started_at, NOW(), NOW())'
        );
        $startedAt = $status === 'processing' ? date('Y-m-d H:i:s') : null;
        $ok = $stmt->execute([
            ':user_id' => $userId,
            ':project_id' => $projectId,
            ':request_type' => $requestType,
            ':request_payload' => json_encode($payload),
            ':status' => $status,
            ':started_at' => $startedAt,
        ]);

        return $ok ? (int) $this->conn->lastInsertId() : 0;
    }

    public function claimPendingRequestById(int $requestId, int $concurrencyLimit): bool
    {
        if (!$this->hasConnection() || $requestId <= 0) {
            return false;
        }
        $concurrencyLimit = max(1, min(200, $concurrencyLimit));

        try {
            $this->conn->beginTransaction();

            $countStmt = $this->conn->query('SELECT COUNT(*) FROM ai_request_queue WHERE status = "processing" FOR UPDATE');
            $processingCount = (int) $countStmt->fetchColumn();
            if ($processingCount >= $concurrencyLimit) {
                $this->conn->rollBack();
                return false;
            }

            $updateStmt = $this->conn->prepare(
                'UPDATE ai_request_queue
                 SET status = "processing",
                     started_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id
                   AND status = "pending"'
            );
            $updateStmt->execute([':id' => $requestId]);
            $claimed = $updateStmt->rowCount() > 0;

            if (!$claimed) {
                $this->conn->rollBack();
                return false;
            }

            $this->conn->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return false;
        }
    }

    public function claimNextPendingRequest(int $concurrencyLimit): ?int
    {
        if (!$this->hasConnection()) {
            return null;
        }
        $concurrencyLimit = max(1, min(200, $concurrencyLimit));

        try {
            $this->conn->beginTransaction();

            $countStmt = $this->conn->query('SELECT COUNT(*) FROM ai_request_queue WHERE status = "processing" FOR UPDATE');
            $processingCount = (int) $countStmt->fetchColumn();
            if ($processingCount >= $concurrencyLimit) {
                $this->conn->rollBack();
                return null;
            }

            $selectStmt = $this->conn->query(
                'SELECT id
                 FROM ai_request_queue
                 WHERE status = "pending"
                 ORDER BY created_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            $row = $selectStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->conn->rollBack();
                return null;
            }

            $requestId = (int) ($row['id'] ?? 0);
            if ($requestId <= 0) {
                $this->conn->rollBack();
                return null;
            }

            $updateStmt = $this->conn->prepare(
                'UPDATE ai_request_queue
                 SET status = "processing",
                     started_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id
                   AND status = "pending"'
            );
            $updateStmt->execute([':id' => $requestId]);
            if ($updateStmt->rowCount() <= 0) {
                $this->conn->rollBack();
                return null;
            }

            $this->conn->commit();
            return $requestId;
        } catch (Throwable $error) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return null;
        }
    }

    public function countProcessing(): int
    {
        if (!$this->hasConnection()) {
            return 0;
        }
        return (int) $this->singleInt('SELECT COUNT(*) FROM ai_request_queue WHERE status = "processing"');
    }

    public function countPendingBeforeRequest(int $requestId): int
    {
        if (!$this->hasConnection() || $requestId <= 0) {
            return 0;
        }

        $stmt = $this->conn->prepare(
            'SELECT COUNT(*)
             FROM ai_request_queue q1
             INNER JOIN ai_request_queue q2 ON q2.id = :request_id
             WHERE q1.status = "pending"
               AND (q1.created_at < q2.created_at OR (q1.created_at = q2.created_at AND q1.id <= q2.id))'
        );
        $stmt->execute([':request_id' => $requestId]);
        return max(0, (int) $stmt->fetchColumn());
    }

    public function markRequestCompleted(int $requestId, array $responsePayload, int $tokensUsed = 0, float $costEstimate = 0.0): bool
    {
        if (!$this->hasConnection() || $requestId <= 0) {
            return false;
        }

        $stmt = $this->conn->prepare(
            'UPDATE ai_request_queue
             SET status = "completed",
                 response_payload = :response_payload,
                 tokens_used = :tokens_used,
                 cost_estimate = :cost_estimate,
                 error_message = NULL,
                 completed_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id'
        );
        return $stmt->execute([
            ':response_payload' => json_encode($responsePayload),
            ':tokens_used' => max(0, $tokensUsed),
            ':cost_estimate' => max(0, $costEstimate),
            ':id' => $requestId,
        ]);
    }

    public function markRequestFailed(int $requestId, string $errorMessage): bool
    {
        if (!$this->hasConnection() || $requestId <= 0) {
            return false;
        }

        $stmt = $this->conn->prepare(
            'UPDATE ai_request_queue
             SET status = "failed",
                 error_message = :error_message,
                 completed_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id'
        );
        return $stmt->execute([
            ':error_message' => mb_substr(trim($errorMessage), 0, 700),
            ':id' => $requestId,
        ]);
    }

    public function recoverStuckProcessing(int $minutes = 15): int
    {
        if (!$this->hasConnection()) {
            return 0;
        }
        $minutes = max(1, min(240, $minutes));
        $stmt = $this->conn->prepare(
            'UPDATE ai_request_queue
             SET status = "failed",
                 error_message = "Timed out while processing. Please retry.",
                 completed_at = NOW(),
                 updated_at = NOW()
             WHERE status = "processing"
               AND started_at < DATE_SUB(NOW(), INTERVAL :minutes MINUTE)'
        );
        $stmt->bindValue(':minutes', $minutes, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function getQueueRequestById(int $requestId): ?array
    {
        if (!$this->hasConnection() || $requestId <= 0) {
            return null;
        }
        $stmt = $this->conn->prepare(
            'SELECT id, user_id, project_id, request_type, request_payload, response_payload, status, tokens_used, cost_estimate, error_message, started_at, completed_at, created_at, updated_at
             FROM ai_request_queue
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeQueueRow($row) : null;
    }

    public function getQueueRequestByIdForUser(int $requestId, int $userId): ?array
    {
        if (!$this->hasConnection() || $requestId <= 0 || $userId <= 0) {
            return null;
        }
        $stmt = $this->conn->prepare(
            'SELECT id, user_id, project_id, request_type, request_payload, response_payload, status, tokens_used, cost_estimate, error_message, started_at, completed_at, created_at, updated_at
             FROM ai_request_queue
             WHERE id = :id
               AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            ':id' => $requestId,
            ':user_id' => $userId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeQueueRow($row) : null;
    }

    public function getRecentRequestsByUser(int $userId, int $limit = 20): array
    {
        if (!$this->hasConnection() || $userId <= 0) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $stmt = $this->conn->prepare(
            'SELECT id, user_id, project_id, request_type, request_payload, response_payload, status, tokens_used, cost_estimate, error_message, started_at, completed_at, created_at, updated_at
             FROM ai_request_queue
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn (array $row): array => $this->normalizeQueueRow($row), $rows);
    }

    public function logCost(int $userId, ?int $requestId, int $tokensUsed, float $costEstimate, ?string $modelName = null): bool
    {
        if (!$this->hasConnection() || $userId <= 0) {
            return false;
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO ai_cost_logs
                (user_id, request_id, tokens_used, cost_estimate, model_name, created_at)
             VALUES
                (:user_id, :request_id, :tokens_used, :cost_estimate, :model_name, NOW())'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':request_id', $requestId, $requestId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':tokens_used', max(0, $tokensUsed), PDO::PARAM_INT);
        $stmt->bindValue(':cost_estimate', max(0, $costEstimate), PDO::PARAM_STR);
        $stmt->bindValue(':model_name', $modelName !== null ? mb_substr(trim($modelName), 0, 80) : null, $modelName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        return $stmt->execute();
    }

    private function normalizeQueueRow(array $row): array
    {
        $requestPayload = $row['request_payload'] ?? null;
        if (is_string($requestPayload)) {
            $decoded = json_decode($requestPayload, true);
            $requestPayload = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($requestPayload)) {
            $requestPayload = [];
        }

        $responsePayload = $row['response_payload'] ?? null;
        if (is_string($responsePayload)) {
            $decoded = json_decode($responsePayload, true);
            $responsePayload = is_array($decoded) ? $decoded : null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'project_id' => (int) ($row['project_id'] ?? 0),
            'request_type' => $this->normalizeRequestType((string) ($row['request_type'] ?? 'advisor')),
            'request_payload' => $requestPayload,
            'response_payload' => is_array($responsePayload) ? $responsePayload : null,
            'status' => $this->normalizeStatus((string) ($row['status'] ?? 'pending')),
            'tokens_used' => (int) ($row['tokens_used'] ?? 0),
            'cost_estimate' => (float) ($row['cost_estimate'] ?? 0),
            'error_message' => (string) ($row['error_message'] ?? ''),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'completed_at' => (string) ($row['completed_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function normalizeMonth(string $month): string
    {
        $month = trim($month);
        if (!preg_match('/^\d{4}\-(0[1-9]|1[0-2])$/', $month)) {
            return date('Y-m');
        }
        return $month;
    }

    private function normalizePlan(string $planType): string
    {
        $planType = strtolower(trim($planType));
        if (!in_array($planType, ['free', 'pro', 'agency'], true)) {
            return 'free';
        }
        return $planType;
    }

    private function normalizeRequestType(string $requestType): string
    {
        $requestType = strtolower(trim($requestType));
        if (!in_array($requestType, ['advisor', 'meta', 'optimizer'], true)) {
            return 'advisor';
        }
        return $requestType;
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['pending', 'processing', 'completed', 'failed'], true)) {
            return 'pending';
        }
        return $status;
    }

    private function singleInt(string $sql): int
    {
        if (!$this->hasConnection()) {
            return 0;
        }
        try {
            $value = $this->conn->query($sql)->fetchColumn();
            return (int) ($value !== false ? $value : 0);
        } catch (Throwable $error) {
            return 0;
        }
    }
}

