<?php
/**
 * Subscription Payments Migration
 * Creates subscription_payments table and adds auto_pay support to users table
 * Run: http://yourdomain.com/main/database/run_subscription_payments_migration.php
 */

// Display errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Subscription Payments Migration</h2>";

try {
    // Include database connection
    require_once __DIR__ . '/../db_connection.php';
    
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        global $pdo;
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }
    
    echo "<p>✓ Database connected</p>";
    
    // Step 1: Create subscription_payments table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS subscription_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            restaurant_id VARCHAR(10) NOT NULL,
            transaction_id VARCHAR(100) NOT NULL UNIQUE,
            amount DECIMAL(10,2) NOT NULL DEFAULT 999.00,
            subscription_type ENUM('monthly','yearly','custom') DEFAULT 'monthly',
            duration_months INT DEFAULT 1,
            payment_status ENUM('pending','success','failed') DEFAULT 'pending',
            payment_method VARCHAR(50) DEFAULT 'phonepe',
            phonepe_transaction_id VARCHAR(100) DEFAULT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_restaurant_id (restaurant_id),
            INDEX idx_transaction (transaction_id),
            INDEX idx_status (payment_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<p>✓ subscription_payments table created/verified</p>";

    // Step 1b: Patch subscription_payments columns that may be missing on older installs
    $spColumns = [
        'duration_months' => "ALTER TABLE subscription_payments ADD COLUMN duration_months INT DEFAULT 1 AFTER subscription_type",
        'payment_method' => "ALTER TABLE subscription_payments ADD COLUMN payment_method VARCHAR(50) DEFAULT 'phonepe' AFTER payment_status",
        'notes' => "ALTER TABLE subscription_payments ADD COLUMN notes VARCHAR(255) DEFAULT NULL AFTER phonepe_transaction_id",
    ];
    $existingCols = $conn->query("SHOW COLUMNS FROM subscription_payments")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($spColumns as $col => $sql) {
        if (!in_array($col, $existingCols)) {
            $conn->exec($sql);
            echo "<p>✓ {$col} column added to subscription_payments table</p>";
        } else {
            echo "<p>— {$col} column already exists on subscription_payments</p>";
        }
    }

    // Step 1c: Make sure subscription_type enum includes 'custom'
    $typeCol = $conn->query("SHOW COLUMNS FROM subscription_payments LIKE 'subscription_type'")->fetch(PDO::FETCH_ASSOC);
    if ($typeCol && strpos($typeCol['Type'], "'custom'") === false) {
        $conn->exec("ALTER TABLE subscription_payments MODIFY subscription_type ENUM('monthly','yearly','custom') DEFAULT 'monthly'");
        echo "<p>✓ subscription_type enum updated to include 'custom'</p>";
    } else {
        echo "<p>— subscription_type enum already includes 'custom'</p>";
    }

    // Step 2: Add auto_pay column to users if not exists
    try {
        $conn->exec("ALTER TABLE users ADD COLUMN auto_pay TINYINT(1) DEFAULT 0");
        echo "<p>✓ auto_pay column added to users table</p>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "<p>— auto_pay column already exists</p>";
        } else {
            throw $e;
        }
    }
    
    // Step 3: Add payment_method column to users if not exists (for auto-pay)
    try {
        $conn->exec("ALTER TABLE users ADD COLUMN auto_pay_method VARCHAR(50) DEFAULT 'phonepe'");
        echo "<p>✓ auto_pay_method column added to users table</p>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "<p>— auto_pay_method column already exists</p>";
        } else {
            throw $e;
        }
    }
    
    // Step 4: Add subscription_updated_at column if not exists
    try {
        $conn->exec("ALTER TABLE users ADD COLUMN subscription_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        echo "<p>✓ subscription_updated_at column added to users table</p>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "<p>— subscription_updated_at column already exists</p>";
        } else {
            throw $e;
        }
    }
    
    // Step 5: Make sure renewal_date column exists
    try {
        $conn->exec("ALTER TABLE users ADD COLUMN renewal_date DATE DEFAULT NULL");
        echo "<p>✓ renewal_date column added to users table</p>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "<p>— renewal_date column already exists</p>";
        } else {
            throw $e;
        }
    }
    
    // Step 6: Check if there are existing users with expired trials and update them
    $stmt = $conn->prepare("UPDATE users SET subscription_status = 'expired' WHERE subscription_status = 'trial' AND trial_end_date IS NOT NULL AND trial_end_date < CURDATE() AND is_active = 1");
    $stmt->execute();
    $updated = $stmt->rowCount();
    if ($updated > 0) {
        echo "<p>✓ {$updated} expired trial(s) marked as 'expired'</p>";
    }
    
    echo "<h3 style='color:green;margin-top:20px;'>✓ Migration completed successfully!</h3>";
    echo "<p><a href='../views/dashboard.php' style='color:#e17055;font-weight:700;'>Go to Dashboard →</a></p>";
    
} catch (Exception $e) {
    echo "<h3 style='color:red;'>Migration failed:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
