<?php
ini_set('display_errors',0);
require_once __DIR__ . '/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: /dashboard/login.php'); exit; }
$pageTitle='Bank Statements'; $activePage='bank_statements'; $backTo='balance_tools.php';

// Decrypt password-protected PDF (tries qpdf, pdftk, ghostscript)
function decryptPdf(string $inPath, string $password): array {
    $outPath = $inPath . '.dec.pdf';
    $in  = escapeshellarg($inPath);
    $out = escapeshellarg($outPath);
    $pw  = escapeshellarg($password);
    @shell_exec("qpdf --password={$pw} --decrypt {$in} {$out} 2>&1");
    if (file_exists($outPath) && filesize($outPath) > 100) { rename($outPath, $inPath); return [true, 'qpdf']; }
    @shell_exec("pdftk {$in} input_pw {$pw} output {$out} 2>&1");
    if (file_exists($outPath) && filesize($outPath) > 100) { rename($outPath, $inPath); return [true, 'pdftk']; }
    @shell_exec("gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -sPDFPassword={$pw} -sOutputFile={$out} {$in} 2>&1");
    if (file_exists($outPath) && filesize($outPath) > 100) { rename($outPath, $inPath); return [true, 'gs']; }
    @unlink($outPath);
    return [false, 'no tool could unlock it'];
}

function pdfIsEncrypted(string $path): bool {
    $full = @file_get_contents($path);
    return $full !== false && strpos($full, '/Encrypt') !== false;
}

function getSavedPw(int $accountId): string {
    try {
        $st = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key=?");
        $st->execute(['stmt_pw_' . $accountId]);
        return (string)($st->fetchColumn() ?: '');
    } catch(Exception $e){ return ''; }
}
function savePw(int $accountId, string $pw): void {
    try {
        db()->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
           ->execute(['stmt_pw_' . $accountId, $pw]);
    } catch(Exception $e){}
}

// Create tables
try {
    db()->exec("CREATE TABLE IF NOT EXISTS bank_statements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        bank_name VARCHAR(100) NOT NULL DEFAULT 'Unknown',
        statement_date DATE NULL,
        period_from DATE NULL,
        period_to DATE NULL,
        opening_balance DECIMAL(15,4) DEFAULT 0,
        closing_balance DECIMAL(15,4) DEFAULT 0,
        currency VARCHAR(10) DEFAULT 'BHD',
        total_credits DECIMAL(15,4) DEFAULT 0,
        total_debits DECIMAL(15,4) DEFAULT 0,
        txn_count INT DEFAULT 0,
        file_name VARCHAR(255) NULL,
        file_path VARCHAR(500) NULL,
        parsed_by VARCHAR(50) DEFAULT 'manual',
        status VARCHAR(20) DEFAULT 'pending',
        parse_notes TEXT NULL,
        imported_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

try {
    db()->exec("CREATE TABLE IF NOT EXISTS bank_statement_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        statement_id INT NOT NULL,
        line_date DATE NULL,
        description VARCHAR(500) NULL,
        reference VARCHAR(100) NULL,
        debit DECIMAL(15,4) DEFAULT 0,
        credit DECIMAL(15,4) DEFAULT 0,
        balance DECIMAL(15,4) DEFAULT 0,
        currency VARCHAR(10) DEFAULT 'BHD',
        raw_text TEXT NULL,
        matched_txn_id INT NULL DEFAULT NULL,
        match_status VARCHAR(20) DEFAULT 'unmatched'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

$msg   = $_GET['msg'] ?? '';
$error = '';

// Delete a saved password
if (isset($_GET['delpw']) && is_numeric($_GET['delpw'])) {
    try { db()->prepare("DELETE FROM app_settings WHERE setting_key=?")->execute(['stmt_pw_'.(int)$_GET['delpw']]); } catch(Exception $e){}
    header('Location: /dashboard/bank_statements.php?msg=pw_deleted'); exit;
}

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $sid = (int)$_GET['delete'];
    try {
        $row = db()->prepare("SELECT file_path FROM bank_statements WHERE id=?");
        $row->execute([$sid]);
        $r = $row->fetch();
        if ($r && $r['file_path'] && file_exists($r['file_path'])) @unlink($r['file_path']);
        db()->prepare("DELETE FROM bank_statement_lines WHERE statement_id=?")->execute([$sid]);
        db()->prepare("DELETE FROM bank_statements WHERE id=?")->execute([$sid]);
    } catch(Exception $e) {}
    header('Location: /dashboard/bank_statements.php?msg=deleted'); exit;
}

// Reparse an existing statement (uses stored PDF)
if (isset($_GET['reparse']) && is_numeric($_GET['reparse'])) {
    $sid = (int)$_GET['reparse'];
    $row = db()->prepare("SELECT * FROM bank_statements WHERE id=?");
    $row->execute([$sid]);
    $s = $row->fetch();
    if ($s && $s['file_path'] && file_exists($s['file_path'])) {
        $parserFile = dirname(__DIR__) . '/parsers/ParserFactory.php';
        if (file_exists($parserFile)) {
            require_once $parserFile;
            $parsed = ParserFactory::parseWithClaude($s['file_path'], $s['currency']);
            $txns = $parsed['transactions'] ?? [];
            if ($txns) {
                db()->prepare("DELETE FROM bank_statement_lines WHERE statement_id=?")->execute([$sid]);
                foreach ($txns as $t) {
                    db()->prepare("INSERT INTO bank_statement_lines (statement_id,line_date,description,reference,debit,credit,balance,currency,raw_text) VALUES (?,?,?,?,?,?,?,?,?)")
                       ->execute([$sid, $t['line_date']??null, substr($t['description']??'',0,499), substr($t['reference']??'',0,99), $t['debit']??0, $t['credit']??0, $t['balance']??0, $s['currency'], '']);
                }
                db()->prepare("UPDATE bank_statements SET bank_name=?, period_from=?, period_to=?, statement_date=?, opening_balance=?, closing_balance=?, total_credits=?, total_debits=?, txn_count=?, status='parsed', parse_notes=? WHERE id=?")
                   ->execute([$parsed['bank_name']??$s['bank_name'], $parsed['period_from']??null, $parsed['period_to']??null, $parsed['period_to']??null, $parsed['opening_balance']??0, $parsed['closing_balance']??0, array_sum(array_column($txns,'credit')), array_sum(array_column($txns,'debit')), count($txns), count($txns).' transactions (reparsed)', $sid]);
                header('Location: /dashboard/bank_statement_view.php?id='.$sid.'&msg=imported'); exit;
            } else {
                db()->prepare("UPDATE bank_statements SET status='error', parse_notes=? WHERE id=?")
                   ->execute(['Reparse failed: ' . ParserFactory::$lastError, $sid]);
            }
        }
    }
    header('Location: /dashboard/bank_statements.php?msg=reparse_failed'); exit;
}

// Handle upload
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['pdf'])) {
    $file      = $_FILES['pdf'];
    $accountId = (int)($_POST['account_id'] ?? 0);
    $currency  = $_POST['currency'] ?? 'BHD';
    $useAI     = !empty($_POST['use_ai']);

    if ($file['error'] === 0 && $accountId > 0) {
        // Storage dir
        $storageDir = dirname(__DIR__) . '/storage/statements/';
        if (!is_dir($storageDir)) @mkdir($storageDir, 0755, true);

        $fileName = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
        $filePath = $storageDir . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            $error = 'Could not save file. Check storage folder permissions.';
        } else {
            // Handle password-protected PDFs
            $pdfPw = trim($_POST['pdf_password'] ?? '');
            if ($pdfPw === '') $pdfPw = getSavedPw($accountId);
            if (pdfIsEncrypted($filePath)) {
                if ($pdfPw === '') {
                    @unlink($filePath);
                    $error = 'This PDF is password-protected. Enter the password below and try again.';
                } else {
                    [$okDec, $tool] = decryptPdf($filePath, $pdfPw);
                    if (!$okDec) {
                        @unlink($filePath);
                        $error = 'Could not unlock PDF (wrong password, or server missing qpdf/pdftk/gs).';
                    } elseif (!empty($_POST['save_password'])) {
                        savePw($accountId, $pdfPw);
                    }
                }
            } elseif ($pdfPw !== '' && !empty($_POST['save_password'])) {
                savePw($accountId, $pdfPw);
            }
        }
        if ($file['error'] === 0 && !$error) {
            // Get account info
            $accSt = db()->prepare("SELECT name, currency FROM accounts WHERE id=?");
            $accSt->execute([$accountId]);
            $acc = $accSt->fetch();
            if (!$currency) $currency = $acc['currency'] ?? 'BHD';

            $parsed = [];

            // Try AI parsing
            if ($useAI && defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY) {
                $parserFile = dirname(__DIR__) . '/parsers/ParserFactory.php';
                if (file_exists($parserFile)) {
                    require_once $parserFile;
                    $parsed = ParserFactory::parseWithClaude($filePath, $currency);
                }
            }

            // Save statement record
            $txns    = $parsed['transactions'] ?? [];
            $stmtId  = null;
            try {
                db()->prepare("INSERT INTO bank_statements 
                    (account_id,bank_name,statement_date,period_from,period_to,
                     opening_balance,closing_balance,currency,total_credits,
                     total_debits,txn_count,file_name,file_path,parsed_by,status,parse_notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $accountId,
                    $parsed['bank_name'] ?? ($acc['name'] ?? 'Unknown'),
                    $parsed['period_to'] ?? null,
                    $parsed['period_from'] ?? null,
                    $parsed['period_to'] ?? null,
                    $parsed['opening_balance'] ?? 0,
                    $parsed['closing_balance'] ?? 0,
                    $currency,
                    $parsed['total_credits'] ?? array_sum(array_column($txns,'credit')),
                    $parsed['total_debits']  ?? array_sum(array_column($txns,'debit')),
                    count($txns),
                    $fileName,
                    $filePath,
                    $parsed['parsed_by'] ?? 'manual',
                    count($txns) > 0 ? 'parsed' : 'error',
                    count($txns) > 0 ? count($txns).' transactions found' : ('Parse failed: ' . (class_exists('ParserFactory') ? ParserFactory::$lastError : 'parser not loaded')),
                ]);
                $stmtId = db()->lastInsertId();

                // Save lines
                foreach ($txns as $t) {
                    db()->prepare("INSERT INTO bank_statement_lines 
                        (statement_id,line_date,description,reference,debit,credit,balance,currency,raw_text)
                        VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $stmtId,
                        $t['line_date'] ?? null,
                        substr($t['description'] ?? '', 0, 499),
                        substr($t['reference']   ?? '', 0, 99),
                        $t['debit']   ?? 0,
                        $t['credit']  ?? 0,
                        $t['balance'] ?? 0,
                        $currency,
                        substr($t['raw_text'] ?? '', 0, 999),
                    ]);
                }
                header('Location: /dashboard/bank_statement_view.php?id='.$stmtId.'&msg=imported'); exit;
            } catch(Exception $e) {
                $error = 'DB Error: ' . $e->getMessage();
            }
        }
    } else {
        $error = !$accountId ? 'Please select an account' : 'Please upload a PDF file';
    }
}

// Load statements list
$statements = [];
try {
    $statements = db()->query("
        SELECT bs.*, a.name as acc_name
        FROM bank_statements bs
        JOIN accounts a ON a.id = bs.account_id
        ORDER BY bs.imported_at DESC
        LIMIT 100
    ")->fetchAll();
} catch(Exception $e) {}

// Load accounts
$accounts = db()->query("SELECT id,name,currency FROM accounts WHERE is_active=1 ORDER BY name")->fetchAll();

require 'header.php';
?>
<?php if($msg==='imported'):?><div class="alert alert-success">Statement imported!</div><?php endif;?>
<?php if($msg==='deleted'):?><div class="alert alert-success">Statement deleted.</div><?php endif;?>
<?php if($msg==='pw_deleted'):?><div class="alert alert-success">Saved password removed.</div><?php endif;?>
<?php if($msg==='reparse_failed'):?><div class="alert alert-danger">Reparse failed — see the error under the statement status.</div><?php endif;?>
<?php if($error):?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php endif;?>

<div class="card" style="margin-bottom:20px;">
    <div class="section-title" style="margin-bottom:16px;">Import Bank Statement</div>
    <form method="POST" enctype="multipart/form-data">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Account</label>
                <select class="form-control" name="account_id" required onchange="document.getElementById('cur').value=this.options[this.selectedIndex].getAttribute('data-c')||'BHD'">
                    <option value="">-- Select Account --</option>
                    <?php foreach($accounts as $a):?>
                    <option value="<?=$a['id']?>" data-c="<?=$a['currency']?>"><?=htmlspecialchars($a['name'])?> (<?=$a['currency']?>)</option>
                    <?php endforeach;?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Currency</label>
                <select class="form-control" name="currency" id="cur">
                    <?php foreach(['BHD','USD','BDT','GBP','EUR'] as $c):?>
                    <option value="<?=$c?>"><?=$c?></option>
                    <?php endforeach;?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">PDF File</label>
            <input type="file" name="pdf" accept=".pdf" class="form-control" required style="padding:10px;">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">PDF Password <small style="color:var(--muted);">(only if statement is protected)</small></label>
                <input type="text" name="pdf_password" class="form-control" placeholder="Leave empty if not protected / use saved" autocomplete="off">
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:4px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.85rem;">
                    <input type="checkbox" name="save_password" value="1">
                    💾 Remember password for this account
                </label>
            </div>
        </div>
        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.85rem;">
                <input type="checkbox" name="use_ai" value="1" checked>
                Use AI (Claude) for parsing — recommended
            </label>
        </div>
        <button type="submit" class="btn btn-primary">Import Statement</button>
    </form>
</div>

<?php
// Saved passwords list
$savedPws = [];
try {
    $rows = db()->query("SELECT setting_key FROM app_settings WHERE setting_key LIKE 'stmt_pw_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $k) {
        $aid = (int)substr($k, 8);
        foreach ($accounts as $a) if ((int)$a['id'] === $aid) { $savedPws[] = $a; break; }
    }
} catch(Exception $e){}
?>
<?php if($savedPws): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="section-title" style="margin-bottom:10px;">🔑 Saved Statement Passwords</div>
    <div style="font-size:.8rem;color:var(--muted);margin-bottom:10px;">These accounts have a saved PDF password — uploads for them unlock automatically.</div>
    <div class="gap-2">
        <?php foreach($savedPws as $a): ?>
        <span style="display:inline-flex;align-items:center;gap:6px;background:var(--s2);border:1px solid var(--s3);border-radius:20px;padding:5px 12px;font-size:.8rem;">
            <?=htmlspecialchars($a['name'])?>
            <a href="?delpw=<?=$a['id']?>" onclick="return confirm('Remove saved password for <?=htmlspecialchars($a['name'])?>?')" style="color:var(--red);font-weight:700;">✕</a>
        </span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="section-header">
        <div class="section-title">Imported Statements (<?=count($statements)?>)</div>
    </div>
    <?php if(!$statements):?>
    <p style="text-align:center;color:var(--muted);padding:30px;">No statements yet. Upload a PDF above.</p>
    <?php else:?>
    <table class="tbl">
        <tr><th>Account</th><th>Period</th><th>Opening</th><th>Closing</th><th>Txns</th><th>Status</th><th></th></tr>
        <?php foreach($statements as $s):?>
        <tr>
            <td><strong><?=htmlspecialchars($s['acc_name'])?></strong><br><small class="c-muted"><?=htmlspecialchars($s['bank_name'])?></small></td>
            <td><?=$s['period_from']?date('d M Y',strtotime($s['period_from'])):'—'?><br><small class="c-muted"><?=$s['period_to']?date('d M Y',strtotime($s['period_to'])):'—'?></small></td>
            <td data-hide="true"><?=money($s['opening_balance'], $s['currency'])?> <?=$s['currency']?></td>
            <td data-hide="true"><?=money($s['closing_balance'], $s['currency'])?> <?=$s['currency']?></td>
            <td style="text-align:center;"><strong class="c-blue"><?=$s['txn_count']?></strong></td>
            <td><span style="font-size:.8rem;color:<?=$s['status']==='parsed'?'var(--green)':($s['status']==='error'?'var(--red)':'var(--muted)')?>;"><?=ucfirst($s['status'])?></span>
                <?php if($s['status']==='error' && $s['parse_notes']):?><br><small style="color:var(--red);font-size:.68rem;"><?=htmlspecialchars(substr($s['parse_notes'],0,120))?></small><?php endif;?>
            </td>
            <td>
                <a href="bank_statement_view.php?id=<?=$s['id']?>" class="btn btn-ghost btn-sm">View</a>
                <a href="bank_reconciliation.php?id=<?=$s['id']?>" class="btn btn-ghost btn-sm">Reconcile</a>
                <a href="?reparse=<?=$s['id']?>" class="btn btn-ghost btn-sm" onclick="return confirm('Re-run AI parsing on this PDF?')">↻ Reparse</a>
                <a href="?delete=<?=$s['id']?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')">Del</a>
            </td>
        </tr>
        <?php endforeach;?>
    </table>
    <?php endif;?>
</div>
<?php require 'footer.php'; ?>
