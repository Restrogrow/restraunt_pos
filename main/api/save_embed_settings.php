<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (!isset($_SESSION['restaurant_id']) && !isset($_SESSION['superadmin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$restaurant_id = $_SESSION['restaurant_id'] ?? '';
if (!$restaurant_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No restaurant selected']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

try {
    require_once __DIR__ . '/../db_connection.php';
    $conn = getConnection();

    $embed_enabled = !empty($input['embed_enabled']) ? 1 : 0;
    $custom_domain = trim($input['custom_domain'] ?? '');

    $stmt = $conn->prepare("UPDATE users SET embed_enabled = ?, custom_domain = ? WHERE restaurant_id = ?");
    $stmt->execute([$embed_enabled, $custom_domain, $restaurant_id]);

    echo json_encode(['success' => true, 'message' => 'Embed settings saved']);
} catch (Exception $e) {
    http_response_code(500);
    error_log("Error in save_embed_settings.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}