<?php
/**
 * Bestsellers admin API (Offers > Bestsellers)
 *
 * GET               — the restaurant's bestsellers (ordered) plus every menu
 *                     item, for the picker
 * POST (JSON body)  — { items: [{ menu_item_id, offer_price|null }, ...] }
 *                     replaces the list; array order = display order
 *
 * Always scoped to the session's restaurant; never a client-supplied ID.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/bestseller_helpers.php';

header('Content-Type: application/json; charset=UTF-8');

const MAX_BESTSELLERS = 20;

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}
$restaurantId = $_SESSION['restaurant_id'];

try {
    $conn = getConnection();
    ensureBestsellersTable($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $conn->prepare("
            SELECT b.menu_item_id, b.offer_price, b.sort_order
            FROM bestsellers b
            JOIN menu_items mi ON mi.id = b.menu_item_id AND mi.restaurant_id = b.restaurant_id
            WHERE b.restaurant_id = ?
            ORDER BY b.sort_order, b.id
        ");
        $stmt->execute([$restaurantId]);
        $selected = array_map(function ($r) {
            return ['menu_item_id' => (int)$r['menu_item_id'], 'offer_price' => $r['offer_price'] !== null ? (float)$r['offer_price'] : null];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        $stmt = $conn->prepare("
            SELECT mi.id, mi.item_name_en AS name, mi.base_price, mi.item_type, mi.item_image,
                   mi.is_available, mi.has_variations, m.menu_name,
                   (SELECT COUNT(*) FROM menu_item_variations v WHERE v.menu_item_id = mi.id) AS variation_count
            FROM menu_items mi
            JOIN menu m ON m.id = mi.menu_id
            WHERE mi.restaurant_id = ?
            ORDER BY m.sort_order, m.menu_name, mi.sort_order, mi.item_name_en
        ");
        $stmt->execute([$restaurantId]);
        $items = array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'price' => (float)$r['base_price'],
                'type' => $r['item_type'],
                'image' => $r['item_image'],
                'available' => (int)$r['is_available'] === 1,
                'has_variations' => (int)$r['has_variations'] === 1 && (int)$r['variation_count'] > 0,
                'category' => $r['menu_name'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode(['success' => true, 'bestsellers' => $selected, 'items' => $items, 'max' => MAX_BESTSELLERS], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Changing what the public website promotes (and its prices) is an
    // admin/manager action, not something waiters/chefs should do.
    $role = getUserRole();
    if (!in_array($role, ['Admin', 'Manager'], true) && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only an admin or manager can change bestsellers']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $list = $input['items'] ?? null;
    if (!is_array($list)) {
        throw new InvalidArgumentException('Invalid request');
    }
    if (count($list) > MAX_BESTSELLERS) {
        throw new InvalidArgumentException('You can feature up to ' . MAX_BESTSELLERS . ' bestsellers');
    }

    // Only this restaurant's own items may be featured
    $ids = [];
    foreach ($list as $row) {
        $id = (int)($row['menu_item_id'] ?? 0);
        if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
    }
    $prices = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT id, base_price, item_name_en FROM menu_items WHERE restaurant_id = ? AND id IN ($ph)");
        $stmt->execute(array_merge([$restaurantId], $ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $prices[(int)$r['id']] = $r;
    }

    $rows = [];
    $seen = [];
    foreach ($list as $row) {
        $id = (int)($row['menu_item_id'] ?? 0);
        if (!isset($prices[$id]) || isset($seen[$id])) continue;
        $seen[$id] = true;
        $offer = $row['offer_price'] ?? null;
        if ($offer === '' || $offer === null) {
            $offer = null;
        } else {
            $offer = round((float)$offer, 2);
            if ($offer <= 0 || $offer >= (float)$prices[$id]['base_price']) {
                throw new InvalidArgumentException('Offer price for "' . $prices[$id]['item_name_en'] . '" must be more than 0 and less than its regular price');
            }
        }
        $rows[] = [$id, $offer];
    }

    $conn->beginTransaction();
    $conn->prepare("DELETE FROM bestsellers WHERE restaurant_id = ?")->execute([$restaurantId]);
    $ins = $conn->prepare("INSERT INTO bestsellers (restaurant_id, menu_item_id, sort_order, offer_price) VALUES (?, ?, ?, ?)");
    foreach ($rows as $i => [$id, $offer]) {
        $ins->execute([$restaurantId, $id, $i, $offer]);
    }
    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'Bestsellers saved', 'count' => count($rows)]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('bestsellers.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save bestsellers. Please try again.']);
}
