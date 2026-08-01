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
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET');
    header('Access-Control-Allow-Headers: Content-Type');
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error initializing: ' . $e->getMessage(), 'data' => []]);
    exit();
}

$requested_restaurant_id = $_GET['restaurant_id'] ?? null;

if (isLoggedIn()) {
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

    // Get active KOTs (Pending or Preparing status) with table_id - no date filter to catch overnight KOTs
    $kotStmt = $conn->prepare("SELECT table_id, kot_number, created_at, total, kot_status FROM kot WHERE restaurant_id = ? AND kot_status IN ('Pending', 'Preparing') AND table_id IS NOT NULL");
    $kotStmt->execute([$restaurant_id]);
    $activeKots = $kotStmt->fetchAll(PDO::FETCH_ASSOC);
    $kotByTable = [];
    foreach ($activeKots as $k) {
        $tid = $k['table_id'];
        if (!isset($kotByTable[$tid])) $kotByTable[$tid] = [];
        $kotByTable[$tid][] = $k;
    }

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
        $hasActiveKot = isset($kotByTable[$tid]);
        $hasReservation = isset($resByTable[$tid]);

        $status = 'free';
        $customerName = null;
        $orderTimer = null;
        $kotInfo = null;

        if ($hasActiveKot) {
            $status = 'occupied';
            $kots = $kotByTable[$tid];
            $kotInfo = $kots;
            // Calculate timer from earliest KOT
            $earliestKot = null;
            foreach ($kots as $k) {
                if (!$earliestKot || $k['created_at'] < $earliestKot['created_at']) $earliestKot = $k;
            }
            if ($earliestKot) {
                $created = strtotime($earliestKot['created_at']);
                $diff = time() - $created;
                $mins = floor($diff / 60);
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
            'customer_name' => $customerName,
            'order_timer' => $orderTimer,
            'kot_info' => $kotInfo && count($kotInfo) > 0 ? $kotInfo : null
        ];
    }

    // Also return areas list
    $areaStmt = $conn->prepare("SELECT id, area_name FROM areas WHERE restaurant_id = ? ORDER BY sort_order ASC, id ASC");
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
} catch (Exception $e) {
    error_log("Error in get_table_status.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'data' => []]);
    exit();
}
?>
