<?php
// Payment proof screenshots live in their own table (not a BLOB column on
// orders) so that existing `SELECT o.*` queries elsewhere in the codebase
// can't accidentally pull binary image data into a json_encode() call.
try {
    require_once __DIR__ . '/../db_connection.php';
    $pdo = getConnection();

    $stmt = $pdo->query("SHOW TABLES LIKE 'payment_proofs'");
    if (!$stmt->fetch()) {
        $pdo->exec("CREATE TABLE payment_proofs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            restaurant_id VARCHAR(10) NOT NULL,
            proof_data LONGBLOB NOT NULL,
            proof_mime_type VARCHAR(50) NOT NULL,
            status ENUM('Pending','Confirmed','Rejected') NOT NULL DEFAULT 'Pending',
            reviewed_by VARCHAR(100) DEFAULT NULL,
            reviewed_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            UNIQUE KEY unique_order_proof (order_id),
            INDEX idx_restaurant_id (restaurant_id)
        )");
        echo "Table payment_proofs created successfully.\n";
    } else {
        echo "Table payment_proofs already exists.\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
