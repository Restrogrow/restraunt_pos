<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
requirePermission(PERMISSION_MANAGE_COUPONS);

require_once __DIR__ . '/../db_connection.php';
if (function_exists('getConnection')) { $conn = getConnection(); } else { global $pdo; $conn = $pdo; }

$action = $_POST['action'] ?? '';

try {
    $restaurant_id = $_SESSION['restaurant_id'] ?? '';

    // Auto-create coupons table if missing
    try { $conn->query("SELECT id FROM coupons LIMIT 1"); } catch (PDOException $e) {
        $conn->exec("CREATE TABLE IF NOT EXISTS coupons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(10) NOT NULL,
            coupon_code VARCHAR(50) NOT NULL,
            discount_type ENUM('percent','flat') NOT NULL DEFAULT 'percent',
            discount_value DECIMAL(10,2) NOT NULL,
            minimum_order_amount DECIMAL(10,2) DEFAULT 0.00,
            max_uses INT DEFAULT 0,
            current_uses INT DEFAULT 0,
            valid_from DATE DEFAULT NULL,
            valid_until DATE DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            description VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_coupon (restaurant_id, coupon_code),
            INDEX idx_restaurant (restaurant_id)
        )");
    }

    switch ($action) {
        case 'add':
            $code = strtoupper(trim($_POST['coupon_code'] ?? ''));
            $type = $_POST['discount_type'] ?? 'percent';
            $value = (float)($_POST['discount_value'] ?? 0);
            $minOrder = (float)($_POST['minimum_order_amount'] ?? 0);
            $maxUses = (int)($_POST['max_uses'] ?? 0);
            $validFrom = $_POST['valid_from'] ?: null;
            $validUntil = $_POST['valid_until'] ?: null;
            $desc = trim($_POST['description'] ?? '');

            // Validate inputs — guard against negative values and invalid dates
            if (!$code || $value <= 0) throw new Exception('Coupon code and discount value are required');
            if (!in_array($type, ['percent', 'flat'])) $type = 'percent';
            if ($type === 'percent' && $value > 100) throw new Exception('Percentage discount cannot exceed 100%');
            if ($minOrder < 0) throw new Exception('Minimum order amount cannot be negative');
            if ($maxUses < 0) throw new Exception('Max uses cannot be negative');
            if ($validFrom && $validUntil && $validFrom > $validUntil) throw new Exception('Valid from date must be before or equal to valid until date');

            // Check duplicate
            $check = $conn->prepare("SELECT id FROM coupons WHERE restaurant_id = ? AND coupon_code = ?");
            $check->execute([$restaurant_id, $code]);
            if ($check->fetch()) throw new Exception('Coupon code already exists');

            $stmt = $conn->prepare("INSERT INTO coupons (restaurant_id, coupon_code, discount_type, discount_value, minimum_order_amount, max_uses, valid_from, valid_until, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$restaurant_id, $code, $type, $value, $minOrder, $maxUses, $validFrom, $validUntil, $desc]);
            echo json_encode(['success' => true, 'message' => 'Coupon created']);
            break;

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid coupon id');

            $code = strtoupper(trim($_POST['coupon_code'] ?? ''));
            $type = $_POST['discount_type'] ?? 'percent';
            $value = (float)($_POST['discount_value'] ?? 0);
            $minOrder = (float)($_POST['minimum_order_amount'] ?? 0);
            $maxUses = (int)($_POST['max_uses'] ?? 0);
            $validFrom = $_POST['valid_from'] ?: null;
            $validUntil = $_POST['valid_until'] ?: null;
            $desc = trim($_POST['description'] ?? '');

            // Validate inputs — guard against negative values and invalid dates
            if (!$code || $value <= 0) throw new Exception('Coupon code and discount value are required');
            if (!in_array($type, ['percent', 'flat'])) $type = 'percent';
            if ($type === 'percent' && $value > 100) throw new Exception('Percentage discount cannot exceed 100%');
            if ($minOrder < 0) throw new Exception('Minimum order amount cannot be negative');
            if ($maxUses < 0) throw new Exception('Max uses cannot be negative');
            if ($validFrom && $validUntil && $validFrom > $validUntil) throw new Exception('Valid from date must be before or equal to valid until date');

            // Check duplicate (exclude self)
            $check = $conn->prepare("SELECT id FROM coupons WHERE restaurant_id = ? AND coupon_code = ? AND id != ?");
            $check->execute([$restaurant_id, $code, $id]);
            if ($check->fetch()) throw new Exception('Coupon code already exists');

            $stmt = $conn->prepare("UPDATE coupons SET coupon_code=?, discount_type=?, discount_value=?, minimum_order_amount=?, max_uses=?, valid_from=?, valid_until=?, description=? WHERE id=? AND restaurant_id=?");
            $stmt->execute([$code, $type, $value, $minOrder, $maxUses, $validFrom, $validUntil, $desc, $id, $restaurant_id]);
            echo json_encode(['success' => true, 'message' => 'Coupon updated']);
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid coupon id');
            $stmt = $conn->prepare("DELETE FROM coupons WHERE id = ? AND restaurant_id = ?");
            $stmt->execute([$id, $restaurant_id]);
            echo json_encode(['success' => true, 'message' => 'Coupon deleted']);
            break;

        case 'toggle':
            $id = (int)($_POST['id'] ?? 0);
            $active = (int)($_POST['is_active'] ?? 1);
            if ($id <= 0) throw new Exception('Invalid coupon id');
            $stmt = $conn->prepare("UPDATE coupons SET is_active = ? WHERE id = ? AND restaurant_id = ?");
            $stmt->execute([$active, $id, $restaurant_id]);
            echo json_encode(['success' => true, 'message' => 'Coupon status updated']);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    error_log("Error in coupon_operations.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
