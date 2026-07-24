<?php
/**
 * Get Add-ons API
 * Returns all available add-ons for a given restaurant
 * Used by: customer menu page, cart, and admin
 */

if (ob_get_level()) {
    ob_clean();
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true); // Skip timeout validation for public customer website

// Only check admin permissions if this is an admin (authenticated) request
$isAdminRequest = isset($_SESSION['user_id']) && isset($_SESSION['restaurant_id']);
if ($isAdminRequest) {
    require_once __DIR__ . '/../config/authorization_config.php';
}

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
}

// Resolve restaurant ID
$restaurant_id = $_GET['restaurant_id'] ?? $_SESSION['restaurant_id'] ?? null;

if (!$restaurant_id) {
    echo json_encode(['success' => false, 'message' => 'Restaurant ID required', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
}

// Check if we should only show available add-ons (for customer-facing)
$availableOnly = isset($_GET['available']) ? (int)$_GET['available'] === 1 : false;

try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }
    
    // Auto-create meal_addons table if it doesn't exist
    try {
        $checkTable = $conn->query("SHOW TABLES LIKE 'meal_addons'");
        if ($checkTable->rowCount() == 0) {
            $conn->exec("CREATE TABLE IF NOT EXISTS meal_addons (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id VARCHAR(50) NOT NULL,
                addon_name VARCHAR(255) NOT NULL,
                addon_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
                addon_image VARCHAR(500) DEFAULT NULL,
                image_data LONGBLOB DEFAULT NULL,
                image_mime_type VARCHAR(50) DEFAULT NULL,
                is_available TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_addon_restaurant (restaurant_id),
                INDEX idx_addon_available (restaurant_id, is_available)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    $sql = "SELECT id, addon_name, addon_price, addon_image, is_available, created_at 
            FROM meal_addons WHERE restaurant_id = ?";
    $params = [$restaurant_id];
    
    if ($availableOnly) {
        $sql .= " AND is_available = 1";
    }
    
    $sql .= " ORDER BY addon_name ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $addons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $addons,
        'count' => count($addons)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("PDO Error in get_addons.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in get_addons.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
}
