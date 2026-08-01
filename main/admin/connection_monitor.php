<?php
/**
 * Database Connection Monitor
 * Shows active connections and connection statistics
 */

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Check if user is logged in
if (!isSessionValid() || (!isset($_SESSION['user_id']) && !isset($_SESSION['staff_id']) && !isset($_SESSION['branch_admin_id']))) {
    header('Location: login.php');
    exit();
}

// Include database connection
require_once __DIR__ . '/../db_connection.php';

// Auto-refresh every 5 seconds (using AJAX, not meta refresh)
$refresh_interval = 5;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Connection Monitor</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0f1419;
            color: #e6e6e6;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            color: #4a9eff;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .refresh-info {
            font-size: 14px;
            color: #888;
            font-weight: normal;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #1a1f2e;
            border: 1px solid #2d3748;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .stat-card h3 {
            color: #a0aec0;
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #4a9eff;
        }
        .stat-value.warning {
            color: #f6ad55;
        }
        .stat-value.danger {
            color: #fc8181;
        }
        .stat-value.success {
            color: #68d391;
        }
        .connections-table {
            background: #1a1f2e;
            border: 1px solid #2d3748;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: #2d3748;
            color: #e6e6e6;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #4a9eff;
        }
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #2d3748;
        }
        tr:hover {
            background: #252b3a;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-active {
            background: #22543d;
            color: #68d391;
        }
        .status-sleep {
            background: #744210;
            color: #f6ad55;
        }
        .status-locked {
            background: #742a2a;
            color: #fc8181;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #4a9eff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #3182ce;
        }
        .log-section {
            background: #1a1f2e;
            border: 1px solid #2d3748;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        .log-section h3 {
            color: #4a9eff;
            margin-bottom: 15px;
        }
        .log-entry {
            padding: 10px;
            margin-bottom: 10px;
            background: #0f1419;
            border-left: 3px solid #4a9eff;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        .log-entry.error {
            border-left-color: #fc8181;
            color: #fc8181;
        }
        .log-entry.warning {
            border-left-color: #f6ad55;
            color: #f6ad55;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            Database Connection Monitor
            <span class="refresh-info">Auto-refresh: <?php echo $refresh_interval; ?>s</span>
        </h1>

        <?php
        try {
            // Get connection using getConnection() for lazy connection support
            if (function_exists('getConnection')) {
                $conn = getConnection();
            } else {
                // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
                global $pdo;
                $conn = $pdo ?? null;
                if (!$conn) {
                    throw new Exception('Database connection not available');
                }
            }
            
            // Get connection statistics
            $stats = [];
            
            // Get max connections allowed
            $stmt = $conn->query("SHOW VARIABLES LIKE 'max_connections'");
            $max_conn = $stmt->fetch(PDO::FETCH_ASSOC);
            $max_connections = (int)($max_conn['Value'] ?? 151);
            
            // Get max connections per hour
            $stmt = $conn->query("SHOW VARIABLES LIKE 'max_connections_per_hour'");
            $max_conn_hour = $stmt->fetch(PDO::FETCH_ASSOC);
            $max_connections_per_hour = (int)($max_conn_hour['Value'] ?? 500);
            
            // Get current connections
            $stmt = $conn->query("SHOW STATUS LIKE 'Threads_connected'");
            $threads = $stmt->fetch(PDO::FETCH_ASSOC);
            $current_connections = (int)($threads['Value'] ?? 0);
            
            // Get max used connections
            $stmt = $conn->query("SHOW STATUS LIKE 'Max_used_connections'");
            $max_used = $stmt->fetch(PDO::FETCH_ASSOC);
            $max_used_connections = (int)($max_used['Value'] ?? 0);
            
            // Get connection errors
            $stmt = $conn->query("SHOW STATUS LIKE 'Connection_errors_max_connections'");
            $conn_errors = $stmt->fetch(PDO::FETCH_ASSOC);
            $connection_errors = (int)($conn_errors['Value'] ?? 0);
            
            // Get current user connections
            $stmt = $conn->query("SHOW STATUS LIKE 'Threads_running'");
            $threads_running = $stmt->fetch(PDO::FETCH_ASSOC);
            $running_threads = (int)($threads_running['Value'] ?? 0);
            
            // Get process list
            $process_list = [];
            try {
                $stmt = $conn->query("SHOW PROCESSLIST");
                $process_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // May not have permission
            }
            
            // Calculate connection percentage
            $connection_percentage = $max_connections > 0 ? ($current_connections / $max_connections) * 100 : 0;
            
            // Determine status color
            $status_class = 'success';
            if ($connection_percentage > 80) {
                $status_class = 'danger';
            } elseif ($connection_percentage > 60) {
                $status_class = 'warning';
            }
        ?>
        
        <div class="stats-grid" id="statsGrid">
            <div class="stat-card">
                <h3>Current Connections</h3>
                <div class="stat-value <?php echo $status_class; ?>" id="statCurrentConnections">
                    <?php echo $current_connections; ?>
                </div>
                <small>of <span id="statMaxConnectionsA"><?php echo $max_connections; ?></span> max</small>
            </div>

            <div class="stat-card">
                <h3>Connection Usage</h3>
                <div class="stat-value <?php echo $status_class; ?>" id="statConnectionPercentage">
                    <?php echo number_format($connection_percentage, 1); ?>%
                </div>
                <small><span id="statCurrentConnectionsB"><?php echo $current_connections; ?></span> / <span id="statMaxConnectionsB"><?php echo $max_connections; ?></span></small>
            </div>

            <div class="stat-card">
                <h3>Max Used (Ever)</h3>
                <div class="stat-value" id="statMaxUsed">
                    <?php echo $max_used_connections; ?>
                </div>
                <small>Peak connections</small>
            </div>

            <div class="stat-card">
                <h3>Running Threads</h3>
                <div class="stat-value" id="statRunningThreads">
                    <?php echo $running_threads; ?>
                </div>
                <small>Active queries</small>
            </div>

            <div class="stat-card">
                <h3>Connection Errors</h3>
                <div class="stat-value <?php echo $connection_errors > 0 ? 'danger' : 'success'; ?>" id="statConnectionErrors">
                    <?php echo $connection_errors; ?>
                </div>
                <small>Max connections exceeded</small>
            </div>

            <div class="stat-card">
                <h3>Max Per Hour</h3>
                <div class="stat-value" id="statMaxPerHour">
                    <?php echo $max_connections_per_hour; ?>
                </div>
                <small>Connection limit per hour</small>
            </div>
        </div>

        <div class="connections-table" id="connectionsTableWrap" <?php echo empty($process_list) ? 'style="display:none;"' : ''; ?>>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Host</th>
                        <th>Database</th>
                        <th>Command</th>
                        <th>Time</th>
                        <th>State</th>
                        <th>Info</th>
                    </tr>
                </thead>
                <tbody id="processListBody">
                    <?php foreach ($process_list as $process):
                        $state_class = 'status-active';
                        if ($process['Command'] === 'Sleep') {
                            $state_class = 'status-sleep';
                        } elseif (strpos($process['State'] ?? '', 'Locked') !== false) {
                            $state_class = 'status-locked';
                        }
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($process['Id']); ?></td>
                        <td><?php echo htmlspecialchars($process['User']); ?></td>
                        <td><?php echo htmlspecialchars($process['Host']); ?></td>
                        <td><?php echo htmlspecialchars($process['db'] ?? '-'); ?></td>
                        <td>
                            <span class="status-badge <?php echo $state_class; ?>">
                                <?php echo htmlspecialchars($process['Command']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($process['Time']); ?>s</td>
                        <td><?php echo htmlspecialchars($process['State'] ?? '-'); ?></td>
                        <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?php echo htmlspecialchars(substr($process['Info'] ?? '-', 0, 100)); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="log-section">
            <h3>Connection Information</h3>
            <div class="log-entry" id="connectionInfoLog">
                <?php
                // Get connection info
                global $host, $dbname, $options;
                $db_host = $host ?? 'localhost';
                $db_name = $dbname ?? 'N/A';
                $is_persistent = isset($options[PDO::ATTR_PERSISTENT]) && $options[PDO::ATTR_PERSISTENT];
                ?>
                <strong>Database Host:</strong> <?php echo htmlspecialchars($db_host); ?><br>
                <strong>Database Name:</strong> <?php echo htmlspecialchars($db_name); ?><br>
                <strong>Connection Type:</strong> <?php echo $is_persistent ? 'Persistent' : 'Non-Persistent (Optimized)'; ?><br>
                <strong>PHP Version:</strong> <?php echo PHP_VERSION; ?><br>
                <strong>PDO Driver:</strong> <?php echo $conn->getAttribute(PDO::ATTR_DRIVER_NAME); ?><br>
                <strong>Server Info:</strong> <?php echo $conn->getAttribute(PDO::ATTR_SERVER_INFO); ?><br>
                <?php
                // Get connection stats if available
                if (isset($GLOBALS['db_connection_stats'])) {
                    $stats = $GLOBALS['db_connection_stats'];
                ?>
                <strong>Connection Stats:</strong><br>
                &nbsp;&nbsp;Total Attempts: <?php echo $stats['attempts'] ?? 0; ?><br>
                &nbsp;&nbsp;Successful: <?php echo $stats['success'] ?? 0; ?><br>
                &nbsp;&nbsp;Failed: <?php echo $stats['failures'] ?? 0; ?><br>
                &nbsp;&nbsp;Retries: <?php echo $stats['retries'] ?? 0; ?><br>
                &nbsp;&nbsp;Success Rate: <?php echo ($stats['attempts'] ?? 0) > 0 ? number_format((($stats['success'] ?? 0) / ($stats['attempts'] ?? 1)) * 100, 2) : 0; ?>%
                <?php } ?>
            </div>
        </div>

        <a href="dashboard.php" class="btn">Back to Dashboard</a>
        <button class="btn" id="refreshBtn" style="margin-left: 10px; cursor: pointer; border: none;">Refresh Now</button>

        <?php
        } catch (Exception $e) {
            echo '<div class="log-section">';
            echo '<h3>Error</h3>';
            echo '<div class="log-entry error">';
            echo 'Error: ' . htmlspecialchars($e->getMessage());
            echo '</div>';
            echo '</div>';
        }
        ?>
    </div>
    
    <script>
        // Auto-refresh using JavaScript instead of meta refresh (more efficient)
        // Live-polls get_connection_stats.php and patches the DOM in place —
        // this used to call window.location.reload() every 5 seconds, which
        // flashed the whole page and reset scroll position constantly.
        let refreshInterval = null;
        const refreshIntervalMs = <?php echo $refresh_interval * 1000; ?>;
        let lastRefreshTime = Date.now();

        function statClass(pct) {
            if (pct > 80) return 'danger';
            if (pct > 60) return 'warning';
            return 'success';
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = String(str ?? '');
            return div.innerHTML;
        }

        function renderProcessRow(p) {
            let stateClass = 'status-active';
            if (p.command === 'Sleep') stateClass = 'status-sleep';
            else if ((p.state || '').indexOf('Locked') !== -1) stateClass = 'status-locked';
            return `<tr>
                <td>${escapeHtml(p.id)}</td>
                <td>${escapeHtml(p.user)}</td>
                <td>${escapeHtml(p.host)}</td>
                <td>${escapeHtml(p.db)}</td>
                <td><span class="status-badge ${stateClass}">${escapeHtml(p.command)}</span></td>
                <td>${escapeHtml(p.time)}s</td>
                <td>${escapeHtml(p.state)}</td>
                <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(p.info)}</td>
            </tr>`;
        }

        async function refreshData() {
            if (document.hidden) return;
            try {
                const res = await fetch('../api/get_connection_stats.php', { cache: 'no-store' });
                const data = await res.json();
                if (!data.success) return;

                const cls = statClass(data.connection_percentage);
                const setText = (id, text) => { const el = document.getElementById(id); if (el) el.textContent = text; };
                const setClass = (id, extra) => { const el = document.getElementById(id); if (el) el.className = 'stat-value ' + extra; };

                setText('statCurrentConnections', data.current_connections);
                setClass('statCurrentConnections', cls);
                setText('statMaxConnectionsA', data.max_connections);
                setText('statConnectionPercentage', data.connection_percentage.toFixed(1) + '%');
                setClass('statConnectionPercentage', cls);
                setText('statCurrentConnectionsB', data.current_connections);
                setText('statMaxConnectionsB', data.max_connections);
                setText('statMaxUsed', data.max_used_connections);
                setText('statRunningThreads', data.running_threads);
                setText('statConnectionErrors', data.connection_errors);
                setClass('statConnectionErrors', data.connection_errors > 0 ? 'danger' : 'success');
                setText('statMaxPerHour', data.max_connections_per_hour);

                const tableWrap = document.getElementById('connectionsTableWrap');
                const tbody = document.getElementById('processListBody');
                if (data.process_list && data.process_list.length > 0) {
                    tableWrap.style.display = '';
                    tbody.innerHTML = data.process_list.map(renderProcessRow).join('');
                } else {
                    tableWrap.style.display = 'none';
                }

                const ci = data.connection_info;
                let infoHtml = `<strong>Database Host:</strong> ${escapeHtml(ci.host)}<br>
                    <strong>Database Name:</strong> ${escapeHtml(ci.db_name)}<br>
                    <strong>Connection Type:</strong> ${ci.is_persistent ? 'Persistent' : 'Non-Persistent (Optimized)'}<br>
                    <strong>PHP Version:</strong> ${escapeHtml(ci.php_version)}<br>
                    <strong>PDO Driver:</strong> ${escapeHtml(ci.driver)}<br>
                    <strong>Server Info:</strong> ${escapeHtml(ci.server_info)}<br>`;
                if (ci.stats) {
                    const s = ci.stats;
                    const rate = (s.attempts || 0) > 0 ? (((s.success || 0) / (s.attempts || 1)) * 100).toFixed(2) : 0;
                    infoHtml += `<strong>Connection Stats:</strong><br>
                        &nbsp;&nbsp;Total Attempts: ${s.attempts || 0}<br>
                        &nbsp;&nbsp;Successful: ${s.success || 0}<br>
                        &nbsp;&nbsp;Failed: ${s.failures || 0}<br>
                        &nbsp;&nbsp;Retries: ${s.retries || 0}<br>
                        &nbsp;&nbsp;Success Rate: ${rate}%`;
                }
                document.getElementById('connectionInfoLog').innerHTML = infoHtml;

                lastRefreshTime = Date.now();
            } catch (e) {
                // Network hiccup — try again next tick rather than reloading blind.
                console.warn('Connection monitor refresh failed:', e);
            }
        }

        // Start live polling
        refreshInterval = setInterval(refreshData, refreshIntervalMs);

        // Manual refresh button now also just re-fetches, no page reload
        document.getElementById('refreshBtn')?.addEventListener('click', refreshData);

        // Pause polling when the tab is hidden, resume (with an immediate
        // refresh) when it becomes visible again.
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                if (refreshInterval) {
                    clearInterval(refreshInterval);
                    refreshInterval = null;
                }
            } else if (!refreshInterval) {
                refreshInterval = setInterval(refreshData, refreshIntervalMs);
                refreshData();
            }
        });

        // Update refresh info display
        function updateRefreshInfo() {
            const refreshInfo = document.querySelector('.refresh-info');
            if (refreshInfo) {
                const secondsSinceRefresh = Math.floor((Date.now() - lastRefreshTime) / 1000);
                refreshInfo.textContent = `Auto-refresh: <?php echo $refresh_interval; ?>s (Last: ${secondsSinceRefresh}s ago)`;
            }
        }

        // Update refresh info every second
        setInterval(updateRefreshInfo, 1000);
    </script>
</body>
</html>

