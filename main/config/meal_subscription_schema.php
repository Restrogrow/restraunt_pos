<?php
/**
 * Self-healing schema for customer meal-plan subscriptions (the "tiffin
 * subscription" feature). Mirrors the CREATE TABLE IF NOT EXISTS convention
 * used throughout the app (see customer_session.php's ensureCustomerAuthSchema),
 * but shared as one function since three tables here are needed by several
 * entry points (controller, subscribe.php, my-subscription.php, admin screens)
 * rather than duplicated inline in each one.
 *
 * Naming: meal_plans / customer_meal_subscriptions / meal_plan_payments are
 * DELIBERATELY distinct from the existing users.subscription_status /
 * subscription_payments tables, which are this platform's own SaaS billing
 * to the restaurant tenant - a completely unrelated concept.
 */

if (!function_exists('mealSubscriptionsFeatureEnabled')) {
    /**
     * Whether a superadmin has turned the meal-subscriptions feature on for
     * this restaurant (users.meal_subscriptions_enabled, off by default).
     * Checked at both the UI layer (dashboard sidebar, customer pages) and
     * here at the data layer, so a direct URL/API hit can't bypass a hidden
     * nav item. Defaults to false (and never throws) if the column hasn't
     * been created yet on this DB - self-healed by main/superadmin/api.php.
     */
    function mealSubscriptionsFeatureEnabled($conn, $restaurant_id) {
        if (!$restaurant_id) return false;
        try {
            $stmt = $conn->prepare("SELECT meal_subscriptions_enabled FROM users WHERE restaurant_id = ? LIMIT 1");
            $stmt->execute([$restaurant_id]);
            return (int)($stmt->fetchColumn() ?: 0) === 1;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('mealPlanToday')) {
    /**
     * Today's date on the restaurant's own clock (users.timezone, Asia/Kolkata if unset):
     * which day's deliveries to make, the earliest start date and the earliest skip date.
     * $offsetDays = 1 gives tomorrow.
     */
    function mealPlanToday($conn, $restaurant_id, $offsetDays = 0) {
        $tz = 'Asia/Kolkata';
        try {
            $stmt = $conn->prepare("SELECT timezone FROM users WHERE restaurant_id = ? LIMIT 1");
            $stmt->execute([$restaurant_id]);
            $t = (string)$stmt->fetchColumn();
            if ($t !== '' && in_array($t, timezone_identifiers_list(), true)) $tz = $t;
        } catch (Exception $e) { /* no timezone column: India */ }
        $d = new DateTime('today', new DateTimeZone($tz));
        if ($offsetDays) $d->modify(($offsetDays > 0 ? '+' : '') . (int)$offsetDays . ' days');
        return $d->format('Y-m-d');
    }
}

if (!function_exists('ensureMealPlanTables')) {
    function ensureMealPlanTables($conn) {
        $conn->exec("CREATE TABLE IF NOT EXISTS meal_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            plan_name VARCHAR(150) NOT NULL,
            description TEXT DEFAULT NULL,
            meal_scope ENUM('lunch','dinner','both') NOT NULL DEFAULT 'both',
            total_meal_credits INT NOT NULL,
            price DECIMAL(10, 2) NOT NULL,
            bonus_credits INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_mp_restaurant (restaurant_id),
            INDEX idx_mp_active (restaurant_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Self-heal onto meal_plans tables created before the plan photo
        // feature existed - same convention as customer_meal_subscriptions'
        // delivery_lat/lng self-heal below.
        try {
            $col = $conn->query("SHOW COLUMNS FROM meal_plans LIKE 'image_data'");
            if ($col->rowCount() === 0) {
                $conn->exec("ALTER TABLE meal_plans
                    ADD COLUMN image_data LONGBLOB DEFAULT NULL,
                    ADD COLUMN image_mime_type VARCHAR(50) DEFAULT NULL");
            }
        } catch (PDOException $e) {
            error_log('ensureMealPlanTables (meal_plans image): ' . $e->getMessage());
        }

        $conn->exec("CREATE TABLE IF NOT EXISTS meal_plan_weekly_menu (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            day_of_week TINYINT NOT NULL,
            meal_time ENUM('lunch','dinner') NOT NULL,
            menu_text TEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mpwm_cell (restaurant_id, day_of_week, meal_time),
            INDEX idx_mpwm_restaurant (restaurant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Structured weekly-menu items (replaces the old free-text-only cell
        // with a per-day/meal-time list of named dishes, each with its own
        // optional photo) - menu_text above is kept as an optional freeform
        // note alongside the item list, not removed.
        $conn->exec("CREATE TABLE IF NOT EXISTS meal_plan_weekly_menu_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            day_of_week TINYINT NOT NULL,
            meal_time ENUM('lunch','dinner') NOT NULL,
            item_name VARCHAR(150) NOT NULL,
            image_data LONGBLOB DEFAULT NULL,
            image_mime_type VARCHAR(50) DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_mpwmi_cell (restaurant_id, day_of_week, meal_time),
            INDEX idx_mpwmi_restaurant (restaurant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('ensureMealSubscriptionTables')) {
    function ensureMealSubscriptionTables($conn) {
        ensureMealPlanTables($conn);

        $conn->exec("CREATE TABLE IF NOT EXISTS customer_meal_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            customer_id INT NOT NULL,
            meal_plan_id INT NOT NULL,
            plan_name_snapshot VARCHAR(150) NOT NULL,
            meal_scope_snapshot ENUM('lunch','dinner','both') NOT NULL,
            credits_total INT NOT NULL,
            credits_used INT NOT NULL DEFAULT 0,
            amount_paid DECIMAL(10, 2) NOT NULL,
            delivery_address TEXT DEFAULT NULL,
            delivery_phone VARCHAR(20) DEFAULT NULL,
            delivery_lat DECIMAL(10, 7) DEFAULT NULL,
            delivery_lng DECIMAL(10, 7) DEFAULT NULL,
            status ENUM('pending_payment','active','paused','completed','cancelled') NOT NULL DEFAULT 'pending_payment',
            paused_at DATETIME DEFAULT NULL,
            start_date DATE DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_cms_restaurant (restaurant_id),
            INDEX idx_cms_customer (customer_id),
            INDEX idx_cms_status (restaurant_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Self-heal onto tables created before delivery_lat/lng existed (this
        // feature already shipped once without them).
        try {
            $col = $conn->query("SHOW COLUMNS FROM customer_meal_subscriptions LIKE 'delivery_lat'");
            if ($col->rowCount() === 0) {
                $conn->exec("ALTER TABLE customer_meal_subscriptions
                    ADD COLUMN delivery_lat DECIMAL(10, 7) DEFAULT NULL,
                    ADD COLUMN delivery_lng DECIMAL(10, 7) DEFAULT NULL");
            }
        } catch (PDOException $e) {
            error_log('ensureMealSubscriptionTables (delivery_lat/lng): ' . $e->getMessage());
        }

        // Self-heal onto tables created before the customer-chosen start
        // date existed. NULL means "no start restriction" - deliveries
        // generate from day one, matching every subscription created before
        // this feature (and staff-created ones, which still don't collect it).
        try {
            $col = $conn->query("SHOW COLUMNS FROM customer_meal_subscriptions LIKE 'start_date'");
            if ($col->rowCount() === 0) {
                $conn->exec("ALTER TABLE customer_meal_subscriptions
                    ADD COLUMN start_date DATE DEFAULT NULL");
            }
        } catch (PDOException $e) {
            error_log('ensureMealSubscriptionTables (start_date): ' . $e->getMessage());
        }

        $conn->exec("CREATE TABLE IF NOT EXISTS meal_subscription_skip_dates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subscription_id INT NOT NULL,
            skip_date DATE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sub_skip_date (subscription_id, skip_date),
            INDEX idx_skip_subscription (subscription_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $conn->exec("CREATE TABLE IF NOT EXISTS meal_plan_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            subscription_id INT NOT NULL,
            transaction_id VARCHAR(100) DEFAULT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            payment_method VARCHAR(100) DEFAULT 'Cash',
            payment_status ENUM('Success','Failed','Pending','Refunded') DEFAULT 'Pending',
            payment_proof_image LONGBLOB DEFAULT NULL,
            payment_proof_mime VARCHAR(50) DEFAULT NULL,
            reference_number VARCHAR(100) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mpp_restaurant (restaurant_id),
            INDEX idx_mpp_subscription (subscription_id),
            INDEX idx_mpp_txn (transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

/*
 * Changes both sides make to a subscription (the customer on my-subscription.php, staff on
 * admin/meal_subscriptions.php), kept here so the two always follow the same rules, and the
 * details both lists show (next delivery, payment, recent deliveries) worked out the same way.
 */

if (!function_exists('mealSubAddSkip')) {
    function mealSubAddSkip($conn, array $sub, $skipDate) {
        $d = DateTime::createFromFormat('Y-m-d', (string)$skipDate);
        if (!$d || $d->format('Y-m-d') !== $skipDate) {
            throw new Exception('Invalid date');
        }
        if (!in_array($sub['status'], ['active', 'paused'], true)) {
            throw new Exception('Only an active or paused subscription can skip days');
        }
        if ($skipDate < mealPlanToday($conn, $sub['restaurant_id'])) {   // the restaurant's own today
            throw new Exception('Cannot skip a date in the past');
        }
        // that day's delivery already went to the kitchen (cron / "Generate Today's Deliveries"): too late to skip
        try {
            $made = $conn->prepare("SELECT 1 FROM orders WHERE subscription_id = ? AND delivery_date = ? LIMIT 1");
            $made->execute([(int)$sub['id'], $skipDate]);
            $alreadyMade = (bool)$made->fetchColumn();
        } catch (PDOException $e) {
            $alreadyMade = false;   // no subscription columns on orders yet: nothing generated
        }
        if ($alreadyMade) {
            throw new Exception("That day's meal is already being prepared, so it can't be skipped now.");
        }
        $conn->prepare("INSERT IGNORE INTO meal_subscription_skip_dates (subscription_id, skip_date) VALUES (?, ?)")
             ->execute([(int)$sub['id'], $skipDate]);
    }
}

if (!function_exists('mealSubRemoveSkip')) {
    function mealSubRemoveSkip($conn, array $sub, $skipDate) {
        $conn->prepare("DELETE FROM meal_subscription_skip_dates WHERE subscription_id = ? AND skip_date = ?")
             ->execute([(int)$sub['id'], (string)$skipDate]);
    }
}

if (!function_exists('mealSubUpdateDelivery')) {
    /**
     * New delivery address / phone. Today's delivery still waiting in the kitchen goes to the new
     * address too. A changed address drops the old map pin unless a new one comes with it.
     */
    function mealSubUpdateDelivery($conn, array $sub, $address, $phone, $lat = null, $lng = null) {
        $address = trim((string)$address);
        $phone = trim((string)$phone);
        if ($address === '') throw new Exception('Delivery address is required');
        if (!preg_match('/^\+?[0-9 \-]{7,20}$/', $phone)) throw new Exception('Please enter a valid phone number');
        if (in_array($sub['status'], ['completed', 'cancelled'], true)) {
            throw new Exception('This subscription has ended');
        }
        if ($lat === null && $address === trim((string)$sub['delivery_address'])) {
            $lat = $sub['delivery_lat'];   // only the phone changed: keep the pin
            $lng = $sub['delivery_lng'];
        }
        $conn->prepare("UPDATE customer_meal_subscriptions SET delivery_address = ?, delivery_phone = ?, delivery_lat = ?, delivery_lng = ? WHERE id = ?")
             ->execute([$address, $phone, $lat, $lng, (int)$sub['id']]);
        try {
            $conn->prepare("UPDATE orders SET customer_address = ?, customer_phone = ?
                WHERE subscription_id = ? AND delivery_date >= ? AND order_status = 'Pending'")
                 ->execute([$address, $phone, (int)$sub['id'], mealPlanToday($conn, $sub['restaurant_id'])]);
        } catch (PDOException $e) { /* no subscription columns on orders yet */ }
    }
}

if (!function_exists('mealSubAttachDetails')) {
    /**
     * Adds to each subscription row: credits_remaining, skip_dates (today on), payment_status and
     * payment_method (latest payment), next_delivery (Y-m-d or null) and deliveries (latest 10).
     */
    function mealSubAttachDetails($conn, $restaurant_id, array $subs) {
        if (!$subs) return $subs;
        $today = mealPlanToday($conn, $restaurant_id);
        $ids = array_map('intval', array_column($subs, 'id'));
        $in = implode(',', array_fill(0, count($ids), '?'));

        $skips = [];
        $st = $conn->prepare("SELECT subscription_id, skip_date FROM meal_subscription_skip_dates WHERE subscription_id IN ($in) AND skip_date >= ? ORDER BY skip_date ASC");
        $st->execute(array_merge($ids, [$today]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $skips[$r['subscription_id']][] = $r['skip_date'];

        $pay = [];
        $st = $conn->prepare("SELECT subscription_id, payment_status, payment_method FROM meal_plan_payments WHERE subscription_id IN ($in) ORDER BY id ASC");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $pay[$r['subscription_id']] = $r;   // the latest wins

        $deliveries = [];
        $madeDays = [];
        try {
            $st = $conn->prepare("SELECT subscription_id, delivery_date, meal_time, order_status, order_number FROM orders
                WHERE restaurant_id = ? AND subscription_id IN ($in) ORDER BY delivery_date DESC, meal_time ASC");
            $st->execute(array_merge([$restaurant_id], $ids));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sid = $r['subscription_id'];
                $madeDays[$sid][$r['delivery_date']] = true;
                if (count($deliveries[$sid] ?? []) < 10) $deliveries[$sid][] = $r;
            }
        } catch (PDOException $e) { /* no subscription columns on orders yet */ }

        // the days of the week each meal time has a menu: deliveries are only made on those
        $menuDays = ['lunch' => [], 'dinner' => []];
        try {
            $st = $conn->prepare("SELECT day_of_week, meal_time FROM meal_plan_weekly_menu WHERE restaurant_id = ? AND is_active = 1");
            $st->execute([$restaurant_id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $menuDays[$r['meal_time']][(int)$r['day_of_week']] = true;
        } catch (PDOException $e) {}

        foreach ($subs as &$s) {
            $sid = $s['id'];
            $s['credits_remaining'] = max(0, (int)$s['credits_total'] - (int)$s['credits_used']);
            $s['skip_dates'] = $skips[$sid] ?? [];
            $s['payment_status'] = $pay[$sid]['payment_status'] ?? null;
            $s['payment_method'] = $pay[$sid]['payment_method'] ?? null;
            $s['deliveries'] = $deliveries[$sid] ?? [];
            $s['next_delivery'] = null;
            if ($s['status'] === 'active' && $s['credits_remaining'] > 0) {
                $scope = $s['meal_scope_snapshot'];
                $times = $scope === 'both' ? ['lunch', 'dinner'] : [$scope];
                $d = new DateTime(max($today, (string)($s['start_date'] ?? '')));
                for ($i = 0; $i < 60; $i++, $d->modify('+1 day')) {
                    $ds = $d->format('Y-m-d');
                    if (isset($madeDays[$sid][$ds]) || in_array($ds, $s['skip_dates'], true)) continue;
                    $dow = (int)$d->format('w');
                    foreach ($times as $t) {
                        if (isset($menuDays[$t][$dow])) { $s['next_delivery'] = $ds; break 2; }
                    }
                }
            }
        }
        unset($s);
        return $subs;
    }
}
