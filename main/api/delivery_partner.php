<?php
/**
 * Delivery booked with an outside app (Order details › Delivery › Book a delivery partner).
 * The dashboard opens Uber / Rapido / Porter / Borzo / Ola with the drop already copied (Uber also gets
 * pickup and drop filled in by its link); this saves who is delivering so the customer's tracking page
 * shows the driver and the app's own live-tracking link. Owner, branch admin, manager or cashier.
 *
 * GET  ?order_id=…                                          what is saved for that order
 * POST {order_id, partner, rider_name, rider_phone, track_url}  save (partner '' clears it)
 * Stored in delivery_tracking (config/rider_engine.php), the same row the rider page and riders use.
 */
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin();
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function partnerOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

const DELIVERY_PARTNERS = ['uber' => 'Uber', 'rapido' => 'Rapido', 'porter' => 'Porter', 'borzo' => 'Borzo', 'ola' => 'Ola', 'other' => 'Other'];

require_once __DIR__ . '/../config/rider_engine.php';
if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) partnerOut(['success' => false, 'message' => 'Please log in again.'], 401);
if (!riderCanDispatch()) partnerOut(['success' => false, 'message' => 'Only the owner, a manager or the cashier can book a delivery.'], 403);
$rid = (string)$_SESSION['restaurant_id'];
$by = (string)($_SESSION['username'] ?? 'Owner');
session_write_close();
require_once __DIR__ . '/../db_connection.php';

try {
    $conn = getConnection();
    riderEnsure($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $st = $conn->prepare('SELECT partner, rider_name, rider_phone, partner_track_url, delivery_status, rider_staff_id FROM delivery_tracking WHERE order_id = ? AND restaurant_id = ? LIMIT 1');
        $st->execute([(int)($_GET['order_id'] ?? 0), $rid]);
        $t = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        partnerOut(['success' => true, 'partners' => DELIVERY_PARTNERS, 'delivery' => [
            'partner' => (string)($t['partner'] ?? ''),
            'rider_name' => (string)($t['rider_name'] ?? ''),
            'rider_phone' => (string)($t['rider_phone'] ?? ''),
            'track_url' => (string)($t['partner_track_url'] ?? ''),
            'status' => (string)($t['delivery_status'] ?? ''),
            'own_rider' => !empty($t['rider_staff_id']),
        ]]);
    }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $orderId = (int)($in['order_id'] ?? 0);
    $partner = strtolower(trim((string)($in['partner'] ?? '')));
    if ($partner !== '' && !isset(DELIVERY_PARTNERS[$partner])) partnerOut(['success' => false, 'message' => 'Pick a delivery app from the list.'], 400);
    $name = mb_substr(trim((string)($in['rider_name'] ?? '')), 0, 100);
    $phone = mb_substr(preg_replace('/[^\d+ ]/', '', (string)($in['rider_phone'] ?? '')), 0, 20);
    $url = trim((string)($in['track_url'] ?? ''));
    if ($url !== '' && !preg_match('#^https?://#i', $url)) partnerOut(['success' => false, 'message' => 'The tracking link must start with https://'], 400);
    $url = mb_substr($url, 0, 500);

    $conn->beginTransaction();
    try {
        $o = $conn->prepare("SELECT id, order_type, order_status, order_number FROM orders WHERE id = ? AND restaurant_id = ? AND deleted_at IS NULL FOR UPDATE");
        $o->execute([$orderId, $rid]);
        $order = $o->fetch(PDO::FETCH_ASSOC);
        if (!$order) throw new InvalidArgumentException('Order not found');
        if ($order['order_type'] !== 'Delivery') throw new InvalidArgumentException('Only delivery orders can be booked with a delivery app');
        if (in_array($order['order_status'], ['Cancelled', 'Rejected'], true)) throw new InvalidArgumentException('This order is ' . strtolower($order['order_status']));
        $t = riderTrackingRow($conn, $rid, $orderId);
        if ($t['delivery_status'] === 'Delivered' && $partner !== '') throw new InvalidArgumentException('This order is already delivered');
        if ($partner !== '' && !empty($t['rider_staff_id'])) throw new InvalidArgumentException('One of your own riders has this order. Take it back from the rider first.');
        // a Shiprocket Quick rider (api/quick_delivery.php) is changed only there
        if (($t['partner'] ?? '') === 'shiprocket') throw new InvalidArgumentException('A Shiprocket Quick rider has this order. Cancel that rider first.');

        if ($partner === '') {
            $conn->prepare('UPDATE delivery_tracking SET partner = NULL, partner_track_url = NULL, rider_name = NULL, rider_phone = NULL WHERE id = ?')->execute([$t['id']]);
        } else {
            $status = $t['delivery_status'] === 'Pending' || $t['delivery_status'] === 'Preparing' ? 'Assigned' : $t['delivery_status'];
            $conn->prepare('UPDATE delivery_tracking SET partner = ?, partner_track_url = ?, rider_name = ?, rider_phone = ?, delivery_status = ?, assigned_at = COALESCE(assigned_at, NOW()) WHERE id = ?')
                ->execute([$partner, $url !== '' ? $url : null, $name !== '' ? $name : DELIVERY_PARTNERS[$partner] . ' driver', $phone !== '' ? $phone : null, $status, $t['id']]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }

    try {
        require_once __DIR__ . '/../config/activity_log.php';
        logActivity($conn, $rid, 'delivery.partner', $partner === '' ? 'Delivery app removed from ' . $order['order_number'] : $order['order_number'] . ' sent with ' . DELIVERY_PARTNERS[$partner], [], null, null, ['name' => $by]);
    } catch (Throwable $e) { /* the log is optional */ }

    partnerOut(['success' => true, 'message' => $partner === '' ? 'Removed' : 'Saved — the customer now sees the driver on their tracking page.']);
} catch (InvalidArgumentException $e) {
    partnerOut(['success' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('delivery_partner.php: ' . $e->getMessage());
    partnerOut(['success' => false, 'message' => 'Could not save. Please try again.'], 500);
}
