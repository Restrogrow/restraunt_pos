<?php
/**
 * Fix Currency Symbol Encoding
 * Run this once to fix corrupted currency symbols in database
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('default_charset', 'UTF-8');

// Set UTF-8 headers
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/config/unicode_utils.php';

echo "<pre style='font-family: Consolas, monospace; background: #1e1e1e; color: #0f0; padding: 20px;'>";
echo "===========================================\n";
echo "   FIX CURRENCY SYMBOL ENCODING\n";
echo "===========================================\n\n";

try {
    global $pdo;
    $conn = $pdo;
    
    // Get all users with currency symbols
    $stmt = $conn->query("SELECT id, username, restaurant_name, currency_symbol FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($users) . " users\n\n";
    
    $fixed = 0;
    foreach ($users as $user) {
        $old_symbol = $user['currency_symbol'] ?? '';
        
        // Use centralized fix function
        $new_symbol = fixCurrencySymbol($old_symbol);
        
        // Update if changed or empty
        if ($new_symbol !== $old_symbol || empty($old_symbol)) {
            $update_stmt = $conn->prepare("UPDATE users SET currency_symbol = ? WHERE id = ?");
            $update_stmt->execute([$new_symbol, $user['id']]);
            
            echo "✓ Fixed user #{$user['id']} ({$user['username']}):\n";
            echo "  Old: " . bin2hex($old_symbol) . " ('{$old_symbol}')\n";
            echo "  New: " . bin2hex($new_symbol) . " ('{$new_symbol}')\n\n";
            $fixed++;
        } else {
            echo "✓ User #{$user['id']} ({$user['username']}): OK ('{$old_symbol}')\n";
        }
    }
    
    echo "\n===========================================\n";
    echo "   FIX COMPLETE!\n";
    echo "===========================================\n";
    echo "Fixed: $fixed users\n";
    echo "\n⚠️  DELETE THIS FILE AFTER RUNNING!\n";
    echo "</pre>";
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    echo "</pre>";
}
?>

