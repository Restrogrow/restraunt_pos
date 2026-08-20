<?php
/**
 * "Schedule for later" ordering: a customer places an order now but asks
 * for it to be prepared at a future time. The order is written to the
 * normal orders table immediately (so it shows up in admin/reporting right
 * away) but sits in order_status='Scheduled' — invisible to the kitchen/KOT
 * board and to notifications — until activateDueScheduledOrders() flips it
 * to 'Pending' at (or after) scheduled_at, at which point it "goes" through
 * the exact same flow a normal order does (KOT creation, push/email
 * notifications via fireOrderConfirmedActions()).
 */

require_once __DIR__ . '/order_confirmation.php';
require_once __DIR__ . '/order_state_machine.php';

if (!function_exists('ensureOrdersScheduleColumns')) {
    /**
     * Self-healing ALTER on the existing `orders` table, same lazy pattern as
     * ensureOrdersSubscriptionColumns() in subscription_delivery_generator.php.
     * Cheap no-op after the first call.
     */
    function ensureOrdersScheduleColumns($conn) {
        try {
            $col = $conn->query("SHOW COLUMNS FROM orders LIKE 'scheduled_at'");
            if ($col->rowCount() === 0) {
                $conn->exec("ALTER TABLE orders
                    ADD COLUMN scheduled_at DATETIME DEFAULT NULL,
                    ADD COLUMN is_scheduled TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (PDOException $e) {
            error_log('ensureOrdersScheduleColumns: ' . $e->getMessage());
        }

        try {
            // order_status is a plain ENUM on this DB - extend it with
            // 'Scheduled' rather than overloading an existing status, since
            // the whole point is the kitchen/KOT board must NOT see this
            // order until it's activated.
            $statusCol = $conn->query("SHOW COLUMNS FROM orders LIKE 'order_status'")->fetch(PDO::FETCH_ASSOC);
            if ($statusCol && stripos($statusCol['Type'], "'scheduled'") === false) {
                $conn->exec("ALTER TABLE orders MODIFY COLUMN order_status
                    ENUM('Scheduled','Pending','Accepted','Preparing','Ready','Served','Completed','Cancelled','Rejected')
                    NOT NULL DEFAULT 'Pending'");
            }
        } catch (PDOException $e) {
            error_log('ensureOrdersScheduleColumns (status enum): ' . $e->getMessage());
        }
    }
}

if (!function_exists('activateDueScheduledOrders')) {
    /**
     * Flips every order whose scheduled_at has arrived from 'Scheduled' to
     * 'Pending' and fires the same confirmation actions (KOT creation, push
     * notification, confirmation email) a normal order gets at placement
     * time. Cheap to call often — the WHERE clause is normally empty.
     *
     * Called from get_orders.php on every admin poll (so the feature works
     * out of the box with zero cron setup, matching this codebase's existing
     * "admin panel polling doubles as the trigger" pattern) and from
     * main/cron/activate_scheduled_orders.php for production reliability
     * when nobody has the admin panel open.
     *
     * @param PDO         $conn
     * @param string|null $restaurantId  Scope to one restaurant, or null for all.
     * @return int Number of orders activated.
     */
    function activateDueScheduledOrders($conn, $restaurantId = null) {
        ensureOrdersScheduleColumns($conn);

        try {
            $sql = "SELECT id, restaurant_id FROM orders
                    WHERE order_status = 'Scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()";
            $params = [];
            if ($restaurantId !== null) {
                $sql .= " AND restaurant_id = ?";
                $params[] = $restaurantId;
            }
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $dueOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('activateDueScheduledOrders: lookup failed: ' . $e->getMessage());
            return 0;
        }

        $activated = 0;
        foreach ($dueOrders as $row) {
            $orderId = (int)$row['id'];
            $orderRestaurantId = $row['restaurant_id'];
            try {
                $conn->beginTransaction();
                $result = validateAndUpdateOrderStatus(
                    $conn,
                    $orderId,
                    'Pending',
                    ['is_scheduled = ?'],
                    [0],
                    $orderRestaurantId
                );
                $conn->commit();

                if ($result['success']) {
                    $activated++;
                    try {
                        fireOrderConfirmedActions($conn, $orderId);
                    } catch (Exception $e) {
                        error_log('activateDueScheduledOrders: fireOrderConfirmedActions failed for order ' . $orderId . ': ' . $e->getMessage());
                    }
                }
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                error_log('activateDueScheduledOrders: failed to activate order ' . $orderId . ': ' . $e->getMessage());
            }
        }

        return $activated;
    }
}
