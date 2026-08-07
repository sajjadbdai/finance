<?php

require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$msg = ''; $error = '';

if (isset($_POST['do_save'])) {
    $name    = trim($_POST['name']       ?? '');
    $group   = trim($_POST['group_name'] ?? '');
    $currency= trim($_POST['currency']   ?? 'BHD');
    $balance = (float)($_POST['balance'] ?? 0);
    $type    = trim($_POST['type']       ?? 'asset');
    $isCC    = isset($_POST['is_credit_card']) ? 1 : 0;
    $limit   = (float)($_POST['credit_limit']       ?? 0);
    $billD   = (int)($_POST['bill_date']             ?? 15);
    $dueD    = (int)($_POST['payment_due_date']      ?? 4);
    $payable = (float)($_POST['payable_balance']     ?? 0);
    $outst   = (float)($_POST['outstanding_balance'] ?? 0);
    $lastStr = trim($_POST['last_string'] ?? '');
    if (!$name) { $error = 'Name is required.'; }
    else {
        try {
            db()->prepare("INSERT INTO accounts (name,group_name,currency,balance,type,is_credit_card,credit_limit,bill_date,payment_due_date,payable_balance,outstanding_balance,last_string) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$name,$group,$currency,$balance,$type,$isCC,$limit,$billD,$dueD,$payable,$outst,$lastStr]);
            $accId = (int)db()->lastInsertId();
            if ($balance != 0) {
                $bhd = toBHD(abs($balance), $currency);
                db()->prepare("INSERT INTO transactions (txn_date,type,amount,currency,amount_bhd,account_id,category,note,source) VALUES (NOW(),?,?,?,?,?,'Opening Balance','Opening balance','web')")
                ->execute([$balance>0?'income':'expense', abs($balance), $currency, $bhd, $accId]);
            }
            header('Location: /dashboard/accounts.php?msg=saved'); exit;
        } catch(Exception $e) { $error = $e->getMessage(); }
    }
}

$groups = [];
try { $groups = db()->query("SELECT name FROM account_groups ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){}
if (!$groups) $groups = ['Cash','Bahrain Savings A/C','Bahrain Deposit A/C','TK Savings A/C','TK DPS A/C','TK Fixed Deposit A/C','TK Bond','Investments','Other Currencies Account','Refundable Security Deposit','Loan Given to','Credit Card','GNF and GNS fund not for me'];

$pageTitle  = 'Add Account';
$activePage = 'add_acc';
$backTo = 'accounts.php';
require __DIR__ . '/header.php';
?>
<div style="max-width:640px;">
<?php if(isset($error)&&$error):?><div class="alert alert-danger">❌ <?=htmlspecialchars($error)?></div><?php endif;?>
<?php if(isset($msg)&&$msg):?><div class="alert alert-success">✅ <?=htmlspecialchars($msg)?></div><?php endif;?>
<div class="card">
      <form method="POST" action="add_account.php">
        <input type="hidden" name="do_save" value="1">
        <div class="form-group">
          <label class="form-label">Account Name *</label>
          <input class="form-control" type="text" name="name" placeholder="e.g. ILA, BisB, Brac Bank" required autofocus>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Group</label>
            <select class="form-control" name="group_name" id="groupSel" onchange="checkNew()">
              <option value="">— Select Group —</option>
              <?php foreach($groups as $g): ?><option value="<?=htmlspecialchars($g)?>"><?=htmlspecialchars($g)?></option><?php endforeach; ?>
              <option value="__new__">+ Create New Group</option>
            </select>
          </div>
          <div class="form-group" id="newGDiv" style="display:none;">
            <label class="form-label">New Group Name</label>
            <input class="form-control" type="text" name="new_group" id="newGInput" placeholder="e.g. Insurance">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Type</label>
            <select class="form-control" name="type">
              <option value="asset">Asset</option>
              <option value="liability">Liability</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Currency</label>
            <select class="form-control" name="currency">
              <?php foreach(['BHD','BDT','USD','GBP','EUR','SAR','AED'] as $c): ?><option value="<?=$c?>"><?=$c?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Opening Balance</label>
          <input class="form-control" type="number" step="0.001" name="balance" value="0">
          <div style="font-size:.78rem;color:var(--muted);margin-top:4px;">Use negative for credit card debt (e.g. -337.14)</div>
        </div>
        <div class="form-group" style="padding:13px;background:var(--s2);border-radius:8px;border:1px solid var(--s3);">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:0;">
            <input type="checkbox" name="is_credit_card" id="ccToggle" onchange="document.getElementById('ccBox').style.display=this.checked?'block':'none'" style="width:auto;">
            <span style="font-weight:600;">💳 This is a Credit Card</span>
          </label>
        </div>
        <div id="ccBox" style="display:none;">
          <div class="cc-box" style="margin-top:12px;">
            <div style="font-size:.8rem;font-weight:700;color:var(--blue);margin-bottom:12px;">Credit Card Details</div>
            <div class="form-row">
              <div class="form-group"><label class="form-label">Credit Limit</label><input class="form-control" type="number" step="0.01" name="credit_limit" value="0"></div>
              <div class="form-group"><label class="form-label">Last 4 Digits</label><input class="form-control" type="text" name="last_string" placeholder="e.g. 6397"></div>
            </div>
            <div class="form-row">
              <div class="form-group"><label class="form-label">Bill Date (day)</label><input class="form-control" type="number" min="1" max="31" name="bill_date" value="15"><div style="font-size:.78rem;color:var(--muted);margin-top:4px;">Day bill is generated</div></div>
              <div class="form-group"><label class="form-label">Payment Due Date (day)</label><input class="form-control" type="number" min="1" max="31" name="payment_due_date" value="4"></div>
            </div>
            <div class="form-row">
              <div class="form-group"><label class="form-label">Balance Payable</label><input class="form-control" type="number" step="0.001" name="payable_balance" value="0"><div style="font-size:.78rem;color:var(--muted);margin-top:4px;">Last bill — due now</div></div>
              <div class="form-group"><label class="form-label">Outstanding Balance</label><input class="form-control" type="number" step="0.001" name="outstanding_balance" value="0"><div style="font-size:.78rem;color:var(--muted);margin-top:4px;">New charges — not yet due</div></div>
            </div>
          </div>
        </div>
        <div class="gap-2" style="margin-top:18px;">
          <button type="submit" class="btn btn-primary">✅ Add Account</button>
          <a href="accounts.php" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
<script>
function checkNew(){
  const v=document.getElementById('groupSel').value;
  document.getElementById('newGDiv').style.display=v==='__new__'?'block':'none';
  if(v==='__new__')document.getElementById('newGInput').focus();
}
</script>
</div>
<?php require __DIR__ . '/footer.php'; ?>
