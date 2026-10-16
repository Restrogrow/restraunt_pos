# Important to-do: the next-level features (planned 16 October 2026)

Status: **1 and 2 built (16 Oct 2026), 4 later, 5 next when asked (added 17 Oct 2026).** These three change what RestroGrow is: from billing software to the
restaurant's own ordering channel, manager and single screen for every order. Order of work:

| # | Feature | What it means for a restaurant | Rough size |
|---|---------|--------------------------------|-----------|
| 1 | **AI ordering on WhatsApp** | Customers order and pay in a WhatsApp chat, no app, no commission; the order lands in the POS with a KOT | 3–4 weeks |
| 2 | **AI manager for the owner** | A morning WhatsApp report with what changed, why, and one-tap actions ("send ₹50 off to 23 regulars? YES") | 2–3 weeks |
| 4 | **Swiggy, Zomato and ONDC orders in the POS** (later, after 1 and 2) | One screen and one KOT for every channel, stock and menu synced, no extra tablets | 4–8 weeks + partner approvals |
| 5 | **Custom access per staff member** | The owner switches each permission on or off per person (or saves custom roles like "Captain"), instead of fixed roles only | about one session + new APKs |

(The numbers match the list we discussed; #3 voice ordering for waiters is parked.)
Smaller AI ideas stay in `docs/AI_TODO.md`; #1 and #2 here are the bigger versions of its items 2 and 5.

Rules for all three:
- Each is a switch per restaurant (Settings) plus a Super Admin switch; off by default until the owner turns it on.
- AI runs only on the server; keys stay in `main/.env`. Every AI decision is logged (what it read, what it did).
- Money is never decided by the AI: prices, totals, tax and discounts come from the same PHP code the POS
  uses (`controllers/pos_operations.php`, `config/pos_loyalty.php`). The AI only turns words into item ids.
- Cost guard per restaurant per day; if the AI fails, fall back to a simple menu-and-buttons flow.

---

## 1. AI ordering on WhatsApp

### Status: built (16 October 2026)
- Owner screen: Dashboard › Growth › **WhatsApp ordering** (switch, order types, welcome line, "suggest an extra",
  the order link + one QR per table, a test chat that talks to the real bot, customer chats with a reply box).
- Files: `main/config/wa_order_engine.php` (the chat), `main/config/ai_gemini.php` (Gemini, the user's choice of AI;
  without a key a built-in matcher understands plain orders), `main/api/wa_webhook.php` (RepeatGrow → RestroGrow),
  `main/api/wa_order_admin.php`, `main/assets/js/wa-order-studio.js`. Orders go through
  `api/process_website_order.php` with a signed internal header (skips only the per-IP limits) and carry
  `[WhatsApp order]` in their notes; status changes message the customer (hook in `config/order_state_machine.php`).
- Prices in the chat match the order exactly (dish offers, packing, tax on dishes); dishes whose category is closed
  (e.g. breakfast hours) are left out; the website's minimum order, order types and stock rules apply.
- Tested locally: Hinglish orders, notes ("kam spicy"), sizes, menu by category, takeaway/delivery/table, name,
  confirm → real order + KOT, status messages, signed webhook (bad signature refused), duplicate deliveries ignored.

**Still needs the user (to go live):**
1. In RepeatGrow: allow `webhooks:manage` and `contacts:read` for the RestroGrow Connect client, then set
   `REPEATGROW_CONNECT_ORDERING=1` in the live `main/.env`; restaurants reconnect once (Growth › WhatsApp).
2. The live `main/.env` needs `GEMINI_API_KEY` (already used by the chatbot) for the AI understanding.
3. Optional shared number for restaurants without their own: `WA_ORDER_SHARED_NUMBER` + `REPEATGROW_WEBHOOK_SECRET`,
   and register `https://restrogrow.com/main/api/wa_webhook.php?rid=platform` as a webhook on that RepeatGrow account.
4. Not in v1: voice notes, tap buttons (RepeatGrow's API sends text only today), online payment links in chat
   (takeaway/delivery pay on pickup/delivery; restaurants that require online payment get a link to their website).

### What the customer sees
1. Scans the table QR / taps "Order on WhatsApp" on the website / messages the restaurant's number.
2. Types or sends a voice note: *"2 butter naan, ek paneer tikka, kam spicy, table 4"*.
3. Gets back a clean bill: items, prices, add-on suggestions ("Add a Masala Chaas for ₹59?"), total with GST,
   and buttons: **Confirm**, **Change**, **Cancel**.
4. Confirm → table order goes to the kitchen; takeaway/delivery gets a UPI pay link first.
5. Updates in the same chat: "Accepted", "Being cooked", "Ready", "Out for delivery"; the bill as a PDF;
   points earned ("You earned 12 points, balance 240").
6. Next time: *"same as last time"* works, and they can say *"use my points"*.

### What the restaurant sees
- WhatsApp orders appear in Orders / POS / Kitchen like website orders, marked **WhatsApp**, with the alert sound.
- Settings › WhatsApp ordering: on/off, hours, which order types (dine-in, takeaway, delivery), minimum order,
  delivery radius (existing settings), auto-accept or manual accept, the greeting text.
- A Conversations view for chats the AI handed over to a human ("Talk to the restaurant").

### How we build it
```
Customer WhatsApp ──► Meta ──► RepeatGrow (number, templates, inbox)
                                   │ webhook: message.received  (already exists in RepeatGrow)
                                   ▼
             RestroGrow  main/api/wa_order_webhook.php   (new; checks the webhook signature)
                                   │
                    config/wa_order_engine.php  (new)  ── keeps one "cart" per chat (table wa_order_sessions)
                        │ 1. AI reads the message + the restaurant's live menu (ids, names, variations, add-ons)
                        │    and returns ONLY structured actions: add item id X qty 2, remove, set table, checkout…
                        │ 2. PHP prices the cart with the POS money rules (no AI maths)
                        │ 3. PHP writes the reply (bill text + buttons) and sends it via RepeatGrow /api/v1/messages
                        ▼
             On Confirm: the same order path as the website (process_website_order.php logic) → order + KOT +
             alerts; payment link from the restaurant's PhonePe / UPI; status changes push WhatsApp updates.
```
Steps:
1. **Webhook in** — register RestroGrow's URL in RepeatGrow (`message.received`), verify the signature, map the
   WhatsApp number to the restaurant (one number per restaurant, or a shared number where the first message
   carries the restaurant code from the QR/link, e.g. "Order SGI002 T4").
2. **Chat session table** — `wa_order_sessions` (restaurant, phone, cart JSON, table, order type, state, last
   message at, 2-hour expiry) and `wa_order_messages` (log).
3. **Menu for the AI** — a compact list from `menu_items` + variations + add-ons + availability + sold-out,
   cached per restaurant and refreshed when the menu changes.
4. **AI step** — Claude (claude-haiku-5-5: fast and cheap) with tool/JSON output: `add_item`, `remove_item`,
   `set_quantity`, `set_table`, `set_order_type`, `ask_question`, `checkout`, `handover_to_human`.
   Hindi, Hinglish and English. Voice notes: speech-to-text first, then the same step.
5. **Pricing + reply** — reuse POS totals, coupons, loyalty points; WhatsApp interactive buttons/lists via RepeatGrow.
6. **Confirm → order** — reuse the website order code (table check, stock, KOT, alerts, loyalty, receipts).
7. **Payment** — UPI link (existing PhonePe setup) for takeaway/delivery; dine-in pays at the table / counter.
8. **Status updates** — hook order status changes to send WhatsApp updates (utility templates, approved once).
9. **Entry points** — table QR option "Order on WhatsApp", website button, Google Business "Order" link.
10. **Safety** — rate limit per phone, block list, max order value without payment, human handover, opt-out ("STOP").

### Requirements
- **Meta / WhatsApp:** each restaurant's number connected in RepeatGrow (Meta Business verification), or one
  shared RestroGrow ordering number to start. Approved utility templates: order confirmed, status update, bill.
  Customer-started chats are free to reply to for 24 hours; templates outside that window cost a small fee per
  message (check Meta's current India price list before pricing the plan).
- **RepeatGrow side:** webhook delivery with signature (exists), sending interactive buttons/lists (check; add if
  missing), voice note download URL in the webhook (check).
- **AI:** an Anthropic API key in `main/.env` (`ANTHROPIC_API_KEY`), model ids in `.env` so they can change.
  The website chatbot uses Gemini today; either works, but one provider for ordering keeps it simple.
- **Speech-to-text** for voice notes (any provider that handles Hindi; optional in the first version).
- **Payments:** the restaurant's PhonePe / UPI setup (already in settings).
- **Testing:** a test WhatsApp number and the demo restaurant (Spice Garden Indiranagar).

### Done when
A customer can order, change, pay and get status updates in WhatsApp for the demo restaurant; the order shows in
POS/Kitchen with a KOT; prices always match the POS; the owner can switch it off; every chat is logged.

---

## 2. AI manager for the owner

### Status: built (16 October 2026)
- Owner screen: Dashboard › Growth › **AI manager** (switch, send time, language English/Hinglish/Hindi, number;
  today's report with Do it / Skip buttons, "send to my WhatsApp now", ask-me-anything, earlier reports).
- Files: `main/config/owner_manager.php` (facts, rules, actions, Gemini wording with a number check, questions),
  `main/api/owner_manager_admin.php`, `main/assets/js/owner-manager-studio.js`, `main/cron/owner_brief.php`.
  The owner's WhatsApp replies reach it through `api/wa_webhook.php` (owner's phone → `ownerMgrHandleMessage`).
- Report: yesterday's sales vs the same weekday last week, average bill, counter/website/WhatsApp split, top dishes,
  a dish selling much less this week, cancellations, new vs returning customers, low stock, month so far, and the
  result of earlier actions. Actions (reply 1 / 2): switch on the loyalty "We miss you" gift for lapsed regulars,
  switch a dish that was selling back on. Gemini only re-words the plain report; any number not in the data → the
  plain report is sent. Questions: Gemini picks one of 9 fixed reports + a period; PHP runs it.
- Tested locally: English, Hinglish and Hindi reports; "aaj kitna bika", "kal ke top dishes", "whatsapp vs website
  this month"; reply 1 ran the action; cron sends once a day after the chosen time; cron refuses web access.

**Still needs the user:**
1. Hostinger cron every 15 minutes: `/usr/bin/php /home/<user>/public_html/main/cron/owner_brief.php`
   (without it the report is sent when the owner opens the AI manager page after the chosen time).
2. A WhatsApp "daily report" utility template approved by Meta, so the morning message arrives even when the owner
   hasn't messaged in the last 24 hours (until then it is sent as text).
3. Same WhatsApp setup as feature 1 for the owner's replies (webhook).
4. Not yet: a card on the Partner app's Home screen.

### What the owner sees
Every morning at a time they pick, on WhatsApp (and in the app's Home):
> **Yesterday ₹42,180 (−18% vs last Thursday).** Dine-in was normal; delivery fell 41%.
> Paneer Tikka sold 40% less since its price went to ₹279.
> 23 regulars haven't come in 30+ days. **Send them ₹50 off?** Reply **YES**
> 3 dishes ran out before 8 pm. Prep more Dal Makhani today (forecast: 34 plates).

They reply **YES**, **NO**, or ask a question: *"how much did we make this month?"*, *"which waiter sold most?"*.

### How we build it
1. **Facts first, no AI:** a PHP job builds a "daily facts" pack per restaurant from data we already have:
   sales vs same weekday, channels, top/bottom dishes, price changes, sold-outs, cancellations, average bill,
   new vs repeat customers, loyalty and gifts (loyalty_admin performance), stock alerts (inventory engine),
   reviews (Google Business, when approved), staff sales.
2. **Rules pick what matters:** thresholds decide the 3–5 most important points (big drops, opportunities).
3. **AI writes it:** Claude turns the chosen facts into a short message in the owner's language. It may only
   use numbers from the facts pack (checked after writing; if a number doesn't match, send the plain version).
4. **One-tap actions:** each suggestion maps to an action we already have: send a gift to a group (loyalty
   autopilot / campaigns), switch a dish off or on, change a price (needs a second confirm), make a coupon,
   remind about stock. Replies come back through the same RepeatGrow webhook as feature 1.
5. **Questions:** the owner can ask; the AI picks from a fixed list of safe report queries (no free SQL),
   answers with the numbers and a link to the report.
6. **Schedule:** a cron job (Hostinger cron) runs at the owner's chosen time; a weekly summary on Mondays.

### Requirements
- Feature 1's webhook path (for replies) and an approved utility template ("daily report") for the first
  message of the day, since it is business-started.
- Anthropic API key (same as feature 1).
- A cron job on Hostinger (one line, every 15 minutes; it decides who is due).
- Owner's WhatsApp number confirmed (users.phone), opt-in switch, time and language in Settings.

### Done when
The demo owner gets a correct report every morning, every number in it matches Analytics, and YES on a
suggestion really runs it (logged, with the result shown next day: "The ₹50 gift brought back 6 customers").

---

## 4. Swiggy, Zomato and ONDC orders in the POS (later)

> The user decided on 16 October 2026: build 1 and 2 first, this one later.

### What the restaurant sees
- Aggregator orders pop up in RestroGrow with the alert; **Accept / Reject / set prep time** from the POS or app.
- One KOT in the kitchen, status updates go back (food ready, picked up).
- Menu and dish availability: switch a dish off once, it goes off everywhere; prices per channel.
- Reports per channel: sales, commission, payouts.

### How we can build it (two roads)
**Road A: through an integration partner (fastest, recommended to start).**
Companies such as UrbanPiper are already approved by Swiggy and Zomato and give one API for all of them.
1. Sign a partner agreement; each restaurant links its Swiggy/Zomato store in the partner's dashboard.
2. Build `main/api/aggregator_webhook.php`: receive orders → create a RestroGrow order (source = swiggy/zomato)
   with KOT and alerts; map their item ids to our `menu_items` (a mapping screen in Menu).
3. Send back accept/reject/ready and stock on/off through the partner API.
4. Menu push: our menu → partner → aggregators.
Cost: a monthly fee per restaurant to the partner (pass it into the plan price).

**Road B: direct (cheaper later, slow to get).**
Swiggy and Zomato only give their POS APIs to approved POS companies (application, review, testing on their
sandbox, then go-live). Apply in parallel with Road A; switch when approved.

**ONDC (cheaper channel, government-backed).**
Restaurants can sell on ONDC buyer apps (Paytm, Magicpin, etc.) through a seller app / technology provider.
Either join through an existing seller-side provider's API, or become a seller app ourselves (bigger:
registration, certification, settlement). Start through a provider.

### Requirements
- A partner account (Road A) or Swiggy/Zomato POS partner approval (Road B); legal agreement, test stores.
- Each restaurant's aggregator store ids and their permission to link.
- Item mapping screen; channel field on orders (`orders.source` already exists for POS/website); commission
  and payout fields for reports.
- Reliable webhooks: retries, duplicate protection (same aggregator order id never makes two orders), a log.
- Kitchen and alerts already exist; the app's order alerts work for these too.

### Done when
A test Swiggy/Zomato order shows in the POS within seconds, prints one KOT, accept/ready go back to the
aggregator, switching a dish off removes it there, and the day's report splits sales by channel.

---

## 5. Custom access per staff member (next, build when asked)

Today each role has a fixed list of permissions (`main/config/authorization_config.php`: Admin, Manager,
Waiter, Chef, Cashier, Delivery rider). Owners want to give exactly the access they want.

### What the owner sees
- Staff › add / edit member: the **role is a starting point** that fills in a set of switches; below it,
  **Custom access** with every switch, grouped:
  - Orders: see orders, accept / reject online orders, cancel orders, delete orders
  - Billing: use the POS, give discounts, take payments, refunds
  - Menu: edit dishes and prices, mark items out of stock
  - Tables, kitchen, bookings
  - Customers: see customers, see phone numbers, loyalty points
  - Money: today's sales, reports, cash and settlements
  - Admin: staff, settings, coupons, inventory
- **Saved roles** (optional): save a set of switches under a name ("Captain", "Head chef") and reuse it.
- A small "Custom" tag on staff who differ from their role.

### How we build it
- Table `staff_permissions` (staff_id, permission, allowed) = overrides on top of the role's defaults;
  `staff_roles_custom` for saved roles. Loaded into the session at login.
- `hasPermission()` checks the overrides first, then the role. New, finer permissions (discounts, refunds,
  cancel, delete, see phone numbers, see sales) are checked in the endpoints that do those things.
- `get_session.php` sends the person's final permission list; the website and both apps show only the
  screens, tiles and buttons that list allows (the server still refuses everything else).
- Changing someone's access applies at their next request (no logout needed).

### Done when
A waiter given "give discounts" can apply one in POS and a waiter without it can't (also through the API);
a manager with "reports" switched off sees no Reports anywhere; a saved "Captain" role can be given to two
people; the website and both apps hide what the person can't use.

---

## Order of work (suggested)
1. Feature 1 with a shared test number on the demo restaurant (no voice notes yet) → then real numbers.
2. Feature 2 (reuses feature 1's webhook and AI setup).
3. Feature 4: start the partner conversations now (approvals take weeks), build when an account is ready.

Before starting each, write down the "before" numbers (order count per channel, repeat rate, average bill)
so the result can be shown in Analytics and in sales demos.
