<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Categories'; $activePage='categories';
$msg=''; $editItem=null;

// Delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    db()->prepare("DELETE FROM categories WHERE id=?")->execute([(int)$_GET['delete']]);
    header('Location: /dashboard/categories.php?msg=deleted'); exit;
}
// Edit load
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $st=db()->prepare("SELECT * FROM categories WHERE id=?"); $st->execute([(int)$_GET['edit']]);
    $editItem=$st->fetch();
}
$msg = $_GET['msg'] ?? '';

// Save (add or edit)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_save'])) {
    $sid    = (int)($_POST['sid'] ?? 0);
    $name   = trim($_POST['name']     ?? '');
    $parent = trim($_POST['parent']   ?? '');
    $type   = $_POST['cat_type']      ?? 'expense';
    $icon   = trim($_POST['icon']     ?? '');
    if ($name) {
        if ($sid) {
            db()->prepare("UPDATE categories SET name=?,parent=?,type=?,icon=? WHERE id=?")->execute([$name,$parent,$type,$icon,$sid]);
        } else {
            db()->prepare("INSERT INTO categories (name,parent,type,icon) VALUES (?,?,?,?)")->execute([$name,$parent,$type,$icon]);
        }
        header('Location: /dashboard/categories.php?msg=saved'); exit;
    }
}

$allCats = db()->query("SELECT * FROM categories ORDER BY type,parent,name")->fetchAll();
$grouped = [];
foreach ($allCats as $c) $grouped[$c['type']][] = $c;

require 'header.php';
?>
<?php if($msg==='saved'):?><div class="alert alert-success">✅ Category saved!</div><?php endif;?>
<?php if($msg==='deleted'):?><div class="alert alert-danger">🗑 Deleted.</div><?php endif;?>

<div class="g2">
<!-- Add/Edit Form -->
<div>
  <div class="section-header"><div class="section-title"><?=$editItem?'Edit':'Add'?> Category</div><?php if($editItem):?><a href="categories.php" class="btn btn-ghost btn-sm">+ New</a><?php endif;?></div>
  <div class="card">
    <form method="POST" action="categories.php">
      <input type="hidden" name="do_save" value="1">
      <input type="hidden" name="sid" value="<?=$editItem?$editItem['id']:0?>">
      <div class="form-group">
        <label class="form-label">Type *</label>
        <select class="form-control" name="cat_type" id="catType" onchange="updateParents()">
          <option value="expense" <?=($editItem['type']??'expense')==='expense'?'selected':''?>>Expense</option>
          <option value="income"  <?=($editItem['type']??'')==='income' ?'selected':''?>>Income</option>
          <option value="transfer"<?=($editItem['type']??'')==='transfer'?'selected':''?>>Transfer</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Category Name *</label>
        <input class="form-control" name="name" value="<?=htmlspecialchars($editItem['name']??'')?>" placeholder="e.g. Food, Medical" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label">Parent (leave empty for top-level)</label>
        <select class="form-control" name="parent" id="parentSel">
          <option value="">— Top Level —</option>
          <?php foreach($allCats as $c): if($c['parent']) continue; ?>
            <option value="<?=htmlspecialchars($c['name'])?>" data-type="<?=$c['type']?>" <?=($editItem['parent']??'')===$c['name']?'selected':''?>><?=htmlspecialchars($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Icon (emoji)</label>
        <input class="form-control" name="icon" value="<?=htmlspecialchars($editItem['icon']??'')?>" placeholder="e.g. 🍽" style="width:100px;">
      </div>
      <div class="gap-2">
        <button type="submit" class="btn btn-primary"><?=$editItem?'💾 Save Changes':'✅ Add Category'?></button>
        <?php if($editItem):?><a href="categories.php" class="btn btn-ghost">Cancel</a><?php endif;?>
      </div>
    </form>
  </div>
</div>

<!-- List -->
<div>
  <div class="section-header"><div class="section-title">All Categories</div></div>
  <?php foreach(['expense'=>'🔴 Expense','income'=>'🟢 Income','transfer'=>'🔵 Transfer'] as $type=>$label): ?>
  <?php if(empty($grouped[$type])) continue; ?>
  <div class="card" style="margin-bottom:12px;padding:0;overflow:hidden;">
    <div style="padding:10px 16px;background:var(--s2);font-size:.8rem;font-weight:600;"><?=$label?></div>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Icon</th><th>Name</th><th>Parent</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach($grouped[$type] as $c): ?>
        <tr>
          <td style="font-size:1.1rem;"><?=htmlspecialchars($c['icon'])?:' '?></td>
          <td><?=htmlspecialchars($c['name'])?></td>
          <td style="color:var(--muted);font-size:.82rem;"><?=htmlspecialchars($c['parent'])?:'—'?></td>
          <td>
            <div class="gap-2">
              <a href="categories.php?edit=<?=$c['id']?>" class="btn btn-ghost btn-sm">✏️ Edit</a>
              <a href="categories.php?delete=<?=$c['id']?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')">Del</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endforeach; ?>
</div>
</div>

<script>
function updateParents() {
    const type=document.getElementById('catType').value;
    Array.from(document.getElementById('parentSel').options).forEach(o=>{
        if(!o.value){o.style.display='';return;}
        o.style.display=o.dataset.type===type?'':'none';
    });
    document.getElementById('parentSel').value='';
}
</script>
<?php require 'footer.php'; ?>
