<?php
/**
 * Tables › Tables (assets/js/tables-studio.js).
 *
 * GET                       — every table with its area (name, icon, colour) and live status
 *                             (busy with since/bill, reserved, free, off), plus the areas
 * POST JSON { action: 'save', id?, number, seats, area_id, available }
 *           { action: 'bulk', ids: [..], move_to?, seats?, available? }
 *           { action: 'delete', ids: [..] }   — tables with an open kitchen ticket are kept
 *
 * Scoped to the session's restaurant; needs the "manage tables" permission.
 * A table's QR code holds its name: renaming it means reprinting that QR code.
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../db_connection.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
requirePermission(PERMISSION_MANAGE_TABLES);
$rid = $_SESSION['restaurant_id'] ?? null;
session_write_close();

function tsOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if (!$rid) tsOut(['success' => false, 'message' => 'Please log in'], 401);

try {
    $conn = getConnection();
    foreach (['icon' => 'VARCHAR(30) NULL', 'color' => 'VARCHAR(7) NULL', 'note' => 'VARCHAR(120) NULL'] as $col => $def) {
        $q = $conn->query("SHOW COLUMNS FROM areas LIKE '$col'");
        if (!($q && $q->fetch())) $conn->exec("ALTER TABLE areas ADD COLUMN `$col` $def");
    }
    // the restaurant's own day and clock (today's sales, reservations, busy timers)
    require_once __DIR__ . '/../config/timezone_utils.php';
    $tzq = $conn->prepare('SELECT timezone FROM users WHERE restaurant_id = ? LIMIT 1');
    $tzq->execute([$rid]);
    applyRestaurantTimezone($tzq->fetchColumn() ?: 'Asia/Kolkata', $conn);
    // busy: an unpaid open order or a ticket still in the kitchen (config/table_busy.php)
    require_once __DIR__ . '/../config/table_busy.php';
    $busyIds = function () use ($conn, $rid) {
        $out = [];
        foreach (rgBusyTables($conn, (string)$rid) as $tid => $b) $out[(int)$tid] = ['since' => $b['since'], 'total' => (float)$b['total']];
        return $out;
    };
    $ownArea = function ($id) use ($conn, $rid) { $q = $conn->prepare('SELECT id FROM areas WHERE id = ? AND restaurant_id = ?'); $q->execute([(int)$id, $rid]); return (bool)$q->fetchColumn(); };
    $ownIds = function (array $ids) use ($conn, $rid) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $st = $conn->prepare('SELECT id FROM tables WHERE restaurant_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute(array_merge([$rid], $ids));
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    };

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $a = $conn->prepare('SELECT id, area_name, icon, color, sort_order FROM areas WHERE restaurant_id = ? ORDER BY sort_order ASC, id ASC');
        $a->execute([$rid]);
        $areas = $a->fetchAll(PDO::FETCH_ASSOC);
        $t = $conn->prepare('SELECT id, area_id, table_number, capacity, is_available, created_at FROM tables WHERE restaurant_id = ? ORDER BY sort_order ASC, id ASC');
        $t->execute([$rid]);
        $busy = $busyIds();
        $res = [];
        try {
            $rv = $conn->prepare("SELECT table_id, customer_name, time_slot FROM reservations WHERE restaurant_id = ? AND status = 'Confirmed' AND reservation_date = CURDATE() AND table_id IS NOT NULL");
            $rv->execute([$rid]);
            foreach ($rv->fetchAll(PDO::FETCH_ASSOC) as $r) $res[(int)$r['table_id']] = ['name' => $r['customer_name'], 'slot' => $r['time_slot']];
        } catch (PDOException $e) {}
        $sales = [];
        $s = $conn->prepare("SELECT table_id, COUNT(*) orders, COALESCE(SUM(total), 0) revenue FROM orders WHERE restaurant_id = ? AND table_id IS NOT NULL AND created_at >= CURDATE()
            AND deleted_at IS NULL AND order_status NOT IN ('Cancelled', 'Rejected') GROUP BY table_id");
        $s->execute([$rid]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $sales[(int)$r['table_id']] = ['orders' => (int)$r['orders'], 'revenue' => (float)$r['revenue']];
        $cur = $conn->prepare('SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1');
        $cur->execute([$rid]);
        tsOut(['success' => true, 'currency' => (string)($cur->fetchColumn() ?: '₹'), 'now' => date('Y-m-d H:i:s'),
            'areas' => array_map(function ($r) { return ['id' => (int)$r['id'], 'name' => $r['area_name'], 'icon' => $r['icon'] ?: null, 'color' => $r['color'] ?: null]; }, $areas),
            'tables' => array_map(function ($r) use ($busy, $res, $sales) {
                $id = (int)$r['id'];
                $st = isset($busy[$id]) ? 'busy' : (isset($res[$id]) ? 'reserved' : ((int)$r['is_available'] ? 'free' : 'off'));
                return ['id' => $id, 'area_id' => (int)$r['area_id'], 'number' => $r['table_number'], 'seats' => (int)$r['capacity'], 'available' => (int)$r['is_available'],
                        'status' => $st, 'since' => $busy[$id]['since'] ?? null, 'bill' => $busy[$id]['total'] ?? null, 'guest' => $res[$id]['name'] ?? null, 'slot' => $res[$id]['slot'] ?? null,
                        'today' => $sales[$id] ?? ['orders' => 0, 'revenue' => 0]];
            }, $t->fetchAll(PDO::FETCH_ASSOC))]);
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');
    // waiters and cashiers use the table map; adding, renaming and removing tables is for the owner and managers
    if (!in_array(getUserRole(), [ROLE_ADMIN, ROLE_MANAGER], true)) tsOut(['success' => false, 'message' => 'Only the owner or a manager can change tables.'], 403);

    if ($action === 'save') {
        $id = (int)($in['id'] ?? 0);
        $number = trim(preg_replace('/\s+/', ' ', mb_substr((string)($in['number'] ?? ''), 0, 50)));
        $seats = max(1, min(50, (int)($in['seats'] ?? 4)));
        $area = (int)($in['area_id'] ?? 0);
        if ($number === '') tsOut(['success' => false, 'message' => 'Give the table a name, e.g. T7'], 400);
        if (!$ownArea($area)) tsOut(['success' => false, 'message' => 'Pick an area'], 400);
        // names stay unique in the whole restaurant: the QR code is made from the name
        $dup = $conn->prepare('SELECT COUNT(*) FROM tables WHERE restaurant_id = ? AND table_number = ? AND id <> ?');
        $dup->execute([$rid, $number, $id]);
        if ((int)$dup->fetchColumn()) tsOut(['success' => false, 'message' => 'There is already a table called “' . $number . '”'], 400);
        $avail = empty($in['available']) ? 0 : 1;
        if ($id) {
            if (!$ownIds([$id])) tsOut(['success' => false, 'message' => 'Table not found'], 404);
            $old = $conn->prepare('SELECT table_number FROM tables WHERE id = ?');
            $old->execute([$id]);
            $renamed = (string)$old->fetchColumn() !== $number;
            $conn->prepare('UPDATE tables SET table_number = ?, capacity = ?, area_id = ?, is_available = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?')
                 ->execute([$number, $seats, $area, $avail, $id, $rid]);
            tsOut(['success' => true, 'id' => $id, 'renamed' => $renamed]);
        }
        $max = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM tables WHERE area_id = ?');
        $max->execute([$area]);
        $conn->prepare('INSERT INTO tables (restaurant_id, area_id, table_number, capacity, is_available, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())')
             ->execute([$rid, $area, $number, $seats, $avail, (int)$max->fetchColumn()]);
        tsOut(['success' => true, 'id' => (int)$conn->lastInsertId()]);
    }

    if ($action === 'bulk') {
        $ids = $ownIds((array)($in['ids'] ?? []));
        if (!$ids) tsOut(['success' => false, 'message' => 'Select some tables first'], 400);
        $set = []; $args = [];
        if (!empty($in['move_to'])) { if (!$ownArea($in['move_to'])) tsOut(['success' => false, 'message' => 'Area not found'], 400); $set[] = 'area_id = ?'; $args[] = (int)$in['move_to']; }
        if (!empty($in['seats'])) { $set[] = 'capacity = ?'; $args[] = max(1, min(50, (int)$in['seats'])); }
        if (isset($in['available']) && $in['available'] !== null && $in['available'] !== '') { $set[] = 'is_available = ?'; $args[] = empty($in['available']) ? 0 : 1; }
        if (!$set) tsOut(['success' => false, 'message' => 'Nothing to change'], 400);
        $conn->prepare('UPDATE tables SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE restaurant_id = ? AND id IN (' . implode(',', $ids) . ')')->execute(array_merge($args, [$rid]));
        tsOut(['success' => true, 'updated' => count($ids)]);
    }

    if ($action === 'delete') {
        $ids = $ownIds((array)($in['ids'] ?? []));
        if (!$ids) tsOut(['success' => false, 'message' => 'Select some tables first'], 400);
        $busy = $busyIds();
        $keep = array_values(array_filter($ids, function ($id) use ($busy) { return isset($busy[$id]); }));
        $go = array_values(array_diff($ids, $keep));
        if ($go) $conn->prepare('DELETE FROM tables WHERE restaurant_id = ? AND id IN (' . implode(',', $go) . ')')->execute([$rid]);
        tsOut(['success' => true, 'deleted' => count($go), 'kept_busy' => count($keep)]);
    }

    tsOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('tables_studio.php: ' . $e->getMessage());
    tsOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
