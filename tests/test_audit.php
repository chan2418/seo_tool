<?php

require_once __DIR__ . '/../services/SeoAnalyzer.php';
require_once __DIR__ . '/../services/PageSpeedService.php';
require_once __DIR__ . '/../services/ScoringEngine.php';
require_once __DIR__ . '/../utils/Validator.php';

// Mock Config for testing if needed, or rely on default
// $config = ['pagespeed_api_key' => ''];

echo "Starting Verification...\n";

// 1. Validator Test
$url = "google.com";
$validUrl = Validator::validateUrl($url);
echo "Validator Test: " . ($validUrl === "https://google.com" ? "PASS" : "FAIL") . "\n";

// 2. Analyzer Test (Using google.com as a stable target)
echo "Analyzing https://www.google.com...\n";
$analyzer = new SeoAnalyzer("https://www.google.com");
$results = $analyzer->analyze();

if (isset($results['error'])) {
    echo "Analyzer Test: FAIL (Could not fetch URL)\n";
} else {
    echo "Analyzer Test: PASS\n";
    echo " - Title: " . ($results['meta_title']['value'] ? "Found" : "Missing") . "\n";
    echo " - Description: " . ($results['meta_description']['value'] ? "Found" : "Missing") . "\n";
    echo " - H1 Count: " . $results['h1_tags']['count'] . "\n";
}

// 3. PageSpeed Test (Mock)
$psService = new PageSpeedService();
$psResults = $psService->analyze("https://www.google.com");
echo "PageSpeed Test: " . (isset($psResults['score']) ? "PASS (Score: {$psResults['score']})" : "FAIL") . "\n";

// 4. Scoring Test
$scoring = new ScoringEngine();
$score = $scoring->calculateScore([
    'seo' => $results,
    'pagespeed' => $psResults
]);
echo "Scoring Test: Calculated Score = $score\n";

echo "Verification Complete.\n";
