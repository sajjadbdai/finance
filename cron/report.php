<?php
/**
 * CRON JOB — auto-reports via Telegram
 *
 * Set up in Exonhost cPanel → Cron Jobs:
 *   Daily report:   0 21 * * *   php /home/username/public_html/finance/cron/report.php daily
 *   Weekly report:  0 20 * * 5   php /home/username/public_html/finance/cron/report.php weekly
 *   Monthly report: 0 9  1 * *   php /home/username/public_html/finance/cron/report.php monthly
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../bot/telegram.php';

$type = $argv[1] ?? 'daily';
$chatId = YOUR_TELEGRAM_ID;

if (!$chatId) {
    echo "Error: YOUR_TELEGRAM_ID not set in config.php\n";
    exit(1);
}

switch ($type) {
    case 'daily':
        sendDailyReport($chatId);
        break;
    case 'weekly':
        sendWeeklyReport($chatId);
        break;
    case 'monthly':
        sendMonthlyReport($chatId);
        break;
}

function sendDailyReport(int $chatId): void {
    $today = date('Y-m-d');
    $st    = db()->prepare(
        "SELECT type, SUM(amount_bhd) as total, COUNT(*) as cnt
         FROM transactions WHERE DATE(txn_date)=? GROUP BY type"
    );
    $st->execute([$today]);
    $rows  = $st->fetchAll();
    $stats = ['income' => 0, 'expense' => 0, 'transfer' => 0];
    $cnts  = ['income' => 0, 'expense' => 0];
    foreach ($rows as $r) { $stats[$r['type']] = (float)$r['total']; $cnts[$r['type']] = $r['cnt']; }

    // Top categories today
    $st2 = db()->prepare(
        "SELECT category, SUM(amount_bhd) as total FROM transactions
         WHERE DATE(txn_date)=? AND type='expense' GROUP BY category ORDER BY total DESC LIMIT 5"
    );
    $st2->execute([$today]);
    $cats = $st2->fetchAll();

    $msg  = "🌙 <b>Daily Report — " . date('d M Y') . "</b>\n\n";
    $msg .= "🟢 Income:  BD " . money($stats['income']) . " ({$cnts['income']} txns)\n";
    $msg .= "🔴 Expense: BD " . money($stats['expense']) . " ({$cnts['expense']} txns)\n";
    $net  = $stats['income'] - $stats['expense'];
    $msg .= "💰 Net:     BD " . money($net) . "\n";

    if ($cats) {
        $msg .= "\n<b>Top Expenses:</b>\n";
        foreach ($cats as $c) {
            $msg .= "  • {$c['category']}: BD " . money($c['total']) . "\n";
        }
    }

    // Monthly progress
    $month = date('Y-m');
    $mst   = db()->prepare("SELECT type, SUM(amount_bhd) as total FROM transactions
                            WHERE DATE_FORMAT(txn_date,'%Y-%m')=? GROUP BY type");
    $mst->execute([$month]);
    $mstats = ['income' => 0, 'expense' => 0];
    foreach ($mst->fetchAll() as $r) $mstats[$r['type']] = (float)$r['total'];
    $msg .= "\n📅 <b>" . date('M') . " so far:</b> Saved BD " . money($mstats['income'] - $mstats['expense']);
    $msg .= "\n🌐 " . SITE_URL;

    tgSend($chatId, $msg);
}

function sendWeeklyReport(int $chatId): void {
    $from = date('Y-m-d', strtotime('last Saturday'));
    $to   = date('Y-m-d');

    $st = db()->prepare(
        "SELECT type, category, SUM(amount_bhd) as total
         FROM transactions WHERE txn_date BETWEEN ? AND ?
         GROUP BY type, category ORDER BY type, total DESC"
    );
    $st->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $rows = $st->fetchAll();

    $inc = 0; $exp = 0; $expCats = [];
    foreach ($rows as $r) {
        if ($r['type'] === 'income')  $inc += (float)$r['total'];
        if ($r['type'] === 'expense') { $exp += (float)$r['total']; $expCats[] = $r; }
    }

    $msg  = "📈 <b>Weekly Report</b>\n";
    $msg .= date('d M', strtotime($from)) . " – " . date('d M Y', strtotime($to)) . "\n\n";
    $msg .= "🟢 Income:  BD " . money($inc) . "\n";
    $msg .= "🔴 Expense: BD " . money($exp) . "\n";
    $msg .= "💰 Saved:   BD " . money($inc - $exp) . "\n\n";

    if ($expCats) {
        $msg .= "<b>Expenses Breakdown:</b>\n";
        foreach (array_slice($expCats, 0, 8) as $c) {
            $pct  = $exp > 0 ? round(($c['total'] / $exp) * 100) : 0;
            $bar  = str_repeat('█', max(1,(int)($pct/10))) . str_repeat('░', 10-max(1,(int)($pct/10)));
            $msg .= "{$bar} {$c['category']}: BD " . money($c['total']) . " ({$pct}%)\n";
        }
    }

    $msg .= "\n🌐 " . SITE_URL;
    tgSend($chatId, $msg);
}

function sendMonthlyReport(int $chatId): void {
    $lastMonth = date('Y-m', strtotime('last month'));
    $monthName = date('F Y', strtotime('last month'));

    $st = db()->prepare(
        "SELECT type, SUM(amount_bhd) as total, COUNT(*) as cnt
         FROM transactions WHERE DATE_FORMAT(txn_date,'%Y-%m')=? GROUP BY type"
    );
    $st->execute([$lastMonth]);
    $stats = ['income' => 0, 'expense' => 0]; $cnts = ['income' => 0, 'expense' => 0];
    foreach ($st->fetchAll() as $r) { $stats[$r['type']] = (float)$r['total']; $cnts[$r['type']] = $r['cnt']; }

    // Net worth now
    $nw = db()->query("SELECT SUM(CASE WHEN type='asset' THEN balance*1 ELSE balance END) as total FROM accounts WHERE is_active=1")->fetchColumn();

    $msg  = "🗓 <b>Monthly Report — {$monthName}</b>\n\n";
    $msg .= "🟢 Income:  BD " . money($stats['income']) . " ({$cnts['income']} txns)\n";
    $msg .= "🔴 Expense: BD " . money($stats['expense']) . " ({$cnts['expense']} txns)\n";
    $msg .= "💰 Saved:   BD " . money($stats['income'] - $stats['expense']) . "\n\n";
    $msg .= "💎 Current Net Worth: BD " . money((float)$nw) . "\n";
    $msg .= "\n🌐 Full report: " . SITE_URL;

    tgSend($chatId, $msg);
}
