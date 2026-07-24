<?php
/**
 * Unicode and Currency Symbol Utilities
 * Centralized functions for fixing Unicode encoding issues, especially currency symbols
 */

if (!function_exists('fixCurrencySymbol')) {
    /**
     * Fix corrupted currency symbols
     * Handles common Unicode corruption patterns for currency symbols
     * 
     * @param string $currency_symbol The potentially corrupted currency symbol
     * @param string $default Default currency symbol if fixing fails (default: '₹')
     * @return string Clean, valid UTF-8 currency symbol
     */
    function fixCurrencySymbol($currency_symbol, $default = '₹') {
        if (empty($currency_symbol)) {
            return $default;
        }
        
        // Trim whitespace
        $currency_symbol = trim($currency_symbol);
        
        if (empty($currency_symbol)) {
            return $default;
        }
        
        // Currency symbol mappings (common corrupted patterns)
        $currency_fixes = [
            // Indian Rupee (₹) corruption patterns
            'Γé╣' => '₹',
            'â‚¹' => '₹',
            'â€¹' => '₹',
            'Ã¢â€šÂ¹' => '₹',
            'â¹' => '₹',
            'â€¹' => '₹',
            // Other common corruptions
            'Ã¢â€šÂ¬' => '€',  // Euro
            'Ã‚Â£' => '£',     // Pound
            'Ã‚Â¥' => '¥',     // Yen
            'Ã‚Â$' => '$',     // Dollar
        ];
        
        // Check if symbol matches any corruption pattern
        foreach ($currency_fixes as $corrupted => $correct) {
            if (strpos($currency_symbol, $corrupted) !== false) {
                $currency_symbol = $correct;
                break;
            }
        }
        
        // Ensure valid UTF-8 encoding
        if (!mb_check_encoding($currency_symbol, 'UTF-8')) {
            $currency_symbol = mb_convert_encoding($currency_symbol, 'UTF-8', 'UTF-8');
        }
        
        // Validate: ensure it's not empty and is valid UTF-8
        $length = mb_strlen($currency_symbol, 'UTF-8');
        if ($length === 0) {
            return $default;
        }
        
        // Ensure valid UTF-8 encoding and return
        $cleaned = mb_convert_encoding($currency_symbol, 'UTF-8', 'UTF-8');
        if ($cleaned === false || $cleaned === '') {
            return $default;
        }
        
        return $cleaned;
    }
}





if (!function_exists('fixDatabaseCurrencySymbols')) {
    /**
     * Fix all currency symbols in the database
     * 
     * @param PDO $pdo Database connection
     * @return array Statistics about the fix operation
     */
    function fixDatabaseCurrencySymbols($pdo) {
        $stats = [
            'total' => 0,
            'fixed' => 0,
            'errors' => 0,
        ];
        
        try {
            // Get all users with currency symbols
            $stmt = $pdo->query("SELECT id, username, currency_symbol FROM users WHERE currency_symbol IS NOT NULL");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stats['total'] = count($users);
            
            $update_stmt = $pdo->prepare("UPDATE users SET currency_symbol = ? WHERE id = ?");
            
            foreach ($users as $user) {
                $old_symbol = $user['currency_symbol'] ?? '';
                $new_symbol = fixCurrencySymbol($old_symbol);
                
                if ($new_symbol !== $old_symbol) {
                    try {
                        $update_stmt->execute([$new_symbol, $user['id']]);
                        $stats['fixed']++;
                    } catch (PDOException $e) {
                        $stats['errors']++;
                        error_log("Error fixing currency for user #{$user['id']}: " . $e->getMessage());
                    }
                }
            }
        } catch (PDOException $e) {
            $stats['errors']++;
            error_log("Error in fixDatabaseCurrencySymbols: " . $e->getMessage());
        }
        
        return $stats;
    }
}

