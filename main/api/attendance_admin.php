<?php
/**
 * Attendance and Payroll pages (dashboard, assets/js/attendance-studio.js): who worked which day, and
 * what that comes to at the end of the month (config/attendance.php).
 *
 *   GET ?view=month&period=YYYY-MM    the month's grid: every person, every marked day, their day counts
 *   GET ?view=payroll&period=YYYY-MM  salary per person from that attendance, with advances and bonuses
 *   GET ?view=staff                   the team with their salary settings
 *   POST {action:'mark', staff_id, date, status, punch_in?, punch_out?, minutes?, overtime_minutes?, note?}
 *   POST {action:'punch', staff_id}   clock in, or clock out when already clocked in today
 *   POST {action:'salary', staff_id, salary_type, salary, joined_on?}
 *   POST {action:'entry', staff_id, period, type:'advance'|'deduction'|'bonus', amount, note?}
 *   POST {action:'entry_delete', id}
 *   POST {action:'pay', staff_id, period, method?}     freeze the month as paid
 *   POST {action:'reopen', staff_id, period}           undo that, so it can be corrected
 *
 * Everything needs "manage attendance", except reading, which "view reports" also opens.
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
if (!hasPermission(PERMISSION_MANAGE_ATTENDANCE) && !hasPermission(PERMISSION_VIEW_REPORTS)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to attendance.']);
    exit;
}
$rid = (string)$_SESSION['restaurant_id'];
$canEdit = hasPermission(PERMISSION_MANAGE_ATTENDANCE);
$who = ['id' => (int)($_SESSION['staff_id'] ?? 0),
        'name' => (string)($_SESSION['username'] ?? $_SESSION['staff_name'] ?? $_SESSION['user_name'] ?? 'Owner')];
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/attendance.php';

function atOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $conn = getConnection();
    atEnsure($conn);
    $st = $conn->prepare('SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1');
    $st->execute([$rid]);
    $currency = (string)($st->fetchColumn() ?: '₹');
    $labels = [];
    foreach (AT_STATUS_LABELS as $k => $v) $labels[] = ['key' => $k, 'label' => $v];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $view = (string)($_GET['view'] ?? 'month');
        $period = atPeriod($conn, $rid, $_GET['period'] ?? '');
        $base = ['success' => true, 'can_edit' => $canEdit, 'currency' => $currency, 'today' => atToday($conn, $rid),
                 'period' => $period, 'statuses' => $labels, 'salary_types' => AT_SALARY_TYPES];
        if ($view === 'staff') atOut($base + ['staff' => atStaff($conn, $rid, false)]);
        if ($view === 'payroll') atOut($base + ['payroll' => plPayroll($conn, $rid, $period)]);
        atOut($base + ['month' => atMonth($conn, $rid, $period)]);
    }

    if (!$canEdit) atOut(['success' => false, 'message' => 'You don’t have permission to change attendance.'], 403);

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');
    $staffId = (int)($in['staff_id'] ?? 0);

    if ($action === 'mark') {
        $r = atMark($conn, $rid, $staffId, (string)($in['date'] ?? ''), (string)($in['status'] ?? 'present'), [
            'punch_in' => $in['punch_in'] ?? null, 'punch_out' => $in['punch_out'] ?? null,
            'minutes' => $in['minutes'] ?? null, 'overtime_minutes' => $in['overtime_minutes'] ?? 0, 'note' => $in['note'] ?? '',
        ], $who);
        atOut(['success' => true, 'day' => $r]);
    }

    if ($action === 'punch') {
        atOut(['success' => true, 'punch' => atPunch($conn, $rid, $staffId, $who)]);
    }

    if ($action === 'salary') {
        $joined = isset($in['joined_on']) && $in['joined_on'] !== '' ? atDay($in['joined_on'], '') : '';
        atOut(['success' => true, 'staff' => atSetSalary($conn, $rid, $staffId, (string)($in['salary_type'] ?? 'monthly'),
            (float)($in['salary'] ?? 0), $joined ?: null)]);
    }

    if ($action === 'entry') {
        $r = plAdd($conn, $rid, $staffId, atPeriod($conn, $rid, $in['period'] ?? ''), (string)($in['type'] ?? 'advance'),
                   (float)($in['amount'] ?? 0), (string)($in['note'] ?? ''), $who);
        atOut(['success' => true, 'entry' => $r]);
    }

    if ($action === 'entry_delete') {
        plRemove($conn, $rid, (int)($in['id'] ?? 0));
        atOut(['success' => true]);
    }

    if ($action === 'pay') {
        atOut(['success' => true, 'run' => plMarkPaid($conn, $rid, $staffId, atPeriod($conn, $rid, $in['period'] ?? ''),
            (string)($in['method'] ?? 'Cash'), $who)]);
    }

    if ($action === 'reopen') {
        plReopen($conn, $rid, $staffId, atPeriod($conn, $rid, $in['period'] ?? ''));
        atOut(['success' => true]);
    }

    atOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (InvalidArgumentException $e) {
    atOut(['success' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('attendance_admin.php: ' . $e->getMessage());
    atOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
