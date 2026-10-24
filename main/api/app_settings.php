<?php
// The app's restaurant settings: GET returns them, POST saves only the fields it is sent.
// admin/auth.php's updateRestaurantSettings expects the whole settings form at once and
// blanks anything left out, so the app's one-section-at-a-time saves come here instead.
// Same gate as that action: the restaurant owner or a branch admin, never staff.
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
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

$isBranchAdmin = isset($_SESSION['branch_admin_id']);
if ((!isset($_SESSION['user_id']) && !$isBranchAdmin) || !isset($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Only the restaurant owner or branch admin can change settings']);
    exit;
}

require_once __DIR__ . '/../config/app_setting_fields.php';   // APP_SETTING_FIELDS, appSettingValue()

try {
    require_once __DIR__ . '/../db_connection.php';
    $conn = function_exists('getConnection') ? getConnection() : ($GLOBALS['pdo'] ?? null);
    if (!$conn) throw new Exception('Database connection not available');

    if ($isBranchAdmin) {
        $linked = array_column($_SESSION['linked_restaurants'] ?? [], 'restaurant_id');
        if (!in_array($_SESSION['restaurant_id'], $linked, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You are not allowed to change this restaurant']);
            exit;
        }
        $st = $conn->prepare('SELECT id FROM users WHERE restaurant_id = ? LIMIT 1');
        $st->execute([$_SESSION['restaurant_id']]);
        $userId = $st->fetchColumn();
        if (!$userId) throw new Exception('Restaurant not found');
    } else {
        $userId = $_SESSION['user_id'];
    }

    require_once __DIR__ . '/../config/waiter_call.php';
    waiterCallEnsureSchema($conn);
    // only columns this database actually has (older databases miss some of the newer ones)
    $have = array_flip($conn->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN));
    $fields = array_filter(APP_SETTING_FIELDS, fn($f) => isset($have[$f]), ARRAY_FILTER_USE_KEY);

    $read = function () use ($conn, $fields, $userId) {
        $st = $conn->prepare('SELECT ' . implode(', ', array_map(fn($f) => "`$f`", array_keys($fields))) . ' FROM users WHERE id = ?');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($row as $k => $v) {
            if ($fields[$k] === 'bool') $row[$k] = (int)$v;
            elseif (in_array($fields[$k], ['money', 'percent'], true)) $row[$k] = $v === null ? null : (float)$v;
            elseif ($fields[$k] === 'hours') $row[$k] = $v ? json_decode($v, true) : null;
        }
        return $row;
    };

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        session_write_close(); // GET only reads the session
        echo json_encode(['success' => true, 'settings' => $read()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // the demo restaurant's name is its website address, so visitors can't change it
    require_once __DIR__ . '/../config/demo_restaurant.php';
    if (isDemoRestaurantSession()) unset($_POST['restaurant_name']);

    $sets = [];
    $vals = [];
    foreach ($fields as $f => $type) {
        if (!array_key_exists($f, $_POST)) continue;
        $sets[] = "`$f` = ?";
        $vals[] = appSettingValue($f, $type, $_POST[$f]);
    }
    if (!$sets) {
        echo json_encode(['success' => false, 'message' => 'Nothing to save']);
        exit;
    }

    // Same rule as auth.php's toggleOrderType: the restaurant always takes at least one kind of order.
    $typeCols = array_values(array_filter(['enable_delivery', 'enable_takeaway', 'enable_dinein'], fn($c) => isset($have[$c])));
    if (count($typeCols) === 3 && array_intersect($typeCols, array_keys($_POST))) {
        $cur = $conn->prepare('SELECT enable_delivery, enable_takeaway, enable_dinein FROM users WHERE id = ?');
        $cur->execute([$userId]);
        $projected = $cur->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($typeCols as $c) {
            if (array_key_exists($c, $_POST)) $projected[$c] = appSettingValue($c, 'bool', $_POST[$c]);
        }
        if (!((int)($projected['enable_delivery'] ?? 0) || (int)($projected['enable_takeaway'] ?? 0) || (int)($projected['enable_dinein'] ?? 0))) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'At least one order type must stay enabled']);
            exit;
        }
    }

    require_once __DIR__ . '/../config/activity_log.php';
    $before = activityUserRow($conn, $userId);
    $vals[] = $userId;
    if (isset($have['updated_at'])) $sets[] = 'updated_at = NOW()';
    $conn->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    $changed = activitySettingsChanged($before, activityUserRow($conn, $userId));
    if ($changed) logActivity($conn, $_SESSION['restaurant_id'] ?? null, 'settings.restaurant', 'Changed settings in the app: ' . implode(', ', $changed));

    if (array_key_exists('restaurant_name', $_POST)) $_SESSION['restaurant_name'] = trim($_POST['restaurant_name']);

    echo json_encode(['success' => true, 'settings' => $read()], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('app_settings.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save, please try again.']);
}
