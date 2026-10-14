<?php
/**
 * Profile › Sign-in activity (assets/js/profile-studio.js): the logged-in person's own latest sign-ins
 * (and failed tries) from login_logs, so they can spot a login that wasn't them.
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
$name = (string)($_SESSION['username'] ?? '');
$type = (string)($_SESSION['user_type'] ?? 'admin');
session_write_close();
require_once __DIR__ . '/../db_connection.php';

try {
    $conn = getConnection();
    $st = $conn->prepare("SELECT outcome, device_label, ip_address, is_new_device, created_at FROM login_logs
        WHERE restaurant_id = ? AND user_type = ? AND (username_tried = ? OR actor_name = ?) ORDER BY id DESC LIMIT 8");
    $st->execute([$rid, $type, $name, $name]);
    $rows = array_map(function ($r) {
        // half the address is enough to recognise a network, without showing all of it
        $ip = (string)$r['ip_address'];
        if (strpos($ip, '.') !== false) { $p = explode('.', $ip); $ip = $p[0] . '.' . ($p[1] ?? '*') . '.*.*'; }
        elseif ($ip !== '' && $ip !== '::1') { $ip = implode(':', array_slice(explode(':', $ip), 0, 3)) . ':…'; }
        return ['ok' => $r['outcome'] === 'success', 'outcome' => $r['outcome'], 'device' => $r['device_label'] ?: 'Unknown device', 'ip' => $ip === '::1' ? 'this computer' : $ip,
            'new_device' => (int)$r['is_new_device'] === 1, 'at' => $r['created_at']];
    }, $st->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['success' => true, 'logins' => $rows], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('profile_security: ' . $e->getMessage());
    echo json_encode(['success' => true, 'logins' => []]);
}
