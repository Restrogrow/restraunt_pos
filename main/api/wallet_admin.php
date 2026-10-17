<?php
/**
 * Wallet admin API (Offers > Wallet)
 *
 * GET              — settings, last 30 days' numbers, customers holding money, latest entries
 * POST JSON { action: 'save', settings: {...} }
 * POST JSON { action: 'adjust', phone, amount (+ add / - take), note }  — a manual correction
 *
 * Always scoped to the session's restaurant.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/wallet_helpers.php';
require_once __DIR__ . '/../config/order_abuse_guard.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}
$rid = $_SESSION['restaurant_id'];

try {
    $conn = getConnection();
    ensureWalletTables($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sum = function (string $sql, array $p) use ($conn) { $st = $conn->prepare($sql); $st->execute($p); return (float)$st->fetchColumn(); };
        $since = "AND created_at >= NOW() - INTERVAL 30 DAY";
        $stats = [
            'held' => $sum('SELECT COALESCE(SUM(balance), 0) FROM wallets WHERE restaurant_id = ?', [$rid]),
            'holders' => (int)$sum('SELECT COUNT(*) FROM wallets WHERE restaurant_id = ? AND balance > 0', [$rid]),
            'topups' => $sum("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE restaurant_id = ? AND type = 'topup' $since", [$rid]),
            'topup_count' => (int)$sum("SELECT COUNT(*) FROM wallet_ledger WHERE restaurant_id = ? AND type = 'topup' $since", [$rid]),
            // paid from wallets, minus what came back on cancelled orders
            'spent' => -$sum("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE restaurant_id = ? AND type IN ('spend', 'refund') $since", [$rid]),
            'bonus' => $sum("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE restaurant_id = ? AND type IN ('bonus', 'cashback') $since", [$rid]),
        ];
        $st = $conn->prepare('SELECT w.phone, w.balance, w.updated_at, c.customer_name FROM wallets w
            LEFT JOIN customers c ON c.restaurant_id = w.restaurant_id AND c.phone = w.phone
            WHERE w.restaurant_id = ? AND w.balance > 0 ORDER BY w.balance DESC LIMIT 50');
        $st->execute([$rid]);
        $holders = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $conn->prepare('SELECT l.phone, l.amount, l.type, l.note, l.created_at, o.order_number FROM wallet_ledger l
            LEFT JOIN orders o ON o.id = l.order_id WHERE l.restaurant_id = ? ORDER BY l.id DESC LIMIT 25');
        $st->execute([$rid]);
        $recent = $st->fetchAll(PDO::FETCH_ASSOC);
        $cur = (string)($conn->query('SELECT currency_symbol FROM users WHERE restaurant_id = ' . $conn->quote($rid) . ' LIMIT 1')->fetchColumn() ?: '₹');
        $s = getWalletSettings($conn, $rid);
        $st = $conn->prepare('SELECT phonepe_merchant_id, payment_gateway_mode FROM users WHERE restaurant_id = ? LIMIT 1');
        $st->execute([$rid]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['success' => true, 'settings' => $s, 'stats' => $stats, 'holders' => $holders, 'recent' => $recent, 'currency' => $cur,
            'phonepe_ready' => !empty($u['phonepe_merchant_id']) || ($u['payment_gateway_mode'] ?? 'own') !== 'own'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // changing settings or a balance is money: owner / manager only, as in Offers › Combos
    if (!hasPermission(PERMISSION_MANAGE_COUPONS) && !hasPermission(PERMISSION_MANAGE_MENU)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You don’t have permission to change the wallet.']);
        exit;
    }
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $in['action'] ?? '';

    if ($action === 'save') {
        $s = (array)($in['settings'] ?? []);
        $num = function ($v, $min, $max) { return max($min, min($max, round((float)$v, 2))); };
        $tiers = [];
        foreach (array_slice((array)($s['bonus_tiers'] ?? []), 0, 6) as $t) {
            $a = $num($t['amount'] ?? 0, 0, 1000000); $b = $num($t['bonus'] ?? 0, 0, 100000);
            if ($a > 0 && $b > 0 && $b < $a) $tiers[] = ['amount' => $a, 'bonus' => $b];
        }
        $quick = array_slice(array_values(array_unique(array_filter(array_map(function ($v) { return (int)$v; }, (array)($s['quick_amounts'] ?? [])), function ($v) { return $v > 0; }))), 0, 6);
        $min = $num($s['min_topup'] ?? 100, 1, 100000);
        $max = max($min, $num($s['max_topup'] ?? 10000, 1, 1000000));
        $conn->prepare('REPLACE INTO wallet_settings (restaurant_id, enabled, name, min_topup, max_topup, max_balance, quick_amounts, bonus_tiers, cashback_pct, cashback_max) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
             ->execute([$rid, empty($s['enabled']) ? 0 : 1, mb_substr(trim(strip_tags((string)($s['name'] ?? ''))), 0, 40) ?: null,
                        $min, $max, max($max, $num($s['max_balance'] ?? 20000, 1, 10000000)), implode(',', $quick) ?: null, json_encode($tiers),
                        $num($s['cashback_pct'] ?? 0, 0, 50), $num($s['cashback_max'] ?? 0, 0, 100000)]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'adjust') {
        $phone = orderAbuseNormalizePhone((string)($in['phone'] ?? '')) ?? '';
        $amount = round((float)($in['amount'] ?? 0), 2);
        $note = mb_substr(trim(strip_tags((string)($in['note'] ?? ''))), 0, 120);
        if (!preg_match('/^\d{10}$/', $phone)) throw new InvalidArgumentException('Enter a valid 10-digit phone number');
        if ($amount == 0 || abs($amount) > 100000) throw new InvalidArgumentException('Enter an amount to add (e.g. 100) or take away (e.g. -100)');
        try {
            $bal = walletTx($conn, function () use ($conn, $rid, $phone, $amount, $note) {
                return walletApply($conn, $rid, $phone, $amount, 'adjust', $note !== '' ? $note : 'Changed by the restaurant');
            });
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException('That would take the balance below zero');
        }
        echo json_encode(['success' => true, 'balance' => $bal]);
        exit;
    }

    throw new InvalidArgumentException('Unknown action');
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('wallet_admin.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
