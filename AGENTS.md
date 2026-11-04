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
- **The WhatsApp code at customer sign-up** (`website/otp.php`, purpose `customer_signup`, checked by
  `customer_auth.php`'s `handleCustomerSignup()`) can be switched off per restaurant in Settings › Ordering:
  `users.customer_otp_enabled`, on by default, read only through `customerOtpRequired()`
  (`main/config/customer_otp.php`). Off, the form alone makes the account — on the page
  (`window.customerOtpRequired` in `website/login.php`) and on the server, so a stale page gains nothing.
  The new-device login code is already off everywhere (`config/device_trust_helpers.php`); the WhatsApp
  password reset keeps its code.
- **"Continue as guest"** on the website login screen can be switched off the same way in Settings › Ordering:
  `users.guest_checkout_enabled`, on by default, read only through `guestCheckoutAllowed()`
  (`main/config/guest_checkout.php`). Off, ordering needs an account — the button goes
  (`website/login.php`, which also drops the "or" divider when Google is unconfigured too), the checkout
  and profile gates stop honouring `sessionStorage.guestSessionActive` and clear it (so a tab open from
  before the switch bounces to login once instead of looping), and `api/process_website_order.php` refuses
  a website order with no customer session. A guest at a table's QR code (`$rgTableOrder`), a hotel guest
  ordering from their room (`room_qr`) and WhatsApp orders (`$rgInternalOrder`) are exempt everywhere:
  none of them has a login screen to show, and someone in the restaurant is already being served.

## Key conventions

- All API endpoints return JSON with `Content-Type: application/json`.
- `display_errors = 0` everywhere — errors go to logs, never HTML output.
- PDO with `ERRMODE_EXCEPTION`, non-persistent connections, prepared statements.
- PHP files use `<?php` open tags, no short tags.

## What's on the menu, and where

Three separate switches, often confused:
- **A dish out of stock** — `menu_items.is_available = 0` (Menu items › Out of stock). Off the website; the POS
  still lists it, greyed with an "Out of stock" tag and no + to tap (`.pos-out` in `script.js` /
  `waiter_dashboard.php`), and `pos_operations.php` names it if a bill reaches the server with it on.
- **A dish hidden** — `menu_items.is_hidden = 1` (Menu items › Hide; also where a deleted dish that was already
  ordered ends up, `config/menu_delete.php`). Gone everywhere: website, POS, apps, upsell widgets.
  `api/get_menu_items.php` hands hidden dishes back only to a real menu-manager session asking `include_hidden=1`.
- **A category hidden** — `menu.is_active = 0` (Categories › Hide from website; `set_active` in
  `controllers/menu_operations.php`). Off the website (`website/api.php`) and out of the page's schema.org menu
  (`config/seo_helpers.php`); the POS keeps it, because a counter still has to bill what it sells in person.
  The "Removed dishes" section (`MENU_ARCHIVE_SECTION`) is always off and has no button.

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

## Table sessions (everyone at one table, one bill)

- `main/config/table_session.php`: the first website Dine-in order from a table opens a session (`table_sessions`);
  every later one from that table (other phones, "Order more") gets the same `orders.table_session_id`. Each order stays
  its own order (prices, payment, KOT unchanged). Read a session only through `tsCurrent()`, which closes it when it's
  settled (has orders, none open and unpaid), idle `TS_IDLE_CLOSE` (3 h) with nothing open, or a day old.
- Guests: `api/table_session.php` (first names, no phones) feeds the tracking page's "Table T4 · one bill" card and the
  cart's "Table T4 is already ordering" note. Staff: `api/table_bill.php` + Tables › Bill (`tables-studio.js`): the table as
  one bill, Print bill (`printOrder(0, preset)`), Mark all paid (each order through `update_payment_status.php`), Free table
  (only when nothing is left to pay).
- **A guest from a table's QR code** (`rgQrDineIn()` in cart.php: `$qr_table`, kept in the tab as `sessionStorage.qrTable` by
  menu.php / cart.php) gets the short way: no login page, no coupon popup, no minimum order (the API skips it too for a
  Dine-in order with a table), no email / order type / "When?", "Pay at the table" first, and the tracking page right
  after ordering. Name and phone are still asked once (remembered on the phone): they make the guest's row in Customers.
  The menu and cart show `website/table_session_bar.php` ("Table T4 · Aman, Bina ordered · ₹757 · See"). The order sends
  `table_number` too, so the server finds the table when the page couldn't. Table orders skip the per-IP caps (a table
  shares the restaurant's Wi-Fi) and are capped per table instead (20 in 30 min); the phone caps still apply.
- `orders.client_ref`: the cart sends one per Place order (`rgOrderRef()`, kept until it succeeds); the same ref again returns
  the order already placed. With a client_ref, the old same-phone-same-total 10-minute dedupe no longer merges two real
  rounds of the same dishes.

## Table calls: who is going

- A call (`waiter_requests`) is never **assigned**. Every waiter sees every call and any of them can finish any of
  them — that is the whole model and changing it would be a different product. `taken_by` / `taken_by_name` /
  `taken_at` only record who said "I'm going", so two waiters don't walk to the same table.
- `handleTakeRequest()` (`controllers/waiter_request_operations.php`): first tap wins through
  `WHERE … AND taken_by_name IS NULL`, so a second waiter is told who got there first rather than overwriting them;
  only the one who took a call may release it. `mark_attended` stamps the finisher with `COALESCE`, so whoever took
  it keeps the credit and an untaken call still gets a name.
- Boards: `assets/js/staff-calls.js` (waiter and manager) draws the name, the All / Nobody going / Mine chips (hidden
  until something is taken) and the actions; `StaffUI.isMine()` matches on staff id, falling back to the name for an
  owner with no staff row — so the pages must pass `staffId` / `staffName` in `window.STAFF`.
- Columns are added by `waiterCallEnsureSchema()` (`config/waiter_call.php`), which also owns
  `users.call_waiter_enabled`; call it anywhere that reads or writes them.

## Rooms over the API

- `main/mcp/tools/rooms.php` (`MCP_MODULES` key `rooms`): `list_rooms` and `add_room_photo`, under `rooms:read` /
  `rooms:write` and the `roomsPage` access switch. REST: `GET /api/v1/rooms`, `POST /api/v1/rooms/{room_id}/photos`.
- A room photo is a **file** under `main/uploads/rooms` (that path is gitignored), never a database value. Write one
  only through `roomPhotoStore()` (`config/rooms.php`), shared with the Rooms page's own upload: `save_type` in
  `api/rooms_admin.php` filters `room_types.photos` through `roomPhotoValid()` on every save, so a photo written any
  other way looks fine until the owner next edits that room and then silently disappears.

## Printing bills

- Printer settings live in the browser (`localStorage.printerSettings`, per device and per address), so localhost and
  restrogrow.com can be on different modes. Browser mode shows the HTML preview; the direct modes (USB / Bluetooth /
  network) send bytes.
- A bill (POS bill after payment, Orders › Print) is printed from its preview HTML (`rgBillForPrinter` in
  `assets/js/escpos.js`), so paper and preview match: a network printer gets the HTML as a picture (`rgHtmlBillBuilder`,
  ~75 KB); Bluetooth / USB get the preview turned into printer text in the same columns, bold and sizes, with the logo and
  QR as small pictures (`rgHtmlBillText`, ~4 KB: a picture over Bluetooth's 20-byte packets takes a minute and prints in
  strips). `buildBillEscPos` is only the fallback. Change a bill's look in its HTML, not in the text layout.

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

## Cash book (the drawer: opening and closing balance)

- `main/config/cash_session.php` (tables `cash_sessions`, `cash_movements`, columns `payments.cash_session_id`
  and `orders.cash_session_id`), API `main/api/cashbook.php`, page `assets/js/cashbook-studio.js` (#cashbookPage).
- One shift open at a time per restaurant. `csOpen()` takes the cash already in the drawer, `csMove()` records
  cash that isn't a bill (petty cash, a bank drop), `csClose()` asks for the counted cash and freezes
  `expected_cash`, `counted_cash`, `difference` and the whole report into `cash_sessions.summary`. A closed shift
  is never recalculated: `csSummary()` / `csOne()` return the frozen copy, so a report printed months later reads
  the same.
- `expected = opening + cash taken − cash refunded + movements in − movements out`. Only real cash counts
  (`csIsCashMethod()`, `CS_CASH_METHODS`); card, UPI, wallet and credit are reported but never in the expected
  figure.
- The money is stamped on the **payment**, not the order (`csStamp()`, called by `pos_operations.php`): an open
  bill settled on the night shift belongs to the night shift. `orders.cash_session_id` only answers "which shift
  rang this up".
- **Billing never waits on the cash book.** No open shift = bills go through with no session id, and `csStamp()`
  swallows its own errors. Keep it that way.
- A split bill writes **one `payments` row per method** (`posPaymentSplit()` in `pos_operations.php`, fed by
  `paySplit` from the POS screen). Before that a split bill was a single "Cash+Card+UPI" row and its cash part
  could not be told apart. The client's split only divides the amount the server worked out; if the parts don't
  add up to it, it is ignored and the bill is saved the old way.

## Credit (udhaar)

- `main/config/credit_ledger.php` (tables `credit_accounts`, `credit_entries`), API `main/api/credit_admin.php`,
  page `assets/js/credit-studio.js` (#creditPage).
- The balance is only ever changed through `clCharge()` / `clPay()` / `clAdjust()`, which write the entry and the
  new balance in one transaction. Never `UPDATE credit_accounts.balance` by hand — the entries are the truth and
  the column is their running total.
- A POS bill settled with a method that means credit (`clIsCreditMethod()`: Credit, Udhaar, Khata, On account)
  becomes a charge on the account found from the phone on the bill (`clOnPosBill()`). The unique key on
  (restaurant_id, order_id, type) makes a re-synced offline bill safe. No phone = nothing happens and it stays due.
- **A payment back is not a sale.** The sale was counted when the bill was rung up, so `clPay()` with cash writes
  a cash movement **in** on the open shift instead of new takings — which is what makes the drawer tally without
  double counting the revenue.

## Discounts off a bill

- `main/config/staff_discount.php` (tables `bill_discounts`, `discount_settings`), API `main/api/discount_admin.php`,
  page `assets/js/discount-studio.js` (#discountsPage).
- `sdRecord()` is called inside the bill's transaction from `pos_operations.php`, so `orders.discount_amount` and
  its record can never drift apart. One row per bill (unique key on order_id).
- `sdCheck()` enforces the ceiling per role, **on the server**: over it, only `PERMISSION_APPROVE_DISCOUNT` can put
  the bill through, and the row is marked `over_cap`. Caps start empty = no limit, so billing is unchanged until an
  owner switches them on. Settings are one JSON row per restaurant, read through `sdSettings()` and written through
  `sdSettingsSave()` — add a new setting there, not as a column.
- A free or cheap staff meal is just a reason here ("Staff meal"), so it shows up next to every other reason.

## Attendance and payroll

- `main/config/attendance.php` (tables `attendance`, `payroll_entries`, `payroll_runs`, columns `staff.salary_type`,
  `staff.salary_amount`, `staff.joined_on`), API `main/api/attendance_admin.php`, page
  `assets/js/attendance-studio.js` (#attendancePage and #payrollPage — one script, told which by `load(page)`).
- One row per (restaurant, staff, date). `atMark()` corrects a day instead of adding a second one, and refuses a
  day that hasn't happened yet. `atPunch()` is the one-tap clock in / clock out.
- Day values: present 1 · half 0.5 · absent 0 · paid_leave 1 · week_off and holiday 1 on a monthly salary, 0 on a
  daily wage. **A day nobody marked is not an absence** — a blank means "not marked", so only a day actually marked
  absent costs money (`plFor()`).
- `plMarkPaid()` freezes the month's figures into `payroll_runs` (with a snapshot of the basis and day counts), so a
  payslip never changes because attendance was corrected later. A frozen month refuses attendance and entry changes
  (`atRefuseIfPaid()`) until `plReopen()`.
- Payroll says what is **owed**; it never moves cash on its own. A salary paid out of the drawer is a cash-out the
  cashier records in the Cash book — keeping them apart is what lets them be checked against each other.

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
