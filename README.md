# RestroGrow

**Restaurant ordering, POS and growth platform.** Every restaurant gets its own ordering website and an admin dashboard for the menu, orders, POS, kitchen, tables, delivery, offers, loyalty, WhatsApp and marketing, plus apps for waiters, chefs, managers and riders. Many restaurants run on one installation; a superadmin manages them.

> **Team only.** This README and everything in `docs/` are private: the repository is private and the live website refuses to serve `.md` files and the `docs/` folder. Credentials, emails and setup status are in the **[admin handbook](docs/ADMIN_HANDBOOK.md)** (also at Super Admin › Tools › Admin handbook).

**Live:** [restrogrow.com](https://restrogrow.com) · **User guide:** [restrogrow.com/main/views/user-guide.html](https://restrogrow.com/main/views/user-guide.html) · **WhatsApp partner:** [repeatgrow.com](https://repeatgrow.com)

<p align="center">
  <img src="main/assets/guide/dashboardPage.jpg" alt="RestroGrow dashboard" width="900">
</p>

---

## Contents

- [What's new](#whats-new)
- [Demo restaurant (team only)](#demo-restaurant-team-only)
- [Screenshots](#screenshots)
- [Features](#features)
- [Inventory: stock, recipes, purchase orders](#inventory)
- [New-order alerts and push notifications](#new-order-alerts-and-push-notifications)
- [Guides and documents](#guides-and-documents)
- [Running it locally](#running-it-locally-xampp)
- [Configuration (.env)](#configuration-env)
- [Deploying, uptime monitoring, cron jobs and WhatsApp templates](#deploying)
- [Project structure and tech stack](#project-structure)
- [Roadmap and known limitations](#roadmap)

---

## What's new

### 18 October 2026

| | Change | What it means |
|---|---|---|
| **Access per restaurant** | Super Admin › Restaurants › **Access** button on each row | Choose exactly which dashboard sections each restaurant gets: POS billing, Kitchen (KOT), Online orders, Tables & QR, Reservations, Inventory, Customers, Staff, Payments, Reports & analytics, **Loyalty & Growth** (Loyalty Studio plus the whole Growth menu: WhatsApp, WhatsApp ordering, AI manager, segments, referrals, growth reports), Offers, Marketing, Google & SEO, Delivery, Feedback, Website design. Switch a whole section or single pages inside it, or start from a ready set (Everything, POS only, Online ordering, Loyalty & Growth only). Switched-off pages disappear from that restaurant's sidebar and home shortcuts, and their own APIs answer 403, so the Partner app is blocked too. Dashboard home, Menu, Orders, Settings and Profile are always on. Every restaurant starts with everything on. Code: `main/config/access_control.php`. |

### 17 October 2026

| | Change | What it means |
|---|---|---|
| **Security: plan payments** | Renewals can't be bought cheap | The renewal amount and length now come from Super Admin › Settings on the server (before, the page could send ₹1 for a year), and plan payments go to and are checked with **RestroGrow's own PhonePe account only** (before, a restaurant's own PhonePe keys, even test keys, were used). The popup shows the real prices from Settings. Code: `pfRenewalPrice` / `pfAssertCanRenewOnline` in `main/config/platform_engine.php`, `main/api/subscription_payment.php`, `main/api/phonepe_callback.php`. |
| **Restaurants outside India** | Basic country defaults | **WhatsApp** codes and messages go to the restaurant's own country code (+977 Nepal, +44 UK …; numbers typed with the code, 00 or a leading 0 work) instead of always +91. A **new restaurant** gets its country's currency, time zone and tax name/rate (Nepal: Rs., Asia/Kathmandu, VAT 13%); tax starts switched on only in India. **Settings › Region** picks the time zone with the country and warns restaurants still on India's clock. **PhonePe is India-only** (it takes rupees): others see "contact support" for renewal and online payments, and their customers, wallet top-ups, VIP and meal plans never reach it. What's left for other countries (time zone everywhere, Nepal VAT bills, +91 links, Google showing INR …) is [docs/IMPORTANT_TODO.md](docs/IMPORTANT_TODO.md) #6. Code: `main/config/countries.php` (`getCountryLocalDefaults`, `applyCountryDefaults`, `phoneToInternational`, `restaurantIsIndia`). |
| **Blog: design, keywords, 10 posts** | Ready to publish | The blog home and posts got a magazine design (dark hero with the cover beside the title, numbered sections, a numbered contents rail with reading progress, FAQ cards, a boxed summary, sign-up boxes). **Keyword research** from Google India's real search suggestions: 20 keywords in 4 topic groups, the first 10 posts and the rules for each, in [docs/BLOG_KEYWORD_PLAN.md](docs/BLOG_KEYWORD_PLAN.md); Keywords › *Add many at once* takes the whole plan in one paste. **10 ready-written posts** (KOT, KOT printers, Swiggy and Zomato fees, WhatsApp orders, billing software prices, dhaba profit, kitchen screens) come in as drafts with **Add 10 starter posts** (files in `main/config/blog_starters/`, blocked from the web). |
| **Bug fixes** | From the feature list's "problems found" | Reports' payment-method filter works on the Payment Methods report; Auto-Pay and two Settings switches that did nothing are hidden; Loyalty Autopilot text updated; removed `main/api/update_menu_items.php` (could wipe a menu); Super Admin **Export CSV** exports every matching restaurant with useful columns; Super Admin **Payments** has one loader with working search and status filters; Super Admin screens no longer stop at 50 restaurants; Settlements without payout keys are saved as Failed, not as paid; the broken "Database Performance Test" link is gone; `main/admin/index.php` goes to the dashboard. |
| **Emails** | Every automatic email, branded and logged | 21 kinds of email in one design: RestroGrow's for owners, the restaurant's own name, logo and phone for customers ("Spice Garden via RestroGrow", replies go to the restaurant). **Owners:** welcome, trial ending / ended, renewal, plan expired, payment received, invoices (mail button in the Invoice generator), new-device login, support replies, password resets, AI manager report by email. **Team:** new signups, contact-form and chatbot leads (+ "we got your message"). **Customers:** order confirmed, updates (takeaway / delivery), e-bill, table bookings + reminder, account welcome, password reset, loyalty gifts for customers without WhatsApp. **Restaurants:** "new order" email. Everything is queued in `email_outbox`, sent after the page answers (checkout never waits), retried 3 times. Code: `main/config/mail_engine.php`, `main/config/mail_catalog.php`, `main/cron/email_dispatch.php`. Plan and hooks: [docs/EMAIL_PLAN.md](docs/EMAIL_PLAN.md). |
| **Blog** | restrogrow.com/blog, run from Super Admin | **Super Admin › Tools › Blog**: write posts with images, cover, categories and tags; draft, publish or schedule; a live 15-point SEO score with a Google preview; *Fill with AI* for SEO fields and *Write a draft with AI* (Gemini); a **keyword plan** that shows which searches have a post and which don't. Public blog with categories, search, table of contents, related posts, share buttons, Article / Breadcrumb / FAQ structured data, RSS feed and `/sitemap-blog.xml` (submitted in Search Console; blog, app demo and FAQ pages sent for indexing). Code: `main/config/blog_engine.php`, `main/superadmin/blog.php`, `blog/`. |
| **Cashier role** | The billing counter | New staff role **Cashier**: orders (including accepting online orders), POS billing, payments, customers, tables, kitchen tickets, assigning riders and settling their cash; no reports, menu, staff or settings. Uses the manager's web screen without Reports and the Staff app's cashier layout. |
| **Delivery riders** | Riders are staff now | Switched on per restaurant in **Settings › Delivery › "We deliver with our own riders"** (off by default). New staff role **Delivery rider**: their own login on the Staff app or the website, only their deliveries, big Picked up → On the way → Delivered buttons, directions, call the customer, cash on delivery (the order is marked paid on delivery), live location on the customer's tracking page, and an "on the way" email. Owners and managers assign deliveries (order details, **Delivery › Riders**, More › Riders in both apps), see each rider's load and cash in hand, and **Settle cash**. Riders can also pick open deliveries themselves (a switch). The QR rider link stays for outside riders. Code: `main/config/rider_engine.php`, `main/api/rider_app.php`, `main/api/delivery_dispatch.php`. Plan: [docs/RIDER_PLAN.md](docs/RIDER_PLAN.md). |
| **`/demo`: every app** | All demos with screen links | One page with every app and direct links to its main screens: owner dashboard pages (`?go=admin&page=ordersPage`), customer website pages, Partner app screens (`/app-demo/app/?screen=pos`, logs in by itself), the **new Staff app browser demo** (`/staff-app-demo/app/?as=waiter&screen=tables`, on the real demo restaurant) and the customer app. Signed in to Super Admin it adds the **Super Admin app in the browser** (`/superadmin-app-web/app/`), the panel and the Email book. Super Admin › Tools › All demos. The Partner app demo is rebuilt with the latest app, and this PC uses local copies of every build so the demos work here too. |
| **Super Admin › Tools › Email book** | Every email in one place | All emails full size, grouped by owners / team / customers, with when and why each is sent and a checklist of what's left to switch sending on. Live from the sending code (`main/superadmin/email_book.php`). |
| **Super Admin › Tools › Email** | See and manage every email | **All emails:** each email as it looks (32 previews from sample data), who gets it, when and what starts it, and *Send me a test*. **Sent log:** sent / failed / waiting, view, send again. **Settings & jobs:** sending account, test email, each email on/off, and whether the cron jobs are running. |

### 16 October 2026

| | Change | What it means |
|---|---|---|
| **Demo restaurant** | Rebuilt from scratch | **Spice Garden Indiranagar** (`RESSGI002`, login `demo` / `demo`) is a full, realistic restaurant: 54 dishes in 9 menus with photos, descriptions, sizes and veg / non-veg subcategories, breakfast and thali hours, bestsellers, today's specials, 15 tables in 3 areas, 5 staff, 40 customers, about 1,200 orders over six weeks, live orders waiting / cooking / ready, waiter calls, bookings and coupons. One builder for local, live and the reset cron: `main/config/demo_restaurant.php`. [More below](#demo-restaurant-team-only). |
| **`/demo`** | One-tap demo page | Private page (not linked anywhere public, `noindex`) with buttons for the owner dashboard, manager / waiter / kitchen screens, the customer website and the app demo. `/demo?go=admin` logs straight in. |
| **Demo safety** | The shared account can't break things | No password / login / email change, payment keys, currency, uploads or rename for the demo account; WhatsApp never sends from it. Super Admin › Tools › Demo restaurant › **Reset demo** puts it back (no cron, by choice). |
| **Super Admin** | New **Tools** group | **Admin handbook** (reads `docs/ADMIN_HANDBOOK.md` and every other doc) and **Demo restaurant** (create, reset, remove, logins and links). |
| **Private docs** | `.htaccess` | `README.md`, `docs/`, every `.md`, `.sql` and `.log` file, `package.json` and the test setup are no longer served by the website (they were public at restrogrow.com/README.md). |
| **Continue with Google** | Owners, staff, customers, app | One Google sign-in for the **dashboard login** (owners and staff, matched by email; a new email lands on sign-up with name and email filled in), **every restaurant website** including restaurants on their own domain (returning customers by email; new ones confirm their mobile once with a WhatsApp code so orders, points and wallet stay on the phone), and the **Partner app 1.1.6** (Google in the browser, back to the app via `restrogrow://`). Only one redirect address is listed on the Google client: `/main/api/google_login_callback.php`. Code: `main/config/google_login.php`, `main/api/google_login.php` + `google_login_callback.php`, `main/admin/google_finish.php`, `main/website/google_finish.php`, `admin/auth.php` action `googleLogin`. Uses `GOOGLE_LOGIN_CLIENT_ID/SECRET`, else the Google Business or Search Console client already in `.env`. |
| **Partner app 1.1.5** | Branded login | The RestroGrow logo replaces the generic icon on the login and welcome screens; the login shows a preview of the app's Home screen in a phone frame above the form (hidden on short phones) and fades in smoothly. **App demo page** (`/app-demo`): pick iPhone 15 Pro, Pixel 8, Galaxy S24, a small phone or a tablet, each at its real screen size, with frame colours; the stretched logo is fixed. |
| **Partner app 1.1.4** | First launch and Home | **Welcome slides** on first launch (Skip / Next, then login). **Home header** redesigned: logo, greeting, restaurant name, date, **Open now · till …** from business hours and a bell with new orders. Fixed the restaurant name being hidden under the header on More and Settings. **Stays in sync with the website**: every screen refreshes the moment the app comes back to the front, POS picks up dishes switched off on the website within a minute, and Customers refresh too. |
| **Partner app 1.1.3** | Cleaner and complete | POS with photo dish tiles and ADD buttons, compact search everywhere, Menu as one tidy list, compact More screen with quick links to settings; the **Bluetooth printer stays connected** (reconnects when it drops or the app comes back, retries a failed print, shows Connected / Not connected); restaurant **details, business hours, ordering, delivery, tax and social links** are editable in the app (`main/api/app_settings.php` saves only the fields sent, owner or branch admin only). |

### 14 October 2026

| | Change | What it means for a restaurant |
|---|---|---|
| **Online Orders** | Redesigned, in RestroGrow orange | **Delivery / Dine-in / Takeaway** selector with counts, and a summary for the chosen type (orders, new, preparing, ready, completed, cancelled, total value, done value). Then **Ongoing / Completed / Cancelled / All** tabs and stage chips (New, Scheduled, Accepted, Preparing, Ready, Served) with counts. Every card has the same layout: customer, order number with a type chip, status, one row per dish with photo, size, add-ons, quantity and price, the time, and payment next to the total (green **Paid · UPI** or orange **To collect · Cash**). One main button (Accept + Reject, Start cooking, Mark as ready / served / completed) and round buttons for WhatsApp, Call, Print, Details, Update payment and Cancel. Takeaway shows **Ready for pickup** and **Picked up**, as on the tracking page. |
| **Orders** | Ongoing / Completed tabs | The same tabs and stage chips on the Orders page, with one consistent card per order. |
| **Update payment** | New | From an order card: how it was paid (Cash, UPI, Card, Online, Wallet) and Paid / Part paid / Not paid yet / Refunded. Only changes the payment rules allow can be picked. |
| **Pop-ups** | RestroGrow modals | Every confirm and message on the dashboard opens in one styled window (cancel order, mark paid, discard held order, saved / error messages) instead of the browser's alert box. |
| **Kitchen tickets (KOT)** | Redesigned | Ticket cards with a live **waiting time** (amber after 10 min, red after 20), table or takeaway, payment, big quantity squares the kitchen can read, notes, and one button: Start cooking → Mark as ready → Complete order. Stage chips: All, New, In kitchen, Ready. |
| **Blocked customers** | Redesigned | Numbers at a glance (blocked now, auto, by you, expired), a Mobile / IP switch, search and filters, and a clear Unblock confirm. |
| **New-order popup** | Preparing + delivery time | **Ready in** has a Preparing time and, for delivery orders, a Delivery time: 5, 10, 15, 20, 30, 45 min, − / + buttons or any number. The customer's tracking page shows **Ready around 8:45 PM** or **Arrives around 9:10 PM**. |
| **Analytics** | Rebuilt | “What to do now” tips worked out from your own orders (slowest day, busiest hour, combo ideas, dishes to push or drop, regulars who stopped coming), numbers compared with the period before, sales trend, busy-hours heatmap, menu analysis (Stars, Workhorses, Puzzles, Dogs), customers, website visitors to orders, reviews, why orders were lost, and a 7-day forecast. |
| **Campaign calendar** | Every festival and day, 5 years ahead | 51 occasions month by month with dates to 2031: the big festivals (Diwali, Dhanteras, Govardhan Puja, Bhai Dooj, Chhath, Holi, Ramadan, Eid, Bakrid, Ugadi / Gudi Padwa, Akshaya Tritiya, Onam, Navratri, Dussehra, Karva Chauth, Raksha Bandhan, Janmashtami, Ganesh Chaturthi, Guru Nanak Jayanti, Easter, Christmas…), national days and food days. Each card has an offer idea, when to post, and Create Poster / Caption / **Offer**. Filters: next 30 days, 3 months, a year or 5 years; festivals, days or food days. Moon festival dates are from the New Delhi panchang (drikpanchang.com) and the official holiday list (Ramadan, Eid and Bakrid marked as moon-dependent); Easter is calculated. Add 2032+ dates to `main/assets/js/campaign-calendar.js` before 2031 ends. |
| **POS** | Customers and loyalty at the counter | **Add customer** in the cart: search by name / phone, add a new one (name, phone, optional email and birthday) or continue without. With a customer the bill shows their **points** (and what they're worth), stamps, tier, **wallet** and their own **reward codes**; *Use points* (stepper, max), tap a reward, *Pay from wallet*; discounts come off before tax and the payment window asks only for the rest. Paid sales **earn immediately** (points / stamp / cashback, once per order: completing the order later adds nothing twice); the bill shows the customer, discounts and "You earned … · Balance …". Personal reward codes only work for their owner. **Account QR** (POS and Customers): a one-time, 14-day link to the website login (`?claim=`) where the customer adds an email and password and logs in with their points. Customers list shows points and an *Online* tag and no longer sends password hashes to the browser. Code: `assets/js/pos-customer.js`, `api/pos_customer.php`, `config/pos_loyalty.php` (table `customer_claim_codes`), `controllers/pos_operations.php`, `website/login.php` + `customer_auth.php` (claimInfo / claim). |
| **POS add-ons** | Extras at the counter | *+ Add-on* under a cart line (all units or just one, the line splits), chips with remove; an *Add-ons* section and chip in the dishes for order extras. Server checks add-on prices **and the subtotal** (it trusted the posted subtotal before), saves them like the website (dish line JSON + `order_item_addons`, order extras as 'Add-on' lines), keeps them on held orders and brings them back on resume. KOT screen, printed ticket (browser and network printer) and bill show them; order extras ride on the ticket note. Code: `assets/js/pos-addons.js`, `controllers/pos_operations.php` (`posVerifyAddons`, `posSaveOrderAddons`), `api/get_kot.php`, `assets/js/kot-studio.js`. |
| **Offline billing** | The POS bills with no internet | Pay and KOT go through `window.rgPosSend()`: with no connection the bill is queued on the device (localStorage `rgoff:<restaurant>:queue`) with a unique `pos_ref`, its time and a local number (OFF-MMDD-NNN); the bill and a kitchen ticket print at the counter. Sync is in order, automatic (back online, every 30 s, on load) or *Sync now*; the server saves each `pos_ref` once (`pos_sync_log`), keeps the offline time, marks the order `[Offline bill]` and sends no second kitchen ticket. A KOT made offline is matched to its order before its Pay. Refused bills: retry, *Open in cart* (current prices) or discard. Menu, categories, tables, add-ons and session are kept on the device; the service worker keeps the dashboard page (`rg-shell`, cleared on logout) and Google fonts. Points / wallet / rewards are off while offline. Code: `assets/js/pos-offline.js`, `controllers/pos_operations.php` (`pos_ref`, `offline_at`), `website/sw.js`. |
| **Menus by time of day** | Breakfast 7–11 am | *Hours* on a category card: days and from / to times (past midnight works), quick picks. The website shows the dishes with “Served 7–11 am” instead of Add (`is_available = 0`, `hours_label`) and `process_website_order.php` refuses them at the order or scheduled time; the POS shows the hours on the category heading but still sells. Restaurant timezone. Columns `menu.avail_days / avail_from / avail_to`. Code: `config/menu_hours.php`, `assets/js/menu-hours.js`, `controllers/menu_operations.php` (`set_hours`), `website/api.php`, `api/get_menus.php`. |
| **Activity log** | Who did what, for the owner | Staff › *Activity log*: order cancel / reject / delete (with reason), payment changes and refunds, KOT cancels, POS discounts and points, offline bills synced, price and availability edits, dish delete / hide, category delete and hours, coupons, customer delete, staff add / edit / remove, settings changes (names of fields, never keys) and logins. Filters by kind, person and dates, and search. Owner only. Table `activity_log`. Code: `config/activity_log.php` (`logActivity()`), `api/activity_log.php`, `assets/js/activity-log.js`. |
| **Marketing** | Rebuilt so owners actually use it | **Brand kit** (logo, 2 colours, headline font, phone, address, tagline, hashtags, caption language) used by every design. **Posters** in 4 steps: real-dish thumbnails, a searchable *Choose dish* window that fills in name and price, an **offer step** that attaches a coupon or creates one in place, a QR code drawn locally (no outside service) that opens the menu **with the coupon applied**, and downloads in **Square, Story (9:16) and Wide**, one or all three, with an editable caption, WhatsApp share and Save. **Videos**: Square or Story, logo, progress bar while recording, *Save as picture*. **Captions**: English, **Hinglish** or **Hindi**, emoji level, editable results, brand hashtags, tracked link. **Campaigns**: one name, link tag and offer shared by poster, video and caption; started from the Calendar's *Create campaign* or the bar on each page. **My campaigns**: saved designs with visits, orders and sales from their links and coupons; open, duplicate, delete. **Dashboard** shows the next 3 occasions under *Coming up*. Orange brand buttons throughout. Code: `main/assets/js/marketing-studio.js`, `api/marketing_studio.php`, `config/marketing_helpers.php` (tables `marketing_brand_kit`, `marketing_designs`, `marketing_order_src`, created on first use); the website keeps `?src` / `?coupon` (`website/header.php`, `cart.php`) and `process_website_order.php` records the order's campaign. |
| **Faster dashboards** | Smaller icon font | Dashboards download only the ~420 icons they use (41 KB instead of 373 KB) and the browser console stays quiet on the live site. |

For the RestroGrow team: the **superadmin panel** gained a restaurant view, analytics, renewals, coupons, leads, support tickets, announcements, invoices with GST, security and activity, platform health and bulk actions (see [Staff apps and superadmin](#staff-apps-and-superadmin)); email can go out from a different address per kind of email; and there is a public health check for uptime monitors (see [Uptime monitoring](#uptime-monitoring)).

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
    <td width="50%"><img src="main/assets/guide/onlineOrdersPage.jpg" alt="Online orders"><br><b>Online orders</b>: Delivery / Dine-in / Takeaway, numbers, one card per order</td>
    <td width="50%"><img src="main/assets/guide/kotPage.jpg" alt="Kitchen tickets"><br><b>Kitchen tickets</b>: waiting time, big quantities, one button</td>
  </tr>
  <tr>
    <td><img src="main/assets/guide/analyticsPage.jpg" alt="Analytics"><br><b>Analytics</b>: what to do now, worked out from your orders</td>
    <td><img src="main/assets/guide/blocklistPage.jpg" alt="Blocked customers"><br><b>Blocked customers</b>: numbers, search, clear unblock</td>
  </tr>
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
    <td><img src="main/assets/guide/ordersPage.jpg" alt="Orders"><br><b>Orders</b>: Ongoing / Completed tabs and one card per order</td>
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
    <td><img src="main/assets/guide/marketingPostersPage.jpg" alt="Posters"><br><b>Posters</b>: brand kit, offer with QR code, 3 sizes, caption</td>
    <td><img src="main/assets/guide/marketingCalendarPage.jpg" alt="Campaign calendar"><br><b>Campaign calendar</b>: every festival, an offer idea and when to post</td>
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
- **POS** and **KOT** (kitchen tickets), with network or browser printing; customers on the bill with points, rewards and wallet, and an Account QR for website login
- **Orders**: online orders (accept / reject / status / route), all orders, blocked customers, customers, staff, waiter requests
- **Payments**: transactions, PhonePe gateway*, sales reports with CSV, analytics
- **Marketing**: brand kit; posters and promo videos in Square, Story and Wide with a real coupon and a QR code that applies it; captions in English, Hinglish and Hindi; campaigns; campaign calendar (festivals, national days and food days with offer ideas); My campaigns with the visits, orders and sales each post brought in
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

## Demo restaurant (team only)

**Spice Garden Indiranagar** is the restaurant we show to people. Everything about it, including the logins, what's switched off and how it's reset, is in the [admin handbook](docs/ADMIN_HANDBOOK.md#2-the-demo-restaurant).

| | |
|---|---|
| One-tap page | `https://restrogrow.com/demo` (private link, share only when pitching) · local `http://localhost/menuwebsite/demo/` |
| Owner login | `demo` / `demo` |
| Staff logins | `manager.demo@`, `waiter.demo@`, `waiter2.demo@`, `chef.demo@`, `chef2.demo@spicegarden.test`, password `demo` |
| Customer website | `/spice-garden-indiranagar` (coupons `WELCOME50`, `SPICE20`, `FEAST100`, `WEEKEND15`) |
| Build or reset | Live: Super Admin › Tools › Demo restaurant › **Create / Reset demo**. Local: `php main/database/seed_demo_restaurant.php`. Reset before a pitch, so today's orders are fresh. |
| Code and data | `main/config/demo_restaurant.php`; photos in `main/assets/demo_restaurant/` (credits: `demo-credits.txt`, must stay public) |

---

## Guides and documents

| Page | What it shows |
|---|---|
| **[Admin handbook](docs/ADMIN_HANDBOOK.md)** (team only) | Links, the demo restaurant, accounts and roles, our email addresses, the Super Admin panel, every integration with what's set up and what's missing, deploy and cron jobs, feature status, the Partner app and open items. |
| **[User guide](https://restrogrow.com/main/views/user-guide.html)** ([source](main/views/user-guide.html)) | Every feature with screenshots and steps, a **What's new** section and a quick-start checklist. Start here. |
| [Website layout gallery](https://restrogrow.com/main/views/layout-gallery.html) | All customer-website designs side by side. |
| [Table map preview](https://restrogrow.com/table-map-preview.html) | The live floor-plan view. |
| [Email plan](docs/EMAIL_PLAN.md) (team only) | Every email, when it is sent, where it is hooked in, and what is needed to switch it on. See them all: Super Admin › Tools › **Email book**. |
| [Feature list](docs/FEATURES.md) (team only) | Every built feature, where to find it and what it needs (also at Super Admin › Tools › Features). |
| [Blog keyword plan](docs/BLOG_KEYWORD_PLAN.md) (team only) | The researched keywords to rank for, which are easy and why, the first 10 posts, and the block to paste into Super Admin › Blog › Keywords. |
| [Important to-do](docs/IMPORTANT_TODO.md) (team only) | The bigger features: WhatsApp AI ordering and AI manager (built), Swiggy / Zomato in the POS, custom staff access, and restaurants outside India (#6, with every gap found). |
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

Demo data:
- `php main/database/seed_demo_restaurant.php`: the demo restaurant **Spice Garden Indiranagar** (`demo` / `demo`, one-tap at `/demo/`).
- `php main/database/seed_multibranch_demo.php`: the Spice Garden chain (owner `sg_owner` / `Owner@123`, branches Koramangala and HSR Layout, plus the demo restaurant as its Indiranagar branch).
- `php main/database/seed_loyalty_demo.php [RESTAURANT_ID]` (default `RESSGI002`): loyalty, VIP, games and phone card on top.

---

## Configuration (.env)

The full list with comments is in [`main/.env.example`](main/.env.example). The ones that matter most:

| Variable | Needed for |
|---|---|
| `DB_*` | Database (Hostinger is detected automatically) |
| `PHONEPE_*` | Online payments, wallet top-ups, VIP plans (restaurants in India only). `PHONEPE_CLIENT_ID` / `PHONEPE_CLIENT_SECRET` are RestroGrow's own account: **plan renewals use only these**; without them renewal runs in demo mode, which activates nothing. |
| `REPEATGROW_CONNECT_URL`, `REPEATGROW_CLIENT_ID`, `REPEATGROW_CLIENT_SECRET` | The **Connect RepeatGrow** button (WhatsApp) |
| `WA_KEY_SECRET` | Encrypts each restaurant's RepeatGrow key. Make one with `php -r "echo base64_encode(random_bytes(32));"` and never change it. |
| `REPEATGROW_API_URL`, `REPEATGROW_API_KEY`, `REPEATGROW_GIFT_TEMPLATE` | WhatsApp OTP and RestroGrow's shared number. Numbers get the restaurant's own country code; `REPEATGROW_DEFAULT_COUNTRY_CODE` (default 91) is only used when the country is unknown. |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | Optional: push keys (generated automatically if missing) |
| `CRON_SECRET_KEY` | Running cron jobs by URL |
| `SMTP_ENABLED`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM_EMAIL`, `SMTP_FROM_NAME` | Sending email. With Google Workspace: `smtp.gmail.com`, port `587`, `tls`, the mailbox as username and a Google **app password** (not the normal password). |
| `MAIL_FROM_BILLING`, `MAIL_FROM_ALERTS`, `MAIL_FROM_ORDERS`, `MAIL_FROM_ACCOUNTS`, `MAIL_FROM_SUPPORT`, `MAIL_FROM_SALES` | Optional: which address each kind of email comes from (billing, new-order alerts to restaurants, order confirmations, password resets, support, sales). Each must be an alias of the SMTP user (Google Admin). Unset ones use `SMTP_FROM_EMAIL`. |
| `MAIL_TEAM` | Optional: where new signups and leads are emailed (default `SMTP_FROM_EMAIL`). |
| `MAIL_SINK_DIR` | Local PCs only: save every email as an .html file in this folder instead of sending it. Never set it on the live server. |
| `HEALTH_CHECK_KEY` | Optional: unlocks the detailed diagnostic page `main/admin/health_check.php` (`?key=…`). Not needed for the public health check. |
| Apple / Google Wallet keys | The phone wallet card |

---

## Deploying

Pushing to `main` deploys to restrogrow.com automatically. The code detects the Hostinger server and connects through the local MySQL socket. New tables and columns create themselves on first use (each feature has an `ensure…` function), so no manual migration is needed.

A few Super Admin pages set things up the first time they're opened. On a fresh database or a new server, open each once (all done on the live site on 17 Oct 2026; details in the [admin handbook](docs/ADMIN_HANDBOOK.md#7-server-deploy-and-cron-jobs)):

| Open | What it sets up |
|---|---|
| [Dashboard](https://restrogrow.com/main/superadmin/dashboard.php) | Database speed indexes |
| [My to-do](https://restrogrow.com/main/superadmin/todo.php) | The owner's to-do list |
| [Demo restaurant](https://restrogrow.com/main/superadmin/demo_restaurant.php) | Press **Create demo** |
| [Blog](https://restrogrow.com/main/superadmin/blog.php) | Press **Add starter posts**; paste the keyword plan in Keywords › *Add many at once* |
| [Email](https://restrogrow.com/main/superadmin/email.php) | Email settings table |

### Uptime monitoring

`https://restrogrow.com/main/api/health.php` is a public health check for UptimeRobot or any monitor. It answers **200** with `{"status":"ok"}` when the database answers, logins can be saved and the disk is fine, and **503** with `{"status":"down"}` and a short reason when something critical fails. It shows no passwords, host names, versions or paths, and accepts GET or HEAD. Add `?check=orders` to also read the orders table.

In UptimeRobot: **New monitor › Keyword**, URL `https://restrogrow.com/main/api/health.php?check=orders`, keyword `"status":"ok"`, alert when the keyword **does not exist**, every 5 minutes. Add plain HTTP(s) monitors for `https://restrogrow.com` and `https://restrogrow.com/main/admin/login.php` too.

### Cron jobs (Hostinger)

hPanel › **Advanced › Cron Jobs** › type **Custom**. `<HOME>` is the folder shown at the top of hPanel › Files › File Manager, e.g. `/home/u123456789/domains/restrogrow.com/public_html`.

| What it does | Command | When |
|---|---|---|
| WhatsApp messages, template reviews, birthday / win-back gifts, phone-card updates | `/usr/bin/php <HOME>/main/cron/whatsapp_dispatch.php` | Every 5 minutes (`*/5 * * * *`) |
| Gifts for restaurants without WhatsApp | `/usr/bin/php <HOME>/main/cron/loyalty_autopilot.php` | Daily, e.g. 9 am (`0 9 * * *`) |
| Scheduled website orders | `/usr/bin/php <HOME>/main/cron/activate_scheduled_orders.php` | Every 5 minutes |
| Meal-subscription deliveries | `/usr/bin/php <HOME>/main/cron/generate_subscription_deliveries.php` | Daily, e.g. 5 am (`0 5 * * *`) |
| Emails: trial / renewal reminders, payment receipts, gift emails, booking reminders, retries | `/usr/bin/php <HOME>/main/cron/email_dispatch.php` | Every 10 minutes (`*/10 * * * *`) |
| AI manager morning report (WhatsApp / email) | `/usr/bin/php <HOME>/main/cron/owner_brief.php` | Every 15 minutes (`*/15 * * * *`) |

Each run writes one line, which hPanel shows under the job's output. Super Admin › Tools › Email › Settings & jobs shows whether the email and morning-report jobs ran recently. They can also run as a URL (`…/whatsapp_dispatch.php?key=…`) once `CRON_SECRET_KEY` is set.

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

**AI to do** (researched, not built): smart upsell at checkout, demand forecast for prep and stock, review replies, menu helpers, phone-order agent. (WhatsApp AI ordering and the AI manager's morning report are built: [docs/IMPORTANT_TODO.md](docs/IMPORTANT_TODO.md) #1 and #2.) Details, order and sources: [docs/AI_TODO.md](docs/AI_TODO.md).

**Bigger next steps** ([docs/IMPORTANT_TODO.md](docs/IMPORTANT_TODO.md)): Swiggy / Zomato / ONDC orders in the POS (#4), custom access per staff member (#5), restaurants outside India, Nepal first (#6).

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
| Email | Google Workspace SMTP sends up to 2,000 emails a day per user; emails need SPF / DKIM / DMARC on restrogrow.com to stay out of spam; invoices go as an email, not a PDF attachment |
| Push | Needs HTTPS; on iPhone the dashboard must be added to the Home Screen; a browser that is fully closed on a computer only gets the notification when it opens again |
| Other countries | Built for India first. Outside India: no online payments (PhonePe is rupees only), most reports and timers still follow India's clock, bills are not Nepal VAT bills, and some WhatsApp links add +91. Full list: [docs/IMPORTANT_TODO.md](docs/IMPORTANT_TODO.md) #6 |
| Restaurant hours | A restaurant open past midnight refuses website orders after 12 (part of #6's time zone work) |

This is a private project. For feature requests or bug reports, contact the maintainer.
