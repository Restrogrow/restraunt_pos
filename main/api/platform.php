<?php
/**
 * Restaurant dashboard side of the platform: announcements from RestroGrow and support tickets.
 * Everything is scoped to the logged-in restaurant (session restaurant_id).
 *   GET  ?action=notices                 active announcements + unread support replies
 *   POST ?action=dismiss   {id}
 *   GET  ?action=tickets                 this restaurant's tickets
 *   GET  ?action=ticket&id=              one ticket with its messages (marks replies read)
 *   POST ?action=createTicket {subject, category, body}
 *   POST ?action=reply     {id, body}
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
header('Content-Type: application/json');
header('Cache-Control: no-store');

$rid = $_SESSION['restaurant_id'] ?? null;
$loggedIn = $rid && (isset($_SESSION['user_id']) || isset($_SESSION['staff_id']) || isset($_SESSION['branch_admin_id']));
if (!$loggedIn) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']); exit; }

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/platform_engine.php';
$conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
if (!$conn instanceof PDO) { http_response_code(500); echo json_encode(['success' => false, 'message' => 'Database unavailable']); exit; }

$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$who = $_SESSION['staff_name'] ?? $_SESSION['member_name'] ?? $_SESSION['username'] ?? $_SESSION['restaurant_name'] ?? 'Restaurant';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

try {
    pfEnsure($conn);
    switch ($action) {
    case 'notices':
        $n = $conn->prepare("SELECT COUNT(*) FROM support_tickets WHERE restaurant_id = ? AND unread_restaurant = 1");
        $n->execute([$rid]);
        echo json_encode(['success' => true, 'announcements' => pfAnnouncementsFor($conn, $rid), 'support_unread' => (int)$n->fetchColumn()]);
        break;

    case 'dismiss':
        if (!$post) throw new Exception('POST required');
        $conn->prepare("INSERT IGNORE INTO sa_announcement_dismiss (announcement_id, restaurant_id) SELECT id, ? FROM sa_announcements WHERE id = ? AND dismissible = 1")
            ->execute([$rid, (int)($in['id'] ?? 0)]);
        echo json_encode(['success' => true]);
        break;

    case 'tickets':
        $st = $conn->prepare("SELECT t.id, t.subject, t.category, t.status, t.unread_restaurant, t.created_at, t.updated_at,
                (SELECT body FROM support_messages m WHERE m.ticket_id = t.id ORDER BY m.id DESC LIMIT 1) last_message
            FROM support_tickets t WHERE t.restaurant_id = ? ORDER BY t.updated_at DESC LIMIT 50");
        $st->execute([$rid]);
        echo json_encode(['success' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'ticket':
        $st = $conn->prepare("SELECT id, subject, category, status, created_at FROM support_tickets WHERE id = ? AND restaurant_id = ?");
        $st->execute([(int)($_GET['id'] ?? 0), $rid]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new Exception('Ticket not found');
        $conn->prepare("UPDATE support_tickets SET unread_restaurant = 0 WHERE id = ?")->execute([$t['id']]);
        $m = $conn->prepare("SELECT sender, sender_name, body, created_at FROM support_messages WHERE ticket_id = ? ORDER BY id");
        $m->execute([$t['id']]);
        echo json_encode(['success' => true, 'ticket' => $t, 'messages' => $m->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'createTicket':
        if (!$post) throw new Exception('POST required');
        $subject = trim($in['subject'] ?? '');
        $body = trim($in['body'] ?? '');
        if ($subject === '' || $body === '') throw new Exception('Please write a subject and what happened');
        $cat = in_array($in['category'] ?? '', ['orders', 'payments', 'menu', 'website', 'printing', 'whatsapp', 'billing', 'other'], true) ? $in['category'] : 'other';
        // a few tickets an hour is plenty; stops accidental floods
        $c = $conn->prepare("SELECT COUNT(*) FROM support_tickets WHERE restaurant_id = ? AND created_at >= NOW() - INTERVAL 1 HOUR");
        $c->execute([$rid]);
        if ((int)$c->fetchColumn() >= 5) throw new Exception('You have opened several requests in the last hour. Please add to an open one instead.');
        $conn->prepare("INSERT INTO support_tickets (restaurant_id, subject, category, created_by_name) VALUES (?, ?, ?, ?)")
            ->execute([$rid, mb_substr($subject, 0, 160), $cat, mb_substr((string)$who, 0, 100)]);
        $id = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO support_messages (ticket_id, sender, sender_name, body) VALUES (?, 'restaurant', ?, ?)")
            ->execute([$id, mb_substr((string)$who, 0, 100), mb_substr($body, 0, 5000)]);
        echo json_encode(['success' => true, 'id' => $id, 'message' => 'Sent. We will reply here soon.']);
        break;

    case 'reply':
        if (!$post) throw new Exception('POST required');
        $body = trim($in['body'] ?? '');
        if ($body === '') throw new Exception('Write a message first');
        $st = $conn->prepare("SELECT id FROM support_tickets WHERE id = ? AND restaurant_id = ?");
        $st->execute([(int)($in['id'] ?? 0), $rid]);
        $id = (int)$st->fetchColumn();
        if (!$id) throw new Exception('Ticket not found');
        $conn->prepare("INSERT INTO support_messages (ticket_id, sender, sender_name, body) VALUES (?, 'restaurant', ?, ?)")
            ->execute([$id, mb_substr((string)$who, 0, 100), mb_substr($body, 0, 5000)]);
        $conn->prepare("UPDATE support_tickets SET status = 'open', unread_admin = 1, updated_at = NOW() WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
