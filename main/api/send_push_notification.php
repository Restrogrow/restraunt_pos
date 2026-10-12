<?php
/**
 * API: send a push notification to this restaurant's subscribed admin devices
 * (the dashboard's "Test notification" button). POST {title, body, url, orderId}.
 * Sending is done by config/push_notification.php → config/web_push.php (no library needed).
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/push_notification.php';

startSecureSession();
header('Content-Type: application/json; charset=UTF-8');

if (!isSessionValid() || !isset($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
$restaurantId = $_SESSION['restaurant_id'];
session_write_close();

try {
    $conn = getConnection();
    $stmt = $conn->prepare('SELECT id FROM users WHERE restaurant_id = ?');
    $stmt->execute([$restaurantId]);
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($userIds)) {
        echo json_encode(['success' => false, 'message' => 'No users found']);
        exit;
    }
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $conn->prepare("SELECT id, endpoint, p256dh_key, auth_key FROM push_subscriptions WHERE user_id IN ($placeholders)");
    $stmt->execute($userIds);
    $subscriptions = $stmt->fetchAll();
    if (empty($subscriptions)) {
        echo json_encode(['success' => true, 'sent' => 0, 'total_subscribers' => 0, 'message' => 'No device has turned on notifications yet']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = mb_substr((string)($input['title'] ?? 'RestroGrow'), 0, 80);
    $body = mb_substr((string)($input['body'] ?? 'You have a new notification'), 0, 200);
    $url = (string)($input['url'] ?? '../views/dashboard.php');
    $orderId = isset($input['orderId']) ? (int)$input['orderId'] : null;

    $r = deliverPushNotifications($conn, $subscriptions, $title, $body, $url, $orderId);
    echo json_encode([
        'success' => true,
        'sent' => (int)($r['sent'] ?? 0),
        'failed' => (int)($r['failed'] ?? 0),
        'total_subscribers' => count($subscriptions),
        'failure_reasons' => $r['failure_reasons'] ?? [],
    ]);
} catch (Throwable $e) {
    error_log('Send push notification error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not send the notification. Please try again.']);
}
