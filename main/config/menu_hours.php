<?php
/**
 * Menus by time of day: a category (table `menu`) can be served only on some days and between two
 * times, e.g. Breakfast 7–11 am. Empty = always.
 *   menu.avail_days  digits 1 (Mon) … 7 (Sun), e.g. "12345"; NULL or '' = every day
 *   menu.avail_from / menu.avail_to  TIME; both NULL = all day. "to" before "from" runs past midnight.
 *
 * Where it applies:
 *   - website menu (website/api.php): dishes of a category that's closed now come back with
 *     is_available = 0 and hours_label, so the page shows the hours instead of ADD
 *   - website orders (api/process_website_order.php): refused, checked at the order time (or the
 *     scheduled time)
 *   - POS: a badge on the category; the sale is allowed (the cashier decides)
 * Times are the restaurant's own timezone (users.timezone).
 */

function menuHoursEnsure(PDO $conn): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $have = [];
        foreach ($conn->query("SHOW COLUMNS FROM menu WHERE Field IN ('avail_days','avail_from','avail_to')") as $c) $have[$c['Field']] = true;
        if (count($have) < 3 && $conn->inTransaction()) { $ok = null; return false; }   // ALTER would commit the caller's transaction
        if (!isset($have['avail_days'])) $conn->exec("ALTER TABLE menu ADD COLUMN avail_days VARCHAR(7) NULL DEFAULT NULL");
        if (!isset($have['avail_from'])) $conn->exec("ALTER TABLE menu ADD COLUMN avail_from TIME NULL DEFAULT NULL");
        if (!isset($have['avail_to'])) $conn->exec("ALTER TABLE menu ADD COLUMN avail_to TIME NULL DEFAULT NULL");
        $ok = true;
    } catch (Throwable $e) {
        error_log('menuHoursEnsure: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

function menuHoursTz(PDO $conn, string $restaurantId): DateTimeZone {
    static $cache = [];
    if (!isset($cache[$restaurantId])) {
        $name = 'Asia/Kolkata';
        try {
            $s = $conn->prepare('SELECT timezone FROM users WHERE restaurant_id = ? LIMIT 1');
            $s->execute([$restaurantId]);
            $tz = (string)$s->fetchColumn();
            if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) $name = $tz;
        } catch (Throwable $e) {}
        $cache[$restaurantId] = new DateTimeZone($name);
    }
    return $cache[$restaurantId];
}

/** Does this category have any limit at all? */
function menuHoursLimited(array $m): bool {
    $days = preg_replace('/[^1-7]/', '', (string)($m['avail_days'] ?? ''));
    return ($days !== '' && strlen(count_chars($days, 3)) < 7) || (!empty($m['avail_from']) && !empty($m['avail_to']));
}

/** Is the category served at this moment (in the restaurant's time)? */
function menuHoursOpenAt(array $m, DateTime $at): bool {
    if (!menuHoursLimited($m)) return true;
    $days = preg_replace('/[^1-7]/', '', (string)($m['avail_days'] ?? ''));
    $dayOk = function (int $dow) use ($days) { return $days === '' || strpos($days, (string)$dow) !== false; };
    $from = substr((string)($m['avail_from'] ?? ''), 0, 5);
    $to = substr((string)($m['avail_to'] ?? ''), 0, 5);
    $dow = (int)$at->format('N');
    if ($from === '' || $to === '' || $from === $to) return $dayOk($dow);
    $t = $at->format('H:i');
    if ($from < $to) return $dayOk($dow) && $t >= $from && $t < $to;
    // past midnight (e.g. 22:00–02:00): late evening counts for today, early morning for yesterday
    if ($t >= $from) return $dayOk($dow);
    if ($t < $to) return $dayOk($dow === 1 ? 7 : $dow - 1);
    return false;
}

function menuHoursTimeLabel(string $hm): string {
    [$h, $i] = array_map('intval', explode(':', $hm));
    $ap = $h < 12 ? 'am' : 'pm';
    $h12 = $h % 12 ?: 12;
    return $h12 . ($i ? ':' . str_pad((string)$i, 2, '0', STR_PAD_LEFT) : '') . ' ' . $ap;
}

/** "Served 7–11 am", "Served 6 pm–1 am · Fri, Sat", "Served Mon–Fri"; '' when there is no limit. */
function menuHoursLabel(array $m): string {
    if (!menuHoursLimited($m)) return '';
    $bits = [];
    $from = substr((string)($m['avail_from'] ?? ''), 0, 5);
    $to = substr((string)($m['avail_to'] ?? ''), 0, 5);
    if ($from !== '' && $to !== '' && $from !== $to) {
        $a = menuHoursTimeLabel($from); $b = menuHoursTimeLabel($to);
        $sameHalf = substr($a, -2) === substr($b, -2) && $from < $to;
        $bits[] = ($sameHalf ? substr($a, 0, -3) : $a) . '–' . $b;
    }
    $days = count_chars(preg_replace('/[^1-7]/', '', (string)($m['avail_days'] ?? '')), 3);
    if ($days !== '' && strlen($days) < 7) {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        if ($days === '12345') $bits[] = 'Mon–Fri';
        elseif ($days === '67') $bits[] = 'Sat, Sun';
        elseif (strlen($days) >= 3 && strpos('1234567', $days) !== false) $bits[] = $names[(int)$days[0]] . '–' . $names[(int)substr($days, -1)];
        else $bits[] = implode(', ', array_map(function ($d) use ($names) { return $names[(int)$d]; }, str_split($days)));
    }
    return 'Served ' . implode(' · ', $bits);
}

/**
 * The categories that are NOT served at $at (default now): [menu_id => label]. Empty when no
 * category has hours (the usual case, one cheap query).
 */
function menuHoursClosedMap(PDO $conn, string $restaurantId, ?DateTime $at = null): array {
    if (!menuHoursEnsure($conn)) return [];
    try {
        $s = $conn->prepare("SELECT id, avail_days, avail_from, avail_to FROM menu WHERE restaurant_id = ?
            AND ((avail_days IS NOT NULL AND avail_days <> '') OR (avail_from IS NOT NULL AND avail_to IS NOT NULL))");
        $s->execute([$restaurantId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    if (!$rows) return [];
    $tz = menuHoursTz($conn, $restaurantId);
    $at = $at ? (clone $at)->setTimezone($tz) : new DateTime('now', $tz);
    $out = [];
    foreach ($rows as $m) if (!menuHoursOpenAt($m, $at)) $out[(int)$m['id']] = menuHoursLabel($m);
    return $out;
}

/** Website item lists: dishes of a closed category can't be added, and say when they're served. */
function menuHoursMarkItems(array $items, array $closed): array {
    if (!$closed) return $items;
    foreach ($items as &$it) {
        $mid = (int)($it['menu_id'] ?? 0);
        if ($mid && isset($closed[$mid])) { $it['is_available'] = 0; $it['hours_label'] = $closed[$mid]; }
    }
    unset($it);
    return $items;
}
