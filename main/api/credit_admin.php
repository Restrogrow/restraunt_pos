<?php
/**
 * Credit page (dashboard, assets/js/credit-studio.js): credit accounts, what is outstanding and the
 * payments against it (config/credit_ledger.php).
 *
 *   GET                                      overview + accounts (?filter=owing|clear|all|off)
 *   GET ?account=ID                          one account's statement, every charge and payment
 *   POST {action:'save', account}            create / edit an account (name, phone, limit, note, active)
 *   POST {action:'charge', id, amount, note} put an amount on the account by hand
 *   POST {action:'pay', id, amount, method, note}   money paid back (cash also goes into the drawer)
 *   POST {action:'adjust', id, amount, note} a signed correction, reason required
 *
 * Reading needs "view reports" or "manage credit"; changing anything needs "manage credit".
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
if (!hasPermission(PERMISSION_MANAGE_CREDIT) && !hasPermission(PERMISSION_VIEW_REPORTS)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to credit accounts.']);
    exit;
}
$rid = (string)$_SESSION['restaurant_id'];
$canEdit = hasPermission(PERMISSION_MANAGE_CREDIT);
$who = ['id' => (int)($_SESSION['staff_id'] ?? 0),
        'name' => (string)($_SESSION['username'] ?? $_SESSION['staff_name'] ?? $_SESSION['user_name'] ?? 'Owner')];
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/credit_ledger.php';

function crOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $conn = getConnection();
    clEnsure($conn);
    $st = $conn->prepare('SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1');
    $st->execute([$rid]);
    $currency = (string)($st->fetchColumn() ?: '₹');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if (!empty($_GET['account'])) {
            $s = clStatement($conn, $rid, (int)$_GET['account']);
            if (!$s) crOut(['success' => false, 'message' => 'That account was not found.'], 404);
            crOut(['success' => true, 'statement' => $s, 'currency' => $currency, 'can_edit' => $canEdit]);
        }
        $filter = in_array($_GET['filter'] ?? '', ['owing', 'clear', 'all', 'off'], true) ? $_GET['filter'] : 'owing';
        crOut(['success' => true, 'can_edit' => $canEdit, 'currency' => $currency, 'filter' => $filter,
               'overview' => clOverview($conn, $rid), 'accounts' => clAccounts($conn, $rid, $filter)]);
    }

    if (!$canEdit) crOut(['success' => false, 'message' => 'You don’t have permission to change credit accounts.'], 403);

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');

    if ($action === 'save') {
        $a = (array)($in['account'] ?? []);
        $id = (int)($a['id'] ?? 0);
        $name = mb_substr(trim(strip_tags((string)($a['name'] ?? ''))), 0, 120);
        $phone = clPhone($a['phone'] ?? '');
        $limit = max(0, min(99999999, round((float)($a['limit'] ?? 0), 2)));
        $note = mb_substr(trim(strip_tags((string)($a['notes'] ?? ''))), 0, 255);
        $active = empty($a['active']) && isset($a['active']) ? 0 : 1;
        if ($name === '') crOut(['success' => false, 'message' => 'Enter a name for the account.'], 400);
        if (strlen($phone) < 6 || strlen($phone) > 15) crOut(['success' => false, 'message' => 'Enter a valid phone number — it is how the POS finds the account.'], 400);
        $clash = $conn->prepare('SELECT id FROM credit_accounts WHERE restaurant_id = ? AND phone = ? AND id <> ?');
        $clash->execute([$rid, $phone, $id]);
        if ($clash->fetchColumn()) crOut(['success' => false, 'message' => 'Another account already uses that phone number.'], 400);
        if ($id) {
            if (!clAccount($conn, $rid, $id)) crOut(['success' => false, 'message' => 'That account was not found.'], 404);
            $conn->prepare('UPDATE credit_accounts SET name = ?, phone = ?, limit_amount = ?, notes = ?, is_active = ? WHERE id = ? AND restaurant_id = ?')
                 ->execute([$name, $phone, $limit, $note ?: null, $active, $id, $rid]);
        } else {
            $conn->prepare('INSERT INTO credit_accounts (restaurant_id, name, phone, limit_amount, notes, is_active) VALUES (?, ?, ?, ?, ?, ?)')
                 ->execute([$rid, $name, $phone, $limit, $note ?: null, $active]);
            $id = (int)$conn->lastInsertId();
        }
        crOut(['success' => true, 'id' => $id]);
    }

    $id = (int)($in['id'] ?? 0);

    if ($action === 'charge') {
        $r = clCharge($conn, $rid, $id, (float)($in['amount'] ?? 0),
                      ['note' => (string)($in['note'] ?? 'Added by hand'), 'method' => 'Credit'], $who, !empty($in['force']));
        crOut(['success' => true, 'balance' => $r['balance']]);
    }

    if ($action === 'pay') {
        $r = clPay($conn, $rid, $id, (float)($in['amount'] ?? 0), (string)($in['method'] ?? 'Cash'), (string)($in['note'] ?? ''), $who);
        crOut(['success' => true, 'balance' => $r['balance'], 'in_drawer' => !empty($r['in_drawer'])]);
    }

    if ($action === 'adjust') {
        $r = clAdjust($conn, $rid, $id, (float)($in['amount'] ?? 0), (string)($in['note'] ?? ''), $who);
        crOut(['success' => true, 'balance' => $r['balance']]);
    }

    crOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (InvalidArgumentException $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    crOut(['success' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('credit_admin.php: ' . $e->getMessage());
    crOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
