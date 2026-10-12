<?php
/**
 * Offers > Today's Special.
 *
 * Up to TODAYS_SPECIAL_MAX dishes per weekday (0 = Sunday … 6 = Saturday) or
 * for every day (7, used when a day has none), each with a chef's note and an
 * optional daily limit. The home page shows today's ones above Bestsellers;
 * "plates left" is counted from today's real orders of each dish.
 */
if (!defined('TODAYS_SPECIAL_EVERY_DAY')) define('TODAYS_SPECIAL_EVERY_DAY', 7);
if (!defined('TODAYS_SPECIAL_MAX')) define('TODAYS_SPECIAL_MAX', 6);

if (!function_exists('ensureTodaysSpecialTable')) {

    function ensureTodaysSpecialTable(PDO $conn): void {
        static $done = false;
        if ($done) return;
        $done = true;
        $conn->exec("CREATE TABLE IF NOT EXISTS todays_specials (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            weekday TINYINT NOT NULL,
            position TINYINT NOT NULL DEFAULT 0,
            menu_item_id INT NULL,
            note VARCHAR(160) NULL,
            daily_limit INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_todays_special (restaurant_id, weekday)
        )");
        // First version allowed one dish per day: lift that and add the order column
        try {
            $q = $conn->query("SHOW COLUMNS FROM todays_specials LIKE 'position'");
            if (!($q && $q->fetch())) $conn->exec("ALTER TABLE todays_specials ADD COLUMN position TINYINT NOT NULL DEFAULT 0 AFTER weekday");
            $q = $conn->query("SHOW INDEX FROM todays_specials WHERE Key_name = 'uq_todays_special'");
            if ($q && $q->fetch()) {
                $conn->exec("ALTER TABLE todays_specials DROP INDEX uq_todays_special");
                $conn->exec("ALTER TABLE todays_specials ADD INDEX idx_todays_special (restaurant_id, weekday)");
            }
        } catch (PDOException $e) {
            error_log('ensureTodaysSpecialTable: ' . $e->getMessage());
        }
    }

    /** The restaurant's time zone (users.timezone), falling back to the server's. */
    function todaysSpecialZone(PDO $conn, string $restaurantId): DateTimeZone {
        try {
            $st = $conn->prepare('SELECT timezone FROM users WHERE restaurant_id = ? LIMIT 1');
            $st->execute([$restaurantId]);
            $tz = (string)$st->fetchColumn();
            if ($tz !== '') return new DateTimeZone($tz);
        } catch (Exception $e) {}
        return new DateTimeZone(date_default_timezone_get());
    }

    /**
     * Today's specials for the customer website: ['weekday_name', 'ends_ts',
     * 'items' => [[menu_item_id, note, daily_limit, sold_today], …]], or null.
     */
    function getTodaysSpecials(PDO $conn, string $restaurantId): ?array {
        ensureTodaysSpecialTable($conn);
        $tz = todaysSpecialZone($conn, $restaurantId);
        $now = new DateTime('now', $tz);
        $weekday = (int)$now->format('w');

        $st = $conn->prepare("SELECT ts.weekday, ts.menu_item_id, ts.note, ts.daily_limit
            FROM todays_specials ts
            JOIN menu_items mi ON mi.id = ts.menu_item_id AND mi.restaurant_id = ts.restaurant_id
            WHERE ts.restaurant_id = ? AND ts.weekday IN (?, ?) AND ts.menu_item_id IS NOT NULL
              AND (mi.is_hidden = 0 OR mi.is_hidden IS NULL)
            ORDER BY ts.weekday = ? DESC, ts.position, ts.id");
        $st->execute([$restaurantId, $weekday, TODAYS_SPECIAL_EVERY_DAY, $weekday]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;
        // Today's own dishes win; "every day" only when today has none
        $useDay = (int)$rows[0]['weekday'];
        $rows = array_values(array_filter($rows, function ($r) use ($useDay) { return (int)$r['weekday'] === $useDay; }));

        // Start of the restaurant's day in the database's own clock (orders.created_at)
        $midnight = (clone $now)->setTime(0, 0, 0);
        $sinceMidnight = $now->getTimestamp() - $midnight->getTimestamp();
        $dbNow = strtotime((string)$conn->query('SELECT NOW()')->fetchColumn());
        $dayStartDb = date('Y-m-d H:i:s', $dbNow - $sinceMidnight);
        $count = $conn->prepare("SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.restaurant_id = ? AND oi.menu_item_id = ? AND o.created_at >= ?
              AND o.order_status NOT IN ('Cancelled', 'Rejected') AND o.deleted_at IS NULL");

        $items = [];
        foreach ($rows as $r) {
            $limit = (int)$r['daily_limit'] ?: null;
            $sold = 0;
            if ($limit) { $count->execute([$restaurantId, (int)$r['menu_item_id'], $dayStartDb]); $sold = (int)$count->fetchColumn(); }
            $items[] = ['menu_item_id' => (int)$r['menu_item_id'], 'note' => (string)($r['note'] ?? ''), 'daily_limit' => $limit, 'sold_today' => $sold];
        }
        return ['weekday_name' => $now->format('l'), 'ends_ts' => $midnight->getTimestamp() + 86400, 'items' => $items];
    }
}
