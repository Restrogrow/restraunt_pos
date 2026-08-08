<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);
require_once __DIR__ . '/../db_connection.php';

$orderNumber = $_POST['order_number'] ?? $_GET['order_number'] ?? '';
$customerPhone = $_POST['customer_phone'] ?? $_GET['customer_phone'] ?? '';
$error = '';
$order = null;
$tracking = null;
$items = [];

if ($orderNumber && $customerPhone) {
    try {
        $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
        if (!$conn) throw new Exception('No connection');

        $stmt = $conn->prepare("SELECT o.*, u.restaurant_name, u.currency_symbol, u.address as restaurant_address
            FROM orders o JOIN users u ON o.restaurant_id = u.restaurant_id
            WHERE o.order_number = ? AND o.customer_phone = ? AND o.source = 'website'
            LIMIT 1");
        $stmt->execute([$orderNumber, $customerPhone]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $error = 'Order not found. Please check your order number and phone number.';
        } else {
            $itemsStmt = $conn->prepare("SELECT item_name, quantity, unit_price, total_price FROM order_items WHERE order_id = ?");
            $itemsStmt->execute([$order['id']]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $trackStmt = $conn->prepare("SELECT * FROM delivery_tracking WHERE order_id = ? AND restaurant_id = ? LIMIT 1");
            $trackStmt->execute([$order['id'], $order['restaurant_id']]);
            $tracking = $trackStmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $error = 'Something went wrong. Please try again.';
        error_log("Track error: " . $e->getMessage());
    }
}

$currency = ($order['currency_symbol'] ?? '₹');
$statusLabels = [
    'Pending' => 'Order Placed', 'Accepted' => 'Accepted', 'Preparing' => 'Preparing',
    'Ready' => 'Ready', 'Served' => 'Served', 'Completed' => 'Completed',
    'Rejected' => 'Rejected', 'Cancelled' => 'Cancelled'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Track Your Order</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', sans-serif;
    background: #f8fafc;
    color: #1a1b1f;
    min-height: 100vh;
    padding: 16px;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 48px;
}
.container { max-width: 440px; width: 100%; }
.card {
    background: #fff;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 2px 16px rgba(0,0,0,0.06);
    margin-bottom: 16px;
}
.text-center { text-align: center; }
h1 { font-size: 22px; font-weight: 700; text-align: center; margin-bottom: 4px; }
h2 { font-size: 16px; font-weight: 600; margin-bottom: 12px; color: #374151; }
.subtitle { font-size: 13px; color: #6b7280; text-align: center; margin-bottom: 24px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 4px; }
.form-group input {
    width: 100%; padding: 12px 14px; border: 1.5px solid #d1d5db; border-radius: 10px;
    font-size: 15px; font-family: inherit; outline: none; transition: border-color 0.2s;
}
.form-group input:focus { border-color: #1a3934; }
.btn {
    display: block; width: 100%; padding: 14px; border: none; border-radius: 12px;
    font-size: 15px; font-weight: 600; cursor: pointer; text-align: center;
    background: #1a3934; color: #fff; transition: background 0.2s; font-family: inherit;
}
.btn:hover { background: #0f2925; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.error-box {
    background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px;
    padding: 20px; text-align: center; color: #991b1b; font-size: 14px;
}
.success-msg {
    text-align: center; padding: 12px; background: #d1fae5;
    border-radius: 10px; color: #065f46; font-size: 14px; font-weight: 500;
}
.status-badge {
    display: inline-block; padding: 6px 16px; border-radius: 20px;
    font-size: 13px; font-weight: 600;
}
.status-pending { background: #fef3c7; color: #92400e; }
.status-accepted { background: #dbeafe; color: #1e40af; }
.status-preparing { background: #dbeafe; color: #1e40af; }
.status-ready { background: #d1fae5; color: #065f46; }
.status-served { background: #d1fae5; color: #065f46; }
.status-completed { background: #d1fae5; color: #065f46; }
.status-rejected { background: #fce4ec; color: #c62828; }
.status-cancelled { background: #f3f4f6; color: #6b7280; }
.info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
.info-row:last-child { border-bottom: none; }
.label { color: #6b7280; }
.value { font-weight: 600; color: #1a1b1f; text-align: right; }
.item-row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 13px; color: #4b5563; }
.item-name { flex: 1; }
.item-qty { width: 40px; text-align: center; color: #6b7280; }
.item-price { width: 70px; text-align: right; font-weight: 500; }
.delivery-steps {
    display: flex; justify-content: space-between; margin: 20px 0; position: relative;
}
.delivery-steps::before {
    content: ''; position: absolute; top: 50%; left: 10%; right: 10%;
    height: 3px; background: #e5e7eb; transform: translateY(-50%); z-index: 0;
}
.step {
    position: relative; z-index: 1; display: flex; flex-direction: column;
    align-items: center; gap: 4px; width: 25%;
}
.step .dot { width: 12px; height: 12px; border-radius: 50%; background: #e5e7eb; border: 2px solid #fff; box-shadow: 0 0 0 2px #e5e7eb; }
.step.completed .dot { background: #059669; box-shadow: 0 0 0 2px #059669; }
.step.active .dot { background: #1a3934; box-shadow: 0 0 0 2px #1a3934; animation: pulse 1.5s infinite; }
.step .step-label { font-size: 9px; color: #9ca3af; text-align: center; white-space: nowrap; }
.step.completed .step-label { color: #059669; font-weight: 600; }
.step.active .step-label { color: #1a3934; font-weight: 600; }
@keyframes pulse { 0%, 100% { box-shadow: 0 0 0 2px #1a3934; } 50% { box-shadow: 0 0 0 6px rgba(26,57,52,0.3); } }
#trackMap { height: 200px; border-radius: 12px; margin-top: 12px; display: none; }
.back-link {
    display: inline-flex; align-items: center; gap: 4px; color: #1a3934;
    text-decoration: none; font-size: 14px; font-weight: 500; margin-bottom: 20px;
}
.back-link:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="container">
    <?php if (!$order): ?>
        <div class="card" style="text-align:center;padding-top:40px;">
            <span class="material-symbols-rounded" style="font-size:48px;color:#1a3934;">search</span>
            <h1>Track Your Order</h1>
            <p class="subtitle">Enter your order number and phone to see live status</p>
        </div>
        <div class="card">
            <form method="POST">
                <div class="form-group">
                    <label>Order Number</label>
                    <input type="text" name="order_number" placeholder="e.g. #ORD-123" required>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="customer_phone" placeholder="Phone used at checkout" required>
                </div>
                <button type="submit" class="btn">Track Order</button>
            </form>
            <?php if ($error): ?>
                <div class="error-box" style="margin-top:16px;"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <a href="track.php" class="back-link"><span class="material-symbols-rounded" style="font-size:18px;">arrow_back</span> Track Another</a>

        <div class="card text-center">
            <span class="material-symbols-rounded" style="font-size:40px;color:#1a3934;margin-bottom:8px;">receipt_long</span>
            <h1><?php echo htmlspecialchars($order['restaurant_name']); ?></h1>
            <p class="subtitle">Order #<?php echo htmlspecialchars($order['order_number']); ?></p>
            <div class="status-badge status-<?php echo strtolower($order['order_status']); ?>" id="orderStatusBadge">
                <span id="orderStatusText"><?php echo htmlspecialchars($statusLabels[$order['order_status']] ?? $order['order_status']); ?></span>
            </div>
        </div>

        <?php if ($order['order_type'] === 'Delivery'): ?>
        <div class="card">
            <h2>Delivery Progress</h2>
            <?php
            // Rendered for every Delivery order, not just ones that already
            // have a delivery_tracking row — otherwise this card (and the
            // live-updating script below it) never appears until the
            // customer manually refreshes after a rider gets assigned.
            $steps = ['Assigned' => 'Ready', 'Picked_Up' => 'Picked Up', 'In_Transit' => 'In Transit', 'Delivered' => 'Delivered'];
            $allStatuses = ['Assigned', 'Picked_Up', 'In_Transit', 'Delivered'];
            $currentIdx = ($tracking && in_array($tracking['delivery_status'] ?? null, $allStatuses, true))
                ? array_search($tracking['delivery_status'], $allStatuses)
                : -1;
            ?>
            <div class="delivery-steps" id="deliverySteps">
                <?php foreach ($allStatuses as $i => $s): $cls = $i <= $currentIdx ? 'completed' : ($i === $currentIdx + 1 ? 'active' : ''); ?>
                <div class="step <?php echo $cls; ?>" data-status="<?php echo $s; ?>">
                    <div class="dot"></div>
                    <span class="step-label"><?php echo htmlspecialchars($steps[$s] ?? $s); ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <div id="trackMap" style="display:none; height:200px; border-radius:12px; margin-top:12px;"></div>
            <p id="riderLocationNote" style="display:none; font-size:11px;color:#9ca3af;margin-top:8px;text-align:center;">Rider's live location</p>
        </div>
        <?php endif; ?>

        <?php if ($order['order_type'] === 'Delivery'): ?>
        <div class="card">
            <h2>Delivery Details</h2>
            <div class="info-row">
                <span class="label">Address</span>
                <span class="value" style="max-width:200px;"><?php echo htmlspecialchars($order['customer_address'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-row" id="deliveryChargeRow" style="<?php echo ((float)$order['delivery_charge'] > 0) ? '' : 'display:none;'; ?>">
                <span class="label">Delivery Charge</span>
                <span class="value" id="deliveryChargeValue"><?php echo $currency . number_format((float)$order['delivery_charge'], 2); ?></span>
            </div>
            <div class="info-row" id="riderRow" style="<?php echo ($tracking && $tracking['rider_name']) ? '' : 'display:none;'; ?>">
                <span class="label">Rider</span>
                <span class="value" id="riderName"><?php echo $tracking ? htmlspecialchars($tracking['rider_name'] ?? '') : ''; ?></span>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2>Order Summary</h2>
            <?php foreach ($items as $item): ?>
            <div class="item-row">
                <span class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></span>
                <span class="item-qty">x<?php echo (int)$item['quantity']; ?></span>
                <span class="item-price"><?php echo $currency . number_format((float)$item['total_price'], 2); ?></span>
            </div>
            <?php endforeach; ?>
            <div style="border-top:1px solid #e5e7eb;margin-top:8px;padding-top:8px;">
                <div class="info-row"><span class="label">Subtotal</span><span class="value"><?php echo $currency . number_format((float)$order['subtotal'], 2); ?></span></div>
                <?php if ((float)$order['tax'] > 0): ?>
                <div class="info-row"><span class="label">Tax</span><span class="value"><?php echo $currency . number_format((float)$order['tax'], 2); ?></span></div>
                <?php endif; ?>
                <div class="info-row" id="summaryDeliveryRow" style="<?php echo ((float)$order['delivery_charge'] > 0) ? '' : 'display:none;'; ?>"><span class="label">Delivery</span><span class="value" id="summaryDeliveryValue"><?php echo $currency . number_format((float)$order['delivery_charge'], 2); ?></span></div>
                <div class="info-row" style="font-weight:700;"><span class="label">Total</span><span class="value" id="summaryTotalValue"><?php echo $currency . number_format((float)$order['total'], 2); ?></span></div>
            </div>
        </div>

        <p style="text-align:center;font-size:11px;color:#9ca3af;margin-bottom:24px;">Updates automatically every 20 seconds</p>
    <?php endif; ?>
</div>

<script>
(function() {
    var statusLabels = <?php echo json_encode($statusLabels); ?>;
    var deliverySteps = ['Assigned', 'Picked_Up', 'In_Transit', 'Delivered'];
    var map = null, riderMarker = null, leafletPromise = null;

    // Leaflet is only loaded once we actually have GPS coordinates to show —
    // most orders spend most of their life with no rider assigned yet, so
    // there's no point loading a map library that has nothing to render.
    // This also means the map now appears live the moment a rider starts
    // sharing location mid-session, instead of requiring a page refresh.
    function loadLeaflet() {
        if (window.L) return Promise.resolve();
        if (leafletPromise) return leafletPromise;
        leafletPromise = new Promise(function(resolve) {
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            document.head.appendChild(link);
            var script = document.createElement('script');
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.onload = function() { resolve(); };
            document.head.appendChild(script);
        });
        return leafletPromise;
    }

    function showRiderOnMap(lat, lng) {
        loadLeaflet().then(function() {
            var mapEl = document.getElementById('trackMap');
            var noteEl = document.getElementById('riderLocationNote');
            // Explicit 'block'/'' matters here: #trackMap has display:none in
            // the stylesheet, so clearing the inline style would just fall
            // back to that CSS default instead of actually showing it.
            if (mapEl) mapEl.style.display = 'block';
            if (noteEl) noteEl.style.display = 'block';
            if (!map) {
                map = L.map('trackMap').setView([lat, lng], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
                var riderIcon = L.divIcon({
                    className: '',
                    html: '<div style="background:#1a3934;color:#fff;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.3);"><span class="material-symbols-rounded" style="font-size:20px;">directions_bike</span></div>',
                    iconSize: [36, 36], iconAnchor: [18, 36], popupAnchor: [0, -40]
                });
                riderMarker = L.marker([lat, lng], { icon: riderIcon }).addTo(map).bindPopup('Rider is here');
                // Leaflet sizes itself off the container's dimensions at
                // creation time; since it can now be created while the
                // container just became visible, force a resize check.
                setTimeout(function() { map.invalidateSize(); }, 0);
            } else {
                riderMarker.setLatLng([lat, lng]);
                map.panTo([lat, lng], { animate: true });
            }
        });
    }

    <?php if ($tracking && $tracking['current_lat'] && $tracking['current_lng']): ?>
    showRiderOnMap(<?php echo $tracking['current_lat']; ?>, <?php echo $tracking['current_lng']; ?>);
    <?php endif; ?>

    function updateDeliveryProgress(deliveryStatus) {
        var idx = deliverySteps.indexOf(deliveryStatus);
        var steps = document.querySelectorAll('#deliverySteps .step');
        steps.forEach(function(el, i) {
            el.className = 'step';
            if (i < idx) el.classList.add('completed');
            else if (i === idx) el.classList.add('active');
        });
    }

    var consecutivePollFailures = 0;
    function setLocationNote(text) {
        var noteEl = document.getElementById('riderLocationNote');
        if (noteEl) noteEl.textContent = text;
    }

    setInterval(function() {
        fetch('../api/get_tracking_status.php?order_number=<?php echo urlencode($order['order_number']); ?>&customer_phone=<?php echo urlencode($customerPhone); ?>')
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.success) return;
                consecutivePollFailures = 0;
                var statusEl = document.getElementById('orderStatusText');
                var badgeEl = document.getElementById('orderStatusBadge');
                if (statusEl && d.order_status) {
                    statusEl.textContent = statusLabels[d.order_status] || d.order_status;
                    badgeEl.className = 'status-badge status-' + d.order_status.toLowerCase();
                }
                // Reflects a delivery charge the restaurant sets when accepting
                // the order (KM-based delivery pricing) — not present at page
                // load yet if the order was still Pending, so patch it in live.
                if (typeof d.delivery_charge !== 'undefined' && d.delivery_charge !== null) {
                    var dc = parseFloat(d.delivery_charge) || 0;
                    if (dc > 0) {
                        var chargeRow = document.getElementById('deliveryChargeRow');
                        var chargeVal = document.getElementById('deliveryChargeValue');
                        var summaryRow = document.getElementById('summaryDeliveryRow');
                        var summaryVal = document.getElementById('summaryDeliveryValue');
                        var chargeText = '<?php echo $currency; ?>' + dc.toFixed(2);
                        if (chargeRow) chargeRow.style.display = '';
                        if (chargeVal) chargeVal.textContent = chargeText;
                        if (summaryRow) summaryRow.style.display = '';
                        if (summaryVal) summaryVal.textContent = chargeText;
                    }
                }
                if (typeof d.total !== 'undefined' && d.total !== null) {
                    var totalVal = document.getElementById('summaryTotalValue');
                    if (totalVal) totalVal.textContent = '<?php echo $currency; ?>' + parseFloat(d.total).toFixed(2);
                }
                if (d.tracking) {
                    if (d.tracking.rider_name) {
                        document.getElementById('riderName').textContent = d.tracking.rider_name;
                        document.getElementById('riderRow').style.display = '';
                    }
                    if (d.tracking.delivery_status) {
                        updateDeliveryProgress(d.tracking.delivery_status);
                    }
                    if (d.tracking.current_lat && d.tracking.current_lng) {
                        // Handles both cases: map already showing (moves the
                        // pin) and map not yet initialized because the rider
                        // just started sharing location for the first time
                        // since this page loaded (creates it on the fly).
                        showRiderOnMap(d.tracking.current_lat, d.tracking.current_lng);
                    }
                    // A frozen map looks identical to a live one unless we say
                    // otherwise — tell the customer when the rider's location
                    // hasn't actually updated recently instead of leaving them
                    // to assume a stalled pin is up to date.
                    var ageSec = d.tracking.location_age_seconds;
                    if (typeof ageSec === 'number') {
                        if (ageSec > 180) {
                            setLocationNote("Rider's location hasn't updated in a while — they may have a weak signal.");
                        } else {
                            setLocationNote("Rider's live location");
                        }
                    }
                }
            })
            .catch(function() {
                // Network hiccup on our side (not necessarily the rider's) —
                // after a few misses in a row, tell the customer instead of
                // leaving a silently-frozen map with no explanation.
                consecutivePollFailures++;
                if (consecutivePollFailures >= 3) {
                    setLocationNote('Having trouble getting live updates. Checking your connection…');
                }
            });
    }, 20000);
})();
</script>
</body>
</html>
