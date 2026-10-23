<?php
/**
 * One place that decides a PhonePe payment: ppSettle($conn, $merchantOrderId).
 *
 * Asks PhonePe's status API (never the webhook body or the browser), checks the amount, then applies
 * the result once, whatever started the payment:
 *   PP_…   customer website order      → payments + orders (kitchen ticket, alerts, emails fire once)
 *   WT…    wallet top-up               → wallet_topups + wallet ledger
 *   VM…    VIP membership              → vip_members
 *   SUB_…  restaurant plan renewal     → subscription_payments + users.renewal_date (RestroGrow's keys)
 *   PPS_…  meal-plan subscription      → meal_plan_payments + customer_meal_subscriptions
 *   SPL_…  Super Admin payment link    → payment_links (+ plan extended when the link carries months)
 * Every attempt of a meal-plan payment or payment link is kept in payment_attempts, so paying an older
 * attempt is still found.
 *
 * Callers: api/phonepe_webhook.php (all webhooks), the status checks on each return page, and
 * config/payment_reconcile.php (the safety net for anything nobody came back for).
 *
 * Returns ['kind' => …, 'outcome' => paid|failed|pending|already|not_found|unverified|mismatch].
 */
require_once __DIR__ . '/phonepe_verify.php';
require_once __DIR__ . '/order_state_machine.php';

if (!function_exists('ppSettle')) {

    function ppAttemptsEnsure(PDO $conn): void {
        static $done = false;
        if ($done) return;
        $done = true;
        $conn->exec("CREATE TABLE IF NOT EXISTS payment_attempts (
            txn VARCHAR(63) PRIMARY KEY,
            kind VARCHAR(10) NOT NULL,
            ref_id INT NOT NULL,
            restaurant_id VARCHAR(50) NULL,
            amount DECIMAL(10,2) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'Pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            settled_at DATETIME NULL,
            INDEX idx_pa_ref (kind, ref_id),
            INDEX idx_pa_pending (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    function ppAttemptAdd(PDO $conn, string $txn, string $kind, int $refId, ?string $rid, float $amount): void {
        ppAttemptsEnsure($conn);
        $conn->prepare('INSERT INTO payment_attempts (txn, kind, ref_id, restaurant_id, amount) VALUES (?, ?, ?, ?, ?)')->execute([$txn, $kind, $refId, $rid, $amount]);
    }

    function ppKind(string $txn): string {
        if (strpos($txn, 'PP_') === 0) return 'order';
        if (strpos($txn, 'PPS_') === 0) return 'meal';
        if (strpos($txn, 'SUB_') === 0) return 'subscription';
        if (strpos($txn, 'SPL_') === 0) return 'link';
        if (strpos($txn, 'WT') === 0) return 'wallet';
        if (strpos($txn, 'VM') === 0) return 'vip';
        return 'unknown';
    }

    /** PhonePe's word on a payment, mapped to what we do with it. $ageMin: how old our attempt is. */
    function ppOutcomeOf(?string $state, float $ageMin): string {
        if ($state === null) return 'unverified';
        if ($state === 'COMPLETED') return 'paid';
        if ($state === 'AMOUNT_MISMATCH') return 'mismatch';
        if (in_array($state, ['FAILED', 'REJECTED', 'CANCELLED', 'EXPIRED', 'PAYMENT_ERROR', 'PAYMENT_DECLINED'], true)) return 'failed';
        // never reached PhonePe's page (Back pressed, tab closed): failed once the page would have expired
        if ($state === 'NOT_FOUND') return $ageMin >= 25 ? 'failed' : 'pending';
        return 'pending';
    }

    function ppAgeMin(?string $createdAt): float {
        $t = $createdAt ? strtotime($createdAt) : false;
        return $t ? max(0, (time() - $t) / 60) : 0;
    }

    function ppSettle(PDO $conn, string $txn): array {
        $kind = ppKind($txn);
        try {
            switch ($kind) {
                case 'order': return ['kind' => $kind, 'outcome' => ppSettleOrder($conn, $txn)];
                case 'wallet': return ['kind' => $kind, 'outcome' => ppSettleWallet($conn, $txn)];
                case 'vip': return ['kind' => $kind, 'outcome' => ppSettleVip($conn, $txn)];
                case 'subscription': return ['kind' => $kind, 'outcome' => ppSettleSubscription($conn, $txn)];
                case 'meal': return ['kind' => $kind, 'outcome' => ppSettleMeal($conn, $txn)];
                case 'link': return ['kind' => $kind, 'outcome' => ppSettleLink($conn, $txn)];
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            error_log('ppSettle ' . $txn . ': ' . $e->getMessage());
            return ['kind' => $kind, 'outcome' => 'unverified'];
        }
        return ['kind' => $kind, 'outcome' => 'not_found'];
    }

    /* ── customer website order ── */
    function ppSettleOrder(PDO $conn, string $txn): string {
        $st = $conn->prepare("SELECT p.id, p.order_id, p.amount, p.payment_status, p.created_at, o.restaurant_id, o.order_status, o.payment_method AS order_method
            FROM payments p JOIN orders o ON p.order_id = o.id WHERE p.transaction_id = ? AND p.payment_method = 'Online' LIMIT 1");
        $st->execute([$txn]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return 'not_found';
        if ($p['payment_status'] === 'Success') return 'already';
        if ($p['payment_status'] === 'Failed' && ppAgeMin($p['created_at']) > 60 * 48) return 'failed';
        $out = ppOutcomeOf(phonepeVerifyOrderState($conn, $p['restaurant_id'], $txn, (int)round((float)$p['amount'] * 100)), ppAgeMin($p['created_at']));
        if ($out === 'paid') {
            $confirm = null;
            $conn->beginTransaction();
            try {
                $u = $conn->prepare("UPDATE payments SET payment_status = 'Success' WHERE id = ? AND payment_status <> 'Success'");
                $u->execute([$p['id']]);
                if ($u->rowCount() > 0) {
                    if (in_array($p['order_status'], ['Cancelled', 'Rejected'], true)) {
                        // paid after the order was given up on (rare: PhonePe finished very late) — the owner refunds it
                        $conn->prepare("UPDATE orders SET notes = CONCAT(COALESCE(notes, ''), ' [Paid online after it was cancelled: please refund the customer]') WHERE id = ?")->execute([$p['order_id']]);
                        error_log('PhonePe: order ' . $p['order_id'] . ' was paid after cancellation (txn ' . $txn . ') — refund needed');
                    } else {
                        $r = validateAndUpdatePaymentStatus($conn, (int)$p['order_id'], 'Paid');
                        if (!empty($r['transitioned'])) {
                            if ($p['order_method'] === 'Cash') {
                                // the customer switched to cash (phonepe_order_payment.php action=cash) while this try was
                                // still open, then paid anyway: the kitchen already has the order, so no second ticket
                                $conn->prepare("UPDATE orders SET payment_method = 'UPI / NetBanking', notes = CONCAT(COALESCE(notes, ''), ' [Paid online after choosing cash: do not collect cash]') WHERE id = ?")->execute([$p['order_id']]);
                            } else {
                                $confirm = (int)$p['order_id'];
                            }
                        }
                    }
                }
                $conn->commit();
            } catch (Throwable $e) { if ($conn->inTransaction()) $conn->rollBack(); throw $e; }
            if ($confirm) {
                require_once __DIR__ . '/order_confirmation.php';
                try { fireOrderConfirmedActions($conn, $confirm); } catch (Throwable $e) { error_log('fireOrderConfirmedActions (payment): ' . $e->getMessage()); }
            }
            return 'paid';
        }
        if ($out === 'failed' || $out === 'mismatch') {
            $conn->prepare("UPDATE payments SET payment_status = 'Failed' WHERE id = ? AND payment_status = 'Pending'")->execute([$p['id']]);
        }
        return $out;
    }

    /* ── wallet top-up ── */
    function ppSettleWallet(PDO $conn, string $txn): string {
        require_once __DIR__ . '/wallet_helpers.php';
        ensureWalletTables($conn);
        $st = $conn->prepare('SELECT * FROM wallet_topups WHERE transaction_id = ? LIMIT 1');
        $st->execute([$txn]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) return 'not_found';
        if ($t['status'] !== 'Pending') return $t['status'] === 'Success' ? 'already' : 'failed';
        $out = ppOutcomeOf(phonepeVerifyOrderState($conn, $t['restaurant_id'], $txn, (int)round((float)$t['amount'] * 100)), ppAgeMin($t['created_at'] ?? null));
        if ($out === 'paid') walletCompleteTopup($conn, $t);
        elseif ($out === 'failed' || $out === 'mismatch') walletFailTopup($conn, $t);
        return $out;
    }

    /* ── VIP membership ── */
    function ppSettleVip(PDO $conn, string $txn): string {
        require_once __DIR__ . '/vip_membership.php';
        ensureVipSchema($conn);
        $st = $conn->prepare('SELECT * FROM vip_members WHERE transaction_id = ? LIMIT 1');
        $st->execute([$txn]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) return 'not_found';
        if ($m['status'] !== 'Pending') return $m['status'] === 'Failed' ? 'failed' : 'already';
        $out = ppOutcomeOf(phonepeVerifyOrderState($conn, $m['restaurant_id'], $txn, (int)round((float)$m['amount'] * 100)), ppAgeMin($m['created_at'] ?? null));
        if ($out === 'paid') vipActivate($conn, $m);
        elseif ($out === 'failed' || $out === 'mismatch') vipFail($conn, $m);
        return $out;
    }

    /** Add months to a restaurant's plan, on top of the days still left. */
    function ppExtendPlan(PDO $conn, int $userId, int $months): void {
        $months = max(1, min(36, $months));
        $conn->prepare("UPDATE users SET subscription_status = 'active', renewal_date = DATE_ADD(GREATEST(COALESCE(renewal_date, CURDATE()), CURDATE()), INTERVAL ? MONTH), is_active = 1 WHERE id = ?")
             ->execute([$months, $userId]);
    }

    /* ── restaurant plan renewal (RestroGrow's own money: RestroGrow's keys only) ── */
    function ppSettleSubscription(PDO $conn, string $txn): string {
        $st = $conn->prepare('SELECT id, user_id, restaurant_id, amount, payment_status, duration_months, created_at FROM subscription_payments WHERE transaction_id = ? LIMIT 1');
        $st->execute([$txn]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return 'not_found';
        if ($p['payment_status'] === 'success') return 'already';
        $out = ppOutcomeOf(phonepeVerifyOrderState($conn, null, $txn, (int)round((float)$p['amount'] * 100), true), ppAgeMin($p['created_at']));
        if ($out === 'paid') {
            $u = $conn->prepare("UPDATE subscription_payments SET payment_status = 'success', updated_at = NOW() WHERE id = ? AND payment_status <> 'success'");
            $u->execute([$p['id']]);
            if ($u->rowCount() > 0) ppExtendPlan($conn, (int)$p['user_id'], (int)($p['duration_months'] ?: 1));
        } elseif ($out === 'failed' || $out === 'mismatch') {
            $conn->prepare("UPDATE subscription_payments SET payment_status = 'failed', updated_at = NOW() WHERE id = ? AND payment_status = 'pending'")->execute([$p['id']]);
        }
        return $out;
    }

    /* ── meal-plan subscription (every attempt kept in payment_attempts) ── */
    function ppSettleMeal(PDO $conn, string $txn): string {
        ppAttemptsEnsure($conn);
        $a = $conn->prepare("SELECT * FROM payment_attempts WHERE txn = ? AND kind = 'meal'");
        $a->execute([$txn]);
        $att = $a->fetch(PDO::FETCH_ASSOC);
        $sql = 'SELECT p.id, p.subscription_id, p.payment_status, p.amount, s.restaurant_id FROM meal_plan_payments p JOIN customer_meal_subscriptions s ON p.subscription_id = s.id WHERE ';
        $st = $conn->prepare($sql . ($att ? 'p.id = ?' : 'p.transaction_id = ?') . ' LIMIT 1');
        $st->execute([$att ? (int)$att['ref_id'] : $txn]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return 'not_found';
        if ($p['payment_status'] === 'Success') return 'already';
        $amount = $att ? (float)$att['amount'] : (float)$p['amount'];
        $out = ppOutcomeOf(phonepeVerifyOrderState($conn, $p['restaurant_id'], $txn, (int)round($amount * 100)), ppAgeMin($att['created_at'] ?? null));
        if ($out === 'paid') {
            $conn->beginTransaction();
            try {
                $conn->prepare("UPDATE meal_plan_payments SET payment_status = 'Success', transaction_id = ? WHERE id = ? AND payment_status <> 'Success'")->execute([$txn, $p['id']]);
                $conn->prepare("UPDATE customer_meal_subscriptions SET status = 'active' WHERE id = ? AND status = 'pending_payment'")->execute([$p['subscription_id']]);
                if ($att) $conn->prepare("UPDATE payment_attempts SET status = 'Success', settled_at = NOW() WHERE txn = ?")->execute([$txn]);
                $conn->commit();
            } catch (Throwable $e) { if ($conn->inTransaction()) $conn->rollBack(); throw $e; }
        } elseif ($out === 'failed' || $out === 'mismatch') {
            if ($att) $conn->prepare("UPDATE payment_attempts SET status = 'Failed', settled_at = NOW() WHERE txn = ? AND status = 'Pending'")->execute([$txn]);
            // the subscription's payment fails only if no other attempt is still open
            $open = $conn->prepare("SELECT COUNT(*) FROM payment_attempts WHERE kind = 'meal' AND ref_id = ? AND status = 'Pending'");
            $open->execute([$p['id']]);
            if (!(int)$open->fetchColumn()) $conn->prepare("UPDATE meal_plan_payments SET payment_status = 'Failed' WHERE id = ? AND payment_status = 'Pending'")->execute([$p['id']]);
        }
        return $out;
    }

    /* ── Super Admin payment link (RestroGrow's own money: RestroGrow's keys only) ── */
    function ppSettleLink(PDO $conn, string $txn): string {
        ppAttemptsEnsure($conn);
        ppLinksEnsure($conn);
        $a = $conn->prepare("SELECT * FROM payment_attempts WHERE txn = ? AND kind = 'link'");
        $a->execute([$txn]);
        $att = $a->fetch(PDO::FETCH_ASSOC);
        $st = $conn->prepare('SELECT * FROM payment_links WHERE ' . ($att ? 'id = ?' : 'transaction_id = ?') . ' LIMIT 1');
        $st->execute([$att ? (int)$att['ref_id'] : $txn]);
        $l = $st->fetch(PDO::FETCH_ASSOC);
        if (!$l) return 'not_found';
        if ($l['payment_status'] === 'Success') return 'already';
        $out = ppOutcomeOf(phonepeVerifyOrderState($conn, null, $txn, (int)round((float)$l['amount'] * 100), true), ppAgeMin($att['created_at'] ?? $l['created_at']));
        if ($out === 'paid') {
            $u = $conn->prepare("UPDATE payment_links SET payment_status = 'Success', transaction_id = ?, paid_at = NOW() WHERE id = ? AND payment_status <> 'Success'");
            $u->execute([$txn, $l['id']]);
            if ($att) $conn->prepare("UPDATE payment_attempts SET status = 'Success', settled_at = NOW() WHERE txn = ?")->execute([$txn]);
            if ($u->rowCount() > 0 && (int)($l['plan_months'] ?? 0) > 0) {
                $uid = $conn->prepare('SELECT id FROM users WHERE restaurant_id = ? ORDER BY id LIMIT 1');
                $uid->execute([$l['restaurant_id']]);
                if ($id = (int)$uid->fetchColumn()) ppExtendPlan($conn, $id, (int)$l['plan_months']);
            }
        } elseif (($out === 'failed' || $out === 'mismatch') && $att) {
            // a failed try never closes the link: the restaurant can open it and pay again
            $conn->prepare("UPDATE payment_attempts SET status = 'Failed', settled_at = NOW() WHERE txn = ? AND status = 'Pending'")->execute([$txn]);
        }
        return $out;
    }

    /** payment_links: a token for the permanent link, months to add to the plan, when it was paid, when it expires. */
    function ppLinksEnsure(PDO $conn): void {
        static $done = false;
        if ($done) return;
        $done = true;
        $conn->exec("CREATE TABLE IF NOT EXISTS payment_links (
            id INT AUTO_INCREMENT PRIMARY KEY, restaurant_id VARCHAR(10) NOT NULL, restaurant_name VARCHAR(255) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00, transaction_id VARCHAR(100) DEFAULT NULL,
            payment_status ENUM('Pending','Success','Failed') DEFAULT 'Pending', payment_url TEXT DEFAULT NULL,
            phonepe_order_id VARCHAR(100) DEFAULT NULL, phonepe_checkout_url TEXT DEFAULT NULL, notes VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_restaurant (restaurant_id), INDEX idx_status (payment_status), INDEX idx_transaction (transaction_id))");
        $have = array_column($conn->query('SHOW COLUMNS FROM payment_links')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        foreach (['token' => 'CHAR(32) NULL', 'plan_months' => 'TINYINT NOT NULL DEFAULT 0', 'paid_at' => 'DATETIME NULL', 'expires_at' => 'DATETIME NULL'] as $c => $d)
            if (!in_array($c, $have, true)) $conn->exec("ALTER TABLE payment_links ADD COLUMN $c $d");
        if (!in_array('token', $have, true)) {
            $conn->exec('ALTER TABLE payment_links ADD UNIQUE KEY uq_pl_token (token)');
        }
        // older links get a token, so their address keeps working
        foreach ($conn->query("SELECT id FROM payment_links WHERE token IS NULL LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) as $id)
            $conn->prepare('UPDATE payment_links SET token = ? WHERE id = ? AND token IS NULL')->execute([bin2hex(random_bytes(16)), $id]);
    }

    /** The permanent address of a payment link (opens a fresh PhonePe page every time). */
    function ppLinkUrl(string $token): string {
        $app = rtrim((string)env('APP_URL', ''), '/') ?: 'https://restrogrow.com';
        if (ppIsLocal() && !empty($_SERVER['HTTP_HOST'])) $app = 'http://' . $_SERVER['HTTP_HOST'] . '/menuwebsite';
        return $app . '/main/website/pay_link.php?t=' . $token;
    }
}
