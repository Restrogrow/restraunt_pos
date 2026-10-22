<?php
/**
 * Missed call → WhatsApp (Growth › WhatsApp › Missed calls).
 *
 * A calling service (Exotel, MyOperator, Knowlarity, Servetel, or any service that can call a link)
 * calls this when a call to the restaurant goes unanswered:
 *   GET or POST  /main/api/missed_call.php?rid=RES001&key=<secret from the page>&caller=98XXXXXXXX
 *
 * The caller's number is read from whichever field the service uses (caller, from, CallFrom,
 * caller_number, caller_id, msisdn, mobile, phone…), from the query, a form post or JSON.
 * A call the service reports as answered is ignored. waMissedCall() does the rest: logs the call
 * and queues the WhatsApp message with the order link (once per 12 hours per number).
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/whatsapp_engine.php';

function mcOut(int $code, string $msg, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(['ok' => $code < 300, 'message' => $msg] + $extra);
    exit;
}

$rid = (string)($_GET['rid'] ?? '');
$key = (string)($_GET['key'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{3,20}$/', $rid) || !preg_match('/^[a-f0-9]{32}$/', $key)) mcOut(400, 'Unknown restaurant');

// every field the service may send, whatever the format
$in = $_GET + $_POST;
$raw = (string)file_get_contents('php://input');
if ($raw !== '' && ($j = json_decode($raw, true)) && is_array($j)) $in += $j;
$pick = function (array $names) use ($in): string {
    $lower = array_change_key_case($in, CASE_LOWER);
    foreach ($names as $n) { $v = $lower[strtolower($n)] ?? null; if (is_scalar($v) && trim((string)$v) !== '') return trim((string)$v); }
    return '';
};
$caller = $pick(['caller', 'from', 'callfrom', 'caller_number', 'callernumber', 'caller_id', 'callerid', 'clid', 'msisdn', 'mobile', 'phone', 'customer_number']);
// some services call the same link for every call: skip the ones that were picked up
$status = strtolower($pick(['status', 'call_status', 'callstatus', 'dialcallstatus', 'dial_status', 'callType']));
if (in_array($status, ['completed', 'answered', 'connected', 'call-attempt-answered'], true)) mcOut(200, 'Answered call, nothing to send');

try {
    $conn = getConnection();
    ensureWhatsappSchema($conn);
    $st = $conn->prepare('SELECT mc_key FROM wa_settings WHERE restaurant_id = ?');
    $st->execute([$rid]);
    $want = (string)$st->fetchColumn();
    if ($want === '' || !hash_equals($want, $key)) mcOut(403, 'This link is not valid any more. Copy the new one from Growth › WhatsApp › Missed calls.');

    [$result, $note] = waMissedCall($conn, $rid, $caller, 'call');
    // send it right away (the heartbeat / cron would also pick it up within minutes)
    if ($result === 'queued') { try { waDispatch($conn, $rid, 5); } catch (Throwable $e) { error_log('missed call dispatch: ' . $e->getMessage()); } }
    mcOut(200, $note, ['result' => $result]);
} catch (Throwable $e) {
    error_log('missed_call.php: ' . $e->getMessage());
    mcOut(500, 'Something went wrong');
}
