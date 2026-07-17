<?php
ini_set('display_errors','0'); error_reporting(0); ob_start();
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
ob_clean();
if (!isset($_SESSION['auth'])) {
    ob_end_clean(); header('Content-Type: application/json');
    echo '{"results":[],"errors":["Unauthorized"],"count":0}'; exit;
}

set_time_limit(60);
$results=[]; $errors=[];
$type = $_GET['type'] ?? 'fast';

function hGet(string $url): ?string {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
        CURLOPT_FOLLOWLOCATION=>true,CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_USERAGENT=>'Mozilla/5.0']);
    $r=curl_exec($ch); curl_close($ch); return $r?:null;
}

function gCall(string $prompt): ?string {
    if (!defined('GEMINI_API_KEY')||!GEMINI_API_KEY) return null;
    // gemini-2.5-flash-lite: 1000/day free | gemini-2.5-flash: 1500/day free
    foreach (['gemini-2.5-flash-lite','gemini-2.5-flash'] as $m) {
        $payload = [
            'contents' => [['parts'=>[['text'=>$prompt]]]],
            'tools'    => [['google_search'=>new stdClass()]],
            'generationConfig' => ['temperature'=>0,'maxOutputTokens'=>1500],
        ];
        $ch=curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$m}:generateContent?key=".GEMINI_API_KEY);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>json_encode($payload),
            CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>false]);
        $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code===200&&$resp) {
            $d=json_decode($resp,true); $t='';
            foreach(($d['candidates'][0]['content']['parts']??[]) as $p)
                if(isset($p['text'])) $t.=$p['text'];
            if ($t) return $t;
        }
        if ($code===429) sleep(4); else sleep(1);
    }
    return null;
}

function pBatch(string $t, array $symbols=[]): array {
    $p = [];

    // Method 1: Direct JSON decode (works when responseSchema is honoured)
    $j = json_decode(trim($t), true);
    if (is_array($j)) {
        if (!empty($j[0]['symbol'])) {
            foreach ($j as $item)
                if (!empty($item['symbol']) && (float)($item['price']??0) > 0)
                    $p[strtoupper(trim($item['symbol']))] = (float)$item['price'];
            if ($p) return $p;
        }
        foreach ($j as $k=>$v)
            if (is_string($k) && is_numeric($v) && (float)$v > 0)
                $p[strtoupper(trim($k))] = (float)$v;
        if ($p) return $p;
    }

    // Method 2: SYMBOL=price or "SYMBOL": price patterns
    $t2 = preg_replace('/```(?:json)?\s*/i','',$t);
    $t2 = preg_replace('/```/','',$t2);
    preg_match_all('/([A-Z][A-Z0-9]{1,11})\s*[=:]\s*([\d]+\.?[\d]*)/i',$t2,$m2);
    foreach($m2[1] as $i=>$s) {
        $v=(float)$m2[2][$i];
        if($v>0&&$v<500000) $p[strtoupper(trim($s))]=$v;
    }
    if ($p) return $p;

    // Method 3: Natural language fallback — scan for each symbol near a price
    // e.g. "BRACBANK: The latest LTP ... is 67.9 BDT"
    // e.g. "* **CITYBANK:** 30.70 BDT"
    $targets = !empty($symbols) ? $symbols : [];
    if (empty($targets)) {
        // Extract all-caps words as potential symbols
        preg_match_all('/([A-Z][A-Z0-9]{2,11})/', $t, $symMatches);
        $targets = array_unique($symMatches[1]);
    }
    foreach ($targets as $sym) {
        $sym = strtoupper(trim($sym));
        // Find the symbol in text then grab first number after it within ~150 chars
        $pos = stripos($t, $sym);
        if ($pos !== false) {
            $snippet = substr($t, $pos, 150);
            if (preg_match('/([\d]+\.[\d]+|[\d]+)/', $snippet, $nm)) {
                $v = (float)$nm[1];
                if ($v > 0 && $v < 500000) $p[$sym] = $v;
            }
        }
    }
    return $p;
}

function pNum(string $t): float {
    preg_match_all('/([\d,]+\.?\d*)/',strip_tags($t),$m);
    foreach($m[1] as $n){ $v=(float)str_replace(',','',$n); if($v>0&&$v<500000) return $v; }
    return 0;
}

// Load holdings
try {
    $rows=db()->query("SELECT symbol,market,exchange,currency,current_price,coin_id FROM portfolio WHERE quantity>0 ORDER BY market,symbol")->fetchAll();
} catch(Exception $e) {
    ob_end_clean(); header('Content-Type: application/json');
    echo json_encode(['results'=>[],'errors'=>['DB: '.$e->getMessage()],'count'=>0]); exit;
}

$pMap=[]; $usa=[]; $crypto=[]; $dse=[]; $cse=[];
foreach($rows as $h){
    $sym=strtoupper($h['symbol']); $exch=strtoupper($h['exchange']??''); $mkt=$h['market']??'';
    $pMap["{$sym}|{$exch}"]=(float)$h['current_price']; $pMap[$sym]=(float)$h['current_price'];
    if($mkt==='USA'||$mkt==='UK') $usa[]=$sym;
    elseif($mkt==='Crypto') $crypto[]=$sym;
    elseif($exch==='CSE') $cse[]=$sym;
    else $dse[]=$sym;
}

// ── TYPE: list — return all stocks instantly ──────────────
if ($type==='list') {
    $stocks=[];
    foreach($rows as $h){
        $stocks[]=[
            'symbol'    => strtoupper($h['symbol']),
            'exchange'  => strtoupper($h['exchange']??''),
            'market'    => $h['market']??'',
            'currency'  => $h['currency']??'BHD',
            'old_price' => (float)$h['current_price'],
        ];
    }
    ob_end_clean(); header('Content-Type: application/json');
    echo json_encode(['stocks'=>$stocks,'count'=>count($stocks)]); exit;
}

// ── TYPE: fast — USA + Crypto only ───────────────────────
if ($type==='fast') {
    // USA via Yahoo Finance
    foreach(array_unique($usa) as $sym){
        $r=hGet("https://query1.finance.yahoo.com/v8/finance/chart/{$sym}?interval=1d&range=1d");
        $np=null; if($r){ $d=json_decode($r,true); $np=$d['chart']['result'][0]['meta']['regularMarketPrice']??null; }
        $op=$pMap["{$sym}|NYSE"]??$pMap[$sym]??0;
        if($np){ $np=round((float)$np,4); $results[]=['symbol'=>$sym,'exchange'=>'NYSE','currency'=>'USD','old_price'=>$op,'new_price'=>$np,'change'=>round($np-$op,4),'change_pct'=>$op>0?round(($np-$op)/$op*100,2):0]; }
        else $errors[]="[NYSE] {$sym}: not found";
        sleep(1);
    }
    // Crypto via CoinGecko
    if($crypto){
        $cm=['BTC'=>'bitcoin','BITCOIN'=>'bitcoin','ETH'=>'ethereum','BNB'=>'binancecoin',
             'SOL'=>'solana','XRP'=>'ripple','SHIB'=>'shiba-inu','DOGE'=>'dogecoin',
             'ADA'=>'cardano','PEPE'=>'pepe','MATIC'=>'matic-network'];
        foreach($rows as $h) if(strtoupper($h['market']??'')==='CRYPTO'&&!empty($h['coin_id'])) $cm[strtoupper($h['symbol'])]=strtolower($h['coin_id']);
        $ids=[]; $sid=[];
        foreach(array_unique($crypto) as $sym){ $id=$cm[$sym]??strtolower($sym); $ids[]=$id; $sid[$id]=$sym; }
        $r=hGet("https://api.coingecko.com/api/v3/simple/price?ids=".implode(',',$ids)."&vs_currencies=usd");
        if($r){ $d=json_decode($r,true)??[]; $found=[];
            foreach($d as $cid=>$px){ $sym=$sid[$cid]??strtoupper($cid); $raw=(float)($px['usd']??0); $op=$pMap["{$sym}|Crypto"]??$pMap[$sym]??0;
                if($raw>0){ $results[]=['symbol'=>$sym,'exchange'=>'Crypto','currency'=>'USD','old_price'=>$op,'new_price'=>$raw,'change'=>$raw-$op,'change_pct'=>$op>0?round(($raw-$op)/$op*100,4):0]; $found[]=$sym; }
            }
            foreach(array_unique($crypto) as $sym) if(!in_array($sym,$found)) $errors[]="[Crypto] {$sym}: not found";
        } else foreach($crypto as $s) $errors[]="[Crypto] {$s}: CoinGecko failed";
    }
}

// ── TYPE: one — single BD stock with SESSION cache ───────
if ($type==='one') {
    $sym  = strtoupper($_GET['sym']  ?? '');
    $exch = strtoupper($_GET['exch'] ?? '');
    $mkt  = $_GET['mkt'] ?? '';

    if (!$sym) { ob_end_clean(); header('Content-Type: application/json'); echo '{"results":[],"errors":["No symbol"]}'; exit; }

    // USA/Crypto handled separately via type=fast
    if ($mkt==='USA'||$mkt==='UK') {
        $r=hGet("https://query1.finance.yahoo.com/v8/finance/chart/{$sym}?interval=1d&range=1d");
        $np=null; if($r){ $d=json_decode($r,true); $np=$d['chart']['result'][0]['meta']['regularMarketPrice']??null; }
        $op=$pMap[$sym]??0;
        if($np){ $np=round((float)$np,4); $results[]=['symbol'=>$sym,'exchange'=>$exch,'currency'=>'USD','old_price'=>$op,'new_price'=>$np,'change'=>round($np-$op,4),'change_pct'=>$op>0?round(($np-$op)/$op*100,2):0]; }
        else $errors[]="[{$exch}] {$sym}: not found";
    } elseif ($mkt==='Crypto') {
        $cm=['BTC'=>'bitcoin','BITCOIN'=>'bitcoin','ETH'=>'ethereum','SHIB'=>'shiba-inu','DOGE'=>'dogecoin','ADA'=>'cardano','PEPE'=>'pepe'];
        foreach($rows as $h) if(strtoupper($h['symbol'])===$sym&&!empty($h['coin_id'])) $cm[$sym]=strtolower($h['coin_id']);
        $id=$cm[$sym]??strtolower($sym);
        $r=hGet("https://api.coingecko.com/api/v3/simple/price?ids={$id}&vs_currencies=usd");
        $raw=0; if($r){ $d=json_decode($r,true); $raw=(float)($d[$id]['usd']??0); }
        $op=$pMap[$sym]??0;
        if($raw>0){ $results[]=['symbol'=>$sym,'exchange'=>$exch,'currency'=>'USD','old_price'=>$op,'new_price'=>$raw,'change'=>$raw-$op,'change_pct'=>$op>0?round(($raw-$op)/$op*100,4):0]; }
        else $errors[]="[{$exch}] {$sym}: not found";
    } else {
        // BD stocks — SESSION cache (10 min TTL) prevents repeat Gemini calls
        $cacheKey  = ($exch==='CSE') ? 'bd_cse_cache' : 'bd_dse_cache';
        $timeKey   = $cacheKey.'_time';
        $cacheTTL  = 600; // 10 minutes

        $cacheValid = isset($_SESSION[$cacheKey], $_SESSION[$timeKey])
            && (time()-$_SESSION[$timeKey]) < $cacheTTL;

        if (!$cacheValid) {
            // Build full list for this exchange
            if ($exch==='CSE') {
                $allSyms=[]; foreach($rows as $r) if(strtoupper($r['exchange']??'')==='CSE') $allSyms[]=strtoupper($r['symbol']);
                $exLabel='Chittagong Stock Exchange (CSE)';
            } else {
                $allSyms=[]; foreach($rows as $r){ $rx=strtoupper($r['exchange']??''); $mx=$r['market']??''; if($mx!=='USA'&&$mx!=='UK'&&$mx!=='Crypto'&&$rx!=='CSE') $allSyms[]=strtoupper($r['symbol']); }
                $exLabel='Dhaka Stock Exchange (DSE)';
            }
            $allSyms = array_values(array_unique($allSyms));

            // Fetch in chunks of 4 — saves to session
            $_SESSION[$cacheKey] = [];
            $_SESSION[$timeKey]  = time();
            $chunks = array_chunk($allSyms, 2); // 2 per call avoids truncation with verbose responses
            foreach ($chunks as $idx => $chunk) {
                if ($idx > 0) sleep(2);
                $syms = implode(' and ',$chunk);
                $t = gCall("What is the current LTP of {$syms} on {$exLabel} Bangladesh? Reply with ONLY the symbol and price like: BRACBANK=65.90");
                if ($t) $_SESSION[$cacheKey] = array_merge($_SESSION[$cacheKey], pBatch($t, $chunk));
            }
        }

        // Get price from session cache
        $op = $pMap["{$sym}|{$exch}"]??$pMap[$sym]??0;
        $np = $_SESSION[$cacheKey][$sym] ?? null;

        // Sanity check: reject if >85% different from old price
        if ($np && $op>0 && abs($np-$op)/$op>0.85) {
            $errors[]="[{$exch}] {$sym}: price {$np} vs expected ~{$op}, skipped";
            $np = null;
        }

        if ($np && $np>0) {
            $results[]=['symbol'=>$sym,'exchange'=>$exch,'currency'=>'BDT',
                'old_price'=>$op,'new_price'=>round($np,2),
                'change'=>round($np-$op,2),'change_pct'=>$op>0?round(($np-$op)/$op*100,2):0];
        } else {
            $errors[]="[{$exch}] {$sym}: not found in cache";
        }
    }
}

ob_end_clean();
header('Content-Type: application/json');
echo json_encode(['results'=>$results,'errors'=>$errors,'count'=>count($results)]);
