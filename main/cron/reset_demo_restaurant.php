<?php
/**
 * Rebuilds the public demo restaurant, undoing whatever visitors changed and keeping
 * "today" fresh (new orders waiting, food cooking, tables busy).
 *
 * Hostinger hPanel › Advanced › Cron Jobs, every 3 hours:
 *   /usr/bin/php /home/<user>/public_html/main/cron/reset_demo_restaurant.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/demo_restaurant.php';
require_once __DIR__ . '/../config/env_loader.php';

$appUrl = rtrim((string)(env('APP_URL', '') ?: 'https://restrogrow.com'), '/');
try {
    $s = resetDemoRestaurant(getConnection(), __DIR__ . '/../assets/demo_restaurant', $appUrl . '/main');
    echo date('c') . " demo reset: {$s['orders']} orders, {$s['dishes']} dishes\n";
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' demo reset failed: ' . $e->getMessage() . "\n");
    exit(1);
}
