<?php
/**
 * Order Abuse Guard
 * ─────────────────────────────────────────────────────────────────────────────
 * Protects the public website order endpoint (process_website_order.php)
 * against fake/spam ordering:
 *
 *   1. Phone validation      — must be a plausible 10-digit Indian mobile
 *   2. Blocklist check       — blocked phones/IPs are rejected outright
 *   3. Rate limiting         — IP flood cap + per-phone daily cap
 *   4. Auto-block (strikes)  — restaurant rejecting/cancelling a customer's
 *                              website order adds a strike; 3 strikes in
 *                              30 days auto-blocks the phone
 *
 * Requires: db_connection.php (getConnection), config/rate_limit.php.
 * The blocklist table is auto-created on first use so order placement never
 * breaks before the migration is run.
 */

if (!function_exists('orderAbuseNormalizePhone')) {

    /**
     * Normalize a phone for storage/matching: keep digits only, strip a
     * leading 91/0 country code. Returns null if nothing remains.
     */
    function orderAbuseNormalizePhone($phone) {
        $digits = preg_replace('/\D+/', '', (string)$phone);
        if ($digits === '') return null;
        if (strlen($digits) > 10 && substr($digits, 0, 2) === '91') $digits = substr($digits, 2);
        if (strlen($digits) === 11 && $digits[0] === '0') $digits = substr($digits, 1);
        return $digits;
    }

    /**
     * Validate the customer phone submitted at checkout. Guests give no other
     * identity, so a garbage phone both breaks callbacks/tracking and lets a
     * joker scatter fake orders across endless numbers. Enforce exactly 10
     * digits starting 6-9 (Indian mobile), allow +91 / 0 prefixes.
     */
    function orderAbuseValidatePhone($phone) {
        $digits = orderAbuseNormalizePhone($phone);
        if ($digits === null || strlen($digits) !== 10 || $digits[0] < '6') {
            return ['valid' => false, 'message' => 'Please enter a valid 10-digit mobile number.'];
        }
        return ['valid' => true, 'value' => $digits];
    }

    /** Best-effort client IP for blocklisting/flood caps. */
    function orderAbuseGetClientIp() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        // Only trust X-Forwarded-For when REMOTE_ADDR is a configured proxy
        // (same rule as getRateLimitIdentifier() in rate_limit.php — XFF is
        // otherwise trivially spoofable and would give each fake order a
        // fresh "IP").
        $trustedProxies = function_exists('env') ? env('TRUSTED_PROXY_IPS', '') : '';
        if ($trustedProxies !== '' && isset($_SERVER['HTTP_X_FORWARDED_FOR']) && in_array($ip, array_map('trim', explode(',', $trustedProxies)), true)) {
            $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($forwarded[0]);
        }
        return $ip !== '' ? $ip : 'unknown';
    }

    /** Ensure the blocklist table exists (cheap, run once per request). */
    function orderAbuseEnsureTables($conn) {
        static $done = false;
        if ($done) return;
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS order_blocklist (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id VARCHAR(10) NOT NULL,
                type ENUM('phone', 'ip') NOT NULL,
                value VARCHAR(64) NOT NULL,
                reason VARCHAR(255) NULL,
                source ENUM('auto', 'manual') NOT NULL DEFAULT 'manual',
                strikes INT NOT NULL DEFAULT 0,
                last_strike_at DATETIME NULL,
                blocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NULL,
                blocked_by VARCHAR(50) NULL,
                UNIQUE KEY uq_block (restaurant_id, type, value),
                INDEX idx_block_lookup (restaurant_id, type, value, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $done = true;
        } catch (Exception $e) {
            error_log('order_abuse_guard: table bootstrap failed: ' . $e->getMessage());
        }
    }

    /**
     * Is this phone or IP blocked for the restaurant? An entry blocks while
     * expires_at IS NULL (permanent) or expires_at > NOW().
     */
    function orderAbuseIsBlocked($conn, $restaurant_id, $phone, $ip) {
        orderAbuseEnsureTables($conn);
        try {
            $stmt = $conn->prepare(
                "SELECT type, value, reason, expires_at FROM order_blocklist
                 WHERE restaurant_id = ? AND type = ? AND value = ?
                 AND (expires_at IS NULL OR expires_at > NOW())
                 AND (source = 'manual' OR reason LIKE '%[AUTO-BLOCKED%]')
                 LIMIT 1"
            );
            foreach ([[2, $phone], [2, $ip]] as $pair) {
                if ($pair[1] === null) continue;
                $stmt->execute([$restaurant_id, $pair[0], $pair[1]]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) return $row;
            }
        } catch (Exception $e) {
            error_log('order_abuse_guard: blocklist check failed (failing open): ' . $e->getMessage());
        }
        return null;
    }

    /**
     * IP flood cap: how many orders has this IP placed for the restaurant in
     * the last $windowMinutes? A genuine customer places one, maybe two.
     */
    function orderAbuseCountRecentOrdersByIp($conn, $restaurant_id, $ip, $windowMinutes = 30) {
        if ($ip === null) return 0;
        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) FROM orders
                 WHERE restaurant_id = ? AND customer_ip = INET6_ATON(?)
                 AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
            );
            $stmt->execute([$restaurant_id, $ip, $windowMinutes]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0; // column not migrated yet — fail open
        }
    }

    /**
     * Per-phone cap: distinct recent orders for this phone at this restaurant.
     * Stops one number from spamming dozens of orders in a day.
     */
    function orderAbuseCountRecentOrdersByPhone($conn, $restaurant_id, $phoneDigits, $windowHours = 24) {
        if ($phoneDigits === null) return 0;
        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) FROM orders
                 WHERE restaurant_id = ? AND customer_phone = ? AND source = 'website'
                 AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)"
            );
            $stmt->execute([$restaurant_id, $phoneDigits, $windowHours]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Fake-order strike: call when the restaurant REJECTS or CANCELS a
     * website order. 3 strikes within 30 days → permanent auto-block; the
     * counter resets if the last strike is older than 30 days so a customer
     * with a genuinely old bad order isn't blocked forever. $notesHint
     * (customer name excerpt) is stored so the admin can recognize patterns.
     */
    function orderAbuseAddStrike($conn, $restaurant_id, $phone, $reason = '', $notesHint = '') {
        $digits = orderAbuseNormalizePhone($phone);
        if ($digits === null || strlen($digits) !== 10) return;
        orderAbuseEnsureTables($conn);
        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare(
                "SELECT id, strikes, last_strike_at, blocked_at FROM order_blocklist
                 WHERE restaurant_id = ? AND type = 'phone' AND value = ?
                 FOR UPDATE"
            );
            $stmt->execute([$restaurant_id, $digits]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $now = time();
            $nowDb = date('Y-m-d H:i:s', $now);
            $hint = function_exists('mb_substr') ? mb_substr(trim((string)$notesHint), 0, 120) : substr(trim((string)$notesHint), 0, 120);
            $logLine = "\n[" . $nowDb . "] " . $reason . ($hint !== '' ? ' — ' . $hint : '');

            if ($row) {
                // Reset the counter when the last strike is older than 30 days.
                $withinWindow = $row['last_strike_at'] !== null
                    && (strtotime($row['last_strike_at']) >= $now - 30 * 86400);
                $strikes = $withinWindow ? ((int)$row['strikes'] + 1) : 1;
                $upd = $conn->prepare(
                    "UPDATE order_blocklist SET strikes = ?, last_strike_at = ?, reason = CONCAT(COALESCE(reason, ''), ?) WHERE id = ?"
                );
                $upd->execute([$strikes, $nowDb, $logLine, $row['id']]);
                $blocklistId = (int)$row['id'];
            } else {
                $strikes = 1;
                $ins = $conn->prepare(
                    "INSERT INTO order_blocklist (restaurant_id, type, value, reason, source, strikes, last_strike_at, blocked_at)
                     VALUES (?, 'phone', ?, '', 'auto', 1, ?, ?)"
                );
                $ins->execute([$restaurant_id, $digits, $nowDb, $nowDb]);
                $blocklistId = (int)$conn->lastInsertId();
            }

            // 3rd strike inside the window → block the number permanently
            // (expires_at stays NULL). Only stamped once, on the transition.
            if ($strikes >= 3) {
                $blk = $conn->prepare(
                    "UPDATE order_blocklist SET
                       reason = CONCAT(COALESCE(reason, ''), ?)
                     WHERE id = ? AND expires_at IS NULL
                       AND (reason IS NULL OR reason NOT LIKE '%[AUTO-BLOCKED%]')"
                );
                $blk->execute(["\n[AUTO-BLOCKED " . $nowDb . "] 3 fake-order strikes within 30 days", $blocklistId]);
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            error_log('order_abuse_guard: addStrike failed: ' . $e->getMessage());
        }
    }

    /** Remove a block (admin unblock). */
    function orderAbuseRemoveBlock($conn, $restaurant_id, $type, $value) {
        orderAbuseEnsureTables($conn);
        try {
            $stmt = $conn->prepare("DELETE FROM order_blocklist WHERE restaurant_id = ? AND type = ? AND value = ?");
            $stmt->execute([$restaurant_id, $type, $value]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}
