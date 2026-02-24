<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';

echo "Testing Database Connection...\n";

$cfg = require __DIR__ . '/config/config.php';
echo "Loaded DB config:\n";
echo "- host: " . (string) ($cfg['db_host'] ?? '') . "\n";
echo "- name: " . (string) ($cfg['db_name'] ?? '') . "\n";
echo "- user: " . (string) ($cfg['db_user'] ?? '') . "\n";

$db = new Database();
$conn = $db->connect();

if ($conn) {
    echo "Connection Successful!\n";
    
    // Check if table exists
    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM seo_audits");
        $count = $stmt->fetchColumn();
        echo "Table 'seo_audits' exists. Row count: $count\n";
        
        // Try a dummy insert
        $sql = "INSERT INTO seo_audits (url, seo_score, created_at) VALUES ('test.com', 0, NOW())";
        $conn->exec($sql);
        $id = $conn->lastInsertId();
        echo "Test Insert Successful. ID: $id\n";
        
        // Clean up
        $conn->exec("DELETE FROM seo_audits WHERE id = $id");
        echo "Test Delete Successful.\n";
        
    } catch (PDOException $e) {
        echo "Error querying table: " . $e->getMessage() . "\n";
    }
} else {
    echo "Connection FAILED.\n";
}
