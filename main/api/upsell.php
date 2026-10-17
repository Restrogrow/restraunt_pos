<?php
/**
 * Complete your meal admin API (Offers > Complete your meal)
 *
 * GET              — settings, category roles, dishes, the owner's picks, what was
 *                    learned from real orders and the last 30 days' numbers
 * GET ?relearn=1   — same, with "often ordered together" worked out again now
 * POST (JSON body) — { settings: {enabled, mode, title, max_items, price_cap_pct, veg_safe, goal_nudge},
 *                      roles: {menu_id: role}, always: [item ids], pairs: {item id: [item ids]} }
 *
 * Always scoped to the session's restaurant.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/upsell_helpers.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}
$restaurantId = $_SESSION['restaurant_id'];
session_write_close();

try {
    $conn = getConnection();
    ensureUpsellTables($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $s = getUpsellSettings($conn, $restaurantId);
        $st = $conn->prepare("SELECT mi.id, mi.item_name_en, mi.menu_id, m.menu_name, mi.item_type, mi.is_available, mi.base_price, mi.has_variations, mi.item_image
            FROM menu_items mi JOIN menu m ON m.id = mi.menu_id
            WHERE mi.restaurant_id = ? AND (mi.is_hidden = 0 OR mi.is_hidden IS NULL)
            ORDER BY m.sort_order, m.menu_name, mi.sort_order, mi.item_name_en");
        $st->execute([$restaurantId]);
        $items = array_map(function ($r) {
            return ['id' => (int)$r['id'], 'item_name_en' => $r['item_name_en'], 'menu_id' => (int)$r['menu_id'], 'category' => $r['menu_name'],
                    'item_type' => $r['item_type'], 'is_available' => (int)$r['is_available'], 'base_price' => (float)$r['base_price'],
                    'has_variations' => (int)$r['has_variations'], 'item_image' => $r['item_image']];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $conn->prepare('SELECT COALESCE(SUM(views),0) views, COALESCE(SUM(adds),0) adds, COALESCE(SUM(added_value),0) added
            FROM upsell_stats WHERE restaurant_id = ? AND day >= CURDATE() - INTERVAL 30 DAY');
        $st->execute([$restaurantId]);
        $stats = $st->fetch(PDO::FETCH_ASSOC);
        unset($s['roles']);
        echo json_encode(['success' => true, 'settings' => $s,
            'categories' => upsellCategoryRoles($conn, $restaurantId, getUpsellSettings($conn, $restaurantId)['roles']),
            'items' => $items, 'manual' => upsellManual($conn, $restaurantId),
            'learned' => upsellLearned($conn, $restaurantId, !empty($_GET['relearn'])),
            'stats' => ['views' => (int)$stats['views'], 'adds' => (int)$stats['adds'], 'added' => (float)$stats['added']]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false]); exit; }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in) || !is_array($in['settings'] ?? null)) throw new InvalidArgumentException('Nothing to save');
    $s = $in['settings'];

    $own = $conn->prepare('SELECT id FROM menu_items WHERE restaurant_id = ?');
    $own->execute([$restaurantId]);
    $ownIds = array_flip(array_map('intval', $own->fetchAll(PDO::FETCH_COLUMN)));
    $cats = $conn->prepare('SELECT id FROM menu WHERE restaurant_id = ?');
    $cats->execute([$restaurantId]);
    $ownCats = array_flip(array_map('intval', $cats->fetchAll(PDO::FETCH_COLUMN)));

    $roles = [];
    foreach ((array)($in['roles'] ?? []) as $mid => $role) {
        if (isset($ownCats[(int)$mid]) && in_array($role, UPSELL_ROLES, true)) $roles[(string)(int)$mid] = $role;
    }
    $mode = in_array($s['mode'] ?? '', ['auto', 'manual', 'both'], true) ? $s['mode'] : 'both';
    $title = mb_substr(trim(strip_tags((string)($s['title'] ?? ''))), 0, 60);

    $conn->beginTransaction();
    $conn->prepare('REPLACE INTO upsell_settings (restaurant_id, enabled, mode, title, max_items, price_cap_pct, veg_safe, goal_nudge, roles) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
         ->execute([$restaurantId, empty($s['enabled']) ? 0 : 1, $mode, $title !== '' ? $title : null,
                    max(3, min(10, (int)($s['max_items'] ?? 6))), max(0, min(100, (int)($s['price_cap_pct'] ?? 50))),
                    empty($s['veg_safe']) ? 0 : 1, empty($s['goal_nudge']) ? 0 : 1, json_encode($roles)]);
    $conn->prepare('DELETE FROM upsell_pairs WHERE restaurant_id = ?')->execute([$restaurantId]);
    $ins = $conn->prepare('INSERT INTO upsell_pairs (restaurant_id, menu_item_id, suggest_item_id, position) VALUES (?, ?, ?, ?)');
    $lists = ['0' => (array)($in['always'] ?? [])];
    foreach ((array)($in['pairs'] ?? []) as $x => $ys) $lists[(string)(int)$x] = (array)$ys;
    foreach ($lists as $x => $ys) {
        $x = (int)$x;
        if ($x !== 0 && !isset($ownIds[$x])) continue;
        $pos = 0; $seen = [];
        foreach ($ys as $y) {
            $y = (int)$y;
            if (!isset($ownIds[$y]) || $y === $x || isset($seen[$y]) || $pos >= 10) continue;
            $seen[$y] = 1;
            $ins->execute([$restaurantId, $x, $y, $pos++]);
        }
    }
    $conn->commit();
    echo json_encode(['success' => true]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('upsell.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save. Please try again.']);
}
