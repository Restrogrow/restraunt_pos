<?php
/**
 * The dashboard reports a page being opened (assets/js/onboarding.js, once per page per browser tab
 * session and day), for Super Admin › Usage: "opened but never used" vs "never opened".
 * POST page=posPage. No answer body needed; never fails loudly.
 */
require_once __DIR__ . '/../config/session_config.php';
header('Content-Type: application/json; charset=UTF-8');
$rid = (string)($_SESSION['restaurant_id'] ?? '');
$who = ($_SESSION['user_type'] ?? '') === 'staff' ? 'staff' : 'owner';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
// only someone logged in to the dashboard (a website visitor also has restaurant_id in the session)
$dash = !empty($_SESSION['user_id']) || !empty($_SESSION['staff_id']) || !empty($_SESSION['branch_admin_id']);
if ($rid === '' || !$dash || $_SERVER['REQUEST_METHOD'] !== 'POST') { echo '{"success":false}'; exit; }
try {
    require_once __DIR__ . '/../db_connection.php';
    require_once __DIR__ . '/../config/usage.php';
    usPing(getConnection(), $rid, (string)($_POST['page'] ?? ''), $who);
    echo '{"success":true}';
} catch (Throwable $e) {
    error_log('[usage ping] ' . $e->getMessage());
    echo '{"success":false}';
}
