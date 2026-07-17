<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();

if (isset($_GET['logout'])) { session_destroy(); header('Location: /dashboard/login.php'); exit; }
if (isset($_SESSION['auth'])) { header('Location: /dashboard/index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (($_POST['password']??'') === DASHBOARD_PASSWORD) {
        $_SESSION['auth'] = true;
        header('Location: /dashboard/index.php'); exit;
    }
    $error = 'Wrong password.';
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — Sajjad Finance</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#0f1117;color:#e1e1e1;font-family:'Segoe UI',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;}
.box{background:#1a1d27;border:1px solid #2e3347;border-radius:16px;padding:40px 36px;width:100%;max-width:380px;}
.logo{text-align:center;font-size:2.5rem;margin-bottom:8px;}
h2{text-align:center;color:#4e9af1;margin-bottom:4px;font-size:1.3rem;}
p{text-align:center;color:#8892a4;font-size:.85rem;margin-bottom:28px;}
label{display:block;font-size:.85rem;color:#8892a4;margin-bottom:6px;}
input{width:100%;padding:12px 14px;background:#252836;border:1px solid #2e3347;border-radius:8px;color:#e1e1e1;font-size:.95rem;outline:none;margin-bottom:16px;}
input:focus{border-color:#4e9af1;}
button{width:100%;padding:12px;background:#4e9af1;color:#fff;border:none;border-radius:8px;font-size:.95rem;font-weight:600;cursor:pointer;}
button:hover{background:#3d87d8;}
.err{background:#e74c3c22;border:1px solid #e74c3c;color:#e74c3c;padding:10px 14px;border-radius:8px;font-size:.85rem;margin-bottom:16px;}
</style>
</head><body>
<div class="box">
  <div class="logo">💰</div>
  <h2>Sajjad Finance</h2>
  <p>Personal Finance Dashboard</p>
  <?php if($error):?><div class="err">❌ <?=htmlspecialchars($error)?></div><?php endif;?>
  <form method="POST">
    <label>Password</label>
    <input type="password" name="password" placeholder="Enter password" autofocus>
    <button type="submit">Login</button>
  </form>
</div>
</body></html>
