<?php
/**
 * Opening Balance Reconstruction — Checkpoint Reconciliation
 *
 * Uses a real balance_checkpoints table (one row per account) instead
 * of a hardcoded array. This means the checkpoint can be RESET any
 * time you've manually verified every balance is correct — like after
 * a full manual fix-up pass — rather than being stuck comparing
 * against one snapshot in time forever.
 *
 *   expected_today = checkpoint_balance + net(transactions strictly after
 *   the exact checkpoint moment, not just the checkpoint date — using
 *   date-only precision would double-count any transaction recorded
 *   earlier the same day the checkpoint was set, since that transaction's
 *   effect is already baked into the checkpoint balance itself)
 *   drift          = stored_today − expected_today
 *
 * "Set Today as New Checkpoint" is the ONE action on this page that
 * writes anything — everything else is read-only, generating SQL for
 * you to review, not executing it.
 *
 * Any account with no checkpoint row yet falls back to the original
 * 18-June-2026 hardcoded values (transcribed from screenshots, before
 * the scheduled-payment incident) — kept only as a fallback so nothing
 * regresses for accounts you haven't re-baselined.
 */
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Opening Balance Reconstruction'; $activePage='accounts'; $backTo='balance_tools.php';

// ── Action: set today's balances as the new checkpoint for every active account ──
$setMsg = '';
if (isset($_POST['set_checkpoint']) && isset($_POST['confirm'])) {
    $accs = db()->query("SELECT id,balance,currency FROM accounts WHERE is_active=1")->fetchAll();
    $today = date('Y-m-d H:i:s'); // exact moment, not just the date — see fix note below
    $n = 0;
    foreach ($accs as $a) {
        db()->prepare(
            "INSERT INTO balance_checkpoints (account_id,checkpoint_date,balance,currency)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE checkpoint_date=VALUES(checkpoint_date), balance=VALUES(balance), currency=VALUES(currency)"
        )->execute([$a['id'], $today, $a['balance'], $a['currency']]);
        $n++;
    }
    $setMsg = "Checkpoint set for {$n} accounts as of {$today}. Balance corrections from before today no longer show as drift — only anything that changes without a transaction FROM NOW ON will.";
}

// ── Fallback: the original 18-June-2026 hardcoded checkpoint ──────────
$FALLBACK_DATE = '2026-06-18';
$fallbackCheckpoint = [
    'DBBL 13 months FD'                    => [50000.00, 'BDT'],
    'MTB  Credit card security'            => [400000.00,'BDT'],
    'MTB Credit card security'             => [400000.00,'BDT'],
    'Pubali'                                => [53538.00, 'BDT'],
    'City Bank FDR 6 month'                => [50000.00, 'BDT'],
    'Brac probashi fixed deposit'          => [100000.00,'BDT'],
    'Brac Wage earner Development'         => [100000.00,'BDT'],
    'Padma Bank Bond'                       => [100000.00,'BDT'],
    'Post Office Bonds 3 months interest'  => [500000.00,'BDT'],
    'Brac EPL ODS622'                      => [65000.00, 'BDT'],
    'Berich 179387'                        => [60000.00, 'BDT'],
    'Binance FX'                            => [20.00,    'BHD'],
    'Cash In Hand'                          => [94.52,    'BHD'],
    'AL Salam'                              => [792.35,   'BHD'],
    'BBK'                                   => [2097.42,  'BHD'],
    'BisB'                                  => [449.40,   'BHD'],
    'ILA'                                   => [24.71,    'BHD'],
    'KFH'                                   => [310.86,   'BHD'],
    'NBB'                                   => [217.30,   'BHD'],
    'SC'                                    => [170.00,   'BHD'],
    'STC and Beyon'                        => [70.87,    'BHD'],
    'Al Salam Danat'                        => [100.43,   'BHD'],
    'ILA Kanz'                               => [250.00,   'BHD'],
    'Bangladesh School'                     => [100.00,   'BHD'],
    'EWA Deposit Sitra'                     => [100.00,   'BHD'],
    'GOSI Deposit'                          => [1140.00,  'BHD'],
    'To friends and family'                => [0.00,     'BHD'],
    'Kapil Bhi'                             => [100000.00,'BDT'],
    'Jashim BFC'                            => [25.00,    'BHD'],
    'Jahangir Bhi'                          => [100.00,   'BHD'],
    'নোয়াখালী ফোরাম'                        => [944.30,   'BHD'],
    'নোয়াখালী সোসাইটি'                       => [404.28,   'BHD'],
    'Liability GNF and GNS'                => [-1348.58, 'BHD'],
    'Al Salam Platinum'                     => [-5.00,    'BHD'],
    'BisB Signature VISA 1000'             => [-48.34,   'BHD'],
    'Credimax Talabat'                      => [-348.96,  'BHD'],
    'ILA Platinum Master'                   => [0.00,     'BHD'],
    'Imtiaz Platinum Master'               => [-83.92,   'BHD'],
    'MTB Signature Visa Tk 300000'         => [0.00,     'BDT'],
    'Brac Bank'                             => [36119.95, 'BDT'],
    'City Bank'                              => [13264.85, 'BDT'],
    'DBBL'                                   => [33906.26, 'BDT'],
    'IBBL With Cellfin'                     => [7151.44,  'BDT'],
    'Midland'                                => [43715.80, 'BDT'],
];

function effect(array $t, int $accountId): float {
    $amt = (float)$t['amount'];
    if ((int)$t['account_id'] === $accountId) {
        if ($t['type']==='income')  return  $amt;
        if ($t['type']==='expense') return -$amt;
        if ($t['type']==='transfer') return -$amt;
    }
    if ((int)$t['to_account_id'] === $accountId && $t['type']==='transfer') return (function_exists('toAccountAmount') ? toAccountAmount($amt, $t['currency'] ?? 'BHD', $accountId) : $amt);
    return 0.0;
}

$accounts = db()->query("SELECT id,name,currency,balance,is_credit_card,is_active FROM accounts WHERE is_active=1")->fetchAll();
$checkpointRows = db()->query("SELECT * FROM balance_checkpoints")->fetchAll();
$checkpointByAccount = [];
foreach ($checkpointRows as $c) $checkpointByAccount[(int)$c['account_id']] = $c;

$results = [];
$unmatched = [];
foreach ($accounts as $acc) {
    $aid = (int)$acc['id'];
    $source = null; $cbal = null; $cdate = null;

    if (isset($checkpointByAccount[$aid])) {
        $source = 'set';
        $cbal   = (float)$checkpointByAccount[$aid]['balance'];
        $cdate  = $checkpointByAccount[$aid]['checkpoint_date'];
    } elseif (isset($fallbackCheckpoint[trim($acc['name'])])) {
        $source = 'fallback';
        [$cbal, ] = $fallbackCheckpoint[trim($acc['name'])];
        $cdate  = $FALLBACK_DATE;
    } else {
        $unmatched[] = $acc['name'];
        continue;
    }

    $st = db()->prepare("SELECT * FROM transactions WHERE (account_id=? OR to_account_id=?) AND txn_date > ? ORDER BY txn_date ASC, id ASC");
    $st->execute([$aid, $aid, $cdate]);
    $txns = $st->fetchAll();
    $net = 0.0;
    foreach ($txns as $t) $net += effect($t, $aid);

    $expected = $cbal + $net;
    $actual   = (float)$acc['balance'];
    $drift    = $actual - $expected;

    $results[] = [
        'id'=>$aid, 'name'=>$acc['name'], 'currency'=>$acc['currency'], 'source'=>$source, 'checkpoint_date'=>$cdate,
        'checkpoint'=>$cbal, 'net_since'=>$net, 'expected'=>$expected,
        'actual'=>$actual, 'drift'=>$drift, 'txn_count'=>count($txns),
    ];
}
usort($results, fn($a,$b) => abs($b['drift']) <=> abs($a['drift']));
$totalDrift = array_sum(array_map(fn($r)=>abs($r['drift']) > 0.02 ? 1 : 0, $results));
$setCount = count($checkpointByAccount);

require 'header.php';
?>
<div class="no-print" style="text-align:right;margin-bottom:12px;">
  <button onclick="window.print()" class="btn btn-ghost btn-sm">🖨️ Print / Save as PDF</button>
</div>

<div class="card" style="margin-bottom:16px;">
  <div class="section-title" style="margin-bottom:12px;">🧭 Opening Balance Reconstruction — Checkpoint Reconciliation</div>
  <div style="font-size:.84rem;color:var(--muted);line-height:1.6;">
    <strong>Expected Today = Checkpoint + everything recorded since.</strong> Where that doesn't match the
    actual stored balance, the difference is real drift. Everything on this page is read-only EXCEPT the
    button below.
  </div>
</div>

<?php if($setMsg):?><div class="alert alert-success">✅ <?=htmlspecialchars($setMsg)?></div><?php endif;?>

<div class="card no-print" style="margin-bottom:16px;border:1px solid var(--blue);">
  <div class="section-title" style="margin-bottom:8px;">📌 Set Today as New Checkpoint</div>
  <div style="font-size:.82rem;color:var(--muted);margin-bottom:10px;">
    Only do this once you've verified every account balance is actually correct — like right after a
    manual fix-up pass. This snapshots EVERY active account's current balance as today's checkpoint,
    replacing whatever checkpoint it had before. From then on, this page only flags drift that happens
    <em>after</em> today — the old correction noise stops mattering.
  </div>
  <form method="POST" onsubmit="return confirm('Snapshot all account balances as of today as the new checkpoint? Only do this if you\'ve just verified every balance is correct.');">
    <input type="hidden" name="set_checkpoint" value="1">
    <input type="hidden" name="confirm" value="1">
    <button type="submit" class="btn btn-primary btn-sm">📌 Set Today (<?=date('d M Y')?>) as New Checkpoint for All Accounts</button>
  </form>
  <div style="font-size:.78rem;color:var(--muted);margin-top:8px;"><?=$setCount?> of <?=count($accounts)?> accounts currently have a checkpoint set this way; the rest still use the 18-June-2026 fallback below.</div>
</div>

<?php if($unmatched):?>
<div class="card" style="margin-bottom:16px;border:1px solid var(--orange);">
  <div class="section-title" style="color:var(--orange);margin-bottom:8px;">⚠️ No checkpoint at all for <?=count($unmatched)?> account(s)</div>
  <div style="font-size:.82rem;color:var(--muted);">No row in balance_checkpoints, and no exact match in the 18-June fallback list:</div>
  <ul style="font-size:.82rem;margin-top:8px;"><?php foreach($unmatched as $u):?><li><?=htmlspecialchars($u)?></li><?php endforeach;?></ul>
  <div style="font-size:.82rem;color:var(--muted);margin-top:8px;">Click "Set Today as New Checkpoint" above to cover these too.</div>
</div>
<?php endif;?>

<div class="g3" style="margin-bottom:16px;">
  <div class="card"><div class="card-title">Accounts Checked</div><div class="card-value c-blue"><?=count($results)?></div></div>
  <div class="card"><div class="card-title">Accounts With Drift</div><div class="card-value <?=$totalDrift>0?'c-red':'c-green'?>"><?=$totalDrift?></div></div>
  <div class="card"><div class="card-title">Using 18-June Fallback</div><div class="card-value <?=count($accounts)-$setCount>0?'c-orange':'c-green'?>"><?=count($accounts)-$setCount?></div></div>
</div>

<div class="card" style="padding:0;overflow:hidden;">
  <div style="padding:12px 18px;background:var(--s2);font-weight:600;">Per-Account Reconciliation (sorted by largest drift first)</div>
  <div class="tbl-wrap"><table class="tbl" style="font-size:.8rem;">
    <tr>
      <th>Account</th><th>Checkpoint</th><th style="text-align:right;">Balance</th>
      <th style="text-align:right;">Net Since</th><th style="text-align:right;">Expected Today</th>
      <th style="text-align:right;">Stored Today</th><th style="text-align:right;">Drift</th><th>Fix SQL</th>
    </tr>
    <?php foreach($results as $r): $bad = abs($r['drift']) > 0.02; ?>
    <tr style="<?=$bad?'background:var(--red)11;':''?>">
      <td><a href="account_detail.php?id=<?=$r['id']?>"><?=htmlspecialchars($r['name'])?></a></td>
      <td class="c-muted" style="font-size:.72rem;"><?=$r['source']==='set'?'✅ '.date('d M Y H:i',strtotime($r['checkpoint_date'])):'⚠️ 18 Jun fallback'?></td>
      <td style="text-align:right;" data-hide="true"><?=money($r['checkpoint'])?></td>
      <td style="text-align:right;" data-hide="true"><?=money($r['net_since'])?> <span class="c-muted" style="font-size:.7rem;">(<?=$r['txn_count']?>)</span></td>
      <td style="text-align:right;" data-hide="true"><?=money($r['expected'])?></td>
      <td style="text-align:right;" data-hide="true"><?=money($r['actual'])?></td>
      <td style="text-align:right;font-weight:700;" class="<?=$bad?'c-red':'c-green'?>" data-hide="true"><?=money($r['drift'])?></td>
      <td style="font-size:.7rem;color:var(--muted);">
        <?php if($bad):?><code>UPDATE accounts SET balance=<?=number_format($r['expected'],4,'.','')?> WHERE id=<?=$r['id']?>;</code><?php else: ?>—<?php endif;?>
      </td>
    </tr>
    <?php endforeach;?>
  </table></div>
</div>

<?php require 'footer.php'; ?>
