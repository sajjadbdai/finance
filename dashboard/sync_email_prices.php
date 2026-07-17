<?php
/**
 * Sync portfolio prices from BRAC EPL email PDF
 * Uses Gmail API via Claude to read latest portfolio statement
 * Called from portfolio page "Sync from Email" button
 */
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { http_response_code(403); exit; }
header('Content-Type: application/json');

// This endpoint receives price data extracted by Claude from the email
// The actual Gmail reading happens in the dashboard via API artifact
$input = json_decode(file_get_contents('php://input'), true);
$prices = $input['prices'] ?? [];
$source = $input['source'] ?? 'email';
$saved  = 0;
$log    = [];

foreach($prices as $item){
    $sym      = strtoupper(trim($item['symbol']??''));
    $exchange = trim($item['exchange']??'DSE');
    $price    = (float)($item['price']??0);
    if(!$sym || $price<=0) continue;

    $st = db()->prepare("UPDATE portfolio SET current_price=?, last_updated=NOW(), notes=CONCAT(IFNULL(notes,''),' [Updated from email ".date('d M Y')."]') WHERE UPPER(symbol)=? AND exchange=?");
    $st->execute([$price, $sym, $exchange]);
    if($st->rowCount()===0){
        // Try without exchange filter
        db()->prepare("UPDATE portfolio SET current_price=?, last_updated=NOW() WHERE UPPER(symbol)=?")
           ->execute([$price, $sym]);
    }
    $saved++;
    $log[] = "✅ {$sym}: {$price} BDT";
}

echo json_encode(['saved'=>$saved, 'log'=>$log]);
