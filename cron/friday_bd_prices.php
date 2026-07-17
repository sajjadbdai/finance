<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * BD Stock Prices Update (DSE/CSE)
 * Run: Friday 11:30pm Bahrain time (after Thursday market close)
 * Cron: 30 23 * * 5 /usr/local/bin/php /home/sajjadbd/finance.sajjad.bd/cron/friday_bd_prices.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/db.php';

$log = []; $updated = 0;

function gCallCron(string $prompt): ?string {
    if (!defined('GEMINI_API_KEY') || !GEMINI_API_KEY) return null;
    foreach (['gemini-2.5-flash','gemini-2.5-flash-lite'] as $m) {
        $payload = [
            'contents'         => [['parts'=>[['text'=>$prompt]]]],
            'tools'            => [['google_search'=>new stdClass()]],
            'generationConfig' => ['temperature'=>0,'maxOutputTokens'=>1500],
        ];
        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$m}:generateContent?key=".GEMINI_API_KEY);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>json_encode($payload),
            CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>false]);
        $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if($code===200&&$resp){
            $d=json_decode($resp,true); $t='';
            foreach(($d['candidates'][0]['content']['parts']??[]) as $p) if(isset($p['text'])) $t.=$p['text'];
            if($t) return $t;
        }
        if($code===429) sleep(5); else sleep(2);
    }
    return null;
}

function parseSymPrices(string $text): array {
    $p=[];
    // Try SYMBOL=price format
    preg_match_all('/([A-Z][A-Z0-9]{1,11})\s*=\s*([\d]+\.?[\d]*)/i',$text,$m);
    foreach($m[1] as $i=>$s){ $v=(float)$m[2][$i]; if($v>0&&$v<500000) $p[strtoupper(trim($s))]=$v; }
    if($p) return $p;
    // Try JSON
    if(preg_match('/\{.*\}/s',$text,$jm)){
        $j=json_decode($jm[0],true);
        if(is_array($j)) foreach($j as $k=>$v) if(is_numeric($v)&&(float)$v>0) $p[strtoupper(trim($k))]=(float)$v;
    }
    return $p;
}

// Get all BD stocks from portfolio
$rows = db()->query("SELECT UPPER(symbol) as sym, exchange FROM portfolio WHERE quantity>0 AND market='BD' ORDER BY exchange")->fetchAll();

if(!$rows){ echo "No BD stocks found\n"; exit; }

// Group by exchange
$dse=[]; $cse=[];
foreach($rows as $r){
    if($r['exchange']==='CSE') $cse[]=$r['sym'];
    else $dse[]=$r['sym'];
}

// Fetch DSE in chunks of 2
$chunks = array_chunk(array_unique($dse), 2);
foreach($chunks as $i=>$chunk){
    if($i>0) sleep(3);
    $syms = implode(' and ', $chunk);
    $text = gCallCron("What is the current LTP of {$syms} on Dhaka Stock Exchange (DSE) Bangladesh? Reply ONLY: SYMBOL=price");
    if($text){
        $prices = parseSymPrices($text);
        foreach($chunk as $sym){
            if(isset($prices[$sym]) && $prices[$sym]>0){
                db()->prepare("UPDATE portfolio SET current_price=?,last_updated=NOW() WHERE UPPER(symbol)=? AND exchange='DSE'")->execute([$prices[$sym],$sym]);
                $log[]="✅ DSE {$sym}: {$prices[$sym]}"; $updated++;
            } else { $log[]="❌ DSE {$sym}: not found"; }
        }
    } else { foreach($chunk as $s) $log[]="❌ DSE {$s}: Gemini failed"; }
}

// Fetch CSE in chunks of 2
$chunks = array_chunk(array_unique($cse), 2);
foreach($chunks as $i=>$chunk){
    if($i>0) sleep(3);
    $syms = implode(' and ', $chunk);
    $text = gCallCron("What is the current LTP of {$syms} on Chittagong Stock Exchange (CSE) Bangladesh? Reply ONLY: SYMBOL=price");
    if($text){
        $prices = parseSymPrices($text);
        foreach($chunk as $sym){
            if(isset($prices[$sym]) && $prices[$sym]>0){
                db()->prepare("UPDATE portfolio SET current_price=?,last_updated=NOW() WHERE UPPER(symbol)=? AND exchange='CSE'")->execute([$prices[$sym],$sym]);
                $log[]="✅ CSE {$sym}: {$prices[$sym]}"; $updated++;
            } else { $log[]="❌ CSE {$sym}: not found"; }
        }
    } else { foreach($chunk as $s) $log[]="❌ CSE {$s}: Gemini failed"; }
}

$msg = "📈 BD Prices Updated ".date('d M Y H:i')."\n\n".implode("\n",$log);
echo $msg."\n";

// Send Telegram notification
if(defined('TELEGRAM_BOT_TOKEN') && defined('YOUR_TELEGRAM_ID')){
    $url = "https://api.telegram.org/bot".TELEGRAM_BOT_TOKEN."/sendMessage";
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>['chat_id'=>YOUR_TELEGRAM_ID,'text'=>$msg]]);
    curl_exec($ch); curl_close($ch);
}
