<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$type  = $_GET['type']  ?? 'transactions';
$month = $_GET['month'] ?? '';

// Helper to get correct CC balance
function getDisplayBalance(array $acc): float {
    if ($acc['is_credit_card'] ?? false) {
        $ccB = getCCBalances($acc);
        return -$ccB['total'];
    }
    return (float)$acc['balance'];
}

if ($type === 'transactions_csv') {
    $where = "WHERE 1=1"; $params = [];
    if ($month) { $where .= " AND DATE_FORMAT(t.txn_date,'%Y-%m')=?"; $params[]=$month; }
    $st = db()->prepare("SELECT t.txn_date,t.type,t.amount,t.currency,t.amount_bhd,a.name as account,b.name as to_account,t.category,t.subcategory,t.note,t.source FROM transactions t LEFT JOIN accounts a ON a.id=t.account_id LEFT JOIN accounts b ON b.id=t.to_account_id $where ORDER BY t.txn_date DESC");
    $st->execute($params); $rows=$st->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transactions_'.($month?:date('Y-m')).'.csv"');
    $out=fopen('php://output','w');
    fprintf($out,chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['Date','Type','Amount','Currency','Amount BHD','Account','To Account','Category','Subcategory','Note','Source']);
    foreach($rows as $r) fputcsv($out,[$r['txn_date'],$r['type'],$r['amount'],$r['currency'],$r['amount_bhd'],$r['account'],$r['to_account'],$r['category'],$r['subcategory'],$r['note'],$r['source']]);
    fclose($out); exit;
}

if ($type === 'accounts_csv') {
    $rows = db()->query("SELECT * FROM accounts WHERE is_active=1 ORDER BY type DESC,group_name,name")->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="accounts_'.date('Y-m-d').'.csv"');
    $out=fopen('php://output','w');
    fprintf($out,chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['Account Name','Group','Type','Currency','Balance','Payable','Outstanding']);
    foreach($rows as $r) {
        $bal = getDisplayBalance($r);
        $pay = $r['is_credit_card'] ? (getCCBalances($r)['payable']) : '';
        $out2 = $r['is_credit_card'] ? (getCCBalances($r)['outstanding']) : '';
        fputcsv($out,[$r['name'],$r['group_name'],$r['type'],$r['currency'],abs($bal),$pay,$out2]);
    }
    fclose($out); exit;
}

if ($type === 'networth_csv') {
    $rows = db()->query("SELECT * FROM accounts WHERE is_active=1 ORDER BY type DESC,group_name,name")->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="networth_'.date('Y-m-d').'.csv"');
    $out=fopen('php://output','w');
    fprintf($out,chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['Account Name','Group','Type','Currency','Balance','BHD Equivalent']);
    foreach($rows as $r) {
        $bal = getDisplayBalance($r);
        fputcsv($out,[$r['name'],$r['group_name'],$r['type'],$r['currency'],abs($bal),toBHD(abs($bal),$r['currency'])]);
    }
    fclose($out); exit;
}

$pageTitle='Export Data'; $activePage='export'; $backTo='index.php';
require 'header.php';
?>
<div style="max-width:600px;">
  <div class="section-header"><div class="section-title">📤 Export Data</div></div>
  <div class="card" style="margin-bottom:16px;">
    <div class="card-title" style="margin-bottom:14px;">Export Transactions</div>
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="type" value="transactions_csv">
      <div><div class="form-label">Month (blank = all)</div><input type="month" class="form-control" name="month" style="width:180px;"></div>
      <button type="submit" class="btn btn-primary">⬇️ Download CSV</button>
    </form>
  </div>
  <div class="card" style="margin-bottom:16px;">
    <div class="card-title" style="margin-bottom:14px;">Export Accounts</div>
    <div class="gap-2">
      <a href="?type=accounts_csv" class="btn btn-success">⬇️ Accounts CSV</a>
      <a href="?type=networth_csv" class="btn btn-primary">⬇️ Net Worth CSV</a>
    </div>
  </div>
  <div class="card" style="margin-bottom:16px;">
    <div class="card-title" style="margin-bottom:14px;">Import</div>
    <div style="font-size:.8rem;color:var(--muted);margin-bottom:10px;">Re-import a CSV that this app itself exported (not Money Manager).</div>
    <a href="import_own_export.php" class="btn btn-primary">📥 Import Own Export</a>
  </div>
  <div class="card" style="border:1px solid var(--red);">
    <div class="card-title" style="margin-bottom:14px;color:var(--red);">⚠️ Danger Zone</div>
    <div style="font-size:.8rem;color:var(--muted);margin-bottom:10px;">Permanently delete all transactions and reset balances to 0.</div>
    <a href="reset_data.php" class="btn btn-danger">🗑️ Reset All Transactions</a>
  </div>
</div>
<?php require 'footer.php'; ?>
