<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ledger.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: accounts.php'); exit; }

// ── Handle reverse transaction (was delete) ────────────────────────────
// Transactions are never physically deleted anymore. "Deleting" now
// creates an offsetting transaction and marks the original as reversed
// — full history stays intact, balance still corrects. See
// ledger.php::reverseTransaction() for the actual logic.
if (isset($_GET['delete_txn']) && is_numeric($_GET['delete_txn'])) {
    $txnId = (int)$_GET['delete_txn'];
    $r = reverseTransaction($txnId);
    $msgParam = $r['ok'] ? 'reversed' : 'reverse_failed';
    if ($r['ok'] && $r['warning']) $msgParam = 'reversed_with_warning';
    header("Location: /dashboard/account_detail.php?id=" . $id . "&msg=" . $msgParam);
    exit;
}


$accSt = db()->prepare("SELECT * FROM accounts WHERE id=?");
$accSt->execute([$id]);
$account = $accSt->fetch();
if (!$account) { header('Location: accounts.php'); exit; }

$pageTitle  = $account['name'];
$activePage = 'accounts';
$backTo = 'accounts.php';
$isCC       = (bool)$account['is_credit_card'];

// Filters (type/search only affect which ROWS are displayed, never the
// opening/closing math below — those always reconcile against the full
// period regardless of filter, exactly like a bank statement would)
$filterMonth = $_GET['month'] ?? '';
$filterType  = $_GET['type']  ?? '';
$search      = $_GET['search'] ?? '';

// ══════════════════════════════════════════════════════════════════════
// LEDGER ENGINE
// Single pass over every transaction that ever touched this account
// (as source OR destination), oldest→newest, building a running balance
// for EVERY row. currentBalance (accounts.balance) is the anchor; we
// walk backwards from it to also recover the "opening" balance that
// existed before this app started tracking transactions at all.
// ══════════════════════════════════════════════════════════════════════
$currentBalance = (float)$account['balance'];

$allSt = db()->prepare(
    "SELECT t.*, a.name as acc_name, b.name as to_acc_name
     FROM transactions t
     LEFT JOIN accounts a ON a.id=t.account_id
     LEFT JOIN accounts b ON b.id=t.to_account_id
     WHERE (t.account_id=? OR t.to_account_id=?)
     ORDER BY t.txn_date ASC, t.id ASC"
);
$allSt->execute([$id, $id]);
$allTxns = $allSt->fetchAll();

function txnEffect(array $t, int $accountId): float {
    $amt = (float)$t['amount'];
    if ((int)$t['account_id'] === $accountId) {
        if ($t['type']==='income')  return  $amt;
        if ($t['type']==='expense') return -$amt;
        if ($t['type']==='transfer') return -$amt; // money leaving as the source
    }
    if ((int)$t['to_account_id'] === $accountId && $t['type']==='transfer') {
        return (function_exists('toAccountAmount') ? toAccountAmount($amt, $t['currency'] ?? 'BHD', $accountId) : $amt); // money arriving as the destination
    }
    return 0.0;
}

// True pre-tracking opening balance = current balance minus every
// movement we know about.
$netAll = 0.0;
foreach ($allTxns as $t) $netAll += txnEffect($t, $id);
// The opening balance is a FACT, not a plug. When accounts.opening_balance
// is set we use it verbatim, so nothing can ever shift it. The walk and the
// stored balance then become two INDEPENDENT figures — and any gap between
// them is real drift, shown on the card rather than absorbed in silence.
$storedOpening = (isset($account['opening_balance'])
                  && $account['opening_balance'] !== null
                  && $account['opening_balance'] !== '')
                 ? (float)$account['opening_balance'] : null;
$trueOpening  = $storedOpening !== null ? $storedOpening : ($currentBalance - $netAll);
$openingDrift = $storedOpening !== null
                 ? round(($storedOpening + $netAll) - $currentBalance, 4) : 0.0;

// Walk forward once, stamping a running balance onto every row.
$running = $trueOpening;
foreach ($allTxns as &$t) {
    $running += txnEffect($t, $id);
    $t['_running'] = $running;
    $t['_effect']  = txnEffect($t, $id);
}
unset($t);
// Sanity: last running balance must equal currentBalance (dr = cr).
$reconciled = $allTxns ? abs(end($allTxns)['_running'] - $currentBalance) < 0.005 : abs($trueOpening - $currentBalance) < 0.005;

// ── Resolve the period (month filter, or "all time") ──────────────────
if ($filterMonth) {
    $periodStart = $filterMonth . '-01';
    $periodEnd   = date('Y-m-t', strtotime($periodStart));
} else {
    $periodStart = null;
    $periodEnd   = null;
}

$openingBal = $trueOpening;
$periodTxns = [];
foreach ($allTxns as $t) {
    $d = date('Y-m-d', strtotime($t['txn_date']));
    if ($periodStart !== null && $d < $periodStart) {
        $openingBal = $t['_running'];
        continue;
    }
    if ($periodEnd !== null && $d > $periodEnd) continue;
    $periodTxns[] = $t;
}
$closingBal = $periodTxns ? end($periodTxns)['_running'] : $openingBal;

// ── Period reconciliation totals (ALWAYS full period, ignores type/search) ──
$sumIncome = $sumExpense = $sumTransferIn = $sumTransferOut = 0.0;
$cntIncome = $cntExpense = $cntTransferIn = $cntTransferOut = 0;
foreach ($periodTxns as $t) {
    $isSource = ((int)$t['account_id'] === $id);
    if ($t['type']==='income' && $isSource)       { $sumIncome += (float)$t['amount']; $cntIncome++; }
    elseif ($t['type']==='expense' && $isSource)  { $sumExpense += (float)$t['amount']; $cntExpense++; }
    elseif ($t['type']==='transfer' && $isSource) { $sumTransferOut += (float)$t['amount']; $cntTransferOut++; }
    elseif ($t['type']==='transfer' && !$isSource){ $sumTransferIn  += abs((float)($t['_effect'] ?? $t['amount'])); $cntTransferIn++; }
}

// ── Rows to actually DISPLAY (type/search filters applied here only) ──
$displayTxns = array_filter($periodTxns, function($t) use ($filterType, $search) {
    if ($filterType && $t['type'] !== $filterType) return false;
    if ($search) {
        $hay = strtolower(($t['category']??'').' '.($t['note']??''));
        if (strpos($hay, strtolower($search)) === false) return false;
    }
    return true;
});
// Latest first for display; running balance was already computed correctly
// off the full, unfiltered, chronological pass above, so it stays accurate
// per row even though the visible subset is filtered/reversed.
$displayTxns = array_reverse($displayTxns);

// Current display balance (header)
$displayBal = $currentBalance;
if ($isCC) {
    $ccB = getCCBalances($account);
}

// ── Portfolio holdings linked to this account (display only) ──────────
// Under the trade_stock.php model: accounts.balance is CASH ONLY —
// it moves when you buy (cash out) or sell (cash in) via a real
// transaction, and never when the market price just moves. Cost basis
// of what you currently hold lives in portfolio.avg_cost × quantity,
// completely separate from the cash balance. Market value is computed
// live from portfolio.quantity × current_price and is NEVER written
// back into accounts.balance — see sync_portfolio_accounts.php for why
// that used to happen and why it stopped. Unrealized gain/loss = market
// value − cost basis of holdings, and stays unrealized until an actual
// sell transaction (via trade_stock.php) moves cash back into the ledger.
$portfolioHoldings = [];
$marketValueNative = 0.0;
$costBasisNative    = 0.0;
try {
    $phSt = db()->prepare("SELECT * FROM portfolio WHERE account_id=? AND quantity>0 ORDER BY symbol");
    $phSt->execute([$id]);
    $portfolioHoldings = $phSt->fetchAll();
    foreach ($portfolioHoldings as $p) {
        $holdingCur = $p['currency'] ?: $account['currency'];
        $mv   = (float)$p['quantity'] * (float)$p['current_price'];
        $cost = (float)$p['quantity'] * (float)$p['avg_cost'];
        $mvBHD   = toBHD($mv, $holdingCur);
        $costBHD = toBHD($cost, $holdingCur);
        $rateAcc = ($account['currency'] === 'BHD') ? 1.0 : (1.0 / max(0.000001, toBHD(1, $account['currency'])));
        $marketValueNative += $mvBHD * $rateAcc;
        $costBasisNative   += $costBHD * $rateAcc;
    }
} catch (Exception $e) {}
$hasPortfolio  = count($portfolioHoldings) > 0;
$unrealizedGL  = $marketValueNative - $costBasisNative;
$totalAccountValue = $currentBalance + $marketValueNative; // cash + holdings, for net-worth purposes

require 'header.php';
?>

<?php $msgParam = $_GET['msg'] ?? ''; ?>
<?php if($msgParam==='reversed'):?>
<div class="alert alert-success">✅ Transaction reversed. The original stays in your history, marked as reversed, and a new offsetting entry corrected the balance.</div>
<?php elseif($msgParam==='reversed_with_warning'):?>
<div class="alert alert-danger">⚠️ Transaction reversed, but this was a stock trade — cash and the ledger are corrected, but you'll need to manually fix the portfolio holding's quantity/avg cost via <a href="trade_stock.php">Trade Stock</a> or Portfolio.</div>
<?php elseif($msgParam==='reverse_failed'):?>
<div class="alert alert-danger">❌ Couldn't reverse that transaction — it may already be reversed, or its type isn't recognized.</div>
<?php endif;?>

<!-- Account Header -->
<div class="card" style="margin-bottom:20px;">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
    <div>
      <div style="font-size:.8rem;color:var(--muted);margin-bottom:4px;"><?=htmlspecialchars($account['group_name'])?></div>
      <div style="font-size:1.4rem;font-weight:700;"><?=htmlspecialchars($account['name'])?></div>
      <div style="margin-top:6px;display:flex;gap:8px;align-items:center;">
        <span class="badge <?=$account['type']==='asset'?'badge-inc':'badge-exp'?>"><?=strtoupper($account['type'])?></span>
        <?php if($isCC):?><span class="badge badge-tra">💳 CREDIT CARD</span><?php endif;?>
        <span style="color:var(--muted);font-size:.85rem;"><?=$account['currency']?></span>
        <?php if(!$reconciled):?>
        <span class="badge badge-exp" title="Stored balance does not match the sum of transactions. Visit Balance Audit.">⚠️ Unreconciled</span>
        <?php endif;?>
      </div>
    </div>
    <div style="text-align:right;">
      <div style="font-size:.8rem;color:var(--muted);">Current Balance</div>
      <?php if($isCC):?>
      <div style="font-size:1.8rem;font-weight:700;color:var(--red);" data-hide="true">
        -<?=money($ccB['total'], $account['currency'])?> <?=$account['currency']?>
      </div>
      <div style="font-size:.8rem;margin-top:4px;" data-hide="true">
        Payable: <span style="color:var(--red);font-weight:600;"><?=money($ccB['payable'])?></span> &nbsp;|&nbsp;
        Outstanding: <span style="color:var(--orange);font-weight:600;"><?=money($ccB['outstanding'])?></span>
      </div>
      <?php else:?>
      <div style="font-size:1.8rem;font-weight:700;" class="<?=$displayBal<0?'c-red':'c-blue'?>" data-hide="true">
        <?=money($displayBal, $account['currency'])?> <?=$account['currency']?>
      </div>
      <?php if($account['currency']!=='BHD'):?>
      <div style="font-size:.8rem;color:var(--muted);" data-hide="true">≈ BD <?=money(toBHD($displayBal,$account['currency']))?></div>
      <?php endif;?>
      <?php endif;?>
    </div>
  </div>
  <hr class="divider">
  <div class="gap-2">
    <a href="add_transaction.php?account_id=<?=$id?>&amp;return_to=account_detail.php?id=<?=$id?>" class="btn btn-primary btn-sm">+ Add Transaction</a>
    <a href="edit_account.php?id=<?=$id?>" class="btn btn-ghost btn-sm">✏️ Edit Account</a>
    <a href="balance_audit.php?id=<?=$id?>" class="btn btn-ghost btn-sm">🔍 Balance Audit</a>
    <a href="accounts.php" class="btn btn-ghost btn-sm">← All Accounts</a>
  </div>
</div>

<?php if($hasPortfolio):?>
<!-- Portfolio: Cash vs Cost Basis vs Market Value vs Unrealized -->
<div class="card" style="margin-bottom:20px;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
    <div class="section-title">📊 Portfolio Holdings — unrealized until you sell</div>
    <a href="trade_stock.php" class="btn btn-primary btn-sm">📈 Trade Stock</a>
  </div>
  <div class="g3">
    <div class="card" style="box-shadow:none;background:var(--s2);">
      <div class="card-title">Cash Balance (Ledger)</div>
      <div class="card-value c-blue" data-hide="true"><?=money($currentBalance, $account['currency'])?></div>
      <div class="card-sub">Uninvested cash — only moves via transfers or trades</div>
    </div>
    <div class="card" style="box-shadow:none;background:var(--s2);">
      <div class="card-title">Portfolio Cost Basis</div>
      <div class="card-value c-blue" data-hide="true"><?=money($costBasisNative, $account['currency'])?></div>
      <div class="card-sub">What you paid for shares you still hold</div>
    </div>
    <div class="card" style="box-shadow:none;background:var(--s2);">
      <div class="card-title">Market Value (Today)</div>
      <div class="card-value c-blue" data-hide="true"><?=money($marketValueNative, $account['currency'])?></div>
      <div class="card-sub"><?=count($portfolioHoldings)?> holding(s), live price × quantity</div>
    </div>
  </div>
  <div class="g2" style="margin-top:12px;">
    <div class="card" style="box-shadow:none;background:var(--s2);">
      <div class="card-title">Unrealized Gain / Loss</div>
      <div class="card-value <?=$unrealizedGL>=0?'c-green':'c-red'?>" data-hide="true"><?=$unrealizedGL>=0?'+':''?><?=money($unrealizedGL, $account['currency'])?></div>
      <div class="card-sub">Market value − cost basis. Becomes real only when you sell.</div>
    </div>
    <div class="card" style="box-shadow:none;background:var(--s2);">
      <div class="card-title">Total Account Value</div>
      <div class="card-value c-green" data-hide="true"><?=money($totalAccountValue, $account['currency'])?></div>
      <div class="card-sub">Cash + market value — this is what counts toward net worth</div>
    </div>
  </div>
  <div style="overflow-x:auto;margin-top:14px;">
    <table class="tbl" style="font-size:.8rem;">
      <tr><th>Symbol</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Avg Cost</th><th style="text-align:right;">Price</th><th style="text-align:right;">Market Value</th><th style="text-align:right;">Unrealized</th></tr>
      <?php foreach($portfolioHoldings as $p):
        $hMV = (float)$p['quantity']*(float)$p['current_price'];
        $hCost = (float)$p['quantity']*(float)$p['avg_cost'];
        $hPL = $hMV - $hCost;
      ?>
      <tr>
        <td><?=htmlspecialchars($p['symbol'])?></td>
        <td style="text-align:right;"><?=number_format((float)$p['quantity'],2)?></td>
        <td style="text-align:right;" data-hide="true"><?=money((float)$p['avg_cost'], $p['currency'])?></td>
        <td style="text-align:right;" data-hide="true"><?=money((float)$p['current_price'], $p['currency'])?> <?=$p['currency']?></td>
        <td style="text-align:right;" data-hide="true"><?=money($hMV, $p['currency'])?> <?=$p['currency']?></td>
        <td style="text-align:right;" class="<?=$hPL>=0?'c-green':'c-red'?>" data-hide="true"><?=$hPL>=0?'+':''?><?=money($hPL)?></td>
      </tr>
      <?php endforeach;?>
    </table>
  </div>
</div>
<?php endif;?>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;padding:14px 18px;">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <input type="hidden" name="id" value="<?=$id?>">
    <div><div class="form-label">Month</div><input type="month" class="form-control" name="month" value="<?=htmlspecialchars($filterMonth)?>" style="width:160px;"></div>
    <div><div class="form-label">Type</div>
      <select class="form-control" name="type" style="width:130px;">
        <option value="">All</option>
        <option value="income" <?=$filterType==='income'?'selected':''?>>Income</option>
        <option value="expense" <?=$filterType==='expense'?'selected':''?>>Expense</option>
        <option value="transfer" <?=$filterType==='transfer'?'selected':''?>>Transfer</option>
      </select>
    </div>
    <div><div class="form-label">Search</div><input class="form-control" name="search" value="<?=htmlspecialchars($search)?>" placeholder="category or note" style="width:160px;"></div>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="account_detail.php?id=<?=$id?>" class="btn btn-ghost btn-sm">Clear</a>
    <a href="report_pdf.php?report=account&from=<?=$filterMonth?$filterMonth.'-01':date('Y-01-01')?>&to=<?=$filterMonth?date('Y-m-t',strtotime($filterMonth.'-01')):date('Y-m-d')?>&account_id=<?=$id?>" target="_blank" class="btn btn-ghost btn-sm">🖨️ PDF</a>
  </form>
</div>

<!-- Period Reconciliation: Opening → Income/Expense/Transfers → Closing -->
<div style="margin-bottom:16px;">
  <div class="section-title" style="margin-bottom:10px;font-size:.85rem;color:var(--muted);">
    <?=$filterMonth ? date('F Y', strtotime($filterMonth.'-01')) : 'All Time'?> Reconciliation
  </div>
  <div class="g3">
    <div class="card">
      <div class="card-title">Opening Balance</div>
      <div class="card-value c-blue" data-hide="true"><?=money($openingBal, $account['currency'])?></div>
      <div class="card-sub"><?=$account['currency']?><?php if(isset($openingDrift) && abs($openingDrift)>=0.005): ?> <span style="color:var(--red);" title="The walk does not reach the stored balance. This gap used to be hidden inside the opening figure.">drift <?=money($openingDrift, $account['currency'])?></span><?php endif; ?></div>
    </div>
    <div class="card">
      <div class="card-title">Income</div>
      <div class="card-value c-green" data-hide="true">+<?=money($sumIncome, $account['currency'])?></div>
      <div class="card-sub"><?=$cntIncome?> txns</div>
    </div>
    <div class="card">
      <div class="card-title">Expense</div>
      <div class="card-value c-red" data-hide="true">-<?=money($sumExpense, $account['currency'])?></div>
      <div class="card-sub"><?=$cntExpense?> txns</div>
    </div>
  </div>
  <div class="g3">
    <div class="card">
      <div class="card-title">Transfer In</div>
      <div class="card-value c-green" data-hide="true">+<?=money($sumTransferIn, $account['currency'])?></div>
      <div class="card-sub"><?=$cntTransferIn?> txns</div>
    </div>
    <div class="card">
      <div class="card-title">Transfer Out</div>
      <div class="card-value c-red" data-hide="true">-<?=money($sumTransferOut, $account['currency'])?></div>
      <div class="card-sub"><?=$cntTransferOut?> txns</div>
    </div>
    <div class="card">
      <div class="card-title">Closing Balance</div>
      <div class="card-value <?=$closingBal<0?'c-red':'c-green'?>" data-hide="true"><?=money($closingBal, $account['currency'])?></div>
      <div class="card-sub"><?=$account['currency']?></div>
    </div>
  </div>
</div>

<!-- Statement -->
<div class="card" style="padding:0;overflow:hidden;">
  <div style="padding:12px 18px;background:var(--s2);display:flex;justify-content:space-between;align-items:center;">
    <span style="font-weight:600;">Transactions (<?=count($displayTxns)?>)</span>
    <a href="add_transaction.php?account_id=<?=$id?>&amp;return_to=account_detail.php?id=<?=$id?>" class="btn btn-primary btn-sm">+ Add</a>
  </div>

  <div style="display:flex;justify-content:space-between;padding:10px 18px;background:#4e9af111;border-bottom:1px solid var(--s3);">
    <span style="font-size:.85rem;color:var(--muted);font-style:italic;">Opening Balance<?=$filterMonth?' ('.date('d M Y',strtotime($filterMonth.'-01')).')':''?></span>
    <span style="font-weight:700;color:var(--blue);" data-hide="true"><?=money($openingBal, $account['currency'])?> <?=$account['currency']?></span>
  </div>

  <?php if(!$displayTxns):?>
    <div style="padding:40px;text-align:center;color:var(--muted);">No transactions found.</div>
  <?php else:?>
  <div style="overflow-x:auto;">
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Category</th><th>Note</th><th>Other Account</th><th>Running Balance</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($displayTxns as $t):
          $isSource = ($t['account_id'] == $id);
          $otherAcc = $isSource ? $t['to_acc_name'] : $t['acc_name'];
          $bc=$t['type']==='income'?'badge-inc':($t['type']==='expense'?'badge-exp':'badge-tra');
          $amtColor = $t['type']==='expense'?'c-red':($t['type']==='income'?'c-green':'c-blue');
          $amtSign  = ($t['type']==='expense'||($t['type']==='transfer'&&$isSource)) ? '−' : '+';
          $typeLabel = $t['type']==='transfer' ? ($isSource ? 'Transfer Out' : 'Transfer In') : ucfirst($t['type']);
        ?>
        <tr style="<?=$t['reversed_at']?'opacity:.55;':''?>">
          <td style="white-space:nowrap;font-size:.82rem;color:var(--muted);"><?=date('d M Y',strtotime($t['txn_date']))?></td>
          <td>
            <span class="badge <?=$bc?>"><?=$typeLabel?></span>
            <?php if($t['reversed_at']):?><span class="badge badge-exp" title="Reversed on <?=date('d M Y',strtotime($t['reversed_at']))?>">↩️ REVERSED</span><?php endif;?>
            <?php if($t['reversal_of']):?><span class="badge badge-tra" title="This entry reverses transaction #<?=$t['reversal_of']?>">↩️ Reversal</span><?php endif;?>
          </td>
          <td style="font-weight:600;white-space:nowrap;" class="<?=$amtColor?>" data-hide="true">
            <?=$amtSign?><?=money((float)$t['amount'], $t['currency'])?> <?=$t['currency']?>
          </td>
          <td><?=htmlspecialchars($t['category']??'')?><?=$t['subcategory']?' › '.htmlspecialchars($t['subcategory']):''?></td>
          <td style="color:var(--muted);max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($t['note']??'')?></td>
          <td style="color:var(--muted);font-size:.85rem;">
            <?php if($t['type']==='transfer' && !$otherAcc):?>
              <span class="c-red" title="This transfer has no destination account — money left this account but was never credited anywhere.">⚠️ missing</span>
            <?php else:?>
              <?=htmlspecialchars($otherAcc??'—')?>
            <?php endif;?>
          </td>
          <td style="font-weight:600;white-space:nowrap;" class="<?=$t['_running']<0?'c-red':'c-blue'?>" data-hide="true"><?=money($t['_running'], $account['currency'])?></td>
          <td>
            <div class="gap-2">
              <?php if($t['reversed_at']):?>
                <span class="c-muted" style="font-size:.78rem;">already reversed</span>
              <?php else:?>
                <a href="edit_transaction.php?id=<?=$t['id']?>" class="btn btn-ghost btn-sm">Edit</a>
                <a href="account_detail.php?id=<?=$id?>&delete_txn=<?=$t['id']?>" class="btn btn-danger btn-sm" onclick="return confirm('Reverse this transaction? The original stays in history, marked as reversed, and a new offsetting entry corrects the balance. Nothing is deleted.')">↩️ Reverse</a>
              <?php endif;?>
            </div>
          </td>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table></div>
  </div>
  <div style="display:flex;justify-content:space-between;padding:10px 18px;background:#2ecc7111;border-top:2px solid var(--s3);">
    <span style="font-size:.85rem;font-weight:600;color:var(--green);">Closing Balance<?=$filterMonth?' ('.date('d M Y',strtotime($filterMonth.'-'.date('t',strtotime($filterMonth.'-01')))).')':''?></span>
    <span style="font-weight:700;color:var(--green);" data-hide="true"><?=money($closingBal, $account['currency'])?> <?=$account['currency']?></span>
  </div>
  <?php endif;?>
</div>

<?php require 'footer.php'; ?>
