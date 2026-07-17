<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Exchange Rates'; $activePage='rates';
$msg='';

// Get base currency from settings (stored in exchange_rates or a settings table)
// Try to get from a simple key-value in exchange_rates table using from_cur='BASE'
try {
    db()->exec("CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value VARCHAR(255))");
} catch(Exception $e){}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (isset($_POST['save_rate'])) {
        $from = strtoupper(trim($_POST['from_cur']??''));
        $to   = strtoupper(trim($_POST['to_cur']??''));
        $rate = (float)($_POST['rate']??0);
        if ($from && $to && $rate > 0) {
            db()->prepare("INSERT INTO exchange_rates (from_cur,to_cur,rate,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE rate=?,updated_at=NOW()")
               ->execute([$from,$to,$rate,$rate]);
            // Also save reverse if BHD involved
            if ($to==='BHD' && $rate>0) {
                $rev = round(1/$rate,6);
                db()->prepare("INSERT INTO exchange_rates (from_cur,to_cur,rate,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE rate=?,updated_at=NOW()")
                   ->execute(['BHD',$from,$rev,$rev]);
            }
            $msg='saved';
        }
    }
    if (isset($_POST['delete_rate'])) {
        db()->prepare("DELETE FROM exchange_rates WHERE from_cur=? AND to_cur=?")->execute([$_POST['from_cur'],$_POST['to_cur']]);
        $msg='deleted';
    }
    if (isset($_POST['save_base'])) {
        $base = strtoupper(trim($_POST['base_currency']??'BHD'));
        db()->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES ('base_currency',?) ON DUPLICATE KEY UPDATE setting_value=?")
           ->execute([$base,$base]);
        $msg='base_saved';
    }
}

$rates = db()->query("SELECT * FROM exchange_rates WHERE to_cur='BHD' ORDER BY from_cur")->fetchAll();
try {
    $baseSt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key='base_currency'");
    $baseSt->execute();
    $baseCurrency = $baseSt->fetchColumn() ?: 'BHD';
} catch(Exception $e) { $baseCurrency = 'BHD'; }

$currencies = ['BHD','BDT','USD','GBP','EUR','SAR','AED','SGD','CAD','AUD'];

require 'header.php';
?>
<?php if($msg==='saved'):?><div class="alert alert-success">✅ Rate saved!</div><?php endif;?>
<?php if($msg==='deleted'):?><div class="alert alert-danger">🗑 Rate deleted.</div><?php endif;?>
<?php if($msg==='base_saved'):?><div class="alert alert-success">✅ Base currency updated!</div><?php endif;?>

<div class="g2">
<!-- Add Rate -->
<div>
  <!-- Base Currency Setting -->
  <div class="section-header"><div class="section-title">⚙️ App Base Currency</div></div>
  <div class="card" style="margin-bottom:20px;">
    <div style="font-size:.85rem;color:var(--muted);margin-bottom:12px;">
      All totals, reports and net worth are converted to this currency.
    </div>
    <form method="POST" style="display:flex;gap:10px;align-items:flex-end;">
      <div style="flex:1;">
        <label class="form-label">Base Currency</label>
        <select class="form-control" name="base_currency">
          <?php foreach($currencies as $c):?>
          <option value="<?=$c?>" <?=$baseCurrency===$c?'selected':''?>><?=$c?></option>
          <?php endforeach;?>
        </select>
      </div>
      <button type="submit" name="save_base" value="1" class="btn btn-primary">💾 Save</button>
    </form>
    <div style="margin-top:10px;font-size:.82rem;color:var(--muted);">
      Current base currency: <strong style="color:var(--blue);"><?=$baseCurrency?></strong>
    </div>
  </div>

  <div class="section-header"><div class="section-title">Add / Update Rate</div></div>
  <div class="card">
    <form method="POST">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">From Currency</label>
          <select class="form-control" name="from_cur">
            <?php foreach($currencies as $c):?><option value="<?=$c?>"><?=$c?></option><?php endforeach;?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">To Currency</label>
          <select class="form-control" name="to_cur">
            <?php foreach($currencies as $c):?><option value="<?=$c?>" <?=$c==='BHD'?'selected':''?>><?=$c?></option><?php endforeach;?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Rate (1 FROM = ? TO)</label>
        <input class="form-control" type="number" step="0.000001" name="rate" placeholder="e.g. 0.00307 for BDT→BHD" required>
        <div style="font-size:.75rem;color:var(--muted);margin-top:4px;">
          Example: BDT→BHD = 0.00307 &nbsp;|&nbsp; USD→BHD = 0.376 &nbsp;|&nbsp; GBP→BHD = 0.476
        </div>
      </div>
      <button type="submit" name="save_rate" value="1" class="btn btn-primary">✅ Save Rate</button>
    </form>
  </div>
</div>

<!-- Rates List -->
<div>
  <div class="section-header"><div class="section-title">Current Rates → BHD</div></div>
  <div class="card" style="padding:0;overflow:hidden;">
    <table class="tbl">
      <thead><tr><th>From</th><th>To</th><th>Rate</th><th>1 BHD =</th><th>Updated</th><th></th></tr></thead>
      <tbody>
        <?php foreach($rates as $r):
          $inverse = $r['rate']>0 ? round(1/$r['rate'],2) : 0;
        ?>
        <tr>
          <td style="font-weight:700;"><?=$r['from_cur']?></td>
          <td><?=$r['to_cur']?></td>
          <td style="font-weight:600;color:var(--blue);"><?=number_format((float)$r['rate'],6)?></td>
          <td style="color:var(--muted);font-size:.82rem;"><?=$r['from_cur']?> <?=number_format($inverse,2)?></td>
          <td style="color:var(--muted);font-size:.8rem;"><?=date('d M H:i',strtotime($r['updated_at']??'now'))?></td>
          <td>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="from_cur" value="<?=$r['from_cur']?>">
              <input type="hidden" name="to_cur"   value="<?=$r['to_cur']?>">
              <button type="submit" name="delete_rate" value="1" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')">Del</button>
            </form>
          </td>
        </tr>
        <?php endforeach;?>
        <?php if(!$rates):?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px;">No rates yet. Add BDT→BHD, USD→BHD etc.</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>

  <div class="card" style="margin-top:12px;font-size:.82rem;color:var(--muted);">
    <div style="font-weight:600;margin-bottom:8px;">💡 Common rates to add:</div>
    BDT → BHD: ~0.003077 &nbsp;|&nbsp; USD → BHD: ~0.376 &nbsp;|&nbsp; GBP → BHD: ~0.476<br>
    When you enter BDT→BHD, the reverse (BHD→BDT) is auto-saved too.
  </div>
</div>
</div>
<?php require 'footer.php'; ?>
