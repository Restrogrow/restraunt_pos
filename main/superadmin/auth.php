<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

function require_superadmin() {
    if (!isSessionValid() || !isset($_SESSION['superadmin_id'])) {
        header('Location: login.php');
        exit();
    }
}

// api.php is a JSON-only endpoint, never a page a browser navigates to
// directly — an expired/missing session there must fail with a JSON 401,
// not the HTML redirect require_superadmin() sends. Every dashboard.php
// fetch('api.php?...') call does res.json() unconditionally; getting an
// HTML login page back instead throws a SyntaxError that call sites were
// silently swallowing, which is what made pagination Prev/Next (born
// `disabled` in the HTML, only re-enabled after a full successful fetch)
// look permanently broken whenever the superadmin session had expired.
function require_superadmin_api() {
    if (!isSessionValid() || !isset($_SESSION['superadmin_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.', 'session_expired' => true]);
        exit();
    }
}
?>


