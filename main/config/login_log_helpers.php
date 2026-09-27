<?php
/**
 * Login security logs + IP blocking.
 *
 * Every login attempt (success, wrong password, locked out, blocked IP) is
 * recorded in login_logs with the requester's IP and a device fingerprint,
 * so the owner can see exactly who logged in when, from where, and on what.
 * Admins can block abusive IPs with one tap in the app's Logs screen.
 *
 * Lockout policy (enforced in rate_limit.php, mirrored here for logging):
 * 3 failed attempts within 15 minutes -> 30-minute lockout. Every attempt
 * after the 3rd still logs, so brute-force patterns are fully visible.
 */

if (!defined('LOGIN_LOG_MAX_ATTEMPTS')) {
    define('LOGIN_LOG_MAX_ATTEMPTS', 3);
}
if (!defined('LOGIN_LOG_LOCKOUT_MINUTES')) {
    define('LOGIN_LOG_LOCKOUT_MINUTES', 30);
}

if (!function_exists('loginLogEnsureTables')) {
    /**
     * Creates login_logs + blocked_ips on first use. Mirrors the
     * "ensure tables" pattern of inventory_tables.php — no formal migration
     * system in this codebase. Cheap after first call (two SELECT probes).
     */
    function loginLogEnsureTables($conn) {
        try { $conn->query("SELECT id FROM login_logs LIMIT 1"); } catch (PDOException $e) {
            $conn->exec("CREATE TABLE IF NOT EXISTS login_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id VARCHAR(10) DEFAULT NULL,
                username_tried VARCHAR(100) DEFAULT NULL,
                user_type ENUM('admin','staff','branch_admin','unknown') DEFAULT 'unknown',
                actor_name VARCHAR(100) DEFAULT NULL,
                outcome ENUM('success','failed','locked_out','blocked_ip','logout') NOT NULL DEFAULT 'failed',
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                device_label VARCHAR(80) DEFAULT NULL,
                device_hash CHAR(32) DEFAULT NULL,
                is_new_device TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ll_restaurant (restaurant_id),
                INDEX idx_ll_created (created_at),
                INDEX idx_ll_outcome (outcome),
                INDEX idx_ll_rest_created (restaurant_id, created_at)
            )");
        }

        try { $conn->query("SELECT id FROM blocked_ips LIMIT 1"); } catch (PDOException $e) {
            $conn->exec("CREATE TABLE IF NOT EXISTS blocked_ips (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id VARCHAR(10) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                reason VARCHAR(255) DEFAULT NULL,
                blocked_by VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_blocked_rest_ip (restaurant_id, ip_address),
                INDEX idx_bi_restaurant (restaurant_id)
            )");
        }
    }
}

if (!function_exists('loginLogClientIp')) {
    /**
     * Same trust model as rate_limit.php's getRateLimitIdentifier(): the TCP
     * peer (REMOTE_ADDR) is authoritative; X-Forwarded-For is only honored
     * when REMOTE_ADDR is a configured trusted proxy. Behind Hostinger's
     * proxy set TRUSTED_PROXY_IPS in .env, otherwise you'll see the proxy IP.
     */
    function loginLogClientIp() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $trustedProxies = function_exists('env') ? env('TRUSTED_PROXY_IPS', '') : '';
        if ($trustedProxies !== '' && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $trustedList = array_map('trim', explode(',', $trustedProxies));
            if (in_array($ip, $trustedList, true)) {
                $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $candidate = trim($forwarded[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    $ip = $candidate;
                }
            }
        }
        return substr($ip, 0, 45);
    }
}

if (!function_exists('loginLogDeviceLabel')) {
    /**
     * Short human-readable device description from the User-Agent, e.g.
     * "Android · Chrome", "Windows · Edge", "iPhone · Safari".
     */
    function loginLogDeviceLabel($ua) {
        $ua = strtolower($ua);
        $os = 'Unknown OS';
        if (strpos($ua, 'android') !== false) $os = 'Android';
        elseif (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) $os = 'iOS';
        elseif (strpos($ua, 'windows') !== false) $os = 'Windows';
        elseif (strpos($ua, 'mac os') !== false || strpos($ua, 'macintosh') !== false) $os = 'Mac';
        elseif (strpos($ua, 'cros') !== false) $os = 'ChromeOS';
        elseif (strpos($ua, 'linux') !== false) $os = 'Linux';

        $browser = 'Unknown browser';
        if (strpos($ua, 'edg/') !== false || strpos($ua, 'edga') !== false) $browser = 'Edge';
        elseif (strpos($ua, 'opr/') !== false || strpos($ua, 'opera') !== false) $browser = 'Opera';
        elseif (strpos($ua, 'chrome') !== false && strpos($ua, 'chromium') === false) $browser = 'Chrome';
        elseif (strpos($ua, 'firefox') !== false) $browser = 'Firefox';
        elseif (strpos($ua, 'safari') !== false) $browser = 'Safari';
        elseif (strpos($ua, 'okhttp') !== false) $browser = 'App (native)';
        elseif (strpos($ua, 'dart') !== false || strpos($ua, 'curl') !== false) $browser = 'Script';

        return $os . ' · ' . $browser;
    }
}

if (!function_exists('loginLogWrite')) {
    /**
     * Best-effort audit write. Logging failures must never break login, so
     * everything is swallowed into error_log.
     *
     * $args keys: restaurant_id, username, user_type, actor_name, outcome
     *             (success|failed|locked_out|blocked_ip|logout),
     *             is_new_device (bool)
     */
    function loginLogWrite($conn, array $args) {
        try {
            loginLogEnsureTables($conn);

            $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $isNew = !empty($args['is_new_device']) ? 1 : 0;

            $stmt = $conn->prepare("
                INSERT INTO login_logs
                    (restaurant_id, username_tried, user_type, actor_name, outcome,
                     ip_address, user_agent, device_label, device_hash, is_new_device)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $args['restaurant_id'] ?? null,
                isset($args['username']) ? substr((string)$args['username'], 0, 100) : null,
                in_array($args['user_type'] ?? '', ['admin', 'staff', 'branch_admin', 'unknown'], true) ? $args['user_type'] : 'unknown',
                isset($args['actor_name']) ? substr((string)$args['actor_name'], 0, 100) : null,
                in_array($args['outcome'] ?? '', ['success', 'failed', 'locked_out', 'blocked_ip', 'logout'], true) ? $args['outcome'] : 'failed',
                loginLogClientIp(),
                $ua,
                loginLogDeviceLabel($ua),
                md5($ua),
                $isNew,
            ]);
        } catch (Exception $e) {
            error_log('loginLogWrite failed (non-fatal): ' . $e->getMessage());
        } catch (Error $e) {
            error_log('loginLogWrite failed (non-fatal): ' . $e->getMessage());
        }
    }
}

if (!function_exists('loginLogIsNewDevice')) {
    /**
     * True when this user-agent hash has never logged in successfully for
     * this restaurant before. Called BEFORE the success row is written, so
     * the "new device" flag lands on the login that introduced the device.
     */
    function loginLogIsNewDevice($conn, $restaurantId) {
        try {
            $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
            if ($ua === '') {
                return false; // nothing to fingerprint — can't call it new
            }
            $hash = md5($ua);
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM login_logs
                WHERE restaurant_id = ? AND device_hash = ? AND outcome = 'success'
            ");
            $stmt->execute([$restaurantId, $hash]);
            return (int)$stmt->fetchColumn() === 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('loginLogIsBlocked')) {
    /**
     * Blocklist check. IMPORTANT: unlike the per-restaurant login_logs view,
     * the blocklist is intentionally GLOBAL — a brute-forcing IP gets
     * rejected everywhere, not just for the restaurant whose owner blocked
     * it. Returns the blocking row (for logging which rule fired) or null.
     */
    function loginLogIsBlocked($conn, $ip) {
        try {
            loginLogEnsureTables($conn);
            $stmt = $conn->prepare("SELECT id, reason, blocked_by FROM blocked_ips WHERE ip_address = ? LIMIT 1");
            $stmt->execute([$ip]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) {
            error_log('loginLogIsBlocked failed (fail-open): ' . $e->getMessage());
            return null; // fail-open: a broken blocklist must not lock everyone out
        }
    }
}

if (!function_exists('loginLogBlockIp')) {
    /**
     * Adds an IP to the blocklist. Idempotent (unique key) — returns true
     * when the IP is blocked (freshly or already), false on DB error.
     */
    function loginLogBlockIp($conn, $restaurantId, $ip, $reason, $blockedBy) {
        try {
            loginLogEnsureTables($conn);
            $stmt = $conn->prepare("
                INSERT INTO blocked_ips (restaurant_id, ip_address, reason, blocked_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE reason = VALUES(reason), blocked_by = VALUES(blocked_by)
            ");
            $stmt->execute([$restaurantId, $ip, $reason, $blockedBy]);
            return true;
        } catch (Exception $e) {
            error_log('loginLogBlockIp failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('loginLogUnblockIp')) {
    function loginLogUnblockIp($conn, $ip) {
        try {
            loginLogEnsureTables($conn);
            $stmt = $conn->prepare("DELETE FROM blocked_ips WHERE ip_address = ?");
            $stmt->execute([$ip]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log('loginLogUnblockIp failed: ' . $e->getMessage());
            return false;
        }
    }
}
