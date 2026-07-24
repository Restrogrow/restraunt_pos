<?php
if (ob_get_level()) ob_clean();
ob_start();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
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

ob_end_clean();
echo json_encode([
    'success' => true,
    'restaurant_id' => $found['restaurant_id'],
    'restaurant_name' => $found['restaurant_name']
]);