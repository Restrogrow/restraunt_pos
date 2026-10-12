# RestroGrow — Restaurant ordering, POS and growth platform

RestroGrow gives every restaurant its own ordering website plus an admin dashboard for menu, orders, POS, kitchen, delivery, offers, loyalty, wallet and marketing, with separate apps for waiters, chefs, managers and riders. Many restaurants run on one installation; a superadmin manages them.

**Live:** [restrogrow.com](https://restrogrow.com)

## Guides and previews

| Page | What it shows |
|---|---|
| **[User guide](https://restrogrow.com/main/views/user-guide.html)** ([source](main/views/user-guide.html)) | Every feature, where to find it and how to use it, with screenshots. Marks the features a superadmin has to switch on. Start here. |
| [Website layout gallery](https://restrogrow.com/main/views/layout-gallery.html) ([source](main/views/layout-gallery.html)) | All customer-website designs side by side: home layouts, menu styles, cards, profile page designs and more. |
| [Table map preview](https://restrogrow.com/table-map-preview.html) ([source](table-map-preview.html)) | The live floor-plan view of tables. |
| [Project documentation](main/docs/PROJECT_DOCUMENTATION.html) | Technical overview of the codebase. |
| [AGENTS.md](AGENTS.md) | Architecture, routing and conventions for developers. |

## Features

### Customer website
- Mobile-first ordering site per restaurant at `restrogrow.com/{slug}` or the restaurant's own domain
- Home page with banners, categories, **Today's Specials**, **Bestsellers** and the full menu; many layouts to choose from
- Dish details with sizes, add-ons, notes, **ingredients ("What's inside")**, **nutrition** (calories and macros) and **health tags**
- Cart with **milestone offers** (auto-applied), coupons, **Complete your meal** suggestions, a note for the kitchen and a clear bill
- Checkout for dine-in, takeaway and delivery (pincode or per-km), scheduled orders, map pin for the address
- Payments: cash, PhonePe (UPI / cards), UPI QR with screenshot, **wallet**, loyalty points
- **Live order tracking** with a journey view, rider location on the map and a **scratch card** reward
- Customer accounts with OTP sign-up, a profile page (10 designs), order history, favourites and the **wallet card**
- **Rewards page** (counter QR): join, points, levels, free dishes, invite codes, redeem
- Table QR ordering, call waiter, reservations, catering requests, meal-plan subscriptions, policy pages, install as an app

### Admin dashboard
- **Dashboard** with today's numbers and a sound alert for new orders
- **Menu**: categories, dishes (variations, add-ons, ingredients, nutrition, health tags), add-ons, inventory, photo gallery*
- **Subscriptions***: tiffin / meal plans and subscribers
- **Tables**: areas, tables, QR codes, live table map, reservations
- **POS** and **KOT** (kitchen tickets)
- **Orders**: online orders (accept / reject / status / mark paid / route), all orders, blocked customers, customers, staff, waiter requests
- **Payments**: transactions, PhonePe gateway*, sales reports with CSV, analytics
- **Marketing**: posters, promo videos, social captions, campaign calendar
- **Offers**: coupons and milestone offers, deals, bestsellers, Today's Special, Complete your meal (auto / manual / both), scratch card, wallet, offer filters, catering
- **Growth › Loyalty Studio**: earn by cashback, points or a stamp card on every channel; Autopilot gifts (welcome, birthday, anniversary, win-back, level up, milestones, referrals); Games (challenges, weekly streaks, scratch card or spin wheel); a paid VIP membership; a rewards card in Apple / Google Wallet; a Performance dashboard. **Growth › WhatsApp**: every gift code sent on WhatsApp by itself, campaigns to chosen audiences with personal codes, message log. Also referrals, customer segments, growth reports, counter QR
- **Delivery**: zones (pincode / per-km), live delivery map, feedback
- **Settings** and **Website appearance** with live preview, policy pages, custom domain / embed

\* switched on per restaurant by the superadmin (see below)

### Staff apps
- Waiter, chef and manager dashboards with their own logins
- Rider page (no login) with route, status updates and live location

### Superadmin
- Create and manage restaurants, subscriptions and trials, payment links and settlements
- Turn features on per restaurant, create branch admins, import a menu from photos

## Features a superadmin switches on

| Feature | What the restaurant gets |
|---|---|
| Photo Gallery | Stock food photos in Menu › Photo Gallery |
| Meal Subscriptions | Meal plans, subscribers and the Plans page on the website |
| Payment Gateway (cash + online) | Online payments at checkout; also needed for wallet top-ups |
| Install App | An Install button on the website |
| WhatsApp Orders | Order details on WhatsApp |
| Branches | Several outlets under one owner, with branch admins |
| Menu from photos | Menu imported from photos of the printed menu |
| Subscription / trial | Whether the site accepts orders |

## What's new (October 2026)

| Feature | Details |
|---|---|
| Rewards landing page | The counter QR opens a full rewards page: join, member card, levels, free dishes, invites, redeem. Accounts and orders matched by 10-digit phone; faster loyalty queries. |
| Wallet | Top up with PhonePe (bonus on bigger top-ups), pay orders fully or partly, refund on cancel / reject, cashback on completion; wallet card and balance on the profile; owner page with stats and corrections. |
| Scratch card | Card on the tracking page after checkout; owner sets prizes, chances, per-prize minimum next order and a monthly budget; private one-time reward codes. |
| Loyalty Studio | Program type (cashback / points / stamps) earned on website, POS and table orders; personal one-time gift codes; Autopilot gifts with message templates kept for WhatsApp; challenges and streaks; spin wheel; paid VIP plans (PhonePe or wallet) with perks applied at checkout; Performance (revenue by source, return, members vs guests, what customers can still claim). Cron backstop: `main/cron/loyalty_autopilot.php`. |
| Phone wallet card | Members add the rewards card to Apple Wallet (signed .pkpass, live updates through `website/wallet_card_service.php` and APNs) or Google Wallet (Save link, updates through the Wallet REST API). Balance, level and gifts update by themselves; the QR is the member's phone for the counter. Needs the Apple / Google credentials in `.env` (see `.env.example`). |
| WhatsApp (Growth) | Gift codes from every part of the loyalty program go out on WhatsApp to opted-in customers (own RepeatGrow number with the owner's approved templates, or RestroGrow's number with one ready-made template); campaigns to audiences with a personal code each, scheduling, weekly limit per customer, quiet hours; message log with retry; revenue each message brought back. Opt-in at checkout, sign-up and the rewards page. Sends from the dashboard every minute and `main/cron/whatsapp_dispatch.php` (run every 5 minutes). Never sends from a local computer. |
| Loyalty demo | `php main/database/seed_loyalty_demo.php [RESTAURANT_ID]` switches the whole system on for the demo restaurant (default RESSGI002): Autopilot, wheel and challenges, Spice Club plans, phone card, demo birthdays; WhatsApp and demo opt-ins only on a local computer. |
| Complete your meal | Cart suggestions from the owner's picks and from real orders (often ordered together, missing drink / dessert / side), with an "unlocks the next offer" push. |
| Today's Special | Several dishes per weekday, swipeable row with chef's note, plates left and countdown. |
| Nutrition and ingredients | Calories, macros, health tags and "What's inside" on every dish. |
| Profile page | 10 designs with options for avatar, corners, stats and colours; photo gallery section. |
| Order tracking | Redesigned journey with live updates and rider card. |
| Local development | OTP shown on screen locally (no WhatsApp), map picker falls back to OpenStreetMap without a Google key, wallet top-ups and VIP payments simulated without PhonePe test keys, WhatsApp messages prepared and logged but never sent. |

## Project structure

```
main/
├── admin/          Admin login, auth, PWA manifest
├── api/            JSON endpoints (orders, menu, payments, offers, wallet, scratch card…)
├── assets/         CSS, JS (dashboard modules like wallet-admin.js), images, guide screenshots
├── config/         DB, session, env, helpers per feature (wallet_helpers.php, growth_helpers.php…)
├── controllers/    Business logic (menu, orders, staff, POS, KOT, growth)
├── database/       SQL schemas, migrations, full_database_dump.sql
├── delivery/       Rider page
├── superadmin/     Multi-restaurant management
├── views/          Dashboards (admin, manager, waiter, chef), user guide, layout gallery
└── website/        Customer website (menu, cart, track, profile, loyalty) and its scripts (rg-*.js)
```

New tables and columns create themselves on first use (each feature has an `ensure…` function), so no manual migration is needed after deploying.

## Tech stack

| Layer | Technology |
|---|---|
| Backend | PHP 8 (no framework), PDO |
| Database | MySQL / MariaDB |
| Frontend | Vanilla JavaScript, CSS |
| Payments | PhonePe (OAuth v2), UPI QR |
| Messaging | WhatsApp OTP and alerts, Web Push (VAPID), SMTP email |
| Maps | Google Maps, Leaflet / OpenStreetMap |
| Icons | Font Awesome, Material Symbols |

## Running it locally (XAMPP)

```bash
# 1. Clone into htdocs
git clone https://github.com/Restrogrow/restraunt_pos.git menuwebsite

# 2. Create the database and import main/database/full_database_dump.sql
#    (e.g. database "menuwebsite_db", user root, no password on XAMPP)

# 3. PHP dependencies (Web Push)
cd menuwebsite
php composer.phar install

# 4. Configuration: copy main/.env.example to main/.env and fill in keys

# 5. Open
#    Customer site: http://localhost/menuwebsite/{restaurant-slug}
#    Admin:         http://localhost/menuwebsite/main/admin/login.php
```

On localhost, OTP codes are shown on screen, and PhonePe runs in sandbox / simulated mode.

## Deploying

Pushing to `main` deploys to restrogrow.com automatically. The code detects the Hostinger server and connects through the local MySQL socket.

### Cron jobs (Hostinger)

Timers on the server that run a file by themselves. Without them, WhatsApp messages and phone-card updates only go out while someone has the dashboard open.

1. hPanel › **Advanced › Cron Jobs**.
2. Find your home folder: hPanel › **Files › File Manager** shows it at the top, e.g. `/home/u123456789/domains/restrogrow.com/public_html`. Below, `<HOME>` means that path.
3. Add each job: type **Custom**, paste the command, pick the timing, **Save**.

| What it does | Command | When |
|---|---|---|
| WhatsApp messages, birthday / win-back gifts, phone-card updates | `/usr/bin/php <HOME>/main/cron/whatsapp_dispatch.php` | Every 5 minutes (`*/5 * * * *`) |
| Birthday / win-back gifts for restaurants without WhatsApp | `/usr/bin/php <HOME>/main/cron/loyalty_autopilot.php` | Once a day, e.g. 9 am (`0 9 * * *`) |
| Scheduled website orders | `/usr/bin/php <HOME>/main/cron/activate_scheduled_orders.php` | Every 5 minutes (`*/5 * * * *`) |
| Meal-subscription deliveries | `/usr/bin/php <HOME>/main/cron/generate_subscription_deliveries.php` | Once a day, e.g. 5 am (`0 5 * * *`) |

Each run writes one line (e.g. "Checked 3 restaurant(s): 12 sent, 0 failed"); hPanel shows it under the job's output. They can also run as a URL (`https://restrogrow.com/main/cron/whatsapp_dispatch.php?key=…`) once `CRON_SECRET_KEY` is set in `.env`.

### WhatsApp templates (RepeatGrow)

Meta only lets a business start a WhatsApp chat with an **approved template**. A restaurant using its own number creates one template per message it uses, in RepeatGrow › Templates (category **Marketing**, language **English**). Growth › WhatsApp shows each one's exact name and text with a Copy button; `{{1}}`, `{{2}}`… are filled in for each customer (name, gift, code, last day…).

| # | Message | Needed when |
|---|---|---|
| 1 | Welcome gift | Autopilot › Welcome gift is on |
| 2 | Birthday treat | Autopilot › Birthday is on |
| 3 | Anniversary | Autopilot › Anniversary is on |
| 4 | We miss you | Autopilot › We miss you is on |
| 5 | Level up | Autopilot › Level up is on |
| 6 | Order milestone | Autopilot › Milestone is on |
| 7 | Referral gift | Autopilot › Referral gift is on (cashback / stamp programs) |
| 8 | Full stamp card | Program is a stamp card |
| 9 | Challenge done | A challenge is live (Games) |
| 10 | Weekly streak | A streak challenge is live (Games) |
| 11 | Member welcome gift | A VIP plan has a welcome gift |
| + | One per campaign wording | Each new campaign text; reuse a template for campaigns with the same text |

Restaurants on RestroGrow's number need none of these: they all use one ready-made template (`REPEATGROW_GIFT_TEMPLATE`, text in `.env.example`), created once by the RestroGrow team.

## Roadmap

Done recently: inventory, table map, customer accounts, loyalty landing page, wallet, scratch card, cart upsell, Today's Special, nutrition.

Next ideas:
- Birthday and welcome bonus points; WhatsApp points-balance messages
- Time-of-day suggestions in Complete your meal
- Group cart ("unlock the table") and a fill-the-thali milestone bar
- Visual reports with PDF / Excel export
- Multi-language customer website
- Automated backups

## Known limitations

| Area | Detail |
|---|---|
| Hosting | Shared-hosting MySQL connection limits (~150–200 at once) |
| Email | SMTP sending limits on shared hosting |
| Push | Needs HTTPS for the service worker |
| Scale | A VPS is recommended beyond about 10 busy restaurants |

This is a private project. For feature requests or bug reports, contact the maintainer.
