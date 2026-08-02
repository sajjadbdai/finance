<?php

require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Accounts'; $activePage='accounts'; $backTo='index.php';

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    db()->prepare("UPDATE accounts SET is_active=0 WHERE id=?")->execute([(int)$_GET['delete']]);
    header('Location: /dashboard/accounts.php?msg=deleted'); exit;
}
$msg = $_GET['msg'] ?? '';

// Load all groups ordered
$groupOrder = db()->query("SELECT name,type FROM account_groups ORDER BY sort_order")->fetchAll(PDO::FETCH_KEY_PAIR);

// Load all accounts
$accounts = db()->query("SELECT * FROM accounts WHERE is_active=1 ORDER BY group_name, type DESC, name")->fetchAll();

// Group accounts
$grouped = []; $totalAssets=0; $totalLiab=0;
foreach ($accounts as $a) {
    $g = $a['group_name'] ?: 'Other';
    $grouped[$g][] = $a;
    $bhd = toBHD((float)$a['balance'], $a['currency']);
    if ($a['type']==='asset') $totalAssets += $bhd;
    else $totalLiab += $bhd;
}

// Sort groups by account_groups order
$sortedGroups = [];
foreach ($groupOrder as $gname => $gtype) {
    if (isset($grouped[$gname])) $sortedGroups[$gname] = $grouped[$gname];
}
foreach ($grouped as $gname => $accs) {
    if (!isset($sortedGroups[$gname])) $sortedGroups[$gname] = $accs;
}

// Helper: compute group total in BHD
function groupTotalBHD(array $accs): float {
    $t = 0;
    foreach ($accs as $a) {
        // For all accounts use the actual balance field
        $t += toBHD((float)$a['balance'], $a['currency']);
    }
    return $t;
}

require 'header.php';
?>
<?php if ($msg==='saved'): ?><div class="alert alert-success">✅ Account saved!</div><?php endif; ?>
<?php if ($msg==='deleted'): ?><div class="alert alert-danger">🗑 Account removed.</div><?php endif; ?>

<!-- Summary -->
<div class="g3" style="margin-bottom:24px;">
  <div class="card"><div class="card-title">Total Assets</div><div class="card-value c-blue">BD <?=money($totalAssets)?></div></div>
  <div class="card"><div class="card-title">Total Liabilities</div><div class="card-value c-red">BD <?=money($totalLiab)?></div></div>
  <div class="card"><div class="card-title">Net Worth</div><div class="card-value c-green">BD <?=money($totalAssets+$totalLiab)?></div></div>
</div>

<div class="section-header">
  <div class="section-title">All Accounts</div>
  <a href="add_account.php" class="btn btn-primary btn-sm">+ Add Account</a>
</div>

<?php foreach ($sortedGroups as $gname => $accs):
  $gtotalBHD = groupTotalBHD($accs);
  $hasCreditCard = !empty(array_filter($accs, fn($a)=>$a['is_credit_card']));
  $hasAsset = !empty(array_filter($accs, fn($a)=>$a['type']==='asset'));
  $hasLiab  = !empty(array_filter($accs, fn($a)=>$a['type']==='liability'));
  $isMixed  = $hasAsset && $hasLiab;
  $gcolor   = $gtotalBHD >= 0 ? 'c-blue' : 'c-red';
?>
<div class="card" style="margin-bottom:14px;padding:0;overflow:hidden;">
  <!-- Group Header -->
  <div style="background:var(--s2);padding:11px 18px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--s3);">
    <span style="font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);">
      <?= htmlspecialchars($gname) ?>
    </span>
    <span class="<?=$gcolor?>" style="font-size:.9rem;font-weight:700;">
      BD <?=money($gtotalBHD)?>
    </span>
  </div>

  <?php if ($hasCreditCard): ?>
  <!-- Credit Card Group — show payable + outstanding -->
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;background:var(--s2);padding:7px 18px;border-bottom:1px solid var(--s3);font-size:.72rem;color:var(--muted);text-transform:uppercase;gap:8px;">
    <span>Card Name</span><span style="text-align:right;">Balance Payable</span><span style="text-align:right;">Outst. Balance</span><span style="text-align:right;">Credit Limit</span><span></span>
  </div>
  <?php foreach ($accs as $a):
    $ccBal       = getCCBalances($a);
    $payable     = $ccBal['payable'];
    $outstanding = $ccBal['outstanding'];
    $total       = $ccBal['total'];
    $limit       = (float)$a['credit_limit'];
    $available   = $limit - $total;
    $usePct      = $limit > 0 ? min(100, round($total/$limit*100)) : 0;
  ?>
  <div style="padding:10px 18px;border-bottom:1px solid var(--s3);display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;align-items:center;gap:8px;">
    <div>
      <a href="account_detail.php?id=<?=$a['id']?>" style="font-weight:500;font-size:.88rem;"><?=htmlspecialchars($a['name'])?></a>
      <?php if ($a['bill_date']): ?>
        <div style="font-size:.72rem;color:var(--muted);">Bill: <?=$a['bill_date']?> | Due: <?=$a['payment_due_date']?></div>
      <?php endif; ?>
      <?php if ($limit > 0): ?>
        <div style="margin-top:4px;height:3px;background:var(--s3);border-radius:2px;">
          <div style="width:<?=$usePct?>%;height:100%;background:<?=$usePct>80?'var(--red)':'var(--orange)'?>;border-radius:2px;"></div>
        </div>
      <?php endif; ?>
    </div>
    <div style="text-align:right;font-weight:600;" class="<?=$payable>0?'c-red':'c-muted'?>">
      -<?=$a['currency']?> <?=money($payable, $a['currency'])?>
    </div>
    <div style="text-align:right;font-weight:600;" class="<?=$outstanding>0?'c-orange':'c-muted'?>">
      <?=$a['currency']?> <?=money($outstanding, $a['currency'])?>
    </div>
    <div style="text-align:right;font-size:.82rem;color:var(--muted);">
      <?=$a['currency']?> <?=number_format($limit,0)?><br>
      <span style="font-size:.7rem;">Avail: <?=money($available)?></span>
    </div>
    <div class="gap-2">
      <a href="account_detail.php?id=<?=$a['id']?>" class="btn btn-ghost btn-sm">View</a>
      <a href="edit_account.php?id=<?=$a['id']?>" class="btn btn-ghost btn-sm">Edit</a>
    </div>
  </div>
  <?php endforeach; ?>

  <?php else: ?>
  <!-- Normal account group — asset+liability together -->
  <?php
    // Sort: assets first, then liabilities
    usort($accs, fn($a,$b) => strcmp($b['type'],$a['type']));
    $lastType = null;
  ?>
  <?php foreach ($accs as $a):
    // Show separator between asset and liability in mixed group
    if ($isMixed && $lastType && $lastType !== $a['type']):
  ?>
    <div style="background:var(--bg);padding:5px 18px;font-size:.7rem;color:var(--red);text-transform:uppercase;letter-spacing:.06em;border-top:1px solid var(--s3);">
      ↓ Liabilities
    </div>
  <?php endif; $lastType = $a['type']; ?>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 18px;border-bottom:1px solid var(--s3);">
    <div style="flex:1;">
      <a href="account_detail.php?id=<?=$a['id']?>" style="font-size:.9rem;font-weight:500;"><?=htmlspecialchars($a['name'])?></a>
      <?php if ($a['currency']!=='BHD'): ?>
        <span style="font-size:.72rem;color:var(--muted);margin-left:6px;">≈ BD <?=money(toBHD((float)$a['balance'],$a['currency']))?></span>
      <?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
      <span style="font-size:.92rem;font-weight:700;" class="<?=(float)$a['balance']<0?'c-red':'c-blue'?>">
        <?=money((float)$a['balance'], $a['currency'])?> <?=$a['currency']?>
      </span>
      <div class="gap-2">
        <a href="account_detail.php?id=<?=$a['id']?>" class="btn btn-ghost btn-sm">View</a>
        <a href="edit_account.php?id=<?=$a['id']?>" class="btn btn-ghost btn-sm">Edit</a>
        <a href="add_transaction.php?account_id=<?=$a['id']?>" class="btn btn-primary btn-sm">+</a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<?php require 'footer.php'; ?>
