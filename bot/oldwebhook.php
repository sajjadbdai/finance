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

// Respond to Telegram IMMEDIATELY to prevent timeout
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    echo '{"ok":true}';
    ob_end_flush();
    flush();
}
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
if (strpos($text, '/') === 0) {
    handleCommand($chatId, $text);
    exit;
}

// ---- Transaction ----
processTextTransaction($chatId, $text, 'telegram', $text);

// ================================================================
function processTextTransaction(int $chatId, string $text, string $source, string $raw): void {
    tgSend($chatId, "⏳ Parsing with AI...");

    $allParsed = parseTransactions($text);

    if (empty($allParsed)) {
        tgSend($chatId,
            "❓ <b>Couldn't understand that.</b>\n\n" .
            "Examples:\n" .
            "<code>spent 6 BHD grocery credimax</code>\n" .
            "<code>grocery 2.5 taxi 1.2 coffee 0.8 from ILA</code>\n" .
            "<code>paid 100 rent and 50 electricity from BBK</code>\n" .
            "<code>salary 893.5 BHD bisb</code>"
        );
        return;
    }

    $count = count($allParsed);

    if ($count === 1) {
        // Single transaction - original flow
        $parsed = array_values($allParsed)[0];
        sendConfirmSingle($chatId, $parsed, $raw, $source);
    } else {
        // Multiple transactions - show summary and confirm all
        sendConfirmMultiple($chatId, $allParsed, $raw, $source);
    }
}

function txnEmoji(string $type): string {
    return $type === 'income' ? '🟢' : ($type === 'expense' ? '🔴' : '🔵');
}

function txnLine(array $t): string {
    $emoji  = txnEmoji($t['type'] ?? '');
    $amt    = number_format((float)($t['amount'] ?? 0), 3);
    $cur    = $t['currency'] ?? '';
    $acc    = $t['account'] ?? '';
    $toAcc  = $t['to_account'] ?? null;
    $cat    = $t['category'] ?? '';
    $note   = $t['note'] ?? '';
    $line   = "$emoji <b>".strtoupper($t['type'] ?? '').": {$amt} {$cur}</b>\n";
    if ($cat)  $line .= "   📂 {$cat}\n";
    if ($note) $line .= "   📝 {$note}\n";
    $line .= "   🏦 {$acc}" . ($toAcc ? " → {$toAcc}" : '') . "\n";
    return $line;
}

function sendConfirmSingle(int $chatId, array $parsed, string $raw, string $source): void {
    $conf = round(($parsed['confidence'] ?? 1) * 100);
    $msg  = txnLine($parsed);
    $msg .= "\n🎯 Confidence: {$conf}%\n";
    $msg .= "\nSave this transaction?";

    $sessionData = json_encode(['mode'=>'single','parsed'=>$parsed,'raw'=>$raw,'source'=>$source]);
    db()->prepare("INSERT INTO bot_sessions (telegram_id,state,context) VALUES (?,'confirm',?) ON DUPLICATE KEY UPDATE state='confirm',context=VALUES(context)")
       ->execute([$chatId,$sessionData]);

    tgSend($chatId, $msg, ['reply_markup' => json_encode(['inline_keyboard'=>[[
        ['text'=>'✅ Save','callback_data'=>'confirm_yes'],
        ['text'=>'❌ Cancel','callback_data'=>'confirm_no'],
    ]]])]);
}

function sendConfirmMultiple(int $chatId, array $allParsed, string $raw, string $source): void {
    $count = count($allParsed);
    $msg   = "📋 <b>Found {$count} transactions:</b>\n\n";
    foreach ($allParsed as $i => $t) {
        $msg .= ($i+1) . ". " . txnLine($t) . "\n";
    }
    $totalByAcc = [];
    foreach ($allParsed as $t) {
        $key = ($t['account']??'?').'|'.($t['currency']??'BHD');
        $totalByAcc[$key] = ($totalByAcc[$key] ?? 0) + (float)($t['amount'] ?? 0);
    }
    $msg .= "💰 <b>Save all {$count} transactions?</b>";

    $sessionData = json_encode(['mode'=>'multi','all_parsed'=>array_values($allParsed),'raw'=>$raw,'source'=>$source]);
    db()->prepare("INSERT INTO bot_sessions (telegram_id,state,context) VALUES (?,'confirm',?) ON DUPLICATE KEY UPDATE state='confirm',context=VALUES(context)")
       ->execute([$chatId,$sessionData]);

    tgSend($chatId, $msg, ['reply_markup' => json_encode(['inline_keyboard'=>[[
        ['text'=>"✅ Save All {$count}",'callback_data'=>'confirm_yes'],
        ['text'=>'❌ Cancel All','callback_data'=>'confirm_no'],
    ]]])]);
}

// ================================================================
function handlePriceUpdate(int $chatId, string $text): void {
    // Format: /price SYMBOL NEW_PRICE
    $parts = explode(' ', trim($text));
    if (count($parts) < 3) { tgSend($chatId,"Usage: /price NVDA 95.50"); return; }
    $symbol = strtoupper($parts[1]);
    $price  = (float)$parts[2];
    try {
        $st = db()->prepare("UPDATE portfolio SET current_price=?,last_updated=NOW() WHERE UPPER(symbol)=?");
        $st->execute([$price,$symbol]);
        $rows = $st->rowCount();
        if ($rows) tgSend($chatId,"✅ Updated <b>{$symbol}</b> price to {$price}");
        else tgSend($chatId,"❌ Symbol {$symbol} not found in portfolio.");
    } catch(Exception $e){ tgSend($chatId,"❌ ".$e->getMessage()); }
}

function handleAddStock(int $chatId, string $text): void {
    // Format: /stock SYMBOL QTY AVG_COST CURRENCY MARKET
    // Example: /stock NVDA 10 95.50 USD USA
    // Example: /stock NBL 2500 7.50 BDT BD
    $parts = explode(' ', trim($text));
    if (count($parts) < 5) {
        tgSend($chatId,
            "Usage: <code>/stock SYMBOL QTY AVG_COST CURRENCY MARKET</code>\n\n".
            "Examples:\n".
            "<code>/stock NVDA 10 95.50 USD USA</code>\n".
            "<code>/stock NBL 2500 7.50 BDT BD</code>\n".
            "<code>/stock BTC 0.5 45000 USD Crypto</code>\n\n".
            "Markets: BD, USA, UK, Crypto, Other"
        );
        return;
    }
    $symbol   = strtoupper($parts[1]);
    $qty      = (float)$parts[2];
    $avgCost  = (float)$parts[3];
    $currency = strtoupper($parts[4]);
    $market   = ucfirst(strtolower($parts[5] ?? 'BD'));
    if (!in_array($market,['BD','USA','UK','Crypto','Other'])) $market='Other';

    try {
        // Check if exists
        $ex = db()->prepare("SELECT id,company_name FROM portfolio WHERE UPPER(symbol)=?");
        $ex->execute([$symbol]);
        $existing = $ex->fetch();
        if ($existing) {
            db()->prepare("UPDATE portfolio SET quantity=?,avg_cost=?,currency=?,market=?,last_updated=NOW() WHERE UPPER(symbol)=?")
               ->execute([$qty,$avgCost,$currency,$market,$symbol]);
            tgSend($chatId,"✅ Updated <b>{$symbol}</b>: {$qty} @ {$avgCost} {$currency} ({$market})");
        } else {
            db()->prepare("INSERT INTO portfolio (symbol,company_name,market,quantity,avg_cost,currency,current_price) VALUES (?,?,?,?,?,?,?)")
               ->execute([$symbol,$symbol,$market,$qty,$avgCost,$currency,$avgCost]);
            tgSend($chatId,"✅ Added <b>{$symbol}</b>: {$qty} @ {$avgCost} {$currency} ({$market})\nUpdate price with: /price {$symbol} CURRENT_PRICE");
        }
    } catch(Exception $e){ tgSend($chatId,"❌ ".$e->getMessage()); }
}

function sendPortfolioSummary(int $chatId): void {
    try {
        $holdings = db()->query(
            "SELECT p.*, ROUND((p.quantity*p.current_price)-(p.quantity*p.avg_cost),2) as pl,
                    ROUND(((p.current_price-p.avg_cost)/p.avg_cost)*100,2) as pl_pct
             FROM portfolio p ORDER BY p.market, p.symbol"
        )->fetchAll();
    } catch(Exception $e) { tgSend($chatId,"❌ Portfolio table not found."); return; }

    if (!$holdings) { tgSend($chatId,"📊 No portfolio holdings yet.\nAdd via: /stock NVDA 1 100 USD USA"); return; }

    $msg = "💹 <b>Portfolio Summary</b>\n";
    $msg .= date('d M Y H:i')."\n\n";

    $markets = []; $totalCostBHD=0; $totalValBHD=0;
    foreach($holdings as $h) $markets[$h['market']][]=$h;

    foreach($markets as $mkt=>$items) {
        $mktLabel=['BD'=>'🇧🇩','USA'=>'🇺🇸','UK'=>'🇬🇧','Crypto'=>'🪙','Other'=>'🌐'][$mkt]??'';
        $msg .= "\n{$mktLabel} <b>{$mkt}</b>\n";
        $mktCost=0; $mktVal=0;
        foreach($items as $h) {
            $cost  = (float)$h['quantity']*(float)$h['avg_cost'];
            $val   = (float)$h['quantity']*(float)$h['current_price'];
            $pl    = (float)$h['pl'];
            $plPct = (float)$h['pl_pct'];
            $plSign= $pl>=0?'+':'-';
            $plEmoji=$pl>=0?'📈':'📉';
            $msg .= "  <b>{$h['symbol']}</b> {$h['quantity']} @ {$h['avg_cost']} → {$h['current_price']} {$h['currency']}\n";
            $msg .= "  {$plEmoji} P&L: {$plSign}".abs(round($pl,2))." (".abs($plPct)."%)\n";
            $mktCost+=$cost; $mktVal+=$val;
        }
        $mktPL=$mktVal-$mktCost;
        $msg .= "  <i>Market total: ".round($mktVal,2)." | P&L: ".($mktPL>=0?'+':'').round($mktPL,2)."</i>\n";
    }
    $msg .= "\n📌 Update price: <code>/price NVDA 95.50</code>";
    tgSend($chatId, $msg);
}

// ================================================================
function handleCallback(array $cb): void {
    $chatId     = (int)$cb['message']['chat']['id'];
    $msgId      = (int)$cb['message']['message_id'];
    $data       = $cb['data'];
    $callbackId = $cb['id'];

    // --- Authorization check ---
    $callbackUserId = (int)($cb['from']['id'] ?? 0);
    if (!isAuthorized($callbackUserId)) {
        exit; // Silently ignore unauthorized callbacks
    }

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

    if (!$ctx || !isset($ctx['mode'])) {
        tgSend($chatId, "⏰ Session data corrupted. Please re-send your transaction.");
        db()->prepare("UPDATE bot_sessions SET state='idle', context='' WHERE telegram_id=?")
           ->execute([$chatId]);
        return;
    }

    if ($data === 'confirm_yes') {
        $mode = $ctx['mode'] ?? 'single';

        if ($mode === 'multi') {
            // Save all transactions
            $allParsed = $ctx['all_parsed'] ?? [];
            $saved = []; $failed = [];
            foreach ($allParsed as $p) {
                $txnId = saveTransaction($p, $ctx['source'] ?? 'telegram', $ctx['raw'] ?? '');
                if ($txnId) $saved[] = $p;
                else        $failed[] = $p['account'] ?? '?';
            }
            $msg = "✅ <b>Saved " . count($saved) . "/" . count($allParsed) . " transactions!</b>\n\n";
            foreach ($saved as $i => $p) {
                $msg .= ($i+1) . ". " . txnLine($p);
            }
            if ($failed) $msg .= "\n❌ Failed: " . implode(', ', $failed);
            tgSend($chatId, $msg);
        } else {
            // Single transaction
            $txnId = saveTransaction($ctx['parsed'], $ctx['source'] ?? 'telegram', $ctx['raw'] ?? '');
            if ($txnId) {
                $acc = getAccountByName($ctx['parsed']['account'] ?? '');
                $cur = $acc['currency'] ?? '';
                if ($acc && $acc['is_credit_card']) {
                    $ccBal = getCCBalancesBot($acc);
                    $bal   = '-' . number_format($ccBal['total'], 3);
                    $balExtra = "\n📊 Payable: ".number_format($ccBal['payable'],3)." | Outst: ".number_format($ccBal['outstanding'],3)." {$cur}";
                } else {
                    $bal      = $acc ? number_format((float)$acc['balance'], 3) : '—';
                    $balExtra = '';
                }
                tgSend($chatId,
                    "✅ <b>Saved! #{$txnId}</b>\n\n" .
                    txnLine($ctx['parsed']) .
                    "\n💰 New balance: <b>{$bal} {$cur}</b>" . $balExtra
                );
            } else {
                tgSend($chatId, "❌ Could not save — account name not matched. Try /accounts to see exact names.");
            }
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
        case '/portfolio': sendPortfolioSummary($chatId); break;
        case '/today':     sendTodaySummary($chatId);   break;
        case '/monthly':   sendMonthlySummary($chatId); break;
        case '/accounts':  sendAccountsList($chatId);   break;
        case '/rates':     sendRates($chatId);           break;
        case '/report':    sendWeeklyReport($chatId);   break;
        default:
            if (strpos($text, '/price ') === 0) { handlePriceUpdate($chatId, $text); return; }
            if (strpos($text, '/stock ') === 0) { handleAddStock($chatId, $text); return; }
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
