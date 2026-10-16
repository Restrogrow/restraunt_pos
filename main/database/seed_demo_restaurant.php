<?php
/**
 * Builds (or rebuilds) the public demo restaurant "Spice Garden Indiranagar".
 *
 *   php main/database/seed_demo_restaurant.php
 *
 * Login: demo / demo  (or open /demo for one-tap login). Staff: <role>.demo@spicegarden.test / demo.
 * Everything is in main/config/demo_restaurant.php; on the live server use
 * Super Admin › Demo restaurant › Reset demo instead.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/demo_restaurant.php';

try {
    $summary = resetDemoRestaurant(getConnection(), __DIR__ . '/../assets/demo_restaurant', 'http://localhost/menuwebsite/main');
} catch (Throwable $e) {
    fwrite(STDERR, 'Demo restaurant failed: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($summary as $k => $v) {
    printf("  %-12s %s\n", str_replace('_', ' ', $k), $v);
}
echo "\nAdmin:   http://localhost/menuwebsite/main/admin/login.php  (" . DEMO_USERNAME . ' / ' . DEMO_PASSWORD . ")\n";
echo "Website: http://localhost/menuwebsite/" . demoRestaurantSlug() . "\n";
echo "One-tap: http://localhost/menuwebsite/demo/\n";
