<?php
// Start output buffering to catch any unexpected output
ob_start();

// Suppress error display, log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set error handler to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean(); // Clear any output
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => 'A fatal error occurred: ' . $error['message'],
            'error' => $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line'],
            'type' => 'FatalError'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
});

// Set custom error handler for warnings/notices
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (error_reporting() === 0) {
        return false;
    }
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return false; // Let PHP handle it normally
});

try {
    // Include secure session configuration
    require_once __DIR__ . '/../config/session_config.php';
    startSecureSession();
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    error_log("Session init error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred during initialization.',
        'type' => 'SessionError'
    ], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Error $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    error_log("Fatal init error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred during initialization.',
        'type' => 'InitError'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // Include authorization configuration
    require_once __DIR__ . '/../config/authorization_config.php';
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    error_log("Auth config error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Authorization configuration error.',
        'type' => 'AuthConfigError'
    ], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Error $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    error_log("Auth fatal error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Authorization error.',
        'type' => 'AuthError'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Ensure no output before headers
ob_clean();

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

try {
    // Require permission to manage reservations
    requirePermission(PERMISSION_MANAGE_RESERVATIONS);
} catch (Exception $e) {
    error_log("Permission error: " . $e->getMessage());
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied.',
        'type' => 'PermissionError'
    ], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Error $e) {
    error_log("Permission check error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred checking permissions.',
        'type' => 'PermissionCheckError'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Include database connection
if (file_exists(__DIR__ . '/../db_connection.php')) {
    require_once __DIR__ . '/../db_connection.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection file not found'], JSON_UNESCAPED_UNICODE);
    exit();
}

// Include validation and rate limiting
if (file_exists(__DIR__ . '/../config/validation.php')) {
    require_once __DIR__ . '/../config/validation.php';
}
if (file_exists(__DIR__ . '/../config/rate_limit.php')) {
    require_once __DIR__ . '/../config/rate_limit.php';
    // Apply rate limiting: 30 requests per minute
    applyRateLimit(30, 60);
}

// Check request size (max 5MB for POST requests)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sizeCheck = checkRequestSize(5);
    if (!$sizeCheck['valid']) {
        http_response_code(413);
        echo json_encode(['success' => false, 'message' => $sizeCheck['message']], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

$restaurant_id = $_SESSION['restaurant_id'];

try {
    // Check if request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }
    
    // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }
    
    // Get the action and data from POST
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    $reservationId = isset($_POST['reservationId']) ? (int)$_POST['reservationId'] : 0;
    $reservationDate = isset($_POST['reservationDate']) ? trim($_POST['reservationDate']) : '';
    $timeSlot = isset($_POST['timeSlot']) ? trim($_POST['timeSlot']) : '';
    $noOfGuests = isset($_POST['noOfGuests']) ? (int)$_POST['noOfGuests'] : 1;
    $mealType = isset($_POST['mealType']) ? trim($_POST['mealType']) : 'Lunch';
    $customerName = isset($_POST['customerName']) ? trim($_POST['customerName']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $specialRequest = isset($_POST['specialRequest']) ? trim($_POST['specialRequest']) : '';
    $tableId = isset($_POST['selectTable']) && $_POST['selectTable'] !== '' ? (int)$_POST['selectTable'] : NULL;
    $status = isset($_POST['status']) ? trim($_POST['status']) : 'Pending';
    
    // Validate action
    if (empty($action)) {
        throw new Exception('Action is required');
    }
    
    // Validate required fields for add and update actions using validation functions
    if (in_array($action, ['add', 'update'])) {
        $validationErrors = [];
        $fieldErrors = [];
        
        // Validate date
        $dateValidation = validateDate($reservationDate, 'Y-m-d');
        if (!$dateValidation['valid']) {
            $validationErrors[] = $dateValidation['message'];
            $fieldErrors['reservationDate'] = $dateValidation['message'];
        } else {
            $reservationDate = $dateValidation['value'];
        }
        
        // Validate time slot
        $timeValidation = validateTime($timeSlot);
        if (!$timeValidation['valid']) {
            $validationErrors[] = $timeValidation['message'];
            $fieldErrors['timeSlot'] = $timeValidation['message'];
        } else {
            $timeSlot = $timeValidation['value'];
        }
        
        // Validate customer name
        $nameValidation = validateString($customerName, 2, 100, true);
        if (!$nameValidation['valid']) {
            $validationErrors[] = $nameValidation['message'];
            $fieldErrors['customerName'] = $nameValidation['message'];
        } else {
            $customerName = sanitizeString($nameValidation['value']);
        }
        
        // Validate phone number - optional, but if provided must be exactly 10 digits
        if (!empty($phone)) {
            $phoneDigits = preg_replace('/\D/', '', $phone); // Remove all non-digit characters
            if (empty($phoneDigits)) {
                // Phone was provided but contains no digits
                $validationErrors[] = 'Phone number must contain at least 10 digits';
                $fieldErrors['phone'] = 'Phone number must contain at least 10 digits. Please enter a valid 10-digit phone number.';
            } elseif (strlen($phoneDigits) !== 10) {
                $validationErrors[] = 'Phone number must be exactly 10 digits';
                $fieldErrors['phone'] = 'Phone number must be exactly 10 digits. Please enter a valid 10-digit phone number.';
            } else {
                $phone = $phoneDigits; // Use cleaned phone number
            }
        } else {
            // Phone is optional, set to empty string
            $phone = '';
        }
        
        // Validate email if provided
        if (!empty($email)) {
            $emailValidation = validateEmail($email);
            if (!$emailValidation['valid']) {
                $validationErrors[] = $emailValidation['message'];
                $fieldErrors['email'] = $emailValidation['message'];
            } else {
                $email = $emailValidation['value'];
            }
        }
        
        // Validate number of guests
        $guestsValidation = validateInteger($noOfGuests, 1, 50);
        if (!$guestsValidation['valid']) {
            $validationErrors[] = $guestsValidation['message'];
            $fieldErrors['noOfGuests'] = $guestsValidation['message'];
        } else {
            $noOfGuests = $guestsValidation['value'];
        }
        
        // Sanitize special request
        if (!empty($specialRequest)) {
            $specialRequest = sanitizeString($specialRequest);
            if (strlen($specialRequest) > 500) {
                $validationErrors[] = 'Special request must be less than 500 characters';
                $fieldErrors['specialRequest'] = 'Special request must be less than 500 characters';
            }
        }
        
        // If there are validation errors, return them
        if (!empty($validationErrors)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Validation failed. Please check the form for errors.',
                'errors' => $validationErrors,
                'field_errors' => $fieldErrors
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }
    
    // Validate reservation ID for update and delete actions
    if (in_array($action, ['update', 'delete']) && $reservationId <= 0) {
        throw new Exception('Invalid reservation ID');
    }
    
    switch ($action) {
        case 'add':
            // Verify table belongs to this restaurant if specified
            if ($tableId && $tableId > 0) {
                $tableCheckStmt = $conn->prepare("SELECT id FROM tables WHERE id = ? AND restaurant_id = ?");
                $tableCheckStmt->execute([$tableId, $restaurant_id]);
                if (!$tableCheckStmt->fetch()) {
                    throw new Exception('Invalid table selection');
                }
            }
            
            // Insert new reservation
            $insertStmt = $conn->prepare("INSERT INTO reservations (restaurant_id, table_id, reservation_date, time_slot, no_of_guests, meal_type, customer_name, phone, email, special_request, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $result = $insertStmt->execute([$restaurant_id, $tableId, $reservationDate, $timeSlot, $noOfGuests, $mealType, $customerName, $phone, $email, $specialRequest, $status]);
            
            if ($result) {
                $newReservationId = $conn->lastInsertId();
                
                // Auto-add customer if not exists
                if (!empty($phone)) {
                    // Check if customer already exists
                    $customerCheckStmt = $conn->prepare("SELECT id FROM customers WHERE restaurant_id = ? AND phone = ?");
                    $customerCheckStmt->execute([$restaurant_id, $phone]);
                    
                    if (!$customerCheckStmt->fetch()) {
                        // Customer doesn't exist, add them
                        $addCustomerStmt = $conn->prepare("INSERT INTO customers (restaurant_id, customer_name, phone, email, total_visits, last_visit_date) VALUES (?, ?, ?, ?, 1, ?)");
                        $addCustomerStmt->execute([$restaurant_id, $customerName, $phone, $email, $reservationDate]);
                    } else {
                        // Customer exists, update their visit count and last visit date
                        $updateCustomerStmt = $conn->prepare("UPDATE customers SET total_visits = total_visits + 1, last_visit_date = ? WHERE restaurant_id = ? AND phone = ?");
                        $updateCustomerStmt->execute([$reservationDate, $restaurant_id, $phone]);
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Reservation added successfully',
                    'data' => [
                        'id' => $newReservationId,
                        'customer_name' => $customerName,
                        'reservation_date' => $reservationDate,
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('Failed to add reservation');
            }
            break;
            
        case 'update':
            // Check if reservation exists and belongs to this restaurant
            $checkStmt = $conn->prepare("SELECT id FROM reservations WHERE id = ? AND restaurant_id = ?");
            $checkStmt->execute([$reservationId, $restaurant_id]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Reservation not found');
            }
            
            // Verify table belongs to this restaurant if specified
            if ($tableId && $tableId > 0) {
                $tableCheckStmt = $conn->prepare("SELECT id FROM tables WHERE id = ? AND restaurant_id = ?");
                $tableCheckStmt->execute([$tableId, $restaurant_id]);
                if (!$tableCheckStmt->fetch()) {
                    throw new Exception('Invalid table selection');
                }
            }
            
            // Update reservation
            $updateStmt = $conn->prepare("UPDATE reservations SET table_id = ?, reservation_date = ?, time_slot = ?, no_of_guests = ?, meal_type = ?, customer_name = ?, phone = ?, email = ?, special_request = ?, status = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
            $result = $updateStmt->execute([$tableId, $reservationDate, $timeSlot, $noOfGuests, $mealType, $customerName, $phone, $email, $specialRequest, $status, $reservationId, $restaurant_id]);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Reservation updated successfully',
                    'data' => [
                        'id' => $reservationId,
                        'customer_name' => $customerName,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('Failed to update reservation');
            }
            break;
            
        case 'delete':
            // Check if reservation exists and belongs to this restaurant
            $checkStmt = $conn->prepare("SELECT customer_name FROM reservations WHERE id = ? AND restaurant_id = ?");
            $checkStmt->execute([$reservationId, $restaurant_id]);
            $reservation = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$reservation) {
                throw new Exception('Reservation not found');
            }
            
            // Delete reservation
            $deleteStmt = $conn->prepare("DELETE FROM reservations WHERE id = ? AND restaurant_id = ?");
            $result = $deleteStmt->execute([$reservationId, $restaurant_id]);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Reservation deleted successfully',
                    'data' => [
                        'id' => $reservationId,
                        'customer_name' => $reservation['customer_name']
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('Failed to delete reservation');
            }
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (PDOException $e) {
    error_log("=== PDO Exception ===");
    error_log("Message: " . $e->getMessage());
    error_log("Code: " . $e->getCode());
    error_log("File: " . $e->getFile());
    error_log("Line: " . $e->getLine());
    error_log("Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    error_log("=== Exception ===");
    error_log("Message: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Error $e) {
    error_log("=== Fatal Error ===");
    error_log("Message: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
?>

