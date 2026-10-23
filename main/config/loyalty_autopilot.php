<?php
/**
 * Loyalty autopilot (Growth > Loyalty Studio > Autopilot).
 *
 * Rewards that issue themselves as personal gift codes (loyaltyIssueReward):
 *   welcome      first completed order
 *   milestone    every Nth completed order (param = N)
 *   tier_up      the customer reaches a new level
 *   referral     a referred friend's first order: a gift for both (cashback / stamps programs;
 *                the points program keeps its referral points)
 *   birthday     N days before the birthday (param = N)
 *   anniversary  N days before the anniversary (param = N)
 *   winback      no order for N days (param = N)
 * Event ones run from the order-completed hook; date ones run in a sweep at most
 * once an hour per restaurant (triggered by the rewards page / dashboard, or the
 * cron endpoint). Each gift is logged with notify_status 'pending' and the
 * owner's message, ready for WhatsApp sending later.
 */
require_once __DIR__ . '/loyalty_engine.php';
require_once __DIR__ . '/loyalty_games.php';

if (!function_exists('loyaltyAutopilotDefaults')) {

    function loyaltyAutopilotDefaults(): array {
        // type => [enabled, kind, value, param, min_order, valid_days, message]; all off until the owner turns one on
        return [
            'welcome'     => [0, 'flat', 50, 0, 199, 30, 'Welcome to {restaurant}, {name}! Here is {reward} on your next order. Use code {code} before {valid_till}.'],
            'birthday'    => [0, 'item', 0, 7, 0, 14, 'Happy birthday, {name}! {restaurant} has a treat for you: {reward}. Use code {code} before {valid_till}.'],
            'anniversary' => [0, 'percent', 15, 7, 499, 14, 'Happy anniversary, {name}! Celebrate with {reward} at {restaurant}. Use code {code} before {valid_till}.'],
            'winback'     => [0, 'flat', 75, 30, 299, 14, 'We miss you, {name}! Come back to {restaurant} for {reward}. Use code {code} before {valid_till}.'],
            'tier_up'     => [0, 'flat', 100, 0, 399, 30, 'Well done, {name}! You just reached {tier} at {restaurant}. Enjoy {reward}. Use code {code} before {valid_till}.'],
            'milestone'   => [0, 'percent', 20, 10, 0, 30, 'Thank you, {name}! That was order number {count} at {restaurant}. Here is {reward}. Use code {code} before {valid_till}.'],
            'referral'    => [0, 'flat', 50, 0, 199, 30, 'Thanks for spreading the word, {name}! You get {reward} at {restaurant}. Use code {code} before {valid_till}.'],
        ];
    }

    function ensureLoyaltyAutopilotSchema(PDO $conn): void {
        // once per deploy, not on every request (config/schema_cache.php)
        require_once __DIR__ . '/schema_cache.php';
        $rgOnceKey = 'e:ensureLoyaltyAutopilotSchema:' . @filemtime(__FILE__);
        if (rgSchemaKnown($rgOnceKey) || $conn->inTransaction()) return;
        static $done = false;
        if ($done || $conn->inTransaction()) return;
        $done = true;
        ensureLoyaltyEngineSchema($conn);
        ensureLoyaltyGamesSchema($conn);
        $conn->exec("CREATE TABLE IF NOT EXISTS loyalty_automations (
            restaurant_id VARCHAR(50) NOT NULL,
            type VARCHAR(20) NOT NULL,
            enabled TINYINT NOT NULL DEFAULT 0,
            reward_kind VARCHAR(10) NOT NULL DEFAULT 'flat',
            reward_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            reward_item_id INT NULL,
            param INT NOT NULL DEFAULT 0,
            min_order DECIMAL(10,2) NOT NULL DEFAULT 0,
            valid_days INT NOT NULL DEFAULT 30,
            message VARCHAR(500) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (restaurant_id, type)
        )");
        try {
            $q = $conn->query("SHOW COLUMNS FROM growth_settings LIKE 'autopilot_last_run'");
            if (!($q && $q->fetch())) $conn->exec('ALTER TABLE growth_settings ADD COLUMN autopilot_last_run DATETIME NULL');
            $q = $conn->query("SHOW COLUMNS FROM loyalty_rewards_issued LIKE 'message'");
            if (!($q && $q->fetch())) $conn->exec('ALTER TABLE loyalty_rewards_issued ADD COLUMN message VARCHAR(600) NULL');
        } catch (PDOException $e) { error_log('ensureLoyaltyAutopilotSchema: ' . $e->getMessage()); }
            rgSchemaRemember($rgOnceKey);
    }

    /** The owner's automations, defaults filled in. */
    function loyaltyAutomations(PDO $conn, string $rid): array {
        $out = [];
        foreach (loyaltyAutopilotDefaults() as $type => $d) {
            $out[$type] = ['type' => $type, 'enabled' => $d[0], 'reward_kind' => $d[1], 'reward_value' => (float)$d[2], 'reward_item_id' => null,
                           'param' => $d[3], 'min_order' => (float)$d[4], 'valid_days' => $d[5], 'message' => $d[6], 'saved' => false];
        }
        try {
            $st = $conn->prepare('SELECT * FROM loyalty_automations WHERE restaurant_id = ?');
            $st->execute([$rid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!isset($out[$r['type']])) continue;
                $out[$r['type']] = ['type' => $r['type'], 'enabled' => (int)$r['enabled'], 'reward_kind' => $r['reward_kind'], 'reward_value' => (float)$r['reward_value'],
                    'reward_item_id' => $r['reward_item_id'] ? (int)$r['reward_item_id'] : null, 'param' => (int)$r['param'], 'min_order' => (float)$r['min_order'],
                    'valid_days' => (int)$r['valid_days'], 'message' => $r['message'] ?? $out[$r['type']]['message'], 'saved' => true];
            }
        } catch (PDOException $e) {}
        return $out;
    }

    /** "Free Gulab Jamun", "₹50 off", "15% off" for an automation's reward. */
    function loyaltyAutoRewardLabel(PDO $conn, string $rid, array $a): string {
        $n = rtrim(rtrim(number_format((float)$a['reward_value'], 2, '.', ''), '0'), '.');
        if ($a['reward_kind'] === 'percent') return $n . '% off';
        if ($a['reward_kind'] === 'item') {
            $st = $conn->prepare('SELECT item_name_en FROM menu_items WHERE id = ? AND restaurant_id = ?');
            $st->execute([(int)$a['reward_item_id'], $rid]);
            return 'Free ' . ($st->fetchColumn() ?: 'dish');
        }
        $cur = (string)($conn->query('SELECT currency_symbol FROM users WHERE restaurant_id = ' . $conn->quote($rid) . ' LIMIT 1')->fetchColumn() ?: '₹');
        return $cur . $n . ' off';
    }

    /** Issue one automation's gift to one customer, with the message filled in. */
    function loyaltyAutoGift(PDO $conn, string $rid, array $a, array $customer, string $periodKey, array $vars = []): ?array {
        if (!$a['enabled']) return null;
        if ($a['reward_kind'] === 'item' && empty($a['reward_item_id'])) return null;
        $g = loyaltyIssueReward($conn, $rid, (int)$customer['id'], (string)$customer['phone'], $a['type'], $periodKey,
            $a['reward_kind'], (float)$a['reward_value'], (float)$a['min_order'], (int)$a['valid_days'], $a['reward_item_id'] ?: null);
        if (!$g) return null;
        $name = trim(explode(' ', (string)($customer['customer_name'] ?? ''))[0]) ?: 'there';
        $rest = (string)($conn->query('SELECT restaurant_name FROM users WHERE restaurant_id = ' . $conn->quote($rid) . ' LIMIT 1')->fetchColumn() ?: '');
        $msg = strtr((string)$a['message'], ['{name}' => $name, '{reward}' => $g['label'], '{code}' => $g['code'], '{restaurant}' => $rest,
                                             '{tier}' => $vars['tier'] ?? '', '{count}' => (string)($vars['count'] ?? ''),
                                             '{valid_till}' => !empty($g['expires_on']) ? date('j M', strtotime($g['expires_on'])) : 'soon']);
        $conn->prepare('UPDATE loyalty_rewards_issued SET message = ? WHERE restaurant_id = ? AND coupon_code = ?')->execute([mb_substr($msg, 0, 600), $rid, $g['code']]);
        return $g;
    }

    /** Event automations, from the order-completed hook (inside its transaction). */
    function loyaltyAfterOrderHooks(PDO $conn, array $order, int $customerId, array $settings): void {
        $rid = $order['restaurant_id'];
        $A = loyaltyAutomations($conn, $rid);
        $st = $conn->prepare('SELECT * FROM customers WHERE id = ?');
        $st->execute([$customerId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return;
        $phone = (string)$c['phone'];
        $cnt = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(total), 0) FROM orders WHERE restaurant_id = ? AND customer_phone = ? AND order_status = 'Completed' AND deleted_at IS NULL");
        $cnt->execute([$rid, $phone]);
        [$completed, $spent] = $cnt->fetch(PDO::FETCH_NUM);
        $completed = (int)$completed;
        $spent = max((float)$spent, (float)$c['total_spent']);

        if ($completed === 1) loyaltyAutoGift($conn, $rid, $A['welcome'], $c, 'once');
        $n = max(2, (int)$A['milestone']['param']);
        if ($completed > 0 && $completed % $n === 0) loyaltyAutoGift($conn, $rid, $A['milestone'], $c, 'order' . $completed, ['count' => $completed]);

        // Level up: the level now vs before this order's spend
        $now = getCustomerTier($conn, $rid, $spent);
        $before = getCustomerTier($conn, $rid, max(0, $spent - (float)$order['total']));
        if ($now && (!$before || $before['tier_name'] !== $now['tier_name'])) {
            loyaltyAutoGift($conn, $rid, $A['tier_up'], $c, 'tier-' . $now['tier_name'], ['tier' => $now['tier_name']]);
        }

        // Referral gifts for both friends (cashback / stamps programs)
        if (loyaltyProgramType($settings) !== 'points' && !empty($settings['referral_enabled']) && $A['referral']['enabled']) {
            $r = $conn->prepare("SELECT id, referrer_customer_id FROM referrals WHERE restaurant_id = ? AND referred_customer_id = ? AND status = 'pending' FOR UPDATE");
            $r->execute([$rid, $customerId]);
            $ref = $r->fetch(PDO::FETCH_ASSOC);
            if ($ref) {
                $conn->prepare("UPDATE referrals SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$ref['id']]);
                $st->execute([(int)$ref['referrer_customer_id']]);
                $referrer = $st->fetch(PDO::FETCH_ASSOC);
                if ($referrer) loyaltyAutoGift($conn, $rid, $A['referral'], $referrer, 'ref' . $ref['id']);
                loyaltyAutoGift($conn, $rid, $A['referral'], $c, 'refd' . $ref['id']);
            }
        }

        // Challenges and streaks (Games)
        loyaltyChallengeHooks($conn, $order, $c);
    }

    /**
     * Date automations for one restaurant: birthday, anniversary, win-back.
     * At most once an hour unless forced; returns how many gifts were issued.
     */
    function loyaltyRunSweep(PDO $conn, string $rid, bool $force = false): int {
        ensureLoyaltyAutopilotSchema($conn);
        $s = getGrowthSettings($conn, $rid);
        if (empty($s['loyalty_enabled'])) return 0;
        if (!$force && !empty($s['autopilot_last_run']) && strtotime($s['autopilot_last_run']) > time() - 3600) return 0;
        $conn->prepare('UPDATE growth_settings SET autopilot_last_run = NOW() WHERE restaurant_id = ?')->execute([$rid]);
        $A = loyaltyAutomations($conn, $rid);
        $issued = 0;
        $year = (int)date('Y');

        foreach (['birthday', 'anniversary'] as $type) {
            $a = $A[$type];
            if (!$a['enabled']) continue;
            $days = max(0, min(30, (int)$a['param']));
            // the date this year (or next, near New Year) falls within the next N days; customers who already
            // have this occasion's gift are left out (same period key as below), so the 500 are always new ones
            $st = $conn->prepare("SELECT c.* FROM customers c WHERE c.restaurant_id = ? AND c.`$type` IS NOT NULL AND (
                    DATEDIFF(STR_TO_DATE(CONCAT(YEAR(CURDATE()), DATE_FORMAT(c.`$type`, '-%m-%d')), '%Y-%m-%d'), CURDATE()) BETWEEN 0 AND ?
                 OR DATEDIFF(STR_TO_DATE(CONCAT(YEAR(CURDATE()) + 1, DATE_FORMAT(c.`$type`, '-%m-%d')), '%Y-%m-%d'), CURDATE()) BETWEEN 0 AND ?)
                AND NOT EXISTS (SELECT 1 FROM loyalty_rewards_issued r WHERE r.customer_id = c.id AND r.source = ?
                    AND r.period_key = CAST(YEAR(CURDATE()) + (DATE_FORMAT(CURDATE(), '%m-%d') > DATE_FORMAT(c.`$type`, '%m-%d')) AS CHAR)) LIMIT 500");
            $st->execute([$rid, $days, $days, $type]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $mmdd = date('m-d', strtotime($c[$type]));
                $periodYear = (date('m-d') > $mmdd) ? $year + 1 : $year;
                $conn->beginTransaction();
                try { if (loyaltyAutoGift($conn, $rid, $a, $c, (string)$periodYear)) $issued++; $conn->commit(); }
                catch (Throwable $e) { $conn->rollBack(); error_log("autopilot $type: " . $e->getMessage()); }
            }
        }

        $a = $A['winback'];
        if ($a['enabled']) {
            $days = max(7, (int)$a['param']);
            // quiet for N days (but not more than N + 90: those are long gone), one gift per quiet spell
            // (customers who already have this quiet spell's gift are left out, so the 500 are always new ones)
            $st = $conn->prepare("SELECT q.* FROM (SELECT c.*, MAX(o.created_at) last_order FROM customers c
                JOIN orders o ON o.restaurant_id = c.restaurant_id AND o.customer_phone = c.phone AND o.order_status = 'Completed' AND o.deleted_at IS NULL
                WHERE c.restaurant_id = ? GROUP BY c.id
                HAVING last_order < NOW() - INTERVAL ? DAY AND last_order >= NOW() - INTERVAL ? DAY) q
                WHERE NOT EXISTS (SELECT 1 FROM loyalty_rewards_issued r WHERE r.customer_id = q.id AND r.source = 'winback'
                    AND r.period_key = CONCAT('after', DATE_FORMAT(q.last_order, '%Y%m%d'))) LIMIT 500");
            $st->execute([$rid, $days, $days + 90]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $conn->beginTransaction();
                try { if (loyaltyAutoGift($conn, $rid, $a, $c, 'after' . date('Ymd', strtotime($c['last_order'])))) $issued++; $conn->commit(); }
                catch (Throwable $e) { $conn->rollBack(); error_log('autopilot winback: ' . $e->getMessage()); }
            }
        }
        return $issued;
    }

    /** Per-automation numbers for the dashboard (last 90 days). */
    function loyaltyAutomationStats(PDO $conn, string $rid): array {
        $out = [];
        try {
            $st = $conn->prepare("SELECT r.source, COUNT(*) issued, SUM(COALESCE(c.current_uses, 0) > 0) used
                FROM loyalty_rewards_issued r LEFT JOIN coupons c ON c.restaurant_id = r.restaurant_id AND c.coupon_code = r.coupon_code
                WHERE r.restaurant_id = ? AND r.created_at >= NOW() - INTERVAL 90 DAY GROUP BY r.source");
            $st->execute([$rid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['source']] = ['issued' => (int)$r['issued'], 'used' => (int)$r['used'], 'revenue' => 0.0];
            $st = $conn->prepare("SELECT r.source, COALESCE(SUM(o.total), 0) revenue FROM loyalty_rewards_issued r
                JOIN orders o ON o.restaurant_id = r.restaurant_id AND o.coupon_code = r.coupon_code AND o.deleted_at IS NULL AND o.order_status NOT IN ('Cancelled', 'Rejected')
                WHERE r.restaurant_id = ? AND r.created_at >= NOW() - INTERVAL 90 DAY GROUP BY r.source");
            $st->execute([$rid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { if (isset($out[$r['source']])) $out[$r['source']]['revenue'] = (float)$r['revenue']; }
        } catch (PDOException $e) {}
        return $out;
    }
}
