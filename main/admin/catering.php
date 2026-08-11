<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

if (!isSessionValid() || !isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['restaurant_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/../config/authorization_config.php';
requirePermission(PERMISSION_MANAGE_ORDERS);

$restaurant_id = $_SESSION['restaurant_id'];
$restaurant_name = $_SESSION['restaurant_name'] ?? 'Restaurant';
$currency_symbol = $_SESSION['currency_symbol'] ?? '₹';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catering / Bulk Orders - <?php echo htmlspecialchars($restaurant_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: #f0f2f5; color: #1a1b1f; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .header h1 { font-size: 28px; font-weight: 700; color: #151A2D; }
        .header p { color: #666; font-size: 14px; margin-top: 4px; }
        .btn { padding: 10px 20px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: 'Poppins', sans-serif; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: linear-gradient(135deg, #e17055, #d63031); color: #fff; }
        .btn-edit { background: #10b981; color: #fff; }
        .btn-delete { background: #ef4444; color: #fff; }
        .btn-secondary { background: #6b7280; color: #fff; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .back-link { display: inline-flex; align-items: center; gap: 6px; color: #e17055; text-decoration: none; font-size: 14px; font-weight: 500; margin-bottom: 20px; }
        .back-link:hover { text-decoration: underline; }
        .tabs { display: flex; gap: 4px; margin-bottom: 20px; background: #fff; border-radius: 10px; padding: 4px; width: fit-content; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .tab-btn { padding: 10px 20px; border: none; background: none; border-radius: 8px; font-size: 14px; font-weight: 600; color: #666; cursor: pointer; font-family: 'Poppins', sans-serif; }
        .tab-btn.active { background: linear-gradient(135deg, #e17055, #d63031); color: #fff; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
        .card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 8px; font-size: 13px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
        th { color: #888; font-weight: 600; font-size: 11.5px; text-transform: uppercase; }
        .status-badge { font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 20px; display: inline-block; }
        .status-badge.new { background: #e5e7eb; color: #374151; }
        .status-badge.contacted { background: #fef3c7; color: #92400e; }
        .status-badge.confirmed { background: #d1fae5; color: #065f46; }
        .status-badge.completed { background: #dbeafe; color: #1e40af; }
        .status-badge.cancelled { background: #fee2e2; color: #991b1b; }
        .status-select { padding: 5px 8px; border-radius: 6px; border: 1px solid #ddd; font-size: 12px; font-family: 'Poppins', sans-serif; }
        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.4; }
        .loading { text-align: center; padding: 40px; color: #999; }
        .toast { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); padding: 12px 24px; border-radius: 12px; color: #fff; font-size: 14px; font-weight: 500; z-index: 99999; box-shadow: 0 4px 20px rgba(0,0,0,0.3); display: none; font-family: 'Poppins', sans-serif; }
        .toast.success { background: #10b981; }
        .toast.error { background: #ef4444; }
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: #fff; border-radius: 16px; max-width: 460px; width: 100%; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid #e5e7eb; }
        .modal-header h2 { font-size: 18px; font-weight: 700; margin: 0; }
        .modal-close { width: 32px; height: 32px; border-radius: 8px; border: none; background: #f5f5f5; cursor: pointer; font-size: 20px; color: #666; }
        .modal-body { padding: 24px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333; }
        .form-group input { width: 100%; padding: 10px 14px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        .toggle-wrapper { display: flex; align-items: center; gap: 10px; }
        .toggle { position: relative; width: 44px; height: 24px; }
        .toggle input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; inset: 0; background: #ccc; border-radius: 24px; transition: 0.3s; }
        .toggle-slider::before { content: ''; position: absolute; width: 20px; height: 20px; left: 2px; bottom: 2px; background: #fff; border-radius: 50%; transition: 0.3s; }
        .toggle input:checked + .toggle-slider { background: #10b981; }
        .toggle input:checked + .toggle-slider::before { transform: translateX(20px); }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link"><i class="fa fa-arrow-left"></i> Back to Dashboard</a>

        <div class="header">
            <div>
                <h1><i class="fa fa-champagne-glasses" style="color:#e17055"></i> Catering / Bulk Orders</h1>
                <p>Price tiers for events, and incoming catering requests</p>
            </div>
            <div class="header-actions" id="tiersHeaderActions">
                <button class="btn btn-primary" onclick="openTierModal()"><i class="fa fa-plus"></i> Add Price Tier</button>
            </div>
        </div>

        <div class="tabs">
            <button class="tab-btn active" id="tabTiersBtn" onclick="switchTab('tiers')">Price Tiers</button>
            <button class="tab-btn" id="tabRequestsBtn" onclick="switchTab('requests')">Requests</button>
        </div>

        <div class="tab-panel active" id="tiersPanel">
            <div class="card">
                <table>
                    <thead><tr><th>Tier</th><th>Guest Range</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody id="tiersBody"><tr><td colspan="5" class="loading"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
                </table>
            </div>
        </div>

        <div class="tab-panel" id="requestsPanel">
            <div class="card">
                <table>
                    <thead><tr><th>Contact</th><th>Event Date</th><th>Guests</th><th>Tier / Quote</th><th>Notes</th><th>Status</th></tr></thead>
                    <tbody id="requestsBody"><tr><td colspan="6" class="loading"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add/Edit Tier Modal -->
    <div class="modal-overlay" id="tierModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2 id="tierModalTitle">Add Price Tier</h2>
                <button class="modal-close" onclick="closeTierModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="tierForm" onsubmit="return saveTier(event)">
                    <input type="hidden" id="tierId" value="">
                    <div class="form-group"><label>Tier Label *</label><input type="text" id="tierLabel" required placeholder="e.g. Up to 20 people"></div>
                    <div class="form-row">
                        <div class="form-group"><label>Min Guests *</label><input type="number" id="minGuests" min="1" required></div>
                        <div class="form-group"><label>Max Guests *</label><input type="number" id="maxGuests" min="1" required></div>
                    </div>
                    <div class="form-group"><label>Price (<?php echo htmlspecialchars($currency_symbol); ?>) *</label><input type="number" id="tierPrice" step="0.01" min="0" required></div>
                    <div class="form-group">
                        <label>Status</label>
                        <div class="toggle-wrapper">
                            <label class="toggle"><input type="checkbox" id="tierActive" checked><span class="toggle-slider"></span></label>
                            <span id="tierStatusLabel">Active</span>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeTierModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveTierBtn"><i class="fa fa-save"></i> Save Tier</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        var API_URL = '../controllers/catering_operations.php';
        var CURRENCY = <?php echo json_encode($currency_symbol, JSON_UNESCAPED_UNICODE); ?>;
        var statusLabels = { new: 'New', contacted: 'Contacted', confirmed: 'Confirmed', completed: 'Completed', cancelled: 'Cancelled' };

        function showToast(msg, type) {
            var t = document.getElementById('toast');
            t.textContent = msg; t.className = 'toast ' + type; t.style.display = 'block';
            setTimeout(function() { t.style.display = 'none'; }, 3000);
        }
        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
        }

        function switchTab(tab) {
            document.getElementById('tabTiersBtn').classList.toggle('active', tab === 'tiers');
            document.getElementById('tabRequestsBtn').classList.toggle('active', tab === 'requests');
            document.getElementById('tiersPanel').classList.toggle('active', tab === 'tiers');
            document.getElementById('requestsPanel').classList.toggle('active', tab === 'requests');
            document.getElementById('tiersHeaderActions').style.display = tab === 'tiers' ? '' : 'none';
            if (tab === 'requests') loadRequests();
        }

        /* ---------- Tiers ---------- */
        function loadTiers() {
            fetch('../api/get_catering_requests.php')
                .then(function(r) { return r.json(); })
                .then(function(data) { renderTiers(data.success ? (data.tiers || []) : []); })
                .catch(function() { document.getElementById('tiersBody').innerHTML = '<tr><td colspan="5" class="empty-state"><i class="fa fa-exclamation-circle"></i></td></tr>'; });
        }

        function renderTiers(tiers) {
            var body = document.getElementById('tiersBody');
            if (!tiers.length) { body.innerHTML = '<tr><td colspan="5" class="empty-state"><i class="fa fa-champagne-glasses"></i><h3>No price tiers yet</h3></td></tr>'; return; }
            var html = '';
            tiers.forEach(function(t) {
                html += '<tr>';
                html += '<td><b>' + escapeHtml(t.tier_label) + '</b></td>';
                html += '<td>' + t.min_guests + ' - ' + t.max_guests + ' guests</td>';
                html += '<td>' + CURRENCY + parseFloat(t.price).toFixed(2) + '</td>';
                html += '<td>' + (t.is_active == 1 ? '<span class="status-badge confirmed">Active</span>' : '<span class="status-badge cancelled">Hidden</span>') + '</td>';
                html += '<td>';
                html += '<button class="btn btn-edit btn-sm" onclick=\'editTier(' + JSON.stringify(t) + ')\'><i class="fa fa-edit"></i></button> ';
                html += '<button class="btn btn-delete btn-sm" onclick="deleteTier(' + t.id + ')"><i class="fa fa-trash"></i></button>';
                html += '</td></tr>';
            });
            body.innerHTML = html;
        }

        function openTierModal() {
            document.getElementById('tierModalTitle').textContent = 'Add Price Tier';
            document.getElementById('tierForm').reset();
            document.getElementById('tierId').value = '';
            document.getElementById('tierActive').checked = true;
            document.getElementById('tierStatusLabel').textContent = 'Active';
            document.getElementById('tierModal').classList.add('active');
        }
        function closeTierModal() { document.getElementById('tierModal').classList.remove('active'); }
        function editTier(t) {
            document.getElementById('tierModalTitle').textContent = 'Edit Price Tier';
            document.getElementById('tierId').value = t.id;
            document.getElementById('tierLabel').value = t.tier_label;
            document.getElementById('minGuests').value = t.min_guests;
            document.getElementById('maxGuests').value = t.max_guests;
            document.getElementById('tierPrice').value = t.price;
            document.getElementById('tierActive').checked = t.is_active == 1;
            document.getElementById('tierStatusLabel').textContent = t.is_active == 1 ? 'Active' : 'Hidden';
            document.getElementById('tierModal').classList.add('active');
        }
        document.getElementById('tierActive').addEventListener('change', function() {
            document.getElementById('tierStatusLabel').textContent = this.checked ? 'Active' : 'Hidden';
        });

        function saveTier(e) {
            e.preventDefault();
            var id = document.getElementById('tierId').value;
            var isEdit = id !== '';
            var fd = new FormData();
            fd.append('action', isEdit ? 'update_tier' : 'add_tier');
            fd.append('tierLabel', document.getElementById('tierLabel').value.trim());
            fd.append('minGuests', document.getElementById('minGuests').value);
            fd.append('maxGuests', document.getElementById('maxGuests').value);
            fd.append('price', document.getElementById('tierPrice').value);
            fd.append('isActive', document.getElementById('tierActive').checked ? 1 : 0);
            if (isEdit) fd.append('tierId', id);

            var btn = document.getElementById('saveTierBtn');
            btn.disabled = true;
            fetch(API_URL, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) { showToast(data.message, 'success'); closeTierModal(); loadTiers(); }
                    else showToast(data.message || 'Failed to save', 'error');
                })
                .catch(function() { showToast('Network error', 'error'); })
                .finally(function() { btn.disabled = false; });
            return false;
        }

        function deleteTier(id) {
            if (!confirm('Delete this price tier?')) return;
            var fd = new FormData();
            fd.append('action', 'delete_tier');
            fd.append('tierId', id);
            fetch(API_URL, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) { showToast('Tier deleted', 'success'); loadTiers(); }
                    else showToast(data.message || 'Failed to delete', 'error');
                })
                .catch(function() { showToast('Network error', 'error'); });
        }

        /* ---------- Requests ---------- */
        function loadRequests() {
            fetch('../api/get_catering_requests.php')
                .then(function(r) { return r.json(); })
                .then(function(data) { renderRequests(data.success ? (data.data || []) : []); })
                .catch(function() { document.getElementById('requestsBody').innerHTML = '<tr><td colspan="6" class="empty-state"><i class="fa fa-exclamation-circle"></i></td></tr>'; });
        }

        function renderRequests(reqs) {
            var body = document.getElementById('requestsBody');
            if (!reqs.length) { body.innerHTML = '<tr><td colspan="6" class="empty-state"><i class="fa fa-inbox"></i><h3>No catering requests yet</h3></td></tr>'; return; }
            var html = '';
            reqs.forEach(function(r) {
                html += '<tr>';
                html += '<td><b>' + escapeHtml(r.contact_name) + '</b><br><span style="color:#999">' + escapeHtml(r.contact_phone) + '</span></td>';
                html += '<td>' + escapeHtml(r.event_date) + '</td>';
                html += '<td>' + r.guest_count + '</td>';
                html += '<td>' + (r.tier_label ? escapeHtml(r.tier_label) : '-') + (r.quoted_price ? '<br>' + CURRENCY + parseFloat(r.quoted_price).toFixed(2) : '') + '</td>';
                html += '<td style="max-width:180px;">' + escapeHtml(r.notes || '-') + '</td>';
                html += '<td><select class="status-select" onchange="updateRequestStatus(' + r.id + ', this.value)">';
                Object.keys(statusLabels).forEach(function(s) {
                    html += '<option value="' + s + '"' + (s === r.status ? ' selected' : '') + '>' + statusLabels[s] + '</option>';
                });
                html += '</select></td>';
                html += '</tr>';
            });
            body.innerHTML = html;
        }

        function updateRequestStatus(id, status) {
            var fd = new FormData();
            fd.append('action', 'update_request_status');
            fd.append('requestId', id);
            fd.append('status', status);
            fetch(API_URL, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) showToast('Status updated', 'success');
                    else showToast(data.message || 'Failed to update', 'error');
                })
                .catch(function() { showToast('Network error', 'error'); });
        }

        document.getElementById('tierModal').addEventListener('click', function(e) { if (e.target === this) closeTierModal(); });

        loadTiers();
    </script>
</body>
</html>
