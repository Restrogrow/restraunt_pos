<?php
/**
 * Delivery › Riders (web dashboard, Partner app, Staff app manager). Owner, branch admin or manager.
 *
 * GET                                       riders (load, delivered today, cash in hand, last seen),
 *                                           delivery orders under way with who has them, settings
 * GET ?order_id=…                           one order's delivery (for the order screens)
 * POST {action:'assign', order_id, rider_id}   rider_id 0 = take it back
 * POST {action:'settle', rider_id}             the restaurant got the cash this rider collected
 * POST {action:'settings', self_pick}          riders may pick open deliveries themselves
 * Accepts JSON or form posts.
 */
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin();
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function dispatchOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

require_once __DIR__ . '/../config/rider_engine.php';
if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) dispatchOut(['success' => false, 'message' => 'Please log in again.'], 401);
if (!riderCanDispatch()) dispatchOut(['success' => false, 'message' => 'Only the owner, a manager or the cashier can assign riders.'], 403);
$rid = (string)$_SESSION['restaurant_id'];
$by = (string)($_SESSION['username'] ?? 'Owner');
session_write_close();
require_once __DIR__ . '/../db_connection.php';

try {
    $conn = getConnection();
    riderEnsure($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $settings = riderSettings($conn, $rid);
        $canSet = ($_SESSION['user_type'] ?? '') !== 'staff' || in_array($_SESSION['role'] ?? '', ['Manager', 'Admin'], true);
        if (!$settings['own_riders']) {
            // own riders switched off: nothing to show but the switch (Settings › Delivery)
            dispatchOut(['success' => true, 'enabled' => false, 'can_change' => $canSet, 'settings' => $settings, 'riders' => [], 'deliveries' => [], 'delivery' => null, 'waiting' => 0]);
        }
        $riders = riderList($conn, $rid);
        if (isset($_GET['order_id'])) {
            $one = riderDeliveries($conn, $rid, ['order' => (int)$_GET['order_id']]);
            dispatchOut(['success' => true, 'enabled' => true, 'riders' => $riders, 'delivery' => $one[0] ?? null]);
        }
        $active = riderDeliveries($conn, $rid, ['active' => true]);
        dispatchOut(['success' => true, 'enabled' => true, 'can_change' => $canSet, 'riders' => $riders, 'deliveries' => $active, 'settings' => $settings,
            'waiting' => count(array_filter($active, fn($d) => !$d['rider_staff_id'] && !$d['via_qr']))]);
    }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $action = (string)($in['action'] ?? '');

    if ($action === 'assign') {
        $msg = riderAssign($conn, $rid, (int)($in['order_id'] ?? 0), (int)($in['rider_id'] ?? 0));
        try {
            require_once __DIR__ . '/../config/activity_log.php';
            logActivity($conn, $rid, 'delivery.assign', $msg, [], null, null, ['name' => $by]);
        } catch (Throwable $e) { /* the log is not important */ }
        dispatchOut(['success' => true, 'message' => $msg]);
    }
    if ($action === 'settle') {
        [$n, $sum] = riderSettle($conn, $rid, (int)($in['rider_id'] ?? 0), $by);
        if (!$n) dispatchOut(['success' => true, 'message' => 'No cash to settle']);
        dispatchOut(['success' => true, 'message' => 'Settled ' . number_format($sum, 2) . ' from ' . $n . ' deliver' . ($n > 1 ? 'ies' : 'y')]);
    }
    if ($action === 'settings') {
        if (($_SESSION['user_type'] ?? '') === 'staff' && !in_array($_SESSION['role'] ?? '', ['Manager', 'Admin'], true)) {
            dispatchOut(['success' => false, 'message' => 'Only the owner or a manager can change this.'], 403);
        }
        riderSaveSettings($conn, $rid, $in);
        if (array_key_exists('own_riders', $in)) {
            dispatchOut(['success' => true, 'message' => empty($in['own_riders']) ? 'Own riders switched off. Riders, rider staff and Assign rider are hidden.' : 'Own riders switched on. Add riders in Staff with the role Delivery rider.']);
        }
        dispatchOut(['success' => true, 'message' => empty($in['self_pick']) ? 'Only you and managers assign deliveries now' : 'Riders can pick open deliveries themselves']);
    }
    dispatchOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if ($e instanceof PDOException) { error_log('delivery_dispatch: ' . $msg); $msg = 'Something went wrong. Please try again.'; }
    dispatchOut(['success' => false, 'message' => $msg], 400);
}
