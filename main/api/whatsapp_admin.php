<?php
/**
 * Growth › WhatsApp (admin): connection, automatic gift messages, campaigns, log.
 *
 * GET  ?view=all                      — everything the page shows
 * GET  ?view=audience&key=lapsed30    — how many opted-in customers a campaign would reach
 * POST JSON { action: 'settings' | 'check_key' | 'auto' | 'test' | 'campaign' | 'cancel' | 'retry' | 'dispatch'
 *              | 'templates_create' | 'templates_sync' | 'disconnect', ... }
 * Connect RepeatGrow itself starts at api/repeatgrow_connect.php?action=start.
 *
 * Scoped to the session's restaurant. The API key is never sent back (last 4 only).
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/whatsapp_engine.php';
require_once __DIR__ . '/../config/vip_membership.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}
$rid = $_SESSION['restaurant_id'];
session_write_close();

function waOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $conn = getConnection();
    ensureWhatsappSchema($conn);
    ensureLoyaltyAutopilotSchema($conn);
    ensureVipSchema($conn);
    $s = waSettings($conn, $rid);
    $r = waRestaurant($conn, $rid);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $view = $_GET['view'] ?? 'all';
        if ($view === 'audience') waOut(['success' => true, 'count' => waAudienceCount($conn, $rid, (string)($_GET['key'] ?? ''))]);

        $q = function ($sql, $args) use ($conn) { $st = $conn->prepare($sql); $st->execute($args); return $st; };
        try { waEnsureStatusHook($conn, $rid); } catch (Throwable $e) { error_log('wa status hook: ' . $e->getMessage()); }
        $stats = [];
        // delivered / read (RepeatGrow receipts) and link clicks, per automatic message (30 days)
        $funnel = [];
        foreach ($q("SELECT kind, SUM(delivered_at IS NOT NULL) delivered, SUM(read_at IS NOT NULL) seen, SUM(clicked_at IS NOT NULL) clicked FROM wa_outbox
                WHERE restaurant_id = ? AND status = 'sent' AND created_at >= NOW() - INTERVAL 30 DAY GROUP BY kind", [$rid])->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $funnel[$x['kind']] = ['delivered' => (int)$x['delivered'], 'read' => (int)$x['seen'], 'clicked' => (int)$x['clicked']];
        }
        foreach ($q("SELECT kind, status, COUNT(*) n FROM wa_outbox WHERE restaurant_id = ? AND created_at >= NOW() - INTERVAL 30 DAY GROUP BY kind, status", [$rid])->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $stats[$x['kind']][$x['status']] = (int)$x['n'];
        }
        // gift codes that came back as orders after a WhatsApp message (30 days)
        $used = [];
        foreach ($q("SELECT w.kind, COUNT(DISTINCT o.id) orders, COALESCE(SUM(o.total), 0) revenue FROM wa_outbox w
                JOIN loyalty_rewards_issued g ON g.id = w.ref_id AND w.ref_type = 'gift'
                JOIN orders o ON o.restaurant_id = g.restaurant_id AND o.coupon_code = g.coupon_code AND o.deleted_at IS NULL AND o.order_status NOT IN ('Cancelled', 'Rejected')
                WHERE w.restaurant_id = ? AND w.status = 'sent' AND w.created_at >= NOW() - INTERVAL 30 DAY GROUP BY w.kind", [$rid])->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $used[$x['kind']] = ['orders' => (int)$x['orders'], 'revenue' => (float)$x['revenue']];
        }
        $autos = loyaltyAutomations($conn, $rid);
        $auto = array_values(array_map(function ($a) use ($stats, $used, $autos, $conn, $rid) {
            [$tpl, $vars] = waTemplateFromMessage($a['message']);
            // the preview shows the gift this automation really gives
            $sampleReward = isset($autos[$a['kind']]) ? loyaltyAutoRewardLabel($conn, $rid, $autos[$a['kind']]) : null;
            // connected: does the wording still match the template that was sent for review?
            $a['needs_update'] = !empty($a['template_status']) && $tpl !== $a['template_text'] && $tpl !== $a['pending_text'];
            return $a + ['sample_reward' => $sampleReward, 'template_text' => $tpl, 'template_vars' => $vars, 'stats' => $stats[$a['kind']] ?? (object)[], 'orders' => $used[$a['kind']] ?? ['orders' => 0, 'revenue' => 0],
                'funnel' => $funnel[$a['kind']] ?? ['delivered' => 0, 'read' => 0, 'clicked' => 0]];
        }, waAutoConfig($conn, $rid)));
        $campaigns = $q("SELECT c.*, (SELECT COUNT(*) FROM wa_outbox o WHERE o.restaurant_id = c.restaurant_id AND o.ref_type = 'campaign' AND o.ref_id = c.id AND o.status = 'sent') sent,
                (SELECT COUNT(*) FROM wa_outbox o WHERE o.restaurant_id = c.restaurant_id AND o.ref_type = 'campaign' AND o.ref_id = c.id AND o.status = 'failed') failed,
                (SELECT COUNT(*) FROM wa_outbox o WHERE o.restaurant_id = c.restaurant_id AND o.ref_type = 'campaign' AND o.ref_id = c.id AND o.delivered_at IS NOT NULL) delivered,
                (SELECT COUNT(*) FROM wa_outbox o WHERE o.restaurant_id = c.restaurant_id AND o.ref_type = 'campaign' AND o.ref_id = c.id AND o.read_at IS NOT NULL) seen,
                (SELECT COUNT(*) FROM wa_outbox o WHERE o.restaurant_id = c.restaurant_id AND o.ref_type = 'campaign' AND o.ref_id = c.id AND o.clicked_at IS NOT NULL) clicked,
                (SELECT COUNT(*) FROM wa_outbox o WHERE o.restaurant_id = c.restaurant_id AND o.ref_type = 'campaign' AND o.ref_id = c.id AND o.status = 'queued') queued,
                (SELECT COUNT(DISTINCT ord.id) FROM loyalty_rewards_issued g JOIN orders ord ON ord.restaurant_id = g.restaurant_id AND ord.coupon_code = g.coupon_code AND ord.deleted_at IS NULL AND ord.order_status NOT IN ('Cancelled', 'Rejected')
                    WHERE g.restaurant_id = c.restaurant_id AND g.source = 'campaign' AND g.period_key = CONCAT('c', c.id)) orders,
                (SELECT COALESCE(SUM(ord.total), 0) FROM loyalty_rewards_issued g JOIN orders ord ON ord.restaurant_id = g.restaurant_id AND ord.coupon_code = g.coupon_code AND ord.deleted_at IS NULL AND ord.order_status NOT IN ('Cancelled', 'Rejected')
                    WHERE g.restaurant_id = c.restaurant_id AND g.source = 'campaign' AND g.period_key = CONCAT('c', c.id)) revenue
            FROM wa_campaigns c WHERE c.restaurant_id = ? ORDER BY c.id DESC LIMIT 30", [$rid])->fetchAll(PDO::FETCH_ASSOC);
        $log = $q("SELECT id, kind, phone, name, body, status, error, sent_at, created_at, delivered_at, read_at, clicked_at FROM wa_outbox WHERE restaurant_id = ? ORDER BY id DESC LIMIT 60", [$rid])->fetchAll(PDO::FETCH_ASSOC);
        $k = $q("SELECT COUNT(*) total, SUM(whatsapp_optin = 1) optin FROM customers WHERE restaurant_id = ?", [$rid])->fetch(PDO::FETCH_ASSOC);
        $sum = $q("SELECT SUM(status = 'sent') sent, SUM(status = 'failed') failed, SUM(status = 'queued') queued, SUM(delivered_at IS NOT NULL) delivered, SUM(read_at IS NOT NULL) seen, SUM(clicked_at IS NOT NULL) clicked
            FROM wa_outbox WHERE restaurant_id = ? AND created_at >= NOW() - INTERVAL 30 DAY", [$rid])->fetch(PDO::FETCH_ASSOC);
        $receipts = (function () use ($conn, $rid) { $x = $conn->prepare('SELECT status_hook_id FROM wa_settings WHERE restaurant_id = ?'); $x->execute([$rid]); $v = (string)$x->fetchColumn(); return $v !== '' && strpos($v, 'try:') !== 0; })();
        $rev = array_sum(array_column($used, 'revenue')) + array_sum(array_map(function ($c) { return (float)$c['revenue']; }, $campaigns));
        $pt = waPlatformTemplate();
        $items = $q('SELECT mi.id, mi.item_name_en name, mi.base_price price FROM menu_items mi WHERE mi.restaurant_id = ? ORDER BY mi.item_name_en', [$rid])->fetchAll(PDO::FETCH_ASSOC);
        waOut(['success' => true, 'currency' => $r['cur'], 'restaurant' => $r['name'], 'dry_run' => waDryRun(),
            'settings' => ['enabled' => $s['enabled'], 'mode' => $s['mode'], 'key_last4' => $s['api_key'] !== '' ? substr($s['api_key'], -4) : '', 'account_name' => $s['account_name'],
                           'quiet_start' => $s['quiet_start'], 'quiet_end' => $s['quiet_end'], 'weekly_cap' => $s['weekly_cap'], 'test_phone' => $s['test_phone'], 'last_dispatch' => $s['last_dispatch'],
                           'connected_via' => $s['connected_via'], 'rg_phone' => $s['rg_phone'], 'connected_at' => $s['connected_at'], 'templates_synced_at' => $s['templates_synced_at'],
                           // the owner disconnected RestroGrow in RepeatGrow (it answered 401)
                           'connection_lost' => !empty($s['lost_at']) && $s['connected_via'] === '', 'lost_message' => waLostMessage()],
            'connect' => ['available' => rgcAvailable(), 'url' => '../api/repeatgrow_connect.php?action=start', 'repeatgrow' => rgcBase()],
            'platform' => ['available' => env('REPEATGROW_API_URL') && env('REPEATGROW_API_KEY') && $pt['name'] !== '', 'template' => $pt['name'], 'text' => $pt['text']],
            'kpis' => ['customers' => (int)$k['total'], 'optin' => (int)$k['optin'], 'sent' => (int)$sum['sent'], 'failed' => (int)$sum['failed'], 'queued' => (int)$sum['queued'], 'revenue' => $rev,
                       'delivered' => (int)$sum['delivered'], 'read' => (int)$sum['seen'], 'clicked' => (int)$sum['clicked'], 'receipts' => $receipts],
            'auto' => $auto, 'vars' => waVars(),
            'audiences' => array_map(function ($key, $a) use ($conn, $rid) { return ['key' => $key, 'label' => $a[0], 'count' => waAudienceCount($conn, $rid, $key)]; }, array_keys(waAudiences()), waAudiences()),
            'campaigns' => array_map(function ($c) { return ['id' => (int)$c['id'], 'name' => $c['name'], 'audience' => $c['audience'], 'template_name' => $c['template_name'], 'language' => $c['language'],
                'message' => $c['message'], 'gift_kind' => $c['gift_kind'], 'gift_value' => (float)$c['gift_value'], 'gift_item_id' => $c['gift_item_id'] ? (int)$c['gift_item_id'] : null,
                'gift_min_order' => (float)$c['gift_min_order'], 'gift_valid_days' => (int)$c['gift_valid_days'], 'scheduled_at' => $c['scheduled_at'], 'status' => $c['status'],
                'template_status' => $c['template_status'] ?? null,
                'audience_count' => (int)$c['audience_count'], 'sent' => (int)$c['sent'], 'failed' => (int)$c['failed'], 'orders' => (int)$c['orders'], 'revenue' => (float)$c['revenue'],
                'delivered' => (int)$c['delivered'], 'read' => (int)$c['seen'], 'clicked' => (int)$c['clicked'], 'queued' => (int)$c['queued'], 'created_at' => $c['created_at']]; }, $campaigns),
            'log' => array_map(function ($l) { return ['id' => (int)$l['id'], 'kind' => $l['kind'], 'phone' => substr($l['phone'], 0, 2) . '******' . substr($l['phone'], -2), 'name' => $l['name'],
                'body' => $l['body'], 'status' => $l['status'], 'error' => $l['error'], 'at' => $l['sent_at'] ?: $l['created_at']]; }, $log),
            'items' => array_map(function ($i) { return ['id' => (int)$i['id'], 'name' => $i['name'], 'price' => (float)$i['price']]; }, $items),
        ]);
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');
    $upsert = function (array $cols) use ($conn, $rid) {
        $keys = array_keys($cols);
        $conn->prepare('INSERT INTO wa_settings (restaurant_id, ' . implode(', ', $keys) . ') VALUES (?' . str_repeat(', ?', count($keys)) . ') ON DUPLICATE KEY UPDATE '
            . implode(', ', array_map(function ($k) { return "$k = VALUES($k)"; }, $keys)))->execute(array_merge([$rid], array_values($cols)));
    };

    if ($action === 'settings') {
        $mode = ($in['mode'] ?? '') === 'own' ? 'own' : 'platform';
        $cols = ['enabled' => empty($in['enabled']) ? 0 : 1, 'mode' => $mode,
                 'quiet_start' => max(0, min(23, (int)($in['quiet_start'] ?? 21))), 'quiet_end' => max(0, min(23, (int)($in['quiet_end'] ?? 9))),
                 'weekly_cap' => max(0, min(7, (int)($in['weekly_cap'] ?? 2)))];
        $tp = preg_replace('/\D/', '', (string)($in['test_phone'] ?? ''));
        $cols['test_phone'] = $tp !== '' ? (orderAbuseNormalizePhoneSafe($tp)) : null;
        if (isset($in['api_key']) && trim((string)$in['api_key']) !== '') {
            [$ok, $name] = waCheckKey(trim((string)$in['api_key']));
            if (!$ok) waOut(['success' => false, 'message' => $name], 400);
            $cols['api_key'] = waKeySeal(trim((string)$in['api_key']));   // stored encrypted
            $cols['connected_via'] = 'key';
            $cols['lost_at'] = null;
            $cols['account_name'] = mb_substr($name, 0, 120);
        }
        if ($cols['enabled'] && $mode === 'own' && empty($cols['api_key']) && $s['api_key'] === '') waOut(['success' => false, 'message' => 'Paste your RepeatGrow API key to use your own number'], 400);
        if ($cols['enabled'] && $mode === 'platform' && !(env('REPEATGROW_API_URL') && env('REPEATGROW_API_KEY') && waPlatformTemplate()['name'] !== '')) {
            waOut(['success' => false, 'message' => 'RestroGrow’s number is not ready yet. Connect your own RepeatGrow account instead.'], 400);
        }
        $upsert($cols);
        waOut(['success' => true]);
    }

    if ($action === 'auto') {
        $kinds = waKinds();
        $kind = (string)($in['kind'] ?? '');
        if (!isset($kinds[$kind])) waOut(['success' => false, 'message' => 'Unknown message'], 400);
        $tpl = trim(mb_substr((string)($in['template_name'] ?? ''), 0, 100));
        if ($tpl !== '' && !preg_match('/^[a-z0-9_]+$/', $tpl)) waOut(['success' => false, 'message' => 'Template names use lowercase letters, numbers and _ only (as in RepeatGrow)'], 400);
        $lang = preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', (string)($in['language'] ?? '')) ? $in['language'] : 'en';
        if (array_key_exists('template_name', $in)) {
            $conn->prepare('INSERT INTO wa_auto (restaurant_id, kind, enabled, template_name, language, template_status) VALUES (?, ?, ?, ?, ?, NULL)
                ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), template_name = VALUES(template_name), language = VALUES(language),
                    template_status = IF(VALUES(template_name) <=> template_name, template_status, NULL)')
                 ->execute([$rid, $kind, empty($in['enabled']) ? 0 : 1, $tpl ?: null, $lang]);
        } else {
            $conn->prepare('INSERT INTO wa_auto (restaurant_id, kind, enabled) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)')
                 ->execute([$rid, $kind, empty($in['enabled']) ? 0 : 1]);
        }
        if (isset($in['message'])) {
            $msg = trim(mb_substr((string)$in['message'], 0, 700));
            if ($msg === '') waOut(['success' => false, 'message' => 'Write the message'], 400);
            if (strpos($msg, '{code}') === false) waOut(['success' => false, 'message' => 'Keep {code} in the message so the customer gets their gift code'], 400);
            waSaveMessage($conn, $rid, $kind, $msg);
            // connected: the new wording goes to Meta for review by itself
            if ($s['connected_via'] === 'connect') {
                $r2 = waTemplatesCreate($conn, $rid, [$kind]);
                if (!empty($r2['errors']['_']) || !empty($r2['errors']['_rate'])) waOut(['success' => true, 'warning' => 'Saved. ' . ($r2['errors']['_'] ?? $r2['errors']['_rate'])]);
                if (!empty($r2['errors'][$kind])) waOut(['success' => true, 'warning' => 'Saved, but RepeatGrow said: ' . $r2['errors'][$kind]]);
                if ($r2['created']) waOut(['success' => true, 'submitted' => true]);
            }
        }
        waOut(['success' => true]);
    }

    if ($action === 'test') {
        if ($s['test_phone'] === '') waOut(['success' => false, 'message' => 'Add your own phone number under Settings first'], 400);
        $vars = ['name' => 'Priya', 'reward' => $r['cur'] . '50 off', 'code' => 'GIFT7K2Q9M', 'valid_till' => date('j M', strtotime('+14 days')), 'restaurant' => $r['name'],
                 'min_order' => $r['cur'] . '199', 'link' => $r['link'], 'tier' => 'Gold', 'count' => '10'];
        if (!empty($in['campaign'])) {
            $c = $in['campaign'];
            $b = ['template_name' => trim((string)($c['template_name'] ?? '')), 'language' => $c['language'] ?? 'en', 'body' => waFill((string)($c['message'] ?? ''), $vars)];
            [, $order] = waTemplateFromMessage((string)($c['message'] ?? ''));
            $b['params'] = array_map(function ($k) use ($vars) { return (string)$vars[$k]; }, $order);
            if ($b['template_name'] === '') waOut(['success' => false, 'message' => 'Add the template name first'], 400);
        } else {
            $auto = waAutoConfig($conn, $rid);
            $kind = (string)($in['kind'] ?? '');
            if (!isset($auto[$kind])) waOut(['success' => false, 'message' => 'Unknown message'], 400);
            $b = waBuild($s, $auto[$kind], $auto[$kind]['message'], $vars);
            if (!$b) {
                $st0 = $auto[$kind]['template_status'] ?? '';
                waOut(['success' => false, 'message' => $st0 === 'pending' ? 'This message is still waiting for WhatsApp’s approval (usually a few minutes). Try again soon.'
                    : ($st0 === 'rejected' ? 'WhatsApp turned this wording down. Change the message and save to send it again.' : 'Add the approved template name first')], 400);
            }
        }
        [$ok, $mid, $err, $why] = waSend($s, $s['test_phone'], $b['template_name'], $b['language'], $b['params'], $b['body']);
        if (!$ok && $why === 'unauthorized' && $s['mode'] === 'own') { waConnectionLost($conn, $rid); $err = waLostMessage(); }
        if (!$ok && $why === 'rate_limited') $err = 'RepeatGrow is busy right now. Try the test again in a minute.';
        if (!$ok && $why === 'opted_out') $err = 'Your test number replied STOP on WhatsApp, so RepeatGrow won’t send offers to it. Use another number for tests.';
        waOut(['success' => $ok, 'message' => $ok ? (waDryRun() ? 'Test only: on this computer nothing is sent. It would say: ' . $b['body'] : 'Sent to your phone') : $err, 'body' => $b['body']]);
    }

    if ($action === 'campaign') {
        if ($s['mode'] !== 'own') waOut(['success' => false, 'message' => 'Campaigns need your own RepeatGrow connection (Settings)'], 400);
        $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 80));
        $aud = (string)($in['audience'] ?? '');
        $tpl = trim((string)($in['template_name'] ?? ''));
        $msg = trim(mb_substr((string)($in['message'] ?? ''), 0, 700));
        if ($name === '' || !isset(waAudiences()[$aud]) || $msg === '') waOut(['success' => false, 'message' => 'Give the campaign a name, an audience and a message'], 400);
        $managed = $s['connected_via'] === 'connect';
        if ($managed) $tpl = '';
        elseif (!preg_match('/^[a-z0-9_]+$/', $tpl)) waOut(['success' => false, 'message' => 'Add the approved template name (lowercase letters, numbers and _)'], 400);
        $gk = in_array($in['gift_kind'] ?? '', ['flat', 'percent', 'item'], true) ? $in['gift_kind'] : null;
        $gv = max(0, min($gk === 'percent' ? 100 : 100000, round((float)($in['gift_value'] ?? 0), 2)));
        $gi = (int)($in['gift_item_id'] ?? 0);
        if ($gi) { $own = $conn->prepare('SELECT 1 FROM menu_items WHERE id = ? AND restaurant_id = ?'); $own->execute([$gi, $rid]); if (!$own->fetchColumn()) $gi = 0; }
        if ($gk === 'item' && !$gi) waOut(['success' => false, 'message' => 'Pick the free dish'], 400);
        if ($gk && $gk !== 'item' && $gv <= 0) waOut(['success' => false, 'message' => 'Set the gift amount'], 400);
        if ($gk && strpos($msg, '{code}') === false) waOut(['success' => false, 'message' => 'The campaign has a gift: put {code} in the message'], 400);
        $when = !empty($in['send_now']) ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime((string)($in['scheduled_at'] ?? '')) ?: time());
        if (strtotime($when) < time() - 60) $when = date('Y-m-d H:i:s');
        if (waAudienceCount($conn, $rid, $aud) === 0) waOut(['success' => false, 'message' => 'Nobody in this audience has opted in to WhatsApp yet'], 400);
        $conn->prepare('INSERT INTO wa_campaigns (restaurant_id, name, audience, template_name, language, message, gift_kind, gift_value, gift_item_id, gift_min_order, gift_valid_days, scheduled_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
             ->execute([$rid, $name, $aud, $tpl, preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', (string)($in['language'] ?? '')) ? $in['language'] : 'en', $msg, $gk,
                        $gk === 'item' ? 0 : $gv, $gk === 'item' ? $gi : null, max(0, round((float)($in['gift_min_order'] ?? 0), 2)), max(1, min(90, (int)($in['gift_valid_days'] ?? 14))), $when]);
        $cid = (int)$conn->lastInsertId();
        $waiting = false;
        if ($managed) {
            [$ok, $res0] = waCampaignTemplate($conn, $rid, $cid, $msg, preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', (string)($in['language'] ?? '')) ? $in['language'] : 'en');
            if (!$ok) { $conn->prepare('DELETE FROM wa_campaigns WHERE id = ?')->execute([$cid]); waOut(['success' => false, 'message' => 'RepeatGrow could not create the template: ' . $res0], 400); }
            $waiting = $res0 !== 'approved';
        }
        try { waEnsureStatusHook($conn, $rid); } catch (Throwable $e) { error_log('wa status hook: ' . $e->getMessage()); }
        $res = !empty($in['send_now']) && !$waiting ? waDispatch($conn, $rid, 25) : null;
        waOut(['success' => true, 'dispatched' => $res, 'waiting_approval' => $waiting]);
    }

    if ($action === 'cancel') {
        $id = (int)($in['id'] ?? 0);
        $conn->prepare("UPDATE wa_campaigns SET status = 'cancelled' WHERE id = ? AND restaurant_id = ? AND status IN ('scheduled', 'sending')")->execute([$id, $rid]);
        $conn->prepare("UPDATE wa_outbox SET status = 'skipped', error = 'Campaign cancelled' WHERE restaurant_id = ? AND ref_type = 'campaign' AND ref_id = ? AND status = 'queued'")->execute([$rid, $id]);
        waOut(['success' => true]);
    }

    if ($action === 'retry') {
        $n = $conn->prepare("UPDATE wa_outbox SET status = 'queued', error = NULL, send_after = NOW() WHERE restaurant_id = ? AND status = 'failed' AND created_at >= NOW() - INTERVAL 3 DAY");
        $n->execute([$rid]);
        waOut(['success' => true, 'requeued' => $n->rowCount(), 'dispatched' => waDispatch($conn, $rid, 25)]);
    }

    if ($action === 'dispatch') waOut(['success' => true, 'dispatched' => waDispatch($conn, $rid, 25)]);

    if ($action === 'templates_create') {
        $kinds = isset($in['kinds']) && is_array($in['kinds']) ? array_values(array_intersect($in['kinds'], array_keys(waKinds()))) : null;
        $r3 = waTemplatesCreate($conn, $rid, $kinds);
        waOut(['success' => empty($r3['errors']['_']), 'message' => $r3['errors']['_'] ?? $r3['errors']['_rate'] ?? null] + $r3);
    }
    if ($action === 'templates_sync') waOut(['success' => true, 'result' => waTemplatesSync($conn, $rid)]);

    if ($action === 'disconnect') {
        if ($s['connected_via'] === 'connect' && $s['api_key'] !== '') rgcRevoke($s['api_key']);
        $conn->prepare("UPDATE wa_settings SET enabled = 0, api_key = NULL, account_name = NULL, rg_account_id = NULL, rg_phone = NULL, connected_via = NULL, connected_at = NULL, lost_at = NULL, templates_retry = 0 WHERE restaurant_id = ?")->execute([$rid]);
        waResetTemplates($conn, $rid);
        waOut(['success' => true]);
    }

    waOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('whatsapp_admin.php: ' . $e->getMessage());
    waOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}

function orderAbuseNormalizePhoneSafe(string $p): string {
    require_once __DIR__ . '/../config/order_abuse_guard.php';
    return orderAbuseNormalizePhone($p) ?? $p;
}
