<?php
/**
 * Start "Continue with Google" (see config/google_login.php).
 *   ?for=owner                                   dashboard login (owners and staff)
 *   ?for=superadmin                              Super Admin login (allowed team emails only)
 *   ?for=customer&rid=RES…&host=…&ret=/path      a restaurant website (its own domain too)
 *   ?for=app&app=restrogrow://google-auth        the Partner app
 */
require_once __DIR__ . '/../config/google_login.php';
require_once __DIR__ . '/../db_connection.php';

function googleStartFail(string $msg): void
{
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in</title>'
       . '<body style="font-family:system-ui,sans-serif;padding:40px 20px;text-align:center;color:#374151">'
       . '<p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p><p><a href="javascript:history.back()">Go back</a></p></body>';
    exit;
}

if (!googleLoginReady()) googleStartFail('Google sign-in is not switched on yet.');

$for = (string)($_GET['for'] ?? '');
$state = ['f' => $for];

$browserKey = '';
if ($for === 'owner') {
    // nothing else needed
} elseif ($for === 'superadmin') {
    if (!googleLoginSuperadminEmails()) googleStartFail('Google sign-in is switched off for Super Admin.');
    // ties the sign-in to this browser: google_finish.php only accepts the pass next to this cookie
    $browserKey = bin2hex(random_bytes(16));
    $state['k'] = $browserKey;
} elseif ($for === 'customer') {
    $rid = trim((string)($_GET['rid'] ?? ''));
    $host = strtolower(trim((string)($_GET['host'] ?? '')));
    $ret = (string)($_GET['ret'] ?? '/');
    if (!preg_match('/^[A-Za-z0-9]{3,20}$/', $rid)) googleStartFail('This restaurant could not be found.');
    // back only to this restaurant's site: restrogrow.com (or this local copy) or its own domain
    $st = getConnection()->prepare('SELECT custom_domain FROM users WHERE restaurant_id = ? LIMIT 1');
    $st->execute([$rid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) googleStartFail('This restaurant could not be found.');
    $own = strtolower(preg_replace('#^https?://|/.*$#', '', trim((string)($row['custom_domain'] ?? ''))));
    $allowed = ['restrogrow.com', 'www.restrogrow.com', strtolower($_SERVER['HTTP_HOST'] ?? '')];
    if ($own !== '') { $allowed[] = $own; $allowed[] = preg_replace('/^www\./', '', $own); $allowed[] = 'www.' . preg_replace('/^www\./', '', $own); }
    // an outlet of a brand: back to the brand's own domain too (config/brand.php)
    require_once __DIR__ . '/../config/brand.php';
    if (($bo = brandOfRestaurant(getConnection(), $rid)) && $bo['brand']['domain_live']) { $allowed[] = $bo['brand']['custom_domain']; $allowed[] = 'www.' . $bo['brand']['custom_domain']; }
    if (!in_array($host, $allowed, true)) $host = strtolower($_SERVER['HTTP_HOST'] ?? 'restrogrow.com');
    $path = fn($p) => ($p !== '' && $p[0] === '/' && !str_starts_with($p, '//') && !preg_match('/[\r\n]/', $p)) ? mb_substr($p, 0, 300) : '/';
    // r: where to go once logged in; b: the login page, for "cancelled" and error messages
    $state += ['rid' => $rid, 'h' => $host, 'r' => $path($ret), 'b' => $path((string)($_GET['back'] ?? $ret))];
} elseif ($for === 'app') {
    $app = (string)($_GET['app'] ?? '');
    if (!preg_match('#^(restrogrow|exps?)://[^\s"<>]{0,200}$#', $app)) googleStartFail('This sign-in link is not valid.');
    $state += ['a' => $app];
} else {
    googleStartFail('This sign-in link is not valid.');
}

// Super Admin only: the browser key (checked by google_finish.php); also tells the callback where an
// expired sign-in began, since an expired signed state can't be read. Any other sign-in clears it.
setcookie('rg_google_sa', $browserKey, ['expires' => $browserKey !== '' ? time() + 1800 : 1, 'path' => '/',
    'secure' => !googleLoginIsLocal(), 'httponly' => true, 'samesite' => 'Lax']);
header('Location: ' . googleLoginAuthUrl($state));
exit;
