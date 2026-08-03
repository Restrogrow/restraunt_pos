<?php
/**
 * API: Save/Delete push notification subscriptions for Web Push
 * POST: Save a new subscription
 * DELETE: Remove a subscription
 */

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';

startSecureSession();
header('Content-Type: application/json; charset=UTF-8');

if (!isSessionValid() || (!isset($_SESSION['user_id']) && !isset($_SESSION['staff_id']))) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $conn = getConnection();
    
    if ($method === 'POST') {
        // Save a new subscription
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || empty($input['endpoint']) || empty($input['keys']['p256dh']) || empty($input['keys']['auth'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid subscription data']);
            exit;
        }
        
        $userId = $_SESSION['user_id'] ?? null;
        $staffId = $_SESSION['staff_id'] ?? null;
        $endpoint = $input['endpoint'];
        $p256dhKey = $input['keys']['p256dh'];
        $authKey = $input['keys']['auth'];
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Check if this endpoint already exists for this user. Staff sessions
        // have $userId === null, and "user_id = NULL" never matches in SQL —
        // that variant used to insert a fresh duplicate row on every single
        // save for staff instead of updating, so match on whichever ID this
        // session actually has.
        if ($userId !== null) {
            $stmt = $conn->prepare("SELECT id FROM push_subscriptions WHERE endpoint = ? AND user_id = ?");
            $stmt->execute([$endpoint, $userId]);
        } else {
            $stmt = $conn->prepare("SELECT id FROM push_subscriptions WHERE endpoint = ? AND staff_id = ?");
            $stmt->execute([$endpoint, $staffId]);
        }
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing subscription
            $stmt = $conn->prepare("UPDATE push_subscriptions SET p256dh_key = ?, auth_key = ?, user_agent = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$p256dhKey, $authKey, $userAgent, $existing['id']]);
        } else {
            // Insert new subscription
            $stmt = $conn->prepare("INSERT INTO push_subscriptions (user_id, staff_id, endpoint, p256dh_key, auth_key, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $staffId, $endpoint, $p256dhKey, $authKey, $userAgent]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Subscription saved']);
        
    } elseif ($method === 'DELETE') {
        // Remove a subscription
        $input = json_decode(file_get_contents('php://input'), true);
        $endpoint = $input['endpoint'] ?? $_GET['endpoint'] ?? '';
        
        if (!$endpoint) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No endpoint specified']);
            exit;
        }
        
        $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND (user_id = ? OR staff_id = ?)");
        $stmt->execute([$endpoint, $_SESSION['user_id'] ?? 0, $_SESSION['staff_id'] ?? 0]);
        
        echo json_encode(['success' => true, 'message' => 'Subscription removed']);
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Push subscription error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
