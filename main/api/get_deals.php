<?php
/**
 * Get all deals for a restaurant
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Auth required', 'data' => []]);
    exit;
}

try {
    $conn = getConnection();
    
    // Check if deals table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'deals'");
    if ($tableCheck->rowCount() === 0) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }
    
    // This endpoint requires login above, so always scope to the session's own
    // tenant — never trust a client-supplied restaurant_id.
    $restaurant_id = $_SESSION['restaurant_id'] ?? '';
    
    if (empty($restaurant_id)) {
        echo json_encode(['success' => false, 'data' => []]);
        exit;
    }
    
    $stmt = $conn->prepare("
        SELECT d.id, d.deal_type, d.menu_id, d.is_active, d.created_at,
               m.menu_name
        FROM deals d
        JOIN menu m ON d.menu_id = m.id
        WHERE d.restaurant_id = ? AND d.is_active = 1
        ORDER BY d.created_at DESC
    ");
    $stmt->execute([$restaurant_id]);
    $deals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $deals]);
    
} catch (Exception $e) {
    error_log("Error in get_deals.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.', 'data' => []]);
}
