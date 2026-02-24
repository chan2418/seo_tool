<?php

require_once __DIR__ . '/../config/database.php';

echo '<h2>Database Patch: Phase 5 AI Intelligence Layer</h2>';

try {
    $db = new Database();
    $conn = $db->connect();
    if (!$conn) {
        $cfg = require __DIR__ . '/../config/config.php';
        $host = (string) ($cfg['db_host'] ?? '');
        $port = (string) ($cfg['db_port'] ?? '');
        $name = (string) ($cfg['db_name'] ?? '');
        $user = (string) ($cfg['db_user'] ?? '');
        $pdoError = (string) ($db->getLastError() ?? 'Unknown connection error');
        throw new RuntimeException(
            'Database connection failed. Check DB credentials in .env (DB_HOST/DB_NAME/DB_USER/DB_PASS). ' .
            'Loaded values: host=' . $host . ', port=' . $port . ', db=' . $name . ', user=' . $user . '. ' .
            'PDO error: ' . $pdoError
        );
    }

    $databaseName = (string) $conn->query('SELECT DATABASE()')->fetchColumn();

    $tableExists = static function (PDO $pdo, string $dbName, string $table): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :db_name
               AND TABLE_NAME = :table_name'
        );
        $stmt->execute([
            ':db_name' => $dbName,
            ':table_name' => $table,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    };

    $columnExists = static function (PDO $pdo, string $dbName, string $table, string $column): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
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
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
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

    echo '<h3>Google Sign-In Columns</h3>';
    if ($tableExists($conn, $databaseName, 'users') && !$columnExists($conn, $databaseName, 'users', 'auth_provider')) {
        $conn->exec("ALTER TABLE users ADD COLUMN auth_provider ENUM('local','google') NOT NULL DEFAULT 'local' AFTER password");
        echo '<p style="color:green">Added users.auth_provider.</p>';
    }
    if ($tableExists($conn, $databaseName, 'users') && !$columnExists($conn, $databaseName, 'users', 'google_id')) {
        $conn->exec("ALTER TABLE users ADD COLUMN google_id VARCHAR(191) NULL AFTER auth_provider");
        echo '<p style="color:green">Added users.google_id.</p>';
    }
    if ($tableExists($conn, $databaseName, 'users') && !$columnExists($conn, $databaseName, 'users', 'google_avatar')) {
        $conn->exec("ALTER TABLE users ADD COLUMN google_avatar VARCHAR(2048) NULL AFTER google_id");
        echo '<p style="color:green">Added users.google_avatar.</p>';
    }
    if ($tableExists($conn, $databaseName, 'users') && !$indexExists($conn, $databaseName, 'users', 'idx_users_auth_provider')) {
        $conn->exec('CREATE INDEX idx_users_auth_provider ON users(auth_provider)');
    }
    if ($tableExists($conn, $databaseName, 'users') && !$indexExists($conn, $databaseName, 'users', 'uniq_users_google_id')) {
        $conn->exec('CREATE UNIQUE INDEX uniq_users_google_id ON users(google_id)');
    }

    echo '<h3>Plan Limits</h3>';
    if ($tableExists($conn, $databaseName, 'plan_limits') && !$columnExists($conn, $databaseName, 'plan_limits', 'ai_monthly_limit')) {
        $conn->exec("ALTER TABLE plan_limits ADD COLUMN ai_monthly_limit INT NOT NULL DEFAULT 3 AFTER insights_limit");
        echo '<p style="color:green">Added plan_limits.ai_monthly_limit.</p>';
    }

    echo '<h3>AI Tables</h3>';
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS ai_usage (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            month CHAR(7) NOT NULL,
            request_count INT NOT NULL DEFAULT 0,
            last_request_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_ai_usage_user_month (user_id, month),
            INDEX idx_ai_usage_month (month),
            INDEX idx_ai_usage_last_request (last_request_at)
        )"
    );
    echo '<p style="color:green">Ensured table: ai_usage</p>';

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS ai_request_queue (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            project_id INT NOT NULL,
            request_type ENUM('advisor', 'meta', 'optimizer') NOT NULL,
            request_payload JSON NOT NULL,
            response_payload JSON NULL,
            status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
            tokens_used INT NOT NULL DEFAULT 0,
            cost_estimate DECIMAL(12,4) NOT NULL DEFAULT 0,
            error_message VARCHAR(700) NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            INDEX idx_ai_queue_status_created (status, created_at),
            INDEX idx_ai_queue_user_status (user_id, status, created_at),
            INDEX idx_ai_queue_project_status (project_id, status, created_at),
            INDEX idx_ai_queue_updated (updated_at)
        )"
    );
    echo '<p style="color:green">Ensured table: ai_request_queue</p>';

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS ai_cost_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            request_id BIGINT NULL,
            tokens_used INT NOT NULL DEFAULT 0,
            cost_estimate DECIMAL(12,4) NOT NULL DEFAULT 0,
            model_name VARCHAR(80) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (request_id) REFERENCES ai_request_queue(id) ON DELETE SET NULL,
            INDEX idx_ai_cost_user_time (user_id, created_at),
            INDEX idx_ai_cost_time (created_at)
        )"
    );
    echo '<p style="color:green">Ensured table: ai_cost_logs</p>';

    echo '<h3>Default AI Config</h3>';
    $conn->exec(
        "INSERT INTO security_settings (setting_key, setting_value)
         VALUES
            ('ai_global_enabled', '1'),
            ('ai_global_concurrency_limit', '20'),
            ('ai_max_input_chars', '600')
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value)"
    );
    echo '<p style="color:green">Seeded AI security settings defaults.</p>';

    $conn->exec(
        "INSERT INTO plan_limits
            (plan_type, projects_limit, keywords_limit, api_calls_daily, insights_limit, ai_monthly_limit, can_export, can_manual_refresh)
         VALUES
            ('free', 1, 5, 250, 3, 3, 0, 0),
            ('pro', 5, 50, 2500, 80, 20, 1, 1),
            ('agency', 1000000, 200, 10000, 200, 100, 1, 1)
         ON DUPLICATE KEY UPDATE
            ai_monthly_limit = VALUES(ai_monthly_limit)"
    );
    echo '<p style="color:green">Seeded ai_monthly_limit values for plans.</p>';

    echo '<p><strong>Phase 5 database patch complete.</strong></p>';
    echo '<p><a href="ai.php">Open AI Intelligence</a></p>';
} catch (Throwable $error) {
    echo '<p style="color:red">Error: ' . htmlspecialchars($error->getMessage()) . '</p>';
}
