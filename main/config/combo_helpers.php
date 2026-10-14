<?php
/**
 * Combos & Deals (Offers › Combos & Deals): real meal deals at one price.
 *
 * A combo is a name, a price and slots. Each slot is "pick 1 of these dishes" (one dish = fixed),
 * served `qty` times, and each choice can be a size of the dish and cost extra (+30 for the bigger
 * one). It can run on some days, between two times and between two dates.
 *
 * Used by api/combos_admin.php (dashboard), website/api.php getCombos (the website row and sheet)
 * and api/process_website_order.php (comboVerify: the server works out the price, never the browser).
 *
 * An order stores a combo as one priced line (no dish, variation 'Combo') and one line per dish at 0
 * ('in <combo>'), so the bill shows the combo price, the kitchen gets every dish and stock follows
 * each dish. combo_sales keeps how many sold for the dashboard.
 */

function ensureCombosTables(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if ($conn->inTransaction()) { $done = false; return; }   // DDL commits: only outside a transaction
    $conn->exec("CREATE TABLE IF NOT EXISTS combos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(50) NOT NULL,
        name VARCHAR(120) NOT NULL,
        description VARCHAR(300) DEFAULT NULL,
        image_path VARCHAR(255) DEFAULT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        badge VARCHAR(40) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        valid_from DATE DEFAULT NULL,
        valid_until DATE DEFAULT NULL,
        days VARCHAR(7) NOT NULL DEFAULT '1234567',
        time_from TIME DEFAULT NULL,
        time_to TIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_combos_rid (restaurant_id, is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->exec("CREATE TABLE IF NOT EXISTS combo_slots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        combo_id INT NOT NULL,
        title VARCHAR(80) NOT NULL,
        qty INT NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        INDEX idx_combo_slots (combo_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->exec("CREATE TABLE IF NOT EXISTS combo_slot_options (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slot_id INT NOT NULL,
        menu_item_id INT NOT NULL,
        variation_name VARCHAR(100) DEFAULT NULL,
        extra_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        INDEX idx_combo_opts (slot_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->exec("CREATE TABLE IF NOT EXISTS combo_sales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(50) NOT NULL,
        combo_id INT NOT NULL,
        order_id INT NOT NULL,
        qty INT NOT NULL DEFAULT 1,
        revenue DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_combo_sales (restaurant_id, combo_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Is the combo on sale at this moment? Returns [bool live, string why-not / when]. */
function comboLiveState(array $c, ?DateTime $at = null): array
{
    $at = $at ?: new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    if (empty($c['is_active'])) return [false, 'Off'];
    $d = $at->format('Y-m-d');
    if (!empty($c['valid_from']) && $d < $c['valid_from']) return [false, 'Starts ' . date('j M', strtotime($c['valid_from']))];
    if (!empty($c['valid_until']) && $d > $c['valid_until']) return [false, 'Ended ' . date('j M', strtotime($c['valid_until']))];
    $days = (string)($c['days'] ?? '1234567');
    if ($days !== '' && strpos($days, $at->format('N')) === false) return [false, 'Not today'];
    $from = $c['time_from'] ?? null; $to = $c['time_to'] ?? null;
    if ($from && $to) {
        $t = $at->format('H:i:s');
        $in = $from <= $to ? ($t >= $from && $t < $to) : ($t >= $from || $t < $to);   // 22:00-02:00 crosses midnight
        if (!$in) return [false, 'From ' . date('g:i a', strtotime($from))];
    }
    return [true, ''];
}

/**
 * Every combo of a restaurant with its slots and choices (dish name, image, type, price, available).
 * $liveOnly: only combos on sale now whose every slot still has an available dish (the website).
 */
function getCombos(PDO $conn, string $rid, bool $liveOnly = false): array
{
    ensureCombosTables($conn);
    $st = $conn->prepare('SELECT * FROM combos WHERE restaurant_id = ? ORDER BY sort_order, id');
    $st->execute([$rid]);
    $combos = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$combos) return [];
    $ids = array_column($combos, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $sl = $conn->prepare("SELECT * FROM combo_slots WHERE combo_id IN ($ph) ORDER BY sort_order, id");
    $sl->execute($ids);
    $slots = $sl->fetchAll(PDO::FETCH_ASSOC);
    $opts = [];
    if ($slots) {
        $sids = array_column($slots, 'id');
        $ph2 = implode(',', array_fill(0, count($sids), '?'));
        // dish details come from the menu, scoped to this restaurant
        $op = $conn->prepare("SELECT o.*, m.item_name_en, m.item_image, m.item_type, m.base_price, m.is_available, m.menu_id,
                v.price AS variation_price, v.is_available AS variation_available
            FROM combo_slot_options o
            JOIN menu_items m ON m.id = o.menu_item_id AND m.restaurant_id = ?
            LEFT JOIN menu_item_variations v ON v.menu_item_id = o.menu_item_id AND v.variation_name = o.variation_name COLLATE utf8mb4_unicode_ci
            WHERE o.slot_id IN ($ph2) ORDER BY o.sort_order, o.id");
        $op->execute(array_merge([$rid], $sids));
        foreach ($op->fetchAll(PDO::FETCH_ASSOC) as $o) $opts[(int)$o['slot_id']][] = $o;
    }

    // categories not served right now (Menu › Category › Hours) make their dishes unavailable here too
    $closed = [];
    if ($liveOnly && is_file(__DIR__ . '/menu_hours.php')) {
        require_once __DIR__ . '/menu_hours.php';
        if (function_exists('menuHoursClosedMap')) { try { $closed = menuHoursClosedMap($conn, $rid, null); } catch (Throwable $e) { $closed = []; } }
    }

    $bySlot = [];
    foreach ($slots as $s) $bySlot[(int)$s['combo_id']][] = $s;
    $out = [];
    foreach ($combos as $c) {
        [$live, $why] = comboLiveState($c);
        if ($liveOnly && !$live) continue;
        $cSlots = []; $worth = 0; $ok = true;
        foreach ($bySlot[(int)$c['id']] ?? [] as $s) {
            $choices = [];
            foreach ($opts[(int)$s['id']] ?? [] as $o) {
                $price = $o['variation_name'] !== null && $o['variation_name'] !== '' && $o['variation_price'] !== null ? (float)$o['variation_price'] : (float)$o['base_price'];
                $available = (int)$o['is_available'] === 1 && !isset($closed[(int)$o['menu_id']])
                    && ($o['variation_price'] === null || (int)$o['variation_available'] !== 0);   // a switched-off size counts too
                if ($liveOnly && !$available) continue;
                $choices[] = [
                    'id' => (int)$o['id'], 'menu_item_id' => (int)$o['menu_item_id'],
                    'name' => $o['item_name_en'], 'variation' => $o['variation_name'] ?: null,
                    'image' => $o['item_image'] ?: null, 'type' => $o['item_type'] ?: null,
                    'price' => round($price, 2), 'extra' => round((float)$o['extra_price'], 2), 'available' => $available,
                ];
            }
            if (!$choices) { $ok = false; if ($liveOnly) break; }
            $qty = max(1, (int)$s['qty']);
            // what the slot is worth: its first (default) dish at menu price
            if ($choices) $worth += $choices[0]['price'] * $qty;
            $cSlots[] = ['id' => (int)$s['id'], 'title' => $s['title'], 'qty' => $qty, 'choices' => $choices];
        }
        if ($liveOnly && (!$ok || !$cSlots)) continue;
        $price = round((float)$c['price'], 2);
        $out[] = [
            'id' => (int)$c['id'], 'name' => $c['name'], 'description' => $c['description'] ?: '',
            'image' => $c['image_path'] ?: null, 'price' => $price, 'worth' => round($worth, 2),
            'save' => max(0, round($worth - $price, 2)), 'badge' => $c['badge'] ?: null,
            'is_active' => (int)$c['is_active'], 'sort_order' => (int)$c['sort_order'],
            'valid_from' => $c['valid_from'], 'valid_until' => $c['valid_until'], 'days' => $c['days'],
            'time_from' => $c['time_from'] ? substr($c['time_from'], 0, 5) : null, 'time_to' => $c['time_to'] ? substr($c['time_to'], 0, 5) : null,
            'live' => $live, 'state' => $live ? 'Live' : $why, 'slots' => $cSlots,
            // the website counts down when a deal stops today
            'ends_at' => $live ? comboEndsAt($c) : null,
        ];
    }
    return $out;
}

/** When a live combo stops today (its time window or its last date), as an ISO time, else null. */
function comboEndsAt(array $c): ?string
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $tz);
    $ends = null;
    if (!empty($c['time_to']) && !empty($c['time_from'])) {
        $e = new DateTime($now->format('Y-m-d') . ' ' . $c['time_to'], $tz);
        if ($e <= $now) $e->modify('+1 day');   // window crossing midnight
        $ends = $e;
    }
    if (!empty($c['valid_until']) && $c['valid_until'] === $now->format('Y-m-d')) {
        $e = new DateTime($c['valid_until'] . ' 23:59:59', $tz);
        if (!$ends || $e < $ends) $ends = $e;
    }
    if (!$ends || $ends->getTimestamp() - $now->getTimestamp() > 12 * 3600) return null;   // only a countdown under 12 h
    return $ends->format(DATE_ATOM);
}

/**
 * Checks one combo from a cart and prices it from the database.
 * $picks: slot id => choice id (slots with one choice may be left out).
 * Returns ['ok'=>true, 'name', 'unit_price', 'lines'=>[[menu_item_id, name, variation, qty]]] or ['ok'=>false, 'message'].
 */
function comboVerify(array $combosById, int $comboId, array $picks): array
{
    $c = $combosById[$comboId] ?? null;
    if (!$c) return ['ok' => false, 'message' => 'A combo in your cart is not on offer right now. Please remove it.'];
    $unit = (float)$c['price'];
    $lines = [];
    foreach ($c['slots'] as $s) {
        $want = (int)($picks[$s['id']] ?? $picks[(string)$s['id']] ?? 0);
        $choice = null;
        foreach ($s['choices'] as $ch) if ($ch['id'] === $want) $choice = $ch;
        if (!$choice && count($s['choices']) === 1) $choice = $s['choices'][0];
        if (!$choice) return ['ok' => false, 'message' => 'Please choose "' . $s['title'] . '" again for ' . $c['name'] . ': that choice is no longer available.'];
        $unit += (float)$choice['extra'];
        $lines[] = ['menu_item_id' => $choice['menu_item_id'], 'name' => $choice['name'], 'variation' => $choice['variation'], 'qty' => (int)$s['qty']];
    }
    return ['ok' => true, 'name' => $c['name'], 'unit_price' => round($unit, 2), 'lines' => $lines];
}

/** Saves one combo from the dashboard builder. Returns the combo id. Throws InvalidArgumentException with a message. */
function saveCombo(PDO $conn, string $rid, array $in): int
{
    ensureCombosTables($conn);
    $name = trim(mb_substr(strip_tags((string)($in['name'] ?? '')), 0, 120));
    if ($name === '') throw new InvalidArgumentException('Give the combo a name.');
    $price = round((float)($in['price'] ?? 0), 2);
    if ($price <= 0) throw new InvalidArgumentException('Set the combo price.');
    $slots = is_array($in['slots'] ?? null) ? $in['slots'] : [];
    if (!$slots) throw new InvalidArgumentException('Add at least one dish to the combo.');
    if (count($slots) > 12) throw new InvalidArgumentException('A combo can have up to 12 parts.');

    // every dish must be this restaurant's (and the size must exist)
    $dishIds = [];
    foreach ($slots as $s) foreach ((array)($s['choices'] ?? []) as $o) $dishIds[] = (int)($o['menu_item_id'] ?? 0);
    $dishIds = array_values(array_unique(array_filter($dishIds)));
    if (!$dishIds) throw new InvalidArgumentException('Add at least one dish to the combo.');
    $ph = implode(',', array_fill(0, count($dishIds), '?'));
    $st = $conn->prepare("SELECT id FROM menu_items WHERE restaurant_id = ? AND id IN ($ph)");
    $st->execute(array_merge([$rid], $dishIds));
    $mine = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $vs = $conn->prepare("SELECT menu_item_id, variation_name FROM menu_item_variations WHERE menu_item_id IN ($ph)");
    $vs->execute($dishIds);
    $sizes = [];
    foreach ($vs->fetchAll(PDO::FETCH_ASSOC) as $v) $sizes[(int)$v['menu_item_id']][$v['variation_name']] = true;

    $date = function ($v) { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; };
    $time = function ($v) { $v = trim((string)$v); return preg_match('/^\d{2}:\d{2}$/', $v) ? $v . ':00' : null; };
    $days = preg_replace('/[^1-7]/', '', (string)($in['days'] ?? '1234567'));
    $days = $days === '' ? '1234567' : implode('', array_unique(str_split($days)));
    $tFrom = $time($in['time_from'] ?? ''); $tTo = $time($in['time_to'] ?? '');
    if (($tFrom === null) !== ($tTo === null) || ($tFrom !== null && $tFrom === $tTo)) { $tFrom = $tTo = null; }
    $vFrom = $date($in['valid_from'] ?? ''); $vUntil = $date($in['valid_until'] ?? '');
    if ($vFrom && $vUntil && $vUntil < $vFrom) throw new InvalidArgumentException('The end date is before the start date.');
    $image = trim((string)($in['image'] ?? ''));
    if ($image !== '' && !preg_match('#^uploads/combos/[A-Za-z0-9_\-]+\.(jpg|jpeg|png|webp)$#', $image)) $image = '';

    $id = (int)($in['id'] ?? 0);
    $conn->beginTransaction();
    try {
        $fields = [$name, mb_substr(trim(strip_tags((string)($in['description'] ?? ''))), 0, 300) ?: null, $image ?: null, $price,
            mb_substr(trim(strip_tags((string)($in['badge'] ?? ''))), 0, 40) ?: null, !empty($in['is_active']) ? 1 : 0,
            $vFrom, $vUntil, $days, $tFrom, $tTo];
        if ($id) {
            $own = $conn->prepare('SELECT id FROM combos WHERE id = ? AND restaurant_id = ?');
            $own->execute([$id, $rid]);
            if (!$own->fetchColumn()) throw new InvalidArgumentException('That combo was not found.');
            $conn->prepare('UPDATE combos SET name=?, description=?, image_path=?, price=?, badge=?, is_active=?, valid_from=?, valid_until=?, days=?, time_from=?, time_to=? WHERE id=? AND restaurant_id=?')
                ->execute(array_merge($fields, [$id, $rid]));
            $old = $conn->prepare('SELECT id FROM combo_slots WHERE combo_id = ?');
            $old->execute([$id]);
            $oldSlots = $old->fetchAll(PDO::FETCH_COLUMN);
            if ($oldSlots) {
                $ph3 = implode(',', array_fill(0, count($oldSlots), '?'));
                $conn->prepare("DELETE FROM combo_slot_options WHERE slot_id IN ($ph3)")->execute($oldSlots);
                $conn->prepare('DELETE FROM combo_slots WHERE combo_id = ?')->execute([$id]);
            }
        } else {
            $next = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM combos WHERE restaurant_id = ?');
            $next->execute([$rid]);
            $conn->prepare('INSERT INTO combos (name, description, image_path, price, badge, is_active, valid_from, valid_until, days, time_from, time_to, restaurant_id, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge($fields, [$rid, (int)$next->fetchColumn()]));
            $id = (int)$conn->lastInsertId();
        }
        $insSlot = $conn->prepare('INSERT INTO combo_slots (combo_id, title, qty, sort_order) VALUES (?, ?, ?, ?)');
        $insOpt = $conn->prepare('INSERT INTO combo_slot_options (slot_id, menu_item_id, variation_name, extra_price, sort_order) VALUES (?, ?, ?, ?, ?)');
        $n = 0;
        foreach ($slots as $s) {
            $choices = array_values(array_filter((array)($s['choices'] ?? []), function ($o) use ($mine) { return in_array((int)($o['menu_item_id'] ?? 0), $mine, true); }));
            if (!$choices) continue;
            $title = trim(mb_substr(strip_tags((string)($s['title'] ?? '')), 0, 80)) ?: (count($choices) > 1 ? 'Pick one' : 'Included');
            $insSlot->execute([$id, $title, max(1, min(20, (int)($s['qty'] ?? 1))), $n++]);
            $sid = (int)$conn->lastInsertId();
            foreach (array_slice($choices, 0, 30) as $k => $o) {
                $dish = (int)$o['menu_item_id'];
                $size = trim((string)($o['variation'] ?? ''));
                if ($size !== '' && empty($sizes[$dish][$size])) $size = '';
                $insOpt->execute([$sid, $dish, $size !== '' ? $size : null, max(0, round((float)($o['extra'] ?? 0), 2)), $k]);
            }
        }
        if (!$n) throw new InvalidArgumentException('Add at least one dish to the combo.');
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    return $id;
}
