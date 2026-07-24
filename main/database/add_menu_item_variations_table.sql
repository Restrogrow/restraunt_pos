-- Create menu_item_variations table for storing item variations (e.g., Small, Medium, Large)
CREATE TABLE IF NOT EXISTS menu_item_variations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    menu_item_id INT NOT NULL,
    variation_name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sort_order INT DEFAULT 0,
    is_available BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    INDEX idx_menu_item_id (menu_item_id),
    INDEX idx_sort_order (sort_order),
    INDEX idx_is_available (is_available),
    UNIQUE KEY unique_variation_per_item (menu_item_id, variation_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

