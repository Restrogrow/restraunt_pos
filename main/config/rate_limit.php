<?php
/**
 * Rate Limiting for API & Auth Endpoints
 * Prevents abuse, brute-force attacks, and DDoS
 */

/**
 * Simple file-based rate limiter
 * For production, consider using Redis or Memcached
 */
function checkRateLimit($identifier, $maxRequests = 60, $timeWindow = 60) {
    $rateLimitDir = __DIR__ . '/../tmp/rate_limits';
    
    // Create directory if it doesn't exist
    if (!is_dir($rateLimitDir)) {
        mkdir($rateLimitDir, 0755, true);
    }
    
    // Use IP address or user ID as identifier
    if (empty($identifier)) {
        $identifier = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    // Sanitize identifier for filename
    $safeIdentifier = preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier);
    $rateLimitFile = $rateLimitDir . '/' . $safeIdentifier . '.json';
    
    $now = time();
    $requests = [];
    
    // Check for lockout file (progressive lockout for failed auth attempts)
    $lockoutFile = $rateLimitDir . '/' . $safeIdentifier . '_lockout.json';
    
    // Also check for base IP lockout (for auth rate limiter which prefixes with type like 'login_ip_xxx')
    // This ensures trackFailedAttempt() lockouts are enforced even when applyAuthRateLimit() prefixes the identifier
    $baseLockoutFile = null;
    if (strpos($identifier, 'ip_') !== false || strpos($identifier, 'login_') === 0 || strpos($identifier, 'signup_') === 0 || strpos($identifier, 'forgotPwd_') === 0 || strpos($identifier, 'resetPwd_') === 0) {
        $ipOnly = 'ip_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $ipSafe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $ipOnly);
        $baseLockoutFile = $rateLimitDir . '/' . $ipSafe . '_lockout.json';
    }
    
    // Try the type-prefixed lockout first, then fall back to base IP lockout
    foreach ([$lockoutFile, $baseLockoutFile] as $lf) {
        if ($lf && file_exists($lf)) {
            $lockoutData = json_decode(file_get_contents($lf), true);
            if ($lockoutData && isset($lockoutData['locked_until'])) {
                if ($now < $lockoutData['locked_until']) {
                    $retryAfter = $lockoutData['locked_until'] - $now;
                    return [
                        'allowed' => false,
                        'retry_after' => $retryAfter,
                        'message' => "Too many failed attempts. Account locked for {$retryAfter} more seconds."
                    ];
                } else {
                    // Lockout expired, remove lockout file
                    @unlink($lf);
                }
            }
        }
    }
    
    // Load existing requests
    if (file_exists($rateLimitFile)) {
        $data = json_decode(file_get_contents($rateLimitFile), true);
        if ($data && isset($data['requests'])) {
            $requests = $data['requests'];
        }
    }
    
    // Remove requests outside the time window
    $requests = array_filter($requests, function($timestamp) use ($now, $timeWindow) {
        return ($now - $timestamp) < $timeWindow;
    });
    
    // Check if limit exceeded
    if (count($requests) >= $maxRequests) {
        $oldestRequest = min($requests);
        $retryAfter = $timeWindow - ($now - $oldestRequest);
        if ($retryAfter < 1) $retryAfter = 1;
        
        return [
            'allowed' => false,
            'retry_after' => $retryAfter,
            'message' => "Rate limit exceeded. Please try again in {$retryAfter} seconds."
        ];
    }
    
    // Add current request
    $requests[] = $now;
    
    // Save updated requests
    file_put_contents($rateLimitFile, json_encode([
        'requests' => array_values($requests),
        'last_updated' => $now
    ]));
    
    // Clean up old files (older than 1 hour)
    if (rand(1, 100) === 1) { // 1% chance to clean up (avoid overhead)
        cleanupOldRateLimitFiles($rateLimitDir, 3600);
    }
    
    return [
        'allowed' => true,
        'remaining' => $maxRequests - count($requests)
    ];
}

/**
 * Track a failed auth attempt and apply progressive lockout
 * After 10 failed attempts within 15 min, lock for 30 min
 */
function trackFailedAttempt($identifier) {
    $rateLimitDir = __DIR__ . '/../tmp/rate_limits';
    if (!is_dir($rateLimitDir)) {
        mkdir($rateLimitDir, 0755, true);
    }
    
    $safeIdentifier = preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier);
    $failedFile = $rateLimitDir . '/' . $safeIdentifier . '_failed.json';
    $now = time();
    
    $data = ['attempts' => [], 'count' => 0];
    if (file_exists($failedFile)) {
        $existing = json_decode(file_get_contents($failedFile), true);
        if ($existing && isset($existing['attempts'])) {
            $data = $existing;
        }
    }
    
    // Remove attempts older than 15 minutes
    $data['attempts'] = array_values(array_filter($data['attempts'], function($t) use ($now) {
        return ($now - $t) < 900; // 15 minutes
    }));
    
    // Add this failed attempt
    $data['attempts'][] = $now;
    $data['count'] = count($data['attempts']);
    
    // If 10+ failed attempts in 15 minutes, lock for 30 minutes
    if ($data['count'] >= 10) {
        $lockoutFile = $rateLimitDir . '/' . $safeIdentifier . '_lockout.json';
        file_put_contents($lockoutFile, json_encode([
            'locked_until' => $now + 1800, // 30 minutes
            'failed_count' => $data['count'],
            'last_attempt' => $now
        ]));
        // Clear failed attempts counter
        file_put_contents($failedFile, json_encode(['attempts' => [], 'count' => 0]));
    } else {
        file_put_contents($failedFile, json_encode($data));
    }
}

/**
 * Clear failed attempts counter (on successful login)
 */
function clearFailedAttempts($identifier) {
    $rateLimitDir = __DIR__ . '/../tmp/rate_limits';
    $safeIdentifier = preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier);
    
    $failedFile = $rateLimitDir . '/' . $safeIdentifier . '_failed.json';
    if (file_exists($failedFile)) {
        @unlink($failedFile);
    }
    
    $lockoutFile = $rateLimitDir . '/' . $safeIdentifier . '_lockout.json';
    if (file_exists($lockoutFile)) {
        @unlink($lockoutFile);
    }
}

/**
 * Clean up old rate limit files
 */
function cleanupOldRateLimitFiles($directory, $maxAge = 3600) {
    $files = glob($directory . '/*.json');
    if (!$files) return;
    $now = time();
    
    foreach ($files as $file) {
        if (filemtime($file) < ($now - $maxAge)) {
            @unlink($file);
        }
    }
}

/**
 * Get rate limit identifier (IP address or user ID)
 */
function getRateLimitIdentifier() {
    // Try to use user ID if logged in
    if (isset($_SESSION['user_id'])) {
        return 'user_' . $_SESSION['user_id'];
    }
    
    // Fall back to IP address
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Handle proxy headers (use X-Forwarded-For if available)
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($forwarded[0]);
    }
    
    return 'ip_' . $ip;
}

/**
 * Apply general rate limiting to an API endpoint
 * Default: 120 requests per 60 seconds
 */
function applyRateLimit($maxRequests = 120, $timeWindow = 60) {
    $identifier = getRateLimitIdentifier();
    $result = checkRateLimit($identifier, $maxRequests, $timeWindow);
    
    if (!$result['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . $result['retry_after']);
        header('Content-Type: application/json');
        
        echo json_encode([
            'success' => false,
            'message' => $result['message'],
            'retry_after' => $result['retry_after']
        ]);
        
        exit();
    }
    
    // Add rate limit headers to response
    header('X-RateLimit-Limit: ' . $maxRequests);
    header('X-RateLimit-Remaining: ' . ($result['remaining'] ?? 0));
    
    return true;
}

/**
 * Apply rate limiting specifically for auth endpoints
 * Tiers:
 *   'login'        -> 5 attempts per 60 seconds
 *   'signup'       -> 3 attempts per 3600 seconds (1 hour)
 *   'forgotPwd'    -> 3 attempts per 300 seconds (5 minutes)
 *   'resetPwd'     -> 5 attempts per 900 seconds (15 minutes)
 * After 10 consecutive failed attempts within 15 min, a 30-min lockout is applied.
 */
function applyAuthRateLimit($type = 'login') {
    $limits = [
        'login'     => ['max' => 5,  'window' => 60],
        'signup'    => ['max' => 3,  'window' => 3600],
        'forgotPwd' => ['max' => 3,  'window' => 300],
        'resetPwd'  => ['max' => 5,  'window' => 900],
    ];
    
    if (!isset($limits[$type])) {
        $type = 'login'; // fallback
    }
    
    $limit = $limits[$type];
    
    // Use a combined identifier: type + IP (so login and forgotPwd counters are separate)
    $baseId = getRateLimitIdentifier();
    $identifier = $type . '_' . $baseId;
    
    $result = checkRateLimit($identifier, $limit['max'], $limit['window']);
    
    if (!$result['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . $result['retry_after']);
        header('Content-Type: application/json');
        
        echo json_encode([
            'success' => false,
            'message' => $result['message'],
            'retry_after' => $result['retry_after']
        ]);
        
        exit();
    }
    
    // Add rate limit headers
    header('X-RateLimit-Limit: ' . $limit['max']);
    header('X-RateLimit-Remaining: ' . ($result['remaining'] ?? 0));
    
    return true;
}
