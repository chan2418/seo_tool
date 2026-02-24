<?php

require_once __DIR__ . '/PlanEnforcementService.php';
require_once __DIR__ . '/../utils/SecurityValidator.php';
require_once __DIR__ . '/../models/AuditModel.php';
require_once __DIR__ . '/CompetitorService.php';
require_once __DIR__ . '/BacklinkService.php';
require_once __DIR__ . '/AdvancedScoringService.php';
require_once __DIR__ . '/UsageMonitoringService.php';

class ReportService
{
    private CompetitorService $competitorService;
    private BacklinkService $backlinkService;
    private AdvancedScoringService $scoringService;
    private AuditModel $auditModel;
    private PlanEnforcementService $planEnforcementService;
    private UsageMonitoringService $usageMonitoringService;

    public function __construct(
        ?CompetitorService $competitorService = null,
        ?BacklinkService $backlinkService = null,
        ?AdvancedScoringService $scoringService = null,
        ?AuditModel $auditModel = null,
        ?PlanEnforcementService $planEnforcementService = null,
        ?UsageMonitoringService $usageMonitoringService = null
    ) {
        $this->competitorService = $competitorService ?? new CompetitorService();
        $this->backlinkService = $backlinkService ?? new BacklinkService();
        $this->scoringService = $scoringService ?? new AdvancedScoringService();
        $this->auditModel = $auditModel ?? new AuditModel();
        $this->planEnforcementService = $planEnforcementService ?? new PlanEnforcementService();
        $this->usageMonitoringService = $usageMonitoringService ?? new UsageMonitoringService();
    }

    public function generateWhiteLabelPdf(int $userId, string $planType, array $options): array
    {
        $planType = $this->planEnforcementService->getEffectivePlan($userId, $planType);
        $access = $this->planEnforcementService->assertFeatureAccess($userId, 'white_label_reports');
        if (empty($access['allowed'])) {
            return [
                'success' => false,
                'status' => 403,
                'error' => (string) ($access['message'] ?? 'Feature not available on your current plan.'),
            ];
        }

        $primaryDomain = SecurityValidator::sanitizeDomain((string) ($options['primary_domain'] ?? ''));
        if (!SecurityValidator::isValidDomain($primaryDomain)) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'A valid primary domain is required.',
            ];
        }

        $competitorDomain = SecurityValidator::sanitizeDomain((string) ($options['competitor_domain'] ?? ''));
        if ($competitorDomain !== '' && !SecurityValidator::isValidDomain($competitorDomain)) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Competitor domain is invalid.',
            ];
        }

        $reportTitle = $this->sanitizeReportTitle((string) ($options['report_title'] ?? 'SEO Performance Report'));
        $logoPath = $this->resolveLogoPath((string) ($options['logo_file'] ?? ''));

        $latestAudit = $this->findLatestAuditForDomain($userId, $primaryDomain);
        $auditScore = (int) ($latestAudit['seo_score'] ?? 0);

        $primaryCompetitor = $this->competitorService->analyze($primaryDomain, $userId, $planType);
        $primaryCompetitorData = (array) ($primaryCompetitor['data'] ?? []);

        $comparisonData = [];
        if ($competitorDomain !== '') {
            $comparisonResponse = $this->competitorService->analyze($competitorDomain, $userId, $planType);
            $comparisonData = (array) ($comparisonResponse['data'] ?? []);
        }

        $backlinkResponse = $this->backlinkService->overview($primaryDomain, $userId, $planType);
        $backlinkData = (array) ($backlinkResponse['data'] ?? []);
        $comparisonBackSummary = [];
        if ($competitorDomain !== '') {
            $comparisonBacklinkResponse = $this->backlinkService->overview($competitorDomain, $userId, $planType);
            $comparisonBackSummary = (array) (($comparisonBacklinkResponse['data']['summary'] ?? []));
        }

        $summaryScores = $this->scoringService->calculate([
            'technical' => $auditScore > 0 ? $auditScore : (int) (($primaryCompetitorData['summary']['pagespeed_score'] ?? 0) + 10),
            'content' => (int) ($primaryCompetitorData['summary']['domain_health_score'] ?? 55),
            'authority' => (int) ($primaryCompetitorData['summary']['domain_authority'] ?? 45),
            'keyword_optimization' => min(100, (int) round(((int) ($primaryCompetitorData['summary']['ranking_keywords'] ?? 0)) / 300)),
        ]);

        $filename = 'seo-report-' . $primaryDomain . '-' . date('Ymd-His') . '.pdf';
        $competitorSummary = (array) ($primaryCompetitorData['summary'] ?? []);
        $comparisonSummary = (array) ($comparisonData['summary'] ?? []);
        $backSummary = (array) ($backlinkData['summary'] ?? []);
        $topKeywords = array_slice((array) ($primaryCompetitorData['top_keywords'] ?? []), 0, 10);
        $topPages = array_slice((array) ($primaryCompetitorData['top_pages'] ?? []), 0, 5);
        $topAnchors = array_slice((array) ($backlinkData['top_anchor_texts'] ?? []), 0, 8);
        $topLinkingDomains = array_slice((array) ($backlinkData['top_linking_domains'] ?? []), 0, 10);
        $topBacklinks = array_slice((array) ($backlinkData['top_backlinks'] ?? []), 0, 6);
        $kpiCards = $this->buildKpiCards($summaryScores, $competitorSummary, $backSummary);
        $scoreRows = $this->buildScoreRows($summaryScores);
        $snapshotRows = $this->buildSnapshotRows($competitorSummary, $backSummary);
        $comparisonRows = $this->buildComparisonMetricRows($competitorSummary, $comparisonSummary, $backSummary, $comparisonBackSummary);
        $keywordRows = $this->buildKeywordRows($topKeywords);
        $pageRows = $this->buildPageRows($topPages);
        $anchorRows = $this->buildAnchorRows($topAnchors);
        $linkingDomainRows = $this->buildLinkingDomainRows($topLinkingDomains);
        $topBacklinkRows = $this->buildTopBacklinkRows($topBacklinks);
        $recommendations = $this->buildRecommendations($summaryScores, $competitorSummary, $backSummary, $comparisonSummary);

        if (!$this->loadTcpdf()) {
            $this->usageMonitoringService->logApiCall($userId, 'report_export');
            return [
                'success' => true,
                'status' => 200,
                'filename' => $filename,
                'content' => $this->buildStyledFallbackPdfDocument(
                    $reportTitle,
                    $primaryDomain,
                    $competitorDomain,
                    $summaryScores,
                    $kpiCards,
                    $scoreRows,
                    $snapshotRows,
                    $comparisonRows,
                    $keywordRows,
                    $pageRows,
                    $anchorRows,
                    $linkingDomainRows,
                    $topBacklinkRows,
                    $recommendations
                ),
                'fallback' => true,
            ];
        }

        $pdf = new TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('SEO Audit SaaS');
        $pdf->SetAuthor('SEO Audit SaaS');
        $pdf->SetTitle($reportTitle);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->AddPage();

        if ($logoPath !== null) {
            $pdf->Image($logoPath, 174, 12, 22, 0, '', '', '', false, 300, '', false, false, 0, false, false, false);
        }

        $pdf->writeHTML(
            $this->buildTcpdfStyledHtmlReport(
                $reportTitle,
                $primaryDomain,
                $competitorDomain,
                $summaryScores,
                $kpiCards,
                $scoreRows,
                $snapshotRows,
                $comparisonRows,
                $keywordRows,
                $pageRows,
                $anchorRows,
                $linkingDomainRows,
                $topBacklinkRows,
                $recommendations
            ),
            true,
            false,
            true,
            false,
            ''
        );

        $binary = $pdf->Output($filename, 'S');
        $this->usageMonitoringService->logApiCall($userId, 'report_export');

        return [
            'success' => true,
            'status' => 200,
            'filename' => $filename,
            'content' => $binary,
        ];
    }

    private function findLatestAuditForDomain(int $userId, string $domain): ?array
    {
        $audits = $this->auditModel->getUserAudits($userId);
        foreach ($audits as $audit) {
            $url = (string) ($audit['url'] ?? '');
            $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
            if ($host === strtolower($domain)) {
                return $audit;
            }
        }

        return $audits[0] ?? null;
    }

    private function sanitizeReportTitle(string $title): string
    {
        $title = trim($title);
        $title = preg_replace('/\s+/', ' ', $title);
        if ($title === '') {
            return 'SEO Performance Report';
        }

        return mb_substr($title, 0, 120);
    }

    private function resolveLogoPath(string $logoFile): ?string
    {
        $logoFile = trim($logoFile);
        if ($logoFile === '') {
            return null;
        }

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $logoFile)) {
            return null;
        }

        $logosDir = realpath(__DIR__ . '/../public/assets/logos');
        if ($logosDir === false) {
            return null;
        }

        $logoPath = realpath($logosDir . DIRECTORY_SEPARATOR . $logoFile);
        if ($logoPath === false) {
            return null;
        }

        if (!str_starts_with($logoPath, $logosDir)) {
            return null;
        }

        return $logoPath;
    }

    private function buildRecommendations(array $scores, array $competitorSummary, array $backSummary, array $comparisonSummary = []): array
    {
        $items = [];

        if ((int) ($scores['technical'] ?? 0) < 70) {
            $items[] = 'Fix technical blockers first: missing meta tags, heading structure, and broken internal links.';
        }

        if ((int) ($competitorSummary['pagespeed_score'] ?? 0) < 75) {
            $items[] = 'Improve Core Web Vitals and reduce page load times to raise technical score quickly.';
        }

        if ((int) ($competitorSummary['domain_authority'] ?? 0) < 50) {
            $items[] = 'Invest in quality backlinks and PR placements to improve authority.';
        }

        if ((int) ($backSummary['referring_domains'] ?? 0) < 100) {
            $items[] = 'Diversify referring domains to strengthen backlink profile resilience.';
        }

        if (!empty($comparisonSummary)) {
            if ((int) ($competitorSummary['organic_traffic'] ?? 0) < (int) ($comparisonSummary['organic_traffic'] ?? 0)) {
                $items[] = 'Close the traffic gap with competitor-focused content clusters and refreshed ranking pages.';
            }
            if ((int) ($competitorSummary['ranking_keywords'] ?? 0) < (int) ($comparisonSummary['ranking_keywords'] ?? 0)) {
                $items[] = 'Expand keyword coverage for mid-intent terms where competitors currently outrank your domain.';
            }
        }

        $items[] = 'Publish high-intent, topic-cluster content to improve keyword coverage and organic growth.';

        return array_values(array_unique($items));
    }

    private function buildKpiCards(array $summaryScores, array $competitorSummary, array $backSummary): array
    {
        return [
            [
                'label' => 'Final SEO Score',
                'value' => (int) ($summaryScores['final_score'] ?? 0) . '/100',
                'note' => (string) ($summaryScores['label'] ?? 'Performance'),
            ],
            [
                'label' => 'Organic Traffic',
                'value' => number_format((int) ($competitorSummary['organic_traffic'] ?? 0)),
                'note' => 'Estimated monthly',
            ],
            [
                'label' => 'Domain Authority',
                'value' => (string) (int) ($competitorSummary['domain_authority'] ?? 0),
                'note' => 'Competitive strength',
            ],
            [
                'label' => 'Referring Domains',
                'value' => number_format((int) ($backSummary['referring_domains'] ?? 0)),
                'note' => 'Backlink diversity',
            ],
        ];
    }

    private function buildScoreRows(array $summaryScores): array
    {
        return [
            [
                'Final SEO Score',
                (int) ($summaryScores['final_score'] ?? 0) . '/100',
                (string) ($summaryScores['label'] ?? 'Overall'),
            ],
            [
                'Technical Score',
                (string) (int) ($summaryScores['technical'] ?? 0),
                $this->scoreBandLabel((int) ($summaryScores['technical'] ?? 0)),
            ],
            [
                'Content Score',
                (string) (int) ($summaryScores['content'] ?? 0),
                $this->scoreBandLabel((int) ($summaryScores['content'] ?? 0)),
            ],
            [
                'Authority Score',
                (string) (int) ($summaryScores['authority'] ?? 0),
                $this->scoreBandLabel((int) ($summaryScores['authority'] ?? 0)),
            ],
            [
                'Keyword Optimization',
                (string) (int) ($summaryScores['keyword_optimization'] ?? 0),
                $this->scoreBandLabel((int) ($summaryScores['keyword_optimization'] ?? 0)),
            ],
        ];
    }

    private function buildSnapshotRows(array $competitorSummary, array $backSummary): array
    {
        return [
            ['Estimated Organic Traffic', number_format((int) ($competitorSummary['organic_traffic'] ?? 0))],
            ['Domain Authority', (string) (int) ($competitorSummary['domain_authority'] ?? 0)],
            ['Ranking Keywords', number_format((int) ($competitorSummary['ranking_keywords'] ?? 0))],
            ['Domain Health Score', (string) (int) ($competitorSummary['domain_health_score'] ?? 0)],
            ['PageSpeed Score', (string) (int) ($competitorSummary['pagespeed_score'] ?? 0)],
            ['Total Backlinks', number_format((int) ($backSummary['total_backlinks'] ?? 0))],
            ['Referring Domains', number_format((int) ($backSummary['referring_domains'] ?? 0))],
            ['Do-follow Ratio', number_format((float) ($backSummary['dofollow_pct'] ?? 0), 1) . '%'],
        ];
    }

    private function buildComparisonMetricRows(
        array $competitorSummary,
        array $comparisonSummary,
        array $backSummary,
        array $comparisonBackSummary
    ): array {
        if (empty($comparisonSummary)) {
            return [];
        }

        $rows = [
            $this->buildComparisonRow(
                'Organic Traffic',
                (float) ($competitorSummary['organic_traffic'] ?? 0),
                (float) ($comparisonSummary['organic_traffic'] ?? 0)
            ),
            $this->buildComparisonRow(
                'Domain Authority',
                (float) ($competitorSummary['domain_authority'] ?? 0),
                (float) ($comparisonSummary['domain_authority'] ?? 0)
            ),
            $this->buildComparisonRow(
                'Ranking Keywords',
                (float) ($competitorSummary['ranking_keywords'] ?? 0),
                (float) ($comparisonSummary['ranking_keywords'] ?? 0)
            ),
            $this->buildComparisonRow(
                'Domain Health Score',
                (float) ($competitorSummary['domain_health_score'] ?? 0),
                (float) ($comparisonSummary['domain_health_score'] ?? 0)
            ),
            $this->buildComparisonRow(
                'PageSpeed Score',
                (float) ($competitorSummary['pagespeed_score'] ?? 0),
                (float) ($comparisonSummary['pagespeed_score'] ?? 0)
            ),
        ];

        if (!empty($comparisonBackSummary)) {
            $rows[] = $this->buildComparisonRow(
                'Total Backlinks',
                (float) ($backSummary['total_backlinks'] ?? 0),
                (float) ($comparisonBackSummary['total_backlinks'] ?? 0)
            );
            $rows[] = $this->buildComparisonRow(
                'Referring Domains',
                (float) ($backSummary['referring_domains'] ?? 0),
                (float) ($comparisonBackSummary['referring_domains'] ?? 0)
            );
        }

        return $rows;
    }

    private function buildComparisonRow(string $metric, float $primary, float $competitor): array
    {
        $delta = $primary - $competitor;
        $leader = 'Tie';
        if ($delta > 0) {
            $leader = 'Primary';
        } elseif ($delta < 0) {
            $leader = 'Competitor';
        }

        return [
            $metric,
            number_format((int) round($primary)),
            number_format((int) round($competitor)),
            $this->formatSignedDelta($delta),
            $leader,
        ];
    }

    private function buildKeywordRows(array $topKeywords): array
    {
        $rows = [];
        foreach ($topKeywords as $row) {
            $position = (int) ($row['position'] ?? 0);
            if ($position > 0 && $position <= 3) {
                $action = 'Defend rank';
            } elseif ($position > 0 && $position <= 10) {
                $action = 'Push to top 3';
            } else {
                $action = 'Growth opportunity';
            }

            $rows[] = [
                (string) ($row['keyword'] ?? '-'),
                '#' . max(0, $position),
                number_format((int) ($row['volume'] ?? 0)),
                $action,
            ];
        }

        return $rows;
    }

    private function buildPageRows(array $topPages): array
    {
        $rows = [];
        foreach ($topPages as $row) {
            $rows[] = [
                $this->compactUrl((string) ($row['url'] ?? '-'), 80),
                number_format((int) ($row['estimated_traffic'] ?? 0)),
                number_format((int) ($row['keywords'] ?? 0)),
            ];
        }

        return $rows;
    }

    private function buildAnchorRows(array $topAnchors): array
    {
        $rows = [];
        foreach ($topAnchors as $row) {
            $rows[] = [
                (string) ($row['text'] ?? '-'),
                number_format((int) ($row['count'] ?? 0)),
            ];
        }

        return $rows;
    }

    private function buildLinkingDomainRows(array $topLinkingDomains): array
    {
        $rows = [];
        foreach ($topLinkingDomains as $row) {
            $rows[] = [
                (string) ($row['domain'] ?? '-'),
                number_format((int) ($row['backlinks'] ?? 0)),
                (string) (int) ($row['authority'] ?? 0),
            ];
        }

        return $rows;
    }

    private function buildTopBacklinkRows(array $topBacklinks): array
    {
        $rows = [];
        foreach ($topBacklinks as $row) {
            $rows[] = [
                $this->compactUrl((string) ($row['source_url'] ?? '-'), 55),
                $this->compactUrl((string) ($row['target_url'] ?? '-'), 55),
                strtoupper((string) ($row['link_type'] ?? 'dofollow')),
            ];
        }

        return $rows;
    }

    private function scoreBandLabel(int $score): string
    {
        if ($score >= 85) {
            return 'Strong';
        }

        if ($score >= 70) {
            return 'Stable';
        }

        if ($score >= 50) {
            return 'Needs work';
        }

        return 'Critical';
    }

    private function formatSignedDelta(float $delta): string
    {
        $rounded = (int) round($delta);
        if ($rounded > 0) {
            return '+' . number_format($rounded);
        }

        if ($rounded < 0) {
            return number_format($rounded);
        }

        return '0';
    }

    private function compactUrl(string $url, int $maxChars = 70): string
    {
        $url = trim($url);
        if ($url === '') {
            return '-';
        }

        $parsed = parse_url($url);
        if (!is_array($parsed) || !isset($parsed['host'])) {
            return $this->truncatePdfCell($url, $maxChars);
        }

        $display = (string) $parsed['host'];
        $display .= (string) ($parsed['path'] ?? '/');
        if (!empty($parsed['query'])) {
            $display .= '?' . (string) $parsed['query'];
        }

        return $this->truncatePdfCell($display, $maxChars);
    }

    private function buildTcpdfStyledHtmlReport(
        string $reportTitle,
        string $primaryDomain,
        string $competitorDomain,
        array $summaryScores,
        array $kpiCards,
        array $scoreRows,
        array $snapshotRows,
        array $comparisonRows,
        array $keywordRows,
        array $pageRows,
        array $anchorRows,
        array $linkingDomainRows,
        array $topBacklinkRows,
        array $recommendations
    ): string {
        $score = (int) ($summaryScores['final_score'] ?? 0);
        $scoreLabel = (string) ($summaryScores['label'] ?? 'Performance');
        $generated = date('M d, Y H:i');
        $domainLine = 'Primary Domain: ' . $primaryDomain;
        if ($competitorDomain !== '') {
            $domainLine .= ' | Competitor: ' . $competitorDomain;
        }

        $html = '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr>'
            . '<td width="70%" bgcolor="#4F46E5" style="border:1px solid #4F46E5;padding:14px;">'
            . '<span style="font-size:16px;font-weight:bold;color:#FFFFFF;">' . htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8') . '</span><br/>'
            . '<span style="font-size:9px;color:#E0E7FF;">' . htmlspecialchars($domainLine, ENT_QUOTES, 'UTF-8') . '</span><br/>'
            . '<span style="font-size:9px;color:#E0E7FF;">Generated: ' . htmlspecialchars($generated, ENT_QUOTES, 'UTF-8') . '</span>'
            . '</td>'
            . '<td width="30%" bgcolor="#4338CA" style="border:1px solid #4F46E5;padding:12px;text-align:center;">'
            . '<span style="font-size:8px;color:#C7D2FE;">FINAL SEO SCORE</span><br/>'
            . '<span style="font-size:22px;font-weight:bold;color:#FFFFFF;">' . $score . '/100</span><br/>'
            . '<span style="font-size:9px;color:#E0E7FF;">' . htmlspecialchars($scoreLabel, ENT_QUOTES, 'UTF-8') . '</span>'
            . '</td>'
            . '</tr>'
            . '</table>';

        $html .= $this->buildTcpdfCardGrid($kpiCards);
        $html .= $this->buildTcpdfTableSection('Score Breakdown', ['Metric', 'Score', 'Status'], $scoreRows, [46, 18, 36]);
        $html .= $this->buildTcpdfTableSection('Competitor Snapshot', ['Metric', 'Value'], $snapshotRows, [62, 38]);

        if (!empty($comparisonRows)) {
            $html .= $this->buildTcpdfTableSection(
                'Primary vs Competitor Comparison',
                ['Metric', 'Primary', 'Competitor', 'Delta', 'Leader'],
                $comparisonRows,
                [30, 18, 18, 16, 18]
            );
        }

        $html .= $this->buildTcpdfTableSection('Top Organic Keywords', ['Keyword', 'Position', 'Volume', 'Action'], $keywordRows, [48, 14, 18, 20]);
        $html .= $this->buildTcpdfTableSection('Top Pages', ['Page URL', 'Traffic', 'Keywords'], $pageRows, [56, 22, 22]);
        $html .= $this->buildTcpdfTableSection('Top Anchor Texts', ['Anchor Text', 'Mentions'], $anchorRows, [72, 28]);
        $html .= $this->buildTcpdfTableSection('Top Linking Domains', ['Domain', 'Backlinks', 'Authority'], $linkingDomainRows, [56, 24, 20]);
        $html .= $this->buildTcpdfTableSection('Sample Backlink Sources', ['Source URL', 'Target URL', 'Type'], $topBacklinkRows, [40, 40, 20]);

        $html .= '<h3 style="font-size:12px;font-weight:bold;color:#334155;padding-top:4px;">Action Plan</h3>'
            . '<table width="100%" cellspacing="0" cellpadding="7" border="1" style="border-color:#E2E8F0;">';

        if (empty($recommendations)) {
            $recommendations = ['No immediate actions. Continue monitoring weekly trends.'];
        }

        foreach ($recommendations as $index => $item) {
            $rowColor = $index % 2 === 0 ? '#FFFFFF' : '#F8FAFC';
            $html .= '<tr bgcolor="' . $rowColor . '"><td style="font-size:9px;color:#1E293B;">'
                . htmlspecialchars((string) ($index + 1) . '. ' . $item, ENT_QUOTES, 'UTF-8')
                . '</td></tr>';
        }

        $html .= '</table>';

        return $html;
    }

    private function buildTcpdfCardGrid(array $cards): string
    {
        if (empty($cards)) {
            return '';
        }

        $html = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;">';
        for ($i = 0; $i < count($cards); $i += 2) {
            $left = $cards[$i] ?? ['label' => '-', 'value' => '-', 'note' => ''];
            $right = $cards[$i + 1] ?? ['label' => '', 'value' => '', 'note' => ''];

            $html .= '<tr>'
                . '<td width="49%" bgcolor="#F8FAFC" style="border:1px solid #E2E8F0;padding:9px;">'
                . '<span style="font-size:8px;color:#64748B;">' . htmlspecialchars((string) ($left['label'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</span><br/>'
                . '<span style="font-size:15px;font-weight:bold;color:#1E293B;">' . htmlspecialchars((string) ($left['value'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</span><br/>'
                . '<span style="font-size:8px;color:#64748B;">' . htmlspecialchars((string) ($left['note'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>'
                . '</td>'
                . '<td width="2%"></td>'
                . '<td width="49%" bgcolor="#F8FAFC" style="border:1px solid #E2E8F0;padding:9px;">'
                . '<span style="font-size:8px;color:#64748B;">' . htmlspecialchars((string) ($right['label'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</span><br/>'
                . '<span style="font-size:15px;font-weight:bold;color:#1E293B;">' . htmlspecialchars((string) ($right['value'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</span><br/>'
                . '<span style="font-size:8px;color:#64748B;">' . htmlspecialchars((string) ($right['note'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>'
                . '</td>'
                . '</tr><tr><td colspan="3" height="6"></td></tr>';
        }
        $html .= '</table>';

        return $html;
    }

    private function buildTcpdfTableSection(string $title, array $headers, array $rows, array $widths = []): string
    {
        if (empty($headers)) {
            return '';
        }

        if (empty($rows)) {
            $rows = [array_fill(0, count($headers), 'No data available')];
        }

        if (empty($widths) || count($widths) !== count($headers)) {
            $equal = (int) floor(100 / count($headers));
            $widths = array_fill(0, count($headers), $equal);
            $widths[count($widths) - 1] = 100 - ($equal * (count($widths) - 1));
        }

        $html = '<h3 style="font-size:12px;font-weight:bold;color:#334155;padding-top:5px;">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</h3>'
            . '<table width="100%" cellspacing="0" cellpadding="6" border="1" style="border-color:#E2E8F0;">'
            . '<tr bgcolor="#4F46E5">';

        foreach ($headers as $index => $header) {
            $html .= '<td width="' . (int) ($widths[$index] ?? 0) . '%" style="font-size:9px;font-weight:bold;color:#FFFFFF;">'
                . htmlspecialchars((string) $header, ENT_QUOTES, 'UTF-8')
                . '</td>';
        }
        $html .= '</tr>';

        foreach ($rows as $rowIndex => $row) {
            $rowColor = $rowIndex % 2 === 0 ? '#FFFFFF' : '#F8FAFC';
            $html .= '<tr bgcolor="' . $rowColor . '">';
            foreach ($headers as $colIndex => $unused) {
                $value = (string) ($row[$colIndex] ?? '-');
                $html .= '<td width="' . (int) ($widths[$colIndex] ?? 0) . '%" style="font-size:8.8px;color:#1E293B;">'
                    . htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
                    . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</table>';

        return $html;
    }

    private function buildStyledFallbackPdfDocument(
        string $reportTitle,
        string $primaryDomain,
        string $competitorDomain,
        array $summaryScores,
        array $kpiCards,
        array $scoreRows,
        array $snapshotRows,
        array $comparisonRows,
        array $keywordRows,
        array $pageRows,
        array $anchorRows,
        array $linkingDomainRows,
        array $topBacklinkRows,
        array $recommendations
    ): string {
        $pageWidth = 595.0;
        $margin = 36.0;
        $contentWidth = $pageWidth - ($margin * 2);
        $bottomBoundary = 54.0;

        $pages = [[]];
        $pageIndex = 0;
        $cursorY = 804.0;

        $push = function (string $op) use (&$pages, &$pageIndex): void {
            $pages[$pageIndex][] = $op;
        };

        $newPage = function () use (&$pages, &$pageIndex, &$cursorY): void {
            $pages[] = [];
            $pageIndex = count($pages) - 1;
            $cursorY = 804.0;
        };

        $ensureSpace = function (float $height) use (&$cursorY, $newPage, $bottomBoundary): void {
            if (($cursorY - $height) < $bottomBoundary) {
                $newPage();
            }
        };

        $drawRect = function (
            float $x,
            float $yTop,
            float $width,
            float $height,
            array $fill,
            array $stroke = [226, 232, 240],
            float $lineWidth = 0.8
        ) use ($push): void {
            $y = $yTop - $height;
            $push(sprintf(
                "q %.3F %.3F %.3F rg %.3F %.3F %.3F RG %.2F w %.2F %.2F %.2F %.2F re B Q",
                $fill[0] / 255,
                $fill[1] / 255,
                $fill[2] / 255,
                $stroke[0] / 255,
                $stroke[1] / 255,
                $stroke[2] / 255,
                $lineWidth,
                $x,
                $y,
                $width,
                $height
            ));
        };

        $drawText = function (
            float $x,
            float $y,
            string $text,
            float $size = 10,
            string $font = 'F1',
            array $color = [30, 41, 59]
        ) use ($push): void {
            $line = $this->sanitizePdfLine($text);
            if ($line === '') {
                return;
            }

            $push(sprintf(
                "BT /%s %.2F Tf %.3F %.3F %.3F rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET",
                $font,
                $size,
                $color[0] / 255,
                $color[1] / 255,
                $color[2] / 255,
                $x,
                $y,
                $this->escapePdfText($line)
            ));
        };

        $wrapText = function (string $text, float $cellWidth, int $maxLines = 5): array {
            $clean = $this->sanitizePdfLine($text);
            if ($clean === '') {
                return ['-'];
            }

            $maxChars = max(6, (int) floor(($cellWidth - 10) / 4.6));
            $words = preg_split('/\s+/', $clean) ?: [];
            $lines = [];
            $line = '';

            foreach ($words as $word) {
                if (strlen($word) > $maxChars) {
                    if ($line !== '') {
                        $lines[] = $line;
                        $line = '';
                    }

                    while (strlen($word) > $maxChars) {
                        $lines[] = substr($word, 0, $maxChars - 1) . '-';
                        $word = substr($word, $maxChars - 1);
                        if (count($lines) >= $maxLines) {
                            return $lines;
                        }
                    }

                    if ($word !== '') {
                        $line = $word;
                    }
                    continue;
                }

                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if (strlen($candidate) <= $maxChars) {
                    $line = $candidate;
                    continue;
                }

                if ($line !== '') {
                    $lines[] = $line;
                    if (count($lines) >= $maxLines) {
                        return $lines;
                    }
                }
                $line = $word;
            }

            if ($line !== '' && count($lines) < $maxLines) {
                $lines[] = $line;
            }

            return empty($lines) ? ['-'] : $lines;
        };

        $drawSectionTitle = function (string $title) use (&$cursorY, $ensureSpace, $drawText, $margin): void {
            $ensureSpace(24);
            $drawText($margin, $cursorY, strtoupper($title), 11, 'F2', [71, 85, 105]);
            $cursorY -= 18;
        };

        $drawMetricCards = function (array $cards) use (
            &$cursorY,
            $ensureSpace,
            $drawRect,
            $drawText,
            $margin,
            $contentWidth
        ): void {
            if (empty($cards)) {
                return;
            }

            $cardWidth = ($contentWidth - 12) / 2;
            $cardHeight = 54.0;

            for ($i = 0; $i < count($cards); $i += 2) {
                $ensureSpace($cardHeight + 10);
                $left = $cards[$i] ?? ['label' => '-', 'value' => '-', 'note' => ''];
                $right = $cards[$i + 1] ?? ['label' => '-', 'value' => '-', 'note' => ''];

                $drawRect($margin, $cursorY, $cardWidth, $cardHeight, [248, 250, 252], [226, 232, 240], 0.7);
                $drawText($margin + 8, $cursorY - 13, (string) ($left['label'] ?? '-'), 8.5, 'F1', [100, 116, 139]);
                $drawText($margin + 8, $cursorY - 30, (string) ($left['value'] ?? '-'), 14, 'F2', [30, 41, 59]);
                $drawText($margin + 8, $cursorY - 45, (string) ($left['note'] ?? ''), 8.3, 'F1', [100, 116, 139]);

                $rightX = $margin + $cardWidth + 12;
                $drawRect($rightX, $cursorY, $cardWidth, $cardHeight, [248, 250, 252], [226, 232, 240], 0.7);
                $drawText($rightX + 8, $cursorY - 13, (string) ($right['label'] ?? '-'), 8.5, 'F1', [100, 116, 139]);
                $drawText($rightX + 8, $cursorY - 30, (string) ($right['value'] ?? '-'), 14, 'F2', [30, 41, 59]);
                $drawText($rightX + 8, $cursorY - 45, (string) ($right['note'] ?? ''), 8.3, 'F1', [100, 116, 139]);

                $cursorY -= $cardHeight + 10;
            }
            $cursorY -= 2;
        };

        $drawTable = function (array $headers, array $widths, array $rows) use (
            &$cursorY,
            $margin,
            $drawRect,
            $drawText,
            $newPage,
            $bottomBoundary,
            $wrapText
        ): void {
            if (empty($headers)) {
                return;
            }

            if (empty($rows)) {
                $rows = [array_fill(0, count($headers), 'No data available')];
            }

            $tableWidth = array_sum($widths);
            if ($tableWidth <= 0) {
                return;
            }

            $headerHeight = 24.0;
            $lineHeight = 10.0;

            $drawHeader = function () use (&$cursorY, $headers, $widths, $margin, $drawRect, $drawText): void {
                $drawRect($margin, $cursorY, array_sum($widths), 24, [79, 70, 229], [79, 70, 229], 0.8);
                $x = $margin;
                foreach ($headers as $colIndex => $header) {
                    $cellWidth = (float) ($widths[$colIndex] ?? 100.0);
                    $drawText($x + 5, $cursorY - 15, (string) $header, 8.8, 'F2', [255, 255, 255]);
                    $x += $cellWidth;
                }
            };

            if (($cursorY - ($headerHeight + 26)) < $bottomBoundary) {
                $newPage();
            }
            $drawHeader();
            $cursorY -= $headerHeight;

            foreach ($rows as $rowIndex => $row) {
                $lineMap = [];
                $maxLines = 1;
                foreach ($headers as $colIndex => $unused) {
                    $cellWidth = (float) ($widths[$colIndex] ?? 100.0);
                    $lines = $wrapText((string) ($row[$colIndex] ?? '-'), $cellWidth, 5);
                    $lineMap[$colIndex] = $lines;
                    $maxLines = max($maxLines, count($lines));
                }

                $rowHeight = max(22.0, 8 + ($maxLines * $lineHeight));
                if (($cursorY - $rowHeight) < $bottomBoundary) {
                    $newPage();
                    $drawHeader();
                    $cursorY -= $headerHeight;
                }

                $x = $margin;
                foreach ($headers as $colIndex => $unused) {
                    $cellWidth = (float) ($widths[$colIndex] ?? 100.0);
                    $fill = $rowIndex % 2 === 0 ? [255, 255, 255] : [248, 250, 252];
                    $drawRect($x, $cursorY, $cellWidth, $rowHeight, $fill, [226, 232, 240], 0.6);

                    $lineY = $cursorY - 13;
                    foreach ($lineMap[$colIndex] as $line) {
                        $drawText($x + 5, $lineY, $line, 8.6, 'F1', [30, 41, 59]);
                        $lineY -= $lineHeight;
                        if ($lineY < ($cursorY - $rowHeight + 5)) {
                            break;
                        }
                    }
                    $x += $cellWidth;
                }

                $cursorY -= $rowHeight;
            }

            $cursorY -= 12;
        };

        $drawActionPlan = function (array $items) use (
            &$cursorY,
            $margin,
            $contentWidth,
            $drawRect,
            $drawText,
            $ensureSpace,
            $wrapText
        ): void {
            if (empty($items)) {
                $items = ['No immediate actions. Continue monitoring weekly trend changes.'];
            }

            foreach ($items as $index => $item) {
                $lines = $wrapText((string) ($index + 1) . '. ' . $item, $contentWidth - 16, 6);
                $height = max(24.0, 8 + count($lines) * 10.0);
                $ensureSpace($height + 6);
                $drawRect($margin, $cursorY, $contentWidth, $height, [248, 250, 252], [226, 232, 240], 0.7);
                $lineY = $cursorY - 13;
                foreach ($lines as $line) {
                    $drawText($margin + 8, $lineY, $line, 8.8, 'F1', [30, 41, 59]);
                    $lineY -= 10.0;
                }
                $cursorY -= $height + 6;
            }
        };

        $ensureSpace(112);
        $drawRect($margin, $cursorY, $contentWidth, 94, [79, 70, 229], [79, 70, 229], 0.8);
        $drawText($margin + 12, $cursorY - 28, $reportTitle, 16, 'F2', [255, 255, 255]);
        $drawText($margin + 12, $cursorY - 46, 'Primary Domain: ' . $primaryDomain, 10, 'F1', [224, 231, 255]);
        if ($competitorDomain !== '') {
            $drawText($margin + 12, $cursorY - 60, 'Competitor Domain: ' . $competitorDomain, 9, 'F1', [224, 231, 255]);
        }
        $drawText($margin + 12, $cursorY - 74, 'Generated: ' . date('M d, Y H:i'), 9, 'F1', [224, 231, 255]);

        $scoreBadgeWidth = 122.0;
        $score = (int) ($summaryScores['final_score'] ?? 0);
        $scoreLabel = (string) ($summaryScores['label'] ?? 'Performance');
        $scoreX = $margin + $contentWidth - $scoreBadgeWidth - 10;
        $drawRect($scoreX, $cursorY - 14, $scoreBadgeWidth, 56, [67, 56, 202], [129, 140, 248], 0.8);
        $drawText($scoreX + 10, $cursorY - 30, 'FINAL SEO SCORE', 7.8, 'F2', [199, 210, 254]);
        $drawText($scoreX + 10, $cursorY - 46, $score . '/100', 16, 'F2', [255, 255, 255]);
        $drawText($scoreX + 10, $cursorY - 60, $scoreLabel, 8.5, 'F1', [224, 231, 255]);
        $cursorY -= 106;

        $drawMetricCards($kpiCards);
        $drawSectionTitle('Score Breakdown');
        $drawTable(['Metric', 'Score', 'Status'], [220, 150, 153], $scoreRows);

        $drawSectionTitle('Competitor Snapshot');
        $drawTable(['Metric', 'Value'], [300, 223], $snapshotRows);

        if (!empty($comparisonRows)) {
            $drawSectionTitle('Primary vs Competitor Comparison');
            $drawTable(['Metric', 'Primary', 'Competitor', 'Delta', 'Leader'], [155, 96, 96, 88, 88], $comparisonRows);
        }

        $drawSectionTitle('Top Organic Keywords');
        $drawTable(['Keyword', 'Position', 'Volume', 'Action'], [250, 63, 110, 100], $keywordRows);

        $drawSectionTitle('Top Pages');
        $drawTable(['Page URL', 'Traffic', 'Keywords'], [285, 112, 126], $pageRows);

        $drawSectionTitle('Top Anchor Texts');
        $drawTable(['Anchor Text', 'Mentions'], [300, 223], $anchorRows);

        $drawSectionTitle('Top Linking Domains');
        $drawTable(['Domain', 'Backlinks', 'Authority'], [265, 120, 138], $linkingDomainRows);

        $drawSectionTitle('Sample Backlink Sources');
        $drawTable(['Source URL', 'Target URL', 'Type'], [210, 223, 90], $topBacklinkRows);

        $drawSectionTitle('Action Plan');
        $drawActionPlan($recommendations);

        $objects = [];
        $objects[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj";
        $objects[3] = "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj";
        $objects[4] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj";

        $pageIds = [];
        foreach ($pages as $idx => $ops) {
            $pageObjectId = 5 + ($idx * 2);
            $contentObjectId = $pageObjectId + 1;
            $pageIds[] = $pageObjectId;

            $content = implode("\n", $ops);
            $objects[$pageObjectId] = sprintf(
                "%d 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>\nendobj",
                $pageObjectId,
                $contentObjectId
            );
            $objects[$contentObjectId] = sprintf(
                "%d 0 obj\n<< /Length %d >>\nstream\n%s\nendstream\nendobj",
                $contentObjectId,
                strlen($content),
                $content
            );
        }

        $objects[2] = "2 0 obj\n<< /Type /Pages /Kids [" . implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageIds)) . '] /Count ' . count($pageIds) . " >>\nendobj";

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $object . "\n";
        }

        $maxObjectId = max(array_keys($objects));
        $xrefStart = strlen($pdf);
        $pdf .= "xref\n0 " . ($maxObjectId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= $maxObjectId; $i++) {
            if (!isset($offsets[$i])) {
                $pdf .= "0000000000 00000 f \n";
                continue;
            }
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size " . ($maxObjectId + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefStart . "\n%%EOF";

        return $pdf;
    }

    private function truncatePdfCell(string $text, int $maxChars): string
    {
        $clean = $this->sanitizePdfLine($text);
        if ($maxChars < 4) {
            $maxChars = 4;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($clean) > $maxChars) {
                return rtrim(mb_substr($clean, 0, $maxChars - 1)) . '...';
            }
            return $clean;
        }

        if (strlen($clean) > $maxChars) {
            return rtrim(substr($clean, 0, $maxChars - 1)) . '...';
        }

        return $clean;
    }

    private function sanitizePdfLine(string $line): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $line));
        if ($line === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $line);
            if (is_string($converted) && $converted !== '') {
                $line = $converted;
            }
        }

        return trim((string) preg_replace('/[^\x20-\x7E]/', '', $line));
    }

    private function escapePdfText(string $text): string
    {
        return str_replace(
            ['\\', '(', ')'],
            ['\\\\', '\\(', '\\)'],
            $text
        );
    }

    private function loadTcpdf(): bool
    {
        if (class_exists('TCPDF')) {
            return true;
        }

        $candidates = [
            __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php',
            __DIR__ . '/../vendor/tcpdf/tcpdf.php',
            __DIR__ . '/../lib/tcpdf/tcpdf.php',
        ];

        foreach ($candidates as $file) {
            if (file_exists($file)) {
                require_once $file;
                if (class_exists('TCPDF')) {
                    return true;
                }
            }
        }

        return false;
    }
}
