<?php $p = $activePage ?? ''; ?>
<nav class="sidebar" id="sidebar">
  <div class="logo">💰 Sajjad Finance</div>
  <div class="ns">Main</div>
  <a href="index.php"        class="ni <?=$p==='dashboard'   ?'active':''?>"><span>📊</span> Dashboard</a>
  <a href="accounts.php"     class="ni <?=$p==='accounts'    ?'active':''?>"><span>🏦</span> Accounts</a>
  <a href="transactions.php" class="ni <?=$p==='transactions'?'active':''?>"><span>📋</span> Transactions</a>
  <a href="scheduled.php"    class="ni <?=$p==='scheduled'   ?'active':''?>"><span>⏰</span> Scheduled</a>
  <div class="ns">Actions</div>
  <a href="add_transaction.php" class="ni <?=$p==='add_txn'    ?'active':''?>"><span>➕</span> Add Transaction</a>
  <a href="add_account.php"     class="ni <?=$p==='add_acc'   ?'active':''?>"><span>🏛</span> Add Account</a>
  <a href="account_groups.php"  class="ni <?=$p==='groups'    ?'active':''?>"><span>📁</span> Account Groups</a>
  <a href="import.php"          class="ni <?=$p==='import'    ?'active':''?>"><span>📥</span> Import Excel</a>
  <div class="ns">Reports</div>
  <a href="reports.php"    class="ni <?=$p==='reports'    ?'active':''?>"><span>📈</span> Reports</a>
  <a href="portfolio.php"  class="ni <?=$p==='portfolio'  ?'active':''?>"><span>💹</span> Portfolio</a>
  <a href="rates.php"      class="ni <?=$p==='rates'      ?'active':''?>"><span>💱</span> Rates</a>
  <a href="categories.php" class="ni <?=$p==='categories' ?'active':''?>"><span>🏷</span> Categories</a>
  <a href="export.php"     class="ni <?=$p==='export'     ?'active':''?>"><span>📤</span> Export Data</a>
  <div class="ns">System</div>
  <a href="setup.php"          class="ni <?=$p==='setup'?'active':''?>"><span>🤖</span> Bot Setup</a>
  <a href="/dashboard/login.php?logout=1" class="ni"><span>🔒</span> Logout</a>
</nav>
