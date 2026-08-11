<?php
/**
 * Meal Plan (tiffin/subscription bundle) Operations Controller
 * Handles CRUD for meal_plans and their weekly menu (meal_plan_weekly_menu).
 * Mirrors main/controllers/addon_operations.php's structure.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

require_once __DIR__ . '/../config/authorization_config.php';

if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Plan/weekly-menu content is menu-adjacent, so it reuses the existing menu
// permission rather than introducing a new permission constant just for this.
requirePermission(PERMISSION_MANAGE_MENU);

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found'], JSON_UNESCAPED_UNICODE);
    exit();
}

function ensureMealPlanTables($conn) {
    $conn->exec("CREATE TABLE IF NOT EXISTS meal_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(50) NOT NULL,
        plan_name VARCHAR(150) NOT NULL,
        description TEXT DEFAULT NULL,
        meal_scope ENUM('lunch','dinner','both') NOT NULL DEFAULT 'both',
        total_meal_credits INT NOT NULL,
        price DECIMAL(10, 2) NOT NULL,
        bonus_credits INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_mp_restaurant (restaurant_id),
        INDEX idx_mp_active (restaurant_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->exec("CREATE TABLE IF NOT EXISTS meal_plan_weekly_menu (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(50) NOT NULL,
        day_of_week TINYINT NOT NULL,
        meal_time ENUM('lunch','dinner') NOT NULL,
        menu_text TEXT NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_mpwm_cell (restaurant_id, day_of_week, meal_time),
        INDEX idx_mpwm_restaurant (restaurant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }

    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }

    try {
        $conn->query("SELECT 1 FROM meal_plans LIMIT 1");
        $conn->query("SELECT 1 FROM meal_plan_weekly_menu LIMIT 1");
    } catch (PDOException $e) {
        ensureMealPlanTables($conn);
    }

    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    $restaurant_id = $_SESSION['restaurant_id'];

    require_once __DIR__ . '/../config/meal_subscription_schema.php';
    if (!mealSubscriptionsFeatureEnabled($conn, $restaurant_id)) {
        throw new Exception('This feature is not enabled for your account.');
    }

    if (empty($action)) {
        throw new Exception('Action is required');
    }

    switch ($action) {
        case 'add_plan':
            handleAddPlan($conn, $restaurant_id);
            break;
        case 'update_plan':
            handleUpdatePlan($conn, (int)($_POST['planId'] ?? 0), $restaurant_id);
            break;
        case 'delete_plan':
            handleDeletePlan($conn, (int)($_POST['planId'] ?? 0), $restaurant_id);
            break;
        case 'toggle_plan_active':
            handleTogglePlanActive($conn, (int)($_POST['planId'] ?? 0), $restaurant_id);
            break;
        case 'save_weekly_menu_cell':
            handleSaveWeeklyMenuCell($conn, $restaurant_id);
            break;
        case 'clear_weekly_menu_cell':
            handleClearWeeklyMenuCell($conn, $restaurant_id);
            break;
        default:
            throw new Exception('Invalid action');
    }

} catch (PDOException $e) {
    error_log("PDO Error in meal_plan_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.'], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in meal_plan_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit();
}

function validatePlanFields($planName, $mealScope, $totalCredits, $price) {
    if (empty($planName)) {
        throw new Exception('Plan name is required');
    }
    if (!in_array($mealScope, ['lunch', 'dinner', 'both'], true)) {
        throw new Exception('Invalid meal scope');
    }
    if ($totalCredits <= 0) {
        throw new Exception('Total meal credits must be greater than 0');
    }
    if ($price < 0) {
        throw new Exception('Price cannot be negative');
    }
}

function handleAddPlan($conn, $restaurant_id) {
    $planName = trim($_POST['planName'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $mealScope = trim($_POST['mealScope'] ?? 'both');
    $totalCredits = (int)($_POST['totalCredits'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $bonusCredits = (int)($_POST['bonusCredits'] ?? 0);
    $isActive = isset($_POST['isActive']) ? (int)$_POST['isActive'] : 1;

    validatePlanFields($planName, $mealScope, $totalCredits, $price);
    if ($bonusCredits < 0) {
        throw new Exception('Bonus credits cannot be negative');
    }

    $stmt = $conn->prepare("INSERT INTO meal_plans (restaurant_id, plan_name, description, meal_scope, total_meal_credits, price, bonus_credits, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$restaurant_id, $planName, $description ?: null, $mealScope, $totalCredits, $price, $bonusCredits, $isActive]);

    echo json_encode([
        'success' => true,
        'message' => 'Plan added successfully',
        'data' => ['id' => $conn->lastInsertId(), 'plan_name' => $planName]
    ], JSON_UNESCAPED_UNICODE);
}

function handleUpdatePlan($conn, $planId, $restaurant_id) {
    if ($planId <= 0) {
        throw new Exception('Invalid plan ID');
    }

    $checkStmt = $conn->prepare("SELECT id FROM meal_plans WHERE id = ? AND restaurant_id = ?");
    $checkStmt->execute([$planId, $restaurant_id]);
    if (!$checkStmt->fetch()) {
        throw new Exception('Plan not found');
    }

    $planName = trim($_POST['planName'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $mealScope = trim($_POST['mealScope'] ?? 'both');
    $totalCredits = (int)($_POST['totalCredits'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $bonusCredits = (int)($_POST['bonusCredits'] ?? 0);
    $isActive = isset($_POST['isActive']) ? (int)$_POST['isActive'] : 1;

    validatePlanFields($planName, $mealScope, $totalCredits, $price);
    if ($bonusCredits < 0) {
        throw new Exception('Bonus credits cannot be negative');
    }

    $stmt = $conn->prepare("UPDATE meal_plans SET plan_name = ?, description = ?, meal_scope = ?, total_meal_credits = ?, price = ?, bonus_credits = ?, is_active = ? WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$planName, $description ?: null, $mealScope, $totalCredits, $price, $bonusCredits, $isActive, $planId, $restaurant_id]);

    echo json_encode([
        'success' => true,
        'message' => 'Plan updated successfully',
        'data' => ['id' => $planId, 'plan_name' => $planName]
    ], JSON_UNESCAPED_UNICODE);
}

function handleDeletePlan($conn, $planId, $restaurant_id) {
    if ($planId <= 0) {
        throw new Exception('Invalid plan ID');
    }

    // Existing customer subscriptions reference meal_plans with ON DELETE
    // RESTRICT (see customer_meal_subscriptions FK) - a plan that already has
    // subscribers can't be hard-deleted, only deactivated, so past/active
    // subscriptions never lose their plan reference.
    $subCheck = $conn->prepare("SELECT COUNT(*) FROM customer_meal_subscriptions WHERE meal_plan_id = ?");
    try {
        $subCheck->execute([$planId]);
        if ((int)$subCheck->fetchColumn() > 0) {
            throw new Exception('This plan has active or past subscribers and cannot be deleted. Deactivate it instead.');
        }
    } catch (PDOException $e) {
        // customer_meal_subscriptions table doesn't exist yet - nothing references this plan, safe to proceed
    }

    $stmt = $conn->prepare("DELETE FROM meal_plans WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$planId, $restaurant_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Plan deleted successfully'], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Plan not found or already deleted');
    }
}

function handleTogglePlanActive($conn, $planId, $restaurant_id) {
    if ($planId <= 0) {
        throw new Exception('Invalid plan ID');
    }

    $stmt = $conn->prepare("UPDATE meal_plans SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$planId, $restaurant_id]);

    if ($stmt->rowCount() > 0) {
        $fetchStmt = $conn->prepare("SELECT is_active FROM meal_plans WHERE id = ?");
        $fetchStmt->execute([$planId]);
        $row = $fetchStmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true,
            'message' => 'Plan status updated',
            'is_active' => $row ? (int)$row['is_active'] : 1
        ], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Plan not found');
    }
}

function handleSaveWeeklyMenuCell($conn, $restaurant_id) {
    $dayOfWeek = (int)($_POST['dayOfWeek'] ?? -1);
    $mealTime = trim($_POST['mealTime'] ?? '');
    $menuText = trim($_POST['menuText'] ?? '');

    if ($dayOfWeek < 0 || $dayOfWeek > 6) {
        throw new Exception('Invalid day of week');
    }
    if (!in_array($mealTime, ['lunch', 'dinner'], true)) {
        throw new Exception('Invalid meal time');
    }
    if ($menuText === '') {
        throw new Exception('Menu text is required');
    }

    $stmt = $conn->prepare("INSERT INTO meal_plan_weekly_menu (restaurant_id, day_of_week, meal_time, menu_text, is_active)
        VALUES (?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE menu_text = VALUES(menu_text), is_active = 1");
    $stmt->execute([$restaurant_id, $dayOfWeek, $mealTime, $menuText]);

    echo json_encode(['success' => true, 'message' => 'Weekly menu updated'], JSON_UNESCAPED_UNICODE);
}

function handleClearWeeklyMenuCell($conn, $restaurant_id) {
    $dayOfWeek = (int)($_POST['dayOfWeek'] ?? -1);
    $mealTime = trim($_POST['mealTime'] ?? '');

    if ($dayOfWeek < 0 || $dayOfWeek > 6) {
        throw new Exception('Invalid day of week');
    }
    if (!in_array($mealTime, ['lunch', 'dinner'], true)) {
        throw new Exception('Invalid meal time');
    }

    $stmt = $conn->prepare("DELETE FROM meal_plan_weekly_menu WHERE restaurant_id = ? AND day_of_week = ? AND meal_time = ?");
    $stmt->execute([$restaurant_id, $dayOfWeek, $mealTime]);

    echo json_encode(['success' => true, 'message' => 'Cell cleared'], JSON_UNESCAPED_UNICODE);
}
