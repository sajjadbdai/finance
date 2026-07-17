@1976<?php
// ZERO external includes - completely self-contained
define('DB_HOST', 'localhost');
define('DB_NAME', 'sajjadbd_fin');
define('DB_USER', 'sajjadbd_fin');
define('DB_PASS', 'Dajjas@1976');
define('DASHBOARD_PASSWORD', 'Dajjas@1976');

function db(){static $p=null;if(!$p)$p=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);return $p;}

if(session_status()===PHP_SESSION_NONE)session_start();
if(!isset($_SESSION['auth'])){header('Location: login.php');exit;}

$editId=(int)($_POST['edit_id']??$_GET['id']??0);
if(!$editId){header('Location: accounts.php');exit;}
$msg='';$error='';

if(isset($_POST['do_delete'])){
    $c=db()->prepare("SELECT COUNT(*) FROM transactions WHERE account_id=? OR to_account_id=?");
    $c->execute([$editId,$editId]);
    if((int)$c->fetchColumn()>0)db()->prepare("UPDATE accounts SET is_active=0 WHERE id=?")->execute([$editId]);
    else db()->prepare("DELETE FROM accounts WHERE id=?")->execute([$editId]);
    header('Location: accounts.php?msg=deleted');exit;
}

if(isset($_POST['do_save'])){
    $name=trim($_POST['name']??'');
    $group=trim($_POST['group_name']??'');
    $currency=trim($_POST['currency']??'BHD');
    $balance=(float)($_POST['balance']??0);
    $type=trim($_POST['type']??'asset');
    $isCC=isset($_POST['is_credit_card'])?1:0;
    $limit=(float)($_POST['credit_limit']??0);
    $billD=(int)($_POST['bill_date']??15);
    $dueD=(int)($_POST['payment_due_date']??4);
    $payable=(float)($_POST['payable_balance']??0);
    $outst=(float)($_POST['outstanding_balance']??0);
    $lastStr=trim($_POST['last_string']??'');
    if(!$name){$error='Name is required.';}
    else{
        try{
            db()->prepare("UPDATE accounts SET name=?,group_name=?,currency=?,balance=?,type=?,is_credit_card=?,credit_limit=?,bill_date=?,payment_due_date=?,payable_balance=?,outstanding_balance=?,last_string=?,updated_at=NOW() WHERE id=?")
            ->execute([$name,$group,$currency,$balance,$type,$isCC,$limit,$billD,$dueD,$payable,$outst,$lastStr,$editId]);
            $msg='saved';
        }catch(Exception $e){$error=$e->getMessage();}
    }
}

$st=db()->prepare("SELECT * FROM accounts WHERE id=?");$st->execute([$editId]);$data=$st->fetch();
if(!$data){header('Location: accounts.php');exit;}

$groups=[];
try{$groups=db()->query("SELECT name FROM account_groups ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN);}catch(Exception $e){}
if(!$groups)$groups=['Cash','Bahrain Savings A/C','Bahrain Deposit A/C','TK Savings A/C','TK DPS A/C','TK Fixed Deposit A/C','TK Bond','Investments','Other Currencies Account','Refundable Security Deposit','Loan Given to','Credit Card','GNF and GNS fund not for me'];
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Edit Account</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#0f1117;color:#e1e1e1;font-family:'Segoe UI',sans-serif;padding:20px;}
.wrap{max-width:560px;margin:0 auto;}
.hdr{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
h2{color:#4e9af1;font-size:1.1rem;}
a{color:#4e9af1;text-decoration:none;}
.card{background:#1a1d27;border:1px solid #2e3347;border-radius:12px;padding:20px;margin-bottom:14px;}
.ok{background:#2ecc7122;border:1px solid #2ecc71;color:#2ecc71;padding:12px;border-radius:8px;margin-bottom:14px;font-size:.9rem;}
.err{background:#e74c3c22;border:1px solid #e74c3c;color:#e74c3c;padding:12px;border-radius:8px;margin-bottom:14px;font-size:.9rem;}
.fg{margin-bottom:14px;}
label{display:block;font-size:.82rem;color:#8892a4;margin-bottom:4px;}
input,select{width:100%;padding:10px 12px;background:#252836;border:1px solid #2e3347;border-radius:7px;color:#e1e1e1;font-size:.9rem;outline:none;}
input:focus,select:focus{border-color:#4e9af1;}
.r2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.btns{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;}
.b1{background:#4e9af1;color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:.95rem;font-weight:700;cursor:pointer;}
.b2{background:#252836;color:#e1e1e1;border:1px solid #2e3347;padding:11px 20px;border-radius:8px;cursor:pointer;text-decoration:none;display:inline-block;font-size:.9rem;}
.b3{background:#e74c3c;color:#fff;border:none;padding:10px 20px;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;}
.hint{font-size:.71rem;color:#8892a4;margin-top:3px;}
.cc-inner{padding:14px;background:#252836;border-radius:8px;border:1px solid #2e3347;margin-top:10px;}
.danger{border-color:#e74c3c44;background:#1e0a0a;}
.nav{background:#1a1d27;padding:10px 20px;border-bottom:1px solid #2e3347;margin:-20px -20px 20px;display:flex;gap:16px;font-size:.85rem;}
select option{background:#252836;}
</style>
</head><body>
<div class="nav">
  <a href="index.php">📊 Dashboard</a>
  <a href="accounts.php">🏦 Accounts</a>
  <a href="transactions.php">📋 Transactions</a>
  <a href="add_transaction.php">➕ Add Transaction</a>
  <a href="add_account.php">🏛 Add Account</a>
  <a href="reports.php">📈 Reports</a>
</div>
<div class="wrap">
<div class="hdr">
  <h2>✏️ Edit: <?=htmlspecialchars($data['name'])?></h2>
  <a href="accounts.php">← All Accounts</a>
</div>

<?php if($msg==='saved'):?><div class="ok">✅ Saved successfully!</div><?php endif;?>
<?php if($error):?><div class="err">❌ <?=htmlspecialchars($error)?></div><?php endif;?>

<div class="card">
<form method="POST" action="edit_account.php">
<input type="hidden" name="edit_id" value="<?=$editId?>">
<input type="hidden" name="do_save" value="1">

<div class="fg"><label>Account Name *</label>
<input type="text" name="name" value="<?=htmlspecialchars($data['name'])?>" required autofocus></div>

<div class="r2">
<div class="fg"><label>Group</label>
<select name="group_name">
<option value="">— Select —</option>
<?php foreach($groups as $g):?><option value="<?=htmlspecialchars($g)?>" <?=$data['group_name']===$g?'selected':''?>><?=htmlspecialchars($g)?></option><?php endforeach;?>
</select></div>
<div class="fg"><label>Type</label>
<select name="type">
<option value="asset" <?=$data['type']==='asset'?'selected':''?>>Asset</option>
<option value="liability" <?=$data['type']==='liability'?'selected':''?>>Liability</option>
</select></div>
</div>

<div class="r2">
<div class="fg"><label>Currency</label>
<select name="currency">
<?php foreach(['BHD','BDT','USD','GBP','EUR','SAR','AED'] as $c):?><option value="<?=$c?>" <?=$data['currency']===$c?'selected':''?>><?=$c?></option><?php endforeach;?>
</select></div>
<div class="fg"><label>Balance</label>
<input type="number" step="0.001" name="balance" value="<?=htmlspecialchars($data['balance'])?>"></div>
</div>

<div class="fg" style="padding:12px;background:#252836;border-radius:8px;border:1px solid #2e3347;">
<label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:0;">
<input type="checkbox" name="is_credit_card" id="cct" <?=$data['is_credit_card']?'checked':''?> onchange="document.getElementById('ccb').style.display=this.checked?'block':'none'" style="width:auto;margin:0;">
<span style="font-weight:600;">💳 This is a Credit Card</span>
</label></div>

<div id="ccb" style="display:<?=$data['is_credit_card']?'block':'none'?>;">
<div class="cc-inner" style="margin-top:10px;">
<div style="color:#4e9af1;font-weight:700;font-size:.82rem;margin-bottom:12px;">Credit Card Details</div>
<div class="r2">
<div class="fg"><label>Credit Limit</label><input type="number" step="0.01" name="credit_limit" value="<?=htmlspecialchars($data['credit_limit'])?>"></div>
<div class="fg"><label>Last 4 Digits</label><input type="text" name="last_string" value="<?=htmlspecialchars($data['last_string'])?>" placeholder="e.g. 6397"></div>
</div>
<div class="r2">
<div class="fg"><label>Bill Date (day)</label><input type="number" min="1" max="31" name="bill_date" value="<?=htmlspecialchars($data['bill_date'])?>"><div class="hint">Day bill is generated each month</div></div>
<div class="fg"><label>Payment Due Date (day)</label><input type="number" min="1" max="31" name="payment_due_date" value="<?=htmlspecialchars($data['payment_due_date'])?>"></div>
</div>
<div class="r2">
<div class="fg"><label>Balance Payable</label><input type="number" step="0.001" name="payable_balance" value="<?=htmlspecialchars($data['payable_balance'])?>"><div class="hint">Last bill — due now</div></div>
<div class="fg"><label>Outstanding Balance</label><input type="number" step="0.001" name="outstanding_balance" value="<?=htmlspecialchars($data['outstanding_balance'])?>"><div class="hint">New charges — not yet due</div></div>
</div>
</div></div>

<div class="btns">
<button type="submit" class="b1">💾 Save Changes</button>
<a href="accounts.php" class="b2">Cancel</a>
</div>
</form>
</div>

<div class="card danger">
<div style="color:#e74c3c;font-weight:700;margin-bottom:8px;">⚠️ Delete Account</div>
<div style="font-size:.82rem;color:#8892a4;margin-bottom:12px;">Accounts with transactions will be hidden, not deleted.</div>
<form method="POST" action="edit_account.php" onsubmit="return confirm('Delete this account?')">
<input type="hidden" name="edit_id" value="<?=$editId?>">
<input type="hidden" name="do_delete" value="1">
<button type="submit" class="b3">🗑 Delete</button>
</form>
</div>

</div></body></html>
