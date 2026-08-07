<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Account Groups'; $activePage='groups'; $backTo='accounts.php';
$msg=''; $editItem=null;

// Try add group_type column
try { db()->exec("ALTER TABLE account_groups ADD COLUMN IF NOT EXISTS group_type VARCHAR(20) DEFAULT 'bank' AFTER type"); } catch(Exception $e){}

// Delete
if (isset($_GET['delete'])) {
    db()->prepare("DELETE FROM account_groups WHERE name=?")->execute([$_GET['delete']??'']);
    header('Location: /dashboard/account_groups.php?msg=deleted'); exit;
}
// Edit load
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $st=db()->prepare("SELECT * FROM account_groups WHERE id=?"); $st->execute([(int)$_GET['edit']]);
    $editItem=$st->fetch();
}
$msg = $_GET['msg'] ?? '';

// Save
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_save'])) {
    $sid   = (int)($_POST['sid'] ?? 0);
    $name  = trim($_POST['name']       ?? '');
    $type  = $_POST['type']            ?? 'asset';
    $gtype = $_POST['group_type']      ?? 'bank';
    $sort  = (int)($_POST['sort_order']?? 99);
    if ($name) {
        if ($sid) {
            db()->prepare("UPDATE account_groups SET name=?,type=?,group_type=?,sort_order=? WHERE id=?")->execute([$name,$type,$gtype,$sort,$sid]);
            // Update all accounts with old group name
            $old = db()->prepare("SELECT name FROM account_groups WHERE id=?"); $old->execute([$sid]);
            $oldRow = $old->fetch();
            if ($oldRow && $oldRow['name'] !== $name) {
                db()->prepare("UPDATE accounts SET group_name=? WHERE group_name=?")->execute([$name,$oldRow['name']]);
            }
        } else {
            db()->prepare("INSERT IGNORE INTO account_groups (name,type,group_type,sort_order) VALUES (?,?,?,?)")->execute([$name,$type,$gtype,$sort]);
        }
        header('Location: /dashboard/account_groups.php?msg=saved'); exit;
    }
}

$groups = db()->query("SELECT g.*,COUNT(a.id) as acc_count FROM account_groups g LEFT JOIN accounts a ON a.group_name=g.name AND a.is_active=1 GROUP BY g.id ORDER BY g.sort_order")->fetchAll();
$groupTypes=['bank'=>'🏦 Bank Account','cash'=>'💵 Cash','credit_card'=>'💳 Credit Card','debit_card'=>'💳 Debit Card','loan'=>'🤝 Loan','investment'=>'📈 Investment','savings'=>'🏧 Savings/Deposit','bond'=>'📜 Bond/FD','other'=>'📌 Other'];

require 'header.php';
?>
<?php if($msg==='saved'):?><div class="alert alert-success">✅ Group saved!</div><?php endif;?>
<?php if($msg==='deleted'):?><div class="alert alert-danger">🗑 Deleted.</div><?php endif;?>

<div class="g2">
<div>
  <div class="section-header">
    <div class="section-title"><?=$editItem?'Edit':'Add'?> Group</div>
    <?php if($editItem):?><a href="account_groups.php" class="btn btn-ghost btn-sm">+ New</a><?php endif;?>
  </div>
  <div class="card">
    <form method="POST" action="account_groups.php">
      <input type="hidden" name="do_save" value="1">
      <input type="hidden" name="sid" value="<?=$editItem?$editItem['id']:0?>">
      <div class="form-group">
        <label class="form-label">Group Name *</label>
        <input class="form-control" name="name" value="<?=htmlspecialchars($editItem['name']??'')?>" placeholder="e.g. Insurance" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label">Group Type</label>
        <select class="form-control" name="group_type">
          <?php foreach($groupTypes as $v=>$l):?>
            <option value="<?=$v?>" <?=($editItem['group_type']??'bank')===$v?'selected':''?>><?=$l?></option>
          <?php endforeach;?>
        </select>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Asset/Liability</label>
          <select class="form-control" name="type">
            <option value="asset"     <?=($editItem['type']??'asset')==='asset'    ?'selected':''?>>Asset</option>
            <option value="liability" <?=($editItem['type']??'')==='liability'?'selected':''?>>Liability</option>
            <option value="mixed"     <?=($editItem['type']??'')==='mixed'    ?'selected':''?>>Mixed</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Sort Order</label>
          <input class="form-control" type="number" name="sort_order" value="<?=$editItem['sort_order']??99?>">
        </div>
      </div>
      <div class="gap-2">
        <button type="submit" class="btn btn-primary"><?=$editItem?'💾 Save Changes':'✅ Add Group'?></button>
        <?php if($editItem):?><a href="account_groups.php" class="btn btn-ghost">Cancel</a><?php endif;?>
      </div>
    </form>
  </div>
</div>

<div>
  <div class="section-header"><div class="section-title">All Groups</div></div>
  <div class="card" style="padding:0;overflow:hidden;">
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>#</th><th>Group Name</th><th>Type</th><th>Accounts</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach($groups as $g):?>
        <tr>
          <td style="color:var(--muted);"><?=$g['sort_order']?></td>
          <td>
            <div style="font-weight:500;"><?=htmlspecialchars($g['name'])?></div>
            <div style="font-size:.72rem;color:var(--muted);"><?=$groupTypes[$g['group_type']??'bank']??''?></div>
          </td>
          <td><span class="badge <?=$g['type']==='asset'?'badge-inc':($g['type']==='liability'?'badge-exp':'badge-tra')?>"><?=ucfirst($g['type'])?></span></td>
          <td><?=$g['acc_count']?></td>
          <td>
            <div class="gap-2">
              <a href="account_groups.php?edit=<?=$g['id']?>" class="btn btn-ghost btn-sm">✏️ Edit</a>
              <?php if($g['acc_count']==0):?>
              <a href="account_groups.php?delete=<?=urlencode($g['name'])?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')">Del</a>
              <?php endif;?>
            </div>
          </td>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table></div>
  </div>
</div>
</div>
<?php require 'footer.php'; ?>
