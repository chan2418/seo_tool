<?php

require_once __DIR__ . '/../utils/Env.php';

Env::load(dirname(__DIR__) . '/.env');

return [
    'db_host' => Env::get('DB_HOST', 'localhost'),
    'db_port' => Env::get('DB_PORT', '3306'),
    'db_name' => Env::get('DB_NAME', 'seo_tool'),
    'db_user' => Env::get('DB_USER', 'your_database_user'),
    'db_pass' => Env::get('DB_PASS', ''),

    'pagespeed_api_key' => Env::get('PAGESPEED_API_KEY', ''),
    'serpapi_api_key' => Env::get('SERPAPI_API_KEY', ''),
    'dataforseo_api_key' => Env::get('DATAFORSEO_API_KEY', ''),
    'openai_api_key' => Env::get('OPENAI_API_KEY', ''),
    'openai_model' => Env::get('OPENAI_MODEL', 'gpt-4.1-mini'),
    'ai_cost_per_1k_inr' => Env::get('AI_COST_PER_1K_INR', '0.18'),

    'gsc_client_id' => Env::get('GSC_CLIENT_ID', ''),
    'gsc_client_secret' => Env::get('GSC_CLIENT_SECRET', ''),
    'gsc_redirect_uri' => Env::get('GSC_REDIRECT_URI', ''),
    'google_auth_client_id' => Env::get('GOOGLE_AUTH_CLIENT_ID', ''),
    'google_auth_client_secret' => Env::get('GOOGLE_AUTH_CLIENT_SECRET', ''),
    'google_auth_redirect_uri' => Env::get('GOOGLE_AUTH_REDIRECT_URI', ''),

    'razorpay_key_id' => Env::get('RAZORPAY_KEY_ID', ''),
    'razorpay_key_secret' => Env::get('RAZORPAY_KEY_SECRET', ''),
    'razorpay_webhook_secret' => Env::get('RAZORPAY_WEBHOOK_SECRET', ''),
    'ai_cron_token' => Env::get('AI_CRON_TOKEN', ''),
];
