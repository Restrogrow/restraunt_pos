<?php
// Blocked Customers (order abuse blocklist) — admin management page.
// Embedded in the admin dashboard via iframe (same pattern as addons.php).

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Check if user is logged in
if (!isSessionValid() || !isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['restaurant_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/../config/authorization_config.php';
requirePermission(PERMISSION_MANAGE_ORDERS);

require_once __DIR__ . '/../config/order_abuse_guard.php';

$restaurant_id = $_SESSION['restaurant_id'];
$restaurant_name = $_SESSION['restaurant_name'] ?? 'Restaurant';
$username = $_SESSION['username'];

require_once __DIR__ . '/../db_connection.php';
$conn = getConnection();
orderAbuseEnsureTables($conn);

$message = '';
$messageType = 'ok';

// ── Actions ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        $type = ($_POST['type'] ?? 'phone') === 'ip' ? 'ip' : 'phone';
        $value = trim($_POST['value'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $days = (int)($_POST['days'] ?? 0); // 0 = permanent

        if ($type === 'phone') {
            $norm = orderAbuseNormalizePhone($value);
            $ok = $norm !== null && strlen($norm) === 10;
            if (!$ok) {
                $message = 'Enter a valid 10-digit mobile number.';
            } else {
                $value = $norm;
            }
        } else {
            $ok = filter_var($value, FILTER_VALIDATE_IP) !== false;
            if (!$ok) {
                $message = 'Enter a valid IP address.';
            }
        }

        if ($ok) {
            try {
                $stmt = $conn->prepare(
                    "INSERT INTO order_blocklist (restaurant_id, type, value, reason, source, blocked_by, expires_at)
                     VALUES (?, ?, ?, ?, 'manual', ?, IF(? > 0, DATE_ADD(NOW(), INTERVAL ? DAY), NULL))
                     ON DUPLICATE KEY UPDATE
                       reason = VALUES(reason),
                       source = 'manual',
                       blocked_by = VALUES(blocked_by),
                       blocked_at = NOW(),
                       expires_at = IF(? > 0, DATE_ADD(NOW(), INTERVAL ? DAY), NULL)"
                );
                $stmt->execute([$restaurant_id, $type, $value, $reason, $username, $days, $days, $days, $days]);
                $message = ($type === 'phone' ? 'Number ' : 'IP ') . $value . ' blocked.';
            } catch (Exception $e) {
                $message = 'Could not save block: ' . $e->getMessage();
                $messageType = 'err';
            }
        } else {
            $messageType = 'err';
        }
    } elseif ($action === 'unblock') {
        $type = ($_POST['type'] ?? 'phone') === 'ip' ? 'ip' : 'phone';
        $value = trim($_POST['value'] ?? '');
        if (orderAbuseRemoveBlock($conn, $restaurant_id, $type, $value)) {
            $message = 'Entry removed.';
        } else {
            $message = 'Entry not found or already removed.';
            $messageType = 'err';
        }
    }
}

// ── List ─────────────────────────────────────────────────────────────────────
$blocks = [];
try {
    $stmt = $conn->prepare(
        "SELECT id, type, value, reason, source, strikes, last_strike_at, blocked_at, expires_at, blocked_by
         FROM order_blocklist WHERE restaurant_id = ?
         ORDER BY blocked_at DESC LIMIT 500"
    );
    $stmt->execute([$restaurant_id]);
    $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = 'Could not load blocklist: ' . $e->getMessage();
    $messageType = 'err';
}

function blocklistActive($row) {
    return $row['expires_at'] === null || strtotime($row['expires_at']) > time();
}

function blocklistEscape($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blocked Customers - <?php echo blocklistEscape($restaurant_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: #f0f2f5; color: #1a1b1f; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; }
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .header h1 { font-size: 24px; font-weight: 700; color: #151A2D; }
        .card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .card h2 { font-size: 16px; font-weight: 600; margin-bottom: 12px; color: #151A2D; }
        .form-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group label { font-size: 12px; font-weight: 500; color: #555; }
        .form-group select, .form-group input {
            padding: 9px 12px; border: 1px solid #d8dbe2; border-radius: 8px; font-size: 14px; font-family: inherit; background: #fff;
        }
        .form-group input#reason { min-width: 220px; }
        .btn {
            padding: 9px 18px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600;
            cursor: pointer; font-family: inherit; background: #151A2D; color: #fff; transition: opacity .15s;
        }
        .btn:hover { opacity: .85; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; padding: 10px 12px; background: #f7f8fa; color: #555; font-weight: 600; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        td { padding: 10px 12px; border-bottom: 1px solid #f0f1f4; vertical-align: top; }
        tr:hover td { background: #fafbfc; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .badge-phone { background: #e8f0fe; color: #1a56db; }
        .badge-ip { background: #fef3e2; color: #b45309; }
        .badge-auto { background: #fdeaea; color: #b91c1c; }
        .badge-manual { background: #e6f4ea; color: #137333; }
        .badge-inactive { background: #eceff3; color: #6b7280; }
        .reason-cell { max-width: 380px; white-space: pre-wrap; word-break: break-word; color: #555; }
        .unblock-btn {
            padding: 5px 12px; border: 1px solid #dc2626; border-radius: 6px; background: #fff; color: #dc2626;
            font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit; white-space: nowrap;
        }
        .unblock-btn:hover { background: #dc2626; color: #fff; }
        .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; }
        .flash.ok { background: #e6f4ea; color: #137333; }
        .flash.err { background: #fdeaea; color: #b91c1c; }
        .empty { text-align: center; color: #9aa0aa; padding: 30px 0; }
        .muted { color: #8a90a0; font-size: 12px; }
        .strike-pill { display: inline-block; min-width: 22px; text-align: center; background: #fdeaea; color: #b91c1c; border-radius: 999px; font-weight: 700; font-size: 11px; padding: 2px 7px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1><i class="fa-solid fa-ban" style="color:#dc2626"></i> Blocked Customers</h1>
        <span class="muted">Blocked phones/IPs cannot place website orders</span>
    </div>

    <?php if ($message): ?>
        <div class="flash <?php echo blocklistEscape($messageType); ?>"><?php echo blocklistEscape($message); ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Block a customer</h2>
        <form method="POST" action="blocklist.php">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group">
                    <label for="type">Type</label>
                    <select name="type" id="type">
                        <option value="phone">Mobile number</option>
                        <option value="ip">IP address</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="value">Number / IP</label>
                    <input type="text" id="value" name="value" placeholder="10-digit number or IP" required>
                </div>
                <div class="form-group">
                    <label for="reason">Reason (optional)</label>
                    <input type="text" id="reason" name="reason" placeholder="e.g. repeated fake orders">
                </div>
                <div class="form-group">
                    <label for="days">Duration</label>
                    <select name="days" id="days">
                        <option value="0">Permanent</option>
                        <option value="1">1 day</option>
                        <option value="7">7 days</option>
                        <option value="30">30 days</option>
                    </select>
                </div>
                <button type="submit" class="btn">Block</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Blocked list</h2>
        <?php if (empty($blocks)): ?>
            <div class="empty">No blocked customers. Numbers with 3 fake-order strikes are blocked automatically.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Value</th>
                        <th>Source</th>
                        <th>Strikes</th>
                        <th>Reason / history</th>
                        <th>Blocked</th>
                        <th>Expires</th>
                        <th>By</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($blocks as $b): ?>
                    <tr style="<?php echo blocklistActive($b) ? '' : 'opacity:.5'; ?>">
                        <td><span class="badge badge-<?php echo blocklistEscape($b['type']); ?>"><?php echo blocklistEscape(strtoupper($b['type'])); ?></span></td>
                        <td><strong><?php echo blocklistEscape($b['value']); ?></strong></td>
                        <td><span class="badge badge-<?php echo blocklistEscape($b['source']); ?>"><?php echo blocklistEscape(ucfirst($b['source'])); ?></span></td>
                        <td><?php echo (int)$b['strikes'] > 0 ? '<span class="strike-pill">' . (int)$b['strikes'] . '</span>' . ($b['last_strike_at'] ? ' <span class="muted">last: ' . blocklistEscape(date('d M', strtotime($b['last_strike_at']))) . '</span>' : '') : '—'; ?></td>
                        <td class="reason-cell"><?php echo $b['reason'] !== null && $b['reason'] !== '' ? blocklistEscape($b['reason']) : '<span class="muted">—</span>'; ?></td>
                        <td class="muted"><?php echo blocklistEscape(date('d M Y, h:i A', strtotime($b['blocked_at']))); ?></td>
                        <td><?php echo $b['expires_at'] ? '<span class="muted">' . blocklistEscape(date('d M Y, h:i A', strtotime($b['expires_at']))) . '</span>' : '<strong>Never</strong>'; ?></td>
                        <td class="muted"><?php echo blocklistEscape($b['blocked_by'] ?: 'System'); ?></td>
                        <td>
                            <form method="POST" action="blocklist.php" style="display:inline" onsubmit="return confirm('Remove this block?');">
                                <input type="hidden" name="action" value="unblock">
                                <input type="hidden" name="type" value="<?php echo blocklistEscape($b['type']); ?>">
                                <input type="hidden" name="value" value="<?php echo blocklistEscape($b['value']); ?>">
                                <button type="submit" class="unblock-btn">Unblock</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
