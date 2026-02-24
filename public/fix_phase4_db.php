<?php

require_once __DIR__ . '/../config/database.php';

echo '<h2>Database Patch: Phase 4 Business Core</h2>';

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
        $stmt->execute([':db_name' => $dbName, ':table_name' => $table]);
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

    echo '<h3>Users Table</h3>';
    $conn->exec("ALTER TABLE users MODIFY COLUMN plan_type ENUM('free', 'pro', 'agency') DEFAULT 'free'");
    echo '<p style="color:green">Updated users.plan_type enum.</p>';

    if (!$columnExists($conn, $databaseName, 'users', 'role')) {
        $conn->exec("ALTER TABLE users ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'user' AFTER plan_type");
        echo '<p style="color:green">Added users.role.</p>';
    }
    if (!$columnExists($conn, $databaseName, 'users', 'auth_provider')) {
        $conn->exec("ALTER TABLE users ADD COLUMN auth_provider ENUM('local','google') NOT NULL DEFAULT 'local' AFTER password");
        echo '<p style="color:green">Added users.auth_provider.</p>';
    }
    if (!$columnExists($conn, $databaseName, 'users', 'google_id')) {
        $conn->exec("ALTER TABLE users ADD COLUMN google_id VARCHAR(191) NULL AFTER auth_provider");
        echo '<p style="color:green">Added users.google_id.</p>';
    }
    if (!$columnExists($conn, $databaseName, 'users', 'google_avatar')) {
        $conn->exec("ALTER TABLE users ADD COLUMN google_avatar VARCHAR(2048) NULL AFTER google_id");
        echo '<p style="color:green">Added users.google_avatar.</p>';
    }
    try {
        $conn->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(30) NOT NULL DEFAULT 'user'");
        echo '<p style="color:green">Updated users.role to VARCHAR(30).</p>';
    } catch (Throwable $error) {
        echo '<p style="color:orange">users.role update skipped: ' . htmlspecialchars($error->getMessage()) . '</p>';
    }
    if (!$columnExists($conn, $databaseName, 'users', 'status')) {
        $conn->exec("ALTER TABLE users ADD COLUMN status ENUM('active','suspended') DEFAULT 'active' AFTER role");
        echo '<p style="color:green">Added users.status.</p>';
    }
    if (!$columnExists($conn, $databaseName, 'users', 'suspended_reason')) {
        $conn->exec("ALTER TABLE users ADD COLUMN suspended_reason VARCHAR(255) NULL AFTER status");
        echo '<p style="color:green">Added users.suspended_reason.</p>';
    }
    if (!$columnExists($conn, $databaseName, 'users', 'last_login_at')) {
        $conn->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER suspended_reason");
        echo '<p style="color:green">Added users.last_login_at.</p>';
    }
    if (!$columnExists($conn, $databaseName, 'users', 'last_login_ip')) {
        $conn->exec("ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) NULL AFTER last_login_at");
        echo '<p style="color:green">Added users.last_login_ip.</p>';
    }
    if (!$columnExists($conn, $databaseName, 'users', 'force_password_reset')) {
        $conn->exec("ALTER TABLE users ADD COLUMN force_password_reset TINYINT(1) DEFAULT 0 AFTER last_login_ip");
        echo '<p style="color:green">Added users.force_password_reset.</p>';
    }
    if (!$columnExists($conn, $databaseName, 'users', 'force_logout_after')) {
        $conn->exec("ALTER TABLE users ADD COLUMN force_logout_after DATETIME NULL AFTER force_password_reset");
        echo '<p style="color:green">Added users.force_logout_after.</p>';
    }
    if (!$columnExists($conn, $databaseName, 'users', 'is_deleted')) {
        $conn->exec("ALTER TABLE users ADD COLUMN is_deleted TINYINT(1) DEFAULT 0 AFTER force_logout_after");
        echo '<p style="color:green">Added users.is_deleted.</p>';
    }
    if (!$columnExists($conn, $databaseName, 'users', 'deleted_at')) {
        $conn->exec("ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL AFTER is_deleted");
        echo '<p style="color:green">Added users.deleted_at.</p>';
    }
    if (!$columnExists($conn, $databaseName, 'users', 'deleted_reason')) {
        $conn->exec("ALTER TABLE users ADD COLUMN deleted_reason VARCHAR(255) NULL AFTER deleted_at");
        echo '<p style="color:green">Added users.deleted_reason.</p>';
    }
    if (!$columnExists($conn, $databaseName, 'users', 'blocked_at')) {
        $conn->exec("ALTER TABLE users ADD COLUMN blocked_at DATETIME NULL AFTER deleted_reason");
        echo '<p style="color:green">Added users.blocked_at.</p>';
    }
    if (!$columnExists($conn, $databaseName, 'users', 'updated_at')) {
        $conn->exec('ALTER TABLE users ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
        echo '<p style="color:green">Added users.updated_at.</p>';
    }

    if (!$indexExists($conn, $databaseName, 'users', 'idx_users_plan')) {
        $conn->exec('CREATE INDEX idx_users_plan ON users(plan_type)');
    }
    if (!$indexExists($conn, $databaseName, 'users', 'idx_users_role_status')) {
        $conn->exec('CREATE INDEX idx_users_role_status ON users(role, status)');
    }
    if (!$indexExists($conn, $databaseName, 'users', 'idx_users_soft_delete')) {
        $conn->exec('CREATE INDEX idx_users_soft_delete ON users(is_deleted, deleted_at)');
    }
    if (!$indexExists($conn, $databaseName, 'users', 'idx_users_last_login')) {
        $conn->exec('CREATE INDEX idx_users_last_login ON users(last_login_at)');
    }
    if (!$indexExists($conn, $databaseName, 'users', 'idx_users_last_login_ip')) {
        $conn->exec('CREATE INDEX idx_users_last_login_ip ON users(last_login_ip)');
    }
    if (!$indexExists($conn, $databaseName, 'users', 'idx_users_auth_provider')) {
        $conn->exec('CREATE INDEX idx_users_auth_provider ON users(auth_provider)');
    }
    if (!$indexExists($conn, $databaseName, 'users', 'uniq_users_google_id')) {
        $conn->exec('CREATE UNIQUE INDEX uniq_users_google_id ON users(google_id)');
    }

    echo '<h3>Audit History Compatibility</h3>';
    if ($tableExists($conn, $databaseName, 'audit_history') && !$columnExists($conn, $databaseName, 'audit_history', 'details')) {
        $conn->exec('ALTER TABLE audit_history ADD COLUMN details JSON NULL AFTER pagespeed_score');
        echo '<p style="color:green">Added audit_history.details JSON column.</p>';
    }

    echo '<h3>Subscriptions Table</h3>';
    if (!$tableExists($conn, $databaseName, 'subscriptions')) {
        $conn->exec(
            "CREATE TABLE subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                razorpay_customer_id VARCHAR(100) NULL,
                razorpay_subscription_id VARCHAR(100) NULL,
                plan_type ENUM('free','pro','agency') DEFAULT 'free',
                status ENUM('incomplete','trialing','active','past_due','canceled') DEFAULT 'incomplete',
                next_billing_date DATETIME NULL,
                grace_ends_at DATETIME NULL,
                current_period_start DATETIME NULL,
                current_period_end DATETIME NULL,
                cancel_at_period_end TINYINT(1) DEFAULT 0,
                lifetime_access TINYINT(1) DEFAULT 0,
                promotional_days INT NOT NULL DEFAULT 0,
                manual_override_until DATETIME NULL,
                admin_notes VARCHAR(255) NULL,
                start_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                end_date DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY uniq_razorpay_subscription (razorpay_subscription_id),
                INDEX idx_subscription_user_status (user_id, status),
                INDEX idx_subscription_plan_status (plan_type, status),
                INDEX idx_subscription_next_billing (next_billing_date),
                INDEX idx_subscription_grace (grace_ends_at)
            )"
        );
        echo '<p style="color:green">Created subscriptions table.</p>';
    } else {
        $subscriptionAlter = [
            'razorpay_customer_id' => "ALTER TABLE subscriptions ADD COLUMN razorpay_customer_id VARCHAR(100) NULL AFTER user_id",
            'razorpay_subscription_id' => "ALTER TABLE subscriptions ADD COLUMN razorpay_subscription_id VARCHAR(100) NULL AFTER razorpay_customer_id",
            'next_billing_date' => "ALTER TABLE subscriptions ADD COLUMN next_billing_date DATETIME NULL AFTER status",
            'grace_ends_at' => "ALTER TABLE subscriptions ADD COLUMN grace_ends_at DATETIME NULL AFTER next_billing_date",
            'current_period_start' => "ALTER TABLE subscriptions ADD COLUMN current_period_start DATETIME NULL AFTER grace_ends_at",
            'current_period_end' => "ALTER TABLE subscriptions ADD COLUMN current_period_end DATETIME NULL AFTER current_period_start",
            'cancel_at_period_end' => "ALTER TABLE subscriptions ADD COLUMN cancel_at_period_end TINYINT(1) DEFAULT 0 AFTER current_period_end",
            'lifetime_access' => "ALTER TABLE subscriptions ADD COLUMN lifetime_access TINYINT(1) DEFAULT 0 AFTER cancel_at_period_end",
            'promotional_days' => "ALTER TABLE subscriptions ADD COLUMN promotional_days INT NOT NULL DEFAULT 0 AFTER lifetime_access",
            'manual_override_until' => "ALTER TABLE subscriptions ADD COLUMN manual_override_until DATETIME NULL AFTER promotional_days",
            'admin_notes' => "ALTER TABLE subscriptions ADD COLUMN admin_notes VARCHAR(255) NULL AFTER manual_override_until",
            'created_at' => "ALTER TABLE subscriptions ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER end_date",
            'updated_at' => "ALTER TABLE subscriptions ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];
        foreach ($subscriptionAlter as $column => $query) {
            if (!$columnExists($conn, $databaseName, 'subscriptions', $column)) {
                $conn->exec($query);
                echo '<p style="color:green">Added subscriptions.' . htmlspecialchars($column) . '.</p>';
            }
        }

        try {
            $conn->exec("ALTER TABLE subscriptions MODIFY COLUMN plan_type ENUM('free','pro','agency') DEFAULT 'free'");
        } catch (Throwable $error) {
            echo '<p style="color:orange">subscriptions.plan_type enum update skipped: ' . htmlspecialchars($error->getMessage()) . '</p>';
        }
        try {
            $conn->exec("ALTER TABLE subscriptions MODIFY COLUMN status ENUM('incomplete','trialing','active','past_due','canceled') DEFAULT 'incomplete'");
        } catch (Throwable $error) {
            echo '<p style="color:orange">subscriptions.status enum update skipped: ' . htmlspecialchars($error->getMessage()) . '</p>';
        }
        try {
            $conn->exec('ALTER TABLE subscriptions MODIFY COLUMN end_date DATETIME NULL');
        } catch (Throwable $error) {
            echo '<p style="color:orange">subscriptions.end_date alter skipped: ' . htmlspecialchars($error->getMessage()) . '</p>';
        }
        if (!$indexExists($conn, $databaseName, 'subscriptions', 'uniq_razorpay_subscription')) {
            $conn->exec('CREATE UNIQUE INDEX uniq_razorpay_subscription ON subscriptions(razorpay_subscription_id)');
        }
        if (!$indexExists($conn, $databaseName, 'subscriptions', 'idx_subscription_user_status')) {
            $conn->exec('CREATE INDEX idx_subscription_user_status ON subscriptions(user_id, status)');
        }
        if (!$indexExists($conn, $databaseName, 'subscriptions', 'idx_subscription_plan_status')) {
            $conn->exec('CREATE INDEX idx_subscription_plan_status ON subscriptions(plan_type, status)');
        }
        if (!$indexExists($conn, $databaseName, 'subscriptions', 'idx_subscription_next_billing')) {
            $conn->exec('CREATE INDEX idx_subscription_next_billing ON subscriptions(next_billing_date)');
        }
        if (!$indexExists($conn, $databaseName, 'subscriptions', 'idx_subscription_grace')) {
            $conn->exec('CREATE INDEX idx_subscription_grace ON subscriptions(grace_ends_at)');
        }
    }

    echo '<h3>Phase 4 Tables</h3>';
    $createQueries = [
        'plan_limits' => "CREATE TABLE IF NOT EXISTS plan_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_type ENUM('free', 'pro', 'agency') NOT NULL UNIQUE,
            projects_limit INT NOT NULL DEFAULT 1,
            keywords_limit INT NOT NULL DEFAULT 5,
            api_calls_daily INT NOT NULL DEFAULT 250,
            insights_limit INT NOT NULL DEFAULT 3,
            ai_monthly_limit INT NOT NULL DEFAULT 3,
            can_export TINYINT(1) NOT NULL DEFAULT 0,
            can_manual_refresh TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
        'ai_usage' => "CREATE TABLE IF NOT EXISTS ai_usage (
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
        )",
        'ai_request_queue' => "CREATE TABLE IF NOT EXISTS ai_request_queue (
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
        )",
        'ai_cost_logs' => "CREATE TABLE IF NOT EXISTS ai_cost_logs (
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
        )",
        'usage_logs' => "CREATE TABLE IF NOT EXISTS usage_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            project_id INT NULL,
            metric VARCHAR(50) NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            context VARCHAR(100) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
            INDEX idx_usage_user_metric_time (user_id, metric, created_at),
            INDEX idx_usage_metric_time (metric, created_at),
            INDEX idx_usage_project_metric_time (project_id, metric, created_at)
        )",
        'system_logs' => "CREATE TABLE IF NOT EXISTS system_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            level ENUM('info', 'warning', 'error', 'critical') DEFAULT 'info',
            source VARCHAR(60) NOT NULL,
            user_id INT NULL,
            project_id INT NULL,
            message VARCHAR(800) NOT NULL,
            context_json JSON NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
            INDEX idx_system_logs_level_time (level, created_at),
            INDEX idx_system_logs_source_time (source, created_at),
            INDEX idx_system_logs_user_time (user_id, created_at)
        )",
        'payment_events' => "CREATE TABLE IF NOT EXISTS payment_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            subscription_id INT NULL,
            gateway VARCHAR(30) NOT NULL,
            gateway_event_id VARCHAR(120) NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            payload_json JSON NULL,
            processed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL,
            UNIQUE KEY uniq_gateway_event (gateway, gateway_event_id),
            INDEX idx_payment_event_type_time (event_type, processed_at),
            INDEX idx_payment_event_user_time (user_id, processed_at)
        )",
        'onboarding_progress' => "CREATE TABLE IF NOT EXISTS onboarding_progress (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            step_key VARCHAR(40) NOT NULL,
            is_completed TINYINT(1) DEFAULT 0,
            completed_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_onboarding_user_step (user_id, step_key),
            INDEX idx_onboarding_user_completed (user_id, is_completed)
        )",
        'user_roles' => "CREATE TABLE IF NOT EXISTS user_roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            role VARCHAR(30) NOT NULL,
            assigned_by INT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_user_roles_user_active (user_id, is_active),
            INDEX idx_user_roles_role_active (role, is_active)
        )",
        'user_activity_logs' => "CREATE TABLE IF NOT EXISTS user_activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            action_type VARCHAR(80) NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            metadata_json JSON NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_user_activity_user_time (user_id, created_at),
            INDEX idx_user_activity_action_time (action_type, created_at),
            INDEX idx_user_activity_ip_time (ip_address, created_at)
        )",
        'payment_logs' => "CREATE TABLE IF NOT EXISTS payment_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            subscription_id INT NULL,
            gateway VARCHAR(30) NOT NULL DEFAULT 'razorpay',
            gateway_transaction_id VARCHAR(120) NULL,
            event_type VARCHAR(80) NOT NULL,
            amount DECIMAL(12,2) DEFAULT 0,
            currency VARCHAR(10) DEFAULT 'INR',
            payment_status VARCHAR(30) NOT NULL DEFAULT 'pending',
            notes_json JSON NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL,
            INDEX idx_payment_logs_status_time (payment_status, created_at),
            INDEX idx_payment_logs_user_time (user_id, created_at),
            INDEX idx_payment_logs_event_time (event_type, created_at)
        )",
        'cron_logs' => "CREATE TABLE IF NOT EXISTS cron_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cron_name VARCHAR(100) NOT NULL,
            run_status ENUM('success', 'warning', 'failed') NOT NULL DEFAULT 'success',
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            duration_ms INT DEFAULT 0,
            message VARCHAR(600) NULL,
            stats_json JSON NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cron_logs_name_time (cron_name, created_at),
            INDEX idx_cron_logs_status_time (run_status, created_at)
        )",
        'api_usage_logs' => "CREATE TABLE IF NOT EXISTS api_usage_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            project_id INT NULL,
            provider VARCHAR(50) NOT NULL,
            endpoint VARCHAR(120) NOT NULL,
            units INT NOT NULL DEFAULT 1,
            status_code INT DEFAULT 200,
            response_time_ms INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
            INDEX idx_api_usage_provider_time (provider, created_at),
            INDEX idx_api_usage_user_time (user_id, created_at),
            INDEX idx_api_usage_project_time (project_id, created_at)
        )",
        'blocked_ips' => "CREATE TABLE IF NOT EXISTS blocked_ips (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            reason VARCHAR(255) NULL,
            blocked_by INT NULL,
            expires_at DATETIME NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (blocked_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY uniq_blocked_ip (ip_address),
            INDEX idx_blocked_ips_active_expiry (is_active, expires_at)
        )",
        'failed_logins' => "CREATE TABLE IF NOT EXISTS failed_logins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_blocked TINYINT(1) DEFAULT 0,
            INDEX idx_failed_logins_email_time (email, attempted_at),
            INDEX idx_failed_logins_ip_time (ip_address, attempted_at)
        )",
        'security_settings' => "CREATE TABLE IF NOT EXISTS security_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(80) NOT NULL,
            setting_value VARCHAR(255) NOT NULL,
            updated_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY uniq_security_setting (setting_key)
        )",
        'plans' => "CREATE TABLE IF NOT EXISTS plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_code VARCHAR(30) NOT NULL,
            display_name VARCHAR(80) NOT NULL,
            price_monthly DECIMAL(10,2) DEFAULT 0,
            price_yearly DECIMAL(10,2) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            description VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_plan_code (plan_code)
        )",
        'feature_flags' => "CREATE TABLE IF NOT EXISTS feature_flags (
            id INT AUTO_INCREMENT PRIMARY KEY,
            flag_key VARCHAR(80) NOT NULL,
            flag_name VARCHAR(120) NOT NULL,
            description VARCHAR(255) NULL,
            is_enabled TINYINT(1) DEFAULT 0,
            rollout_plan VARCHAR(30) DEFAULT 'all',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_feature_flag (flag_key),
            INDEX idx_feature_flags_enabled_plan (is_enabled, rollout_plan)
        )",
        'coupons' => "CREATE TABLE IF NOT EXISTS coupons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL,
            discount_type ENUM('percent', 'fixed') NOT NULL DEFAULT 'percent',
            discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            max_uses INT NULL,
            used_count INT NOT NULL DEFAULT 0,
            expires_at DATETIME NULL,
            plan_scope VARCHAR(30) DEFAULT 'all',
            is_active TINYINT(1) DEFAULT 1,
            metadata_json JSON NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_coupon_code (code),
            INDEX idx_coupon_active_expiry (is_active, expires_at)
        )",
        'audit_logs' => "CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            actor_user_id INT NULL,
            target_user_id INT NULL,
            project_id INT NULL,
            action_type VARCHAR(80) NOT NULL,
            ip_address VARCHAR(45) NULL,
            metadata_json JSON NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
            INDEX idx_audit_logs_actor_time (actor_user_id, created_at),
            INDEX idx_audit_logs_target_time (target_user_id, created_at),
            INDEX idx_audit_logs_action_time (action_type, created_at),
            INDEX idx_audit_logs_project_time (project_id, created_at)
        )",
    ];

    foreach ($createQueries as $table => $query) {
        $conn->exec($query);
        echo '<p style="color:green">Ensured table: ' . htmlspecialchars($table) . '</p>';
    }

    if ($tableExists($conn, $databaseName, 'plan_limits') && !$columnExists($conn, $databaseName, 'plan_limits', 'ai_monthly_limit')) {
        $conn->exec("ALTER TABLE plan_limits ADD COLUMN ai_monthly_limit INT NOT NULL DEFAULT 3 AFTER insights_limit");
        echo '<p style="color:green">Added plan_limits.ai_monthly_limit.</p>';
    }

    $conn->exec(
        "INSERT INTO plan_limits
            (plan_type, projects_limit, keywords_limit, api_calls_daily, insights_limit, ai_monthly_limit, can_export, can_manual_refresh)
         VALUES
            ('free', 1, 5, 250, 3, 3, 0, 0),
            ('pro', 5, 50, 2500, 80, 20, 1, 1),
            ('agency', 1000000, 200, 10000, 200, 100, 1, 1)
         ON DUPLICATE KEY UPDATE
            projects_limit = VALUES(projects_limit),
            keywords_limit = VALUES(keywords_limit),
            api_calls_daily = VALUES(api_calls_daily),
            insights_limit = VALUES(insights_limit),
            ai_monthly_limit = VALUES(ai_monthly_limit),
            can_export = VALUES(can_export),
            can_manual_refresh = VALUES(can_manual_refresh)"
    );
    echo '<p style="color:green">Seeded plan_limits defaults.</p>';

    $conn->exec(
        "INSERT INTO plans (plan_code, display_name, price_monthly, price_yearly, is_active, description)
         VALUES
            ('free', 'Free', 0, 0, 1, 'Starter access for individuals'),
            ('pro', 'Pro', 999, 9990, 1, 'Growth plan for professionals'),
            ('agency', 'Agency', 2999, 29990, 1, 'Scale plan for agencies')
         ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name),
            price_monthly = VALUES(price_monthly),
            price_yearly = VALUES(price_yearly),
            is_active = VALUES(is_active),
            description = VALUES(description)"
    );
    echo '<p style="color:green">Seeded plans defaults.</p>';

    $conn->exec(
        "INSERT INTO feature_flags (flag_key, flag_name, description, is_enabled, rollout_plan)
         VALUES
            ('registration_enabled', 'Registration Enabled', 'Allow new user signup.', 1, 'all'),
            ('maintenance_mode', 'Maintenance Mode', 'Enable global maintenance mode.', 0, 'all'),
            ('manual_refresh_enabled', 'Manual Refresh', 'Allow manual refresh actions.', 1, 'pro'),
            ('white_label_reports', 'White Label Reports', 'Allow white-label export.', 1, 'agency')
         ON DUPLICATE KEY UPDATE
            flag_name = VALUES(flag_name),
            description = VALUES(description),
            is_enabled = VALUES(is_enabled),
            rollout_plan = VALUES(rollout_plan)"
    );
    echo '<p style="color:green">Seeded feature flags defaults.</p>';

    $conn->exec(
        "INSERT INTO security_settings (setting_key, setting_value)
         VALUES
            ('registration_enabled', '1'),
            ('maintenance_mode', '0'),
            ('admin_2fa_required', '0'),
            ('admin_totp_secret', ''),
            ('failed_login_limit', '5'),
            ('rate_limit_admin_login_per_10m', '20'),
            ('ai_global_enabled', '1'),
            ('ai_global_concurrency_limit', '20'),
            ('ai_max_input_chars', '600')
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value)"
    );
    echo '<p style="color:green">Seeded security settings defaults.</p>';

    echo '<p><strong>Phase 4 database patch complete.</strong></p>';
    echo '<p><a href="admin/dashboard.php">Open Admin Dashboard</a></p>';
    echo '<p><a href="fix_phase5_db.php">Run Phase 5 AI Patch</a></p>';
} catch (Throwable $error) {
    echo '<p style="color:red">Error: ' . htmlspecialchars($error->getMessage()) . '</p>';
}
