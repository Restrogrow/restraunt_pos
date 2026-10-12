# Connect RepeatGrow ↔ RestroGrow

RestroGrow (restaurant ordering + loyalty, https://restrogrow.com, PHP) sends its WhatsApp messages
through **RepeatGrow** (WhatsApp CRM, https://repeatgrow.com, Next.js + Supabase; code in
`Desktop/Repeat grow/wacrm`). This document is the contract between the two.

Instead of a restaurant owner creating an API key in RepeatGrow and pasting it into RestroGrow,
they click **Connect RepeatGrow** in RestroGrow › Growth › WhatsApp, log in to RepeatGrow,
click **Allow**, and land back in RestroGrow connected. RestroGrow can then create every WhatsApp
message template for them and follow Meta's review, so the owner never copies a template.

**Status:** the RestroGrow side is built and tested against a local stand-in. The RepeatGrow side
(section 3) is still to be built.

## 1. The flow

```
Owner clicks "Connect RepeatGrow" (RestroGrow)
  → GET https://repeatgrow.com/connect/restrogrow
        ?client_id=<id>&redirect_uri=<url>&state=<48 hex>&scopes=messages:send,templates:read,templates:write
        &restaurant=<restaurant name, display only>
  → owner logs in to RepeatGrow if needed (come back to this same URL after login)
  → consent page: "RestroGrow wants to send WhatsApp messages from <account>
                   and create message templates for it"   [Allow] [Cancel]
  Allow  → 302 <redirect_uri>?code=<one-time code>&state=<same state>
  Cancel → 302 <redirect_uri>?error=access_denied&state=<same state>

RestroGrow (server to server)
  → POST https://repeatgrow.com/api/v1/connect/token
        { client_id, client_secret, code, redirect_uri }
  ← 200 { "data": { "api_key": "wacrm_live_…", "account": { "id": "…", "name": "…" },
                     "phone_number": "+91 98765 43210" | null,
                     "scopes": ["messages:send", "templates:read", "templates:write"] } }
```

RestroGrow then uses the key exactly like any RepeatGrow API key:

| Call | Scope | Used for |
|---|---|---|
| `POST /api/v1/messages` (exists) | `messages:send` | Gift messages and campaigns (`type: "template"`, positional `params` array) |
| `POST /api/v1/templates` (new) | `templates:write` | Create a template and send it to Meta for review |
| `GET /api/v1/templates?names=a,b,c` (new) | `templates:read` | Review status of those templates |
| `POST /api/v1/keys/revoke-self` (new) | none | "Disconnect" in RestroGrow |
| `GET /api/v1/me` (exists) | none | Connection check |

All responses use the existing `{ data }` / `{ error: { code, message } }` envelope
(`src/lib/api/v1/respond.ts`).

## 2. What RestroGrow sends (already built)

**Client registration (RestroGrow `.env`):** `REPEATGROW_CONNECT_URL=https://repeatgrow.com`,
`REPEATGROW_CLIENT_ID`, `REPEATGROW_CLIENT_SECRET`. The Connect button only shows once these are set.

**Redirect URIs RepeatGrow must allow (exact match):**
- `https://restrogrow.com/main/api/repeatgrow_connect.php`
- `https://www.restrogrow.com/main/api/repeatgrow_connect.php`
- `http://localhost/menuwebsite/main/api/repeatgrow_connect.php` (local development only)

**Templates RestroGrow creates** (`POST /api/v1/templates`):

```json
{
  "name": "spice_garden_indiranagar_birthday_gift",
  "category": "MARKETING",
  "language": "en",
  "body_text": "Happy birthday, {{1}}! {{2}} has a treat for you: {{3}}. Use code {{4}} before {{5}}.",
  "sample_values": { "body": ["Priya", "Spice Garden Indiranagar", "Free Gulab Jamun", "GIFT7K2Q9M", "27 Oct"] }
}
```

- One per message type (11 per restaurant: welcome, birthday, anniversary, winback, tier_up,
  milestone, referral, stamps, challenge, streak, vip), named `<restaurant>_<type>_gift`.
- A changed wording on an **approved** template is created under a new name (`…_gift_v2`, `_v3`…);
  RestroGrow keeps sending the approved one until the new one is approved, then switches.
- Campaigns: `<restaurant>_offer_<8 hex>`; reused for campaigns with the same wording.
- RestroGrow checks statuses every ~10 minutes (and every 30 s while the owner watches the page)
  with `GET /api/v1/templates?names=…` (up to 50 names per call).
- Body only: no header, footer or buttons. Category is sent uppercase (`MARKETING`).

**Sending** (existing endpoint):
```json
{ "to": "+919876543210", "type": "template",
  "template": { "name": "spice_garden_indiranagar_birthday_gift", "language": "en",
                "params": ["Priya", "Spice Garden Indiranagar", "Free Gulab Jamun", "GIFT7K2Q9M", "27 Oct"] } }
```

## 3. What RepeatGrow needs (to build)

### 3.1 Scopes — `src/lib/api-keys/scopes.ts`
Add `templates:read` ("List message templates and their review status") and
`templates:write` ("Create message templates and submit them to Meta").

### 3.2 Connect clients and codes — migration `supabase/migrations/035_connect_clients.sql`

```sql
create table connect_clients (
  id text primary key,                 -- e.g. 'restrogrow'
  name text not null,                  -- 'RestroGrow'
  secret_hash text not null,           -- sha256 hex of the client secret
  redirect_uris text[] not null,       -- exact-match allowlist
  allowed_scopes text[] not null,      -- ceiling for what the client may ask for
  created_at timestamptz default now()
);
create table connect_codes (
  code_hash text primary key,          -- sha256 of the one-time code
  client_id text not null references connect_clients(id),
  account_id uuid not null references accounts(id) on delete cascade,
  user_id uuid not null references auth.users(id) on delete cascade,
  scopes text[] not null,
  redirect_uri text not null,
  label text,                          -- e.g. restaurant name, for the key name
  expires_at timestamptz not null,     -- now() + 5 minutes
  used_at timestamptz
);
alter table api_keys add column if not exists client_id text references connect_clients(id);
```
Both new tables: RLS enabled with no policies (service role only). Seed the RestroGrow client
(generate the secret once, store only its hash, hand the plaintext to RestroGrow's `.env`).

### 3.3 Consent page — `src/app/connect/[client]/page.tsx` (+ a server action)
- Not logged in → redirect to `/login?next=<this URL>` and come back after login.
- Validate: `client_id` exists and equals the `[client]` segment; `redirect_uri` is in its
  allowlist (exact string match); every requested scope is in `allowed_scopes`; `state` is
  present (pass through untouched). Invalid → show an error page, **never redirect** to an
  unchecked `redirect_uri`.
- Requires role admin+ (`requireRole('admin')`), same as creating API keys; members see
  "Ask an admin of <account> to connect".
- If the account has no connected WhatsApp number, say so and link to WhatsApp settings
  (Allow still works; sending fails until a number is connected).
- Show: client name, the account name, the restaurant name (`restaurant` param, escaped, display
  only), and the permissions in plain words (from `SCOPE_DESCRIPTIONS`). RepeatGrow styling.
- **Allow** (POST, server action, CSRF-safe): create a 32-byte base64url code, store its sha256 in
  `connect_codes` (5-minute expiry), then `302 redirect_uri?code=…&state=…`.
- **Cancel**: `302 redirect_uri?error=access_denied&state=…`.

### 3.4 `POST /api/v1/connect/token` — `src/app/api/v1/connect/token/route.ts`
- Body `{ client_id, client_secret, code, redirect_uri }`. No API key on this route.
- `client_secret` → sha256 → timing-safe compare with `secret_hash`; else `401 invalid_client`.
- Code: look up by sha256; must belong to that client, match `redirect_uri` exactly, be unexpired
  and unused; mark used atomically (`update … set used_at = now() where code_hash = $1 and
  used_at is null returning *`); else `400 invalid_grant`.
- Mint the key with `generateApiKey()` and insert into `api_keys` (service role): `account_id`,
  `created_by` = the approving user, `name` = `RestroGrow – <label>`, `scopes` = the code's scopes,
  `client_id`. Return the plaintext once (never stored):
  `200 { data: { api_key, account: { id, name }, phone_number, scopes } }`.
  `phone_number` = the connected number in display form if known (e.g. from the Graph API
  `display_phone_number`), else `null`.
- Rate-limit this route (existing `src/lib/rate-limit.ts`).

### 3.5 `POST /api/v1/templates` — scope `templates:write`
- Same pipeline as the dashboard route `src/app/api/whatsapp/templates/submit/route.ts`
  (`validateTemplatePayload`, `buildMetaTemplatePayload`, `submitMessageTemplate`, the
  `message_templates` upsert on `(account_id, name, language)`), but the account comes from
  `requireApiKey(request, 'templates:write')` and the DB client is the service role. Factor the
  shared part into `src/lib/whatsapp/` rather than duplicating it.
- Accept `category` in any case (`MARKETING` / `Marketing` → `'Marketing'`). Reject
  `AUTHENTICATION` like the dashboard does.
- Idempotent, so RestroGrow can safely call it again:
  - same name+language, same `body_text`, status `APPROVED` or `PENDING` → `200` with the
    current row, **no** new submission to Meta;
  - same name, `APPROVED`, different body → `409 conflict` (RestroGrow uses a new name instead);
  - `REJECTED` / `DRAFT` → resubmit (edit) and return the new status.
- `201 { data: { id, name, language, category, status } }`, status in Meta's words
  (`PENDING`, `APPROVED`, `REJECTED`, `PAUSED`, `DISABLED`). Honour `WHATSAPP_TEMPLATES_DRY_RUN`.
- No connected WhatsApp number → `400` with a clear message.

### 3.6 `GET /api/v1/templates?names=a,b,c` — scope `templates:read`
- The account's templates, filtered by `names` (comma list, up to 50) when given.
- `200 { data: [ { id, name, language, category, status, rejection_reason, body_text, updated_at } ] }`.
- Status comes from `message_templates`, kept current by the existing template status webhook
  (`src/lib/whatsapp/template-webhook.ts`). Optionally refresh `PENDING` rows from Meta if a row
  hasn't been updated for a while.

### 3.7 `POST /api/v1/keys/revoke-self`
Any valid key may revoke itself: set `revoked_at = now()` on the calling key → `200 { data: { revoked: true } }`.

### 3.8 Settings › Connected apps
In RepeatGrow settings, list keys with a `client_id` ("RestroGrow – Spice Garden Indiranagar",
connected by, date, last used) with **Disconnect** (revoke). Hide them from the normal API-key list
or tag them "Connected app".

### 3.9 Tests
Vitest: scope list; code issue/exchange (expired, reused, wrong redirect_uri, wrong secret);
template create idempotency (same body pending/approved → no resubmit, approved+different → 409,
rejected → resubmit); revoke-self; consent page rejects unknown redirect_uri without redirecting.

## 4. Security notes
- Exact-match redirect URIs; the code is single-use, 5 minutes, stored hashed.
- The client secret only ever travels server to server; the API key is never shown in either UI.
- Keys are account-scoped and carry only the approved scopes; the owner can revoke from either side.
- `restaurant` and `state` are untrusted: escape when displaying, pass `state` back unchanged.

## 5. Local testing
RestroGrow never calls the real RepeatGrow from a local computer. For local runs set
`REPEATGROW_CONNECT_URL` to a local RepeatGrow (or a stand-in) and `REPEATGROW_CLIENT_ID/SECRET`
to a test client registered there.
