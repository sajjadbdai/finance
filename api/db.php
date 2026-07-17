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

function updateAccountBalance(int $accountId, float $delta): void {
    try {
        db()->prepare("UPDATE accounts SET balance=balance+?, updated_at=NOW() WHERE id=?")
           ->execute([$delta, $accountId]);
    } catch(Exception $e){}
}

function getCCBalancesBot(array $account): array {
    $billDay = (int)($account['bill_date'] ?? 0);
    $aid     = (int)$account['id'];
    $currentBalance = (float)$account['balance'];
    $totalOwed      = abs(min(0, $currentBalance));

    if ($totalOwed == 0 || !$billDay) {
        return ['payable'=>$totalOwed,'outstanding'=>0,'total'=>$totalOwed];
    }

    $today = new DateTime();
    $d     = (int)$today->format('j');
    if ($d >= $billDay) {
        $lastBill = new DateTime($today->format('Y-m-').str_pad($billDay,2,'0',STR_PAD_LEFT));
    } else {
        $lastBill = new DateTime($today->format('Y-m-').str_pad($billDay,2,'0',STR_PAD_LEFT));
        $lastBill->modify('-1 month');
    }
    $lbs = $lastBill->format('Y-m-d');

    try {
        $st = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='expense' AND account_id=? THEN amount WHEN type='transfer' AND account_id=? THEN amount WHEN type='income' AND account_id=? THEN -amount WHEN type='transfer' AND to_account_id=? THEN -amount ELSE 0 END),0) FROM transactions WHERE (account_id=? OR to_account_id=?) AND DATE(txn_date)>?");
        $st->execute([$aid,$aid,$aid,$aid,$aid,$aid,$lbs]);
        $netAfter    = (float)$st->fetchColumn();
        $outstanding = max(0, min($netAfter, $totalOwed));
        $payable     = max(0, $totalOwed - $outstanding);
        return ['payable'=>round($payable,4),'outstanding'=>round($outstanding,4),'total'=>round($totalOwed,4)];
    } catch(Exception $e) {
        return ['payable'=>$totalOwed,'outstanding'=>0,'total'=>$totalOwed];
    }
}
