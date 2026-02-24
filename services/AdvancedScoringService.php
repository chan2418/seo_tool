<?php

class AdvancedScoringService
{
    private const WEIGHT_TECHNICAL = 0.40;
    private const WEIGHT_CONTENT = 0.30;
    private const WEIGHT_AUTHORITY = 0.20;
    private const WEIGHT_KEYWORD = 0.10;

    public function calculate(array $scores): array
    {
        $technical = $this->clamp((int) ($scores['technical'] ?? 0));
        $content = $this->clamp((int) ($scores['content'] ?? 0));
        $authority = $this->clamp((int) ($scores['authority'] ?? 0));
        $keyword = $this->clamp((int) ($scores['keyword_optimization'] ?? 0));

        $finalScore = (int) round(
            ($technical * self::WEIGHT_TECHNICAL)
            + ($content * self::WEIGHT_CONTENT)
            + ($authority * self::WEIGHT_AUTHORITY)
            + ($keyword * self::WEIGHT_KEYWORD)
        );

        return [
            'technical' => $technical,
            'content' => $content,
            'authority' => $authority,
            'keyword_optimization' => $keyword,
            'final_score' => $this->clamp($finalScore),
            'label' => $this->labelForScore($finalScore),
            'color' => $this->colorForScore($finalScore),
        ];
    }

    public function calculateFromCrawlerSummary(array $summary, int $pagesAnalyzed): array
    {
        $pages = max(1, $pagesAnalyzed);
        $issues = is_array($summary['issues'] ?? null) ? $summary['issues'] : [];

        $duplicateTitles = (int) ($issues['duplicate_titles'] ?? 0);
        $missingH1 = (int) ($issues['missing_h1'] ?? 0);
        $missingMeta = (int) ($issues['missing_meta_description'] ?? 0);
        $brokenLinks = (int) ($issues['broken_links'] ?? 0);
        $thinContent = (int) ($issues['thin_content'] ?? 0);

        $technicalPenalty = ($duplicateTitles * 4) + ($missingMeta * 3) + ($missingH1 * 4) + ($brokenLinks * 2);
        $contentPenalty = ($thinContent * 6) + ($missingMeta * 2) + ($missingH1 * 2);
        $authorityBase = max(30, 80 - (int) round(($brokenLinks / $pages) * 8));
        $keywordPenalty = ($missingMeta * 4) + ($missingH1 * 5) + ($duplicateTitles * 3);

        $technical = $this->clamp(100 - (int) round($technicalPenalty / $pages));
        $content = $this->clamp(100 - (int) round($contentPenalty / $pages));
        $authority = $this->clamp($authorityBase);
        $keyword = $this->clamp(100 - (int) round($keywordPenalty / $pages));

        return $this->calculate([
            'technical' => $technical,
            'content' => $content,
            'authority' => $authority,
            'keyword_optimization' => $keyword,
        ]);
    }

    public function labelForScore(int $score): string
    {
        $score = $this->clamp($score);
        if ($score >= 85) {
            return 'Excellent';
        }

        if ($score >= 70) {
            return 'Good';
        }

        if ($score >= 50) {
            return 'Needs Improvement';
        }

        return 'Critical';
    }

    public function colorForScore(int $score): string
    {
        $score = $this->clamp($score);

        if ($score >= 80) {
            return '#22C55E';
        }

        if ($score >= 50) {
            return '#F59E0B';
        }

        return '#EF4444';
    }

    private function clamp(int $score): int
    {
        return max(0, min(100, $score));
    }
}
