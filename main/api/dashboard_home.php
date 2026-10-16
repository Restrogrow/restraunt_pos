<?php
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin(); // the companion app's dev web preview (logged-in requests)
/**
 * Dashboard home: everything the first screen shows, in one call.
 *
 * Sales and order counts use the same rules as get_dashboard_stats.php and the Sales
 * Report: not cancelled / rejected / deleted, QR payments only once paid, held POS
 * orders not counted. "vs yesterday" compares with yesterday up to this time of day.
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
if (!hasPermission(PERMISSION_VIEW_DASHBOARD)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No access to the dashboard.']);
    exit;
}
$rid = $_SESSION['restaurant_id'];
session_write_close();
require_once __DIR__ . '/../db_connection.php';

function dhTry(callable $f, $fallback) { try { return $f(); } catch (Throwable $e) { error_log('dashboard_home: ' . $e->getMessage()); return $fallback; } }

try {
    $conn = getConnection();
    $q = function (string $sql, array $a = []) use ($conn) { $st = $conn->prepare($sql); $st->execute($a); return $st; };
    $SALE = "restaurant_id = ? AND deleted_at IS NULL AND order_status NOT IN ('Cancelled', 'Rejected')
             AND NOT (payment_method = 'QR Payment' AND payment_status != 'Paid')
             AND NOT (COALESCE(notes, '') = 'Order on hold' AND order_status = 'Pending' AND payment_status = 'Pending')";
    $SALE_O = preg_replace('/\b(restaurant_id|deleted_at|order_status|payment_method|payment_status|notes)\b/', 'o.$1', $SALE);   // same rule, for joins

    $u = $q('SELECT restaurant_name, currency_symbol, opening_hours, timezone FROM users WHERE restaurant_id = ? LIMIT 1', [$rid])->fetch(PDO::FETCH_ASSOC) ?: [];
    $cur = (string)($u['currency_symbol'] ?? '') ?: '₹';

    // today vs yesterday (to this time)
    $today = $q("SELECT COALESCE(SUM(total), 0) s, COUNT(*) n FROM orders WHERE $SALE AND created_at >= CURDATE()", [$rid])->fetch(PDO::FETCH_ASSOC);
    $yday = $q("SELECT COALESCE(SUM(total), 0) s, COUNT(*) n FROM orders WHERE $SALE AND created_at >= CURDATE() - INTERVAL 1 DAY AND created_at < NOW() - INTERVAL 1 DAY", [$rid])->fetch(PDO::FETCH_ASSOC);

    // last 14 days, for the chart (7 shown, previous 7 for the total's comparison)
    $days = [];
    for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $days[$d] = ['date' => $d, 'sales' => 0.0, 'orders' => 0]; }
    foreach ($q("SELECT DATE(created_at) d, COALESCE(SUM(total), 0) s, COUNT(*) n FROM orders WHERE $SALE AND created_at >= CURDATE() - INTERVAL 13 DAY GROUP BY DATE(created_at)", [$rid])->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($days[$r['d']])) { $days[$r['d']]['sales'] = (float)$r['s']; $days[$r['d']]['orders'] = (int)$r['n']; }
    }
    $days = array_values($days);

    // orders in progress right now
    $live = $q("SELECT o.id, o.order_number, o.order_type, o.order_status, o.total, o.customer_name, o.created_at, o.source, t.table_number
        FROM orders o LEFT JOIN tables t ON t.id = o.table_id
        WHERE o.restaurant_id = ? AND o.deleted_at IS NULL AND o.order_status IN ('Pending', 'Accepted', 'Preparing', 'Ready')
          AND o.created_at >= NOW() - INTERVAL 2 DAY AND NOT (COALESCE(o.notes, '') = 'Order on hold' AND o.order_status = 'Pending' AND o.payment_status = 'Pending')
        ORDER BY FIELD(o.order_status, 'Ready', 'Pending', 'Accepted', 'Preparing'), o.created_at DESC LIMIT 8", [$rid])->fetchAll(PDO::FETCH_ASSOC);
    $liveCount = (int)$q("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND deleted_at IS NULL AND order_status IN ('Pending', 'Accepted', 'Preparing', 'Ready') AND created_at >= NOW() - INTERVAL 2 DAY
        AND NOT (COALESCE(notes, '') = 'Order on hold' AND order_status = 'Pending' AND payment_status = 'Pending')", [$rid])->fetchColumn();

    // things waiting for someone
    $toAccept = (int)dhTry(function () use ($q, $rid) { return $q("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND source = 'website' AND order_status = 'Pending' AND deleted_at IS NULL AND (payment_method NOT IN ('PhonePe', 'UPI / NetBanking') OR payment_status = 'Paid')", [$rid])->fetchColumn(); }, 0);
    $calls = (int)dhTry(function () use ($q, $rid) { return $q("SELECT COUNT(*) FROM waiter_requests WHERE restaurant_id = ? AND status = 'Pending' AND created_at >= NOW() - INTERVAL 1 DAY", [$rid])->fetchColumn(); }, 0);
    $resv = dhTry(function () use ($q, $rid) { return $q("SELECT customer_name, time_slot, no_of_guests, status FROM reservations WHERE restaurant_id = ? AND reservation_date = CURDATE() AND status NOT IN ('Cancelled', 'Completed', 'Rejected') ORDER BY time_slot LIMIT 5", [$rid])->fetchAll(PDO::FETCH_ASSOC); }, []);
    $resvPending = count(array_filter($resv, function ($r) { return $r['status'] === 'Pending'; }));
    $catering = (int)dhTry(function () use ($q, $rid) { return $q("SELECT COUNT(*) FROM catering_requests WHERE restaurant_id = ? AND status = 'new'", [$rid])->fetchColumn(); }, 0);
    // stock items at or below their reorder level (after taking out what recent orders used)
    $lowNames = [];
    $lowStock = (int)dhTry(function () use ($q, $rid, $conn, &$lowNames) {
        if (!$q("SHOW TABLES LIKE 'inventory_settings'")->fetchColumn()) return 0;
        require_once __DIR__ . '/../config/inventory_engine.php';
        invRefresh($conn, $rid, 100);
        $rows = $q("SELECT item_name FROM inventory_items WHERE restaurant_id = ? AND is_active = 1 AND (quantity_in_stock <= 0 OR (low_stock_threshold > 0 AND quantity_in_stock <= low_stock_threshold))
            ORDER BY quantity_in_stock <= 0 DESC, quantity_in_stock / NULLIF(low_stock_threshold, 0)", [$rid])->fetchAll(PDO::FETCH_COLUMN);
        $lowNames = array_slice($rows, 0, 3);
        return count($rows);
    }, 0);
    $kot = (int)dhTry(function () use ($q, $rid) { return $q("SELECT COUNT(*) FROM kot WHERE restaurant_id = ? AND kot_status IN ('Pending', 'Preparing')", [$rid])->fetchColumn(); }, 0);
    $staleKot = (int)dhTry(function () use ($q, $rid) { return $q("SELECT COUNT(*) FROM kot WHERE restaurant_id = ? AND kot_status IN ('Pending', 'Preparing') AND created_at < NOW() - INTERVAL 6 HOUR", [$rid])->fetchColumn(); }, 0);

    // tables: busy = an open kitchen ticket (same rule as Tables)
    $tables = dhTry(function () use ($q, $rid) { return $q("SELECT COUNT(*) total, SUM(EXISTS (SELECT 1 FROM kot k WHERE k.table_id = t.id AND k.restaurant_id = t.restaurant_id AND k.kot_status IN ('Pending', 'Preparing'))) busy FROM tables t WHERE t.restaurant_id = ?", [$rid])->fetch(PDO::FETCH_ASSOC); }, ['total' => 0, 'busy' => 0]);

    // best sellers today
    $top = $q("SELECT oi.item_name name, SUM(oi.quantity) qty, COALESCE(SUM(oi.total_price), 0) sales FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE $SALE_O AND o.created_at >= CURDATE() GROUP BY oi.item_name ORDER BY qty DESC LIMIT 5", [$rid])->fetchAll(PDO::FETCH_ASSOC);

    // open now? (Settings › Business hours)
    $open = null; $hoursToday = null;
    $oh = json_decode((string)($u['opening_hours'] ?? ''), true);
    if (is_array($oh)) {
        $tz = new DateTimeZone(@timezone_open((string)($u['timezone'] ?? '')) ? $u['timezone'] : 'Asia/Kolkata');
        $now = new DateTime('now', $tz);
        $d = $oh[strtolower($now->format('l'))] ?? null;
        if ($d) {
            if (empty($d['open'])) { $open = false; $hoursToday = 'Closed today'; }
            else {
                $a = DateTime::createFromFormat('h:i A', $d['opening'] ?? '', $tz); $b = DateTime::createFromFormat('h:i A', $d['closing'] ?? '', $tz);
                if ($a && $b) {
                    $a->setDate((int)$now->format('Y'), (int)$now->format('m'), (int)$now->format('d'));
                    $b->setDate((int)$now->format('Y'), (int)$now->format('m'), (int)$now->format('d'));
                    if ($b <= $a) $b->modify('+1 day');   // closes after midnight
                    $open = $now >= $a && $now < $b;
                    $hoursToday = $a->format('g:i A') . ' – ' . $b->format('g:i A');
                }
            }
        }
    }

    $n = (int)$today['n'];
    echo json_encode(['success' => true, 'currency' => $cur, 'restaurant' => (string)($u['restaurant_name'] ?? ''), 'now' => date('c'),
        'today' => ['sales' => (float)$today['s'], 'orders' => $n, 'avg' => $n ? (float)$today['s'] / $n : 0],
        'yesterday' => ['sales' => (float)$yday['s'], 'orders' => (int)$yday['n'], 'avg' => (int)$yday['n'] ? (float)$yday['s'] / (int)$yday['n'] : 0],
        'days' => $days, 'kot' => $kot, 'stale_kot' => $staleKot,
        'live' => array_map(function ($o) { return ['id' => (int)$o['id'], 'number' => $o['order_number'], 'type' => $o['order_type'], 'status' => $o['order_status'], 'total' => (float)$o['total'],
            'customer' => (string)$o['customer_name'], 'table' => $o['table_number'], 'source' => $o['source'], 'at' => $o['created_at']]; }, $live),
        'live_count' => $liveCount,
        'attention' => ['to_accept' => $toAccept, 'calls' => $calls, 'reservations_pending' => $resvPending, 'catering' => $catering, 'stale_kot' => $staleKot, 'low_stock' => $lowStock, 'low_names' => $lowNames],
        'reservations' => array_map(function ($r) { return ['name' => $r['customer_name'], 'time' => $r['time_slot'], 'guests' => (int)$r['no_of_guests'], 'status' => $r['status']]; }, $resv),
        'tables' => ['total' => (int)$tables['total'], 'busy' => (int)$tables['busy']],
        'top' => array_map(function ($t) { return ['name' => $t['name'], 'qty' => (int)$t['qty'], 'sales' => (float)$t['sales']]; }, $top),
        'store' => ['open' => $open, 'hours' => $hoursToday],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('dashboard_home.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load the dashboard. Please refresh.']);
}
