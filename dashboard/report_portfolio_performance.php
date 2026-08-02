<?php
/**
 * Portfolio Performance — XIRR per investment account
 * Read-only.
 *
 * XIRR treats every Buy as a cash outflow (negative) and every Sell as
 * a cash inflow (positive), on their actual dates, plus one synthetic
 * final inflow: today's market value of whatever's still held (as if
 * you sold everything today). The rate that makes all those cash flows
 * net to zero, annualized, is the XIRR — the standard way to measure
 * return when money went in and out at irregular times and amounts,
 * which is exactly what real trading looks like.
 */
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Portfolio Performance'; $activePage='reports'; $backTo='reports.php';

function xirr(array $cashflows): ?float {
    if (count($cashflows) < 2) return null;
    usort($cashflows, fn($a,$b) => strtotime($a['date']) <=> strtotime($b['date']));
    $d0 = strtotime($cashflows[0]['date']);
    $npv = function(float $rate) use ($cashflows, $d0): float {
        $sum = 0.0;
        foreach ($cashflows as $cf) {
            $days = (strtotime($cf['date']) - $d0) / 86400;
            $sum += $cf['amount'] / (1 + $rate) ** ($days / 365);
        }
        return $sum;
    };
    $rate = 0.1;
    for ($i = 0; $i < 100; $i++) {
        $f  = $npv($rate);
        $f2 = $npv($rate + 0.0001);
        $deriv = ($f2 - $f) / 0.0001;
        if (abs($deriv) < 1e-9) break;
        $next = $rate - $f / $deriv;
        if ($next < -0.99) $next = -0.99;
        if (abs($next - $rate) < 1e-7) { $rate = $next; break; }
        $rate = $next;
    }
    return is_finite($rate) ? $rate : null;
}

// Investment accounts with any trade history or current holdings
$accounts = db()->query(
    "SELECT DISTINCT a.* FROM accounts a
     WHERE a.id IN (SELECT account_id FROM portfolio WHERE account_id IS NOT NULL)
        OR a.id IN (SELECT account_id FROM transactions WHERE category='Investment')
     ORDER BY a.name"
)->fetchAll();

$results = [];
foreach ($accounts as $a) {
    $st = db()->prepare(
        "SELECT txn_date, type, amount, currency, subcategory FROM transactions
         WHERE account_id=? AND category='Investment' AND subcategory IN ('Stock Purchase','Stock Sale')
         ORDER BY txn_date"
    );
    $st->execute([$a['id']]);
    $trades = $st->fetchAll();
    if (!$trades) continue;

    $cashflows = [];
    $totalInvested = 0; $totalRealized = 0;
    foreach ($trades as $t) {
        $bhd = toBHD((float)$t['amount'], $t['currency']);
        if ($t['subcategory'] === 'Stock Purchase') { $cashflows[] = ['date'=>$t['txn_date'],'amount'=>-$bhd]; $totalInvested += $bhd; }
        else                                          { $cashflows[] = ['date'=>$t['txn_date'],'amount'=>$bhd];  $totalRealized += $bhd; }
    }

    $mvSt = db()->prepare("SELECT SUM(quantity*current_price) as mv, MAX(currency) as cur FROM portfolio WHERE account_id=? AND quantity>0");
    $mvSt->execute([$a['id']]);
    $mv = $mvSt->fetch();
    $marketValueBHD = $mv && $mv['mv'] ? toBHD((float)$mv['mv'], $mv['cur']) : 0;
    if ($marketValueBHD > 0) $cashflows[] = ['date'=>date('Y-m-d'), 'amount'=>$marketValueBHD];

    $rate = xirr($cashflows);
    $totalReturn = $totalInvested > 0 ? (($marketValueBHD + $totalRealized - $totalInvested) / $totalInvested) * 100 : 0;

    $results[] = [
        'id'=>$a['id'], 'name'=>$a['name'],
        'invested'=>$totalInvested, 'realized'=>$totalRealized, 'market_value'=>$marketValueBHD,
        'total_return_pct'=>$totalReturn, 'xirr'=>$rate, 'trade_count'=>count($trades),
    ];
}
usort($results, fn($a,$b) => ($b['xirr']??-999) <=> ($a['xirr']??-999));

require 'header.php';
?>

<div class="no-print" style="text-align:right;margin-bottom:12px;">
  <button onclick="window.print()" class="btn btn-ghost btn-sm">🖨️ Print / Save as PDF</button>
</div>


<div class="card" style="margin-bottom:16px;">
  <div style="font-size:.84rem;color:var(--muted);line-height:1.6;">
    XIRR (annualized return) computed from every actual buy/sell recorded through
    <a href="trade_stock.php">Trade Stock</a>, plus today's market value of what's still held as a final
    cash flow. Accounts with no trades recorded through Trade Stock yet won't show a meaningful number —
    older holdings entered directly in Portfolio (before Trade Stock existed) don't have dated buy
    transactions to compute a return from.
  </div>
</div>

<?php if(!$results):?>
<div class="card" style="text-align:center;padding:40px;color:var(--muted);">
  No trade history yet through Trade Stock. XIRR needs dated buy/sell transactions — once you've
  recorded a few trades, come back here.
</div>
<?php else:?>
<div class="tbl-wrap"><table class="tbl" style="font-size:.85rem;">
  <tr>
    <th>Account</th><th style="text-align:right;">Invested</th><th style="text-align:right;">Realized</th>
    <th style="text-align:right;">Market Value</th><th style="text-align:right;">Total Return</th>
    <th style="text-align:right;">XIRR (annualized)</th><th style="text-align:right;">Trades</th>
  </tr>
  <?php foreach($results as $r):?>
  <tr>
    <td><a href="account_detail.php?id=<?=$r['id']?>"><?=htmlspecialchars($r['name'])?></a></td>
    <td style="text-align:right;" data-hide="true">BD <?=money($r['invested'])?></td>
    <td style="text-align:right;" data-hide="true">BD <?=money($r['realized'])?></td>
    <td style="text-align:right;" data-hide="true">BD <?=money($r['market_value'])?></td>
    <td style="text-align:right;" class="<?=$r['total_return_pct']>=0?'c-green':'c-red'?>" data-hide="true"><?=$r['total_return_pct']>=0?'+':''?><?=number_format($r['total_return_pct'],1)?>%</td>
    <td style="text-align:right;font-weight:700;" class="<?=($r['xirr']??0)>=0?'c-green':'c-red'?>" data-hide="true">
      <?=$r['xirr']!==null ? ($r['xirr']>=0?'+':'').number_format($r['xirr']*100,1).'%' : '—'?>
    </td>
    <td style="text-align:right;" class="c-muted"><?=$r['trade_count']?></td>
  </tr>
  <?php endforeach;?>
</table></div>
<?php endif;?>

<?php require 'footer.php'; ?>
