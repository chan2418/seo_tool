<?php

require_once __DIR__ . '/../config/database.php';

echo '<h2>Database Patch: Keyword Research Upgrade</h2>';

try {
    $db = new Database();
    $conn = $db->connect();

    if (!$conn) {
        throw new RuntimeException('Database connection failed.');
    }

    $databaseName = $conn->query('SELECT DATABASE()')->fetchColumn();

    $columnExists = static function (PDO $pdo, string $dbName, string $table, string $column): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db_name
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            ':db_name' => $dbName,
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    };

    $indexExists = static function (PDO $pdo, string $dbName, string $table, string $index): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = :db_name
               AND TABLE_NAME = :table_name
               AND INDEX_NAME = :index_name'
        );
        $stmt->execute([
            ':db_name' => $dbName,
            ':table_name' => $table,
            ':index_name' => $index,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    };

    if (!$columnExists($conn, $databaseName, 'keyword_results', 'seed_keyword')) {
        $conn->exec('ALTER TABLE keyword_results ADD COLUMN seed_keyword VARCHAR(255) NOT NULL AFTER user_id');
        echo '<p style="color:green">Added column: keyword_results.seed_keyword</p>';
    } else {
        echo '<p style="color:orange">Column exists: keyword_results.seed_keyword</p>';
    }

    if (!$columnExists($conn, $databaseName, 'keyword_results', 'difficulty_label')) {
        $conn->exec("ALTER TABLE keyword_results ADD COLUMN difficulty_label VARCHAR(30) DEFAULT 'Medium' AFTER difficulty_score");
        echo '<p style="color:green">Added column: keyword_results.difficulty_label</p>';
    } else {
        echo '<p style="color:orange">Column exists: keyword_results.difficulty_label</p>';
    }

    if (!$columnExists($conn, $databaseName, 'keyword_results', 'position')) {
        $conn->exec('ALTER TABLE keyword_results ADD COLUMN position INT DEFAULT 0 AFTER intent');
        echo '<p style="color:green">Added column: keyword_results.position</p>';
    } else {
        echo '<p style="color:orange">Column exists: keyword_results.position</p>';
    }

    if (!$indexExists($conn, $databaseName, 'keyword_results', 'idx_keyword_seed_time')) {
        $conn->exec('CREATE INDEX idx_keyword_seed_time ON keyword_results (seed_keyword, created_at)');
        echo '<p style="color:green">Created index: idx_keyword_seed_time</p>';
    }

    if (!$indexExists($conn, $databaseName, 'keyword_results', 'idx_keyword_user_time')) {
        $conn->exec('CREATE INDEX idx_keyword_user_time ON keyword_results (user_id, created_at)');
        echo '<p style="color:green">Created index: idx_keyword_user_time</p>';
    }

    $conn->exec(
        'CREATE TABLE IF NOT EXISTS keyword_search_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            seed_keyword VARCHAR(255) NOT NULL,
            plan_type VARCHAR(20) NOT NULL,
            result_count INT DEFAULT 0,
            source VARCHAR(20) DEFAULT "api",
            counted_for_limit TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_search_user_date (user_id, created_at),
            INDEX idx_search_seed_date (seed_keyword, created_at)
        )'
    );
    echo '<p style="color:green">Ensured table exists: keyword_search_logs</p>';

    echo '<p><strong>Keyword DB upgrade complete.</strong></p>';
    echo '<p><a href="keyword.php">Go to Keyword Tool</a></p>';
} catch (Throwable $error) {
    echo '<p style="color:red">Error: ' . htmlspecialchars($error->getMessage()) . '</p>';
}
