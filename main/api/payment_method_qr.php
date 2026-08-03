<?php
/**
 * Serves the QR code image uploaded for a payment method. Scoped to the
 * logged-in restaurant so one restaurant can't fetch another's QR by ID.
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit;
}

require_once __DIR__ . '/../db_connection.php';
$conn = getConnection();

$restaurant_id = $_SESSION['restaurant_id'] ?? '';
$id = (int)($_GET['id'] ?? 0);

if (!$id || empty($restaurant_id)) {
    http_response_code(400);
    exit;
}

$stmt = $conn->prepare("SELECT qr_image, qr_image_mime FROM payment_methods WHERE id = ? AND restaurant_id = ?");
$stmt->execute([$id, $restaurant_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || $row['qr_image'] === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . ($row['qr_image_mime'] ?: 'image/png'));
header('Cache-Control: private, max-age=3600');
echo $row['qr_image'];
