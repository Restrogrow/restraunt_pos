<?php
/**
 * Shiprocket Quick: a local rider for a delivery order, booked through Shiprocket's hyperlocal API
 * (apidocs.shiprocket.in › Hyperlocal). One RestroGrow Shiprocket account pays for every ride from
 * its wallet, so Super Admin switches it on per restaurant (superadmin/quick_delivery.php).
 *
 *   1. log in        POST /v1/external/auth/login (email + password from .env) → token, kept 9 days
 *   2. pickup        POST /settings/company/addpickup, once per restaurant (its address and map pin)
 *   3. order         POST /orders/create/adhoc → Shiprocket order_id + shipment_id
 *   4. rate          GET  /courier/serviceability/ ?is_new_hyperlocal=1 with both map pins → "Shiprocket Quick"
 *   5. rider         POST /courier/assign/awb {shipment_id, courier_id}
 *   6. status        GET  /courier/track/shipment/{id} (cron + order screen) and the webhook
 *                    (api/quick_delivery_hook.php); cancel POST /orders/cancel {ids}
 *
 * The ride is saved in qd_bookings (one row per try) and in delivery_tracking (partner 'shiprocket',
 * config/rider_engine.php), so the order screens and the customer's tracking page show it; the
 * rider's Picked up / On the way / Delivered move the order like one of the restaurant's own riders.
 *
 * .env: SHIPROCKET_EMAIL, SHIPROCKET_PASSWORD (the API user, Shiprocket › Settings › API; or typed into
 * Super Admin › Shiprocket Quick, saved sealed with WA_KEY_SECRET; .env wins when both are there),
 * QUICK_DELIVERY_WEBHOOK_TOKEN (optional, the webhook's security token), GEOAPIFY_API_KEY (the
 * drop's pincode and state from its map pin). Never for the demo restaurant, only in India.
 */
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/rider_engine.php';

// SHIPROCKET_API_URL: only to test against a stand-in on a developer's PC
define('QD_API', rtrim((string)(env('SHIPROCKET_API_URL', '') ?: 'https://apiv2.shiprocket.in/v1/external'), '/'));
const QD_PARTNER = 'shiprocket';
const QD_NAME = 'Shiprocket Quick';
// when the rider is booked by itself: never, when the order is accepted, or when the food is ready
const QD_AUTO_MODES = ['off' => 'No, I book each rider', 'accept' => 'When I accept the order', 'ready' => 'When the food is ready'];
// a booking that is still on (not finished or cancelled)
const QD_LIVE = ['booked', 'picked_up', 'on_the_way'];

/** Are the Shiprocket login details set (.env, or saved in Super Admin)? */
function qdConfigured(): bool
{
    return qdCreds()['email'] !== '' && qdCreds()['password'] !== '';
}

/** The API user: .env when both are there, else the one saved in Super Admin › Shiprocket Quick (sa_settings 'qd_login', password sealed). */
function qdCreds(bool $reload = false): array
{
    static $c = null;
    if ($c !== null && !$reload) return $c;
    $email = trim((string)env('SHIPROCKET_EMAIL', '')); $pass = (string)env('SHIPROCKET_PASSWORD', '');
    if ($email !== '' && trim($pass) !== '') return $c = ['email' => $email, 'password' => $pass, 'from' => 'env'];
    $c = ['email' => '', 'password' => '', 'from' => ''];
    try {
        if (function_exists('getConnection')) {
            $saved = json_decode((string)getConnection()->query("SELECT v FROM sa_settings WHERE k = 'qd_login'")->fetchColumn(), true);
            if (is_array($saved) && !empty($saved['email'])) $c = ['email' => (string)$saved['email'], 'password' => qdOpen((string)($saved['password'] ?? '')), 'from' => 'saved'];
        }
    } catch (Throwable $e) { /* no sa_settings yet */ }
    return $c;
}

/** Save (or with '' clear) the API user typed into Super Admin; the old token goes too. */
function qdSaveCreds(PDO $conn, string $email, string $password): void
{
    qdEnsure($conn);
    if ($email === '') $conn->exec("DELETE FROM sa_settings WHERE k = 'qd_login'");
    else $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES ('qd_login', ?)")->execute([json_encode(['email' => $email, 'password' => qdSeal($password)])]);
    $conn->exec("DELETE FROM sa_settings WHERE k = 'qd_token'");
    qdCreds(true);
}

/** Password at rest: AES-256-GCM with WA_KEY_SECRET (same as config/gbp_helpers.php); plain when that key is missing. */
function qdKey(): ?string
{
    $k = trim((string)env('WA_KEY_SECRET', ''));
    if ($k === '') return null;
    $raw = base64_decode($k, true);
    return ($raw !== false && strlen($raw) >= 32) ? substr($raw, 0, 32) : hash('sha256', $k, true);
}
function qdSeal(string $plain): string
{
    $k = qdKey();
    if ($plain === '' || $k === null) return $plain;
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return $ct === false ? $plain : 'enc:v1:' . base64_encode($iv . $tag . $ct);
}
function qdOpen(string $stored): string
{
    if (strpos($stored, 'enc:v1:') !== 0) return $stored;
    $k = qdKey(); $raw = base64_decode(substr($stored, 7), true);
    $p = ($k !== null && $raw !== false && strlen($raw) > 28) ? openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)) : false;
    return $p === false ? '' : $p;
}

/** Tables (safe to call often; not inside a transaction). */
function qdEnsure(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    riderEnsure($conn);
    $conn->exec("CREATE TABLE IF NOT EXISTS sa_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->exec("CREATE TABLE IF NOT EXISTS qd_restaurants (
        restaurant_id VARCHAR(10) PRIMARY KEY,
        enabled TINYINT NOT NULL DEFAULT 0,
        auto_mode VARCHAR(10) NOT NULL DEFAULT 'accept',
        pickup_name VARCHAR(36) NULL,
        pickup_sig VARCHAR(64) NULL,
        pickup_pincode VARCHAR(10) NULL,
        pickup_state VARCHAR(60) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->exec("CREATE TABLE IF NOT EXISTS qd_bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(10) NOT NULL,
        order_id INT NOT NULL,
        sr_order_id BIGINT NULL,
        shipment_id BIGINT NULL,
        awb VARCHAR(40) NULL,
        courier_id INT NULL,
        rate DECIMAL(10,2) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'booking',
        sr_status VARCHAR(80) NULL,
        rider_name VARCHAR(100) NULL,
        rider_phone VARCHAR(20) NULL,
        track_url VARCHAR(500) NULL,
        error VARCHAR(255) NULL,
        auto TINYINT NOT NULL DEFAULT 0,
        booked_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_order (order_id), INDEX idx_rest (restaurant_id, created_at), INDEX idx_status (status), INDEX idx_awb (awb)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** The restaurant's switch (Super Admin) and when it books by itself (the owner). */
function qdSettings(PDO $conn, string $rid): array
{
    qdEnsure($conn);
    $st = $conn->prepare('SELECT * FROM qd_restaurants WHERE restaurant_id = ?');
    $st->execute([$rid]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $mode = (string)($r['auto_mode'] ?? 'accept');
    return [
        'enabled' => !empty($r['enabled']),
        'auto_mode' => isset(QD_AUTO_MODES[$mode]) ? $mode : 'accept',
        'pickup_name' => (string)($r['pickup_name'] ?? ''),
        'pickup_sig' => (string)($r['pickup_sig'] ?? ''),
        'pickup_pincode' => (string)($r['pickup_pincode'] ?? ''),
        'pickup_state' => (string)($r['pickup_state'] ?? ''),
    ];
}

function qdSaveSettings(PDO $conn, string $rid, array $set): void
{
    qdEnsure($conn);
    $cols = array_intersect_key($set, array_flip(['enabled', 'auto_mode', 'pickup_name', 'pickup_sig', 'pickup_pincode', 'pickup_state']));
    if (!$cols) return;
    $names = array_keys($cols);
    $sql = 'INSERT INTO qd_restaurants (restaurant_id, ' . implode(', ', $names) . ') VALUES (?' . str_repeat(', ?', count($names)) . ')
            ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(function ($c) { return "$c = VALUES($c)"; }, $names));
    $conn->prepare($sql)->execute(array_merge([$rid], array_values($cols)));
}

// ── Shiprocket calls ──

/** One HTTP call. Returns [http code, decoded body (array), raw body]. */
function qdHttp(string $method, string $url, ?array $body = null, string $token = ''): array
{
    $c = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    $ca = trim((string)env('AI_CAINFO', ''));
    if ($ca !== '' && is_file($ca)) $opts[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($c, $opts);
    $raw = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    $err = curl_error($c);
    curl_close($c);
    if ($raw === false) return [0, ['message' => 'Could not reach Shiprocket: ' . $err], ''];
    $data = json_decode((string)$raw, true);
    return [$code, is_array($data) ? $data : [], (string)$raw];
}

/** The API token: kept in sa_settings for 9 days (Shiprocket gives 10). */
function qdToken(PDO $conn, bool $fresh = false): string
{
    qdEnsure($conn);
    if (!$fresh) {
        $saved = json_decode((string)$conn->query("SELECT v FROM sa_settings WHERE k = 'qd_token'")->fetchColumn(), true);
        if (is_array($saved) && !empty($saved['token']) && (int)($saved['exp'] ?? 0) > time()) return (string)$saved['token'];
    }
    if (!qdConfigured()) throw new RuntimeException('Shiprocket is not set up yet: add the API user in Super Admin › Shiprocket Quick (or SHIPROCKET_EMAIL / SHIPROCKET_PASSWORD in .env).');
    $cr = qdCreds();
    [$code, $d] = qdHttp('POST', QD_API . '/auth/login', ['email' => $cr['email'], 'password' => $cr['password']]);
    if ($code !== 200 || empty($d['token'])) {
        throw new RuntimeException('Shiprocket login failed' . (!empty($d['message']) ? ': ' . $d['message'] : ' (HTTP ' . $code . ')') . '. Check the API user ' . ($cr['from'] === 'env' ? 'in .env.' : 'saved in Super Admin.'));
    }
    $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES ('qd_token', ?)")->execute([json_encode(['token' => $d['token'], 'exp' => time() + 9 * 86400])]);
    return (string)$d['token'];
}

/** A logged-in call; logs in again once if the token was refused. Throws with Shiprocket's message. */
function qdApi(PDO $conn, string $method, string $path, ?array $body = null): array
{
    [$code, $d, $raw] = qdHttp($method, QD_API . $path, $body, qdToken($conn));
    if ($code === 401) [$code, $d, $raw] = qdHttp($method, QD_API . $path, $body, qdToken($conn, true));
    if ($code < 200 || $code >= 300) {
        $msg = $d['message'] ?? '';
        // Shiprocket sometimes sends {"address":["…"]} as the message: keep only the words
        if (is_string($msg) && is_array($j = json_decode($msg, true))) $msg = $j;
        if (is_array($msg)) { $flat = []; array_walk_recursive($msg, function ($v) use (&$flat) { $flat[] = (string)$v; }); $msg = implode(' ', $flat); }
        $msg = (string)$msg;
        if (!empty($d['errors']) && is_array($d['errors'])) {
            $first = array_values($d['errors'])[0];
            $msg .= ' ' . (is_array($first) ? implode(' ', $first) : (string)$first);
        }
        error_log("quick_delivery $method $path → HTTP $code: " . mb_substr($raw, 0, 500));
        throw new RuntimeException(trim($msg) !== '' ? trim($msg) : 'Shiprocket answered HTTP ' . $code);
    }
    return $d;
}

// ── addresses ──

/** Pincode, state and city of a map pin: Geoapify (GEOAPIFY_API_KEY), else OpenStreetMap; [] when unknown. */
function qdReverse(float $lat, float $lng): array
{
    static $cache = [];
    $key = round($lat, 5) . ',' . round($lng, 5);
    if (isset($cache[$key])) return $cache[$key];
    if (!$lat || !$lng) return $cache[$key] = [];
    $get = function (string $url) {
        $c = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'RestroGrow/1.0 (restrogrow.com)'];
        $ca = trim((string)env('AI_CAINFO', ''));
        if ($ca !== '' && is_file($ca)) $opts[CURLOPT_CAINFO] = $ca;
        curl_setopt_array($c, $opts);
        $raw = curl_exec($c);
        curl_close($c);
        return json_decode((string)$raw, true) ?: [];
    };
    $pin = function ($v) { $v = str_replace(' ', '', (string)$v); return preg_match('/^\d{6}$/', $v) ? $v : ''; };
    $api = trim((string)env('GEOAPIFY_API_KEY', ''));
    if ($api !== '') {
        $r = $get('https://api.geoapify.com/v1/geocode/reverse?' . http_build_query(['lat' => $lat, 'lon' => $lng, 'format' => 'json', 'apiKey' => $api]))['results'][0] ?? [];
        if ($pin($r['postcode'] ?? '') !== '') {
            return $cache[$key] = ['pincode' => $pin($r['postcode']), 'state' => (string)($r['state'] ?? ''), 'city' => (string)($r['city'] ?? ($r['county'] ?? ''))];
        }
    }
    // OpenStreetMap's Nominatim (free; a few calls a minute is well inside its limit)
    $a = $get('https://nominatim.openstreetmap.org/reverse?' . http_build_query(['lat' => $lat, 'lon' => $lng, 'format' => 'jsonv2', 'zoom' => 18, 'addressdetails' => 1]))['address'] ?? [];
    return $cache[$key] = [
        'pincode' => $pin($a['postcode'] ?? ''),
        'state' => (string)($a['state'] ?? ''),
        'city' => (string)($a['city'] ?? ($a['town'] ?? ($a['county'] ?? ($a['state_district'] ?? '')))),
    ];
}

/**
 * Address line 1 / line 2 to try, in order, for Shiprocket ("Address line 1 should have House no /
 * Flat no / Road no"): the address as written (pincode, state and "India" taken off line 1, since they
 * have their own fields), then with its own first number in front ("No. 12, …"), then, only when it
 * has no number at all, "<$kind> No. 1, …". The rider finds the place by the map pin and the phone.
 */
function qdAddressLines(string $address, string $kind, int $max): array
{
    $a = preg_replace('/(?<!\d)[1-9]\d{2}\s?\d{3}(?!\d)/', '', $address);   // the pincode
    $a = preg_replace('/,?\s*\bIndia\b\.?\s*$/i', '', (string)$a);
    $a = trim(preg_replace(['/\s*,\s*(,\s*)+/', '/\s{2,}/'], [', ', ' '], (string)$a), " ,-");
    if ($a === '') $a = trim($address);
    $split = function (string $line1) use ($max) {
        if (mb_strlen($line1) <= $max) return [$line1, ''];
        $cut = mb_strrpos(mb_substr($line1, 0, $max), ',') ?: $max;   // break at a comma when possible
        return [trim(mb_substr($line1, 0, $cut), ' ,'), trim(mb_substr(mb_substr($line1, $cut), 0, 100), ' ,')];
    };
    $out = [$split($a)];
    if (preg_match('/(?<![\w-])[A-Za-z]{0,3}-?\d+[A-Za-z]?(?:[\/-]\d+[A-Za-z]?)?(?![\w-])/', $a, $m)) {
        $no = $m[0];
        // "B-12, Barakhamba Road" → "No. B-12, Barakhamba Road"; "Near X, Shop 4B, Y" → "No. 4B, Near X, Shop 4B, Y"
        if (stripos($a, $no) === 0) $out[] = $split('No. ' . $a);
        elseif (!preg_match('/^\s*(no\.?|#)\s*/i', $a)) $out[] = $split('No. ' . $no . ', ' . $a);
    } else {
        $out[] = $split($kind . ' No. 1, ' . $a);
    }
    return $out;
}

/** Did Shiprocket refuse the address for having no house / flat / road number? */
function qdAddressRefused(Throwable $e): bool
{
    return (bool)preg_match('/house\s*no|flat\s*no|road\s*no/i', $e->getMessage());
}

/** A 6-digit Indian pincode written in an address, or ''. */
function qdPincodeIn(string $text): string
{
    return preg_match('/(?<!\d)([1-9]\d{2}\s?\d{3})(?!\d)/', $text, $m) ? str_replace(' ', '', $m[1]) : '';
}

/** Last 10 digits of an Indian phone number. */
function qdPhone(string $phone): string
{
    $d = preg_replace('/\D/', '', $phone);
    return strlen($d) >= 10 ? substr($d, -10) : $d;
}

function qdRestaurant(PDO $conn, string $rid): array
{
    $st = $conn->prepare('SELECT restaurant_id, restaurant_name, email, phone, address, city, country, restaurant_lat, restaurant_lng FROM users WHERE restaurant_id = ? LIMIT 1');
    $st->execute([$rid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * The restaurant's pickup address in Shiprocket, added the first time (and again when the address or
 * map pin changes). Returns [pickup_location name, pincode].
 */
function qdPickup(PDO $conn, string $rid): array
{
    $r = qdRestaurant($conn, $rid);
    $lat = (float)($r['restaurant_lat'] ?? 0);
    $lng = (float)($r['restaurant_lng'] ?? 0);
    if (!$lat || !$lng) throw new InvalidArgumentException('Set the restaurant\'s map pin first (Settings › Location): the rider needs it to find you.');
    if (trim((string)$r['address']) === '') throw new InvalidArgumentException('Add the restaurant\'s address first (Settings › Location).');
    $s = qdSettings($conn, $rid);
    $sig = hash('sha256', implode('|', [$r['restaurant_name'], $r['address'], $r['city'], $r['phone'], round($lat, 5), round($lng, 5)]));
    if ($s['pickup_name'] !== '' && $s['pickup_sig'] === $sig && $s['pickup_pincode'] !== '') return [$s['pickup_name'], $s['pickup_pincode']];

    $geo = qdReverse($lat, $lng);
    $pin = qdPincodeIn((string)$r['address']) ?: ($geo['pincode'] ?? '');
    if ($pin === '') throw new InvalidArgumentException('Could not find the restaurant\'s pincode. Add it to the address in Settings › Location.');
    $name = 'RG-' . $rid . '-' . substr($sig, 0, 6);   // Shiprocket's nickname: unique, max 36 characters
    $body = [
        'pickup_location' => $name,
        'name' => mb_substr((string)$r['restaurant_name'], 0, 60),
        'email' => (string)($r['email'] ?: qdCreds()['email']),
        'phone' => qdPhone((string)$r['phone']),
        'address' => '',   // set below from qdAddressLines()
        'address_2' => '',
        'city' => (string)($r['city'] ?: ($geo['city'] ?? '')),
        'state' => (string)($geo['state'] ?? ''),
        'country' => 'India',
        'pin_code' => $pin,
        'lat' => $lat,
        'long' => $lng,
    ];
    if (strlen($body['phone']) !== 10) throw new InvalidArgumentException('Add the restaurant\'s 10-digit phone number first (Settings).');
    // Shiprocket wants a house / shop number in address line 1: try the address as written, then
    // with a number in front (qdAddressLines), until it takes one
    $lines = qdAddressLines((string)$r['address'], 'Shop', 80);
    foreach ($lines as $i => [$line1, $line2]) {
        $body['address'] = $line1;
        $body['address_2'] = $line2;
        try {
            qdApi($conn, 'POST', '/settings/company/addpickup', $body);
            if ($i > 0) error_log("quick_delivery pickup $rid: Shiprocket wanted a shop number, sent \"$line1\"");
            break;
        } catch (RuntimeException $e) {
            if (preg_match('/already|exist/i', $e->getMessage())) break;   // already there (a second server, or saved before a failure): use it
            if (qdAddressRefused($e) && $i < count($lines) - 1) continue;
            throw new RuntimeException('Could not add the pickup address: ' . $e->getMessage() . (qdAddressRefused($e) ? ' Add your shop or building number to the address in Settings › Location.' : ''));
        }
    }
    qdSaveSettings($conn, $rid, ['pickup_name' => $name, 'pickup_sig' => $sig, 'pickup_pincode' => $pin, 'pickup_state' => (string)($geo['state'] ?? '')]);
    return [$name, $pin];
}

// ── orders ──

function qdOrder(PDO $conn, string $rid, int $orderId): array
{
    $st = $conn->prepare('SELECT * FROM orders WHERE id = ? AND restaurant_id = ? AND deleted_at IS NULL');
    $st->execute([$orderId, $rid]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) throw new InvalidArgumentException('Order not found');
    return $o;
}

/** What the rider collects in cash (0 when paid online). */
function qdCashDue(array $o): float
{
    return ($o['payment_status'] ?? '') === 'Paid' ? 0.0 : max(0.0, round((float)$o['total'] - (float)($o['wallet_paid'] ?? 0), 2));
}

/** The order's latest booking, or null. */
function qdBooking(PDO $conn, int $orderId): ?array
{
    qdEnsure($conn);
    $st = $conn->prepare('SELECT * FROM qd_bookings WHERE order_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$orderId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Why this order can't get a Shiprocket rider now, or '' when it can. */
function qdBlocker(PDO $conn, string $rid, array $o): string
{
    require_once __DIR__ . '/demo_restaurant.php';
    require_once __DIR__ . '/countries.php';
    if ($rid === DEMO_RESTAURANT_ID) return 'The demo restaurant never books real riders.';
    if (!qdConfigured()) return 'Shiprocket isn\'t set up on this server yet.';
    if (!qdSettings($conn, $rid)['enabled']) return 'Shiprocket Quick isn\'t switched on for this restaurant. Ask RestroGrow support.';
    $r = qdRestaurant($conn, $rid);
    if (!restaurantIsIndia((string)($r['country'] ?? ''))) return 'Shiprocket Quick works only in India.';
    if (($o['order_type'] ?? '') !== 'Delivery') return 'Only delivery orders get a rider.';
    if (in_array($o['order_status'], ['Cancelled', 'Rejected', 'Completed', 'Served'], true)) return 'This order is ' . strtolower($o['order_status']) . '.';
    if (!(float)($o['address_lat'] ?? 0) || !(float)($o['address_lng'] ?? 0)) return 'This order has no map pin for the drop, so a rider can\'t be booked. Use a delivery app below.';
    $t = $conn->prepare('SELECT partner, rider_staff_id, delivery_status FROM delivery_tracking WHERE order_id = ? LIMIT 1');
    $t->execute([(int)$o['id']]);
    $tr = $t->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($tr['rider_staff_id'])) return 'One of your own riders has this order.';
    if (!empty($tr['partner']) && $tr['partner'] !== QD_PARTNER) return 'This order is already with another delivery app.';
    if (($tr['delivery_status'] ?? '') === 'Delivered') return 'This order is already delivered.';
    $b = qdBooking($conn, (int)$o['id']);
    if ($b && in_array($b['status'], QD_LIVE, true)) return 'A Shiprocket rider is already booked.';
    if ($b && $b['status'] === 'booking' && strtotime($b['created_at']) > time() - 120) return 'Booking is in progress.';
    return '';
}

/** The serviceability query for both map pins; returns the Shiprocket Quick option or throws. */
function qdServiceability(PDO $conn, array $q): array
{
    try {
        $d = qdApi($conn, 'GET', '/courier/serviceability/?' . http_build_query($q));
    } catch (RuntimeException $e) {
        $GLOBALS['qd_last_service'] = ['query' => $q, 'error' => $e->getMessage()];
        throw $e;
    }
    // Super Admin › Test price shows this, so a "no rider" can be checked against what Shiprocket said
    $GLOBALS['qd_last_service'] = ['query' => $q, 'answer' => $d];
    $list = $d['data']['available_courier_companies'] ?? ($d['data'] ?? []);
    if (!is_array($list) || !$list) {
        // Shiprocket's own reason, when it gives one
        $why = trim((string)(is_string($d['message'] ?? null) ? $d['message'] : ''));
        throw new RuntimeException('No Shiprocket Quick rider serves this address right now' . ($why !== '' ? ' (Shiprocket: ' . $why . ')' : '') . '.');
    }
    $pick = null;
    foreach ($list as $c) {
        if (!is_array($c)) continue;
        if (stripos((string)($c['courier_name'] ?? ''), 'quick') !== false) { $pick = $c; break; }
        if ($pick === null) $pick = $c;
    }
    if (!$pick) throw new RuntimeException('No Shiprocket Quick rider serves this address right now.');
    return [
        'courier_id' => (int)($pick['courier_company_id'] ?? ($pick['courier_id'] ?? ($pick['id'] ?? 0))),
        'courier_name' => (string)($pick['courier_name'] ?? QD_NAME),
        'rate' => round((float)($pick['rate'] ?? ($pick['rates'] ?? ($pick['freight_charge'] ?? 0))), 2),
        'eta' => (string)($pick['etd'] ?? ($pick['estimated_delivery_days'] ?? '')),
    ];
}

/** Price of a rider for this order (nothing is booked). */
function qdQuote(PDO $conn, string $rid, int $orderId): array
{
    $o = qdOrder($conn, $rid, $orderId);
    $why = qdBlocker($conn, $rid, $o);
    if ($why !== '' && !preg_match('/already booked|in progress/', $why)) throw new InvalidArgumentException($why);
    [, $pickupPin] = qdPickup($conn, $rid);
    $r = qdRestaurant($conn, $rid);
    $dropPin = qdPincodeIn((string)$o['customer_address']) ?: (qdReverse((float)$o['address_lat'], (float)$o['address_lng'])['pincode'] ?? '');
    if ($dropPin === '') throw new InvalidArgumentException('Could not find the drop\'s pincode from its map pin.');
    return qdServiceability($conn, [
        'pickup_postcode' => $pickupPin, 'delivery_postcode' => $dropPin, 'cod' => qdCashDue($o) > 0 ? 1 : 0, 'weight' => 1,
        'is_new_hyperlocal' => 1, 'lat_from' => (float)$r['restaurant_lat'], 'long_from' => (float)$r['restaurant_lng'],
        'lat_to' => (float)$o['address_lat'], 'long_to' => (float)$o['address_lng'],
    ]);
}

/**
 * Book a Shiprocket Quick rider for a delivery order. $auto: booked by itself (accept / ready).
 * Returns the booking row. Throws InvalidArgumentException (nothing to do) or RuntimeException (Shiprocket said no).
 */
function qdBook(PDO $conn, string $rid, int $orderId, string $by = '', bool $auto = false): array
{
    qdEnsure($conn);
    $o = qdOrder($conn, $rid, $orderId);
    $why = qdBlocker($conn, $rid, $o);
    if ($why !== '') throw new InvalidArgumentException($why);

    // one booking at a time per order: the row is the lock
    $tries = (int)$conn->query('SELECT COUNT(*) FROM qd_bookings WHERE order_id = ' . (int)$orderId)->fetchColumn();
    $conn->prepare('INSERT INTO qd_bookings (restaurant_id, order_id, status, auto, booked_by) VALUES (?, ?, ?, ?, ?)')
        ->execute([$rid, $orderId, 'booking', $auto ? 1 : 0, mb_substr($by, 0, 100)]);
    $bid = (int)$conn->lastInsertId();
    $fail = function (string $msg) use ($conn, $bid) {
        $conn->prepare("UPDATE qd_bookings SET status = 'failed', error = ? WHERE id = ?")->execute([mb_substr($msg, 0, 255), $bid]);
    };

    try {
        [$pickupName, $pickupPin] = qdPickup($conn, $rid);
        $r = qdRestaurant($conn, $rid);
        $geo = qdReverse((float)$o['address_lat'], (float)$o['address_lng']);
        $dropPin = qdPincodeIn((string)$o['customer_address']) ?: ($geo['pincode'] ?? '');
        if ($dropPin === '') throw new InvalidArgumentException('Could not find the drop\'s pincode from its map pin.');
        $cash = qdCashDue($o);

        $it = $conn->prepare('SELECT item_name, quantity, unit_price, total_price FROM order_items WHERE order_id = ?');
        $it->execute([$orderId]);
        $items = [];
        foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $i => $row) {
            $items[] = ['name' => mb_substr((string)$row['item_name'], 0, 100), 'sku' => 'RG' . $orderId . '-' . ($i + 1),
                'units' => max(1, (int)$row['quantity']), 'selling_price' => round((float)$row['unit_price'], 2), 'discount' => 0, 'tax' => 0, 'hsn' => ''];
        }
        if (!$items) $items[] = ['name' => 'Food order ' . $o['order_number'], 'sku' => 'RG' . $orderId, 'units' => 1, 'selling_price' => (float)$o['total'], 'discount' => 0, 'tax' => 0, 'hsn' => ''];

        $name = trim((string)$o['customer_name']) ?: 'Customer';
        $order = [
            // a cancelled Shiprocket order id can't be reused, so each try gets its own
            'order_id' => $o['order_number'] . ($tries ? '-' . ($tries + 1) : ''),
            'order_date' => date('Y-m-d H:i', strtotime((string)$o['created_at'])),
            'pickup_location' => $pickupName,
            'comment' => 'RestroGrow ' . $o['order_number'] . ($o['landmark'] ? ' · Landmark: ' . $o['landmark'] : ''),
            'billing_customer_name' => mb_substr($name, 0, 50),
            'billing_last_name' => '',
            'billing_address' => '',   // set below from qdAddressLines()
            'billing_address_2' => '',
            'billing_city' => (string)(($geo['city'] ?? '') ?: $r['city']),
            'billing_pincode' => $dropPin,
            'billing_state' => (string)(($geo['state'] ?? '') ?: qdSettings($conn, $rid)['pickup_state']),
            'billing_country' => 'India',
            'billing_email' => (string)($o['customer_email'] ?: ($r['email'] ?: qdCreds()['email'])),
            'billing_phone' => qdPhone((string)$o['customer_phone']),
            'shipping_is_billing' => true,
            'order_items' => $items,
            'payment_method' => $cash > 0 ? 'COD' : 'Prepaid',
            'shipping_charges' => 0, 'giftwrap_charges' => 0, 'transaction_charges' => 0, 'total_discount' => 0,
            'sub_total' => $cash > 0 ? $cash : round((float)$o['total'], 2),
            'length' => 20, 'breadth' => 20, 'height' => 10, 'weight' => 1,
        ];
        // the customer's address: as typed, then with a number in front if Shiprocket wants one
        $landmark = trim((string)($o['landmark'] ?? ''));
        $lines = qdAddressLines((string)$o['customer_address'], 'House', 150);
        foreach ($lines as $i => [$line1, $line2]) {
            $order['billing_address'] = $line1;
            $order['billing_address_2'] = mb_substr(trim($line2 . ($landmark !== '' ? ($line2 !== '' ? ', ' : '') . 'Landmark: ' . $landmark : '')), 0, 100);
            try {
                $created = qdApi($conn, 'POST', '/orders/create/adhoc', $order);
                break;
            } catch (RuntimeException $e) {
                if (!qdAddressRefused($e) || $i === count($lines) - 1) throw $e;
            }
        }
        $srOrder = (int)($created['order_id'] ?? 0);
        $shipment = (int)($created['shipment_id'] ?? 0);
        if (!$shipment) throw new RuntimeException((string)($created['message'] ?? 'Shiprocket did not create the order.'));
        $conn->prepare('UPDATE qd_bookings SET sr_order_id = ?, shipment_id = ? WHERE id = ?')->execute([$srOrder, $shipment, $bid]);

        $quote = qdServiceability($conn, [
            'pickup_postcode' => $pickupPin, 'delivery_postcode' => $dropPin, 'cod' => $cash > 0 ? 1 : 0, 'weight' => 1,
            'is_new_hyperlocal' => 1, 'lat_from' => (float)$r['restaurant_lat'], 'long_from' => (float)$r['restaurant_lng'],
            'lat_to' => (float)$o['address_lat'], 'long_to' => (float)$o['address_lng'],
        ]);
        $assign = ['shipment_id' => $shipment];
        if ($quote['courier_id']) $assign['courier_id'] = $quote['courier_id'];
        try {
            $a = qdApi($conn, 'POST', '/courier/assign/awb', $assign);
        } catch (Throwable $e) {
            // no rider: don't leave an open order in Shiprocket
            try { qdApi($conn, 'POST', '/orders/cancel', ['ids' => [$srOrder]]); } catch (Throwable $e2) { error_log('quick_delivery cancel after failed assign: ' . $e2->getMessage()); }
            throw $e;
        }
        $awb = (string)($a['response']['data']['awb_code'] ?? ($a['awb_code'] ?? ''));
        $courier = (string)($a['response']['data']['courier_name'] ?? $quote['courier_name']);
        $track = $awb !== '' ? 'https://shiprocket.co/tracking/' . rawurlencode($awb) : '';
        $conn->prepare("UPDATE qd_bookings SET status = 'booked', awb = ?, courier_id = ?, rate = ?, sr_status = 'Rider being assigned', track_url = ?, error = NULL WHERE id = ?")
            ->execute([$awb !== '' ? $awb : null, $quote['courier_id'] ?: null, $quote['rate'] ?: null, $track ?: null, $bid]);

        // the order screens and the customer's tracking page
        $conn->beginTransaction();
        try {
            $t = riderTrackingRow($conn, $rid, $orderId);
            $status = in_array($t['delivery_status'], ['Pending', 'Preparing'], true) ? 'Assigned' : $t['delivery_status'];
            $conn->prepare('UPDATE delivery_tracking SET partner = ?, partner_track_url = ?, rider_name = ?, rider_phone = NULL, delivery_status = ?, assigned_at = COALESCE(assigned_at, NOW()) WHERE id = ?')
                ->execute([QD_PARTNER, $track ?: null, QD_NAME . ' rider', $status, $t['id']]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            error_log('quick_delivery tracking row: ' . $e->getMessage());
        }
        qdLog($conn, $rid, 'delivery.quick', $o['order_number'] . ' — ' . QD_NAME . ' rider booked' . ($quote['rate'] ? ' (₹' . number_format($quote['rate'], 2) . ')' : '') . ($auto ? ' automatically' : ''), $by);
    } catch (Throwable $e) {
        // the restaurant sees this: Shiprocket's words, never a database error
        $shown = $e instanceof PDOException || !($e instanceof RuntimeException || $e instanceof InvalidArgumentException) ? 'Something went wrong. Please try again.' : $e->getMessage();
        if ($shown !== $e->getMessage()) error_log('quick_delivery book order ' . $orderId . ': ' . $e->getMessage());
        $fail($shown);
        if ($auto) qdLog($conn, $rid, 'delivery.quick', $o['order_number'] . ' — could not book a ' . QD_NAME . ' rider: ' . $shown, $by);
        throw $e;
    }
    return qdBooking($conn, $orderId) ?? [];
}

/** Cancel the order's live booking (the restaurant's choice, or the order was cancelled). */
function qdCancel(PDO $conn, string $rid, int $orderId, string $by = '', string $why = ''): bool
{
    $b = qdBooking($conn, $orderId);
    if (!$b || (int)$b['order_id'] !== $orderId || $b['restaurant_id'] !== $rid || !in_array($b['status'], array_merge(QD_LIVE, ['booking']), true)) return false;
    if ($b['status'] !== 'booked' && $b['status'] !== 'booking') throw new InvalidArgumentException('The rider has the food already, so it can\'t be cancelled here. Call Shiprocket support.');
    if (!empty($b['sr_order_id'])) qdApi($conn, 'POST', '/orders/cancel', ['ids' => [(int)$b['sr_order_id']]]);
    $conn->prepare("UPDATE qd_bookings SET status = 'cancelled', sr_status = ?, error = NULL WHERE id = ?")->execute([$why !== '' ? $why : 'Cancelled by the restaurant', $b['id']]);
    $conn->prepare("UPDATE delivery_tracking SET partner = NULL, partner_track_url = NULL, rider_name = NULL, rider_phone = NULL,
        delivery_status = IF(delivery_status = 'Assigned', 'Pending', delivery_status) WHERE order_id = ? AND partner = ?")->execute([$orderId, QD_PARTNER]);
    $on = $conn->prepare('SELECT order_number FROM orders WHERE id = ?'); $on->execute([$orderId]);
    qdLog($conn, $rid, 'delivery.quick', ($on->fetchColumn() ?: '#' . $orderId) . ' — ' . QD_NAME . ' rider cancelled' . ($why !== '' ? ': ' . $why : ''), $by);
    return true;
}

/**
 * A Shiprocket status (tracking or webhook) on a booking: rider details, and Picked up / On the way /
 * Delivered on the order (riderAdvance, like the restaurant's own riders). $agent: rider name / phone.
 */
function qdApplyStatus(PDO $conn, array $b, string $srStatus, array $agent = [], string $trackUrl = ''): void
{
    $s = strtoupper(trim($srStatus));
    if ($s === '' || !in_array($b['status'], array_merge(QD_LIVE, ['booking']), true)) return;
    $step = null; $next = $b['status'];
    if (preg_match('/CANCEL|RTO|LOST|UNDELIVERED|NOT SERVICEABLE/', $s)) $next = 'cancelled';
    elseif (preg_match('/DELIVERED/', $s)) { $step = 'Delivered'; $next = 'delivered'; }
    elseif (preg_match('/OUT FOR DELIVERY|IN TRANSIT|REACHED.*(DROP|DESTINATION)|ON THE WAY/', $s)) { $step = 'In_Transit'; $next = 'on_the_way'; }
    elseif (preg_match('/PICKED|SHIPPED/', $s)) { $step = 'Picked_Up'; $next = 'picked_up'; }

    $name = mb_substr(trim((string)($agent['name'] ?? '')), 0, 100);
    $phone = mb_substr(preg_replace('/[^\d+]/', '', (string)($agent['phone'] ?? '')), 0, 20);
    $conn->prepare('UPDATE qd_bookings SET status = ?, sr_status = ?, rider_name = COALESCE(?, rider_name), rider_phone = COALESCE(?, rider_phone), track_url = COALESCE(?, track_url) WHERE id = ?')
        ->execute([$next, mb_substr($srStatus, 0, 80), $name ?: null, $phone ?: null, $trackUrl ?: null, $b['id']]);
    if ($name !== '' || $phone !== '' || $trackUrl !== '') {
        $conn->prepare('UPDATE delivery_tracking SET rider_name = COALESCE(?, rider_name), rider_phone = COALESCE(?, rider_phone), partner_track_url = COALESCE(?, partner_track_url) WHERE order_id = ? AND partner = ?')
            ->execute([$name ?: null, $phone ?: null, $trackUrl ?: null, (int)$b['order_id'], QD_PARTNER]);
    }
    if ($next === 'cancelled') {
        // the ride fell through: the order is free for another rider (the restaurant is told on its screen)
        $conn->prepare("UPDATE delivery_tracking SET partner = NULL, partner_track_url = NULL, rider_name = NULL, rider_phone = NULL,
            delivery_status = IF(delivery_status IN ('Assigned'), 'Pending', delivery_status) WHERE order_id = ? AND partner = ?")->execute([(int)$b['order_id'], QD_PARTNER]);
        $conn->prepare('UPDATE qd_bookings SET error = ? WHERE id = ?')->execute(['Shiprocket cancelled the ride (' . mb_substr($srStatus, 0, 60) . '). Book again or use another app.', $b['id']]);
        return;
    }
    if (!$step) return;
    // walk the steps in order (a webhook can skip one), each like the rider tapped it
    foreach (['Picked_Up', 'In_Transit', 'Delivered'] as $want) {
        $t = $conn->prepare('SELECT * FROM delivery_tracking WHERE order_id = ? LIMIT 1');
        $t->execute([(int)$b['order_id']]);
        $row = $t->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;
        $order = ['Pending' => 0, 'Preparing' => 0, 'Assigned' => 0, 'Picked_Up' => 1, 'In_Transit' => 2, 'Delivered' => 3];
        $rank = ['Picked_Up' => 1, 'In_Transit' => 2, 'Delivered' => 3];
        if (($order[$row['delivery_status']] ?? 0) >= $rank[$want]) { if ($want === $step) return; continue; }
        try { riderAdvance($conn, $row, $want, true); }   // cash on delivery: Shiprocket's rider collects it
        catch (Throwable $e) { error_log('quick_delivery advance ' . $want . ' order ' . $b['order_id'] . ': ' . $e->getMessage()); return; }
        if ($want === $step) return;
    }
}

/** Ask Shiprocket where the ride is (order screen, cron). */
function qdRefresh(PDO $conn, array $b): array
{
    if (empty($b['shipment_id']) || !in_array($b['status'], QD_LIVE, true)) return $b;
    $status = ''; $agent = []; $url = '';
    try {
        $d = qdApi($conn, 'GET', '/courier/track/shipment/' . (int)$b['shipment_id']);
        $td = $d['tracking_data'] ?? (is_array($d) ? (array_values($d)[0]['tracking_data'] ?? []) : []);
        $tr = $td['shipment_track'][0] ?? [];
        $agent = is_array($tr['courier_agent_details'] ?? null) ? $tr['courier_agent_details'] : [];
        $status = (string)($tr['current_status'] ?? ($td['shipment_track_activities'][0]['activity'] ?? ''));
        $url = (string)($td['track_url'] ?? '');
    } catch (Throwable $e) {
        error_log('quick_delivery refresh ' . $b['id'] . ': ' . $e->getMessage());
    }
    // no tracking yet (common before pickup): the order's own status, GET /orders/show/{id}
    // ("Get Specific Order Details" in Shiprocket's docs)
    if ($status === '' && !empty($b['sr_order_id'])) {
        try {
            $o = qdApi($conn, 'GET', '/orders/show/' . (int)$b['sr_order_id'])['data'] ?? [];
            $status = (string)($o['status'] ?? '');
            $sh = $o['shipments'] ?? [];
            if (isset($sh[0]) && is_array($sh[0])) $sh = $sh[0];
            if (is_array($sh) && !empty($sh['awb']) && empty($b['awb'])) {
                $conn->prepare('UPDATE qd_bookings SET awb = ? WHERE id = ?')->execute([mb_substr((string)$sh['awb'], 0, 40), $b['id']]);
                $url = 'https://shiprocket.co/tracking/' . rawurlencode((string)$sh['awb']);
            }
        } catch (Throwable $e) {
            error_log('quick_delivery order show ' . $b['id'] . ': ' . $e->getMessage());
        }
    }
    if ($status !== '') {
        qdApplyStatus($conn, $b, $status, ['name' => $agent['name'] ?? ($agent['agent_name'] ?? ''), 'phone' => $agent['phone'] ?? ($agent['mobile'] ?? ($agent['agent_phone'] ?? ''))], preg_match('#^https?://#', $url) ? $url : '');
    }
    return qdBooking($conn, (int)$b['order_id']) ?? $b;
}

/**
 * After an order's status changed (api/update_order_status.php, the kitchen's Ready): book the rider
 * when the restaurant books by itself, or cancel the ride when the order was cancelled. Never throws.
 */
function qdAfterStatus(PDO $conn, string $rid, int $orderId, string $status, string $by = ''): void
{
    // runs on every status change of every restaurant: one cheap lookup before anything else
    try {
        $on = $conn->prepare('SELECT enabled FROM qd_restaurants WHERE restaurant_id = ?');
        $on->execute([$rid]);
        if (!(int)$on->fetchColumn()) return;
    } catch (PDOException $e) { return; }   // table not made yet: nobody has it switched on
    try {
        qdEnsure($conn);
        $s = qdSettings($conn, $rid);
        if (!$s['enabled']) return;
        if ($status === 'Cancelled' || $status === 'Rejected') { qdCancel($conn, $rid, $orderId, $by, 'Order ' . strtolower($status)); return; }
        $due = $s['auto_mode'] === 'accept' ? ['Accepted', 'Preparing', 'Ready'] : ($s['auto_mode'] === 'ready' ? ['Ready'] : []);
        if (!in_array($status, $due, true)) return;
        $o = qdOrder($conn, $rid, $orderId);
        if (!empty($o['is_scheduled']) && !empty($o['scheduled_at']) && strtotime((string)$o['scheduled_at']) > time() + 3600) return;   // later: the cron
        $b = qdBooking($conn, $orderId);
        if ($b && $b['status'] !== 'failed') return;   // booked, or cancelled by hand: leave it
        if ($b && $b['status'] === 'failed' && (int)$conn->query('SELECT COUNT(*) FROM qd_bookings WHERE order_id = ' . (int)$orderId)->fetchColumn() >= 3) return;
        if (qdBlocker($conn, $rid, $o) !== '') return;
        qdBook($conn, $rid, $orderId, $by !== '' ? $by : 'Automatic', true);
    } catch (Throwable $e) {
        error_log('quick_delivery after status ' . $status . ' order ' . $orderId . ': ' . $e->getMessage());
    }
}

/** qdAfterStatus with the order's status now (the kitchen moved it: controllers/kot_operations.php). Never throws. */
function qdAfterOrderChange(PDO $conn, string $rid, int $orderId): void
{
    try {
        $st = $conn->prepare("SELECT order_status FROM orders WHERE id = ? AND restaurant_id = ? AND order_type = 'Delivery'");
        $st->execute([$orderId, $rid]);
        $status = $st->fetchColumn();
        if ($status !== false) qdAfterStatus($conn, $rid, $orderId, (string)$status);
    } catch (Throwable $e) {
        error_log('quick_delivery after change order ' . $orderId . ': ' . $e->getMessage());
    }
}

/** The cron (main/cron/quick_delivery.php): books what is due and follows live rides. */
function qdSweep(PDO $conn): array
{
    qdEnsure($conn);
    $n = ['booked' => 0, 'refreshed' => 0];
    if (!qdConfigured()) return $n;
    $due = $conn->query("SELECT o.id, o.restaurant_id, o.order_status FROM orders o JOIN qd_restaurants q ON q.restaurant_id = o.restaurant_id COLLATE utf8mb4_unicode_ci AND q.enabled = 1 AND q.auto_mode <> 'off'
        WHERE o.order_type = 'Delivery' AND o.deleted_at IS NULL AND o.created_at > NOW() - INTERVAL 6 HOUR
          AND ((q.auto_mode = 'accept' AND o.order_status IN ('Accepted','Preparing','Ready')) OR (q.auto_mode = 'ready' AND o.order_status = 'Ready'))
          AND NOT EXISTS (SELECT 1 FROM qd_bookings b WHERE b.order_id = o.id AND b.status <> 'failed')
        LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($due as $o) {
        $before = qdBooking($conn, (int)$o['id']);
        qdAfterStatus($conn, (string)$o['restaurant_id'], (int)$o['id'], (string)$o['order_status'], 'Automatic');
        $after = qdBooking($conn, (int)$o['id']);
        if ($after && in_array($after['status'], QD_LIVE, true) && (!$before || $before['id'] !== $after['id'])) $n['booked']++;
    }
    $live = $conn->query("SELECT * FROM qd_bookings WHERE status IN ('booked','picked_up','on_the_way') AND updated_at < NOW() - INTERVAL 1 MINUTE AND created_at > NOW() - INTERVAL 1 DAY LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($live as $b) { qdRefresh($conn, $b); $n['refreshed']++; }
    return $n;
}

/** What the order screens show about a booking. */
function qdPublic(?array $b): ?array
{
    if (!$b) return null;
    $label = ['booking' => 'Booking…', 'booked' => 'Rider booked', 'picked_up' => 'Picked up', 'on_the_way' => 'On the way', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'failed' => 'Could not book'][$b['status']] ?? $b['status'];
    return [
        'status' => $b['status'], 'label' => $label, 'sr_status' => (string)($b['sr_status'] ?? ''),
        'rate' => $b['rate'] !== null ? (float)$b['rate'] : null, 'awb' => (string)($b['awb'] ?? ''),
        'rider_name' => (string)($b['rider_name'] ?? ''), 'rider_phone' => (string)($b['rider_phone'] ?? ''),
        'track_url' => (string)($b['track_url'] ?? ''), 'error' => (string)($b['error'] ?? ''),
        'auto' => !empty($b['auto']), 'booked_by' => (string)($b['booked_by'] ?? ''), 'at' => (string)$b['created_at'],
        'can_cancel' => in_array($b['status'], ['booked'], true),
    ];
}

function qdLog(PDO $conn, string $rid, string $action, string $summary, string $by = ''): void
{
    try {
        require_once __DIR__ . '/activity_log.php';
        // who did it: the logged-in person, or (cron, webhook) "Automatic"
        $actor = activityActor();
        if (empty($_SESSION['username']) && empty($_SESSION['superadmin_id'])) $actor = ['name' => $by !== '' ? $by : 'System', 'role' => 'Automatic', 'staff_id' => null];
        logActivity($conn, $rid, $action, $summary, [], 'order', null, $actor);
    } catch (Throwable $e) { /* the log is optional */ }
}
