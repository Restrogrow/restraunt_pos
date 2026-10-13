<?php
/**
 * Superadmin platform API: restaurant 360, analytics, renewals, leads, security, announcements,
 * support tickets, platform health, invoices and bulk actions. JSON in / JSON out
 * (except action=invoice, which returns a printable HTML page, and exportInvoices, which returns CSV).
 */
ob_start();   // auth.php prints a stray blank line at its end; dropped below so the CSV / invoice start clean
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/auth.php';
require_superadmin_api();
require_once __DIR__ . '/../config/platform_engine.php';
if (ob_get_level()) ob_clean();
$conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
if (!$conn instanceof PDO) { http_response_code(500); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Database unavailable']); exit; }

$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if (!in_array($action, ['invoice', 'exportInvoices'], true)) header('Content-Type: application/json');

function out(array $a): void { echo json_encode(['success' => true] + $a, JSON_UNESCAPED_UNICODE); exit; }
function needPost(): void { global $isPost; if (!$isPost) throw new Exception('POST required'); }
function rest(PDO $conn, int $id): array
{
    $st = $conn->prepare("SELECT id, username, restaurant_id, restaurant_name FROM users WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception('Restaurant not found');
    return $r;
}
function tableExists(PDO $conn, string $t): bool
{
    static $c = [];
    if (!isset($c[$t])) $c[$t] = (bool)$conn->query("SHOW TABLES LIKE " . $conn->quote($t))->fetch();
    return $c[$t];
}
const LIVE_ORDER = "o.deleted_at IS NULL AND o.order_status NOT IN ('Cancelled','Rejected')";

try {
    pfEnsure($conn);
    $set = pfSettings($conn);
    $exp = pfExpirySql('u');

    switch ($action) {

    /* ───────── settings ───────── */
    case 'getSettings':
        out(['settings' => $set]);

    case 'saveSettings':
        needPost();
        $new = pfSaveSettings($conn, $in);
        pfAudit($conn, 'settings.save', null, array_intersect_key($in, $new));
        out(['settings' => $new, 'message' => 'Settings saved']);

    /* ───────── 1. restaurant 360 ───────── */
    case 'restaurant360':
        $id = (int)($_GET['id'] ?? 0);
        $st = $conn->prepare("SELECT u.id, u.username, u.restaurant_id, u.restaurant_name, u.owner_name, u.email, u.phone, u.address, u.country,
                u.is_active, u.subscription_status, u.trial_end_date, u.renewal_date, u.created_at, u.custom_domain,
                u.payment_gateway_type, u.show_install_app, u.whatsapp_orders, u.photo_gallery_enabled, u.meal_subscriptions_enabled,
                u.enable_delivery, u.enable_takeaway, u.enable_dinein, u.enable_reservations, u.cod_enabled,
                $exp AS expires_on, DATEDIFF($exp, CURDATE()) AS days_left
            FROM users u WHERE u.id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new Exception('Restaurant not found');
        $rid = $r['restaurant_id'];
        $q = function (string $sql, array $p = []) use ($conn) { $s = $conn->prepare($sql); $s->execute($p); return $s; };
        $sales = $q("SELECT
                COALESCE(SUM(CASE WHEN DATE(o.created_at) = CURDATE() THEN 1 END),0) AS today_orders,
                COALESCE(SUM(CASE WHEN DATE(o.created_at) = CURDATE() THEN o.total END),0) AS today_sales,
                COALESCE(SUM(CASE WHEN o.created_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN 1 END),0) AS month_orders,
                COALESCE(SUM(CASE WHEN o.created_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN o.total END),0) AS month_sales,
                COUNT(*) AS all_orders, COALESCE(SUM(o.total),0) AS all_sales,
                MAX(o.created_at) AS last_order_at
            FROM orders o WHERE o.restaurant_id = ? AND " . LIVE_ORDER, [$rid])->fetch(PDO::FETCH_ASSOC);
        $daily = $q("SELECT DATE(o.created_at) d, COUNT(*) n, COALESCE(SUM(o.total),0) s FROM orders o
            WHERE o.restaurant_id = ? AND " . LIVE_ORDER . " AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(o.created_at)", [$rid])->fetchAll(PDO::FETCH_ASSOC);
        $payments = $q("SELECT id, amount, subscription_type, duration_months, payment_status, payment_method, transaction_id, created_at
            FROM subscription_payments WHERE restaurant_id = ? ORDER BY id DESC LIMIT 10", [$rid])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($payments as &$p) $p['invoice_no'] = pfInvoiceNo($set, $p);
        unset($p);
        $staff = (int)$q("SELECT COUNT(*) FROM staff WHERE restaurant_id = ? AND is_active = 1", [$rid])->fetchColumn();
        $logins = tableExists($conn, 'login_logs') ? $q("SELECT username_tried, actor_name, user_type, outcome, ip_address, device_label, created_at FROM login_logs
            WHERE restaurant_id = ? ORDER BY id DESC LIMIT 8", [$rid])->fetchAll(PDO::FETCH_ASSOC) : [];
        $lastLogin = tableExists($conn, 'login_logs') ? $q("SELECT MAX(created_at) FROM login_logs WHERE restaurant_id = ? AND outcome = 'success'", [$rid])->fetchColumn() : null;
        $errors = $q("SELECT id, severity, source, message, created_at FROM error_logs WHERE restaurant_id = ? ORDER BY id DESC LIMIT 5", [$rid])->fetchAll(PDO::FETCH_ASSOC);
        $errors7 = (int)$q("SELECT COUNT(*) FROM error_logs WHERE restaurant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", [$rid])->fetchColumn();
        $menu = (int)$q("SELECT COUNT(*) FROM menu_items WHERE restaurant_id = ?", [$rid])->fetchColumn();
        $customers = tableExists($conn, 'customers') ? (int)$q("SELECT COUNT(*) FROM customers WHERE restaurant_id = ?", [$rid])->fetchColumn() : 0;
        $wa = tableExists($conn, 'wa_settings') ? $q("SELECT enabled, account_name, rg_phone, connected_at, lost_at FROM wa_settings WHERE restaurant_id = ?", [$rid])->fetch(PDO::FETCH_ASSOC) : null;
        $tickets = $q("SELECT id, subject, status, updated_at FROM support_tickets WHERE restaurant_id = ? ORDER BY updated_at DESC LIMIT 5", [$rid])->fetchAll(PDO::FETCH_ASSOC);
        $push = (int)$q("SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?", [$r['id']])->fetchColumn();
        out(['restaurant' => $r, 'sales' => $sales, 'daily' => $daily, 'payments' => $payments, 'staff' => $staff, 'logins' => $logins,
            'last_login' => $lastLogin, 'errors' => $errors, 'errors_7d' => $errors7, 'menu_items' => $menu, 'customers' => $customers,
            'whatsapp' => $wa ?: null, 'tickets' => $tickets, 'push_devices' => $push]);

    /* ───────── 2. analytics ───────── */
    case 'analytics':
        $price = (float)$set['plan_price'];
        $counts = $conn->query("SELECT
                COUNT(*) total,
                SUM(is_active = 1) active,
                SUM(subscription_status = 'active' AND is_active = 1) paying,
                SUM(subscription_status = 'trial' AND is_active = 1 AND " . pfExpirySql('users') . " >= CURDATE()) on_trial,
                SUM(is_active = 0 OR subscription_status IN ('disabled','expired') OR " . pfExpirySql('users') . " < CURDATE()) lapsed
            FROM users")->fetch(PDO::FETCH_ASSOC);
        // MRR from what was actually paid in the last 12 months, spread per month of cover; plan price as fallback
        $paid = $conn->query("SELECT restaurant_id, amount, GREATEST(COALESCE(duration_months,1),1) m FROM subscription_payments
            WHERE payment_status = 'success' AND amount > 0 AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)")->fetchAll(PDO::FETCH_ASSOC);
        $perRest = [];
        foreach ($paid as $p) $perRest[$p['restaurant_id']] = max($perRest[$p['restaurant_id']] ?? 0, (float)$p['amount'] / (int)$p['m']);
        $payingIds = $conn->query("SELECT restaurant_id FROM users WHERE subscription_status = 'active' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        $mrr = 0;
        foreach ($payingIds as $rid) $mrr += $perRest[$rid] ?? $price;
        $months = [];
        for ($i = 11; $i >= 0; $i--) $months[date('Y-m', strtotime("first day of -$i month"))] = ['month' => date('M y', strtotime("first day of -$i month")), 'new' => 0, 'revenue' => 0, 'orders' => 0, 'sales' => 0];
        foreach ($conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) n FROM users WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 11 MONTH) GROUP BY ym") as $x)
            if (isset($months[$x['ym']])) $months[$x['ym']]['new'] = (int)$x['n'];
        foreach ($conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, SUM(amount) s FROM subscription_payments WHERE payment_status = 'success' AND created_at >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 11 MONTH) GROUP BY ym") as $x)
            if (isset($months[$x['ym']])) $months[$x['ym']]['revenue'] = (float)$x['s'];
        foreach ($conn->query("SELECT DATE_FORMAT(o.created_at,'%Y-%m') ym, COUNT(*) n, SUM(o.total) s FROM orders o WHERE " . LIVE_ORDER . " AND o.created_at >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 11 MONTH) GROUP BY ym") as $x)
            if (isset($months[$x['ym']])) { $months[$x['ym']]['orders'] = (int)$x['n']; $months[$x['ym']]['sales'] = (float)$x['s']; }
        // trials that became paying: restaurants with at least one real (amount > 0) successful payment
        $everPaid = (int)$conn->query("SELECT COUNT(DISTINCT restaurant_id) FROM subscription_payments WHERE payment_status = 'success' AND amount > 0")->fetchColumn();
        $churn30 = (int)$conn->query("SELECT COUNT(*) FROM users u WHERE (u.is_active = 0 AND u.disabled_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))
            OR ($exp BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND u.subscription_status <> 'active')")->fetchColumn();
        // per restaurant: this 30 days vs the 30 before, to find growing and slipping ones
        $rows = $conn->query("SELECT u.id, u.restaurant_id, u.restaurant_name, u.is_active,
                SUM(o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) cur_orders,
                COALESCE(SUM(CASE WHEN o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN o.total END),0) cur_sales,
                SUM(o.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND o.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)) prev_orders,
                MAX(o.created_at) last_order
            FROM users u LEFT JOIN orders o ON o.restaurant_id = u.restaurant_id AND " . LIVE_ORDER . " AND o.created_at >= DATE_SUB(NOW(), INTERVAL 400 DAY)
            GROUP BY u.id ORDER BY cur_sales DESC")->fetchAll(PDO::FETCH_ASSOC);
        $top = array_slice($rows, 0, 8);
        $atRisk = array_values(array_filter($rows, function ($r) {
            if ((int)$r['is_active'] !== 1) return false;
            $quiet = !$r['last_order'] || strtotime($r['last_order']) < strtotime('-7 days');
            $falling = (int)$r['prev_orders'] >= 5 && (int)$r['cur_orders'] < (int)$r['prev_orders'] * 0.5;
            return $quiet || $falling;
        }));
        $t = $conn->query("SELECT COUNT(*) n, COALESCE(SUM(o.total),0) s FROM orders o WHERE " . LIVE_ORDER . " AND DATE(o.created_at) = CURDATE()")->fetch(PDO::FETCH_ASSOC);
        $m = $conn->query("SELECT COUNT(*) n, COALESCE(SUM(o.total),0) s FROM orders o WHERE " . LIVE_ORDER . " AND o.created_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetch(PDO::FETCH_ASSOC);
        out(['counts' => $counts, 'mrr' => round($mrr, 2), 'arr' => round($mrr * 12, 2), 'plan_price' => $price,
            'ever_paid' => $everPaid, 'conversion' => (int)$counts['total'] ? round($everPaid * 100 / (int)$counts['total'], 1) : 0,
            'churn_30d' => $churn30, 'months' => array_values($months), 'top' => $top, 'at_risk' => array_slice($atRisk, 0, 20),
            'today' => $t, 'this_month' => $m]);

    /* ───────── 3. renewals ───────── */
    case 'renewals':
        $days = max(1, min(90, (int)($_GET['days'] ?? $set['renewal_notice_days'])));
        $st = $conn->prepare("SELECT u.id, u.restaurant_id, u.restaurant_name, u.username, u.owner_name, u.phone, u.email, u.is_active,
                u.subscription_status, $exp AS expires_on, DATEDIFF($exp, CURDATE()) AS days_left,
                (SELECT MAX(created_at) FROM subscription_payments sp WHERE sp.restaurant_id = u.restaurant_id AND sp.payment_status = 'success' AND sp.amount > 0) AS last_paid,
                (SELECT COUNT(*) FROM payment_links pl WHERE pl.restaurant_id = u.restaurant_id AND pl.payment_status = 'Pending') AS open_links
            FROM users u
            WHERE u.subscription_status <> 'disabled' AND $exp <= DATE_ADD(CURDATE(), INTERVAL ? DAY) AND $exp >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY $exp ASC");
        $st->execute([$days]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        out(['days' => $days, 'plan_price' => (float)$set['plan_price'], 'plan_price_yearly' => (float)$set['plan_price_yearly'], 'rows' => $rows]);

    case 'logReminder':
        needPost();
        $r = rest($conn, (int)($in['id'] ?? 0));
        pfAudit($conn, 'renewal.remind', $r['restaurant_name'] . ' (' . $r['restaurant_id'] . ')', ['via' => 'whatsapp_link']);
        out([]);

    case 'extendRenewal':
        needPost();
        $r = rest($conn, (int)($in['id'] ?? 0));
        $d = (int)($in['days'] ?? 0);
        if ($d < 1 || $d > 365) throw new Exception('Days must be 1 to 365');
        $conn->prepare("UPDATE users u SET u.renewal_date = DATE_ADD(GREATEST($exp, CURDATE()), INTERVAL ? DAY), u.is_active = 1, u.disabled_at = NULL,
            u.subscription_status = IF(u.subscription_status IN ('expired','disabled'), 'trial', u.subscription_status), u.subscription_updated_at = NOW() WHERE u.id = ?")->execute([$d, $r['id']]);
        pfAudit($conn, 'renewal.extend', $r['restaurant_name'] . ' (' . $r['restaurant_id'] . ')', ['days' => $d]);
        out(['message' => "Extended by $d days"]);

    /* ───────── 5. leads ───────── */
    case 'leads':
        $stage = $_GET['stage'] ?? '';
        $sql = "SELECT id, name, email, phone, message, status, stage, lead_notes, follow_up_on, restaurant_name_hint, converted_restaurant_id, created_at, updated_at FROM contact_queries";
        $p = [];
        if ($stage !== '' && $stage !== 'all') { $sql .= " WHERE stage = ?"; $p[] = $stage; }
        $st = $conn->prepare($sql . " ORDER BY (stage = 'new') DESC, COALESCE(follow_up_on, '9999-12-31') ASC, id DESC LIMIT 300");
        $st->execute($p);
        $counts = $conn->query("SELECT stage, COUNT(*) n FROM contact_queries GROUP BY stage")->fetchAll(PDO::FETCH_KEY_PAIR);
        out(['rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'counts' => $counts]);

    case 'saveLead':
        needPost();
        $stages = ['new', 'contacted', 'demo', 'won', 'lost'];
        $id = (int)($in['id'] ?? 0);
        $stage = in_array($in['stage'] ?? '', $stages, true) ? $in['stage'] : 'new';
        $fu = !empty($in['follow_up_on']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['follow_up_on']) ? $in['follow_up_on'] : null;
        $status = ['new' => 'new', 'contacted' => 'replied', 'demo' => 'replied', 'won' => 'closed', 'lost' => 'closed'][$stage];
        if ($id > 0) {
            $conn->prepare("UPDATE contact_queries SET stage = ?, status = ?, lead_notes = ?, follow_up_on = ?, restaurant_name_hint = ?, converted_restaurant_id = COALESCE(?, converted_restaurant_id) WHERE id = ?")
                ->execute([$stage, $status, mb_substr(trim($in['lead_notes'] ?? ''), 0, 4000), $fu, mb_substr(trim($in['restaurant_name_hint'] ?? ''), 0, 160), ($in['converted_restaurant_id'] ?? '') ?: null, $id]);
        } else {
            $name = trim($in['name'] ?? '');
            if ($name === '') throw new Exception('Name is required');
            $conn->prepare("INSERT INTO contact_queries (name, email, phone, message, status, stage, lead_notes, follow_up_on, restaurant_name_hint) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([mb_substr($name, 0, 255), trim($in['email'] ?? ''), trim($in['phone'] ?? ''), trim($in['message'] ?? 'Added by superadmin'), $status, $stage,
                    trim($in['lead_notes'] ?? ''), $fu, trim($in['restaurant_name_hint'] ?? '')]);
            $id = (int)$conn->lastInsertId();
        }
        pfAudit($conn, 'lead.save', 'Lead #' . $id, ['stage' => $stage]);
        out(['id' => $id, 'message' => 'Lead saved']);

    case 'deleteLead':
        needPost();
        $conn->prepare("DELETE FROM contact_queries WHERE id = ?")->execute([(int)($in['id'] ?? 0)]);
        pfAudit($conn, 'lead.delete', 'Lead #' . (int)($in['id'] ?? 0));
        out([]);

    /* ───────── 7. security + activity ───────── */
    case 'security':
        $logins = [];
        $summary = ['failed_24h' => 0, 'locked_24h' => 0, 'success_24h' => 0, 'new_devices_7d' => 0];
        if (tableExists($conn, 'login_logs')) {
            $w = []; $p = [];
            if (($_GET['outcome'] ?? '') !== '') { $w[] = 'l.outcome = ?'; $p[] = $_GET['outcome']; }
            if (($_GET['q'] ?? '') !== '') { $w[] = '(l.username_tried LIKE ? OR l.ip_address LIKE ? OR u.restaurant_name LIKE ?)'; $like = '%' . $_GET['q'] . '%'; array_push($p, $like, $like, $like); }
            $st = $conn->prepare("SELECT l.*, u.restaurant_name FROM login_logs l LEFT JOIN users u ON u.restaurant_id = l.restaurant_id"
                . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY l.id DESC LIMIT 200");
            $st->execute($p);
            $logins = $st->fetchAll(PDO::FETCH_ASSOC);
            $summary = $conn->query("SELECT SUM(outcome = 'failed' AND created_at >= NOW() - INTERVAL 1 DAY) failed_24h,
                SUM(outcome IN ('locked_out','blocked_ip') AND created_at >= NOW() - INTERVAL 1 DAY) locked_24h,
                SUM(outcome = 'success' AND created_at >= NOW() - INTERVAL 1 DAY) success_24h,
                SUM(is_new_device = 1 AND outcome = 'success' AND created_at >= NOW() - INTERVAL 7 DAY) new_devices_7d FROM login_logs")->fetch(PDO::FETCH_ASSOC);
            $suspicious = $conn->query("SELECT ip_address, COUNT(*) n, MAX(created_at) last_at, GROUP_CONCAT(DISTINCT username_tried SEPARATOR ', ') tried
                FROM login_logs WHERE outcome = 'failed' AND created_at >= NOW() - INTERVAL 1 DAY GROUP BY ip_address HAVING n >= 5 ORDER BY n DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        }
        $blocked = tableExists($conn, 'blocked_ips') ? $conn->query("SELECT b.*, u.restaurant_name FROM blocked_ips b LEFT JOIN users u ON u.restaurant_id = b.restaurant_id ORDER BY b.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) : [];
        out(['logins' => $logins, 'summary' => $summary, 'suspicious' => $suspicious ?? [], 'blocked' => $blocked]);

    case 'unblockIp':
        needPost();
        $st = $conn->prepare("SELECT ip_address, restaurant_id FROM blocked_ips WHERE id = ?");
        $st->execute([(int)($in['id'] ?? 0)]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if (!$b) throw new Exception('Not found');
        $conn->prepare("DELETE FROM blocked_ips WHERE id = ?")->execute([(int)$in['id']]);
        pfAudit($conn, 'security.unblock_ip', $b['ip_address'], ['restaurant_id' => $b['restaurant_id']]);
        out(['message' => 'Unblocked ' . $b['ip_address']]);

    case 'auditLog':
        $w = []; $p = [];
        if (($_GET['q'] ?? '') !== '') { $w[] = '(action LIKE ? OR target LIKE ? OR admin_name LIKE ? OR details LIKE ?)'; $like = '%' . $_GET['q'] . '%'; array_push($p, $like, $like, $like, $like); }
        $st = $conn->prepare("SELECT * FROM sa_audit_log" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY id DESC LIMIT 300");
        $st->execute($p);
        out(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);

    /* ───────── 9. announcements ───────── */
    case 'announcements':
        $rows = $conn->query("SELECT a.*, (SELECT COUNT(*) FROM sa_announcement_dismiss d WHERE d.announcement_id = a.id) dismissed
            FROM sa_announcements a ORDER BY a.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        out(['rows' => $rows]);

    case 'saveAnnouncement':
        needPost();
        $title = trim($in['title'] ?? '');
        if ($title === '') throw new Exception('Title is required');
        $level = in_array($in['level'] ?? '', ['info', 'success', 'warning', 'danger'], true) ? $in['level'] : 'info';
        $ids = array_values(array_filter(array_map(function ($x) { return preg_replace('/[^A-Za-z0-9_-]/', '', (string)$x); }, (array)($in['target_ids'] ?? []))));
        $all = !empty($in['target_all']) || !$ids ? 1 : 0;
        $dt = function ($v) { return $v && strtotime($v) ? date('Y-m-d H:i:s', strtotime($v)) : null; };
        $vals = [mb_substr($title, 0, 160), mb_substr(trim($in['body'] ?? ''), 0, 2000), $level, $all, $all ? null : implode(',', $ids),
            $dt($in['starts_at'] ?? null), $dt($in['ends_at'] ?? null), empty($in['dismissible']) ? 0 : 1, isset($in['is_active']) ? ((int)!empty($in['is_active'])) : 1];
        $id = (int)($in['id'] ?? 0);
        if ($id > 0) {
            $conn->prepare("UPDATE sa_announcements SET title=?, body=?, level=?, target_all=?, target_ids=?, starts_at=?, ends_at=?, dismissible=?, is_active=? WHERE id=?")->execute(array_merge($vals, [$id]));
        } else {
            $conn->prepare("INSERT INTO sa_announcements (title, body, level, target_all, target_ids, starts_at, ends_at, dismissible, is_active, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute(array_merge($vals, [$_SESSION['superadmin_username'] ?? null]));
            $id = (int)$conn->lastInsertId();
        }
        pfAudit($conn, 'announcement.save', $title, ['id' => $id, 'to' => $all ? 'all' : implode(',', $ids)]);
        out(['id' => $id, 'message' => 'Announcement saved']);

    case 'toggleAnnouncement':
        needPost();
        $conn->prepare("UPDATE sa_announcements SET is_active = ? WHERE id = ?")->execute([empty($in['is_active']) ? 0 : 1, (int)($in['id'] ?? 0)]);
        pfAudit($conn, 'announcement.' . (empty($in['is_active']) ? 'stop' : 'start'), 'Announcement #' . (int)($in['id'] ?? 0));
        out([]);

    case 'deleteAnnouncement':
        needPost();
        $id = (int)($in['id'] ?? 0);
        $conn->prepare("DELETE FROM sa_announcement_dismiss WHERE announcement_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM sa_announcements WHERE id = ?")->execute([$id]);
        pfAudit($conn, 'announcement.delete', 'Announcement #' . $id);
        out([]);

    /* ───────── 10. support tickets ───────── */
    case 'tickets':
        $status = $_GET['status'] ?? 'active';
        $w = $status === 'active' ? "t.status IN ('open','waiting')" : ($status === 'all' ? '1=1' : 't.status = ' . $conn->quote($status));
        $rows = $conn->query("SELECT t.*, u.id user_id, u.restaurant_name, u.phone,
                (SELECT body FROM support_messages m WHERE m.ticket_id = t.id ORDER BY m.id DESC LIMIT 1) last_message,
                (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id) messages
            FROM support_tickets t LEFT JOIN users u ON u.restaurant_id = t.restaurant_id WHERE $w ORDER BY t.unread_admin DESC, t.updated_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        $counts = $conn->query("SELECT status, COUNT(*) n FROM support_tickets GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $unread = (int)$conn->query("SELECT COUNT(*) FROM support_tickets WHERE unread_admin = 1 AND status <> 'closed'")->fetchColumn();
        out(['rows' => $rows, 'counts' => $counts, 'unread' => $unread]);

    case 'ticket':
        $id = (int)($_GET['id'] ?? 0);
        $st = $conn->prepare("SELECT t.*, u.id user_id, u.restaurant_name, u.phone, u.email FROM support_tickets t LEFT JOIN users u ON u.restaurant_id = t.restaurant_id WHERE t.id = ?");
        $st->execute([$id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new Exception('Ticket not found');
        $conn->prepare("UPDATE support_tickets SET unread_admin = 0 WHERE id = ?")->execute([$id]);
        $m = $conn->prepare("SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY id");
        $m->execute([$id]);
        $recent = $conn->prepare("SELECT o.order_number, o.order_status, o.total, o.created_at FROM orders o WHERE o.restaurant_id = ? ORDER BY o.id DESC LIMIT 5");
        $recent->execute([$t['restaurant_id']]);
        $errs = $conn->prepare("SELECT severity, message, created_at FROM error_logs WHERE restaurant_id = ? ORDER BY id DESC LIMIT 5");
        $errs->execute([$t['restaurant_id']]);
        out(['ticket' => $t, 'messages' => $m->fetchAll(PDO::FETCH_ASSOC), 'recent_orders' => $recent->fetchAll(PDO::FETCH_ASSOC), 'recent_errors' => $errs->fetchAll(PDO::FETCH_ASSOC)]);

    case 'replyTicket':
        needPost();
        $id = (int)($in['id'] ?? 0);
        $body = trim($in['body'] ?? '');
        $status = in_array($in['status'] ?? '', ['open', 'waiting', 'answered', 'closed'], true) ? $in['status'] : 'answered';
        $st = $conn->prepare("SELECT id, subject, restaurant_id FROM support_tickets WHERE id = ?");
        $st->execute([$id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new Exception('Ticket not found');
        if ($body !== '') {
            $conn->prepare("INSERT INTO support_messages (ticket_id, sender, sender_name, body) VALUES (?, 'superadmin', 'RestroGrow support', ?)")->execute([$id, mb_substr($body, 0, 5000)]);
        }
        $conn->prepare("UPDATE support_tickets SET status = ?, unread_restaurant = IF(? <> '', 1, unread_restaurant), unread_admin = 0, updated_at = NOW() WHERE id = ?")->execute([$status, $body, $id]);
        pfAudit($conn, $body !== '' ? 'ticket.reply' : 'ticket.status', 'Ticket #' . $id . ' ' . $t['subject'], ['status' => $status]);
        out(['message' => $body !== '' ? 'Reply sent' : 'Status updated']);

    /* ───────── 11. platform health ───────── */
    case 'health':
        $db = $conn->query("SELECT table_name t, ROUND((data_length + index_length) / 1048576, 2) mb, table_rows r
            FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length + index_length) DESC")->fetchAll(PDO::FETCH_ASSOC);
        $dbTotal = array_sum(array_map(function ($x) { return (float)$x['mb']; }, $db));
        $dirSize = function (string $dir, int $limit = 60000) {
            $size = 0; $n = 0;
            if (!is_dir($dir)) return [0, 0, false];
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) { if ($f->isFile()) { $size += $f->getSize(); $n++; } if ($n >= $limit) return [$size, $n, true]; }
            return [$size, $n, false];
        };
        [$up, $upN, $upMore] = $dirSize(__DIR__ . '/../uploads');
        $free = @disk_free_space(__DIR__); $total = @disk_total_space(__DIR__);
        $wa = tableExists($conn, 'wa_outbox') ? $conn->query("SELECT status, COUNT(*) n FROM wa_outbox WHERE created_at >= NOW() - INTERVAL 7 DAY GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        $waFailed = tableExists($conn, 'wa_outbox') ? $conn->query("SELECT w.id, w.restaurant_id, u.restaurant_name, w.kind, w.template_name, w.error, w.attempts, w.created_at FROM wa_outbox w
            LEFT JOIN users u ON u.restaurant_id = w.restaurant_id WHERE w.status IN ('failed','error') ORDER BY w.id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC) : [];
        $waStuck = tableExists($conn, 'wa_outbox') ? (int)$conn->query("SELECT COUNT(*) FROM wa_outbox WHERE status IN ('queued','pending') AND COALESCE(send_after, created_at) < NOW() - INTERVAL 30 MINUTE")->fetchColumn() : 0;
        $waLastSent = tableExists($conn, 'wa_outbox') ? $conn->query("SELECT MAX(sent_at) FROM wa_outbox")->fetchColumn() : null;
        $push = (int)$conn->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();
        $pushRest = (int)$conn->query("SELECT COUNT(DISTINCT user_id) FROM push_subscriptions")->fetchColumn();
        $err = $conn->query("SELECT SUM(created_at >= NOW() - INTERVAL 1 DAY) d1, SUM(created_at >= NOW() - INTERVAL 1 DAY AND severity IN ('critical','error')) d1bad,
            SUM(created_at >= NOW() - INTERVAL 7 DAY) d7, SUM(is_read = 0) unread FROM error_logs")->fetch(PDO::FETCH_ASSOC);
        $lastOrder = $conn->query("SELECT MAX(created_at) FROM orders")->fetchColumn();
        $backups = [];
        foreach ([__DIR__ . '/../backups', __DIR__ . '/../../backups', __DIR__ . '/../database/backups'] as $bd) {
            if (is_dir($bd)) foreach (glob($bd . '/*') ?: [] as $f) if (is_file($f)) $backups[] = ['file' => basename($f), 'mb' => round(filesize($f) / 1048576, 2), 'at' => date('Y-m-d H:i', filemtime($f))];
        }
        usort($backups, function ($a, $b) { return strcmp($b['at'], $a['at']); });
        $checks = [];
        $checks[] = ['label' => 'Database', 'ok' => true, 'note' => round($dbTotal, 1) . ' MB in ' . count($db) . ' tables'];
        $checks[] = ['label' => 'Disk space', 'ok' => !$total || $free / $total > 0.1, 'note' => $total ? round($free / 1073741824, 1) . ' GB free of ' . round($total / 1073741824, 1) . ' GB' : 'Not available on this host'];
        $checks[] = ['label' => 'Errors (24 h)', 'ok' => (int)$err['d1bad'] === 0, 'note' => (int)$err['d1'] . ' logged, ' . (int)$err['d1bad'] . ' critical / error'];
        $checks[] = ['label' => 'WhatsApp queue', 'ok' => $waStuck === 0 && !$waFailed, 'note' => $waStuck ? "$waStuck waiting over 30 min" : ($waFailed ? count($waFailed) . ' recent failures' : 'Flowing')];
        $checks[] = ['label' => 'Backups', 'ok' => $backups && strtotime($backups[0]['at']) > strtotime('-2 days'), 'note' => $backups ? 'Last: ' . $backups[0]['at'] : 'No backup files found on this server (use your host’s backups)'];
        $checks[] = ['label' => 'PHP', 'ok' => version_compare(PHP_VERSION, '8.0', '>='), 'note' => PHP_VERSION . ', memory ' . ini_get('memory_limit') . ', upload ' . ini_get('upload_max_filesize')];
        out(['checks' => $checks, 'db_tables' => array_slice($db, 0, 12), 'db_mb' => round($dbTotal, 2),
            'uploads' => ['mb' => round($up / 1048576, 1), 'files' => $upN, 'more' => $upMore], 'disk' => ['free_gb' => $free ? round($free / 1073741824, 1) : null, 'total_gb' => $total ? round($total / 1073741824, 1) : null],
            'whatsapp' => ['by_status_7d' => $wa, 'failed' => $waFailed, 'stuck' => $waStuck, 'last_sent' => $waLastSent],
            'push' => ['devices' => $push, 'restaurants' => $pushRest], 'errors' => $err, 'last_order' => $lastOrder, 'backups' => array_slice($backups, 0, 5),
            'php' => PHP_VERSION, 'server_time' => date('Y-m-d H:i:s')]);

    /* ───────── 12. invoices ───────── */
    case 'invoices':
        $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
        $st = $conn->prepare("SELECT sp.*, u.restaurant_name, u.gstin_no FROM subscription_payments sp LEFT JOIN users u ON u.restaurant_id = sp.restaurant_id
            WHERE sp.payment_status = 'success' AND DATE_FORMAT(sp.created_at, '%Y-%m') = ? ORDER BY sp.id DESC");
        $st->execute([$month]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $sum = ['base' => 0, 'tax' => 0, 'total' => 0];
        foreach ($rows as &$r) {
            $r['invoice_no'] = pfInvoiceNo($set, $r);
            $g = pfGst($set, (float)$r['amount']);
            $r['gst'] = $g;
            foreach ($sum as $k => $_) $sum[$k] += $g[$k];
        }
        unset($r);
        out(['month' => $month, 'rows' => $rows, 'sum' => $sum, 'gst_ready' => trim($set['biz_gstin']) !== '']);

    case 'exportInvoices':
        $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
        $st = $conn->prepare("SELECT sp.*, u.restaurant_name, u.gstin_no FROM subscription_payments sp LEFT JOIN users u ON u.restaurant_id = sp.restaurant_id
            WHERE sp.payment_status = 'success' AND DATE_FORMAT(sp.created_at, '%Y-%m') = ? ORDER BY sp.id");
        $st->execute([$month]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="restrogrow-invoices-' . $month . '.csv"');
        $f = fopen('php://output', 'w');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, ['Invoice no', 'Date', 'Restaurant', 'Restaurant ID', 'Customer GSTIN', 'Plan', 'Months', 'Method', 'Transaction', 'Taxable value', 'GST %', 'CGST', 'SGST', 'Total']);
        foreach ($st as $r) {
            $g = pfGst($set, (float)$r['amount']);
            fputcsv($f, [pfInvoiceNo($set, $r), substr($r['created_at'], 0, 10), $r['restaurant_name'], $r['restaurant_id'], $r['gstin_no'], $r['subscription_type'], $r['duration_months'],
                $r['payment_method'], $r['transaction_id'], $g['base'], $g['rate'], $g['cgst'], $g['sgst'], $g['total']]);
        }
        pfAudit($conn, 'invoices.export', $month);
        exit;

    case 'invoice':
        $id = (int)($_GET['id'] ?? 0);
        $st = $conn->prepare("SELECT sp.*, u.restaurant_name, u.owner_name, u.address, u.email, u.phone, u.gstin_no FROM subscription_payments sp
            LEFT JOIN users u ON u.restaurant_id = sp.restaurant_id WHERE sp.id = ?");
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        header('Content-Type: text/html; charset=utf-8');
        if (!$p) { http_response_code(404); echo 'Invoice not found'; exit; }
        $g = pfGst($set, (float)$p['amount']);
        $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $money = function ($n) { return '₹' . number_format((float)$n, 2); };
        $no = pfInvoiceNo($set, $p);
        $plan = ucfirst((string)$p['subscription_type']) . ' plan' . ((int)$p['duration_months'] ? ' · ' . (int)$p['duration_months'] . ' month' . ((int)$p['duration_months'] > 1 ? 's' : '') : '');
        $paid = in_array($p['payment_status'], ['success'], true);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Invoice ' . $h($no) . '</title><style>
            *{box-sizing:border-box}body{margin:0;background:#f3f5fa;font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif;color:#111827}
            .bar{max-width:800px;margin:16px auto 0;padding:0 16px;display:flex;justify-content:flex-end;gap:8px}
            .bar button{height:40px;padding:0 16px;border:0;border-radius:12px;background:#2563eb;color:#fff;font:inherit;font-weight:700;cursor:pointer}
            .inv{max-width:800px;margin:12px auto 24px;padding:36px;background:#fff;border:1px solid #e5e7eb;border-radius:18px}
            .top{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap}.top h1{margin:0 0 4px;font-size:1.6rem}.muted{color:#6b7280;font-size:.88rem;line-height:1.55}
            .tag{display:inline-block;margin-top:8px;padding:3px 10px;border-radius:999px;background:' . ($paid ? '#ecfdf5;color:#047857' : '#fffbeb;color:#b45309') . ';font-size:.78rem;font-weight:800}
            .grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:28px 0}.grid h3{margin:0 0 6px;font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;color:#9ca3af}
            table{width:100%;border-collapse:collapse;font-size:.92rem}th{text-align:left;font-size:.72rem;letter-spacing:.04em;text-transform:uppercase;color:#6b7280;background:#f9fafb}
            th,td{padding:10px 12px;border-bottom:1px solid #eef0f3}td.r,th.r{text-align:right}.tot td{border:0;padding:6px 12px}.tot .big td{font-size:1.1rem;font-weight:800;border-top:2px solid #111827;padding-top:10px}
            .foot{margin-top:28px;font-size:.8rem;color:#9ca3af}@media print{body{background:#fff}.bar{display:none}.inv{border:0;margin:0;padding:0}}
            @media(max-width:600px){.inv{padding:20px}.grid{grid-template-columns:1fr}}</style></head><body>
            <div class="bar"><button onclick="window.print()">Print / Save as PDF</button></div><div class="inv">
            <div class="top"><div><h1>' . $h($set['biz_name'] ?: 'RestroGrow') . '</h1><div class="muted">' . nl2br($h($set['biz_address'])) .
            ($set['biz_gstin'] ? '<br>GSTIN: ' . $h($set['biz_gstin']) : '') . ($set['biz_email'] ? '<br>' . $h($set['biz_email']) : '') . ($set['biz_phone'] ? ' · ' . $h($set['biz_phone']) : '') . '</div></div>
            <div style="text-align:right"><h1 style="font-size:1.2rem">' . ($set['biz_gstin'] ? 'Tax invoice' : 'Invoice') . '</h1><div class="muted">No. <b>' . $h($no) . '</b><br>Date ' . $h(date('d M Y', strtotime($p['created_at']))) . '</div><span class="tag">' . ($paid ? 'Paid' : $h(ucfirst($p['payment_status']))) . '</span></div></div>
            <div class="grid"><div><h3>Billed to</h3><b>' . $h($p['restaurant_name']) . '</b><div class="muted">' . ($p['owner_name'] ? $h($p['owner_name']) . '<br>' : '') . nl2br($h($p['address'])) .
            ($p['gstin_no'] ? '<br>GSTIN: ' . $h($p['gstin_no']) : '') . ($p['email'] ? '<br>' . $h($p['email']) : '') . ($p['phone'] ? '<br>' . $h($p['phone']) : '') . '<br>Restaurant ID: ' . $h($p['restaurant_id']) . '</div></div>
            <div><h3>Payment</h3><div class="muted">Method: ' . $h($p['payment_method'] ?: '-') . '<br>Transaction: ' . $h($p['transaction_id']) . '</div></div></div>
            <table><thead><tr><th>Description</th><th>SAC</th><th class="r">Amount</th></tr></thead><tbody>
            <tr><td>RestroGrow restaurant software subscription<div class="muted">' . $h($plan) . ($p['notes'] ? ' · ' . $h($p['notes']) : '') . '</div></td><td>998314</td><td class="r">' . $money($g['base']) . '</td></tr></tbody></table>
            <table class="tot" style="max-width:320px;margin-left:auto;margin-top:10px"><tr><td>Taxable value</td><td class="r">' . $money($g['base']) . '</td></tr>' .
            ($g['rate'] > 0 ? '<tr><td>CGST ' . ($g['rate'] / 2) . '%</td><td class="r">' . $money($g['cgst']) . '</td></tr><tr><td>SGST ' . ($g['rate'] / 2) . '%</td><td class="r">' . $money($g['sgst']) . '</td></tr>' : '') .
            '<tr class="big"><td>Total</td><td class="r">' . $money($g['total']) . '</td></tr></table>
            <div class="foot">' . ($set['biz_gstin'] ? 'CGST + SGST shown for supply within the state; for other states read as IGST ' . $h($g['rate']) . '%.' : 'Add your GSTIN in Superadmin › Settings to issue tax invoices.') . ' This is a computer-generated invoice.</div></div></body></html>';
        exit;

    /* ───────── 13. bulk actions ───────── */
    case 'bulk':
        needPost();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['ids'] ?? [])))));
        if (!$ids) throw new Exception('Pick at least one restaurant');
        if (count($ids) > 500) throw new Exception('Too many at once');
        $do = $in['do'] ?? '';
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $features = ['show_install_app' => 'Install app', 'whatsapp_orders' => 'WhatsApp orders', 'photo_gallery_enabled' => 'Gallery', 'meal_subscriptions_enabled' => 'Meal plans'];
        if ($do === 'feature') {
            $f = $in['feature'] ?? '';
            if (!isset($features[$f])) throw new Exception('Unknown feature');
            $on = empty($in['on']) ? 0 : 1;
            $st = $conn->prepare("UPDATE users SET `$f` = ? WHERE id IN ($ph)");
            $st->execute(array_merge([$on], $ids));
            $msg = $features[$f] . ' turned ' . ($on ? 'on' : 'off');
        } elseif ($do === 'extend') {
            $d = (int)($in['days'] ?? 0);
            if ($d < 1 || $d > 365) throw new Exception('Days must be 1 to 365');
            $st = $conn->prepare("UPDATE users u SET u.renewal_date = DATE_ADD(GREATEST($exp, CURDATE()), INTERVAL ? DAY), u.subscription_updated_at = NOW() WHERE u.id IN ($ph)");
            $st->execute(array_merge([$d], $ids));
            $msg = "Extended by $d days";
        } elseif ($do === 'disable') {
            $st = $conn->prepare("UPDATE users SET is_active = 0, subscription_status = 'disabled', disabled_at = NOW() WHERE id IN ($ph)");
            $st->execute($ids);
            $msg = 'Disabled';
        } elseif ($do === 'enable') {
            // same as the single Enable: back on, 30 days from today
            $st = $conn->prepare("UPDATE users SET is_active = 1, subscription_status = 'trial', renewal_date = DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY), disabled_at = NULL WHERE id IN ($ph) AND is_active = 0");
            $st->execute($ids);
            $msg = 'Enabled';
        } else {
            throw new Exception('Unknown action');
        }
        $names = $conn->prepare("SELECT restaurant_id FROM users WHERE id IN ($ph)");
        $names->execute($ids);
        pfAudit($conn, 'bulk.' . $do, count($ids) . ' restaurants', ['ids' => implode(',', $names->fetchAll(PDO::FETCH_COLUMN)), 'feature' => $in['feature'] ?? null, 'on' => $in['on'] ?? null, 'days' => $in['days'] ?? null]);
        out(['message' => $msg . ' for ' . $st->rowCount() . ' of ' . count($ids) . ' restaurants', 'changed' => $st->rowCount()]);

    case 'restaurantOptions':
        out(['rows' => $conn->query("SELECT id, restaurant_id, restaurant_name FROM users ORDER BY restaurant_name")->fetchAll(PDO::FETCH_ASSOC)]);

    case 'badges':
        $t = (int)$conn->query("SELECT COUNT(*) FROM support_tickets WHERE unread_admin = 1 AND status <> 'closed'")->fetchColumn();
        $l = (int)$conn->query("SELECT COUNT(*) FROM contact_queries WHERE stage = 'new'")->fetchColumn();
        $r = (int)$conn->query("SELECT COUNT(*) FROM users u WHERE u.subscription_status <> 'disabled' AND u.is_active = 1 AND $exp BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL " . (int)$set['renewal_notice_days'] . " DAY)")->fetchColumn();
        out(['tickets' => $t, 'leads' => $l, 'renewals' => $r]);

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Throwable $e) {
    if (!headers_sent()) http_response_code(400);
    error_log('[superadmin platform] ' . $action . ': ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
