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
    define('SMTP_FROM_NAME', env('SMTP_FROM_NAME', 'Restaurant POS System'));
}

/**
 * Send email using SMTP
 */
function sendSMTPEmail($to, $subject, $message, $headers = '') {
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
        fputs($smtp, "MAIL FROM: <" . SMTP_FROM_EMAIL . ">\r\n");
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
        
        // Prepare email headers
        $emailHeaders = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
        $emailHeaders .= "To: <" . $to . ">\r\n";
        $emailHeaders .= "Subject: " . $subject . "\r\n";
        $emailHeaders .= "MIME-Version: 1.0\r\n";
        $emailHeaders .= "Content-type: text/html; charset=UTF-8\r\n";
        if ($headers) {
            $emailHeaders .= $headers . "\r\n";
        }
        
        // Send email data
        fputs($smtp, $emailHeaders . "\r\n" . $message . "\r\n.\r\n");
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
function sendEmail($to, $subject, $message, $headers = '') {
    // Development Mode: Only send emails on localhost
    if (DEVELOPMENT_MODE && !isLocalhost()) {
        // Log email instead of sending on production
        $logMessage = "=== EMAIL NOT SENT (Development Mode) ===\n";
        $logMessage .= "To: $to\n";
        $logMessage .= "Subject: $subject\n";
        $logMessage .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\n";
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
        $result = sendSMTPEmail($to, $subject, $message, $headers);
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
    $defaultHeaders .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
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

