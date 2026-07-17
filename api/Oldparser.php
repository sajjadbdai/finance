<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

/**
 * Parse a natural language transaction message using Claude AI.
 * Returns structured array or null on failure.
 */
function parseTransaction(string $userText): ?array {
    // Build accounts list for context
    $accounts = db()->query("SELECT name, currency, type FROM accounts WHERE is_active=1 ORDER BY name")->fetchAll();
    $accountList = implode(', ', array_column($accounts, 'name'));

    $categories = db()->query("SELECT name, parent FROM categories ORDER BY parent, name")->fetchAll();
    $catList = implode(', ', array_column($categories, 'name'));

    $systemPrompt = <<<PROMPT
You are a personal finance transaction parser for Sajjad, who lives in Bahrain and also has Bangladesh accounts.

ACCOUNTS AVAILABLE: {$accountList}

CATEGORIES AVAILABLE: {$catList}

CURRENCIES: BHD (Bahrain Dinar, primary), BDT (Bangladeshi Taka), USD, GBP

RULES:
1. Parse the user's message into a JSON transaction object.
2. type must be: "income", "expense", or "transfer"
3. For transfers, account is the source, to_account is destination.
4. Match account names flexibly (e.g. "ILA" = "ILA", "brac" = "Brac Bank", "bisb" = "BisB")
5. Match categories flexibly (e.g. "food" = "Food", "grocery" = "Grocery", "rent" = "House Rent")
6. If currency not specified, default to BHD for Bahrain accounts, BDT for BD accounts.
7. date defaults to today if not specified.
8. Return ONLY valid JSON, no explanation.

OUTPUT FORMAT:
{
  "type": "expense|income|transfer",
  "amount": 0.00,
  "currency": "BHD",
  "account": "exact account name from list",
  "to_account": "exact account name or null",
  "category": "category name",
  "subcategory": "subcategory or empty string",
  "note": "short note",
  "date": "YYYY-MM-DD",
  "confidence": 0.95,
  "parsed_summary": "Human readable: [account] [type] [amount] [currency] for [category]"
}

If you cannot parse it as a transaction, return: {"error": "reason why"}
PROMPT;

    $payload = [
        'model'      => ANTHROPIC_MODEL,
        'max_tokens' => 500,
        'system'     => $systemPrompt,
        'messages'   => [['role' => 'user', 'content' => 'Today is ' . date('Y-m-d') . ' (Bahrain time). Transaction: ' . $userText]]
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return null;

    $data = json_decode($response, true);
    $text = $data['content'][0]['text'] ?? '';

    // Strip markdown fences if present
    $text = preg_replace('/^```json\s*/i', '', trim($text));
    $text = preg_replace('/\s*```$/', '', $text);

    $parsed = json_decode($text, true);
    if (!$parsed || isset($parsed['error'])) return null;

    return $parsed;
}

/**
 * Save a parsed transaction to DB and update balances.
 */
function saveTransaction(array $parsed, string $source = 'telegram', string $rawInput = ''): ?int {
    $db = db();

    // Resolve account IDs
    $account = getAccountByName($parsed['account'] ?? '');
    $toAccount = isset($parsed['to_account']) && $parsed['to_account']
        ? getAccountByName($parsed['to_account'])
        : null;

    if (!$account) return null;

    $amount    = (float)($parsed['amount'] ?? 0);
    $currency  = $parsed['currency'] ?? 'BHD';
    $amountBHD = toBHD($amount, $currency);
    $date      = $parsed['date'] ?? date('Y-m-d');
    $type      = $parsed['type'] ?? 'expense';

    $st = $db->prepare("INSERT INTO transactions
        (txn_date, type, amount, currency, amount_bhd, account_id, to_account_id,
         category, subcategory, note, source, raw_input)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $st->execute([
        $date . ' ' . date('H:i:s'),
        $type, $amount, $currency, $amountBHD,
        $account['id'],
        $toAccount ? $toAccount['id'] : null,
        $parsed['category']    ?? '',
        $parsed['subcategory'] ?? '',
        $parsed['note']        ?? '',
        $source, $rawInput
    ]);

    $txnId = (int)$db->lastInsertId();

    // Update balances
    if ($type === 'expense') {
        updateAccountBalance($account['id'], -$amount);
    } elseif ($type === 'income') {
        updateAccountBalance($account['id'], $amount);
    } elseif ($type === 'transfer' && $toAccount) {
        updateAccountBalance($account['id'],   -$amount);
        updateAccountBalance($toAccount['id'],  $amount);
    }

    return $txnId;
}
