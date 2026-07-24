-- Restaurant POS Menu Management Database Schema
-- Clean version - Tables and structure only (no sample data)
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
    kot_status ENUM('Pending', 'Preparing', 'Ready', 'Completed') DEFAULT 'Pending',
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

-- Create indexes for better performance
CREATE INDEX idx_menu_created_at ON menu(created_at);
CREATE INDEX idx_menu_items_created_at ON menu_items(created_at);

