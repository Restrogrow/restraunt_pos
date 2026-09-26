-- ─────────────────────────────────────────────────────────────────────────────
-- Order abuse protection migration
--   1. orders.customer_ip  — records the submitter's IP so repeat offenders
--                            (and floods) can be traced/blocked per IP.
--   2. order_blocklist     — phones / IPs blocked from placing orders.
--                            Populated by the auto-block system (fake-order
--                            strikes) and manageable by the restaurant admin
--                            (main/admin/blocklist.php).
-- Safe to re-run (idempotent).
-- ─────────────────────────────────────────────────────────────────────────────

-- 1) orders.customer_ip (VARBINARY(16) stores IPv4 and IPv6 compactly)
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'orders'
      AND COLUMN_NAME  = 'customer_ip'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE orders ADD COLUMN customer_ip VARBINARY(16) NULL AFTER customer_phone',
    'SELECT 1');
PREPARE _stmt FROM @ddl; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- 2) Blocklist table
CREATE TABLE IF NOT EXISTS order_blocklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    type ENUM('phone', 'ip') NOT NULL,
    value VARCHAR(64) NOT NULL,                -- normalized phone (10 digits) or IP string
    reason VARCHAR(255) NULL,
    source ENUM('auto', 'manual') NOT NULL DEFAULT 'manual',
    strikes INT NOT NULL DEFAULT 0,            -- fake-order strike count (resets after 30 idle days)
    last_strike_at DATETIME NULL,              -- when the last strike was recorded
    blocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,                  -- NULL = permanent
    blocked_by VARCHAR(50) NULL,               -- admin username when source = manual
    UNIQUE KEY uq_block (restaurant_id, type, value),
    INDEX idx_block_lookup (restaurant_id, type, value, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
