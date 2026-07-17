<?php
ini_set('display_errors','0'); error_reporting(0); ob_start();
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
ob_clean();
if (!isset($_SESSION['auth'])) { ob_end_clean(); header('Content-Type: application/json'); echo '{"results":[],"errors":["Unauthorized"],"count":0}'; exit; }

set_time_limit(60); // 60 seconds max
$results=[]; $errors=[];
$type = $_GET['type'] ?? 'fast'; // fast=USA+Crypto, slow=BD stocks

function hGet(string $url): ?string {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
        CURLOPT_FOLLOWLOCATION=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_USERAGENT=>'Mozilla/5.0']);
    $r=curl_exec($ch); curl_close($ch); return $r?:null;
}

function gCall(string $prompt): ?string {
    if (!defined('GEMINI_API_KEY')||!GEMINI_API_KEY) return null;
    foreach (['gemini-2.5-flash','gemini-3.5-flash'] as $m) {
        $ch=curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$m}:generateContent?key=".GEMINI_API_KEY);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>json_encode(['contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['temperature'=>0,'maxOutputTokens'=>300],'tools'=>[['google_search'=>new stdClass()]]]),
            CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>false]);
        $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code===200&&$resp) {
            $d=json_decode($resp,true); $t='';
            foreach(($d['candidates'][0]['content']['parts']??[]) as $p) if(isset($p['text'])) $t.=$p['text'];
            if ($t) return $t;
        }
        if ($code===429) sleep(3); else sleep(1);
    }
    return null;
}

function pNum(string $t): float {
    preg_match_all('/([\d,]+\.?\d*)/',strip_tags($t),$m);
    foreach($m[1] as $n){ $v=(float)str_replace(',','',$n); if($v>0&&$v<500000) return $v; }
    return 0;
}

function pBatch(string $t): array {
    $p=[]; $t=preg_replace('/```(?:json)?\s*/i','',$t); $t=preg_replace('/```/','',$t);
    if(preg_match('/\{[^{}]+\}/s',$t,$m)){ $j=json_decode($m[0],true); if(is_array($j)) foreach($j as $k=>$v) if(is_numeric($v)&&(float)$v>0) $p[strtoupper(trim($k))]=(float)$v; }
    if($p) return $p;
    preg_match_all('/([A-Z][A-Z0-9]{1,11})\s*=\s*([\d]+\.?\d*)/i',$t,$m2);
    foreach($m2[1] as $i=>$s) if((float)$m2[2][$i]>0) $p[strtoupper(trim($s))]=(float)$m2[2][$i];
    return $p;
}

$rows=db()->query("SELECT symbol,market,exchange,currency,current_price,coin_id FROM portfolio WHERE quantity>0")->fetchAll();
$pMap=[]; $usa=[]; $crypto=[]; $dse=[]; $cse=[];
foreach($rows as $h){
    $sym=strtoupper($h['symbol']); $exch=strtoupper($h['exchange']??''); $mkt=$h['market']??'';
    $pMap["{$sym}|{$exch}"]=(float)$h['current_price']; $pMap[$sym]=(float)$h['current_price'];
    if($mkt==='USA'||$mkt==='UK') $usa[]=$sym;
    elseif($mkt==='Crypto') $crypto[]=$sym;
    elseif($exch==='CSE') $cse[]=$sym;
    else $dse[]=$sym;
}

if ($type==='list') {
    // Return all stocks - same symbol can appear in multiple exchanges (e.g. EBL in DSE and CSE)
    $stocks = [];
    foreach($rows as $h){
        $sym  = strtoupper($h['symbol']);
        $exch = strtoupper($h['exchange']??'');
        $mkt  = $h['market']??'';
        $stocks[]=['symbol'=>$sym,'exchange'=>$exch,'market'=>$mkt,
            'currency'=>$h['currency']??'BHD','old_price'=>(float)$h['current_price']];
    }
    ob_end_clean(); header('Content-Type: application/json');
    echo json_encode(['stocks'=>$stocks,'count'=>count($stocks)]); exit;
}

if ($type==='one') {
    // Fetch price for ONE stock
    $sym  = strtoupper($_GET['sym']  ?? '');
    $exch = strtoupper($_GET['exch'] ?? '');
    $mkt  = $_GET['mkt'] ?? '';
    $op   = $pMap["{$sym}|{$exch}"] ?? $pMap[$sym] ?? 0;

    if (!$sym) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['results'=>[],'errors'=>['No symbol']]); exit; }

    $np = null;

    if ($mkt==='USA'||$mkt==='UK') {
        // Yahoo Finance
        $r=hGet("https://query1.finance.yahoo.com/v8/finance/chart/{$sym}?interval=1d&range=1d");
        if($r){ $d=json_decode($r,true); $np=$d['chart']['result'][0]['meta']['regularMarketPrice']??null; }
        if($np) $np=round((float)$np,4);
        $currency='USD';
    } elseif($mkt==='Crypto') {
        // CoinGecko
        $cm=['BTC'=>'bitcoin','BITCOIN'=>'bitcoin','ETH'=>'ethereum','BNB'=>'binancecoin',
             'SOL'=>'solana','XRP'=>'ripple','SHIB'=>'shiba-inu','DOGE'=>'dogecoin','ADA'=>'cardano','PEPE'=>'pepe'];
        foreach($rows as $h) if(strtoupper($h['symbol'])===$sym&&!empty($h['coin_id'])) $cm[$sym]=strtolower($h['coin_id']);
        $id=$cm[$sym]??strtolower($sym);
        $r=hGet("https://api.coingecko.com/api/v3/simple/price?ids={$id}&vs_currencies=usd");
        if($r){ $d=json_decode($r,true); $np=(float)($d[$id]['usd']??0); if($np==0) $np=null; }
        $currency='USD';
    } else {
        // BD stocks via Gemini
        // Use cached batch prices if available, else fetch all at once
        static $dseCache = null;
        static $cseCache = null;

        if ($exch==='CSE') {
            if ($cseCache === null) {
                // Get ALL CSE stocks at once
                $allCse = [];
                foreach($rows as $rr) if(strtoupper($rr['exchange']??'')==='CSE') $allCse[]=strtoupper($rr['symbol']);
                $allCse = array_unique($allCse);
                $symsStr = implode(', ', $allCse);
                $t = gCall("Current LTP prices on CSE Chittagong Stock Exchange Bangladesh for these stocks: {$symsStr}\nReturn ONLY JSON like: {\"NBL\":4.00,\"ROBI\":29.00}");
                $cseCache = $t ? pBatch($t) : [];
            }
            $np = $cseCache[$sym] ?? null;
        } else {
            if ($dseCache === null) {
                // Get DSE stocks in two batches (avoid Gemini truncation)
                $allDse = [];
                foreach($rows as $rr) { $rx=strtoupper($rr['exchange']??''); $mx=$rr['market']??''; if($mx!=='USA'&&$mx!=='Crypto'&&$rx!=='CSE') $allDse[]=strtoupper($rr['symbol']); }
                $allDse = array_values(array_unique($allDse));
                $dseCache = [];

                // Batch 1: first half
                $batch1 = array_slice($allDse, 0, 4);
                $t1 = gCall("Current LTP prices on DSE Dhaka Stock Exchange Bangladesh: ".implode(', ', $batch1)."\nReturn ONLY JSON: {\"SYMBOL\":price}");
                if ($t1) $dseCache = array_merge($dseCache, pBatch($t1));
                sleep(2);

                // Batch 2: second half
                $batch2 = array_slice($allDse, 4);
                if ($batch2) {
                    $t2 = gCall("Current LTP prices on DSE Dhaka Stock Exchange Bangladesh: ".implode(', ', $batch2)."\nReturn ONLY JSON: {\"SYMBOL\":price}");
                    if ($t2) $dseCache = array_merge($dseCache, pBatch($t2));
                }
            }
            $np = $dseCache[$sym] ?? null;
        }
        if($np===0.0||$np===0) $np=null;
        // Sanity check
        if($np && $op>0 && abs($np-$op)/$op>0.7){ $errors[]="[{$exch}] {$sym}: got {$np} vs expected ~{$op}, skipped"; $np=null; }
        $currency='BDT';
    }

    if($np&&$np>0){
        $results[]=['symbol'=>$sym,'exchange'=>$exch,'currency'=>$currency,
            'old_price'=>$op,'new_price'=>$np,
            'change'=>round($np-$op,6),'change_pct'=>$op>0?round(($np-$op)/$op*100,4):0];
    } else {
        $errors[]="[{$exch}] {$sym}: not found";
    }
}

ob_end_clean();
header('Content-Type: application/json');
echo json_encode(['results'=>$results,'errors'=>$errors,'count'=>count($results)]);
