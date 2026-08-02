<?php
/**
 * Cash Flow Statement
 * Read-only. Classifies every transaction in a period into:
 *   Operating  — regular income/expense (everything except investing)
 *   Investing  — Stock Purchase (cash out) / Stock Sale (cash in)
 *   Transfers  — money moved between your own accounts (net-zero
 *                system-wide, shown for visibility, not counted in
 *                the "change in cash" total since it doesn't change
 *                YOUR total cash, just which account holds it)
 */
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Cash Flow Statement'; $activePage='reports'; $backTo='reports.php';

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

$st = db()->prepare("SELECT * FROM transactions WHERE DATE(txn_date) BETWEEN ? AND ? ORDER BY txn_date");
$st->execute([$from, $to]);
$txns = $st->fetchAll();

$operatingIn = 0; $operatingOut = 0; $investingIn = 0; $investingOut = 0; $transferVolume = 0; $adjustmentVolume = 0;
$opRows = []; $invRows = [];

foreach ($txns as $t) {
    $v = toBHD((float)$t['amount'], $t['currency']);
    $isInvesting = ($t['category'] === 'Investment' && in_array($t['subcategory'], ['Stock Purchase','Stock Sale']))
                || ($t['category'] === 'Fixed Asset' && in_array($t['subcategory'], ['Asset Purchase','Asset Sale']));

    if ($t['type'] === 'transfer') {
        $transferVolume += $v;
    } elseif ($t['category'] === 'Adjustment') {
        // One-time balance corrections aren't real cash flow — they didn't
        // come from anywhere or go anywhere, they just fixed a number that
        // had drifted. Tracked separately, excluded from Net Change.
        $adjustmentVolume += $v;
    } elseif ($isInvesting) {
        if (in_array($t['subcategory'], ['Stock Purchase','Asset Purchase'])) { $investingOut += $v; $invRows[] = ['d'=>$t['txn_date'],'desc'=>$t['note'],'v'=>-$v]; }
        else                                                                  { $investingIn  += $v; $invRows[] = ['d'=>$t['txn_date'],'desc'=>$t['note'],'v'=>$v]; }
    } else {
        if ($t['type'] === 'income')  { $operatingIn += $v;  $opRows[] = ['d'=>$t['txn_date'],'desc'=>($t['category']?:'Income').($t['note']?' — '.$t['note']:''),'v'=>$v]; }
        if ($t['type'] === 'expense') { $operatingOut += $v; $opRows[] = ['d'=>$t['txn_date'],'desc'=>($t['category']?:'Expense').($t['note']?' — '.$t['note']:''),'v'=>-$v]; }
    }
}

$netOperating = $operatingIn - $operatingOut;
$netInvesting = $investingIn - $investingOut;
$netChange    = $netOperating + $netInvesting;

require 'header.php';
?>

<div class="no-print" style="text-align:right;margin-bottom:12px;">
  <button onclick="window.print()" class="btn btn-ghost btn-sm">🖨️ Print / Save as PDF</button>
</div>


<div class="card" style="margin-bottom:16px;">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <div><div class="form-label">From</div><input type="date" class="form-control" name="from" value="<?=htmlspecialchars($from)?>"></div>
    <div><div class="form-label">To</div><input type="date" class="form-control" name="to" value="<?=htmlspecialchars($to)?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
  </form>
</div>

<div class="g3" style="margin-bottom:16px;">
  <div class="card"><div class="card-title">Net Operating Cash Flow</div><div class="card-value <?=$netOperating>=0?'c-green':'c-red'?>" data-hide="true">BD <?=money($netOperating)?></div></div>
  <div class="card"><div class="card-title">Net Investing Cash Flow</div><div class="card-value <?=$netInvesting>=0?'c-green':'c-red'?>" data-hide="true">BD <?=money($netInvesting)?></div></div>
  <div class="card"><div class="card-title">Net Change in Cash</div><div class="card-value <?=$netChange>=0?'c-green':'c-red'?>" data-hide="true">BD <?=money($netChange)?></div></div>
</div>

<div class="card" style="padding:0;overflow:hidden;margin-bottom:16px;">
  <div style="padding:12px 18px;background:var(--s2);font-weight:700;">💼 Operating Activities</div>
  <div class="tbl-wrap"><table class="tbl" style="font-size:.82rem;">
    <tr><th>Date</th><th>Description</th><th style="text-align:right;">Amount (BD)</th></tr>
    <?php foreach($opRows as $r):?>
    <tr>
      <td style="white-space:nowrap;"><?=date('d M Y',strtotime($r['d']))?></td>
      <td class="c-muted"><?=htmlspecialchars(substr($r['desc'],0,50))?></td>
      <td style="text-align:right;" class="<?=$r['v']>=0?'c-green':'c-red'?>" data-hide="true"><?=$r['v']>=0?'+':''?><?=money($r['v'])?></td>
    </tr>
    <?php endforeach;?>
    <tr style="font-weight:700;border-top:1px solid var(--s3);"><td colspan="2">Net Operating</td><td style="text-align:right;" data-hide="true"><?=money($netOperating)?></td></tr>
  </table></div>
</div>

<div class="card" style="padding:0;overflow:hidden;margin-bottom:16px;">
  <div style="padding:12px 18px;background:var(--s2);font-weight:700;">📈 Investing Activities (stock trades)</div>
  <div class="tbl-wrap"><table class="tbl" style="font-size:.82rem;">
    <tr><th>Date</th><th>Description</th><th style="text-align:right;">Amount (BD)</th></tr>
    <?php foreach($invRows as $r):?>
    <tr>
      <td style="white-space:nowrap;"><?=date('d M Y',strtotime($r['d']))?></td>
      <td class="c-muted"><?=htmlspecialchars(substr($r['desc'],0,50))?></td>
      <td style="text-align:right;" class="<?=$r['v']>=0?'c-green':'c-red'?>" data-hide="true"><?=$r['v']>=0?'+':''?><?=money($r['v'])?></td>
    </tr>
    <?php endforeach;?>
    <tr style="font-weight:700;border-top:1px solid var(--s3);"><td colspan="2">Net Investing</td><td style="text-align:right;" data-hide="true"><?=money($netInvesting)?></td></tr>
  </table></div>
</div>

<div class="card">
  <div style="font-size:.82rem;color:var(--muted);">
    Transfer volume between your own accounts this period: <strong data-hide="true">BD <?=money($transferVolume)?></strong>
    (moved between accounts, not counted in Net Change since it doesn't change your total cash — just which
    account holds it).<br><br>
    Balance-correction adjustments this period: <strong data-hide="true">BD <?=money($adjustmentVolume)?></strong>
    (one-time fixes to a drifted balance — not real cash flow, so also excluded from Net Change).
  </div>
</div>

<?php require 'footer.php'; ?>
