<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Check if user is logged in
if (!isSessionValid() || !isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['restaurant_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/../config/authorization_config.php';
requirePermission(PERMISSION_MANAGE_MENU);

$restaurant_id = $_SESSION['restaurant_id'];
$restaurant_name = $_SESSION['restaurant_name'] ?? 'Restaurant';

// Ensure meal_addons table exists
try {
    require_once __DIR__ . '/../db_connection.php';
    $conn = getConnection();
    
    $checkTable = $conn->query("SHOW TABLES LIKE 'meal_addons'");
    if ($checkTable->rowCount() == 0) {
        $conn->exec("CREATE TABLE IF NOT EXISTS meal_addons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            addon_name VARCHAR(255) NOT NULL,
            addon_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            addon_image VARCHAR(500) DEFAULT NULL,
            image_data LONGBLOB DEFAULT NULL,
            image_mime_type VARCHAR(50) DEFAULT NULL,
            is_available TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_addon_restaurant (restaurant_id),
            INDEX idx_addon_available (restaurant_id, is_available)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (Exception $e) {
    // Ignore errors
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Add-ons - <?php echo htmlspecialchars($restaurant_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: #f0f2f5; color: #1a1b1f; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .header h1 { font-size: 28px; font-weight: 700; color: #151A2D; }
        .header p { color: #666; font-size: 14px; margin-top: 4px; }
        .header-actions { display: flex; gap: 10px; }
        
        .btn { padding: 10px 20px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: 'Poppins', sans-serif; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: linear-gradient(135deg, #e17055, #d63031); color: #fff; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(225,112,85,0.3); }
        .btn-edit { background: #10b981; color: #fff; }
        .btn-edit:hover { background: #059669; }
        .btn-delete { background: #ef4444; color: #fff; }
        .btn-delete:hover { background: #dc2626; }
        .btn-secondary { background: #6b7280; color: #fff; }
        .btn-secondary:hover { background: #4b5563; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .back-link { display: inline-flex; align-items: center; gap: 6px; color: #e17055; text-decoration: none; font-size: 14px; font-weight: 500; margin-bottom: 20px; }
        .back-link:hover { text-decoration: underline; }
        
        .card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        
        .addons-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 20px; }
        .addon-card { background: #fff; border-radius: 12px; border: 2px solid #e5e7eb; overflow: hidden; transition: all 0.2s; position: relative; }
        .addon-card:hover { border-color: #e17055; box-shadow: 0 4px 12px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .addon-card.oos { opacity: 0.6; }
        
        .addon-img { width: 100%; height: 140px; object-fit: cover; background: #f5f5f5; display: flex; align-items: center; justify-content: center; }
        .addon-img img { width: 100%; height: 100%; object-fit: cover; }
        .addon-img .no-img { color: #ccc; font-size: 40px; }
        
        .addon-body { padding: 16px; }
        .addon-name { font-size: 16px; font-weight: 600; color: #1a1b1f; margin-bottom: 4px; }
        .addon-price { font-size: 18px; font-weight: 700; color: #e17055; margin-bottom: 8px; }
        .addon-status { font-size: 12px; font-weight: 500; padding: 3px 10px; border-radius: 20px; display: inline-block; }
        .addon-status.available { background: #d1fae5; color: #065f46; }
        .addon-status.unavailable { background: #fee2e2; color: #991b1b; }
        
        .addon-actions { display: flex; gap: 8px; margin-top: 12px; }
        .addon-actions .btn { flex: 1; padding: 8px 12px; font-size: 13px; justify-content: center; }
        
        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.4; }
        .empty-state h3 { color: #999; margin-bottom: 8px; }
        
        .toast { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); padding: 12px 24px; border-radius: 12px; color: #fff; font-size: 14px; font-weight: 500; z-index: 99999; box-shadow: 0 4px 20px rgba(0,0,0,0.3); display: none; font-family: 'Poppins', sans-serif; }
        .toast.success { background: #10b981; }
        .toast.error { background: #ef4444; }
        
        /* Modal */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: #fff; border-radius: 16px; max-width: 500px; width: 100%; max-height: 90vh; overflow-y: auto; animation: slideUp 0.2s ease; }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid #e5e7eb; }
        .modal-header h2 { font-size: 18px; font-weight: 700; color: #1a1b1f; margin: 0; }
        .modal-close { width: 32px; height: 32px; border-radius: 8px; border: none; background: #f5f5f5; cursor: pointer; font-size: 20px; color: #666; display: flex; align-items: center; justify-content: center; }
        .modal-body { padding: 24px; }
        
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333; }
        .form-group input[type="text"], .form-group input[type="number"] { width: 100%; padding: 10px 14px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; font-family: 'Poppins', sans-serif; outline: none; transition: border-color 0.2s; box-sizing: border-box; }
        .form-group input:focus { border-color: #e17055; }
        
        .form-group .file-upload { display: flex; align-items: center; gap: 10px; }
        .form-group .file-upload-btn { padding: 8px 16px; background: #f5f5f5; border: 2px solid #e0e0e0; border-radius: 6px; cursor: pointer; font-size: 13px; font-family: 'Poppins', sans-serif; transition: all 0.2s; }
        .form-group .file-upload-btn:hover { background: #e0e0e0; }
        .form-group input[type="file"] { display: none; }
        .form-group .file-name { color: #666; font-size: 13px; }
        
        .image-preview { margin-top: 10px; text-align: center; }
        .image-preview img { max-width: 150px; max-height: 120px; border-radius: 8px; object-fit: cover; border: 1px solid #e0e0e0; }
        
        .toggle-wrapper { display: flex; align-items: center; gap: 10px; }
        .toggle { position: relative; width: 44px; height: 24px; cursor: pointer; }
        .toggle input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; inset: 0; background: #ccc; border-radius: 24px; transition: 0.3s; }
        .toggle-slider::before { content: ''; position: absolute; width: 20px; height: 20px; left: 2px; bottom: 2px; background: #fff; border-radius: 50%; transition: 0.3s; }
        .toggle input:checked + .toggle-slider { background: #10b981; }
        .toggle input:checked + .toggle-slider::before { transform: translateX(20px); }
        
        .form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        
        .stats-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: #fff; border-radius: 12px; padding: 16px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 12px; }
        .stat-card i { font-size: 28px; color: #e17055; }
        .stat-card .stat-info { flex: 1; }
        .stat-card .stat-label { font-size: 12px; color: #999; }
        .stat-card .stat-value { font-size: 22px; font-weight: 700; color: #1a1b1f; }
        
        .loading { text-align: center; padding: 40px; color: #999; }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link"><i class="fa fa-arrow-left"></i> Back to Dashboard</a>
        
        <div class="header">
            <div>
                <h1><i class="fa fa-plus-circle" style="color:#e17055"></i> Manage Add-ons</h1>
                <p>Create and manage add-ons like Extra Cheese, Ketchup, etc.</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="openAddonModal()"><i class="fa fa-plus"></i> Add New Add-on</button>
            </div>
        </div>
        
        <div class="stats-bar" id="statsBar">
            <div class="stat-card"><i class="fa fa-box"></i><div class="stat-info"><div class="stat-label">Total Add-ons</div><div class="stat-value" id="totalCount">0</div></div></div>
            <div class="stat-card"><i class="fa fa-check-circle" style="color:#10b981"></i><div class="stat-info"><div class="stat-label">Available</div><div class="stat-value" id="availableCount" style="color:#10b981">0</div></div></div>
            <div class="stat-card"><i class="fa fa-times-circle" style="color:#ef4444"></i><div class="stat-info"><div class="stat-label">Unavailable</div><div class="stat-value" id="unavailableCount" style="color:#ef4444">0</div></div></div>
        </div>
        
        <div class="card">
            <div class="addons-grid" id="addonsGrid">
                <div class="loading"><i class="fa fa-spinner fa-spin"></i> Loading add-ons...</div>
            </div>
        </div>
    </div>
    
    <!-- Add/Edit Modal -->
    <div class="modal-overlay" id="addonModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2 id="modalTitle">Add New Add-on</h2>
                <button class="modal-close" onclick="closeAddonModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addonForm" onsubmit="return saveAddon(event)">
                    <input type="hidden" id="addonId" value="">
                    
                    <div class="form-group">
                        <label>Add-on Name *</label>
                        <input type="text" id="addonName" required placeholder="e.g. Extra Cheese, Ketchup">
                    </div>
                    
                    <div class="form-group">
                        <label>Price (<?php echo htmlspecialchars($_SESSION['currency_symbol'] ?? '₹'); ?>) *</label>
                        <input type="number" id="addonPrice" step="0.01" min="0" required placeholder="0.00">
                    </div>
                    
                    <div class="form-group">
                        <label>Image (Optional)</label>
                        <div class="file-upload">
                            <label class="file-upload-btn" for="addonImage"><i class="fa fa-upload"></i> Choose Image</label>
                            <input type="file" id="addonImage" accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewAddonImage(this)">
                            <span class="file-name" id="addonFileName">No file chosen</span>
                            <input type="hidden" id="addonImageBase64" value="">
                        </div>
                        <div class="image-preview" id="addonImagePreview" style="display:none">
                            <img id="addonPreviewImg" src="" alt="Preview">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <div class="toggle-wrapper">
                            <label class="toggle">
                                <input type="checkbox" id="addonAvailable" checked>
                                <span class="toggle-slider"></span>
                            </label>
                            <span id="addonStatusLabel">Available</span>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeAddonModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveBtn"><i class="fa fa-save"></i> Save Add-on</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal-box" style="max-width:400px">
            <div class="modal-header">
                <h2>Delete Add-on</h2>
                <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom:20px;color:#666">Are you sure you want to delete <strong id="deleteItemName"></strong>?</p>
                <div class="form-actions">
                    <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button class="btn btn-delete" id="confirmDeleteBtn" onclick="confirmDelete()"><i class="fa fa-trash"></i> Delete</button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="toast" id="toast"></div>
    
    <script>
        var currentDeleteId = null;
        var API_URL = '../controllers/addon_operations.php';
        
        function showToast(msg, type) {
            var t = document.getElementById('toast');
            t.textContent = msg;
            t.className = 'toast ' + type;
            t.style.display = 'block';
            setTimeout(function() { t.style.display = 'none'; }, 3000);
        }
        
        function getImageUrl(img) {
            if (!img || img === '' || img === 'no-image' || img === 'null') return null;
            if (img.indexOf('http') === 0 || img.indexOf('https') === 0) return img;
            if (img.indexOf('db:') === 0) return '../api/image.php?id=' + encodeURIComponent(img.substring(3));
            return img;
        }
        
        function loadAddons() {
            var grid = document.getElementById('addonsGrid');
            grid.innerHTML = '<div class="loading"><i class="fa fa-spinner fa-spin"></i> Loading add-ons...</div>';
            
            fetch('../api/get_addons.php')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.data) {
                        renderAddons(data.data);
                        updateStats(data.data);
                    } else {
                        grid.innerHTML = '<div class="empty-state"><i class="fa fa-exclamation-circle"></i><h3>Failed to load add-ons</h3></div>';
                    }
                })
                .catch(function(err) {
                    console.error('Error loading add-ons:', err);
                    grid.innerHTML = '<div class="empty-state"><i class="fa fa-exclamation-circle"></i><h3>Error loading add-ons</h3></div>';
                });
        }
        
        function renderAddons(addons) {
            var grid = document.getElementById('addonsGrid');
            
            if (!addons || addons.length === 0) {
                grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><i class="fa fa-plus-circle"></i><h3>No add-ons yet</h3><p>Create your first add-on to get started!</p></div>';
                return;
            }
            
            var html = '';
            for (var i = 0; i < addons.length; i++) {
                var a = addons[i];
                var imgUrl = getImageUrl(a.addon_image);
                var available = a.is_available == 1;
                var price = parseFloat(a.addon_price || 0);
                var sym = window.CURRENCY_SYMBOL || '<?php echo htmlspecialchars($_SESSION['currency_symbol'] ?? '₹'); ?>';
                
                html += '<div class="addon-card' + (!available ? ' oos' : '') + '" id="addon-' + a.id + '">';
                html += '<div class="addon-img">';
                if (imgUrl) {
                    html += '<img src="' + imgUrl + '" alt="' + escapeHtml(a.addon_name) + '">';
                } else {
                    html += '<div class="no-img"><i class="fa fa-image"></i></div>';
                }
                html += '</div>';
                html += '<div class="addon-body">';
                html += '<div class="addon-name">' + escapeHtml(a.addon_name) + '</div>';
                html += '<div class="addon-price">' + sym + price.toFixed(2) + '</div>';
                html += '<span class="addon-status ' + (available ? 'available' : 'unavailable') + '">' + (available ? 'Available' : 'Unavailable') + '</span>';
                html += '<div class="addon-actions">';
                html += '<button class="btn btn-edit" onclick="editAddon(' + a.id + ')"><i class="fa fa-edit"></i> Edit</button>';
                html += '<button class="btn btn-delete" onclick="deleteAddon(' + a.id + ', \'' + escapeHtml(a.addon_name) + '\')"><i class="fa fa-trash"></i> Delete</button>';
                html += '</div></div></div>';
            }
            grid.innerHTML = html;
        }
        
        function updateStats(addons) {
            var total = addons.length;
            var available = 0;
            for (var i = 0; i < addons.length; i++) {
                if (addons[i].is_available == 1) available++;
            }
            document.getElementById('totalCount').textContent = total;
            document.getElementById('availableCount').textContent = available;
            document.getElementById('unavailableCount').textContent = total - available;
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
        }
        
        function openAddonModal() {
            document.getElementById('modalTitle').textContent = 'Add New Add-on';
            document.getElementById('addonId').value = '';
            document.getElementById('addonForm').reset();
            document.getElementById('addonAvailable').checked = true;
            document.getElementById('addonStatusLabel').textContent = 'Available';
            document.getElementById('addonImagePreview').style.display = 'none';
            document.getElementById('addonFileName').textContent = 'No file chosen';
            document.getElementById('addonImageBase64').value = '';
            document.getElementById('saveBtn').innerHTML = '<i class="fa fa-save"></i> Save Add-on';
            document.getElementById('addonModal').classList.add('active');
        }
        
        function closeAddonModal() {
            document.getElementById('addonModal').classList.remove('active');
        }
        
        function previewAddonImage(input) {
            var file = input.files[0];
            var nameSpan = document.getElementById('addonFileName');
            var preview = document.getElementById('addonImagePreview');
            var img = document.getElementById('addonPreviewImg');
            
            if (file) {
                nameSpan.textContent = file.name;
                var reader = new FileReader();
                reader.onload = function(e) {
                    img.src = e.target.result;
                    preview.style.display = 'block';
                    document.getElementById('addonImageBase64').value = e.target.result;
                };
                reader.readAsDataURL(file);
            } else {
                nameSpan.textContent = 'No file chosen';
                preview.style.display = 'none';
                document.getElementById('addonImageBase64').value = '';
            }
        }
        
        // Toggle handler
        document.getElementById('addonAvailable').addEventListener('change', function() {
            document.getElementById('addonStatusLabel').textContent = this.checked ? 'Available' : 'Unavailable';
        });
        
        function saveAddon(e) {
            e.preventDefault();
            
            var id = document.getElementById('addonId').value;
            var name = document.getElementById('addonName').value.trim();
            var price = document.getElementById('addonPrice').value;
            var available = document.getElementById('addonAvailable').checked ? 1 : 0;
            var base64 = document.getElementById('addonImageBase64').value;
            var isEdit = id !== '';
            
            if (!name) { showToast('Please enter an add-on name', 'error'); return; }
            if (!price || parseFloat(price) < 0) { showToast('Please enter a valid price', 'error'); return; }
            
            var btn = document.getElementById('saveBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';
            
            var formData = new FormData();
            formData.append('action', isEdit ? 'update' : 'add');
            formData.append('addonName', name);
            formData.append('addonPrice', price);
            formData.append('isAvailable', available);
            if (isEdit) formData.append('addonId', id);
            
            // Handle image
            var fileInput = document.getElementById('addonImage');
            if (fileInput.files.length > 0) {
                formData.append('addonImage', fileInput.files[0]);
            } else if (base64 && base64.indexOf('data:image') === 0) {
                formData.append('addonImageBase64', base64);
            }
            
            fetch(API_URL, {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showToast(data.message, 'success');
                    closeAddonModal();
                    loadAddons();
                } else {
                    showToast(data.message || 'Failed to save add-on', 'error');
                }
            })
            .catch(function(err) {
                console.error('Error:', err);
                showToast('Network error. Please try again.', 'error');
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-save"></i> ' + (isEdit ? 'Update' : 'Save') + ' Add-on';
            });
            
            return false;
        }
        
        function editAddon(id) {
            // Fetch add-on details
            fetch('../api/get_addons.php')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.data) {
                        var addon = null;
                        for (var i = 0; i < data.data.length; i++) {
                            if (data.data[i].id == id) {
                                addon = data.data[i];
                                break;
                            }
                        }
                        if (addon) {
                            document.getElementById('modalTitle').textContent = 'Edit Add-on';
                            document.getElementById('addonId').value = addon.id;
                            document.getElementById('addonName').value = addon.addon_name;
                            document.getElementById('addonPrice').value = addon.addon_price;
                            document.getElementById('addonAvailable').checked = addon.is_available == 1;
                            document.getElementById('addonStatusLabel').textContent = addon.is_available == 1 ? 'Available' : 'Unavailable';
                            document.getElementById('saveBtn').innerHTML = '<i class="fa fa-save"></i> Update Add-on';
                            
                            // Show existing image if any
                            var imgUrl = getImageUrl(addon.addon_image);
                            if (imgUrl) {
                                document.getElementById('addonPreviewImg').src = imgUrl;
                                document.getElementById('addonImagePreview').style.display = 'block';
                            } else {
                                document.getElementById('addonImagePreview').style.display = 'none';
                            }
                            
                            document.getElementById('addonModal').classList.add('active');
                        }
                    }
                })
                .catch(function(err) { console.error('Error:', err); showToast('Failed to load add-on details', 'error'); });
        }
        
        function deleteAddon(id, name) {
            currentDeleteId = id;
            document.getElementById('deleteItemName').textContent = name;
            document.getElementById('deleteModal').classList.add('active');
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
            currentDeleteId = null;
        }
        
        function confirmDelete() {
            if (!currentDeleteId) return;
            
            var btn = document.getElementById('confirmDeleteBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Deleting...';
            
            var formData = new FormData();
            formData.append('action', 'delete');
            formData.append('addonId', currentDeleteId);
            
            fetch(API_URL, {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showToast('Add-on deleted successfully', 'success');
                    closeDeleteModal();
                    loadAddons();
                } else {
                    showToast(data.message || 'Failed to delete add-on', 'error');
                }
            })
            .catch(function(err) {
                console.error('Error:', err);
                showToast('Network error. Please try again.', 'error');
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-trash"></i> Delete';
            });
        }
        
        // Close modals on overlay click
        document.getElementById('addonModal').addEventListener('click', function(e) {
            if (e.target === this) closeAddonModal();
        });
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });
        
        // Load on page load
        loadAddons();
    </script>
</body>
</html>
