<?php
/**
 * Offer filters admin API (Offers > Offer Filters)
 *
 * GET               — the restaurant's filters (ordered) plus every menu item,
 *                     for the hand-picked dish picker
 * POST (JSON body)  — { filters: [{ title, icon, rule_type, rule_value,
 *                                   item_ids: [menu item ids], is_active }, ...] }
 *                     replaces the list; array order = display order.
 *
 * Always scoped to the session's restaurant; never a client-supplied ID.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/offer_filter_helpers.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}
$restaurantId = $_SESSION['restaurant_id'];
session_write_close(); // only reads the session from here on; don't block parallel requests

try {
    $conn = getConnection();
    ensureOfferFiltersTable($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $conn->prepare("
            SELECT mi.id, mi.item_name_en AS name, mi.base_price, mi.item_type, m.menu_name
            FROM menu_items mi
            JOIN menu m ON m.id = mi.menu_id
            WHERE mi.restaurant_id = ?
            ORDER BY m.sort_order, m.menu_name, mi.sort_order, mi.item_name_en
        ");
        $stmt->execute([$restaurantId]);
        $items = array_map(function ($r) {
            return ['id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['base_price'],
                    'type' => $r['item_type'], 'category' => $r['menu_name']];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode([
            'success' => true,
            'filters' => getOfferFilters($conn, $restaurantId),
            'items' => $items,
            'icons' => OFFER_FILTER_ICONS,
            'max' => OFFER_FILTER_MAX,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // What the public website promotes is an admin/manager decision
    $role = getUserRole();
    if (!in_array($role, ['Admin', 'Manager'], true) && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only an admin or manager can change offer filters']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $list = $input['filters'] ?? null;
    if (!is_array($list)) throw new InvalidArgumentException('Invalid request');
    if (count($list) > OFFER_FILTER_MAX) throw new InvalidArgumentException('You can add up to ' . OFFER_FILTER_MAX . ' filters');

    // Only this restaurant's dishes can be hand-picked
    $stmt = $conn->prepare("SELECT id FROM menu_items WHERE restaurant_id = ?");
    $stmt->execute([$restaurantId]);
    $ownIds = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

    $rows = [];
    foreach ($list as $i => $f) {
        $n = $i + 1;
        $title = trim((string)($f['title'] ?? ''));
        if ($title === '') throw new InvalidArgumentException("Filter $n needs a name");
        if (mb_strlen($title) > 40) throw new InvalidArgumentException("Filter $n: name is too long (40 characters max)");
        $rule = (string)($f['rule_type'] ?? '');
        if (!array_key_exists($rule, OFFER_FILTER_RULES)) throw new InvalidArgumentException("Filter $n: choose what it shows");
        $icon = in_array($f['icon'] ?? '', OFFER_FILTER_ICONS, true) ? $f['icon'] : OFFER_FILTER_ICONS[0];

        $value = null;
        if (OFFER_FILTER_RULES[$rule]) {
            $value = is_numeric($f['rule_value'] ?? null) ? round((float)$f['rule_value'], 2) : null;
            if ($rule === 'price_under' && !($value > 0)) throw new InvalidArgumentException("“$title”: enter the price limit");
            if ($rule === 'discount_min' && !($value > 0 && $value < 100)) throw new InvalidArgumentException("“$title”: discount must be between 1 and 99 %");
        }

        $ids = [];
        if ($rule === 'handpicked') {
            foreach ((array)($f['item_ids'] ?? []) as $id) {
                $id = (int)$id;
                if (isset($ownIds[$id]) && !in_array($id, $ids, true)) $ids[] = $id;
            }
            if (!$ids) throw new InvalidArgumentException("“$title”: pick at least one dish");
        }

        $rows[] = [$title, $icon, $rule, $value, $ids ? json_encode($ids) : null, !empty($f['is_active']) ? 1 : 0, $i];
    }

    $conn->beginTransaction();
    $conn->prepare("DELETE FROM offer_filters WHERE restaurant_id = ?")->execute([$restaurantId]);
    $ins = $conn->prepare("INSERT INTO offer_filters (restaurant_id, title, icon, rule_type, rule_value, item_ids, is_active, sort_order)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) $ins->execute(array_merge([$restaurantId], $r));
    $conn->commit();

    echo json_encode(['success' => true, 'filters' => getOfferFilters($conn, $restaurantId)], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('offer_filters.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save offer filters. Please try again.']);
}
