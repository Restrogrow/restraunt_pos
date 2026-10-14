<?php
/**
 * Activity log for the owner: who did what in the dashboard (cancelled an order, gave a discount,
 * changed a price, deleted a customer, logged in...). Shown on Staff › Activity log
 * (api/activity_log.php, assets/js/activity-log.js).
 *
 *   logActivity($conn, $restaurantId, 'order.cancel', 'Cancelled order ORD-…', ['reason' => …], 'order', 42);
 *
 * Who did it comes from the session (owner, staff member with their role, branch admin, or
 * RestroGrow support when the superadmin opened the account). Logging never breaks the action:
 * every failure is swallowed. Call it after the change is saved (after commit).
 */

/** Groups shown as filters on the page; the part of the action before the dot. */
function activityGroups(): array {
    return [
        'order' => 'Orders', 'payment' => 'Payments', 'kot' => 'Kitchen', 'pos' => 'POS and discounts',
        'menu' => 'Menu and prices', 'coupon' => 'Coupons', 'customer' => 'Customers', 'staff' => 'Staff',
        'settings' => 'Settings', 'login' => 'Logins',
    ];
}

function activityEnsure(PDO $conn): bool {
    static $ok = null;
    if ($ok === true) return true;
    try {
        if ($conn->query("SHOW TABLES LIKE 'activity_log'")->rowCount() > 0) return $ok = true;
        if ($conn->inTransaction()) return false;   // CREATE TABLE would commit the caller's work
        $conn->exec("CREATE TABLE IF NOT EXISTS activity_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            actor_name VARCHAR(120) NOT NULL DEFAULT '',
            actor_role VARCHAR(60) NOT NULL DEFAULT '',
            staff_id INT NULL,
            action VARCHAR(60) NOT NULL,
            entity VARCHAR(40) NULL,
            entity_id VARCHAR(60) NULL,
            summary VARCHAR(400) NOT NULL,
            details TEXT NULL,
            ip VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_act_rest (restaurant_id, created_at),
            INDEX idx_act_action (restaurant_id, action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return $ok = true;
    } catch (Throwable $e) {
        error_log('activityEnsure: ' . $e->getMessage());
        return false;
    }
}

/** The person behind this request, from the session. */
function activityActor(): array {
    $s = $_SESSION ?? [];
    $role = (string)($s['role'] ?? '');
    $name = (string)($s['username'] ?? '');
    if (!empty($s['superadmin_id']) && empty($s['staff_id'])) {
        $role = 'RestroGrow support';   // the support team opened this account
        if (!empty($s['superadmin_username'])) $name = (string)$s['superadmin_username'];
    }
    if ($role === 'Admin') $role = 'Owner';
    if ($name === '' && $role === '') { $name = 'System'; $role = 'Automatic'; }
    return ['name' => mb_substr($name, 0, 120), 'role' => mb_substr($role, 0, 60), 'staff_id' => isset($s['staff_id']) ? (int)$s['staff_id'] : null];
}

function logActivity(PDO $conn, ?string $restaurantId, string $action, string $summary, array $details = [], ?string $entity = null, $entityId = null, ?array $actor = null): void {
    try {
        if (!$restaurantId) return;
        $actor = $actor ?: activityActor();
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        $row = [$restaurantId, $actor['name'], $actor['role'], $actor['staff_id'] ?? null, mb_substr($action, 0, 60),
            $entity, $entityId === null ? null : mb_substr((string)$entityId, 0, 60), mb_substr($summary, 0, 400),
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, $ip ? mb_substr((string)$ip, 0, 45) : null];
        $write = function () use ($conn, $row) {
            if (!activityEnsure($conn)) return;
            $conn->prepare('INSERT INTO activity_log (restaurant_id, actor_name, actor_role, staff_id, action, entity, entity_id, summary, details, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($row);
        };
        if ($conn->inTransaction() && $conn->query("SHOW TABLES LIKE 'activity_log'")->rowCount() === 0) {
            register_shutdown_function(function () use ($write) { try { $write(); } catch (Throwable $e) {} });   // first entry ever, mid-transaction
            return;
        }
        $write();
    } catch (Throwable $e) {
        error_log('logActivity: ' . $e->getMessage());
    }
}

/** "₹120.00" with the restaurant's own symbol when the caller has it. */
function activityMoney($n, string $sym = '₹'): string {
    return $sym . number_format((float)$n, 2);
}

/** The restaurant's settings row (users) before a save, to say what changed afterwards. */
function activityUserRow(PDO $conn, $userId): array {
    try { $s = $conn->prepare('SELECT * FROM users WHERE id = ?'); $s->execute([$userId]); return $s->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) { return []; }
}

/** Names of the settings that changed between two rows (no values for secrets). */
function activitySettingsChanged(array $before, array $after): array {
    $labels = [
        'restaurant_name' => 'name', 'phone' => 'phone', 'email' => 'email', 'address' => 'address', 'opening_hours' => 'opening hours',
        'minimum_order_value' => 'minimum order', 'enable_gst' => 'GST on/off', 'tax_name' => 'tax name', 'tax_percent' => 'tax %',
        'packaging_charge' => 'packaging charge', 'enable_delivery' => 'delivery on/off', 'enable_takeaway' => 'takeaway on/off',
        'enable_dinein' => 'dine-in on/off', 'enable_reservations' => 'reservations on/off', 'cod_enabled' => 'cash on delivery',
        'delivery_radius_km' => 'delivery radius', 'enable_km_delivery' => 'delivery charge per km', 'delivery_rate_per_km' => 'rate per km',
        'currency_symbol' => 'currency', 'country' => 'country', 'timezone' => 'timezone', 'language' => 'language',
        'phonepe_merchant_id' => 'PhonePe merchant ID', 'phonepe_salt_key' => 'PhonePe key', 'phonepe_environment' => 'PhonePe mode',
        'payment_gateway_mode' => 'payment gateway mode', 'description' => 'description', 'owner_name' => 'owner name',
    ];
    $out = [];
    foreach ($labels as $k => $label) {
        if (!array_key_exists($k, $before) || !array_key_exists($k, $after)) continue;
        $a = (string)$before[$k]; $b = (string)$after[$k];
        if (is_numeric($a) && is_numeric($b) ? abs((float)$a - (float)$b) > 0.0001 : $a !== $b) $out[] = $label;
    }
    return $out;
}
