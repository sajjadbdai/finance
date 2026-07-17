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
    if (!$account['is_credit_card']) {
        $total = abs((float)$account['balance']);
        return ['payable'=>$total,'outstanding'=>0,'total'=>$total];
    }

    $billDay = (int)($account['bill_date'] ?? 0);
    $aid     = (int)$account['id'];

    // SIMPLE: use the actual balance field as source of truth
    // balance field is updated correctly by every transaction (debit/credit)
    $currentBalance = (float)$account['balance']; // e.g. -5 means 5 BHD owed
    $totalOwed      = abs(min(0, $currentBalance)); // 0 if positive, abs if negative

    if ($totalOwed == 0) {
        return ['payable'=>0,'outstanding'=>0,'total'=>0];
    }

    if (!$billDay) {
        return ['payable'=>$totalOwed,'outstanding'=>0,'total'=>$totalOwed];
    }

    // Split into payable vs outstanding based on bill date
    // Outstanding = new charges AFTER last bill date
    $today = new DateTime();
    $d     = (int)$today->format('j');
    if ($d >= $billDay) {
        $lastBillDate = new DateTime($today->format('Y-m-').str_pad($billDay,2,'0',STR_PAD_LEFT));
    } else {
        $lastBillDate = new DateTime($today->format('Y-m-').str_pad($billDay,2,'0',STR_PAD_LEFT));
        $lastBillDate->modify('-1 month');
    }
    $lastBillStr = $lastBillDate->format('Y-m-d');

    try {
        // New charges (expenses + transfer-out) AFTER bill date = outstanding
        $st = db()->prepare(
            "SELECT COALESCE(SUM(
                CASE
                  WHEN type='expense'  AND account_id=?     THEN  amount
                  WHEN type='transfer' AND account_id=?     THEN  amount
                  WHEN type='income'   AND account_id=?     THEN -amount
                  WHEN type='transfer' AND to_account_id=?  THEN -amount
                  ELSE 0
                END
             ),0) FROM transactions
             WHERE (account_id=? OR to_account_id=?) AND DATE(txn_date) > ?"
        );
        $st->execute([$aid,$aid,$aid,$aid,$aid,$aid,$lastBillStr]);
        $netAfterBill = (float)$st->fetchColumn();

        $outstanding = max(0, min($netAfterBill, $totalOwed));
        $payable     = max(0, $totalOwed - $outstanding);

        return [
            'payable'     => round($payable, 4),
            'outstanding' => round($outstanding, 4),
            'total'       => round($totalOwed, 4),
            'bill_date'   => $lastBillStr,
        ];
    } catch(Exception $e) {
        return ['payable'=>$totalOwed,'outstanding'=>0,'total'=>$totalOwed];
    }
}


