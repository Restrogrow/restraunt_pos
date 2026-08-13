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
                 AND TABLE_NAME IN ('growth_settings','loyalty_points_ledger','loyalty_tiers','referral_codes','referrals')
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

    // customers.loyalty_points_balance
    try {
        $conn->query("SELECT loyalty_points_balance FROM customers LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE customers ADD COLUMN loyalty_points_balance INT NOT NULL DEFAULT 0"); } catch (PDOException $e2) {}
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
        'lapsed_days_threshold', 'high_spender_threshold',
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

    $stmt = $conn->prepare("SELECT loyalty_points_balance FROM customers WHERE id = ? AND restaurant_id = ? FOR UPDATE");
    $stmt->execute([$customer_id, $restaurant_id]);
    $customer = $stmt->fetch();
    if (!$customer) {
        return null;
    }

    $newBalance = (int)$customer['loyalty_points_balance'] + $points;

    $conn->prepare("UPDATE customers SET loyalty_points_balance = ? WHERE id = ?")->execute([$newBalance, $customer_id]);
    $conn->prepare(
        "INSERT INTO loyalty_points_ledger (restaurant_id, customer_id, order_id, points_change, balance_after, type, note)
         VALUES (?, ?, ?, ?, ?, 'earned', ?)"
    )->execute([$restaurant_id, $customer_id, $order_id, $points, $newBalance, $order_id ? "Earned from order #$order_id" : 'Earned']);

    if ($order_id) {
        $conn->prepare("UPDATE orders SET loyalty_points_earned = ? WHERE id = ?")->execute([$points, $order_id]);
    }

    return $points;
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
        "SELECT l.id, l.points_change, l.balance_after, l.redemption_status, l.created_at,
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
        'discount_value' => round($points * (float)($row['redeem_value_per_point'] ?? 0), 2),
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
 */
function recordPendingReferral(PDO $conn, string $restaurant_id, string $referralCode, int $referredCustomerId): void {
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
