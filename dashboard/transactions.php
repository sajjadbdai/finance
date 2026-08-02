<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ledger.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$pageTitle  = 'Transactions';
$activePage = 'transactions';
$backTo = 'index.php';

// Reverse transaction (was delete) — see ledger.php::reverseTransaction()
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $txnId = (int)$_GET['delete'];
    $r = reverseTransaction($txnId);
    $msgParam = $r['ok'] ? 'reversed' : 'reverse_failed';
    if ($r['ok'] && $r['warning']) $msgParam = 'reversed_with_warning';

    $back = $_GET['back'] ?? '';
    if ($back && is_numeric($back)) {
        header('Location: /dashboard/account_detail.php?id=' . $back . '&msg=' . $msgParam);
    } else {
        header('Location: /dashboard/transactions.php?msg=' . $msgParam);
    } exit;
}

$msg = $_GET['msg'] ?? '';

// Filters
$filterMonth   = $_GET['month']   ?? date('Y-m');
$filterType    = $_GET['type']    ?? '';
$filterAccount = isset($_GET['account']) ? (int)$_GET['account'] : 0;
$search        = trim($_GET['search'] ?? '');
$page          = max(1, (int)($_GET['p'] ?? 1));
$perPage       = 50;

// Build query
$conditions = ["1=1"];
$params     = [];

if ($filterMonth) {
    $conditions[] = "DATE_FORMAT(t.txn_date,'%Y-%m') = ?";
    $params[]     = $filterMonth;
}
if ($filterType) {
    $conditions[] = "t.type = ?";
    $params[]     = $filterType;
}
if ($filterAccount) {
    $conditions[] = "(t.account_id = ? OR t.to_account_id = ?)";
    $params[]     = $filterAccount;
    $params[]     = $filterAccount;
}
if ($search) {
    $conditions[] = "(t.category LIKE ? OR t.note LIKE ?)";
    $params[]     = "%$search%";
    $params[]     = "%$search%";
}

$where = "WHERE " . implode(" AND ", $conditions);

// Count total
$countSt = db()->prepare("SELECT COUNT(*) FROM transactions t $where");
$countSt->execute($params);
$total      = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$offset     = ($page - 1) * $perPage;

// Fetch transactions
$dataSt = db()->prepare(
    "SELECT t.*, a.name as acc_name, b.name as to_acc_name
     FROM transactions t
     LEFT JOIN accounts a ON a.id = t.account_id
     LEFT JOIN accounts b ON b.id = t.to_account_id
     $where
     ORDER BY t.txn_date DESC
     LIMIT $perPage OFFSET $offset"
);
$dataSt->execute($params);
$transactions = $dataSt->fetchAll();

// Summary totals for filtered set
$sumSt = db()->prepare(
    "SELECT t.type, SUM(t.amount_bhd) as total
     FROM transactions t $where GROUP BY t.type"
);
$sumSt->execute($params);
$sums = ['income' => 0, 'expense' => 0, 'transfer' => 0];
foreach ($sumSt->fetchAll() as $r) {
    $sums[$r['type']] = (float)$r['total'];
}

// All accounts for filter dropdown
$allAccounts = db()->query(
    "SELECT id, name FROM accounts WHERE is_active=1 ORDER BY name"
)->fetchAll();

// All categories for filter
$allCats = db()->query(
    "SELECT DISTINCT category FROM transactions WHERE category != '' ORDER BY category"
)->fetchAll(PDO::FETCH_COLUMN);

require 'header.php';
?>

<?php if ($msg === 'saved'): ?>
  <div class="alert alert-success">✅ Transaction saved!</div>
<?php elseif ($msg === 'reversed'): ?>
  <div class="alert alert-success">✅ Transaction reversed. The original stays in your history, marked as reversed, and a new offsetting entry corrected the balance.</div>
<?php elseif ($msg === 'reversed_with_warning'): ?>
  <div class="alert alert-danger">⚠️ Transaction reversed, but this was a stock trade — cash and the ledger are corrected, but you'll need to manually fix the portfolio holding's quantity/avg cost via <a href="trade_stock.php">Trade Stock</a> or Portfolio.</div>
<?php elseif ($msg === 'reverse_failed'): ?>
  <div class="alert alert-danger">❌ Couldn't reverse that transaction — it may already be reversed.</div>
<?php endif; ?>

<!-- Summary -->
<div class="g3">
  <div class="card">
    <div class="card-title">Income (filtered)</div>
    <div class="card-value c-green">BD <?= money($sums['income']) ?></div>
  </div>
  <div class="card">
    <div class="card-title">Expense (filtered)</div>
    <div class="card-value c-red">BD <?= money($sums['expense']) ?></div>
  </div>
  <div class="card">
    <?php $net = $sums['income'] - $sums['expense']; ?>
    <div class="card-title">Net</div>
    <div class="card-value <?= $net >= 0 ? 'c-green' : 'c-red' ?>">BD <?= money($net) ?></div>
  </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
  <form method="GET">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
      <div>
        <div class="form-label">Month</div>
        <input type="month" class="form-control" name="month" value="<?= htmlspecialchars($filterMonth) ?>" style="width:155px;">
      </div>
      <div>
        <div class="form-label">Type</div>
        <select class="form-control" name="type" style="width:120px;">
          <option value="">All</option>
          <option value="income"   <?= $filterType==='income'   ?'selected':'' ?>>Income</option>
          <option value="expense"  <?= $filterType==='expense'  ?'selected':'' ?>>Expense</option>
          <option value="transfer" <?= $filterType==='transfer' ?'selected':'' ?>>Transfer</option>
        </select>
      </div>
      <div>
        <div class="form-label">Account</div>
        <select class="form-control" name="account" style="width:160px;">
          <option value="">All Accounts</option>
          <?php foreach ($allAccounts as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $filterAccount==$a['id']?'selected':'' ?>>
              <?= htmlspecialchars($a['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <div class="form-label">Search</div>
        <input class="form-control" name="search" value="<?= htmlspecialchars($search) ?>"
               placeholder="note / category" style="width:160px;">
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="transactions.php" class="btn btn-ghost btn-sm">Clear</a>
      <a href="add_transaction.php" class="btn btn-success btn-sm">+ Add Transaction</a>
    </div>
  </form>
</div>

<!-- Transactions Table -->
<div class="card" style="padding:0;overflow:hidden;">
  <div style="padding:12px 18px;background:var(--s2);display:flex;justify-content:space-between;align-items:center;">
    <span style="font-size:.85rem;color:var(--muted);">
      Showing <?= count($transactions) ?> of <?= $total ?> — Page <?= $page ?>/<?= $totalPages ?>
    </span>
    <div class="gap-2">
      <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['p' => $page-1])) ?>" class="btn btn-ghost btn-sm">← Prev</a>
      <?php endif; ?>
      <?php if ($page < $totalPages): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['p' => $page+1])) ?>" class="btn btn-ghost btn-sm">Next →</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$transactions): ?>
    <div style="padding:50px;text-align:center;color:var(--muted);">
      No transactions found.
      <a href="add_transaction.php" style="display:block;margin-top:12px;">+ Add your first transaction</a>
    </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <div class="tbl-wrap"><table class="tbl">
      <thead>
        <tr>
          <th>Date</th>
          <th>Type</th>
          <th>Amount</th>
          <th>Account</th>
          <th>Category</th>
          <th>Note</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($transactions as $t): ?>
        <?php
          $badgeClass = 'badge-tra';
          $amtClass   = 'c-blue';
          $sign       = '';
          if ($t['type'] === 'income')  { $badgeClass = 'badge-inc'; $amtClass = 'c-green'; }
          if ($t['type'] === 'expense') { $badgeClass = 'badge-exp'; $amtClass = 'c-red';   $sign = '−'; }
        ?>
        <tr style="<?=$t['reversed_at']?'opacity:.55;':''?>">
          <td style="white-space:nowrap;">
            <div style="font-weight:500;"><?= date('d M Y', strtotime($t['txn_date'])) ?></div>
            <div style="font-size:.75rem;color:var(--muted);"><?= date('H:i', strtotime($t['txn_date'])) ?></div>
          </td>
          <td>
            <span class="badge <?= $badgeClass ?>"><?= ucfirst($t['type']) ?></span>
            <?php if($t['reversed_at']):?><span class="badge badge-exp" title="Reversed on <?=date('d M Y',strtotime($t['reversed_at']))?>">↩️ REVERSED</span><?php endif;?>
            <?php if($t['reversal_of']):?><span class="badge badge-tra" title="Reverses transaction #<?=$t['reversal_of']?>">↩️ Reversal</span><?php endif;?>
          </td>
          <td style="font-weight:600;white-space:nowrap;" class="<?= $amtClass ?>">
            <?= $sign . money((float)$t['amount'], $t['currency']) ?> <?= htmlspecialchars($t['currency']) ?>
            <?php if ($t['currency'] !== 'BHD'): ?>
              <div style="font-size:.72rem;color:var(--muted);">BD <?= money((float)$t['amount_bhd']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($t['account_id']): ?>
              <a href="account_detail.php?id=<?= $t['account_id'] ?>" style="font-size:.88rem;">
                <?= htmlspecialchars($t['acc_name'] ?? '') ?>
              </a>
            <?php endif; ?>
            <?php if ($t['to_acc_name']): ?>
              <div style="font-size:.75rem;color:var(--muted);">→ <?= htmlspecialchars($t['to_acc_name']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($t['category'] ?? '') ?></td>
          <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted);"
              title="<?= htmlspecialchars($t['note'] ?? '') ?>">
            <?= htmlspecialchars($t['note'] ?? '') ?>
          </td>
          <td>
            <div class="gap-2">
              <?php if($t['reversed_at']):?>
                <span class="c-muted" style="font-size:.78rem;">already reversed</span>
              <?php else:?>
                <a href="edit_transaction.php?id=<?= $t['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
                <a href="transactions.php?delete=<?= $t['id'] ?>"
                   class="btn btn-danger btn-sm"
                   onclick="return confirm('Reverse this transaction? The original stays in history, marked as reversed, and a new offsetting entry corrects the balance. Nothing is deleted.')">↩️ Reverse</a>
              <?php endif;?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>
</div>

<?php require 'footer.php'; ?>
