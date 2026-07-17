<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sync_portfolio_accounts.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: login.php'); exit; }

$log = syncPortfolioToAccounts();

$pageTitle = 'Portfolio Sync'; $activePage = 'portfolio';
require 'header.php';
?>
<div class="card" style="max-width:500px;">
    <div class="section-title" style="margin-bottom:16px;">🔄 Portfolio → Account Sync</div>
    <?php foreach($log as $line): ?>
    <div style="padding:8px 0;border-bottom:1px solid var(--s3);font-size:.9rem;">
        <?=htmlspecialchars($line)?>
    </div>
    <?php endforeach; ?>
    <div style="margin-top:16px;" class="gap-2">
        <a href="portfolio.php" class="btn btn-primary">← Back to Portfolio</a>
        <a href="accounts.php" class="btn btn-ghost">View Accounts</a>
    </div>
</div>
<?php require 'footer.php'; ?>
