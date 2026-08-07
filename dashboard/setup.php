<?php
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/config.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Bot Setup'; $activePage='setup'; $backTo='index.php';

$msg = '';

// Actions
if (isset($_GET['action'])) {
    if ($_GET['action']==='set_webhook') {
        $fastUrl = rtrim(SITE_URL,'/') . '/bot/webhook_fast.php';
        $r = @file_get_contents(TELEGRAM_API . '/setWebhook?url=' . urlencode($fastUrl) . '&drop_pending_updates=true');
        $d = json_decode($r, true);
        $msg = !empty($d['ok']) ? 'webhook_set' : 'error';
    } elseif ($_GET['action']==='delete_webhook') {
        $r = @file_get_contents(TELEGRAM_API . '/deleteWebhook?drop_pending_updates=false');
        $d = json_decode($r, true);
        $msg = !empty($d['ok']) ? 'webhook_deleted' : 'error';
    } elseif ($_GET['action']==='test') {
        $r = @file_get_contents(TELEGRAM_API . '/sendMessage?chat_id=' . YOUR_TELEGRAM_ID . '&text=' . urlencode('✅ Test message from dashboard — bot connection OK!'));
        $d = json_decode($r, true);
        $msg = !empty($d['ok']) ? 'test_sent' : 'error';
    }
    header('Location: /dashboard/setup.php?msg=' . $msg); exit;
}
$msg = $_GET['msg'] ?? '';

// Get current webhook info
$info = [];
$r = @file_get_contents(TELEGRAM_API . '/getWebhookInfo');
if ($r) { $d = json_decode($r, true); $info = $d['result'] ?? []; }

$webhookActive = !empty($info['url']);

// Get bot info
$botName = '';
$r2 = @file_get_contents(TELEGRAM_API . '/getMe');
if ($r2) { $d2 = json_decode($r2, true); $botName = $d2['result']['username'] ?? ''; }

// Which endpoint is Telegram using?
$fastUrl   = rtrim(SITE_URL,'/') . '/bot/webhook_fast.php';
$usingFast = $webhookActive && strpos($info['url'],'webhook_fast.php') !== false;
$usingOld  = $webhookActive && !$usingFast;

// Queue stats
$qStats = ['pending'=>0,'processing'=>0,'done'=>0,'error'=>0];
$qLast  = null; $qExists = false;
try {
    foreach (db()->query("SELECT status, COUNT(*) c FROM bot_queue GROUP BY status")->fetchAll() as $r) {
        $qStats[$r['status']] = (int)$r['c'];
    }
    $qLast   = db()->query("SELECT update_id, status, created_at, processed_at FROM bot_queue ORDER BY id DESC LIMIT 1")->fetch();
    $qExists = true;
} catch(Exception $e) {}

// Polling status
$lastUpdateId = 0; $pollingActive = false;
try {
    $lastUpdateId = (int)db()->query("SELECT setting_value FROM app_settings WHERE setting_key='bot_last_update_id'")->fetchColumn();
    $pollingActive = $lastUpdateId > 0;
} catch(Exception $e) {}

require 'header.php'; ?>

<?php if($msg==='webhook_set'):?><div class="alert alert-success">✅ Webhook set! Bot now replies instantly.</div><?php endif;?>
<?php if($msg==='webhook_deleted'):?><div class="alert alert-success">✅ Webhook deleted. Bot now uses polling mode (cron).</div><?php endif;?>
<?php if($msg==='test_sent'):?><div class="alert alert-success">✅ Test message sent — check your Telegram!</div><?php endif;?>
<?php if($msg==='error'):?><div class="alert alert-danger">❌ Action failed. Check the bot token in config.php.</div><?php endif;?>

<!-- Current Mode -->
<div class="g2" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-title">Bot</div>
        <div class="card-value c-blue" style="font-size:1.2rem;">@<?=htmlspecialchars($botName ?: 'unknown')?></div>
        <div class="card-sub">Ariya — your finance assistant</div>
    </div>
    <div class="card">
        <div class="card-title">Current Mode</div>
        <?php if($usingFast):?>
        <div class="card-value c-green" style="font-size:1.2rem;">⚡ Fast Webhook</div>
        <div class="card-sub">Queue + worker — instant replies</div>
        <?php elseif($usingOld):?>
        <div class="card-value c-red" style="font-size:1.2rem;">⚠️ Old Webhook</div>
        <div class="card-sub">Slow endpoint — will time out. Switch below.</div>
        <?php else:?>
        <div class="card-value c-orange" style="font-size:1.2rem;">🔄 Polling</div>
        <div class="card-sub">Needs the bot_poller.php cron</div>
        <?php endif;?>
    </div>
</div>

<!-- Webhook Details -->
<div class="card" style="margin-bottom:20px;">
    <div class="section-title" style="margin-bottom:14px;">⚙️ Connection Details</div>
    <table class="tbl" style="font-size:.85rem;">
        <tr><td style="width:40%;color:var(--muted);">Webhook URL</td><td><?=$info['url'] ? htmlspecialchars($info['url']) : '<span class="c-muted">— not set (polling mode)</span>'?></td></tr>
        <tr><td style="color:var(--muted);">Expected URL</td><td><code style="font-size:.8rem;"><?=htmlspecialchars($fastUrl)?></code></td></tr>
        <tr><td style="color:var(--muted);">Message Queue</td><td>
            <?php if($qExists):?>
                <span class="c-green"><?=$qStats['done']?> done</span> ·
                <span class="c-orange"><?=$qStats['pending']+$qStats['processing']?> waiting</span> ·
                <span class="c-red"><?=$qStats['error']?> error</span>
                <?php if($qLast):?><br><small class="c-muted">last: #<?=$qLast['update_id']?> (<?=$qLast['status']?>) <?=$qLast['processed_at'] ?: $qLast['created_at']?></small><?php endif;?>
            <?php else:?><span class="c-red">bot_queue table missing — run bot_queue.sql</span><?php endif;?>
        </td></tr>
        <?php if($webhookActive):?>
        <tr><td style="color:var(--muted);">Pending Updates</td><td><?=(int)($info['pending_update_count']??0)?></td></tr>
        <?php if(!empty($info['last_error_message'])):?>
        <tr><td style="color:var(--muted);">Last Error</td><td class="c-red"><?=htmlspecialchars($info['last_error_message'])?> (<?=date('d M H:i', $info['last_error_date']??0)?>)</td></tr>
        <?php endif;?>
        <?php else:?>
        <tr><td style="color:var(--muted);">Last Processed Update</td><td>#<?=$lastUpdateId?: '—'?></td></tr>
        <tr><td style="color:var(--muted);">Cron Job Needed</td><td><code style="font-size:.75rem;">* * * * * /usr/local/bin/php /home/sajjadbd/finance.sajjad.bd/cron/bot_poller.php</code><br><small class="c-muted">Polling mode needs this cron instead of worker.php</small></td></tr>
        <?php endif;?>
    </table>
</div>

<!-- Actions -->
<div class="card" style="margin-bottom:20px;">
    <div class="section-title" style="margin-bottom:14px;">🔧 Actions</div>
    <div class="gap-2">
        <a href="?action=test" class="btn btn-primary btn-sm">📨 Send Test Message</a>
        <?php if($usingFast):?>
        <a href="?action=delete_webhook" class="btn btn-ghost btn-sm" onclick="return confirm('Switch to polling? You must also change the cron from worker.php back to bot_poller.php, or the bot will stop receiving messages.')">🔄 Switch to Polling Mode</a>
        <?php else:?>
        <a href="?action=set_webhook" class="btn btn-success btn-sm" onclick="return confirm('Point Telegram at webhook_fast.php? Make sure the cron is running worker.php.')">⚡ Switch to Fast Webhook</a>
        <?php endif;?>
    </div>
    <div style="margin-top:14px;font-size:.8rem;color:var(--muted);line-height:1.6;">
        ⚡ <strong>Fast Webhook</strong> (recommended): Telegram posts to <code>webhook_fast.php</code>, which queues the message and replies in milliseconds. <code>worker.php</code> then does the slow AI parsing in the background.<br>
        Cron required: <code>* * * * * /usr/local/bin/php /home/sajjadbd/finance.sajjad.bd/bot/worker.php</code><br><br>
        🔄 <strong>Polling</strong> (fallback): the server pulls messages every minute. Replies take up to ~1 min.<br>
        Cron required: <code>* * * * * /usr/local/bin/php /home/sajjadbd/finance.sajjad.bd/cron/bot_poller.php</code><br><br>
        ⚠️ Each mode needs its own cron — switching mode means switching the cron job too.
    </div>
</div>

<!-- Bot Commands Reference -->
<div class="card">
    <div class="section-title" style="margin-bottom:14px;">🤖 Bot Commands</div>
    <table class="tbl" style="font-size:.82rem;">
        <tr><th>Command</th><th>What it does</th></tr>
        <tr><td><code>spent 5 bhd food at restaurant ILA</code></td><td>Record an expense (natural language)</td></tr>
        <tr><td><code>income 500 bhd salary BBK</code></td><td>Record income</td></tr>
        <tr><td><code>transfer 100 bhd from BBK to ILA</code></td><td>Transfer between accounts</td></tr>
        <tr><td><code>/balance</code> or <code>/balance BBK</code></td><td>Show account balance(s)</td></tr>
        <tr><td><code>/portfolio</code></td><td>Show stock holdings</td></tr>
        <tr><td><code>/stock EBL 154 25.00 BDT DSE</code></td><td>Update stock (symbol, qty, avg cost, currency, exchange)</td></tr>
        <tr><td><code>/price BRACBANK 65.90 DSE</code></td><td>Update stock price</td></tr>
        <tr><td><code>/scheduled</code></td><td>List scheduled payments</td></tr>
        <tr><td><code>/report</code></td><td>Monthly report</td></tr>
    </table>
</div>
<?php require 'footer.php'; ?>
