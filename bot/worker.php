<?php
/**
 * Queue worker. Takes pending updates and feeds them to webhook.php
 * (which holds all the existing bot logic, unchanged).
 * Triggered instantly by webhook_fast.php, and by cron as a safety net.
 */
require_once __DIR__ . '/../config.php';

$expected = substr(md5(TELEGRAM_BOT_TOKEN), 0, 16);
if (PHP_SAPI !== 'cli' && ($_GET['key'] ?? '') !== $expected) { http_response_code(403); exit; }

ignore_user_abort(true);
@set_time_limit(300);

// Only one worker at a time
$lockFile = sys_get_temp_dir() . '/bot_worker.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo "busy\n"; exit; }

$log = __DIR__ . '/../webhook_debug.log';

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                   DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    file_put_contents($log, date('Y-m-d H:i:s') . " | WORKER DB ERROR: " . $e->getMessage() . "\n\n", FILE_APPEND);
    exit;
}

// Recover anything stuck in 'processing' for over 5 minutes
$pdo->exec("UPDATE bot_queue SET status='pending'
            WHERE status='processing' AND started_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");

$rows = $pdo->query("SELECT id, payload FROM bot_queue WHERE status='pending' ORDER BY id ASC LIMIT 20")->fetchAll();
$done = 0;

foreach ($rows as $r) {
    $pdo->prepare("UPDATE bot_queue SET status='processing', started_at=NOW() WHERE id=? AND status='pending'")
        ->execute([$r['id']]);

    $ch = curl_init(rtrim(SITE_URL, '/') . '/bot/webhook.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $r['payload'],
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    $pdo->prepare("UPDATE bot_queue SET status=?, processed_at=NOW(), note=? WHERE id=?")
        ->execute([$err ? 'error' : 'done', substr($err, 0, 200), $r['id']]);
    $done++;
}

flock($lock, LOCK_UN);
echo "processed {$done}\n";
