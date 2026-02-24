<?php

class RecommendationEngine {
    
    public function generateRecommendations($auditResults) {
        $recommendations = [];
        $seo = $auditResults['details']['seo'];
        $pagespeed = $auditResults['details']['pagespeed'];

        // 1. Meta Title
        // 1. Meta Title
        $titleValue = $seo['meta_title']['value'] ?? '';
        if (empty($titleValue)) {
            $recommendations[] = [
                'type' => 'critical',
                'category' => 'Meta Tags',
                'message' => 'Missing Meta Title.',
                'action' => 'Add a <title> tag to your <head> section. Keep it between 50-60 characters.'
            ];
        } elseif (mb_strlen($titleValue) < 30) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'Meta Tags',
                'message' => 'Meta Title is too short.',
                'action' => 'Expand your title. Aim for 50-60 characters.'
            ];
        } elseif (mb_strlen($titleValue) > 65) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'Meta Tags',
                'message' => 'Meta Title is too long.',
                'action' => 'Shorten your title to max 60 chars.'
            ];
        }

        // 2. Meta Description
        $descValue = $seo['meta_description']['value'] ?? '';
        if (empty($descValue)) {
            $recommendations[] = [
                'type' => 'critical',
                'category' => 'Meta Tags',
                'message' => 'Missing Meta Description.',
                'action' => 'Add a <meta name="description"> tag.'
            ];
        } elseif (mb_strlen($descValue) < 100) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'Meta Tags',
                'message' => 'Meta Description is too short.',
                'action' => 'Expand description to 150-160 chars.'
            ];
        }

        // 3. H1 Tags
        $h1Count = $seo['h1_tags']['count'] ?? 0;
        if ($h1Count === 0) {
            $recommendations[] = [
                'type' => 'critical',
                'category' => 'Content',
                'message' => 'Missing H1 Heading.',
                'action' => 'Add exactly one <h1> tag.'
            ];
        } elseif ($h1Count > 1) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'Content',
                'message' => 'Multiple H1 Tags found.',
                'action' => 'Use only one <h1> per page.'
            ];
        }

        // 4. Images
        if ($seo['images']['missing_alt'] > 0) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'Images',
                'message' => $seo['images']['missing_alt'] . ' images are missing ALT text.',
                'action' => 'Add descriptive alt attributes to all images for accessibility and SEO.'
            ];
        }

        // 5. HTTPS
        if (!$seo['https']) {
            $recommendations[] = [
                'type' => 'critical',
                'category' => 'Security',
                'message' => 'Site is not using HTTPS.',
                'action' => 'Install an SSL certificate and force HTTPS redirection to secure user data.'
            ];
        }

        // 6. Mobile
        if (!$seo['mobile']) {
            $recommendations[] = [
                'type' => 'critical',
                'category' => 'Mobile',
                'message' => 'No Viewport Meta Tag detected.',
                'action' => 'Add <meta name="viewport" content="width=device-width, initial-scale=1"> for mobile responsiveness.'
            ];
        }

        // 7. PageSpeed
        if ($pagespeed['score'] < 50) {
            $recommendations[] = [
                'type' => 'critical',
                'category' => 'Performance',
                'message' => 'PageSpeed score is critical (' . $pagespeed['score'] . ').',
                'action' => ' optimize images, minify CSS/JS, and leverage browser caching.'
            ];
        } elseif ($pagespeed['score'] < 90) {
             $recommendations[] = [
                'type' => 'warning',
                'category' => 'Performance',
                'message' => 'PageSpeed score needs improvement (' . $pagespeed['score'] . ').',
                'action' => 'Review Core Web Vitals and optimize server response times.'
            ];
        }

        return $recommendations;
    }
}
