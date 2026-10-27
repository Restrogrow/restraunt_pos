<?php
/**
 * Private sign-up links (Super Admin › Sign-up links): restrogrow.com/join/<code>.
 *
 * The same sign-up page (admin/login.php), but for a client RestroGrow is bringing on itself:
 * - no WhatsApp code (RestroGrow already knows the person),
 * - whatever was typed in Super Admin is already filled in (the sign-up page's questions: country, phone,
 *   restaurant, type of place, city, email; the client still sets the password),
 * - its own free-trial length (7 days unless Super Admin picks another); an old link without one: obTrialDays().
 * A link works a set number of times (1 by default) until its expiry date, and can be switched off.
 * After the account is made, the link's sign-up asks for the menu (photos / PDF, api/signup_menu.php). The
 * files are kept privately in uploads/signup_menus (no web access) and listed in Super Admin › Sign-up links.
 * Creates its own tables (the live database never runs migrations).
 */

if (defined('SIGNUP_INVITES_LOADED')) return;
define('SIGNUP_INVITES_LOADED', true);

function inviteEnsure(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->exec("CREATE TABLE IF NOT EXISTS signup_invites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(16) NOT NULL UNIQUE,
        note VARCHAR(120) NULL,
        restaurant_name VARCHAR(120) NULL,
        phone VARCHAR(20) NULL,
        email VARCHAR(190) NULL,
        country VARCHAR(60) NULL,
        business_type VARCHAR(30) NULL,
        city VARCHAR(80) NULL,
        trial_days INT NULL,
        max_uses INT NOT NULL DEFAULT 1,
        uses INT NOT NULL DEFAULT 0,
        expires_at DATETIME NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        restaurant_ids VARCHAR(255) NULL,
        created_by VARCHAR(60) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        used_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $have = array_flip($conn->query('SHOW COLUMNS FROM signup_invites')->fetchAll(PDO::FETCH_COLUMN));
    if (!isset($have['business_type'])) $conn->exec('ALTER TABLE signup_invites ADD COLUMN business_type VARCHAR(30) NULL AFTER country');
    if (!isset($have['city'])) $conn->exec('ALTER TABLE signup_invites ADD COLUMN city VARCHAR(80) NULL AFTER business_type');
    $conn->exec("CREATE TABLE IF NOT EXISTS signup_menu_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(20) NOT NULL,
        file VARCHAR(80) NOT NULL,
        name VARCHAR(190) NOT NULL,
        mime VARCHAR(40) NOT NULL,
        size INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (restaurant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

const INVITE_MENU_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
const INVITE_MENU_MAX_FILES = 10;   // per restaurant

/** The private folder for menu files (created with a "deny all" .htaccess). */
function inviteMenuDir(): string
{
    $dir = __DIR__ . '/../uploads/signup_menus';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    return $dir;
}

/**
 * Keeps the menu files a new owner uploaded ($_FILES['menu'] with several files). Photos up to 8 MB,
 * PDFs up to 20 MB, at most INVITE_MENU_MAX_FILES in all. Returns how many were saved.
 */
function inviteMenuSave(PDO $conn, string $restaurantId, array $files): int
{
    inviteEnsure($conn);
    $names = (array)($files['name'] ?? []); $tmps = (array)($files['tmp_name'] ?? []); $errs = (array)($files['error'] ?? []);
    $have = $conn->prepare('SELECT COUNT(*) FROM signup_menu_files WHERE restaurant_id = ?');
    $have->execute([$restaurantId]);
    if ((int)$have->fetchColumn() + count($names) > INVITE_MENU_MAX_FILES) {
        throw new InvalidArgumentException('Please send at most ' . INVITE_MENU_MAX_FILES . ' files.');
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $ok = [];
    foreach ($names as $i => $name) {
        $name = mb_substr(basename((string)$name), 0, 190);
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmps[$i])) {
            throw new InvalidArgumentException("\"$name\" could not be uploaded. Please try again.");
        }
        $mime = finfo_file($finfo, $tmps[$i]);
        if (!isset(INVITE_MENU_TYPES[$mime])) throw new InvalidArgumentException("\"$name\" must be a photo (JPG, PNG, WEBP) or a PDF.");
        $size = filesize($tmps[$i]);
        if ($size > ($mime === 'application/pdf' ? 20 : 8) * 1024 * 1024) {
            throw new InvalidArgumentException("\"$name\" is too big (photos up to 8 MB, PDFs up to 20 MB).");
        }
        $ok[] = [$tmps[$i], $name, $mime, $size];
    }
    finfo_close($finfo);
    $dir = inviteMenuDir();
    $ins = $conn->prepare('INSERT INTO signup_menu_files (restaurant_id, file, name, mime, size) VALUES (?, ?, ?, ?, ?)');
    foreach ($ok as [$tmp, $name, $mime, $size]) {
        $file = preg_replace('/[^A-Za-z0-9]/', '', $restaurantId) . '_' . bin2hex(random_bytes(8)) . '.' . INVITE_MENU_TYPES[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $file)) throw new RuntimeException('Could not save the file');
        $ins->execute([$restaurantId, $file, $name, $mime, $size]);
    }
    return count($ok);
}

/** Menu files for these restaurants: [restaurant_id => [rows…]]. */
function inviteMenuFiles(PDO $conn, array $restaurantIds): array
{
    if (!$restaurantIds) return [];
    inviteEnsure($conn);
    $st = $conn->prepare('SELECT * FROM signup_menu_files WHERE restaurant_id IN (' . implode(',', array_fill(0, count($restaurantIds), '?')) . ') ORDER BY id');
    $st->execute(array_values($restaurantIds));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['restaurant_id']][] = $r;
    return $out;
}

/** A new link. Returns its code. */
function inviteCreate(PDO $conn, array $d): string
{
    inviteEnsure($conn);
    $abc = 'abcdefghjkmnpqrstuvwxyz23456789';
    for ($try = 0; $try < 5; $try++) {
        $code = '';
        for ($i = 0; $i < 10; $i++) $code .= $abc[random_int(0, strlen($abc) - 1)];
        try {
            $conn->prepare('INSERT INTO signup_invites (code, note, restaurant_name, phone, email, country, business_type, city, trial_days, max_uses, expires_at, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$code, $d['note'] ?: null, $d['restaurant_name'] ?: null, $d['phone'] ?: null, $d['email'] ?: null,
                           $d['country'] ?: null, $d['business_type'] ?: null, $d['city'] ?: null, $d['trial_days'] ?? null, max(1, (int)$d['max_uses']), $d['expires_at'] ?: null, $d['created_by'] ?? null]);
            return $code;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;   // same code by chance: try another
        }
    }
    throw new RuntimeException('Could not make a link. Please try again.');
}

/** Why a link can't be used now ('' = it can). */
function inviteProblem(array $r): string
{
    if (!(int)$r['active']) return 'This sign-up link was switched off.';
    if ((int)$r['uses'] >= (int)$r['max_uses']) return 'This sign-up link has already been used.';
    if ($r['expires_at'] && strtotime($r['expires_at']) < time()) return 'This sign-up link has expired.';
    return '';
}

/** The link for a code, or null when there is no such code. */
function inviteFind(PDO $conn, string $code): ?array
{
    if (!preg_match('/^[a-z0-9]{6,16}$/', $code)) return null;
    inviteEnsure($conn);
    $st = $conn->prepare('SELECT * FROM signup_invites WHERE code = ?');
    $st->execute([$code]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Takes one use of the link (safe when two people sign up at once). False when it can't be used. */
function inviteTake(PDO $conn, int $id): bool
{
    $st = $conn->prepare('UPDATE signup_invites SET uses = uses + 1, used_at = NOW()
                          WHERE id = ? AND active = 1 AND uses < max_uses AND (expires_at IS NULL OR expires_at > NOW())');
    $st->execute([$id]);
    return $st->rowCount() === 1;
}

/** Gives the use back (the account could not be made after all). */
function inviteGiveBack(PDO $conn, int $id): void
{
    $conn->prepare('UPDATE signup_invites SET uses = GREATEST(uses - 1, 0) WHERE id = ?')->execute([$id]);
}

/** Notes which restaurant came from the link. */
function inviteRecord(PDO $conn, int $id, string $restaurantId): void
{
    $conn->prepare("UPDATE signup_invites SET restaurant_ids = LEFT(CONCAT_WS(',', NULLIF(restaurant_ids, ''), ?), 255) WHERE id = ?")
        ->execute([$restaurantId, $id]);
}

/** restrogrow.com/join/<code> (on this computer: the sign-up page itself). */
function inviteUrl(string $code): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'restrogrow.com');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host)) {
        return $scheme . '://' . $host . '/menuwebsite/main/admin/login.php?tab=signup&invite=' . $code;
    }
    return 'https://restrogrow.com/join/' . $code;
}
