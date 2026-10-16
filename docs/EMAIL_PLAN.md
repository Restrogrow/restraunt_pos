# Email plan (16 October 2026)

## What exists today
SMTP (`main/config/email_config.php`, `sendEmail($to, $subject, $html, '', $purpose)`), Gmail SMTP in `.env`,
sender per purpose (`MAIL_FROM_BILLING/ALERTS/ORDERS/ACCOUNTS/SUPPORT/SALES`, all unset → `SMTP_FROM_EMAIL`).

Sent today: owner / Super Admin / customer password resets, "order confirmed" to a customer who typed an
email, "new order" to the restaurant, and the Super Admin test email.

Problems: sender name "Restaurant POS System"; no log of what was sent or failed; sent inline (slow SMTP
slows checkout, no retry); contact-form and chatbot leads email nobody; most life-cycle emails missing.
Sending stays on Google Workspace SMTP (2,000 emails a day per user, plenty for now).

## Status: all 3 phases built (17 October 2026)
- Engine: `main/config/mail_engine.php` (outbox `email_outbox`, `mailLayout()`, `mailQueue()` / `mailSendNow()`,
  `mailDispatch()`, `mailSweep()` for the date-based emails, one function per email). Sending: `config/email_config.php`.
- Sent after the page answers (shutdown), by any open dashboard (api/wa_dispatch.php heartbeat) and by
  `cron/email_dispatch.php` (every 10 min, also makes the hourly reminders).
- Hooks: signup + new-device login + owner reset (admin/auth.php), contact form, chatbot lead, support reply
  and invoice email (superadmin/api_platform.php), order confirmation (config/order_confirmation.php), order status
  (config/order_state_machine.php), bookings (api/create_reservation.php, controllers/reservation_operations.php),
  customer signup + reset (website/customer_auth.php), Super Admin reset, AI manager report (config/owner_manager.php).
- Super Admin › Tools › Email:
  - **All emails**: every email (21 types, 32 previews with sample data from `config/mail_catalog.php`), who gets it,
    when and what starts it, and "Send me a test" for each.
  - **Sent log**: sent / failed / waiting, preview, send again.
  - **Settings & jobs**: sending account, test email, each type on/off, and whether the crons ran recently.
- Local testing: `MAIL_SINK_DIR` in .env saves emails as .html files instead of sending.
- To go live: the "Email" jobs in Super Admin › My to-do (SMTP in the live .env, SPF/DKIM/DMARC, the cron).

## Phase 1: foundation (everything else uses it)
1. **One branded template**: RestroGrow (platform emails) or the restaurant's logo/colours (customer emails),
   mobile-friendly, plain-text part, preheader, footer with address and (for marketing) unsubscribe.
2. **Outbox + log** (`email_outbox`): every email is queued, sent within a minute by the existing dispatcher
   heartbeat + a cron, retried 3 times, and logged (sent / failed + reason). Checkout never waits for SMTP.
3. **Super Admin › Email**: log with search, resend, failure count on the dashboard, test email, settings
   (sender name "RestroGrow", reply-to, on/off per email type).
4. **Provider-ready**: same code works with Gmail SMTP today and a transactional service later
   (Brevo / Amazon SES / Zoho) by changing `.env` only.

## Phase 2: emails to restaurant owners (from RestroGrow)
- Welcome after signup (+ 5-step setup checklist)
- Trial ends in 3 days / 1 day / today, and trial ended
- Plan renewal reminder (7 days, 1 day), plan expired, payment received (receipt + invoice PDF link)
- **Invoice from the Invoice generator**: "Email to restaurant" button
- New lead alerts to the RestroGrow team: contact form, chatbot call-back, new signup
- Security: login from a new device
- Support ticket replied
- Optional: daily sales summary by email (same facts as the AI manager)

## Phase 3: emails to customers (from the restaurant's name)
- Order confirmed (new template) + status updates (accepted, ready, out for delivery) + e-bill after payment
- Table booking received / confirmed / reminder
- Website account: welcome, password reset (new template)
- Loyalty gifts (birthday, we-miss-you) by email for customers without WhatsApp
- Later: email campaigns with unsubscribe

## What the owner (you) needs to do
- Set `SMTP_*` in the live `.env` (Google Workspace address + an App Password).
- For a restrogrow.com address: add SPF, DKIM and DMARC DNS records (the provider gives them), so emails don't
  land in spam.
- Optional: `MAIL_FROM_*` addresses (billing@, orders@, …) once the domain mail exists.
