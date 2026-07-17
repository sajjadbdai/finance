<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

/**
 * Parse one OR multiple transactions from a natural language message.
 * Returns array of parsed transactions.
 *
 * Examples of multi-transaction messages:
 * "spent 5 bd on food and 3 bd on taxi from cash"
 * "paid 10 bhd credimax and 50 bhd brac bank rent"
 * "grocery 2.5, taxi 1.2, coffee 0.8 all from ILA"
 */
function parseTransactions(string $userText): array {
    $accounts = db()->query("SELECT name, currency, group_name FROM accounts WHERE is_active=1 ORDER BY name")->fetchAll();
    $accountList = implode(', ', array_column($accounts, 'name'));

    $categories = db()->query("SELECT name, parent, type FROM categories ORDER BY parent, name")->fetchAll();
    $catList = implode(', ', array_column($categories, 'name'));

    $systemPrompt = <<<PROMPT
You are a personal finance transaction parser for Sajjad, who lives in Bahrain and also has Bangladesh accounts.

ACCOUNTS AVAILABLE: {$accountList}

CATEGORIES AVAILABLE: {$catList}

CURRENCIES: BHD (Bahrain Dinar, primary), BDT (Bangladeshi Taka), USD, GBP

RULES:
1. Parse the message into ONE OR MORE transactions.
2. type must be: "expense", "income", or "transfer"
3. For transfers, account = source, to_account = destination.
4. Match accounts flexibly: "ILA"=ILA, "brac"=Brac Bank, "bisb"=BisB, "credimax"=Credimax Talabat MC 400, "cash"=Cash on Hand
5. Match categories flexibly: "food"=Food, "grocery"=Grocery, "rent"=House Rent, "fuel"=Fuel, "taxi"=Transport
6. If currency not specified, default to BHD for Bahrain accounts, BDT for BD accounts.
7. If account not specified for multiple items, use the last mentioned account.
8. date defaults to today unless specified.
9. Return ONLY a valid JSON array, no explanation, no markdown.

OUTPUT FORMAT — always return an ARRAY even for single transaction:
[
  {
    "type": "expense",
    "amount": 5.00,
    "currency": "BHD",
    "account": "exact account name from list",
    "to_account": null,
    "category": "category name",
    "subcategory": "",
    "note": "short description",
    "date": "YYYY-MM-DD",
    "confidence": 0.95
  }
]

MULTI-TRANSACTION EXAMPLES:
- "spent 5 on food and 3 on taxi from cash" → 2 expense transactions from Cash on Hand
- "grocery 2.5 taxi 1.2 coffee 0.8 from ILA" → 3 expense transactions from ILA
- "paid 100 rent and 50 electricity from BBK" → 2 expense transactions from BBK
- "received salary 500 in BBK and paid 50 rent from cash" → 1 income + 1 expense

If truly cannot parse, return: [{"error": "reason"}]
PROMPT;

    $payload = [
        'model'      => ANTHROPIC_MODEL,
        'max_tokens' => 1000,
        'system'     => $systemPrompt,
        'messages'   => [['role'=>'user','content'=>'Today is '.date('Y-m-d').' (Bahrain time). Parse this: '.$userText]]
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: '.ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT    => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return [];

    $data = json_decode($response, true);
    $text = trim($data['content'][0]['text'] ?? '');
    $text = preg_replace('/^```(?:json)?\s*/i','',  $text);
    $text = preg_replace('/\s*```$/',              '', $text);

    $parsed = json_decode($text, true);
    if (!$parsed || !is_array($parsed)) return [];

    // Filter out error objects
    return array_filter($parsed, fn($t) => !isset($t['error']) && !empty($t['type']));
}

// Backwards compat — single transaction
function parseTransaction(string $userText): ?array {
    $results = parseTransactions($userText);
    return count($results) > 0 ? array_values($results)[0] : null;
}

/**
 * Save a parsed transaction to DB and update balances.
 */
function saveTransaction(array $parsed, string $source = 'telegram', string $rawInput = ''): ?int {
    $account   = getAccountByName($parsed['account'] ?? '');
    $toAccount = (!empty($parsed['to_account'])) ? getAccountByName($parsed['to_account']) : null;

    if (!$account) return null;

    $amount    = (float)($parsed['amount']   ?? 0);
    $currency  = $parsed['currency']         ?? $account['currency'] ?? 'BHD';
    $amountBHD = toBHD($amount, $currency);
    $date      = $parsed['date']             ?? date('Y-m-d');
    $type      = $parsed['type']             ?? 'expense';

    $st = db()->prepare("INSERT INTO transactions
        (txn_date,type,amount,currency,amount_bhd,account_id,to_account_id,
         category,subcategory,note,source,raw_input)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");

    $st->execute([
        $date.' '.date('H:i:s'),
        $type, $amount, $currency, $amountBHD,
        $account['id'],
        $toAccount ? $toAccount['id'] : null,
        $parsed['category']    ?? '',
        $parsed['subcategory'] ?? '',
        $parsed['note']        ?? '',
        $source, $rawInput
    ]);

    $txnId = (int)db()->lastInsertId();

    if ($type==='expense')                    updateAccountBalance($account['id'], -$amount);
    elseif ($type==='income')                 updateAccountBalance($account['id'],  $amount);
    elseif ($type==='transfer' && $toAccount) {
        updateAccountBalance($account['id'],    -$amount);
        updateAccountBalance($toAccount['id'],   $amount);
    }

    return $txnId;
}
