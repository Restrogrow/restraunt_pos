<?php
/**
 * Admin-side meal subscription management: pause/resume a customer's
 * subscription on their behalf, adjust credits for support cases, and
 * manually trigger today's delivery generation for this restaurant.
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

requirePermission(PERMISSION_MANAGE_ORDERS);

require_once __DIR__ . '/../config/meal_subscription_schema.php';
require_once __DIR__ . '/../config/subscription_delivery_generator.php';

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found'], JSON_UNESCAPED_UNICODE);
    exit();
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

    ensureMealSubscriptionTables($conn);

    $restaurant_id = $_SESSION['restaurant_id'];
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';

    if (!mealSubscriptionsFeatureEnabled($conn, $restaurant_id)) {
        throw new Exception('This feature is not enabled for your account.');
    }

    if (empty($action)) {
        throw new Exception('Action is required');
    }

    switch ($action) {
        case 'pause_subscription':
            handleAdminPause($conn, $restaurant_id, (int)($_POST['subscriptionId'] ?? 0));
            break;
        case 'resume_subscription':
            handleAdminResume($conn, $restaurant_id, (int)($_POST['subscriptionId'] ?? 0));
            break;
        case 'adjust_credits':
            handleAdjustCredits($conn, $restaurant_id, (int)($_POST['subscriptionId'] ?? 0), (int)($_POST['delta'] ?? 0));
            break;
        case 'generate_today':
            handleGenerateToday($conn, $restaurant_id);
            break;
        case 'search_customers':
            handleSearchCustomers($conn, $restaurant_id);
            break;
        case 'admin_create_subscription':
            handleAdminCreateSubscription($conn, $restaurant_id);
            break;
        default:
            throw new Exception('Invalid action');
    }

} catch (PDOException $e) {
    error_log("PDO Error in meal_subscription_admin_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.'], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in meal_subscription_admin_operations.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit();
}

function loadRestaurantSubscription($conn, $restaurant_id, $subscriptionId) {
    if ($subscriptionId <= 0) {
        throw new Exception('Invalid subscription');
    }
    $stmt = $conn->prepare("SELECT * FROM customer_meal_subscriptions WHERE id = ? AND restaurant_id = ? LIMIT 1");
    $stmt->execute([$subscriptionId, $restaurant_id]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sub) {
        throw new Exception('Subscription not found');
    }
    return $sub;
}

function handleAdminPause($conn, $restaurant_id, $subscriptionId) {
    $sub = loadRestaurantSubscription($conn, $restaurant_id, $subscriptionId);
    if ($sub['status'] !== 'active') {
        throw new Exception('Only an active subscription can be paused');
    }
    $conn->prepare("UPDATE customer_meal_subscriptions SET status = 'paused', paused_at = NOW() WHERE id = ?")->execute([$subscriptionId]);
    echo json_encode(['success' => true, 'message' => 'Subscription paused'], JSON_UNESCAPED_UNICODE);
}

function handleAdminResume($conn, $restaurant_id, $subscriptionId) {
    $sub = loadRestaurantSubscription($conn, $restaurant_id, $subscriptionId);
    if ($sub['status'] !== 'paused') {
        throw new Exception('Only a paused subscription can be resumed');
    }
    $conn->prepare("UPDATE customer_meal_subscriptions SET status = 'active', paused_at = NULL WHERE id = ?")->execute([$subscriptionId]);
    echo json_encode(['success' => true, 'message' => 'Subscription resumed'], JSON_UNESCAPED_UNICODE);
}

function handleAdjustCredits($conn, $restaurant_id, $subscriptionId, $delta) {
    $sub = loadRestaurantSubscription($conn, $restaurant_id, $subscriptionId);
    if ($delta === 0) {
        throw new Exception('Enter a non-zero adjustment');
    }
    $newUsed = (int)$sub['credits_used'] - $delta; // +delta gives the customer more credit, i.e. reduces credits_used
    if ($newUsed < 0) $newUsed = 0;
    if ($newUsed > (int)$sub['credits_total']) {
        throw new Exception('Adjustment would exceed the plan\'s total credits');
    }
    $conn->prepare("UPDATE customer_meal_subscriptions SET credits_used = ? WHERE id = ?")->execute([$newUsed, $subscriptionId]);
    echo json_encode(['success' => true, 'message' => 'Credits adjusted', 'credits_used' => $newUsed], JSON_UNESCAPED_UNICODE);
}

function handleGenerateToday($conn, $restaurant_id) {
    $dateStr = date('Y-m-d');
    $result = generateSubscriptionDeliveriesForDate($conn, $restaurant_id, $dateStr);
    echo json_encode([
        'success' => true,
        'message' => "Generated {$result['generated']} deliveries for {$dateStr}",
        'result' => $result
    ], JSON_UNESCAPED_UNICODE);
}

function handleSearchCustomers($conn, $restaurant_id) {
    $search = trim($_POST['search'] ?? '');
    if ($search === '') {
        echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
        return;
    }
    $stmt = $conn->prepare("SELECT id, customer_name, phone, email FROM customers WHERE restaurant_id = ? AND (customer_name LIKE ? OR phone LIKE ?) ORDER BY customer_name ASC LIMIT 20");
    $stmt->execute([$restaurant_id, '%' . $search . '%', '%' . $search . '%']);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
}

function handleAdminCreateSubscription($conn, $restaurant_id) {
    $customerId = (int)($_POST['customerId'] ?? 0);
    $mealPlanId = (int)($_POST['mealPlanId'] ?? 0);
    $deliveryAddress = trim($_POST['deliveryAddress'] ?? '');
    $deliveryPhone = trim($_POST['deliveryPhone'] ?? '');
    $paymentMethod = trim($_POST['paymentMethod'] ?? 'Cash');
    $markPaid = isset($_POST['markPaid']) && (int)$_POST['markPaid'] === 1;

    if ($customerId <= 0) {
        throw new Exception('Please select a customer');
    }
    if ($mealPlanId <= 0) {
        throw new Exception('Please choose a plan');
    }
    if (empty($deliveryAddress)) {
        throw new Exception('Delivery address is required');
    }
    if (empty($deliveryPhone)) {
        throw new Exception('Delivery phone is required');
    }
    if (!in_array($paymentMethod, ['Cash', 'QR Payment', 'UPI / NetBanking'], true)) {
        throw new Exception('Invalid payment method');
    }

    // Customer must belong to this restaurant - staff can't subscribe a
    // customer that isn't theirs, even by guessing an id.
    $custStmt = $conn->prepare("SELECT id FROM customers WHERE id = ? AND restaurant_id = ? LIMIT 1");
    $custStmt->execute([$customerId, $restaurant_id]);
    if (!$custStmt->fetch()) {
        throw new Exception('Customer not found');
    }

    $planStmt = $conn->prepare("SELECT * FROM meal_plans WHERE id = ? AND restaurant_id = ? AND is_active = 1 LIMIT 1");
    $planStmt->execute([$mealPlanId, $restaurant_id]);
    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        throw new Exception('This plan is no longer available');
    }

    $totalCredits = (int)$plan['total_meal_credits'] + (int)$plan['bonus_credits'];
    $amountPaid = (float)$plan['price'];

    // Staff creating this in person (e.g. a walk-in customer paying cash or
    // scanning the QR at the counter) can mark it paid immediately instead
    // of leaving it 'Pending' for later reconciliation - unlike the
    // self-serve flow, staff are directly confirming they received payment.
    $paymentStatus = $markPaid ? 'Success' : 'Pending';

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare("INSERT INTO customer_meal_subscriptions
            (restaurant_id, customer_id, meal_plan_id, plan_name_snapshot, meal_scope_snapshot, credits_total, credits_used, amount_paid, delivery_address, delivery_phone, status)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 'active')");
        $stmt->execute([$restaurant_id, $customerId, $mealPlanId, $plan['plan_name'], $plan['meal_scope'], $totalCredits, $amountPaid, $deliveryAddress, $deliveryPhone]);
        $subscriptionId = (int)$conn->lastInsertId();

        $payStmt = $conn->prepare("INSERT INTO meal_plan_payments (restaurant_id, subscription_id, amount, payment_method, payment_status, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $payStmt->execute([$restaurant_id, $subscriptionId, $amountPaid, $paymentMethod, $paymentStatus, 'Created by staff']);

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Subscription created',
        'subscription_id' => $subscriptionId
    ], JSON_UNESCAPED_UNICODE);
}
