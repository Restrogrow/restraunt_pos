<?php
/**
 * The rating a restaurant's website shows.
 *
 * Normally it is the real one: the average of customers' order feedback, counted only for completed
 * orders, and shown from three ratings up so one bad night cannot define a restaurant.
 *
 * A restaurant that has just opened has none of that, and an empty space where other listings show
 * stars reads worse than the restaurant deserves. So Settings › Restaurant profile lets the owner put
 * in a starting rating and review count — their Google or Zomato figure, say — and the website shows
 * that until real feedback arrives.
 *
 * It is a starting point, not an override. The moment three customers have rated an order, the real
 * average takes over and the owner's number is ignored: nobody can use this to bury genuine feedback.
 * rgPublicRating() says which of the two it handed back, so the dashboard can show the owner what the
 * public is actually seeing.
 */
require_once __DIR__ . '/schema_cache.php';

if (!function_exists('rgPublicRating')) {

    /** How many customer ratings a restaurant needs before its real average is the one shown. */
    define('RG_RATING_MIN', 3);

    /** Adds users.manual_rating and users.manual_rating_count if this database predates them. */
    function ensureRestaurantRatingColumns(PDO $conn)
    {
        try {
            if (!rgHasColumn($conn, 'users', 'manual_rating')) {
                $conn->exec('ALTER TABLE users ADD COLUMN manual_rating DECIMAL(2,1) DEFAULT NULL');
            }
            if (!rgHasColumn($conn, 'users', 'manual_rating_count')) {
                $conn->exec('ALTER TABLE users ADD COLUMN manual_rating_count INT DEFAULT NULL');
            }
            return rgHasColumn($conn, 'users', 'manual_rating') && rgHasColumn($conn, 'users', 'manual_rating_count');
        } catch (Throwable $e) {
            error_log('ensureRestaurantRatingColumns: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cleans up a rating typed into the dashboard: null to turn it off, otherwise 1.0-5.0 to one
     * decimal place. Anything unparseable turns it off rather than guessing.
     */
    function rgNormaliseManualRating($value): ?float
    {
        $v = trim((string)$value);
        if ($v === '' || !is_numeric($v)) return null;
        $f = (float)$v;
        if ($f <= 0) return null;
        return round(max(1.0, min(5.0, $f)), 1);
    }

    /** Cleans up a review count: null when blank or zero, otherwise a sane whole number. */
    function rgNormaliseManualRatingCount($value): ?int
    {
        $v = trim((string)$value);
        if ($v === '' || !is_numeric($v)) return null;
        $n = (int)round((float)$v);
        return $n > 0 ? min(1000000, $n) : null;
    }

    /**
     * What the website should show for a restaurant, or null when it should show nothing.
     *
     *   ['avg' => '4.3', 'n' => 128, 'source' => 'customers'|'owner']
     *
     * 'avg' is already formatted to one decimal place for display. 'n' is 0 when the owner gave a
     * rating but no review count, which means "show the stars, not the count".
     */
    function rgPublicRating(PDO $conn, string $restaurantId): ?array
    {
        try {
            $st = $conn->prepare("SELECT COUNT(*) AS n, AVG(f.rating) AS avg FROM order_feedback f
                                  JOIN orders o ON o.id = f.order_id AND o.order_status = 'Completed'
                                  WHERE f.restaurant_id = ? AND f.rating > 0");
            $st->execute([$restaurantId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)$row['n'] >= RG_RATING_MIN) {
                return ['avg' => number_format((float)$row['avg'], 1), 'n' => (int)$row['n'], 'source' => 'customers'];
            }
        } catch (Throwable $e) {
            // no feedback table yet; fall through to whatever the owner set
        }

        try {
            if (!rgHasColumn($conn, 'users', 'manual_rating')) return null;
            $st = $conn->prepare('SELECT manual_rating, manual_rating_count FROM users WHERE restaurant_id = ? LIMIT 1');
            $st->execute([$restaurantId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $manual = $row ? rgNormaliseManualRating($row['manual_rating']) : null;
            if ($manual === null) return null;
            return ['avg' => number_format($manual, 1), 'n' => (int)rgNormaliseManualRatingCount($row['manual_rating_count'] ?? null), 'source' => 'owner'];
        } catch (Throwable $e) {
            error_log('rgPublicRating: ' . $e->getMessage());
        }
        return null;
    }
}
