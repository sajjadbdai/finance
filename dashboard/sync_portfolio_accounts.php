<?php
/**
 * Sync portfolio values — DISPLAY ONLY, as of the fix below.
 *
 * ⚠️ THIS FUNCTION USED TO WRITE MARKET VALUE INTO accounts.balance ⚠️
 * That was wrong: accounts.balance is the account's ledger balance (cost
 * basis / actual cash), driven by real transactions. Every time this ran
 * (after any price refresh), it silently replaced that ledger balance with
 * today's mark-to-market value — turning unrealized gains into what looked
 * like realized income, with no transaction row to explain it, and no way
 * to tell cost basis from market value afterward.
 *
 * It also caused Total Wealth (dashboard/index.php) to double-count: the
 * inflated accounts.balance was summed into $totalAssets, and the SAME
 * portfolio market value was summed again separately as $portfolioBHD.
 *
 * FIX: this function no longer touches accounts.balance at all. It still
 * computes market value per linked account (for display/logging), it just
 * doesn't write it anywhere. accounts.balance now only changes through
 * actual transactions, same as every other account.
 *
 * If you actually want a persisted market-value snapshot, use
 * getPortfolioMarketValue($accountId) below wherever you need to DISPLAY
 * it, rather than writing it into the ledger.
 */
function syncPortfolioToAccounts(): array {
    $log = [];

    $rates = ['BHD'=>1,'USD'=>0.377,'BDT'=>0.00343,'GBP'=>0.478,'EUR'=>0.411,'SAR'=>0.1006];

    try {
        $rows = db()->query("
            SELECT
                p.account_id,
                a.currency as acc_currency,
                a.name as acc_name,
                p.currency as port_currency,
                SUM(p.quantity * p.current_price) as total_value
            FROM portfolio p
            JOIN accounts a ON a.id = p.account_id
            WHERE p.quantity > 0
              AND p.account_id IS NOT NULL
              AND p.account_id > 0
              AND p.current_price > 0
            GROUP BY p.account_id, p.currency, a.currency, a.name
        ")->fetchAll();

        if (!$rows) return ['No linked portfolio holdings found'];

        $acctValues = [];
        foreach ($rows as $r) {
            $aid = (int)$r['account_id'];
            $portRate = $rates[$r['port_currency']] ?? 1;
            $accRate  = $rates[$r['acc_currency']]  ?? 1;
            $valueInBHD = $r['total_value'] * $portRate;
            $valueInAccCur = $accRate > 0 ? $valueInBHD / $accRate : $valueInBHD;

            $acctValues[$aid]['value']    = ($acctValues[$aid]['value'] ?? 0) + $valueInAccCur;
            $acctValues[$aid]['name']     = $r['acc_name'];
            $acctValues[$aid]['currency'] = $r['acc_currency'];
        }

        // NOTE: no UPDATE accounts SET balance=... here anymore — see docblock above.
        foreach ($acctValues as $aid => $data) {
            $marketValue = round($data['value'], 4);
            $log[] = "ℹ️ {$data['name']}: market value {$data['currency']} " . money($marketValue, $data['currency']) . " (not written to balance)";
        }
    } catch (Exception $e) {
        $log[] = "❌ Error: " . $e->getMessage();
    }

    return $log;
}

/**
 * Live market value for a single account (BHD converted), for display only.
 * Does not read or write accounts.balance.
 */
function getPortfolioMarketValue(int $accountId): float {
    try {
        $st = db()->prepare("SELECT COALESCE(SUM(quantity*current_price),0) as v, MAX(currency) as cur
                              FROM portfolio WHERE account_id=? AND quantity>0 AND current_price>0");
        $st->execute([$accountId]);
        $r = $st->fetch();
        if (!$r || !$r['v']) return 0.0;
        return toBHD((float)$r['v'], $r['cur'] ?: 'BHD');
    } catch (Exception $e) {
        return 0.0;
    }
}
