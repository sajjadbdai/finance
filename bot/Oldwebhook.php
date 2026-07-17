<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/parser.php';
require_once __DIR__ . '/telegram.php';

// Log all incoming for debugging
$input = file_get_contents('php://input');
file_put_contents(__DIR__ . '/../webhook_debug.log',
    date('Y-m-d H:i:s') . " | " . $input . "\n\n",
    FILE_APPEND
);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(403); exit; }

$update = json_decode($input, true);
if (!$update) exit;

// ---- Handle CALLBACK QUERY (inline ✅/❌ buttons) ----
if (isset($update['callback_query'])) {
    handleCallback($update['callback_query']);
    exit;
}

// ---- Handle MESSAGE ----
$message = $update['message'] ?? $update['edited_message'] ?? null;
if (!$message) exit;

$chatId   = (int)($message['chat']['id']);
$userId   = (int)($message['from']['id']);
$username = $message['from']['first_name'] ?? 'Friend';

// Security check
if (!isAuthorized($userId)) {
    tgSend($chatId, '🔒 Unauthorized. This is a private bot.');
    exit;
}

// ---- VOICE message ----
if (isset($message['voice'])) {
    tgSend($chatId,
        "🎤 <b>Voice received!</b>\n\n" .
        "Please type your transaction as text:\n\n" .
        "<code>spent 6 BHD grocery credimax</code>\n" .
        "<code>salary 893.5 bisb</code>\n" .
        "<code>transfer 50 BBK to ILA</code>"
    );
    exit;
}

// ---- TEXT message ----
$text = trim($message['text'] ?? '');
if (!$text) exit;

// ---- Commands ----
if (str_starts_with($text, '/')) {
    handleCommand($chatId, $text);
    exit;
}

// ---- Transaction ----
processTextTransaction($chatId, $text, 'telegram', $text);

// ================================================================
function processTextTransaction(int $chatId, string $text, string $source, string $raw): void {
    tgSend($chatId, "⏳ Parsing with AI...");

    $parsed = parseTransaction($text);

    if (!$parsed) {
        tgSend($chatId,
            "❓ <b>Couldn't understand that.</b>\n\n" .
            "Try one per message:\n" .
            "<code>spent 6 BHD grocery credimax</code>\n" .
            "<code>ILA to BisB transfer 50 BHD</code>\n" .
            "<code>salary 893.5 BHD bisb</code>\n" .
            "<code>medical 3 doctor bisb</code>"
        );
        return;
    }

    $amount   = number_format((float)($parsed['amount'] ?? 0), 3);
    $currency = $parsed['currency'] ?? '';
    $type     = strtoupper($parsed['type'] ?? '');
    $account  = $parsed['account'] ?? '';
    $toAcc    = $parsed['to_account'] ?? null;
    $cat      = $parsed['category'] ?? '';
    $subcat   = $parsed['subcategory'] ?? '';
    $note     = $parsed['note'] ?? '';
    $conf     = round(($parsed['confidence'] ?? 1) * 100);

    $_t = strtolower($parsed['type'] ?? '');
    $emoji = $_t === 'income' ? '🟢' : ($_t === 'expense' ? '🔴' : ($_t === 'transfer' ? '🔵' : '⚪'));

    $msg  = "$emoji <b>{$type}: {$amount} {$currency}</b>\n";
    $msg .= "📂 {$cat}" . ($subcat ? " › {$subcat}" : '') . "\n";
    $msg .= "🏦 {$account}" . ($toAcc ? " → {$toAcc}" : '') . "\n";
    if ($note) $msg .= "📝 {$note}\n";
    $msg .= "🎯 Confidence: {$conf}%\n\n";
    $msg .= "Save this transaction?";

    // Save to session
    $sessionData = json_encode(['parsed' => $parsed, 'raw' => $raw, 'source' => $source]);
    try {
        db()->prepare("INSERT INTO bot_sessions (telegram_id, state, context)
                       VALUES (?, 'confirm', ?)
                       ON DUPLICATE KEY UPDATE state='confirm', context=VALUES(context)")
           ->execute([$chatId, $sessionData]);
    } catch (Exception $e) {
        tgSend($chatId, "❌ DB error: " . $e->getMessage());
        return;
    }

    tgSend($chatId, $msg, [
        'reply_markup' => json_encode([
            'inline_keyboard' => [[
                ['text' => '✅ Save', 'callback_data' => 'confirm_yes'],
                ['text' => '❌ Cancel', 'callback_data' => 'confirm_no'],
            ]]
        ])
    ]);
}

// ================================================================
function handleCallback(array $cb): void {
    $chatId     = (int)$cb['message']['chat']['id'];
    $msgId      = (int)$cb['message']['message_id'];
    $data       = $cb['data'];
    $callbackId = $cb['id'];

    // Acknowledge button press
    $ch = curl_init(TELEGRAM_API . '/answerCallbackQuery');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode(['callback_query_id' => $callbackId]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    curl_exec($ch); curl_close($ch);

    // Get session
    try {
        $st = db()->prepare("SELECT state, context FROM bot_sessions WHERE telegram_id=?");
        $st->execute([$chatId]);
        $session = $st->fetch();
    } catch (Exception $e) {
        tgSend($chatId, "❌ DB error: " . $e->getMessage());
        return;
    }

    if (!$session || $session['state'] !== 'confirm') {
        tgSend($chatId, "⏰ Session expired. Please re-send your transaction.");
        return;
    }

    $ctx = json_decode($session['context'], true);

    if ($data === 'confirm_yes') {
        $txnId = saveTransaction($ctx['parsed'], $ctx['source'] ?? 'telegram', $ctx['raw'] ?? '');

        if ($txnId) {
            $acc = getAccountByName($ctx['parsed']['account'] ?? '');
            $cur = $acc['currency'] ?? '';

            // For credit cards, show payable+outstanding total
            if ($acc && $acc['is_credit_card']) {
                $ccBal = getCCBalancesBot($acc);
                $bal   = '-' . number_format($ccBal['total'], 3);
                $balExtra = "\n📊 Payable: " . number_format($ccBal['payable'],3) . " | Outst: " . number_format($ccBal['outstanding'],3) . " {$cur}";
            } else {
                $bal      = $acc ? number_format((float)$acc['balance'], 3) : '—';
                $balExtra = '';
            }

            tgSend($chatId,
                "✅ <b>Saved! #{$txnId}</b>\n\n" .
                strtoupper($ctx['parsed']['type'] ?? '') . ": " .
                number_format((float)($ctx['parsed']['amount'] ?? 0), 3) . " " .
                ($ctx['parsed']['currency'] ?? '') . "\n" .
                "📂 " . ($ctx['parsed']['category'] ?? '') . "\n" .
                "🏦 " . ($ctx['parsed']['account'] ?? '') . "\n\n" .
                "💰 New balance: <b>{$bal} {$cur}</b>" . $balExtra
            );
        } else {
            tgSend($chatId, "❌ Could not save — account name not matched. Try /accounts to see exact names.");
        }
    } else {
        tgSend($chatId, "❌ Cancelled.");
    }

    // Clear session
    db()->prepare("UPDATE bot_sessions SET state='idle', context='' WHERE telegram_id=?")
       ->execute([$chatId]);

    // Remove inline keyboard
    $ch = curl_init(TELEGRAM_API . '/editMessageReplyMarkup');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'chat_id'      => $chatId,
            'message_id'   => $msgId,
            'reply_markup' => json_encode(['inline_keyboard' => []])
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    curl_exec($ch); curl_close($ch);
}

// ================================================================
function handleCommand(int $chatId, string $text): void {
    $cmd = strtolower(explode(' ', $text)[0]);

    switch ($cmd) {
        case '/start':
        case '/help':
            tgSend($chatId,
                "👋 <b>Sajjad Finance Bot</b>\n\n" .
                "Send one transaction per message:\n\n" .
                "💬 <code>spent 6 BHD grocery credimax</code>\n" .
                "💬 <code>salary 893.5 bisb</code>\n" .
                "💬 <code>transfer 50 BBK to ILA</code>\n" .
                "💬 <code>medical 3 BHD doctor bisb</code>\n" .
                "💬 <code>1200 BDT brac food eating out</code>\n\n" .
                "📊 <b>Commands:</b>\n" .
                "/balance — all account balances\n" .
                "/today — today's transactions\n" .
                "/monthly — this month summary\n" .
                "/report — last 7 days\n" .
                "/accounts — list all accounts\n" .
                "/rates — exchange rates"
            );
            break;
        case '/balance':   sendBalanceSummary($chatId); break;
        case '/today':     sendTodaySummary($chatId);   break;
        case '/monthly':   sendMonthlySummary($chatId); break;
        case '/accounts':  sendAccountsList($chatId);   break;
        case '/rates':     sendRates($chatId);           break;
        case '/report':    sendWeeklyReport($chatId);   break;
        default:
            tgSend($chatId, "Unknown command. Type /help");
    }
}

// ================================================================
function sendBalanceSummary(int $chatId): void {
    $accounts = db()->query(
        "SELECT group_name, name, balance, currency, type
         FROM accounts WHERE is_active=1 ORDER BY type DESC, group_name, name"
    )->fetchAll();

    $totalAssets = 0; $totalLiab = 0; $groups = [];
    foreach ($accounts as $a) {
        $g = $a['group_name'] ?: $a['name'];
        $groups[$g][] = $a;
        $bhd = toBHD((float)$a['balance'], $a['currency']);
        if ($a['type'] === 'asset') $totalAssets += $bhd;
        else $totalLiab += $bhd;
    }

    $msg = "💰 <b>Account Balances</b> — " . date('d M Y') . "\n\n";
    foreach ($groups as $gname => $accs) {
        $msg .= "<b>{$gname}</b>\n";
        foreach ($accs as $a) {
            $bal   = number_format((float)$a['balance'], 2);
            $color = $a['balance'] < 0 ? '🔴' : '🔵';
            $msg  .= "  {$color} {$a['name']}: {$bal} {$a['currency']}\n";
        }
        $msg .= "\n";
    }
    $net = number_format($totalAssets + $totalLiab, 3);
    $msg .= "━━━━━━━━━━\n💎 <b>Net Worth: BD {$net}</b>";
    tgSend($chatId, $msg);
}

function sendTodaySummary(int $chatId): void {
    $today = date('Y-m-d');
    $st    = db()->prepare(
        "SELECT t.*, a.name as acc_name FROM transactions t
         LEFT JOIN accounts a ON a.id=t.account_id
         WHERE DATE(t.txn_date)=? ORDER BY t.txn_date DESC"
    );
    $st->execute([$today]);
    $rows = $st->fetchAll();

    if (!$rows) { tgSend($chatId, "📅 No transactions today yet."); return; }

    $exp = 0; $inc = 0;
    $msg = "📅 <b>Today — " . date('d M Y') . "</b>\n\n";
    foreach ($rows as $r) {
        $emoji = $r['type']==='income'?'🟢':($r['type']==='expense'?'🔴':'🔵');
        $amt   = number_format((float)$r['amount'], 2);
        $msg  .= "{$emoji} {$amt} {$r['currency']} {$r['category']} ({$r['acc_name']})\n";
        if ($r['type']==='expense') $exp += toBHD((float)$r['amount'], $r['currency']);
        if ($r['type']==='income')  $inc += toBHD((float)$r['amount'], $r['currency']);
    }
    $msg .= "\n🔴 Spent: BD " . number_format($exp,3);
    $msg .= "\n🟢 Earned: BD " . number_format($inc,3);
    tgSend($chatId, $msg);
}

function sendMonthlySummary(int $chatId): void {
    $month = date('Y-m');
    $st    = db()->prepare(
        "SELECT type, category, SUM(amount_bhd) as total FROM transactions
         WHERE DATE_FORMAT(txn_date,'%Y-%m')=? GROUP BY type, category ORDER BY type, total DESC"
    );
    $st->execute([$month]);
    $rows = $st->fetchAll();

    $inc = 0; $exp = 0; $cats = [];
    foreach ($rows as $r) {
        if ($r['type']==='income')  $inc += (float)$r['total'];
        if ($r['type']==='expense') { $exp += (float)$r['total']; $cats[] = $r; }
    }

    $msg  = "📊 <b>" . date('F Y') . " Summary</b>\n\n";
    $msg .= "🟢 Income:  BD " . number_format($inc, 3) . "\n";
    $msg .= "🔴 Expense: BD " . number_format($exp, 3) . "\n";
    $msg .= "💰 Saved:   BD " . number_format($inc-$exp, 3) . "\n\n";
    if ($cats) {
        $msg .= "<b>By Category:</b>\n";
        foreach (array_slice($cats,0,8) as $c) {
            $pct = $exp>0 ? round($c['total']/$exp*100) : 0;
            $msg .= "  • {$c['category']}: BD " . number_format($c['total'],3) . " ({$pct}%)\n";
        }
    }
    tgSend($chatId, $msg);
}

function sendAccountsList(int $chatId): void {
    $rows = db()->query("SELECT name, balance, currency FROM accounts WHERE is_active=1 ORDER BY name")->fetchAll();
    $msg  = "🏦 <b>All Accounts</b>\n\n";
    foreach ($rows as $a) {
        $bal  = number_format((float)$a['balance'], 2);
        $color = $a['balance'] < 0 ? '🔴' : '🔵';
        $msg .= "{$color} <b>{$a['name']}</b>: {$bal} {$a['currency']}\n";
    }
    tgSend($chatId, $msg);
}

function sendRates(int $chatId): void {
    $rates = db()->query("SELECT * FROM exchange_rates ORDER BY from_cur")->fetchAll();
    $msg   = "💱 <b>Exchange Rates</b>\n\n";
    foreach ($rates as $r) {
        $msg .= "1 {$r['from_cur']} = {$r['rate']} {$r['to_cur']}\n";
    }
    tgSend($chatId, $msg);
}

function sendWeeklyReport(int $chatId): void {
    $from = date('Y-m-d', strtotime('-7 days'));
    $st   = db()->prepare(
        "SELECT type, SUM(amount_bhd) as total FROM transactions WHERE txn_date>=? GROUP BY type"
    );
    $st->execute([$from]);
    $inc = 0; $exp = 0;
    foreach ($st->fetchAll() as $r) {
        if ($r['type']==='income')  $inc = (float)$r['total'];
        if ($r['type']==='expense') $exp = (float)$r['total'];
    }
    $msg  = "📈 <b>Last 7 Days</b>\n\n";
    $msg .= "🟢 Income:  BD " . number_format($inc,3) . "\n";
    $msg .= "🔴 Expense: BD " . number_format($exp,3) . "\n";
    $msg .= "💰 Net:     BD " . number_format($inc-$exp,3);
    tgSend($chatId, $msg);
}
