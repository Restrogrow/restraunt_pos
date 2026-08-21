<?php
/**
 * Get Meal Plans API
 * Returns meal_plans (+ optionally the weekly menu grid) for a restaurant.
 * Used by: admin plan editor, customer plans.php browse page.
 * Mirrors main/api/get_addons.php.
 */

if (ob_get_level()) {
    ob_clean();
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true); // Skip timeout validation for public customer website

$isAdminRequest = isset($_SESSION['user_id']) && isset($_SESSION['restaurant_id']);

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

// Same trust rule as get_addons.php: an authenticated admin request always
// uses its own session restaurant_id, never a client-supplied one. Anonymous
// public requests (customer plans page) pass restaurant_id explicitly.
if ($isAdminRequest) {
    $restaurant_id = $_SESSION['restaurant_id'];
} else {
    $restaurant_id = $_GET['restaurant_id'] ?? null;
}

if (!$restaurant_id) {
    echo json_encode(['success' => false, 'message' => 'Restaurant ID required', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
}

// Admin plan editor wants every plan (active + inactive); the public
// customer-facing plans page only wants ones customers can actually buy.
$activeOnly = !$isAdminRequest || (isset($_GET['active']) && (int)$_GET['active'] === 1);
$includeMenu = isset($_GET['include_menu']) && (int)$_GET['include_menu'] === 1;

try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }

    // Unlike the write-path controllers, this read endpoint never checked
    // whether the superadmin has actually turned the feature on for this
    // restaurant — a customer could still read plan names/prices via a
    // direct request even after it was disabled. Only gates the public
    // customer-facing read; the admin plan editor can still see its own
    // (disabled) plans to review/re-enable them.
    if (!$isAdminRequest) {
        require_once __DIR__ . '/../config/meal_subscription_schema.php';
        if (!mealSubscriptionsFeatureEnabled($conn, $restaurant_id)) {
            echo json_encode(['success' => true, 'data' => [], 'weekly_menu' => []], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }

    try {
        $checkTable = $conn->query("SHOW TABLES LIKE 'meal_plans'");
        if ($checkTable->rowCount() == 0) {
            echo json_encode(['success' => true, 'data' => [], 'weekly_menu' => []], JSON_UNESCAPED_UNICODE);
            exit();
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => true, 'data' => [], 'weekly_menu' => []], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $sql = "SELECT id, plan_name, description, meal_scope, total_meal_credits, price, bonus_credits, is_active, sort_order,
                   (image_data IS NOT NULL) AS has_image
            FROM meal_plans WHERE restaurant_id = ?";
    $params = [$restaurant_id];
    if ($activeOnly) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY sort_order ASC, price ASC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // image_data column may not exist yet on this DB - retry without it
        if (strpos($e->getMessage(), 'Unknown column') === false) throw $e;
        $sql = str_replace(",\n                   (image_data IS NOT NULL) AS has_image", '', $sql);
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($plans as &$plan) {
        $plan['image_url'] = !empty($plan['has_image']) ? '../api/image.php?type=meal_plan&id=' . urlencode($plan['id']) : null;
        unset($plan['has_image']);
    }
    unset($plan);

    $response = ['success' => true, 'data' => $plans, 'count' => count($plans)];

    if ($includeMenu) {
        try {
            $menuStmt = $conn->prepare("SELECT day_of_week, meal_time, menu_text FROM meal_plan_weekly_menu WHERE restaurant_id = ? AND is_active = 1 ORDER BY day_of_week ASC, meal_time ASC");
            $menuStmt->execute([$restaurant_id]);
            $response['weekly_menu'] = $menuStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $response['weekly_menu'] = [];
        }

        try {
            $itemsStmt = $conn->prepare("SELECT id, day_of_week, meal_time, item_name, (image_data IS NOT NULL) AS has_image FROM meal_plan_weekly_menu_items WHERE restaurant_id = ? AND is_active = 1 ORDER BY day_of_week ASC, meal_time ASC, sort_order ASC");
            $itemsStmt->execute([$restaurant_id]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as &$item) {
                $item['image_url'] = !empty($item['has_image']) ? '../api/image.php?type=weekly_menu_item&id=' . urlencode($item['id']) : null;
                unset($item['has_image']);
            }
            unset($item);
            $response['weekly_menu_items'] = $items;
        } catch (PDOException $e) {
            $response['weekly_menu_items'] = [];
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("PDO Error in get_meal_plans.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in get_meal_plans.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit();
}
