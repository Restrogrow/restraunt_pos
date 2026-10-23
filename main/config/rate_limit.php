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
    // This ensures trackFailedAttempt() lockouts are enforced even when applyAuthRateLimit() prefixes the identifier.
    // Login attempts only: Forgot password, reset and sign-up / OTP codes stay open while locked out, since
    // the lockout message sends people to Forgot password (it used to answer 429 there too).
    $baseLockoutFile = null;
    if (strpos($identifier, 'login_') === 0 || strpos($identifier, 'demo_login_') === 0) {
        $ipSafe = preg_replace('/[^a-zA-Z0-9_-]/', '_', getAuthRateLimitIdentifier());
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
                        'message' => 'Too many wrong tries. Please wait ' . ceil($retryAfter / 60) . ' minute(s) and try again, or use Forgot password.'
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
 * After 10 failed attempts within 15 min, lock for 30 min.
 * (Back to 10 from 3 on 2026-10-23 — the owner found 3 locked real users out
 * after a few typos; 10 wrong tries still stops anyone guessing passwords.
 * Every attempt is also mirrored into the login_logs audit table by
 * auth.php, so the Logs screen shows the full strike pattern.)
 *
 * $subject (optional): the username that was tried. A later successful login clears only that
 * username's strikes (clearFailedAttempts()), so logging into your OWN account no longer wipes the
 * strikes run up guessing someone else's password.
 */
function trackFailedAttempt($identifier, $subject = null) {
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
    
    // Remove attempts older than 15 minutes (an attempt is a timestamp, or [t, subject hash])
    $data['attempts'] = array_values(array_filter($data['attempts'], function($t) use ($now) {
        return ($now - (is_array($t) ? (int)($t[0] ?? 0) : (int)$t)) < 900; // 15 minutes
    }));

    // Add this failed attempt
    $data['attempts'][] = $subject !== null && $subject !== '' ? [$now, rateLimitSubjectHash($subject)] : $now;
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

function rateLimitSubjectHash($subject) {
    return substr(hash('sha256', 'rl|' . strtolower(trim((string)$subject))), 0, 16);
}

/**
 * Clear failed attempts counter (on successful login)
 * With $subject: only that username's strikes go (see trackFailedAttempt()); a lockout file is left
 * alone, it can't be active anyway when a login just got through checkRateLimit().
 */
function clearFailedAttempts($identifier, $subject = null) {
    $rateLimitDir = __DIR__ . '/../tmp/rate_limits';
    $safeIdentifier = preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier);

    $failedFile = $rateLimitDir . '/' . $safeIdentifier . '_failed.json';
    if ($subject !== null && $subject !== '') {
        if (!file_exists($failedFile)) return;
        $data = json_decode((string)file_get_contents($failedFile), true);
        if (!$data || !isset($data['attempts'])) return;
        $h = rateLimitSubjectHash($subject);
        $data['attempts'] = array_values(array_filter($data['attempts'], function ($t) use ($h) {
            return !(is_array($t) && ($t[1] ?? '') === $h);
        }));
        $data['count'] = count($data['attempts']);
        file_put_contents($failedFile, json_encode($data));
        return;
    }
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
    return getAuthRateLimitIdentifier();
}

/**
 * IP-only identifier for logins, lockouts and password resets. Never the session: keyed on the
 * logged-in user_id, an attacker signed into their own account got a bucket whose lockout file
 * checkRateLimit() never read, so the 10-wrong-tries lockout never applied to them.
 */
function getAuthRateLimitIdentifier() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // X-Forwarded-For is only trustworthy when the request actually came
    // through a known reverse proxy — otherwise any client can set it to an
    // arbitrary value on every request and get a fresh rate-limit bucket
    // each time, defeating brute-force protection entirely. Only honor it
    // when REMOTE_ADDR (the actual TCP peer, which can't be spoofed) is in
    // a configured trusted-proxy allowlist; TRUSTED_PROXY_IPS is unset by
    // default, so out of the box this always uses REMOTE_ADDR directly.
    $trustedProxies = function_exists('env') ? env('TRUSTED_PROXY_IPS', '') : '';
    if ($trustedProxies !== '' && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $trustedList = array_map('trim', explode(',', $trustedProxies));
        if (in_array($ip, $trustedList, true)) {
            $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($forwarded[0]);
        }
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
 *   'login'        -> 10 attempts per 60 seconds
 *   'signup'       -> 3 attempts per 3600 seconds (1 hour)
 *   'forgotPwd'    -> 3 attempts per 300 seconds (5 minutes)
 *   'resetPwd'     -> 5 attempts per 900 seconds (15 minutes)
 * After 10 failed attempts within 15 min, a 30-min lockout is applied.
 */
function applyAuthRateLimit($type = 'login') {
    $limits = [
        'login'     => ['max' => 10, 'window' => 60],
        'signup'    => ['max' => 3,  'window' => 3600],
        'forgotPwd' => ['max' => 3,  'window' => 300],
        'resetPwd'  => ['max' => 5,  'window' => 900],
    ];
    
    if (!isset($limits[$type])) {
        $type = 'login'; // fallback
    }
    
    $limit = $limits[$type];
    
    // Use a combined identifier: type + IP (so login and forgotPwd counters are separate)
    $baseId = getAuthRateLimitIdentifier(); // IP, never the session (see getAuthRateLimitIdentifier())
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
