<?php
/**
 * Returns this restaurant's payment methods: the built-in ones (Cash, Card,
 * UPI) plus any custom extras the admin has added. Auto-seeds the built-ins
 * the first time a restaurant has none yet, so every restaurant gets a
 * working list without needing a separate one-off migration per account.
 *
 * This is the single source of truth POS, checkout filters, and the admin
 * "Payment Methods" settings list all read from — the goal is that adding
 * a custom method here makes it show up everywhere automatically instead of
 * needing each hardcoded Cash/Card/UPI list updated by hand.
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

try {
    require_once __DIR__ . '/../db_connection.php';
    $conn = getConnection();

    require_once __DIR__ . '/../config/payment_methods.php';
    ensurePaymentMethodsTable($conn);

    $restaurant_id = $_SESSION['restaurant_id'] ?? '';
    if (empty($restaurant_id)) {
        throw new Exception('No restaurant session');
    }

    seedBuiltinPaymentMethods($conn, $restaurant_id);

    $stmt = $conn->prepare("SELECT id, method_name, emoji, display_order, is_active, is_builtin, (qr_image IS NOT NULL) AS has_qr
        FROM payment_methods WHERE restaurant_id = ? ORDER BY display_order ASC, id ASC");
    $stmt->execute([$restaurant_id]);
    $methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($methods as &$m) {
        $m['id'] = (int)$m['id'];
        $m['display_order'] = (int)$m['display_order'];
        $m['is_active'] = (int)$m['is_active'];
        $m['is_builtin'] = (int)$m['is_builtin'];
        $m['qr_code_url'] = ((int)$m['has_qr'] === 1) ? ('payment_method_qr.php?id=' . $m['id']) : '';
        unset($m['has_qr']);
    }
    unset($m);

    echo json_encode(['success' => true, 'data' => $methods]);
} catch (Exception $e) {
    error_log('get_payment_methods error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unable to load payment methods']);
}
