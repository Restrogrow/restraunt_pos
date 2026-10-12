<?php
/**
 * Offers > Today's Special.
 *
 * A dish per weekday (0 = Sunday … 6 = Saturday) or one for every day (7, used
 * when a day has none), a chef's note and an optional daily limit. The home
 * page shows today's one above Bestsellers; "plates left" is counted from
 * today's real orders of that dish.
 */
if (!defined('TODAYS_SPECIAL_EVERY_DAY')) define('TODAYS_SPECIAL_EVERY_DAY', 7);

if (!function_exists('ensureTodaysSpecialTable')) {

    function ensureTodaysSpecialTable(PDO $conn): void {
        static $done = false;
        if ($done) return;
        $done = true;
        $conn->exec("CREATE TABLE IF NOT EXISTS todays_specials (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            weekday TINYINT NOT NULL,
            menu_item_id INT NULL,
            note VARCHAR(160) NULL,
            daily_limit INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_todays_special (restaurant_id, weekday)
        )");
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
     * Today's special for the customer website, or null.
     * Returns the dish id, note, daily limit, plates sold today, the weekday name
     * and when today ends (unix seconds, restaurant time).
     */
    function getTodaysSpecial(PDO $conn, string $restaurantId): ?array {
        ensureTodaysSpecialTable($conn);
        $tz = todaysSpecialZone($conn, $restaurantId);
        $now = new DateTime('now', $tz);
        $weekday = (int)$now->format('w');

        $st = $conn->prepare("SELECT ts.weekday, ts.menu_item_id, ts.note, ts.daily_limit
            FROM todays_specials ts
            JOIN menu_items mi ON mi.id = ts.menu_item_id AND mi.restaurant_id = ts.restaurant_id
            WHERE ts.restaurant_id = ? AND ts.weekday IN (?, ?) AND ts.menu_item_id IS NOT NULL
              AND (mi.is_hidden = 0 OR mi.is_hidden IS NULL)
            ORDER BY ts.weekday = ? DESC LIMIT 1");
        $st->execute([$restaurantId, $weekday, TODAYS_SPECIAL_EVERY_DAY, $weekday]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        // Start of the restaurant's day, expressed in the database's own clock
        // (orders.created_at uses the DB time), so "today" is the restaurant's today.
        $midnight = (clone $now)->setTime(0, 0, 0);
        $sinceMidnight = $now->getTimestamp() - $midnight->getTimestamp();
        $dbNow = strtotime((string)$conn->query('SELECT NOW()')->fetchColumn());
        $dayStartDb = date('Y-m-d H:i:s', $dbNow - $sinceMidnight);

        $sold = 0;
        if ((int)$row['daily_limit'] > 0) {
            $c = $conn->prepare("SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE o.restaurant_id = ? AND oi.menu_item_id = ? AND o.created_at >= ?
                  AND o.order_status NOT IN ('Cancelled', 'Rejected') AND o.deleted_at IS NULL");
            $c->execute([$restaurantId, (int)$row['menu_item_id'], $dayStartDb]);
            $sold = (int)$c->fetchColumn();
        }
        return [
            'menu_item_id' => (int)$row['menu_item_id'],
            'note' => (string)($row['note'] ?? ''),
            'daily_limit' => (int)$row['daily_limit'] ?: null,
            'sold_today' => $sold,
            'weekday_name' => $now->format('l'),
            'ends_ts' => $midnight->getTimestamp() + 86400,
        ];
    }
}
