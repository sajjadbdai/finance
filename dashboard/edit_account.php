<?php

require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$editId = (int)($_POST['edit_id'] ?? $_GET['id'] ?? 0);
if (!$editId) { header('Location: /dashboard/accounts.php'); exit; }
$msg = ''; $error = '';

// DELETE
if (isset($_POST['do_delete'])) {
    $c = db()->prepare("SELECT COUNT(*) FROM transactions WHERE account_id=? OR to_account_id=?");
    $c->execute([$editId,$editId]);
    if ((int)$c->fetchColumn() > 0) {
        db()->prepare("UPDATE accounts SET is_active=0 WHERE id=?")->execute([$editId]);
    } else {
        db()->prepare("DELETE FROM accounts WHERE id=?")->execute([$editId]);
    }
    header('Location: /dashboard/accounts.php?msg=deleted'); exit;
}

// SAVE
if (isset($_POST['do_save'])) {
    $name    = trim($_POST['name']        ?? '');
    $group   = trim($_POST['group_name']  ?? '');
    $currency= trim($_POST['currency']    ?? 'BHD');
    $balance = (float)($_POST['balance']  ?? 0);
    $type    = trim($_POST['type']        ?? 'asset');
    $isCC    = isset($_POST['is_credit_card']) ? 1 : 0;
    $limit   = (float)($_POST['credit_limit']        ?? 0);
    $billD   = (int)($_POST['bill_date']              ?? 15);
    $dueD    = (int)($_POST['payment_due_date']       ?? 4);
    $payable = (float)($_POST['payable_balance']      ?? 0);
    $outst   = (float)($_POST['outstanding_balance']  ?? 0);
    $lastStr = trim($_POST['last_string'] ?? '');
    if (!$name) { $error = 'Name is required.'; }
    else {
        try {
            db()->prepare("UPDATE accounts SET name=?,group_name=?,currency=?,balance=?,type=?,
                is_credit_card=?,credit_limit=?,bill_date=?,payment_due_date=?,
                payable_balance=?,outstanding_balance=?,last_string=?,updated_at=NOW() WHERE id=?")
            ->execute([$name,$group,$currency,$balance,$type,
                $isCC,$limit,$billD,$dueD,$payable,$outst,$lastStr,$editId]);
            $msg = 'saved';
        } catch(Exception $e) { $error = $e->getMessage(); }
    }
}

// Load account
$st = db()->prepare("SELECT * FROM accounts WHERE id=?");
$st->execute([$editId]);
$data = $st->fetch();
if (!$data) { header('Location: /dashboard/accounts.php'); exit; }

// Load groups
$groups = [];
try { $groups = db()->query("SELECT name FROM account_groups ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){}
if (!$groups) $groups = ['Cash','Bahrain Savings A/C','Bahrain Deposit A/C','TK Savings A/C','TK DPS A/C','TK Fixed Deposit A/C','TK Bond','Investments','Other Currencies Account','Refundable Security Deposit','Loan Given to','Credit Card','GNF and GNS fund not for me'];
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Edit Account — Sajjad Finance</title>
<style>
:root{--bg:#0f1117;--s1:#1a1d27;--s2:#252836;--s3:#2e3347;--text:#e1e1e1;--muted:#8892a4;--blue:#4e9af1;--green:#2ecc71;--red:#e74c3c;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Segoe UI',sans-serif;display:flex;min-height:100vh;}
a{color:var(--blue);text-decoration:none;}
/* Sidebar */
.sidebar{width:220px;background:var(--s1);border-right:1px solid var(--s3);padding:20px 0;position:fixed;top:0;left:0;height:100vh;overflow-y:auto;z-index:100;}
.logo{padding:0 18px 18px;font-size:1.1rem;font-weight:700;color:var(--blue);border-bottom:1px solid var(--s3);margin-bottom:8px;}
.ns{padding:10px 18px 4px;font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;}
.ni{display:flex;align-items:center;gap:10px;padding:10px 18px;color:var(--muted);font-size:.9rem;border-left:3px solid transparent;}
.ni:hover{background:var(--s2);color:var(--text);}
.ni.active{background:var(--s2);color:var(--blue);border-left-color:var(--blue);}
/* Main */
.main{margin-left:220px;flex:1;}
.topbar{background:var(--s1);border-bottom:1px solid var(--s3);padding:14px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;}
.topbar-title{font-size:1.05rem;font-weight:600;}
.content{padding:24px;max-width:600px;}
/* Cards */
.card{background:var(--s1);border:1px solid var(--s3);border-radius:12px;padding:20px;margin-bottom:16px;}
/* Alerts */
.ok{background:#2ecc7122;border:1px solid var(--green);color:var(--green);padding:12px 16px;border-radius:8px;margin-bottom:16px;}
.err{background:#e74c3c22;border:1px solid var(--red);color:var(--red);padding:12px 16px;border-radius:8px;margin-bottom:16px;}
/* Form */
.fg{margin-bottom:16px;}
.fl{display:block;font-size:.83rem;color:var(--muted);margin-bottom:5px;font-weight:500;}
.fc{width:100%;padding:10px 13px;background:var(--s2);border:1px solid var(--s3);border-radius:8px;color:var(--text);font-size:.9rem;outline:none;}
.fc:focus{border-color:var(--blue);}
select.fc option{background:var(--s2);}
.r2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media(max-width:600px){.r2{grid-template-columns:1fr;}.main{margin-left:0;}.sidebar{display:none;}}
/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 22px;border-radius:8px;border:none;font-size:.9rem;font-weight:700;cursor:pointer;text-decoration:none;}
.btn-blue{background:var(--blue);color:#fff;}
.btn-blue:hover{background:#3d87d8;}
.btn-gray{background:var(--s2);color:var(--text);border:1px solid var(--s3);}
.btn-gray:hover{background:var(--s3);}
.btn-red{background:var(--red);color:#fff;}
.btns{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px;}
.hint{font-size:.72rem;color:var(--muted);margin-top:3px;}
.section-lbl{font-size:.8rem;font-weight:700;color:var(--blue);margin-bottom:12px;}
.cc-box{padding:16px;background:var(--s2);border-radius:8px;border:1px solid var(--s3);margin-top:12px;margin-bottom:4px;}
.cc-toggle{padding:13px;background:var(--s2);border-radius:8px;border:1px solid var(--s3);margin-bottom:4px;}
.danger{border-color:#e74c3c44;background:#1a0808;}
</style>
</head>
<body>
<nav class="sidebar">
  <div class="logo">💰 Sajjad Finance</div>
  <div class="ns">Main</div>
  <a href="index.php"        class="ni"><span>📊</span> Dashboard</a>
  <a href="accounts.php"     class="ni active"><span>🏦</span> Accounts</a>
  <a href="transactions.php" class="ni"><span>📋</span> Transactions</a>
  <div class="ns">Actions</div>
  <a href="add_transaction.php" class="ni"><span>➕</span> Add Transaction</a>
  <a href="add_account.php"     class="ni"><span>🏛</span> Add Account</a>
  <a href="account_groups.php"  class="ni"><span>📁</span> Account Groups</a>
  <a href="import.php"          class="ni"><span>📥</span> Import Excel</a>
  <div class="ns">Reports</div>
  <a href="reports.php"    class="ni"><span>📈</span> Reports</a>
  <a href="rates.php"      class="ni"><span>💱</span> Rates</a>
  <a href="categories.php" class="ni"><span>🏷</span> Categories</a>
  <a href="export.php"     class="ni"><span>📤</span> Export Data</a>
  <div class="ns">System</div>
  <a href="setup.php"          class="ni"><span>🤖</span> Bot Setup</a>
  <a href="login.php?logout=1" class="ni"><span>🔒</span> Logout</a>
</nav>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">Edit Account</div>
    <div style="display:flex;gap:10px;">
      <a href="account_detail.php?id=<?=$editId?>" class="btn btn-gray" style="padding:7px 14px;font-size:.82rem;">← View Account</a>
      <a href="accounts.php" class="btn btn-gray" style="padding:7px 14px;font-size:.82rem;">All Accounts</a>
    </div>
  </div>
  <div class="content">

    <?php if($msg==='saved'): ?><div class="ok">✅ Account saved successfully!</div><?php endif; ?>
    <?php if($error): ?><div class="err">❌ <?=htmlspecialchars($error)?></div><?php endif; ?>

    <div class="card">
      <form method="POST" action="edit_account.php">
        <input type="hidden" name="edit_id" value="<?=$editId?>">
        <input type="hidden" name="do_save"  value="1">

        <div class="fg">
          <label class="fl">Account Name *</label>
          <input class="fc" type="text" name="name" value="<?=htmlspecialchars($data['name'])?>" required autofocus>
        </div>

        <div class="r2">
          <div class="fg">
            <label class="fl">Group</label>
            <select class="fc" name="group_name">
              <option value="">— Select —</option>
              <?php foreach($groups as $g): ?>
                <option value="<?=htmlspecialchars($g)?>" <?=$data['group_name']===$g?'selected':''?>><?=htmlspecialchars($g)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label class="fl">Type</label>
            <select class="fc" name="type">
              <option value="asset"     <?=$data['type']==='asset'    ?'selected':''?>>Asset</option>
              <option value="liability" <?=$data['type']==='liability'?'selected':''?>>Liability</option>
            </select>
          </div>
        </div>

        <div class="r2">
          <div class="fg">
            <label class="fl">Currency</label>
            <select class="fc" name="currency">
              <?php foreach(['BHD','BDT','USD','GBP','EUR','SAR','AED'] as $c): ?>
                <option value="<?=$c?>" <?=$data['currency']===$c?'selected':''?>><?=$c?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label class="fl">Balance</label>
            <input class="fc" type="number" step="0.001" name="balance" value="<?=htmlspecialchars($data['balance'])?>">
          </div>
        </div>

        <!-- Credit Card Toggle -->
        <div class="cc-toggle fg">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:0;">
            <input type="checkbox" name="is_credit_card" id="ccToggle"
                   <?=$data['is_credit_card']?'checked':''?>
                   onchange="document.getElementById('ccBox').style.display=this.checked?'block':'none'"
                   style="width:auto;">
            <span style="font-weight:600;">💳 This is a Credit Card</span>
          </label>
        </div>

        <div id="ccBox" style="display:<?=$data['is_credit_card']?'block':'none'?>;">
          <div class="cc-box">
            <div class="section-lbl">Credit Card Details</div>
            <div class="r2">
              <div class="fg">
                <label class="fl">Credit Limit</label>
                <input class="fc" type="number" step="0.01" name="credit_limit" value="<?=htmlspecialchars($data['credit_limit'])?>">
              </div>
              <div class="fg">
                <label class="fl">Last 4 Digits</label>
                <input class="fc" type="text" name="last_string" value="<?=htmlspecialchars($data['last_string'])?>" placeholder="e.g. 6397">
              </div>
            </div>
            <div class="r2">
              <div class="fg">
                <label class="fl">Bill Date (day of month)</label>
                <input class="fc" type="number" min="1" max="31" name="bill_date" value="<?=htmlspecialchars($data['bill_date'])?>">
                <div class="hint">Day bill is generated each month</div>
              </div>
              <div class="fg">
                <label class="fl">Payment Due Date</label>
                <input class="fc" type="number" min="1" max="31" name="payment_due_date" value="<?=htmlspecialchars($data['payment_due_date'])?>">
              </div>
            </div>
            <div class="r2">
              <div class="fg">
                <label class="fl">Balance Payable</label>
                <input class="fc" type="number" step="0.001" name="payable_balance" value="<?=htmlspecialchars($data['payable_balance'])?>">
                <div class="hint">Last bill — due now</div>
              </div>
              <div class="fg">
                <label class="fl">Outstanding Balance</label>
                <input class="fc" type="number" step="0.001" name="outstanding_balance" value="<?=htmlspecialchars($data['outstanding_balance'])?>">
                <div class="hint">New charges — not yet due</div>
              </div>
            </div>
          </div>
        </div>

        <div class="btns" style="margin-top:18px;">
          <button type="submit" class="btn btn-blue">💾 Save Changes</button>
          <a href="accounts.php" class="btn btn-gray">Cancel</a>
        </div>
      </form>
    </div>

    <!-- Delete Zone -->
    <div class="card danger">
      <div style="font-size:.9rem;font-weight:700;color:var(--red);margin-bottom:8px;">⚠️ Danger Zone</div>
      <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;">Accounts with transactions will be hidden, not permanently deleted.</div>
      <form method="POST" action="edit_account.php" onsubmit="return confirm('Delete <?=htmlspecialchars(addslashes($data['name']))?>?');">
        <input type="hidden" name="edit_id"   value="<?=$editId?>">
        <input type="hidden" name="do_delete" value="1">
        <button type="submit" class="btn btn-red">🗑 Delete This Account</button>
      </form>
    </div>

  </div>
</div>
</body></html>
