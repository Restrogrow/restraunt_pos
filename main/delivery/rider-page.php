<?php
// Rider tracking page - accessible via QR scan, no login required
require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);

require_once __DIR__ . '/../db_connection.php';

$token = $_GET['token'] ?? '';
$error = '';
$delivery = null;
$items = [];

if (empty($token)) {
    $error = 'Missing QR token';
} else {
    try {
        if (function_exists('getConnection')) {
            $conn = getConnection();
        } else {
            global $pdo;
            $conn = $pdo;
        }

        $stmt = $conn->prepare("SELECT dt.*, o.order_number, o.customer_name, o.customer_phone, o.customer_address, o.delivery_address, o.address_lat, o.address_lng, o.restaurant_id, u.restaurant_name, u.currency_symbol
            FROM delivery_tracking dt
            JOIN orders o ON dt.order_id = o.id
            JOIN users u ON o.restaurant_id = u.restaurant_id
            WHERE dt.qr_token = ? AND dt.qr_expires_at > NOW()
            LIMIT 1");
        $stmt->execute([$token]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) {
            $error = 'This QR code is invalid or has expired. Please ask the restaurant for a new one.';
        } else {
            $itemsStmt = $conn->prepare("SELECT item_name, quantity, unit_price, total_price FROM order_items WHERE order_id = ?");
            $itemsStmt->execute([$delivery['order_id']]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $error = 'Error loading delivery information';
        error_log("Rider page error: " . $e->getMessage());
    }
}

$currency = $delivery['currency_symbol'] ?? '₹';
$status = $delivery['delivery_status'] ?? 'Pending';
$statusLabels = [
    'Pending' => 'Order Placed',
    'Preparing' => 'Preparing',
    'Assigned' => 'Ready for Pickup',
    'Picked_Up' => 'Picked Up',
    'In_Transit' => 'In Transit',
    'Delivered' => 'Delivered'
];
$nextStatus = '';
if ($status === 'Picked_Up') $nextStatus = 'In_Transit';
elseif ($status === 'In_Transit') $nextStatus = 'Delivered';
elseif ($status === 'Preparing') $nextStatus = 'Picked_Up';
elseif ($status === 'Assigned') $nextStatus = 'Picked_Up';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Delivery Tracking</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', sans-serif;
    background: #f0f2f5;
    color: #1a1b1f;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.container { max-width: 420px; width: 100%; }
.card {
    background: #fff;
    border-radius: 20px;
    padding: 24px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
    margin-bottom: 16px;
}
.text-center { text-align: center; }
h1 { font-size: 20px; font-weight: 700; color: #1a1b1f; margin-bottom: 4px; }
h2 { font-size: 16px; font-weight: 600; margin-bottom: 12px; color: #374151; }
.subtitle { font-size: 13px; color: #6b7280; margin-bottom: 16px; }
.status-badge {
    display: inline-block;
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}
.status-pending { background: #fef3c7; color: #92400e; }
.status-preparing { background: #dbeafe; color: #1e40af; }
.status-assigned { background: #e0e7ff; color: #3730a3; }
.status-picked_up { background: #d1fae5; color: #065f46; }
.status-in_transit { background: #d1fae5; color: #065f46; }
.status-delivered { background: #d1fae5; color: #065f46; }
.info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
.info-row:last-child { border-bottom: none; }
.label { color: #6b7280; }
.value { font-weight: 600; color: #1a1b1f; text-align: right; }
.item-row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 13px; color: #4b5563; }
.item-name { flex: 1; }
.item-qty { width: 40px; text-align: center; color: #6b7280; }
.item-price { width: 70px; text-align: right; font-weight: 500; }
.btn {
    display: block;
    width: 100%;
    padding: 14px;
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    text-align: center;
    transition: all 0.2s;
    margin-bottom: 10px;
}
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary { background: #1a3934; color: #fff; }
.btn-primary:hover:not(:disabled) { background: #0f2925; }
.btn-success { background: #059669; color: #fff; }
.btn-success:hover:not(:disabled) { background: #047857; }
.btn-warning { background: #d97706; color: #fff; }
.btn-warning:hover:not(:disabled) { background: #b45309; }
.btn-outline { background: #fff; color: #1a1b1f; border: 2px solid #e5e7eb; }
.btn-outline:hover:not(:disabled) { border-color: #1a3934; }
.btn-secondary { background: #6b7280; color: #fff; }
.btn-secondary:hover:not(:disabled) { background: #4b5563; }
.btn:last-child { margin-bottom: 0; }
.btn-icon {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    justify-content: center;
}
.error-box {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    color: #991b1b;
    font-size: 14px;
}
.error-box .material-symbols-rounded { font-size: 40px; color: #ef4444; margin-bottom: 12px; }
.success-msg {
    text-align: center;
    padding: 10px;
    background: #d1fae5;
    border-radius: 10px;
    color: #065f46;
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 16px;
}
.status-progress {
    display: flex;
    justify-content: space-between;
    margin: 20px 0;
    position: relative;
}
.status-progress::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 10%;
    right: 10%;
    height: 3px;
    background: #e5e7eb;
    transform: translateY(-50%);
    z-index: 0;
}
.progress-step {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    width: 20%;
}
.progress-step .dot {
    width: 12px; height: 12px;
    border-radius: 50%;
    background: #e5e7eb;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px #e5e7eb;
}
.progress-step.completed .dot { background: #059669; box-shadow: 0 0 0 2px #059669; }
.progress-step.active .dot { background: #1a3934; box-shadow: 0 0 0 2px #1a3934; animation: pulse 1.5s infinite; }
.progress-step .step-label { font-size: 9px; color: #9ca3af; text-align: center; white-space: nowrap; }
.progress-step.completed .step-label { color: #059669; font-weight: 600; }
.progress-step.active .step-label { color: #1a3934; font-weight: 600; }
@keyframes pulse { 0%, 100% { box-shadow: 0 0 0 2px #1a3934; } 50% { box-shadow: 0 0 0 6px rgba(26,57,52,0.3); } }
.location-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    justify-content: center;
    background: #eff6ff;
    color: #1e40af;
    border: 2px solid #bfdbfe;
}
.location-btn.active { background: #dbeafe; border-color: #3b82f6; }
.location-btn:hover:not(:disabled) { background: #dbeafe; }
.refresh-note { text-align: center; font-size: 11px; color: #9ca3af; margin-top: 12px; }
</style>
</head>
<body>
<div class="container">
<?php if ($error): ?>
    <div class="card">
        <div class="error-box">
            <div class="material-symbols-rounded">error_outline</div>
            <p><?php echo htmlspecialchars($error); ?></p>
        </div>
    </div>
<?php elseif ($delivery): ?>
    <!-- Status Update Message -->
    <div id="statusMessage" style="display:none;"></div>

    <!-- Header -->
    <div class="card text-center">
        <div class="material-symbols-rounded" style="font-size: 40px; color: #1a3934; margin-bottom: 8px;">local_shipping</div>
        <h1><?php echo htmlspecialchars($delivery['restaurant_name']); ?></h1>
        <p class="subtitle">Order #<?php echo htmlspecialchars($delivery['order_number']); ?></p>
        <div class="status-badge status-<?php echo strtolower($status); ?>">
            <?php echo htmlspecialchars($statusLabels[$status] ?? $status); ?>
        </div>
    </div>

    <!-- Progress Bar -->
    <div class="card">
        <div class="status-progress">
            <?php
            $steps = ['Assigned' => 'Ready', 'Picked_Up' => 'Picked Up', 'In_Transit' => 'In Transit', 'Delivered' => 'Delivered'];
            $allStatuses = ['Assigned', 'Picked_Up', 'In_Transit', 'Delivered'];
            $currentIdx = array_search($status, $allStatuses);
            if ($currentIdx === false) $currentIdx = -1;
            foreach ($allStatuses as $i => $s):
                $cls = $i <= $currentIdx ? 'completed' : ($i === $currentIdx + 1 ? 'active' : '');
            ?>
            <div class="progress-step <?php echo $cls; ?>">
                <div class="dot"></div>
                <span class="step-label"><?php echo htmlspecialchars($steps[$s] ?? $s); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Order Info -->
    <div class="card">
        <h2>Delivery Details</h2>
        <div class="info-row">
            <span class="label">Customer</span>
            <span class="value"><?php echo htmlspecialchars($delivery['customer_name'] ?? 'N/A'); ?></span>
        </div>
        <div class="info-row">
            <span class="label">Phone</span>
            <span class="value"><?php echo htmlspecialchars($delivery['customer_phone'] ?? 'N/A'); ?></span>
        </div>
        <div class="info-row">
            <span class="label">Address</span>
            <span class="value" style="max-width: 200px;"><?php echo htmlspecialchars($delivery['delivery_address'] ?? 'No address'); ?></span>
        </div>
        <?php
            $destLat = $delivery['address_lat'] ?? null;
            $destLng = $delivery['address_lng'] ?? null;
            $destText = $delivery['delivery_address'] ?? $delivery['customer_address'] ?? '';
            $destination = ($destLat && $destLng) ? "$destLat,$destLng" : ($destText !== '' ? rawurlencode($destText) : '');
        ?>
        <?php if ($destination !== ''): ?>
        <a class="btn btn-primary btn-icon" style="text-decoration:none; margin-top: 8px;"
           href="https://www.google.com/maps/dir/?api=1&destination=<?php echo $destination; ?>&travelmode=driving"
           target="_blank" rel="noopener">
            🧭 Navigate in Google Maps
        </a>
        <?php endif; ?>
    </div>

    <!-- Order Items -->
    <div class="card">
        <h2>Order Items</h2>
        <?php foreach ($items as $item): ?>
        <div class="item-row">
            <span class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></span>
            <span class="item-qty">x<?php echo (int)$item['quantity']; ?></span>
            <span class="item-price"><?php echo $currency . number_format((float)$item['total_price'], 2); ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Action Buttons -->
    <div class="card">
        <?php if ($status === 'Assigned' || $status === 'Preparing'): ?>
            <button class="btn btn-primary btn-icon" onclick="updateStatus('Picked_Up')">
                <span class="material-symbols-rounded">inventory_2</span> Picked Up
            </button>
        <?php endif; ?>
        <?php if ($status === 'Picked_Up'): ?>
            <button class="btn btn-warning btn-icon" onclick="updateStatus('In_Transit')">
                <span class="material-symbols-rounded">directions_bike</span> In Transit
            </button>
        <?php endif; ?>
        <?php if ($status === 'In_Transit'): ?>
            <button class="btn btn-success btn-icon" onclick="updateStatus('Delivered')">
                <span class="material-symbols-rounded">check_circle</span> Delivered
            </button>
        <?php endif; ?>
        <?php if ($status === 'Delivered'): ?>
            <div class="success-msg">
                <span class="material-symbols-rounded" style="vertical-align: middle;">check_circle</span>
                Order has been delivered successfully!
            </div>
        <?php endif; ?>

        <?php if ($status !== 'Delivered'): ?>
            <button id="shareLocationBtn" class="btn btn-outline btn-icon location-btn" onclick="toggleLocation()">
                <span class="material-symbols-rounded" id="locationIcon">my_location</span>
                <span id="locationText">Share Live Location</span>
            </button>
        <?php endif; ?>
    </div>

    <p class="refresh-note">Page auto-refreshes every 15 seconds</p>
<?php endif; ?>
</div>

<script>
var token = '<?php echo htmlspecialchars($token); ?>';
var currentStatus = '<?php echo htmlspecialchars($status); ?>';
var locationWatchId = null;
var locationActive = false;

function updateStatus(status) {
    var btns = document.querySelectorAll('.btn');
    btns.forEach(function(b) { b.disabled = true; });

    var formData = new FormData();
    formData.append('token', token);
    formData.append('status', status);

    fetch('../api/rider_update_status.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                showMessage('success', res.message || 'Status updated!');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showMessage('error', res.message || 'Failed to update status');
                btns.forEach(function(b) { b.disabled = false; });
            }
        })
        .catch(function() {
            showMessage('error', 'Network error');
            btns.forEach(function(b) { b.disabled = false; });
        });
}

function toggleLocation() {
    if (locationActive) {
        stopLocation();
        return;
    }

    if (!navigator.geolocation) {
        showMessage('error', 'GPS not available on this device');
        return;
    }

    var btn = document.getElementById('shareLocationBtn');
    btn.disabled = true;
    document.getElementById('locationText').textContent = 'Getting location...';

    navigator.geolocation.getCurrentPosition(
        function(pos) {
            sendLocation(pos.coords.latitude, pos.coords.longitude);
            // Start watching for continuous updates
            locationWatchId = navigator.geolocation.watchPosition(
                function(p) {
                    sendLocation(p.coords.latitude, p.coords.longitude);
                },
                function() { /* silent fail on watch */ },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }
            );
            locationActive = true;
            document.getElementById('locationIcon').textContent = 'gps_fixed';
            document.getElementById('locationText').textContent = 'Sharing Location';
            btn.classList.add('active');
            btn.disabled = false;
        },
        function(err) {
            showMessage('error', 'Could not get location: ' + err.message);
            document.getElementById('locationText').textContent = 'Share Live Location';
            btn.disabled = false;
        },
        { enableHighAccuracy: true, timeout: 15000 }
    );
}

function stopLocation() {
    if (locationWatchId) {
        navigator.geolocation.clearWatch(locationWatchId);
        locationWatchId = null;
    }
    locationActive = false;
    var btn = document.getElementById('shareLocationBtn');
    document.getElementById('locationIcon').textContent = 'my_location';
    document.getElementById('locationText').textContent = 'Share Live Location';
    btn.classList.remove('active');
}

function sendLocation(lat, lng) {
    var formData = new FormData();
    formData.append('token', token);
    formData.append('lat', lat);
    formData.append('lng', lng);
    fetch('../api/rider_update_location.php', { method: 'POST', body: formData })
        .catch(function() { /* silent */ });
}

function showMessage(type, msg) {
    var el = document.getElementById('statusMessage');
    el.style.display = 'block';
    el.className = 'card ' + (type === 'success' ? 'success-msg' : 'error-box');
    el.innerHTML = '<span class="material-symbols-rounded" style="font-size:20px;vertical-align:middle;">' +
        (type === 'success' ? 'check_circle' : 'error') + '</span> ' + msg;
    setTimeout(function() { el.style.display = 'none'; }, 5000);
}

// Auto-refresh every 15 seconds
setTimeout(function() {
    if (currentStatus !== 'Delivered') {
        location.reload();
    }
}, 15000);
</script>
</body>
</html>
