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
     * Every restaurant needs Cash/Card/UPI available even before they ever
     * open the Payment Methods settings page — this seeds those three once,
     * the first time this restaurant is queried and has zero rows.
     */
    function seedBuiltinPaymentMethods(PDO $conn, string $restaurantId) {
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM payment_methods WHERE restaurant_id = ?");
        $countStmt->execute([$restaurantId]);
        if ((int)$countStmt->fetchColumn() > 0) {
            return;
        }

        $builtins = [
            ['Cash', '💵', 0],
            ['Card', '💳', 1],
            ['UPI',  '📱', 2],
        ];
        $insertStmt = $conn->prepare("INSERT INTO payment_methods (restaurant_id, method_name, emoji, display_order, is_active, is_builtin) VALUES (?, ?, ?, ?, 1, 1)");
        foreach ($builtins as $b) {
            $insertStmt->execute([$restaurantId, $b[0], $b[1], $b[2]]);
        }
    }
}
