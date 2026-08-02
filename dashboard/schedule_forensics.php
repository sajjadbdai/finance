<?php
/**
 * Scheduled Payment Forensics
 * Read-only. Changes nothing. Prints review-only SQL, never executes it.
 *
 * METHOD (deliberately independent of any assumed "true opening balance"):
 *
 *   occurrences_done was incremented by BOTH runners every time a schedule
 *   fired, and was never decremented when duplicate transactions were later
 *   deleted. So for each schedule:
 *
 *       surviving = count of [Auto] transactions still in the table that
 *                   match this schedule's account/type/amount
 *       deleted   = occurrences_done − surviving   (never < 0)
 *       correction owed = deleted × amount, applied back to whichever
 *                   accounts the schedule touches
 *
 * This does NOT rely on knowing what any account's opening balance "should"
 * have been — it only uses two things that are still on the server right
 * now: the occurrences_done counter and the surviving transaction rows.
 *
 * NOTE ON MATCHING: dashboard/scheduled.php tags its own inserts with
 * source='schedule' (singular) while cron/scheduled_runner.php uses
 * source='scheduled'. This tool matches on account_id + type + amount +
 * to_account_id + note LIKE '[Auto]%' instead of relying on `source`,
 * since the two runners disagree on that column.
 */
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Scheduled Payment Forensics'; $activePage='scheduled'; $backTo='balance_tools.php';

$schedules = db()->query(
    "SELECT s.*, a.name as acc_name, a.currency as acc_cur,
            b.name as to_acc_name, b.currency as to_cur
     FROM scheduled_payments s
     LEFT JOIN accounts a ON a.id=s.account_id
     LEFT JOIN accounts b ON b.id=s.to_account_id
     ORDER BY s.id"
)->fetchAll();

$report = [];
foreach ($schedules as $s) {
    $sql = "SELECT * FROM transactions
            WHERE account_id=? AND type=? AND ROUND(amount,2)=ROUND(?,2)
              AND note LIKE '[Auto]%'";
    $params = [$s['account_id'], $s['type'], $s['amount']];
    if ($s['to_account_id']) { $sql .= " AND to_account_id=?"; $params[] = $s['to_account_id']; }
    else                     { $sql .= " AND (to_account_id IS NULL OR to_account_id=0)"; }
    $sql .= " ORDER BY txn_date ASC";

    $st = db()->prepare($sql);
    $st->execute($params);
    $surviving = $st->fetchAll();

    $doneCounter = (int)($s['occurrences_done'] ?? 0);
    $survivingCount = count($surviving);
    $deleted = max(0, $doneCounter - $survivingCount);

    $report[] = [
        's' => $s,
        'surviving' => $surviving,
        'surviving_count' => $survivingCount,
        'occurrences_done' => $doneCounter,
        'deleted' => $deleted,
        'correction' => $deleted * (float)$s['amount'],
    ];
}

usort($report, fn($a,$b) => $b['deleted'] <=> $a['deleted']);
$totalFlagged = array_sum(array_map(fn($r)=>$r['deleted']>0?1:0, $report));
require 'header.php';
?>

<div class="card" style="margin-bottom:16px;">
  <div class="section-title" style="margin-bottom:12px;">🔬 Scheduled Payment Forensics</div>
  <div style="font-size:.84rem;color:var(--muted);line-height:1.6;">
    For every schedule: <strong>deleted = occurrences_done − surviving [Auto] transactions</strong>.
    This uses only data still on the server right now — it does not assume any account's
    "true" opening balance. Read-only. Only ever prints SQL for you to review yourself.
  </div>
</div>

<div class="g3" style="margin-bottom:16px;">
  <div class="card">
    <div class="card-title">Schedules Checked</div>
    <div class="card-value c-blue"><?=count($report)?></div>
  </div>
  <div class="card">
    <div class="card-title">Schedules With Gap</div>
    <div class="card-value <?=$totalFlagged>0?'c-red':'c-green'?>"><?=$totalFlagged?></div>
  </div>
  <div class="card">
    <div class="card-title">Total Correction (native, unmixed currencies)</div>
    <div class="card-value c-orange" data-hide="true"><?=money(array_sum(array_column($report,'correction')))?></div>
  </div>
</div>

<?php foreach($report as $r): $s=$r['s']; $flag = $r['deleted'] > 0; ?>
<div class="card" style="margin-bottom:14px;<?=$flag?'border:1px solid var(--red);':''?>">
  <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
    <div>
      <div style="font-weight:700;"><?=htmlspecialchars($s['name'])?> <span class="c-muted" style="font-weight:400;font-size:.8rem;">#<?=$s['id']?></span></div>
      <div style="font-size:.8rem;color:var(--muted);">
        <?=ucfirst($s['type'])?> · <?=money((float)$s['amount'], $s['currency'])?> <?=$s['currency']?> ·
        <?=htmlspecialchars($s['acc_name']??'?')?><?=$s['to_acc_name']?' → '.htmlspecialchars($s['to_acc_name']):''?>
        · <?=ucfirst($s['frequency'])?>
      </div>
    </div>
    <div style="text-align:right;">
      <div style="font-size:.78rem;color:var(--muted);">occurrences_done: <?=$r['occurrences_done']?> &nbsp;|&nbsp; surviving: <?=$r['surviving_count']?></div>
      <?php if($flag):?>
      <div class="c-red" style="font-weight:700;">⚠️ <?=$r['deleted']?> missing → correction <?=money($r['correction'], $s['currency'])?> <?=$s['currency']?></div>
      <?php else:?>
      <div class="c-green" style="font-weight:600;">✅ accounted for</div>
      <?php endif;?>
    </div>
  </div>

  <?php if($flag):?>
  <hr class="divider">
  <div style="font-size:.78rem;color:var(--muted);margin-bottom:6px;">
    Suggested correction (verify against a real bank statement before running):
  </div>
  <?php if($s['type']==='transfer' && $s['to_account_id']):?>
    <code style="display:block;font-size:.74rem;margin-bottom:4px;">UPDATE accounts SET balance=balance+<?=number_format($r['correction'],4,'.','')?> WHERE id=<?=$s['account_id']?>; -- <?=htmlspecialchars($s['acc_name'])?> (money never left, restore it)</code>
    <code style="display:block;font-size:.74rem;">UPDATE accounts SET balance=balance-<?=number_format($r['correction'],4,'.','')?> WHERE id=<?=$s['to_account_id']?>; -- <?=htmlspecialchars($s['to_acc_name'])?> (never arrived, remove it)</code>
  <?php elseif($s['type']==='expense'):?>
    <code style="display:block;font-size:.74rem;">UPDATE accounts SET balance=balance+<?=number_format($r['correction'],4,'.','')?> WHERE id=<?=$s['account_id']?>; -- <?=htmlspecialchars($s['acc_name'])?> (expense effect never reversed on delete)</code>
  <?php elseif($s['type']==='income'):?>
    <code style="display:block;font-size:.74rem;">UPDATE accounts SET balance=balance-<?=number_format($r['correction'],4,'.','')?> WHERE id=<?=$s['account_id']?>; -- <?=htmlspecialchars($s['acc_name'])?> (income effect never reversed on delete)</code>
  <?php else:?>
    <span class="c-orange">Transfer with no to_account_id — check manually, can't auto-suggest.</span>
  <?php endif;?>
  <?php endif;?>

  <?php if($r['surviving']):?>
  <details style="margin-top:10px;">
    <summary style="font-size:.78rem;color:var(--muted);cursor:pointer;">Show <?=$r['surviving_count']?> surviving [Auto] transaction(s) — amount vs amount_bhd</summary>
    <table class="tbl" style="font-size:.76rem;margin-top:6px;">
      <tr><th>Date</th><th style="text-align:right;">amount</th><th style="text-align:right;">amount_bhd</th><th>source tag</th></tr>
      <?php foreach($r['surviving'] as $t):
        $mismatch = $t['currency']!=='BHD' && abs((float)$t['amount'] - (float)$t['amount_bhd']) < 0.005;
      ?>
      <tr>
        <td><?=date('d M Y',strtotime($t['txn_date']))?></td>
        <td style="text-align:right;" data-hide="true"><?=money((float)$t['amount'], $t['currency'])?> <?=$t['currency']?></td>
        <td style="text-align:right;<?=$mismatch?'color:var(--red);':''?>" data-hide="true">
          <?=money((float)$t['amount_bhd'])?><?=$mismatch?' ⚠️ looks unconverted':''?>
        </td>
        <td class="c-muted"><?=htmlspecialchars($t['source'])?></td>
      </tr>
      <?php endforeach;?>
    </table>
  </details>
  <?php endif;?>
</div>
<?php endforeach;?>

<?php if(!$report):?>
<div class="card" style="text-align:center;padding:40px;color:var(--muted);">No scheduled payments found.</div>
<?php endif;?>

<div class="card" style="margin-top:8px;">
  <div style="font-size:.8rem;color:var(--muted);line-height:1.7;">
    <strong>Before running anything:</strong> this matches surviving transactions by
    account + type + amount + destination, not by <code>source</code>, because your two
    runners tag that column differently (<code>scheduled.php</code> writes
    <code>'schedule'</code>, <code>cron/scheduled_runner.php</code> writes <code>'scheduled'</code>).
    If a schedule's amount was ever edited after some payments already ran, older
    surviving rows at the old amount won't match and will inflate the "deleted" count —
    check the surviving-transactions list for each flagged schedule before applying its fix.
  </div>
</div>

<?php require 'footer.php'; ?>
