<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * USA Stocks + Crypto Price Update
 * Run: Daily at 6pm Bahrain time (10am NYSE = market open)
 * Cron: 0 18 * * * /usr/local/bin/php /home/sajjadbd/finance.sajjad.bd/cron/daily_crypto_prices.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/db.php';

$log=[]; $updated=0;

function httpGetC(string $url): ?string {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,
        CURLOPT_FOLLOWLOCATION=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_USERAGENT=>'Mozilla/5.0']);
    $r=curl_exec($ch); curl_close($ch); return $r?:null;
}

// ── USA Stocks via Yahoo Finance ──────────────────────────
$usaStocks = db()->query("SELECT UPPER(symbol) as sym, exchange FROM portfolio WHERE quantity>0 AND market IN ('USA','UK')")->fetchAll();

foreach($usaStocks as $row){
    $sym = $row['sym'];
    $r = httpGetC("https://query1.finance.yahoo.com/v8/finance/chart/{$sym}?interval=1d&range=1d");
    if($r){
        $d = json_decode($r,true);
        $price = $d['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;
        if($price && $price>0){
            db()->prepare("UPDATE portfolio SET current_price=?,last_updated=NOW() WHERE UPPER(symbol)=?")->execute([$price,$sym]);
            $log[]="✅ NYSE {$sym}: ".round($price,2); $updated++;
        } else { $log[]="❌ NYSE {$sym}: no price"; }
    } else { $log[]="❌ NYSE {$sym}: fetch failed"; }
    sleep(1);
}

// ── Crypto via CoinGecko ──────────────────────────────────
$cryptos = db()->query("SELECT UPPER(symbol) as sym, LOWER(COALESCE(coin_id,symbol)) as coin_id FROM portfolio WHERE quantity>0 AND market='Crypto'")->fetchAll();

if($cryptos){
    $defaultIds = ['BTC'=>'bitcoin','BITCOIN'=>'bitcoin','ETH'=>'ethereum','BNB'=>'binancecoin',
                   'SOL'=>'solana','XRP'=>'ripple','SHIB'=>'shiba-inu','DOGE'=>'dogecoin',
                   'ADA'=>'cardano','PEPE'=>'pepe','MATIC'=>'matic-network'];
    $ids=[]; $symToId=[];
    foreach($cryptos as $c){
        $id = !empty($c['coin_id']) && $c['coin_id']!==strtolower($c['sym']) 
              ? $c['coin_id'] 
              : ($defaultIds[$c['sym']] ?? strtolower($c['sym']));
        $ids[]=$id; $symToId[$id]=$c['sym'];
    }
    $r = httpGetC("https://api.coingecko.com/api/v3/simple/price?ids=".implode(',',$ids)."&vs_currencies=usd");
    if($r){
        $data = json_decode($r,true)??[];
        foreach($data as $coinId=>$px){
            $sym = $symToId[$coinId]??strtoupper($coinId);
            $price = (float)($px['usd']??0);
            if($price>0){
                db()->prepare("UPDATE portfolio SET current_price=?,last_updated=NOW() WHERE UPPER(symbol)=? AND market='Crypto'")->execute([$price,$sym]);
                $log[]="✅ Crypto {$sym}: {$price}"; $updated++;
            }
        }
    } else { $log[]="❌ CoinGecko: fetch failed"; }
}

$msg = "💹 Prices Updated ".date('d M Y H:i')."\n\n".implode("\n",$log)."\n\n✅ {$updated} updated";
echo $msg."\n";

// Telegram notification
if(defined('TELEGRAM_BOT_TOKEN') && defined('YOUR_TELEGRAM_ID')){
    $url="https://api.telegram.org/bot".TELEGRAM_BOT_TOKEN."/sendMessage";
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>['chat_id'=>YOUR_TELEGRAM_ID,'text'=>$msg]]);
    curl_exec($ch); curl_close($ch);
}
