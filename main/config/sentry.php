<?php
/**
 * Sentry error monitoring (sentry.io), without the SDK: events go to Sentry's envelope API with curl
 * at the end of the request, after the reply has been sent, so nothing waits on Sentry.
 *
 * What gets sent:
 *  - everything config/error_monitor.php logs at severity error / critical (PHP errors, uncaught
 *    exceptions, fatals, JS errors reported to report_error.php, state machine violations);
 *  - any request that ends with HTTP 500+ (many APIs catch their own errors and just answer 500);
 *  - rgSentryCaptureException() / rgSentryCaptureMessage() calls.
 * No cookies, passwords or request bodies are sent; restaurant id and URL path are.
 *
 * DSN: SENTRY_DSN in .env, else the project DSN below. SENTRY_DSN=off turns it off.
 * The browser side is config/sentry_browser.php (Sentry's loader script).
 */

if (defined('RG_SENTRY_LOADED')) return;
define('RG_SENTRY_LOADED', true);

const RG_SENTRY_DEFAULT_DSN = 'https://e71b765a7f275d7a634d8db26d738d4d@o4512249719488512.ingest.de.sentry.io/4512255432130640';

function rgSentryDsn(): ?array
{
    static $parsed = false;
    if ($parsed !== false) return $parsed;
    $dsn = function_exists('env') ? (string)env('SENTRY_DSN', RG_SENTRY_DEFAULT_DSN) : RG_SENTRY_DEFAULT_DSN;
    if ($dsn === '' || strtolower($dsn) === 'off') return $parsed = null;
    $u = parse_url($dsn);
    if (empty($u['user']) || empty($u['host']) || empty($u['path'])) return $parsed = null;
    $project = trim($u['path'], '/');
    return $parsed = [
        'key' => $u['user'],
        'url' => ($u['scheme'] ?? 'https') . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '') . '/api/' . $project . '/envelope/',
        'dsn' => $dsn,
    ];
}

function rgSentryEnvironment(): string
{
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    if (PHP_SAPI === 'cli') return 'cli';
    return ($host === '' || str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1')) ? 'local' : 'production';
}

/** Same message from the same place: once per request is enough. */
function rgSentryQueue(array $event): ?string
{
    if (!rgSentryDsn()) return null;
    $fp = md5(($event['message']['formatted'] ?? '') . json_encode($event['exception'] ?? null));
    if (isset($GLOBALS['_rg_sentry_seen'][$fp])) return null;
    $GLOBALS['_rg_sentry_seen'][$fp] = true;
    if (count($GLOBALS['_rg_sentry_queue'] ?? []) >= 10) return null;   // a broken loop must not flood Sentry

    $id = bin2hex(random_bytes(16));
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $event += [
        'event_id' => $id,
        'timestamp' => microtime(true),
        'platform' => 'php',
        'environment' => rgSentryEnvironment(),
        'server_name' => $_SERVER['HTTP_HOST'] ?? gethostname(),
        'logger' => 'restrogrow',
    ];
    $event['tags'] = array_merge([
        'restaurant_id' => (string)($_SESSION['restaurant_id'] ?? ''),
        'area' => $path && str_contains($path, '/superadmin/') ? 'superadmin' : ($path && str_contains($path, '/api/') ? 'api' : 'web'),
    ], $event['tags'] ?? []);
    $event['request'] = ['url' => $path ?: (PHP_SAPI === 'cli' ? 'cli' : ''), 'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI'];
    $event['contexts'] = ['runtime' => ['name' => 'php', 'version' => PHP_VERSION]];
    $GLOBALS['_rg_sentry_queue'][] = $event;

    if (empty($GLOBALS['_rg_sentry_flush_on'])) {
        $GLOBALS['_rg_sentry_flush_on'] = true;
        register_shutdown_function('rgSentryShutdown');
    }
    return $id;
}

function rgSentryFrames(array $trace): array
{
    $frames = [];
    foreach (array_reverse($trace) as $f) {
        $frames[] = array_filter([
            'filename' => isset($f['file']) ? str_replace('\\', '/', preg_replace('#^.*?[/\\\\]main[/\\\\]#', 'main/', $f['file'])) : null,
            'lineno' => $f['line'] ?? null,
            'function' => isset($f['class']) ? $f['class'] . ($f['type'] ?? '::') . $f['function'] : ($f['function'] ?? null),
        ], fn($v) => $v !== null);
    }
    return $frames;
}

function rgSentryCaptureException(Throwable $e, array $extra = [], string $level = 'error'): ?string
{
    $trace = $e->getTrace();
    array_unshift($trace, ['file' => $e->getFile(), 'line' => $e->getLine(), 'function' => '{throw}']);
    return rgSentryQueue([
        'level' => $level,
        'exception' => ['values' => [[
            'type' => get_class($e),
            'value' => mb_substr($e->getMessage(), 0, 2000),
            'stacktrace' => ['frames' => rgSentryFrames($trace)],
        ]]],
        'extra' => $extra,
    ]);
}

function rgSentryCaptureMessage(string $message, string $level = 'error', array $extra = [], array $tags = []): ?string
{
    $levels = ['critical' => 'fatal', 'warning' => 'warning', 'info' => 'info', 'debug' => 'debug'];
    return rgSentryQueue([
        'level' => $levels[$level] ?? ($level === 'fatal' ? 'fatal' : 'error'),
        'message' => ['formatted' => mb_substr($message, 0, 4000)],
        'extra' => $extra,
        'tags' => $tags,
    ]);
}

/**
 * End of request. Registered early, so it queues the real work as the very last shutdown step:
 * other shutdown handlers (error_monitor.php's fatal-error logger) run first and their events go too.
 */
function rgSentryShutdown(): void
{
    register_shutdown_function('rgSentryFinal');
}

function rgSentryFinal(): void
{
    try {
        $last = error_get_last();
        $fatal = $last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
        if ($fatal && empty($GLOBALS['_rg_sentry_queue'])) {
            rgSentryCaptureMessage('Fatal: ' . $last['message'], 'critical', ['file' => basename($last['file']) . ':' . $last['line']]);
        }
        $code = function_exists('http_response_code') ? (int)http_response_code() : 0;
        if ($code >= 500 && empty($GLOBALS['_rg_sentry_queue'])) {
            rgSentryCaptureMessage('HTTP ' . $code . ' on ' . (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'request'), 'error',
                $last ? ['last_php_error' => $last['message'] . ' (' . basename($last['file']) . ':' . $last['line'] . ')'] : [], ['http_status' => (string)$code]);
        }
        rgSentryFlush();
    } catch (Throwable $e) {
        error_log('[Sentry] ' . $e->getMessage());
    }
}

function rgSentryFlush(bool $finishRequest = true): int
{
    $queue = $GLOBALS['_rg_sentry_queue'] ?? [];
    $GLOBALS['_rg_sentry_queue'] = [];
    $dsn = rgSentryDsn();
    if (!$queue || !$dsn || !function_exists('curl_init')) return 0;
    // the reply goes now, Sentry after (PHP-FPM, or LiteSpeed on Hostinger)
    if ($finishRequest && PHP_SAPI !== 'cli') { if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request(); }
    $sent = 0;
    foreach ($queue as $event) {
        $body = json_encode(['event_id' => $event['event_id'], 'dsn' => $dsn['dsn'], 'sent_at' => gmdate('Y-m-d\TH:i:s\Z')]) . "\n"
            . json_encode(['type' => 'event']) . "\n"
            . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
        $ch = curl_init($dsn['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-sentry-envelope',
                'X-Sentry-Auth: Sentry sentry_version=7, sentry_client=restrogrow-php/1.0, sentry_key=' . $dsn['key'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
        ]);
        // Windows (local XAMPP): also trust the system's certificates; XAMPP's own bundle is old
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        $res = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status >= 200 && $status < 300) $sent++;
        else error_log('[Sentry] send failed (' . $status . '): ' . ($res === false ? curl_error($ch) : mb_substr((string)$res, 0, 200)));
        curl_close($ch);
    }
    return $sent;
}

// any request that ends in a 500 is reported, even when no handler saw an error
if (rgSentryDsn() && empty($GLOBALS['_rg_sentry_flush_on'])) {
    $GLOBALS['_rg_sentry_flush_on'] = true;
    register_shutdown_function('rgSentryShutdown');
}
