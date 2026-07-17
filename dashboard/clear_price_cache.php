<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { http_response_code(403); exit; }
header('Content-Type: application/json');
unset($_SESSION['bd_dse_cache'],$_SESSION['bd_dse_cache_time'],$_SESSION['bd_cse_cache'],$_SESSION['bd_cse_cache_time']);
echo json_encode(['cleared'=>true]);
