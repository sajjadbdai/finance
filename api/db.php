<?php
require_once __DIR__ . '/../config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function getRate(string $from, string $to): float {
    if ($from===$to) return 1.0;
    try {
        $st=db()->prepare("SELECT rate FROM exchange_rates WHERE from_cur=? AND to_cur=?");
        $st->execute([$from,$to]);
        $r=$st->fetchColumn();
        return $r ? (float)$r : 1.0;
    } catch(Exception $e){return 1.0;}
}

function toBHD(float $amount, string $currency): float {
    return $amount * getRate($currency,'BHD');
}

function getAccountByName(string $name): ?array {
    try {
        $st=db()->prepare("SELECT * FROM accounts WHERE name LIKE ? AND is_active=1 LIMIT 1");
        $st->execute(['%'.$name.'%']);
        return $st->fetch() ?: null;
    } catch(Exception $e){return null;}
}

/**
 * Convert an amount expressed in $fromCurrency into the currency of the
 * destination account, so a cross-currency transfer credits the right
 * figure. Same-currency transfers pass through unchanged.
 */
function toAccountAmount(float $amount, ?string $fromCurrency, int $toAccountId): float {
    if ($toAccountId <= 0) return $amount;
    $fromCurrency = $fromCurrency ?: 'BHD';
    try {
        $st = db()->prepare("SELECT currency FROM accounts WHERE id=?");
        $st->execute([$toAccountId]);
        $toCur = $st->fetchColumn();
    } catch (Exception $e) { return $amount; }
    if (!$toCur || strcasecmp((string)$toCur, $fromCurrency) === 0) return $amount;
    $unit = toBHD(1.0, (string)$toCur);
    if (!$unit || $unit <= 0) return $amount;
    return round(toBHD($amount, $fromCurrency) / $unit, 4);
}

function updateAccountBalance(int $accountId, float $delta): void {
    try {
        db()->prepare("UPDATE accounts SET balance=balance+?, updated_at=NOW() WHERE id=?")
           ->execute([$delta, $accountId]);
    } catch(Exception $e){}
}

function getCCBalancesBot(array $account): array {
    $billDay = (int)($account['bill_date'] ?? 0);
    $aid     = (int)$account['id'];

    // The stored balance stays the source of truth for the TOTAL owed.
    // Only the split between payable and outstanding is computed here.
    $currentBalance = (float)$account['balance'];   // negative = owed
    $totalOwed      = abs(min(0, $currentBalance));
    $creditBal      = max(0, $currentBalance);      // overpayment, if any

    if ($totalOwed == 0) {
        return ['payable'=>0,'outstanding'=>0,'credit'=>$creditBal,'total'=>0];
    }
    if (!$billDay) {
        return ['payable'=>$totalOwed,'outstanding'=>0,'credit'=>0,'total'=>$totalOwed];
    }

    // Last statement date. The day is clamped to the month length, so a card
    // billing on the 31st still resolves correctly in February, April, etc.
    $today = new DateTime('today');
    $y = (int)$today->format('Y');
    $m = (int)$today->format('n');
    $d = (int)$today->format('j');
    if ($d < min($billDay, (int)$today->format('t'))) {
        $m--; if ($m < 1) { $m = 12; $y--; }
    }
    $dim         = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
    $lastBillStr = sprintf('%04d-%02d-%02d', $y, $m, min($billDay, $dim));

    // Charges and payments since the statement, kept SEPARATE. Netting them
    // into one figure is what caused payments to eat the current cycle first.
    $unbilled = 0.0; $postPaid = 0.0;
    try {
        $st = db()->prepare(
            "SELECT
               COALESCE(SUM(CASE
                 WHEN type='expense'  AND account_id=?    THEN amount
                 WHEN type='transfer' AND account_id=?    THEN amount
                 ELSE 0 END), 0) AS charges,
               COALESCE(SUM(CASE
                 WHEN type='income'   AND account_id=?    THEN amount
                 WHEN type='transfer' AND to_account_id=? THEN amount
                 ELSE 0 END), 0) AS payments
             FROM transactions
             WHERE (account_id=? OR to_account_id=?) AND DATE(txn_date) > ?"
        );
        $st->execute([$aid, $aid, $aid, $aid, $aid, $aid, $lastBillStr]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $unbilled = (float)($r['charges']  ?? 0);
        $postPaid = (float)($r['payments'] ?? 0);
    } catch (Exception $e) {
        return ['payable'=>$totalOwed,'outstanding'=>0,'credit'=>0,'total'=>$totalOwed];
    }

    // The statement balance implied by the current balance.
    $stmtBal = $totalOwed - $unbilled + $postPaid;

    // A payment clears the statemented amount FIRST. Only the surplus
    // touches this cycle's new spending.
    $payable     = max(0, $stmtBal - $postPaid);
    $surplus     = max(0, $postPaid - $stmtBal);
    $outstanding = max(0, $unbilled - $surplus);

    // Guarantee the two parts still add up to the balance.
    $payable     = round($payable, 4);
    $outstanding = round($outstanding, 4);
    $drift       = round($totalOwed - ($payable + $outstanding), 4);
    if (abs($drift) >= 0.00005) $outstanding = round($outstanding + $drift, 4);

    return [
        'payable'     => $payable,
        'outstanding' => $outstanding,
        'credit'      => round($creditBal, 4),
        'total'       => round($totalOwed, 4),
        'bill_date'   => $lastBillStr,
    ];
}
