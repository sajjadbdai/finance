<?php
// inc_header.php - safe version, no DB queries, no redirects
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle  = $pageTitle  ?? 'Dashboard';
$activePage = $activePage ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<script>
// Apply theme immediately to prevent flash
(function(){
    var t = localStorage.getItem('theme') || 'dark';
    if(t === 'light') document.documentElement.classList.add('light-early');
})();
</script>
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars($pageTitle)?> — Sajjad Finance</title>
<style>
:root{--bg:#0f1117;--s1:#1a1d27;--s2:#252836;--s3:#2e3347;--text:#e1e1e1;--muted:#8892a4;--blue:#4e9af1;--green:#2ecc71;--red:#e74c3c;--orange:#f39c12;--r:12px;}
html.light-early body,body.light{--bg:#f0f2f7;--s1:#ffffff;--s2:#f5f6fa;--s3:#e2e5ed;--text:#1a1d27;--muted:#6b7280;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Segoe UI',system-ui,sans-serif;font-size:15px;line-height:1.5;display:flex;min-height:100vh;}
a{color:var(--blue);text-decoration:none;}
.sidebar{width:220px;background:var(--s1);border-right:1px solid var(--s3);padding:20px 0;position:fixed;top:0;left:0;height:100vh;overflow-y:auto;z-index:100;}
.logo{padding:0 18px 18px;font-size:1.1rem;font-weight:700;color:var(--blue);border-bottom:1px solid var(--s3);margin-bottom:8px;}
.ns{padding:10px 18px 4px;font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;}
.ni{display:flex;align-items:center;gap:10px;padding:10px 18px;color:var(--muted);font-size:.9rem;border-left:3px solid transparent;}
.ni:hover{background:var(--s2);color:var(--text);}
.ni.active{background:var(--s2);color:var(--blue);border-left-color:var(--blue);}
.main{margin-left:220px;flex:1;min-height:100vh;}
.topbar{background:var(--s1);border-bottom:1px solid var(--s3);padding:14px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:1.05rem;font-weight:600;}
.topbar-right{display:flex;align-items:center;gap:12px;font-size:.82rem;color:var(--muted);}
.content{padding:24px;}
.card{background:var(--s1);border:1px solid var(--s3);border-radius:var(--r);padding:20px;}
.card-title{font-size:.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;}
.card-value{font-size:1.6rem;font-weight:700;}
.card-sub{font-size:.8rem;color:var(--muted);margin-top:4px;}
.g3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px;}
.g2{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:20px;}
.g4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px;}
@media(max-width:900px){.g3{grid-template-columns:1fr 1fr;}}
@media(max-width:650px){.g3,.g4,.g2{grid-template-columns:1fr;}.main{margin-left:0;}.sidebar{transform:translateX(-100%);}.sidebar.open{transform:translateX(0);}}
.c-blue{color:var(--blue);}.c-green{color:var(--green);}.c-red{color:var(--red);}.c-orange{color:var(--orange);}.c-muted{color:var(--muted);}
.tbl{width:100%;border-collapse:collapse;font-size:.88rem;}
.tbl th{text-align:left;padding:10px 14px;background:var(--s2);color:var(--muted);font-weight:500;font-size:.75rem;text-transform:uppercase;white-space:nowrap;}
.tbl td{padding:10px 14px;border-bottom:1px solid var(--s3);vertical-align:middle;}
.tbl tr:last-child td{border-bottom:none;}
.tbl tr:hover td{background:var(--s2);}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:600;}
.badge-exp{background:#e74c3c22;color:var(--red);}
.badge-inc{background:#2ecc7122;color:var(--green);}
.badge-tra{background:#4e9af122;color:var(--blue);}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:8px;border:none;font-size:.88rem;font-weight:600;cursor:pointer;transition:.15s;text-decoration:none;}
.btn-primary{background:var(--blue);color:#fff;}.btn-primary:hover{background:#3d87d8;}
.btn-success{background:var(--green);color:#fff;}.btn-success:hover{background:#27ae60;}
.btn-danger{background:var(--red);color:#fff;}.btn-danger:hover{background:#c0392b;}
.btn-ghost{background:var(--s2);color:var(--text);border:1px solid var(--s3);}.btn-ghost:hover{background:var(--s3);}
.btn-sm{padding:6px 12px;font-size:.8rem;}
.form-group{margin-bottom:18px;}
.form-label{display:block;margin-bottom:6px;font-size:.85rem;color:var(--muted);font-weight:500;}
.form-control{width:100%;padding:10px 14px;background:var(--s2);border:1px solid var(--s3);border-radius:8px;color:var(--text);font-size:.9rem;outline:none;transition:.15s;}
.form-control:focus{border-color:var(--blue);}
select.form-control option{background:var(--s2);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
@media(max-width:600px){.form-row{grid-template-columns:1fr;}}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:.88rem;}
.alert-success{background:#2ecc7122;border:1px solid #2ecc71;color:#2ecc71;}
.alert-danger{background:#e74c3c22;border:1px solid #e74c3c;color:#e74c3c;}
.alert-info{background:#4e9af122;border:1px solid #4e9af1;color:#4e9af1;}
.scroll-y{overflow-y:auto;max-height:420px;}
.scroll-y::-webkit-scrollbar{width:4px;}
.scroll-y::-webkit-scrollbar-thumb{background:var(--s3);border-radius:4px;}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
.section-title{font-size:1rem;font-weight:600;}
.divider{border:none;border-top:1px solid var(--s3);margin:20px 0;}
.gap-2{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.menu-toggle{display:none;background:none;border:none;color:var(--text);font-size:1.3rem;cursor:pointer;padding:4px 8px;}
@media(max-width:650px){.menu-toggle{display:block;}}
.sidebar-overlay{display:none;position:fixed;inset:0;background:#00000060;z-index:99;}
.sidebar-overlay.open{display:block;}
@media(max-width:768px){
  .sidebar{transform:translateX(-100%);transition:transform .25s ease;}
  .sidebar.open{transform:translateX(0);}
  .main{margin-left:0 !important;}
  .menu-toggle{display:block;}
}
body.light .sidebar{box-shadow:2px 0 8px #0000001a;}
body.light .topbar{box-shadow:0 1px 4px #0000001a;}
/* Hide values */
body.values-hidden .card-value,body.values-hidden .card-sub,
body.values-hidden .c-blue,body.values-hidden td:nth-child(2),
body.values-hidden td:nth-child(3){filter:blur(6px);user-select:none;}
</style>
  <!-- PWA -->
  <link rel="manifest" href="/pwa/manifest.json">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Sajjad Finance">
  <meta name="theme-color" content="#4e9af1">
  <link rel="apple-touch-icon" href="/pwa/icon-152.png">
  <link rel="apple-touch-icon" sizes="72x72"  href="/pwa/icon-72.png">
  <link rel="apple-touch-icon" sizes="96x96"  href="/pwa/icon-96.png">
  <link rel="apple-touch-icon" sizes="128x128" href="/pwa/icon-128.png">
  <link rel="apple-touch-icon" sizes="144x144" href="/pwa/icon-144.png">
  <link rel="apple-touch-icon" sizes="152x152" href="/pwa/icon-152.png">
  <link rel="apple-touch-icon" sizes="192x192" href="/pwa/icon-192.png">
  <link rel="apple-touch-icon" sizes="512x512" href="/pwa/icon-512.png">
  <!-- Register Service Worker -->
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function() {
        navigator.serviceWorker.register('/pwa/sw.js')
          .then(function(reg) { console.log('SW registered'); })
          .catch(function(err) { console.log('SW error', err); });
      });
    }
  </script>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
<nav class="sidebar" id="sidebar">
  <div class="logo">💰 Sajjad Finance</div>
  <div class="ns">Main</div>
  <a href="index.php"        class="ni <?=$activePage==='dashboard'   ?'active':''?>"><span>📊</span> Dashboard</a>
  <a href="accounts.php"     class="ni <?=$activePage==='accounts'    ?'active':''?>"><span>🏦</span> Accounts</a>
  <a href="transactions.php" class="ni <?=$activePage==='transactions'?'active':''?>"><span>📋</span> Transactions</a>
  <div class="ns">Actions</div>
  <a href="add_transaction.php" class="ni <?=$activePage==='add_txn'   ?'active':''?>"><span>➕</span> Add Transaction</a>
  <a href="add_account.php"     class="ni <?=$activePage==='add_acc'  ?'active':''?>"><span>🏛</span> Add Account</a>
  <a href="account_groups.php"  class="ni <?=$activePage==='groups'   ?'active':''?>"><span>📁</span> Account Groups</a>
  <a href="import.php"          class="ni <?=$activePage==='import'   ?'active':''?>"><span>📥</span> Import Excel</a>
  <div class="ns">Reports</div>
  <a href="reports.php"    class="ni <?=$activePage==='reports'    ?'active':''?>"><span>📈</span> Reports</a>
  <a href="portfolio.php"  class="ni <?=$activePage==='portfolio'  ?'active':''?>"><span>💹</span> Portfolio</a>
  <a href="scheduled.php"  class="ni <?=$activePage==='scheduled'  ?'active':''?>"><span>⏰</span> Scheduled</a>
  <a href="rates.php"      class="ni <?=$activePage==='rates'      ?'active':''?>"><span>💱</span> Rates</a>
  <a href="categories.php" class="ni <?=$activePage==='categories' ?'active':''?>"><span>🏷</span> Categories</a>
  <a href="export.php"     class="ni <?=$activePage==='export'     ?'active':''?>"><span>📤</span> Export Data</a>
  <div class="ns">System</div>
  <a href="fixed_assets.php"   class="ni <?=$activePage==='fixed_assets'?'active':''?>"><span>🏠</span> Fixed Assets</a>
  <a href="setup.php"          class="ni <?=$activePage==='setup'?'active':''?>"><span>🤖</span> Bot Setup</a>
  <a href="/dashboard/login.php?logout=1" class="ni"><span>🔒</span> Logout</a>
</nav>
<div class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:10px;">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <div class="topbar-title"><?=htmlspecialchars($pageTitle)?></div>
    </div>
    <div class="topbar-right">
      <span><?=date('d M Y, H:i')?></span>
      <button onclick="toggleTheme()" id="theme-btn" title="Toggle theme" style="background:none;border:none;color:var(--muted);font-size:1.2rem;cursor:pointer;">🌙</button>
      <button onclick="toggleHide()" id="hide-btn" title="Hide/Show values" style="background:none;border:none;color:var(--muted);font-size:1.2rem;cursor:pointer;">👁</button>
      <a href="add_transaction.php" class="btn btn-primary btn-sm">+ Add</a>
    </div>
  </div>
  <div class="content">
<script>
function toggleSidebar(){
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
    document.body.style.overflow = document.getElementById('sidebar').classList.contains('open') ? 'hidden' : '';
}
// Theme
function applyTheme(t){ document.body.classList.toggle('light',t==='light'); document.documentElement.classList.toggle('light-early',t==='light'); localStorage.setItem('theme',t); var btn=document.getElementById('theme-btn'); if(btn) btn.textContent=t==='light'?'☀️':'🌙'; }
function toggleTheme(){ applyTheme(localStorage.getItem('theme')==='light'?'dark':'light'); }
applyTheme(localStorage.getItem('theme')||'dark');

// Hide/Show values with PIN
var _hidden=false;
function toggleHide(){
    if(!_hidden){
        // Hide - no PIN needed
        _hidden=true;
        document.body.classList.add('values-hidden');
        document.getElementById('hide-btn').textContent='🙈';
    } else {
        // Show - check PIN if set
        var pin=localStorage.getItem('hide_pin')||'';
        if(pin){
            var input=prompt('Enter PIN to show values:');
            if(input!==pin){ alert('Wrong PIN!'); return; }
        }
        _hidden=false;
        document.body.classList.remove('values-hidden');
        document.getElementById('hide-btn').textContent='👁';
    }
}
// PIN setup - double tap hide button to set PIN
var _tapCount=0;
document.addEventListener('DOMContentLoaded',function(){
    var btn=document.getElementById('hide-btn');
    if(btn) btn.addEventListener('dblclick',function(){
        var cur=localStorage.getItem('hide_pin')||'';
        var np=prompt('Set PIN (leave empty to remove PIN):', cur);
        if(np!==null){ localStorage.setItem('hide_pin',np); alert(np?'PIN set!':'PIN removed!'); }
    });
});

// Close sidebar on nav link click (mobile)
document.querySelectorAll('.ni').forEach(function(el){
    el.addEventListener('click', function(){
        if(window.innerWidth <= 768){
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('overlay').classList.remove('open');
            document.body.style.overflow = '';
        }
    });
});
</script><!-- PWA Install Banner - Android only, hidden on iOS/standalone -->
<div id="pwa-banner" style="display:none;position:fixed;bottom:0;left:0;right:0;background:var(--blue);color:#fff;padding:14px 18px;z-index:9999;align-items:center;justify-content:space-between;box-shadow:0 -2px 12px #00000040;">
  <div>
    <div style="font-weight:700;font-size:.95rem;">📱 Install Sajjad Finance</div>
    <div style="font-size:.78rem;opacity:.85;">Add to home screen for app experience</div>
  </div>
  <div style="display:flex;gap:8px;">
    <button onclick="installPWA()" style="background:#fff;color:var(--blue);border:none;padding:8px 16px;border-radius:8px;font-weight:700;cursor:pointer;font-size:.85rem;">Install</button>
    <button onclick="document.getElementById('pwa-banner').style.display='none';localStorage.setItem('pwa-dismissed','1')" style="background:transparent;color:#fff;border:1px solid rgba(255,255,255,.5);padding:8px 12px;border-radius:8px;cursor:pointer;font-size:.85rem;">✕</button>
  </div>
</div>
<script>
// Never show banner if:
// 1. Already running as installed PWA (standalone mode)
// 2. Running on iOS (Safari handles install via share menu)
// 3. User dismissed it before
var isStandalone = window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true;
var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
var isDismissed = localStorage.getItem('pwa-dismissed');

let deferredPrompt;

if (!isStandalone && !isIOS && !isDismissed) {
  window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    deferredPrompt = e;
    document.getElementById('pwa-banner').style.display = 'flex';
  });
}

function installPWA() {
  document.getElementById('pwa-banner').style.display = 'none';
  if (deferredPrompt) {
    deferredPrompt.prompt();
    deferredPrompt.userChoice.then(function(r) {
      if (r.outcome === 'accepted') localStorage.setItem('pwa-installed','1');
      deferredPrompt = null;
    });
  }
}
window.addEventListener('appinstalled', function() {
  localStorage.setItem('pwa-installed','1');
  document.getElementById('pwa-banner').style.display = 'none';
});
</script>
