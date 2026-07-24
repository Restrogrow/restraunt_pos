<?php
/**
 * Migration Script: Set default currency to INR (₹) for all existing restaurants
 * 
 * Run from browser or command line:
 *   php main/admin/set_default_currency.php
 * 
 * This script:
 * 1. Updates all users with NULL or empty currency_symbol to '₹'
 * 2. Shows statistics about what was updated
 */

// Suppress error display, log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "============================================\n";
echo "  Default Currency Migration Script\n";
echo "  Setting all restaurants to INR (₹)\n";
echo "============================================\n\n";

try {
    // Include database connection
    if (file_exists(__DIR__ . '/../db_connection.php')) {
        require_once __DIR__ . '/../db_connection.php';
    } else {
        throw new Exception('Database connection file not found');
    }

    // Get connection
    if (function_exists('getConnection')) {
        $pdo = getConnection();
    } else {
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('Database connection not available');
        }
    }

    // Include unicode utils for currency fixing
    require_once __DIR__ . '/../config/unicode_utils.php';

    // Step 1: Check if currency_symbol column exists
    echo "\n[1] Checking currency_symbol column...\n";
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'currency_symbol'");
        if ($checkCol->rowCount() == 0) {
            echo "    Column 'currency_symbol' does not exist. Adding it...\n";
            $pdo->exec("ALTER TABLE users ADD COLUMN currency_symbol VARCHAR(10) DEFAULT '₹' AFTER restaurant_name");
            echo "    Column created successfully.\n";
        } else {
            echo "    Column 'currency_symbol' already exists.\n";
        }
    } catch (PDOException $e) {
        echo "    Error checking/adding column: " . $e->getMessage() . "\n";
        exit(1);
    }

    // Step 2: Get total user count
    echo "\n[2] Fetching user statistics...\n";
    $totalStmt = $pdo->query("SELECT COUNT(*) FROM users");
    $totalUsers = $totalStmt->fetchColumn();
    echo "    Total users in database: {$totalUsers}\n";

    // Step 3: Count users with NULL or empty currency_symbol
    $nullStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE currency_symbol IS NULL OR currency_symbol = '' OR TRIM(currency_symbol) = ''");
    $nullCount = $nullStmt->fetchColumn();
    echo "    Users with NULL/empty currency_symbol: {$nullCount}\n";

    // Step 4: Count users with non-INR currency
    $nonInrStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE currency_symbol IS NOT NULL AND currency_symbol != '' AND TRIM(currency_symbol) != '' AND currency_symbol != '₹'");
    $nonInrCount = $nonInrStmt->fetchColumn();
    echo "    Users with non-INR currency_symbol: {$nonInrCount}\n";

    // Step 5: Show what currencies are currently in use
    echo "\n[3] Current currency distribution:\n";
    $distStmt = $pdo->query("SELECT COALESCE(NULLIF(TRIM(currency_symbol), ''), '(NULL/empty)') as sym, COUNT(*) as cnt FROM users GROUP BY sym ORDER BY cnt DESC");
    $distributions = $distStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($distributions as $row) {
        echo "    {$row['sym']}: {$row['cnt']} users\n";
    }

    // Step 6: Update NULL/empty to INR
    echo "\n[4] Updating users with NULL/empty currency_symbol to '₹'...\n";
    $updateNullStmt = $pdo->prepare("UPDATE users SET currency_symbol = '₹', updated_at = NOW() WHERE currency_symbol IS NULL OR currency_symbol = '' OR TRIM(currency_symbol) = ''");
    $updateNullStmt->execute();
    $nullUpdated = $updateNullStmt->rowCount();
    echo "    Updated {$nullUpdated} users.\n";

    // Step 7: Fix any corrupted currency symbols using the existing utility function
    echo "\n[5] Fixing potentially corrupted currency symbols...\n";
    $checkStmt = $pdo->query("SELECT id, username, currency_symbol FROM users WHERE currency_symbol IS NOT NULL AND currency_symbol != ''");
    $users = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $fixUpdateStmt = $pdo->prepare("UPDATE users SET currency_symbol = ?, updated_at = NOW() WHERE id = ?");
    $fixedCount = 0;
    $nonInrFixed = 0;
    
    foreach ($users as $user) {
        $oldSymbol = $user['currency_symbol'];
        $newSymbol = fixCurrencySymbol($oldSymbol, '₹');
        
        // If the symbol is different after fixing, update it
        if ($newSymbol !== $oldSymbol) {
            $fixUpdateStmt->execute([$newSymbol, $user['id']]);
            echo "    Fixed user #{$user['id']} ({$user['username']}): '{$oldSymbol}' -> '{$newSymbol}'\n";
            $fixedCount++;
        }
        // If it's still not INR and was originally set to something else, convert to INR
        elseif ($newSymbol !== '₹' && $newSymbol === $oldSymbol) {
            $fixUpdateStmt->execute(['₹', $user['id']]);
            echo "    Converted user #{$user['id']} ({$user['username']}): '{$oldSymbol}' -> '₹'\n";
            $nonInrFixed++;
        }
    }
    
    echo "    Fixed corrupted symbols: {$fixedCount}\n";
    echo "    Converted non-INR to INR: {$nonInrFixed}\n";

    // Step 8: Final verification
    echo "\n[6] Final verification:\n";
    $finalNullStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE currency_symbol IS NULL OR currency_symbol = '' OR TRIM(currency_symbol) = ''");
    $finalNullCount = $finalNullStmt->fetchColumn();
    
    $finalInrStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE currency_symbol = '₹'");
    $finalInrCount = $finalInrStmt->fetchColumn();
    
    $finalOtherStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE currency_symbol IS NOT NULL AND currency_symbol != '' AND TRIM(currency_symbol) != '' AND currency_symbol != '₹'");
    $finalOtherCount = $finalOtherStmt->fetchColumn();
    
    echo "    NULL/empty: {$finalNullCount}\n";
    echo "    INR (₹): {$finalInrCount}\n";
    echo "    Other: {$finalOtherCount}\n";

    echo "\n============================================\n";
    echo "  Migration completed successfully!\n";
    echo "============================================\n";

} catch (Exception $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    exit(1);
}
