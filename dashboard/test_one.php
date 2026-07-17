<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { echo "Not logged in"; exit; }
header('Content-Type: text/plain');

// Simulate what clicking BRACBANK fetch button does
$_GET['type'] = 'one';
$_GET['sym']  = 'BRACBANK';
$_GET['exch'] = 'DSE';
$_GET['mkt']  = 'BD';

echo "Session ID: ".session_id()."\n";
echo "Session cache exists: ".(isset($_SESSION['bd_dse_cache'])?'YES':'NO')."\n";
echo "Session cache time: ".($_SESSION['bd_dse_cache_time']??'none')."\n\n";

// Test gCall directly first
$prompt = "What is the current LTP of BRACBANK and CITYBANK on Dhaka Stock Exchange (DSE) Bangladesh? Reply with ONLY the symbol and price like: BRACBANK=65.90";

$payload = [
    'contents' => [['parts'=>[['text'=>$prompt]]]],
    'tools'    => [['google_search'=>new stdClass()]],
    'generationConfig' => ['temperature'=>0,'maxOutputTokens'=>1500],
];

$ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=".GEMINI_API_KEY);
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode($payload),
    CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>false]);
$resp=curl_exec($ch);
$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
curl_close($ch);

$d=json_decode($resp,true);
$text='';
foreach(($d['candidates'][0]['content']['parts']??[]) as $p) if(isset($p['text'])) $text.=$p['text'];

echo "Gemini HTTP: $code\n";
echo "Gemini text: $text\n";
echo "Error: ".($d['error']['message']??'none')."\n\n";

// Now check what run_price_update returns
echo "=== run_price_update output ===\n";
ob_start();
include __DIR__.'/run_price_update.php';
$out = ob_get_clean();
echo $out."\n";
