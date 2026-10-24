<?php
/**
 * RestroGrow REST API v1, served at /api/v1/... (root .htaccess). Docs for developers: /developers (developers/index.html).
 *
 * Every call needs an owner's API key (config/api_keys.php): Authorization: Bearer rg_live_… (or X-API-Key).
 * Each route needs one permission, ticked when the key was made. The work itself is done by the same tools the
 * ChatGPT / Claude app uses (mcp/tools.php), so the numbers, checks and activity log match the dashboard.
 *
 * Answers: {"success":true,"data":{…},"summary":"one line for people"} or
 *          {"success":false,"error":{"code":"…","message":"…"}} with a matching HTTP status.
 * GET /api/v1/openapi.json needs no key (the machine-readable version of the docs).
 */
require_once __DIR__ . '/../../config/api_keys.php';
require_once __DIR__ . '/../../config/rate_limit.php';
require_once __DIR__ . '/../../db_connection.php';

header('Access-Control-Allow-Origin: *');   // no cookies here: the key is the only way in
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, X-API-Key, Content-Type');
header('Access-Control-Expose-Headers: Retry-After, X-RateLimit-Limit');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

const API_RATE_LIMIT = 120;   // calls a minute per restaurant (shared with /mcp keys)

function apiOut(int $status, array $body, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    foreach ($headers as $h) header($h);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function apiErr(int $status, string $code, string $message, array $headers = []): void
{
    apiOut($status, ['success' => false, 'error' => ['code' => $code, 'message' => $message]], $headers);
}

/** method + path → [tool, permission, path params]. The route list is also the OpenAPI document (apiOpenApi()). */
function apiRoutes(): array
{
    return [
        ['GET', 'me', null, null],
        ['GET', 'restaurant', 'get_restaurant', 'restaurant:read'],
        ['GET', 'menu', 'list_menu', 'menu:read'],
        ['POST', 'menu/items', 'add_dish', 'menu:write'],
        ['PATCH', 'menu/items/{item_id}', 'update_dish', 'menu:write'],
        ['POST', 'menu/availability', 'set_dish_availability', 'menu:write'],
        ['GET', 'orders', 'list_orders', 'orders:read'],
        ['GET', 'orders/number/{order_number}', 'get_order', 'orders:read'],
        ['GET', 'orders/{order_id}', 'get_order', 'orders:read'],
        ['POST', 'orders/{order_id}/status', 'update_order_status', 'orders:write'],
        ['GET', 'reports/sales', 'get_sales_summary', 'reports:read'],
        ['GET', 'reports/{report}', 'get_report', 'reports:read'],
    ];
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = trim((string)($_GET['route'] ?? ($_SERVER['PATH_INFO'] ?? '')), '/');

if ($path === 'openapi.json' && $method === 'GET') {
    require_once __DIR__ . '/../../mcp/tools.php';
    apiOut(200, apiOpenApi());
}

// find the route
$route = null;
$params = [];
$knownPath = false;
foreach (apiRoutes() as $r) {
    $re = '#^' . preg_replace('#\\\{([a-z_]+)\\\}#', '(?P<$1>[^/]+)', preg_quote($r[1], '#')) . '$#';
    if (!preg_match($re, $path, $m)) continue;
    $knownPath = true;
    if ($r[0] !== $method && !($r[0] === 'PATCH' && $method === 'POST')) continue;   // POST also works where PATCH is asked
    $route = $r;
    foreach ($m as $k => $v) if (is_string($k)) $params[$k] = urldecode($v);
    break;
}
if (!$route) {
    if ($knownPath) apiErr(405, 'method_not_allowed', "$method is not used on /api/v1/$path. See https://restrogrow.com/developers");
    apiErr(404, 'not_found', 'No such endpoint: /api/v1/' . $path . '. See https://restrogrow.com/developers');
}

try {
    $conn = getConnection();
} catch (Throwable $e) {
    error_log('api v1: ' . $e->getMessage());
    apiErr(503, 'unavailable', 'RestroGrow is busy, try again in a moment.');
}

$token = apiKeyFromRequest();
if ($token === '') apiErr(401, 'missing_key', 'Send your API key: Authorization: Bearer rg_live_… Create one in the RestroGrow dashboard › API keys.', ['WWW-Authenticate: Bearer']);
$tok = apiKeyVerify($conn, $token, $why);
if (!$tok) {
    if ($why === 'key_paused') apiErr(403, 'key_paused', 'This API key is paused. The owner can resume it in the RestroGrow dashboard › API keys.');
    if ($why === 'ip_not_allowed') apiErr(403, 'ip_not_allowed', 'This API key does not work from your IP address (' . apiKeyClientIp() . '). The owner can add it in the RestroGrow dashboard › API keys › Edit.');
    apiErr(401, 'invalid_key', 'This API key is wrong, revoked or expired.', ['WWW-Authenticate: Bearer error="invalid_token"']);
}
$rid = $tok['restaurant_id'];

$rl = checkRateLimit('mcp_' . $rid, API_RATE_LIMIT, 60);
if (!$rl['allowed']) apiErr(429, 'rate_limited', 'Too many requests: at most ' . API_RATE_LIMIT . ' a minute. Wait and try again.', ['Retry-After: ' . (int)$rl['retry_after'], 'X-RateLimit-Limit: ' . API_RATE_LIMIT]);
header('X-RateLimit-Limit: ' . API_RATE_LIMIT);

require_once __DIR__ . '/../subscription_middleware.php';
$sub = checkSubscriptionAccess($conn, (int)$tok['user_id'], $rid);
if (!empty($sub['blocked'])) apiErr(403, 'account_blocked', 'This RestroGrow account is ' . ($sub['reason'] === 'disabled' ? 'switched off' : 'expired') . '. Renew it in the RestroGrow dashboard.');
require_once __DIR__ . '/../../config/access_control.php';
if (!accessPageOn($conn, $rid, 'apiKeysPage')) apiErr(403, 'api_off', 'API keys are switched off for this restaurant. Ask RestroGrow support to switch them on.');

[, , $tool, $perm] = $route;
if ($tool === null) {
    // GET /me: which restaurant and what this key may do
    $st = $conn->prepare('SELECT restaurant_name FROM users WHERE id = ?');
    $st->execute([$tok['user_id']]);
    apiOut(200, ['success' => true, 'data' => ['restaurant_id' => $rid, 'restaurant' => (string)$st->fetchColumn(), 'key' => ['id' => $tok['key_id'], 'name' => $tok['key_name'], 'permissions' => $tok['perms'], 'valid_until' => $tok['expires_at']],
        'rate_limit_per_minute' => API_RATE_LIMIT], 'summary' => 'Key "' . $tok['key_name'] . '" can: ' . implode(', ', $tok['perms'])]);
}
if (!in_array($perm, $tok['perms'], true)) apiErr(403, 'missing_permission', "This key has no \"$perm\" permission. Make a key with it in the RestroGrow dashboard › API keys.");

// the arguments: query string for GET, JSON body otherwise, then the path parameters
if ($method === 'GET') {
    $args = $_GET;
    unset($args['route']);
} else {
    $raw = (string)file_get_contents('php://input');
    $args = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($args)) apiErr(400, 'bad_json', 'Send a JSON object body with Content-Type: application/json.');
}
foreach (['limit', 'item_id', 'order_id', 'prep_minutes'] as $k) if (isset($args[$k]) && is_string($args[$k]) && is_numeric($args[$k])) $args[$k] = (int)$args[$k];
if (isset($args['only_switched_off']) && is_string($args['only_switched_off'])) $args['only_switched_off'] = in_array(strtolower($args['only_switched_off']), ['1', 'true', 'yes'], true);
foreach ($params as $k => $v) $args[$k] = in_array($k, ['item_id', 'order_id'], true) ? (int)$v : $v;
if (isset($params['item_id']) && $args['item_id'] <= 0) apiErr(404, 'not_found', 'item_id must be a number.');
if (isset($params['order_id']) && $args['order_id'] <= 0) apiErr(404, 'not_found', 'order_id must be a number. For the number on the bill use /orders/number/{order_number}.');

require_once __DIR__ . '/../../mcp/tools.php';
try {
    $s = ownerMgrSettings($conn, $rid);
    ownerMgrUseTz($conn, $s);
    $ctx = ['rid' => $rid, 'user_id' => (int)$tok['user_id'], 'scopes' => [], 'perms' => $tok['perms'], 'settings' => $s, 'app' => apiKeyLabel($tok)];
    [$text, $data] = mcpRunTool($conn, $ctx, $tool, $args);
    apiOut($method === 'POST' && $tool === 'add_dish' ? 201 : 200, ['success' => true, 'data' => $data, 'summary' => $text]);
} catch (McpToolError $e) {
    // the tools speak to AI apps by tool name; a developer gets the endpoint instead
    $msg = strtr($e->getMessage(), ['Use list_menu' => 'Use GET /menu', 'Use update_dish' => 'Use PATCH /menu/items/{id}', 'Use list_orders' => 'Use GET /orders',
        '(from list_menu)' => '(from GET /menu)', 'get_order lists them' => 'GET /orders/{id} lists them in can_move_to']);
    $notFound = preg_match('/not found|not on this menu|None of those dishes/i', $msg);
    $forbidden = preg_match('/switched off for this restaurant|permission/i', $msg);
    apiErr($notFound ? 404 : ($forbidden ? 403 : 422), $notFound ? 'not_found' : ($forbidden ? 'forbidden' : 'invalid_request'), $msg);
} catch (Throwable $e) {
    error_log("api v1 $tool ($rid): " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    apiErr(500, 'server_error', 'Something went wrong in RestroGrow. Please try again.');
}

/** OpenAPI 3.1 for /api/v1, built from apiRoutes() and the tool schemas, so it never drifts from the code. */
function apiOpenApi(): array
{
    $tools = array_column(mcpToolDefs(), null, 'name');
    $err = ['$ref' => '#/components/responses/Error'];
    $paths = [];
    foreach (apiRoutes() as [$m, $p, $tool, $perm]) {
        $op = ['security' => [['bearer' => []]], 'responses' => ['200' => ['$ref' => '#/components/responses/Ok'], '401' => $err, '403' => $err, '404' => $err, '422' => $err, '429' => $err]];
        if ($tool === null) {
            $op += ['operationId' => 'me', 'summary' => 'This key', 'description' => 'The restaurant this key belongs to and its permissions. Needs no permission.'];
        } else {
            $t = $tools[$tool];
            $schema = $t['inputSchema'];
            $props = (array)$schema['properties'];
            $pathParams = [];
            if (preg_match_all('/\{([a-z_]+)\}/', $p, $mm)) foreach ($mm[1] as $name) {
                $pathParams[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => $props[$name] ?? ($name === 'report' ? ['type' => 'string', 'enum' => MCP_REPORTS] : ['type' => 'string'])];
                unset($props[$name]);
            }
            $id = $tool . ($p === 'orders/number/{order_number}' ? '_by_number' : '');
            if ($tool === 'get_order') unset($props['order_id'], $props['order_number']);
            if ($tool === 'get_report') unset($props['report']);
            $op += ['operationId' => $id, 'summary' => $t['title'], 'description' => $t['description'] . "\n\nPermission: `$perm`.", 'x-permission' => $perm];
            if ($pathParams) $op['parameters'] = $pathParams;
            if ($m === 'GET') {
                foreach ($props as $name => $sch) $op['parameters'][] = ['name' => $name, 'in' => 'query', 'required' => false, 'schema' => $sch];
            } elseif ($props) {
                $req = array_values(array_intersect($schema['required'] ?? [], array_keys($props)));
                $op['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => (object)$props] + ($req ? ['required' => $req] : [])]]];
            }
            if ($tool === 'add_dish') $op['responses']['201'] = $op['responses']['200'];
        }
        $paths['/' . $p][strtolower($m)] = $op;
    }
    return [
        'openapi' => '3.1.0',
        'info' => ['title' => 'RestroGrow API', 'version' => '1.0.0', 'description' => 'Read and change your RestroGrow restaurant: menu, orders and reports. Create a key in the RestroGrow dashboard › API keys. Docs: https://restrogrow.com/developers'],
        'servers' => [['url' => 'https://restrogrow.com/api/v1']],
        'components' => [
            'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Your API key: rg_live_…']],
            'responses' => [
                'Ok' => ['description' => 'Done', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean', 'const' => true], 'data' => ['type' => 'object'], 'summary' => ['type' => 'string']]]]]],
                'Error' => ['description' => 'Refused', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean', 'const' => false],
                    'error' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'message' => ['type' => 'string']]]]]]]],
            ],
        ],
        'paths' => $paths,
    ];
}
