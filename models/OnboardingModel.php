<?php

require_once __DIR__ . '/../config/database.php';

class OnboardingModel
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
        $this->file = $storageDir . '/onboarding_progress.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function setStep(int $userId, string $stepKey, bool $completed): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $stepKey = $this->sanitizeStepKey($stepKey);
        if ($stepKey === '') {
            return false;
        }

        $completedAt = $completed ? date('Y-m-d H:i:s') : null;
        if ($this->useFileStorage) {
            $rows = $this->readRows();
            $found = false;
            foreach ($rows as $index => $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId || (string) ($row['step_key'] ?? '') !== $stepKey) {
                    continue;
                }
                $rows[$index]['is_completed'] = $completed ? 1 : 0;
                $rows[$index]['completed_at'] = $completedAt;
                $found = true;
                break;
            }
            if (!$found) {
                $rows[] = [
                    'id' => $this->nextId($rows),
                    'user_id' => $userId,
                    'step_key' => $stepKey,
                    'is_completed' => $completed ? 1 : 0,
                    'completed_at' => $completedAt,
                ];
            }
            $this->writeRows($rows);
            return true;
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO onboarding_progress (user_id, step_key, is_completed, completed_at)
                 VALUES (:user_id, :step_key, :is_completed, :completed_at)
                 ON DUPLICATE KEY UPDATE
                    is_completed = VALUES(is_completed),
                    completed_at = VALUES(completed_at)'
            );
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':step_key', $stepKey);
            $stmt->bindValue(':is_completed', $completed ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':completed_at', $completedAt, $completedAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->execute();
            return true;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'setStep');
            return $this->setStep($userId, $stepKey, $completed);
        }
    }

    public function getSteps(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        if ($this->useFileStorage) {
            $rows = array_values(array_filter($this->readRows(), static fn (array $row): bool => (int) ($row['user_id'] ?? 0) === $userId));
            $steps = [];
            foreach ($rows as $row) {
                $stepKey = $this->sanitizeStepKey((string) ($row['step_key'] ?? ''));
                if ($stepKey === '') {
                    continue;
                }
                $steps[$stepKey] = [
                    'step_key' => $stepKey,
                    'is_completed' => !empty($row['is_completed']),
                    'completed_at' => isset($row['completed_at']) ? (string) $row['completed_at'] : null,
                ];
            }
            return $steps;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT step_key, is_completed, completed_at
                 FROM onboarding_progress
                 WHERE user_id = :user_id'
            );
            $stmt->execute([':user_id' => $userId]);
            $rows = $stmt->fetchAll();
            $steps = [];
            foreach ($rows as $row) {
                $stepKey = $this->sanitizeStepKey((string) ($row['step_key'] ?? ''));
                if ($stepKey === '') {
                    continue;
                }
                $steps[$stepKey] = [
                    'step_key' => $stepKey,
                    'is_completed' => !empty($row['is_completed']),
                    'completed_at' => isset($row['completed_at']) ? (string) $row['completed_at'] : null,
                ];
            }
            return $steps;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getSteps');
            return $this->getSteps($userId);
        }
    }

    private function sanitizeStepKey(string $stepKey): string
    {
        $stepKey = strtolower(trim($stepKey));
        $stepKey = preg_replace('/[^a-z0-9_\-]/', '_', $stepKey);
        if ($stepKey === '') {
            return '';
        }
        return mb_substr($stepKey, 0, 40);
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
        error_log('OnboardingModel fallback (' . $context . '): ' . $error->getMessage());
        $this->useFileStorage = true;
    }
}

