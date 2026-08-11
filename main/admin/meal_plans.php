<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

if (!isSessionValid() || !isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['restaurant_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/../config/authorization_config.php';
requirePermission(PERMISSION_MANAGE_MENU);

$restaurant_id = $_SESSION['restaurant_id'];
$restaurant_name = $_SESSION['restaurant_name'] ?? 'Restaurant';
$currency_symbol = $_SESSION['currency_symbol'] ?? '₹';

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/meal_subscription_schema.php';

// Customer-facing subscribe link base, e.g. https://host/menuwebsite/test22/subscribe
// - same slug + base-path convention main/views/dashboard.php already uses
// for its "Restaurant Website Link" (see the Copy Link feature there).
$subscribeLinkSlug = strtolower($restaurant_name);
$subscribeLinkSlug = preg_replace('/[^a-z0-9]+/', '-', $subscribeLinkSlug);
$subscribeLinkSlug = trim($subscribeLinkSlug, '-');
$subscribeLinkBaseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$subscribeLinkBasePath = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
$subscribeLinkPrefix = $subscribeLinkBaseUrl . $subscribeLinkBasePath . '/' . urlencode($subscribeLinkSlug) . '/subscribe?plan_id=';
if (!mealSubscriptionsFeatureEnabled(getConnection(), $restaurant_id)) {
    http_response_code(403);
    exit('This feature is not enabled for your account. Contact support to enable Meal Subscriptions.');
}

try {
    $conn = getConnection();
    $checkTable = $conn->query("SHOW TABLES LIKE 'meal_plans'");
    if ($checkTable->rowCount() == 0) {
        $conn->exec("CREATE TABLE IF NOT EXISTS meal_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            plan_name VARCHAR(150) NOT NULL,
            description TEXT DEFAULT NULL,
            meal_scope ENUM('lunch','dinner','both') NOT NULL DEFAULT 'both',
            total_meal_credits INT NOT NULL,
            price DECIMAL(10, 2) NOT NULL,
            bonus_credits INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_mp_restaurant (restaurant_id),
            INDEX idx_mp_active (restaurant_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $checkTable2 = $conn->query("SHOW TABLES LIKE 'meal_plan_weekly_menu'");
    if ($checkTable2->rowCount() == 0) {
        $conn->exec("CREATE TABLE IF NOT EXISTS meal_plan_weekly_menu (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            day_of_week TINYINT NOT NULL,
            meal_time ENUM('lunch','dinner') NOT NULL,
            menu_text TEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mpwm_cell (restaurant_id, day_of_week, meal_time),
            INDEX idx_mpwm_restaurant (restaurant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (Exception $e) {
    // Ignore - tables will be created by the controller on first write if this failed
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meal Plans - <?php echo htmlspecialchars($restaurant_name); ?></title>
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
        .btn-edit { background: #10b981; color: #fff; }
        .btn-edit:hover { background: #059669; }
        .btn-delete { background: #ef4444; color: #fff; }
        .btn-delete:hover { background: #dc2626; }
        .btn-secondary { background: #6b7280; color: #fff; }
        .btn-secondary:hover { background: #4b5563; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .back-link { display: inline-flex; align-items: center; gap: 6px; color: #e17055; text-decoration: none; font-size: 14px; font-weight: 500; margin-bottom: 20px; }
        .back-link:hover { text-decoration: underline; }

        .tabs { display: flex; gap: 4px; margin-bottom: 20px; background: #fff; border-radius: 10px; padding: 4px; width: fit-content; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .tab-btn { padding: 10px 20px; border: none; background: none; border-radius: 8px; font-size: 14px; font-weight: 600; color: #666; cursor: pointer; font-family: 'Poppins', sans-serif; }
        .tab-btn.active { background: linear-gradient(135deg, #e17055, #d63031); color: #fff; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        .card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }

        .plans-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 10px; }
        .plan-card { background: #fff; border-radius: 12px; border: 2px solid #e5e7eb; padding: 18px; transition: all 0.2s; }
        .plan-card:hover { border-color: #e17055; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .plan-card.inactive { opacity: 0.55; }
        .plan-name { font-size: 17px; font-weight: 700; color: #1a1b1f; margin-bottom: 4px; }
        .plan-desc { font-size: 12px; color: #888; margin-bottom: 10px; min-height: 16px; }
        .plan-price { font-size: 22px; font-weight: 800; color: #e17055; }
        .plan-meta { font-size: 12px; color: #666; margin-top: 4px; display: flex; flex-wrap: wrap; gap: 6px; }
        .plan-tag { background: #f0ebe5; color: #8a4a2f; padding: 3px 10px; border-radius: 20px; font-weight: 600; }
        .plan-tag.bonus { background: #fef3c7; color: #92400e; }
        .plan-status { font-size: 12px; font-weight: 500; padding: 3px 10px; border-radius: 20px; display: inline-block; margin-top: 8px; }
        .plan-status.available { background: #d1fae5; color: #065f46; }
        .plan-status.unavailable { background: #fee2e2; color: #991b1b; }
        .plan-actions { display: flex; gap: 8px; margin-top: 14px; }
        .plan-actions .btn { flex: 1; padding: 8px 12px; font-size: 13px; justify-content: center; }

        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.4; }
        .empty-state h3 { color: #999; margin-bottom: 8px; }

        .toast { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); padding: 12px 24px; border-radius: 12px; color: #fff; font-size: 14px; font-weight: 500; z-index: 99999; box-shadow: 0 4px 20px rgba(0,0,0,0.3); display: none; font-family: 'Poppins', sans-serif; }
        .toast.success { background: #10b981; }
        .toast.error { background: #ef4444; }

        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: #fff; border-radius: 16px; max-width: 500px; width: 100%; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid #e5e7eb; }
        .modal-header h2 { font-size: 18px; font-weight: 700; color: #1a1b1f; margin: 0; }
        .modal-close { width: 32px; height: 32px; border-radius: 8px; border: none; background: #f5f5f5; cursor: pointer; font-size: 20px; color: #666; display: flex; align-items: center; justify-content: center; }
        .modal-body { padding: 24px; }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 14px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; font-family: 'Poppins', sans-serif; outline: none; transition: border-color 0.2s; box-sizing: border-box; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #e17055; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

        .toggle-wrapper { display: flex; align-items: center; gap: 10px; }
        .toggle { position: relative; width: 44px; height: 24px; cursor: pointer; }
        .toggle input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; inset: 0; background: #ccc; border-radius: 24px; transition: 0.3s; }
        .toggle-slider::before { content: ''; position: absolute; width: 20px; height: 20px; left: 2px; bottom: 2px; background: #fff; border-radius: 50%; transition: 0.3s; }
        .toggle input:checked + .toggle-slider { background: #10b981; }
        .toggle input:checked + .toggle-slider::before { transform: translateX(20px); }

        .form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        .loading { text-align: center; padding: 40px; color: #999; }

        /* Weekly menu grid */
        .week-grid { display: grid; grid-template-columns: 90px 1fr 1fr; gap: 10px; margin-top: 10px; }
        .week-grid .head-cell { font-weight: 700; font-size: 13px; color: #666; padding: 8px 4px; }
        .week-grid .day-label { font-weight: 700; font-size: 13px; color: #1a1b1f; display: flex; align-items: center; padding: 6px 4px; }
        .week-cell { background: #faf8f6; border: 2px solid #e8e0d8; border-radius: 10px; padding: 8px; }
        .week-cell textarea { width: 100%; min-height: 70px; border: none; background: transparent; font-family: 'Poppins', sans-serif; font-size: 12.5px; resize: vertical; outline: none; }
        .week-cell .cell-save { margin-top: 6px; width: 100%; }
        .week-cell.dirty { border-color: #e17055; }
        .week-cell .cell-status { font-size: 10px; color: #10b981; margin-top: 4px; display: none; }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link"><i class="fa fa-arrow-left"></i> Back to Dashboard</a>

        <div class="header">
            <div>
                <h1><i class="fa fa-utensils" style="color:#e17055"></i> Meal Plans (Tiffin Subscriptions)</h1>
                <p>Define subscription bundles and the weekly menu customers get on each plan</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" id="addPlanBtn" onclick="openPlanModal()"><i class="fa fa-plus"></i> Add New Plan</button>
            </div>
        </div>

        <div class="tabs">
            <button class="tab-btn active" id="tabPlansBtn" onclick="switchTab('plans')">Plans</button>
            <button class="tab-btn" id="tabMenuBtn" onclick="switchTab('menu')">Weekly Menu</button>
        </div>

        <div class="tab-panel active" id="plansPanel">
            <div class="card">
                <div class="plans-grid" id="plansGrid">
                    <div class="loading"><i class="fa fa-spinner fa-spin"></i> Loading plans...</div>
                </div>
            </div>
        </div>

        <div class="tab-panel" id="menuPanel">
            <div class="card">
                <p style="color:#666;font-size:13px;margin-bottom:10px;">One weekly menu is shared by all your plans - write what a customer gets each day. Leave a cell blank and save to clear it.</p>
                <div class="week-grid" id="weekGrid">
                    <div class="loading"><i class="fa fa-spinner fa-spin"></i> Loading weekly menu...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Plan Modal -->
    <div class="modal-overlay" id="planModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2 id="modalTitle">Add New Plan</h2>
                <button class="modal-close" onclick="closePlanModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="planForm" onsubmit="return savePlan(event)">
                    <input type="hidden" id="planId" value="">

                    <div class="form-group">
                        <label>Plan Name *</label>
                        <input type="text" id="planName" required placeholder="e.g. 30 Meals Monthly">
                    </div>

                    <div class="form-group">
                        <label>Description (optional)</label>
                        <textarea id="planDescription" rows="2" placeholder="Shown to customers when browsing plans"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Meal Scope *</label>
                            <select id="planMealScope" required>
                                <option value="both">Lunch + Dinner</option>
                                <option value="lunch">Lunch Only</option>
                                <option value="dinner">Dinner Only</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Total Meal Credits *</label>
                            <input type="number" id="planTotalCredits" min="1" required placeholder="30">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Price (<?php echo htmlspecialchars($currency_symbol); ?>) *</label>
                            <input type="number" id="planPrice" step="0.01" min="0" required placeholder="3000">
                        </div>
                        <div class="form-group">
                            <label>Bonus Credits (optional)</label>
                            <input type="number" id="planBonusCredits" min="0" value="0" placeholder="e.g. 3 free meals">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <div class="toggle-wrapper">
                            <label class="toggle">
                                <input type="checkbox" id="planActive" checked>
                                <span class="toggle-slider"></span>
                            </label>
                            <span id="planStatusLabel">Available for purchase</span>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closePlanModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="savePlanBtn"><i class="fa fa-save"></i> Save Plan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal-box" style="max-width:400px">
            <div class="modal-header">
                <h2>Delete Plan</h2>
                <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom:20px;color:#666">Are you sure you want to delete <strong id="deletePlanName"></strong>?</p>
                <div class="form-actions">
                    <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button class="btn btn-delete" id="confirmDeleteBtn" onclick="confirmDeletePlan()"><i class="fa fa-trash"></i> Delete</button>
                </div>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        var API_URL = '../controllers/meal_plan_operations.php';
        var CURRENCY = <?php echo json_encode($currency_symbol, JSON_UNESCAPED_UNICODE); ?>;
        var SUBSCRIBE_LINK_PREFIX = <?php echo json_encode($subscribeLinkPrefix, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        var DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        var currentDeleteId = null;
        var weeklyMenuData = {}; // key "day_mealtime" -> text

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

        function switchTab(tab) {
            document.getElementById('tabPlansBtn').classList.toggle('active', tab === 'plans');
            document.getElementById('tabMenuBtn').classList.toggle('active', tab === 'menu');
            document.getElementById('plansPanel').classList.toggle('active', tab === 'plans');
            document.getElementById('menuPanel').classList.toggle('active', tab === 'menu');
            document.getElementById('addPlanBtn').style.display = tab === 'plans' ? '' : 'none';
        }

        /* ---------- Plans ---------- */
        function loadPlans() {
            var grid = document.getElementById('plansGrid');
            grid.innerHTML = '<div class="loading"><i class="fa fa-spinner fa-spin"></i> Loading plans...</div>';

            fetch('../api/get_meal_plans.php')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        renderPlans(data.data || []);
                    } else {
                        grid.innerHTML = '<div class="empty-state"><i class="fa fa-exclamation-circle"></i><h3>Failed to load plans</h3></div>';
                    }
                })
                .catch(function() {
                    grid.innerHTML = '<div class="empty-state"><i class="fa fa-exclamation-circle"></i><h3>Error loading plans</h3></div>';
                });
        }

        var scopeLabels = { lunch: 'Lunch Only', dinner: 'Dinner Only', both: 'Lunch + Dinner' };

        function renderPlans(plans) {
            var grid = document.getElementById('plansGrid');
            if (!plans.length) {
                grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><i class="fa fa-utensils"></i><h3>No meal plans yet</h3><p>Create your first tiffin subscription bundle!</p></div>';
                return;
            }

            var html = '';
            for (var i = 0; i < plans.length; i++) {
                var p = plans[i];
                var active = p.is_active == 1;
                html += '<div class="plan-card' + (!active ? ' inactive' : '') + '" id="plan-' + p.id + '">';
                html += '<div class="plan-name">' + escapeHtml(p.plan_name) + '</div>';
                html += '<div class="plan-desc">' + escapeHtml(p.description || '') + '</div>';
                html += '<div class="plan-price">' + CURRENCY + parseFloat(p.price).toFixed(2) + '</div>';
                html += '<div class="plan-meta">';
                html += '<span class="plan-tag">' + p.total_meal_credits + ' meals</span>';
                html += '<span class="plan-tag">' + (scopeLabels[p.meal_scope] || p.meal_scope) + '</span>';
                if (p.bonus_credits > 0) html += '<span class="plan-tag bonus">+' + p.bonus_credits + ' bonus</span>';
                html += '</div>';
                html += '<span class="plan-status ' + (active ? 'available' : 'unavailable') + '">' + (active ? 'Available' : 'Hidden') + '</span>';
                html += '<div class="plan-actions">';
                html += '<button class="btn btn-edit" onclick=\'editPlan(' + JSON.stringify(p) + ')\'><i class="fa fa-edit"></i> Edit</button>';
                html += '<button class="btn btn-delete" onclick="deletePlan(' + p.id + ', \'' + escapeHtml(p.plan_name) + '\')"><i class="fa fa-trash"></i> Delete</button>';
                html += '</div>';
                html += '<button class="btn btn-secondary" style="width:100%;margin-top:8px;" onclick="copyPlanLink(' + p.id + ', this)"><i class="fa fa-link"></i> Copy Subscribe Link</button>';
                html += '</div>';
            }
            grid.innerHTML = html;
        }

        function openPlanModal() {
            document.getElementById('modalTitle').textContent = 'Add New Plan';
            document.getElementById('planId').value = '';
            document.getElementById('planForm').reset();
            document.getElementById('planActive').checked = true;
            document.getElementById('planStatusLabel').textContent = 'Available for purchase';
            document.getElementById('savePlanBtn').innerHTML = '<i class="fa fa-save"></i> Save Plan';
            document.getElementById('planModal').classList.add('active');
        }

        function closePlanModal() {
            document.getElementById('planModal').classList.remove('active');
        }

        function editPlan(p) {
            document.getElementById('modalTitle').textContent = 'Edit Plan';
            document.getElementById('planId').value = p.id;
            document.getElementById('planName').value = p.plan_name;
            document.getElementById('planDescription').value = p.description || '';
            document.getElementById('planMealScope').value = p.meal_scope;
            document.getElementById('planTotalCredits').value = p.total_meal_credits;
            document.getElementById('planPrice').value = p.price;
            document.getElementById('planBonusCredits').value = p.bonus_credits || 0;
            document.getElementById('planActive').checked = p.is_active == 1;
            document.getElementById('planStatusLabel').textContent = p.is_active == 1 ? 'Available for purchase' : 'Hidden from customers';
            document.getElementById('savePlanBtn').innerHTML = '<i class="fa fa-save"></i> Update Plan';
            document.getElementById('planModal').classList.add('active');
        }

        document.getElementById('planActive').addEventListener('change', function() {
            document.getElementById('planStatusLabel').textContent = this.checked ? 'Available for purchase' : 'Hidden from customers';
        });

        function savePlan(e) {
            e.preventDefault();

            var id = document.getElementById('planId').value;
            var isEdit = id !== '';
            var name = document.getElementById('planName').value.trim();
            var totalCredits = document.getElementById('planTotalCredits').value;
            var price = document.getElementById('planPrice').value;

            if (!name) { showToast('Please enter a plan name', 'error'); return false; }
            if (!totalCredits || parseInt(totalCredits, 10) <= 0) { showToast('Total meal credits must be greater than 0', 'error'); return false; }
            if (!price || parseFloat(price) < 0) { showToast('Please enter a valid price', 'error'); return false; }

            var btn = document.getElementById('savePlanBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

            var formData = new FormData();
            formData.append('action', isEdit ? 'update_plan' : 'add_plan');
            formData.append('planName', name);
            formData.append('description', document.getElementById('planDescription').value.trim());
            formData.append('mealScope', document.getElementById('planMealScope').value);
            formData.append('totalCredits', totalCredits);
            formData.append('price', price);
            formData.append('bonusCredits', document.getElementById('planBonusCredits').value || 0);
            formData.append('isActive', document.getElementById('planActive').checked ? 1 : 0);
            if (isEdit) formData.append('planId', id);

            fetch(API_URL, { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closePlanModal();
                        loadPlans();
                    } else {
                        showToast(data.message || 'Failed to save plan', 'error');
                    }
                })
                .catch(function() { showToast('Network error. Please try again.', 'error'); })
                .finally(function() {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa fa-save"></i> ' + (isEdit ? 'Update' : 'Save') + ' Plan';
                });

            return false;
        }

        function copyPlanLink(id, btnEl) {
            var link = SUBSCRIBE_LINK_PREFIX + id;
            var restoreLabel = '<i class="fa fa-link"></i> Copy Subscribe Link';
            var done = function() {
                if (btnEl) {
                    btnEl.innerHTML = '<i class="fa fa-check"></i> Link Copied!';
                    setTimeout(function() { btnEl.innerHTML = restoreLabel; }, 2000);
                }
                showToast('Subscribe link copied - share it with a customer and they can sign up and subscribe themselves.', 'success');
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(link).then(done).catch(function() { promptCopyFallback(link); });
            } else {
                promptCopyFallback(link);
            }
        }

        function promptCopyFallback(link) {
            window.prompt('Copy this link to share with a customer:', link);
        }

        function deletePlan(id, name) {
            currentDeleteId = id;
            document.getElementById('deletePlanName').textContent = name;
            document.getElementById('deleteModal').classList.add('active');
        }
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
            currentDeleteId = null;
        }
        function confirmDeletePlan() {
            if (!currentDeleteId) return;
            var btn = document.getElementById('confirmDeleteBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Deleting...';

            var formData = new FormData();
            formData.append('action', 'delete_plan');
            formData.append('planId', currentDeleteId);

            fetch(API_URL, { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        showToast('Plan deleted successfully', 'success');
                        closeDeleteModal();
                        loadPlans();
                    } else {
                        showToast(data.message || 'Failed to delete plan', 'error');
                    }
                })
                .catch(function() { showToast('Network error. Please try again.', 'error'); })
                .finally(function() {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa fa-trash"></i> Delete';
                });
        }

        /* ---------- Weekly Menu ---------- */
        function loadWeeklyMenu() {
            var grid = document.getElementById('weekGrid');
            grid.innerHTML = '<div class="loading"><i class="fa fa-spinner fa-spin"></i> Loading weekly menu...</div>';

            fetch('../api/get_meal_plans.php?include_menu=1')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    weeklyMenuData = {};
                    if (data.success && data.weekly_menu) {
                        data.weekly_menu.forEach(function(row) {
                            weeklyMenuData[row.day_of_week + '_' + row.meal_time] = row.menu_text;
                        });
                    }
                    renderWeekGrid();
                })
                .catch(function() {
                    grid.innerHTML = '<div class="empty-state"><i class="fa fa-exclamation-circle"></i><h3>Failed to load weekly menu</h3></div>';
                });
        }

        function renderWeekGrid() {
            var grid = document.getElementById('weekGrid');
            var html = '<div class="head-cell"></div><div class="head-cell">☀️ Lunch</div><div class="head-cell">🌙 Dinner</div>';
            for (var d = 0; d < 7; d++) {
                html += '<div class="day-label">' + DAY_NAMES[d] + '</div>';
                ['lunch', 'dinner'].forEach(function(mt) {
                    var key = d + '_' + mt;
                    var text = weeklyMenuData[key] || '';
                    html += '<div class="week-cell" id="cell-' + key + '">' +
                        '<textarea id="ta-' + key + '" oninput="markDirty(' + d + ',\'' + mt + '\')" placeholder="e.g. Dal, Roti, Rice, Sabzi, Pickle, Salad">' + escapeHtml(text) + '</textarea>' +
                        '<button type="button" class="btn btn-primary btn-sm cell-save" onclick="saveCell(' + d + ',\'' + mt + '\')"><i class="fa fa-save"></i> Save</button>' +
                        '<div class="cell-status" id="status-' + key + '">Saved</div>' +
                        '</div>';
                });
            }
            grid.innerHTML = html;
        }

        function markDirty(day, mealTime) {
            var key = day + '_' + mealTime;
            document.getElementById('cell-' + key).classList.add('dirty');
            document.getElementById('status-' + key).style.display = 'none';
        }

        function saveCell(day, mealTime) {
            var key = day + '_' + mealTime;
            var text = document.getElementById('ta-' + key).value.trim();
            var cellEl = document.getElementById('cell-' + key);
            var saveBtn = cellEl.querySelector('.cell-save');
            saveBtn.disabled = true;

            var formData = new FormData();
            formData.append('action', text === '' ? 'clear_weekly_menu_cell' : 'save_weekly_menu_cell');
            formData.append('dayOfWeek', day);
            formData.append('mealTime', mealTime);
            if (text !== '') formData.append('menuText', text);

            fetch(API_URL, { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        cellEl.classList.remove('dirty');
                        var statusEl = document.getElementById('status-' + key);
                        statusEl.style.display = 'block';
                        setTimeout(function() { statusEl.style.display = 'none'; }, 2000);
                    } else {
                        showToast(data.message || 'Failed to save', 'error');
                    }
                })
                .catch(function() { showToast('Network error. Please try again.', 'error'); })
                .finally(function() { saveBtn.disabled = false; });
        }

        // Close modals on overlay click
        document.getElementById('planModal').addEventListener('click', function(e) { if (e.target === this) closePlanModal(); });
        document.getElementById('deleteModal').addEventListener('click', function(e) { if (e.target === this) closeDeleteModal(); });

        loadPlans();
        loadWeeklyMenu();
    </script>
</body>
</html>
