<?php
/**
 * Scheduled Payments Runner - FIXED VERSION
 * Cron: 0 6 * * * /usr/local/bin/php /home/sajjadbd/finance.sajjad.bd/cron/scheduled_runner.php
 * 
 * KEY FIX: Check last_run date to prevent running more than once per period
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/db.php';

$today     = date('Y-m-d');
$now       = date('Y-m-d H:i:s');
$processed = 0;
$skipped   = 0;

// Get all active scheduled payments due today
$schedules = db()->query("
    SELECT s.*, 
           a.name as from_acc_name, a.currency as from_currency,
           b.name as to_acc_name
    FROM scheduled_payments s
    LEFT JOIN accounts a ON a.id = s.account_id
    LEFT JOIN accounts b ON b.id = s.to_account_id
    WHERE s.is_active = 1 
      AND s.next_run <= '{$today}'
    ORDER BY s.next_run ASC
")->fetchAll();

foreach ($schedules as $s) {
    $sid = $s['id'];
    
    // ── KEY FIX: Check if already run today (or this period) ──────
    $lastRun   = $s['last_run'] ?? '';
    $freq      = strtolower($s['frequency'] ?? 'monthly');
    
    $alreadyRun = false;
    
    if ($freq === 'daily') {
        // Daily: check if already run TODAY
        $alreadyRun = ($lastRun === $today);
    } elseif ($freq === 'weekly') {
        // Weekly: check if run in last 7 days
        $alreadyRun = $lastRun && (strtotime($today) - strtotime($lastRun)) < 7 * 86400;
    } elseif ($freq === 'monthly') {
        // Monthly: check if run in same calendar month
        $alreadyRun = $lastRun && date('Y-m', strtotime($lastRun)) === date('Y-m', strtotime($today));
    } elseif ($freq === 'yearly') {
        // Yearly: check if run in same calendar year
        $alreadyRun = $lastRun && date('Y', strtotime($lastRun)) === date('Y', strtotime($today));
    }
    
    if ($alreadyRun) {
        echo "⏭ SKIP [{$freq}] #{$sid} {$s['name']} — already ran {$lastRun}\n";
        $skipped++;
        continue;
    }
    
    // ── Check occurrences limit ────────────────────────────────────
    $maxOcc  = (int)($s['occurrences'] ?? 0);
    $doneOcc = (int)($s['occurrences_done'] ?? 0);
    if ($maxOcc > 0 && $doneOcc >= $maxOcc) {
        // Completed - deactivate
        db()->prepare("UPDATE scheduled_payments SET is_active=0 WHERE id=?")->execute([$sid]);
        echo "✅ COMPLETED #{$sid} {$s['name']} ({$doneOcc}/{$maxOcc})\n";
        continue;
    }
    
    // ── Check end date ─────────────────────────────────────────────
    if (!empty($s['end_date']) && $today > $s['end_date']) {
        db()->prepare("UPDATE scheduled_payments SET is_active=0 WHERE id=?")->execute([$sid]);
        echo "✅ ENDED #{$sid} {$s['name']} (end_date: {$s['end_date']})\n";
        continue;
    }
    
    // ── Execute the payment ────────────────────────────────────────
    $amount   = (float)$s['amount'];
    $currency = $s['from_currency'] ?? 'BHD';
    $amtBHD   = toBHD($amount, $currency);
    $note     = '[Auto] ' . $s['name'];
    $type     = strtolower($s['type'] ?? 'expense');
    
    try {
        // Insert transaction
        db()->prepare("
            INSERT INTO transactions 
            (txn_date, type, amount, currency, amount_bhd, account_id, to_account_id, category, note, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
        ")->execute([
            $today, $type, $amount, $currency, $amtBHD,
            $s['account_id'], $s['to_account_id'],
            $s['category'] ?? '', $note
        ]);
        
        // Update account balances
        if ($type === 'expense') {
            db()->prepare("UPDATE accounts SET balance=balance-? WHERE id=?")->execute([$amtBHD, $s['account_id']]);
        } elseif ($type === 'income') {
            db()->prepare("UPDATE accounts SET balance=balance+? WHERE id=?")->execute([$amtBHD, $s['account_id']]);
        } elseif ($type === 'transfer') {
            db()->prepare("UPDATE accounts SET balance=balance-? WHERE id=?")->execute([$amount, $s['account_id']]);
            if ($s['to_account_id']) {
                $toAcc = db()->prepare("SELECT currency FROM accounts WHERE id=?");
                $toAcc->execute([$s['to_account_id']]);
                $toRow = $toAcc->fetch();
                $toAmount = $toRow ? ($amtBHD / max(0.001, toBHD(1, $toRow['currency']))) : $amount;
                db()->prepare("UPDATE accounts SET balance=balance+? WHERE id=?")->execute([$toAmount, $s['to_account_id']]);
            }
        }
        
        // Calculate next_run date based on frequency
        $nextRun = calculateNextRun($today, $freq, $s['start_date']);
        
        // Update schedule: last_run, next_run, occurrences_done
        db()->prepare("
            UPDATE scheduled_payments 
            SET last_run=?, next_run=?, occurrences_done=occurrences_done+1
            WHERE id=?
        ")->execute([$today, $nextRun, $sid]);
        
        echo "✅ DONE #{$sid} [{$freq}] {$s['name']}: {$amount} {$currency} → next: {$nextRun}\n";
        $processed++;
        
    } catch (Exception $e) {
        echo "❌ ERROR #{$sid} {$s['name']}: " . $e->getMessage() . "\n";
    }
}

echo "\n📊 Done: {$processed} processed, {$skipped} skipped\n";

// ── Calculate next run date ────────────────────────────────────────
function calculateNextRun(string $today, string $freq, string $startDate): string {
    switch ($freq) {
        case 'daily':
            return date('Y-m-d', strtotime($today . ' +1 day'));
        case 'weekly':
            return date('Y-m-d', strtotime($today . ' +7 days'));
        case 'monthly':
            // Keep same day of month as start date
            $startDay = (int)date('d', strtotime($startDate));
            $nextMonth = date('Y-m', strtotime($today . ' +1 month'));
            $daysInNext = (int)date('t', strtotime($nextMonth . '-01'));
            $day = min($startDay, $daysInNext);
            return $nextMonth . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
        case 'yearly':
            return date('Y-m-d', strtotime($today . ' +1 year'));
        default:
            return date('Y-m-d', strtotime($today . ' +1 month'));
    }
}
