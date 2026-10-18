<?php
/**
 * Marketing studio (dashboard): brand kit, saved designs and campaign results.
 *
 *   GET  ?view=brand              the brand kit (defaults from the restaurant profile and website colours)
 *   GET  ?view=designs            saved designs, newest first, with each one's results
 *   GET  ?view=design&id=N        one saved design with its editor state
 *   POST {action:'brand', ...}    save the brand kit
 *   POST {action:'save', id?, kind, name, src, coupon_code, state, thumb}   save a design
 *   POST {action:'delete', id}    delete a saved design
 *
 * Results per campaign tag (src): people who opened the link (page_visits rows whose address
 * has src=<tag>, counted once per visitor a day), website orders placed after opening it
 * (marketing_order_src) and, when a coupon is attached, every order that used the code since
 * the design was made (website and POS). Cancelled / rejected orders don't count.
 * Tables: config/marketing_helpers.php.
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}
$rid = (string)$_SESSION['restaurant_id'];
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/marketing_helpers.php';
require_once __DIR__ . '/../config/link_tracking.php';

function mkOut(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function mkTry(callable $f) { try { return $f(); } catch (Throwable $e) { return null; } }
function mkColor($c, $fallback) { return preg_match('/^#[0-9a-fA-F]{6}$/', (string)$c) ? strtoupper($c) : $fallback; }

try {
    $conn = getConnection();
    mkEnsureTables($conn);
    $q = function (string $sql, array $args = []) use ($conn) { $st = $conn->prepare($sql); $st->execute($args); return $st; };

    $brand = function () use ($q, $rid) {
        $row = $q('SELECT * FROM marketing_brand_kit WHERE restaurant_id = ?', [$rid])->fetch(PDO::FETCH_ASSOC);
        $u = mkTry(function () use ($q, $rid) { return $q('SELECT phone, address FROM users WHERE restaurant_id = ? ORDER BY id LIMIT 1', [$rid])->fetch(PDO::FETCH_ASSOC); }) ?: [];
        $w = mkTry(function () use ($q, $rid) { return $q('SELECT primary_red FROM website_settings WHERE restaurant_id = ? LIMIT 1', [$rid])->fetch(PDO::FETCH_ASSOC); }) ?: [];
        $kit = [
            'saved' => (bool)$row,
            'logo' => $row['logo'] ?? '',
            'color1' => $row['color1'] ?? mkColor($w['primary_red'] ?? '', '#F97316'),
            'color2' => $row['color2'] ?? '#111827',
            'font' => $row['font'] ?? 'modern',
            'phone' => $row['phone'] ?? (string)($u['phone'] ?? ''),
            'address' => $row['address'] ?? mb_substr((string)($u['address'] ?? ''), 0, 160),
            'tagline' => $row['tagline'] ?? '',
            'hashtags' => $row['hashtags'] ?? '',
            'language' => $row['language'] ?? 'en',
        ];
        return $kit;
    };

    // results for a set of designs: visits, orders and sales per campaign tag / coupon
    $results = function (array $designs) use ($q, $rid, $conn) {
        $out = [];
        $notCancelled = "o.order_status NOT IN ('Cancelled','Rejected')";
        foreach ($designs as $d) {
            $src = (string)$d['src']; $code = (string)$d['coupon_code']; $since = $d['created_at'];
            $r = ['visits' => 0, 'orders' => 0, 'sales' => 0.0, 'coupon_orders' => 0];
            if ($src !== '') {
                $like = '%src=' . str_replace(['%', '_'], ['\\%', '\\_'], $src) . '%';
                $r['visits'] = (int)(mkTry(function () use ($q, $rid, $like) {
                    return $q("SELECT COUNT(DISTINCT visitor_ip, DATE(visited_at)) FROM page_visits WHERE restaurant_id = ? AND page_url LIKE ?", [$rid, $like])->fetchColumn();
                }) ?? 0);
                $row = mkTry(function () use ($q, $rid, $src, $notCancelled) {
                    return $q("SELECT COUNT(*) n, COALESCE(SUM(o.total),0) s FROM marketing_order_src m JOIN orders o ON o.id = m.order_id WHERE m.restaurant_id = ? AND m.src = ? AND $notCancelled", [$rid, $src])->fetch(PDO::FETCH_ASSOC);
                });
                if ($row) { $r['orders'] = (int)$row['n']; $r['sales'] = (float)$row['s']; }
            }
            if ($code !== '') {
                $row = mkTry(function () use ($q, $rid, $code, $since, $src, $notCancelled) {
                    // coupon orders that the link didn't already count
                    return $q("SELECT COUNT(*) n, COALESCE(SUM(o.total),0) s FROM orders o
                        LEFT JOIN marketing_order_src m ON m.order_id = o.id AND m.src = ?
                        WHERE o.restaurant_id = ? AND o.coupon_code = ? AND o.created_at >= ? AND $notCancelled AND m.order_id IS NULL", [$src, $rid, $code, $since])->fetch(PDO::FETCH_ASSOC);
                });
                if ($row) { $r['coupon_orders'] = (int)$row['n']; $r['orders'] += (int)$row['n']; $r['sales'] += (float)$row['s']; }
            }
            // taps on the design's short link (restrogrow.com/<slug>/go/<code>, also behind its QR code)
            if ($src !== '') {
                $tl = mkTry(function () use ($q, $rid, $src) { return $q("SELECT * FROM tracked_links WHERE restaurant_id = ? AND kind = 'mk' AND ref = ?", [$rid, $src])->fetch(PDO::FETCH_ASSOC); });
                if ($tl) {
                    $ts = tlStats($conn, $rid, [$tl])[(int)$tl['id']] ?? [];
                    $r['clicks'] = (int)($ts['clicks'] ?? 0); $r['people'] = (int)($ts['people'] ?? 0); $r['last_click'] = $ts['last_click'] ?? null;
                    $r['spark'] = tlDaily($conn, $rid, (int)$tl['id'], 14);
                    $r['short'] = tlShortUrl($conn, $rid, $tl['code']);
                }
            }
            $out[(int)$d['id']] = $r;
        }
        return $out;
    };

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $view = $_GET['view'] ?? 'designs';
        if ($view === 'brand') mkOut(['success' => true, 'brand' => $brand()]);
        // the tracked short link for a design's tag (and coupon): what posters, QR codes, videos and captions print
        if ($view === 'link') {
            $src = mkCleanSrc((string)($_GET['src'] ?? ''));
            if ($src === '') mkOut(['success' => false, 'message' => 'No link tag'], 400);
            $l = tlLink($conn, $rid, 'mk', $src, ['label' => (string)($_GET['label'] ?? $src), 'target' => 'menu', 'src' => $src, 'coupon' => (string)($_GET['coupon'] ?? '')]);
            mkOut(['success' => true, 'short' => $l['short'], 'code' => $l['code']]);
        }
        if ($view === 'design') {
            $row = $q('SELECT id, kind, name, src, coupon_code, state FROM marketing_designs WHERE id = ? AND restaurant_id = ?', [(int)($_GET['id'] ?? 0), $rid])->fetch(PDO::FETCH_ASSOC);
            if (!$row) mkOut(['success' => false, 'message' => 'That design was not found.'], 404);
            $row['state'] = json_decode((string)$row['state'], true) ?: new stdClass();
            mkOut(['success' => true, 'design' => $row]);
        }
        $rows = $q('SELECT id, kind, name, src, coupon_code, thumb, created_at, updated_at FROM marketing_designs WHERE restaurant_id = ? ORDER BY updated_at DESC LIMIT 200', [$rid])->fetchAll(PDO::FETCH_ASSOC);
        $res = $results($rows);
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['results'] = $res[$r['id']] ?? null; }
        unset($r);
        $cur = (string)(mkTry(function () use ($q, $rid) { return $q('SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1', [$rid])->fetchColumn(); }) ?: '₹');
        mkOut(['success' => true, 'designs' => $rows, 'currency' => $cur]);
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $in['action'] ?? '';

    if ($action === 'brand') {
        $logo = (string)($in['logo'] ?? '');
        if ($logo !== '' && (!preg_match('#^data:image/(png|jpeg|webp);base64,#', $logo) || strlen($logo) > 400000)) mkOut(['success' => false, 'message' => 'The logo must be a PNG, JPG or WebP under 300 KB.'], 400);
        $font = in_array($in['font'] ?? '', ['modern', 'elegant', 'bold'], true) ? $in['font'] : 'modern';
        $lang = in_array($in['language'] ?? '', ['en', 'hinglish', 'hi'], true) ? $in['language'] : 'en';
        $q('INSERT INTO marketing_brand_kit (restaurant_id, logo, color1, color2, font, phone, address, tagline, hashtags, language) VALUES (?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE logo = VALUES(logo), color1 = VALUES(color1), color2 = VALUES(color2), font = VALUES(font), phone = VALUES(phone),
            address = VALUES(address), tagline = VALUES(tagline), hashtags = VALUES(hashtags), language = VALUES(language)', [
            $rid, $logo, mkColor($in['color1'] ?? '', '#F97316'), mkColor($in['color2'] ?? '', '#111827'), $font,
            mb_substr(trim((string)($in['phone'] ?? '')), 0, 30), mb_substr(trim((string)($in['address'] ?? '')), 0, 160),
            mb_substr(trim((string)($in['tagline'] ?? '')), 0, 80), mb_substr(trim((string)($in['hashtags'] ?? '')), 0, 255), $lang,
        ]);
        mkOut(['success' => true, 'brand' => $brand()]);
    }

    if ($action === 'save') {
        $kind = in_array($in['kind'] ?? '', ['poster', 'video', 'caption'], true) ? $in['kind'] : 'poster';
        $state = json_encode($in['state'] ?? new stdClass(), JSON_UNESCAPED_UNICODE);
        if (strlen($state) > 2000000) mkOut(['success' => false, 'message' => 'This design is too large to save. Try a smaller uploaded photo.'], 400);
        $thumb = (string)($in['thumb'] ?? '');
        if ($thumb !== '' && (!preg_match('#^data:image/(jpeg|png|webp);base64,#', $thumb) || strlen($thumb) > 200000)) $thumb = '';
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100) ?: 'Untitled';
        $src = mkCleanSrc($in['src'] ?? '');
        $code = strtoupper(mb_substr(trim((string)($in['coupon_code'] ?? '')), 0, 50));
        $id = (int)($in['id'] ?? 0);
        if ($id > 0) {
            $st = $q('UPDATE marketing_designs SET kind = ?, name = ?, src = ?, coupon_code = ?, state = ?, thumb = ? WHERE id = ? AND restaurant_id = ?', [$kind, $name, $src, $code, $state, $thumb, $id, $rid]);
            if (!$st->rowCount() && !$q('SELECT 1 FROM marketing_designs WHERE id = ? AND restaurant_id = ?', [$id, $rid])->fetchColumn()) $id = 0;
        }
        if ($id <= 0) {
            $q('INSERT INTO marketing_designs (restaurant_id, kind, name, src, coupon_code, state, thumb) VALUES (?,?,?,?,?,?,?)', [$rid, $kind, $name, $src, $code, $state, $thumb]);
            $id = (int)$conn->lastInsertId();
        }
        mkOut(['success' => true, 'id' => $id]);
    }

    if ($action === 'delete') {
        $q('DELETE FROM marketing_designs WHERE id = ? AND restaurant_id = ?', [(int)($in['id'] ?? 0), $rid]);
        mkOut(['success' => true]);
    }

    mkOut(['success' => false, 'message' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    error_log('marketing_studio: ' . $e->getMessage());
    mkOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
