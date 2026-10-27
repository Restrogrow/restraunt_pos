<?php
/**
 * Rooms page (dashboard, assets/js/rooms-studio.js): room types and room bookings (config/rooms.php).
 *
 *   GET                                  switch, room types, bookings (from 30 days ago on), today's numbers
 *   GET ?free=1&in=Y-m-d&out=Y-m-d       rooms free per type for those nights (the "Add booking" form)
 *   POST {action:'enable', on}           Rooms on the website on / off (users.enable_rooms)
 *   POST {action:'save_type', type}      create / edit a room type (photos: paths from 'upload')
 *   POST {action:'delete_type', id}      only when no upcoming booking holds it
 *   POST {action:'status', id, status, room_numbers?, owner_note?}
 *   POST {action:'paid', id, paid}
 *   POST {action:'add_booking', booking} a phone / walk-in booking, Confirmed straight away
 *   POST multipart action=upload, photo  a room photo -> uploads/rooms/...
 * Needs the "manage reservations" permission to change anything.
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}
$rid = (string)$_SESSION['restaurant_id'];
$canEdit = hasPermission(PERMISSION_MANAGE_RESERVATIONS);
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/rooms.php';
require_once __DIR__ . '/../config/demo_restaurant.php';

function rmOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $conn = getConnection();
    roomsEnsure($conn);
    $q = function (string $sql, array $args = []) use ($conn) { $st = $conn->prepare($sql); $st->execute($args); return $st; };
    $u = $q('SELECT currency_symbol, timezone FROM users WHERE restaurant_id = ? LIMIT 1', [$rid])->fetch(PDO::FETCH_ASSOC) ?: [];
    try { $tz = new DateTimeZone($u['timezone'] ?: 'Asia/Kolkata'); } catch (Throwable $e) { $tz = new DateTimeZone('Asia/Kolkata'); }
    $today = (new DateTime('today', $tz))->format('Y-m-d');
    $clean = fn($v, int $n) => mb_substr(trim(strip_tags((string)$v)), 0, $n);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if (!empty($_GET['free'])) {
            [$in, $out] = roomDates((string)($_GET['in'] ?? ''), (string)($_GET['out'] ?? ''), $tz, true);
            rmOut(['success' => true, 'free' => roomsFree($conn, $rid, $in, $out)]);
        }
        $from = (new DateTime('today', $tz))->modify('-30 days')->format('Y-m-d');
        $bookings = $q('SELECT b.*, t.name AS type_name FROM room_bookings b LEFT JOIN room_types t ON t.id = b.room_type_id
            WHERE b.restaurant_id = ? AND (b.check_out >= ? OR b.status IN (\'Pending\', \'Checked in\')) ORDER BY b.check_in, b.id LIMIT 500', [$rid, $from])->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($bookings as $b) {
            $out[] = ['id' => (int)$b['id'], 'type_id' => (int)$b['room_type_id'], 'type' => (string)($b['type_name'] ?? 'Room'),
                'name' => $b['guest_name'], 'phone' => $b['guest_phone'], 'email' => (string)$b['guest_email'],
                'in' => $b['check_in'], 'out' => $b['check_out'], 'nights' => (int)(new DateTime($b['check_in']))->diff(new DateTime($b['check_out']))->days,
                'guests' => (int)$b['guests'], 'rooms' => (int)$b['rooms'], 'price' => (float)$b['price_per_night'], 'total' => (float)$b['total'],
                'status' => $b['status'], 'paid' => (int)$b['paid'] === 1, 'room_numbers' => (string)$b['room_numbers'],
                'notes' => (string)$b['notes'], 'owner_note' => (string)$b['owner_note'], 'source' => $b['source'], 'created' => $b['created_at']];
        }
        // today: who arrives, who leaves, rooms in use tonight
        $types = roomTypes($conn, $rid);
        $tomorrow = (new DateTime('tomorrow', $tz))->format('Y-m-d');
        $freeTonight = roomsFree($conn, $rid, $today, $tomorrow);
        $total = array_sum(array_map(fn($t) => $t['active'] ? $t['room_count'] : 0, $types));
        $freeActive = array_sum(array_map(fn($t) => $t['active'] ? ($freeTonight[$t['id']] ?? 0) : 0, $types));
        rmOut(['success' => true, 'enabled' => roomsSwitchOn($conn, $rid), 'can_edit' => $canEdit,
            'currency' => (string)($u['currency_symbol'] ?: '₹'), 'today' => $today, 'types' => $types, 'bookings' => $out,
            'stats' => ['arrivals' => count(array_filter($out, fn($b) => $b['in'] === $today && in_array($b['status'], ['Pending', 'Confirmed'], true))),
                        'departures' => count(array_filter($out, fn($b) => $b['out'] === $today && $b['status'] === 'Checked in')),
                        'pending' => count(array_filter($out, fn($b) => $b['status'] === 'Pending')),
                        'rooms' => $total, 'occupied' => $total - $freeActive]]);
    }

    if (!$canEdit) rmOut(['success' => false, 'message' => 'You don’t have permission to change rooms.'], 403);

    if (($_POST['action'] ?? '') === 'upload') {
        if (isDemoRestaurantSession()) rmOut(['success' => false, 'message' => 'This is turned off in the demo restaurant.']);
        rmOut(['success' => true, 'path' => roomPhotoSave($_FILES['photo'] ?? [], $rid)]);
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');
    $own = function (string $table, $id) use ($q, $rid) { return $q("SELECT * FROM $table WHERE id = ? AND restaurant_id = ?", [(int)$id, $rid])->fetch(PDO::FETCH_ASSOC) ?: null; };

    if ($action === 'enable') {
        $q('UPDATE users SET enable_rooms = ? WHERE restaurant_id = ?', [empty($in['on']) ? 0 : 1, $rid]);
        rmOut(['success' => true, 'enabled' => !empty($in['on'])]);
    }

    if ($action === 'save_type') {
        $t = (array)($in['type'] ?? []);
        $id = (int)($t['id'] ?? 0);
        if ($id && !$own('room_types', $id)) rmOut(['success' => false, 'message' => 'Room type not found'], 404);
        $name = $clean($t['name'] ?? '', 100);
        if ($name === '') rmOut(['success' => false, 'message' => 'Give the room type a name, like "Deluxe Double".'], 400);
        $price = round((float)($t['price'] ?? 0), 2);
        if ($price < 0 || $price > 10000000) rmOut(['success' => false, 'message' => 'Check the price per night.'], 400);
        $guests = max(1, min(20, (int)($t['max_guests'] ?? 2)));
        $count = max(1, min(500, (int)($t['room_count'] ?? 1)));
        $numbers = implode(', ', array_slice(array_filter(array_map(fn($n) => $clean($n, 12), preg_split('/[,\s]+/', (string)($t['room_numbers'] ?? '')))), 0, 100));
        $amen = implode(', ', array_slice(array_filter(array_map(fn($a) => $clean($a, 40), (array)($t['amenities'] ?? []))), 0, 20));
        $photos = array_values(array_slice(array_filter((array)($t['photos'] ?? []), fn($p) => is_string($p) && roomPhotoValid($p)), 0, ROOM_MAX_PHOTOS));
        $args = [$name, $clean($t['description'] ?? '', 1000), $price, $guests, $count, $numbers ?: null, $amen ?: null, json_encode($photos), empty($t['active']) && isset($t['active']) ? 0 : 1];
        if ($id) {
            $q('UPDATE room_types SET name = ?, description = ?, price_per_night = ?, max_guests = ?, room_count = ?, room_numbers = ?, amenities = ?, photos = ?, is_active = ? WHERE id = ? AND restaurant_id = ?', array_merge($args, [$id, $rid]));
        } else {
            $sort = (int)$q('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM room_types WHERE restaurant_id = ?', [$rid])->fetchColumn();
            $q('INSERT INTO room_types (name, description, price_per_night, max_guests, room_count, room_numbers, amenities, photos, is_active, restaurant_id, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($args, [$rid, $sort]));
            $id = (int)$conn->lastInsertId();
        }
        rmOut(['success' => true, 'id' => $id]);
    }

    if ($action === 'delete_type') {
        $t = $own('room_types', $in['id'] ?? 0);
        if (!$t) rmOut(['success' => false, 'message' => 'Room type not found'], 404);
        $busy = (int)$q("SELECT COUNT(*) FROM room_bookings WHERE room_type_id = ? AND status IN ('Pending', 'Confirmed', 'Checked in') AND check_out >= ?", [$t['id'], $today])->fetchColumn();
        if ($busy) rmOut(['success' => false, 'message' => "It has $busy upcoming booking" . ($busy === 1 ? '' : 's') . '. Switch it off instead, or move those bookings first.'], 400);
        $q('DELETE FROM room_types WHERE id = ? AND restaurant_id = ?', [$t['id'], $rid]);
        foreach ((array)json_decode((string)$t['photos'], true) as $p) {
            if (is_string($p) && roomPhotoValid($p)) @unlink(__DIR__ . '/../' . $p);
        }
        rmOut(['success' => true]);
    }

    if ($action === 'status') {
        $b = $own('room_bookings', $in['id'] ?? 0);
        if (!$b) rmOut(['success' => false, 'message' => 'Booking not found'], 404);
        $status = (string)($in['status'] ?? '');
        if (!in_array($status, ROOM_STATUSES, true)) rmOut(['success' => false, 'message' => 'Unknown status'], 400);
        // a declined / cancelled booking taken back has to fit again
        if (in_array($status, ROOM_HOLDING, true) && !in_array($b['status'], ROOM_HOLDING, true)) {
            $free = roomsFree($conn, $rid, $b['check_in'], $b['check_out'], (int)$b['id']);
            if (($free[(int)$b['room_type_id']] ?? 0) < (int)$b['rooms']) rmOut(['success' => false, 'message' => 'Those dates are full now for this room type.'], 400);
        }
        $q('UPDATE room_bookings SET status = ?, room_numbers = COALESCE(?, room_numbers), owner_note = COALESCE(?, owner_note) WHERE id = ? AND restaurant_id = ?',
            [$status, isset($in['room_numbers']) ? $clean($in['room_numbers'], 100) : null, isset($in['owner_note']) ? $clean($in['owner_note'], 500) : null, $b['id'], $rid]);
        rmOut(['success' => true]);
    }

    if ($action === 'paid') {
        $b = $own('room_bookings', $in['id'] ?? 0);
        if (!$b) rmOut(['success' => false, 'message' => 'Booking not found'], 404);
        $q('UPDATE room_bookings SET paid = ? WHERE id = ? AND restaurant_id = ?', [empty($in['paid']) ? 0 : 1, $b['id'], $rid]);
        rmOut(['success' => true]);
    }

    if ($action === 'add_booking') {
        $b = (array)($in['booking'] ?? []);
        $t = $own('room_types', $b['room_type_id'] ?? 0);
        if (!$t) rmOut(['success' => false, 'message' => 'Choose a room type.'], 400);
        [$ci, $co, $nights] = roomDates((string)($b['in'] ?? ''), (string)($b['out'] ?? ''), $tz, true);
        $name = $clean($b['name'] ?? '', 120);
        $phone = preg_replace('/\D/', '', (string)($b['phone'] ?? ''));
        if ($name === '' || strlen($phone) < 7 || strlen($phone) > 15) rmOut(['success' => false, 'message' => 'Enter the guest’s name and phone number.'], 400);
        $rooms = max(1, min(50, (int)($b['rooms'] ?? 1)));
        $guests = max(1, min(200, (int)($b['guests'] ?? 1)));
        $free = roomsFree($conn, $rid, $ci, $co);
        if (($free[(int)$t['id']] ?? 0) < $rooms) rmOut(['success' => false, 'message' => 'Only ' . ($free[(int)$t['id']] ?? 0) . ' room(s) of this type are free for those nights.'], 400);
        $price = isset($b['price']) && $b['price'] !== '' ? max(0, round((float)$b['price'], 2)) : (float)$t['price_per_night'];
        $q('INSERT INTO room_bookings (restaurant_id, room_type_id, guest_name, guest_phone, guest_email, check_in, check_out, guests, rooms, price_per_night, total, status, room_numbers, notes, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$rid, $t['id'], $name, $phone, filter_var($b['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null, $ci, $co, $guests, $rooms, $price, round($price * $nights * $rooms, 2),
             'Confirmed', $clean($b['room_numbers'] ?? '', 100) ?: null, $clean($b['notes'] ?? '', 500) ?: null, 'dashboard']);
        rmOut(['success' => true, 'id' => (int)$conn->lastInsertId()]);
    }

    rmOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (InvalidArgumentException $e) {
    rmOut(['success' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('rooms_admin.php: ' . $e->getMessage());
    rmOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
