<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Dashboard'; $activePage='dashboard';

// Add dashboard columns if missing
try { db()->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS dashboard_show TINYINT(1) DEFAULT 1"); } catch(Exception $e){}
try { db()->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS dashboard_order INT DEFAULT 0"); } catch(Exception $e){}
try { db()->exec("ALTER TABLE account_groups ADD COLUMN IF NOT EXISTS dashboard_show TINYINT(1) DEFAULT 1"); } catch(Exception $e){}
try { db()->exec("ALTER TABLE account_groups ADD COLUMN IF NOT EXISTS dashboard_order INT DEFAULT 0"); } catch(Exception $e){}
try { db()->exec("ALTER TABLE account_groups ADD COLUMN IF NOT EXISTS dashboard_collapsed TINYINT(1) DEFAULT 0"); } catch(Exception $e){}

// Load accounts with dashboard order, respecting show/hide
try {
    $accounts = db()->query(
        "SELECT a.*, COALESCE(g.dashboard_show,1) as grp_show, COALESCE(g.dashboard_order,g.sort_order,99) as grp_order,
                COALESCE(g.dashboard_collapsed,0) as grp_collapsed
         FROM accounts a
         LEFT JOIN account_groups g ON g.name=a.group_name
         WHERE a.is_active=1
         ORDER BY grp_order, COALESCE(a.dashboard_order,0), a.group_name, a.name"
    )->fetchAll();
} catch(Exception $e) {
    // Fallback without dashboard columns
    $accounts = db()->query(
        "SELECT a.*, 1 as grp_show, COALESCE(g.sort_order,99) as grp_order, 0 as grp_collapsed
         FROM accounts a
         LEFT JOIN account_groups g ON g.name=a.group_name
         WHERE a.is_active=1
         ORDER BY grp_order, a.group_name, a.name"
    )->fetchAll();
}

$groupOrder=[]; $grouped=[]; $groupMeta=[];
$totalAssets=0; $totalLiab=0;
foreach ($accounts as $a) {
    // Skip hidden groups or hidden accounts on dashboard
    $g = $a['group_name'] ?: 'Other';
    if (!in_array($g,$groupOrder)) {
        $groupOrder[]=$g;
        $groupMeta[$g]=['show'=>$a['grp_show'],'collapsed'=>$a['grp_collapsed']];
    }
    $grouped[$g][]=$a;
    if ($a['is_credit_card']) {
        $ccB = getCCBalances($a);
        $totalLiab -= toBHD($ccB['total'],$a['currency']);
    } elseif ($a['type']==='asset') {
        $totalAssets += toBHD((float)$a['balance'],$a['currency']);
    } else {
        $totalLiab += toBHD((float)$a['balance'],$a['currency']);
    }
}

// Monthly stats
$month=date('Y-m');
$mst=db()->prepare("SELECT type,SUM(amount_bhd) as total FROM transactions WHERE DATE_FORMAT(txn_date,'%Y-%m')=? GROUP BY type");
$mst->execute([$month]);
$stats=['income'=>0,'expense'=>0];
foreach($mst->fetchAll() as $r) $stats[$r['type']]=(float)$r['total'];

// Recent transactions
$recent=db()->query("SELECT t.*,a.name as acc_name,b.name as to_acc_name FROM transactions t LEFT JOIN accounts a ON a.id=t.account_id LEFT JOIN accounts b ON b.id=t.to_account_id ORDER BY t.txn_date DESC LIMIT 10")->fetchAll();

// 6-month chart
$ym6=[];for($i=5;$i>=0;$i--)$ym6[]=date('Y-m',strtotime("-{$i} months"));
try{$chartRaw=db()->query("SELECT DATE_FORMAT(txn_date,'%Y-%m') as ym,type,SUM(amount_bhd) as total FROM transactions WHERE txn_date>=DATE_SUB(NOW(),INTERVAL 6 MONTH) GROUP BY ym,type ORDER BY ym")->fetchAll();}catch(Exception $e){$chartRaw=[];}
$cLab=[];$cInc=[];$cExp=[];
foreach($ym6 as $ym){$cLab[]=date('M',strtotime($ym.'-01'));$cInc[]=0;$cExp[]=0;}
foreach($chartRaw as $r){$idx=array_search($r['ym'],$ym6);if($idx!==false){if($r['type']==='income')$cInc[$idx]=(float)$r['total'];if($r['type']==='expense')$cExp[$idx]=(float)$r['total'];}}

// Category pie
try{$cats=db()->prepare("SELECT category,SUM(amount_bhd) as total FROM transactions WHERE DATE_FORMAT(txn_date,'%Y-%m')=? AND type='expense' GROUP BY category ORDER BY total DESC LIMIT 7");$cats->execute([$month]);$catRows=$cats->fetchAll();}catch(Exception $e){$catRows=[];}

// ── Wealth: Portfolio (quantity × current_price, grouped by broker) ──
$portfolioBHD=0; $portfolioByBroker=[];
try {
    // Group by linked account (brokerage house) - uses same toBHD() as portfolio page
    $port = db()->query("
        SELECT 
            COALESCE(a.name, 'Unlinked') as broker,
            p.exchange,
            p.currency,
            SUM(p.quantity * p.current_price) as val,
            COUNT(*) as stocks
        FROM portfolio p
        LEFT JOIN accounts a ON a.id = p.account_id
        WHERE p.quantity > 0 AND p.current_price > 0
        GROUP BY a.name, p.exchange, p.currency
        ORDER BY broker
    ")->fetchAll();
    foreach($port as $p){
        $bhd = toBHD((float)$p['val'], $p['currency']); // use same function as portfolio page
        $portfolioBHD += $bhd;
        // Use broker name; append exchange only for unlinked stocks
        $key = $p['broker']==='Unlinked' 
             ? '⚠️ Unlinked ('.$p['exchange'].')' 
             : $p['broker'];
        if(!isset($portfolioByBroker[$key])){
            $portfolioByBroker[$key] = ['bhd'=>0,'stocks'=>0,'broker'=>$p['broker']];
        }
        $portfolioByBroker[$key]['bhd']    += $bhd;
        $portfolioByBroker[$key]['stocks'] += (int)$p['stocks'];
    }
} catch(Exception $e){}

// ── Wealth: Fixed Assets ──────────────────────────────────
$fixedAssetsBHD=0; $fixedByCategory=[];
try {
    $fas = db()->query("SELECT category, currency, SUM(current_value) as val FROM fixed_assets WHERE current_value>0 AND status='owned' GROUP BY category, currency")->fetchAll();
    foreach($fas as $f){
        $bhd = toBHD((float)$f['val'], $f['currency']);
        $fixedAssetsBHD += $bhd;
        $fixedByCategory[$f['category']] = ($fixedByCategory[$f['category']]??0) + $bhd;
    }
} catch(Exception $e){}

// ── Total Wealth ──────────────────────────────────────────
$totalWealthBHD = $totalAssets + $portfolioBHD + $fixedAssetsBHD + $totalLiab;

require 'header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
.group-header{cursor:pointer;user-select:none;}
.group-header:hover{background:var(--s3)!important;}
.group-total{font-size:1rem;font-weight:800;}
.acc-amount{font-size:.82rem;font-weight:600;}
.group-body{transition:none;}
</style>

<!-- Total Wealth -->
<div class="g3" style="margin-bottom:12px;">
  <div class="card" style="border-color:#4e9af144;grid-column:span 3;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <div>
        <div class="card-title">💎 Total Wealth</div>
        <div data-hide="true" style="font-size:1.8rem;font-weight:800;color:var(--blue);">BD <?=money($totalWealthBHD)?></div>
        <div class="card-sub"><?=date('d M Y')?></div>
      </div>
      <div style="display:flex;gap:20px;flex-wrap:wrap;">
        <div style="text-align:right;">
          <div class="card-title">Bank & Cash</div>
          <div data-hide="true" style="font-weight:700;color:var(--blue);">BD <?=money($totalAssets)?></div>
        </div>
        <?php if($portfolioBHD>0):?>
        <div style="text-align:right;">
          <div class="card-title">Portfolio</div>
          <div data-hide="true" style="font-weight:700;color:var(--green);">BD <?=money($portfolioBHD)?></div>
        </div>
        <?php endif;?>
        <?php if($fixedAssetsBHD>0):?>
        <div style="text-align:right;">
          <div class="card-title">Fixed Assets</div>
          <div data-hide="true" style="font-weight:700;color:var(--green);">BD <?=money($fixedAssetsBHD)?></div>
        </div>
        <?php endif;?>
        <div style="text-align:right;">
          <div class="card-title">Liabilities</div>
          <div data-hide="true" style="font-weight:700;color:var(--red);">-BD <?=money(abs($totalLiab))?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Wealth Breakdown -->
<?php if($portfolioByBroker||$fixedByCategory):?>
<div class="g3" style="margin-bottom:20px;">
  <?php if($portfolioByBroker):?>
  <div class="card">
    <div class="card-title">📈 Portfolio Breakdown</div>
    <?php
    $exchIcons=['DSE'=>'🇧🇩','CSE'=>'🇧🇩','NYSE'=>'🇺🇸','NASDAQ'=>'🇺🇸','Crypto'=>'🪙','Other'=>'🌐'];
    foreach($portfolioByBroker as $exch=>$data):
        $icon = $exchIcons[$exch] ?? '📊';
        $bhd  = $data['bhd'];
        $stocks = $data['stocks'];
    ?>
    <a href="portfolio.php?broker=<?=urlencode($data['broker'])?>"
       style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid var(--s3);text-decoration:none;color:inherit;">
      <span style="font-size:.85rem;"><?=$icon?> <?=htmlspecialchars($exch)?>
        <small style="color:var(--muted);margin-left:4px;"><?=$stocks?> stocks</small>
      </span>
      <span data-hide="true" class="c-blue" style="font-weight:600;font-size:.85rem;">BD <?=money($bhd)?></span>
    </a>
    <?php endforeach;?>
    <a href="portfolio.php" style="display:flex;justify-content:space-between;padding:7px 0;font-weight:700;text-decoration:none;color:inherit;">
      <span>Total</span><span class="c-blue">BD <?=money($portfolioBHD)?></span>
    </a>
  </div>
  <?php endif;?>
  <?php if($fixedByCategory):?>
  <div class="card">
    <div class="card-title">🏠 Fixed Assets Breakdown</div>
    <?php
    $icons=['Land'=>'🏗','Home/Property'=>'🏠','Car/Vehicle'=>'🚗','Gold/Silver'=>'🥇','Electronics'=>'💻','Business'=>'💼','Other'=>'📦'];
    foreach($fixedByCategory as $cat=>$val):?>
    <a href="fixed_assets.php?cat=<?=urlencode($cat)?>"
       style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid var(--s3);text-decoration:none;color:inherit;">
      <span style="font-size:.85rem;"><?=$icons[$cat]??'📦'?> <?=htmlspecialchars($cat)?></span>
      <span data-hide="true" class="c-blue" style="font-weight:600;font-size:.85rem;">BD <?=money($val)?></span>
    </a>
    <?php endforeach;?>
    <a href="fixed_assets.php" style="display:flex;justify-content:space-between;padding:7px 0;font-weight:700;text-decoration:none;color:inherit;">
      <span>Total</span><span class="c-blue">BD <?=money($fixedAssetsBHD)?></span>
    </a>
  </div>
  <?php endif;?>
  <div class="card">
    <div class="card-title">⚖️ Net Position</div>
    <a href="accounts.php" style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--s3);font-size:.85rem;text-decoration:none;color:inherit;">
      <span>🏦 Bank & Cash</span><span data-hide="true" class="c-blue" style="font-weight:600;">BD <?=money($totalAssets)?></span>
    </a>
    <a href="portfolio.php" style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--s3);font-size:.85rem;text-decoration:none;color:inherit;">
      <span>📈 Portfolio</span><span data-hide="true" class="c-blue" style="font-weight:600;">BD <?=money($portfolioBHD)?></span>
    </a>
    <a href="fixed_assets.php" style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--s3);font-size:.85rem;text-decoration:none;color:inherit;">
      <span>🏠 Fixed Assets</span><span data-hide="true" class="c-blue" style="font-weight:600;">BD <?=money($fixedAssetsBHD)?></span>
    </a>
    <a href="accounts.php?type=liability" style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--s3);font-size:.85rem;text-decoration:none;color:var(--red);">
      <span>💳 Liabilities</span><span data-hide="true" style="font-weight:600;">-BD <?=money(abs($totalLiab))?></span>
    </a>
    <div style="display:flex;justify-content:space-between;padding:8px 0;font-weight:800;font-size:1rem;">
      <span>💎 Total Wealth</span><span data-hide="true" class="c-green">BD <?=money($totalWealthBHD)?></span>
    </div>
  </div>
</div>
<?php endif;?>

<!-- Monthly Summary -->
<div class="g3">
  <div class="card"><div class="card-title">Total Assets</div><div class="card-value c-blue">BD <?=money($totalAssets)?></div><div class="card-sub"><?=count($accounts)?> accounts</div></div>
  <div class="card"><div class="card-title">Liabilities</div><div class="card-value c-red">BD <?=money(abs($totalLiab))?></div></div>
  <div class="card"><div class="card-title">Net Worth</div><div class="card-value c-green">BD <?=money($totalAssets+$totalLiab)?></div></div>
</div>

<!-- Monthly -->
<div class="g3">
  <div class="card"><div class="card-title"><?=date('F')?> Income</div><div class="card-value c-green">BD <?=money($stats['income'])?></div></div>
  <div class="card"><div class="card-title"><?=date('F')?> Expense</div><div class="card-value c-red">BD <?=money($stats['expense'])?></div></div>
  <div class="card"><?php $sv=$stats['income']-$stats['expense'];?><div class="card-title"><?=date('F')?> Saved</div><div class="card-value <?=$sv>=0?'c-green':'c-red'?>">BD <?=money($sv)?></div></div>
</div>

<!-- Charts -->
<div class="g2">
  <div class="card"><div class="card-title">Income vs Expense (6 months)</div><div style="position:relative;height:200px;"><canvas id="barChart"></canvas></div></div>
  <div class="card"><div class="card-title">Expenses by Category (<?=date('M Y')?>)</div><div style="position:relative;height:200px;"><canvas id="pieChart"></canvas></div></div>
</div>

<!-- Accounts + Recent -->
<div class="g2">
  <!-- ACCOUNTS GROUPED -->
  <div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:12px 18px;background:var(--s2);display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--s3);">
      <span style="font-weight:700;">Accounts</span>
      <div class="gap-2">
        <a href="dashboard_settings.php" style="font-size:.78rem;color:var(--muted);">⚙️ Customize</a>
        <a href="accounts.php" style="font-size:.82rem;">View all →</a>
      </div>
    </div>
    <div class="scroll-y" style="max-height:600px;">
      <?php foreach($groupOrder as $gname):
        $accs   = $grouped[$gname] ?? [];
        $meta   = $groupMeta[$gname] ?? ['show'=>1,'collapsed'=>0];
        if (!$meta['show']) continue; // hidden group

        // Calc group total
        $gtotal = 0;
        $isCCGroup = false;
        foreach($accs as $a) {
            if (!($a['dashboard_show']??1)) continue;
            if ($a['is_credit_card']) {
                $isCCGroup = true;
                $ccB = getCCBalances($a);
                $gtotal -= toBHD($ccB['total'],$a['currency']);
            } else {
                $gtotal += toBHD((float)$a['balance'],$a['currency']);
            }
        }
        $gcolor = $gtotal >= 0 ? 'var(--blue)' : 'var(--red)';
        $collapsed = $meta['collapsed'] ? 'none' : 'block';
        $arrow     = $meta['collapsed'] ? '▶' : '▼';
        $gid = 'grp_'.md5($gname);
      ?>
        <!-- Group Header - BIGGER font, clickable to expand/collapse -->
        <div class="group-header" style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;background:var(--s2);border-bottom:1px solid var(--s3);" onclick="toggleGroup('<?=$gid?>')">
          <div style="display:flex;align-items:center;gap:8px;">
            <span id="<?=$gid?>_arrow" style="font-size:.7rem;color:var(--muted);"><?=$arrow?></span>
            <span style="font-size:.8rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--text);">
              <?=htmlspecialchars($gname)?>
            </span>
          </div>
          <span class="group-total" style="color:<?=$gcolor?>;">
            <span data-hide="true">BD <?=money($gtotal)?></span>
          </span>
        </div>

        <!-- Group accounts (collapsible) -->
        <div id="<?=$gid?>" class="group-body" style="display:<?=$collapsed?>;">
        <?php foreach($accs as $a):
          if (!($a['dashboard_show']??1)) continue;
          if ($a['is_credit_card']) {
              $ccB = getCCBalances($a);
              $dispBal = -$ccB['total'];
              $balColor = 'var(--red)';
          } else {
              $dispBal  = (float)$a['balance'];
              $balColor = $dispBal < 0 ? 'var(--red)' : 'var(--blue)';
          }
        ?>
        <a href="account_detail.php?id=<?=$a['id']?>" style="display:flex;justify-content:space-between;align-items:center;padding:7px 24px;border-bottom:1px solid var(--s3);color:inherit;text-decoration:none;">
          <div>
            <span style="font-size:.85rem;"><?=htmlspecialchars($a['name'])?></span>
            <?php if($a['is_credit_card']): ?>
              <div style="font-size:.68rem;color:var(--muted);">
                P:<span data-hide="true" style="color:var(--red);"><?=money($ccB['payable'])?></span>
                O:<span data-hide="true" style="color:var(--orange);"><?=money($ccB['outstanding'])?></span>
              </div>
            <?php elseif($a['currency']!=='BHD'): ?>
              <div data-hide="true" style="font-size:.68rem;color:var(--muted);">≈ BD <?=money(toBHD($dispBal,$a['currency']))?></div>
            <?php endif; ?>
          </div>
          <span class="acc-amount" data-hide="true" style="color:<?=$balColor?>;">
            <?=money($dispBal, $a['currency'])?> <?=$a['currency']?>
          </span>
        </a>
        <?php endforeach;?>
        </div>
      <?php endforeach;?>
    </div>
  </div>

  <!-- RECENT TRANSACTIONS -->
  <div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:12px 18px;background:var(--s2);display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--s3);">
      <span style="font-weight:700;">Recent Transactions</span>
      <a href="transactions.php" style="font-size:.82rem;">View all →</a>
    </div>
    <?php if(!$recent):?>
      <div style="padding:30px;text-align:center;color:var(--muted);">No transactions yet.<br><a href="add_transaction.php">+ Add one</a></div>
    <?php else:?>
    <div class="scroll-y" style="max-height:600px;">
      <table class="tbl">
        <thead><tr><th>Date</th><th>Amount</th><th>Category</th><th>Account</th></tr></thead>
        <tbody>
          <?php foreach($recent as $t):$bc=$t['type']==='income'?'badge-inc':($t['type']==='expense'?'badge-exp':'badge-tra');?>
          <tr>
            <td style="color:var(--muted);white-space:nowrap;font-size:.82rem;"><?=date('d M',strtotime($t['txn_date']))?></td>
            <td><span data-hide="true" class="badge <?=$bc?>"><?=money((float)$t['amount'], $t['currency'])?> <?=$t['currency']?></span></td>
            <td style="font-size:.85rem;"><?=htmlspecialchars($t['category']?:ucfirst($t['type']))?></td>
            <td><a href="account_detail.php?id=<?=$t['account_id']?>" style="font-size:.82rem;"><?=htmlspecialchars($t['acc_name']??'')?></a></td>
          </tr>
          <?php endforeach;?>
        </tbody>
      </table>
    </div>
    <?php endif;?>
  </div>
</div>

<script>
// Collapse/expand account groups
function toggleGroup(id) {
    const body  = document.getElementById(id);
    const arrow = document.getElementById(id+'_arrow');
    if (body.style.display==='none') {
        body.style.display='block';
        arrow.textContent='▼';
    } else {
        body.style.display='none';
        arrow.textContent='▶';
    }
}

// Charts
new Chart(document.getElementById('barChart'),{type:'bar',data:{labels:<?=json_encode($cLab)?>,datasets:[
  {label:'Income',data:<?=json_encode($cInc)?>,backgroundColor:'#2ecc7155',borderColor:'#2ecc71',borderWidth:1.5,borderRadius:6},
  {label:'Expense',data:<?=json_encode($cExp)?>,backgroundColor:'#e74c3c55',borderColor:'#e74c3c',borderWidth:1.5,borderRadius:6}
]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:'#8892a4',font:{size:11}}}},scales:{x:{ticks:{color:'#8892a4'},grid:{color:'#2e334722'}},y:{ticks:{color:'#8892a4'},grid:{color:'#2e334744'}}}}});
<?php if($catRows):?>
new Chart(document.getElementById('pieChart'),{type:'doughnut',data:{labels:<?=json_encode(array_column($catRows,'category'))?>,datasets:[{data:<?=json_encode(array_map(fn($c)=>round($c['total'],2),$catRows))?>,backgroundColor:['#e74c3c','#f39c12','#4e9af1','#2ecc71','#9b59b6','#1abc9c','#e67e22','#34495e'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right',labels:{color:'#8892a4',font:{size:11},boxWidth:12}}}}});
<?php endif;?>
</script>
<?php require 'footer.php'; ?>
