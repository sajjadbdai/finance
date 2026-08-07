<?php
/**
 * ledger.php — shared double-entry posting
 *
 * accounts.balance is still the fast, simple number every page reads.
 * This file adds a SEPARATE, parallel general ledger (ledger_entries)
 * that records the same events as proper debit/credit pairs, so the
 * whole system can be verified with a real trial balance that must
 * balance to zero by construction — not just "does stored match
 * computed" for one account at a time.
 *
 * THREE KINDS OF LEDGER LEG:
 *   - a real account:  account_id = that account's id
 *   - Equity:           account_id = NULL, bucket = 'EQUITY'
 *                        (income, expense, adjustments, realized gains/losses)
 *   - Portfolio:         account_id = NULL, bucket = 'PORTFOLIO'
 *                        (cost basis of stock currently held)
 *
 * Buying stock is NOT an expense in double-entry terms — cash converts
 * into a portfolio asset, so it's Dr Portfolio / Cr Cash. Selling
 * splits into return-of-cost (Dr Cash / Cr Portfolio, at cost) plus
 * any realized gain or loss (the difference hits Equity). That split
 * is what keeps the Equity bucket meaning "realized profit/loss only" —
 * exactly the distinction requested for unrealized vs realized gains.
 */
require_once __DIR__ . '/db.php';

/**
 * Post one balanced double-entry event. $debitLeg and $creditLeg are
 * each either ['account_id' => int] or ['bucket' => 'EQUITY'|'PORTFOLIO'].
 * Skips silently for near-zero amounts — callers don't need to guard.
 */
function postLedgerPair(?int $txnId, string $date, string $description, array $debitLeg, array $creditLeg, float $amountBHD): void {
    if ($amountBHD <= 0.0001) return;
    $d = date('Y-m-d', strtotime($date));

    db()->prepare("INSERT INTO ledger_entries (txn_id,entry_date,account_id,bucket,debit_bhd,credit_bhd,description) VALUES (?,?,?,?,?,0,?)")
        ->execute([$txnId, $d, $debitLeg['account_id'] ?? null, $debitLeg['bucket'] ?? null, round($amountBHD,4), $description]);

    db()->prepare("INSERT INTO ledger_entries (txn_id,entry_date,account_id,bucket,debit_bhd,credit_bhd,description) VALUES (?,?,?,?,0,?,?)")
        ->execute([$txnId, $d, $creditLeg['account_id'] ?? null, $creditLeg['bucket'] ?? null, round($amountBHD,4), $description]);
}

/** Remove every ledger leg tied to a transaction (used before re-posting on edit, and on delete). */
function clearLedgerForTxn(int $txnId): void {
    db()->prepare("DELETE FROM ledger_entries WHERE txn_id=?")->execute([$txnId]);
}

/** Standard income posting: cash increases (Debit), Equity increases (Credit). */
function postIncome(?int $txnId, string $date, string $desc, int $accountId, float $amount, string $currency): void {
    postLedgerPair($txnId, $date, $desc, ['account_id'=>$accountId], ['bucket'=>'EQUITY'], toBHD($amount, $currency));
}

/** Standard expense posting: Equity decreases (Debit), cash decreases (Credit). */
function postExpense(?int $txnId, string $date, string $desc, int $accountId, float $amount, string $currency): void {
    postLedgerPair($txnId, $date, $desc, ['bucket'=>'EQUITY'], ['account_id'=>$accountId], toBHD($amount, $currency));
}

/** Transfer: destination account debited, source account credited. */
function postTransfer(?int $txnId, string $date, string $desc, int $fromAccountId, int $toAccountId, float $amount, string $currency): void {
    postLedgerPair($txnId, $date, $desc, ['account_id'=>$toAccountId], ['account_id'=>$fromAccountId], toBHD($amount, $currency));
}

/** Stock buy: cash → portfolio cost basis. Not an expense — an asset swap. */
function postStockBuy(?int $txnId, string $date, string $desc, int $accountId, float $amountNative, string $currency): void {
    postLedgerPair($txnId, $date, $desc, ['bucket'=>'PORTFOLIO'], ['account_id'=>$accountId], toBHD($amountNative, $currency));
}

/**
 * Stock sell: proceeds return to cash; the cost-basis portion leaves
 * Portfolio, and any realized gain/loss hits Equity separately so
 * Equity only ever reflects REALIZED profit, never unrealized.
 */
function postStockSell(?int $txnId, string $date, string $desc, int $accountId, float $proceedsNative, float $costBasisNative, string $currency): void {
    $proceedsBHD = toBHD($proceedsNative, $currency);
    $costBHD     = toBHD($costBasisNative, $currency);
    $realizedBHD = $proceedsBHD - $costBHD;

    postLedgerPair($txnId, $date, $desc, ['account_id'=>$accountId], ['bucket'=>'PORTFOLIO'], $costBHD > 0 ? $costBHD : 0.0001);
    if (abs($realizedBHD) > 0.0001) {
        if ($realizedBHD > 0) {
            postLedgerPair($txnId, $date, $desc.' (realized gain)', ['account_id'=>$accountId], ['bucket'=>'EQUITY'], $realizedBHD);
        } else {
            postLedgerPair($txnId, $date, $desc.' (realized loss)', ['bucket'=>'EQUITY'], ['account_id'=>$accountId], -$realizedBHD);
        }
    }
}
