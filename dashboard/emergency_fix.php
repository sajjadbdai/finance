<?php
// Emergency fix - restore header.php nav
$f = __DIR__ . '/header.php';
$c = file_get_contents($f);

// Remove the bank nav lines that were inserted (they may have bad characters)
// and restore original

// Fix 1: Remove any duplicate/broken bank statement lines
$c = preg_replace('/\s*<a href="bank_statements\.php"[^>]*>.*?<\/a>\n?/s', '', $c);
$c = preg_replace('/\s*<a href="bank_reconciliation\.php"[^>]*>.*?<\/a>\n?/s', '', $c);

// Fix 2: Make sure setup.php line is intact
if (strpos($c, 'setup.php') === false) {
    // Restore setup line before logout
    $c = str_replace(
        '<a href="/dashboard/login.php?logout=1"',
        '<a href="setup.php"          class="ni <?=$activePage===\'setup\'?\'active\':\'\'>"><span>🤖</span> Bot Setup</a>
  <a href="/dashboard/login.php?logout=1"',
        $c
    );
}

file_put_contents($f, $c);
echo "✅ Header restored! Main page should work now.<br>";
echo "Setup.php in nav: " . (strpos($c,'setup.php')!==false?'✅':'❌') . "<br>";

// Now add bank nav CLEANLY
$old = '  <a href="setup.php"          class="ni <?=$activePage===\'setup\'?\'active\':\'\'>"><span>🤖</span> Bot Setup</a>';
$new = '  <a href="bank_statements.php"    class="ni <?=$activePage===\'bank_statements\'?\'active\':\'\'>"><span>📄</span> Bank Statements</a>
  <a href="bank_reconciliation.php" class="ni <?=$activePage===\'bank_reconciliation\'?\'active\':\'\'>"><span>⚖️</span> Reconciliation</a>
  <a href="setup.php"               class="ni <?=$activePage===\'setup\'?\'active\':\'\'>"><span>🤖</span> Bot Setup</a>';

if (strpos($c, $old) !== false) {
    $c = str_replace($old, $new, $c);
    file_put_contents($f, $c);
    echo "✅ Bank nav added cleanly!";
} else {
    echo "⚠️ Main page restored but bank nav not added - will fix separately.";
}
