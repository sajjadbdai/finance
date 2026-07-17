<?php
require_once __DIR__ . '/BankParserBase.php';

class BBKParser extends BankParserBase {
    public function getBankName(): string { return 'BBK'; }

    public function canParse(string $text): bool {
        return stripos($text, 'Bank of Bahrain and Kuwait') !== false
            || stripos($text, 'BBK') !== false;
    }

    public function parse(string $text): array {
        $result = [
            'currency'        => 'BHD',
            'opening_balance' => 0,
            'closing_balance' => 0,
            'total_credits'   => 0,
            'total_debits'    => 0,
            'period_from'     => null,
            'period_to'       => null,
            'statement_date'  => null,
            'transactions'    => [],
            'status'          => 'parsed',
            'parse_notes'     => '',
        ];

        // Extract statement period
        if (preg_match('/From\s*[:\s]+(\d{2}[\/\-]\d{2}[\/\-]\d{4})\s*To\s*[:\s]+(\d{2}[\/\-]\d{2}[\/\-]\d{4})/i', $text, $m)) {
            $result['period_from'] = $this->parseDate($m[1]);
            $result['period_to']   = $this->parseDate($m[2]);
            $result['statement_date'] = $result['period_to'];
        }

        // Extract opening balance
        if (preg_match('/Opening\s+Balance\s*[:\s]+([\d,]+\.\d+)/i', $text, $m)) {
            $result['opening_balance'] = $this->parseAmount($m[1]);
        }

        // Extract closing balance
        if (preg_match('/Closing\s+Balance\s*[:\s]+([\d,]+\.\d+)/i', $text, $m)) {
            $result['closing_balance'] = $this->parseAmount($m[1]);
        }

        // Parse transaction lines
        // BBK format: DATE | DESCRIPTION | REFERENCE | DEBIT | CREDIT | BALANCE
        $lines = explode("\n", $text);
        $txns  = [];
        foreach ($lines as $line) {
            $line = trim($line);
            // Match: date at start, amounts at end
            if (preg_match('/^(\d{2}[\/\-]\d{2}[\/\-]\d{4})\s+(.+?)\s+([\d,]*\.?\d*)\s+([\d,]*\.?\d*)\s+([\d,]+\.\d+)$/', $line, $m)) {
                $date  = $this->parseDate($m[1]);
                $desc  = $this->cleanText($m[2]);
                $debit = $this->parseAmount($m[3]);
                $credit= $this->parseAmount($m[4]);
                $bal   = $this->parseAmount($m[5]);
                if ($date && ($debit > 0 || $credit > 0)) {
                    $txns[] = [
                        'line_date'   => $date,
                        'description' => $desc,
                        'debit'       => $debit,
                        'credit'      => $credit,
                        'balance'     => $bal,
                        'currency'    => 'BHD',
                        'raw_text'    => $line,
                    ];
                    if ($debit > 0)  $result['total_debits']  += $debit;
                    if ($credit > 0) $result['total_credits'] += $credit;
                }
            }
        }
        $result['transactions'] = $txns;
        $result['txn_count']    = count($txns);
        return $result;
    }
}
