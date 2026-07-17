<?php

require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();

$result = '';

// Test 1: Direct DB update
if (isset($_POST['test_update'])) {
    $id   = (int)$_POST['test_id'];
    $name = trim($_POST['test_name']);
    try {
        $st = db()->prepare("UPDATE accounts SET name=? WHERE id=?");
        $ok = $st->execute([$name, $id]);
        $rows = $st->rowCount();
        $result = "UPDATE ran. OK=$ok, Rows affected=$rows, ID=$id, Name=$name";
    } catch (Exception $e) {
        $result = "ERROR: " . $e->getMessage();
    }
}

// Load first 5 accounts for testing
$accounts = db()->query("SELECT id,name,balance,currency FROM accounts WHERE is_active=1 LIMIT 5")->fetchAll();
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
body{background:#0f1117;color:#e1e1e1;font-family:monospace;padding:30px;}
input,select{background:#252836;color:#e1e1e1;border:1px solid #2e3347;padding:8px;border-radius:6px;width:100%;margin-bottom:10px;}
button{background:#4e9af1;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:1rem;}
.result{background:#1a2a1a;border:1px solid #2ecc71;padding:14px;border-radius:8px;color:#2ecc71;margin:16px 0;font-size:.9rem;}
.err{background:#2a1a1a;border:1px solid #e74c3c;color:#e74c3c;}
table{width:100%;border-collapse:collapse;margin-bottom:20px;}
td,th{padding:8px 12px;border:1px solid #2e3347;font-size:.85rem;}
th{background:#1a1d27;}
</style></head>
<body>
<h2 style="color:#4e9af1;">🔧 Direct DB Edit Test</h2>

<?php if($result): ?>
<div class="result <?=strpos($result,'ERROR')!==false?'err':''?>"><?=htmlspecialchars($result)?></div>
<?php endif; ?>

<h3>Current Accounts (first 5):</h3>
<table>
<tr><th>ID</th><th>Name</th><th>Balance</th><th>Currency</th></tr>
<?php foreach($accounts as $a): ?>
<tr><td><?=$a['id']?></td><td><?=htmlspecialchars($a['name'])?></td><td><?=$a['balance']?></td><td><?=$a['currency']?></td></tr>
<?php endforeach; ?>
</table>

<h3>Test Direct Update:</h3>
<form method="POST" action="test_edit.php">
  <label>Account ID to update:</label>
  <input type="number" name="test_id" value="<?=$accounts[0]['id']??1?>">
  <label>New Name:</label>
  <input type="text" name="test_name" value="TEST_<?=time()?>">
  <button type="submit" name="test_update" value="1">Run UPDATE Now</button>
</form>

<div style="margin-top:30px;color:#8892a4;font-size:.8rem;">
POST data received: <?=htmlspecialchars(json_encode($_POST))?>
</div>
</body></html>
