<?php
/**
 * cc_lib.php — Credit-card statement cycle logic for Sajjad Finance
 * ---------------------------------------------------------------
 * v2 — now includes the account's OPENING BALANCE in the cycle.
 *      (v1 ignored it, which produced nonsense on any card whose
 *       history starts with a carried-forward balance.)
 *
 * Derives Balance Payable + Outstanding Balance from the TRANSACTION
 * LEDGER instead of reading the stored accounts.payable_balance /
 * accounts.outstanding_balance columns.
 *
 * Why derived: stored columns drift the moment any path forgets to
 * update them (delete, edit, bot, scheduled runner, statement import).
 * Derived numbers are self-healing and auditable.
 *
 * THE RULE THIS IMPLEMENTS
 *   Statement (bill) day = accounts.bill_date, e.g. 15 or 21.
 *   Opening balance + everything dated ON OR BEFORE the last statement
 *   date is BILLED. Everything dated AFTER it is UNBILLED (this cycle).
 *
 *   statement balance = opening + billed charges - billed payments
 *   payable     = statement balance - payments made after the
 *                 statement date                    (floored at 0)
 *   outstanding = unbilled charges - any payment surplus left after
 *                 payable reached 0                 (floored at 0)
 *
 *   i.e. a payment ALWAYS clears the statemented amount first, and
 *   only the excess touches this cycle's new spending.
 *
 * Drop in /dashboard/ next to db.php. Include with:
 *     require_once __DIR__ . '/cc_lib.php';
 *
 * Read-only. Writes nothing. Safe to add without touching any other file.
 */

if (!defined('CC_EPS')) define('CC_EPS', 0.0005);

/** Round to the currency's precision and kill float dust. */
if (!function_exists('ccR')) {
    function ccR($v, $cur = 'BHD') {
        $d = in_array(strtoupper((string)$cur), ['BHD','KWD','OMR','JOD','TND'], true) ? 3 : 2;
        $v = round((float)$v, $d);
        return (abs($v) < CC_EPS) ? 0.0 : $v;
    }
}

/** Format money. Uses the app's money() helper when it exists. */
if (!function_exists('ccMoney')) {
    function ccMoney($v, $cur = 'BHD') {
        if (function_exists('money')) return money($v, $cur);
        $d = in_array(strtoupper((string)$cur), ['BHD','KWD','OMR','JOD','TND'], true) ? 3 : 2;
        return number_format((float)$v, $d);
    }
}

/** Strip any time component so DATE and DATETIME columns compare alike. */
if (!function_exists('ccDay')) {
    function ccDay($d) { return substr((string)$d, 0, 10); }
}

/**
 * Normalise accounts.bill_date into a day-of-month 1..31.
 * Accepts an int (15), a numeric string ("15") or a full date.
 * Returns 0 when the card has no bill day configured.
 */
if (!function_exists('ccBillDay')) {
    function ccBillDay($raw) {
        if ($raw === null || $raw === '' || $raw === '0000-00-00') return 0;
        if (is_numeric($raw)) {
            $d = (int)$raw;
            return ($d >= 1 && $d <= 31) ? $d : 0;
        }
        $ts = strtotime((string)$raw);
        return $ts ? (int)date('j', $ts) : 0;
    }
}

/**
 * The date of the most recent statement on or before $asOf.
 * Bill day 15, today 06 Aug -> 2026-07-15
 * Bill day 15, today 16 Aug -> 2026-08-15
 * Bill day 31, February     -> clamped to the 28th/29th
 * Returns null when the card has no bill day.
 */
if (!function_exists('ccLastStatementDate')) {
    function ccLastStatementDate($billDay, $asOf = null) {
        $billDay = (int)$billDay;
        if ($billDay < 1) return null;
        $asOf = $asOf ?: date('Y-m-d');
        $ts = strtotime($asOf);

        $y = (int)date('Y', $ts);
        $m = (int)date('n', $ts);
        $d = (int)date('j', $ts);

        $dim = (int)date('t', mktime(0, 0, 0, $m, 1, $y));

        // This month's statement has not been cut yet -> step back a month.
        if ($d < min($billDay, $dim)) {
            $m--;
            if ($m < 1) { $m = 12; $y--; }
        }
        $dim = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        return sprintf('%04d-%02d-%02d', $y, $m, min($billDay, $dim));
    }
}

/** The next statement date after the last one — when this cycle gets billed. */
if (!function_exists('ccNextStatementDate')) {
    function ccNextStatementDate($stmtDate) {
        if (!$stmtDate) return null;
        $ts = strtotime($stmtDate);
        $y  = (int)date('Y', $ts);
        $m  = (int)date('n', $ts) + 1;
        $bd = (int)date('j', $ts);
        if ($m > 12) { $m = 1; $y++; }
        $dim = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        return sprintf('%04d-%02d-%02d', $y, $m, min($bd, $dim));
    }
}

/** The payment due date that follows a given statement date. */
if (!function_exists('ccDueDateFor')) {
    function ccDueDateFor($stmtDate, $dueDay) {
        $dueDay = (int)$dueDay;
        if (!$stmtDate || $dueDay < 1) return null;
        $ts = strtotime($stmtDate);
        $y  = (int)date('Y', $ts);
        $m  = (int)date('n', $ts);
        $sd = (int)date('j', $ts);
        // A due day at or before the bill day lands in the following month.
        if ($dueDay <= $sd) { $m++; if ($m > 12) { $m = 1; $y++; } }
        $dim = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        return sprintf('%04d-%02d-%02d', $y, $m, min($dueDay, $dim));
    }
}

/**
 * Signed effect of one transaction row on this account's balance,
 * in the account's own currency. Negative = charge, positive = payment.
 * Mirrors the walk used by balance_audit.php.
 */
if (!function_exists('ccDelta')) {
    function ccDelta(array $t, $accountId) {
        $accountId = (int)$accountId;
        $amt   = (float)$t['amount'];
        $delta = 0.0;
        if ((int)$t['account_id'] === $accountId) {
            if ($t['type'] === 'expense' || $t['type'] === 'transfer') $delta -= $amt;
            elseif ($t['type'] === 'income')                           $delta += $amt;
        }
        if ((int)$t['to_account_id'] === $accountId && $t['type'] === 'transfer') {
            $delta += (function_exists('toAccountAmount') ? toAccountAmount($amt, $t['currency'] ?? 'BHD', $accountId) : $amt);
        }
        return $delta;
    }
}

/**
 * Derive the full credit-card picture for one account.
 *
 * @param int         $accountId
 * @param string|null $asOf  Y-m-d, defaults to today. Lets you re-run history.
 * @return array
 */
if (!function_exists('ccDerive')) {
    function ccDerive($accountId, $asOf = null) {
        $accountId = (int)$accountId;
        $asOf = ccDay($asOf ?: date('Y-m-d'));

        $out = ['ok' => false, 'error' => '', 'notes' => [], 'rows' => []];

        $st = db()->prepare("SELECT * FROM accounts WHERE id=?");
        $st->execute([$accountId]);
        $a = $st->fetch();
        if (!$a) { $out['error'] = 'Account not found'; return $out; }

        $cur     = $a['currency'] ?: 'BHD';
        $billDay = ccBillDay($a['bill_date'] ?? null);
        $dueDay  = isset($a['due_date']) ? ccBillDay($a['due_date']) : 0;
        $stmt    = ccLastStatementDate($billDay, $asOf);

        // Pull the whole history once — needed both for the plug figure
        // below and for the cycle walk.
        $q = db()->prepare(
            "SELECT id, txn_date, type, amount, currency, account_id, to_account_id,
                    category, note
               FROM transactions
              WHERE account_id=? OR to_account_id=?
              ORDER BY txn_date ASC, id ASC"
        );
        $q->execute([$accountId, $accountId]);
        $allTxns = $q->fetchAll();

        // ---- Opening balance ----------------------------------------
        // Prefer a real stored column. Otherwise plug it from the stored
        // balance, which is what account_detail.php effectively shows.
        $totalDelta = 0.0;
        foreach ($allTxns as $t) $totalDelta += ccDelta($t, $accountId);

        if (array_key_exists('opening_balance', $a) && $a['opening_balance'] !== null
            && $a['opening_balance'] !== '') {
            $opening    = (float)$a['opening_balance'];
            $openingSrc = 'stored column';
        } else {
            $opening    = ccR((float)($a['balance'] ?? 0) - $totalDelta, $cur);
            $openingSrc = 'derived (stored balance - all transactions)';
            if (abs($opening) >= CC_EPS) {
                $out['notes'][] = 'Opening balance is inferred from the stored balance, '
                    . 'so it inherits any drift in that balance. Verify against a real statement.';
            }
        }

        // ---- Split the ledger at the statement date -------------------
        $billedCharge = 0.0; $billedPay = 0.0;
        $postCharge   = 0.0; $postPay   = 0.0;

        // The opening balance predates every transaction, so it is
        // always part of the statemented (billed) side.
        if     ($opening < 0) $billedCharge += -$opening;
        elseif ($opening > 0) $billedPay    +=  $opening;

        $running = $opening; $rows = [];

        foreach ($allTxns as $t) {
            $day = ccDay($t['txn_date']);
            if ($day > $asOf) continue;

            $delta = ccDelta($t, $accountId);
            if (abs($delta) < CC_EPS) continue;

            $isBilled = ($stmt !== null && $day <= $stmt);

            if ($delta < 0) {
                if ($isBilled) $billedCharge += -$delta; else $postCharge += -$delta;
            } else {
                if ($isBilled) $billedPay    +=  $delta; else $postPay   +=  $delta;
            }

            $running += $delta;
            $rows[] = [
                'id'       => $t['id'],
                'date'     => $day,
                'type'     => $t['type'],
                'category' => $t['category'] ?? '',
                'note'     => $t['note'] ?? '',
                'delta'    => ccR($delta, $cur),
                'kind'     => $delta < 0 ? 'charge' : 'payment',
                'billed'   => $isBilled,
                'running'  => ccR($running, $cur),
            ];
        }

        $billedCharge = ccR($billedCharge, $cur);
        $billedPay    = ccR($billedPay,    $cur);
        $postCharge   = ccR($postCharge,   $cur);
        $postPay      = ccR($postPay,      $cur);

        // What the bank printed on the last statement.
        $statementBalance = ccR($billedCharge - $billedPay, $cur);

        // ---- THE ALLOCATION ORDER: statement first, then this cycle ----
        $payable     = ccR(max(0, $statementBalance - $postPay), $cur);
        $surplus     = ccR(max(0, $postPay - $statementBalance), $cur);
        $outstanding = ccR(max(0, $postCharge - $surplus), $cur);
        $creditBal   = ccR(max(0, $surplus - $postCharge), $cur);

        $storedPayable     = isset($a['payable_balance'])     ? ccR($a['payable_balance'], $cur)     : null;
        $storedOutstanding = isset($a['outstanding_balance']) ? ccR($a['outstanding_balance'], $cur) : null;
        $storedBalance     = ccR($a['balance'] ?? 0, $cur);

        $out['ok']                 = true;
        $out['account']            = $a;
        $out['currency']           = $cur;
        $out['bill_day']           = $billDay;
        $out['due_day']            = $dueDay;
        $out['statement_date']     = $stmt;
        $out['next_statement']     = ccNextStatementDate($stmt);
        $out['due_date']           = ccDueDateFor($stmt, $dueDay);
        $out['opening_balance']    = ccR($opening, $cur);
        $out['opening_source']     = $openingSrc;
        $out['billed_charges']     = $billedCharge;
        $out['billed_payments']    = $billedPay;
        $out['statement_balance']  = $statementBalance;
        $out['unbilled_charges']   = $postCharge;
        $out['post_payments']      = $postPay;
        $out['surplus']            = $surplus;
        $out['payable']            = $payable;
        $out['outstanding']        = $outstanding;
        $out['credit_balance']     = $creditBal;
        $out['ledger_balance']     = ccR($running, $cur);
        $out['stored_payable']     = $storedPayable;
        $out['stored_outstanding'] = $storedOutstanding;
        $out['stored_balance']     = $storedBalance;
        $out['payable_diff']       = $storedPayable     === null ? null : ccR($storedPayable - $payable, $cur);
        $out['outstanding_diff']   = $storedOutstanding === null ? null : ccR($storedOutstanding - $outstanding, $cur);
        $out['balance_diff']       = ccR($storedBalance - $running, $cur);
        $out['rows']               = $rows;

        if ($billDay < 1) {
            $out['error'] = 'No bill date set on this card - everything is treated as unbilled.';
        }
        return $out;
    }
}

/** Convenience: derive every active credit card at once. */
if (!function_exists('ccDeriveAll')) {
    function ccDeriveAll($asOf = null) {
        $cards = db()->query(
            "SELECT id FROM accounts
              WHERE is_active=1 AND COALESCE(is_credit_card,0)=1
              ORDER BY name"
        )->fetchAll();
        $res = [];
        foreach ($cards as $c) $res[] = ccDerive((int)$c['id'], $asOf);
        return $res;
    }
}
