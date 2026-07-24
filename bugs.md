# Bugs — Deferred

Skipped per discussion. Fix when ready.

## P0 — Critical

### #5 Hardcoded credentials in source code
`db_connection.php:68-71`, `email_config.php:44-48` — production MySQL and SMTP passwords in plaintext.
**Fix:** Move to `.env` (already gitignored), update files to read from env with fallback.

### #6 10-year session lifetime, never expires
`session_config.php:18-23` — `session.cookie_lifetime = 315360000`. `isSessionValid()` checks session keys only, no DB check for disabled accounts.
**Fix:** Add DB check in `isSessionValid()`, reduce cookie lifetime, add absolute session timeout.

### #7 No CSRF on any POST
Zero tokens, origin checks, or referrer validation on any form/AJAX.
**Fix:** Generate per-session CSRF token, validate on all POST endpoints.

## P1 — High

### #11 No delivery address validation
`process_website_order.php:47,67-75` — delivery orders can be placed with empty address.
**Fix:** Validate `$customer_address` is non-empty when `$order_type` is delivery.

### #13 Min order 350.00 silently bypassed
`process_website_order.php:97` — `$minOrderValue = ($rawMin == 350.00) ? 0 : (float)$rawMin`. Hardcoded check skips exactly 350.00.
**Fix:** Remove the hardcoded bypass, always use the DB value.

### #15 Cross-restaurant data access
10+ API files — read `restaurant_id` from `$_GET` first, letting any authenticated user access other restaurants' data.
**Fix:** Always validate against session `restaurant_id`.

### #16 KOT/POS orders price-unverified
`kot_operations.php:87-94` — staff can manipulate order totals from dashboard.
**Fix:** Apply same server-side price verification as website orders.

### #17 Payment amount fully client-controlled
`save_payment.php:20-21` — `save_payment.php` takes `amount` from POST.
**Fix:** Recalculate amount server-side from order total.

### #18 No email format validation
`cart.php:1843` — users can order with garbage email.
**Fix:** Validate email format before submission.

### #19 Corrupted localStorage wipes cart silently
All cart pages — no validation on `JSON.parse` output.
**Fix:** Validate parsed object structure, show error message to user.

### #20 Search rendering crashes
`menu.php:1651` — `setDealFilter` accesses null `.deal-filter[data-deal=clear]`.
**Fix:** Add null check before accessing DOM element.
