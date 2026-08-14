<?php
/**
 * Customer account session + "remember me" handling for the customer-facing
 * website. Customers are scoped per-restaurant (same convention as the
 * `customers` CRM table: unique per restaurant_id+phone), so a login only
 * counts as "active" for the restaurant it was created against even though
 * the PHP session cookie is shared across the whole multi-tenant platform.
 */

define('CUSTOMER_REMEMBER_COOKIE', 'customer_remember');
define('CUSTOMER_REMEMBER_DAYS', 30);

/**
 * Lazily create/upgrade the schema needed for customer accounts. Mirrors the
 * existing app convention (see admin/auth.php handleForgotPassword) of
 * self-healing schema via CREATE TABLE IF NOT EXISTS instead of requiring a
 * manual migration step.
 */
function ensureCustomerAuthSchema($pdo) {
    try {
        $col = $pdo->query("SHOW COLUMNS FROM customers LIKE 'password_hash'");
        if ($col->rowCount() === 0) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER email");
        }
    } catch (PDOException $e) {
        error_log('ensureCustomerAuthSchema (password_hash): ' . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS customer_password_reset_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                used BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_token (token),
                INDEX idx_customer_id (customer_id)
            )
        ");
    } catch (PDOException $e) {
        error_log('ensureCustomerAuthSchema (reset tokens): ' . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS customer_remember_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                selector VARCHAR(24) NOT NULL UNIQUE,
                validator_hash VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_selector (selector),
                INDEX idx_customer_id (customer_id)
            )
        ");
    } catch (PDOException $e) {
        error_log('ensureCustomerAuthSchema (remember tokens): ' . $e->getMessage());
    }
}

/**
 * Returns the logged-in customer row for the given restaurant, re-establishing
 * the session from the "remember me" cookie if the PHP session doesn't have
 * one but a valid remember token does. Returns null if not logged in.
 */
function getLoggedInCustomer($pdo, $restaurant_id) {
    if (!$restaurant_id) return null;

    if (!empty($_SESSION['customer_id']) && ($_SESSION['customer_restaurant_id'] ?? null) === $restaurant_id) {
        try {
            $stmt = $pdo->prepare("SELECT id, restaurant_id, customer_name, phone, email FROM customers WHERE id = ? AND restaurant_id = ? AND password_hash IS NOT NULL LIMIT 1");
            $stmt->execute([$_SESSION['customer_id'], $restaurant_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        } catch (PDOException $e) {
            error_log('getLoggedInCustomer (session lookup): ' . $e->getMessage());
        }
        // Session pointed at a customer that no longer qualifies - clear it.
        unset($_SESSION['customer_id'], $_SESSION['customer_restaurant_id']);
    }

    // No active session - try the remember-me cookie.
    if (empty($_COOKIE[CUSTOMER_REMEMBER_COOKIE])) return null;
    $parts = explode(':', $_COOKIE[CUSTOMER_REMEMBER_COOKIE], 2);
    if (count($parts) !== 2) return null;
    list($selector, $validator) = $parts;

    try {
        ensureCustomerAuthSchema($pdo);
        $stmt = $pdo->prepare("
            SELECT rt.id as token_id, rt.validator_hash, rt.expires_at, c.id, c.restaurant_id, c.customer_name, c.phone, c.email
            FROM customer_remember_tokens rt
            JOIN customers c ON c.id = rt.customer_id
            WHERE rt.selector = ? AND c.restaurant_id = ? AND c.password_hash IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([$selector, $restaurant_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || strtotime($row['expires_at']) < time() || !password_verify($validator, $row['validator_hash'])) {
            // Invalid/expired/mismatched token - clear the cookie so we stop trying.
            setcookie(CUSTOMER_REMEMBER_COOKIE, '', time() - 3600, '/');
            return null;
        }

        // Re-establish the session and rotate the token (mirrors session_regenerate_id
        // on login - a used remember token is replaced rather than reused indefinitely).
        $_SESSION['customer_id'] = $row['id'];
        $_SESSION['customer_restaurant_id'] = $row['restaurant_id'];
        session_regenerate_id(true);

        $pdo->prepare("DELETE FROM customer_remember_tokens WHERE id = ?")->execute([$row['token_id']]);
        issueCustomerRememberCookie($pdo, $row['id']);

        return [
            'id' => $row['id'],
            'restaurant_id' => $row['restaurant_id'],
            'customer_name' => $row['customer_name'],
            'phone' => $row['phone'],
            'email' => $row['email'],
        ];
    } catch (PDOException $e) {
        error_log('getLoggedInCustomer (remember lookup): ' . $e->getMessage());
        return null;
    }
}

function issueCustomerRememberCookie($pdo, $customerId) {
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . CUSTOMER_REMEMBER_DAYS . ' days'));

    $pdo->prepare("INSERT INTO customer_remember_tokens (customer_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$customerId, $selector, password_hash($validator, PASSWORD_DEFAULT), $expiresAt]);

    setcookie(
        CUSTOMER_REMEMBER_COOKIE,
        $selector . ':' . $validator,
        time() + (CUSTOMER_REMEMBER_DAYS * 86400),
        '/',
        '',
        function_exists('isSecureRequest') ? isSecureRequest() : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
        true
    );
}

/**
 * Logs a customer in: sets session vars, regenerates the session id (fixation
 * protection, same as the admin login flow), and optionally issues a
 * long-lived remember-me cookie.
 */
function startCustomerSession($pdo, $customer, $remember) {
    session_regenerate_id(true);
    $_SESSION['customer_id'] = $customer['id'];
    $_SESSION['customer_restaurant_id'] = $customer['restaurant_id'];

    if ($remember) {
        issueCustomerRememberCookie($pdo, $customer['id']);
    }
}

/**
 * Resolves whether the CURRENT browser session is actually authorized to
 * act as the customer identified by ($restaurant_id, $phone), instead of
 * trusting a bare `phone` request parameter (which any caller can supply
 * for any customer — the bug this closes). Two ways to qualify:
 *
 *   1. A real logged-in account (getLoggedInCustomer()) whose own phone
 *      matches — a signed-up customer can only ever act as themselves.
 *   2. A "guest" session that just proved ownership of this phone number by
 *      completing a real order through it in this same browser session
 *      (see process_website_order.php, which sets $_SESSION['ordering_customer_id']
 *      right after resolving the customer row for a new order). This keeps
 *      loyalty/review features working for guest checkout — which this app
 *      supports and relies on — without requiring a full account, while
 *      still requiring actual proof of ownership rather than a guessable
 *      10-digit number.
 *
 * Returns the customer row (id, restaurant_id, customer_name, phone, email)
 * if authorized, or null otherwise.
 */
function getAuthorizedCustomer($pdo, $restaurant_id, $phone) {
    if (!$restaurant_id || !$phone) return null;

    $logged = getLoggedInCustomer($pdo, $restaurant_id);
    if ($logged && $logged['phone'] === $phone) {
        return $logged;
    }

    if (!empty($_SESSION['ordering_customer_id']) && ($_SESSION['ordering_customer_restaurant_id'] ?? null) === $restaurant_id) {
        try {
            $stmt = $pdo->prepare("SELECT id, restaurant_id, customer_name, phone, email FROM customers WHERE id = ? AND restaurant_id = ? AND phone = ? LIMIT 1");
            $stmt->execute([$_SESSION['ordering_customer_id'], $restaurant_id, $phone]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        } catch (PDOException $e) {
            error_log('getAuthorizedCustomer (guest session lookup): ' . $e->getMessage());
        }
    }

    return null;
}

function clearCustomerSession($pdo) {
    if (!empty($_SESSION['customer_id'])) {
        try {
            $pdo->prepare("DELETE FROM customer_remember_tokens WHERE customer_id = ?")->execute([$_SESSION['customer_id']]);
        } catch (PDOException $e) {
            error_log('clearCustomerSession: ' . $e->getMessage());
        }
    }
    unset($_SESSION['customer_id'], $_SESSION['customer_restaurant_id']);
    if (isset($_COOKIE[CUSTOMER_REMEMBER_COOKIE])) {
        setcookie(CUSTOMER_REMEMBER_COOKIE, '', time() - 3600, '/');
    }
}
