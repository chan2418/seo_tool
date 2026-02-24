<?php

require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../services/SeoAnalyzer.php';
require_once __DIR__ . '/../services/PageSpeedService.php';
require_once __DIR__ . '/../services/ScoringEngine.php';
require_once __DIR__ . '/../services/RecommendationEngine.php';
require_once __DIR__ . '/../models/AuditModel.php';
require_once __DIR__ . '/../services/AlertDetectionService.php';

session_start();

require_once __DIR__ . '/../services/SubscriptionService.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? null;
if ($userId) {
    $subService = new SubscriptionService();
    if (!$subService->canPerformAudit($userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Daily audit limit reached. Upgrade to Pro for unlimited audits.']);
        exit;
    }
}

// Disable error display to prevent HTML warnings breaking JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Log errors to file instead
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Start output buffering to catch any unwanted output
ob_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean(); // Clean any previous output
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}


$input = json_decode(file_get_contents('php://input'), true);
$url = $input['url'] ?? '';

// 1. Validate URL
$validatedUrl = Validator::validateUrl($url);
if (!$validatedUrl) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid URL format']);
    exit;
}

// 2. SEO Analysis
$analyzer = new SeoAnalyzer($validatedUrl);
$seoResults = $analyzer->analyze();

if (isset($seoResults['error'])) {
    http_response_code(422);
    echo json_encode(['error' => 'Could not analyze URL. Ensure it is accessible.']);
    exit;
}

// 3. PageSpeed Analysis
$pageSpeedService = new PageSpeedService();
$pageSpeedResults = $pageSpeedService->analyze($validatedUrl);

// 4. Calculate Score
$scoringEngine = new ScoringEngine();
$fullResults = [
    'seo' => $seoResults,
    'pagespeed' => $pageSpeedResults
];
$score = $scoringEngine->calculateScore($fullResults);

// 4.5 Generate Recommendations
$recEngine = new RecommendationEngine();
$recommendations = $recEngine->generateRecommendations([
    'details' => $fullResults
]);
$fullResults['recommendations'] = $recommendations;

// 5. Save to Database
$userId = $_SESSION['user_id'] ?? null;
// Phase 2: Audits require login? 
// "Transform... into SaaS platform" implies most features require login.
// But the landing page input might be usable by guests?
// Requirement says "Allow user to re-check" and "Audit History".
// If guest, maybe just return result without saving to history? 
// Or save with user_id=0 or null.
// Let's assume for now guests can try, but history is only for users.

if ($userId) {
    try {
        $auditModel = new AuditModel();
        $auditId = $auditModel->saveAudit([
            'url' => $validatedUrl,
            'score' => $score,
            'details' => $fullResults
        ], $userId);
    } catch (Exception $e) {
        error_log("Audit Save Failed for User $userId: " . $e->getMessage());
        $auditId = null;
    }
} else {
    // Guest: Try to save for "View Report" functionality (File storage likely)
    // Passing 0 will fail DB FK constraint, trigger exception in model, and fall back to file.
    // This is acceptable behavior for now.
    try {
        $auditModel = new AuditModel();
        $auditId = $auditModel->saveAudit([
            'url' => $validatedUrl,
            'score' => $score,
            'details' => $fullResults
        ], 0);
    } catch (Exception $e) {
         error_log("Audit Save Failed for Guest: " . $e->getMessage());
         // Generate a temporary ID for frontend to display results directly? 
         // Phase 1 architecture relies on ID redirect.
         // If save failed completely, we can't redirect.
         $auditId = null;
    }
}

if (!$auditId) {
    // This should rarely happen now with fallback
    error_log("Failed to save audit (DB and File both failed) for URL: " . $validatedUrl);
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => 'System error: Could not save results.']);
    exit;
}

if ($userId) {
    try {
        $detector = new AlertDetectionService();
        $detector->runForUser((int) $userId, (string) ($_SESSION['plan_type'] ?? 'free'));
    } catch (Throwable $error) {
        error_log('Analyze alert trigger failed: ' . $error->getMessage());
    }
}

// 6. Return Response
ob_clean(); // Clean buffer before sending final JSON
echo json_encode([
    'success' => true,
    'id' => $auditId,
    'url' => $validatedUrl,
    'seo_score' => $score,
    'details' => $fullResults
]);
