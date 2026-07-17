<?php
// Serve dashboard from root URL
// URL stays as finance.sajjad.bd/
define('DASHBOARD_ROOT', __DIR__ . '/dashboard');
chdir(__DIR__ . '/dashboard');
require __DIR__ . '/dashboard/index.php';
