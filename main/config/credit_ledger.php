<?php
/**
 * Credit (udhaar): who is allowed to eat now and pay later, what they owe, and every charge and payment
 * behind that figure.
 *
 * Two tables. `credit_accounts` is the person (name, phone, an optional limit) and their running balance.
 * `credit_entries` is every movement on it — a bill put on the account (charge), money paid back
 * (payment), or a correction (adjustment) — each stamped with the balance it left behind, so a statement
 * printed today and the same statement printed next year read identically.
 *
 * The balance is only ever changed through clCharge() / clPay() / clAdjust(), inside a transaction that
 * writes the entry and the new balance together. Never UPDATE credit_accounts.balance by hand: the
 * entries are the truth and the column is the running total of them.
 *
 * How it meets the rest of the system:
 *  - A POS bill settled with a payment method that means credit (clIsCreditMethod: "Credit", "Udhaar",
 *    "Khata", "On account") becomes a charge on the customer's account, found or made from the phone
 *    number on the bill (clOnPosBill, called by controllers/pos_operations.php). No phone, no account:
 *    the bill just stays due, as it did before.
 *  - A sale is counted when the bill is rung up, not when the cash finally comes in. So a payment
 *    against an account is NOT new takings — it is cash arriving in the drawer. Paid in cash with a
 *    shift open, clPay() writes it as a cash movement in (config/cash_session.php), which is exactly
 *    what the drawer reconciliation needs and keeps it out of the day's sales.
 *  - Credit is never real cash: "Credit" is not in CS_CASH_METHODS, so a bill put on an account never
 *    inflates what the drawer is expected to hold.
 */

if (!function_exists('clEnsure')) {

    /** A payment method that means "pay me later". */
    define('CL_CREDIT_METHODS', ['credit', 'udhaar', 'udhar', 'khata', 'on account', 'account', 'credit (udhaar)']);

    function clEnsure(PDO $conn): void
    {
        static $done = false;
        if ($done || $conn->inTransaction()) return;
        $done = true;
        require_once __DIR__ . '/schema_cache.php';
        try {
            if (!rgHasTable($conn, 'credit_accounts')) {
                $conn->exec("CREATE TABLE IF NOT EXISTS credit_accounts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    restaurant_id VARCHAR(50) NOT NULL,
                    customer_id INT DEFAULT NULL,
                    name VARCHAR(120) NOT NULL DEFAULT '',
                    phone VARCHAR(20) NOT NULL DEFAULT '',
                    limit_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    notes VARCHAR(255) DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_cl_phone (restaurant_id, phone),
                    INDEX idx_cl_rest (restaurant_id, is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            if (!rgHasTable($conn, 'credit_entries')) {
                $conn->exec("CREATE TABLE IF NOT EXISTS credit_entries (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    restaurant_id VARCHAR(50) NOT NULL,
                    account_id INT NOT NULL,
                    type ENUM('charge','payment','adjustment') NOT NULL,
                    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    balance_after DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    order_id INT DEFAULT NULL,
                    method VARCHAR(40) DEFAULT NULL,
                    note VARCHAR(255) DEFAULT NULL,
                    staff_id INT DEFAULT NULL,
                    staff_name VARCHAR(120) NOT NULL DEFAULT '',
                    cash_session_id INT DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_ce_account (account_id, id),
                    INDEX idx_ce_rest (restaurant_id, created_at),
                    UNIQUE KEY uq_ce_order (restaurant_id, order_id, type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        } catch (PDOException $e) {
            error_log('clEnsure: ' . $e->getMessage());
        }
    }

    /** Does this payment method mean "on the account"? */
    function clIsCreditMethod(?string $method): bool
    {
        return in_array(strtolower(trim((string)$method)), CL_CREDIT_METHODS, true);
    }

    function clNow(PDO $conn, string $rid): string
    {
        require_once __DIR__ . '/cash_session.php';
        return csNow($conn, $rid);
    }

    /** Digits only, the way phones are stored everywhere else. */
    function clPhone($raw): string
    {
        return preg_replace('/\D/', '', (string)$raw);
    }

    function clAccount(PDO $conn, string $rid, int $id): ?array
    {
        clEnsure($conn);
        $st = $conn->prepare('SELECT * FROM credit_accounts WHERE id = ? AND restaurant_id = ?');
        $st->execute([$id, $rid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** The account for a phone number, made if it isn't there yet. '' phone = no account possible. */
    function clFindOrMake(PDO $conn, string $rid, string $phone, string $name = '', ?int $customerId = null): ?array
    {
        clEnsure($conn);
        $phone = clPhone($phone);
        if (strlen($phone) < 6) return null;
        $st = $conn->prepare('SELECT * FROM credit_accounts WHERE restaurant_id = ? AND phone = ?');
        $st->execute([$rid, $phone]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($name !== '' && $row['name'] === '') {
                $conn->prepare('UPDATE credit_accounts SET name = ? WHERE id = ?')->execute([mb_substr($name, 0, 120), $row['id']]);
                $row['name'] = $name;
            }
            return $row;
        }
        if ($customerId === null) {
            $c = $conn->prepare('SELECT id FROM customers WHERE restaurant_id = ? AND phone = ? LIMIT 1');
            $c->execute([$rid, $phone]);
            $customerId = ($found = $c->fetchColumn()) ? (int)$found : null;
        }
        $conn->prepare('INSERT INTO credit_accounts (restaurant_id, customer_id, name, phone) VALUES (?, ?, ?, ?)')
             ->execute([$rid, $customerId, mb_substr($name, 0, 120), $phone]);
        return clAccount($conn, $rid, (int)$conn->lastInsertId());
    }

    /**
     * Write one movement and the balance it leaves, together. $signed is what it does to what they owe:
     * a charge is positive, a payment negative.
     */
    function clEntry(PDO $conn, string $rid, int $accountId, string $type, float $signed, array $extra, array $who): array
    {
        clEnsure($conn);
        $mine = $conn->inTransaction();
        if (!$mine) $conn->beginTransaction();
        try {
            $st = $conn->prepare('SELECT * FROM credit_accounts WHERE id = ? AND restaurant_id = ? FOR UPDATE');
            $st->execute([$accountId, $rid]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if (!$a) throw new InvalidArgumentException('That credit account was not found.');
            $after = round((float)$a['balance'] + $signed, 2);
            $conn->prepare('INSERT INTO credit_entries (restaurant_id, account_id, type, amount, balance_after, order_id, method, note, staff_id, staff_name, cash_session_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$rid, $accountId, $type, round(abs($signed), 2), $after,
                           $extra['order_id'] ?? null, $extra['method'] ?? null, isset($extra['note']) ? mb_substr((string)$extra['note'], 0, 255) : null,
                           $who['id'] ?: null, mb_substr($who['name'], 0, 120), $extra['cash_session_id'] ?? null, clNow($conn, $rid)]);
            $entryId = (int)$conn->lastInsertId();
            $conn->prepare('UPDATE credit_accounts SET balance = ? WHERE id = ? AND restaurant_id = ?')->execute([$after, $accountId, $rid]);
            if (!$mine) $conn->commit();
            return ['id' => $entryId, 'balance' => $after];
        } catch (Throwable $e) {
            if (!$mine && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /** A bill goes on the account. Over the limit is refused, unless the caller says it was allowed. */
    function clCharge(PDO $conn, string $rid, int $accountId, float $amount, array $extra, array $who, bool $force = false): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > 99999999) throw new InvalidArgumentException('Check the amount.');
        $a = clAccount($conn, $rid, $accountId);
        if (!$a) throw new InvalidArgumentException('That credit account was not found.');
        if (!(int)$a['is_active']) throw new InvalidArgumentException('That account is switched off.');
        $limit = (float)$a['limit_amount'];
        if (!$force && $limit > 0 && round((float)$a['balance'] + $amount, 2) > $limit) {
            throw new InvalidArgumentException('That would take ' . ($a['name'] ?: $a['phone']) . ' past their credit limit.');
        }
        return clEntry($conn, $rid, $accountId, 'charge', $amount, $extra, $who);
    }

    /**
     * Money paid back. Paid in cash with a shift open, the cash is also written into the drawer as a
     * movement in — the sale was counted when the bill was rung up, so this is cash arriving, not a
     * new sale. Never more than what is owed.
     */
    function clPay(PDO $conn, string $rid, int $accountId, float $amount, string $method, string $note, array $who): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > 99999999) throw new InvalidArgumentException('Enter how much was paid.');
        $a = clAccount($conn, $rid, $accountId);
        if (!$a) throw new InvalidArgumentException('That credit account was not found.');
        $owed = round((float)$a['balance'], 2);
        if ($owed <= 0) throw new InvalidArgumentException('Nothing is outstanding on this account.');
        if ($amount > $owed) throw new InvalidArgumentException('They only owe ' . number_format($owed, 2) . '. Enter that or less.');
        $method = mb_substr(trim($method) ?: 'Cash', 0, 40);

        require_once __DIR__ . '/cash_session.php';
        $sessionId = null;
        if (csIsCashMethod($method)) {
            $shift = csCurrent($conn, $rid);
            if ($shift) $sessionId = (int)$shift['id'];
        }
        $res = clEntry($conn, $rid, $accountId, 'payment', -$amount, ['method' => $method, 'note' => $note, 'cash_session_id' => $sessionId], $who);
        if ($sessionId) {
            try {
                csMove($conn, $rid, 'in', $amount, 'Credit paid back — ' . ($a['name'] ?: $a['phone']), $who);
            } catch (Throwable $e) {
                error_log('clPay cash movement: ' . $e->getMessage());   // the ledger entry stands either way
            }
        }
        $res['in_drawer'] = $sessionId !== null;
        return $res;
    }

    /** A correction, written down with a reason so the balance is never quietly edited. */
    function clAdjust(PDO $conn, string $rid, int $accountId, float $signed, string $note, array $who): array
    {
        $signed = round($signed, 2);
        if ($signed === 0.0) throw new InvalidArgumentException('Enter the correction.');
        if (abs($signed) > 99999999) throw new InvalidArgumentException('Check the amount.');
        if (trim($note) === '') throw new InvalidArgumentException('Say why this correction is being made.');
        return clEntry($conn, $rid, $accountId, 'adjustment', $signed, ['note' => $note], $who);
    }

    /**
     * A POS bill settled "on the account". Called after the bill's payments are written
     * (controllers/pos_operations.php), inside its transaction.
     *
     * The unique key on (restaurant_id, order_id, type) makes this safe to call twice: an offline bill
     * synced again, or a held bill settled a second time, can never charge the same order twice.
     * No usable phone on the bill = nothing happens and the bill stays due, exactly as before.
     * The credit limit is not enforced here: the bill is already rung up and the food already served —
     * refusing it now would only lose the record of the debt.
     */
    function clOnPosBill(PDO $conn, string $rid, int $orderId, string $method, float $amount, string $name, string $phone, array $who): void
    {
        try {
            if (!clIsCreditMethod($method) || $amount <= 0) return;
            require_once __DIR__ . '/schema_cache.php';
            if (!rgHasTable($conn, 'credit_accounts')) return;   // clEnsure can't run inside the POS transaction
            $a = clFindOrMake($conn, $rid, $phone, $name);
            if (!$a) return;
            $dup = $conn->prepare("SELECT id FROM credit_entries WHERE restaurant_id = ? AND order_id = ? AND type = 'charge' LIMIT 1");
            $dup->execute([$rid, $orderId]);
            if ($dup->fetchColumn()) return;
            clCharge($conn, $rid, (int)$a['id'], $amount, ['order_id' => $orderId, 'method' => $method, 'note' => 'Bill on account'], $who, true);
        } catch (Throwable $e) {
            error_log('clOnPosBill: ' . $e->getMessage());   // a bill must never fail because of the ledger
        }
    }

    /** Accounts for the page: who owes what, newest movement first. */
    function clAccounts(PDO $conn, string $rid, string $filter = 'owing'): array
    {
        clEnsure($conn);
        $where = 'restaurant_id = ?';
        if ($filter === 'owing') $where .= ' AND balance > 0.005';
        elseif ($filter === 'clear') $where .= ' AND balance <= 0.005';
        elseif ($filter === 'off') $where .= ' AND is_active = 0';
        if ($filter !== 'off' && $filter !== 'all') $where .= ' AND is_active = 1';
        $st = $conn->prepare("SELECT a.*, (SELECT MAX(created_at) FROM credit_entries e WHERE e.account_id = a.id) AS last_at
            FROM credit_accounts a WHERE $where ORDER BY balance DESC, name LIMIT 500");
        $st->execute([$rid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $out[] = ['id' => (int)$a['id'], 'name' => (string)$a['name'], 'phone' => (string)$a['phone'],
                      'balance' => round((float)$a['balance'], 2), 'limit' => round((float)$a['limit_amount'], 2),
                      'notes' => (string)($a['notes'] ?? ''), 'active' => (int)$a['is_active'] === 1,
                      'last_at' => $a['last_at'], 'since' => $a['created_at']];
        }
        return $out;
    }

    /** One account's statement: every charge and payment, newest first. */
    function clStatement(PDO $conn, string $rid, int $accountId, int $limit = 300): ?array
    {
        $a = clAccount($conn, $rid, $accountId);
        if (!$a) return null;
        $st = $conn->prepare('SELECT e.*, o.order_number FROM credit_entries e LEFT JOIN orders o ON o.id = e.order_id
            WHERE e.account_id = ? AND e.restaurant_id = ? ORDER BY e.id DESC LIMIT ' . max(1, min(1000, $limit)));
        $st->execute([$accountId, $rid]);
        $rows = [];
        $charged = 0.0;
        $paid = 0.0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
            if ($e['type'] === 'charge') $charged += (float)$e['amount'];
            if ($e['type'] === 'payment') $paid += (float)$e['amount'];
            $rows[] = ['id' => (int)$e['id'], 'type' => $e['type'], 'amount' => round((float)$e['amount'], 2),
                       'balance_after' => round((float)$e['balance_after'], 2), 'order' => (string)($e['order_number'] ?? ''),
                       'method' => (string)($e['method'] ?? ''), 'note' => (string)($e['note'] ?? ''),
                       'by' => (string)$e['staff_name'], 'at' => $e['created_at']];
        }
        return [
            'account' => ['id' => (int)$a['id'], 'name' => (string)$a['name'], 'phone' => (string)$a['phone'],
                          'balance' => round((float)$a['balance'], 2), 'limit' => round((float)$a['limit_amount'], 2),
                          'notes' => (string)($a['notes'] ?? ''), 'active' => (int)$a['is_active'] === 1, 'since' => $a['created_at']],
            'entries' => $rows,
            'totals'  => ['charged' => round($charged, 2), 'paid' => round($paid, 2)],
        ];
    }

    /** The one number the owner wants: how much is out there, and how much of it is old. */
    function clOverview(PDO $conn, string $rid): array
    {
        clEnsure($conn);
        $st = $conn->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(balance), 0) AS owed FROM credit_accounts
            WHERE restaurant_id = ? AND is_active = 1 AND balance > 0.005');
        $st->execute([$rid]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'owed' => 0];
        // what has gone unpaid the longest: accounts with a balance whose last payment is over 30 days old
        $old = $conn->prepare("SELECT COUNT(*) FROM credit_accounts a WHERE a.restaurant_id = ? AND a.is_active = 1 AND a.balance > 0.005
            AND COALESCE((SELECT MAX(created_at) FROM credit_entries e WHERE e.account_id = a.id AND e.type = 'payment'), a.created_at) < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $old->execute([$rid]);
        return ['accounts' => (int)$r['n'], 'owed' => round((float)$r['owed'], 2), 'stale' => (int)$old->fetchColumn()];
    }
}
