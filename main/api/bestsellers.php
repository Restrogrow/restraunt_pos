<?php
/**
 * Bestsellers admin API (Offers > Bestsellers)
 *
 * GET               — the restaurant's bestsellers (ordered) plus every menu
 *                     item, for the picker
 * POST (JSON body)  — { items: [{ menu_item_id, offer_price|null,
 *                                 variation_offers: { size name: price } }, ...] }
 *                     replaces the list; array order = display order. Items
 *                     with sizes use variation_offers instead of offer_price.
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
            SELECT b.menu_item_id, b.offer_price, b.variation_offers, b.sort_order
            FROM bestsellers b
            JOIN menu_items mi ON mi.id = b.menu_item_id AND mi.restaurant_id = b.restaurant_id
            WHERE b.restaurant_id = ?
            ORDER BY b.sort_order, b.id
        ");
        $stmt->execute([$restaurantId]);
        $selected = array_map(function ($r) {
            return ['menu_item_id' => (int)$r['menu_item_id'], 'offer_price' => $r['offer_price'] !== null ? (float)$r['offer_price'] : null,
                    'variation_offers' => (object)decodeVariationOffers($r['variation_offers'])];
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
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Sizes (name + regular price) for items with variations
        $sizes = [];
        $vs = $conn->prepare("SELECT v.menu_item_id, v.variation_name, v.price FROM menu_item_variations v JOIN menu_items mi ON mi.id = v.menu_item_id WHERE mi.restaurant_id = ? ORDER BY v.menu_item_id, v.sort_order, v.id");
        $vs->execute([$restaurantId]);
        foreach ($vs->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $sizes[(int)$v['menu_item_id']][] = ['name' => $v['variation_name'], 'price' => (float)$v['price']];
        }
        $items = array_map(function ($r) use ($sizes) {
            return [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'price' => (float)$r['base_price'],
                'type' => $r['item_type'],
                'image' => $r['item_image'],
                'available' => (int)$r['is_available'] === 1,
                'has_variations' => (int)$r['has_variations'] === 1 && (int)$r['variation_count'] > 0,
                'variations' => $sizes[(int)$r['id']] ?? [],
                'category' => $r['menu_name'],
            ];
        }, $rows);

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
    // Regular price of each size, for validating per-size offers
    $sizePrices = [];
    if ($ids) {
        $stmt = $conn->prepare("SELECT menu_item_id, variation_name, price FROM menu_item_variations WHERE menu_item_id IN ($ph)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $v) $sizePrices[(int)$v['menu_item_id']][$v['variation_name']] = (float)$v['price'];
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
        // Per-size offers (items with sizes); unknown sizes are ignored
        $varOffers = [];
        if (!empty($sizePrices[$id]) && is_array($row['variation_offers'] ?? null)) {
            $offer = null; // sizes have their own offers, never a whole-item one
            foreach ($row['variation_offers'] as $size => $p) {
                if (!isset($sizePrices[$id][$size]) || $p === '' || $p === null) continue;
                $p = round((float)$p, 2);
                if ($p <= 0 || $p >= $sizePrices[$id][$size]) {
                    throw new InvalidArgumentException('Offer price for "' . $prices[$id]['item_name_en'] . ' (' . $size . ')" must be more than 0 and less than its regular price');
                }
                $varOffers[$size] = $p;
            }
        }
        $rows[] = [$id, $offer, $varOffers ? json_encode($varOffers, JSON_UNESCAPED_UNICODE) : null];
    }

    $conn->beginTransaction();
    $conn->prepare("DELETE FROM bestsellers WHERE restaurant_id = ?")->execute([$restaurantId]);
    $ins = $conn->prepare("INSERT INTO bestsellers (restaurant_id, menu_item_id, sort_order, offer_price, variation_offers) VALUES (?, ?, ?, ?, ?)");
    foreach ($rows as $i => [$id, $offer, $varOffers]) {
        $ins->execute([$restaurantId, $id, $i, $offer, $varOffers]);
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
