<?php
/**
 * Portfolio Balance Repair
 * Read-only. Changes nothing. Prints review-only SQL, never executes it.
 *
 * UPDATED for the corrected model (see trade_stock.php): accounts.balance
 * is CASH ONLY. Cost basis of what you hold lives in portfolio.avg_cost,
 * not in accounts.balance. So the correction here is NOT "set balance to
 * the full amount ever invested" — it's "set balance to whatever of that
 * amount ISN'T already accounted for as stock cost basis in the portfolio
 * table":
 *
 *   suggested_cash = known_total_invested − SUM(quantity × avg_cost)
 *
 * If avg_cost is already set correctly for these holdings and the money
 * is fully invested, suggested_cash should come out near zero. If it's
 * a large non-zero number, either some cash is genuinely still
 * uninvested, or avg_cost on the holdings doesn't reflect real purchase
 * prices (e.g. was set from current_price during an import rather than
 * the actual buy price) — that needs your judgement, not a guess.
 *
 * known_total_invested still comes from the same checkpoint as
 * opening_balance_reconciliation.php:
 *   - Brac EPL ODS622: 65,000.00 BDT — CONFIRMED directly by the user
 *   - Berich 179387, Binance FX: from the 18-June-2026 screenshots
 */
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Portfolio Balance Repair'; $activePage='accounts'; $backTo='balance_tools.php';

$CHECKPOINT_DATE = '2026-06-18';
$knownTotalInvested = [
    'Brac EPL ODS622' => 65000.00, // BDT — confirmed directly by user
    'Berich 179387'    => 60000.00, // BDT — from 18-June screenshot
    'Binance FX'       => 20.00,    // BHD — from 18-June screenshot
];

function effect(array $t, int $accountId): float {
    $amt = (float)$t['amount'];
    if ((int)$t['account_id'] === $accountId) {
        if ($t['type']==='income')  return  $amt;
        if ($t['type']==='expense') return -$amt;
        if ($t['type']==='transfer') return -$amt;
    }
    if ((int)$t['to_account_id'] === $accountId && $t['type']==='transfer') return $amt;
    return 0.0;
}

// Every account that has any linked portfolio holdings
$accounts = db()->query(
    "SELECT DISTINCT a.* FROM accounts a JOIN portfolio p ON p.account_id=a.id WHERE p.quantity>0"
)->fetchAll();

$results = [];
foreach ($accounts as $a) {
    $mvSt = db()->prepare("SELECT SUM(quantity*current_price) as mv, SUM(quantity*avg_cost) as cost, MAX(currency) as cur FROM portfolio WHERE account_id=? AND quantity>0");
    $mvSt->execute([$a['id']]);
    $mv = $mvSt->fetch();
    $marketValue      = (float)($mv['mv'] ?? 0);
    $portfolioCostNow = (float)($mv['cost'] ?? 0);

    $known = $knownTotalInvested[trim($a['name'])] ?? null;
    $suggestedCash = null;
    $netSince = 0.0;

    if ($known !== null) {
        $st = db()->prepare("SELECT * FROM transactions WHERE (account_id=? OR to_account_id=?) AND txn_date > ? ORDER BY txn_date ASC, id ASC");
        $st->execute([$a['id'], $a['id'], $CHECKPOINT_DATE]);
        foreach ($st->fetchAll() as $t) $netSince += effect($t, (int)$a['id']);
        $totalInvestedToday = $known + $netSince;
        $suggestedCash = $totalInvestedToday - $portfolioCostNow;
    }

    $results[] = [
        'id'=>$a['id'], 'name'=>$a['name'], 'currency'=>$a['currency'],
        'stored'=>(float)$a['balance'], 'market_value'=>$marketValue,
        'portfolio_cost_now'=>$portfolioCostNow, 'suggested_cash'=>$suggestedCash,
    ];
}
require 'header.php';
?>

<div class="card" style="margin-bottom:16px;">
  <div class="section-title" style="margin-bottom:12px;">🔧 Portfolio Balance Repair</div>
  <div style="font-size:.84rem;color:var(--muted);line-height:1.6;">
    <code>sync_portfolio_accounts.php</code> used to overwrite <code>accounts.balance</code> with market
    value — that's fixed going forward. This resets the stored balance back to true CASH, using
    <code>trade_stock.php</code>'s model: <strong>suggested cash = total ever invested − what's already
    recorded as portfolio cost basis</strong>. If that comes out near zero, the money is fully invested and
    that's expected. Read-only — only ever prints SQL for you to review and run yourself.
  </div>
</div>

<div class="tbl-wrap"><table class="tbl" style="font-size:.82rem;">
  <tr>
    <th>Account</th><th style="text-align:right;">Stored Now (= old market value)</th>
    <th style="text-align:right;">Market Value Today</th><th style="text-align:right;">Portfolio Cost (avg_cost×qty)</th>
    <th style="text-align:right;">Suggested Cash</th><th>Fix SQL</th>
  </tr>
  <?php foreach($results as $r): ?>
  <tr>
    <td><a href="account_detail.php?id=<?=$r['id']?>"><?=htmlspecialchars($r['name'])?></a></td>
    <td style="text-align:right;" data-hide="true"><?=money($r['stored'], $r['currency'])?> <?=$r['currency']?></td>
    <td style="text-align:right;" data-hide="true"><?=money($r['market_value'])?></td>
    <td style="text-align:right;" data-hide="true"><?=money($r['portfolio_cost_now'])?></td>
    <?php if($r['suggested_cash']===null):?>
    <td style="text-align:right;" class="c-orange">unknown</td>
    <td class="c-muted">Tell me the real total amount invested (checkpoint 18 Jun) and I'll add it.</td>
    <?php else: $sc = $r['suggested_cash']; ?>
    <td style="text-align:right;<?=abs($sc)>1?'color:var(--orange);':''?>" data-hide="true"><?=money($sc)?></td>
    <td style="font-size:.72rem;">
      <code>UPDATE accounts SET balance=<?=number_format(max(0,$sc),4,'.','')?> WHERE id=<?=$r['id']?>;</code>
      <?php if(abs($sc) > 1):?>
      <div class="c-orange" style="margin-top:4px;">⚠️ Non-zero — either cash is genuinely uninvested, or portfolio.avg_cost for these holdings doesn't reflect real purchase prices. Check before applying.</div>
      <?php endif;?>
    </td>
    <?php endif;?>
  </tr>
  <?php endforeach;?>
</table></div>

<div class="card" style="margin-top:16px;">
  <div style="font-size:.82rem;color:var(--muted);line-height:1.7;">
    After running a correction, <strong>Cash Balance</strong> on the account detail page will reflect true
    uninvested cash, <strong>Portfolio Cost Basis</strong> comes from <code>avg_cost</code> on each holding,
    and <strong>Unrealized Gain</strong> = market value − cost basis. This also fixes Total Wealth, which was
    double-counting these accounts' market value (once via the account balance, once via the portfolio total).
    Going forward, use <a href="trade_stock.php">Trade Stock</a> for buys/sells so cash and cost basis stay in
    sync automatically.
  </div>
</div>

<?php require 'footer.php'; ?>
