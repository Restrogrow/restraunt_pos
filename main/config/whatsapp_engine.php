<?php
/**
 * WhatsApp messages (Growth › WhatsApp), sent through RepeatGrow.
 *
 * Two ways to connect:
 *   own       the restaurant's own RepeatGrow API key (their own WhatsApp number).
 *             Every message type uses a template the owner approved in RepeatGrow;
 *             the message they write here ({name}, {reward}…) becomes that template's
 *             {{1}}, {{2}}… in order of appearance.
 *   platform  RestroGrow's number (REPEATGROW_API_KEY in .env) with one ready-made
 *             gift template (REPEATGROW_GIFT_TEMPLATE). Gift messages only; campaigns
 *             need the restaurant's own connection.
 *
 * Every gift code the loyalty engine issues (loyalty_rewards_issued, notify_status
 * 'pending') is picked up by waDispatch(): customers who opted in get it on
 * WhatsApp, the rest are marked skipped. Campaigns go through the same outbox.
 * waDispatch() runs from the dashboard (api/wa_dispatch.php, every minute while it
 * is open) and from cron/whatsapp_dispatch.php.
 *
 * Never sends from a local computer: localhost requests and CLI runs on Windows
 * are dry runs (logged as "test", nothing leaves the machine). The one exception is a
 * local stand-in RepeatGrow (REPEATGROW_API_URL / REPEATGROW_CONNECT_URL on localhost),
 * used to test error handling.
 *
 * What RepeatGrow can answer, and what happens:
 *   401 unauthorized   the owner disconnected RestroGrow in RepeatGrow: the connection is
 *                      cleared (waConnectionLost) and the page asks them to connect again
 *   429 rate_limited   stop this restaurant's batch; messages stay queued, templates are
 *                      retried (templates_retry) on the next run
 *   409 on a template  that name exists with other wording: the next version (_vN) is tried once
 *   opted_out          the customer replied STOP: no longer opted in here either (waOptedOut)
 *
 * The API key is stored encrypted (waKeySeal / waKeyOpen, WA_KEY_SECRET in .env).
 */
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/repeatgrow_whatsapp.php';
require_once __DIR__ . '/loyalty_engine.php';
require_once __DIR__ . '/loyalty_autopilot.php';
require_once __DIR__ . '/repeatgrow_connect.php';
require_once __DIR__ . '/demo_restaurant.php';

if (!function_exists('ensureWhatsappSchema')) {

    // kind => [label, icon, default message]
    function waKinds(): array {
        return [
            'welcome'     => ['Welcome gift', 'waving_hand', 'Welcome to {restaurant}, {name}! Here is {reward} on your next order. Use code {code} before {valid_till}.'],
            'birthday'    => ['Birthday treat', 'cake', 'Happy birthday, {name}! {restaurant} has a treat for you: {reward}. Use code {code} before {valid_till}.'],
            'anniversary' => ['Anniversary', 'favorite', 'Happy anniversary, {name}! Celebrate with {reward} at {restaurant}. Use code {code} before {valid_till}.'],
            'winback'     => ['We miss you', 'history', 'We miss you, {name}! Come back to {restaurant} for {reward}. Use code {code} before {valid_till}.'],
            'tier_up'     => ['Level up', 'military_tech', 'Well done, {name}! You just reached {tier} at {restaurant}. Enjoy {reward}. Use code {code} before {valid_till}.'],
            'milestone'   => ['Order milestone', 'emoji_events', 'Thank you, {name}! That was order number {count} at {restaurant}. Here is {reward}. Use code {code} before {valid_till}.'],
            'referral'    => ['Referral gift', 'group_add', 'Thanks for spreading the word, {name}! You get {reward} at {restaurant}. Use code {code} before {valid_till}.'],
            'stamps'      => ['Full stamp card', 'approval', 'Your stamp card at {restaurant} is full, {name}! Enjoy {reward}. Use code {code} before {valid_till}.'],
            'challenge'   => ['Challenge done', 'flag', 'Challenge complete, {name}! {restaurant} has {reward} for you. Use code {code} before {valid_till}.'],
            'streak'      => ['Weekly streak', 'local_fire_department', 'What a streak, {name}! {restaurant} has {reward} for you. Use code {code} before {valid_till}.'],
            'vip'         => ['Member welcome gift', 'diamond', 'Welcome to the club, {name}! Your member gift at {restaurant}: {reward}. Use code {code} before {valid_till}.'],
        ];
    }
    // Variables a message can use, in the order the owner may write them
    function waVars(): array {
        return ['name' => 'Customer’s first name', 'reward' => 'The gift, e.g. ₹50 off', 'code' => 'The gift code', 'valid_till' => 'Last day to use it',
                'restaurant' => 'Your restaurant name', 'min_order' => 'Minimum order for the gift', 'link' => 'Order link (their gift is already applied; clicks are counted)',
                'tier' => 'New level (Level up only)', 'count' => 'Order number (Milestone only)'];
    }
    function waAutopilotKinds(): array { return ['welcome', 'birthday', 'anniversary', 'winback', 'tier_up', 'milestone', 'referral']; }

    function ensureWhatsappSchema(PDO $conn): void {
        static $done = false;
        if ($done || $conn->inTransaction()) return;
        $done = true;
        ensureLoyaltyEngineSchema($conn);
        $conn->exec("CREATE TABLE IF NOT EXISTS wa_settings (
            restaurant_id VARCHAR(50) PRIMARY KEY,
            enabled TINYINT NOT NULL DEFAULT 0,
            mode VARCHAR(10) NOT NULL DEFAULT 'platform',
            api_key VARCHAR(200) NULL,
            account_name VARCHAR(120) NULL,
            quiet_start TINYINT NOT NULL DEFAULT 21,
            quiet_end TINYINT NOT NULL DEFAULT 9,
            weekly_cap TINYINT NOT NULL DEFAULT 2,
            test_phone VARCHAR(20) NULL,
            last_dispatch DATETIME NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS wa_auto (
            restaurant_id VARCHAR(50) NOT NULL,
            kind VARCHAR(20) NOT NULL,
            enabled TINYINT NOT NULL DEFAULT 1,
            template_name VARCHAR(100) NULL,
            language VARCHAR(10) NOT NULL DEFAULT 'en',
            message VARCHAR(700) NULL,
            PRIMARY KEY (restaurant_id, kind)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS wa_outbox (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            kind VARCHAR(20) NOT NULL,
            ref_type VARCHAR(10) NOT NULL,
            ref_id INT NOT NULL,
            customer_id INT NULL,
            phone VARCHAR(20) NOT NULL,
            name VARCHAR(100) NULL,
            template_name VARCHAR(100) NULL,
            language VARCHAR(10) NULL,
            params TEXT NULL,
            body VARCHAR(1000) NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'queued',
            error VARCHAR(255) NULL,
            attempts TINYINT NOT NULL DEFAULT 0,
            send_after DATETIME NULL,
            sent_at DATETIME NULL,
            message_id VARCHAR(120) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_wa_ref (restaurant_id, ref_type, ref_id, phone),
            INDEX idx_wa_queue (status, send_after),
            INDEX idx_wa_rest (restaurant_id, created_at)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS wa_campaigns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            name VARCHAR(80) NOT NULL,
            audience VARCHAR(20) NOT NULL,
            template_name VARCHAR(100) NOT NULL,
            language VARCHAR(10) NOT NULL DEFAULT 'en',
            message VARCHAR(700) NOT NULL,
            gift_kind VARCHAR(10) NULL,
            gift_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            gift_item_id INT NULL,
            gift_min_order DECIMAL(10,2) NOT NULL DEFAULT 0,
            gift_valid_days INT NOT NULL DEFAULT 14,
            scheduled_at DATETIME NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'scheduled',
            audience_count INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wc_due (status, scheduled_at)
        )");
        ensureRepeatgrowConnectSchema($conn);   // template tracking for "Connect RepeatGrow"
        // delivery / read (RepeatGrow message.status_updated webhook) and link clicks (website/go.php)
        $have = array_column($conn->query('SHOW COLUMNS FROM wa_outbox')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        foreach (['delivered_at' => 'DATETIME NULL', 'read_at' => 'DATETIME NULL', 'clicked_at' => 'DATETIME NULL'] as $col => $def)
            if (!in_array($col, $have, true)) $conn->exec("ALTER TABLE wa_outbox ADD COLUMN $col $def");
        if (!in_array('message_id', array_column($conn->query("SHOW INDEX FROM wa_outbox WHERE Key_name = 'idx_wa_msg'")->fetchAll(PDO::FETCH_ASSOC), 'Column_name'), true))
            $conn->exec('ALTER TABLE wa_outbox ADD INDEX idx_wa_msg (message_id)');
        $wcols = array_column($conn->query('SHOW COLUMNS FROM wa_settings')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        foreach (['status_hook_id' => 'VARCHAR(80) NULL', 'status_hook_secret' => 'VARCHAR(300) NULL'] as $col => $def)
            if (!in_array($col, $wcols, true)) $conn->exec("ALTER TABLE wa_settings ADD COLUMN $col $def");
        waKeysEncryptAll($conn);
    }

    /* ───────────── API key at rest: AES-256-GCM with WA_KEY_SECRET from .env ───────────── */

    function waKeySecret(): ?string {
        $k = trim((string)env('WA_KEY_SECRET', ''));
        if ($k === '') return null;
        $raw = base64_decode($k, true);
        return hash('sha256', ($raw !== false && strlen($raw) >= 32) ? $raw : $k, true);
    }
    /** Plain key → 'enc:v1:…'. Without WA_KEY_SECRET the key stays as it is (and is encrypted once the secret is set). */
    function waKeySeal(string $plain): string {
        if ($plain === '' || strpos($plain, 'enc:v1:') === 0) return $plain;
        $k = waKeySecret();
        if ($k === null) return $plain;
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        return $ct === false ? $plain : 'enc:v1:' . base64_encode($iv . $tag . $ct);
    }
    /** Stored value → plain key ('' when it can't be read, e.g. WA_KEY_SECRET changed). Plain values pass through. */
    function waKeyOpen(string $stored): string {
        if (strpos($stored, 'enc:v1:') !== 0) return $stored;
        $k = waKeySecret();
        $raw = base64_decode(substr($stored, 7), true);
        $plain = ($k !== null && $raw !== false && strlen($raw) > 28) ? openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)) : false;
        if ($plain === false) { error_log('WhatsApp: a stored RepeatGrow key could not be decrypted (is WA_KEY_SECRET the same as when it was saved?)'); return ''; }
        return $plain;
    }
    /** Older rows saved before encryption: encrypt them (runs with the schema check, once per request). */
    function waKeysEncryptAll(PDO $conn): void {
        if (waKeySecret() === null) return;
        try {
            $rows = $conn->query("SELECT restaurant_id, api_key FROM wa_settings WHERE api_key IS NOT NULL AND api_key <> '' AND api_key NOT LIKE 'enc:v1:%'")->fetchAll(PDO::FETCH_ASSOC);
            $up = $conn->prepare('UPDATE wa_settings SET api_key = ? WHERE restaurant_id = ? AND api_key = ?');
            foreach ($rows as $r) $up->execute([waKeySeal($r['api_key']), $r['restaurant_id'], $r['api_key']]);
        } catch (PDOException $e) { error_log('waKeysEncryptAll: ' . $e->getMessage()); }
    }

    /** Local computer: never send for real. */
    function waDryRun(): bool {
        if (env('WA_DRY_RUN') === '1') return true;
        if (PHP_SAPI === 'cli') return stripos(PHP_OS, 'WIN') === 0;
        return isLocalDevRequest();
    }

    function waSettings(PDO $conn, string $rid): array {
        $st = $conn->prepare('SELECT * FROM wa_settings WHERE restaurant_id = ?');
        $st->execute([$rid]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['enabled' => (int)($r['enabled'] ?? 0), 'mode' => ($r['mode'] ?? '') === 'own' ? 'own' : 'platform',
                'api_key' => waKeyOpen((string)($r['api_key'] ?? '')), 'account_name' => (string)($r['account_name'] ?? ''),
                'quiet_start' => (int)($r['quiet_start'] ?? 21), 'quiet_end' => (int)($r['quiet_end'] ?? 9),
                'weekly_cap' => (int)($r['weekly_cap'] ?? 2), 'test_phone' => (string)($r['test_phone'] ?? ''),
                'last_dispatch' => $r['last_dispatch'] ?? null,
                'connected_via' => (string)($r['connected_via'] ?? ''), 'rg_phone' => (string)($r['rg_phone'] ?? ''),
                'connected_at' => $r['connected_at'] ?? null, 'templates_synced_at' => $r['templates_synced_at'] ?? null,
                'lost_at' => $r['lost_at'] ?? null, 'templates_retry' => (int)($r['templates_retry'] ?? 0),
                'is_demo' => $rid === DEMO_RESTAURANT_ID,
                // waSend() reads it to give local numbers the restaurant's own country code
                'restaurant_id' => $rid];
    }

    /** Template statuses are checked at most every 10 minutes (and right away after a rate limit cleared them). */
    function waTemplatesSyncDue(array $s): bool {
        return $s['connected_via'] === 'connect' && (empty($s['templates_synced_at']) || strtotime($s['templates_synced_at']) < time() - 600);
    }

    /** Forget every template made through a connection (Disconnect, or the key stopped working). */
    function waResetTemplates(PDO $conn, string $rid): void {
        // a different RepeatGrow account may be connected next: its templates are checked again from scratch
        $conn->prepare('UPDATE wa_auto SET template_name = IF(template_text IS NULL, template_name, NULL), template_status = NULL, template_reason = NULL, pending_template = NULL, pending_text = NULL, pending_vars = NULL, template_text = NULL WHERE restaurant_id = ?')->execute([$rid]);
    }

    /** RepeatGrow answered 401: the owner disconnected RestroGrow there. Clear it as Disconnect does. */
    function waConnectionLost(PDO $conn, string $rid): void {
        $conn->prepare("UPDATE wa_settings SET enabled = 0, api_key = NULL, account_name = NULL, rg_account_id = NULL, rg_phone = NULL, connected_via = NULL, connected_at = NULL,
            templates_retry = 0, lost_at = NOW() WHERE restaurant_id = ?")->execute([$rid]);
        waResetTemplates($conn, $rid);
        error_log("WhatsApp $rid: RepeatGrow no longer accepts the key (401), connection cleared");
    }
    function waLostMessage(): string { return 'RepeatGrow was disconnected. Connect again to keep sending.'; }

    /**
     * The same receipts for RestroGrow's shared number (REPEATGROW_API_KEY): one webhook for the whole platform,
     * kept in sa_settings 'wa_platform_status_hook' {id, secret (sealed), error, tried}. Tries again at most once a day.
     */
    function waEnsurePlatformStatusHook(PDO $conn): void {
        if (waDryRun()) return;
        $c = waConnection(['mode' => 'platform', 'api_key' => '']);
        if (!$c) return;
        $conn->exec("CREATE TABLE IF NOT EXISTS sa_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cur = json_decode((string)$conn->query("SELECT v FROM sa_settings WHERE k = 'wa_platform_status_hook'")->fetchColumn(), true) ?: [];
        if (!empty($cur['id']) && !empty($cur['secret'])) return;
        if (!empty($cur['tried']) && (int)$cur['tried'] > time() - 86400) return;
        $app = rtrim((string)env('APP_API_BASE', ''), '/') ?: (rtrim((string)env('APP_URL', ''), '/') ?: 'https://restrogrow.com') . '/main/api';
        $ch = curl_init(preg_replace('#/messages/?$#', '/webhooks', $c['url']));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $c['key']],
            CURLOPT_POSTFIELDS => json_encode(['url' => $app . '/wa_webhook.php?rid=platform&hook=status',
                'events' => ['message.status_updated'], 'description' => 'RestroGrow shared number: delivery and read receipts'])]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $d = json_decode((string)$res, true) ?: [];
        $id = (string)($d['data']['id'] ?? '');
        $secret = (string)($d['data']['secret'] ?? '');
        $save = $id !== '' && $secret !== '' ? ['id' => $id, 'secret' => waKeySeal($secret), 'at' => date('c')]
            : ['tried' => time(), 'error' => $code === 403 ? 'The RepeatGrow key in .env (REPEATGROW_API_KEY) needs the webhooks:manage permission' : mb_substr(rgcError($d, 'Error ' . $code), 0, 200)];
        $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES ('wa_platform_status_hook', ?)")->execute([json_encode($save)]);
        if (empty($save['id'])) error_log('WhatsApp platform status webhook not registered: ' . $save['error']);
    }

    /**
     * Ask RepeatGrow to tell us when this restaurant's messages are delivered / read (webhook
     * message.status_updated → api/wa_webhook.php?hook=status). Once; tries again at most once a day.
     */
    function waEnsureStatusHook(PDO $conn, string $rid): void {
        $s = waSettings($conn, $rid);
        if ($s['mode'] === 'platform') { waEnsurePlatformStatusHook($conn); return; }
        if ($s['mode'] !== 'own' || $s['api_key'] === '' || waDryRun()) return;
        $st = $conn->prepare('SELECT status_hook_id FROM wa_settings WHERE restaurant_id = ?');
        $st->execute([$rid]);
        $have = (string)$st->fetchColumn();
        if ($have !== '' && strpos($have, 'try:') !== 0) return;
        if (strpos($have, 'try:') === 0 && (int)substr($have, 4) > time() - 86400) return;
        $c = waConnection($s);
        if (!$c) return;
        $app = rtrim((string)env('APP_API_BASE', ''), '/') ?: (rtrim((string)env('APP_URL', ''), '/') ?: 'https://restrogrow.com') . '/main/api';
        $ch = curl_init(preg_replace('#/messages/?$#', '/webhooks', $c['url']));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $c['key']],
            CURLOPT_POSTFIELDS => json_encode(['url' => $app . '/wa_webhook.php?rid=' . rawurlencode($rid) . '&hook=status',
                'events' => ['message.status_updated'], 'description' => 'RestroGrow campaign delivery and read receipts'])]);
        $res = curl_exec($ch);
        curl_close($ch);
        $d = json_decode((string)$res, true) ?: [];
        $id = (string)($d['data']['id'] ?? '');
        $secret = (string)($d['data']['secret'] ?? '');
        if ($id !== '' && $secret !== '') $conn->prepare('UPDATE wa_settings SET status_hook_id = ?, status_hook_secret = ? WHERE restaurant_id = ?')->execute([$id, waKeySeal($secret), $rid]);
        else { $conn->prepare('UPDATE wa_settings SET status_hook_id = ? WHERE restaurant_id = ?')->execute(['try:' . time(), $rid]); error_log("WhatsApp $rid: status webhook not registered: " . substr((string)$res, 0, 200)); }
    }

    /** The customer replied STOP on WhatsApp (RepeatGrow: opted_out): stop messaging them from here too. */
    function waOptedOut(PDO $conn, string $rid, array $m): void {
        $why = 'Replied STOP on WhatsApp, so no longer opted in';
        $conn->prepare('UPDATE customers SET whatsapp_optin = 0, wa_stopped_at = NOW() WHERE restaurant_id = ? AND (id = ? OR phone = ?)')
             ->execute([$rid, (int)($m['customer_id'] ?? 0), (string)$m['phone']]);
        // this message and anything else still waiting for them
        $rows = $conn->prepare("SELECT id, ref_type, ref_id FROM wa_outbox WHERE restaurant_id = ? AND phone = ? AND (id = ? OR status = 'queued')");
        $rows->execute([$rid, (string)$m['phone'], (int)$m['id']]);
        $skip = $conn->prepare("UPDATE wa_outbox SET status = 'skipped', error = ?, attempts = attempts + IF(id = ?, 1, 0) WHERE id = ?");
        $gift = $conn->prepare("UPDATE loyalty_rewards_issued SET notify_status = 'opted_out' WHERE id = ? AND restaurant_id = ?");
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $skip->execute([$why, (int)$m['id'], (int)$o['id']]);
            if ($o['ref_type'] === 'gift') $gift->execute([(int)$o['ref_id'], $rid]);
        }
    }

    /** RestroGrow's ready-made gift template (platform mode). */
    function waPlatformTemplate(): array {
        return ['name' => (string)env('REPEATGROW_GIFT_TEMPLATE', ''), 'language' => (string)env('REPEATGROW_GIFT_TEMPLATE_LANG', 'en'),
                // {{1}} name, {{2}} restaurant, {{3}} reward, {{4}} code, {{5}} valid till
                'vars' => ['name', 'restaurant', 'reward', 'code', 'valid_till'],
                'text' => 'Hi {{1}}, {{2}} has a gift for you: {{3}}. Use code {{4}} on your next order before {{5}}.'];
    }

    /** API URL + key for this restaurant, or null when it cannot send. */
    function waConnection(array $s): ?array {
        $url = (string)env('REPEATGROW_API_URL', '');
        if ($url === '') return null;
        $key = $s['mode'] === 'own' ? $s['api_key'] : (string)env('REPEATGROW_API_KEY', '');
        return $key === '' ? null : ['url' => $url, 'key' => $key];
    }

    /** The owner's setup for each message type, defaults filled in. */
    function waAutoConfig(PDO $conn, string $rid): array {
        $out = [];
        foreach (waKinds() as $k => $d) $out[$k] = ['kind' => $k, 'label' => $d[0], 'icon' => $d[1], 'enabled' => 1, 'template_name' => '', 'language' => 'en', 'message' => $d[2],
            // no row yet (e.g. just connected): no template made for it so far
            'template_status' => null, 'template_reason' => null, 'pending_template' => null, 'template_text' => null, 'pending_text' => null,
            'template_version' => 0, 'template_vars' => null, 'pending_vars' => null];
        $st = $conn->prepare('SELECT * FROM wa_auto WHERE restaurant_id = ?');
        $st->execute([$rid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($out[$r['kind']])) continue;
            $out[$r['kind']]['enabled'] = (int)$r['enabled'];
            $out[$r['kind']]['template_name'] = (string)$r['template_name'];
            $out[$r['kind']]['language'] = $r['language'] ?: 'en';
            if (!empty($r['message'])) $out[$r['kind']]['message'] = $r['message'];
            foreach (['template_status', 'template_reason', 'pending_template', 'template_text', 'pending_text'] as $f) $out[$r['kind']][$f] = $r[$f] ?? null;
            $out[$r['kind']]['template_version'] = (int)($r['template_version'] ?? 0);
            $out[$r['kind']]['template_vars'] = !empty($r['template_vars']) ? (json_decode($r['template_vars'], true) ?: null) : null;
            $out[$r['kind']]['pending_vars'] = !empty($r['pending_vars']) ? (json_decode($r['pending_vars'], true) ?: null) : null;
        }
        // Autopilot gifts keep their message with the automation (Autopilot edits the same text)
        foreach (loyaltyAutomations($conn, $rid) as $k => $a) {
            if (isset($out[$k])) { $out[$k]['message'] = $a['message']; $out[$k]['autopilot_on'] = (int)$a['enabled']; }
        }
        return $out;
    }

    /** Save a message type's text: Autopilot gifts into their automation, the rest here. */
    function waSaveMessage(PDO $conn, string $rid, string $kind, string $message): void {
        if (in_array($kind, waAutopilotKinds(), true)) {
            $d = loyaltyAutopilotDefaults()[$kind];
            $conn->prepare('INSERT INTO loyalty_automations (restaurant_id, type, enabled, reward_kind, reward_value, param, min_order, valid_days, message)
                VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE message = VALUES(message)')
                 ->execute([$rid, $kind, $d[1], $d[2], $d[3], $d[4], $d[5], $message]);
            return;
        }
        $conn->prepare('INSERT INTO wa_auto (restaurant_id, kind, message) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE message = VALUES(message)')->execute([$rid, $kind, $message]);
    }

    /** "Hi {name}, {reward}" → ["Hi {{1}}, {{2}}", ['name', 'reward']] */
    function waTemplateFromMessage(string $msg): array {
        $vars = [];
        $known = array_keys(waVars());
        $text = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use (&$vars, $known) {
            if (!in_array($m[1], $known, true)) return $m[0];
            $vars[] = $m[1];
            return '{{' . count($vars) . '}}';
        }, $msg);
        return [$text, $vars];
    }

    function waFill(string $msg, array $v): string {
        return preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($v) { return array_key_exists($m[1], $v) ? (string)$v[$m[1]] : $m[0]; }, $msg);
    }

    /** Stand-in for {link} until the outbox row exists; then waFillLinkSlot puts the customer's own tracked link in. */
    if (!defined('WA_LINK_SLOT')) define('WA_LINK_SLOT', '[[rg-link]]');
    function waFillLinkSlot(PDO $conn, string $rid, int $outboxId, string $kind, string $ref, string $label): void {
        if ($outboxId <= 0) return;   // INSERT IGNORE skipped a duplicate
        try {
            require_once __DIR__ . '/link_tracking.php';
            $l = tlLink($conn, $rid, $kind, $ref, ['label' => $label, 'target' => 'menu', 'src' => 'wa-' . $ref]);
            $url = tlShortUrl($conn, $rid, $l['code'], $outboxId);
        } catch (Throwable $e) {
            error_log('wa link: ' . $e->getMessage());
            $url = waRestaurant($conn, $rid)['link'];
        }
        $st = $conn->prepare('SELECT params, body FROM wa_outbox WHERE id = ?');
        $st->execute([$outboxId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (strpos((string)$row['params'], WA_LINK_SLOT) === false && strpos((string)$row['body'], WA_LINK_SLOT) === false)) return;
        $params = str_replace(json_encode(WA_LINK_SLOT), json_encode($url, JSON_UNESCAPED_SLASHES), (string)$row['params']);
        $conn->prepare('UPDATE wa_outbox SET params = ?, body = ? WHERE id = ?')->execute([$params, mb_substr(str_replace(WA_LINK_SLOT, $url, (string)$row['body']), 0, 1000), $outboxId]);
    }

    function waRestaurant(PDO $conn, string $rid): array {
        static $cache = [];
        if (isset($cache[$rid])) return $cache[$rid];
        $st = $conn->prepare('SELECT restaurant_name, currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1');
        $st->execute([$rid]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $base = rtrim((string)env('APP_URL', ''), '/') ?: 'https://restrogrow.com';
        // the restaurant's own rewards page address: restrogrow.com/<name-as-slug>/loyalty
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)($r['restaurant_name'] ?? ''))), '-');
        return $cache[$rid] = ['name' => (string)($r['restaurant_name'] ?? ''), 'cur' => (string)($r['currency_symbol'] ?? '') ?: '₹',
            'link' => $slug !== '' ? $base . '/' . $slug . '/loyalty' : $base . '/main/website/loyalty.php?restaurant_id=' . rawurlencode($rid)];
    }

    /** Values for one issued gift. */
    function waGiftVars(PDO $conn, array $g, array $customer): array {
        $r = waRestaurant($conn, $g['restaurant_id']);
        $first = trim(explode(' ', trim((string)($customer['customer_name'] ?? '')))[0] ?? '');
        $min = (float)($g['min_order'] ?? 0);
        return ['name' => $first !== '' ? $first : 'there', 'reward' => $g['label'], 'code' => $g['coupon_code'],
                'valid_till' => $g['expires_on'] ? date('j M', strtotime($g['expires_on'])) : 'soon',
                'restaurant' => $r['name'], 'min_order' => $min > 0 ? $r['cur'] . rtrim(rtrim(number_format($min, 2, '.', ''), '0'), '.') : 'any order',
                'link' => $r['link'],
                'tier' => strpos((string)$g['period_key'], 'tier-') === 0 ? substr($g['period_key'], 5) : '',
                'count' => strpos((string)$g['period_key'], 'order') === 0 ? substr($g['period_key'], 5) : ''];
    }

    /** What to send for a message: template name, language, params, readable body. */
    function waBuild(array $s, ?array $auto, string $message, array $vars): ?array {
        if ($s['mode'] === 'platform') {
            $t = waPlatformTemplate();
            if ($t['name'] === '') return null;
            $params = array_map(function ($k) use ($vars) { return (string)($vars[$k] ?? ''); }, $t['vars']);
            $body = $t['text'];
            foreach ($params as $i => $p) $body = str_replace('{{' . ($i + 1) . '}}', $p, $body);
            return ['template_name' => $t['name'], 'language' => $t['language'], 'params' => $params, 'body' => $body];
        }
        $name = trim((string)($auto['template_name'] ?? ''));
        if ($name === '') return null;
        // templates created through Connect RepeatGrow have a review status; only an approved one sends
        if (!empty($auto['template_status']) && $auto['template_status'] !== 'approved') return null;
        [, $order] = waTemplateFromMessage($message);
        if (!empty($auto['template_vars'])) $order = $auto['template_vars'];   // the approved template's own order
        $params = array_map(function ($k) use ($vars) { $v = (string)($vars[$k] ?? ''); return $v !== '' ? $v : '-'; }, $order);
        // the log shows what the customer really gets: the approved template's wording
        $body = waFill($message, $vars);
        if (!empty($auto['template_text'])) { $body = $auto['template_text']; foreach ($params as $i => $p) $body = str_replace('{{' . ($i + 1) . '}}', $p, $body); }
        return ['template_name' => $name, 'language' => $auto['language'] ?? 'en', 'params' => $params, 'body' => $body];
    }

    function waLocalUrl(string $url): bool { return in_array(parse_url($url, PHP_URL_HOST), ['localhost', '127.0.0.1'], true); }

    /**
     * POST one message to RepeatGrow. Returns [ok, message_id|null, error|null, reason|null] where
     * reason is 'unauthorized' (401), 'rate_limited' (429), 'opted_out' (customer replied STOP) or 'network'.
     * $rich: a ready RepeatGrow body without "to" (type image / interactive, from woPayload()), sent instead of $text.
     */
    function waSend(array $s, string $phone, ?string $templateName, string $lang, array $params, string $text, ?array $rich = null): array {
        // the public demo restaurant never sends real messages (anyone can log in to it)
        if (!empty($s['is_demo'])) return [true, 'demo-' . substr(md5($phone . microtime(true)), 0, 10), null, null];
        $c = waConnection($s);
        // local computer: nothing is sent, except to a local stand-in RepeatGrow
        if (waDryRun() && !($c && waLocalUrl($c['url']))) return [true, 'test-' . substr(md5($phone . microtime(true)), 0, 10), null, null];
        if (!$c) return [false, null, 'WhatsApp is not connected', null];
        // a local number gets the restaurant's own country code (+977 for a Nepal restaurant)
        $country = null;
        if (!empty($s['restaurant_id']) && function_exists('getConnection')) {
            require_once __DIR__ . '/countries.php';
            try { $country = restaurantCountryInfo(getConnection(), (string)$s['restaurant_id']); } catch (Throwable $e) { $country = null; }
        }
        $payload = ['to' => normalizePhoneForWhatsapp($phone, $country)];
        if ($templateName) $payload += ['type' => 'template', 'template' => ['name' => $templateName, 'language' => $lang, 'params' => array_values($params)]];
        elseif ($rich) $payload += $rich;
        else $payload += ['type' => 'text', 'text' => $text];
        $ch = curl_init($c['url']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $c['key']], CURLOPT_POSTFIELDS => json_encode($payload)]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) return [false, null, 'Could not reach WhatsApp: ' . $err, 'network'];
        $d = json_decode((string)$res, true) ?: [];
        if ($code >= 200 && $code < 300) return [true, $d['data']['whatsapp_message_id'] ?? $d['data']['message_id'] ?? null, null, null];
        $why = $code === 401 ? 'unauthorized' : ($code === 429 ? 'rate_limited' : rgcErrorCode($d));
        return [false, null, mb_substr(rgcError($d, 'Error ' . $code), 0, 250), $why !== '' ? $why : null];
    }

    /** Check an API key (GET …/me). Returns [ok, account name or error]. */
    function waCheckKey(string $key): array {
        if (waDryRun()) return [true, 'Local test account'];
        $url = (string)env('REPEATGROW_API_URL', '');
        if ($url === '') return [false, 'RepeatGrow is not set up on this server'];
        $me = preg_replace('#/messages/?$#', '/me', $url);
        $ch = curl_init($me);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $d = json_decode((string)$res, true);
        if ($code === 200) {
            $scopes = $d['data']['key']['scopes'] ?? [];
            if ($scopes && !in_array('messages:send', $scopes, true)) return [false, 'This key cannot send messages. Give it the messages:send permission in RepeatGrow.'];
            return [true, (string)($d['data']['account']['name'] ?? 'Connected')];
        }
        return [false, (string)($d['error']['message'] ?? 'The key was not accepted')];
    }

    function waInQuietHours(array $s): bool {
        $h = (int)date('G');
        $a = $s['quiet_start']; $b = $s['quiet_end'];
        if ($a === $b) return false;
        return $a > $b ? ($h >= $a || $h < $b) : ($h >= $a && $h < $b);
    }

    /** Campaign audiences: [key => [label, SQL condition on customers c]] */
    function waAudiences(): array {
        return [
            'all'       => ['Everyone who opted in', '1 = 1'],
            'recent'    => ['Ordered in the last 30 days', "EXISTS (SELECT 1 FROM orders o WHERE o.restaurant_id = c.restaurant_id AND o.customer_phone = c.phone AND o.order_status = 'Completed' AND o.created_at >= NOW() - INTERVAL 30 DAY)"],
            'lapsed30'  => ['No order for 30+ days', "EXISTS (SELECT 1 FROM orders o WHERE o.restaurant_id = c.restaurant_id AND o.customer_phone = c.phone AND o.order_status = 'Completed') AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.restaurant_id = c.restaurant_id AND o.customer_phone = c.phone AND o.created_at >= NOW() - INTERVAL 30 DAY)"],
            'lapsed60'  => ['No order for 60+ days', "EXISTS (SELECT 1 FROM orders o WHERE o.restaurant_id = c.restaurant_id AND o.customer_phone = c.phone AND o.order_status = 'Completed') AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.restaurant_id = c.restaurant_id AND o.customer_phone = c.phone AND o.created_at >= NOW() - INTERVAL 60 DAY)"],
            'regulars'  => ['Regulars (5+ orders)', "(SELECT COUNT(*) FROM orders o WHERE o.restaurant_id = c.restaurant_id AND o.customer_phone = c.phone AND o.order_status = 'Completed') >= 5"],
            'new'       => ['New customers (1 order)', "(SELECT COUNT(*) FROM orders o WHERE o.restaurant_id = c.restaurant_id AND o.customer_phone = c.phone AND o.order_status = 'Completed') = 1"],
            'birthdays' => ['Birthday this month', 'MONTH(c.birthday) = MONTH(CURDATE())'],
            'vip'       => ['VIP members', "EXISTS (SELECT 1 FROM vip_members m WHERE m.restaurant_id = c.restaurant_id AND m.phone = c.phone AND m.status = 'Active' AND m.ends_at > NOW())"],
        ];
    }

    function waAudienceSql(string $key): string {
        $a = waAudiences();
        return "FROM customers c WHERE c.restaurant_id = ? AND c.whatsapp_optin = 1 AND c.phone <> '' AND (" . ($a[$key][1] ?? '1 = 0') . ')';
    }

    function waAudienceCount(PDO $conn, string $rid, string $key): int {
        try { $st = $conn->prepare('SELECT COUNT(*) ' . waAudienceSql($key)); $st->execute([$rid]); return (int)$st->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    /** Due campaigns → outbox rows (with a personal gift code each, if the campaign has one). */
    function waStartCampaigns(PDO $conn, string $rid, array $s): void {
        $st = $conn->prepare("SELECT * FROM wa_campaigns WHERE restaurant_id = ? AND status = 'scheduled' AND scheduled_at <= NOW()
            AND (template_status IS NULL OR template_status = 'approved')");
        $st->execute([$rid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $conn->prepare("UPDATE wa_campaigns SET status = 'sending' WHERE id = ? AND status = 'scheduled'")->execute([(int)$c['id']]);
            $people = $conn->prepare('SELECT c.id, c.phone, c.customer_name ' . waAudienceSql($c['audience']) . ' LIMIT 5000');
            $people->execute([$rid]);
            $list = $people->fetchAll(PDO::FETCH_ASSOC);
            $ins = $conn->prepare("INSERT IGNORE INTO wa_outbox (restaurant_id, kind, ref_type, ref_id, customer_id, phone, name, template_name, language, params, body, status, send_after)
                VALUES (?, 'campaign', 'campaign', ?, ?, ?, ?, ?, ?, ?, ?, 'queued', NOW())");
            [, $order] = waTemplateFromMessage($c['message']);
            if (!empty($c['template_vars'])) $order = json_decode($c['template_vars'], true) ?: $order;
            $r = waRestaurant($conn, $rid);
            foreach ($list as $p) {
                $vars = ['name' => trim(explode(' ', trim((string)$p['customer_name']))[0] ?? '') ?: 'there', 'restaurant' => $r['name'], 'link' => WA_LINK_SLOT,
                         'reward' => '', 'code' => '', 'valid_till' => '', 'min_order' => '', 'tier' => '', 'count' => ''];
                if ($c['gift_kind']) {
                    $conn->beginTransaction();
                    try {
                        $g = loyaltyIssueReward($conn, $rid, (int)$p['id'], (string)$p['phone'], 'campaign', 'c' . $c['id'], $c['gift_kind'], (float)$c['gift_value'],
                            (float)$c['gift_min_order'], (int)$c['gift_valid_days'], $c['gift_item_id'] ? (int)$c['gift_item_id'] : null);
                        $conn->commit();
                    } catch (Throwable $e) { $conn->rollBack(); $g = null; error_log('wa campaign gift: ' . $e->getMessage()); }
                    if ($g) {
                        // the campaign message carries this gift: don't send it twice
                        $conn->prepare("UPDATE loyalty_rewards_issued SET notify_status = 'campaign' WHERE restaurant_id = ? AND coupon_code = ?")->execute([$rid, $g['code']]);
                        $vars['reward'] = $g['label']; $vars['code'] = $g['code'];
                        $vars['valid_till'] = date('j M', strtotime('+' . (int)$c['gift_valid_days'] . ' days'));
                        $vars['min_order'] = $c['gift_min_order'] > 0 ? $r['cur'] . (float)$c['gift_min_order'] : 'any order';
                    }
                }
                $params = array_map(function ($k) use ($vars) { $v = (string)($vars[$k] ?? ''); return $v !== '' ? $v : '-'; }, $order);
                $ins->execute([$rid, (int)$c['id'], (int)$p['id'], $p['phone'], $p['customer_name'], $c['template_name'], $c['language'], json_encode($params, JSON_UNESCAPED_UNICODE), mb_substr(waFill($c['message'], $vars), 0, 1000)]);
                waFillLinkSlot($conn, $rid, (int)$conn->lastInsertId(), 'wa_campaign', 'c' . $c['id'], 'WhatsApp campaign · ' . $c['name']);
            }
            $conn->prepare('UPDATE wa_campaigns SET audience_count = ? WHERE id = ?')->execute([count($list), (int)$c['id']]);
        }
    }

    /** New gift codes → outbox (opted in and switched on) or skipped. */
    function waQueueGifts(PDO $conn, string $rid, array $s): void {
        $auto = waAutoConfig($conn, $rid);
        $st = $conn->prepare("SELECT r.*, c.customer_name, c.whatsapp_optin FROM loyalty_rewards_issued r LEFT JOIN customers c ON c.id = r.customer_id
            WHERE r.restaurant_id = ? AND r.notify_status = 'pending' ORDER BY r.id LIMIT 200");
        $st->execute([$rid]);
        $mark = $conn->prepare('UPDATE loyalty_rewards_issued SET notify_status = ? WHERE id = ?');
        $ins = $conn->prepare("INSERT IGNORE INTO wa_outbox (restaurant_id, kind, ref_type, ref_id, customer_id, phone, name, template_name, language, params, body, status, error, send_after)
            VALUES (?, ?, 'gift', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $kind = $g['source'];
            if (strtotime($g['created_at']) < time() - 3 * 86400) { $mark->execute(['skipped', $g['id']]); continue; }   // too old to announce
            if (!isset($auto[$kind]) || !$auto[$kind]['enabled']) { $mark->execute(['off', $g['id']]); continue; }
            if (empty($g['whatsapp_optin'])) { $mark->execute(['no_optin', $g['id']]); continue; }
            $a = $auto[$kind];
            $message = $a['message'];
            $vars = waGiftVars($conn, $g, $g);
            $vars['link'] = WA_LINK_SLOT;   // the tracked link goes in once the row has its id
            $b = waBuild($s, $a, $message, $vars);
            if (!$b && $s['mode'] === 'own' && in_array($a['template_status'] ?? '', ['pending'], true)) continue;   // goes out once Meta approves
            if (!$b) {
                $ins->execute([$rid, $kind, $g['id'], $g['customer_id'], $g['phone'], $g['customer_name'], null, null, null, mb_substr(waFill($message, $vars), 0, 1000), 'skipped', 'No approved template set for this message']);
                $mark->execute(['no_template', $g['id']]);
                continue;
            }
            $ins->execute([$rid, $kind, $g['id'], $g['customer_id'], $g['phone'], $g['customer_name'], $b['template_name'], $b['language'],
                json_encode($b['params'], JSON_UNESCAPED_UNICODE), mb_substr($b['body'], 0, 1000), 'queued', null]);
            waFillLinkSlot($conn, $rid, (int)$conn->lastInsertId(), 'wa_auto', $kind, 'WhatsApp · ' . $kind);
            $mark->execute(['queued', $g['id']]);
        }
    }

    /* ───────────── Templates through Connect RepeatGrow ───────────── */

    /** Example values Meta sees when reviewing a template. */
    function waTemplateSamples(PDO $conn, string $rid): array {
        $r = waRestaurant($conn, $rid);
        return ['name' => 'Priya', 'reward' => $r['cur'] . '50 off', 'code' => 'GIFT7K2Q9M', 'valid_till' => date('j M', strtotime('+14 days')),
                'restaurant' => $r['name'] ?: 'Our restaurant', 'min_order' => $r['cur'] . '199', 'link' => $r['link'], 'tier' => 'Gold', 'count' => '10'];
    }
    function waTemplateSlug(PDO $conn, string $rid): string {
        $s = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(waRestaurant($conn, $rid)['name'])), '_');
        if (strlen($s) > 30) $s = preg_replace('/_[^_]*$/', '', substr($s, 0, 30));
        return $s !== '' ? $s : 'restaurant';
    }

    /**
     * Create (or update) the templates for these message types in RepeatGrow, which
     * sends them to Meta for review. A changed wording on an approved template goes in
     * as a new version; the approved one keeps sending until the new one is approved.
     * Returns ['created' => n, 'unchanged' => n, 'errors' => [kind => message]].
     */
    function waTemplatesCreate(PDO $conn, string $rid, ?array $kinds = null): array {
        $s = waSettings($conn, $rid);
        $out = ['created' => 0, 'unchanged' => 0, 'errors' => []];
        if ($s['connected_via'] !== 'connect' || $s['api_key'] === '') { $out['errors']['_'] = 'Connect RepeatGrow first'; return $out; }
        $samples = waTemplateSamples($conn, $rid);
        $slug = waTemplateSlug($conn, $rid);
        $save = $conn->prepare('INSERT INTO wa_auto (restaurant_id, kind) VALUES (?, ?) ON DUPLICATE KEY UPDATE kind = kind');
        try {
        foreach (waAutoConfig($conn, $rid) as $kind => $a) {
            if ($kinds !== null && !in_array($kind, $kinds, true)) continue;
            [$text, $vars] = waTemplateFromMessage($a['message']);
            if ($text === ($a['template_text'] ?? null) && in_array($a['template_status'] ?? null, ['approved', 'pending'], true)) { $out['unchanged']++; continue; }
            if ($text === ($a['pending_text'] ?? null)) { $out['unchanged']++; continue; }   // in review, or turned down: change the wording first
            $isUpdate = ($a['template_status'] ?? null) === 'approved' && !empty($a['template_name']);
            $version = $isUpdate ? (int)($a['template_version'] ?? 0) + 1 : (int)($a['template_version'] ?? 0);
            $name = $slug . '_' . $kind . '_gift' . ($version > 0 ? '_v' . ($version + 1) : '');
            $sampleVals = array_map(function ($k) use ($samples) { return (string)$samples[$k]; }, $vars);
            [$ok, $res, $http] = rgcCreateTemplate($s['api_key'], $name, $a['language'] ?: 'en', $text, $sampleVals);
            if (!$ok && $http === 409) {
                // RepeatGrow already has this name with other wording (e.g. after reconnecting): use the next version
                $version++;
                $name = $slug . '_' . $kind . '_gift_v' . ($version + 1);
                [$ok, $res, $http] = rgcCreateTemplate($s['api_key'], $name, $a['language'] ?: 'en', $text, $sampleVals);
            }
            if (!$ok) { $out['errors'][$kind] = $res; continue; }
            $save->execute([$rid, $kind]);
            $status = rgcStatus($res);
            if ($isUpdate) {
                $conn->prepare('UPDATE wa_auto SET pending_template = ?, pending_text = ?, pending_vars = ?, template_version = ? WHERE restaurant_id = ? AND kind = ?')
                     ->execute([$name, $text, json_encode($vars), $version, $rid, $kind]);
            } else {
                $conn->prepare('UPDATE wa_auto SET template_name = ?, template_status = ?, template_reason = NULL, template_text = ?, template_vars = ?, template_version = ? WHERE restaurant_id = ? AND kind = ?')
                     ->execute([$name, $status, $text, json_encode($vars), $version, $rid, $kind]);
            }
            $out['created']++;
        }
        } catch (RgcHalt $h) {
            return waTemplatesHalted($conn, $rid, $h, $out);
        }
        if ($kinds === null && $s['templates_retry']) $conn->prepare('UPDATE wa_settings SET templates_retry = 0 WHERE restaurant_id = ?')->execute([$rid]);
        return $out;
    }

    /** A template call was stopped by RepeatGrow: 401 clears the connection, 429 tries the rest on the next run. */
    function waTemplatesHalted(PDO $conn, string $rid, RgcHalt $h, array $out): array {
        $out['paused'] = $h->reason;
        if ($h->reason === 'unauthorized') {
            waConnectionLost($conn, $rid);
            $out['errors']['_'] = waLostMessage();
        } else {
            $conn->prepare('UPDATE wa_settings SET templates_retry = 1, templates_synced_at = NULL WHERE restaurant_id = ?')->execute([$rid]);
            $out['errors']['_rate'] = 'RepeatGrow is busy right now. The rest go for review by themselves in a few minutes.';
        }
        return $out;
    }

    /** Template for a campaign: reuse an approved one with the same wording, else create it. */
    function waCampaignTemplate(PDO $conn, string $rid, int $campaignId, string $message, string $language): array {
        $s = waSettings($conn, $rid);
        [$text, $vars] = waTemplateFromMessage($message);
        $same = $conn->prepare("SELECT template_name, template_status FROM wa_campaigns WHERE restaurant_id = ? AND message = ? AND template_status IN ('approved', 'pending') AND id <> ? ORDER BY template_status = 'approved' DESC, id DESC LIMIT 1");
        $same->execute([$rid, $message, $campaignId]);
        if ($prev = $same->fetch(PDO::FETCH_ASSOC)) {
            $conn->prepare('UPDATE wa_campaigns SET template_name = ?, template_status = ?, template_vars = ? WHERE id = ?')->execute([$prev['template_name'], $prev['template_status'], json_encode($vars), $campaignId]);
            return [true, $prev['template_status']];
        }
        $name = waTemplateSlug($conn, $rid) . '_offer_' . substr(md5($text), 0, 8);
        $samples = waTemplateSamples($conn, $rid);
        $sampleVals = array_map(function ($k) use ($samples) { return (string)$samples[$k]; }, $vars);
        try {
            [$ok, $res, $http] = rgcCreateTemplate($s['api_key'], $name, $language, $text, $sampleVals);
            if (!$ok && $http === 409) {   // that name exists in RepeatGrow with other wording: next version
                $name .= '_v2';
                [$ok, $res, $http] = rgcCreateTemplate($s['api_key'], $name, $language, $text, $sampleVals);
            }
        } catch (RgcHalt $h) {
            if ($h->reason === 'unauthorized') { waConnectionLost($conn, $rid); return [false, waLostMessage()]; }
            // rate limited: the campaign waits, and the next check creates its template
            $conn->prepare("UPDATE wa_campaigns SET template_name = ?, template_status = 'queued', template_vars = ? WHERE id = ?")->execute([$name, json_encode($vars), $campaignId]);
            $conn->prepare('UPDATE wa_settings SET templates_retry = 1, templates_synced_at = NULL WHERE restaurant_id = ?')->execute([$rid]);
            return [true, 'queued'];
        }
        if (!$ok) return [false, $res];
        $status = rgcStatus($res);
        $conn->prepare('UPDATE wa_campaigns SET template_name = ?, template_status = ?, template_vars = ? WHERE id = ?')->execute([$name, $status, json_encode($vars), $campaignId]);
        return [true, $status];
    }

    /**
     * Ask RepeatGrow how Meta's review is going, switch approved new versions in,
     * and send changed wordings (e.g. edited in Autopilot) for review by themselves.
     */
    function waTemplatesSync(PDO $conn, string $rid): array {
        $s = waSettings($conn, $rid);
        if ($s['connected_via'] !== 'connect' || $s['api_key'] === '') return [];
        $conn->prepare('UPDATE wa_settings SET templates_synced_at = NOW() WHERE restaurant_id = ?')->execute([$rid]);
        try {
            return waTemplatesSyncRun($conn, $rid, $s);
        } catch (RgcHalt $h) {
            if ($h->reason === 'unauthorized') { waConnectionLost($conn, $rid); return ['paused' => 'unauthorized', 'message' => waLostMessage()]; }
            // rate limited: check again on the next run instead of in 10 minutes
            $conn->prepare('UPDATE wa_settings SET templates_synced_at = NULL WHERE restaurant_id = ?')->execute([$rid]);
            return ['paused' => 'rate_limited'];
        }
    }

    function waTemplatesSyncRun(PDO $conn, string $rid, array $s): array {
        $auto = waAutoConfig($conn, $rid);
        $names = [];
        foreach ($auto as $a) { if (!empty($a['template_name']) && !empty($a['template_status'])) $names[] = $a['template_name']; if (!empty($a['pending_template'])) $names[] = $a['pending_template']; }
        $camps = $conn->prepare("SELECT id, template_name FROM wa_campaigns WHERE restaurant_id = ? AND template_status = 'pending'");
        $camps->execute([$rid]);
        $campRows = $camps->fetchAll(PDO::FETCH_ASSOC);
        foreach ($campRows as $c) $names[] = $c['template_name'];
        $st = $names ? rgcTemplateStatuses($s['api_key'], $names) : [];
        foreach ($auto as $kind => $a) {
            if (!empty($a['template_name']) && isset($st[$a['template_name']]) && !empty($a['template_status'])) {
                $conn->prepare('UPDATE wa_auto SET template_status = ?, template_reason = ? WHERE restaurant_id = ? AND kind = ?')
                     ->execute([rgcStatus($st[$a['template_name']][0]), $st[$a['template_name']][1] ?: null, $rid, $kind]);
            }
            if (!empty($a['pending_template']) && isset($st[$a['pending_template']])) {
                $ps = rgcStatus($st[$a['pending_template']][0]);
                if ($ps === 'approved') {
                    $conn->prepare("UPDATE wa_auto SET template_name = pending_template, template_text = pending_text, template_vars = pending_vars, template_status = 'approved', template_reason = NULL,
                        pending_template = NULL, pending_text = NULL, pending_vars = NULL WHERE restaurant_id = ? AND kind = ?")->execute([$rid, $kind]);
                } elseif ($ps === 'rejected') {
                    // keep the turned-down wording (pending_text) so it isn't sent again until the owner changes it
                    $conn->prepare('UPDATE wa_auto SET template_reason = ?, pending_template = NULL, pending_vars = NULL WHERE restaurant_id = ? AND kind = ?')
                         ->execute(['Meta turned down the new wording' . ($st[$a['pending_template']][1] ? ': ' . $st[$a['pending_template']][1] : '') . '. The approved one keeps sending.', $rid, $kind]);
                }
            }
        }
        foreach ($campRows as $c) {
            if (!isset($st[$c['template_name']])) continue;
            $cs = rgcStatus($st[$c['template_name']][0]);
            $conn->prepare('UPDATE wa_campaigns SET template_status = ?, template_reason = ?' . ($cs === 'rejected' ? ", status = 'rejected'" : '') . ' WHERE id = ?')
                 ->execute([$cs, $st[$c['template_name']][1] ?: null, (int)$c['id']]);
        }
        // campaigns whose template waited for a rate limit
        $waitCamps = $conn->prepare("SELECT id, message, language FROM wa_campaigns WHERE restaurant_id = ? AND template_status = 'queued' AND status = 'scheduled' ORDER BY id");
        $waitCamps->execute([$rid]);
        foreach ($waitCamps->fetchAll(PDO::FETCH_ASSOC) as $c) {
            [$ok, $res] = waCampaignTemplate($conn, $rid, (int)$c['id'], $c['message'], $c['language'] ?: 'en');
            if ($ok && $res === 'queued') return ['paused' => 'rate_limited'];
            if (!$ok) $conn->prepare("UPDATE wa_campaigns SET template_status = 'rejected', template_reason = ?, status = 'rejected' WHERE id = ?")->execute([mb_substr($res, 0, 255), (int)$c['id']]);
            if (!$ok && $res === waLostMessage()) return ['paused' => 'unauthorized', 'message' => $res];
        }
        // a template batch that hit the rate limit: send the rest now
        if ($s['templates_retry']) return waTemplatesCreate($conn, $rid);
        // wordings changed since their template was made (e.g. edited in Autopilot): send the new one for review
        $changed = [];
        foreach (waAutoConfig($conn, $rid) as $kind => $a) {
            if (empty($a['template_status']) || !$a['enabled']) continue;
            [$text] = waTemplateFromMessage($a['message']);
            if ($text !== $a['template_text'] && $text !== $a['pending_text']) $changed[] = $kind;
        }
        return $changed ? waTemplatesCreate($conn, $rid, $changed) : ['created' => 0];
    }

    /**
     * Queue new gifts, start due campaigns, and send up to $limit messages for
     * one restaurant. Safe to call often (a lock stops two runs overlapping).
     * Returns ['sent' => n, 'failed' => n].
     */
    function waDispatch(PDO $conn, string $rid, int $limit = 15): array {
        ensureWhatsappSchema($conn);
        $out = ['sent' => 0, 'failed' => 0];
        $s = waSettings($conn, $rid);
        if (!$s['enabled']) return $out;
        require_once __DIR__ . '/access_control.php';
        if (!accessPageOn($conn, $rid, 'whatsappPage')) return $out;   // WhatsApp switched off by Super Admin: gifts and campaigns wait
        $lock = $conn->query('SELECT GET_LOCK(' . $conn->quote('wa_' . $rid) . ', 0)')->fetchColumn();
        if (!$lock) return $out;
        try {
            $conn->prepare('UPDATE wa_settings SET last_dispatch = NOW() WHERE restaurant_id = ?')->execute([$rid]);
            if (waTemplatesSyncDue($s)) {
                try { $sync = waTemplatesSync($conn, $rid); } catch (Throwable $e) { $sync = []; error_log('waTemplatesSync: ' . $e->getMessage()); }
                if (($sync['paused'] ?? '') === 'unauthorized') { $out['paused'] = 'unauthorized'; return $out; }
            }
            waQueueGifts($conn, $rid, $s);
            if ($s['mode'] === 'own') waStartCampaigns($conn, $rid, $s);
            if (waInQuietHours($s)) return $out;
            $st = $conn->prepare("SELECT * FROM wa_outbox WHERE restaurant_id = ? AND status = 'queued' AND (send_after IS NULL OR send_after <= NOW()) ORDER BY id LIMIT " . (int)$limit);
            $st->execute([$rid]);
            $cap = $conn->prepare("SELECT COUNT(*) FROM wa_outbox WHERE restaurant_id = ? AND phone = ? AND kind = 'campaign' AND status = 'sent' AND sent_at >= NOW() - INTERVAL 7 DAY");
            $upd = $conn->prepare('UPDATE wa_outbox SET status = ?, error = ?, attempts = attempts + 1, sent_at = ?, message_id = ?, send_after = ? WHERE id = ?');
            $stopped = [];   // phones that replied STOP during this run: their other messages are already skipped
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
                if (isset($stopped[$m['phone']])) continue;
                $retry = false;
                if ($m['kind'] === 'campaign' && $s['weekly_cap'] > 0) {
                    $cap->execute([$rid, $m['phone']]);
                    if ((int)$cap->fetchColumn() >= $s['weekly_cap']) { $upd->execute(['skipped', 'Weekly limit for this customer', null, null, null, $m['id']]); continue; }
                }
                [$ok, $mid, $err, $why] = waSend($s, $m['phone'], $m['template_name'] ?: null, $m['language'] ?: 'en', json_decode((string)$m['params'], true) ?: [], (string)$m['body']) + [3 => null];
                if (!$ok && $why === 'rate_limited') {
                    // RepeatGrow's limit (120 a minute): this one and the rest wait for the next run, not failed
                    $conn->prepare('UPDATE wa_outbox SET error = ? WHERE id = ?')->execute(['Waiting: RepeatGrow asked us to slow down. Sends on the next run.', $m['id']]);
                    $out['paused'] = 'rate_limited';
                    break;
                }
                if (!$ok && $why === 'unauthorized' && $s['mode'] === 'own') {
                    // the owner disconnected RestroGrow in RepeatGrow: stays queued until they connect again
                    waConnectionLost($conn, $rid);
                    $out['paused'] = 'unauthorized';
                    break;
                }
                if (!$ok && $why === 'opted_out') { waOptedOut($conn, $rid, $m); $stopped[$m['phone']] = true; $out['opted_out'] = ($out['opted_out'] ?? 0) + 1; continue; }
                if ($ok) {
                    $upd->execute(['sent', null, date('Y-m-d H:i:s'), $mid, null, $m['id']]);
                    $out['sent']++;
                } else {
                    $retry = (int)$m['attempts'] + 1 < 3 && $why === 'network';   // network trouble: try again later
                    $upd->execute([$retry ? 'queued' : 'failed', $err, null, null, $retry ? date('Y-m-d H:i:s', time() + 600) : null, $m['id']]);
                    if (!$retry) $out['failed']++;
                }
                if ($m['ref_type'] === 'gift' && !$retry) {
                    $conn->prepare('UPDATE loyalty_rewards_issued SET notify_status = ? WHERE id = ?')->execute([$ok ? 'sent' : 'failed', (int)$m['ref_id']]);
                }
            }
            // finished campaigns
            $conn->prepare("UPDATE wa_campaigns c SET status = 'done' WHERE restaurant_id = ? AND status = 'sending'
                AND NOT EXISTS (SELECT 1 FROM wa_outbox o WHERE o.restaurant_id = c.restaurant_id AND o.ref_type = 'campaign' AND o.ref_id = c.id AND o.status = 'queued')")->execute([$rid]);
        } finally {
            $conn->query('SELECT RELEASE_LOCK(' . $conn->quote('wa_' . $rid) . ')');
        }
        return $out;
    }
}
