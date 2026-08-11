<?php
/**
 * Turns each active meal-plan subscription's day into a real row in the
 * existing orders/order_items tables (source='subscription'), so kitchen
 * sees it on the normal Orders/KOT screen for free - no separate
 * fulfillment system to build or for staff to learn.
 *
 * Called from two places: main/controllers/meal_subscription_admin_operations.php
 * (manual "Generate Today's Deliveries" button, one restaurant) and
 * main/cron/generate_subscription_deliveries.php (all restaurants, real
 * server cron). Both call the same function so there's exactly one place
 * this logic lives.
 */

require_once __DIR__ . '/order_confirmation.php';

if (!function_exists('ensureOrdersSubscriptionColumns')) {
    /**
     * Self-healing ALTER on the existing `orders` table - adds the 3 nullable
     * columns a subscription-generated order needs (subscription_id,
     * delivery_date, meal_time) plus a unique key so cron and the manual
     * admin button can never double-generate the same day's delivery even if
     * both fire close together. Cheap no-op after the first call (checks
     * SHOW COLUMNS before altering).
     */
    function ensureOrdersSubscriptionColumns($conn) {
        try {
            $col = $conn->query("SHOW COLUMNS FROM orders LIKE 'subscription_id'");
            if ($col->rowCount() === 0) {
                $conn->exec("ALTER TABLE orders
                    ADD COLUMN subscription_id INT DEFAULT NULL,
                    ADD COLUMN delivery_date DATE DEFAULT NULL,
                    ADD COLUMN meal_time ENUM('lunch','dinner') DEFAULT NULL,
                    ADD UNIQUE KEY uq_orders_subscription_delivery (subscription_id, delivery_date, meal_time)");
            }
        } catch (PDOException $e) {
            error_log('ensureOrdersSubscriptionColumns: ' . $e->getMessage());
        }

        try {
            // orders.source is a plain ENUM('pos','website') on this DB - not
            // discovered until a live insert failed with a truncation error
            // during testing. Extend it rather than reusing 'website' for
            // subscription-generated orders, since source is meant to
            // distinguish exactly this kind of thing for reporting/filtering.
            $sourceCol = $conn->query("SHOW COLUMNS FROM orders LIKE 'source'")->fetch(PDO::FETCH_ASSOC);
            if ($sourceCol && stripos($sourceCol['Type'], "'subscription'") === false) {
                $conn->exec("ALTER TABLE orders MODIFY COLUMN source ENUM('pos','website','subscription') NOT NULL DEFAULT 'pos'");
            }
        } catch (PDOException $e) {
            error_log('ensureOrdersSubscriptionColumns (source enum): ' . $e->getMessage());
        }
    }
}

if (!function_exists('generateSubscriptionOrderNumber')) {
    // Same shape as process_website_order.php's generateOrderNumber(), kept
    // as its own copy since that file's version is declared inside a request
    // this generator doesn't otherwise load.
    function generateSubscriptionOrderNumber($conn, $restaurant_id) {
        $maxAttempts = 20;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $orderNumber = 'SUB-' . date('Ymd') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $checkStmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE order_number = ? AND restaurant_id = ?");
            $checkStmt->execute([$orderNumber, $restaurant_id]);
            if ((int)$checkStmt->fetchColumn() === 0) {
                return $orderNumber;
            }
        }
        return 'SUB-' . date('YmdHis') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('generateSubscriptionDeliveriesForDate')) {
    /**
     * Generates every due subscription delivery for one restaurant on one
     * date. Returns a summary array: generated / skipped_paused /
     * skipped_date / skipped_no_menu / skipped_already_generated / errors.
     */
    function generateSubscriptionDeliveriesForDate($conn, $restaurant_id, $dateStr) {
        ensureOrdersSubscriptionColumns($conn);

        $summary = ['generated' => 0, 'skipped_date' => 0, 'skipped_no_menu' => 0, 'skipped_already_generated' => 0, 'errors' => 0];

        $date = DateTime::createFromFormat('Y-m-d', $dateStr);
        if (!$date) {
            $summary['errors']++;
            return $summary;
        }
        // 0=Sunday..6=Saturday, matching meal_plan_weekly_menu.day_of_week
        $dayOfWeek = (int)$date->format('w');

        $menuStmt = $conn->prepare("SELECT meal_time, menu_text FROM meal_plan_weekly_menu WHERE restaurant_id = ? AND day_of_week = ? AND is_active = 1");
        $menuStmt->execute([$restaurant_id, $dayOfWeek]);
        $menuByMealTime = [];
        foreach ($menuStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $menuByMealTime[$row['meal_time']] = $row['menu_text'];
        }

        $subStmt = $conn->prepare("
            SELECT s.*, c.customer_name
            FROM customer_meal_subscriptions s
            JOIN customers c ON c.id = s.customer_id
            WHERE s.restaurant_id = ? AND s.status = 'active' AND s.credits_used < s.credits_total
        ");
        $subStmt->execute([$restaurant_id]);
        $subscriptions = $subStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($subscriptions as $sub) {
            try {
                $skipStmt = $conn->prepare("SELECT 1 FROM meal_subscription_skip_dates WHERE subscription_id = ? AND skip_date = ? LIMIT 1");
                $skipStmt->execute([$sub['id'], $dateStr]);
                if ($skipStmt->fetch()) {
                    $summary['skipped_date']++;
                    continue;
                }

                $existStmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE subscription_id = ? AND delivery_date = ?");
                $existStmt->execute([$sub['id'], $dateStr]);
                if ((int)$existStmt->fetchColumn() > 0) {
                    $summary['skipped_already_generated']++;
                    continue;
                }

                $scope = $sub['meal_scope_snapshot'];
                $mealTimes = $scope === 'both' ? ['lunch', 'dinner'] : [$scope];
                $mealTimes = array_values(array_intersect($mealTimes, array_keys($menuByMealTime)));
                if (empty($mealTimes)) {
                    $summary['skipped_no_menu']++;
                    continue;
                }

                $conn->beginTransaction();

                foreach ($mealTimes as $mealTime) {
                    $orderNumber = generateSubscriptionOrderNumber($conn, $restaurant_id);
                    $orderStmt = $conn->prepare("INSERT INTO orders
                        (restaurant_id, order_number, customer_name, customer_phone, customer_address, order_type, payment_method, payment_status, order_status, subtotal, tax, total, source, subscription_id, delivery_date, meal_time)
                        VALUES (?, ?, ?, ?, ?, 'Delivery', 'Meal Plan Credit', 'Paid', 'Pending', 0, 0, 0, 'subscription', ?, ?, ?)");
                    $orderStmt->execute([
                        $restaurant_id, $orderNumber, $sub['customer_name'], $sub['delivery_phone'], $sub['delivery_address'],
                        $sub['id'], $dateStr, $mealTime
                    ]);
                    $orderId = (int)$conn->lastInsertId();

                    $itemStmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, unit_price, total_price) VALUES (?, NULL, ?, 1, 0, 0)");
                    $itemStmt->execute([$orderId, $menuByMealTime[$mealTime]]);

                    // Fires KOT/push/email exactly like a normal confirmed
                    // order - called inside the same transaction as the order
                    // insert is fine here (unlike process_website_order.php,
                    // which deliberately calls it AFTER commit to let the
                    // customer's HTTP response return first - there is no
                    // waiting customer here, this runs from cron/admin).
                    try {
                        fireOrderConfirmedActions($conn, $orderId);
                    } catch (Exception $e) {
                        error_log('generateSubscriptionDeliveriesForDate: fireOrderConfirmedActions failed for order ' . $orderId . ': ' . $e->getMessage());
                    }
                }

                // Confirmed decision: 1 credit per day regardless of how many
                // meal-times were generated (a "both" plan's total credits
                // already represents total delivery-days, not meal count).
                $conn->prepare("UPDATE customer_meal_subscriptions SET credits_used = credits_used + 1 WHERE id = ?")->execute([$sub['id']]);
                $conn->prepare("UPDATE customer_meal_subscriptions SET status = 'completed' WHERE id = ? AND credits_used >= credits_total")->execute([$sub['id']]);

                $conn->commit();
                $summary['generated']++;
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                error_log('generateSubscriptionDeliveriesForDate: failed for subscription ' . $sub['id'] . ': ' . $e->getMessage());
                $summary['errors']++;
            }
        }

        return $summary;
    }
}
