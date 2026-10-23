<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (ob_get_level()) {
    ob_clean();
}

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'A fatal error occurred: ' . $error['message'],
            'data' => []
        ]);
    }
});

try {
    require_once __DIR__ . '/../config/session_config.php';
    startSecureSession();
    require_once __DIR__ . '/../config/authorization_config.php';
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config/cors_helper.php'; allowAppOrigin(true); // app dev preview gets credentials, everyone else keeps *
    header('Access-Control-Allow-Methods: GET');
    header('Access-Control-Allow-Headers: Content-Type');
} catch (Throwable $e) {
    error_log('get_table_status.php init: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.', 'data' => []]);
    exit();
}

$requested_restaurant_id = is_string($_GET['restaurant_id'] ?? null) ? $_GET['restaurant_id'] : null;

$isStaff = isLoggedIn();
if ($isStaff) {
    // Authenticated staff/admin: always scope to the session's own tenant.
    // Never trust a client-supplied restaurant_id here, even if one is present,
    // otherwise one restaurant's staff could read another restaurant's table status.
    $restaurant_id = $_SESSION['restaurant_id'];
} elseif ($requested_restaurant_id) {
    // Anonymous/public access (e.g. customer QR ordering flow) is allowed by design;
    // restaurant_id from the request is the only option in this branch.
    $restaurant_id = $requested_restaurant_id;
} else {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Restaurant ID required', 'data' => []]);
    exit();
}
session_write_close(); // read-only endpoint

if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found', 'data' => []]);
    exit();
}

try {
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        $conn = $pdo ?? null;
        if (!$conn) throw new Exception('Database connection not available');
    }

    // Get all tables with area names
    $stmt = $conn->prepare("SELECT t.*, a.area_name FROM tables t JOIN areas a ON t.area_id = a.id WHERE t.restaurant_id = ? ORDER BY a.id ASC, t.sort_order ASC, t.created_at DESC");
    $stmt->execute([$restaurant_id]);
    $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // the restaurant's own day and clock (reservations "today", busy timers)
    require_once __DIR__ . '/../config/timezone_utils.php';
    $tzStmt = $conn->prepare("SELECT timezone FROM users WHERE restaurant_id = ? LIMIT 1");
    $tzStmt->execute([$restaurant_id]);
    applyRestaurantTimezone($tzStmt->fetchColumn() ?: 'Asia/Kolkata', $conn);

    // Busy tables: an unpaid open order or a ticket still in the kitchen (config/table_busy.php)
    require_once __DIR__ . '/../config/table_busy.php';
    $busyByTable = rgBusyTables($conn, (string)$restaurant_id);

    // Get today's reservations (confirmed)
    $resStmt = $conn->prepare("SELECT table_id, customer_name, reservation_date, time_slot FROM reservations WHERE restaurant_id = ? AND status = 'Confirmed' AND reservation_date = CURDATE()");
    $resStmt->execute([$restaurant_id]);
    $reservations = $resStmt->fetchAll(PDO::FETCH_ASSOC);
    $resByTable = [];
    foreach ($reservations as $r) {
        $tid = $r['table_id'];
        $resByTable[$tid] = $r;
    }

    // Combine into status data
    $result = [];
    foreach ($tables as $table) {
        $tid = $table['id'];
        $hasActiveKot = isset($busyByTable[$tid]);
        $hasReservation = isset($resByTable[$tid]);

        $status = 'free';
        $customerName = null;
        $orderTimer = null;
        $kotInfo = null;

        if ($hasActiveKot) {
            $status = 'occupied';
            $kotInfo = $busyByTable[$tid]['kots'];
            // Timer from the earliest open order / ticket
            if ($busyByTable[$tid]['since']) {
                $created = strtotime($busyByTable[$tid]['since']);
                $diff = time() - $created;
                $mins = max(0, floor($diff / 60));
                if ($mins < 60) $orderTimer = $mins . ' min';
                else $orderTimer = floor($mins / 60) . 'h ' . ($mins % 60) . 'm';
            }
        } elseif ($hasReservation) {
            $status = 'reserved';
            $customerName = $resByTable[$tid]['customer_name'];
        }

        $result[] = [
            'id' => $table['id'],
            'table_number' => $table['table_number'],
            'area_id' => $table['area_id'],
            'area_name' => $table['area_name'],
            'capacity' => (int)$table['capacity'],
            'is_available' => (bool)$table['is_available'],
            'status' => $status,
            // guest names and running bills are for the restaurant's own staff, not the public
            'customer_name' => $isStaff ? $customerName : null,
            'order_timer' => $orderTimer,
            'kot_info' => $isStaff && $kotInfo && count($kotInfo) > 0 ? $kotInfo : null
        ];
    }

    // Also return areas list
    $areaStmt = $conn->prepare("SELECT * FROM areas WHERE restaurant_id = ? ORDER BY sort_order ASC, id ASC");   // * : icon and color too, when the Areas page has added them
    $areaStmt->execute([$restaurant_id]);
    $areas = $areaStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $result,
        'areas' => $areas,
        'count' => count($result)
    ]);

} catch (PDOException $e) {
    error_log("PDO Error in get_table_status.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.', 'data' => []]);
    exit();
} catch (Throwable $e) {
    error_log("Error in get_table_status.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.', 'data' => []]);
    exit();
}
?>
