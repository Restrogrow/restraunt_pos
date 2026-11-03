<?php
/**
 * The WhatsApp code a new customer is asked for on the restaurant's website.
 *
 * Creating an account at /{slug}/login sends a 6-digit code to the phone (website/otp.php,
 * purpose 'customer_signup') and customer_auth.php's handleCustomerSignup() refuses to insert
 * without a verified row under that scope. Some restaurants don't want that step: guests stop at
 * the code and never finish the account, and the restaurant loses the customer. So the owner
 * switches it off per restaurant in Settings › Ordering (users.customer_otp_enabled, on by default).
 *
 * Off means the account is made from the form alone. The phone is still kept and still normalised
 * to the 10-digit form (order_abuse_guard.php), so points, wallet and order history still land on
 * it — but nothing proves the number belongs to whoever typed it, so someone who knows a guest's
 * number can claim that guest's past orders and points. A phone that already has a *password*
 * account is still safe either way: handleCustomerSignup() sends those to the login page.
 *
 * Login is not affected: the new-device code is already off everywhere (device_trust_helpers.php),
 * and the WhatsApp password reset keeps its code — that code is the only proof the person owns the
 * account, and it is sent to the number on file rather than asked of a stranger.
 */

if (defined('CUSTOMER_OTP_LOADED')) return;
define('CUSTOMER_OTP_LOADED', true);

/** Adds the on/off column on first use (older databases don't have it). */
function customerOtpEnsureSchema(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $has = $conn->query("SHOW COLUMNS FROM users LIKE 'customer_otp_enabled'")->fetch();
        if (!$has) $conn->exec("ALTER TABLE users ADD COLUMN customer_otp_enabled TINYINT(1) NOT NULL DEFAULT 1");
    } catch (Throwable $e) {
        error_log('customerOtpEnsureSchema: ' . $e->getMessage());
    }
}

/** Does this restaurant ask a new customer for the WhatsApp code? On unless the owner switched it off. */
function customerOtpRequired(PDO $conn, string $rid): bool
{
    customerOtpEnsureSchema($conn);
    try {
        $st = $conn->prepare('SELECT COALESCE(customer_otp_enabled, 1) FROM users WHERE restaurant_id = ? LIMIT 1');
        $st->execute([$rid]);
        $v = $st->fetchColumn();
        return $v === false ? true : (bool)(int)$v;
    } catch (Throwable $e) {
        error_log('customerOtpRequired: ' . $e->getMessage());
        return true;   // a database hiccup must not drop the check
    }
}
