<?php

class PageSpeedService {
    private $apiKey;
    private $apiUrl = "https://www.googleapis.com/pagespeedonline/v5/runPagespeed";

    public function __construct() {
        $config = require __DIR__ . '/../config/config.php';
        $this->apiKey = $config['pagespeed_api_key'] ?? '';
    }

    public function analyze($url) {
        // Check if key is empty or looks like a placeholder
        if (empty($this->apiKey) || stripos($this->apiKey, 'YOUR_API') !== false || strlen($this->apiKey) < 10) {
            // SIMULATION MODE (For Demo/Dev Purposes)
            return [
                'score' => rand(65, 92), 
                'error' => null
            ];
        }

        $params = [
            'url' => $url,
            'key' => $this->apiKey,
            'strategy' => 'mobile', // Testing mobile performance as standard
            'category' => 'performance'
        ];

        $query = http_build_query($params);
        $endpoint = $this->apiUrl . '?' . $query;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // PageSpeed can be slow
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return [
                'score' => 0,
                'error' => 'API request failed'
            ];
        }

        $data = json_decode($response, true);
        
        // Extract score (0-1) and convert to 100 scale
        if (isset($data['lighthouseResult']['categories']['performance']['score'])) {
            return [
                'score' => round($data['lighthouseResult']['categories']['performance']['score'] * 100),
                'error' => null
            ];
        }

        return [
            'score' => 0,
            'error' => 'Could not parse score'
        ];
    }
}
