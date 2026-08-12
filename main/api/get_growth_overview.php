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

    $stmt = $conn->prepare("SELECT tier_name, min_total_spent, icon, sort_order FROM loyalty_tiers WHERE restaurant_id = ? ORDER BY min_total_spent ASC");
    $stmt->execute([$restaurant_id]);
    $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN type = 'earned' THEN points_change ELSE 0 END), 0) AS points_issued,
            COALESCE(SUM(CASE WHEN type = 'redeemed' THEN -points_change ELSE 0 END), 0) AS points_redeemed,
            COALESCE(SUM(CASE WHEN type = 'referral_bonus' THEN points_change ELSE 0 END), 0) AS referral_points_issued,
            COALESCE(SUM(CASE WHEN type = 'manual_adjust' THEN points_change ELSE 0 END), 0) AS manual_adjustments
         FROM loyalty_points_ledger WHERE restaurant_id = ?"
    );
    $stmt->execute([$restaurant_id]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT COUNT(DISTINCT customer_id) FROM loyalty_points_ledger WHERE restaurant_id = ?");
    $stmt->execute([$restaurant_id]);
    $activeMembers = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare(
        "SELECT l.id, l.customer_id, c.customer_name, c.phone, l.points_change, l.balance_after, l.type, l.note, l.created_at
         FROM loyalty_points_ledger l
         JOIN customers c ON c.id = l.customer_id
         WHERE l.restaurant_id = ?
         ORDER BY l.created_at DESC
         LIMIT 25"
    );
    $stmt->execute([$restaurant_id]);
    $recentLedger = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'settings' => $settings,
        'tiers' => $tiers,
        'totals' => [
            'points_issued' => (int)$totals['points_issued'],
            'points_redeemed' => (int)$totals['points_redeemed'],
            'referral_points_issued' => (int)$totals['referral_points_issued'],
            'manual_adjustments' => (int)$totals['manual_adjustments'],
            'active_members' => $activeMembers,
        ],
        'recent_ledger' => $recentLedger,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Error in get_growth_overview.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load growth overview']);
}
