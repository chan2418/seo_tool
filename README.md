# Beginner-Friendly SEO Audit Web Application (Phase 1)

A PHP-based SEO Audit tool that analyzes a website's homepage and provides an SEO score, structured report, and improvement suggestions.

## Features

- **SEO Score Calculation**: Weighted scoring (0-100) based on critical SEO metrics.
- **Meta Tag Analysis**: Checks for presence and length of Title and Description.
- **Heading Analysis**: Checks for proper H1 usage.
- **Image Optimization**: Analyzes ALT attributes.
- **Technical Checks**: HTTPS validation and Mobile Responsiveness.
- **Performance**: Integration with Google PageSpeed Insights API.

## Project Structure

```
/public
    index.php       # Landing page with input form
    results.php     # Results dashboard
    analyze.php     # API Endpoint
/assets
    css/            # Stylesheets
    js/             # JavaScript
/config
    database.php    # Database connection
    config.php      # Environment variables (API Keys, DB Creds)
/services
    SeoAnalyzer.php # Core SEO logic
    PageSpeedService.php
    ScoringEngine.php
/models
    AuditModel.php  # Database interactions
/utils
    Validator.php   # URL validation and sanitation
```

## Deployment Guide

### 1. Requirements
- PHP 8.0+
- MySQL 5.7+
- Apache/Nginx
- cURL extension enabled
- DOM extension enabled

### 2. Database Setup
1. Create a MySQL database (e.g., `seo_audit_tool`).
2. Import the `database.sql` file located in the root directory.

### 3. Configuration
1. Open `config/config.php`.
2. Update the database credentials (`db_host`, `db_name`, `db_user`, `db_pass`).
3. (Optional) Add your Google PageSpeed API Key to `pagespeed_api_key`.

### 4. Hosting (Shared Hosting)
1. Upload all files to your server (e.g., `public_html`).
2. Point your domain to the `public` folder `public_html/public` or move contents of `public` to root if preferred (adjust includes accordingly).

## Security
- Input validation prevents malformed URLs.
- Output sanitization prevents XSS.
- PDO used for all database queries to prevent SQL injection.
