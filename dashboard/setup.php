<?php



$result = '';
if (isset($_POST['action'])) {
    $ch = curl_init();
    if ($_POST['action'] === 'set_webhook') {
        $url = TELEGRAM_API . '/setWebhook';
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['url' => WEBHOOK_URL]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        $res    = curl_exec($ch);
        $result = '✅ Webhook set: ' . $res;
    } elseif ($_POST['action'] === 'get_info') {
        curl_setopt_array($ch, [CURLOPT_URL => TELEGRAM_API . '/getMe', CURLOPT_RETURNTRANSFER => true]);
        $res    = curl_exec($ch);
        $result = '🤖 Bot info: ' . $res;
    } elseif ($_POST['action'] === 'get_webhook') {
        curl_setopt_array($ch, [CURLOPT_URL => TELEGRAM_API . '/getWebhookInfo', CURLOPT_RETURNTRANSFER => true]);
        $res    = curl_exec($ch);
        $result = '🔗 Webhook info: ' . $res;
    } elseif ($_POST['action'] === 'delete_webhook') {
        curl_setopt_array($ch, [CURLOPT_URL => TELEGRAM_API . '/deleteWebhook', CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true]);
        $res    = curl_exec($ch);
        $result = '🗑 Deleted: ' . $res;
    }
    curl_close($ch);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bot Setup — Sajjad Finance</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { background:#0f1117; color:#e1e1e1; font-family:'Segoe UI',sans-serif; padding:30px; }
.card { background:#1a1d27; border:1px solid #2e3347; border-radius:14px; padding:28px;
        max-width:640px; margin:0 auto; }
h2 { color:#4e9af1; margin-bottom:6px; }
p { color:#8892a4; font-size:.9rem; margin-bottom:20px; }
.btn-row { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:20px; }
button { padding:11px 20px; border:none; border-radius:10px; font-size:.88rem;
         font-weight:600; cursor:pointer; }
.btn-primary  { background:#4e9af1; color:#fff; }
.btn-success  { background:#2ecc71; color:#fff; }
.btn-warning  { background:#f39c12; color:#fff; }
.btn-danger   { background:#e74c3c; color:#fff; }
.result { background:#252836; border:1px solid #2e3347; border-radius:8px; padding:14px;
          font-family:monospace; font-size:.82rem; word-break:break-all; margin-top:16px;
          color:#a8d8ea; white-space:pre-wrap; }
.info-row { display:flex; justify-content:space-between; padding:8px 0;
            border-bottom:1px solid #2e3347; font-size:.88rem; }
.info-label { color:#8892a4; }
.info-val { color:#e1e1e1; font-family:monospace; }
a { color:#4e9af1; }
</style>
</head>
<body>
<div class="card">
  <h2>⚙️ Telegram Bot Setup</h2>
  <p>Configure your bot webhook so Telegram can send messages to your server.</p>

  <div style="margin-bottom:20px">
    <div class="info-row"><span class="info-label">Webhook URL</span><span class="info-val"><?= WEBHOOK_URL ?></span></div>
    <div class="info-row"><span class="info-label">Bot Token</span><span class="info-val"><?= substr(TELEGRAM_BOT_TOKEN,0,10) ?>...<?= substr(TELEGRAM_BOT_TOKEN,-6) ?></span></div>
    <div class="info-row"><span class="info-label">Your Telegram ID</span><span class="info-val"><?= YOUR_TELEGRAM_ID ?: '⚠️ Not set — set in config.php' ?></span></div>
  </div>

  <div class="btn-row">
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="get_info">
      <button class="btn-primary" type="submit">🤖 Test Bot</button>
    </form>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="set_webhook">
      <button class="btn-success" type="submit">🔗 Set Webhook</button>
    </form>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="get_webhook">
      <button class="btn-warning" type="submit">🔍 Check Webhook</button>
    </form>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="delete_webhook">
      <button class="btn-danger" type="submit">🗑 Remove Webhook</button>
    </form>
  </div>

  <?php if ($result): ?>
    <div class="result"><?= htmlspecialchars($result) ?></div>
  <?php endif; ?>

  <div style="margin-top:24px; padding-top:16px; border-top:1px solid #2e3347;">
    <p style="margin-bottom:12px; color:#4e9af1; font-size:.85rem; font-weight:600;">SETUP CHECKLIST</p>
    <p>☐ 1. Create bot with @BotFather → get token → add to config.php</p>
    <p style="margin-top:8px">☐ 2. Get your Telegram ID from @userinfobot → add to config.php as YOUR_TELEGRAM_ID</p>
    <p style="margin-top:8px">☐ 3. Click "Test Bot" above to verify token works</p>
    <p style="margin-top:8px">☐ 4. Click "Set Webhook" to connect Telegram to your server</p>
    <p style="margin-top:8px">☐ 5. Open Telegram, message your bot: <code>/start</code></p>
  </div>

  <p style="margin-top:16px"><a href="index.php">← Back to Dashboard</a></p>
</div>
</body>
</html>
