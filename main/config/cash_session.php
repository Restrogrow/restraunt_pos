<?php
/**
 * The cash drawer: opening balance, closing balance and what happened in between.
 *
 * One shift at a time per restaurant (`cash_sessions`, status 'open'). The cashier opens the drawer with
 * the cash already in it, takes bills all shift, moves cash in and out for anything that isn't a bill
 * (petty cash, a bank drop, change brought in), then counts the drawer and closes. The close freezes
 * what the system expected, what was counted, and the difference — nobody can change it afterwards.
 *
 *   expected cash = opening balance
 *                 + cash taken at the counter this shift      (payments rows, Cash, Success)
 *                 - cash refunded this shift                   (payments rows, Cash, Refunded)
 *                 + cash movements in
 *                 - cash movements out
 *   difference    = counted - expected        (minus = short, plus = over)
 *
 * Only real cash counts. Card, UPI, wallet and credit (udhaar) never touch the drawer, so they are
 * reported but left out of the expected figure. A bill paid part cash part card writes one `payments`
 * row per method (pos_operations.php), which is what makes the cash part separable at all.
 *
 * Cash lands in the drawer when the money is taken, not when the order is placed: the session is stamped
 * on the `payments` row (`payments.cash_session_id`). An open bill settled on the night shift belongs to
 * the night shift even though the order was rung up in the morning. `orders.cash_session_id` is also
 * stamped, but only to answer "which shift sold this" in the shift report.
 *
 * Nothing here blocks billing. No open session = bills still go through, their payments simply carry no
 * session id and show up as "outside a shift" in the report. Accounting must never stop the counter.
 */

if (!function_exists('csEnsure')) {

    /** What a payment method has to be called to be real cash in the drawer. */
    define('CS_CASH_METHODS', ['cash', 'cash payment', 'cod']);

    /** Tables and the two columns that link a payment / order to its shift. Never inside a transaction. */
    function csEnsure(PDO $conn): void
    {
        static $done = false;
        if ($done || $conn->inTransaction()) return;
        $done = true;
        require_once __DIR__ . '/schema_cache.php';
        try {
            if (!rgHasTable($conn, 'cash_sessions')) {
                $conn->exec("CREATE TABLE IF NOT EXISTS cash_sessions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    restaurant_id VARCHAR(50) NOT NULL,
                    opened_by_staff_id INT DEFAULT NULL,
                    opened_by_name VARCHAR(120) NOT NULL DEFAULT '',
                    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    opened_at DATETIME NOT NULL,
                    open_note VARCHAR(255) DEFAULT NULL,
                    closed_by_staff_id INT DEFAULT NULL,
                    closed_by_name VARCHAR(120) DEFAULT NULL,
                    counted_cash DECIMAL(12,2) DEFAULT NULL,
                    expected_cash DECIMAL(12,2) DEFAULT NULL,
                    difference DECIMAL(12,2) DEFAULT NULL,
                    closed_at DATETIME DEFAULT NULL,
                    close_note VARCHAR(255) DEFAULT NULL,
                    summary LONGTEXT DEFAULT NULL,
                    status ENUM('open','closed') NOT NULL DEFAULT 'open',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_cs_open (restaurant_id, status),
                    INDEX idx_cs_when (restaurant_id, opened_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            if (!rgHasTable($conn, 'cash_movements')) {
                $conn->exec("CREATE TABLE IF NOT EXISTS cash_movements (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    restaurant_id VARCHAR(50) NOT NULL,
                    session_id INT NOT NULL,
                    direction ENUM('in','out') NOT NULL,
                    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    reason VARCHAR(160) NOT NULL DEFAULT '',
                    staff_id INT DEFAULT NULL,
                    staff_name VARCHAR(120) NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL,
                    INDEX idx_cm_session (session_id),
                    INDEX idx_cm_rest (restaurant_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            foreach ([['payments', 'cash_session_id'], ['orders', 'cash_session_id']] as [$table, $col]) {
                if (!rgHasColumn($conn, $table, $col)) {
                    $conn->exec("ALTER TABLE `$table` ADD COLUMN `$col` INT DEFAULT NULL");
                    $conn->exec("ALTER TABLE `$table` ADD INDEX `idx_{$table}_cs` (`$col`)");
                }
            }
        } catch (PDOException $e) {
            error_log('csEnsure: ' . $e->getMessage());
        }
    }

    /** The restaurant's own day, so "today" and a shift's date are the owner's, not the server's. */
    function csTz(PDO $conn, string $rid): DateTimeZone
    {
        static $cache = [];
        if (isset($cache[$rid])) return $cache[$rid];
        $zone = '';
        try {
            $st = $conn->prepare('SELECT timezone FROM users WHERE restaurant_id = ? LIMIT 1');
            $st->execute([$rid]);
            $zone = (string)$st->fetchColumn();
        } catch (PDOException $e) {}
        try { $tz = new DateTimeZone($zone ?: 'Asia/Kolkata'); } catch (Throwable $e) { $tz = new DateTimeZone('Asia/Kolkata'); }
        return $cache[$rid] = $tz;
    }

    function csNow(PDO $conn, string $rid): string
    {
        return (new DateTime('now', csTz($conn, $rid)))->format('Y-m-d H:i:s');
    }

    /** Is this payment method real cash in the drawer? */
    function csIsCashMethod(?string $method): bool
    {
        return in_array(strtolower(trim((string)$method)), CS_CASH_METHODS, true);
    }

    /** The shift that is open right now, or null. */
    function csCurrent(PDO $conn, string $rid): ?array
    {
        csEnsure($conn);
        try {
            $st = $conn->prepare("SELECT * FROM cash_sessions WHERE restaurant_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
            $st->execute([$rid]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    /** One shift at a time: opening a second one is refused, not queued. */
    function csOpen(PDO $conn, string $rid, float $opening, array $who, string $note = ''): array
    {
        csEnsure($conn);
        if (csCurrent($conn, $rid)) throw new InvalidArgumentException('A shift is already open. Close it before opening a new one.');
        if ($opening < 0 || $opening > 99999999) throw new InvalidArgumentException('Check the opening balance.');
        $st = $conn->prepare('INSERT INTO cash_sessions (restaurant_id, opened_by_staff_id, opened_by_name, opening_balance, opened_at, open_note)
            VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([$rid, $who['id'] ?: null, mb_substr($who['name'], 0, 120), round($opening, 2), csNow($conn, $rid), mb_substr(trim($note), 0, 255) ?: null]);
        $id = (int)$conn->lastInsertId();
        $row = $conn->prepare('SELECT * FROM cash_sessions WHERE id = ? AND restaurant_id = ?');
        $row->execute([$id, $rid]);
        return $row->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Stamp the open shift on a bill's payments (and the order). Called right after the POS writes them.
     * No open shift = nothing stamped, and billing carries on; never throws into the billing path.
     */
    function csStamp(PDO $conn, string $rid, int $orderId): void
    {
        try {
            require_once __DIR__ . '/schema_cache.php';
            if (!rgHasColumn($conn, 'payments', 'cash_session_id')) return;   // csEnsure can't run inside the POS transaction
            $st = $conn->prepare("SELECT id FROM cash_sessions WHERE restaurant_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
            $st->execute([$rid]);
            $sid = (int)$st->fetchColumn();
            if (!$sid) return;
            $conn->prepare('UPDATE payments SET cash_session_id = ? WHERE order_id = ? AND restaurant_id = ? AND cash_session_id IS NULL')
                 ->execute([$sid, $orderId, $rid]);
            $conn->prepare('UPDATE orders SET cash_session_id = COALESCE(cash_session_id, ?) WHERE id = ? AND restaurant_id = ?')
                 ->execute([$sid, $orderId, $rid]);
        } catch (Throwable $e) {
            error_log('csStamp: ' . $e->getMessage());   // a bill must never fail because of the cash book
        }
    }

    /** Cash in / out that isn't a bill: petty cash, a bank drop, change brought in. */
    function csMove(PDO $conn, string $rid, string $direction, float $amount, string $reason, array $who): array
    {
        csEnsure($conn);
        $s = csCurrent($conn, $rid);
        if (!$s) throw new InvalidArgumentException('Open a shift first.');
        $direction = $direction === 'out' ? 'out' : 'in';
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > 99999999) throw new InvalidArgumentException('Enter how much cash moved.');
        $reason = mb_substr(trim(strip_tags($reason)), 0, 160);
        if ($reason === '') throw new InvalidArgumentException('Say what this cash was for.');
        $conn->prepare('INSERT INTO cash_movements (restaurant_id, session_id, direction, amount, reason, staff_id, staff_name, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$rid, (int)$s['id'], $direction, $amount, $reason, $who['id'] ?: null, mb_substr($who['name'], 0, 120), csNow($conn, $rid)]);
        return ['id' => (int)$conn->lastInsertId(), 'direction' => $direction, 'amount' => $amount, 'reason' => $reason];
    }

    /** Cash movements of a shift, newest first. */
    function csMovements(PDO $conn, string $rid, int $sessionId): array
    {
        $st = $conn->prepare('SELECT id, direction, amount, reason, staff_name, created_at FROM cash_movements
            WHERE session_id = ? AND restaurant_id = ? ORDER BY id DESC LIMIT 500');
        $st->execute([$sessionId, $rid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $out[] = ['id' => (int)$m['id'], 'direction' => $m['direction'], 'amount' => (float)$m['amount'],
                      'reason' => (string)$m['reason'], 'by' => (string)$m['staff_name'], 'at' => $m['created_at']];
        }
        return $out;
    }

    /**
     * Everything a shift took, by payment method, straight from the `payments` rows stamped with it.
     * Refunded rows are kept apart so cash refunds can come off the expected cash.
     */
    function csTakings(PDO $conn, string $rid, int $sessionId): array
    {
        $st = $conn->prepare("SELECT payment_method AS method, payment_status AS status, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total
            FROM payments WHERE cash_session_id = ? AND restaurant_id = ? AND payment_status IN ('Success', 'Refunded')
            GROUP BY payment_method, payment_status");
        $st->execute([$sessionId, $rid]);
        $methods = [];
        $cashIn = 0.0;
        $cashRefund = 0.0;
        $takings = 0.0;
        $refunds = 0.0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = (string)($r['method'] ?: 'Unknown');
            $amount = round((float)$r['total'], 2);
            $refunded = $r['status'] === 'Refunded';
            if (!isset($methods[$name])) $methods[$name] = ['method' => $name, 'cash' => csIsCashMethod($name), 'orders' => 0, 'amount' => 0.0, 'refunded' => 0.0];
            $methods[$name]['orders'] += (int)$r['n'];
            if ($refunded) {
                $methods[$name]['refunded'] += $amount;
                $refunds += $amount;
                if (csIsCashMethod($name)) $cashRefund += $amount;
            } else {
                $methods[$name]['amount'] += $amount;
                $takings += $amount;
                if (csIsCashMethod($name)) $cashIn += $amount;
            }
        }
        usort($methods, fn($a, $b) => $b['amount'] <=> $a['amount']);
        return ['methods' => array_values($methods), 'takings' => round($takings, 2), 'refunds' => round($refunds, 2),
                'cash_taken' => round($cashIn, 2), 'cash_refunded' => round($cashRefund, 2)];
    }

    /** What the drawer should hold right now (a closed shift keeps the figure frozen at its close). */
    function csExpected(PDO $conn, string $rid, array $session): float
    {
        if ($session['status'] === 'closed' && $session['expected_cash'] !== null) return (float)$session['expected_cash'];
        $sid = (int)$session['id'];
        $t = csTakings($conn, $rid, $sid);
        $st = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0)
            FROM cash_movements WHERE session_id = ? AND restaurant_id = ?");
        $st->execute([$sid, $rid]);
        $moved = (float)$st->fetchColumn();
        return round((float)$session['opening_balance'] + $t['cash_taken'] - $t['cash_refunded'] + $moved, 2);
    }

    /**
     * The shift report (the "Z report"): what was sold, how it was paid, what was discounted, what went
     * out on credit, and the cash the drawer should hold. A closed shift returns the copy frozen at close,
     * so a report printed months later still reads the same.
     */
    function csSummary(PDO $conn, string $rid, array $session): array
    {
        if ($session['status'] === 'closed' && $session['summary']) {
            $frozen = json_decode((string)$session['summary'], true);
            if (is_array($frozen)) return $frozen;
        }
        $sid = (int)$session['id'];
        $t = csTakings($conn, $rid, $sid);
        $moves = csMovements($conn, $rid, $sid);
        $in = 0.0;
        $out = 0.0;
        foreach ($moves as $m) {
            if ($m['direction'] === 'in') $in += $m['amount']; else $out += $m['amount'];
        }
        // the bills rung up on this shift (an open bill settled later counts its money on the shift that took it)
        $bills = ['orders' => 0, 'gross' => 0.0, 'discount' => 0.0, 'tax' => 0.0, 'net' => 0.0];
        try {
            $st = $conn->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(subtotal), 0) AS gross, COALESCE(SUM(discount_amount), 0) AS discount,
                    COALESCE(SUM(tax), 0) AS tax, COALESCE(SUM(total), 0) AS net
                FROM orders WHERE cash_session_id = ? AND restaurant_id = ? AND deleted_at IS NULL
                  AND order_status NOT IN ('Cancelled', 'Rejected')");
            $st->execute([$sid, $rid]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $bills = ['orders' => (int)$r['n'], 'gross' => round((float)$r['gross'], 2), 'discount' => round((float)$r['discount'], 2),
                          'tax' => round((float)$r['tax'], 2), 'net' => round((float)$r['net'], 2)];
            }
        } catch (PDOException $e) {}
        return [
            'session'  => ['id' => $sid, 'opened_at' => $session['opened_at'], 'closed_at' => $session['closed_at'],
                           'opened_by' => (string)$session['opened_by_name'], 'closed_by' => (string)($session['closed_by_name'] ?? ''),
                           'opening_balance' => round((float)$session['opening_balance'], 2)],
            'bills'    => $bills,
            'methods'  => $t['methods'],
            'takings'  => $t['takings'],
            'refunds'  => $t['refunds'],
            'cash'     => ['opening' => round((float)$session['opening_balance'], 2), 'taken' => $t['cash_taken'],
                           'refunded' => $t['cash_refunded'], 'in' => round($in, 2), 'out' => round($out, 2),
                           'expected' => csExpected($conn, $rid, $session)],
            'movements' => $moves,
        ];
    }

    /** Count the drawer and shut the shift. The numbers are frozen: a closed shift is never recalculated. */
    function csClose(PDO $conn, string $rid, float $counted, array $who, string $note = ''): array
    {
        csEnsure($conn);
        $s = csCurrent($conn, $rid);
        if (!$s) throw new InvalidArgumentException('No shift is open.');
        $counted = round($counted, 2);
        if ($counted < 0 || $counted > 99999999) throw new InvalidArgumentException('Check the counted cash.');
        $expected = csExpected($conn, $rid, $s);
        $summary = csSummary($conn, $rid, $s);
        $summary['closed'] = ['counted' => $counted, 'expected' => $expected, 'difference' => round($counted - $expected, 2),
                              'by' => mb_substr($who['name'], 0, 120), 'at' => csNow($conn, $rid)];
        $conn->prepare("UPDATE cash_sessions SET status = 'closed', closed_by_staff_id = ?, closed_by_name = ?, counted_cash = ?,
                expected_cash = ?, difference = ?, closed_at = ?, close_note = ?, summary = ?
            WHERE id = ? AND restaurant_id = ? AND status = 'open'")
            ->execute([$who['id'] ?: null, mb_substr($who['name'], 0, 120), $counted, $expected, round($counted - $expected, 2),
                       csNow($conn, $rid), mb_substr(trim($note), 0, 255) ?: null, json_encode($summary, JSON_UNESCAPED_UNICODE), (int)$s['id'], $rid]);
        return $summary;
    }

    /** Past shifts for the list and the day-book, newest first. */
    function csList(PDO $conn, string $rid, string $from, string $to, int $limit = 200): array
    {
        csEnsure($conn);
        $st = $conn->prepare('SELECT * FROM cash_sessions WHERE restaurant_id = ? AND opened_at >= ? AND opened_at < DATE_ADD(?, INTERVAL 1 DAY)
            ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
        $st->execute([$rid, $from . ' 00:00:00', $to]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $closed = $s['status'] === 'closed';
            $out[] = [
                'id' => (int)$s['id'], 'status' => $s['status'],
                'opened_at' => $s['opened_at'], 'opened_by' => (string)$s['opened_by_name'],
                'closed_at' => $s['closed_at'], 'closed_by' => (string)($s['closed_by_name'] ?? ''),
                'opening' => round((float)$s['opening_balance'], 2),
                'counted' => $closed ? round((float)$s['counted_cash'], 2) : null,
                'expected' => $closed ? round((float)$s['expected_cash'], 2) : csExpected($conn, $rid, $s),
                'difference' => $closed ? round((float)$s['difference'], 2) : null,
                'open_note' => (string)($s['open_note'] ?? ''), 'close_note' => (string)($s['close_note'] ?? ''),
            ];
        }
        return $out;
    }

    /** One shift with its full report, for the drill-down and the printable Z report. */
    function csOne(PDO $conn, string $rid, int $id): ?array
    {
        csEnsure($conn);
        $st = $conn->prepare('SELECT * FROM cash_sessions WHERE id = ? AND restaurant_id = ?');
        $st->execute([$id, $rid]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) return null;
        $report = csSummary($conn, $rid, $s);
        $report['status'] = $s['status'];
        if ($s['status'] === 'closed') {
            $report['closed'] = $report['closed'] ?? ['counted' => (float)$s['counted_cash'], 'expected' => (float)$s['expected_cash'],
                'difference' => (float)$s['difference'], 'by' => (string)$s['closed_by_name'], 'at' => $s['closed_at']];
            $report['close_note'] = (string)($s['close_note'] ?? '');
        }
        return $report;
    }
}
