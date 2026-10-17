<?php
/**
 * Today's Special admin API (Offers > Today's Special)
 *
 * GET              — every saved dish (weekday 0-6, or 7 = every day) plus all dishes for the pickers
 * POST (JSON body) — { specials: [{ weekday: 0-7, menu_item_id, note, daily_limit }, ...] } replaces them;
 *                    array order = display order within a day (max TODAYS_SPECIAL_MAX per day)
 *
 * Always scoped to the session's restaurant.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/todays_special_helpers.php';

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
    ensureTodaysSpecialTable($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $st = $conn->prepare("SELECT mi.id, mi.item_name_en AS name, mi.base_price, m.menu_name
            FROM menu_items mi JOIN menu m ON m.id = mi.menu_id
            WHERE mi.restaurant_id = ? ORDER BY m.sort_order, m.menu_name, mi.sort_order, mi.item_name_en");
        $st->execute([$restaurantId]);
        $items = array_map(function ($r) {
            return ['id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['base_price'], 'category' => $r['menu_name']];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $conn->prepare('SELECT weekday, menu_item_id, note, daily_limit FROM todays_specials WHERE restaurant_id = ? ORDER BY weekday, position, id');
        $st->execute([$restaurantId]);
        $specials = array_map(function ($r) {
            return ['weekday' => (int)$r['weekday'], 'menu_item_id' => $r['menu_item_id'] ? (int)$r['menu_item_id'] : null,
                    'note' => (string)($r['note'] ?? ''), 'daily_limit' => $r['daily_limit'] ? (int)$r['daily_limit'] : null];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(['success' => true, 'items' => $items, 'specials' => $specials, 'max' => TODAYS_SPECIAL_MAX,
                          'today' => getTodaysSpecials($conn, $restaurantId)]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false]); exit; }

    $in = json_decode(file_get_contents('php://input'), true);
    $list = is_array($in['specials'] ?? null) ? $in['specials'] : null;
    if ($list === null) throw new InvalidArgumentException('Nothing to save');

    // Only this restaurant's own dishes
    $own = $conn->prepare('SELECT id FROM menu_items WHERE restaurant_id = ?');
    $own->execute([$restaurantId]);
    $ownIds = array_flip(array_map('intval', $own->fetchAll(PDO::FETCH_COLUMN)));

    $conn->beginTransaction();
    $conn->prepare('DELETE FROM todays_specials WHERE restaurant_id = ?')->execute([$restaurantId]);
    $ins = $conn->prepare('INSERT INTO todays_specials (restaurant_id, weekday, position, menu_item_id, note, daily_limit) VALUES (?, ?, ?, ?, ?, ?)');
    $perDay = [];
    foreach ($list as $s) {
        $wd = (int)($s['weekday'] ?? -1);
        $item = (int)($s['menu_item_id'] ?? 0);
        if ($wd < 0 || $wd > 7 || !$item || !isset($ownIds[$item])) continue;
        $key = $wd . ':' . $item;
        if (isset($perDay[$key])) continue;                          // same dish twice on one day
        $pos = count(array_filter(array_keys($perDay), function ($k) use ($wd) { return strpos($k, $wd . ':') === 0; }));
        if ($pos >= TODAYS_SPECIAL_MAX) continue;
        $perDay[$key] = 1;
        $note = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)($s['note'] ?? '')))), 0, 160);
        $limit = (int)($s['daily_limit'] ?? 0);
        $ins->execute([$restaurantId, $wd, $pos, $item, $note !== '' ? $note : null, $limit > 0 ? min($limit, 9999) : null]);
    }
    $conn->commit();
    echo json_encode(['success' => true, 'today' => getTodaysSpecials($conn, $restaurantId)]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('todays_special.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save. Please try again.']);
}
