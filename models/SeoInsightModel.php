<?php

require_once __DIR__ . '/../config/database.php';

class SeoInsightModel
{
    private ?PDO $conn;
    private bool $useFileStorage = false;
    private string $insightsFile;

    public function __construct()
    {
        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $this->insightsFile = $storageDir . '/seo_insights.json';
        if (!file_exists($this->insightsFile)) {
            file_put_contents($this->insightsFile, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function createInsightUnique(
        int $projectId,
        ?string $keyword,
        ?string $pageUrl,
        string $insightType,
        string $message,
        string $severity,
        string $createdDate
    ): array {
        if ($projectId <= 0) {
            return ['success' => false, 'created' => false, 'error' => 'Invalid project id.'];
        }

        $keyword = $this->normalizeNullable($keyword, 255);
        $pageUrl = $this->normalizeNullable($pageUrl, 2048);
        $insightType = $this->sanitizeToken($insightType, 80, 'insight');
        $message = $this->sanitizeMessage($message);
        $severity = $this->sanitizeSeverity($severity);
        $date = $this->normalizeDate($createdDate);
        $createdAt = $date . ' ' . date('H:i:s');

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->insightsFile);
            foreach ($rows as $row) {
                if ((int) ($row['project_id'] ?? 0) !== $projectId) {
                    continue;
                }
                if ((string) ($row['insight_type'] ?? '') !== $insightType) {
                    continue;
                }
                if (($this->normalizeNullable((string) ($row['keyword'] ?? ''), 255) ?? null) !== $keyword) {
                    continue;
                }
                if (($this->normalizeNullable((string) ($row['page_url'] ?? ''), 2048) ?? null) !== $pageUrl) {
                    continue;
                }
                if (strpos((string) ($row['created_at'] ?? ''), $date) !== 0) {
                    continue;
                }

                return [
                    'success' => true,
                    'created' => false,
                    'id' => (int) ($row['id'] ?? 0),
                ];
            }

            $newRow = [
                'id' => $this->nextId($rows),
                'project_id' => $projectId,
                'keyword' => $keyword,
                'page_url' => $pageUrl,
                'insight_type' => $insightType,
                'message' => $message,
                'severity' => $severity,
                'created_at' => $createdAt,
            ];
            $rows[] = $newRow;
            if (count($rows) > 30000) {
                $rows = array_slice($rows, -30000);
            }
            $this->writeJsonFile($this->insightsFile, $rows);

            return [
                'success' => true,
                'created' => true,
                'id' => (int) $newRow['id'],
            ];
        }

        try {
            $checkStmt = $this->conn->prepare(
                'SELECT id
                 FROM seo_insights
                 WHERE project_id = :project_id
                   AND insight_type = :insight_type
                   AND DATE(created_at) = :created_date
                   AND ((keyword IS NULL AND :keyword IS NULL) OR keyword = :keyword)
                   AND ((page_url IS NULL AND :page_url IS NULL) OR page_url = :page_url)
                 LIMIT 1'
            );
            $checkStmt->bindValue(':project_id', $projectId, PDO::PARAM_INT);
            $checkStmt->bindValue(':insight_type', $insightType);
            $checkStmt->bindValue(':created_date', $date);
            $checkStmt->bindValue(':keyword', $keyword, $keyword === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $checkStmt->bindValue(':page_url', $pageUrl, $pageUrl === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $checkStmt->execute();
            $existing = $checkStmt->fetch();
            if ($existing) {
                return [
                    'success' => true,
                    'created' => false,
                    'id' => (int) ($existing['id'] ?? 0),
                ];
            }

            $insertStmt = $this->conn->prepare(
                'INSERT INTO seo_insights
                    (project_id, keyword, page_url, insight_type, message, severity, created_at)
                 VALUES
                    (:project_id, :keyword, :page_url, :insight_type, :message, :severity, :created_at)'
            );
            $insertStmt->bindValue(':project_id', $projectId, PDO::PARAM_INT);
            $insertStmt->bindValue(':keyword', $keyword, $keyword === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':page_url', $pageUrl, $pageUrl === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':insight_type', $insightType);
            $insertStmt->bindValue(':message', $message);
            $insertStmt->bindValue(':severity', $severity);
            $insertStmt->bindValue(':created_at', $createdAt);
            $insertStmt->execute();

            return [
                'success' => true,
                'created' => true,
                'id' => (int) $this->conn->lastInsertId(),
            ];
        } catch (Throwable $error) {
            $sqlState = (string) $error->getCode();
            if ($sqlState === '23000' || stripos($error->getMessage(), 'duplicate') !== false) {
                return ['success' => true, 'created' => false, 'id' => 0];
            }
            $this->switchToFileStorage($error, 'createInsightUnique');
            return $this->createInsightUnique($projectId, $keyword, $pageUrl, $insightType, $message, $severity, $date);
        }
    }

    public function deleteByProjectAndDate(int $projectId, string $date): int
    {
        if ($projectId <= 0) {
            return 0;
        }
        $date = $this->normalizeDate($date);

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->insightsFile);
            $count = 0;
            $filtered = [];
            foreach ($rows as $row) {
                $sameProject = (int) ($row['project_id'] ?? 0) === $projectId;
                $sameDate = strpos((string) ($row['created_at'] ?? ''), $date) === 0;
                if ($sameProject && $sameDate) {
                    $count++;
                    continue;
                }
                $filtered[] = $row;
            }
            if ($count > 0) {
                $this->writeJsonFile($this->insightsFile, $filtered);
            }
            return $count;
        }

        try {
            $stmt = $this->conn->prepare(
                'DELETE FROM seo_insights
                 WHERE project_id = :project_id
                   AND DATE(created_at) = :created_date'
            );
            $stmt->execute([
                ':project_id' => $projectId,
                ':created_date' => $date,
            ]);
            return $stmt->rowCount();
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'deleteByProjectAndDate');
            return $this->deleteByProjectAndDate($projectId, $date);
        }
    }

    public function deleteOlderThanDays(int $days): int
    {
        $days = max(1, min(365, $days));
        $cutoffDate = date('Y-m-d', strtotime('-' . $days . ' days'));

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->insightsFile);
            $count = 0;
            $filtered = [];
            foreach ($rows as $row) {
                $createdDate = substr((string) ($row['created_at'] ?? ''), 0, 10);
                if ($createdDate !== '' && $createdDate < $cutoffDate) {
                    $count++;
                    continue;
                }
                $filtered[] = $row;
            }
            if ($count > 0) {
                $this->writeJsonFile($this->insightsFile, $filtered);
            }
            return $count;
        }

        try {
            $stmt = $this->conn->prepare(
                'DELETE FROM seo_insights
                 WHERE DATE(created_at) < :cutoff_date'
            );
            $stmt->execute([':cutoff_date' => $cutoffDate]);
            return $stmt->rowCount();
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'deleteOlderThanDays');
            return $this->deleteOlderThanDays($days);
        }
    }

    public function getInsightsByProject(int $projectId, array $options = []): array
    {
        if ($projectId <= 0) {
            return [];
        }

        $days = max(1, min(365, (int) ($options['days'] ?? 28)));
        $limit = max(1, min(300, (int) ($options['limit'] ?? 120)));
        $cutoffDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        $severities = isset($options['severities']) && is_array($options['severities'])
            ? array_values(array_filter(array_map([$this, 'sanitizeSeverity'], $options['severities'])))
            : [];
        $severities = array_values(array_unique($severities));

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->insightsFile);
            $filtered = [];
            foreach ($rows as $row) {
                if ((int) ($row['project_id'] ?? 0) !== $projectId) {
                    continue;
                }
                $createdDate = substr((string) ($row['created_at'] ?? ''), 0, 10);
                if ($createdDate !== '' && $createdDate < $cutoffDate) {
                    continue;
                }
                $rowSeverity = $this->sanitizeSeverity((string) ($row['severity'] ?? 'info'));
                if (!empty($severities) && !in_array($rowSeverity, $severities, true)) {
                    continue;
                }
                $filtered[] = $this->normalizeRow($row);
            }
            usort($filtered, static function (array $a, array $b): int {
                $cmp = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
                if ($cmp !== 0) {
                    return $cmp;
                }
                return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
            });
            return array_slice($filtered, 0, $limit);
        }

        try {
            $where = [
                'project_id = :project_id',
                'DATE(created_at) >= :cutoff_date',
            ];
            $params = [
                ':project_id' => $projectId,
                ':cutoff_date' => $cutoffDate,
            ];

            if (!empty($severities)) {
                $severityParams = [];
                foreach ($severities as $index => $severity) {
                    $key = ':severity_' . $index;
                    $severityParams[] = $key;
                    $params[$key] = $severity;
                }
                $where[] = 'severity IN (' . implode(', ', $severityParams) . ')';
            }

            $sql = 'SELECT id, project_id, keyword, page_url, insight_type, message, severity, created_at
                    FROM seo_insights
                    WHERE ' . implode(' AND ', $where) . '
                    ORDER BY created_at DESC, id DESC
                    LIMIT ' . (int) $limit;
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            return array_map(fn (array $row): array => $this->normalizeRow($row), $rows ?: []);
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getInsightsByProject');
            return $this->getInsightsByProject($projectId, $options);
        }
    }

    public function getLatestCreatedAtByProject(int $projectId): ?string
    {
        if ($projectId <= 0) {
            return null;
        }

        if ($this->useFileStorage) {
            $rows = $this->readJsonFile($this->insightsFile);
            $latest = '';
            foreach ($rows as $row) {
                if ((int) ($row['project_id'] ?? 0) !== $projectId) {
                    continue;
                }
                $createdAt = (string) ($row['created_at'] ?? '');
                if ($createdAt > $latest) {
                    $latest = $createdAt;
                }
            }
            return $latest !== '' ? $latest : null;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT created_at
                 FROM seo_insights
                 WHERE project_id = :project_id
                 ORDER BY created_at DESC
                 LIMIT 1'
            );
            $stmt->execute([':project_id' => $projectId]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            $createdAt = (string) ($row['created_at'] ?? '');
            return $createdAt !== '' ? $createdAt : null;
        } catch (Throwable $error) {
            $this->switchToFileStorage($error, 'getLatestCreatedAtByProject');
            return $this->getLatestCreatedAtByProject($projectId);
        }
    }

    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'project_id' => (int) ($row['project_id'] ?? 0),
            'keyword' => $this->normalizeNullable((string) ($row['keyword'] ?? ''), 255),
            'page_url' => $this->normalizeNullable((string) ($row['page_url'] ?? ''), 2048),
            'insight_type' => $this->sanitizeToken((string) ($row['insight_type'] ?? 'insight'), 80, 'insight'),
            'message' => $this->sanitizeMessage((string) ($row['message'] ?? '')),
            'severity' => $this->sanitizeSeverity((string) ($row['severity'] ?? 'info')),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private function sanitizeToken(string $value, int $maxLength, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_\-]/', '_', $value);
        $value = trim((string) $value, '_');
        if ($value === '') {
            $value = $fallback;
        }
        return mb_substr($value, 0, $maxLength);
    }

    private function sanitizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));
        if (!in_array($severity, ['info', 'opportunity', 'warning'], true)) {
            return 'info';
        }
        return $severity;
    }

    private function sanitizeMessage(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message));
        if ($message === '') {
            $message = 'SEO insight generated.';
        }
        return mb_substr($message, 0, 700);
    }

    private function normalizeNullable(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $maxLength);
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return date('Y-m-d');
        }
        return date('Y-m-d', $timestamp);
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
        error_log('SeoInsightModel fallback (' . $context . '): ' . $error->getMessage());
        $this->useFileStorage = true;
    }
}

