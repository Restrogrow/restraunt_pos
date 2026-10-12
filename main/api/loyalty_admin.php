<?php
/**
 * Loyalty Studio admin API (Growth > Loyalty & Rewards).
 *
 * GET  ?section=program   — program type + its settings, dishes (for a free-dish reward), wallet status
 * GET  ?section=autopilot — automations, their numbers, who is coming up, recent gifts
 * POST JSON { section: 'program', ... }   — save the program
 * POST JSON { section: 'autopilot', type, enabled, reward_kind, ... } — save one automation
 * POST JSON { section: 'autopilot', action: 'run' } — run the date checks now
 * Always scoped to the session's restaurant.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/loyalty_autopilot.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}
$rid = $_SESSION['restaurant_id'];
session_write_close();

function laOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function laItems(PDO $conn, string $rid): array {
    $st = $conn->prepare('SELECT mi.id, mi.item_name_en name, mi.base_price price, m.menu_name category FROM menu_items mi JOIN menu m ON m.id = mi.menu_id WHERE mi.restaurant_id = ? ORDER BY m.sort_order, m.menu_name, mi.item_name_en');
    $st->execute([$rid]);
    return array_map(function ($r) { return ['id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'], 'category' => $r['category']]; }, $st->fetchAll(PDO::FETCH_ASSOC));
}

try {
    $conn = getConnection();
    ensureLoyaltyAutopilotSchema($conn);
    $cur = (string)($conn->query('SELECT currency_symbol FROM users WHERE restaurant_id = ' . $conn->quote($rid) . ' LIMIT 1')->fetchColumn() ?: '₹');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $section = $_GET['section'] ?? 'program';
        if ($section === 'program') {
            $s = getGrowthSettings($conn, $rid);
            $items = laItems($conn, $rid);
            $members = $conn->prepare('SELECT COUNT(*) total, SUM(loyalty_points_balance > 0) with_points, SUM(stamps > 0) with_stamps, SUM(birthday IS NOT NULL) with_birthday, SUM(whatsapp_optin = 1) opted_in FROM customers WHERE restaurant_id = ?');
            $members->execute([$rid]);
            $u = $conn->prepare('SELECT phonepe_merchant_id, payment_gateway_mode FROM users WHERE restaurant_id = ? LIMIT 1');
            $u->execute([$rid]);
            $uu = $u->fetch(PDO::FETCH_ASSOC) ?: [];
            laOut(['success' => true, 'currency' => $cur,
                'program' => [
                    'loyalty_enabled' => (int)$s['loyalty_enabled'], 'program_type' => loyaltyProgramType($s),
                    'cashback_pct' => (float)$s['cashback_pct'], 'cashback_max' => (float)$s['cashback_max'],
                    'stamp_goal' => (int)$s['stamp_goal'], 'stamp_min_order' => (float)$s['stamp_min_order'],
                    'stamp_reward_kind' => $s['stamp_reward_kind'], 'stamp_reward_item_id' => $s['stamp_reward_item_id'] ? (int)$s['stamp_reward_item_id'] : null,
                    'stamp_reward_value' => (float)$s['stamp_reward_value'], 'reward_valid_days' => (int)$s['reward_valid_days'],
                    'earn_points_per_amount' => (int)$s['earn_points_per_amount'], 'earn_amount_threshold' => (float)$s['earn_amount_threshold'],
                    'redeem_value_per_point' => (float)$s['redeem_value_per_point'],
                ],
                'items' => $items,
                'members' => array_map('intval', $members->fetch(PDO::FETCH_ASSOC) ?: []),
                'phonepe_ready' => !empty($uu['phonepe_merchant_id']) || ($uu['payment_gateway_mode'] ?? 'own') !== 'own',
            ]);
        }
        if ($section === 'autopilot') {
            loyaltyRunSweep($conn, $rid);
            $s = getGrowthSettings($conn, $rid);
            $q = function ($sql) use ($conn, $rid) { $st = $conn->prepare($sql); $st->execute([$rid]); return (int)$st->fetchColumn(); };
            $A = loyaltyAutomations($conn, $rid);
            $soon = function ($col, $days) { return "SELECT COUNT(*) FROM customers WHERE restaurant_id = ? AND `$col` IS NOT NULL AND (
                DATEDIFF(STR_TO_DATE(CONCAT(YEAR(CURDATE()), DATE_FORMAT(`$col`, '-%m-%d')), '%Y-%m-%d'), CURDATE()) BETWEEN 0 AND $days
             OR DATEDIFF(STR_TO_DATE(CONCAT(YEAR(CURDATE()) + 1, DATE_FORMAT(`$col`, '-%m-%d')), '%Y-%m-%d'), CURDATE()) BETWEEN 0 AND $days)"; };
            $wb = max(7, (int)$A['winback']['param']);
            $recent = $conn->prepare('SELECT r.source, r.label, r.coupon_code, r.created_at, r.expires_on, r.notify_status, c.customer_name, c.phone, COALESCE(cp.current_uses, 0) > 0 used
                FROM loyalty_rewards_issued r LEFT JOIN customers c ON c.id = r.customer_id LEFT JOIN coupons cp ON cp.restaurant_id = r.restaurant_id AND cp.coupon_code = r.coupon_code
                WHERE r.restaurant_id = ? ORDER BY r.id DESC LIMIT 12');
            $recent->execute([$rid]);
            laOut(['success' => true, 'currency' => $cur,
                'loyalty_enabled' => (int)$s['loyalty_enabled'], 'program_type' => loyaltyProgramType($s), 'referral_enabled' => (int)($s['referral_enabled'] ?? 0),
                'last_run' => $s['autopilot_last_run'] ?? null,
                'automations' => array_values($A),
                'stats' => (object)loyaltyAutomationStats($conn, $rid),
                'upcoming' => [
                    'birthday' => $q($soon('birthday', 30)), 'anniversary' => $q($soon('anniversary', 30)),
                    'winback' => $q("SELECT COUNT(*) FROM (SELECT c.id FROM customers c JOIN orders o ON o.restaurant_id = c.restaurant_id AND o.customer_phone = c.phone AND o.order_status = 'Completed' AND o.deleted_at IS NULL WHERE c.restaurant_id = ? GROUP BY c.id HAVING MAX(o.created_at) < NOW() - INTERVAL $wb DAY) x"),
                    'birthdays_known' => $q('SELECT COUNT(*) FROM customers WHERE restaurant_id = ? AND birthday IS NOT NULL'),
                    'opted_in' => $q('SELECT COUNT(*) FROM customers WHERE restaurant_id = ? AND whatsapp_optin = 1'),
                ],
                'recent' => array_map(function ($r) { return ['source' => $r['source'], 'label' => $r['label'], 'code' => $r['coupon_code'], 'at' => $r['created_at'], 'expires_on' => $r['expires_on'],
                    'name' => $r['customer_name'] ?: 'Guest', 'phone' => $r['phone'] ? substr($r['phone'], 0, 2) . '******' . substr($r['phone'], -2) : '', 'used' => (int)$r['used'], 'notify' => $r['notify_status']]; }, $recent->fetchAll(PDO::FETCH_ASSOC)),
                'items' => laItems($conn, $rid),
            ]);
        }
        laOut(['success' => false, 'message' => 'Unknown section'], 400);
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $section = $in['section'] ?? '';

    if ($section === 'program') {
        $num = function ($v, $min, $max) { return max($min, min($max, round((float)$v, 2))); };
        $type = in_array($in['program_type'] ?? '', LOYALTY_PROGRAM_TYPES, true) ? $in['program_type'] : 'points';
        $kind = in_array($in['stamp_reward_kind'] ?? '', ['item', 'flat', 'percent'], true) ? $in['stamp_reward_kind'] : 'item';
        $itemId = (int)($in['stamp_reward_item_id'] ?? 0);
        if ($itemId) {
            $own = $conn->prepare('SELECT 1 FROM menu_items WHERE id = ? AND restaurant_id = ?');
            $own->execute([$itemId, $rid]);
            if (!$own->fetchColumn()) $itemId = 0;
        }
        if ($type === 'stamps' && $kind === 'item' && !$itemId) laOut(['success' => false, 'message' => 'Pick the free dish for a full stamp card'], 400);
        if ($type === 'stamps' && $kind !== 'item' && $num($in['stamp_reward_value'] ?? 0, 0, 100000) <= 0) laOut(['success' => false, 'message' => 'Set the reward for a full stamp card'], 400);
        $conn->prepare('UPDATE growth_settings SET loyalty_enabled = ?, program_type = ?, cashback_pct = ?, cashback_max = ?, stamp_goal = ?, stamp_min_order = ?,
                stamp_reward_kind = ?, stamp_reward_item_id = ?, stamp_reward_value = ?, reward_valid_days = ? WHERE restaurant_id = ?')
             ->execute([empty($in['loyalty_enabled']) ? 0 : 1, $type, $num($in['cashback_pct'] ?? 5, 0.5, 50), $num($in['cashback_max'] ?? 0, 0, 100000),
                        (int)$num($in['stamp_goal'] ?? 6, 2, 20), $num($in['stamp_min_order'] ?? 0, 0, 100000), $kind, $itemId ?: null,
                        $num($in['stamp_reward_value'] ?? 0, 0, 100000), (int)$num($in['reward_valid_days'] ?? 30, 1, 365), $rid]);
        loyaltySyncCashbackToWallet($conn, $rid, getGrowthSettings($conn, $rid));
        laOut(['success' => true]);
    }

    if ($section === 'autopilot') {
        if (($in['action'] ?? '') === 'run') laOut(['success' => true, 'issued' => loyaltyRunSweep($conn, $rid, true)]);
        $defs = loyaltyAutopilotDefaults();
        $type = (string)($in['type'] ?? '');
        if (!isset($defs[$type])) laOut(['success' => false, 'message' => 'Unknown automation'], 400);
        $kind = in_array($in['reward_kind'] ?? '', ['item', 'flat', 'percent'], true) ? $in['reward_kind'] : 'flat';
        $value = max(0, min($kind === 'percent' ? 100 : 100000, round((float)($in['reward_value'] ?? 0), 2)));
        $itemId = (int)($in['reward_item_id'] ?? 0);
        if ($itemId) {
            $own = $conn->prepare('SELECT 1 FROM menu_items WHERE id = ? AND restaurant_id = ?');
            $own->execute([$itemId, $rid]);
            if (!$own->fetchColumn()) $itemId = 0;
        }
        $enabled = empty($in['enabled']) ? 0 : 1;
        if ($enabled && $kind === 'item' && !$itemId) laOut(['success' => false, 'message' => 'Pick the free dish'], 400);
        if ($enabled && $kind !== 'item' && $value <= 0) laOut(['success' => false, 'message' => 'Set the gift amount'], 400);
        $limits = ['birthday' => [0, 30], 'anniversary' => [0, 30], 'winback' => [7, 365], 'milestone' => [2, 100]];
        $param = (int)($in['param'] ?? $defs[$type][3]);
        if (isset($limits[$type])) $param = max($limits[$type][0], min($limits[$type][1], $param)); else $param = 0;
        $msg = trim(mb_substr((string)($in['message'] ?? ''), 0, 500)) ?: $defs[$type][6];
        $conn->prepare('INSERT INTO loyalty_automations (restaurant_id, type, enabled, reward_kind, reward_value, reward_item_id, param, min_order, valid_days, message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), reward_kind = VALUES(reward_kind), reward_value = VALUES(reward_value),
                reward_item_id = VALUES(reward_item_id), param = VALUES(param), min_order = VALUES(min_order), valid_days = VALUES(valid_days), message = VALUES(message)')
             ->execute([$rid, $type, $enabled, $kind, $value, $itemId ?: null, $param, max(0, min(100000, round((float)($in['min_order'] ?? 0), 2))),
                        max(1, min(365, (int)($in['valid_days'] ?? 30))), $msg]);
        laOut(['success' => true]);
    }

    laOut(['success' => false, 'message' => 'Unknown section'], 400);
} catch (Throwable $e) {
    error_log('loyalty_admin.php: ' . $e->getMessage());
    laOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
