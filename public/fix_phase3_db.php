<?php

require_once __DIR__ . '/../config/database.php';

echo '<h2>Database Patch: Phase 3 Modules</h2>';

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

    echo '<p>Updating users.plan_type enum...</p>';
    try {
        $conn->exec("ALTER TABLE users MODIFY COLUMN plan_type ENUM('free', 'pro', 'agency') DEFAULT 'free'");
        echo '<p style="color:green">users.plan_type supports free/pro/agency.</p>';
    } catch (Throwable $error) {
        echo '<p style="color:orange">users.plan_type update skipped: ' . htmlspecialchars($error->getMessage()) . '</p>';
    }

    $createQueries = [
        'competitor_snapshots' => 'CREATE TABLE IF NOT EXISTS competitor_snapshots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            domain VARCHAR(255) NOT NULL,
            organic_traffic INT DEFAULT 0,
            domain_authority INT DEFAULT 0,
            ranking_keywords INT DEFAULT 0,
            domain_health_score INT DEFAULT 0,
            pagespeed_score INT DEFAULT 0,
            source VARCHAR(30) DEFAULT "api",
            payload JSON,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_comp_domain_time (domain, created_at),
            INDEX idx_comp_user_time (user_id, created_at)
        )',
        'backlink_snapshots' => 'CREATE TABLE IF NOT EXISTS backlink_snapshots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            domain VARCHAR(255) NOT NULL,
            total_backlinks INT DEFAULT 0,
            referring_domains INT DEFAULT 0,
            dofollow_pct DECIMAL(5,2) DEFAULT 0,
            nofollow_pct DECIMAL(5,2) DEFAULT 0,
            source VARCHAR(30) DEFAULT "api",
            payload JSON,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_back_domain_time (domain, created_at),
            INDEX idx_back_user_time (user_id, created_at)
        )',
        'crawl_runs' => 'CREATE TABLE IF NOT EXISTS crawl_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            start_url VARCHAR(2048) NOT NULL,
            domain VARCHAR(255) NOT NULL,
            status VARCHAR(30) DEFAULT "running",
            progress INT DEFAULT 0,
            total_pages INT DEFAULT 0,
            pages_completed INT DEFAULT 0,
            technical_score INT DEFAULT 0,
            content_score INT DEFAULT 0,
            authority_score INT DEFAULT 0,
            keyword_score INT DEFAULT 0,
            final_score INT DEFAULT 0,
            queue_json JSON,
            summary_json JSON,
            error_message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_crawl_user_time (user_id, created_at),
            INDEX idx_crawl_domain_time (domain, created_at)
        )',
        'crawl_pages' => 'CREATE TABLE IF NOT EXISTS crawl_pages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            run_id INT NOT NULL,
            url VARCHAR(2048) NOT NULL,
            title VARCHAR(512),
            meta_description TEXT,
            h1_count INT DEFAULT 0,
            word_count INT DEFAULT 0,
            broken_links INT DEFAULT 0,
            is_thin_content TINYINT(1) DEFAULT 0,
            has_missing_meta TINYINT(1) DEFAULT 0,
            has_missing_h1 TINYINT(1) DEFAULT 0,
            content_hash VARCHAR(80),
            issues_json JSON,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (run_id) REFERENCES crawl_runs(id) ON DELETE CASCADE,
            INDEX idx_crawl_pages_run (run_id, created_at)
        )',
        'projects' => 'CREATE TABLE IF NOT EXISTS projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            domain VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_user_domain (user_id, domain),
            INDEX idx_projects_user_created (user_id, created_at)
        )',
        'tracked_keywords' => 'CREATE TABLE IF NOT EXISTS tracked_keywords (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            keyword VARCHAR(255) NOT NULL,
            country VARCHAR(8) DEFAULT "US",
            device_type ENUM("desktop", "mobile") DEFAULT "desktop",
            status ENUM("active", "paused") DEFAULT "active",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            INDEX idx_tracked_project_status (project_id, status),
            INDEX idx_tracked_keyword (keyword),
            UNIQUE KEY uniq_tracked_keyword (project_id, keyword, country, device_type)
        )',
        'keyword_rankings' => 'CREATE TABLE IF NOT EXISTS keyword_rankings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tracked_keyword_id INT NOT NULL,
            rank_position INT NOT NULL DEFAULT 101,
            checked_date DATE NOT NULL,
            source VARCHAR(20) DEFAULT "api",
            meta_json JSON,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (tracked_keyword_id) REFERENCES tracked_keywords(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_keyword_date (tracked_keyword_id, checked_date),
            INDEX idx_rank_checked_date (checked_date),
            INDEX idx_rank_keyword_created (tracked_keyword_id, created_at)
        )',
        'rank_alerts' => 'CREATE TABLE IF NOT EXISTS rank_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            project_id INT NOT NULL,
            tracked_keyword_id INT NOT NULL,
            alert_type VARCHAR(50) NOT NULL,
            message TEXT,
            previous_rank INT DEFAULT 0,
            current_rank INT DEFAULT 0,
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (tracked_keyword_id) REFERENCES tracked_keywords(id) ON DELETE CASCADE,
            INDEX idx_rank_alert_user_time (user_id, created_at),
            INDEX idx_rank_alert_read (user_id, is_read)
        )',
        'alerts' => 'CREATE TABLE IF NOT EXISTS alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            project_id INT NOT NULL,
            alert_type VARCHAR(60) NOT NULL,
            reference_id VARCHAR(120) NOT NULL,
            message VARCHAR(600) NOT NULL,
            severity ENUM("info", "warning", "critical") DEFAULT "info",
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            INDEX idx_alert_dedupe_lookup (user_id, project_id, alert_type, reference_id, created_at),
            INDEX idx_alert_user_time (user_id, created_at),
            INDEX idx_alert_project_time (project_id, created_at),
            INDEX idx_alert_user_read (user_id, is_read),
            INDEX idx_alert_type (alert_type),
            INDEX idx_alert_severity (severity)
        )',
        'alert_settings' => 'CREATE TABLE IF NOT EXISTS alert_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            project_id INT NOT NULL,
            rank_drop_threshold INT DEFAULT 10,
            seo_score_drop_threshold INT DEFAULT 5,
            email_notifications_enabled TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_alert_setting (user_id, project_id),
            INDEX idx_alert_setting_user (user_id),
            INDEX idx_alert_setting_project (project_id)
        )',
        'search_console_accounts' => 'CREATE TABLE IF NOT EXISTS search_console_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            project_id INT NOT NULL,
            google_property VARCHAR(2048) NOT NULL,
            access_token LONGTEXT NOT NULL,
            refresh_token LONGTEXT,
            token_expiry DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_sc_account_project (user_id, project_id),
            INDEX idx_sc_account_user (user_id),
            INDEX idx_sc_account_project (project_id),
            INDEX idx_sc_account_expiry (token_expiry)
        )',
        'search_console_cache' => 'CREATE TABLE IF NOT EXISTS search_console_cache (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            date_range VARCHAR(50) NOT NULL,
            total_clicks DECIMAL(14,2) DEFAULT 0,
            total_impressions DECIMAL(14,2) DEFAULT 0,
            avg_ctr DECIMAL(10,6) DEFAULT 0,
            avg_position DECIMAL(10,4) DEFAULT 0,
            trend_json JSON,
            fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            INDEX idx_sc_cache_project_range (project_id, date_range),
            INDEX idx_sc_cache_fetched (fetched_at)
        )',
        'search_console_queries' => 'CREATE TABLE IF NOT EXISTS search_console_queries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            query VARCHAR(2048) NOT NULL,
            clicks DECIMAL(14,2) DEFAULT 0,
            impressions DECIMAL(14,2) DEFAULT 0,
            ctr DECIMAL(10,6) DEFAULT 0,
            position DECIMAL(10,4) DEFAULT 0,
            date_range VARCHAR(50) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            INDEX idx_sc_query_project_range (project_id, date_range),
            INDEX idx_sc_query_clicks (clicks)
        )',
        'search_console_pages' => 'CREATE TABLE IF NOT EXISTS search_console_pages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            page_url VARCHAR(2048) NOT NULL,
            clicks DECIMAL(14,2) DEFAULT 0,
            impressions DECIMAL(14,2) DEFAULT 0,
            ctr DECIMAL(10,6) DEFAULT 0,
            position DECIMAL(10,4) DEFAULT 0,
            date_range VARCHAR(50) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            INDEX idx_sc_page_project_range (project_id, date_range),
            INDEX idx_sc_page_clicks (clicks)
        )',
        'phase3_request_logs' => 'CREATE TABLE IF NOT EXISTS phase3_request_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            module VARCHAR(50) NOT NULL,
            request_key VARCHAR(255),
            source VARCHAR(30) DEFAULT "api",
            status_code INT DEFAULT 200,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_phase3_user_module_time (user_id, module, created_at),
            INDEX idx_phase3_request_time (created_at)
        )',
        'seo_insights' => 'CREATE TABLE IF NOT EXISTS seo_insights (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            keyword VARCHAR(255) NULL,
            page_url VARCHAR(2048) NULL,
            insight_type VARCHAR(80) NOT NULL,
            message VARCHAR(700) NOT NULL,
            severity ENUM("info", "opportunity", "warning") DEFAULT "info",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            INDEX idx_insight_project_time (project_id, created_at),
            INDEX idx_insight_project_severity (project_id, severity, created_at),
            INDEX idx_insight_project_type (project_id, insight_type, created_at),
            INDEX idx_insight_keyword (keyword)
        )',
    ];

    foreach ($createQueries as $table => $query) {
        if ($tableExists($conn, $databaseName, $table)) {
            echo '<p style="color:orange">Table exists: ' . htmlspecialchars($table) . '</p>';
            continue;
        }

        $conn->exec($query);
        echo '<p style="color:green">Created table: ' . htmlspecialchars($table) . '</p>';
    }

    echo '<p><strong>Phase 3 database patch complete.</strong></p>';
    echo '<p><a href="fix_phase4_db.php">Run Phase 4 Database Patch</a></p>';
    echo '<p><a href="dashboard.php">Go to Dashboard</a></p>';
} catch (Throwable $error) {
    echo '<p style="color:red">Error: ' . htmlspecialchars($error->getMessage()) . '</p>';
}
