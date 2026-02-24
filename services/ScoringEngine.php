<?php

class ScoringEngine {
    // Audit Weights
    const WEIGHT_TITLE = 15;
    const WEIGHT_DESC = 15;
    const WEIGHT_H1 = 10;
    const WEIGHT_IMG_ALT = 20;
    const WEIGHT_HTTPS = 10;
    const WEIGHT_MOBILE = 10;
    const WEIGHT_PAGESPEED = 20;

    public function calculateScore($results) {
        $score = 0;

        // Meta Title
        if (isset($results['seo']['meta_title']['status']) && $results['seo']['meta_title']['status'] === 'valid') {
            $score += self::WEIGHT_TITLE;
        } elseif (isset($results['seo']['meta_title']['status']) && $results['seo']['meta_title']['status'] === 'warning') {
            $score += (self::WEIGHT_TITLE / 2); // Partial credit
        }

        // Meta Description
        if (isset($results['seo']['meta_description']['status']) && $results['seo']['meta_description']['status'] === 'valid') {
            $score += self::WEIGHT_DESC;
        } elseif (isset($results['seo']['meta_description']['status']) && $results['seo']['meta_description']['status'] === 'warning') {
            $score += (self::WEIGHT_DESC / 2);
        }

        // H1 Tag
        if (isset($results['seo']['h1_tags']['status']) && $results['seo']['h1_tags']['status'] === 'valid') {
            $score += self::WEIGHT_H1;
        }

        // Image ALTs
        if (isset($results['seo']['images']['score'])) {
            $altScore = $results['seo']['images']['score']; // Percentage 0-100
            $score += ($altScore / 100) * self::WEIGHT_IMG_ALT;
        }

        // HTTPS
        if (!empty($results['seo']['https'])) {
            $score += self::WEIGHT_HTTPS;
        }

        // Mobile Responsiveness
        if (!empty($results['seo']['mobile'])) {
            $score += self::WEIGHT_MOBILE;
        }

        // PageSpeed
        if (isset($results['pagespeed']['score'])) {
            $speedScore = $results['pagespeed']['score'];
            $score += ($speedScore / 100) * self::WEIGHT_PAGESPEED;
        }

        return round($score);
    }
}
