<?php
/**
 * Rooms: for a restaurant that also lets rooms (hotel, lodge, guest house).
 *
 *   room_types      a kind of room the guest books ("Deluxe Double", Rs. 2500 a night, 4 rooms, up to 2 guests),
 *                   with photos (files in uploads/rooms), amenities and an optional list of room numbers
 *   room_bookings   a stay: dates, guests, how many rooms, the price per night when booked, status and payment
 *
 * Off by default: users.enable_rooms (Settings › Rooms, or the switch on the Rooms page) and the Super Admin
 * "Access" switch both have to be on (roomsFeatureEnabled). Guests book on {site}/rooms (website/rooms.php,
 * api/rooms_public.php) as a request; the owner confirms it on the Rooms page (api/rooms_admin.php).
 * Pay at the hotel: no online payment, the owner marks a booking paid.
 * A room type is free on a night when its room_count minus the rooms held that night (Pending, Confirmed or
 * Checked in bookings) is at least the rooms asked for. Creates its own tables (the live database never
 * runs migrations).
 *
 *   room_settings        one row per restaurant: check-in / check-out times, cancellation policy, house rules
 *                        (shown on the guest page), room tax and bill look, room service switches (JSON in `data`,
 *                        merged over roomSettingsDefaults()), and the salt behind the room QR codes
 *   room_service_orders  a website order placed from a room's QR code: which room, which stay, and whether it
 *                        goes on the room bill (to_bill = 1, paid at checkout with the stay)
 *
 * Room bill (roomBill, website/room_bill.php): the nights, the owner's extra charges, a discount, room tax
 * (India "auto": GST 5% up to Rs. 7,500 a night, 18% above), the room service orders put on the bill, minus the
 * advance. A room is given to one stay at a time per night (roomNumbersClash). Reports: roomsReport().
 */

if (defined('ROOMS_LOADED')) return;
define('ROOMS_LOADED', true);

const ROOM_STATUSES = ['Pending', 'Confirmed', 'Checked in', 'Checked out', 'Cancelled', 'Declined'];
const ROOM_HOLDING = ['Pending', 'Confirmed', 'Checked in'];   // these keep a room taken for their nights
const ROOM_MAX_NIGHTS = 30;
const ROOM_BOOK_AHEAD_DAYS = 365;
const ROOM_MAX_PHOTOS = 6;

function roomsEnsure(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->query('SELECT enable_rooms FROM users LIMIT 1');
    } catch (PDOException $e) {
        try { $conn->exec('ALTER TABLE users ADD COLUMN enable_rooms TINYINT(1) NOT NULL DEFAULT 0'); } catch (PDOException $e2) {}
    }
    $conn->exec("CREATE TABLE IF NOT EXISTS room_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(20) NOT NULL,
        name VARCHAR(100) NOT NULL,
        description TEXT NULL,
        price_per_night DECIMAL(10,2) NOT NULL DEFAULT 0,
        max_guests INT NOT NULL DEFAULT 2,
        room_count INT NOT NULL DEFAULT 1,
        room_numbers VARCHAR(255) NULL,
        amenities VARCHAR(500) NULL,
        photos TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_room_types_rest (restaurant_id, is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS room_bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(20) NOT NULL,
        room_type_id INT NOT NULL,
        guest_name VARCHAR(120) NOT NULL,
        guest_phone VARCHAR(20) NOT NULL,
        guest_email VARCHAR(190) NULL,
        check_in DATE NOT NULL,
        check_out DATE NOT NULL,
        guests INT NOT NULL DEFAULT 1,
        rooms INT NOT NULL DEFAULT 1,
        price_per_night DECIMAL(10,2) NOT NULL DEFAULT 0,
        total DECIMAL(10,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        paid TINYINT(1) NOT NULL DEFAULT 0,
        room_numbers VARCHAR(100) NULL,
        notes VARCHAR(500) NULL,
        owner_note VARCHAR(500) NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'website',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_room_bookings_rest (restaurant_id, check_in),
        INDEX idx_room_bookings_type (room_type_id, status, check_in, check_out)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // the room bill: its number, the owner's extra charges, discount, advance, how it was paid, the guest's company
    if (!$conn->query("SHOW COLUMNS FROM room_bookings LIKE 'bill_no'")->fetch()) {
        $conn->exec("ALTER TABLE room_bookings ADD COLUMN bill_no INT UNSIGNED NULL, ADD COLUMN extras TEXT NULL,
            ADD COLUMN discount DECIMAL(10,2) NOT NULL DEFAULT 0, ADD COLUMN advance DECIMAL(10,2) NOT NULL DEFAULT 0,
            ADD COLUMN pay_method VARCHAR(40) NULL, ADD COLUMN paid_at DATETIME NULL,
            ADD COLUMN company_name VARCHAR(150) NULL, ADD COLUMN company_tax_id VARCHAR(30) NULL,
            ADD INDEX idx_room_bookings_bill (restaurant_id, bill_no)");
    }
    $conn->exec("CREATE TABLE IF NOT EXISTS room_settings (
        restaurant_id VARCHAR(20) NOT NULL PRIMARY KEY,
        data TEXT NULL,
        qr_salt VARCHAR(16) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS room_service_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(20) NOT NULL,
        order_id INT NOT NULL,
        booking_id INT NULL,
        room_number VARCHAR(12) NOT NULL,
        to_bill TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_room_service_order (order_id),
        INDEX idx_room_service_booking (booking_id),
        INDEX idx_room_service_rest (restaurant_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** The owner switched Rooms on, and Super Admin hasn't switched the page off. Never throws. */
function roomsFeatureEnabled(PDO $conn, ?string $rid): bool
{
    if (!$rid) return false;
    try {
        roomsEnsure($conn);
        $st = $conn->prepare('SELECT enable_rooms FROM users WHERE restaurant_id = ? LIMIT 1');
        $st->execute([$rid]);
        if ((int)$st->fetchColumn() !== 1) return false;
        require_once __DIR__ . '/access_control.php';
        return accessPageOn($conn, $rid, 'roomsPage');
    } catch (Throwable $e) {
        return false;
    }
}

/** Only the owner's switch (the dashboard shows the Rooms item while it's on). */
function roomsSwitchOn(PDO $conn, string $rid): bool
{
    try {
        roomsEnsure($conn);
        $st = $conn->prepare('SELECT enable_rooms FROM users WHERE restaurant_id = ? LIMIT 1');
        $st->execute([$rid]);
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/** A room type as the pages use it (photos and amenities as lists). */
function roomTypePublic(array $r): array
{
    $photos = json_decode((string)($r['photos'] ?? ''), true);
    return [
        'id' => (int)$r['id'],
        'name' => (string)$r['name'],
        'description' => (string)($r['description'] ?? ''),
        'price' => (float)$r['price_per_night'],
        'max_guests' => (int)$r['max_guests'],
        'room_count' => (int)$r['room_count'],
        'room_numbers' => (string)($r['room_numbers'] ?? ''),
        'amenities' => array_values(array_filter(array_map('trim', explode(',', (string)($r['amenities'] ?? ''))))),
        'photos' => is_array($photos) ? array_values(array_filter($photos, 'is_string')) : [],
        'active' => (int)($r['is_active'] ?? 1) === 1,
    ];
}

function roomTypes(PDO $conn, string $rid, bool $activeOnly = false): array
{
    roomsEnsure($conn);
    $st = $conn->prepare('SELECT * FROM room_types WHERE restaurant_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id');
    $st->execute([$rid]);
    return array_map('roomTypePublic', $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Y-m-d dates: in is today or later, out is after in, at most ROOM_MAX_NIGHTS nights. Returns [in, out, nights] or throws. */
function roomDates(string $in, string $out, DateTimeZone $tz, bool $allowPast = false): array
{
    $a = DateTime::createFromFormat('!Y-m-d', $in, $tz);
    $b = DateTime::createFromFormat('!Y-m-d', $out, $tz);
    if (!$a || !$b || $a->format('Y-m-d') !== $in || $b->format('Y-m-d') !== $out) throw new InvalidArgumentException('Please choose check-in and check-out dates.');
    $today = new DateTime('today', $tz);
    if (!$allowPast && $a < $today) throw new InvalidArgumentException('Check-in can\'t be in the past.');
    if ($a > (clone $today)->modify('+' . ROOM_BOOK_AHEAD_DAYS . ' days')) throw new InvalidArgumentException('Bookings open up to a year ahead.');
    $nights = (int)$a->diff($b)->format('%r%a');
    if ($nights < 1) throw new InvalidArgumentException('Check-out must be after check-in.');
    if ($nights > ROOM_MAX_NIGHTS) throw new InvalidArgumentException('A booking can be up to ' . ROOM_MAX_NIGHTS . ' nights.');
    return [$in, $out, $nights];
}

/**
 * How many rooms of each type are still free for every night of [in, out): [type_id => free].
 * $skipBooking leaves one booking out (when changing that booking).
 */
function roomsFree(PDO $conn, string $rid, string $in, string $out, int $skipBooking = 0): array
{
    roomsEnsure($conn);
    $types = $conn->prepare('SELECT id, room_count FROM room_types WHERE restaurant_id = ?');
    $types->execute([$rid]);
    $count = array_map('intval', $types->fetchAll(PDO::FETCH_KEY_PAIR));
    $marks = implode(',', array_fill(0, count(ROOM_HOLDING), '?'));
    $st = $conn->prepare("SELECT room_type_id, check_in, check_out, rooms FROM room_bookings
        WHERE restaurant_id = ? AND status IN ($marks) AND check_in < ? AND check_out > ? AND id <> ?");
    $st->execute(array_merge([$rid], ROOM_HOLDING, [$out, $in, $skipBooking]));
    $held = [];   // type => night => rooms
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $d = new DateTime(max($b['check_in'], $in));
        $end = min($b['check_out'], $out);
        for (; $d->format('Y-m-d') < $end; $d->modify('+1 day')) {
            $held[(int)$b['room_type_id']][$d->format('Y-m-d')] = ($held[(int)$b['room_type_id']][$d->format('Y-m-d')] ?? 0) + (int)$b['rooms'];
        }
    }
    $free = [];
    foreach ($count as $id => $n) $free[$id] = max(0, $n - (empty($held[$id]) ? 0 : max($held[$id])));
    return $free;
}

/** Saves an uploaded room photo (JPG / PNG / WebP, up to 6 MB) as a file. Returns its path under uploads/. */
function roomPhotoSave(array $file, string $rid): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('The photo could not be uploaded. Please try again.');
    if (filesize($file['tmp_name']) > 6 * 1024 * 1024) throw new InvalidArgumentException('Photos can be up to 6 MB.');
    $info = @getimagesize($file['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) throw new InvalidArgumentException('Photos must be JPG, PNG or WebP.');
    $dir = __DIR__ . '/../uploads/rooms';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'room_' . preg_replace('/[^A-Za-z0-9]/', '', $rid) . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    // a big phone photo is shrunk to 1600 px wide (the page never shows it bigger)
    $src = $info[0] > 1600 && function_exists('imagecreatefromstring') ? @imagecreatefromstring((string)file_get_contents($file['tmp_name'])) : null;
    if ($src) {
        $w = 1600; $h = (int)round($info[1] * $w / $info[0]);
        $dst = imagecreatetruecolor($w, $h);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
        $name = preg_replace('/\.\w+$/', '.jpg', $name);
        $ok = imagejpeg($dst, $dir . '/' . $name, 84);
        imagedestroy($src); imagedestroy($dst);
        if (!$ok) throw new RuntimeException('Could not save the photo');
    } elseif (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save the photo');
    }
    return 'uploads/rooms/' . $name;
}

/** A path this feature saved (so a request can't point a room at some other file). */
function roomPhotoValid(string $path): bool
{
    return (bool)preg_match('#^uploads/rooms/room_[A-Za-z0-9]+_[a-f0-9]{12}\.(jpg|png|webp)$#', $path);
}

/** Tells the owner about a new website booking (browser push; never for the demo restaurant). */
function roomBookingNotify(PDO $conn, string $rid, array $b, string $typeName): void
{
    try {
        require_once __DIR__ . '/demo_restaurant.php';
        if ($rid === DEMO_RESTAURANT_ID) return;
        require_once __DIR__ . '/push_notification.php';
        if (function_exists('sendPushNotification')) {
            sendPushNotification($conn, $rid, 'New room booking',
                $b['guest_name'] . ' · ' . $typeName . ' · ' . date('j M', strtotime($b['check_in'])) . ' – ' . date('j M', strtotime($b['check_out'])),
                '../views/dashboard.php?page=roomsPage', null);
        }
    } catch (Throwable $e) {
        error_log('room booking notify: ' . $e->getMessage());
    }
}

// ───────────────────────── Settings: times, policies, house rules, room tax, bill, room service ─────────────────────────

/** House rules the owner can tick (shown on the guest page and, if chosen, the bill). key => [words, on by default] */
const ROOM_RULES = [
    'id_required'   => ['Valid photo ID needed at check-in for every adult', true],
    'couples_ok'    => ['Unmarried couples welcome', false],
    'locals_ok'     => ['Guests with a local ID are welcome', true],
    'foreigners_ok' => ['Foreign guests welcome (passport and visa needed)', true],
    'pets_ok'       => ['Pets allowed', false],
    'no_smoking'    => ['No smoking inside the rooms', true],
    'no_alcohol'    => ['No alcohol on the property', false],
    'no_visitors'   => ['Visitors are not allowed in the rooms', false],
    'quiet_hours'   => ['Quiet hours from 10 PM to 7 AM', false],
    'no_parties'    => ['No parties or events', true],
    'early_late'    => ['Early check-in and late check-out on request (charges may apply)', true],
    'kids_free'     => ['Children under 5 stay free with their parents', false],
];

/** Cancellation choices. key => words shown to the guest ('custom' shows the owner's own words) */
const ROOM_CANCEL = [
    'free_24h' => 'Free cancellation up to 24 hours before check-in. Later than that, the first night is charged.',
    'free_48h' => 'Free cancellation up to 48 hours before check-in. Later than that, the first night is charged.',
    'free_7d'  => 'Free cancellation up to 7 days before check-in. Later than that, half of the stay is charged.',
    'no_free'  => 'No free cancellation: once confirmed, the full stay is charged.',
    'custom'   => '',
];

/** Room tax: auto (India: GST slab by the nightly rate; elsewhere the restaurant's tax), restaurant (same % as food), fixed (own %), none */
const ROOM_TAX_MODES = ['auto', 'restaurant', 'fixed', 'none'];

function roomSettingsDefaults(): array
{
    $rules = [];
    foreach (ROOM_RULES as $k => [$label, $on]) if ($on) $rules[] = $k;
    return [
        'checkin_time' => '12:00', 'checkout_time' => '11:00',
        'cancel' => 'free_24h', 'cancel_text' => '',
        'rules' => $rules, 'house_rules' => '',
        'extra_bed' => 0,                       // an extra bed a night (0 = not offered)
        'tax_mode' => 'auto', 'tax_percent' => 12, 'tax_inclusive' => false,
        'bill_title' => '', 'bill_prefix' => 'RM-', 'bill_footer' => 'Thank you for staying with us. We hope to see you again!',
        'bill_rules' => false, 'bill_signature' => true, 'bill_food' => true,
        'room_service' => true, 'room_charge' => true,
    ];
}

/** The restaurant's room settings, every key always there. */
function roomSettings(PDO $conn, string $rid): array
{
    roomsEnsure($conn);
    $st = $conn->prepare('SELECT data FROM room_settings WHERE restaurant_id = ?');
    $st->execute([$rid]);
    $saved = json_decode((string)$st->fetchColumn(), true);
    $s = array_merge(roomSettingsDefaults(), is_array($saved) ? $saved : []);
    $s['rules'] = array_values(array_intersect((array)$s['rules'], array_keys(ROOM_RULES)));
    if (!array_key_exists((string)$s['cancel'], ROOM_CANCEL)) $s['cancel'] = 'free_24h';
    if (!in_array($s['tax_mode'], ROOM_TAX_MODES, true)) $s['tax_mode'] = 'auto';
    return $s;
}

/** Cleans what the Settings tab sent and saves it. Returns the saved settings. */
function roomSettingsSave(PDO $conn, string $rid, array $in): array
{
    $s = roomSettings($conn, $rid);
    $clean = fn($v, int $n) => mb_substr(trim(strip_tags((string)$v)), 0, $n);
    $time = fn($v, $d) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$v) ? (string)$v : $d;
    $s['checkin_time'] = $time($in['checkin_time'] ?? '', $s['checkin_time']);
    $s['checkout_time'] = $time($in['checkout_time'] ?? '', $s['checkout_time']);
    if (isset($in['cancel']) && array_key_exists((string)$in['cancel'], ROOM_CANCEL)) $s['cancel'] = (string)$in['cancel'];
    if (array_key_exists('cancel_text', $in)) $s['cancel_text'] = $clean($in['cancel_text'], 600);
    if ($s['cancel'] === 'custom' && $s['cancel_text'] === '') throw new InvalidArgumentException('Write your cancellation policy, or pick one of the ready ones.');
    if (isset($in['rules'])) $s['rules'] = array_values(array_intersect(array_map('strval', (array)$in['rules']), array_keys(ROOM_RULES)));
    if (array_key_exists('house_rules', $in)) $s['house_rules'] = implode("\n", array_slice(array_values(array_filter(array_map(fn($l) => $clean($l, 160), preg_split('/\r?\n/', (string)$in['house_rules'])), 'strlen')), 0, 15));
    if (isset($in['extra_bed'])) $s['extra_bed'] = max(0, min(1000000, round((float)$in['extra_bed'], 2)));
    if (isset($in['tax_mode']) && in_array($in['tax_mode'], ROOM_TAX_MODES, true)) $s['tax_mode'] = (string)$in['tax_mode'];
    if (isset($in['tax_percent'])) $s['tax_percent'] = max(0, min(50, round((float)$in['tax_percent'], 2)));
    foreach (['tax_inclusive', 'bill_rules', 'bill_signature', 'bill_food', 'room_service', 'room_charge'] as $k) {
        if (array_key_exists($k, $in)) $s[$k] = !empty($in[$k]);
    }
    if (array_key_exists('bill_title', $in)) $s['bill_title'] = $clean($in['bill_title'], 40);
    if (array_key_exists('bill_prefix', $in)) $s['bill_prefix'] = preg_replace('/[^A-Za-z0-9\/_-]/', '', mb_substr((string)$in['bill_prefix'], 0, 10));
    if (array_key_exists('bill_footer', $in)) $s['bill_footer'] = $clean($in['bill_footer'], 400);
    $conn->prepare('INSERT INTO room_settings (restaurant_id, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
         ->execute([$rid, json_encode($s, JSON_UNESCAPED_UNICODE)]);
    return $s;
}

/** What the guest sees: times, the cancellation words, the ticked rules and then the owner's own lines. */
function roomPolicies(array $s): array
{
    $rules = array_map(fn($k) => ROOM_RULES[$k][0], $s['rules']);
    foreach (preg_split('/\r?\n/', (string)$s['house_rules']) as $l) if (trim($l) !== '') $rules[] = trim($l);
    return [
        'checkin' => roomTimeLabel($s['checkin_time']), 'checkout' => roomTimeLabel($s['checkout_time']),
        'cancel' => $s['cancel'] === 'custom' ? $s['cancel_text'] : ROOM_CANCEL[$s['cancel']],
        'rules' => $rules, 'extra_bed' => (float)$s['extra_bed'],
    ];
}

/** "14:00" -> "2:00 PM" */
function roomTimeLabel(string $hm): string
{
    $t = DateTime::createFromFormat('H:i', $hm);
    return $t ? $t->format('g:i A') : $hm;
}

// ───────────────────────── Room numbers: which room a stay is in ─────────────────────────

/** "101, 102 103" -> ['101', '102', '103'] */
function roomNumberList(?string $s): array
{
    return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\s]+/', (string)$s)), 'strlen')));
}

/**
 * The first of $numbers already given to another stay that holds a room on one of the nights [in, out).
 * Returns ['room' => '102', 'guest' => 'Asha', 'in' => …, 'out' => …] or null when they are all free.
 */
function roomNumbersClash(PDO $conn, string $rid, array $numbers, string $in, string $out, int $skipBooking = 0): ?array
{
    if (!$numbers) return null;
    $marks = implode(',', array_fill(0, count(ROOM_HOLDING), '?'));
    $st = $conn->prepare("SELECT id, guest_name, check_in, check_out, room_numbers FROM room_bookings
        WHERE restaurant_id = ? AND status IN ($marks) AND check_in < ? AND check_out > ? AND id <> ? AND room_numbers IS NOT NULL AND room_numbers <> ''");
    $st->execute(array_merge([$rid], ROOM_HOLDING, [$out, $in, $skipBooking]));
    $want = array_map('strtolower', $numbers);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $both = array_intersect($want, array_map('strtolower', roomNumberList($b['room_numbers'])));
        if ($both) return ['room' => (string)reset($both), 'guest' => $b['guest_name'], 'in' => $b['check_in'], 'out' => $b['check_out']];
    }
    return null;
}

/**
 * Checks the room numbers given to a stay of a room type: each is one of that type's rooms (when the type lists
 * its numbers), no more numbers than rooms booked, and none given to another stay on those nights.
 * Returns the cleaned "101, 102" ('' for none), or throws InvalidArgumentException with words for the owner.
 */
function roomNumbersCheck(PDO $conn, string $rid, array $type, string $numbers, string $in, string $out, int $rooms, int $skipBooking = 0): string
{
    $list = array_map(fn($n) => mb_substr($n, 0, 12), roomNumberList($numbers));
    if (!$list) return '';
    if (count($list) > max(1, $rooms)) throw new InvalidArgumentException('This stay has ' . $rooms . ' room' . ($rooms === 1 ? '' : 's') . ': give ' . ($rooms === 1 ? 'one room number' : 'up to ' . $rooms . ' room numbers') . '.');
    $own = roomNumberList($type['room_numbers'] ?? '');
    if ($own) {
        $lower = array_map('strtolower', $own);
        foreach ($list as $n) {
            if (!in_array(strtolower($n), $lower, true)) throw new InvalidArgumentException('Room ' . $n . ' is not one of the ' . $type['name'] . ' rooms (' . implode(', ', $own) . ').');
        }
    }
    $c = roomNumbersClash($conn, $rid, $list, $in, $out, $skipBooking);
    if ($c) throw new InvalidArgumentException('Room ' . $c['room'] . ' is already given to ' . $c['guest'] . ' (' . date('j M', strtotime($c['in'])) . ' – ' . date('j M', strtotime($c['out'])) . ').');
    return implode(', ', $list);
}

// ───────────────────────── Room service: a QR code in each room ─────────────────────────

function roomQrSecret(): string
{
    require_once __DIR__ . '/env_loader.php';
    return 'rooms|' . (string)env('WA_KEY_SECRET', '') . '|' . (string)env('DB_PASS', '') . '|' . (string)env('DB_NAME', '');
}

/** The salt behind this restaurant's room QR codes ("Make new QR codes" changes it, so old prints stop working). */
function roomQrSalt(PDO $conn, string $rid, bool $renew = false): string
{
    roomsEnsure($conn);
    if (!$renew) {
        $st = $conn->prepare('SELECT qr_salt FROM room_settings WHERE restaurant_id = ?');
        $st->execute([$rid]);
        $salt = (string)$st->fetchColumn();
        if ($salt !== '') return $salt;
    }
    $salt = bin2hex(random_bytes(6));
    $conn->prepare('INSERT INTO room_settings (restaurant_id, qr_salt) VALUES (?, ?) ON DUPLICATE KEY UPDATE qr_salt = VALUES(qr_salt)')->execute([$rid, $salt]);
    return $salt;
}

/** What a room's QR code carries in ?room=: "104.k3f9a0c2e1" (the room number, a dot, a check code). */
function roomQrParam(string $rid, string $room, string $salt): string
{
    return $room . '.' . substr(hash_hmac('sha256', $rid . '|' . strtolower($room) . '|' . $salt, roomQrSecret()), 0, 10);
}

/**
 * A scanned ?room= value: ['room', 'type', 'booking' => the stay checked in to that room today or null, 'room_charge'].
 * null when the code is wrong or old ("Make new QR codes"), the room is gone, or room service is off.
 */
function roomServiceFind(PDO $conn, string $rid, string $param): ?array
{
    try {
        $dot = strrpos($param, '.');
        if ($dot === false || $dot === 0) return null;
        $room = substr($param, 0, $dot);
        if (!hash_equals(roomQrParam($rid, $room, roomQrSalt($conn, $rid)), $param)) return null;
        $s = roomSettings($conn, $rid);
        if (empty($s['room_service'])) return null;
        $type = null;
        foreach (roomTypes($conn, $rid) as $t) {
            if (in_array(strtolower($room), array_map('strtolower', roomNumberList($t['room_numbers'])), true)) { $type = $t; break; }
        }
        if (!$type) return null;
        $today = (new DateTime('today', roomRestaurantTz($conn, $rid)))->format('Y-m-d');
        $st = $conn->prepare("SELECT * FROM room_bookings WHERE restaurant_id = ? AND status = 'Checked in' AND check_in <= ? AND check_out >= ?
            AND FIND_IN_SET(?, REPLACE(LOWER(room_numbers), ' ', '')) ORDER BY check_in DESC, id DESC LIMIT 1");
        $st->execute([$rid, $today, $today, strtolower($room)]);
        return ['room' => $room, 'type' => $type['name'], 'booking' => $st->fetch(PDO::FETCH_ASSOC) ?: null, 'room_charge' => !empty($s['room_charge'])];
    } catch (Throwable $e) {
        error_log('roomServiceFind: ' . $e->getMessage());
        return null;
    }
}

/** Same last 10 digits: the phone typed at checkout is the stay's phone (needed to put food on the room bill). */
function roomPhoneSame(string $a, string $b): bool
{
    $a = substr(preg_replace('/\D/', '', $a), -10);
    $b = substr(preg_replace('/\D/', '', $b), -10);
    return strlen($a) >= 7 && $a === $b;
}

/** Remembers that a website order came from a room (and whether it goes on that stay's bill). */
function roomServiceLink(PDO $conn, string $rid, int $orderId, array $found, bool $toBill): void
{
    try {
        $conn->prepare('INSERT IGNORE INTO room_service_orders (restaurant_id, order_id, booking_id, room_number, to_bill) VALUES (?, ?, ?, ?, ?)')
             ->execute([$rid, $orderId, $found['booking']['id'] ?? null, mb_substr($found['room'], 0, 12), $toBill ? 1 : 0]);
    } catch (Throwable $e) {
        error_log('roomServiceLink: ' . $e->getMessage());
    }
}

function roomRestaurantTz(PDO $conn, string $rid): DateTimeZone
{
    try {
        $st = $conn->prepare('SELECT timezone FROM users WHERE restaurant_id = ? LIMIT 1');
        $st->execute([$rid]);
        return new DateTimeZone((string)$st->fetchColumn() ?: 'Asia/Kolkata');
    } catch (Throwable $e) {
        return new DateTimeZone('Asia/Kolkata');
    }
}

// ───────────────────────── Room bill ─────────────────────────

/** The extra charges saved on a stay: [['label' => 'Extra bed', 'amount' => 500], …] */
function roomExtras(?string $json): array
{
    $out = [];
    foreach ((array)json_decode((string)$json, true) as $x) {
        if (!is_array($x)) continue;
        $label = mb_substr(trim(strip_tags((string)($x['label'] ?? ''))), 0, 80);
        $amount = round((float)($x['amount'] ?? 0), 2);
        if ($label !== '' && $amount > 0) $out[] = ['label' => $label, 'amount' => min($amount, 10000000)];
    }
    return array_slice($out, 0, 20);
}

/** Room tax % for a nightly rate. India "auto" follows the GST slabs for hotel rooms: 5% up to Rs. 7,500 a night, 18% above. */
function roomTaxPercent(array $s, array $u, float $rate): float
{
    $mode = $s['tax_mode'];
    $india = strtolower(trim((string)($u['country'] ?? 'India'))) === 'india';
    if ($mode === 'auto' && !$india) $mode = 'restaurant';
    if ($mode === 'none') return 0.0;
    if ($mode === 'auto') return $rate > 7500 ? 18.0 : 5.0;
    if ($mode === 'fixed') return (float)$s['tax_percent'];
    return !isset($u['enable_gst']) || (int)$u['enable_gst'] === 1 ? (float)($u['tax_percent'] ?? 5) : 0.0;
}

/** Gives the stay its bill number (1, 2, 3… per restaurant, shown after the prefix) the first time its bill is made. */
function roomBillNo(PDO $conn, string $rid, int $id): ?int
{
    $lock = $conn->quote('rgroombill_' . $rid);
    $conn->query("SELECT GET_LOCK($lock, 5)");
    try {
        $st = $conn->prepare('SELECT bill_no FROM room_bookings WHERE id = ? AND restaurant_id = ?');
        $st->execute([$id, $rid]);
        $no = $st->fetchColumn();
        if ($no === false) return null;
        if ($no === null) {
            $mx = $conn->prepare('SELECT COALESCE(MAX(bill_no), 0) + 1 FROM room_bookings WHERE restaurant_id = ?');
            $mx->execute([$rid]);
            $no = (int)$mx->fetchColumn();
            $conn->prepare('UPDATE room_bookings SET bill_no = ? WHERE id = ? AND restaurant_id = ? AND bill_no IS NULL')->execute([$no, $id, $rid]);
        }
        return (int)$no;
    } finally {
        $conn->query("SELECT RELEASE_LOCK($lock)");
    }
}

/** "5.00" -> "5", "2.50" -> "2.5" */
function roomPct(float $p): string
{
    return rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.');
}

/**
 * Everything on a stay's bill, worked out on the server: the nights (rate × nights × rooms), extra charges, discount,
 * room tax (CGST + SGST halves for Indian GST), the room service orders put on the bill, the advance, and what is
 * left to pay. $number = true gives the stay its bill number if it has none. null when it isn't this restaurant's.
 */
function roomBill(PDO $conn, string $rid, int $id, bool $number = true): ?array
{
    roomsEnsure($conn);
    $st = $conn->prepare('SELECT b.*, t.name AS type_name FROM room_bookings b LEFT JOIN room_types t ON t.id = b.room_type_id WHERE b.id = ? AND b.restaurant_id = ?');
    $st->execute([$id, $rid]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) return null;
    $us = $conn->prepare('SELECT * FROM users WHERE restaurant_id = ? LIMIT 1');
    $us->execute([$rid]);
    $u = $us->fetch(PDO::FETCH_ASSOC) ?: [];
    $s = roomSettings($conn, $rid);
    $nights = (int)(new DateTime($b['check_in']))->diff(new DateTime($b['check_out']))->days;
    $rooms = (int)$b['rooms'];
    $rate = (float)$b['price_per_night'];
    $roomAmount = round($rate * $nights * $rooms, 2);
    $extras = roomExtras($b['extras']);
    $extraSum = round(array_sum(array_column($extras, 'amount')), 2);
    $discount = min(max(0, round((float)$b['discount'], 2)), $roomAmount + $extraSum);
    $taxable = max(0, round($roomAmount + $extraSum - $discount, 2));
    $pct = roomTaxPercent($s, $u, $rate);
    $t = $pct / 100;
    if (!empty($s['tax_inclusive'])) {
        $net = round($taxable / (1 + $t), 2);
        $tax = round($taxable - $net, 2);
        $stayTotal = $taxable;
    } else {
        $net = $taxable;
        $tax = round($taxable * $t, 2);
        $stayTotal = round($taxable + $tax, 2);
    }
    $taxName = trim((string)($u['tax_name'] ?? '')) ?: 'GST';
    $india = strtolower(trim((string)($u['country'] ?? 'India'))) === 'india';
    $taxLines = [];
    if ($tax > 0) {
        if ($india && stripos($taxName, 'GST') !== false) {
            $half = round($tax / 2, 2);
            $taxLines = [['label' => 'CGST ' . roomPct($pct / 2) . '%', 'amount' => $half], ['label' => 'SGST ' . roomPct($pct / 2) . '%', 'amount' => round($tax - $half, 2)]];
        } else {
            $taxLines = [['label' => $taxName . ' ' . roomPct($pct) . '%', 'amount' => $tax]];
        }
    }
    // room service orders put on this stay's bill (not cancelled); each already holds its own food tax
    $food = [];
    $fq = $conn->prepare("SELECT o.id, o.order_number, o.created_at, o.total, o.payment_status, r.room_number
        FROM room_service_orders r JOIN orders o ON o.id = r.order_id
        WHERE r.booking_id = ? AND r.restaurant_id = ? AND r.to_bill = 1 AND o.order_status NOT IN ('Cancelled', 'Rejected') AND o.deleted_at IS NULL ORDER BY o.created_at");
    $fq->execute([$id, $rid]);
    $items = $conn->prepare('SELECT item_name, quantity FROM order_items WHERE order_id = ? ORDER BY id');
    foreach ($fq->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $items->execute([$o['id']]);
        $food[] = ['id' => (int)$o['id'], 'number' => $o['order_number'], 'at' => $o['created_at'], 'room' => $o['room_number'], 'total' => (float)$o['total'],
            'paid' => $o['payment_status'] === 'Paid',
            'items' => implode(', ', array_map(fn($i) => ((int)$i['quantity'] > 1 ? (int)$i['quantity'] . ' × ' : '') . $i['item_name'], $items->fetchAll(PDO::FETCH_ASSOC)))];
    }
    $paid = (int)$b['paid'] === 1;
    // settling the stay marks its food orders Paid; one ordered after that is still owed (balance) until settled again
    $foodTotal = round(array_sum(array_column($food, 'total')), 2);
    $foodDue = round(array_sum(array_map(fn($f) => $f['paid'] ? 0 : $f['total'], $food)), 2);
    $grand = round($stayTotal + $foodTotal, 2);
    $advance = min(max(0, round((float)$b['advance'], 2)), $grand);
    $billNo = $b['bill_no'] !== null ? (int)$b['bill_no'] : ($number ? roomBillNo($conn, $rid, $id) : null);
    return [
        'id' => (int)$b['id'], 'bill_no' => $billNo, 'bill_label' => $billNo ? ($s['bill_prefix'] . $billNo) : '',
        'title' => $s['bill_title'] !== '' ? $s['bill_title'] : ($tax > 0 ? 'Tax Invoice' : 'Bill'),
        'hotel' => ['name' => (string)($u['restaurant_name'] ?? ''), 'address' => (string)($u['address'] ?? ''), 'phone' => (string)($u['phone'] ?? ''),
            'email' => (string)($u['email'] ?? ''), 'user_id' => (int)($u['id'] ?? 0), 'logo' => (string)($u['restaurant_logo'] ?? ''),
            'tax_id_label' => !empty($u['show_business_id']) && !empty($u['business_id_no']) ? ((string)($u['business_id_label'] ?? '') ?: 'GSTIN') : '',
            'tax_id' => !empty($u['show_business_id']) ? (string)($u['business_id_no'] ?? '') : ''],
        'currency' => (string)($u['currency_symbol'] ?? '') ?: '₹',
        'guest' => ['name' => $b['guest_name'], 'phone' => $b['guest_phone'], 'email' => (string)$b['guest_email'],
            'company' => (string)$b['company_name'], 'company_tax_id' => (string)$b['company_tax_id']],
        'stay' => ['type' => (string)($b['type_name'] ?? 'Room'), 'rooms' => $rooms, 'room_numbers' => (string)$b['room_numbers'], 'guests' => (int)$b['guests'],
            'in' => $b['check_in'], 'out' => $b['check_out'], 'nights' => $nights, 'status' => $b['status'],
            'checkin_time' => roomTimeLabel($s['checkin_time']), 'checkout_time' => roomTimeLabel($s['checkout_time'])],
        'lines' => array_merge(
            [['label' => ($b['type_name'] ?? 'Room') . ($rooms > 1 ? ' × ' . $rooms . ' rooms' : ''), 'detail' => $nights . ' night' . ($nights === 1 ? '' : 's'), 'rate' => $rate, 'qty' => $nights * $rooms, 'amount' => $roomAmount]],
            array_map(fn($x) => ['label' => $x['label'], 'detail' => '', 'rate' => $x['amount'], 'qty' => 1, 'amount' => $x['amount']], $extras)),
        'extras' => $extras, 'discount' => $discount, 'tax_percent' => $pct, 'tax_inclusive' => !empty($s['tax_inclusive']), 'tax_name' => $taxName,
        'net' => $net, 'tax' => $tax, 'tax_lines' => $taxLines, 'stay_total' => $stayTotal,
        'food' => $food, 'food_total' => $foodTotal, 'grand_total' => $grand, 'advance' => $advance,
        'paid' => $paid, 'pay_method' => (string)$b['pay_method'], 'paid_at' => $b['paid_at'],
        'food_due' => $foodDue, 'balance' => $paid ? $foodDue : round($grand - $advance, 2),
        'footer' => $s['bill_footer'], 'signature' => !empty($s['bill_signature']), 'show_food' => !empty($s['bill_food']),
        'policies' => !empty($s['bill_rules']) ? roomPolicies($s) : null,
    ];
}

/** The code in the link a guest opens to see their bill (sent on WhatsApp): website/room_bill.php?b=ID&k=CODE */
function roomBillCode(string $rid, int $id): string
{
    return substr(hash_hmac('sha256', 'roombill|' . $rid . '|' . $id, roomQrSecret()), 0, 16);
}

// ───────────────────────── Reports ─────────────────────────

/**
 * Rooms report for the nights from $from to $to (Y-m-d, both included): occupancy (room nights sold ÷ room nights
 * you had), average rate a sold night (ADR), revenue per room you had (RevPAR), room revenue, extra charges, room
 * service, by room type, by where bookings came from, night by night, cancellations, average stay, money to collect.
 * Sold = Confirmed, Checked in and Checked out stays; Pending requests are counted apart. Stays (count, extras, source)
 * are the ones that start in the period; nights and revenue count only the nights inside it.
 */
function roomsReport(PDO $conn, string $rid, string $from, string $to): array
{
    roomsEnsure($conn);
    $end = (new DateTime($to))->modify('+1 day')->format('Y-m-d');   // nights [from, end)
    $days = (int)(new DateTime($from))->diff(new DateTime($end))->days;
    $byType = [];
    foreach (roomTypes($conn, $rid) as $t) {
        $byType[$t['id']] = ['name' => $t['name'], 'rooms' => $t['room_count'], 'available' => $t['active'] ? $t['room_count'] * $days : 0, 'sold' => 0, 'revenue' => 0.0, 'stays' => 0];
    }
    $available = array_sum(array_column($byType, 'available'));
    $night = [];
    for ($d = new DateTime($from); $d->format('Y-m-d') < $end; $d->modify('+1 day')) $night[$d->format('Y-m-d')] = 0;

    $st = $conn->prepare('SELECT * FROM room_bookings WHERE restaurant_id = ? AND check_in < ? AND check_out > ?');
    $st->execute([$rid, $end, $from]);
    $sold = 0; $revenue = 0.0; $extras = 0.0; $discounts = 0.0; $stays = 0; $nightsAll = 0; $pending = 0; $cancelled = 0; $declined = 0;
    $source = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $startsHere = $b['check_in'] >= $from && $b['check_in'] < $end;
        if ($b['status'] === 'Pending') { $pending++; continue; }
        if ($b['status'] === 'Cancelled') { if ($startsHere) $cancelled++; continue; }
        if ($b['status'] === 'Declined') { if ($startsHere) $declined++; continue; }
        $tid = (int)$b['room_type_id'];
        $rooms = (int)$b['rooms'];
        $o = 0;
        $last = min($b['check_out'], $end);
        for ($d = new DateTime(max($b['check_in'], $from)); $d->format('Y-m-d') < $last; $d->modify('+1 day')) {
            $night[$d->format('Y-m-d')] += $rooms;
            $o++;
        }
        $money = $o * $rooms * (float)$b['price_per_night'];
        $sold += $o * $rooms;
        $revenue += $money;
        if (isset($byType[$tid])) { $byType[$tid]['sold'] += $o * $rooms; $byType[$tid]['revenue'] += $money; }
        if ($startsHere) {
            $stays++;
            $nightsAll += (int)(new DateTime($b['check_in']))->diff(new DateTime($b['check_out']))->days;
            if (isset($byType[$tid])) $byType[$tid]['stays']++;
            $src = $b['source'] === 'website' ? 'Website' : 'Added by you';
            $source[$src] = ($source[$src] ?? 0) + 1;
            $extras += array_sum(array_column(roomExtras($b['extras']), 'amount'));
            $discounts += (float)$b['discount'];
        }
    }
    $rs = $conn->prepare("SELECT COUNT(*) n, COALESCE(SUM(o.total), 0) t, COALESCE(SUM(CASE WHEN r.to_bill = 1 THEN o.total ELSE 0 END), 0) b
        FROM room_service_orders r JOIN orders o ON o.id = r.order_id
        WHERE r.restaurant_id = ? AND r.created_at >= ? AND r.created_at < ? AND o.order_status NOT IN ('Cancelled', 'Rejected') AND o.deleted_at IS NULL");
    $rs->execute([$rid, $from . ' 00:00:00', $end . ' 00:00:00']);
    $food = $rs->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 't' => 0, 'b' => 0];
    $due = $conn->prepare("SELECT COUNT(*) n, COALESCE(SUM(total), 0) t FROM room_bookings WHERE restaurant_id = ? AND paid = 0 AND status IN ('Checked in', 'Checked out')");
    $due->execute([$rid]);
    $due = $due->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 't' => 0];
    $r2 = fn($n) => round((float)$n, 2);
    $perNight = $days ? $available / $days : 0;
    return [
        'from' => $from, 'to' => $to, 'days' => $days, 'available' => $available, 'sold' => $sold,
        'occupancy' => $available ? round($sold * 100 / $available, 1) : 0,
        'adr' => $sold ? $r2($revenue / $sold) : 0, 'revpar' => $available ? $r2($revenue / $available) : 0,
        'revenue' => $r2($revenue), 'extras' => $r2($extras), 'discounts' => $r2($discounts),
        'room_service' => ['orders' => (int)$food['n'], 'total' => $r2($food['t']), 'on_bill' => $r2($food['b'])],
        'stays' => $stays, 'avg_nights' => $stays ? round($nightsAll / $stays, 1) : 0,
        'pending' => $pending, 'cancelled' => $cancelled, 'declined' => $declined,
        'unpaid' => ['stays' => (int)$due['n'], 'total' => $r2($due['t'])],
        'by_type' => array_values(array_map(fn($t) => array_merge($t, ['revenue' => $r2($t['revenue']), 'occupancy' => $t['available'] ? round($t['sold'] * 100 / $t['available'], 1) : 0]), $byType)),
        'by_source' => $source,
        'nights' => array_map(fn($d, $n) => ['day' => $d, 'sold' => $n, 'pct' => $perNight ? round($n * 100 / $perNight, 1) : 0], array_keys($night), $night),
    ];
}
