# RestroGrow Customer App (template)

The app a restaurant's **customers** install from the Play Store to order food: browse the menu,
customise dishes, apply coupons, order delivery / takeaway / dine-in, track the order, earn
points, save favourites and addresses, book a table.

It is a **template**: nothing here is published. It shows a restaurant owner exactly how their
own app would look, and is the starting point we brand and ship for them. The sibling apps are
`../app` (owner / partner), `../staff-app` and `../superadmin-app`.

Live preview (with a "make it yours" panel): `/customer-app-demo/` (linked from `/demo`).

## Make it a restaurant's own

1. `config/brand.js`: name, tagline, colour, address, hours, fees, tax, loyalty name. Pick or
   add a preset and set `ACTIVE_PRESET` (or build with `EXPO_PUBLIC_PRESET=leaf`). Every colour
   in the app is derived from `primary`.
2. `app.json`: `name`, `slug`, `android.package` (e.g. `com.tandoortales.app`), icon, splash.
3. `data/restaurant.js` (+ `data/menu.json`, `assets/menu/`): the menu. Today it is the demo
   restaurant's menu, bundled. For a real restaurant, load it from their RestroGrow account.

## Run

```bash
cd customer-app
npm install
npm run web        # http://localhost:8084
npm start          # Expo Go: scan the QR code
```

Browser overrides (web only): `?preset=leaf&name=My Cafe&color=7048E8`.

## Build the browser demo

```bash
# live (what restrogrow.com/customer-app-demo/ serves; commit the output)
EXPO_PUBLIC_DEMO=1 EXPO_BASE_URL=/customer-app-demo/app npx expo export -p web --output-dir ../customer-app-demo/app
# local XAMPP copy (gitignored)
EXPO_PUBLIC_DEMO=1 EXPO_BASE_URL=/menuwebsite/customer-app-demo-local/app npx expo export -p web --output-dir ../customer-app-demo-local/app
```

On Windows Git Bash, prefix with `MSYS_NO_PATHCONV=1` or the base URL gets rewritten.
`customer-app-demo/index.html` (device frames + brand panel) is generated from
`app-demo/index.html`; the local copy is the same file next to its own `app/`.

## What is real and what is simulated

| Real in the template | Simulated until a restaurant is connected |
|---|---|
| Menu browsing, search, veg filter, serving hours, sizes, notes | Menu data is bundled, not fetched |
| Cart, coupons (`WELCOME50` etc.), GST, delivery / packaging fees, free-delivery bar | Coupon rules are local copies |
| Orders, history, reorder, favourites, addresses, points / tiers (saved on the device) | Orders never reach a kitchen |
| Live order tracker (sped up so a visitor sees every step in ~90 s) | Real status comes from the restaurant dashboard |
| OTP login screens | Any 4 digits are accepted; payment screen takes no money |

Connecting it (next step): replace `data/restaurant.js` with the website API
(`main/website/api.php`, `main/api/process_website_order.php`), add a restaurant slug to
`config/brand.js`, use the existing PhonePe flow for payments and Expo push for status.

## Layout

```
config/brand.js     the one file a restaurant edits
theme/              colours derived from the brand, fonts, shadows
data/               menu.json, images.js (generated require map), restaurant.js (menu, offers)
store/              AppContext (persisted state), bill.js (totals), orderFlow.js (tracker)
screens/            Auth, Home, Menu, Item (sheet), Cart, Checkout, Track, Orders, Profile, More
components/         ui.js (buttons, chips, steppers), food.js (rows, cards, cart bar), TabBar.js
```

Photo credits: `/demo-credits.txt` (Wikimedia Commons).
