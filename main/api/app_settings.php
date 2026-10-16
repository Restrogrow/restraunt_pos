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

// field => type. Anything not listed here can't be read or written through this file.
const APP_SETTING_FIELDS = [
    'restaurant_name' => 'text', 'owner_name' => 'text', 'description' => 'longtext',
    'phone' => 'phone', 'email' => 'email', 'address' => 'longtext', 'google_maps_link' => 'url',
    'opening_hours' => 'hours',
    'enable_delivery' => 'bool', 'enable_takeaway' => 'bool', 'enable_dinein' => 'bool',
    'enable_reservations' => 'bool', 'cod_enabled' => 'bool',
    'minimum_order_value' => 'money', 'packaging_charge' => 'money',
    'delivery_radius_km' => 'money', 'enable_km_delivery' => 'bool', 'delivery_rate_per_km' => 'money',
    'enable_gst' => 'bool', 'tax_name' => 'text', 'tax_percent' => 'percent',
    'show_business_id' => 'bool', 'business_id_label' => 'text', 'business_id_no' => 'upper',
    'instagram_link' => 'url', 'facebook_link' => 'url', 'twitter_link' => 'url',
    'youtube_link' => 'url', 'linkedin_link' => 'url',
];

function appSettingValue(string $field, string $type, $raw) {
    $v = is_string($raw) ? trim($raw) : $raw;
    switch ($type) {
        case 'bool':
            return in_array((string)$v, ['1', 'true', 'on'], true) ? 1 : 0;
        case 'money':
            if ($v === '' || $v === null) return 0;
            if (!is_numeric($v) || (float)$v < 0 || (float)$v > 1000000) throw new InvalidArgumentException("Enter a valid number for " . str_replace('_', ' ', $field));
            return round((float)$v, 2);
        case 'percent':
            if (!is_numeric($v) || (float)$v < 0 || (float)$v > 100) throw new InvalidArgumentException('Tax must be between 0 and 100%');
            return round((float)$v, 2);
        case 'email':
            if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address');
            return mb_substr($v, 0, 190);
        case 'phone':
            if ($v !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $v)) throw new InvalidArgumentException('Enter a valid phone number');
            return $v;
        case 'url':
            if ($v !== '' && !preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
            if ($v !== '' && !filter_var($v, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('Enter a valid link for ' . str_replace(['_link', '_'], ['', ' '], $field));
            return mb_substr($v, 0, 500);
        case 'hours':
            $h = json_decode((string)$v, true);
            if (!is_array($h)) throw new InvalidArgumentException('Business hours could not be read');
            $out = [];
            foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $d) {
                $day = $h[$d] ?? [];
                $fmt = fn($t, $def) => preg_match('/^(0?[1-9]|1[0-2]):[0-5][0-9] (AM|PM)$/', (string)$t) ? (string)$t : $def;
                $out[$d] = ['open' => !empty($day['open']), 'opening' => $fmt($day['opening'] ?? '', '09:00 AM'), 'closing' => $fmt($day['closing'] ?? '', '11:00 PM')];
            }
            return json_encode($out);
        case 'upper':
            return mb_substr(strtoupper($v), 0, 60);
        case 'longtext':
            return mb_substr((string)$v, 0, 2000);
        default:
            if ($field === 'restaurant_name' && $v === '') throw new InvalidArgumentException('Restaurant name cannot be empty');
            return mb_substr((string)$v, 0, 190);
    }
}

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
        echo json_encode(['success' => true, 'settings' => $read()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

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
} catch (Exception $e) {
    error_log('app_settings.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save, please try again.']);
}
