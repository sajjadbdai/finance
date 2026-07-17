<?php
ob_start();
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
$output_so_far = ob_get_contents();
ob_end_clean();

header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'output_before_json' => $output_so_far,
    'session_auth' => isset($_SESSION['auth']),
    'gemini_key' => defined('GEMINI_API_KEY') ? 'set' : 'not set',
    'anthropic_key' => defined('ANTHROPIC_API_KEY') ? 'set' : 'not set',
    'php_version' => PHP_VERSION,
]);
