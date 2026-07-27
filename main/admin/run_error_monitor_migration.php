<?php
/**
 * Error Monitor - Database Migration
 * Creates the `error_logs` table for centralized error tracking
 * 
 * Run this script in your browser once after deploying the new code:
 *   https://yourdomain.com/main/admin/run_error_monitor_migration.php
 * 
 * Safe to run multiple times — it uses "CREATE TABLE IF NOT EXISTS"
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Require admin login for security
session_start();
$isAuthorized = isset($_SESSION['user_id']) || isset($_SESSION['superadmin_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error Monitor Migration</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f0f4f8;
        }
        .card {
            background: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        h1 { color: #1a1a2e; margin: 0 0 8px 0; font-size: 24px; }
        .subtitle { color: #64748b; margin-bottom: 24px; }
        .step { margin: 16px 0; padding: 12px 16px; border-radius: 10px; font-size: 14px; }
        .step.pending { background: #f8fafc; border: 1px solid #e2e8f0; color: #475569; }
        .step.success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
        .step.error { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
        .btn {
            display: inline-block;
            padding: 12px 28px;
            background: #059669;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn:hover { background: #047857; transform: translateY(-1px); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .btn-secondary {
            display: inline-block;
            padding: 10px 20px;
            background: #e2e8f0;
            color: #475569;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            margin-left: 8px;
        }
        .btn-secondary:hover { background: #cbd5e1; }
        .log { 
            background: #1e293b; 
            color: #e2e8f0; 
            padding: 16px; 
            border-radius: 10px; 
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            line-height: 1.6;
            overflow-x: auto;
            white-space: pre-wrap;
            margin: 16px 0;
        }
        .badge { 
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
        }
        .badge-success { background: #86efac; color: #166534; }
        .badge-error { background: #fca5a5; color: #991b1b; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🛡️ Error Monitor — Database Migration</h1>
        <p class="subtitle">Creates the <code>error_logs</code> table for real-time error tracking across PHP, JavaScript, database, and state machine errors.</p>

        <?php if (!$isAuthorized): ?>
            <div class="step error">
                <strong>⛔ Unauthorized</strong><br>
                Please <a href="login.php" style="color:#059669;font-weight:600;">login as admin</a> first, then run this migration.
            </div>
            <p><a href="login.php" class="btn">Go to Login</a></p>
        <?php else: ?>
            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                echo '<h2 style="font-size:18px;margin:20px 0 12px;">📋 Migration Results</h2>';
                echo '<div class="log">';

                try {
                    require_once __DIR__ . '/../db_connection.php';
                    $conn = getConnection();

                    // Step 1: Create table
                    echo "▶ Step 1/2: Creating error_logs table...\n";
                    $sql = "
                        CREATE TABLE IF NOT EXISTS `error_logs` (
                            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            `restaurant_id` VARCHAR(50) NULL DEFAULT NULL,
                            `source` ENUM('php','js','api','auth','db','state_machine','custom') NOT NULL DEFAULT 'php',
                            `severity` ENUM('critical','error','warning','info','debug') NOT NULL DEFAULT 'error',
                            `message` TEXT NOT NULL,
                            `file` VARCHAR(500) DEFAULT NULL,
                            `line` INT UNSIGNED DEFAULT NULL,
                            `trace` TEXT DEFAULT NULL,
                            `context` JSON DEFAULT NULL,
                            `url` VARCHAR(500) DEFAULT NULL,
                            `user_agent` VARCHAR(500) DEFAULT NULL,
                            `ip_address` VARCHAR(45) DEFAULT NULL,
                            `user_id` INT UNSIGNED DEFAULT NULL,
                            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                            `is_acknowledged` TINYINT(1) NOT NULL DEFAULT 0,
                            `acknowledged_by` VARCHAR(100) DEFAULT NULL,
                            `acknowledged_at` DATETIME DEFAULT NULL,
                            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            INDEX `idx_source` (`source`),
                            INDEX `idx_severity` (`severity`),
                            INDEX `idx_restaurant` (`restaurant_id`),
                            INDEX `idx_is_read` (`is_read`),
                            INDEX `idx_created_at` (`created_at`),
                            INDEX `idx_source_severity` (`source`, `severity`),
                            INDEX `idx_restaurant_created` (`restaurant_id`, `created_at`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ";
                    $conn->exec($sql);
                    echo "✅ Table `error_logs` created successfully.\n\n";

                    // Step 2: Verify
                    echo "▶ Step 2/2: Verifying table structure...\n";
                    $stmt = $conn->query("SHOW COLUMNS FROM error_logs");
                    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    echo "✅ Found " . count($cols) . " columns:\n";
                    foreach ($cols as $col) {
                        echo "   • {$col['Field']} ({$col['Type']})\n";
                    }
                    echo "\n✅ Migration complete! 🎉\n";

                } catch (PDOException $e) {
                    echo "❌ Database error: " . $e->getMessage() . "\n";
                } catch (Exception $e) {
                    echo "❌ Error: " . $e->getMessage() . "\n";
                }

                echo '</div>';

                echo '<div style="margin-top:20px;display:flex;align-items:center;gap:12px;">';
                echo '<span class="badge badge-success">✅ DONE</span>';
                echo '<span style="color:#475569;font-size:14px;">The Error Monitor is now active! Go check it out.</span>';
                echo '</div>';
                
                echo '<div style="margin-top:20px;">';
                echo '<a href="index.php" class="btn-secondary">← Back to Admin</a>';
                echo '<a href="../views/dashboard.php" class="btn" style="margin-left:8px;">Go to Error Monitor</a>';
                echo '</div>';

            } else {
                // Show confirmation button
                ?>
                <div class="step pending">
                    <strong>ℹ️ Before you run this:</strong><br>
                    • This creates the <code>error_logs</code> table in your database<br>
                    • It's safe to run multiple times (uses <code>IF NOT EXISTS</code>)<br>
                    • No existing data will be affected<br>
                    • The Error Monitor will start capturing errors immediately after
                </div>

                <form method="POST" style="margin-top:24px;">
                    <button type="submit" class="btn" onclick="this.disabled=true;this.innerHTML='⏳ Running...';this.form.submit();">
                        🚀 Run Migration Now
                    </button>
                </form>
                <?php
            }
            ?>
        <?php endif; ?>
    </div>
</body>
</html>
