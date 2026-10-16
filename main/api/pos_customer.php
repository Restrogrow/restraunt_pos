<?php
/**
 * Customers for the POS (and the Customers page's account QR).
 *
 *   GET  ?q=text           up to 8 customers matching a name or phone, with points / wallet
 *   GET  ?id=N             one customer with everything the POS shows (config/pos_loyalty.php)
 *   POST {action:'create', name, phone, email?, birthday?}
 *                          a new customer; if the phone already belongs to one, that one comes back
 *   POST {action:'claim', id}
 *                          a one-time "complete your account" code for a customer without a
 *                          website account (the QR opens the website's login page with it)
 *
 * Staff who can take orders (manage_orders) or manage customers can use it.
 */
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin(); // the companion app's dev web preview (logged-in requests)
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id']) || !(hasPermission(PERMISSION_MANAGE_ORDERS) || hasPermission(PERMISSION_MANAGE_CUSTOMERS))) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}
$rid = (string)$_SESSION['restaurant_id'];
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/pos_loyalty.php';

function pcOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $conn = getConnection();
    posEnsureSchema($conn);
    $one = function (int $id) use ($conn, $rid) {
        $st = $conn->prepare('SELECT * FROM customers WHERE id = ? AND restaurant_id = ?');
        $st->execute([$id, $rid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['id'])) {
            $c = $one((int)$_GET['id']);
            if (!$c) pcOut(['success' => false, 'message' => 'Customer not found.'], 404);
            pcOut(['success' => true, 'customer' => posCustomerSummary($conn, $rid, $c)]);
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) pcOut(['success' => true, 'customers' => []]);
        $digits = preg_replace('/\D/', '', $q);
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $sql = 'SELECT id, customer_name, phone, email, loyalty_points_balance, total_visits, total_spent, password_hash IS NOT NULL has_account FROM customers WHERE restaurant_id = ? AND (customer_name LIKE ? OR email LIKE ?';
        $args = [$rid, $like, $like];
        if (strlen($digits) >= 3) { $sql .= ' OR phone LIKE ?'; $args[] = '%' . $digits . '%'; }
        $sql .= ') ORDER BY (phone = ?) DESC, last_visit_date DESC, customer_name LIMIT 8';
        $args[] = $digits;
        $st = $conn->prepare($sql);
        $st->execute($args);
        $rows = array_map(function ($r) {
            return ['id' => (int)$r['id'], 'name' => $r['customer_name'], 'phone' => $r['phone'], 'email' => $r['email'], 'points' => (int)$r['loyalty_points_balance'],
                'visits' => (int)$r['total_visits'], 'spent' => (float)$r['total_spent'], 'has_account' => (bool)$r['has_account']];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        pcOut(['success' => true, 'customers' => $rows]);
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $in['action'] ?? '';

    if ($action === 'create') {
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100);
        $phone = posNormPhone((string)($in['phone'] ?? ''));
        $email = trim((string)($in['email'] ?? ''));
        $bday = trim((string)($in['birthday'] ?? ''));
        if (mb_strlen($name) < 2) pcOut(['success' => false, 'message' => 'Enter the customer’s name.'], 400);
        if (strlen($phone) < 6 || strlen($phone) > 15) pcOut(['success' => false, 'message' => 'Enter a valid phone number.'], 400);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) pcOut(['success' => false, 'message' => 'That email doesn’t look right.'], 400);
        $bday = preg_match('/^\d{4}-\d{2}-\d{2}$/', $bday) && $bday <= date('Y-m-d') && $bday >= '1900-01-01' ? $bday : null;
        $st = $conn->prepare('SELECT id FROM customers WHERE restaurant_id = ? AND phone = ? LIMIT 1');
        $st->execute([$rid, $phone]);
        $id = (int)$st->fetchColumn();
        $existed = $id > 0;
        if (!$existed) {
            $conn->prepare('INSERT INTO customers (restaurant_id, customer_name, phone, email, birthday) VALUES (?, ?, ?, ?, ?)')
                 ->execute([$rid, $name, $phone, $email !== '' ? $email : null, $bday]);
            $id = (int)$conn->lastInsertId();
        } else {
            // fill in what was missing, never overwrite what the customer set themselves
            $conn->prepare("UPDATE customers SET email = COALESCE(NULLIF(email, ''), ?), birthday = COALESCE(birthday, ?) WHERE id = ?")
                 ->execute([$email !== '' ? $email : null, $bday, $id]);
        }
        pcOut(['success' => true, 'existed' => $existed, 'customer' => posCustomerSummary($conn, $rid, $one($id))]);
    }

    if ($action === 'claim') {
        $c = $one((int)($in['id'] ?? 0));
        if (!$c) pcOut(['success' => false, 'message' => 'Customer not found.'], 404);
        if (!empty($c['password_hash'])) pcOut(['success' => false, 'message' => 'This customer already has an account. They can log in with their phone number.'], 400);
        if (empty($c['phone'])) pcOut(['success' => false, 'message' => 'Add a phone number to this customer first.'], 400);
        pcOut(['success' => true, 'code' => posCreateClaimCode($conn, $rid, (int)$c['id']), 'valid_days' => 14]);
    }

    pcOut(['success' => false, 'message' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    error_log('pos_customer: ' . $e->getMessage());
    pcOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
