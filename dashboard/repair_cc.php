<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$msg = '';

if (isset($_POST['repair_all'])) {
    $cards = db()->query("SELECT * FROM accounts WHERE is_credit_card=1 AND is_active=1")->fetchAll();
    $fixed = 0;
    foreach ($cards as $card) {
        // Calculate outstanding from transactions AFTER last bill date
        $today    = date('Y-m-d');
        $billDay  = (int)$card['bill_date'];
        $thisMonth= (int)date('m');
        $thisYear = (int)date('Y');

        // Find last bill date
        if ((int)date('j') >= $billDay) {
            $lastBillDate = date('Y-m-').str_pad($billDay,2,'0',STR_PAD_LEFT);
        } else {
            $lastBillDate = date('Y-m-', strtotime('-1 month')).str_pad($billDay,2,'0',STR_PAD_LEFT);
        }

        // Sum expenses on this card since last bill date = outstanding
        $st = db()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN type='expense' THEN amount WHEN type='income' THEN -amount ELSE 0 END),0) as total
             FROM transactions WHERE account_id=? AND DATE(txn_date) > ?"
        );
        $st->execute([$card['id'], $lastBillDate]);
        $newOutstanding = max(0, (float)$st->fetchColumn());

        $payable    = (float)$card['payable_balance'];
        $newBalance = -($payable + $newOutstanding);

        db()->prepare("UPDATE accounts SET outstanding_balance=?, balance=?, updated_at=NOW() WHERE id=?")
           ->execute([$newOutstanding, $newBalance, $card['id']]);
        $fixed++;
    }
    $msg = "✅ Recalculated outstanding from transactions for $fixed credit card(s).";
}

if (isset($_POST['manual_fix'])) {
    $id    = (int)$_POST['card_id'];
    $pay   = (float)$_POST['payable'];
    $outst = (float)$_POST['outstanding'];
    $bal   = -($pay + $outst);
    db()->prepare("UPDATE accounts SET payable_balance=?, outstanding_balance=?, balance=? WHERE id=?")
       ->execute([$pay, $outst, $bal, $id]);
    $msg = "✅ Manually updated.";
}

$cards = db()->query("SELECT * FROM accounts WHERE is_credit_card=1 AND is_active=1 ORDER BY name")->fetchAll();
?>
<!DOCTYPE html><html><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Repair CC Balances</title>
<?php include 'css.php'; ?>
</head><body>
<?php include 'nav.php'; ?>
<div class="main">
<div class="topbar"><div class="tbar-title">🔧 Credit Card Balance Repair</div><a href="accounts.php" class="btn btn-ghost btn-sm">← Accounts</a></div>
<div class="content" style="max-width:750px;">

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>

<div class="card" style="margin-bottom:20px;">
  <div style="font-weight:600;margin-bottom:8px;">Auto Recalculate from Transactions</div>
  <div style="font-size:.85rem;color:var(--muted);margin-bottom:14px;">
    Scans all transactions since last bill date to recalculate <strong>Outstanding Balance</strong> for each card.
    Then sets <strong>Balance = -(Payable + Outstanding)</strong>.
  </div>
  <form method="POST">
    <button type="submit" name="repair_all" value="1" class="btn btn-primary">🔧 Auto Repair All Cards</button>
  </form>
</div>

<?php foreach($cards as $card):
  $expected = -((float)$card['payable_balance'] + (float)$card['outstanding_balance']);
  $actual   = (float)$card['balance'];
  $ok       = abs($expected - $actual) < 0.01;
?>
<div class="card" style="margin-bottom:14px;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
    <div style="font-weight:700;"><?=htmlspecialchars($card['name'])?></div>
    <span class="badge <?=$ok?'badge-inc':'badge-exp'?>"><?=$ok?'✅ OK':'❌ Mismatch'?></span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:14px;font-size:.85rem;">
    <div><div style="color:var(--muted);margin-bottom:4px;">Balance (stored)</div><div style="font-weight:700;color:var(--red);"><?=number_format($actual,3)?> <?=$card['currency']?></div></div>
    <div><div style="color:var(--muted);margin-bottom:4px;">Payable</div><div style="font-weight:700;color:var(--red);"><?=number_format((float)$card['payable_balance'],3)?> <?=$card['currency']?></div></div>
    <div><div style="color:var(--muted);margin-bottom:4px;">Outstanding</div><div style="font-weight:700;color:var(--orange);"><?=number_format((float)$card['outstanding_balance'],3)?> <?=$card['currency']?></div></div>
  </div>
  <?php if(!$ok):?><div style="font-size:.82rem;color:var(--red);margin-bottom:10px;">Expected balance: <?=number_format($expected,3)?></div><?php endif;?>
  <!-- Manual override -->
  <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <input type="hidden" name="card_id" value="<?=$card['id']?>">
    <div><label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:3px;">Payable Balance</label>
      <input type="number" step="0.001" name="payable" value="<?=htmlspecialchars($card['payable_balance'])?>" style="width:130px;padding:8px;background:var(--s2);border:1px solid var(--s3);border-radius:6px;color:var(--text);"></div>
    <div><label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:3px;">Outstanding Balance</label>
      <input type="number" step="0.001" name="outstanding" value="<?=htmlspecialchars($card['outstanding_balance'])?>" style="width:130px;padding:8px;background:var(--s2);border:1px solid var(--s3);border-radius:6px;color:var(--text);"></div>
    <button type="submit" name="manual_fix" value="1" class="btn btn-ghost btn-sm">💾 Set Manually</button>
  </form>
</div>
<?php endforeach;?>
</div></div>
</body></html>
