<?php
/**
 * Trade Stock — Buy / Sell
 *
 * This is the missing link between "buy a stock" and the account ledger.
 * Until now, portfolio.php only edited the portfolio table directly —
 * nothing reduced the investment account's cash when you bought, and
 * nothing increased it when you sold. Meanwhile the (now-removed) price
 * sync was overwriting the WHOLE account balance with market value,
 * which is a different bug but came from the same underlying gap: no
 * real link between a trade and the ledger.
 *
 * MODEL:
 *   accounts.balance (ledger)  = CASH sitting in the account, untouched
 *                                by price movement. Only changes via
 *                                transfers in/out and actual buy/sell trades.
 *   portfolio.avg_cost × qty   = cost basis of what you currently hold.
 *   portfolio.current_price    = live market price (from the price cron),
 *                                purely for display — never written to
 *                                accounts.balance.
 *   Unrealized P&L             = market value − cost basis. Stays
 *                                unrealized until you actually sell.
 *
 * BUY:  cash −= qty×price ; portfolio quantity += qty, avg_cost
 *       recalculated as weighted average.
 * SELL: cash += qty×price ; portfolio quantity −= qty (avg_cost of the
 *       remaining shares is unchanged — standard practice). Realized
 *       gain/loss = (sell_price − avg_cost) × qty is recorded in the
 *       transaction note.
 *
 * Both legs (portfolio + transaction + balance) happen inside one DB
 * transaction — either all three happen or none do.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ledger.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$msg=''; $error='';
$accounts = db()->query("SELECT id,name,currency FROM accounts WHERE is_active=1 AND group_name IN ('Investments','Other Currencies Account') ORDER BY name")->fetchAll();

if (isset($_POST['do_trade'])) {
    $action   = $_POST['action'] ?? 'buy'; // buy | sell
    $accId    = (int)($_POST['account_id'] ?? 0);
    $symbol   = strtoupper(trim($_POST['symbol'] ?? ''));
    $company  = trim($_POST['company_name'] ?? '');
    $market   = trim($_POST['market'] ?? 'BD');
    $exchange = strtoupper(trim($_POST['exchange'] ?? 'DSE'));
    $qty      = (float)($_POST['quantity'] ?? 0);
    $price    = (float)($_POST['price'] ?? 0);
    $currency = trim($_POST['currency'] ?? 'BDT');
    $txnDate  = ($_POST['txn_date'] ?? date('Y-m-d')) . ' ' . date('H:i:s');
    $notes    = trim($_POST['notes'] ?? '');

    if (!$accId || !$symbol || $qty <= 0 || $price <= 0) {
        $error = 'Account, symbol, quantity and price are all required.';
    } else {
        $accSt = db()->prepare("SELECT * FROM accounts WHERE id=?");
        $accSt->execute([$accId]);
        $account = $accSt->fetch();
        if (!$account) { $error = 'Account not found.'; }
        else {
            $tradeAmountNative = $qty * $price; // in the trade's own currency
            // convert to the account's own currency for the ledger leg
            $amountBHD = toBHD($tradeAmountNative, $currency);
            $accRate   = getRate('BHD', $account['currency']);
            $amountInAccCur = $accRate > 0 ? $amountBHD * $accRate : $tradeAmountNative;

            try {
                db()->beginTransaction();

                $pSt = db()->prepare("SELECT * FROM portfolio WHERE account_id=? AND UPPER(symbol)=? AND exchange=? LIMIT 1");
                $pSt->execute([$accId, $symbol, $exchange]);
                $holding = $pSt->fetch();

                if ($action === 'buy') {
                    if ($holding) {
                        $oldQty  = (float)$holding['quantity'];
                        $oldCost = (float)$holding['avg_cost'];
                        $newQty  = $oldQty + $qty;
                        $newAvg  = $newQty > 0 ? (($oldQty*$oldCost) + ($qty*$price)) / $newQty : $price;
                        db()->prepare("UPDATE portfolio SET quantity=?,avg_cost=?,company_name=?,current_price=IF(current_price=0,?,current_price),last_updated=NOW() WHERE id=?")
                            ->execute([$newQty, $newAvg, $company ?: $holding['company_name'], $price, $holding['id']]);
                    } else {
                        db()->prepare("INSERT INTO portfolio (symbol,company_name,market,exchange,quantity,avg_cost,currency,current_price,account_id,notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
                            ->execute([$symbol, $company ?: $symbol, $market, $exchange, $qty, $price, $currency, $price, $accId, $notes]);
                    }

                    $note = "Buy {$qty} {$symbol} @ {$price} {$currency}" . ($notes ? " — {$notes}" : '');
                    $bhd  = toBHD($amountInAccCur, $account['currency']);
                    db()->prepare("INSERT INTO transactions (txn_date,type,amount,currency,amount_bhd,account_id,category,subcategory,note,source) VALUES (?,?,?,?,?,?,?,?,?,'web')")
                        ->execute([$txnDate, 'expense', $amountInAccCur, $account['currency'], $bhd, $accId, 'Investment', 'Stock Purchase', $note]);
                    $txnId = (int)db()->lastInsertId();
                    updateAccountBalance($accId, -$amountInAccCur);
                    // Double-entry: this is an asset swap (cash → portfolio cost basis),
                    // NOT an expense — Equity is untouched by a purchase.
                    postStockBuy($txnId, $txnDate, $note, $accId, $amountInAccCur, $account['currency']);

                    $msg = "Bought {$qty} {$symbol} @ {$price} {$currency}. Cash reduced by " . money($amountInAccCur, $account['currency']) . " {$account['currency']}.";

                } elseif ($action === 'sell') {
                    if (!$holding || (float)$holding['quantity'] < $qty) {
                        throw new Exception("You only hold " . ($holding['quantity'] ?? 0) . " {$symbol} in this account — can't sell {$qty}.");
                    }
                    $avgCost   = (float)$holding['avg_cost'];
                    $newQty    = (float)$holding['quantity'] - $qty;
                    $realized  = ($price - $avgCost) * $qty;

                    db()->prepare("UPDATE portfolio SET quantity=?,current_price=?,last_updated=NOW() WHERE id=?")
                        ->execute([$newQty, $price, $holding['id']]);

                    $sign = $realized >= 0 ? '+' : '';
                    $note = "Sell {$qty} {$symbol} @ {$price} {$currency} — Realized P/L: {$sign}" . money($realized, $currency) . " {$currency}" . ($notes ? " — {$notes}" : '');
                    $bhd  = toBHD($amountInAccCur, $account['currency']);
                    db()->prepare("INSERT INTO transactions (txn_date,type,amount,currency,amount_bhd,account_id,category,subcategory,note,source) VALUES (?,?,?,?,?,?,?,?,?,'web')")
                        ->execute([$txnDate, 'income', $amountInAccCur, $account['currency'], $bhd, $accId, 'Investment', 'Stock Sale', $note]);
                    $txnId = (int)db()->lastInsertId();
                    updateAccountBalance($accId, $amountInAccCur);
                    // Double-entry: proceeds return to cash; cost basis leaves Portfolio;
                    // only the realized gain/loss portion hits Equity.
                    $costBasisNative = $qty * $avgCost; // in the trade's own currency
                    $costBHD    = toBHD($costBasisNative, $currency);
                    $costInAccCur = $accRate > 0 ? $costBHD * $accRate : $costBasisNative;
                    postStockSell($txnId, $txnDate, $note, $accId, $amountInAccCur, $costInAccCur, $account['currency']);

                    $msg = "Sold {$qty} {$symbol} @ {$price} {$currency}. Realized P/L: {$sign}" . money($realized, $currency) . " {$currency}. Cash increased by " . money($amountInAccCur, $account['currency']) . " {$account['currency']}.";
                }

                db()->commit();
            } catch (Exception $e) {
                db()->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}

$pageTitle  = 'Trade Stock';
$activePage = 'portfolio';
$backTo = 'portfolio.php';
require __DIR__ . '/header.php';
?>
<div style="max-width:640px;">
<?php if($error):?><div class="alert alert-danger">❌ <?=htmlspecialchars($error)?></div><?php endif;?>
<?php if($msg):?><div class="alert alert-success">✅ <?=htmlspecialchars($msg)?></div><?php endif;?>

<div class="card" style="margin-bottom:16px;">
<form method="POST" action="trade_stock.php">
<input type="hidden" name="do_trade" value="1">

<div class="form-group">
<label class="form-label">Action *</label>
<div style="display:flex;gap:8px;">
<?php foreach(['buy'=>['🟢','Buy'],'sell'=>['🔴','Sell']] as $a=>[$ico,$lbl]):?>
<label style="flex:1;cursor:pointer;">
  <input type="radio" name="action" value="<?=$a?>" <?=$a==='buy'?'checked':''?> style="display:none">
  <div class="type-btn" data-action="<?=$a?>" style="text-align:center;padding:10px;border:2px solid var(--s3);border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;"><?=$ico?> <?=$lbl?></div>
</label>
<?php endforeach;?>
</div></div>

<div class="form-group">
<label class="form-label">Investment Account *</label>
<select class="form-control" name="account_id" required>
<option value="">— Select —</option>
<?php foreach($accounts as $a):?>
<option value="<?=$a['id']?>"><?=htmlspecialchars($a['name'])?> (<?=$a['currency']?>)</option>
<?php endforeach;?>
</select>
<div class="hint">Cash moves in/out of this account. Buy reduces its balance, sell increases it.</div>
</div>

<div class="form-row">
  <div class="form-group"><label class="form-label">Symbol *</label>
    <input class="form-control" name="symbol" placeholder="e.g. EBL" style="text-transform:uppercase;" required></div>
  <div class="form-group"><label class="form-label">Exchange</label>
    <input class="form-control" name="exchange" value="DSE" placeholder="DSE, NYSE, Crypto..."></div>
</div>

<div class="form-group"><label class="form-label">Company / Asset Name</label>
<input class="form-control" name="company_name" placeholder="e.g. Eastern Bank Limited"></div>

<div class="form-group"><label class="form-label">Market</label>
<input class="form-control" name="market" value="BD" placeholder="BD, USA, Crypto..."></div>

<div class="form-row">
  <div class="form-group"><label class="form-label">Quantity *</label>
    <input class="form-control" type="number" step="0.0001" name="quantity" required></div>
  <div class="form-group"><label class="form-label">Price per Share *</label>
    <input class="form-control" type="number" step="any" name="price" required></div>
</div>

<div class="form-row">
  <div class="form-group"><label class="form-label">Currency</label>
    <select class="form-control" name="currency"><?php foreach(['BDT','BHD','USD','GBP','EUR'] as $c):?><option value="<?=$c?>"><?=$c?></option><?php endforeach;?></select></div>
  <div class="form-group"><label class="form-label">Date</label>
    <input class="form-control" type="date" name="txn_date" value="<?=date('Y-m-d')?>"></div>
</div>

<div class="form-group"><label class="form-label">Notes</label>
<input class="form-control" name="notes" placeholder="Optional"></div>

<div class="gap-2" style="margin-top:8px;">
<button type="submit" class="btn btn-primary">✅ Record Trade</button>
<a href="portfolio.php" class="btn btn-ghost">← Back to Portfolio</a>
</div>
</form>
</div>

<div class="card">
  <div style="font-size:.82rem;color:var(--muted);line-height:1.7;">
    <strong>Buy:</strong> cash leaves the selected investment account (recorded as an expense, category
    "Investment › Stock Purchase") and the same amount becomes cost basis on the portfolio holding.<br><br>
    <strong>Sell:</strong> cash returns to the account (recorded as income, category "Investment › Stock
    Sale"), and the realized gain/loss vs. your average cost is written into the transaction note. The
    remaining shares keep the same average cost — only the quantity drops.<br><br>
    Price updates from the Friday/daily cron jobs only ever change <code>portfolio.current_price</code> —
    they never touch this account's cash balance. Unrealized gain/loss stays unrealized until you record
    an actual sell here.
  </div>
</div>
</div>
<script>
document.querySelectorAll('input[name="action"]').forEach(r=>r.addEventListener('change', updateActionBtns));
function updateActionBtns(){
  const a = document.querySelector('input[name="action"]:checked').value;
  document.querySelectorAll('.type-btn').forEach(b=>{
    const on = b.dataset.action === a;
    b.style.borderColor = on ? 'var(--blue)' : 'var(--s3)';
    b.style.color = on ? 'var(--blue)' : '';
    b.style.background = on ? 'var(--s2)' : '';
  });
}
document.querySelectorAll('.type-btn').forEach(b=>b.addEventListener('click', ()=>{
  document.querySelector('input[name="action"][value="'+b.dataset.action+'"]').checked = true;
  updateActionBtns();
}));
updateActionBtns();
</script>
<?php require __DIR__ . '/footer.php'; ?>
