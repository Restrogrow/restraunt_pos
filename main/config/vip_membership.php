<?php
/**
 * Paid VIP membership (Growth > Loyalty Studio > VIP membership).
 *
 * The owner sells plans (monthly, quarterly, yearly...). A member gets, while
 * the plan runs:
 *   discount_pct   % off every website order (capped per order by discount_max)
 *   free_delivery  no delivery fee (on orders above free_delivery_min)
 *   earn_boost     points x N (points program) or cashback x N (cashback program)
 *   welcome gift   a personal gift code the moment they join
 * Customers buy on the rewards page with PhonePe (transaction ids 'VM…', same
 * flow and webhook as wallet top-ups) or from their wallet balance. Perks are
 * applied by the server at checkout for a logged-in member ordering with the
 * same phone number.
 */
require_once __DIR__ . '/loyalty_engine.php';

if (!function_exists('ensureVipSchema')) {

    function ensureVipSchema(PDO $conn): void {
        static $done = false;
        if ($done || $conn->inTransaction()) return;
        $done = true;
        ensureLoyaltyEngineSchema($conn);
        $conn->exec("CREATE TABLE IF NOT EXISTS vip_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            name VARCHAR(40) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            duration_days INT NOT NULL DEFAULT 30,
            discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
            discount_max DECIMAL(10,2) NOT NULL DEFAULT 0,
            free_delivery TINYINT NOT NULL DEFAULT 0,
            free_delivery_min DECIMAL(10,2) NOT NULL DEFAULT 0,
            earn_boost DECIMAL(4,2) NOT NULL DEFAULT 1,
            gift_kind VARCHAR(10) NULL,
            gift_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            gift_item_id INT NULL,
            highlight TINYINT NOT NULL DEFAULT 0,
            active TINYINT NOT NULL DEFAULT 1,
            position INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_vp_rest (restaurant_id, active)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS vip_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            customer_id INT NULL,
            phone VARCHAR(20) NOT NULL,
            plan_id INT NOT NULL,
            plan_name VARCHAR(40) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            paid_via VARCHAR(10) NOT NULL,
            transaction_id VARCHAR(64) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'Pending',
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vip_txn (transaction_id),
            INDEX idx_vm_owner (restaurant_id, phone, status, ends_at)
        )");
        try {
            $q = $conn->query("SHOW COLUMNS FROM growth_settings LIKE 'vip_enabled'");
            if (!($q && $q->fetch())) {
                $conn->exec("ALTER TABLE growth_settings ADD COLUMN vip_enabled TINYINT NOT NULL DEFAULT 0, ADD COLUMN vip_name VARCHAR(40) NULL, ADD COLUMN vip_tagline VARCHAR(120) NULL");
            }
            $q = $conn->query("SHOW COLUMNS FROM orders LIKE 'vip_discount'");
            if (!($q && $q->fetch())) $conn->exec('ALTER TABLE orders ADD COLUMN vip_discount DECIMAL(10,2) NOT NULL DEFAULT 0');
        } catch (PDOException $e) { error_log('ensureVipSchema: ' . $e->getMessage()); }
    }

    function vipPlanOut(array $r): array {
        return ['id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'], 'duration_days' => (int)$r['duration_days'],
                'discount_pct' => (float)$r['discount_pct'], 'discount_max' => (float)$r['discount_max'], 'free_delivery' => (int)$r['free_delivery'],
                'free_delivery_min' => (float)$r['free_delivery_min'], 'earn_boost' => (float)$r['earn_boost'], 'gift_kind' => $r['gift_kind'] ?: null,
                'gift_value' => (float)$r['gift_value'], 'gift_item_id' => $r['gift_item_id'] ? (int)$r['gift_item_id'] : null,
                'highlight' => (int)$r['highlight'], 'active' => (int)$r['active']];
    }

    function vipPlans(PDO $conn, string $rid, bool $activeOnly = false): array {
        try {
            $st = $conn->prepare('SELECT * FROM vip_plans WHERE restaurant_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY position, price, id');
            $st->execute([$rid]);
            return array_map('vipPlanOut', $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) { return []; }
    }

    function vipSettings(PDO $conn, string $rid): array {
        $s = getGrowthSettings($conn, $rid);
        return ['enabled' => (int)($s['vip_enabled'] ?? 0), 'name' => trim((string)($s['vip_name'] ?? '')) ?: 'VIP Club',
                'tagline' => trim((string)($s['vip_tagline'] ?? '')), 'loyalty_enabled' => (int)($s['loyalty_enabled'] ?? 0),
                'program_type' => loyaltyProgramType($s)];
    }

    /** The phone's running membership with its plan's perks, or null. */
    function vipActiveMembership(PDO $conn, string $rid, string $phone): ?array {
        if ($phone === '') return null;
        try {
            $st = $conn->prepare("SELECT m.id member_id, m.plan_name, m.starts_at, m.ends_at, p.* FROM vip_members m JOIN vip_plans p ON p.id = m.plan_id
                WHERE m.restaurant_id = ? AND m.phone = ? AND m.status = 'Active' AND m.starts_at <= NOW() AND m.ends_at > NOW() ORDER BY m.ends_at DESC LIMIT 1");
            $st->execute([$rid, $phone]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            // the last day of all stacked renewals
            $e = $conn->prepare("SELECT MAX(ends_at) FROM vip_members WHERE restaurant_id = ? AND phone = ? AND status = 'Active'");
            $e->execute([$rid, $phone]);
            return vipPlanOut($r) + ['member_id' => (int)$r['member_id'], 'plan_name' => $r['plan_name'], 'since' => $r['starts_at'], 'ends_at' => $e->fetchColumn() ?: $r['ends_at']];
        } catch (PDOException $e) { return null; }
    }

    /** Points / cashback multiplier for a member (1 when not a member). */
    function vipEarnBoost(PDO $conn, string $rid, string $phone): float {
        $s = getGrowthSettings($conn, $rid);
        if (empty($s['vip_enabled'])) return 1.0;
        $m = vipActiveMembership($conn, $rid, $phone);
        return $m ? max(1.0, min(5.0, (float)$m['earn_boost'])) : 1.0;
    }

    /**
     * What a member saves on this order (server-side, at checkout):
     *   ['discount' => ₹ off the food after other discounts, 'free_delivery' => bool, 'plan' => name]
     */
    function vipCheckoutPerks(PDO $conn, string $rid, string $phone, float $foodAfterDiscounts, string $orderType, float $itemsTotal): ?array {
        $s = getGrowthSettings($conn, $rid);
        if (empty($s['vip_enabled'])) return null;
        $m = vipActiveMembership($conn, $rid, $phone);
        if (!$m) return null;
        $disc = 0.0;
        if ($m['discount_pct'] > 0 && $foodAfterDiscounts > 0) {
            $disc = round($foodAfterDiscounts * $m['discount_pct'] / 100, 2);
            if ($m['discount_max'] > 0) $disc = min($disc, $m['discount_max']);
        }
        $free = $orderType === 'Delivery' && $m['free_delivery'] && $itemsTotal >= $m['free_delivery_min'];
        return ['discount' => $disc, 'free_delivery' => $free, 'plan' => $m['name'], 'member' => $m];
    }

    function vipGiftLabel(PDO $conn, string $rid, array $p, string $cur): ?string {
        if (empty($p['gift_kind'])) return null;
        $n = rtrim(rtrim(number_format((float)$p['gift_value'], 2, '.', ''), '0'), '.');
        if ($p['gift_kind'] === 'percent') return $n . '% off';
        if ($p['gift_kind'] === 'item') {
            $st = $conn->prepare('SELECT item_name_en FROM menu_items WHERE id = ? AND restaurant_id = ?');
            $st->execute([(int)$p['gift_item_id'], $rid]);
            $name = $st->fetchColumn();
            return $name ? 'Free ' . $name : null;
        }
        return (float)$p['gift_value'] > 0 ? $cur . $n . ' off' : null;
    }

    /** Start a purchase: a Pending membership with a 'VM…' transaction id. */
    function vipCreatePending(PDO $conn, string $rid, ?int $customerId, string $phone, array $plan, string $via): array {
        $txn = 'VM' . date('ymdHis') . strtoupper(bin2hex(random_bytes(6)));
        $conn->prepare('INSERT INTO vip_members (restaurant_id, customer_id, phone, plan_id, plan_name, amount, paid_via, transaction_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
             ->execute([$rid, $customerId, $phone, $plan['id'], $plan['name'], $plan['price'], $via, $txn]);
        $st = $conn->prepare('SELECT * FROM vip_members WHERE transaction_id = ?');
        $st->execute([$txn]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * A paid membership: switch it on exactly once. It starts now, or when the
     * member's current plan ends (renewing early adds the time on). Gives the
     * plan's welcome gift.
     */
    function vipActivate(PDO $conn, array $member): bool {
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $st = $conn->prepare("SELECT * FROM vip_members WHERE id = ? FOR UPDATE");
            $st->execute([(int)$member['id']]);
            $m = $st->fetch(PDO::FETCH_ASSOC);
            if (!$m || $m['status'] !== 'Pending') { if ($own) $conn->commit(); return false; }
            $p = $conn->prepare('SELECT * FROM vip_plans WHERE id = ?');
            $p->execute([(int)$m['plan_id']]);
            $plan = $p->fetch(PDO::FETCH_ASSOC);
            $days = $plan ? max(1, (int)$plan['duration_days']) : 30;
            $e = $conn->prepare("SELECT MAX(ends_at) FROM vip_members WHERE restaurant_id = ? AND phone = ? AND status = 'Active' AND ends_at > NOW()");
            $e->execute([$m['restaurant_id'], $m['phone']]);
            $start = $e->fetchColumn() ?: date('Y-m-d H:i:s');
            $end = date('Y-m-d H:i:s', strtotime($start . ' +' . $days . ' days'));
            $conn->prepare("UPDATE vip_members SET status = 'Active', starts_at = ?, ends_at = ? WHERE id = ?")->execute([$start, $end, (int)$m['id']]);
            if ($plan && !empty($plan['gift_kind']) && ($plan['gift_kind'] === 'item' ? $plan['gift_item_id'] : (float)$plan['gift_value'] > 0)) {
                $cid = $m['customer_id'] ? (int)$m['customer_id'] : null;
                if (!$cid) {
                    $c = $conn->prepare('SELECT id FROM customers WHERE restaurant_id = ? AND phone = ? LIMIT 1');
                    $c->execute([$m['restaurant_id'], $m['phone']]);
                    $cid = (int)$c->fetchColumn() ?: null;
                }
                if ($cid) {
                    loyaltyIssueReward($conn, $m['restaurant_id'], $cid, $m['phone'], 'vip', 'm' . $m['id'], $plan['gift_kind'], (float)$plan['gift_value'], 0,
                        30, $plan['gift_item_id'] ? (int)$plan['gift_item_id'] : null);
                }
            }
            if ($own) $conn->commit();
            return true;
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    function vipFail(PDO $conn, array $member): void {
        $conn->prepare("UPDATE vip_members SET status = 'Failed' WHERE id = ? AND status = 'Pending'")->execute([(int)$member['id']]);
    }

    /** What membership has saved a member so far (completed and open orders). */
    function vipSavings(PDO $conn, string $rid, string $phone, string $since): float {
        try {
            $st = $conn->prepare("SELECT COALESCE(SUM(vip_discount), 0) FROM orders WHERE restaurant_id = ? AND customer_phone = ? AND created_at >= ? AND deleted_at IS NULL AND order_status NOT IN ('Cancelled', 'Rejected')");
            $st->execute([$rid, $phone, $since]);
            return (float)$st->fetchColumn();
        } catch (PDOException $e) { return 0.0; }
    }
}
