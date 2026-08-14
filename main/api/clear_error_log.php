<?php
/**
 * Clear/Acknowledge Error Logs API
 * 
 * POST actions:
 *   mark_read    - Mark single error as read (id required)
 *   mark_all_read - Mark ALL errors as read for this restaurant
 *   acknowledge  - Acknowledge (assign) an error (id + note required)
 *   delete       - Delete a single error (id required)
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/error_monitor.php';

startSecureSession();

// Allow superadmin access without requiring dashboard permissions
$isSuperadmin = isset($_SESSION['superadmin_id']);
if (!$isSuperadmin) {
    requireLogin(true);
    // Admin-only — this endpoint can permanently delete a restaurant's error
    // logs, and PERMISSION_VIEW_DASHBOARD is held by every staff role.
    requirePermission(PERMISSION_MANAGE_SETTINGS, true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);
$note   = trim($_POST['note'] ?? '');

try {
    $conn = getConnection();
    $restaurantId = $_SESSION['restaurant_id'] ?? null;
    $username = $_SESSION['username'] ?? 'unknown';

    switch ($action) {

        case 'mark_read':
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID required']);
                exit;
            }
            $sql = "UPDATE error_logs SET is_read = 1 WHERE id = ?";
            $params = [$id];
            if ($restaurantId) {
                $sql .= " AND (restaurant_id = ? OR restaurant_id IS NULL)";
                $params[] = $restaurantId;
            }
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'message' => 'Marked as read']);
            break;

        case 'mark_all_read':
            $sql = "UPDATE error_logs SET is_read = 1 WHERE is_read = 0";
            $params = [];
            if ($restaurantId) {
                $sql .= " AND (restaurant_id = ? OR restaurant_id IS NULL)";
                $params[] = $restaurantId;
            }
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'message' => 'All marked as read']);
            break;

        case 'acknowledge':
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID required']);
                exit;
            }
            // Missing the restaurant_id scoping its sibling actions
            // (mark_read/mark_all_read/delete) already have — any logged-in
            // staff could acknowledge another restaurant's error-log entry
            // by guessing/incrementing id.
            $sql = "UPDATE error_logs SET is_read = 1, is_acknowledged = 1, acknowledged_by = ?, acknowledged_at = NOW() WHERE id = ?";
            $params = [$username, $id];
            if ($restaurantId) {
                $sql .= " AND (restaurant_id = ? OR restaurant_id IS NULL)";
                $params[] = $restaurantId;
            }
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'message' => 'Acknowledged']);
            break;

        case 'delete':
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID required']);
                exit;
            }
            $sql = "DELETE FROM error_logs WHERE id = ?";
            $params = [$id];
            if ($restaurantId) {
                $sql .= " AND (restaurant_id = ? OR restaurant_id IS NULL)";
                $params[] = $restaurantId;
            }
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'message' => 'Deleted']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Operation failed']);
}
