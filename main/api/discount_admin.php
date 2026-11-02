<?php
/**
 * Discounts given page (dashboard, assets/js/discount-studio.js): every discount taken off a bill —
 * who gave it, why, and who allowed it — plus the ceilings per role (config/staff_discount.php).
 *
 *   GET ?from=Y-m-d&to=Y-m-d      the report (by person, by reason, by day) and the list behind it
 *   GET ?settings=1               the ceilings per role, the reasons, and whether a reason is required
 *   POST {action:'save_settings', settings:{caps:{Role:{percent,amount}}, reasons:[], require_reason}}
 *
 * Reading needs "view reports"; changing the ceilings needs "approve discount" (the owner or a manager).
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
if (!hasPermission(PERMISSION_VIEW_REPORTS) && !hasPermission(PERMISSION_APPROVE_DISCOUNT)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to the discount report.']);
    exit;
}
$rid = (string)$_SESSION['restaurant_id'];
$canEdit = hasPermission(PERMISSION_APPROVE_DISCOUNT);
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/staff_discount.php';
require_once __DIR__ . '/../config/cash_session.php';

function dsOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $conn = getConnection();
    sdEnsure($conn);
    $tz = csTz($conn, $rid);
    $st = $conn->prepare('SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1');
    $st->execute([$rid]);
    $currency = (string)($st->fetchColumn() ?: '₹');
    // the roles a cap can be set on: whatever the team actually uses, plus the built-in ones
    $roles = ['Manager', 'Cashier', 'Waiter', 'Chef', 'Bartender'];
    try {
        $used = $conn->prepare('SELECT DISTINCT role FROM staff WHERE restaurant_id = ? AND role IS NOT NULL');
        $used->execute([$rid]);
        foreach ($used->fetchAll(PDO::FETCH_COLUMN) as $r) if ($r !== '' && !in_array($r, $roles, true)) $roles[] = (string)$r;
    } catch (PDOException $e) {}

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $day = fn($v, $d) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && DateTime::createFromFormat('!Y-m-d', $v)) ? $v : $d;
        $from = $day($_GET['from'] ?? '', (new DateTime('first day of this month', $tz))->format('Y-m-d'));
        $to = $day($_GET['to'] ?? '', (new DateTime('today', $tz))->format('Y-m-d'));
        if ($to < $from) [$from, $to] = [$to, $from];
        if ((new DateTime($from))->diff(new DateTime($to))->days > 366) {
            dsOut(['success' => false, 'message' => 'Pick up to one year at a time.'], 400);
        }
        dsOut(['success' => true, 'can_edit' => $canEdit, 'currency' => $currency, 'roles' => $roles,
               'settings' => sdSettings($conn, $rid), 'all_reasons' => SD_REASONS,
               'report' => sdReport($conn, $rid, $from, $to), 'list' => sdList($conn, $rid, $from, $to)]);
    }

    if (!$canEdit) dsOut(['success' => false, 'message' => 'You don’t have permission to change the discount limits.'], 403);

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    if ((string)($in['action'] ?? '') === 'save_settings') {
        dsOut(['success' => true, 'settings' => sdSettingsSave($conn, $rid, (array)($in['settings'] ?? []))]);
    }
    dsOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (InvalidArgumentException $e) {
    dsOut(['success' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('discount_admin.php: ' . $e->getMessage());
    dsOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
