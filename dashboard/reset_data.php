<?php
/**
 * Reset All Transactions
 *
 * DESTRUCTIVE. Deletes every transaction, every ledger_entries row, and
 * resets every account's balance to 0 — for starting over with a clean
 * import (e.g. via import_own_export.php after cleaning up a CSV).
 *
 * Safety: requires typing DELETE exactly (case-sensitive) into a text
 * field. The button stays disabled until the typed text matches, and
 * the server re-checks the same string — the client-side check alone
 * is not trusted.
 *
 * Does NOT touch: accounts (the accounts themselves stay, only their
 * balance resets to 0), portfolio holdings, fixed_assets, scheduled
 * payments, categories, or balance_checkpoints (cleared separately
 * below since old checkpoints reference balances that no longer apply).
 */
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Reset All Transactions'; $activePage='export'; $backTo='export.php';

$counts = [
    'transactions' => (int)db()->query("SELECT COUNT(*) FROM transactions")->fetchColumn(),
    'ledger'       => (int)db()->query("SELECT COUNT(*) FROM ledger_entries")->fetchColumn(),
    'accounts'     => (int)db()->query("SELECT COUNT(*) FROM accounts WHERE is_active=1")->fetchColumn(),
];

$done = false; $error = '';
if (isset($_POST['do_reset'])) {
    $typed = trim($_POST['confirm_text'] ?? '');
    if ($typed !== 'DELETE') {
        $error = 'You must type DELETE exactly to confirm. Nothing was reset.';
    } else {
        try {
            db()->beginTransaction();
            db()->exec("DELETE FROM transactions");
            db()->exec("DELETE FROM ledger_entries");
            db()->exec("DELETE FROM balance_checkpoints");
            db()->exec("UPDATE accounts SET balance=0");
            db()->commit();
            $done = true;
        } catch (\Throwable $e) {
            db()->rollBack();
            $error = 'Reset failed, nothing was changed: ' . $e->getMessage();
        }
    }
}

require 'header.php';
?>

<?php if($done):?>
<div class="card" style="border:1px solid var(--green);margin-bottom:16px;">
  <div class="section-title" style="color:var(--green);margin-bottom:10px;">✅ Reset Complete</div>
  <div style="font-size:.84rem;color:var(--muted);">
    All transactions, ledger entries, and balance checkpoints are gone. Every account balance is now 0.
    <a href="import_own_export.php">Import your CSV</a> to rebuild, or add transactions manually.
  </div>
</div>
<?php else: ?>

<div class="card" style="margin-bottom:16px;">
  <div class="section-title" style="margin-bottom:10px;">Current Data</div>
  <div class="g3">
    <div class="card"><div class="card-title">Transactions</div><div class="card-value c-blue"><?=number_format($counts['transactions'])?></div></div>
    <div class="card"><div class="card-title">Ledger Entries</div><div class="card-value c-blue"><?=number_format($counts['ledger'])?></div></div>
    <div class="card"><div class="card-title">Active Accounts (unaffected)</div><div class="card-value c-green"><?=number_format($counts['accounts'])?></div></div>
  </div>
</div>

<div class="card" style="margin-bottom:16px;">
  <div style="font-size:.84rem;color:var(--muted);">
    Before resetting anything, back up your data: <a href="export.php">Export Data</a> → Download CSV
    (all months) and Accounts CSV. This cannot be undone once confirmed.
  </div>
</div>

<?php if($error):?><div class="alert alert-danger">❌ <?=htmlspecialchars($error)?></div><?php endif;?>

<div class="card" style="border:2px solid var(--red);">
  <div class="section-title" style="color:var(--red);margin-bottom:10px;">⚠️ Reset All Transactions</div>
  <div style="font-size:.84rem;color:var(--muted);margin-bottom:14px;line-height:1.6;">
    This permanently deletes:
    <ul style="margin:8px 0 0 20px;">
      <li>All <?=number_format($counts['transactions'])?> transactions</li>
      <li>All <?=number_format($counts['ledger'])?> double-entry ledger rows</li>
      <li>All balance checkpoints</li>
    </ul>
    And resets every account's balance to <strong>0</strong>. Your accounts themselves (names, currencies,
    credit card settings) are NOT deleted — only their transaction history and balance.
  </div>

  <form method="POST" onsubmit="return confirmReset(event)">
    <input type="hidden" name="do_reset" value="1">
    <div class="form-group">
      <label class="form-label">Type <code>DELETE</code> to confirm</label>
      <input type="text" name="confirm_text" id="confirmText" class="form-control" autocomplete="off" placeholder="DELETE">
    </div>
    <button type="submit" id="resetBtn" class="btn btn-danger" disabled>🗑️ Reset Everything</button>
  </form>
</div>

<script>
const input = document.getElementById('confirmText');
const btn = document.getElementById('resetBtn');
input.addEventListener('input', () => {
  btn.disabled = (input.value !== 'DELETE');
});
function confirmReset(e) {
  if (input.value !== 'DELETE') { e.preventDefault(); return false; }
  return confirm('Really delete all transactions and reset every balance to 0? This cannot be undone.');
}
</script>

<?php endif; ?>

<?php require 'footer.php'; ?>
