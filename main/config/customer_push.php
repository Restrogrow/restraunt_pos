<?php
/**
 * Push notifications for customers of the restaurant website: "accepted", "being prepared",
 * "ready", "on the way", "delivered", "cancelled" land on the customer's phone even with the
 * tracking page closed.
 *
 * The customer turns them on for one order (tracking page or the "Order placed" box,
 * website/rg-order-push.js). A phone that already allowed them is added to each new order
 * by itself. Rows are per order and deleted once the order is finished.
 *
 *   cpSubscribe($conn, $orderId, $rid, $sub)     save one browser for one order
 *   cpQueue($conn, $orderId, $event = null)      after the request: tell the customer the saved status
 *   cpNotify($conn, $orderId, $event = null)     send now ($event 'onway' when the rider picks it up)
 */
require_once __DIR__ . '/web_push.php';

if (!function_exists('cpNotify')) {

    function cpTable(PDO $conn): void
    {
        static $done = false;
        if ($done) return;
        $conn->exec('CREATE TABLE IF NOT EXISTS customer_push_subs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            restaurant_id VARCHAR(50) NOT NULL,
            endpoint VARCHAR(600) NOT NULL,
            p256dh VARCHAR(200) NOT NULL,
            auth VARCHAR(100) NOT NULL,
            endpoint_hash CHAR(40) NOT NULL,
            page_url VARCHAR(600) NULL,
            icon_url VARCHAR(600) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_order_endpoint (order_id, endpoint_hash),
            KEY idx_created (created_at)
        )');
        $done = true;
    }

    /** $page / $icon: the tracking page and logo addresses the customer's browser uses (opened from the notification). */
    function cpSubscribe(PDO $conn, int $orderId, string $rid, array $sub, string $page = '', string $icon = ''): void
    {
        cpTable($conn);
        $url = fn($u) => preg_match('#^https?://[^\s"<>]+$#i', $u) && strlen($u) <= 600 ? $u : null;
        $conn->prepare('INSERT INTO customer_push_subs (order_id, restaurant_id, endpoint, p256dh, auth, endpoint_hash, page_url, icon_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), page_url = VALUES(page_url), icon_url = VALUES(icon_url)')
             ->execute([$orderId, $rid, $sub['endpoint'], $sub['keys']['p256dh'], $sub['keys']['auth'], sha1($sub['endpoint']), $url($page), $url($icon)]);
        // orders a phone never finished (left open for days) don't pile up
        if (mt_rand(1, 50) === 1) $conn->exec('DELETE FROM customer_push_subs WHERE created_at < NOW() - INTERVAL 3 DAY');
    }

    /** What the customer reads for this status, or null for nothing to say. [title, text] */
    function cpMessage(string $status, string $type, ?string $event, string $rider): ?array
    {
        if ($event === 'onway') return ['Your order is on the way 🛵', $rider !== '' ? $rider . ' is bringing it to you.' : 'It has left the restaurant.'];
        $delivery = $type === 'Delivery';
        $takeaway = $type === 'Takeaway';
        switch ($status) {
            case 'Accepted':  return ['Order accepted ✅', 'The kitchen has your order and will start on it shortly.'];
            case 'Preparing': return ['Your food is being prepared 🍳', 'Freshly cooking now.'];
            case 'Ready':     return $delivery ? ['Packed and ready 📦', 'It leaves the restaurant shortly.']
                                 : ($takeaway ? ['Ready for pickup 🎉', 'Your order is waiting for you at the counter.'] : ['Your food is ready 🎉', 'It is on its way to your table.']);
            case 'Served':    return $delivery ? ['Delivered 🎉', 'Enjoy your meal!'] : ($takeaway ? ['Order collected 🙌', 'Enjoy your meal!'] : ['Served. Enjoy your meal 😋', 'Need anything else? Just ask.']);
            case 'Completed': return ['Thank you for ordering 🙏', 'Hope you loved it. See you again soon!'];
            case 'Cancelled': return ['Your order was cancelled', 'Sorry about that. If you paid online, the refund is on its way.'];
            case 'Rejected':  return ['Your order couldn\'t be taken', 'Sorry, the restaurant couldn\'t take this order right now.'];
        }
        return null;
    }

    function cpNotify(PDO $conn, int $orderId, ?string $event = null): void
    {
        cpTable($conn);
        $st = $conn->prepare('SELECT id, endpoint, p256dh, auth, page_url, icon_url FROM customer_push_subs WHERE order_id = ?');
        $st->execute([$orderId]);
        $subs = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$subs) return;

        $st = $conn->prepare('SELECT o.order_number, o.order_status, o.order_type, o.customer_phone, u.restaurant_name, u.username
                              FROM orders o JOIN users u ON u.restaurant_id = o.restaurant_id WHERE o.id = ? LIMIT 1');
        $st->execute([$orderId]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) return;
        $rider = '';
        if ($event === 'onway') {
            $t = $conn->prepare('SELECT rider_name FROM delivery_tracking WHERE order_id = ? LIMIT 1');
            $t->execute([$orderId]);
            $rider = trim((string)$t->fetchColumn());
        }
        $status = (string)$o['order_status'];
        $msg = cpMessage($status, (string)$o['order_type'], $event, $rider);
        if (!$msg) return;

        // the tracking page the customer turned this on from (their own address: custom domain or ours)
        $app = rtrim((string)(env('APP_URL', '') ?: 'https://restrogrow.com'), '/');
        $track = $app . '/' . rawurlencode((string)$o['username']) . '/track?order_number=' . rawurlencode((string)$o['order_number']) . '&customer_phone=' . rawurlencode((string)$o['customer_phone']);

        $vapid = wpVapidKeys($conn);
        foreach ($subs as $s) {
            $payload = json_encode([
                'title' => $msg[0],
                'body' => $msg[1] . "\n" . $o['restaurant_name'] . ' · #' . $o['order_number'],
                'icon' => $s['icon_url'] ?: null,
                'url' => $s['page_url'] ?: $track,
                'tag' => 'rg-order-' . $orderId,      // one notification per order, replaced by the newest
                'sticky' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $r = wpSend($vapid, $s['endpoint'], $s['p256dh'], $s['auth'], $payload, 6 * 3600);
            if ($r['expired']) $conn->prepare('DELETE FROM customer_push_subs WHERE id = ?')->execute([$s['id']]);
            elseif (!$r['ok']) error_log('Customer push failed for order ' . $orderId . ': ' . $r['error']);
        }
        // nothing more to say about a finished order
        if (in_array($status, ['Completed', 'Cancelled', 'Rejected'], true)) {
            $conn->prepare('DELETE FROM customer_push_subs WHERE order_id = ?')->execute([$orderId]);
        }
    }

    /**
     * Called on every status change (order_state_machine.php). Sends after the request is done and
     * reads the saved status then, so a rolled-back change sends nothing and several steps in one
     * request (Ready → Served → Completed) send one notification.
     */
    function cpQueue(PDO $conn, int $orderId, ?string $event = null): void
    {
        static $queued = [];
        if (!empty($queued[$orderId . ':' . $event])) return;
        $queued[$orderId . ':' . $event] = true;
        register_shutdown_function(function () use ($conn, $orderId, $event) {
            try {
                cpTable($conn);
                $n = $conn->prepare('SELECT COUNT(*) FROM customer_push_subs WHERE order_id = ?');
                $n->execute([$orderId]);
                if (!(int)$n->fetchColumn()) return;
                // the answer is already complete: don't keep the screen waiting on the push service
                if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                cpNotify($conn, $orderId, $event);
            } catch (Throwable $e) { error_log('cpNotify: ' . $e->getMessage()); }
        });
    }
}
