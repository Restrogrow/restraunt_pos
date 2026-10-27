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
