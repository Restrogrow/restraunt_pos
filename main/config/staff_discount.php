<?php
/**
 * Discounts off a bill: who gave it, why, and who allowed it.
 *
 * The POS has always let a cashier knock money off a bill (pos_operations.php, $manualDiscount), but it
 * landed in orders.discount_amount with nothing saying who did it or what for. `bill_discounts` is that
 * missing record — one row per bill, written in the same transaction as the bill itself, so the two can
 * never disagree.
 *
 * Caps. Each role can be given a ceiling: up to this much off, no questions; over it, a manager has to
 * be the one logged in (PERMISSION_APPROVE_DISCOUNT). The ceiling can be a percentage of the bill, a
 * flat amount, or both — whichever is reached first. Caps start empty, meaning no limit, so nothing
 * about billing changes until an owner decides to switch them on.
 *
 * "Staff discount" in the sense of a free or cheap staff meal is just a reason here ("Staff meal"), so
 * it shows up in the report next to every other reason instead of hiding inside one lump figure.
 *
 * Settings live as one JSON row per restaurant (`discount_settings.data`), always read through
 * sdSettings() (defaults merged in) and written through sdSettingsSave() (every key cleaned). Add a new
 * setting there, not as a column.
 */

if (!function_exists('sdEnsure')) {

    /** Offered in the POS and on the settings screen; an owner can add their own. */
    define('SD_REASONS', ['Staff meal', 'Owner / family', 'Regular customer', 'Complaint / remake', 'Loyalty goodwill',
                          'Bulk order', 'Festival offer', 'Spoiled or delayed', 'Other']);

    function sdEnsure(PDO $conn): void
    {
        static $done = false;
        if ($done || $conn->inTransaction()) return;
        $done = true;
        require_once __DIR__ . '/schema_cache.php';
        try {
            if (!rgHasTable($conn, 'bill_discounts')) {
                $conn->exec("CREATE TABLE IF NOT EXISTS bill_discounts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    restaurant_id VARCHAR(50) NOT NULL,
                    order_id INT NOT NULL,
                    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    kind ENUM('percent','amount') NOT NULL DEFAULT 'amount',
                    value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    bill_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    reason VARCHAR(120) NOT NULL DEFAULT '',
                    note VARCHAR(255) DEFAULT NULL,
                    staff_id INT DEFAULT NULL,
                    staff_name VARCHAR(120) NOT NULL DEFAULT '',
                    staff_role VARCHAR(40) NOT NULL DEFAULT '',
                    approved_by_name VARCHAR(120) DEFAULT NULL,
                    over_cap TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    UNIQUE KEY uq_bd_order (restaurant_id, order_id),
                    INDEX idx_bd_rest (restaurant_id, created_at),
                    INDEX idx_bd_staff (restaurant_id, staff_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            if (!rgHasTable($conn, 'discount_settings')) {
                $conn->exec("CREATE TABLE IF NOT EXISTS discount_settings (
                    restaurant_id VARCHAR(50) NOT NULL PRIMARY KEY,
                    data LONGTEXT NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        } catch (PDOException $e) {
            error_log('sdEnsure: ' . $e->getMessage());
        }
    }

    function sdDefaults(): array
    {
        return [
            // role => ['percent' => 0, 'amount' => 0]; 0 means "no ceiling of this kind"
            'caps'           => [],
            'reasons'        => SD_REASONS,
            'require_reason' => false,
        ];
    }

    function sdSettings(PDO $conn, string $rid): array
    {
        static $cache = [];
        if (isset($cache[$rid])) return $cache[$rid];
        sdEnsure($conn);
        $saved = [];
        try {
            $st = $conn->prepare('SELECT data FROM discount_settings WHERE restaurant_id = ? LIMIT 1');
            $st->execute([$rid]);
            $raw = $st->fetchColumn();
            if ($raw) {
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded)) $saved = $decoded;
            }
        } catch (PDOException $e) {}
        $s = array_merge(sdDefaults(), $saved);
        $s['caps'] = is_array($s['caps'] ?? null) ? $s['caps'] : [];
        $s['reasons'] = array_values(array_filter(array_map(fn($r) => mb_substr(trim(strip_tags((string)$r)), 0, 120), (array)($s['reasons'] ?: SD_REASONS))));
        if (!$s['reasons']) $s['reasons'] = SD_REASONS;
        $s['require_reason'] = !empty($s['require_reason']);
        return $cache[$rid] = $s;
    }

    function sdSettingsSave(PDO $conn, string $rid, array $in): array
    {
        sdEnsure($conn);
        $caps = [];
        foreach ((array)($in['caps'] ?? []) as $role => $cap) {
            $role = mb_substr(trim(strip_tags((string)$role)), 0, 40);
            if ($role === '') continue;
            $pct = max(0, min(100, round((float)($cap['percent'] ?? 0), 2)));
            $amt = max(0, min(99999999, round((float)($cap['amount'] ?? 0), 2)));
            if ($pct > 0 || $amt > 0) $caps[$role] = ['percent' => $pct, 'amount' => $amt];
        }
        $reasons = array_values(array_unique(array_filter(array_map(
            fn($r) => mb_substr(trim(strip_tags((string)$r)), 0, 120), (array)($in['reasons'] ?? SD_REASONS)))));
        $clean = ['caps' => $caps, 'reasons' => $reasons ?: SD_REASONS, 'require_reason' => !empty($in['require_reason'])];
        $conn->prepare('INSERT INTO discount_settings (restaurant_id, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
             ->execute([$rid, json_encode($clean, JSON_UNESCAPED_UNICODE)]);
        return $clean;
    }

    /** The ceiling for a role, or null when it has none. */
    function sdCap(PDO $conn, string $rid, string $role): ?array
    {
        $caps = sdSettings($conn, $rid)['caps'];
        foreach ($caps as $name => $cap) {
            if (strcasecmp($name, $role) === 0) return ['percent' => (float)$cap['percent'], 'amount' => (float)$cap['amount']];
        }
        return null;
    }

    /**
     * May this person take this much off this bill?
     *
     * Returns ['ok' => bool, 'over_cap' => bool, 'message' => string]. 'ok' false means the bill must be
     * refused and the message shown; over_cap true with ok true means a manager was the one logged in and
     * the row is marked so the report can show it.
     */
    function sdCheck(PDO $conn, string $rid, string $role, float $billBefore, float $discount, bool $canApprove): array
    {
        $discount = round($discount, 2);
        if ($discount <= 0) return ['ok' => true, 'over_cap' => false, 'message' => ''];
        $cap = sdCap($conn, $rid, $role);
        if (!$cap) return ['ok' => true, 'over_cap' => false, 'message' => ''];
        $overs = [];
        if ($cap['percent'] > 0 && $billBefore > 0 && round($discount / $billBefore * 100, 2) > $cap['percent']) {
            $overs[] = rtrim(rtrim(number_format($cap['percent'], 2), '0'), '.') . '%';
        }
        if ($cap['amount'] > 0 && $discount > $cap['amount']) $overs[] = number_format($cap['amount'], 2);
        if (!$overs) return ['ok' => true, 'over_cap' => false, 'message' => ''];
        if ($canApprove) return ['ok' => true, 'over_cap' => true, 'message' => ''];
        return ['ok' => false, 'over_cap' => true,
                'message' => 'This discount is over what a ' . $role . ' can give (' . implode(' / ', $overs) . '). Ask a manager to put it through.'];
    }

    /**
     * Write the record of a discount. Called inside the bill's transaction
     * (controllers/pos_operations.php), so a bill and its discount record are saved together or not at
     * all. Safe to call twice for one order: the unique key keeps the first one and updates it.
     */
    function sdRecord(PDO $conn, string $rid, int $orderId, array $d, array $who): void
    {
        try {
            if (($d['amount'] ?? 0) <= 0) return;
            require_once __DIR__ . '/schema_cache.php';
            if (!rgHasTable($conn, 'bill_discounts')) return;   // sdEnsure can't run inside the transaction
            require_once __DIR__ . '/cash_session.php';
            $conn->prepare('INSERT INTO bill_discounts (restaurant_id, order_id, amount, kind, value, bill_total, reason, note,
                    staff_id, staff_name, staff_role, approved_by_name, over_cap, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE amount = VALUES(amount), kind = VALUES(kind), value = VALUES(value),
                    bill_total = VALUES(bill_total), reason = VALUES(reason), note = VALUES(note), over_cap = VALUES(over_cap)')
                ->execute([$rid, $orderId, round((float)$d['amount'], 2), ($d['kind'] ?? '') === 'percent' ? 'percent' : 'amount',
                           round((float)($d['value'] ?? 0), 2), round((float)($d['bill_total'] ?? 0), 2),
                           mb_substr(trim(strip_tags((string)($d['reason'] ?? ''))), 0, 120),
                           mb_substr(trim(strip_tags((string)($d['note'] ?? ''))), 0, 255) ?: null,
                           $who['id'] ?: null, mb_substr($who['name'], 0, 120), mb_substr($who['role'] ?? '', 0, 40),
                           !empty($d['over_cap']) ? mb_substr($who['name'], 0, 120) : null,
                           !empty($d['over_cap']) ? 1 : 0, csNow($conn, $rid)]);
        } catch (Throwable $e) {
            error_log('sdRecord: ' . $e->getMessage());   // a bill must never fail because of the discount log
        }
    }

    /** Every discount in a period, newest first. */
    function sdList(PDO $conn, string $rid, string $from, string $to, int $limit = 500): array
    {
        sdEnsure($conn);
        $st = $conn->prepare('SELECT d.*, o.order_number, o.order_type FROM bill_discounts d LEFT JOIN orders o ON o.id = d.order_id
            WHERE d.restaurant_id = ? AND d.created_at >= ? AND d.created_at < DATE_ADD(?, INTERVAL 1 DAY)
            ORDER BY d.id DESC LIMIT ' . max(1, min(2000, $limit)));
        $st->execute([$rid, $from . ' 00:00:00', $to]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $out[] = ['id' => (int)$d['id'], 'order' => (string)($d['order_number'] ?? ''), 'order_type' => (string)($d['order_type'] ?? ''),
                      'amount' => round((float)$d['amount'], 2), 'kind' => $d['kind'], 'value' => round((float)$d['value'], 2),
                      'bill_total' => round((float)$d['bill_total'], 2), 'reason' => (string)$d['reason'], 'note' => (string)($d['note'] ?? ''),
                      'by' => (string)$d['staff_name'], 'role' => (string)$d['staff_role'],
                      'over_cap' => (int)$d['over_cap'] === 1, 'at' => $d['created_at']];
        }
        return $out;
    }

    /** The report: what discounts cost, broken down by who gave them and why. */
    function sdReport(PDO $conn, string $rid, string $from, string $to): array
    {
        sdEnsure($conn);
        $args = [$rid, $from . ' 00:00:00', $to];
        $window = 'restaurant_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $one = function (string $sql) use ($conn, $args) { $st = $conn->prepare($sql); $st->execute($args); return $st->fetchAll(PDO::FETCH_ASSOC); };

        $totals = $one("SELECT COUNT(*) AS bills, COALESCE(SUM(amount), 0) AS given, COALESCE(SUM(bill_total), 0) AS billed,
                COALESCE(SUM(over_cap), 0) AS over_cap FROM bill_discounts WHERE $window")[0] ?? [];
        $byStaff = $one("SELECT staff_name AS name, staff_role AS role, COUNT(*) AS bills, COALESCE(SUM(amount), 0) AS given,
                COALESCE(SUM(over_cap), 0) AS over_cap FROM bill_discounts WHERE $window
                GROUP BY staff_name, staff_role ORDER BY given DESC LIMIT 100");
        $byReason = $one("SELECT reason, COUNT(*) AS bills, COALESCE(SUM(amount), 0) AS given FROM bill_discounts WHERE $window
                GROUP BY reason ORDER BY given DESC LIMIT 100");
        $byDay = $one("SELECT DATE(created_at) AS day, COUNT(*) AS bills, COALESCE(SUM(amount), 0) AS given FROM bill_discounts
                WHERE $window GROUP BY DATE(created_at) ORDER BY day");

        $given = round((float)($totals['given'] ?? 0), 2);
        $billed = round((float)($totals['billed'] ?? 0), 2);
        return [
            'from' => $from, 'to' => $to,
            'totals' => ['bills' => (int)($totals['bills'] ?? 0), 'given' => $given, 'billed' => $billed,
                         'over_cap' => (int)($totals['over_cap'] ?? 0),
                         'share' => $billed > 0 ? round($given / $billed * 100, 2) : 0.0],
            'by_staff'  => array_map(fn($r) => ['name' => (string)($r['name'] ?: '—'), 'role' => (string)$r['role'],
                                                'bills' => (int)$r['bills'], 'given' => round((float)$r['given'], 2),
                                                'over_cap' => (int)$r['over_cap']], $byStaff),
            'by_reason' => array_map(fn($r) => ['reason' => (string)($r['reason'] ?: 'Not given'), 'bills' => (int)$r['bills'],
                                                'given' => round((float)$r['given'], 2)], $byReason),
            'by_day'    => array_map(fn($r) => ['day' => $r['day'], 'bills' => (int)$r['bills'], 'given' => round((float)$r['given'], 2)], $byDay),
        ];
    }
}
