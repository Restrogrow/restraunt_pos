# RestroGrow POS — Restaurant Management System

A full-featured restaurant management platform with online ordering, admin dashboard, POS, delivery tracking, reservations, staff management, and multi-restaurant support.

**Live demo:** [restrogrow.com](https://restrogrow.com)

---

## 📋 Quick Navigation

- [Features](#-features)
- [Recent Updates](#-recent-updates)
- [Project Structure](#-project-structure)
- [Tech Stack](#-tech-stack)
- [Quick Start](#-quick-start)
- [Roadmap](#-roadmap)

---

## 🚀 Features

### 🧾 Online Ordering (Customer Website)
- **Mobile-first** responsive customer website with phone-frame layout
- **Menu browsing** — category filters, live search, item details bottom sheet
- **Cart management** — quantity controls, add-ons, variations
- **3 order types** — Dine-in, Takeaway, Delivery (toggle per restaurant)
- **Delivery zones** — pincode-based availability + charge calculation
- **Coupons & discounts** — percentage/fixed, expiry dates, minimum order validation
- **Payments** — Cash on Delivery + **PhonePe** payment gateway
- **Smart checkout** — restaurant hours validation, minimum order enforcement, delivery charge, packaging charge, GST
- **Order tracking** — enter order number + phone to see live status & rider location on map
- **Customer profile** — order history, ratings & reviews, feedback
- **QR table scanning** — dine-in ordering from table QR codes
- **Call Waiter** — table-side customer requests sent to staff dashboard
- **Custom domain** — each restaurant gets its own domain URL
- **PWA** — manifest, service worker, install prompt for "Add to Home Screen"

### 📊 Admin Dashboard
- **Real-time overview** — revenue, orders, KOT stats with live updates
- **New order notifications** — popup with sound (polls every 10s)
- **Pending order badge** — auto-updates every 30s
- **Live order management** — Pending → Accepted → Preparing → Ready → Served → Completed
- **Online orders view** — auto-refresh for new incoming orders
- **KOT system** — Kitchen Order Ticket with status management, printing
- **Order detail modals** — full order info, items, addons, payment
- **QR code generation** — for delivery rider handoff
- **Analytics dashboard** — page visit tracking, traffic stats, popular items, peak hours, revenue trends
- **Live dashboard clock** — restaurant timezone (Asia/Kolkata)

### 🚚 Delivery Management
- **Delivery zones** — pincode-based CRUD, toggle active/inactive
- **Delivery charges** — per-zone configuration
- **6-status tracking** — Pending → Preparing → Assigned → Picked_Up → In_Transit → Delivered
- **Rider QR page** — no-login access via QR scan
- **Rider live GPS** — real-time location sharing
- **Delivery map view** — Leaflet.js with colored markers, auto-refresh
- **Customer tracking page** — progress bar + live rider location

### 💳 Point of Sale (POS)
- **In-restaurant POS** for walk-in orders
- **Table selection** — area & table management
- **Multiple payment methods** — Cash, Card, UPI
- **KOT generation & printing**
- **Order hold/release**

### 👥 Staff Management
- **Role-based access** — Admin, Manager, Waiter, Chef
- **Staff login** — dedicated dashboards per role
- **Waiter request system** — table-side customer requests with alerts
- **Chef dashboard** — KOT management with status updates
- **Staff activity logs**

### 🍽️ Menu & Inventory
- **Categories (menus)** — sorting, visibility toggles
- **Menu items** — images, descriptions, pricing, availability
- **Item types** — Veg, Non-Veg, Egg, Drink, Dessert
- **Variations** — size/price variations per item (e.g. Small/Medium/Large)
- **Add-ons** — per-item add-on selection with pricing
- **Image upload** — with database storage option

### 📅 Reservations
- **Online table booking**
- **Date/time slot selection** — custom time slots supported
- **Capacity validation** — per-table limits
- **Availability checking** — real-time slot availability
- **Meal type selection** — Breakfast, Lunch, Dinner

### 👤 Customer Management
- Customer database with full order history
- Customer search & profile management
- Per-customer order history view

### ⚙️ Restaurant Settings
- Restaurant info (name, address, phone, email, description)
- Currency symbol & timezone (IST / Asia/Kolkata)
- Opening hours per day of week
- Minimum order value
- Payment gateway config (PhonePe)
- Website theme: colors, banners, backgrounds, logo shape/size
- Mobile layout: 1 or 2 columns
- Custom domain / embed code generation
- WhatsApp order notification toggle
- Delivery zones & charges
- Food station ordering

### 🏷️ Coupons & Deals
- Coupon codes with expiry dates
- Percentage & fixed discount types
- Minimum order amount requirements
- Combo/special deal creation

### 📈 Reports
- Sales reports with date range filtering
- Top-selling items analysis
- Payment method breakdown
- Hourly sales trends
- CSV export

### 📊 Analytics (NEW)
- Real page visit tracking per restaurant
- Traffic sources, hourly/daily trends
- Popular menu items
- Peak ordering hours
- Dashboard with visual stats

### 🔔 Push Notifications (NEW)
- **Web Push (VAPID)** — no third-party service needed
- Admin gets push notifications on **new orders**
- Works in: browser, PWA installed, **APK from PWABuilder**
- Visible opt-in prompt on admin login ("Enable Notifications?")
- Subscription stored per user in database
- Dead subscription auto-cleanup

### 📱 Admin PWA / TWA App
- **PWA manifest** with proper id, display_override, launch_handler
- **Service worker** with push event handling
- **PWABuilder compatible** — generate APK for sideloading
- Notifications work inside the installed APK

### 🏢 Superadmin Panel
- Multi-restaurant management
- Subscription billing & payment tracking
- PhonePe subscription payments
- Settlement payouts
- Global settings

---

## 🆕 Recent Updates

| Date | Feature | Details |
|------|---------|---------|
| Jul '26 | **Push Notifications** | Web Push (VAPID) system — admins get notified on new orders. Visible opt-in prompt on login. Works in browser, PWA, and APK. |
| Jul '26 | **Admin TWA App** | PWA manifest + service worker for admin panel. PWABuilder APK generation support. |
| Jul '26 | **Analytics Dashboard** | Page visit tracking, traffic stats, popular items, peak hours, daily trends. |
| Jul '26 | **Custom Domain Routing** | Full custom domain support — restaurant gets its own URL. Slug-based routing everywhere. |
| Jul '26 | **POS/KOT Fixes** | Addon handling in KOT, duplicate helper extraction, status column fix. |
| Jul '26 | **Profile Page Redesign** | Dark mode, stats, avatar, filtered order history. |
| Jul '26 | **PhonePe Payments** | Production PhonePe payment gateway integration + subscription payments. |
| Jul '26 | **UX Improvements** | Loading states, empty states, pagination on list endpoints. |
| Jul '26 | **Security Fixes** | Mass assignment guards, file upload validation, PDO exception handling. |

---

## 📁 Project Structure

All application files are in the `main/` directory:

```
main/
├── admin/              # Admin panel — login, auth, PWA manifest
├── api/                # API endpoints — orders, menu, payments, analytics, push
├── assets/             # CSS, JS, images
├── config/             # DB, session, email, rate-limit, env loader, push helper
├── controllers/        # Business logic — menu, orders, staff, POS, KOT
├── database/           # SQL schemas, migration scripts
├── delivery/           # Rider delivery tracking page
├── docs/               # Documentation, audit reports
├── public/             # Public image serving
├── superadmin/         # Multi-restaurant management panel
├── uploads/            # User uploads — logos, banners, menu images
├── views/              # Dashboard views — admin, manager, waiter, chef
├── website/            # Customer-facing website — menu, cart, ordering
├── db_connection.php   # DB connection (auto-detect local vs production)
└── index.php           # Main entry point / router
```

---

## 🧰 Tech Stack

| Layer | Technology |
|-------|-----------|
| **Backend** | PHP 8+ (vanilla, no framework) |
| **Database** | MySQL (PDO with prepared statements) |
| **Frontend** | Vanilla JavaScript, CSS3 |
| **Maps** | Leaflet.js (delivery tracking) |
| **Icons** | Font Awesome 6, Material Symbols, Bootstrap Icons |
| **Image Crop** | Cropper.js |
| **Payments** | PhonePe API |
| **Email** | PHPMailer / SMTP |
| **Push Notifications** | Web Push (VAPID) via `minishlink/web-push` |
| **Charts** | Custom CSS-based |

---

## ⚡ Quick Start

### Local (XAMPP)

```bash
# 1. Clone to htdocs
git clone https://github.com/sujaysarraf-dev/restraunt_pos.git menuwebsite

# 2. Import database
# Use: main/database/full_database_dump.sql

# 3. Install PHP dependencies (for Web Push)
cd menuwebsite
php composer.phar install

# 4. Configure .env
# Copy main/.env.example → main/.env
# Generate VAPID keys: php main/admin/generate_vapid.php

# 5. Access
# Customer: http://localhost/menuwebsite/{restaurant-slug}
# Admin:    http://localhost/menuwebsite/main/admin/login.php
```

### Production (Hostinger)

The code auto-detects Hostinger servers (`hstgr.io`) and connects via local MySQL socket — **no remote MySQL config needed**.

---

## 📌 Roadmap

### ✅ Done
- [x] Online ordering (menu, cart, checkout)
- [x] Admin dashboard with order management
- [x] KOT system with chef/waiter dashboards
- [x] Delivery tracking with rider GPS
- [x] PhonePe payment gateway
- [x] Coupons & deals
- [x] Reservations
- [x] Staff management (role-based)
- [x] Restaurant settings & theme customization
- [x] Custom domain support
- [x] Analytics dashboard
- [x] **Push notifications** (Web Push for admin)
- [x] **Admin PWA/TWA app** (PWABuilder APK)
- [x] Multi-restaurant superadmin panel
- [x] Subscription billing system

### 🔜 In Progress / Up Next

- [ ] **Landing page redesign** — SaaS-style hero, pricing, features showcase
- [ ] **Customer accounts** — registration, saved addresses, favorites, reorder
- [ ] **KOT push alerts** — notify chef/waiter when new KOT is generated
- [ ] **Status change push alerts** — notify admin when order status changes
- [ ] **Email notifications** — HTML templates, configurable recipients
- [ ] **Visual reports** — charts, trend analysis, PDF/Excel export
- [ ] **Multi-language support** — i18n for customer website
- [ ] **WhatsApp ordering** — direct WhatsApp cart share
- [ ] **Inventory management** — stock tracking, low stock alerts
- [ ] **Table management** — visual table map, merge/split tables
- [ ] **Advanced analytics** — customer lifetime value, retention, churn
- [ ] **Automated backups** — DB + file backup scheduling

---

## 🐛 Known Limitations

| Area | Detail |
|------|--------|
| **Hosting** | Shared hosting MySQL connection limits (~150-200 concurrent) |
| **Email** | Hostinger SMTP limits (~300/day) |
| **Push** | Requires HTTPS for service worker (works on custom domains) |
| **Scaling** | VPS recommended beyond 10+ restaurant clients |

---

## 🤝 Contributing

This is a private project. For feature requests or bug reports, contact the maintainer.

---

<p align="center">Built with ❤️ for restaurants</p>
