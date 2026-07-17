<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$id=(int)($_GET['id']??0);
if(!$id){ header('Location: /dashboard/bank_statements.php'); exit; }

$st=db()->prepare("SELECT bs.*,a.name as acc_name,a.id as acc_id FROM bank_statements bs JOIN accounts a ON a.id=bs.account_id WHERE bs.id=?");
$st->execute([$id]); $stmt=$st->fetch();
if(!$stmt){ header('Location: /dashboard/bank_statements.php'); exit; }

$pageTitle='Statement: '.$stmt['acc_name']; $activePage='bank_statements';
$msg=$_GET['msg']??'';

// Handle auto-import transaction
if(isset($_POST['import_line']) && is_numeric($_POST['import_line'])){
    $lineId=(int)$_POST['import_line'];
    $lineSt=db()->prepare("SELECT * FROM bank_statement_lines WHERE id=? AND statement_id=?");
    $lineSt->execute([$lineId,$id]); $line=$lineSt->fetch();
    if($line){
        $type = $line['credit']>0?'income':'expense';
        $amount = $line['credit']>0?$line['credit']:$line['debit'];
        $amtBHD = toBHD($amount,$stmt['currency']);
        try {
            db()->prepare("INSERT INTO transactions (txn_date,type,amount,currency,amount_bhd,account_id,category,note,source) VALUES (?,?,?,?,?,?,?,?,'web')")
               ->execute([$line['line_date'],$type,$amount,$stmt['currency'],$amtBHD,$stmt['account_id'],'Bank Statement',$line['description']]);
            $txnId=db()->lastInsertId();
            db()->prepare("UPDATE bank_statement_lines SET matched_txn_id=?,match_status='matched' WHERE id=?")->execute([$txnId,$lineId]);
            db()->prepare("UPDATE accounts SET balance=balance+? WHERE id=?")->execute([$type==='income'?$amtBHD:-$amtBHD,$stmt['account_id']]);
            $msg='imported';
        } catch(Exception $e){ $msg='error'; }
    }
}

// Handle ignore
if(isset($_GET['ignore']) && is_numeric($_GET['ignore'])){
    db()->prepare("UPDATE bank_statement_lines SET match_status='ignored' WHERE id=? AND statement_id=?")->execute([(int)$_GET['ignore'],$id]);
    header("Location: /dashboard/bank_statement_view.php?id={$id}"); exit;
}

// Handle unmatch
if(isset($_GET['unmatch']) && is_numeric($_GET['unmatch'])){
    db()->prepare("UPDATE bank_statement_lines SET matched_txn_id=NULL,match_status='unmatched' WHERE id=? AND statement_id=?")->execute([(int)$_GET['unmatch'],$id]);
    header("Location: /dashboard/bank_statement_view.php?id={$id}"); exit;
}

// Load lines
$filter=$_GET['filter']??'all';
$whereFilter='';
if($filter==='unmatched') $whereFilter="AND l.match_status='unmatched'";
elseif($filter==='matched') $whereFilter="AND l.match_status='matched'";

$lines=db()->query("SELECT l.*,t.note as txn_note FROM bank_statement_lines l LEFT JOIN transactions t ON t.id=l.matched_txn_id WHERE l.statement_id={$id} {$whereFilter} ORDER BY l.line_date ASC, l.id ASC")->fetchAll();

// Count stats
$stats=db()->query("SELECT match_status,COUNT(*) as cnt FROM bank_statement_lines WHERE statement_id={$id} GROUP BY match_status")->fetchAll(PDO::FETCH_KEY_PAIR);
$total=array_sum($stats);
$matched=$stats['matched']??0;
$unmatched=$stats['unmatched']??0;
$ignored=$stats['ignored']??0;

require 'header.php'; ?>
<?php if($msg==='imported'):?><div class="alert alert-success">✅ Transaction imported!</div><?php endif;?>
<?php if($msg==='error'):?><div class="alert alert-danger">❌ Error importing transaction.</div><?php endif;?>

<!-- Statement Header -->
<div class="card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
        <div>
            <div style="font-weight:700;font-size:1.1rem;"><?=htmlspecialchars($stmt['acc_name'])?></div>
            <div style="color:var(--muted);font-size:.85rem;"><?=htmlspecialchars($stmt['bank_name'])?></div>
            <div style="color:var(--muted);font-size:.82rem;margin-top:4px;">
                <?=$stmt['period_from']?date('d M Y',strtotime($stmt['period_from'])):'—'?>
                → <?=$stmt['period_to']?date('d M Y',strtotime($stmt['period_to'])):'—'?>
            </div>
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;">
            <div style="text-align:right;">
                <div class="card-title">Opening</div>
                <div data-hide="true" style="font-weight:700;color:var(--muted);"><?=number_format($stmt['opening_balance'],3)?> <?=$stmt['currency']?></div>
            </div>
            <div style="text-align:right;">
                <div class="card-title">Closing</div>
                <div data-hide="true" style="font-weight:700;color:var(--blue);"><?=number_format($stmt['closing_balance'],3)?> <?=$stmt['currency']?></div>
            </div>
            <div style="text-align:right;">
                <div class="card-title">Credits</div>
                <div data-hide="true" style="font-weight:700;color:var(--green);">+<?=number_format($stmt['total_credits'],3)?></div>
            </div>
            <div style="text-align:right;">
                <div class="card-title">Debits</div>
                <div data-hide="true" style="font-weight:700;color:var(--red);">-<?=number_format($stmt['total_debits'],3)?></div>
            </div>
        </div>
    </div>
</div>

<!-- Match Progress -->
<div class="card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <div style="font-weight:600;">Reconciliation Progress</div>
        <div style="font-size:.85rem;color:var(--muted);"><?=$matched?>/<?=$total?> matched</div>
    </div>
    <div style="background:var(--s3);border-radius:8px;height:8px;overflow:hidden;">
        <div style="background:var(--green);height:100%;width:<?=$total>0?round($matched/$total*100):0?>%;transition:.3s;"></div>
    </div>
    <div style="display:flex;gap:16px;margin-top:10px;font-size:.8rem;">
        <span>✅ <?=$matched?> matched</span>
        <span style="color:var(--orange);">⏳ <?=$unmatched?> unmatched</span>
        <span style="color:var(--muted);">⏭ <?=$ignored?> ignored</span>
    </div>
</div>

<!-- Filter + Actions -->
<div class="gap-2" style="margin-bottom:16px;flex-wrap:wrap;">
    <a href="?id=<?=$id?>&filter=all" class="btn <?=$filter==='all'?'btn-primary':'btn-ghost'?> btn-sm">All (<?=$total?>)</a>
    <a href="?id=<?=$id?>&filter=unmatched" class="btn <?=$filter==='unmatched'?'btn-primary':'btn-ghost'?> btn-sm" style="color:var(--orange);">⏳ Unmatched (<?=$unmatched?>)</a>
    <a href="?id=<?=$id?>&filter=matched" class="btn <?=$filter==='matched'?'btn-primary':'btn-ghost'?> btn-sm" style="color:var(--green);">✅ Matched (<?=$matched?>)</a>
    <a href="bank_reconciliation.php?id=<?=$id?>" class="btn btn-ghost btn-sm">⚖️ Full Reconciliation</a>
    <a href="bank_statements.php" class="btn btn-ghost btn-sm">← Back</a>
</div>

<!-- Transactions Table -->
<div class="card">
    <?php if(!$lines):?>
    <div style="text-align:center;padding:30px;color:var(--muted);">No transactions found<?=$filter!=='all'?' for this filter':''?>.</div>
    <?php else:?>
    <table class="tbl" style="font-size:.82rem;">
        <tr>
            <th>Date</th>
            <th>Description</th>
            <th style="text-align:right;">Debit</th>
            <th style="text-align:right;">Credit</th>
            <th style="text-align:right;">Balance</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        <?php foreach($lines as $l):
            $statusColor=['matched'=>'var(--green)','ignored'=>'var(--muted)','unmatched'=>'var(--orange)'][$l['match_status']]??'var(--muted)';
            $rowBg=$l['match_status']==='matched'?'background:var(--green)11':($l['match_status']==='ignored'?'opacity:.5':'');
        ?>
        <tr style="<?=$rowBg?>">
            <td style="white-space:nowrap;"><?=$l['line_date']?date('d M Y',strtotime($l['line_date'])):'—'?></td>
            <td>
                <?=htmlspecialchars(substr($l['description'],0,60))?>
                <?php if($l['reference']):?>
                <br><small class="c-muted"><?=htmlspecialchars($l['reference'])?></small>
                <?php endif;?>
                <?php if($l['txn_note']):?>
                <br><small style="color:var(--green);">→ <?=htmlspecialchars($l['txn_note'])?></small>
                <?php endif;?>
            </td>
            <td data-hide="true" style="text-align:right;color:var(--red);"><?=$l['debit']>0?number_format($l['debit'],3):'—'?></td>
            <td data-hide="true" style="text-align:right;color:var(--green);"><?=$l['credit']>0?number_format($l['credit'],3):'—'?></td>
            <td data-hide="true" style="text-align:right;"><?=$l['balance']>0?number_format($l['balance'],3):'—'?></td>
            <td><span style="color:<?=$statusColor?>;font-size:.75rem;font-weight:600;"><?=ucfirst($l['match_status'])?></span></td>
            <td>
                <?php if($l['match_status']==='unmatched'):?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="import_line" value="<?=$l['id']?>">
                    <button type="submit" class="btn btn-success btn-sm" style="font-size:.72rem;padding:3px 7px;">+Add</button>
                </form>
                <a href="?id=<?=$id?>&ignore=<?=$l['id']?>" class="btn btn-ghost btn-sm" style="font-size:.72rem;padding:3px 7px;">Skip</a>
                <?php elseif($l['match_status']==='matched'):?>
                <a href="/dashboard/account_detail.php?id=<?=$stmt['acc_id']?>" class="btn btn-ghost btn-sm" style="font-size:.72rem;">View</a>
                <a href="?id=<?=$id?>&unmatch=<?=$l['id']?>" class="btn btn-ghost btn-sm" style="font-size:.72rem;">Undo</a>
                <?php else:?>
                <a href="?id=<?=$id?>&unmatch=<?=$l['id']?>" class="btn btn-ghost btn-sm" style="font-size:.72rem;">Restore</a>
                <?php endif;?>
            </td>
        </tr>
        <?php endforeach;?>
    </table>
    <?php endif;?>
</div>
<?php require 'footer.php'; ?>
