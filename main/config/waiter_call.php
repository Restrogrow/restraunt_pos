<?php
/**
 * "Call waiter" from the customer website (website/call_waiter.php → api/create_waiter_request.php).
 * Calls show up in Waiter Requests, the waiter dashboard and the Partner app.
 *
 * The owner switches it on or off in Waiter Requests (users.call_waiter_enabled, on by default).
 * It only shows when the restaurant takes dine-in orders and has tables.
 */

const WAITER_CALL_REASONS = [
    'waiter' => ['General', 'Calling a waiter'],
    'bill'   => ['Bill', 'Please bring the bill'],
    'water'  => ['Water', 'Water, please'],
    'extras' => ['Cutlery', 'Cutlery or napkins, please'],
    'order'  => ['Order', 'Ready to order'],
];

/** Adds the on/off column on first use (older databases don't have it). */
function waiterCallEnsureSchema(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $has = $conn->query("SHOW COLUMNS FROM users LIKE 'call_waiter_enabled'")->fetch();
        if (!$has) $conn->exec("ALTER TABLE users ADD COLUMN call_waiter_enabled TINYINT(1) NOT NULL DEFAULT 1");
    } catch (Throwable $e) {
        error_log('waiterCallEnsureSchema: ' . $e->getMessage());
    }
}

/** Is "Call waiter" on for this restaurant (switched on, dine-in on, and it has tables)? */
function waiterCallAvailable(PDO $conn, string $rid): bool
{
    waiterCallEnsureSchema($conn);
    $st = $conn->prepare('SELECT COALESCE(call_waiter_enabled, 1) AS on_, COALESCE(enable_dinein, 1) AS dinein FROM users WHERE restaurant_id = ? LIMIT 1');
    $st->execute([$rid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || !(int)$r['on_'] || !(int)$r['dinein']) return false;
    $t = $conn->prepare('SELECT 1 FROM tables WHERE restaurant_id = ? LIMIT 1');
    $t->execute([$rid]);
    return (bool)$t->fetchColumn();
}
