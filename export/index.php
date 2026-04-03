<?php

$publicTarget = 'export.php';

$root = __DIR__;
while (!is_dir($root . '/public')) {
    $parent = dirname($root);
    if ($parent === $root) {
        http_response_code(500);
        echo 'Application root not found.';
        exit;
    }
    $root = $parent;
}

$target = $root . '/public/' . $publicTarget;
if (!is_file($target)) {
    http_response_code(404);
    echo 'This page does not exist.';
    exit;
}

require $target;
