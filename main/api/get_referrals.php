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

    $stmt = $conn->prepare(
        "SELECT r.id, r.referral_code, r.status, r.created_at, r.completed_at,
            ref.customer_name AS referrer_name, ref.phone AS referrer_phone,
            rd.customer_name AS referred_name, rd.phone AS referred_phone
         FROM referrals r
         JOIN customers ref ON ref.id = r.referrer_customer_id
         JOIN customers rd ON rd.id = r.referred_customer_id
         WHERE r.restaurant_id = ?
         ORDER BY r.created_at DESC
         LIMIT 100"
    );
    $stmt->execute([$restaurant_id]);
    $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare(
        "SELECT c.id, c.customer_name, c.phone,
            COUNT(r.id) AS total_referrals,
            SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) AS completed_referrals
         FROM referrals r
         JOIN customers c ON c.id = r.referrer_customer_id
         WHERE r.restaurant_id = ?
         GROUP BY c.id, c.customer_name, c.phone
         ORDER BY completed_referrals DESC, total_referrals DESC
         LIMIT 20"
    );
    $stmt->execute([$restaurant_id]);
    $leaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
         FROM referrals WHERE restaurant_id = ?"
    );
    $stmt->execute([$restaurant_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = (int)($summary['total'] ?? 0);
    $completed = (int)($summary['completed'] ?? 0);

    echo json_encode([
        'success' => true,
        'referrals' => $referrals,
        'leaderboard' => $leaderboard,
        'summary' => [
            'total' => $total,
            'completed' => $completed,
            'conversion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Error in get_referrals.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load referrals']);
}
