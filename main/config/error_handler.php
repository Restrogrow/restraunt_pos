<?php
/**
 * Centralized Error Handling System
 * 
 * NOW INTEGRATED WITH ERROR MONITOR:
 *   - All errors are logged to the `error_logs` DB table
 *   - All errors are still logged to files for backup
 *   - PHP errors/exceptions/fatals are auto-captured
 *   - View all errors in real-time in Error Monitor dashboard
 * 
 * Legacy file-based logging still works for backward compatibility.
 */

if (defined('ERROR_HANDLER_LOADED')) return;
define('ERROR_HANDLER_LOADED', true);

// Error log directory (legacy file-based logging)
define('ERROR_LOG_DIR', __DIR__ . '/../logs');
define('SECURITY_LOG_FILE', ERROR_LOG_DIR . '/security.log');
define('ERROR_LOG_FILE', ERROR_LOG_DIR . '/errors.log');
define('GENERAL_LOG_FILE', ERROR_LOG_DIR . '/general.log');

// Create logs directory if it doesn't exist
if (!is_dir(ERROR_LOG_DIR)) {
    @mkdir(ERROR_LOG_DIR, 0755, true);
}

/**
 * Log error with different severity levels (legacy file-based)
 * Now also logs to DB if error_monitor is loaded.
 */
function logError($message, $severity = 'ERROR', $context = []) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$severity}] {$message}";
    
    if (!empty($context)) {
        $logEntry .= " | Context: " . json_encode($context);
    }
    
    $logEntry .= PHP_EOL;
    
    // Determine log file based on severity
    $logFile = ERROR_LOG_FILE;
    if ($severity === 'SECURITY' || $severity === 'SECURITY_WARNING') {
        $logFile = SECURITY_LOG_FILE;
    }
    
    // Append to log file
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    // Also log to PHP error log
    error_log($logEntry);

    // Also log to DB if error_monitor is available
    if (function_exists('logErrorToDB') && isset($GLOBALS['_error_monitor_conn'])) {
        $dbSeverity = 'error';
        if ($severity === 'SECURITY' || $severity === 'SECURITY_WARNING') $dbSeverity = 'critical';
        elseif ($severity === 'WARNING') $dbSeverity = 'warning';
        elseif ($severity === 'INFO') $dbSeverity = 'info';

        logErrorToDB($GLOBALS['_error_monitor_conn'], 'php', $dbSeverity, $message, [
            'extra' => $context,
        ]);
    }
}

/**
 * Log security-related errors separately
 */
function logSecurityError($message, $context = []) {
    logError($message, 'SECURITY', $context);
}

/**
 * Log general errors
 */
function logGeneralError($message, $context = []) {
    logError($message, 'ERROR', $context);
}

/**
 * Log warnings
 */
function logWarning($message, $context = []) {
    logError($message, 'WARNING', $context);
}

/**
 * Handle and format errors for API responses
 */
function handleError($exception, $showDetails = false) {
    // Log the error
    $errorMessage = $exception->getMessage();
    $errorCode = $exception->getCode() ?: 500;
    $trace = $exception->getTraceAsString();
    
    // Determine if this is a security-related error
    $isSecurityError = (
        $exception instanceof PDOException ||
        strpos($errorMessage, 'SQL') !== false ||
        strpos($errorMessage, 'database') !== false ||
        strpos($errorMessage, 'authentication') !== false ||
        strpos($errorMessage, 'authorization') !== false ||
        strpos($errorMessage, 'unauthorized') !== false ||
        strpos($errorMessage, 'forbidden') !== false
    );
    
    if ($isSecurityError) {
        logSecurityError($errorMessage, [
            'code' => $errorCode,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_id' => $_SESSION['user_id'] ?? 'anonymous',
            'trace' => $trace
        ]);
    } else {
        logGeneralError($errorMessage, [
            'code' => $errorCode,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $trace
        ]);
    }

    // Also log to DB via logException if available
    if (function_exists('logException') && isset($GLOBALS['_error_monitor_conn'])) {
        logException($GLOBALS['_error_monitor_conn'], $exception);
    }
    
    // Set HTTP status code
    http_response_code($errorCode >= 400 && $errorCode < 600 ? $errorCode : 500);
    
    // Prepare response
    $response = [
        'success' => false,
        'message' => $showDetails ? $errorMessage : 'An error occurred. Please try again later.'
    ];
    
    // Add error code for debugging (only in development)
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        $response['error_code'] = $errorCode;
        $response['debug_message'] = $errorMessage;
    }
    
    return $response;
}

/**
 * Clean old log files (older than specified days)
 */
function cleanOldLogs($daysToKeep = 30) {
    $logDir = ERROR_LOG_DIR;
    $files = @glob($logDir . '/*.log');
    if (!$files) return;
    $cutoffTime = time() - ($daysToKeep * 24 * 60 * 60);
    
    foreach ($files as $file) {
        if (@filemtime($file) < $cutoffTime) {
            @unlink($file);
        }
    }
}

// Auto-cleanup old logs (1% chance on each request to avoid overhead)
if (rand(1, 100) === 1) {
    cleanOldLogs(30);
}
