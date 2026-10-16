<?php
/**
 * Waiter Requests board (dashboard › Waiter Requests, assets/js/waiter-studio.js).
 *
 *   GET   tables waiting now (oldest first, with area and how long), what was attended today with the
 *         response time, and today's numbers (waiting, oldest wait, attended, average response).
 * Mark attended stays in controllers/waiter_request_operations.php (window.markAttended).
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}
$rid = (string)$_SESSION['restaurant_id'];
session_write_close();
require_once __DIR__ . '/../db_connection.php';

function wrOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $conn = getConnection();
    $q = function (string $sql, array $a = []) use ($conn) { $st = $conn->prepare($sql); $st->execute($a); return $st; };
    $cols = "wr.id, wr.request_type, wr.notes, wr.created_at, wr.attended_at, t.table_number, a.area_name";
    $join = "FROM waiter_requests wr LEFT JOIN tables t ON t.id = wr.table_id LEFT JOIN areas a ON a.id = t.area_id";

    $waiting = $q("SELECT $cols, TIMESTAMPDIFF(SECOND, wr.created_at, NOW()) AS waited $join
        WHERE wr.restaurant_id = ? AND wr.status = 'Pending' ORDER BY wr.created_at ASC LIMIT 200", [$rid])->fetchAll(PDO::FETCH_ASSOC);
    $done = $q("SELECT $cols, TIMESTAMPDIFF(SECOND, wr.created_at, wr.attended_at) AS response $join
        WHERE wr.restaurant_id = ? AND wr.status = 'Attended' AND wr.attended_at >= CURDATE() ORDER BY wr.attended_at DESC LIMIT 100", [$rid])->fetchAll(PDO::FETCH_ASSOC);

    foreach ($waiting as &$w) { $w['id'] = (int)$w['id']; $w['waited'] = max(0, (int)$w['waited']); }
    unset($w);
    $resp = [];
    foreach ($done as &$d) { $d['id'] = (int)$d['id']; $d['response'] = $d['response'] !== null ? max(0, (int)$d['response']) : null; if ($d['response'] !== null) $resp[] = $d['response']; }
    unset($d);
    sort($resp);
    $median = $resp ? $resp[(int)floor((count($resp) - 1) / 2)] : null;

    // the "customers can call a waiter from the website" switch (Waiter Requests page)
    require_once __DIR__ . '/../config/waiter_call.php';
    waiterCallEnsureSchema($conn);
    $set = $q("SELECT COALESCE(call_waiter_enabled, 1) AS on_, COALESCE(enable_dinein, 1) AS dinein FROM users WHERE restaurant_id = ? LIMIT 1", [$rid])->fetch(PDO::FETCH_ASSOC) ?: [];
    $tables = (int)$q("SELECT COUNT(*) FROM tables WHERE restaurant_id = ?", [$rid])->fetchColumn();
    $canEdit = !empty($_SESSION['user_id']) || !empty($_SESSION['branch_admin_id']);

    wrOut(['success' => true, 'now' => date('Y-m-d H:i:s'), 'waiting' => $waiting, 'attended' => $done,
        'website_calls' => ['enabled' => (int)($set['on_'] ?? 1) === 1, 'dinein' => (int)($set['dinein'] ?? 1) === 1, 'tables' => $tables, 'can_edit' => $canEdit],
        'stats' => ['waiting' => count($waiting), 'oldest' => $waiting ? $waiting[0]['waited'] : 0, 'attended_today' => count($done),
            'avg_response' => $resp ? (int)round(array_sum($resp) / count($resp)) : null, 'median_response' => $median]]);
} catch (Throwable $e) {
    error_log('waiter_requests_overview: ' . $e->getMessage());
    wrOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
