<?php
// Test what run_price_update.php actually outputs
ini_set('display_errors', '0');
error_reporting(0);

session_start();
$_SESSION['auth'] = true; // fake auth for test

ob_start();
include __DIR__ . '/run_price_update.php';
$output = ob_get_clean();

// Show raw output
echo "<pre>First 500 chars of output:\n";
echo htmlspecialchars(substr($output, 0, 500));
echo "\n\nIs valid JSON: ";
json_decode($output);
echo json_last_error() === JSON_ERROR_NONE ? 'YES' : 'NO - '.json_last_error_msg();
echo "</pre>";
