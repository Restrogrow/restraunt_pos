<?php
/**
 * Old payment-link address (payment_link_redirect.php?id=N), kept so links already sent still work.
 * Payment links now live at website/pay_link.php?t=<token>: they open a fresh PhonePe payment every
 * time and are confirmed only by PhonePe's status API (config/payment_settle.php).
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/payment_settle.php';

$conn = getConnection();
ppLinksEnsure($conn);
$id = (int)($_GET['id'] ?? 0);
$token = '';
if ($id > 0) {
    $st = $conn->prepare('SELECT token FROM payment_links WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $token = (string)$st->fetchColumn();
}
if ($token === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'This payment link is not valid. Please ask RestroGrow for a new one.';
    exit();
}
header('Location: ' . ppLinkUrl($token), true, 302);
