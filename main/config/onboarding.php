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
    } catch (PDOException $e) { error_log('[onboarding] tables: ' . $e->getMessage()); }
}

/** Free-trial days, one setting for the whole platform. */
function obTrialDays(PDO $conn): int
{
    try {
        require_once __DIR__ . '/platform_engine.php';
        return max(1, min(365, (int)(pfSettings($conn)['trial_days'] ?? 30)));
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
        $conn->prepare('INSERT INTO signup_funnel (token, step, phone, country, restaurant_name, restaurant_id, source, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$token, $step, $phone ?: null, isset($extra['country']) ? mb_substr((string)$extra['country'], 0, 60) : null,
                       isset($extra['restaurant_name']) ? mb_substr((string)$extra['restaurant_name'], 0, 150) : null,
                       $extra['restaurant_id'] ?? null, isset($extra['source']) ? substr((string)$extra['source'], 0, 20) : null,
                       substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
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
    elseif ($what === 'mark' && preg_match('/^[a-z_]{2,30}$/', $mark)) {
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
