<?php
/**
 * Applies a restaurant's configured IANA timezone (users.timezone) to the current
 * request: PHP's default timezone (so date()/DateTime default to it) and, if a PDO
 * connection is passed, the MySQL session time_zone (so NOW()/CURDATE() in queries
 * match it too).
 *
 * db_connection.php hardcodes Asia/Kolkata as a safe default before any restaurant
 * is known; call this afterwards, once $restaurant_id/timezone has been resolved,
 * to make the per-restaurant setting actually take effect.
 */
function applyRestaurantTimezone($timezoneName, $pdo = null) {
    if (empty($timezoneName) || !in_array($timezoneName, timezone_identifiers_list(), true)) {
        $timezoneName = 'Asia/Kolkata';
    }

    date_default_timezone_set($timezoneName);

    if ($pdo instanceof PDO) {
        try {
            $tz = new DateTimeZone($timezoneName);
            $offsetSeconds = $tz->getOffset(new DateTime('now', $tz));
            $sign = $offsetSeconds < 0 ? '-' : '+';
            $offsetSeconds = abs($offsetSeconds);
            $offset = sprintf('%s%02d:%02d', $sign, intdiv($offsetSeconds, 3600), intdiv($offsetSeconds % 3600, 60));
            $pdo->exec("SET SESSION time_zone = " . $pdo->quote($offset));
        } catch (Exception $e) {
            // Ignore - PHP's default timezone is still set correctly above
        }
    }

    return $timezoneName;
}
