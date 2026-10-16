<?php
/**
 * WhatsApp sender heartbeat (Growth › WhatsApp). The dashboard calls this once a
 * minute while it is open: it queues new gift codes, starts due campaigns,
 * sends a small batch, and pushes balance changes to phone wallet cards. cron/whatsapp_dispatch.php does the same for every
 * restaurant when no dashboard is open.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/whatsapp_engine.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) { http_response_code(401); echo json_encode(['success' => false]); exit; }
$rid = $_SESSION['restaurant_id'];
session_write_close();

try {
    $conn = getConnection();
    ensureWhatsappSchema($conn);
    // Phone wallet cards: push balance changes (Loyalty Studio › Phone card)
    $cards = 0;
    try { require_once __DIR__ . '/../config/wallet_card.php'; require_once __DIR__ . '/../config/vip_membership.php'; $cards = walletCardsSync($conn, $rid); }
    catch (Throwable $e) { error_log('wa_dispatch cards: ' . $e->getMessage()); }
    // emails: the hourly date-based ones (trial, renewal, receipts…), then the outbox, after this reply
    try { require_once __DIR__ . '/../config/mail_engine.php'; mailSweep($conn); mailKick($conn); }
    catch (Throwable $e) { error_log('wa_dispatch mail: ' . $e->getMessage()); }
    $s = waSettings($conn, $rid);
    if (!$s['enabled']) { echo json_encode(['success' => true, 'enabled' => false, 'cards' => $cards]); exit; }
    // birthday / win-back gifts first, so they go out in this same run
    require_once __DIR__ . '/../config/loyalty_autopilot.php';
    try { loyaltyRunSweep($conn, $rid); } catch (Throwable $e) { error_log('wa_dispatch sweep: ' . $e->getMessage()); }
    echo json_encode(['success' => true, 'enabled' => true] + waDispatch($conn, $rid, 15));
} catch (Throwable $e) {
    error_log('wa_dispatch.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false]);
}
