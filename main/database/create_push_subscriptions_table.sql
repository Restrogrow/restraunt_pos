-- Create push_subscriptions table for Web Push notifications
-- Stores push subscription data for each user (admin/staff)
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'User ID from users table',
  `staff_id` int(11) DEFAULT NULL COMMENT 'Staff ID from staff table (if staff)',
  `endpoint` text NOT NULL COMMENT 'Push service endpoint URL',
  `p256dh_key` text NOT NULL COMMENT 'Public key for encryption',
  `auth_key` text NOT NULL COMMENT 'Auth secret for encryption',
  `user_agent` varchar(500) DEFAULT NULL COMMENT 'Browser/user agent info',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_staff_id` (`staff_id`),
  KEY `idx_endpoint` (`endpoint`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
