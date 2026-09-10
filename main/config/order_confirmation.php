<?php
/**
 * Shared "order is confirmed, tell everyone" actions: creates the KOT ticket
 * for dine-in orders, pushes an admin notification, and emails the customer
 * + restaurant a confirmation.
 *
 * Called from two different points depending on payment method:
 *   - process_website_order.php calls this immediately for payment methods
 *     that are confirmed at order time (Cash, Business QR proof).
 *   - phonepe_order_callback.php / phonepe_order_payment.php call this once
 *     PhonePe payment is independently verified as Paid, so the kitchen,
 *     staff, and customer are never alerted about an order nobody has
 *     actually paid for yet.
 *
 * Idempotent by design: callers only invoke this once, at the point a
 * transition into a confirmed state is detected (see the 'transitioned'
 * flag returned by validateAndUpdatePaymentStatus()).
 */

if (!function_exists('fireOrderConfirmedActions')) {
    function fireOrderConfirmedActions(PDO $conn, int $orderId) {
        $orderStmt = $conn->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            error_log('fireOrderConfirmedActions: order ' . $orderId . ' not found');
            return;
        }

        // A scheduled order (see scheduled_order_helpers.php) can have its
        // PhonePe payment confirmed well before its scheduled_at arrives —
        // that must NOT alert the kitchen/staff/customer early. Skip here;
        // activateDueScheduledOrders() calls this function again once it
        // flips the order to 'Pending' at the right time.
        if (($order['order_status'] ?? null) === 'Scheduled') {
            return;
        }

        $restaurant_id = $order['restaurant_id'];

        $itemsStmt = $conn->prepare("SELECT menu_item_id AS id, item_name AS name, variation_name, quantity, unit_price AS price, addons FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$orderId]);
        $rawItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($rawItems as $ri) {
            $items[] = [
                'id' => $ri['id'],
                'name' => $ri['name'],
                'variation_name' => $ri['variation_name'],
                'quantity' => (int)$ri['quantity'],
                'price' => (float)$ri['price'],
                // Addons were already price-verified against meal_addons at
                // order-creation time before being stored here, so this JSON
                // does not need re-verification.
                'addons' => $ri['addons'] ? json_decode($ri['addons'], true) : [],
            ];
        }

        $kotCreated = false;
        if ($order['order_type'] === 'Dine-in' && !empty($order['table_id'])) {
            try {
                createDineInKOT($conn, $restaurant_id, $order, $items);
                $kotCreated = true;
            } catch (Exception $e) {
                error_log('KOT creation error for order ' . $orderId . ': ' . $e->getMessage());
            }
        }

        $waStmt = $conn->prepare("SELECT whatsapp_orders, phone, email, currency_symbol, restaurant_name FROM users WHERE restaurant_id = ? LIMIT 1");
        $waStmt->execute([$restaurant_id]);
        $waSettings = $waStmt->fetch(PDO::FETCH_ASSOC);
        $currencySymbol = $waSettings ? trim($waSettings['currency_symbol'] ?? '₹') : '₹';

        try {
            require_once __DIR__ . '/push_notification.php';
            sendPushNotification(
                $conn,
                $restaurant_id,
                '📦 New Order!',
                'Order #' . $order['order_number'] . ' received - ' . $currencySymbol . number_format((float)$order['total'], 2),
                '../views/orders.php',
                $orderId
            );
            // Kitchen needs to know the moment an order is confirmed, not
            // just the owner — chef_dashboard.php otherwise only finds out
            // via its ~10-20s polling loop. Gated on a KOT actually existing:
            // chef_dashboard.php only ever displays KOT tickets (Dine-in
            // orders with a table), so pushing for Takeaway/Delivery orders
            // would send the chef to a dashboard with nothing to show for it.
            if ($kotCreated) {
                sendStaffPushNotification(
                    $conn,
                    $restaurant_id,
                    ['Chef'],
                    '👨‍🍳 New Order!',
                    'Order #' . $order['order_number'] . ' - ' . $order['order_type'] . ' - start preparing',
                    '../views/chef_dashboard.php',
                    $orderId
                );
            }
        } catch (Exception $e) {
            // Don't let push notification failure break order confirmation
            error_log('Push notification error: ' . $e->getMessage());
        }

        // Dispatched, not sent inline — sendOrderConfirmationEmailsForOrder()
        // opens a raw hand-rolled SMTP connection (no pooling) to Gmail
        // twice per order (customer + restaurant), which realistically costs
        // 1-4+ seconds combined. That used to happen right here, in the same
        // request the customer's browser is waiting on. See
        // dispatchOrderConfirmationEmails() below for why this is safe to
        // detach from the response.
        dispatchOrderConfirmationEmails($conn, $orderId);
    }
}

if (!function_exists('internalDispatchToken')) {
    /**
     * Shared secret for send_order_confirmation_emails.php, verifying the
     * request genuinely came from dispatchOrderConfirmationEmails() below
     * and not just anyone hitting the URL to spam emails.
     *
     * Deliberately NOT an IP/loopback check — production sits behind a CDN,
     * so a self-call to the public domain doesn't necessarily arrive at the
     * origin with REMOTE_ADDR still showing 127.0.0.1, and forcing the
     * connection to 127.0.0.1 directly risks breaking on TLS/SNI or
     * HTTP->HTTPS redirects depending on the host's exact setup — both
     * unverifiable without live access to it. A token check works
     * regardless of how many proxies sit in between.
     *
     * Derived from SMTP_PASSWORD (already required to exist — see
     * config/email_config.php) rather than a new .env var, so there's
     * nothing extra to configure on deploy.
     */
    function internalDispatchToken($orderId) {
        require_once __DIR__ . '/env_loader.php';
        return hash('sha256', 'order-email-dispatch|' . $orderId . '|' . env('SMTP_PASSWORD', ''));
    }
}

if (!function_exists('dispatchOrderConfirmationEmails')) {
    /**
     * Fire-and-forget: hands off to send_order_confirmation_emails.php over
     * HTTP with a near-zero timeout, so this call returns almost instantly
     * regardless of how long the actual SMTP sends take. The target script
     * sets ignore_user_abort(true) so it keeps running to completion after
     * we disconnect.
     *
     * Deliberately does NOT rely on process_website_order.php's
     * respond-early trick (fastcgi_finish_request / Content-Length) — that
     * only works if the hosting stack actually honors it, which isn't
     * guaranteed behind a CDN/reverse proxy. This works regardless, because
     * it's an outbound connection we control, not a hope about how the
     * inbound one gets flushed.
     */
    function dispatchOrderConfirmationEmails($conn, $orderId) {
        // No live HTTP request to protect the latency of (cron/CLI tools
        // like tools/reconcile_pending_payments.php) — just send directly.
        if (empty($_SERVER['HTTP_HOST'])) {
            sendOrderConfirmationEmailsForOrder($conn, $orderId);
            return;
        }

        try {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            // Every current caller lives directly under main/ (main/api/*.php,
            // main/config/*.php) — two levels up from SCRIPT_NAME lands on
            // main/'s own URL path regardless of which one triggered this.
            $mainPath = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
            if ($mainPath === '/' || $mainPath === '\\') $mainPath = '';
            $url = $scheme . '://' . $host . $mainPath . '/api/send_order_confirmation_emails.php';

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'order_id' => $orderId,
                'token' => internalDispatchToken($orderId),
            ]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 300);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 300);
            // We only need the request to have been *sent* before giving up
            // waiting — the target keeps running via ignore_user_abort(true)
            // even if this times out before a response comes back, which it
            // usually will.
            curl_exec($ch);
            curl_close($ch);
        } catch (Exception $e) {
            error_log('dispatchOrderConfirmationEmails: dispatch failed, sending inline instead - ' . $e->getMessage());
            sendOrderConfirmationEmailsForOrder($conn, $orderId);
        }
    }
}

if (!function_exists('sendOrderConfirmationEmailsForOrder')) {
    /**
     * Re-fetches everything sendOrderConfirmationEmails() needs from just an
     * order ID — used both by the fire-and-forget dispatch target
     * (send_order_confirmation_emails.php, a separate request with none of
     * fireOrderConfirmedActions()'s local variables in scope) and as the
     * direct-call fallback when dispatch isn't applicable/fails.
     */
    function sendOrderConfirmationEmailsForOrder(PDO $conn, int $orderId) {
        $orderStmt = $conn->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            error_log('sendOrderConfirmationEmailsForOrder: order ' . $orderId . ' not found');
            return;
        }

        $itemsStmt = $conn->prepare("SELECT menu_item_id AS id, item_name AS name, variation_name, quantity, unit_price AS price, addons FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$orderId]);
        $rawItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($rawItems as $ri) {
            $items[] = [
                'id' => $ri['id'],
                'name' => $ri['name'],
                'variation_name' => $ri['variation_name'],
                'quantity' => (int)$ri['quantity'],
                'price' => (float)$ri['price'],
                'addons' => $ri['addons'] ? json_decode($ri['addons'], true) : [],
            ];
        }

        $restaurant_id = $order['restaurant_id'];
        $waStmt = $conn->prepare("SELECT email, currency_symbol FROM users WHERE restaurant_id = ? LIMIT 1");
        $waStmt->execute([$restaurant_id]);
        $waSettings = $waStmt->fetch(PDO::FETCH_ASSOC);
        $currencySymbol = $waSettings ? trim($waSettings['currency_symbol'] ?? '₹') : '₹';
        $restaurantEmail = $waSettings ? trim($waSettings['email'] ?? '') : '';

        $taxName = 'GST';
        $taxPercent = 5.00;
        $packagingCharge = 0.0;
        try {
            $taxStmt = $conn->prepare("SELECT tax_name, tax_percent, packaging_charge FROM users WHERE restaurant_id = ? LIMIT 1");
            $taxStmt->execute([$restaurant_id]);
            $taxRow = $taxStmt->fetch(PDO::FETCH_ASSOC);
            if ($taxRow) {
                if (!empty($taxRow['tax_name'])) $taxName = $taxRow['tax_name'];
                if (isset($taxRow['tax_percent']) && $taxRow['tax_percent'] !== null) $taxPercent = (float)$taxRow['tax_percent'];
                $packagingCharge = (float)($taxRow['packaging_charge'] ?? 0);
            }
        } catch (Exception $e) {
            // Columns may not exist on older DBs — fall back to defaults above
        }

        try {
            require_once __DIR__ . '/email_config.php';
            sendOrderConfirmationEmails($order, $items, $order['customer_email'] ?? '', $restaurantEmail, $currencySymbol, $taxName, $taxPercent, $packagingCharge);
        } catch (Exception $e) {
            error_log('Email notification error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('createDineInKOT')) {
    function createDineInKOT(PDO $conn, $restaurant_id, array $order, array $items) {
        $table_id = $order['table_id'];
        $order_type = $order['order_type'];
        $order_number = $order['order_number'];
        $notes = $order['notes'] ?? '';
        $subtotal = (float)$order['subtotal'];
        $tax = (float)$order['tax'];
        $grand_total = (float)$order['total'];
        $customer_name = $order['customer_name'];
        $customer_phone = $order['customer_phone'];
        $customer_email = $order['customer_email'];
        $customer_address = $order['customer_address'];

        $kotNumber = null;
        $maxAttempts = 100;
        $attempt = 0;
        do {
            $kotNumber = 'KOT-' . date('Ymd') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            try {
                $checkStmt = $conn->prepare("SELECT COUNT(*) FROM kot WHERE kot_number = ? AND restaurant_id = ?");
                $checkStmt->execute([$kotNumber, $restaurant_id]);
                $exists = $checkStmt->fetchColumn() > 0;
            } catch (PDOException $e) {
                $exists = false;
            }
            $attempt++;
            if ($attempt >= $maxAttempts) {
                $kotNumber = 'KOT-' . date('YmdHis') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
                break;
            }
        } while ($exists);

        $kotNotes = 'Order #' . ($order_number ?? '') . ' - Website Order';
        if (!empty($notes)) {
            $kotNotes .= "\n" . $notes;
        }

        $checkKotCols = $conn->query("SHOW COLUMNS FROM kot LIKE 'customer_phone'");
        if ($checkKotCols && $checkKotCols->rowCount() > 0) {
            $kotStmt = $conn->prepare("INSERT INTO kot (restaurant_id, kot_number, table_id, order_type, customer_name, customer_phone, customer_email, customer_address, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $kotStmt->execute([$restaurant_id, $kotNumber, $table_id, $order_type, $customer_name, $customer_phone, $customer_email, $customer_address, $subtotal, $tax, $grand_total, $kotNotes]);
        } else {
            $kotStmt = $conn->prepare("INSERT INTO kot (restaurant_id, kot_number, table_id, order_type, customer_name, subtotal, tax, total, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $kotStmt->execute([$restaurant_id, $kotNumber, $table_id, $order_type, $customer_name, $subtotal, $tax, $grand_total, $kotNotes]);
        }
        $kotId = $conn->lastInsertId();

        $kotItemsHasAddons = false;
        try {
            $checkAddonsCol = $conn->query("SHOW COLUMNS FROM kot_items LIKE 'addons'");
            $kotItemsHasAddons = $checkAddonsCol && $checkAddonsCol->rowCount() > 0;
        } catch (Exception $e) {}

        foreach ($items as $item) {
            $addonsJson = null;
            if (!empty($item['addons']) && $kotItemsHasAddons) {
                $addonsJson = json_encode($item['addons'], JSON_UNESCAPED_UNICODE);
            }

            if ($kotItemsHasAddons) {
                $kotItemStmt = $conn->prepare("INSERT INTO kot_items (kot_id, menu_item_id, item_name, quantity, unit_price, total_price, addons) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $kotItemStmt->execute([
                    $kotId,
                    (int)$item['id'],
                    (string)$item['name'],
                    (int)$item['quantity'],
                    (float)$item['price'],
                    (float)($item['price'] * $item['quantity']),
                    $addonsJson
                ]);
            } else {
                $kotItemStmt = $conn->prepare("INSERT INTO kot_items (kot_id, menu_item_id, item_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
                $kotItemStmt->execute([
                    $kotId,
                    (int)$item['id'],
                    (string)$item['name'],
                    (int)$item['quantity'],
                    (float)$item['price'],
                    (float)($item['price'] * $item['quantity'])
                ]);
            }
        }
    }
}

if (!function_exists('sendOrderConfirmationEmails')) {
    function sendOrderConfirmationEmails(array $order, array $items, string $customer_email, string $restaurantEmail, string $currencySymbol, string $taxName = 'Tax', float $taxPercent = 0.0, float $packagingCharge = 0.0) {
        $order_number = $order['order_number'];
        $order_type = $order['order_type'];
        $customer_name = $order['customer_name'];
        $customer_phone = $order['customer_phone'];
        $customer_address = $order['customer_address'] ?? '';
        $notes = $order['notes'] ?? '';
        $subtotal = (float)$order['subtotal'];
        $discount_amount = (float)($order['discount_amount'] ?? 0);
        $tax = (float)$order['tax'];
        $deliveryCharge = (float)($order['delivery_charge'] ?? 0);
        $grand_total = (float)$order['total'];

        if (empty($customer_email) && empty($restaurantEmail)) {
            return;
        }

        $itemsHtml = '';
        foreach ($items as $item) {
            $itemName = htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8');
            $itemQty = (int)($item['quantity'] ?? 1);
            $itemPrice = (float)($item['price'] ?? 0);
            $itemTotal = $itemPrice * $itemQty;
            $itemsHtml .= '<tr><td style="padding:8px;border-bottom:1px solid #ddd;">' . $itemName . '</td><td style="padding:8px;border-bottom:1px solid #ddd;text-align:center;">' . $itemQty . '</td><td style="padding:8px;border-bottom:1px solid #ddd;text-align:right;">' . $currencySymbol . number_format($itemPrice, 2) . '</td><td style="padding:8px;border-bottom:1px solid #ddd;text-align:right;">' . $currencySymbol . number_format($itemTotal, 2) . '</td></tr>';
        }
        $safeOrderNumber = htmlspecialchars($order_number, ENT_QUOTES, 'UTF-8');
        $safeOrderType = htmlspecialchars($order_type, ENT_QUOTES, 'UTF-8');
        $safeCustomerName = htmlspecialchars($customer_name, ENT_QUOTES, 'UTF-8');
        $safeCustomerPhone = htmlspecialchars($customer_phone, ENT_QUOTES, 'UTF-8');
        $safeCustomerAddress = htmlspecialchars($customer_address, ENT_QUOTES, 'UTF-8');
        $safeNotes = htmlspecialchars($notes, ENT_QUOTES, 'UTF-8');
        $orderDetailsHtml = '
        <div style="max-width:600px;margin:0 auto;font-family:Arial,sans-serif;">
            <div style="background:#2c3e50;color:#fff;padding:20px;text-align:center;border-radius:8px 8px 0 0;">
                <h1 style="margin:0;font-size:20px;">Order Confirmation</h1>
                <p style="margin:5px 0 0;opacity:0.9;">' . $safeOrderNumber . '</p>
            </div>
            <div style="background:#fff;padding:20px;border:1px solid #ddd;border-top:none;">
                <p>Dear ' . $safeCustomerName . ',</p>
                <p>Thank you for your order! Here are the details:</p>
                <table style="width:100%;border-collapse:collapse;margin:15px 0;">
                    <tr><td style="padding:6px 8px;font-weight:bold;width:120px;">Order Type:</td><td style="padding:6px 8px;">' . $safeOrderType . '</td></tr>
                    <tr><td style="padding:6px 8px;font-weight:bold;">Order No:</td><td style="padding:6px 8px;">' . $safeOrderNumber . '</td></tr>
                    <tr><td style="padding:6px 8px;font-weight:bold;">Name:</td><td style="padding:6px 8px;">' . $safeCustomerName . '</td></tr>
                    <tr><td style="padding:6px 8px;font-weight:bold;">Phone:</td><td style="padding:6px 8px;">' . $safeCustomerPhone . '</td></tr>' .
                    (!empty($customer_address) ? '<tr><td style="padding:6px 8px;font-weight:bold;">Address:</td><td style="padding:6px 8px;">' . $safeCustomerAddress . '</td></tr>' : '') .
                    (!empty($notes) ? '<tr><td style="padding:6px 8px;font-weight:bold;">Notes:</td><td style="padding:6px 8px;">' . $safeNotes . '</td></tr>' : '') . '
                </table>
                <h3 style="margin:15px 0 10px;">Order Items</h3>
                <table style="width:100%;border-collapse:collapse;">
                    <thead><tr style="background:#f8f9fa;"><th style="padding:8px;text-align:left;border-bottom:2px solid #dee2e6;">Item</th><th style="padding:8px;text-align:center;border-bottom:2px solid #dee2e6;">Qty</th><th style="padding:8px;text-align:right;border-bottom:2px solid #dee2e6;">Price</th><th style="padding:8px;text-align:right;border-bottom:2px solid #dee2e6;">Total</th></tr></thead>
                    <tbody>' . $itemsHtml . '</tbody>
                    <tfoot>
                        <tr><td colspan="3" style="padding:8px;text-align:right;font-weight:bold;">Subtotal:</td><td style="padding:8px;text-align:right;">' . $currencySymbol . number_format($subtotal, 2) . '</td></tr>' .
                        ($discount_amount > 0 ? '<tr><td colspan="3" style="padding:8px;text-align:right;font-weight:bold;color:#e74c3c;">Discount:</td><td style="padding:8px;text-align:right;color:#e74c3c;">-' . $currencySymbol . number_format($discount_amount, 2) . '</td></tr>' : '') .
                        ($packagingCharge > 0 ? '<tr><td colspan="3" style="padding:8px;text-align:right;font-weight:bold;">Packaging Charge:</td><td style="padding:8px;text-align:right;">' . $currencySymbol . number_format($packagingCharge, 2) . '</td></tr>' : '') .
                        ($tax > 0 ? '<tr><td colspan="3" style="padding:8px;text-align:right;font-weight:bold;">' . htmlspecialchars($taxName) . ' (' . rtrim(rtrim(number_format($taxPercent, 2), '0'), '.') . '%):</td><td style="padding:8px;text-align:right;">' . $currencySymbol . number_format($tax, 2) . '</td></tr>' : '') .
                        ($deliveryCharge > 0 ? '<tr><td colspan="3" style="padding:8px;text-align:right;font-weight:bold;">Delivery Fee:</td><td style="padding:8px;text-align:right;">' . $currencySymbol . number_format($deliveryCharge, 2) . '</td></tr>' : '') . '
                        <tr><td colspan="3" style="padding:8px;text-align:right;font-weight:bold;font-size:16px;">Total:</td><td style="padding:8px;text-align:right;font-weight:bold;font-size:16px;">' . $currencySymbol . number_format($grand_total, 2) . '</td></tr>
                    </tfoot>
                </table>
                <p style="margin-top:20px;color:#666;font-size:13px;">If you have any questions, please contact the restaurant.</p>
            </div>
            <div style="background:#f8f9fa;padding:15px;text-align:center;border-radius:0 0 8px 8px;border:1px solid #ddd;border-top:none;color:#999;font-size:12px;">
                RestroGrow POS — Restaurant Management System
            </div>
        </div>';

        if (!empty($customer_email)) {
            sendEmail($customer_email, 'Order Confirmed - ' . $safeOrderNumber, $orderDetailsHtml);
        }
        if (!empty($restaurantEmail)) {
            sendEmail($restaurantEmail, 'New Order Received - ' . $safeOrderNumber, '<h2 style="color:#e74c3c;">New Order Alert</h2>' . $orderDetailsHtml);
        }
    }
}
