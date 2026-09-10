<?php
/**
 * Internal-only, fire-and-forget target for order confirmation emails.
 *
 * fireOrderConfirmedActions() used to call sendOrderConfirmationEmails()
 * directly, in-request. That function opens a raw SMTP connection by hand
 * (EHLO/STARTTLS/AUTH LOGIN/full DATA transaction, no connection reuse) to
 * Gmail's SMTP server — twice, once for the customer and once for the
 * restaurant — which realistically costs 1-4+ seconds combined. The
 * "respond to the customer, keep working after" trick in
 * process_website_order.php only works if the hosting stack actually lets
 * the connection close early (fastcgi_finish_request, or the manual
 * Content-Length approach on plain mod_php); behind a CDN/reverse proxy
 * that isn't guaranteed, so a slow SMTP handshake could still show up as a
 * slow "Place Order" click.
 *
 * Dispatched via a short-timeout cURL call to itself (see
 * order_confirmation.php) that intentionally doesn't wait for a response,
 * so email latency is fully decoupled from the customer-facing request
 * regardless of what the hosting/proxy layer does with response flushing.
 *
 * Restricted to loopback requests only — this isn't meant to be a public
 * endpoint, just an internal dispatch target.
 */

// The caller deliberately disconnects almost immediately (that's the whole
// point — it isn't waiting for this to finish). Without this, PHP would
// abort execution the moment that happens, killing the email send before
// it gets anywhere.
ignore_user_abort(true);

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit();
}

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/order_confirmation.php';
require_once __DIR__ . '/../config/email_config.php';

$orderId = (int)($_POST['order_id'] ?? 0);
if (!$orderId) {
    http_response_code(400);
    exit();
}

try {
    $conn = getConnection();
    sendOrderConfirmationEmailsForOrder($conn, $orderId);
} catch (Exception $e) {
    error_log('send_order_confirmation_emails: ' . $e->getMessage());
}

http_response_code(200);
echo 'ok';
