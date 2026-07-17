<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { echo "Not logged in"; exit; }

$key = GEMINI_API_KEY;

// Test with 2 stocks (our new chunk size)
$tests = [
    "What is the current LTP of BRACBANK and CITYBANK on Dhaka Stock Exchange (DSE) Bangladesh? Reply with ONLY the symbol and price like: BRACBANK=65.90",
    "What is the current LTP of NBL and ROBI on Chittagong Stock Exchange (CSE) Bangladesh? Reply with ONLY the symbol and price like: NBL=4.00",
];

foreach ($tests as $prompt) {
    $payload = [
        'contents' => [['parts'=>[['text'=>$prompt]]]],
        'tools'    => [['google_search'=>new stdClass()]],
        'generationConfig' => ['temperature'=>0,'maxOutputTokens'=>1500],
    ];

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$key}");
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

    // Try to parse
    $prices=[];
    preg_match_all('/([A-Z][A-Z0-9]{1,11})\s*[=:]\s*([\d]+\.?[\d]*)/i',$text,$m);
    foreach($m[1] as $i=>$s){ $v=(float)$m[2][$i]; if($v>0&&$v<500000) $prices[strtoupper($s)]=$v; }

    // Natural language fallback
    if(empty($prices)){
        foreach(['BRACBANK','CITYBANK','MTB','NBL','ROBI','BEXGSUKUK','TRUSTB1MF'] as $sym){
            $pos=stripos($text,$sym);
            if($pos!==false){ $snip=substr($text,$pos,120); if(preg_match('/([\d]+\.[\d]+|[\d]+)/',$snip,$nm)){ $v=(float)$nm[1]; if($v>0&&$v<500000) $prices[$sym]=$v; } }
        }
    }

    echo "<pre>HTTP: $code\nPrompt: ".htmlspecialchars(substr($prompt,0,60))."...\nText: ".htmlspecialchars($text)."\nParsed: ".print_r($prices,true)."</pre><hr>";
    sleep(2);
}
