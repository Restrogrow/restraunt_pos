<?php
/**
 * Email Configuration
 * Configure SMTP settings here
 */

// Load environment variables if not already loaded
if (!defined('ENV_LOADER_LOADED') && file_exists(__DIR__ . '/env_loader.php')) {
    require_once __DIR__ . '/env_loader.php';
}

// Development Mode Configuration
// When DEVELOPMENT_MODE is true, emails will only be sent on localhost
// On production servers, emails will be logged instead of sent
define('DEVELOPMENT_MODE', false); // Set to false to send emails on production

// Detect if we're on localhost
function isLocalhost() {
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    return (
        $host === 'localhost' ||
        $host === '127.0.0.1' ||
        strpos($host, 'localhost:') === 0 ||
        strpos($host, '127.0.0.1:') === 0 ||
        strpos($host, '.local') !== false ||
        strpos($host, '192.168.') === 0
    );
}

// SMTP Configuration - Load from environment variables
if (!defined('SMTP_ENABLED')) {
    define('SMTP_ENABLED', env('SMTP_ENABLED', 'true') === 'true' || env('SMTP_ENABLED', 'true') === true);
}
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', env('SMTP_HOST', 'smtp.gmail.com'));
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', (int)env('SMTP_PORT', 587));
}
if (!defined('SMTP_SECURE')) {
    define('SMTP_SECURE', env('SMTP_SECURE', 'tls')); // 'tls' or 'ssl'
}
if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', env('SMTP_USERNAME', 'restrogrow@gmail.com'));
}
if (!defined('SMTP_PASSWORD')) {
    // No hardcoded fallback: a committed default here would mean rotating the
    // real password in .env doesn't actually revoke the old one. If SMTP_PASSWORD
    // isn't configured, sendEmail() below already falls back to PHP's mail().
    define('SMTP_PASSWORD', env('SMTP_PASSWORD', ''));
}
if (!defined('SMTP_FROM_EMAIL')) {
    define('SMTP_FROM_EMAIL', env('SMTP_FROM_EMAIL', 'restrogrow@gmail.com'));
}
if (!defined('SMTP_FROM_NAME')) {
    // the old default name: emails now say RestroGrow unless .env picks something else
    $rgFromName = trim((string)env('SMTP_FROM_NAME', 'RestroGrow'), " \r\n\"'");
    define('SMTP_FROM_NAME', ($rgFromName === '' || $rgFromName === 'Restaurant POS System') ? 'RestroGrow' : $rgFromName);
}
/** The sender name: env_loader may already have made SMTP_FROM_NAME from .env, so the old name is replaced here */
function rgMailFromName(): string
{
    $n = trim((string)SMTP_FROM_NAME, " \r\n\"'");
    return ($n === '' || $n === 'Restaurant POS System') ? 'RestroGrow' : $n;
}

/**
 * Which address an automatic email is sent from. Google Workspace: every address must be added to the
 * login account (SMTP_USERNAME) in Gmail › Settings › Accounts › "Send mail as", or Gmail sends it
 * from the login address instead. Unset ones fall back to SMTP_FROM_EMAIL.
 *   billing   MAIL_FROM_BILLING   invoices, renewals, payment links
 *   alerts    MAIL_FROM_ALERTS    "new order" emails to restaurants, system alerts
 *   orders    MAIL_FROM_ORDERS    order confirmations to customers
 *   accounts  MAIL_FROM_ACCOUNTS  password resets and login emails
 *   support   MAIL_FROM_SUPPORT   support replies
 *   sales     MAIL_FROM_SALES     sales and offers
 */
function rgMailSenders(): array
{
    return ['billing' => 'MAIL_FROM_BILLING', 'alerts' => 'MAIL_FROM_ALERTS', 'orders' => 'MAIL_FROM_ORDERS',
        'accounts' => 'MAIL_FROM_ACCOUNTS', 'support' => 'MAIL_FROM_SUPPORT', 'sales' => 'MAIL_FROM_SALES'];
}
function rgMailFrom(?string $purpose = null): string
{
    $keys = rgMailSenders();
    if ($purpose && isset($keys[$purpose])) {
        $v = trim((string)env($keys[$purpose], ''));
        if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) return $v;
    }
    return SMTP_FROM_EMAIL;
}
/**
 * Per-email options set by config/mail_engine.php around one send (rgMailWith()):
 *   from_name  e.g. "Spice Garden via RestroGrow"   reply_to  where replies go
 *   text       plain-text version                    list_unsubscribe  URL (marketing emails)
 */
$GLOBALS['RG_MAIL_OPTS'] = [];
function rgMailWith(array $opts, callable $send)
{
    $GLOBALS['RG_MAIL_OPTS'] = $opts;
    try { return $send(); } finally { $GLOBALS['RG_MAIL_OPTS'] = []; }
}
function rgMailEncodeHeader(string $v): string
{
    return preg_match('/[^\x20-\x7E]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
}
/** "Name <address>" with the name encoded if it is not plain ASCII */
function rgMailHeaderFrom(string $email): string
{
    $name = trim((string)($GLOBALS['RG_MAIL_OPTS']['from_name'] ?? '')) ?: rgMailFromName();
    if (preg_match('/[^\x20-\x7E]/', $name)) $name = '=?UTF-8?B?' . base64_encode($name) . '?=';
    else $name = '"' . str_replace('"', '', $name) . '"';
    return $name . ' <' . $email . '>';
}

/**
 * Send email using SMTP
 */
function sendSMTPEmail($to, $subject, $message, $headers = '', $purpose = null) {
    $fromEmail = rgMailFrom($purpose);
    if (!SMTP_ENABLED) {
        // Fallback to PHP mail() function
        return @mail($to, $subject, $message, $headers);
    }
    
    $smtp = null;
    $errorMsg = '';
    
    try {
        // Create socket connection with SSL/TLS support
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);
        
        // Connect to SMTP server
        $smtp = stream_socket_client(
            SMTP_HOST . ':' . SMTP_PORT,
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );
        
        if (!$smtp) {
            $errorMsg = "SMTP Connection failed: $errstr ($errno)";
            error_log($errorMsg);
            return ['success' => false, 'error' => $errorMsg];
        }

        // Bound every subsequent read/write on this socket. Without this, a
        // server that accepts the TCP connection but then stalls (firewall
        // black-holing the port, mail server hung) leaves fgets() blocking
        // indefinitely with no way for the caller's error handling to run.
        stream_set_timeout($smtp, 15);

        // Read server greeting
        $response = fgets($smtp, 515);
        if (substr($response, 0, 3) != '220') {
            $errorMsg = "SMTP Error: $response";
            error_log($errorMsg);
            fclose($smtp);
            return ['success' => false, 'error' => $errorMsg];
        }
        
        // Send EHLO
        fputs($smtp, "EHLO " . SMTP_HOST . "\r\n");
        $response = '';
        while ($line = fgets($smtp, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) == ' ') break;
        }
        
        // Start TLS if required
        if (SMTP_SECURE == 'tls') {
            fputs($smtp, "STARTTLS\r\n");
            $response = fgets($smtp, 515);
            if (substr($response, 0, 3) != '220') {
                $errorMsg = "STARTTLS failed: $response";
                error_log($errorMsg);
                fclose($smtp);
                return ['success' => false, 'error' => $errorMsg];
            }
            
            // Enable crypto
            if (!stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $errorMsg = "TLS encryption failed";
                error_log($errorMsg);
                fclose($smtp);
                return ['success' => false, 'error' => $errorMsg];
            }
            
            // Send EHLO again after TLS
            fputs($smtp, "EHLO " . SMTP_HOST . "\r\n");
            $response = '';
            while ($line = fgets($smtp, 515)) {
                $response .= $line;
                if (substr($line, 3, 1) == ' ') break;
            }
        }
        
        // Authenticate
        fputs($smtp, "AUTH LOGIN\r\n");
        $response = fgets($smtp, 515);
        if (substr($response, 0, 3) != '334') {
            $errorMsg = "AUTH LOGIN failed: $response";
            error_log($errorMsg);
            fclose($smtp);
            return ['success' => false, 'error' => $errorMsg];
        }
        
        fputs($smtp, base64_encode(SMTP_USERNAME) . "\r\n");
        $response = fgets($smtp, 515);
        if (substr($response, 0, 3) != '334') {
            $errorMsg = "Username authentication failed: $response";
            error_log($errorMsg);
            fclose($smtp);
            return ['success' => false, 'error' => $errorMsg];
        }
        
        fputs($smtp, base64_encode(SMTP_PASSWORD) . "\r\n");
        $response = fgets($smtp, 515);
        if (substr($response, 0, 3) != '235') {
            $errorMsg = "SMTP Authentication failed: $response";
            error_log($errorMsg);
            fclose($smtp);
            return ['success' => false, 'error' => $errorMsg];
        }
        
        // Send email
        fputs($smtp, "MAIL FROM: <" . $fromEmail . ">\r\n");
        $response = fgets($smtp, 515);
        if (substr($response, 0, 3) != '250') {
            $errorMsg = "MAIL FROM failed: $response";
            error_log($errorMsg);
            fclose($smtp);
            return ['success' => false, 'error' => $errorMsg];
        }
        
        fputs($smtp, "RCPT TO: <" . $to . ">\r\n");
        $response = fgets($smtp, 515);
        if (substr($response, 0, 3) != '250') {
            $errorMsg = "RCPT TO failed: $response";
            error_log($errorMsg);
            fclose($smtp);
            return ['success' => false, 'error' => $errorMsg];
        }
        
        fputs($smtp, "DATA\r\n");
        $response = fgets($smtp, 515);
        if (substr($response, 0, 3) != '354') {
            $errorMsg = "DATA command failed: $response";
            error_log($errorMsg);
            fclose($smtp);
            return ['success' => false, 'error' => $errorMsg];
        }
        
        // Headers: encoded subject, Date and Message-ID (spam filters expect them), reply-to
        $o = $GLOBALS['RG_MAIL_OPTS'] ?? [];
        $replyTo = trim((string)($o['reply_to'] ?? '')) ?: $fromEmail;
        $domain = substr(strrchr($fromEmail, '@') ?: '@restrogrow.com', 1);
        $emailHeaders = "Date: " . date('r') . "\r\n";
        $emailHeaders .= "Message-ID: <" . bin2hex(random_bytes(12)) . "@" . $domain . ">\r\n";
        $emailHeaders .= "From: " . rgMailHeaderFrom($fromEmail) . "\r\n";
        if (stripos((string)$headers, 'Reply-To:') === false) $emailHeaders .= "Reply-To: <" . $replyTo . ">\r\n";
        $emailHeaders .= "To: <" . $to . ">\r\n";
        $emailHeaders .= "Subject: " . rgMailEncodeHeader((string)$subject) . "\r\n";
        if (!empty($o['list_unsubscribe'])) $emailHeaders .= "List-Unsubscribe: <" . $o['list_unsubscribe'] . ">\r\n";
        $emailHeaders .= "MIME-Version: 1.0\r\n";
        if ($headers) {
            $emailHeaders .= rtrim($headers) . "\r\n";
        }
        // Body: HTML + plain text, base64 (no line-length limit, and a line starting with "." can't end the message early)
        $text = trim((string)($o['text'] ?? '')) ?: trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/tr|\/h[1-6])[^>]*>/i', "\n", (string)$message)), ENT_QUOTES, 'UTF-8'));
        $boundary = 'rg' . bin2hex(random_bytes(8));
        $emailHeaders .= "Content-Type: multipart/alternative; boundary=\"" . $boundary . "\"\r\n";
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode((string)$message))
            . "--$boundary--";

        // Send email data
        fputs($smtp, $emailHeaders . "\r\n" . $body . "\r\n.\r\n");
        $response = fgets($smtp, 515);
        
        if (substr($response, 0, 3) != '250') {
            $errorMsg = "SMTP Send failed: $response";
            error_log($errorMsg);
            fclose($smtp);
            return ['success' => false, 'error' => $errorMsg];
        }
        
        fputs($smtp, "QUIT\r\n");
        fclose($smtp);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        $errorMsg = "SMTP Exception: " . $e->getMessage();
        error_log($errorMsg);
        if ($smtp) {
            fclose($smtp);
        }
        return ['success' => false, 'error' => $errorMsg];
    }
}

/**
 * Send email (wrapper function that tries SMTP first, then falls back to mail())
 * Returns array with 'success' and optional 'error' keys
 */
function sendEmail($to, $subject, $message, $headers = '', $purpose = null) {
    $fromEmail = rgMailFrom($purpose);
    // Development Mode: Only send emails on localhost
    if (DEVELOPMENT_MODE && !isLocalhost()) {
        // Log email instead of sending on production
        $logMessage = "=== EMAIL NOT SENT (Development Mode) ===\n";
        $logMessage .= "To: $to\n";
        $logMessage .= "Subject: $subject\n";
        $logMessage .= "From: " . rgMailFromName() . " <" . $fromEmail . ">\n";
        $logMessage .= "Time: " . date('Y-m-d H:i:s') . "\n";
        $logMessage .= "Message Preview: " . substr(strip_tags($message), 0, 200) . "...\n";
        $logMessage .= "========================================\n\n";
        
        // Log to error log
        error_log($logMessage);
        
        // Also log to a dedicated email log file
        $logFile = __DIR__ . '/../logs/email_dev_mode.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        @file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Return success so the application continues normally
        return ['success' => true, 'dev_mode' => true, 'message' => 'Email logged (Development Mode - not sent on production)'];
    }
    
    // Try SMTP first if enabled
    if (SMTP_ENABLED && !empty(SMTP_PASSWORD)) {
        $result = sendSMTPEmail($to, $subject, $message, $headers, $purpose);
        if (is_array($result)) {
            return $result; // Return the result array
        }
        // Legacy: if function returns boolean, convert to array
        if ($result === true) {
            return ['success' => true];
        }
        // If SMTP fails, fall back to mail()
        error_log("SMTP failed, falling back to mail() function");
    }
    
    // Fallback to PHP mail() function
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $defaultHeaders = "MIME-Version: 1.0\r\n";
    $defaultHeaders .= "Content-type: text/html; charset=UTF-8\r\n";
    $defaultHeaders .= "From: " . rgMailHeaderFrom($fromEmail) . "\r\n";
    if ($headers) {
        $defaultHeaders .= $headers;
    }
    
    $mailResult = @mail($to, $subject, $message, $defaultHeaders);
    
    if ($mailResult) {
        return ['success' => true];
    } else {
        $lastError = error_get_last();
        $errorMsg = $lastError ? ($lastError['message'] ?? 'Mail function returned false') : 'Mail function returned false';
        return ['success' => false, 'error' => $errorMsg];
    }
}
?>

