<?php
/**
 * Sync portfolio values to linked investment accounts
 * Called after any price update
 */
function syncPortfolioToAccounts(): array {
    $log = [];
    
    // Exchange rates to BHD
    $rates = ['BHD'=>1,'USD'=>0.377,'BDT'=>0.00343,'GBP'=>0.478,'EUR'=>0.411,'SAR'=>0.1006];
    
    try {
        // Get portfolio value per account_id, converting to account's currency
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

        // Group by account - convert all to account currency
        $acctValues = [];
        foreach ($rows as $r) {
            $aid = (int)$r['account_id'];
            // Convert portfolio currency → BHD → account currency
            $portRate = $rates[$r['port_currency']] ?? 1;
            $accRate  = $rates[$r['acc_currency']]  ?? 1;
            $valueInBHD = $r['total_value'] * $portRate;
            $valueInAccCur = $accRate > 0 ? $valueInBHD / $accRate : $valueInBHD;
            
            $acctValues[$aid]['value']    = ($acctValues[$aid]['value'] ?? 0) + $valueInAccCur;
            $acctValues[$aid]['name']     = $r['acc_name'];
            $acctValues[$aid]['currency'] = $r['acc_currency'];
        }

        // Update each account balance
        foreach ($acctValues as $aid => $data) {
            $newBalance = round($data['value'], 4);
            db()->prepare("UPDATE accounts SET balance=? WHERE id=?")
               ->execute([$newBalance, $aid]);
            $log[] = "✅ {$data['name']}: {$data['currency']} " . number_format($newBalance, 2);
        }
    } catch (Exception $e) {
        $log[] = "❌ Error: " . $e->getMessage();
    }
    
    return $log;
}
