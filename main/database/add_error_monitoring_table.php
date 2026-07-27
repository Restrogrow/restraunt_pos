<?php
/**
 * Error Monitoring System — Database Migration
 * 
 * Creates the `error_logs` table for centralized error tracking
 * across PHP, JavaScript, API, and logical errors.
 * 
 * Run: php main/database/add_error_monitoring_table.php
 * Or load it in your browser.
 */

// Bootstrap
require_once __DIR__ . '/../db_connection.php';

$conn = getConnection();

// ─── Error Logs Table ──────────────────────────────────────────────────────

$sql = "
CREATE TABLE IF NOT EXISTS `error_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` VARCHAR(50) NULL DEFAULT NULL,
    `source` ENUM('php','js','api','auth','db','state_machine','custom') NOT NULL DEFAULT 'php',
    `severity` ENUM('critical','error','warning','info','debug') NOT NULL DEFAULT 'error',
    `message` TEXT NOT NULL,
    `file` VARCHAR(500) DEFAULT NULL,
    `line` INT UNSIGNED DEFAULT NULL,
    `trace` TEXT DEFAULT NULL COMMENT 'Stack trace as text',
    `context` JSON DEFAULT NULL COMMENT 'Additional JSON context data',
    `url` VARCHAR(500) DEFAULT NULL COMMENT 'Request URL where error occurred',
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `is_acknowledged` TINYINT(1) NOT NULL DEFAULT 0,
    `acknowledged_by` VARCHAR(100) DEFAULT NULL,
    `acknowledged_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_source` (`source`),
    INDEX `idx_severity` (`severity`),
    INDEX `idx_restaurant` (`restaurant_id`),
    INDEX `idx_is_read` (`is_read`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_source_severity` (`source`, `severity`),
    INDEX `idx_restaurant_created` (`restaurant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $conn->exec($sql);
    echo "✅ Table `error_logs` created successfully.\n";
} catch (PDOException $e) {
    echo "❌ Error creating `error_logs` table: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Migration complete.\n";
