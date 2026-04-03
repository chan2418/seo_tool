<?php
// public/fix_db.php
require_once __DIR__ . '/../config/database.php';

echo "<h2>Database Patch: Add 'details' column</h2>";

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Add 'details' JSON column to audit_history if it doesn't exist
    // MySQL doesn't have "IF NOT EXISTS" for columns in simple syntax, so we try/catch or check information_schema.
    // Simpler: Just try to add it.
    
    $sql = "ALTER TABLE audit_history ADD COLUMN details JSON AFTER pagespeed_score";
    
    try {
        $conn->exec($sql);
        echo "<p style='color:green'>Success: Added 'details' column to audit_history.</p>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
             echo "<p style='color:orange'>Column 'details' already exists.</p>";
        } else {
             echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
        }
    }
    
    // Also ensuring user_id is nullable might be good for guests, but let's stick to file fallback for now.
    
} catch (Exception $e) {
    echo "<p style='color:red'>" . $e->getMessage() . "</p>";
}

echo "<p><a href='/'>Go Home</a></p>";
