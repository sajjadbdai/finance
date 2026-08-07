<?php
/**
 * cc_sync.php — keeps accounts.payable_balance / outstanding_balance in step
 *               with what the transaction ledger actually says.
 *
 * REPLACES cron/cc_bill_processor.php. Delete that file once this is running.
 *
 * Cron (daily, just after midnight so the roll-over lands on the bill date):
 *   5 0 * * * php /home/sajjadbd/finance.sajjad.bd/cron/cc_sync.php >> /home/sajjadbd/cc_sync.log 2>&1
 *
 * Dry run first — prints what it would change and writes nothing:
 *   php /home/sajjadbd/finance.sajjad.bd/cron/cc_sync.php --dry
 *
 * WHAT IT DOES NOT DO
 *   It never writes accounts.balance. Balance belongs to the ledger.
 *   The old processor set balance = -(payable + outstanding), which meant a
 *   stale column could overwrite a correct balance. That is how balances drift.
 *
 * WHY THE ROLL-OVER NEEDS NO SPECIAL CASE
 *   Payable and outstanding are derived from the bill day every time this runs,
 *   so on the bill date the current cycle becomes payable on its own. There is
 *   no state to carry, nothing to miss if a run is skipped, and running it
 *   twice changes nothing.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/db.php';

$libCandidates = [
    __DIR__ . '/../dashboard/cc_lib.php',
    __DIR__ . '/../api/cc_lib.php',
    __DIR__ . '/cc_lib.php',
];
$lib = null;
foreach ($libCandidates as $p) { if (file_exists($p)) { $lib = $p; break; } }
if (!$lib) {
    fwrite(STDERR, "cc_lib.php not found. Looked in:\n  " . implode("\n  ", $libCandidates) . "\n");
    exit(1);
}
require_once $lib;

$dry   = in_array('--dry', $argv ?? [], true) || in_array('--dry-run', $argv ?? [], true);
$stamp = date('Y-m-d H:i:s');
$today = date('Y-m-d');

echo "$stamp | cc_sync " . ($dry ? "(DRY RUN — nothing will be written)" : "") . "\n";

$cards = db()->query(
    "SELECT id, name, currency, bill_date
       FROM accounts
      WHERE is_credit_card=1 AND is_active=1
      ORDER BY name"
)->fetchAll();

if (!$cards) { echo "No active credit cards.\n"; exit(0); }

$changed = 0; $skipped = 0; $billedToday = 0;

foreach ($cards as $card) {
    $id  = (int)$card['id'];
    $cur = $card['currency'] ?: 'BHD';
    $d   = ccDerive($id, $today);

    if (empty($d['ok'])) {
        echo "  SKIP  {$card['name']} — " . ($d['error'] ?: 'could not derive') . "\n";
        $skipped++;
        continue;
    }
    if ($d['bill_day'] < 1) {
        echo "  SKIP  {$card['name']} — no bill date set\n";
        $skipped++;
        continue;
    }

    $newPay  = $d['payable'];
    $newOut  = $d['outstanding'];
    $oldPay  = $d['stored_payable'];
    $oldOut  = $d['stored_outstanding'];

    // Flag the day the cycle rolls, purely for the log.
    if ($d['statement_date'] === $today) {
        echo "  BILL  {$card['name']} — statement cut today ({$today})\n";
        $billedToday++;
    }

    $payMoved = ($oldPay === null) || (abs($oldPay - $newPay) >= CC_EPS);
    $outMoved = ($oldOut === null) || (abs($oldOut - $newOut) >= CC_EPS);

    if (!$payMoved && !$outMoved) {
        echo "  ok    {$card['name']} — payable " . ccMoney($newPay, $cur)
           . ", outstanding " . ccMoney($newOut, $cur) . "\n";
        continue;
    }

    printf(
        "  %s  %s\n         payable      %s -> %s\n         outstanding  %s -> %s\n",
        $dry ? 'WOULD' : 'FIX  ',
        $card['name'],
        $oldPay === null ? 'null' : ccMoney($oldPay, $cur), ccMoney($newPay, $cur),
        $oldOut === null ? 'null' : ccMoney($oldOut, $cur), ccMoney($newOut, $cur)
    );

    if (!$dry) {
        // balance is deliberately absent from this UPDATE.
        db()->prepare(
            "UPDATE accounts
                SET payable_balance=?, outstanding_balance=?, updated_at=NOW()
              WHERE id=?"
        )->execute([$newPay, $newOut, $id]);
    }
    $changed++;
}

echo "$stamp | " . count($cards) . " card(s): $changed "
   . ($dry ? "would change" : "updated") . ", $skipped skipped"
   . ($billedToday ? ", $billedToday billed today" : "") . "\n";
