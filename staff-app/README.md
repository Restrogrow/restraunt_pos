# RestroGrow Staff

The app for a restaurant's staff: waiters, chefs and managers. It is built from the partner
app (`../app`): same backend, same login (`main/admin/auth.php`), same screens. Each role gets
only the pages its login is allowed to use.

| Role | Tabs | Alerts |
|---|---|---|
| Waiter | Home · Tables · POS · Orders · More (Kitchen, Waiter calls, Bookings, Customers) | new online orders, waiter calls, food ready to serve |
| Chef | Home · Kitchen · Orders · More (Tables) | new kitchen tickets |
| Manager (also owners / branch admins) | Home (sales) · Orders · POS · Menu · More (everything) | new online orders, waiter calls |

Staff log in with the email or phone and password set under Staff in the RestroGrow dashboard.
Restaurant settings (details, hours, tax, coupons, logo) stay with the owner; staff see only
this device's settings (printer, alerts, fingerprint lock) and can change their own password.

## Run

```bash
cd staff-app
npm install
npm run web        # http://localhost:8083 (the partner app uses 8081)
npm start          # Expo Go on a phone: scan the QR code
```

In dev the app calls its own address and `metro.config.js` forwards `/menuwebsite/*` to XAMPP,
so XAMPP (Apache + MySQL) must be running. Release builds call https://restrogrow.com/main.

Local demo logins (Spice Garden Indiranagar), password `demo`:
`ravi@spicegardenindiranagar.com`, `suresh@spicegardenindiranagar.com`, `neha@spicegardenindiranagar.com`.

## Differences from the partner app

- `App.js` picks the tabs from the role (`utils/roles.js`, `LAYOUT`); `screens/ShiftHomeScreen.js`
  is the waiter / chef Home, with the next step one tap away on each row (chef: Start / Ready
  and a "What to cook" total; waiter: Done on a call, Served on ready food).
- Kitchen tickets and waiter calls come from one shared live feed (`utils/liveStore.js`, kept
  fresh by `components/LiveFeed.js`, which also raises each role's alerts). Every action patches
  it at once and refreshes it, so Home, Kitchen, badges and the other phones agree within seconds.
- The bell in the Home and Kitchen headers mutes alert sounds (the buzz and banner stay). The
  Kitchen screen keeps the display awake while it's open, for a tablet on the counter.
- No Android background order service (`order-watch`) and no browser demo mode, so the app
  also runs in Expo Go. Bluetooth printing still needs a development build.
- Android package `com.restrogrow.staff`, so it installs next to the partner app.
- Sections Super Admin switches off for the restaurant (Restaurants › Access) leave the tabs, More
  and Home shortcuts within about 20 seconds (`utils/access.js`, same file as the partner app's;
  the list comes from `get_session.php` as `access_off`). An open screen shows "Not switched on".
