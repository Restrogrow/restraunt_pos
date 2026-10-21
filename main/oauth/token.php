<?php
/**
 * OAuth token endpoint for AI apps (config/mcp_oauth.php).
 *   grant_type=authorization_code: code + code_verifier (PKCE S256) + redirect_uri + client_id
 *   grant_type=refresh_token:      refresh_token + client_id; the refresh token is replaced every time
 */
require_once __DIR__ . '/../config/mcp_oauth.php';
require_once __DIR__ . '/../config/rate_limit.php';
require_once __DIR__ . '/../db_connection.php';
mcpCors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') mcpJson(405, ['error' => 'invalid_request', 'error_description' => 'POST only'], ['Allow: POST']);
$rl = checkRateLimit('mcp_token_' . ($_SERVER['REMOTE_ADDR'] ?? ''), 60, 60);
if (!$rl['allowed']) mcpJson(429, ['error' => 'slow_down']);

$in = $_POST;
if (!$in && str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$p = fn(string $k) => is_string($in[$k] ?? null) ? $in[$k] : '';
$fail = fn(string $err, string $desc = '') => mcpJson(400, array_filter(['error' => $err, 'error_description' => $desc]));

try {
    $conn = getConnection();
    mcpEnsure($conn);
    $clientId = $p('client_id');
    $grant = $p('grant_type');

    if ($grant === 'authorization_code') {
        $conn->beginTransaction();
        $st = $conn->prepare('SELECT * FROM mcp_codes WHERE code_hash = ? FOR UPDATE');
        $st->execute([mcpHash($p('code'))]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        // one use only, whatever happens next
        if ($c) $conn->prepare('DELETE FROM mcp_codes WHERE code_hash = ?')->execute([$c['code_hash']]);
        $conn->commit();
        if (!$c || strtotime($c['expires_at']) < time()) $fail('invalid_grant', 'The code is wrong or has expired. Connect again.');
        if ($clientId !== '' && $clientId !== $c['client_id']) $fail('invalid_grant', 'The code was issued to another app.');
        if ($p('redirect_uri') !== '' && $p('redirect_uri') !== $c['redirect_uri']) $fail('invalid_grant', 'redirect_uri does not match.');
        $verifier = $p('code_verifier');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if ($verifier === '' || !hash_equals($c['code_challenge'], $challenge)) $fail('invalid_grant', 'PKCE check failed.');
        if ($p('resource') !== '' && rtrim($p('resource'), '/') !== $c['resource']) $fail('invalid_target', 'Unknown resource.');
        mcpJson(200, mcpIssueTokens($conn, $c['client_id'], $c['scope'], $c['resource'], $c['restaurant_id'], (int)$c['user_id']));
    }

    if ($grant === 'refresh_token') {
        $conn->beginTransaction();
        $st = $conn->prepare("SELECT * FROM mcp_tokens WHERE token_hash = ? AND kind = 'refresh' AND revoked = 0 AND expires_at > NOW() FOR UPDATE");
        $st->execute([mcpHash($p('refresh_token'))]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t || ($clientId !== '' && $clientId !== $t['client_id']) || !mcpOwnerActive($conn, $t)) { $conn->rollBack(); $fail('invalid_grant', 'Please connect RestroGrow again.'); }
        $conn->prepare('UPDATE mcp_tokens SET revoked = 1 WHERE id = ?')->execute([$t['id']]);
        $out = mcpIssueTokens($conn, $t['client_id'], $t['scope'], $t['resource'], $t['restaurant_id'], (int)$t['user_id']);
        $conn->commit();
        mcpJson(200, $out);
    }

    $fail('unsupported_grant_type');
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('mcp token: ' . $e->getMessage());
    mcpJson(500, ['error' => 'server_error']);
}
