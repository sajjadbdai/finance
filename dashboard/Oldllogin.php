<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();

// Auto-login via device token cookie
if (!isset($_SESSION['auth']) && isset($_COOKIE['device_token'])) {
    try {
        $tok = $_COOKIE['device_token'];
        $st  = db()->prepare("SELECT id FROM device_tokens WHERE token=? AND is_active=1");
        $st->execute([$tok]);
        if ($st->fetch()) {
            $_SESSION['auth'] = true;
            // Update last used
            db()->prepare("UPDATE device_tokens SET last_used=NOW() WHERE token=?")->execute([$tok]);
            header('Location: /dashboard/index.php'); exit;
        }
    } catch(Exception $e){}
}

if (isset($_GET['logout'])) {
    // Clear device token on logout if requested
    if (isset($_GET['all']) && isset($_COOKIE['device_token'])) {
        try {
            db()->prepare("UPDATE device_tokens SET is_active=0 WHERE token=?")
               ->execute([$_COOKIE['device_token']]);
        } catch(Exception $e){}
        setcookie('device_token', '', time()-3600, '/', '', true, true);
    }
    session_destroy();
    header('Location: /dashboard/login.php');
    exit;
}

if (isset($_SESSION['auth'])) {
    header('Location: /dashboard/index.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (($_POST['password']??'') === DASHBOARD_PASSWORD) {
        $_SESSION['auth'] = true;

        // If "Remember this device" checked — create device token
        if (!empty($_POST['remember'])) {
            try {
                db()->exec("CREATE TABLE IF NOT EXISTS device_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    token VARCHAR(64) NOT NULL UNIQUE,
                    device_name VARCHAR(100) DEFAULT '',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_used DATETIME DEFAULT CURRENT_TIMESTAMP,
                    is_active TINYINT(1) DEFAULT 1
                )");
                $token = bin2hex(random_bytes(32));
                $device = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                db()->prepare("INSERT INTO device_tokens (token, device_name) VALUES (?,?)")
                   ->execute([$token, substr($device,0,100)]);
                // Set cookie for 1 year, secure, httponly
                setcookie('device_token', $token, time()+365*24*3600, '/', '', true, true);
            } catch(Exception $e){}
        }

        header('Location: /dashboard/index.php'); exit;
    }
    $error = 'Wrong password.';
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — Sajjad Finance</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#0f1117;color:#e1e1e1;font-family:'Segoe UI',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;}
.box{background:#1a1d27;border:1px solid #2e3347;border-radius:16px;padding:40px 36px;width:100%;max-width:380px;}
.logo{text-align:center;font-size:2.5rem;margin-bottom:8px;}
h2{text-align:center;color:#4e9af1;margin-bottom:4px;font-size:1.3rem;}
p{text-align:center;color:#8892a4;font-size:.85rem;margin-bottom:28px;}
label{display:block;font-size:.85rem;color:#8892a4;margin-bottom:6px;}
input[type=password]{width:100%;padding:13px 14px;background:#252836;border:1px solid #2e3347;border-radius:8px;color:#e1e1e1;font-size:1rem;outline:none;margin-bottom:16px;}
input[type=password]:focus{border-color:#4e9af1;}
.remember{display:flex;align-items:center;gap:10px;margin-bottom:20px;cursor:pointer;}
.remember input{width:18px;height:18px;cursor:pointer;accent-color:#4e9af1;}
.remember span{font-size:.85rem;color:#8892a4;}
button{width:100%;padding:13px;background:#4e9af1;color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;}
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
    <label class="remember">
      <input type="checkbox" name="remember" value="1" checked>
      <span>📱 Remember this device (stay logged in)</span>
    </label>
    <button type="submit">Login</button>
  </form>
</div>
</body></html>
