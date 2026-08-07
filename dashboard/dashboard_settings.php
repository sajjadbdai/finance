<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Dashboard Settings'; $activePage='settings'; $activePage='dashboard'; $backTo='index.php';

// Save settings
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_settings'])) {
    // Update account groups visibility and order
    $groups = db()->query("SELECT id,name FROM account_groups ORDER BY sort_order")->fetchAll();
    foreach ($groups as $g) {
        $show      = isset($_POST['group_show_'.$g['id']]) ? 1 : 0;
        $order     = (int)($_POST['group_order_'.$g['id']] ?? 0);
        $collapsed = isset($_POST['group_collapsed_'.$g['id']]) ? 1 : 0;
        try {
            db()->prepare("UPDATE account_groups SET dashboard_show=?,dashboard_order=?,dashboard_collapsed=? WHERE id=?")
               ->execute([$show,$order,$collapsed,$g['id']]);
        } catch(Exception $e) {
            // Columns may not exist yet
            db()->exec("ALTER TABLE account_groups ADD COLUMN IF NOT EXISTS dashboard_show TINYINT(1) DEFAULT 1");
            db()->exec("ALTER TABLE account_groups ADD COLUMN IF NOT EXISTS dashboard_order INT DEFAULT 0");
            db()->exec("ALTER TABLE account_groups ADD COLUMN IF NOT EXISTS dashboard_collapsed TINYINT(1) DEFAULT 0");
        }
    }
    // Update individual accounts
    $accounts = db()->query("SELECT id FROM accounts WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($accounts as $accId) {
        $show  = isset($_POST['acc_show_'.$accId]) ? 1 : 0;
        $order = (int)($_POST['acc_order_'.$accId] ?? 0);
        try {
            db()->prepare("UPDATE accounts SET dashboard_show=?,dashboard_order=? WHERE id=?")
               ->execute([$show,$order,$accId]);
        } catch(Exception $e) {
            db()->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS dashboard_show TINYINT(1) DEFAULT 1");
            db()->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS dashboard_order INT DEFAULT 0");
        }
    }
    header('Location: /dashboard/dashboard_settings.php?msg=saved'); exit;
}

$msg = $_GET['msg'] ?? '';

// Add columns if missing
try { db()->exec("ALTER TABLE account_groups ADD COLUMN IF NOT EXISTS dashboard_show TINYINT(1) DEFAULT 1"); } catch(Exception $e){}
try { db()->exec("ALTER TABLE account_groups ADD COLUMN IF NOT EXISTS dashboard_order INT DEFAULT 0"); } catch(Exception $e){}
try { db()->exec("ALTER TABLE account_groups ADD COLUMN IF NOT EXISTS dashboard_collapsed TINYINT(1) DEFAULT 0"); } catch(Exception $e){}
try { db()->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS dashboard_show TINYINT(1) DEFAULT 1"); } catch(Exception $e){}
try { db()->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS dashboard_order INT DEFAULT 0"); } catch(Exception $e){}

$groups = db()->query("SELECT g.*,COUNT(a.id) as acc_count FROM account_groups g LEFT JOIN accounts a ON a.group_name=g.name AND a.is_active=1 GROUP BY g.id ORDER BY COALESCE(g.dashboard_order,g.sort_order)")->fetchAll();
$allAccounts = db()->query("SELECT * FROM accounts WHERE is_active=1 ORDER BY group_name,COALESCE(dashboard_order,0),name")->fetchAll();
$accByGroup = [];
foreach ($allAccounts as $a) $accByGroup[$a['group_name']][] = $a;

require 'header.php';
?>
<?php if($msg==='saved'):?><div class="alert alert-success">✅ Dashboard settings saved!</div><?php endif;?>

<!-- Privacy Settings Card -->
<div class="card" style="margin-bottom:20px;">
  <div class="section-title" style="margin-bottom:14px;">🔒 Privacy & Security</div>

  <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--s3);">
    <div>
      <div style="font-weight:600;font-size:.9rem;">Hide Values on Load</div>
      <div style="font-size:.78rem;color:var(--muted);">All amounts hidden until you tap 🙈 button</div>
    </div>
    <label style="position:relative;display:inline-block;width:44px;height:24px;">
      <input type="checkbox" id="hide_on_load" onchange="saveSetting('hide_on_load',this.checked)"
        
        style="opacity:0;width:0;height:0;">
      <span onclick="toggleSwitch('hide_on_load')" style="position:absolute;cursor:pointer;inset:0;background:var(--s3);border-radius:24px;transition:.3s;" id="sw_hide_on_load"></span>
    </label>
  </div>

  <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--s3);">
    <div>
      <div style="font-weight:600;font-size:.9rem;">PIN Protection</div>
      <div style="font-size:.78rem;color:var(--muted);">Require PIN to show values</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <span id="pin-status" style="font-size:.8rem;color:var(--muted);"></span>
      <button onclick="openSetupPin()" class="btn btn-primary btn-sm" style="font-size:.78rem;">Set PIN</button>
      <button onclick="removePin()" class="btn btn-ghost btn-sm" style="font-size:.78rem;">Remove</button>
    </div>
  </div>

  <div style="padding:10px 0;font-size:.78rem;color:var(--muted);">
    💡 To disable all privacy features: turn off "Hide Values on Load" and click "Remove" PIN
  </div>
</div>

<script>
// Init toggle states from localStorage
document.addEventListener('DOMContentLoaded',function(){
    var hideOn = localStorage.getItem('hide_on_load') !== 'false'; // default ON
    setSwitch('sw_hide_on_load', hideOn);
    document.getElementById('pin-status').textContent = localStorage.getItem('finance_pin') ? '🔒 PIN set' : '🔓 No PIN';
});

function setSwitch(id, on){
    var el = document.getElementById(id);
    if(!el) return;
    el.style.background = on ? 'var(--blue)' : 'var(--s3)';
    el.style.setProperty('--tx', on ? '20px' : '0px');
    if(!el.querySelector('.knob')){
        var k=document.createElement('span');
        k.className='knob';
        k.style.cssText='position:absolute;height:18px;width:18px;left:3px;bottom:3px;background:white;border-radius:50%;transition:.3s;';
        el.appendChild(k);
    }
    el.querySelector('.knob').style.transform = on ? 'translateX(20px)' : 'translateX(0)';
}

function toggleSwitch(key){
    var cur = localStorage.getItem(key) !== 'false';
    var newVal = !cur;
    localStorage.setItem(key, newVal);
    setSwitch('sw_'+key, newVal);
    if(key==='hide_on_load'){
        if(!newVal){
            // Disable: show values, remove hide button behavior
            document.body.classList.remove('values-hidden');
            localStorage.setItem('hide_on_load','false');
        } else {
            localStorage.setItem('hide_on_load','true');
        }
    }
}

function removePin(){
    if(confirm('Remove PIN protection?')){
        localStorage.removeItem('finance_pin');
        document.getElementById('pin-status').textContent='🔓 No PIN';
    }
}
</script>

<div style="margin-bottom:16px;">
  <div style="font-size:.85rem;color:var(--muted);">
    Control which groups and accounts appear on your dashboard, their order, and whether groups start collapsed.
  </div>
</div>

<form method="POST" action="dashboard_settings.php">
  <input type="hidden" name="save_settings" value="1">

  <?php foreach($groups as $g):
    $gAccs = $accByGroup[$g['name']] ?? [];
  ?>
  <div class="card" style="margin-bottom:12px;padding:0;overflow:hidden;">
    <!-- Group Header -->
    <div style="background:var(--s2);padding:12px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <div style="flex:1;font-weight:700;font-size:.95rem;"><?=htmlspecialchars($g['name'])?></div>
      <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;cursor:pointer;">
        <input type="checkbox" name="group_show_<?=$g['id']?>" <?=($g['dashboard_show']??1)?'checked':''?>>
        Show Group
      </label>
      <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;cursor:pointer;">
        <input type="checkbox" name="group_collapsed_<?=$g['id']?>" <?=($g['dashboard_collapsed']??0)?'checked':''?>>
        Start Collapsed
      </label>
      <div style="display:flex;align-items:center;gap:6px;font-size:.82rem;">
        <span style="color:var(--muted);">Order:</span>
        <input type="number" name="group_order_<?=$g['id']?>" value="<?=$g['dashboard_order']??$g['sort_order']?>" style="width:60px;padding:4px 6px;background:var(--bg);border:1px solid var(--s3);border-radius:5px;color:var(--text);">
      </div>
    </div>
    <!-- Accounts in group -->
    <?php foreach($gAccs as $a):?>
    <div style="display:flex;align-items:center;gap:12px;padding:8px 24px;border-bottom:1px solid var(--s3);flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;flex:1;font-size:.88rem;">
        <input type="checkbox" name="acc_show_<?=$a['id']?>" <?=($a['dashboard_show']??1)?'checked':''?>>
        <?=htmlspecialchars($a['name'])?>
        <span style="color:var(--muted);font-size:.75rem;"><?=$a['currency']?></span>
      </label>
      <div style="display:flex;align-items:center;gap:6px;font-size:.82rem;">
        <span style="color:var(--muted);">Order:</span>
        <input type="number" name="acc_order_<?=$a['id']?>" value="<?=$a['dashboard_order']??0?>" style="width:60px;padding:4px 6px;background:var(--bg);border:1px solid var(--s3);border-radius:5px;color:var(--text);">
      </div>
    </div>
    <?php endforeach;?>
    <?php if(!$gAccs):?><div style="padding:8px 24px;color:var(--muted);font-size:.82rem;">No accounts in this group</div><?php endif;?>
  </div>
  <?php endforeach;?>

  <div style="margin-top:16px;">
    <button type="submit" class="btn btn-primary">💾 Save Dashboard Settings</button>
    <a href="index.php" class="btn btn-ghost" style="margin-left:10px;">← Back to Dashboard</a>
  </div>
</form>

<?php require 'footer.php'; ?>
