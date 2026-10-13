<?php
/**
 * Customers at the POS: look-up summary, loyalty at the counter, and account claim codes.
 *
 *  posCustomerSummary()      everything the POS shows for a customer: points (and what they're
 *                            worth), stamps, wallet, tier, gifts and coupons only they can use,
 *                            visits / spend, and whether they have a website account.
 *  posCreditOnPayment()      a fully paid POS order earns right away (points, stamps or cashback,
 *                            whichever program runs). Each of these is written at most once per
 *                            order, so marking the order Completed later adds nothing twice; the
 *                            gifts that count completed orders (welcome, every Nth order, tier up)
 *                            still run on Completed (order_state_machine.php).
 *  posCreateClaimCode()      a one-time code for "complete your account" (QR in Customers and POS).
 *                            The website's login page (?claim=CODE) checks it with posFindClaim()
 *                            and the customer sets an email and password (website/customer_auth.php).
 *                            Codes are stored hashed, last 14 days and work once.
 */
require_once __DIR__ . '/loyalty_engine.php';
require_once __DIR__ . '/wallet_helpers.php';

if (!function_exists('posCustomerSummary')) {

    function posEnsureSchema(PDO $conn): void {
        static $done = false;
        if ($done || $conn->inTransaction()) return;
        $done = true;
        ensureLoyaltyEngineSchema($conn);
        ensureWalletTables($conn);
        $conn->exec("CREATE TABLE IF NOT EXISTS customer_claim_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            customer_id INT NOT NULL,
            code_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ccc_hash (code_hash),
            INDEX idx_ccc_cust (restaurant_id, customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    function posNormPhone(string $phone): string {
        $d = preg_replace('/\D/', '', $phone);
        return orderAbuseNormalizePhone($d) ?? $d;
    }

    function posCustomerSummary(PDO $conn, string $rid, array $c): array {
        $s = getGrowthSettings($conn, $rid);
        $type = loyaltyProgramType($s);
        $on = !empty($s['loyalty_enabled']);
        $phone = (string)$c['phone'];
        $tier = getCustomerTier($conn, $rid, (float)$c['total_spent']);
        $ws = getWalletSettings($conn, $rid);
        $wallet = $ws['enabled'] ? loyaltyWalletBalance($conn, $rid, $phone) : 0.0;

        // coupons only this customer can use: loyalty gifts and scratch-card rewards
        $gifts = [];
        if ($phone !== '') {
            $st = $conn->prepare("SELECT coupon_code, discount_type, discount_value, minimum_order_amount, description, valid_until FROM coupons
                WHERE restaurant_id = ? AND owner_phone = ? AND is_active = 1 AND (valid_until IS NULL OR valid_until >= CURDATE())
                  AND (max_uses = 0 OR current_uses < max_uses) ORDER BY valid_until IS NULL, valid_until LIMIT 10");
            $st->execute([$rid, $phone]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
                $gifts[] = ['code' => $g['coupon_code'], 'type' => $g['discount_type'], 'value' => (float)$g['discount_value'],
                    'min_order' => (float)$g['minimum_order_amount'], 'label' => $g['description'] ?: '', 'valid_until' => $g['valid_until']];
            }
        }
        $pts = (int)$c['loyalty_points_balance'];
        $rate = (float)$s['redeem_value_per_point'];
        return [
            'id' => (int)$c['id'], 'name' => $c['customer_name'], 'phone' => $phone, 'email' => (string)($c['email'] ?? ''),
            'has_account' => !empty($c['password_hash']),
            'visits' => (int)$c['total_visits'], 'spent' => (float)$c['total_spent'], 'last_visit' => $c['last_visit_date'],
            'birthday' => $c['birthday'] ?? null,
            'loyalty' => [
                'enabled' => $on, 'type' => $type,
                'points' => $pts, 'points_value' => round($pts * $rate, 2), 'redeem_value_per_point' => $rate, 'min_redeem' => (int)$s['min_redeem_points'],
                'earn_per' => (float)$s['earn_amount_threshold'], 'earn_points' => (int)$s['earn_points_per_amount'],
                'stamps' => (int)($c['stamps'] ?? 0), 'stamp_goal' => max(2, (int)$s['stamp_goal']), 'stamp_reward' => $type === 'stamps' ? loyaltyStampRewardLabel($conn, $s) : '',
                'cashback_pct' => (float)$s['cashback_pct'],
                'tier' => $tier ? $tier['tier_name'] : null,
            ],
            'wallet' => ['enabled' => (bool)$ws['enabled'], 'balance' => $wallet],
            'gifts' => $gifts,
        ];
    }

    /** Earn now for a fully paid POS order (see the note at the top). Returns what was earned. */
    function posCreditOnPayment(PDO $conn, string $rid, int $orderId): array {
        $out = ['points' => 0, 'stamp' => false, 'stamps' => null, 'reward' => null, 'cashback' => 0.0];
        $st = $conn->prepare('SELECT * FROM orders WHERE id = ? AND restaurant_id = ?');
        $st->execute([$orderId, $rid]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o || empty($o['customer_phone']) || $o['payment_status'] !== 'Paid') return $out;
        $s = getGrowthSettings($conn, $rid);
        $conn->beginTransaction();
        try {
            $cid = loyaltyEnsureCustomerForOrder($conn, $o);
            if ($cid && !empty($s['loyalty_enabled'])) {
                $type = loyaltyProgramType($s);
                if ($type === 'points') $out['points'] = (int)(awardLoyaltyPoints($conn, $rid, $cid, $orderId, (float)$o['total']) ?? 0);
                if ($type === 'stamps') {
                    $before = (int)$conn->query('SELECT stamps FROM customers WHERE id = ' . (int)$cid)->fetchColumn();
                    $out['reward'] = loyaltyAddStamp($conn, $s, $o, $cid);
                    $out['stamps'] = (int)$conn->query('SELECT stamps FROM customers WHERE id = ' . (int)$cid)->fetchColumn();
                    $out['stamp'] = $out['reward'] !== null || $out['stamps'] !== $before;
                }
            }
            $cb = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE order_id = ? AND type = 'cashback'");
            walletOrderStatusHook($conn, $orderId, 'Completed');   // cashback only (if the owner turned it on)
            $cb->execute([$orderId]);
            $out['cashback'] = (float)$cb->fetchColumn();
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            error_log('posCreditOnPayment order ' . $orderId . ': ' . $e->getMessage());
        }
        return $out;
    }

    function posCreateClaimCode(PDO $conn, string $rid, int $customerId): string {
        $code = bin2hex(random_bytes(16));
        $conn->prepare('INSERT INTO customer_claim_codes (restaurant_id, customer_id, code_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 14 DAY))')
             ->execute([$rid, $customerId, hash('sha256', $code)]);
        return $code;
    }

    /** The customer a claim code belongs to, or null (wrong, used or expired). */
    function posFindClaim(PDO $conn, string $rid, string $code): ?array {
        if (!preg_match('/^[a-f0-9]{32}$/', $code)) return null;
        try {
            $st = $conn->prepare('SELECT k.id claim_id, c.* FROM customer_claim_codes k JOIN customers c ON c.id = k.customer_id AND c.restaurant_id = k.restaurant_id
                WHERE k.restaurant_id = ? AND k.code_hash = ? AND k.used_at IS NULL AND k.expires_at > NOW() LIMIT 1');
            $st->execute([$rid, hash('sha256', $code)]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (PDOException $e) { return null; }
    }
}
