<?php
/**
 * Self-healing columns for the "Show GSTIN on Bill" setting. Off by default
 * for every restaurant — the owner turns it on in Settings and enters their
 * GSTIN, which then prints on the customer bill/KOT. Deliberately separate
 * from show_pan_no/pan_no (see main/config/pan_helpers.php) — GSTIN and PAN
 * are different registrations and a restaurant may want to show either or
 * both, so one can't just be relabeled as the other.
 */

if (defined('GSTIN_HELPERS_LOADED')) {
    return;
}
define('GSTIN_HELPERS_LOADED', true);

if (!function_exists('ensureGstinColumns')) {
    function ensureGstinColumns(PDO $conn): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $conn->query("SELECT show_gstin, gstin_no FROM users LIMIT 1");
        } catch (PDOException $e) {
            try {
                $conn->exec("ALTER TABLE users ADD COLUMN show_gstin TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN gstin_no VARCHAR(20) DEFAULT NULL");
            } catch (PDOException $e2) {
            }
        }
    }
}
