<?php
/**
 * "Continue as guest" on the restaurant's website login screen.
 *
 * /{slug}/login offers three ways in: log in, create an account, or continue as a guest. The guest
 * way asks for nothing — cart.php and profile.php let the browser session past their gate on
 * sessionStorage.guestSessionActive alone, and the checkout form collects the name and phone when
 * the order is placed. Some restaurants would rather every website order came from an account, so a
 * customer's order history, points and wallet stay together on one phone number instead of
 * scattering across guest orders. So the owner switches the guest way off per restaurant in
 * Settings › Ordering (users.guest_checkout_enabled, on by default).
 *
 * Off hides the button and closes the same gate on the server (api/process_website_order.php), so a
 * page left open from before the switch, or a hand-set sessionStorage flag, gains nothing.
 *
 * Three ways in stay open either way, because none of them has a login screen to show and closing
 * them would turn away orders the restaurant wants:
 *   - a guest at a table who scanned its QR code — a Dine-in order with a table, which cart.php's
 *     rgQrDineIn() deliberately takes straight to the cart with no login page,
 *   - a hotel guest ordering from their room's QR code (room_qr),
 *   - an order the restaurant's own WhatsApp bot sends (config/wa_order_engine.php).
 * Someone in the restaurant is already being served in all three; an account would only be in the way.
 */

if (defined('GUEST_CHECKOUT_LOADED')) return;
define('GUEST_CHECKOUT_LOADED', true);

/** Adds the on/off column on first use (older databases don't have it). */
function guestCheckoutEnsureSchema(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $has = $conn->query("SHOW COLUMNS FROM users LIKE 'guest_checkout_enabled'")->fetch();
        if (!$has) $conn->exec("ALTER TABLE users ADD COLUMN guest_checkout_enabled TINYINT(1) NOT NULL DEFAULT 1");
    } catch (Throwable $e) {
        error_log('guestCheckoutEnsureSchema: ' . $e->getMessage());
    }
}

/** Can a visitor order from this restaurant's website without an account? On unless the owner switched it off. */
function guestCheckoutAllowed(PDO $conn, string $rid): bool
{
    guestCheckoutEnsureSchema($conn);
    try {
        $st = $conn->prepare('SELECT COALESCE(guest_checkout_enabled, 1) FROM users WHERE restaurant_id = ? LIMIT 1');
        $st->execute([$rid]);
        $v = $st->fetchColumn();
        return $v === false ? true : (bool)(int)$v;
    } catch (Throwable $e) {
        error_log('guestCheckoutAllowed: ' . $e->getMessage());
        return true;   // a database hiccup must not lock customers out of ordering
    }
}
