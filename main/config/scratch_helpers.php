<?php
/**
 * Offers > Scratch card.
 *
 * After checkout the tracking page shows a card to scratch. It unlocks once
 * the restaurant accepts the order (so placing and cancelling can't farm
 * prizes), only for orders above the owner's minimum. The prize is drawn on
 * the server from the owner's list (₹ off / % off the next order, or "better
 * luck next time") by its chance, within the owner's monthly budget. A won
 * prize becomes a private, single-use coupon tied to the winner's phone
 * (coupons.owner_phone), valid for a few days. The chances are shown to the
 * customer as they are.
 */
require_once __DIR__ . '/coupon_helpers.php';

if (!function_exists('ensureScratchTables')) {

    function ensureScratchTables(PDO $conn): void {
        static $done = false;
        if ($done) return;
        $done = true;
        ensureCouponOwnerColumn($conn);
        $conn->exec("CREATE TABLE IF NOT EXISTS scratch_settings (
            restaurant_id VARCHAR(50) PRIMARY KEY,
            enabled TINYINT NOT NULL DEFAULT 0,
            title VARCHAR(60) NULL,
            min_order DECIMAL(10,2) NOT NULL DEFAULT 0,
            next_min_order DECIMAL(10,2) NOT NULL DEFAULT 0,
            valid_days INT NOT NULL DEFAULT 15,
            monthly_budget DECIMAL(10,2) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS scratch_prizes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            type VARCHAR(10) NOT NULL,
            value DECIMAL(10,2) NOT NULL DEFAULT 0,
            weight INT NOT NULL DEFAULT 1,
            position TINYINT NOT NULL DEFAULT 0,
            INDEX idx_scratch_prizes (restaurant_id)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS scratch_cards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            order_id INT NOT NULL,
            customer_phone VARCHAR(20) NOT NULL,
            prize_type VARCHAR(10) NOT NULL,
            prize_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            coupon_code VARCHAR(20) NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'new',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            scratched_at DATETIME NULL,
            expires_on DATE NULL,
            UNIQUE KEY uq_scratch_order (order_id),
            INDEX idx_scratch_rest (restaurant_id, created_at),
            INDEX idx_scratch_code (coupon_code)
        )");
    }

    function getScratchSettings(PDO $conn, string $rid): array {
        ensureScratchTables($conn);
        $st = $conn->prepare('SELECT enabled, title, min_order, next_min_order, valid_days, monthly_budget FROM scratch_settings WHERE restaurant_id = ?');
        $st->execute([$rid]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['enabled' => (int)($r['enabled'] ?? 0), 'title' => (string)($r['title'] ?? ''),
                'min_order' => (float)($r['min_order'] ?? 0), 'next_min_order' => (float)($r['next_min_order'] ?? 0),
                'valid_days' => (int)($r['valid_days'] ?? 15) ?: 15, 'monthly_budget' => (float)($r['monthly_budget'] ?? 0)];
    }

    function getScratchPrizes(PDO $conn, string $rid): array {
        ensureScratchTables($conn);
        $st = $conn->prepare('SELECT type, value, weight FROM scratch_prizes WHERE restaurant_id = ? ORDER BY position, id');
        $st->execute([$rid]);
        return array_map(function ($r) {
            return ['type' => $r['type'], 'value' => (float)$r['value'], 'weight' => (int)$r['weight']];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    function scratchPrizeLabel(string $type, float $value, string $cur): string {
        $v = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        if ($type === 'flat') return $cur . $v . ' off your next order';
        if ($type === 'percent') return $v . '% off your next order';
        return 'Better luck next time';
    }

    /** The chances, exactly as drawn: [{label, pct}] */
    function scratchOdds(array $prizes, string $cur): array {
        $sum = array_sum(array_column($prizes, 'weight')) ?: 1;
        return array_map(function ($p) use ($sum, $cur) {
            return ['label' => scratchPrizeLabel($p['type'], $p['value'], $cur), 'pct' => round($p['weight'] / $sum * 100, 1)];
        }, $prizes);
    }

    function scratchSpentThisMonth(PDO $conn, string $rid): float {
        $st = $conn->prepare("SELECT COALESCE(SUM(cost), 0) FROM scratch_cards WHERE restaurant_id = ? AND prize_type <> 'none'
            AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
        $st->execute([$rid]);
        return (float)$st->fetchColumn();
    }

    /** What a prize may cost: ₹ off as is, % off estimated on this order's total. */
    function scratchPrizeCost(array $p, float $orderTotal): float {
        if ($p['type'] === 'flat') return $p['value'];
        if ($p['type'] === 'percent') return round($orderTotal * $p['value'] / 100, 2);
        return 0.0;
    }

    /**
     * The card for an order, as the tracking page shows it:
     *   null                       — no card (feature off, order too small, cancelled, too old)
     *   ['state' => 'locked']      — waiting for the restaurant to accept
     *   ['state' => 'new' | 'scratched', ...]
     */
    function scratchCardForOrder(PDO $conn, array $order, string $cur): ?array {
        $rid = $order['restaurant_id'];
        $s = getScratchSettings($conn, $rid);
        $st = $conn->prepare('SELECT * FROM scratch_cards WHERE order_id = ?');
        $st->execute([(int)$order['id']]);
        $card = $st->fetch(PDO::FETCH_ASSOC);
        $status = (string)$order['order_status'];
        if (in_array($status, ['Cancelled', 'Rejected'], true)) return null;

        if (!$card) {
            if (!$s['enabled'] || (float)$order['total'] < $s['min_order']) return null;
            if (strtotime((string)$order['created_at']) < time() - 3 * 86400) return null;   // old orders don't get one
            $prizes = getScratchPrizes($conn, $rid);
            if (!$prizes) return null;
            $base = ['state' => 'locked', 'title' => $s['title'], 'odds' => scratchOdds($prizes, $cur)];
            if ($status === 'Pending') return $base;

            // Draw within the month's budget
            $left = $s['monthly_budget'] > 0 ? $s['monthly_budget'] - scratchSpentThisMonth($conn, $rid) : INF;
            $total = (float)$order['total'];
            $pool = array_values(array_filter($prizes, function ($p) use ($left, $total) { return scratchPrizeCost($p, $total) <= $left && $p['weight'] > 0; }));
            if (!$pool) return null;
            $roll = random_int(1, array_sum(array_column($pool, 'weight')));
            foreach ($pool as $p) { $roll -= $p['weight']; if ($roll <= 0) break; }
            $conn->prepare('INSERT IGNORE INTO scratch_cards (restaurant_id, order_id, customer_phone, prize_type, prize_value, cost) VALUES (?, ?, ?, ?, ?, ?)')
                 ->execute([$rid, (int)$order['id'], (string)$order['customer_phone'], $p['type'], $p['value'], scratchPrizeCost($p, $total)]);
            $st->execute([(int)$order['id']]);
            $card = $st->fetch(PDO::FETCH_ASSOC);
            if (!$card) return null;
        }

        $out = ['state' => $card['status'] === 'scratched' ? 'scratched' : 'new', 'title' => $s['title'],
                'odds' => scratchOdds(getScratchPrizes($conn, $rid) ?: [['type' => $card['prize_type'], 'value' => (float)$card['prize_value'], 'weight' => 1]], $cur)];
        if ($out['state'] === 'scratched') $out['prize'] = scratchPrizeOut($card, $s, $cur);
        return $out;
    }

    function scratchPrizeOut(array $card, array $s, string $cur): array {
        return ['type' => $card['prize_type'], 'value' => (float)$card['prize_value'],
                'label' => scratchPrizeLabel($card['prize_type'], (float)$card['prize_value'], $cur),
                'code' => $card['coupon_code'], 'expires_on' => $card['expires_on'], 'min_order' => $s['next_min_order']];
    }

    /** Scratch it: a won prize becomes a private single-use coupon for this phone. */
    function scratchReveal(PDO $conn, array $order, string $cur): ?array {
        $rid = $order['restaurant_id'];
        $s = getScratchSettings($conn, $rid);
        $conn->beginTransaction();
        $st = $conn->prepare('SELECT * FROM scratch_cards WHERE order_id = ? FOR UPDATE');
        $st->execute([(int)$order['id']]);
        $card = $st->fetch(PDO::FETCH_ASSOC);
        if (!$card) { $conn->rollBack(); return null; }
        if ($card['status'] !== 'scratched') {
            $code = null; $until = null;
            if ($card['prize_type'] !== 'none') {
                $until = date('Y-m-d', strtotime('+' . max(1, $s['valid_days']) . ' days'));
                $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                $exists = $conn->prepare('SELECT 1 FROM coupons WHERE restaurant_id = ? AND coupon_code = ?');
                do {
                    $code = 'WIN';
                    for ($i = 0; $i < 6; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
                    $exists->execute([$rid, $code]);
                } while ($exists->fetchColumn());
                $conn->prepare("INSERT INTO coupons (restaurant_id, coupon_code, discount_type, discount_value, minimum_order_amount, max_uses, current_uses, valid_from, valid_until, is_active, description, is_milestone, owner_phone)
                    VALUES (?, ?, ?, ?, ?, 1, 0, CURDATE(), ?, 1, 'Scratch card reward', 0, ?)")
                     ->execute([$rid, $code, $card['prize_type'], $card['prize_value'], $s['next_min_order'], $until, $card['customer_phone']]);
            }
            $conn->prepare("UPDATE scratch_cards SET status = 'scratched', scratched_at = NOW(), coupon_code = ?, expires_on = ? WHERE id = ? AND status = 'new'")
                 ->execute([$code, $until, (int)$card['id']]);
            $st->execute([(int)$order['id']]);
            $card = $st->fetch(PDO::FETCH_ASSOC);
        }
        $conn->commit();
        return scratchPrizeOut($card, $s, $cur);
    }
}
