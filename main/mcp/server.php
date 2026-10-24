<?php
/**
 * RestroGrow MCP server: ChatGPT (Apps), Claude and other AI apps manage a restaurant through it.
 * Served at /mcp (root .htaccess). Streamable HTTP transport, JSON responses only (no SSE stream needed:
 * every call is answered straight away). Every request needs a bearer token from main/oauth/token.php, or an
 * owner's API key (config/api_keys.php); without one the 401 tells the app where to log in (config/mcp_oauth.php).
 * Tools: mcp/tools.php.
 */
require_once __DIR__ . '/../config/mcp_oauth.php';
require_once __DIR__ . '/../config/rate_limit.php';
require_once __DIR__ . '/../db_connection.php';
mcpCors();

const MCP_PROTOCOLS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    // no server-to-app stream: each answer comes back on its own POST
    header('Allow: POST');
    mcpJson(405, ['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Use POST.'], 'id' => null]);
}

try {
    $conn = getConnection();
} catch (Throwable $e) {
    error_log('mcp: ' . $e->getMessage());
    mcpJson(503, ['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'RestroGrow is busy, try again.'], 'id' => null]);
}

// an OAuth token from ChatGPT / Claude, or an owner's API key (config/api_keys.php) for Claude Code, Cursor and other agents
require_once __DIR__ . '/../config/api_keys.php';
$token = apiKeyFromRequest();
$tok = $token === '' ? null : (apiKeyLooksLike($token) ? apiKeyVerify($conn, $token, $why) : mcpVerifyAccess($conn, $token));
if (!$tok && apiKeyLooksLike($token) && in_array($why ?? '', ['key_paused', 'ip_not_allowed'], true)) {
    mcpJson(403, ['jsonrpc' => '2.0', 'error' => ['code' => -32001, 'message' => $why === 'key_paused' ? 'This RestroGrow API key is paused. The owner can resume it in the dashboard › API keys.'
        : 'This RestroGrow API key does not work from this IP address (' . apiKeyClientIp() . ').'], 'id' => null]);
}
if (!$tok) {
    mcpJson(401, ['jsonrpc' => '2.0', 'error' => ['code' => -32001, 'message' => 'Connect your RestroGrow account first.'], 'id' => null],
        [$token !== '' ? mcpChallenge('invalid_token', 'The token is expired or was revoked.') : mcpChallenge()]);
}
$rl = checkRateLimit('mcp_' . $tok['restaurant_id'], 120, 60);
if (!$rl['allowed']) mcpJson(429, ['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Too many requests, slow down a little.'], 'id' => null], ['Retry-After: ' . (int)$rl['retry_after']]);

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) mcpJson(400, ['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error'], 'id' => null]);

$batch = array_is_list($body);
$out = [];
foreach ($batch ? $body : [$body] as $msg) {
    $r = mcpHandle($conn, $tok, is_array($msg) ? $msg : []);
    if ($r !== null) $out[] = $r;
}
if (!$out) { http_response_code(202); exit; }   // only notifications
mcpJson(200, $batch ? $out : $out[0]);

function mcpHandle(PDO $conn, array $tok, array $msg): ?array
{
    $id = $msg['id'] ?? null;
    $m = (string)($msg['method'] ?? '');
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
    if (!array_key_exists('id', $msg)) return null;   // a notification (initialized, cancelled …): nothing to answer
    $ok = fn($result) => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    $err = fn(int $code, string $text) => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $text]];

    switch ($m) {
        case 'initialize':
            $v = (string)($params['protocolVersion'] ?? '');
            return $ok([
                'protocolVersion' => in_array($v, MCP_PROTOCOLS, true) ? $v : MCP_PROTOCOLS[1],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'restrogrow', 'title' => 'RestroGrow', 'version' => '1.0.0', 'websiteUrl' => 'https://restrogrow.com'],
                'instructions' => "RestroGrow runs this restaurant's POS, online orders and menu. Use the tools for real numbers; never guess sales or prices. "
                    . "Money is in the restaurant's currency (get_restaurant). Before changing prices, switching dishes off or cancelling an order, say exactly what will change. "
                    . "For menu changes find the dish id with list_menu first. Keep answers short and practical: the owner is busy running a restaurant.",
            ]);
        case 'ping':
            return $ok(new stdClass());
        case 'tools/list':
            require_once __DIR__ . '/tools.php';
            $tools = mcpToolDefs();
            // an API key lists only the tools its permissions allow
            if (isset($tok['perms'])) $tools = array_values(array_filter($tools, fn($t) => in_array(API_TOOL_PERMS[$t['name']] ?? '', $tok['perms'], true)));
            return $ok(['tools' => $tools]);
        case 'tools/call':
            require_once __DIR__ . '/tools.php';
            return $ok(mcpCall($conn, $tok, (string)($params['name'] ?? ''), is_array($params['arguments'] ?? null) ? $params['arguments'] : []));
        case 'resources/list':
            return $ok(['resources' => []]);
        case 'prompts/list':
            return $ok(['prompts' => []]);
    }
    return $err(-32601, "Method not found: $m");
}

/** tools/call → MCP result. Problems the owner can fix come back as isError results the AI can explain. */
function mcpCall(PDO $conn, array $tok, string $name, array $args): array
{
    $fail = fn(string $t) => ['content' => [['type' => 'text', 'text' => $t]], 'isError' => true];
    $rid = $tok['restaurant_id'];
    try {
        require_once __DIR__ . '/../api/subscription_middleware.php';
        $sub = checkSubscriptionAccess($conn, (int)$tok['user_id'], $rid);
        if (!empty($sub['blocked'])) return $fail('This RestroGrow account is ' . ($sub['reason'] === 'disabled' ? 'switched off' : 'expired') . '. Renew it in the RestroGrow dashboard to use it here.');
        require_once __DIR__ . '/../config/access_control.php';
        $isKey = isset($tok['perms']);
        if (!accessPageOn($conn, $rid, $isKey ? 'apiKeysPage' : 'aiAppsPage')) return $fail(($isKey ? 'API keys are' : 'ChatGPT & Claude is') . ' switched off for this restaurant. Ask RestroGrow support to switch ' . ($isKey ? 'them' : 'it') . ' on.');
        $s = ownerMgrSettings($conn, $rid);
        ownerMgrUseTz($conn, $s);
        $client = $isKey ? null : mcpClient($conn, $tok['client_id']);
        $ctx = ['rid' => $rid, 'user_id' => (int)$tok['user_id'], 'scopes' => $tok['scopes'], 'settings' => $s, 'app' => $isKey ? apiKeyLabel($tok) : ($client ? mcpClientLabel($client) : 'AI app')]
            + ($isKey ? ['perms' => $tok['perms']] : []);
        [$text, $data] = mcpRunTool($conn, $ctx, $name, $args);
        return ['content' => [['type' => 'text', 'text' => $text]], 'structuredContent' => (object)$data, 'isError' => false];
    } catch (McpToolError $e) {
        return $fail($e->getMessage());
    } catch (Throwable $e) {
        error_log("mcp tool $name ($rid): " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return $fail('Something went wrong in RestroGrow. Please try again, or do it from the dashboard.');
    }
}
