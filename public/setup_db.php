<?php
// public/setup_db.php

// 1. Load Database Configuration
require_once __DIR__ . '/../config/database.php';

echo "<h1>Database Setup / Verification</h1>";

try {
    $db = new Database();
    $conn = $db->connect();

    if (!$conn) {
        die("<p style='color:red'>Connection Failed. Check config/config.php</p>");
    }
    
    echo "<p style='color:green'>Database Connected Successfully.</p>";

    // 2. Read SQL File
    $sqlFile = __DIR__ . '/../database.sql';
    if (!file_exists($sqlFile)) {
        die("<p style='color:red'>Error: database.sql file not found in parent directory.</p>");
    }

    $sqlContent = file_get_contents($sqlFile);

    // 3. Clean SQL Queries
    // Remove "CREATE DATABASE" and "USE" commands as we are already connected to the DB defined in config.
    // Also split by semicolon to execute one by one.
    
    // Remove comments
    $sqlContent = preg_replace('/--.*\n/', '', $sqlContent);
    
    $queries = explode(';', $sqlContent);

    echo "<ul>";
    
    foreach ($queries as $query) {
        $query = trim($query);
        
        // Skip empty lines or specific commands we want to ignore
        if (empty($query)) continue;
        if (stripos($query, 'CREATE DATABASE') === 0) continue;
        if (stripos($query, 'USE ') === 0) continue;

        try {
            $conn->exec($query);
            // Truncate long queries for display
            $displayQuery = strlen($query) > 50 ? substr($query, 0, 50) . '...' : $query;
            echo "<li>Executed: " . htmlspecialchars($displayQuery) . " <span style='color:green'>[OK]</span></li>";
        } catch (PDOException $e) {
            // Check if error is "Table already exists" - usually safe to ignore if using IF NOT EXISTS
            echo "<li>Query: " . htmlspecialchars(substr($query, 0, 50)) . "... <br><span style='color:orange'>Result: " . htmlspecialchars($e->getMessage()) . "</span></li>";
        }
    }
    
    echo "</ul>";
    echo "<h3>Setup Complete.</h3>";
    echo "<p><a href='/'>Go to Home</a></p>";
    
    // Security Warning
    echo "<div style='background:#fff3cd; padding:10px; border:1px solid #ffeeba; margin-top:20px;'>
            <strong>IMPORTANT:</strong> Please delete this file (<code>setup_db.php</code>) from your server after successful setup to prevent security risks.
          </div>";

} catch (Exception $e) {
    die("<p style='color:red'>Critical Error: " . $e->getMessage() . "</p>");
}
