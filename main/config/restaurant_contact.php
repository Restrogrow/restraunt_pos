<?php
/**
 * A restaurant's contact numbers.
 *
 * Settings › Restaurant profile › Contact takes two: the main one (users.phone, the number the
 * restaurant has always had) and a second (users.phone_2) for the other line — a landline beside a
 * mobile, the kitchen beside the counter, the owner beside the shop. Both show wherever one showed
 * before: the website's Call, Contact and About, the policy pages, and the printed bill.
 *
 * The second is optional everywhere. rgPhones() hands back whichever are filled in, in order and
 * without repeats, so every caller can write the same loop and a restaurant with one number sees no
 * change at all.
 */
require_once __DIR__ . '/schema_cache.php';

if (!function_exists('rgPhones')) {

    /** Adds users.phone_2 if this database predates it. Run through rgEnsureOnce(). */
    function ensureRestaurantContactColumns(PDO $conn): void
    {
        try {
            if (!rgHasColumn($conn, 'users', 'phone_2')) {
                $conn->exec("ALTER TABLE users ADD COLUMN phone_2 VARCHAR(20) DEFAULT NULL AFTER phone");
            }
        } catch (Throwable $e) {
            error_log('ensureRestaurantContactColumns: ' . $e->getMessage());
        }
    }

    /**
     * The numbers a restaurant can be reached on, best first: [] when it has none, one when it has
     * one. The same number typed into both boxes counts once.
     */
    function rgPhones($phone, $phone2 = ''): array
    {
        $out = [];
        foreach ([$phone, $phone2] as $p) {
            $p = trim((string)$p);
            if ($p === '') continue;
            $digits = preg_replace('/\D/', '', $p);
            foreach ($out as $had) {
                if (preg_replace('/\D/', '', $had) === $digits) continue 2;   // the same number twice
            }
            $out[] = $p;
        }
        return $out;
    }
}
