<?php
/**
 * Scratch card admin API (Offers > Scratch card)
 *
 * GET              — settings, prizes, the last 30 days' numbers and recent cards
 * POST (JSON body) — { settings: {enabled, title, min_order, next_min_order, valid_days, monthly_budget},
 *                      prizes: [{ type: flat|percent|none, value, weight, min_order (optional) }] }
 *
 * Always scoped to the session's restaurant.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/scratch_helpers.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}
$restaurantId = $_SESSION['restaurant_id'];
session_write_close(); // only reads the session — release its lock

try {
    $conn = getConnection();
    ensureScratchTables($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $st = $conn->prepare("SELECT COUNT(*) given, SUM(sc.status = 'scratched') scratched, SUM(sc.prize_type <> 'none' AND sc.status = 'scratched') won,
                SUM(c.current_uses > 0) used
            FROM scratch_cards sc LEFT JOIN coupons c ON c.restaurant_id = sc.restaurant_id AND c.coupon_code = sc.coupon_code
            WHERE sc.restaurant_id = ? AND sc.created_at >= NOW() - INTERVAL 30 DAY");
        $st->execute([$restaurantId]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        // Real orders placed with a won code
        $st = $conn->prepare("SELECT COUNT(*) n, COALESCE(SUM(o.total), 0) sales FROM orders o
            JOIN scratch_cards sc ON sc.restaurant_id = o.restaurant_id AND sc.coupon_code = o.coupon_code
            WHERE o.restaurant_id = ? AND o.created_at >= NOW() - INTERVAL 30 DAY AND o.deleted_at IS NULL AND o.order_status NOT IN ('Cancelled', 'Rejected')");
        $st->execute([$restaurantId]);
        $back = $st->fetch(PDO::FETCH_ASSOC);
        $st = $conn->prepare("SELECT sc.created_at, sc.customer_phone, sc.prize_type, sc.prize_value, sc.status, sc.coupon_code, sc.expires_on, o.order_number, COALESCE(c.current_uses, 0) used
            FROM scratch_cards sc JOIN orders o ON o.id = sc.order_id
            LEFT JOIN coupons c ON c.restaurant_id = sc.restaurant_id AND c.coupon_code = sc.coupon_code
            WHERE sc.restaurant_id = ? ORDER BY sc.id DESC LIMIT 12");
        $st->execute([$restaurantId]);
        $cur = (string)($conn->query("SELECT currency_symbol FROM users WHERE restaurant_id = " . $conn->quote($restaurantId) . " LIMIT 1")->fetchColumn() ?: '₹');
        $recent = array_map(function ($r) use ($cur) {
            $ph = (string)$r['customer_phone'];
            return ['when' => $r['created_at'], 'phone' => strlen($ph) > 4 ? str_repeat('•', strlen($ph) - 4) . substr($ph, -4) : $ph,
                    'order' => $r['order_number'], 'prize' => scratchPrizeLabel($r['prize_type'], (float)$r['prize_value'], $cur),
                    'type' => $r['prize_type'], 'status' => $r['status'], 'code' => $r['coupon_code'], 'used' => (int)$r['used'] > 0, 'expires_on' => $r['expires_on']];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(['success' => true, 'settings' => getScratchSettings($conn, $restaurantId), 'prizes' => getScratchPrizes($conn, $restaurantId),
            'stats' => ['given' => (int)$s['given'], 'scratched' => (int)$s['scratched'], 'won' => (int)$s['won'], 'used' => (int)$s['used'],
                        'return_orders' => (int)$back['n'], 'return_sales' => (float)$back['sales'], 'spent_month' => scratchSpentThisMonth($conn, $restaurantId)],
            'recent' => $recent, 'currency' => $cur], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false]); exit; }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in) || !is_array($in['settings'] ?? null) || !is_array($in['prizes'] ?? null)) throw new InvalidArgumentException('Nothing to save');
    $s = $in['settings'];
    $prizes = [];
    foreach (array_slice($in['prizes'], 0, 8) as $p) {
        $type = in_array($p['type'] ?? '', ['flat', 'percent', 'none'], true) ? $p['type'] : null;
        $value = round((float)($p['value'] ?? 0), 2);
        $weight = (int)($p['weight'] ?? 0);
        if (!$type || $weight < 1 || $weight > 1000) continue;
        if ($type === 'flat' && ($value <= 0 || $value > 100000)) continue;
        if ($type === 'percent' && ($value <= 0 || $value > 100)) continue;
        if ($type === 'none') $value = 0;
        $min = round((float)($p['min_order'] ?? 0), 2);
        $prizes[] = [$type, $value, $weight, $type !== 'none' && $min > 0 ? min($min, 1000000) : null];
    }
    if (!empty($s['enabled']) && !$prizes) throw new InvalidArgumentException('Add at least one prize to switch the scratch card on');

    $conn->beginTransaction();
    $conn->prepare('REPLACE INTO scratch_settings (restaurant_id, enabled, title, min_order, next_min_order, valid_days, monthly_budget) VALUES (?, ?, ?, ?, ?, ?, ?)')
         ->execute([$restaurantId, empty($s['enabled']) ? 0 : 1, mb_substr(trim(strip_tags((string)($s['title'] ?? ''))), 0, 60) ?: null,
                    max(0, min(1000000, (float)($s['min_order'] ?? 0))), max(0, min(1000000, (float)($s['next_min_order'] ?? 0))),
                    max(1, min(90, (int)($s['valid_days'] ?? 15))), max(0, min(10000000, (float)($s['monthly_budget'] ?? 0)))]);
    $conn->prepare('DELETE FROM scratch_prizes WHERE restaurant_id = ?')->execute([$restaurantId]);
    $ins = $conn->prepare('INSERT INTO scratch_prizes (restaurant_id, type, value, weight, min_order, position) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($prizes as $i => $p) $ins->execute([$restaurantId, $p[0], $p[1], $p[2], $p[3], $i]);
    $conn->commit();
    echo json_encode(['success' => true]);
} catch (InvalidArgumentException $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('scratch_admin.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save. Please try again.']);
}
