<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Bank Reconciliation'; $activePage='bank_reconciliation'; $backTo='balance_tools.php';

// Get all accounts that have statements
$accts = db()->query("
    SELECT a.id, a.name, a.currency, a.balance as app_balance,
           bs.id as stmt_id, bs.closing_balance as stmt_balance,
           bs.period_to, bs.period_from, bs.currency as stmt_currency,
           bs.opening_balance, bs.total_credits, bs.total_debits, bs.status
    FROM accounts a
    INNER JOIN bank_statements bs ON bs.account_id = a.id
    WHERE a.is_active=1
    ORDER BY bs.period_to DESC, a.name ASC
")->fetchAll();

// Group by account - take most recent statement per account
$byAccount = [];
foreach ($accts as $r) {
    if (!isset($byAccount[$r['id']])) $byAccount[$r['id']] = $r;
}

// Summary counts
$totalAccts    = count($byAccount);
$matchedAccts  = 0;
$diffAccts     = 0;
foreach ($byAccount as $r) {
    $diff = abs((float)$r['app_balance'] - (float)$r['stmt_balance']);
    if ($diff < 0.01) $matchedAccts++; else $diffAccts++;
}

require 'header.php'; ?>

<!-- Summary Cards -->
<div class="g3" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-title">Total Statements</div>
        <div class="card-value c-blue"><?=$totalAccts?></div>
        <div class="card-sub">accounts with statements</div>
    </div>
    <div class="card">
        <div class="card-title">✅ Matched</div>
        <div class="card-value c-green"><?=$matchedAccts?></div>
        <div class="card-sub">balances reconciled</div>
    </div>
    <div class="card">
        <div class="card-title">⚠️ Differences</div>
        <div class="card-value c-red"><?=$diffAccts?></div>
        <div class="card-sub">need attention</div>
    </div>
</div>

<!-- Reconciliation Table -->
<div class="card">
    <div class="section-header">
        <div class="section-title">⚖️ Balance Reconciliation</div>
        <a href="bank_statements.php" class="btn btn-primary btn-sm">+ Import Statement</a>
    </div>
    <?php if (!$byAccount): ?>
    <div style="text-align:center;padding:40px;color:var(--muted);">
        No statements imported yet.<br>
        <a href="bank_statements.php" class="btn btn-primary" style="margin-top:12px;">📤 Import First Statement</a>
    </div>
    <?php else: ?>
    <table class="tbl">
        <tr>
            <th>Account</th>
            <th>Period</th>
            <th style="text-align:right;">App Balance</th>
            <th style="text-align:right;">Statement Balance</th>
            <th style="text-align:right;">Difference</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        <?php foreach ($byAccount as $r):
            $appBal  = (float)$r['app_balance'];
            $stmtBal = (float)$r['stmt_balance'];
            $diff    = $appBal - $stmtBal;
            $absDiff = abs($diff);
            $matched = $absDiff < 0.01;
            $statusColor = $matched ? 'var(--green)' : 'var(--red)';
            $statusLabel = $matched ? '✅ Matched' : '⚠️ Difference';
        ?>
        <tr>
            <td>
                <strong><?=htmlspecialchars($r['name'])?></strong>
                <br><small class="c-muted"><?=$r['currency']?></small>
            </td>
            <td>
                <small><?=$r['period_from']?date('d M Y',strtotime($r['period_from'])):'—'?></small>
                <br><small class="c-muted">to <?=$r['period_to']?date('d M Y',strtotime($r['period_to'])):'—'?></small>
            </td>
            <td data-hide="true" style="text-align:right;font-weight:600;">
                <?=money($appBal, $r['currency'])?> <?=$r['currency']?>
            </td>
            <td data-hide="true" style="text-align:right;font-weight:600;">
                <?=money($stmtBal)?> <?=$r['stmt_currency']?>
            </td>
            <td data-hide="true" style="text-align:right;font-weight:700;color:<?=$matched?'var(--green)':($diff>0?'var(--blue)':'var(--red)')?>">
                <?=$matched?'0.000':($diff>0?'+':'').money($diff)?>
                <?=$r['currency']?>
            </td>
            <td>
                <span style="color:<?=$statusColor?>;font-weight:600;font-size:.82rem;"><?=$statusLabel?></span>
            </td>
            <td>
                <a href="bank_reconciliation_detail.php?id=<?=$r['stmt_id']?>" class="btn btn-ghost btn-sm">📊 Detail</a>
                <a href="bank_statement_view.php?id=<?=$r['stmt_id']?>" class="btn btn-ghost btn-sm">👁 Lines</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<?php require 'footer.php'; ?>
