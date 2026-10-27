<?php
/**
 * Payment safety net: nothing stays "stuck".
 *
 * runPaymentReconcile():
 *  1. asks PhonePe about every online payment still waiting (orders, plan renewals, wallet top-ups, VIP,
 *     meal plans, payment links) that is between 1 minute and 2 days old, and settles it (payment_settle.php);
 *  2. cancels website orders whose online payment never completed within 30 minutes (Back pressed, tab closed,
 *     payment failed): the coupon, loyalty points, wallet money and stock all go back (order_state_machine.php).
 *
 * Runs from: cron/payments_reconcile.php (every 5 minutes, best), the dashboard heartbeat (api/wa_dispatch.php,
 * once a minute while any dashboard is open) and the email / WhatsApp crons. One run at a time (MySQL lock),
 * at most once every 90 seconds, and it stops after ~20 seconds of work.
 */
require_once __DIR__ . '/payment_settle.php';

if (!function_exists('runPaymentReconcile')) {

    if (!defined('PP_ABANDON_MINUTES')) define('PP_ABANDON_MINUTES', 30);   // PhonePe's page expires after 20; give the customer 10 more

    function runPaymentReconcile(PDO $conn, bool $force = false, int $budgetSeconds = 20): array {
        $out = ['checked' => 0, 'paid' => 0, 'failed' => 0, 'cancelled' => 0, 'skipped' => false];
        $got = (int)$conn->query("SELECT GET_LOCK('rg_payment_reconcile', 0)")->fetchColumn();
        if (!$got) return ['skipped' => true] + $out;
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS sa_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $last = (int)$conn->query("SELECT v FROM sa_settings WHERE k = 'pay_reconcile_at'")->fetchColumn();
            if (!$force && $last > time() - 90) return ['skipped' => true] + $out;
            $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES ('pay_reconcile_at', ?)")->execute([(string)time()]);
            $until = microtime(true) + $budgetSeconds;

            foreach (ppReconcileCandidates($conn) as $txn) {
                if (microtime(true) > $until) break;
                $r = ppSettle($conn, $txn);
                $out['checked']++;
                if ($r['outcome'] === 'paid') $out['paid']++;
                elseif ($r['outcome'] === 'failed' || $r['outcome'] === 'mismatch') $out['failed']++;
            }
            if (microtime(true) < $until) $out['cancelled'] = ppCancelAbandonedOrders($conn, $until);
            if ($out['checked'] || $out['cancelled']) error_log('Payment reconcile: ' . json_encode($out));
        } finally {
            $conn->query("SELECT RELEASE_LOCK('rg_payment_reconcile')");
        }
        return $out;
    }

    /** Payment ids still waiting, oldest first (each table may not exist yet on a new server). */
    function ppReconcileCandidates(PDO $conn): array {
        $win = "created_at < NOW() - INTERVAL 1 MINUTE AND created_at > NOW() - INTERVAL 2 DAY";
        $sqls = [
            "SELECT transaction_id t, created_at c FROM payments WHERE payment_status = 'Pending' AND payment_method = 'Online' AND transaction_id LIKE 'PP\\_%' AND $win",
            "SELECT transaction_id t, created_at c FROM subscription_payments WHERE payment_status = 'pending' AND transaction_id LIKE 'SUB\\_%' AND $win",
            "SELECT transaction_id t, created_at c FROM wallet_topups WHERE status = 'Pending' AND $win",
            "SELECT transaction_id t, created_at c FROM vip_members WHERE status = 'Pending' AND transaction_id LIKE 'VM%' AND $win",
            "SELECT txn t, created_at c FROM payment_attempts WHERE status = 'Pending' AND $win",
            "SELECT transaction_id t, created_at c FROM meal_plan_payments WHERE payment_status = 'Pending' AND transaction_id LIKE 'PPS\\_%' AND $win",
            "SELECT transaction_id t, created_at c FROM qr_kit_orders WHERE payment_status = 'pending' AND $win",
        ];
        $all = [];
        foreach ($sqls as $sql) {
            try { foreach ($conn->query($sql . ' ORDER BY created_at LIMIT 150')->fetchAll(PDO::FETCH_ASSOC) as $r) if ($r['t']) $all[$r['t']] = $r['c']; }
            catch (PDOException $e) { /* table not made yet */ }
        }
        asort($all);
        return array_keys($all);
    }

    /**
     * Website orders that chose online payment and never got paid: cancelled once no payment attempt is younger
     * than PP_ABANDON_MINUTES and none succeeded. Cancelling gives back coupon, points, wallet money and stock.
     */
    function ppCancelAbandonedOrders(PDO $conn, float $until): int {
        $mins = PP_ABANDON_MINUTES;
        try {
            $rows = $conn->query("SELECT o.id, o.restaurant_id FROM orders o
                WHERE o.payment_method = 'UPI / NetBanking' AND o.payment_status = 'Pending' AND o.order_status IN ('Pending', 'Scheduled')
                  AND o.created_at < NOW() - INTERVAL $mins MINUTE AND o.created_at > NOW() - INTERVAL 2 DAY AND o.deleted_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id AND (p.payment_status = 'Success'
                       OR (p.payment_status = 'Pending' AND p.created_at > NOW() - INTERVAL $mins MINUTE)))
                ORDER BY o.id LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { error_log('ppCancelAbandonedOrders: ' . $e->getMessage()); return 0; }
        $n = 0;
        foreach ($rows as $o) {
            if (microtime(true) > $until) break;
            // last word from PhonePe on its attempts first (a late success must win over the cancel)
            $tx = $conn->prepare("SELECT transaction_id FROM payments WHERE order_id = ? AND payment_status = 'Pending' AND transaction_id LIKE 'PP\\_%'");
            $tx->execute([$o['id']]);
            $paid = false;
            foreach ($tx->fetchAll(PDO::FETCH_COLUMN) as $t) {
                $r = ppSettle($conn, $t);
                if ($r['outcome'] === 'paid' || $r['outcome'] === 'already') $paid = true;
                if ($r['outcome'] === 'pending' || $r['outcome'] === 'unverified') continue 2;   // PhonePe still busy / unreachable: next run
            }
            if ($paid) continue;
            $conn->beginTransaction();
            try {
                $res = validateAndUpdateOrderStatus($conn, (int)$o['id'], 'Cancelled', [], [], $o['restaurant_id'], '[Online payment not completed: cancelled automatically]');
                $conn->commit();
                if (!empty($res['success'])) $n++;
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                error_log('ppCancelAbandonedOrders ' . $o['id'] . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    /** Run after the current response is sent (heartbeats), so nobody waits for PhonePe. */
    function ppReconcileAfterResponse(PDO $conn): void {
        static $queued = false;
        if ($queued) return;
        $queued = true;
        register_shutdown_function(function () use ($conn) {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            try { runPaymentReconcile($conn); } catch (Throwable $e) { error_log('payment reconcile: ' . $e->getMessage()); }
        });
    }
}
