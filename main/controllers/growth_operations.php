<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
requirePermission(PERMISSION_MANAGE_LOYALTY);

require_once __DIR__ . '/../db_connection.php';
if (function_exists('getConnection')) { $conn = getConnection(); } else { global $pdo; $conn = $pdo; }

require_once __DIR__ . '/../config/growth_helpers.php';

$action = $_POST['action'] ?? '';

try {
    $restaurant_id = $_SESSION['restaurant_id'] ?? '';
    if (!$restaurant_id) throw new Exception('Restaurant ID not found in session');

    ensureGrowthSchema($conn);

    switch ($action) {
        case 'save_settings':
            $data = [
                'loyalty_enabled' => isset($_POST['loyalty_enabled']) ? (int)!!$_POST['loyalty_enabled'] : 0,
                'earn_points_per_amount' => max(0, (int)($_POST['earn_points_per_amount'] ?? 0)),
                'earn_amount_threshold' => max(0, (float)($_POST['earn_amount_threshold'] ?? 0)),
                'redeem_value_per_point' => max(0, (float)($_POST['redeem_value_per_point'] ?? 0)),
                'min_redeem_points' => max(0, (int)($_POST['min_redeem_points'] ?? 0)),
                'referral_enabled' => isset($_POST['referral_enabled']) ? (int)!!$_POST['referral_enabled'] : 0,
                'referrer_reward_points' => max(0, (int)($_POST['referrer_reward_points'] ?? 0)),
                'referred_reward_points' => max(0, (int)($_POST['referred_reward_points'] ?? 0)),
                'lapsed_days_threshold' => max(1, (int)($_POST['lapsed_days_threshold'] ?? 30)),
                'high_spender_threshold' => max(0, (float)($_POST['high_spender_threshold'] ?? 0)),
            ];
            saveGrowthSettings($conn, $restaurant_id, $data);
            echo json_encode(['success' => true, 'message' => 'Growth settings saved']);
            break;

        case 'add_tier':
            $name = trim($_POST['tier_name'] ?? '');
            $minSpent = (float)($_POST['min_total_spent'] ?? 0);
            $icon = trim($_POST['icon'] ?? 'star');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            if (!$name) throw new Exception('Tier name is required');
            if ($minSpent < 0) throw new Exception('Minimum spend cannot be negative');

            $stmt = $conn->prepare("INSERT INTO loyalty_tiers (restaurant_id, tier_name, min_total_spent, icon, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$restaurant_id, $name, $minSpent, $icon ?: 'star', $sortOrder]);
            echo json_encode(['success' => true, 'message' => 'Tier created']);
            break;

        case 'update_tier':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['tier_name'] ?? '');
            $minSpent = (float)($_POST['min_total_spent'] ?? 0);
            $icon = trim($_POST['icon'] ?? 'star');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid tier id');
            if (!$name) throw new Exception('Tier name is required');
            if ($minSpent < 0) throw new Exception('Minimum spend cannot be negative');

            $stmt = $conn->prepare("UPDATE loyalty_tiers SET tier_name=?, min_total_spent=?, icon=?, sort_order=? WHERE id=? AND restaurant_id=?");
            $stmt->execute([$name, $minSpent, $icon ?: 'star', $sortOrder, $id, $restaurant_id]);
            echo json_encode(['success' => true, 'message' => 'Tier updated']);
            break;

        case 'delete_tier':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid tier id');
            $stmt = $conn->prepare("DELETE FROM loyalty_tiers WHERE id = ? AND restaurant_id = ?");
            $stmt->execute([$id, $restaurant_id]);
            echo json_encode(['success' => true, 'message' => 'Tier deleted']);
            break;

        case 'adjust_points':
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $delta = (int)($_POST['points_delta'] ?? 0);
            $note = trim($_POST['note'] ?? 'Manual adjustment');
            if ($customerId <= 0) throw new Exception('Invalid customer');
            if ($delta === 0) throw new Exception('Points adjustment cannot be zero');

            $conn->beginTransaction();
            try {
                $stmt = $conn->prepare("SELECT loyalty_points_balance FROM customers WHERE id = ? AND restaurant_id = ? FOR UPDATE");
                $stmt->execute([$customerId, $restaurant_id]);
                $customer = $stmt->fetch();
                if (!$customer) throw new Exception('Customer not found');

                $newBalance = (int)$customer['loyalty_points_balance'] + $delta;
                if ($newBalance < 0) throw new Exception('Adjustment would make balance negative');

                $conn->prepare("UPDATE customers SET loyalty_points_balance = ? WHERE id = ?")->execute([$newBalance, $customerId]);
                $conn->prepare(
                    "INSERT INTO loyalty_points_ledger (restaurant_id, customer_id, order_id, points_change, balance_after, type, note)
                     VALUES (?, ?, NULL, ?, ?, 'manual_adjust', ?)"
                )->execute([$restaurant_id, $customerId, $delta, $newBalance, $note]);

                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Points adjusted', 'new_balance' => $newBalance]);
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    error_log("Error in growth_operations.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
