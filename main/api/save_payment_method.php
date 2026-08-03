<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
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

    $action = $_POST['action'] ?? 'create';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        if (!$id) throw new Exception('Method ID required');
        // Built-ins can be deactivated but not deleted outright — several
        // parts of the app (order history, KOT notes) refer to "Cash" by
        // name, and losing the row entirely is more confusing than useful.
        $checkStmt = $conn->prepare("SELECT is_builtin FROM payment_methods WHERE id = ? AND restaurant_id = ?");
        $checkStmt->execute([$id, $restaurant_id]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) throw new Exception('Payment method not found');
        if ((int)$existing['is_builtin'] === 1) {
            throw new Exception('Built-in payment methods can be disabled but not deleted');
        }
        $stmt = $conn->prepare("DELETE FROM payment_methods WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$id, $restaurant_id]);
        echo json_encode(['success' => true, 'message' => 'Payment method deleted']);
        exit;
    }

    if ($action === 'toggle') {
        if (!$id) throw new Exception('Method ID required');
        $stmt = $conn->prepare("SELECT is_active FROM payment_methods WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$id, $restaurant_id]);
        $method = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$method) throw new Exception('Payment method not found');
        $newStatus = $method['is_active'] ? 0 : 1;
        $stmt = $conn->prepare("UPDATE payment_methods SET is_active = ? WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$newStatus, $id, $restaurant_id]);
        echo json_encode(['success' => true, 'message' => $newStatus ? 'Payment method enabled' : 'Payment method disabled']);
        exit;
    }

    if ($action === 'uploadQR') {
        if (!$id) throw new Exception('Method ID required');
        if (empty($_FILES['qr_image']) || $_FILES['qr_image']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No image uploaded');
        }
        $allowedMimes = ['image/jpeg' => true, 'image/png' => true, 'image/webp' => true, 'image/gif' => true];
        $mime = mime_content_type($_FILES['qr_image']['tmp_name']);
        if (!isset($allowedMimes[$mime])) {
            throw new Exception('Please upload a JPEG, PNG, WEBP, or GIF image');
        }
        if ($_FILES['qr_image']['size'] > 3 * 1024 * 1024) {
            throw new Exception('Image must be under 3MB');
        }
        $imageData = file_get_contents($_FILES['qr_image']['tmp_name']);
        $stmt = $conn->prepare("UPDATE payment_methods SET qr_image = ?, qr_image_mime = ? WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$imageData, $mime, $id, $restaurant_id]);
        echo json_encode(['success' => true, 'message' => 'QR code uploaded']);
        exit;
    }

    $method_name = trim($_POST['method_name'] ?? '');
    $emoji = trim($_POST['emoji'] ?? '') ?: '💳';
    $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

    if (empty($method_name)) {
        throw new Exception('Method name is required');
    }
    if (mb_strlen($method_name) > 100) {
        throw new Exception('Method name is too long');
    }

    if ($action === 'update') {
        if (!$id) throw new Exception('Method ID required for update');
        $stmt = $conn->prepare("UPDATE payment_methods SET method_name = ?, emoji = ?, is_active = ? WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$method_name, $emoji, $is_active, $id, $restaurant_id]);
        echo json_encode(['success' => true, 'message' => 'Payment method updated']);
    } else {
        $check = $conn->prepare("SELECT id FROM payment_methods WHERE restaurant_id = ? AND method_name = ?");
        $check->execute([$restaurant_id, $method_name]);
        if ($check->fetch()) {
            throw new Exception('A payment method with this name already exists');
        }
        $orderStmt = $conn->prepare("SELECT COALESCE(MAX(display_order), -1) + 1 FROM payment_methods WHERE restaurant_id = ?");
        $orderStmt->execute([$restaurant_id]);
        $nextOrder = (int)$orderStmt->fetchColumn();

        $stmt = $conn->prepare("INSERT INTO payment_methods (restaurant_id, method_name, emoji, display_order, is_active, is_builtin) VALUES (?, ?, ?, ?, ?, 0)");
        $stmt->execute([$restaurant_id, $method_name, $emoji, $nextOrder, $is_active]);
        echo json_encode(['success' => true, 'message' => 'Payment method added', 'id' => $conn->lastInsertId()]);
    }

} catch (Exception $e) {
    http_response_code(400);
    error_log('Error in save_payment_method.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage() ?: 'An error occurred. Please try again.']);
}
