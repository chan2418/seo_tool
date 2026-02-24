<?php
require_once __DIR__ . '/../auth/AuthController.php';
$auth = new AuthController();
$auth->logout();
