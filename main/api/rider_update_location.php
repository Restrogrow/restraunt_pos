<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);

require_once __DIR__ . '/../db_connection.php';

try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        global $pdo;
        $conn = $pdo;
    }

    $token = $_POST['token'] ?? '';
    $lat = (float)($_POST['lat'] ?? 0);
    $lng = (float)($_POST['lng'] ?? 0);

    if (empty($token) || $lat == 0 || $lng == 0) {
        throw new Exception('Missing parameters');
    }

    // Validate token
    $stmt = $conn->prepare("SELECT dt.id, o.restaurant_id FROM delivery_tracking dt JOIN orders o ON dt.order_id = o.id WHERE dt.qr_token = ? AND dt.qr_expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $tracking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tracking) {
        throw new Exception('Invalid or expired QR code');
    }

    // Update location
    $stmt = $conn->prepare("UPDATE delivery_tracking SET current_lat = ?, current_lng = ?, location_updated_at = NOW(), updated_at = NOW() WHERE id = ?");
    $stmt->execute([$lat, $lng, $tracking['id']]);

    echo json_encode(['success' => true, 'message' => 'Location updated']);

} catch (Exception $e) {
    http_response_code(400);
    error_log("Error in rider_update_location.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
