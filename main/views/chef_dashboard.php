<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/env_loader.php';

// Require login and permission to view dashboard (Chef role)
requireLogin();
requirePermission(PERMISSION_VIEW_DASHBOARD);

// Verify user is a Chef
if (!isset($_SESSION['staff_id']) || !isset($_SESSION['restaurant_id']) || $_SESSION['role'] !== ROLE_CHEF) {
    header('Location: ../admin/login.php');
    exit();
}

$restaurant_id = $_SESSION['restaurant_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Chef Dashboard - <?php echo htmlspecialchars($_SESSION['restaurant_name'] ?? 'Restaurant'); ?></title>
    
    <!-- Resource Hints for Performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="dns-prefetch" href="https://fonts.googleapis.com">
    <link rel="dns-prefetch" href="https://fonts.gstatic.com">
    
    <!-- Critical CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <!-- Optimized Font Loading -->
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap"></noscript>
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0"></noscript>
    
    <!-- Scripts - Defer non-critical -->
    <script src="../assets/js/sweetalert2.all.min.js" defer></script>
    <style>
        /* Prevent zoom on mobile devices */
        html, body {
            touch-action: manipulation;
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            user-select: none;
        }
        /* Allow text selection in input fields */
        input, textarea, select {
            -webkit-user-select: text;
            user-select: text;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f3f4f6; min-height: 100vh; }
        .main-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .chef-header { 
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); 
            color: white; 
            padding: 28px 32px; 
            margin-bottom: 32px; 
            border-radius: 16px; 
            box-shadow: 0 4px 20px rgba(245, 158, 11, 0.3);
            width: 100%;
        }
        .stats-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 20px; 
            margin-bottom: 32px;
            width: 100%;
        }
        .stat-card { 
            background: white; 
            padding: 24px; 
            border-radius: 16px; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid #f59e0b;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
        .stat-label { font-size: 0.875rem; color: #6b7280; font-weight: 500; margin-bottom: 8px; }
        .stat-value { font-size: 2rem; font-weight: 700; color: #111827; }
        .stat-card.pending { border-left-color: #f59e0b; }
        .stat-card.preparing { border-left-color: #3b82f6; }
        .stat-card.ready { border-left-color: #10b981; }
        .tab-container { 
            display: flex; 
            gap: 12px; 
            margin-bottom: 32px; 
            background: white; 
            padding: 8px; 
            border-radius: 12px; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            width: 100%;
            flex-wrap: wrap;
        }
        .tab-button { 
            flex: 1; 
            padding: 12px 20px; 
            border: none; 
            background: transparent; 
            border-radius: 8px; 
            font-weight: 600; 
            cursor: pointer; 
            transition: all 0.2s;
            color: #6b7280;
            font-size: 0.95rem;
        }
        .tab-button.active { 
            background: linear-gradient(135deg, #f59e0b, #d97706); 
            color: white; 
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
        }
        .tab-button:not(.active):hover { background: #f9fafb; }
        .kot-card { 
            background: white; 
            border: 1px solid #e5e7eb; 
            border-radius: 16px; 
            padding: 24px; 
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: all 0.3s;
            animation: slideIn 0.3s ease-out;
            width: 100%;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .kot-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .kot-card.pending { border-left: 5px solid #f59e0b; }
        .kot-card.preparing { border-left: 5px solid #3b82f6; }
        .kot-card.ready { border-left: 5px solid #10b981; }
        .kot-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start; 
            margin-bottom: 24px; 
            padding-bottom: 20px;
            border-bottom: 2px solid #f3f4f6;
            gap: 20px;
            flex-wrap: wrap;
        }
        .kot-number { 
            font-size: 1.75rem; 
            font-weight: 700; 
            color: #111827; 
            margin-bottom: 8px;
        }
        .kot-info { 
            display: flex; 
            flex-direction: column; 
            gap: 6px;
        }
        .kot-meta { 
            color: #6b7280; 
            font-size: 0.875rem; 
            display: flex; 
            align-items: center; 
            gap: 8px;
        }
        .kot-time { 
            color: #6b7280; 
            font-size: 0.875rem; 
            display: flex; 
            align-items: center; 
            gap: 6px;
        }
        .kot-status { 
            padding: 8px 16px; 
            border-radius: 24px; 
            font-weight: 600; 
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .kot-status.pending { background: #fef3c7; color: #92400e; }
        .kot-status.preparing { background: #dbeafe; color: #1e40af; }
        .kot-status.ready { background: #d1fae5; color: #065f46; }
        .kot-items { 
            margin: 24px 0; 
        }
        .kot-items-title { 
            font-size: 0.875rem; 
            font-weight: 600; 
            color: #374151; 
            text-transform: uppercase; 
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        .kot-item { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start;
            padding: 16px; 
            background: #f9fafb; 
            border-radius: 12px; 
            margin-bottom: 12px;
            transition: background 0.2s;
        }
        .kot-item:hover { background: #f3f4f6; }
        .kot-item-info { flex: 1; }
        .kot-item-name { 
            font-weight: 600; 
            color: #111827; 
            margin-bottom: 4px;
            font-size: 1rem;
        }
        .kot-item-qty { 
            color: #6b7280; 
            font-size: 0.875rem;
        }
        .kot-item-note { 
            color: #f59e0b; 
            font-size: 0.875rem; 
            font-style: italic;
            margin-top: 6px;
            padding: 6px 10px;
            background: #fef3c7;
            border-radius: 6px;
        }
        .kot-actions { 
            display: flex; 
            gap: 12px; 
            margin-top: 24px;
            padding-top: 24px;
            border-top: 2px solid #f3f4f6;
        }
        .btn { 
            padding: 12px 24px; 
            border: none; 
            border-radius: 10px; 
            font-weight: 600; 
            cursor: pointer; 
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }
        .btn-primary { 
            background: linear-gradient(135deg, #3b82f6, #2563eb); 
            color: white;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }
        .btn-success { 
            background: linear-gradient(135deg, #10b981, #059669); 
            color: white;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }
        .btn:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 4px 12px rgba(0,0,0,0.2); 
        }
        .btn:active { transform: translateY(0); }
        .btn-refresh {
            background: white;
            color: #3b82f6;
            border: 2px solid #3b82f6;
        }
        .btn-refresh:hover {
            background: #3b82f6;
            color: white;
        }
        .ready-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.95rem;
        }
        .empty-state { 
            text-align: center; 
            padding: 60px 20px; 
            color: #6b7280;
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .empty-state-icon { font-size: 4rem; margin-bottom: 16px; opacity: 0.5; }
        .loading { text-align: center; padding: 40px; color: #6b7280; }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .tab-container { flex-wrap: wrap; }
            .kot-header { flex-direction: column; gap: 12px; }
            .kot-actions { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <header class="chef-header">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 style="margin: 0; font-size: 1.75rem;">👨‍🍳 Chef Dashboard</h1>
                    <p style="margin: 8px 0 0 0; opacity: 0.9;"><?php echo htmlspecialchars($_SESSION['restaurant_name'] ?? 'Restaurant'); ?></p>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 0.9rem; opacity: 0.9;">Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Chef'); ?></div>
                    <button id="enableAlertsBtn" onclick="enableStaffPushAlerts(this)" style="color: white; font-weight: 600; margin-top: 8px; margin-right: 8px; display: inline-block; padding: 8px 16px; background: rgba(255,255,255,0.2); border: none; border-radius: 8px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">🔔 Enable Alerts</button>
                    <a href="../controllers/staff_logout.php" style="color: white; text-decoration: none; font-weight: 600; margin-top: 8px; display: inline-block; padding: 8px 16px; background: rgba(255,255,255,0.2); border-radius: 8px; transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">Logout</a>
                </div>
            </div>
        </header>

        <div class="main-content">
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card pending">
                    <div class="stat-label">🕐 Pending Orders</div>
                    <div class="stat-value" id="pendingCount">0</div>
                </div>
                <div class="stat-card preparing">
                    <div class="stat-label">👨‍🍳 In Kitchen</div>
                    <div class="stat-value" id="preparingCount">0</div>
                </div>
                <div class="stat-card ready">
                    <div class="stat-label">✅ Ready to Serve</div>
                    <div class="stat-value" id="readyCount">0</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tab-container">
                <button class="tab-button active" onclick="filterKOTs('all')">All Orders</button>
                <button class="tab-button" onclick="filterKOTs('pending')">Pending</button>
                <button class="tab-button" onclick="filterKOTs('preparing')">Preparing</button>
                <button class="tab-button" onclick="filterKOTs('ready')">Ready</button>
            </div>

            <!-- Header with Refresh -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; gap: 16px; flex-wrap: wrap; width: 100%;">
                <h2 style="font-size: 1.5rem; font-weight: 700; color: #111827; margin: 0;">Kitchen Orders (KOT)</h2>
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <span id="lastRefresh" style="color: #6b7280; font-size: 0.875rem; white-space: nowrap;">Auto-refreshing...</span>
                    <button class="btn btn-refresh" onclick="loadKOTOrders()">
                        <span class="material-symbols-rounded">refresh</span> 
                        <span class="refresh-text">Refresh</span>
                    </button>
                </div>
            </div>

            <div id="kotOrdersList">
                <div class="loading">Loading KOT orders...</div>
            </div>
        </div>
    </div>

    <script>
        const chefRestaurantId = <?php echo json_encode($restaurant_id); ?>;
        const chefRestaurantIdQuery = chefRestaurantId ? encodeURIComponent(chefRestaurantId) : '';
        const showChefAlert = (message, type = 'info') => {
            if (window.Swal) {
                Swal.fire({
                    icon: type,
                    text: message,
                    confirmButtonColor: '#d97706'
                });
            } else {
                alert(message);
            }
        };
        async function loadKOTOrders() {
            try {
                const response = await fetch('../api/get_kot.php?restaurant_id=' + chefRestaurantIdQuery, { cache: 'no-store' });
                const result = await response.json();
                
                const kotList = document.getElementById('kotOrdersList');
                const lastRefresh = document.getElementById('lastRefresh');
                
                // Update last refresh time
                if (lastRefresh) {
                    lastRefresh.textContent = 'Last updated: ' + new Date().toLocaleTimeString();
                }
                
                if (result.success && result.kots && result.kots.length > 0) {
                    // Update statistics
                    const pending = result.kots.filter(k => (k.kot_status || k.status || 'Pending') === 'Pending').length;
                    const preparing = result.kots.filter(k => (k.kot_status || k.status || 'Pending') === 'Preparing').length;
                    const ready = result.kots.filter(k => (k.kot_status || k.status || 'Pending') === 'Ready').length;
                    
                    document.getElementById('pendingCount').textContent = pending;
                    document.getElementById('preparingCount').textContent = preparing;
                    document.getElementById('readyCount').textContent = ready;
                    
                    // Store all KOTs for filtering
                    window.allKOTs = result.kots;
                    
                    // Display KOTs based on current filter
                    displayKOTs(window.allKOTs);
                } else {
                    kotList.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state-icon">🍽️</div>
                            <div style="font-size: 1.2rem; font-weight: 600; margin-bottom: 8px; color: #374151;">No KOT orders at the moment</div>
                            <div style="color: #6b7280;">New orders will appear here automatically</div>
                        </div>
                    `;
                    // Reset stats
                    document.getElementById('pendingCount').textContent = '0';
                    document.getElementById('preparingCount').textContent = '0';
                    document.getElementById('readyCount').textContent = '0';
                }
            } catch (error) {
                console.error('Error loading KOT orders:', error);
                document.getElementById('kotOrdersList').innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading orders</div>';
            }
        }

        async function updateKOTStatus(kotId, newStatus) {
            try {
                const response = await fetch('../controllers/kot_operations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=update_kot_status&kotId=${kotId}&status=${newStatus}`
                });
                const result = await response.json();
                
                if (result.success) {
                    // Show success notification (you can enhance this with a toast notification library)
                    const statusMsg = newStatus === 'Preparing' ? 'Started preparing!' : newStatus === 'Ready' ? 'Marked as ready! Order created for waiter.' : 'Status updated!';
                    console.log(`✅ ${statusMsg}`);
                    loadKOTOrders(); // Refresh immediately after update
                } else {
                    showChefAlert('Error: ' + (result.message || 'Failed to update status'));
                }
            } catch (error) {
                console.error('Error updating status:', error);
                showChefAlert('Error updating status');
            }
        }
        
        async function completeKOT(kotId) {
            try {
                const response = await fetch('../controllers/kot_operations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=complete_kot&kotId=${kotId}`
                });
                const result = await response.json();
                
                if (result.success) {
                    console.log('✅ Order completed and KOT closed!');
                    showChefAlert('Order completed! Order moved to main Orders tab.');
                    loadKOTOrders(); // Refresh immediately after update
                } else {
                    showChefAlert('Error: ' + (result.message || 'Failed to complete order'));
                }
            } catch (error) {
                console.error('Error completing KOT:', error);
                showChefAlert('Error completing order');
            }
        }
        
        // Make functions globally available
        window.updateKOTStatus = updateKOTStatus;
        window.completeKOT = completeKOT;

        // Current filter
        window.currentFilter = 'all';
        
        // Display KOTs with filtering
        let lastKOTSignature = null;
        function displayKOTs(kots, force) {
            const kotList = document.getElementById('kotOrdersList');
            let filteredKOTs = kots;

            if (window.currentFilter !== 'all') {
                filteredKOTs = kots.filter(kot => {
                    const status = (kot.kot_status || kot.status || 'Pending').toLowerCase();
                    return status === window.currentFilter;
                });
            }

            // Skip the re-render entirely when nothing actually changed since
            // last poll — rebuilding the whole list every 10-20s (via
            // innerHTML) was resetting scroll position on every tick even
            // when there was nothing new to show, which is disruptive during
            // a busy kitchen shift. Only the id/status/item-count are
            // fingerprinted (not timestamps), so an unrelated tick doesn't
            // force a rebuild just because a few seconds passed.
            const signature = JSON.stringify(filteredKOTs.map(k => [
                k.id, k.kot_status || k.status, (k.items || []).length
            ]));
            if (!force && signature === lastKOTSignature) {
                return;
            }
            lastKOTSignature = signature;

            if (filteredKOTs.length === 0) {
                const filterLabels = {
                    'pending': '🕐 Pending',
                    'preparing': '👨‍🍳 Preparing',
                    'ready': '✅ Ready'
                };
                kotList.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">${filterLabels[window.currentFilter] || '🍽️'}</div>
                        <div style="font-size: 1.2rem; font-weight: 600; margin-bottom: 8px; color: #374151;">No ${filterLabels[window.currentFilter] || ''} orders</div>
                        <div style="color: #6b7280;">Orders will appear here automatically</div>
                    </div>
                `;
                return;
            }
            
            // Calculate time elapsed for each KOT
            function getTimeElapsed(createdAt) {
                const now = new Date();
                const created = new Date(createdAt);
                const diff = Math.floor((now - created) / 1000); // seconds
                if (diff < 60) return `${diff}s ago`;
                if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
                return `${Math.floor(diff / 3600)}h ago`;
            }
            
            kotList.innerHTML = filteredKOTs.map(kot => {
                const statusClass = (kot.kot_status || kot.status || 'Pending').toLowerCase();
                const statusBadgeClass = statusClass;
                const statusText = kot.kot_status || kot.status || 'Pending';
                const timeElapsed = getTimeElapsed(kot.created_at);
                
                return `
                    <div class="kot-card ${statusClass}" data-status="${statusClass}">
                        <div class="kot-header">
                            <div class="kot-info">
                                <div class="kot-number">KOT #${kot.kot_number || kot.id}</div>
                                <div class="kot-meta">
                                    <span>📍 ${kot.table_number || 'Takeaway'}</span>
                                    ${kot.area_name ? `<span>• ${kot.area_name}</span>` : ''}
                                </div>
                                ${kot.customer_name && kot.customer_name !== 'Table Customer' && kot.customer_name !== 'Takeaway' ? `
                                <div class="kot-customer-info" style="margin-top: 8px; padding: 8px; background: rgba(255,255,255,0.5); border-radius: 6px; font-size: 0.85rem;">
                                    <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">👤 ${kot.customer_name}</div>
                                    ${kot.customer_phone ? `<div style="color: #6b7280; font-size: 0.8rem;">📞 ${kot.customer_phone}</div>` : ''}
                                    ${kot.customer_email ? `<div style="color: #6b7280; font-size: 0.8rem;">✉️ ${kot.customer_email}</div>` : ''}
                                    ${kot.customer_address ? `<div style="color: #6b7280; font-size: 0.8rem;">📍 ${kot.customer_address}</div>` : ''}
                                </div>
                                ` : ''}
                            </div>
                            <div style="text-align: right;">
                                <span class="kot-status ${statusBadgeClass}">
                                    ${statusText === 'Preparing' ? '👨‍🍳 ' : statusText === 'Ready' ? '✅ ' : '🕐 '}
                                    ${statusText}
                                </span>
                                <div class="kot-time" style="margin-top: 8px;">
                                    <span class="material-symbols-rounded" style="font-size: 16px;">schedule</span>
                                    ${timeElapsed}
                                </div>
                            </div>
                        </div>
                        <div class="kot-items">
                            <div class="kot-items-title">Items (${(kot.items || []).length})</div>
                            ${(kot.items || []).map(item => `
                                <div class="kot-item">
                                    <div class="kot-item-info">
                                        <div class="kot-item-name">${item.item_name_translated || item.item_name || item.name}</div>
                                        <div class="kot-item-qty">Quantity: ${item.quantity}</div>
                                        ${(item.notes || item.special_instructions) ? `
                                            <div class="kot-item-note">
                                                <strong>Note:</strong> ${item.notes || item.special_instructions}
                                            </div>
                                        ` : ''}
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                        <div class="kot-actions">
                            ${statusText === 'Pending' ? `
                                <button class="btn btn-primary" onclick="updateKOTStatus(${kot.id}, 'Preparing')">
                                    <span class="material-symbols-rounded">restaurant_menu</span>
                                    Start Preparing
                                </button>
                            ` : ''}
                            ${statusText === 'Preparing' ? `
                                <button class="btn btn-success" onclick="updateKOTStatus(${kot.id}, 'Ready')">
                                    <span class="material-symbols-rounded">check_circle</span>
                                    Mark as Ready
                                </button>
                            ` : ''}
                            ${statusText === 'Ready' ? `
                                <button class="btn btn-warning" onclick="completeKOT(${kot.id})">
                                    <span class="material-symbols-rounded">done_all</span>
                                    Complete Order
                                </button>
                            ` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        }
        
        // Filter function
        function filterKOTs(filter) {
            window.currentFilter = filter;
            
            // Update tab buttons - use a matching approach instead of event.target
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
                if (btn.getAttribute('onclick') && btn.getAttribute('onclick').includes("'" + filter + "'")) {
                    btn.classList.add('active');
                }
            });
            
            // Display filtered KOTs (force=true: a manual filter switch should
            // always re-render, even if the signature happens to match)
            if (window.allKOTs) {
                displayKOTs(window.allKOTs, true);
            }
        }
        
        window.filterKOTs = filterKOTs;
        
        // Intelligent auto-refresh: 10 seconds base, slows down when idle
        let refreshInterval = null;
        let refreshIntervalTime = 10000; // Start with 10 seconds
        let lastKOTCount = 0;
        let noChangeCount = 0;
        
        const smartRefresh = () => {
            // Only refresh if page is visible
            if (document.hidden) {
                return;
            }
            
            const beforeCount = document.querySelectorAll('.kot-card').length;
            loadKOTOrders().then(() => {
                setTimeout(() => {
                    const afterCount = document.querySelectorAll('.kot-card').length;
                    
                    // If no changes and no KOTs, slow down
                    if (afterCount === beforeCount && afterCount === 0) {
                        noChangeCount++;
                        if (noChangeCount > 2) {
                            refreshIntervalTime = Math.min(20000, refreshIntervalTime * 1.5); // Max 20 seconds
                        }
                    } else {
                        noChangeCount = 0;
                        refreshIntervalTime = 10000; // Reset to 10 seconds when active
                    }
                    
                    // Restart with new interval
                    if (refreshInterval) clearInterval(refreshInterval);
                    refreshInterval = setInterval(smartRefresh, refreshIntervalTime);
                }, 500);
            }).catch(() => {
                // On error, slow down
                refreshIntervalTime = Math.min(20000, refreshIntervalTime * 1.2);
                if (refreshInterval) clearInterval(refreshInterval);
                refreshInterval = setInterval(smartRefresh, refreshIntervalTime);
            });
        };
        
        refreshInterval = setInterval(smartRefresh, refreshIntervalTime);
        
        // Pause auto-refresh when page is hidden, resume when visible
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                if (refreshInterval) {
                    clearInterval(refreshInterval);
                    refreshInterval = null;
                }
            } else {
                if (!refreshInterval) {
                    refreshIntervalTime = 10000; // Reset to base interval
                    refreshInterval = setInterval(smartRefresh, refreshIntervalTime);
                }
            }
        });
        
        // Load on page load
        loadKOTOrders();

        // ── Push notifications: chef gets pushed the moment a new order
        // comes in, instead of relying solely on the smartRefresh poll above.
        function _pushVapidKeyToUint8Array(base64String) {
            var padding = '='.repeat((4 - base64String.length % 4) % 4);
            var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            var rawData = window.atob(base64);
            var outputArray = new Uint8Array(rawData.length);
            for (var i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
            return outputArray;
        }

        async function enableStaffPushAlerts(btn) {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                alert('This browser does not support push notifications (Safari on iOS needs the site added to the Home Screen first).');
                return;
            }
            if (btn) { btn.disabled = true; btn.textContent = 'Enabling…'; }
            try {
                var perm = Notification.permission;
                if (perm !== 'granted') {
                    perm = await Notification.requestPermission();
                }
                if (perm !== 'granted') {
                    alert('Notification permission was not granted.');
                    if (btn) { btn.disabled = false; btn.textContent = '🔔 Enable Alerts'; }
                    return;
                }

                var reg = await navigator.serviceWorker.register('../website/sw.php?v=6', { scope: '../' });
                reg = await navigator.serviceWorker.ready;
                var sub = await reg.pushManager.getSubscription();
                if (!sub) {
                    var vapidKey = <?php echo json_encode(env('VAPID_PUBLIC_KEY', '')); ?>;
                    if (!vapidKey) {
                        alert('Push notifications are not configured on the server yet.');
                        if (btn) { btn.disabled = false; btn.textContent = '🔔 Enable Alerts'; }
                        return;
                    }
                    sub = await reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: _pushVapidKeyToUint8Array(vapidKey)
                    });
                }

                var res = await fetch('../api/save_push_subscription.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(sub.toJSON ? sub.toJSON() : sub)
                });
                var data = await res.json();
                if (data.success) {
                    localStorage.setItem('staffPushEnabled', '1');
                    if (btn) { btn.disabled = false; btn.textContent = '🔔 Alerts On'; }
                } else {
                    alert('Failed to save subscription: ' + (data.message || 'unknown error'));
                    if (btn) { btn.disabled = false; btn.textContent = '🔔 Enable Alerts'; }
                }
            } catch (e) {
                console.error('Push subscribe error', e);
                alert('Could not enable push alerts: ' + e.message);
                if (btn) { btn.disabled = false; btn.textContent = '🔔 Enable Alerts'; }
            }
        }

        // Silently re-subscribe on later visits once permission has already
        // been granted once — otherwise the button would show "off" on every
        // reload even though nothing needs the user's attention again.
        if ('Notification' in window && Notification.permission === 'granted' && localStorage.getItem('staffPushEnabled') === '1') {
            document.addEventListener('DOMContentLoaded', function() {
                enableStaffPushAlerts(document.getElementById('enableAlertsBtn'));
            });
        }
    </script>
</body>
</html>

