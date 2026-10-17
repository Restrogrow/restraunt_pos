<?php
/**
 * Platform engine: the superadmin side of RestroGrow (settings, audit log, announcements,
 * support tickets, leads). Tables create themselves on first use (local and live), versioned in
 * sa_settings('schema'), so no manual migration is needed.
 *
 * Used by superadmin/api_platform.php (superadmin) and api/platform.php (restaurant dashboard).
 */

const PF_SCHEMA = 2;

function pfCol(PDO $conn, string $table, string $col): bool
{
    $st = $conn->query("SHOW COLUMNS FROM `$table` LIKE " . $conn->quote($col));
    return (bool)($st && $st->fetch());
}

function pfEnsure(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->exec("CREATE TABLE IF NOT EXISTS sa_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $v = (int)$conn->query("SELECT v FROM sa_settings WHERE k = 'schema'")->fetchColumn();
    if ($v >= PF_SCHEMA) return;

    $conn->exec("CREATE TABLE IF NOT EXISTS sa_audit_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NULL, admin_name VARCHAR(100) NULL,
        action VARCHAR(60) NOT NULL, target VARCHAR(160) NULL, details TEXT NULL,
        ip_address VARCHAR(45) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (created_at), INDEX (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS sa_announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(160) NOT NULL, body TEXT NULL,
        level VARCHAR(10) NOT NULL DEFAULT 'info',
        target_all TINYINT(1) NOT NULL DEFAULT 1, target_ids TEXT NULL,
        starts_at DATETIME NULL, ends_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1, dismissible TINYINT(1) NOT NULL DEFAULT 1,
        created_by VARCHAR(100) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS sa_announcement_dismiss (
        announcement_id INT NOT NULL, restaurant_id VARCHAR(10) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (announcement_id, restaurant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS support_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(10) NOT NULL, subject VARCHAR(160) NOT NULL,
        category VARCHAR(30) NOT NULL DEFAULT 'other',
        status VARCHAR(12) NOT NULL DEFAULT 'open',
        priority VARCHAR(10) NOT NULL DEFAULT 'normal',
        created_by_name VARCHAR(100) NULL,
        unread_admin TINYINT(1) NOT NULL DEFAULT 1, unread_restaurant TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (restaurant_id), INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS support_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL, sender VARCHAR(12) NOT NULL, sender_name VARCHAR(100) NULL,
        body TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (ticket_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // leads: the website contact form already fills contact_queries
    $conn->exec("CREATE TABLE IF NOT EXISTS contact_queries (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, email VARCHAR(255) NULL, phone VARCHAR(50) NULL,
        message TEXT NULL, status ENUM('new','read','replied','closed') DEFAULT 'new',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach ([
        'stage' => "VARCHAR(20) NOT NULL DEFAULT 'new'",
        'lead_notes' => 'TEXT NULL',
        'follow_up_on' => 'DATE NULL',
        'restaurant_name_hint' => 'VARCHAR(160) NULL',
        'converted_restaurant_id' => 'VARCHAR(10) NULL',
    ] as $c => $def) {
        if (!pfCol($conn, 'contact_queries', $c)) $conn->exec("ALTER TABLE contact_queries ADD COLUMN `$c` $def");
    }
    // join with users / orders without "Illegal mix of collations": use the same collation as users.restaurant_id
    $coll = $conn->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'restaurant_id'")->fetchColumn();
    if ($coll && preg_match('/^utf8mb4_[a-z0-9_]+$/', $coll)) {
        foreach (['sa_audit_log', 'sa_announcements', 'sa_announcement_dismiss', 'support_tickets', 'support_messages'] as $t) $conn->exec("ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE $coll");
    }
    $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES ('schema', ?)")->execute([PF_SCHEMA]);
}

/** Platform settings with defaults (plan price, trial length, invoice / GST details). */
function pfSettings(PDO $conn): array
{
    pfEnsure($conn);
    $d = [
        'trial_days' => '30', 'plan_price' => '999', 'plan_price_yearly' => '9990', 'auto_renewal' => '1',
        'renewal_notice_days' => '7',
        'biz_name' => 'RestroGrow', 'biz_address' => '', 'biz_gstin' => '', 'biz_email' => '', 'biz_phone' => '',
        'gst_percent' => '18', 'prices_include_gst' => '1', 'invoice_prefix' => 'RG', 'biz_upi' => '', 'biz_bank' => '',
    ];
    foreach ($conn->query("SELECT k, v FROM sa_settings WHERE k <> 'schema'") as $r) $d[$r['k']] = (string)$r['v'];
    return $d;
}

/**
 * What a restaurant pays to renew online: always the Super Admin price for the plan, never an amount
 * sent by the browser (that used to let anyone renew a year for ₹1). [amount, months]
 */
function pfRenewalPrice(PDO $conn, string $type): array
{
    $s = pfSettings($conn);
    return $type === 'yearly' ? [round((float)$s['plan_price_yearly'], 2), 12] : [round((float)$s['plan_price'], 2), 1];
}

/**
 * Online renewal is PhonePe in Indian rupees, so only Indian restaurants can use it; the rest renew
 * through support. Throws the message to show.
 */
function pfAssertCanRenewOnline(PDO $conn, string $restaurantId): void
{
    require_once __DIR__ . '/countries.php';
    $st = $conn->prepare('SELECT country FROM users WHERE restaurant_id = ? ORDER BY id LIMIT 1');
    $st->execute([$restaurantId]);
    if (!restaurantIsIndia((string)$st->fetchColumn())) {
        throw new Exception('Online renewal is available for restaurants in India only. Please contact RestroGrow support (WhatsApp +91 63775 68749 or restrogrow@gmail.com) to renew.');
    }
}

function pfSaveSettings(PDO $conn, array $in): array
{
    $allowed = ['trial_days', 'plan_price', 'plan_price_yearly', 'auto_renewal', 'renewal_notice_days', 'biz_name', 'biz_address',
        'biz_gstin', 'biz_email', 'biz_phone', 'gst_percent', 'prices_include_gst', 'invoice_prefix', 'biz_upi', 'biz_bank'];
    $nums = ['trial_days' => [1, 365], 'plan_price' => [0, 1000000], 'plan_price_yearly' => [0, 10000000], 'renewal_notice_days' => [1, 60], 'gst_percent' => [0, 40]];
    $st = $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES (?, ?)");
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $in)) continue;
        $v = is_scalar($in[$k]) ? trim((string)$in[$k]) : '';
        if (isset($nums[$k])) {
            if (!is_numeric($v)) throw new Exception("$k must be a number");
            $v = (string)max($nums[$k][0], min($nums[$k][1], (float)$v));
        } elseif (in_array($k, ['auto_renewal', 'prices_include_gst'], true)) {
            $v = $v === '1' || $v === 'true' ? '1' : '0';
        } else {
            $v = mb_substr($v, 0, 500);
        }
        $st->execute([$k, $v]);
    }
    return pfSettings($conn);
}

/** Record a superadmin action (who did what, to which restaurant). Never throws. */
function pfAudit(PDO $conn, string $action, ?string $target = null, $details = null): void
{
    try {
        pfEnsure($conn);
        if (is_array($details)) $details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $conn->prepare("INSERT INTO sa_audit_log (admin_id, admin_name, action, target, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$_SESSION['superadmin_id'] ?? null, $_SESSION['superadmin_username'] ?? null, mb_substr($action, 0, 60),
                $target !== null ? mb_substr($target, 0, 160) : null, $details !== null ? mb_substr((string)$details, 0, 4000) : null,
                $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        error_log('[platform] audit failed: ' . $e->getMessage());
    }
}

/** When a restaurant's plan / trial ends: renewal date, else trial end, else 30 days after sign-up. */
function pfExpirySql(string $alias = 'u'): string
{
    return "COALESCE($alias.renewal_date, $alias.trial_end_date, DATE_ADD(DATE($alias.created_at), INTERVAL 30 DAY))";
}

/** Invoice number for a subscription payment, e.g. RG/2026-27/00012 (Indian financial year). */
function pfInvoiceNo(array $set, array $pay): string
{
    $t = strtotime($pay['created_at'] ?? 'now');
    $y = (int)date('Y', $t);
    $fy = (int)date('n', $t) >= 4 ? $y . '-' . substr((string)($y + 1), 2) : ($y - 1) . '-' . substr((string)$y, 2);
    $prefix = preg_replace('/[^A-Za-z0-9-]/', '', $set['invoice_prefix'] ?? 'RG') ?: 'RG';
    return $prefix . '/' . $fy . '/' . str_pad((string)(int)$pay['id'], 5, '0', STR_PAD_LEFT);
}

/** Suggested number for a hand-made invoice, e.g. RG/2026-27/00042 (Indian financial year, next after paid ones). */
function pfNextManualInvoiceNo(PDO $conn, array $set): string
{
    $seq = (int)$conn->query("SELECT COALESCE(MAX(id), 0) + 1 FROM subscription_payments")->fetchColumn();
    $t = time();
    $y = (int)date('Y', $t);
    $fy = (int)date('n', $t) >= 4 ? $y . '-' . substr((string)($y + 1), 2) : ($y - 1) . '-' . substr((string)$y, 2);
    $prefix = preg_replace('/[^A-Za-z0-9-]/', '', $set['invoice_prefix'] ?? 'RG') ?: 'RG';
    return $prefix . '/' . $fy . '/' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
}

/** GST split of an amount according to the settings (inclusive or on top). */
function pfGst(array $set, float $amount): array
{
    $rate = (float)($set['gst_percent'] ?? 18);
    if (($set['prices_include_gst'] ?? '1') === '1') {
        $base = $rate > 0 ? round($amount * 100 / (100 + $rate), 2) : $amount;
        $tax = round($amount - $base, 2);
        $total = $amount;
    } else {
        $base = $amount;
        $tax = round($amount * $rate / 100, 2);
        $total = round($amount + $tax, 2);
    }
    return ['rate' => $rate, 'base' => $base, 'tax' => $tax, 'cgst' => round($tax / 2, 2), 'sgst' => round($tax - round($tax / 2, 2), 2), 'total' => $total];
}

/** Active announcements for one restaurant (not dismissed by it). */
function pfAnnouncementsFor(PDO $conn, string $rid): array
{
    pfEnsure($conn);
    $st = $conn->prepare("SELECT a.id, a.title, a.body, a.level, a.dismissible FROM sa_announcements a
        LEFT JOIN sa_announcement_dismiss d ON d.announcement_id = a.id AND d.restaurant_id = ?
        WHERE a.is_active = 1 AND d.announcement_id IS NULL
          AND (a.starts_at IS NULL OR a.starts_at <= NOW()) AND (a.ends_at IS NULL OR a.ends_at >= NOW())
          AND (a.target_all = 1 OR FIND_IN_SET(?, a.target_ids))
        ORDER BY a.id DESC LIMIT 5");
    $st->execute([$rid, $rid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
