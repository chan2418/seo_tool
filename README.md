# SEO Tool

SEO Tool is a PHP web application for SEO auditing, rank tracking, Search Console insights, alerts, and subscription-based usage controls.

## Highlights

- Website SEO audit with score and actionable recommendations
- Keyword tracking and rank history workflows
- Backlink and competitor snapshot support
- Google Search Console OAuth connect and sync flows
- AI-assisted insight generation and queue processing
- Alert engine with email notifications
- Plan and billing workflows (including Razorpay webhook handling)
- Admin dashboards for users, subscriptions, security, and system visibility

## Tech Stack

- PHP 8+
- MySQL 5.7+ or MySQL 8+
- Apache/Nginx (or PHP built-in server for local development)
- No framework dependency required

## Repository Layout

```text
public/          Web entry points and pages
services/        Business/domain logic
models/          Data access layer
middleware/      Auth, role, rate-limit, CSRF, plan enforcement
config/          Runtime config + DB bootstrap
cron/            Scheduled job scripts
storage/         Runtime JSON/log data
utils/           Shared utility classes
database.sql     Base schema
```

## Quick Start

1. Clone and enter project.

```bash
git clone https://github.com/chan2418/seo_tool.git
cd seo_tool
```

2. Create env file from template.

```bash
cp .env.example .env
```

3. Update `.env` with your local values at minimum:
- `APP_URL`
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`

4. Create database and import schema.

```bash
mysql -u <user> -p <database_name> < database.sql
```

5. Run locally.

```bash
php -S localhost:8000 -t public
```

Then open `http://localhost:8000`.

## Environment Variables

Copy from `.env.example` and fill only what you need for enabled modules.

- Core app: `APP_URL`, `APP_ENV`, `APP_KEY`
- Database: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- APIs: `PAGESPEED_API_KEY`, `SERPAPI_API_KEY`, `DATAFORSEO_API_KEY`, `OPENAI_API_KEY`
- Google: `GSC_CLIENT_ID`, `GSC_CLIENT_SECRET`, `GSC_REDIRECT_URI`, `GOOGLE_AUTH_CLIENT_ID`, `GOOGLE_AUTH_CLIENT_SECRET`, `GOOGLE_AUTH_REDIRECT_URI`
- Billing: `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`
- Mail: `SMTP_*`, `MAIL_*`
- Cron protection: `RANK_CRON_TOKEN`, `ALERT_CRON_TOKEN`, `INSIGHT_CRON_TOKEN`, `SUBSCRIPTION_CRON_TOKEN`, `AI_CRON_TOKEN`, `GSC_SYNC_CRON_TOKEN`

## Cron Jobs

All cron scripts support CLI execution directly:

```bash
php cron/rank-check.php
php cron/alert-check.php
php cron/search-console-sync.php
php cron/insight-generate.php
php cron/process-ai-queue.php
php cron/subscription-reconcile.php
```

When calling via HTTP, each cron endpoint validates its token from `.env`.

## Deployment Notes

- Set web root to `public/`
- Ensure `storage/` and `logs/` are writable by PHP
- Keep `.env` out of version control
- Rotate API keys and secrets before production rollout

## Security Notes

- Do not commit real credentials or tokens
- Use strong random values for cron tokens and app secrets
- Keep admin credentials private and rotate regularly
- For public repositories, use placeholders in docs and SQL samples
