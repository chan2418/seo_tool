<?php

require_once __DIR__ . '/../config/database.php';

class AlertSettingModel
{
    private ?PDO $conn;
    private bool $useFileStorage = false;
    private string $settingsFile;

    public function __construct()
    {
        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $this->settingsFile = $storageDir . '/alert_settings.json';
        if (!file_exists($this->settingsFile)) {
            file_put_contents($this->settingsFile, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function getSettings(int $userId, int $projectId): array
    {
        $defaults = $this->defaultSettings($userId, $projectId);
        if ($userId <= 0 || $projectId <= 0) {
            return $defaults;
        }

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->settingsFile);
            foreach ($rows as $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if ((int) ($row['project_id'] ?? 0) !== $projectId) {
                    continue;
                }
                return $this->normalizeSettingRow($row);
            }
            return $defaults;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT id, user_id, project_id, rank_drop_threshold, seo_score_drop_threshold, email_notifications_enabled, created_at, updated_at
                 FROM alert_settings
                 WHERE user_id = :user_id
                   AND project_id = :project_id
                 LIMIT 1'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':project_id' => $projectId,
            ]);
            $row = $stmt->fetch();
            if (!$row) {
                return $defaults;
            }

            return $this->normalizeSettingRow($row);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getSettings');
            return $this->getSettings($userId, $projectId);
        }
    }

    public function saveSettings(
        int $userId,
        int $projectId,
        int $rankDropThreshold,
        int $seoDropThreshold,
        bool $emailEnabled
    ): array {
        if ($userId <= 0 || $projectId <= 0) {
            return $this->defaultSettings($userId, $projectId);
        }

        $rankDropThreshold = max(2, min(100, $rankDropThreshold));
        $seoDropThreshold = max(1, min(50, $seoDropThreshold));
        $now = date('Y-m-d H:i:s');

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->settingsFile);
            $updated = false;
            foreach ($rows as $index => $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if ((int) ($row['project_id'] ?? 0) !== $projectId) {
                    continue;
                }

                $rows[$index]['rank_drop_threshold'] = $rankDropThreshold;
                $rows[$index]['seo_score_drop_threshold'] = $seoDropThreshold;
                $rows[$index]['email_notifications_enabled'] = $emailEnabled ? 1 : 0;
                $rows[$index]['updated_at'] = $now;
                $updated = true;
                break;
            }

            if (!$updated) {
                $rows[] = [
                    'id' => $this->nextId($rows),
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'rank_drop_threshold' => $rankDropThreshold,
                    'seo_score_drop_threshold' => $seoDropThreshold,
                    'email_notifications_enabled' => $emailEnabled ? 1 : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $this->writeJsonFile($this->settingsFile, $rows);
            return $this->getSettings($userId, $projectId);
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO alert_settings
                    (user_id, project_id, rank_drop_threshold, seo_score_drop_threshold, email_notifications_enabled, created_at, updated_at)
                 VALUES
                    (:user_id, :project_id, :rank_drop_threshold, :seo_score_drop_threshold, :email_notifications_enabled, :created_at, :updated_at)
                 ON DUPLICATE KEY UPDATE
                    rank_drop_threshold = VALUES(rank_drop_threshold),
                    seo_score_drop_threshold = VALUES(seo_score_drop_threshold),
                    email_notifications_enabled = VALUES(email_notifications_enabled),
                    updated_at = VALUES(updated_at)'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':project_id' => $projectId,
                ':rank_drop_threshold' => $rankDropThreshold,
                ':seo_score_drop_threshold' => $seoDropThreshold,
                ':email_notifications_enabled' => $emailEnabled ? 1 : 0,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            return $this->getSettings($userId, $projectId);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'saveSettings');
            return $this->saveSettings($userId, $projectId, $rankDropThreshold, $seoDropThreshold, $emailEnabled);
        }
    }

    public function getEmailEnabledSettingsByUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->settingsFile);
            $result = [];
            foreach ($rows as $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if ((int) ($row['email_notifications_enabled'] ?? 0) !== 1) {
                    continue;
                }
                $result[] = $this->normalizeSettingRow($row);
            }
            return $result;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT id, user_id, project_id, rank_drop_threshold, seo_score_drop_threshold, email_notifications_enabled, created_at, updated_at
                 FROM alert_settings
                 WHERE user_id = :user_id
                   AND email_notifications_enabled = 1'
            );
            $stmt->execute([':user_id' => $userId]);
            $rows = $stmt->fetchAll();

            return array_map(fn (array $row): array => $this->normalizeSettingRow($row), $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getEmailEnabledSettingsByUser');
            return $this->getEmailEnabledSettingsByUser($userId);
        }
    }

    public function defaultSettings(int $userId, int $projectId): array
    {
        return [
            'id' => 0,
            'user_id' => $userId,
            'project_id' => $projectId,
            'rank_drop_threshold' => 10,
            'seo_score_drop_threshold' => 5,
            'email_notifications_enabled' => 0,
            'created_at' => '',
            'updated_at' => '',
        ];
    }

    private function normalizeSettingRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'project_id' => (int) ($row['project_id'] ?? 0),
            'rank_drop_threshold' => max(2, (int) ($row['rank_drop_threshold'] ?? 10)),
            'seo_score_drop_threshold' => max(1, (int) ($row['seo_score_drop_threshold'] ?? 5)),
            'email_notifications_enabled' => (int) (($row['email_notifications_enabled'] ?? 0) ? 1 : 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
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

    private function switchToFileStorage(Throwable $error, string $context): void
    {
        error_log('AlertSettingModel fallback (' . $context . '): ' . $error->getMessage());
        $this->useFileStorage = true;
    }
}
