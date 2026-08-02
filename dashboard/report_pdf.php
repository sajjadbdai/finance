<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$reportType = $_GET['report']     ?? 'transactions';
$dateFrom   = $_GET['from']       ?? date('Y-m-01');
$dateTo     = $_GET['to']         ?? date('Y-m-d');
$accountId  = (int)($_GET['account_id'] ?? 0);

$account = null;
$openingBalance = null;

if ($reportType === 'account' && $accountId) {
    $accSt = db()->prepare("SELECT * FROM accounts WHERE id=?");
    $accSt->execute([$accountId]);
    $account = $accSt->fetch();

    // Get transactions for this account in date range - ASC for running balance
    $st = db()->prepare(
        "SELECT t.*,a.name as acc_name,b.name as to_acc_name
         FROM transactions t
         LEFT JOIN accounts a ON a.id=t.account_id
         LEFT JOIN accounts b ON b.id=t.to_account_id
         WHERE (t.account_id=? OR t.to_account_id=?)
           AND DATE(t.txn_date) BETWEEN ? AND ?
         ORDER BY t.txn_date ASC, t.id ASC"
    );
    $st->execute([$accountId,$accountId,$dateFrom,$dateTo]);
    $transactions = $st->fetchAll();
    $title = 'Account Statement: ' . ($account['name'] ?? '');

    // Opening balance calculation
    // For CC: start from stored payable+outstanding, then adjust for transactions before period
    // For normal accounts: start from 0, add all transactions before period
    /**
     * Universal opening balance formula for ALL account types:
     *
     * The stored balance field = opening_balance_at_seed + all_transactions_effect
     * So: opening_at_date = current_balance - effect_of_transactions_on_or_after_date
     *
     * For CC accounts: current balance is -(payable+outstanding) from getCCBalances
     * For normal accounts: current balance is the stored balance field
     *
     * Transaction effects on balance:
     *   income   (account=source): +amount
     *   expense  (account=source): -amount
     *   transfer (account=source): -amount (money leaves)
     *   transfer (account=dest):   +amount (money arrives)
     */
    $aid = $accountId;

    // Get current real balance
    if ($account['is_credit_card']) {
        $ccNow = getCCBalances($account);
        $currentBal = -$ccNow['total'];
    } else {
        $currentBal = (float)$account['balance'];
    }

    // Sum effect of ALL transactions on/after dateFrom for this account
    $periodSt = db()->prepare(
        "SELECT COALESCE(SUM(
            CASE
              WHEN type='income'   AND account_id=?      THEN  amount
              WHEN type='expense'  AND account_id=?      THEN -amount
              WHEN type='transfer' AND account_id=?      THEN -amount
              WHEN type='transfer' AND to_account_id=?   THEN  amount
              ELSE 0
            END
         ),0) FROM transactions
         WHERE (account_id=? OR to_account_id=?) AND DATE(txn_date) >= ?"
    );
    $periodSt->execute([$aid,$aid,$aid,$aid,$aid,$aid,$dateFrom]);
    $periodEffect = (float)$periodSt->fetchColumn();

    // Opening balance = what balance was BEFORE this period
    $openingBalance = $currentBal - $periodEffect;

} else {
    // Transaction report - all accounts
    $where  = "WHERE DATE(t.txn_date) BETWEEN ? AND ?";
    $params = [$dateFrom,$dateTo];
    if ($accountId) {
        $where  .= " AND (t.account_id=? OR t.to_account_id=?)";
        $params[]=$accountId; $params[]=$accountId;
    }
    $st = db()->prepare(
        "SELECT t.*,a.name as acc_name,a.currency as acc_cur,b.name as to_acc_name
         FROM transactions t
         LEFT JOIN accounts a ON a.id=t.account_id
         LEFT JOIN accounts b ON b.id=t.to_account_id
         $where ORDER BY t.txn_date ASC, t.id ASC"
    );
    $st->execute($params);
    $transactions = $st->fetchAll();
    $title = 'Transaction Report';
}

$totalInc = 0; $totalExp = 0;
foreach($transactions as $t) {
    if ($t['type']==='income')  $totalInc += (float)$t['amount_bhd'];
    if ($t['type']==='expense') $totalExp += (float)$t['amount_bhd'];
}
?>
<!DOCTYPE html><html><head>
<meta charset="UTF-8">
<title><?=htmlspecialchars($title)?></title>
<style>
@media print{.no-print{display:none;}}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:11px;color:#111;margin:0;padding:16px;}
h1{font-size:16px;color:#2c3e50;margin:0 0 2px;}
.meta{color:#666;font-size:10px;margin-bottom:14px;}
.acc-info{background:#eaf4fb;border:1px solid #b8d9ee;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:11px;}
.acc-info strong{font-size:13px;color:#2c3e50;}
.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px;}
.sbox{border:1px solid #ddd;border-radius:6px;padding:10px;text-align:center;}
.slabel{font-size:9px;color:#666;text-transform:uppercase;}
.sval{font-size:15px;font-weight:700;margin-top:3px;}
.green{color:#27ae60;}.red{color:#e74c3c;}.blue{color:#2980b9;}
table{width:100%;border-collapse:collapse;font-size:10px;}
th{background:#2c3e50;color:#fff;padding:6px 8px;text-align:left;font-weight:600;}
td{padding:5px 8px;border-bottom:1px solid #eee;}
tr:nth-child(even) td{background:#f9f9f9;}
.row-opening td{background:#dbeafe;font-weight:700;font-style:italic;}
.row-closing td{background:#dcfce7;font-weight:700;border-top:2px solid #2c3e50;}
.t-inc{color:#27ae60;font-weight:600;}
.t-exp{color:#e74c3c;font-weight:600;}
.t-tra{color:#2980b9;font-weight:600;}
.print-btn{background:#2c3e50;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-size:13px;cursor:pointer;margin-bottom:12px;}
.footer{margin-top:14px;padding-top:8px;border-top:1px solid #ddd;font-size:9px;color:#999;text-align:center;}
</style>
</head><body>
<div class="no-print" style="margin-bottom:12px;">
  <button onclick="window.print()" class="print-btn">🖨️ Print / Save as PDF</button>
  <a href="reports.php" style="margin-left:12px;color:#2980b9;font-size:13px;">← Back to Reports</a>
</div>

<h1>💰 Sajjad Finance — <?=htmlspecialchars($title)?></h1>
<div class="meta">
  Period: <?=htmlspecialchars($dateFrom)?> to <?=htmlspecialchars($dateTo)?> &nbsp;|&nbsp;
  Generated: <?=date('d M Y H:i')?> &nbsp;|&nbsp;
  Total Records: <?=count($transactions)?>
</div>

<?php if($account):?>
<div class="acc-info">
  <strong><?=htmlspecialchars($account['name'])?></strong> &nbsp;
  <span style="color:#666;"><?=htmlspecialchars($account['group_name'])?> · <?=$account['currency']?> · <?=ucfirst($account['type'])?></span>
  <?php if($account['is_credit_card'] && $account['credit_limit']):?>
  &nbsp;|&nbsp; Credit Limit: <?=$account['currency']?> <?=money((float)$account['credit_limit'], $account['currency'])?>
  <?php endif;?>
</div>
<?php endif;?>

<div class="summary">
  <div class="sbox"><div class="slabel">Total Income</div><div class="sval green">BD <?=money($totalInc)?></div></div>
  <div class="sbox"><div class="slabel">Total Expense</div><div class="sval red">BD <?=money($totalExp)?></div></div>
  <div class="sbox"><?php $net=$totalInc-$totalExp;?>
    <div class="slabel">Net</div>
    <div class="sval <?=$net>=0?'green':'red'?>">BD <?=money($net)?></div>
  </div>
</div>

<table>
  <thead><tr>
    <th>Date</th><th>Type</th><th>Amount</th><th>Currency</th>
    <?php if(!$account):?><th>Account</th><?php endif;?>
    <th>Category</th><th>Note</th>
    <?php if(!$account):?><th>BD Equiv.</th><?php else:?><th>Running Bal (<?=$account['currency']?>)</th><?php if($account['currency']!=='BHD'):?><th>≈ BHD</th><?php endif; endif;?>
  </tr></thead>
  <tbody>

  <?php if($account !== null && $openingBalance !== null):?>
  <tr class="row-opening">
    <td><?=date('d M Y',strtotime($dateFrom))?></td>
    <td colspan="<?=$account?3:5?>"><em>Opening Balance</em></td>
    <td class="blue"><em><?=money($openingBalance, $account['currency'])?> <?=$account['currency']?></em></td>
    <?php if($account['currency']!=='BHD'): $openBHD=toBHD($openingBalance,$account['currency']); ?>
    <td class="blue" style="font-size:10px;"><em>≈ BD <?=money($openBHD)?></em></td>
    <?php else:?><td></td><?php endif;?>
  </tr>
  <?php endif;?>

  <?php
  $runBal = $openingBalance ?? 0;
  foreach($transactions as $t):
    $isSource = $account && ($t['account_id'] == $accountId);
    $cls = $t['type']==='income'?'t-inc':($t['type']==='expense'?'t-exp':'t-tra');

    // Update running balance
    if ($account) {
        if ($t['type']==='income'  && $isSource)  $runBal += (float)$t['amount'];
        elseif ($t['type']==='expense' && $isSource) $runBal -= (float)$t['amount'];
        elseif ($t['type']==='transfer') {
            if ($isSource) $runBal -= (float)$t['amount'];
            else           $runBal += (float)$t['amount'];
        }
    }

    // Display amount with sign
    if ($account) {
        $dispAmt = ($t['type']==='expense' || ($t['type']==='transfer' && $isSource))
            ? '-'.money((float)$t['amount'])
            : money((float)$t['amount']);
    } else {
        $dispAmt = ($t['type']==='expense'?'-':'').money((float)$t['amount']);
    }

    $otherAcc = $account
        ? ($isSource ? ($t['to_acc_name']??'') : ($t['acc_name']??''))
        : (($t['acc_name']??'').($t['to_acc_name']?' → '.$t['to_acc_name']:''));
  ?>
  <tr>
    <td><?=date('d M Y',strtotime($t['txn_date']))?></td>
    <td class="<?=$cls?>"><?=ucfirst($t['type'])?></td>
    <td class="<?=$cls?>"><?=$dispAmt?></td>
    <td><?=$t['currency']?></td>
    <?php if(!$account):?>
    <td><?=htmlspecialchars($otherAcc)?></td>
    <?php endif;?>
    <td><?=htmlspecialchars($t['category']??'')?><?=$t['subcategory']?' › '.htmlspecialchars($t['subcategory']):''?></td>
    <td><?=htmlspecialchars($t['note']??'')?></td>
    <?php if(!$account):?>
    <td><?=money((float)$t['amount_bhd'])?></td>
    <?php else:?>
    <td class="<?=$runBal<0?'red':'green'?>"><?=money($runBal)?></td>
    <?php if($account['currency']!=='BHD'):?>
    <td style="color:#666;font-size:10px;">≈ <?=money(toBHD($runBal,$account['currency']))?></td>
    <?php endif;?>
    <?php endif;?>
  </tr>
  <?php endforeach;?>

  <?php if($account !== null && $openingBalance !== null):?>
  <tr class="row-closing">
    <td><?=date('d M Y',strtotime($dateTo))?></td>
    <td colspan="<?=$account?3:5?>"><strong>Closing Balance</strong></td>
    <td class="<?=$runBal<0?'red':'green'?>"><strong><?=money($runBal, $account['currency'])?> <?=$account['currency']?></strong></td>
    <?php if($account['currency']!=='BHD'): $runBHD=toBHD($runBal,$account['currency']); ?>
    <td class="<?=$runBHD<0?'red':'green'?>" style="font-size:10px;">≈ BD <?=money($runBHD)?></td>
    <?php else:?><td></td><?php endif;?>
  </tr>
  <?php endif;?>

  </tbody>
</table>

<div class="footer">Sajjad Finance · finance.sajjad.bd · <?=date('d M Y H:i')?></div>
</body></html>
