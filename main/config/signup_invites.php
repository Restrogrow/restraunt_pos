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
 * Creates its own table (the live database never runs migrations).
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
