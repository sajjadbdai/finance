<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ledger.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Fixed Assets'; $activePage='fixed_assets'; $backTo='index.php';

// Create table
try {
    db()->exec("CREATE TABLE IF NOT EXISTS fixed_assets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        category VARCHAR(50) NOT NULL DEFAULT 'Other',
        purchase_date DATE,
        purchase_price DECIMAL(20,2) DEFAULT 0,
        current_value DECIMAL(20,2) DEFAULT 0,
        currency VARCHAR(10) DEFAULT 'BHD',
        location VARCHAR(100),
        notes TEXT,
        account_id INT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'owned',
        sold_date DATE NULL,
        sold_price DECIMAL(20,2) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch(Exception $e) { /* table may already exist */ }

$msg=''; $error=''; $editItem=null;
$accounts = db()->query("SELECT id,name,currency FROM accounts WHERE is_active=1 ORDER BY name")->fetchAll();

if(isset($_GET['delete']) && is_numeric($_GET['delete'])){
    $aid = (int)$_GET['delete'];
    $chk = db()->prepare("SELECT account_id,status FROM fixed_assets WHERE id=?");
    $chk->execute([$aid]);
    $row = $chk->fetch();
    if ($row && $row['account_id']) {
        // Linked to real money via Trade Fixed Asset — deleting the row
        // directly here would leave the transaction/balance/ledger
        // pointing at nothing. Reverse the purchase transaction instead
        // (find it via transactions.php, it'll reference this asset).
        header('Location: /dashboard/fixed_assets.php?msg=linked_delete_blocked'); exit;
    }
    db()->prepare("DELETE FROM fixed_assets WHERE id=?")->execute([$aid]);
    header('Location: /dashboard/fixed_assets.php?msg=deleted'); exit;
}
if(isset($_GET['edit']) && is_numeric($_GET['edit'])){
    $st=db()->prepare("SELECT * FROM fixed_assets WHERE id=?");
    $st->execute([(int)$_GET['edit']]); $editItem=$st->fetch();
}
$msg=$_GET['msg']??'';

if(isset($_POST['do_save'])){
    $sid   = (int)($_POST['sid']??0);
    $name  = trim($_POST['name']??'');
    $cat   = trim($_POST['category']??'Other');
    $pdate = $_POST['purchase_date']??'';
    $pp    = (float)($_POST['purchase_price']??0);
    $cv    = (float)($_POST['current_value']??0);
    $cur   = $_POST['currency']??'BHD';
    $loc   = trim($_POST['location']??'');
    $notes = trim($_POST['notes']??'');
    $accId = (int)($_POST['account_id']??0) ?: null;
    if(!$name){ $error='Name required.'; }
    elseif(!$accId){ $error='Linked account is required. Use Trade Fixed Asset for an actual purchase — this form is for corrections to an existing record only.'; }
    else {
        if($sid) db()->prepare("UPDATE fixed_assets SET name=?,category=?,purchase_date=?,purchase_price=?,current_value=?,currency=?,location=?,notes=?,account_id=?,updated_at=NOW() WHERE id=?")
            ->execute([$name,$cat,$pdate?:null,$pp,$cv,$cur,$loc,$notes,$accId,$sid]);
        else db()->prepare("INSERT INTO fixed_assets (name,category,purchase_date,purchase_price,current_value,currency,location,notes,account_id,status) VALUES (?,?,?,?,?,?,?,?,?,'owned')")
            ->execute([$name,$cat,$pdate?:null,$pp,$cv,$cur,$loc,$notes,$accId]);
        header('Location: /dashboard/fixed_assets.php?msg=saved'); exit;
    }
}

try {
    $assets = db()->query("SELECT * FROM fixed_assets ORDER BY status='owned' DESC, category, name")->fetchAll();
} catch(Exception $e) { $assets = []; $error = 'DB Error: '.$e->getMessage(); }

// Group by category — only OWNED assets count toward totals. Sold or
// reversed ones stay visible in the list for history, but shouldn't
// count toward Net Worth or category totals anymore.
$groups=[]; $totalBHD=0;
$rates=['BHD'=>1,'USD'=>0.377,'BDT'=>0.00343,'GBP'=>0.478,'EUR'=>0.411];
foreach($assets as $a){
    $groups[$a['category']][]=$a;
    if ($a['status'] !== 'owned') continue;
    $rate=$rates[$a['currency']]??1;
    $totalBHD += $a['current_value']*$rate;
}

$categories=['Land','Home/Property','Car/Vehicle','Gold/Silver','Electronics','Furniture','Business','Other'];

require 'header.php'; ?>
<?php if($msg==='saved'):?><div class="alert alert-success">✅ Saved!</div><?php endif;?>
<?php if($msg==='deleted'):?><div class="alert alert-danger">🗑 Deleted.</div><?php endif;?>
<?php if($msg==='linked_delete_blocked'):?><div class="alert alert-danger">⚠️ This asset is linked to a real transaction (bought via Trade Fixed Asset) — deleting it here would leave the transaction and balance pointing at nothing. Find and reverse the purchase transaction on <a href="transactions.php">Transactions</a> instead (search for the asset name); that will correctly restore the cash and remove the asset.</div><?php endif;?>
<?php if($error):?><div class="alert alert-danger">❌ <?=htmlspecialchars($error)?></div><?php endif;?>

<div class="no-print" style="text-align:right;margin-bottom:12px;">
  <a href="trade_fixed_asset.php" class="btn btn-primary btn-sm">🏠 Trade Fixed Asset (Buy/Sell)</a>
</div>

<!-- Summary -->
<div class="g3" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-title">Total Fixed Assets (Owned)</div>
        <div class="card-value c-blue">BD <?=money($totalBHD)?></div>
        <div class="card-sub"><?=count(array_filter($assets,fn($a)=>$a['status']==='owned'))?> owned</div>
    </div>
    <?php
    $catTotals=[];
    foreach($assets as $a){ if($a['status']!=='owned') continue; $rate=$rates[$a['currency']]??1; $catTotals[$a['category']]=($catTotals[$a['category']]??0)+$a['current_value']*$rate; }
    arsort($catTotals);
    foreach(array_slice($catTotals,0,2,true) as $cat=>$val):?>
    <div class="card">
        <div class="card-title"><?=htmlspecialchars($cat)?></div>
        <div class="card-value c-blue">BD <?=money($val)?></div>
    </div>
    <?php endforeach;?>
</div>

<!-- Add/Edit Form -->
<div class="card" style="margin-bottom:20px;">
    <div class="section-header">
        <div class="section-title"><?=$editItem?'✏️ Edit':'➕ Add'?> Fixed Asset (manual correction — for an actual purchase, use Trade Fixed Asset above)</div>
    </div>
    <form method="POST">
        <input type="hidden" name="do_save" value="1">
        <input type="hidden" name="sid" value="<?=(int)($editItem['id']??0)?>">
        <div class="form-group">
            <label class="form-label">Linked Account *</label>
            <select class="form-control" name="account_id" required>
              <option value="">— Select —</option>
              <?php foreach($accounts as $a):?><option value="<?=$a['id']?>" <?=($editItem['account_id']??0)==$a['id']?'selected':''?>><?=htmlspecialchars($a['name'])?></option><?php endforeach;?>
            </select>
            <div class="hint">Required so every asset traces back to real money. This form only edits the record (e.g. fixing a wrong value) — it doesn't move any cash.</div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Asset Name</label>
                <input class="form-control" name="name" value="<?=htmlspecialchars($editItem['name']??'')?>" placeholder="e.g. Family Home, Toyota Camry" required>
            </div>
            <div class="form-group">
                <label class="form-label">Category</label>
                <select class="form-control" name="category">
                    <?php foreach($categories as $c):?>
                    <option value="<?=$c?>" <?=($editItem['category']??'Other')===$c?'selected':''?>><?=$c?></option>
                    <?php endforeach;?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Purchase Price</label>
                <input type="number" step="any" class="form-control" name="purchase_price" value="<?=$editItem['purchase_price']??0?>">
            </div>
            <div class="form-group">
                <label class="form-label">Current Value</label>
                <input type="number" step="any" class="form-control" name="current_value" value="<?=$editItem['current_value']??0?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Currency</label>
                <select class="form-control" name="currency">
                    <?php foreach(['BHD','USD','BDT','GBP','EUR'] as $c):?>
                    <option value="<?=$c?>" <?=($editItem['currency']??'BHD')===$c?'selected':''?>><?=$c?></option>
                    <?php endforeach;?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Purchase Date</label>
                <input type="date" class="form-control" name="purchase_date" value="<?=$editItem['purchase_date']??''?>">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Location / Notes</label>
            <input class="form-control" name="location" value="<?=htmlspecialchars($editItem['location']??'')?>" placeholder="e.g. Dhaka, Bangladesh">
        </div>
        <div class="gap-2">
            <button type="submit" class="btn btn-primary">💾 Save Asset</button>
            <?php if($editItem):?><a href="fixed_assets.php" class="btn btn-ghost">Cancel</a><?php endif;?>
        </div>
    </form>
</div>

<!-- Assets List -->
<?php if($groups): foreach($groups as $cat=>$items):?>
<div class="card" style="margin-bottom:16px;">
    <div class="section-header">
        <div class="section-title">
            <?php $icons=['Land'=>'🏗','Home/Property'=>'🏠','Car/Vehicle'=>'🚗','Gold/Silver'=>'🥇','Electronics'=>'💻','Furniture'=>'🛋','Business'=>'💼','Other'=>'📦'];?>
            <?=$icons[$cat]??'📦'?> <?=htmlspecialchars($cat)?>
        </div>
        <div class="c-blue" style="font-weight:700;">
            BD <?=money($catTotals[$cat]??0)?>
        </div>
    </div>
    <table class="tbl">
        <tr><th>Name</th><th>Purchase</th><th>Current Value</th><th>Location</th><th></th></tr>
        <?php foreach($items as $a):
            $rate=$rates[$a['currency']]??1;
            $bhd=$a['current_value']*$rate;
            $gain=$a['current_value']-$a['purchase_price'];
            $gainPct=$a['purchase_price']>0?($gain/$a['purchase_price']*100):0;
        ?>
        <tr style="<?=$a['status']!=='owned'?'opacity:.55;':''?>">
            <td>
              <strong><?=htmlspecialchars($a['name'])?></strong>
              <?php if($a['status']==='sold'):?><span class="badge badge-exp" title="Sold <?=$a['sold_date']?' on '.date('d M Y',strtotime($a['sold_date'])):''?> for <?=money((float)$a['sold_price'], $a['currency'])?> <?=$a['currency']?>">SOLD</span>
              <?php elseif($a['status']==='reversed'):?><span class="badge badge-exp" title="This purchase was reversed">↩️ REVERSED</span>
              <?php endif;?>
            </td>
            <td><?=$a['purchase_price']>0?money($a['purchase_price'], $a['currency']).' '.$a['currency']:'—'?></td>
            <td>
                <?=money($a['current_value'], $a['currency'])?> <?=$a['currency']?>
                <br><small class="c-muted">BD <?=money($bhd)?></small>
                <?php if($a['purchase_price']>0 && $a['status']==='owned'):?>
                <br><small class="<?=$gain>=0?'c-green':'c-red'?>"><?=$gain>=0?'+':''?><?=number_format($gainPct,1)?>%</small>
                <?php endif;?>
            </td>
            <td><small class="c-muted"><?=htmlspecialchars($a['location']??'')?></small></td>
            <td>
                <?php if($a['status']==='owned'):?>
                <a href="?edit=<?=$a['id']?>" class="btn btn-ghost btn-sm">✏️</a>
                <?php if(!$a['account_id']):?>
                <a href="?delete=<?=$a['id']?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this asset?')">🗑</a>
                <?php endif;?>
                <?php else:?>
                <span class="c-muted" style="font-size:.75rem;">—</span>
                <?php endif;?>
            </td>
        </tr>
        <?php endforeach;?>
    </table>
</div>
<?php endforeach; else:?>
<div class="card" style="text-align:center;padding:40px;color:var(--muted);">
    No fixed assets yet. Use <a href="trade_fixed_asset.php">Trade Fixed Asset</a> to buy your first one, linked to a real account! 🏠
</div>
<?php endif;?>

<?php require 'footer.php'; ?>
