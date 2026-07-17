<?php
/**
 * BOT SETUP PAGE - with proper sidebar navigation
 * File: dashboard/bot_setup.php (REPLACE existing)
 * Fixes: missing sidebar navigation
 */
session_start();
require_once '../config.php';
require_once 'theme_system.php';

$current_page = 'bot_setup';

$message = '';
$message_type = '';

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['webhook_url'])) {
    $webhook = trim($_POST['webhook_url']);
    if ($webhook) {
        $bot_token = defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '';
        if ($bot_token) {
            $api_url = "https://api.telegram.org/bot{$bot_token}/setWebhook";
            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['url' => $webhook]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);
            $result = json_decode($response, true);
            if ($result && $result['ok']) {
                $message = '✅ Webhook set successfully! URL: ' . htmlspecialchars($webhook);
                $message_type = 'success';
            } else {
                $message = '❌ Failed: ' . ($result['description'] ?? 'Unknown error');
                $message_type = 'error';
            }
        } else {
            $message = '❌ TELEGRAM_BOT_TOKEN not set in config.php';
            $message_type = 'error';
        }
    }
}

// Get current webhook info
$current_webhook = '';
$bot_info = [];
$bot_token = defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '';
if ($bot_token) {
    $ch = curl_init("https://api.telegram.org/bot{$bot_token}/getWebhookInfo");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $wh = json_decode($response, true);
    if ($wh && $wh['ok']) {
        $current_webhook = $wh['result']['url'] ?? '';
    }

    $ch2 = curl_init("https://api.telegram.org/bot{$bot_token}/getMe");
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    $r2 = curl_exec($ch2);
    curl_close($ch2);
    $me = json_decode($r2, true);
    if ($me && $me['ok']) $bot_info = $me['result'];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $current_theme ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Bot Setup – Sajjad Finance</title>
<link rel="stylesheet" href="theme.css">
<style>
body { padding: 0; }
.main-content { padding: 16px; max-width: 600px; }

.setup-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 24px;
  margin-bottom: 20px;
}
.setup-card h2 {
  margin: 0 0 6px;
  font-size: 17px;
  color: var(--text-primary);
  display: flex; align-items: center; gap: 8px;
}
.setup-card p {
  margin: 0 0 18px;
  font-size: 13px;
  color: var(--text-secondary);
}
.form-group { margin-bottom: 14px; }
.form-group label {
  display: block; font-size: 12px; font-weight: 600;
  color: var(--text-secondary); margin-bottom: 5px;
  text-transform: uppercase; letter-spacing: 0.04em;
}
.form-group input { width: 100%; }
.save-btn {
  background: var(--accent-blue); color: #fff;
  border: none; border-radius: 10px; padding: 12px 24px;
  font-size: 14px; font-weight: 600; cursor: pointer;
  transition: opacity 0.15s;
}
.save-btn:hover { opacity: 0.9; }
.alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; }
.alert.success { background: rgba(62,207,142,0.1); border:1px solid var(--positive); color:var(--positive); }
.alert.error   { background: rgba(242,107,107,0.1); border:1px solid var(--negative); color:var(--negative); }

.info-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 10px 0; border-bottom: 1px solid var(--border-subtle); font-size: 13px;
}
.info-row:last-child { border-bottom: none; }
.info-label { color: var(--text-secondary); }
.info-value { color: var(--text-primary); font-weight: 500; font-family: monospace; font-size: 12px; }

.cmd-list { list-style: none; padding: 0; margin: 0; }
.cmd-list li {
  display: flex; gap: 12px; align-items: flex-start;
  padding: 8px 0; border-bottom: 1px solid var(--border-subtle); font-size: 13px;
}
.cmd-list li:last-child { border-bottom: none; }
.cmd-tag {
  background: rgba(79,142,247,0.15); color: var(--accent-blue);
  border-radius: 6px; padding: 2px 8px; font-family: monospace;
  font-size: 12px; font-weight: 700; white-space: nowrap;
}
.cmd-desc { color: var(--text-secondary); line-height: 1.4; }
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main-content" id="main-content">

  <?php if ($message): ?>
  <div class="alert <?= $message_type ?>"><?= $message ?></div>
  <?php endif; ?>

  <!-- Bot Status -->
  <?php if ($bot_info): ?>
  <div class="setup-card">
    <h2>🤖 Bot Status</h2>
    <div class="info-row">
      <span class="info-label">Bot Name</span>
      <span class="info-value"><?= htmlspecialchars($bot_info['first_name'] ?? '') ?></span>
    </div>
    <div class="info-row">
      <span class="info-label">Username</span>
      <span class="info-value">@<?= htmlspecialchars($bot_info['username'] ?? '') ?></span>
    </div>
    <div class="info-row">
      <span class="info-label">Current Webhook</span>
      <span class="info-value" style="word-break:break-all; max-width:300px; text-align:right;">
        <?= $current_webhook ? htmlspecialchars($current_webhook) : '<span style="color:var(--negative)">Not set</span>' ?>
      </span>
    </div>
  </div>
  <?php endif; ?>

  <!-- Webhook Setup -->
  <div class="setup-card">
    <h2>⚙️ Telegram Webhook Setup</h2>
    <p>Configure your bot webhook so Telegram can send messages to your server.</p>

    <form method="POST">
      <div class="form-group">
        <label>Webhook URL</label>
        <input type="url" name="webhook_url"
               value="https://finance.sajjad.bd/telegram_bot.php"
               placeholder="https://finance.sajjad.bd/telegram_bot.php">
      </div>
      <button type="submit" class="save-btn">⚡ Set Webhook</button>
    </form>
  </div>

  <!-- Bot Commands -->
  <div class="setup-card">
    <h2>📋 Available Bot Commands</h2>
    <ul class="cmd-list">
      <li><span class="cmd-tag">/balance</span><span class="cmd-desc">Show all account balances grouped by category</span></li>
      <li><span class="cmd-tag">/portfolio</span><span class="cmd-desc">Show investment portfolio with P&L</span></li>
      <li><span class="cmd-tag">/add</span><span class="cmd-desc">Add a transaction (guided multi-step)</span></li>
      <li><span class="cmd-tag">/scheduled</span><span class="cmd-desc">List upcoming scheduled payments</span></li>
      <li><span class="cmd-tag">/rates</span><span class="cmd-desc">Show currency exchange rates</span></li>
      <li><span class="cmd-tag">/help</span><span class="cmd-desc">Show all available commands</span></li>
    </ul>
  </div>

</div>
</body>
</html>
