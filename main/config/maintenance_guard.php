<?php
/**
 * Maintenance Script Access Guard
 * ─────────────────────────────────────────────────────────────────────────────
 * One-time migration / DB-fix scripts in admin/ and api/ used to be runnable
 * by ANYONE who knew the URL — e.g. restrogrow.com/main/admin/run_addons_migration.php
 * would run DDL against the production DB from an anonymous browser session.
 *
 * This guard allows exactly two callers:
 *   1. CLI (php run_addons_migration.php) — real cron / shell access implies
 *      server access already.
 *   2. A logged-in Admin (or Super Admin) web session — the people who
 *      legitimately run these from a browser tab.
 *
 * Everyone else gets a flat 404-style response with no information leak.
 * Usage: require_once __DIR__ . '/maintenance_guard.php'; at the very top of
 * the script, BEFORE any output or DB work.
 */

if (!function_exists('enforceMaintenanceAccess')) {
    function enforceMaintenanceAccess(): void {
        // CLI is always trusted.
        if (php_sapi_name() === 'cli') {
            return;
        }

        // Web: require an authenticated admin/staff/super-admin session.
        // session_config.php defines startSecureSession() + isSessionValid();
        // include it lazily so scripts that already started a session still work.
        $__sessionConfig = __DIR__ . '/session_config.php';
        if (file_exists($__sessionConfig)) {
            require_once $__sessionConfig;
        }
        if (function_exists('startSecureSession')) {
            startSecureSession();
        }

        $__loggedIn = false;
        if (function_exists('isSessionValid')) {
            $__loggedIn = isSessionValid()
                && (isset($_SESSION['user_id']) || isset($_SESSION['staff_id'])
                    || isset($_SESSION['branch_admin_id']) || isset($_SESSION['superadmin_id']));
        } else {
            // Fallback if session_config is somehow unavailable: any of the
            // known identity keys counts as logged in.
            $__loggedIn = isset($_SESSION['user_id']) || isset($_SESSION['staff_id'])
                || isset($_SESSION['branch_admin_id']) || isset($_SESSION['superadmin_id']);
        }

        if ($__loggedIn) {
            return;
        }

        // Flat, information-free refusal.
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Robots-Tag: noindex');
        exit('Not found.');
    }
}
