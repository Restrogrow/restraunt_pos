<?php
/**
 * API: Send push notifications to subscribed admin users
 * POST: Send a notification to one or all admin users
 */

require_once __DIR__ . '/../../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/env_loader.php';

require_once __DIR__ . '/../../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

startSecureSession();
header('Content-Type: application/json; charset=UTF-8');

// Only allow authenticated requests
if (!isSessionValid() || !isset($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$restaurantId = $_SESSION['restaurant_id'];

try {
    $conn = getConnection();
    
    // Get the user IDs for this restaurant
    $stmt = $conn->prepare("SELECT id FROM users WHERE restaurant_id = ?");
    $stmt->execute([$restaurantId]);
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($userIds)) {
        echo json_encode(['success' => false, 'message' => 'No users found']);
        exit;
    }
    
    // Get all push subscriptions for these users
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $conn->prepare("SELECT id, endpoint, p256dh_key, auth_key FROM push_subscriptions WHERE user_id IN ($placeholders)");
    $stmt->execute($userIds);
    $subscriptions = $stmt->fetchAll();
    
    if (empty($subscriptions)) {
        echo json_encode(['success' => true, 'sent' => 0, 'message' => 'No subscribers found']);
        exit;
    }
    
    // Get notification payload from request
    $input = json_decode(file_get_contents('php://input'), true);
    $title = $input['title'] ?? 'RestroGrow Admin';
    $body = $input['body'] ?? 'You have a new notification';
    $url = $input['url'] ?? '../views/dashboard.php';
    $orderId = $input['orderId'] ?? null;
    
    // Load VAPID keys from env
    $vapidPublicKey = env('VAPID_PUBLIC_KEY', '');
    $vapidPrivateKey = env('VAPID_PRIVATE_KEY', '');
    $vapidSubject = env('VAPID_SUBJECT', 'mailto:admin@restrogrow.com');
    
    if (empty($vapidPublicKey) || empty($vapidPrivateKey)) {
        echo json_encode(['success' => false, 'message' => 'VAPID keys not configured']);
        exit;
    }
    
    $auth = [
        'VAPID' => [
            'subject' => $vapidSubject,
            'publicKey' => $vapidPublicKey,
            'privateKey' => $vapidPrivateKey,
        ],
    ];
    
    $webPush = new WebPush($auth);
    $payload = json_encode([
        'title' => $title,
        'body' => $body,
        'url' => $url,
        'orderId' => $orderId,
        'tag' => 'order-' . ($orderId ?: date('His')),
        'actions' => $orderId ? [
            ['action' => 'view', 'title' => 'View Order'],
            ['action' => 'dismiss', 'title' => 'Dismiss'],
        ] : [],
    ]);
    
    $sent = 0;
    $failed = 0;
    
    foreach ($subscriptions as $sub) {
        try {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys' => [
                    'p256dh' => $sub['p256dh_key'],
                    'auth' => $sub['auth_key'],
                ],
            ]);
            
            $webPush->sendOneNotification($subscription, $payload);
            $sent++;
        } catch (Exception $e) {
            // If expired, remove the subscription
            if (strpos($e->getMessage(), 'expired') !== false || 
                strpos($e->getMessage(), 'gone') !== false ||
                strpos($e->getMessage(), '410') !== false) {
                $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE id = ?");
                $stmt->execute([$sub['id']]);
            }
            $failed++;
        }
    }
    
    // Send all notifications
    $webPush->flush();
    
    echo json_encode([
        'success' => true, 
        'sent' => $sent, 
        'failed' => $failed,
        'total_subscribers' => count($subscriptions)
    ]);
    
} catch (Exception $e) {
    error_log("Send push notification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error sending notifications: ' . $e->getMessage()]);
}
