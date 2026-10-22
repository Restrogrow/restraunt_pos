<?php
/**
 * Sign-up and first-day onboarding.
 *
 * - Trial length: one number for everything (Super Admin › Settings › trial days, default 30): sign-up,
 *   the sign-up page and the homepage all read obTrialDays().
 * - Sign-up funnel: each step a visitor reaches is logged in signup_funnel (Super Admin › Sign-ups), and a
 *   phone that was verified but never became an account is kept as a lead to follow up.
 * - Onboarding: the "Get ready" guide on the dashboard home and the product tour. What's done is worked out
 *   from the restaurant's data where possible; the rest (tour seen, link shared, guide hidden) is saved in
 *   onboarding_state, so nothing asks twice.
 * Creates its own tables and columns (the live database never runs migrations).
 */

if (defined('ONBOARDING_LOADED')) return;
define('ONBOARDING_LOADED', true);

const OB_TYPES = ['restaurant' => 'Restaurant', 'cafe' => 'Café', 'dhaba' => 'Dhaba', 'cloud_kitchen' => 'Cloud kitchen',
                  'bakery' => 'Bakery / sweets', 'bar' => 'Bar / lounge', 'qsr' => 'Fast food', 'other' => 'Something else'];

function obEnsure(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $have = array_flip($conn->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN));
        if (!isset($have['business_type'])) $conn->exec("ALTER TABLE users ADD COLUMN business_type VARCHAR(30) NULL");
        if (!isset($have['city'])) $conn->exec("ALTER TABLE users ADD COLUMN city VARCHAR(80) NULL");
        if (!isset($have['signup_source'])) $conn->exec("ALTER TABLE users ADD COLUMN signup_source VARCHAR(20) NULL");
    } catch (PDOException $e) { error_log('[onboarding] users columns: ' . $e->getMessage()); }
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS onboarding_state (
            restaurant_id VARCHAR(50) NOT NULL PRIMARY KEY,
            tour_done_at DATETIME NULL,
            tour_skipped_at DATETIME NULL,
            guide_hidden_at DATETIME NULL,
            marks TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->exec("CREATE TABLE IF NOT EXISTS signup_funnel (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(40) NOT NULL,
            step VARCHAR(30) NOT NULL,
            phone VARCHAR(20) NULL,
            country VARCHAR(60) NULL,
            restaurant_name VARCHAR(150) NULL,
            restaurant_id VARCHAR(50) NULL,
            source VARCHAR(20) NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_funnel_step (step, created_at),
            KEY idx_funnel_token (token),
            KEY idx_funnel_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // the website visitor who signed up (config/site_visitors.php): an unfinished sign-up's lead still knows its source
        if (!$conn->query("SHOW COLUMNS FROM signup_funnel LIKE 'vid'")->fetch()) $conn->exec('ALTER TABLE signup_funnel ADD COLUMN vid CHAR(20) NULL');
    } catch (PDOException $e) { error_log('[onboarding] tables: ' . $e->getMessage()); }
}

/** Free-trial days, one setting for the whole platform. */
function obTrialDays(PDO $conn): int
{
    try {
        require_once __DIR__ . '/platform_engine.php';
        return max(1, min(365, (int)(pfSettings($conn)['trial_days'] ?? 7)));
    } catch (Throwable $e) {
        return 30;
    }
}

/** A free username from the restaurant name ("Spice Garden" -> "spicegarden", "spicegarden2", …). */
function obMakeUsername(PDO $conn, string $restaurantName, string $email = ''): string
{
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '', $restaurantName));
    if (strlen($base) < 3) $base = strtolower(preg_replace('/[^a-z0-9]+/i', '', strstr($email, '@', true) ?: '')) ?: 'restaurant';
    $base = substr(str_pad($base, 3, '0'), 0, 20);
    $st = $conn->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    for ($i = 0; $i < 50; $i++) {
        $try = $i === 0 ? $base : $base . ($i + 1);
        $st->execute([$try]);
        if (!(int)$st->fetchColumn()) return $try;
    }
    return $base . random_int(1000, 9999);
}

/** One step of the sign-up funnel (opened, code_sent, verified, details, created). Never throws. */
function obFunnel(PDO $conn, string $token, string $step, array $extra = []): void
{
    try {
        obEnsure($conn);
        $token = substr(preg_replace('/[^a-zA-Z0-9]/', '', $token), 0, 40);
        if ($token === '' || !preg_match('/^[a-z_]{3,30}$/', $step)) return;
        // one row per token and step
        $st = $conn->prepare('SELECT id FROM signup_funnel WHERE token = ? AND step = ? LIMIT 1');
        $st->execute([$token, $step]);
        if ($st->fetchColumn()) return;
        $phone = isset($extra['phone']) ? substr(preg_replace('/\D/', '', (string)$extra['phone']), 0, 20) : null;
        require_once __DIR__ . '/site_visitors.php';
        $conn->prepare('INSERT INTO signup_funnel (token, step, phone, country, restaurant_name, restaurant_id, source, ip, vid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$token, $step, $phone ?: null, isset($extra['country']) ? mb_substr((string)$extra['country'], 0, 60) : null,
                       isset($extra['restaurant_name']) ? mb_substr((string)$extra['restaurant_name'], 0, 150) : null,
                       $extra['restaurant_id'] ?? null, isset($extra['source']) ? substr((string)$extra['source'], 0, 20) : null,
                       substr(geoClientIp(), 0, 45), siteVid() ?: null]);
    } catch (Throwable $e) {
        error_log('[onboarding] funnel: ' . $e->getMessage());
    }
}

function obState(PDO $conn, string $rid): array
{
    obEnsure($conn);
    $st = $conn->prepare('SELECT * FROM onboarding_state WHERE restaurant_id = ?');
    $st->execute([$rid]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $marks = json_decode((string)($r['marks'] ?? ''), true);
    return ['tour_done' => !empty($r['tour_done_at']) || !empty($r['tour_skipped_at']), 'tour_skipped' => !empty($r['tour_skipped_at']),
            'guide_hidden' => !empty($r['guide_hidden_at']), 'marks' => is_array($marks) ? $marks : []];
}

function obSave(PDO $conn, string $rid, string $what, string $mark = ''): void
{
    obEnsure($conn);
    $conn->prepare('INSERT IGNORE INTO onboarding_state (restaurant_id) VALUES (?)')->execute([$rid]);
    if ($what === 'tour_done') $conn->prepare('UPDATE onboarding_state SET tour_done_at = COALESCE(tour_done_at, NOW()) WHERE restaurant_id = ?')->execute([$rid]);
    elseif ($what === 'tour_skipped') $conn->prepare('UPDATE onboarding_state SET tour_skipped_at = COALESCE(tour_skipped_at, NOW()) WHERE restaurant_id = ?')->execute([$rid]);
    elseif ($what === 'tour_again') $conn->prepare('UPDATE onboarding_state SET tour_done_at = NULL, tour_skipped_at = NULL WHERE restaurant_id = ?')->execute([$rid]);
    elseif ($what === 'guide_hidden') $conn->prepare('UPDATE onboarding_state SET guide_hidden_at = NOW() WHERE restaurant_id = ?')->execute([$rid]);
    elseif ($what === 'guide_shown') $conn->prepare('UPDATE onboarding_state SET guide_hidden_at = NULL WHERE restaurant_id = ?')->execute([$rid]);
    elseif ($what === 'mark' && preg_match('/^[a-z0-9_]{2,30}$/', $mark)) {
        $s = obState($conn, $rid);
        $s['marks'][$mark] = date('Y-m-d H:i:s');
        $conn->prepare('UPDATE onboarding_state SET marks = ? WHERE restaurant_id = ?')->execute([json_encode($s['marks']), $rid]);
    }
}

/** How many rows a query finds (0 when the table or column doesn't exist on this database). */
function obCount(PDO $conn, string $sql, array $args): int
{
    try {
        $st = $conn->prepare($sql);
        $st->execute($args);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * The "Get ready" guide: each step with done true/false, worked out from the restaurant's own data
 * (dishes, tables, staff, orders, logo, opening hours) or from what they ticked (link shared).
 */
function obGuide(PDO $conn, string $rid): array
{
    $s = obState($conn, $rid);
    $u = $conn->prepare('SELECT restaurant_name, business_type, restaurant_logo, opening_hours, enable_dinein, phone FROM users WHERE restaurant_id = ? LIMIT 1');
    try { $u->execute([$rid]); $user = $u->fetch(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { $u = $conn->prepare('SELECT restaurant_name FROM users WHERE restaurant_id = ? LIMIT 1'); $u->execute([$rid]); $user = $u->fetch(PDO::FETCH_ASSOC) ?: []; }
    $type = (string)($user['business_type'] ?? '');
    $dishes = obCount($conn, 'SELECT COUNT(*) FROM menu_items WHERE restaurant_id = ?', [$rid]);
    $tables = obCount($conn, 'SELECT COUNT(*) FROM tables WHERE restaurant_id = ?', [$rid]);
    $orders = obCount($conn, 'SELECT COUNT(*) FROM orders WHERE restaurant_id = ?', [$rid]);
    $staff = obCount($conn, 'SELECT COUNT(*) FROM staff WHERE restaurant_id = ?', [$rid]);
    $hasLogo = !empty($user['restaurant_logo']);
    $hasHours = !empty($user['opening_hours']);
    $noTables = in_array($type, ['cloud_kitchen'], true);

    $steps = [
        ['key' => 'menu', 'title' => 'Add your menu', 'sub' => $dishes ? $dishes . ' dish' . ($dishes === 1 ? '' : 'es') . ' added' : 'Type your dishes, or send us a photo of your menu', 'done' => $dishes >= 3, 'page' => 'menuItemsPage', 'icon' => 'restaurant_menu'],
        $noTables ? null : ['key' => 'tables', 'title' => 'Set up your tables', 'sub' => $tables ? $tables . ' table' . ($tables === 1 ? '' : 's') : 'One tap makes T1 to T10, change them any time', 'done' => $tables > 0, 'page' => 'tablesPage', 'icon' => 'table_restaurant'],
        ['key' => 'look', 'title' => 'Add your logo and opening hours', 'sub' => $hasLogo && $hasHours ? 'Done' : ($hasLogo ? 'Opening hours still missing' : 'Shown on your bills and website'), 'done' => $hasLogo && $hasHours, 'page' => 'settingsPage', 'icon' => 'storefront'],
        ['key' => 'share', 'title' => 'Share your website', 'sub' => 'Your customers order from it, no app needed', 'done' => isset($s['marks']['share']), 'page' => '', 'icon' => 'share'],
        ['key' => 'staff', 'title' => 'Add your team', 'sub' => $staff ? $staff . ' staff member' . ($staff === 1 ? '' : 's') : 'Waiters, chefs and cashiers get their own login', 'done' => $staff > 0 || isset($s['marks']['staff']), 'page' => 'staffPage', 'icon' => 'group_add', 'optional' => true],
        ['key' => 'order', 'title' => 'Take your first order', 'sub' => $orders ? 'You are live' : 'Bill a test order on the POS', 'done' => $orders > 0, 'page' => 'posPage', 'icon' => 'point_of_sale'],
    ];
    $steps = array_values(array_filter($steps));
    $done = count(array_filter($steps, fn($x) => $x['done']));
    return ['steps' => $steps, 'done' => $done, 'total' => count($steps), 'complete' => $done === count($steps),
            'state' => $s, 'type' => $type, 'type_label' => OB_TYPES[$type] ?? ''];
}

/* ───────── follow-up: welcome on WhatsApp, unfinished sign-ups, setup reminders ───────── */

/**
 * A WhatsApp from RestroGrow's own number to a restaurant owner. Business-started messages need an
 * approved template, so this only sends when the template named in .env exists ($templateEnv, e.g.
 * REPEATGROW_WELCOME_TEMPLATE); otherwise it does nothing and the email covers it. Never throws.
 */
function obWhatsappOwner(PDO $conn, string $rid, string $phone, string $templateEnv, array $params, string $text): bool
{
    $tpl = trim((string)env($templateEnv, ''));
    if ($tpl === '' || preg_replace('/\D/', '', $phone) === '') return false;
    try {
        require_once __DIR__ . '/whatsapp_engine.php';
        $s = ['mode' => 'platform', 'api_key' => '', 'is_demo' => false, 'restaurant_id' => $rid];
        [$ok, , $err] = waSend($s, $phone, $tpl, (string)env($templateEnv . '_LANG', 'en'), $params, $text);
        if (!$ok) error_log("[onboarding] WhatsApp $templateEnv to $rid: $err");
        return (bool)$ok;
    } catch (Throwable $e) {
        error_log('[onboarding] WhatsApp: ' . $e->getMessage());
        return false;
    }
}

/** Right after sign-up: the WhatsApp welcome ({{1}} first name, {{2}} restaurant, {{3}} dashboard link, {{4}} website link). */
function obWelcomeWhatsapp(PDO $conn, string $rid): void
{
    $u = $conn->prepare('SELECT restaurant_name, owner_name, username, phone FROM users WHERE restaurant_id = ? LIMIT 1');
    $u->execute([$rid]);
    $r = $u->fetch(PDO::FETCH_ASSOC);
    if (!$r) return;
    require_once __DIR__ . '/mail_engine.php';
    $base = mailAppUrl();
    $first = trim(explode(' ', (string)($r['owner_name'] ?: $r['username']))[0]) ?: 'there';
    $site = $base . '/' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$r['restaurant_name'])), '-');
    obWhatsappOwner($conn, $rid, (string)$r['phone'], 'REPEATGROW_WELCOME_TEMPLATE',
        [$first, (string)$r['restaurant_name'], $base . '/main/admin/login.php', $site],
        "Welcome to RestroGrow, $first! {$r['restaurant_name']} is ready. Dashboard: $base/main/admin/login.php · Your website: $site");
}

/** Run hourly (mailSweep): unfinished sign-ups become leads, and setup reminders go out on day 1, 3 and 7. */
function obSweep(PDO $conn): array
{
    obEnsure($conn);
    require_once __DIR__ . '/mail_engine.php';
    require_once __DIR__ . '/platform_engine.php';
    pfEnsure($conn);
    $out = ['leads' => 0, 'nudges' => 0];

    // 1) a phone that got a code (or was verified) 45+ minutes ago, but no account: a lead to call
    $st = $conn->query("SELECT f.token, f.phone, MAX(f.created_at) at, MAX(CASE WHEN f.step = 'verified' THEN 1 ELSE 0 END) verified, MAX(f.vid) vid, MAX(f.ip) ip,
            (SELECT d.restaurant_name FROM signup_funnel d WHERE d.token = f.token AND d.step = 'details' LIMIT 1) rname
        FROM signup_funnel f
        WHERE f.step IN ('code_sent', 'verified') AND f.phone IS NOT NULL AND f.created_at BETWEEN NOW() - INTERVAL 7 DAY AND NOW() - INTERVAL 45 MINUTE
          AND NOT EXISTS (SELECT 1 FROM signup_funnel c WHERE c.token = f.token AND c.step IN ('created', 'lead'))
        GROUP BY f.token, f.phone LIMIT 50");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $last = substr((string)$f['phone'], -10);
        $has = $conn->prepare('SELECT COUNT(*) FROM users WHERE phone LIKE ?');
        $has->execute(['%' . $last]);
        $hasAccount = (int)$has->fetchColumn();
        $lead = $conn->prepare('SELECT COUNT(*) FROM contact_queries WHERE phone LIKE ? AND created_at > NOW() - INTERVAL 30 DAY');
        $lead->execute(['%' . $last]);
        $hasLead = (int)$lead->fetchColumn();
        if ($hasAccount || $hasLead) { obFunnel($conn, (string)$f['token'], 'lead', ['phone' => $f['phone']]); continue; }
        $how = $f['rname'] ? 'reached the last step (restaurant "' . $f['rname'] . '")' : ((int)$f['verified'] ? 'verified the phone' : 'asked for the WhatsApp code');
        $msg = 'Started signing up on ' . date('j M, H:i', strtotime((string)$f['at'])) . ' and ' . $how . ' but did not finish. Call or WhatsApp them to help.';
        // email '' (not NULL): some databases have contact_queries.email NOT NULL
        $conn->prepare("INSERT INTO contact_queries (name, email, phone, message, status, stage, restaurant_name_hint) VALUES (?, '', ?, ?, 'new', 'new', ?)")
             ->execute([$f['rname'] ?: 'Unfinished sign-up', (string)$f['phone'], $msg, $f['rname'] ?: null]);
        // where they came from: the visitor id and IP saved while they were signing up (this runs from a cron)
        require_once __DIR__ . '/site_visitors.php';
        siteAttribute($conn, 'contact', (string)$conn->lastInsertId(), (string)($f['vid'] ?? ''), (string)($f['ip'] ?? ''));
        obFunnel($conn, (string)$f['token'], 'lead', ['phone' => $f['phone']]);   // handled: never looked at again
        mailNewLead($conn, 'unfinished sign-up', ['name' => $f['rname'] ?: 'Unfinished sign-up', 'phone' => $f['phone'], 'message' => $msg]);
        $out['leads']++;
    }

    // 2) setup reminders: day 1, 3 and 7 after sign-up, while the guide isn't done (email always, WhatsApp with a template)
    $st = $conn->query('SELECT restaurant_id, restaurant_name, owner_name, username, email, phone, DATEDIFF(CURDATE(), DATE(created_at)) d FROM users
        WHERE is_active = 1 AND DATEDIFF(CURDATE(), DATE(created_at)) IN (1, 3, 7) AND created_at > NOW() - INTERVAL 10 DAY');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $rid = (string)$u['restaurant_id'];
        $g = obGuide($conn, $rid);
        if ($g['complete'] || $g['state']['guide_hidden']) continue;
        $d = (int)$u['d'];
        if (!empty($g['state']['marks']['nudge_' . $d])) continue;
        obSave($conn, $rid, 'mark', 'nudge_' . $d);
        $left = array_values(array_filter($g['steps'], fn($s) => !$s['done'] && empty($s['optional'])));
        $first = trim(explode(' ', (string)($u['owner_name'] ?: $u['username']))[0]) ?: 'there';
        if (!empty($u['email'])) { mailSetupNudge($conn, $u, $left, $d, $g); $out['nudges']++; }
        if ($left) obWhatsappOwner($conn, $rid, (string)$u['phone'], 'REPEATGROW_SETUP_TEMPLATE',
            [$first, (string)$u['restaurant_name'], $left[0]['title'], mailAppUrl() . '/main/admin/login.php'],
            "Hi $first, next step for {$u['restaurant_name']}: {$left[0]['title']}. " . mailAppUrl() . '/main/admin/login.php');
    }
    return $out;
}

/** The setup reminder email: what's done, what's left, one button. */
function mailSetupNudge(PDO $conn, array $u, array $left, int $day, array $g): void
{
    $b = mailPlatformBrand();
    $first = trim(explode(' ', (string)($u['owner_name'] ?: $u['username']))[0]) ?: 'there';
    $titles = [1 => 'Your next step on RestroGrow', 3 => 'You are ' . $g['done'] . ' of ' . $g['total'] . ' steps in', 7 => 'Need a hand setting up?'];
    $intro = $day === 7
        ? 'Hi ' . mailE($first) . ', a week in and <b>' . mailE((string)$u['restaurant_name']) . '</b> still has a few steps left. Reply to this email or WhatsApp us and we will set it up with you, free.'
        : 'Hi ' . mailE($first) . ', here is what is left to get <b>' . mailE((string)$u['restaurant_name']) . '</b> taking orders. Each takes a couple of minutes.';
    $list = '<ol style="margin:14px 0 0;padding-left:20px;font-size:14.5px;line-height:1.9;color:#4b4036">'
        . implode('', array_map(fn($s) => '<li><b>' . mailE($s['title']) . '</b> · ' . mailE($s['sub']) . '</li>', $left)) . '</ol>';
    if ($day <= 3) $list .= '<p style="margin:14px 0 0;font-size:14px;color:#6b5e52">Menu on paper? Send us a photo on WhatsApp (+91 63775 68749) and we will type it in for you.</p>';
    mailQueue($conn, 'setup_nudge', (string)$u['email'], $titles[$day] ?? 'Your next step on RestroGrow',
        mailLayout($b, ['title' => $titles[$day] ?? 'Your next step', 'preheader' => $left ? 'Next: ' . $left[0]['title'] : 'Almost there',
            'intro' => '<p style="margin:0">' . $intro . '</p>' . $list,
            'button' => ['Continue setting up', mailAppUrl() . '/main/admin/login.php'], 'small' => 'Don\'t want these reminders? Hide the guide on your dashboard and they stop.']),
        ['restaurant_id' => (string)$u['restaurant_id'], 'brand' => $b, 'dedupe' => 'nudge:' . $u['restaurant_id'] . ':' . $day]);
}
