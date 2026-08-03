<?php
/**
 * Monthly Comparison
 * -------------------------------------------------------------------------
 * Compares two months (or "as of now" for the current month) side by side:
 * Summary KPIs, Cash Flow, and a per-account breakdown grouped by
 * account_groups — exactly like the rest of the app groups accounts.
 *
 * AUDITABILITY — this is the whole point of the page, so read this before
 * changing the math:
 *
 *   - Account balances are NOT read from a cached snapshot. They're
 *     reconstructed the same way account_detail.php's ledger view does:
 *     take the account's current stored balance, and walk every
 *     transaction that ever touched it backwards to "true opening", then
 *     forward again up to the requested cutoff date. That means every
 *     number on this page traces back to real rows in `transactions`,
 *     and every account cell links to account_detail.php?id=X&month=Y-m
 *     so you can see the exact list of transactions behind it.
 *   - Income / Expense are a live SUM(amount_bhd) over the month's date
 *     range, and link to transactions.php?month=Y-m&type=... for the
 *     same reason.
 *   - Portfolio and Fixed Assets have no per-transaction ledger (share
 *     prices/valuations aren't historized anywhere in this app yet), so
 *     those two lines fall back to the monthly `networth_history` cron
 *     snapshot for past months, or the live current value for the
 *     current month. If no snapshot exists for a past month, it's shown
 *     as "—" rather than guessed at.
 */
require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$pageTitle  = 'Monthly Comparison';
$activePage = 'reports';
$backTo     = 'reports.php';

// ═══════════════════════════════════════════════════════════════════════
// 1. Figure out which months are selectable, and which two are picked
// ═══════════════════════════════════════════════════════════════════════

$currentMonthKey = date('Y-m');

$earliestRow = db()->query("SELECT MIN(txn_date) AS d FROM transactions")->fetch();
$earliestKey = $earliestRow && $earliestRow['d'] ? date('Y-m', strtotime($earliestRow['d'])) : $currentMonthKey;

$availableMonths = [];
$cursor = $currentMonthKey . '-01';
while (true) {
    $key = date('Y-m', strtotime($cursor));
    $availableMonths[] = $key;
    if ($key <= $earliestKey) break;
    $cursor = date('Y-m-01', strtotime($cursor . ' -1 month'));
    if (count($availableMonths) > 60) break; // hard safety cap (5 years)
}
// $availableMonths is newest-first; keep it that way for the <select>.

$monthBKey = $_GET['monthB'] ?? $currentMonthKey;
$monthAKey = $_GET['monthA'] ?? date('Y-m', strtotime($monthBKey . '-01 -1 month'));
if (!in_array($monthBKey, $availableMonths, true)) $monthBKey = $currentMonthKey;
if (!in_array($monthAKey, $availableMonths, true)) $monthAKey = date('Y-m', strtotime($monthBKey . '-01 -1 month'));

function monthLabel(string $key): string {
    return date('F Y', strtotime($key . '-01'));
}
function monthShortLabel(string $key): string {
    return date('M Y', strtotime($key . '-01'));
}

// ═══════════════════════════════════════════════════════════════════════
// 2. Load accounts + account_groups once
// ═══════════════════════════════════════════════════════════════════════

$accounts = db()->query(
    "SELECT id, name, balance, currency, type, is_credit_card, group_name
     FROM accounts WHERE is_active=1 ORDER BY group_name, name"
)->fetchAll();

$groupDefs = db()->query("SELECT name, sort_order FROM account_groups ORDER BY sort_order, name")->fetchAll();
$groupOrder = [];
foreach ($groupDefs as $g) $groupOrder[$g['name']] = (int)$g['sort_order'];

// ═══════════════════════════════════════════════════════════════════════
// 3. Pull every transaction once, bucket by account for the ledger walk
// ═══════════════════════════════════════════════════════════════════════

$allTxns = db()->query(
    "SELECT id, account_id, to_account_id, type, amount, currency, txn_date
     FROM transactions ORDER BY txn_date ASC, id ASC"
)->fetchAll();

function txnEffect(array $t, int $accountId): float {
    $amt = (float)$t['amount'];
    if ((int)$t['account_id'] === $accountId) {
        if ($t['type'] === 'income')   return  $amt;
        if ($t['type'] === 'expense')  return -$amt;
        if ($t['type'] === 'transfer') return -$amt; // leaving as the source
    }
    if (!empty($t['to_account_id']) && (int)$t['to_account_id'] === $accountId && $t['type'] === 'transfer') {
        return $amt; // arriving as the destination
    }
    return 0.0;
}

$txnsByAccount = [];
foreach ($allTxns as $t) {
    if ($t['account_id']) $txnsByAccount[(int)$t['account_id']][] = $t;
    if (!empty($t['to_account_id']) && $t['type'] === 'transfer') {
        $txnsByAccount[(int)$t['to_account_id']][] = $t;
    }
}

/**
 * Native-currency balance of $account as of $cutoffDateTime (inclusive),
 * reconstructed from the current stored balance minus every transaction
 * effect after that cutoff — the exact same math account_detail.php uses.
 */
function balanceAsOf(array $account, array $txnsByAccount, string $cutoffDateTime): float {
    $id = (int)$account['id'];
    $current = (float)$account['balance'];
    $txns = $txnsByAccount[$id] ?? [];

    $netAll = 0.0;
    $netUpToCutoff = 0.0;
    foreach ($txns as $t) {
        $effect = txnEffect($t, $id);
        if ($effect === 0.0) continue;
        $netAll += $effect;
        if ($t['txn_date'] <= $cutoffDateTime) $netUpToCutoff += $effect;
    }
    $trueOpening = $current - $netAll;
    return $trueOpening + $netUpToCutoff;
}

// ═══════════════════════════════════════════════════════════════════════
// 4. Build a full snapshot (summary + cash flow + per-account) for one month
// ═══════════════════════════════════════════════════════════════════════

function buildMonthSnapshot(
    string $monthKey,
    array $accounts,
    array $txnsByAccount,
    string $currentMonthKey
): array {
    $isCurrent = ($monthKey === $currentMonthKey);
    $monthStart = $monthKey . '-01 00:00:00';
    $cutoff = $isCurrent ? date('Y-m-d H:i:s') : date('Y-m-t 23:59:59', strtotime($monthKey . '-01'));
    $rangeEndForCashFlow = $isCurrent ? date('Y-m-d H:i:s') : date('Y-m-t 23:59:59', strtotime($monthKey . '-01'));

    // ---- Per-account balances as of cutoff ----
    $accountBalances = []; // id => ['native'=>..,'bhd'=>..,'isLiability'=>bool]
    $totalAssets = 0.0;
    $totalLiab = 0.0;
    foreach ($accounts as $acc) {
        $id = (int)$acc['id'];
        $native = balanceAsOf($acc, $txnsByAccount, $cutoff);
        $bhd = toBHD($native, $acc['currency']);
        // isLiability drives asset/liability bucketing and the "(liability)"
        // label only. It is NOT a sentiment-invert flag: liability balances
        // are stored signed (negative = debt), so a diff that makes one more
        // negative is already correctly "bad" under normal sentiment rules —
        // inverting it here would flip debt increases to green. See the
        // Liabilities row comment above for the same reasoning.
        $isLiability = (bool)$acc['is_credit_card'] || $acc['type'] === 'liability';
        $accountBalances[$id] = ['native' => $native, 'bhd' => $bhd, 'isLiability' => $isLiability, 'currency' => $acc['currency']];
        if ($isLiability) $totalLiab += $bhd; else $totalAssets += $bhd;
    }

    // ---- Portfolio / Fixed Assets: live for the current month, snapshot otherwise ----
    $portfolioBHD = null;
    $fixedAssetsBHD = null;
    $valuationSource = null; // 'live' | 'snapshot' | null

    if ($isCurrent) {
        $portfolioBHD = 0.0;
        $port = db()->query(
            "SELECT currency, SUM(quantity*current_price) AS val FROM portfolio
             WHERE quantity>0 AND current_price>0 GROUP BY currency"
        )->fetchAll();
        foreach ($port as $p) $portfolioBHD += toBHD((float)$p['val'], $p['currency']);

        $fixedAssetsBHD = 0.0;
        try {
            $fas = db()->query(
                "SELECT currency, SUM(current_value) AS val FROM fixed_assets
                 WHERE current_value>0 GROUP BY currency"
            )->fetchAll();
            foreach ($fas as $f) $fixedAssetsBHD += toBHD((float)$f['val'], $f['currency']);
        } catch (Exception $e) {}
        $valuationSource = 'live';
    } else {
        try {
            $snap = db()->prepare(
                "SELECT portfolio, fixed_assets FROM networth_history
                 WHERE DATE_FORMAT(snapshot_date,'%Y-%m')=? LIMIT 1"
            );
            $snap->execute([$monthKey]);
            $row = $snap->fetch();
            if ($row) {
                $portfolioBHD = (float)$row['portfolio'];
                $fixedAssetsBHD = (float)$row['fixed_assets'];
                $valuationSource = 'snapshot';
            }
        } catch (Exception $e) {}
    }

    $netWorth = $totalAssets + $totalLiab; // liquid net worth — matches the dashboard's "Net Worth" card
    $totalWealth = ($portfolioBHD !== null && $fixedAssetsBHD !== null)
        ? $totalAssets + $portfolioBHD + $fixedAssetsBHD + $totalLiab
        : null;

    // ---- Cash flow for the month ----
    $cfSt = db()->prepare(
        "SELECT type, SUM(amount_bhd) AS total FROM transactions
         WHERE txn_date BETWEEN ? AND ? GROUP BY type"
    );
    $cfSt->execute([$monthStart, $rangeEndForCashFlow]);
    $income = 0.0; $expense = 0.0;
    foreach ($cfSt->fetchAll() as $r) {
        if ($r['type'] === 'income')  $income  = (float)$r['total'];
        if ($r['type'] === 'expense') $expense = (float)$r['total'];
    }
    $saved = $income - $expense;

    return [
        'key' => $monthKey,
        'label' => monthLabel($monthKey),
        'shortLabel' => monthShortLabel($monthKey),
        'isCurrent' => $isCurrent,
        'cutoff' => $cutoff,
        'valuationSource' => $valuationSource,
        'accountBalances' => $accountBalances,
        'totalAssets' => $totalAssets,
        'totalLiab' => $totalLiab,
        'portfolioBHD' => $portfolioBHD,
        'fixedAssetsBHD' => $fixedAssetsBHD,
        'netWorth' => $netWorth,
        'totalWealth' => $totalWealth,
        'income' => $income,
        'expense' => $expense,
        'saved' => $saved,
    ];
}

$snapA = buildMonthSnapshot($monthAKey, $accounts, $txnsByAccount, $currentMonthKey);
$snapB = buildMonthSnapshot($monthBKey, $accounts, $txnsByAccount, $currentMonthKey);

// ═══════════════════════════════════════════════════════════════════════
// 5. Small shared helpers for rendering diffs consistently
// ═══════════════════════════════════════════════════════════════════════

/**
 * @return array{diff:float,pct:?float,sentiment:string,arrow:string}
 */
function computeChange(?float $a, ?float $b, bool $invert = false): array {
    if ($a === null || $b === null) {
        return ['diff' => null, 'pct' => null, 'sentiment' => 'neutral', 'arrow' => '·'];
    }
    $diff = round($b - $a, 3);
    $pct = ($a != 0.0) ? (($b - $a) / $a) * 100 : ($b == 0.0 ? 0.0 : null);
    if ($diff === 0.0) {
        $sentiment = 'neutral';
    } else {
        $isIncrease = $diff > 0;
        $isGood = $invert ? !$isIncrease : $isIncrease;
        $sentiment = $isGood ? 'positive' : 'negative';
    }
    $arrow = $sentiment === 'positive' ? '▲' : ($sentiment === 'negative' ? '▼' : '·');
    return ['diff' => $diff, 'pct' => $pct, 'sentiment' => $sentiment, 'arrow' => $arrow];
}

function sentimentClass(string $sentiment): string {
    if ($sentiment === 'positive') return 'c-green';
    if ($sentiment === 'negative') return 'c-red';
    return 'c-muted';
}

function fmtDiff(?float $diff, string $currency = 'BHD'): string {
    if ($diff === null) return '—';
    if ($diff == 0.0) return money(0, $currency);
    $sign = $diff > 0 ? '+' : '';
    return $sign . money($diff, $currency);
}

function fmtPct(?float $pct): string {
    if ($pct === null) return '—';
    $sign = $pct > 0 ? '+' : ($pct < 0 ? '' : '');
    return $sign . number_format($pct, 2) . '%';
}

function fmtOrDash(?float $v, string $currency = 'BHD'): string {
    return $v === null ? '—' : money($v, $currency);
}

// ═══════════════════════════════════════════════════════════════════════
// 6. CSV export — must run before any HTML output
// ═══════════════════════════════════════════════════════════════════════

if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="monthly-comparison_' . $monthAKey . '_vs_' . $monthBKey . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Metric', $snapA['shortLabel'], $snapB['shortLabel'], 'Difference', '% Change']);

    $exportRows = [
        ['Total Wealth', $snapA['totalWealth'], $snapB['totalWealth'], false],
        ['Total Assets (Bank & Cash)', $snapA['totalAssets'], $snapB['totalAssets'], false],
        ['Portfolio', $snapA['portfolioBHD'], $snapB['portfolioBHD'], false],
        ['Liabilities', $snapA['totalLiab'], $snapB['totalLiab'], true],
        ['Net Worth', $snapA['netWorth'], $snapB['netWorth'], false],
        ['Income', $snapA['income'], $snapB['income'], false],
        ['Expense', $snapA['expense'], $snapB['expense'], true],
        ['Saved', $snapA['saved'], $snapB['saved'], false],
    ];
    foreach ($exportRows as [$label, $a, $b, $invert]) {
        $ch = computeChange($a, $b, $invert);
        fputcsv($out, [$label, fmtOrDash($a), fmtOrDash($b), fmtDiff($ch['diff']), fmtPct($ch['pct'])]);
    }
    foreach ($accounts as $acc) {
        $id = (int)$acc['id'];
        $a = $snapA['accountBalances'][$id]['bhd'] ?? null;
        $b = $snapB['accountBalances'][$id]['bhd'] ?? null;
        // Signed balance (negative = debt for liabilities) — invert always false, see note above.
        $ch = computeChange($a, $b, false);
        fputcsv($out, [
            ($acc['group_name'] ?: 'Other') . ' — ' . $acc['name'],
            fmtOrDash($a), fmtOrDash($b), fmtDiff($ch['diff']), fmtPct($ch['pct']),
        ]);
    }
    fclose($out);
    exit;
}

require 'header.php';
?>

<div class="no-print" style="display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:14px;margin-bottom:18px;">
  <div>
    <div style="font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;">Reports</div>
    <h1 style="font-size:1.4rem;font-weight:700;margin:2px 0 4px;">Monthly Comparison</h1>
    <div style="font-size:.85rem;color:var(--muted);">
      Comparing <span style="color:var(--text);font-weight:600;"><?=htmlspecialchars($snapA['label'])?></span>
      vs <span style="color:var(--text);font-weight:600;"><?=htmlspecialchars($snapB['label'])?></span>
      <?php if ($snapB['isCurrent']): ?><span class="badge badge-tra" style="margin-left:6px;">as of today</span><?php endif; ?>
    </div>
  </div>

  <form method="GET" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
    <select class="fc" name="monthA" style="width:150px;" onchange="this.form.submit()">
      <?php foreach ($availableMonths as $k): ?>
      <option value="<?=$k?>" <?=$k === $monthAKey ? 'selected' : ''?>><?=monthLabel($k)?></option>
      <?php endforeach; ?>
    </select>

    <a href="?monthA=<?=urlencode($monthBKey)?>&amp;monthB=<?=urlencode($monthAKey)?>" title="Swap months"
       class="btn btn-ghost btn-sm" style="padding:9px 11px;text-decoration:none;">⇄</a>

    <select class="fc" name="monthB" style="width:150px;" onchange="this.form.submit()">
      <?php foreach ($availableMonths as $k): ?>
      <option value="<?=$k?>" <?=$k === $monthBKey ? 'selected' : ''?>><?=monthLabel($k)?></option>
      <?php endforeach; ?>
    </select>

    <a href="?monthA=<?=urlencode($monthAKey)?>&amp;monthB=<?=urlencode($monthBKey)?>&amp;export=1" class="btn btn-primary btn-sm">⬇️ Export CSV</a>
  </form>
</div>

<!-- ═══════════════ Summary metric cards ═══════════════ -->
<div class="g4" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;">
  <?php
  $cards = [
      ['label' => 'Total Wealth',  'a' => $snapA['totalWealth'], 'b' => $snapB['totalWealth'], 'invert' => false],
      ['label' => 'Net Worth',     'a' => $snapA['netWorth'],    'b' => $snapB['netWorth'],    'invert' => false],
      ['label' => 'Total Assets',  'a' => $snapA['totalAssets'], 'b' => $snapB['totalAssets'], 'invert' => false],
      // NOTE: totalLiab is a *signed* balance (negative = debt), not a positive
      // magnitude — a diff that makes it MORE negative is already "bad" under
      // normal (non-inverted) sentiment rules, so invert stays false here.
      // (Only positive-magnitude "cost" metrics like Expense need invert=true.)
      ['label' => 'Liabilities',   'a' => $snapA['totalLiab'],   'b' => $snapB['totalLiab'],   'invert' => false],
  ];
  foreach ($cards as $c):
      $ch = computeChange($c['a'], $c['b'], $c['invert']);
  ?>
  <div class="card">
    <div class="card-title"><?=$c['label']?></div>
    <div class="card-value" data-hide="true"><?=fmtOrDash($c['b'])?></div>
    <div class="card-sub" data-hide="true">
      <?=monthShortLabel($monthAKey)?>: <?=fmtOrDash($c['a'])?>
      &nbsp;·&nbsp;
      <span class="<?=sentimentClass($ch['sentiment'])?>" style="font-weight:600;">
        <?=$ch['arrow']?> <?=fmtDiff($ch['diff'])?> (<?=fmtPct($ch['pct'])?>)
      </span>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<style>@media(max-width:800px){.g4{grid-template-columns:repeat(2,1fr) !important;}}@media(max-width:480px){.g4{grid-template-columns:1fr !important;}}</style>

<!-- ═══════════════ Summary table ═══════════════ -->
<div class="card" style="padding:0;overflow:hidden;margin-bottom:16px;">
  <div style="padding:12px 16px;border-bottom:1px solid var(--s3);font-weight:600;">⚖️ Summary</div>
  <div class="tbl-wrap" style="overflow-x:auto;">
  <table class="tbl">
    <tr>
      <th>Metric</th>
      <th style="text-align:right;"><?=monthShortLabel($monthAKey)?></th>
      <th style="text-align:right;"><?=monthShortLabel($monthBKey)?></th>
      <th style="text-align:right;">Difference</th>
      <th style="text-align:right;">% Change</th>
    </tr>
    <?php
    $summaryRows = [
        ['Total Wealth', $snapA['totalWealth'], $snapB['totalWealth'], false, null],
        ['Total Assets (Bank & Cash)', $snapA['totalAssets'], $snapB['totalAssets'], false, 'accounts.php'],
        ['Portfolio' . ($snapB['valuationSource'] === 'snapshot' ? ' <span class="c-muted" style="font-weight:400;font-size:.72rem;">(monthly snapshot)</span>' : ''), $snapA['portfolioBHD'], $snapB['portfolioBHD'], false, 'portfolio.php'],
        ['Liabilities', $snapA['totalLiab'], $snapB['totalLiab'], false, null],
    ];
    foreach ($summaryRows as [$label, $a, $b, $invert, $link]):
        $ch = computeChange($a, $b, $invert);
    ?>
    <tr>
      <td><?php if ($link): ?><a href="<?=$link?>"><?=$label?></a><?php else: ?><?=$label?><?php endif; ?></td>
      <td style="text-align:right;" data-hide="true"><?=fmtOrDash($a)?></td>
      <td style="text-align:right;" data-hide="true"><?=fmtOrDash($b)?></td>
      <td style="text-align:right;" class="<?=sentimentClass($ch['sentiment'])?>" data-hide="true"><?=fmtDiff($ch['diff'])?></td>
      <td style="text-align:right;" class="<?=sentimentClass($ch['sentiment'])?>" data-hide="true"><?=$ch['arrow']?> <?=fmtPct($ch['pct'])?></td>
    </tr>
    <?php endforeach; ?>
    <?php $chNW = computeChange($snapA['netWorth'], $snapB['netWorth'], false); ?>
    <tr style="font-weight:700;border-top:2px solid var(--s3);">
      <td>Net Worth</td>
      <td style="text-align:right;" data-hide="true"><?=fmtOrDash($snapA['netWorth'])?></td>
      <td style="text-align:right;" data-hide="true"><?=fmtOrDash($snapB['netWorth'])?></td>
      <td style="text-align:right;" class="<?=sentimentClass($chNW['sentiment'])?>" data-hide="true"><?=fmtDiff($chNW['diff'])?></td>
      <td style="text-align:right;" class="<?=sentimentClass($chNW['sentiment'])?>" data-hide="true"><?=$chNW['arrow']?> <?=fmtPct($chNW['pct'])?></td>
    </tr>
  </table>
  </div>
  <?php if ($snapA['valuationSource'] === null || $snapB['valuationSource'] === null): ?>
  <div style="padding:10px 16px;font-size:.78rem;color:var(--muted);border-top:1px solid var(--s3);">
    ℹ️ No <code>networth_history</code> snapshot found for one of the selected months, so Portfolio / Total Wealth show
    "—" for it rather than a guess. Snapshots are recorded automatically on the 1st of each month.
  </div>
  <?php endif; ?>
</div>

<!-- ═══════════════ Cash flow table ═══════════════ -->
<div class="card" style="padding:0;overflow:hidden;margin-bottom:16px;">
  <div style="padding:12px 16px;border-bottom:1px solid var(--s3);font-weight:600;">💵 Cash Flow Comparison</div>
  <div class="tbl-wrap" style="overflow-x:auto;">
  <table class="tbl">
    <tr>
      <th>Metric</th>
      <th style="text-align:right;"><?=monthShortLabel($monthAKey)?></th>
      <th style="text-align:right;"><?=monthShortLabel($monthBKey)?></th>
      <th style="text-align:right;">Difference</th>
      <th style="text-align:right;">% Change</th>
    </tr>
    <?php
    $cashFlowRows = [
        ['income',  'Income',  $snapA['income'],  $snapB['income'],  false],
        ['expense', 'Expense', $snapA['expense'], $snapB['expense'], true],
    ];
    foreach ($cashFlowRows as [$typeKey, $label, $a, $b, $invert]):
        $ch = computeChange($a, $b, $invert);
    ?>
    <tr>
      <td>
        <?=$label?>
        <a href="transactions.php?month=<?=$monthAKey?>&type=<?=$typeKey?>" class="badge badge-tra" style="margin-left:6px;text-decoration:none;" title="View <?=$label?> transactions for <?=monthShortLabel($monthAKey)?>"><?=monthShortLabel($monthAKey)?></a>
        <a href="transactions.php?month=<?=$monthBKey?>&type=<?=$typeKey?>" class="badge badge-tra" style="text-decoration:none;" title="View <?=$label?> transactions for <?=monthShortLabel($monthBKey)?>"><?=monthShortLabel($monthBKey)?></a>
      </td>
      <td style="text-align:right;" data-hide="true"><?=fmtOrDash($a)?></td>
      <td style="text-align:right;" data-hide="true"><?=fmtOrDash($b)?></td>
      <td style="text-align:right;" class="<?=sentimentClass($ch['sentiment'])?>" data-hide="true"><?=fmtDiff($ch['diff'])?></td>
      <td style="text-align:right;" class="<?=sentimentClass($ch['sentiment'])?>" data-hide="true"><?=$ch['arrow']?> <?=fmtPct($ch['pct'])?></td>
    </tr>
    <?php endforeach; ?>
    <?php $chSaved = computeChange($snapA['saved'], $snapB['saved'], false); ?>
    <tr style="font-weight:700;border-top:2px solid var(--s3);">
      <td>Saved</td>
      <td style="text-align:right;" data-hide="true"><?=fmtOrDash($snapA['saved'])?></td>
      <td style="text-align:right;" data-hide="true"><?=fmtOrDash($snapB['saved'])?></td>
      <td style="text-align:right;" class="<?=sentimentClass($chSaved['sentiment'])?>" data-hide="true"><?=fmtDiff($chSaved['diff'])?></td>
      <td style="text-align:right;" class="<?=sentimentClass($chSaved['sentiment'])?>" data-hide="true"><?=$chSaved['arrow']?> <?=fmtPct($chSaved['pct'])?></td>
    </tr>
  </table>
  </div>
</div>

<!-- ═══════════════ Account Groups Breakdown (expandable, auditable) ═══════════════ -->
<div class="card" style="padding:0;overflow:hidden;margin-bottom:16px;">
  <div style="padding:12px 16px;border-bottom:1px solid var(--s3);display:flex;justify-content:space-between;align-items:center;">
    <span style="font-weight:600;">🏦 Account Groups Breakdown</span>
    <span style="font-size:.75rem;color:var(--muted);">Click any balance to see that account's ledger for that month</span>
  </div>
  <div class="tbl-wrap" style="overflow-x:auto;">
  <table class="tbl">
    <tr>
      <th>Account</th>
      <th style="text-align:right;"><?=monthShortLabel($monthAKey)?></th>
      <th style="text-align:right;"><?=monthShortLabel($monthBKey)?></th>
      <th style="text-align:right;">Difference</th>
      <th style="text-align:right;">% Change</th>
    </tr>
    <?php
    // Group accounts exactly like accounts.php does: by group_name, "Other" fallback.
    $grouped = [];
    foreach ($accounts as $acc) {
        $g = $acc['group_name'] ?: 'Other';
        $grouped[$g][] = $acc;
    }
    // Order groups by account_groups.sort_order, unknown groups last alphabetically.
    uksort($grouped, function ($x, $y) use ($groupOrder) {
        $ox = $groupOrder[$x] ?? 999;
        $oy = $groupOrder[$y] ?? 999;
        return $ox <=> $oy ?: strcmp($x, $y);
    });

    $gi = 0;
    foreach ($grouped as $groupName => $groupAccounts):
        $gi++;
        $gid = 'grp' . $gi;
        $subA = 0.0; $subB = 0.0;
        $rowsHtml = '';
        foreach ($groupAccounts as $acc) {
            $id = (int)$acc['id'];
            $subA += $snapA['accountBalances'][$id]['bhd'] ?? 0.0;
            $subB += $snapB['accountBalances'][$id]['bhd'] ?? 0.0;
        }
        // Group subtotal is a signed BHD sum — same reasoning as totalLiab: invert always false.
        $chGroup = computeChange($subA, $subB, false);
    ?>
    <tr onclick="toggleComparisonGroup('<?=$gid?>')" style="cursor:pointer;background:#00000022;font-weight:600;">
      <td><span class="grp-caret" id="<?=$gid?>-caret">▾</span> <?=htmlspecialchars($groupName)?> <span class="c-muted" style="font-weight:400;font-size:.75rem;">(<?=count($groupAccounts)?>)</span></td>
      <td style="text-align:right;" data-hide="true"><?=money($subA)?></td>
      <td style="text-align:right;" data-hide="true"><?=money($subB)?></td>
      <td style="text-align:right;" class="<?=sentimentClass($chGroup['sentiment'])?>" data-hide="true"><?=fmtDiff($chGroup['diff'])?></td>
      <td style="text-align:right;" class="<?=sentimentClass($chGroup['sentiment'])?>" data-hide="true"><?=$chGroup['arrow']?> <?=fmtPct($chGroup['pct'])?></td>
    </tr>
    <tbody id="<?=$gid?>" style="display:table-row-group;">
    <?php foreach ($groupAccounts as $acc):
        $id = (int)$acc['id'];
        $a = $snapA['accountBalances'][$id]['bhd'] ?? null;
        $b = $snapB['accountBalances'][$id]['bhd'] ?? null;
        $isLiability = $snapA['accountBalances'][$id]['isLiability'] ?? false;
        // Signed balance (negative = debt for liabilities) — invert always false, see note above.
        $ch = computeChange($a, $b, false);
        $linkA = 'account_detail.php?id=' . $id . '&month=' . $monthAKey;
        $linkB = 'account_detail.php?id=' . $id . '&month=' . $monthBKey;
    ?>
    <tr>
      <td style="padding-left:32px;">
        <?=htmlspecialchars($acc['name'])?>
        <?php if ($isLiability): ?><span class="c-muted" style="font-size:.7rem;">(liability)</span><?php endif; ?>
      </td>
      <td style="text-align:right;" data-hide="true"><a href="<?=$linkA?>" title="See <?=htmlspecialchars($acc['name'])?>'s ledger for <?=monthShortLabel($monthAKey)?>"><?=fmtOrDash($a)?></a></td>
      <td style="text-align:right;" data-hide="true"><a href="<?=$linkB?>" title="See <?=htmlspecialchars($acc['name'])?>'s ledger for <?=monthShortLabel($monthBKey)?>"><?=fmtOrDash($b)?></a></td>
      <td style="text-align:right;" class="<?=sentimentClass($ch['sentiment'])?>" data-hide="true"><?=fmtDiff($ch['diff'])?></td>
      <td style="text-align:right;" class="<?=sentimentClass($ch['sentiment'])?>" data-hide="true"><?=$ch['arrow']?> <?=fmtPct($ch['pct'])?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <?php endforeach; ?>
  </table>
  </div>
</div>

<p class="c-muted" style="font-size:.75rem;margin-top:4px;">
  Account balances are reconstructed from the transaction ledger as of
  <?=monthShortLabel($monthAKey)?>: <?=htmlspecialchars($snapA['cutoff'])?> and
  <?=monthShortLabel($monthBKey)?>: <?=htmlspecialchars($snapB['cutoff'])?>.
  Every account balance links to its ledger for that exact period.
</p>

<style>
.grp-collapsed { display: none !important; }
.grp-caret { display:inline-block; transition:transform .15s ease; }
.grp-caret.rotated { transform:rotate(-90deg); }
</style>
<script>
function toggleComparisonGroup(gid) {
  document.getElementById(gid).classList.toggle('grp-collapsed');
  document.getElementById(gid + '-caret').classList.toggle('rotated');
}
</script>

<?php require 'footer.php'; ?>
