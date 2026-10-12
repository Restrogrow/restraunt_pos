<?php
/**
 * Loyalty Studio admin API (Growth > Loyalty & Rewards).
 *
 * GET  ?section=program   — program type + its settings, dishes (for a free-dish reward), wallet status
 * POST JSON { section: 'program', ... }   — save the program
 *
 * Later sections (autopilot, games, vip, performance) are added here as they land.
 * Always scoped to the session's restaurant.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/loyalty_engine.php';

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

try {
    $conn = getConnection();
    ensureLoyaltyEngineSchema($conn);
    $cur = (string)($conn->query('SELECT currency_symbol FROM users WHERE restaurant_id = ' . $conn->quote($rid) . ' LIMIT 1')->fetchColumn() ?: '₹');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $section = $_GET['section'] ?? 'program';
        if ($section === 'program') {
            $s = getGrowthSettings($conn, $rid);
            $items = $conn->prepare('SELECT mi.id, mi.item_name_en name, mi.base_price price, m.menu_name category FROM menu_items mi JOIN menu m ON m.id = mi.menu_id WHERE mi.restaurant_id = ? ORDER BY m.sort_order, m.menu_name, mi.item_name_en');
            $items->execute([$rid]);
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
                'items' => array_map(function ($r) { return ['id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'], 'category' => $r['category']]; }, $items->fetchAll(PDO::FETCH_ASSOC)),
                'members' => array_map('intval', $members->fetch(PDO::FETCH_ASSOC) ?: []),
                'phonepe_ready' => !empty($uu['phonepe_merchant_id']) || ($uu['payment_gateway_mode'] ?? 'own') !== 'own',
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

    laOut(['success' => false, 'message' => 'Unknown section'], 400);
} catch (Throwable $e) {
    error_log('loyalty_admin.php: ' . $e->getMessage());
    laOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
