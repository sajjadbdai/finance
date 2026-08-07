<?php
/**
 * account_trace.php — walk one account from its first transaction to today
 *                     and test the things that CAN actually be wrong.
 *
 * WHAT THIS IS NOT
 *   It is not "opening = balance - transactions". That is an identity: it
 *   always balances, on every account, even a corrupt one. It proves nothing.
 *
 * WHAT IT ACTUALLY CHECKS
 *   1. DOUBLE-ENTRY PAIRING
 *      Every transfer must name a counterparty account that exists. An
 *      orphaned leg means one side of the pair moved and the other did not.
 *
 *   2. LEDGER BALANCE
 *      If ledger_entries exists, each transaction's entries must sum to
 *      zero. A non-zero sum is a half-posted transaction.
 *
 *   3. CURRENCY CONVERSION
 *      amount_bhd must equal amount x a plausible rate. For a BHD row the
 *      rate must be 1.0. For other currencies the implied rate is compared
 *      against the median for that currency across this account; an outlier
 *      means amount_bhd was written without converting — the known past bug.
 *
 *   4. LEDGER vs STORED BALANCE
 *      The running balance is rebuilt from the earliest transaction. Where
 *      it ends is compared against accounts.balance.
 *
 *   Note on check 4: with no stored opening_balance the start point is
 *   inferred, so the endpoint matches by construction. It becomes a real
 *   test only once cc_baseline.php has written a fixed opening — that is
 *   the entire point of storing one.
 *
 * USAGE — upload to the SITE ROOT or one directory below it:
 *   php account_trace.php "Brac Bank"
 *   php account_trace.php 12
 *   php account_trace.php "Brac Bank" --checks-only
 *   php account_trace.php "Brac Bank" --from=2026-06-01
 *
 * Read-only. Writes nothing.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = __DIR__;
if (!file_exists($root . '/config.php') && file_exists(dirname(__DIR__) . '/config.php')) {
    $root = dirname(__DIR__);
}
if (!file_exists($root . '/config.php')) {
    fwrite(STDERR, "config.php not found from " . __DIR__ . "\n");
    exit(1);
}
require_once $root . '/config.php';
require_once $root . '/api/db.php';

$argvv      = $argv ?? [];
$checksOnly = in_array('--checks-only', $argvv, true);
$from       = null;
$needle     = null;
foreach (array_slice($argvv, 1) as $arg) {
    if (strpos($arg, '--from=') === 0) { $from = substr($arg, 7); continue; }
    if (strpos($arg, '--') === 0) continue;
    if ($needle === null) $needle = $arg;
}
if ($needle === null) {
    echo "usage: php account_trace.php \"<account name or id>\" [--from=YYYY-MM-DD] [--checks-only]\n";
    exit(1);
}

function dec($v, $cur = 'BHD') {
    $d = in_array(strtoupper((string)$cur), ['BHD','KWD','OMR','JOD','TND'], true) ? 3 : 2;
    return number_format((float)$v, $d);
}
function tableExists($name) {
    try { db()->query("SELECT 1 FROM `$name` LIMIT 1"); return true; }
    catch (Exception $e) { return false; }
}
function colsOf($name) {
    $out = [];
    foreach (db()->query("SHOW COLUMNS FROM `$name`")->fetchAll() as $c) $out[] = $c['Field'];
    return $out;
}

// ---- resolve the account ---------------------------------------------
if (ctype_digit((string)$needle)) {
    $st = db()->prepare("SELECT * FROM accounts WHERE id=?");
    $st->execute([(int)$needle]);
} else {
    $st = db()->prepare("SELECT * FROM accounts WHERE name LIKE ? ORDER BY LENGTH(name) LIMIT 5");
    $st->execute(['%' . $needle . '%']);
}
$matches = $st->fetchAll();
if (!$matches) { echo "No account matched \"$needle\".\n"; exit(1); }
if (count($matches) > 1) {
    echo "Several accounts matched — be more specific or use the id:\n";
    foreach ($matches as $m) printf("  %4d  %s\n", $m['id'], $m['name']);
    exit(1);
}
$a   = $matches[0];
$aid = (int)$a['id'];
$cur = $a['currency'] ?: 'BHD';

echo "\n" . str_repeat('=', 96) . "\n";
printf("%s  (id %d, %s)%s\n", $a['name'], $aid, $cur,
    !empty($a['is_credit_card']) ? '  [credit card]' : '');
echo str_repeat('=', 96) . "\n";

// ---- pull the history -------------------------------------------------
$sql = "SELECT t.id, t.txn_date, t.type, t.amount, t.currency, t.amount_bhd,
               t.account_id, t.to_account_id, t.category, t.note,
               fa.name AS from_name, ta.name AS ta_name
          FROM transactions t
          LEFT JOIN accounts fa ON fa.id = t.account_id
          LEFT JOIN accounts ta ON ta.id = t.to_account_id
         WHERE (t.account_id=? OR t.to_account_id=?)"
     . ($from ? " AND t.txn_date >= " . db()->quote($from) : "")
     . " ORDER BY t.txn_date ASC, t.id ASC";
$q = db()->prepare($sql);
$q->execute([$aid, $aid]);
$txns = $q->fetchAll();

if (!$txns) { echo "No transactions.\n"; exit(0); }

// ---- ledger_entries, if present --------------------------------------
$ledgerSums = []; $ledgerNote = '';
if (tableExists('ledger_entries')) {
    $lc     = colsOf('ledger_entries');
    $txnCol = null;
    foreach (['txn_id','transaction_id','tx_id'] as $c) if (in_array($c, $lc, true)) { $txnCol = $c; break; }
    $dcPair = null;
    foreach ([['debit','credit'], ['debit_bhd','credit_bhd'], ['dr','cr'],
              ['debit_amount','credit_amount']] as $pair) {
        if (in_array($pair[0], $lc, true) && in_array($pair[1], $lc, true)) { $dcPair = $pair; break; }
    }
    $amtCol = in_array('amount', $lc, true) ? 'amount' : (in_array('amount_bhd', $lc, true) ? 'amount_bhd' : null);

    if ($txnCol && ($dcPair || $amtCol)) {
        $expr = $dcPair
            ? "SUM(COALESCE(`{$dcPair[0]}`,0)-COALESCE(`{$dcPair[1]}`,0))"
            : "SUM(COALESCE(`$amtCol`,0))";
        foreach (db()->query("SELECT `$txnCol` AS tid, $expr AS s, COUNT(*) AS n
                                FROM ledger_entries GROUP BY `$txnCol`")->fetchAll() as $r) {
            $ledgerSums[(int)$r['tid']] = ['sum' => (float)$r['s'], 'n' => (int)$r['n']];
        }
        $ledgerNote = "ledger_entries: using `$txnCol` with "
                    . ($dcPair ? "`{$dcPair[0]}` / `{$dcPair[1]}`" : "`$amtCol`");
    } else {
        $ledgerNote = "ledger_entries present but its columns were not recognised ("
                    . implode(', ', $lc) . ") — skipping check 2";
    }
} else {
    $ledgerNote = "ledger_entries table not found — skipping check 2";
}

// ---- implied FX rates, for check 3 -----------------------------------
$ratesBy = [];
foreach ($txns as $t) {
    $amt = (float)$t['amount'];
    if (abs($amt) < 0.0000001 || $t['amount_bhd'] === null) continue;
    $ratesBy[strtoupper($t['currency'] ?: $cur)][] = (float)$t['amount_bhd'] / $amt;
}
$median = [];
foreach ($ratesBy as $c => $list) {
    sort($list);
    $median[$c] = $list[intdiv(count($list), 2)];
}

// ---- walk -------------------------------------------------------------
$totalDelta = 0.0;
foreach ($txns as $t) {
    $amt = (float)$t['amount']; $d = 0.0;
    if ((int)$t['account_id'] === $aid) {
        if ($t['type'] === 'expense' || $t['type'] === 'transfer') $d -= $amt;
        elseif ($t['type'] === 'income') $d += $amt;
    }
    if ((int)$t['to_account_id'] === $aid && $t['type'] === 'transfer') $d += $amt;
    $totalDelta += $d;
}
$stored  = (float)$a['balance'];
$openCol = (array_key_exists('opening_balance', $a) && $a['opening_balance'] !== null
            && $a['opening_balance'] !== '') ? (float)$a['opening_balance'] : null;
$opening = $openCol !== null ? $openCol : round($stored - $totalDelta, 4);

printf("stored balance %s   transactions %s   opening %s  (%s)\n\n",
    dec($stored, $cur), dec($totalDelta, $cur), dec($opening, $cur),
    $openCol !== null ? 'stored column' : 'inferred — see note in header');

$problems = [];
$running  = $opening;
$rows     = [];

foreach ($txns as $t) {
    $tid  = (int)$t['id'];
    $amt  = (float)$t['amount'];
    $d    = 0.0;
    $side = '';

    if ((int)$t['account_id'] === $aid) {
        if ($t['type'] === 'expense')      { $d -= $amt; $side = 'expense'; }
        elseif ($t['type'] === 'transfer') { $d -= $amt; $side = 'transfer out'; }
        elseif ($t['type'] === 'income')   { $d += $amt; $side = 'income'; }
    }
    if ((int)$t['to_account_id'] === $aid && $t['type'] === 'transfer') {
        $d += $amt; $side = 'transfer in';
    }
    $running += $d;

    $flags = [];

    // 1. double-entry pairing
    if ($t['type'] === 'transfer') {
        if (empty($t['to_account_id'])) {
            $flags[] = 'transfer with no counterparty';
        } elseif ($t['ta_name'] === null) {
            $flags[] = 'counterparty account #' . $t['to_account_id'] . ' does not exist';
        }
        if (empty($t['account_id']) || $t['from_name'] === null) {
            $flags[] = 'source account missing';
        }
    }

    // 2. ledger balance
    if ($ledgerSums) {
        if (!isset($ledgerSums[$tid])) {
            $flags[] = 'no ledger entries';
        } elseif (abs($ledgerSums[$tid]['sum']) > 0.005) {
            $flags[] = 'ledger entries sum to ' . round($ledgerSums[$tid]['sum'], 4) . ', not 0';
        } elseif ($ledgerSums[$tid]['n'] < 2) {
            $flags[] = 'only ' . $ledgerSums[$tid]['n'] . ' ledger entry';
        }
    }

    // 3. currency conversion
    $tc = strtoupper($t['currency'] ?: $cur);
    if ($t['amount_bhd'] === null) {
        $flags[] = 'amount_bhd is NULL';
    } elseif (abs($amt) > 0.0000001) {
        $rate = (float)$t['amount_bhd'] / $amt;
        if ($tc === 'BHD') {
            if (abs($rate - 1.0) > 0.001) $flags[] = 'BHD row but amount_bhd/amount = ' . round($rate, 4);
        } elseif (isset($median[$tc]) && abs($median[$tc]) > 0.0000001) {
            $dev = abs($rate - $median[$tc]) / abs($median[$tc]);
            if ($dev > 0.20) {
                $flags[] = sprintf('rate %.6f vs usual %.6f for %s', $rate, $median[$tc], $tc);
            }
        }
    }

    if ($flags) $problems[] = [$t['txn_date'], $tid, implode('; ', $flags)];

    $other = ((int)$t['account_id'] === $aid) ? ($t['ta_name'] ?? '') : ($t['from_name'] ?? '');
    $rows[] = [$t['txn_date'], $tid, $side, $d, $running, $other,
               trim(($t['category'] ?? '') . ($t['note'] ? ' / ' . $t['note'] : '')),
               $flags ? '<<' : ''];
}

// ---- checks -----------------------------------------------------------
echo "INTEGRITY CHECKS\n" . str_repeat('-', 96) . "\n";
echo "  $ledgerNote\n";
$closing = round($running, 4);
$diff    = round($stored - $closing, 4);
printf("  walk ends at %s, stored balance %s -> %s\n",
    dec($closing, $cur), dec($stored, $cur),
    abs($diff) < 0.005 ? 'match' : 'DIFFERS BY ' . dec($diff, $cur));
if ($openCol === null) {
    echo "  (that comparison is circular until an opening_balance is stored)\n";
}
printf("  %d transaction(s) examined, %d flagged\n", count($txns), count($problems));

if ($problems) {
    echo "\n  FLAGGED\n";
    foreach ($problems as $p) printf("    %s  #%-6d %s\n", $p[0], $p[1], $p[2]);
} else {
    echo "  no pairing, ledger or conversion problems found\n";
}

if ($checksOnly) { echo "\n"; exit(0); }

// ---- the walk ---------------------------------------------------------
echo "\nWALK\n" . str_repeat('-', 96) . "\n";
printf("%-11s %-7s %-13s %13s %14s  %-18s %s\n",
    'date', 'id', 'side', 'delta', 'running', 'other account', 'category / note');
echo str_repeat('-', 96) . "\n";
printf("%-11s %-7s %-13s %13s %14s\n", '', '', 'opening', '', dec($opening, $cur));
foreach ($rows as $r) {
    printf("%-11s %-7d %-13s %13s %14s  %-18s %s %s\n",
        substr($r[0], 0, 10), $r[1], $r[2], dec($r[3], $cur), dec($r[4], $cur),
        substr($r[5], 0, 18), substr($r[6], 0, 26), $r[7]);
}
echo str_repeat('-', 96) . "\n";
printf("%-11s %-7s %-13s %13s %14s\n", '', '', 'closing', '', dec($running, $cur));
echo "\n";
