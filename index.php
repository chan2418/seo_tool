<?php
/**
 * SEO Audit Tool - Hostinger Setup
 * 
 * This file redirects incoming traffic to the 'public' directory where the application lives.
 * Ideally, you should point your domain's document root to the 'public' folder.
 * If you can't change the document root (common on shared hosting), this file handles the redirection.
 */

// Permanent 301 Redirect to the public folder
header("Location: public/");
exit;
