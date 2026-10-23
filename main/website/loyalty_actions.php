<?php
// Customer-facing loyalty API: balance, tier, referral code, recent activity,
// and self-serve redemption. Mirrors api.php's boilerplate but kept as its
// own file so the growth module stays self-contained rather than growing
// the already very large api.php.
//
//   action=page     — everything the loyalty landing page needs in one call: the
//                     program (public: earn rate, tiers, rewards, referral bonus)
//                     plus the visitor's own card when they're logged in
//   action=summary  — the member's card (used by the cart for points at checkout)
//   action=redeem / redeem_item (POST) — turn points into a discount / free item code

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/../config/growth_helpers.php';
require_once __DIR__ . '/../config/order_abuse_guard.php';
require_once __DIR__ . '/customer_session.php';
require_once __DIR__ . '/../config/loyalty_autopilot.php';
require_once __DIR__ . '/../config/wallet_card.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');

$restaurantId = isset($_REQUEST['restaurant_id']) && $_REQUEST['restaurant_id'] !== ''
    ? $_REQUEST['restaurant_id']
    : (isset($_SESSION['restaurant_id']) ? $_SESSION['restaurant_id'] : '');

$action = $_REQUEST['action'] ?? '';
$phone = isset($_REQUEST['phone']) ? trim($_REQUEST['phone']) : '';

/**
 * The customer record that holds this visitor's points. Only for the
 * customer's own logged-in account, or a guest session that just proved
 * ownership of the phone by placing an order (getAuthorizedCustomer()) —
 * never a bare phone parameter. Orders keep points on the 10-digit phone, so
 * an account saved as 0XXXXXXXXXX still finds its points.
 */
function loyaltyResolveCustomer(PDO $conn, string $rid, string $phone, bool $mustLogIn = false): ?array {
    // spending points, gift codes and changing details: only the logged-in customer (a phone number proves nothing)
    $who = getLoggedInCustomer($conn, $rid);
    if (!$who && $phone !== '' && !$mustLogIn) $who = getAuthorizedCustomer($conn, $rid, $phone);
    if (!$who) return null;
    $raw = (string)$who['phone'];
    $norm = orderAbuseNormalizePhone($raw) ?? $raw;
    $stmt = $conn->prepare("SELECT id, customer_name, phone, total_spent, total_visits, loyalty_points_balance, created_at, stamps, birthday, anniversary, whatsapp_optin
        FROM customers WHERE restaurant_id = ? AND phone IN (?, ?) ORDER BY phone = ? DESC, total_spent DESC LIMIT 1");
    $stmt->execute([$rid, $norm, $raw, $norm]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && empty($row['customer_name'])) $row['customer_name'] = $who['customer_name'] ?? '';
    return $row ?: null;
}

/** A guest (not logged in) sees points and tier, never codes or personal details. */
function loyaltyGuestView(array $m): array {
    $m['gifts'] = [];
    $m['pending_codes'] = [];
    $m['details'] = null;
    $m['ledger'] = array_map(function ($l) { $l['redemption_code'] = null; return $l; }, $m['ledger']);
    $m['guest'] = true;
    return $m;
}

/** Tiers, lowest first, with what each one gives. */
function loyaltyTiers(PDO $conn, string $rid): array {
    $stmt = $conn->prepare("SELECT tier_name, min_total_spent, icon, points_multiplier FROM loyalty_tiers WHERE restaurant_id = ? ORDER BY min_total_spent ASC");
    $stmt->execute([$rid]);
    return array_map(function ($t) {
        return ['tier_name' => $t['tier_name'], 'min_total_spent' => (float)$t['min_total_spent'], 'icon' => $t['icon'] ?: 'star',
                'points_multiplier' => (float)$t['points_multiplier']];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Active free-item rewards with the dish photo and price (cheapest first). */
function loyaltyRewardsWithDish(PDO $conn, string $rid): array {
    $stmt = $conn->prepare("SELECT r.id, r.menu_item_id, r.item_name, r.points_cost, mi.item_image, mi.base_price
        FROM loyalty_rewards r LEFT JOIN menu_items mi ON mi.id = r.menu_item_id AND mi.restaurant_id = r.restaurant_id
        WHERE r.restaurant_id = ? AND r.is_active = 1 ORDER BY r.points_cost ASC");
    $stmt->execute([$rid]);
    return array_map(function ($r) {
        return ['id' => (int)$r['id'], 'item_name' => $r['item_name'], 'points_cost' => (int)$r['points_cost'],
                'image' => $r['item_image'] ?: null, 'price' => $r['base_price'] !== null ? (float)$r['base_price'] : null];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** The member's own card: points, tier and progress, referral code, history. */
function loyaltyMember(PDO $conn, string $rid, array $customer, array $settings, array $tiers): array {
    // POS / table orders don't add to customers.total_spent, so count completed orders too
    $done = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(total), 0) FROM orders WHERE restaurant_id = ? AND customer_phone = ? AND order_status = 'Completed' AND deleted_at IS NULL");
    $done->execute([$rid, $customer['phone']]);
    [$doneCount, $doneSum] = $done->fetch(PDO::FETCH_NUM);
    $spent = max((float)$customer['total_spent'], (float)$doneSum);
    $customer['total_visits'] = max((int)$customer['total_visits'], (int)$doneCount);
    $currentTier = null; $nextTier = null;
    foreach ($tiers as $t) {
        if ($spent >= $t['min_total_spent']) $currentTier = $t;
        elseif (!$nextTier) $nextTier = $t + ['amount_needed' => round($t['min_total_spent'] - $spent, 2)];
    }
    $referralCode = !empty($settings['referral_enabled'])
        ? generateReferralCode($conn, $rid, (int)$customer['id'], (string)$customer['customer_name']) : null;
    $stmt = $conn->prepare("SELECT points_change, balance_after, type, note, created_at, redemption_code, redemption_status, redemption_item_name
        FROM loyalty_points_ledger WHERE restaurant_id = ? AND customer_id = ? ORDER BY id DESC LIMIT 20");
    $stmt->execute([$rid, (int)$customer['id']]);
    $ledger = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Codes waiting to be shown to staff
    $cs = $conn->prepare("SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1");
    $cs->execute([$rid]);
    $cur = (string)($cs->fetchColumn() ?: "₹");
    $pending = array_values(array_filter($ledger, function ($l) { return !empty($l['redemption_code']) && $l['redemption_status'] === 'pending'; }));
    return [
        'name' => (string)$customer['customer_name'],
        'points_balance' => (int)$customer['loyalty_points_balance'],
        'total_spent' => $spent,
        'visits' => (int)$customer['total_visits'],
        'member_since' => $customer['created_at'],
        'tier' => $currentTier,
        'next_tier' => $nextTier,
        'referral_code' => $referralCode,
        'ledger' => $ledger,
        'stamps' => (int)($customer['stamps'] ?? 0),
        'gifts' => loyaltyCustomerGifts($conn, $rid, (int)$customer['id']),
        'wallet_balance' => loyaltyProgramType($settings) === 'cashback' ? loyaltyWalletBalance($conn, $rid, (string)$customer['phone']) : null,
        'details' => ['birthday' => $customer['birthday'] ?? null, 'anniversary' => $customer['anniversary'] ?? null, 'whatsapp_optin' => (int)($customer['whatsapp_optin'] ?? 0)],
        'pending_codes' => array_map(function ($l) use ($settings, $cur) {
            $label = !empty($l['redemption_item_name']) ? 'Free ' . $l['redemption_item_name']
                : $cur . rtrim(rtrim(number_format(abs((int)$l['points_change']) * (float)$settings['redeem_value_per_point'], 2, '.', ''), '0'), '.') . ' off';
            return ['code' => $l['redemption_code'], 'note' => $label, 'points' => (int)$l['points_change']];
        }, $pending),
    ];
}

try {
    $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
    if (!$conn) throw new Exception('No connection');
    if (!$restaurantId) throw new Exception('Restaurant not identified');

    // table setup: once per deploy, not on every request (config/schema_cache.php)
    require_once __DIR__ . '/../config/schema_cache.php';
    rgEnsureOnce('ensureGrowthSchema', $conn);
    rgEnsureOnce('ensureLoyaltyAutopilotSchema', $conn);
    rgEnsureOnce('ensureWalletCardSchema', $conn);

    switch ($action) {
        case 'page':
            // birthday / anniversary / win-back gifts (at most once an hour per restaurant)
            try { loyaltyRunSweep($conn, $restaurantId); } catch (Throwable $e) { error_log('autopilot sweep: ' . $e->getMessage()); }
            $settings = getGrowthSettings($conn, $restaurantId);
            $autos = loyaltyAutomations($conn, $restaurantId);
            $tiers = loyaltyTiers($conn, $restaurantId);
            $program = [
                'loyalty_enabled' => (bool)$settings['loyalty_enabled'],
                'referral_enabled' => (bool)$settings['referral_enabled'],
                'earn_points_per_amount' => (int)$settings['earn_points_per_amount'],
                'earn_amount_threshold' => (float)$settings['earn_amount_threshold'],
                'redeem_value_per_point' => (float)$settings['redeem_value_per_point'],
                'min_redeem_points' => (int)$settings['min_redeem_points'],
                'referrer_reward_points' => (int)$settings['referrer_reward_points'],
                'referred_reward_points' => (int)$settings['referred_reward_points'],
                'points_expiry_days' => (int)($settings['points_expiry_days'] ?? 0),
                'program_type' => loyaltyProgramType($settings),
                'cashback_pct' => (float)($settings['cashback_pct'] ?? 0),
                'cashback_max' => (float)($settings['cashback_max'] ?? 0),
                'stamp_goal' => (int)($settings['stamp_goal'] ?? 6),
                'stamp_min_order' => (float)($settings['stamp_min_order'] ?? 0),
                'stamp_reward' => loyaltyProgramType($settings) === 'stamps' ? loyaltyStampRewardLabel($conn, $settings + ['restaurant_id' => $restaurantId]) : null,
                // cashback / stamps programs reward referrals with a gift code for both friends
                'referral_gift' => loyaltyProgramType($settings) !== 'points' && $autos['referral']['enabled'] ? loyaltyAutoRewardLabel($conn, $restaurantId, $autos['referral']) : null,
                'birthday_gift' => $autos['birthday']['enabled'] ? loyaltyAutoRewardLabel($conn, $restaurantId, $autos['birthday']) : null,
                'challenges' => [],
                // "Add to Apple / Google Wallet" (Loyalty Studio › Phone card)
                'wallet_card' => (function () use ($conn, $restaurantId) {
                    $cs = walletCardSettings($conn, $restaurantId);
                    return $cs['enabled'] ? walletCardPlatforms() : ['apple' => false, 'google' => false];
                })(),
                'tiers' => $tiers,
                'rewards' => $settings['loyalty_enabled'] ? loyaltyRewardsWithDish($conn, $restaurantId) : [],
            ];
            $customer = $settings['loyalty_enabled'] ? loyaltyResolveCustomer($conn, $restaurantId, $phone) : null;
            if ($settings['loyalty_enabled']) {
                $cs = $conn->prepare('SELECT currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1');
                $cs->execute([$restaurantId]);
                $program['challenges'] = loyaltyChallengesForCustomer($conn, $restaurantId, $customer ?: null, (string)($cs->fetchColumn() ?: '₹'));
            }
            $realLogin = (bool)getLoggedInCustomer($conn, $restaurantId);
            $member = $customer ? loyaltyMember($conn, $restaurantId, $customer, $settings, $tiers) : null;
            if ($member && !$realLogin) $member = loyaltyGuestView($member);
            echo json_encode(['success' => true, 'program' => $program, 'logged_in' => $realLogin || $customer, 'guest' => !$realLogin,
                'member' => $member], JSON_UNESCAPED_UNICODE);
            break;

        case 'save_details':
            // Birthday / anniversary / WhatsApp opt-in, for the logged-in customer
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid request method');
            $customer = loyaltyResolveCustomer($conn, $restaurantId, $phone, true);
            if (!$customer) throw new Exception('Please log in first.');
            $date = function ($v) { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) && $v <= date('Y-m-d') && $v >= '1900-01-01' ? $v : null; };
            $optin = !empty($_POST['whatsapp_optin']) ? 1 : 0;
            // ticking the box here is a clear choice, so it also undoes an earlier STOP on WhatsApp
            $conn->prepare('UPDATE customers SET optin_at = IF(? = 1 AND whatsapp_optin = 0, NOW(), optin_at), wa_stopped_at = IF(? = 1, NULL, wa_stopped_at), birthday = COALESCE(?, birthday), anniversary = ?, whatsapp_optin = ? WHERE id = ?')
                 ->execute([$optin, $optin, $date($_POST['birthday'] ?? ''), $date($_POST['anniversary'] ?? ''), $optin, (int)$customer['id']]);
            echo json_encode(['success' => true]);
            break;

        case 'summary':
            $customer = loyaltyResolveCustomer($conn, $restaurantId, $phone);
            if (!$customer) {
                echo json_encode(['success' => false, 'need_login' => true, 'message' => 'Please log in to see your rewards.']);
                break;
            }
            $settings = getGrowthSettings($conn, $restaurantId);
            $tiers = loyaltyTiers($conn, $restaurantId);
            $m = loyaltyMember($conn, $restaurantId, $customer, $settings, $tiers);
            $isGuest = !getLoggedInCustomer($conn, $restaurantId);
            if ($isGuest) $m = loyaltyGuestView($m);
            echo json_encode([
                'success' => true,
                'enrolled' => true,
                // a guest sees their points but can't spend them (checkout needs a login): the cart hides "use points"
                'guest' => $isGuest,
                'loyalty_enabled' => (bool)$settings['loyalty_enabled'],
                'referral_enabled' => (bool)$settings['referral_enabled'],
                'points_balance' => $m['points_balance'],
                'redeem_value_per_point' => (float)$settings['redeem_value_per_point'],
                'min_redeem_points' => (int)$settings['min_redeem_points'],
                'tier' => $m['tier'],
                'next_tier' => $m['next_tier'],
                'total_spent' => $m['total_spent'],
                'referral_code' => $m['referral_code'],
                'ledger' => $m['ledger'],
                'rewards' => getLoyaltyRewards($conn, $restaurantId, true),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'redeem':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid request method');
            $points = (int)($_POST['points'] ?? 0);
            if ($points <= 0) throw new Exception('Enter a valid number of points');

            $customer = loyaltyResolveCustomer($conn, $restaurantId, $phone, true);
            if (!$customer) throw new Exception('Please log in before redeeming points.');

            $conn->beginTransaction();
            try {
                $result = redeemLoyaltyPoints($conn, $restaurantId, (int)$customer['id'], $points);
                $conn->commit();
                echo json_encode([
                    'success' => true,
                    'discount_value' => $result['discount_value'],
                    'code' => $result['code'],
                    'message' => 'Redeemed! Show code ' . $result['code'] . ' to our staff to apply your ' . number_format($result['discount_value'], 2) . ' discount.',
                ]);
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;

        case 'redeem_item':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid request method');
            $rewardId = (int)($_POST['reward_id'] ?? 0);
            if ($rewardId <= 0) throw new Exception('Invalid reward');

            $customer = loyaltyResolveCustomer($conn, $restaurantId, $phone, true);
            if (!$customer) throw new Exception('Please log in before redeeming a reward.');

            $conn->beginTransaction();
            try {
                $result = redeemItemReward($conn, $restaurantId, (int)$customer['id'], $rewardId);
                $conn->commit();
                echo json_encode([
                    'success' => true,
                    'code' => $result['code'],
                    'item_name' => $result['item_name'],
                    'message' => 'Redeemed! Show code ' . $result['code'] . ' to our staff to get your free ' . $result['item_name'] . '.',
                ]);
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    error_log('Error in loyalty_actions.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
