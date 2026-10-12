<?php
/**
 * Tables › Areas (assets/js/areas-studio.js).
 *
 * GET                       — areas with their look, tables with live status (busy / reserved / free),
 *                             seats, and today's sales per area
 * POST JSON { action: 'save', id?, name, icon, color, note }
 *           { action: 'delete', id, move_to? }     — tables move to move_to first (required if it has tables)
 *           { action: 'reorder', ids: [..] }
 *           { action: 'add_tables', id, count, capacity, prefix }   — e.g. 6 tables "R5".."R10"
 *           { action: 'quick', names: [..] }        — create several areas at once (empty-state ideas)
 *
 * Scoped to the session's restaurant; needs the "manage areas" permission
 * (adding tables also needs "manage tables").
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../db_connection.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
requirePermission(PERMISSION_MANAGE_AREAS);
$rid = $_SESSION['restaurant_id'] ?? null;
$canTables = function_exists('hasPermission') ? hasPermission(PERMISSION_MANAGE_TABLES) : true;
session_write_close();

function asOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if (!$rid) asOut(['success' => false, 'message' => 'Please log in'], 401);

const AS_ICONS = ['restaurant', 'deck', 'roofing', 'local_bar', 'ac_unit', 'park', 'meeting_room', 'celebration', 'weekend', 'family_restroom', 'coffee', 'stairs', 'wine_bar', 'storefront', 'pool', 'nightlife'];

try {
    $conn = getConnection();
    // the look of each area (added with this page)
    foreach (['icon' => 'VARCHAR(30) NULL', 'color' => 'VARCHAR(7) NULL', 'note' => 'VARCHAR(120) NULL'] as $col => $def) {
        $q = $conn->query("SHOW COLUMNS FROM areas LIKE '$col'");
        if (!($q && $q->fetch())) $conn->exec("ALTER TABLE areas ADD COLUMN `$col` $def");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $a = $conn->prepare('SELECT id, area_name, icon, color, note, sort_order, created_at FROM areas WHERE restaurant_id = ? ORDER BY sort_order ASC, id ASC');
        $a->execute([$rid]);
        $areas = $a->fetchAll(PDO::FETCH_ASSOC);
        $t = $conn->prepare('SELECT id, area_id, table_number, capacity, is_available FROM tables WHERE restaurant_id = ? ORDER BY sort_order ASC, id ASC');
        $t->execute([$rid]);
        $tables = $t->fetchAll(PDO::FETCH_ASSOC);
        // live status, same rules as the table map (get_table_status.php)
        $busy = [];
        $k = $conn->prepare("SELECT table_id, MIN(created_at) since, SUM(total) total FROM kot WHERE restaurant_id = ? AND kot_status IN ('Pending', 'Preparing') AND table_id IS NOT NULL GROUP BY table_id");
        $k->execute([$rid]);
        foreach ($k->fetchAll(PDO::FETCH_ASSOC) as $r) $busy[(int)$r['table_id']] = ['since' => $r['since'], 'total' => (float)$r['total']];
        $res = [];
        try {
            $rv = $conn->prepare("SELECT table_id, customer_name, time_slot FROM reservations WHERE restaurant_id = ? AND status = 'Confirmed' AND reservation_date = CURDATE() AND table_id IS NOT NULL");
            $rv->execute([$rid]);
            foreach ($rv->fetchAll(PDO::FETCH_ASSOC) as $r) $res[(int)$r['table_id']] = ['name' => $r['customer_name'], 'slot' => $r['time_slot']];
        } catch (PDOException $e) {}
        $sales = [];
        $s = $conn->prepare("SELECT t.area_id, COUNT(o.id) orders, COALESCE(SUM(o.total), 0) revenue FROM orders o JOIN tables t ON t.id = o.table_id
            WHERE o.restaurant_id = ? AND o.created_at >= CURDATE() AND o.deleted_at IS NULL AND o.order_status NOT IN ('Cancelled', 'Rejected') GROUP BY t.area_id");
        $s->execute([$rid]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $sales[(int)$r['area_id']] = ['orders' => (int)$r['orders'], 'revenue' => (float)$r['revenue']];
        $cur = $conn->prepare('SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1');
        $cur->execute([$rid]);
        $byArea = [];
        foreach ($tables as $tb) {
            $id = (int)$tb['id'];
            $st = isset($busy[$id]) ? 'busy' : (isset($res[$id]) ? 'reserved' : ((int)$tb['is_available'] ? 'free' : 'off'));
            $byArea[(int)$tb['area_id']][] = ['id' => $id, 'number' => $tb['table_number'], 'seats' => (int)$tb['capacity'], 'status' => $st,
                'since' => $busy[$id]['since'] ?? null, 'bill' => $busy[$id]['total'] ?? null, 'guest' => $res[$id]['name'] ?? null, 'slot' => $res[$id]['slot'] ?? null];
        }
        asOut(['success' => true, 'currency' => (string)($cur->fetchColumn() ?: '₹'), 'can_tables' => (bool)$canTables, 'icons' => AS_ICONS, 'now' => date('Y-m-d H:i:s'),
            'areas' => array_map(function ($r) use ($byArea, $sales) {
                $id = (int)$r['id'];
                return ['id' => $id, 'name' => $r['area_name'], 'icon' => $r['icon'] ?: null, 'color' => $r['color'] ?: null, 'note' => $r['note'] ?: '',
                        'tables' => $byArea[$id] ?? [], 'sales' => $sales[$id] ?? ['orders' => 0, 'revenue' => 0]];
            }, $areas),
            'unassigned' => count(array_filter($tables, function ($tb) use ($areas) { return !in_array($tb['area_id'], array_column($areas, 'id')); }))]);
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');
    $own = function ($id) use ($conn, $rid) { $q = $conn->prepare('SELECT id FROM areas WHERE id = ? AND restaurant_id = ?'); $q->execute([(int)$id, $rid]); return (bool)$q->fetchColumn(); };
    $clean = function ($in) {
        $name = trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')));
        $icon = in_array($in['icon'] ?? '', AS_ICONS, true) ? $in['icon'] : null;
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? strtolower($in['color']) : null;
        $note = trim(mb_substr((string)($in['note'] ?? ''), 0, 120));
        return [mb_substr($name, 0, 100), $icon, $color, $note];
    };

    if ($action === 'save') {
        [$name, $icon, $color, $note] = $clean($in);
        if ($name === '') asOut(['success' => false, 'message' => 'Give the area a name'], 400);
        $id = (int)($in['id'] ?? 0);
        $dup = $conn->prepare('SELECT COUNT(*) FROM areas WHERE restaurant_id = ? AND area_name = ? AND id <> ?');
        $dup->execute([$rid, $name, $id]);
        if ((int)$dup->fetchColumn()) asOut(['success' => false, 'message' => 'You already have an area called “' . $name . '”'], 400);
        if ($id) {
            if (!$own($id)) asOut(['success' => false, 'message' => 'Area not found'], 404);
            $conn->prepare('UPDATE areas SET area_name = ?, icon = ?, color = ?, note = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?')->execute([$name, $icon, $color, $note ?: null, $id, $rid]);
        } else {
            $max = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM areas WHERE restaurant_id = ?');
            $max->execute([$rid]);
            $conn->prepare('INSERT INTO areas (restaurant_id, area_name, icon, color, note, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())')
                 ->execute([$rid, $name, $icon, $color, $note ?: null, (int)$max->fetchColumn()]);
            $id = (int)$conn->lastInsertId();
        }
        asOut(['success' => true, 'id' => $id]);
    }

    if ($action === 'quick') {
        $names = array_slice(array_values(array_filter(array_map(function ($n) { return trim(mb_substr((string)$n, 0, 100)); }, (array)($in['names'] ?? [])))), 0, 8);
        $styles = (array)($in['styles'] ?? []);
        $made = 0;
        foreach ($names as $i => $n) {
            $dup = $conn->prepare('SELECT COUNT(*) FROM areas WHERE restaurant_id = ? AND area_name = ?');
            $dup->execute([$rid, $n]);
            if ((int)$dup->fetchColumn()) continue;
            [, $icon, $color] = $clean(['name' => $n, 'icon' => $styles[$i]['icon'] ?? '', 'color' => $styles[$i]['color'] ?? '']);
            $max = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM areas WHERE restaurant_id = ?');
            $max->execute([$rid]);
            $conn->prepare('INSERT INTO areas (restaurant_id, area_name, icon, color, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())')->execute([$rid, $n, $icon, $color, (int)$max->fetchColumn()]);
            $made++;
        }
        asOut(['success' => true, 'created' => $made]);
    }

    if ($action === 'reorder') {
        $ids = array_values(array_map('intval', (array)($in['ids'] ?? [])));
        $u = $conn->prepare('UPDATE areas SET sort_order = ? WHERE id = ? AND restaurant_id = ?');
        foreach ($ids as $i => $id) $u->execute([$i + 1, $id, $rid]);
        asOut(['success' => true]);
    }

    if ($action === 'delete') {
        $id = (int)($in['id'] ?? 0);
        if (!$own($id)) asOut(['success' => false, 'message' => 'Area not found'], 404);
        $n = $conn->prepare('SELECT COUNT(*) FROM tables WHERE area_id = ? AND restaurant_id = ?');
        $n->execute([$id, $rid]);
        $count = (int)$n->fetchColumn();
        $moveTo = (int)($in['move_to'] ?? 0);
        if ($count > 0) {
            if (!$moveTo || $moveTo === $id || !$own($moveTo)) asOut(['success' => false, 'message' => 'Choose where this area’s ' . $count . ' table' . ($count > 1 ? 's go' : ' goes') . ' first'], 400);
            // a table number already used in the destination gets the old area's initial in front
            $clash = $conn->prepare('SELECT t.id, t.table_number FROM tables t WHERE t.area_id = ? AND t.restaurant_id = ? AND t.table_number IN (SELECT table_number FROM (SELECT table_number FROM tables WHERE area_id = ? AND restaurant_id = ?) x)');
            $clash->execute([$id, $rid, $moveTo, $rid]);
            $an = $conn->prepare('SELECT area_name FROM areas WHERE id = ?');
            $an->execute([$id]);
            $prefix = strtoupper(mb_substr((string)$an->fetchColumn(), 0, 1)) ?: 'X';
            foreach ($clash->fetchAll(PDO::FETCH_ASSOC) as $c) $conn->prepare('UPDATE tables SET table_number = ? WHERE id = ?')->execute([$prefix . '-' . $c['table_number'], $c['id']]);
            $conn->prepare('UPDATE tables SET area_id = ?, updated_at = NOW() WHERE area_id = ? AND restaurant_id = ?')->execute([$moveTo, $id, $rid]);
        }
        $conn->prepare('DELETE FROM areas WHERE id = ? AND restaurant_id = ?')->execute([$id, $rid]);
        asOut(['success' => true, 'moved' => $count]);
    }

    if ($action === 'add_tables') {
        if (!$canTables) asOut(['success' => false, 'message' => 'You don’t have permission to add tables'], 403);
        $id = (int)($in['id'] ?? 0);
        if (!$own($id)) asOut(['success' => false, 'message' => 'Area not found'], 404);
        $count = max(1, min(30, (int)($in['count'] ?? 1)));
        $cap = max(1, min(30, (int)($in['capacity'] ?? 4)));
        $prefix = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($in['prefix'] ?? ''))) ;
        $prefix = substr($prefix, 0, 3) ?: 'T';
        // next free numbers after the highest PREFIXn used anywhere in the restaurant (so QR codes stay unique)
        $ex = $conn->prepare('SELECT table_number FROM tables WHERE restaurant_id = ?');
        $ex->execute([$rid]);
        $used = array_map('strtoupper', $ex->fetchAll(PDO::FETCH_COLUMN));
        $n = 0;
        foreach ($used as $u) if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $u, $m)) $n = max($n, (int)$m[1]);
        $max = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM tables WHERE area_id = ?');
        $max->execute([$id]);
        $sort = (int)$max->fetchColumn();
        $ins = $conn->prepare('INSERT INTO tables (restaurant_id, area_id, table_number, capacity, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
        $made = [];
        for ($i = 0; $i < $count; $i++) {
            do { $n++; $num = $prefix . $n; } while (in_array($num, $used, true));
            $used[] = $num;
            $ins->execute([$rid, $id, $num, $cap, ++$sort]);
            $made[] = $num;
        }
        asOut(['success' => true, 'created' => $made]);
    }

    asOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('areas_studio.php: ' . $e->getMessage());
    asOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
