<?php
/**
 * Order State Machine — guards against illegal status transitions
 * and concurrent-write corruption for the orders table.
 *
 * Two independent state machines:
 *   1) order_status — lifecycle of an order (Pending → Preparing → Ready → Served → Completed)
 *   2) payment_status — lifecycle of payment (Pending → Paid/Refunded, Pending → Failed)
 *
 * Every status update SHOULD go through validateAndUpdateOrderStatus() or
 * validateAndUpdatePaymentStatus() which atomically:
 *   a) lock the row (SELECT … FOR UPDATE)
 *   b) check the current status
 *   c) validate the transition
 *   d) execute the UPDATE
 */

// ─── Order Status (order_status) ─────────────────────────────────────────────
// DB ENUM: ('Pending', 'Accepted', 'Preparing', 'Ready', 'Served', 'Completed', 'Cancelled', 'Rejected')
//
// Valid forward transitions:
//   Pending   → Preparing, Cancelled, Accepted, Rejected
//   Accepted  → Preparing, Cancelled
//   Preparing → Ready, Cancelled
//   Ready     → Served, Cancelled
//   Served    → Completed
//   Completed → ∅  (terminal)
//   Cancelled → ∅  (terminal)
//   Rejected  → ∅  (terminal)

if (!defined('ORDER_STATUS_TRANSITIONS')) {
    define('ORDER_STATUS_TRANSITIONS', serialize([
        'Pending'   => ['Preparing', 'Cancelled', 'Accepted', 'Rejected'],
        'Accepted'  => ['Preparing', 'Cancelled'],
        'Preparing' => ['Ready', 'Cancelled'],
        'Ready'     => ['Served', 'Cancelled'],
        'Served'    => ['Completed'],
        'Completed' => [],
        'Cancelled' => [],
        'Rejected'  => [],
    ]));
}

if (!defined('PAYMENT_STATUS_TRANSITIONS')) {
    define('PAYMENT_STATUS_TRANSITIONS', serialize([
        'Pending'        => ['Paid', 'Failed', 'Partially Paid', 'Refunded'],
        'Paid'           => ['Refunded', 'Partially Paid'],
        'Partially Paid' => ['Paid', 'Refunded'],
        'Failed'         => ['Pending'],   // allow retry
        'Refunded'       => [],
    ]));
}

// ─── Lookup helpers ──────────────────────────────────────────────────────────

function getAllowedOrderStatusTransitions(): array {
    return unserialize(ORDER_STATUS_TRANSITIONS);
}

function getAllowedPaymentStatusTransitions(): array {
    return unserialize(PAYMENT_STATUS_TRANSITIONS);
}

/**
 * Get the list of legal next statuses from a given status.
 */
function getNextOrderStatuses(string $currentStatus): array {
    $map = getAllowedOrderStatusTransitions();
    return $map[$currentStatus] ?? [];
}

/**
 * Check whether a transition is allowed.
 */
function isAllowedOrderStatusTransition(string $currentStatus, string $newStatus): bool {
    return in_array($newStatus, getNextOrderStatuses($currentStatus), true);
}

function isAllowedPaymentStatusTransition(string $currentStatus, string $newStatus): bool {
    $map = getAllowedPaymentStatusTransitions();
    return isset($map[$currentStatus]) && in_array($newStatus, $map[$currentStatus], true);
}

/**
 * Return an ORDER BY / LIMIT sub-priority for status-based locking.
 * Higher priority = more "locked in" statuses.
 */
function orderStatusLockPriority(string $status): int {
    $p = [
        'Completed' => 10,
        'Cancelled' => 10,
        'Rejected'  => 10,
        'Served'    => 8,
        'Ready'     => 6,
        'Preparing' => 4,
        'Accepted'  => 3,
        'Pending'   => 2,
    ];
    return $p[$status] ?? 0;
}

// ─── Atomic UPDATE helpers (SELECT…FOR UPDATE + transition guard) ────────────

/**
 * Atomically validate and update order_status with row-level locking.
 *
 * The caller MUST have started a transaction via $conn->beginTransaction()
 * before calling this function. This function performs SELECT … FOR UPDATE
 * to prevent concurrent-write corruption.
 *
 * @param PDO         $conn          Active DB connection (inside a transaction)
 * @param int         $orderId       Order ID
 * @param string      $newStatus     Desired new status
 * @param array       $extraSet      Extra SET clauses as ["column = ?", …]
 * @param array       $extraParams   Values for extraSet
 * @param string|null $restaurantId  Scopes lock/update to this restaurant
 * @param string|null $appendNotes   If set, appends text to existing notes in the update
 *
 * @return array ['success' => bool, 'message' => string, 'current_status' => string|null]
 *
 * @throws Exception on DB errors
 */
function validateAndUpdateOrderStatus(
    PDO $conn,
    int $orderId,
    string $newStatus,
    array $extraSet = [],
    array $extraParams = [],
    ?string $restaurantId = null,
    ?string $appendNotes = null
): array {
    // 1. Lock the row and read current status + notes
    $sql = 'SELECT id, order_status, payment_status, notes, restaurant_id FROM orders WHERE id = ?';
    $params = [$orderId];
    if ($restaurantId !== null) {
        $sql .= ' AND restaurant_id = ?';
        $params[] = $restaurantId;
    }
    $sql .= ' FOR UPDATE';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return ['success' => false, 'message' => 'Order not found', 'current_status' => null];
    }

    $currentStatus = $order['order_status'];

    // 2. Check if already in target status
    if ($currentStatus === $newStatus) {
        return ['success' => false, 'message' => "Order is already {$currentStatus}", 'current_status' => $currentStatus];
    }

    // 3. Validate transition
    if (!isAllowedOrderStatusTransition($currentStatus, $newStatus)) {
        $allowed = getNextOrderStatuses($currentStatus);
        $msg = 'Illegal transition: cannot change from ' . $currentStatus . ' to ' . $newStatus;
        if (!empty($allowed)) {
            $msg .= '. Allowed: ' . implode(', ', $allowed);
        } else {
            $msg .= ' — ' . $currentStatus . ' is a terminal state';
        }
        // Log state machine violation to error monitor
        if (function_exists('logStateMachineViolation')) {
            logStateMachineViolation($conn, $orderId, $currentStatus, $newStatus, $restaurantId ?? $order['restaurant_id'] ?? null);
        }
        return ['success' => false, 'message' => $msg, 'current_status' => $currentStatus];
    }

    // 4. Build SET clauses
    $setClauses = ['order_status = ?', 'updated_at = CURRENT_TIMESTAMP'];
    $allParams  = [$newStatus];

    // 4a. Handle notes appending
    if ($appendNotes !== null) {
        $existingNotes = $order['notes'] ?? '';
        $newNotes = $existingNotes ? $existingNotes . ' ' . $appendNotes : $appendNotes;
        $setClauses[] = 'notes = ?';
        $allParams[] = $newNotes;
    }

    // 4b. Handle extra SET clauses
    foreach ($extraSet as $i => $clause) {
        $setClauses[] = $clause;
        if (isset($extraParams[$i])) {
            $allParams[] = $extraParams[$i];
        }
    }

    $allParams[] = $orderId;
    if ($restaurantId !== null) {
        $allParams[] = $restaurantId;
    }

    $updateSql = 'UPDATE orders SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
    if ($restaurantId !== null) {
        $updateSql .= ' AND restaurant_id = ?';
    }

    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute($allParams);

    // Growth module hook: award loyalty points / complete a pending referral
    // exactly once, the moment an order lands on 'Completed'. Runs inside
    // the same lock/transaction as the status update above. Failures here
    // must never break order completion, so they're swallowed and logged.
    if ($newStatus === 'Completed') {
        try {
            $orderRestaurantId = $restaurantId ?? ($order['restaurant_id'] ?? null);
            if ($orderRestaurantId) {
                $detailStmt = $conn->prepare('SELECT customer_phone, total FROM orders WHERE id = ?');
                $detailStmt->execute([$orderId]);
                $detail = $detailStmt->fetch(PDO::FETCH_ASSOC);
                if ($detail && !empty($detail['customer_phone'])) {
                    require_once __DIR__ . '/growth_helpers.php';
                    $customerId = findCustomerIdByPhone($conn, $orderRestaurantId, $detail['customer_phone']);
                    if ($customerId) {
                        awardLoyaltyPoints($conn, $orderRestaurantId, $customerId, $orderId, (float)$detail['total']);
                        completeReferralIfPending($conn, $orderRestaurantId, $customerId);
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Growth module hook failed for order ' . $orderId . ': ' . $e->getMessage());
        }
    }

    return [
        'success'       => true,
        'message'       => "Order status updated from {$currentStatus} to {$newStatus}",
        'current_status' => $currentStatus,
    ];
}

/**
 * Atomically validate and update payment_status with row-level locking.
 *
 * The caller MUST have started a transaction via $conn->beginTransaction()
 * before calling this function. This function performs SELECT … FOR UPDATE
 * to prevent concurrent-write corruption.
 *
 * @param PDO    $conn         Database connection (inside an active transaction)
 * @param int    $orderId      Order ID
 * @param string $newStatus    Desired new payment status
 * @param string|null $restaurantId  If set, scopes lock/update to this restaurant
 *
 * @return array ['success' => bool, 'message' => string]
 *
 * @throws Exception on DB errors
 */
function validateAndUpdatePaymentStatus(
    PDO $conn,
    int $orderId,
    string $newStatus,
    ?string $restaurantId = null
): array {
    $sql = 'SELECT id, payment_status, restaurant_id FROM orders WHERE id = ?';
    $params = [$orderId];
    if ($restaurantId !== null) {
        $sql .= ' AND restaurant_id = ?';
        $params[] = $restaurantId;
    }
    $sql .= ' FOR UPDATE';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return ['success' => false, 'message' => 'Order not found'];
    }

    $currentStatus = $order['payment_status'];

    if ($currentStatus === $newStatus) {
        return ['success' => true, 'transitioned' => false, 'message' => "Payment already {$currentStatus}"];
    }

    if (!isAllowedPaymentStatusTransition($currentStatus, $newStatus)) {
        $map = getAllowedPaymentStatusTransitions();
        $allowed = $map[$currentStatus] ?? [];
        $msg = 'Illegal payment transition: cannot go from ' . $currentStatus . ' to ' . $newStatus;
        if (!empty($allowed)) {
            $msg .= '. Allowed: ' . implode(', ', $allowed);
        }
        // Log state machine violation to error monitor
        if (function_exists('logStateMachineViolation')) {
            logStateMachineViolation($conn, $orderId, $currentStatus, $newStatus, $restaurantId ?? $order['restaurant_id'] ?? null);
        }
        return ['success' => false, 'transitioned' => false, 'message' => $msg];
    }

    $updateStmt = $conn->prepare('UPDATE orders SET payment_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $updateStmt->execute([$newStatus, $orderId]);

    // 'transitioned' => true means THIS call is the one that actually moved
    // payment_status into $newStatus (as opposed to it already being there,
    // or a retry losing a race under the FOR UPDATE lock). Callers that need
    // to fire one-time side effects (KOT creation, notifications, emails) on
    // "payment just became Paid" should gate on this flag, not just 'success'.
    return ['success' => true, 'transitioned' => true, 'message' => "Payment updated from {$currentStatus} to {$newStatus}"];
}
