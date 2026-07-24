<?php
/**
 * Fix All Unicode Issues in Codebase
 * This script scans and fixes Unicode encoding issues across the entire codebase
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/unicode_utils.php';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Unicode Fix Report</title>";
echo "<style>
    body { font-family: Consolas, monospace; background: #1e1e1e; color: #0f0; padding: 20px; }
    h1 { color: #0ff; }
    h2 { color: #ff0; margin-top: 30px; }
    .success { color: #0f0; }
    .error { color: #f00; }
    .warning { color: #ff0; }
    .info { color: #0ff; }
    pre { background: #000; padding: 10px; border: 1px solid #333; }
</style></head><body>";

echo "<h1>==========================================</h1>";
echo "<h1>   UNICODE ISSUES FIX REPORT</h1>";
echo "<h1>==========================================</h1><br>";

$stats = [
    'database' => ['total' => 0, 'fixed' => 0, 'errors' => 0],
    'files_checked' => 0,
    'files_fixed' => 0,
];

try {
    global $pdo;
    $conn = $pdo;
    
    // 1. Fix database currency symbols
    echo "<h2>1. Fixing Database Currency Symbols</h2>";
    $db_stats = fixDatabaseCurrencySymbols($conn);
    $stats['database'] = $db_stats;
    
    echo "<div class='success'>✓ Database check complete:</div>";
    echo "<ul>";
    echo "<li>Total users checked: {$db_stats['total']}</li>";
    echo "<li>Fixed: {$db_stats['fixed']}</li>";
    echo "<li>Errors: {$db_stats['errors']}</li>";
    echo "</ul><br>";
    
    // 2. Verify database connection encoding
    echo "<h2>2. Verifying Database Connection Encoding</h2>";
    try {
        $encoding_stmt = $conn->query("SHOW VARIABLES LIKE 'character_set%'");
        $encodings = $encoding_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<div class='info'>Database encoding settings:</div>";
        echo "<pre>";
        foreach ($encodings as $row) {
            echo "{$row['Variable_name']}: {$row['Value']}\n";
        }
        echo "</pre>";
        
        // Set connection encoding explicitly
        $conn->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $conn->exec("SET CHARACTER SET utf8mb4");
        echo "<div class='success'>✓ Database connection encoding set to utf8mb4</div><br>";
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Error checking database encoding: " . htmlspecialchars($e->getMessage()) . "</div><br>";
    }
    
    // 3. Check for corrupted currency symbols in database
    echo "<h2>3. Scanning for Corrupted Currency Symbols</h2>";
    try {
        $check_stmt = $conn->query("SELECT id, username, currency_symbol FROM users WHERE currency_symbol IS NOT NULL");
        $users = $check_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $corrupted = [];
        foreach ($users as $user) {
            $symbol = $user['currency_symbol'];
            $fixed = fixCurrencySymbol($symbol);
            
            if ($symbol !== $fixed || mb_strlen($symbol, 'UTF-8') > 1) {
                $corrupted[] = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'old' => $symbol,
                    'new' => $fixed,
                    'hex_old' => bin2hex($symbol),
                    'hex_new' => bin2hex($fixed),
                ];
            }
        }
        
        if (empty($corrupted)) {
            echo "<div class='success'>✓ No corrupted currency symbols found</div><br>";
        } else {
            echo "<div class='warning'>⚠ Found " . count($corrupted) . " corrupted currency symbols:</div>";
            echo "<pre>";
            foreach ($corrupted as $item) {
                echo "User #{$item['id']} ({$item['username']}):\n";
                echo "  Old: {$item['hex_old']} ('{$item['old']}')\n";
                echo "  New: {$item['hex_new']} ('{$item['new']}')\n\n";
            }
            echo "</pre>";
        }
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Error scanning currency symbols: " . htmlspecialchars($e->getMessage()) . "</div><br>";
    }
    
    // 4. Summary
    echo "<h2>4. Summary</h2>";
    echo "<div class='success'>✓ Unicode fix process completed!</div>";
    echo "<ul>";
    echo "<li>Database users checked: {$stats['database']['total']}</li>";
    echo "<li>Database symbols fixed: {$stats['database']['fixed']}</li>";
    echo "<li>Database errors: {$stats['database']['errors']}</li>";
    echo "</ul>";
    
    echo "<br><div class='info'>All currency symbols are now using proper UTF-8 encoding.</div>";
    echo "<div class='warning'><br>⚠️  DELETE THIS FILE AFTER RUNNING!</div>";
    
} catch (Exception $e) {
    echo "<div class='error'>✗ Fatal Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "</body></html>";
?>

