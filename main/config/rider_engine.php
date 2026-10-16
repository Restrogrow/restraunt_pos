<?php
/**
 * Delivery riders (plan: docs/RIDER_PLAN.md).
 *
 * A rider is a staff member with role "Delivery". The owner or a manager assigns a delivery order to a
 * rider (or, if the restaurant allows it, riders pick open deliveries themselves). The rider moves it
 * Picked up → On the way → Delivered, collects cash on delivery, and shares their live location, which
 * the customer's tracking page shows. Cash collected is settled with the restaurant ("Settle cash").
 *
 * Everything is stored in delivery_tracking (one row per delivery order), which the older "QR for rider"
 * link also uses; riderAdvance() is the one place the delivery steps happen, for both.
 */
require_once __DIR__ . '/order_state_machine.php';

if (!function_exists('riderEnsure')) {

    define('RIDER_ROLE', 'Delivery');

    /** Tables and columns this needs; safe to call often (once per request does the work). Not inside a transaction. */
    function riderEnsure(PDO $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $cols = "id INT AUTO_INCREMENT PRIMARY KEY, restaurant_id VARCHAR(10) NOT NULL, order_id INT NOT NULL, delivery_zone_id INT NULL,
            delivery_address TEXT NULL, delivery_notes TEXT NULL,
            delivery_status ENUM('Pending','Preparing','Assigned','Picked_Up','In_Transit','Delivered') DEFAULT 'Pending',
            qr_token VARCHAR(100) NULL UNIQUE, qr_expires_at DATETIME NULL, rider_name VARCHAR(100) NULL, rider_phone VARCHAR(20) NULL,
            rider_vehicle VARCHAR(50) NULL, current_lat DECIMAL(10,8) NULL, current_lng DECIMAL(11,8) NULL, location_updated_at DATETIME NULL,
            picked_up_at DATETIME NULL, in_transit_at DATETIME NULL, delivered_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_restaurant_id (restaurant_id), INDEX idx_order_id (order_id), INDEX idx_delivery_status (delivery_status)";
        try { $conn->query('SELECT id FROM delivery_tracking LIMIT 1'); }
        catch (PDOException $e) { $conn->exec("CREATE TABLE IF NOT EXISTS delivery_tracking ($cols) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); }

        $have = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_tracking'")->fetchAll(PDO::FETCH_COLUMN);
        $add = [
            'rider_staff_id' => 'INT NULL', 'assigned_at' => 'DATETIME NULL', 'cod_amount' => 'DECIMAL(10,2) NULL',
            'cod_collected_at' => 'DATETIME NULL', 'cash_settled_at' => 'DATETIME NULL', 'cash_settled_by' => 'VARCHAR(100) NULL',
        ];
        foreach ($add as $c => $def) {
            if (!in_array($c, $have, true)) {
                try { $conn->exec("ALTER TABLE delivery_tracking ADD COLUMN $c $def"); } catch (PDOException $e) { error_log("riderEnsure $c: " . $e->getMessage()); }
            }
        }
        if (!in_array('rider_staff_id', $have, true)) {
            try { $conn->exec('ALTER TABLE delivery_tracking ADD INDEX idx_rider (rider_staff_id)'); } catch (PDOException $e) { /* already there */ }
        }
        // staff.role gets "Delivery" (and "Cashier")
        require_once __DIR__ . '/staff_roles.php';
        staffRolesEnsure($conn);
        $conn->exec("CREATE TABLE IF NOT EXISTS rider_settings (
            restaurant_id VARCHAR(10) PRIMARY KEY,
            self_pick TINYINT NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    function riderSettings(PDO $conn, string $rid): array
    {
        $st = $conn->prepare('SELECT self_pick FROM rider_settings WHERE restaurant_id = ?');
        $st->execute([$rid]);
        $v = $st->fetchColumn();
        return ['self_pick' => $v === false ? true : (int)$v === 1];
    }

    function riderIsSession(): bool
    {
        return ($_SESSION['user_type'] ?? '') === 'staff' && strcasecmp((string)($_SESSION['role'] ?? ''), RIDER_ROLE) === 0;
    }

    /** Owner, branch admin or manager: may assign riders and settle cash. */
    function riderCanDispatch(): bool
    {
        $t = $_SESSION['user_type'] ?? '';
        return $t === 'admin' || $t === 'branch_admin' || ($t === 'staff' && in_array($_SESSION['role'] ?? '', ['Manager', 'Admin', 'Cashier'], true));
    }

    /** The restaurant's active riders with what they have on now. */
    function riderList(PDO $conn, string $rid): array
    {
        $st = $conn->prepare("SELECT s.id, s.member_name AS name, s.phone,
                (SELECT COUNT(*) FROM delivery_tracking d JOIN orders o ON o.id = d.order_id
                  WHERE d.rider_staff_id = s.id AND d.delivery_status IN ('Assigned', 'Picked_Up', 'In_Transit') AND o.order_status NOT IN ('Cancelled', 'Rejected', 'Completed')) AS active,
                (SELECT COUNT(*) FROM delivery_tracking d WHERE d.rider_staff_id = s.id AND d.delivery_status = 'Delivered' AND d.delivered_at >= CURDATE()) AS delivered_today,
                (SELECT COALESCE(SUM(d.cod_amount), 0) FROM delivery_tracking d WHERE d.rider_staff_id = s.id AND d.cod_collected_at IS NOT NULL AND d.cash_settled_at IS NULL) AS cash_in_hand,
                (SELECT MAX(d.location_updated_at) FROM delivery_tracking d WHERE d.rider_staff_id = s.id) AS last_seen
            FROM staff s WHERE s.restaurant_id = ? AND s.role = 'Delivery' AND s.is_active = 1 ORDER BY s.member_name");
        $st->execute([$rid]);
        return array_map(function ($r) {
            $r['id'] = (int)$r['id']; $r['active'] = (int)$r['active']; $r['delivered_today'] = (int)$r['delivered_today'];
            $r['cash_in_hand'] = round((float)$r['cash_in_hand'], 2);
            return $r;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Cash still to collect for an order: unpaid cash-on-delivery orders. */
    function riderCodDue(array $o): float
    {
        $m = strtolower((string)($o['payment_method'] ?? ''));
        $isCash = $m === '' || $m === 'cash' || strpos($m, 'cod') !== false || strpos($m, 'cash') !== false;
        return ($isCash && ($o['payment_status'] ?? '') !== 'Paid') ? round((float)$o['total'] - (float)($o['wallet_paid'] ?? 0), 2) : 0.0;
    }

    /** Delivery orders for a restaurant, a rider (their own), or open ones nobody has. */
    function riderDeliveries(PDO $conn, string $rid, array $f = []): array
    {
        $w = ["o.restaurant_id = ?", "o.order_type = 'Delivery'", 'o.deleted_at IS NULL'];
        $p = [$rid];
        if (!empty($f['rider'])) { $w[] = 'd.rider_staff_id = ?'; $p[] = (int)$f['rider']; }
        if (!empty($f['order'])) { $w[] = 'o.id = ?'; $p[] = (int)$f['order']; }
        if (!empty($f['open'])) {
            $w[] = 'd.rider_staff_id IS NULL'; $w[] = "COALESCE(d.delivery_status, 'Pending') IN ('Pending', 'Preparing', 'Assigned')";
            $w[] = "o.order_status IN ('Accepted', 'Preparing', 'Ready')"; $w[] = 'd.qr_token IS NULL';
        }
        if (!empty($f['active'])) {
            $w[] = "o.order_status NOT IN ('Cancelled', 'Rejected', 'Completed', 'Scheduled')";
            $w[] = "COALESCE(d.delivery_status, 'Pending') <> 'Delivered'";
            $w[] = "(o.order_status <> 'Pending' OR o.source <> 'website')";
            $w[] = 'o.created_at >= NOW() - INTERVAL 2 DAY';
        }
        if (!empty($f['done_today'])) { $w[] = "d.delivery_status = 'Delivered'"; $w[] = 'd.delivered_at >= CURDATE()'; }
        $st = $conn->prepare("SELECT o.id, o.order_number, o.order_status, o.payment_method, o.payment_status, o.total, o.wallet_paid, o.customer_name, o.customer_phone,
                o.customer_address, o.delivery_address, o.landmark, o.address_lat, o.address_lng, o.notes, o.created_at, o.estimated_ready_at,
                d.id AS tracking_id, COALESCE(d.delivery_status, 'Pending') AS delivery_status, d.rider_staff_id, d.rider_name, d.rider_phone, d.assigned_at,
                d.picked_up_at, d.in_transit_at, d.delivered_at, d.cod_amount, d.cod_collected_at, d.cash_settled_at, d.qr_token IS NOT NULL AS via_qr,
                (SELECT GROUP_CONCAT(CONCAT(oi.quantity, '× ', oi.item_name) ORDER BY oi.id SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id) AS items
            FROM orders o LEFT JOIN delivery_tracking d ON d.order_id = o.id
            WHERE " . implode(' AND ', $w) . " ORDER BY FIELD(COALESCE(d.delivery_status, 'Pending'), 'In_Transit', 'Picked_Up', 'Assigned', 'Preparing', 'Pending', 'Delivered'), o.created_at" . (!empty($f['done_today']) ? ' DESC' : '') . " LIMIT 100");
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['rider_staff_id'] = $r['rider_staff_id'] !== null ? (int)$r['rider_staff_id'] : null;
            $r['address'] = trim((string)($r['delivery_address'] ?: $r['customer_address']));
            $r['cod_due'] = $r['cod_collected_at'] ? 0 : riderCodDue($r);
            $r['maps_url'] = ($r['address_lat'] && $r['address_lng'])
                ? 'https://www.google.com/maps/dir/?api=1&destination=' . $r['address_lat'] . ',' . $r['address_lng']
                : ($r['address'] !== '' ? 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($r['address']) : null);
            $r['via_qr'] = (bool)$r['via_qr'];
            unset($r['delivery_address'], $r['customer_address']);
        }
        unset($r);
        return $rows;
    }

    /** The tracking row of an order (made if missing). Call inside a transaction; locks the row. */
    function riderTrackingRow(PDO $conn, string $rid, int $orderId): array
    {
        $st = $conn->prepare('SELECT * FROM delivery_tracking WHERE order_id = ? AND restaurant_id = ? FOR UPDATE');
        $st->execute([$orderId, $rid]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if ($t) return $t;
        $conn->prepare("INSERT INTO delivery_tracking (restaurant_id, order_id, delivery_status) VALUES (?, ?, 'Pending')")->execute([$rid, $orderId]);
        $st->execute([$orderId, $rid]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /** Give a delivery order to a rider ($staffId 0 = take it back). Returns the message. */
    function riderAssign(PDO $conn, string $rid, int $orderId, int $staffId): string
    {
        riderEnsure($conn);
        $conn->beginTransaction();
        try {
            $o = $conn->prepare("SELECT id, order_type, order_status, order_number FROM orders WHERE id = ? AND restaurant_id = ? AND deleted_at IS NULL FOR UPDATE");
            $o->execute([$orderId, $rid]);
            $order = $o->fetch(PDO::FETCH_ASSOC);
            if (!$order) throw new Exception('Order not found');
            if ($order['order_type'] !== 'Delivery') throw new Exception('Only delivery orders get a rider');
            if (in_array($order['order_status'], ['Cancelled', 'Rejected', 'Completed'], true)) throw new Exception('This order is already ' . strtolower($order['order_status']));
            $t = riderTrackingRow($conn, $rid, $orderId);
            if ($t['delivery_status'] === 'Delivered') throw new Exception('This order is already delivered');
            if ($staffId === 0) {
                if (in_array($t['delivery_status'], ['Picked_Up', 'In_Transit'], true)) throw new Exception('The rider already has the food. Assign another rider instead.');
                $conn->prepare("UPDATE delivery_tracking SET rider_staff_id = NULL, rider_name = NULL, rider_phone = NULL, assigned_at = NULL, delivery_status = 'Pending' WHERE id = ?")->execute([$t['id']]);
                $conn->commit();
                return 'Rider removed from ' . $order['order_number'];
            }
            $s = $conn->prepare("SELECT id, member_name, phone FROM staff WHERE id = ? AND restaurant_id = ? AND role = 'Delivery' AND is_active = 1");
            $s->execute([$staffId, $rid]);
            $rider = $s->fetch(PDO::FETCH_ASSOC);
            if (!$rider) throw new Exception('That rider was not found');
            $status = in_array($t['delivery_status'], ['Picked_Up', 'In_Transit'], true) ? $t['delivery_status'] : 'Assigned';
            $conn->prepare('UPDATE delivery_tracking SET rider_staff_id = ?, rider_name = ?, rider_phone = ?, assigned_at = NOW(), delivery_status = ?, qr_token = NULL, qr_expires_at = NULL WHERE id = ?')
                ->execute([$rider['id'], $rider['member_name'], $rider['phone'], $status, $t['id']]);
            $conn->commit();
            return $order['order_number'] . ' given to ' . $rider['member_name'];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /**
     * The delivery steps: Picked_Up → In_Transit → Delivered (a rider may jump straight to Delivered).
     * $t: the delivery_tracking row. $codCollected: the rider took the cash (Delivered only).
     * Delivered also completes the order and, for cash on delivery, marks it paid. Returns the message.
     */
    function riderAdvance(PDO $conn, array $t, string $status, bool $codCollected = false): string
    {
        if (!in_array($status, ['Picked_Up', 'In_Transit', 'Delivered'], true)) throw new Exception('Unknown step');
        $from = (string)$t['delivery_status'];
        $next = ['Pending' => ['Picked_Up'], 'Preparing' => ['Picked_Up'], 'Assigned' => ['Picked_Up'], 'Picked_Up' => ['In_Transit', 'Delivered'], 'In_Transit' => ['Delivered']];
        if (!in_array($status, $next[$from] ?? [], true)) {
            if ($from === $status) return 'Already done';
            throw new Exception('This delivery is ' . str_replace('_', ' ', strtolower($from)) . ', so it can\'t be marked ' . str_replace('_', ' ', strtolower($status)));
        }
        $started = !$conn->inTransaction();
        if ($started) $conn->beginTransaction();
        try {
            $os = $conn->prepare('SELECT order_status, payment_method, payment_status, total, wallet_paid FROM orders WHERE id = ? FOR UPDATE');
            $os->execute([$t['order_id']]);
            $order = $os->fetch(PDO::FETCH_ASSOC);
            if (!$order) throw new Exception('Order not found');
            if (in_array($order['order_status'], ['Cancelled', 'Rejected'], true)) throw new Exception('This order was ' . strtolower($order['order_status']) . '. Bring the food back to the restaurant.');
            $set = ['delivery_status = ?'];
            $par = [$status];
            if ($status === 'Picked_Up') $set[] = 'picked_up_at = NOW()';
            if ($status === 'In_Transit') $set[] = 'in_transit_at = NOW()';
            if ($status === 'Delivered') {
                $set[] = 'delivered_at = NOW()';
                if ($from === 'Picked_Up') $set[] = 'in_transit_at = COALESCE(in_transit_at, NOW())';
                $due = riderCodDue($order);
                if ($due > 0) {
                    if (!$codCollected) throw new Exception('Collect ' . number_format($due, 2) . ' in cash first, then mark it delivered.');
                    $set[] = 'cod_amount = ?'; $par[] = $due; $set[] = 'cod_collected_at = NOW()';
                }
            }
            $par[] = $t['id'];
            $conn->prepare('UPDATE delivery_tracking SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($par);

            if ($status === 'Delivered') {
                if (riderCodDue($order) > 0) {
                    $r = validateAndUpdatePaymentStatus($conn, (int)$t['order_id'], 'Paid');
                    if (empty($r['success'])) throw new Exception($r['message'] ?? 'Could not mark the order paid');
                }
                $path = ['Served' => ['Completed'], 'Ready' => ['Served', 'Completed'], 'Preparing' => ['Ready', 'Served', 'Completed'],
                    'Accepted' => ['Preparing', 'Ready', 'Served', 'Completed'], 'Pending' => ['Accepted', 'Preparing', 'Ready', 'Served', 'Completed']][$order['order_status']] ?? [];
                foreach ($path as $target) {
                    $r = validateAndUpdateOrderStatus($conn, (int)$t['order_id'], $target);
                    if (empty($r['success'])) throw new Exception($r['message'] ?? 'Could not complete the order');
                }
            }
            if ($started) $conn->commit();
        } catch (Throwable $e) {
            if ($started && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        // customer: "on the way" email (the tracking page and WhatsApp follow the status by themselves)
        if ($status === 'Picked_Up') {
            try {
                require_once __DIR__ . '/mail_engine.php';
                if (function_exists('mailOrderOnTheWay')) mailOrderOnTheWay($conn, (int)$t['order_id']);
            } catch (Throwable $e) { error_log('rider on-the-way email: ' . $e->getMessage()); }
        }
        return ['Picked_Up' => 'Picked up. Have a safe ride!', 'In_Transit' => 'On the way', 'Delivered' => 'Delivered. Well done!'][$status];
    }

    /** A rider takes an open delivery themselves (when the restaurant allows it). */
    function riderClaim(PDO $conn, string $rid, int $orderId, int $staffId): string
    {
        if (!riderSettings($conn, $rid)['self_pick']) throw new Exception('Your manager assigns the deliveries here.');
        $open = array_filter(riderDeliveries($conn, $rid, ['open' => true, 'active' => true]), fn($d) => $d['id'] === $orderId);
        if (!$open) throw new Exception('Someone already took this delivery.');
        riderAssign($conn, $rid, $orderId, $staffId);
        return 'You took ' . reset($open)['order_number'] . '. Pick it up at the restaurant.';
    }

    /** The rider's live location goes on all their deliveries that are under way. */
    function riderLocation(PDO $conn, string $rid, int $staffId, float $lat, float $lng): int
    {
        if (abs($lat) < 0.0001 || abs($lng) < 0.0001 || abs($lat) > 90 || abs($lng) > 180) return 0;
        $st = $conn->prepare("UPDATE delivery_tracking SET current_lat = ?, current_lng = ?, location_updated_at = NOW()
            WHERE restaurant_id = ? AND rider_staff_id = ? AND delivery_status IN ('Assigned', 'Picked_Up', 'In_Transit')");
        $st->execute([$lat, $lng, $rid, $staffId]);
        return $st->rowCount();
    }

    /** The restaurant got the cash a rider collected. Returns [count, amount]. */
    function riderSettle(PDO $conn, string $rid, int $staffId, string $by): array
    {
        $st = $conn->prepare('SELECT COUNT(*), COALESCE(SUM(cod_amount), 0) FROM delivery_tracking WHERE restaurant_id = ? AND rider_staff_id = ? AND cod_collected_at IS NOT NULL AND cash_settled_at IS NULL');
        $st->execute([$rid, $staffId]);
        [$n, $sum] = $st->fetch(PDO::FETCH_NUM);
        $conn->prepare('UPDATE delivery_tracking SET cash_settled_at = NOW(), cash_settled_by = ? WHERE restaurant_id = ? AND rider_staff_id = ? AND cod_collected_at IS NOT NULL AND cash_settled_at IS NULL')
            ->execute([mb_substr($by, 0, 100), $rid, $staffId]);
        return [(int)$n, round((float)$sum, 2)];
    }
}
