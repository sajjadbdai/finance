<?php
/**
 * Trade Fixed Asset — Buy / Sell
 *
 * Same gap as the original portfolio problem, for fixed assets: buying
 * a car, land, or gold had no link to where the money came from — the
 * fixed_assets table was completely standalone. This is the missing
 * link, built exactly like trade_stock.php.
 *
 * MODEL:
 *   accounts.balance (ledger) = cash, untouched by the asset's value
 *                               changing over time — only moves on an
 *                               actual buy/sell here.
 *   fixed_assets.current_value = cost basis (on buy) or market
 *                               estimate you update manually over
 *                               time (a car isn't priced by a live
 *                               feed like a stock).
 *
 * BUY:  cash −= price ; a fixed_assets row is created, linked to the
 *       funding account. Funding account can be ANY account —
 *       including a credit card, for a car bought on credit.
 * SELL: cash += price ; the asset is marked 'sold' (not deleted — kept
 *       for history), and any gain/loss vs. its recorded cost is
 *       treated as realized, same accounting treatment as a stock sale.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ledger.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$msg=''; $error='';
// ANY account can fund an asset purchase — including credit cards, for
// buying on credit — unlike Trade Stock which is Investments-only.
$accounts = db()->query("SELECT id,name,currency,is_credit_card FROM accounts WHERE is_active=1 ORDER BY group_name,name")->fetchAll();
$categories = ['Land','Home/Property','Car/Vehicle','Gold/Silver','Electronics','Furniture','Business','Other'];

// Owned assets, for the Sell dropdown
$ownedAssets = db()->query("SELECT * FROM fixed_assets WHERE status='owned' ORDER BY name")->fetchAll();

if (isset($_POST['do_trade'])) {
    $action  = $_POST['action'] ?? 'buy';
    $accId   = (int)($_POST['account_id'] ?? 0);
    $txnDate = ($_POST['txn_date'] ?? date('Y-m-d')) . ' ' . date('H:i:s');
    $notes   = trim($_POST['notes'] ?? '');

    $accSt = db()->prepare("SELECT * FROM accounts WHERE id=?");
    $accSt->execute([$accId]);
    $account = $accSt->fetch();

    if (!$account) {
        $error = 'Please select a funding account.';
    } elseif ($action === 'buy') {
        $name  = trim($_POST['name'] ?? '');
        $cat   = trim($_POST['category'] ?? 'Other');
        $price = (float)($_POST['price'] ?? 0);
        $currency = trim($_POST['currency'] ?? $account['currency']);
        $location = trim($_POST['location'] ?? '');

        if (!$name || $price <= 0) {
            $error = 'Asset name and price are required.';
        } else {
            try {
                db()->beginTransaction();
                db()->prepare("INSERT INTO fixed_assets (name,category,purchase_date,purchase_price,current_value,currency,location,notes,account_id,status) VALUES (?,?,?,?,?,?,?,?,?,'owned')")
                    ->execute([$name,$cat,date('Y-m-d',strtotime($txnDate)),$price,$price,$currency,$location,$notes,$accId]);
                $assetId = (int)db()->lastInsertId();

                $note = "Buy Fixed Asset: {$name} ({$cat}) for " . money($price, $currency) . " {$currency}" . ($notes?" — {$notes}":'') . " [asset#{$assetId}]";
                $amountInAccCur = $account['currency'] === $currency ? $price : toBHD($price,$currency) / max(0.000001, toBHD(1,$account['currency']));
                $bhd = toBHD($amountInAccCur, $account['currency']);

                db()->prepare("INSERT INTO transactions (txn_date,type,amount,currency,amount_bhd,account_id,category,subcategory,note,source) VALUES (?,?,?,?,?,?,?,?,?,'web')")
                    ->execute([$txnDate,'expense',$amountInAccCur,$account['currency'],$bhd,$accId,'Fixed Asset','Asset Purchase',$note]);
                $txnId = (int)db()->lastInsertId();
                updateAccountBalance($accId, -$amountInAccCur);
                postFixedAssetBuy($txnId, $txnDate, $note, $accId, $amountInAccCur, $account['currency']);

                db()->commit();
                $msg = "Bought \"{$name}\" for " . money($price, $currency) . " {$currency}. Cash reduced by " . money($amountInAccCur, $account['currency']) . " {$account['currency']}.";
            } catch (Exception $e) {
                db()->rollBack();
                $error = $e->getMessage();
            }
        }

    } elseif ($action === 'sell') {
        $assetId = (int)($_POST['asset_id'] ?? 0);
        $price   = (float)($_POST['price'] ?? 0);
        $currency = trim($_POST['currency'] ?? $account['currency']);

        $aSt = db()->prepare("SELECT * FROM fixed_assets WHERE id=? AND status='owned'");
        $aSt->execute([$assetId]);
        $asset = $aSt->fetch();

        if (!$asset || $price <= 0) {
            $error = 'Select a valid owned asset and a sale price.';
        } else {
            try {
                db()->beginTransaction();
                $costBasis = (float)$asset['current_value'];
                $realized  = $price - $costBasis;
                $sign = $realized >= 0 ? '+' : '';
                $note = "Sell Fixed Asset: {$asset['name']} for " . money($price, $currency) . " {$currency} — Realized P/L: {$sign}" . money($realized, $currency) . " {$currency}" . ($notes?" — {$notes}":'') . " [asset#{$assetId}]";

                $amountInAccCur = $account['currency'] === $currency ? $price : toBHD($price,$currency) / max(0.000001, toBHD(1,$account['currency']));
                $bhd = toBHD($amountInAccCur, $account['currency']);

                db()->prepare("INSERT INTO transactions (txn_date,type,amount,currency,amount_bhd,account_id,category,subcategory,note,source) VALUES (?,?,?,?,?,?,?,?,?,'web')")
                    ->execute([$txnDate,'income',$amountInAccCur,$account['currency'],$bhd,$accId,'Fixed Asset','Asset Sale',$note]);
                $txnId = (int)db()->lastInsertId();
                updateAccountBalance($accId, $amountInAccCur);
                postFixedAssetSell($txnId, $txnDate, $note, $accId, $amountInAccCur, $costBasis, $account['currency']);

                db()->prepare("UPDATE fixed_assets SET status='sold', sold_date=?, sold_price=? WHERE id=?")
                    ->execute([date('Y-m-d',strtotime($txnDate)), $price, $assetId]);

                db()->commit();
                $msg = "Sold \"{$asset['name']}\" for " . money($price, $currency) . " {$currency}. Realized P/L: {$sign}" . money($realized, $account['currency']) . ". Cash increased by " . money($amountInAccCur, $account['currency']) . " {$account['currency']}.";
            } catch (Exception $e) {
                db()->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}

$pageTitle  = 'Trade Fixed Asset';
$activePage = 'fixed_assets';
$backTo = 'fixed_assets.php';
require __DIR__ . '/header.php';
?>
<div style="max-width:640px;">
<?php if($error):?><div class="alert alert-danger">❌ <?=htmlspecialchars($error)?></div><?php endif;?>
<?php if($msg):?><div class="alert alert-success">✅ <?=htmlspecialchars($msg)?></div><?php endif;?>

<div class="card" style="margin-bottom:16px;">
<form method="POST" action="trade_fixed_asset.php">
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
<label class="form-label">Funding / Receiving Account *</label>
<select class="form-control" name="account_id" required>
<option value="">— Select —</option>
<?php foreach($accounts as $a):?>
<option value="<?=$a['id']?>"><?=htmlspecialchars($a['name'])?><?=$a['is_credit_card']?' 💳':''?> (<?=$a['currency']?>)</option>
<?php endforeach;?>
</select>
<div class="hint">Cash moves in/out of this account. Any account works — including a credit card, for buying on credit.</div>
</div>

<!-- BUY fields -->
<div id="buyFields">
  <div class="form-group"><label class="form-label">Asset Name *</label>
    <input class="form-control" name="name" placeholder="e.g. Toyota Corolla 2024"></div>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Category</label>
      <select class="form-control" name="category"><?php foreach($categories as $c):?><option value="<?=$c?>"><?=$c?></option><?php endforeach;?></select></div>
    <div class="form-group"><label class="form-label">Location</label>
      <input class="form-control" name="location" placeholder="Optional"></div>
  </div>
</div>

<!-- SELL fields -->
<div id="sellFields" style="display:none;">
  <div class="form-group"><label class="form-label">Asset to Sell *</label>
    <select class="form-control" name="asset_id">
      <option value="">— Select —</option>
      <?php foreach($ownedAssets as $a):?>
      <option value="<?=$a['id']?>">
        <?=htmlspecialchars($a['name'])?> — cost basis <?=money((float)$a['current_value'], $a['currency'])?> <?=$a['currency']?>
      </option>
      <?php endforeach;?>
    </select>
  </div>
</div>

<div class="form-row">
  <div class="form-group"><label class="form-label">Price *</label>
    <input class="form-control" type="number" step="any" name="price" required></div>
  <div class="form-group"><label class="form-label">Currency</label>
    <select class="form-control" name="currency"><?php foreach(['BHD','BDT','USD','GBP','EUR'] as $c):?><option value="<?=$c?>"><?=$c?></option><?php endforeach;?></select></div>
</div>

<div class="form-group"><label class="form-label">Date</label>
<input class="form-control" type="date" name="txn_date" value="<?=date('Y-m-d')?>"></div>

<div class="form-group"><label class="form-label">Notes</label>
<input class="form-control" name="notes" placeholder="Optional"></div>

<div class="gap-2" style="margin-top:8px;">
<button type="submit" class="btn btn-primary">✅ Record</button>
<a href="fixed_assets.php" class="btn btn-ghost">← Back to Fixed Assets</a>
</div>
</form>
</div>

<div class="card">
  <div style="font-size:.82rem;color:var(--muted);line-height:1.7;">
    <strong>Buy:</strong> cash leaves the funding account (expense, category "Fixed Asset › Asset Purchase")
    and becomes the asset's cost basis — not spending, an asset swap, same treatment as buying a stock.<br><br>
    <strong>Sell:</strong> cash returns to the account (income, "Fixed Asset › Asset Sale"), with the
    gain/loss vs. the recorded cost basis written into the note as realized P/L. The asset is marked sold,
    not deleted — its history stays visible in Fixed Assets.
  </div>
</div>
</div>
<script>
function updateFields(){
  const a = document.querySelector('input[name="action"]:checked').value;
  document.getElementById('buyFields').style.display = a==='buy' ? 'block' : 'none';
  document.getElementById('sellFields').style.display = a==='sell' ? 'block' : 'none';
  document.querySelectorAll('.type-btn').forEach(b=>{
    const on = b.dataset.action === a;
    b.style.borderColor = on ? 'var(--blue)' : 'var(--s3)';
    b.style.color = on ? 'var(--blue)' : '';
    b.style.background = on ? 'var(--s2)' : '';
  });
}
document.querySelectorAll('input[name="action"]').forEach(r=>r.addEventListener('change', updateFields));
document.querySelectorAll('.type-btn').forEach(b=>b.addEventListener('click', ()=>{
  document.querySelector('input[name="action"][value="'+b.dataset.action+'"]').checked = true;
  updateFields();
}));
updateFields();
</script>
<?php require __DIR__ . '/footer.php'; ?>
