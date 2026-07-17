<?php
$configPaths = [
    __DIR__ . '/config.php',
    __DIR__ . '/../config.php',
    dirname(__DIR__) . '/config.php',
];
foreach ($configPaths as $path) {
    if (file_exists($path)) { require_once $path; break; }
}

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

// Simple - same for ALL accounts, no special CC logic
function updateAccountBalance(int $accountId, float $delta): void {
    try {
        db()->prepare("UPDATE accounts SET balance=balance+?, updated_at=NOW() WHERE id=?")
           ->execute([$delta, $accountId]);
    } catch(Exception $e){}
}

/**
 * Calculate CC payable/outstanding for DISPLAY only.
 * 
 * The balance field is the source of truth (updated by every transaction).
 * We split the total balance into payable vs outstanding based on bill date.
 * 
 * Payable = spending that happened ON or BEFORE last bill date
 * Outstanding = spending that happened AFTER last bill date
 * Both calculated from transactions only. Opening amounts stored in
 * payable_balance/outstanding_balance are used as seed for txn-less cards.
 */
function getCCBalances(array $account): array {
    if (!$account['is_credit_card'] || !$account['bill_date']) {
        $total = abs((float)$account['balance']);
        return ['payable'=>$total,'outstanding'=>0,'total'=>$total];
    }

    $billDay = (int)$account['bill_date'];
    $today   = new DateTime();
    $d       = (int)$today->format('j');

    if ($d >= $billDay) {
        $lastBillDate = new DateTime($today->format('Y-m-').str_pad($billDay,2,'0',STR_PAD_LEFT));
    } else {
        $lastBillDate = new DateTime($today->format('Y-m-').str_pad($billDay,2,'0',STR_PAD_LEFT));
        $lastBillDate->modify('-1 month');
    }
    $lastBillStr = $lastBillDate->format('Y-m-d');

    try {
        // Check if any transactions exist
        $check = db()->prepare("SELECT COUNT(*) FROM transactions WHERE account_id=?");
        $check->execute([$account['id']]);
        $hasTxns = (int)$check->fetchColumn() > 0;

        if (!$hasTxns) {
            // No transactions yet - use stored opening balances
            $p = (float)$account['payable_balance'];
            $o = (float)$account['outstanding_balance'];
            if ($p == 0 && $o == 0) {
                // Only balance field set - show all as payable
                $p = abs((float)$account['balance']);
            }
            return ['payable'=>$p,'outstanding'=>$o,'total'=>$p+$o,'bill_date'=>$lastBillStr];
        }

        // Has transactions - calculate split from transactions
        // expense + transfer-out = more debt; income + transfer-in = payment = less debt
        $aid = $account['id'];
        $st = db()->prepare(
            "SELECT COALESCE(SUM(
                CASE
                  WHEN type='expense'  AND account_id=?      THEN amount
                  WHEN type='income'   AND account_id=?      THEN -amount
                  WHEN type='transfer' AND account_id=?      THEN amount
                  WHEN type='transfer' AND to_account_id=?   THEN -amount
                  ELSE 0
                END
             ),0) as total
             FROM transactions
             WHERE (account_id=? OR to_account_id=?) AND DATE(txn_date) <= ?"
        );
        $st->execute([$aid,$aid,$aid,$aid,$aid,$aid,$lastBillStr]);
        $txnPayable = max(0, (float)$st->fetchColumn());

        // Outstanding = opening outstanding + transactions after bill date
        $st2 = db()->prepare(
            "SELECT COALESCE(SUM(
                CASE
                  WHEN type='expense'  AND account_id=?      THEN amount
                  WHEN type='income'   AND account_id=?      THEN -amount
                  WHEN type='transfer' AND account_id=?      THEN amount
                  WHEN type='transfer' AND to_account_id=?   THEN -amount
                  ELSE 0
                END
             ),0) as total
             FROM transactions
             WHERE (account_id=? OR to_account_id=?) AND DATE(txn_date) > ?"
        );
        $st2->execute([$aid,$aid,$aid,$aid,$aid,$aid,$lastBillStr]);
        $txnOutst = max(0, (float)$st2->fetchColumn());

        $openingPayable = (float)$account['payable_balance'];
        $openingOutst   = (float)$account['outstanding_balance'];

        $payable     = $openingPayable + $txnPayable;
        $outstanding = $openingOutst   + $txnOutst;

        return [
            'payable'     => $payable,
            'outstanding' => $outstanding,
            'total'       => $payable + $outstanding,
            'bill_date'   => $lastBillStr,
        ];
    } catch(Exception $e) {
        $p = (float)$account['payable_balance'];
        $o = (float)$account['outstanding_balance'];
        return ['payable'=>$p,'outstanding'=>$o,'total'=>$p+$o];
    }
}


if (defined('TIMEZONE')) date_default_timezone_set(TIMEZONE);
else date_default_timezone_set('Asia/Bahrain');
