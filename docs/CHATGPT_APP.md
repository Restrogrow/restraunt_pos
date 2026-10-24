# RestroGrow in ChatGPT and Claude (MCP app)

Restaurant owners connect RestroGrow inside ChatGPT (or Claude) and run the restaurant by chatting:
"sales this week?", "switch off Paneer Tikka", "add Mango Lassi 129 to Beverages", "accept the newest order".

Built 2026-10-21. Rollback point before it: git tag `before-chatgpt-app`.

## How it works

| Piece | File | Address |
|---|---|---|
| MCP server (JSON-RPC over HTTP POST) | `main/mcp/server.php` | `https://restrogrow.com/mcp` |
| Tools (what the AI can do) | `main/mcp/tools.php` | |
| OAuth helpers, tables, tokens | `main/config/mcp_oauth.php` | |
| Discovery documents | `main/oauth/metadata.php` | `/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server` |
| App registration (DCR) | `main/oauth/register.php` | |
| "Connect" screen (login + Allow) | `main/oauth/authorize.php` | |
| Token endpoint | `main/oauth/token.php` | |

Flow: the AI app calls `/mcp` → gets 401 with `WWW-Authenticate` → reads the discovery documents → registers itself
(DCR, or a Client ID Metadata Document from chatgpt.com / claude.ai) → opens `authorize.php` → the owner logs in with
the normal RestroGrow login (`admin/login.php?oauth=<request>`, WhatsApp OTP and Google sign-in included; new owners
can sign up there too) → **Allow** → code (PKCE S256, 5 minutes, one use) → access token (1 hour) + refresh token
(90 days, replaced on every use).

Safety:
- Only the restaurant **owner** can connect (not staff or branch admins). The token is tied to that one restaurant.
- Every query is scoped to the token's restaurant; another restaurant's ids answer "not found".
- Tokens are random and only their SHA-256 is stored. The token's audience (resource) is checked on every call.
- An owner who is switched off, or whose plan expired or was disabled, gets a clear message instead of data.
- Changing or resetting the owner password (dashboard, forgot password, Super Admin) cuts every AI connection.
- Write tools need the `write` scope; ChatGPT asks the owner before running them (tool annotations).
- Order changes use the same order state machine as the dashboard; cancel / reject needs a reason.
- Every change is in the owner's Activity log with "via ChatGPT" / "Claude".
- Rate limits: 120 calls a minute per restaurant, 20 registrations an hour per IP.

Tables (created on first use, so the live DB needs no migration): `mcp_clients`, `mcp_requests`, `mcp_codes`, `mcp_tokens`.

## Tools

Read (scope `read`): `get_restaurant`, `get_sales_summary`, `get_report` (top / slow dishes, one dish, peak hours,
payments, channels, customers, cancellations, lapsed regulars, today so far, advice; the same numbers as the AI manager),
`list_orders`, `get_order`, `list_menu`.
Reports follow Super Admin › Restaurants › Access (`reportsPage`). Menu and orders are always on.

Write (scope `write`): `set_dish_availability`, `update_dish` (name, description, price, veg type, size prices),
`add_dish` (creates the menu section when new), `update_order_status`.

## Try it (owner's to-do: Super Admin › Tools › My to-do › AI apps)

Owners find the steps in the dashboard: **Growth › ChatGPT & Claude** (`assets/js/ai-apps-studio.js`,
`api/ai_apps_admin.php`): ChatGPT / Claude tabs, the address with a Copy button, example questions, the connected
apps with Disconnect (rgUndo), and the safety points. Super Admin › Restaurants › Access › "ChatGPT & Claude" switches
the page, the Allow screen and every tool off for a restaurant. Super Admin › Usage counts AI sessions.

ChatGPT (paid plan only: Developer mode is not on Free): Settings › Apps & Connectors › Advanced settings (bottom) ›
Developer mode on › Create: name RestroGrow, URL `https://restrogrow.com/mcp`, Authentication OAuth › tick I trust this
application › Create › log in (demo / demo) › Allow › new chat › + › More (or Developer mode) › RestroGrow.

Claude: Settings › Connectors › Add custom connector › `https://restrogrow.com/mcp` › Add › Connect.

The demo account (demo / demo) is not read-only: it holds only sample data, reset it from Super Admin › Tools › Demo
restaurant. Owners always connect with their own login.

Test prompts (also for the OpenAI review):
1. "How were my sales this week compared to last week?"
2. "What are my top 5 dishes this month?"
3. "How many Paneer Tikka did I sell this month?"
4. "Show today's open orders."
5. "Switch off Paneer Tikka, we ran out." (asks first, then switches it off)
6. "Add Mango Lassi for 129 to Beverages."
7. "Accept the newest pending order, 20 minutes."

## Local testing

`http://localhost/menuwebsite/mcp` works, **but on this PC Avast's web shield empties every reply whose URL ends in
`/mcp`** (Apache's access log shows the real size; curl gets 0 bytes). Test locally with
`http://localhost/menuwebsite/main/mcp/server.php` instead. The live site is not affected.

## Publishing in the ChatGPT app directory

Needs OpenAI review (platform.openai.com › Apps): verified organisation, MCP URL, OAuth, a test account without 2FA
(demo / demo), privacy policy and terms URLs, logo, screenshots (706 px wide), demo video, test prompts above.
Without approval, owners can still connect through Developer mode.

## Later

- ChatGPT widgets (order cards, menu list with photos) through the Apps SDK UI resources.
- More tools: coupons, reservations, customers, stock, staff.

Owners can also use these tools with an API key (Claude Code, Cursor, own code): see docs/API_KEYS.md.
