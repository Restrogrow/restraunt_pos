<?php
/**
 * Customer Meal Subscription Operations Controller
 * Handles a logged-in customer subscribing to a meal plan, and managing
 * their subscription (pause/resume/skip dates/delivery address). All actions are scoped to
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

    // The restaurant whose website sent this (subscribe.php / my-subscription.php post it), then
    // the one the customer is logged in to. $_SESSION['restaurant_id'] came first before: it is
    // never set on slug / custom-domain pages and holds the owner's own restaurant for a
    // dashboard login, so subscribing failed or went to the wrong restaurant. Safe to take
    // from the request: everything below needs a customer logged in to that same restaurant.
    $restaurant_id = (isset($_POST['restaurant_id']) && is_string($_POST['restaurant_id']) && trim($_POST['restaurant_id']) !== '')
        ? trim($_POST['restaurant_id'])
        : ($_SESSION['customer_restaurant_id'] ?? ($_SESSION['restaurant_id'] ?? null));
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
        case 'update_delivery':
            handleUpdateDelivery($conn, $customer_id, (int)($_POST['subscriptionId'] ?? 0));
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
    $deliveryLat = isset($_POST['deliveryLat']) && $_POST['deliveryLat'] !== '' ? (float)$_POST['deliveryLat'] : null;
    $deliveryLng = isset($_POST['deliveryLng']) && $_POST['deliveryLng'] !== '' ? (float)$_POST['deliveryLng'] : null;
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

    // The restaurant's payment settings, same rules as a website order (process_website_order.php):
    // online payment only where it can really be used, QR only with a business QR, and no Cash
    // when COD is switched off (unless nothing else is set up)
    $payRow = [];
    try {
        $pr = $conn->prepare("SELECT * FROM users WHERE restaurant_id = ? LIMIT 1");
        $pr->execute([$restaurant_id]);
        $payRow = $pr->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {}
    require_once __DIR__ . '/../config/phonepe_verify.php';
    $ppCfg = phonepeCallbackConfig($restaurant_id);
    $gwType = (string)($payRow['payment_gateway_type'] ?? '');
    $onlineReady = !($gwType === '' || $gwType === 'cash_only' || (!$ppCfg['ready'] && !$ppCfg['demo']));
    $hasQr = isset($payRow['business_qr_code_data']);   // IS NOT NULL, as the subscribe page checks it
    if ($paymentMethod === 'UPI / NetBanking' && !$onlineReady) {
        throw new Exception('Online payment is not available at this restaurant right now. Please choose another way to pay.');
    }
    if ($paymentMethod === 'QR Payment' && !$hasQr) {
        throw new Exception('Paying by QR is not available here. Please choose another way to pay.');
    }
    if ($paymentMethod === 'Cash' && isset($payRow['cod_enabled']) && (int)$payRow['cod_enabled'] === 0 && ($onlineReady || $hasQr)) {
        throw new Exception('Cash is not accepted for meal plans here. Please choose an online payment method.');
    }
    unset($payRow);

    // Customer-chosen delivery start date - defaults to tomorrow (matching
    // subscribe.php's pre-filled date input) when missing/invalid, and can
    // never be earlier than tomorrow so kitchen isn't asked to start same-day.
    // (tomorrow on the restaurant's own clock)
    $tomorrow = mealPlanToday($conn, $restaurant_id, 1);
    $startDateInput = trim($_POST['startDate'] ?? '');
    $startDateObj = DateTime::createFromFormat('Y-m-d', $startDateInput);
    $startDate = ($startDateObj && $startDateObj->format('Y-m-d') === $startDateInput && $startDateInput >= $tomorrow) ? $startDateInput : $tomorrow;

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
            (restaurant_id, customer_id, meal_plan_id, plan_name_snapshot, meal_scope_snapshot, credits_total, credits_used, amount_paid, delivery_address, delivery_phone, delivery_lat, delivery_lng, status, start_date)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$restaurant_id, $customer_id, $mealPlanId, $plan['plan_name'], $plan['meal_scope'], $totalCredits, $amountPaid, $deliveryAddress, $deliveryPhone, $deliveryLat, $deliveryLng, $status, $startDate]);
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
    $sub = loadOwnSubscription($conn, $customer_id, $subscriptionId);
    mealSubAddSkip($conn, $sub, $skipDate);
    echo json_encode(['success' => true, 'message' => 'That date will be skipped'], JSON_UNESCAPED_UNICODE);
}

function handleRemoveSkipDate($conn, $customer_id, $subscriptionId, $skipDate) {
    $sub = loadOwnSubscription($conn, $customer_id, $subscriptionId);
    mealSubRemoveSkip($conn, $sub, $skipDate);
    echo json_encode(['success' => true, 'message' => 'Skip removed'], JSON_UNESCAPED_UNICODE);
}

function handleUpdateDelivery($conn, $customer_id, $subscriptionId) {
    $sub = loadOwnSubscription($conn, $customer_id, $subscriptionId);
    mealSubUpdateDelivery($conn, $sub, $_POST['deliveryAddress'] ?? '', $_POST['deliveryPhone'] ?? '');
    echo json_encode(['success' => true, 'message' => 'Delivery details updated'], JSON_UNESCAPED_UNICODE);
}
