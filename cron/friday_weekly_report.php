<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * Weekly Telegram Report - Every Friday morning
 * Cron: 0 8 * * 5 /usr/local/bin/php /home/sajjadbd/finance.sajjad.bd/cron/friday_weekly_report.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/db.php';
echo "✅ Starting Weekly Report: ".date('Y-m-d H:i:s')."\n";

if(!defined('TELEGRAM_BOT_TOKEN')||!defined('YOUR_TELEGRAM_ID')) exit;

// Exchange rates
// Using toBHD() from api/db.php

// ── Net Worth ─────────────────────────────────────────────
$accounts = db()->query("SELECT balance,currency,type,group_name FROM accounts WHERE is_active=1")->fetchAll();
$totalAssets=0; $totalLiab=0;
foreach($accounts as $a){
    $bhd = toBHD((float)$a['balance'],$a['currency']);
    if($a['type']==='liability' || ($bhd<0 && !isset($a['is_credit_card']))) $totalLiab+=$bhd;
    elseif($a['is_credit_card']&&$bhd<0) $totalLiab+=$bhd;
    else $totalAssets+=$bhd;
}

// Portfolio value
$port = db()->query("SELECT currency,SUM(quantity*current_price) as val FROM portfolio WHERE quantity>0 AND current_price>0 GROUP BY currency")->fetchAll();
$portBHD=0;
foreach($port as $p) $portBHD+=toBHD((float)$p['val'],$p['currency']);

// Fixed assets
$fixedBHD=0;
try {
    $fas=db()->query("SELECT currency,SUM(current_value) as val FROM fixed_assets WHERE current_value>0 GROUP BY currency")->fetchAll();
    foreach($fas as $f) $fixedBHD+=toBHD((float)$f['val'],$f['currency']);
} catch(Exception $e){}

$netWorth = $totalAssets + $portBHD + $fixedBHD + $totalLiab;

// ── This week income/expense ──────────────────────────────
$weekStart = date('Y-m-d',strtotime('last saturday'));
$weekData  = db()->prepare("SELECT type,SUM(amount_bhd) as total FROM transactions WHERE txn_date>=? AND type IN ('income','expense') GROUP BY type");
$weekData->execute([$weekStart]);
$weekIncome=0; $weekExpense=0;
foreach($weekData->fetchAll() as $r){
    if($r['type']==='income') $weekIncome=(float)$r['total'];
    else $weekExpense=(float)$r['total'];
}

// ── CC dues this month ────────────────────────────────────
$ccDue = db()->query("SELECT name,balance FROM accounts WHERE is_credit_card=1 AND balance<0 AND is_active=1")->fetchAll();
$ccTotal=0; $ccLines=[];
foreach($ccDue as $cc){
    $amt=abs((float)$cc['balance']); $ccTotal+=$amt;
    $ccLines[]="  • {$cc['name']}: BD ".number_format($amt,2);
}

// ── Upcoming scheduled payments ───────────────────────────
$upcomingQ=db()->prepare("SELECT name,amount,currency,next_run FROM scheduled_payments WHERE is_active=1 AND next_run<=? ORDER BY next_run LIMIT 5");
$upcomingQ->execute([date('Y-m-d',strtotime('+7 days'))]);
$upcoming=$upcomingQ->fetchAll();

// ── Portfolio top movers ──────────────────────────────────
$movers=db()->query("SELECT symbol,exchange,((current_price-avg_cost)/avg_cost*100) as pct FROM portfolio WHERE quantity>0 AND avg_cost>0 ORDER BY pct DESC LIMIT 3")->fetchAll();

// ── Build message ─────────────────────────────────────────
$msg  = "📊 *Weekly Finance Report*\n";
$msg .= date('d M Y')."\n";
$msg .= str_repeat("─",28)."\n\n";

$msg .= "💎 *Total Wealth*\n";
$msg .= "BD ".number_format($netWorth,2)."\n";
$msg .= "  🏦 Bank & Cash: BD ".number_format($totalAssets,2)."\n";
$msg .= "  📈 Portfolio:   BD ".number_format($portBHD,2)."\n";
if($fixedBHD>0) $msg .= "  🏠 Fixed Assets: BD ".number_format($fixedBHD,2)."\n";
if($totalLiab<0) $msg .= "  💳 Liabilities: BD ".number_format(abs($totalLiab),2)."\n";

$msg .= "\n📅 *This Week*\n";
$msg .= "  ✅ Income:  BD ".number_format($weekIncome,2)."\n";
$msg .= "  ❌ Expense: BD ".number_format($weekExpense,2)."\n";
$msg .= "  💰 Net:     BD ".number_format($weekIncome-$weekExpense,2)."\n";

if($ccLines){
    $msg .= "\n💳 *CC Outstanding*\n";
    $msg .= implode("\n",$ccLines)."\n";
    $msg .= "  Total: BD ".number_format($ccTotal,2)."\n";
}

if($upcoming){
    $msg .= "\n⏰ *Upcoming Payments (7 days)*\n";
    foreach($upcoming as $u)
        $msg .= "  • {$u['name']}: ".number_format($u['amount'],0)." {$u['currency']} on ".date('d M',strtotime($u['next_run']))."\n";
}

if($movers){
    $msg .= "\n📈 *Portfolio Top Movers*\n";
    foreach($movers as $mv)
        $msg .= "  • {$mv['symbol']} ({$mv['exchange']}): ".($mv['pct']>=0?'+':'').round($mv['pct'],1)."%\n";
}

$msg .= "\n_Sajjad Finance_ · ".date('H:i');

// Send
$ch=curl_init("https://api.telegram.org/bot".TELEGRAM_BOT_TOKEN."/sendMessage");
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>['chat_id'=>YOUR_TELEGRAM_ID,'text'=>$msg,'parse_mode'=>'Markdown']]);
$r=curl_exec($ch); curl_close($ch);
echo "Report sent!\n".$msg."\n";
