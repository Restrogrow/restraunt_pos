-- Delivery Management System
-- Run this SQL to set up delivery zones, tracking, and order modifications

CREATE TABLE IF NOT EXISTS delivery_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    pincode VARCHAR(10) NOT NULL,
    zone_name VARCHAR(100) DEFAULT '',
    delivery_charge DECIMAL(10,2) DEFAULT 0.00,
    minimum_order DECIMAL(10,2) DEFAULT 0.00,
    estimated_time INT DEFAULT 30,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_pincode_restaurant (restaurant_id, pincode),
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    order_id INT NOT NULL,
    delivery_zone_id INT NULL,
    delivery_address TEXT NULL,
    delivery_notes TEXT NULL,
    delivery_status ENUM('Pending','Preparing','Assigned','Picked_Up','In_Transit','Delivered') DEFAULT 'Pending',
    qr_token VARCHAR(100) NULL UNIQUE,
    qr_expires_at DATETIME NULL,
    rider_name VARCHAR(100) NULL,
    rider_phone VARCHAR(20) NULL,
    rider_vehicle VARCHAR(50) NULL,
    current_lat DECIMAL(10,8) NULL,
    current_lng DECIMAL(11,8) NULL,
    location_updated_at DATETIME NULL,
    picked_up_at DATETIME NULL,
    in_transit_at DATETIME NULL,
    delivered_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (delivery_zone_id) REFERENCES delivery_zones(id) ON DELETE SET NULL,
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_order_id (order_id),
    INDEX idx_delivery_status (delivery_status),
    INDEX idx_qr_token (qr_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
    ADD COLUMN delivery_zone_id INT NULL AFTER order_type,
    ADD COLUMN delivery_charge DECIMAL(10,2) DEFAULT 0.00 AFTER delivery_zone_id,
    ADD COLUMN delivery_address TEXT NULL AFTER delivery_charge;
