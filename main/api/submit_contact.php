<?php
// Contact Form Submission API
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any errors
ob_start();

// Set error handler to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error occurred. Please try again later.',
            'error' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
        exit();
    }
});

// Ensure no output before headers
if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Include database connection
$dbPath = __DIR__ . '/../db_connection.php';
if (file_exists($dbPath)) {
    try {
        require_once $dbPath;
    } catch (Exception $e) {
        error_log("Error including db_connection.php: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Database configuration error'
        ]);
        exit();
    }
} else {
    error_log("db_connection.php not found at: $dbPath");
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database connection file not found',
        'path' => $dbPath
    ]);
    exit();
}

try {
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
    
    // Get form data
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    // Validate required fields
    if (empty($name) || empty($email) || empty($message)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Please fill in all required fields (Name, Email, Message).'
        ]);
        exit();
    }
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Please enter a valid email address.'
        ]);
        exit();
    }
    
    // Check if contact_queries table exists, create if not
    $checkTable = $conn->query("SHOW TABLES LIKE 'contact_queries'");
    if ($checkTable->rowCount() === 0) {
        // Create table
        $createTable = $conn->exec("
            CREATE TABLE IF NOT EXISTS contact_queries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                phone VARCHAR(50),
                message TEXT NOT NULL,
                status ENUM('new', 'read', 'replied', 'closed') DEFAULT 'new',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // Insert query into database
    $stmt = $conn->prepare("
        INSERT INTO contact_queries (name, email, phone, message, status) 
        VALUES (?, ?, ?, ?, 'new')
    ");
    
    $result = $stmt->execute([$name, $email, $phone, $message]);
    
    // Verify the insert was successful
    $insertId = $conn->lastInsertId();
    $rowCount = $stmt->rowCount();
    
    error_log("Contact form insert attempt - Result: " . ($result ? 'true' : 'false') . ", RowCount: $rowCount, InsertId: $insertId");
    
    if ($result && $insertId) {
        error_log("Contact form submitted successfully - ID: $insertId, Name: $name, Email: $email");
        echo json_encode([
            'success' => true,
            'message' => 'Thank you for your interest! We will contact you soon.',
            'id' => $insertId,
            'debug' => [
                'insertId' => $insertId,
                'rowCount' => $rowCount
            ]
        ]);
    } else {
        error_log("Contact form insert failed - Result: " . ($result ? 'true' : 'false') . ", RowCount: $rowCount, InsertId: $insertId");
        throw new Exception('Failed to insert contact query into database. InsertId: ' . $insertId . ', RowCount: ' . $rowCount);
    }
    
} catch (PDOException $e) {
    error_log("PDO Error in submit_contact.php: " . $e->getMessage());
    error_log("PDO Error Code: " . $e->getCode());
    error_log("PDO Error Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ]);
    exit();
} catch (Exception $e) {
    error_log("Error in submit_contact.php: " . $e->getMessage());
    error_log("Error Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again.'
    ]);
    exit();
}
?>

