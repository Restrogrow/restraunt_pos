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

/**
 * Adds the columns this feature needs on first use (older databases don't have them).
 *
 * users.call_waiter_enabled   the owner's on/off switch
 * waiter_requests.taken_by    who said they would go, so two waiters don't walk to the same table
 *            taken_by_name    their name as it was then, so the board still reads right after they leave
 *            taken_at         when they took it
 *
 * Nobody is given a call: it stays the open list it always was, and any waiter can still finish any
 * call. Taking one only says out loud "I'm going", which is what the board was missing.
 */
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
    try {
        $have = array_column($conn->query('SHOW COLUMNS FROM waiter_requests')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $add = ['taken_by' => 'INT NULL', 'taken_by_name' => 'VARCHAR(100) NULL', 'taken_at' => 'DATETIME NULL'];
        foreach ($add as $col => $def) if (!in_array($col, $have, true)) $conn->exec("ALTER TABLE waiter_requests ADD COLUMN $col $def");
    } catch (Throwable $e) {
        error_log('waiterCallEnsureSchema (taken_by): ' . $e->getMessage());
    }
}

/** Who is pressing the button: a staff member, or the owner working the floor themselves. */
function waiterCallWhoAmI(): array
{
    $id = (int)($_SESSION['staff_id'] ?? 0);
    $name = trim((string)($_SESSION['staff_name'] ?? $_SESSION['username'] ?? $_SESSION['user_name'] ?? ''));
    if ($name === '') $name = $id ? 'Staff' : 'Owner';
    return ['id' => $id ?: null, 'name' => mb_substr($name, 0, 100)];
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
