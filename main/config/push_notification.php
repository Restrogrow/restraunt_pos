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

    return deliverPushNotifications($conn, $subscriptions, $title, $body, $url, $orderId);
}

/**
 * Push to a subset of a restaurant's staff, by role (e.g. ['Chef'] for a
 * new order, ['Waiter'] for "order ready to serve"). Kept separate from
 * sendPushNotification() (which only ever targets admin/owner accounts in
 * `users`) rather than folding roles into it, because admin and staff need
 * to be notified at different points in the order lifecycle — a single
 * "notify everyone" call would either spam the owner on every kitchen
 * event or miss staff entirely.
 */
function sendStaffPushNotification($conn, $restaurantId, array $roles, $title, $body, $url = '../views/dashboard.php', $orderId = null) {
    if (empty($roles)) {
        return ['sent' => 0, 'error' => 'No roles specified'];
    }

    $rolePlaceholders = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $conn->prepare(
        "SELECT ps.id, ps.endpoint, ps.p256dh_key, ps.auth_key
         FROM push_subscriptions ps
         JOIN staff s ON s.id = ps.staff_id
         WHERE s.restaurant_id = ? AND s.is_active = 1 AND s.role IN ($rolePlaceholders)"
    );
    $stmt->execute(array_merge([$restaurantId], $roles));
    $subscriptions = $stmt->fetchAll();

    return deliverPushNotifications($conn, $subscriptions, $title, $body, $url, $orderId);
}

/**
 * Notify waiters that an order is ready to serve. Deliberately a thin
 * wrapper callers invoke themselves AFTER their own transaction commits
 * (see update_order_status.php / kot_operations.php) rather than a hook
 * inside validateAndUpdateOrderStatus() — that used to fire the push while
 * still holding the order's row lock (FOR UPDATE) inside an open
 * transaction, so a slow push-service response could hold up other
 * operations on the same order row. Best-effort: failures are logged, never
 * thrown, since a missed notification shouldn't be treated like a failed
 * order update.
 */
function notifyWaitersOrderReady(PDO $conn, string $restaurantId, int $orderId) {
    try {
        $detailStmt = $conn->prepare('SELECT order_number, order_type FROM orders WHERE id = ?');
        $detailStmt->execute([$orderId]);
        $detail = $detailStmt->fetch(PDO::FETCH_ASSOC);
        if (!$detail) {
            return;
        }
        sendStaffPushNotification(
            $conn,
            $restaurantId,
            ['Waiter'],
            '🔔 Order Ready!',
            'Order #' . $detail['order_number'] . ' (' . $detail['order_type'] . ') is ready to serve',
            '../views/waiter_dashboard.php',
            $orderId
        );
    } catch (Exception $e) {
        error_log('Waiter push notification failed for order ' . $orderId . ': ' . $e->getMessage());
    }
}

/**
 * Shared delivery loop for both sendPushNotification() (admin) and
 * sendStaffPushNotification() (role-scoped staff) — same VAPID auth,
 * payload shape, and expired-subscription cleanup either way.
 */
function deliverPushNotifications($conn, $subscriptions, $title, $body, $url, $orderId) {
    if (empty($subscriptions)) {
        return ['sent' => 0, 'message' => 'No subscribers'];
    }

    $vapidPublicKey = env('VAPID_PUBLIC_KEY', '');
    $vapidPrivateKey = env('VAPID_PRIVATE_KEY', '');
    $vapidSubject = env('VAPID_SUBJECT', 'mailto:admin@restrogrow.com');

    if (empty($vapidPublicKey) || empty($vapidPrivateKey)) {
        error_log("Push notification skipped: VAPID keys not configured");
        return ['sent' => 0, 'error' => 'VAPID keys not configured'];
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

    foreach ($subscriptions as $sub) {
        try {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys' => [
                    'p256dh' => $sub['p256dh_key'],
                    'auth' => $sub['auth_key'],
                ],
            ]);
            // sendOneNotification() sends synchronously and returns a
            // MessageSentReport — it does NOT throw for a rejected push
            // (expired subscription, 404/410, bad VAPID auth, etc). The old
            // code only had a catch block here, so every failed delivery was
            // silently counted as "sent" and expired subscriptions were
            // never cleaned up.
            $report = $webPush->sendOneNotification($subscription, $payload);
            if ($report->isSuccess()) {
                $sent++;
            } elseif ($report->isSubscriptionExpired()) {
                $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE id = ?");
                $stmt->execute([$sub['id']]);
            } else {
                error_log('Push notification failed for subscription ' . $sub['id'] . ': ' . $report->getReason());
            }
        } catch (Exception $e) {
            error_log('Push notification exception for subscription ' . $sub['id'] . ': ' . $e->getMessage());
        }
    }

    return ['sent' => $sent, 'total' => count($subscriptions)];
}
