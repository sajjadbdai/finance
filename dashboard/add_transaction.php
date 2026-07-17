<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$editId   = (int)($_POST['edit_id'] ?? $_GET['id'] ?? 0);
$isEdit   = $editId > 0;
$preAccId = (int)($_GET['account_id'] ?? 0);
$msg=''; $error='';

$accounts = db()->query("SELECT id,name,currency,group_name,is_credit_card FROM accounts WHERE is_active=1 ORDER BY group_name,name")->fetchAll();
$allCats  = db()->query("SELECT * FROM categories ORDER BY type,parent,name")->fetchAll();
$catsByType=['expense'=>[],'income'=>[],'transfer'=>[]];
foreach($allCats as $c) $catsByType[$c['type']][]=$c;

$data=['txn_date'=>date('Y-m-d'),'txn_time'=>date('H:i'),'type'=>'expense','amount'=>'',
       'currency'=>'BHD','account_id'=>$preAccId,'to_account_id'=>'',
       'category'=>'','subcategory'=>'','note'=>'','bank_charge'=>0];

if($isEdit){
    $st=db()->prepare("SELECT * FROM transactions WHERE id=?");$st->execute([$editId]);
    $row=$st->fetch();
    if($row){$data=array_merge($data,$row);$data['txn_date']=date('Y-m-d',strtotime($row['txn_date']));$data['txn_time']=date('H:i',strtotime($row['txn_date']));}
}

if(isset($_POST['do_save'])){
    $type       = trim($_POST['type']           ?? 'expense');
    $amount     = (float)($_POST['amount']      ?? 0);
    $currency   = trim($_POST['currency']       ?? 'BHD');
    $accId      = (int)($_POST['account_id']    ?? 0);
    $toAccId    = (int)($_POST['to_account_id'] ?? 0) ?: null;
    $cat        = trim($_POST['category']       ?? '');
    $subcat     = trim($_POST['subcategory']    ?? '');
    $note       = trim($_POST['note']           ?? '');
    $bankCharge = (float)($_POST['bank_charge'] ?? 0);
    $txnDate    = ($_POST['txn_date']??date('Y-m-d')).' '.($_POST['txn_time']??date('H:i')).':00';

    if(!$amount)    { $error='Amount required.'; }
    elseif(!$accId) { $error='Account required.'; }
    elseif($type==='transfer'&&!$toAccId) { $error='Destination account required for transfer.'; }
    else {
        try {
            $bhd = toBHD($amount, $currency);

            if($isEdit) {
                // Reverse old transaction
                $old=db()->prepare("SELECT * FROM transactions WHERE id=?");$old->execute([$editId]);$oldR=$old->fetch();
                if($oldR){
                    if($oldR['type']==='expense')  updateAccountBalance($oldR['account_id'],  (float)$oldR['amount']);
                    elseif($oldR['type']==='income') updateAccountBalance($oldR['account_id'], -(float)$oldR['amount']);
                    elseif($oldR['type']==='transfer'){
                        updateAccountBalance($oldR['account_id'], (float)$oldR['amount']);
                        if($oldR['to_account_id']) updateAccountBalance($oldR['to_account_id'], -(float)$oldR['amount']);
                    }
                }
                db()->prepare("UPDATE transactions SET txn_date=?,type=?,amount=?,currency=?,amount_bhd=?,account_id=?,to_account_id=?,category=?,subcategory=?,note=? WHERE id=?")
                ->execute([$txnDate,$type,$amount,$currency,$bhd,$accId,$toAccId,$cat,$subcat,$note,$editId]);
            } else {
                db()->prepare("INSERT INTO transactions (txn_date,type,amount,currency,amount_bhd,account_id,to_account_id,category,subcategory,note,source) VALUES (?,?,?,?,?,?,?,?,?,?,'web')")
                ->execute([$txnDate,$type,$amount,$currency,$bhd,$accId,$toAccId,$cat,$subcat,$note]);
            }

            // Apply balance changes
            if($type==='expense')       updateAccountBalance($accId, -$amount);
            elseif($type==='income')    updateAccountBalance($accId,  $amount);
            elseif($type==='transfer' && $toAccId){
                updateAccountBalance($accId,    -$amount);
                updateAccountBalance($toAccId,   $amount);
            }

            // Bank charge as separate expense on source account
            if($bankCharge > 0 && ($type==='transfer' || $type==='expense')) {
                $chgBHD = toBHD($bankCharge, $currency);
                db()->prepare("INSERT INTO transactions (txn_date,type,amount,currency,amount_bhd,account_id,category,note,source) VALUES (?,?,?,?,?,?,?,?,'web')")
                ->execute([$txnDate,'expense',$bankCharge,$currency,$chgBHD,$accId,'Bank Charge','Bank charge for: '.$note]);
                updateAccountBalance($accId, -$bankCharge);
            }

                $rt = $_POST['return_to'] ?? '';
    if ($rt && strpos($rt, 'account_detail.php?id=') !== false) {
        header('Location: /dashboard/' . $rt . '&msg=saved');
    } else {
        header('Location: /dashboard/transactions.php?msg=saved');
    }
    exit;
        } catch(Exception $e){ $error=$e->getMessage(); }
    }
}
$pageTitle  = 'Add Transaction';
$activePage = 'add_txn';
require __DIR__ . '/header.php';
?>
<div style="max-width:640px;">
<?php if($error):?><div class="alert alert-danger">❌ <?=htmlspecialchars($error)?></div><?php endif;?>
<?php if($msg):?><div class="alert alert-success">✅ <?=htmlspecialchars($msg)?></div><?php endif;?>
<div class="card">
<form method="POST" action="add_transaction.php">
<input type="hidden" name="return_to" value="<?=htmlspecialchars($_GET['return_to']??'')?>">
<input type="hidden" name="do_save" value="1">
<input type="hidden" name="edit_id" value="<?=$editId?>">

<!-- Type -->
<div class="form-group">
<label class="form-label">Type *</label>
<div style="display:flex;gap:8px;">
<?php foreach(['expense'=>['🔴','Expense'],'income'=>['🟢','Income'],'transfer'=>['🔵','Transfer']] as $t=>[$ico,$lbl]):?>
<label style="flex:1;cursor:pointer;">
  <input type="radio" name="type" value="<?=$t?>" <?=$data['type']===$t?'checked':''?> style="display:none" onchange="onType()">
  <div class="type-btn" data-type="<?=$t?>" style="text-align:center;padding:10px;border:2px solid var(--s3);border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;"><?=$ico?> <?=$lbl?></div>
</label>
<?php endforeach;?>
</div></div>

<div class="form-row">
  <div class="form-group"><label class="form-label">Amount *</label>
    <input class="form-control" type="number" step="0.001" name="amount" value="<?=htmlspecialchars($data['amount'])?>" placeholder="0.000" required></div>
  <div class="form-group"><label class="form-label">Currency</label>
    <select class="form-control" name="currency"><?php foreach(['BHD','BDT','USD','GBP','EUR','SAR','AED'] as $c):?><option value="<?=$c?>" <?=$data['currency']===$c?'selected':''?>><?=$c?></option><?php endforeach;?></select></div>
</div>

<div class="form-row">
  <div class="form-group"><label class="form-label">Date *</label>
    <input class="form-control" type="date" name="txn_date" value="<?=$data['txn_date']?>" required></div>
  <div class="form-group"><label class="form-label">Time</label>
    <input class="form-control" type="time" name="txn_time" value="<?=$data['txn_time']?>"></div>
</div>

<div class="form-group"><label class="form-label" id="accLabel">Account *</label>
<select class="form-control" name="account_id" required>
<option value="">— Select Account —</option>
<?php $lg='';foreach($accounts as $a){if($a['group_name']!==$lg){if($lg)echo'</optgroup>';echo'<optgroup label="'.htmlspecialchars($a['group_name']).'">';$lg=$a['group_name'];}
$ccTag=$a['is_credit_card']?' 💳':'';
?><option value="<?=$a['id']?>" <?=$data['account_id']==$a['id']?'selected':''?>><?=htmlspecialchars($a['name'].$ccTag)?> (<?=$a['currency']?>)</option><?php }if($lg)echo'</optgroup>';?>
</select></div>

<div class="form-group" id="toAccDiv" style="display:<?=$data['type']==='transfer'?'block':'none'?>">
<label class="form-label">Transfer To *</label>
<select class="form-control" name="to_account_id">
<option value="">— Select Destination —</option>
<?php $lg='';foreach($accounts as $a){if($a['group_name']!==$lg){if($lg)echo'</optgroup>';echo'<optgroup label="'.htmlspecialchars($a['group_name']).'">';$lg=$a['group_name'];}?><option value="<?=$a['id']?>" <?=$data['to_account_id']==$a['id']?'selected':''?>><?=htmlspecialchars($a['name'])?> (<?=$a['currency']?>)</option><?php }if($lg)echo'</optgroup>';?>
</select></div>

<!-- Bank charge (shown for transfer/expense) -->
<div class="form-group" id="chargeDiv" style="display:<?=($data['type']==='transfer'||$data['type']==='expense')?'block':'none'?>">
<label class="form-label">Bank Charge (optional)</label>
<input class="form-control" type="number" step="0.001" name="bank_charge" value="<?=htmlspecialchars($data['bank_charge']??0)?>" placeholder="0.000">
<div style="font-size:.75rem;color:var(--muted);margin-top:3px;">Will be booked as separate Bank Charge expense on source account</div>
</div>

<div class="form-row">
<div class="form-group"><label class="form-label">Category <a href="categories.php" target="_blank" style="font-size:.72rem;margin-left:6px;">+ Manage</a></label>
<select class="form-control" name="category" id="catSel" onchange="loadSub()">
<option value="">— Select —</option>
<?php foreach($allCats as $c){if($c['parent'])continue;?><option value="<?=htmlspecialchars($c['name'])?>" data-type="<?=$c['type']?>" <?=$data['category']===$c['name']?'selected':''?>><?=htmlspecialchars($c['icon'])?> <?=htmlspecialchars($c['name'])?></option><?php }?>
</select></div>
<div class="form-group"><label class="form-label">Subcategory</label>
<select class="form-control" name="subcategory" id="subSel">
<option value="">— None —</option>
<?php foreach($allCats as $c){if(!$c['parent'])continue;?><option value="<?=htmlspecialchars($c['name'])?>" data-parent="<?=htmlspecialchars($c['parent'])?>" <?=$data['subcategory']===$c['name']?'selected':''?>><?=htmlspecialchars($c['icon'])?> <?=htmlspecialchars($c['name'])?></option><?php }?>
</select></div>
</div>

<div class="form-group"><label class="form-label">Note</label>
<input class="form-control" name="note" value="<?=htmlspecialchars($data['note'])?>" placeholder="Optional"></div>

<div class="gap-2" style="margin-top:8px;">
<button type="submit" class="btn btn-primary"><?=$isEdit?'💾 Save Changes':'✅ Save Transaction'?></button>
<a href="transactions.php" class="btn btn-ghost">Cancel</a>
</div>
</form>
</div>
</div></div>

<script>
const allCats=<?=json_encode($allCats)?>;
function onType(){
  const t=document.querySelector('input[name="type"]:checked')?.value||'expense';
  document.getElementById('toAccDiv').style.display=t==='transfer'?'block':'none';
  document.getElementById('chargeDiv').style.display=(t==='transfer'||t==='expense')?'block':'none';
  document.getElementById('accLabel').textContent=t==='transfer'?'From Account *':'Account *';
  const sel=document.getElementById('catSel');const cur=sel.value;
  sel.innerHTML='<option value="">— Select —</option>';
  allCats.filter(c=>!c.parent&&(c.type===t)).forEach(c=>{
    const o=document.createElement('option');
    o.value=c.name;o.dataset.type=c.type;
    o.textContent=(c.icon||'')+' '+c.name;
    if(c.name===cur)o.selected=true;
    sel.appendChild(o);
  });
  loadSub();
  document.querySelectorAll('.type-btn').forEach(b=>{
    b.style.borderColor=b.dataset.type===t?'var(--blue)':'var(--s3)';
    b.style.color=b.dataset.type===t?'var(--blue)':'';
    b.style.background=b.dataset.type===t?'var(--s2)':'';
  });
}
function loadSub(){
  const cat=document.getElementById('catSel').value;
  const sel=document.getElementById('subSel');const cur=sel.value;
  sel.innerHTML='<option value="">— None —</option>';
  allCats.filter(c=>c.parent===cat).forEach(c=>{
    const o=document.createElement('option');o.value=c.name;
    o.textContent=(c.icon||'')+' '+c.name;
    if(c.name===cur)o.selected=true;sel.appendChild(o);
  });
}
document.querySelectorAll('.type-btn').forEach(b=>b.addEventListener('click',()=>{
  document.querySelector('input[name="type"][value="'+b.dataset.type+'"]').checked=true;onType();
}));
onType();
</script>
</div>
<?php require __DIR__ . '/footer.php'; ?>
