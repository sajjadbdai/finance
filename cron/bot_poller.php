<?php
/**
 * Telegram Bot Poller - workaround for blocked webhook
 * Pulls updates from Telegram and feeds them to webhook.php locally.
 * Cron: * * * * * /usr/local/bin/php /home/sajjadbd/finance.sajjad.bd/cron/bot_poller.php
 */
require_once dirname(__DIR__) . '/config.php';

// Prevent overlapping runs (lock file)
$lockFile = sys_get_temp_dir() . '/bot_poller.lock';
$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) { exit; } // another run in progress

// Get last processed update_id from DB
$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('bot_last_update_id','0')");
$offset = (int)$pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='bot_last_update_id'")->fetchColumn();

// Poll Telegram (long-poll 25s so one cron run covers most of the minute)
$url = TELEGRAM_API . '/getUpdates?timeout=25&offset=' . ($offset + 1);
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30]);
$resp = curl_exec($ch);
curl_close($ch);
if (!$resp) { flock($lock, LOCK_UN); exit; }

$data = json_decode($resp, true);
if (empty($data['ok']) || empty($data['result'])) { flock($lock, LOCK_UN); exit; }

foreach ($data['result'] as $update) {
    $updateId = (int)$update['update_id'];

    // Feed the update to webhook.php via local HTTP POST (reuses ALL existing bot logic)
    $ch = curl_init(WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($update),
        CURLOPT_TIMEOUT        => 90,
    ]);
    curl_exec($ch);
    curl_close($ch);

    // Save progress after each update
    $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key='bot_last_update_id'")
        ->execute([$updateId]);
}

flock($lock, LOCK_UN);
echo "Processed " . count($data['result']) . " update(s)\n";
