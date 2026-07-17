<?php
/**
 * Read portfolio PDFs from broker emails via Gmail MCP
 * Requires Gmail OAuth token passed from browser
 */
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { http_response_code(403); exit; }
header('Content-Type: application/json');
set_time_limit(120);

$input    = json_decode(file_get_contents('php://input'), true);
$gmailToken = trim($input['gmail_token'] ?? '');

if (!$gmailToken) {
    echo json_encode(['error' => 'NO_TOKEN', 'message' => 'Gmail token required']);
    exit;
}

$anthropicKey = ANTHROPIC_API_KEY;

$prompt = <<<PROMPT
Use Gmail to do these two things and extract stock prices:

1. Search for the latest email from eportfolio@bracepsl.com with subject "Portfolio".
   Get the thread and read the PDF attachment "Portfolio Statement-ODS622.pdf".
   Extract ALL stocks and their Market Price column values.
   These are DSE (Dhaka Stock Exchange) stocks.

2. Search for the latest email with subject containing "Portfolio Statement of ID# 179387".
   Get the thread and read the PDF attachment.
   Extract ALL stocks and their Current Price or Market Price.
   These are CSE (Chittagong Stock Exchange) stocks.

Return ONLY a JSON array, no explanation:
[
  {"symbol":"CITYBANK","price":30.70,"exchange":"DSE","broker":"BRAC EPL"},
  {"symbol":"NBL","price":4.00,"exchange":"CSE","broker":"Berich"}
]
PROMPT;

$payload = [
    'model'      => 'claude-sonnet-4-6',
    'max_tokens' => 2000,
    'messages'   => [['role'=>'user','content'=>$prompt]],
    'mcp_servers'=> [[
        'type'             => 'url',
        'url'              => 'https://gmailmcp.googleapis.com/mcp/v1',
        'name'             => 'gmail-mcp',
        'authorization_token' => $gmailToken,
    ]]
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: '.$anthropicKey,
        'anthropic-version: 2023-06-01',
        'anthropic-beta: mcp-client-2025-04-04',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 90,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if($code!==200||!$resp){
    $err = $resp ? json_decode($resp,true) : null;
    $msg = $err['error']['message'] ?? "HTTP {$code}";
    echo json_encode(['error'=>$msg]);
    exit;
}

$data = json_decode($resp,true);
$text = '';
foreach(($data['content']??[]) as $block)
    if($block['type']==='text') $text .= $block['text'];

$prices = [];
if(preg_match('/\[[\s\S]*\]/m', $text, $m)){
    $arr = json_decode($m[0], true);
    if(is_array($arr)){
        foreach($arr as $item){
            $sym   = strtoupper(trim($item['symbol']??''));
            $price = (float)($item['price']??0);
            $exch  = trim($item['exchange']??'DSE');
            $broker= trim($item['broker']??'');
            if($sym && $price>0)
                $prices[] = ['symbol'=>$sym,'exchange'=>$exch,'price'=>$price,'broker'=>$broker];
        }
    }
}

if(empty($prices)){
    echo json_encode(['error'=>'Could not extract prices. Response: '.substr($text,0,400)]);
    exit;
}

usort($prices, function($a,$b){ return strcmp($a['broker'].$a['symbol'],$b['broker'].$b['symbol']); });

echo json_encode([
    'prices'   => $prices,
    'source'   => 'BRAC EPL + Berich Email',
    'date'     => date('d M Y'),
    'count'    => count($prices),
    'brac_epl' => count(array_filter($prices,function($p){return $p['exchange']==='DSE';})),
    'berich'   => count(array_filter($prices,function($p){return $p['exchange']==='CSE';})),
]);
