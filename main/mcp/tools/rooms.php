<?php
/**
 * Rooms over the API: what a restaurant lets, and the photos guests see (config/rooms.php).
 *
 * The Rooms page is the only place a room photo could be added until now, which left an owner who
 * has the pictures but cannot get to a browser with nowhere to put them. These tools add the same
 * two steps the page does — read the room types, attach a photo — through an API key, so a script
 * or an AI agent can finish the job.
 *
 * Photos go through roomPhotoStore(), the same checking and shrinking as the page's own upload, and
 * come back as the uploads/rooms path that roomPhotoValid() accepts. That matters: save_type in
 * api/rooms_admin.php silently drops any photo value of another shape, so a photo written any other
 * way would vanish the next time the owner edited that room.
 *
 * Both tools belong to the Rooms page, so Super Admin switching Rooms off for a restaurant turns
 * them off too (the 'page' key below), exactly like the dashboard.
 */

const ROOMS_API_MAX_PHOTOS = 8;   // ROOM_MAX_PHOTOS is defined alongside the feature; this is the API's own cap

function roomsTools(): array
{
    $t = fn(array $def, string $perm, ?string $page, ?array $route) => ['def' => $def, 'perm' => $perm, 'page' => $page, 'route' => $route ? $route + [2 => 'Rooms'] : null];
    return [
        $t(mcpDef('list_rooms', 'Rooms', 'Every room type the restaurant lets: name, price a night, how many guests it sleeps, how many rooms of it there are, its photos, and whether it is switched on. Also says whether the Rooms page is live on the website.',
            [], [], 'read', 'Reading the rooms…', 'Rooms loaded'), 'rooms:read', 'roomsPage', ['GET', 'rooms']),
        $t(mcpDef('add_room_photo', 'Add a room photo', 'Add a photo to one room type (JPEG, PNG or WebP, at most 6 MB). Pass room_id from list_rooms. The photo is added after the ones already there; a room keeps at most ' . ROOMS_API_MAX_PHOTOS . '.',
            ['room_id' => ['type' => 'integer', 'description' => 'Which room type, from list_rooms.'],
             'image' => ['type' => 'string', 'description' => 'The photo: a data URL (data:image/jpeg;base64,…) or a public https link.']],
            ['room_id', 'image'], 'create', 'Adding the photo…', 'Photo added'), 'rooms:write', 'roomsPage', ['POST', 'rooms/{room_id}/photos']),
    ];
}

function roomsRun(PDO $conn, array $ctx, string $name, array $a): array
{
    require_once __DIR__ . '/../../config/rooms.php';
    $rid = $ctx['rid'];
    roomsEnsure($conn);

    $show = function (array $r): array {
        $photos = array_values(array_filter((array)json_decode((string)$r['photos'], true), fn($p) => is_string($p) && roomPhotoValid($p)));
        return [
            'room_id' => (int)$r['id'],
            'name' => $r['name'],
            'description' => $r['description'],
            'price_per_night' => (float)$r['price_per_night'],
            'max_guests' => (int)$r['max_guests'],
            'room_count' => (int)$r['room_count'],
            'active' => !empty($r['is_active']),
            'photos' => array_map(fn($p) => 'https://restrogrow.com/main/' . $p, $photos),
            'photo_count' => count($photos),
        ];
    };

    switch ($name) {
        case 'list_rooms': {
            $st = $conn->prepare('SELECT * FROM room_types WHERE restaurant_id = ? ORDER BY sort_order, id');
            $st->execute([$rid]);
            $rooms = array_map($show, $st->fetchAll(PDO::FETCH_ASSOC));
            $live = roomsSwitchOn($conn, $rid);
            $text = $rooms
                ? count($rooms) . ' room type' . (count($rooms) === 1 ? '' : 's') . ': ' . implode(', ', array_map(fn($r) => $r['name'] . ' ' . mcpMoney($ctx, $r['price_per_night']), $rooms))
                    . '. The Rooms page is ' . ($live ? 'live on the website.' : 'switched off, so guests cannot see them yet.')
                : 'No room types yet.';
            return [$text, ['rooms_page_live' => $live, 'rooms' => $rooms]];
        }

        case 'add_room_photo': {
            // the shared demo account keeps the pictures it was built with (config/demo_restaurant.php)
            require_once __DIR__ . '/../../config/demo_restaurant.php';
            if ($rid === DEMO_RESTAURANT_ID) throw new McpToolError('The demo restaurant keeps its room photos.');

            $st = $conn->prepare('SELECT * FROM room_types WHERE id = ? AND restaurant_id = ?');
            $st->execute([(int)($a['room_id'] ?? 0), $rid]);
            $room = $st->fetch(PDO::FETCH_ASSOC);
            if (!$room) throw new McpToolError('No room type with that room_id. Call list_rooms to see them.');

            $photos = array_values(array_filter((array)json_decode((string)$room['photos'], true), fn($p) => is_string($p) && roomPhotoValid($p)));
            if (count($photos) >= ROOMS_API_MAX_PHOTOS) throw new McpToolError($room['name'] . ' already has ' . ROOMS_API_MAX_PHOTOS . ' photos, the most a room can show.');

            require_once __DIR__ . '/../../config/api_media.php';
            $img = apiImageFrom((string)($a['image'] ?? ''), 6 * 1048576, 'Photo');
            try {
                $path = roomPhotoStore($img['bytes'], $rid);
            } catch (InvalidArgumentException $e) {
                throw new McpToolError($e->getMessage());
            }
            $photos[] = $path;

            $up = $conn->prepare('UPDATE room_types SET photos = ? WHERE id = ? AND restaurant_id = ?');
            $up->execute([json_encode($photos), (int)$room['id'], $rid]);

            logActivity($conn, $rid, 'rooms.photo', 'Added a photo to ' . $room['name'] . ' via ' . $ctx['app'], ['via' => $ctx['app']], null, null, mcpActor($ctx));

            $st->execute([(int)$room['id'], $rid]);
            $after = $show($st->fetch(PDO::FETCH_ASSOC));
            return ['✅ Photo added to ' . $room['name'] . ' (' . $after['photo_count'] . ' now).', ['room' => $after]];
        }
    }
    throw new McpToolError('Unknown rooms tool: ' . $name);
}
