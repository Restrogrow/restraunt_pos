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
require_once __DIR__ . '/../config/sa_invoices.php';
if (ob_get_level()) ob_clean();
$conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
if (!$conn instanceof PDO) { http_response_code(500); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Database unavailable']); exit; }

$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if (!in_array($action, ['invoice', 'invoiceView', 'exportInvoices', 'leadRecording', 'downloadLeadRecordings'], true)) header('Content-Type: application/json');

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
// call recordings for leads: files live on this server in uploads/lead_recordings (web access denied, served by this API only)
const LEAD_REC_EXT = ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'opus', 'amr', '3gp', 'webm', 'flac', 'wma', 'mp4'];
function leadRecDir(): string
{
    $dir = __DIR__ . '/../uploads/lead_recordings';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new Exception('Could not create the recordings folder on the server');
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
    return $dir;
}
/** Size of each upload piece: comfortably under the server's upload and POST limits, at most 8 MB */
function leadRecChunkBytes(): int
{
    $toBytes = function ($v) { $v = trim((string)$v); $n = (float)$v; $u = strtolower(substr($v, -1)); return (int)($u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n))); };
    $lims = array_filter([$toBytes(ini_get('upload_max_filesize')), $toBytes(ini_get('post_max_size'))], function ($x) { return $x > 0; });
    $lim = $lims ? min($lims) : 8388608;
    return max(262144, min(8388608, (int)($lim * 0.8)));
}
function leadRecEnsure(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $conn->exec("CREATE TABLE IF NOT EXISTS lead_recordings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        stored_name VARCHAR(120) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        size_bytes BIGINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_lead (lead_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}
function leadRecMime(string $name): string
{
    $m = ['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'mp4' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg',
        'opus' => 'audio/ogg', 'amr' => 'audio/amr', '3gp' => 'audio/3gpp', 'webm' => 'audio/webm', 'flac' => 'audio/flac', 'wma' => 'audio/x-ms-wma'];
    return $m[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}
function zipSafe(string $s): string
{
    $s = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', ' ', $s));
    return mb_substr(preg_replace('/\s+/', ' ', $s), 0, 80) ?: 'file';
}
/**
 * Streams a ZIP (stored, no compression: audio is already compressed) without needing PHP's zip extension.
 * Switches to ZIP64 records past 4 GB, so the download has no size limit. $files: [[zipPath, diskPath], ...]
 */
function streamZip(string $zipName, array $files, int $z64 = 0xFFFFFFFF): void
{
    while (ob_get_level()) ob_end_clean();
    @set_time_limit(0);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Cache-Control: no-store');
    $offset = 0; $central = ''; $count = 0;
    $t = getdate();
    $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dosDate = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
    foreach ($files as [$name, $disk]) {
        if (!is_file($disk)) continue;
        $size = filesize($disk);
        $crc = hexdec(hash_file('crc32b', $disk));
        $big = $size >= $z64; $far = $offset >= $z64;
        // flag 0x0800 = file name is UTF-8
        $local = pack('VvvvvvVVVvv', 0x04034b50, $big ? 45 : 20, 0x0800, 0, $dosTime, $dosDate, $crc, $big ? 0xFFFFFFFF : $size, $big ? 0xFFFFFFFF : $size, strlen($name), $big ? 20 : 0)
            . $name . ($big ? pack('vvPP', 1, 16, $size, $size) : '');
        echo $local;
        $fh = fopen($disk, 'rb');
        while (!feof($fh)) { echo fread($fh, 1048576); flush(); if (connection_aborted()) exit; }
        fclose($fh);
        $extra = ($big ? pack('PP', $size, $size) : '') . ($far ? pack('P', $offset) : '');
        if ($extra !== '') $extra = pack('vv', 1, strlen($extra)) . $extra;
        $v = $extra !== '' ? 45 : 20;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, $v, $v, 0x0800, 0, $dosTime, $dosDate, $crc, $big ? 0xFFFFFFFF : $size, $big ? 0xFFFFFFFF : $size,
            strlen($name), strlen($extra), 0, 0, 0, 0, $far ? 0xFFFFFFFF : $offset) . $name . $extra;
        $offset += strlen($local) + $size;
        $count++;
    }
    $cdSize = strlen($central);
    echo $central;
    if ($count >= 0xFFFF || $offset >= $z64 || $cdSize >= $z64) {
        echo pack('VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $count, $count, $cdSize, $offset);   // ZIP64 end record
        echo pack('VVPV', 0x07064b50, 0, $offset + $cdSize, 1);                                      // and its locator
        echo pack('VvvvvVVv', 0x06054b50, 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0);
    } else {
        echo pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $cdSize, $offset, 0);
    }
    exit;
}
const LIVE_ORDER ="o.deleted_at IS NULL AND o.order_status NOT IN ('Cancelled','Rejected')";

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
            u.subscription_status = IF(u.subscription_status IN ('expired','disabled'), 'trial', u.subscription_status),
            u.trial_end_date = IF(u.subscription_status = 'trial', u.renewal_date, u.trial_end_date),   /* the trial-expiry sweep reads trial_end_date (assignments run left to right) */
            u.subscription_updated_at = NOW() WHERE u.id = ?")->execute([$d, $r['id']]);
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
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        leadRecEnsure($conn);
        $recs = [];
        foreach ($conn->query("SELECT id, lead_id, original_name, size_bytes, created_at FROM lead_recordings ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $rc) $recs[$rc['lead_id']][] = $rc;
        // where each lead came from and where they are (config/site_visitors.php)
        require_once __DIR__ . '/../config/site_visitors.php';
        $from = siteAttributionsFor($conn, 'contact', array_column($rows, 'id'));
        foreach ($rows as &$row) { $row['recordings'] = $recs[$row['id']] ?? []; $row['from'] = $from[(string)$row['id']] ?? null; }
        unset($row);
        $recTotal = $conn->query("SELECT COUNT(*) AS n, COUNT(DISTINCT lead_id) AS leads FROM lead_recordings WHERE lead_id > 0")->fetch(PDO::FETCH_ASSOC);
        out(['rows' => $rows, 'counts' => $counts, 'recordings' => ['files' => (int)$recTotal['n'], 'leads' => (int)$recTotal['leads'], 'chunk_bytes' => leadRecChunkBytes()]]);

    /* ───────── call recordings on leads ───────── */
    case 'uploadLeadRecording':
        needPost();
        leadRecEnsure($conn);
        $leadId = (int)($_POST['lead_id'] ?? 0);   // 0 = uploaded in Recordings without a lead
        if ($leadId) {
            $chk = $conn->prepare("SELECT id FROM contact_queries WHERE id = ?");
            $chk->execute([$leadId]);
            if (!$chk->fetchColumn()) throw new Exception('Lead not found');
        }
        // no size limit: the page sends big files in pieces (chunk_bytes) that are joined here, so the server's upload limit never applies
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new Exception('Upload failed, try again');
        $orig = mb_substr(basename((string)($_POST['name'] ?? $f['name'])), 0, 255);
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, LEAD_REC_EXT, true)) throw new Exception($orig . ': not an audio file (' . implode(', ', LEAD_REC_EXT) . ')');
        $dir = leadRecDir();
        $uid = (string)($_POST['upload_id'] ?? '');
        if ($uid !== '') {
            if (!preg_match('/^[a-f0-9]{16,40}$/', $uid)) throw new Exception('Upload failed, try again');
            $part = $dir . '/.part_' . $uid;
            $offset = (int)($_POST['offset'] ?? 0);
            if ($offset === 0) {
                foreach (glob($dir . '/.part_*') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);   // abandoned uploads
                @unlink($part);
            }
            clearstatcache(true, $part);
            if ((is_file($part) ? filesize($part) : 0) !== $offset) throw new Exception('Upload got out of step, try again');
            $src = fopen($f['tmp_name'], 'rb'); $dst = fopen($part, 'ab');
            if (!$src || !$dst) throw new Exception('Could not save the file on the server');
            stream_copy_to_stream($src, $dst);
            fclose($src); fclose($dst);
            clearstatcache(true, $part);
            $have = filesize($part);
            if ($have < (int)($_POST['total'] ?? 0)) out(['received' => $have]);   // more pieces to come
        }
        $stored = $leadId . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
        if ($uid !== '' ? !rename($part, $dir . '/' . $stored) : !move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) throw new Exception('Could not save the file on the server');
        clearstatcache();
        $conn->prepare("INSERT INTO lead_recordings (lead_id, stored_name, original_name, size_bytes) VALUES (?,?,?,?)")->execute([$leadId, $stored, $orig, (int)filesize($dir . '/' . $stored)]);
        $recId = (int)$conn->lastInsertId();
        pfAudit($conn, 'lead.recording.upload', $leadId ? 'Lead #' . $leadId : 'Recordings', ['file' => $orig]);
        out(['id' => $recId, 'message' => 'Recording uploaded']);

    case 'leadRecording':
        leadRecEnsure($conn);
        $st = $conn->prepare("SELECT stored_name, original_name FROM lead_recordings WHERE id = ?");
        $st->execute([(int)($_GET['id'] ?? 0)]);
        $rc = $st->fetch(PDO::FETCH_ASSOC);
        $path = $rc ? leadRecDir() . '/' . $rc['stored_name'] : '';
        if (!$rc || !is_file($path)) { http_response_code(404); header('Content-Type: text/plain'); echo 'Recording not found'; exit; }
        while (ob_get_level()) ob_end_clean();
        session_write_close();
        $size = filesize($path); $start = 0; $end = $size - 1;
        header('Content-Type: ' . leadRecMime($rc['original_name']));
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=3600');
        header('Content-Disposition: ' . (!empty($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', $rc['original_name']) . '"');
        // range requests let the audio player seek
        if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'] ?? '', $mr) && ($mr[1] !== '' || $mr[2] !== '')) {
            if ($mr[1] === '') { $start = max(0, $size - (int)$mr[2]); } else { $start = (int)$mr[1]; if ($mr[2] !== '') $end = min($end, (int)$mr[2]); }
            if ($start > $end) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: ' . ($end - $start + 1));
        $fh = fopen($path, 'rb'); fseek($fh, $start); $left = $end - $start + 1;
        while ($left > 0 && !feof($fh)) { $chunk = fread($fh, min(1048576, $left)); echo $chunk; $left -= strlen($chunk); flush(); }
        fclose($fh);
        exit;

    case 'deleteLeadRecording':
        needPost();
        leadRecEnsure($conn);
        $st = $conn->prepare("SELECT id, lead_id, stored_name, original_name FROM lead_recordings WHERE id = ?");
        $st->execute([(int)($in['id'] ?? 0)]);
        $rc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$rc) throw new Exception('Recording not found');
        @unlink(leadRecDir() . '/' . $rc['stored_name']);
        $conn->prepare("DELETE FROM lead_recordings WHERE id = ?")->execute([$rc['id']]);
        pfAudit($conn, 'lead.recording.delete', 'Lead #' . $rc['lead_id'], ['file' => $rc['original_name']]);
        out(['message' => 'Recording deleted']);

    case 'downloadLeadRecordings':
        // one ZIP, a folder per lead: "Name - Restaurant - Phone (#id)/recording.mp3"
        // scope=leads: only lead recordings (Leads page); ids=1,2,3: just those (Recordings › Download selected); else everything
        leadRecEnsure($conn);
        $leadId = (int)($_GET['lead_id'] ?? 0);
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))));
        $where = []; $p = [];
        if ($leadId) { $where[] = 'r.lead_id = ?'; $p[] = $leadId; }
        elseif (($_GET['scope'] ?? '') === 'leads') $where[] = 'r.lead_id > 0';
        if ($ids) { $where[] = 'r.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $p = array_merge($p, $ids); }
        $sql = "SELECT r.stored_name, r.original_name, r.created_at, r.lead_id, q.name, q.phone, q.restaurant_name_hint
            FROM lead_recordings r LEFT JOIN contact_queries q ON q.id = r.lead_id" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY (q.id IS NULL), q.name, q.id, r.id";
        $st = $conn->prepare($sql);
        $st->execute($p);
        $dir = leadRecDir(); $files = []; $used = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rc) {
            $folder = $rc['name'] === null ? 'Recordings' : zipSafe(implode(' - ', array_filter([$rc['name'], $rc['restaurant_name_hint'], $rc['phone']]))) . ' (#' . $rc['lead_id'] . ')';
            $name = zipSafe(pathinfo($rc['original_name'], PATHINFO_FILENAME));
            $ext = strtolower(pathinfo($rc['original_name'], PATHINFO_EXTENSION));
            $path = $folder . '/' . $name . '.' . $ext;
            for ($n = 2; isset($used[$path]); $n++) $path = $folder . '/' . $name . " ($n)." . $ext;
            $used[$path] = true;
            $files[] = [$path, $dir . '/' . $rc['stored_name']];
        }
        if (!$files) { header('Content-Type: text/plain'); echo 'No recordings yet'; exit; }
        session_write_close();
        pfAudit($conn, 'lead.recording.download', $leadId ? 'Lead #' . $leadId : ($ids ? 'Selected recordings' : 'All recordings'), ['files' => count($files)]);
        streamZip(($leadId || ($_GET['scope'] ?? '') === 'leads' ? 'lead-recordings-' : 'recordings-') . ($leadId ? $leadId . '-' : '') . ($ids ? 'selected-' : '') . date('Y-m-d') . '.zip', $files);

    case 'recordings':
        // Super Admin › Recordings: every recording, uploaded there or on a lead
        leadRecEnsure($conn);
        $q = trim((string)($_GET['q'] ?? ''));
        $sql = "SELECT r.id, r.lead_id, r.original_name, r.size_bytes, r.created_at, q.name AS lead_name, q.phone AS lead_phone, q.restaurant_name_hint
            FROM lead_recordings r LEFT JOIN contact_queries q ON q.id = r.lead_id";
        $p = [];
        if ($q !== '') { $sql .= " WHERE r.original_name LIKE ? OR q.name LIKE ? OR q.phone LIKE ? OR q.restaurant_name_hint LIKE ?"; $p = array_fill(0, 4, '%' . $q . '%'); }
        $st = $conn->prepare($sql . " ORDER BY r.id DESC LIMIT 2000");
        $st->execute($p);
        $tot = $conn->query("SELECT COUNT(*) AS n, COALESCE(SUM(size_bytes),0) AS bytes FROM lead_recordings")->fetch(PDO::FETCH_ASSOC);
        $leads = $conn->query("SELECT id, name, phone, restaurant_name_hint FROM contact_queries ORDER BY id DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
        out(['rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => (int)$tot['n'], 'bytes' => (int)$tot['bytes'], 'leads' => $leads,
            'chunk_bytes' => leadRecChunkBytes()]);

    case 'moveRecording':
        // link a recording to a lead, or unlink it (lead_id 0)
        needPost();
        leadRecEnsure($conn);
        $leadId = (int)($in['lead_id'] ?? 0);
        if ($leadId) { $chk = $conn->prepare("SELECT id FROM contact_queries WHERE id = ?"); $chk->execute([$leadId]); if (!$chk->fetchColumn()) throw new Exception('Lead not found'); }
        $conn->prepare("UPDATE lead_recordings SET lead_id = ? WHERE id = ?")->execute([$leadId, (int)($in['id'] ?? 0)]);
        out(['message' => $leadId ? 'Linked to the lead' : 'Unlinked from the lead']);

    /* ───────── chatbot leads ───────── */
    case 'chatLeads':
        require_once __DIR__ . '/../config/chatbot_store.php';
        rgEnsureChatbotTables($conn);
        $q = trim((string)($_GET['q'] ?? ''));
        $status = (string)($_GET['status'] ?? 'all');
        $allowedStatuses = ['new', 'contacted', 'qualified', 'closed'];
        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(l.name LIKE ? OR l.restaurant_name LIKE ? OR l.email LIKE ? OR l.phone LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($status !== 'all' && in_array($status, $allowedStatuses, true)) {
            $where[] = 'l.status = ?';
            $params[] = $status;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $conn->prepare("SELECT l.id, l.name, l.restaurant_name, l.email, l.phone, l.status, l.created_at, l.updated_at,
                (SELECT COUNT(*) FROM chatbot_messages m WHERE m.lead_id = l.id) AS message_count,
                (SELECT COUNT(*) FROM chatbot_messages m WHERE m.lead_id = l.id AND m.channel = 'voice') AS voice_count,
                (SELECT m.message FROM chatbot_messages m WHERE m.lead_id = l.id ORDER BY m.id DESC LIMIT 1) AS last_message
            FROM chatbot_leads l $whereSql ORDER BY l.updated_at DESC, l.id DESC LIMIT 300");
        $stmt->execute($params);
        $counts = $conn->query("SELECT status, COUNT(*) AS n FROM chatbot_leads GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $chatRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        require_once __DIR__ . '/../config/site_visitors.php';
        $from = siteAttributionsFor($conn, 'chat', array_column($chatRows, 'id'));
        foreach ($chatRows as &$cr) $cr['from'] = $from[(string)$cr['id']] ?? null;
        unset($cr);
        out(['rows' => $chatRows, 'counts' => $counts]);

    case 'chatConversation':
        require_once __DIR__ . '/../config/chatbot_store.php';
        rgEnsureChatbotTables($conn);
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare('SELECT id, name, restaurant_name, email, phone, status, consent_at, created_at, updated_at FROM chatbot_leads WHERE id = ?');
        $stmt->execute([$id]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lead) throw new Exception('Chat lead not found');
        $stmt = $conn->prepare('SELECT sender, message, channel, created_at FROM chatbot_messages WHERE lead_id = ? ORDER BY id ASC');
        $stmt->execute([$id]);
        out(['lead' => $lead, 'messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    case 'updateChatLead':
        needPost();
        require_once __DIR__ . '/../config/chatbot_store.php';
        rgEnsureChatbotTables($conn);
        $id = (int)($in['id'] ?? 0);
        $status = (string)($in['status'] ?? '');
        if ($id < 1 || !in_array($status, ['new', 'contacted', 'qualified', 'closed'], true)) {
            throw new Exception('Choose a valid chat lead and status');
        }
        $stmt = $conn->prepare('UPDATE chatbot_leads SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        if ($stmt->rowCount() === 0) {
            $check = $conn->prepare('SELECT id FROM chatbot_leads WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetchColumn()) throw new Exception('Chat lead not found');
        }
        pfAudit($conn, 'chat_lead.status', 'Chat lead #' . $id, ['status' => $status]);
        out(['message' => 'Chat lead status updated']);

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
        leadRecEnsure($conn);
        $recs = $conn->prepare("SELECT stored_name FROM lead_recordings WHERE lead_id = ?");
        $recs->execute([(int)($in['id'] ?? 0)]);
        foreach ($recs->fetchAll(PDO::FETCH_COLUMN) as $sn) @unlink(leadRecDir() . '/' . $sn);
        $conn->prepare("DELETE FROM lead_recordings WHERE lead_id = ?")->execute([(int)($in['id'] ?? 0)]);
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
        if ($body !== '') {
            try {
                require_once __DIR__ . '/../config/mail_engine.php';
                mailSupportReply($conn, (string)$t['restaurant_id'], (string)$t['subject'], $body);
            } catch (Throwable $e) { error_log('support reply email: ' . $e->getMessage()); }
        }
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
        // invoices made in the Invoice generator (cancelled ones left out)
        saInvEnsure($conn);
        $mi = $conn->prepare("SELECT i.*, u.gstin_no FROM sa_invoices i LEFT JOIN users u ON u.restaurant_id = i.restaurant_id
            WHERE i.status <> 'void' AND DATE_FORMAT(i.invoice_date, '%Y-%m') = ? ORDER BY i.id DESC");
        $mi->execute([$month]);
        foreach ($mi as $i) {
            $g = ['base' => (float)$i['taxable'], 'tax' => round((float)$i['cgst'] + (float)$i['sgst'] + (float)$i['igst'], 2), 'total' => (float)$i['total']];
            $rows[] = ['id' => (int)$i['id'], 'kind' => 'manual', 'invoice_no' => $i['invoice_no'], 'created_at' => $i['invoice_date'], 'restaurant_id' => $i['restaurant_id'],
                'restaurant_name' => $i['restaurant_name'], 'gstin_no' => $i['gstin_no'], 'subscription_type' => 'Generated', 'duration_months' => 0,
                'payment_method' => $i['status'] === 'due' ? 'Unpaid' : $i['method'], 'gst' => $g];
            foreach ($sum as $k => $_) $sum[$k] += $g[$k];
        }
        usort($rows, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
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
        fputcsv($f, ['Invoice no', 'Date', 'Restaurant', 'Restaurant ID', 'Customer GSTIN', 'Plan', 'Months', 'Method', 'Transaction', 'Taxable value', 'GST %', 'CGST', 'SGST', 'IGST', 'Total', 'Status']);
        foreach ($st as $r) {
            $g = pfGst($set, (float)$r['amount']);
            // same inter-state rule as the invoice itself (action=invoice): IGST instead of CGST + SGST
            $inter = saInvStateCode($r['gstin_no']) !== '' && saInvStateCode($set['biz_gstin']) !== '' && saInvStateCode($r['gstin_no']) !== saInvStateCode($set['biz_gstin']);
            fputcsv($f, [pfInvoiceNo($set, $r), substr($r['created_at'], 0, 10), $r['restaurant_name'], $r['restaurant_id'], $r['gstin_no'], $r['subscription_type'], $r['duration_months'],
                $r['payment_method'], $r['transaction_id'], $g['base'], $g['rate'], $inter ? 0 : $g['cgst'], $inter ? 0 : $g['sgst'], $inter ? $g['tax'] : 0, $g['total'], 'Paid']);
        }
        saInvEnsure($conn);
        $mi = $conn->prepare("SELECT i.*, u.gstin_no FROM sa_invoices i LEFT JOIN users u ON u.restaurant_id = i.restaurant_id
            WHERE i.status <> 'void' AND DATE_FORMAT(i.invoice_date, '%Y-%m') = ? ORDER BY i.seq");
        $mi->execute([$month]);
        foreach ($mi as $i) {
            $months = $i['period_from'] && $i['period_to'] ? (int)round((strtotime($i['period_to']) - strtotime($i['period_from'])) / 2629746) : '';
            fputcsv($f, [$i['invoice_no'], $i['invoice_date'], $i['restaurant_name'], $i['restaurant_id'], $i['gstin_no'], 'Invoice generator', $months,
                $i['method'], $i['reference'], $i['taxable'], $i['tax_rate'], $i['cgst'], $i['sgst'], $i['igst'], $i['total'], $i['status'] === 'due' ? 'Unpaid' : 'Paid']);
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
        $months = (int)$p['duration_months'];
        $inter = saInvStateCode($p['gstin_no']) !== '' && saInvStateCode($set['biz_gstin']) !== '' && saInvStateCode($p['gstin_no']) !== saInvStateCode($set['biz_gstin']);
        $paid = $p['payment_status'] === 'success';
        echo saInvRender([
            'invoice_no' => pfInvoiceNo($set, $p), 'invoice_date' => substr($p['created_at'], 0, 10), 'due_date' => null, 'paid_on' => substr($p['created_at'], 0, 10),
            'status' => $paid ? 'paid' : 'due', 'method' => ucfirst((string)($p['payment_method'] ?: 'Online')), 'reference' => $p['transaction_id'],
            'period_from' => null, 'period_to' => null,
            'billed_to' => ['name' => $p['restaurant_name'], 'owner' => $p['owner_name'], 'address' => $p['address'], 'gstin' => $p['gstin_no'], 'email' => $p['email'], 'phone' => $p['phone'], 'rid' => $p['restaurant_id']],
            'items' => [['desc' => 'RestroGrow restaurant software subscription', 'sub' => ucfirst((string)$p['subscription_type']) . ' plan' . ($months ? ' · ' . $months . ' month' . ($months > 1 ? 's' : '') : '') . ($p['notes'] ? ' · ' . $p['notes'] : ''),
                'sac' => '998314', 'qty' => 1, 'unit' => '', 'rate' => $g['base'], 'amount' => $g['base']]],
            'subtotal' => $g['base'], 'discount' => 0, 'taxable' => $g['base'], 'tax_rate' => $g['rate'],
            'cgst' => $inter ? 0 : $g['cgst'], 'sgst' => $inter ? 0 : $g['sgst'], 'igst' => $inter ? $g['tax'] : 0, 'total' => $g['total'], 'notes' => '',
        ], $set, ['autodownload' => !empty($_GET['dl'])]);
        exit;

    /* 12b. invoice generator: pick restaurant, plan, months, add-ons → numbered invoice + branded PDF */
    case 'invoiceData':
        saInvEnsure($conn);
        $st = $conn->prepare("SELECT u.id, u.restaurant_id, u.restaurant_name, u.owner_name, u.email, u.phone, u.address, u.gstin_no, u.subscription_status,
                u.renewal_date, u.trial_end_date, $exp AS expires_on FROM users u WHERE u.id = ?");
        $st->execute([(int)($_GET['id'] ?? 0)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new Exception('Restaurant not found');
        $tc = $conn->prepare('SELECT COUNT(*) FROM tables WHERE restaurant_id = ?');
        $tc->execute([$r['restaurant_id']]);
        $tables = (int)$tc->fetchColumn();
        $branches = 1;
        try {
            $bc = $conn->prepare('SELECT COUNT(DISTINCT l2.restaurant_id) FROM branch_restaurant_links l1 JOIN branch_restaurant_links l2 ON l2.branch_admin_id = l1.branch_admin_id WHERE l1.restaurant_id = ?');
            $bc->execute([$r['restaurant_id']]);
            $branches = max(1, (int)$bc->fetchColumn());
        } catch (Throwable $e) { /* no chains on this database */ }
        $lp = $conn->prepare("SELECT subscription_type, duration_months, amount, payment_method, created_at FROM subscription_payments WHERE restaurant_id = ? AND payment_status = 'success' ORDER BY id DESC LIMIT 1");
        $lp->execute([$r['restaurant_id']]);
        $last = $lp->fetch(PDO::FETCH_ASSOC) ?: null;
        $li = $conn->prepare("SELECT invoice_no, total, status, invoice_date, period_to FROM sa_invoices WHERE restaurant_id = ? AND status <> 'void' ORDER BY id DESC LIMIT 1");
        $li->execute([$r['restaurant_id']]);
        $suggest = $branches >= 2 ? 'multi' : ($tables > 5 ? 'growing' : 'small');
        $start = ($r['expires_on'] && $r['expires_on'] >= date('Y-m-d')) ? date('Y-m-d', strtotime($r['expires_on'] . ' +1 day')) : date('Y-m-d');
        $inter = saInvStateCode($r['gstin_no']) !== '' && saInvStateCode($set['biz_gstin']) !== '' && saInvStateCode($r['gstin_no']) !== saInvStateCode($set['biz_gstin']);
        out(['restaurant' => $r, 'tables' => $tables, 'branches' => $branches, 'suggested_plan' => $suggest, 'period_from' => $start,
            'last_payment' => $last, 'last_invoice' => $li->fetch(PDO::FETCH_ASSOC) ?: null,
            'invoice_no' => saInvPeekNo($conn, $set), 'today' => date('Y-m-d'), 'catalog' => saInvCatalog($set), 'inter' => $inter,
            'settings' => ['gst_percent' => (float)$set['gst_percent'], 'prices_include_gst' => $set['prices_include_gst'] === '1', 'biz_gstin' => $set['biz_gstin'],
                'biz_upi' => $set['biz_upi'] ?? '', 'biz_ready' => trim($set['biz_address']) !== '']]);

    case 'invoiceCreate':
        needPost();
        $st = $conn->prepare("SELECT u.id, u.restaurant_id, u.restaurant_name, u.owner_name, u.email, u.phone, u.address, u.gstin_no, $exp AS expires_on FROM users u WHERE u.id = ?");
        $st->execute([(int)($in['id'] ?? 0)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new Exception('Choose a restaurant first');
        $cat = saInvCatalog($set);
        $date = date('Y-m-d');
        $lines = [];
        // the plan: price from the catalogue (never from the browser), quantity = months
        $months = max(0, min(36, (int)($in['months'] ?? 0)));
        $from = $to = null;
        $planKey = (string)($in['plan'] ?? '');
        if ($planKey !== '' && $months > 0) {
            $plan = null;
            foreach ($cat['plans'] as $p) if ($p['key'] === $planKey) $plan = $p;
            if (!$plan) throw new Exception('Unknown plan');
            $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['period_from'] ?? '')) ? $in['period_from'] : date('Y-m-d');
            $to = date('Y-m-d', strtotime($from . " +$months month -1 day"));
            $lines[] = ['desc' => $plan['name'] . ' plan · RestroGrow POS', 'sub' => 'Subscription ' . date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to)) . ($plan['sub'] ? ' · ' . $plan['sub'] : ''),
                'sac' => $cat['sac'], 'qty' => $months, 'unit' => $months > 1 ? 'months' : 'month', 'rate' => $plan['price']];
        }
        foreach ((array)($in['addons'] ?? []) as $a) {
            foreach ($cat['addons'] as $x) {
                if ($x['key'] !== ($a['key'] ?? '')) continue;
                $qty = $x['per'] ? max(1, min(500, (int)($a['qty'] ?? 1))) : 1;
                $lines[] = ['desc' => $x['name'], 'sub' => trim($x['sub'] . ' · one-time', ' ·'), 'sac' => $x['sac'], 'qty' => $qty, 'unit' => $x['per'] ? ($qty > 1 ? $x['per'] . 's' : $x['per']) : '', 'rate' => $x['price']];
            }
        }
        foreach ((array)($in['custom'] ?? []) as $c) {
            $desc = trim((string)($c['desc'] ?? ''));
            if ($desc !== '' && (float)($c['rate'] ?? 0) > 0) $lines[] = ['desc' => $desc, 'sub' => '', 'sac' => $cat['sac'], 'qty' => max(1, (float)($c['qty'] ?? 1)), 'unit' => '', 'rate' => (float)$c['rate']];
        }
        if (!$lines) throw new Exception('Pick a plan or at least one item');
        $sub = 0; foreach ($lines as $l) $sub += $l['qty'] * $l['rate'];
        $dv = max(0, (float)($in['discount_value'] ?? 0));
        $disc = ($in['discount_type'] ?? 'pct') === 'amt' ? $dv : round($sub * min($dv, 100) / 100, 2);
        $inter = saInvStateCode($r['gstin_no']) !== '' && saInvStateCode($set['biz_gstin']) !== '' && saInvStateCode($r['gstin_no']) !== saInvStateCode($set['biz_gstin']);
        $c = saInvCompute($lines, $disc, (float)$set['gst_percent'], $set['prices_include_gst'] === '1', $inter);
        $status = ($in['status'] ?? 'paid') === 'due' ? 'due' : 'paid';
        $method = mb_substr(trim((string)($in['method'] ?? '')), 0, 30) ?: 'UPI';
        $dueDays = max(0, min(90, (int)($in['due_days'] ?? 7)));
        $inv = saInvCreate($conn, $set, $c + [
            'restaurant_id' => $r['restaurant_id'], 'restaurant_name' => $r['restaurant_name'],
            'billed_to' => ['name' => $r['restaurant_name'], 'owner' => $r['owner_name'], 'address' => $r['address'], 'gstin' => strtoupper((string)$r['gstin_no']), 'email' => $r['email'], 'phone' => $r['phone'], 'rid' => $r['restaurant_id']],
            'status' => $status, 'method' => $status === 'paid' ? $method : null, 'reference' => mb_substr(trim((string)($in['reference'] ?? '')), 0, 100) ?: null,
            'invoice_date' => $date, 'due_date' => $status === 'due' ? date('Y-m-d', strtotime("+$dueDays day")) : null, 'paid_on' => $status === 'paid' ? $date : null,
            'period_from' => $from, 'period_to' => $to, 'notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 600) ?: null,
            'created_by' => $_SESSION['superadmin_username'] ?? null,
        ]);
        $extended = false;
        if ($status === 'paid' && $to && !empty($in['extend'])) {
            $conn->prepare("UPDATE users SET renewal_date = GREATEST(COALESCE(renewal_date, '1970-01-01'), ?), subscription_status = 'active', is_active = 1, disabled_at = NULL, subscription_updated_at = NOW() WHERE id = ?")
                ->execute([$to, $r['id']]);
            $extended = true;
        }
        pfAudit($conn, 'invoice.create', $r['restaurant_name'] . ' (' . $r['restaurant_id'] . ')', ['invoice_no' => $inv['invoice_no'], 'total' => $inv['total'], 'status' => $status, 'extended_to' => $extended ? $to : null]);
        out(['invoice' => ['id' => (int)$inv['id'], 'invoice_no' => $inv['invoice_no'], 'total' => (float)$inv['total'], 'status' => $status],
            'message' => 'Invoice ' . $inv['invoice_no'] . ' created' . ($extended ? ' · plan extended to ' . date('d M Y', strtotime($to)) : '')]);

    case 'invoiceView':
        header('Content-Type: text/html; charset=utf-8');
        try { $inv = saInvGet($conn, (int)($_GET['id'] ?? 0)); } catch (Throwable $e) { http_response_code(404); echo 'Invoice not found'; exit; }
        echo saInvRender($inv, $set, ['autodownload' => !empty($_GET['dl'])]);
        exit;

    case 'invoiceList':
        saInvEnsure($conn);
        $q = trim((string)($_GET['q'] ?? ''));
        $sql = 'SELECT id, invoice_no, restaurant_id, restaurant_name, total, status, method, invoice_date, due_date, period_from, period_to, created_by, created_at, billed_to FROM sa_invoices';
        $p = [];
        if ($q !== '') { $sql .= ' WHERE invoice_no LIKE ? OR restaurant_name LIKE ? OR restaurant_id LIKE ?'; $p = ["%$q%", "%$q%", "%$q%"]; }
        $st = $conn->prepare($sql . ' ORDER BY id DESC LIMIT 100');
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        // where "Email" sends it: the billed-to email, else the restaurant's
        // the restaurants' emails in one query (it was one per invoice)
        $emails = [];
        $rids = array_values(array_unique(array_column($rows, 'restaurant_id')));
        if ($rids) {
            $ue = $conn->prepare('SELECT restaurant_id, email FROM users WHERE restaurant_id IN (' . implode(',', array_fill(0, count($rids), '?')) . ') ORDER BY id');
            $ue->execute($rids);
            foreach ($ue->fetchAll(PDO::FETCH_ASSOC) as $u) $emails[strtoupper((string)$u['restaurant_id'])] ??= (string)$u['email'];
        }
        foreach ($rows as &$r) {
            $bt = json_decode((string)$r['billed_to'], true) ?: [];
            if (empty($bt['email'])) $bt['email'] = $emails[strtoupper((string)$r['restaurant_id'])] ?? '';
            $r['email'] = $bt['email'];
            unset($r['billed_to']);
        }
        unset($r);
        $sm = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'paid' THEN total END), 0) paid, COALESCE(SUM(CASE WHEN status = 'due' THEN total END), 0) due,
            COALESCE(SUM(status = 'due'), 0) due_n, COALESCE(SUM(status <> 'void'), 0) n FROM sa_invoices WHERE fy = ?");
        $sm->execute([saInvFy()]);
        out(['rows' => $rows, 'fy' => saInvFy(), 'sum' => $sm->fetch(PDO::FETCH_ASSOC)]);

    case 'invoiceMark':
        needPost();
        $inv = saInvGet($conn, (int)($in['id'] ?? 0));
        $to = (string)($in['status'] ?? '');
        if ($to === 'paid') {
            if ($inv['status'] !== 'due') throw new Exception('Only an unpaid invoice can be marked paid');
            $conn->prepare("UPDATE sa_invoices SET status = 'paid', paid_on = CURDATE(), method = ?, reference = COALESCE(?, reference) WHERE id = ?")
                ->execute([mb_substr(trim((string)($in['method'] ?? 'UPI')), 0, 30) ?: 'UPI', mb_substr(trim((string)($in['reference'] ?? '')), 0, 100) ?: null, $inv['id']]);
        } elseif ($to === 'void') {
            if ($inv['status'] === 'void') throw new Exception('Already cancelled');
            $conn->prepare("UPDATE sa_invoices SET status = 'void' WHERE id = ?")->execute([$inv['id']]);
        } else throw new Exception('Unknown status');
        pfAudit($conn, 'invoice.' . $to, $inv['restaurant_name'] . ' (' . $inv['restaurant_id'] . ')', ['invoice_no' => $inv['invoice_no']]);
        out(['message' => $to === 'paid' ? 'Marked as paid' : 'Invoice cancelled (its number stays used)']);

    case 'invoiceEmail':
        needPost();
        $inv = saInvGet($conn, (int)($in['id'] ?? 0));
        if ($inv['status'] === 'void') throw new Exception('A cancelled invoice is not emailed');
        require_once __DIR__ . '/../config/mail_engine.php';
        $to = trim((string)($in['to'] ?? ''));
        if ($to !== '') {
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new Exception('That email address does not look right');
            $inv['billed_to']['email'] = $to;
        }
        if (!mailInvoice($conn, $inv)) throw new Exception('No email address for this restaurant (or invoice emails are switched off in Email)');
        pfAudit($conn, 'invoice.email', $inv['restaurant_name'] . ' (' . $inv['restaurant_id'] . ')', ['invoice_no' => $inv['invoice_no'], 'to' => $to ?: ($inv['billed_to']['email'] ?? '')]);
        out(['message' => 'Invoice emailed']);

    case 'invoiceCatalog':
        if ($isPost) {
            saInvSaveCatalog($conn, $in);
            pfAudit($conn, 'invoice.prices', null, $in);
            $set = pfSettings($conn);
        }
        out(['catalog' => saInvCatalog($set), 'message' => 'Prices saved']);

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
            // same as the single Extend: an ended plan comes back as a trial until the new date
            $st = $conn->prepare("UPDATE users u SET u.renewal_date = DATE_ADD(GREATEST($exp, CURDATE()), INTERVAL ? DAY), u.is_active = 1, u.disabled_at = NULL,
                u.subscription_status = IF(u.subscription_status IN ('expired','disabled'), 'trial', u.subscription_status),
                u.trial_end_date = IF(u.subscription_status = 'trial', u.renewal_date, u.trial_end_date), u.subscription_updated_at = NOW() WHERE u.id IN ($ph)");
            $st->execute(array_merge([$d], $ids));
            $msg = "Extended by $d days";
        } elseif ($do === 'disable') {
            $st = $conn->prepare("UPDATE users SET is_active = 0, subscription_status = 'disabled', disabled_at = NOW() WHERE id IN ($ph)");
            $st->execute($ids);
            $msg = 'Disabled';
        } elseif ($do === 'enable') {
            // same as the single Enable: back on, 30 days from today
            $st = $conn->prepare("UPDATE users SET is_active = 1, subscription_status = 'trial', renewal_date = DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY), trial_end_date = DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY), disabled_at = NULL WHERE id IN ($ph) AND is_active = 0");
            $st->execute($ids);
            $msg = 'Enabled';
        } elseif ($do === 'activate') {
            // same as the single "Activate 30 days" in Subscriptions (api.php updateSubscription): paid for 30 days from today,
            // with a ₹0 manual payment row so the history shows who switched it on. Restaurants already active
            // until today + 30 days or later are left alone: a yearly plan isn't cut short, and clicking twice
            // adds no second history row
            $rows = $conn->prepare("SELECT id, restaurant_id FROM users WHERE id IN ($ph)
                AND NOT (subscription_status = 'active' AND is_active = 1 AND renewal_date >= DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY))");
            $rows->execute($ids);
            $todo = $rows->fetchAll(PDO::FETCH_ASSOC);
            $changed = 0;
            if ($todo) {
                $tph = implode(',', array_fill(0, count($todo), '?'));
                $st = $conn->prepare("UPDATE users SET subscription_status = 'active', renewal_date = DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY), is_active = 1, disabled_at = NULL, subscription_updated_at = NOW() WHERE id IN ($tph)");
                $st->execute(array_column($todo, 'id'));
                $pay = $conn->prepare("INSERT INTO subscription_payments (user_id, restaurant_id, transaction_id, amount, subscription_type, duration_months, payment_status, payment_method, notes) VALUES (?, ?, ?, 0, 'monthly', 1, 'success', 'manual', 'Bulk activate')");
                foreach ($todo as $r) $pay->execute([$r['id'], $r['restaurant_id'], 'ADMIN_' . time() . '_' . $r['id']]);
                $changed = $st->rowCount();
            }
            $msg = 'Activated for 30 days';
        } elseif ($do === 'expire') {
            // same as the single Expire: plan marked expired, restaurant left on so it can still pay
            $st = $conn->prepare("UPDATE users SET subscription_status = 'expired', is_active = 1, subscription_updated_at = NOW() WHERE id IN ($ph)");
            $st->execute($ids);
            $msg = 'Marked expired';
        } else {
            throw new Exception('Unknown action');
        }
        // the audit names only the restaurants this touched (activate may skip some)
        $auditIds = isset($todo) ? array_map('intval', array_column($todo, 'id')) : $ids;
        $restIds = [];
        if ($auditIds) {
            $names = $conn->prepare('SELECT restaurant_id FROM users WHERE id IN (' . implode(',', array_fill(0, count($auditIds), '?')) . ')');
            $names->execute($auditIds);
            $restIds = $names->fetchAll(PDO::FETCH_COLUMN);
        }
        pfAudit($conn, 'bulk.' . $do, count($auditIds) . ' restaurants', ['ids' => implode(',', $restIds), 'feature' => $in['feature'] ?? null, 'on' => $in['on'] ?? null, 'days' => $in['days'] ?? null]);
        $changed = $changed ?? $st->rowCount();   // activate counts its own (it may skip every row)
        out(['message' => $msg . ' for ' . $changed . ' of ' . count($ids) . ' restaurants', 'changed' => $changed]);

    /* ───────── email check: a fun "offer accepted" email sent with the live SMTP settings ───────── */
    case 'testEmail':
        needPost();
        $to = trim((string)($in['to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new Exception('Enter a valid email address');
        $recent = (int)$conn->query("SELECT COUNT(*) FROM sa_audit_log WHERE action = 'email.test' AND created_at >= NOW() - INTERVAL 10 MINUTE")->fetchColumn();
        if ($recent >= 5) throw new Exception('Five test emails in 10 minutes is enough. Try again a little later.');
        require_once __DIR__ . '/../config/email_config.php';
        if (!SMTP_ENABLED) throw new Exception('Email is switched off: set SMTP_ENABLED=true in main/.env');
        if (SMTP_PASSWORD === '') throw new Exception('SMTP_PASSWORD is empty in main/.env');
        $li = function ($t) { return '<li style="margin:0 0 8px">' . $t . '</li>'; };
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;background:#f3f5fa;padding:24px 12px"><div style="max-width:560px;margin:auto;background:#fff;border-radius:18px;overflow:hidden;border:1px solid #e5e7eb">'
            . '<div style="background:linear-gradient(135deg,#16a34a,#0891b2);padding:28px 26px;color:#fff"><div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;opacity:.85">RestroGrow · HR Department (of one)</div>'
            . '<div style="font-size:26px;font-weight:800;margin-top:6px">Offer accepted! Welcome aboard 🥳</div><div style="font-size:15px;margin-top:6px;opacity:.95">Your Chief Everything Officer badge is now active.</div></div>'
            . '<div style="padding:24px 26px;color:#374151;font-size:15px;line-height:1.6">'
            . '<p style="margin:0 0 14px">We received your “I’m in”. The whole team (us) did a small dance next to the POS. Here is what happens next:</p><ol style="margin:0 0 16px;padding-left:20px">'
            . $li('<b>Day 1:</b> collect your welcome kit: one Paneer Tikka, still the #1 Star dish')
            . $li('<b>Day 2:</b> master the sacred art of tapping <i>Accept · 25 + 20 min</i>')
            . $li('<b>Day 3:</b> make Thursday great again (the Analytics page is watching)')
            . $li('<b>Every day:</b> answer the new-order ring, even from another tab') . '</ol>'
            . '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px 16px;color:#14532d"><b>Good news:</b> if you are reading this, email from <b>' . htmlspecialchars(SMTP_FROM_EMAIL) . '</b> works on the live site. Setup complete ✅</div>'
            . '<p style="margin:18px 0 0">See you at the next push to production,<br><b>Team RestroGrow</b></p></div>'
            . '<div style="background:#f9fafb;padding:12px 26px;color:#9ca3af;font-size:12px">Sent from Superadmin › Settings › Send test email on ' . date('d M Y, g:i A') . '. Just for fun, not a contract. 😄</div></div></div>';
        $purpose = isset(rgMailSenders()[$in['purpose'] ?? '']) ? $in['purpose'] : null;
        $fromUsed = rgMailFrom($purpose);
        $html = str_replace(htmlspecialchars(SMTP_FROM_EMAIL), htmlspecialchars($fromUsed), $html);
        $r = sendSMTPEmail($to, 'Offer accepted! Welcome to RestroGrow 🥳', $html, '', $purpose);
        pfAudit($conn, 'email.test', $to, ['ok' => !empty($r['success']), 'host' => SMTP_HOST, 'from' => $fromUsed]);
        if (empty($r['success'])) throw new Exception('Not sent: ' . trim(preg_replace('/\s+/', ' ', (string)($r['error'] ?? 'unknown error'))) . ' (server ' . SMTP_HOST . ':' . SMTP_PORT . ', login ' . SMTP_USERNAME . ')');
        out(['message' => 'Sent to ' . $to . ' from ' . $fromUsed . ' via ' . SMTP_HOST . '. Check the inbox (and Spam / Promotions). If it shows ' . SMTP_USERNAME . ' as the sender instead, add ' . $fromUsed . ' under Gmail › Settings › Accounts › Send mail as.']);

    case 'mailSenders':
        require_once __DIR__ . '/../config/email_config.php';
        $list = [];
        foreach (rgMailSenders() as $k => $envKey) $list[] = ['purpose' => $k, 'env' => $envKey, 'from' => rgMailFrom($k), 'set' => trim((string)env($envKey, '')) !== ''];
        out(['senders' => $list, 'default' => SMTP_FROM_EMAIL, 'login' => SMTP_USERNAME]);

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
