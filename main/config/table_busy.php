<?php
/**
 * Which tables are busy right now (api/get_table_status.php, api/tables_studio.php).
 *
 * A table is busy while either is true (last 24 hours only, so a forgotten bill can't block it forever):
 *  - it has an open order (not cancelled / rejected / completed / deleted, not a parked "Hold") that
 *    isn't paid yet: the guests are still there until the bill is settled;
 *  - it has a kitchen ticket still Pending / Preparing whose order isn't closed: food is on its way
 *    (covers bills paid up front and website QR orders paid online).
 * Settling an existing dine-in bill closes its tickets (rgCloseOrderKots), so a paid table frees up even
 * when the kitchen never taps the tickets through.
 *
 * Returns [table_id => ['since' => earliest start, 'total' => running bill, 'kots' => open tickets]].
 */
function rgBusyTables(PDO $conn, string $rid): array
{
    $out = [];
    $add = function ($tid, $since) use (&$out) {
        $tid = (int)$tid;
        if (!isset($out[$tid])) $out[$tid] = ['since' => $since, 'total' => 0.0, 'kots' => [], 'orders' => []];
        elseif ($since && (!$out[$tid]['since'] || $since < $out[$tid]['since'])) $out[$tid]['since'] = $since;
    };

    $o = $conn->prepare("SELECT id, table_id, created_at, total FROM orders
        WHERE restaurant_id = ? AND table_id IS NOT NULL AND deleted_at IS NULL
          AND order_status IN ('Pending', 'Accepted', 'Preparing', 'Ready', 'Served')
          AND payment_status IN ('Pending', 'Partially Paid')
          AND NOT (COALESCE(notes, '') = 'Order on hold' AND order_status = 'Pending')
          AND created_at >= NOW() - INTERVAL 1 DAY");
    $o->execute([$rid]);
    foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $add($r['table_id'], $r['created_at']);
        $out[(int)$r['table_id']]['orders'][(int)$r['id']] = (float)$r['total'];
    }

    $k = $conn->prepare("SELECT k.table_id, k.order_id, k.kot_number, k.created_at, k.total, k.kot_status FROM kot k
        LEFT JOIN orders o ON o.id = k.order_id AND o.restaurant_id = k.restaurant_id
        WHERE k.restaurant_id = ? AND k.table_id IS NOT NULL AND k.kot_status IN ('Pending', 'Preparing')
          AND k.created_at >= NOW() - INTERVAL 1 DAY
          AND (k.order_id IS NULL OR (o.id IS NOT NULL AND o.deleted_at IS NULL AND o.order_status NOT IN ('Cancelled', 'Rejected', 'Completed')))
        ORDER BY k.created_at ASC");
    $k->execute([$rid]);
    $kotOnly = [];
    foreach ($k->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tid = (int)$r['table_id'];
        $add($tid, $r['created_at']);
        $out[$tid]['kots'][] = ['table_id' => $tid, 'kot_number' => $r['kot_number'], 'created_at' => $r['created_at'], 'total' => $r['total'], 'kot_status' => $r['kot_status']];
        // a ticket with no unpaid order behind it adds its own amount to the running bill
        if (empty($r['order_id']) || !isset($out[$tid]['orders'][(int)$r['order_id']])) $kotOnly[$tid] = ($kotOnly[$tid] ?? 0) + (float)$r['total'];
    }

    foreach ($out as $tid => &$t) {
        $t['total'] = round(array_sum($t['orders']) + ($kotOnly[$tid] ?? 0), 2);
        unset($t['orders']);
    }
    unset($t);
    return $out;
}

/**
 * A dine-in bill that was already open is now settled: its kitchen tickets are done, so the table frees
 * up. $keepKotId is a ticket made by this same request (dishes added at Pay), which still goes to the kitchen.
 */
function rgCloseOrderKots(PDO $conn, string $rid, int $orderId, int $keepKotId = 0): void
{
    try {
        $conn->prepare("UPDATE kot SET kot_status = 'Completed', updated_at = CURRENT_TIMESTAMP
            WHERE restaurant_id = ? AND order_id = ? AND id <> ? AND kot_status IN ('Pending', 'Preparing', 'Ready')")
             ->execute([$rid, $orderId, $keepKotId]);
    } catch (PDOException $e) {
        error_log('rgCloseOrderKots order ' . $orderId . ': ' . $e->getMessage());
    }
}
