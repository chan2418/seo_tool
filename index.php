<?php
/**
 * SEO Audit Tool - Hostinger Setup
 *
 * Front controller for hosts where document root points to project root.
 * Load the real app entrypoint from /public without external redirects.
 */

$target = __DIR__ . '/public/index.php';
if (!is_file($target)) {
    http_response_code(500);
    echo 'Application entrypoint not found.';
    exit;
}

require $target;
