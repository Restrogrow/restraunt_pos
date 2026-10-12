<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (ob_get_level()) ob_clean();

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
// Read-only public endpoint: release the session lock right away ($_SESSION
// stays readable below) so it doesn't hold up the page's other requests.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin(true);
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database configuration not found']);
    exit();
}

try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        global $pdo;
        $conn = $pdo ?? null;
        if (!$conn) throw new Exception('No database connection');
    }

    $restaurant_id = $_GET['restaurant_id'] ?? $_SESSION['restaurant_id'] ?? '';

    if (empty($restaurant_id)) {
        echo json_encode(['success' => false, 'message' => 'No restaurant_id provided']);
        exit();
    }

    require_once __DIR__ . '/../config/coupon_helpers.php';
    ensureCouponMilestoneColumn($conn);
    ensureCouponOwnerColumn($conn);
    $valid = "restaurant_id = :rid AND is_active = 1 AND (valid_from IS NULL OR valid_from <= CURDATE()) AND (valid_until IS NULL OR valid_until >= CURDATE()) AND (max_uses = 0 OR current_uses < max_uses)";
    if (!empty($_GET['code'])) {
        // One typed-in code, private ones included (scratch card rewards; checkout checks the phone)
        $stmt = $conn->prepare("SELECT id, coupon_code, discount_type, discount_value, minimum_order_amount, description, valid_from, valid_until, is_milestone FROM coupons WHERE $valid AND coupon_code = :code LIMIT 1");
        $stmt->execute([':rid' => $restaurant_id, ':code' => strtoupper(substr((string)$_GET['code'], 0, 50))]);
    } else {
        // Private reward codes are listed only for their owner (the logged-in customer)
        $myPhone = '';
        try {
            require_once __DIR__ . '/../website/customer_session.php';
            require_once __DIR__ . '/../config/order_abuse_guard.php';
            $me = getLoggedInCustomer($conn, $restaurant_id);
            if ($me) $myPhone = orderAbuseNormalizePhone((string)$me['phone']) ?? (string)$me['phone'];
        } catch (Throwable $e) {}
        $stmt = $conn->prepare("SELECT id, coupon_code, discount_type, discount_value, minimum_order_amount, description, valid_from, valid_until, is_milestone, owner_phone IS NOT NULL AS is_personal FROM coupons WHERE $valid AND (owner_phone IS NULL OR (owner_phone = :me AND :me2 <> '')) ORDER BY owner_phone IS NULL, discount_value DESC");
        $stmt->execute([':rid' => $restaurant_id, ':me' => $myPhone, ':me2' => $myPhone]);
    }
    $coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'coupons' => $coupons]);
} catch (Exception $e) {
    error_log("Error in get_coupons.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
