<?php
// Order tracking links without the customer's phone number in them.
// A link is ?order_number=ORD-…&k=<code>; the code proves it came from us, so a shared or screenshotted
// link never shows the phone. track.php still accepts the old ?customer_phone= links (sent in past messages).
require_once __DIR__ . '/env_loader.php';

function orderTrackCode(string $orderNumber, string $phone): string
{
    $secret = (string)env('WA_KEY_SECRET', '') . '|' . (string)env('DB_PASS', '') . '|' . (string)env('DB_NAME', '');
    return substr(hash_hmac('sha256', 'track|' . $orderNumber . '|' . $phone, $secret), 0, 16);
}

// the order's phone when the code matches, else null
function orderTrackPhoneFromCode(PDO $conn, string $orderNumber, string $code): ?string
{
    if ($orderNumber === '' || !preg_match('/^[0-9a-f]{16}$/', $code)) return null;
    $st = $conn->prepare("SELECT customer_phone FROM orders WHERE order_number = ? AND source = 'website' LIMIT 1");
    $st->execute([$orderNumber]);
    $phone = $st->fetchColumn();
    if ($phone === false || $phone === null) return null;
    return hash_equals(orderTrackCode($orderNumber, (string)$phone), $code) ? (string)$phone : null;
}
