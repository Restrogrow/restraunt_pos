<?php
/**
 * A table calls the waiter: from the customer website (website/call_waiter.php) or the old table
 * picker (website/script.js). The call appears in Waiter Requests, the waiter dashboard and the app.
 *
 * POST JSON { restaurant_id, table_id | table_number, reason: waiter|bill|water|extras|order, note }
 *  - The restaurant comes from the request first (the page the customer is on), never from a
 *    dashboard login in the same browser.
 *  - The table must belong to that restaurant; the owner can switch the feature off.
 *  - One open call per table: calling again while a call is pending just refreshes it.
 *  - At most 6 calls per 10 minutes from one device, so it can't be used to spam a restaurant.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/waiter_call.php';
if (file_exists(__DIR__ . '/../config/rate_limit.php')) require_once __DIR__ . '/../config/rate_limit.php';

function waiterCallFail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') waiterCallFail('Method not allowed', 405);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$rid = trim((string)($input['restaurant_id'] ?? ($_GET['restaurant_id'] ?? '')));
if ($rid === '') $rid = (string)($_SESSION['restaurant_id'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{3,20}$/', $rid)) waiterCallFail('Restaurant not found.');

$reasonKey = (string)($input['reason'] ?? 'waiter');
if (!isset(WAITER_CALL_REASONS[$reasonKey])) {
    // the old picker sends request_type instead
    $reasonKey = strtolower((string)($input['request_type'] ?? '')) === 'order' ? 'order' : 'waiter';
}
[$requestType, $reasonText] = WAITER_CALL_REASONS[$reasonKey];
$note = trim(strip_tags((string)($input['note'] ?? ($input['notes'] ?? ''))));
$note = mb_substr(preg_replace('/\s+/', ' ', $note), 0, 200);

try {
    $conn = getConnection();
    if (!waiterCallAvailable($conn, $rid)) waiterCallFail('Calling a waiter is switched off here. Please ask the staff.');

    // the table: by id (old picker) or by its number (from the table QR code)
    if (!empty($input['table_id'])) {
        $st = $conn->prepare('SELECT t.id, t.table_number, a.area_name FROM tables t LEFT JOIN areas a ON a.id = t.area_id WHERE t.id = ? AND t.restaurant_id = ? LIMIT 1');
        $st->execute([(int)$input['table_id'], $rid]);
    } else {
        $num = trim((string)($input['table_number'] ?? ''));
        if ($num === '') waiterCallFail('Please choose your table.');
        $st = $conn->prepare('SELECT t.id, t.table_number, a.area_name FROM tables t LEFT JOIN areas a ON a.id = t.area_id WHERE t.restaurant_id = ? AND LOWER(t.table_number) = LOWER(?) LIMIT 1');
        $st->execute([$rid, $num]);
    }
    $table = $st->fetch(PDO::FETCH_ASSOC);
    if (!$table) waiterCallFail('That table could not be found. Please ask the staff.');

    if (function_exists('checkRateLimit')) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rate = checkRateLimit('waiter_call_' . $rid . '_' . $ip, 6, 600);
        if (!$rate['allowed']) waiterCallFail('You have called several times already. A waiter will be with you shortly.', 429);
    }

    $text = $reasonText . ($note !== '' ? ': ' . $note : '');

    // one open call per table: a second tap refreshes it instead of stacking up
    $open = $conn->prepare("SELECT id FROM waiter_requests WHERE restaurant_id = ? AND table_id = ? AND status = 'Pending' ORDER BY id DESC LIMIT 1");
    $open->execute([$rid, $table['id']]);
    if ($id = $open->fetchColumn()) {
        $conn->prepare('UPDATE waiter_requests SET request_type = ?, notes = ?, updated_at = NOW() WHERE id = ?')->execute([$requestType, $text, $id]);
        echo json_encode(['success' => true, 'request_id' => (int)$id, 'already' => true,
            'message' => 'A waiter has already been called to ' . $table['table_number'] . '. We updated your request.', 'table' => $table['table_number']]);
        exit;
    }

    $conn->prepare("INSERT INTO waiter_requests (restaurant_id, table_id, request_type, notes, status) VALUES (?, ?, ?, ?, 'Pending')")
        ->execute([$rid, $table['id'], $requestType, $text]);
    echo json_encode(['success' => true, 'request_id' => (int)$conn->lastInsertId(),
        'message' => 'A waiter is on the way to ' . $table['table_number'] . '.', 'table' => $table['table_number']]);
} catch (Throwable $e) {
    error_log('create_waiter_request.php: ' . $e->getMessage());
    waiterCallFail('Something went wrong. Please try again or ask the staff.', 500);
}
