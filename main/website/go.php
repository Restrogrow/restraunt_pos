<?php
/**
 * A tracked link:  restrogrow.com/<slug>/go/<code>  or  <custom domain>/go/<code>  (config/link_tracking.php).
 * Counts the click (one per visitor per link per hour), then sends the visitor to the restaurant's page with
 * ?src=<tag>&coupon=<code>. ?m=<outbox id, base 36> marks which WhatsApp customer opened it, and for a
 * message that carried a personal gift, that customer's own code is filled in on the menu.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/link_tracking.php';

$code = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['c'] ?? ''));
$dest = '';
try {
    $conn = getConnection();
    tlEnsure($conn);
    $st = $conn->prepare('SELECT * FROM tracked_links WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    $l = $code !== '' ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if ($l) {
        $rid = $l['restaurant_id'];
        $coupon = null;
        $outboxId = null;
        if (preg_match('/^[0-9a-z]{1,8}$/', (string)($_GET['m'] ?? ''))) {
            $oid = (int)base_convert((string)$_GET['m'], 36, 10);
            $ob = $conn->prepare('SELECT id, ref_type, ref_id, phone FROM wa_outbox WHERE id = ? AND restaurant_id = ?');
            try { $ob->execute([$oid, $rid]); $row = $ob->fetch(PDO::FETCH_ASSOC); } catch (PDOException $e) { $row = false; }
            if ($row) {
                $outboxId = (int)$row['id'];
                // the gift this customer got in that message: open the menu with it already applied
                try {
                    if ($row['ref_type'] === 'gift') {
                        $g = $conn->prepare("SELECT coupon_code FROM loyalty_rewards_issued WHERE id = ? AND restaurant_id = ?");
                        $g->execute([(int)$row['ref_id'], $rid]);
                    } else {
                        $g = $conn->prepare("SELECT coupon_code FROM loyalty_rewards_issued WHERE restaurant_id = ? AND phone = ? AND period_key = ? ORDER BY id DESC LIMIT 1");
                        $g->execute([$rid, $row['phone'], 'c' . (int)$row['ref_id']]);
                    }
                    $coupon = $g->fetchColumn() ?: null;
                } catch (PDOException $e) { $coupon = null; }
                try { $conn->prepare('UPDATE wa_outbox SET clicked_at = COALESCE(clicked_at, NOW()) WHERE id = ?')->execute([$outboxId]); } catch (PDOException $e) {}
            }
        }
        $dest = tlDestination($conn, $l, $coupon);

        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        // link previews (WhatsApp, Facebook…) and crawlers open links too: they don't count
        $bot = $ua === '' || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|skype|slack|discord|curl|wget|python|headless/i', $ua);
        $seen = 'rgl_' . substr(md5($code . '|' . $outboxId), 0, 10);
        if (!$bot && empty($_COOKIE[$seen])) {
            $ip = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
            $visitor = substr(hash('sha256', $rid . '|' . date('Y-m-d') . '|' . $ip . '|' . $ua), 0, 16);
            $device = preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : (preg_match('/Mobi|Android|iPhone/i', $ua) ? 'mobile' : 'desktop');
            $conn->prepare('INSERT INTO link_clicks (link_id, restaurant_id, ts, day, visitor, device, outbox_id) VALUES (?, ?, NOW(), CURDATE(), ?, ?, ?)')
                 ->execute([(int)$l['id'], $rid, $visitor, $device, $outboxId]);
            setcookie($seen, '1', ['expires' => time() + 3600, 'path' => '/', 'samesite' => 'Lax']);
        }
    }
} catch (Throwable $e) {
    error_log('go link: ' . $e->getMessage());
}
if ($dest === '' || !preg_match('#^https?://#', $dest)) {
    http_response_code(404);
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Link not found</title><p style="font-family:sans-serif;padding:24px">This link has expired or was typed wrong.</p>';
    exit;
}
header('Cache-Control: no-store');
header('Location: ' . $dest, true, 302);
