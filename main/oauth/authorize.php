<?php
/**
 * "Connect RestroGrow" screen that ChatGPT / Claude open (config/mcp_oauth.php).
 *
 *  GET with the OAuth parameters → checked, saved as a request, then shown as ?req=<id> (a clean URL that also
 *      survives the login page: admin/login.php?oauth=<id> comes back here after logging in).
 *  ?req=<id>, not logged in as the owner → "Log in to RestroGrow".
 *  ?req=<id>, logged in → "Allow ChatGPT to manage <restaurant>?"; POST allow / deny → back to the app.
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/mcp_oauth.php';
require_once __DIR__ . '/../db_connection.php';

header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Cache-Control: no-store');

function oaPage(string $title, string $body): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . htmlspecialchars($title) . ' · RestroGrow</title>'
        . '<link rel="icon" type="image/png" href="../assets/images/logo-192.png"><style>'
        . '*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px;background:#fff7f2;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1f2937}'
        . '.card{width:100%;max-width:420px;background:#fff;border-radius:18px;box-shadow:0 10px 40px rgba(0,0,0,.08);padding:28px}'
        . '.logo{display:flex;align-items:center;gap:10px;font-weight:700;font-size:18px;margin-bottom:18px}.logo img{height:28px;width:auto}'
        . 'h1{font-size:21px;margin:0 0 8px}p{margin:0 0 14px;line-height:1.5;color:#4b5563}ul{margin:0 0 18px;padding-left:20px;color:#374151;line-height:1.7}'
        . '.who{background:#f9fafb;border:1px solid #eee;border-radius:12px;padding:10px 12px;margin-bottom:16px;font-size:14px}.who b{color:#111}'
        . '.btn{display:block;width:100%;border:0;border-radius:12px;padding:13px;font-size:16px;font-weight:600;cursor:pointer;text-align:center;text-decoration:none;margin-top:10px}'
        . '.go{background:#ff6b35;color:#fff}.go:hover{background:#f05a22}.no{background:#f3f4f6;color:#374151}.small{font-size:13px;color:#6b7280;margin-top:14px}'
        . '</style></head><body><div class="card"><div class="logo"><img src="../assets/images/logo-transparent.png" alt="RestroGrow"></div>' . $body . '</div></body></html>';
    exit;
}

function oaError(string $msg): void
{
    http_response_code(400);
    oaPage('Cannot connect', '<h1>Cannot connect</h1><p>' . htmlspecialchars($msg) . '</p><p class="small">Go back to the app and try connecting RestroGrow again.</p>');
}

try {
    $conn = getConnection();
    mcpEnsure($conn);
} catch (Throwable $e) {
    error_log('mcp authorize: ' . $e->getMessage());
    oaError('RestroGrow is busy right now. Please try again in a minute.');
}

// ── 1. A new request from the AI app ──
if (!isset($_GET['req']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $g = fn(string $k) => is_string($_GET[$k] ?? null) ? $_GET[$k] : '';
    $client = $g('client_id') !== '' ? mcpClient($conn, $g('client_id')) : null;
    if (!$client) oaError('This app is not registered with RestroGrow.');
    $redirect = $g('redirect_uri');
    if ($redirect === '' && count($client['redirect_uris']) === 1) $redirect = $client['redirect_uris'][0];
    // never send anyone to an address the app did not register
    if (!in_array($redirect, $client['redirect_uris'], true)) oaError('The return address does not match this app.');
    $back = fn(string $err, string $desc) => mcpRedirectBack($redirect, array_filter(['error' => $err, 'error_description' => $desc, 'state' => $g('state')], 'strlen'));
    if ($g('response_type') !== 'code') $back('unsupported_response_type', 'Only response_type=code is supported.');
    if ($g('code_challenge_method') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $g('code_challenge'))) $back('invalid_request', 'PKCE with S256 is required.');
    $resource = rtrim($g('resource'), '/') ?: mcpResource();
    if ($resource !== mcpResource()) $back('invalid_target', 'Unknown resource.');
    $id = bin2hex(random_bytes(16));
    $conn->prepare('INSERT INTO mcp_requests (id, client_id, redirect_uri, state, code_challenge, scope, resource, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL 30 MINUTE)')
        ->execute([$id, $client['client_id'], $redirect, mb_substr($g('state'), 0, 500), $g('code_challenge'), mcpScope($g('scope')), $resource]);
    header('Location: authorize.php?req=' . $id, true, 302);
    exit;
}

// ── 2. The saved request ──
$reqId = (string)($_GET['req'] ?? $_POST['req'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $reqId)) oaError('This link is not valid.');
$st = $conn->prepare('SELECT * FROM mcp_requests WHERE id = ? AND expires_at > NOW()');
$st->execute([$reqId]);
$req = $st->fetch(PDO::FETCH_ASSOC);
if (!$req) oaError('This connect link has expired.');
$client = mcpClient($conn, $req['client_id']);
if (!$client) oaError('This app is not registered with RestroGrow.');
$app = mcpClientLabel($client);
$appH = htmlspecialchars($app);

$isOwner = isSessionValid() && ($_SESSION['user_type'] ?? '') === 'admin' && !empty($_SESSION['user_id']) && !empty($_SESSION['restaurant_id']);
if (!$isOwner) {
    $staff = !empty($_SESSION['staff_id']) || !empty($_SESSION['branch_admin_id']);
    oaPage('Connect ' . $app, '<h1>Connect ' . $appH . ' to RestroGrow</h1>'
        . '<p>Log in with your RestroGrow <b>owner</b> account to let ' . $appH . ' look after your restaurant.</p>'
        . ($staff ? '<p class="small">You are logged in as staff. Only the restaurant owner can connect apps. Log out first, then log in as the owner.</p>' : '')
        . '<a class="btn go" href="../admin/login.php?oauth=' . $reqId . '">Log in to RestroGrow</a>');
}

$rid = (string)$_SESSION['restaurant_id'];
$userId = (int)$_SESSION['user_id'];
require_once __DIR__ . '/../config/access_control.php';
if (!accessPageOn($conn, $rid, 'aiAppsPage')) oaError('ChatGPT & Claude is switched off for this restaurant. Ask RestroGrow support to switch it on.');
if (empty($_SESSION['mcp_csrf'])) $_SESSION['mcp_csrf'] = bin2hex(random_bytes(16));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['mcp_csrf'], (string)($_POST['csrf'] ?? ''))) oaError('Please open the connect link again.');
    $state = array_filter(['state' => (string)$req['state']], 'strlen');
    if (($_POST['decision'] ?? '') !== 'allow') {
        $conn->prepare('DELETE FROM mcp_requests WHERE id = ?')->execute([$reqId]);
        mcpRedirectBack($req['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'The owner did not allow the connection.'] + $state);
    }
    $code = mcpIssueCode($conn, $req, $rid, $userId);
    try {
        require_once __DIR__ . '/../config/activity_log.php';
        logActivity($conn, $rid, 'ai_app.connect', 'Connected ' . $app . ' (it can see sales, orders and menu' . (str_contains($req['scope'], 'write') ? ', and change the menu and order status' : '') . ')', ['client' => $req['client_id']]);
    } catch (Throwable $e) {}
    mcpRedirectBack($req['redirect_uri'], ['code' => $code] + $state);
}

$u = $conn->prepare('SELECT restaurant_name, username, email FROM users WHERE id = ? AND restaurant_id = ?');
$u->execute([$userId, $rid]);
$me = $u->fetch(PDO::FETCH_ASSOC) ?: [];
$write = str_contains($req['scope'], 'write');
oaPage('Allow ' . $app, '<h1>Allow ' . $appH . ' to manage your restaurant?</h1>'
    . '<div class="who">Restaurant: <b>' . htmlspecialchars((string)($me['restaurant_name'] ?? $rid)) . '</b><br>Logged in as ' . htmlspecialchars((string)(($me['email'] ?? '') ?: ($me['username'] ?? ''))) . '</div>'
    . '<p>' . $appH . ' will be able to:</p><ul><li>See your sales, reports, orders and menu</li>'
    . ($write ? '<li>Add dishes, change prices and switch dishes on or off</li><li>Accept, update or cancel orders</li>' : '') . '</ul>'
    . '<p class="small">' . $appH . ' asks you before it changes anything. You can disconnect any time from ' . $appH . "'s settings.</p>"
    . '<form method="post" action="authorize.php"><input type="hidden" name="req" value="' . $reqId . '"><input type="hidden" name="csrf" value="' . htmlspecialchars($_SESSION['mcp_csrf']) . '">'
    . '<button class="btn go" name="decision" value="allow">Allow</button><button class="btn no" name="decision" value="deny">Cancel</button></form>'
    . '<p class="small">Wrong restaurant? Switch restaurant in your dashboard, then open this link again.</p>');
