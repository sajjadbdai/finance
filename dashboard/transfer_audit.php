<?php
/**
 * Transfer Integrity Audit — Dr = Cr checker
 * Read-only. Changes nothing.
 *
 * Every transfer transaction stores ONE amount, debited from account_id
 * and credited to to_account_id. That means Dr and Cr are the same
 * number by construction — UNLESS:
 *   (a) to_account_id is missing/0 → money left an account and was
 *       never credited anywhere (a real leak), or
 *   (b) the two accounts use different currencies → the same raw
 *       amount is being applied to both, which misstates the
 *       destination leg (flagged for manual review, not auto-fixed).
 */
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Transfer Integrity Audit'; $activePage='accounts'; $backTo='balance_tools.php';

// All transfer rows, with source/destination account details
$rows = db()->query(
    "SELECT t.*, a.name as acc_name, a.currency as acc_cur, a.is_active as acc_active,
            b.name as to_acc_name, b.currency as to_cur, b.is_active as to_active
     FROM transactions t
     LEFT JOIN accounts a ON a.id=t.account_id
     LEFT JOIN accounts b ON b.id=t.to_account_id
     WHERE t.type='transfer'
     ORDER BY t.txn_date DESC, t.id DESC"
)->fetchAll();

$orphaned      = [];
$crossCurrency = [];
$badAccount    = [];
$totalDebit    = 0.0;
$totalCredit   = 0.0;

foreach ($rows as $r) {
    $amt = (float)$r['amount'];
    $totalDebit += $amt;
    if (!$r['to_account_id']) {
        $orphaned[] = $r;
        continue; // no credit leg at all
    }
    $totalCredit += $amt;
    if (!$r['to_active'] || !$r['acc_active']) {
        $badAccount[] = $r;
    }
    if ($r['acc_cur'] && $r['to_cur'] && $r['acc_cur'] !== $r['to_cur']) {
        $crossCurrency[] = $r;
    }
}

$leak = $totalDebit - $totalCredit; // should be 0 if fully reconciled
require 'header.php';
?>

<div class="card" style="margin-bottom:16px;">
  <div class="section-title" style="margin-bottom:12px;">🔍 Transfer Integrity Audit (Dr = Cr)</div>
  <div style="font-size:.84rem;color:var(--muted);">
    Checks every transfer transaction system-wide. Read-only — changes nothing.
  </div>
</div>

<div class="g3" style="margin-bottom:16px;">
  <div class="card">
    <div class="card-title">Total Transfers (Debit legs)</div>
    <div class="card-value c-blue" data-hide="true"><?=money($totalDebit)?></div>
    <div class="card-sub"><?=count($rows)?> transfer txns</div>
  </div>
  <div class="card">
    <div class="card-title">Total Credited</div>
    <div class="card-value c-blue" data-hide="true"><?=money($totalCredit)?></div>
    <div class="card-sub"><?=count($rows)-count($orphaned)?> with a destination</div>
  </div>
  <div class="card">
    <div class="card-title">Leak (Dr − Cr)</div>
    <div class="card-value <?=abs($leak)<0.01?'c-green':'c-red'?>" data-hide="true"><?=money($leak)?></div>
    <div class="card-sub"><?=abs($leak)<0.01?'Balanced':'Money debited with no matching credit'?></div>
  </div>
</div>

<?php if($orphaned):?>
<div class="card" style="margin-bottom:16px;">
  <div class="section-title" style="margin-bottom:10px;color:var(--red);">⚠️ Orphaned Transfers (<?=count($orphaned)?>) — debited, never credited</div>
  <div style="font-size:.82rem;color:var(--muted);margin-bottom:10px;">
    These left the source account as a transfer but have no destination account on the row.
    The money is missing from the system's books. Fix by editing each one to add the correct
    destination, or convert it to an Expense if that's what it actually was.
  </div>
  <table class="tbl" style="font-size:.82rem;">
    <tr><th>Date</th><th>Account</th><th style="text-align:right;">Amount</th><th>Note</th><th>Action</th></tr>
    <?php foreach($orphaned as $r):?>
    <tr>
      <td style="white-space:nowrap;"><?=date('d M Y',strtotime($r['txn_date']))?></td>
      <td><?=htmlspecialchars($r['acc_name']??'—')?></td>
      <td style="text-align:right;color:var(--red);" data-hide="true"><?=money((float)$r['amount'], $r['currency'])?> <?=$r['currency']?></td>
      <td style="color:var(--muted);"><?=htmlspecialchars(substr($r['note']??'',0,40))?></td>
      <td><a href="edit_transaction.php?id=<?=$r['id']?>" class="btn btn-ghost btn-sm">Fix</a></td>
    </tr>
    <?php endforeach;?>
  </table>
</div>
<?php endif;?>

<?php if($badAccount):?>
<div class="card" style="margin-bottom:16px;">
  <div class="section-title" style="margin-bottom:10px;color:var(--orange);">⚠️ Transfers touching an inactive account (<?=count($badAccount)?>)</div>
  <table class="tbl" style="font-size:.82rem;">
    <tr><th>Date</th><th>From</th><th>To</th><th style="text-align:right;">Amount</th></tr>
    <?php foreach($badAccount as $r):?>
    <tr>
      <td style="white-space:nowrap;"><?=date('d M Y',strtotime($r['txn_date']))?></td>
      <td><?=htmlspecialchars($r['acc_name']??'—')?><?=$r['acc_active']?'':' (inactive)'?></td>
      <td><?=htmlspecialchars($r['to_acc_name']??'—')?><?=$r['to_active']?'':' (inactive)'?></td>
      <td style="text-align:right;" data-hide="true"><?=money((float)$r['amount'], $r['currency'])?> <?=$r['currency']?></td>
    </tr>
    <?php endforeach;?>
  </table>
</div>
<?php endif;?>

<?php if($crossCurrency):?>
<div class="card">
  <div class="section-title" style="margin-bottom:10px;color:var(--orange);">ℹ️ Cross-Currency Transfers (<?=count($crossCurrency)?>) — verify manually</div>
  <div style="font-size:.82rem;color:var(--muted);margin-bottom:10px;">
    The same raw amount is applied to both legs regardless of currency (e.g. a "50" leaving a BHD
    account also credits "50" to a BDT account). If these were meant to preserve value across the
    exchange rate rather than the raw number, they need a manual correction.
  </div>
  <table class="tbl" style="font-size:.82rem;">
    <tr><th>Date</th><th>From (cur)</th><th>To (cur)</th><th style="text-align:right;">Amount</th><th>Action</th></tr>
    <?php foreach($crossCurrency as $r):?>
    <tr>
      <td style="white-space:nowrap;"><?=date('d M Y',strtotime($r['txn_date']))?></td>
      <td><?=htmlspecialchars($r['acc_name']??'—')?> (<?=$r['acc_cur']?>)</td>
      <td><?=htmlspecialchars($r['to_acc_name']??'—')?> (<?=$r['to_cur']?>)</td>
      <td style="text-align:right;" data-hide="true"><?=money((float)$r['amount'])?></td>
      <td><a href="edit_transaction.php?id=<?=$r['id']?>" class="btn btn-ghost btn-sm">Review</a></td>
    </tr>
    <?php endforeach;?>
  </table>
</div>
<?php endif;?>

<?php if(!$orphaned && !$badAccount && !$crossCurrency):?>
<div class="card" style="text-align:center;padding:40px;color:var(--green);">
  ✅ All transfers are clean — every debit has a matching credit, same currency, active accounts.
</div>
<?php endif;?>

<?php require 'footer.php'; ?>
