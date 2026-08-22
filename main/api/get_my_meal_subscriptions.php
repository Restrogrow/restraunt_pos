<?php
/**
 * Returns the logged-in customer's own meal-plan subscriptions (with skip
 * dates) for this restaurant. Never accepts a client-supplied customer_id -
 * always the session's own logged-in customer, same rule as
 * meal_subscription_operations.php.
 */

if (ob_get_level()) {
    ob_clean();
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../website/customer_session.php';
require_once __DIR__ . '/../config/meal_subscription_schema.php';

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }

    $restaurant_id = $_SESSION['restaurant_id'] ?? ($_GET['restaurant_id'] ?? null);
    if (!$restaurant_id) {
        echo json_encode(['success' => false, 'message' => 'Restaurant not identified', 'data' => []], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $customer = getLoggedInCustomer($conn, $restaurant_id);
    if (!$customer) {
        echo json_encode(['success' => false, 'message' => 'Not logged in', 'data' => []], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $checkTable = $conn->query("SHOW TABLES LIKE 'customer_meal_subscriptions'");
        if ($checkTable->rowCount() == 0) {
            echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
            exit();
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmt = $conn->prepare("SELECT id, meal_plan_id, plan_name_snapshot, meal_scope_snapshot, credits_total, credits_used,
            amount_paid, delivery_address, delivery_phone, status, paused_at, start_date, created_at
        FROM customer_meal_subscriptions
        WHERE restaurant_id = ? AND customer_id = ?
        ORDER BY created_at DESC");
    $stmt->execute([$restaurant_id, (int)$customer['id']]);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($subscriptions) {
        $ids = array_column($subscriptions, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $skipStmt = $conn->prepare("SELECT subscription_id, skip_date FROM meal_subscription_skip_dates WHERE subscription_id IN ($placeholders) AND skip_date >= CURDATE() ORDER BY skip_date ASC");
        $skipStmt->execute($ids);
        $skipsBySub = [];
        foreach ($skipStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $skipsBySub[$row['subscription_id']][] = $row['skip_date'];
        }
        foreach ($subscriptions as &$sub) {
            $sub['credits_remaining'] = max(0, (int)$sub['credits_total'] - (int)$sub['credits_used']);
            $sub['skip_dates'] = $skipsBySub[$sub['id']] ?? [];
        }
        unset($sub);
    }

    echo json_encode(['success' => true, 'data' => $subscriptions], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("PDO Error in get_my_meal_subscriptions.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in get_my_meal_subscriptions.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred.', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
}
