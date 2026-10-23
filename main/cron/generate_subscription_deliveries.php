<?php
/**
 * Cron entry point for meal-plan subscription deliveries. Meant to be called
 * once a day (early morning, before lunch prep) by a real server cron -
 * Hostinger's cPanel supports scheduling either a CLI command or a URL hit.
 *
 * CLI:  php /path/to/main/cron/generate_subscription_deliveries.php
 * URL:  https://restrogrow.com/main/cron/generate_subscription_deliveries.php?key=YOUR_SECRET
 *
 * Loops every restaurant (unlike the admin panel's "Generate Today's
 * Deliveries" button, which is scoped to the single restaurant in session)
 * and generates today's due deliveries for each. Safe to run more than once
 * a day, and safe to run alongside a restaurant's own manual button press -
 * generateSubscriptionDeliveriesForDate() skips anything already generated.
 *
 * No server cron set up yet? The admin "Generate Today's Deliveries" button
 * on main/admin/meal_subscriptions.php calls the exact same underlying
 * function, so the feature still works without this file ever running.
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    // URL-callable path needs a shared secret so a random visitor can't spam
    // this endpoint - CLI invocation (real cron, or `php script.php`) is
    // trusted implicitly since reaching the filesystem already implies
    // server access.
    require_once __DIR__ . '/../config/env_loader.php';
    $cronKey = env('CRON_SECRET_KEY') ?: null;
    if (!$cronKey || !isset($_GET['key']) || !hash_equals($cronKey, (string)$_GET['key'])) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo "Forbidden. Set CRON_SECRET_KEY in .env and pass it as ?key=...\n";
        exit();
    }
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/meal_subscription_schema.php';
require_once __DIR__ . '/../config/subscription_delivery_generator.php';

header('Content-Type: text/plain');

try {
    $conn = getConnection();
    ensureMealSubscriptionTables($conn);
    ensureOrdersSubscriptionColumns($conn);

    // Optional ?date=YYYY-MM-DD override for testing; defaults to today on each restaurant's own clock.
    $dateOverride = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : null;
    $dateStr = $dateOverride ?: date('Y-m-d');

    // only restaurants that are live (plan not ended / switched off) and still offer meal plans
    // (no meal_subscriptions_enabled column yet = the feature is off everywhere, see mealSubscriptionsFeatureEnabled)
    $restaurantIds = [];
    if ($conn->query("SHOW COLUMNS FROM users LIKE 'meal_subscriptions_enabled'")->fetch()) {
        // (filtered in PHP: the tables' collations may differ)
        $live = array_flip($conn->query("SELECT restaurant_id FROM users WHERE is_active = 1 AND COALESCE(subscription_status, '') NOT IN ('expired', 'disabled')
            AND meal_subscriptions_enabled = 1")->fetchAll(PDO::FETCH_COLUMN));
        $restaurantsStmt = $conn->query("SELECT DISTINCT restaurant_id FROM customer_meal_subscriptions WHERE status = 'active'");
        $restaurantIds = array_values(array_filter($restaurantsStmt->fetchAll(PDO::FETCH_COLUMN), function ($rid) use ($live) { return isset($live[$rid]); }));
    }

    echo "Generating subscription deliveries for {$dateStr}\n";
    echo "Restaurants with active subscriptions: " . count($restaurantIds) . "\n\n";

    $totals = ['generated' => 0, 'skipped_date' => 0, 'skipped_no_menu' => 0, 'skipped_already_generated' => 0, 'errors' => 0];

    foreach ($restaurantIds as $restaurantId) {
        $day = $dateOverride ?: mealPlanToday($conn, $restaurantId);
        $result = generateSubscriptionDeliveriesForDate($conn, $restaurantId, $day);
        echo "  {$restaurantId} ({$day}): generated={$result['generated']} skipped_date={$result['skipped_date']} skipped_no_menu={$result['skipped_no_menu']} already_generated={$result['skipped_already_generated']} errors={$result['errors']}\n";
        foreach ($totals as $k => $v) {
            $totals[$k] += $result[$k];
        }
    }

    echo "\nTotals: " . json_encode($totals) . "\n";
} catch (Exception $e) {
    error_log('generate_subscription_deliveries.php: ' . $e->getMessage());
    http_response_code(500);
    echo "Error: " . $e->getMessage() . "\n";
}
