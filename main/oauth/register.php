<?php
/** Dynamic Client Registration (RFC 7591) for AI apps: public clients only (config/mcp_oauth.php). */
require_once __DIR__ . '/../config/mcp_oauth.php';
require_once __DIR__ . '/../config/rate_limit.php';
require_once __DIR__ . '/../db_connection.php';
mcpCors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') mcpJson(405, ['error' => 'invalid_request', 'error_description' => 'POST only'], ['Allow: POST']);
$rl = checkRateLimit('mcp_register_' . ($_SERVER['REMOTE_ADDR'] ?? ''), 20, 3600);
if (!$rl['allowed']) mcpJson(429, ['error' => 'slow_down', 'error_description' => 'Too many registrations, try again later.']);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) mcpJson(400, ['error' => 'invalid_client_metadata', 'error_description' => 'JSON body expected']);
try {
    $r = mcpRegister(getConnection(), $in);
} catch (Throwable $e) {
    error_log('mcp register: ' . $e->getMessage());
    mcpJson(500, ['error' => 'server_error']);
}
if (isset($r['error'])) mcpJson(400, ['error' => str_starts_with($r['error'], 'redirect_uri') ? 'invalid_redirect_uri' : 'invalid_client_metadata', 'error_description' => $r['error']]);

mcpJson(201, [
    'client_id' => $r['client_id'],
    'client_id_issued_at' => time(),
    'client_name' => $r['client_name'],
    'redirect_uris' => $r['redirect_uris'],
    'grant_types' => ['authorization_code', 'refresh_token'],
    'response_types' => ['code'],
    'token_endpoint_auth_method' => 'none',
    'scope' => implode(' ', MCP_SCOPES),
]);
