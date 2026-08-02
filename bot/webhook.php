<?php
// ============================================================
// Handle browser verification FIRST — lightweight, no deps
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Bot Status</title>';
    echo '<style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#f5f5f5}.card{background:#fff;border-radius:12px;padding:40px 60px;box-shadow:0 2px 20px rgba(0,0,0,.08);text-align:center}.check{color:#22c55e;font-size:64px;margin-bottom:10px}h1{color:#333;margin:0 0 8px}p{color:#666;margin:0 0 4px;font-size:14px}.badge{display:inline-block;background:#22c55e;color:#fff;padding:4px 16px;border-radius:20px;font-size:13px;margin-top:16px}</style></head>';
    echo '<body><div class="card"><div class="check">✅</div>';
    echo '<h1>Bot Webhook Active</h1>';
    echo '<p>Server: ' . gethostname() . '</p>';
    echo '<p>Time: ' . date('Y-m-d H:i:s') . ' ' . date('T') . '</p>';
    echo '<p>PHP: ' . PHP_VERSION . '</p>';
    echo '<div class="badge">✓ Accepting Telegram updates</div>';
    echo '</div></body></html>';
    exit;
}

// ============================================================
// Only POST requests from Telegram are allowed beyond this point
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(403); exit; }

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
ignore_user_abort(true);
set_time_limit(120);

// Safely manage output buffering
if (ob_get_level() === 0) {
    ob_start();
}
echo '{"ok":true}';
$size = ob_get_length();
if (!headers_sent()) {
    header('Content-Type: application/json');
    if ($size) header('Content-Length: ' . $size);
    header('Connection: close');
}
// Flush safely — close output buffers without warnings
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
if (function_exists('litespeed_finish_request')) litespeed_finish_request();

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
    // Check if message has multiple lines with commands
    $lines = array_filter(array_map('trim', explode("\n", $text)));
    $cmdLines = array_filter($lines, function($l){ return strpos($l, '/') === 0; });

    if (count($cmdLines) > 1) {
        // Multiple commands - process each one
        foreach ($cmdLines as $line) {
            handleCommand($chatId, $line);
        }
    } else {
        handleCommand($chatId, $text);
    }
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
    $amt    = money((float)($t['amount'] ?? 0), ($t['currency'] ?? 'BHD'));
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

    // Send header
    tgSend($chatId, "📋 <b>Found {$count} transactions — confirm each one:</b>");

    // Store ALL transactions in ONE session as JSON array
    $allData = json_encode(['mode'=>'multi','transactions'=>$allParsed,'raw'=>$raw,'source'=>$source]);
    db()->prepare("INSERT INTO bot_sessions (telegram_id,state,context) VALUES (?,'multi',?) ON DUPLICATE KEY UPDATE state='multi',context=VALUES(context)")
       ->execute([$chatId, $allData]);

    // Send each transaction with its own Save/Skip buttons
    foreach ($allParsed as $i => $t) {
        $num  = $i + 1;
        $conf = round(($t['confidence'] ?? 1) * 100);
        $msg  = "{$num}/{$count} " . txnLine($t);
        $msg .= "🎯 Confidence: {$conf}%\n\nSave this?";

        tgSend($chatId, $msg, ['reply_markup' => json_encode(['inline_keyboard' => [[
            ['text' => '✅ Save', 'callback_data' => 'myes_' . $i . '_' . $chatId],
            ['text' => '❌ Skip', 'callback_data' => 'mno_'  . $i . '_' . $chatId],
        ]]])]);
    }
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
            "Usage: <code>/stock SYMBOL QTY AVG_COST CURRENCY EXCHANGE</code>\n\n".
            "Examples:\n".
            "<code>/stock NVDA 10 95.50 USD NYSE</code>\n".
            "<code>/stock EBL 154 25.00 BDT DSE</code>\n".
            "<code>/stock EBL 154 25.00 BDT CSE</code>\n".
            "<code>/stock BTC 0.5 45000 USD Crypto</code>\n\n".
            "Exchanges: DSE, CSE, NYSE, NASDAQ, Crypto"
        );
        return;
    }
    // Format: /stock SYMBOL QTY AVG_COST CURRENCY EXCHANGE
    // Exchange: DSE, CSE, NYSE, Crypto etc
    $symbol   = strtoupper($parts[1]);
    $qty      = (float)$parts[2];
    $avgCost  = (float)$parts[3];
    $currency = strtoupper($parts[4]);
    $exchange = strtoupper($parts[5] ?? 'DSE');

    // Determine market from exchange
    $marketMap = ['DSE'=>'BD','CSE'=>'BD','NYSE'=>'USA','NASDAQ'=>'USA','LSE'=>'UK','CRYPTO'=>'Crypto'];
    $market = $marketMap[$exchange] ?? 'Other';

    try {
        // Check if exists by SYMBOL + EXCHANGE (not just symbol!)
        $ex = db()->prepare("SELECT id,company_name FROM portfolio WHERE UPPER(symbol)=? AND UPPER(exchange)=?");
        $ex->execute([$symbol,$exchange]);
        $existing = $ex->fetch();
        if ($existing) {
            db()->prepare("UPDATE portfolio SET quantity=?,avg_cost=?,currency=?,market=?,last_updated=NOW() WHERE UPPER(symbol)=? AND UPPER(exchange)=?")
               ->execute([$qty,$avgCost,$currency,$market,$symbol,$exchange]);
            tgSend($chatId,"✅ Updated <b>{$symbol}</b> ({$exchange}): {$qty} @ {$avgCost} {$currency}");
        } else {
            // Check if exists without exchange match - warn user
            $ex2 = db()->prepare("SELECT id,exchange FROM portfolio WHERE UPPER(symbol)=?");
            $ex2->execute([$symbol]);
            $others = $ex2->fetchAll();
            if($others){
                $othExch = implode(', ', array_column($others,'exchange'));
                // Insert new entry for this exchange
                db()->prepare("INSERT INTO portfolio (symbol,company_name,market,exchange,quantity,avg_cost,currency,current_price) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$symbol,$symbol,$market,$exchange,$qty,$avgCost,$currency,$avgCost]);
                tgSend($chatId,"✅ Added <b>{$symbol}</b> ({$exchange}): {$qty} @ {$avgCost} {$currency}
⚠️ Note: {$symbol} also exists in {$othExch}");
            } else {
                db()->prepare("INSERT INTO portfolio (symbol,company_name,market,exchange,quantity,avg_cost,currency,current_price) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$symbol,$symbol,$market,$exchange,$qty,$avgCost,$currency,$avgCost]);
                tgSend($chatId,"✅ Added <b>{$symbol}</b> ({$exchange}): {$qty} @ {$avgCost} {$currency}
Update price: /price {$symbol} CURRENT_PRICE {$exchange}");
            }
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
        // Silently ignore unauthorized callbacks
        exit;
    }

    // Acknowledge button press
    $ch = curl_init(TELEGRAM_API . '/answerCallbackQuery');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode(['callback_query_id' => $callbackId]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    curl_exec($ch); curl_close($ch);

    // Handle multi-transaction buttons: myes_INDEX_CHATID or mno_INDEX_CHATID
    if (strpos($data, 'myes_') === 0 || strpos($data, 'mno_') === 0) {
        $parts   = explode('_', $data);
        $action  = $parts[0];
        $idx     = $parts[1] ?? 0;
        $origCid = $parts[2] ?? $chatId;
        $sKey    = $origCid . '_m' . $idx;

        // Load all transactions from main session (stored as array)
        try {
            $st = db()->prepare("SELECT context FROM bot_sessions WHERE telegram_id=? AND state='multi'");
            $st->execute([$chatId]);
            $row = $st->fetch();
        } catch(Exception $e) { $row = null; }

        if (!$row) { tgSend($chatId, "⏰ Session expired. Please resend all transactions."); return; }

        $allCtx = json_decode($row['context'], true);

        if (!$allCtx || !isset($allCtx['transactions'])) {
            tgSend($chatId, "⏰ Session data corrupted. Please re-send all transactions.");
            db()->prepare("UPDATE bot_sessions SET state='idle',context='' WHERE telegram_id=?")
               ->execute([$chatId]);
            return;
        }

        $ctx2   = $allCtx['transactions'][$idx] ?? null;

        if (!$ctx2) { tgSend($chatId, "⏰ Transaction not found."); return; }

        // Prevent duplicate saves - check if already processed
        $processed = $allCtx['processed'] ?? [];
        if (in_array((int)$idx, $processed)) {
            tgSend($chatId, "⚠️ Already processed."); return;
        }
        // Mark as processed immediately
        $processed[] = (int)$idx;
        $allCtx['processed'] = $processed;
        db()->prepare("UPDATE bot_sessions SET context=? WHERE telegram_id=? AND state='multi'")
           ->execute([json_encode($allCtx), $chatId]);

        if ($action === 'myes') {
            $txnId = saveTransaction($ctx2, $allCtx['source'] ?? 'telegram', $allCtx['raw'] ?? '');
            if ($txnId) {
                $acc = getAccountByName($ctx2['account'] ?? '');
                $cur = $acc['currency'] ?? '';
                if ($acc && $acc['is_credit_card']) {
                    $ccBal = getCCBalancesBot($acc);
                    $bal   = '-' . money($ccBal['total'], $cur);
                } else {
                    $bal = $acc ? money((float)$acc['balance'], $cur) : '—';
                }
                $remaining = count($allCtx['transactions']) - count($processed);
                tgSend($chatId, "✅ Saved! #{$txnId}
" . txnLine($ctx2) . "
💰 Balance: <b>{$bal} {$cur}</b>" .
                    ($remaining > 0 ? "

⏳ {$remaining} more to confirm..." : "

🎉 All done!"));
            } else {
                tgSend($chatId, "❌ Could not save — account not matched.");
            }
        } else {
            $remaining = count($allCtx['transactions']) - count($processed);
            tgSend($chatId, "⏭ Skipped." . ($remaining > 0 ? " {$remaining} more..." : " All done! 🎉"));
        }
        // Clear session when all transactions processed
        if (count($processed) >= count($allCtx['transactions'])) {
            db()->prepare("UPDATE bot_sessions SET state='idle',context='' WHERE telegram_id=?")
               ->execute([$chatId]);
        }
        return;
    }

    // Single transaction confirm buttons
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

    // Defensive: context must be an array with 'parsed' and 'mode' keys
    if (!is_array($ctx) || !isset($ctx['parsed']) || !isset($ctx['mode'])) {
        tgSend($chatId, "⏰ Session data corrupted. Please re-send your transaction.");
        db()->prepare("UPDATE bot_sessions SET state='idle', context='' WHERE telegram_id=?")
           ->execute([$chatId]);
        return;
    }

    // Also guard against null/invalid parsed data
    if (!is_array($ctx['parsed']) || empty($ctx['parsed'])) {
        tgSend($chatId, "⏰ Session data corrupted (empty transaction). Please re-send.");
        db()->prepare("UPDATE bot_sessions SET state='idle', context='' WHERE telegram_id=?")
           ->execute([$chatId]);
        return;
    }

    // Clear session IMMEDIATELY to prevent duplicate saves on re-tap
    db()->prepare("UPDATE bot_sessions SET state='idle', context='' WHERE telegram_id=?")
       ->execute([$chatId]);

    if ($data === 'confirm_yes') {
        $txnId = saveTransaction($ctx['parsed'], $ctx['source'] ?? 'telegram', $ctx['raw'] ?? '');
        if ($txnId) {
            $acc = getAccountByName($ctx['parsed']['account'] ?? '');
            $cur = $acc['currency'] ?? '';
            if ($acc && $acc['is_credit_card']) {
                $ccBal = getCCBalancesBot($acc);
                $bal   = '-' . money($ccBal['total'], $cur);
                $balExtra = "
📊 Payable: ".money($ccBal['payable'], $cur)." | Outst: ".money($ccBal['outstanding'], $cur)." {$cur}";
            } else {
                $bal      = $acc ? money((float)$acc['balance'], $cur) : '—';
                $balExtra = '';
            }
            tgSend($chatId,
                "✅ <b>Saved! #{$txnId}</b>

" .
                txnLine($ctx['parsed']) .
                "
💰 New balance: <b>{$bal} {$cur}</b>" . $balExtra
            );
        } else {
            tgSend($chatId, "❌ Could not save — account name not matched. Try /accounts to see exact names.");
        }
    } else {
        tgSend($chatId, "❌ Cancelled.");
    }

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
        case '/balance':
            // Support /balance BBK or /balance cash etc
            $balSearch = trim(substr($text, 8)); // text after '/balance '
            sendBalanceSummary($chatId, $balSearch);
            break;
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
function sendBalanceSummary(int $chatId, string $search = ''): void {
    $accounts = db()->query(
        "SELECT a.*, COALESCE(a.is_credit_card,0) as is_cc,
                a.payable_balance, a.outstanding_balance, a.bill_date
         FROM accounts a WHERE a.is_active=1 ORDER BY a.type DESC, a.group_name, a.name"
    )->fetchAll();

    // Filter if search term given
    if ($search) {
        $s = strtolower($search);
        $accounts = array_filter($accounts, function($a) use ($s) {
            return strpos(strtolower($a['name']), $s) !== false
                || strpos(strtolower($a['group_name']), $s) !== false;
        });
        $accounts = array_values($accounts);

        if (empty($accounts)) {
            tgSend($chatId, "❌ No accounts found matching \"" . htmlspecialchars($search) . "\".\nTry /accounts to see all account names.");
            return;
        }

        // Show matched accounts only
        $msg = "💰 <b>Balance: " . htmlspecialchars($search) . "</b> — " . date('d M Y') . "\n\n";
        foreach ($accounts as $a) {
            if ($a['is_cc']) {
                $ccB = getCCBalancesBot($a);
                $bal = '-' . money($ccB['total'], $a['currency']);
                $color = '🔴';
                $extra = "\n    P: " . money($ccB['payable'], $a['currency']) . " | O: " . money($ccB['outstanding'], $a['currency']);
            } else {
                $bal   = money((float)$a['balance'], $a['currency']);
                $color = (float)$a['balance'] < 0 ? '🔴' : '🔵';
                $extra = '';
            }
            $bhd = $a['currency'] !== 'BHD' ? ' (≈BD ' . money(toBHD(abs((float)$a['balance']), $a['currency'])) . ')' : '';
            $msg .= "{$color} <b>{$a['name']}</b>\n    {$bal} {$a['currency']}{$bhd}{$extra}\n\n";
        }
        tgSend($chatId, $msg);
        return;
    }

    // Show all accounts grouped
    $totalAssets = 0; $totalLiab = 0; $groups = [];
    foreach ($accounts as $a) {
        $g = $a['group_name'] ?: $a['name'];
        $groups[$g][] = $a;
        if ($a['is_cc']) {
            $ccB = getCCBalancesBot($a);
            $totalLiab -= toBHD($ccB['total'], $a['currency']);
        } elseif ($a['type'] === 'asset') {
            $totalAssets += toBHD((float)$a['balance'], $a['currency']);
        } else {
            $totalLiab += toBHD((float)$a['balance'], $a['currency']);
        }
    }

    $msg = "💰 <b>Account Balances</b> — " . date('d M Y') . "\n\n";
    foreach ($groups as $gname => $accs) {
        $msg .= "<b>{$gname}</b>\n";
        foreach ($accs as $a) {
            if ($a['is_cc']) {
                $ccB  = getCCBalancesBot($a);
                $bal  = '-' . money($ccB['total'], $a['currency']);
                $color= '🔴';
            } else {
                $bal  = money((float)$a['balance'], $a['currency']);
                $color= (float)$a['balance'] < 0 ? '🔴' : '🔵';
            }
            $msg .= "  {$color} {$a['name']}: {$bal} {$a['currency']}\n";
        }
        $msg .= "\n";
    }
    $net = money($totalAssets + $totalLiab);
    $msg .= "━━━━━━━━━━\n💎 <b>Net Worth: BD {$net}</b>\n";
    $msg .= "\n💡 Tip: /balance BBK to check specific account";
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
        $amt   = money((float)$r['amount'], $r['currency']);
        $msg  .= "{$emoji} {$amt} {$r['currency']} {$r['category']} ({$r['acc_name']})\n";
        if ($r['type']==='expense') $exp += toBHD((float)$r['amount'], $r['currency']);
        if ($r['type']==='income')  $inc += toBHD((float)$r['amount'], $r['currency']);
    }
    $msg .= "\n🔴 Spent: BD " . money($exp);
    $msg .= "\n🟢 Earned: BD " . money($inc);
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
    $msg .= "🟢 Income:  BD " . money($inc) . "\n";
    $msg .= "🔴 Expense: BD " . money($exp) . "\n";
    $msg .= "💰 Saved:   BD " . money($inc-$exp) . "\n\n";
    if ($cats) {
        $msg .= "<b>By Category:</b>\n";
        foreach (array_slice($cats,0,8) as $c) {
            $pct = $exp>0 ? round($c['total']/$exp*100) : 0;
            $msg .= "  • {$c['category']}: BD " . money($c['total']) . " ({$pct}%)\n";
        }
    }
    tgSend($chatId, $msg);
}

function sendAccountsList(int $chatId): void {
    $rows = db()->query("SELECT name, balance, currency FROM accounts WHERE is_active=1 ORDER BY name")->fetchAll();
    $msg  = "🏦 <b>All Accounts</b>\n\n";
    foreach ($rows as $a) {
        $bal  = money((float)$a['balance'], $a['currency']);
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
    $msg .= "🟢 Income:  BD " . money($inc) . "\n";
    $msg .= "🔴 Expense: BD " . money($exp) . "\n";
    $msg .= "💰 Net:     BD " . money($inc-$exp);
    tgSend($chatId, $msg);
}
