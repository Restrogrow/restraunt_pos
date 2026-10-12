<?php
/**
 * Loyalty engine (Growth > Loyalty Studio).
 *
 * Program type, chosen by the owner (growth_settings.program_type):
 *   points   — points per ₹, redeemed for discounts / free dishes (growth_helpers.php)
 *   cashback — a % of every completed order back into the customer's wallet
 *              (wallet_helpers.php does the crediting; settings are kept in step)
 *   stamps   — one stamp per qualifying order; a full card issues a reward
 * Tiers sit on top of points and cashback.
 *
 * Every gift (stamp card, birthday, win-back, challenge…) is issued by
 * loyaltyIssueReward(): a private one-time coupon for the customer's phone
 * (coupons.owner_phone), logged in loyalty_rewards_issued with its source so
 * revenue can be traced and, later, the gift can be sent on WhatsApp
 * (notify_status = 'pending').
 *
 * loyaltyOnOrderCompleted() runs from the order status hook on Completed,
 * for every channel (website, POS, table QR) as long as the order has a phone.
 */
require_once __DIR__ . '/growth_helpers.php';
require_once __DIR__ . '/coupon_helpers.php';
require_once __DIR__ . '/order_abuse_guard.php';

if (!defined('LOYALTY_ENGINE_VERSION')) define('LOYALTY_ENGINE_VERSION', 2);   // 2: customers.wa_stopped_at (WhatsApp STOP)
if (!defined('LOYALTY_PROGRAM_TYPES')) define('LOYALTY_PROGRAM_TYPES', ['cashback', 'points', 'stamps']);

if (!function_exists('ensureLoyaltyEngineSchema')) {

    function ensureLoyaltyEngineSchema(PDO $conn): void {
        static $done = false;
        if ($done || $conn->inTransaction()) return;   // DDL would commit a caller's transaction
        $done = true;
        ensureGrowthSchema($conn);
        ensureCouponOwnerColumn($conn);
        try {
            if ((int)$conn->query('SELECT v FROM loyalty_engine_version LIMIT 1')->fetchColumn() === LOYALTY_ENGINE_VERSION) return;
        } catch (PDOException $e) {}

        $addCol = function (string $table, string $col, string $def) use ($conn) {
            try {
                $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE " . $conn->quote($col));
                if (!($q && $q->fetch())) $conn->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
            } catch (PDOException $e) { error_log("ensureLoyaltyEngineSchema ($table.$col): " . $e->getMessage()); }
        };
        foreach ([
            'program_type' => "VARCHAR(10) NOT NULL DEFAULT 'points'",
            'cashback_pct' => 'DECIMAL(5,2) NOT NULL DEFAULT 5',
            'cashback_max' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
            'stamp_goal' => 'INT NOT NULL DEFAULT 6',
            'stamp_min_order' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
            'stamp_reward_kind' => "VARCHAR(10) NOT NULL DEFAULT 'item'",
            'stamp_reward_item_id' => 'INT NULL',
            'stamp_reward_value' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
            'reward_valid_days' => 'INT NOT NULL DEFAULT 30',
        ] as $col => $def) $addCol('growth_settings', $col, $def);
        foreach ([
            'birthday' => 'DATE NULL',
            'anniversary' => 'DATE NULL',
            'whatsapp_optin' => 'TINYINT NOT NULL DEFAULT 0',
            'optin_at' => 'DATETIME NULL',
            'wa_stopped_at' => 'DATETIME NULL',   // replied STOP on WhatsApp: only the rewards page opts them back in
            'stamps' => 'INT NOT NULL DEFAULT 0',
        ] as $col => $def) $addCol('customers', $col, $def);

        $conn->exec("CREATE TABLE IF NOT EXISTS loyalty_stamp_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            customer_id INT NOT NULL,
            order_id INT NULL,
            change_count INT NOT NULL,
            balance_after INT NOT NULL,
            note VARCHAR(160) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lsl_cust (restaurant_id, customer_id, id),
            INDEX idx_lsl_order (order_id)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS loyalty_rewards_issued (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            customer_id INT NOT NULL,
            phone VARCHAR(20) NOT NULL,
            source VARCHAR(20) NOT NULL,
            period_key VARCHAR(40) NOT NULL,
            label VARCHAR(120) NOT NULL,
            coupon_code VARCHAR(20) NOT NULL,
            value_type VARCHAR(10) NOT NULL,
            value DECIMAL(10,2) NOT NULL,
            min_order DECIMAL(10,2) NOT NULL DEFAULT 0,
            expires_on DATE NULL,
            notify_status VARCHAR(10) NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_lri (restaurant_id, customer_id, source, period_key),
            INDEX idx_lri_rest (restaurant_id, created_at),
            INDEX idx_lri_code (coupon_code)
        )");

        $conn->exec('CREATE TABLE IF NOT EXISTS loyalty_engine_version (v INT NOT NULL)');
        $conn->exec('DELETE FROM loyalty_engine_version');
        $conn->prepare('INSERT INTO loyalty_engine_version (v) VALUES (?)')->execute([LOYALTY_ENGINE_VERSION]);
    }

    function loyaltyProgramType(array $settings): string {
        $t = $settings['program_type'] ?? 'points';
        return in_array($t, LOYALTY_PROGRAM_TYPES, true) ? $t : 'points';
    }

    /** The label shown for a stamp-card reward, e.g. "Free Gulab Jamun" or "₹100 off". */
    function loyaltyStampRewardLabel(PDO $conn, array $s, string $cur = '₹'): string {
        if (($s['stamp_reward_kind'] ?? 'item') === 'item' && !empty($s['stamp_reward_item_id'])) {
            $st = $conn->prepare('SELECT item_name_en FROM menu_items WHERE id = ? AND restaurant_id = ?');
            $st->execute([(int)$s['stamp_reward_item_id'], $s['restaurant_id']]);
            $n = $st->fetchColumn();
            if ($n) return 'Free ' . $n;
        }
        if (($s['stamp_reward_kind'] ?? '') === 'percent') return rtrim(rtrim(number_format((float)$s['stamp_reward_value'], 2, '.', ''), '0'), '.') . '% off';
        return $cur . rtrim(rtrim(number_format((float)$s['stamp_reward_value'], 2, '.', ''), '0'), '.') . ' off';
    }

    /**
     * Give a customer a gift: a private one-time coupon for their phone.
     *   kind: 'flat' (₹ off), 'percent' (% off) or 'item' (a free dish: ₹ off equal to its price)
     * Returns the issued row, or null if this exact gift (source + period) was already given.
     * Safe inside the caller's transaction (no table changes).
     */
    function loyaltyIssueReward(PDO $conn, string $rid, int $customerId, string $phone, string $source, string $periodKey,
                                string $kind, float $value, float $minOrder, int $validDays, ?int $itemId = null, ?string $label = null): ?array {
        $phone = orderAbuseNormalizePhone($phone) ?? $phone;
        $cur = (string)($conn->query('SELECT currency_symbol FROM users WHERE restaurant_id = ' . $conn->quote($rid) . ' LIMIT 1')->fetchColumn() ?: '₹');
        $type = 'flat';
        if ($kind === 'item' && $itemId) {
            $st = $conn->prepare('SELECT item_name_en, base_price FROM menu_items WHERE id = ? AND restaurant_id = ?');
            $st->execute([$itemId, $rid]);
            $it = $st->fetch(PDO::FETCH_ASSOC);
            if (!$it) return null;
            $value = (float)$it['base_price'];
            $label = $label ?: 'Free ' . $it['item_name_en'];
        } elseif ($kind === 'percent') {
            $type = 'percent';
            $value = max(1, min(100, $value));
            $label = $label ?: rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '% off';
        } else {
            $label = $label ?: $cur . rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' off';
        }
        if ($value <= 0) return null;

        // Already given? (the unique key also guards against a race)
        $chk = $conn->prepare('SELECT id FROM loyalty_rewards_issued WHERE restaurant_id = ? AND customer_id = ? AND source = ? AND period_key = ?');
        $chk->execute([$rid, $customerId, $source, $periodKey]);
        if ($chk->fetchColumn()) return null;

        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $exists = $conn->prepare('SELECT 1 FROM coupons WHERE restaurant_id = ? AND coupon_code = ?');
        do {
            $code = 'GIFT';
            for ($i = 0; $i < 6; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
            $exists->execute([$rid, $code]);
        } while ($exists->fetchColumn());
        $until = $validDays > 0 ? date('Y-m-d', strtotime('+' . $validDays . ' days')) : null;

        $conn->prepare("INSERT INTO coupons (restaurant_id, coupon_code, discount_type, discount_value, minimum_order_amount, max_uses, current_uses, valid_from, valid_until, is_active, description, is_milestone, owner_phone)
            VALUES (?, ?, ?, ?, ?, 1, 0, CURDATE(), ?, 1, ?, 0, ?)")
             ->execute([$rid, $code, $type, round($value, 2), max(0, $minOrder), $until, mb_substr($label, 0, 255), $phone]);
        try {
            $conn->prepare('INSERT INTO loyalty_rewards_issued (restaurant_id, customer_id, phone, source, period_key, label, coupon_code, value_type, value, min_order, expires_on)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                 ->execute([$rid, $customerId, $phone, $source, $periodKey, mb_substr($label, 0, 120), $code, $kind === 'item' ? 'item' : $type, round($value, 2), max(0, $minOrder), $until]);
        } catch (PDOException $e) {
            // Lost a race to an identical gift: take the coupon back
            $conn->prepare('DELETE FROM coupons WHERE restaurant_id = ? AND coupon_code = ?')->execute([$rid, $code]);
            return null;
        }
        return ['code' => $code, 'label' => $label, 'value' => $value, 'type' => $type, 'expires_on' => $until, 'source' => $source];
    }

    /**
     * The customer record for an order's phone, created if this is their first
     * order through a channel that doesn't create one (POS, waiter, table).
     */
    function loyaltyEnsureCustomerForOrder(PDO $conn, array $order): ?int {
        $phone = orderAbuseNormalizePhone((string)($order['customer_phone'] ?? ''));
        if (!$phone) return null;
        $rid = $order['restaurant_id'];
        $st = $conn->prepare('SELECT id FROM customers WHERE restaurant_id = ? AND phone = ? LIMIT 1');
        $st->execute([$rid, $phone]);
        $id = $st->fetchColumn();
        if ($id) return (int)$id;
        $conn->prepare('INSERT INTO customers (restaurant_id, customer_name, phone, total_visits, total_spent, last_visit_date) VALUES (?, ?, ?, 1, ?, CURDATE())')
             ->execute([$rid, mb_substr(trim((string)($order['customer_name'] ?? '')) ?: 'Guest', 0, 100), $phone, (float)$order['total']]);
        return (int)$conn->lastInsertId();
    }

    /** One stamp per qualifying completed order; a full card turns into a reward code. */
    function loyaltyAddStamp(PDO $conn, array $settings, array $order, int $customerId): ?array {
        if ((float)$order['total'] < (float)$settings['stamp_min_order']) return null;
        $done = $conn->prepare('SELECT 1 FROM loyalty_stamp_ledger WHERE order_id = ? AND change_count > 0 LIMIT 1');
        $done->execute([(int)$order['id']]);
        if ($done->fetchColumn()) return null;                      // once per order
        $rid = $order['restaurant_id'];
        $st = $conn->prepare('SELECT stamps FROM customers WHERE id = ? FOR UPDATE');
        $st->execute([$customerId]);
        $stamps = (int)$st->fetchColumn() + 1;
        $goal = max(2, (int)$settings['stamp_goal']);
        $reward = null;
        $conn->prepare('INSERT INTO loyalty_stamp_ledger (restaurant_id, customer_id, order_id, change_count, balance_after, note) VALUES (?, ?, ?, 1, ?, ?)')
             ->execute([$rid, $customerId, (int)$order['id'], min($stamps, $goal), 'Stamp for order ' . ($order['order_number'] ?? '')]);
        if ($stamps >= $goal) {
            $reward = loyaltyIssueReward($conn, $rid, $customerId, (string)$order['customer_phone'], 'stamps', 'order' . (int)$order['id'],
                (string)$settings['stamp_reward_kind'], (float)$settings['stamp_reward_value'], 0, (int)$settings['reward_valid_days'],
                $settings['stamp_reward_item_id'] ? (int)$settings['stamp_reward_item_id'] : null);
            $stamps -= $goal;
            $conn->prepare('INSERT INTO loyalty_stamp_ledger (restaurant_id, customer_id, order_id, change_count, balance_after, note) VALUES (?, ?, ?, ?, ?, ?)')
                 ->execute([$rid, $customerId, (int)$order['id'], -$goal, $stamps, 'Card complete: ' . ($reward['label'] ?? 'reward')]);
        }
        $conn->prepare('UPDATE customers SET stamps = ? WHERE id = ?')->execute([$stamps, $customerId]);
        return $reward;
    }

    /**
     * Order status hook (order_state_machine.php), on Completed, inside its
     * transaction. Points are awarded by the caller (growth_helpers); this adds
     * stamps and anything else the program runs on completed orders.
     */
    function loyaltyOnOrderCompleted(PDO $conn, int $orderId, int $customerId): void {
        $st = $conn->prepare('SELECT * FROM orders WHERE id = ?');
        $st->execute([$orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) return;
        $settings = getGrowthSettings($conn, $order['restaurant_id']);
        if (empty($settings['loyalty_enabled'])) return;
        if (loyaltyProgramType($settings) === 'stamps') loyaltyAddStamp($conn, $settings, $order, $customerId);
        // Stage B/C hooks (milestones, challenges, streaks) plug in here
        if (function_exists('loyaltyAfterOrderHooks')) loyaltyAfterOrderHooks($conn, $order, $customerId, $settings);
    }

    /** A customer's gifts (newest first) with whether each was used. */
    function loyaltyCustomerGifts(PDO $conn, string $rid, int $customerId, int $limit = 20): array {
        $st = $conn->prepare("SELECT r.source, r.label, r.coupon_code, r.value_type, r.value, r.min_order, r.expires_on, r.created_at,
                COALESCE(c.current_uses, 0) used, c.is_active
            FROM loyalty_rewards_issued r LEFT JOIN coupons c ON c.restaurant_id = r.restaurant_id AND c.coupon_code = r.coupon_code
            WHERE r.restaurant_id = ? AND r.customer_id = ? ORDER BY r.id DESC LIMIT " . (int)$limit);
        $st->execute([$rid, $customerId]);
        $today = date('Y-m-d');
        return array_map(function ($g) use ($today) {
            $state = (int)$g['used'] > 0 ? 'used' : (($g['expires_on'] && $g['expires_on'] < $today) || !(int)$g['is_active'] ? 'expired' : 'ready');
            return ['source' => $g['source'], 'label' => $g['label'], 'code' => $g['coupon_code'], 'min_order' => (float)$g['min_order'],
                    'expires_on' => $g['expires_on'], 'created_at' => $g['created_at'], 'state' => $state];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    function loyaltyWalletBalance(PDO $conn, string $rid, string $phone): float {
        $phone = orderAbuseNormalizePhone($phone) ?? $phone;
        try {
            $st = $conn->prepare('SELECT balance FROM wallets WHERE restaurant_id = ? AND phone = ?');
            $st->execute([$rid, $phone]);
            return (float)$st->fetchColumn();
        } catch (PDOException $e) { return 0.0; }
    }

    /** Owner switches the program: keep the wallet's cashback in step with "cashback". */
    function loyaltySyncCashbackToWallet(PDO $conn, string $rid, array $settings): void {
        require_once __DIR__ . '/wallet_helpers.php';
        ensureWalletTables($conn);
        $on = !empty($settings['loyalty_enabled']) && loyaltyProgramType($settings) === 'cashback';
        $conn->prepare('INSERT IGNORE INTO wallet_settings (restaurant_id) VALUES (?)')->execute([$rid]);
        if ($on) {
            $conn->prepare('UPDATE wallet_settings SET enabled = 1, cashback_pct = ?, cashback_max = ? WHERE restaurant_id = ?')
                 ->execute([(float)$settings['cashback_pct'], (float)$settings['cashback_max'], $rid]);
        } else {
            $conn->prepare('UPDATE wallet_settings SET cashback_pct = 0 WHERE restaurant_id = ?')->execute([$rid]);
        }
    }
}
