<?php
/**
 * PORTFOLIO → ACCOUNT SYNC
 * File: dashboard/sync_portfolio_to_accounts.php
 *
 * Call this after saving portfolio prices (save_prices.php already does it)
 * OR call it standalone via AJAX.
 *
 * What it does:
 *   1. For each portfolio item, sum up current market value per account_id
 *   2. Update that account's balance in the accounts table
 *   3. Returns a summary of what was updated
 *
 * The portfolio table has: account_id, quantity, current_price, currency
 * The accounts table has: id, balance, currency
 *
 * Usage from save_prices.php: include this file after saving.
 * Usage standalone AJAX: POST action=sync → returns JSON
 */

if (!defined('SAJJAD_FINANCE')) {
    session_start();
    require_once '../config.php';
    header('Content-Type: application/json');
    $standalone = true;
} else {
    $standalone = false;
}

function syncPortfolioToAccounts(PDO $pdo): array {
    // Get all portfolio items grouped by account
    $stmt = $pdo->query("
        SELECT
            p.account_id,
            p.currency,
            SUM(p.quantity * p.current_price) as market_value,
            COUNT(*) as stock_count,
            GROUP_CONCAT(p.symbol ORDER BY p.symbol SEPARATOR ', ') as symbols
        FROM portfolio p
        WHERE p.current_price > 0 AND p.quantity > 0 AND p.account_id IS NOT NULL
        GROUP BY p.account_id, p.currency
    ");
    $portfolio_by_account = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = [];
    $errors  = [];

    foreach ($portfolio_by_account as $row) {
        $account_id   = $row['account_id'];
        $market_value = round($row['market_value'], 2);
        $currency     = $row['currency'];

        // Get account info
        $acct = $pdo->prepare("SELECT id, name, balance, currency FROM accounts WHERE id = ?");
        $acct->execute([$account_id]);
        $account = $acct->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            $errors[] = "Account ID $account_id not found";
            continue;
        }

        $old_balance = $account['balance'];

        // Update account balance
        $upd = $pdo->prepare("UPDATE accounts SET balance = ?, updated_at = NOW() WHERE id = ?");
        $upd->execute([$market_value, $account_id]);

        $updated[] = [
            'account_id'   => $account_id,
            'account_name' => $account['name'],
            'old_balance'  => $old_balance,
            'new_balance'  => $market_value,
            'currency'     => $currency,
            'stocks'       => $row['symbols'],
        ];
    }

    return ['updated' => $updated, 'errors' => $errors];
}

if ($standalone) {
    try {
        $pdo = new PDO(
            "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $result = syncPortfolioToAccounts($pdo);
        echo json_encode(['ok' => true, ...$result]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
