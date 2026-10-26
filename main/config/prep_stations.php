<?php
/**
 * Bar Order Tickets (BOT): drinks go to the bar on their own ticket, food to the kitchen on the KOT.
 *   menu.prep_station        'kitchen' (default) or 'bar': where a category's dishes are made
 *   menu_items.prep_station  NULL = same as its category, or 'kitchen' / 'bar' for this one dish
 *   kot.station              'kitchen' or 'bar'; a bar ticket is numbered BOT-YYYYMMDD-NNNN
 * One order can have a KOT and a BOT, both linked by kot.order_id. Each moves on its own
 * (the bar marks drinks ready, the kitchen the food); the order is Ready when every ticket is.
 *
 * Where it applies:
 *   - POS (controllers/pos_operations.php, the website POS and both apps): one send makes a KOT
 *     and/or a BOT
 *   - website / WhatsApp orders accepted (config/order_confirmation.php createDineInKOT)
 *   - kitchen screens (api/get_kot.php ?station=), printing (assets/js/script.js printKOT, which
 *     sends a BOT to the bar printer from Settings › Printer)
 * Order add-ons that aren't on a dish stay on the KOT. Nothing changes for a restaurant until it
 * marks something as made at the bar.
 */

const PREP_STATIONS = ['kitchen', 'bar'];

/** Adds the columns. Never inside a transaction (ALTER would commit it): returns false there. */
function stationEnsureSchema(PDO $conn): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $need = [];
        if (!$conn->query("SHOW COLUMNS FROM menu LIKE 'prep_station'")->fetch()) $need[] = "ALTER TABLE menu ADD COLUMN prep_station VARCHAR(10) NOT NULL DEFAULT 'kitchen'";
        if (!$conn->query("SHOW COLUMNS FROM menu_items LIKE 'prep_station'")->fetch()) $need[] = "ALTER TABLE menu_items ADD COLUMN prep_station VARCHAR(10) NULL DEFAULT NULL";
        if (!$conn->query("SHOW COLUMNS FROM kot LIKE 'station'")->fetch()) $need[] = "ALTER TABLE kot ADD COLUMN station VARCHAR(10) NOT NULL DEFAULT 'kitchen', ADD INDEX idx_kot_station (restaurant_id, station)";
        if ($need && $conn->inTransaction()) return false;
        foreach ($need as $sql) $conn->exec($sql);
        $ok = true;
    } catch (Throwable $e) {
        error_log('stationEnsureSchema: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

/** Are the columns there? Read-only, safe inside a transaction. */
function stationReady(PDO $conn): bool {
    static $ready = null;
    if ($ready === true) return true;
    try {
        $ready = (bool)$conn->query("SHOW COLUMNS FROM kot LIKE 'station'")->fetch()
              && (bool)$conn->query("SHOW COLUMNS FROM menu_items LIKE 'prep_station'")->fetch()
              && (bool)$conn->query("SHOW COLUMNS FROM menu LIKE 'prep_station'")->fetch();
    } catch (Throwable $e) { $ready = false; }
    return $ready;
}

function stationClean($v, bool $allowInherit = false): ?string {
    $v = strtolower(trim((string)$v));
    if (in_array($v, PREP_STATIONS, true)) return $v;
    return $allowInherit ? null : 'kitchen';
}

/** Does this restaurant send anything to the bar? */
function stationHasBar(PDO $conn, string $rid): bool {
    if (!stationReady($conn)) return false;
    try {
        $s = $conn->prepare("SELECT 1 FROM menu_items i JOIN menu m ON m.id = i.menu_id
            WHERE i.restaurant_id = ? AND COALESCE(i.prep_station, m.prep_station) = 'bar' LIMIT 1");
        $s->execute([$rid]);
        return (bool)$s->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** menu_item_id => 'bar' for the dishes made at the bar (anything not listed: kitchen). */
function stationBarItems(PDO $conn, string $rid, array $menuItemIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $menuItemIds), function ($i) { return $i > 0; })));
    if (!$ids || !stationReady($conn)) return [];
    try {
        $s = $conn->prepare("SELECT i.id FROM menu_items i JOIN menu m ON m.id = i.menu_id
            WHERE i.restaurant_id = ? AND i.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
              AND COALESCE(i.prep_station, m.prep_station) = 'bar'");
        $s->execute(array_merge([$rid], $ids));
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $out[(int)$id] = 'bar';
        return $out;
    } catch (Throwable $e) {
        error_log('stationBarItems: ' . $e->getMessage());
        return [];
    }
}

/**
 * Does the restaurant have an active Bartender? Then the bar has its own screen, and a chef's
 * screen (api/get_kot.php) shows only kitchen tickets. Without one the chef sees the bar's too.
 */
function stationHasBartender(PDO $conn, string $rid): bool {
    try {
        $s = $conn->prepare("SELECT 1 FROM staff WHERE restaurant_id = ? AND role = 'Bartender' AND is_active = 1 LIMIT 1");
        $s->execute([$rid]);
        return (bool)$s->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** A unique ticket number: KOT-20261025-4821 for the kitchen, BOT-20261025-1093 for the bar. */
function stationTicketNumber(PDO $conn, string $rid, string $station = 'kitchen'): string {
    $prefix = $station === 'bar' ? 'BOT' : 'KOT';
    $check = $conn->prepare('SELECT COUNT(*) FROM kot WHERE kot_number = ? AND restaurant_id = ?');
    for ($i = 0; $i < 100; $i++) {
        $n = $prefix . '-' . date('Ymd') . '-' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        try { $check->execute([$n, $rid]); if (!$check->fetchColumn()) return $n; }
        catch (PDOException $e) { return $n; }
    }
    return $prefix . '-' . date('YmdHis') . '-' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
}

/** Saves a ticket's station (no-op on a database without the column). */
function stationMarkTicket(PDO $conn, int $kotId, string $station): void {
    if ($station === 'kitchen' || !$kotId || !stationReady($conn)) return;
    $conn->prepare('UPDATE kot SET station = ? WHERE id = ?')->execute([$station, $kotId]);
}

/**
 * The order's other tickets still being made: an order (and the waiter's "ready" moment) waits
 * for both the kitchen and the bar. $exceptKotId is the ticket being moved now.
 */
function stationOtherTicketsOpen(PDO $conn, int $orderId, int $exceptKotId): int {
    if (!$orderId) return 0;
    try {
        $s = $conn->prepare("SELECT COUNT(*) FROM kot WHERE order_id = ? AND id <> ? AND kot_status IN ('Pending', 'Preparing')");
        $s->execute([$orderId, $exceptKotId]);
        return (int)$s->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
