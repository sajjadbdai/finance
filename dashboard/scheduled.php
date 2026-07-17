<?php

require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$msg=''; $error='';

// Run due scheduled payments
function runDuePayments() {
    $due = db()->query("SELECT s.*, a.name as acc_name, b.name as to_acc_name 
        FROM scheduled_payments s 
        LEFT JOIN accounts a ON a.id=s.account_id 
        LEFT JOIN accounts b ON b.id=s.to_account_id 
        WHERE s.is_active=1 AND s.next_run <= CURDATE()
        AND (s.end_date IS NULL OR s.next_run <= s.end_date)
        AND (s.occurrences IS NULL OR s.occurrences_done < s.occurrences)
        AND (s.last_run IS NULL OR s.last_run < CURDATE())"
    )->fetchAll();
    
    $executed = 0;
    foreach ($due as $s) {
        // Create transaction
        $bhd = $s['amount'] * (defined('BHD_RATE') ? BHD_RATE : 1);
        db()->prepare("INSERT INTO transactions (txn_date,type,amount,currency,amount_bhd,account_id,to_account_id,category,subcategory,note,source) VALUES (NOW(),?,?,?,?,?,?,?,?,?,'schedule')")
        ->execute([$s['type'],$s['amount'],$s['currency'],$s['amount'],$s['account_id'],$s['to_account_id']??null,$s['category'],$s['subcategory'],'[Auto] '.$s['note']]);
        
        // Update balances
        if ($s['type']==='expense') {
            db()->prepare("UPDATE accounts SET balance=balance-? WHERE id=?")->execute([$s['amount'],$s['account_id']]);
        } elseif ($s['type']==='income') {
            db()->prepare("UPDATE accounts SET balance=balance+? WHERE id=?")->execute([$s['amount'],$s['account_id']]);
        } elseif ($s['type']==='transfer' && $s['to_account_id']) {
            db()->prepare("UPDATE accounts SET balance=balance-? WHERE id=?")->execute([$s['amount'],$s['account_id']]);
            db()->prepare("UPDATE accounts SET balance=balance+? WHERE id=?")->execute([$s['amount'],$s['to_account_id']]);
        }
        
        // Calculate next run
        $next = date('Y-m-d', strtotime($s['next_run'] . ' +1 ' . $s['frequency']));
        $done = $s['occurrences_done'] + 1;
        $active = 1;
        if ($s['occurrences'] && $done >= $s['occurrences']) $active = 0;
        if ($s['end_date'] && $next > $s['end_date']) $active = 0;
        
        db()->prepare("UPDATE scheduled_payments SET next_run=?,last_run=CURDATE(),occurrences_done=?,is_active=? WHERE id=?")
        ->execute([$next,$done,$active,$s['id']]);
        $executed++;
    }
    return $executed;
}

// Manual run
if (isset($_GET['run'])) {
    $n = runDuePayments();
    $msg = "Executed $n scheduled payment(s).";
}

// Delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    db()->prepare("DELETE FROM scheduled_payments WHERE id=?")->execute([(int)$_GET['delete']]);
    $msg = 'Deleted.';
}

// Toggle active
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    db()->prepare("UPDATE scheduled_payments SET is_active = 1-is_active WHERE id=?")->execute([(int)$_GET['toggle']]);
    header('Location: /dashboard/scheduled.php'); exit;
}

// Save new/edit
if (isset($_POST['do_save'])) {
    $sid     = (int)($_POST['sid'] ?? 0);
    $name    = trim($_POST['name'] ?? '');
    $type    = $_POST['type']      ?? 'expense';
    $amount  = (float)($_POST['amount'] ?? 0);
    $currency= $_POST['currency']  ?? 'BHD';
    $accId   = (int)($_POST['account_id'] ?? 0);
    $toAccId = (int)($_POST['to_account_id'] ?? 0) ?: null;
    $cat     = trim($_POST['category']    ?? '');
    $subcat  = trim($_POST['subcategory'] ?? '');
    $note    = trim($_POST['note']        ?? '');
    $freq    = $_POST['frequency']        ?? 'monthly';
    $startD  = $_POST['start_date']       ?? date('Y-m-d');
    $endD    = $_POST['end_date']         ?: null;
    $occur   = (int)($_POST['occurrences'] ?? 0) ?: null;

    if (!$name || !$amount || !$accId) { $error = 'Name, amount and account are required.'; }
    else {
        if ($sid) {
            db()->prepare("UPDATE scheduled_payments SET name=?,type=?,amount=?,currency=?,account_id=?,to_account_id=?,category=?,subcategory=?,note=?,frequency=?,start_date=?,end_date=?,occurrences=?,next_run=? WHERE id=?")
            ->execute([$name,$type,$amount,$currency,$accId,$toAccId,$cat,$subcat,$note,$freq,$startD,$endD,$occur,$startD,$sid]);
        } else {
            db()->prepare("INSERT INTO scheduled_payments (name,type,amount,currency,account_id,to_account_id,category,subcategory,note,frequency,start_date,end_date,occurrences,next_run) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$name,$type,$amount,$currency,$accId,$toAccId,$cat,$subcat,$note,$freq,$startD,$endD,$occur,$startD]);
        }
        $msg = 'Saved!';
    }
}

$schedules = db()->query("SELECT s.*,a.name as acc_name,b.name as to_acc_name FROM scheduled_payments s LEFT JOIN accounts a ON a.id=s.account_id LEFT JOIN accounts b ON b.id=s.to_account_id ORDER BY s.next_run")->fetchAll();
$accounts  = db()->query("SELECT id,name,currency,group_name FROM accounts WHERE is_active=1 ORDER BY group_name,name")->fetchAll();
$categories= db()->query("SELECT * FROM categories ORDER BY type,parent,name")->fetchAll();
$editItem  = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $st = db()->prepare("SELECT * FROM scheduled_payments WHERE id=?");
    $st->execute([(int)$_GET['edit']]);
    $editItem = $st->fetch();
}

$due = array_filter($schedules, function($s) {
    return $s['is_active'] 
        && $s['next_run'] <= date('Y-m-d')
        && (empty($s['last_run']) || $s['last_run'] < date('Y-m-d'));
});

$pageTitle  = 'Scheduled Payments';
$activePage = 'scheduled';
require __DIR__ . '/header.php';
?>

<?php if($msg):?><div class="alert alert-success">✅ <?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($error):?><div class="alert alert-danger">❌ <?=htmlspecialchars($error)?></div><?php endif;?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">
<!-- Form -->
<div>
  <div class="section-header"><div class="section-title"><?=$editItem?'Edit':'New'?> Scheduled Payment</div></div>
  <div class="card">
    <form method="POST" action="scheduled.php">
      <input type="hidden" name="do_save" value="1">
      <input type="hidden" name="sid" value="<?=$editItem?$editItem['id']:0?>">
      <div class="form-group"><label class="form-label">Name *</label>
        <input class="form-control" name="name" value="<?=htmlspecialchars($editItem['name']??'')?>" placeholder="e.g. Pension DPS 2000" required>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Type</label>
          <select class="form-control" name="type" id="schType" onchange="schTypeChange()">
            <?php foreach(['expense'=>'Expense','income'=>'Income','transfer'=>'Transfer'] as $t=>$l):?>
            <option value="<?=$t?>" <?=($editItem['type']??'expense')===$t?'selected':''?>><?=$l?></option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Frequency</label>
          <select class="form-control" name="frequency">
            <?php foreach(['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','yearly'=>'Yearly'] as $f=>$l):?>
            <option value="<?=$f?>" <?=($editItem['frequency']??'monthly')===$f?'selected':''?>><?=$l?></option>
            <?php endforeach;?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Amount *</label>
          <input class="form-control" type="number" step="0.001" name="amount" value="<?=htmlspecialchars($editItem['amount']??'')?>" required>
        </div>
        <div class="form-group"><label class="form-label">Currency</label>
          <select class="form-control" name="currency">
            <?php foreach(['BHD','BDT','USD','GBP','EUR'] as $c):?>
            <option value="<?=$c?>" <?=($editItem['currency']??'BHD')===$c?'selected':''?>><?=$c?></option>
            <?php endforeach;?>
          </select>
        </div>
      </div>
      <div class="form-group"><label class="form-label" id="schAccLabel">From Account *</label>
        <select class="form-control" name="account_id" required>
          <option value="">— Select —</option>
          <?php $lg='';foreach($accounts as $a){if($a['group_name']!==$lg){if($lg)echo'</optgroup>';echo'<optgroup label="'.htmlspecialchars($a['group_name']).'">';$lg=$a['group_name'];}?><option value="<?=$a['id']?>" <?=($editItem['account_id']??0)==$a['id']?'selected':''?>><?=htmlspecialchars($a['name'])?></option><?php }if($lg)echo'</optgroup>';?>
        </select>
      </div>
      <div class="form-group" id="schToAcc" style="display:<?=($editItem['type']??'')=='transfer'?'block':'none'?>">
        <label class="form-label">To Account</label>
        <select class="form-control" name="to_account_id">
          <option value="">— Select —</option>
          <?php $lg='';foreach($accounts as $a){if($a['group_name']!==$lg){if($lg)echo'</optgroup>';echo'<optgroup label="'.htmlspecialchars($a['group_name']).'">';$lg=$a['group_name'];}?><option value="<?=$a['id']?>" <?=($editItem['to_account_id']??0)==$a['id']?'selected':''?>><?=htmlspecialchars($a['name'])?></option><?php }if($lg)echo'</optgroup>';?>
        </select>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Category</label>
          <select class="form-control" name="category">
            <option value="">— None —</option>
            <?php foreach($categories as $c){if($c['parent'])continue;?><option value="<?=htmlspecialchars($c['name'])?>" <?=($editItem['category']??'')===$c['name']?'selected':''?>><?=$c['icon']?> <?=htmlspecialchars($c['name'])?></option><?php }?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Note</label>
          <input class="form-control" name="note" value="<?=htmlspecialchars($editItem['note']??'')?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Start Date *</label>
          <input class="form-control" type="date" name="start_date" value="<?=htmlspecialchars($editItem['start_date']??date('Y-m-d'))?>">
        </div>
        <div class="form-group"><label class="form-label">End Date (optional)</label>
          <input class="form-control" type="date" name="end_date" value="<?=htmlspecialchars($editItem['end_date']??'')?>">
        </div>
      </div>
      <div class="form-group"><label class="form-label">Number of Occurrences (0 = unlimited)</label>
        <input class="form-control" type="number" name="occurrences" value="<?=htmlspecialchars($editItem['occurrences']??0)?>" min="0" placeholder="e.g. 36 for 3 years monthly">
        <div class="hint">e.g. 36 for monthly DPS over 3 years. Leave 0 for ongoing.</div>
      </div>
      <div class="gap-2">
        <button type="submit" class="btn btn-primary">✅ Save Schedule</button>
        <?php if($editItem):?><a href="scheduled.php" class="btn btn-ghost">Cancel</a><?php endif;?>
      </div>
    </form>
  </div>
</div>

<!-- List -->
<div>
  <div class="section-header"><div class="section-title">All Schedules (<?=count($schedules)?>)</div></div>
  <?php if(!$schedules):?>
    <div class="card" style="text-align:center;color:var(--muted);padding:40px;">No scheduled payments yet.</div>
  <?php else:?>
  <?php foreach($schedules as $s):
    $isDue = $s['is_active'] && $s['next_run'] <= date('Y-m-d');
    $progress = $s['occurrences'] ? round($s['occurrences_done']/$s['occurrences']*100) : null;
  ?>
  <div class="card <?=$s['is_active']?'':'inactive'?>" style="margin-bottom:12px;padding:14px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
      <div>
        <div style="font-weight:600;font-size:.95rem;"><?=htmlspecialchars($s['name'])?></div>
        <div style="font-size:.78rem;color:var(--muted);margin-top:2px;">
          <?=ucfirst($s['frequency'])?> · <?=htmlspecialchars($s['acc_name']??'')?>
          <?=$s['to_acc_name']?' → '.htmlspecialchars($s['to_acc_name']):''?>
        </div>
      </div>
      <div style="text-align:right;">
        <?php $tc=$s['type']==='income'?'c-green':($s['type']==='expense'?'c-red':'c-blue');?>
        <div class="<?=$tc?>" style="font-weight:700;"><?=number_format((float)$s['amount'],2)?> <?=$s['currency']?></div>
        <div style="font-size:.75rem;color:var(--muted);">Next: <?=$s['next_run']?> <?=$isDue?'<span class="due-badge">DUE</span>':''?></div>
      </div>
    </div>
    <?php if($progress!==null):?>
    <div style="height:4px;background:var(--s3);border-radius:2px;margin-bottom:8px;">
      <div style="width:<?=$progress?>%;height:100%;background:var(--blue);border-radius:2px;"></div>
    </div>
    <div style="font-size:.72rem;color:var(--muted);margin-bottom:8px;"><?=$s['occurrences_done']?>/<?=$s['occurrences']?> done (<?=$progress?>%)</div>
    <?php endif;?>
    <div class="gap-2">
      <a href="scheduled.php?toggle=<?=$s['id']?>" class="btn btn-ghost btn-sm"><?=$s['is_active']?'⏸ Pause':'▶ Resume'?></a>
      <a href="scheduled.php?edit=<?=$s['id']?>" class="btn btn-ghost btn-sm">✏️ Edit</a>
      <a href="scheduled.php?delete=<?=$s['id']?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this schedule?')">🗑</a>
    </div>
  </div>
  <?php endforeach;?>
  <?php endif;?>
</div>
</div>
<script>
function schTypeChange(){
  const t=document.getElementById('schType').value;
  document.getElementById('schToAcc').style.display=t==='transfer'?'block':'none';
  document.getElementById('schAccLabel').textContent=t==='transfer'?'From Account *':'Account *';
}

<?php require __DIR__ . '/footer.php'; ?>
