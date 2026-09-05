<?php
/**
 * Self-healing columns for the "Show PAN No on bill" setting. Off by default
 * for every restaurant — the owner turns it on in Settings and enters their
 * PAN number, which then prints on the customer bill (mirrors how
 * enable_reservations gates the reservations feature, see
 * main/config/reservation_helpers.php).
 */

if (defined('PAN_HELPERS_LOADED')) {
    return;
}
define('PAN_HELPERS_LOADED', true);

if (!function_exists('ensurePanColumns')) {
    function ensurePanColumns(PDO $conn): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $conn->query("SELECT show_pan_no, pan_no FROM users LIMIT 1");
        } catch (PDOException $e) {
            try {
                $conn->exec("ALTER TABLE users ADD COLUMN show_pan_no TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN pan_no VARCHAR(20) DEFAULT NULL");
            } catch (PDOException $e2) {
            }
        }
    }
}
