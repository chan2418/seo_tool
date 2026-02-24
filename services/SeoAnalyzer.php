<?php

class SeoAnalyzer {
    private $url;
    private $html;
    private $dom;
    private $xpath;

    public function __construct($url) {
        $this->url = $url;
        $this->dom = new DOMDocument();
    }

    public function analyze() {
        $this->fetchHtml();
        
        if (empty($this->html)) {
            return ['error' => 'Could not fetch URL'];
        }

        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);
        $this->dom->loadHTML($this->html);
        libxml_clear_errors();
        
        $this->xpath = new DOMXPath($this->dom);

        return [
            'meta_title' => $this->checkTitle(),
            'meta_description' => $this->checkMetaDescription(),
            'h1_tags' => $this->checkH1(),
            'images' => $this->checkImages(),
            'https' => $this->checkHttps(),
            'mobile' => $this->checkMobileViewport(),
        ];
    }

    private function fetchHtml() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; SEOAuditBot/1.0)');
        
        $this->html = curl_exec($ch);
        curl_close($ch);
    }

    private function checkTitle() {
        $titleNodes = $this->dom->getElementsByTagName('title');
        $title = $titleNodes->length > 0 ? $titleNodes->item(0)->nodeValue : null;
        $length = $title ? mb_strlen($title) : 0;
        
        $status = 'valid';
        $message = 'Perfect length.';

        if (!$title) {
            $status = 'missing';
            $message = 'Title tag is missing.';
        } elseif ($length < 50) {
            $status = 'warning';
            $message = 'Title is too short (recommended 50-60 chars).';
        } elseif ($length > 60) {
            $status = 'warning'; // Or error depending on strictness
            $message = 'Title is too long (recommended 50-60 chars).';
        }

        return [
            'value' => $title,
            'length' => $length,
            'status' => $status,
            'message' => $message
        ];
    }

    private function checkMetaDescription() {
        $metas = $this->dom->getElementsByTagName('meta');
        $description = null;

        foreach ($metas as $meta) {
            if ($meta->getAttribute('name') == 'description') {
                $description = $meta->getAttribute('content');
                break;
            }
        }

        $length = $description ? mb_strlen($description) : 0;
        $status = 'valid';
        $message = 'Perfect length.';

        if (!$description) {
            $status = 'missing';
            $message = 'Meta description is missing.';
        } elseif ($length < 150) {
            $status = 'warning';
            $message = 'Description is too short (recommended 150-160 chars).';
        } elseif ($length > 160) {
            $status = 'warning';
            $message = 'Description is too long (recommended 150-160 chars).';
        }

        return [
            'value' => $description,
            'length' => $length,
            'status' => $status,
            'message' => $message
        ];
    }

    private function checkH1() {
        $h1s = $this->dom->getElementsByTagName('h1');
        $count = $h1s->length;
        
        $status = 'valid';
        $message = 'Perfect. One H1 tag found.';

        if ($count == 0) {
            $status = 'missing';
            $message = 'No H1 tag found.';
        } elseif ($count > 1) {
            $status = 'warning';
            $message = 'Multiple H1 tags found (recommended: 1).';
        }

        return [
            'count' => $count,
            'status' => $status,
            'message' => $message
        ];
    }

    private function checkImages() {
        $images = $this->dom->getElementsByTagName('img');
        $total = $images->length;
        $missingAlt = 0;

        foreach ($images as $img) {
            if (!$img->hasAttribute('alt') || trim($img->getAttribute('alt')) === '') {
                $missingAlt++;
            }
        }

        $score = $total > 0 ? round((($total - $missingAlt) / $total) * 100) : 100;
        
        return [
            'total' => $total,
            'missing_alt' => $missingAlt,
            'score' => $score // Optimization percentage
        ];
    }

    private function checkHttps() {
        return strpos($this->url, 'https://') === 0;
    }

    private function checkMobileViewport() {
        $metas = $this->dom->getElementsByTagName('meta');
        foreach ($metas as $meta) {
            if ($meta->getAttribute('name') == 'viewport') {
                return true;
            }
        }
        return false;
    }
}
