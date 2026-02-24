<?php

require_once __DIR__ . '/../config/database.php';

class CrawlModel
{
    private ?PDO $conn;
    private bool $useFileStorage = false;
    private string $runsFile;
    private string $pagesFile;
    private string $logFile;

    public function __construct()
    {
        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $this->runsFile = $storageDir . '/crawl_runs.json';
        $this->pagesFile = $storageDir . '/crawl_pages.json';
        $this->logFile = $storageDir . '/phase3_request_logs.json';

        if (!file_exists($this->runsFile)) {
            file_put_contents($this->runsFile, json_encode([]));
        }

        if (!file_exists($this->pagesFile)) {
            file_put_contents($this->pagesFile, json_encode([]));
        }

        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, json_encode([]));
        }

        if (!$this->conn) {
            $this->useFileStorage = true;
        }
    }

    public function createRun(int $userId, string $startUrl, string $domain, array $queue): int
    {
        $queue = array_values($queue);
        $totalPages = count($queue);

        if ($this->useFileStorage) {
            $runs = $this->readJsonFile($this->runsFile);
            $newId = $this->nextRunId($runs);
            $runs[] = [
                'id' => $newId,
                'user_id' => $userId,
                'start_url' => $startUrl,
                'domain' => $domain,
                'status' => 'running',
                'progress' => 0,
                'total_pages' => $totalPages,
                'pages_completed' => 0,
                'queue_json' => $queue,
                'summary_json' => [
                    'issues' => [
                        'duplicate_titles' => 0,
                        'missing_h1' => 0,
                        'missing_meta_description' => 0,
                        'broken_links' => 0,
                        'thin_content' => 0,
                    ],
                    'title_registry' => [],
                ],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $this->writeJsonFile($this->runsFile, $runs);

            return $newId;
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO crawl_runs
                    (user_id, start_url, domain, status, progress, total_pages, pages_completed, queue_json, summary_json, created_at, updated_at)
                 VALUES
                    (:user_id, :start_url, :domain, :status, :progress, :total_pages, :pages_completed, :queue_json, :summary_json, :created_at, :updated_at)'
            );

            $now = date('Y-m-d H:i:s');
            $stmt->execute([
                ':user_id' => $userId,
                ':start_url' => $startUrl,
                ':domain' => $domain,
                ':status' => 'running',
                ':progress' => 0,
                ':total_pages' => $totalPages,
                ':pages_completed' => 0,
                ':queue_json' => json_encode($queue),
                ':summary_json' => json_encode([
                    'issues' => [
                        'duplicate_titles' => 0,
                        'missing_h1' => 0,
                        'missing_meta_description' => 0,
                        'broken_links' => 0,
                        'thin_content' => 0,
                    ],
                    'title_registry' => [],
                ]),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            return (int) $this->conn->lastInsertId();
        } catch (Throwable $error) {
            // If DB schema is missing/incompatible, fallback to file storage so crawl can still start.
            error_log('Crawl run create failed in DB mode, switching to file storage: ' . $error->getMessage());
            $this->useFileStorage = true;

            return $this->createRun($userId, $startUrl, $domain, $queue);
        }
    }

    public function getRun(int $runId, int $userId): ?array
    {
        if ($this->useFileStorage) {
            $runs = $this->readJsonFile($this->runsFile);
            foreach ($runs as $run) {
                if ((int) ($run['id'] ?? 0) === $runId && (int) ($run['user_id'] ?? 0) === $userId) {
                    return $this->normalizeRun($run);
                }
            }
            return null;
        }

        try {
            $stmt = $this->conn->prepare('SELECT * FROM crawl_runs WHERE id = :id AND user_id = :user_id LIMIT 1');
            $stmt->execute([
                ':id' => $runId,
                ':user_id' => $userId,
            ]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }

            return $this->normalizeRun($row);
        } catch (Throwable $error) {
            error_log('Crawl run fetch failed: ' . $error->getMessage());
            return null;
        }
    }

    public function updateRun(int $runId, int $userId, array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        $fields['updated_at'] = date('Y-m-d H:i:s');

        if ($this->useFileStorage) {
            $runs = $this->readJsonFile($this->runsFile);
            foreach ($runs as &$run) {
                if ((int) ($run['id'] ?? 0) !== $runId || (int) ($run['user_id'] ?? 0) !== $userId) {
                    continue;
                }

                foreach ($fields as $key => $value) {
                    if (in_array($key, ['queue_json', 'summary_json'], true) && !is_array($value)) {
                        $decoded = json_decode((string) $value, true);
                        $run[$key] = is_array($decoded) ? $decoded : $value;
                    } else {
                        $run[$key] = $value;
                    }
                }
                break;
            }
            unset($run);

            $this->writeJsonFile($this->runsFile, $runs);
            return;
        }

        try {
            $setClauses = [];
            $params = [
                ':id' => $runId,
                ':user_id' => $userId,
            ];

            foreach ($fields as $key => $value) {
                $paramName = ':' . $key;
                $setClauses[] = $key . ' = ' . $paramName;
                $params[$paramName] = in_array($key, ['queue_json', 'summary_json'], true) && is_array($value)
                    ? json_encode($value)
                    : $value;
            }

            if (empty($setClauses)) {
                return;
            }

            $sql = 'UPDATE crawl_runs SET ' . implode(', ', $setClauses) . ' WHERE id = :id AND user_id = :user_id';
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
        } catch (Throwable $error) {
            error_log('Crawl run update failed: ' . $error->getMessage());
        }
    }

    public function addPageResult(int $runId, array $pageResult): void
    {
        if ($this->useFileStorage) {
            $pagesByRun = $this->readJsonFile($this->pagesFile);
            $runKey = (string) $runId;
            if (!isset($pagesByRun[$runKey]) || !is_array($pagesByRun[$runKey])) {
                $pagesByRun[$runKey] = [];
            }
            $pagesByRun[$runKey][] = $pageResult;
            $this->writeJsonFile($this->pagesFile, $pagesByRun);
            return;
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO crawl_pages
                    (run_id, url, title, meta_description, h1_count, word_count, broken_links, is_thin_content, has_missing_meta, has_missing_h1, content_hash, issues_json, created_at)
                 VALUES
                    (:run_id, :url, :title, :meta_description, :h1_count, :word_count, :broken_links, :is_thin_content, :has_missing_meta, :has_missing_h1, :content_hash, :issues_json, :created_at)'
            );

            $stmt->execute([
                ':run_id' => $runId,
                ':url' => (string) ($pageResult['url'] ?? ''),
                ':title' => (string) ($pageResult['title'] ?? ''),
                ':meta_description' => (string) ($pageResult['meta_description'] ?? ''),
                ':h1_count' => (int) ($pageResult['h1_count'] ?? 0),
                ':word_count' => (int) ($pageResult['word_count'] ?? 0),
                ':broken_links' => (int) ($pageResult['broken_links'] ?? 0),
                ':is_thin_content' => !empty($pageResult['is_thin_content']) ? 1 : 0,
                ':has_missing_meta' => !empty($pageResult['has_missing_meta']) ? 1 : 0,
                ':has_missing_h1' => !empty($pageResult['has_missing_h1']) ? 1 : 0,
                ':content_hash' => (string) ($pageResult['content_hash'] ?? ''),
                ':issues_json' => json_encode($pageResult['issues'] ?? []),
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $error) {
            error_log('Crawl page save failed: ' . $error->getMessage());
        }
    }

    public function getPageResults(int $runId): array
    {
        if ($this->useFileStorage) {
            $pagesByRun = $this->readJsonFile($this->pagesFile);
            $pages = $pagesByRun[(string) $runId] ?? [];
            return is_array($pages) ? array_values($pages) : [];
        }

        try {
            $stmt = $this->conn->prepare('SELECT * FROM crawl_pages WHERE run_id = :run_id ORDER BY id ASC');
            $stmt->execute([':run_id' => $runId]);
            $rows = $stmt->fetchAll();

            $results = [];
            foreach ($rows as $row) {
                $issues = json_decode((string) ($row['issues_json'] ?? ''), true);
                $results[] = [
                    'url' => (string) ($row['url'] ?? ''),
                    'title' => (string) ($row['title'] ?? ''),
                    'meta_description' => (string) ($row['meta_description'] ?? ''),
                    'h1_count' => (int) ($row['h1_count'] ?? 0),
                    'word_count' => (int) ($row['word_count'] ?? 0),
                    'broken_links' => (int) ($row['broken_links'] ?? 0),
                    'is_thin_content' => (int) ($row['is_thin_content'] ?? 0) === 1,
                    'has_missing_meta' => (int) ($row['has_missing_meta'] ?? 0) === 1,
                    'has_missing_h1' => (int) ($row['has_missing_h1'] ?? 0) === 1,
                    'content_hash' => (string) ($row['content_hash'] ?? ''),
                    'issues' => is_array($issues) ? $issues : [],
                ];
            }

            return $results;
        } catch (Throwable $error) {
            error_log('Crawl page fetch failed: ' . $error->getMessage());
            return [];
        }
    }

    public function countRecentRequests(int $userId, int $windowSeconds = 60): int
    {
        $cutoffTimestamp = time() - $windowSeconds;

        if ($this->useFileStorage) {
            $logs = $this->readJsonFile($this->logFile);
            $count = 0;
            foreach ($logs as $log) {
                if ((int) ($log['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if (($log['module'] ?? '') !== 'crawl') {
                    continue;
                }
                $createdAt = strtotime((string) ($log['created_at'] ?? ''));
                if ($createdAt !== false && $createdAt >= $cutoffTimestamp) {
                    $count++;
                }
            }
            return $count;
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT COUNT(*) AS total
                 FROM phase3_request_logs
                 WHERE user_id = :user_id
                   AND module = :module
                   AND created_at >= :cutoff'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':module' => 'crawl',
                ':cutoff' => date('Y-m-d H:i:s', $cutoffTimestamp),
            ]);
            $row = $stmt->fetch();

            return (int) ($row['total'] ?? 0);
        } catch (Throwable $error) {
            return 0;
        }
    }

    public function logRequest(int $userId, string $requestKey, string $source, int $statusCode): void
    {
        if ($this->useFileStorage) {
            $logs = $this->readJsonFile($this->logFile);
            $logs[] = [
                'user_id' => $userId,
                'module' => 'crawl',
                'request_key' => $requestKey,
                'source' => $source,
                'status_code' => $statusCode,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            if (count($logs) > 4000) {
                $logs = array_slice($logs, -4000);
            }
            $this->writeJsonFile($this->logFile, $logs);
            return;
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO phase3_request_logs
                    (user_id, module, request_key, source, status_code, created_at)
                 VALUES
                    (:user_id, :module, :request_key, :source, :status_code, :created_at)'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':module' => 'crawl',
                ':request_key' => $requestKey,
                ':source' => $source,
                ':status_code' => $statusCode,
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $error) {
            error_log('Crawl request log failed: ' . $error->getMessage());
        }
    }

    public function getRecentRuns(int $userId, int $limit = 10): array
    {
        if ($this->useFileStorage) {
            $runs = $this->readJsonFile($this->runsFile);
            $filtered = array_filter($runs, static fn ($run) => (int) ($run['user_id'] ?? 0) === $userId);
            usort($filtered, static fn ($a, $b) => strtotime((string) ($b['created_at'] ?? '')) <=> strtotime((string) ($a['created_at'] ?? '')));
            return array_slice(array_map(fn ($run) => $this->normalizeRun($run), $filtered), 0, $limit);
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT * FROM crawl_runs
                 WHERE user_id = :user_id
                 ORDER BY created_at DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            return array_map(fn ($row) => $this->normalizeRun($row), $rows ?: []);
        } catch (Throwable $error) {
            return [];
        }
    }

    private function normalizeRun(array $run): array
    {
        $queue = $run['queue_json'] ?? [];
        $summary = $run['summary_json'] ?? [];

        if (is_string($queue)) {
            $decodedQueue = json_decode($queue, true);
            $queue = is_array($decodedQueue) ? $decodedQueue : [];
        }

        if (is_string($summary)) {
            $decodedSummary = json_decode($summary, true);
            $summary = is_array($decodedSummary) ? $decodedSummary : [];
        }

        return [
            'id' => (int) ($run['id'] ?? 0),
            'user_id' => (int) ($run['user_id'] ?? 0),
            'start_url' => (string) ($run['start_url'] ?? ''),
            'domain' => (string) ($run['domain'] ?? ''),
            'status' => (string) ($run['status'] ?? 'pending'),
            'progress' => (int) ($run['progress'] ?? 0),
            'total_pages' => (int) ($run['total_pages'] ?? 0),
            'pages_completed' => (int) ($run['pages_completed'] ?? 0),
            'technical_score' => (int) ($run['technical_score'] ?? 0),
            'content_score' => (int) ($run['content_score'] ?? 0),
            'authority_score' => (int) ($run['authority_score'] ?? 0),
            'keyword_score' => (int) ($run['keyword_score'] ?? 0),
            'final_score' => (int) ($run['final_score'] ?? 0),
            'queue_json' => is_array($queue) ? $queue : [],
            'summary_json' => is_array($summary) ? $summary : [],
            'error_message' => (string) ($run['error_message'] ?? ''),
            'created_at' => (string) ($run['created_at'] ?? ''),
            'updated_at' => (string) ($run['updated_at'] ?? ''),
        ];
    }

    private function nextRunId(array $runs): int
    {
        $maxId = 0;
        foreach ($runs as $run) {
            $maxId = max($maxId, (int) ($run['id'] ?? 0));
        }

        return $maxId + 1;
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
}
