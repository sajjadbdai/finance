<?php
/**
 * cc_check.php — READ-ONLY credit-card cycle check.  v2
 * Compares what the app currently stores against what the ledger says.
 * Writes nothing. Safe to run any time.
 *
 * Upload to /dashboard/ alongside cc_lib.php, then open:
 *   https://finance.sajjad.bd/dashboard/cc_check.php
 *   https://finance.sajjad.bd/dashboard/cc_check.php?id=<card id>
 *   https://finance.sajjad.bd/dashboard/cc_check.php?id=<card id>&asof=2026-08-20
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cc_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }

$pageTitle = 'Credit Card Check'; $activePage = 'accounts';

$id   = (int)($_GET['id'] ?? 0);
$asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['asof'] ?? '') ? $_GET['asof'] : date('Y-m-d');

$cards = db()->query(
    "SELECT id, name, currency FROM accounts
      WHERE is_active=1 AND COALESCE(is_credit_card,0)=1
      ORDER BY name"
)->fetchAll();

$all = $id ? [ccDerive($id, $asOf)] : ccDeriveAll($asOf);

require 'header.php';

function ccVerdict($diff, $cur) {
    if ($diff === null) return '<span style="color:var(--muted)">n/a</span>';
    if (abs($diff) < CC_EPS) return '<span style="color:var(--green)">match</span>';
    return '<span style="color:var(--red)">off by ' . ccMoney($diff, $cur) . '</span>';
}
function ccFmtDate($d) { return $d ? date('d M Y', strtotime($d)) : '—'; }
?>

<div class="card" style="margin-bottom:16px;">
  <div class="section-title" style="margin-bottom:10px;">Credit Card Cycle Check</div>
  <div style="font-size:.84rem;color:var(--muted);margin-bottom:14px;line-height:1.6;">
    Rebuilds <strong>Balance Payable</strong> and <strong>Outstanding Balance</strong> from the transaction
    ledger using each card's bill day. Charges only become payable once the statement is cut; a payment
    clears the statemented amount first and only the surplus touches the current cycle.
    <strong>Read-only — nothing is written.</strong>
  </div>
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <div class="form-group" style="margin:0;min-width:220px;">
      <label style="font-size:.75rem;color:var(--muted);">Card</label>
      <select class="form-control" name="id">
        <option value="">— All credit cards —</option>
        <?php foreach ($cards as $c): ?>
          <option value="<?=$c['id']?>" <?=$id==$c['id']?'selected':''?>>
            <?=htmlspecialchars($c['name'])?> (<?=htmlspecialchars($c['currency'])?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label style="font-size:.75rem;color:var(--muted);">As of</label>
      <input class="form-control" type="date" name="asof" value="<?=htmlspecialchars($asOf)?>">
    </div>
    <div class="form-group" style="margin:0;">
      <button class="btn btn-primary" type="submit">Run</button>
    </div>
  </form>
  <div style="font-size:.78rem;color:var(--muted);margin-top:10px;">
    Tip: set <em>As of</em> to a date just after the next bill day to preview what rolls into Payable.
  </div>
</div>

<?php foreach ($all as $d):
    if (empty($d['ok'])) {
        echo '<div class="card" style="margin-bottom:16px;color:var(--red);">'
           . htmlspecialchars($d['error'] ?: 'Could not read card') . '</div>';
        continue;
    }
    $cur  = $d['currency'];
    $acc  = $d['account'];
    $mism = (abs((float)($d['payable_diff'] ?? 0)) >= CC_EPS)
         || (abs((float)($d['outstanding_diff'] ?? 0)) >= CC_EPS);
?>
<div class="card" style="margin-bottom:18px;border-left:3px solid <?=$mism?'var(--red)':'var(--green)'?>;">

  <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;align-items:baseline;margin-bottom:4px;">
    <div style="font-size:1.05rem;font-weight:600;"><?=htmlspecialchars($acc['name'])?></div>
    <div style="font-size:.8rem;color:var(--muted);">
      Bill day <?=$d['bill_day'] ?: '—'?><?php if ($d['due_day']): ?> &nbsp;|&nbsp; Due day <?=$d['due_day']?><?php endif; ?>
    </div>
  </div>

  <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;line-height:1.7;">
    Last statement <strong style="color:var(--text)"><?=ccFmtDate($d['statement_date'])?></strong>
    <?php if ($d['due_date']): ?> &nbsp;·&nbsp; due <strong style="color:var(--text)"><?=ccFmtDate($d['due_date'])?></strong><?php endif; ?>
    <br>
    Next statement <strong style="color:var(--text)"><?=ccFmtDate($d['next_statement'])?></strong>
    — this is when the current cycle becomes payable.
    <br>Viewing as of <?=ccFmtDate($asOf)?>.
  </div>

  <?php if (!empty($d['error'])): ?>
    <div style="color:var(--orange);font-size:.82rem;margin-bottom:12px;"><?=htmlspecialchars($d['error'])?></div>
  <?php endif; ?>
  <?php foreach (($d['notes'] ?? []) as $n): ?>
    <div style="color:var(--orange);font-size:.8rem;margin-bottom:10px;">Note: <?=htmlspecialchars($n)?></div>
  <?php endforeach; ?>

  <table class="tbl" style="margin-bottom:16px;">
    <thead>
      <tr><th></th><th style="text-align:right">App shows now</th><th style="text-align:right">Should be</th><th style="text-align:right">Verdict</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Balance Payable</td>
        <td style="text-align:right" data-hide="true"><?=$d['stored_payable'] === null ? '—' : ccMoney($d['stored_payable'], $cur)?></td>
        <td style="text-align:right;font-weight:600;color:var(--red)" data-hide="true"><?=ccMoney($d['payable'], $cur)?></td>
        <td style="text-align:right"><?=ccVerdict($d['payable_diff'], $cur)?></td>
      </tr>
      <tr>
        <td>Outstanding Balance</td>
        <td style="text-align:right" data-hide="true"><?=$d['stored_outstanding'] === null ? '—' : ccMoney($d['stored_outstanding'], $cur)?></td>
        <td style="text-align:right;font-weight:600;color:var(--orange)" data-hide="true"><?=ccMoney($d['outstanding'], $cur)?></td>
        <td style="text-align:right"><?=ccVerdict($d['outstanding_diff'], $cur)?></td>
      </tr>
      <tr>
        <td>Card balance</td>
        <td style="text-align:right" data-hide="true"><?=ccMoney($d['stored_balance'], $cur)?></td>
        <td style="text-align:right;font-weight:600" data-hide="true"><?=ccMoney($d['ledger_balance'], $cur)?></td>
        <td style="text-align:right"><?=ccVerdict($d['balance_diff'], $cur)?></td>
      </tr>
      <?php if ($d['credit_balance'] > 0): ?>
      <tr>
        <td>Credit in your favour</td>
        <td style="text-align:right">—</td>
        <td style="text-align:right;color:var(--green)" data-hide="true"><?=ccMoney($d['credit_balance'], $cur)?></td>
        <td style="text-align:right"><span style="color:var(--muted)">overpaid</span></td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div style="font-size:.78rem;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;">Working</div>
  <table class="tbl" style="margin-bottom:16px;">
    <tbody>
      <tr><td>Opening balance <span style="color:var(--muted);font-size:.78rem;">(<?=htmlspecialchars($d['opening_source'])?>)</span></td>
          <td style="text-align:right" data-hide="true"><?=ccMoney($d['opening_balance'], $cur)?></td></tr>
      <tr><td>Charges up to <?=ccFmtDate($d['statement_date'])?> <span style="color:var(--muted);font-size:.78rem;">(excl. opening)</span></td>
          <td style="text-align:right" data-hide="true"><?=ccMoney($d['billed_charges'] - max(0, -$d['opening_balance']), $cur)?></td></tr>
      <tr><td>Payments up to <?=ccFmtDate($d['statement_date'])?></td>
          <td style="text-align:right" data-hide="true">− <?=ccMoney($d['billed_payments'] - max(0, $d['opening_balance']), $cur)?></td></tr>
      <tr style="font-weight:600;"><td>= Statement balance on <?=ccFmtDate($d['statement_date'])?></td>
          <td style="text-align:right" data-hide="true"><?=ccMoney($d['statement_balance'], $cur)?></td></tr>
      <tr><td>Payments made after the statement</td>
          <td style="text-align:right" data-hide="true">− <?=ccMoney($d['post_payments'], $cur)?></td></tr>
      <tr style="font-weight:600;color:var(--red)"><td>= Balance Payable</td>
          <td style="text-align:right" data-hide="true"><?=ccMoney($d['payable'], $cur)?></td></tr>

      <tr><td style="padding-top:16px;">New charges since the statement</td>
          <td style="text-align:right;padding-top:16px;" data-hide="true"><?=ccMoney($d['unbilled_charges'], $cur)?></td></tr>
      <tr><td>Payment surplus carried down</td>
          <td style="text-align:right" data-hide="true">− <?=ccMoney($d['surplus'], $cur)?></td></tr>
      <tr style="font-weight:600;color:var(--orange)"><td>= Outstanding Balance <span style="color:var(--muted);font-weight:400;font-size:.78rem;">(bills on <?=ccFmtDate($d['next_statement'])?>)</span></td>
          <td style="text-align:right" data-hide="true"><?=ccMoney($d['outstanding'], $cur)?></td></tr>
    </tbody>
  </table>

  <?php if ($d['rows']): ?>
  <details>
    <summary style="cursor:pointer;font-size:.82rem;color:var(--blue);margin-bottom:8px;">
      Show the <?=count($d['rows'])?> transactions behind this
    </summary>
    <table class="tbl">
      <thead>
        <tr><th>Date</th><th>Cycle</th><th>Kind</th><th>Category / note</th>
            <th style="text-align:right">Amount</th><th style="text-align:right">Running</th></tr>
      </thead>
      <tbody>
      <?php foreach ($d['rows'] as $r): ?>
        <tr>
          <td><?=ccFmtDate($r['date'])?></td>
          <td>
            <span class="badge" style="background:<?=$r['billed']?'rgba(231,76,60,.15)':'rgba(243,156,18,.15)'?>;color:<?=$r['billed']?'var(--red)':'var(--orange)'?>;">
              <?=$r['billed'] ? 'Billed' : 'Current'?>
            </span>
          </td>
          <td><?=htmlspecialchars($r['kind'])?></td>
          <td style="font-size:.82rem;color:var(--muted)">
            <?=htmlspecialchars(trim($r['category'] . ($r['note'] ? ' — ' . $r['note'] : '')))?>
          </td>
          <td style="text-align:right;color:<?=$r['delta']<0?'var(--red)':'var(--green)'?>" data-hide="true">
            <?=$r['delta']<0?'−':'+'?><?=ccMoney(abs($r['delta']), $cur)?>
          </td>
          <td style="text-align:right" data-hide="true"><?=ccMoney($r['running'], $cur)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </details>
  <?php endif; ?>

</div>
<?php endforeach; ?>

<?php if (file_exists(__DIR__ . '/footer.php')) require 'footer.php'; ?>
