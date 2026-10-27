<?php
/**
 * Smart QR Menu kit orders from the product page restrogrow.com/qr-menu-ordering/ (RestroGrow sells a package:
 * the menu set up + printed QR standees). Paid to RestroGrow's own PhonePe account, never a restaurant's.
 *
 *   qr_kit_orders   one booking: package, standee design and count, add-ons, total, how much is paid now
 *                   (₹500 advance, or everything), the buyer's details and delivery address, payment and work status
 *
 * Prices come only from qkPrice() (the page's numbers are shown, never trusted). The payment id starts with QRK_;
 * config/payment_settle.php sends it to qkSettle(), so the webhook, the return page and the safety net all settle it
 * once. When it is paid the team gets an email (mail type team_lead). Super Admin › QR kit orders (superadmin/qr_kits.php)
 * lists them and moves them along: Paid → Menu building → Delivered (balance collected).
 */
if (defined('QR_KIT_LOADED')) return;
define('QR_KIT_LOADED', true);

require_once __DIR__ . '/phonepe_verify.php';

const QK_ADVANCE = 500;
const QK_STANDEE = 99;
const QK_ADD_DELIVERY = 999;     // takeaway & delivery orders
const QK_ADD_PAYMENTS = 1999;    // online payment gateway
const QK_PACKAGES = [
    'basic' => ['name' => 'QR Menu', 'price' => 1999, 'stands' => 0],
    'smart' => ['name' => 'QR Ordering', 'price' => 4999, 'stands' => 10],
];
/** Standee designs in the page's order (qr-menu-ordering/index.html const D) */
const QK_DESIGNS = ['Indian Feast', 'Burger Pop', 'Trattoria Red', 'Fresh Botanical', 'Cafe Lilac', 'Orange Pop', 'Berry Cafe', 'Midnight Bistro', 'Aqua Fresh', 'Royal Feast'];
/** Work status after payment */
const QK_STAGES = ['paid' => 'Paid, to do', 'building' => 'Menu being built', 'shipped' => 'Standees sent', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];

function qkEnsure(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->exec("CREATE TABLE IF NOT EXISTS qr_kit_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id VARCHAR(63) NOT NULL,
        package VARCHAR(10) NOT NULL,
        design VARCHAR(40) NOT NULL,
        standees INT NOT NULL DEFAULT 0,
        extra_standees INT NOT NULL DEFAULT 0,
        add_delivery TINYINT(1) NOT NULL DEFAULT 0,
        add_payments TINYINT(1) NOT NULL DEFAULT 0,
        total DECIMAL(10,2) NOT NULL,
        pay_now DECIMAL(10,2) NOT NULL,
        pay_on_delivery DECIMAL(10,2) NOT NULL DEFAULT 0,
        payment_mode VARCHAR(12) NOT NULL,
        payment_status VARCHAR(10) NOT NULL DEFAULT 'pending',
        stage VARCHAR(12) NOT NULL DEFAULT 'paid',
        balance_paid TINYINT(1) NOT NULL DEFAULT 0,
        name VARCHAR(120) NOT NULL,
        phone VARCHAR(15) NOT NULL,
        restaurant VARCHAR(150) NOT NULL,
        address VARCHAR(400) NOT NULL,
        city VARCHAR(80) NOT NULL,
        state VARCHAR(80) NOT NULL,
        pincode VARCHAR(6) NOT NULL,
        team_note VARCHAR(500) NULL,
        paid_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_qk_txn (transaction_id),
        INDEX idx_qk_status (payment_status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // people who may open the QR kit orders dashboard (main/qrkit/), made in Super Admin › QR kit orders
    $conn->exec("CREATE TABLE IF NOT EXISTS qr_kit_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        username VARCHAR(40) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        last_login DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_qku_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Moves a paid order along (Super Admin and the QR kit dashboard). Returns false when it isn't a paid order. */
function qkUpdateOrder(PDO $conn, int $id, string $stage, bool $balancePaid, string $note): bool
{
    if (!isset(QK_STAGES[$stage])) throw new InvalidArgumentException('Pick a status.');
    $u = $conn->prepare("UPDATE qr_kit_orders SET stage = ?, balance_paid = ?, team_note = ? WHERE id = ? AND payment_status = 'paid'");
    $u->execute([$stage, $balancePaid ? 1 : 0, mb_substr(trim(strip_tags($note)), 0, 500) ?: null, $id]);
    $ok = $conn->prepare("SELECT COUNT(*) FROM qr_kit_orders WHERE id = ? AND payment_status = 'paid'");
    $ok->execute([$id]);
    return (int)$ok->fetchColumn() === 1;
}

// ───────────── logins for the QR kit orders dashboard (main/qrkit/) ─────────────

/** A new login. The username is lowercase letters, digits, dot, dash or underscore; the password at least 8 characters. */
function qkUserCreate(PDO $conn, string $name, string $username, string $password): void
{
    qkEnsure($conn);
    $name = mb_substr(trim(strip_tags($name)), 0, 80);
    $username = strtolower(trim($username));
    if ($name === '') throw new InvalidArgumentException('Give the person a name.');
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) throw new InvalidArgumentException('Username: 3 to 40 letters, digits, dot, dash or underscore.');
    if (strlen($password) < 8) throw new InvalidArgumentException('The password needs at least 8 characters.');
    $have = $conn->prepare('SELECT COUNT(*) FROM qr_kit_users WHERE username = ?');
    $have->execute([$username]);
    if ((int)$have->fetchColumn()) throw new InvalidArgumentException('That username is taken.');
    $conn->prepare('INSERT INTO qr_kit_users (name, username, password_hash) VALUES (?, ?, ?)')->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
}

/** The login's row when the username and password match and it is switched on, else null. */
function qkUserCheck(PDO $conn, string $username, string $password): ?array
{
    qkEnsure($conn);
    $st = $conn->prepare('SELECT * FROM qr_kit_users WHERE username = ? LIMIT 1');
    $st->execute([strtolower(trim($username))]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || !(int)$u['active'] || !password_verify($password, $u['password_hash'])) return null;
    $conn->prepare('UPDATE qr_kit_users SET last_login = NOW() WHERE id = ?')->execute([$u['id']]);
    return $u;
}

/** The signed-in dashboard user (still switched on), or null. Session keys: qrkit_user_id, qrkit_at. */
function qkSessionUser(PDO $conn): ?array
{
    $id = (int)($_SESSION['qrkit_user_id'] ?? 0);
    if (!$id || time() - (int)($_SESSION['qrkit_at'] ?? 0) > 7 * 86400) return null;   // signed in for a week at most
    qkEnsure($conn);
    $st = $conn->prepare('SELECT id, name, username FROM qr_kit_users WHERE id = ? AND active = 1');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * The price of what the buyer picked, worked out here: ['package', 'design', 'standees', 'extra', 'delivery', 'payments',
 * 'total', 'pay_now', 'pay_on_delivery', 'mode']. Throws InvalidArgumentException for anything that isn't on the page.
 */
function qkPrice(array $in): array
{
    $pk = (string)($in['package'] ?? '');
    if (!isset(QK_PACKAGES[$pk])) throw new InvalidArgumentException('Please choose a package.');
    $di = (int)($in['design'] ?? -1);
    if (!isset(QK_DESIGNS[$di])) throw new InvalidArgumentException('Please choose a standee design.');
    $extra = (int)($in['extra'] ?? 0);
    if ($extra < 0 || $extra > 200) throw new InvalidArgumentException('Please check the number of standees.');
    $p = QK_PACKAGES[$pk];
    $del = !empty($in['delivery']);
    $pay = !empty($in['payments']);
    $total = $p['price'] + $extra * QK_STANDEE + ($del ? QK_ADD_DELIVERY : 0) + ($pay ? QK_ADD_PAYMENTS : 0);
    $full = !empty($in['full']);
    $now = $full ? $total : min(QK_ADVANCE, $total);
    return ['package' => $pk, 'name' => $p['name'], 'design' => QK_DESIGNS[$di], 'standees' => $p['stands'] + $extra, 'extra' => $extra,
        'delivery' => $del, 'payments' => $pay, 'total' => (float)$total, 'pay_now' => (float)$now, 'pay_on_delivery' => (float)($total - $now),
        'mode' => $full ? 'full_online' : 'advance_cod'];
}

/** The buyer's details, cleaned and checked (Indian mobile and pincode, as the page asks). */
function qkBuyer(array $in): array
{
    $c = fn($k, $n) => mb_substr(trim(strip_tags((string)($in[$k] ?? ''))), 0, $n);
    $b = ['name' => $c('name', 120), 'phone' => preg_replace('/\D/', '', (string)($in['phone'] ?? '')), 'restaurant' => $c('restaurant', 150),
        'address' => $c('address', 400), 'city' => $c('city', 80), 'state' => $c('state', 80), 'pincode' => preg_replace('/\D/', '', (string)($in['pincode'] ?? ''))];
    if (strlen($b['phone']) === 12 && strpos($b['phone'], '91') === 0) $b['phone'] = substr($b['phone'], 2);
    if ($b['name'] === '') throw new InvalidArgumentException('Please enter your name.');
    if (!preg_match('/^[6-9]\d{9}$/', $b['phone'])) throw new InvalidArgumentException('Please enter a 10-digit mobile number.');
    if ($b['restaurant'] === '') throw new InvalidArgumentException('Please enter your restaurant\'s name.');
    if ($b['address'] === '' || $b['city'] === '' || $b['state'] === '') throw new InvalidArgumentException('Please fill in the delivery address.');
    if (!preg_match('/^\d{6}$/', $b['pincode'])) throw new InvalidArgumentException('Please enter a 6-digit pincode.');
    return $b;
}

/** Saves the booking (payment pending) and returns its id. */
function qkCreate(PDO $conn, string $txn, array $price, array $buyer): int
{
    qkEnsure($conn);
    $conn->prepare('INSERT INTO qr_kit_orders (transaction_id, package, design, standees, extra_standees, add_delivery, add_payments, total, pay_now, pay_on_delivery, payment_mode,
            name, phone, restaurant, address, city, state, pincode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
         ->execute([$txn, $price['package'], $price['design'], $price['standees'], $price['extra'], $price['delivery'] ? 1 : 0, $price['payments'] ? 1 : 0,
             $price['total'], $price['pay_now'], $price['pay_on_delivery'], $price['mode'],
             $buyer['name'], $buyer['phone'], $buyer['restaurant'], $buyer['address'], $buyer['city'], $buyer['state'], $buyer['pincode']]);
    return (int)$conn->lastInsertId();
}

/** Marks it paid once, and tells the team. Returns true the first time. */
function qkMarkPaid(PDO $conn, int $id): bool
{
    $u = $conn->prepare("UPDATE qr_kit_orders SET payment_status = 'paid', paid_at = NOW() WHERE id = ? AND payment_status <> 'paid'");
    $u->execute([$id]);
    if ($u->rowCount() === 0) return false;
    try {
        require_once __DIR__ . '/mail_engine.php';
        $st = $conn->prepare('SELECT * FROM qr_kit_orders WHERE id = ?');
        $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        $inr = fn($n) => '₹' . number_format((float)$n, 0, '.', ',');
        mailNewLead($conn, 'QR menu page (PAID ' . $inr($o['pay_now']) . ')', [
            'name' => $o['name'], 'restaurant' => $o['restaurant'], 'phone' => $o['phone'],
            'message' => QK_PACKAGES[$o['package']]['name'] . ' · ' . $o['standees'] . ' standees · ' . $o['design']
                . ($o['add_delivery'] ? ' · + takeaway & delivery' : '') . ($o['add_payments'] ? ' · + online payments' : '')
                . "\nTotal " . $inr($o['total']) . ', paid ' . $inr($o['pay_now']) . ', on delivery ' . $inr($o['pay_on_delivery'])
                . "\n" . $o['address'] . ', ' . $o['city'] . ', ' . $o['state'] . ' - ' . $o['pincode']
                . "\nSuper Admin › QR kit orders. Menu to go live within 24 hours.",
        ]);
    } catch (Throwable $e) {
        error_log('qkMarkPaid mail: ' . $e->getMessage());
    }
    return true;
}

/** config/payment_settle.php ppSettle() for QRK_… ids: PhonePe's own answer on RestroGrow's keys, amount checked. */
function qkSettle(PDO $conn, string $txn): string
{
    qkEnsure($conn);
    $st = $conn->prepare('SELECT id, pay_now, payment_status, created_at FROM qr_kit_orders WHERE transaction_id = ? LIMIT 1');
    $st->execute([$txn]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) return 'not_found';
    if ($o['payment_status'] === 'paid') return 'already';
    $out = ppOutcomeOf(phonepeVerifyOrderState($conn, null, $txn, (int)round((float)$o['pay_now'] * 100), true), ppAgeMin($o['created_at']));
    if ($out === 'paid') {
        qkMarkPaid($conn, (int)$o['id']);
    } elseif ($out === 'failed' || $out === 'mismatch') {
        $conn->prepare("UPDATE qr_kit_orders SET payment_status = 'failed' WHERE id = ? AND payment_status = 'pending'")->execute([$o['id']]);
    }
    return $out;
}
