<?php
/**
 * OAuth discovery documents for AI apps (config/mcp_oauth.php). The root .htaccess serves
 *   /.well-known/oauth-protected-resource[/mcp]  → ?doc=resource   (RFC 9728)
 *   /.well-known/oauth-authorization-server      → ?doc=server     (RFC 8414)
 *   /.well-known/openid-configuration            → ?doc=server
 */
require_once __DIR__ . '/../config/mcp_oauth.php';
mcpCors();

$base = mcpBase();
if (($_GET['doc'] ?? '') === 'resource') {
    mcpJson(200, [
        'resource' => mcpResource(),
        'authorization_servers' => [mcpIssuer()],
        'scopes_supported' => MCP_SCOPES,
        'bearer_methods_supported' => ['header'],
        'resource_name' => 'RestroGrow',
        'resource_documentation' => $base . '/faq/',
    ], ['Cache-Control: public, max-age=3600']);
}

mcpJson(200, [
    'issuer' => mcpIssuer(),
    'authorization_endpoint' => $base . '/main/oauth/authorize.php',
    'token_endpoint' => $base . '/main/oauth/token.php',
    'registration_endpoint' => $base . '/main/oauth/register.php',
    'scopes_supported' => MCP_SCOPES,
    'response_types_supported' => ['code'],
    'grant_types_supported' => ['authorization_code', 'refresh_token'],
    'code_challenge_methods_supported' => ['S256'],
    'token_endpoint_auth_methods_supported' => ['none'],
    'client_id_metadata_document_supported' => true,
    'authorization_response_iss_parameter_supported' => true,
    'service_documentation' => $base . '/faq/',
], ['Cache-Control: public, max-age=3600']);
