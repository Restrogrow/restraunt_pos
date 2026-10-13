<?php
/**
 * Times the customer sees on the tracking page, from what the restaurant chose when accepting:
 * "Ready around 7:45 PM" (estimated_ready_at) and, for delivery, "Arrives around 8:10 PM"
 * (estimated_delivery_at). Both clocks come from the database, so they share its time zone.
 */
function orderEta(PDO $conn, int $orderId): array
{
    try {
        $st = $conn->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
        $st->execute([$orderId]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) return [];
        $now = strtotime((string)$conn->query("SELECT NOW()")->fetchColumn());
        $out = [];
        foreach (['ready' => 'estimated_ready_at', 'arrive' => 'estimated_delivery_at'] as $k => $col) {
            if (empty($o[$col])) continue;
            $t = strtotime($o[$col]);
            if (!$t) continue;
            $out[$k] = ['at' => date('g:i A', $t), 'in_min' => (int)ceil(($t - $now) / 60)];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** One line for the tracking page, or '' when there is nothing useful to say. */
function orderEtaText(array $eta, string $status, string $orderType, ?string $deliveryStatus = null): string
{
    $s = strtolower($status);
    $delivered = $deliveryStatus && preg_match('/deliver/i', $deliveryStatus) && !preg_match('/out|way|pick/i', $deliveryStatus);
    if (in_array($s, ['cancelled', 'rejected', 'completed', 'served'], true) || $delivered) return '';
    $isDel = stripos($orderType, 'deliver') !== false;
    if ($isDel && !empty($eta['arrive'])) {
        return $eta['arrive']['in_min'] > 0 ? 'Arrives around ' . $eta['arrive']['at'] : 'Arriving any minute (expected ' . $eta['arrive']['at'] . ')';
    }
    if (!empty($eta['ready']) && in_array($s, ['accepted', 'preparing'], true)) {
        return $eta['ready']['in_min'] > 0 ? 'Ready around ' . $eta['ready']['at'] : 'Almost ready (expected ' . $eta['ready']['at'] . ')';
    }
    return '';
}
