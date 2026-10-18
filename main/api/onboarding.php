<?php
/**
 * First-day onboarding for the restaurant dashboard (assets/js/onboarding.js).
 *
 * GET               the "Get ready" guide (steps done / not done from the restaurant's data), whether the
 *                   tour was seen or skipped, and whether the guide should show at all
 * POST action=…     tour_done | tour_skipped | tour_again | guide_hidden | guide_shown
 *                   mark (key=share|staff)            a step the data can't see
 *                   quick_tables                      makes area "Main hall" + tables T1–T10 when there are none
 * Owner / branch admin only. Progress lives in onboarding_state (config/onboarding.php), so it is
 * remembered on every device and never asked twice.
 */
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/onboarding.php';
header('Content-Type: application/json; charset=UTF-8');

function obOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (empty($_SESSION['restaurant_id']) || (empty($_SESSION['user_id']) && empty($_SESSION['staff_id']) && empty($_SESSION['branch_admin_id']))) obOut(['success' => false, 'message' => 'Please log in again.'], 401);   // not a website visitor
$rid = (string)$_SESSION['restaurant_id'];
$isOwner = in_array($_SESSION['user_type'] ?? '', ['admin', 'branch_admin'], true);
session_write_close();

try {
    $conn = getConnection();
    obEnsure($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $g = obGuide($conn, $rid);
        $u = $conn->prepare('SELECT created_at FROM users WHERE restaurant_id = ? LIMIT 1');
        $u->execute([$rid]);
        $created = (string)$u->fetchColumn();
        $ageDays = $created ? (int)floor((time() - strtotime($created)) / 86400) : 999;
        // the guide is for the first month; the tour starts by itself only for new restaurants (or ?welcome=1)
        $g['show_guide'] = $isOwner && !$g['complete'] && !$g['state']['guide_hidden'] && $ageDays <= 45;
        $g['auto_tour'] = $isOwner && !$g['state']['tour_done'] && $ageDays <= 14;
        $g['is_owner'] = $isOwner;
        $g['age_days'] = $ageDays;
        $g['support_whatsapp'] = preg_replace('/\D/', '', (string)env('SUPPORT_WHATSAPP', '916377568749'));
        obOut(['success' => true] + $g);
    }

    if (!$isOwner) obOut(['success' => false, 'message' => 'Only the owner can change this.'], 403);
    $in = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?: []);
    $action = (string)($in['action'] ?? '');

    if (in_array($action, ['tour_done', 'tour_skipped', 'tour_again', 'guide_hidden', 'guide_shown'], true)) {
        obSave($conn, $rid, $action);
        obOut(['success' => true]);
    }
    if ($action === 'mark') {
        $key = (string)($in['key'] ?? '');
        if (!in_array($key, ['share', 'staff', 'menu_photo'], true)) obOut(['success' => false, 'message' => 'Unknown step'], 400);
        obSave($conn, $rid, 'mark', $key);
        obOut(['success' => true]);
    }
    if ($action === 'quick_tables') {
        $n = obCount($conn, 'SELECT COUNT(*) FROM tables WHERE restaurant_id = ?', [$rid]);
        if ($n > 0) obOut(['success' => false, 'message' => 'You already have tables. Change them in Tables.']);
        $area = $conn->prepare('SELECT id FROM areas WHERE restaurant_id = ? ORDER BY sort_order, id LIMIT 1');
        $area->execute([$rid]);
        $areaId = (int)$area->fetchColumn();
        if (!$areaId) {
            $conn->prepare('INSERT INTO areas (restaurant_id, area_name, sort_order) VALUES (?, ?, 1)')->execute([$rid, 'Main hall']);
            $areaId = (int)$conn->lastInsertId();
        }
        $ins = $conn->prepare('INSERT INTO tables (restaurant_id, area_id, table_number, capacity, is_available, sort_order, created_at, updated_at) VALUES (?, ?, ?, 4, 1, ?, NOW(), NOW())');
        for ($i = 1; $i <= 10; $i++) $ins->execute([$rid, $areaId, 'T' . $i, $i]);
        obOut(['success' => true, 'message' => 'Tables T1 to T10 are ready. Rename or add more in Tables.']);
    }
    obOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('[onboarding api] ' . $e->getMessage());
    obOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
