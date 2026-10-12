<?php
/**
 * Connect RepeatGrow (Growth › WhatsApp).
 *
 *   ?action=start            — (owner logged in) sends them to RepeatGrow to approve
 *   ?code=…&state=…          — RepeatGrow sends them back here: we swap the code for an
 *                              API key, save it, and return to the dashboard
 *   ?error=…&state=…         — they pressed Cancel on RepeatGrow
 *
 * Once connected, every message type's template is created straight away, so the owner
 * lands on the WhatsApp page with them already in review ("Create templates" stays for later edits).
 *
 * See config/repeatgrow_connect.php and docs/REPEATGROW_CONNECT.md.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/whatsapp_engine.php';

function rgcBack(string $result, string $msg = ''): void {
    header('Location: ../views/dashboard.php?wa=' . rawurlencode($result) . ($msg !== '' ? '&wa_msg=' . rawurlencode(mb_substr($msg, 0, 160)) : ''), true, 302);
    exit;
}

try {
    $conn = getConnection();
    ensureWhatsappSchema($conn);
    ensureRepeatgrowConnectSchema($conn);

    if (($_GET['action'] ?? '') === 'start') {
        if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) { header('Location: ../admin/login.php', true, 302); exit; }
        $rid = $_SESSION['restaurant_id'];
        session_write_close();
        if (!rgcAvailable()) rgcBack('unavailable', 'Connecting is not switched on yet. Paste an API key in Settings instead.');
        $n = $conn->prepare('SELECT restaurant_name FROM users WHERE restaurant_id = ? LIMIT 1');
        $n->execute([$rid]);
        header('Location: ' . rgcAuthorizeUrl($conn, $rid, (string)$n->fetchColumn()), true, 302);
        exit;
    }

    $state = (string)($_GET['state'] ?? '');
    if ($state === '' || !preg_match('/^[a-f0-9]{48}$/', $state)) rgcBack('failed', 'The connection link was not valid. Please try again.');
    $rid = rgcConsumeState($conn, $state);
    if (!$rid) rgcBack('failed', 'That connection link was already used or has expired. Please try again.');
    // the owner who started it should still be the one logged in here
    if (isLoggedIn() && !empty($_SESSION['restaurant_id']) && $_SESSION['restaurant_id'] !== $rid) rgcBack('failed', 'You are logged in to a different restaurant.');
    session_write_close();

    if (!empty($_GET['error'])) rgcBack('cancelled');
    $code = (string)($_GET['code'] ?? '');
    if ($code === '' || strlen($code) > 200) rgcBack('failed', 'RepeatGrow did not send a code back. Please try again.');

    [$ok, $data] = rgcExchangeCode($code);
    if (!$ok) rgcBack('failed', $data);

    $conn->prepare("INSERT INTO wa_settings (restaurant_id, mode, api_key, account_name, rg_account_id, rg_phone, connected_via, connected_at, lost_at, templates_retry)
        VALUES (?, 'own', ?, ?, ?, ?, 'connect', NOW(), NULL, 0)
        ON DUPLICATE KEY UPDATE mode = 'own', api_key = VALUES(api_key), account_name = VALUES(account_name), rg_account_id = VALUES(rg_account_id),
            rg_phone = VALUES(rg_phone), connected_via = 'connect', connected_at = NOW(), lost_at = NULL, templates_retry = 0")
         ->execute([$rid, waKeySeal((string)$data['api_key']), mb_substr((string)($data['account']['name'] ?? 'RepeatGrow'), 0, 120),
                    mb_substr((string)($data['account']['id'] ?? ''), 0, 64), mb_substr((string)($data['phone_number'] ?? ''), 0, 30)]);
    // send every template for review now; a rate limit or error is picked up by the next check
    try { waTemplatesCreate($conn, $rid); } catch (Throwable $e) { error_log('repeatgrow_connect.php templates: ' . $e->getMessage()); }
    rgcBack('connected');
} catch (Throwable $e) {
    error_log('repeatgrow_connect.php: ' . $e->getMessage());
    rgcBack('failed', 'Something went wrong. Please try again.');
}
