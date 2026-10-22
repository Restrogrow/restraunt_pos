<?php
/**
 * The delivery rider's own screen (Staff app rider layout and views/rider_dashboard.php). Riders only.
 *
 * GET                                   my deliveries under way, open ones I may pick (if allowed), today's
 *                                       delivered list, cash in hand
 * POST {action:'step', order_id, status: Picked_Up|In_Transit|Delivered, cod_collected}
 * POST {action:'claim', order_id}       take an open delivery (when the restaurant allows it)
 * POST {action:'location', lat, lng}    live location for the customer's tracking page
 * Accepts JSON or form posts.
 */
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin();
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function riderOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) riderOut(['success' => false, 'message' => 'Please log in again.'], 401);
if (!isDeliverySession()) riderOut(['success' => false, 'message' => 'This screen is for delivery riders.'], 403);
$rid = (string)$_SESSION['restaurant_id'];
$me = (int)($_SESSION['staff_id'] ?? 0);
$myName = (string)($_SESSION['username'] ?? '');
session_write_close();

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/rider_engine.php';

try {
    $conn = getConnection();
    riderEnsure($conn);
    $ownOn = riderSettings($conn, $rid)['own_riders'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$ownOn) {
        riderOut(['success' => true, 'enabled' => false, 'message' => 'Your restaurant is not using its own riders right now. Ask your manager.',
            'me' => ['id' => $me, 'name' => $myName], 'self_pick' => false, 'restaurant' => ['name' => '', 'phone' => '', 'address' => '', 'currency' => '₹'],
            'mine' => [], 'open' => [], 'done_today' => [], 'cash_in_hand' => 0, 'delivered_today' => 0]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $mine = riderDeliveries($conn, $rid, ['rider' => $me, 'active' => true]);
        $done = riderDeliveries($conn, $rid, ['rider' => $me, 'done_today' => true]);
        $settings = riderSettings($conn, $rid);
        $open = $settings['self_pick'] ? riderDeliveries($conn, $rid, ['open' => true, 'active' => true]) : [];
        // the public demo always has deliveries to show (config/demo_restaurant.php)
        require_once __DIR__ . '/../config/demo_restaurant.php';
        $topped = false;
        try {
            $topped = $rid === DEMO_RESTAURANT_ID && demoRiderTopUp($conn, $rid, $me, count($mine), $settings['self_pick'] ? count($open) : 1, count($done));
        } catch (Throwable $e) {
            // the rider screen must work even if the demo top-up can't
            error_log('demo rider top-up: ' . $e->getMessage());
        }
        if ($topped) {
            $mine = riderDeliveries($conn, $rid, ['rider' => $me, 'active' => true]);
            $done = riderDeliveries($conn, $rid, ['rider' => $me, 'done_today' => true]);
            $open = $settings['self_pick'] ? riderDeliveries($conn, $rid, ['open' => true, 'active' => true]) : [];
        }
        $cash = $conn->prepare('SELECT COALESCE(SUM(cod_amount), 0) FROM delivery_tracking WHERE restaurant_id = ? AND rider_staff_id = ? AND cod_collected_at IS NOT NULL AND cash_settled_at IS NULL');
        $cash->execute([$rid, $me]);
        $u = $conn->prepare('SELECT restaurant_name, phone, address, currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1');
        $u->execute([$rid]);
        $rest = $u->fetch(PDO::FETCH_ASSOC) ?: [];
        riderOut(['success' => true, 'enabled' => true, 'me' => ['id' => $me, 'name' => $myName], 'self_pick' => $settings['self_pick'],
            'restaurant' => ['name' => $rest['restaurant_name'] ?? '', 'phone' => $rest['phone'] ?? '', 'address' => $rest['address'] ?? '', 'currency' => ($rest['currency_symbol'] ?? '') ?: '₹'],
            'mine' => $mine, 'open' => $open, 'done_today' => $done, 'cash_in_hand' => round((float)$cash->fetchColumn(), 2),
            'delivered_today' => count($done),
            // every time above is server time without a zone (the DB session is pinned to IST)
            'tz' => date('P')]);
    }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $action = (string)($in['action'] ?? '');
    $orderId = (int)($in['order_id'] ?? 0);
    // a delivery already under way can still be finished after the switch goes off
    if (!$ownOn && $action !== 'step') riderOut(['success' => false, 'message' => 'Your restaurant is not using its own riders right now.'], 403);

    if ($action === 'step') {
        $st = $conn->prepare('SELECT * FROM delivery_tracking WHERE order_id = ? AND restaurant_id = ? AND rider_staff_id = ?');
        $st->execute([$orderId, $rid, $me]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) riderOut(['success' => false, 'message' => 'This delivery is not assigned to you any more.'], 404);
        $msg = riderAdvance($conn, $t, (string)($in['status'] ?? ''), !empty($in['cod_collected']));
        riderOut(['success' => true, 'message' => $msg]);
    }
    if ($action === 'claim') {
        riderOut(['success' => true, 'message' => riderClaim($conn, $rid, $orderId, $me)]);
    }
    if ($action === 'location') {
        $n = riderLocation($conn, $rid, $me, (float)($in['lat'] ?? 0), (float)($in['lng'] ?? 0));
        riderOut(['success' => true, 'updated' => $n]);
    }
    riderOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    // the rider steps explain themselves; a database or code error stays in the log
    if ($e instanceof PDOException || !($e instanceof Exception)) { error_log('rider_app: ' . $msg); riderOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500); }
    riderOut(['success' => false, 'message' => $msg], 400);
}
