<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

// Require login and permission to view dashboard (Manager role)
requireLogin();
requirePermission(PERMISSION_VIEW_DASHBOARD);

// Verify user is a Manager
if (!isset($_SESSION['staff_id']) || !isset($_SESSION['restaurant_id']) || $_SESSION['role'] !== ROLE_MANAGER) {
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
    <title>Manager Dashboard - <?php echo htmlspecialchars($_SESSION['restaurant_name'] ?? 'Restaurant'); ?></title>
    
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
        .manager-header { background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 20px; margin-bottom: 24px; border-radius: 12px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; }
        .stat-label { color: #6b7280; font-size: 0.875rem; margin-bottom: 8px; }
        .stat-value { font-size: 2rem; font-weight: 800; color: #151A2D; }
        .page-tabs { display: flex; gap: 10px; margin-bottom: 24px; border-bottom: 2px solid #e5e7eb; }
        .tab { padding: 12px 24px; cursor: pointer; font-weight: 600; color: #6b7280; border-bottom: 3px solid transparent; transition: all 0.2s; }
        .tab.active { color: #10b981; border-bottom-color: #10b981; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f9fafb; font-weight: 600; color: #374151; }
        .btn { padding: 10px 20px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: #10b981; color: white; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    </style>
</head>
<body>
    <div class="main-container">
        <header class="manager-header">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 style="margin: 0; font-size: 1.75rem;">👔 Manager Dashboard</h1>
                    <p style="margin: 8px 0 0 0; opacity: 0.9;"><?php echo htmlspecialchars($_SESSION['restaurant_name'] ?? 'Restaurant'); ?></p>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 0.9rem; opacity: 0.9;">Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Manager'); ?></div>
                    <a href="../admin/auth.php?action=logout" style="color: white; text-decoration: none; font-weight: 600; margin-top: 8px; display: inline-block;">Logout</a>
                </div>
            </div>
        </header>

        <div class="main-content">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Today's Revenue</div>
                    <div class="stat-value" id="todayRevenue">₹0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Today's Orders</div>
                    <div class="stat-value" id="todayOrders">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Active Tables</div>
                    <div class="stat-value" id="activeTables">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Pending Requests</div>
                    <div class="stat-value" id="pendingRequests">0</div>
                </div>
            </div>

            <div class="page-tabs">
                <div class="tab active" onclick="switchTab('orders')">Orders</div>
                <div class="tab" onclick="switchTab('customers')">Customers</div>
                <div class="tab" onclick="switchTab('payments')">Payments</div>
                <div class="tab" onclick="switchTab('reports')">Reports</div>
            </div>

            <!-- Orders Tab -->
            <div id="ordersTab" class="tab-content active">
                <div class="card">
                    <div class="page-header" style="margin-bottom: 16px;">
                        <h2>Orders</h2>
                        <button class="btn btn-primary" onclick="loadOrders()">
                            <span class="material-symbols-rounded" style="vertical-align: middle;">refresh</span> Refresh
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table id="ordersTable">
                            <thead>
                                <tr>
                                    <th>Order ID</th><th>Table</th><th>Amount</th><th>Status</th><th>Date</th>
                                </tr>
                            </thead>
                            <tbody id="ordersTbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Customers Tab -->
            <div id="customersTab" class="tab-content">
                <div class="card">
                    <div class="page-header" style="margin-bottom: 16px;">
                        <h2>Customers</h2>
                        <button class="btn btn-primary" onclick="loadCustomers()">
                            <span class="material-symbols-rounded" style="vertical-align: middle;">refresh</span> Refresh
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table id="customersTable">
                            <thead>
                                <tr>
                                    <th>Name</th><th>Phone</th><th>Email</th><th>Visits</th><th>Total Spent</th><th>Last Visit</th>
                                </tr>
                            </thead>
                            <tbody id="customersTbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Payments Tab -->
            <div id="paymentsTab" class="tab-content">
                <div class="card">
                    <div class="page-header" style="margin-bottom: 16px;">
                        <h2>Payments</h2>
                        <button class="btn btn-primary" onclick="loadPayments()">
                            <span class="material-symbols-rounded" style="vertical-align: middle;">refresh</span> Refresh
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table id="paymentsTable">
                            <thead>
                                <tr>
                                    <th>Transaction ID</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th>
                                </tr>
                            </thead>
                            <tbody id="paymentsTbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Reports Tab -->
            <div id="reportsTab" class="tab-content">
                <div class="card">
                    <h2>Sales Report</h2>
                    <div id="salesReport" style="padding: 20px; color: #6b7280;">
                        Loading report...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            // Find the tab with matching onclick attribute
            document.querySelectorAll('.tab').forEach(t => {
                if (t.getAttribute('onclick') && t.getAttribute('onclick').includes("'" + tab + "'")) {
                    t.classList.add('active');
                }
            });
            document.getElementById(tab + 'Tab').classList.add('active');
            if (tab === 'orders') loadOrders();
            else if (tab === 'customers') loadCustomers();
            else if (tab === 'payments') loadPayments();
            else if (tab === 'reports') loadReports();
        }

        async function loadStats() {
            try {
                const response = await fetch(`get_dashboard_stats.php?restaurant_id=<?php echo $restaurant_id; ?>`);
                const result = await response.json();
                if (result.success) {
                    document.getElementById('todayRevenue').textContent = (window.CURRENCY_SYMBOL || '₹') + parseFloat(result.today_revenue || 0).toFixed(2);
                    document.getElementById('todayOrders').textContent = result.today_orders || 0;
                    document.getElementById('activeTables').textContent = result.active_tables || 0;
                    document.getElementById('pendingRequests').textContent = result.pending_requests || 0;
                }
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        }

        async function loadOrders() {
            try {
                const response = await fetch(`get_orders.php?restaurant_id=<?php echo $restaurant_id; ?>`);
                const result = await response.json();
                const tbody = document.getElementById('ordersTbody');
                if (result.success && result.orders && result.orders.length > 0) {
                    tbody.innerHTML = result.orders.map(order => `
                        <tr>
                            <td>#${order.id}</td>
                            <td>${order.table_number || 'Takeaway'}</td>
                            <td>₹${parseFloat(order.total_amount || 0).toFixed(2)}</td>
                            <td>${order.status || 'Pending'}</td>
                            <td>${new Date(order.created_at).toLocaleString()}</td>
                        </tr>
                    `).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#6b7280;">No orders found</td></tr>';
                }
            } catch (error) {
                console.error('Error loading orders:', error);
                document.getElementById('ordersTbody').innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#ef4444;">Failed to load orders. Please try again.</td></tr>';
            }
        }

        async function loadCustomers() {
            try {
                const response = await fetch(`get_customers.php?restaurant_id=<?php echo $restaurant_id; ?>`);
                const result = await response.json();
                const tbody = document.getElementById('customersTbody');
                if (result.success && result.customers && result.customers.length > 0) {
                    tbody.innerHTML = result.customers.map(customer => `
                        <tr>
                            <td>${customer.customer_name || 'N/A'}</td>
                            <td>${customer.phone || 'N/A'}</td>
                            <td>${customer.email || 'N/A'}</td>
                            <td>${customer.total_visits || 0}</td>
                            <td>₹${parseFloat(customer.total_spent || 0).toFixed(2)}</td>
                            <td>${customer.last_visit_date || 'N/A'}</td>
                        </tr>
                    `).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#6b7280;">No customers found</td></tr>';
                }
            } catch (error) {
                console.error('Error loading customers:', error);
                document.getElementById('customersTbody').innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#ef4444;">Failed to load customers. Please try again.</td></tr>';
            }
        }

        async function loadPayments() {
            try {
                const response = await fetch(`get_payments.php?restaurant_id=<?php echo $restaurant_id; ?>`);
                const result = await response.json();
                const tbody = document.getElementById('paymentsTbody');
                if (result.success && result.payments && result.payments.length > 0) {
                    tbody.innerHTML = result.payments.map(payment => `
                        <tr>
                            <td>${payment.transaction_id || 'N/A'}</td>
                            <td>₹${parseFloat(payment.amount || 0).toFixed(2)}</td>
                            <td>${payment.payment_method || 'N/A'}</td>
                            <td>${payment.payment_status || 'Pending'}</td>
                            <td>${new Date(payment.created_at).toLocaleString()}</td>
                        </tr>
                    `).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#6b7280;">No payments found</td></tr>';
                }
            } catch (error) {
                console.error('Error loading payments:', error);
                document.getElementById('paymentsTbody').innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#ef4444;">Failed to load payments. Please try again.</td></tr>';
            }
        }

        async function loadReports() {
            try {
                const response = await fetch(`get_sales_report.php?restaurant_id=<?php echo $restaurant_id; ?>`);
                const result = await response.json();
                const reportDiv = document.getElementById('salesReport');
                if (result.success) {
                    reportDiv.innerHTML = `
                        <h3>Sales Report</h3>
                        <p>Total Revenue: ₹${parseFloat(result.total_revenue || 0).toFixed(2)}</p>
                        <p>Total Orders: ${result.total_orders || 0}</p>
                        <p>Average Order Value: ₹${parseFloat(result.average_order_value || 0).toFixed(2)}</p>
                    `;
                } else {
                    reportDiv.innerHTML = '<p>Error loading report</p>';
                }
            } catch (error) {
                console.error('Error loading reports:', error);
                document.getElementById('salesReport').innerHTML = '<p style="color:#ef4444;">Failed to load report. Please try again.</p>';
            }
        }

        // Optimized auto-refresh: only when page is visible and active
        let statsRefreshInterval = null;
        
        function startStatsRefresh() {
            // Clear existing interval
            if (statsRefreshInterval) {
                clearInterval(statsRefreshInterval);
            }
            
            // Only refresh if page is visible
            if (!document.hidden) {
                statsRefreshInterval = setInterval(() => {
                    // Double-check page is still visible before refreshing
                    if (!document.hidden) {
                        loadStats();
                    }
                }, 30000); // 30 seconds
            }
        }
        
        // Pause when page is hidden, resume when visible
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                if (statsRefreshInterval) {
                    clearInterval(statsRefreshInterval);
                    statsRefreshInterval = null;
                }
            } else {
                startStatsRefresh();
            }
        });
        
        // Load on page load
        loadStats();
        startStatsRefresh();
        loadOrders();
        loadCustomers();
        loadPayments();
        loadReports();
    </script>
</body>
</html>

