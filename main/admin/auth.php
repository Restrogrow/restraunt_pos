<?php
// Suppress error display, log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any unexpected output
ob_start();

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';

function maskSecret($val) {
    if (!$val || strlen($val) < 4) return '********';
    return str_repeat('*', max(0, strlen($val) - 4)) . substr($val, -4);
}
startSecureSession();

// Clear any output that might have been generated
ob_clean();

// Set JSON headers
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// ── Release session file lock early ──
// The session was started above for auth checks. We close it here so that
// other requests (e.g. another tab loading login.php, or parallel AJAX calls)
// don't block on the session file lock while this script does DB work.
// Handlers that need to write to $_SESSION re-acquire the lock themselves.
session_write_close();

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
    require_once __DIR__ . '/../config/countries.php';
} else {
    throw new Exception('Database connection file not found');
}

try {
    // Check if request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }
    
    // Get the action and data from POST
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    
    // Validate action
    if (empty($action)) {
        throw new Exception('Action is required');
    }
    
    // Load rate limiter for auth endpoints
    if (file_exists(__DIR__ . '/../config/rate_limit.php')) {
        require_once __DIR__ . '/../config/rate_limit.php';
        
        // Apply rate limiting to sensitive auth actions before handling
        switch ($action) {
            case 'login':
                applyAuthRateLimit('login');
                break;
            case 'signup':
                applyAuthRateLimit('signup');
                break;
            case 'forgotPassword':
                applyAuthRateLimit('forgotPwd');
                break;
            case 'resetPassword':
                applyAuthRateLimit('resetPwd');
                break;
        }
    }
    
    switch ($action) {
        case 'login':
            handleLogin();
            break;
            
        case 'signup':
            handleSignup();
            break;
            
        case 'logout':
            handleLogout();
            break;
            
        case 'updateProfile':
            handleUpdateProfile();
            break;
            
        case 'changePassword':
            handleChangePassword();
            break;
            
        case 'updateRestaurantSettings':
            handleUpdateRestaurantSettings();
            break;
            
        case 'updateSystemSettings':
            handleUpdateSystemSettings();
            break;

        case 'updatePaymentGateway':
            handleUpdatePaymentGateway();
            break;
            
        case 'uploadRestaurantLogo':
            handleUploadRestaurantLogo();
            break;
            
        case 'uploadBusinessQR':
            handleUploadBusinessQR();
            break;
            
        case 'removeBusinessQR':
            handleRemoveBusinessQR();
            break;
            
        case 'forgotPassword':
            handleForgotPassword();
            break;
            
        case 'resetPassword':
            handleResetPassword();
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (PDOException $e) {
    // Database error - log full error, show generic message to user
    error_log("Database error in auth.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Clear any output
    ob_clean();
    
    // Return generic error message to user (don't expose server details)
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Incorrect username or password',
        'error_code' => 'DB_ERROR'
    ], JSON_UNESCAPED_UNICODE);
    exit();
    
} catch (Exception $e) {
    // General error - log full error, show user-friendly message
    error_log("Error in auth.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Clear any output
    ob_clean();
    
    // For login errors, show generic message. For other errors, show the message
    $userMessage = $e->getMessage();
    if (stripos($e->getMessage(), 'username') !== false || 
        stripos($e->getMessage(), 'password') !== false || 
        stripos($e->getMessage(), 'invalid') !== false ||
        stripos($e->getMessage(), 'login') !== false) {
        $userMessage = 'Incorrect username or password';
    }
    
    // Return JSON error
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $userMessage,
        'error_code' => 'GENERAL_ERROR'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Don't flush output buffer here - let each function handle it

function handleLogin() {
    // Re-acquire session lock so we can write $_SESSION vars
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $pdo = getConnection();
    } else {
        // Fallback to global $pdo if getConnection() doesn't exist (backward compatibility)
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('Database connection not available');
        }
    }
    
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    // Validate input
    if (empty($username) || empty($password)) {
        throw new Exception('Username and password are required');
    }
    
    // Check if input looks like an email
    $isEmail = filter_var($username, FILTER_VALIDATE_EMAIL);
    
    // First, try to find in users table (Admin)
    // Try username first, then email if input looks like email
    if ($isEmail) {
        // If input is email, try to find by email
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = null; // Free statement
        
        // If not found by email, try username as fallback
        if (!$user) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt = null; // Free statement
        }
    } else {
        // If not email, try username first
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = null; // Free statement
        
        // If not found by username and input contains @, try email as fallback
        if (!$user && strpos($username, '@') !== false) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt = null; // Free statement
        }
    }
    
    if ($user && password_verify($password, $user['password'])) {
        // Admin/User login success — clear failed attempts
        if (function_exists('clearFailedAttempts')) {
            clearFailedAttempts(getRateLimitIdentifier());
        }
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['restaurant_id'] = $user['restaurant_id'];
        $_SESSION['restaurant_name'] = $user['restaurant_name'];
        $_SESSION['user_type'] = 'admin';
        $_SESSION['role'] = 'Admin';
        
        // Regenerate session ID after successful login for security
        regenerateSessionAfterLogin();
        
        // Clear any output before JSON
        ob_clean();
        
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => '../views/dashboard.php',
            'data' => [
                'username' => $user['username'],
                'restaurant_id' => $user['restaurant_id'],
                'restaurant_name' => $user['restaurant_name'],
                'user_type' => 'admin',
                'role' => 'Admin'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // If not found in users table, check staff table
    // Try email first, then phone as username
    $staffStmt = $pdo->prepare("SELECT s.*, u.restaurant_name FROM staff s LEFT JOIN users u ON s.restaurant_id = u.restaurant_id WHERE (s.email = ? OR s.phone = ?) AND s.is_active = 1 LIMIT 1");
    $staffStmt->execute([$username, $username]);
    $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);
    $staffStmt = null; // Free statement
    
    if ($staff && password_verify($password, $staff['password'])) {
        // Staff login success — clear failed attempts
        if (function_exists('clearFailedAttempts')) {
            clearFailedAttempts(getRateLimitIdentifier());
        }
        $_SESSION['staff_id'] = $staff['id'];
        $_SESSION['username'] = $staff['member_name'];
        $_SESSION['email'] = $staff['email'];
        $_SESSION['restaurant_id'] = $staff['restaurant_id'];
        $_SESSION['restaurant_name'] = $staff['restaurant_name'] ?? 'Restaurant';
        $_SESSION['user_type'] = 'staff';
        $_SESSION['role'] = $staff['role'];
        
        // Regenerate session ID after successful login for security
        regenerateSessionAfterLogin();
        
        // Redirect based on role to appropriate dashboard
        $role = $staff['role'];
        switch ($role) {
            case 'Waiter':
                $redirect = '../views/waiter_dashboard.php';
                break;
            case 'Chef':
                $redirect = '../views/chef_dashboard.php';
                break;
            case 'Manager':
                $redirect = '../views/manager_dashboard.php';
                break;
            case 'Admin':
            default:
                $redirect = '../views/dashboard.php';
                break;
        }
        
        // Clear any output before JSON
        ob_clean();
        
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => $redirect,
            'data' => [
                'username' => $staff['member_name'],
                'restaurant_id' => $staff['restaurant_id'],
                'restaurant_name' => $staff['restaurant_name'] ?? 'Restaurant',
                'user_type' => 'staff',
                'role' => $staff['role']
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // If not found in users or staff, check branch_admins table
    // Auto-create tables if missing
    try { $pdo->query("SELECT id FROM branch_admins LIMIT 1"); } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS branch_admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(100) DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS branch_restaurant_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_admin_id INT NOT NULL,
            restaurant_id VARCHAR(10) NOT NULL,
            label VARCHAR(100) DEFAULT NULL,
            FOREIGN KEY (branch_admin_id) REFERENCES branch_admins(id) ON DELETE CASCADE
        )");
    }
    $branchStmt = $pdo->prepare("SELECT * FROM branch_admins WHERE username = ? AND is_active = 1 LIMIT 1");
    $branchStmt->execute([$username]);
    $branchAdmin = $branchStmt->fetch(PDO::FETCH_ASSOC);
    $branchStmt = null;

    if ($branchAdmin && password_verify($password, $branchAdmin['password_hash'])) {
        // Branch admin login success — clear failed attempts
        if (function_exists('clearFailedAttempts')) {
            clearFailedAttempts(getRateLimitIdentifier());
        }
        // Get linked restaurants
        $linkStmt = $pdo->prepare("
            SELECT brl.restaurant_id, brl.label, u.restaurant_name 
            FROM branch_restaurant_links brl 
            JOIN users u ON u.restaurant_id = brl.restaurant_id 
            WHERE brl.branch_admin_id = ?
        ");
        $linkStmt->execute([$branchAdmin['id']]);
        $linked = $linkStmt->fetchAll(PDO::FETCH_ASSOC);
        $linkStmt = null;

        if (empty($linked)) {
            throw new Exception('No linked restaurants found for this account');
        }

        $_SESSION['branch_admin_id'] = $branchAdmin['id'];
        $_SESSION['username'] = $branchAdmin['username'];
        $_SESSION['user_type'] = 'branch_admin';
        $_SESSION['role'] = 'Branch Admin';
        $_SESSION['linked_restaurants'] = $linked;
        $_SESSION['restaurant_id'] = $linked[0]['restaurant_id'];
        $_SESSION['restaurant_name'] = $linked[0]['restaurant_name'];

        regenerateSessionAfterLogin();

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => '../views/dashboard.php',
            'data' => [
                'username' => $branchAdmin['username'],
                'restaurant_id' => $linked[0]['restaurant_id'],
                'restaurant_name' => $linked[0]['restaurant_name'],
                'user_type' => 'branch_admin',
                'role' => 'Branch Admin',
                'linked_restaurants' => $linked
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Track failed login attempt for progressive lockout
    if (function_exists('trackFailedAttempt') && function_exists('getRateLimitIdentifier')) {
        trackFailedAttempt(getRateLimitIdentifier());
    }
    
    // If neither found, throw error
    throw new Exception('Invalid username, email or password');
}

function handleSignup() {
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $pdo = getConnection();
    } else {
        // Fallback to global $pdo if getConnection() doesn't exist (backward compatibility)
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('Database connection not available');
        }
    }
    
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $restaurantName = isset($_POST['restaurant_name']) ? trim($_POST['restaurant_name']) : '';
    $country = isset($_POST['country']) ? trim($_POST['country']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';

    // Validate input
    if (empty($username) || empty($password) || empty($restaurantName) || empty($country) || empty($phone)) {
        throw new Exception('All fields are required');
    }

    // Derive default currency from the chosen country (still editable later in Settings)
    $countryInfo = getCountryByName($country);
    $currencySymbol = $countryInfo['currency_symbol'] ?? '₹';
    
    if (strlen($username) < 3) {
        throw new Exception('Username must be at least 3 characters long');
    }
    
    if (strlen($password) < 6) {
        throw new Exception('Password must be at least 6 characters long');
    }
    
    if (strlen($restaurantName) < 2) {
        throw new Exception('Restaurant name must be at least 2 characters long');
    }
    
    // Check if username already exists
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $checkStmt->execute([$username]);
    if ($checkStmt->fetchColumn() > 0) {
        throw new Exception('Username already exists');
    }
    
    // Generate restaurant ID
    $restaurantId = generateRestaurantId();
    
    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert new user with 30-day trial period, currency derived from chosen country
    $insertStmt = $pdo->prepare("
        INSERT INTO users (username, password, restaurant_id, restaurant_name, country, phone, currency_symbol, subscription_status, trial_end_date, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'trial', DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY), NOW(), NOW())
    ");
    $result = $insertStmt->execute([$username, $hashedPassword, $restaurantId, $restaurantName, $country, $phone, $currencySymbol]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Account created successfully',
            'data' => [
                'username' => $username,
                'restaurant_id' => $restaurantId,
                'restaurant_name' => $restaurantName
            ]
        ]);
    } else {
        throw new Exception('Failed to create account');
    }
}

function handleLogout() {
    // Re-acquire session lock before destroying it
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    // Use secure session destruction
    destroySession();
    
    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully'
    ]);
}

function generateRestaurantId() {
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $pdo = getConnection();
    } else {
        // Fallback to global $pdo if getConnection() doesn't exist (backward compatibility)
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('Database connection not available');
        }
    }
    
    // Get the highest existing restaurant ID
    $stmt = $pdo->prepare("SELECT restaurant_id FROM users ORDER BY restaurant_id DESC LIMIT 1");
    $stmt->execute();
    $lastId = $stmt->fetchColumn();
    
    if ($lastId) {
        // Extract number from last ID (e.g., RES001 -> 1)
        $lastNumber = (int)substr($lastId, 3);
        $newNumber = $lastNumber + 1;
    } else {
        $newNumber = 1;
    }
    
    // Format as RES001, RES002, etc.
    return 'RES' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);
}

function handleUpdateProfile() {
    // Re-acquire session lock so we can write $_SESSION vars
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $pdo = getConnection();
    } else {
        // Fallback to global $pdo if getConnection() doesn't exist (backward compatibility)
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('Database connection not available');
        }
    }
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('You must be logged in to update your profile');
    }
    
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    
    // Validate input
    if (empty($username)) {
        throw new Exception('Username is required');
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Valid email address is required');
    }
    
    if (strlen($username) < 3) {
        throw new Exception('Username must be at least 3 characters long');
    }
    
    $userId = $_SESSION['user_id'];
    
    // Check if username is already taken by another user
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $checkStmt->execute([$username, $userId]);
    if ($checkStmt->fetch()) {
        throw new Exception('Username already exists');
    }
    
    // Check if email is already taken by another user
    $checkEmailStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $checkEmailStmt->execute([$email, $userId]);
    if ($checkEmailStmt->fetch()) {
        throw new Exception('Email already exists');
    }
    
    // Update user profile - handle email column existence
    try {
        $updateStmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, updated_at = NOW() WHERE id = ?");
        $result = $updateStmt->execute([$username, $email, $userId]);
    } catch (PDOException $e) {
        // If email column doesn't exist, update without it
        if (strpos($e->getMessage(), 'email') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
            $updateStmt = $pdo->prepare("UPDATE users SET username = ?, updated_at = NOW() WHERE id = ?");
            $result = $updateStmt->execute([$username, $userId]);
        } else {
            throw $e;
        }
    }
    
    if ($result) {
        // Update session
        $_SESSION['username'] = $username;
        
        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'username' => $username,
                'email' => $email
            ]
        ]);
    } else {
        throw new Exception('Failed to update profile');
    }
}

function handleChangePassword() {
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $pdo = getConnection();
    } else {
        // Fallback to global $pdo if getConnection() doesn't exist (backward compatibility)
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('Database connection not available');
        }
    }
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('You must be logged in to change your password');
    }
    
    $currentPassword = isset($_POST['currentPassword']) ? $_POST['currentPassword'] : '';
    $newPassword = isset($_POST['newPassword']) ? $_POST['newPassword'] : '';
    
    // Validate input
    if (empty($currentPassword)) {
        throw new Exception('Current password is required');
    }
    
    if (empty($newPassword)) {
        throw new Exception('New password is required');
    }
    
    if (strlen($newPassword) < 6) {
        throw new Exception('New password must be at least 6 characters long');
    }
    
    $userId = $_SESSION['user_id'];
    
    // Get current user password - check both users and staff tables
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If not found in users table, check staff table
    if (!$user && isset($_SESSION['staff_id'])) {
        $staffStmt = $pdo->prepare("SELECT password FROM staff WHERE id = ?");
        $staffStmt->execute([$_SESSION['staff_id']]);
        $user = $staffStmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    // Verify current password
    if (!password_verify($currentPassword, $user['password'])) {
        throw new Exception('Current password is incorrect');
    }
    
    // Hash new password
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // Update password in appropriate table
    if (isset($_SESSION['staff_id'])) {
        $updateStmt = $pdo->prepare("UPDATE staff SET password = ?, updated_at = NOW() WHERE id = ?");
        $result = $updateStmt->execute([$hashedPassword, $_SESSION['staff_id']]);
    } else {
        $updateStmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $result = $updateStmt->execute([$hashedPassword, $userId]);
    }
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    } else {
        throw new Exception('Failed to change password');
    }
}

function handleUpdateRestaurantSettings() {
    // Re-acquire session lock so we can write $_SESSION vars
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    if (function_exists('getConnection')) {
        $pdo = getConnection();
    } else {
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('Database connection not available');
        }
    }
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['restaurant_id'])) {
        throw new Exception('You must be logged in to update restaurant settings');
    }
    
    $restaurantName = isset($_POST['restaurant_name']) ? trim($_POST['restaurant_name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $descriptionFormat = isset($_POST['description_format']) ? trim($_POST['description_format']) : 'paragraph';
    $openingHours = isset($_POST['opening_hours']) ? trim($_POST['opening_hours']) : '';
    // Cashfree removed — use PhonePe only
    // Mass assignment protection: fetch existing payment credentials first
    // so they aren't accidentally wiped when saving other settings
    $existingStmt = $pdo->prepare("SELECT phonepe_merchant_id, phonepe_salt_key, phonepe_environment FROM users WHERE id = ?");
    $existingStmt->execute([$userId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    // Only overwrite if a value is explicitly provided; otherwise preserve existing
    $phonepeMerchantId = (isset($_POST['phonepe_merchant_id']) && $_POST['phonepe_merchant_id'] !== '') ? trim($_POST['phonepe_merchant_id']) : ($existing['phonepe_merchant_id'] ?? '');
    $phonepeSaltKey = (isset($_POST['phonepe_salt_key']) && $_POST['phonepe_salt_key'] !== '') ? trim($_POST['phonepe_salt_key']) : ($existing['phonepe_salt_key'] ?? '');
    $phonepeEnvironment = isset($_POST['phonepe_environment']) ? trim($_POST['phonepe_environment']) : ($existing['phonepe_environment'] ?? 'SANDBOX');
    $minimumOrderValue = isset($_POST['minimum_order_value']) && $_POST['minimum_order_value'] !== '' ? floatval($_POST['minimum_order_value']) : 0;
    $packagingCharge = isset($_POST['packaging_charge']) && $_POST['packaging_charge'] !== '' ? floatval($_POST['packaging_charge']) : 0;
    $deliveryRadius = isset($_POST['delivery_radius_km']) && $_POST['delivery_radius_km'] !== '' ? floatval($_POST['delivery_radius_km']) : 0;
    $clientLat = isset($_POST['restaurant_lat']) && $_POST['restaurant_lat'] !== '' ? floatval($_POST['restaurant_lat']) : null;
    $clientLng = isset($_POST['restaurant_lng']) && $_POST['restaurant_lng'] !== '' ? floatval($_POST['restaurant_lng']) : null;
    $googleMapsLink = isset($_POST['google_maps_link']) ? trim($_POST['google_maps_link']) : '';
    $ownerName = isset($_POST['owner_name']) ? trim($_POST['owner_name']) : '';
    $enableGst = isset($_POST['enable_gst']) ? (int)$_POST['enable_gst'] : 1;
    $taxName = isset($_POST['tax_name']) && trim($_POST['tax_name']) !== '' ? trim($_POST['tax_name']) : 'GST';
    $taxPercent = isset($_POST['tax_percent']) && $_POST['tax_percent'] !== '' ? (float)$_POST['tax_percent'] : 5.00;
    if ($taxPercent < 0) $taxPercent = 0;
    if ($taxPercent > 100) $taxPercent = 100;
    $enableDelivery = isset($_POST['enable_delivery']) ? (int)$_POST['enable_delivery'] : 1;
    $enableTakeaway = isset($_POST['enable_takeaway']) ? (int)$_POST['enable_takeaway'] : 1;
    $enableDinein = isset($_POST['enable_dinein']) ? (int)$_POST['enable_dinein'] : 1;
    $codEnabled = isset($_POST['cod_enabled']) ? (int)$_POST['cod_enabled'] : 1;
    $enableLanguage = isset($_POST['enable_language']) ? (int)$_POST['enable_language'] : 1;
    $instagramLink = isset($_POST['instagram_link']) ? trim($_POST['instagram_link']) : '';
    $facebookLink = isset($_POST['facebook_link']) ? trim($_POST['facebook_link']) : '';
    $twitterLink = isset($_POST['twitter_link']) ? trim($_POST['twitter_link']) : '';
    $youtubeLink = isset($_POST['youtube_link']) ? trim($_POST['youtube_link']) : '';
    $linkedinLink = isset($_POST['linkedin_link']) ? trim($_POST['linkedin_link']) : '';
    
    if (empty($restaurantName)) {
        throw new Exception('Restaurant name is required');
    }
    
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Valid email address is required');
    }
    
    $userId = $_SESSION['user_id'];
    $restaurantId = $_SESSION['restaurant_id'];
    
    if (!empty($email)) {
        $checkEmailStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $checkEmailStmt->execute([$email, $userId]);
        if ($checkEmailStmt->fetch()) {
            throw new Exception('Email already exists');
        }
    }
    
    
    try {
        $updateStmt = $pdo->prepare("UPDATE users SET restaurant_name = ?, email = ?, phone = ?, address = ?, description = ?, description_format = ?, opening_hours = ?, phonepe_merchant_id = ?, phonepe_salt_key = ?, phonepe_environment = ?, minimum_order_value = ?, google_maps_link = ?, owner_name = ?, enable_gst = ?, tax_name = ?, tax_percent = ?, instagram_link = ?, facebook_link = ?, twitter_link = ?, youtube_link = ?, linkedin_link = ?, enable_delivery = ?, enable_takeaway = ?, enable_dinein = ?, cod_enabled = ?, enable_language = ?, packaging_charge = ?, delivery_radius_km = ?, updated_at = NOW() WHERE id = ?");
        $result = $updateStmt->execute([$restaurantName, $email, $phone, $address, $description, $descriptionFormat, $openingHours, $phonepeMerchantId, $phonepeSaltKey, $phonepeEnvironment, $minimumOrderValue, $googleMapsLink, $ownerName, $enableGst, $taxName, $taxPercent, $instagramLink, $facebookLink, $twitterLink, $youtubeLink, $linkedinLink, $enableDelivery, $enableTakeaway, $enableDinein, $codEnabled, $enableLanguage, $packagingCharge, $deliveryRadius, $userId]);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'minimum_order_value') !== false || strpos($msg, 'Unknown column') !== false) {
            try {
                $updateStmt = $pdo->prepare("UPDATE users SET restaurant_name = ?, email = ?, phone = ?, address = ?, opening_hours = ?, updated_at = NOW() WHERE id = ?");
                $result = $updateStmt->execute([$restaurantName, $email, $phone, $address, $openingHours, $userId]);
            } catch (PDOException $e2) {
                $msg2 = $e2->getMessage();
                if (strpos($msg2, 'phone') !== false || strpos($msg2, 'address') !== false || strpos($msg2, 'Unknown column') !== false) {
                            try {
                        $updateStmt = $pdo->prepare("UPDATE users SET restaurant_name = ?, email = ?, updated_at = NOW() WHERE id = ?");
                        $result = $updateStmt->execute([$restaurantName, $email, $userId]);
                    } catch (PDOException $e3) {
                        $msg3 = $e3->getMessage();
                        if (strpos($msg3, 'email') !== false || strpos($msg3, 'Unknown column') !== false) {
                            $updateStmt = $pdo->prepare("UPDATE users SET restaurant_name = ?, updated_at = NOW() WHERE id = ?");
                            $result = $updateStmt->execute([$restaurantName, $userId]);
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
    
    if ($result) {
        // Reset language to English if language support is disabled
        if (!$enableLanguage) {
            try {
                $pdo->prepare("UPDATE users SET language = 'en' WHERE id = ?")->execute([$userId]);
                $_SESSION['language'] = 'en';
            } catch (Exception $e) {}
        }
        
        $_SESSION['restaurant_name'] = $restaurantName;
        if (!empty($email)) {
            $_SESSION['email'] = $email;
        }
        
        // ── Store restaurant coordinates ──
        // Prefer coordinates the client already resolved (via Places Autocomplete or the
        // map picker) — they're more accurate and avoid an extra geocoding call. Only fall
        // back to server-side geocoding when the admin typed the address manually.
        if ($clientLat !== null && $clientLng !== null && $clientLat && $clientLng) {
            $updateCoords = $pdo->prepare("UPDATE users SET restaurant_lat = ?, restaurant_lng = ?, updated_at = NOW() WHERE id = ?");
            $updateCoords->execute([$clientLat, $clientLng, $userId]);
        } elseif ($deliveryRadius > 0 && !empty($address)) {
            // Load env_loader for Google Maps API key if not already loaded
            if (!function_exists('env') && file_exists(__DIR__ . '/../config/env_loader.php')) {
                require_once __DIR__ . '/../config/env_loader.php';
            }
            $geoKey = function_exists('env') ? env('GOOGLE_MAPS_API_KEY', '') : '';
            if (!empty($geoKey)) {
                $geoUrl = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($address) . '&key=' . $geoKey;
                $ch = curl_init();
                if ($ch !== false) {
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $geoUrl,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 5,
                        CURLOPT_SSL_VERIFYPEER => true
                    ]);
                    $geoResp = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($httpCode === 200 && $geoResp) {
                        $geoData = json_decode($geoResp, true);
                        if (($geoData['status'] ?? '') === 'OK' && !empty($geoData['results'][0]['geometry']['location'])) {
                            $lat = (float)$geoData['results'][0]['geometry']['location']['lat'];
                            $lng = (float)$geoData['results'][0]['geometry']['location']['lng'];
                            if ($lat && $lng) {
                                $updateCoords = $pdo->prepare("UPDATE users SET restaurant_lat = ?, restaurant_lng = ?, updated_at = NOW() WHERE id = ?");
                                $updateCoords->execute([$lat, $lng, $userId]);
                            }
                        }
                    }
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Restaurant settings updated successfully',
            'data' => [
                'restaurant_name' => $restaurantName,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
                'description' => $description,
                'opening_hours' => $openingHours,
                // Cashfree removed
                'phonepe_merchant_id' => maskSecret($phonepeMerchantId),
                'phonepe_salt_key' => maskSecret($phonepeSaltKey),
                'phonepe_environment' => $phonepeEnvironment,
                'minimum_order_value' => $minimumOrderValue,
                'google_maps_link' => $googleMapsLink,
                'owner_name' => $ownerName,
                'enable_gst' => $enableGst,
                'enable_delivery' => $enableDelivery,
                'enable_takeaway' => $enableTakeaway,
                'enable_dinein' => $enableDinein,
                'cod_enabled' => $codEnabled,
                'enable_language' => $enableLanguage,
                'packaging_charge' => $packagingCharge,
                'instagram_link' => $instagramLink,
                'facebook_link' => $facebookLink,
                'twitter_link' => $twitterLink,
                'youtube_link' => $youtubeLink,
                'linkedin_link' => $linkedinLink
            ]
        ]);
    } else {
        throw new Exception('Failed to update restaurant settings');
    }
}

function handleUpdatePaymentGateway() {
    if (function_exists('getConnection')) {
        $pdo = getConnection();
    } else {
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('Database connection not available');
        }
    }

    if (!isset($_SESSION['user_id']) || !isset($_SESSION['restaurant_id'])) {
        throw new Exception('You must be logged in to update payment gateway settings');
    }

    // Cashfree removed — use PhonePe only
    $phonepeMerchantId = isset($_POST['phonepe_merchant_id']) ? trim($_POST['phonepe_merchant_id']) : '';
    $phonepeSaltKey = isset($_POST['phonepe_salt_key']) ? trim($_POST['phonepe_salt_key']) : '';
    $phonepeEnvironment = isset($_POST['phonepe_environment']) ? trim($_POST['phonepe_environment']) : 'SANDBOX';
    $gatewayMode = isset($_POST['payment_gateway_mode']) ? trim($_POST['payment_gateway_mode']) : 'own';
    $userId = $_SESSION['user_id'];
    $restaurantId = $_SESSION['restaurant_id'];

    // If platform mode, clear credentials so env() fallback is used
    if ($gatewayMode === 'platform') {
        // Cashfree removed
        $phonepeMerchantId = '';
        $phonepeSaltKey = '';
        $phonepeEnvironment = 'SANDBOX';
    }

    try {
        $updateStmt = $pdo->prepare("UPDATE users SET phonepe_merchant_id = ?, phonepe_salt_key = ?, phonepe_environment = ?, payment_gateway_mode = ?, updated_at = NOW() WHERE id = ?");
        $result = $updateStmt->execute([$phonepeMerchantId, $phonepeSaltKey, $phonepeEnvironment, $gatewayMode, $userId]);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Unknown column') !== false) {
            throw new Exception('Database columns not ready. Please try again.');
        } else {
            throw $e;
        }
    }

    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Payment gateway settings updated successfully',
            'data' => [
                // Cashfree removed
                'phonepe_merchant_id' => maskSecret($phonepeMerchantId),
                'phonepe_salt_key' => maskSecret($phonepeSaltKey),
                'phonepe_environment' => $phonepeEnvironment,
                'payment_gateway_mode' => $gatewayMode
            ]
        ]);
    } else {
        throw new Exception('Failed to update payment gateway settings');
    }
}

function handleUpdateSystemSettings() {
    // Re-acquire session lock so we can write $_SESSION vars
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $pdo = getConnection();
    } else {
        // Fallback to global $pdo if getConnection() doesn't exist (backward compatibility)
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('Database connection not available');
        }
    }
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['restaurant_id'])) {
        throw new Exception('You must be logged in to update system settings');
    }
    
    $language = isset($_POST['language']) ? trim($_POST['language']) : 'en';
    $country = isset($_POST['country']) ? trim($_POST['country']) : 'India';
    $currencySymbolRaw = isset($_POST['currency_symbol']) ? trim($_POST['currency_symbol']) : '₹';
    $timezone = isset($_POST['timezone']) ? trim($_POST['timezone']) : 'Asia/Kolkata';
    $autoSync = isset($_POST['auto_sync']) ? (int)$_POST['auto_sync'] : 0;
    $notifications = isset($_POST['notifications']) ? (int)$_POST['notifications'] : 0;
    $showInstallApp = isset($_POST['show_install_app']) ? (int)$_POST['show_install_app'] : 0;
    
    // Check if language support is enabled
    $enableLanguageStmt = $pdo->prepare("SELECT enable_language FROM users WHERE id = ?");
    $enableLanguageStmt->execute([$_SESSION['user_id']]);
    $enableLangRow = $enableLanguageStmt->fetch(PDO::FETCH_ASSOC);
    $enableLanguage = isset($enableLangRow['enable_language']) ? (int)$enableLangRow['enable_language'] : 1;
    
    // If language support is disabled, force English
    if (!$enableLanguage) {
        $language = 'en';
    }
    
    // Use centralized Unicode fix function
    require_once __DIR__ . '/../config/unicode_utils.php';
    $currencySymbol = fixCurrencySymbol($currencySymbolRaw);
    
    // Validate input
    if (empty($currencySymbol)) {
        $currencySymbol = '₹';
    }
    
    if (empty($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
        $timezone = 'Asia/Kolkata';
    }

    if (empty($language)) {
        $language = 'en';
    }

    if (empty($country)) {
        $country = 'India';
    }

    $userId = $_SESSION['user_id'];
    $restaurantId = $_SESSION['restaurant_id'];
    
    // Ensure language column exists
    require_once __DIR__ . '/../config/translate_utils.php';
    ensureLanguageColumns($pdo, $restaurantId);
    
    // Update system settings - handle column existence gracefully
    try {
        // Try to update with all fields
        $updateStmt = $pdo->prepare("UPDATE users SET currency_symbol = ?, country = ?, timezone = ?, language = ?, show_install_app = ?, updated_at = NOW() WHERE id = ?");
        $result = $updateStmt->execute([$currencySymbol, $country, $timezone, $language, $showInstallApp, $userId]);
    } catch (PDOException $e) {
        // If columns don't exist, try without them
        if (strpos($e->getMessage(), 'currency_symbol') !== false ||
            strpos($e->getMessage(), 'country') !== false ||
            strpos($e->getMessage(), 'timezone') !== false ||
            strpos($e->getMessage(), 'language') !== false ||
            strpos($e->getMessage(), 'Unknown column') !== false) {
            try {
                $updateStmt = $pdo->prepare("UPDATE users SET currency_symbol = ?, timezone = ?, updated_at = NOW() WHERE id = ?");
                $result = $updateStmt->execute([$currencySymbol, $timezone, $userId]);
            } catch (PDOException $e2) {
                $updateStmt = $pdo->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
                $result = $updateStmt->execute([$userId]);
            }
        } else {
            throw $e;
        }
    }

    if ($result) {
        // Save to session so it's immediately available
        $_SESSION['currency_symbol'] = $currencySymbol;
        $_SESSION['country'] = $country;
        $_SESSION['language'] = $language;
        
        // Auto-translate all items if language changed to non-English
        if ($language !== 'en') {
            try {
                autoTranslateMenuItems($pdo, $restaurantId, $language);
            } catch (Exception $e) {
                error_log("Auto-translate error: " . $e->getMessage());
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'System settings updated successfully',
            'data' => [
                'currency_symbol' => $currencySymbol,
                'country' => $country,
                'timezone' => $timezone,
                'language' => $language
            ]
        ]);
    } else {
        throw new Exception('Failed to update system settings');
    }
}

function handleUploadRestaurantLogo() {
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $pdo = getConnection();
    } else {
        // Fallback to global $pdo if getConnection() doesn't exist (backward compatibility)
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('Database connection not available');
        }
    }
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['restaurant_id'])) {
        throw new Exception('You must be logged in to upload restaurant logo');
    }
    
    // Check if file was uploaded
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error occurred');
    }
    
    $file = $_FILES['logo'];
    
    // Validate file extension
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions)) {
        throw new Exception('Invalid file extension. Only JPG, JPEG, PNG, GIF, and WebP files are allowed.');
    }
    
    // Validate file type via content inspection
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.');
    }
    
    // Validate file size (2MB max)
    $maxSize = 2 * 1024 * 1024; // 2MB
    if ($file['size'] > $maxSize) {
        throw new Exception('File size too large. Maximum size is 2MB.');
    }
    
    // Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/../uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $extension = '';
    switch ($mimeType) {
        case 'image/jpeg':
            $extension = '.jpg';
            break;
        case 'image/png':
            $extension = '.png';
            break;
        case 'image/gif':
            $extension = '.gif';
            break;
        case 'image/webp':
            $extension = '.webp';
            break;
    }
    
    // Read image data for database storage
    $logoData = file_get_contents($file['tmp_name']);
    if ($logoData === false) {
        throw new Exception('Failed to read image file');
    }
    
    $logoPath = 'db:' . uniqid(); // Reference ID for database storage
    $userId = $_SESSION['user_id'];
    
    // Delete old logo file if exists (for backward compatibility)
    try {
        $oldLogoStmt = $pdo->prepare("SELECT restaurant_logo FROM users WHERE id = ?");
        $oldLogoStmt->execute([$userId]);
        $oldLogo = $oldLogoStmt->fetchColumn();
        
        if ($oldLogo && strpos($oldLogo, 'db:') !== 0 && file_exists(__DIR__ . '/../' . $oldLogo)) {
            @unlink(__DIR__ . '/../' . $oldLogo);
        }
    } catch (PDOException $e) {
        // Ignore if column doesn't exist yet
    }
    
    
    // Try full UPDATE first (with BLOB data)
    $blobStored = false;
    try {
        $updateStmt = $pdo->prepare("UPDATE users SET restaurant_logo = ?, logo_data = ?, logo_mime_type = ?, updated_at = NOW() WHERE id = ?");
        $result = $updateStmt->execute([$logoPath, $logoData, $mimeType, $userId]);
        $blobStored = true;
    } catch (PDOException $e) {
        error_log("Logo BLOB update failed, falling back: " . $e->getMessage());
    }
    
    if (!$blobStored) {

        // Retry full UPDATE
        try {
            $updateStmt = $pdo->prepare("UPDATE users SET restaurant_logo = ?, logo_data = ?, logo_mime_type = ?, updated_at = NOW() WHERE id = ?");
            $result = $updateStmt->execute([$logoPath, $logoData, $mimeType, $userId]);
            $blobStored = true;
        } catch (PDOException $e) {
            error_log("Logo BLOB retry also failed: " . $e->getMessage());
        }
    }
    
    if (!$blobStored) {
        // Last resort: store reference-only (no BLOB)
        try {
            $updateStmt = $pdo->prepare("UPDATE users SET restaurant_logo = ?, updated_at = NOW() WHERE id = ?");
            $result = $updateStmt->execute([$logoPath, $userId]);
            error_log("WARNING: Logo stored as db: reference only (no BLOB data) — logo_data column is missing or not writable");
        } catch (PDOException $e2) {
            throw new Exception('Failed to update restaurant logo: ' . $e2->getMessage());
        }
    }
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Restaurant logo uploaded successfully',
            'data' => [
                'restaurant_logo' => $logoPath,
                'logo_url' => 'api/image.php?type=logo&id=' . $userId
            ]
        ]);
    } else {
        throw new Exception('Failed to update restaurant logo');
    }
}

function handleUploadBusinessQR() {
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $pdo = getConnection();
    } else {
        // Fallback to global $pdo if getConnection() doesn't exist (backward compatibility)
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('Database connection not available');
        }
    }
    
    if (!isset($_FILES['business_qr']) || $_FILES['business_qr']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No QR code file uploaded');
    }
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $file = $_FILES['business_qr'];
    
    // Validate file extension
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions)) {
        throw new Exception('Invalid file extension. Only JPG, JPEG, PNG, GIF, and WebP files are allowed.');
    }
    
    // Verify MIME type from file content (required — never trust extension alone)
    if (!function_exists('finfo_open')) {
        throw new Exception('File type detection not available. Cannot validate uploaded file.');
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $actualMimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($actualMimeType, $allowedTypes)) {
        throw new Exception('Invalid QR code file type. Allowed: JPEG, PNG, GIF, WebP');
    }
    
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('QR code file too large (max 5MB)');
    }
    
    // Read image data for database storage
    $qrData = file_get_contents($file['tmp_name']);
    if ($qrData === false) {
        throw new Exception('Failed to read QR code file');
    }
    
    $qrPath = 'db:' . uniqid();
    $userId = $_SESSION['user_id'];
    
    
    // Update business QR code
    try {
        $updateStmt = $pdo->prepare("UPDATE users SET business_qr_code_path = ?, business_qr_code_data = ?, business_qr_code_mime_type = ?, updated_at = NOW() WHERE id = ?");
        $result = $updateStmt->execute([$qrPath, $qrData, $actualMimeType, $userId]);
    } catch (PDOException $e) {
        throw new Exception('Failed to update business QR code: ' . $e->getMessage());
    }
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Business QR code uploaded successfully',
            'data' => [
                'business_qr_code_path' => $qrPath,
                'qr_code_url' => 'api/image.php?type=business_qr&id=' . $userId
            ]
        ]);
    } else {
        throw new Exception('Failed to update business QR code');
    }
}

function handleRemoveBusinessQR() {
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $pdo = getConnection();
    } else {
        // Fallback to global $pdo if getConnection() doesn't exist (backward compatibility)
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('Database connection not available');
        }
    }
    
    $userId = $_SESSION['user_id'];
    
    try {
        $updateStmt = $pdo->prepare("UPDATE users SET business_qr_code_path = NULL, business_qr_code_data = NULL, business_qr_code_mime_type = NULL, updated_at = NOW() WHERE id = ?");
        $result = $updateStmt->execute([$userId]);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Business QR code removed successfully'
            ]);
        } else {
            throw new Exception('Failed to remove business QR code');
        }
    } catch (PDOException $e) {
        throw new Exception('Failed to remove business QR code: ' . $e->getMessage());
    }
}

function handleForgotPassword() {
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $pdo = getConnection();
    } else {
        // Fallback to global $pdo if getConnection() doesn't exist (backward compatibility)
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('Database connection not available');
        }
    }
    
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    
    // Validate input
    if (empty($email)) {
        throw new Exception('Email address is required');
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please enter a valid email address');
    }
    
    // Create password_reset_tokens table if it doesn't exist (do this first for cooldown check)
    try {
        // Check if table exists first
        $checkTable = $pdo->query("SHOW TABLES LIKE 'password_reset_tokens'");
        if ($checkTable->rowCount() == 0) {
            // Create table without foreign key first (to avoid constraint issues)
            $createTableStmt = $pdo->prepare("
                CREATE TABLE IF NOT EXISTS password_reset_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    token VARCHAR(64) NOT NULL UNIQUE,
                    expires_at DATETIME NOT NULL,
                    used BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_token (token),
                    INDEX idx_user_id (user_id),
                    INDEX idx_expires_at (expires_at),
                    INDEX idx_created_at (created_at)
                )
            ");
            $createTableStmt->execute();
        }
    } catch (PDOException $e) {
        error_log("Error creating password_reset_tokens table: " . $e->getMessage());
        // Continue anyway - table might already exist or we'll handle it in the insert
    }
    
    // Check cooldown for this email (check BEFORE checking if user exists, for security)
    $cooldownSeconds = 0;
    $requestCount = 0;
    
    try {
        // Count recent reset requests in the last hour for this email
        $cooldownStmt = $pdo->prepare("
            SELECT COUNT(*) as request_count, 
                   MAX(prt.created_at) as last_request,
                   TIMESTAMPDIFF(SECOND, MAX(prt.created_at), NOW()) as seconds_since_last
            FROM password_reset_tokens prt
            JOIN users u ON prt.user_id = u.id
            WHERE u.email = ? 
            AND prt.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $cooldownStmt->execute([$email]);
        $cooldownData = $cooldownStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cooldownData) {
            $requestCount = (int)$cooldownData['request_count'];
            $secondsSinceLast = (int)($cooldownData['seconds_since_last'] ?? 0);
            
            // Progressive cooldown:
            // 1st request: no cooldown
            // 2nd request: 60 seconds
            // 3rd request: 60 seconds
            // 4th+ requests: 30 minutes (1800 seconds)
            if ($requestCount >= 4) {
                $requiredCooldown = 1800; // 30 minutes
            } elseif ($requestCount >= 2) {
                $requiredCooldown = 60; // 60 seconds
            } else {
                $requiredCooldown = 0; // No cooldown
            }
            
            // Calculate remaining cooldown
            if ($requiredCooldown > 0 && $secondsSinceLast < $requiredCooldown) {
                $cooldownSeconds = $requiredCooldown - $secondsSinceLast;
            }
        }
    } catch (PDOException $e) {
        // If table doesn't exist yet or query fails, no cooldown (but log error)
        error_log("Error checking cooldown: " . $e->getMessage());
    }
    
    // If cooldown is active, return error (even if user doesn't exist, for security)
    if ($cooldownSeconds > 0) {
        $minutes = floor($cooldownSeconds / 60);
        $seconds = $cooldownSeconds % 60;
        $timeStr = $minutes > 0 ? "{$minutes} minute(s) and {$seconds} second(s)" : "{$seconds} second(s)";
        
        echo json_encode([
            'success' => false,
            'message' => "Please wait {$timeStr} before requesting another password reset.",
            'cooldown_seconds' => $cooldownSeconds
        ]);
        return;
    }
    
    // Check if email exists in users table
    $stmt = $pdo->prepare("SELECT id, username, restaurant_name, email FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Always return success message (security: don't reveal if email exists)
    if (!$user) {
        // Still return success to prevent email enumeration
        echo json_encode([
            'success' => true,
            'message' => 'If the email exists in our system, a password reset link has been sent.'
        ]);
        return;
    }
    
    // Create password reset token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes')); // Token expires in 5 minutes
    
    // Invalidate any existing tokens for this user
    try {
        $invalidateStmt = $pdo->prepare("UPDATE password_reset_tokens SET used = TRUE WHERE user_id = ? AND used = FALSE");
        $invalidateStmt->execute([$user['id']]);
    } catch (PDOException $e) {
        // Ignore if table doesn't exist yet
        error_log("Error invalidating tokens: " . $e->getMessage());
    }
    
    // Insert new token
    try {
        $insertStmt = $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
        $insertStmt->execute([$user['id'], $token, $expiresAt]);
    } catch (PDOException $e) {
        error_log("Error creating reset token: " . $e->getMessage());
        throw new Exception('Failed to create reset token. Please try again later.');
    }
    
    // Generate reset link
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $resetLink = $protocol . '://' . $host . dirname($_SERVER['PHP_SELF']) . '/reset_password.php?token=' . $token;
    
    // Include email configuration if available
    if (file_exists(__DIR__ . '/../config/email_config.php')) {
        require_once __DIR__ . '/../config/email_config.php';
    }
    
    // Send email
    $subject = 'Password Reset Request - ' . $user['restaurant_name'];
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #151A2D; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f9f9f9; }
            .button { display: inline-block; padding: 12px 24px; background: #151A2D; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            .footer { padding: 20px; text-align: center; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Password Reset Request</h2>
            </div>
            <div class='content'>
                <p>Hello,</p>
                <p>We received a request to reset your password for your restaurant account: <strong>" . htmlspecialchars($user['restaurant_name']) . "</strong></p>
                <p>Click the button below to reset your password:</p>
                <p style='text-align: center;'>
                    <a href='" . $resetLink . "' class='button'>Reset Password</a>
                </p>
                <p>Or copy and paste this link into your browser:</p>
                <p style='word-break: break-all; color: #666;'>" . $resetLink . "</p>
                <p><strong>This link will expire in 5 minutes.</strong></p>
                <p>If you did not request a password reset, please ignore this email. Your password will remain unchanged.</p>
            </div>
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Try to send email using the email helper function if available
    $mailSent = false;
    $mailError = '';
    
    if (function_exists('sendEmail')) {
        // Use SMTP email function
        $emailResult = sendEmail($email, $subject, $message);
        if (is_array($emailResult)) {
            $mailSent = $emailResult['success'] ?? false;
            $mailError = $emailResult['error'] ?? '';
        } else {
            // Legacy boolean return
            $mailSent = $emailResult;
        }
        
        if (!$mailSent) {
            if (empty($mailError)) {
                $mailError = 'SMTP email sending failed';
            }
            error_log("Failed to send password reset email via SMTP to: " . $email . " - Error: " . $mailError);
        }
    } else {
        // Fallback to PHP mail() function
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: Restaurant POS System <noreply@" . $host . ">" . "\r\n";
        
        $mailSent = @mail($email, $subject, $message, $headers);
        
        if (!$mailSent) {
            $lastError = error_get_last();
            $mailError = $lastError ? ($lastError['message'] ?? 'Mail function failed') : 'Mail function returned false';
            error_log("Failed to send password reset email to: " . $email . " - Error: " . $mailError);
        }
    }
    
    // Check if we're in development mode (localhost or local IP)
    $isDevelopment = (
        in_array($host, ['localhost', '127.0.0.1']) || 
        strpos($host, 'localhost') !== false || 
        strpos($host, '127.0.0.1') !== false ||
        strpos($host, '192.168.') !== false ||
        strpos($host, '10.0.') !== false ||
        (isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] === 'localhost') ||
        (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] === '127.0.0.1')
    );
    
    // Return success message (don't expose reset link in response for security)
    $responseMessage = 'If the email exists in our system, a password reset link has been sent to your email address. Please check your inbox and spam folder.';
    
    if (!$mailSent && !empty($mailError)) {
        error_log("Password reset email failed for: " . $email . " - Error: " . $mailError);
        // Still return success to prevent email enumeration, but log the error
    }
    
    echo json_encode([
        'success' => true,
        'message' => $responseMessage,
        // Don't include reset_link in response for security
        'email_sent' => $mailSent,
        'expires_in' => '5 minutes'
    ]);
}

function handleResetPassword() {
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $pdo = getConnection();
    } else {
        // Fallback to global $pdo if getConnection() doesn't exist (backward compatibility)
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('Database connection not available');
        }
    }
    
    $token = isset($_POST['token']) ? trim($_POST['token']) : '';
    $newPassword = isset($_POST['newPassword']) ? $_POST['newPassword'] : '';
    $confirmPassword = isset($_POST['confirmPassword']) ? $_POST['confirmPassword'] : '';
    
    // Validate input
    if (empty($token)) {
        throw new Exception('Reset token is required');
    }
    
    if (empty($newPassword)) {
        throw new Exception('New password is required');
    }
    
    if (strlen($newPassword) < 6) {
        throw new Exception('Password must be at least 6 characters long');
    }
    
    if ($newPassword !== $confirmPassword) {
        throw new Exception('Passwords do not match');
    }
    
    // Ensure password_reset_tokens table exists
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'password_reset_tokens'");
        if ($checkTable->rowCount() == 0) {
            // Create table if it doesn't exist
            $createTableStmt = $pdo->prepare("
                CREATE TABLE IF NOT EXISTS password_reset_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    token VARCHAR(64) NOT NULL UNIQUE,
                    expires_at DATETIME NOT NULL,
                    used BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_token (token),
                    INDEX idx_user_id (user_id),
                    INDEX idx_expires_at (expires_at)
                )
            ");
            $createTableStmt->execute();
        }
    } catch (PDOException $e) {
        error_log("Error checking/creating password_reset_tokens table: " . $e->getMessage());
        // Continue anyway, might be a permission issue
    }
    
    // Verify token
    try {
        // First check if token exists and get expiration info
        $stmt = $pdo->prepare("
            SELECT prt.user_id, prt.expires_at, prt.used, u.id, u.username,
                   NOW() as server_time,
                   TIMESTAMPDIFF(SECOND, NOW(), prt.expires_at) as seconds_remaining
            FROM password_reset_tokens prt
            JOIN users u ON prt.user_id = u.id
            WHERE prt.token = ?
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tokenData) {
            throw new Exception('Invalid reset token. Please request a new password reset.');
        }
        
        // Check if token is already used
        if ($tokenData['used']) {
            throw new Exception('This reset token has already been used. Please request a new password reset.');
        }
        
        // Check if token is expired (using server time)
        $currentTime = new DateTime();
        $expiresAt = new DateTime($tokenData['expires_at']);
        
        if ($currentTime > $expiresAt) {
            $minutesExpired = round(($currentTime->getTimestamp() - $expiresAt->getTimestamp()) / 60);
            throw new Exception('Reset token has expired. Please request a new password reset. (Expired ' . $minutesExpired . ' minute(s) ago)');
        }
        
        // Hash new password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update password
        $updateStmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $updateResult = $updateStmt->execute([$hashedPassword, $tokenData['user_id']]);
        
        if (!$updateResult) {
            throw new Exception('Failed to update password. Please try again.');
        }
        
        // Mark token as used
        $markUsedStmt = $pdo->prepare("UPDATE password_reset_tokens SET used = TRUE WHERE token = ?");
        $markUsedStmt->execute([$token]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Password has been reset successfully. You can now login with your new password.'
        ]);
        
    } catch (PDOException $e) {
        error_log("Error resetting password: " . $e->getMessage());
        // Check if it's a table doesn't exist error
        if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Unknown table") !== false) {
            throw new Exception('Password reset system not properly initialized. Please contact support.');
        }
        // For other database errors, provide more helpful message
        throw new Exception('Database error: ' . $e->getMessage());
    }
}
?>
