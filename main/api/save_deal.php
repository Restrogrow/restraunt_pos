<?php
/**
 * Save a deal (create or update)
 * POST: deal_type (combo/new), menu_id, restaurant_id
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json');

// Require admin/staff login for dashboard operations
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

try {
    $conn = getConnection();
    
    // Only allow POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $restaurant_id = $_POST['restaurant_id'] ?? $_SESSION['restaurant_id'] ?? '';
    $deal_type = $_POST['deal_type'] ?? '';
    $menu_id = (int)($_POST['menu_id'] ?? 0);
    $action = $_POST['action'] ?? 'create'; // create or delete
    $deal_id = (int)($_POST['deal_id'] ?? 0);
    
    if (empty($restaurant_id)) {
        throw new Exception('Restaurant ID is required');
    }
    
    if ($action === 'delete') {
        if (!$deal_id) throw new Exception('Deal ID required');
        $stmt = $conn->prepare("DELETE FROM deals WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$deal_id, $restaurant_id]);
        echo json_encode(['success' => true, 'message' => 'Deal deleted']);
        exit;
    }
    
    if (!in_array($deal_type, ['combo', 'new'])) {
        throw new Exception('Invalid deal type');
    }
    if (!$menu_id) {
        throw new Exception('Menu is required');
    }
    
    // Check if menu exists
    $check = $conn->prepare("SELECT id FROM menu WHERE id = ? AND restaurant_id = ?");
    $check->execute([$menu_id, $restaurant_id]);
    if (!$check->fetch()) {
        throw new Exception('Selected menu not found');
    }
    
    // Only one combo and one new deal allowed per restaurant
    $check = $conn->prepare("SELECT id, menu_id FROM deals WHERE restaurant_id = ? AND deal_type = ?");
    $check->execute([$restaurant_id, $deal_type]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        throw new Exception('A ' . $deal_type . ' deal already exists. Delete it first to create a new one.');
    }
    
    // Insert new deal
    $stmt = $conn->prepare("INSERT INTO deals (restaurant_id, deal_type, menu_id) VALUES (?, ?, ?)");
    $stmt->execute([$restaurant_id, $deal_type, $menu_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Deal created successfully',
        'deal_id' => $conn->lastInsertId()
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    error_log("Error in save_deal.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
