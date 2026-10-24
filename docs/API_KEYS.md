# API keys, REST API and MCP for developers

Owners create API keys so their developer, Claude Code, Cursor or any AI agent can read and change their restaurant.
Built 2026-10-24. Public docs: https://restrogrow.com/developers (`developers/index.html`).

## Pieces

| Piece | File | Address |
|---|---|---|
| Keys, permissions, verify | `main/config/api_keys.php` | |
| Dashboard page (left menu › API keys) | `main/assets/js/api-keys-studio.js`, `main/api/api_keys_admin.php` | `dashboard.php?page=apiKeysPage` |
| REST API router + OpenAPI | `main/api/v1/index.php` | `/api/v1/...`, `/api/v1/openapi.json` (root .htaccess) |
| Same keys on the MCP server | `main/mcp/server.php`, `main/mcp/tools.php` | `/mcp` |
| Public docs | `developers/index.html` | `/developers/` (sitemap + home footer) |

The REST routes call the same tools as the ChatGPT / Claude app (`mcpRunTool` in `mcp/tools.php`), so numbers,
checks (order state machine, restaurant scoping) and the Activity log match the dashboard. Add a tool there, give it a
permission in `API_TOOL_PERMS`, add a route in `apiRoutes()` and a section in `developers/index.html`; the OpenAPI
document is built from the routes and tool schemas, so it follows by itself.

## Keys

- Format `rg_live_` + 40 hex. Only the SHA-256 is stored (table `api_keys`, created on first use). Shown once.
- Owner only creates / revokes (manager with settings permission can look). At most 20 live keys per restaurant.
- Valid until: never, 7 / 30 / 90 / 365 days or a picked date (through 23:59:59 that day, at most 5 years ahead).
- Edit (name, permissions, valid until, allowed IPs) keeps the same secret.
- Rotate: new row with a new secret and the same settings (`rotated_from` = old id); the old key is revoked now or its
  `expires_at` is cut to 1 h / 24 h ahead. The list shows it as "Old key · stops in …" with Stop now.
- Pause / resume (`paused_at`): calls get 403 `key_paused`. Allowed IPs (`allowed_ips`, exact IPv4/IPv6, CF-Connecting-IP
  first): others get 403 `ip_not_allowed`. `use_count` + `last_used_at` go up on every call.
- Revoked keys stay listed 90 days; revoked or expired keys can be deleted. Columns added after the first live push
  (allowed_ips, use_count, paused_at, rotated_from) are added by `apiKeysEnsure()` itself.
- A key stops working when revoked, expired, the owner account is switched off or no longer owns the restaurant,
  the plan is blocked, or Super Admin › Restaurants › Access switches off **API keys** (module `api`, page `apiKeysPage`).
- Unlike ChatGPT connections, a password change does **not** revoke keys (developer keys are long-lived on purpose;
  owners revoke them on the page).
- Header: `Authorization: Bearer rg_live_…` or `X-API-Key: rg_live_…`.

## Permissions

`restaurant:read`, `menu:read`, `menu:write`, `orders:read`, `orders:write`, `reports:read` (`API_PERMS`).
On /mcp a key lists only the tools its permissions allow (`tools/list` is filtered) and the call is checked again.

## Limits and answers

- 120 calls a minute per restaurant, shared with /mcp (`checkRateLimit('mcp_<rid>')`).
- `{"success":true,"data":…,"summary":"…"}` or `{"success":false,"error":{"code","message"}}`;
  codes: missing_key / invalid_key 401, missing_permission / key_paused / ip_not_allowed / api_off / account_blocked 403, not_found 404,
  method_not_allowed 405, invalid_request 422, rate_limited 429.
- Activity log actor: `API key "<name>"`, role `API key`.

## Local testing

`http://localhost/menuwebsite/api/v1/me` works. For /mcp use `main/mcp/server.php` (Avast empties replies whose URL
ends in `/mcp`, see CHATGPT_APP.md). Create a test key in the dashboard as demo / demo, or with `apiKeyCreate()` from PHP CLI.

## Later

More endpoints when asked (customers, coupons, reservations, stock, staff, webhooks for new orders).
