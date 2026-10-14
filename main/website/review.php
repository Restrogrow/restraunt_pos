<?php
/**
 * Short review link: website/review.php?r=<restaurant id>&s=<qr|link|wa|bill|site>
 * Counts the scan / tap (dashboard › Google Business › Get reviews) and sends the customer to
 * the restaurant's Google review form (config/gbp_helpers.php gbpReviewUrl), else its Maps page.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/gbp_helpers.php';

$rid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['r'] ?? ''));
$src = in_array($_GET['s'] ?? '', ['qr', 'link', 'wa', 'bill', 'site', 'table'], true) ? $_GET['s'] : 'link';
$dest = '';
try {
    $conn = getConnection();
    $u = $conn->prepare('SELECT google_maps_link FROM users WHERE restaurant_id = ? LIMIT 1');
    $u->execute([$rid]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if ($user !== false) {
        $dest = gbpReviewUrl(gbpRow($conn, $rid), $user['google_maps_link'] ?? null);
        // one count per visitor per hour, so a refresh doesn't add up
        $seen = 'rgrv_' . md5($rid . $src);
        if (preg_match('#^https://#', $dest) && empty($_COOKIE[$seen])) {   // count only scans that reach Google
            $conn->prepare('INSERT INTO gbp_link_hits (restaurant_id, source) VALUES (?, ?)')->execute([$rid, $src]);
            setcookie($seen, '1', ['expires' => time() + 3600, 'path' => '/', 'samesite' => 'Lax']);
        }
    }
} catch (Throwable $e) {
    error_log('review link: ' . $e->getMessage());
}
if ($dest === '' || !preg_match('#^https://#', $dest)) {
    http_response_code(404);
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><p style="font-family:sans-serif;padding:24px">This review link isn’t set up yet.</p>';
    exit;
}
header('Location: ' . $dest, true, 302);
