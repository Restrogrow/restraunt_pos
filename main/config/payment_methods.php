<?php
/**
 * Shared helpers for the restaurant-scoped payment_methods table.
 * Used by get_payment_methods.php, save_payment_method.php, and
 * payment_method_qr.php.
 */

if (!function_exists('ensurePaymentMethodsTable')) {
    function ensurePaymentMethodsTable(PDO $conn) {
        try {
            $conn->query("SELECT id FROM payment_methods LIMIT 1");
        } catch (PDOException $e) {
            $conn->exec("CREATE TABLE IF NOT EXISTS payment_methods (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id VARCHAR(20) NOT NULL,
                method_name VARCHAR(100) NOT NULL,
                emoji VARCHAR(10) DEFAULT '💳',
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_builtin TINYINT(1) NOT NULL DEFAULT 0,
                qr_image LONGBLOB DEFAULT NULL,
                qr_image_mime VARCHAR(50) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_paymethod_restaurant (restaurant_id),
                INDEX idx_paymethod_active (restaurant_id, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }
}

if (!function_exists('seedBuiltinPaymentMethods')) {
    /**
     * Every restaurant needs its built-in methods available even before they ever
     * open the Payment Methods settings page — this seeds them once, the first
     * time this restaurant is queried and has zero rows: Cash/Card/UPI, or in
     * Nepal (no UPI there) Cash/Card/eSewa/Khalti.
     * A Nepal restaurant seeded with UPI earlier gets it swapped once: the built-in
     * UPI becomes eSewa, and Khalti is added.
     */
    function seedBuiltinPaymentMethods(PDO $conn, string $restaurantId) {
        $nepal = paymentMethodsCountryIsNepal($conn, $restaurantId);
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM payment_methods WHERE restaurant_id = ?");
        $countStmt->execute([$restaurantId]);
        if ((int)$countStmt->fetchColumn() > 0) {
            if ($nepal) {
                $swap = $conn->prepare("UPDATE payment_methods SET method_name = 'eSewa', emoji = '🟢' WHERE restaurant_id = ? AND is_builtin = 1 AND method_name = 'UPI'
                                        AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM payment_methods WHERE restaurant_id = ? AND method_name = 'eSewa') e)");
                $swap->execute([$restaurantId, $restaurantId]);
                $has = $conn->prepare("SELECT COUNT(*) FROM payment_methods WHERE restaurant_id = ? AND method_name = 'Khalti'");
                $has->execute([$restaurantId]);
                if ($swap->rowCount() > 0 && (int)$has->fetchColumn() === 0) {   // only right after the swap: an owner who later removes Khalti keeps it removed
                    $conn->prepare("INSERT INTO payment_methods (restaurant_id, method_name, emoji, display_order, is_active, is_builtin)
                                    SELECT ?, 'Khalti', '🟣', COALESCE(MAX(display_order), 0) + 1, 1, 1 FROM payment_methods WHERE restaurant_id = ?")
                         ->execute([$restaurantId, $restaurantId]);
                }
            }
            return;
        }

        $builtins = $nepal
            ? [['Cash', '💵', 0], ['Card', '💳', 1], ['eSewa', '🟢', 2], ['Khalti', '🟣', 3]]
            : [['Cash', '💵', 0], ['Card', '💳', 1], ['UPI', '📱', 2]];
        $insertStmt = $conn->prepare("INSERT INTO payment_methods (restaurant_id, method_name, emoji, display_order, is_active, is_builtin) VALUES (?, ?, ?, ?, 1, 1)");
        foreach ($builtins as $b) {
            $insertStmt->execute([$restaurantId, $b[0], $b[1], $b[2]]);
        }
    }
}

if (!function_exists('paymentMethodsCountryIsNepal')) {
    /** The restaurant is set to Nepal (users.country), where wallets are eSewa / Khalti, not UPI. */
    function paymentMethodsCountryIsNepal(PDO $conn, string $restaurantId): bool {
        try {
            $st = $conn->prepare("SELECT country FROM users WHERE restaurant_id = ? ORDER BY id LIMIT 1");
            $st->execute([$restaurantId]);
            return strcasecmp(trim((string)$st->fetchColumn()), 'Nepal') === 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}
