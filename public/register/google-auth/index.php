<?php

$target = dirname(__DIR__, 2) . '/google-auth.php';
if (!is_file($target)) {
    http_response_code(404);
    echo 'This page does not exist.';
    exit;
}

require $target;
