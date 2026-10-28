# RestroGrow POS — AGENTS.md

## Architecture

- All application code is under `main/`. Root files are the landing page only.
- **No framework** — vanilla PHP 8+, MySQL via PDO, vanilla JS, CSS3.
- DB connection auto-detects Hostinger (`hstgr.io`) vs local; uses internal MySQL socket on Hostinger (no remote config needed).
- `getConnection()` is the canonical way to get the PDO instance (lazy, with retry + health checks).
- Timezone is hard-coded to `Asia/Kolkata` everywhere (PHP `date_default_timezone_set`, MySQL `SET SESSION time_zone = '+05:30'`).

## Key directories

| Directory | Purpose |
|---|---|
| `main/website/` | Customer-facing site (menu, cart, checkout, tracking) |
| `main/api/` | JSON API endpoints (orders, menu, payments, analytics, push) |
| `main/controllers/` | Business logic extracted from admin/API (menu, orders, staff, POS, KOT) |
| `main/views/` | Admin/Staff dashboards (dashboard, chef, waiter, manager) |
| `main/admin/` | Admin login, auth, migration scripts, admin PWA |
| `main/superadmin/` | Multi-restaurant management, subscriptions |
| `main/config/` | Session, env loader, email, push notifications, rate limit, validation |
| `main/database/` | SQL schemas, migration scripts, `full_database_dump.sql` for initial import |
| `main/delivery/` | Rider delivery tracking page |

## Routing

- `.htaccess` rewrites requests → `main/website/custom-domain.php` (front controller).
- URL pattern: `/{restaurant-slug}` or `/{restaurant-slug}/{page}` (menu, cart, about, etc.).
- `custom-domain.php` maps page names to PHP files in `main/website/`.
- Custom domain support: looks up `users.custom_domain` → serves the same website files under the custom domain.
- `is_custom_request`: `$host !== 'restrogrow.com' && $host !== 'www.restrogrow.com' && $host !== 'localhost' && $host !== '127.0.0.1' && strpos($host, 'hstgr.io') === false` — repeated across many files, update consistently.

## Setup

```bash
# 1. Clone to XAMPP htdocs
# 2. Import main/database/full_database_dump.sql into MySQL (UTF-16: convert to UTF-8 first),
#    then run main/database/local_schema_upgrade.sql
# 3. Configure (no Composer: there are no PHP dependencies to install)
cp main/.env.example main/.env
# 4. Demo data
php main/database/seed_demo_restaurant.php      # Spice Garden Indiranagar, demo / demo
php main/database/seed_multibranch_demo.php     # Spice Garden chain (sg_owner / Owner@123)
```

- **Local URLs**: Customer: `http://localhost/menuwebsite/{restaurant-slug}` — Admin: `http://localhost/menuwebsite/main/admin/login.php`
- `.env` is loaded by `main/config/env_loader.php`. `.env.local` overrides `.env` (both gitignored).
- Required env vars: DB credentials, PhonePe config, Google Maps API key, Google Gemini API key, SMTP config.
- Team handbook (accounts, emails, integrations status, crons): `docs/ADMIN_HANDBOOK.md`. README and `docs/` are private: the root `.htaccess` refuses `.md` files and `docs/`.

## Demo restaurant

- **Spice Garden Indiranagar** (`RESSGI002`, login `demo` / `demo`, one-tap at `/demo/`). One builder for everything: `main/config/demo_restaurant.php` (`resetDemoRestaurant()`), used by `main/database/seed_demo_restaurant.php`, and Super Admin › Tools › Demo restaurant (the owner resets it from there; `main/cron/reset_demo_restaurant.php` exists but isn't scheduled).
- `isDemoRestaurantSession()` guards the shared account: `admin/auth.php` blocks password / profile / payment-gateway / system-settings / upload actions and keeps the name (it's the website slug); `api/app_settings.php` ignores renames; `waSend()` never sends for it.
- New features that send messages, charge money or upload files should check `isDemoRestaurantSession()` too.

## Auth

- Session-based, configured in `main/config/session_config.php`.
- `startSecureSession()` must be called before any session usage.
- Roles: Admin, Manager, Waiter, Chef, Cashier, Delivery rider — stored in `$_SESSION['role']` (`main/config/staff_roles.php`). Call `resetLoginIdentity()` before every login so one session never mixes two people.
- Session files stored in `main/sessions/`.

## Key conventions

- All API endpoints return JSON with `Content-Type: application/json`.
- `display_errors = 0` everywhere — errors go to logs, never HTML output.
- PDO with `ERRMODE_EXCEPTION`, non-persistent connections, prepared statements.
- PHP files use `<?php` open tags, no short tags.

## Customer website: speed and bad connections (every page, new ones too)

Every customer page (`main/website/*.php` that includes `header.php`) gets these from `header.php` by itself; a new
page only has to include `header.php` like the others.
- **Retries:** `rg_net.php` goes first in `<head>`. `fetch()` of the site's own GET URLs tries again on a dropped
  connection or a 502/503/504/429 (and waits while offline); POSTs are never sent twice. Use `rgFetchJson(url)` for
  JSON (also retries a 12 s timeout or a non-JSON host error page) and `rgLoadFailed(el, 'the menu', retry)` when a
  section still can't load: "Couldn't load … Try again", never a dead message or an empty grey box.
- **No big photos in the page:** `header.php`'s page filter turns any `data:image` over ~15 KB into a cached WebP
  file (`websiteInlineImageUrl()`, `main/uploads/cache/bg`). Still store uploads as files or `image.php` URLs.
- **Small images:** show logos with `$restaurant_logo_img` (a 256 px WebP), dishes with `image.php?id=…&w=96/200/400/800`,
  not the original upload.
- **No jumping:** a row filled in after load (coupons, banners, combos, specials) must not push the page down. Decide on
  the server whether it has anything (leave it out when not), keep its space when it has (`websiteBannerSlot()`), or
  put the data into the page (`websiteCombosMount()`). Check phone layout shift (412 px wide) stays under 0.1.
- **Tracking links never carry the phone:** build them with `orderTrackCode()` (`config/order_track_link.php`):
  `track?order_number=…&k=…`. Old `customer_phone=` links still open, and the page strips the phone from the address bar.
- **Cart badge** (`bottom_nav.php`) counts dishes, not order add-ons, and hides at 0; it corrects whatever a page writes.
- **Also from `rg_net.php` on every page:** phone boxes (`type="tel"`) keep only digits and get the number keypad;
  Esc closes the popup on top through its close button. Give new popups a close button with `aria-label="Close"`.
- **Words:** information in grey, errors in red; nothing in Title Case; no "in a moment" text that can go stale.

## Owner dashboard: phones, tablets, laptops (every page, new ones too)

- `main/assets/css/mobile.css` is loaded last on `views/dashboard.php` and holds the fixes for all pages. Put a layout fix
  there (or in the page's own CSS), not as a one-off inline style.
- Every box inside `.page` gets `min-width: 0` (zero specificity), so a long number, name or dropdown can't push a grid
  or flex column past the screen. Give a tab bar a `<nav>` parent (its items keep their width and the bar scrolls
  sideways) or `flex: none` on the items; let rows of buttons `flex-wrap: wrap`.
- **Loading and bad connections:** every dashboard (`views/dashboard.php`, manager, waiter, chef, rider, branches) has
  `rgNetScript(false)` (`website/rg_net.php`: GET requests try again on a dropped connection or 502/503/504/429; POSTs never
  twice; offline fails at once so the POS uses its saved menu) and `assets/js/rg-load.js`. rg-load turns any placeholder
  whose text is "Loading…" (or a word-less `.sx-loading` / `.cbx-loading`) into a shimmer skeleton, and after 20 s on
  screen, or on an "Error loading / Could not load / Network error" message, adds Try again (reopens the page with
  `showPage`, else reloads on `?page=`). A new page only has to write "Loading…" where its data will go and replace it
  when the data comes; `data-no-skel` opts out.
- Check a new page at 360, 390, 768, 1024 and 1366 px wide: nothing cut off at the right edge, no text spilling out of
  a button or pill, no tabs drawn on top of each other.

## Private sign-up links

- Super Admin › Sign-up links (`main/superadmin/signup_links.php`, engine `main/config/signup_invites.php`) makes
  `restrogrow.com/join/<code>` (`.htaccess` redirects it to `admin/login.php?tab=signup&invite=<code>`). The client fills in
  all their own details; the link skips the WhatsApp code (like Google sign-up), and `handleSignup()` checks the code again, takes one
  use with `inviteTake()` (gives it back if the insert fails), uses the link's trial days (7 unless Super Admin picks a custom number), and sets `signup_source = 'invite'`.
  After the account is made the link asks for the menu (step 4, `api/signup_menu.php`); files go to the private
  `main/uploads/signup_menus/` (deny-all `.htaccess`, gitignored) and Super Admin opens them via `signup_menu_file.php`.

## Rooms (hotel / lodge)

- `main/config/rooms.php` (tables `room_types`, `room_bookings`, column `users.enable_rooms`, off by default),
  owner API `main/api/rooms_admin.php` + page `assets/js/rooms-studio.js` (#roomsPage, sidebar item above
  Reservations, switch in Settings › Ordering that saves on its own), guest API `main/api/rooms_public.php` +
  page `main/website/rooms.php` (`/{slug}/rooms`, own-domain `/rooms`, bottom bar item while on).
- Room types with a count; guests send a request (Pending), the owner confirms. Pay at the hotel. A room is held
  by Pending / Confirmed / Checked in bookings for the nights [check_in, check_out); count with `roomsFree()`,
  never by hand. Gate everything with `roomsFeatureEnabled()` (owner switch + Super Admin Access `roomsPage`).
- Photos are files in `main/uploads/rooms` (gitignored), only paths that pass `roomPhotoValid()`.
- **Settings** (Rooms › Settings): one JSON row per restaurant in `room_settings.data`, always read through
  `roomSettings()` (defaults merged in) and saved through `roomSettingsSave()` (cleans every key). Times, cancellation
  (`ROOM_CANCEL` or the owner's words), house rules (`ROOM_RULES` ticks + own lines), extra bed price, room tax, bill
  look, room service switches. The guest sees them through `roomPolicies()`. Add a new setting there, not as a column.
- **Room numbers:** a stay's rooms are `room_bookings.room_numbers` ("101, 102"). Every write goes through
  `roomNumbersCheck()` (one of the type's numbers, no more than the rooms booked, and `roomNumbersClash()`: never two
  holding stays in one room on the same night). The Calendar tab draws rooms × 14 nights from the same data.
- **Room bill:** only `roomBill()` works it out (nights × rate, `extras` JSON, `discount`, room tax from
  `roomTaxPercent()`: India "auto" = GST 5% up to Rs. 7,500 a night, 18% above, split CGST / SGST; the room service
  orders on the bill; `advance`; balance). Number: `bill_prefix` + `roomBillNo()` (1, 2, 3 per restaurant, given the
  first time the printable bill opens). Printable page `main/website/room_bill.php` (A4 or `&size=80`), for the logged-in
  team (`?b=ID`) or the guest's WhatsApp link (`?r=RID&b=ID&k=roomBillCode()`). A paid bill can't be edited; marking
  the stay paid (`paid` + `pay_method`) also marks its room service orders Paid, and unpaid again when undone.
- **Room service:** each room's QR code opens the menu with `?room=<number>.<code>` (`roomQrParam()`, HMAC with the
  restaurant's `room_settings.qr_salt`; "Make new QR codes" changes the salt). `website/header.php` checks it with
  `roomServiceFind()` and keeps `$qr_room` in the session for a day; the cart then offers only "Room service · Room N"
  and sends `room_qr`. `api/process_website_order.php` checks it again, saves a Dine-in order with no table and notes
  `ROOM SERVICE - Room N` (every kitchen / order screen shows it, no new order type), and `roomServiceLink()` records
  it in `room_service_orders`. Payment "Room bill" (on the stay's bill, paid at checkout) only for the guest checked
  in to that room today, with the stay's phone number (`roomPhoneSame()`); never with the wallet.
- **Reports:** `roomsReport()`: occupancy, ADR, RevPAR, revenue, extras, room service, by type / source / night.
  Sold nights are Confirmed, Checked in and Checked out stays only.
- **API** (`main/api/rooms_admin.php`, owner; changes need the "manage reservations" permission):
  `GET` (everything for the page), `?free=1&in&out`, `?bill=ID`, `?report=1&from&to`, `?qr=1`; `POST` actions `enable`,
  `save_type`, `delete_type`, `upload`, `status`, `paid` (`method`), `add_booking`, `assign`, `save_bill`,
  `save_settings`, `new_qr`. Guest API `main/api/rooms_public.php`: `GET` rooms (+ `policies`), `POST` a request.

## Smart QR Menu kit orders (restrogrow.com/qr-menu-ordering/)

- The product page `qr-menu-ordering/index.html` sells a package (menu set up + QR standees). "Pay & place order"
  posts to `main/api/qr_kit_order.php`, which prices it again with `qkPrice()` (`main/config/qr_kit.php`, keep its
  numbers equal to the page's `PKG` / `ADVANCE` / `STANDEE` / add-ons and the `D` design list), saves the booking and
  opens PhonePe on **RestroGrow's own keys** for ₹500 or the full amount. Payment ids start `QRK_`; `ppSettle()` sends
  them to `qkSettle()`. Paid = the team gets an email; Super Admin › QR kit orders (`main/superadmin/qr_kits.php`).
  Back from PhonePe the page reads `?booking=QRK_…` and asks the API. Local computer without keys: paid at once (pretend).
- "Make your menu" on that page posts photos / a PDF to `main/api/qr_menu_extract.php`: Gemini (`aiGeminiCall`, JSON
  schema) reads the dishes into `{categories:[{name, items:[{name, price, description, type, variants}]}]}`, the shape the
  page's `mountMenu()` needs. Public, so rationed before Gemini is called: 4 an hour and 10 a day per IP, and
  `QR_MENU_AI_DAILY` (default 300) a day for the whole site. Nothing is stored. Any failure → the page offers WhatsApp.
  Each dish then gets a photo from the server's photo library `images/<Folder>/<file>` (not in git, like Super Admin's
  menu import): `find_local_menu_item_image_candidates()` shortlists by name, a second Gemini call picks the right one
  or none; if that call fails only a score ≥ 0.6 counts. Sent as `image` (`main/website/image.php?path=local:…&w=200`,
  a cached small WebP); the page's `mountMenu()` shows `img` (7th field of an item) before the Kohuke sprite.
- Separate dashboard `main/qrkit/` (`login.php`, `index.php`, `logout.php`) with its own logins (`qr_kit_users`, made in
  Super Admin › QR kit orders › Dashboard logins). Its session keys are only `qrkit_user_id` / `qrkit_at`, which no other
  part of RestroGrow accepts (`isSessionValid()` ignores them): keep it that way. Order changes go through `qkUpdateOrder()`.

## Payments

- **PhonePe** is the only payment gateway integrated, and it takes Indian rupees: it is **India-only**. Check
  `restaurantIsIndia($country)` (`main/config/countries.php`) before offering it; `phonepeCallbackConfig()` returns no
  keys for a restaurant outside India, and those restaurants see "contact support".
- `PHONEPE_ENVIRONMENT=test` for sandbox, `production` for live.
- Callback: `phonepe_order_callback.php` / `phonepe_callback.php`.
- **RestroGrow plan renewals:** the amount and months come only from `pfRenewalPrice()` (Super Admin settings), never
  from the request; they are paid to and verified with the platform keys (`phonepeCallbackConfig(null)`), never a
  restaurant's own keys. Keep it that way in any new payment path.

## Countries

- `main/config/countries.php` is the single source: dial code, currency, phone length (`getCountryData`), time zone and
  tax defaults (`getCountryLocalDefaults`), `applyCountryDefaults()` on signup / Super Admin create.
- Phone numbers are stored as typed digits. When sending WhatsApp, turn them into `+<code><number>` with the
  restaurant's country: `normalizePhoneForWhatsapp($phone, restaurantCountryInfo($pdo, $rid))`. Never hardcode 91.
- `users.country` holds the country **name** ("Nepal"); `users.timezone` the IANA zone. Most of the server still runs on
  IST (`db_connection.php`); the open work for other countries is `docs/IMPORTANT_TODO.md` #6.

## Blog

- Public pages in `blog/` (`_layout.php`, `index.php`, `post.php`, `feed.php`, `sitemap.php`), styles `blog/blog.css`;
  engine `main/config/blog_engine.php`; editor and API `main/superadmin/blog.php` / `blog_api.php`.
- `blog/post.php` only decorates at display time (numbered headings, FAQ cards, summary box, label cards, sign-up box,
  table wrapper); stored posts stay plain HTML from the editor.
- Ready-written posts live in `main/config/blog_starters/*.html` (a `<!-- key: value -->` header + HTML; the folder is
  blocked from the web) and are imported as drafts from Super Admin › Blog.

## Push notifications

- VAPID-based, sent with PHP's own OpenSSL (`main/config/web_push.php`); keys are created into `push_vapid` when `.env` has none.
- Requires HTTPS for service worker registration.
- Subscriptions stored in `push_subscriptions` table.
- Admin opt-in prompt shown on login.

## DB migrations

- Migration scripts live in `main/database/` (SQL) and `main/admin/` (PHP).
- Key files: `full_database_dump.sql` (complete schema + sample data).
- Run migrations via browser or CLI: `php main/admin/run_addons_migration.php` etc.

## No tests

- No test framework (no phpunit, no test directory).
- Manual testing via curl or browser.

## No build step

- No npm scripts, no bundler, no codegen.
- PHP files are served directly by Apache.

## Related project: RepeatGrow

- WhatsApp sending (OTP, gift messages, campaigns) goes through RepeatGrow (https://repeatgrow.com), our WhatsApp CRM: Next.js + Supabase, code in `Desktop/Repeat grow/wacrm`.
- RestroGrow side: `main/config/repeatgrow_whatsapp.php` (OTP), `main/config/whatsapp_engine.php` (gifts, campaigns, outbox), `main/config/repeatgrow_connect.php` + `main/api/repeatgrow_connect.php` (Connect button, template creation).
- The contract between the two is `docs/REPEATGROW_CONNECT.md` (a copy lives in the RepeatGrow repo as `docs/RESTROGROW_CONNECT.md`). Change both sides together.
- Never send real WhatsApp messages from a local computer: `waDryRun()` and `rgcBlocked()` guard this.
