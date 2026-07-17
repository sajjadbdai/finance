<?php
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Bank Statements'; $activePage='bank_statements';

// Create tables if not exist
try {
    db()->exec("CREATE TABLE IF NOT EXISTS bank_statements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        bank_name VARCHAR(100) NOT NULL,
        statement_date DATE,
        period_from DATE,
        period_to DATE,
        opening_balance DECIMAL(15,4) DEFAULT 0,
        closing_balance DECIMAL(15,4) DEFAULT 0,
        currency VARCHAR(10) DEFAULT 'BHD',
        total_credits DECIMAL(15,4) DEFAULT 0,
        total_debits DECIMAL(15,4) DEFAULT 0,
        txn_count INT DEFAULT 0,
        file_name VARCHAR(255),
        file_path VARCHAR(500),
        parsed_by VARCHAR(50) DEFAULT 'manual',
        status ENUM('pending','parsed','reconciled','error') DEFAULT 'pending',
        parse_notes TEXT,
        imported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_account (account_id),
        INDEX idx_date (statement_date)
    )");
    db()->exec("CREATE TABLE IF NOT EXISTS bank_statement_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        statement_id INT NOT NULL,
        line_date DATE,
        description VARCHAR(500),
        reference VARCHAR(100),
        debit DECIMAL(15,4) DEFAULT 0,
        credit DECIMAL(15,4) DEFAULT 0,
        balance DECIMAL(15,4) DEFAULT 0,
        currency VARCHAR(10) DEFAULT 'BHD',
        raw_text TEXT,
        matched_txn_id INT DEFAULT NULL,
        match_status ENUM('unmatched','matched','ignored') DEFAULT 'unmatched',
        INDEX idx_statement (statement_id),
        INDEX idx_date (line_date)
    )");
} catch(Exception $e) {}

$msg=''; $error='';

// Handle delete
if(isset($_GET['delete']) && is_numeric($_GET['delete'])){
    try {
        $sid=(int)$_GET['delete'];
        // Delete the PDF file
        $stmt=db()->prepare("SELECT file_path FROM bank_statements WHERE id=?");
        $stmt->execute([$sid]);
        $row=$stmt->fetch();
        if($row && $row['file_path'] && file_exists($row['file_path'])) @unlink($row['file_path']);
        db()->prepare("DELETE FROM bank_statement_lines WHERE statement_id=?")->execute([$sid]);
        db()->prepare("DELETE FROM bank_statements WHERE id=?")->execute([$sid]);
        header('Location: /dashboard/bank_statements.php?msg=deleted'); exit;
    } catch(Exception $e){ $error=$e->getMessage(); }
}

// Handle PDF upload
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['pdf'])){
    $file      = $_FILES['pdf'];
    $accountId = (int)($_POST['account_id']??0);
    $currency  = $_POST['currency']??'BHD';
    $useAI     = isset($_POST['use_ai']);

    if($file['error']===0 && $accountId>0){
        // Store file
        $storageDir = dirname(__DIR__) . '/storage/statements/';
        if(!is_dir($storageDir)) mkdir($storageDir, 0755, true);
        $fileName = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/','',$file['name']);
        $filePath = $storageDir . $fileName;
        move_uploaded_file($file['tmp_name'], $filePath);

        // Get account info
        $accSt=db()->prepare("SELECT name,currency FROM accounts WHERE id=?");
        $accSt->execute([$accountId]);
        $acc=$accSt->fetch();
        $currency=$currency?:($acc['currency']??'BHD');

        // Parse the PDF
        require_once dirname(__DIR__) . '/parsers/ParserFactory.php';
        $parsed=[];
        if($useAI){
            $parsed = ParserFactory::parseWithClaude($filePath, $currency);
        }
        if(empty($parsed)){
            // Try regex parsers
            $text = ParserFactory::extractTextFromPdf($filePath);
            if($text){
                $parser = ParserFactory::detect($text);
                $parsed = $parser->getResult($filePath, $accountId);
            }
        }
        if(empty($parsed)){
            $error='Could not extract data from PDF. Try enabling AI Parse.';
        } else {
            try {
                // Save statement
                $txns = $parsed['transactions']??[];
                $db=db();
                $db->prepare("INSERT INTO bank_statements 
                    (account_id,bank_name,statement_date,period_from,period_to,opening_balance,closing_balance,currency,total_credits,total_debits,txn_count,file_name,file_path,parsed_by,status,parse_notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                    $accountId,
                    $parsed['bank_name']??($acc['name']??'Unknown'),
                    $parsed['statement_date']??$parsed['period_to']??date('Y-m-d'),
                    $parsed['period_from']??null,
                    $parsed['period_to']??null,
                    $parsed['opening_balance']??0,
                    $parsed['closing_balance']??0,
                    $currency,
                    $parsed['total_credits']??array_sum(array_column($txns,'credit')),
                    $parsed['total_debits']??array_sum(array_column($txns,'debit')),
                    count($txns),
                    $fileName,
                    $filePath,
                    $parsed['parsed_by']??'claude-ai',
                    count($txns)>0?'parsed':'error',
                    count($txns)>0?count($txns).' transactions extracted':($parsed['parse_notes']??'No transactions found'),
                ]);
                $stmtId=$db->lastInsertId();

                // Save transaction lines
                foreach($txns as $t){
                    $db->prepare("INSERT INTO bank_statement_lines (statement_id,line_date,description,reference,debit,credit,balance,currency,raw_text)
                        VALUES (?,?,?,?,?,?,?,?,?)")->execute([
                        $stmtId,
                        $t['line_date']??null,
                        $t['description']??'',
                        $t['reference']??'',
                        $t['debit']??0,
                        $t['credit']??0,
                        $t['balance']??0,
                        $currency,
                        $t['raw_text']??'',
                    ]);
                }
                header('Location: /dashboard/bank_statement_view.php?id='.$stmtId.'&msg=imported'); exit;
            } catch(Exception $e){ $error='DB Error: '.$e->getMessage(); }
        }
    } else {
        $error = $accountId?'Please upload a PDF file':'Please select an account';
    }
}

$msg=$_GET['msg']??$msg;

// Load statements
$statements = db()->query("
    SELECT bs.*, a.name as acc_name, a.currency as acc_currency
    FROM bank_statements bs
    JOIN accounts a ON a.id=bs.account_id
    ORDER BY bs.statement_date DESC, bs.imported_at DESC
")->fetchAll();

// Load accounts for upload form
$accounts = db()->query("SELECT id,name,currency,group_name FROM accounts WHERE is_active=1 ORDER BY group_name,name")->fetchAll();

require 'header.php'; ?>
<?php if($msg==='imported'):?><div class="alert alert-success">✅ Statement imported successfully!</div><?php endif;?>
<?php if($msg==='deleted'):?><div class="alert alert-success">🗑 Statement deleted.</div><?php endif;?>
<?php if($error):?><div class="alert alert-danger">❌ <?=htmlspecialchars($error)?></div><?php endif;?>

<!-- Upload Form -->
<div class="card" style="margin-bottom:20px;">
    <div class="section-header">
        <div class="section-title">📄 Import Bank Statement</div>
    </div>
    <form method="POST" enctype="multipart/form-data">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Account</label>
                <select class="form-control" name="account_id" required onchange="setCurrency(this)">
                    <option value="">-- Select Account --</option>
                    <?php foreach($accounts as $a):?>
                    <option value="<?=$a['id']?>" data-currency="<?=$a['currency']?>"><?=htmlspecialchars($a['name'])?> (<?=$a['currency']?>)</option>
                    <?php endforeach;?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Currency</label>
                <select class="form-control" name="currency" id="currency_sel">
                    <?php foreach(['BHD','USD','BDT','GBP','EUR'] as $c):?>
                    <option value="<?=$c?>"><?=$c?></option>
                    <?php endforeach;?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">PDF Statement File</label>
            <input type="file" name="pdf" accept=".pdf" class="form-control" required style="padding:10px;cursor:pointer;">
        </div>
        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="use_ai" value="1" checked style="width:18px;height:18px;">
                <span style="font-size:.85rem;">🤖 Use AI (Claude) for accurate parsing <small style="color:var(--muted);">— recommended for all banks</small></span>
            </label>
        </div>
        <button type="submit" class="btn btn-primary">📤 Import Statement</button>
    </form>
</div>

<!-- Statements List -->
<div class="card">
    <div class="section-header">
        <div class="section-title">📋 Imported Statements (<?=count($statements)?>)</div>
    </div>
    <?php if(!$statements):?>
    <div style="text-align:center;padding:40px;color:var(--muted);">No statements imported yet. Upload your first bank PDF above!</div>
    <?php else:?>
    <table class="tbl">
        <tr>
            <th>Account</th>
            <th>Period</th>
            <th>Opening</th>
            <th>Closing</th>
            <th>Txns</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        <?php foreach($statements as $s):
            $statusColor=['parsed'=>'var(--green)','reconciled'=>'var(--blue)','error'=>'var(--red)','pending'=>'var(--muted)'][$s['status']]??'var(--muted)';
        ?>
        <tr>
            <td>
                <strong><?=htmlspecialchars($s['acc_name'])?></strong>
                <br><small class="c-muted"><?=htmlspecialchars($s['bank_name'])?></small>
            </td>
            <td>
                <?=htmlspecialchars($s['period_from']?date('d M Y',strtotime($s['period_from'])):'—')?>
                <br><small class="c-muted">to <?=htmlspecialchars($s['period_to']?date('d M Y',strtotime($s['period_to'])):'—')?></small>
            </td>
            <td data-hide="true"><?=number_format($s['opening_balance'],3)?> <small><?=$s['currency']?></small></td>
            <td data-hide="true"><?=number_format($s['closing_balance'],3)?> <small><?=$s['currency']?></small></td>
            <td style="text-align:center;">
                <span style="font-weight:700;color:var(--blue);"><?=$s['txn_count']?></span>
            </td>
            <td>
                <span style="color:<?=$statusColor?>;font-size:.8rem;font-weight:600;">
                    <?=ucfirst($s['status'])?>
                </span>
            </td>
            <td>
                <a href="bank_statement_view.php?id=<?=$s['id']?>" class="btn btn-ghost btn-sm">👁 View</a>
                <a href="bank_reconciliation.php?id=<?=$s['id']?>" class="btn btn-ghost btn-sm">⚖️ Reconcile</a>
                <a href="?delete=<?=$s['id']?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this statement?')">🗑</a>
            </td>
        </tr>
        <?php endforeach;?>
    </table>
    <?php endif;?>
</div>

<script>
function setCurrency(sel){
    var opt=sel.options[sel.selectedIndex];
    var cur=opt.getAttribute('data-currency')||'BHD';
    document.getElementById('currency_sel').value=cur;
}
</script>
<?php require 'footer.php'; ?>
