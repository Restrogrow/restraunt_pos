<?php
/**
 * Settings › WhatsApp ordering (dashboard + Partner app). Data for config/wa_order_engine.php.
 *
 * GET                       settings, links, WhatsApp/AI status, today's numbers, recent chats
 * GET  ?chat=<phone>        one chat's messages (newest 80)
 * POST {action:'save', enabled, order_types[], greeting, suggest_extras}
 *                           saves; switching on registers the webhook in RepeatGrow when connected
 * POST {action:'try', text, reset?, real?}
 *                           the test chat: runs a message as a customer would (no WhatsApp needed).
 *                           real = 1 places a real order at the end (tagged [WhatsApp order]).
 * POST {action:'reply', phone, text}
 *                           the restaurant answers a customer in a chat (e.g. after "talk to a person")
 *
 * Owners and staff who manage settings or orders. Always the session's restaurant.
 */
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin();
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id']) || !(hasPermission(PERMISSION_MANAGE_SETTINGS) || hasPermission(PERMISSION_MANAGE_ORDERS))) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}
$rid = (string)$_SESSION['restaurant_id'];
$tester = 'test' . substr(preg_replace('/\D/', '', (string)($_SESSION['user_id'] ?? '0')), 0, 8);   // the test chat's "phone"
$canSettings = hasPermission(PERMISSION_MANAGE_SETTINGS);
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/wa_order_engine.php';

function woaOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $conn = getConnection();
    woEnsure($conn);
    ensureWhatsappSchema($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['chat'])) {
            $phone = preg_replace('/[^0-9a-z]/', '', (string)$_GET['chat']);
            $st = $conn->prepare('SELECT direction, body, meta, created_at FROM wa_order_log WHERE restaurant_id = ? AND phone = ? ORDER BY id DESC LIMIT 80');
            $st->execute([$rid, $phone]);
            woaOut(['success' => true, 'messages' => array_reverse($st->fetchAll(PDO::FETCH_ASSOC))]);
        }
        $cfg = woSettings($conn, $rid);
        $wa = waSettings($conn, $rid);
        $connected = $wa['mode'] === 'own' ? $wa['api_key'] !== '' : waConnection($wa) !== null;
        $tables = $conn->prepare('SELECT table_number FROM tables WHERE restaurant_id = ? ORDER BY sort_order, table_number LIMIT 60');
        $tables->execute([$rid]);
        $links = ['order' => woOrderLink($conn, $rid)];
        foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $t) $links['tables'][] = ['table' => $t, 'link' => woOrderLink($conn, $rid, (string)$t)];
        $today = $conn->prepare("SELECT COUNT(*) n, COALESCE(SUM(total), 0) amount FROM orders WHERE restaurant_id = ? AND notes LIKE '[WhatsApp order]%' AND deleted_at IS NULL
            AND order_status NOT IN ('Cancelled','Rejected') AND created_at >= CURDATE()");
        $today->execute([$rid]);
        $week = $conn->prepare("SELECT COUNT(*) n, COALESCE(SUM(total), 0) amount FROM orders WHERE restaurant_id = ? AND notes LIKE '[WhatsApp order]%' AND deleted_at IS NULL
            AND order_status NOT IN ('Cancelled','Rejected') AND created_at >= CURDATE() - INTERVAL 6 DAY");
        $week->execute([$rid]);
        $chats = $conn->prepare("SELECT s.phone, s.name, s.state, s.order_number, s.handover_until, s.updated_at,
                (SELECT body FROM wa_order_log l WHERE l.restaurant_id = s.restaurant_id AND l.phone = s.phone ORDER BY l.id DESC LIMIT 1) last
            FROM wa_order_sessions s WHERE s.restaurant_id = ? AND s.phone NOT LIKE 'test%' ORDER BY s.updated_at DESC LIMIT 30");
        $chats->execute([$rid]);
        woaOut(['success' => true,
            'settings' => ['enabled' => $cfg['enabled'], 'order_types' => $cfg['order_types'], 'allowed_types' => $cfg['allowed_types'], 'greeting' => $cfg['greeting'],
                'suggest_extras' => $cfg['suggest_extras'], 'webhook_ready' => $cfg['webhook_id'] !== '' && $cfg['webhook_secret'] !== '',
                'webhook_note' => (function () use ($conn, $rid) {
                    try { $n = $conn->prepare('SELECT webhook_note FROM wa_order_settings WHERE restaurant_id = ?'); $n->execute([$rid]); return (string)$n->fetchColumn(); }
                    catch (Throwable $e) { return ''; }   // column comes with the first failed link
                })()],
            'whatsapp' => ['connected' => $connected, 'mode' => $wa['mode'], 'via' => $wa['connected_via'], 'number' => $wa['rg_phone'], 'local' => waDryRun()],
            'ai' => aiGeminiAvailable(),
            'restaurant' => (string)($cfg['restaurant']['restaurant_name'] ?? ''),
            'links' => $links,
            'menu_count' => count(woMenu($conn, $rid)),
            'today' => $today->fetch(PDO::FETCH_ASSOC), 'week' => $week->fetch(PDO::FETCH_ASSOC),
            'chats' => $chats->fetchAll(PDO::FETCH_ASSOC),
            'can_edit' => $canSettings,
        ]);
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');

    if ($action === 'save') {
        if (!$canSettings) woaOut(['success' => false, 'message' => 'Only the owner or a manager can change this.'], 403);
        woSaveSettings($conn, $rid, $in);
        $note = '';
        if (!empty($in['enabled'])) $note = woRegisterWebhook($conn, $rid);
        woaOut(['success' => true, 'message' => 'Saved' . ($note !== '' ? '. ' . $note : '')]);
    }

    // checklist step 2 › Link now: register the incoming-message webhook again and say what happened
    if ($action === 'link') {
        if (!$canSettings) woaOut(['success' => false, 'message' => 'Only the owner or a manager can change this.'], 403);
        $note = woRegisterWebhook($conn, $rid);
        $ready = woSettings($conn, $rid)['webhook_id'] !== '';
        woaOut(['success' => $ready, 'message' => $note !== '' ? $note : 'WhatsApp messages are linked.']);
    }

    if ($action === 'try') {
        if (!empty($in['reset'])) {
            $conn->prepare('DELETE FROM wa_order_sessions WHERE restaurant_id = ? AND phone = ?')->execute([$rid, $tester]);
            $conn->prepare('DELETE FROM wa_order_log WHERE restaurant_id = ? AND phone = ?')->execute([$rid, $tester]);
            woaOut(['success' => true, 'replies' => []]);
        }
        $text = trim((string)($in['text'] ?? ''));
        if ($text === '') woaOut(['success' => false, 'message' => 'Type a message']);
        $real = !empty($in['real']);
        // a real test order needs a phone the order checks accept: the restaurant's own number
        $phone = $tester;
        if ($real) {
            $p = $conn->prepare('SELECT phone FROM users WHERE restaurant_id = ?');
            $p->execute([$rid]);
            $phone = substr(preg_replace('/\D/', '', (string)$p->fetchColumn()), -10);
            if (strlen($phone) !== 10) woaOut(['success' => false, 'message' => 'Add the restaurant phone in Settings to place a real test order.']);
            // carry the test chat over to that number
            $conn->prepare('DELETE FROM wa_order_sessions WHERE restaurant_id = ? AND phone = ?')->execute([$rid, $phone]);
            $conn->prepare('UPDATE wa_order_sessions SET phone = ? WHERE restaurant_id = ? AND phone = ?')->execute([$phone, $rid, $tester]);
        }
        $t0 = microtime(true);
        $replies = woHandle($conn, $rid, $phone, $text, ['simulate' => !$real, 'force' => true, 'name' => 'Test customer']);
        if ($real) $conn->prepare('UPDATE wa_order_sessions SET phone = ? WHERE restaurant_id = ? AND phone = ? AND state <> "placed"')->execute([$tester, $rid, $phone]);
        woaOut(['success' => true, 'replies' => $replies, 'ms' => (int)((microtime(true) - $t0) * 1000), 'ai' => aiGeminiAvailable()]);
    }

    if ($action === 'reply') {
        $phone = preg_replace('/\D/', '', (string)($in['phone'] ?? ''));
        $text = trim(mb_substr((string)($in['text'] ?? ''), 0, 1000));
        if ($phone === '' || $text === '') woaOut(['success' => false, 'message' => 'Type a message']);
        $ok = woSend($conn, $rid, $phone, $text);
        woaOut(['success' => $ok, 'message' => $ok ? 'Sent' : 'Could not send. Check the WhatsApp connection.']);
    }

    woaOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('wa_order_admin: ' . $e->getMessage());
    woaOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}

/** Register this restaurant's webhook in RepeatGrow (message.received) once; returns a note for the owner. */
function woRegisterWebhook(PDO $conn, string $rid): string
{
    $cfg = woSettings($conn, $rid);
    if ($cfg['webhook_id'] !== '' && $cfg['webhook_secret'] !== '') return '';
    $wa = waSettings($conn, $rid);
    if ($wa['mode'] !== 'own' || $wa['api_key'] === '') return 'Connect your WhatsApp (RepeatGrow) to start taking orders.';
    if (waDryRun()) return 'On this test computer WhatsApp is not connected for real; use the test chat.';
    $c = waConnection($wa);
    $base = preg_replace('#/api/[^/]+$#', '/api', woApiBase() . '/x');
    $url = $base . '/wa_webhook.php?rid=' . rawurlencode($rid);
    // RepeatGrow only accepts https; behind the host's proxy PHP can think the request was http
    if (!preg_match('#^http://(localhost|127\.0\.0\.1)[:/]#', $url)) $url = preg_replace('#^http://#', 'https://', $url);
    $ch = curl_init(preg_replace('#/messages/?$#', '/webhooks', $c['url']));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $c['key']],
        CURLOPT_POSTFIELDS => json_encode(['url' => $url, 'events' => ['message.received'], 'description' => 'RestroGrow WhatsApp ordering'])]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode((string)$res, true) ?: [];
    $id = (string)($d['data']['id'] ?? '');
    $secret = (string)($d['data']['secret'] ?? '');
    if ($code === 403 || $code === 401) return woWebhookNote($conn, $rid, 'RepeatGrow did not allow RestroGrow to receive messages (' . rgcError($d, 'error ' . $code) . '). Reconnect RepeatGrow in Growth › WhatsApp, then press Link now.');
    if ($id === '' || $secret === '') return woWebhookNote($conn, $rid, 'Could not link WhatsApp messages yet (' . rgcError($d, $code ? 'error ' . $code : 'no answer from RepeatGrow') . ').');
    $conn->prepare('UPDATE wa_order_settings SET webhook_id = ?, webhook_secret = ? WHERE restaurant_id = ?')->execute([$id, waKeySeal($secret), $rid]);
    woWebhookNote($conn, $rid, '');
    return 'WhatsApp messages are linked.';
}

/** Why linking failed last time, kept so step 2 of the checklist can show it ('' clears it). Returns the note. */
function woWebhookNote(PDO $conn, string $rid, string $note): string
{
    try {
        $conn->prepare('UPDATE wa_order_settings SET webhook_note = ? WHERE restaurant_id = ?')->execute([$note !== '' ? mb_substr($note, 0, 300) : null, $rid]);
    } catch (Throwable $e) {
        try { $conn->exec('ALTER TABLE wa_order_settings ADD COLUMN webhook_note VARCHAR(300) NULL'); } catch (Throwable $e2) { /* added meanwhile */ }
        try { $conn->prepare('UPDATE wa_order_settings SET webhook_note = ? WHERE restaurant_id = ?')->execute([$note !== '' ? mb_substr($note, 0, 300) : null, $rid]); } catch (Throwable $e3) {}
    }
    if ($note !== '') error_log("wa ordering webhook ($rid): $note");
    return $note;
}
