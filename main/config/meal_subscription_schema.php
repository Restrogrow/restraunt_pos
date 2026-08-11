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
