<?php

require_once __DIR__ . '/../config/database.php';

class SearchConsoleDataModel
{
    private ?PDO $conn;
    private bool $useFileStorage = false;
    private string $cacheFile;
    private string $queriesFile;
    private string $pagesFile;

    public function __construct()
    {
        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $this->cacheFile = $storageDir . '/search_console_cache.json';
        $this->queriesFile = $storageDir . '/search_console_queries.json';
        $this->pagesFile = $storageDir . '/search_console_pages.json';

        if (!file_exists($this->cacheFile)) {
            file_put_contents($this->cacheFile, json_encode([]));
        }
        if (!file_exists($this->queriesFile)) {
            file_put_contents($this->queriesFile, json_encode([]));
        }
        if (!file_exists($this->pagesFile)) {
            file_put_contents($this->pagesFile, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function getLatestCache(int $projectId, string $dateRange): ?array
    {
        if ($projectId <= 0) {
            return null;
        }
        $dateRange = $this->normalizeRange($dateRange);

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->cacheFile);
            $latest = null;
            foreach ($rows as $row) {
                if ((int) ($row['project_id'] ?? 0) !== $projectId) {
                    continue;
                }
                if ((string) ($row['date_range'] ?? '') !== $dateRange) {
                    continue;
                }
                if ($latest === null || (string) ($row['fetched_at'] ?? '') > (string) ($latest['fetched_at'] ?? '')) {
                    $latest = $row;
                }
            }
            return $latest ? $this->normalizeCacheRow($latest) : null;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT id, project_id, date_range, total_clicks, total_impressions, avg_ctr, avg_position, trend_json, fetched_at
                 FROM search_console_cache
                 WHERE project_id = :project_id
                   AND date_range = :date_range
                 ORDER BY fetched_at DESC
                 LIMIT 1'
            );
            $stmt->execute([
                ':project_id' => $projectId,
                ':date_range' => $dateRange,
            ]);
            $row = $stmt->fetch();
            return $row ? $this->normalizeCacheRow($row) : null;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getLatestCache');
            return $this->getLatestCache($projectId, $dateRange);
        }
    }

    public function isCacheFresh(int $projectId, string $dateRange, int $hours = 24): bool
    {
        $latest = $this->getLatestCache($projectId, $dateRange);
        if (!$latest) {
            return false;
        }
        $fetchedAt = strtotime((string) ($latest['fetched_at'] ?? ''));
        if ($fetchedAt === false) {
            return false;
        }
        return $fetchedAt >= (time() - (max(1, $hours) * 3600));
    }

    public function saveCache(
        int $projectId,
        string $dateRange,
        float $totalClicks,
        float $totalImpressions,
        float $avgCtr,
        float $avgPosition,
        array $trendRows
    ): bool {
        if ($projectId <= 0) {
            return false;
        }
        $dateRange = $this->normalizeRange($dateRange);
        $now = date('Y-m-d H:i:s');
        $trendRows = $this->normalizeTrendRows($trendRows);

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->cacheFile);
            $updated = false;
            foreach ($rows as $index => $row) {
                if ((int) ($row['project_id'] ?? 0) !== $projectId) {
                    continue;
                }
                if ((string) ($row['date_range'] ?? '') !== $dateRange) {
                    continue;
                }
                if (strpos((string) ($row['fetched_at'] ?? ''), date('Y-m-d')) !== 0) {
                    continue;
                }

                $rows[$index]['total_clicks'] = $totalClicks;
                $rows[$index]['total_impressions'] = $totalImpressions;
                $rows[$index]['avg_ctr'] = $avgCtr;
                $rows[$index]['avg_position'] = $avgPosition;
                $rows[$index]['trend_json'] = $trendRows;
                $rows[$index]['fetched_at'] = $now;
                $updated = true;
                break;
            }

            if (!$updated) {
                $rows[] = [
                    'id' => $this->nextId($rows),
                    'project_id' => $projectId,
                    'date_range' => $dateRange,
                    'total_clicks' => $totalClicks,
                    'total_impressions' => $totalImpressions,
                    'avg_ctr' => $avgCtr,
                    'avg_position' => $avgPosition,
                    'trend_json' => $trendRows,
                    'fetched_at' => $now,
                ];
            }
            $this->writeJsonFile($this->cacheFile, $rows);
            return true;
        }

        try {
            $findStmt = $this->conn->prepare(
                'SELECT id
                 FROM search_console_cache
                 WHERE project_id = :project_id
                   AND date_range = :date_range
                   AND DATE(fetched_at) = :today
                 LIMIT 1'
            );
            $findStmt->execute([
                ':project_id' => $projectId,
                ':date_range' => $dateRange,
                ':today' => date('Y-m-d'),
            ]);
            $existing = $findStmt->fetch();

            if ($existing) {
                $updateStmt = $this->conn->prepare(
                    'UPDATE search_console_cache
                     SET total_clicks = :total_clicks,
                         total_impressions = :total_impressions,
                         avg_ctr = :avg_ctr,
                         avg_position = :avg_position,
                         trend_json = :trend_json,
                         fetched_at = :fetched_at
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    ':total_clicks' => $totalClicks,
                    ':total_impressions' => $totalImpressions,
                    ':avg_ctr' => $avgCtr,
                    ':avg_position' => $avgPosition,
                    ':trend_json' => json_encode($trendRows),
                    ':fetched_at' => $now,
                    ':id' => (int) ($existing['id'] ?? 0),
                ]);
                return true;
            }

            $insertStmt = $this->conn->prepare(
                'INSERT INTO search_console_cache
                    (project_id, date_range, total_clicks, total_impressions, avg_ctr, avg_position, trend_json, fetched_at)
                 VALUES
                    (:project_id, :date_range, :total_clicks, :total_impressions, :avg_ctr, :avg_position, :trend_json, :fetched_at)'
            );
            $insertStmt->execute([
                ':project_id' => $projectId,
                ':date_range' => $dateRange,
                ':total_clicks' => $totalClicks,
                ':total_impressions' => $totalImpressions,
                ':avg_ctr' => $avgCtr,
                ':avg_position' => $avgPosition,
                ':trend_json' => json_encode($trendRows),
                ':fetched_at' => $now,
            ]);
            return true;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'saveCache');
            return $this->saveCache($projectId, $dateRange, $totalClicks, $totalImpressions, $avgCtr, $avgPosition, $trendRows);
        }
    }

    public function getQueries(int $projectId, string $dateRange, int $limit = 50): array
    {
        return $this->getDimensionRows('queries', $projectId, $dateRange, $limit);
    }

    public function getPages(int $projectId, string $dateRange, int $limit = 50): array
    {
        return $this->getDimensionRows('pages', $projectId, $dateRange, $limit);
    }

    public function saveQueries(int $projectId, string $dateRange, array $rows): bool
    {
        return $this->saveDimensionRows('queries', $projectId, $dateRange, $rows);
    }

    public function savePages(int $projectId, string $dateRange, array $rows): bool
    {
        return $this->saveDimensionRows('pages', $projectId, $dateRange, $rows);
    }

    private function getDimensionRows(string $type, int $projectId, string $dateRange, int $limit): array
    {
        if ($projectId <= 0) {
            return [];
        }
        $dateRange = $this->normalizeRange($dateRange);
        $limit = max(1, min(200, $limit));
        $file = $type === 'queries' ? $this->queriesFile : $this->pagesFile;
        $table = $type === 'queries' ? 'search_console_queries' : 'search_console_pages';
        $key = $type === 'queries' ? 'query' : 'page_url';

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($file);
            $result = [];
            foreach ($rows as $row) {
                if ((int) ($row['project_id'] ?? 0) !== $projectId) {
                    continue;
                }
                if ((string) ($row['date_range'] ?? '') !== $dateRange) {
                    continue;
                }
                $result[] = $this->normalizeDimensionRow($row, $key);
            }
            usort($result, static fn (array $a, array $b): int => ((float) ($b['clicks'] ?? 0)) <=> ((float) ($a['clicks'] ?? 0)));
            return array_slice($result, 0, $limit);
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT id, project_id, ' . $key . ', clicks, impressions, ctr, position, date_range, created_at
                 FROM ' . $table . '
                 WHERE project_id = :project_id
                   AND date_range = :date_range
                 ORDER BY clicks DESC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute([
                ':project_id' => $projectId,
                ':date_range' => $dateRange,
            ]);
            $rows = $stmt->fetchAll();
            return array_map(fn (array $row): array => $this->normalizeDimensionRow($row, $key), $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getDimensionRows-' . $type);
            return $this->getDimensionRows($type, $projectId, $dateRange, $limit);
        }
    }

    private function saveDimensionRows(string $type, int $projectId, string $dateRange, array $rows): bool
    {
        if ($projectId <= 0) {
            return false;
        }
        $dateRange = $this->normalizeRange($dateRange);
        $rows = array_slice($rows, 0, 200);
        $file = $type === 'queries' ? $this->queriesFile : $this->pagesFile;
        $table = $type === 'queries' ? 'search_console_queries' : 'search_console_pages';
        $key = $type === 'queries' ? 'query' : 'page_url';
        $now = date('Y-m-d H:i:s');

        $normalized = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $normalized[] = [
                $key => mb_substr($value, 0, 2048),
                'clicks' => (float) ($row['clicks'] ?? 0),
                'impressions' => (float) ($row['impressions'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0),
                'position' => (float) ($row['position'] ?? 0),
            ];
        }

        if ($this->useFileStorage) {
            $current = $this->readJsonFile($file);
            $current = array_values(array_filter($current, static function (array $row) use ($projectId, $dateRange): bool {
                return !((int) ($row['project_id'] ?? 0) === $projectId && (string) ($row['date_range'] ?? '') === $dateRange);
            }));
            $nextId = $this->nextId($current);
            foreach ($normalized as $item) {
                $current[] = [
                    'id' => $nextId++,
                    'project_id' => $projectId,
                    $key => $item[$key],
                    'clicks' => $item['clicks'],
                    'impressions' => $item['impressions'],
                    'ctr' => $item['ctr'],
                    'position' => $item['position'],
                    'date_range' => $dateRange,
                    'created_at' => $now,
                ];
            }
            $this->writeJsonFile($file, $current);
            return true;
        }

        try {
            $deleteStmt = $this->conn->prepare(
                'DELETE FROM ' . $table . '
                 WHERE project_id = :project_id
                   AND date_range = :date_range'
            );
            $deleteStmt->execute([
                ':project_id' => $projectId,
                ':date_range' => $dateRange,
            ]);

            if (!empty($normalized)) {
                $insertStmt = $this->conn->prepare(
                    'INSERT INTO ' . $table . '
                        (project_id, ' . $key . ', clicks, impressions, ctr, position, date_range, created_at)
                     VALUES
                        (:project_id, :value, :clicks, :impressions, :ctr, :position, :date_range, :created_at)'
                );
                foreach ($normalized as $item) {
                    $insertStmt->execute([
                        ':project_id' => $projectId,
                        ':value' => $item[$key],
                        ':clicks' => $item['clicks'],
                        ':impressions' => $item['impressions'],
                        ':ctr' => $item['ctr'],
                        ':position' => $item['position'],
                        ':date_range' => $dateRange,
                        ':created_at' => $now,
                    ]);
                }
            }
            return true;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'saveDimensionRows-' . $type);
            return $this->saveDimensionRows($type, $projectId, $dateRange, $rows);
        }
    }

    private function normalizeCacheRow(array $row): array
    {
        $trend = $row['trend_json'] ?? [];
        if (is_string($trend)) {
            $decoded = json_decode($trend, true);
            $trend = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'project_id' => (int) ($row['project_id'] ?? 0),
            'date_range' => $this->normalizeRange((string) ($row['date_range'] ?? 'last_28_days')),
            'total_clicks' => (float) ($row['total_clicks'] ?? 0),
            'total_impressions' => (float) ($row['total_impressions'] ?? 0),
            'avg_ctr' => (float) ($row['avg_ctr'] ?? 0),
            'avg_position' => (float) ($row['avg_position'] ?? 0),
            'trend' => $this->normalizeTrendRows(is_array($trend) ? $trend : []),
            'fetched_at' => (string) ($row['fetched_at'] ?? ''),
        ];
    }

    private function normalizeDimensionRow(array $row, string $key): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'project_id' => (int) ($row['project_id'] ?? 0),
            $key => (string) ($row[$key] ?? ''),
            'clicks' => (float) ($row['clicks'] ?? 0),
            'impressions' => (float) ($row['impressions'] ?? 0),
            'ctr' => (float) ($row['ctr'] ?? 0),
            'position' => (float) ($row['position'] ?? 0),
            'date_range' => $this->normalizeRange((string) ($row['date_range'] ?? 'last_28_days')),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private function normalizeTrendRows(array $trendRows): array
    {
        $result = [];
        foreach ($trendRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $date = trim((string) ($row['date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $result[] = [
                'date' => $date,
                'clicks' => (float) ($row['clicks'] ?? 0),
                'impressions' => (float) ($row['impressions'] ?? 0),
                'position' => (float) ($row['position'] ?? 0),
            ];
        }
        usort($result, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
        return $result;
    }

    private function normalizeRange(string $dateRange): string
    {
        $dateRange = strtolower(trim($dateRange));
        if (!preg_match('/^last_\d+_days$/', $dateRange)) {
            return 'last_28_days';
        }
        return $dateRange;
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

    private function writeJsonFile(string $path, array $rows): void
    {
        file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function switchToFileStorage(Throwable $error, string $context): void
    {
        error_log('SearchConsoleDataModel fallback (' . $context . '): ' . $error->getMessage());
        $this->useFileStorage = true;
    }
}

