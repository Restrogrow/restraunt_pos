<?php
/**
 * Order details › Delivery › Shiprocket Quick rider (config/quick_delivery.php). Owner, branch admin,
 * manager or cashier (riderCanDispatch); the auto-book setting is the owner's or a manager's.
 *
 * GET  ?order_id=…                      can it be booked, the booking (refreshed from Shiprocket), settings
 * POST {action:'quote',   order_id}     the price of a rider now (nothing is booked)
 * POST {action:'book',    order_id}     book the rider
 * POST {action:'cancel',  order_id}     cancel the rider (before pickup)
 * POST {action:'settings', auto_mode}   book by itself: off | accept | ready
 */
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin();
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function qdOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

require_once __DIR__ . '/../config/quick_delivery.php';
if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) qdOut(['success' => false, 'message' => 'Please log in again.'], 401);
if (!riderCanDispatch()) qdOut(['success' => false, 'message' => 'Only the owner, a manager or the cashier can book a rider.'], 403);
$rid = (string)$_SESSION['restaurant_id'];
$by = (string)($_SESSION['username'] ?? 'Owner');
$canSet = ($_SESSION['user_type'] ?? '') !== 'staff' || in_array($_SESSION['role'] ?? '', ['Manager', 'Admin'], true);
session_write_close();
require_once __DIR__ . '/../db_connection.php';

try {
    $conn = getConnection();
    qdEnsure($conn);
    $settings = qdSettings($conn, $rid);
    $base = ['enabled' => $settings['enabled'] && qdConfigured(), 'auto_mode' => $settings['auto_mode'], 'auto_modes' => QD_AUTO_MODES, 'can_change' => $canSet];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $orderId = (int)($_GET['order_id'] ?? 0);
        if (!$base['enabled']) qdOut(['success' => true] + $base);   // not switched on: the screen shows nothing
        $b = $orderId ? qdBooking($conn, $orderId) : null;
        if ($b && $b['restaurant_id'] !== $rid) $b = null;
        if ($b) $b = qdRefresh($conn, $b);
        $blocker = '';
        if ($orderId) { $o = qdOrder($conn, $rid, $orderId); $blocker = qdBlocker($conn, $rid, $o); }
        qdOut(['success' => true, 'booking' => qdPublic($b), 'blocker' => $blocker] + $base);
    }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $action = (string)($in['action'] ?? '');
    $orderId = (int)($in['order_id'] ?? 0);

    if ($action === 'settings') {
        if (!$canSet) qdOut(['success' => false, 'message' => 'Only the owner or a manager can change this.'], 403);
        $mode = (string)($in['auto_mode'] ?? '');
        if (!isset(QD_AUTO_MODES[$mode])) qdOut(['success' => false, 'message' => 'Pick when the rider is booked.'], 400);
        if (!$settings['enabled']) qdOut(['success' => false, 'message' => 'Shiprocket Quick isn\'t switched on for this restaurant.'], 400);
        qdSaveSettings($conn, $rid, ['auto_mode' => $mode]);
        qdLog($conn, $rid, 'delivery.quick', 'Shiprocket Quick: book a rider by itself — ' . QD_AUTO_MODES[$mode], $by);
        qdOut(['success' => true, 'message' => 'Saved', 'auto_mode' => $mode]);
    }
    if (!$orderId) qdOut(['success' => false, 'message' => 'Order not found'], 400);

    if ($action === 'quote') {
        $q = qdQuote($conn, $rid, $orderId);
        qdOut(['success' => true, 'quote' => $q, 'message' => 'A ' . QD_NAME . ' rider costs ₹' . number_format($q['rate'], 2) . ' for this order.']);
    }
    if ($action === 'book') {
        @set_time_limit(90);
        $b = qdBook($conn, $rid, $orderId, $by);
        qdOut(['success' => true, 'booking' => qdPublic($b), 'message' => 'Rider booked' . (!empty($b['rate']) ? ' for ₹' . number_format((float)$b['rate'], 2) : '') . '. The customer sees the ride on their tracking page.']);
    }
    if ($action === 'cancel') {
        $done = qdCancel($conn, $rid, $orderId, $by);
        qdOut(['success' => $done, 'booking' => qdPublic(qdBooking($conn, $orderId)), 'message' => $done ? 'Rider cancelled.' : 'There is no rider to cancel.']);
    }
    qdOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (InvalidArgumentException $e) {
    qdOut(['success' => false, 'message' => $e->getMessage()], 400);
} catch (PDOException $e) {
    error_log('quick_delivery.php: ' . $e->getMessage());
    qdOut(['success' => false, 'message' => 'Could not do that. Please try again.'], 500);
} catch (RuntimeException $e) {
    // Shiprocket said no: its message helps the restaurant (no keys in it)
    qdOut(['success' => false, 'message' => 'Shiprocket: ' . $e->getMessage()], 502);
} catch (Throwable $e) {
    error_log('quick_delivery.php: ' . $e->getMessage());
    qdOut(['success' => false, 'message' => 'Could not do that. Please try again.'], 500);
}
