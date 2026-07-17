<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * Monthly Net Worth Snapshot
 * Saves to DB on 1st of each month at 11pm
 * Cron: 0 23 1 * * /usr/local/bin/php /home/sajjadbd/finance.sajjad.bd/cron/monthly_networth_snapshot.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/db.php';
echo "✅ Starting Monthly Snapshot: ".date('Y-m-d H:i:s')."\n";

// Create snapshot table if not exists
db()->exec("CREATE TABLE IF NOT EXISTS networth_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_date DATE NOT NULL,
    bank_cash DECIMAL(20,4) DEFAULT 0,
    portfolio DECIMAL(20,4) DEFAULT 0,
    fixed_assets DECIMAL(20,4) DEFAULT 0,
    liabilities DECIMAL(20,4) DEFAULT 0,
    net_worth DECIMAL(20,4) DEFAULT 0,
    currency VARCHAR(10) DEFAULT 'BHD',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_date (snapshot_date)
)");

// Using toBHD() from api/db.php

// Bank & Cash
$accounts = db()->query("SELECT balance,currency,type FROM accounts WHERE is_active=1")->fetchAll();
$bankCash=0; $liabilities=0;
foreach($accounts as $a){
    $bhd = toBHD((float)$a['balance'],$a['currency']);
    if($a['type']==='liability'||$bhd<0) $liabilities+=$bhd;
    else $bankCash+=$bhd;
}

// Portfolio
$port = db()->query("SELECT currency,SUM(quantity*current_price) as val FROM portfolio WHERE quantity>0 AND current_price>0 GROUP BY currency")->fetchAll();
$portBHD=0;
foreach($port as $p) $portBHD+=toBHD((float)$p['val'],$p['currency']);

// Fixed assets
$fixedBHD=0;
try {
    $fas=db()->query("SELECT currency,SUM(current_value) as val FROM fixed_assets WHERE current_value>0 GROUP BY currency")->fetchAll();
    foreach($fas as $f) $fixedBHD+=toBHD((float)$f['val'],$f['currency']);
} catch(Exception $e){}

$netWorth = $bankCash + $portBHD + $fixedBHD + $liabilities;
$snapDate = date('Y-m-01'); // First day of current month

// Save snapshot
$st=db()->prepare("INSERT INTO networth_history (snapshot_date,bank_cash,portfolio,fixed_assets,liabilities,net_worth) 
    VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE bank_cash=VALUES(bank_cash),portfolio=VALUES(portfolio),
    fixed_assets=VALUES(fixed_assets),liabilities=VALUES(liabilities),net_worth=VALUES(net_worth)");
$st->execute([$snapDate,round($bankCash,4),round($portBHD,4),round($fixedBHD,4),round($liabilities,4),round($netWorth,4)]);

$msg = "📸 Monthly Snapshot Saved\n".date('M Y')."\n\n";
$msg .= "💎 Net Worth: BD ".number_format($netWorth,2)."\n";
$msg .= "🏦 Bank: BD ".number_format($bankCash,2)."\n";
$msg .= "📈 Portfolio: BD ".number_format($portBHD,2)."\n";
if($fixedBHD>0) $msg .= "🏠 Fixed: BD ".number_format($fixedBHD,2)."\n";
$msg .= "💳 Liabilities: BD ".number_format(abs($liabilities),2)."\n";

echo $msg;

// Telegram notification
if(defined('TELEGRAM_BOT_TOKEN')&&defined('YOUR_TELEGRAM_ID')){
    $ch=curl_init("https://api.telegram.org/bot".TELEGRAM_BOT_TOKEN."/sendMessage");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>['chat_id'=>YOUR_TELEGRAM_ID,'text'=>$msg]]);
    curl_exec($ch); curl_close($ch);
}
