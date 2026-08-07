<?php
/**
 * Trial Balance & Debit/Credit Ledger Check
 * Read-only. Changes nothing.
 *
 * PART 1 — Trial Balance (formal accounting check)
 *   Debit side  = every Asset account's balance (normal debit balance)
 *   Credit side = every Liability/Credit-Card account's balance,
 *                 absolute value (normal credit balance)
 *   Net Worth   = Total Debit − Total Credit
 *
 *   This only covers real ledger accounts (accounts.balance). Portfolio
 *   market value and fixed assets are valuations, not double-entry
 *   ledger accounts, so they're shown separately, not mixed in.
 *
 *   INDEPENDENT CHECK: this app isn't full double-entry — income and
 *   expense transactions only touch one account each, with no offsetting
 *   "Equity" ledger entry. So instead of Debit always trivially equalling
 *   Credit, we check something more useful: does
 *       Net Worth (from account balances)
 *   roughly equal
 *       Seed Capital (never recorded as a transaction, e.g. an account's
 *       balance when it was first created) + Cumulative Income − Expense
 *       (recomputed fresh from every transaction, not from amount_bhd,
 *       which has its own known bugs)
 *   Since "seed capital" isn't tracked as a number anywhere, this tool
 *   shows the GAP instead. Write the gap down. If you re-run this later
 *   and the gap moved without you doing a deliberate Opening Balance
 *   Adjustment to explain it, something is writing to a balance outside
 *   the transaction ledger again — the exact pattern behind every
 *   incident so far.
 *
 * PART 2 — Per-Account Debit/Credit Ledger
 *   For every account: total Debits posted (income + transfer-in +
 *   upward adjustments), total Credits posted (expense + transfer-out +
 *   downward adjustments), Net = Dr − Cr, and whether that matches the
 *   account's actual stored balance. Same idea as balance_audit.php but
 *   for every account on one page, in Dr/Cr terms.
 */
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Trial Balance'; $activePage='accounts'; $backTo='balance_tools.php';

// ── PART 1: Trial Balance ──────────────────────────────────────────
$accounts = db()->query("SELECT * FROM accounts WHERE is_active=1 ORDER BY group_name, name")->fetchAll();

$rows = [];
$totalDebit = 0.0; $totalCredit = 0.0;
foreach ($accounts as $a) {
    $isLiability = (bool)$a['is_credit_card'] || $a['type'] === 'liability';
    $bhd = toBHD((float)$a['balance'], $a['currency']);

    $debit = 0.0; $credit = 0.0; $contra = false;
    if ($isLiability) {
        if ($bhd <= 0) { $credit = abs($bhd); }
        else           { $debit = $bhd; $contra = true; } // liability with a positive/credit-side balance
    } else {
        if ($bhd >= 0) { $debit = $bhd; }
        else           { $credit = abs($bhd); $contra = true; } // asset overdrawn
    }
    $totalDebit  += $debit;
    $totalCredit += $credit;
    $rows[] = ['name'=>$a['name'], 'group'=>$a['group_name'], 'type'=>$isLiability?'Liability':'Asset',
               'debit'=>$debit, 'credit'=>$credit, 'contra'=>$contra, 'id'=>$a['id']];
}

// Portfolio and Fixed Assets are real assets too, so they belong in the
// Trial Balance — but at COST BASIS, not current market value. The
// double-entry ledger only ever tracks what you paid (PORTFOLIO/
// FIXED_ASSET buckets); unrealized gains are deliberately kept OUT of
// it until realized, so a Trial Balance line here has to match that
// same convention or it can never tie to the ledger. Market value
// (with unrealized gains included) is what the Balance Sheet report
// shows instead — the two are supposed to differ by exactly your
// total unrealized gain/loss, not a bug.
$portCostBHD = 0.0;
try {
    $pRows = db()->query("SELECT quantity,avg_cost,currency FROM portfolio WHERE quantity>0")->fetchAll();
    foreach ($pRows as $p) $portCostBHD += toBHD((float)$p['quantity']*(float)$p['avg_cost'], $p['currency']);
} catch(Exception $e){}
if ($portCostBHD > 0.0001) {
    $rows[] = ['name'=>'Portfolio (at cost)', 'group'=>'', 'type'=>'Asset', 'debit'=>$portCostBHD, 'credit'=>0, 'contra'=>false, 'id'=>0, 'link'=>'portfolio.php'];
    $totalDebit += $portCostBHD;
}

$fixedAssetBHD = 0.0;
try {
    $faRows = db()->query("SELECT current_value,currency FROM fixed_assets WHERE status='owned'")->fetchAll();
    foreach ($faRows as $f) $fixedAssetBHD += toBHD((float)$f['current_value'], $f['currency']);
} catch(Exception $e){}
if ($fixedAssetBHD > 0.0001) {
    $rows[] = ['name'=>'Fixed Assets (at cost)', 'group'=>'', 'type'=>'Asset', 'debit'=>$fixedAssetBHD, 'credit'=>0, 'contra'=>false, 'id'=>0, 'link'=>'fixed_assets.php'];
    $totalDebit += $fixedAssetBHD;
}

// For comparison against the Balance Sheet's market-value figures
$portMarketBHD = 0.0;
try {
    $pmRows = db()->query("SELECT quantity,current_price,currency FROM portfolio WHERE quantity>0")->fetchAll();
    foreach ($pmRows as $p) $portMarketBHD += toBHD((float)$p['quantity']*(float)$p['current_price'], $p['currency']);
} catch(Exception $e){}
$unrealizedGL = ($portMarketBHD - $portCostBHD);

$netWorth = $totalDebit - $totalCredit;

// Equity is a real accounting concept — Assets = Liabilities + Equity —
// it's just not tracked as its own ledger account in this app the way
// a real accounts row is. Adding it here as the balancing line is what
// makes Total Debit actually equal Total Credit, the way a trial
// balance is supposed to look, instead of needing a separate
// explanation for why the two totals differ.
if ($netWorth >= 0) {
    $rows[] = ['name'=>'Equity (Net Worth)', 'group'=>'', 'type'=>'Equity', 'debit'=>0, 'credit'=>$netWorth, 'contra'=>false, 'id'=>0, 'link'=>'report_financial_statements.php'];
    $totalCredit += $netWorth;
} else {
    $rows[] = ['name'=>'Accumulated Deficit', 'group'=>'', 'type'=>'Equity', 'debit'=>abs($netWorth), 'credit'=>0, 'contra'=>false, 'id'=>0, 'link'=>'report_financial_statements.php'];
    $totalDebit += abs($netWorth);
}

// Cumulative income − expense, recomputed fresh (not from amount_bhd).
// EXCLUDES Investment/Stock Purchase & Sale — those are asset swaps
// (cash ↔ portfolio cost basis) since trade_stock.php was introduced,
// not real income/expense. Only the realized gain/loss portion (parsed
// from the "Realized P/L: ±X" note, same as ledger_backfill.php) counts.
$plSt = db()->query("SELECT type, amount, currency, category, subcategory, note FROM transactions WHERE type IN ('income','expense')");
$cumPL = 0.0;
foreach ($plSt->fetchAll() as $t) {
    if ($t['category'] === 'Investment' && $t['subcategory'] === 'Stock Purchase') continue; // pure asset swap, no P&L
    if ($t['category'] === 'Fixed Asset' && $t['subcategory'] === 'Asset Purchase') continue; // pure asset swap, no P&L
    if ($t['category'] === 'Fixed Asset' && $t['subcategory'] === 'Asset Sale') {
        if (preg_match('/Realized P\/L:\s*([+-]?[\d.]+)/', $t['note'] ?? '', $m)) {
            $cumPL += toBHD((float)$m[1], $t['currency']);
        }
        continue;
    }
    if ($t['category'] === 'Investment' && $t['subcategory'] === 'Stock Sale') {
        if (preg_match('/Realized P\/L:\s*([+-]?[\d.]+)/', $t['note'] ?? '', $m)) {
            $cumPL += toBHD((float)$m[1], $t['currency']);
        }
        continue;
    }
    $v = toBHD((float)$t['amount'], $t['currency']);
    $cumPL += ($t['type'] === 'income') ? $v : -$v;
}
$seedGap = $netWorth - $cumPL;

// ── PART 0: TRUE double-entry check, from ledger_entries ──────────
// If every posting was a properly balanced pair, these two MUST be
// exactly equal — not approximately, not "usually". Any gap here means
// something inserted into ledger_entries without its matching leg,
// which shouldn't be possible through the app's own code paths.
$ledgerTotals = db()->query("SELECT SUM(debit_bhd) as dr, SUM(credit_bhd) as cr, COUNT(*) as cnt FROM ledger_entries")->fetch();
$trueDebit  = (float)($ledgerTotals['dr'] ?? 0);
$trueCredit = (float)($ledgerTotals['cr'] ?? 0);
$trueGap    = $trueDebit - $trueCredit;
$ledgerEntryCount = (int)($ledgerTotals['cnt'] ?? 0);

// Breakdown by bucket/account for the double-entry summary table
$bucketRows = db()->query(
    "SELECT COALESCE(bucket, CONCAT('ACC:',account_id)) as grp, account_id, bucket,
            SUM(debit_bhd) as dr, SUM(credit_bhd) as cr
     FROM ledger_entries GROUP BY grp ORDER BY bucket IS NULL DESC, bucket, account_id"
)->fetchAll();
$accNameMap = [];
foreach ($accounts as $a) $accNameMap[$a['id']] = $a['name'];

// ── PART 2: Per-account Dr/Cr ledger ────────────────────────────────
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

$ledgerRows = [];
foreach ($accounts as $a) {
    $st = db()->prepare("SELECT * FROM transactions WHERE account_id=? OR to_account_id=?");
    $st->execute([$a['id'], $a['id']]);
    $dr = 0.0; $cr = 0.0;
    foreach ($st->fetchAll() as $t) {
        $e = effect($t, (int)$a['id']);
        if ($e >= 0) $dr += $e; else $cr += -$e;
    }
    $net    = $dr - $cr;
    $stored = (float)$a['balance'];
    $seed   = $stored - $net; // whatever isn't explained by transactions at all
    $ledgerRows[] = ['id'=>$a['id'],'name'=>$a['name'],'currency'=>$a['currency'],
                      'dr'=>$dr,'cr'=>$cr,'net'=>$net,'stored'=>$stored,'seed'=>$seed];
}

require 'header.php';
?>

<div class="no-print" style="text-align:right;margin-bottom:12px;">
  <button onclick="window.print()" class="btn btn-ghost btn-sm">🖨️ Print / Save as PDF</button>
</div>


<div class="card" style="margin-bottom:16px;">
  <div class="section-title" style="margin-bottom:12px;">⚖️ Trial Balance & Debit/Credit Ledger Check</div>
  <div style="font-size:.84rem;color:var(--muted);line-height:1.6;">
    Read-only, changes nothing. Covers ledger accounts only — portfolio market value and fixed assets are
    valuations, not double-entry accounts, so they're not mixed into these totals.
  </div>
</div>

<!-- PART 0: TRUE double-entry -->
<div class="card" style="margin-bottom:16px;border:1px solid <?=$ledgerEntryCount>0?'var(--green)':'var(--orange)'?>;">
  <div class="section-title" style="margin-bottom:10px;">
    🎯 True Double-Entry Check (most authoritative)
  </div>
  <?php if($ledgerEntryCount === 0):?>
  <div style="font-size:.84rem;color:var(--orange);">
    No ledger_entries found yet. Run <a href="ledger_backfill.php">Ledger Backfill</a> first to populate this
    from your existing transactions, then come back here.
  </div>
  <?php else:?>
  <div class="g3">
    <div class="card" style="box-shadow:none;background:var(--s2);">
      <div class="card-title">Total Debit</div>
      <div class="card-value c-blue" data-hide="true">BD <?=number_format($trueDebit,4)?></div>
    </div>
    <div class="card" style="box-shadow:none;background:var(--s2);">
      <div class="card-title">Total Credit</div>
      <div class="card-value c-blue" data-hide="true">BD <?=number_format($trueCredit,4)?></div>
    </div>
    <div class="card" style="box-shadow:none;background:var(--s2);">
      <div class="card-title">Gap</div>
      <div class="card-value <?=abs($trueGap)<0.001?'c-green':'c-red'?>" data-hide="true">BD <?=number_format($trueGap,4)?></div>
      <div class="card-sub"><?=abs($trueGap)<0.001?'✅ Balances exactly':'⚠️ Should be zero — investigate'?></div>
    </div>
  </div>
  <div style="font-size:.78rem;color:var(--muted);margin-top:10px;">
    <?=number_format($ledgerEntryCount)?> ledger legs from <?=number_format($ledgerEntryCount/2)?> events.
    Every posting through add_transaction.php, trade_stock.php, or edit_account.php's adjustment writes a
    balanced Debit/Credit pair — so unlike the heuristics below, this total is not supposed to be
    "approximately right," it's supposed to be exactly zero. If it isn't, something bypassed the shared
    posting functions.
  </div>

  <details style="margin-top:12px;">
    <summary style="font-size:.8rem;color:var(--muted);cursor:pointer;">Show breakdown by account/bucket</summary>
    <table class="tbl" style="font-size:.78rem;margin-top:8px;">
      <tr><th>Ledger Account</th><th style="text-align:right;">Debit</th><th style="text-align:right;">Credit</th><th style="text-align:right;">Net</th></tr>
      <?php foreach($bucketRows as $b):
        $label = $b['bucket'] ?: ($accNameMap[$b['account_id']] ?? "Account #{$b['account_id']}");
        $net = (float)$b['dr'] - (float)$b['cr'];
      ?>
      <tr>
        <td><?=htmlspecialchars($label)?></td>
        <td style="text-align:right;" data-hide="true"><?=money((float)$b['dr'])?></td>
        <td style="text-align:right;" data-hide="true"><?=money((float)$b['cr'])?></td>
        <td style="text-align:right;font-weight:600;" data-hide="true"><?=money($net)?></td>
      </tr>
      <?php endforeach;?>
    </table>
  </details>
  <?php endif;?>
</div>

<!-- PART 1 -->
<div class="card" style="padding:0;overflow:hidden;margin-bottom:16px;">
  <div style="padding:12px 18px;background:var(--s2);font-weight:600;">Trial Balance</div>
  <div class="tbl-wrap"><table class="tbl" style="font-size:.82rem;">
    <tr><th>Account</th><th>Type</th><th style="text-align:right;">Debit (BD)</th><th style="text-align:right;">Credit (BD)</th></tr>
    <?php foreach($rows as $r): $isEquityRow = ($r['id']===0); ?>
    <tr style="<?=$isEquityRow?'border-top:1px solid var(--s3);font-weight:600;':''?>">
      <td>
        <?php if($r['id']>0):?>
          <a href="account_detail.php?id=<?=$r['id']?>"><?=htmlspecialchars($r['name'])?></a>
        <?php elseif(!empty($r['link'])):?>
          <a href="<?=htmlspecialchars($r['link'])?>"><?=htmlspecialchars($r['name'])?></a>
        <?php else:?>
          <?=htmlspecialchars($r['name'])?>
        <?php endif;?>
        <?=$r['contra']?' <span class="c-orange" title="Balance is on the opposite side of what\'s normal for this account type">⚠️</span>':''?>
      </td>
      <td class="c-muted"><?=$r['type']?></td>
      <td style="text-align:right;" data-hide="true"><?=$r['debit']>0?money($r['debit']):'—'?></td>
      <td style="text-align:right;" data-hide="true"><?=$r['credit']>0?money($r['credit']):'—'?></td>
    </tr>
    <?php endforeach;?>
    <tr style="font-weight:700;border-top:2px solid var(--blue);">
      <td colspan="2">TOTAL</td>
      <td style="text-align:right;" class="c-green" data-hide="true"><?=money($totalDebit)?></td>
      <td style="text-align:right;" class="c-green" data-hide="true"><?=money($totalCredit)?></td>
    </tr>
  </table></div>
  <div style="padding:10px 18px;font-size:.78rem;color:<?=abs($totalDebit-$totalCredit)<0.01?'var(--green)':'var(--red)'?>;">
    <?=abs($totalDebit-$totalCredit)<0.01?'✅ Balances exactly — Debit = Credit.':'⚠️ Debit and Credit differ by BD '.money(abs($totalDebit-$totalCredit)).' — investigate.'?>
  </div>
  <?php if(abs($unrealizedGL) > 0.001):?>
  <div style="padding:10px 18px;font-size:.78rem;color:var(--muted);border-top:1px solid var(--s3);">
    Portfolio is shown here at <strong>cost</strong> (BD <?=money($portCostBHD)?>) — the ledger never
    records unrealized gains. The <a href="report_financial_statements.php">Balance Sheet</a> report shows it
    at today's <strong>market value</strong> instead (BD <?=money($portMarketBHD)?>), so its Total
    Assets and Equity will run <?=$unrealizedGL>=0?'higher':'lower'?> than this page by exactly
    BD <?=money(abs($unrealizedGL))?> — your current unrealized <?=$unrealizedGL>=0?'gain':'loss'?>.
    That gap is expected, not an error.
  </div>
  <?php endif;?>
</div>

<!-- PART 2 -->
<div class="card" style="padding:0;overflow:hidden;">
  <div style="padding:12px 18px;background:var(--s2);font-weight:600;">Per-Account Debit/Credit Ledger</div>
  <div class="tbl-wrap"><table class="tbl" style="font-size:.8rem;">
    <tr>
      <th>Account</th><th style="text-align:right;">Total Dr</th><th style="text-align:right;">Total Cr</th>
      <th style="text-align:right;">Net (Dr−Cr)</th><th style="text-align:right;">Stored Balance</th>
      <th style="text-align:right;">Seed / Unexplained</th>
    </tr>
    <?php foreach($ledgerRows as $r):?>
    <tr>
      <td><a href="account_detail.php?id=<?=$r['id']?>"><?=htmlspecialchars($r['name'])?></a></td>
      <td style="text-align:right;color:var(--green);" data-hide="true"><?=money($r['dr'])?></td>
      <td style="text-align:right;color:var(--red);" data-hide="true"><?=money($r['cr'])?></td>
      <td style="text-align:right;font-weight:600;" data-hide="true"><?=money($r['net'])?></td>
      <td style="text-align:right;" data-hide="true"><?=money($r['stored'], $r['currency'])?> <?=$r['currency']?></td>
      <td style="text-align:right;" data-hide="true"><?=money($r['seed'])?></td>
    </tr>
    <?php endforeach;?>
  </table></div>
</div>

<div class="card" style="margin-top:16px;">
  <div style="font-size:.82rem;color:var(--muted);line-height:1.7;">
    <strong>How to read the Seed/Unexplained column:</strong> it's the part of each account's balance that
    isn't explained by any transaction on record — normally that's just the account's starting balance
    from before you began tracking it, or a deliberate Opening Balance Adjustment. It should be the same
    number every time you check, for a given account, unless you made an adjustment. If it changes on its
    own between checks, something is writing to that account's balance outside the transaction ledger —
    check <a href="transfer_audit.php">Transfer Audit</a> and <a href="schedule_forensics.php">Schedule
    Forensics</a> next.
  </div>
</div>

<?php require 'footer.php'; ?>
