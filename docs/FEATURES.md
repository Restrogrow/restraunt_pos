# RestroGrow feature list

Everything RestroGrow can do today, grouped by area. Made on 16 Oct 2026 from the code itself (not from memory), updated 17 Oct 2026, so if it is in this list, it exists.

## How to use this list

- **Find a feature:** use the category list below, or (in Super Admin › Tools › Features) type in the search box — e.g. "printer", "WhatsApp", "coupon".
- **Where** tells you exactly where to click. "Dashboard › Offers › Wallet" means: log in to the restaurant dashboard, open **Offers** in the left menu, then **Wallet**. Customer pages are shown as web addresses, where `{restaurant}` is the restaurant's name in lowercase with dashes (e.g. `restrogrow.com/spice-garden-indiranagar/menu`).
- **Who:** owner (restaurant owner), staff (manager / waiter / chef), customer (the restaurant's guests), super admin (you, the RestroGrow team).
- **Needs setup?** "—" means it works now. Anything else is what must be done first (keys, approval, or a switch Super Admin turns on). "Waiting" means the code is built but it cannot be used yet.
- The fastest way to re-learn the product: do the **15-minute tour** at the bottom on the demo restaurant.

| Category | Features |
|---|---|
| Orders & POS | 18 |
| Kitchen & staff | 14 |
| Tables & dine-in | 10 |
| Menu | 12 |
| Customer website & online ordering | 28 |
| Delivery | 10 |
| Customers, loyalty & rewards | 20 |
| Offers | 13 |
| Marketing & WhatsApp | 17 |
| Emails | 9 |
| Reports & analytics | 10 |
| Inventory | 9 |
| Payments & billing | 12 |
| Google & SEO | 9 |
| Website look & settings | 19 |
| Security & accounts | 11 |
| Apps | 18 |
| Super Admin (platform) | 33 |
| Demo & sales tools | 10 |
| **Total built** | **282** |
| Planned, not built | 19 |

## Orders & POS

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Point of Sale (POS) | Take an order at the counter: search dishes, pick table or walk-in, then bill, send to kitchen, or hold. | Dashboard › POS | owner, staff | — |
| POS search and filters | Find dishes fast with search (press "/"), veg / non-veg and category chips, grid or list view. | Dashboard › POS | owner, staff | — |
| Sizes and add-ons in POS | Choose a dish size and add extras (extra cheese etc.) per dish or per order. | Dashboard › POS (tap a dish) | owner, staff | Add-ons made in Menu › Add-ons |
| Combos in POS | A strip of your combo deals that can be added to the bill in one tap. | Dashboard › POS (combos strip) | owner, staff | Combos made in Offers › Combos & Deals |
| Customer on the bill | Attach a customer to the bill so they earn points / stamps / cashback and can use points, gift codes or wallet money. | Dashboard › POS › Customer | owner, staff | Loyalty Studio switched on |
| Account QR | Shows a QR the guest scans to finish a website account that was created at the counter. | Dashboard › POS › Customer › Account QR | owner, staff | — |
| Manual discount | Give a % or flat discount on the bill; it is capped and written to the activity log. | Dashboard › POS (Discount) | owner, staff | — |
| Hold and resume bills | Park a bill and pick it up later (Held orders). | Dashboard › POS (Hold / Held orders) | owner, staff | — |
| Split / part payments | Take part of the bill in cash and part by card/UPI, or collect later. | Dashboard › POS (Pay) | owner, staff | — |
| Offline billing | Keep billing when the internet is down; bills sync by themselves when it is back (loyalty needs internet). | Dashboard › POS (works automatically) | owner, staff | — |
| Direct bill and KOT printing | Print to a thermal printer by Bluetooth, USB, network (IP) or the browser print window. | Dashboard › POS; set up in Dashboard › Settings › Printer | owner, staff | A thermal printer (Bluetooth not on iPhone) |
| Bill numbers 1, 2, 3… | Every restaurant gets its own running bill numbers without gaps. | Automatic on every bill | owner | — |
| New-order popup | Rings and pops up for every new online order; accept with a "ready in" time or reject with a reason. | Dashboard (any page) | owner, staff | — |
| Order alerts | "Turn on order alerts" bar: sound, flashing tab, and phone/desktop notifications even when the tab is in the background. | Dashboard (top bar) | owner, staff | Browser allows notifications |
| Online Orders | All orders from the website: Delivery / Dine-in / Takeaway, Ongoing / Completed / Cancelled, accept / reject, update payment. | Dashboard › Orders › Online Orders | owner, staff | — |
| All orders | Every order from every channel with filters for status, payment and type, print, mark paid, CSV export. | Dashboard › Orders › Orders | owner, staff | — |
| Scheduled orders | Guests order for later; the order waits in "Scheduled" and moves to the kitchen when it is due. | Dashboard › Orders › Orders (Scheduled) | owner, customer | Best with the scheduled-orders cron job |
| Reject / cancel reasons | Pick one of 5 reasons when you reject; the customer sees it in plain words. A "fake order" reason counts a strike against that phone. | New-order popup › Reject | owner, staff | — |

## Kitchen & staff

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| KOT (kitchen tickets) | Kitchen tickets with waiting timers and stage chips; print, move forward, complete or cancel. | Dashboard › Orders › KOT | owner, staff | — |
| Chef screen (kitchen display) | Big New / Cooking / Ready board; tickets turn amber at 10 min and red at 20 min; full-screen. | Staff login (chef) › Kitchen display | staff (chef) | Chef account in Dashboard › Staff |
| Waiter screen | Table calls, food ready to serve, and the POS, made for a phone. | Staff login (waiter) | staff (waiter) | Waiter account |
| Manager screen | Overview, orders, kitchen, table calls, tables, POS, customers, payments and today's report. | Staff login (manager) | staff (manager) | Manager account |
| Staff accounts | Add managers, waiters and chefs, see last login and recent actions, pause access, CSV export. | Dashboard › Staff | owner | — |
| Activity log | Who cancelled, deleted, discounted or changed what, with filters and search. | Dashboard › Staff › Activity log button | owner | — |
| Waiter Requests | Live list of guests who tapped "Call waiter", oldest first, coloured by wait time, mark attended. | Dashboard › Waiter Requests | owner, staff | — |
| Call waiter button | Guests tap "Call waiter" on the website (bill, water, order, cutlery…); the table comes from the QR. | Customer website (floating button) | customer | On by default; needs dine-in and tables |
| Staff alerts (web push) | Staff phones ring for new orders and table calls. | Staff screens › Turn on alerts | staff | Browser allows notifications |
| Staff sign-in by role | Staff log in at the same login page and land on their own screen (manager / waiter / chef). | restrogrow.com/main/admin/login.php | staff | — |
| Continue with Google (staff/owner) | Log in to the dashboard with a Google account. | Login page › Continue with Google | owner, staff | Google login keys |
| Branch switcher | For owners with several branches: compare today's numbers per branch and jump into any branch. | Dashboard › sidebar top › All branches | owner (multi-branch) | Branch login made in Super Admin › Branch admins |
| Help & support | Send a question to the RestroGrow team and read replies inside the dashboard. | Dashboard › Help & support | owner | — |
| Cashier role | A staff role for the billing counter: orders (including accepting online orders), POS billing, payments, customers, tables, kitchen tickets, assigning riders and settling their cash. No reports, menu editing, staff or settings. | Staff › add member (role Cashier); Staff app or the website | staff (cashier) | — |

## Tables & dine-in

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Areas | Group tables into areas (AC hall, rooftop…) with live table status, occupancy and today's sales. | Dashboard › Tables › Area | owner | — |
| Tables | Add, edit, switch off, bulk-change or delete tables; grid or list; live status. | Dashboard › Tables › Tables | owner | — |
| Table QR codes | A menu QR for each table; scanning it opens the website with the table already chosen. | Dashboard › Tables › QR Code (Download All) | owner, customer | — |
| QR standee designer | Design and print 4×6 inch table standees with your logo, colours and text. | Dashboard › Tables › Tables (QR / standee) | owner | — |
| Table Map | Live floor view: free, busy (with time), reserved, off. | Dashboard › Tables › Table Map | owner, staff | — |
| Reservations | Day strip and timeline of bookings: confirm, decline, seat, no-show, assign table, call or WhatsApp the guest. | Dashboard › Reservations | owner, staff | — |
| Online table booking | Guests book a table from the website. | `/{restaurant}/reservations` | customer | Switch on in Dashboard › Settings › Ordering › Table reservations |
| Reservation availability check | Stops double booking of the same table and time. | Automatic when booking | customer | — |
| QR table ordering | Guest scans the table QR, orders from the phone, the order arrives tagged with the table. | Table QR › website | customer | Dine-in switched on |
| Dine-in orders on the dashboard | Dine-in orders are shown separately from delivery and takeaway. | Dashboard › Orders › Online Orders › Dine-in | owner, staff | — |

## Menu

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Categories and subcategories | Create, sort and edit menu categories with dish counts. | Dashboard › Menu › Category | owner | — |
| Category hours (menu by time) | Serve a category only at set times, e.g. breakfast 7–11 am; the website shows "Served 7–11 am". | Dashboard › Menu › Category › Hours | owner | — |
| Menu items | Add dishes with photo (crop + preview), price, type, prep time, calories and description. | Dashboard › Menu › Menu Items | owner | — |
| Sizes (variations) | Half / full, small / large etc. with their own price. | Dashboard › Menu › Menu Items › Variations | owner | — |
| Nutrition and diet tags | Calories, nutrition, ingredients and tags like High protein, Jain, Vegan, shown to guests. | Dashboard › Menu › Menu Items | owner | — |
| In stock / out of stock / hide | Switch a dish off for today or hide it. | Dashboard › Menu › Menu Items | owner, staff | — |
| Sort mode | Drag dishes into the order you want. | Dashboard › Menu › Menu Items › Sort | owner | — |
| Add-ons | Extras like extra cheese or dips that guests can add to a dish. | Dashboard › Menu › Add-ons | owner | — |
| Menu translation | Dish names are translated automatically when you choose a non-English language. | Dashboard › Settings › Region and language | owner | Uses a free Google service that can break |
| Photo Gallery | Ready food photos to download and use for dishes. | Dashboard › Menu › Photo Gallery | owner | Super Admin must switch it on |
| Meal plans (tiffin) | Weekly meal / tiffin plans with a weekly menu. | Dashboard › Subscriptions › Meal Plans | owner | Super Admin must switch on Meal plans |
| Subscribers | See and manage customers on meal plans; today's deliveries are created by a button or a cron job. | Dashboard › Subscriptions › Subscribers | owner | Super Admin switch; cron for automatic daily orders |
| Menu from photos (AI) | Upload photos/PDF of a paper menu and AI types out the dishes for you. | Super Admin › Restaurants › Menus from photos | super admin | Valid Gemini key |

## Customer website & online ordering

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Restaurant website | Every restaurant gets its own ordering website. | `restrogrow.com/{restaurant}` | customer | — |
| Home page | Banners, story photos, Today's Special, Bestsellers, combos, categories and search. | `/{restaurant}` | customer | — |
| Menu page | Full menu with veg switch, search, sizes and add-ons; dishes outside their hours show the hours instead of Add. | `/{restaurant}/menu` | customer | — |
| Dish details sheet | Photo, sizes (chosen after tapping Add), add-ons, cooking note, ingredients and nutrition. | Website › tap a dish | customer | — |
| Offer filter bubble | Quick filters like "Under ₹99", "50% OFF", Bestsellers, Veg only. | Website menu (floating bubble) | customer | Set in Dashboard › Offers › Offer filters |
| Favourites | Guests heart dishes and find them again. | Website › heart icon; Profile | customer | — |
| Cart | Bill details, offers and coupons, "Complete your meal" suggestions, note chips. | `/{restaurant}/cart` | customer | — |
| Order types | Delivery, takeaway and dine-in, each switchable. | Website › cart | customer | Dashboard › Settings › Ordering |
| Delivery check by pincode or distance | Shows if the address is covered and the delivery charge (by pincode zone or per km). | Website › cart (address) | customer | Delivery › Zones or Settings › Delivery |
| Map pin for address | Guest drops a pin for the delivery address. | Website › cart (address) | customer | Google Maps key (falls back to OpenStreetMap) |
| Schedule for later | Order now for a later time. | Website › cart | customer | — |
| Milestone coupons | Progress bar "add ₹X more to unlock…", auto-applies with confetti. | Website › cart | customer | Coupons with a milestone in Offers › Coupons |
| Pay online / cash / UPI QR | Pay by PhonePe, cash, or scan your UPI QR and upload the payment screenshot. | Website › cart › Payment | customer | PhonePe needs keys + Super Admin switch "Online pay" |
| Pay with wallet or points | Use wallet money or loyalty points at checkout. | Website › cart | customer | Wallet / loyalty switched on |
| Order tracking | Live status with animation per step, "ready around / arrives around" time, rider card. | `/{restaurant}/track` | customer | — |
| Customer login | Sign up / log in with phone and password, forgot password by email. | `/{restaurant}/login` | customer | SMTP email for password reset |
| WhatsApp OTP | One-time code on WhatsApp at sign-up and on a new device. | `/{restaurant}/login` | customer | RepeatGrow keys + approved OTP template |
| Continue with Google (customers) | Guests log in with Google. | `/{restaurant}/login` | customer | Google login keys |
| Profile and order history | Saved address, past orders with reorder, favourites, rate an order. | `/{restaurant}/profile` | customer | — |
| Rate your order | Guests rate orders; results go to Dashboard › Feedback. | Website › Profile › order | customer | — |
| Ask for a Google review | Every 3rd completed order the guest is asked to rate you on Google. | Website › Profile | customer | Google Places key + your Google place |
| Install as app (PWA) | Guests can "install" your website on their phone with your logo. | Website › install prompt | customer | HTTPS |
| Catering request | Guests ask for a catering quote with a live price estimate. | `/{restaurant}/catering` | customer | **Half-built:** no link to this page anywhere on the website — guests must be sent the address |
| Meal plan subscription | Guests browse plans, subscribe, pay and pause/skip days. | `/{restaurant}/plans`, `/subscribe`, `/my-subscription` | customer | Super Admin switch; PhonePe for online pay |
| About and Contact pages | About text, map, hours and social links. | `/{restaurant}/about`, `/{restaurant}/contact` | customer | Fill profile in Settings |
| Policy pages | Privacy, terms, refund, shipping and cookie pages with ready default text. | `/{restaurant}/privacy-policy` etc. | customer | Edit in Dashboard › Policy Pages |
| Closed / expired pages | Shows "We're closed" outside hours, and a friendly page if the restaurant's plan has ended. | Website (automatic) | customer | — |
| Fake-order protection | Limits orders per phone/IP and blocks a number after 3 fake orders. | Automatic; list in Dashboard › Orders › Blocked Customers | owner | — |

## Delivery

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Delivery zones | Pincodes you deliver to, each with its own charge and time. | Dashboard › Delivery › Zones | owner | — |
| Delivery by distance | Charge per km within a radius instead of zones. | Dashboard › Settings › Delivery | owner | Restaurant location pin set |
| Packaging charge and minimum order | Add a packaging fee; set a minimum order value. | Dashboard › Settings › Delivery / Ordering | owner | — |
| Delivery riders (staff role) | Switched on per restaurant in Settings › Delivery › "We deliver with our own riders" (off by default; everything below is hidden and refused while off). Riders are staff with their own login (role "Delivery rider"). They see only their deliveries: directions, call the customer, Picked up → On the way → Delivered, cash to collect, today's deliveries and cash in hand. | Staff app (rider layout) or the website (rider screen) | staff (rider) | Add riders in Staff |
| Assign riders | Give a delivery order to a rider, change or take it back; or let riders pick open deliveries themselves. | Order details › Delivery; Delivery › Riders; Partner app / Staff app › More › Riders | owner, manager | — |
| Riders board and cash | Every rider with what they carry, deliveries today, last location and cash collected; Settle cash at the end of the shift. Delivered cash-on-delivery orders are marked paid. | Dashboard › Delivery › Riders; apps › More › Riders | owner, manager | — |
| "On the way" email | The customer gets an email with the rider's name and a Track button when the rider picks the order up. | Automatic | customer | Emails switched on |
| Rider page (no app) | For outside riders: print/share a QR per order; the rider opens it, gets directions and taps Picked up → On the way → Delivered. | Order details › Delivery QR / Share link | outside rider | — |
| Live rider location | The rider's phone shares GPS while a delivery is under way, so the guest and you can see where the food is. | Rider app / rider page; guest sees it on Track | customer, owner | Rider allows location |
| Delivery map | Live map of all active deliveries. | Dashboard › Delivery › Map View | owner | Riders use the rider page |

## Customers, loyalty & rewards

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Customers list | All customers with visits, sorting, add/edit/delete and CSV export. | Dashboard › Customers | owner | — |
| Blocked customers | Phone numbers/IPs blocked for fake orders, automatic or by hand. | Dashboard › Orders › Blocked Customers | owner | — |
| Loyalty program | Choose cashback (goes to wallet), points, or a stamp card; set rates and the reward. | Dashboard › Growth › Loyalty Studio › Program | owner | — |
| Verify redemption | Type a guest's 5-character gift code to check and use it. | Dashboard › Growth › Loyalty Studio › Program | owner, staff | — |
| Loyalty levels (tiers) | Silver/Gold-style levels where higher levels earn faster. | Dashboard › Growth › Loyalty Studio › Program | owner | — |
| Free item rewards | Rewards like a free dessert after enough points. | Dashboard › Growth › Loyalty Studio › Program | owner | — |
| Counter loyalty QR | A QR at the counter that guests scan to join rewards. | Dashboard › Growth › Loyalty Studio › Program (Loyalty Card QR) | owner, customer | — |
| Autopilot gifts | Automatic gifts for welcome, birthday, anniversary, "we miss you", level-up, every Nth order, referral. | Dashboard › Growth › Loyalty Studio › Autopilot | owner | Best with the loyalty cron; WhatsApp sending needs RepeatGrow |
| Games: scratch card / spin wheel | After an accepted order, guests scratch or spin to win a discount for next time, within your monthly budget. | Dashboard › Growth › Loyalty Studio › Games (and Offers › Scratch card) | owner, customer | — |
| Challenges | "Visit 4 times this month", spend, try-a-dish and weekly streak challenges with rewards. | Dashboard › Growth › Loyalty Studio › Games | owner, customer | — |
| VIP membership | Paid monthly/quarterly/yearly plans with % off, free delivery, faster points and a welcome gift. | Dashboard › Growth › Loyalty Studio › VIP | owner, customer | PhonePe for online joining (otherwise wallet only) |
| Phone wallet card | A loyalty card in Apple Wallet / Google Wallet that updates by itself. | Dashboard › Growth › Loyalty Studio › Phone card | owner, customer | **Waiting:** Apple and Google Wallet credentials |
| Loyalty performance | Money loyalty brought in, return on gifts, repeat rate, 12-week trend, unused points owed. | Dashboard › Growth › Loyalty Studio › Performance | owner | — |
| Rewards page for guests | Join, see points/stamps, levels, gifts, challenges, redeem, invite friends, join VIP. | `/{restaurant}/loyalty` | customer | Loyalty switched on |
| Referrals | Guests invite friends with a link; both get a bonus; see top referrers. | Dashboard › Growth › Referrals; guest link `?ref=CODE` | owner, customer | — |
| Customer segments | New, repeat, high spender and lapsed customers, with points adjust. | Dashboard › Growth › Customer Segments | owner | — |
| Growth reports | Customer lifetime value, churn risk, last 30 vs previous 30 days, loyalty and referral revenue (CSV/PDF). | Dashboard › Growth › Reports | owner | — |
| Feedback | Ratings and reviews from guests with average, good/bad counts and filter. | Dashboard › Feedback | owner | — |
| Customer wallet | Guests keep money with you, top up with bonuses ("add ₹500 get ₹50") and pay from it. | Dashboard › Offers › Wallet; guest: Website › Profile | owner, customer | PhonePe for top-ups |
| Loyalty on the POS | Points, stamps and cashback also work for counter bills. | Dashboard › POS › Customer | owner, staff | — |

## Offers

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| All offers overview | One page showing every offer tool, on/off, and what coupons sold in 30 days. | Dashboard › Offers › All offers | owner | — |
| Coupons | Discount codes (% or ₹), minimum order, limits, dates; see status and how each performed. | Dashboard › Offers › Coupons | owner | — |
| Milestone offers | "Spend ₹X, get Y" with a progress bar in the cart. | Dashboard › Offers › Coupons (milestone) | owner | — |
| Offer filters | The quick filter chips in the website menu bubble. | Dashboard › Offers › Offer filters | owner | — |
| Combos & deals | Build combos with choices (any 1 pizza + any drink), extra cost per size, dates and a live preview. | Dashboard › Offers › Combos & Deals | owner | — |
| Combos / New buttons | Category jump buttons for combos and new dishes on the website. | Dashboard › Offers › Combos & Deals (Menu filter buttons) | owner | — |
| Bestsellers | Pick and order the bestseller carousel, with optional offer price. | Dashboard › Offers › Bestsellers | owner | — |
| Today's Special | Dishes for each weekday with chef's note and a daily plate limit ("8 plates left"). | Dashboard › Offers › Today's Special | owner | — |
| Complete your meal (upsell) | Suggests sides and drinks in the cart — automatic, hand-picked, or both. | Dashboard › Offers › Complete your meal | owner | — |
| Scratch card prizes | Prizes, chances, monthly budget and code validity for the after-order scratch card. | Dashboard › Offers › Scratch card | owner | — |
| Wallet settings | Wallet name, top-up limits, bonuses, cashback %, balances and corrections. | Dashboard › Offers › Wallet | owner | PhonePe for guest top-ups |
| Catering prices | Price per guest tiers and the list of catering requests. | Dashboard › Offers › Catering | owner | — |
| Animated offer banners | Moving (animated) offer banners with headline, code, button and end date. | Dashboard › Website Appearance › Banners | owner | — |

## Marketing & WhatsApp

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| AI manager (morning report) | Every morning on WhatsApp: yesterday's sales and what changed, top and falling dishes, low stock, plus one-tap fixes ("reply 1 to switch Amritsari Fish back on"). Ask it anything on WhatsApp or in the page's chat: sales with the change vs the period before, compare weeks, top / least sold dishes, one dish ("how many Paneer Tikka this week"), busiest hours and day, cash vs UPI vs card, channels, new vs returning customers, cancellations, and tips from your own numbers. Answers in the language you ask in (English, Hindi, Hinglish); numbers always from your data, in your restaurant's time zone. The page shows today / yesterday / week / month with % change, a setup checklist, and on phones three tabs (Today · Ask · Settings). **Voice:** the chat's mic types what you say. **Voice mode:** tap the circle, speak, check or edit what it heard, press Send; the answer shows and is spoken in Gemini's natural voice (female or male; the phone's own voice if that fails), as 1–3 natural sentences with the real numbers. Nothing is sent or heard again until you tap. Hindi/Hinglish or English; listening uses the browser's speech (best in Chrome on Android). | Dashboard › Growth › AI manager | owner | Cron every 15 min (cron/owner_brief.php); WhatsApp template for messages outside 24h; GEMINI_API_KEY |
| WhatsApp ordering (AI) | Customers order in a WhatsApp chat in English, Hindi or Hinglish; they get the bill, reply 1 to confirm, and the order lands in Orders with a KOT. They get "accepted / being made / ready" messages. Includes a test chat, table QR codes and a reply box for chats that need a person. | Dashboard › Growth › WhatsApp ordering | owner, customer | RepeatGrow must allow webhooks + contacts, then REPEATGROW_CONNECT_ORDERING=1; GEMINI_API_KEY for AI understanding |
| Brand kit | Save logo, colours, font, phone, tagline and hashtags once; every design uses them. | Dashboard › Marketing (Brand kit) | owner | — |
| Posters | 12 ready poster designs with coupon and QR in Square, Story and Wide sizes. | Dashboard › Marketing › Posters | owner | — |
| Videos | 9 short animated promo videos to download (MP4/WebM). | Dashboard › Marketing › Videos | owner | — |
| Social captions | Ready captions in English, Hinglish or Hindi with hashtags and your link. | Dashboard › Marketing › Social Captions | owner | — |
| Campaign calendar | Festivals and food days with offer ideas; opens poster/caption/coupon already filled. | Dashboard › Marketing › Campaign Calendar | owner | Festival dates run to 2031 |
| Tracked campaign links | Each campaign gets a link and QR so you can see opens and orders it brought. | Dashboard › Marketing (campaign bar) | owner | — |
| My campaigns | Saved designs with opens and orders. | Dashboard › Marketing › My campaigns | owner | — |
| WhatsApp automatic messages | Sends the autopilot gifts (birthday, we miss you…) on WhatsApp. | Dashboard › Growth › WhatsApp › Automatic messages | owner | RepeatGrow keys + approved templates |
| WhatsApp campaigns | Send "We miss you", weekend treat, new dish, thank-you campaigns with personal gift codes. | Dashboard › Growth › WhatsApp › Campaigns | owner | RepeatGrow keys + approved templates |
| WhatsApp message log | Every WhatsApp message sent, with status. | Dashboard › Growth › WhatsApp › Message log | owner | — |
| Use your own WhatsApp number | Connect the restaurant's own number through RepeatGrow (button or API key); templates created in one click. | Dashboard › Growth › WhatsApp › Settings | owner | **Waiting:** RepeatGrow side of "Connect" not built; API-key paste works |
| RestroGrow WhatsApp number | Send from RestroGrow's shared number. | Dashboard › Growth › WhatsApp › Settings (Quick start) | owner | **Waiting:** shows "Coming soon" |
| Quiet hours and limits | No messages at night; max campaigns per customer per week; test number. | Dashboard › Growth › WhatsApp › Settings | owner | — |
| Purchase order on WhatsApp | Send a purchase order to a supplier on WhatsApp. | Dashboard › Menu › Inventory › Purchase orders | owner | — |
| Announcements from RestroGrow | Notices from the RestroGrow team appear at the top of the dashboard. | Dashboard (top bar) | owner | Sent from Super Admin › Growth › Announcements |

## Emails

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Email system (outbox + log) | Every automatic email in one branded design, queued so checkout never waits, retried 3 times, logged. All emails tab shows each email as it looks, who gets it and when, with a test send; plus the sent log (preview, send again), on/off per email, and whether the cron jobs run. | Super Admin › Tools › Email | super admin | SMTP_ENABLED=true + Google Workspace login in .env; cron every 10 min (cron/email_dispatch.php) for reminders |
| Owner life-cycle emails | Welcome with a 5-step setup list after signup; trial ends in 3 days / 1 day / today; trial ended; renewal in 7 days / 1 day; plan expired; payment received. | Automatic | owner | SMTP_ENABLED=true + Google Workspace login in .env |
| Invoice by email | Email any invoice from the invoice generator to the restaurant (change the address first if needed); paid ones go as a receipt. | Super Admin › Invoice generator › list › mail button | super admin | SMTP_ENABLED=true + Google Workspace login in .env |
| Security and support emails | Owner gets an email when the account is opened on a new device, when support replies to a ticket, and branded password-reset links (owner, customer, Super Admin). | Automatic | owner, customer | SMTP_ENABLED=true + Google Workspace login in .env |
| Lead alerts to the team | Contact form, chatbot call-back requests and new signups email the RestroGrow team; contact-form visitors get a "we got your message" reply. | Automatic (goes to MAIL_TEAM) | super admin | SMTP_ENABLED=true + Google Workspace login in .env; optional MAIL_TEAM |
| Order emails to customers | Order confirmed with the bill, accepted / ready / cancelled updates (takeaway and delivery), and the e-bill when the order is completed. Sent in the restaurant's name with its logo. The restaurant gets a "new order" email. | Automatic (customer typed an email, or has one on file) | customer, owner | SMTP_ENABLED=true + Google Workspace login in .env |
| Table booking emails | Booking received, confirmed or cancelled, and a reminder on the day. | Automatic | customer | SMTP_ENABLED=true + Google Workspace login in .env |
| Customer welcome and gift emails | Welcome when a customer makes a website account; loyalty gifts (birthday, we miss you…) by email for customers who don't get WhatsApp messages. | Automatic | customer | SMTP_ENABLED=true + Google Workspace login in .env |
| AI manager report by email | The morning report can also come to the owner's email. | Dashboard › Growth › AI manager › Settings | owner | SMTP_ENABLED=true + Google Workspace login in .env |

## Reports & analytics

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Home dashboard | Today vs yesterday, things needing attention, orders in progress, 7-day chart, today's bookings and bestsellers. | Dashboard › Dashboard | owner | — |
| Reports | Sales, customers, top items, payment methods, hourly and staff reports with CSV/PDF. | Dashboard › Reports | owner | Payment-method filter is broken (see Problems found) |
| Analytics | "What to do now" tips, sales trend, next-7-day forecast, busy hours, weekdays, categories, order sources. | Dashboard › Analytics | owner | — |
| Menu analysis | Which dishes are stars, which sell together, which never sell, and lost orders. | Dashboard › Analytics | owner | — |
| Customer analytics | Top customers, regulars who stopped coming, low ratings to read, website visitors. | Dashboard › Analytics | owner | — |
| Payments report | Money collected by method and day vs previous period, still to collect, CSV. | Dashboard › Payments › Payments | owner | — |
| Sales CSV export | Download orders, customers, staff and payments as spreadsheets. | Export buttons on Orders, Customers, Staff, Payments | owner | — |
| Branches overview | Today's revenue, orders and share per branch. | Dashboard › All branches | owner (multi-branch) | Branch login |
| Manager's today report | Today's sales and payment mix for managers. | Manager screen › Reports | staff (manager) | — |
| Website visit tracking | Counts visits and where visitors came from. | Dashboard › Analytics (Website) | owner | — |

## Inventory

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Inventory overview | Stock value, low stock and what needs buying at a glance. | Dashboard › Menu › Inventory › Overview | owner | — |
| Stock items | Ingredients with quantity, restock, adjust and wastage. | Dashboard › Menu › Inventory › Stock | owner | — |
| Recipes | Link dishes to ingredients so stock goes down by itself with every order. | Dashboard › Menu › Inventory › Recipes | owner | — |
| Live stock deduction | POS, kitchen and website orders reduce stock; cancelled orders put it back. | Automatic | owner | Recipes set |
| Auto sold-out | A dish is marked out of stock when its ingredients run out. | Automatic (switch in Inventory) | owner | Recipes set |
| Low-stock alerts | Phone alert when an ingredient runs low. | Dashboard › Menu › Inventory | owner | Order alerts on |
| Purchase orders | Create, print, send and receive orders from suppliers (receiving adds stock); suggested order. | Dashboard › Menu › Inventory › Purchase orders | owner | — |
| Suppliers | Supplier list with contacts. | Dashboard › Menu › Inventory › Suppliers | owner | — |
| Stock count, activity and reports | Count physical stock, see every stock change, and stock reports. | Dashboard › Menu › Inventory › Stock count / Activity / Reports | owner | — |

## Payments & billing

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Payment gateway | Take online payments through RestroGrow's account (paid to you in 2 days) or your own PhonePe. **India only** (PhonePe takes rupees): restaurants in other countries see "contact support", and their customers, wallet top-ups, VIP and meal plans never reach PhonePe. | Dashboard › Payments › Gateway | owner | Super Admin "Online pay" switch + PhonePe keys; Super Admin can't switch it on outside India |
| Payment methods | Add your own methods (e.g. Paytm QR) that show in POS and reports. | Dashboard › Payments › Gateway › Payment methods | owner | — |
| Business UPI QR | Upload your UPI QR; guests pay and upload a screenshot. | Dashboard › Settings › Payments | owner | — |
| Confirm payment screenshots | Check and confirm the screenshot a guest uploaded. | Dashboard › Orders (order details) | owner, staff | — |
| Update payment status | Mark an order paid / part paid / refunded. | Dashboard › Orders › Online Orders / Orders | owner, staff | — |
| Missed-payment checker | A background job confirms PhonePe payments whose callback was missed. | Automatic (tools/reconcile_pending_payments.php) | super admin | Cron job + PhonePe keys |
| Tax on the bill | Tax name and % on bills; GSTIN/PAN printed on the bill. | Dashboard › Settings › Tax and bill | owner | — |
| RestroGrow plan and renewal | See plan / trial days left and renew with PhonePe. The prices shown and charged are the ones in Super Admin › Settings (never what the page sends), paid only to RestroGrow's own PhonePe account. Outside India the popup shows WhatsApp / email support instead. | Dashboard › Profile (Manage Subscription), renewal popup | owner | Platform PhonePe keys in main/.env (otherwise demo mode, which activates nothing). Auto-Pay is hidden until recurring payments are built |
| Payment links (platform) | Send a restaurant a PhonePe link to pay its RestroGrow bill. | Super Admin › Money › Payment links | super admin | PhonePe keys; paying does not extend the plan by itself |
| Invoice generator | Make GST invoices for restaurants: plan, months, extras, discount, paid or due, PDF. | Super Admin › Money › Invoice generator | super admin | Business details + GSTIN in Super Admin › Settings |
| Invoices & GST export | Monthly list of invoices and a CSV for your accountant. | Super Admin › Money › Invoices & GST | super admin | — |
| Country defaults | A new restaurant gets its country's currency, time zone and tax name/rate (Nepal: Rs., Asia/Kathmandu, VAT 13%); tax starts switched on only in India. | Signup; Super Admin › New restaurant | owner, super admin | — |

## Google & SEO

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| SEO health score | Checklist with one-tap fixes so Google shows the restaurant properly. | Dashboard › SEO | owner | — |
| Google page titles | Title and description for each page with Google and WhatsApp previews; "Write it for me" (AI). | Dashboard › SEO | owner | AI writing needs a valid Gemini key |
| Google tracking codes | Search Console verification, Google Analytics and Meta Pixel. | Dashboard › SEO › Google & tracking | owner | Your own GA / Pixel IDs |
| Google rich results and sitemap | Tells Google your dishes, hours and prices; automatic sitemap. | Dashboard › SEO › What Google reads | owner | — |
| Search performance | Clicks and searches from Google Search Console. | Dashboard › SEO | owner | **Waiting:** shows "Coming soon" |
| Google Business: reviews | See Google rating and reviews, with AI reply drafts. | Dashboard › Google Business › Reviews | owner | **Waiting:** Google OAuth keys + Google API approval (page says "Coming soon") |
| Google Business: posts, profile, insights | Post updates, edit phone/hours/description, see calls and direction requests. | Dashboard › Google Business | owner | **Waiting:** same as above |
| Get more Google reviews | Review link as table QR, WhatsApp link, card or website button, with tracking. | Dashboard › Google Business › Get reviews | owner, customer | **Waiting:** same as above (needs Places key + your place) |
| Restaurant sitemaps | Sitemap and robots file for every restaurant site and custom domain. | `/{restaurant}/sitemap.xml` | — | — |

## Website look & settings

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Live phone preview | See website changes on your phone (scan a QR) before going live. | Dashboard › Website Appearance | owner | — |
| Brand and header | Display name, logo, favicon and 15 header styles. | Dashboard › Website Appearance › Brand | owner | — |
| Story photos | Up to 10 Instagram-style photos at the top of the site. | Dashboard › Website Appearance (Story header) | owner | — |
| Colours and fonts | Theme presets, colours, 6 fonts, background. | Dashboard › Website Appearance › Colours and font | owner | — |
| Home and menu layouts | Home dish layouts, menu card designs, categories on the side or top, cut-out category pictures. | Dashboard › Website Appearance › Home page / Menu page | owner | — |
| Bottom bar and profile style | Bottom navigation style, icon packs, custom icons and 10 profile page designs. | Dashboard › Website Appearance › Navigation and profile | owner | — |
| Banner slideshow | Photo banners on the home page. | Dashboard › Website Appearance › Banners | owner | — |
| Policy page editor | Edit privacy, terms, refund, shipping and cookie pages. | Dashboard › Policy Pages | owner | — |
| Custom domain | Use your own web address (e.g. spicegarden.in) with a DNS check; QR codes switch to it. | Dashboard › Custom Domain | owner | Buy a domain + add a DNS record |
| Embed on another website | Code to put the ordering menu inside a Wix / WordPress / HTML site. | Dashboard › Custom Domain (Embed) | owner | — |
| Restaurant profile | Name, owner, tagline, phone, email. | Dashboard › Settings › Restaurant profile | owner | — |
| Location | Address, Google Maps link and pin. | Dashboard › Settings › Location | owner | Google Maps key for the pin map |
| Business hours | Opening hours per day. | Dashboard › Settings › Business hours | owner | — |
| Ordering switches | Delivery / takeaway / dine-in, table reservations, minimum order. | Dashboard › Settings › Ordering | owner | — |
| Region and language | Country, currency, time zone and 19 languages. Picking a country also picks its currency and time zone; a restaurant outside India still on India's clock gets a warning to change it. | Dashboard › Settings › Region and language | owner | — |
| WhatsApp to the right country | Every WhatsApp code and message goes to the restaurant's own country code (+977 for Nepal, +44 for the UK); numbers typed with the code, 00 or a leading 0 work too. | Automatic (signup, logins, password reset, customer accounts, loyalty, campaigns) | all | — |
| Printer settings | 58/80 mm paper, Bluetooth / USB / network / browser, test print, printer log. | Dashboard › Settings › Printer | owner | Network printer only works if the server can reach it |
| Social links | Instagram, Facebook etc. shown on the website. | Dashboard › Settings › Social links | owner | — |
| How to use guide | Built-in picture guide that opens at the page you were on, with a quick-start checklist. | Dashboard › How to use | owner | — |

## Security & accounts

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Profile and password | See account details, change password (strength meter), recent sign-ins. | Dashboard › Profile | owner | — |
| New device check | A code is needed when someone logs in from a new device. | Login | owner, staff, customer | Email/WhatsApp working |
| Login lockout | Too many wrong passwords locks the login for 30 minutes. | Automatic | all | — |
| Login log and IP blocking | Record of every login; suspicious IPs blocked. | Super Admin › System › Security & activity; Partner app › More › Security | owner, super admin | — |
| Install dashboard as app | Install the dashboard on a phone or computer like an app. | Dashboard › Settings › Account › Install app | owner | HTTPS |
| Web push notifications | Phone/desktop notifications for orders, calls and stock. | Dashboard › Settings › Account | owner, staff | — (keys made automatically) |
| Error monitor | Errors from all restaurants collected for the RestroGrow team (with Sentry). | Super Admin › System › Error monitor | super admin | Sentry (already set) |
| Unlock OTP / reset password | Super admin can unlock a locked restaurant or reset its password. | Super Admin › Restaurants › ⋮ menu | super admin | — |
| Owner-only pages | Staff cannot open owner pages (activity log, loyalty, security). | Automatic | — | — |
| Fingerprint / Face ID lock | Lock the phone apps with fingerprint or face. | Partner / Staff / Super Admin app › Settings | owner, staff, super admin | Phone with biometrics |
| Safe plan payments | The renewal amount and length are decided by the server from Super Admin › Settings, and checked with RestroGrow's own PhonePe account (a changed page or a restaurant's own test keys can't buy a plan). | Automatic | — | — |

## Apps

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Partner app (Android) | The restaurant app for owners and staff. | Android APK (`app/`) | owner, staff | Install the APK |
| Partner app: Home | Today's sales, 14-day chart, things needing attention, top dishes, shortcuts. | Partner app › Home | owner, staff | — |
| Partner app: Orders | Accept, reject, move orders forward, set prep time, print bill/KOT, share rider link. | Partner app › Orders | owner, staff | — |
| Partner app: POS | Full counter billing with sizes, add-ons, combos, customer, points, wallet, coupon, split pay, printing. | Partner app › POS | owner, staff | — |
| Partner app: Menu | Edit dishes, prices, photos, stock on/off. | Partner app › Menu | owner, staff | — |
| Partner app: Kitchen, tables, calls, bookings | Kitchen tickets, table status, waiter calls and reservations. | Partner app › More › Service | owner, staff | — |
| Partner app: Customers and reports | Customer list and Today / 7 days / 30 days reports. | Partner app › More › Business | owner | — |
| Partner app: Loyalty | Program, offers, autopilot and performance. | Partner app › More › Loyalty | owner | — |
| Partner app: Security | Logins, failed attempts, lockouts, new devices. | Partner app › More › Security | owner | — |
| Partner app: Settings | Printer, hours, ordering, tax and bill, alerts, password. | Partner app › More › Settings | owner, staff | — |
| Order alerts when app is closed | The Android app keeps ringing for new orders and waiter calls even when closed. | Partner app (automatic) | owner, staff | Full APK build (not Expo Go) |
| Staff app | A simpler app for waiters, chefs and managers, with tabs by role. | Staff app (`staff-app/`) | staff | Install the app |
| Super Admin app | Your own app: overview, restaurants, renewals, support, leads, payments and more. | Super Admin app (`superadmin-app/`) | super admin | Live site must have the app login file deployed |
| Super Admin app: web panel inside | Menus, settlements, invoice generator and error monitor open the web panel inside the app. | Super Admin app › More | super admin | — |
| Customer app template | A branded app for a restaurant's own customers (home, menu, cart, orders, profile). | `customer-app/` | customer | **Half-built:** demo only — fixed menu, any OTP works, no real payment or orders |
| App sign-in with Google | Log in to the Partner app with Google. | Partner app › Login | owner, staff | Google login keys |
| Welcome slides | First-time welcome screens before login. | Partner app (first open) | owner, staff | — |
| Partner app web demo | Try the Partner app in a browser with fake data, in a phone frame. | `restrogrow.com/app-demo/` | anyone | — |

## Super Admin (platform)

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Platform dashboard | Restaurants, active, today's sales and orders across all restaurants, shortcuts. | Super Admin › Overview › Dashboard | super admin | — |
| Platform analytics | Monthly/yearly revenue (MRR/ARR), trials, paying, lost, top and at-risk restaurants. | Super Admin › Overview › Analytics | super admin | — |
| Access per restaurant | Choose which dashboard sections each restaurant gets (POS, Kitchen, Online orders, Tables, Reservations, Inventory, Customers, Staff, Payments, Reports, Loyalty & Growth, Offers, Marketing, Google & SEO, Delivery, Feedback, Website design): whole sections or single pages, or a ready set (Everything, POS only, Online ordering, Loyalty & Growth only). Off pages vanish from their sidebar and are blocked on the server, and from the Partner and Staff apps within about 20 seconds (tabs, More, home shortcuts). They stop for customers too: e.g. Loyalty off = no points or rewards on the website or POS, Coupons off = only reward codes work, Reservations / Catering pages close, WhatsApp gifts and the AI manager report stop. Dashboard, Menu, Orders, Settings, Profile are always on. | Super Admin › Restaurants › Access (on each row); Super Admin app › restaurant › Dashboard sections | super admin | — (everything on by default); apps from Partner 1.2.0 / Staff 1.0.3 |
| Usage & sign-ups | What every restaurant uses, only looked at or never touched (46 features, counts, last used, page opens, logins), and the sign-up funnel with drop-off per step. | Super Admin › Tools › Usage & sign-ups | super admin | — |
| New sign-up + setup guide + tour | 3-step sign-up into the dashboard, a self-ticking "Get ready" guide and an animated tour, saved so they never ask twice; unfinished sign-ups become leads; reminders on day 1, 3, 7. | restrogrow.com › Start free trial; Dashboard home; Help | owner | WhatsApp welcome/reminders need 2 approved templates |
| Undo design | Pick the look of the Undo box (Dark pill, Countdown ring, Snackbar, Floating glass), each tried live first. | Super Admin › Tools › Undo design | super admin | — |
| Restaurants list | All restaurants with filters (active, trial, expired), search; **Export CSV** downloads every matching restaurant (all pages) with country, phone, plan, days left and online pay. | Super Admin › Restaurants › Restaurants | super admin | — |
| Add a restaurant | Create a new restaurant login with country; currency, time zone and tax follow the country. | Super Admin › Restaurants › New restaurant | super admin | — |
| Feature switches | Turn on/off per restaurant: online pay, install app, WhatsApp orders, photo gallery, meal plans. | Super Admin › Restaurants (row switches) | super admin | — |
| Bulk actions | Tick many restaurants to switch features, extend, enable/disable or send a notice. | Super Admin › Restaurants (tick boxes) | super admin | — |
| Restaurant 360 view | Everything about one restaurant: plan, sales, orders chart, logins, payments, tickets, errors. | Super Admin › Restaurants › click name | super admin | — |
| Open their dashboard | Log into a restaurant's dashboard in one click to help them. | Super Admin › Restaurants › Admin | super admin | — |
| Send login on WhatsApp | Send a new restaurant its login details on WhatsApp. | Super Admin › Restaurants › ⋮ › Send login | super admin | Phone number on file |
| Renewals | Plans ending soon or ended; payment link, WhatsApp reminder, extend. | Super Admin › Restaurants › Renewals | super admin | — |
| Branch admins | One login linked to several restaurants. | Super Admin › Restaurants › Branch admins | super admin | — |
| All coupons | Every restaurant's coupons in one place. | Super Admin › Restaurants › Coupons | super admin | — |
| Leads | Sales enquiries with stages (new → won), follow-up dates, call/WhatsApp, "create restaurant". | Super Admin › Growth › Leads | super admin | — |
| Chat leads | Leads and full chats from the AI chatbot on restrogrow.com. | Super Admin › Growth › Chat leads | super admin | Valid Gemini key |
| Support tickets | Answer restaurants' Help & support messages. | Super Admin › Growth › Support | super admin | — |
| Announcements | Show a notice bar on all or chosen restaurant dashboards. | Super Admin › Growth › Announcements | super admin | — |
| Subscriptions | Activate, extend or expire restaurant plans. | Super Admin › Money › Subscriptions | super admin | — |
| Subscription payments | All plan payments; search by restaurant, ID or transaction and filter by status. | Super Admin › Money › Payments | super admin | — |
| Settlements | Pay restaurants their online-payment money to UPI. | Super Admin › Money › Settlements | super admin | PhonePe payout keys; without them it is saved as Failed ("pay by hand"), never as paid |
| Security & activity | Logins, suspicious IPs, blocked IPs, and a log of every Super Admin change. | Super Admin › System › Security & activity | super admin | — |
| Platform health | Database size, uploads, errors, WhatsApp queue (shows if the cron is not running), backups. | Super Admin › System › Platform health | super admin | — |
| Platform settings | Trial days, prices, invoice business details, UPI and bank details. | Super Admin › System › Settings | super admin | — |
| Email test | Send a test email from any sender address. | Super Admin › System › Settings › Email check | super admin | SMTP in main/.env |
| Admin handbook | Read the team handbook and docs inside the panel. | Super Admin › Tools › Admin handbook | super admin | — |
| Features (this list) | This feature list with search. | Super Admin › Tools › Features | super admin | — |
| Demo restaurant | Create, reset or remove the Spice Garden demo restaurant. | Super Admin › Tools › Demo restaurant | super admin | — |
| Super Admin login and reset | Separate login with forgot-password. | restrogrow.com/main/superadmin/ | super admin | Reset email goes to one fixed Gmail address |
| Database tools | Links to run database indexes and migrations. | Super Admin › System › Settings | super admin | — |
| Superadmin app login | Lets the Super Admin phone app sign in. | Super Admin app | super admin | — |
| Blog | restrogrow.com/blog in a magazine design: dark hero, featured post, categories, tags, search and pages; post pages with the cover beside the title, numbered sections, a numbered contents rail that follows your reading, a reading bar, FAQ cards, a boxed summary, sign-up boxes, share buttons, related posts and a free-trial band; RSS feed (/blog/feed.xml) and blog sitemap (/sitemap-blog.xml). Fully SEO-ready: meta tags, Open Graph, Article, Breadcrumb and FAQ structured data. | restrogrow.com/blog | customer (visitors) | — |
| Blog editor | Write posts with headings, lists, links, images (upload, paste or drag; resized and saved as WEBP) and videos; cover image with alt text; categories and tags; draft, publish now or schedule; feature on the blog home; preview drafts; duplicate; views per post. | Super Admin › Tools › Blog | super admin | — |
| Blog SEO tools | Focus keyword, SEO title, meta description, keywords and canonical URL with a live 15-point SEO score and a Google preview; Fill with AI (Gemini) for the SEO fields; Write a draft with AI from a topic and keyword. | Super Admin › Tools › Blog › editor | super admin | GEMINI_API_KEY for the AI buttons |
| Blog keyword plan | List of the searches to rank for (type, priority, monthly searches, notes, status idea → planned → written), which posts cover each, gaps with no post yet, "Write post" from a keyword, bulk add (also "keyword \| intent \| priority \| volume \| notes" lines, so the researched plan in docs/BLOG_KEYWORD_PLAN.md pastes in one go), and AI keyword ideas. | Super Admin › Tools › Blog › Keywords | super admin | — |
| Blog starter posts | 10 ready-written posts from the keyword plan (KOT, KOT printers, Swiggy and Zomato fees, WhatsApp orders, billing software prices, dhaba profit, kitchen screens), added as drafts with one click; each passes the SEO checklist except the cover. | Super Admin › Tools › Blog › Add 10 starter posts | super admin | Review, add a cover, publish |
| Blog keyword research | 20 keywords from Google India's real search suggestions, checked against who ranks today, in 4 topic groups, with the first 10 posts and rules for each post. | docs/BLOG_KEYWORD_PLAN.md | super admin | — |

## Demo & sales tools

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Demo restaurant | "Spice Garden Indiranagar" with menu, photos, tables, staff, customers and 6 weeks of orders. | Owner login demo / demo | anyone | Created from Super Admin › Tools › Demo restaurant |
| One-tap demo page | Private page with buttons to open the owner dashboard, website, staff screens and apps already logged in. | `restrogrow.com/demo/` | sales | — |
| Demo safety | Changes in the demo are locked and WhatsApp never sends from it. | Automatic | — | — |
| Demo coupons | WELCOME50, SPICE20, FEAST100, WEEKEND15 work on the demo website. | `restrogrow.com/spice-garden-indiranagar` | anyone | — |
| Partner app demo | The Partner app in a phone frame with device picker. | `restrogrow.com/app-demo/` | anyone | — |
| Customer app demo | Preview of a restaurant's own customer app with "make it yours" (name, colour). | `restrogrow.com/customer-app-demo/` | anyone | — |
| Marketing website | RestroGrow home page with features, pricing, screenshots and contact form. | `restrogrow.com` | anyone | — |
| AI chatbot on restrogrow.com | Answers visitors' questions and saves them as leads. | `restrogrow.com` (chat bubble) | anyone | Valid Gemini key |
| FAQ and About pages | Public FAQ, about and legal pages. | `restrogrow.com/faq/`, `/about/`, `/privacy-policy/` etc. | anyone | Blog is an empty "coming soon" page |
| Year-end goal page | Internal team countdown to 31 Dec with progress and checkpoints (not a product feature). | `restrogrow.com/until-the-year-ends/` | team | — |

## Planned, not built

These are ideas and plans written in the docs. **None of these exist in the product yet.**

| Feature | What it does | Where | Who | Needs setup? |
|---|---|---|---|---|
| Voice ordering for waiters | Planned, not built (parked). | docs/IMPORTANT_TODO.md #3 | staff | Parked |
| Swiggy / Zomato / ONDC orders in POS | Planned, not built: aggregator orders arrive in the POS. | docs/IMPORTANT_TODO.md #4 | owner | Planned, not built; needs partner/approval |
| Custom access per staff member | Planned, not built: switch each permission on or off per person, saved custom roles. | docs/IMPORTANT_TODO.md #5 | owner | Build when asked |
| Restaurants outside India (Nepal first) | Planned, not built: restaurant time zone in every report and timer, Nepal VAT bills (VAT-inclusive prices, service charge, PAN, bill numbers per fiscal year, Nepali dates), right country code in WhatsApp links, Google showing NPR, Nepal festivals, Nepali language, eSewa / Khalti. | docs/IMPORTANT_TODO.md #6 | owner, customer | Build when asked |
| Smart upsell (AI) | Planned, not built: AI suggestions at checkout and POS. | docs/AI_TODO.md #1 | customer, staff | Planned, not built |
| Demand forecast for prep and stock | Planned, not built: how much to prepare and buy. | docs/AI_TODO.md #3 | owner | Planned, not built |
| AI review replies and complaint summary | Planned, not built (reply drafts exist inside Google Business, which is waiting). | docs/AI_TODO.md #4 | owner | Planned, not built |
| AI menu helpers | Planned, not built: AI dish descriptions, photo touch-up, price ideas. | docs/AI_TODO.md #6 | owner | Planned, not built |
| AI phone-order agent | Planned, not built (later). | docs/AI_TODO.md #7 | customer | Planned, not built |
| "Reply on Google" button | Planned, not built. | docs/GOOGLE_BUSINESS_TODO.md | owner | Planned, not built |
| Review QR on the bill | Planned, not built. | docs/GOOGLE_BUSINESS_TODO.md | customer | Planned, not built |
| Alerts for 1–3 star reviews | Planned, not built. | docs/GOOGLE_BUSINESS_TODO.md | owner | Planned, not built |
| Birthday / welcome bonus points | Planned, not built (as points; gifts already exist in Autopilot). | README roadmap | customer | Planned, not built |
| WhatsApp points-balance messages | Planned, not built. | README roadmap | customer | Planned, not built |
| Time-of-day upsell suggestions | Planned, not built. | README roadmap | customer | Planned, not built |
| Group cart and thali milestone bar | Planned, not built. | README roadmap | customer | Planned, not built |
| PDF / Excel reports | Planned, not built (CSV and some PDF exist today). | README roadmap | owner | Planned, not built |
| Multi-language customer website | Planned, not built (only dish names translate today). | README roadmap | customer | Planned, not built |
| Automated backups | Planned, not built. | README roadmap | super admin | Planned, not built |

## Problems found while making this list

Things that exist but are half-built or broken. Fix or finish when there is time. (On 17 Oct 2026 these were fixed and taken off: Reports payment filter, Auto-Pay and two dead Settings switches hidden, Loyalty Autopilot text, the leftover file that could wipe a menu, Super Admin CSV export and Payments page, the broken Database test link, `admin/index.php`, Settlements recording fake payouts, and Super Admin screens that stopped at 50 restaurants; `/reservations` on custom domains was already fixed.)

- **Restaurants outside India** still run on India's clock in most reports and timers, get Indian-style bills and +91 WhatsApp links; the full list is in docs/IMPORTANT_TODO.md #6.
- **Tax 0% shows as 5%** on the customer cart (`website/cart.php` ~2877, `taxPercent || 5`).
- **Restaurants open past midnight** refuse website orders after 12 (`api/process_website_order.php` ~367).

- **Catering page has no link** on the customer website; guests can only reach `/{restaurant}/catering` by typing it.
- **Payment links / due invoices:** a paid payment link, or marking a due invoice paid later, does not extend the restaurant's plan — extend it by hand.
- **Payment-link mode** (demo / sandbox / live) is kept only for your login session and resets on every login.
- **Old pages inside the dashboard** (Meal Plans, Subscribers, Blocked Customers) may show their full old layout inside the frame.
- **Two delivery pricing systems** (pincode zones and per-km) live in two different places — easy to confuse.
- **Menu translation** uses a free unofficial Google service that may stop working.
- **No cron jobs seem to be scheduled** (scheduled orders, meal-plan deliveries, loyalty autopilot, WhatsApp sending). The dashboard does this work only while it is open.
- **Festival dates** in the campaign calendar end in 2031.

## Check it yourself: 15-minute tour

Open https://restrogrow.com/demo/ (or log in as owner **demo / demo**). Click through these in order:

1. **Dashboard › Dashboard** — today's numbers and "Needs attention" (1 min).
2. **Customer website** — open `restrogrow.com/spice-garden-indiranagar`, add a dish with a size and add-on, open the cart, try coupon **WELCOME50**, and place a cash order (2 min).
3. Back in the dashboard: the **new-order popup** rings — accept it with a "ready in" time. Then open **Orders › Online Orders** and move it to Ready (1 min).
4. **Orders › KOT** and the **chef screen** (from `/demo/` › Kitchen) — see the ticket on the kitchen board (1 min).
5. **POS** — make a counter bill for a table, attach a customer, use points, hold it, resume, pay (2 min).
6. **Tables › Table Map** and **Reservations** — the live floor and the day's bookings (1 min).
7. **Menu › Menu Items** and **Menu › Category › Hours** — edit a dish and see "menu by time" (1 min).
8. **Menu › Inventory › Recipes** — how stock goes down by itself with every order (1 min).
9. **Offers › All offers**, then **Combos & Deals** and **Complete your meal** (1 min).
10. **Growth › Loyalty Studio** — Program, Autopilot, Games, VIP and Performance tabs (1 min).
11. On the website, open `/spice-garden-indiranagar/loyalty` — what guests see for rewards (30 sec).
12. **Marketing › Posters** — make one poster with a coupon and QR; peek at **Campaign Calendar** (1 min).
13. **Analytics** — "What to do now" tips and menu analysis (1 min).
14. **Website Appearance** — change a colour and scan the QR to see the live phone preview (1 min).
15. **Settings › Printer** and the **Partner app demo** at `restrogrow.com/app-demo/` (30 sec).
