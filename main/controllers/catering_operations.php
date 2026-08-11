<?php
/**
 * Catering/bulk-order operations. Two kinds of actions here:
 *  - Admin-only: manage price tiers, update a request's status/quote
 *    (requires PERMISSION_MANAGE_ORDERS, same as order management generally).
 *  - Public: submit_request - anyone can send a catering inquiry, no login
 *    required, same "public form" convention as other public submit
 *    endpoints in this codebase.
 * Mirrors main/controllers/addon_operations.php's structure otherwise.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true); // public customer website also posts here (submit_request)

if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found'], JSON_UNESCAPED_UNICODE);
    exit();
}

function ensureCateringTables($conn) {
    $conn->exec("CREATE TABLE IF NOT EXISTS catering_price_tiers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(50) NOT NULL,
        tier_label VARCHAR(100) NOT NULL,
        min_guests INT NOT NULL,
        max_guests INT NOT NULL,
        price DECIMAL(10, 2) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ctiers_restaurant (restaurant_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->exec("CREATE TABLE IF NOT EXISTS catering_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(50) NOT NULL,
        customer_id INT DEFAULT NULL,
        contact_name VARCHAR(100) NOT NULL,
        contact_phone VARCHAR(20) NOT NULL,
        event_date DATE NOT NULL,
        guest_count INT NOT NULL,
        tier_id INT DEFAULT NULL,
        quoted_price DECIMAL(10, 2) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        status ENUM('new','contacted','confirmed','completed','cancelled') NOT NULL DEFAULT 'new',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_creq_restaurant (restaurant_id, status),
        INDEX idx_creq_event_date (event_date)
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
        $conn->query("SELECT 1 FROM catering_price_tiers LIMIT 1");
        $conn->query("SELECT 1 FROM catering_requests LIMIT 1");
    } catch (PDOException $e) {
        ensureCateringTables($conn);
    }

    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    if (empty($action)) {
        throw new Exception('Action is required');
    }

    if ($action === 'submit_request') {
        // Public action - no permission/session requirement.
        handleSubmitRequest($conn);
    } else {
        // Every other action is admin/staff-only.
        require_once __DIR__ . '/../config/authorization_config.php';
        requirePermission(PERMISSION_MANAGE_ORDERS);
        $restaurant_id = $_SESSION['restaurant_id'];

        switch ($action) {
            case 'add_tier':
                handleAddTier($conn, $restaurant_id);
                break;
            case 'update_tier':
                handleUpdateTier($conn, (int)($_POST['tierId'] ?? 0), $restaurant_id);
                break;
            case 'delete_tier':
                handleDeleteTier($conn, (int)($_POST['tierId'] ?? 0), $restaurant_id);
                break;
            case 'toggle_tier_active':
                handleToggleTierActive($conn, (int)($_POST['tierId'] ?? 0), $restaurant_id);
                break;
            case 'update_request_status':
                handleUpdateRequestStatus($conn, (int)($_POST['requestId'] ?? 0), $restaurant_id);
                break;
            default:
                throw new Exception('Invalid action');
        }
    }

} catch (PDOException $e) {
    error_log("PDO Error in catering_operations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.'], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("Error in catering_operations.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit();
}

function validateTierFields($label, $min, $max, $price) {
    if (empty($label)) throw new Exception('Tier label is required');
    if ($min <= 0 || $max <= 0 || $max < $min) throw new Exception('Invalid guest count range');
    if ($price < 0) throw new Exception('Price cannot be negative');
}

function handleAddTier($conn, $restaurant_id) {
    $label = trim($_POST['tierLabel'] ?? '');
    $min = (int)($_POST['minGuests'] ?? 0);
    $max = (int)($_POST['maxGuests'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $isActive = isset($_POST['isActive']) ? (int)$_POST['isActive'] : 1;

    validateTierFields($label, $min, $max, $price);

    $stmt = $conn->prepare("INSERT INTO catering_price_tiers (restaurant_id, tier_label, min_guests, max_guests, price, is_active) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$restaurant_id, $label, $min, $max, $price, $isActive]);

    echo json_encode(['success' => true, 'message' => 'Price tier added', 'data' => ['id' => $conn->lastInsertId()]], JSON_UNESCAPED_UNICODE);
}

function handleUpdateTier($conn, $tierId, $restaurant_id) {
    if ($tierId <= 0) throw new Exception('Invalid tier ID');
    $checkStmt = $conn->prepare("SELECT id FROM catering_price_tiers WHERE id = ? AND restaurant_id = ?");
    $checkStmt->execute([$tierId, $restaurant_id]);
    if (!$checkStmt->fetch()) throw new Exception('Price tier not found');

    $label = trim($_POST['tierLabel'] ?? '');
    $min = (int)($_POST['minGuests'] ?? 0);
    $max = (int)($_POST['maxGuests'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $isActive = isset($_POST['isActive']) ? (int)$_POST['isActive'] : 1;

    validateTierFields($label, $min, $max, $price);

    $stmt = $conn->prepare("UPDATE catering_price_tiers SET tier_label = ?, min_guests = ?, max_guests = ?, price = ?, is_active = ? WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$label, $min, $max, $price, $isActive, $tierId, $restaurant_id]);

    echo json_encode(['success' => true, 'message' => 'Price tier updated'], JSON_UNESCAPED_UNICODE);
}

function handleDeleteTier($conn, $tierId, $restaurant_id) {
    if ($tierId <= 0) throw new Exception('Invalid tier ID');
    $stmt = $conn->prepare("DELETE FROM catering_price_tiers WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$tierId, $restaurant_id]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Price tier deleted'], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Price tier not found or already deleted');
    }
}

function handleToggleTierActive($conn, $tierId, $restaurant_id) {
    if ($tierId <= 0) throw new Exception('Invalid tier ID');
    $stmt = $conn->prepare("UPDATE catering_price_tiers SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$tierId, $restaurant_id]);
    if ($stmt->rowCount() > 0) {
        $fetchStmt = $conn->prepare("SELECT is_active FROM catering_price_tiers WHERE id = ?");
        $fetchStmt->execute([$tierId]);
        $row = $fetchStmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'message' => 'Status updated', 'is_active' => $row ? (int)$row['is_active'] : 1], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Price tier not found');
    }
}

function handleUpdateRequestStatus($conn, $requestId, $restaurant_id) {
    if ($requestId <= 0) throw new Exception('Invalid request ID');
    $checkStmt = $conn->prepare("SELECT id FROM catering_requests WHERE id = ? AND restaurant_id = ?");
    $checkStmt->execute([$requestId, $restaurant_id]);
    if (!$checkStmt->fetch()) throw new Exception('Request not found');

    $status = trim($_POST['status'] ?? '');
    if (!in_array($status, ['new', 'contacted', 'confirmed', 'completed', 'cancelled'], true)) {
        throw new Exception('Invalid status');
    }
    $quotedPrice = isset($_POST['quotedPrice']) && $_POST['quotedPrice'] !== '' ? (float)$_POST['quotedPrice'] : null;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;

    $stmt = $conn->prepare("UPDATE catering_requests SET status = ?, quoted_price = COALESCE(?, quoted_price), notes = COALESCE(?, notes) WHERE id = ? AND restaurant_id = ?");
    $stmt->execute([$status, $quotedPrice, $notes === '' ? null : $notes, $requestId, $restaurant_id]);

    echo json_encode(['success' => true, 'message' => 'Request updated'], JSON_UNESCAPED_UNICODE);
}

function handleSubmitRequest($conn) {
    $restaurant_id = trim($_POST['restaurant_id'] ?? '');
    $contactName = trim($_POST['contactName'] ?? '');
    $contactPhone = trim($_POST['contactPhone'] ?? '');
    $eventDate = trim($_POST['eventDate'] ?? '');
    $guestCount = (int)($_POST['guestCount'] ?? 0);
    $tierId = isset($_POST['tierId']) && $_POST['tierId'] !== '' ? (int)$_POST['tierId'] : null;
    $notes = trim($_POST['notes'] ?? '');

    if (empty($restaurant_id)) throw new Exception('Restaurant not identified');
    if (empty($contactName)) throw new Exception('Name is required');
    if (empty($contactPhone)) throw new Exception('Phone is required');
    $eventDateObj = DateTime::createFromFormat('Y-m-d', $eventDate);
    if (!$eventDateObj || $eventDateObj->format('Y-m-d') !== $eventDate) {
        throw new Exception('Please choose a valid event date');
    }
    if ($guestCount <= 0) throw new Exception('Please enter the number of guests');

    // Server-authoritative quoted price: recompute from the tier that
    // actually matches the guest count, never trust a client-sent price.
    $quotedPrice = null;
    if ($tierId) {
        $tierStmt = $conn->prepare("SELECT price FROM catering_price_tiers WHERE id = ? AND restaurant_id = ? AND is_active = 1 AND ? BETWEEN min_guests AND max_guests LIMIT 1");
        $tierStmt->execute([$tierId, $restaurant_id, $guestCount]);
        $tierRow = $tierStmt->fetch(PDO::FETCH_ASSOC);
        if ($tierRow) {
            $quotedPrice = (float)$tierRow['price'];
        } else {
            $tierId = null;
        }
    }

    // Optional: attach the logged-in customer's id if there is one, purely
    // informational - catering itself never requires login.
    $customerId = null;
    try {
        require_once __DIR__ . '/../website/customer_session.php';
        $customer = getLoggedInCustomer($conn, $restaurant_id);
        if ($customer) $customerId = (int)$customer['id'];
    } catch (Exception $e) {
    }

    $stmt = $conn->prepare("INSERT INTO catering_requests (restaurant_id, customer_id, contact_name, contact_phone, event_date, guest_count, tier_id, quoted_price, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$restaurant_id, $customerId, $contactName, $contactPhone, $eventDate, $guestCount, $tierId, $quotedPrice, $notes ?: null]);

    echo json_encode([
        'success' => true,
        'message' => 'Request submitted! We will contact you shortly.',
        'quoted_price' => $quotedPrice
    ], JSON_UNESCAPED_UNICODE);
}
