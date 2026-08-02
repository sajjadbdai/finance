<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Balance Audit'; $activePage='accounts'; $backTo='balance_tools.php';

$id = (int)($_GET['id'] ?? 0);
$accounts = db()->query("SELECT id,name,currency,balance,type,is_credit_card FROM accounts WHERE is_active=1 ORDER BY name")->fetchAll();

$acc=null; $calc=0; $txns=[]; $imported=0;
if ($id) {
    $s=db()->prepare("SELECT * FROM accounts WHERE id=?"); $s->execute([$id]); $acc=$s->fetch();
    if ($acc) {
        $s=db()->prepare("SELECT * FROM transactions WHERE account_id=? OR to_account_id=? ORDER BY txn_date ASC, id ASC");
        $s->execute([$id,$id]); $txns=$s->fetchAll();
        foreach ($txns as $t) {
            $amt=(float)$t['amount'];
            if ((int)$t['account_id']===$id) {
                if ($t['type']==='income')       $calc += $amt;
                elseif ($t['type']==='expense')  $calc -= $amt;
                elseif ($t['type']==='transfer') $calc -= $amt;
            }
            if ((int)$t['to_account_id']===$id && $t['type']==='transfer') $calc += $amt;
        }
        try {
            $s=db()->prepare("SELECT COUNT(*) FROM bank_statement_lines l JOIN bank_statements b ON b.id=l.statement_id WHERE b.account_id=? AND l.matched_txn_id IS NOT NULL");
            $s->execute([$id]); $imported=(int)$s->fetchColumn();
        } catch(Exception $e){}
    }
}
require 'header.php'; ?>

<div class="card" style="margin-bottom:16px;">
  <div class="section-title" style="margin-bottom:12px;">🔍 Balance Audit</div>
  <div style="font-size:.84rem;color:var(--muted);margin-bottom:12px;">
    Compares the <strong>stored balance</strong> against the sum of all transactions in the account's own currency. Read-only — changes nothing.
  </div>
  <form method="GET">
    <div class="form-group">
      <select class="form-control" name="id" onchange="this.form.submit()">
        <option value="">— Select account —</option>
        <?php foreach($accounts as $a):?>
        <option value="<?=$a['id']?>" <?=$id==$a['id']?'selected':''?>><?=htmlspecialchars($a['name'])?> (<?=$a['currency']?>)</option>
        <?php endforeach;?>
      </select>
    </div>
  </form>
</div>

<?php if($acc): $stored=(float)$acc['balance']; $diff=$stored-$calc; ?>
<div class="g3" style="margin-bottom:16px;">
  <div class="card">
    <div class="card-title">Stored Balance</div>
    <div class="card-value c-blue" data-hide="true"><?=money($stored)?></div>
    <div class="card-sub"><?=$acc['currency']?> — what the app displays</div>
  </div>
  <div class="card">
    <div class="card-title">Sum of Transactions</div>
    <div class="card-value" data-hide="true"><?=money($calc)?></div>
    <div class="card-sub"><?=count($txns)?> transactions, native currency</div>
  </div>
  <div class="card">
    <div class="card-title">Difference</div>
    <div class="card-value <?=abs($diff)<0.01?'c-green':'c-red'?>" data-hide="true"><?=money($diff)?></div>
    <div class="card-sub"><?=abs($diff)<0.01?'Consistent':'Includes opening balance and/or drift'?></div>
  </div>
</div>

<div class="card" style="margin-bottom:16px;">
  <div style="font-size:.85rem;line-height:1.7;">
    <?php if($imported>0):?>
    <div style="background:var(--orange)22;border:1px solid var(--orange);border-radius:8px;padding:12px;margin-bottom:12px;">
      ⚠️ <strong><?=$imported?> transaction(s) were imported from bank statements</strong> using the +Add button.
      <?php if($acc['currency']!=='BHD'):?>
      Because this is a <?=$acc['currency']?> account, each import moved the stored balance by the BHD-converted amount instead of the <?=$acc['currency']?> amount — this is the drift.
      <?php endif;?>
    </div>
    <?php endif;?>
    <strong>How to read the difference:</strong> if this account had a starting balance that was never entered as a transaction, the difference should equal that opening balance and stay constant. If it doesn't match anything you recognise, the balance has drifted.
  </div>
</div>

<div class="card">
  <div class="section-title" style="margin-bottom:10px;">Transactions (<?=count($txns)?>) — running total from zero</div>
  <?php if(!$txns):?><p style="color:var(--muted);padding:20px;text-align:center;">No transactions.</p><?php else:?>
  <table class="tbl" style="font-size:.8rem;">
    <tr><th>Date</th><th>Type</th><th style="text-align:right;">Amount</th><th style="text-align:right;">BHD col</th><th>Note</th><th>Source</th><th style="text-align:right;">Running</th></tr>
    <?php $run=0; foreach(array_reverse($txns) as $t): ?><?php endforeach; $run=0; foreach($txns as $t):
      $amt=(float)$t['amount']; $eff=0;
      if((int)$t['account_id']===$id){ $eff = $t['type']==='income'?$amt:-$amt; }
      if((int)$t['to_account_id']===$id && $t['type']==='transfer'){ $eff = $amt; }
      $run+=$eff;
    ?>
    <tr>
      <td style="white-space:nowrap;"><?=date('d M Y',strtotime($t['txn_date']))?></td>
      <td><?=ucfirst($t['type'])?></td>
      <td data-hide="true" style="text-align:right;color:<?=$eff>=0?'var(--green)':'var(--red)'?>"><?=money($eff)?></td>
      <td data-hide="true" style="text-align:right;color:var(--muted);"><?=money((float)$t['amount_bhd'])?></td>
      <td><?=htmlspecialchars(substr($t['note']??'',0,45))?></td>
      <td><small class="c-muted"><?=$t['source']?></small></td>
      <td data-hide="true" style="text-align:right;font-weight:600;"><?=money($run)?></td>
    </tr>
    <?php endforeach;?>
  </table>
  <?php endif;?>
</div>
<?php endif;?>
<?php require 'footer.php'; ?>
