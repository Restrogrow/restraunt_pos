<?php
/**
 * Customer wallet (Offers > Wallet).
 *
 * A closed wallet per restaurant: money added here can only be spent at this
 * restaurant's website. Keyed by (restaurant_id, phone) so it follows the
 * customer across devices once they log in.
 *
 *   wallets         — the balance (one row per restaurant + phone)
 *   wallet_ledger   — every change, signed: topup, bonus, spend, refund, cashback, adjust
 *   wallet_topups   — PhonePe top-ups (Pending → Success / Failed), confirmed only
 *                     by PhonePe's own status API (webhook or status check)
 *   wallet_settings — the owner's rules: limits, top-up bonus tiers, cashback
 *
 * Every balance change goes through walletApply() under a row lock, inside
 * the caller's transaction (or its own if there is none).
 */
if (!function_exists('ensureWalletTables')) {

    function ensureWalletTables(PDO $conn): void {
        static $done = false;
        if ($done || $conn->inTransaction()) return;   // DDL would commit the caller's transaction
        $done = true;
        $conn->exec("CREATE TABLE IF NOT EXISTS wallet_settings (
            restaurant_id VARCHAR(50) PRIMARY KEY,
            enabled TINYINT NOT NULL DEFAULT 0,
            name VARCHAR(40) NULL,
            min_topup DECIMAL(10,2) NOT NULL DEFAULT 100,
            max_topup DECIMAL(10,2) NOT NULL DEFAULT 10000,
            max_balance DECIMAL(10,2) NOT NULL DEFAULT 20000,
            quick_amounts VARCHAR(120) NULL,
            bonus_tiers TEXT NULL,
            cashback_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
            cashback_max DECIMAL(10,2) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS wallets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_wallet (restaurant_id, phone)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS wallet_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            balance_after DECIMAL(12,2) NOT NULL,
            type VARCHAR(12) NOT NULL,
            note VARCHAR(160) NULL,
            order_id INT NULL,
            topup_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wl_owner (restaurant_id, phone, id),
            INDEX idx_wl_order (order_id, type)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS wallet_topups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            bonus DECIMAL(10,2) NOT NULL DEFAULT 0,
            transaction_id VARCHAR(64) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'Pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            UNIQUE KEY uq_wallet_txn (transaction_id),
            INDEX idx_wt_owner (restaurant_id, phone)
        )");
        try {
            $q = $conn->query("SHOW COLUMNS FROM orders LIKE 'wallet_paid'");
            if (!($q && $q->fetch())) $conn->exec("ALTER TABLE orders ADD COLUMN wallet_paid DECIMAL(10,2) NOT NULL DEFAULT 0");
        } catch (PDOException $e) {
            error_log('ensureWalletTables (orders.wallet_paid): ' . $e->getMessage());
        }
    }

    function getWalletSettings(PDO $conn, string $rid): array {
        ensureWalletTables($conn);
        $st = $conn->prepare('SELECT * FROM wallet_settings WHERE restaurant_id = ?');
        $st->execute([$rid]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $tiers = json_decode((string)($r['bonus_tiers'] ?? ''), true);
        $tiers = is_array($tiers) ? array_values(array_filter(array_map(function ($t) {
            return ['amount' => (float)($t['amount'] ?? 0), 'bonus' => (float)($t['bonus'] ?? 0)];
        }, $tiers), function ($t) { return $t['amount'] > 0 && $t['bonus'] > 0; })) : [];
        usort($tiers, function ($a, $b) { return $a['amount'] <=> $b['amount']; });
        $quick = array_values(array_filter(array_map('floatval', explode(',', (string)($r['quick_amounts'] ?? '')))));
        return [
            'enabled' => (int)($r['enabled'] ?? 0),
            'name' => (string)($r['name'] ?? '') ?: 'RestroGrow Wallet',
            'min_topup' => (float)($r['min_topup'] ?? 100),
            'max_topup' => (float)($r['max_topup'] ?? 10000),
            'max_balance' => (float)($r['max_balance'] ?? 20000),
            'quick_amounts' => $quick ?: [200, 500, 1000],
            'bonus_tiers' => $tiers,
            'cashback_pct' => (float)($r['cashback_pct'] ?? 0),
            'cashback_max' => (float)($r['cashback_max'] ?? 0),
        ];
    }

    /** The extra money a top-up of $amount earns: the best tier it reaches. */
    function walletBonusFor(array $s, float $amount): float {
        $bonus = 0.0;
        foreach ($s['bonus_tiers'] as $t) if ($amount >= $t['amount']) $bonus = $t['bonus'];
        return $bonus;
    }

    function walletBalance(PDO $conn, string $rid, string $phone): float {
        ensureWalletTables($conn);
        $st = $conn->prepare('SELECT balance FROM wallets WHERE restaurant_id = ? AND phone = ?');
        $st->execute([$rid, $phone]);
        return (float)$st->fetchColumn();
    }

    /**
     * Change a balance by $amount (+ credit / - debit) and write it to the ledger.
     * Must run inside a transaction (the caller's). Refuses to go below zero.
     * Returns the new balance.
     */
    function walletApply(PDO $conn, string $rid, string $phone, float $amount, string $type, string $note = '', ?int $orderId = null, ?int $topupId = null): float {
        $amount = round($amount, 2);
        $conn->prepare('INSERT IGNORE INTO wallets (restaurant_id, phone, balance) VALUES (?, ?, 0)')->execute([$rid, $phone]);
        $st = $conn->prepare('SELECT id, balance FROM wallets WHERE restaurant_id = ? AND phone = ? FOR UPDATE');
        $st->execute([$rid, $phone]);
        $w = $st->fetch(PDO::FETCH_ASSOC);
        $new = round((float)$w['balance'] + $amount, 2);
        if ($new < 0) throw new RuntimeException('Not enough money in the wallet');
        $conn->prepare('UPDATE wallets SET balance = ? WHERE id = ?')->execute([$new, (int)$w['id']]);
        $conn->prepare('INSERT INTO wallet_ledger (restaurant_id, phone, amount, balance_after, type, note, order_id, topup_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
             ->execute([$rid, $phone, $amount, $new, $type, mb_substr($note, 0, 160), $orderId, $topupId]);
        return $new;
    }

    /** Run $fn in a transaction unless one is already open. */
    function walletTx(PDO $conn, callable $fn) {
        if ($conn->inTransaction()) return $fn();
        $conn->beginTransaction();
        try { $r = $fn(); $conn->commit(); return $r; }
        catch (Throwable $e) { $conn->rollBack(); throw $e; }
    }

    /** A paid top-up: add the money and its bonus, exactly once. */
    function walletCompleteTopup(PDO $conn, array $topup): bool {
        return walletTx($conn, function () use ($conn, $topup) {
            $st = $conn->prepare("UPDATE wallet_topups SET status = 'Success', completed_at = NOW() WHERE id = ? AND status = 'Pending'");
            $st->execute([(int)$topup['id']]);
            if ($st->rowCount() !== 1) return false;                // already done
            walletApply($conn, $topup['restaurant_id'], $topup['phone'], (float)$topup['amount'], 'topup', 'Added with PhonePe', null, (int)$topup['id']);
            if ((float)$topup['bonus'] > 0) {
                walletApply($conn, $topup['restaurant_id'], $topup['phone'], (float)$topup['bonus'], 'bonus', 'Top-up bonus', null, (int)$topup['id']);
            }
            return true;
        });
    }

    function walletFailTopup(PDO $conn, array $topup): void {
        $conn->prepare("UPDATE wallet_topups SET status = 'Failed', completed_at = NOW() WHERE id = ? AND status = 'Pending'")->execute([(int)$topup['id']]);
    }

    /**
     * Order status hook (order_state_machine.php, inside its transaction):
     *   Cancelled / Rejected → give back what the wallet paid
     *   Completed            → cashback, if the owner turned it on
     * Each happens at most once per order.
     */
    function walletOrderStatusHook(PDO $conn, int $orderId, string $newStatus): void {
        $st = $conn->prepare('SELECT * FROM orders WHERE id = ?');
        $st->execute([$orderId]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o || empty($o['customer_phone'])) return;
        $o['wallet_paid'] = (float)($o['wallet_paid'] ?? 0);
        // Restaurants that never used the wallet have no wallet tables: nothing to do
        try { $conn->query('SELECT 1 FROM wallet_ledger LIMIT 1'); } catch (PDOException $e) { return; }
        $done = $conn->prepare('SELECT 1 FROM wallet_ledger WHERE order_id = ? AND type = ? LIMIT 1');

        if (in_array($newStatus, ['Cancelled', 'Rejected'], true) && (float)$o['wallet_paid'] > 0) {
            $done->execute([$orderId, 'refund']);
            if (!$done->fetchColumn()) {
                walletApply($conn, $o['restaurant_id'], $o['customer_phone'], (float)$o['wallet_paid'], 'refund', 'Order ' . strtolower($newStatus) . ', money returned', $orderId);
            }
        }
        if ($newStatus === 'Completed') {
            $s = getWalletSettings($conn, $o['restaurant_id']);
            if (!$s['enabled'] || $s['cashback_pct'] <= 0) return;
            $cash = floor((float)$o['total'] * $s['cashback_pct']) / 100;
            if ($s['cashback_max'] > 0) $cash = min($cash, $s['cashback_max']);
            // VIP members earn faster (Loyalty Studio > VIP membership)
            require_once __DIR__ . '/vip_membership.php';
            if (function_exists('vipEarnBoost')) $cash *= vipEarnBoost($conn, $o['restaurant_id'], (string)$o['customer_phone']);
            $cash = round($cash, 2);
            if ($cash <= 0) return;
            $done->execute([$orderId, 'cashback']);
            if (!$done->fetchColumn()) {
                walletApply($conn, $o['restaurant_id'], $o['customer_phone'], $cash, 'cashback', 'Cashback on your order', $orderId);
            }
        }
    }

    /** The customer's wallet for the profile page. */
    function walletSummary(PDO $conn, string $rid, string $phone, int $limit = 25): array {
        $st = $conn->prepare('SELECT l.amount, l.balance_after, l.type, l.note, l.created_at, o.order_number
            FROM wallet_ledger l LEFT JOIN orders o ON o.id = l.order_id
            WHERE l.restaurant_id = ? AND l.phone = ? ORDER BY l.id DESC LIMIT ' . (int)$limit);
        $st->execute([$rid, $phone]);
        return ['balance' => walletBalance($conn, $rid, $phone), 'history' => array_map(function ($r) {
            return ['amount' => (float)$r['amount'], 'type' => $r['type'], 'note' => $r['note'], 'when' => $r['created_at'], 'order' => $r['order_number']];
        }, $st->fetchAll(PDO::FETCH_ASSOC))];
    }
}
