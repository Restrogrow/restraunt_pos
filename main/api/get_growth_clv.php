<?php
// Customer lifetime value + churn-risk report, built on top of the same
// classifyCustomerSegment() inputs (total_visits, total_spent,
// last_visit_date) segmentation already uses — see computeCustomerClv()
// in growth_helpers.php for the scoring itself.
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/growth_helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['restaurant_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$restaurant_id = $_SESSION['restaurant_id'];
$filterRisk = $_GET['risk'] ?? '';

try {
    $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
    if (!$conn) throw new Exception('No connection');

    ensureGrowthSchema($conn);
    $settings = getGrowthSettings($conn, $restaurant_id);

    $stmt = $conn->prepare(
        "SELECT id, customer_name, phone, email, total_visits, total_spent, last_visit_date, created_at, loyalty_points_balance
         FROM customers WHERE restaurant_id = ? ORDER BY total_spent DESC"
    );
    $stmt->execute([$restaurant_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $counts = ['low' => 0, 'medium' => 0, 'high' => 0];
    $revenueAtRisk = ['low' => 0.0, 'medium' => 0.0, 'high' => 0.0];
    $totalPredictedClv = 0.0;
    $filtered = [];

    foreach ($customers as &$customer) {
        $clv = computeCustomerClv($customer, $settings);
        $customer = array_merge($customer, $clv);
        $counts[$clv['churn_risk']]++;
        $revenueAtRisk[$clv['churn_risk']] += $clv['predicted_clv'];
        $totalPredictedClv += $clv['predicted_clv'];

        if (!$filterRisk || $filterRisk === $clv['churn_risk']) {
            $filtered[] = $customer;
        }
    }
    unset($customer);

    echo json_encode([
        'success' => true,
        'counts' => $counts,
        'revenue_at_risk' => $revenueAtRisk,
        'total_predicted_clv' => round($totalPredictedClv, 2),
        'customers' => $filtered,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Error in get_growth_clv.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load CLV report']);
}
