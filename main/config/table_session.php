<?php
/**
 * Table sessions: everyone at one table, one bill.
 *
 * The first guest who orders from a table's QR code opens a session for that table; every later website
 * order from that table (other phones in the group, "Order more") goes into the same session. Each order
 * stays its own order (own price checks, payment, KOT), with orders.table_session_id tying them together:
 *  - guests see the whole table on the tracking page (api/table_session.php: who ordered what, the total);
 *  - staff see and settle the table as one bill in Tables (api/table_bill.php, assets/js/tables-studio.js).
 *
 * A session closes by itself (tsCurrent, checked whenever it's read) when:
 *  - it has orders and none of them is still open and unpaid (the bill is settled), or
 *  - nothing is open and nothing happened for TS_IDLE_CLOSE seconds, or
 *  - it is older than a day (like rgBusyTables, so a forgotten bill can't hold a table for ever);
 * or when staff press Free table (tsClose). The next guest at that table then starts a new one.
 *
 * orders.client_ref: an id the guest's phone makes for each Place order. The same id sent again (the
 * connection dropped after the order was saved, the guest tapped again) returns the order already placed.
 */

if (!defined('TS_IDLE_CLOSE')) define('TS_IDLE_CLOSE', 3 * 3600);

// Tables and columns, made the first time they're needed. DDL ends a transaction: call before beginTransaction.
function tsEnsure(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS table_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(10) NOT NULL,
            table_id INT NOT NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            opened_at DATETIME NOT NULL,
            last_activity DATETIME NOT NULL,
            closed_at DATETIME NULL,
            closed_by VARCHAR(30) NULL,
            KEY ts_table (restaurant_id, table_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (['table_session_id' => 'INT NULL', 'client_ref' => 'VARCHAR(40) NULL'] as $col => $def) {
            $q = $conn->query("SHOW COLUMNS FROM orders LIKE '$col'");
            if (!($q && $q->fetch())) {
                $conn->exec("ALTER TABLE orders ADD COLUMN `$col` $def");
                $conn->exec("ALTER TABLE orders ADD INDEX idx_orders_$col (restaurant_id, `$col`)");
            }
        }
    } catch (Throwable $e) {
        error_log('tsEnsure: ' . $e->getMessage());
    }
}

// Orders of a session that still hold the table: open and not paid (same rule as rgBusyTables)
function tsOpenUnpaidCount(PDO $conn, int $sessionId): int
{
    $q = $conn->prepare("SELECT COUNT(*) FROM orders WHERE table_session_id = ? AND deleted_at IS NULL
        AND order_status IN ('Pending', 'Accepted', 'Preparing', 'Ready', 'Served', 'Scheduled')
        AND payment_status IN ('Pending', 'Partially Paid')");
    $q->execute([$sessionId]);
    return (int)$q->fetchColumn();
}

function tsClose(PDO $conn, int $sessionId, string $by): void
{
    $conn->prepare("UPDATE table_sessions SET status = 'closed', closed_at = NOW(), closed_by = ? WHERE id = ? AND status = 'open'")
        ->execute([mb_substr($by, 0, 30), $sessionId]);
}

// The open session of a table, after closing it if it's finished. $create: open one when there's none.
function tsCurrent(PDO $conn, string $rid, int $tableId, bool $create = false): ?array
{
    if ($tableId <= 0) return null;
    $q = $conn->prepare("SELECT * FROM table_sessions WHERE restaurant_id = ? AND table_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
    $q->execute([$rid, $tableId]);
    $s = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($s) {
        $open = tsOpenUnpaidCount($conn, (int)$s['id']);
        $hasOrders = $conn->prepare('SELECT COUNT(*) FROM orders WHERE table_session_id = ?');
        $hasOrders->execute([(int)$s['id']]);
        $n = (int)$hasOrders->fetchColumn();
        $idle = time() - strtotime((string)$s['last_activity']);
        $age = time() - strtotime((string)$s['opened_at']);
        $why = null;
        if ($n > 0 && $open === 0) $why = 'settled';
        elseif ($open === 0 && $idle > TS_IDLE_CLOSE) $why = 'idle';
        elseif ($age > 86400) $why = 'expired';
        if ($why) { tsClose($conn, (int)$s['id'], $why); $s = null; }
    }
    if (!$s && $create) {
        $conn->prepare("INSERT INTO table_sessions (restaurant_id, table_id, status, opened_at, last_activity) VALUES (?, ?, 'open', NOW(), NOW())")
            ->execute([$rid, $tableId]);
        $q->execute([$rid, $tableId]);
        $s = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return $s;
}

function tsTouch(PDO $conn, int $sessionId): void
{
    $conn->prepare('UPDATE table_sessions SET last_activity = NOW() WHERE id = ?')->execute([$sessionId]);
}

// A table by its number (the QR code holds the name, e.g. "T4"), for this restaurant
function tsTableByNumber(PDO $conn, string $rid, string $number): ?array
{
    if ($number === '') return null;
    $q = $conn->prepare('SELECT t.id, t.table_number, a.area_name FROM tables t LEFT JOIN areas a ON a.id = t.area_id
        WHERE t.restaurant_id = ? AND LOWER(t.table_number) = LOWER(?) LIMIT 1');
    $q->execute([$rid, $number]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * The table's bill: every order of the session (cancelled / rejected ones left out) with its dishes.
 * $forGuests: first names only and no phone numbers.
 */
function tsBill(PDO $conn, int $sessionId, bool $forGuests = false): array
{
    $o = $conn->prepare("SELECT id FROM orders WHERE table_session_id = ? AND deleted_at IS NULL AND order_status NOT IN ('Cancelled', 'Rejected') ORDER BY id ASC");
    $o->execute([$sessionId]);
    return tsBillOfOrders($conn, array_map('intval', $o->fetchAll(PDO::FETCH_COLUMN)), $forGuests);
}

// The same bill for any orders (a table with no session: api/table_bill.php)
function tsBillOfOrders(PDO $conn, array $orderIds, bool $forGuests = false): array
{
    $orders = [];
    if ($orderIds) {
        $o = $conn->prepare("SELECT id, order_number, customer_name, customer_phone, order_status, payment_status, payment_method,
                subtotal, tax, total, COALESCE(discount_amount, 0) discount, created_at
            FROM orders WHERE id IN (" . implode(',', array_fill(0, count($orderIds), '?')) . ") ORDER BY id ASC");
        $o->execute(array_values($orderIds));
        $orders = $o->fetchAll(PDO::FETCH_ASSOC);
    }
    $items = [];
    if ($orders) {
        $ids = array_map(function ($r) { return (int)$r['id']; }, $orders);
        $i = $conn->prepare('SELECT order_id, item_name, variation_name, quantity, unit_price, total_price FROM order_items WHERE order_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id ASC');
        $i->execute($ids);
        foreach ($i->fetchAll(PDO::FETCH_ASSOC) as $r) $items[(int)$r['order_id']][] = $r;
    }
    $sum = ['subtotal' => 0.0, 'tax' => 0.0, 'discount' => 0.0, 'total' => 0.0, 'paid' => 0.0, 'due' => 0.0];
    $out = [];
    foreach ($orders as $r) {
        $paid = in_array($r['payment_status'], ['Paid', 'Refunded'], true);
        foreach (['subtotal', 'tax', 'discount', 'total'] as $k) $sum[$k] += (float)$r[$k];
        $sum[$paid ? 'paid' : 'due'] += (float)$r['total'];
        $name = trim((string)$r['customer_name']);
        if ($forGuests) $name = $name !== '' ? preg_split('/\s+/', $name)[0] : 'Guest';
        $row = [
            'id' => (int)$r['id'], 'number' => $r['order_number'], 'name' => $name !== '' ? $name : 'Guest',
            'status' => $r['order_status'], 'paid' => $paid, 'payment_method' => $r['payment_method'],
            'subtotal' => (float)$r['subtotal'], 'tax' => (float)$r['tax'], 'discount' => (float)$r['discount'], 'total' => (float)$r['total'],
            'at' => $r['created_at'],
            'items' => array_map(function ($it) {
                return ['name' => $it['item_name'] . (!empty($it['variation_name']) && $it['variation_name'] !== 'Combo' ? ' (' . $it['variation_name'] . ')' : ''),
                        'qty' => (int)$it['quantity'], 'rate' => (float)$it['unit_price'], 'amount' => (float)$it['total_price']];
            }, $items[(int)$r['id']] ?? []),
        ];
        if (!$forGuests) $row['phone'] = $r['customer_phone'];
        $out[] = $row;
    }
    foreach ($sum as $k => $v) $sum[$k] = round($v, 2);
    return ['orders' => $out, 'sum' => $sum];
}
