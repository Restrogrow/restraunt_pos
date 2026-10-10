<?php
if (ob_get_level()) ob_clean();
ob_start();

// No CORS wildcard — branch-admin restaurant switching, always called
// same-origin from the admin dashboard. Same-origin requests never trigger
// a CORS preflight, so this branch is only ever hit by a real cross-origin
// caller; responding without any Access-Control-* headers means the
// browser blocks it either way.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit();
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

if (!isset($_SESSION['branch_admin_id']) || !isset($_SESSION['linked_restaurants'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$restaurantId = trim($input['restaurant_id'] ?? '');

if (!$restaurantId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing restaurant_id']);
    exit();
}

// Verify restaurant is in linked list
$found = null;
foreach ($_SESSION['linked_restaurants'] as $lr) {
    if ($lr['restaurant_id'] === $restaurantId) {
        $found = $lr;
        break;
    }
}

if (!$found) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Restaurant not in your linked list']);
    exit();
}

$_SESSION['restaurant_id'] = $found['restaurant_id'];
$_SESSION['restaurant_name'] = $found['restaurant_name'];

// Act as that branch's admin account, so every admin page/API that keys
// off user_id (settings, staff, add-ons, catering, ...) works per branch.
try {
    require_once __DIR__ . '/../db_connection.php';
    $stmt = getConnection()->prepare("SELECT id FROM users WHERE restaurant_id = ? LIMIT 1");
    $stmt->execute([$found['restaurant_id']]);
    $branchUserId = $stmt->fetchColumn();
    if ($branchUserId) {
        $_SESSION['user_id'] = (int)$branchUserId;
    } else {
        unset($_SESSION['user_id']);
    }
} catch (Exception $e) {
    error_log('switch_restaurant.php: could not resolve branch user_id: ' . $e->getMessage());
}

ob_end_clean();
echo json_encode([
    'success' => true,
    'restaurant_id' => $found['restaurant_id'],
    'restaurant_name' => $found['restaurant_name']
]);