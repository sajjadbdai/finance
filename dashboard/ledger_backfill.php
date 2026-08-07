<?php
/**
 * Ledger Backfill — one-time (but safe to re-run)
 *
 * The double-entry posting in add_transaction.php, trade_stock.php,
 * account_detail.php and edit_account.php only fires for NEW activity
 * from the moment those files are deployed. Every transaction that
 * already existed before that has no ledger_entries rows, so the
 * trial balance would look incomplete/wrong for historical data.
 *
 * This walks every existing transaction once and posts the matching
 * double-entry legs, using the exact same classification rules as the
 * live code:
 *   - category = 'Investment', subcategory = 'Stock Purchase' → postStockBuy
 *   - category = 'Investment', subcategory = 'Stock Sale'     → postStockSell
 *       (cost basis recovered by parsing "Realized P/L: ±X" out of the
 *       note — trade_stock.php always writes it in that exact format)
 *   - everything else: income → postIncome, expense → postExpense,
 *     transfer → postTransfer
 *
 * IDEMPOTENT: skips any transaction that already has ledger_entries
 * rows (txn_id already present), so it's safe to run multiple times —
 * e.g. run it now, then again later after fixing a few transactions.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ledger.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Ledger Backfill'; $activePage='accounts'; $backTo='balance_tools.php';

$run = isset($_GET['run']);
$log = [];
$posted = 0; $skipped = 0; $errors = 0;

if ($run) {
    $already = db()->query("SELECT DISTINCT txn_id FROM ledger_entries WHERE txn_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $alreadySet = array_flip($already);

    $txns = db()->query("SELECT * FROM transactions ORDER BY txn_date ASC, id ASC")->fetchAll();
    foreach ($txns as $t) {
        $tid = (int)$t['id'];
        if (isset($alreadySet[$tid])) { $skipped++; continue; }

        try {
            $desc = ucfirst($t['type']) . ($t['category'] ? " — {$t['category']}" : '') . ($t['note'] ? " — {$t['note']}" : '');

            if ($t['category'] === 'Investment' && $t['subcategory'] === 'Stock Purchase') {
                postStockBuy($tid, $t['txn_date'], $desc, (int)$t['account_id'], (float)$t['amount'], $t['currency']);
            } elseif ($t['category'] === 'Investment' && $t['subcategory'] === 'Stock Sale') {
                // Recover cost basis from the note: "Realized P/L: +123.45" or "-123.45"
                $realized = 0.0;
                if (preg_match('/Realized P\/L:\s*([+-]?[\d.]+)/', $t['note'] ?? '', $m)) {
                    $realized = (float)$m[1];
                }
                $proceeds = (float)$t['amount'];
                $cost     = $proceeds - $realized;
                postStockSell($tid, $t['txn_date'], $desc, (int)$t['account_id'], $proceeds, $cost, $t['currency']);
            } elseif ($t['type'] === 'income') {
                postIncome($tid, $t['txn_date'], $desc, (int)$t['account_id'], (float)$t['amount'], $t['currency']);
            } elseif ($t['type'] === 'expense') {
                postExpense($tid, $t['txn_date'], $desc, (int)$t['account_id'], (float)$t['amount'], $t['currency']);
            } elseif ($t['type'] === 'transfer' && $t['to_account_id']) {
                postTransfer($tid, $t['txn_date'], $desc, (int)$t['account_id'], (int)$t['to_account_id'], (float)$t['amount'], $t['currency']);
            } else {
                $skipped++;
                continue;
            }
            $posted++;
        } catch (Exception $e) {
            $errors++;
            $log[] = "❌ txn #{$tid}: " . $e->getMessage();
        }
    }
}

require 'header.php';
?>

<div class="card" style="margin-bottom:16px;">
  <div class="section-title" style="margin-bottom:12px;">📚 Ledger Backfill</div>
  <div style="font-size:.84rem;color:var(--muted);line-height:1.6;">
    Generates double-entry ledger_entries for every existing transaction that doesn't have any yet.
    Safe to re-run — already-posted transactions are skipped automatically. Run this once after
    deploying the double-entry files, before trusting the Trial Balance's "true zero-sum" check.
  </div>
</div>

<?php if(!$run):?>
<div class="card" style="text-align:center;padding:30px;">
  <a href="ledger_backfill.php?run=1" class="btn btn-primary" onclick="return confirm('This will insert ledger entries for every existing transaction that doesn\'t have any yet. Continue?')">▶ Run Backfill</a>
</div>
<?php else: ?>
<div class="g3" style="margin-bottom:16px;">
  <div class="card"><div class="card-title">Posted</div><div class="card-value c-green"><?=$posted?></div></div>
  <div class="card"><div class="card-title">Already Had Entries (Skipped)</div><div class="card-value c-blue"><?=$skipped?></div></div>
  <div class="card"><div class="card-title">Errors</div><div class="card-value <?=$errors>0?'c-red':'c-green'?>"><?=$errors?></div></div>
</div>
<?php if($log):?>
<div class="card">
  <div class="section-title" style="margin-bottom:8px;">Errors</div>
  <div style="font-size:.8rem;font-family:monospace;">
    <?php foreach($log as $l):?><div><?=htmlspecialchars($l)?></div><?php endforeach;?>
  </div>
</div>
<?php endif;?>
<div class="card" style="margin-top:16px;text-align:center;">
  <a href="trial_balance.php" class="btn btn-primary">View Trial Balance →</a>
</div>
<?php endif;?>

<?php require 'footer.php'; ?>
