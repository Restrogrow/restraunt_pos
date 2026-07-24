-- Restaurant POS Menu Management Database Schema
-- Create database
CREATE DATABASE IF NOT EXISTS restro2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Use the database
USE restro2;

-- Create menu table
CREATE TABLE IF NOT EXISTS menu (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    menu_name VARCHAR(100) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_menu_name (menu_name),
    INDEX idx_is_active (is_active),
    INDEX idx_sort_order (sort_order),
    UNIQUE KEY unique_menu_per_restaurant (restaurant_id, menu_name),
    -- Critical performance indexes
    INDEX idx_restaurant_active (restaurant_id, is_active)
);

-- Create menu_items table
CREATE TABLE IF NOT EXISTS menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    menu_id INT NOT NULL,
    item_name_en VARCHAR(200) NOT NULL,
    item_description_en TEXT,
    item_category VARCHAR(100),
    item_type ENUM('Veg', 'Non Veg', 'Egg', 'Drink', 'Dessert', 'Other') DEFAULT 'Veg',
    preparation_time INT DEFAULT 0,
    calories INT DEFAULT 0,
    is_available BOOLEAN DEFAULT TRUE,
    base_price DECIMAL(10,2) DEFAULT 0.00,
    has_variations BOOLEAN DEFAULT FALSE,
    item_image VARCHAR(500),
    image_data LONGBLOB NULL,
    image_mime_type VARCHAR(50) NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (menu_id) REFERENCES menu(id) ON DELETE CASCADE,
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_menu_id (menu_id),
    INDEX idx_item_category (item_category),
    INDEX idx_item_type (item_type),
    INDEX idx_is_available (is_available),
    INDEX idx_sort_order (sort_order),
    -- Critical performance indexes
    INDEX idx_restaurant_menu (restaurant_id, menu_id),
    INDEX idx_available_category (is_available, item_category),
    INDEX idx_restaurant_available (restaurant_id, is_available)
);

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

-- Create users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    restaurant_id VARCHAR(10) NOT NULL UNIQUE,
    restaurant_name VARCHAR(100) NOT NULL,
    restaurant_logo VARCHAR(255) NULL,
    logo_data LONGBLOB NULL,
    logo_mime_type VARCHAR(50) NULL,
    email VARCHAR(100) NULL,
    phone VARCHAR(20) NULL,
    address TEXT NULL,
    currency_symbol VARCHAR(10) DEFAULT '₹',
    timezone VARCHAR(50) DEFAULT 'Asia/Kolkata',
    business_qr_code_path VARCHAR(500) DEFAULT NULL,
    business_qr_code_data LONGBLOB NULL,
    business_qr_code_mime_type VARCHAR(50) NULL,
    role VARCHAR(50) DEFAULT 'Administrator',
    is_active BOOLEAN DEFAULT TRUE,
    subscription_status ENUM('trial','active','expired','disabled') DEFAULT 'trial',
    trial_end_date DATE NULL,
    renewal_date DATE NULL,
    disabled_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_username (username),
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_is_active (is_active),
    INDEX idx_subscription_status (subscription_status),
    INDEX idx_email (email),
    INDEX idx_phone (phone)
);

-- Create areas table
CREATE TABLE IF NOT EXISTS areas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    area_name VARCHAR(100) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_area_name (area_name),
    INDEX idx_sort_order (sort_order),
    UNIQUE KEY unique_area_per_restaurant (restaurant_id, area_name)
);

-- Create tables table
CREATE TABLE IF NOT EXISTS tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    area_id INT NOT NULL,
    table_number VARCHAR(50) NOT NULL,
    capacity INT DEFAULT 4,
    is_available BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE CASCADE,
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_area_id (area_id),
    INDEX idx_table_number (table_number),
    INDEX idx_is_available (is_available),
    INDEX idx_sort_order (sort_order),
    UNIQUE KEY unique_table_per_area (area_id, table_number)
);

-- Create reservations table
CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    table_id INT,
    reservation_date DATE NOT NULL,
    time_slot VARCHAR(20) NOT NULL,
    no_of_guests INT NOT NULL DEFAULT 1,
    meal_type VARCHAR(20) DEFAULT 'Lunch',
    customer_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    special_request TEXT,
    status ENUM('Pending', 'Confirmed', 'Checked In', 'Completed', 'Cancelled', 'No Show') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE SET NULL,
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_table_id (table_id),
    INDEX idx_reservation_date (reservation_date),
    INDEX idx_status (status),
    INDEX idx_customer_name (customer_name),
    INDEX idx_phone (phone)
);

-- Create customers table
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    total_visits INT DEFAULT 0,
    last_visit_date DATE,
    total_spent DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_customer_name (customer_name),
    INDEX idx_phone (phone),
    INDEX idx_email (email),
    UNIQUE KEY unique_customer_per_restaurant (restaurant_id, phone)
);

-- Create waiter_requests table
CREATE TABLE IF NOT EXISTS waiter_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    table_id INT NOT NULL,
    request_type VARCHAR(50) DEFAULT 'General',
    status ENUM('Pending', 'Attended', 'Completed') DEFAULT 'Pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    attended_at TIMESTAMP NULL,
    
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE,
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_table_id (table_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

-- Create staff table
CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    member_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('Admin', 'Manager', 'Waiter', 'Chef') DEFAULT 'Waiter',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_email (email),
    INDEX idx_phone (phone),
    INDEX idx_role (role),
    INDEX idx_is_active (is_active),
    UNIQUE KEY unique_email_per_restaurant (restaurant_id, email)
);

-- Create orders table
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    table_id INT,
    order_number VARCHAR(50) NOT NULL,
    customer_name VARCHAR(100),
    order_type ENUM('Dine-in', 'Takeaway', 'Delivery') DEFAULT 'Dine-in',
    payment_method VARCHAR(100) DEFAULT 'Cash',
    payment_status ENUM('Pending', 'Paid', 'Partially Paid', 'Refunded') DEFAULT 'Pending',
    order_status ENUM('Pending', 'Preparing', 'Ready', 'Served', 'Completed', 'Cancelled') DEFAULT 'Pending',
    subtotal DECIMAL(10,2) DEFAULT 0.00,
    tax DECIMAL(10,2) DEFAULT 0.00,
    total DECIMAL(10,2) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE SET NULL,
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_table_id (table_id),
    INDEX idx_order_number (order_number),
    INDEX idx_order_status (order_status),
    INDEX idx_payment_status (payment_status),
    INDEX idx_created_at (created_at),
    -- Critical performance indexes
    INDEX idx_restaurant_date (restaurant_id, created_at),
    INDEX idx_status_date (order_status, created_at)
);

-- Create kot table for kitchen orders
CREATE TABLE IF NOT EXISTS kot (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    kot_number VARCHAR(50) NOT NULL,
    table_id INT,
    order_type ENUM('Dine-in', 'Takeaway', 'Delivery') DEFAULT 'Dine-in',
    customer_name VARCHAR(100),
    kot_status ENUM('Pending', 'Preparing', 'Ready', 'Completed', 'Cancelled') DEFAULT 'Pending',
    subtotal DECIMAL(10,2) DEFAULT 0.00,
    tax DECIMAL(10,2) DEFAULT 0.00,
    total DECIMAL(10,2) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE SET NULL,
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_table_id (table_id),
    INDEX idx_kot_number (kot_number),
    INDEX idx_kot_status (kot_status),
    INDEX idx_created_at (created_at)
);

-- Create kot_items table for KOT order items
CREATE TABLE IF NOT EXISTS kot_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kot_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    item_name VARCHAR(200) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (kot_id) REFERENCES kot(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    INDEX idx_kot_id (kot_id),
    INDEX idx_menu_item_id (menu_item_id)
);

-- Create order_items table
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    item_name VARCHAR(200) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    INDEX idx_order_id (order_id),
    INDEX idx_menu_item_id (menu_item_id)
);

-- Create payments table
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    order_id INT NOT NULL,
    transaction_id VARCHAR(100),
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(100) DEFAULT 'Cash',
    payment_status ENUM('Success', 'Failed', 'Pending', 'Refunded') DEFAULT 'Success',
    reference_number VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_order_id (order_id),
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_payment_method (payment_method),
    INDEX idx_payment_status (payment_status),
    INDEX idx_created_at (created_at),
    -- Critical performance indexes
    INDEX idx_restaurant_date (restaurant_id, created_at)
);

-- Create super_admins table
CREATE TABLE IF NOT EXISTS super_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(150) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_superadmin_username (username),
    INDEX idx_superadmin_active (is_active)
);

-- Create website_settings table
CREATE TABLE IF NOT EXISTS website_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL UNIQUE,
    primary_red VARCHAR(20) DEFAULT '#F70000',
    dark_red VARCHAR(20) DEFAULT '#DA020E',
    primary_yellow VARCHAR(20) DEFAULT '#FFD100',
    banner_image VARCHAR(255) NULL,
    banner_data LONGBLOB NULL,
    banner_mime_type VARCHAR(50) NULL,
    layout_columns TINYINT(1) DEFAULT 2,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ws_restaurant_id (restaurant_id)
);

-- Create website_banners table for multiple banners per restaurant
CREATE TABLE IF NOT EXISTS website_banners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(50) NOT NULL,
    banner_path VARCHAR(255) NOT NULL,
    banner_data LONGBLOB NULL,
    banner_mime_type VARCHAR(50) NULL,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_restaurant (restaurant_id),
    INDEX idx_order (restaurant_id, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create password_reset_tokens table
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create subscription_payments table
CREATE TABLE IF NOT EXISTS subscription_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    restaurant_id VARCHAR(10) NOT NULL,
    transaction_id VARCHAR(100) NOT NULL UNIQUE,
    phonepe_transaction_id VARCHAR(100),
    amount DECIMAL(10,2) NOT NULL,
    subscription_type ENUM('monthly', 'yearly') DEFAULT 'monthly',
    payment_status ENUM('pending', 'success', 'failed', 'cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_user_id (user_id),
    INDEX idx_payment_status (payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample admin user
INSERT INTO users (username, password, restaurant_id, restaurant_name, subscription_status, trial_end_date) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'RES001', 'Demo Restaurant', 'trial', DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY));

-- Insert sample superadmin (password: admin123)
INSERT INTO super_admins (username, email, password_hash, is_active) VALUES 
('superadmin', 'superadmin@possystem.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', TRUE);

-- Insert sample menus for RES001
INSERT INTO menu (restaurant_id, menu_name, sort_order) VALUES 
('RES001', 'Breakfast', 1),
('RES001', 'Lunch', 2),
('RES001', 'Dinner', 3),
('RES001', 'Snacks', 4),
('RES001', 'Beverages', 5);

-- Insert real menu items for RES001
INSERT INTO menu_items (restaurant_id, menu_id, item_name_en, item_description_en, item_category, item_type, preparation_time, calories, is_available, base_price, has_variations) VALUES 
-- Breakfast Menu
('RES001', 1, 'Egg Fried Rice', 'Fried rice with scrambled eggs, vegetables and spices', 'Main Course', 'Egg', 12, 320, TRUE, 180.00, FALSE),
('RES001', 1, 'Masala Omelette', 'Two egg omelette with onions, tomatoes and spices', 'Main Course', 'Egg', 8, 220, TRUE, 150.00, FALSE),
('RES001', 1, 'Poha', 'Flattened rice cooked with onions, curry leaves and peanuts', 'Main Course', 'Veg', 10, 180, TRUE, 80.00, FALSE),
('RES001', 1, 'Paratha with Butter', 'Crispy layered flatbread served with butter', 'Main Course', 'Veg', 8, 350, TRUE, 100.00, TRUE),
('RES001', 1, 'Bread Toast with Jam', 'Toasted bread slices with butter and jam', 'Main Course', 'Veg', 5, 180, TRUE, 60.00, FALSE),
('RES001', 1, 'Egg Bhurji', 'Spiced scrambled eggs with onions and tomatoes', 'Main Course', 'Egg', 8, 240, TRUE, 140.00, FALSE),
('RES001', 1, 'Aloo Paratha', 'Stuffed wheat flatbread with spiced potatoes', 'Main Course', 'Veg', 10, 280, TRUE, 120.00, FALSE),
('RES001', 1, 'Suji Upma', 'Semolina cooked with vegetables and spices', 'Main Course', 'Veg', 10, 200, TRUE, 90.00, FALSE),

-- Lunch Menu
('RES001', 2, 'Butter Chicken', 'Creamy tomato based curry with tender chicken pieces', 'Main Course', 'Non Veg', 20, 450, TRUE, 320.00, FALSE),
('RES001', 2, 'Dal Makhani', 'Slow cooked black lentils with cream and butter', 'Main Course', 'Veg', 15, 300, TRUE, 180.00, FALSE),
('RES001', 2, 'Chicken Biryani', 'Fragrant basmati rice with marinated chicken and spices', 'Main Course', 'Non Veg', 25, 550, TRUE, 280.00, FALSE),
('RES001', 2, 'Veg Biryani', 'Aromatic basmati rice with mixed vegetables and spices', 'Main Course', 'Veg', 20, 400, TRUE, 200.00, FALSE),
('RES001', 2, 'Paneer Tikka', 'Grilled cottage cheese cubes marinated in spices', 'Appetizer', 'Veg', 15, 280, TRUE, 220.00, FALSE),
('RES001', 2, 'Chicken Tikka', 'Grilled chicken pieces marinated in yogurt and spices', 'Appetizer', 'Non Veg', 18, 300, TRUE, 280.00, FALSE),
('RES001', 2, 'Naan', 'Soft leavened flatbread baked in tandoor', 'Bread', 'Veg', 8, 180, TRUE, 50.00, TRUE),
('RES001', 2, 'Roti', 'Freshly made whole wheat flatbread', 'Bread', 'Veg', 5, 120, TRUE, 30.00, FALSE),
('RES001', 2, 'Jeera Rice', 'Basmati rice tempered with cumin seeds', 'Rice', 'Veg', 10, 200, TRUE, 100.00, FALSE),
('RES001', 2, 'Gobi Manchurian', 'Crispy cauliflower in sweet and spicy sauce', 'Appetizer', 'Veg', 15, 250, TRUE, 200.00, FALSE),
('RES001', 2, 'Chicken Curry', 'Traditional chicken curry with onions and tomatoes', 'Main Course', 'Non Veg', 20, 380, TRUE, 260.00, FALSE),
('RES001', 2, 'Aloo Gobi', 'Potatoes and cauliflower cooked with Indian spices', 'Main Course', 'Veg', 15, 180, TRUE, 150.00, FALSE),
('RES001', 2, 'Rajma Chawal', 'Kidney beans curry served with steamed rice', 'Main Course', 'Veg', 20, 350, TRUE, 170.00, FALSE),
('RES001', 2, 'Chole Bhature', 'Spiced chickpeas with deep fried bread', 'Main Course', 'Veg', 15, 450, TRUE, 180.00, FALSE),

-- Dinner Menu
('RES001', 3, 'Grilled Chicken Breast', 'Marinated chicken breast grilled to perfection', 'Main Course', 'Non Veg', 18, 350, TRUE, 350.00, FALSE),
('RES001', 3, 'Mixed Grill Platter', 'Assorted grilled meats and vegetables', 'Main Course', 'Non Veg', 25, 600, TRUE, 450.00, FALSE),
('RES001', 3, 'Mutton Rogan Josh', 'Tender mutton cooked in rich Kashmiri curry', 'Main Course', 'Non Veg', 30, 500, TRUE, 380.00, FALSE),
('RES001', 3, 'Palak Paneer', 'Cottage cheese cubes in creamy spinach gravy', 'Main Course', 'Veg', 15, 320, TRUE, 220.00, FALSE),
('RES001', 3, 'Dal Tadka', 'Yellow lentils tempered with spices and herbs', 'Main Course', 'Veg', 12, 200, TRUE, 140.00, FALSE),
('RES001', 3, 'Kadai Paneer', 'Cottage cheese cooked in spicy kadai masala', 'Main Course', 'Veg', 15, 340, TRUE, 240.00, FALSE),
('RES001', 3, 'Chicken Laziz', 'Creamy chicken cooked in rich cashew gravy', 'Main Course', 'Non Veg', 20, 420, TRUE, 320.00, FALSE),
('RES001', 3, 'Veg Kofta Curry', 'Vegetable dumplings in creamy tomato gravy', 'Main Course', 'Veg', 18, 280, TRUE, 200.00, FALSE),
('RES001', 3, 'Mutton Curry', 'Mutton cooked in traditional Indian spices', 'Main Course', 'Non Veg', 30, 480, TRUE, 360.00, FALSE),
('RES001', 3, 'Baingan Bharta', 'Smoky roasted eggplant mashed with spices', 'Main Course', 'Veg', 15, 180, TRUE, 160.00, FALSE),

-- Snacks Menu
('RES001', 4, 'French Fries', 'Crispy golden french fries served hot', 'Snacks', 'Veg', 8, 320, TRUE, 120.00, TRUE),
('RES001', 4, 'Onion Rings', 'Crispy fried onion rings with dipping sauce', 'Snacks', 'Veg', 10, 280, TRUE, 150.00, FALSE),
('RES001', 4, 'Chicken Wings', 'Spicy fried chicken wings with hot sauce', 'Snacks', 'Non Veg', 15, 380, TRUE, 250.00, TRUE),
('RES001', 4, 'Spring Rolls', 'Crispy vegetable spring rolls with dip', 'Snacks', 'Veg', 12, 200, TRUE, 140.00, FALSE),
('RES001', 4, 'Mozzarella Sticks', 'Crispy mozzarella cheese sticks with marinara', 'Snacks', 'Veg', 10, 300, TRUE, 180.00, FALSE),
('RES001', 4, 'Paneer Pakora', 'Deep fried cottage cheese fritters', 'Snacks', 'Veg', 12, 250, TRUE, 160.00, FALSE),
('RES001', 4, 'Chicken Nuggets', 'Crispy breaded chicken nuggets', 'Snacks', 'Non Veg', 12, 350, TRUE, 220.00, TRUE),
('RES001', 4, 'Aloo Tikki', 'Spiced potato patties served with chutneys', 'Snacks', 'Veg', 10, 180, TRUE, 120.00, FALSE),
('RES001', 4, 'Samosa', 'Crispy pastry filled with spiced potatoes', 'Snacks', 'Veg', 8, 150, TRUE, 40.00, FALSE),
('RES001', 4, 'Vada Pav', 'Spiced potato fritter in soft bread bun', 'Snacks', 'Veg', 8, 220, TRUE, 50.00, FALSE),

-- Beverages Menu
('RES001', 5, 'Fresh Orange Juice', 'Freshly squeezed orange juice', 'Beverages', 'Drink', 3, 110, TRUE, 100.00, FALSE),
('RES001', 5, 'Mango Lassi', 'Sweet mango yogurt drink', 'Beverages', 'Drink', 5, 180, TRUE, 120.00, FALSE),
('RES001', 5, 'Masala Chai', 'Spiced tea with milk and herbs', 'Beverages', 'Drink', 5, 60, TRUE, 40.00, FALSE),
('RES001', 5, 'Coffee', 'Hot brewed coffee', 'Beverages', 'Drink', 5, 5, TRUE, 60.00, TRUE),
('RES001', 5, 'Cold Coffee', 'Chilled coffee with ice cream', 'Beverages', 'Drink', 5, 250, TRUE, 100.00, FALSE),
('RES001', 5, 'Lemonade', 'Fresh lemon juice with sugar and soda', 'Beverages', 'Drink', 3, 120, TRUE, 80.00, FALSE),
('RES001', 5, 'Buttermilk', 'Spiced yogurt drink', 'Beverages', 'Drink', 3, 50, TRUE, 50.00, FALSE),
('RES001', 5, 'Ice Tea', 'Chilled tea with lemon', 'Beverages', 'Drink', 3, 90, TRUE, 70.00, FALSE),
('RES001', 5, 'Badam Milk', 'Sweet almond milk', 'Beverages', 'Drink', 5, 180, TRUE, 120.00, FALSE),
('RES001', 5, 'Water Bottle', 'Packaged drinking water', 'Beverages', 'Drink', 1, 0, TRUE, 30.00, FALSE),
('RES001', 5, 'Soft Drinks', 'Coca Cola, Pepsi, Sprite (250ml)', 'Beverages', 'Drink', 2, 140, TRUE, 50.00, TRUE),
('RES001', 5, 'Fresh Lime Soda', 'Lime juice with soda water', 'Beverages', 'Drink', 3, 80, TRUE, 60.00, FALSE);

-- Insert sample areas for RES001
INSERT INTO areas (restaurant_id, area_name, sort_order) VALUES 
('RES001', 'Indoor Dining', 1),
('RES001', 'Outdoor Terrace', 2),
('RES001', 'Smoking Area', 3);

-- Insert sample tables for RES001
INSERT INTO tables (restaurant_id, area_id, table_number, capacity) VALUES 
('RES001', 1, 'ID-01', 4),
('RES001', 1, 'ID-02', 4),
('RES001', 1, 'ID-03', 6),
('RES001', 2, 'OT-01', 4),
('RES001', 2, 'OT-02', 2),
('RES001', 3, 'SA-01', 4);

-- Insert sample reservations for RES001
INSERT INTO reservations (restaurant_id, table_id, reservation_date, time_slot, no_of_guests, meal_type, customer_name, phone, email, special_request, status) VALUES 
('RES001', 1, '2025-10-26', '12:00 PM', 1, 'Lunch', 'Zainab', '9257864022', 'zainab22@gmail.com', 'birthday', 'Checked In'),
('RES001', 2, '2025-10-26', '12:00 PM', 1, 'Lunch', 'Zainab', '9257864022', 'zainab22@gmail.com', 'birthday', 'Confirmed');

-- Insert sample customers for RES001
INSERT INTO customers (restaurant_id, customer_name, phone, email, total_visits, last_visit_date, total_spent) VALUES 
('RES001', 'Zainab', '9257864022', 'zainab22@gmail.com', 2, '2025-10-26', 750.00);

-- Insert sample waiter requests for RES001
INSERT INTO waiter_requests (restaurant_id, table_id, request_type, status, notes) VALUES 
('RES001', 1, 'General', 'Pending', 'Customer needs assistance'),
('RES001', 2, 'General', 'Pending', 'Bill request');

-- Insert sample staff for RES001
INSERT INTO staff (restaurant_id, member_name, email, phone, password, role) VALUES 
('RES001', 'John Manager', 'john.manager@restaurant.com', '+1-1234567890', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Manager'),
('RES001', 'Sarah Waiter', 'sarah.waiter@restaurant.com', '+1-9876543210', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Waiter');

-- Create indexes for better performance
CREATE INDEX idx_menu_created_at ON menu(created_at);
CREATE INDEX idx_menu_items_created_at ON menu_items(created_at);

-- Show the created tables
SHOW TABLES;

-- Display sample data
SELECT 'Users Table:' as info;
SELECT * FROM users ORDER BY created_at;

SELECT 'Menu Table:' as info;
SELECT * FROM menu ORDER BY restaurant_id, sort_order;

SELECT 'Menu Items Table:' as info;
SELECT mi.*, m.menu_name FROM menu_items mi JOIN menu m ON mi.menu_id = m.id ORDER BY mi.restaurant_id, mi.sort_order;

SELECT 'Areas Table:' as info;
SELECT * FROM areas ORDER BY restaurant_id, sort_order;

SELECT 'Tables Table:' as info;
SELECT t.*, a.area_name FROM tables t JOIN areas a ON t.area_id = a.id ORDER BY t.restaurant_id, t.sort_order;

SELECT 'Reservations Table:' as info;
SELECT r.*, t.table_number, a.area_name FROM reservations r LEFT JOIN tables t ON r.table_id = t.id LEFT JOIN areas a ON t.area_id = a.id ORDER BY r.reservation_date DESC, r.time_slot;

SELECT 'Staff Table:' as info;
SELECT id, restaurant_id, member_name, email, phone, role, is_active, created_at FROM staff ORDER BY restaurant_id, role, member_name;

SELECT 'Orders Table:' as info;
SELECT o.*, t.table_number, a.area_name FROM orders o LEFT JOIN tables t ON o.table_id = t.id LEFT JOIN areas a ON t.area_id = a.id ORDER BY o.created_at DESC;