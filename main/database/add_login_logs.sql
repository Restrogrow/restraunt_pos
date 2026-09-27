-- Security logs + IP blocking.
--
-- login_logs: one row per login attempt (success / wrong password /
-- locked out / blocked IP / logout), with IP + device fingerprint so the
-- owner can audit who logged in when and from where. New devices are
-- flagged is_new_device = 1.
--
-- blocked_ips: IPs blocked from logging in (globally — a brute-forcing IP
-- is an attack on the platform, not one restaurant).
--
-- The app self-migrates via loginLogEnsureTables() in
-- main/config/login_log_helpers.php, so running this file is optional.

CREATE TABLE IF NOT EXISTS login_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) DEFAULT NULL,
    username_tried VARCHAR(100) DEFAULT NULL,
    user_type ENUM('admin','staff','branch_admin','unknown') DEFAULT 'unknown',
    actor_name VARCHAR(100) DEFAULT NULL,
    outcome ENUM('success','failed','locked_out','blocked_ip','logout') NOT NULL DEFAULT 'failed',
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    device_label VARCHAR(80) DEFAULT NULL,
    device_hash CHAR(32) DEFAULT NULL,
    is_new_device TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ll_restaurant (restaurant_id),
    INDEX idx_ll_created (created_at),
    INDEX idx_ll_outcome (outcome),
    INDEX idx_ll_rest_created (restaurant_id, created_at)
);

CREATE TABLE IF NOT EXISTS blocked_ips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    blocked_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_blocked_rest_ip (restaurant_id, ip_address),
    INDEX idx_bi_restaurant (restaurant_id)
);
