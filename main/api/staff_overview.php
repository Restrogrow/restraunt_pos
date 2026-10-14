<?php
/**
 * Staff page (dashboard › Staff, assets/js/staff-studio.js): the team with what each person did lately.
 *
 *   GET                                  staff, last login / last active and actions in 7 days (activity_log),
 *                                        their latest actions, and counts by role
 *   POST {action:'toggle', id, is_active} pause / resume someone's access (staff login needs is_active = 1)
 *
 * Add / edit / delete stay in controllers/staff_operations.php (the existing staff modal).
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
$canEdit = hasPermission(PERMISSION_MANAGE_STAFF);
session_write_close();
require_once __DIR__ . '/../db_connection.php';

function soOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $conn = getConnection();
    $q = function (string $sql, array $a = []) use ($conn) { $st = $conn->prepare($sql); $st->execute($a); return $st; };

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canEdit) soOut(['success' => false, 'message' => 'You don’t have permission to manage staff.'], 403);
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        if (($in['action'] ?? '') === 'toggle') {
            $id = (int)($in['id'] ?? 0);
            $on = !empty($in['is_active']) ? 1 : 0;
            $st = $q('UPDATE staff SET is_active = ? WHERE id = ? AND restaurant_id = ?', [$on, $id, $rid]);
            if (!$st->rowCount() && !$q('SELECT id FROM staff WHERE id = ? AND restaurant_id = ?', [$id, $rid])->fetchColumn()) soOut(['success' => false, 'message' => 'That staff member was not found.'], 404);
            if (is_file(__DIR__ . '/../config/activity_log.php')) {
                require_once __DIR__ . '/../config/activity_log.php';
                $name = (string)$q('SELECT member_name FROM staff WHERE id = ?', [$id])->fetchColumn();
                if (function_exists('logActivity')) logActivity($conn, $rid, $on ? 'staff.resume' : 'staff.pause', ($on ? 'Gave access back to ' : 'Paused access for ') . $name, [], 'staff', $id);
            }
            soOut(['success' => true]);
        }
        soOut(['success' => false, 'message' => 'Unknown action'], 400);
    }

    $staff = $q('SELECT id, member_name, email, phone, role, is_active, created_at FROM staff WHERE restaurant_id = ? ORDER BY is_active DESC, role, member_name', [$rid])->fetchAll(PDO::FETCH_ASSOC);
    $act = [];
    try {
        foreach ($q("SELECT staff_id, MAX(created_at) AS last_active, MAX(CASE WHEN action = 'login.staff' THEN created_at END) AS last_login,
                SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND action <> 'login.staff') AS actions_7d
            FROM activity_log WHERE restaurant_id = ? AND staff_id IS NOT NULL GROUP BY staff_id", [$rid])->fetchAll(PDO::FETCH_ASSOC) as $r) $act[(int)$r['staff_id']] = $r;
        $recent = [];
        foreach ($q("SELECT staff_id, action, summary, created_at FROM activity_log WHERE restaurant_id = ? AND staff_id IS NOT NULL AND action <> 'login.staff' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY id DESC LIMIT 300", [$rid])->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sid = (int)$r['staff_id'];
            if (count($recent[$sid] ?? []) < 3) $recent[$sid][] = ['action' => $r['action'], 'summary' => $r['summary'], 'at' => $r['created_at']];
        }
    } catch (PDOException $e) { $recent = []; }   // no activity log yet

    $roles = []; $active = 0; $today = 0;
    foreach ($staff as &$s) {
        $s['id'] = (int)$s['id']; $s['is_active'] = (int)$s['is_active'];
        $a = $act[$s['id']] ?? [];
        $s['last_active'] = $a['last_active'] ?? null;
        $s['last_login'] = $a['last_login'] ?? null;
        $s['actions_7d'] = (int)($a['actions_7d'] ?? 0);
        $s['recent'] = $recent[$s['id']] ?? [];
        $roles[$s['role']] = ($roles[$s['role']] ?? 0) + 1;
        if ($s['is_active']) $active++;
        if ($s['last_active'] && substr($s['last_active'], 0, 10) === date('Y-m-d')) $today++;
    }
    unset($s);
    soOut(['success' => true, 'can_edit' => $canEdit, 'staff' => $staff, 'totals' => ['total' => count($staff), 'active' => $active, 'today' => $today, 'roles' => $roles], 'now' => date('Y-m-d H:i:s')]);
} catch (Throwable $e) {
    error_log('staff_overview: ' . $e->getMessage());
    soOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
