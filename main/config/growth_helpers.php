<?php
/**
 * Growth module shared library: loyalty points, tiers, segmentation,
 * and referrals. Used by admin controllers/APIs, the customer-facing
 * loyalty page, and the order-completion hook in order_state_machine.php.
 */

if (defined('GROWTH_HELPERS_LOADED')) {
    return;
}
define('GROWTH_HELPERS_LOADED', true);

/**
 * Self-healing schema creation, matching the pattern used by
 * coupon_operations.php — safe to call on every request, only does work
 * the first time a table/column is missing.
 */
function ensureGrowthSchema(PDO $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $conn->query("SELECT restaurant_id FROM growth_settings LIMIT 1");
    } catch (PDOException $e) {
        $conn->exec("CREATE TABLE IF NOT EXISTS growth_settings (
            restaurant_id VARCHAR(10) NOT NULL PRIMARY KEY,
            loyalty_enabled TINYINT(1) DEFAULT 0,
            earn_points_per_amount INT DEFAULT 1,
            earn_amount_threshold DECIMAL(10,2) DEFAULT 100.00,
            redeem_value_per_point DECIMAL(10,4) DEFAULT 0.50,
            min_redeem_points INT DEFAULT 100,
            referral_enabled TINYINT(1) DEFAULT 0,
            referrer_reward_points INT DEFAULT 50,
            referred_reward_points INT DEFAULT 20,
            lapsed_days_threshold INT DEFAULT 30,
            high_spender_threshold DECIMAL(10,2) DEFAULT 5000.00,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    try {
        $conn->query("SELECT id FROM loyalty_points_ledger LIMIT 1");
    } catch (PDOException $e) {
        $conn->exec("CREATE TABLE IF NOT EXISTS loyalty_points_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(10) NOT NULL,
            customer_id INT NOT NULL,
            order_id INT DEFAULT NULL,
            points_change INT NOT NULL,
            balance_after INT NOT NULL,
            type ENUM('earned','redeemed','referral_bonus','manual_adjust') NOT NULL,
            note VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_restaurant (restaurant_id),
            INDEX idx_customer (customer_id),
            INDEX idx_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    try {
        $conn->query("SELECT id FROM loyalty_tiers LIMIT 1");
    } catch (PDOException $e) {
        $conn->exec("CREATE TABLE IF NOT EXISTS loyalty_tiers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(10) NOT NULL,
            tier_name VARCHAR(50) NOT NULL,
            min_total_spent DECIMAL(10,2) NOT NULL,
            icon VARCHAR(50) DEFAULT 'star',
            sort_order INT DEFAULT 0,
            points_multiplier DECIMAL(3,2) NOT NULL DEFAULT 1.00,
            INDEX idx_restaurant (restaurant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    try {
        $conn->query("SELECT id FROM referral_codes LIMIT 1");
    } catch (PDOException $e) {
        $conn->exec("CREATE TABLE IF NOT EXISTS referral_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(10) NOT NULL,
            customer_id INT NOT NULL,
            code VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_code (restaurant_id, code),
            UNIQUE KEY unique_customer_code (restaurant_id, customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    try {
        $conn->query("SELECT id FROM referrals LIMIT 1");
    } catch (PDOException $e) {
        $conn->exec("CREATE TABLE IF NOT EXISTS referrals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(10) NOT NULL,
            referrer_customer_id INT NOT NULL,
            referred_customer_id INT NOT NULL,
            referral_code VARCHAR(20) NOT NULL,
            status ENUM('pending','completed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            UNIQUE KEY unique_referred (restaurant_id, referred_customer_id),
            INDEX idx_referrer (referrer_customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    try {
        $conn->query("SELECT id FROM loyalty_rewards LIMIT 1");
    } catch (PDOException $e) {
        $conn->exec("CREATE TABLE IF NOT EXISTS loyalty_rewards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(10) NOT NULL,
            menu_item_id INT NOT NULL,
            item_name VARCHAR(200) NOT NULL,
            points_cost INT NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_restaurant (restaurant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // The tables above were originally created without an explicit COLLATE,
    // which on this server's default (utf8mb4_general_ci) mismatched the
    // rest of the schema (utf8mb4_unicode_ci) and broke any query joining
    // them to customers/orders on restaurant_id ("Illegal mix of collations").
    // Self-heal any table still on the wrong collation, once per request.
    static $collationChecked = false;
    if (!$collationChecked) {
        $collationChecked = true;
        try {
            $stmt = $conn->query(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME IN ('growth_settings','loyalty_points_ledger','loyalty_tiers','referral_codes','referrals','loyalty_rewards')
                 AND TABLE_COLLATION != 'utf8mb4_unicode_ci'"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $badTable) {
                $conn->exec("ALTER TABLE `$badTable` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        } catch (PDOException $e) {
            error_log('Growth module collation self-heal failed: ' . $e->getMessage());
        }
    }

    // loyalty_points_ledger.redemption_code / redemption_status — only set
    // for self-serve counter redemptions (order_id IS NULL), so staff have
    // something to look up and mark used instead of just trusting the
    // customer's word. Redemptions applied automatically at website checkout
    // are already tied to a real order_id and need no separate code.
    try {
        $conn->query("SELECT redemption_code FROM loyalty_points_ledger LIMIT 1");
    } catch (PDOException $e) {
        try {
            $conn->exec("ALTER TABLE loyalty_points_ledger
                ADD COLUMN redemption_code VARCHAR(10) DEFAULT NULL,
                ADD COLUMN redemption_status ENUM('pending','used') DEFAULT NULL,
                ADD INDEX idx_redemption_code (redemption_code)");
        } catch (PDOException $e2) {}
    }

    // loyalty_points_ledger.redemption_item_name — set only when a
    // redemption was for a free menu item (loyalty_rewards) rather than a
    // cash-value discount, so the verify screen can show what to hand over.
    try {
        $conn->query("SELECT redemption_item_name FROM loyalty_points_ledger LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE loyalty_points_ledger ADD COLUMN redemption_item_name VARCHAR(200) DEFAULT NULL"); } catch (PDOException $e2) {}
    }

    // Expand the ledger's type ENUM to cover clawback/reversal/expiry
    // entries — MODIFY on an ENUM is safe here since it's additive-only,
    // every existing row's value stays a valid option.
    try {
        $conn->exec("ALTER TABLE loyalty_points_ledger MODIFY COLUMN type
            ENUM('earned','redeemed','referral_bonus','manual_adjust','clawback','redeem_reversed','expired','item_redeemed') NOT NULL");
    } catch (PDOException $e) {}

    // growth_settings.points_expiry_days — 0 means points never expire.
    try {
        $conn->query("SELECT points_expiry_days FROM growth_settings LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE growth_settings ADD COLUMN points_expiry_days INT DEFAULT 0"); } catch (PDOException $e2) {}
    }

    // customers.loyalty_points_balance
    try {
        $conn->query("SELECT loyalty_points_balance FROM customers LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE customers ADD COLUMN loyalty_points_balance INT NOT NULL DEFAULT 0"); } catch (PDOException $e2) {}
    }

    // customers.signup_ip — captured at signup so a referral can be blocked
    // when the "friend" signing up shares the referrer's own signup IP
    // (the most common self-referral farming pattern).
    try {
        $conn->query("SELECT signup_ip FROM customers LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE customers ADD COLUMN signup_ip VARCHAR(45) DEFAULT NULL"); } catch (PDOException $e2) {}
    }

    // orders.loyalty_* columns
    foreach ([
        'loyalty_points_earned' => 'INT DEFAULT 0',
        'loyalty_points_redeemed' => 'INT DEFAULT 0',
        'loyalty_discount_amount' => 'DECIMAL(10,2) DEFAULT 0.00',
    ] as $col => $def) {
        try {
            $conn->query("SELECT $col FROM orders LIMIT 1");
        } catch (PDOException $e) {
            try { $conn->exec("ALTER TABLE orders ADD COLUMN $col $def"); } catch (PDOException $e2) {}
        }
    }

    // loyalty_tiers.points_multiplier — restaurants whose tiers were created
    // before this column existed already have rows; a plain ADD COLUMN above
    // only fires for a fresh table, so back-fill it separately here too.
    try {
        $conn->query("SELECT points_multiplier FROM loyalty_tiers LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE loyalty_tiers ADD COLUMN points_multiplier DECIMAL(3,2) NOT NULL DEFAULT 1.00"); } catch (PDOException $e2) {}
    }

    // customers.google_review_opt_out / google_review_last_prompted_visit —
    // durable per-customer state for the "rate us on Google" prompt: a
    // permanent opt-out flag, plus the total_visits count at which we last
    // asked, so the prompt can re-fire periodically without re-asking on
    // every single order.
    try {
        $conn->query("SELECT google_review_opt_out FROM customers LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE customers ADD COLUMN google_review_opt_out TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e2) {}
    }
    try {
        $conn->query("SELECT google_review_last_prompted_visit FROM customers LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE customers ADD COLUMN google_review_last_prompted_visit INT NOT NULL DEFAULT 0"); } catch (PDOException $e2) {}
    }
}

/**
 * Find the highest tier a customer qualifies for given their lifetime
 * spend, or null if they haven't reached the lowest tier's threshold yet
 * (or no tiers are configured). Tiers are evaluated highest-threshold-first
 * so the first match is always the best one the customer qualifies for.
 */
function getCustomerTier(PDO $conn, string $restaurant_id, float $totalSpent): ?array {
    ensureGrowthSchema($conn);

    $stmt = $conn->prepare(
        "SELECT id, tier_name, min_total_spent, icon, points_multiplier
         FROM loyalty_tiers WHERE restaurant_id = ? ORDER BY min_total_spent DESC"
    );
    $stmt->execute([$restaurant_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tier) {
        if ($totalSpent >= (float)$tier['min_total_spent']) {
            return $tier;
        }
    }
    return null;
}

/**
 * Fetch (creating if necessary) the single settings row for a restaurant.
 */
function getGrowthSettings(PDO $conn, string $restaurant_id): array {
    ensureGrowthSchema($conn);

    $stmt = $conn->prepare("SELECT * FROM growth_settings WHERE restaurant_id = ?");
    $stmt->execute([$restaurant_id]);
    $settings = $stmt->fetch();

    if (!$settings) {
        $conn->prepare("INSERT IGNORE INTO growth_settings (restaurant_id) VALUES (?)")->execute([$restaurant_id]);
        $stmt->execute([$restaurant_id]);
        $settings = $stmt->fetch();
    }

    return $settings ?: [];
}

/**
 * Update whichever settings fields are present in $data.
 */
function saveGrowthSettings(PDO $conn, string $restaurant_id, array $data): void {
    ensureGrowthSchema($conn);
    getGrowthSettings($conn, $restaurant_id); // ensure row exists

    $allowed = [
        'loyalty_enabled', 'earn_points_per_amount', 'earn_amount_threshold',
        'redeem_value_per_point', 'min_redeem_points', 'referral_enabled',
        'referrer_reward_points', 'referred_reward_points',
        'lapsed_days_threshold', 'high_spender_threshold', 'points_expiry_days',
    ];

    $set = [];
    $params = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $data)) {
            $set[] = "$field = ?";
            $params[] = $data[$field];
        }
    }

    if (empty($set)) {
        return;
    }

    $params[] = $restaurant_id;
    $conn->prepare("UPDATE growth_settings SET " . implode(', ', $set) . " WHERE restaurant_id = ?")->execute($params);
}

/**
 * Look up a customer's id by phone (the same identity anchor used
 * everywhere else in the app — see process_website_order.php).
 */
function findCustomerIdByPhone(PDO $conn, string $restaurant_id, string $phone): ?int {
    $stmt = $conn->prepare("SELECT id FROM customers WHERE restaurant_id = ? AND phone = ?");
    $stmt->execute([$restaurant_id, $phone]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

/**
 * Award points for a completed order. Idempotent per order_id: safe to
 * call more than once for the same order (e.g. defensive re-invocation)
 * because it checks the ledger before inserting.
 *
 * Must be called from inside a transaction that already holds a lock on
 * the relevant order row (see order_state_machine.php).
 *
 * @return int|null Points awarded, or null if nothing was awarded.
 */
function awardLoyaltyPoints(PDO $conn, string $restaurant_id, int $customer_id, ?int $order_id, float $order_total): ?int {
    ensureGrowthSchema($conn);

    $settings = getGrowthSettings($conn, $restaurant_id);
    if (empty($settings['loyalty_enabled'])) {
        return null;
    }

    if ($order_id) {
        $check = $conn->prepare("SELECT 1 FROM loyalty_points_ledger WHERE order_id = ? AND type = 'earned' LIMIT 1");
        $check->execute([$order_id]);
        if ($check->fetch()) {
            return null; // already awarded for this order
        }
    }

    $threshold = (float)($settings['earn_amount_threshold'] ?? 0);
    $rate = (int)($settings['earn_points_per_amount'] ?? 0);
    if ($threshold <= 0 || $rate <= 0 || $order_total <= 0) {
        return null;
    }

    $points = (int)floor(($order_total / $threshold) * $rate);
    if ($points <= 0) {
        return null;
    }

    $stmt = $conn->prepare("SELECT loyalty_points_balance, total_spent FROM customers WHERE id = ? AND restaurant_id = ? FOR UPDATE");
    $stmt->execute([$customer_id, $restaurant_id]);
    $customer = $stmt->fetch();
    if (!$customer) {
        return null;
    }

    // Tier bonus: applied on top of the base rate, using lifetime spend as
    // of right now. Note customers.total_spent is incremented when the order
    // is first placed (process_website_order.php), not when it completes —
    // so by the time this runs (on Completed), total_spent already includes
    // *this* order. A customer whose current order pushes them past a tier
    // threshold gets that tier's bonus on this same order, not just future
    // ones. Intentional-enough to keep (it's a nice "you just hit Gold!"
    // moment), but worth knowing if the multiplier ever looks off by one
    // order's worth of spend.
    $tier = getCustomerTier($conn, $restaurant_id, (float)($customer['total_spent'] ?? 0));
    $multiplier = $tier ? (float)$tier['points_multiplier'] : 1.0;
    if ($multiplier > 1.0) {
        $points = (int)floor($points * $multiplier);
    }
    if ($points <= 0) {
        return null;
    }

    $newBalance = (int)$customer['loyalty_points_balance'] + $points;

    $conn->prepare("UPDATE customers SET loyalty_points_balance = ? WHERE id = ?")->execute([$newBalance, $customer_id]);
    $conn->prepare(
        "INSERT INTO loyalty_points_ledger (restaurant_id, customer_id, order_id, points_change, balance_after, type, note)
         VALUES (?, ?, ?, ?, ?, 'earned', ?)"
    )->execute([$restaurant_id, $customer_id, $order_id, $points, $newBalance, $order_id ? ("Earned from order #$order_id" . ($tier && $multiplier > 1.0 ? " ({$tier['tier_name']} tier, {$multiplier}x)" : '')) : 'Earned']);

    if ($order_id) {
        $conn->prepare("UPDATE orders SET loyalty_points_earned = ? WHERE id = ?")->execute([$points, $order_id]);
    }

    return $points;
}

/**
 * Undo whatever loyalty effect an order had, when it's cancelled or its
 * payment is refunded:
 *   - if the order had earned points (only possible once it reached
 *     Completed), claw them back — capped so the balance never goes
 *     negative, since the customer may have already spent them elsewhere.
 *   - if the order had redeemed points (a discount applied at checkout),
 *     give them back — the customer didn't get what they paid points for.
 *
 * Idempotent: checks the ledger for a prior reversal of the same kind
 * before writing another one, so it's safe to call from both the
 * order-status hook (Cancelled) and the payment-status hook (Refunded)
 * without double-reversing if both ever fire for the same order.
 *
 * Must be called from inside a transaction that already holds a lock on
 * the order row (see order_state_machine.php).
 */
function reverseLoyaltyForOrder(PDO $conn, string $restaurant_id, int $order_id, string $reason): void {
    ensureGrowthSchema($conn);

    $orderStmt = $conn->prepare("SELECT customer_phone, loyalty_points_earned, loyalty_points_redeemed FROM orders WHERE id = ? AND restaurant_id = ?");
    $orderStmt->execute([$order_id, $restaurant_id]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || empty($order['customer_phone'])) {
        return;
    }

    $pointsEarned = (int)($order['loyalty_points_earned'] ?? 0);
    $pointsRedeemed = (int)($order['loyalty_points_redeemed'] ?? 0);
    if ($pointsEarned <= 0 && $pointsRedeemed <= 0) {
        return;
    }

    $customerId = findCustomerIdByPhone($conn, $restaurant_id, $order['customer_phone']);
    if (!$customerId) {
        return;
    }

    if ($pointsEarned > 0) {
        $already = $conn->prepare("SELECT 1 FROM loyalty_points_ledger WHERE order_id = ? AND type = 'clawback' LIMIT 1");
        $already->execute([$order_id]);
        if (!$already->fetch()) {
            $stmt = $conn->prepare("SELECT loyalty_points_balance FROM customers WHERE id = ? AND restaurant_id = ? FOR UPDATE");
            $stmt->execute([$customerId, $restaurant_id]);
            $customer = $stmt->fetch();
            if ($customer) {
                // Cap the clawback at the current balance — the customer may
                // have already redeemed points earned from this order.
                $clawback = min($pointsEarned, (int)$customer['loyalty_points_balance']);
                if ($clawback > 0) {
                    $newBalance = (int)$customer['loyalty_points_balance'] - $clawback;
                    $conn->prepare("UPDATE customers SET loyalty_points_balance = ? WHERE id = ?")->execute([$newBalance, $customerId]);
                    $conn->prepare(
                        "INSERT INTO loyalty_points_ledger (restaurant_id, customer_id, order_id, points_change, balance_after, type, note)
                         VALUES (?, ?, ?, ?, ?, 'clawback', ?)"
                    )->execute([$restaurant_id, $customerId, $order_id, -$clawback, $newBalance, "Order #$order_id $reason — points clawed back"]);
                }
            }
        }
    }

    if ($pointsRedeemed > 0) {
        $already = $conn->prepare("SELECT 1 FROM loyalty_points_ledger WHERE order_id = ? AND type = 'redeem_reversed' LIMIT 1");
        $already->execute([$order_id]);
        if (!$already->fetch()) {
            $stmt = $conn->prepare("SELECT loyalty_points_balance FROM customers WHERE id = ? AND restaurant_id = ? FOR UPDATE");
            $stmt->execute([$customerId, $restaurant_id]);
            $customer = $stmt->fetch();
            if ($customer) {
                $newBalance = (int)$customer['loyalty_points_balance'] + $pointsRedeemed;
                $conn->prepare("UPDATE customers SET loyalty_points_balance = ? WHERE id = ?")->execute([$newBalance, $customerId]);
                $conn->prepare(
                    "INSERT INTO loyalty_points_ledger (restaurant_id, customer_id, order_id, points_change, balance_after, type, note)
                     VALUES (?, ?, ?, ?, ?, 'redeem_reversed', ?)"
                )->execute([$restaurant_id, $customerId, $order_id, $pointsRedeemed, $newBalance, "Order #$order_id $reason — redeemed points refunded"]);
            }
        }
    }
}

/**
 * Redeem points for a customer. Throws on invalid redemption (insufficient
 * balance, below minimum, program disabled, etc).
 *
 * When $order_id is given (redemption applied automatically at website
 * checkout), the order itself is the record of what happened — no
 * verification code is needed. When $order_id is null (self-serve counter
 * redemption from the customer's Loyalty page), staff have no other way to
 * confirm the redemption, so a short code is generated and left 'pending'
 * until a staff member looks it up and marks it used (see
 * findRedemptionByCode() / markRedemptionUsed()).
 *
 * @return array{discount_value: float, code: ?string}
 */
function redeemLoyaltyPoints(PDO $conn, string $restaurant_id, int $customer_id, int $points, ?int $order_id = null): array {
    ensureGrowthSchema($conn);

    $settings = getGrowthSettings($conn, $restaurant_id);
    if (empty($settings['loyalty_enabled'])) {
        throw new Exception('Loyalty program is not enabled');
    }

    $minRedeem = (int)($settings['min_redeem_points'] ?? 0);
    if ($points < $minRedeem) {
        throw new Exception("Minimum redemption is {$minRedeem} points");
    }

    $stmt = $conn->prepare("SELECT loyalty_points_balance FROM customers WHERE id = ? AND restaurant_id = ? FOR UPDATE");
    $stmt->execute([$customer_id, $restaurant_id]);
    $customer = $stmt->fetch();
    if (!$customer) {
        throw new Exception('Customer not found');
    }

    if ((int)$customer['loyalty_points_balance'] < $points) {
        throw new Exception('Insufficient points balance');
    }

    $newBalance = (int)$customer['loyalty_points_balance'] - $points;
    $discountValue = round($points * (float)$settings['redeem_value_per_point'], 2);

    $code = null;
    $status = null;
    if ($order_id === null) {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $dupe = $conn->prepare("SELECT 1 FROM loyalty_points_ledger WHERE restaurant_id = ? AND redemption_code = ?");
            $dupe->execute([$restaurant_id, $candidate]);
            if (!$dupe->fetch()) {
                $code = $candidate;
                break;
            }
        }
        if ($code === null) {
            throw new Exception('Could not generate a redemption code, please try again');
        }
        $status = 'pending';
    }

    $conn->prepare("UPDATE customers SET loyalty_points_balance = ? WHERE id = ?")->execute([$newBalance, $customer_id]);
    $conn->prepare(
        "INSERT INTO loyalty_points_ledger (restaurant_id, customer_id, order_id, points_change, balance_after, type, note, redemption_code, redemption_status)
         VALUES (?, ?, ?, ?, ?, 'redeemed', ?, ?, ?)"
    )->execute([
        $restaurant_id, $customer_id, $order_id, -$points, $newBalance,
        $order_id ? "Redeemed for order #$order_id" : ("Redeemed for " . $discountValue . ($code ? " (code: $code)" : '')),
        $code, $status,
    ]);

    if ($order_id) {
        $conn->prepare("UPDATE orders SET loyalty_points_redeemed = ?, loyalty_discount_amount = ? WHERE id = ?")
            ->execute([$points, $discountValue, $order_id]);
    }

    return ['discount_value' => $discountValue, 'code' => $code];
}

/**
 * Look up a pending/used self-serve redemption by its staff-facing code.
 * Read-only — does not mark it used (see markRedemptionUsed()).
 */
function findRedemptionByCode(PDO $conn, string $restaurant_id, string $code): ?array {
    ensureGrowthSchema($conn);

    $stmt = $conn->prepare(
        "SELECT l.id, l.points_change, l.balance_after, l.redemption_status, l.redemption_item_name, l.created_at,
                c.customer_name, c.phone,
                s.redeem_value_per_point
         FROM loyalty_points_ledger l
         JOIN customers c ON c.id = l.customer_id
         LEFT JOIN growth_settings s ON s.restaurant_id = l.restaurant_id
         WHERE l.restaurant_id = ? AND l.redemption_code = ?"
    );
    $stmt->execute([$restaurant_id, strtoupper(trim($code))]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $points = abs((int)$row['points_change']);
    return [
        'ledger_id' => (int)$row['id'],
        'customer_name' => $row['customer_name'],
        'phone' => $row['phone'],
        'points' => $points,
        'item_name' => $row['redemption_item_name'],
        'discount_value' => $row['redemption_item_name'] ? null : round($points * (float)($row['redeem_value_per_point'] ?? 0), 2),
        'status' => $row['redemption_status'],
        'created_at' => $row['created_at'],
    ];
}

/**
 * Mark a self-serve redemption code as used, once staff has honored it at
 * the counter. Throws if the code doesn't exist or was already used, so a
 * code can't be applied twice.
 */
function markRedemptionUsed(PDO $conn, string $restaurant_id, string $code): void {
    ensureGrowthSchema($conn);

    $stmt = $conn->prepare("SELECT id, redemption_status FROM loyalty_points_ledger WHERE restaurant_id = ? AND redemption_code = ? FOR UPDATE");
    $stmt->execute([$restaurant_id, strtoupper(trim($code))]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Redemption code not found');
    }
    if ($row['redemption_status'] === 'used') {
        throw new Exception('This code has already been used');
    }

    $conn->prepare("UPDATE loyalty_points_ledger SET redemption_status = 'used' WHERE id = ?")->execute([$row['id']]);
}

/**
 * Zero out loyalty balances for customers who've gone inactive past their
 * restaurant's configured expiry window (growth_settings.points_expiry_days;
 * 0 or unset means points never expire, and that restaurant is skipped
 * entirely). "Inactive" is measured off last_visit_date — the same column
 * segmentation already uses for "lapsed" — rather than tracking a separate
 * per-point expiry date, since that would need FIFO batch tracking for
 * little practical benefit at this scale.
 *
 * Intended to run periodically (see the lock-file gate in
 * db_connection.php, matching the existing trial-expiry sweep), not on
 * every request — it scans every restaurant with expiry enabled.
 */
function expireInactiveLoyaltyPoints(PDO $conn): void {
    ensureGrowthSchema($conn);

    $restaurants = $conn->query(
        "SELECT restaurant_id, points_expiry_days FROM growth_settings WHERE loyalty_enabled = 1 AND points_expiry_days > 0"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($restaurants as $r) {
        $restaurantId = $r['restaurant_id'];
        $days = (int)$r['points_expiry_days'];

        $stmt = $conn->prepare(
            "SELECT id, loyalty_points_balance FROM customers
             WHERE restaurant_id = ? AND loyalty_points_balance > 0
             AND (last_visit_date IS NULL OR last_visit_date < DATE_SUB(CURDATE(), INTERVAL ? DAY))"
        );
        $stmt->execute([$restaurantId, $days]);
        $expiredCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($expiredCustomers as $customer) {
            $customerId = (int)$customer['id'];
            $balance = (int)$customer['loyalty_points_balance'];
            try {
                $conn->beginTransaction();
                $lockStmt = $conn->prepare("SELECT loyalty_points_balance FROM customers WHERE id = ? FOR UPDATE");
                $lockStmt->execute([$customerId]);
                $current = (int)$lockStmt->fetchColumn();
                if ($current > 0) {
                    $conn->prepare("UPDATE customers SET loyalty_points_balance = 0 WHERE id = ?")->execute([$customerId]);
                    $conn->prepare(
                        "INSERT INTO loyalty_points_ledger (restaurant_id, customer_id, order_id, points_change, balance_after, type, note)
                         VALUES (?, ?, NULL, ?, 0, 'expired', ?)"
                    )->execute([$restaurantId, $customerId, -$current, "Expired after {$days} days of inactivity"]);
                }
                $conn->commit();
            } catch (Exception $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                error_log("Points expiry failed for customer $customerId: " . $e->getMessage());
            }
        }
    }
}

/**
 * List a restaurant's free-item rewards (menu item redeemable for points).
 * $activeOnly filters to is_active=1 — customers should only ever see
 * active rewards, but the admin management screen needs to see all of them.
 */
function getLoyaltyRewards(PDO $conn, string $restaurant_id, bool $activeOnly = false): array {
    ensureGrowthSchema($conn);
    $sql = "SELECT id, menu_item_id, item_name, points_cost, is_active FROM loyalty_rewards WHERE restaurant_id = ?";
    if ($activeOnly) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY points_cost ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$restaurant_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Redeem points for a free menu item instead of a cash discount. Same
 * verification-code + staff-lookup flow as a cash counter redemption
 * (redeemLoyaltyPoints() with order_id=null) — the code just carries the
 * item name instead of a discount amount, so findRedemptionByCode() knows
 * what to show staff.
 *
 * @return array{code: string, item_name: string, points_cost: int}
 */
function redeemItemReward(PDO $conn, string $restaurant_id, int $customer_id, int $reward_id): array {
    ensureGrowthSchema($conn);

    $settings = getGrowthSettings($conn, $restaurant_id);
    if (empty($settings['loyalty_enabled'])) {
        throw new Exception('Loyalty program is not enabled');
    }

    $rewardStmt = $conn->prepare("SELECT item_name, points_cost FROM loyalty_rewards WHERE id = ? AND restaurant_id = ? AND is_active = 1");
    $rewardStmt->execute([$reward_id, $restaurant_id]);
    $reward = $rewardStmt->fetch(PDO::FETCH_ASSOC);
    if (!$reward) {
        throw new Exception('This reward is no longer available');
    }
    $pointsCost = (int)$reward['points_cost'];

    $stmt = $conn->prepare("SELECT loyalty_points_balance FROM customers WHERE id = ? AND restaurant_id = ? FOR UPDATE");
    $stmt->execute([$customer_id, $restaurant_id]);
    $customer = $stmt->fetch();
    if (!$customer) {
        throw new Exception('Customer not found');
    }
    if ((int)$customer['loyalty_points_balance'] < $pointsCost) {
        throw new Exception('Insufficient points balance');
    }

    $newBalance = (int)$customer['loyalty_points_balance'] - $pointsCost;

    $code = null;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $dupe = $conn->prepare("SELECT 1 FROM loyalty_points_ledger WHERE restaurant_id = ? AND redemption_code = ?");
        $dupe->execute([$restaurant_id, $candidate]);
        if (!$dupe->fetch()) {
            $code = $candidate;
            break;
        }
    }
    if ($code === null) {
        throw new Exception('Could not generate a redemption code, please try again');
    }

    $conn->prepare("UPDATE customers SET loyalty_points_balance = ? WHERE id = ?")->execute([$newBalance, $customer_id]);
    $conn->prepare(
        "INSERT INTO loyalty_points_ledger (restaurant_id, customer_id, order_id, points_change, balance_after, type, note, redemption_code, redemption_status, redemption_item_name)
         VALUES (?, ?, NULL, ?, ?, 'item_redeemed', ?, ?, 'pending', ?)"
    )->execute([
        $restaurant_id, $customer_id, -$pointsCost, $newBalance,
        "Redeemed for {$reward['item_name']} (code: $code)", $code, $reward['item_name'],
    ]);

    return ['code' => $code, 'item_name' => $reward['item_name'], 'points_cost' => $pointsCost];
}

/**
 * Classify a customer row (from the `customers` table) into a segment
 * using the restaurant's configured thresholds. Pure function — segments
 * are always computed live from existing columns, never stored, so they
 * can't drift out of sync.
 */
function classifyCustomerSegment(array $customer, array $settings): string {
    $visits = (int)($customer['total_visits'] ?? 0);
    $spent = (float)($customer['total_spent'] ?? 0);
    $lastVisit = $customer['last_visit_date'] ?? null;

    if ($visits <= 1) {
        return 'new';
    }

    $lapsedDays = (int)($settings['lapsed_days_threshold'] ?? 30);
    $lastVisitTs = $lastVisit ? strtotime($lastVisit) : false;
    if ($lastVisitTs === false) {
        // No usable last-visit date (NULL or a zero-date row) despite having
        // more than one visit — treat as lapsed rather than guessing a date.
        return 'lapsed';
    }
    $daysSince = (int)((strtotime('today') - $lastVisitTs) / 86400);
    if ($daysSince > $lapsedDays) {
        return 'lapsed';
    }

    $highSpenderThreshold = (float)($settings['high_spender_threshold'] ?? 5000);
    if ($spent >= $highSpenderThreshold) {
        return 'high_spender';
    }

    return 'repeat';
}

/**
 * Estimate a customer's lifetime value and churn risk from the same
 * columns segmentation already uses (total_visits, total_spent,
 * last_visit_date, created_at) — no new tracking tables needed.
 *
 * - lifetime_value: realized revenue to date (total_spent).
 * - predicted_clv: a simple 2-year-forward projection — average order
 *   value times how often they order (annualized from visits/tenure).
 *   This is intentionally a rough heuristic, not a statistical model: good
 *   enough to rank/prioritize customers, not to bill against.
 * - churn_risk: low/medium/high based on how far past the restaurant's own
 *   "lapsed" threshold (growth_settings.lapsed_days_threshold) the customer
 *   already is, so it stays consistent with the existing lapsed segment
 *   instead of introducing a second, conflicting definition of "at risk".
 */
function computeCustomerClv(array $customer, array $settings): array {
    $visits = max(1, (int)($customer['total_visits'] ?? 0));
    $spent = (float)($customer['total_spent'] ?? 0);
    $lastVisit = $customer['last_visit_date'] ?? null;
    $createdAt = $customer['created_at'] ?? null;

    $avgOrderValue = $spent / $visits;

    $tenureDays = 365;
    if ($createdAt) {
        $createdTs = strtotime($createdAt);
        if ($createdTs !== false) {
            $tenureDays = max(1, (int)((strtotime('today') - $createdTs) / 86400));
        }
    }
    $orderFrequencyPerYear = ($visits / $tenureDays) * 365;
    $predictedClv = round($avgOrderValue * $orderFrequencyPerYear * 2, 2);

    $lapsedDays = max(1, (int)($settings['lapsed_days_threshold'] ?? 30));
    $lastVisitTs = $lastVisit ? strtotime($lastVisit) : false;
    $daysSinceVisit = $lastVisitTs !== false ? (int)((strtotime('today') - $lastVisitTs) / 86400) : $lapsedDays + 1;

    if ($daysSinceVisit > $lapsedDays) {
        $churnRisk = 'high';
    } elseif ($daysSinceVisit > $lapsedDays * 0.5) {
        $churnRisk = 'medium';
    } else {
        $churnRisk = 'low';
    }

    return [
        'avg_order_value' => round($avgOrderValue, 2),
        'lifetime_value' => round($spent, 2),
        'predicted_clv' => $predictedClv,
        'days_since_last_visit' => $daysSinceVisit,
        'churn_risk' => $churnRisk,
    ];
}

/**
 * Get (or lazily create) a customer's shareable referral code.
 */
function generateReferralCode(PDO $conn, string $restaurant_id, int $customer_id, string $customerName = ''): string {
    ensureGrowthSchema($conn);

    $stmt = $conn->prepare("SELECT code FROM referral_codes WHERE restaurant_id = ? AND customer_id = ?");
    $stmt->execute([$restaurant_id, $customer_id]);
    $row = $stmt->fetch();
    if ($row) {
        return $row['code'];
    }

    $prefix = strtoupper(preg_replace('/[^A-Z]/', '', strtoupper(substr($customerName, 0, 4))));
    if ($prefix === '') {
        $prefix = 'FRND';
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = $prefix . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        try {
            $conn->prepare("INSERT INTO referral_codes (restaurant_id, customer_id, code) VALUES (?, ?, ?)")
                ->execute([$restaurant_id, $customer_id, $code]);
            return $code;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'unique_customer_code') !== false) {
                // Another request already created this customer's code — reread it.
                $stmt->execute([$restaurant_id, $customer_id]);
                $row = $stmt->fetch();
                if ($row) {
                    return $row['code'];
                }
            }
            // Otherwise assume a code collision (unique_code) and retry with a new suffix.
        }
    }

    throw new Exception('Could not generate a unique referral code');
}

/**
 * Record a pending referral at signup time and, if referrals are enabled,
 * award the new customer their welcome bonus immediately (the referrer's
 * reward comes later, via completeReferralIfPending(), when the referred
 * customer finishes their first order). No-op if the code doesn't exist
 * for this restaurant, or the customer is referring themselves.
 *
 * $referredIp (the new signup's IP) is checked against the referrer's own
 * signup_ip — the most common farming pattern is one person referring
 * themselves from a second phone number on the same device/network. This
 * won't catch someone using a VPN or a different network, but it's a
 * cheap block for the obvious case with no false positives for genuine
 * referrals (different people are very unlikely to share an IP at
 * signup time).
 */
function recordPendingReferral(PDO $conn, string $restaurant_id, string $referralCode, int $referredCustomerId, string $referredIp = ''): void {
    ensureGrowthSchema($conn);

    $stmt = $conn->prepare("SELECT customer_id FROM referral_codes WHERE restaurant_id = ? AND code = ?");
    $stmt->execute([$restaurant_id, strtoupper(trim($referralCode))]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }

    $referrerId = (int)$row['customer_id'];
    if ($referrerId === $referredCustomerId) {
        return;
    }

    if ($referredIp !== '') {
        $ipStmt = $conn->prepare("SELECT signup_ip FROM customers WHERE id = ? AND restaurant_id = ?");
        $ipStmt->execute([$referrerId, $restaurant_id]);
        $referrerIp = $ipStmt->fetchColumn();
        if ($referrerIp && $referrerIp === $referredIp) {
            error_log("Referral blocked: customer $referredCustomerId shares signup IP with referrer $referrerId (restaurant $restaurant_id)");
            return;
        }
    }

    try {
        $conn->prepare(
            "INSERT INTO referrals (restaurant_id, referrer_customer_id, referred_customer_id, referral_code, status)
             VALUES (?, ?, ?, ?, 'pending')"
        )->execute([$restaurant_id, $referrerId, $referredCustomerId, strtoupper(trim($referralCode))]);
    } catch (PDOException $e) {
        // unique_referred — this customer was already referred by someone; ignore.
        return;
    }

    $settings = getGrowthSettings($conn, $restaurant_id);
    if (empty($settings['referral_enabled'])) {
        return;
    }

    $welcomeBonus = (int)($settings['referred_reward_points'] ?? 0);
    if ($welcomeBonus <= 0) {
        return;
    }

    $stmt = $conn->prepare("SELECT loyalty_points_balance FROM customers WHERE id = ? AND restaurant_id = ? FOR UPDATE");
    $stmt->execute([$referredCustomerId, $restaurant_id]);
    $referred = $stmt->fetch();
    if (!$referred) {
        return;
    }

    $newBalance = (int)$referred['loyalty_points_balance'] + $welcomeBonus;
    $conn->prepare("UPDATE customers SET loyalty_points_balance = ? WHERE id = ?")->execute([$newBalance, $referredCustomerId]);
    $conn->prepare(
        "INSERT INTO loyalty_points_ledger (restaurant_id, customer_id, order_id, points_change, balance_after, type, note)
         VALUES (?, ?, NULL, ?, ?, 'referral_bonus', 'Welcome bonus for joining via referral')"
    )->execute([$restaurant_id, $referredCustomerId, $welcomeBonus, $newBalance]);
}

/**
 * Called when a customer's order transitions to Completed. If they were
 * referred and the referral is still pending, marks it completed and
 * rewards the referrer. No-op otherwise. Safe to call unconditionally.
 */
function completeReferralIfPending(PDO $conn, string $restaurant_id, int $customerId): void {
    ensureGrowthSchema($conn);

    $settings = getGrowthSettings($conn, $restaurant_id);
    if (empty($settings['referral_enabled'])) {
        return;
    }

    $stmt = $conn->prepare(
        "SELECT id, referrer_customer_id FROM referrals
         WHERE restaurant_id = ? AND referred_customer_id = ? AND status = 'pending'
         FOR UPDATE"
    );
    $stmt->execute([$restaurant_id, $customerId]);
    $referral = $stmt->fetch();
    if (!$referral) {
        return;
    }

    $conn->prepare("UPDATE referrals SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$referral['id']]);

    $referrerId = (int)$referral['referrer_customer_id'];
    $bonusPoints = (int)($settings['referrer_reward_points'] ?? 0);
    if ($bonusPoints <= 0) {
        return;
    }

    $stmt = $conn->prepare("SELECT loyalty_points_balance FROM customers WHERE id = ? AND restaurant_id = ? FOR UPDATE");
    $stmt->execute([$referrerId, $restaurant_id]);
    $referrer = $stmt->fetch();
    if (!$referrer) {
        return;
    }

    $newBalance = (int)$referrer['loyalty_points_balance'] + $bonusPoints;
    $conn->prepare("UPDATE customers SET loyalty_points_balance = ? WHERE id = ?")->execute([$newBalance, $referrerId]);
    $conn->prepare(
        "INSERT INTO loyalty_points_ledger (restaurant_id, customer_id, order_id, points_change, balance_after, type, note)
         VALUES (?, ?, NULL, ?, ?, 'referral_bonus', 'Referral bonus: friend completed their first order')"
    )->execute([$restaurant_id, $referrerId, $bonusPoints, $newBalance]);
}
