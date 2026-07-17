<?php
/**
 * UNIFIED SIDEBAR - fixes missing nav on Import & Bot Setup pages
 * Also adds theme toggle + balance lock button in header
 *
 * File: dashboard/sidebar.php
 * Usage: <?php include 'sidebar.php'; ?>
 * Replace any existing sidebar/nav HTML on every page with this one include.
 *
 * Requires:
 *   - session_start() already called
 *   - theme_system.php already included
 *   - $current_page set to one of: dashboard, accounts, transactions,
 *     scheduled, reports, portfolio, rates, categories, import, bot_setup,
 *     fixed_assets, export
 */

if (!isset($current_page)) $current_page = '';

$nav_items = [
    'MAIN' => [
        ['id'=>'dashboard',    'href'=>'index.php',           'icon'=>'📊', 'label'=>'Dashboard'],
        ['id'=>'accounts',     'href'=>'accounts.php',        'icon'=>'🏦', 'label'=>'Accounts'],
        ['id'=>'transactions', 'href'=>'transactions.php',    'icon'=>'📋', 'label'=>'Transactions'],
    ],
    'ACTIONS' => [
        ['id'=>'add_tx',       'href'=>'add_transaction.php', 'icon'=>'+',  'label'=>'Add Transaction', 'class'=>'nav-action'],
        ['id'=>'accounts_add', 'href'=>'add_account.php',     'icon'=>'🏛', 'label'=>'Add Account'],
        ['id'=>'groups',       'href'=>'account_groups.php',  'icon'=>'📁', 'label'=>'Account Groups'],
        ['id'=>'import',       'href'=>'import.php',          'icon'=>'📥', 'label'=>'Import Excel'],
        ['id'=>'fixed_assets', 'href'=>'fixed_assets.php',   'icon'=>'🏠', 'label'=>'Fixed Assets'],
    ],
    'REPORTS' => [
        ['id'=>'reports',      'href'=>'reports.php',         'icon'=>'📈', 'label'=>'Reports'],
        ['id'=>'portfolio',    'href'=>'portfolio.php',       'icon'=>'💹', 'label'=>'Portfolio'],
        ['id'=>'scheduled',    'href'=>'scheduled.php',       'icon'=>'⏰', 'label'=>'Scheduled'],
        ['id'=>'rates',        'href'=>'rates.php',           'icon'=>'💱', 'label'=>'Rates'],
        ['id'=>'categories',   'href'=>'categories.php',      'icon'=>'🏷', 'label'=>'Categories'],
        ['id'=>'export',       'href'=>'export.php',          'icon'=>'📤', 'label'=>'Export Data'],
    ],
    'SYSTEM' => [
        ['id'=>'bot_setup',    'href'=>'bot_setup.php',       'icon'=>'⚙️', 'label'=>'Bot Setup'],
    ],
];
?>

<!-- ====== MOBILE DRAWER OVERLAY ====== -->
<div id="drawer-overlay" onclick="closeSidebar()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:200;"></div>

<!-- ====== SIDEBAR ====== -->
<nav id="sidebar" style="
  position:fixed; top:0; left:0; height:100vh; width:240px;
  background:var(--sidebar-bg); border-right:1px solid var(--sidebar-border);
  display:flex; flex-direction:column; z-index:300;
  transform:translateX(-240px); transition:transform 0.25s ease;
  overflow-y:auto;
">
  <!-- Logo -->
  <div style="padding:20px 16px 14px; border-bottom:1px solid var(--border-subtle); flex-shrink:0;">
    <a href="index.php" style="text-decoration:none; display:flex; align-items:center; gap:8px;">
      <span style="font-size:22px;">💰</span>
      <span style="font-size:15px; font-weight:700; color:var(--accent-blue);">Sajjad Finance</span>
    </a>
  </div>

  <!-- Nav -->
  <div style="flex:1; padding:8px 0; overflow-y:auto;">
    <?php foreach ($nav_items as $section => $items): ?>
      <div class="section-label" style="margin-top:8px;"><?= $section ?></div>
      <?php foreach ($items as $item): ?>
        <?php $active = ($current_page === $item['id']); ?>
        <a href="<?= htmlspecialchars($item['href']) ?>"
           class="nav-link<?= $active ? ' active' : '' ?>"
           style="
             display:flex; align-items:center; gap:10px;
             padding:10px 16px; font-size:14px; font-weight:500;
             text-decoration:none;
             color: <?= $active ? 'var(--accent-blue)' : 'var(--text-secondary)' ?>;
             background: <?= $active ? 'var(--sidebar-active)' : 'transparent' ?>;
             border-left: 3px solid <?= $active ? 'var(--accent-blue)' : 'transparent' ?>;
             transition:all 0.15s;
           "
           onmouseover="if(!this.classList.contains('active')){this.style.background='var(--sidebar-hover)';this.style.color='var(--text-primary)';}"
           onmouseout="if(!this.classList.contains('active')){this.style.background='transparent';this.style.color='var(--text-secondary)';}">
          <span style="width:20px;text-align:center;font-size:16px;"><?= htmlspecialchars($item['icon']) ?></span>
          <span><?= htmlspecialchars($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>

  <!-- Theme toggle at bottom of sidebar -->
  <div style="padding:12px 16px; border-top:1px solid var(--border-subtle); flex-shrink:0;">
    <button onclick="toggleTheme()" class="theme-toggle-btn" style="width:100%; justify-content:center;">
      <span id="theme-icon"><?= $_SESSION['theme']==='dark' ? '☀️' : '🌙' ?></span>
      <span id="theme-label"><?= $_SESSION['theme']==='dark' ? 'Light Mode' : 'Dark Mode' ?></span>
    </button>
  </div>
</nav>

<!-- ====== TOP HEADER BAR ====== -->
<header id="top-header" style="
  position:fixed; top:0; left:0; right:0; height:52px;
  background:var(--bg-card); border-bottom:1px solid var(--border);
  display:flex; align-items:center; padding:0 16px; gap:12px;
  z-index:100; box-shadow:var(--shadow-sm);
">
  <!-- Hamburger -->
  <button onclick="toggleSidebar()" aria-label="Menu" style="
    background:none; border:none; color:var(--text-primary);
    font-size:22px; cursor:pointer; padding:4px 6px; border-radius:6px;
    line-height:1; flex-shrink:0;
  ">☰</button>

  <!-- Page title -->
  <span style="font-size:15px; font-weight:600; color:var(--text-primary); flex:1;">
    <?php
    $titles = [
      'dashboard'=>'Dashboard','accounts'=>'Accounts','transactions'=>'Transactions',
      'scheduled'=>'Scheduled','reports'=>'Reports','portfolio'=>'Portfolio',
      'rates'=>'Rates','categories'=>'Categories','import'=>'Import Excel',
      'fixed_assets'=>'Fixed Assets','export'=>'Export Data','bot_setup'=>'Bot Setup',
      'add_tx'=>'Add Transaction','groups'=>'Account Groups','accounts_add'=>'Add Account',
    ];
    echo htmlspecialchars($titles[$current_page] ?? 'Sajjad Finance');
    ?>
  </span>

  <!-- Balance lock button -->
  <?php if ($current_page === 'dashboard' || $current_page === 'accounts'): ?>
  <button onclick="toggleBalanceLock()" class="balance-lock-btn" id="balance-lock-btn" title="Hide/Show balances">
    <span id="lock-icon"><?= $balance_hidden ? '🔒' : '👁' ?></span>
    <span id="lock-label" style="display:none;" class="hide-on-mobile"><?= $balance_hidden ? 'Show' : 'Hide' ?></span>
  </button>
  <?php endif; ?>

  <!-- Theme toggle (top bar, visible on desktop) -->
  <button onclick="toggleTheme()" class="theme-toggle-btn" style="display:none;" id="theme-btn-top">
    <span id="theme-icon-top"><?= $_SESSION['theme']==='dark' ? '☀️' : '🌙' ?></span>
  </button>
</header>

<!-- Main content push -->
<div id="main-spacer" style="height:52px;"></div>

<!-- ====== THEME + SIDEBAR JS ====== -->
<script>
// Apply saved theme immediately
(function(){
  var t = '<?= $_SESSION['theme'] ?>';
  document.documentElement.setAttribute('data-theme', t);
})();

// Sidebar
var sidebarOpen = false;
function toggleSidebar() {
  sidebarOpen ? closeSidebar() : openSidebar();
}
function openSidebar() {
  document.getElementById('sidebar').style.transform = 'translateX(0)';
  document.getElementById('drawer-overlay').style.display = 'block';
  sidebarOpen = true;
}
function closeSidebar() {
  document.getElementById('sidebar').style.transform = 'translateX(-240px)';
  document.getElementById('drawer-overlay').style.display = 'none';
  sidebarOpen = false;
}

// Theme toggle
function toggleTheme() {
  var html = document.documentElement;
  var current = html.getAttribute('data-theme') || 'dark';
  var next = current === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  // Update icons
  document.getElementById('theme-icon').textContent = next === 'dark' ? '☀️' : '🌙';
  document.getElementById('theme-label').textContent = next === 'dark' ? 'Light Mode' : 'Dark Mode';
  var top = document.getElementById('theme-icon-top');
  if(top) top.textContent = next === 'dark' ? '☀️' : '🌙';
  // Save to server
  fetch(window.location.href, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=set_theme&theme='+next
  });
}

// Balance lock
var balanceHidden = <?= $balance_hidden ? 'true' : 'false' ?>;
var hasPin = <?= $has_pin ? 'true' : 'false' ?>;

function toggleBalanceLock() {
  if (balanceHidden) {
    // Show - need PIN if set
    if (hasPin) {
      showPinModal('unlock');
    } else {
      revealBalance();
    }
  } else {
    // Hide
    if (!hasPin) {
      showPinModal('setup');
    } else {
      hideBalance();
    }
  }
}

function revealBalance() {
  balanceHidden = false;
  document.querySelectorAll('.balance-value').forEach(function(el){
    el.classList.remove('hidden-balance');
  });
  var btn = document.getElementById('balance-lock-btn');
  if(btn) {
    document.getElementById('lock-icon').textContent = '👁';
    document.getElementById('lock-label').textContent = 'Hide';
  }
  fetch(window.location.href, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=verify_pin&pin=OK' // server already verified
  });
}

function hideBalance() {
  balanceHidden = true;
  document.querySelectorAll('.balance-value').forEach(function(el){
    el.classList.add('hidden-balance');
  });
  document.getElementById('lock-icon').textContent = '🔒';
  document.getElementById('lock-label').textContent = 'Show';
  fetch(window.location.href, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=hide_balance'
  });
}

// Click blurred balance to unlock
document.addEventListener('click', function(e) {
  if(e.target.classList.contains('hidden-balance')) {
    toggleBalanceLock();
  }
});

// ── PIN MODAL ──
var pinBuffer = '';
var pinModalMode = '';

function showPinModal(mode) {
  pinModalMode = mode;
  pinBuffer = '';
  updatePinDots();

  var title = mode === 'setup' ? '🔒 Set PIN' : '🔓 Enter PIN';
  var subtitle = mode === 'setup' ? 'Choose a 4-digit PIN to hide your balances' : 'Enter your 4-digit PIN';

  var html = '<div class="pin-modal-overlay" id="pin-modal-overlay" onclick="closePinModal(event)">'
    + '<div class="pin-modal" onclick="event.stopPropagation()">'
    + '<h3>' + title + '</h3>'
    + '<p>' + subtitle + '</p>'
    + '<div class="pin-dots" id="pin-dots">'
    + '<div class="pin-dot" id="dot0"></div>'
    + '<div class="pin-dot" id="dot1"></div>'
    + '<div class="pin-dot" id="dot2"></div>'
    + '<div class="pin-dot" id="dot3"></div>'
    + '</div>'
    + '<div class="pin-keypad">'
    + [1,2,3,4,5,6,7,8,9].map(function(n){
        return '<button class="pin-key" onclick="pinPress(\''+n+'\')">' + n + '</button>';
      }).join('')
    + '<button class="pin-key wide" onclick="pinPress(\'0\')">0</button>'
    + '<button class="pin-key" onclick="pinBackspace()">⌫</button>'
    + '</div>'
    + '<div class="pin-error" id="pin-error"></div>'
    + (hasPin && mode === 'unlock' ? '<button onclick="removePinAction()" style="margin-top:8px;font-size:12px;color:var(--text-muted);background:none;border:none;cursor:pointer;">Remove PIN</button>' : '')
    + '<button onclick="closePinModal()" style="margin-top:8px;font-size:13px;color:var(--text-muted);background:none;border:none;cursor:pointer;">Cancel</button>'
    + '</div></div>';

  var div = document.createElement('div');
  div.id = 'pin-modal-container';
  div.innerHTML = html;
  document.body.appendChild(div);
}

function closePinModal(e) {
  if(e && e.target && e.target.id !== 'pin-modal-overlay') return;
  var el = document.getElementById('pin-modal-container');
  if(el) el.remove();
  pinBuffer = '';
}

function pinPress(digit) {
  if(pinBuffer.length >= 4) return;
  pinBuffer += digit;
  updatePinDots();
  if(pinBuffer.length === 4) {
    setTimeout(submitPin, 150);
  }
}

function pinBackspace() {
  pinBuffer = pinBuffer.slice(0,-1);
  updatePinDots();
  document.getElementById('pin-error').textContent = '';
}

function updatePinDots() {
  for(var i=0;i<4;i++){
    var d = document.getElementById('dot'+i);
    if(d) d.classList.toggle('filled', i < pinBuffer.length);
  }
}

function submitPin() {
  if(pinModalMode === 'setup') {
    fetch(window.location.href, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'action=set_pin&pin='+pinBuffer
    }).then(r=>r.json()).then(function(d){
      if(d.ok) {
        hasPin = true;
        var el = document.getElementById('pin-modal-container');
        if(el) el.remove();
        hideBalance();
      } else {
        document.getElementById('pin-error').textContent = d.msg || 'Error';
        pinBuffer = ''; updatePinDots();
      }
    });
  } else {
    fetch(window.location.href, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'action=verify_pin&pin='+pinBuffer
    }).then(r=>r.json()).then(function(d){
      if(d.ok) {
        var el = document.getElementById('pin-modal-container');
        if(el) el.remove();
        revealBalance();
      } else {
        document.getElementById('pin-error').textContent = 'Wrong PIN';
        pinBuffer = ''; updatePinDots();
      }
    });
  }
}

function removePinAction() {
  if(confirm('Remove PIN? Balances will not be locked.')) {
    fetch(window.location.href, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'action=remove_pin'
    }).then(function(){
      hasPin = false;
      var el = document.getElementById('pin-modal-container');
      if(el) el.remove();
      revealBalance();
    });
  }
}

// Apply hidden state on load
if(balanceHidden) {
  document.querySelectorAll('.balance-value').forEach(function(el){
    el.classList.add('hidden-balance');
  });
}
</script>

<style>
@media(min-width:768px){
  #sidebar { transform: translateX(0) !important; }
  #drawer-overlay { display:none !important; }
  #main-content, .main-content { margin-left: 240px; }
  #theme-btn-top { display:flex !important; }
}
@media(max-width:767px){
  .hide-on-mobile { display:none !important; }
}
</style>
