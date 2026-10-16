# Delivery rider role (17 October 2026)

**Status: built (17 October 2026).** On/off per restaurant: Settings › Delivery › "We deliver with our own riders" (`rider_settings.own_riders`, off by default; the demo has it on). Server: `main/config/rider_engine.php`, `main/api/rider_app.php`, `main/api/delivery_dispatch.php`.
Web: `main/assets/js/riders-board.js` (Delivery › Riders + Assign in order details), `main/views/rider_dashboard.php`.
Apps: Staff app `screens/RiderHomeScreen.js` (rider layout, location with expo-location), `RidersScreen.js` and
`components/RiderAssign.js` in both the Partner app and the Staff app. Demo riders: `rider.demo@spicegarden.test`,
`rider2.demo@spicegarden.test` (password `demo`). The phone apps need a new APK for the rider screens (location module).

The restaurant's own delivery riders become staff with their own login, on the web and in the Staff app.
The owner or a manager assigns each delivery order to a rider; the rider sees only their own deliveries,
marks them picked up / on the way / delivered, collects cash on delivery, and shares their live location
with the customer's tracking page. Cash the rider collected is settled with the restaurant at the end of
the shift.

## What already existed (kept)
- `delivery_tracking` per delivery order: status (Assigned → Picked_Up → In_Transit → Delivered),
  rider name / phone, live location, timestamps.
- "Generate QR for Rider": a one-time link for an outside rider with no login (`delivery/rider-page.php`).
  Stays, for freelancers and aggregator riders.
- Customer tracking page (`/{restaurant}/track`): rider name, call button, live map. Works with both.

## New
**Server**
- Staff role `Delivery` (shown as "Delivery rider"). Login sends a rider to the rider screen.
- `config/rider_engine.php`: tables/columns (`delivery_tracking.rider_staff_id`, `assigned_at`,
  `cod_amount`, `cod_collected_at`, `cash_settled_at`, `cash_settled_by`), the status steps (one function
  used by the QR page and the rider app), assign / unassign, cash settle.
- `api/rider_app.php` (rider only): my deliveries, open deliveries to pick up (if the owner allows it),
  status, cash collected, live location.
- `api/delivery_dispatch.php` (owner / manager): riders with their load and cash in hand, delivery orders
  with who has them, assign, settle cash, "riders can pick orders themselves" switch.
- A rider's session sees only the orders assigned to them; it can't use the other order screens.
- Customer: "on the way" email when the rider picks the order up (the tracking page already updates).

**Web dashboard**
- Staff › add member: role "Delivery rider".
- Orders (delivery orders): "Assign rider" next to "Generate QR for Rider".
- Delivery › Riders: live board of riders (active deliveries, cash in hand, last location, Settle cash)
  and delivery orders waiting for a rider.
- Rider screen in the browser (`views/rider_dashboard.php`) for riders without the app.

**Apps**
- Staff app: rider layout (my deliveries with big step buttons, call, Google Maps, cash to collect,
  location sharing while on a delivery, today's deliveries and cash).
- Partner app and the Staff app (manager): assign a rider on a delivery order; Riders board in More.

**Docs, demo**
- Demo restaurant gets a rider (`rider.demo@spicegarden.test` / `demo`); `/demo` links to the rider app.
- Feature list, handbook, README.

## Later (not in this build)
- Rider pay per delivery / distance, rider ratings, auto-assign to the nearest rider, route batching.
