<?php
/**
 * Loyalty games (Growth > Loyalty Studio > Games).
 *
 * Challenges a customer completes by ordering, each with a gift code reward:
 *   visits  N completed orders this month
 *   spend   ₹N spent this month
 *   dish    a dish ordered N times this month ("try our new biryani": N = 1)
 *   streak  an order every week for N weeks in a row (a gift every N weeks)
 * Progress is read from completed orders (every channel), and the gift is issued
 * from the order-completed hook through loyaltyIssueReward, once per period.
 *
 * Also the after-checkout game's style: the scratch card or a spin wheel, both
 * drawing from the same prizes and budget (Offers > Scratch card).
 */
require_once __DIR__ . '/loyalty_engine.php';

if (!function_exists('ensureLoyaltyGamesSchema')) {

    define('LOYALTY_CHALLENGE_KINDS', ['visits', 'spend', 'dish', 'streak']);

    function ensureLoyaltyGamesSchema(PDO $conn): void {
        // once per deploy, not on every request (config/schema_cache.php)
        require_once __DIR__ . '/schema_cache.php';
        $rgOnceKey = 'e:ensureLoyaltyGamesSchema:' . @filemtime(__FILE__);
        if (rgSchemaKnown($rgOnceKey) || $conn->inTransaction()) return;
        static $done = false;
        if ($done || $conn->inTransaction()) return;
        $done = true;
        ensureLoyaltyEngineSchema($conn);
        $conn->exec("CREATE TABLE IF NOT EXISTS loyalty_challenges (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            kind VARCHAR(10) NOT NULL,
            title VARCHAR(80) NULL,
            target DECIMAL(10,2) NOT NULL DEFAULT 1,
            item_id INT NULL,
            reward_kind VARCHAR(10) NOT NULL DEFAULT 'flat',
            reward_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            reward_item_id INT NULL,
            min_order DECIMAL(10,2) NOT NULL DEFAULT 0,
            valid_days INT NOT NULL DEFAULT 30,
            active TINYINT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lc_rest (restaurant_id, active)
        )");
        try {
            require_once __DIR__ . '/scratch_helpers.php';
            ensureScratchTables($conn);
            $q = $conn->query("SHOW COLUMNS FROM scratch_settings LIKE 'game_style'");
            if (!($q && $q->fetch())) $conn->exec("ALTER TABLE scratch_settings ADD COLUMN game_style VARCHAR(10) NOT NULL DEFAULT 'scratch'");
        } catch (PDOException $e) { error_log('ensureLoyaltyGamesSchema: ' . $e->getMessage()); }
            rgSchemaRemember($rgOnceKey);
    }

    function loyaltyChallenges(PDO $conn, string $rid, bool $activeOnly = false): array {
        try {
            $st = $conn->prepare('SELECT ch.*, mi.item_name_en item_name FROM loyalty_challenges ch LEFT JOIN menu_items mi ON mi.id = ch.item_id AND mi.restaurant_id = ch.restaurant_id
                WHERE ch.restaurant_id = ?' . ($activeOnly ? ' AND ch.active = 1' : '') . ' ORDER BY ch.active DESC, ch.id');
            $st->execute([$rid]);
            return array_map(function ($r) {
                return ['id' => (int)$r['id'], 'kind' => $r['kind'], 'title' => $r['title'], 'target' => (float)$r['target'], 'item_id' => $r['item_id'] ? (int)$r['item_id'] : null,
                        'item_name' => $r['item_name'], 'reward_kind' => $r['reward_kind'], 'reward_value' => (float)$r['reward_value'],
                        'reward_item_id' => $r['reward_item_id'] ? (int)$r['reward_item_id'] : null, 'min_order' => (float)$r['min_order'],
                        'valid_days' => (int)$r['valid_days'], 'active' => (int)$r['active']];
            }, $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) { return []; }
    }

    /** "Visit 3 times this month" when the owner left the title empty. */
    function loyaltyChallengeTitle(array $c, string $cur): string {
        if (!empty($c['title'])) return $c['title'];
        $n = (int)$c['target'];
        switch ($c['kind']) {
            case 'visits': return 'Order ' . $n . ' times this month';
            case 'spend':  return 'Spend ' . $cur . number_format($c['target'], 0, '.', ',') . ' this month';
            case 'dish':   return $n > 1 ? 'Order ' . ($c['item_name'] ?: 'the dish') . ' ' . $n . ' times this month' : 'Try our ' . ($c['item_name'] ?: 'special');
            case 'streak': return 'Order ' . $n . ' weeks in a row';
        }
        return 'Challenge';
    }

    /** Consecutive weeks with a completed order, counting back from this week (or last, if none yet this week). */
    function loyaltyStreakWeeks(PDO $conn, string $rid, string $phone): array {
        $st = $conn->prepare("SELECT DISTINCT YEARWEEK(created_at, 3) w FROM orders WHERE restaurant_id = ? AND customer_phone = ? AND order_status = 'Completed'
            AND deleted_at IS NULL AND created_at >= NOW() - INTERVAL 400 DAY ORDER BY w DESC");
        $st->execute([$rid, $phone]);
        $weeks = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $wk = function ($ts) { return (int)date('oW', $ts); };
        $thisWeek = $wk(time());
        $orderedThisWeek = in_array($thisWeek, $weeks, true);
        $n = 0;
        $ts = $orderedThisWeek ? time() : strtotime('-7 days');
        while (in_array($wk($ts), $weeks, true)) { $n++; $ts = strtotime('-7 days', $ts); }
        return ['weeks' => $n, 'this_week' => $orderedThisWeek];
    }

    /** One customer's progress on one challenge this period. */
    function loyaltyChallengeProgress(PDO $conn, string $rid, array $c, string $phone): array {
        $monthStart = date('Y-m-01 00:00:00');
        $base = "FROM orders o WHERE o.restaurant_id = ? AND o.customer_phone = ? AND o.order_status = 'Completed' AND o.deleted_at IS NULL AND o.created_at >= ?";
        switch ($c['kind']) {
            case 'visits':
                $st = $conn->prepare("SELECT COUNT(*) $base"); $st->execute([$rid, $phone, $monthStart]);
                return ['value' => (float)$st->fetchColumn(), 'period' => date('Ym')];
            case 'spend':
                $st = $conn->prepare("SELECT COALESCE(SUM(o.total), 0) $base"); $st->execute([$rid, $phone, $monthStart]);
                return ['value' => (float)$st->fetchColumn(), 'period' => date('Ym')];
            case 'dish':
                $st = $conn->prepare("SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                    WHERE o.restaurant_id = ? AND o.customer_phone = ? AND o.order_status = 'Completed' AND o.deleted_at IS NULL AND o.created_at >= ? AND oi.menu_item_id = ?");
                $st->execute([$rid, $phone, $monthStart, (int)$c['item_id']]);
                return ['value' => (float)$st->fetchColumn(), 'period' => date('Ym')];
            case 'streak':
                $s = loyaltyStreakWeeks($conn, $rid, $phone);
                $t = max(2, (int)$c['target']);
                // progress within the current round of N weeks; a full round pays out
                $v = $s['weeks'] > 0 && $s['weeks'] % $t === 0 ? $t : $s['weeks'] % $t;
                return ['value' => (float)$v, 'weeks' => $s['weeks'], 'this_week' => $s['this_week'], 'period' => 'w' . date('oW') . '-' . intdiv($s['weeks'], $t)];
        }
        return ['value' => 0.0, 'period' => ''];
    }

    function loyaltyChallengeIssued(PDO $conn, string $rid, int $customerId, array $c, string $period): bool {
        $st = $conn->prepare('SELECT 1 FROM loyalty_rewards_issued WHERE restaurant_id = ? AND customer_id = ? AND source = ? AND period_key = ? LIMIT 1');
        $st->execute([$rid, $customerId, $c['kind'] === 'streak' ? 'streak' : 'challenge', 'c' . $c['id'] . '-' . $period]);
        return (bool)$st->fetchColumn();
    }

    /** From the order-completed hook: pay out any challenge this order completed. */
    function loyaltyChallengeHooks(PDO $conn, array $order, array $customer): void {
        $rid = $order['restaurant_id'];
        $cur = (string)($conn->query('SELECT currency_symbol FROM users WHERE restaurant_id = ' . $conn->quote($rid) . ' LIMIT 1')->fetchColumn() ?: '₹');
        foreach (loyaltyChallenges($conn, $rid, true) as $c) {
            if ($c['kind'] === 'dish' && !$c['item_id']) continue;
            $p = loyaltyChallengeProgress($conn, $rid, $c, (string)$customer['phone']);
            $done = $c['kind'] === 'streak' ? ($p['weeks'] > 0 && $p['weeks'] % max(2, (int)$c['target']) === 0 && $p['this_week']) : $p['value'] >= $c['target'];
            if (!$done) continue;
            if ($c['reward_kind'] === 'item' && !$c['reward_item_id']) continue;
            loyaltyIssueReward($conn, $rid, (int)$customer['id'], (string)$customer['phone'], $c['kind'] === 'streak' ? 'streak' : 'challenge',
                'c' . $c['id'] . '-' . $p['period'], $c['reward_kind'], $c['reward_value'], $c['min_order'], $c['valid_days'], $c['reward_item_id'] ?: null);
        }
    }

    /** The customer's challenges for the rewards page. */
    function loyaltyChallengesForCustomer(PDO $conn, string $rid, ?array $customer, string $cur): array {
        $out = [];
        foreach (loyaltyChallenges($conn, $rid, true) as $c) {
            if ($c['kind'] === 'dish' && !$c['item_id']) continue;
            $row = ['id' => $c['id'], 'kind' => $c['kind'], 'title' => loyaltyChallengeTitle($c, $cur), 'target' => $c['kind'] === 'streak' ? max(2, (int)$c['target']) : $c['target'],
                    'reward' => loyaltyChallengeRewardLabel($conn, $rid, $c, $cur), 'min_order' => $c['min_order'],
                    'ends' => $c['kind'] === 'streak' ? null : date('Y-m-t'), 'progress' => 0, 'done' => false];
            if ($customer) {
                $p = loyaltyChallengeProgress($conn, $rid, $c, (string)$customer['phone']);
                $row['progress'] = min($row['target'], $p['value']);
                if ($c['kind'] === 'streak') { $row['weeks'] = $p['weeks']; $row['this_week'] = $p['this_week']; }
                $row['done'] = $c['kind'] === 'streak' ? ($p['this_week'] && $p['weeks'] > 0 && $p['weeks'] % $row['target'] === 0) : $p['value'] >= $c['target'];
            }
            $out[] = $row;
        }
        return $out;
    }

    function loyaltyChallengeRewardLabel(PDO $conn, string $rid, array $c, string $cur): string {
        $n = rtrim(rtrim(number_format($c['reward_value'], 2, '.', ''), '0'), '.');
        if ($c['reward_kind'] === 'percent') return $n . '% off';
        if ($c['reward_kind'] === 'item') {
            $st = $conn->prepare('SELECT item_name_en FROM menu_items WHERE id = ? AND restaurant_id = ?');
            $st->execute([(int)$c['reward_item_id'], $rid]);
            return 'Free ' . ($st->fetchColumn() ?: 'dish');
        }
        return $cur . $n . ' off';
    }

    /** Owner numbers per challenge: taking part this period, completed, codes used. */
    function loyaltyChallengeStats(PDO $conn, string $rid, array $c): array {
        $src = $c['kind'] === 'streak' ? 'streak' : 'challenge';
        $st = $conn->prepare("SELECT COUNT(*) issued, SUM(COALESCE(cp.current_uses, 0) > 0) used FROM loyalty_rewards_issued r
            LEFT JOIN coupons cp ON cp.restaurant_id = r.restaurant_id AND cp.coupon_code = r.coupon_code
            WHERE r.restaurant_id = ? AND r.source = ? AND r.period_key LIKE ?");
        $st->execute([$rid, $src, 'c' . $c['id'] . '-%']);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $from = $c['kind'] === 'streak' ? date('Y-m-d', strtotime('-14 days')) : date('Y-m-01');
        if ($c['kind'] === 'dish') {
            $p = $conn->prepare("SELECT COUNT(DISTINCT o.customer_phone) FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE o.restaurant_id = ? AND o.order_status = 'Completed'
                AND o.deleted_at IS NULL AND o.created_at >= ? AND oi.menu_item_id = ? AND o.customer_phone IS NOT NULL AND o.customer_phone <> ''");
            $p->execute([$rid, $from, (int)$c['item_id']]);
        } else {
            $p = $conn->prepare("SELECT COUNT(DISTINCT customer_phone) FROM orders WHERE restaurant_id = ? AND order_status = 'Completed' AND deleted_at IS NULL
                AND created_at >= ? AND customer_phone IS NOT NULL AND customer_phone <> ''");
            $p->execute([$rid, $from]);
        }
        return ['taking_part' => (int)$p->fetchColumn(), 'completed' => (int)($r['issued'] ?? 0), 'used' => (int)($r['used'] ?? 0)];
    }
}
