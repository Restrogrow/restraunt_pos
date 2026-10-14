<?php
/**
 * Offers › Combos & Deals (dashboard): build and run combos (config/combo_helpers.php).
 *
 *   GET                       combos with how each did (30 days), totals, and the menu for the dish picker
 *   POST {action:'save', combo}            create / edit (the whole combo, slots and choices)
 *   POST {action:'toggle', id, is_active}  on / off
 *   POST {action:'delete', id}
 *   POST {action:'duplicate', id}
 *   POST {action:'order', ids:[...]}       order on the website
 *   POST multipart action=upload, image    a combo photo -> uploads/combos/...
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}
$rid = (string)$_SESSION['restaurant_id'];
$canEdit = hasPermission(PERMISSION_MANAGE_COUPONS) || hasPermission(PERMISSION_MANAGE_MENU);
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/combo_helpers.php';

function cbOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $conn = getConnection();
    ensureCombosTables($conn);
    $q = function (string $sql, array $args = []) use ($conn) { $st = $conn->prepare($sql); $st->execute($args); return $st; };

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canEdit) cbOut(['success' => false, 'message' => 'You don’t have permission to change offers.'], 403);

        // photo upload (multipart)
        if (($_POST['action'] ?? '') === 'upload') {
            $f = $_FILES['image'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) cbOut(['success' => false, 'message' => 'The photo did not upload. Try again.'], 400);
            if ($f['size'] > 4 * 1024 * 1024) cbOut(['success' => false, 'message' => 'Photo is too big (4 MB max).'], 400);
            $info = @getimagesize($f['tmp_name']);
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
            if (!$ext) cbOut(['success' => false, 'message' => 'Use a JPG, PNG or WebP photo.'], 400);
            $dir = __DIR__ . '/../uploads/combos';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $name = 'combo_' . preg_replace('/[^A-Za-z0-9]/', '', $rid) . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) cbOut(['success' => false, 'message' => 'Could not save the photo.'], 500);
            cbOut(['success' => true, 'path' => 'uploads/combos/' . $name]);
        }

        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $in['action'] ?? '';
        $own = function (int $id) use ($q, $rid) { return (bool)$q('SELECT id FROM combos WHERE id = ? AND restaurant_id = ?', [$id, $rid])->fetchColumn(); };

        if ($action === 'save') {
            try { $id = saveCombo($conn, $rid, (array)($in['combo'] ?? [])); }
            catch (InvalidArgumentException $e) { cbOut(['success' => false, 'message' => $e->getMessage()], 400); }
            cbOut(['success' => true, 'id' => $id]);
        }
        if ($action === 'toggle') {
            $id = (int)($in['id'] ?? 0);
            if (!$own($id)) cbOut(['success' => false, 'message' => 'That combo was not found.'], 404);
            $q('UPDATE combos SET is_active = ? WHERE id = ? AND restaurant_id = ?', [!empty($in['is_active']) ? 1 : 0, $id, $rid]);
            cbOut(['success' => true]);
        }
        if ($action === 'delete') {
            $id = (int)($in['id'] ?? 0);
            if (!$own($id)) cbOut(['success' => false, 'message' => 'That combo was not found.'], 404);
            $conn->beginTransaction();
            $slots = $q('SELECT id FROM combo_slots WHERE combo_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN);
            if ($slots) $q('DELETE FROM combo_slot_options WHERE slot_id IN (' . implode(',', array_fill(0, count($slots), '?')) . ')', $slots);
            $q('DELETE FROM combo_slots WHERE combo_id = ?', [$id]);
            $q('DELETE FROM combos WHERE id = ? AND restaurant_id = ?', [$id, $rid]);
            $conn->commit();
            cbOut(['success' => true]);
        }
        if ($action === 'duplicate') {
            $id = (int)($in['id'] ?? 0);
            $all = getCombos($conn, $rid, false);
            $src = null;
            foreach ($all as $c) if ($c['id'] === $id) $src = $c;
            if (!$src) cbOut(['success' => false, 'message' => 'That combo was not found.'], 404);
            $copy = $src;
            $copy['id'] = 0;
            $copy['name'] = mb_substr($src['name'] . ' (copy)', 0, 120);
            $copy['is_active'] = 0;
            $copy['image'] = $src['image'];
            $copy['slots'] = array_map(function ($s) {
                return ['title' => $s['title'], 'qty' => $s['qty'], 'choices' => array_map(function ($o) {
                    return ['menu_item_id' => $o['menu_item_id'], 'variation' => $o['variation'], 'extra' => $o['extra']];
                }, $s['choices'])];
            }, $src['slots']);
            try { $newId = saveCombo($conn, $rid, $copy); }
            catch (InvalidArgumentException $e) { cbOut(['success' => false, 'message' => $e->getMessage()], 400); }
            cbOut(['success' => true, 'id' => $newId]);
        }
        if ($action === 'order') {
            $ids = array_map('intval', (array)($in['ids'] ?? []));
            $st = $conn->prepare('UPDATE combos SET sort_order = ? WHERE id = ? AND restaurant_id = ?');
            foreach ($ids as $i => $id) $st->execute([$i + 1, $id, $rid]);
            cbOut(['success' => true]);
        }
        cbOut(['success' => false, 'message' => 'Unknown action'], 400);
    }

    // ── GET: combos, how they did, and the menu for the picker ──
    $combos = getCombos($conn, $rid, false);
    $sales = [];
    foreach ($q('SELECT combo_id, SUM(qty) AS sold, SUM(revenue) AS revenue FROM combo_sales WHERE restaurant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY combo_id', [$rid])->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sales[(int)$r['combo_id']] = ['sold' => (int)$r['sold'], 'revenue' => round((float)$r['revenue'], 2)];
    }
    $sold = 0; $revenue = 0; $saves = [];
    foreach ($combos as &$c) {
        $c['sold'] = $sales[$c['id']]['sold'] ?? 0;
        $c['revenue'] = $sales[$c['id']]['revenue'] ?? 0;
        $sold += $c['sold']; $revenue += $c['revenue'];
        if ($c['worth'] > 0) $saves[] = $c['save'] / $c['worth'];
    }
    unset($c);

    $dishes = $q("SELECT m.id, m.item_name_en AS name, m.item_image AS image, m.item_type AS type, m.base_price AS price, m.is_available, m.has_variations, c.menu_name AS category
        FROM menu_items m LEFT JOIN menu c ON c.id = m.menu_id
        WHERE m.restaurant_id = ? AND COALESCE(m.is_hidden, 0) = 0 ORDER BY c.sort_order, c.id, m.sort_order, m.id", [$rid])->fetchAll(PDO::FETCH_ASSOC);
    $sizes = [];
    if ($dishes) {
        $ids = array_column($dishes, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        foreach ($q("SELECT menu_item_id, variation_name, price FROM menu_item_variations WHERE menu_item_id IN ($ph) ORDER BY sort_order, id", $ids)->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $sizes[(int)$v['menu_item_id']][] = ['name' => $v['variation_name'], 'price' => round((float)$v['price'], 2)];
        }
    }
    foreach ($dishes as &$d) {
        $d['id'] = (int)$d['id']; $d['price'] = round((float)$d['price'], 2); $d['is_available'] = (int)$d['is_available'];
        $d['sizes'] = $sizes[$d['id']] ?? [];
        unset($d['has_variations']);
    }
    unset($d);

    $cur = (string)($q('SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1', [$rid])->fetchColumn() ?: '₹');
    cbOut(['success' => true, 'currency' => $cur, 'can_edit' => $canEdit, 'combos' => $combos, 'dishes' => $dishes,
        'totals' => ['live' => count(array_filter($combos, fn($c) => $c['live'])), 'count' => count($combos), 'sold' => $sold, 'revenue' => round($revenue, 2),
            'avg_save' => $saves ? (int)round(array_sum($saves) / count($saves) * 100) : 0]]);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('combos_admin: ' . $e->getMessage());
    cbOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
