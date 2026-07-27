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
# 2. Import main/database/full_database_dump.sql into MySQL
# 3. Install PHP deps
php composer.phar install
# 4. Configure
cp main/.env.example main/.env
# 5. Generate VAPID keys (for push notifications)
php main/admin/generate_vapid.php
```

- **Local URLs**: Customer: `http://localhost/menuwebsite/{restaurant-slug}` — Admin: `http://localhost/menuwebsite/main/admin/login.php`
- `.env` is loaded by `main/config/env_loader.php`. `.env.local` overrides `.env` (both gitignored).
- Required env vars: DB credentials, PhonePe config, Google Maps API key, Google Gemini API key, SMTP config.

## Auth

- Session-based, configured in `main/config/session_config.php`.
- `startSecureSession()` must be called before any session usage.
- Roles: Admin, Manager, Waiter, Chef — stored in `$_SESSION['role']`.
- Session files stored in `main/sessions/`.

## Key conventions

- All API endpoints return JSON with `Content-Type: application/json`.
- `display_errors = 0` everywhere — errors go to logs, never HTML output.
- PDO with `ERRMODE_EXCEPTION`, non-persistent connections, prepared statements.
- PHP files use `<?php` open tags, no short tags.

## Payments

- **PhonePe** is the only payment gateway integrated.
- `PHONEPE_ENVIRONMENT=test` for sandbox, `production` for live.
- Callback: `phonepe_order_callback.php` / `phonepe_callback.php`.

## Push notifications

- VAPID-based via `minishlink/web-push` Composer package.
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
