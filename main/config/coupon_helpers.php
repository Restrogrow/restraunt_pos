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
