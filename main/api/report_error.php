<?php
/**
 * Error Report API — for client-side (JS) error ingestion
 * 
 * Accepts POST requests from the JS error interceptor and logs them
 * into the `error_logs` table for unified monitoring.
 * 
 * POST params:
 *   source    - 'js'
 *   severity  - 'error' | 'warning' | 'info' | 'critical'
 *   message   - The error message
 *   file      - Source file URL
 *   line      - Line number
 *   trace     - Stack trace
 *   context   - JSON string of extra context
 *   url       - Page URL where error occurred
 * 
 * Response: { "success": true }
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/error_monitor.php';

// Don't validate session — we want errors even from anonymous users

$input = $_POST;

// If JSON body, parse it
if (empty($input)) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $input = json_decode($raw, true) ?? [];
    }
}

// Validate required
$message = trim($input['message'] ?? '');
if (empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'message is required']);
    exit;
}

try {
    $conn = getConnection();
    
    $source   = $input['source'] ?? 'js';
    $severity = $input['severity'] ?? 'error';
    $file     = $input['file'] ?? null;
    $line     = isset($input['line']) ? (int)$input['line'] : null;
    $trace    = $input['trace'] ?? null;
    $url      = $input['url'] ?? ($_SERVER['HTTP_REFERER'] ?? null);
    $ua       = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    // Parse extra context
    $extra = [];
    if (!empty($input['context'])) {
        $ctx = is_string($input['context']) 
            ? json_decode($input['context'], true) 
            : $input['context'];
        if (is_array($ctx)) $extra = $ctx;
    }
    
    // Add browser info
    $extra['browser'] = [
        'user_agent' => $ua,
        'language'   => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
    ];

    // Log via central monitor
    $id = logErrorToDB($conn, $source, $severity, $message, [
        'file'       => $file,
        'line'       => $line,
        'trace'      => $trace,
        'url'        => $url,
        'user_agent' => $ua,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_id'    => $_SESSION['user_id'] ?? null,
        'restaurant_id' => $_SESSION['restaurant_id'] ?? null,
        'extra'      => $extra,
    ]);

    echo json_encode([
        'success' => true,
        'id'      => $id,
    ]);

} catch (Exception $e) {
    // Silently fail — we don't want error-reporting to cause errors
    http_response_code(200); // Still return 200 so the client doesn't get HTTP errors
    echo json_encode(['success' => false, 'message' => 'Logging failed']);
}
