<?php
/**
 * Run on server to analyze existing structure
 * Visit: https://finance.sajjad.bd/dashboard/analyze_request.php
 */
$dashboard = __DIR__;

echo "<h2>Dashboard Files</h2><pre>";
foreach(glob($dashboard.'/*.php') as $f) {
    echo basename($f) . " (" . number_format(filesize($f)) . " bytes)\n";
}
echo "</pre>";

echo "<h2>Database Tables</h2><pre>";
require_once $dashboard.'/db.php';
$tables = db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $t) {
    $cols = db()->query("DESCRIBE `$t`")->fetchAll();
    echo "\n=== $t ===\n";
    foreach($cols as $c) {
        echo "  {$c['Field']} {$c['Type']} {$c['Key']}\n";
    }
}
echo "</pre>";

echo "<h2>Sample Data Counts</h2><pre>";
foreach(['accounts','transactions','portfolio','scheduled_payments','exchange_rates'] as $t) {
    try {
        $cnt = db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "$t: $cnt rows\n";
    } catch(Exception $e) { echo "$t: error\n"; }
}
echo "</pre>";

echo "<h2>Config Keys</h2><pre>";
$cfg = file_get_contents($dashboard.'/../config.php');
preg_match_all("/define\('([^']+)'/", $cfg, $m);
foreach($m[1] as $k) echo "$k\n";
echo "</pre>";
