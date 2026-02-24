<?php

require_once __DIR__ . '/../config/database.php';

class RankingModel
{
    private ?PDO $conn;
    private bool $useFileStorage = false;
    private string $rankingsFile;
    private string $alertsFile;

    public function __construct()
    {
        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $this->rankingsFile = $storageDir . '/keyword_rankings.json';
        $this->alertsFile = $storageDir . '/rank_alerts.json';

        if (!file_exists($this->rankingsFile)) {
            file_put_contents($this->rankingsFile, json_encode([]));
        }

        if (!file_exists($this->alertsFile)) {
            file_put_contents($this->alertsFile, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function saveDailyRanking(
        int $trackedKeywordId,
        int $rankPosition,
        string $checkedDate,
        string $source = 'api',
        array $meta = []
    ): bool {
        if ($trackedKeywordId <= 0) {
            return false;
        }

        $rankPosition = max(1, min(999, $rankPosition));
        $checkedDate = $this->normalizeCheckedDate($checkedDate);
        $source = trim($source) !== '' ? trim($source) : 'api';
        $now = date('Y-m-d H:i:s');

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->rankingsFile);
            $updated = false;
            foreach ($rows as $index => $row) {
                if ((int) ($row['tracked_keyword_id'] ?? 0) !== $trackedKeywordId) {
                    continue;
                }
                if ((string) ($row['checked_date'] ?? '') !== $checkedDate) {
                    continue;
                }

                $rows[$index]['rank_position'] = $rankPosition;
                $rows[$index]['source'] = $source;
                $rows[$index]['meta_json'] = $meta;
                $rows[$index]['updated_at'] = $now;
                $updated = true;
                break;
            }

            if (!$updated) {
                $rows[] = [
                    'id' => $this->nextId($rows),
                    'tracked_keyword_id' => $trackedKeywordId,
                    'rank_position' => $rankPosition,
                    'checked_date' => $checkedDate,
                    'source' => $source,
                    'meta_json' => $meta,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $this->writeJsonFile($this->rankingsFile, $rows);
            return true;
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO keyword_rankings
                    (tracked_keyword_id, rank_position, checked_date, source, meta_json, created_at, updated_at)
                 VALUES
                    (:tracked_keyword_id, :rank_position, :checked_date, :source, :meta_json, :created_at, :updated_at)
                 ON DUPLICATE KEY UPDATE
                    rank_position = VALUES(rank_position),
                    source = VALUES(source),
                    meta_json = VALUES(meta_json),
                    updated_at = VALUES(updated_at)'
            );

            $stmt->execute([
                ':tracked_keyword_id' => $trackedKeywordId,
                ':rank_position' => $rankPosition,
                ':checked_date' => $checkedDate,
                ':source' => $source,
                ':meta_json' => json_encode($meta),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            return true;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'saveDailyRanking');
            return $this->saveDailyRanking($trackedKeywordId, $rankPosition, $checkedDate, $source, $meta);
        }
    }

    public function hasRankingForDate(int $trackedKeywordId, string $checkedDate): bool
    {
        if ($trackedKeywordId <= 0) {
            return false;
        }
        $checkedDate = $this->normalizeCheckedDate($checkedDate);

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->rankingsFile);
            foreach ($rows as $row) {
                if ((int) ($row['tracked_keyword_id'] ?? 0) !== $trackedKeywordId) {
                    continue;
                }
                if ((string) ($row['checked_date'] ?? '') === $checkedDate) {
                    return true;
                }
            }
            return false;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT id
                 FROM keyword_rankings
                 WHERE tracked_keyword_id = :tracked_keyword_id
                   AND checked_date = :checked_date
                 LIMIT 1'
            );
            $stmt->execute([
                ':tracked_keyword_id' => $trackedKeywordId,
                ':checked_date' => $checkedDate,
            ]);

            return (bool) $stmt->fetch();
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'hasRankingForDate');
            return $this->hasRankingForDate($trackedKeywordId, $checkedDate);
        }
    }

    public function getLatestAndPreviousByKeywordIds(array $trackedKeywordIds): array
    {
        $trackedKeywordIds = array_values(array_unique(array_map('intval', $trackedKeywordIds)));
        $trackedKeywordIds = array_values(array_filter($trackedKeywordIds, static fn (int $id): bool => $id > 0));

        $result = [];
        foreach ($trackedKeywordIds as $id) {
            $result[$id] = [
                'latest' => null,
                'previous' => null,
            ];
        }

        if (empty($trackedKeywordIds)) {
            return $result;
        }

        $rows = $this->getRankingRowsForKeywords($trackedKeywordIds);
        $perKeyword = [];
        foreach ($rows as $row) {
            $id = (int) ($row['tracked_keyword_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (!isset($perKeyword[$id])) {
                $perKeyword[$id] = [];
            }
            if (count($perKeyword[$id]) >= 2) {
                continue;
            }
            $perKeyword[$id][] = $row;
        }

        foreach ($trackedKeywordIds as $id) {
            $rowsForKeyword = $perKeyword[$id] ?? [];
            $result[$id]['latest'] = $rowsForKeyword[0] ?? null;
            $result[$id]['previous'] = $rowsForKeyword[1] ?? null;
        }

        return $result;
    }

    public function getBestRanksByKeywordIds(array $trackedKeywordIds): array
    {
        $trackedKeywordIds = array_values(array_unique(array_map('intval', $trackedKeywordIds)));
        $trackedKeywordIds = array_values(array_filter($trackedKeywordIds, static fn (int $id): bool => $id > 0));

        if (empty($trackedKeywordIds)) {
            return [];
        }

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->rankingsFile);
            $best = [];
            foreach ($rows as $row) {
                $id = (int) ($row['tracked_keyword_id'] ?? 0);
                if (!in_array($id, $trackedKeywordIds, true)) {
                    continue;
                }
                $rank = (int) ($row['rank_position'] ?? 999);
                if (!isset($best[$id]) || $rank < $best[$id]) {
                    $best[$id] = $rank;
                }
            }
            return $best;
        }

        try {
            [$placeholders, $params] = $this->buildInClause($trackedKeywordIds, 'id');
            $stmt = $this->conn->prepare(
                'SELECT tracked_keyword_id, MIN(rank_position) AS best_rank
                 FROM keyword_rankings
                 WHERE tracked_keyword_id IN (' . $placeholders . ')
                 GROUP BY tracked_keyword_id'
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $best = [];
            foreach ($rows as $row) {
                $best[(int) ($row['tracked_keyword_id'] ?? 0)] = (int) ($row['best_rank'] ?? 999);
            }
            return $best;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getBestRanksByKeywordIds');
            return $this->getBestRanksByKeywordIds($trackedKeywordIds);
        }
    }

    public function getHistory(int $trackedKeywordId, int $days = 30): array
    {
        if ($trackedKeywordId <= 0) {
            return [];
        }

        $days = max(2, min($days, 120));
        $cutoff = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->rankingsFile);
            $history = [];
            foreach ($rows as $row) {
                if ((int) ($row['tracked_keyword_id'] ?? 0) !== $trackedKeywordId) {
                    continue;
                }
                $checkedDate = (string) ($row['checked_date'] ?? '');
                if ($checkedDate < $cutoff) {
                    continue;
                }
                $history[] = [
                    'checked_date' => $checkedDate,
                    'rank_position' => (int) ($row['rank_position'] ?? 999),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'source' => (string) ($row['source'] ?? 'simulated'),
                ];
            }

            usort($history, static function (array $a, array $b): int {
                return strcmp((string) ($a['checked_date'] ?? ''), (string) ($b['checked_date'] ?? ''));
            });

            return $history;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT checked_date, rank_position, created_at, source
                 FROM keyword_rankings
                 WHERE tracked_keyword_id = :tracked_keyword_id
                   AND checked_date >= :cutoff
                 ORDER BY checked_date ASC, id ASC'
            );
            $stmt->execute([
                ':tracked_keyword_id' => $trackedKeywordId,
                ':cutoff' => $cutoff,
            ]);
            $rows = $stmt->fetchAll();

            return array_map(static function (array $row): array {
                return [
                    'checked_date' => (string) ($row['checked_date'] ?? ''),
                    'rank_position' => (int) ($row['rank_position'] ?? 999),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'source' => (string) ($row['source'] ?? 'simulated'),
                ];
            }, $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getHistory');
            return $this->getHistory($trackedKeywordId, $days);
        }
    }

    public function deleteByTrackedKeywordId(int $trackedKeywordId): void
    {
        if ($trackedKeywordId <= 0) {
            return;
        }

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->rankingsFile);
            $filtered = array_values(array_filter($rows, static function (array $row) use ($trackedKeywordId): bool {
                return (int) ($row['tracked_keyword_id'] ?? 0) !== $trackedKeywordId;
            }));
            $this->writeJsonFile($this->rankingsFile, $filtered);
            return;
        }

        try {
            $stmt = $this->conn->prepare('DELETE FROM keyword_rankings WHERE tracked_keyword_id = :tracked_keyword_id');
            $stmt->execute([':tracked_keyword_id' => $trackedKeywordId]);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'deleteByTrackedKeywordId');
            $this->deleteByTrackedKeywordId($trackedKeywordId);
        }
    }

    public function createAlert(
        int $userId,
        int $projectId,
        int $trackedKeywordId,
        string $alertType,
        string $message,
        int $previousRank,
        int $currentRank
    ): void {
        if ($userId <= 0 || $projectId <= 0 || $trackedKeywordId <= 0) {
            return;
        }

        $alertType = trim($alertType);
        if ($alertType === '') {
            $alertType = 'rank_change';
        }

        $entry = [
            'user_id' => $userId,
            'project_id' => $projectId,
            'tracked_keyword_id' => $trackedKeywordId,
            'alert_type' => $alertType,
            'message' => trim($message),
            'previous_rank' => $previousRank,
            'current_rank' => $currentRank,
            'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->alertsFile);
            $entry['id'] = $this->nextId($rows);
            $rows[] = $entry;
            if (count($rows) > 4000) {
                $rows = array_slice($rows, -4000);
            }
            $this->writeJsonFile($this->alertsFile, $rows);
            return;
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO rank_alerts
                    (user_id, project_id, tracked_keyword_id, alert_type, message, previous_rank, current_rank, is_read, created_at)
                 VALUES
                    (:user_id, :project_id, :tracked_keyword_id, :alert_type, :message, :previous_rank, :current_rank, :is_read, :created_at)'
            );
            $stmt->execute([
                ':user_id' => $entry['user_id'],
                ':project_id' => $entry['project_id'],
                ':tracked_keyword_id' => $entry['tracked_keyword_id'],
                ':alert_type' => $entry['alert_type'],
                ':message' => $entry['message'],
                ':previous_rank' => $entry['previous_rank'],
                ':current_rank' => $entry['current_rank'],
                ':is_read' => 0,
                ':created_at' => $entry['created_at'],
            ]);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'createAlert');
            $this->createAlert($userId, $projectId, $trackedKeywordId, $alertType, $message, $previousRank, $currentRank);
        }
    }

    public function getRecentAlerts(int $userId, int $limit = 10): array
    {
        $limit = max(1, min($limit, 100));

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->alertsFile);
            $filtered = [];
            foreach ($rows as $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                $filtered[] = $row;
            }

            usort($filtered, static function (array $a, array $b): int {
                return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
            });

            return array_slice($filtered, 0, $limit);
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT id, user_id, project_id, tracked_keyword_id, alert_type, message, previous_rank, current_rank, is_read, created_at
                 FROM rank_alerts
                 WHERE user_id = :user_id
                 ORDER BY created_at DESC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute([':user_id' => $userId]);
            $rows = $stmt->fetchAll();

            return array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'user_id' => (int) ($row['user_id'] ?? 0),
                    'project_id' => (int) ($row['project_id'] ?? 0),
                    'tracked_keyword_id' => (int) ($row['tracked_keyword_id'] ?? 0),
                    'alert_type' => (string) ($row['alert_type'] ?? ''),
                    'message' => (string) ($row['message'] ?? ''),
                    'previous_rank' => (int) ($row['previous_rank'] ?? 0),
                    'current_rank' => (int) ($row['current_rank'] ?? 0),
                    'is_read' => (int) ($row['is_read'] ?? 0),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }, $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getRecentAlerts');
            return $this->getRecentAlerts($userId, $limit);
        }
    }

    private function getRankingRowsForKeywords(array $trackedKeywordIds): array
    {
        if (empty($trackedKeywordIds)) {
            return [];
        }

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->rankingsFile);
            $filtered = [];
            foreach ($rows as $row) {
                $id = (int) ($row['tracked_keyword_id'] ?? 0);
                if (!in_array($id, $trackedKeywordIds, true)) {
                    continue;
                }
                $filtered[] = [
                    'tracked_keyword_id' => $id,
                    'rank_position' => (int) ($row['rank_position'] ?? 999),
                    'checked_date' => (string) ($row['checked_date'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'source' => (string) ($row['source'] ?? 'simulated'),
                ];
            }

            usort($filtered, static function (array $a, array $b): int {
                if ((int) ($a['tracked_keyword_id'] ?? 0) !== (int) ($b['tracked_keyword_id'] ?? 0)) {
                    return (int) ($a['tracked_keyword_id'] ?? 0) <=> (int) ($b['tracked_keyword_id'] ?? 0);
                }

                $cmp = strcmp((string) ($b['checked_date'] ?? ''), (string) ($a['checked_date'] ?? ''));
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
            });

            return $filtered;
        }

        try {
            [$placeholders, $params] = $this->buildInClause($trackedKeywordIds, 'id');
            $stmt = $this->conn->prepare(
                'SELECT tracked_keyword_id, rank_position, checked_date, created_at, source
                 FROM keyword_rankings
                 WHERE tracked_keyword_id IN (' . $placeholders . ')
                 ORDER BY tracked_keyword_id ASC, checked_date DESC, id DESC'
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            return array_map(static function (array $row): array {
                return [
                    'tracked_keyword_id' => (int) ($row['tracked_keyword_id'] ?? 0),
                    'rank_position' => (int) ($row['rank_position'] ?? 999),
                    'checked_date' => (string) ($row['checked_date'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'source' => (string) ($row['source'] ?? 'simulated'),
                ];
            }, $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getRankingRowsForKeywords');
            return $this->getRankingRowsForKeywords($trackedKeywordIds);
        }
    }

    private function buildInClause(array $ids, string $prefix): array
    {
        $params = [];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $key = ':' . $prefix . $index;
            $placeholders[] = $key;
            $params[$key] = (int) $id;
        }

        return [implode(', ', $placeholders), $params];
    }

    private function normalizeCheckedDate(string $checkedDate): string
    {
        $checkedDate = trim($checkedDate);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkedDate)) {
            return $checkedDate;
        }

        $timestamp = strtotime($checkedDate);
        if ($timestamp === false) {
            return date('Y-m-d');
        }

        return date('Y-m-d', $timestamp);
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
        error_log('RankingModel fallback (' . $context . '): ' . $error->getMessage());
        $this->useFileStorage = true;
    }
}
