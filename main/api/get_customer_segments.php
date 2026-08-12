<?php
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
$filterSegment = $_GET['segment'] ?? '';

try {
    $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
    if (!$conn) throw new Exception('No connection');

    ensureGrowthSchema($conn);
    $settings = getGrowthSettings($conn, $restaurant_id);

    $stmt = $conn->prepare(
        "SELECT id, customer_name, phone, email, total_visits, total_spent, last_visit_date, loyalty_points_balance
         FROM customers WHERE restaurant_id = ? ORDER BY total_spent DESC"
    );
    $stmt->execute([$restaurant_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $counts = ['new' => 0, 'repeat' => 0, 'high_spender' => 0, 'lapsed' => 0];
    $revenue = ['new' => 0.0, 'repeat' => 0.0, 'high_spender' => 0.0, 'lapsed' => 0.0];
    $filtered = [];

    foreach ($customers as &$customer) {
        $segment = classifyCustomerSegment($customer, $settings);
        $customer['segment'] = $segment;
        $counts[$segment]++;
        $revenue[$segment] += (float)$customer['total_spent'];

        if (!$filterSegment || $filterSegment === $segment) {
            $filtered[] = $customer;
        }
    }
    unset($customer);

    echo json_encode([
        'success' => true,
        'counts' => $counts,
        'revenue' => $revenue,
        'customers' => $filtered,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Error in get_customer_segments.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load customer segments']);
}
