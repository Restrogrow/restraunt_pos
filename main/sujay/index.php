<?php
/**
 * Sujay Testing Dashboard
 * Quick access to all important testing tools and connections
 */

require_once __DIR__ . '/../db_connection.php';

// Get connection using getConnection() for lazy connection support
if (function_exists('getConnection')) {
    $conn = getConnection();
} else {
    // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
    global $pdo;
    $conn = $pdo ?? null;
    if (!$conn) {
        die('Database connection not available');
    }
}

// Create superadmin user "sujay" if it doesn't exist
try {
    $checkStmt = $conn->prepare("SELECT id FROM super_admins WHERE username = 'sujay' LIMIT 1");
    $checkStmt->execute();
    $existing = $checkStmt->fetch();
    
    if (!$existing) {
        // Create superadmin user "sujay" with password "sujay123"
        $passwordHash = password_hash('sujay123', PASSWORD_DEFAULT);
        $insertStmt = $conn->prepare("INSERT INTO super_admins (username, email, password_hash, is_active) VALUES ('sujay', 'sujay@restrogrow.com', ?, 1)");
        $insertStmt->execute([$passwordHash]);
        $userCreated = true;
    } else {
        $userCreated = false;
    }
} catch (Exception $e) {
    $userError = $e->getMessage();
}

// Test database connection
$dbStatus = 'success';
$dbMessage = 'Connected successfully';
try {
    $testQuery = $conn->query("SELECT 1");
    $testQuery->fetch();
} catch (Exception $e) {
    $dbStatus = 'error';
    $dbMessage = $e->getMessage();
}

// Get connection stats
$connectionStats = getConnectionStats();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Testing Dashboard - RestroGrow</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1e40af;
            --primary-dark: #1e3a8a;
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .navbar {
            background: white;
            border-bottom: 1px solid var(--gray-200);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            text-decoration: none;
        }

        .nav-links {
            display: flex;
            gap: 2rem;
            align-items: center;
        }

        .nav-links a {
            color: var(--gray-600);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: var(--gray-600);
            font-size: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.2s;
        }

        .stat-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-color: var(--gray-300);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .stat-value {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .stat-details {
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        .section {
            margin-bottom: 3rem;
        }

        .section-header {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-900);
        }

        .section-divider {
            flex: 1;
            height: 1px;
            background: var(--gray-200);
            margin-left: 1rem;
        }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            padding: 1.5rem;
            text-decoration: none;
            color: inherit;
            display: block;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary);
            transform: scaleX(0);
            transition: transform 0.2s;
        }

        .card:hover {
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .card:hover::before {
            transform: scaleX(1);
        }

        .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
            transition: background 0.2s;
        }

        .card:hover .card-icon {
            background: var(--primary);
            color: white;
        }

        .card-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .card-description {
            font-size: 0.875rem;
            color: var(--gray-600);
            line-height: 1.5;
        }

        .info-box {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-left: 4px solid var(--primary);
            border-radius: 8px;
            padding: 1.25rem;
            margin-top: 1rem;
        }

        .info-box-title {
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.75rem;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .info-box-content {
            font-size: 0.875rem;
            color: var(--gray-700);
        }

        .info-box code {
            background: white;
            border: 1px solid var(--gray-200);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 0.8125rem;
            color: var(--primary);
        }

        .info-box a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .info-box a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .cards-grid {
                grid-template-columns: 1fr;
            }

            .nav-container {
                padding: 0 1rem;
            }

            .nav-links {
                gap: 1rem;
                font-size: 0.875rem;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <a href="../index.html" class="logo">RestroGrow</a>
            <div class="nav-links">
                <a href="testing.php">More Options</a>
                <a href="https://restrogrow.com/main/superadmin/dashboard.php">Dashboard</a>
                <a href="https://restrogrow.com/main/superadmin/login.php">Login</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h1>Testing Dashboard</h1>
            <p>Quick access to testing tools, APIs, and system configurations</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Database Status</div>
                    <span class="stat-badge <?php echo $dbStatus === 'success' ? 'badge-success' : 'badge-error'; ?>">
                        <?php echo $dbStatus === 'success' ? 'Online' : 'Offline'; ?>
                    </span>
                </div>
                <div class="stat-value"><?php echo $connectionStats['success']; ?></div>
                <div class="stat-details">Successful connections</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Connection Attempts</div>
                    <span class="stat-badge badge-info">Total</span>
                </div>
                <div class="stat-value"><?php echo $connectionStats['attempts']; ?></div>
                <div class="stat-details">Total connection attempts</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Failed Connections</div>
                    <span class="stat-badge <?php echo $connectionStats['failures'] > 0 ? 'badge-error' : 'badge-success'; ?>">
                        <?php echo $connectionStats['failures'] > 0 ? 'Issues' : 'None'; ?>
                    </span>
                </div>
                <div class="stat-value"><?php echo $connectionStats['failures']; ?></div>
                <div class="stat-details">Connection failures</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Superadmin User</div>
                    <span class="stat-badge <?php echo isset($userCreated) && !$userCreated ? 'badge-success' : (isset($userCreated) ? 'badge-info' : 'badge-error'); ?>">
                        <?php echo isset($userCreated) && !$userCreated ? 'Active' : (isset($userCreated) ? 'Created' : 'Error'); ?>
                    </span>
                </div>
                <div class="stat-value">sujay</div>
                <div class="stat-details">Superadmin account</div>
            </div>
        </div>

        <div class="stat-card" style="margin-bottom: 2rem;">
            <div class="stat-title" style="margin-bottom: 1rem;">Superadmin Credentials</div>
            <div class="info-box" style="margin-top: 0;">
                <div class="info-box-content">
                    <strong>Username:</strong> <code>sujay</code><br>
                    <strong>Password:</strong> <code>sujay123</code>
                </div>
                <a href="https://restrogrow.com/main/superadmin/login.php">
                    Access Superadmin Dashboard →
                </a>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <h2 class="section-title">Testing Tools</h2>
                <div class="section-divider"></div>
            </div>
            <div class="cards-grid">
                <a href="https://restrogrow.com/main/test_image_loading.php" class="card">
                    <div class="card-icon">🖼️</div>
                    <div class="card-title">Image Loading Test</div>
                    <div class="card-description">Test image loading from database and file system. Check uploads directory and image paths.</div>
                </a>

                <a href="https://restrogrow.com/main/db_connection.php" class="card" target="_blank">
                    <div class="card-icon">🔌</div>
                    <div class="card-title">Database Connection</div>
                    <div class="card-description">View database connection configuration and test connection status.</div>
                </a>

                <a href="https://restrogrow.com/main/admin/test_email.php" class="card">
                    <div class="card-icon">📧</div>
                    <div class="card-title">Email Test</div>
                    <div class="card-description">Test email sending functionality and SMTP configuration.</div>
                </a>

                <a href="https://restrogrow.com/main/admin/connection_monitor.php" class="card">
                    <div class="card-icon">📡</div>
                    <div class="card-title">Connection Monitor</div>
                    <div class="card-description">Monitor database connections and view connection statistics.</div>
                </a>

                <a href="https://restrogrow.com/main/admin/db_test.php" class="card">
                    <div class="card-icon">⚡</div>
                    <div class="card-title">Database Performance Test</div>
                    <div class="card-description">Test database speed, query performance, indexes, buffer pool, and connection status.</div>
                </a>

                <a href="https://restrogrow.com/main/admin/run_indexes_both_dbs.php" class="card">
                    <div class="card-icon">📊</div>
                    <div class="card-title">Run Database Indexes</div>
                    <div class="card-description">Add critical database indexes to improve query performance on both production and localhost.</div>
                </a>

                <a href="https://restrogrow.com/main/admin/run_password_reset_table_both_dbs.php" class="card">
                    <div class="card-icon">🔐</div>
                    <div class="card-title">Password Reset Tokens Migration</div>
                    <div class="card-description">Create password_reset_tokens table and add idx_created_at index on both production and localhost databases.</div>
                </a>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <h2 class="section-title">Password Reset Data</h2>
                <div class="section-divider"></div>
            </div>
            <div class="card" style="margin-bottom: 0;">
                <div class="card-body">
                    <div style="overflow-x:auto;">
                        <table style="width:100%;border-collapse:collapse;font-size:0.95rem;">
                            <thead>
                                <tr style="background:var(--gray-100);">
                                    <th style="padding:12px;border-bottom:1px solid var(--gray-200);text-align:left;font-weight:600;font-size:0.8rem;text-transform:uppercase;">ID</th>
                                    <th style="padding:12px;border-bottom:1px solid var(--gray-200);text-align:left;font-weight:600;font-size:0.8rem;text-transform:uppercase;">User</th>
                                    <th style="padding:12px;border-bottom:1px solid var(--gray-200);text-align:left;font-weight:600;font-size:0.8rem;text-transform:uppercase;">Restaurant</th>
                                    <th style="padding:12px;border-bottom:1px solid var(--gray-200);text-align:left;font-weight:600;font-size:0.8rem;text-transform:uppercase;">Email</th>
                                    <th style="padding:12px;border-bottom:1px solid var(--gray-200);text-align:left;font-weight:600;font-size:0.8rem;text-transform:uppercase;">Token</th>
                                    <th style="padding:12px;border-bottom:1px solid var(--gray-200);text-align:left;font-weight:600;font-size:0.8rem;text-transform:uppercase;">Status</th>
                                    <th style="padding:12px;border-bottom:1px solid var(--gray-200);text-align:left;font-weight:600;font-size:0.8rem;text-transform:uppercase;">Created</th>
                                    <th style="padding:12px;border-bottom:1px solid var(--gray-200);text-align:left;font-weight:600;font-size:0.8rem;text-transform:uppercase;">Expires</th>
                                </tr>
                            </thead>
                            <tbody id="passwordResetTbody">
                                <tr><td colspan="8" style="text-align:center;padding:20px;color:var(--gray-600);">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;">
                        <button class="btn btn-outline" id="prevResetPage" style="padding:8px 16px;border:1px solid var(--gray-300);border-radius:6px;background:white;cursor:pointer;">Prev</button>
                        <div id="resetPageInfo" style="color:var(--gray-600);">Page 1</div>
                        <button class="btn btn-outline" id="nextResetPage" style="padding:8px 16px;border:1px solid var(--gray-300);border-radius:6px;background:white;cursor:pointer;">Next</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <h2 class="section-title">Configuration Files</h2>
                <div class="section-divider"></div>
            </div>
            <div class="cards-grid">
                <a href="https://restrogrow.com/main/config/session_config.php" class="card" target="_blank">
                    <div class="card-icon">🔐</div>
                    <div class="card-title">Session Configuration</div>
                    <div class="card-description">Session configuration and security settings.</div>
                </a>

                <a href="https://restrogrow.com/main/config/email_config.php" class="card" target="_blank">
                    <div class="card-icon">⚙️</div>
                    <div class="card-title">Email Configuration</div>
                    <div class="card-description">Email server configuration and SMTP settings.</div>
                </a>

                <a href="https://restrogrow.com/main/config/db_migration.php" class="card" target="_blank">
                    <div class="card-icon">🔄</div>
                    <div class="card-title">Database Migration</div>
                    <div class="card-description">Database migration scripts and schema updates.</div>
                </a>

                <a href="https://restrogrow.com/main/superadmin/dashboard.php" class="card">
                    <div class="card-icon">📊</div>
                    <div class="card-title">Superadmin Dashboard</div>
                    <div class="card-description">Access the superadmin dashboard for managing restaurants.</div>
                </a>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <h2 class="section-title">API Endpoints</h2>
                <div class="section-divider"></div>
            </div>
            <div class="cards-grid">
                <a href="https://restrogrow.com/main/api/image.php" class="card" target="_blank">
                    <div class="card-icon">🖼️</div>
                    <div class="card-title">Image API</div>
                    <div class="card-description">Image serving endpoint for database and file-based images.</div>
                </a>

                <a href="https://restrogrow.com/main/api/get_menu_items.php" class="card" target="_blank">
                    <div class="card-icon">🍽️</div>
                    <div class="card-title">Menu Items API</div>
                    <div class="card-description">Get menu items for restaurants.</div>
                </a>

                <a href="https://restrogrow.com/main/api/get_dashboard_stats.php" class="card" target="_blank">
                    <div class="card-icon">📈</div>
                    <div class="card-title">Dashboard Stats API</div>
                    <div class="card-description">Get dashboard statistics and analytics.</div>
                </a>

                <a href="https://restrogrow.com/main/superadmin/api.php" class="card" target="_blank">
                    <div class="card-icon">⚡</div>
                    <div class="card-title">Superadmin API</div>
                    <div class="card-description">Superadmin API endpoints for restaurant management.</div>
                </a>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <h2 class="section-title">Documentation</h2>
                <div class="section-divider"></div>
            </div>
            <div class="cards-grid">
                <a href="https://restrogrow.com/main/docs/DATABASE_CONNECTION_OPTIMIZATION.md" class="card" target="_blank">
                    <div class="card-icon">📖</div>
                    <div class="card-title">Database Connection Docs</div>
                    <div class="card-description">Documentation on database connection optimization.</div>
                </a>

                <a href="https://restrogrow.com/main/docs/IMAGE_STORAGE_OPTIONS.md" class="card" target="_blank">
                    <div class="card-icon">📸</div>
                    <div class="card-title">Image Storage Docs</div>
                    <div class="card-description">Documentation on image storage options and implementation.</div>
                </a>

                <a href="https://restrogrow.com/main/docs/DEPLOYMENT.md" class="card" target="_blank">
                    <div class="card-icon">🚀</div>
                    <div class="card-title">Deployment Guide</div>
                    <div class="card-description">Deployment instructions and server configuration.</div>
                </a>

                <a href="https://restrogrow.com/main/database/database_schema.sql" class="card" target="_blank">
                    <div class="card-icon">🗄️</div>
                    <div class="card-title">Database Schema</div>
                    <div class="card-description">Complete database schema and table structures.</div>
                </a>
            </div>
        </div>
    </div>

    <script>
        // Password Reset Data
        let resetPage = 1, resetLimit = 10;
        async function loadPasswordResetData(){
            const tbody = document.getElementById('passwordResetTbody');
            if (!tbody) return;
            
            try {
                const res = await fetch('https://restrogrow.com/main/superadmin/api.php?action=getPasswordResetData&page='+resetPage+'&limit='+resetLimit);
                
                if (!res.ok) {
                    // If we get HTML back, it means we're being redirected to login
                    const contentType = res.headers.get('content-type');
                    if (contentType && contentType.includes('text/html')) {
                        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--gray-600);">Authentication required. <a href="https://restrogrow.com/main/superadmin/login.php" style="color:var(--primary);text-decoration:underline;">Login as Superadmin</a> to view password reset data.</td></tr>';
                        return;
                    }
                    throw new Error('HTTP error: ' + res.status);
                }
                
                const text = await res.text();
                
                // Check if response is HTML (login page redirect)
                if (text.trim().startsWith('<!DOCTYPE') || text.trim().startsWith('<html')) {
                    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--gray-600);">Authentication required. <a href="https://restrogrow.com/main/superadmin/login.php" style="color:var(--primary);text-decoration:underline;">Login as Superadmin</a> to view password reset data.</td></tr>';
                    return;
                }
                
                let data;
                try {
                    data = JSON.parse(text);
                } catch(e) {
                    console.error('JSON parse error:', text.substring(0, 200));
                    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--gray-600);">Invalid response from server. Authentication may be required.</td></tr>';
                    return;
                }
                
                if(data.success && data.tokens){
                    if (data.tokens.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--gray-600);">No password reset tokens found</td></tr>';
                    } else {
                        tbody.innerHTML = data.tokens.map(t => {
                            const isUsed = Number(t.used) === 1;
                            const isExpired = new Date(t.expires_at) < new Date();
                            const status = isUsed ? 'Used' : (isExpired ? 'Expired' : 'Active');
                            const badgeStyle = isUsed ? 'background:#fde68a;color:#92400e;' : (isExpired ? 'background:#fee2e2;color:#991b1b;' : 'background:#d1fae5;color:#065f46;');
                            return `
                                <tr>
                                    <td style="padding:12px;border-bottom:1px solid var(--gray-200);">${t.id}</td>
                                    <td style="padding:12px;border-bottom:1px solid var(--gray-200);">${t.username || 'N/A'}</td>
                                    <td style="padding:12px;border-bottom:1px solid var(--gray-200);">${t.restaurant_name || 'N/A'}</td>
                                    <td style="padding:12px;border-bottom:1px solid var(--gray-200);">${t.email || 'N/A'}</td>
                                    <td style="padding:12px;border-bottom:1px solid var(--gray-200);font-family:monospace;font-size:0.8rem;">${(t.token || '').substring(0,16)}...</td>
                                    <td style="padding:12px;border-bottom:1px solid var(--gray-200);"><span style="padding:4px 12px;border-radius:999px;font-weight:600;font-size:0.8rem;display:inline-block;${badgeStyle}">${status}</span></td>
                                    <td style="padding:12px;border-bottom:1px solid var(--gray-200);">${t.created_at ? new Date(t.created_at).toLocaleString() : 'N/A'}</td>
                                    <td style="padding:12px;border-bottom:1px solid var(--gray-200);">${t.expires_at ? new Date(t.expires_at).toLocaleString() : 'N/A'}</td>
                                </tr>
                            `;
                        }).join('');
                    }
                    const total = data.total || 0;
                    const pages = Math.max(1, Math.ceil(total/resetLimit));
                    const pageInfo = document.getElementById('resetPageInfo');
                    if (pageInfo) {
                        pageInfo.textContent = `Page ${resetPage} of ${pages}`;
                    }
                    const prevBtn = document.getElementById('prevResetPage');
                    const nextBtn = document.getElementById('nextResetPage');
                    if (prevBtn) prevBtn.disabled = resetPage <= 1;
                    if (nextBtn) nextBtn.disabled = resetPage >= pages;
                } else {
                    const message = data.message || 'Error loading data';
                    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--gray-600);">' + message + '</td></tr>';
                }
            } catch(e) {
                console.error('Error loading password reset data:', e);
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--gray-600);">Error: ' + e.message + '. <a href="https://restrogrow.com/main/superadmin/login.php" style="color:var(--primary);text-decoration:underline;">Login as Superadmin</a> may be required.</td></tr>';
            }
        }
        document.getElementById('prevResetPage')?.addEventListener('click', ()=>{ if(resetPage>1){ resetPage--; loadPasswordResetData(); }});
        document.getElementById('nextResetPage')?.addEventListener('click', ()=>{ resetPage++; loadPasswordResetData(); });
        
        // Load password reset data on page load
        loadPasswordResetData();
    </script>
</body>
</html>
