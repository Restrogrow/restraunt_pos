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
  ADD COLUMN IF NOT EXISTS phone_2 VARCHAR(20) DEFAULT NULL,
  -- these fifteen are selected by admin/get_session.php and written by the Settings save. Without
  -- them both fall back to a shorter column list, so Settings quietly saves only the name and the
  -- dashboard loads with the wrong tax rate and no country. Types follow the one-off migrations in
  -- main/admin/ that added each one to the live database.
  ADD COLUMN IF NOT EXISTS country VARCHAR(100) DEFAULT 'India',
  ADD COLUMN IF NOT EXISTS tax_name VARCHAR(50) DEFAULT 'GST',
  ADD COLUMN IF NOT EXISTS tax_percent DECIMAL(5,2) DEFAULT 5.00,
  ADD COLUMN IF NOT EXISTS minimum_order_value DECIMAL(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS cod_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS enable_reservations TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS delivery_radius_km DECIMAL(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS restaurant_lat DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS restaurant_lng DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS enable_km_delivery TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS delivery_rate_per_km DECIMAL(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS show_install_app TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS show_business_id TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS business_id_label VARCHAR(30) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS business_id_no VARCHAR(20) DEFAULT NULL,
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

-- ── bestsellers (Offers > Bestsellers; also auto-created by config/bestseller_helpers.php) ──
CREATE TABLE IF NOT EXISTS bestsellers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  restaurant_id VARCHAR(10) NOT NULL,
  menu_item_id INT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  offer_price DECIMAL(10,2) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bestseller (restaurant_id, menu_item_id),
  KEY idx_restaurant_sort (restaurant_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Bar Order Tickets (BOT): kitchen or bar per category / dish, and per ticket (also auto-added by config/prep_stations.php) ──
ALTER TABLE menu ADD COLUMN IF NOT EXISTS prep_station VARCHAR(10) NOT NULL DEFAULT 'kitchen';
ALTER TABLE menu_items ADD COLUMN IF NOT EXISTS prep_station VARCHAR(10) NULL DEFAULT NULL;
ALTER TABLE kot ADD COLUMN IF NOT EXISTS station VARCHAR(10) NOT NULL DEFAULT 'kitchen';

-- ── subcategories (Menu > categories can hold sub-groups; also auto-created by admin/add_subcategories.php) ──
CREATE TABLE IF NOT EXISTS subcategories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  menu_id INT NOT NULL,
  subcategory_name VARCHAR(100) NOT NULL,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_menu_id (menu_id),
  INDEX idx_sort_order (sort_order),
  UNIQUE KEY unique_subcategory_per_menu (menu_id, subcategory_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── menu_items: the rest of what api/get_menu_items.php selects ──
-- Without these the POS dish list answers 500 and sits on its loading skeleton for ever,
-- which reads as a broken grid rather than a missing column.
ALTER TABLE menu_items
  ADD COLUMN IF NOT EXISTS subcategory_id INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS ingredients TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS health_tags VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS protein_g DECIMAL(6,1) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS fat_g DECIMAL(6,1) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS carbs_g DECIMAL(6,1) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS fibre_g DECIMAL(6,1) DEFAULT NULL;

-- ── orders.source (website vs POS; read by the pending-order polling) ──
ALTER TABLE orders ADD COLUMN IF NOT EXISTS source VARCHAR(20) DEFAULT 'pos';

-- ── users: opening hours and subscription auto-pay ──
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS opening_hours TEXT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS auto_pay TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS auto_pay_method VARCHAR(50) DEFAULT 'phonepe';

-- ── website_offer_banners (also auto-created by config/website_theme_helpers.php) ──
CREATE TABLE IF NOT EXISTS website_offer_banners (
  id INT AUTO_INCREMENT PRIMARY KEY,
  restaurant_id VARCHAR(50) NOT NULL,
  template VARCHAR(20) NOT NULL DEFAULT 'burst',
  title VARCHAR(40) NOT NULL,
  subtitle VARCHAR(90) NULL,
  code VARCHAR(30) NULL,
  cta VARCHAR(24) NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#e11d48',
  menu_item_id INT NULL,
  placement VARCHAR(20) NOT NULL DEFAULT 'home_top',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  display_order INT NOT NULL DEFAULT 0,
  opts TEXT NULL,
  ends_ts INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_offer_banners_rid (restaurant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
