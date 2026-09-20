<?php
/**
 * Self-healing columns for the unified "Business Registration ID" setting —
 * replaces the separate PAN/GSTIN toggles added earlier. A bill only ever
 * needs to show one such number, and which one it's called varies by
 * country (GSTIN in India, PAN No in Nepal, ...), so this is one generic
 * label+value pair instead of a fixed field per country. The label always
 * starts from a sensible country-based default but is freely editable —
 * same idea as tax_name/tax_percent already are.
 */

if (defined('BUSINESS_ID_HELPERS_LOADED')) {
    return;
}
define('BUSINESS_ID_HELPERS_LOADED', true);

if (!function_exists('defaultBusinessIdLabel')) {
    function defaultBusinessIdLabel(?string $country): string {
        $country = strtolower(trim((string)$country));
        if ($country === 'nepal') return 'PAN No';
        if ($country === 'india') return 'GSTIN';
        return 'PAN';
    }
}

if (!function_exists('ensureBusinessIdColumns')) {
    function ensureBusinessIdColumns(PDO $conn): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $conn->query("SELECT show_business_id, business_id_label, business_id_no FROM users LIMIT 1");
            return;
        } catch (PDOException $e) {
            try {
                $conn->exec("ALTER TABLE users ADD COLUMN show_business_id TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN business_id_label VARCHAR(30) DEFAULT NULL, ADD COLUMN business_id_no VARCHAR(20) DEFAULT NULL");
            } catch (PDOException $e2) {
                return;
            }
        }

        // One-time backfill from the older, now-retired separate PAN/GSTIN
        // columns (see pan_helpers.php/gstin_helpers.php) — only runs the
        // moment the new columns are first created, and only if the caller
        // ensured those older columns exist first (harmless no-op otherwise).
        try {
            $conn->exec("
                UPDATE users
                SET show_business_id = 1, business_id_label = 'GSTIN', business_id_no = gstin_no
                WHERE show_gstin = 1 AND gstin_no IS NOT NULL AND gstin_no != ''
                  AND (business_id_no IS NULL OR business_id_no = '')
            ");
        } catch (PDOException $e3) {
        }
        try {
            $conn->exec("
                UPDATE users
                SET show_business_id = 1, business_id_label = 'PAN No', business_id_no = pan_no
                WHERE show_pan_no = 1 AND pan_no IS NOT NULL AND pan_no != ''
                  AND (business_id_no IS NULL OR business_id_no = '')
            ");
        } catch (PDOException $e4) {
        }
    }
}
