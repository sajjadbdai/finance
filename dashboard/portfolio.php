<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Investment Portfolio'; $activePage='portfolio';

// Create table if not exists
try {
    db()->exec("CREATE TABLE IF NOT EXISTS portfolio (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT,
        symbol VARCHAR(20) NOT NULL,
        company_name VARCHAR(100) NOT NULL,
        market ENUM('BD','USA','UK','Crypto','Other') DEFAULT 'BD',
        quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
        avg_cost DECIMAL(15,4) NOT NULL DEFAULT 0,
        currency VARCHAR(10) DEFAULT 'BDT',
        current_price DECIMAL(15,4) DEFAULT 0,
        last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch(Exception $e){}

$msg=''; $error=''; $editItem=null;

// Delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    db()->prepare("DELETE FROM portfolio WHERE id=?")->execute([(int)$_GET['delete']]);
    header('Location: /dashboard/portfolio.php?msg=deleted'); exit;
}
// Edit load
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $st=db()->prepare("SELECT * FROM portfolio WHERE id=?"); $st->execute([(int)$_GET['edit']]);
    $editItem=$st->fetch();
}
$msg = $_GET['msg'] ?? '';

// Save
if (isset($_POST['do_save'])) {
    $sid      = (int)($_POST['sid'] ?? 0);
    $symbol   = strtoupper(trim($_POST['symbol']       ?? ''));
    $name     = trim($_POST['company_name']             ?? '');
    $market   = $_POST['market']                        ?? 'BD';
    $qty      = (float)($_POST['quantity']              ?? 0);
    $avgCost  = (float)($_POST['avg_cost']              ?? 0);
    $currency = $_POST['currency']                      ?? 'BDT';
    $curPrice = (float)($_POST['current_price']         ?? 0);
    $accId    = (int)($_POST['account_id']              ?? 0) ?: null;
    $notes    = trim($_POST['notes']                    ?? '');

    if (!$symbol || !$name) { $error='Symbol and name required.'; }
    else {
        if ($sid) {
            $exch = strtoupper($_POST['exchange'] ?? 'DSE');
            db()->prepare("UPDATE portfolio SET symbol=?,company_name=?,market=?,exchange=?,quantity=?,avg_cost=?,currency=?,current_price=?,account_id=?,notes=?,last_updated=NOW() WHERE id=?")
            ->execute([$symbol,$name,$market,$exch,$qty,$avgCost,$currency,$curPrice,$accId,$notes,$sid]);
        } else {
            $exch = strtoupper($_POST['exchange'] ?? 'DSE');
            db()->prepare("INSERT INTO portfolio (symbol,company_name,market,exchange,quantity,avg_cost,currency,current_price,account_id,notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$symbol,$name,$market,$exch,$qty,$avgCost,$currency,$curPrice,$accId,$notes]);
        }
        header('Location: /dashboard/portfolio.php?msg=saved'); exit;
    }
}

// Update price only
if (isset($_POST['update_price'])) {
    $pid   = (int)($_POST['pid']   ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    db()->prepare("UPDATE portfolio SET current_price=?,last_updated=NOW() WHERE id=?")->execute([$price,$pid]);
    header('Location: /dashboard/portfolio.php?msg=updated'); exit;
}

// Apply exchange/broker filter from dashboard click
$filterBroker = trim($_GET['broker'] ?? '');

// Build query - use INNER JOIN when filtering by broker to avoid NULL issues
if($filterBroker) {
    $stmt = db()->prepare("SELECT p.*,a.name as acc_name FROM portfolio p 
        INNER JOIN accounts a ON a.id=p.account_id 
        WHERE p.quantity > 0 AND a.name=?
        ORDER BY p.market,p.symbol");
    $stmt->execute([$filterBroker]);
    $holdings = $stmt->fetchAll();
} else {
    $holdings = db()->query("SELECT p.*,a.name as acc_name FROM portfolio p 
        LEFT JOIN accounts a ON a.id=p.account_id 
        WHERE p.quantity > 0 
        ORDER BY p.market,p.symbol")->fetchAll();
}
$accounts = db()->query("SELECT id,name,currency FROM accounts WHERE is_active=1 AND group_name IN ('Investments','Other Currencies Account') ORDER BY name")->fetchAll();

// Calculate totals by market
$markets=['BD'=>[],'USA'=>[],'UK'=>[],'Crypto'=>[],'Other'=>[]];
$totalCostBHD=0; $totalValueBHD=0;
foreach($holdings as $h) {
    $cost    = (float)$h['quantity'] * (float)$h['avg_cost'];
    $value   = (float)$h['quantity'] * (float)$h['current_price'];
    $pl      = $value - $cost;
    $plPct   = $cost > 0 ? round($pl/$cost*100,2) : 0;
    $costBHD = toBHD($cost, $h['currency']);
    $valBHD  = toBHD($value, $h['currency']);
    $totalCostBHD  += $costBHD;
    $totalValueBHD += $valBHD;
    $markets[$h['market']][] = array_merge($h,compact('cost','value','pl','plPct','costBHD','valBHD'));
}

require 'header.php';
?>
<?php if($msg==='saved'):?><div class="alert alert-success">✅ Saved!</div><?php endif;?>
<?php if($msg==='deleted'):?><div class="alert alert-danger">🗑 Deleted.</div><?php endif;?>
<?php if($msg==='updated'):?><div class="alert alert-success">✅ Price updated!</div><?php endif;?>
<?php if($error):?><div class="alert alert-danger">❌ <?=htmlspecialchars($error)?></div><?php endif;?>

<!-- Price Update Modal -->
<div id="price-modal" style="display:none;position:fixed;inset:0;background:#00000090;z-index:1000;overflow-y:auto;padding:20px;">
  <div style="background:var(--s1);border-radius:14px;max-width:600px;margin:20px auto;padding:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <div style="font-weight:700;font-size:1rem;">📈 Price Update Preview</div>
      <button onclick="closeModal()" style="background:none;border:none;color:var(--muted);font-size:1.3rem;cursor:pointer;">✕</button>
    </div>
    <div id="modal-loading" style="text-align:center;padding:30px;">
      <div style="font-size:2rem;margin-bottom:10px;">⏳</div>
      <div id="modal-load-msg" style="color:var(--muted);">Loading stock list...</div>
    </div>
    <div id="modal-results" style="display:none;">
      <div id="price-table"></div>
      <div id="modal-errors" style="margin-top:10px;font-size:.8rem;color:var(--red);"></div>
      <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;align-items:center;">
        <button onclick="saveAllFetched()" class="btn btn-success" id="save-all-btn">✅ Save All Fetched</button>
        <button onclick="closeModal()" class="btn btn-ghost">✕ Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Export buttons -->
<div class="gap-2" style="margin-bottom:16px;">
  <?php if($filterBroker):?>
  <a href="portfolio.php" class="btn btn-ghost btn-sm">← All Holdings</a>
  <span style="color:var(--blue);font-weight:600;">📊 <?=htmlspecialchars($filterBroker)?></span>
  <?php endif;?>
  <button onclick="openPriceUpdate()" class="btn btn-primary btn-sm">🔄 Update Prices</button>
  <a href="import_portfolio_pdf.php" class="btn btn-success btn-sm">📧 Import from PDF</a>
  <a href="portfolio_export.php?format=pdf<?=($filterBroker?'&broker='.urlencode($filterBroker):'')?>" target="_blank" class="btn btn-ghost btn-sm">🖨️ Report</a>
  <a href="portfolio_export.php?format=csv<?=($filterBroker?'&broker='.urlencode($filterBroker):'')?>" class="btn btn-ghost btn-sm">⬇️ CSV</a>
</div>

<!-- Email Sync Modal -->
<div id="email-modal" style="display:none;position:fixed;inset:0;background:#00000090;z-index:1000;overflow-y:auto;padding:20px;">
  <div style="background:var(--s1);border-radius:14px;max-width:500px;margin:20px auto;padding:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <div style="font-weight:700;">📧 Sync from BRAC EPL Email</div>
      <button onclick="closeEmailModal()" style="background:none;border:none;color:var(--muted);font-size:1.3rem;cursor:pointer;">✕</button>
    </div>
    <div id="email-loading" style="text-align:center;padding:20px;">
      <div style="font-size:2rem;">⏳</div>
      <div style="color:var(--muted);margin-top:8px;">Reading latest portfolio emails...<br><small>BRAC EPL + Berich PDFs · auto-saving when done</small></div>
    </div>
    <div id="email-results" style="display:none;">
      <div id="email-table"></div>
      <div style="display:flex;gap:8px;margin-top:16px;align-items:center;">
        <button class="btn btn-success" id="email-save-btn" disabled>⏳ Auto-saving...</button>
        <button onclick="closeEmailModal()" class="btn btn-ghost">✕ Close</button>
      </div>
    </div>
    <div id="email-error" style="display:none;color:var(--red);padding:12px;"></div>
  </div>
</div>

<!-- Portfolio Summary -->
<div class="g3" style="margin-bottom:20px;">
  <div class="card"><div class="card-title">Total Cost</div><div class="card-value c-blue">BD <?=number_format($totalCostBHD,2)?></div></div>
  <div class="card"><div class="card-title">Current Value</div><div class="card-value c-blue">BD <?=number_format($totalValueBHD,2)?></div></div>
  <div class="card">
    <?php $totalPL=$totalValueBHD-$totalCostBHD; $totalPLPct=$totalCostBHD>0?round($totalPL/$totalCostBHD*100,2):0;?>
    <div class="card-title">Unrealized P&L</div>
    <div class="card-value <?=$totalPL>=0?'c-green':'c-red'?>">BD <?=number_format($totalPL,2)?></div>
    <div class="card-sub <?=$totalPL>=0?'c-green':'c-red'?>"><?=$totalPLPct>=0?'+':''?><?=$totalPLPct?>%</div>
  </div>
</div>

<div class="g2">
<!-- Add/Edit Form -->
<div>
  <!-- Price Update Modal -->


<div class="section-header">
    <div class="section-title"><?=$editItem?'Edit':'Add'?> Holding</div>
    <?php if($editItem):?><a href="portfolio.php" class="btn btn-ghost btn-sm">+ New</a><?php endif;?>
  </div>
  <div class="card">
    <form method="POST" action="portfolio.php">
      <input type="hidden" name="do_save" value="1">
      <input type="hidden" name="sid" value="<?=$editItem?$editItem['id']:0?>">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Symbol *</label>
          <input class="form-control" name="symbol" value="<?=htmlspecialchars($editItem['symbol']??'')?>" placeholder="e.g. NVDA, BTC" style="text-transform:uppercase;" required autofocus>
        </div>
        <div class="form-group">
          <label class="form-label">Market</label>
          <input list="market_list" class="form-control" name="market" id="mkt_select"
                 value="<?=htmlspecialchars($editItem['market']??'BD')?>"
                 onchange="updateExchangeHint(this.value)" placeholder="e.g. BD, USA, Crypto">
          <datalist id="market_list">
            <option value="BD">🇧🇩 Bangladesh</option>
            <option value="USA">🇺🇸 USA</option>
            <option value="UK">🇬🇧 UK</option>
            <option value="Crypto">🪙 Crypto</option>
            <option value="Japan">🇯🇵 Japan</option>
            <option value="India">🇮🇳 India</option>
            <option value="UAE">🇦🇪 UAE</option>
            <option value="Other">🌐 Other</option>
          </datalist>
        </div>
        <div class="form-group">
          <label class="form-label">Exchange</label>
          <input list="exchange_list" class="form-control" name="exchange" id="exch_select"
                 value="<?=htmlspecialchars($editItem['exchange']??'DSE')?>"
                 placeholder="e.g. DSE, NYSE, Crypto">
          <datalist id="exchange_list">
            <option value="DSE">DSE - Dhaka Stock Exchange</option>
            <option value="CSE">CSE - Chittagong Stock Exchange</option>
            <option value="NYSE">NYSE - New York</option>
            <option value="NASDAQ">NASDAQ</option>
            <option value="LSE">LSE - London</option>
            <option value="BSE">BSE - Bombay</option>
            <option value="NSE">NSE - National India</option>
            <option value="TSE">TSE - Tokyo</option>
            <option value="Crypto">Crypto (CoinGecko)</option>
            <option value="Other">Other</option>
          </datalist>
          <div class="hint" id="exchange_hint"></div>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Company/Asset Name *</label>
        <input class="form-control" name="company_name" value="<?=htmlspecialchars($editItem['company_name']??'')?>" placeholder="e.g. Nvidia, Bitcoin" required>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Quantity</label>
          <input class="form-control" type="number" step="0.0001" name="quantity" value="<?=htmlspecialchars($editItem['quantity']??0)?>">
        </div>
        <div class="form-group">
          <label class="form-label">Currency</label>
          <select class="form-control" name="currency">
            <?php foreach(['BDT'=>'BDT','USD'=>'USD','GBP'=>'GBP','BHD'=>'BHD'] as $v=>$l):?>
            <option value="<?=$v?>" <?=($editItem['currency']??'BDT')===$v?'selected':''?>><?=$l?></option>
            <?php endforeach;?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Avg. Buy Price</label>
          <input class="form-control" type="number" step="any" name="avg_cost" value="<?=htmlspecialchars($editItem['avg_cost']??0)?>">
        </div>
        <div class="form-group">
          <label class="form-label">Current Price</label>
          <input class="form-control" type="number" step="any" name="current_price" value="<?=htmlspecialchars($editItem['current_price']??0)?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Linked Account (optional)</label>
        <select class="form-control" name="account_id">
          <option value="">— None —</option>
          <?php foreach($accounts as $a):?><option value="<?=$a['id']?>" <?=($editItem['account_id']??0)==$a['id']?'selected':''?>><?=htmlspecialchars($a['name'])?></option><?php endforeach;?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Notes</label>
        <input class="form-control" name="notes" value="<?=htmlspecialchars($editItem['notes']??'')?>" placeholder="e.g. Berich account 179387">
      </div>
      <div class="gap-2">
        <button type="submit" class="btn btn-primary"><?=$editItem?'💾 Save':'✅ Add Holding'?></button>
        <?php if($editItem):?><a href="portfolio.php" class="btn btn-ghost">Cancel</a><?php endif;?>
      </div>
    </form>
  </div>
</div>

<!-- Holdings by Market -->
<div>
  <?php foreach($markets as $mkt=>$items): if(!$items) continue;
    $mktCost=array_sum(array_column($items,'costBHD'));
    $mktVal=array_sum(array_column($items,'valBHD'));
    $mktPL=$mktVal-$mktCost;
    $mktLabel=['BD'=>'🇧🇩 Bangladesh','USA'=>'🇺🇸 USA','UK'=>'🇬🇧 UK','Crypto'=>'🪙 Crypto','Other'=>'🌐 Other'][$mkt]??$mkt;
  ?>
  <div class="card" style="margin-bottom:12px;padding:0;overflow:hidden;">
    <div style="background:var(--s2);padding:10px 16px;display:flex;justify-content:space-between;align-items:center;">
      <span style="font-weight:700;"><?=$mktLabel?></span>
      <div style="text-align:right;">
        <span style="font-size:.82rem;color:var(--muted);">BD <?=number_format($mktVal,2)?></span>
        <span class="<?=$mktPL>=0?'c-green':'c-red'?>" style="margin-left:10px;font-size:.82rem;font-weight:700;"><?=$mktPL>=0?'+':''?><?=number_format($mktPL,2)?></span>
      </div>
    </div>
    <table class="tbl" style="font-size:.82rem;">
      <thead><tr><th>Symbol</th><th>Qty</th><th>Avg Cost</th><th>Curr Price</th><th>Value</th><th>P&L</th><th></th></tr></thead>
      <tbody>
        <?php foreach($items as $h):
          $plColor = $h['pl'] >= 0 ? 'c-green' : 'c-red';
        ?>
        <tr>
          <td>
            <div style="font-weight:700;"><?=htmlspecialchars($h['symbol'])?></div>
            <div style="font-size:.72rem;color:var(--muted);"><?=htmlspecialchars($h['company_name'])?></div>
          </td>
          <td><?=number_format((float)$h['quantity'],2)?></td>
          <td><?=number_format((float)$h['avg_cost'],2)?> <?=$h['currency']?></td>
          <td>
            <!-- Inline price update -->
            <form method="POST" action="portfolio.php" style="display:flex;gap:4px;align-items:center;">
              <input type="hidden" name="update_price" value="1">
              <input type="hidden" name="pid" value="<?=$h['id']?>">
              <input type="number" step="0.0001" name="price" value="<?=htmlspecialchars($h['current_price'])?>" style="width:70px;padding:4px 6px;background:var(--s2);border:1px solid var(--s3);border-radius:5px;color:var(--text);font-size:.78rem;">
              <button type="submit" style="background:var(--blue);color:#fff;border:none;padding:4px 6px;border-radius:5px;cursor:pointer;font-size:.75rem;">✓</button>
            </form>
            <div style="font-size:.68rem;color:var(--muted);">Updated: <?=date('d M',strtotime($h['last_updated']))?></div>
          </td>
          <td>
            <div style="font-weight:600;"><?=number_format($h['value'],2)?> <?=$h['currency']?></div>
            <div style="font-size:.72rem;color:var(--muted);">BD <?=number_format($h['valBHD'],2)?></div>
          </td>
          <td class="<?=$plColor?>" style="font-weight:600;">
            <?=$h['pl']>=0?'+':''?><?=number_format($h['pl'],2)?><br>
            <span style="font-size:.72rem;"><?=$h['plPct']>=0?'+':''?><?=$h['plPct']?>%</span>
          </td>
          <td>
            <div class="gap-2">
              <a href="portfolio.php?edit=<?=$h['id']?>" class="btn btn-ghost btn-sm">✏️</a>
              <a href="portfolio.php?delete=<?=$h['id']?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')">🗑</a>
            </div>
          </td>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table>
  </div>
  <?php endforeach;?>
</div>
</div>
<script>
let fetchedPrices = [];

function openPriceUpdate() {
    document.getElementById('price-modal').style.display = 'block';
    document.getElementById('modal-loading').style.display = 'block';
    document.getElementById('modal-results').style.display = 'none';
    document.body.style.overflow = 'hidden';

    // Load stock list immediately (no Gemini yet)
    fetch('/dashboard/run_price_update.php?type=list')
        .then(r => r.json())
        .then(data => {
            document.getElementById('modal-loading').style.display = 'none';
            document.getElementById('modal-results').style.display = 'block';
            renderStockList(data.stocks || []);
        })
        .catch(err => {
            document.getElementById('modal-loading').innerHTML = '<div style="color:var(--red)">❌ ' + err.message + '</div>';
        });
}

let stockList = [];

function renderStockList(stocks) {
    stockList = stocks;
    fetchedPrices = [];

    let html = '<div style="font-size:.8rem;color:var(--muted);margin-bottom:12px;">Click 🔄 to fetch each price individually. Click ✅ after updating to save.</div>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:.85rem;">';
    html += '<tr style="color:var(--muted);font-size:.72rem;border-bottom:1px solid var(--s3);">';
    html += '<th style="padding:6px;text-align:left;">Symbol</th>';
    html += '<th style="padding:6px;text-align:right;">Current</th>';
    html += '<th style="padding:6px;text-align:right;">New Price</th>';
    html += '<th style="padding:6px;text-align:center;">Action</th></tr>';

    stocks.forEach((s, i) => {
        html += `<tr id="row_${i}" style="border-bottom:1px solid var(--s3);">
            <td style="padding:6px;font-weight:600;">${s.symbol}<br>
                <small style="color:var(--muted);font-weight:normal;">${s.exchange} · ${s.currency}</small></td>
            <td style="padding:6px;text-align:right;color:var(--muted);">${s.old_price > 0 ? s.old_price.toFixed(s.old_price < 0.01 ? 6 : 2) : '—'}</td>
            <td id="newprice_${i}" style="padding:6px;text-align:right;font-weight:700;color:var(--muted);">—</td>
            <td style="padding:6px;text-align:center;">
                <button id="btn_${i}" onclick="fetchOne(${i})"
                    style="background:var(--s3);border:none;color:var(--text);padding:5px 10px;border-radius:6px;cursor:pointer;font-size:.8rem;">
                    🔄 Fetch
                </button>
            </td>
        </tr>`;
    });
    html += '</table>';
    document.getElementById('price-table').innerHTML = html;
    document.getElementById('modal-errors').innerHTML = '';
}

function fetchOne(idx) {
    const s = stockList[idx];
    const btn = document.getElementById('btn_' + idx);
    const priceCell = document.getElementById('newprice_' + idx);

    btn.textContent = '⏳';
    btn.disabled = true;
    priceCell.textContent = '...';

    const url = '/dashboard/run_price_update.php?type=one&sym=' + encodeURIComponent(s.symbol) + '&exch=' + encodeURIComponent(s.exchange) + '&mkt=' + encodeURIComponent(s.market);

    console.log('Fetching:', url);
    fetch(url)
        .then(r => r.json())
        .then(data => {
            console.log('fetchOne response for ' + s.symbol + ':', JSON.stringify(data));
            if (data.results && data.results.length > 0) {
                const r = data.results[0];
                const change = r.new_price - r.old_price;
                const color = change > 0 ? '#2ecc71' : change < 0 ? '#e74c3c' : 'var(--text)';
                const dec = r.new_price < 0.01 ? 6 : 2;
                priceCell.innerHTML = '<span style="color:' + color + ';">' + r.new_price.toFixed(dec) + '</span>';
                btn.textContent = '✅';
                btn.style.background = '#2ecc7133';
                btn.style.color = '#2ecc71';
                btn.disabled = false;
                btn.onclick = function() { saveSingle(idx, r); };

                // Add to fetchedPrices (remove old entry if exists)
                fetchedPrices = fetchedPrices.filter(p => !(p.symbol===r.symbol && p.exchange===r.exchange));
                fetchedPrices.push(r);
            } else {
                const errMsg = data.errors && data.errors.length > 0 ? data.errors[0] : 'not found';
                priceCell.innerHTML = '<span style="color:var(--red);font-size:.75rem;" title="' + errMsg + '">❌</span>';
                btn.textContent = '🔄 Retry';
                btn.disabled = false;
                btn.onclick = function() { fetchOne(idx); };
                document.getElementById('modal-errors').innerHTML += '<div style="font-size:.75rem;">[' + s.exchange + '] ' + s.symbol + ': ' + errMsg + '</div>';
            }
        })
        .catch(() => {
            priceCell.textContent = '⏱️';
            btn.textContent = '🔄 Retry';
            btn.disabled = false;
            btn.onclick = function() { fetchOne(idx); };
        });
}

function saveSingle(idx, r) {
    fetch('/dashboard/save_prices.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({prices:[r]})
    }).then(res => res.json()).then(d => {
        const btn = document.getElementById('btn_' + idx);
        btn.textContent = '💾 Saved!';
        btn.style.background = '#4e9af133';
        btn.style.color = '#4e9af1';
        btn.disabled = true;
    });
}


function saveAllFetched() {
    if (fetchedPrices.length === 0) { alert('No prices fetched yet. Click 🔄 Fetch on each stock first.'); return; }
    const btn = document.getElementById('save-all-btn');
    btn.disabled = true; btn.textContent = '⏳ Saving...';
    fetch('/dashboard/save_prices.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({prices: fetchedPrices})
    }).then(r=>r.json()).then(d=>{
        btn.textContent = '✅ Saved ' + d.saved + ' prices!';
        setTimeout(()=>{ closeModal(); location.reload(); }, 1500);
    }).catch(()=>{ btn.textContent='❌ Failed'; btn.disabled=false; });
}

function closeModal() {
    document.getElementById('price-modal').style.display = 'none';
    document.body.style.overflow = '';
}
</script>



<?php require 'footer.php'; ?>
