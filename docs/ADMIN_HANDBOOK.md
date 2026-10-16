# RestroGrow admin handbook

**Private: for the RestroGrow team only.** It's in the private GitHub repo and in **Super Admin › Tools › Admin handbook**. The public website can't serve it: `.htaccess` blocks every `.md` file and the `docs/` folder. No passwords, keys or tokens go in this file. It only says where each one is set.

Last updated: 16 October 2026.

---

## 1. Quick links

| What | Live | Local (XAMPP) |
|---|---|---|
| Marketing website | https://restrogrow.com | http://localhost/menuwebsite/ |
| Restaurant admin login | https://restrogrow.com/main/admin/login.php | http://localhost/menuwebsite/main/admin/login.php |
| Super Admin | https://restrogrow.com/main/superadmin/login.php | http://localhost/menuwebsite/main/superadmin/login.php |
| **One-tap demo (private link)** | **https://restrogrow.com/demo** | http://localhost/menuwebsite/demo/ |
| Demo restaurant's website | https://restrogrow.com/spice-garden-indiranagar | http://localhost/menuwebsite/spice-garden-indiranagar |
| Partner app in the browser (public, linked from the homepage) | https://restrogrow.com/app-demo/ | http://localhost/menuwebsite/app-demo/ |
| Health check (for UptimeRobot) | https://restrogrow.com/main/api/health.php?check=orders | |
| GitHub (private) | https://github.com/Restrogrow/restraunt_pos (branch `main`) | |
| Hosting | Hostinger hPanel (database `u509616587_restrogrow`) | XAMPP, database `menuwebsite_db` (root, no password) |

---

## 2. The demo restaurant

**Spice Garden Indiranagar** (restaurant ID `RESSGI002`) is the restaurant we show to people. It's a full, realistic restaurant:
- 54 dishes in 9 menus, with photos, descriptions, calories and sizes. Breakfast is served 8:00–11:30 and thalis at lunch only.
- Veg and non-veg subcategories, bestsellers with offer prices, and today's specials.
- 15 tables in Ground Floor, Rooftop and Private Dining. 5 staff and 40 customers.
- About 1,200 orders over six weeks (busier at weekends, slowly growing).
- **Live orders right now**: 2 new website orders waiting to be accepted, food cooking, orders ready, and tables served and calling the waiter.
- Upcoming table bookings and 4 coupons.
- GST 5% with a GSTIN on the bill, delivery by distance (₹8 per km, 7 km radius), minimum order ₹199 and packaging ₹20.

### Logins (password for all: `demo`)

| Who | Username / email | Opens |
|---|---|---|
| Owner | `demo` | Full admin dashboard |
| Manager | `manager.demo@spicegarden.test` | Manager dashboard |
| Waiters | `waiter.demo@spicegarden.test`, `waiter2.demo@spicegarden.test` | Waiter dashboard (calls, active orders, POS) |
| Kitchen | `chef.demo@spicegarden.test`, `chef2.demo@spicegarden.test` | Chef dashboard (KOT screen) |
| Coupons to try on the website | `WELCOME50`, `SPICE20`, `FEAST100`, `WEEKEND15` | |

### Showing the demo
- **`/demo`** is a private page with one button each for the owner dashboard, staff screens, customer website and app demo. Nobody needs to type anything.
- `/demo?go=admin` (or `manager`, `waiter`, `chef`) logs straight in. This is handy as a link in a WhatsApp message.
- `/demo` is **not linked from any public page**, and it's marked `noindex`, so search engines won't show it. Share it only with people you're pitching to.

### Keeping it clean
- Anyone with the link can log in, so the demo account **can't** change its password, login or email, payment-gateway keys, currency or timezone, upload a logo or QR, or rename the restaurant (the name is the website address).
- **WhatsApp never sends** from the demo restaurant. Sends report success but nothing goes out (`waSend()` in `main/config/whatsapp_engine.php`).
- Everything else (orders, menu, settings) visitors can change. A reset undoes it:
  - **Super Admin › Tools › Demo restaurant › Reset demo** (about 10–30 seconds).
  - Press **Reset demo** before showing it to someone too: that also makes "today" fresh (new orders waiting, food cooking). No cron is used for this, by choice. `main/cron/reset_demo_restaurant.php` exists if that ever changes.
  - Local: `php main/database/seed_demo_restaurant.php`.
- All of this is one file: `main/config/demo_restaurant.php`. The menu data, the logins and what the demo is allowed to do are all there.
- Photos: `main/assets/demo_restaurant/` (Wikimedia Commons, credits in `/demo-credits.txt`, which must stay public because of the CC licences).
- **First time on the live server:** after deploying, open Super Admin › Tools › Demo restaurant and press **Create demo**. It also removes the old "Spice Garden Demo" (`RESDEMO01`) if that was created earlier.

---

## 3. Accounts and roles

| Type | Stored in | Logs in at | Lands on |
|---|---|---|---|
| Super Admin | `super_admins` | `/main/superadmin/login.php` | Super Admin dashboard |
| Restaurant owner | `users` (one row per restaurant) | `/main/admin/login.php` | `main/views/dashboard.php` |
| Multi-branch owner | `branch_admins` + `branch_restaurant_links` | same login | `main/views/branches.php` |
| Staff (Manager, Waiter, Chef) | `staff`, log in by email or phone | same login | manager, waiter or chef dashboard |
| Delivery rider | none (QR link) | `main/delivery/rider-page.php` | |
| Customer | `customers`, WhatsApp OTP | `/{restaurant-slug}/login` | restaurant website |
| Partner app | owner or staff login | Android app "Restrogrow Partner" | |

- **Super Admin accounts:** no screen creates them. Add a row to `super_admins` with a `password_hash()` bcrypt hash. The local database has one, `sujay`. Check the live one in phpMyAdmin.
- **Forgot password (Super Admin):** the reset link always goes to **sujaysarraf55@gmail.com** (hard-coded in `main/superadmin/forgot_password.php`) and lasts 5 minutes.

### Local test logins (local database only, from the seed scripts)

| Login | Password | What |
|---|---|---|
| `sg_owner` | `Owner@123` | Spice Garden chain owner: Koramangala, HSR Layout and the Indiranagar demo |
| `sg_koramangala`, `sg_hsr` | `Branch@123` | Branch admins |
| `<manager\|waiter\|chef>.<kor\|hsr>@spicegarden.test` | `Staff@123` | Staff at those branches |
| `demo` | `demo` | The demo restaurant (§2) |
| `admin` | see local setup | Old "Demo Restaurant" (`RES001`) from the schema seed |

Re-create everything with `php main/database/seed_multibranch_demo.php` (this also rebuilds the demo restaurant and links it to `sg_owner`). Loyalty demo data: `php main/database/seed_loyalty_demo.php RESSGI002`.

---

## 4. Emails and contacts

| Address | Used for |
|---|---|
| **restrogrow@gmail.com** | Public contact on the website (footer, contact section, about and policy pages, Google structured data). Default sender in `main/config/email_config.php`. Fallback restaurant email on policy pages. Shown in the chatbot's error message and in the "send login details" WhatsApp text in Super Admin. |
| **sujaysarraf55@gmail.com** | Super Admin password-reset links (hard-coded) |
| **teamrestrogrow@gmail.com** | Team Google account. The Google Cloud organisation for Google Business is `teamrestrogrow-org` (project `restrogrow-gmb`). |
| asfluencernandkishor@gmail.com | Default "To" in Super Admin › Settings › Email check (test sender) |
| admin@restrogrow.com | Web-push VAPID "subject" default (`VAPID_SUBJECT`). Not a real inbox. |
| demo@restrogrow.invalid | Email of the demo restaurant (a deliberately undeliverable address) |
| Phone on the website | +91 63775 68749 |

**Manage everything in Super Admin › Tools › Email:**
- **All emails:** every email (32 previews of 21 types) as it really looks, who gets it, when and what starts it, how many went out in 30 days, and **Send me a test** for any of them. Type your address once at the top.
- **Sent log:** every email sent, failed or waiting, with View and Send again (reset links are never kept).
- **Settings & jobs:** the sending account, a plain test email, each email type on/off, and whether the two scheduled jobs (cron) are running (green "Running" means they ran in the last 30 / 45 minutes).

**How email is sent:**
- Google Workspace SMTP (`smtp.gmail.com:587`, TLS) with an App Password, through the built-in client in `main/config/email_config.php`. Limit: 2,000 emails a day per Workspace user.
- `main/config/mail_engine.php` makes every email: one branded design (RestroGrow for owners; the restaurant's name, logo and phone for customers, sent as "Spice Garden via RestroGrow" with replies going to the restaurant).
- Every email goes into the `email_outbox` table and is sent seconds later, after the page has answered, so checkout never waits. Failed ones are retried 3 times (after 10, 20, 30 minutes).
- Date-based emails (trial ending, renewal, payment received, gift emails, booking reminders) are made by an hourly check: the email cron, or any open dashboard if the cron isn't there.
- Different emails can come from different addresses via `MAIL_FROM_BILLING`, `MAIL_FROM_ALERTS`, `MAIL_FROM_ORDERS`, `MAIL_FROM_ACCOUNTS`, `MAIL_FROM_SUPPORT` and `MAIL_FROM_SALES` (each must be an alias of the SMTP user in Google Admin). `MAIL_TEAM` is where new leads and signups go.
- On a local PC, `MAIL_SINK_DIR=<folder>` in `.env` saves emails as .html files instead of sending them.
- The full list of emails and their timing is in `docs/EMAIL_PLAN.md` and on the All emails tab.

**Who gets what (short version):**
- Owners: welcome, trial and renewal reminders, payment received, invoices, new-device login, support replies, password resets, optional morning report.
- RestroGrow team: new signups, contact-form and chatbot leads. Contact-form visitors get "we got your message".
- Customers: order confirmed, order updates (takeaway / delivery), e-bill, table bookings, account welcome, password reset, loyalty gifts (only if they don't get WhatsApp).
- The restaurant: a "new order" email for every order.

---

## 5. Super Admin panel

Sidebar groups, and what each page does:
- **Overview:**
  - Dashboard: totals and recent activity.
  - Analytics: MRR estimate, paying vs trial, trial-to-paid, churn, 12-month charts.
- **Restaurants:**
  - Restaurants: search, detail drawer, feature switches per restaurant, bulk actions, create a restaurant and send its login on WhatsApp.
  - Renewals: plans ending soon, payment link, WhatsApp reminder, extend.
  - Menus from photos: Gemini reads a photo of a paper menu.
  - Branch admins, and Coupons across all restaurants.
- **Growth:**
  - Leads: website contact form, with stages and notes.
  - Chat leads: from the website's AI chatbot.
  - Support tickets, and Announcements to restaurants.
- **Money:** Subscriptions, Payments, Payment links (PhonePe), Settlements, Invoices & GST (monthly CSV) and the Invoice generator.
- **System:**
  - Security & activity: logins, blocked IPs, audit log.
  - Platform health: database, disk, WhatsApp queue, backups.
  - Settings: trial days, prices, auto-renewal, our business name, GSTIN, email, phone and address, GST %, invoice prefix, email test, database tools.
  - Error monitor.
- **Tools:**
  - My to-do: the jobs only you can do (keys, approvals, cron, DNS).
  - Email: every email with previews and test sends, the sent log, on/off per email, cron status.
  - Admin handbook: this file, plus every other doc in the repo.
  - Features: every feature, where it is and what it needs.
  - Demo restaurant: reset or remove the demo.

Code: `main/superadmin/dashboard.php` (sidebar around line 190, one `<div class="menu-item" data-page="…">` per tab plus a matching `<div id="…Page" class="page">`). It's backed by `api.php` and `api_platform.php`; newer pages load through `sa-platform.js`.

---

## 6. Integrations: what's set up and what's missing

Settings live in `main/.env` on each server (never in git; `main/.env.example` lists every key).

| Integration | `.env` keys | Status | Needed for |
|---|---|---|---|
| Database | `DB_HOST_*`, `DB_NAME`, `DB_USER`, `DB_PASS` | ✅ Working | Everything |
| Email (Google Workspace SMTP) | `SMTP_*`, `MAIL_FROM_*`, `MAIL_TEAM` | ⚠️ Local: test mode (`MAIL_SINK_DIR`). Live: set `SMTP_ENABLED=true` + an App Password, then Super Admin › Email › Settings & jobs › Send test. Add SPF, DKIM, DMARC (My to-do). | Every email (Super Admin › Tools › Email) |
| PhonePe | `PHONEPE_*` | ⚠️ Test mode with demo credentials. Production merchant credentials missing. Each restaurant's own keys are in `users`. | Online payment, wallet top-up, VIP, subscription payment links |
| WhatsApp via RepeatGrow (shared number) | `REPEATGROW_API_URL`, `REPEATGROW_API_KEY`, `REPEATGROW_OTP_TEMPLATE_*`, `REPEATGROW_GIFT_TEMPLATE` | ⚠️ API key set (live key). Local API URL is a placeholder. `REPEATGROW_GIFT_TEMPLATE` is missing. | Customer OTP login, loyalty gifts, campaigns |
| Connect RepeatGrow (each restaurant's own number) | `REPEATGROW_CONNECT_URL`, `REPEATGROW_CLIENT_ID`, `REPEATGROW_CLIENT_SECRET`, `WA_KEY_SECRET` | ❌ The RepeatGrow side isn't built (contract: `docs/REPEATGROW_CONNECT.md`). The button stays hidden until the client ID and secret are set. | One-click template creation |
| Google Maps | `GOOGLE_MAPS_API_KEY` | ❌ The local key is invalid. Without it, address search falls back to OpenStreetMap. | Address autocomplete, delivery map |
| Gemini AI | `GEMINI_API_KEY`, `GEMINI_MODEL` | ❌ The local key is invalid. | Menus from photos, website chatbot, SEO AI, AI review replies |
| Google Business Profile | `GOOGLE_GBP_CLIENT_ID`, `GOOGLE_GBP_CLIENT_SECRET`, `GOOGLE_PLACES_API_KEY` | ⏳ Places key set. The OAuth client and Google's API approval are pending (`docs/GOOGLE_BUSINESS_TODO.md`). Shows "Coming soon" until then. | Reviews, replies, posts, insights |
| Google Search Console | `GOOGLE_SEO_CLIENT_ID`, `GOOGLE_SEO_CLIENT_SECRET` | ✅ Set | Dashboard › SEO |
| Continue with Google (sign-in) | `GOOGLE_LOGIN_CLIENT_ID/SECRET`, else the GBP or Search Console client | ✅ Uses the Search Console client. The OAuth client must list `https://restrogrow.com/main/api/google_login_callback.php` (and the localhost one), and the consent screen must be **In production** with email/profile/openid scopes | Owner/staff login, customer login on every restaurant website, Partner app login |
| Web push (browser alerts) | `VAPID_*` (optional) | ✅ Keys generate themselves into `push_vapid` | New-order alerts in the browser |
| Apple and Google Wallet cards | `GOOGLE_WALLET_*`, `APPLE_*` | ❌ Missing. The buttons stay hidden. | Loyalty card on the phone |
| Sentry | `SENTRY_DSN` | ✅ A default DSN is built into `config/sentry.php` | Error tracking |
| Cron by URL | `CRON_SECRET_KEY` | ❌ Not set (crons run through the command line instead) | Running crons through a URL |
| Other | `APP_URL`, `HEALTH_CHECK_KEY`, `RECONCILE_SECRET`, `TRUSTED_PROXY_IPS` | ❌ Not set (defaults are used) | Links in messages, detailed health check |

Not used: reCAPTCHA, Cloudinary (images are stored in the database and served by `main/api/image.php`), analytics tags. Geoapify is deprecated.

---

## 7. Server, deploy and cron jobs

- **Deploy:** push to `main` and Hostinger deploys restrogrow.com automatically (there are no GitHub Actions). Every push is a production change: test locally first, and only push when the owner says "push".
- **Live database:** new tables and columns create themselves on first use. The live database never ran `local_schema_upgrade.sql`, so new code must add any missing column or table itself, outside a transaction. Example: POS Pay failed on 15 Oct 2026 until `posEnsureBillColumns()` was added.
- **Crons** (hPanel › Advanced › Cron Jobs › Custom). `<HOME>` is the folder shown in hPanel › File Manager.

| Job | Command | When | Status |
|---|---|---|---|
| WhatsApp sending | `/usr/bin/php <HOME>/main/cron/whatsapp_dispatch.php` | every 5 min | check it's added |
| Loyalty gifts | `/usr/bin/php <HOME>/main/cron/loyalty_autopilot.php` | daily 09:00 | check it's added |
| Scheduled orders | `/usr/bin/php <HOME>/main/cron/activate_scheduled_orders.php` | every 5 min | check it's added |
| Meal-plan deliveries | `/usr/bin/php <HOME>/main/cron/generate_subscription_deliveries.php` | daily 05:00 | check it's added |
| Emails (reminders, retries) | `/usr/bin/php <HOME>/main/cron/email_dispatch.php` | every 10 min | Super Admin › Email › Settings & jobs shows if it runs |
| AI manager morning report | `/usr/bin/php <HOME>/main/cron/owner_brief.php` | every 15 min | Super Admin › Email › Settings & jobs shows if it runs |

- **Uptime:** UptimeRobot keyword monitor on `/main/api/health.php?check=orders`, looking for `"status":"ok"`.

---

## 8. What the product does: status

**✅ Built and working:**
- Customer website and QR ordering (dine-in, takeaway, delivery, scheduled orders), live order tracking, scratch cards, customer accounts.
- POS and kitchen tickets (KOT) with printing.
- Tables and live floor map, reservations, waiter calls.
- Delivery zones, map and rider page.
- Catering, and meal plans and subscriptions (switch on per restaurant in Super Admin).
- Coupons, offers, combos, upsell, today's specials, bestsellers, wallet.
- Inventory Studio, Marketing Studio (posters, videos, captions, calendar), referrals, customer segments, reports.
- Browser push alerts, multi-branch, SEO page.
- Super Admin panel.
- **Partner app 1.1.6** (Android, `com.restrogrow.app`, built locally as an APK) and its browser demo.

**⏳ Built, waiting on setup:**

| Feature | What's missing |
|---|---|
| Loyalty Studio and Growth › WhatsApp | RepeatGrow templates approved, `REPEATGROW_GIFT_TEMPLATE`, crons added |
| Connect RepeatGrow | RepeatGrow side not built, plus the client ID and secret |
| Google Business | Google Cloud OAuth client, Google API approval, a Business Profile |
| Wallet cards | Apple and Google Wallet credentials |
| PhonePe live | Production credentials |
| Email | SMTP app password and real sender addresses on the server |
| Menus from photos, AI chatbot, AI replies | A valid Gemini key |

**📝 Planned (researched, not built):**
- From `docs/AI_TODO.md`: smart upsell, WhatsApp ordering assistant, demand forecast, review replies and complaint summary, weekly owner summary, menu helpers, AI phone agent.
- From the README roadmap: birthday bonus points, group cart, PDF/Excel reports, multi-language website, automated backups.

---

## 9. Partner app (Expo, `app/`)

- Expo SDK 57. The release APK is built locally (JDK 17 and Android SDK in `C:\Users\INDIA\dev`): run `./gradlew assembleRelease` in `app/android`. Copies are kept on the Desktop as `RestroGrow-Partner-<version>.apk`.
- Version is in `app/app.json` and `app/android/app/build.gradle` (now 1.1.6, versionCode 16).
- **Browser demo:** `app/demo/mockApi.js` and `app/demo/fixtures.json`. Rebuild with `EXPO_PUBLIC_DEMO=1 EXPO_BASE_URL=/app-demo/app npx expo export --platform web --output-dir ../app-demo/app` (in Git Bash also add `MSYS_NO_PATHCONV=1`).
- Restaurant settings the app can edit are saved through `main/api/app_settings.php`. It saves only the fields it's sent; only the owner or a branch admin can use it.

### Super Admin app (`superadmin-app/`) and Staff app (`staff-app/`)

- **Super Admin app** (v1.1.0, `com.restrogrow.superadmin`): the Super Admin panel on a phone. Overview, restaurants (plan filters, 360 page, plan / features / password / OTP lock), renewals, support tickets, leads, chatbot, payments, **payment links, coupons, branch admins**, announcements, security, activity log, health, settings. Menus, settlements, the invoice generator and the error monitor open the web panel **inside the app**, already signed in. Code and details: `superadmin-app/README.md`.
- **Stays signed in:** `main/superadmin/app_auth.php` gives each phone a device token (only its hash is stored, table `sa_app_tokens`, 90 days from last use). It is cleared on sign-out, when the super admin is deactivated, or when their password changes.
- **Notifications:** new / updated tickets, new leads, and a daily "plans ending soon". The app checks itself (every minute while open, about every 15 minutes in the background), there is no push server. Switches are in the app's Settings.
- **Staff app** (v1.0.1, `com.restrogrow.staff`): for waiters, chefs and managers, built from the Partner app. Details: `staff-app/README.md`.
- Both APKs are built locally like the Partner app (`./gradlew assembleRelease` in `superadmin-app/android` or `staff-app/android`; the Desktop copies are `RestroGrow-SuperAdmin-<version>.apk` and `RestroGrow-Staff-<version>.apk`). They are signed with the debug key because they are for our own use, not the Play Store. If the build runs out of memory, close other programs and use `--max-workers=2`.
- After changing the server side (`app_auth.php`, `api.php`), push first: the installed apps talk to restrogrow.com.

---

## 10. Where things are written down

| Doc | What |
|---|---|
| `README.md` | Product overview, features, local setup, `.env`, deploy, structure |
| `docs/ADMIN_HANDBOOK.md` | This file |
| `docs/REPEATGROW_CONNECT.md` | The contract for the Connect RepeatGrow button |
| `docs/GOOGLE_BUSINESS_TODO.md` | Google Business setup steps |
| `docs/AI_TODO.md` | Researched AI features, not built |
| `docs/SCALING_TO_1000_USERS.md`, `docs/CODEBASE_AUDIT_REPORT.md` | Scaling and audit notes |
| `main/docs/*` | Older technical notes (payments, email, QR codes, deployment, XAMPP) |
| `AGENTS.md` | Architecture, routing and conventions for developers and AI coding agents |

All of these can be read in Super Admin › Tools › Admin handbook. None can be opened on the public website.

---

## 11. Open items

- [ ] Live: deploy, then press **Create demo** in Super Admin › Tools › Demo restaurant.
- [ ] Live: confirm the other four crons are added.
- [ ] Live: check `SMTP_ENABLED`, the app password and the `MAIL_FROM_*` aliases.
- [ ] RepeatGrow: approve the WhatsApp templates, set `REPEATGROW_GIFT_TEMPLATE`, and build the Connect side.
- [ ] Google: OAuth client and API approval for Business Profile. Valid Maps and Gemini keys.
- [ ] PhonePe production credentials.
- [ ] Super Admin password reset is hard-coded to one Gmail address. Consider moving it to `.env`.
- [x] `AGENTS.md` updated (no Composer, built-in web push, demo restaurant).
