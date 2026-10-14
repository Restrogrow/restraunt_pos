<?php
/**
 * Payments page (dashboard › Payments › Payments, assets/js/payments-studio.js).
 *
 *   GET ?range=today|7|30|90|custom&from=Y-m-d&to=Y-m-d&search=&method=&status=
 *     totals for the range (collected, transactions, average, pending, refunded), the same against the
 *     period before, money by method, what's still to collect on unpaid / part-paid bills, collected per
 *     day, and the transactions (newest first, up to 500) with their order.
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
session_write_close();
require_once __DIR__ . '/../db_connection.php';

function poOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $conn = getConnection();
    $q = function (string $sql, array $a = []) use ($conn) { $st = $conn->prepare($sql); $st->execute($a); return $st; };

    // the range: today, the last N days, or two dates
    $range = $_GET['range'] ?? '7';
    $today = new DateTime('today');
    if ($range === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')) {
        $from = new DateTime($_GET['from']); $to = new DateTime($_GET['to']);
        if ($to < $from) [$from, $to] = [$to, $from];
        if ($from->diff($to)->days > 366) $from = (clone $to)->modify('-366 days');
    } else {
        $days = $range === 'today' ? 1 : max(1, min(366, (int)$range));
        $to = clone $today; $from = (clone $today)->modify('-' . ($days - 1) . ' days');
        $range = $range === 'today' ? 'today' : (string)$days;
    }
    $span = $from->diff($to)->days + 1;
    $pFrom = (clone $from)->modify("-$span days"); $pTo = (clone $from)->modify('-1 day');
    $a = $from->format('Y-m-d 00:00:00'); $b = $to->format('Y-m-d 23:59:59');

    $sum = function (string $s, string $e) use ($q, $rid) {
        $r = $q("SELECT COALESCE(SUM(CASE WHEN payment_status = 'Success' THEN amount END), 0) AS collected, SUM(payment_status = 'Success') AS n,
                COALESCE(SUM(CASE WHEN payment_status = 'Pending' THEN amount END), 0) AS pending, COALESCE(SUM(CASE WHEN payment_status = 'Refunded' THEN amount END), 0) AS refunded
            FROM payments WHERE restaurant_id = ? AND created_at BETWEEN ? AND ?", [$rid, $s, $e])->fetch(PDO::FETCH_ASSOC);
        return ['collected' => round((float)$r['collected'], 2), 'count' => (int)$r['n'], 'pending' => round((float)$r['pending'], 2), 'refunded' => round((float)$r['refunded'], 2)];
    };
    $now = $sum($a, $b);
    $prev = $sum($pFrom->format('Y-m-d 00:00:00'), $pTo->format('Y-m-d 23:59:59'));
    $now['average'] = $now['count'] ? round($now['collected'] / $now['count'], 2) : 0;

    // money by method (successful payments)
    $methods = [];
    foreach ($q("SELECT payment_method AS method, SUM(amount) AS amount, COUNT(*) AS n FROM payments WHERE restaurant_id = ? AND payment_status = 'Success' AND created_at BETWEEN ? AND ?
        GROUP BY payment_method ORDER BY SUM(amount) DESC", [$rid, $a, $b])->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $methods[] = ['method' => $m['method'] ?: 'Other', 'amount' => round((float)$m['amount'], 2), 'count' => (int)$m['n']];
    }

    // still to collect: bills in the range not fully paid yet (open POS bills, dues, part payments)
    $due = $q("SELECT COUNT(*) AS n, COALESCE(SUM(GREATEST(o.total - COALESCE(p.paid, 0), 0)), 0) AS amount
        FROM orders o LEFT JOIN (SELECT order_id, SUM(amount) AS paid FROM payments WHERE restaurant_id = ? AND payment_status = 'Success' GROUP BY order_id) p ON p.order_id = o.id
        WHERE o.restaurant_id = ? AND o.payment_status IN ('Pending', 'Partially Paid') AND o.order_status NOT IN ('Cancelled', 'Rejected')
        AND (o.deleted_at IS NULL) AND o.created_at BETWEEN ? AND ?", [$rid, $rid, $a, $b])->fetch(PDO::FETCH_ASSOC);

    // collected per day
    $series = [];
    for ($d = clone $from; $d <= $to; $d->modify('+1 day')) $series[$d->format('Y-m-d')] = 0;
    foreach ($q("SELECT DATE(created_at) AS d, SUM(amount) AS amount FROM payments WHERE restaurant_id = ? AND payment_status = 'Success' AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at)", [$rid, $a, $b])->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $series[$r['d']] = round((float)$r['amount'], 2);
    }

    // the transactions, with the page's search and filters
    $where = 'p.restaurant_id = ? AND p.created_at BETWEEN ? AND ?';
    $args = [$rid, $a, $b];
    $method = trim((string)($_GET['method'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $search = trim((string)($_GET['search'] ?? ''));
    if ($method !== '') { $where .= ' AND p.payment_method = ?'; $args[] = $method; }
    if ($status !== '') { $where .= ' AND p.payment_status = ?'; $args[] = $status; }
    if ($search !== '') {
        $where .= ' AND (o.order_number LIKE ? OR p.transaction_id LIKE ? OR o.customer_name LIKE ? OR CAST(p.amount AS CHAR) LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
        array_push($args, $like, $like, $like, $like);
    }
    // bill_no exists once the first numbered bill was printed (config/bill_numbers.php)
    $billCol = $q("SHOW COLUMNS FROM orders LIKE 'bill_no'")->fetch() ? 'o.bill_no' : 'NULL AS bill_no';
    $rows = $q("SELECT p.id, p.order_id, p.amount, p.payment_method, p.payment_status, p.transaction_id, p.reference_number, p.created_at,
            o.order_number, o.order_type, o.customer_name, $billCol
        FROM payments p LEFT JOIN orders o ON o.id = p.order_id AND o.restaurant_id = p.restaurant_id
        WHERE $where ORDER BY p.created_at DESC, p.id DESC LIMIT 500", $args)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['amount'] = round((float)$r['amount'], 2); }
    unset($r);

    $cur = (string)($q('SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1', [$rid])->fetchColumn() ?: '₹');
    poOut(['success' => true, 'currency' => $cur, 'range' => ['key' => $range, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'days' => $span],
        'totals' => $now, 'previous' => $prev, 'methods' => $methods, 'due' => ['count' => (int)$due['n'], 'amount' => round((float)$due['amount'], 2)],
        'series' => $series, 'payments' => $rows]);
} catch (Throwable $e) {
    error_log('payments_overview: ' . $e->getMessage());
    poOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
