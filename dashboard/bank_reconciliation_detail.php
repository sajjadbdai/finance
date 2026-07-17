<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$id=(int)($_GET['id']??0);
if(!$id){ header('Location: /dashboard/bank_reconciliation.php'); exit; }

$st=db()->prepare("SELECT bs.*,a.name as acc_name,a.balance as app_balance,a.currency as app_currency FROM bank_statements bs JOIN accounts a ON a.id=bs.account_id WHERE bs.id=?");
$st->execute([$id]); $stmt=$st->fetch();
if(!$stmt){ header('Location: /dashboard/bank_reconciliation.php'); exit; }

$pageTitle='Reconciliation: '.$stmt['acc_name']; $activePage='bank_reconciliation';

// Calculate summary from statement lines
$summary = db()->query("
    SELECT 
        SUM(CASE WHEN credit>0 THEN credit ELSE 0 END) as total_credits,
        SUM(CASE WHEN debit>0 THEN debit ELSE 0 END) as total_debits,
        COUNT(*) as total_lines,
        SUM(CASE WHEN match_status='matched' THEN 1 ELSE 0 END) as matched,
        SUM(CASE WHEN match_status='unmatched' THEN 1 ELSE 0 END) as unmatched
    FROM bank_statement_lines WHERE statement_id={$id}
")->fetch();

$appBal   = (float)$stmt['app_balance'];
$stmtBal  = (float)$stmt['closing_balance'];
$diff     = $appBal - $stmtBal;
$matched  = abs($diff) < 0.01;

// Handle notes save
if (isset($_POST['save_notes'])) {
    db()->prepare("UPDATE bank_statements SET parse_notes=? WHERE id=?")->execute([$_POST['notes']??'',$id]);
    header("Location: /dashboard/bank_reconciliation_detail.php?id={$id}&msg=saved"); exit;
}

// Mark as reconciled
if (isset($_GET['reconcile'])) {
    db()->prepare("UPDATE bank_statements SET status='reconciled' WHERE id=?")->execute([$id]);
    header("Location: /dashboard/bank_reconciliation_detail.php?id={$id}&msg=reconciled"); exit;
}

$msg=$_GET['msg']??'';
require 'header.php'; ?>
<?php if($msg==='saved'):?><div class="alert alert-success">✅ Notes saved!</div><?php endif;?>
<?php if($msg==='reconciled'):?><div class="alert alert-success">✅ Marked as reconciled!</div><?php endif;?>

<div class="gap-2" style="margin-bottom:16px;">
    <a href="bank_reconciliation.php" class="btn btn-ghost btn-sm">← Back</a>
    <a href="bank_statement_view.php?id=<?=$id?>" class="btn btn-ghost btn-sm">👁 View Lines</a>
    <?php if($stmt['status']!=='reconciled'&&$matched):?>
    <a href="?id=<?=$id?>&reconcile=1" class="btn btn-success btn-sm" onclick="return confirm('Mark as reconciled?')">✅ Mark Reconciled</a>
    <?php endif;?>
</div>

<!-- Balance Comparison -->
<div class="card" style="margin-bottom:16px;">
    <div class="section-title" style="margin-bottom:14px;">⚖️ Balance Comparison — <?=htmlspecialchars($stmt['acc_name'])?></div>
    <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;">
        Period: <?=$stmt['period_from']?date('d M Y',strtotime($stmt['period_from'])):'—'?> → <?=$stmt['period_to']?date('d M Y',strtotime($stmt['period_to'])):'—'?>
    </div>

    <?php
    $rows = [
        ['label'=>'Opening Balance (Statement)', 'value'=>$stmt['opening_balance'], 'color'=>'var(--muted)'],
        ['label'=>'+ Total Credits', 'value'=>$stmt['total_credits'], 'color'=>'var(--green)'],
        ['label'=>'- Total Debits', 'value'=>$stmt['total_debits'], 'color'=>'var(--red)'],
        ['label'=>'Statement Closing Balance', 'value'=>$stmtBal, 'color'=>'var(--blue)', 'bold'=>true],
        ['label'=>'App Balance (Current)', 'value'=>$appBal, 'color'=>'var(--blue)', 'bold'=>true],
        ['label'=>'Difference', 'value'=>$diff, 'color'=>$matched?'var(--green)':'var(--red)', 'bold'=>true],
    ];
    foreach($rows as $row): ?>
    <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--s3);">
        <span style="font-size:.88rem;<?=isset($row['bold'])?'font-weight:700;':''?>"><?=$row['label']?></span>
        <span data-hide="true" style="font-weight:<?=isset($row['bold'])?'700':'600'?>;color:<?=$row['color']?>;font-size:.88rem;">
            <?=number_format(abs($row['value']),3)?> <?=$stmt['currency']?>
            <?=isset($row['bold'])&&$row['label']==='Difference'?($matched?' ✅':' ⚠️'):''?>
        </span>
    </div>
    <?php endforeach; ?>
</div>

<!-- Statement Summary -->
<div class="g3" style="margin-bottom:16px;">
    <div class="card">
        <div class="card-title">Total Credits</div>
        <div class="card-value c-green" data-hide="true"><?=number_format($summary['total_credits'],3)?></div>
        <div class="card-sub"><?=$stmt['currency']?></div>
    </div>
    <div class="card">
        <div class="card-title">Total Debits</div>
        <div class="card-value c-red" data-hide="true"><?=number_format($summary['total_debits'],3)?></div>
        <div class="card-sub"><?=$stmt['currency']?></div>
    </div>
    <div class="card">
        <div class="card-title">Transactions</div>
        <div class="card-value c-blue"><?=$summary['total_lines']?></div>
        <div class="card-sub"><?=$summary['matched']?> matched, <?=$summary['unmatched']?> unmatched</div>
    </div>
</div>

<!-- Adjustment Notes -->
<div class="card">
    <div class="section-title" style="margin-bottom:12px;">📝 Adjustment Notes</div>
    <?php if($diff != 0): ?>
    <div style="background:var(--orange)22;border:1px solid var(--orange);border-radius:8px;padding:12px;margin-bottom:14px;font-size:.85rem;">
        ⚠️ <strong>Difference of <?=number_format(abs($diff),3)?> <?=$stmt['currency']?> found.</strong><br>
        <?=$diff>0?'App balance is higher than statement — possible missing expense or double-counted income.':'Statement balance is higher than app — possible missing income or uncounted transaction.'?>
        <br><br>Please review and post manual adjustment entries if needed via the main transactions page.
    </div>
    <?php else: ?>
    <div style="background:var(--green)22;border:1px solid var(--green);border-radius:8px;padding:12px;margin-bottom:14px;font-size:.85rem;">
        ✅ <strong>Balances match perfectly!</strong> No adjustments needed.
    </div>
    <?php endif; ?>
    <form method="POST">
        <div class="form-group">
            <label class="form-label">Notes / Adjustment Details</label>
            <textarea class="form-control" name="notes" rows="4" placeholder="Note any adjustments made, discrepancies found, or actions taken..."><?=htmlspecialchars($stmt['parse_notes']??'')?></textarea>
        </div>
        <button type="submit" name="save_notes" class="btn btn-primary">💾 Save Notes</button>
    </form>
</div>
<?php require 'footer.php'; ?>
