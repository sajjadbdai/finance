<?php
/**
 * Budget vs Actual
 * Set a monthly limit per category (in BHD), compare against actual
 * spend for the selected month. Requires the `budgets` table from
 * migration_double_entry.sql.
 */
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Budget vs Actual'; $activePage='reports'; $backTo='reports.php';

$msg = '';
// Save/update a budget
if (isset($_POST['do_save_budget'])) {
    $cat = trim($_POST['category'] ?? '');
    $lim = (float)($_POST['monthly_limit_bhd'] ?? 0);
    if ($cat && $lim >= 0) {
        db()->prepare("INSERT INTO budgets (category,monthly_limit_bhd) VALUES (?,?) ON DUPLICATE KEY UPDATE monthly_limit_bhd=VALUES(monthly_limit_bhd), is_active=1")
            ->execute([$cat, $lim]);
        $msg = 'Budget saved.';
    }
}
if (isset($_GET['deactivate']) ) {
    db()->prepare("UPDATE budgets SET is_active=0 WHERE id=?")->execute([(int)$_GET['deactivate']]);
    header('Location: report_budget_vs_actual.php'); exit;
}

$month = $_GET['month'] ?? date('Y-m');
$budgets = db()->query("SELECT * FROM budgets WHERE is_active=1 ORDER BY category")->fetchAll();

// Actual spend by category this month (native expenses only, excludes stock purchases — those are asset swaps, not spending)
$st = db()->prepare(
    "SELECT category, SUM(amount) as amt, currency FROM transactions
     WHERE type='expense' AND DATE_FORMAT(txn_date,'%Y-%m')=?
       AND NOT (category='Investment' AND subcategory='Stock Purchase')
       AND NOT (category='Fixed Asset' AND subcategory='Asset Purchase')
     GROUP BY category, currency"
);
$st->execute([$month]);
$actualByCat = [];
foreach ($st->fetchAll() as $r) {
    $cat = $r['category'] ?: 'Uncategorized';
    $actualByCat[$cat] = ($actualByCat[$cat] ?? 0) + toBHD((float)$r['amt'], $r['currency']);
}

// All categories that have EITHER a budget OR actual spend this month
$allCats = array_unique(array_merge(array_column($budgets,'category'), array_keys($actualByCat)));
sort($allCats);

$budgetMap = [];
foreach ($budgets as $b) $budgetMap[$b['category']] = $b;

$totalBudget = array_sum(array_column($budgets,'monthly_limit_bhd'));
$totalActual = array_sum($actualByCat);

// Existing categories list for the dropdown (from your Categories page)
$categories = [];
try { $categories = db()->query("SELECT DISTINCT name FROM categories WHERE type='expense' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){}

require 'header.php';
?>

<div class="no-print" style="text-align:right;margin-bottom:12px;">
  <button onclick="window.print()" class="btn btn-ghost btn-sm">🖨️ Print / Save as PDF</button>
</div>

<?php if($msg):?><div class="alert alert-success">✅ <?=htmlspecialchars($msg)?></div><?php endif;?>

<div class="card" style="margin-bottom:16px;">
  <form method="GET" style="display:flex;gap:10px;align-items:flex-end;">
    <div><div class="form-label">Month</div><input type="month" class="form-control" name="month" value="<?=htmlspecialchars($month)?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">View</button>
  </form>
</div>

<div class="g3" style="margin-bottom:16px;">
  <div class="card"><div class="card-title">Total Budgeted</div><div class="card-value c-blue" data-hide="true">BD <?=money($totalBudget)?></div></div>
  <div class="card"><div class="card-title">Total Actual</div><div class="card-value <?=$totalActual>$totalBudget&&$totalBudget>0?'c-red':'c-blue'?>" data-hide="true">BD <?=money($totalActual)?></div></div>
  <div class="card"><div class="card-title">Remaining</div><div class="card-value <?=($totalBudget-$totalActual)>=0?'c-green':'c-red'?>" data-hide="true">BD <?=money($totalBudget-$totalActual)?></div></div>
</div>

<div class="card" style="padding:0;overflow:hidden;margin-bottom:20px;">
  <div style="padding:12px 18px;background:var(--s2);font-weight:700;"><?=date('F Y',strtotime($month.'-01'))?> — By Category</div>
  <div class="tbl-wrap"><table class="tbl" style="font-size:.85rem;">
    <tr><th>Category</th><th style="text-align:right;">Budget (BD)</th><th style="text-align:right;">Actual (BD)</th><th>Progress</th><th style="text-align:right;">Remaining</th></tr>
    <?php foreach($allCats as $cat):
      $budget = (float)($budgetMap[$cat]['monthly_limit_bhd'] ?? 0);
      $actual = (float)($actualByCat[$cat] ?? 0);
      $pct = $budget > 0 ? round($actual/$budget*100) : ($actual>0 ? 100 : 0);
      $over = $budget > 0 && $actual > $budget;
      $remaining = $budget - $actual;
    ?>
    <tr>
      <td><?=htmlspecialchars($cat)?><?=isset($budgetMap[$cat])?' <a href="?deactivate='.$budgetMap[$cat]['id'].'" onclick="return confirm(\'Remove budget for '.htmlspecialchars($cat).'?\')" style="font-size:.7rem;color:var(--muted);">✕</a>':''?></td>
      <td style="text-align:right;" data-hide="true"><?=$budget>0?money($budget):'—'?></td>
      <td style="text-align:right;" class="<?=$over?'c-red':''?>" data-hide="true"><?=money($actual)?></td>
      <td style="min-width:100px;">
        <?php if($budget>0):?>
        <div style="height:6px;background:var(--s3);border-radius:3px;"><div style="width:<?=min(100,$pct)?>%;height:100%;background:<?=$over?'var(--red)':'var(--blue)'?>;border-radius:3px;"></div></div>
        <div style="font-size:.7rem;color:var(--muted);"><?=$pct?>%</div>
        <?php else:?><span class="c-muted" style="font-size:.75rem;">no budget set</span><?php endif;?>
      </td>
      <td style="text-align:right;" class="<?=$remaining>=0?'c-green':'c-red'?>" data-hide="true"><?=$budget>0?money($remaining):'—'?></td>
    </tr>
    <?php endforeach;?>
    <?php if(!$allCats):?><tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px;">No budgets or spend recorded yet.</td></tr><?php endif;?>
  </table></div>
</div>

<div class="card">
  <div class="section-title" style="margin-bottom:10px;">Set / Update a Budget</div>
  <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <input type="hidden" name="do_save_budget" value="1">
    <div><div class="form-label">Category</div>
      <input class="form-control" name="category" list="cat_list" placeholder="e.g. Groceries" required style="width:200px;">
      <datalist id="cat_list"><?php foreach($categories as $c):?><option value="<?=htmlspecialchars($c)?>"><?php endforeach;?></datalist>
    </div>
    <div><div class="form-label">Monthly Limit (BD)</div><input class="form-control" type="number" step="0.01" name="monthly_limit_bhd" required style="width:150px;"></div>
    <button type="submit" class="btn btn-primary btn-sm">Save Budget</button>
  </form>
  <div class="hint" style="margin-top:8px;">Budgets are set once, in BHD, and apply every month going forward until you remove them. There's no per-month override yet — just one recurring monthly limit per category.</div>
</div>

<?php require 'footer.php'; ?>
