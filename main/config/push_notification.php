<?php
/**
 * Push Notification Helper
 * Send Web Push notifications from any part of the application
 * 
 * Usage:
 *   sendPushNotification($conn, $restaurantId, 'New Order!', 'Order #42 received', '../views/orders.php');
 */

require_once __DIR__ . '/env_loader.php';

// WebPush classes (loaded via require autoload below)
require_once __DIR__ . '/../../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

function sendPushNotification($conn, $restaurantId, $title, $body, $url = '../views/dashboard.php', $orderId = null) {
    // Get VAPID keys
    $vapidPublicKey = env('VAPID_PUBLIC_KEY', '');
    $vapidPrivateKey = env('VAPID_PRIVATE_KEY', '');
    $vapidSubject = env('VAPID_SUBJECT', 'mailto:admin@restrogrow.com');
    
    if (empty($vapidPublicKey) || empty($vapidPrivateKey)) {
        error_log("Push notification skipped: VAPID keys not configured");
        return ['sent' => 0, 'error' => 'VAPID keys not configured'];
    }
    
    // Get user IDs for this restaurant
    $stmt = $conn->prepare("SELECT id FROM users WHERE restaurant_id = ?");
    $stmt->execute([$restaurantId]);
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($userIds)) {
        return ['sent' => 0, 'error' => 'No users found'];
    }
    
    // Get all push subscriptions
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $conn->prepare("SELECT id, endpoint, p256dh_key, auth_key FROM push_subscriptions WHERE user_id IN ($placeholders)");
    $stmt->execute($userIds);
    $subscriptions = $stmt->fetchAll();
    
    if (empty($subscriptions)) {
        return ['sent' => 0, 'message' => 'No subscribers'];
    }
    
    // Send notifications
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
            if (strpos($e->getMessage(), 'expired') !== false || 
                strpos($e->getMessage(), '410') !== false) {
                $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE id = ?");
                $stmt->execute([$sub['id']]);
            }
        }
    }
    
    $webPush->flush();
    
    return ['sent' => $sent, 'total' => count($subscriptions)];
}
