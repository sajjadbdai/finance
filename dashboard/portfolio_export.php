<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$format       = $_GET['format'] ?? 'pdf';
$filterBroker = trim($_GET['broker'] ?? '');

try {
    if ($filterBroker) {
        $stmt = db()->prepare(
            "SELECT p.*, a.name as acc_name FROM portfolio p
             INNER JOIN accounts a ON a.id=p.account_id
             WHERE p.quantity > 0 AND a.name=?
             ORDER BY p.market, p.symbol"
        );
        $stmt->execute([$filterBroker]);
        $holdings = $stmt->fetchAll();
    } else {
        $holdings = db()->query(
            "SELECT p.*, a.name as acc_name FROM portfolio p
             LEFT JOIN accounts a ON a.id=p.account_id
             WHERE p.quantity > 0
             ORDER BY p.market, p.symbol"
        )->fetchAll();
    }
} catch(Exception $e) { $holdings=[]; }

// Calculate
$markets   = ['BD'=>[],'USA'=>[],'UK'=>[],'Crypto'=>[],'Other'=>[]];
$totalCost = 0; $totalValue = 0;
$rows = [];
foreach ($holdings as $h) {
    $cost    = (float)$h['quantity'] * (float)$h['avg_cost'];
    $value   = (float)$h['quantity'] * (float)$h['current_price'];
    $pl      = $value - $cost;
    $plPct   = $cost > 0 ? round($pl/$cost*100,2) : 0;
    $costBHD = toBHD($cost,  $h['currency']);
    $valBHD  = toBHD($value, $h['currency']);
    $totalCost  += $costBHD;
    $totalValue += $valBHD;
    $rows[] = array_merge($h, compact('cost','value','pl','plPct','costBHD','valBHD'));
    $markets[$h['market']][] = end($rows);
}
$totalPL    = $totalValue - $totalCost;
$totalPLPct = $totalCost > 0 ? round($totalPL/$totalCost*100,2) : 0;

if ($format === 'csv') {
    // CSV / Excel export
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="portfolio_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($out,['Symbol','Company','Market','Quantity','Avg Cost','Currency','Current Price','Total Cost','Current Value','P&L','P&L %','Cost BHD','Value BHD','Account','Last Updated']);
    foreach ($rows as $r) {
        fputcsv($out,[
            $r['symbol'],$r['company_name'],$r['market'],
            $r['quantity'],$r['avg_cost'],$r['currency'],$r['current_price'],
            number_format($r['cost'],2),number_format($r['value'],2),
            number_format($r['pl'],2),$r['plPct'].'%',
            number_format($r['costBHD'],3),number_format($r['valBHD'],3),
            $r['acc_name']??'',date('d M Y',strtotime($r['last_updated']))
        ]);
    }
    fputcsv($out,[]);
    fputcsv($out,['TOTAL','','','','','','','','','','',
        number_format($totalCost,3),number_format($totalValue,3),'','']);
    fputcsv($out,['Total P&L (BHD)','','','','','','','','',
        number_format($totalPL,3),$totalPLPct.'%','','','','']);
    fclose($out); exit;
}

// PDF (print-ready HTML)
?>
<!DOCTYPE html><html><head>
<meta charset="UTF-8">
<title>Portfolio Report — <?=date('d M Y')?></title>
<style>
@media print{.no-print{display:none;}}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:11px;color:#111;margin:0;padding:16px;}
h1{font-size:16px;color:#2c3e50;margin:0 0 4px;}
.meta{color:#666;font-size:10px;margin-bottom:14px;}
.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px;}
.sbox{border:1px solid #ddd;border-radius:6px;padding:10px;text-align:center;}
.slabel{font-size:9px;color:#666;text-transform:uppercase;}
.sval{font-size:15px;font-weight:700;margin-top:3px;}
.green{color:#27ae60;}.red{color:#e74c3c;}.blue{color:#2980b9;}
.mkt-header{background:#2c3e50;color:#fff;padding:6px 10px;font-weight:700;font-size:11px;margin-top:12px;border-radius:4px 4px 0 0;}
table{width:100%;border-collapse:collapse;font-size:10px;}
th{background:#34495e;color:#fff;padding:6px 8px;text-align:left;font-weight:600;}
td{padding:5px 8px;border-bottom:1px solid #eee;}
tr:nth-child(even) td{background:#f9f9f9;}
.total-row td{background:#ecf0f1;font-weight:700;border-top:2px solid #2c3e50;}
.print-btn{background:#2c3e50;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-size:13px;cursor:pointer;margin-bottom:12px;}
.csv-btn{background:#27ae60;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-size:13px;cursor:pointer;margin-bottom:12px;margin-left:8px;text-decoration:none;display:inline-block;}
.footer{margin-top:14px;padding-top:8px;border-top:1px solid #ddd;font-size:9px;color:#999;text-align:center;}
</style>
</head><body>
<div class="no-print" style="margin-bottom:12px;">
  <button onclick="window.print()" class="print-btn">🖨️ Print / Save as PDF</button>
  <a href="portfolio_export.php?format=csv" class="csv-btn">⬇️ Download Excel/CSV</a>
  <a href="portfolio.php" style="margin-left:12px;color:#2980b9;font-size:13px;">← Back to Portfolio</a>
</div>

<h1>💹 Sajjad Finance — Investment Portfolio Report</h1>
<div class="meta">Generated: <?=date('d M Y H:i')?> &nbsp;|&nbsp; Total Holdings: <?=count($rows)?></div>

<div class="summary">
  <div class="sbox"><div class="slabel">Total Cost</div><div class="sval blue">BD <?=number_format($totalCost,3)?></div></div>
  <div class="sbox"><div class="slabel">Current Value</div><div class="sval blue">BD <?=number_format($totalValue,3)?></div></div>
  <div class="sbox"><div class="slabel">Unrealized P&L</div>
    <div class="sval <?=$totalPL>=0?'green':'red'?>">BD <?=number_format($totalPL,3)?><br>
    <span style="font-size:11px;"><?=$totalPLPct>=0?'+':''?><?=$totalPLPct?>%</span></div>
  </div>
</div>

<?php foreach($markets as $mkt=>$items): if(!$items) continue;
  $mktLabel=['BD'=>'🇧🇩 Bangladesh','USA'=>'🇺🇸 USA','UK'=>'🇬🇧 UK','Crypto'=>'🪙 Crypto','Other'=>'🌐 Other'][$mkt]??$mkt;
  $mktCost=array_sum(array_column($items,'costBHD'));
  $mktVal=array_sum(array_column($items,'valBHD'));
  $mktPL=$mktVal-$mktCost;
?>
<div class="mkt-header"><?=$mktLabel?> &nbsp;|&nbsp; Value: BD <?=number_format($mktVal,2)?> &nbsp;|&nbsp; P&L: <span style="color:<?=$mktPL>=0?'#2ecc71':'#e74c3c'?>"><?=$mktPL>=0?'+':''?><?=number_format($mktPL,2)?></span></div>
<table>
  <thead><tr>
    <th>Symbol</th><th>Company</th><th>Qty</th><th>Avg Cost</th><th>Curr Price</th><th>Currency</th>
    <th>Total Cost</th><th>Curr Value</th><th>P&L</th><th>P&L%</th><th>BHD Value</th>
  </tr></thead>
  <tbody>
  <?php foreach($items as $r): $plc=$r['pl']>=0?'green':'red'; ?>
  <tr>
    <td><strong><?=htmlspecialchars($r['symbol'])?></strong></td>
    <td><?=htmlspecialchars($r['company_name'])?></td>
    <td><?=number_format((float)$r['quantity'],2)?></td>
    <td><?=number_format((float)$r['avg_cost'],4)?></td>
    <td><?=number_format((float)$r['current_price'],4)?></td>
    <td><?=$r['currency']?></td>
    <td><?=number_format($r['cost'],2)?></td>
    <td><?=number_format($r['value'],2)?></td>
    <td class="<?=$plc?>"><?=$r['pl']>=0?'+':''?><?=number_format($r['pl'],2)?></td>
    <td class="<?=$plc?>"><?=$r['plPct']>=0?'+':''?><?=$r['plPct']?>%</td>
    <td>BD <?=number_format($r['valBHD'],3)?></td>
  </tr>
  <?php endforeach;?>
  <tr class="total-row">
    <td colspan="6">Market Total</td>
    <td>BD <?=number_format($mktCost,2)?></td>
    <td>BD <?=number_format($mktVal,2)?></td>
    <td class="<?=$mktPL>=0?'green':'red'?>"><?=$mktPL>=0?'+':''?><?=number_format($mktPL,2)?></td>
    <td class="<?=$mktPL>=0?'green':'red'?>"><?=$totalCost>0?round($mktPL/$totalCost*100,1):0?>%</td>
    <td>BD <?=number_format($mktVal,3)?></td>
  </tr>
  </tbody>
</table>
<?php endforeach;?>

<div class="footer">Sajjad Finance · finance.sajjad.bd · <?=date('d M Y H:i')?></div>
</body></html>
