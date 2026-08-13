<?php
// Customer-facing loyalty API: balance, tier, referral code, recent activity,
// and self-serve redemption. Mirrors api.php's boilerplate but kept as its
// own file so the growth module stays self-contained rather than growing
// the already very large api.php.

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

try {
    $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
    if (!$conn) throw new Exception('No connection');
    if (!$restaurantId) throw new Exception('Restaurant not identified');

    ensureGrowthSchema($conn);

    switch ($action) {
        case 'summary':
            if (!$phone) throw new Exception('Phone number is required');

            $stmt = $conn->prepare("SELECT id, customer_name, total_spent, loyalty_points_balance FROM customers WHERE restaurant_id = ? AND phone = ?");
            $stmt->execute([$restaurantId, $phone]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$customer) {
                echo json_encode(['success' => true, 'enrolled' => false]);
                break;
            }

            $settings = getGrowthSettings($conn, $restaurantId);

            $stmt = $conn->prepare("SELECT tier_name, min_total_spent, icon FROM loyalty_tiers WHERE restaurant_id = ? ORDER BY min_total_spent DESC");
            $stmt->execute([$restaurantId]);
            $tiersDesc = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $currentTier = null;
            foreach ($tiersDesc as $tier) {
                if ((float)$customer['total_spent'] >= (float)$tier['min_total_spent']) {
                    $currentTier = $tier;
                    break;
                }
            }

            // Next tier up (the cheapest tier still above current spend) —
            // ordering ascending here so the first one found is the closest.
            $nextTier = null;
            $tiersAsc = array_reverse($tiersDesc);
            foreach ($tiersAsc as $tier) {
                if ((float)$tier['min_total_spent'] > (float)$customer['total_spent']) {
                    $nextTier = [
                        'tier_name' => $tier['tier_name'],
                        'min_total_spent' => (float)$tier['min_total_spent'],
                        'amount_needed' => round((float)$tier['min_total_spent'] - (float)$customer['total_spent'], 2),
                    ];
                    break;
                }
            }

            $referralCode = null;
            if (!empty($settings['referral_enabled'])) {
                $referralCode = generateReferralCode($conn, $restaurantId, (int)$customer['id'], $customer['customer_name']);
            }

            $stmt = $conn->prepare("SELECT points_change, balance_after, type, note, created_at FROM loyalty_points_ledger WHERE restaurant_id = ? AND customer_id = ? ORDER BY created_at DESC LIMIT 15");
            $stmt->execute([$restaurantId, $customer['id']]);
            $ledger = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $rewards = getLoyaltyRewards($conn, $restaurantId, true);

            echo json_encode([
                'success' => true,
                'enrolled' => true,
                'loyalty_enabled' => (bool)$settings['loyalty_enabled'],
                'referral_enabled' => (bool)$settings['referral_enabled'],
                'points_balance' => (int)$customer['loyalty_points_balance'],
                'redeem_value_per_point' => (float)$settings['redeem_value_per_point'],
                'min_redeem_points' => (int)$settings['min_redeem_points'],
                'tier' => $currentTier,
                'next_tier' => $nextTier,
                'total_spent' => (float)$customer['total_spent'],
                'referral_code' => $referralCode,
                'ledger' => $ledger,
                'rewards' => $rewards,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'redeem':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid request method');
            if (!$phone) throw new Exception('Phone number is required');

            $points = (int)($_POST['points'] ?? 0);
            if ($points <= 0) throw new Exception('Enter a valid number of points');

            $stmt = $conn->prepare("SELECT id FROM customers WHERE restaurant_id = ? AND phone = ?");
            $stmt->execute([$restaurantId, $phone]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$customer) throw new Exception('Customer not found');

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
            if (!$phone) throw new Exception('Phone number is required');

            $rewardId = (int)($_POST['reward_id'] ?? 0);
            if ($rewardId <= 0) throw new Exception('Invalid reward');

            $stmt = $conn->prepare("SELECT id FROM customers WHERE restaurant_id = ? AND phone = ?");
            $stmt->execute([$restaurantId, $phone]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$customer) throw new Exception('Customer not found');

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
