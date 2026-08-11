<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

function maskSecret($val) {
    if (!$val || strlen($val) < 4) return '********';
    return str_repeat('*', max(0, strlen($val) - 4)) . substr($val, -4);
}

if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

try {
    $isAdmin = isset($_SESSION['user_id']);
    $isStaff = isset($_SESSION['staff_id']);
    $isBranchAdmin = isset($_SESSION['branch_admin_id']);

    if (!isSessionValid() || (!$isAdmin && !$isStaff && !$isBranchAdmin) || !isset($_SESSION['username']) || !isset($_SESSION['restaurant_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Not logged in.'
        ]);
        exit();
    }

    if (file_exists(__DIR__ . '/../db_connection.php')) {
        require_once __DIR__ . '/../db_connection.php';
    } else {
        throw new Exception('Database connection file not found');
    }

    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        global $pdo;
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }


    $row = [];

    if ($isAdmin) {
        try {
            $stmt = $conn->prepare("SELECT id, subscription_status, trial_end_date, renewal_date, created_at, email, role, phone, address, description, description_format, opening_hours, phonepe_merchant_id, phonepe_salt_key, phonepe_environment, minimum_order_value, payment_gateway_type, currency_symbol, country, timezone, restaurant_logo, business_qr_code_path, google_maps_link, owner_name, enable_gst, tax_name, tax_percent, instagram_link, facebook_link, twitter_link, youtube_link, linkedin_link, enable_delivery, enable_takeaway, enable_dinein, cod_enabled, packaging_charge, delivery_radius_km, restaurant_lat, restaurant_lng, enable_km_delivery, delivery_rate_per_km FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'currency_symbol') !== false || strpos($e->getMessage(), 'timezone') !== false || strpos($e->getMessage(), 'phone') !== false || strpos($e->getMessage(), 'address') !== false || strpos($e->getMessage(), 'opening_hours') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                try {
                    $stmt = $conn->prepare("SELECT subscription_status, trial_end_date, renewal_date, created_at, email, role, phone, address, payment_gateway_type FROM users WHERE id = :id LIMIT 1");
                    $stmt->execute([':id' => $_SESSION['user_id']]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                } catch (PDOException $e2) {
                    if (strpos($e2->getMessage(), 'phone') !== false || strpos($e2->getMessage(), 'address') !== false || strpos($e2->getMessage(), 'Unknown column') !== false) {
                        try {
                            $stmt = $conn->prepare("SELECT subscription_status, trial_end_date, renewal_date, created_at, email, role, payment_gateway_type FROM users WHERE id = :id LIMIT 1");
                            $stmt->execute([':id' => $_SESSION['user_id']]);
                            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                        } catch (PDOException $e3) {
                            if (strpos($e3->getMessage(), 'email') !== false || strpos($e3->getMessage(), 'role') !== false || strpos($e3->getMessage(), 'Unknown column') !== false) {
                                $stmt = $conn->prepare("SELECT subscription_status, trial_end_date, renewal_date, created_at, payment_gateway_type FROM users WHERE id = :id LIMIT 1");
                                $stmt->execute([':id' => $_SESSION['user_id']]);
                                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                            } else {
                                throw $e3;
                            }
                        }
                    } else {
                        throw $e2;
                    }
                }
            } else {
                throw $e;
            }
        }
    } else if ($isStaff) {
        try {
            $stmt = $conn->prepare("SELECT id, member_name, email, role, restaurant_id FROM staff WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $_SESSION['staff_id']]);
            $staffRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            if (!empty($staffRow['restaurant_id'])) {
                $restStmt = $conn->prepare("SELECT restaurant_name, currency_symbol, restaurant_logo, business_qr_code_path FROM users WHERE restaurant_id = :restaurant_id LIMIT 1");
                $restStmt->execute([':restaurant_id' => $staffRow['restaurant_id']]);
                $restRow = $restStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $row = array_merge($staffRow, $restRow);
            } else {
                $row = $staffRow;
            }
        } catch (PDOException $e) {
            error_log("PDO Error fetching staff data: " . $e->getMessage());
            $row = [];
        }
    } else if ($isBranchAdmin) {
        try {
            $stmt = $conn->prepare("SELECT id, restaurant_name, currency_symbol, email, phone, address, description, description_format, opening_hours, payment_gateway_type, country, timezone, restaurant_logo, business_qr_code_path, google_maps_link, owner_name, enable_gst, tax_name, tax_percent, instagram_link, facebook_link, twitter_link, youtube_link, linkedin_link, enable_delivery, enable_takeaway, enable_dinein, cod_enabled, packaging_charge, delivery_radius_km, restaurant_lat, restaurant_lng, enable_km_delivery, delivery_rate_per_km FROM users WHERE restaurant_id = :restaurant_id LIMIT 1");
            $stmt->execute([':restaurant_id' => $_SESSION['restaurant_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("PDO Error fetching branch admin restaurant data: " . $e->getMessage());
            $row = [];
        }
    }

    if ($isAdmin && !empty($row['subscription_status']) && !empty($row['trial_end_date'])) {
        $subscriptionStatus = $row['subscription_status'];
        $trialEndDate = $row['trial_end_date'];
        $today = date('Y-m-d');

        if ($subscriptionStatus === 'trial' && $trialEndDate < $today) {
            try {
                $updateStmt = $conn->prepare("UPDATE users SET subscription_status = 'expired', is_active = 0 WHERE id = :id");
                $updateStmt->execute([':id' => $_SESSION['user_id']]);
                $row['subscription_status'] = 'expired';
                $row['is_active'] = 0;
            } catch (PDOException $e) {
                error_log("Error updating trial expiry: " . $e->getMessage());
            }
        }
    }

    $responseData = [
        'id' => $row['id'] ?? ($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? null),
        'user_id' => $_SESSION['user_id'] ?? null,
        'staff_id' => $_SESSION['staff_id'] ?? null,
        'username' => $_SESSION['username'] ?? ($row['member_name'] ?? null),
        'restaurant_id' => $_SESSION['restaurant_id'],
        'restaurant_name' => $_SESSION['restaurant_name'] ?? ($row['restaurant_name'] ?? 'Restaurant'),
        'email' => $row['email'] ?? $_SESSION['email'] ?? null,
        'phone' => $row['phone'] ?? null,
        'address' => $row['address'] ?? null,
        'description' => $row['description'] ?? null,
        'description_format' => $row['description_format'] ?? 'paragraph',
        'opening_hours' => $row['opening_hours'] ?? null,
        'payment_gateway_type' => $row['payment_gateway_type'] ?? 'cash_only',
        'currency_symbol' => isset($row['currency_symbol']) ? (function() use ($row) {
            require_once __DIR__ . '/../config/unicode_utils.php';
            return fixCurrencySymbol($row['currency_symbol']);
        })() : ($_SESSION['currency_symbol'] ?? null),
        'country' => $row['country'] ?? null,
        'timezone' => $row['timezone'] ?? null,
        'restaurant_logo' => $row['restaurant_logo'] ?? null,
        'business_qr_code_path' => $row['business_qr_code_path'] ?? null,
        'role' => $row['role'] ?? $_SESSION['role'] ?? 'Administrator',
        
        'subscription_status' => $row['subscription_status'] ?? null,
        'trial_end_date' => $row['trial_end_date'] ?? null,
        'renewal_date' => $row['renewal_date'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'google_maps_link' => $row['google_maps_link'] ?? null,
        'owner_name' => $row['owner_name'] ?? null,
        'enable_gst' => $row['enable_gst'] ?? 1,
        'tax_name' => $row['tax_name'] ?? 'GST',
        'tax_percent' => isset($row['tax_percent']) ? (float)$row['tax_percent'] : 5.00,
        'enable_delivery' => $row['enable_delivery'] ?? 1,
        'enable_takeaway' => $row['enable_takeaway'] ?? 1,
        'enable_dinein' => $row['enable_dinein'] ?? 1,
        'cod_enabled' => $row['cod_enabled'] ?? 1,
        'minimum_order_value' => $row['minimum_order_value'] ?? 0,
        'packaging_charge' => $row['packaging_charge'] ?? 0,
        'delivery_radius_km' => $row['delivery_radius_km'] ?? 0,
        'restaurant_lat' => (isset($row['restaurant_lat']) && $row['restaurant_lat'] !== '') ? (float)$row['restaurant_lat'] : null,
        'restaurant_lng' => (isset($row['restaurant_lng']) && $row['restaurant_lng'] !== '') ? (float)$row['restaurant_lng'] : null,
        'instagram_link' => $row['instagram_link'] ?? null,
        'facebook_link' => $row['facebook_link'] ?? null,
        'twitter_link' => $row['twitter_link'] ?? null,
        'youtube_link' => $row['youtube_link'] ?? null,
        'linkedin_link' => $row['linkedin_link'] ?? null,
        'phonepe_merchant_id' => $row['phonepe_merchant_id'] ? maskSecret($row['phonepe_merchant_id']) : null,
        'phonepe_salt_key' => $row['phonepe_salt_key'] ? maskSecret($row['phonepe_salt_key']) : null,
        'phonepe_environment' => $row['phonepe_environment'] ?? 'SANDBOX',
        'is_active' => $row['is_active'] ?? 1,
        'linked_restaurants' => $_SESSION['linked_restaurants'] ?? null,
        'user_type' => $_SESSION['user_type'] ?? ($isAdmin ? 'admin' : ($isStaff ? 'staff' : 'branch_admin')),
        'session_remaining_time' => 86400,
        'session_remaining_minutes' => 1440,
        'session_remaining_seconds' => 0,
    ];

    echo json_encode([
        'success' => true,
        'data' => $responseData
    ]);

} catch (PDOException $e) {
    error_log("PDO Error in get_session.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ]);
    exit();
} catch (Exception $e) {
    error_log("Error in get_session.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.'
    ]);
    exit();
}
