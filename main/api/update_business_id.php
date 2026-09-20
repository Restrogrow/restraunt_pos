<?php
// Lets the app's Settings screen update just the Business ID fields (show
// toggle, label, number) without going through admin/auth.php's
// updateRestaurantSettings action — that action expects the entire
// settings form's ~30 fields at once and would blank out anything not sent,
// which a small single-setting save from the app never would.
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// The app's fetch() sends credentials, which makes this a non-"simple"
// request needing a preflight — answer it before session/auth run, same as
// print_network.php and convert_receipt_image.php.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

if (ob_get_level()) {
    ob_clean();
}
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Mirrors admin/auth.php's updateRestaurantSettings gate — restaurant
// settings are only editable by the restaurant owner or a branch admin,
// never a staff (waiter/chef/manager) session. See that file's comment on
// this same check for why it's a manual session-type gate rather than
// requirePermission().
$isBranchAdmin = isset($_SESSION['branch_admin_id']);
if ((!isset($_SESSION['user_id']) && !$isBranchAdmin) || !isset($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'You must be logged in as the restaurant owner to change this setting']);
    exit;
}

try {
    require_once __DIR__ . '/../db_connection.php';
    $conn = function_exists('getConnection') ? getConnection() : ($GLOBALS['pdo'] ?? null);
    if (!$conn) {
        throw new Exception('Database connection not available');
    }

    require_once __DIR__ . '/../config/business_id_helpers.php';
    ensureBusinessIdColumns($conn);

    // Resolve the numeric users.id to update — same resolution as
    // updateRestaurantSettings: branch admins only carry restaurant_id, so
    // look up the owning account.
    if ($isBranchAdmin) {
        $linkedRestaurantIds = array_column($_SESSION['linked_restaurants'] ?? [], 'restaurant_id');
        if (!in_array($_SESSION['restaurant_id'], $linkedRestaurantIds, true)) {
            throw new Exception('You are not authorized to update this restaurant');
        }
        $ownerLookupStmt = $conn->prepare("SELECT id FROM users WHERE restaurant_id = ? LIMIT 1");
        $ownerLookupStmt->execute([$_SESSION['restaurant_id']]);
        $userId = $ownerLookupStmt->fetchColumn();
        if (!$userId) {
            throw new Exception('Restaurant not found');
        }
    } else {
        $userId = $_SESSION['user_id'];
    }

    $showBusinessId = isset($_POST['show_business_id']) ? (int)$_POST['show_business_id'] : 0;
    $businessIdLabel = isset($_POST['business_id_label']) ? trim($_POST['business_id_label']) : '';
    $businessIdNo = isset($_POST['business_id_no']) ? strtoupper(trim($_POST['business_id_no'])) : '';

    $stmt = $conn->prepare("UPDATE users SET show_business_id = ?, business_id_label = ?, business_id_no = ? WHERE id = ?");
    $stmt->execute([$showBusinessId, $businessIdLabel, $businessIdNo, $userId]);

    echo json_encode([
        'success' => true,
        'show_business_id' => $showBusinessId,
        'business_id_label' => $businessIdLabel,
        'business_id_no' => $businessIdNo,
    ]);
} catch (Exception $e) {
    error_log('Error in update_business_id.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save — please try again.']);
}
