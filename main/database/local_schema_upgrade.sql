-- Local schema upgrade for XAMPP (MariaDB)
--
-- full_database_dump.sql predates many columns the current code reads and
-- writes, so a fresh local import fails with "Unknown column ..." errors
-- (dashboard stats, get_session.php, pending-order polling, etc.).
--
-- Usage, after importing full_database_dump.sql:
--   mysql -u root menuwebsite_db < main/database/local_schema_upgrade.sql
--
-- Safe to re-run: every statement is IF NOT EXISTS (MariaDB syntax, as
-- shipped with XAMPP). Types/defaults follow how the code uses each column.

-- ── orders ──
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS customer_email VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS customer_address TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS landmark VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS address_lat DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS address_lng DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS customer_ip VARCHAR(45) DEFAULT NULL;

-- ── kot / kot_items / order_items ──
ALTER TABLE kot
  ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS customer_email VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS customer_address TEXT DEFAULT NULL;

ALTER TABLE kot_items
  ADD COLUMN IF NOT EXISTS addons TEXT DEFAULT NULL;

ALTER TABLE order_items
  ADD COLUMN IF NOT EXISTS variation_name VARCHAR(100) DEFAULT NULL;

-- ── users (restaurant settings) ──
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS owner_name VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS description TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS description_format VARCHAR(20) DEFAULT 'paragraph',
  ADD COLUMN IF NOT EXISTS google_maps_link TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS instagram_link VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS facebook_link VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS twitter_link VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS youtube_link VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS linkedin_link VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS enable_delivery TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS enable_takeaway TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS enable_dinein TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS enable_gst TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS enable_language TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS language VARCHAR(10) DEFAULT 'en',
  ADD COLUMN IF NOT EXISTS packaging_charge DECIMAL(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS whatsapp_orders TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS payment_gateway_mode VARCHAR(20) DEFAULT 'own',
  ADD COLUMN IF NOT EXISTS payment_gateway_type VARCHAR(30) DEFAULT 'cash_only',
  ADD COLUMN IF NOT EXISTS phonepe_merchant_id VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS phonepe_salt_key VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS phonepe_environment VARCHAR(20) DEFAULT 'test',
  ADD COLUMN IF NOT EXISTS auto_login_token VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS auto_login_expires DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS custom_domain VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS embed_enabled TINYINT(1) NOT NULL DEFAULT 0;

-- ── menu / menu_items / subcategories ──
ALTER TABLE menu
  ADD COLUMN IF NOT EXISTS menu_image VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS menu_image_data LONGBLOB DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS menu_image_mime_type VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS translations LONGTEXT DEFAULT NULL;

ALTER TABLE menu_items
  ADD COLUMN IF NOT EXISTS calories INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS description_format VARCHAR(20) DEFAULT 'paragraph',
  ADD COLUMN IF NOT EXISTS translations LONGTEXT DEFAULT NULL;

ALTER TABLE subcategories
  ADD COLUMN IF NOT EXISTS translations LONGTEXT DEFAULT NULL;

-- ── website_settings (theme) ──
ALTER TABLE website_settings
  ADD COLUMN IF NOT EXISTS layout_columns TINYINT(1) DEFAULT 2,
  ADD COLUMN IF NOT EXISTS background_theme VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS logo_shape VARCHAR(20) DEFAULT 'circle',
  ADD COLUMN IF NOT EXISTS logo_size INT DEFAULT 90,
  ADD COLUMN IF NOT EXISTS font_family VARCHAR(50) DEFAULT 'Poppins';

-- ── deals (from api/deals_migration.php) ──
CREATE TABLE IF NOT EXISTS `deals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurant_id` varchar(10) NOT NULL,
  `deal_type` enum('combo','new') NOT NULL,
  `menu_id` int(11) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_restaurant_id` (`restaurant_id`),
  KEY `idx_deal_type` (`deal_type`),
  KEY `idx_menu_id` (`menu_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
