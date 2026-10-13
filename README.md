# RestroGrow

**Restaurant ordering, POS and growth platform.** Every restaurant gets its own ordering website and an admin dashboard for the menu, orders, POS, kitchen, tables, delivery, offers, loyalty, WhatsApp and marketing, plus apps for waiters, chefs, managers and riders. Many restaurants run on one installation; a superadmin manages them.

**Live:** [restrogrow.com](https://restrogrow.com) · **User guide:** [restrogrow.com/main/views/user-guide.html](https://restrogrow.com/main/views/user-guide.html) · **WhatsApp partner:** [repeatgrow.com](https://repeatgrow.com)

<p align="center">
  <img src="main/assets/guide/dashboardPage.jpg" alt="RestroGrow dashboard" width="900">
</p>

---

## Contents

- [What's new](#whats-new)
- [Screenshots](#screenshots)
- [Features](#features)
- [Inventory: stock, recipes, purchase orders](#inventory)
- [New-order alerts and push notifications](#new-order-alerts-and-push-notifications)
- [Guides and documents](#guides-and-documents)
- [Running it locally](#running-it-locally-xampp)
- [Configuration (.env)](#configuration-env)
- [Deploying, cron jobs and WhatsApp templates](#deploying)
- [Project structure and tech stack](#project-structure)
- [Roadmap and known limitations](#roadmap)

---

## What's new

### 13 October 2026

| | Change | What it means for a restaurant |
|---|---|---|
| **Inventory** | Rebuilt as a full stock system | **Recipes** per dish take stock out as orders are made (and put it back if an order is cancelled); cost per plate, food cost % and plates left; **reorder list** with how much to buy; **suppliers** and **purchase orders** (WhatsApp, print, receive into stock); **stock count** with differences; **activity log**; **reports** (bought, used, wasted, food cost vs sales). Purchases and wastage still go into expenses and Sales Reports. [More below](#inventory). |
| **Help & support** | New, in every dashboard | **Help & support** in the sidebar (and on How to use): send a request to the RestroGrow team and read the replies there; a red count shows new replies. Notices from RestroGrow (new features, maintenance) show as a bar at the top of the dashboard. |
| **Printing** | More reliable | Printing a KOT, bill or invoice retries when the server is briefly busy instead of failing with an error. |
| **POS** | Category-wise and faster | Category chips with counts (Starters, Main Course…), Veg / Non-veg / Egg / Drinks chips, a big search, dishes grouped under their category, a count on dishes already in the cart, cleaner cart with a big Pay. Cart, payment, KOT and hold unchanged. |
| **Reservations** | By day, one-tap actions | Day strip (today and the next days with counts, all upcoming, earlier, any date), bookings / guests / to confirm / next to arrive, status tabs, search; each booking with Confirm / Decline, Check in / No show, Complete, Assign table, Call, WhatsApp, Edit, and a Late flag. |
| **New-order popup** | Redesigned | Dish photos, live “received … ago”, order type / table / scheduled time, new or returning customer, tap-to-call, a clear payment box (collect in cash, paid online, UPI QR to check, part paid from wallet), note, bill, **Ready in** time (10–45 min or a custom number, sent with Accept), **Later**, “+N more waiting”. **Reject and Cancel ask why**: 5 common reasons + your own; preset reasons are shown politely on the customer's tracking page; only “couldn't reach the customer” (or a custom reason marked fake) counts as a strike. |
| **Live stock** | POS, KOT and website orders change stock straight away | Choose whether stock goes out when the kitchen starts an order or as soon as it's placed. Optional **sold out on POS and website** when an ingredient runs out (back on restock). **Low-stock push notifications** at each item's reorder level, and low items named on the Dashboard. **Set stock to** by hand. |
| **Payment gateway** | Redesigned | A banner says whether online payments are live, in test or missing keys; two clear choices (platform account / own PhonePe); show/hide secret; one Save; payment-method cards with icons and QR codes. |
| **Menu pages** | Same menu, cleaner pages | Tabs across Categories, Menu items, Add-ons and Inventory; dish counts on categories; numbers and a search box on Menu items; tidier dish cards; Add-ons in the same style. Nothing about how menus or dishes are saved changed. |
| **Every older page** | Same look as the new ones | Orders, Online orders, KOT, Customers, Staff, Reservations, Waiter requests, Reports, Payments, Feedback, Delivery zones and map, Profile, Analytics, Segments, Referrals, QR codes and more: light tables, one toolbar style, stat tiles, calmer order and kitchen cards, icons instead of emojis, aligned icons, and every old pop-up form with the new header. Features unchanged. |
| **Dashboard** | Redesigned home screen | Sales, orders and average order vs this time yesterday; open / closed now; **Needs attention** (website orders to accept, tables calling a waiter, reservations to confirm, catering requests, old kitchen tickets); orders in progress; last 7 days; busy tables; today's reservations; best sellers. Refreshes every minute. |
| **Order alerts** | Work in the background | The new-order check no longer stops when the tab is hidden. A system notification appears and the tab title flashes; clicking it opens Online Orders. One click on **Turn on order alerts** unlocks the ring and notifications on each device. |
| **Push notifications** | Actually sent now | They needed a Composer library that was never installed, so nothing was ever delivered. Now sent with PHP's own OpenSSL (VAPID + aes128gcm); keys are created automatically if `.env` has none. Tested end to end with Google's push service and Chrome. |
| **Offers** | New **All offers** page, clearer Coupons, Combos & New | One page lists every offer tool, where customers see it, whether it's on and what needs attention. Coupons show orders, sales and discount from each code in 30 days, with pause / edit / delete and filters. "Deals" (really the Menu page's Combos / New filters) is renamed and simplified. |
| **Website appearance** | Same settings, better layout | Six groups (Brand, Colours and font, Home page, Menu page, Navigation and profile, Banners and sharing), a jump bar and a save bar that stays in view. |
| **Marketing studio** | Posters, Videos, Captions, Calendar as one studio | Tabs across the four tools; posters and videos start with a photo from the restaurant's own menu, with “use its name and price” and upload; the preview stays in view while editing; the calendar uses icons and says when to post. |
| **Refresh fix** | Every section loads after a page refresh | 16 sections (Loyalty Studio, WhatsApp, Policy pages, Marketing, Inventory, Catering…) only loaded from a menu click and stayed empty after a refresh. Checked on all 50 sections. |

### 12 October 2026

| | Change | What it means for a restaurant |
|---|---|---|
| **Settings** | Sections and one save bar | Restaurant profile, Location, Business hours, Ordering, Delivery, Payments, Tax and bill, Social links, Region and language, Printer, Account. Fixed "open till 11:59 PM" being saved as 11:00 PM. |
| **Custom domain** | Step by step, with a real check | The exact DNS record to add, then a check that `https://<domain>/` really shows the restaurant (DNS, SSL, right site) before customers are sent there. Printed table QR codes keep their table after the redirect. |
| **Policy pages** | Redesigned, on both sites | Restaurant pages carry the logo and colours, tabs between policies, "On this page" list, contact card, print view. restrogrow.com legal pages match the main site and got the missing phone menu. |
| **Tables, Table map, Areas** | Rebuilt | Live status per table, bulk changes, safe delete, QR per table; floor map with shapes, chairs, busy timers and reservations. |
| **Connect RepeatGrow** | One-click WhatsApp setup | The owner approves on RepeatGrow; all message templates are created and followed through Meta's review. Handles revoked keys, rate limits, template conflicts and customers who reply STOP. API keys are stored encrypted. |

<details>
<summary><b>Earlier in October 2026</b></summary>

| Feature | Details |
|---|---|
| Rewards landing page | The counter QR opens a full rewards page: join, member card, levels, free dishes, invites, redeem. |
| Wallet | Top up with PhonePe (bonus on bigger top-ups), pay fully or partly, refund on cancel / reject, cashback; owner page with stats and corrections. |
| Scratch card | On the tracking page after checkout; prizes, chances, minimum next order and a monthly budget. |
| Loyalty Studio | Cashback / points / stamps on every channel; Autopilot gifts; challenges, streaks, spin wheel; paid VIP plans; Performance. |
| Phone wallet card | Apple Wallet and Google Wallet rewards card that updates by itself. |
| WhatsApp (Growth) | Gift codes and campaigns on WhatsApp through RepeatGrow, with a message log and revenue per message. |
| Complete your meal, Today's Special, nutrition, profile designs, order tracking | See the user guide. |

</details>

---

## Screenshots

<table>
  <tr>
    <td width="50%"><img src="main/assets/guide/dashboardPage.jpg" alt="Dashboard"><br><b>Dashboard</b>: today at a glance and what needs attention</td>
    <td width="50%"><img src="docs/screenshots/new-order-popup.jpg" alt="New order popup"><br><b>New order</b>: photos, payment, bill, ready-in time, reasons</td>
  </tr>
  <tr>
    <td><img src="main/assets/guide/inventoryPage.jpg" alt="Inventory overview"><br><b>Inventory</b>: stock value, what to reorder, what orders use</td>
    <td><img src="main/assets/guide/iv_recipes.jpg" alt="Recipes"><br><b>Recipes</b>: cost per plate, food cost %, plates left</td>
  </tr>
  <tr>
    <td><img src="main/assets/guide/iv_orders.jpg" alt="Purchase orders"><br><b>Purchase orders</b>: send on WhatsApp, receive into stock</td>
    <td><img src="main/assets/guide/iv_reports.jpg" alt="Inventory reports"><br><b>Inventory reports</b>: bought, used, wasted, food cost</td>
  </tr>
  <tr>
    <td><img src="main/assets/guide/menuItemsPage.jpg" alt="Menu items"><br><b>Menu items</b>: numbers, search, cleaner dish cards</td>
    <td><img src="main/assets/guide/ordersPage.jpg" alt="Orders"><br><b>Orders</b> and the other older pages in the new look</td>
  </tr>
  <tr>
    <td><img src="main/assets/guide/offersHomePage.jpg" alt="All offers"><br><b>All offers</b>: every offer tool and whether it's on</td>
    <td><img src="main/assets/guide/couponsPage.jpg" alt="Coupons"><br><b>Coupons</b>: what each code brought in</td>
  </tr>
  <tr>
    <td><img src="main/assets/guide/settingsPage.jpg" alt="Settings"><br><b>Settings</b>: sections and one save bar</td>
    <td><img src="main/assets/guide/websiteThemePage.jpg" alt="Website appearance"><br><b>Website appearance</b>: live preview, grouped settings</td>
  </tr>
  <tr>
    <td><img src="main/assets/guide/tablesPage.jpg" alt="Tables"><br><b>Tables</b>: live status, bulk changes, QR codes</td>
    <td><img src="main/assets/guide/tableMapPage.jpg" alt="Table map"><br><b>Table map</b>: who is seated and for how long</td>
  </tr>
  <tr>
    <td><img src="main/assets/guide/marketingPostersPage.jpg" alt="Posters"><br><b>Posters</b>: pick a dish from your menu, download</td>
    <td><img src="main/assets/guide/marketingCalendarPage.jpg" alt="Campaign calendar"><br><b>Campaign calendar</b>: what to promote and when to post</td>
  </tr>
  <tr>
    <td><img src="main/assets/guide/embedPage.jpg" alt="Custom domain"><br><b>Custom domain</b>: DNS record and a real check</td>
    <td><img src="main/assets/guide/growthSettingsPage.jpg" alt="Loyalty Studio"><br><b>Loyalty Studio</b>: program, autopilot, games, VIP</td>
  </tr>
  <tr>
    <td><img src="docs/screenshots/policy-page-restaurant.jpg" alt="Restaurant policy page"><br><b>Policy pages</b> on a restaurant's website</td>
    <td><img src="docs/screenshots/policy-page-restrogrow.jpg" alt="restrogrow.com legal page"><br><b>Legal pages</b> on restrogrow.com</td>
  </tr>
</table>

<p align="center">
  <img src="docs/screenshots/dashboard-phone.jpg" alt="Dashboard on a phone" width="260">
  &nbsp;&nbsp;
  <img src="docs/screenshots/inventory-phone.jpg" alt="Inventory on a phone" width="260">
  &nbsp;&nbsp;
  <img src="docs/screenshots/order-alerts-chip.jpg" alt="Turn on order alerts" width="560">
  <br><sub>The dashboard and Inventory on a phone, and the one-click “Turn on order alerts” bar</sub>
</p>

---

## Features

### Customer website
- Mobile-first ordering site per restaurant at `restrogrow.com/{slug}` or the restaurant's own domain
- Home page with banners, categories, **Today's Specials**, **Bestsellers** and the full menu; many layouts ([layout gallery](https://restrogrow.com/main/views/layout-gallery.html))
- Dish details with sizes, add-ons, notes, ingredients, nutrition and health tags
- Cart with **milestone offers**, coupons, **Complete your meal** suggestions and a clear bill
- Dine-in, takeaway and delivery (pincode or per-km), scheduled orders, map pin for the address
- Payments: cash, PhonePe (UPI / cards), UPI QR with screenshot, wallet, loyalty points
- **Live order tracking** with rider location and a **scratch card** reward
- Customer accounts (OTP), profile page (10 designs), order history, favourites, phone **wallet card**
- **Rewards page** from the counter QR; table QR ordering, call waiter, reservations, catering, meal plans
- Policy pages in the restaurant's own look; install as an app

### Admin dashboard
- **Dashboard**: today vs yesterday, needs attention, orders in progress, 7-day chart, floor, reservations, best sellers
- **New-order alerts**: ring, popup, background notifications on computer and phone
- **Menu**: categories, dishes (variations, add-ons, ingredients, nutrition, health tags), add-ons, photo gallery*
- **Inventory**: stock with reorder levels and use-by dates, recipes that take stock out as orders are made, food cost per dish, suppliers, purchase orders (WhatsApp / print / receive), stock count, activity log, reports
- **Tables**: areas, tables, QR codes, live table map, reservations
- **POS** and **KOT** (kitchen tickets), with network or browser printing
- **Orders**: online orders (accept / reject / status / route), all orders, blocked customers, customers, staff, waiter requests
- **Payments**: transactions, PhonePe gateway*, sales reports with CSV, analytics
- **Marketing**: posters, promo videos, social captions, campaign calendar
- **Offers**: All offers overview, coupons and milestone offers, offer filters, Combos & New, bestsellers, Today's Special, Complete your meal, scratch card, wallet, catering
- **Growth**: Loyalty Studio (cashback / points / stamps, Autopilot gifts, games, VIP, phone card, performance) and WhatsApp (automatic gift messages and campaigns through RepeatGrow), referrals, segments, growth reports
- **Delivery**: zones, live delivery map, feedback
- **Customer website**: settings, website appearance with live preview, policy pages, custom domain / embed

\* switched on per restaurant by the superadmin

### Staff apps and superadmin
- Waiter, chef and manager dashboards with their own logins and push notifications; rider page (no login) with route and live location
- Superadmin: restaurants, subscriptions and trials, payment links, feature switches, branch admins, menu import from photos, plus:
  - **Restaurant view**: click a restaurant for its plan, sales (today, month, all time), 14-day orders, payments with invoices, setup, WhatsApp status, support requests, errors and logins, with quick actions
  - **Analytics**: monthly recurring revenue (estimate), paying vs trial, trial to paid, restaurants lost, 12-month charts, top restaurants and the ones that stopped taking orders
  - **Renewals**: plans and trials ending soon or just ended, with Payment link, WhatsApp reminder (opens WhatsApp with the text ready) and Extend
  - **Coupons** of every restaurant; **Leads** from the website contact form (stages, notes, follow-up dates, create restaurant); **Support** inbox; **Announcements** to all or chosen restaurants (scheduled, closable or not)
  - **Invoices & GST**: an invoice per subscription payment (print / save as PDF) and a monthly CSV for the accountant; business details and GSTIN in Settings
  - **Security & activity**: logins, wrong-password bursts, new devices, blocked IPs (unblock), and a log of every change made in the superadmin panel
  - **Platform health**: database and uploads size, disk, errors, WhatsApp queue, push devices, backups
  - **Bulk actions** on Restaurants: tick several, then switch a feature, extend, enable / disable, or send them an announcement
  - Settings now save; new restaurants get the trial length set there
  - New tables (sa_settings, sa_audit_log, sa_announcements, sa_announcement_dismiss, support_tickets, support_messages; extra columns on contact_queries) create themselves on first use

| Feature a superadmin switches on | What the restaurant gets |
|---|---|
| Photo Gallery | Stock food photos in Menu › Photo Gallery |
| Meal Subscriptions | Meal plans, subscribers and the Plans page on the website |
| Payment Gateway (cash + online) | Online payments at checkout; also needed for wallet top-ups |
| Install App | An Install button on the website |
| WhatsApp Orders | Order details on WhatsApp |
| Branches | Several outlets under one owner, with branch admins |
| Menu from photos | Menu imported from photos of the printed menu |
| Subscription / trial | Whether the site accepts orders |

---

## Inventory

Menu › Inventory has eight tabs: **Overview, Stock, Recipes, Purchase orders, Suppliers, Stock count, Activity, Reports**.

| Tab | What the restaurant does there |
|---|---|
| Overview | Stock value, items to reorder, money spent and wasted this month, stock used by orders in 30 days; the reorder list, use-by dates, what orders use most, latest movements; the switch **Take stock out when orders are made**. |
| Stock | Items with category, supplier, code and use-by date; a level bar against the reorder level; **Restock** (adds an *Inventory Purchase* expense), **Wastage** with a reason (adds an *Inventory Wastage* expense) or **Correction**; search, filters, CSV export. |
| Recipes | Per dish: how much of each item one plate uses (g / kg, ml / L, pieces). Shows cost per plate, food cost %, what you keep and plates you can still make. |
| Purchase orders | Drafts made from the reorder list (one per supplier, up to each item's *Order up to* level), WhatsApp link with the order typed in, print, **Receive** (stock + expense for what actually came). |
| Suppliers | Contacts, the items bought from each, open orders, spend in 90 days. |
| Stock count | Type what's on the shelf; the difference is logged per item and the stock set to the count. |
| Activity / Reports | Every movement with filters and export; bought, used, wasted, count differences and food cost (stock used ÷ sales) for 7 / 30 / 90 days, used per day, top items and suppliers. |

**How orders use stock** (`main/config/inventory_engine.php`): each dish's recipe × quantity is taken out of stock once per order (`inventory_order_usage`), either when the kitchen starts it (*Preparing, Ready, Served, Completed*) or, if the owner chooses, as soon as it is placed (*Pending, Accepted* too). Dishes added to or removed from an order later change stock by the difference. If the order is *Cancelled*, *Rejected* or deleted, the stock goes back. Only orders placed after the first recipe was saved count, so old orders are never taken twice.

**Live, from every order path:** `invKick()` runs `invRefresh()` right after the request has committed, from the POS (`pos_operations.php`), KOT (`kot_operations.php`), website orders (`process_website_order.php`), every status change (`order_state_machine.php`) and deleting an order. `invRefresh()` = sales sync + **sold out**: with the setting on, a dish whose recipe can't be made is switched to unavailable on the POS and website (`inventory_auto_off`) and back on after a restock; a dish the owner switches back on by hand is left on + **low-stock alerts**: an item at or below its *Reorder when below* level, or out, sends one push notification (`sendPushNotification`, opening `dashboard.php?page=inventoryPage`) and is armed again once restocked. Low items are also listed under *Needs attention* on the Dashboard.

**By hand:** *Set stock to*, *Add / remove*, *Wastage*, *Restock*, *Stock count*. Every change is in Activity.

**Tables** (created by themselves): `inventory_items` (+ supplier, reorder-up-to level, code, use-by), `inventory_transactions` (+ kinds sale / return / count / opening and what caused them), `inventory_recipes`, `inventory_suppliers`, `inventory_purchase_orders`, `inventory_po_lines`, `inventory_order_usage`, `inventory_settings`. API: `main/api/inventory_studio.php`; screen: `main/assets/js/inventory-studio.js`.

---

## New-order alerts and push notifications

| Situation | What happens |
|---|---|
| Dashboard open and in front | The order rings like a phone call and a popup shows customer, address, items and total with **Accept / Reject**. |
| Dashboard in another tab or minimised | It keeps checking for orders; a system notification appears (“New order #1042 · ₹450 · Delivery · Priya”) and the tab title flashes. |
| Browser closed or phone locked | A Web Push notification from the server (needs notifications allowed once on that device). Clicking it opens the dashboard on Online Orders. |

- **Turning it on:** the dashboard shows a **Turn on order alerts** bar until the owner clicks it once per device. Browsers only allow sound after a click, and notifications only after permission. Per device it can be switched off in **Settings › Account › Push notifications**.
- **How push is sent:** `main/config/web_push.php` signs (VAPID, RFC 8292) and encrypts (aes128gcm, RFC 8291) with PHP's OpenSSL. No Composer or vendor folder is needed. `main/config/push_notification.php` sends to the owner, kitchen and waiters, and forgets subscriptions the browser has dropped.
- **Keys:** `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` in `.env` if you have them. Otherwise a key pair is generated on first use and kept in the `push_vapid` table.
- **Requirements:** HTTPS (or localhost). On iPhone, notifications need the dashboard added to the Home Screen.

---

## Guides and documents

| Page | What it shows |
|---|---|
| **[User guide](https://restrogrow.com/main/views/user-guide.html)** ([source](main/views/user-guide.html)) | Every feature with screenshots and steps, a **What's new** section and a quick-start checklist. Start here. |
| [Website layout gallery](https://restrogrow.com/main/views/layout-gallery.html) | All customer-website designs side by side. |
| [Table map preview](https://restrogrow.com/table-map-preview.html) | The live floor-plan view. |
| [AGENTS.md](AGENTS.md) | Architecture, routing and conventions for developers. |
| [Connect RepeatGrow](docs/REPEATGROW_CONNECT.md) | How RestroGrow and RepeatGrow link up, which errors are handled and how. |
| [Project documentation](main/docs/PROJECT_DOCUMENTATION.html) | Technical overview of the codebase. |

---

## Running it locally (XAMPP)

```bash
# 1. Clone into htdocs
git clone https://github.com/Restrogrow/restraunt_pos.git menuwebsite

# 2. Create the database and import main/database/full_database_dump.sql
#    (database "menuwebsite_db", user root, no password on XAMPP)

# 3. Configuration: copy main/.env.example to main/.env and fill in what you need

# 4. Open
#    Customer site: http://localhost/menuwebsite/{restaurant-slug}
#    Admin:         http://localhost/menuwebsite/main/admin/login.php
```

No Composer step is needed. On a local computer, by design:
- OTP codes are shown on screen;
- PhonePe runs in sandbox / simulated mode;
- WhatsApp messages are prepared and logged but never sent, and RepeatGrow is only called if it runs on localhost;
- the map falls back to OpenStreetMap without a Google key.

Demo data for the loyalty features: `php main/database/seed_loyalty_demo.php [RESTAURANT_ID]` (default `RESSGI002`).

---

## Configuration (.env)

The full list with comments is in [`main/.env.example`](main/.env.example). The ones that matter most:

| Variable | Needed for |
|---|---|
| `DB_*` | Database (Hostinger is detected automatically) |
| `PHONEPE_*` | Online payments, wallet top-ups, VIP plans |
| `REPEATGROW_CONNECT_URL`, `REPEATGROW_CLIENT_ID`, `REPEATGROW_CLIENT_SECRET` | The **Connect RepeatGrow** button (WhatsApp) |
| `WA_KEY_SECRET` | Encrypts each restaurant's RepeatGrow key. Make one with `php -r "echo base64_encode(random_bytes(32));"` and never change it. |
| `REPEATGROW_API_URL`, `REPEATGROW_API_KEY`, `REPEATGROW_GIFT_TEMPLATE` | WhatsApp OTP and RestroGrow's shared number |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | Optional: push keys (generated automatically if missing) |
| `CRON_SECRET_KEY` | Running cron jobs by URL |
| Apple / Google Wallet keys | The phone wallet card |

---

## Deploying

Pushing to `main` deploys to restrogrow.com automatically. The code detects the Hostinger server and connects through the local MySQL socket. New tables and columns create themselves on first use (each feature has an `ensure…` function), so no manual migration is needed.

### Cron jobs (Hostinger)

hPanel › **Advanced › Cron Jobs** › type **Custom**. `<HOME>` is the folder shown at the top of hPanel › Files › File Manager, e.g. `/home/u123456789/domains/restrogrow.com/public_html`.

| What it does | Command | When |
|---|---|---|
| WhatsApp messages, template reviews, birthday / win-back gifts, phone-card updates | `/usr/bin/php <HOME>/main/cron/whatsapp_dispatch.php` | Every 5 minutes (`*/5 * * * *`) |
| Gifts for restaurants without WhatsApp | `/usr/bin/php <HOME>/main/cron/loyalty_autopilot.php` | Daily, e.g. 9 am (`0 9 * * *`) |
| Scheduled website orders | `/usr/bin/php <HOME>/main/cron/activate_scheduled_orders.php` | Every 5 minutes |
| Meal-subscription deliveries | `/usr/bin/php <HOME>/main/cron/generate_subscription_deliveries.php` | Daily, e.g. 5 am (`0 5 * * *`) |

Each run writes one line, which hPanel shows under the job's output. They can also run as a URL (`…/whatsapp_dispatch.php?key=…`) once `CRON_SECRET_KEY` is set.

### WhatsApp templates (RepeatGrow)

WhatsApp messages go through **RepeatGrow** ([repeatgrow.com](https://repeatgrow.com)).
- **With Connect RepeatGrow** (Growth › WhatsApp): the owner logs in to RepeatGrow and clicks Allow. RestroGrow then creates every template and follows Meta's review by itself. Contract: [docs/REPEATGROW_CONNECT.md](docs/REPEATGROW_CONNECT.md).
- **Without it:** templates are created by hand in RepeatGrow › Templates (category Marketing, language English), one per message in use. These are Welcome gift, Birthday, Anniversary, We miss you, Level up, Order milestone, Referral gift, Full stamp card, Challenge done, Weekly streak and Member welcome gift, plus one per campaign wording. Growth › WhatsApp shows each template's exact name and text with a Copy button.
- **On RestroGrow's shared number:** restaurants need none of these, because one ready-made template (`REPEATGROW_GIFT_TEMPLATE`) covers them.

---

## Project structure

```
main/
├── admin/          Login, auth, admin service worker, catering page
├── api/            JSON endpoints (orders, menu, payments, offers, wallet, dashboard_home, offers_studio, inventory_studio…)
├── assets/
│   ├── css/        style.css, studio-ui.css (the shared look of the redesigned screens)
│   ├── js/         script.js plus one file per redesigned screen: dashboard-home, order-alerts,
│   │               offers-studio, settings-studio, tables-studio, areas-studio, custom-domain-studio,
│               inventory-studio, menu-studio, marketing-studio,
│   │               loyalty-studio-*, whatsapp-admin…
│   └── guide/      Screenshots used by the user guide and this README
├── config/         DB, session, env and one helper per feature (web_push.php, whatsapp_engine.php,
│                   repeatgrow_connect.php, loyalty_engine.php, inventory_engine.php…)
├── controllers/    Business logic (menu, orders, staff, POS, KOT, coupons)
├── cron/           Timed jobs (WhatsApp, loyalty, scheduled orders, subscriptions)
├── database/       SQL schemas, migrations, demo seeds, full_database_dump.sql
├── delivery/       Rider page
├── superadmin/     Multi-restaurant management
├── views/          Dashboards (admin, manager, waiter, chef), user guide, layout gallery
└── website/        Customer website (menu, cart, track, profile, loyalty, policy pages) and its service worker
docs/               RepeatGrow contract, audits, README screenshots
```

### Tech stack

| Layer | Technology |
|---|---|
| Backend | PHP 8 (no framework, no Composer dependencies), PDO |
| Database | MySQL / MariaDB |
| Frontend | Vanilla JavaScript and CSS; Material Symbols icons |
| Payments | PhonePe (OAuth v2), UPI QR |
| Messaging | WhatsApp through RepeatGrow, Web Push (VAPID + aes128gcm with OpenSSL), SMTP email |
| Maps | Google Maps, Leaflet / OpenStreetMap |

---

## Roadmap

**AI to do** (researched, not built): smart upsell at checkout, WhatsApp ordering assistant, demand forecast for prep and stock, review replies, weekly owner summary, menu helpers, phone-order agent. Details, order and sources: [docs/AI_TODO.md](docs/AI_TODO.md).

Ideas for next:
- Birthday and welcome bonus points; WhatsApp points-balance messages
- Time-of-day suggestions in Complete your meal
- Group cart ("unlock the table") and a fill-the-thali milestone bar
- Reports with PDF / Excel export
- Multi-language customer website
- Automated backups

## Known limitations

| Area | Detail |
|---|---|
| Hosting | Shared-hosting MySQL connection limits (~150–200 at once); a VPS is recommended beyond about 10 busy restaurants |
| Email | SMTP sending limits on shared hosting |
| Push | Needs HTTPS; on iPhone the dashboard must be added to the Home Screen; a browser that is fully closed on a computer only gets the notification when it opens again |

This is a private project. For feature requests or bug reports, contact the maintainer.
