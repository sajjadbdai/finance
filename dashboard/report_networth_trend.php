<?php
/**
 * Net Worth Trend
 * Read-only. Charts the monthly snapshots already saved by
 * cron/monthly_networth_snapshot.php into networth_history.
 */
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Net Worth Trend'; $activePage='reports'; $backTo='reports.php';

$rows = [];
try {
    $rows = db()->query("SELECT * FROM networth_history ORDER BY snapshot_date ASC")->fetchAll();
} catch (Exception $e) {}

$labels = []; $netWorthSeries = []; $assetsSeries = []; $liabSeries = [];
foreach ($rows as $r) {
    $labels[] = date('M Y', strtotime($r['snapshot_date']));
    $netWorthSeries[] = round((float)$r['net_worth'], 2);
    $assetsSeries[]   = round((float)$r['bank_cash'] + (float)$r['portfolio'] + (float)$r['fixed_assets'], 2);
    $liabSeries[]     = round(abs((float)$r['liabilities']), 2);
}

$latest = $rows ? end($rows) : null;
$first  = $rows ? $rows[0] : null;
$change = ($latest && $first) ? (float)$latest['net_worth'] - (float)$first['net_worth'] : 0;

require 'header.php';
?>

<div class="no-print" style="text-align:right;margin-bottom:12px;">
  <button onclick="window.print()" class="btn btn-ghost btn-sm">🖨️ Print / Save as PDF</button>
</div>


<?php if(!$rows):?>
<div class="card" style="text-align:center;padding:40px;color:var(--muted);">
  No snapshots yet — networth_history fills in on the 1st of each month via the monthly cron job.
  Check back after it's run at least twice for a meaningful trend.
</div>
<?php else:?>

<div class="g3" style="margin-bottom:16px;">
  <div class="card"><div class="card-title">Latest Net Worth</div><div class="card-value c-blue" data-hide="true">BD <?=money((float)$latest['net_worth'])?></div><div class="card-sub"><?=date('F Y',strtotime($latest['snapshot_date']))?></div></div>
  <div class="card"><div class="card-title">Since First Snapshot</div><div class="card-value <?=$change>=0?'c-green':'c-red'?>" data-hide="true"><?=$change>=0?'+':''?>BD <?=money($change)?></div><div class="card-sub">since <?=date('M Y',strtotime($first['snapshot_date']))?></div></div>
  <div class="card"><div class="card-title">Snapshots on Record</div><div class="card-value c-blue"><?=count($rows)?></div></div>
</div>

<div class="card">
  <div class="section-title" style="margin-bottom:10px;">Net Worth Over Time</div>
  <canvas id="nwChart" height="90"></canvas>
</div>

<div class="card" style="margin-top:16px;padding:0;overflow:hidden;">
  <div class="tbl-wrap"><table class="tbl" style="font-size:.82rem;">
    <tr><th>Month</th><th style="text-align:right;">Assets</th><th style="text-align:right;">Liabilities</th><th style="text-align:right;">Net Worth</th></tr>
    <?php foreach(array_reverse($rows) as $r):?>
    <tr>
      <td><?=date('F Y',strtotime($r['snapshot_date']))?></td>
      <td style="text-align:right;" data-hide="true"><?=money((float)$r['bank_cash']+(float)$r['portfolio']+(float)$r['fixed_assets'])?></td>
      <td style="text-align:right;" data-hide="true"><?=money(abs((float)$r['liabilities']))?></td>
      <td style="text-align:right;font-weight:600;" data-hide="true"><?=money((float)$r['net_worth'])?></td>
    </tr>
    <?php endforeach;?>
  </table></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('nwChart'), {
  type: 'line',
  data: {
    labels: <?=json_encode($labels)?>,
    datasets: [{
      label: 'Net Worth (BD)',
      data: <?=json_encode($netWorthSeries)?>,
      borderColor: '#4e9af1', backgroundColor: '#4e9af122', fill: true, tension: 0.3
    }]
  },
  options: { plugins:{legend:{display:false}}, scales:{ y:{ ticks:{ callback:v=>'BD '+v.toLocaleString() } } } }
});
</script>
<?php endif;?>

<?php require 'footer.php'; ?>
