<?php
/**
 * Import Own Export — re-imports a CSV in this app's OWN export format
 * (Export Data → Download CSV), not the Money Manager format that
 * import_excel.php handles.
 *
 * Expected columns (exact header from export.php):
 *   Date,Type,Amount,Currency,Amount BHD,Account,To Account,Category,Subcategory,Note,Source
 *
 * Two-step flow: upload+parse shows a PREVIEW (nothing written yet),
 * then a separate Confirm step actually inserts. Every inserted row
 * goes through updateAccountBalance() and the matching ledger.php
 * posting function, exactly like add_transaction.php — this import
 * path doesn't bypass the same balance/ledger discipline as everything
 * else in the app.
 *
 * "Amount BHD" from the CSV is NOT trusted/reused — it's recomputed
 * fresh via toBHD(amount, currency), since that column has known
 * historical bugs elsewhere in the app.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ledger.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Import Own Export'; $activePage='export'; $backTo='export.php';

$EXPECTED_HEADER = ['Date','Type','Amount','Currency','Amount BHD','Account','To Account','Category','Subcategory','Note','Source'];

$error = ''; $preview = null; $result = null;

function findAccountId(array &$cache, string $name): ?int {
    $name = trim($name);
    if ($name === '') return null;
    if (array_key_exists($name, $cache)) return $cache[$name];
    $st = db()->prepare("SELECT id FROM accounts WHERE name=? LIMIT 1");
    $st->execute([$name]);
    $id = $st->fetchColumn();
    $cache[$name] = $id ? (int)$id : null;
    return $cache[$name];
}

// ── STEP 1: upload + parse + preview (writes nothing) ──────────────
if (isset($_POST['do_upload']) && isset($_FILES['csv_file'])) {
    $tmp = $_FILES['csv_file']['tmp_name'];
    if (!$tmp || !is_uploaded_file($tmp)) {
        $error = 'Upload failed — try again.';
    } else {
        $fh = fopen($tmp, 'r');
        // strip BOM if present
        $bom = fread($fh, 3);
        if ($bom !== chr(0xEF).chr(0xBB).chr(0xBF)) rewind($fh);
        $header = fgetcsv($fh);
        if (!$header || array_map('trim',$header) !== $EXPECTED_HEADER) {
            $error = 'This doesn\'t look like this app\'s own export format. Expected header: ' . implode(', ', $EXPECTED_HEADER) . '. Got: ' . ($header ? implode(', ', $header) : 'empty file');
        } else {
            $accCache = [];
            $rows = [];
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 11) continue;
                [$date,$type,$amount,$currency,,$account,$toAccount,$category,$subcategory,$note,$source] = $row;
                $accId = findAccountId($accCache, $account);
                $toAccId = $type === 'transfer' ? findAccountId($accCache, $toAccount) : null;

                $status = 'ok';
                if (!$accId) $status = 'no_account';
                elseif ($type === 'transfer' && !$toAccId) $status = 'no_to_account';
                else {
                    $dupSt = db()->prepare("SELECT COUNT(*) FROM transactions WHERE txn_date=? AND type=? AND account_id=? AND ROUND(amount,2)=ROUND(?,2) AND category=?");
                    $dupSt->execute([$date, $type, $accId, (float)$amount, $category]);
                    if ((int)$dupSt->fetchColumn() > 0) $status = 'duplicate';
                }

                $rows[] = [
                    'date'=>$date,'type'=>$type,'amount'=>(float)$amount,'currency'=>$currency,
                    'account'=>$account,'account_id'=>$accId,'to_account'=>$toAccount,'to_account_id'=>$toAccId,
                    'category'=>$category,'subcategory'=>$subcategory,'note'=>$note,'source'=>$source,
                    'status'=>$status,
                ];
            }
            fclose($fh);
            $_SESSION['import_preview_rows'] = $rows;
            $preview = $rows;
        }
    }
}

// ── STEP 2: confirm + actually insert ───────────────────────────────
if (isset($_POST['do_confirm'])) {
    $rows = $_SESSION['import_preview_rows'] ?? [];
    $imported = 0; $skippedDup = 0; $skippedNoAcc = 0;
    foreach ($rows as $r) {
        if ($r['status'] === 'duplicate') { $skippedDup++; continue; }
        if ($r['status'] !== 'ok')        { $skippedNoAcc++; continue; }

        $bhd = toBHD($r['amount'], $r['currency']);
        db()->prepare("INSERT INTO transactions (txn_date,type,amount,currency,amount_bhd,account_id,to_account_id,category,subcategory,note,source) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$r['date'],$r['type'],$r['amount'],$r['currency'],$bhd,$r['account_id'],$r['to_account_id'],$r['category'],$r['subcategory'],$r['note'],'import']);
        $txnId = (int)db()->lastInsertId();
        $desc = ucfirst($r['type']) . ($r['category'] ? " — {$r['category']}" : '');

        if ($r['type']==='expense') {
            updateAccountBalance($r['account_id'], -$r['amount']);
            postExpense($txnId, $r['date'], $desc, $r['account_id'], $r['amount'], $r['currency']);
        } elseif ($r['type']==='income') {
            updateAccountBalance($r['account_id'], $r['amount']);
            postIncome($txnId, $r['date'], $desc, $r['account_id'], $r['amount'], $r['currency']);
        } elseif ($r['type']==='transfer' && $r['to_account_id']) {
            updateAccountBalance($r['account_id'], -$r['amount']);
            updateAccountBalance($r['to_account_id'], $r['amount']);
            postTransfer($txnId, $r['date'], $desc, $r['account_id'], $r['to_account_id'], $r['amount'], $r['currency']);
        }
        $imported++;
    }
    unset($_SESSION['import_preview_rows']);
    $result = ['imported'=>$imported, 'skipped_dup'=>$skippedDup, 'skipped_no_acc'=>$skippedNoAcc];
}

require 'header.php';
?>

<div class="card" style="margin-bottom:16px;">
  <div class="section-title" style="margin-bottom:10px;">📥 Import Own Export</div>
  <div style="font-size:.84rem;color:var(--muted);line-height:1.6;">
    For re-importing a CSV that THIS app exported (Export Data → Download CSV) — not a Money Manager
    file (use <a href="import_excel.php">Import Excel</a> for that). Nothing is written to the database
    until you review the preview and click Confirm.
  </div>
</div>

<?php if($error):?><div class="alert alert-danger">❌ <?=htmlspecialchars($error)?></div><?php endif;?>

<?php if($result):?>
<div class="card" style="margin-bottom:16px;border:1px solid var(--green);">
  <div class="section-title" style="margin-bottom:10px;color:var(--green);">✅ Import Complete</div>
  <div class="g3">
    <div class="card"><div class="card-title">Imported</div><div class="card-value c-green"><?=$result['imported']?></div></div>
    <div class="card"><div class="card-title">Skipped — Duplicate</div><div class="card-value c-blue"><?=$result['skipped_dup']?></div></div>
    <div class="card"><div class="card-title">Skipped — No Matching Account</div><div class="card-value <?=$result['skipped_no_acc']>0?'c-orange':'c-green'?>"><?=$result['skipped_no_acc']?></div></div>
  </div>
  <a href="transactions.php" class="btn btn-primary btn-sm" style="margin-top:12px;">View Transactions</a>
</div>

<?php elseif($preview !== null): ?>
<?php
  $okCount = count(array_filter($preview, fn($r)=>$r['status']==='ok'));
  $dupCount = count(array_filter($preview, fn($r)=>$r['status']==='duplicate'));
  $noAccCount = count(array_filter($preview, fn($r)=>in_array($r['status'],['no_account','no_to_account'])));
?>
<div class="card" style="margin-bottom:16px;">
  <div class="section-title" style="margin-bottom:10px;">Preview — <?=count($preview)?> rows read, nothing saved yet</div>
  <div class="g3" style="margin-bottom:14px;">
    <div class="card"><div class="card-title">Will Import</div><div class="card-value c-green"><?=$okCount?></div></div>
    <div class="card"><div class="card-title">Will Skip — Duplicate</div><div class="card-value c-blue"><?=$dupCount?></div></div>
    <div class="card"><div class="card-title">Will Skip — Account Not Found</div><div class="card-value <?=$noAccCount>0?'c-orange':'c-green'?>"><?=$noAccCount?></div></div>
  </div>
  <form method="POST">
    <input type="hidden" name="do_confirm" value="1">
    <button type="submit" class="btn btn-primary" <?=$okCount===0?'disabled':''?>>✅ Confirm — Import <?=$okCount?> Transaction<?=$okCount===1?'':'s'?></button>
    <a href="import_own_export.php" class="btn btn-ghost">Cancel</a>
  </form>
</div>
<div class="card" style="padding:0;overflow:hidden;">
  <div class="tbl-wrap"><table class="tbl" style="font-size:.78rem;">
    <tr><th>Status</th><th>Date</th><th>Type</th><th style="text-align:right;">Amount</th><th>Account</th><th>Category</th></tr>
    <?php foreach(array_slice($preview,0,200) as $r):
      $badge = ['ok'=>'✅','duplicate'=>'⏭️ dup','no_account'=>'⚠️ no acct','no_to_account'=>'⚠️ no dest'][$r['status']];
    ?>
    <tr>
      <td><?=$badge?></td>
      <td style="white-space:nowrap;"><?=htmlspecialchars($r['date'])?></td>
      <td><?=htmlspecialchars($r['type'])?></td>
      <td style="text-align:right;" data-hide="true"><?=money($r['amount'], $r['currency'])?> <?=htmlspecialchars($r['currency'])?></td>
      <td><?=htmlspecialchars($r['account'])?></td>
      <td class="c-muted"><?=htmlspecialchars($r['category'])?></td>
    </tr>
    <?php endforeach;?>
  </table></div>
  <?php if(count($preview)>200):?><div style="padding:10px 18px;font-size:.78rem;color:var(--muted);">+<?=count($preview)-200?> more rows not shown here, but all will be processed on confirm.</div><?php endif;?>
</div>

<?php else: ?>
<div class="card">
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="do_upload" value="1">
    <div class="form-group">
      <label class="form-label">CSV file (from this app's own Export Data page)</label>
      <input type="file" name="csv_file" accept=".csv" required class="form-control">
    </div>
    <button type="submit" class="btn btn-primary">📤 Upload & Preview</button>
  </form>
</div>
<?php endif;?>

<?php require 'footer.php'; ?>
