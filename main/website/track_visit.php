<?php
// Lightweight page visit tracking endpoint
// Called via AJAX from header.php tracking script (fire-and-forget)
// No session needed - just logs the visit

$restaurant_id = $_GET['rid'] ?? '';
$page_url = $_GET['page'] ?? '/';
$page_name = $_GET['name'] ?? 'Home';
$visitor_ip = $_GET['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$referrer_url = $_GET['ref'] ?? '';
$user_agent = $_GET['ua'] ?? $_SERVER['HTTP_USER_AGENT'] ?? '';

if (empty($restaurant_id)) {
    http_response_code(204);
    exit;
}

try {
    // Minimal bootstrap - just need DB connection
    require_once __DIR__ . '/db_config.php';
    $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
    
    if ($conn) {
        try {
            // Try INSERT first (99.9% of requests - fast path)
            $stmt = $conn->prepare("INSERT INTO page_visits (restaurant_id, page_url, page_name, visitor_ip, user_agent, referrer_url) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$restaurant_id, $page_url, $page_name, $visitor_ip, $user_agent, $referrer_url]);
        } catch (Exception $insertErr) {
            // If table is missing (SQLSTATE 42S02), create it and retry once
            if ($insertErr->getCode() === '42S02') {
                $conn->exec("CREATE TABLE IF NOT EXISTS page_visits (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    restaurant_id VARCHAR(50) NOT NULL DEFAULT 'RES001',
                    page_url VARCHAR(255) NOT NULL,
                    page_name VARCHAR(100) NOT NULL,
                    visitor_ip VARCHAR(45) DEFAULT NULL,
                    user_agent TEXT DEFAULT NULL,
                    referrer_url VARCHAR(500) DEFAULT NULL,
                    visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_restaurant (restaurant_id),
                    INDEX idx_visited_at (visited_at),
                    INDEX idx_page (page_url(100))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                // Retry the INSERT once
                $stmt = $conn->prepare("INSERT INTO page_visits (restaurant_id, page_url, page_name, visitor_ip, user_agent, referrer_url) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$restaurant_id, $page_url, $page_name, $visitor_ip, $user_agent, $referrer_url]);
            }
        }
    }
} catch (Exception $e) {
    // Silently fail - tracking should never break the page
}

// Return 204 No Content (lightweight response)
http_response_code(204);
