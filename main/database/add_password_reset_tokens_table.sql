-- Create password_reset_tokens table for storing password reset tokens
-- This migration adds the table if it doesn't exist and adds the idx_created_at index if missing

-- Create table if it doesn't exist
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add idx_created_at index if it doesn't exist (for existing tables)
SET @index_exists = (
    SELECT COUNT(*) 
    FROM information_schema.statistics 
    WHERE table_schema = DATABASE() 
    AND table_name = 'password_reset_tokens' 
    AND index_name = 'idx_created_at'
);

SET @sql = IF(@index_exists = 0, 
    'CREATE INDEX idx_created_at ON password_reset_tokens(created_at)',
    'SELECT "Index idx_created_at already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

