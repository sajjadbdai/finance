<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: accounts.php'); exit; }

// Handle delete transaction
if (isset($_GET['delete_txn']) && is_numeric($_GET['delete_txn'])) {
    try {
        $txnId = (int)$_GET['delete_txn'];
        $txn = db()->prepare("SELECT * FROM transactions WHERE id=?");
        $txn->execute([$txnId]);
        $t = $txn->fetch();
        if ($t) {
            $amt = (float)$t['amount_bhd'];
            $rawAmt = (float)$t['amount'];
            if ($t['type']==="income") {
                db()->prepare("UPDATE accounts SET balance=balance-? WHERE id=?")->execute([$amt,$t['account_id']]);
            } elseif ($t['type']==="expense") {
                db()->prepare("UPDATE accounts SET balance=balance+? WHERE id=?")->execute([$amt,$t['account_id']]);
            } elseif ($t['type']==="transfer") {
                db()->prepare("UPDATE accounts SET balance=balance+? WHERE id=?")->execute([$rawAmt,$t['account_id']]);
                if ($t['to_account_id']) {
                    db()->prepare("UPDATE accounts SET balance=balance-? WHERE id=?")->execute([$rawAmt,$t['to_account_id']]);
                }
            }
            db()->prepare("DELETE FROM transactions WHERE id=?")->execute([$txnId]);
        }
    } catch(Exception $e) {}
    header("Location: /dashboard/account_detail.php?id=" . $id . "&msg=deleted");
    exit;
}

$accSt = db()->prepare("SELECT * FROM accounts WHERE id=?");
$accSt->execute([$id]);
$account = $accSt->fetch();
if (!$account) { header('Location: accounts.php'); exit; }

$pageTitle  = $account['name'];
$activePage = 'accounts';

// Filters
$filterMonth = $_GET['month'] ?? '';
$filterType  = $_GET['type']  ?? '';
$search      = $_GET['search'] ?? '';

$where = "WHERE (t.account_id=? OR t.to_account_id=?)";
$params = [$id,$id];
if ($filterMonth) { $where .= " AND DATE_FORMAT(t.txn_date,'%Y-%m')=?"; $params[]=$filterMonth; }
if ($filterType)  { $where .= " AND t.type=?"; $params[]=$filterType; }
if ($search)      { $where .= " AND (t.category LIKE ? OR t.note LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; }

$st = db()->prepare("SELECT t.*,a.name as acc_name,b.name as to_acc_name FROM transactions t LEFT JOIN accounts a ON a.id=t.account_id LEFT JOIN accounts b ON b.id=t.to_account_id $where ORDER BY t.txn_date ASC");
$st->execute($params);
$transactions = $st->fetchAll();

// Stats
$statSt = db()->prepare("SELECT type,SUM(amount) as total,COUNT(*) as cnt FROM transactions WHERE account_id=? GROUP BY type");
$statSt->execute([$id]);
$stats=['income'=>0,'expense'=>0,'transfer'=>0]; $cnts=['income'=>0,'expense'=>0,'transfer'=>0];
foreach($statSt->fetchAll() as $r){$stats[$r['type']]=(float)$r['total'];$cnts[$r['type']]=(int)$r['cnt'];}

// Current display balance
$isCC = (bool)$account['is_credit_card'];
if ($isCC) {
    $ccB = getCCBalances($account);
    $displayBal = -$ccB['total'];
} else {
    $displayBal = (float)$account['balance'];
}

// Opening balance = balance before filtered period
$openingBal = 0;
if ($filterMonth) {
    $firstDay = $filterMonth . '-01';
    $obSt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount WHEN type='expense' THEN -amount ELSE 0 END),0) FROM transactions WHERE account_id=? AND DATE(txn_date) < ?");
    $obSt->execute([$id, $firstDay]);
    $openingBal = (float)$obSt->fetchColumn();
    if ($isCC) $openingBal = (float)$account['payable_balance'] + (float)$account['outstanding_balance'] - $openingBal;
}

require 'header.php';
?>

<!-- Account Header -->
<div class="card" style="margin-bottom:20px;">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
    <div>
      <div style="font-size:.8rem;color:var(--muted);margin-bottom:4px;"><?=htmlspecialchars($account['group_name'])?></div>
      <div style="font-size:1.4rem;font-weight:700;"><?=htmlspecialchars($account['name'])?></div>
      <div style="margin-top:6px;display:flex;gap:8px;align-items:center;">
        <span class="badge <?=$account['type']==='asset'?'badge-inc':'badge-exp'?>"><?=strtoupper($account['type'])?></span>
        <?php if($isCC):?><span class="badge badge-tra">💳 CREDIT CARD</span><?php endif;?>
        <span style="color:var(--muted);font-size:.85rem;"><?=$account['currency']?></span>
      </div>
    </div>
    <div style="text-align:right;">
      <div style="font-size:.8rem;color:var(--muted);">Current Balance</div>
      <?php if($isCC):?>
      <div style="font-size:1.8rem;font-weight:700;color:var(--red);">
        -<?=number_format($ccB['total'],2)?> <?=$account['currency']?>
      </div>
      <div style="font-size:.8rem;margin-top:4px;">
        Payable: <span style="color:var(--red);font-weight:600;"><?=number_format($ccB['payable'],2)?></span> &nbsp;|&nbsp;
        Outstanding: <span style="color:var(--orange);font-weight:600;"><?=number_format($ccB['outstanding'],2)?></span>
      </div>
      <?php else:?>
      <div style="font-size:1.8rem;font-weight:700;" class="<?=$displayBal<0?'c-red':'c-blue'?>">
        <?=number_format($displayBal,2)?> <?=$account['currency']?>
      </div>
      <?php if($account['currency']!=='BHD'):?>
      <div style="font-size:.8rem;color:var(--muted);">≈ BD <?=number_format(toBHD($displayBal,$account['currency']),3)?></div>
      <?php endif;?>
      <?php endif;?>
    </div>
  </div>
  <hr class="divider">
  <div class="gap-2">
    <a href="add_transaction.php?account_id=<?=$id?>&amp;return_to=account_detail.php?id=<?=$id?>" class="btn btn-primary btn-sm">+ Add Transaction</a>
    <a href="edit_account.php?id=<?=$id?>" class="btn btn-ghost btn-sm">✏️ Edit Account</a>
    <a href="accounts.php" class="btn btn-ghost btn-sm">← All Accounts</a>
  </div>
</div>

<!-- Stats -->
<div class="g3" style="margin-bottom:20px;">
  <div class="card"><div class="card-title">Total Income</div><div class="card-value c-green"><?=number_format($stats['income'],2)?> <span style="font-size:.9rem"><?=$account['currency']?></span></div><div class="card-sub"><?=$cnts['income']?> txns</div></div>
  <div class="card"><div class="card-title">Total Expense</div><div class="card-value c-red"><?=number_format($stats['expense'],2)?> <span style="font-size:.9rem"><?=$account['currency']?></span></div><div class="card-sub"><?=$cnts['expense']?> txns</div></div>
  <div class="card"><div class="card-title">Transfers</div><div class="card-value c-blue"><?=number_format($stats['transfer'],2)?> <span style="font-size:.9rem"><?=$account['currency']?></span></div><div class="card-sub"><?=$cnts['transfer']?> txns</div></div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;padding:14px 18px;">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <input type="hidden" name="id" value="<?=$id?>">
    <div><div class="form-label">Month</div><input type="month" class="form-control" name="month" value="<?=htmlspecialchars($filterMonth)?>" style="width:160px;"></div>
    <div><div class="form-label">Type</div>
      <select class="form-control" name="type" style="width:130px;">
        <option value="">All</option>
        <option value="income" <?=$filterType==='income'?'selected':''?>>Income</option>
        <option value="expense" <?=$filterType==='expense'?'selected':''?>>Expense</option>
        <option value="transfer" <?=$filterType==='transfer'?'selected':''?>>Transfer</option>
      </select>
    </div>
    <div><div class="form-label">Search</div><input class="form-control" name="search" value="<?=htmlspecialchars($search)?>" placeholder="category or note" style="width:160px;"></div>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="account_detail.php?id=<?=$id?>" class="btn btn-ghost btn-sm">Clear</a>
    <!-- PDF Report -->
    <a href="report_pdf.php?report=account&from=<?=$filterMonth?$filterMonth.'-01':date('Y-01-01')?>&to=<?=$filterMonth?date('Y-m-t',strtotime($filterMonth.'-01')):date('Y-m-d')?>&account_id=<?=$id?>" target="_blank" class="btn btn-ghost btn-sm">🖨️ PDF</a>
  </form>
</div>

<!-- Statement with Opening/Closing Balance -->
<div class="card" style="padding:0;overflow:hidden;">
  <div style="padding:12px 18px;background:var(--s2);display:flex;justify-content:space-between;align-items:center;">
    <span style="font-weight:600;">Transactions (<?=count($transactions)?>)</span>
    <a href="add_transaction.php?account_id=<?=$id?>&amp;return_to=account_detail.php?id=<?=$id?>" class="btn btn-primary btn-sm">+ Add</a>
  </div>

  <?php if($filterMonth):?>
  <!-- Opening Balance Row -->
  <div style="display:flex;justify-content:space-between;padding:10px 18px;background:#4e9af111;border-bottom:1px solid var(--s3);">
    <span style="font-size:.85rem;color:var(--muted);font-style:italic;">Opening Balance (<?=date('d M Y',strtotime($filterMonth.'-01'))?>)</span>
    <span style="font-weight:700;color:var(--blue);"><?=number_format($openingBal,2)?> <?=$account['currency']?></span>
  </div>
  <?php endif;?>

  <?php if(!$transactions):?>
    <div style="padding:40px;text-align:center;color:var(--muted);">No transactions found.</div>
  <?php else:?>
  <div style="overflow-x:auto;">
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Category</th><th>Note</th><th>Other Account</th><th>Running Balance</th><th>Actions</th></tr></thead>
      <tbody>
        <?php
        // Start running balance from correct opening point
        if ($filterMonth) {
            $runningBal = $openingBal;
        } else {
            // Start from current balance and reverse all transactions to find opening
            if ($isCC) {
                // For CC use negative of getCCBalances total as current balance
                $ccBCur = getCCBalances($account);
                $runningBal = -$ccBCur['total'];
            } else {
                $runningBal = (float)$account['balance'];
            }
            // Reverse all shown transactions to get opening balance
            foreach ($transactions as $txn) {
                $src = ($txn['account_id'] == $id);
                if ($txn['type']==='income' && $src)      $runningBal -= (float)$txn['amount'];
                elseif ($txn['type']==='expense' && $src) $runningBal += (float)$txn['amount'];
                elseif ($txn['type']==='transfer') {
                    if ($src) $runningBal += (float)$txn['amount'];
                    else      $runningBal -= (float)$txn['amount'];
                }
            }
        }
        foreach ($transactions as $t):
          $isSource = ($t['account_id'] == $id);
          $otherAcc = $isSource ? $t['to_acc_name'] : $t['acc_name'];
          // Update running balance
          if ($t['type']==='income' && $isSource)       $runningBal += (float)$t['amount'];
          elseif ($t['type']==='expense' && $isSource)  $runningBal -= (float)$t['amount'];
          elseif ($t['type']==='transfer') {
              if ($isSource) $runningBal -= (float)$t['amount'];
              else           $runningBal += (float)$t['amount'];
          }
          $bc=$t['type']==='income'?'badge-inc':($t['type']==='expense'?'badge-exp':'badge-tra');
          $amtColor = $t['type']==='expense'?'c-red':($t['type']==='income'?'c-green':'c-blue');
          $amtSign  = ($t['type']==='expense'||($t['type']==='transfer'&&$isSource)) ? '−' : '+';
        ?>
        <tr>
          <td style="white-space:nowrap;font-size:.82rem;color:var(--muted);"><?=date('d M Y',strtotime($t['txn_date']))?></td>
          <td><span class="badge <?=$bc?>"><?=ucfirst($t['type'])?></span></td>
          <td style="font-weight:600;white-space:nowrap;" class="<?=$amtColor?>">
            <?=$amtSign?><?=number_format((float)$t['amount'],2)?> <?=$t['currency']?>
          </td>
          <td><?=htmlspecialchars($t['category']??'')?><?=$t['subcategory']?' › '.htmlspecialchars($t['subcategory']):''?></td>
          <td style="color:var(--muted);max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($t['note']??'')?></td>
          <td style="color:var(--muted);font-size:.85rem;"><?=htmlspecialchars($otherAcc??'—')?></td>
          <td style="font-weight:600;white-space:nowrap;" class="<?=$runningBal<0?'c-red':'c-blue'?>"><?=number_format($runningBal,2)?></td>
          <td>
            <div class="gap-2">
              <a href="edit_transaction.php?id=<?=$t['id']?>" class="btn btn-ghost btn-sm">Edit</a>
              <a href="account_detail.php?id=<?=$id?>&delete_txn=<?=$t['id']?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')">Del</a>
            </div>
          </td>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table></div>
  </div>
  <?php if($filterMonth):?>
  <!-- Closing Balance Row -->
  <div style="display:flex;justify-content:space-between;padding:10px 18px;background:#2ecc7111;border-top:2px solid var(--s3);">
    <span style="font-size:.85rem;font-weight:600;color:var(--green);">Closing Balance (<?=date('d M Y',strtotime($filterMonth.'-'.date('t',strtotime($filterMonth.'-01'))))?>) </span>
    <span style="font-weight:700;color:var(--green);"><?=number_format($runningBal,2)?> <?=$account['currency']?></span>
  </div>
  <?php endif;?>
  <?php endif;?>
</div>

<?php require 'footer.php'; ?>
