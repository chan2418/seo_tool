-- Full fresh import for SEO Suite Phase 4 (Hostinger/phpMyAdmin friendly)
-- WARNING: This drops existing app tables before recreating them.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS coupons;
DROP TABLE IF EXISTS feature_flags;
DROP TABLE IF EXISTS plans;
DROP TABLE IF EXISTS security_settings;
DROP TABLE IF EXISTS failed_logins;
DROP TABLE IF EXISTS blocked_ips;
DROP TABLE IF EXISTS api_usage_logs;
DROP TABLE IF EXISTS cron_logs;
DROP TABLE IF EXISTS payment_logs;
DROP TABLE IF EXISTS user_activity_logs;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS onboarding_progress;
DROP TABLE IF EXISTS payment_events;
DROP TABLE IF EXISTS system_logs;
DROP TABLE IF EXISTS usage_logs;
DROP TABLE IF EXISTS plan_limits;
DROP TABLE IF EXISTS ai_cost_logs;
DROP TABLE IF EXISTS ai_request_queue;
DROP TABLE IF EXISTS ai_usage;
DROP TABLE IF EXISTS seo_insights;
DROP TABLE IF EXISTS phase3_request_logs;
DROP TABLE IF EXISTS search_console_pages;
DROP TABLE IF EXISTS search_console_queries;
DROP TABLE IF EXISTS search_console_cache;
DROP TABLE IF EXISTS search_console_accounts;
DROP TABLE IF EXISTS alert_settings;
DROP TABLE IF EXISTS alerts;
DROP TABLE IF EXISTS rank_alerts;
DROP TABLE IF EXISTS keyword_rankings;
DROP TABLE IF EXISTS tracked_keywords;
DROP TABLE IF EXISTS crawl_pages;
DROP TABLE IF EXISTS crawl_runs;
DROP TABLE IF EXISTS backlink_snapshots;
DROP TABLE IF EXISTS competitor_snapshots;
DROP TABLE IF EXISTS subscriptions;
DROP TABLE IF EXISTS keyword_search_logs;
DROP TABLE IF EXISTS keyword_results;
DROP TABLE IF EXISTS audit_history;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS seo_audits;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;


CREATE TABLE IF NOT EXISTS seo_audits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(2048) NOT NULL,
    seo_score INT NOT NULL,
    meta_title_details JSON,
    meta_description_details JSON,
    h1_details JSON,
    image_alt_details JSON,
    https_status BOOLEAN DEFAULT FALSE,
    mobile_status BOOLEAN DEFAULT FALSE,
    pagespeed_score INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    auth_provider ENUM('local', 'google') NOT NULL DEFAULT 'local',
    google_id VARCHAR(191) NULL,
    google_avatar VARCHAR(2048) NULL,
    plan_type ENUM('free', 'pro', 'agency') DEFAULT 'free',
    role VARCHAR(30) NOT NULL DEFAULT 'user',
    status ENUM('active', 'suspended') DEFAULT 'active',
    suspended_reason VARCHAR(255) NULL,
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(45) NULL,
    force_password_reset TINYINT(1) DEFAULT 0,
    force_logout_after DATETIME NULL,
    is_deleted TINYINT(1) DEFAULT 0,
    deleted_at DATETIME NULL,
    deleted_reason VARCHAR(255) NULL,
    blocked_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_plan (plan_type),
    INDEX idx_users_role_status (role, status),
    INDEX idx_users_soft_delete (is_deleted, deleted_at),
    INDEX idx_users_last_login (last_login_at),
    INDEX idx_users_last_login_ip (last_login_ip),
    INDEX idx_users_auth_provider (auth_provider),
    UNIQUE KEY uniq_users_google_id (google_id)
);

CREATE TABLE IF NOT EXISTS audit_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    url VARCHAR(2048) NOT NULL,
    seo_score INT NOT NULL,
    meta_title_score INT DEFAULT 0,
    meta_description_score INT DEFAULT 0,
    h1_score INT DEFAULT 0,
    image_alt_score INT DEFAULT 0,
    https_score INT DEFAULT 0,
    mobile_score INT DEFAULT 0,
    pagespeed_score INT DEFAULT 0,
    details JSON NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS keyword_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    seed_keyword VARCHAR(255) NOT NULL,
    keyword VARCHAR(255) NOT NULL,
    search_volume INT DEFAULT 0,
    difficulty_score INT DEFAULT 0,
    difficulty_label VARCHAR(30) DEFAULT 'Medium',
    intent VARCHAR(50),
    position INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_keyword_seed_time (seed_keyword, created_at),
    INDEX idx_keyword_user_time (user_id, created_at)
);

CREATE TABLE IF NOT EXISTS keyword_search_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    seed_keyword VARCHAR(255) NOT NULL,
    plan_type VARCHAR(20) NOT NULL,
    result_count INT DEFAULT 0,
    source VARCHAR(20) DEFAULT 'api',
    counted_for_limit TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_search_user_date (user_id, created_at),
    INDEX idx_search_seed_date (seed_keyword, created_at)
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    razorpay_customer_id VARCHAR(100) NULL,
    razorpay_subscription_id VARCHAR(100) NULL,
    plan_type ENUM('free', 'pro', 'agency') DEFAULT 'free',
    status ENUM('incomplete', 'trialing', 'active', 'past_due', 'canceled') DEFAULT 'incomplete',
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
);

CREATE TABLE IF NOT EXISTS competitor_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    domain VARCHAR(255) NOT NULL,
    organic_traffic INT DEFAULT 0,
    domain_authority INT DEFAULT 0,
    ranking_keywords INT DEFAULT 0,
    domain_health_score INT DEFAULT 0,
    pagespeed_score INT DEFAULT 0,
    source VARCHAR(30) DEFAULT 'api',
    payload JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_comp_domain_time (domain, created_at),
    INDEX idx_comp_user_time (user_id, created_at)
);

CREATE TABLE IF NOT EXISTS backlink_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    domain VARCHAR(255) NOT NULL,
    total_backlinks INT DEFAULT 0,
    referring_domains INT DEFAULT 0,
    dofollow_pct DECIMAL(5,2) DEFAULT 0,
    nofollow_pct DECIMAL(5,2) DEFAULT 0,
    source VARCHAR(30) DEFAULT 'api',
    payload JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_back_domain_time (domain, created_at),
    INDEX idx_back_user_time (user_id, created_at)
);

CREATE TABLE IF NOT EXISTS crawl_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    start_url VARCHAR(2048) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    status VARCHAR(30) DEFAULT 'running',
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
);

CREATE TABLE IF NOT EXISTS crawl_pages (
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
);

CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_domain (user_id, domain),
    INDEX idx_projects_user_created (user_id, created_at)
);

CREATE TABLE IF NOT EXISTS tracked_keywords (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    keyword VARCHAR(255) NOT NULL,
    country VARCHAR(8) DEFAULT 'US',
    device_type ENUM('desktop', 'mobile') DEFAULT 'desktop',
    status ENUM('active', 'paused') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_tracked_project_status (project_id, status),
    INDEX idx_tracked_keyword (keyword),
    UNIQUE KEY uniq_tracked_keyword (project_id, keyword, country, device_type)
);

CREATE TABLE IF NOT EXISTS keyword_rankings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracked_keyword_id INT NOT NULL,
    rank_position INT NOT NULL DEFAULT 101,
    checked_date DATE NOT NULL,
    source VARCHAR(20) DEFAULT 'api',
    meta_json JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tracked_keyword_id) REFERENCES tracked_keywords(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_keyword_date (tracked_keyword_id, checked_date),
    INDEX idx_rank_checked_date (checked_date),
    INDEX idx_rank_keyword_created (tracked_keyword_id, created_at)
);

CREATE TABLE IF NOT EXISTS rank_alerts (
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
);

CREATE TABLE IF NOT EXISTS alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    project_id INT NOT NULL,
    alert_type VARCHAR(60) NOT NULL,
    reference_id VARCHAR(120) NOT NULL,
    message VARCHAR(600) NOT NULL,
    severity ENUM('info', 'warning', 'critical') DEFAULT 'info',
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
);

CREATE TABLE IF NOT EXISTS alert_settings (
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
);

CREATE TABLE IF NOT EXISTS search_console_accounts (
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
);

CREATE TABLE IF NOT EXISTS search_console_cache (
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
);

CREATE TABLE IF NOT EXISTS search_console_queries (
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
);

CREATE TABLE IF NOT EXISTS search_console_pages (
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
);

CREATE TABLE IF NOT EXISTS phase3_request_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    module VARCHAR(50) NOT NULL,
    request_key VARCHAR(255),
    source VARCHAR(30) DEFAULT 'api',
    status_code INT DEFAULT 200,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_phase3_user_module_time (user_id, module, created_at),
    INDEX idx_phase3_request_time (created_at)
);

CREATE TABLE IF NOT EXISTS seo_insights (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    keyword VARCHAR(255) NULL,
    page_url VARCHAR(2048) NULL,
    insight_type VARCHAR(80) NOT NULL,
    message VARCHAR(700) NOT NULL,
    severity ENUM('info', 'opportunity', 'warning') DEFAULT 'info',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_insight_project_time (project_id, created_at),
    INDEX idx_insight_project_severity (project_id, severity, created_at),
    INDEX idx_insight_project_type (project_id, insight_type, created_at),
    INDEX idx_insight_keyword (keyword)
);

CREATE TABLE IF NOT EXISTS plan_limits (
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
);

CREATE TABLE IF NOT EXISTS ai_usage (
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
);

CREATE TABLE IF NOT EXISTS ai_request_queue (
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
);

CREATE TABLE IF NOT EXISTS ai_cost_logs (
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
);

CREATE TABLE IF NOT EXISTS usage_logs (
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
);

CREATE TABLE IF NOT EXISTS system_logs (
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
);

CREATE TABLE IF NOT EXISTS payment_events (
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
);

CREATE TABLE IF NOT EXISTS onboarding_progress (
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
);

CREATE TABLE IF NOT EXISTS user_roles (
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
);

CREATE TABLE IF NOT EXISTS user_activity_logs (
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
);

CREATE TABLE IF NOT EXISTS payment_logs (
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
);

CREATE TABLE IF NOT EXISTS cron_logs (
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
);

CREATE TABLE IF NOT EXISTS api_usage_logs (
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
);

CREATE TABLE IF NOT EXISTS blocked_ips (
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
);

CREATE TABLE IF NOT EXISTS failed_logins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_blocked TINYINT(1) DEFAULT 0,
    INDEX idx_failed_logins_email_time (email, attempted_at),
    INDEX idx_failed_logins_ip_time (ip_address, attempted_at)
);

CREATE TABLE IF NOT EXISTS security_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(80) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_security_setting (setting_key)
);

CREATE TABLE IF NOT EXISTS plans (
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
);

CREATE TABLE IF NOT EXISTS feature_flags (
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
);

CREATE TABLE IF NOT EXISTS coupons (
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
);

CREATE TABLE IF NOT EXISTS audit_logs (
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
);

INSERT INTO plans (plan_code, display_name, price_monthly, price_yearly, is_active, description)
VALUES
    ('free', 'Free', 0, 0, 1, 'Starter access for individuals'),
    ('pro', 'Pro', 999, 9990, 1, 'Growth plan for professionals'),
    ('agency', 'Agency', 2999, 29990, 1, 'Scale plan for agencies')
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    price_monthly = VALUES(price_monthly),
    price_yearly = VALUES(price_yearly),
    is_active = VALUES(is_active),
    description = VALUES(description);

INSERT INTO feature_flags (flag_key, flag_name, description, is_enabled, rollout_plan)
VALUES
    ('registration_enabled', 'Registration Enabled', 'Allow new user signup.', 1, 'all'),
    ('maintenance_mode', 'Maintenance Mode', 'Enable global maintenance mode.', 0, 'all'),
    ('manual_refresh_enabled', 'Manual Refresh', 'Allow manual refresh actions.', 1, 'pro'),
    ('white_label_reports', 'White Label Reports', 'Allow white-label export.', 1, 'agency')
ON DUPLICATE KEY UPDATE
    flag_name = VALUES(flag_name),
    description = VALUES(description),
    is_enabled = VALUES(is_enabled),
    rollout_plan = VALUES(rollout_plan);

INSERT INTO security_settings (setting_key, setting_value)
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
    setting_value = VALUES(setting_value);

INSERT INTO plan_limits
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
    can_manual_refresh = VALUES(can_manual_refresh);
