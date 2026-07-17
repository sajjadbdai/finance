<?php

if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
require_once __DIR__ . '/db.php';


$message = ''; $imported = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel'])) {
    $file = $_FILES['excel']['tmp_name'];
    if ($file) {
        // Use PhpSpreadsheet if available, else fallback CSV
        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray();
            } catch (Exception $e) { $rows = []; }
        } else {
            // Fallback: treat as CSV
            $rows = array_map('str_getcsv', file($file));
        }

        $header = array_shift($rows);
        // Map columns: Period, Accounts, Category, Subcategory, Note, BHD, Income/Expense, Description, Amount, Currency
        foreach ($rows as $row) {
            if (empty($row[0])) continue;
            try {
                $dateVal  = $row[0];
                // Excel date serial
                if (is_numeric($dateVal)) {
                    $unixDate = ($dateVal - 25569) * 86400;
                    $txnDate  = date('Y-m-d H:i:s', $unixDate);
                } else {
                    $txnDate = date('Y-m-d H:i:s', strtotime($dateVal));
                }

                $accName  = trim($row[1] ?? '');
                $cat      = trim($row[2] ?? '');
                $subcat   = trim($row[3] ?? '');
                $note     = trim($row[4] ?? '');
                $bhd      = (float)($row[5] ?? 0);
                $typeRaw  = trim($row[6] ?? '');
                $amount   = (float)($row[8] ?? 0);
                $currency = trim($row[9] ?? 'BHD');

                $type = 'expense';
                if (stripos($typeRaw, 'Income') !== false) $type = 'income';
                elseif (stripos($typeRaw, 'Transfer') !== false) $type = 'transfer';

                $acc = getAccountByName($accName);
                if (!$acc) continue;

                $st = db()->prepare("INSERT IGNORE INTO transactions
                    (txn_date, type, amount, currency, amount_bhd, account_id,
                     category, subcategory, note, source)
                    VALUES (?,?,?,?,?,?,?,?,?,'import')");
                $st->execute([$txnDate, $type, $amount, $currency, $bhd,
                              $acc['id'], $cat, $subcat, $note]);
                $imported++;
            } catch (Exception $e) { continue; }
        }
        $message = "✅ Imported {$imported} transactions successfully!";
    }
}
?>
<?php
$pageTitle='Import Excel'; $activePage='import';
require 'header.php';
?>
<div class="card" style="max-width:600px;">
    <div class="section-title" style="margin-bottom:4px;">📥 Import Transactions</div>
    <div style="font-size:.82rem;color:var(--muted);margin-bottom:20px;">
        Upload your Money Manager Excel export to sync transactions.
    </div>

    <?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label class="form-label">Select Excel file (.xlsx)</label>
            <input type="file" name="excel" accept=".xlsx,.xls,.csv" class="form-control" style="padding:10px;cursor:pointer;">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;padding:12px;">📤 Import Now</button>
    </form>

    <div style="margin-top:24px;padding-top:20px;border-top:1px solid var(--s3);">
        <div style="font-size:.82rem;color:var(--blue);font-weight:600;margin-bottom:14px;">HOW TO EXPORT FROM YOUR APP</div>
        <div style="display:flex;flex-direction:column;gap:12px;">
            <?php foreach([
                'Open Money Manager → More tab',
                'Tap Export → Choose Excel format',
                'Save the file and upload it here',
                'Transactions sync automatically — duplicates are skipped'
            ] as $i=>$step): ?>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="background:var(--blue);color:#fff;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0;"><?=$i+1?></div>
                <div style="font-size:.85rem;color:var(--muted);padding-top:4px;"><?=$step?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div style="margin-top:20px;">
        <a href="index.php" class="btn btn-ghost btn-sm">← Back to Dashboard</a>
    </div>
</div>
<?php require 'footer.php'; ?>
