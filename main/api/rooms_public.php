<?php
/**
 * Rooms on the customer website ({site}/rooms, website/rooms.php; config/rooms.php).
 *
 *   GET  ?restaurant_id=…[&in=Y-m-d&out=Y-m-d]   the restaurant's rooms, and with dates how many are free; and its
 *        policies (check-in / check-out times, cancellation, house rules, extra bed price: roomPolicies)
 *   POST {restaurant_id, room_type_id, in, out, guests, rooms, name, phone, email?, notes?}
 *        a booking request: Pending until the restaurant confirms it on its Rooms page
 * Only while the restaurant has Rooms on. Pay at the hotel: nothing is charged here.
 * Rate limited per IP and per phone, like table reservations.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/rooms.php';

function rpOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $conn = getConnection();
    $body = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
    $rid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($body['restaurant_id'] ?? $_GET['restaurant_id'] ?? ''));
    if (!roomsFeatureEnabled($conn, $rid)) rpOut(['success' => false, 'message' => 'Rooms are not open for booking here.'], 404);
    $u = $conn->prepare('SELECT currency_symbol, timezone FROM users WHERE restaurant_id = ? LIMIT 1');
    $u->execute([$rid]);
    $u = $u->fetch(PDO::FETCH_ASSOC) ?: [];
    try { $tz = new DateTimeZone($u['timezone'] ?: 'Asia/Kolkata'); } catch (Throwable $e) { $tz = new DateTimeZone('Asia/Kolkata'); }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $types = array_map(function ($t) {
            unset($t['room_numbers']);   // the owner's own numbering stays private
            return $t;
        }, roomTypes($conn, $rid, true));
        $free = null; $nights = 0;
        if (!empty($_GET['in']) || !empty($_GET['out'])) {
            [$in, $out, $nights] = roomDates((string)($_GET['in'] ?? ''), (string)($_GET['out'] ?? ''), $tz);
            $free = roomsFree($conn, $rid, $in, $out);
        }
        foreach ($types as &$t) {
            $t['free'] = $free === null ? null : (int)($free[$t['id']] ?? 0);
            unset($t['room_count'], $t['active']);
        }
        unset($t);
        rpOut(['success' => true, 'currency' => (string)($u['currency_symbol'] ?: '₹'), 'nights' => $nights, 'rooms' => $types,
               'policies' => roomPolicies(roomSettings($conn, $rid))]);
    }

    // ── a booking request ──
    $name = mb_substr(trim(strip_tags((string)($body['name'] ?? ''))), 0, 120);
    $phone = preg_replace('/\D/', '', (string)($body['phone'] ?? ''));
    $email = trim((string)($body['email'] ?? ''));
    if ($name === '') rpOut(['success' => false, 'message' => 'Please enter your name.'], 400);
    if (strlen($phone) < 7 || strlen($phone) > 15) rpOut(['success' => false, 'message' => 'Please enter a valid phone number.'], 400);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) rpOut(['success' => false, 'message' => 'Please check your email address.'], 400);
    [$in, $out, $nights] = roomDates((string)($body['in'] ?? ''), (string)($body['out'] ?? ''), $tz);
    $rooms = max(1, min(10, (int)($body['rooms'] ?? 1)));
    $guests = max(1, min(50, (int)($body['guests'] ?? 1)));

    require_once __DIR__ . '/../config/rate_limit.php';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    foreach ([['room_ip_' . $ip, 6, 3600], ['room_phone_' . $phone, 4, 86400]] as [$key, $max, $win]) {
        if (!checkRateLimit($key, $max, $win)['allowed']) rpOut(['success' => false, 'message' => 'Too many bookings. Please call us to book more rooms.'], 429);
    }

    $conn->beginTransaction();
    // the room type row is locked while we count, so two guests can't take the last room at once
    $st = $conn->prepare('SELECT * FROM room_types WHERE id = ? AND restaurant_id = ? AND is_active = 1 FOR UPDATE');
    $st->execute([(int)($body['room_type_id'] ?? 0), $rid]);
    $type = $st->fetch(PDO::FETCH_ASSOC);
    if (!$type) { $conn->rollBack(); rpOut(['success' => false, 'message' => 'Please choose a room.'], 400); }
    if ($guests > (int)$type['max_guests'] * $rooms) {
        $conn->rollBack();
        rpOut(['success' => false, 'message' => $type['name'] . ' fits up to ' . (int)$type['max_guests'] . ' guest' . ((int)$type['max_guests'] === 1 ? '' : 's') . ' a room. Add a room or choose a bigger one.'], 400);
    }
    $free = roomsFree($conn, $rid, $in, $out);
    if (($free[(int)$type['id']] ?? 0) < $rooms) {
        $conn->rollBack();
        $left = (int)($free[(int)$type['id']] ?? 0);
        rpOut(['success' => false, 'message' => $left ? "Only $left room" . ($left === 1 ? '' : 's') . ' of this type left for those dates.' : 'This room is fully booked for those dates. Try other dates or another room.'], 409);
    }
    $price = (float)$type['price_per_night'];
    $booking = ['guest_name' => $name, 'check_in' => $in, 'check_out' => $out];
    $conn->prepare('INSERT INTO room_bookings (restaurant_id, room_type_id, guest_name, guest_phone, guest_email, check_in, check_out, guests, rooms, price_per_night, total, status, notes, source)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'Pending\', ?, \'website\')')
         ->execute([$rid, $type['id'], $name, $phone, $email ?: null, $in, $out, $guests, $rooms, $price, round($price * $nights * $rooms, 2),
                    mb_substr(trim(strip_tags((string)($body['notes'] ?? ''))), 0, 500) ?: null]);
    $id = (int)$conn->lastInsertId();
    $conn->commit();
    roomBookingNotify($conn, $rid, $booking, (string)$type['name']);
    rpOut(['success' => true, 'id' => $id, 'nights' => $nights, 'total' => round($price * $nights * $rooms, 2), 'room' => $type['name']]);
} catch (InvalidArgumentException $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    rpOut(['success' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('rooms_public.php: ' . $e->getMessage());
    rpOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
