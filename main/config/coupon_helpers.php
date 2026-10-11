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
