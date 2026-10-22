<?php
/**
 * Coupons: "milestone" offers (Offers > Coupons → Milestone offer).
 *
 * A milestone coupon has a minimum order amount and is shown on the cart's
 * progress bar ("Add ₹40 more to get ₹50 OFF"); the best one the order has
 * reached is applied automatically. The checkout still validates every coupon
 * on the server (process_website_order.php) exactly like a typed-in code.
 */
if (!function_exists('ensureCouponMilestoneColumn')) {
    function ensureCouponMilestoneColumn($conn) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $col = $conn->query("SHOW COLUMNS FROM coupons LIKE 'is_milestone'");
            if ($col && $col->fetch()) return;
            $conn->exec("ALTER TABLE coupons ADD COLUMN is_milestone TINYINT(1) NOT NULL DEFAULT 0");
        } catch (PDOException $e) {
            error_log('coupon_helpers.php: could not add is_milestone: ' . $e->getMessage());
        }
    }
}

/**
 * Columns an older live coupons table can miss (it was created before some of them existed).
 * Run through rgEnsureOnce(), so it checks once per deploy, not on every request.
 */
if (!function_exists('ensureCouponColumns')) {
    function ensureCouponColumns($conn) {
        try {
            $have = [];
            foreach ($conn->query("SHOW COLUMNS FROM coupons")->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['Field']] = true;
            $need = [
                'discount_type' => "ENUM('percent','flat') NOT NULL DEFAULT 'percent'",
                'minimum_order_amount' => 'DECIMAL(10,2) DEFAULT 0.00',
                'max_uses' => 'INT DEFAULT 0',
                'current_uses' => 'INT DEFAULT 0',
                'valid_from' => 'DATE DEFAULT NULL',
                'valid_until' => 'DATE DEFAULT NULL',
                'is_active' => 'TINYINT(1) DEFAULT 1',
                'description' => "VARCHAR(255) DEFAULT ''",
            ];
            foreach ($need as $col => $def) {
                if (!isset($have[$col])) $conn->exec("ALTER TABLE coupons ADD COLUMN `$col` $def");
            }
        } catch (PDOException $e) {
            error_log('coupon_helpers.php: could not check coupon columns: ' . $e->getMessage());
        }
    }
}

/** A coupon form mistake, written for the owner: shown as it is. */
if (!class_exists('CouponInputError')) {
    class CouponInputError extends Exception {}
}

/**
 * Private coupons (Offers > Scratch card rewards): owner_phone set = only that
 * customer may use it, and it is never listed publicly.
 */
if (!function_exists('ensureCouponOwnerColumn')) {
    function ensureCouponOwnerColumn($conn) {
        // once per deploy, not on every request (config/schema_cache.php)
        require_once __DIR__ . '/schema_cache.php';
        $rgOnceKey = 'e:ensureCouponOwnerColumn:' . @filemtime(__FILE__);
        if (rgSchemaKnown($rgOnceKey) || $conn->inTransaction()) return;
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $col = $conn->query("SHOW COLUMNS FROM coupons LIKE 'owner_phone'");
            if ($col && $col->fetch()) { rgSchemaRemember($rgOnceKey); return; }
            $conn->exec("ALTER TABLE coupons ADD COLUMN owner_phone VARCHAR(20) NULL DEFAULT NULL");
        } catch (PDOException $e) {
            error_log('coupon_helpers.php: could not add owner_phone: ' . $e->getMessage());
        }
            rgSchemaRemember($rgOnceKey);
    }
}
