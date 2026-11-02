<?php
/**
 * Cash book page (dashboard, assets/js/cashbook-studio.js): the drawer's shifts — opening balance,
 * cash in / out, counted closing balance and the difference (config/cash_session.php).
 *
 *   GET                                  the open shift with its running report, and this month's shifts
 *   GET ?list=1&from=Y-m-d&to=Y-m-d      past shifts for a period
 *   GET ?session=ID                      one shift's full report (the printable Z report)
 *   POST {action:'open', opening, note}  open the drawer with the cash already in it
 *   POST {action:'move', direction:'in'|'out', amount, reason}   cash that isn't a bill
 *   POST {action:'close', counted, note} count the drawer and shut the shift (frozen afterwards)
 *
 * Reading needs "view reports"; opening, moving cash and closing need "manage cash".
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
if (!hasPermission(PERMISSION_MANAGE_CASH) && !hasPermission(PERMISSION_VIEW_REPORTS)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to the cash book.']);
    exit;
}
$rid = (string)$_SESSION['restaurant_id'];
$canEdit = hasPermission(PERMISSION_MANAGE_CASH);
$who = [
    'id'   => (int)($_SESSION['staff_id'] ?? 0),
    'name' => (string)($_SESSION['username'] ?? $_SESSION['staff_name'] ?? $_SESSION['user_name'] ?? 'Owner'),
];
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/cash_session.php';

function cbOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function cbDay($v, string $fallback): string
{
    return (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && DateTime::createFromFormat('!Y-m-d', $v)) ? $v : $fallback;
}

try {
    $conn = getConnection();
    csEnsure($conn);
    $tz = csTz($conn, $rid);
    $st = $conn->prepare('SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1');
    $st->execute([$rid]);
    $currency = (string)($st->fetchColumn() ?: '₹');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if (!empty($_GET['session'])) {
            $report = csOne($conn, $rid, (int)$_GET['session']);
            if (!$report) cbOut(['success' => false, 'message' => 'That shift was not found.'], 404);
            cbOut(['success' => true, 'report' => $report, 'currency' => $currency]);
        }
        if (!empty($_GET['list'])) {
            $from = cbDay($_GET['from'] ?? '', (new DateTime('first day of this month', $tz))->format('Y-m-d'));
            $to   = cbDay($_GET['to'] ?? '', (new DateTime('today', $tz))->format('Y-m-d'));
            if ($to < $from) [$from, $to] = [$to, $from];
            cbOut(['success' => true, 'sessions' => csList($conn, $rid, $from, $to), 'from' => $from, 'to' => $to, 'currency' => $currency]);
        }
        $open = csCurrent($conn, $rid);
        cbOut([
            'success'  => true,
            'can_edit' => $canEdit,
            'currency' => $currency,
            'me'       => $who['name'],
            'today'    => (new DateTime('today', $tz))->format('Y-m-d'),
            'open'     => $open ? csSummary($conn, $rid, $open) : null,
            'sessions' => csList($conn, $rid, (new DateTime('first day of this month', $tz))->format('Y-m-d'), (new DateTime('today', $tz))->format('Y-m-d')),
        ]);
    }

    if (!$canEdit) cbOut(['success' => false, 'message' => 'You don’t have permission to open or close the drawer.'], 403);

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');

    if ($action === 'open') {
        $s = csOpen($conn, $rid, (float)($in['opening'] ?? 0), $who, (string)($in['note'] ?? ''));
        cbOut(['success' => true, 'open' => csSummary($conn, $rid, $s)]);
    }

    if ($action === 'move') {
        $m = csMove($conn, $rid, (string)($in['direction'] ?? 'in'), (float)($in['amount'] ?? 0), (string)($in['reason'] ?? ''), $who);
        $open = csCurrent($conn, $rid);
        cbOut(['success' => true, 'movement' => $m, 'open' => $open ? csSummary($conn, $rid, $open) : null]);
    }

    if ($action === 'close') {
        if (!isset($in['counted']) || !is_numeric($in['counted'])) cbOut(['success' => false, 'message' => 'Enter the cash you counted.'], 400);
        $report = csClose($conn, $rid, (float)$in['counted'], $who, (string)($in['note'] ?? ''));
        cbOut(['success' => true, 'report' => $report]);
    }

    cbOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (InvalidArgumentException $e) {
    cbOut(['success' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('cashbook.php: ' . $e->getMessage());
    cbOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
