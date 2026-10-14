<?php
/**
 * Bill numbers that count up per restaurant (per branch): 1, 2, 3...
 *
 * orders.bill_no is given out in the order bills were created, the first time a number is needed
 * (printing a bill or invoice). Every earlier order of that restaurant that has no number yet is
 * numbered first, oldest first, so the count follows the order of bills with no gaps, whichever
 * screen made the order (POS, website, KOT, waiter, subscriptions). Orders still on hold get theirs
 * when they become a bill. Once given, a number never changes.
 *
 * Call outside a transaction: the first call adds the column (ALTER TABLE commits).
 */

function rgEnsureBillNoColumn(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        if (!$conn->query("SHOW COLUMNS FROM orders LIKE 'bill_no'")->fetch()) {
            if ($conn->inTransaction()) return $ready = false;
            $conn->exec('ALTER TABLE orders ADD COLUMN bill_no INT UNSIGNED DEFAULT NULL, ADD INDEX idx_orders_bill_no (restaurant_id, bill_no)');
        }
        return $ready = true;
    } catch (Throwable $e) {
        error_log('rgEnsureBillNoColumn: ' . $e->getMessage());
        return $ready = false;
    }
}

/** The order's bill number, giving out numbers up to it if needed. null when it can't be had. */
function rgBillNo(PDO $conn, $restaurantId, int $orderId): ?int
{
    if (!$restaurantId || $orderId <= 0 || !rgEnsureBillNoColumn($conn)) return null;
    try {
        $one = $conn->prepare('SELECT bill_no FROM orders WHERE id = ? AND restaurant_id = ?');
        $one->execute([$orderId, $restaurantId]);
        $row = $one->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ($row['bill_no'] !== null) return (int)$row['bill_no'];

        // one numbering at a time per restaurant, so two tills never hand out the same number
        $lock = 'rg_bill_no_' . substr(md5((string)$restaurantId), 0, 16);
        $got = $conn->prepare('SELECT GET_LOCK(?, 5)');
        $got->execute([$lock]);
        if ((int)$got->fetchColumn() !== 1) return null;
        try {
            $one->execute([$orderId, $restaurantId]);
            $now = $one->fetchColumn();
            if ($now !== null && $now !== false) return (int)$now;

            $max = $conn->prepare('SELECT COALESCE(MAX(bill_no), 0) FROM orders WHERE restaurant_id = ?');
            $max->execute([$restaurantId]);
            $conn->prepare('SET @rg_bill_no = ?')->execute([(int)$max->fetchColumn()]);
            // one statement, oldest first (the first time this numbers the whole history)
            $conn->prepare("UPDATE orders SET bill_no = (@rg_bill_no := @rg_bill_no + 1)
                WHERE restaurant_id = ? AND bill_no IS NULL AND id <= ?
                AND (id = ? OR NOT (order_status = 'Pending' AND notes = 'Order on hold')) ORDER BY id")
                ->execute([$restaurantId, $orderId, $orderId]);
            $one->execute([$orderId, $restaurantId]);
            $mine = $one->fetchColumn();
            return $mine === null || $mine === false ? null : (int)$mine;
        } finally {
            $conn->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    } catch (Throwable $e) {
        error_log('rgBillNo order ' . $orderId . ': ' . $e->getMessage());
        return null;
    }
}
