<?php
require_once __DIR__ . '/db_connection.php';
$conn = getConnection();

// Auto-create coupons table if needed
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

$codes = [
    ['SAVE10', 'percent', 10, 0, '10% off on all orders'],
    ['FLAT50', 'flat', 50, 299, 'Flat Rs.50 off on orders above Rs.299'],
    ['WELCOME20', 'percent', 20, 199, '20% off on orders above Rs.199'],
];

foreach ($codes as $c) {
    try {
        $s = $conn->prepare('INSERT IGNORE INTO coupons (restaurant_id, coupon_code, discount_type, discount_value, minimum_order_amount, description, valid_until) VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL 6 MONTH))');
        $s->execute(['RES005', $c[0], $c[1], $c[2], $c[3], $c[4]]);
        echo "Created: {$c[0]}\n";
    } catch (Exception $e) {
        echo "Skip {$c[0]}: {$e->getMessage()}\n";
    }
}
echo "Done!\n";
