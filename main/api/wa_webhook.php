<?php
/**
 * RepeatGrow → RestroGrow: incoming WhatsApp messages (webhook event `message.received`).
 *
 *   POST /main/api/wa_webhook.php?rid=<restaurant id>     a restaurant's own WhatsApp number
 *   POST /main/api/wa_webhook.php?rid=platform            the shared RestroGrow ordering number
 *
 * Checks the X-Wacrm-Signature (t=<unix>,v1=HMAC-SHA256 of "<t>.<raw body>" with the endpoint's
 * secret, 5-minute window), ignores repeats (delivery id), looks up the sender's phone (RepeatGrow
 * sends contact_id), then:
 *   - the restaurant owner's own phone → the AI manager (config/owner_manager.php), if switched on
 *   - everyone else → WhatsApp ordering (config/wa_order_engine.php)
 * Replies go back through RepeatGrow. Always answers 200 quickly once the signature is good, so
 * RepeatGrow doesn't retry a message that was handled.
 *
 * Secrets: a restaurant's endpoint secret is saved (encrypted) when it switches WhatsApp ordering on
 * (api/wa_order_admin.php); the shared number's is REPEATGROW_WEBHOOK_SECRET in .env.
 */
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/wa_order_engine.php';

function whOut(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => $code < 300, 'message' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') whOut(405, 'POST only');
$raw = (string)file_get_contents('php://input');
$rid = (string)($_GET['rid'] ?? '');
if ($rid !== 'platform' && !preg_match('/^[A-Za-z0-9]{3,20}$/', $rid)) whOut(400, 'Unknown restaurant');

try {
    $conn = getConnection();
    woEnsure($conn);
    ensureWhatsappSchema($conn);

    // ── who sent it, and is it really from RepeatGrow ──
    $statusHook = ($_GET['hook'] ?? '') === 'status';   // delivery / read updates for campaigns (waEnsureStatusHook)
    if ($statusHook && $rid === 'platform') {
        $ph = json_decode((string)$conn->query("SELECT v FROM sa_settings WHERE k = 'wa_platform_status_hook'")->fetchColumn(), true) ?: [];
        $secret = !empty($ph['secret']) ? waKeyOpen((string)$ph['secret']) : '';
    } elseif ($statusHook) {
        $hs = $conn->prepare('SELECT status_hook_secret FROM wa_settings WHERE restaurant_id = ?');
        $hs->execute([$rid]);
        $sealed = (string)$hs->fetchColumn();
        $secret = $sealed !== '' ? waKeyOpen($sealed) : '';
    } else {
        $secret = $rid === 'platform' ? trim((string)env('REPEATGROW_WEBHOOK_SECRET', '')) : woSettings($conn, $rid)['webhook_secret'];
    }
    $sig = (string)($_SERVER['HTTP_X_WACRM_SIGNATURE'] ?? '');
    if ($secret === '' || !preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $sig, $m)) whOut(401, 'Missing signature');
    if (abs(time() - (int)$m[1]) > 300) whOut(401, 'Old signature');
    if (!hash_equals(hash_hmac('sha256', $m[1] . '.' . $raw, $secret), $m[2])) whOut(401, 'Bad signature');

    $ev = json_decode($raw, true);
    // a message we sent was delivered / read / failed: update the campaign numbers
    if (is_array($ev) && ($ev['event'] ?? '') === 'message.status_updated') {
        $d = (array)($ev['data'] ?? []);
        $mid = mb_substr((string)($d['whatsapp_message_id'] ?? $d['message_id'] ?? ''), 0, 120);
        $stt = strtolower((string)($d['status'] ?? ''));
        if ($mid !== '') {
            $where = 'message_id = ?' . ($rid !== 'platform' ? ' AND restaurant_id = ?' : '');
            $args = $rid !== 'platform' ? [$mid, $rid] : [$mid];
            if ($stt === 'delivered') $conn->prepare("UPDATE wa_outbox SET delivered_at = COALESCE(delivered_at, NOW()) WHERE $where")->execute($args);
            elseif ($stt === 'read') $conn->prepare("UPDATE wa_outbox SET read_at = COALESCE(read_at, NOW()), delivered_at = COALESCE(delivered_at, NOW()) WHERE $where")->execute($args);
            elseif ($stt === 'failed') $conn->prepare("UPDATE wa_outbox SET status = 'failed', error = COALESCE(error, 'WhatsApp could not deliver it') WHERE $where AND delivered_at IS NULL")->execute($args);
        }
        whOut(200, 'Status noted');
    }
    if (!is_array($ev) || ($ev['event'] ?? '') !== 'message.received') whOut(200, 'Ignored');
    $deliveryId = mb_substr((string)($ev['id'] ?? ($_SERVER['HTTP_X_WACRM_WEBHOOK_ID'] ?? '')), 0, 80);
    if ($deliveryId !== '') {
        try { $conn->prepare('INSERT INTO wa_webhook_seen (id) VALUES (?)')->execute([$deliveryId]); }
        catch (PDOException $e) { whOut(200, 'Already handled'); }
        if (random_int(1, 200) === 1) $conn->exec('DELETE FROM wa_webhook_seen WHERE created_at < NOW() - INTERVAL 7 DAY');
    }
    $data = (array)($ev['data'] ?? []);
    $text = trim((string)($data['text'] ?? ''));
    if ($text === '') whOut(200, 'No text');   // photos / voice notes: not understood yet

    // ── the sender's phone (RepeatGrow sends the contact id; looked up once, then remembered) ──
    $waRid = $rid === 'platform' ? '' : $rid;
    $settings = $waRid !== '' ? waSettings($conn, $waRid) : ['mode' => 'platform', 'api_key' => '', 'is_demo' => false];
    $phone = whContactPhone($conn, (string)($data['contact_id'] ?? ''), $settings, $name);
    if ($phone === '') whOut(200, 'Unknown contact');

    // ── the shared number: which restaurant (from the QR's "ORDER RES… T4", else the last one used) ──
    $tableId = null;
    if ($rid === 'platform') {
        if (preg_match('/\bORDER\s+(RES[A-Z0-9]{3,12})(?:\s+(?:TABLE\s*)?([A-Z]?\d{1,3}))?/i', $text, $om)) {
            $waRid = strtoupper($om[1]);
            $tableId = isset($om[2]) ? woTableId($conn, $waRid, $om[2]) : null;
            $conn->prepare('INSERT INTO wa_order_routes (phone, restaurant_id, table_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE restaurant_id = VALUES(restaurant_id), table_id = VALUES(table_id)')
                ->execute([$phone, $waRid, $tableId]);
            $text = trim(preg_replace('/\bORDER\s+RES[A-Z0-9]{3,12}(?:\s+(?:TABLE\s*)?[A-Z]?\d{1,3})?/i', '', $text)) ?: 'hi';
        } else {
            $r = $conn->prepare('SELECT restaurant_id, table_id FROM wa_order_routes WHERE phone = ?');
            $r->execute([$phone]);
            $route = $r->fetch(PDO::FETCH_ASSOC);
            if (!$route) {
                whPlatformSend($phone, "Hi! To order, scan the WhatsApp QR code at the restaurant (or tap “Order on WhatsApp” on its website).");
                whOut(200, 'No restaurant');
            }
            $waRid = (string)$route['restaurant_id'];
        }
        $chk = $conn->prepare('SELECT 1 FROM users WHERE restaurant_id = ?');
        $chk->execute([$waRid]);
        if (!$chk->fetchColumn()) whOut(200, 'Unknown restaurant');
    }

    // ── the owner → AI manager; everyone else → ordering ──
    $replies = null;
    $own = $conn->prepare('SELECT phone FROM users WHERE restaurant_id = ? LIMIT 1');
    $own->execute([$waRid]);
    // the owner = the restaurant's phone, or the number set for the report in Growth › AI manager
    $ownerPhones = [preg_replace('/\D/', '', (string)$own->fetchColumn())];
    if (is_file(__DIR__ . '/../config/owner_manager.php')) {
        require_once __DIR__ . '/../config/owner_manager.php';
        try { $ownerPhones[] = (string)ownerMgrSettings($conn, $waRid)['phone_override']; } catch (Throwable $e) { /* no AI manager tables yet */ }
    }
    // stored numbers have no country code (and maybe a leading 0); compare their last digits, at most 10
    $inDigits = preg_replace('/\D/', '', $phone);
    $isOwner = false;
    foreach ($ownerPhones as $p) {
        $p = ltrim($p, '0');
        $n = min(10, strlen($p));
        if ($n >= 7 && substr($inDigits, -$n) === substr($p, -$n)) $isOwner = true;
    }
    if ($isOwner && function_exists('ownerMgrHandleMessage')) {
        $replies = ownerMgrHandleMessage($conn, $waRid, $phone, $text);
    }
    if ($replies === null) $replies = woHandle($conn, $waRid, $phone, $text, ['name' => $name, 'table' => $tableId]);

    foreach ($replies as $msg) {
        if ($rid === 'platform') whPlatformSend($phone, $msg);
        else woSend($conn, $waRid, $phone, $msg, false);
    }
    whOut(200, 'OK');
} catch (Throwable $e) {
    error_log('wa_webhook: ' . $e->getMessage());
    whOut(200, 'Error logged');   // a retry would only repeat the same failure
}

/** RepeatGrow contact id → phone (and name), via GET /api/v1/contacts/{id}; cached in wa_contact_phones. */
function whContactPhone(PDO $conn, string $contactId, array $s, ?string &$name): string
{
    $name = null;
    if ($contactId === '') return '';
    $st = $conn->prepare('SELECT phone, name FROM wa_contact_phones WHERE contact_id = ?');
    $st->execute([$contactId]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $name = $r['name']; return (string)$r['phone']; }
    $c = waConnection($s);
    if (!$c) return '';
    $url = preg_replace('#/messages/?$#', '/contacts/' . rawurlencode($contactId), $c['url']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c['key']]]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode((string)$res, true);
    $phone = preg_replace('/\D/', '', (string)($d['data']['phone'] ?? ''));
    if ($code !== 200 || $phone === '') { error_log('wa_webhook: contact lookup HTTP ' . $code); return ''; }
    if (strlen($phone) > 10 && substr($phone, 0, 2) === '91') $phone = substr($phone, -10);   // Indian numbers: stored as 10 digits like orders
    $name = trim((string)($d['data']['name'] ?? '')) ?: null;
    $conn->prepare('INSERT INTO wa_contact_phones (contact_id, phone, name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE phone = VALUES(phone), name = VALUES(name)')
        ->execute([$contactId, $phone, $name]);
    return $phone;
}

/** A message from the shared RestroGrow number (platform key in .env). */
function whPlatformSend(string $phone, string $text): void
{
    [$ok, , $err] = waSend(['mode' => 'platform', 'api_key' => '', 'is_demo' => false], $phone, null, '', [], $text);
    if (!$ok) error_log('wa_webhook platform send: ' . $err);
}
