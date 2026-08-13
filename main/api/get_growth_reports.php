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

try {
    $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
    if (!$conn) throw new Exception('No connection');

    ensureGrowthSchema($conn);
    $settings = getGrowthSettings($conn, $restaurant_id);

    // Loyalty ROI: revenue from customers who have any ledger activity
    // ("members") vs those who don't, using the existing running total on
    // customers rather than re-aggregating orders.
    $stmt = $conn->prepare(
        "SELECT
            COUNT(*) AS member_count,
            COALESCE(SUM(c.total_spent), 0) AS member_revenue
         FROM customers c
         WHERE c.restaurant_id = ?
         AND EXISTS (SELECT 1 FROM loyalty_points_ledger l WHERE l.customer_id = c.id)"
    );
    $stmt->execute([$restaurant_id]);
    $memberStats = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare(
        "SELECT
            COUNT(*) AS non_member_count,
            COALESCE(SUM(c.total_spent), 0) AS non_member_revenue
         FROM customers c
         WHERE c.restaurant_id = ?
         AND NOT EXISTS (SELECT 1 FROM loyalty_points_ledger l WHERE l.customer_id = c.id)"
    );
    $stmt->execute([$restaurant_id]);
    $nonMemberStats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Redemption cost: currency value of points redeemed
    $stmt = $conn->prepare("SELECT COALESCE(SUM(loyalty_discount_amount), 0) AS redemption_cost FROM orders WHERE restaurant_id = ?");
    $stmt->execute([$restaurant_id]);
    $redemptionCost = (float)$stmt->fetchColumn();

    // Referral-driven revenue: orders from customers whose referral is completed
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(o.total), 0) AS referral_revenue, COUNT(DISTINCT o.id) AS referral_orders
         FROM orders o
         JOIN customers c ON c.restaurant_id = o.restaurant_id AND c.phone = o.customer_phone
         JOIN referrals r ON r.restaurant_id = o.restaurant_id AND r.referred_customer_id = c.id AND r.status = 'completed'
         WHERE o.restaurant_id = ? AND o.order_status = 'Completed'"
    );
    $stmt->execute([$restaurant_id]);
    $referralRevenue = $stmt->fetch(PDO::FETCH_ASSOC);

    // Segment revenue breakdown (recomputed live, same classification as get_customer_segments.php)
    $stmt = $conn->prepare("SELECT total_visits, total_spent, last_visit_date FROM customers WHERE restaurant_id = ?");
    $stmt->execute([$restaurant_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $segmentRevenue = ['new' => 0.0, 'repeat' => 0.0, 'high_spender' => 0.0, 'lapsed' => 0.0];
    $segmentCounts = ['new' => 0, 'repeat' => 0, 'high_spender' => 0, 'lapsed' => 0];
    foreach ($customers as $customer) {
        $segment = classifyCustomerSegment($customer, $settings);
        $segmentRevenue[$segment] += (float)$customer['total_spent'];
        $segmentCounts[$segment]++;
    }

    // 30-day trend: this window vs the one immediately before it, so the
    // admin can see whether engagement is growing or shrinking rather than
    // just a lifetime snapshot.
    $stmt = $conn->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND type = 'earned' THEN points_change ELSE 0 END), 0) AS points_issued_current,
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND type = 'earned' THEN points_change ELSE 0 END), 0) AS points_issued_previous,
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND type IN ('redeemed','item_redeemed') THEN -points_change ELSE 0 END), 0) AS points_redeemed_current,
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND type IN ('redeemed','item_redeemed') THEN -points_change ELSE 0 END), 0) AS points_redeemed_previous
         FROM loyalty_points_ledger WHERE restaurant_id = ?"
    );
    $stmt->execute([$restaurant_id]);
    $pointsTrend = $stmt->fetch(PDO::FETCH_ASSOC);

    // New members = customers whose first-ever ledger entry falls in the window.
    $stmt = $conn->prepare(
        "SELECT
            SUM(CASE WHEN first_activity >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS new_members_current,
            SUM(CASE WHEN first_activity >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND first_activity < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS new_members_previous
         FROM (SELECT customer_id, MIN(created_at) AS first_activity FROM loyalty_points_ledger WHERE restaurant_id = ? GROUP BY customer_id) t"
    );
    $stmt->execute([$restaurant_id]);
    $memberTrend = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare(
        "SELECT
            SUM(CASE WHEN completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS referrals_current,
            SUM(CASE WHEN completed_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND completed_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS referrals_previous
         FROM referrals WHERE restaurant_id = ? AND status = 'completed'"
    );
    $stmt->execute([$restaurant_id]);
    $referralTrend = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'trend' => [
            'points_issued' => ['current' => (int)$pointsTrend['points_issued_current'], 'previous' => (int)$pointsTrend['points_issued_previous']],
            'points_redeemed' => ['current' => (int)$pointsTrend['points_redeemed_current'], 'previous' => (int)$pointsTrend['points_redeemed_previous']],
            'new_members' => ['current' => (int)($memberTrend['new_members_current'] ?? 0), 'previous' => (int)($memberTrend['new_members_previous'] ?? 0)],
            'referral_conversions' => ['current' => (int)($referralTrend['referrals_current'] ?? 0), 'previous' => (int)($referralTrend['referrals_previous'] ?? 0)],
        ],
        'loyalty' => [
            'member_count' => (int)$memberStats['member_count'],
            'member_revenue' => (float)$memberStats['member_revenue'],
            'non_member_count' => (int)$nonMemberStats['non_member_count'],
            'non_member_revenue' => (float)$nonMemberStats['non_member_revenue'],
            'redemption_cost' => $redemptionCost,
        ],
        'referrals' => [
            'revenue' => (float)$referralRevenue['referral_revenue'],
            'order_count' => (int)$referralRevenue['referral_orders'],
        ],
        'segments' => [
            'counts' => $segmentCounts,
            'revenue' => $segmentRevenue,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Error in get_growth_reports.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load growth reports']);
}
