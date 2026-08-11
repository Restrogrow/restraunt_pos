<?php
/**
 * Customer Meal Subscription Operations Controller
 * Handles a logged-in customer subscribing to a meal plan, and managing
 * their subscription (pause/resume/skip dates). All actions are scoped to
 * the session's own customer_id - never a client-supplied one - the same
 * rule get_addons.php documents for restaurant_id.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true); // public customer website, not staff timeout rules

if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../website/customer_session.php';
require_once __DIR__ . '/../config/meal_subscription_schema.php';

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

    $restaurant_id = $_SESSION['restaurant_id'] ?? ($_POST['restaurant_id'] ?? null);
    if (!$restaurant_id) {
        throw new Exception('Restaurant not identified');
    }

    ensureMealSubscriptionTables($conn);

    if (!mealSubscriptionsFeatureEnabled($conn, $restaurant_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This restaurant does not offer meal subscriptions'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $customer = getLoggedInCustomer($conn, $restaurant_id);
    if (!$customer) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Please log in to manage a subscription'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    $customer_id = (int)$customer['id'];

    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    if (empty($action)) {
        throw new Exception('Action is required');
    }

    switch ($action) {
        case 'create_subscription':
            handleCreateSubscription($conn, $restaurant_id, $customer_id);
            break;
        case 'pause_subscription':
            handlePause($conn, $customer_id, (int)($_POST['subscriptionId'] ?? 0));
            break;
        case 'resume_subscription':
            handleResume($conn, $customer_id, (int)($_POST['subscriptionId'] ?? 0));
            break;
        case 'add_skip_date':
            handleAddSkipDate($conn, $customer_id, (int)($_POST['subscriptionId'] ?? 0), trim($_POST['skipDate'] ?? ''));
            break;
        case 'remove_skip_date':
            handleRemoveSkipDate($conn, $customer_id, (int)($_POST['subscriptionId'] ?? 0), trim($_POST['skipDate'] ?? ''));
            break;
        default:
            throw new Exception('Invalid action');
    }

} catch (PDOException $e) {
    error_log("PDO Error in meal_subscription_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.'], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in meal_subscription_operations.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit();
}

function handleCreateSubscription($conn, $restaurant_id, $customer_id) {
    $mealPlanId = (int)($_POST['mealPlanId'] ?? 0);
    $deliveryAddress = trim($_POST['deliveryAddress'] ?? '');
    $deliveryPhone = trim($_POST['deliveryPhone'] ?? '');
    $paymentMethod = trim($_POST['paymentMethod'] ?? 'Cash');

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

    $planStmt = $conn->prepare("SELECT * FROM meal_plans WHERE id = ? AND restaurant_id = ? AND is_active = 1 LIMIT 1");
    $planStmt->execute([$mealPlanId, $restaurant_id]);
    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        throw new Exception('This plan is no longer available');
    }

    // Server-authoritative pricing/credits - never trust anything the client
    // sends about plan price or credit count, same principle as
    // process_website_order.php re-pricing every cart item from the DB.
    $totalCredits = (int)$plan['total_meal_credits'] + (int)$plan['bonus_credits'];
    $amountPaid = (float)$plan['price'];

    // PhonePe payments start pending until phonepe_subscription_payment.php's
    // callback/poll confirms them; Cash and QR-proof are trusted up front the
    // same way process_website_order.php treats COD and business-QR orders -
    // the customer can start using the plan immediately, staff reconcile the
    // actual payment afterward.
    $usePhonePe = ($paymentMethod === 'UPI / NetBanking');
    $status = $usePhonePe ? 'pending_payment' : 'active';
    // Every method starts 'Pending' here - Cash/QR-proof get reconciled by
    // staff afterward (subscription is already usable), PhonePe gets flipped
    // to 'Success' by phonepe_subscription_payment.php's callback/poll.
    $paymentStatus = 'Pending';

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare("INSERT INTO customer_meal_subscriptions
            (restaurant_id, customer_id, meal_plan_id, plan_name_snapshot, meal_scope_snapshot, credits_total, credits_used, amount_paid, delivery_address, delivery_phone, status)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)");
        $stmt->execute([$restaurant_id, $customer_id, $mealPlanId, $plan['plan_name'], $plan['meal_scope'], $totalCredits, $amountPaid, $deliveryAddress, $deliveryPhone, $status]);
        $subscriptionId = (int)$conn->lastInsertId();

        // Optional Business-QR payment screenshot, same base64 convention as
        // process_website_order.php's payment_proof_base64.
        $proofBytes = null;
        $proofMime = null;
        $proofInput = $_POST['paymentProofBase64'] ?? '';
        if ($proofInput !== '' && preg_match('/^data:(image\/(?:jpeg|jpg|png|webp|gif));base64,(.+)$/i', $proofInput, $m)) {
            $decoded = base64_decode($m[2], true);
            if ($decoded !== false && strlen($decoded) > 0 && strlen($decoded) <= 5 * 1024 * 1024) {
                $proofBytes = $decoded;
                $proofMime = strtolower($m[1]) === 'image/jpg' ? 'image/jpeg' : strtolower($m[1]);
            }
        }

        $payStmt = $conn->prepare("INSERT INTO meal_plan_payments (restaurant_id, subscription_id, amount, payment_method, payment_status, payment_proof_image, payment_proof_mime) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $payStmt->execute([$restaurant_id, $subscriptionId, $amountPaid, $paymentMethod, $paymentStatus, $proofBytes, $proofMime]);
        $paymentId = (int)$conn->lastInsertId();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

    echo json_encode([
        'success' => true,
        'subscription_id' => $subscriptionId,
        'payment_id' => $paymentId,
        'status' => $status,
        'phonepe_required' => $usePhonePe,
        'message' => $usePhonePe ? 'Redirecting to payment...' : 'Subscribed successfully!'
    ], JSON_UNESCAPED_UNICODE);
}

function loadOwnSubscription($conn, $customer_id, $subscriptionId) {
    if ($subscriptionId <= 0) {
        throw new Exception('Invalid subscription');
    }
    $stmt = $conn->prepare("SELECT * FROM customer_meal_subscriptions WHERE id = ? AND customer_id = ? LIMIT 1");
    $stmt->execute([$subscriptionId, $customer_id]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sub) {
        throw new Exception('Subscription not found');
    }
    return $sub;
}

function handlePause($conn, $customer_id, $subscriptionId) {
    $sub = loadOwnSubscription($conn, $customer_id, $subscriptionId);
    if ($sub['status'] !== 'active') {
        throw new Exception('Only an active subscription can be paused');
    }
    $stmt = $conn->prepare("UPDATE customer_meal_subscriptions SET status = 'paused', paused_at = NOW() WHERE id = ?");
    $stmt->execute([$subscriptionId]);
    echo json_encode(['success' => true, 'message' => 'Subscription paused'], JSON_UNESCAPED_UNICODE);
}

function handleResume($conn, $customer_id, $subscriptionId) {
    $sub = loadOwnSubscription($conn, $customer_id, $subscriptionId);
    if ($sub['status'] !== 'paused') {
        throw new Exception('Only a paused subscription can be resumed');
    }
    $stmt = $conn->prepare("UPDATE customer_meal_subscriptions SET status = 'active', paused_at = NULL WHERE id = ?");
    $stmt->execute([$subscriptionId]);
    echo json_encode(['success' => true, 'message' => 'Subscription resumed'], JSON_UNESCAPED_UNICODE);
}

function handleAddSkipDate($conn, $customer_id, $subscriptionId, $skipDate) {
    loadOwnSubscription($conn, $customer_id, $subscriptionId);
    $d = DateTime::createFromFormat('Y-m-d', $skipDate);
    if (!$d || $d->format('Y-m-d') !== $skipDate) {
        throw new Exception('Invalid date');
    }
    $today = new DateTime('today');
    if ($d < $today) {
        throw new Exception('Cannot skip a date in the past');
    }
    $stmt = $conn->prepare("INSERT IGNORE INTO meal_subscription_skip_dates (subscription_id, skip_date) VALUES (?, ?)");
    $stmt->execute([$subscriptionId, $skipDate]);
    echo json_encode(['success' => true, 'message' => 'That date will be skipped'], JSON_UNESCAPED_UNICODE);
}

function handleRemoveSkipDate($conn, $customer_id, $subscriptionId, $skipDate) {
    loadOwnSubscription($conn, $customer_id, $subscriptionId);
    $stmt = $conn->prepare("DELETE FROM meal_subscription_skip_dates WHERE subscription_id = ? AND skip_date = ?");
    $stmt->execute([$subscriptionId, $skipDate]);
    echo json_encode(['success' => true, 'message' => 'Skip removed'], JSON_UNESCAPED_UNICODE);
}
