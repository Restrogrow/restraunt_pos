<?php
/**
 * Self-healing toggle for the customer-facing "Book a Table" reservation
 * feature. Off by default for every restaurant — the owner turns it on in
 * Settings, which shows a Reservations nav item on their customer website
 * (mirrors how meal_subscriptions_enabled gates the "Plans" nav item, see
 * main/config/meal_subscription_schema.php).
 */

if (defined('RESERVATION_HELPERS_LOADED')) {
    return;
}
define('RESERVATION_HELPERS_LOADED', true);

if (!function_exists('ensureReservationsToggleColumn')) {
    function ensureReservationsToggleColumn(PDO $conn): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $conn->query("SELECT enable_reservations FROM users LIMIT 1");
        } catch (PDOException $e) {
            try {
                $conn->exec("ALTER TABLE users ADD COLUMN enable_reservations TINYINT(1) NOT NULL DEFAULT 0");
            } catch (PDOException $e2) {
            }
        }
    }
}

if (!function_exists('reservationsFeatureEnabled')) {
    /**
     * Whether the restaurant owner has turned table reservations on for
     * their customer website. Defaults to false (and never throws) if the
     * column can't be read for any reason.
     */
    function reservationsFeatureEnabled(PDO $conn, ?string $restaurant_id): bool {
        if (!$restaurant_id) {
            return false;
        }
        try {
            ensureReservationsToggleColumn($conn);
            $stmt = $conn->prepare("SELECT enable_reservations FROM users WHERE restaurant_id = ? LIMIT 1");
            $stmt->execute([$restaurant_id]);
            return (int)($stmt->fetchColumn() ?: 0) === 1;
        } catch (Exception $e) {
            return false;
        }
    }
}
