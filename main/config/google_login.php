<?php
/**
 * "Continue with Google" for owners and staff (dashboard), the Partner app and restaurant
 * websites (customers), including restaurants on their own domain.
 *
 * Every flow goes through ONE redirect address on restrogrow.com, so only that address is
 * listed on the Google OAuth client (not every restaurant domain):
 *
 *   start   main/api/google_login.php?for=owner|customer|app      -> Google
 *   back    main/api/google_login_callback.php                    <- Google
 *   finish  owner:    main/admin/google_finish.php?t=…              (restrogrow.com)
 *           customer: <restaurant's own host>/main/website/google_finish.php?t=…
 *           app:      restrogrow://google-auth?t=…  -> admin/auth.php action=googleLogin
 *
 * The callback turns Google's answer into a single-use pass (google_login_tokens, 3 minutes)
 * that the finish step trades for a session on the right domain. The state sent to Google is
 * signed (HMAC) and expires after 15 minutes; Google's ID token is checked for our client ID,
 * issuer, a verified email and the nonce we sent.
 *
 * Keys: GOOGLE_LOGIN_CLIENT_ID / _SECRET, or the Google Business / Search Console client
 * already in .env (one OAuth client can serve all of them).
 */

require_once __DIR__ . '/env_loader.php';

function googleLoginEnv(string $k): string { return function_exists('env') ? trim((string)env($k, '')) : ''; }

function googleLoginClient(): array
{
    foreach (['GOOGLE_LOGIN', 'GOOGLE_GBP', 'GOOGLE_SEO'] as $p) {
        $id = googleLoginEnv($p . '_CLIENT_ID');
        $secret = googleLoginEnv($p . '_CLIENT_SECRET');
        if ($id !== '' && $secret !== '') return ['id' => $id, 'secret' => $secret];
    }
    return ['id' => '', 'secret' => ''];
}

function googleLoginReady(): bool { return googleLoginClient()['id'] !== ''; }

/** Is this request on a local computer (XAMPP)? */
function googleLoginIsLocal(): bool
{
    return (bool)preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $_SERVER['HTTP_HOST'] ?? '');
}

/** restrogrow.com (or the local site) — where the callback and owner pages live. */
function googleLoginMainBase(): string
{
    if (googleLoginIsLocal()) {
        $path = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $root = ($p = strpos($path, '/main/')) !== false ? substr($path, 0, $p) : '';
        return 'http://' . $_SERVER['HTTP_HOST'] . $root;
    }
    $app = rtrim(googleLoginEnv('APP_URL'), '/');
    return $app !== '' && preg_match('#^https://#', $app) ? $app : 'https://restrogrow.com';
}

function googleLoginCallbackUrl(): string { return googleLoginMainBase() . '/main/api/google_login_callback.php'; }

function googleLoginB64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function googleLoginUnB64(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/')); }
function googleLoginSignKey(): string { return hash('sha256', googleLoginClient()['secret'] . '|rg-google-login-v1', true); }

/** URL that sends the browser to Google. $state: for, rid, host, ret, app (whatever the finish step needs). */
function googleLoginAuthUrl(array $state): string
{
    $state['n'] = bin2hex(random_bytes(12));   // also sent as the OpenID nonce
    $state['x'] = time() + 900;
    $body = googleLoginB64(json_encode($state));
    $signed = $body . '.' . googleLoginB64(hash_hmac('sha256', $body, googleLoginSignKey(), true));
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => googleLoginClient()['id'],
        'redirect_uri' => googleLoginCallbackUrl(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $signed,
        'nonce' => $state['n'],
        'prompt' => 'select_account',
    ]);
}

/** The state Google hands back, if it's ours and still fresh. */
function googleLoginReadState(string $signed): ?array
{
    $parts = explode('.', $signed);
    if (count($parts) !== 2) return null;
    $expect = googleLoginB64(hash_hmac('sha256', $parts[0], googleLoginSignKey(), true));
    if (!hash_equals($expect, $parts[1])) return null;
    $state = json_decode(googleLoginUnB64($parts[0]), true);
    if (!is_array($state) || (int)($state['x'] ?? 0) < time()) return null;
    return $state;
}

function googleLoginHttp(string $url, ?array $post = null): array
{
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6];
    if ($post !== null) $opts += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)];
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('Could not reach Google: ' . $err);
    return [$code, json_decode((string)$body, true) ?: []];
}

/** Trades Google's code for the person's verified profile: sub, email, name, picture. */
function googleLoginProfile(string $code, string $nonce): array
{
    $c = googleLoginClient();
    [$status, $tok] = googleLoginHttp('https://oauth2.googleapis.com/token', [
        'code' => $code, 'client_id' => $c['id'], 'client_secret' => $c['secret'],
        'redirect_uri' => googleLoginCallbackUrl(), 'grant_type' => 'authorization_code',
    ]);
    if ($status !== 200 || empty($tok['id_token'])) {
        throw new RuntimeException('Google sign-in failed (' . ($tok['error_description'] ?? $tok['error'] ?? "HTTP $status") . ')');
    }
    // Google checks the token's signature for us here; we check it's meant for us and for this sign-in
    [$status, $info] = googleLoginHttp('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($tok['id_token']));
    if ($status !== 200) throw new RuntimeException('Google could not confirm the sign-in');
    if (($info['aud'] ?? '') !== $c['id']) throw new RuntimeException('Google sign-in was for a different app');
    if (!in_array($info['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) throw new RuntimeException('Unexpected sign-in issuer');
    if ((int)($info['exp'] ?? 0) < time()) throw new RuntimeException('Google sign-in expired, please try again');
    if (!hash_equals($nonce, (string)($info['nonce'] ?? ''))) throw new RuntimeException('Google sign-in could not be matched, please try again');
    $email = strtolower(trim((string)($info['email'] ?? '')));
    if ($email === '' || !in_array((string)($info['email_verified'] ?? ''), ['true', '1'], true)) {
        throw new RuntimeException('Your Google account has no verified email');
    }
    return [
        'sub' => (string)$info['sub'],
        'email' => $email,
        'name' => trim((string)($info['name'] ?? '')) ?: strstr($email, '@', true),
        'picture' => (string)($info['picture'] ?? ''),
    ];
}

function googleLoginEnsureSchema(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS google_login_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL UNIQUE,
        target VARCHAR(12) NOT NULL,
        restaurant_id VARCHAR(20) DEFAULT NULL,
        payload TEXT NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** A single-use pass (3 minutes) carrying the verified Google profile to the finish step. */
function googleLoginIssueToken(PDO $conn, string $target, ?string $rid, array $payload): string
{
    googleLoginEnsureSchema($conn);
    $conn->exec("DELETE FROM google_login_tokens WHERE expires_at < NOW() - INTERVAL 1 DAY");
    $token = bin2hex(random_bytes(24));
    $conn->prepare('INSERT INTO google_login_tokens (token_hash, target, restaurant_id, payload, expires_at) VALUES (?, ?, ?, ?, NOW() + INTERVAL 3 MINUTE)')
        ->execute([hash('sha256', $token), $target, $rid, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    return $token;
}

/** Uses up a pass. Returns its payload, or null if unknown, expired, already used or for another target. */
function googleLoginConsumeToken(PDO $conn, string $token, string $target): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    googleLoginEnsureSchema($conn);
    $hash = hash('sha256', $token);
    $use = $conn->prepare('UPDATE google_login_tokens SET used_at = NOW() WHERE token_hash = ? AND target = ? AND used_at IS NULL AND expires_at > NOW()');
    $use->execute([$hash, $target]);
    if ($use->rowCount() !== 1) return null;
    $st = $conn->prepare('SELECT restaurant_id, payload FROM google_login_tokens WHERE token_hash = ?');
    $st->execute([$hash]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $p = json_decode($row['payload'], true) ?: [];
    $p['restaurant_id'] = $row['restaurant_id'];
    return $p;
}

/**
 * The owner or staff account for a Google email: ['type' => 'admin', 'row' => users row] or
 * ['type' => 'staff', 'row' => staff row], or null. Multi-branch owners have no email on file.
 */
function googleLoginFindAccount(PDO $conn, string $email): ?array
{
    $st = $conn->prepare('SELECT * FROM users WHERE LOWER(email) = ? AND is_active = 1 ORDER BY id LIMIT 2');
    $st->execute([$email]);
    $users = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($users) === 1) return ['type' => 'admin', 'row' => $users[0]];
    if (count($users) > 1) throw new RuntimeException('More than one restaurant uses ' . $email . '. Please log in with your username.');
    $st = $conn->prepare('SELECT s.*, u.restaurant_name FROM staff s LEFT JOIN users u ON u.restaurant_id = s.restaurant_id WHERE LOWER(s.email) = ? AND s.is_active = 1 ORDER BY s.id LIMIT 2');
    $st->execute([$email]);
    $staff = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($staff) === 1) return ['type' => 'staff', 'row' => $staff[0]];
    if (count($staff) > 1) throw new RuntimeException('More than one staff account uses ' . $email . '. Please log in with your email and password.');
    return null;
}

/**
 * Logs the owner or staff member in on this session — the same session values, logs and
 * dashboard as the password login in admin/auth.php (handleLogin). Google has already verified
 * the person, so the new-device WhatsApp code is not asked. Returns [redirect, data].
 */
function googleLoginStartSession(PDO $conn, array $account): array
{
    require_once __DIR__ . '/session_config.php';
    require_once __DIR__ . '/activity_log.php';
    if (is_file(__DIR__ . '/login_log_helpers.php')) require_once __DIR__ . '/login_log_helpers.php';
    if (session_status() !== PHP_SESSION_ACTIVE) startSecureSession();
    $r = $account['row'];
    $log = function (string $rid, string $name, string $type) use ($conn) {
        if (!function_exists('loginLogWrite')) return;
        try {
            loginLogWrite($conn, ['restaurant_id' => $rid, 'username' => $name, 'user_type' => $type, 'actor_name' => $name,
                                  'outcome' => 'success', 'is_new_device' => function_exists('loginLogIsNewDevice') ? loginLogIsNewDevice($conn, $rid) : false]);
        } catch (Throwable $e) {
            error_log('google login log: ' . $e->getMessage());
        }
    };

    if ($account['type'] === 'admin') {
        resetLoginIdentity();
        $_SESSION['user_id'] = $r['id'];
        $_SESSION['username'] = $r['username'];
        $_SESSION['restaurant_id'] = $r['restaurant_id'];
        $_SESSION['restaurant_name'] = $r['restaurant_name'];
        $_SESSION['user_type'] = 'admin';
        $_SESSION['role'] = 'Admin';
        regenerateSessionAfterLogin();
        $log($r['restaurant_id'], $r['username'], 'admin');
        logActivity($conn, $r['restaurant_id'], 'login.owner', 'Logged in with Google', [], null, null, ['name' => (string)$r['username'], 'role' => 'Owner', 'staff_id' => null]);
        return ['../views/dashboard.php', ['username' => $r['username'], 'restaurant_id' => $r['restaurant_id'], 'restaurant_name' => $r['restaurant_name'], 'user_type' => 'admin', 'role' => 'Admin']];
    }

    resetLoginIdentity();
    $_SESSION['staff_id'] = $r['id'];
    $_SESSION['username'] = $r['member_name'];
    $_SESSION['email'] = $r['email'];
    $_SESSION['restaurant_id'] = $r['restaurant_id'];
    $_SESSION['restaurant_name'] = $r['restaurant_name'] ?? 'Restaurant';
    $_SESSION['user_type'] = 'staff';
    $_SESSION['role'] = $r['role'];
    regenerateSessionAfterLogin();
    $log($r['restaurant_id'], $r['member_name'], 'staff');
    logActivity($conn, $r['restaurant_id'], 'login.staff', 'Logged in with Google', [], 'staff', $r['id'], ['name' => (string)$r['member_name'], 'role' => (string)$r['role'], 'staff_id' => (int)$r['id']]);
    $redirect = ['Waiter' => '../views/waiter_dashboard.php', 'Chef' => '../views/chef_dashboard.php', 'Manager' => '../views/manager_dashboard.php', 'Delivery' => '../views/rider_dashboard.php', 'Cashier' => '../views/manager_dashboard.php'][$r['role']] ?? '../views/dashboard.php';
    return [$redirect, ['username' => $r['member_name'], 'restaurant_id' => $r['restaurant_id'], 'restaurant_name' => $r['restaurant_name'] ?? 'Restaurant', 'user_type' => 'staff', 'role' => $r['role']]];
}
