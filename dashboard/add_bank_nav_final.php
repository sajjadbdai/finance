<?php
$f = __DIR__ . '/header.php';
$c = file_get_contents($f);

// Find setup.php line exactly as it appears
$lines = explode("\n", $c);
$newLines = [];
$added = false;

foreach ($lines as $line) {
    // Insert before the setup.php nav line
    if (!$added && strpos($line, 'setup.php') !== false && strpos($line, 'Bot Setup') !== false) {
        $newLines[] = '  <a href="bank_statements.php"    class="ni <?=$activePage===\'bank_statements\'?\'active\':\'\'>"><span>&#128196;</span> Bank Statements</a>';
        $newLines[] = '  <a href="bank_reconciliation.php" class="ni <?=$activePage===\'bank_reconciliation\'?\'active\':\'\'>"><span>&#9878;</span> Reconciliation</a>';
        $added = true;
    }
    $newLines[] = $line;
}

if ($added) {
    file_put_contents($f, implode("\n", $newLines));
    echo "✅ Bank nav added! Delete this file now.";
} else {
    echo "❌ Could not find setup.php line";
}
