<?php
/**
 * Credit Card Bill Date Processor
 * Run daily via cPanel Cron: 0 7 * * * php /path/finance/cron/cc_bill_processor.php
 * 
 * On bill date: moves outstanding_balance to payable_balance
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/db.php';

$today = (int)date('j'); // day of month

$cards = db()->query(
    "SELECT * FROM accounts WHERE is_credit_card=1 AND is_active=1 AND outstanding_balance > 0"
)->fetchAll();

foreach ($cards as $card) {
    if ((int)$card['bill_date'] === $today) {
        $outst      = (float)$card['outstanding_balance'];
        $newPayable = (float)$card['payable_balance'] + $outst;
        $newBalance = -$newPayable;

        db()->prepare(
            "UPDATE accounts SET payable_balance=?, outstanding_balance=0, balance=?, updated_at=NOW() WHERE id=?"
        )->execute([$newPayable, $newBalance, $card['id']]);

        echo date('Y-m-d H:i:s') . " | Bill date reached for: {$card['name']} | Moved {$outst} {$card['currency']} to payable\n";
    }
}
echo "Done. Processed " . count($cards) . " credit card(s).\n";
