<?php
/**
 * Database Connection File - Highly Optimized for High Concurrency
 * Creates PDO connection and getConnection() function
 * Auto-detects environment (local vs Hostinger server)
 * 
 * Features:
 * - Non-persistent connections (prevents connection leaks)
 * - Automatic connection cleanup and closure
 * - Error handling and retry logic with progressive backoff
 * - Connection health checks
 * - Optimized timeouts for high concurrency
 * - Optimized for 200+ concurrent users
 */

// Force Indian Standard Time everywhere (date(), NOW()-equivalents, timestamps)
// regardless of the server's own system timezone.
date_default_timezone_set('Asia/Kolkata');

// Prevent multiple includes
if (isset($pdo) && $pdo instanceof PDO) {
    return;
}

if (function_exists('getConnection')) {
    return;
}

// Load environment variables first (from .env file)
require_once __DIR__ . '/config/env_loader.php';

// Load error handler (file-based fallback logging)
require_once __DIR__ . '/config/error_handler.php';

// ============================================================
// DATABASE CONFIGURATION - READ FROM ENVIRONMENT VARIABLES
// ============================================================
// Set DB_* env vars in your .env file (copy from .env.example).
// Local dev defaults are provided as fallback.
// ============================================================

// Lazy connection flag - only connect when actually needed
$GLOBALS['db_lazy_connect'] = true;

// Error handling - log errors but don't display (prevents HTML in JSON responses)
error_reporting(E_ALL);
ini_set('display_errors', 0);  // Don't display errors (prevents HTML output)
ini_set('log_errors', 1);

// Set UTF-8 encoding for all database operations and PHP output
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
mb_regex_encoding('UTF-8');

// Auto-detect if running on Hostinger server
// On Hostinger: connects via localhost (internal MySQL socket) to production DB
// On local XAMPP: connects via localhost to local MySQL with imported data
$is_hostinger_server = (
    isset($_SERVER['SERVER_NAME']) && 
    (strpos($_SERVER['SERVER_NAME'], 'hstgr.io') !== false || 
     strpos($_SERVER['SERVER_NAME'], 'restrogrow.com') !== false ||
     strpos($_SERVER['HTTP_HOST'] ?? '', 'hstgr.io') !== false ||
     strpos($_SERVER['HTTP_HOST'] ?? '', 'restrogrow.com') !== false)
);

// 🔐 CREDENTIALS READ FROM ENVIRONMENT VARIABLES (via .env file)
// NEVER hardcode production credentials in source code.
// To set credentials, edit the .env file (copy from .env.example):
//   DB_HOST_REMOTE=auth-db1336.hstgr.io
//   DB_HOST_LOCAL=localhost
//   DB_NAME=your_database_name
//   DB_USER=your_database_user
//   DB_PASS=your_database_password
//
// For Hostinger production, the DB connects via localhost (internal socket).
// Set DB_HOST_LOCAL in .env if different from 'localhost'.

if ($is_hostinger_server) {
    // Production: connect to Hostinger MySQL via localhost
    $host = env('DB_HOST_LOCAL', 'localhost');
    $dbname = env('DB_NAME', 'u509616587_restrogrow');
    $username = env('DB_USER', 'u509616587_restrogrow');
    $password = env('DB_PASS', '');
} else {
    // Local development: connect to XAMPP MySQL
    $host = env('DB_HOST_LOCAL', 'localhost');
    $dbname = env('DB_NAME', 'menuwebsite_db');
    $username = env('DB_USER', 'root');
    $password = env('DB_PASS', '');
}

// Highly optimized connection configuration
// Add connection timeout in DSN for remote connections
$timeout = $is_hostinger_server ? 2 : 5; // 5s timeout for remote connections from localhost
$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
// Use NON-persistent connections to prevent connection accumulation on Hostinger
// This ensures connections are properly closed after each request
// Prevents hitting the 500 connections/hour limit
$options = [
    PDO::ATTR_PERSISTENT => false,  // NON-persistent connections (prevents connection leaks)
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,  // Use native prepared statements (faster, more secure)
    PDO::ATTR_TIMEOUT => $timeout,  // Connection timeout
    PDO::ATTR_STRINGIFY_FETCHES => false,  // Keep numeric types as numbers (faster)
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,  // Use buffered queries (REQUIRED to prevent unbuffered query errors)
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
];

// Connection retry configuration
$max_retries = $is_hostinger_server ? 3 : 2; // Fewer retries for remote (already slower)
$retry_delays = $is_hostinger_server ? [0.5, 1, 2] : [2, 5]; // Longer delays for remote connections

// Connection statistics (for monitoring)
if (!isset($GLOBALS['db_connection_stats'])) {
    $GLOBALS['db_connection_stats'] = [
        'attempts' => 0,
        'success' => 0,
        'failures' => 0,
        'retries' => 0,
    ];
}

// Lazy connection - only create connection when getConnection() is called
// This prevents unnecessary connections for requests that don't need DB access
$pdo = null;
$last_error = null;
$connection_attempt = 0;

// Function to actually create the connection (called lazily)
function createDatabaseConnection() {
    global $pdo, $last_error, $connection_attempt, $dsn, $username, $password, $options, $max_retries, $retry_delays, $is_hostinger_server;
    
    // If connection already exists and is valid, return it
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $stmt = $pdo->query("SELECT 1");
            $stmt->fetchAll();
            $stmt = null;
            return $pdo;
        } catch (Exception $e) {
            // Connection is dead, recreate it
            $pdo = null;
        }
    }
    
    $pdo = null;
    $last_error = null;
    $connection_attempt = 0;
    
    // Retry logic for connection failures with progressive backoff
    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        $connection_attempt = $attempt;
        $GLOBALS['db_connection_stats']['attempts']++;
        
        try {
            // Create connection
            $pdo = new PDO($dsn, $username, $password, $options);

            // Note: no post-connect "SELECT 1" verify here — the PDO
            // constructor above already throws on failure, so a fresh
            // connection is proven alive without an extra round trip.

            // Force IST + tune session timeouts in a single round trip
            // (previously 4 separate exec() calls, each its own network
            // round trip to the DB on every single non-persistent connection
            // — i.e. on every login and every order placed).
            $pdo->exec("SET SESSION time_zone = '+05:30', wait_timeout = 30, interactive_timeout = 30, sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");

            // Best-effort perf tuning - not all MySQL versions/hosts support
            // these (query_cache_type was removed entirely in MySQL 8+), so
            // combined into one guarded statement rather than 3 separate ones.
            try {
                $pdo->exec("SET SESSION query_cache_type = OFF, net_read_timeout = 30, net_write_timeout = 30");
            } catch (PDOException $e) {
                // Ignore if not supported
            }

            // Automatically expire trials that have passed their end date.
            // This used to run on EVERY connection (i.e. every login/order),
            // adding a full write round trip to each one. Now gated to run
            // at most once every 2 minutes across all requests via a
            // timestamp lock file - the sweep is idempotent, so occasionally
            // running it twice under concurrent load is harmless.
            $trialSweepLock = __DIR__ . '/tmp/trial_sweep.lock';
            $lastSweep = @filemtime($trialSweepLock);
            if ($lastSweep === false || (time() - $lastSweep) > 120) {
                if (!is_dir(__DIR__ . '/tmp')) {
                    @mkdir(__DIR__ . '/tmp', 0755, true);
                }
                if (@touch($trialSweepLock)) {
                    try {
                        $pdo->exec("UPDATE users SET subscription_status = 'expired', is_active = 0
                                   WHERE subscription_status = 'trial'
                                   AND trial_end_date IS NOT NULL
                                   AND trial_end_date < CURRENT_DATE()
                                   LIMIT 100");  // Limit to prevent long-running queries
                    } catch (PDOException $e) {
                        // Silently fail if columns don't exist or other error
                        error_log("Error auto-expiring trials: " . $e->getMessage());
                    }
                }
            }
            
            // Expire inactive loyalty point balances, same lock-file gating
            // as the trial sweep above but checked once every 24 hours —
            // expiry is a daily-granularity concern, not worth checking on
            // every connection.
            $pointsExpirySweepLock = __DIR__ . '/tmp/points_expiry_sweep.lock';
            $lastPointsSweep = @filemtime($pointsExpirySweepLock);
            if ($lastPointsSweep === false || (time() - $lastPointsSweep) > 86400) {
                if (!is_dir(__DIR__ . '/tmp')) {
                    @mkdir(__DIR__ . '/tmp', 0755, true);
                }
                if (@touch($pointsExpirySweepLock)) {
                    try {
                        require_once __DIR__ . '/config/growth_helpers.php';
                        expireInactiveLoyaltyPoints($pdo);
                    } catch (Exception $e) {
                        error_log("Error expiring loyalty points: " . $e->getMessage());
                    }
                }
            }

            // Clear any previous transaction state
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // ── Boot error monitor on this connection ──
            // Installs PHP error/exception/shutdown handlers that log to DB
            if (!defined('ERROR_MONITOR_LOADED') && file_exists(__DIR__ . '/config/error_monitor.php')) {
                require_once __DIR__ . '/config/error_monitor.php';
            }
            if (function_exists('setupErrorMonitor')) {
                try {
                    setupErrorMonitor($pdo);
                } catch (Exception $_em) {
                    // Don't let monitoring setup break the app
                }
            }
            
            // Track connection creation time for monitoring
            $GLOBALS['db_connection_created'] = time();
            
            // Success - break out of retry loop
            $GLOBALS['db_connection_stats']['success']++;
            if ($attempt > 1) {
                $GLOBALS['db_connection_stats']['retries']++;
            }
            return $pdo; // Return connection
            
        } catch (PDOException $e) {
            $last_error = $e;
            $GLOBALS['db_connection_stats']['failures']++;
            
            $error_msg = $e->getMessage();
            $error_code = $e->getCode();
            
            // If it's a connection limit error, wait before retry
            if (strpos($error_msg, 'max_connections') !== false || 
                strpos($error_msg, 'Too many connections') !== false ||
                $error_code == 1226) {
                
                if ($attempt < $max_retries) {
                    $delay = $retry_delays[$attempt - 1] ?? 1;
                    error_log("Connection limit reached, waiting {$delay}s before retry (attempt $attempt/$max_retries)");
                    sleep($delay);
                }
            } elseif (strpos($error_msg, 'Connection refused') !== false ||
                      strpos($error_msg, 'Connection timed out') !== false ||
                      strpos($error_msg, 'did not properly respond') !== false ||
                      $error_code == 2002 || $error_code == 2006) {
                // Network/timeout errors - wait longer and retry
                if ($attempt < $max_retries) {
                    $delay = $retry_delays[$attempt - 1] ?? 2;
                    error_log("Connection timeout/network error, waiting {$delay}s before retry (attempt $attempt/$max_retries): $error_msg");
                    sleep($delay);
                }
            } else {
                // Other errors - don't retry
                break;
            }
        }
    }
    
    // If still no connection after retries, throw error
    if (!$pdo || !($pdo instanceof PDO)) {
        throw $last_error ?? new Exception('Failed to establish database connection after ' . $max_retries . ' attempts');
    }
    
    return $pdo;
}

// Connection health check function (ensures buffered query)
if (!function_exists('checkConnectionHealth')) {
    function checkConnectionHealth($conn) {
        try {
            // Use lightweight ping query for health check (faster than SELECT 1)
            $stmt = $conn->query("SELECT 1");
            $result = $stmt->fetchAll();
            $stmt = null;  // Free statement immediately
            return !empty($result);
        } catch (Exception $e) {
            return false;
        }
    }
}



// Optimized getConnection function with lazy connection and health check
if (!function_exists('getConnection')) {
    function getConnection() {
        global $pdo, $GLOBALS, $is_hostinger_server, $host, $dbname, $connection_attempt, $max_retries;
        
        // Lazy connection - only create when actually needed
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            try {
                createDatabaseConnection();
            } catch (Exception $e) {
                // Handle connection errors
                $error_code = $e->getCode();
                $error_message = $e->getMessage();
                
                // Check if it's a connection limit error
                if (strpos($error_message, 'max_connections') !== false || 
                    strpos($error_message, 'Too many connections') !== false ||
                    $error_code == 1226) {
                    error_log("Database connection limit exceeded");
                    $error_msg = "Database temporarily unavailable due to high traffic. Please try again in a moment.";
                    $http_code = 503;  // Service Unavailable
                } elseif (strpos($error_message, 'Connection refused') !== false ||
                          strpos($error_message, 'Connection timed out') !== false ||
                          strpos($error_message, 'did not properly respond') !== false ||
                          $error_code == 2002 || $error_code == 2006) {
                    error_log("Database connection network/timeout error: " . $error_message);
                    if (!$is_hostinger_server) {
                        $error_msg = "Cannot connect to remote database. Remote access may be disabled or network is slow. Please check your connection or use the production server.";
                    } else {
                        $error_msg = "Database connection timeout. Please try again.";
                    }
                    $http_code = 503;
                } else {
                    $error_msg = "Database Error: " . $error_message;
                    $http_code = 500;  // Internal Server Error
                }
                
                $stats = getConnectionStats();
                $attempts = $stats['attempts'] ?? 0;
                error_log("DB Connection Error: " . $error_msg . " (Attempts: $attempts)");
                
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=UTF-8');
                    http_response_code($http_code);
                    echo json_encode([
                        'success' => false,
                        'message' => $error_msg,
                        'host' => $host,
                        'database' => $dbname,
                        'environment' => $is_hostinger_server ? 'hostinger' : 'local',
                        'attempts' => $attempts
                    ], JSON_UNESCAPED_UNICODE);
                    exit();
                } else {
                    die($error_msg);
                }
            }
        }
        
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('Database connection not initialized');
        }
        
        // Quick health check (only if connection seems stale or old)
        static $last_check = 0;
        $now = time();
        $connection_age = isset($GLOBALS['db_connection_created']) ? ($now - $GLOBALS['db_connection_created']) : 0;
        
        // Check health if:
        // 1. More than 5 seconds since last check, OR
        // 2. Connection is older than 60 seconds (prevent stale connections)
        if (($now - $last_check > 5) || ($connection_age > 60)) {
            try {
                if (!checkConnectionHealth($pdo)) {
                    // Connection is dead, recreate it
                    $pdo = null;
                    unset($GLOBALS['db_connection_created']);
                    createDatabaseConnection();
                    if (!isset($pdo) || !($pdo instanceof PDO)) {
                        throw new Exception('Database connection is not healthy');
                    }
                }
            } catch (Exception $e) {
                // Health check failed - try to recreate connection
                $pdo = null;
                unset($GLOBALS['db_connection_created']);
                try {
                    createDatabaseConnection();
                } catch (Exception $e2) {
                    throw new Exception('Database connection failed: ' . $e2->getMessage());
                }
            }
            $last_check = $now;
        }
        
        return $pdo;
    }
}

// Connection statistics function
if (!function_exists('getConnectionStats')) {
    function getConnectionStats() {
        $stats = $GLOBALS['db_connection_stats'] ?? [
            'attempts' => 0,
            'success' => 0,
            'failures' => 0,
            'retries' => 0,
        ];
        
        // Add connection age if available
        if (isset($GLOBALS['db_connection_created'])) {
            $stats['connection_age'] = time() - $GLOBALS['db_connection_created'];
        }
        
        // Calculate success rate
        if ($stats['attempts'] > 0) {
            $stats['success_rate'] = round(($stats['success'] / $stats['attempts']) * 100, 2);
        } else {
            $stats['success_rate'] = 0;
        }
        
        return $stats;
    }
}



// Only create connection immediately if lazy connection is disabled
// Otherwise, connection will be created on first getConnection() call
if (!($GLOBALS['db_lazy_connect'] ?? true)) {
    try {
        $pdo = createDatabaseConnection();
    } catch (Exception $e) {
        // Error will be handled when getConnection() is called
        error_log("Initial connection attempt failed: " . $e->getMessage());
    }
}

// If connection was created, set up shutdown function
if (isset($pdo) && $pdo instanceof PDO) {
    // For non-persistent connections, we close them properly to prevent connection leaks
    // This ensures connections are freed immediately after each request
    register_shutdown_function(function() use (&$pdo) {
        if (isset($pdo) && $pdo instanceof PDO) {
            try {
                // Close any open transactions
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // Explicitly close the connection for non-persistent connections
                $pdo = null;
            } catch (Exception $e) {
                // Ignore errors during cleanup, but still try to close
                $pdo = null;
            }
        }
    });
}
?>
