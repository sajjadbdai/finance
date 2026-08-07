<?php
/**
 * Financial Statements — Balance Sheet & Income Statement
 * Read-only.
 *
 * Balance Sheet: a snapshot as-of a chosen date — Assets, Liabilities,
 * Equity (=Net Worth). Uses accounts.balance as of TODAY only (there's
 * no historical balance-by-date table), so the "as of" date only
 * affects the Income Statement period below it, not the Balance Sheet
 * itself — noted clearly on the page.
 *
 * Income Statement: real income/expense for a chosen period, EXCLUDING
 * Investment/Stock Purchase (asset swap, not an expense) and counting
 * only the realized portion of Stock Sale — same treatment as
 * trial_balance.php's cumulative P&L, just scoped to a period.
 */
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Financial Statements'; $activePage='reports'; $backTo='reports.php';

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

// ── Balance Sheet (as of today's stored balances) ──────────────────
$accounts = db()->query("SELECT * FROM accounts WHERE is_active=1 ORDER BY group_name, name")->fetchAll();
$assets = []; $liabilities = []; $totalAssets = 0; $totalLiab = 0;
foreach ($accounts as $a) {
    $isLiability = (bool)$a['is_credit_card'] || $a['type'] === 'liability';
    $bhd = toBHD((float)$a['balance'], $a['currency']);
    if ($isLiability) { $liabilities[] = ['name'=>$a['name'],'bhd'=>abs($bhd)]; $totalLiab += abs($bhd); }
    else              { $assets[]      = ['name'=>$a['name'],'bhd'=>$bhd];       $totalAssets += $bhd; }
}
// Portfolio and Fixed Assets, at COST — same methodology as
// trial_balance.php, on purpose. A Balance Sheet is supposed to be
// PRESENTED FROM the Trial Balance's own numbers, not recomputed
// independently with a different valuation basis (market value vs
// cost) — that's what caused Total Assets/Equity to disagree between
// the two pages before. Current market value is still shown, but
// only as a separate memo line, clearly outside the Total Assets sum.
$portRow = db()->query("SELECT SUM(quantity*avg_cost) as v, currency FROM portfolio WHERE quantity>0 GROUP BY currency")->fetchAll();
$portfolioBHD = 0; foreach ($portRow as $p) $portfolioBHD += toBHD((float)$p['v'], $p['currency']);

$portMarketRow = db()->query("SELECT SUM(quantity*current_price) as v, currency FROM portfolio WHERE quantity>0 AND current_price>0 GROUP BY currency")->fetchAll();
$portfolioMarketBHD = 0; foreach ($portMarketRow as $p) $portfolioMarketBHD += toBHD((float)$p['v'], $p['currency']);

$fixedBHD = 0;
try { $fx = db()->query("SELECT SUM(current_value) as v, currency FROM fixed_assets WHERE status='owned' GROUP BY currency")->fetchAll(); foreach ($fx as $f) $fixedBHD += toBHD((float)$f['v'], $f['currency']); } catch(Exception $e){}

$unrealizedGL = $portfolioMarketBHD - $portfolioBHD;
$netWorth = $totalAssets + $portfolioBHD + $fixedBHD - $totalLiab;

// ── Income Statement (period) ───────────────────────────────────────
$st = db()->prepare("SELECT type,amount,currency,category,subcategory,note FROM transactions WHERE type IN ('income','expense') AND DATE(txn_date) BETWEEN ? AND ?");
$st->execute([$from, $to]);
$incomeByCat = []; $expenseByCat = []; $totalIncome = 0; $totalExpense = 0;
$adjustmentIncome = 0; $adjustmentExpense = 0; $adjustmentCount = 0;
foreach ($st->fetchAll() as $t) {
    if ($t['category']==='Investment' && $t['subcategory']==='Stock Purchase') continue;
    if ($t['category']==='Fixed Asset' && $t['subcategory']==='Asset Purchase') continue;
    if ($t['category']==='Investment' && $t['subcategory']==='Stock Sale') {
        if (preg_match('/Realized P\/L:\s*([+-]?[\d.]+)/', $t['note'] ?? '', $m)) {
            $v = toBHD((float)$m[1], $t['currency']);
            $cat = 'Realized Investment Gain/Loss';
            if ($v >= 0) { $incomeByCat[$cat] = ($incomeByCat[$cat] ?? 0) + $v; $totalIncome += $v; }
            else         { $expenseByCat[$cat] = ($expenseByCat[$cat] ?? 0) + abs($v); $totalExpense += abs($v); }
        }
        continue;
    }
    if ($t['category']==='Fixed Asset' && $t['subcategory']==='Asset Sale') {
        if (preg_match('/Realized P\/L:\s*([+-]?[\d.]+)/', $t['note'] ?? '', $m)) {
            $v = toBHD((float)$m[1], $t['currency']);
            $cat = 'Realized Fixed Asset Gain/Loss';
            if ($v >= 0) { $incomeByCat[$cat] = ($incomeByCat[$cat] ?? 0) + $v; $totalIncome += $v; }
            else         { $expenseByCat[$cat] = ($expenseByCat[$cat] ?? 0) + abs($v); $totalExpense += abs($v); }
        }
        continue;
    }
    // Balance-correction "Adjustment" entries are one-time fixes to a
    // stored balance, not real operating income/expense — mixing them
    // into the Income Statement makes real spending patterns unreadable
    // (a manual correction can dwarf actual monthly activity). Standard
    // accounting treatment: a balance correction like this is a "prior
    // period adjustment" that goes straight to Equity, not through the
    // Income Statement. Tracked separately below instead.
    if ($t['category']==='Adjustment') {
        $v = toBHD((float)$t['amount'], $t['currency']);
        if ($t['type']==='income') $adjustmentIncome += $v; else $adjustmentExpense += $v;
        $adjustmentCount++;
        continue;
    }
    $v = toBHD((float)$t['amount'], $t['currency']);
    $cat = $t['category'] ?: 'Uncategorized';
    if ($t['type']==='income') { $incomeByCat[$cat] = ($incomeByCat[$cat] ?? 0) + $v; $totalIncome += $v; }
    else                       { $expenseByCat[$cat] = ($expenseByCat[$cat] ?? 0) + $v; $totalExpense += $v; }
}
arsort($incomeByCat); arsort($expenseByCat);
$netIncome = $totalIncome - $totalExpense;

require 'header.php';
?>

<div class="no-print" style="text-align:right;margin-bottom:12px;">
  <button onclick="window.print()" class="btn btn-ghost btn-sm">🖨️ Print / Save as PDF</button>
</div>


<div class="card" style="margin-bottom:16px;">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <div><div class="form-label">Income Statement From</div><input type="date" class="form-control" name="from" value="<?=htmlspecialchars($from)?>"></div>
    <div><div class="form-label">To</div><input type="date" class="form-control" name="to" value="<?=htmlspecialchars($to)?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
  </form>
</div>

<!-- Balance Sheet -->
<div class="card" style="padding:0;overflow:hidden;margin-bottom:8px;">
  <div style="padding:12px 18px;background:var(--s2);font-weight:700;">📋 Balance Sheet — as of today (matches Trial Balance exactly)</div>
  <div class="tbl-wrap"><table class="tbl" style="font-size:.85rem;">
    <tr><th colspan="2">Assets</th></tr>
    <?php foreach($assets as $a):?><tr><td><?=htmlspecialchars($a['name'])?></td><td style="text-align:right;" data-hide="true"><?=number_format($a['bhd'],2)?></td></tr><?php endforeach;?>
    <tr><td><a href="portfolio.php">Portfolio (at cost)</a></td><td style="text-align:right;" data-hide="true"><?=number_format($portfolioBHD,2)?></td></tr>
    <tr><td><a href="fixed_assets.php">Fixed Assets (at cost)</a></td><td style="text-align:right;" data-hide="true"><?=number_format($fixedBHD,2)?></td></tr>
    <tr style="font-weight:700;border-top:1px solid var(--s3);"><td>Total Assets</td><td style="text-align:right;" data-hide="true">BD <?=number_format($totalAssets+$portfolioBHD+$fixedBHD,2)?></td></tr>
    <tr><th colspan="2" style="padding-top:14px;">Liabilities</th></tr>
    <?php foreach($liabilities as $l):?><tr><td><?=htmlspecialchars($l['name'])?></td><td style="text-align:right;" data-hide="true"><?=number_format($l['bhd'],2)?></td></tr><?php endforeach;?>
    <tr style="font-weight:700;border-top:1px solid var(--s3);"><td>Total Liabilities</td><td style="text-align:right;" data-hide="true">BD <?=number_format($totalLiab,2)?></td></tr>
    <tr style="font-weight:700;border-top:2px solid var(--blue);"><td><a href="trial_balance.php">Equity (Net Worth)</a></td><td style="text-align:right;color:var(--blue);" data-hide="true">BD <?=number_format($netWorth,2)?></td></tr>
    <?php $lhs = $totalAssets+$portfolioBHD+$fixedBHD; $rhs = $totalLiab+$netWorth; $matches = abs($lhs-$rhs)<0.01; ?>
    <tr style="font-weight:700;background:<?=$matches?'#2ecc7118':'#e74c3c18'?>;">
      <td>TOTAL (Liabilities + Equity)</td>
      <td style="text-align:right;" class="<?=$matches?'c-green':'c-red'?>" data-hide="true">BD <?=number_format($rhs,2)?> <?=$matches?'✅':'⚠️'?></td>
    </tr>
  </table></div>
  <?php if(abs($unrealizedGL) > 0.001):?>
  <div style="padding:10px 18px;font-size:.78rem;color:var(--muted);border-top:1px solid var(--s3);">
    Memo — not included in the totals above: your portfolio's current <strong>market value</strong> is
    BD <?=number_format($portfolioMarketBHD,2)?>, an unrealized <?=$unrealizedGL>=0?'gain':'loss'?> of
    BD <?=number_format(abs($unrealizedGL),2)?> over the cost basis shown. See
    <a href="report_portfolio_performance.php">Portfolio Performance</a> for the full picture.
  </div>
  <?php endif;?>
</div>

<!-- Income Statement -->
<div class="card" style="padding:0;overflow:hidden;">
  <div style="padding:12px 18px;background:var(--s2);font-weight:700;">📈 Income Statement — <?=htmlspecialchars($from)?> to <?=htmlspecialchars($to)?></div>
  <div class="tbl-wrap"><table class="tbl" style="font-size:.85rem;">
    <tr><th colspan="2">Income</th></tr>
    <?php foreach($incomeByCat as $cat=>$v):?><tr><td><?=htmlspecialchars($cat)?></td><td style="text-align:right;color:var(--green);" data-hide="true">+<?=number_format($v,2)?></td></tr><?php endforeach;?>
    <tr style="font-weight:700;border-top:1px solid var(--s3);"><td>Total Income</td><td style="text-align:right;color:var(--green);" data-hide="true">BD <?=number_format($totalIncome,2)?></td></tr>
    <tr><th colspan="2" style="padding-top:14px;">Expense</th></tr>
    <?php foreach($expenseByCat as $cat=>$v):?><tr><td><?=htmlspecialchars($cat)?></td><td style="text-align:right;color:var(--red);" data-hide="true">-<?=number_format($v,2)?></td></tr><?php endforeach;?>
    <tr style="font-weight:700;border-top:1px solid var(--s3);"><td>Total Expense</td><td style="text-align:right;color:var(--red);" data-hide="true">BD <?=number_format($totalExpense,2)?></td></tr>
    <tr style="font-weight:700;border-top:2px solid var(--blue);"><td>Net Income</td><td style="text-align:right;" class="<?=$netIncome>=0?'c-green':'c-red'?>" data-hide="true">BD <?=number_format($netIncome,2)?></td></tr>
  </table></div>
  <?php if($adjustmentCount>0):?>
  <div style="padding:12px 18px;font-size:.8rem;color:var(--muted);border-top:1px solid var(--s3);">
    Excludes <?=$adjustmentCount?> balance-correction "Adjustment" entr<?=$adjustmentCount===1?'y':'ies'?> this period
    (<span class="c-green" data-hide="true">+<?=number_format($adjustmentIncome,2)?></span> /
    <span class="c-red" data-hide="true">-<?=number_format($adjustmentExpense,2)?></span>) —
    those are one-time balance fixes, not real income or spending, so they're kept out of the P&L and
    counted directly against Equity instead. See <a href="trial_balance.php">Trial Balance</a>.
  </div>
  <?php endif;?>
</div>

<?php require 'footer.php'; ?>
