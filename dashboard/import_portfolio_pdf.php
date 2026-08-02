<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle = 'Import Portfolio PDF'; $activePage = 'portfolio'; $backTo = 'portfolio.php';

$extracted = null;
$error     = null;

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['pdf']) && !isset($_POST['save_prices'])) {
    $file     = $_FILES['pdf'];
    $exchange = $_POST['exchange'] ?? 'DSE';

    if ($file['error']===0) {
        $pdfData = base64_encode(file_get_contents($file['tmp_name']));
        $broker  = $exchange==='DSE' ? 'BRAC EPL ODS622' : 'Berich 179387';

        $prompt = "This is a {$broker} portfolio statement PDF from Bangladesh stock market.\n\nExtract ALL stocks with their Market Price or LTP (Last Traded Price) column.\n\nReturn ONLY a JSON array like this:\n[{\"symbol\":\"CITYBANK\",\"price\":30.70},{\"symbol\":\"MTB\",\"price\":14.50}]\n\nInclude every stock. Numbers only for price. No explanation.";

        $payload = [
            'model'      => 'claude-sonnet-4-6',
            'max_tokens' => 1000,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type'=>'document','source'=>['type'=>'base64','media_type'=>'application/pdf','data'=>$pdfData]],
                    ['type'=>'text','text'=>$prompt]
                ]
            ]]
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>[
                'Content-Type: application/json',
                'x-api-key: '.ANTHROPIC_API_KEY,
                'anthropic-version: 2023-06-01',
                'anthropic-beta: pdfs-2024-09-25',
            ],
            CURLOPT_POSTFIELDS=>json_encode($payload),
            CURLOPT_TIMEOUT=>60, CURLOPT_SSL_VERIFYPEER=>false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code===200 && $resp) {
            $data = json_decode($resp,true);
            $text = '';
            foreach(($data['content']??[]) as $b) if($b['type']==='text') $text.=$b['text'];
            $text = preg_replace('/```(?:json)?\s*/i','',$text);
            $text = preg_replace('/```/','',$text);

            if(preg_match('/\[[\s\S]*\]/m', $text, $m)){
                $arr = json_decode($m[0], true);
                if(is_array($arr) && count($arr)>0){
                    $extracted = ['exchange'=>$exchange,'broker'=>$broker,'items'=>[]];
                    foreach($arr as $item){
                        $sym   = strtoupper(trim($item['symbol']??''));
                        $price = (float)($item['price']??0);
                        if($sym && $price>0) $extracted['items'][] = ['symbol'=>$sym,'price'=>$price];
                    }
                } else { $error = 'No stocks found in PDF. Raw: '.substr($text,0,200); }
            } else { $error = 'Could not parse response: '.substr($text,0,200); }
        } else {
            $err = json_decode($resp,true);
            $error = 'API error: '.($err['error']['message']??'HTTP '.$code);
        }
    } else { $error = 'File upload error: '.$file['error']; }
}

// Save prices
$saved = 0; $saveLog = [];
if (isset($_POST['save_prices']) && isset($_POST['prices'])) {
    $pricesToSave = json_decode($_POST['prices'], true) ?? [];
    $exchange     = $_POST['save_exchange'] ?? 'DSE';
    foreach($pricesToSave as $item){
        $sym   = strtoupper(trim($item['symbol']??''));
        $price = (float)($item['price']??0);
        if(!$sym||$price<=0) continue;
        $st = db()->prepare("UPDATE portfolio SET current_price=?,last_updated=NOW() WHERE UPPER(symbol)=? AND exchange=?");
        $st->execute([$price,$sym,$exchange]);
        if($st->rowCount()===0)
            db()->prepare("UPDATE portfolio SET current_price=?,last_updated=NOW() WHERE UPPER(symbol)=?")->execute([$price,$sym]);
        $saved++; $saveLog[] = $sym.': '.$price;
    }
    // Sync portfolio values to linked accounts
    if ($saved > 0) {
        require_once __DIR__ . '/sync_portfolio_accounts.php';
        $syncLog = syncPortfolioToAccounts();
    }
}

require 'header.php';
?>
<div style="max-width:640px;">

<?php if($saved>0): ?>
<div class="alert alert-success">
    ✅ Updated <strong><?=$saved?></strong> stock prices successfully!<br>
    <small style="color:var(--muted);"><?=implode(' · ',$saveLog)?></small>
</div>
<div class="gap-2">
    <a href="portfolio.php" class="btn btn-primary">← Back to Portfolio</a>
    <a href="import_portfolio_pdf.php" class="btn btn-ghost">📄 Import Another PDF</a>
</div>

<?php elseif($extracted && count($extracted['items'])>0): ?>
<!-- Preview extracted prices -->
<div class="card" style="margin-bottom:16px;">
    <div style="font-weight:700;margin-bottom:4px;">📊 Extracted Prices — <?=htmlspecialchars($extracted['broker'])?></div>
    <div style="font-size:.8rem;color:var(--muted);margin-bottom:14px;">Found <?=count($extracted['items'])?> stocks · <?=htmlspecialchars($extracted['exchange'])?> Exchange</div>

    <table class="tbl" style="margin-bottom:16px;">
        <tr>
            <th>Symbol</th>
            <th style="text-align:right;">Market Price (BDT)</th>
            <th style="text-align:center;">Save?</th>
        </tr>
        <?php foreach($extracted['items'] as $i=>$item): ?>
        <?php
            $cur = db()->prepare("SELECT current_price FROM portfolio WHERE UPPER(symbol)=? AND exchange=? LIMIT 1");
            $cur->execute([strtoupper($item['symbol']),$extracted['exchange']]);
            $oldPrice = (float)($cur->fetchColumn() ?: 0);
            $change   = $item['price'] - $oldPrice;
            $changeColor = $change>0?'var(--green)':($change<0?'var(--red)':'var(--muted)');
        ?>
        <tr>
            <td><strong><?=htmlspecialchars($item['symbol'])?></strong></td>
            <td style="text-align:right;">
                <?=money($item['price'])?> BDT
                <?php if($oldPrice>0): ?>
                <br><small style="color:<?=$changeColor?>;">
                    <?=$change>=0?'+':''?><?=money($change)?> (was <?=money($oldPrice)?>)
                </small>
                <?php endif; ?>
            </td>
            <td style="text-align:center;">
                <input type="checkbox" name="chk_<?=$i?>" id="chk_<?=$i?>" checked
                    style="width:18px;height:18px;accent-color:var(--blue);cursor:pointer;">
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

    <form method="POST" id="save-form">
        <input type="hidden" name="save_prices" value="1">
        <input type="hidden" name="save_exchange" value="<?=htmlspecialchars($extracted['exchange'])?>">
        <input type="hidden" name="prices" id="prices-json">
        <div class="gap-2">
            <button type="submit" onclick="prepareSave()" class="btn btn-success">✅ Save Checked Prices</button>
            <a href="import_portfolio_pdf.php" class="btn btn-ghost">↩ Import Another</a>
            <a href="portfolio.php" class="btn btn-ghost">← Portfolio</a>
        </div>
    </form>
</div>

<script>
const allPrices = <?=json_encode($extracted['items'])?>;
function prepareSave(){
    const toSave = allPrices.filter((_,i) => {
        const chk = document.getElementById('chk_'+i);
        return chk && chk.checked;
    });
    document.getElementById('prices-json').value = JSON.stringify(toSave);
}
</script>

<?php else: ?>
<!-- Upload form -->
<?php if($error): ?>
<div class="alert alert-danger">❌ <?=htmlspecialchars($error)?></div>
<?php endif; ?>

<div class="card">
    <div style="font-weight:700;font-size:1rem;margin-bottom:4px;">📧 Import Portfolio from Broker PDF</div>
    <div style="font-size:.82rem;color:var(--muted);margin-bottom:20px;">
        Download the PDF from your broker email and upload here. Claude will extract all stock prices automatically.
    </div>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label class="form-label">Select Broker / Exchange</label>
            <select class="form-control" name="exchange">
                <option value="DSE">BRAC EPL ODS622 — DSE (Dhaka)</option>
                <option value="CSE">Berich 179387 — CSE (Chittagong)</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Portfolio PDF File</label>
            <input type="file" name="pdf" accept="application/pdf,.pdf" class="form-control" required
                   style="padding:10px;cursor:pointer;">
            <div class="hint" style="margin-top:6px;">Max 10MB · PDF only</div>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;padding:13px;">
            📊 Extract Prices from PDF
        </button>
    </form>

    <div style="margin-top:24px;padding-top:16px;border-top:1px solid var(--s3);">
        <div style="font-size:.82rem;font-weight:600;margin-bottom:10px;">How to import:</div>
        <div style="display:grid;gap:10px;">
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="background:var(--blue);color:#fff;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0;">1</div>
                <div style="font-size:.82rem;color:var(--muted);">Open BRAC EPL email → tap <strong>Portfolio Statement-ODS622.pdf</strong> → Save to Files</div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="background:var(--blue);color:#fff;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0;">2</div>
                <div style="font-size:.82rem;color:var(--muted);">Select <strong>BRAC EPL ODS622 — DSE</strong> above, then choose the PDF file</div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="background:var(--blue);color:#fff;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0;">3</div>
                <div style="font-size:.82rem;color:var(--muted);">Click Extract → review prices → click Save. Repeat for Berich PDF.</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
</div>
<?php require 'footer.php'; ?>
