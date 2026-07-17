<?php
// Run via cPanel Cron: 0 6 * * * php /home/USERNAME/public_html/finance/cron/scheduled_runner.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/db.php';

$due = db()->query("SELECT s.*, a.currency as acc_cur FROM scheduled_payments s LEFT JOIN accounts a ON a.id=s.account_id WHERE s.is_active=1 AND s.next_run <= CURDATE() AND (s.end_date IS NULL OR s.next_run <= s.end_date) AND (s.occurrences IS NULL OR s.occurrences_done < s.occurrences)")->fetchAll();

foreach ($due as $s) {
    $bhd = toBHD((float)$s['amount'], $s['currency']);
    db()->prepare("INSERT INTO transactions (txn_date,type,amount,currency,amount_bhd,account_id,to_account_id,category,subcategory,note,source) VALUES (NOW(),?,?,?,?,?,?,?,?,?,'schedule')")
    ->execute([$s['type'],$s['amount'],$s['currency'],$bhd,$s['account_id'],$s['to_account_id']??null,$s['category'],$s['subcategory'],'[Auto] '.$s['note']]);

    if ($s['type']==='expense') db()->prepare("UPDATE accounts SET balance=balance-? WHERE id=?")->execute([$s['amount'],$s['account_id']]);
    elseif ($s['type']==='income') db()->prepare("UPDATE accounts SET balance=balance+? WHERE id=?")->execute([$s['amount'],$s['account_id']]);
    elseif ($s['type']==='transfer' && $s['to_account_id']) {
        db()->prepare("UPDATE accounts SET balance=balance-? WHERE id=?")->execute([$s['amount'],$s['account_id']]);
        db()->prepare("UPDATE accounts SET balance=balance+? WHERE id=?")->execute([$s['amount'],$s['to_account_id']]);
    }

    $next = date('Y-m-d', strtotime($s['next_run'] . ' +1 ' . $s['frequency']));
    $done = $s['occurrences_done'] + 1;
    $active = 1;
    if ($s['occurrences'] && $done >= $s['occurrences']) $active = 0;
    if ($s['end_date'] && $next > $s['end_date']) $active = 0;

    db()->prepare("UPDATE scheduled_payments SET next_run=?,last_run=CURDATE(),occurrences_done=?,is_active=? WHERE id=?")
    ->execute([$next,$done,$active,$s['id']]);

    echo date('Y-m-d H:i:s') . " | Executed: {$s['name']} | {$s['amount']} {$s['currency']}\n";
}
echo "Done. " . count($due) . " payment(s) processed.\n";
