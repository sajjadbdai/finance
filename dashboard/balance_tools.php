<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Balance & Integrity Tools'; $activePage='balance_tools'; $backTo='index.php';
require 'header.php';
?>

<div class="card" style="margin-bottom:20px;">
  <div style="font-size:.84rem;color:var(--muted);line-height:1.6;">
    Everything built during the balance-drift investigation, in one place. All read-only unless noted —
    none of these change data without an explicit confirm step.
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;">

  <a href="trial_balance.php" class="tool-card">
    <div class="tool-icon">⚖️</div>
    <div class="tool-title">Trial Balance</div>
    <div class="tool-desc">The definitive check — true double-entry Debit/Credit totals, should balance to zero exactly. Start here.</div>
  </a>

  <a href="balance_audit.php" class="tool-card">
    <div class="tool-icon">🔍</div>
    <div class="tool-title">Balance Audit</div>
    <div class="tool-desc">Per-account: stored balance vs. sum of its own transactions.</div>
  </a>

  <a href="transfer_audit.php" class="tool-card">
    <div class="tool-icon">🔀</div>
    <div class="tool-title">Transfer Audit</div>
    <div class="tool-desc">Finds transfers that debited an account but were never credited anywhere (Dr = Cr check).</div>
  </a>

  <a href="schedule_forensics.php" class="tool-card">
    <div class="tool-icon">🔬</div>
    <div class="tool-title">Schedule Forensics</div>
    <div class="tool-desc">Measures exactly what the scheduled-payment duplicate incident cost, per schedule.</div>
  </a>

  <a href="opening_balance_reconciliation.php" class="tool-card">
    <div class="tool-icon">🧭</div>
    <div class="tool-title">Opening Balance Check</div>
    <div class="tool-desc">Compares today's balance against your 18-June checkpoint plus everything recorded since.</div>
  </a>

  <a href="portfolio_balance_repair.php" class="tool-card">
    <div class="tool-icon">🔧</div>
    <div class="tool-title">Portfolio Balance Repair</div>
    <div class="tool-desc">Resets a portfolio-linked account's cash balance after the old market-value sync bug.</div>
  </a>

  <a href="ledger_backfill.php" class="tool-card">
    <div class="tool-icon">📚</div>
    <div class="tool-title">Ledger Backfill</div>
    <div class="tool-desc"><strong>Writes data.</strong> Generates double-entry ledger rows for existing transactions. Safe to re-run.</div>
  </a>

</div>

<style>
.tool-card{display:block;padding:16px;border:1px solid var(--s3);border-radius:10px;text-decoration:none;color:inherit;background:var(--s2);transition:border-color .15s,transform .15s;}
.tool-card:hover{border-color:var(--blue);transform:translateY(-1px);}
.tool-icon{font-size:1.6rem;}
.tool-title{font-weight:700;margin-top:8px;font-size:.95rem;}
.tool-desc{font-size:.78rem;color:var(--muted);margin-top:4px;line-height:1.4;}
</style>

<?php require 'footer.php'; ?>
