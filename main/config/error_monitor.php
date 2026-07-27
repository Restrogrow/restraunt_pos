<?php
/**
 * Error Monitoring System — Central Engine
 * 
 * Catches ALL errors from every part of the application:
 *   - PHP errors / exceptions / fatals
 *   - JavaScript console errors (via report_error.php API)
 *   - State machine violations (order transitions)
 *   - Database errors
 *   - Auth failures
 *   - Custom/logical errors from anywhere
 * 
 * Every error is logged to the `error_logs` table and viewable in
 * the admin Error Monitor dashboard in real time.
 * 
 * HOW TO USE:
 *   // Auto-setup (call once at app bootstrap):
 *   setupErrorMonitor($conn);
 * 
 *   // Manual log from anywhere:
 *   logErrorToDB($conn, 'php', 'error', 'Something broke', [
 *     'file' => __FILE__,
 *     'line' => __LINE__,
 *   ]);
 * 
 *   // With exception:
 *   logException($conn, $e, ['extra' => 'data']);
 */

// Prevent double-load
if (defined('ERROR_MONITOR_LOADED')) return;
define('ERROR_MONITOR_LOADED', true);

// ─── Core: Log to database ─────────────────────────────────────────────────

/**
 * Log an error to the error_logs table.
 *
 * @param PDO      $conn        Active DB connection
 * @param string   $source      php|js|api|auth|db|state_machine|custom
 * @param string   $severity    critical|error|warning|info|debug
 * @param string   $message     The error message
 * @param array    $context     Optional: file, line, trace, extra data
 *
 * @return int|false  The inserted row ID, or false on failure
 */
function logErrorToDB(PDO $conn, string $source, string $severity, string $message, array $context = []): int|false {
    // Sanitise
    $allowedSources = ['php','js','api','auth','db','state_machine','custom'];
    $allowedSeverities = ['critical','error','warning','info','debug'];

    if (!in_array($source, $allowedSources, true)) $source = 'custom';
    if (!in_array($severity, $allowedSeverities, true)) $severity = 'error';

    // Build context
    $restaurantId = $context['restaurant_id']
        ?? ($_SESSION['restaurant_id'] ?? null);

    $file   = $context['file'] ?? null;
    $line   = $context['line'] ?? null;
    $trace  = $context['trace'] ?? null;
    $url    = $context['url'] ?? ($_SERVER['REQUEST_URI'] ?? null);
    $ua     = $context['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
    $ip     = $context['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    $userId = $context['user_id'] ?? ($_SESSION['user_id'] ?? null);

    // Encode extra context
    $jsonContext = null;
    if (!empty($context['extra'])) {
        $jsonContext = json_encode($context['extra'], JSON_UNESCAPED_UNICODE);
    } elseif (!empty($context)) {
        // Strip known keys, keep the rest as context
        $extra = $context;
        unset($extra['restaurant_id'], $extra['file'], $extra['line'], $extra['trace'],
              $extra['url'], $extra['user_agent'], $extra['ip_address'], $extra['user_id'],
              $extra['extra']);
        if (!empty($extra)) {
            $jsonContext = json_encode($extra, JSON_UNESCAPED_UNICODE);
        }
    }

    try {
        $stmt = $conn->prepare("
            INSERT INTO error_logs 
                (restaurant_id, source, severity, message, file, line, trace, 
                 context, url, user_agent, ip_address, user_id, created_at)
            VALUES 
                (?, ?, ?, ?, ?, ?, ?, 
                 ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $restaurantId,
            $source,
            $severity,
            mb_substr($message, 0, 5000),   // cap message length
            $file ? mb_substr($file, 0, 500) : null,
            $line ? (int)$line : null,
            $trace ? mb_substr($trace, 0, 10000) : null,
            $jsonContext,
            $url ? mb_substr($url, 0, 500) : null,
            $ua ? mb_substr($ua, 0, 500) : null,
            $ip ? mb_substr($ip, 0, 45) : null,
            $userId ? (int)$userId : null,
        ]);
        return (int)$conn->lastInsertId();
    } catch (PDOException $e) {
        // Don't infinitely loop — fallback to error_log
        error_log("[ErrorMonitor] Failed to log to DB: " . $e->getMessage());
        error_log("[ErrorMonitor] Original error: $message");
        return false;
    }
}

/**
 * Log a PHP Exception/Throwable with full trace.
 */
function logException(PDO $conn, Throwable $e, array $extra = []): int|false {
    $context = array_merge([
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'extra' => $extra,
    ], $extra);

    $severity = 'error';
    if ($e instanceof PDOException) {
        $source = 'db';
        $severity = 'critical';
    } elseif ($e instanceof InvalidArgumentException) {
        $source = 'api';
        $severity = 'warning';
    } else {
        $source = 'php';
    }

    return logErrorToDB($conn, $source, $severity, $e->getMessage(), $context);
}

/**
 * Log a state machine violation (illegal order status transition).
 */
function logStateMachineViolation(PDO $conn, int $orderId, string $from, string $to, ?string $restaurantId = null): int|false {
    return logErrorToDB($conn, 'state_machine', 'warning',
        "Illegal order status transition: {$from} → {$to}",
        [
            'file'  => __FILE__,
            'extra' => [
                'order_id'       => $orderId,
                'from_status'    => $from,
                'to_status'      => $to,
                'restaurant_id'  => $restaurantId,
            ],
        ]
    );
}

/**
 * Log an auth-related event (failed login, session expiry, permission denied).
 */
function logAuthEvent(PDO $conn, string $severity, string $message, array $extra = []): int|false {
    return logErrorToDB($conn, 'auth', $severity, $message, [
        'extra' => $extra,
    ]);
}

// ─── PHP Error / Exception / Shutdown Handlers ────────────────────────────

/** @var PDO|null Stashed for use in static callbacks */
$GLOBALS['_error_monitor_conn'] = null;

/**
 * Install all PHP-side error hooks.
 * Call once at bootstrap (db_connection.php or index.php).
 */
function setupErrorMonitor(PDO $conn): void {
    // Stash connection for the static callbacks
    $GLOBALS['_error_monitor_conn'] = $conn;

    // ── 1. Custom error handler (E_WARNING, E_NOTICE, E_USER_*) ──
    set_error_handler(function (
        int    $errno,
        string $errstr,
        string $errfile = '',
        int    $errline = 0
    ): bool {
        $conn = $GLOBALS['_error_monitor_conn'] ?? null;
        if (!$conn) return false;

        $severityMap = [
            E_WARNING        => 'warning',
            E_NOTICE         => 'info',
            E_USER_ERROR     => 'error',
            E_USER_WARNING   => 'warning',
            E_USER_NOTICE    => 'info',
            E_STRICT         => 'info',
            E_DEPRECATED     => 'info',
            E_USER_DEPRECATED=> 'info',
        ];

        // Don't log suppressed errors (@ operator)
        if (!(error_reporting() & $errno)) return false;

        $severity = $severityMap[$errno] ?? 'error';

        logErrorToDB($conn, 'php', $severity, $errstr, [
            'file' => $errfile,
            'line' => $errline,
        ]);

        // Let the standard error handler also run
        return false;
    });

    // ── 2. Uncaught exception handler ──
    set_exception_handler(function (Throwable $e): void {
        $conn = $GLOBALS['_error_monitor_conn'] ?? null;
        if ($conn) {
            logException($conn, $e);
        }
        // Fallback: let the default handler run
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Internal server error',
            ]);
        }
    });

    // ── 3. Fatal error shutdown handler ──
    register_shutdown_function(function (): void {
        $conn = $GLOBALS['_error_monitor_conn'] ?? null;
        if (!$conn) return;

        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            logErrorToDB($conn, 'php', 'critical',
                $error['message'],
                [
                    'file'  => $error['file'] ?? 'unknown',
                    'line'  => $error['line'] ?? 0,
                    'extra' => ['type' => $error['type']],
                ]
            );
        }
    });
}

// ─── Helper: Get unread error count ────────────────────────────────────────

/**
 * Get count of unread errors for badge display.
 */
function getUnreadErrorCount(PDO $conn, ?string $restaurantId = null): int {
    try {
        $sql = "SELECT COUNT(*) FROM error_logs WHERE is_read = 0";
        $params = [];
        if ($restaurantId) {
            $sql .= " AND (restaurant_id = ? OR restaurant_id IS NULL)";
            $params[] = $restaurantId;
        }
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Get recent errors for the dashboard.
 */
function getRecentErrors(PDO $conn, int $limit = 50, ?string $restaurantId = null): array {
    try {
        $sql = "SELECT * FROM error_logs WHERE 1=1";
        $params = [];
        if ($restaurantId) {
            $sql .= " AND (restaurant_id = ? OR restaurant_id IS NULL)";
            $params[] = $restaurantId;
        }
        $sql .= " ORDER BY created_at DESC LIMIT " . (int)$limit;
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}
