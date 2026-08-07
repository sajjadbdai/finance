<?php
/**
 * balance_check.php — reconcile every account: stored balance vs the walk.
 *
 * WHY THIS IS NOW A REAL TEST
 *   Until accounts.opening_balance was stored, the opening was derived as
 *   (balance - movements). That made the walk end on the stored balance by
 *   definition, so comparing them proved nothing and any error was silently
 *   absorbed into the opening.
 *
 *   With the opening stored, these are two INDEPENDENT figures:
 *
 *       stored balance          what accounts.balance says
 *       opening + movements     what the transaction history implies
 *
 *   Where they disagree, something moved a balance without a transaction
 *   behind it — a manual DB edit, a deleted row, a half-applied reversal.
 *
 * IMMEDIATE REASON TO RUN IT
 *   cc_baseline.php froze all 62 openings while test transactions were still
 *   present. Cash on Hand and ILA both captured a 1.000 error that way. Any
 *   other account touched during testing may have done the same.
 *
 * WHAT DRIFT MEANS
 *   drift = (opening + movements) - stored balance
 *   positive  the walk expects MORE than the balance holds
 *   negative  the balance holds more than the history accounts for
 *
 *   Fix by correcting whichever is wrong — usually re-entering a missing
 *   transaction, occasionally correcting the stored opening. Do not simply
 *   overwrite the opening to make it balance; that is the plug behaviour
 *   this whole exercise removed.
 *
 * Read-only. Writes nothing.
 *
 * USAGE — upload to the SITE ROOT or dashboard/:
 *   php balance_check.php              every account
 *   php balance_check.php --drift      only ones that disagree
 *   php balance_check.php --no-opening only ones with no stored opening
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = __DIR__;
if (!file_exists($root . '/config.php') && file_exists(dirname(__DIR__) . '/config.php')) {
    $root = dirname(__DIR__);
}
if (!file_exists($root . '/config.php')) {
    fwrite(STDERR, "config.php not found from " . __DIR__ . "\n"); exit(1);
}
require_once $root . '/config.php';
require_once $root . '/api/db.php';

$argvv     = $argv ?? [];
$driftOnly = in_array('--drift', $argvv, true);
$noOpening = in_array('--no-opening', $argvv, true);

function dec2($v, $cur = 'BHD') {
    $d = in_array(strtoupper((string)$cur), ['BHD','KWD','OMR','JOD','TND'], true) ? 3 : 2;
    return number_format((float)$v, $d);
}

/** Signed effect of one transaction on this account, converted where needed. */
function effectFor(array $t, int $aid): float {
    $amt = (float)$t['amount'];
    if ((int)$t['account_id'] === $aid) {
        if ($t['type'] === 'income')   return  $amt;
        if ($t['type'] === 'expense')  return -$amt;
        if ($t['type'] === 'transfer') return -$amt;
    }
    if (!empty($t['to_account_id']) && (int)$t['to_account_id'] === $aid
        && $t['type'] === 'transfer') {
        return function_exists('toAccountAmount')
             ? toAccountAmount($amt, $t['currency'] ?? 'BHD', $aid) : $amt;
    }
    return 0.0;
}

$hasOpeningCol = false;
foreach (db()->query("SHOW COLUMNS FROM accounts")->fetchAll() as $c) {
    if (($c['Field'] ?? '') === 'opening_balance') { $hasOpeningCol = true; break; }
}
if (!$hasOpeningCol) {
    echo "accounts.opening_balance does not exist — run cc_baseline.php first.\n"
       . "Without it this check is circular and proves nothing.\n";
    exit(1);
}
if (!function_exists('toAccountAmount')) {
    echo "NOTE: toAccountAmount() not found — run fx_patch.php first, or\n"
       . "      cross-currency transfers will be miscounted here too.\n\n";
}

$accounts = db()->query(
    "SELECT id, name, currency, balance, opening_balance, is_credit_card
       FROM accounts WHERE is_active=1 ORDER BY name"
)->fetchAll();

$txns = db()->query(
    "SELECT id, txn_date, type, amount, currency, account_id, to_account_id
       FROM transactions ORDER BY txn_date ASC, id ASC"
)->fetchAll();

// Bucket transactions by the accounts they touch.
$byAcct = [];
foreach ($txns as $t) {
    if (!empty($t['account_id']))    $byAcct[(int)$t['account_id']][]    = $t;
    if (!empty($t['to_account_id']) && $t['type'] === 'transfer') {
        $byAcct[(int)$t['to_account_id']][] = $t;
    }
}

printf("%-34s %4s %14s %14s %14s %14s  %s\n",
    'account', 'cur', 'opening', 'movements', 'expected', 'balance', 'verdict');
echo str_repeat('-', 118) . "\n";

$drifted = []; $missing = []; $shown = 0;

foreach ($accounts as $a) {
    $aid = (int)$a['id'];
    $cur = $a['currency'] ?: 'BHD';

    $hasOpening = ($a['opening_balance'] !== null && $a['opening_balance'] !== '');
    if ($noOpening && $hasOpening) continue;
    if (!$hasOpening) $missing[] = $a['name'];

    $moves = 0.0;
    foreach ($byAcct[$aid] ?? [] as $t) $moves += effectFor($t, $aid);

    $opening  = $hasOpening ? (float)$a['opening_balance'] : 0.0;
    $expected = round($opening + $moves, 4);
    $balance  = round((float)$a['balance'], 4);
    $drift    = round($expected - $balance, 4);
    $bad      = $hasOpening && abs($drift) >= 0.005;

    if ($bad) $drifted[] = [$a['name'], $cur, $drift];
    if ($driftOnly && !$bad) continue;

    printf("%-34s %4s %14s %14s %14s %14s  %s\n",
        substr($a['name'], 0, 34), $cur,
        $hasOpening ? dec2($opening, $cur) : '—',
        dec2($moves, $cur), dec2($expected, $cur), dec2($balance, $cur),
        !$hasOpening ? 'no opening stored' : ($bad ? 'DRIFT ' . dec2($drift, $cur) : 'ok'));
    $shown++;
}

echo str_repeat('-', 118) . "\n";
printf("%d account(s) checked, %d shown, %d drifted, %d without a stored opening\n",
    count($accounts), $shown, count($drifted), count($missing));

if ($drifted) {
    echo "\nDRIFTED\n";
    foreach ($drifted as $d) printf("  %-34s %s %s\n", $d[0], $d[1], dec2($d[2], $d[1]));
    echo "\nA positive drift means the history expects more than the balance holds —\n"
       . "usually a transaction that was deleted without returning the money.\n"
       . "A negative drift means the balance holds more than the history explains.\n"
       . "Correct whichever figure is actually wrong. Do not paper over it by\n"
       . "rewriting the opening; that is the behaviour we just removed.\n";
} else {
    echo "\nEvery account reconciles. Balance, opening and history all agree.\n";
}
