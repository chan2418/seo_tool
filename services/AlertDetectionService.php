<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AlertModel.php';
require_once __DIR__ . '/../models/AlertSettingModel.php';
require_once __DIR__ . '/../models/AuditModel.php';
require_once __DIR__ . '/../models/TrackedKeywordModel.php';
require_once __DIR__ . '/../models/RankingModel.php';
require_once __DIR__ . '/AlertService.php';
require_once __DIR__ . '/PlanEnforcementService.php';

class AlertDetectionService
{
    private AlertService $alertService;
    private AlertModel $alertModel;
    private AlertSettingModel $alertSettingModel;
    private AuditModel $auditModel;
    private TrackedKeywordModel $trackedKeywordModel;
    private RankingModel $rankingModel;
    private PlanEnforcementService $planEnforcementService;
    private ?PDO $conn;
    private string $stateFile;

    public function __construct(
        ?AlertService $alertService = null,
        ?AlertModel $alertModel = null,
        ?AlertSettingModel $alertSettingModel = null,
        ?AuditModel $auditModel = null,
        ?TrackedKeywordModel $trackedKeywordModel = null,
        ?RankingModel $rankingModel = null,
        ?PlanEnforcementService $planEnforcementService = null
    ) {
        $this->alertService = $alertService ?? new AlertService();
        $this->alertModel = $alertModel ?? new AlertModel();
        $this->alertSettingModel = $alertSettingModel ?? new AlertSettingModel();
        $this->auditModel = $auditModel ?? new AuditModel();
        $this->trackedKeywordModel = $trackedKeywordModel ?? new TrackedKeywordModel();
        $this->rankingModel = $rankingModel ?? new RankingModel();
        $this->planEnforcementService = $planEnforcementService ?? new PlanEnforcementService();

        $db = new Database();
        $connection = $db->connect();
        $this->conn = $connection instanceof PDO ? $connection : null;

        $storageDir = __DIR__ . '/../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }
        $this->stateFile = $storageDir . '/alert_detection_state.json';
        if (!file_exists($this->stateFile)) {
            file_put_contents($this->stateFile, json_encode([]));
        }
    }

    public function runForAllUsers(int $maxUsers = 500): array
    {
        $userIds = $this->alertModel->getUserIdsWithProjects();
        if (count($userIds) > $maxUsers) {
            $userIds = array_slice($userIds, 0, $maxUsers);
        }

        $summary = [
            'success' => true,
            'date' => date('Y-m-d'),
            'users_total' => count($userIds),
            'users_processed' => 0,
            'alerts_created' => 0,
            'details' => [],
        ];

        foreach ($userIds as $userId) {
            $profile = $this->alertModel->getUserEmailAndPlan((int) $userId);
            $plan = $this->planEnforcementService->getEffectivePlan((int) $userId, (string) ($profile['plan_type'] ?? 'free'));
            $result = $this->runForUser((int) $userId, $plan);
            if (!empty($result['success'])) {
                $summary['users_processed']++;
                $summary['alerts_created'] += (int) ($result['alerts_created'] ?? 0);
            }
            $summary['details'][] = $result;
        }

        $this->saveState($this->loadState());
        return $summary;
    }

    public function runForUser(int $userId, string $planType): array
    {
        $planType = $this->alertService->normalizePlanType($planType);
        $this->trackedKeywordModel->syncProjectsFromAudits($userId);
        $projects = $this->alertModel->getProjectsByUser($userId);
        $state = $this->loadState();

        $result = [
            'success' => true,
            'user_id' => $userId,
            'plan_type' => $planType,
            'projects_processed' => 0,
            'alerts_created' => 0,
            'by_type' => [],
        ];

        foreach ($projects as $project) {
            $projectId = (int) ($project['id'] ?? 0);
            $domain = strtolower(trim((string) ($project['domain'] ?? '')));
            if ($projectId <= 0 || $domain === '') {
                continue;
            }
            $result['projects_processed']++;

            $setting = $this->alertSettingModel->getSettings($userId, $projectId);
            $stateKey = $this->stateKey($userId, $projectId);
            $projectState = is_array($state[$stateKey] ?? null) ? $state[$stateKey] : [];

            $rankStats = $this->detectRankMovementAlerts($userId, $planType, $projectId, $domain, $setting);
            $result['alerts_created'] += (int) ($rankStats['created'] ?? 0);
            $this->addTypeCounts($result['by_type'], (array) ($rankStats['types'] ?? []));

            if (in_array($planType, ['pro', 'agency'], true)) {
                $seoStats = $this->detectSeoScoreAlerts($userId, $planType, $projectId, $domain, $setting, $projectState);
                $result['alerts_created'] += (int) ($seoStats['created'] ?? 0);
                $this->addTypeCounts($result['by_type'], (array) ($seoStats['types'] ?? []));
                $projectState = (array) ($seoStats['state'] ?? $projectState);
            }

            if ($planType === 'agency') {
                $backlinkStats = $this->detectBacklinkAlerts($userId, $planType, $projectId, $domain, $projectState);
                $result['alerts_created'] += (int) ($backlinkStats['created'] ?? 0);
                $this->addTypeCounts($result['by_type'], (array) ($backlinkStats['types'] ?? []));
                $projectState = (array) ($backlinkStats['state'] ?? $projectState);

                $crawlStats = $this->detectCrawlAlerts($userId, $planType, $projectId, $domain, $projectState);
                $result['alerts_created'] += (int) ($crawlStats['created'] ?? 0);
                $this->addTypeCounts($result['by_type'], (array) ($crawlStats['types'] ?? []));
                $projectState = (array) ($crawlStats['state'] ?? $projectState);
            }

            $projectState['updated_at'] = date('Y-m-d H:i:s');
            $state[$stateKey] = $projectState;
        }

        $this->saveState($state);
        $this->alertService->queueDailySummaryEmailIfEnabled($userId, $planType, 'daily');
        if ((int) date('N') === 7) {
            $this->alertService->queueDailySummaryEmailIfEnabled($userId, $planType, 'weekly');
        }

        return $result;
    }

    private function detectRankMovementAlerts(
        int $userId,
        string $planType,
        int $projectId,
        string $domain,
        array $setting
    ): array {
        $trackedKeywords = $this->trackedKeywordModel->getTrackedKeywords($userId, $projectId, 'active');
        if (empty($trackedKeywords)) {
            return ['created' => 0, 'types' => []];
        }

        $threshold = max(2, (int) ($setting['rank_drop_threshold'] ?? 10));
        $keywordIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $trackedKeywords);
        $map = $this->rankingModel->getLatestAndPreviousByKeywordIds($keywordIds);

        $created = 0;
        $types = [];
        foreach ($trackedKeywords as $keywordRow) {
            $keywordId = (int) ($keywordRow['id'] ?? 0);
            if ($keywordId <= 0) {
                continue;
            }

            $latest = $map[$keywordId]['latest'] ?? null;
            $previous = $map[$keywordId]['previous'] ?? null;
            if (!$latest || !$previous) {
                continue;
            }

            $keyword = (string) ($keywordRow['keyword'] ?? 'Keyword');
            $current = (int) ($latest['rank_position'] ?? 101);
            $prior = (int) ($previous['rank_position'] ?? 101);
            $reference = 'keyword:' . $keywordId;
            $date = (string) ($latest['checked_date'] ?? date('Y-m-d'));

            if ($prior <= 100 && $current <= 100 && ($current - $prior) >= $threshold) {
                $drop = $current - $prior;
                $severity = $drop >= ($threshold * 2) ? 'critical' : 'warning';
                $res = $this->alertService->createAlert(
                    $userId,
                    $planType,
                    $projectId,
                    'rank_drop',
                    $reference,
                    'Keyword "' . $keyword . '" dropped from ' . $prior . ' to ' . $current . ' (' . $drop . ' positions).',
                    $severity,
                    $date
                );
                if (!empty($res['created'])) {
                    $created++;
                    $types['rank_drop'] = (int) ($types['rank_drop'] ?? 0) + 1;
                }
            }

            if ($prior > 10 && $current <= 10) {
                $res = $this->alertService->createAlert(
                    $userId,
                    $planType,
                    $projectId,
                    'rank_top10',
                    $reference,
                    'Keyword "' . $keyword . '" entered Top 10 (' . $prior . ' to ' . $current . ').',
                    'info',
                    $date
                );
                if (!empty($res['created'])) {
                    $created++;
                    $types['rank_top10'] = (int) ($types['rank_top10'] ?? 0) + 1;
                }
            }

            if ($prior <= 50 && $current > 50) {
                $res = $this->alertService->createAlert(
                    $userId,
                    $planType,
                    $projectId,
                    'rank_out_top50',
                    $reference,
                    'Keyword "' . $keyword . '" fell out of Top 50 (' . $prior . ' to ' . ($current > 100 ? '100+' : $current) . ').',
                    'warning',
                    $date
                );
                if (!empty($res['created'])) {
                    $created++;
                    $types['rank_out_top50'] = (int) ($types['rank_out_top50'] ?? 0) + 1;
                }
            }
        }

        return ['created' => $created, 'types' => $types];
    }

    private function detectSeoScoreAlerts(
        int $userId,
        string $planType,
        int $projectId,
        string $domain,
        array $setting,
        array $state
    ): array {
        $created = 0;
        $types = [];
        $threshold = max(1, (int) ($setting['seo_score_drop_threshold'] ?? 5));

        $latestAudit = $this->getLatestAuditForDomain($userId, $domain);
        if ($latestAudit !== null) {
            $currentSeo = (int) ($latestAudit['seo_score'] ?? 0);
            $prevSeo = isset($state['seo_score']) ? (int) $state['seo_score'] : null;
            if ($prevSeo !== null && ($prevSeo - $currentSeo) >= $threshold) {
                $drop = $prevSeo - $currentSeo;
                $res = $this->alertService->createAlert(
                    $userId,
                    $planType,
                    $projectId,
                    'seo_drop',
                    'seo_score:' . $projectId,
                    'SEO score dropped by ' . $drop . ' points for ' . $domain . ' (' . $prevSeo . ' to ' . $currentSeo . ').',
                    $drop >= ($threshold * 2) ? 'critical' : 'warning',
                    (string) substr((string) ($latestAudit['created_at'] ?? date('Y-m-d')), 0, 10)
                );
                if (!empty($res['created'])) {
                    $created++;
                    $types['seo_drop'] = (int) ($types['seo_drop'] ?? 0) + 1;
                }
            }
            $state['seo_score'] = $currentSeo;
        }

        $crawlMetrics = $this->getLatestCrawlMetrics($userId, $domain);
        if ($crawlMetrics !== null) {
            $currentTechnical = (int) ($crawlMetrics['technical_score'] ?? 0);
            $prevTechnical = isset($state['technical_score']) ? (int) $state['technical_score'] : null;
            if ($prevTechnical !== null && ($prevTechnical - $currentTechnical) >= max(3, $threshold)) {
                $drop = $prevTechnical - $currentTechnical;
                $res = $this->alertService->createAlert(
                    $userId,
                    $planType,
                    $projectId,
                    'technical_score_drop',
                    'technical_score:' . $projectId,
                    'Technical score dropped by ' . $drop . ' points for ' . $domain . '.',
                    $drop >= 10 ? 'critical' : 'warning',
                    (string) substr((string) ($crawlMetrics['created_at'] ?? date('Y-m-d')), 0, 10)
                );
                if (!empty($res['created'])) {
                    $created++;
                    $types['technical_score_drop'] = (int) ($types['technical_score_drop'] ?? 0) + 1;
                }
            }

            $issues = is_array($crawlMetrics['issues'] ?? null) ? $crawlMetrics['issues'] : [];
            $criticalCount = (int) ($issues['broken_links'] ?? 0) + (int) ($issues['missing_h1'] ?? 0) + (int) ($issues['missing_meta_description'] ?? 0);
            if ($criticalCount > 0) {
                $res = $this->alertService->createAlert(
                    $userId,
                    $planType,
                    $projectId,
                    'crawl_critical',
                    'crawl_critical:' . $projectId,
                    'Crawler detected critical technical issues (' . $criticalCount . ') for ' . $domain . '.',
                    'critical',
                    (string) substr((string) ($crawlMetrics['created_at'] ?? date('Y-m-d')), 0, 10)
                );
                if (!empty($res['created'])) {
                    $created++;
                    $types['crawl_critical'] = (int) ($types['crawl_critical'] ?? 0) + 1;
                }
            }

            $state['technical_score'] = $currentTechnical;
        }

        return ['created' => $created, 'types' => $types, 'state' => $state];
    }

    private function detectBacklinkAlerts(
        int $userId,
        string $planType,
        int $projectId,
        string $domain,
        array $state
    ): array {
        $created = 0;
        $types = [];
        $metrics = $this->getLatestBacklinkMetrics($userId, $domain);
        if ($metrics === null) {
            return ['created' => 0, 'types' => [], 'state' => $state];
        }

        $currentBacklinks = (int) ($metrics['total_backlinks'] ?? 0);
        $currentRefDomains = (int) ($metrics['referring_domains'] ?? 0);
        $previousBacklinks = isset($state['backlinks_total']) ? (int) $state['backlinks_total'] : null;
        $previousRefDomains = isset($state['ref_domains']) ? (int) $state['ref_domains'] : null;
        $date = (string) substr((string) ($metrics['created_at'] ?? date('Y-m-d')), 0, 10);
        $reference = 'backlink:' . $projectId;

        if ($previousBacklinks !== null && $currentBacklinks > $previousBacklinks) {
            $gain = $currentBacklinks - $previousBacklinks;
            $res = $this->alertService->createAlert(
                $userId,
                $planType,
                $projectId,
                'backlink_new',
                $reference,
                $gain . ' new backlinks detected for ' . $domain . '.',
                'info',
                $date
            );
            if (!empty($res['created'])) {
                $created++;
                $types['backlink_new'] = (int) ($types['backlink_new'] ?? 0) + 1;
            }
        }

        if ($previousBacklinks !== null && $currentBacklinks < $previousBacklinks) {
            $lost = $previousBacklinks - $currentBacklinks;
            $res = $this->alertService->createAlert(
                $userId,
                $planType,
                $projectId,
                'backlink_lost',
                $reference,
                $lost . ' backlinks were lost for ' . $domain . '.',
                $lost >= 50 ? 'critical' : 'warning',
                $date
            );
            if (!empty($res['created'])) {
                $created++;
                $types['backlink_lost'] = (int) ($types['backlink_lost'] ?? 0) + 1;
            }
        }

        if ($previousRefDomains !== null && $currentRefDomains < $previousRefDomains) {
            $lostDomains = $previousRefDomains - $currentRefDomains;
            $res = $this->alertService->createAlert(
                $userId,
                $planType,
                $projectId,
                'ref_domains_drop',
                $reference,
                'Referring domains decreased by ' . $lostDomains . ' for ' . $domain . '.',
                'warning',
                $date
            );
            if (!empty($res['created'])) {
                $created++;
                $types['ref_domains_drop'] = (int) ($types['ref_domains_drop'] ?? 0) + 1;
            }
        }

        $state['backlinks_total'] = $currentBacklinks;
        $state['ref_domains'] = $currentRefDomains;
        return ['created' => $created, 'types' => $types, 'state' => $state];
    }

    private function detectCrawlAlerts(
        int $userId,
        string $planType,
        int $projectId,
        string $domain,
        array $state
    ): array {
        $created = 0;
        $types = [];
        $metrics = $this->getLatestCrawlMetrics($userId, $domain);
        if ($metrics === null) {
            return ['created' => 0, 'types' => [], 'state' => $state];
        }

        $issues = is_array($metrics['issues'] ?? null) ? $metrics['issues'] : [];
        $broken = (int) ($issues['broken_links'] ?? 0);
        $duplicateTitles = (int) ($issues['duplicate_titles'] ?? 0);
        $thinContent = (int) ($issues['thin_content'] ?? 0);
        $date = (string) substr((string) ($metrics['created_at'] ?? date('Y-m-d')), 0, 10);
        $reference = 'crawl:' . $projectId;

        $prevBroken = isset($state['crawl_broken_links']) ? (int) $state['crawl_broken_links'] : null;
        $prevDuplicate = isset($state['crawl_duplicate_titles']) ? (int) $state['crawl_duplicate_titles'] : null;
        $prevThin = isset($state['crawl_thin_content']) ? (int) $state['crawl_thin_content'] : null;

        if ($prevBroken !== null && $broken > $prevBroken) {
            $res = $this->alertService->createAlert(
                $userId,
                $planType,
                $projectId,
                'crawl_broken_links_up',
                $reference,
                'Broken links increased from ' . $prevBroken . ' to ' . $broken . ' for ' . $domain . '.',
                'warning',
                $date
            );
            if (!empty($res['created'])) {
                $created++;
                $types['crawl_broken_links_up'] = (int) ($types['crawl_broken_links_up'] ?? 0) + 1;
            }
        }

        if ($prevDuplicate !== null && $duplicateTitles > $prevDuplicate) {
            $res = $this->alertService->createAlert(
                $userId,
                $planType,
                $projectId,
                'crawl_duplicate_titles_up',
                $reference,
                'Duplicate titles increased from ' . $prevDuplicate . ' to ' . $duplicateTitles . ' for ' . $domain . '.',
                'warning',
                $date
            );
            if (!empty($res['created'])) {
                $created++;
                $types['crawl_duplicate_titles_up'] = (int) ($types['crawl_duplicate_titles_up'] ?? 0) + 1;
            }
        }

        if ($prevThin !== null && $thinContent > $prevThin) {
            $res = $this->alertService->createAlert(
                $userId,
                $planType,
                $projectId,
                'crawl_thin_content_up',
                $reference,
                'Thin-content pages increased from ' . $prevThin . ' to ' . $thinContent . ' for ' . $domain . '.',
                'warning',
                $date
            );
            if (!empty($res['created'])) {
                $created++;
                $types['crawl_thin_content_up'] = (int) ($types['crawl_thin_content_up'] ?? 0) + 1;
            }
        }

        $state['crawl_broken_links'] = $broken;
        $state['crawl_duplicate_titles'] = $duplicateTitles;
        $state['crawl_thin_content'] = $thinContent;
        return ['created' => $created, 'types' => $types, 'state' => $state];
    }

    private function getLatestAuditForDomain(int $userId, string $domain): ?array
    {
        $audits = $this->auditModel->getUserAudits($userId);
        foreach ($audits as $audit) {
            $host = strtolower((string) (parse_url((string) ($audit['url'] ?? ''), PHP_URL_HOST) ?? ''));
            if ($host === strtolower($domain)) {
                return $audit;
            }
        }
        return null;
    }

    private function getLatestBacklinkMetrics(int $userId, string $domain): ?array
    {
        if ($this->conn) {
            try {
                $stmt = $this->conn->prepare(
                    'SELECT id, total_backlinks, referring_domains, created_at
                     FROM backlink_snapshots
                     WHERE user_id = :user_id
                       AND domain = :domain
                     ORDER BY created_at DESC
                     LIMIT 1'
                );
                $stmt->execute([
                    ':user_id' => $userId,
                    ':domain' => strtolower($domain),
                ]);
                $row = $stmt->fetch();
                if ($row) {
                    return [
                        'id' => (int) ($row['id'] ?? 0),
                        'total_backlinks' => (int) ($row['total_backlinks'] ?? 0),
                        'referring_domains' => (int) ($row['referring_domains'] ?? 0),
                        'created_at' => (string) ($row['created_at'] ?? ''),
                    ];
                }
            } catch (Throwable $error) {
                error_log('AlertDetection backlink query failed: ' . $error->getMessage());
            }
        }

        $file = __DIR__ . '/../storage/backlink_snapshots.json';
        $rows = $this->readJsonFile($file);
        $entry = $rows[strtolower($domain)] ?? null;
        if (!is_array($entry) || (int) ($entry['user_id'] ?? 0) !== $userId) {
            return null;
        }
        $summary = is_array($entry['payload']['summary'] ?? null) ? $entry['payload']['summary'] : [];

        return [
            'id' => 0,
            'total_backlinks' => (int) ($summary['total_backlinks'] ?? 0),
            'referring_domains' => (int) ($summary['referring_domains'] ?? 0),
            'created_at' => (string) ($entry['created_at'] ?? date('Y-m-d H:i:s')),
        ];
    }

    private function getLatestCrawlMetrics(int $userId, string $domain): ?array
    {
        if ($this->conn) {
            try {
                $stmt = $this->conn->prepare(
                    'SELECT id, technical_score, summary_json, created_at
                     FROM crawl_runs
                     WHERE user_id = :user_id
                       AND domain = :domain
                       AND status = "completed"
                     ORDER BY created_at DESC
                     LIMIT 1'
                );
                $stmt->execute([
                    ':user_id' => $userId,
                    ':domain' => strtolower($domain),
                ]);
                $row = $stmt->fetch();
                if ($row) {
                    $summary = $row['summary_json'];
                    if (is_string($summary)) {
                        $summary = json_decode($summary, true);
                    }
                    $summary = is_array($summary) ? $summary : [];
                    $issues = is_array($summary['issues'] ?? null) ? $summary['issues'] : [];

                    return [
                        'id' => (int) ($row['id'] ?? 0),
                        'technical_score' => (int) ($row['technical_score'] ?? 0),
                        'issues' => $issues,
                        'created_at' => (string) ($row['created_at'] ?? ''),
                    ];
                }
            } catch (Throwable $error) {
                error_log('AlertDetection crawl query failed: ' . $error->getMessage());
            }
        }

        $file = __DIR__ . '/../storage/crawl_runs.json';
        $rows = $this->readJsonFile($file);
        $latest = null;
        foreach ($rows as $row) {
            if ((int) ($row['user_id'] ?? 0) !== $userId) {
                continue;
            }
            if (strtolower((string) ($row['domain'] ?? '')) !== strtolower($domain)) {
                continue;
            }
            if ((string) ($row['status'] ?? '') !== 'completed') {
                continue;
            }
            if ($latest === null || (string) ($row['created_at'] ?? '') > (string) ($latest['created_at'] ?? '')) {
                $latest = $row;
            }
        }
        if (!is_array($latest)) {
            return null;
        }

        $summary = is_array($latest['summary_json'] ?? null) ? $latest['summary_json'] : [];
        $issues = is_array($summary['issues'] ?? null) ? $summary['issues'] : [];

        return [
            'id' => (int) ($latest['id'] ?? 0),
            'technical_score' => (int) ($latest['technical_score'] ?? 0),
            'issues' => $issues,
            'created_at' => (string) ($latest['created_at'] ?? date('Y-m-d H:i:s')),
        ];
    }

    private function stateKey(int $userId, int $projectId): string
    {
        return 'u' . $userId . '_p' . $projectId;
    }

    private function loadState(): array
    {
        return $this->readJsonFile($this->stateFile);
    }

    private function saveState(array $state): void
    {
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function addTypeCounts(array &$target, array $source): void
    {
        foreach ($source as $type => $count) {
            $target[$type] = (int) ($target[$type] ?? 0) + (int) $count;
        }
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
}
