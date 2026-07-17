<?php
// Debug bank_statements.php errors
ini_set('display_errors', 1);
error_reporting(E_ALL);

$f = __DIR__ . '/bank_statements.php';
echo "<h3>Checking bank_statements.php</h3>";

// Check file exists
if (!file_exists($f)) { echo "❌ File not found!"; exit; }
echo "✅ File exists (" . filesize($f) . " bytes)<br>";

// Check parsers folder
$parsersDir = dirname(__DIR__) . '/parsers/';
echo "<br><h3>Checking parsers folder: $parsersDir</h3>";
if (!is_dir($parsersDir)) {
    echo "❌ Parsers folder not found!<br>";
    echo "Parent dir contents:<pre>";
    print_r(scandir(dirname(__DIR__)));
    echo "</pre>";
} else {
    echo "✅ Parsers folder exists<br>";
    echo "Files: " . implode(', ', scandir($parsersDir)) . "<br>";
}

// Check storage folder
$storageDir = dirname(__DIR__) . '/storage/statements/';
echo "<br><h3>Checking storage: $storageDir</h3>";
if (!is_dir($storageDir)) {
    echo "⚠️ Storage folder missing - will try to create<br>";
    if (mkdir($storageDir, 0755, true)) echo "✅ Created!";
    else echo "❌ Cannot create!";
} else {
    echo "✅ Storage exists, writable: " . (is_writable($storageDir)?'yes':'NO') . "<br>";
}

// Try including db.php
echo "<br><h3>Testing db.php include</h3>";
try {
    require_once __DIR__ . '/db.php';
    echo "✅ db.php loaded<br>";
    db()->query("SELECT 1");
    echo "✅ DB connection works<br>";
    // Check tables
    $tables = db()->query("SHOW TABLES LIKE 'bank_%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "Bank tables: " . (empty($tables) ? '❌ NONE - run the SQL first!' : implode(', ', $tables)) . "<br>";
} catch(Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// Try including bank_statements.php with output buffering
echo "<br><h3>Testing bank_statements.php syntax</h3>";
$output = shell_exec('/usr/local/bin/php -l ' . escapeshellarg($f) . ' 2>&1');
echo $output ? htmlspecialchars($output) : "Cannot run php -l";
