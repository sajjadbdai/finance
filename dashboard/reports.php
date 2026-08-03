<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Reports'; $activePage='reports'; $backTo='index.php';

$dateFrom  = $_GET['from']       ?? date('Y-m-01');
$dateTo    = $_GET['to']         ?? date('Y-m-d');
$accountId = (int)($_GET['account_id'] ?? 0);

// Build filter
$params = [$dateFrom, $dateTo];
$accFilter = '';
if ($accountId) {
    $accFilter = " AND (t.account_id=? OR t.to_account_id=?)";
    $params[] = $accountId;
    $params[] = $accountId;
}

// Summary stats
try {
    $st = db()->prepare("SELECT type, SUM(amount_bhd) as total, COUNT(*) as cnt FROM transactions t WHERE DATE(t.txn_date) BETWEEN ? AND ? $accFilter GROUP BY type");
    $st->execute($params);
    $stats = ['income'=>0,'expense'=>0,'transfer'=>0];
    $cnts  = ['income'=>0,'expense'=>0,'transfer'=>0];
    foreach ($st->fetchAll() as $r) { $stats[$r['type']]=(float)$r['total']; $cnts[$r['type']]=(int)$r['cnt']; }
} catch(Exception $e) { $stats=['income'=>0,'expense'=>0,'transfer'=>0]; $cnts=$stats; }

// Category breakdown
try {
    $catParams = [$dateFrom, $dateTo];
    if ($accountId) { $catParams[]=$accountId; $catParams[]=$accountId; }
    $catSt = db()->prepare("SELECT category, SUM(amount_bhd) as total, COUNT(*) as cnt FROM transactions t WHERE DATE(t.txn_date) BETWEEN ? AND ? AND type='expense' $accFilter GROUP BY category ORDER BY total DESC LIMIT 10");
    $catSt->execute($catParams);
    $cats = $catSt->fetchAll();
} catch(Exception $e) { $cats=[]; }

// Account activity
try {
    $accSt = db()->prepare(
        "SELECT a.id, a.name, a.currency,
         SUM(CASE WHEN t.type='income' THEN t.amount_bhd WHEN t.type='expense' THEN -t.amount_bhd ELSE 0 END) as net,
         COUNT(*) as cnt
         FROM transactions t
         LEFT JOIN accounts a ON a.id=t.account_id
         WHERE DATE(t.txn_date) BETWEEN ? AND ?
         GROUP BY t.account_id, a.id, a.name, a.currency
         ORDER BY ABS(net) DESC LIMIT 10"
    );
    $accSt->execute([$dateFrom, $dateTo]);
    $accStats = $accSt->fetchAll();
} catch(Exception $e) { $accStats=[]; }

// 6-month chart
$ym6=[];
for($i=5;$i>=0;$i--) $ym6[]=date('Y-m',strtotime("-{$i} months"));
try {
    $chartRaw = db()->query("SELECT DATE_FORMAT(txn_date,'%Y-%m') as ym, type, SUM(amount_bhd) as total FROM transactions WHERE txn_date>=DATE_SUB(NOW(),INTERVAL 6 MONTH) GROUP BY ym,type ORDER BY ym")->fetchAll();
} catch(Exception $e) { $chartRaw=[]; }
$cLab=[];$cInc=[];$cExp=[];
foreach($ym6 as $ym){$cLab[]=date('M',strtotime($ym.'-01'));$cInc[]=0;$cExp[]=0;}
foreach($chartRaw as $r){$idx=array_search($r['ym'],$ym6);if($idx!==false){if($r['type']==='income')$cInc[$idx]=(float)$r['total'];if($r['type']==='expense')$cExp[$idx]=(float)$r['total'];}}

// Accounts for filter dropdown
try {
    $accounts = db()->query("SELECT id,name FROM accounts WHERE is_active=1 ORDER BY group_name,name")->fetchAll();
} catch(Exception $e) { $accounts=[]; }

require 'header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

<!-- More Reports -->
<div class="card" style="margin-bottom:20px;">
  <div class="section-title" style="margin-bottom:12px;">📑 More Reports</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
    <a href="report_financial_statements.php" class="report-link-card">
      <div style="font-size:1.4rem;">📋</div>
      <div style="font-weight:600;margin-top:6px;">Balance Sheet & Income Statement</div>
      <div style="font-size:.78rem;color:var(--muted);margin-top:2px;">Assets, Liabilities, Equity + period P&L</div>
    </a>
    <a href="report_cash_flow.php" class="report-link-card">
      <div style="font-size:1.4rem;">💵</div>
      <div style="font-weight:600;margin-top:6px;">Cash Flow Statement</div>
      <div style="font-size:.78rem;color:var(--muted);margin-top:2px;">Operating vs Investing vs Transfers</div>
    </a>
    <a href="report_networth_trend.php" class="report-link-card">
      <div style="font-size:1.4rem;">📉</div>
      <div style="font-weight:600;margin-top:6px;">Net Worth Trend</div>
      <div style="font-size:.78rem;color:var(--muted);margin-top:2px;">Chart of your monthly snapshots</div>
    </a>
    <a href="report_budget_vs_actual.php" class="report-link-card">
      <div style="font-size:1.4rem;">🎯</div>
      <div style="font-weight:600;margin-top:6px;">Budget vs Actual</div>
      <div style="font-size:.78rem;color:var(--muted);margin-top:2px;">Set limits, track spend by category</div>
    </a>
    <a href="report_portfolio_performance.php" class="report-link-card">
      <div style="font-size:1.4rem;">🚀</div>
      <div style="font-weight:600;margin-top:6px;">Portfolio Performance</div>
      <div style="font-size:.78rem;color:var(--muted);margin-top:2px;">XIRR per investment account</div>
    </a>
    <a href="report_monthly_comparison.php" class="report-link-card">
      <div style="font-size:1.4rem;">🆚</div>
      <div style="font-weight:600;margin-top:6px;">Monthly Comparison</div>
      <div style="font-size:.78rem;color:var(--muted);margin-top:2px;">Compare two months side by side, drill into any number</div>
    </a>
    <a href="balance_tools.php" class="report-link-card">
      <div style="font-size:1.4rem;">⚖️</div>
      <div style="font-weight:600;margin-top:6px;">Balance & Integrity Tools</div>
      <div style="font-size:.78rem;color:var(--muted);margin-top:2px;">Trial Balance, audits, reconciliation</div>
    </a>
  </div>
</div>
<style>
.report-link-card{display:block;padding:14px;border:1px solid var(--s3);border-radius:10px;text-decoration:none;color:inherit;transition:border-color .15s,transform .15s;background:var(--s2);}
.report-link-card:hover{border-color:var(--blue);transform:translateY(-1px);}
</style>

<!-- Date Range Filter -->
<div class="card" style="margin-bottom:20px;">
  <form method="GET">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
      <div><label class="form-label">From</label><input type="date" class="form-control" name="from" value="<?=htmlspecialchars($dateFrom)?>" style="width:150px;"></div>
      <div><label class="form-label">To</label><input type="date" class="form-control" name="to" value="<?=htmlspecialchars($dateTo)?>" style="width:150px;"></div>
      <div><label class="form-label">Account</label>
        <select class="form-control" name="account_id" style="width:180px;">
          <option value="">All Accounts</option>
          <?php foreach($accounts as $a):?><option value="<?=$a['id']?>" <?=$accountId==$a['id']?'selected':''?>><?=htmlspecialchars($a['name'])?></option><?php endforeach;?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="reports.php" class="btn btn-ghost btn-sm">Reset</a>
    </div>
    <!-- Quick ranges -->
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;">
      <?php
      $ranges=['This Month'=>[date('Y-m-01'),date('Y-m-d')],'Last Month'=>[date('Y-m-01',strtotime('last month')),date('Y-m-t',strtotime('last month'))],'Last 30 Days'=>[date('Y-m-d',strtotime('-30 days')),date('Y-m-d')],'Last 90 Days'=>[date('Y-m-d',strtotime('-90 days')),date('Y-m-d')],'This Year'=>[date('Y-01-01'),date('Y-12-31')]];
      foreach($ranges as $label=>[$f,$t]):?>
      <a href="?from=<?=$f?>&to=<?=$t?>&account_id=<?=$accountId?>" class="btn btn-ghost btn-sm" style="<?=$dateFrom===$f&&$dateTo===$t?'border-color:var(--blue);color:var(--blue);':''?>"><?=$label?></a>
      <?php endforeach;?>
    </div>
  </form>
</div>

<!-- Summary -->
<div class="g3">
  <div class="card"><div class="card-title">Income</div><div class="card-value c-green">BD <?=money($stats['income'])?></div><div class="card-sub"><?=$cnts['income']?> transactions</div></div>
  <div class="card"><div class="card-title">Expense</div><div class="card-value c-red">BD <?=money($stats['expense'])?></div><div class="card-sub"><?=$cnts['expense']?> transactions</div></div>
  <div class="card"><?php $net=$stats['income']-$stats['expense'];?><div class="card-title">Net Saved</div><div class="card-value <?=$net>=0?'c-green':'c-red'?>">BD <?=money($net)?></div></div>
</div>

<!-- PDF Export -->
<div class="card" style="margin-bottom:20px;padding:14px 18px;">
  <div style="font-size:.85rem;font-weight:600;margin-bottom:10px;">📄 Export</div>
  <div class="gap-2">
    <a href="report_pdf.php?report=transactions&from=<?=urlencode($dateFrom)?>&to=<?=urlencode($dateTo)?>&account_id=<?=$accountId?>" target="_blank" class="btn btn-primary btn-sm">🖨️ Transaction PDF</a>
    <?php if($accountId):?><a href="report_pdf.php?report=account&from=<?=urlencode($dateFrom)?>&to=<?=urlencode($dateTo)?>&account_id=<?=$accountId?>" target="_blank" class="btn btn-success btn-sm">🖨️ Account PDF</a><?php endif;?>
    <a href="export.php?type=transactions_csv" class="btn btn-ghost btn-sm">⬇️ CSV Export</a>
  </div>
</div>

<!-- Charts -->
<div class="g2">
  <div class="card">
    <div class="card-title">Income vs Expense (6 months)</div>
    <div style="position:relative;height:220px;"><canvas id="barChart"></canvas></div>
  </div>
  <div class="card">
    <div class="card-title">Expenses by Category</div>
    <?php if($cats): $totalExp2=array_sum(array_column($cats,'total')); ?>
    <?php foreach($cats as $c): $pct=$totalExp2>0?round($c['total']/$totalExp2*100):0; ?>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
      <div style="flex:1;font-size:.85rem;"><?=htmlspecialchars($c['category']?:'Other')?></div>
      <div style="width:80px;height:5px;background:var(--s3);border-radius:3px;overflow:hidden;"><div style="width:<?=$pct?>%;height:100%;background:var(--red);border-radius:3px;"></div></div>
      <div style="font-size:.8rem;font-weight:600;width:70px;text-align:right;">BD <?=money($c['total'])?></div>
      <div style="font-size:.75rem;color:var(--muted);width:28px;"><?=$pct?>%</div>
    </div>
    <?php endforeach; ?>
    <?php else:?><div style="color:var(--muted);padding:20px;text-align:center;">No expense data.</div><?php endif;?>
  </div>
</div>

<!-- Account Activity -->
<?php if($accStats):?>
<div class="card" style="margin-bottom:20px;">
  <div class="card-title" style="margin-bottom:12px;">Account Activity — <?=htmlspecialchars($dateFrom)?> to <?=htmlspecialchars($dateTo)?></div>
  <div style="overflow-x:auto;">
  <table class="tbl">
    <thead><tr><th>Account</th><th>Transactions</th><th>Net (BHD)</th><th>PDF</th></tr></thead>
    <tbody>
      <?php foreach($accStats as $a):
        $netVal = (float)($a['net']??0);
        $netColor = $netVal >= 0 ? 'c-green' : 'c-red';
        $accIdForLink = (int)($a['id'] ?? 0);
      ?>
      <tr>
        <td><?=htmlspecialchars($a['name']??'')?></td>
        <td><?=(int)($a['cnt']??0)?></td>
        <td class="<?=$netColor?>" style="font-weight:600;">BD <?=money($netVal)?></td>
        <td><?php if($accIdForLink):?><a href="report_pdf.php?report=account&from=<?=urlencode($dateFrom)?>&to=<?=urlencode($dateTo)?>&account_id=<?=$accIdForLink?>" target="_blank" class="btn btn-ghost btn-sm">PDF</a><?php endif;?></td>
      </tr>
      <?php endforeach;?>
    </tbody>
  </table>
  </div>
</div>
<?php endif;?>

<script>
new Chart(document.getElementById('barChart'),{type:'bar',data:{labels:<?=json_encode($cLab)?>,datasets:[
  {label:'Income',data:<?=json_encode($cInc)?>,backgroundColor:'#2ecc7155',borderColor:'#2ecc71',borderWidth:1.5,borderRadius:6},
  {label:'Expense',data:<?=json_encode($cExp)?>,backgroundColor:'#e74c3c55',borderColor:'#e74c3c',borderWidth:1.5,borderRadius:6}
]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:'#8892a4',font:{size:11}}}},scales:{x:{ticks:{color:'#8892a4'},grid:{color:'#2e334722'}},y:{ticks:{color:'#8892a4'},grid:{color:'#2e334744'}}}}});
</script>
<?php require 'footer.php'; ?>
