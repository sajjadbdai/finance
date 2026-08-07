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

/** Fixed asset buy: cash → fixed asset cost basis. Same reasoning as postStockBuy(). */
function postFixedAssetBuy(?int $txnId, string $date, string $desc, int $accountId, float $amountNative, string $currency): void {
    postLedgerPair($txnId, $date, $desc, ['bucket'=>'FIXED_ASSET'], ['account_id'=>$accountId], toBHD($amountNative, $currency));
}

/** Fixed asset sell: same split as postStockSell() — cost basis leaves FIXED_ASSET, realized gain/loss hits Equity. */
function postFixedAssetSell(?int $txnId, string $date, string $desc, int $accountId, float $proceedsNative, float $costBasisNative, string $currency): void {
    $proceedsBHD = toBHD($proceedsNative, $currency);
    $costBHD     = toBHD($costBasisNative, $currency);
    $realizedBHD = $proceedsBHD - $costBHD;

    postLedgerPair($txnId, $date, $desc, ['account_id'=>$accountId], ['bucket'=>'FIXED_ASSET'], $costBHD > 0 ? $costBHD : 0.0001);
    if (abs($realizedBHD) > 0.0001) {
        if ($realizedBHD > 0) {
            postLedgerPair($txnId, $date, $desc.' (realized gain)', ['account_id'=>$accountId], ['bucket'=>'EQUITY'], $realizedBHD);
        } else {
            postLedgerPair($txnId, $date, $desc.' (realized loss)', ['bucket'=>'EQUITY'], ['account_id'=>$accountId], -$realizedBHD);
        }
    }
}

/**
 * Reverse a transaction instead of deleting it. Creates a new,
 * opposite transaction (same account(s)/amount, inverted effect),
 * marks the original as reversed (never physically deleted), and
 * posts both the balance change and the double-entry ledger legs for
 * the NEW reversing entry — the original's ledger legs stay exactly
 * as they were, since the original event genuinely happened.
 *
 * income  A on account X  →  reversal = expense A on account X
 * expense A on account X  →  reversal = income  A on account X
 * transfer A from X to Y  →  reversal = transfer A from Y to X
 *
 * Returns ['ok'=>bool, 'new_id'=>int, 'warning'=>?string, 'error'=>?string].
 *
 * STOCK TRADES (category='Investment', subcategory='Stock Purchase'/
 * 'Stock Sale'): cash and the ledger are always corrected properly —
 * through the PORTFOLIO bucket, not EQUITY, mirroring exactly how
 * postStockBuy()/postStockSell() posted the original trade. The
 * portfolio holding's quantity/avg_cost is ALSO auto-corrected,
 * *provided* no other trade has touched that same holding since —
 * checked by looking for any later, non-reversed Investment
 * transaction on the same account+symbol. If nothing else happened,
 * the weighted-average math is exactly reversible (as it is for a
 * buy immediately followed by its own reversal). If something else
 * did happen, unwinding one piece of a mixed weighted average isn't
 * safe in general, so quantity/avg_cost is left alone and a warning
 * is returned instead.
 */
function reverseTransaction(int $txnId): array {
    $st = db()->prepare("SELECT * FROM transactions WHERE id=?");
    $st->execute([$txnId]);
    $t = $st->fetch();
    if (!$t || $t['reversed_at']) {
        return ['ok'=>false, 'error'=>'Transaction not found or already reversed.'];
    }

    $amt   = (float)$t['amount'];
    $cur   = $t['currency'];
    $today = date('Y-m-d H:i:s');
    $note  = 'Reversal of #' . $txnId . ($t['note'] ? ': ' . $t['note'] : '');
    $warning = null;
    $isStockTrade = ($t['category']==='Investment' && in_array($t['subcategory'], ['Stock Purchase','Stock Sale']));
    $isAssetTrade = ($t['category']==='Fixed Asset' && in_array($t['subcategory'], ['Asset Purchase','Asset Sale']));

    if ($t['type'] === 'income') {
        db()->prepare("INSERT INTO transactions (txn_date,type,amount,currency,amount_bhd,account_id,category,subcategory,note,source,reversal_of) VALUES (?,?,?,?,?,?,?,?,?,'reversal',?)")
            ->execute([$today,'expense',$amt,$cur,toBHD($amt,$cur),$t['account_id'],$t['category'],$t['subcategory'],$note,$txnId]);
        $newId = (int)db()->lastInsertId();
        updateAccountBalance((int)$t['account_id'], -$amt);

        if ($isStockTrade && $t['subcategory']==='Stock Sale') {
            $warning = reverseStockSaleLedgerAndHolding($t, $newId, $today, $note, $amt, $cur);
        } elseif ($isAssetTrade && $t['subcategory']==='Asset Sale') {
            $warning = reverseAssetSaleLedgerAndHolding($t, $newId, $today, $note, $amt, $cur);
        } else {
            postExpense($newId, $today, $note, (int)$t['account_id'], $amt, $cur);
        }

    } elseif ($t['type'] === 'expense') {
        db()->prepare("INSERT INTO transactions (txn_date,type,amount,currency,amount_bhd,account_id,category,subcategory,note,source,reversal_of) VALUES (?,?,?,?,?,?,?,?,?,'reversal',?)")
            ->execute([$today,'income',$amt,$cur,toBHD($amt,$cur),$t['account_id'],$t['category'],$t['subcategory'],$note,$txnId]);
        $newId = (int)db()->lastInsertId();
        updateAccountBalance((int)$t['account_id'], $amt);

        if ($isStockTrade && $t['subcategory']==='Stock Purchase') {
            $warning = reverseStockPurchaseLedgerAndHolding($t, $newId, $today, $note, $amt, $cur);
        } elseif ($isAssetTrade && $t['subcategory']==='Asset Purchase') {
            $warning = reverseAssetPurchaseLedgerAndHolding($t, $newId, $today, $note, $amt, $cur);
        } else {
            postIncome($newId, $today, $note, (int)$t['account_id'], $amt, $cur);
        }

    } elseif ($t['type'] === 'transfer' && $t['to_account_id']) {
        // swapped: reversal moves money from the ORIGINAL destination back to the ORIGINAL source
        // A cross-currency transfer credits the destination a DIFFERENT figure from
        // the one it debits the source. The reversal has to be recorded in the
        // DESTINATION's own currency, using the amount that actually arrived there —
        // otherwise the row describes a movement that never happened, and every
        // later walk over the ledger disagrees with the stored balance.
        $revAmt = function_exists('toAccountAmount')
                ? toAccountAmount($amt, $cur, (int)$t['to_account_id']) : $amt;
        $revCur = $cur;
        try {
            $rcSt = db()->prepare("SELECT currency FROM accounts WHERE id=?");
            $rcSt->execute([(int)$t['to_account_id']]);
            $rc = $rcSt->fetchColumn();
            if ($rc) $revCur = $rc;
        } catch (Exception $e) {}
        // What the original source gets back, derived the same way a walk derives it,
        // so the row and the balance can never part company by a rounding step.
        $backAmt = function_exists('toAccountAmount')
                 ? toAccountAmount($revAmt, $revCur, (int)$t['account_id']) : $amt;
        db()->prepare("INSERT INTO transactions (txn_date,type,amount,currency,amount_bhd,account_id,to_account_id,category,subcategory,note,source,reversal_of) VALUES (?,?,?,?,?,?,?,?,?,?,'reversal',?)")
            ->execute([$today,'transfer',$revAmt,$revCur,toBHD($revAmt,$revCur),$t['to_account_id'],$t['account_id'],$t['category'],$t['subcategory'],$note,$txnId]);
        $newId = (int)db()->lastInsertId();
        updateAccountBalance((int)$t['to_account_id'], -$revAmt);
        updateAccountBalance((int)$t['account_id'],     $backAmt);
        postTransfer($newId, $today, $note, (int)$t['to_account_id'], (int)$t['account_id'], $revAmt, $revCur);

    } else {
        return ['ok'=>false, 'error'=>'Unrecognized transaction type or missing destination account — nothing reversed.'];
    }

    db()->prepare("UPDATE transactions SET reversed_at=NOW() WHERE id=?")->execute([$txnId]);

    return ['ok'=>true, 'new_id'=>$newId, 'warning'=>$warning];
}

/**
 * Parse "Buy 50 EBL @ 11 BDT" / "Sell 50 EBL @ 11 BDT — Realized P/L: +5.00 BDT"
 * out of the note trade_stock.php always writes in that exact format.
 * Returns null if it doesn't match (e.g. a manually-entered Investment
 * transaction that didn't go through Trade Stock).
 */
function parseTradeNote(string $note): ?array {
    if (!preg_match('/^(Buy|Sell) ([\d.]+) (\S+) @ ([\d.]+) (\S+)/', $note, $m)) return null;
    return ['action'=>$m[1], 'qty'=>(float)$m[2], 'symbol'=>strtoupper($m[3]), 'price'=>(float)$m[4], 'currency'=>$m[5]];
}

/** Has any OTHER non-reversed Investment transaction touched this account+symbol after $afterTxnId? */
function hasLaterTrade(int $accountId, string $symbol, int $afterTxnId): bool {
    $st = db()->prepare(
        "SELECT COUNT(*) FROM transactions
         WHERE account_id=? AND category='Investment' AND id>? AND reversed_at IS NULL AND note LIKE ?"
    );
    $st->execute([$accountId, $afterTxnId, '%' . $symbol . '%']);
    return (int)$st->fetchColumn() > 0;
}

/** Reverse a Stock Purchase: ledger through PORTFOLIO (not EQUITY), quantity/avg_cost unwound if safe. */
function reverseStockPurchaseLedgerAndHolding(array $t, int $newId, string $today, string $note, float $amt, string $cur): ?string {
    $accId = (int)$t['account_id'];
    // Ledger: mirror of postStockBuy() reversed — Dr Account / Cr PORTFOLIO
    postLedgerPair($newId, $today, $note, ['account_id'=>$accId], ['bucket'=>'PORTFOLIO'], toBHD($amt, $cur));

    $trade = parseTradeNote($t['note'] ?? '');
    if (!$trade) return 'This was a stock purchase, but its note doesn\'t match the expected format — cash and the ledger are corrected, but you\'ll need to manually check the portfolio holding.';

    if (hasLaterTrade($accId, $trade['symbol'], (int)$t['id'])) {
        return "This was a purchase of {$trade['symbol']}, but other trades happened on this holding afterward — unwinding the average cost automatically isn't safe. Cash and the ledger are corrected; fix the {$trade['symbol']} quantity/avg cost manually via Trade Stock or Portfolio.";
    }

    $pSt = db()->prepare("SELECT * FROM portfolio WHERE account_id=? AND UPPER(symbol)=? LIMIT 1");
    $pSt->execute([$accId, $trade['symbol']]);
    $holding = $pSt->fetch();
    if (!$holding) return "This was a purchase of {$trade['symbol']}, but that holding no longer exists — cash and the ledger are corrected; check the portfolio manually.";

    $newQty = (float)$holding['quantity'] - $trade['qty'];
    if ($newQty < -0.0001) return "This was a purchase of {$trade['symbol']}, but the holding shows less quantity than this purchase added — cash and the ledger are corrected; check the portfolio manually.";
    $newAvg = $newQty > 0.0001
        ? (((float)$holding['quantity'] * (float)$holding['avg_cost']) - ($trade['qty'] * $trade['price'])) / $newQty
        : 0;
    db()->prepare("UPDATE portfolio SET quantity=?,avg_cost=? WHERE id=?")->execute([$newQty, $newAvg, $holding['id']]);
    return null; // fully auto-corrected, no warning needed
}

/** Reverse a Stock Sale: ledger through PORTFOLIO + EQUITY (mirrors postStockSell), quantity restored if safe. */
function reverseStockSaleLedgerAndHolding(array $t, int $newId, string $today, string $note, float $proceeds, string $cur): ?string {
    $accId = (int)$t['account_id'];
    $realized = 0.0;
    if (preg_match('/Realized P\/L:\s*([+-]?[\d.]+)/', $t['note'] ?? '', $m)) $realized = (float)$m[1];
    $costNative = $proceeds - $realized;

    // FIX: the note stored on this reversal row still says "Realized P/L:
    // +50.00" verbatim (copied from the original by the generic caller) —
    // if left as-is, anything parsing "Realized P/L" out of transaction
    // notes (report_financial_statements.php, trial_balance.php) would
    // read the SAME positive number a second time instead of the
    // offsetting loss, doubling the gain instead of netting to zero. Flip
    // just the number, keep everything else (symbol, qty, price) intact —
    // hasLaterTrade() still needs the symbol name in this text.
    $reversedRealized = -$realized;
    $fixedNote = preg_replace_callback(
        '/Realized P\/L:\s*([+-]?[\d.]+)/',
        function() use ($reversedRealized) { return 'Realized P/L: ' . ($reversedRealized>=0?'+':'') . money($reversedRealized); },
        $note
    );
    db()->prepare("UPDATE transactions SET note=? WHERE id=?")->execute([$fixedNote, $newId]);

    // Ledger: mirror of postStockSell() reversed
    postLedgerPair($newId, $today, $fixedNote, ['bucket'=>'PORTFOLIO'], ['account_id'=>$accId], toBHD($costNative, $cur) > 0 ? toBHD($costNative, $cur) : 0.0001);
    $realizedBHD = toBHD($realized, $cur);
    if (abs($realizedBHD) > 0.0001) {
        if ($realizedBHD > 0) postLedgerPair($newId, $today, $fixedNote.' (reversing realized gain)', ['bucket'=>'EQUITY'], ['account_id'=>$accId], $realizedBHD);
        else                  postLedgerPair($newId, $today, $fixedNote.' (reversing realized loss)', ['account_id'=>$accId], ['bucket'=>'EQUITY'], -$realizedBHD);
    }

    $trade = parseTradeNote($t['note'] ?? '');
    if (!$trade) return 'This was a stock sale, but its note doesn\'t match the expected format — cash and the ledger are corrected, but you\'ll need to manually check the portfolio holding.';

    if (hasLaterTrade($accId, $trade['symbol'], (int)$t['id'])) {
        return "This was a sale of {$trade['symbol']}, but other trades happened on this holding afterward — restoring the quantity automatically isn't safe. Cash and the ledger are corrected; fix the {$trade['symbol']} quantity manually via Trade Stock or Portfolio.";
    }

    $pSt = db()->prepare("SELECT * FROM portfolio WHERE account_id=? AND UPPER(symbol)=? LIMIT 1");
    $pSt->execute([$accId, $trade['symbol']]);
    $holding = $pSt->fetch();
    if ($holding) {
        // Sells don't change avg_cost of what remains, so restoring quantity
        // at the holding's current avg_cost is the correct inverse.
        db()->prepare("UPDATE portfolio SET quantity=quantity+? WHERE id=?")->execute([$trade['qty'], $holding['id']]);
    } else {
        // Holding was fully sold and removed — recreate it at the trade's own cost basis.
        $avgCost = $trade['price'] - ($realized / max(0.0001, $trade['qty']));
        db()->prepare("INSERT INTO portfolio (symbol,company_name,market,exchange,quantity,avg_cost,currency,current_price,account_id) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$trade['symbol'], $trade['symbol'], 'BD', 'DSE', $trade['qty'], $avgCost, $cur, $trade['price'], $accId]);
    }
    return null; // fully auto-corrected, no warning needed
}

/**
 * Fixed assets are discrete items (one specific car, one specific plot
 * of land), not a fungible quantity like shares — so reversal is
 * simpler than stocks: no weighted-average math, just "does this
 * asset's current status make it safe to auto-correct."
 *
 * trade_fixed_asset.php writes transaction notes containing
 * "[asset#123]" so reversal can find the exact row with certainty —
 * no name/symbol matching ambiguity like stocks have.
 */
function reverseAssetPurchaseLedgerAndHolding(array $t, int $newId, string $today, string $note, float $amt, string $cur): ?string {
    $accId = (int)$t['account_id'];
    postLedgerPair($newId, $today, $note, ['account_id'=>$accId], ['bucket'=>'FIXED_ASSET'], toBHD($amt, $cur));

    if (!preg_match('/\[asset#(\d+)\]/', $t['note'] ?? '', $m)) {
        return 'This was a fixed asset purchase, but its note doesn\'t reference an asset record — cash and the ledger are corrected, but you\'ll need to manually check Fixed Assets.';
    }
    $assetId = (int)$m[1];
    $aSt = db()->prepare("SELECT * FROM fixed_assets WHERE id=?");
    $aSt->execute([$assetId]);
    $asset = $aSt->fetch();
    if (!$asset) return "This was a fixed asset purchase, but asset #{$assetId} no longer exists — cash and the ledger are corrected; check Fixed Assets manually.";
    if ($asset['status'] !== 'owned') {
        return "This was a purchase of \"{$asset['name']}\", but it's no longer marked owned (status: {$asset['status']}) — unsafe to auto-correct. Cash and the ledger are corrected; check Fixed Assets manually.";
    }
    // Marked reversed, not deleted — same "never erase history" reasoning as transactions.
    db()->prepare("UPDATE fixed_assets SET status='reversed' WHERE id=?")->execute([$assetId]);
    return null;
}

function reverseAssetSaleLedgerAndHolding(array $t, int $newId, string $today, string $note, float $proceeds, string $cur): ?string {
    $accId = (int)$t['account_id'];
    $realized = 0.0;
    if (preg_match('/Realized P\/L:\s*([+-]?[\d.]+)/', $t['note'] ?? '', $m)) $realized = (float)$m[1];
    $costNative = $proceeds - $realized;

    // Same note-sign fix as the stock sale reversal — flip the number so
    // it doesn't double-count the original gain/loss when reports parse it.
    $reversedRealized = -$realized;
    $fixedNote = preg_replace_callback(
        '/Realized P\/L:\s*([+-]?[\d.]+)/',
        function() use ($reversedRealized) { return 'Realized P/L: ' . ($reversedRealized>=0?'+':'') . money($reversedRealized); },
        $note
    );
    db()->prepare("UPDATE transactions SET note=? WHERE id=?")->execute([$fixedNote, $newId]);

    postLedgerPair($newId, $today, $fixedNote, ['bucket'=>'FIXED_ASSET'], ['account_id'=>$accId], toBHD($costNative,$cur) > 0 ? toBHD($costNative,$cur) : 0.0001);
    $realizedBHD = toBHD($realized, $cur);
    if (abs($realizedBHD) > 0.0001) {
        if ($realizedBHD > 0) postLedgerPair($newId, $today, $fixedNote.' (reversing realized gain)', ['bucket'=>'EQUITY'], ['account_id'=>$accId], $realizedBHD);
        else                  postLedgerPair($newId, $today, $fixedNote.' (reversing realized loss)', ['account_id'=>$accId], ['bucket'=>'EQUITY'], -$realizedBHD);
    }

    if (!preg_match('/\[asset#(\d+)\]/', $t['note'] ?? '', $m2)) {
        return 'This was a fixed asset sale, but its note doesn\'t reference an asset record — cash and the ledger are corrected, but you\'ll need to manually check Fixed Assets.';
    }
    $assetId = (int)$m2[1];
    $aSt = db()->prepare("SELECT * FROM fixed_assets WHERE id=?");
    $aSt->execute([$assetId]);
    $asset = $aSt->fetch();
    if (!$asset) return "This was a fixed asset sale, but asset #{$assetId} no longer exists — cash and the ledger are corrected; check Fixed Assets manually.";
    if ($asset['status'] !== 'sold') {
        return "This was a sale of \"{$asset['name']}\", but it's not currently marked sold (status: {$asset['status']}) — unsafe to auto-restore. Cash and the ledger are corrected; check Fixed Assets manually.";
    }
    db()->prepare("UPDATE fixed_assets SET status='owned', current_value=?, sold_date=NULL, sold_price=NULL WHERE id=?")->execute([$costNative, $assetId]);
    return null;
}
