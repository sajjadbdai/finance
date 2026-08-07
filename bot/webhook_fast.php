<?php
/**
 * FAST webhook endpoint.
 * Saves the update to a queue, answers Telegram in <1s, then triggers the worker.
 * All actual bot logic still lives in webhook.php (unchanged).
 */

// Browser check
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<div style="font-family:sans-serif;text-align:center;padding:60px">'
       . '<h1 style="color:#22c55e">&#9989; Fast Webhook Active</h1>'
       . '<p>' . date('Y-m-d H:i:s T') . '</p></div>';
    exit;
}

$input = file_get_contents('php://input');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $input === '') { http_response_code(403); exit; }

require_once __DIR__ . '/../config.php';

$log = __DIR__ . '/../webhook_debug.log';
$t0  = microtime(true);

// ---- 1. Queue the update (fast, ~10ms) -------------------------------
$queued = false;
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                   DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $u   = json_decode($input, true);
    $uid = (int)($u['update_id'] ?? 0);
    $pdo->prepare("INSERT IGNORE INTO bot_queue (update_id, payload, status) VALUES (?,?,'pending')")
        ->execute([$uid, $input]);
    $queued = true;
} catch (Exception $e) {
    file_put_contents($log, date('Y-m-d H:i:s') . " | QUEUE ERROR: " . $e->getMessage() . "\n\n", FILE_APPEND);
}

// ---- 2. Answer Telegram immediately ----------------------------------
$resp = '{"ok":true}';
header('Content-Type: application/json');
header('Content-Length: ' . strlen($resp));
header('Connection: close');
echo $resp;

if (function_exists('litespeed_finish_request'))      { litespeed_finish_request(); }
elseif (function_exists('fastcgi_finish_request'))    { fastcgi_finish_request(); }
else { while (ob_get_level() > 0) { @ob_end_flush(); } @flush(); }

$ms = round((microtime(true) - $t0) * 1000);
file_put_contents($log, date('Y-m-d H:i:s') . " | ACK in {$ms}ms (queued=" . ($queued?'yes':'NO') . ") " . substr($input,0,160) . "\n\n", FILE_APPEND);

// ---- 3. Trigger the worker without waiting for it --------------------
ignore_user_abort(true);
$workerUrl = rtrim(SITE_URL, '/') . '/bot/worker.php?key=' . substr(md5(TELEGRAM_BOT_TOKEN), 0, 16);
$ch = curl_init($workerUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT_MS     => 300,   // fire and forget
    CURLOPT_NOSIGNAL       => 1,
    CURLOPT_SSL_VERIFYPEER => false,
]);
curl_exec($ch);
curl_close($ch);
