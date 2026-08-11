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

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/meal_subscription_schema.php';
if (!mealSubscriptionsFeatureEnabled(getConnection(), $restaurant_id)) {
    http_response_code(403);
    exit('This feature is not enabled for your account. Contact support to enable Meal Subscriptions.');
}

try {
    $conn = getConnection();
    ensureMealSubscriptionTables($conn);
} catch (Exception $e) {
    // Ignore - the read/write endpoints self-heal on their own too
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meal Subscriptions - <?php echo htmlspecialchars($restaurant_name); ?></title>
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

        .btn { padding: 10px 20px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: 'Poppins', sans-serif; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: linear-gradient(135deg, #e17055, #d63031); color: #fff; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(225,112,85,0.3); }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-pause { background: #fef3c7; color: #92400e; }
        .btn-resume { background: #d1fae5; color: #065f46; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .back-link { display: inline-flex; align-items: center; gap: 6px; color: #e17055; text-decoration: none; font-size: 14px; font-weight: 500; margin-bottom: 20px; }
        .back-link:hover { text-decoration: underline; }

        .card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .toolbar { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
        .toolbar input, .toolbar select { padding: 9px 12px; border: 1.5px solid #e0e0e0; border-radius: 8px; font-size: 13px; font-family: 'Poppins', sans-serif; }
        .toolbar input { flex: 1; min-width: 180px; }

        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 8px; font-size: 13px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
        th { color: #888; font-weight: 600; font-size: 11.5px; text-transform: uppercase; }

        .status-badge { font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 20px; display: inline-block; }
        .status-badge.active { background: #d1fae5; color: #065f46; }
        .status-badge.paused { background: #fef3c7; color: #92400e; }
        .status-badge.pending_payment { background: #e5e7eb; color: #374151; }
        .status-badge.completed { background: #dbeafe; color: #1e40af; }
        .status-badge.cancelled { background: #fee2e2; color: #991b1b; }

        .credit-cell b { color: #1a1b1f; }
        .skip-chip { background: #f3f4f6; color: #374151; padding: 2px 8px; border-radius: 10px; font-size: 10.5px; display: inline-block; margin: 2px 3px 0 0; }

        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.4; }
        .loading { text-align: center; padding: 40px; color: #999; }

        .toast { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); padding: 12px 24px; border-radius: 12px; color: #fff; font-size: 14px; font-weight: 500; z-index: 99999; box-shadow: 0 4px 20px rgba(0,0,0,0.3); display: none; font-family: 'Poppins', sans-serif; }
        .toast.success { background: #10b981; }
        .toast.error { background: #ef4444; }

        .gen-result { margin-top: 12px; padding: 10px 14px; border-radius: 8px; background: #f0f7f5; font-size: 12.5px; color: #1a3934; display: none; }

        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: #fff; border-radius: 16px; max-width: 460px; width: 100%; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid #e5e7eb; }
        .modal-header h2 { font-size: 18px; font-weight: 700; margin: 0; }
        .modal-close { width: 32px; height: 32px; border-radius: 8px; border: none; background: #f5f5f5; cursor: pointer; font-size: 20px; color: #666; }
        .modal-body { padding: 24px; }
        .form-group { margin-bottom: 16px; position: relative; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333; }
        .form-group input, .form-group select { width: 100%; padding: 10px 14px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        .form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        .cust-search-results { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; max-height: 180px; overflow-y: auto; z-index: 20; box-shadow: 0 4px 12px rgba(0,0,0,0.1); display: none; }
        .cust-search-results.active { display: block; }
        .cust-result-row { padding: 8px 12px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #f5f5f5; }
        .cust-result-row:hover { background: #faf8f6; }
        .cust-result-row:last-child { border-bottom: none; }
        .selected-customer-chip { display: none; align-items: center; justify-content: space-between; padding: 8px 12px; background: #f0f7f5; border-radius: 8px; font-size: 13px; margin-top: 6px; }
        .selected-customer-chip.active { display: flex; }
        .selected-customer-chip .remove-cust { color: #ef4444; cursor: pointer; font-weight: 700; }
        .checkbox-row { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #555; }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link"><i class="fa fa-arrow-left"></i> Back to Dashboard</a>

        <div class="header">
            <div>
                <h1><i class="fa fa-users" style="color:#e17055"></i> Meal Subscriptions</h1>
                <p>View and manage customer tiffin subscriptions</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="openNewSubModal()"><i class="fa fa-plus"></i> New Subscription</button>
                <button class="btn btn-primary" id="generateTodayBtn" onclick="generateToday()"><i class="fa fa-truck"></i> Generate Today's Deliveries</button>
            </div>
        </div>

        <div class="card">
            <div class="gen-result" id="genResult"></div>
            <div class="toolbar">
                <input type="text" id="searchInput" placeholder="Search by customer name or phone..." oninput="debouncedLoad()">
                <select id="statusFilter" onchange="loadSubscriptions()">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="paused">Paused</option>
                    <option value="pending_payment">Payment Pending</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div style="overflow-x:auto;">
                <table id="subTable">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Plan</th>
                            <th>Credits</th>
                            <th>Status</th>
                            <th>Upcoming Skips</th>
                            <th>Delivery Address</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="subTableBody">
                        <tr><td colspan="7" class="loading"><i class="fa fa-spinner fa-spin"></i> Loading subscriptions...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- New Subscription Modal -->
    <div class="modal-overlay" id="newSubModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2>New Subscription</h2>
                <button class="modal-close" onclick="closeNewSubModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="newSubForm" onsubmit="return submitNewSub(event)">
                    <div class="form-group">
                        <label>Customer *</label>
                        <input type="text" id="custSearchInput" placeholder="Search by name or phone..." autocomplete="off" oninput="onCustSearchInput()">
                        <div class="cust-search-results" id="custSearchResults"></div>
                        <div class="selected-customer-chip" id="selectedCustChip">
                            <span id="selectedCustLabel"></span>
                            <span class="remove-cust" onclick="clearSelectedCustomer()">&times;</span>
                        </div>
                        <input type="hidden" id="selectedCustomerId" value="">
                    </div>

                    <div class="form-group">
                        <label>Plan *</label>
                        <select id="newSubPlan" required>
                            <option value="">Select a plan...</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Delivery Phone *</label>
                        <input type="text" id="newSubPhone" required placeholder="Delivery contact number">
                    </div>

                    <div class="form-group">
                        <label>Delivery Address *</label>
                        <input type="text" id="newSubAddress" required placeholder="Full delivery address">
                    </div>

                    <div class="form-group">
                        <label>Payment Method</label>
                        <select id="newSubPayment">
                            <option value="Cash">Cash</option>
                            <option value="QR Payment">QR Payment</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-row"><input type="checkbox" id="newSubMarkPaid" checked style="width:auto;"> Mark as paid now (staff confirmed payment in person)</label>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeNewSubModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveNewSubBtn"><i class="fa fa-save"></i> Create Subscription</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        var API_URL = '../controllers/meal_subscription_admin_operations.php';
        var CURRENCY = <?php echo json_encode($currency_symbol, JSON_UNESCAPED_UNICODE); ?>;
        var scopeLabels = { lunch: 'Lunch Only', dinner: 'Dinner Only', both: 'Lunch + Dinner' };
        var statusLabels = { active: 'Active', paused: 'Paused', pending_payment: 'Payment Pending', completed: 'Completed', cancelled: 'Cancelled' };
        var debounceTimer = null;

        function showToast(msg, type) {
            var t = document.getElementById('toast');
            t.textContent = msg;
            t.className = 'toast ' + type;
            t.style.display = 'block';
            setTimeout(function() { t.style.display = 'none'; }, 3000);
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
        }

        function debouncedLoad() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(loadSubscriptions, 300);
        }

        function loadSubscriptions() {
            var body = document.getElementById('subTableBody');
            body.innerHTML = '<tr><td colspan="7" class="loading"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>';

            var search = document.getElementById('searchInput').value.trim();
            var status = document.getElementById('statusFilter').value;
            var url = '../api/get_meal_subscriptions.php?search=' + encodeURIComponent(search) + '&status=' + encodeURIComponent(status);

            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        renderTable(data.data || []);
                    } else {
                        body.innerHTML = '<tr><td colspan="7" class="empty-state"><i class="fa fa-exclamation-circle"></i><h3>Failed to load</h3></td></tr>';
                    }
                })
                .catch(function() {
                    body.innerHTML = '<tr><td colspan="7" class="empty-state"><i class="fa fa-exclamation-circle"></i><h3>Error loading subscriptions</h3></td></tr>';
                });
        }

        function renderTable(subs) {
            var body = document.getElementById('subTableBody');
            if (!subs.length) {
                body.innerHTML = '<tr><td colspan="7" class="empty-state"><i class="fa fa-utensils"></i><h3>No subscriptions found</h3></td></tr>';
                return;
            }

            var html = '';
            subs.forEach(function(s) {
                html += '<tr id="sub-row-' + s.id + '">';
                html += '<td><b>' + escapeHtml(s.customer_name) + '</b><br><span style="color:#999">' + escapeHtml(s.phone) + '</span></td>';
                html += '<td>' + escapeHtml(s.plan_name_snapshot) + '<br><span style="color:#999">' + (scopeLabels[s.meal_scope_snapshot] || s.meal_scope_snapshot) + '</span></td>';
                html += '<td class="credit-cell"><b>' + s.credits_remaining + '</b> / ' + s.credits_total + ' left</td>';
                html += '<td><span class="status-badge ' + s.status + '">' + (statusLabels[s.status] || s.status) + '</span></td>';
                html += '<td>';
                (s.skip_dates || []).forEach(function(d) { html += '<span class="skip-chip">' + escapeHtml(d) + '</span>'; });
                if (!s.skip_dates || !s.skip_dates.length) html += '<span style="color:#ccc">-</span>';
                html += '</td>';
                html += '<td style="max-width:200px;">' + escapeHtml(s.delivery_address || '-') + '</td>';
                html += '<td>';
                if (s.status === 'active') {
                    html += '<button class="btn btn-pause btn-sm" onclick="pauseSub(' + s.id + ')"><i class="fa fa-pause"></i> Pause</button>';
                } else if (s.status === 'paused') {
                    html += '<button class="btn btn-resume btn-sm" onclick="resumeSub(' + s.id + ')"><i class="fa fa-play"></i> Resume</button>';
                } else {
                    html += '<span style="color:#ccc">-</span>';
                }
                html += '</td>';
                html += '</tr>';
            });
            body.innerHTML = html;
        }

        function pauseSub(id) { setSubAction(id, 'pause_subscription'); }
        function resumeSub(id) { setSubAction(id, 'resume_subscription'); }

        function setSubAction(id, action) {
            var fd = new FormData();
            fd.append('action', action);
            fd.append('subscriptionId', id);
            fetch(API_URL, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        loadSubscriptions();
                    } else {
                        showToast(data.message || 'Action failed', 'error');
                    }
                })
                .catch(function() { showToast('Network error. Please try again.', 'error'); });
        }

        function generateToday() {
            var btn = document.getElementById('generateTodayBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Generating...';

            var fd = new FormData();
            fd.append('action', 'generate_today');
            fetch(API_URL, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var resultEl = document.getElementById('genResult');
                    if (data.success) {
                        var r = data.result;
                        resultEl.style.display = 'block';
                        resultEl.textContent = 'Generated: ' + r.generated + ' | Skipped (paused date): ' + r.skipped_date + ' | Already generated: ' + r.skipped_already_generated + ' | No menu configured: ' + r.skipped_no_menu + ' | Errors: ' + r.errors;
                        showToast(data.message, 'success');
                        loadSubscriptions();
                    } else {
                        showToast(data.message || 'Failed to generate deliveries', 'error');
                    }
                })
                .catch(function() { showToast('Network error. Please try again.', 'error'); })
                .finally(function() {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa fa-truck"></i> Generate Today\'s Deliveries';
                });
        }

        /* ---------- New Subscription modal ---------- */
        var custSearchTimer = null;

        function openNewSubModal() {
            document.getElementById('newSubForm').reset();
            clearSelectedCustomer();
            document.getElementById('custSearchResults').classList.remove('active');
            document.getElementById('newSubMarkPaid').checked = true;

            var planSelect = document.getElementById('newSubPlan');
            planSelect.innerHTML = '<option value="">Loading plans...</option>';
            fetch('../api/get_meal_plans.php')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var plans = data.success ? (data.data || []) : [];
                    if (!plans.length) {
                        planSelect.innerHTML = '<option value="">No plans configured yet</option>';
                        return;
                    }
                    var html = '<option value="">Select a plan...</option>';
                    plans.forEach(function(p) {
                        html += '<option value="' + p.id + '">' + escapeHtml(p.plan_name) + ' - ' + CURRENCY + parseFloat(p.price).toFixed(2) + ' (' + p.total_meal_credits + ' meals)</option>';
                    });
                    planSelect.innerHTML = html;
                })
                .catch(function() { planSelect.innerHTML = '<option value="">Failed to load plans</option>'; });

            document.getElementById('newSubModal').classList.add('active');
        }

        function closeNewSubModal() {
            document.getElementById('newSubModal').classList.remove('active');
        }

        function onCustSearchInput() {
            clearTimeout(custSearchTimer);
            var q = document.getElementById('custSearchInput').value.trim();
            var resultsEl = document.getElementById('custSearchResults');
            if (q.length < 2) {
                resultsEl.classList.remove('active');
                resultsEl.innerHTML = '';
                return;
            }
            custSearchTimer = setTimeout(function() {
                var fd = new FormData();
                fd.append('action', 'search_customers');
                fd.append('search', q);
                fetch(API_URL, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        var customers = data.success ? (data.data || []) : [];
                        if (!customers.length) {
                            resultsEl.innerHTML = '<div class="cust-result-row" style="color:#999;">No customers found</div>';
                            resultsEl.classList.add('active');
                            return;
                        }
                        var html = '';
                        customers.forEach(function(c) {
                            html += '<div class="cust-result-row" onclick=\'selectCustomer(' + JSON.stringify(c) + ')\'>' +
                                '<b>' + escapeHtml(c.customer_name) + '</b> &middot; ' + escapeHtml(c.phone) + '</div>';
                        });
                        resultsEl.innerHTML = html;
                        resultsEl.classList.add('active');
                    })
                    .catch(function() {});
            }, 250);
        }

        function selectCustomer(c) {
            document.getElementById('selectedCustomerId').value = c.id;
            document.getElementById('selectedCustLabel').textContent = c.customer_name + ' (' + c.phone + ')';
            document.getElementById('selectedCustChip').classList.add('active');
            document.getElementById('custSearchInput').value = '';
            document.getElementById('custSearchResults').classList.remove('active');
            document.getElementById('custSearchResults').innerHTML = '';
            if (!document.getElementById('newSubPhone').value) {
                document.getElementById('newSubPhone').value = c.phone || '';
            }
        }

        function clearSelectedCustomer() {
            document.getElementById('selectedCustomerId').value = '';
            document.getElementById('selectedCustChip').classList.remove('active');
        }

        function submitNewSub(e) {
            e.preventDefault();
            var customerId = document.getElementById('selectedCustomerId').value;
            var planId = document.getElementById('newSubPlan').value;
            var phone = document.getElementById('newSubPhone').value.trim();
            var address = document.getElementById('newSubAddress').value.trim();

            if (!customerId) { showToast('Please select an existing customer', 'error'); return false; }
            if (!planId) { showToast('Please choose a plan', 'error'); return false; }
            if (!phone || !address) { showToast('Delivery phone and address are required', 'error'); return false; }

            var btn = document.getElementById('saveNewSubBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Creating...';

            var fd = new FormData();
            fd.append('action', 'admin_create_subscription');
            fd.append('customerId', customerId);
            fd.append('mealPlanId', planId);
            fd.append('deliveryPhone', phone);
            fd.append('deliveryAddress', address);
            fd.append('paymentMethod', document.getElementById('newSubPayment').value);
            fd.append('markPaid', document.getElementById('newSubMarkPaid').checked ? 1 : 0);

            fetch(API_URL, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closeNewSubModal();
                        loadSubscriptions();
                    } else {
                        showToast(data.message || 'Failed to create subscription', 'error');
                    }
                })
                .catch(function() { showToast('Network error. Please try again.', 'error'); })
                .finally(function() {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa fa-save"></i> Create Subscription';
                });
            return false;
        }

        document.getElementById('newSubModal').addEventListener('click', function(e) { if (e.target === this) closeNewSubModal(); });
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#custSearchInput') && !e.target.closest('#custSearchResults')) {
                document.getElementById('custSearchResults').classList.remove('active');
            }
        });

        loadSubscriptions();
    </script>
</body>
</html>
