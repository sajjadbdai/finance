<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { http_response_code(403); exit; }
header('Content-Type: application/json');

$data   = json_decode(file_get_contents('php://input'), true);
$prices = $data['prices'] ?? [];
$saved  = 0;

foreach ($prices as $item) {
    $sym   = strtoupper($item['symbol'] ?? '');
    $exch  = $item['exchange'] ?? '';
    $price = (float)($item['new_price'] ?? 0);
    if (!$sym || $price <= 0) continue;
    db()->prepare("UPDATE portfolio SET current_price=?, last_updated=NOW() WHERE UPPER(symbol)=? AND exchange=?")
       ->execute([$price, $sym, $exch]);
    $saved++;
}

echo json_encode(['saved' => $saved]);

// Sync portfolio values to linked accounts
require_once __DIR__ . '/sync_portfolio_accounts.php';
syncPortfolioToAccounts();
