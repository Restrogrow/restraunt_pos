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
| **Dashboard** | Redesigned home screen | Sales, orders and average order vs this time yesterday; open / closed now; **Needs attention** (website orders to accept, tables calling a waiter, reservations to confirm, catering requests, old kitchen tickets); orders in progress; last 7 days; busy tables; today's reservations; best sellers. Refreshes every minute. |
| **Order alerts** | Work in the background | The new-order check no longer stops when the tab is hidden. A system notification appears and the tab title flashes; clicking it opens Online Orders. One click on **Turn on order alerts** unlocks the ring and notifications on each device. |
| **Push notifications** | Actually sent now | They needed a Composer library that was never installed, so nothing was ever delivered. Now sent with PHP's own OpenSSL (VAPID + aes128gcm); keys are created automatically if `.env` has none. Tested end to end with Google's push service and Chrome. |
| **Offers** | New **All offers** page, clearer Coupons, Combos & New | One page lists every offer tool, where customers see it, whether it's on and what needs attention. Coupons show orders, sales and discount from each code in 30 days, with pause / edit / delete and filters. "Deals" (really the Menu page's Combos / New filters) is renamed and simplified. |
| **Website appearance** | Same settings, better layout | Six groups (Brand, Colours and font, Home page, Menu page, Navigation and profile, Banners and sharing), a jump bar and a save bar that stays in view. |
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
    <td width="50%"><img src="docs/screenshots/new-order-popup.jpg" alt="New order popup"><br><b>New order</b>: rings, pops up, Accept or Reject</td>
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
  <img src="docs/screenshots/order-alerts-chip.jpg" alt="Turn on order alerts" width="560">
  <br><sub>The dashboard on a phone, and the one-click “Turn on order alerts” bar</sub>
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
- **Menu**: categories, dishes (variations, add-ons, ingredients, nutrition, health tags), add-ons, inventory, photo gallery*
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
- Superadmin: restaurants, subscriptions and trials, payment links, feature switches, branch admins, menu import from photos

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
├── api/            JSON endpoints (orders, menu, payments, offers, wallet, dashboard_home, offers_studio…)
├── assets/
│   ├── css/        style.css, studio-ui.css (the shared look of the redesigned screens)
│   ├── js/         script.js plus one file per redesigned screen: dashboard-home, order-alerts,
│   │               offers-studio, settings-studio, tables-studio, areas-studio, custom-domain-studio,
│   │               loyalty-studio-*, whatsapp-admin…
│   └── guide/      Screenshots used by the user guide and this README
├── config/         DB, session, env and one helper per feature (web_push.php, whatsapp_engine.php,
│                   repeatgrow_connect.php, loyalty_engine.php…)
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
