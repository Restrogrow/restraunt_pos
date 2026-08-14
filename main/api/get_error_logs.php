<?php
/**
 * Get Error Logs API — for the Error Monitor dashboard
 * 
 * GET params:
 *   limit      - Max rows (default 50, max 200)
 *   offset     - Pagination offset
 *   source     - Filter by source (php|js|api|auth|db|state_machine|custom)
 *   severity   - Filter by severity (critical|error|warning|info|debug)
 *   search     - Search in message
 *   unread_only - 1 to show only unread
 *   since_id   - Get only errors with ID > this (for polling new errors)
 * 
 * Returns: { success: true, errors: [...], unread_count: N, total: N }
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
    // Require login (return JSON on failure)
    requireLogin(true);
    // Only admin can view errors (stack traces, internal file paths) — this
    // used to check PERMISSION_VIEW_DASHBOARD, which every staff role
    // (including Waiter/Chef) has, contradicting the comment's own intent.
    requirePermission(PERMISSION_MANAGE_SETTINGS, true);
}

try {
    $conn = getConnection();
    $restaurantId = $_SESSION['restaurant_id'] ?? null;

    $limit    = min((int)($_GET['limit'] ?? 50), 200);
    $offset   = max((int)($_GET['offset'] ?? 0), 0);
    $source   = $_GET['source'] ?? '';
    $severity = $_GET['severity'] ?? '';
    $search   = trim($_GET['search'] ?? '');
    $unreadOnly = !empty($_GET['unread_only']);
    $sinceId  = (int)($_GET['since_id'] ?? 0);

    // Build WHERE
    $conditions = [];
    $params = [];

    if ($restaurantId) {
        $conditions[] = "(restaurant_id = ? OR restaurant_id IS NULL)";
        $params[] = $restaurantId;
    }

    if ($source !== '' && $source !== 'all') {
        $conditions[] = "source = ?";
        $params[] = $source;
    }

    if ($severity !== '' && $severity !== 'all') {
        $conditions[] = "severity = ?";
        $params[] = $severity;
    }

    if ($search !== '') {
        $conditions[] = "message LIKE ?";
        $params[] = '%' . $search . '%';
    }

    if ($unreadOnly) {
        $conditions[] = "is_read = 0";
    }

    if ($sinceId > 0) {
        $conditions[] = "id > ?";
        $params[] = $sinceId;
    }

    $where = '';
    if (!empty($conditions)) {
        $where = 'WHERE ' . implode(' AND ', $conditions);
    }

    // Get total count
    $countSql = "SELECT COUNT(*) FROM error_logs $where";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Get unread count (no filters)
    $unreadSql = "SELECT COUNT(*) FROM error_logs WHERE is_read = 0";
    $unreadParams = [];
    if ($restaurantId) {
        $unreadSql .= " AND (restaurant_id = ? OR restaurant_id IS NULL)";
        $unreadParams[] = $restaurantId;
    }
    $unreadStmt = $conn->prepare($unreadSql);
    $unreadStmt->execute($unreadParams);
    $unreadCount = (int)$unreadStmt->fetchColumn();

    // Fetch rows
    $sql = "SELECT * FROM error_logs $where ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $allParams = array_merge($params, [$limit, $offset]);
    $stmt = $conn->prepare($sql);
    $stmt->execute($allParams);
    $errors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Truncate long messages for the list view
    foreach ($errors as &$row) {
        $row['message_short'] = mb_strlen($row['message']) > 200
            ? mb_substr($row['message'], 0, 200) . '...'
            : $row['message'];
        // Format created_at
        $row['created_at_formatted'] = date('d M Y, h:i A', strtotime($row['created_at']));
    }
    unset($row);

    echo json_encode([
        'success'      => true,
        'errors'       => $errors,
        'unread_count' => $unreadCount,
        'total'        => $total,
        'limit'        => $limit,
        'offset'       => $offset,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch error logs',
    ]);
}
