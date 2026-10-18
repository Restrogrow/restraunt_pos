<?php
/**
 * Online payment (PhonePe: UPI/cards/netbanking) for a customer's meal-plan
 * subscription. Mirrors main/api/phonepe_order_payment.php's own-vs-platform
 * gateway logic (users.payment_gateway_mode / phonepe_merchant_id /
 * phonepe_salt_key / phonepe_environment) but drives meal_plan_payments +
 * customer_meal_subscriptions instead of payments + orders.
 *
 * Unlike phonepe_order_payment.php (a JSON API called via fetch), this is a
 * full-page redirect target - subscribe.php navigates here directly after
 * create_subscription returns phonepe_required=true, so action=initiate
 * responds with an HTTP redirect (to PhonePe, or straight back for demo
 * mode/errors) rather than JSON. action=status is the one JSON endpoint,
 * polled from my-subscription.php the same way cart.php polls order status.
 */
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../config/meal_subscription_schema.php';
require_once __DIR__ . '/../config/phonepe_verify.php';
require_once __DIR__ . '/../config/payment_settle.php';

if (!mealSubscriptionsFeatureEnabled($conn, $restaurant_id)) {
    header('Location: ' . restaurantPageUrl('menu'));
    exit();
}

$action = $_GET['action'] ?? 'initiate';

if ($action === 'status') {
    handleStatusCheck($conn, $restaurant_id, $logged_in_customer);
} else {
    handleInitiate($conn, $restaurant_id, $logged_in_customer);
}

function getBaseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($basePath === '/' || $basePath === '\\') $basePath = '';
    return $scheme . '://' . $host . $basePath;
}

function mySubscriptionUrl($restaurantId, $extraParams = []) {
    $params = array_merge(['restaurant_id' => $restaurantId], $extraParams);
    return getBaseUrl() . '/main/website/my-subscription.php?' . http_build_query($params);
}

/**
 * Loads the subscription (must belong to the logged-in customer) and its
 * most recent online-payment row. Amount is always read from this row, never
 * from the query string, the same server-authoritative-pricing rule used
 * throughout the codebase (process_website_order.php, phonepe_order_payment.php).
 */
function loadOwnSubscriptionForPayment($conn, $restaurantId, $customer, $subscriptionId) {
    if (!$customer) {
        throw new Exception('Please log in to continue');
    }
    if ($subscriptionId <= 0) {
        throw new Exception('Missing subscription');
    }

    $stmt = $conn->prepare("SELECT * FROM customer_meal_subscriptions WHERE id = ? AND customer_id = ? AND restaurant_id = ? LIMIT 1");
    $stmt->execute([$subscriptionId, $customer['id'], $restaurantId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sub) {
        throw new Exception('Subscription not found');
    }

    $payStmt = $conn->prepare("SELECT * FROM meal_plan_payments WHERE subscription_id = ? AND payment_method = 'UPI / NetBanking' ORDER BY id DESC LIMIT 1");
    $payStmt->execute([$subscriptionId]);
    $payment = $payStmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        throw new Exception('No online payment found for this subscription');
    }

    return [$sub, $payment];
}

function handleInitiate($conn, $restaurantId, $customer) {
    $subscriptionId = (int)($_GET['subscription_id'] ?? 0);

    try {
        list($sub, $payment) = loadOwnSubscriptionForPayment($conn, $restaurantId, $customer, $subscriptionId);

        if ($sub['status'] !== 'pending_payment') {
            // Already active (or cancelled) - nothing to pay, just go back.
            header('Location: ' . mySubscriptionUrl($restaurantId));
            exit();
        }

        // PhonePe charges Indian rupees: outside India there's no online payment (pay the restaurant directly)
        require_once __DIR__ . '/../config/countries.php';
        $cst = $conn->prepare('SELECT country FROM users WHERE restaurant_id = ? ORDER BY id LIMIT 1');
        $cst->execute([$restaurantId]);
        if (!restaurantIsIndia((string)$cst->fetchColumn())) {
            header('Location: ' . mySubscriptionUrl($restaurantId, ['subscription_id' => $subscriptionId, 'payment_error' => 1]));
            exit();
        }

        $amount = (float)$payment['amount'];
        if ($amount <= 0) {
            throw new Exception('Invalid amount');
        }

        // same keys, sandbox rule and demo rule as order payments (config/phonepe_verify.php)
        $config = phonepeCallbackConfig($restaurantId);
        if (!$config['ready'] && !$config['demo']) {
            throw new Exception('online payment not set up for ' . $restaurantId);
        }
        $transactionId = ppTxnId('PPS_', (string)$subscriptionId);

        // every try is kept (payment_attempts), so a late success of an earlier try is never lost
        $conn->prepare("UPDATE meal_plan_payments SET payment_status = 'Pending' WHERE id = ? AND payment_status <> 'Success'")->execute([$payment['id']]);
        ppAttemptAdd($conn, $transactionId, 'meal', (int)$payment['id'], (string)$restaurantId, $amount);

        if ($config['demo']) {
            header('Location: ' . mySubscriptionUrl($restaurantId, [
                'subscription_id' => $subscriptionId,
                'phonepe_demo' => 1,
            ]));
            exit();
        }

        $res = phonepeCreatePayment($config, $transactionId, $amount, mySubscriptionUrl($restaurantId, ['subscription_id' => $subscriptionId]), 1200, 'Meal plan');
        if ($res['ok']) {
            header('Location: ' . $res['url']);
            exit();
        }
        $conn->prepare("UPDATE payment_attempts SET status = 'Failed', settled_at = NOW() WHERE txn = ?")->execute([$transactionId]);
        throw new Exception($res['error']);
    } catch (Exception $e) {
        error_log('PhonePe subscription payment initiate error: ' . $e->getMessage());
        header('Location: ' . mySubscriptionUrl($restaurantId, ['payment_error' => 1]));
        exit();
    }
}

function handleStatusCheck($conn, $restaurantId, $customer) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=UTF-8');

    $subscriptionId = (int)($_GET['subscription_id'] ?? 0);

    try {
        list($sub, $payment) = loadOwnSubscriptionForPayment($conn, $restaurantId, $customer, $subscriptionId);

        if ($payment['payment_status'] === 'Pending') {
            // ask PhonePe about every open try (newest first); amount checked, activated once (config/payment_settle.php)
            ppAttemptsEnsure($conn);
            $tries = $conn->prepare("SELECT txn FROM payment_attempts WHERE kind = 'meal' AND ref_id = ? AND status = 'Pending' ORDER BY created_at DESC LIMIT 5");
            $tries->execute([$payment['id']]);
            $txns = $tries->fetchAll(PDO::FETCH_COLUMN);
            if (!$txns && !empty($payment['transaction_id'])) $txns = [$payment['transaction_id']];   // made before payment_attempts
            foreach ($txns as $t) {
                if (in_array(ppSettle($conn, $t)['outcome'], ['paid', 'already'], true)) break;
            }
            $re = $conn->prepare('SELECT payment_status FROM meal_plan_payments WHERE id = ?');
            $re->execute([$payment['id']]);
            $payment['payment_status'] = $re->fetchColumn() ?: $payment['payment_status'];
            $re = $conn->prepare('SELECT status FROM customer_meal_subscriptions WHERE id = ?');
            $re->execute([$sub['id']]);
            $sub['status'] = $re->fetchColumn() ?: $sub['status'];
        }

        echo json_encode([
            'success' => true,
            'payment_status' => $payment['payment_status'],
            'subscription_status' => $sub['status'],
        ]);
    } catch (Exception $e) {
        error_log('PhonePe subscription status check error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred checking payment status. Please try again.']);
    }
    exit();
}
