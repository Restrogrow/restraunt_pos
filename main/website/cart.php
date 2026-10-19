<?php require_once __DIR__ . '/header.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<base href="<?php echo $website_base_href; ?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="restaurant-id" content="<?php echo htmlspecialchars($restaurant_id ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<?php include __DIR__ . '/cart_scope.php'; ?>
<meta name="restaurant-slug" content="<?php echo htmlspecialchars($restaurant_slug ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Cart - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?>">
<link rel="manifest" href="manifest.php<?php echo $restaurant_id ? '?restaurant_id=' . urlencode($restaurant_id) : ''; ?>">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars($restaurant_logo ?? $local_placeholder_svg, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? $local_favicon_svg, ENT_QUOTES, 'UTF-8'); ?>">
<title>Cart - <?php echo htmlspecialchars($restaurant_name ?? 'Dvani Cafe & Grill', ENT_QUOTES, 'UTF-8'); ?></title>
<?php require_once __DIR__ . '/font_links.php'; echo websiteFontLinks(['poppins', 'material-symbols', 'fontawesome']); ?>
<?php if (!empty($font_family_google_param)): ?><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=<?php echo $font_family_google_param; ?>:wght@300;400;500;600;700&display=swap" rel="stylesheet"><?php endif; ?>
<!-- Google Maps Places Autocomplete (Delivery address) -->
<?php $googleMapsApiKey = function_exists('googleMapsApiKey') ? googleMapsApiKey() : ''; ?>
<?php if ($googleMapsApiKey): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo urlencode($googleMapsApiKey); ?>&libraries=places&loading=async" async defer></script>
<?php endif; ?>
<!-- PhonePe SDK (loaded only when needed) -->
<style>
:root {
  --primary-red: <?php echo htmlspecialchars($primary_red, ENT_QUOTES, 'UTF-8'); ?>;
  --dark-red: <?php echo htmlspecialchars($dark_red, ENT_QUOTES, 'UTF-8'); ?>;
  --primary-yellow: <?php echo htmlspecialchars($primary_yellow, ENT_QUOTES, 'UTF-8'); ?>;
  --site-font: <?php echo $font_family_css; ?>;
  --card-radius: <?php echo htmlspecialchars($card_radius_css, ENT_QUOTES, 'UTF-8'); ?>;
  --btn-radius: <?php echo htmlspecialchars($btn_radius_css, ENT_QUOTES, 'UTF-8'); ?>;
  --checkout-color: <?php echo htmlspecialchars($checkout_color, ENT_QUOTES, 'UTF-8'); ?>;
  --checkout-color-dark: <?php echo htmlspecialchars($checkout_color_dark, ENT_QUOTES, 'UTF-8'); ?>;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: var(--site-font);
  background: #e8ecf2;
  color: #1a1b1f;
  min-height: 100vh;
  overflow-x: hidden;
}
.phone-frame {
  max-width: 425px;
  margin: 0 auto;
  min-height: 100vh;
  background: #fff;
  position: relative;
  box-shadow: 0 0 40px rgba(0,0,0,0.08);
}
@media (min-width: 768px) {
  .phone-frame { margin: 20px auto; min-height: calc(100vh - 40px); border-radius: 28px; overflow: hidden; }
<?php if ($host === 'triposhsymmetry.in'): ?>
  /* TEMPORARY (added 2026-08-18, for PhonePe merchant approval — remove
     this block in ~2 days): full-width desktop layout for this restaurant
     only, so the checkout/payment screen isn't a narrow phone card on a
     PC. Mobile view (no media query) is untouched. */
  .phone-frame { max-width: 100%; margin: 0; border-radius: 0; }
<?php endif; ?>
}

.bg-wrapper {
  background: #fff;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

/* Header */
.pr-share-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px 12px 12px;
  border-bottom: 1.5px solid #ccc;
}
.back-btn-cmon {
  display: flex; align-items: center; justify-content: center;
  width: 40px; height: 40px; border-radius: 8px;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff; border: none; cursor: pointer; font-size: 22px;
  flex-shrink: 0;
}
.header-brand {
  flex: 1;
  font-size: 16px;
  font-weight: 700;
  color: #1a1b1f;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  min-width: 0;
}
.header-actions {
  display: flex; align-items: center; gap: 8px;
}
.header-action-btn {
  display: flex; align-items: center; justify-content: center;
  width: 40px; height: 40px; border-radius: 8px;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff; border: none; cursor: pointer; font-size: 18px;
  flex-shrink: 0;
}

/* Scrollable content */
.content {
  flex: 1;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding-bottom: 100px;
}

/* Card style */
.card {
  background: #fff;
  border-radius: 14px;
  border: 1.5px solid #000;
  overflow: hidden;
  box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}

.card-pad {
  padding: 14px;
}

/* Cart Item */
.cart-item {
  display: flex;
  align-items: center;
  gap: 12px;
}
.cart-item img {
  width: 70px; height: 70px;
  border-radius: 10px;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid #eee;
}
.item-info { flex: 1; min-width: 0; }
.item-name { font-weight: 600; font-size: 14px; color: #1a1b1f; }
.item-variant { font-size: 12px; color: var(--primary-red, #e17055); font-weight: 500; margin-top: 1px; }
.item-price { font-weight: 600; font-size: 14px;  color: #2d3436; margin-top: 4px; }
.item-price span { color: #888; font-weight: 400; }
.qty-ctrl {
  display: flex; align-items: center;
  gap: 0; margin-top: 8px;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  border-radius: 8px;
  overflow: hidden;
  width: fit-content;
}
.qty-ctrl button {
  width: 28px; height: 28px;
  border: none; background: transparent;
  color: #fff; font-size: 16px; font-weight: 700;
  cursor: pointer; display: grid; place-items: center;
  font-family: var(--site-font);
}
.qty-ctrl span {
  min-width: 22px; text-align: center;
  color: #fff; font-size: 14px; font-weight: 600;
}
.delete-btn {
  width: 36px; height: 36px; border-radius: 8px;
  border: none; cursor: pointer;
  display: grid; place-items: center;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff; font-size: 16px;
  align-self: flex-start;
  margin-left: auto;
  transition: opacity 0.2s, transform 0.2s;
  flex-shrink: 0;
}
.delete-btn:hover { opacity: 0.88; transform: scale(1.08); }
.delete-btn:active { transform: scale(0.95); }

/* ── Cart redesign: soft cards, one "Your order" list, quiet delete, floating bar ── */
.content { background: #f5f6f8; padding-bottom: 110px; }
.card { border: 1px solid #eceef1; border-radius: 16px; box-shadow: 0 2px 12px rgba(17,24,39,0.05); }
.order-card { padding: 2px 14px 0; }
.order-head { display: flex; justify-content: space-between; align-items: baseline; padding: 12px 0 8px; }
.order-head strong { font-size: 15px; font-weight: 800; color: #111827; }
.order-head span { font-size: 12px; color: #6b7280; font-weight: 500; }
.order-row { display: flex; gap: 12px; padding: 12px 0; border-top: 1px solid #f1f2f4; }
.order-row img { width: 60px; height: 60px; border-radius: 12px; object-fit: cover; flex-shrink: 0; background: #f3f4f6; }
.order-main { flex: 1; min-width: 0; }
.order-name-row { display: flex; align-items: flex-start; gap: 7px; }
.veg-mark { width: 13px; height: 13px; flex-shrink: 0; margin-top: 3px; border: 1.5px solid #16a34a; border-radius: 3px; display: flex; align-items: center; justify-content: center; }
.veg-mark i { width: 6px; height: 6px; border-radius: 50%; background: #16a34a; }
.veg-mark.nonveg { border-color: #dc2626; } .veg-mark.nonveg i { background: #dc2626; }
.veg-mark.egg { border-color: #f59e0b; } .veg-mark.egg i { background: #f59e0b; }
.order-name { flex: 1; min-width: 0; font-size: 14px; font-weight: 700; color: #111827; line-height: 1.3; }
.order-del { width: 30px; height: 30px; margin: -5px -6px 0 0; flex-shrink: 0; border: 0; border-radius: 8px; background: none; color: #9ca3af; font-size: 13px; cursor: pointer; transition: background 0.15s, color 0.15s; }
.order-del:hover, .order-del:active { background: #fef2f2; color: #dc2626; }
.order-size { margin-top: 2px; font-size: 12px; font-weight: 600; color: var(--primary-red, #e53935); }
.order-addon { margin-top: 2px; font-size: 11.5px; color: #6b7280; }
/* a combo line (Offers > Combos & Deals) */
.order-combo .combo-tag { flex-shrink: 0; display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 999px; background: color-mix(in srgb, var(--primary-red, #e53935) 12%, #fff); color: var(--primary-red, #e53935); font-size: 10.5px; font-weight: 800; }
.order-combo .order-addon { line-height: 1.4; }
.order-note { margin-top: 3px; font-size: 11.5px; color: #b45309; line-height: 1.35; }
.order-bottom { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 9px; }
.qty-pill { display: inline-flex; align-items: center; height: 30px; border: 1.5px solid color-mix(in srgb, var(--primary-red, #e53935) 35%, #fff); border-radius: 10px; background: #fff; overflow: hidden; }
.qty-pill button { width: 30px; height: 100%; border: 0; background: none; color: var(--primary-red, #e53935); font-size: 16px; font-weight: 800; cursor: pointer; font-family: var(--site-font); }
.qty-pill button:active { background: color-mix(in srgb, var(--primary-red, #e53935) 10%, #fff); }
.qty-pill span { min-width: 18px; text-align: center; font-size: 14px; font-weight: 800; color: #111827; }
.order-price { text-align: right; line-height: 1.2; }
.order-price b { display: block; font-size: 14.5px; font-weight: 800; color: #111827; }
.order-price small { font-size: 11px; color: #9ca3af; }
.order-more { display: flex; align-items: center; justify-content: center; gap: 7px; margin: 0 -14px; padding: 13px 0; border-top: 1px dashed #e5e7eb; color: var(--primary-red, #e53935); font-size: 13px; font-weight: 700; text-decoration: none; }
.order-more:active { background: #fafafa; }
body .price-card h3 { font-size: 15px; font-weight: 800; }
body .price-row { font-size: 13px; color: #4b5563; margin-bottom: 9px; }
body .divider { border-top: 1px dashed #d1d5db; margin: 10px 0; }
body .total-row { font-size: 16px; font-weight: 800; color: #111827; }
/* Floating checkout bar, same as the menu's View Cart bar */
body .checkout-bar { bottom: 12px; width: calc(100% - 24px); max-width: 401px; border-radius: 16px; padding: 10px 10px 10px 18px; box-shadow: 0 12px 28px color-mix(in srgb, var(--checkout-color, #e17055) 35%, transparent), 0 4px 10px rgba(0,0,0,0.12); }
@media (min-width: 768px) { body .checkout-bar { bottom: 32px; border-radius: 16px; } }
<?php if ($host === 'triposhsymmetry.in'): ?>
body .checkout-bar { max-width: calc(100% - 24px); }
<?php endif; ?>
body .checkout-bar .total-label { font-size: 14.5px; white-space: nowrap; }
body .checkout-bar .checkout-btn { background: #fff; color: var(--checkout-color, #e17055); font-weight: 800; font-size: 14px; border-radius: 11px; padding: 10px 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.12); transition: transform 0.15s ease; }
body .checkout-bar .checkout-btn:active { transform: scale(0.96); }

.add-more-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  width: 100%;
  padding: 10px;
  margin-top: 10px;
  border: none;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  font-family: var(--site-font);
  cursor: pointer;
  letter-spacing: .3px;
  border-radius: 0 0 14px 14px;
}
.add-more-btn:hover { opacity: 0.92; }

/* Empty cart */
.empty-cart {
  text-align: center;
  padding: 40px 20px;
}
.empty-cart .empty-icon {
  font-size: 48px;
  margin-bottom: 16px;
  opacity: 0.4;
}
.empty-cart h3 {
  font-size: 16px;
  font-weight: 600;
  color: #1a1b1f;
  margin-bottom: 6px;
}
.empty-cart p {
  font-size: 13px;
  color: #888;
  margin-bottom: 20px;
}
.empty-cart .browse-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 10px 24px;
  border: none;
  border-radius: 10px;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  font-family: var(--site-font);
  cursor: pointer;
}

/* Coupon Section - Redesigned */
.coupon-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  margin: -14px -14px 14px;
  background: #faf5f0;
  border-bottom: 1px solid #f0e8e0;
  border-radius: 14px 14px 0 0;
}
.coupon-header .icon-wrap {
  width: 36px; height: 36px;
  border-radius: 10px;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  display: flex; align-items: center; justify-content: center;
  font-size: 17px; color: #fff; flex-shrink: 0;
}
.coupon-header .header-text {
  flex: 1;
}
.coupon-header .header-text strong {
  display: block; font-size: 14px; font-weight: 600; color: #1a1b1f;
}
.coupon-header .header-text span {
  display: block; font-size: 11px; color: #999; font-weight: 400; margin-top: 1px;
}

.coupon-browse {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  margin-top: 12px;
  padding: 10px 12px;
  border: 1.5px dashed #e0d8d0;
  border-radius: 10px;
  transition: all 0.2s;
}
.coupon-browse:hover {
  border-color: var(--primary-red, #e17055);
  background: #fdf8f5;
}
.coupon-browse .badge {
  width: 28px; height: 28px; border-radius: 8px;
  background: #f0ebe5;
  color: var(--primary-red, #e17055);
  display: grid; place-items: center;
  font-size: 14px; font-weight: 700;
}
.coupon-browse span { font-weight: 500; font-size: 13px; color: #1a1b1f; flex: 1; }
.coupon-browse .arrow {
  font-size: 14px; color: #ccc;
  transition: transform 0.2s;
}
.coupon-browse:hover .arrow {
  transform: translateX(3px);
  color: var(--primary-red, #e17055);
}

/* Coupon Input - Redesigned */
.coupon-input-row {
  display: flex; gap: 8px;
  width: 100%; max-width: 100%;
}
.coupon-input-row input {
  flex: 1; min-width: 0; width: 0;
  padding: 10px 14px; border: 2px solid #e8e0d8;
  border-radius: 10px; font-size: 13px; font-family: var(--site-font);
  outline: none; transition: all 0.2s;
  box-sizing: border-box;
  background: #faf8f6;
}
.coupon-input-row input:focus { 
  border-color: var(--primary-red, #e17055); 
  background: #fff;
  box-shadow: 0 0 0 3px rgba(225,112,85,0.1);
}
.coupon-input-row input.error { border-color: #e74c3c; }
.apply-coupon-btn {
  padding: 10px 18px; border: none; border-radius: 10px;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff; font-weight: 600; font-size: 13px;
  cursor: pointer; font-family: var(--site-font);
  white-space: nowrap;
  flex-shrink: 0;
  transition: opacity 0.2s, transform 0.2s;
}
.apply-coupon-btn:hover { opacity: 0.9; transform: translateY(-1px); }
.apply-coupon-btn:active { transform: scale(0.97); }
.apply-coupon-btn:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }
@media (max-width: 360px) {
  .coupon-input-row { flex-direction: column; gap: 6px; }
  .coupon-input-row input { width: 100%; }
  .apply-coupon-btn { width: 100%; padding: 12px; }
}
.coupon-status {
  font-size: 12px; margin-top: 8px;
  display: flex; align-items: center; gap: 8px;
  padding: 6px 10px; border-radius: 8px;
  background: #f9f9f9;
  flex-wrap: nowrap;
}
.coupon-status.success {
  color: #27ae60;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
}
.coupon-status.error {
  color: #e74c3c;
  background: #fef2f2;
  border: 1px solid #fecaca;
}
/* Keep the "CODE applied (X% OFF)" bit on a single line even on narrow
   phones (it was wrapping to 2 lines there while fitting fine on wider
   screens) - truncate with an ellipsis instead of wrapping. */
.coupon-status .cpn-status-text {
  flex: 1; min-width: 0;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.coupon-status .remove-coupon {
  color: #e74c3c; cursor: pointer; font-weight: 600; font-size: 11px;
  text-decoration: underline; margin-left: auto; white-space: nowrap;
  flex-shrink: 0;
}

/* Coupon Bottom Sheet */
.coupon-overlay {
  position: fixed; inset: 0; z-index: 9999;
  background: rgba(0,0,0,0.4);
  opacity: 0; visibility: hidden;
  transition: opacity 0.3s, visibility 0.3s;
}
.coupon-overlay.open { opacity: 1; visibility: visible; }
.coupon-sheet {
  position: fixed; bottom: 0; left: 0; right: 0; z-index: 10000;
  background: #fff; border-radius: 20px 20px 0 0;
  max-height: 70vh; overflow-y: auto;
  transform: translateY(100%); transition: transform 0.3s ease;
  box-shadow: 0 -4px 20px rgba(0,0,0,0.15);
}
.coupon-overlay.open .coupon-sheet { transform: translateY(0); }
.coupon-sheet-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 16px 12px; border-bottom: 1px solid #eee;
  position: sticky; top: 0; background: #fff; z-index: 2;
}
.coupon-sheet-header h3 { font-size: 16px; font-weight: 700; }
.coupon-sheet-close {
  width: 32px; height: 32px; border-radius: 50%;
  border: none; background: #f0f0f0; cursor: pointer;
  font-size: 18px; display: grid; place-items: center;
}
.coupon-list { padding: 12px 16px 24px; display: flex; flex-direction: column; gap: 10px; }
.coupon-item {
  border: 1.5px solid #e8e8e8; border-radius: var(--card-radius, 12px); padding: 14px;
  display: flex; gap: 12px; align-items: flex-start;
  cursor: pointer; transition: border-color 0.2s;
}
.coupon-item:hover {  border-color: #2d3436; }
.coupon-item.active {  border-color: #2d3436; background: #f0f7f5; }
.coupon-item .cpn-icon {
  width: 42px; height: 42px; border-radius: 50%;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff; display: grid; place-items: center;
  font-size: 18px; flex-shrink: 0;
}
.coupon-item .cpn-info { flex: 1; min-width: 0; }
/* .coupon-sheet has no max-width, so it's much wider on a laptop (plenty of
   room for these lines) than on an actual phone viewport - without an
   explicit single-line rule, the same text wraps to 2 lines there. Truncate
   instead so every coupon renders the same compact one-line-per-field card
   regardless of screen width. */
.coupon-item .cpn-code {
  font-weight: 700; font-size: 14px;  color: #2d3436;
  letter-spacing: 1px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.coupon-item .cpn-desc {
  font-size: 12px; color: #555; margin-top: 2px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.coupon-item .cpn-expiry {
  font-size: 11px; color: #999; margin-top: 4px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.coupon-item .cpn-min {
  font-size: 11px; color: #e67e22; margin-top: 2px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.coupon-item .cpn-select {
  padding: 6px 14px; border: none; border-radius: 6px;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff; font-size: 11px; font-weight: 600;
  cursor: pointer; font-family: var(--site-font);
  flex-shrink: 0; align-self: center;
}

/* Delivery */
.delivery-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.delivery-row .icon { font-size: 18px; }
.delivery-row span { font-size: 13px; color: #1a1b1f; font-weight: 500; }
.delivery-row .info-icon {
  margin-left: auto;
  width: 20px; height: 20px; border-radius: 50%;
  background: var(--primary-red, #e17055); color: #fff;
  display: grid; place-items: center;
  font-size: 11px; font-weight: 700; cursor: pointer;
  position: relative;
  z-index: 5;
}
.delivery-row .info-icon::after {
  content: attr(data-tooltip);
  position: absolute;
  bottom: calc(100% + 10px);
  right: -4px;
  left: auto;
  transform: none;
  background: var(--primary-red, #e53935);
  color: #fff;
  padding: 7px 12px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 500;
  white-space: nowrap;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.25s ease;
  box-shadow: 0 4px 12px rgba(0,0,0,0.2);
  letter-spacing: 0.2px;
}
.delivery-row .info-icon::before {
  content: '';
  position: absolute;
  bottom: calc(100% + 4px);
  left: 50%;
  transform: translateX(-50%);
  border: 6px solid transparent;
  border-top-color: var(--primary-red, #e53935);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.25s ease;
}
.delivery-row .info-icon:hover::after,
.delivery-row .info-icon:hover::before {
  opacity: 1;
}

/* Instruction - Redesigned */
.instr-card {
  border: 1.5px solid #f0e8e0;
  border-radius: 14px;
  overflow: hidden;
  transition: box-shadow 0.2s;
  background: #fff;
}
.instr-card:hover {
  box-shadow: 0 2px 12px rgba(0,0,0,0.06);
}
.instr-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
  padding: 14px 16px;
  gap: 10px;
  transition: background 0.2s;
}
.instr-header:hover {
  background: #faf8f6;
}
.instr-header .label-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 1;
}
.instr-header .label-icon {
  width: 32px; height: 32px;
  border-radius: 8px;
  background: #f5f0eb;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px;
  flex-shrink: 0;
}
.instr-header .label-text {
  font-size: 13px; font-weight: 500; color: #1a1b1f;
}
.instr-header .chevron {
  font-size: 14px; color: #bbb;
  transition: transform 0.25s ease;
}
.instr-header.open .chevron { 
  transform: rotate(180deg); 
  color: var(--primary-red, #e17055);
}

.instr-body-inner {
  display: none;
  flex-direction: column;
  gap: 10px;
  padding: 4px 16px 16px;
  border-top: 1px solid #f0e8e0;
}
.instr-body-inner.visible { display: flex; }
.instr-body-inner textarea {
  border: 2px solid #e8e0d8;
  border-radius: 10px;
  padding: 12px 14px;
  font-size: 13px;
  font-family: var(--site-font);
  resize: vertical;
  min-height: 90px;
  color: #444;
  outline: none;
  transition: all 0.2s;
  background: #faf8f6;
  width: 100%;
  box-sizing: border-box;
}
.instr-body-inner textarea:focus { 
  border-color: var(--primary-red, #e17055);
  background: #fff;
  box-shadow: 0 0 0 3px rgba(225,112,85,0.08);
}
.instr-body-inner textarea::placeholder {
  color: #bbb;
}
.char-count { 
  font-size: 12px; color: #bbb; text-align: right; 
  padding: 0 2px;
}
.save-btn {
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff;
  border: none;
  border-radius: 10px;
  padding: 12px;
  font-size: 13px;
  font-weight: 600;
  font-family: var(--site-font);
  cursor: pointer;
  width: 100%;
  transition: opacity 0.2s, transform 0.2s;
}
.save-btn:hover {
  opacity: 0.92;
  transform: translateY(-1px);
}
.save-btn:active {
  transform: scale(0.98);
}

/* ── Checkout form polish (matches the cart + login) ── */
.modal-overlay .modal-box { border-radius: 24px; }
.modal-overlay .modal-header h2 { font-size: 19px; font-weight: 800; }
.modal-overlay .form-group label { font-size: 12.5px; font-weight: 700; color: #374151; }
.modal-overlay .form-group input, .modal-overlay .form-group select { border: 1.5px solid #e5e7eb !important; border-radius: 12px !important; background: #fafafa; min-height: 46px; }
.modal-overlay .form-group input:focus, .modal-overlay .form-group select:focus { border-color: var(--primary-red, #e53935) !important; background: #fff; box-shadow: 0 0 0 4px color-mix(in srgb, var(--primary-red, #e53935) 12%, transparent); }
.modal-overlay .form-group label.order-type-option, .modal-overlay .form-group label.schedule-option { margin-bottom: 0; }
.modal-overlay .order-type-option, .modal-overlay .schedule-option, .modal-overlay .form-group label.order-type-option, .modal-overlay .form-group label.schedule-option { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 5px; border-width: 1.5px !important; border-radius: 14px !important; font-weight: 600; transition: all 0.2s ease; }
.modal-overlay .order-type-option i, .modal-overlay .schedule-option i { font-size: 17px; color: var(--primary-red, #e53935); }
.modal-overlay .order-summary-box { border-radius: 16px; }
.modal-overlay .btn-group .btn { height: 50px; border-radius: 14px; font-weight: 700; }
.modal-overlay .btn-group .btn-primary { box-shadow: 0 10px 22px color-mix(in srgb, var(--primary-red, #e53935) 28%, transparent); }
/* ── Offers & coupons section ── */
.cpn-sec-head { display: flex; align-items: center; justify-content: space-between; margin: 2px 0 12px; }
.cpn-sec-title { display: flex; align-items: center; gap: 10px; font-size: 15px; font-weight: 800; color: #111827; }
.cpn-sec-ico { width: 32px; height: 32px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: color-mix(in srgb, var(--primary-red, #e53935) 10%, #fff); color: var(--primary-red, #e53935); font-size: 14px; }
.cpn-viewall { border: 0; background: none; color: var(--primary-red, #e53935); font-family: var(--site-font); font-size: 12.5px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 5px; padding: 6px 0; }
.cpn-viewall i { font-size: 10px; }
.cpn-ticket { position: relative; display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 14px; background: #f0fdf4; border: 1.5px dashed #86efac; }
.cpn-ticket::before, .cpn-ticket::after { content: ""; position: absolute; top: 50%; width: 14px; height: 14px; margin-top: -7px; border-radius: 50%; background: #fff; border: 1.5px dashed #86efac; }
.cpn-ticket::before { left: -8px; clip-path: inset(0 0 0 50%); }
.cpn-ticket::after { right: -8px; clip-path: inset(0 50% 0 0); }
.cpn-ticket.pop { animation: cpnPop 0.55s cubic-bezier(.34,1.56,.64,1); }
.cpn-ticket-ico { width: 36px; height: 36px; flex-shrink: 0; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: #16a34a; color: #fff; font-size: 14px; }
.cpn-ticket-txt { flex: 1; min-width: 0; line-height: 1.3; }
.cpn-ticket-txt b { display: block; font-size: 14px; font-weight: 800; color: #14532d; letter-spacing: 0.04em; }
.cpn-ticket-txt span { font-size: 12px; color: #15803d; font-weight: 600; }
.cpn-ticket-remove { border: 0; background: none; color: #6b7280; font-family: var(--site-font); font-size: 12px; font-weight: 700; cursor: pointer; padding: 6px 2px; text-decoration: underline; text-underline-offset: 3px; }
.cpn-code-row { display: flex; gap: 8px; }
.cpn-code-box { flex: 1; min-width: 0; display: flex; align-items: center; gap: 9px; height: 44px; padding: 0 12px; border: 1.5px solid #e5e7eb; border-radius: 12px; background: #fafafa; transition: border-color 0.2s, background 0.2s, box-shadow 0.2s; }
.cpn-code-box:focus-within { border-color: var(--primary-red, #e53935); background: #fff; box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary-red, #e53935) 12%, transparent); }
.cpn-code-box i { color: #9ca3af; font-size: 12px; }
.cpn-code-box input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font-family: var(--site-font); font-size: 13.5px; font-weight: 600; letter-spacing: 0.04em; color: #111827; }
.cpn-code-box input::placeholder { letter-spacing: 0; font-weight: 500; color: #9ca3af; }
.cpn-code-box input.error { color: #dc2626; }
.cpn-apply { height: 44px; padding: 0 18px; border: 0; border-radius: 12px; background: var(--primary-red, #e53935); color: #fff; font-family: var(--site-font); font-size: 13.5px; font-weight: 700; cursor: pointer; transition: transform 0.15s; }
.cpn-apply:active { transform: scale(0.96); }
.cpn-error { display: flex; align-items: center; gap: 6px; margin-top: 8px; font-size: 12px; font-weight: 600; color: #dc2626; animation: cpnShake 0.4s ease; }
@keyframes cpnPop { 0% { transform: scale(0.92); opacity: 0.4; } 100% { transform: scale(1); opacity: 1; } }
@keyframes cpnShake { 0%, 100% { transform: none; } 25% { transform: translateX(-4px); } 75% { transform: translateX(4px); } }
@media (prefers-reduced-motion: reduce) { .cpn-ticket.pop, .cpn-error { animation: none; } }
/* ── Note for the restaurant (special instructions) ── */
.note-card { background: #fff; border: 1px solid #eceef1; border-radius: 16px; box-shadow: 0 2px 12px rgba(17,24,39,0.05); overflow: hidden; }
.note-head { display: flex; align-items: center; gap: 12px; padding: 14px; cursor: pointer; -webkit-tap-highlight-color: transparent; }
.note-ico { width: 38px; height: 38px; flex-shrink: 0; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: color-mix(in srgb, var(--primary-red, #e53935) 10%, #fff); color: var(--primary-red, #e53935); font-size: 15px; transition: transform 0.3s ease; }
.note-card.open .note-ico { transform: rotate(-8deg) scale(1.06); }
.note-ico { position: relative; background: linear-gradient(135deg, color-mix(in srgb, var(--primary-red, #e53935) 80%, #fff), var(--primary-red, #e53935)); color: #fff; box-shadow: 0 6px 14px color-mix(in srgb, var(--primary-red, #e53935) 28%, transparent); }
.note-ico svg.hat { width: 21px; height: 21px; }
.note-ico .pen { position: absolute; right: -5px; bottom: -5px; width: 18px; height: 18px; border-radius: 50%; background: #fff; color: var(--primary-red, #e53935); display: grid; place-items: center; box-shadow: 0 1px 4px rgba(0,0,0,.18); }
.note-ico .pen svg { width: 10px; height: 10px; }
.note-card.open .note-ico .pen { animation: notePen 0.9s ease-in-out 2; }
@keyframes notePen { 0%, 100% { transform: translate(0, 0) rotate(0); } 25% { transform: translate(-1px, -1px) rotate(-14deg); } 75% { transform: translate(1px, 0) rotate(10deg); } }
.note-txt { flex: 1; min-width: 0; }
.note-title { font-size: 14px; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 8px; }
.note-sub { margin-top: 2px; font-size: 12px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.note-sub.has { color: #374151; font-style: italic; }
.note-saved { font-size: 10px; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #15803d; background: #dcfce7; padding: 2px 7px; border-radius: 999px; }
.note-saved.pop { animation: notePop 0.5s cubic-bezier(.34,1.56,.64,1); }
.note-chev { color: #9ca3af; font-size: 13px; transition: transform 0.3s ease, color 0.3s; }
.note-card.open .note-chev { transform: rotate(180deg); color: var(--primary-red, #e53935); }
.note-body { display: grid; grid-template-rows: 0fr; transition: grid-template-rows 0.35s cubic-bezier(.22,1,.36,1); }
.note-card.open .note-body { grid-template-rows: 1fr; }
.note-inner { overflow: hidden; }
.note-pad { padding: 0 14px 14px; opacity: 0; transform: translateY(-6px); transition: opacity 0.25s ease, transform 0.3s ease; }
.note-card.open .note-pad { opacity: 1; transform: none; transition-delay: 0.08s; }
.note-chips { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 10px; }
.note-chip { border: 1px solid #e5e7eb; background: #fff; color: #374151; border-radius: 999px; padding: 6px 11px; font-family: var(--site-font); font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.15s ease; }
.note-chip:active { transform: scale(0.95); }
.note-chip.on { border-color: var(--primary-red, #e53935); background: color-mix(in srgb, var(--primary-red, #e53935) 8%, #fff); color: var(--primary-red, #e53935); }
.note-area { position: relative; }
.note-area textarea { width: 100%; box-sizing: border-box; min-height: 84px; padding: 11px 12px 22px; border: 1.5px solid #e5e7eb; border-radius: 12px; background: #fafafa; font-family: var(--site-font); font-size: 13px; color: #111827; resize: none; outline: none; transition: border-color 0.2s, background 0.2s, box-shadow 0.2s; }
.note-area textarea:focus { border-color: var(--primary-red, #e53935); background: #fff; box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary-red, #e53935) 12%, transparent); }
.note-area .char-count { position: absolute; right: 10px; bottom: 7px; font-size: 10.5px; color: #9ca3af; }
.note-actions { display: flex; gap: 8px; margin-top: 10px; }
.note-actions button { flex: 1; height: 40px; border-radius: 11px; font-family: var(--site-font); font-size: 13px; font-weight: 700; cursor: pointer; transition: transform 0.15s ease; }
.note-actions button:active { transform: scale(0.97); }
.note-clear { border: 1px solid #e5e7eb; background: #fff; color: #6b7280; }
.note-save { border: 0; background: var(--primary-red, #e53935); color: #fff; }
@keyframes notePop { 0% { transform: scale(0.5); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
/* ── Bill details ── */
.bill-head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.bill-head .bill-ico { width: 32px; height: 32px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: #f3f4f6; color: #4b5563; font-size: 14px; }
.bill-head h3 { margin: 0 !important; }
.bill-save { position: relative; overflow: hidden; display: flex; align-items: center; gap: 8px; margin-top: 12px; padding: 10px 12px; border-radius: 12px; background: linear-gradient(135deg, #f0fdf4, #dcfce7); color: #15803d; font-size: 12.5px; font-weight: 700; }
.bill-save i { font-size: 14px; }
.bill-save::after { content: ""; position: absolute; top: 0; bottom: 0; width: 35%; left: -50%; background: linear-gradient(100deg, transparent, rgba(255,255,255,0.7), transparent); animation: bsShine 3.4s ease-in-out 0.8s infinite; }
.price-card .price-row { animation: billRow 0.4s ease both; }
.price-card .price-row:nth-of-type(2) { animation-delay: 0.04s; } .price-card .price-row:nth-of-type(3) { animation-delay: 0.08s; }
.price-card .price-row:nth-of-type(4) { animation-delay: 0.12s; } .price-card .price-row:nth-of-type(5) { animation-delay: 0.16s; }
@keyframes billRow { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
@keyframes bsShine { 0% { left: -60%; } 40%, 100% { left: 130%; } }
@media (prefers-reduced-motion: reduce) { .note-body, .note-pad, .note-chev, .note-ico { transition: none; } .bill-save::after, .price-card .price-row { animation: none; } }
/* Complete your meal (Offers > Complete your meal): suggestions for this cart */
.ups-card { padding: 14px 0 12px; overflow: hidden; }
.ups-head { display: flex; align-items: center; gap: 10px; padding: 0 14px 10px; }
.ups-ico { width: 34px; height: 34px; flex-shrink: 0; border-radius: 11px; display: grid; place-items: center; background: linear-gradient(135deg, color-mix(in srgb, var(--primary-red, #e53935) 75%, #fff), var(--primary-red, #e53935)); color: #fff; }
.ups-ico svg { width: 18px; height: 18px; animation: upsTwinkle 2.6s ease-in-out infinite; }
@keyframes upsTwinkle { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-2px); } }
.ups-head b { display: block; font-size: 15px; font-weight: 800; color: #111827; }
.ups-head span { display: block; font-size: 12px; color: #6b7280; }
.ups-head span b { display: inline; font-size: 12px; color: #16a34a; }
.ups-track { display: flex; gap: 10px; overflow-x: auto; scroll-snap-type: x mandatory; padding: 2px 14px 4px; scrollbar-width: none; }
.ups-track::-webkit-scrollbar { display: none; }
.ups-item { position: relative; flex: 0 0 138px; scroll-snap-align: start; display: flex; flex-direction: column; border: 1px solid #eef0f3; border-radius: 16px; background: #fff; overflow: hidden; animation: upsIn .35s ease both; }
.ups-item:nth-child(2) { animation-delay: .05s; } .ups-item:nth-child(3) { animation-delay: .1s; } .ups-item:nth-child(4) { animation-delay: .15s; }
@keyframes upsIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
.ups-item img { width: 100%; height: 96px; object-fit: cover; background: #f3f4f6; display: block; }
.ups-unlock { position: absolute; left: 6px; top: 6px; padding: 3px 7px; border-radius: 999px; background: #16a34a; color: #fff; font-size: 10px; font-weight: 800; box-shadow: 0 2px 6px rgba(22,163,74,.35); }
.ups-body { display: flex; flex-direction: column; gap: 2px; padding: 8px 9px 9px; flex: 1; }
.ups-name { display: flex; align-items: center; gap: 5px; font-size: 13px; font-weight: 700; color: #111827; }
.ups-name span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ups-name .veg-mark { flex-shrink: 0; }
.ups-why { font-size: 10.5px; font-weight: 600; color: #6d28d9; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ups-foot { display: flex; align-items: center; justify-content: space-between; margin-top: auto; padding-top: 6px; }
.ups-foot b { font-size: 13px; color: #111827; }
.ups-add { padding: 5px 11px; border: 1.5px solid var(--primary-red, #e53935); border-radius: 9px; background: #fff; color: var(--primary-red, #e53935); font: inherit; font-size: 12px; font-weight: 800; cursor: pointer; transition: transform .15s, background .2s, color .2s; }
.ups-add:active { transform: scale(.94); }
.ups-add.done { background: #16a34a; border-color: #16a34a; color: #fff; }
@media (prefers-reduced-motion: reduce) { .ups-ico svg, .ups-item { animation: none; } }
/* Pay from wallet (checkout) */
.chk-wallet { display: flex; align-items: center; gap: 10px; margin: 0 0 14px; padding: 12px; border-radius: 14px; background: linear-gradient(135deg, #eef2ff, #f5f3ff); border: 1.5px solid #c7d2fe; cursor: pointer; }
.chk-wallet input { width: 18px; height: 18px; accent-color: #4338ca; flex-shrink: 0; }
.chk-wallet .cw-ic { width: 34px; height: 34px; flex-shrink: 0; display: grid; place-items: center; border-radius: 10px; background: #4338ca; color: #fff; }
.chk-wallet .cw-ic svg { width: 18px; height: 18px; }
.chk-wallet b { display: block; font-size: 14px; color: #1e1b4b; }
.chk-wallet small { display: block; font-size: 12px; color: #4b5563; }
/* Price Details */
.price-card h3 { font-size: 15px; font-weight: 700; color: #1a1b1f; margin-bottom: 10px; }
.price-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 13px;
  color: #555;
  margin-bottom: 8px;
}
.price-row .val { font-weight: 500; color: #1a1b1f; }
.price-row .note { color: #c0392b; font-size: 12px; font-weight: 500; }
.price-row.discount .val { color: #27ae60; }
.price-row.discount span:first-child { color: #27ae60; }
.divider { border: none; border-top: 1px solid #e5e5e5; margin: 8px 0; }
.total-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 15px;
  font-weight: 700;
  color: #1a1b1f;
  margin-bottom: 10px;
}
.min-order-warning {
  background: #fff0f0;
  border: 2px solid #e74c3c;
  border-radius: 10px;
  padding: 12px 14px;
  font-size: 13px;
  color: #c0392b;
  font-weight: 600;
  line-height: 1.5;
  margin-bottom: 8px;
  text-align: center;
}
.min-order-warning i {
  color: #e74c3c;
  font-size: 16px;
  margin-right: 4px;
}
.checkout-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  background: #999 !important;
}
.min-order-bar {
  height: 4px;
  background: #e5e5e5;
  border-radius: 4px;
  overflow: hidden;
}
.min-order-bar .fill {
  height: 100%;
  width: 0.3%;
  background: linear-gradient(90deg, #2d3436, #4caf50);
  border-radius: 4px;
}

/* Checkout Bar */
/* Modal overlay */
.modal-overlay {
  position: fixed; top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.5); display: flex;
  align-items: center; justify-content: center; z-index: 9999;
  padding: 20px;
}
.pac-container { z-index: 10000 !important; }
.modal-overlay .modal-box {
  background: #fff; border-radius: 20px;
  max-width: 480px; width: 100%; max-height: 90vh; overflow-y: auto;
  animation: modalIn 0.2s ease;
}
@keyframes modalIn {
  from { transform: scale(0.95); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}
.modal-overlay .modal-header {
  display: flex; justify-content: space-between; align-items: center;
  padding: 20px 24px; border-bottom: 2px solid #f0f0f0;
  position: sticky; top: 0; background: #fff; z-index: 1;
}
.modal-overlay .modal-header h2 {
  font-size: 18px; font-weight: 600;  color: #2d3436; margin: 0;
}
.modal-overlay .modal-close {
  width: 32px; height: 32px; border-radius: 50%; border: none;
  background: #f5f5f5; cursor: pointer; font-size: 18px; color: #666;
  display: flex; align-items: center; justify-content: center;
}
.modal-overlay .modal-body { padding: 24px; }
.modal-overlay .form-group { margin-bottom: 16px; }
.modal-overlay .form-group label {
  display: block; margin-bottom: 6px;
  font-weight: 500; color: #333; font-size: 13px;
}
.modal-overlay .form-group input,
.modal-overlay .form-group textarea,
.modal-overlay .form-group select {
  width: 100%; padding: 12px 14px;
  border: 2px solid #e0e0e0; border-radius: 10px;
  font-size: 13px; font-family: var(--site-font);
  transition: border-color 0.2s; box-sizing: border-box;
}
.modal-overlay .form-group input:focus,
.modal-overlay .form-group textarea:focus,
.modal-overlay .form-group select:focus {
  outline: none;  border-color: #2d3436;
}
.modal-overlay .order-summary-box {
  background: #f9f9f9; border-radius: 12px; padding: 16px; margin-bottom: 16px;
}
.modal-overlay .order-summary-box h3 {
  font-size: 14px; font-weight: 600;  color: #2d3436; margin: 0 0 12px;
}
.modal-overlay .summary-row {
  display: flex; justify-content: space-between; padding: 6px 0;
  font-size: 13px; color: #333;
}
.modal-overlay .summary-discount {
  display: flex; justify-content: space-between; padding: 6px 0;
  font-size: 13px; color: #27ae60; font-weight: 500;
}
.modal-overlay .summary-divider {
  border: none; border-top: 1px solid #e0e0e0; margin: 8px 0;
}
.modal-overlay .summary-total {
  display: flex; justify-content: space-between; padding: 6px 0;
  font-weight: 600;  color: #2d3436; font-size: 14px;
}
.modal-overlay .btn-group { display: flex; gap: 10px; margin-top: 20px; }
.modal-overlay .btn-group .btn {
  flex: 1; padding: 12px; border-radius: var(--btn-radius, 10px);
  font-weight: 500; cursor: pointer;
  font-family: var(--site-font); font-size: 13px;
}
.modal-overlay .btn-group .btn-primary {
  border: none;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff;
}
.modal-overlay .btn-group .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.modal-overlay .btn-group .btn-secondary {
  border: 2px solid #e0e0e0; background: #fff; color: #666;
}

/* Success modal */
.success-box {
  background: #fff; border-radius: 20px;
  max-width: 380px; width: 100%; text-align: center;
  padding: 40px 24px; animation: modalIn 0.2s ease;
}
.success-box .check-icon {
  width: 64px; height: 64px; border-radius: 50%;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff; font-size: 32px; display: flex;
  align-items: center; justify-content: center;
  margin: 0 auto 16px;
}
.success-box h2 { font-size: 20px; font-weight: 600;  color: #2d3436; margin: 0 0 8px; }
.success-box p { color: #666; font-size: 14px; margin: 0 0 4px; }
.success-box .order-num { color: #999; font-size: 13px; margin: 0 0 24px; }
.success-box .btn-profile {
  width: 100%; padding: 14px; border: none; border-radius: 12px;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff; font-weight: 600; cursor: pointer;
  font-size: 15px; font-family: var(--site-font);
}

/* Alert modal */
.alert-box {
  background: #fff; border-radius: 20px;
  max-width: 340px; width: 100%; text-align: center;
  padding: 32px 24px; animation: modalIn 0.2s ease;
}
.alert-box h3 { font-size: 16px; font-weight: 600;  color: #2d3436; margin: 0 0 8px; }
.alert-box p { color: #666; font-size: 14px; margin: 0 0 20px; }
.alert-box .btn-ok {
  padding: 10px 32px; border: none; border-radius: 10px;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff; font-weight: 500; cursor: pointer;
  font-size: 13px; font-family: var(--site-font);
}

.checkout-bar {
  position: fixed;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 100%; max-width: 425px;
  background: linear-gradient(135deg, var(--checkout-color, #e17055), var(--checkout-color-dark, #d63031));
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 18px;
  z-index: 100;
  box-shadow: 0 -2px 12px rgba(0,0,0,0.15);
}
@media (min-width: 768px) {
  .checkout-bar { border-radius: 0 0 28px 28px; }
<?php if ($host === 'triposhsymmetry.in'): ?>
  /* TEMPORARY (see .phone-frame note above, remove together): keep the
     sticky checkout bar full-width to match the desktop layout. */
  .checkout-bar { max-width: 100%; }
<?php endif; ?>
}
.checkout-bar .total-label { color: #fff; font-weight: 700; font-size: 15px; }
.checkout-bar .checkout-btn {
  color: #fff;
  font-weight: 700;
  font-size: 15px;
  background: none;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 6px;
  font-family: var(--site-font);
}
.checkout-bar .checkout-btn:hover { opacity: .85; }
.checkout-bar .checkout-btn:disabled { opacity: 0.4; cursor: not-allowed; }

/* Closed overlay */
.closed-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,0.65);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}
.closed-overlay-inner {
  background: #fff;
  border-radius: 24px;
  max-width: 360px;
  width: 100%;
  padding: 40px 28px 32px;
  text-align: center;
  animation: fadeIn 0.3s ease;
}
.closed-overlay-inner .closed-icon {
  font-size: 64px;
  margin-bottom: 12px;
  display: block;
}
.closed-overlay-inner h2 {
  font-size: 22px;
  font-weight: 700;
  color: #1a1b1f;
  margin: 0 0 6px;
}
.closed-overlay-inner p {
  font-size: 14px;
  color: #666;
  margin: 0 0 24px;
  line-height: 1.6;
}
.closed-overlay-inner button {
  padding: 12px 36px;
  border: none;
  border-radius: 12px;
  background: var(--primary-red, #e53935);
  color: #fff;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  font-family: var(--site-font);
  transition: opacity 0.2s;
}
.closed-overlay-inner button:hover { opacity: 0.9; }

.skeleton {
  background: linear-gradient(90deg, #e8e8e8 25%, #d4d4d4 50%, #e8e8e8 75%);
  background-size: 200% 100%;
  animation: shimmer 1.2s ease-in-out infinite;
  border-radius: 8px;
}
@keyframes shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
.skel-cart-row { display: flex; gap: 12px; padding: 14px; align-items: center; border-bottom: 1px solid #f0f0f0; }
.skel-cart-img { width: 70px; height: 70px; border-radius: 10px; flex-shrink: 0; }
.skel-cart-body { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.skel-cart-title { height: 14px; width: 60%; border-radius: 6px; }
.skel-cart-price { height: 14px; width: 30%; border-radius: 6px; }
.skel-cart-actions { height: 28px; width: 80px; border-radius: 8px; }
.skel-cart-summary { padding: 14px; display: flex; flex-direction: column; gap: 10px; }
.skel-summary-row { height: 14px; width: 100%; border-radius: 6px; }
.skel-summary-row-half { height: 14px; width: 55%; border-radius: 6px; }
.toast-notification {
  position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%);
  padding: 12px 24px; border-radius: 12px; font-size: 13px; font-weight: 500;
  z-index: 99999; font-family: var(--site-font);
  box-shadow: 0 4px 20px rgba(0,0,0,0.3);
  opacity: 0; transition: opacity 0.3s ease, transform 0.3s ease;
  transform: translateX(-50%) translateY(20px);
  pointer-events: none;
  max-width: 90%; text-align: center;
  display: flex; align-items: center; gap: 8px;
}
.toast-notification.show { opacity: 1; transform: translateX(-50%) translateY(0); }
.toast-notification.success { background: #10b981; color: #fff; }
.toast-notification.error { background: #ef4444; color: #fff; }
.toast-notification.info { background: #3b82f6; color: #fff; }
.toast-notification.warning { background: #f59e0b; color: #fff; }
.toast-icon { font-size: 16px; font-weight: 700; flex-shrink: 0; }





/* Global Add-ons */
#cartAddonChips button:hover {
  border-color: var(--primary-red, #e17055) !important;
  color: var(--primary-red, #e17055) !important;
}

.bottom-nav {
  position: fixed; bottom: 0; left: 50%; transform: translateX(-50%);
  width: 100%; max-width: 425px;
  background: #fff; border-top: 1px solid #e8e8e8;
  display: flex; align-items: center; justify-content: space-between; padding: 8px 12px 10px;
  z-index: 100;
  box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
}
.nav-item {
  display: flex; flex-direction: column; align-items: center;
  gap: 2px; cursor: pointer; position: relative;
  border: none; background: none; font-family: var(--site-font);
}
.nav-item img { width: 30px; height: 30px; }
.nav-item span { font-size: 11px; color: #555; }
.nav-item.active span { color: #2d3436; font-weight: 600; }
.nav-icon { font-size: 24px; color: #888; display: block; margin: 0 auto; }
.nav-item.active .nav-icon { color: #2d3436; }
.cart-badge {
  position: absolute; top: -5px; right: 50%;
  transform: translateX(17px);
  background: #ff4444; color: #fff; font-size: 10px;
  width: 18px; height: 18px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 600;
}
.login-btn {
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff; border: none; border-radius: 8px;
  padding: 6px 14px; font-size: 11px; font-family: var(--site-font);
  cursor: pointer; font-weight: 500;
}
/* ── Coupon celebration on Checkout ── */
.cpn-cele { position: fixed; inset: 0; z-index: 99999; display: flex; align-items: center; justify-content: center; padding: 24px; background: rgba(17,24,39,0.55); backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px); opacity: 0; transition: opacity 0.25s ease; cursor: pointer; }
.cpn-cele.show { opacity: 1; }
.cpn-cele.hide { opacity: 0; transition: opacity 0.35s ease; }
.cpn-cele canvas { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
.cpn-cele-card { position: relative; width: 100%; max-width: 300px; padding: 30px 22px 22px; border-radius: 24px; background: #fff; text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,0.3); transform: scale(0.6) translateY(30px); opacity: 0; }
.cpn-cele.show .cpn-cele-card { animation: celeCardIn 0.55s cubic-bezier(.34,1.56,.64,1) forwards; }
.cpn-cele-badge { position: relative; width: 76px; height: 76px; margin: -68px auto 14px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; color: #fff; background: linear-gradient(135deg, #fbbf24, var(--primary-red, #e53935)); box-shadow: 0 10px 24px color-mix(in srgb, var(--primary-red, #e53935) 45%, transparent); border: 5px solid #fff; }
.cpn-cele.show .cpn-cele-badge { animation: celeBadge 0.9s cubic-bezier(.34,1.56,.64,1) 0.2s both; }
.cpn-cele-badge::before, .cpn-cele-badge::after { content: ""; position: absolute; inset: -10px; border-radius: 50%; border: 2px solid color-mix(in srgb, var(--primary-red, #e53935) 40%, transparent); animation: celeRing 1.6s ease-out 0.4s infinite; }
.cpn-cele-badge::after { animation-delay: 1.2s; }
.cpn-cele-kicker { font-size: 12px; font-weight: 800; letter-spacing: 0.14em; text-transform: uppercase; color: var(--primary-red, #e53935); }
.cpn-cele-amount { margin: 6px 0 4px; font-size: 34px; font-weight: 900; color: #111827; line-height: 1.1; }
.cpn-cele-amount span { background: linear-gradient(90deg, #f59e0b, var(--primary-red, #e53935)); -webkit-background-clip: text; background-clip: text; color: transparent; }
.cpn-cele-code { display: inline-block; margin-top: 8px; padding: 5px 12px; border: 1.5px dashed color-mix(in srgb, var(--primary-red, #e53935) 50%, #fff); border-radius: 8px; font-size: 12.5px; font-weight: 700; color: #374151; letter-spacing: 0.05em; }
.cpn-cele-note { margin-top: 12px; font-size: 11.5px; color: #9ca3af; }
.cpn-cele-timer { position: absolute; left: 22px; right: 22px; bottom: 10px; height: 3px; border-radius: 999px; background: #f3f4f6; overflow: hidden; }
.cpn-cele-timer i { display: block; height: 100%; width: 100%; background: linear-gradient(90deg, #fbbf24, var(--primary-red, #e53935)); transform-origin: left; animation: celeTimer var(--cele-ms, 2600ms) linear forwards; }
@keyframes celeCardIn { to { transform: none; opacity: 1; } }
@keyframes celeBadge { 0% { transform: scale(0) rotate(-30deg); } 100% { transform: scale(1) rotate(0); } }
@keyframes celeRing { from { transform: scale(0.85); opacity: 1; } to { transform: scale(1.5); opacity: 0; } }
@keyframes celeTimer { from { transform: scaleX(1); } to { transform: scaleX(0); } }
@media (prefers-reduced-motion: reduce) { .cpn-cele.show .cpn-cele-card, .cpn-cele.show .cpn-cele-badge { animation: none; transform: none; opacity: 1; } .cpn-cele-badge::before, .cpn-cele-badge::after { animation: none; display: none; } }
/* ── Milestone offers: progress bar to the next coupon ── */
.ms-card { position: relative; margin: 0 0 14px; padding: 14px 14px 12px; border-radius: 16px; background: #fff; border: 1px solid #f1f1f1; box-shadow: 0 4px 16px rgba(0,0,0,0.05); overflow: hidden; }
.ms-top { display: flex; align-items: center; gap: 10px; }
.ms-icon { width: 34px; height: 34px; flex-shrink: 0; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: color-mix(in srgb, var(--primary-red, #e53935) 12%, #fff); color: var(--primary-red, #e53935); font-size: 15px; }
.ms-icon.done { background: #dcfce7; color: #16a34a; animation: msWiggle 2.6s ease-in-out infinite; }
.ms-title { font-size: 14px; font-weight: 700; color: #111827; line-height: 1.3; }
.ms-title b { color: var(--primary-red, #e53935); }
.ms-sub { margin-top: 2px; font-size: 11.5px; color: #6b7280; }
.ms-sub a { color: var(--primary-red, #e53935); font-weight: 700; text-decoration: none; white-space: nowrap; }
.ms-track { position: relative; height: 8px; margin: 26px 12px 28px; border-radius: 999px; background: #f3f4f6; }
.ms-fill { position: absolute; left: 0; top: 0; bottom: 0; border-radius: 999px; overflow: hidden; background: linear-gradient(90deg, #fbbf24, var(--primary-red, #e53935)); transition: width 0.9s cubic-bezier(.22,1,.36,1); }
.ms-fill::after { content: ""; position: absolute; inset: 0; background: linear-gradient(110deg, transparent 20%, rgba(255,255,255,0.55) 45%, transparent 70%); background-size: 200% 100%; animation: msShine 1.8s linear infinite; }
.ms-stop { position: absolute; top: 50%; width: 18px; height: 18px; margin: -9px 0 0 -9px; border-radius: 50%; background: #fff; border: 2px solid #e5e7eb; display: flex; align-items: center; justify-content: center; font-size: 9px; color: #fff; transition: background 0.3s, border-color 0.3s; }
.ms-stop.on { background: var(--primary-red, #e53935); border-color: var(--primary-red, #e53935); }
.ms-stop.just { animation: msPop 0.6s cubic-bezier(.34,1.56,.64,1); }
.ms-stop.just::after { content: ""; position: absolute; inset: -4px; border-radius: 50%; border: 2px solid var(--primary-red, #e53935); animation: msRing 0.9s ease-out forwards; }
.ms-stop .ms-off { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); white-space: nowrap; font-size: 10.5px; font-weight: 800; color: #9ca3af; }
.ms-stop.on .ms-off { color: var(--primary-red, #e53935); }
.ms-stop .ms-at { position: absolute; top: 20px; left: 50%; transform: translateX(-50%); white-space: nowrap; font-size: 10.5px; color: #9ca3af; font-weight: 600; }
.ms-stop:last-child .ms-off, .ms-stop:last-child .ms-at { left: auto; right: -6px; transform: none; }
.ms-pill { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 999px; background: #f0fdf4; color: #15803d; font-size: 12px; font-weight: 700; }
.ms-pill i { font-size: 11px; }
.ms-pill.better { background: #fff7ed; color: #9a3412; }
.ms-pill.just { animation: msSlide 0.5s ease, msGlow 1.2s ease 0.2s; }
.ms-apply-btn { border: 0; border-radius: 999px; padding: 4px 10px; background: var(--primary-red, #e53935); color: #fff; font: inherit; font-size: 11.5px; font-weight: 700; cursor: pointer; }
@keyframes msShine { from { background-position: 150% 0; } to { background-position: -50% 0; } }
@keyframes msPop { 0% { transform: scale(0.4); } 100% { transform: scale(1); } }
@keyframes msRing { from { transform: scale(0.8); opacity: 1; } to { transform: scale(2); opacity: 0; } }
@keyframes msSlide { from { transform: translateY(6px); opacity: 0; } to { transform: none; opacity: 1; } }
@keyframes msGlow { 0% { box-shadow: 0 0 0 0 rgba(22,163,74,0.45); } 100% { box-shadow: 0 0 0 12px rgba(22,163,74,0); } }
@keyframes msWiggle { 0%, 88%, 100% { transform: rotate(0); } 92% { transform: rotate(-12deg); } 96% { transform: rotate(12deg); } }
@media (prefers-reduced-motion: reduce) { .ms-fill, .ms-fill::after, .ms-stop.just, .ms-stop.just::after, .ms-pill.just, .ms-icon.done { animation: none; transition: none; } }
</style>
<script>
window.websiteRestaurantId = <?php echo json_encode($restaurant_id ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.websiteRestaurantSlug = <?php echo json_encode($restaurant_slug ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.globalCurrencySymbol = <?php echo json_encode($currency_symbol ?? '₹', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
window.restaurantOpen = <?php echo json_encode($restaurant_open, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.openingHours = <?php echo json_encode($opening_hours ? json_decode($opening_hours, true) : null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
window.minimumOrderValue = <?php echo json_encode((float)$minimum_order_value, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.packagingCharge = <?php echo json_encode((float)$packaging_charge, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.paymentGatewayType = <?php echo json_encode($payment_gateway_type ?? 'cash_only', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
<?php
$phonepe_configured = false;
$business_qr_available = false;
$business_qr_image_url = '';
if (!empty($restaurant_id) && function_exists('getConnection')) {
  try {
    $gwConn = getConnection();
    $gwStmt = $gwConn->prepare("SELECT id, payment_gateway_mode, phonepe_merchant_id, (business_qr_code_data IS NOT NULL) AS has_business_qr FROM users WHERE restaurant_id = ? LIMIT 1");
    $gwStmt->execute([$restaurant_id]);
    $gwRow = $gwStmt->fetch(PDO::FETCH_ASSOC);
    if ($gwRow) {
      // same rule the order API enforces (process_website_order.php): keys usable, India only
      // (Nepal and other countries get none), and not switched to cash-only
      require_once __DIR__ . '/../config/phonepe_verify.php';
      $gwCfg = phonepeCallbackConfig($restaurant_id);
      $phonepe_configured = ($payment_gateway_type ?? 'cash_only') !== 'cash_only' && ($gwCfg['ready'] || $gwCfg['demo']);
      if (!empty($gwRow['has_business_qr'])) {
        $business_qr_available = true;
        $business_qr_image_url = '../api/image.php?type=business_qr&id=' . urlencode($gwRow['id']);
      }
    }
  } catch (Exception $e) {}
}
?>
<?php
// Growth › WhatsApp: ask for WhatsApp consent at checkout when the restaurant sends messages
$wa_optin_ask = false;
if (!empty($restaurant_id) && isset($gwConn)) {
  try { $waSt = $gwConn->prepare('SELECT enabled FROM wa_settings WHERE restaurant_id = ?'); $waSt->execute([$restaurant_id]); $wa_optin_ask = (bool)$waSt->fetchColumn(); } catch (Exception $e) {}
}
?>
window.waOptinAsk = <?php echo json_encode($wa_optin_ask); ?>;
window.phonepeConfigured = <?php echo json_encode($phonepe_configured, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.businessQrAvailable = <?php echo json_encode($business_qr_available, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.businessQrImageUrl = <?php echo json_encode($business_qr_image_url, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.restaurantTimezoneOffset = <?php echo json_encode($timezone_offset_minutes ?? 330, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.enableGst = <?php echo json_encode($enable_gst ?? 1, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.taxName = <?php echo json_encode($tax_name ?? 'GST', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
window.taxPercent = <?php echo json_encode((float)($tax_percent ?? 5.00), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.restaurantCountry = <?php echo json_encode($country ?? 'India', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
window.restaurantDialCode = <?php echo json_encode($phone_dial_code ?? '+91', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.restaurantPhoneMin = <?php echo json_encode((int)($phone_min_digits ?? 10), JSON_HEX_TAG); ?>;
window.restaurantPhoneMax = <?php echo json_encode((int)($phone_max_digits ?? 10), JSON_HEX_TAG); ?>;
window.enableDelivery = <?php echo json_encode($enable_delivery ?? 1, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.enableTakeaway = <?php echo json_encode($enable_takeaway ?? 1, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.enableDinein = <?php echo json_encode($enable_dinein ?? 1, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.codEnabled = <?php echo json_encode($cod_enabled ?? 1, JSON_HEX_TAG | JSON_HEX_AMP); ?>;

window.websiteTableNumber = <?php echo json_encode($qr_table ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.restaurantAddress = <?php echo json_encode($restaurant_address ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
window.loggedInCustomer = <?php echo $logged_in_customer ? json_encode([
    'name' => $logged_in_customer['customer_name'],
    'phone' => $logged_in_customer['phone'],
    'email' => $logged_in_customer['email'],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) : 'null'; ?>;
window.loginUrl = <?php
$cartLoginUrlBase = restaurantPageUrl('login');
$cartLoginUrlSep = strpos($cartLoginUrlBase, '?') !== false ? '&' : '?';
echo json_encode($cartLoginUrlBase . $cartLoginUrlSep . 'redirect=cart', JSON_HEX_TAG | JSON_HEX_AMP);
?>;

// Google Maps API key loaded from .env (used for delivery address autocomplete + geocoding)
window.googleMapsApiKey = <?php echo json_encode($googleMapsApiKey, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.deliveryRadius = <?php echo json_encode((float)$delivery_radius_km, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.restaurantLat = <?php echo json_encode($restaurant_lat !== null ? (float)$restaurant_lat : null, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.restaurantLng = <?php echo json_encode($restaurant_lng !== null ? (float)$restaurant_lng : null, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.enableKmDelivery = <?php echo json_encode((int)$enable_km_delivery, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.deliveryRatePerKm = <?php echo json_encode((float)$delivery_rate_per_km, JSON_HEX_TAG | JSON_HEX_AMP); ?>;

// Helper: append ?table=X param from sessionStorage or window var to any URL
function appendTableParam(url) {
  var tbl = window.websiteTableNumber || sessionStorage.getItem('qrTable') || '';
  if (tbl) {
    url += (url.indexOf('?') > -1 ? '&' : '?') + 'table=' + encodeURIComponent(tbl);
  }
  return url;
}</script>
<script src="rg-notify.js?v=<?php echo @filemtime(__DIR__ . '/rg-notify.js'); ?>"></script>
<script>RGNotify.setup({ toast: 'web' }); document.documentElement.style.setProperty('--rgn-bottom-m', 'calc(84px + env(safe-area-inset-bottom))');</script>
</head>
<body>
<div class="phone-frame">
  <div class="bg-wrapper">

    <!-- Header -->
    <div class="pr-share-header">
      <button class="back-btn-cmon" onclick="goBack()"><i class="fa fa-arrow-circle-left"></i></button>
      <span class="header-brand"><?php echo htmlspecialchars($restaurant_name ?? 'Dvani Cafe & Grill', ENT_QUOTES, 'UTF-8'); ?></span>
      <div class="header-actions">
        <button class="header-action-btn" onclick="window.location.href=appendTableParam('<?php echo restaurantPageUrl('menu'); ?>')"><i class="fa fa-list"></i></button>
        <button class="header-action-btn" onclick="window.location.href=appendTableParam('<?php echo restaurantPageUrl(); ?>')"><i class="fa fa-home"></i></button>
      </div>
    </div>

    <!-- Banners: Cart page — top -->
    <div class="rg-banners" data-placement="cart" style="padding:12px 12px 0"></div>

    <!-- Content -->
    <div class="content" id="cartContent">
      <div class="card" style="overflow:hidden">
        <div class="skel-cart-row"><div class="skel-cart-img skeleton"></div><div class="skel-cart-body"><div class="skel-cart-title skeleton"></div><div class="skel-cart-price skeleton"></div><div class="skel-cart-actions skeleton"></div></div></div>
        <div class="skel-cart-row"><div class="skel-cart-img skeleton"></div><div class="skel-cart-body"><div class="skel-cart-title skeleton"></div><div class="skel-cart-price skeleton"></div><div class="skel-cart-actions skeleton"></div></div></div>
        <div class="skel-cart-row"><div class="skel-cart-img skeleton"></div><div class="skel-cart-body"><div class="skel-cart-title skeleton"></div><div class="skel-cart-price skeleton"></div><div class="skel-cart-actions skeleton"></div></div></div>
      </div>
      <div class="card"><div class="skel-cart-summary"><div class="skel-summary-row-half skeleton"></div><div class="skel-summary-row skeleton"></div><div class="skel-summary-row skeleton"></div><div class="skel-summary-row-half skeleton"></div></div></div>
    </div>

    <!-- Checkout Bar -->
    <div class="checkout-bar">
      <span class="total-label" id="checkoutLabel"><?php echo htmlspecialchars($currency_symbol ?? '₹'); ?>0.00 (0 Items)</span>
      <button class="checkout-btn" id="checkoutBtn" onclick="proceedCheckout()" disabled>Checkout <i class="fa fa-arrow-right"></i></button>
    </div>

  </div>
</div>

<script>
var cartItems = {};
var MIN_ORDER = parseFloat(window.minimumOrderValue) || 0;
var PACKAGING_CHARGE = parseFloat(window.packagingCharge) || 0;
var flatItems = [];

// Retries a fetch on transient failures (network drop, server 5xx) with
// short exponential backoff, so a single blip on restaurant wifi/mobile
// data doesn't force the customer to manually redo the whole action. Does
// NOT retry on a successful HTTP response with an application-level
// {success:false} — that's a real validation error (e.g. "Cart is empty"),
// not something a retry would fix.
function fetchJsonWithRetry(url, options, maxAttempts) {
  maxAttempts = maxAttempts || 3;
  var attempt = 0;
  function tryOnce() {
    attempt++;
    return fetch(url, options).then(function(r) {
      if (!r.ok && r.status >= 500 && attempt < maxAttempts) {
        return backoffAndRetry();
      }
      return r.json();
    }).catch(function(err) {
      if (attempt < maxAttempts) {
        return backoffAndRetry();
      }
      throw err;
    });
  }
  function backoffAndRetry() {
    var delay = 700 * Math.pow(2, attempt - 1); // 700ms, 1400ms, 2800ms...
    return new Promise(function(resolve) {
      setTimeout(function() { resolve(tryOnce()); }, delay);
    });
  }
  return tryOnce();
}

function getImageUrl(img, w) {   // w: width of the small copy image.php makes (96, 200, 400, 800)
  if (!img || img === '' || img === 'no-image') return 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22150%22 height=%22150%22 viewBox=%220 0 150 150%22%3E%3Crect width=%22100%25%22 height=%22100%25%22 fill=%22%23f0f0f0%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 font-family=%22Arial%22 font-size=%2212%22 fill=%22%23999%22 text-anchor=%22middle%22 dy=%22.3em%22%3ENo Image%3C/text%3E%3C/svg%3E';
  if (img.indexOf('http://') === 0 || img.indexOf('https://') === 0) return img;
  if (img.indexOf('db:') === 0) return 'image.php?id=' + encodeURIComponent(img.substring(3)) + '&w=' + (w || 200);
  return 'image.php?id=' + encodeURIComponent(img);
}

function loadMenuItems() {
  var rid = window.websiteRestaurantId || document.querySelector('meta[name="restaurant-id"]')?.content || '';
  if (!rid) return;
  var apiBase = 'api.php?restaurant_id=' + encodeURIComponent(rid);
  fetchJsonWithRetry(apiBase + '&action=getMenus')
    .then(function(menusData) {
      var menus = menusData || [];
      return fetchJsonWithRetry(apiBase + '&action=getMenuItems')
        .then(function(itemsData) {
          var rawItems = itemsData && itemsData.items ? itemsData.items : (itemsData || []);
          var menuMap = {};
          for (var i = 0; i < menus.length; i++) {
            menuMap[menus[i].id] = { name: menus[i].menu_name_translated || menus[i].menu_name, image: menus[i].menu_image, items: [] };
          }
          for (var i = 0; i < rawItems.length; i++) {
            var item = rawItems[i];
            var mid = item.menu_id;
            if (menuMap[mid]) {
              menuMap[mid].items.push(item);
            } else {
              var catName = item.item_category || 'General';
              if (!menuMap[catName]) {
                menuMap[catName] = { name: catName, image: null, items: [] };
              }
              menuMap[catName].items.push(item);
            }
          }
          var menuItems = [];
          for (var key in menuMap) menuItems.push(menuMap[key]);
          flatItems = [];
          for (var m = 0; m < menuItems.length; m++) {
            for (var i = 0; i < menuItems[m].items.length; i++) {
              flatItems.push(menuItems[m].items[i]);
            }
          }
          itemsById = {};
          cleanCart();
          renderCart();
          // a saved reward / poster offer whose minimum depends on the cart total: check again now the prices are known
          if (availableCoupons.length) applySavedReward();
        });
    })
    .catch(function(err) { console.error('Failed to load menu items:', err); document.getElementById('cartContent').innerHTML = '<div class="card card-pad" style="text-align:center;padding:40px 20px;color:#e74c3c"><p>Failed to load menu. Please refresh.</p></div>'; });
}
 
function loadCart() {
  try {
    var saved = localStorage.getItem('dvaniCart');
    if (saved) { cartItems = JSON.parse(saved); }
  } catch(e) {
    cartItems = {};
    console.warn('Saved cart could not be read, starting empty:', e);
    if (typeof showToast === 'function') {
      showToast('We could not restore your previous cart. Starting fresh.', 'warning');
    }
  }
}

function saveCart() {
  try {
    localStorage.setItem('dvaniCart', JSON.stringify(cartItems));
  } catch(e) {
    // Storage full / blocked (private browsing edge cases) — the cart still
    // works for this page view, it just won't survive a refresh. Tell the
    // customer instead of letting it silently vanish later.
    console.warn('Could not save cart to this device:', e);
    if (typeof showToast === 'function') {
      showToast('Your cart could not be saved on this device. Please complete checkout without refreshing.', 'warning');
    }
  }
}

function getCurrency() {
  return window.globalCurrencySymbol || '₹';
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/[&<>"']/g, function(c) {
    return '&#' + c.charCodeAt(0) + ';';
  });
}

var itemsById = {};
 
function getItemByKey(key) {
  if (!itemsById || Object.keys(itemsById).length === 0) {
    itemsById = {};
    for (var i = 0; i < flatItems.length; i++) {
      if (flatItems[i] && flatItems[i].id) {
        itemsById[flatItems[i].id] = flatItems[i];
      }
    }
  }
  var itemId = parseInt(key.split('_v')[0], 10);
  return itemsById[itemId] || null;
}

function getCartPrice(key) {
  var raw = cartItems[key];
  if (typeof raw === 'number') {
    var item = getItemByKey(key);
    return item ? parseFloat(item.base_price || item.price || 0) : 0;
  }
  if (raw && typeof raw === 'object') return raw.varPrice || raw.addonPrice || 0;
  return 0;
}

function getCartQty(key) {
  var raw = cartItems[key];
  if (typeof raw === 'number') return raw;
  if (typeof raw === 'string') return parseInt(raw, 10) || 0;
  if (raw && typeof raw === 'object') return raw.qty || 0;
  return 0;
}

// --- Add-ons Helper Functions ---
function getAddonsForItemKey(key) {
  try { var s = localStorage.getItem('_itemAddons'); var all = s ? JSON.parse(s) : {}; return all[key] || []; } catch(e){return [];}
}
function getAddonTotalForItem(key) {
  var addons = getAddonsForItemKey(key);
  var total = 0;
  for (var a = 0; a < addons.length; a++) { total += parseFloat(addons[a].price || 0); }
  return total;
}
function getAddonNamesString(key) {
  var addons = getAddonsForItemKey(key);
  var names = [];
  for (var a = 0; a < addons.length; a++) { names.push(addons[a].name); }
  return names.length ? '+ ' + names.join(', ') : '';
}
// Cooking instruction per cart line, added on the Menu page (optional)
function getItemNote(key) {
  try { var all = JSON.parse(localStorage.getItem('_itemNotes') || '{}') || {}; return all[key] || ''; } catch(e) { return ''; }
}
function clearItemNote(key) {
  try { var all = JSON.parse(localStorage.getItem('_itemNotes') || '{}') || {}; delete all[key]; localStorage.setItem('_itemNotes', JSON.stringify(all)); } catch(e) {}
}
function clearAddonsForItem(key) {
  try { var s = localStorage.getItem('_itemAddons'); var all = s ? JSON.parse(s) : {}; delete all[key]; localStorage.setItem('_itemAddons', JSON.stringify(all)); } catch(e){}
}

function cleanCart() {
  // Remove cart entries for items that no longer exist in the loaded menu.
  // Guard: if the menu failed to load anything meaningful (network hiccup,
  // partial API response), flatItems is empty/near-empty and every cart item
  // would look "not found" — that's a loading problem, not evidence the
  // items were actually removed from the menu. Skip cleaning rather than
  // wiping the customer's cart over a transient failure.
  if (!flatItems || flatItems.length === 0) return;

  var changed = false;
  var removedNames = [];
  for (var k in cartItems) {
    if (k.indexOf('addon_') === 0 || k.indexOf('combo_') === 0) continue;   // combos are checked at checkout
    var raw = cartItems[k];
    if (typeof raw !== 'number' && (!raw || typeof raw !== 'object' || raw.isAddon || raw.isCombo)) continue;
    var item = getItemByKey(k);
    if (!item) {
      var removedLabel = (raw && typeof raw === 'object' && raw.varName) ? ('item #' + k.split('_v')[0]) : ('item #' + k);
      removedNames.push(removedLabel);
      delete cartItems[k];
      changed = true;
    }
  }
  if (changed) {
    saveCart();
    if (typeof showToast === 'function') {
      var msg = removedNames.length === 1
        ? '1 item in your cart is no longer available and was removed.'
        : removedNames.length + ' items in your cart are no longer available and were removed.';
      showToast(msg, 'warning');
    }
  }
}
// --- End Add-ons Helpers ---

var addonsList = [];

function fetchAddons() {
  var rid = window.websiteRestaurantId || document.querySelector('meta[name="restaurant-id"]')?.content || 'RES001';
  fetch('api.php?restaurant_id=' + encodeURIComponent(rid) + '&action=getAddons')
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success && data.data) {
        addonsList = data.data;
        renderGlobalAddons();
      }
    })
    .catch(function() {});
}

function renderGlobalAddons() {
  var container = document.getElementById('cartAddonChips');
  if (!container) return;
  // Hide the whole "Add-ons" card when this restaurant hasn't configured
  // any add-ons at all, instead of showing an empty upsell section.
  var cardEl = document.getElementById('cartAddonCard');
  if (cardEl) cardEl.style.display = addonsList.length > 0 ? '' : 'none';
  var sym = getCurrency();
  var html = '';
  // Show available add-ons to click
  if (addonsList.length > 0) {
    for (var i = 0; i < addonsList.length; i++) {
      var a = addonsList[i];
      if (a.is_available == 0) continue;
      var aName = a.addon_name || a.name || 'Add-on';
      var aPrice = parseFloat(a.addon_price || a.price || 0);
      var id = a.id;
      html += '<div class="addon-row" data-addon-id="' + id + '" style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;margin:2px 0;border-radius:8px;cursor:pointer;transition:background 0.15s;font-size:13px">' +
        '<span style="font-weight:500;color:#444">' + escapeHtml(aName) + '</span>' +
        '<span style="color:var(--primary-red, #e17055);font-weight:600">+' + sym + aPrice.toFixed(2) + '</span>' +
        '</div>';
    }
  }
  // Show already selected addons
  var addedAddons = [];
  var allKeys = Object.keys(cartItems);
  for (var ki = 0; ki < allKeys.length; ki++) {
    var raw = cartItems[allKeys[ki]];
    if (raw && typeof raw === "object" && raw.isAddon) {
      addedAddons.push({ key: allKeys[ki], raw: raw });
    }
  }
  if (addedAddons.length > 0) {
    html += '<div style="border-top:1px solid #f0e8e0;margin:6px 0;padding-top:6px"></div>';
    for (var si = 0; si < addedAddons.length; si++) {
      var item = addedAddons[si];
      var key = item.key;
      var raw = item.raw;
      var qty = raw.qty || 0;
      var price = raw.addonPrice || 0;
      html += '<div style="display:flex;align-items:center;gap:8px;padding:6px 4px;font-size:13px">' +
        '<span style="font-weight:500;color:var(--primary-red, #e17055);flex:1">' + escapeHtml(raw.addonName || 'Add-on') + '</span>' +
        '<span style="color:#999;font-size:12px">x' + qty + '</span>' +
        '<span style="font-weight:600;color:#2d3436">' + sym + (price * qty).toFixed(2) + '</span>' +
        '<button onclick="deleteItem(\'' + key + '\')" style="width:24px;height:24px;border:none;border-radius:4px;background:#fef2f2;color:#e74c3c;cursor:pointer;font-size:12px;display:grid;place-items:center;flex-shrink:0">\u2715</button>' +
        '</div>';
    }
  }
  if (!html) {
    container.innerHTML = '<div style="font-size:12px;color:#aaa;padding:4px 0">No add-ons available</div>';
    return;
  }
  container.innerHTML = html;
  var rows = container.querySelectorAll('.addon-row');
  for (var ri = 0; ri < rows.length; ri++) {
    rows[ri].addEventListener('click', function() {
      var id = parseInt(this.getAttribute('data-addon-id'), 10);
      var a = null;
      for (var j = 0; j < addonsList.length; j++) {
        if (addonsList[j].id == id) { a = addonsList[j]; break; }
      }
      if (!a) return;
      var aName = a.addon_name || a.name || 'Add-on';
      var aPrice = parseFloat(a.addon_price || a.price || 0);
      addGlobalAddon(id, aName, aPrice);
    });
  }
}


function addGlobalAddon(addonId, addonName, addonPrice) {
  var key = 'addon_' + addonId;
  if (cartItems[key]) {
    if (typeof cartItems[key] === 'object') {
      cartItems[key].qty = (cartItems[key].qty || 0) + 1;
    } else {
      cartItems[key] = { qty: 1, addonName: addonName, addonPrice: addonPrice, isAddon: true };
    }
  } else {
    cartItems[key] = { qty: 1, addonName: addonName, addonPrice: addonPrice, isAddon: true };
  }
  saveCart();
  renderCart();
  showToast('Added ' + escapeHtml(addonName) + ' to cart', 'success');
}


function renderCart() {
  var container = document.getElementById('cartContent');
  var keys = Object.keys(cartItems).filter(function(k) { return typeof cartItems[k] === 'object' || cartItems[k] > 0; });
 
  if (keys.length > 0 && flatItems.length === 0) {
    container.innerHTML =
      '<div class="card card-pad" style="text-align:center;padding:40px 20px">' +
        '<p>Loading cart items...</p>' +
      '</div>';
    document.getElementById('checkoutLabel').textContent = getCurrency() + '0.00 (0 Items)';
    document.getElementById('checkoutBtn').disabled = true;
    return;
  }

  if (keys.length === 0) {
    appliedCoupon = null;
    couponError = '';
    container.innerHTML =
      '<div class="card card-pad">' +
        '<div class="empty-cart">' +
          '<div class="empty-icon">🛒</div>' +
          '<h3>Your cart is empty</h3>' +
          '<p>Looks like you haven\'t added anything yet.\nBrowse our menu to find something delicious!</p>' +
          '<button class="browse-btn" onclick="window.location.href=appendTableParam(\'' + '<?php echo restaurantPageUrl('menu'); ?>' + '\')"><i class="fa fa-plus"></i> Browse Menu</button>' +
        '</div>' +
      '</div>' +
      '<div class="card card-pad">' +
        '<div class="coupon-header">' +
          '<div class="icon-wrap">%</div>' +
          '<div class="header-text">' +
            '<strong>Have a coupon code?</strong>' +
            '<span>Browse available offers & discounts</span>' +
          '</div>' +
        '</div>' +
        '<div class="coupon-browse" onclick="openCouponSheet()">' +
          '<div class="badge">%</div>' +
          '<span>Browse all coupons</span>' +
          '<span class="arrow">→</span>' +
        '</div>' +
      '</div>';
    document.getElementById('checkoutLabel').textContent = (window.globalCurrencySymbol || '₹') + '0.00 (0 Items)';
    document.getElementById('checkoutBtn').disabled = true;
    return;
  }

  var totalQty = 0, totalPrice = 0;
  var regQty = 0, regPrice = 0;
  var addonQty = 0, addonPrice = 0;
  var cartHtml = '';
  var orderRows = '';
  var addonKeys = [];

  // First pass: render regular items + collect addon keys
  for (var k = 0; k < keys.length; k++) {
    var key = keys[k];
    var qty = getCartQty(key);
    if (qty <= 0) continue;
    var raw = cartItems[key];
    
    // Skip add-on items - they get their own section
    if (raw && typeof raw === 'object' && raw.isAddon) {
      addonKeys.push(key);
      addonQty += qty;
      addonPrice += (raw.addonPrice || 0) * qty;
      continue;
    }

    // a combo (Offers > Combos & Deals, rg-combos.js): its name, what's in it, one price
    if (raw && typeof raw === 'object' && raw.isCombo) {
      var cPrice = parseFloat(raw.varPrice || 0);
      regQty += qty;
      regPrice += cPrice * qty;
      var cName = escapeHtml(raw.name || 'Combo');
      orderRows +=
        '<div class="order-row order-combo" id="cartCard-' + key.replace(/[^A-Za-z0-9_-]/g, '_') + '">' +
          '<img src="' + getImageUrl(raw.image || '') + '" alt="' + cName + '" loading="lazy">' +
          '<div class="order-main">' +
            '<div class="order-name-row">' +
              '<span class="combo-tag"><i class="fa-solid fa-fire-flame-curved"></i> Combo</span>' +
              '<div class="order-name">' + cName + '</div>' +
              '<button type="button" class="order-del" onclick="deleteItem(\'' + key + '\')" title="Remove" aria-label="Remove ' + cName + '"><i class="fa-regular fa-trash-can"></i></button>' +
            '</div>' +
            (raw.varName ? '<div class="order-addon">' + escapeHtml(raw.varName) + '</div>' : '') +
            '<div class="order-bottom">' +
              '<div class="qty-pill">' +
                '<button type="button" onclick="changeQty(\'' + key + '\', -1)" aria-label="One less">−</button>' +
                '<span>' + qty + '</span>' +
                '<button type="button" onclick="changeQty(\'' + key + '\', 1)" aria-label="One more">+</button>' +
              '</div>' +
              '<div class="order-price"><b>' + getCurrency() + (cPrice * qty).toFixed(2) + '</b>' +
                (qty > 1 ? '<small>' + getCurrency() + cPrice.toFixed(2) + ' each</small>' : '') + '</div>' +
            '</div>' +
          '</div>' +
        '</div>';
      continue;
    }

    var info = getItemByKey(key);
    if (!info) continue;
    var price = getCartPrice(key);
    regQty += qty;

    var varDisplay = '';
    if (raw && typeof raw === 'object' && raw.varName) {
      varDisplay = '<div class="order-size">' + escapeHtml(raw.varName) + '</div>';
    }
    
    // Show add-ons for this item
    var addonNames = getAddonNamesString(key);
    if (addonNames) {
      varDisplay += '<div class="order-addon">' + escapeHtml(addonNames) + '</div>';
    }
    var itemNote = getItemNote(key);
    if (itemNote) {
      varDisplay += '<div class="order-note"><i class="fa-regular fa-note-sticky"></i> ' + escapeHtml(itemNote) + '</div>';
    }
    var addonTotal = getAddonTotalForItem(key);
    if (addonTotal > 0) {
      price = price + addonTotal;   // add-ons are per plate, as the menu and the order server count them
    }
    regPrice += price * qty;

    // One row per dish inside the "Your order" card
    var dishName = escapeHtml(info.item_name_translated || info.item_name_en || info.name);
    var typeLc = String(info.item_type || '').toLowerCase();
    var vegCls = typeLc.indexOf('non') !== -1 ? ' nonveg' : (typeLc.indexOf('egg') !== -1 ? ' egg' : '');
    var showVeg = typeLc && typeLc !== 'drink' && typeLc !== 'beverage';
    orderRows +=
      '<div class="order-row" id="cartCard-' + key.replace('.', '_') + '">' +
        '<img src="' + getImageUrl(info.item_image || info.image) + '" alt="' + dishName + '" loading="lazy">' +
        '<div class="order-main">' +
          '<div class="order-name-row">' +
            (showVeg ? '<span class="veg-mark' + vegCls + '"><i></i></span>' : '') +
            '<div class="order-name">' + dishName + '</div>' +
            '<button type="button" class="order-del" onclick="deleteItem(\'' + key + '\')" title="Remove" aria-label="Remove ' + dishName + '"><i class="fa-regular fa-trash-can"></i></button>' +
          '</div>' +
          varDisplay +
          '<div class="order-bottom">' +
            '<div class="qty-pill">' +
              '<button type="button" onclick="changeQty(\'' + key + '\', -1)" aria-label="One less">−</button>' +
              '<span>' + qty + '</span>' +
              '<button type="button" onclick="changeQty(\'' + key + '\', 1)" aria-label="One more">+</button>' +
            '</div>' +
            '<div class="order-price"><b>' + getCurrency() + (price * qty).toFixed(2) + '</b>' +
              (qty > 1 ? '<small>' + getCurrency() + price.toFixed(2) + ' each</small>' : '') + '</div>' +
          '</div>' +
        '</div>' +
      '</div>';
  }

  if (orderRows) {
    cartHtml += '<div class="card order-card">' +
      '<div class="order-head"><strong>Your order</strong><span>' + regQty + (regQty === 1 ? ' item' : ' items') + '</span></div>' +
      orderRows +
      '<a class="order-more" href="' + appendTableParam('<?php echo restaurantPageUrl('menu'); ?>') + '"><i class="fa fa-plus"></i> Add more items</a>' +
    '</div>';
  }
  cartHtml += '<div id="cartUpsell"></div>';

  // Combine totals
  totalQty = regQty + addonQty;
  totalPrice = regPrice + addonPrice;
  evaluateMilestones(totalPrice);
  var ticketJustApplied = !!(appliedCoupon && lastTicketCoupon !== appliedCoupon.code);
  lastTicketCoupon = appliedCoupon ? appliedCoupon.code : '';

  var couponStatusHtml = '';
  if (appliedCoupon) {
    var discLabel = appliedCoupon.type === 'percent' ? appliedCoupon.discount + '% OFF' : (appliedCoupon.type === 'flat' ? getCurrency() + appliedCoupon.discount + ' OFF' : 'FREE DELIVERY');
    couponStatusHtml = '<div class="coupon-status success"><span class="cpn-status-text">✅ ' + appliedCoupon.code + ' applied (' + discLabel + ')</span><span class="remove-coupon" onclick="removeCoupon()">Remove</span></div>';
  } else if (couponError) {
    couponStatusHtml = '<div class="coupon-status error">❌ ' + couponError + '</div>';
  }

  cartHtml +=
    '<div class="card card-pad" id="couponCard">' +
      milestoneCardHtml(totalPrice) +
      // Offers & coupons: applied coupon as a ticket, otherwise a code box
      '<div class="cpn-sec-head">' +
        '<div class="cpn-sec-title"><span class="cpn-sec-ico"><i class="fa-solid fa-ticket"></i></span>Offers &amp; coupons</div>' +
        '<button type="button" class="cpn-viewall" onclick="openCouponSheet()">View all <i class="fa fa-chevron-right"></i></button>' +
      '</div>' +
      (appliedCoupon
        ? '<div class="cpn-ticket' + (ticketJustApplied ? ' pop' : '') + '">' +
            '<div class="cpn-ticket-ico"><i class="fa-solid fa-tag"></i></div>' +
            '<div class="cpn-ticket-txt"><b>' + escapeHtml(appliedCoupon.code) + '</b>' +
              '<span>You save ' + getCurrency() + couponSaving(appliedCoupon, totalPrice).toFixed(0) + ' with this code</span></div>' +
            '<button type="button" class="cpn-ticket-remove" onclick="removeCoupon()">Remove</button>' +
          '</div>'
        : '<div class="cpn-code-row">' +
            '<div class="cpn-code-box"><i class="fa-solid fa-percent"></i>' +
              '<input type="text" id="couponInput" value="' + escapeHtml(lastCouponTyped) + '" placeholder="Enter coupon code" maxlength="20" autocomplete="off" oninput="this.value=this.value.toUpperCase()" onkeydown="if(event.key===\'Enter\')applyCoupon()"></div>' +
            '<button type="button" class="cpn-apply" id="applyCouponBtn" onclick="applyCoupon()">Apply</button>' +
          '</div>' +
          (couponError ? '<div class="cpn-error"><i class="fa-solid fa-circle-exclamation"></i>' + escapeHtml(couponError) + '</div>' : '')) +      '</div>' +
      '<div class="note-card' + (noteOpen ? ' open' : '') + '" id="noteCard">' +
      '<div class="note-head" id="instrHeader" onclick="toggleInstruction()" role="button" aria-expanded="' + (noteOpen ? 'true' : 'false') + '">' +
        '<div class="note-ico"><svg class="hat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21a1 1 0 0 0 1-1v-5.35c0-.46.32-.84.73-1.04a4 4 0 0 0-2.14-7.59 5 5 0 0 0-9.18 0 4 4 0 0 0-2.14 7.59c.41.2.73.58.73 1.04V20a1 1 0 0 0 1 1Z"/><path d="M6 17h12"/></svg>' +
          '<span class="pen"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span></div>' +
        '<div class="note-txt">' +
          '<div class="note-title">Note for the restaurant' + (savedInstruction ? '<span class="note-saved' + (noteJustSaved ? ' pop' : '') + '">Saved</span>' : '') + '</div>' +
          '<div class="note-sub' + (savedInstruction ? ' has' : '') + '">' + (savedInstruction ? '“' + escapeHtml(savedInstruction) + '”' : 'Allergies, spice level, delivery or gifting notes') + '</div>' +
        '</div>' +
        '<i class="fa fa-chevron-down note-chev"></i>' +
      '</div>' +
      '<div class="note-body" id="instrBody"><div class="note-inner"><div class="note-pad">' +
        '<div class="note-chips">' + ['No onion', 'No garlic', 'Less spicy', 'Extra spicy', 'Jain food', 'Ring the bell'].map(function(t) {
          return '<button type="button" class="note-chip' + ((savedInstruction || '').toLowerCase().indexOf(t.toLowerCase()) !== -1 ? ' on' : '') + '" onclick="toggleNoteChip(this, \'' + t + '\')">' + t + '</button>';
        }).join('') + '</div>' +
        '<div class="note-area">' +
          '<textarea id="instrText" maxlength="500" placeholder="Anything else the restaurant should know?" oninput="updateCount()">' + escapeHtml(savedInstruction || '') + '</textarea>' +
          '<div class="char-count"><span id="charCount">' + (savedInstruction ? savedInstruction.length : 0) + '</span>/500</div>' +
        '</div>' +
        '<div class="note-actions">' +
          (savedInstruction ? '<button type="button" class="note-clear" onclick="clearInstruction()">Clear</button>' : '') +
          '<button type="button" class="note-save" onclick="saveInstruction()">Save note</button>' +
        '</div>' +
      '</div></div></div>' +
    '</div>' +
    '<div class="card card-pad" id="cartAddonCard" style="display:none">' +
      '<div class="coupon-header">' +
        '<div class="icon-wrap"><i class="fa fa-plus-circle"></i></div>' +
        '<div class="header-text">' +
          '<strong>Add-ons</strong>' +
          '<span>Customise your order with extras</span>' +
        '</div>' +
      '</div>' +
      '<div id="cartAddonChips" style="display:flex;flex-wrap:wrap;gap:8px;padding:4px 0"></div>' +
    '</div>' +
  '</div>';

    var discountAmount = 0;
    var discountLabel = '';
    if (appliedCoupon) {
      if (appliedCoupon.type === 'percent') {
        discountAmount = totalPrice * appliedCoupon.discount / 100;
      } else if (appliedCoupon.type === 'flat') {
        discountAmount = appliedCoupon.discount;
      }
      discountLabel = appliedCoupon.code + ' (' + (appliedCoupon.type === 'percent' ? appliedCoupon.discount + '%' : getCurrency() + appliedCoupon.discount) + ')';
    }
    var remainingAfterCoupon = Math.max(0, totalPrice - discountAmount);
    var loyaltyMaxUsable = getLoyaltyMaxUsablePoints(remainingAfterCoupon);
    var canUseLoyalty = loyaltyMaxUsable >= loyaltyMinRedeemPoints && loyaltyMaxUsable > 0;
    var loyaltyDiscountAmount = (useLoyaltyPoints && canUseLoyalty) ? +(loyaltyMaxUsable * loyaltyRedeemValuePerPoint).toFixed(2) : 0;
    var vipDiscountAmount = vipCartDiscount(Math.max(0, remainingAfterCoupon - loyaltyDiscountAmount));
    var finalTotal = Math.max(0, remainingAfterCoupon - loyaltyDiscountAmount - vipDiscountAmount);

    if (loyaltyProgramEnabled && loyaltyBalance > 0) {
      cartHtml +=
        '<div class="card card-pad" id="loyaltyCard">' +
          '<div class="coupon-header">' +
            '<div class="icon-wrap"><i class="fa fa-gift"></i></div>' +
            '<div class="header-text">' +
              '<strong>' + loyaltyBalance + ' Loyalty Points Available</strong>' +
              '<span>Worth ' + getCurrency() + (loyaltyBalance * loyaltyRedeemValuePerPoint).toFixed(2) + '</span>' +
            '</div>' +
          '</div>' +
          (canUseLoyalty ?
            '<label style="display:flex;align-items:center;gap:8px;margin-top:8px;font-size:13px;cursor:pointer;">' +
              '<input type="checkbox" ' + (useLoyaltyPoints ? 'checked' : '') + ' onchange="toggleLoyaltyRedeem()"> ' +
              'Use ' + loyaltyMaxUsable + ' points to save ' + getCurrency() + (loyaltyMaxUsable * loyaltyRedeemValuePerPoint).toFixed(2) +
            '</label>'
            : '<div style="font-size:12px;color:#999;margin-top:6px;">Minimum ' + loyaltyMinRedeemPoints + ' points required to redeem</div>') +
        '</div>';
    }

    cartHtml +=
    '<div class="card card-pad">' +
      '<div class="price-card">' +
        '<div class="bill-head"><div class="bill-ico"><i class="fa-solid fa-receipt"></i></div><h3>Bill details</h3></div>' +
        '<div class="price-row">' +
          '<span>Item total</span>' +
          '<span class="val">' + getCurrency() + regPrice.toFixed(2) + '</span>' +
        '</div>' +
        (addonQty > 0 ? '<div class="price-row">' +
          '<span>Add-ons (' + addonQty + ' Items)</span>' +
          '<span class="val">' + getCurrency() + addonPrice.toFixed(2) + '</span>' +
        '</div>' : '') +
        (discountAmount > 0 ? '<div class="price-row discount">' +
          '<span>Discount (' + discountLabel + ')</span>' +
          '<span class="val">-' + getCurrency() + discountAmount.toFixed(2) + '</span>' +
        '</div>' : '') +
        (loyaltyDiscountAmount > 0 ? '<div class="price-row discount">' +
          '<span>Loyalty Points (' + loyaltyMaxUsable + ' pts)</span>' +
          '<span class="val">-' + getCurrency() + loyaltyDiscountAmount.toFixed(2) + '</span>' +
        '</div>' : '') +
        (vipDiscountAmount > 0 ? '<div class="price-row discount">' +
          '<span>' + escapeHtml(window.cartVip.name) + ' member (' + window.cartVip.pct + '% off)</span>' +
          '<span class="val">-' + getCurrency() + vipDiscountAmount.toFixed(2) + '</span>' +
        '</div>' : '') +
        (PACKAGING_CHARGE > 0 ? '<div class="price-row">' +
          '<span>Packaging (delivery &amp; takeaway)</span>' +
          '<span class="note">' + getCurrency() + PACKAGING_CHARGE.toFixed(2) + ', added at checkout</span>' +
        '</div>' : '') +
        '<div class="price-row">' +
          '<span>Delivery Fee</span>' +
          (vipFreeDelivery(totalPrice) ? '<span class="val" style="color:#16a34a">Free for ' + escapeHtml(window.cartVip.name) + ' members</span>' : '<span class="note">Delivery fee is calculated after login</span>') +
        '</div>' +
        '<hr class="divider">' +
        '<div class="total-row">' +
          '<span>Subtotal</span>' +
          '<span>' + getCurrency() + finalTotal.toFixed(2) + '</span>' +
        '</div>' +
        '<div class="price-row"><span class="note">Taxes and charges are added at checkout, before you pay.</span></div>' +
        (MIN_ORDER > 0 ? '<div class="min-order-warning" id="minOrderWarning">' +
          '<i class="fa-solid fa-circle-exclamation"></i> Minimum Order Value Is ' + getCurrency() + MIN_ORDER.toFixed(2) + ' Please Add More Items To Proceed.' +
        '</div>' +
        (totalPrice < MIN_ORDER ? '<div class="min-order-bar"><div class="fill" style="width:' + Math.min(100, (totalPrice / MIN_ORDER) * 100) + '%"></div></div>' : '') : '') +
        ((discountAmount + loyaltyDiscountAmount + vipDiscountAmount) > 0 ? '<div class="bill-save"><i class="fa-solid fa-circle-check"></i>You\'re saving ' + getCurrency() + (discountAmount + loyaltyDiscountAmount + vipDiscountAmount).toFixed(0) + ' on this order</div>' : '') +
      '</div>' +
    '</div>';

  cartHtml +=
    '<div style="text-align:center;padding:16px 0 8px">' +
      '<div style="display:flex;justify-content:center;flex-wrap:wrap;gap:4px 12px;font-size:11px">' +
        '<a href="<?php echo restaurantPageUrl('privacy-policy'); ?>" style="color:#888;text-decoration:none">Privacy Policy</a>' +
        '<span style="color:#ccc">|</span>' +
        '<a href="<?php echo restaurantPageUrl('terms-of-service'); ?>" style="color:#888;text-decoration:none">Terms of Service</a>' +
        '<span style="color:#ccc">|</span>' +
        '<a href="<?php echo restaurantPageUrl('refund-policy'); ?>" style="color:#888;text-decoration:none">Refund Policy</a>' +
        '<span style="color:#ccc">|</span>' +
        '<a href="<?php echo restaurantPageUrl('shipping-policy'); ?>" style="color:#888;text-decoration:none">Shipping Policy</a>' +
        '<span style="color:#ccc">|</span>' +
        '<a href="<?php echo restaurantPageUrl('cookie-policy'); ?>" style="color:#888;text-decoration:none">Cookie Policy</a>' +
      '</div>' +
      '<div style="font-size:10px;color:#aaa;margin-top:6px">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?>. All rights reserved.</div>' +
    '</div>';

  container.innerHTML = cartHtml;
  renderGlobalAddons();
  renderUpsell(keys, totalPrice);
  var sym = getCurrency();
  document.getElementById('checkoutLabel').innerHTML = regQty + (regQty === 1 ? ' item' : ' items') + '<span style="opacity:.55;margin:0 6px;font-weight:400">|</span>' + sym + finalTotal.toFixed(2);
  document.getElementById('checkoutBtn').disabled = true;

  if (MIN_ORDER <= 0 || totalPrice >= MIN_ORDER) {
    var warningEl = document.getElementById('minOrderWarning');
    if (warningEl) {
      warningEl.style.display = 'none';
    }
    document.getElementById('checkoutBtn').disabled = false;
  }
}

// ── Complete your meal (Offers > Complete your meal) ──
// One request for the restaurant's picks, learned pairs and settings; the
// ranking for the current cart runs here (rg-upsell.js) on every re-draw.
var upsellData = null, upsellViewed = false, upsellJustAdded = 0;
function loadUpsell() {
  var rid = window.websiteRestaurantId || (document.querySelector('meta[name="restaurant-id"]') || {}).content || '';
  if (!rid) return;
  fetch('api.php?action=getUpsell&restaurant_id=' + encodeURIComponent(rid))
    .then(function(r) { return r.json(); })
    .then(function(d) { if (d && d.upsell) { upsellData = d.upsell; if (flatItems.length) renderCart(); } })
    .catch(function() {});
}
function trackUpsellEvent(ev, id) {
  var rid = window.websiteRestaurantId || (document.querySelector('meta[name="restaurant-id"]') || {}).content || '';
  try { fetch('api.php?action=trackUpsell&restaurant_id=' + encodeURIComponent(rid) + '&event=' + ev + (id ? '&item_id=' + id : ''), { method: 'POST', keepalive: true }); } catch (e) {}
}
function upsellGoal(total) {
  if (MIN_ORDER > 0 && total < MIN_ORDER) return { gap: MIN_ORDER - total, label: 'Reaches minimum order' };
  var list = typeof milestoneCoupons === 'function' ? milestoneCoupons() : [];
  for (var i = 0; i < list.length; i++) if (total < list[i].minOrder) return { gap: list[i].minOrder - total, label: 'Unlocks ' + couponLabel(list[i]) };
  return null;
}
function renderUpsell(keys, total) {
  var box = document.getElementById('cartUpsell');
  if (!box || !upsellData || !window.RGUpsell) return;
  var lines = [];
  keys.forEach(function(k) {
    var raw = cartItems[k];
    if (raw && typeof raw === 'object' && raw.isAddon) return;
    var id = parseInt(String(k).split('_v')[0], 10);
    if (id && getCartQty(k) > 0) lines.push({ id: id, qty: getCartQty(k) });
  });
  var goal = upsellGoal(total);
  var list = RGUpsell.rank(upsellData, lines, flatItems, { total: total, goal: goal });
  if (!list.length) { box.innerHTML = ''; return; }
  var cur = getCurrency();
  var sub = goal && list.some(function(o) { return o.unlock; })
    ? 'Add <b>' + cur + Math.ceil(goal.gap) + '</b> more: ' + escapeHtml(goal.label.replace(/^Unlocks /, 'unlock ').replace(/^Reaches /, 'reach the '))
    : 'Picked to go with your order';
  box.innerHTML = '<div class="card ups-card">' +
    '<div class="ups-head"><div class="ups-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 17a8 8 0 0 1 16 0"/><path d="M2 17h20"/><path d="M12 9V7"/><path d="M10.5 7h3"/><path d="M5 20h14"/></svg></div>' +
      '<div><b>' + escapeHtml(upsellData.title || 'Complete your meal') + '</b><span>' + sub + '</span></div></div>' +
    '<div class="ups-track">' + list.map(function(o) {
      var it = o.item, nm = escapeHtml(it.item_name_translated || it.item_name_en || it.name);
      var typeLc = String(it.item_type || '').toLowerCase();
      var vegCls = typeLc.indexOf('non') !== -1 ? ' nonveg' : (typeLc.indexOf('egg') !== -1 ? ' egg' : '');
      var showVeg = typeLc && typeLc !== 'drink' && typeLc !== 'beverage';
      var hasVar = it.has_variations == 1 && it.variations && it.variations.length;
      return '<div class="ups-item">' +
        '<img src="' + getImageUrl(it.item_image || it.image) + '" alt="" loading="lazy">' +
        (o.unlock ? '<span class="ups-unlock">' + escapeHtml(o.unlock) + '</span>' : '') +
        '<div class="ups-body">' +
          '<div class="ups-name">' + (showVeg ? '<span class="veg-mark' + vegCls + '"><i></i></span>' : '') + '<span>' + nm + '</span></div>' +
          '<div class="ups-why">' + escapeHtml(o.reason) + '</div>' +
          '<div class="ups-foot"><b>' + cur + parseFloat(it.base_price || 0).toFixed(0) + '</b>' +
            (hasVar
              ? '<button type="button" class="ups-add" onclick="upsellChoose(' + it.id + ')">Choose</button>'
              : '<button type="button" class="ups-add' + (upsellJustAdded === it.id ? ' done' : '') + '" onclick="upsellAdd(' + it.id + ', this)">ADD +</button>') +
          '</div></div></div>';
    }).join('') + '</div></div>';
  if (!upsellViewed) { upsellViewed = true; trackUpsellEvent('view'); }
}
function upsellAdd(id, btn) {
  var key = String(id);
  if (typeof cartItems[key] === 'object' && cartItems[key]) cartItems[key].qty = (cartItems[key].qty || 0) + 1;
  else cartItems[key] = (parseInt(cartItems[key], 10) || 0) + 1;
  saveCart();
  trackUpsellEvent('add', id);
  if (btn) { btn.classList.add('done'); btn.textContent = 'Added'; }
  var it = getItemByKey(key);
  if (typeof showToast === 'function' && it) showToast(escapeHtml(it.item_name_translated || it.item_name_en || 'Dish') + ' added', 'success');
  setTimeout(renderCart, 450);
}
function upsellChoose(id) {
  trackUpsellEvent('add', id);
  var url = appendTableParam('<?php echo restaurantPageUrl('menu'); ?>');
  window.location.href = url + (url.indexOf('?') === -1 ? '?' : '&') + 'dish=' + id;
}

// Wallet balance for "Pay from wallet" at checkout (logged-in customers only)
window.cartWallet = null;
function loadCartWallet() {
  var rid = window.websiteRestaurantId || document.querySelector('meta[name=restaurant-id]')?.content || '';
  if (!rid) return;
  fetch('wallet_api.php?action=summary&restaurant_id=' + encodeURIComponent(rid), { credentials: 'same-origin', cache: 'no-store' })
    .then(function(r) { return r.json(); })
    .then(function(d) { if (d && d.success && d.enabled && d.logged_in) window.cartWallet = { name: d.name, balance: +d.balance || 0 }; })
    .catch(function() {});
}

// VIP membership perks (Loyalty Studio > VIP): the server applies them at checkout
// for a logged-in member; this mirrors them so the bill shows the same numbers.
window.cartVip = null;
function loadCartVip() {
  var rid = window.websiteRestaurantId || document.querySelector('meta[name=restaurant-id]')?.content || '';
  if (!rid) return;
  fetch('vip_api.php?action=summary&restaurant_id=' + encodeURIComponent(rid), { credentials: 'same-origin', cache: 'no-store' })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (!d || !d.success || !d.membership) return;
      var m = d.membership;
      window.cartVip = { name: d.name, pct: +m.discount_pct || 0, max: +m.discount_max || 0, freeDelivery: !!+m.free_delivery, freeMin: +m.free_delivery_min || 0 };
      if (typeof renderCart === 'function' && document.getElementById('cartContent')) renderCart();
      if (typeof refreshCheckoutTotal === 'function') refreshCheckoutTotal();
    })
    .catch(function() {});
}
function vipCartDiscount(foodLeft) {
  var v = window.cartVip;
  if (!v || v.pct <= 0 || foodLeft <= 0) return 0;
  var d = Math.round(foodLeft * v.pct) / 100;
  return +(v.max > 0 ? Math.min(d, v.max) : d).toFixed(2);
}
function vipFreeDelivery(itemsTotal) { var v = window.cartVip; return !!(v && v.freeDelivery && itemsTotal >= v.freeMin); }

function changeQty(key, delta) {
  if (!cartItems[key]) return;
  var raw = cartItems[key];
  var snap = snapshotLine(key);
  if (typeof raw === 'number') {
    cartItems[key] = Math.max(0, raw + delta);
    if (cartItems[key] === 0) delete cartItems[key];
  } else if (typeof raw === 'object') {
    raw.qty = Math.max(0, (raw.qty || 0) + delta);
    if (raw.qty === 0) delete cartItems[key];
  }
  if (!cartItems[key]) { clearItemNote(key); offerUndo(snap); }
  saveCart();
  renderCart();
}

function deleteItem(key) {
  if (!cartItems[key]) return;
  var snap = snapshotLine(key);
  clearAddonsForItem(key);
  clearItemNote(key);
  delete cartItems[key];
  saveCart();
  renderCart();
  offerUndo(snap);
}

// A removed dish shows "… removed · UNDO" (rg-notify.js); Undo puts back the line, its add-ons and its note.
function snapshotLine(key) {
  var get = function (k) { try { return (JSON.parse(localStorage.getItem(k) || '{}') || {})[key]; } catch (e) { return null; } };
  var btn = document.querySelector('.order-del[onclick*="\'' + key + '\'"]');
  var name = btn ? (btn.getAttribute('aria-label') || '').replace(/^Remove /, '') : '';
  return { key: key, raw: JSON.parse(JSON.stringify(cartItems[key])), addons: get('_itemAddons'), note: get('_itemNotes'), name: name || 'Item' };
}
function offerUndo(snap) {
  if (!window.RGNotify || !snap) return;
  RGNotify.snack(snap.name + ' removed', { action: 'Undo', onAction: function () {
    var put = function (k, v) { if (v == null) return; try { var all = JSON.parse(localStorage.getItem(k) || '{}') || {}; all[snap.key] = v; localStorage.setItem(k, JSON.stringify(all)); } catch (e) {} };
    cartItems[snap.key] = snap.raw;
    put('_itemAddons', snap.addons); put('_itemNotes', snap.note);
    saveCart();
    renderCart();
    RGNotify.island(snap.name + ' is back in your cart', 'success');
  } });
}

var savedInstruction = '';

// Note for the restaurant: opens/closes with a smooth slide; quick chips add
// common requests; Save collapses it and shows the note in the header.
var noteOpen = false, noteJustSaved = false;
function toggleInstruction() {
  var card = document.getElementById('noteCard');
  if (!card) return;
  noteOpen = !card.classList.contains('open');
  card.classList.toggle('open', noteOpen);
  var head = document.getElementById('instrHeader');
  if (head) head.setAttribute('aria-expanded', noteOpen ? 'true' : 'false');
  if (noteOpen) setTimeout(function() { var t = document.getElementById('instrText'); if (t && !t.value) t.focus(); }, 300);
}
function toggleNoteChip(btn, text) {
  var t = document.getElementById('instrText');
  if (!t) return;
  var parts = t.value.split(',').map(function(p) { return p.trim(); }).filter(Boolean);
  var i = parts.map(function(p) { return p.toLowerCase(); }).indexOf(text.toLowerCase());
  if (i === -1) parts.push(text); else parts.splice(i, 1);
  t.value = parts.join(', ').slice(0, 500);
  btn.classList.toggle('on', i === -1);
  updateCount();
}
function clearInstruction() {
  var t = document.getElementById('instrText');
  if (t) t.value = '';
  savedInstruction = '';
  try { localStorage.removeItem('dvaniInstruction'); } catch(e) {}
  noteOpen = true;
  renderCart();
}

function updateCount() {
  var t = document.getElementById('instrText');
  var countEl = document.getElementById('charCount');
  if (t && countEl) countEl.textContent = t.value.length;
}

function saveInstruction() {
  var t = document.getElementById('instrText');
  if (!t) return;
  savedInstruction = t.value.trim();
  try { localStorage.setItem('dvaniInstruction', savedInstruction); } catch(e) {}
  noteOpen = false;
  noteJustSaved = !!savedInstruction;
  renderCart();
  noteJustSaved = false;
  showToast(savedInstruction ? 'Note saved' : 'Note removed');
}

function loadInstruction() {
  try {
    var saved = localStorage.getItem('dvaniInstruction');
    if (saved) savedInstruction = saved;
  } catch(e) {}
}

function showToast(msg, type) {
  if (window.RGNotify) return RGNotify.toast(msg, type || 'success', { html: true });
  type = type || 'success';
  var existing = document.querySelector('.toast-notification');
  if (existing) existing.remove();
  var icons = { success: '✓', error: '✕', info: 'ℹ', warning: '⚠' };
  var toast = document.createElement('div');
  toast.className = 'toast-notification ' + type;
  toast.innerHTML = '<span class="toast-icon">' + (icons[type] || icons.success) + '</span>' + msg;
  document.body.appendChild(toast);
  requestAnimationFrame(function() { toast.classList.add('show'); });
  setTimeout(function() {
    toast.classList.remove('show');
    setTimeout(function() { toast.remove(); }, 300);
  }, 2500);
}

// --- Coupon System ---
var appliedCoupon = null;
var couponError = '';
var availableCoupons = [];

// --- Loyalty Points Redemption ---
var loyaltyProgramEnabled = false;
var loyaltyBalance = 0;
var loyaltyRedeemValuePerPoint = 0;
var loyaltyMinRedeemPoints = 0;
var useLoyaltyPoints = false;

function loadLoyaltyBalance() {
  var cd = loadCustomer();
  var phone = cd && cd.phone ? cd.phone : '';
  if (!phone) return;
  var rid = document.querySelector('meta[name=restaurant-id]')?.content || 'RES001';
  fetch('loyalty_actions.php?action=summary&restaurant_id=' + encodeURIComponent(rid) + '&phone=' + encodeURIComponent(phone))
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.success || !data.enrolled || !data.loyalty_enabled) return;
      loyaltyProgramEnabled = true;
      loyaltyBalance = data.points_balance || 0;
      loyaltyRedeemValuePerPoint = data.redeem_value_per_point || 0;
      loyaltyMinRedeemPoints = data.min_redeem_points || 0;
      renderCart();
    })
    .catch(function() {});
}

function getLoyaltyMaxUsablePoints(remainingAfterCoupon) {
  if (!loyaltyProgramEnabled || loyaltyRedeemValuePerPoint <= 0) return 0;
  var maxByOrderValue = Math.floor(remainingAfterCoupon / loyaltyRedeemValuePerPoint);
  return Math.max(0, Math.min(loyaltyBalance, maxByOrderValue));
}

function toggleLoyaltyRedeem() {
  useLoyaltyPoints = !useLoyaltyPoints;
  renderCart();
}

function loadCouponsFromServer() {
  var rid = document.querySelector('meta[name=restaurant-id]')?.content || 'RES001';
  fetch('../api/get_coupons.php?restaurant_id=' + encodeURIComponent(rid))
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success && data.coupons) {
        availableCoupons = data.coupons.map(function(c) {
          var until = c.valid_until || '';
          if (until) {
            var parts = until.split('-');
            if (parts.length === 3) until = parts[2] + '/' + parts[1] + '/' + parts[0];
          }
          return {
            code: c.coupon_code,
            discount: parseFloat(c.discount_value),
            type: c.discount_type,
            minOrder: parseFloat(c.minimum_order_amount || 0),
            description: c.description || '',
            validTill: until || 'No expiry',
            dbId: c.id,
            isMilestone: c.is_milestone == 1
          };
        });
        if (appliedCoupon) {
          var stillValid = false;
          for (var i = 0; i < availableCoupons.length; i++) {
            if (availableCoupons[i].code === appliedCoupon.code) { stillValid = true; break; }
          }
          if (!stillValid) { appliedCoupon = null; couponError = ''; }
        }
        renderCart();
        applySavedReward();
      }
    })
    .catch(function() {});
}

// --- Milestone offers (coupons marked "Milestone offer" in the dashboard) ---
// The bar shows how far the order is from the next coupon; the best one the
// order has reached is applied automatically. A coupon the customer picks or
// removes by hand is respected (couponUserChoice). A coupon whose minimum is
// no longer met is taken off here, since checkout would refuse it.
var couponUserChoice = false;
var lastTicketCoupon = '';
var lastCouponTyped = '';
var lastAutoAppliedCoupon = '';
var milestoneJustApplied = false;

function couponLabel(c) {
  return c.type === 'percent' ? c.discount + '% OFF' : getCurrency() + c.discount + ' OFF';
}
function couponSaving(c, total) {
  if (!c) return 0;
  var v = c.type === 'percent' ? total * c.discount / 100 : c.discount;
  return Math.max(0, Math.min(v, total));
}
function milestoneCoupons() {
  return availableCoupons.filter(function(c) { return c.isMilestone && c.minOrder > 0; })
    .sort(function(a, b) { return a.minOrder - b.minOrder; });
}
function bestReachedMilestone(total) {
  var best = null;
  milestoneCoupons().forEach(function(c) {
    if (total >= c.minOrder && (!best || couponSaving(c, total) > couponSaving(best, total))) best = c;
  });
  return best;
}
function evaluateMilestones(total) {
  milestoneJustApplied = false;
  if (appliedCoupon && appliedCoupon.minOrder > 0 && total < appliedCoupon.minOrder) {
    if (couponUserChoice) couponError = appliedCoupon.code + ' removed — it needs a minimum order of ' + getCurrency() + appliedCoupon.minOrder.toFixed(0);
    appliedCoupon = null;
    couponUserChoice = false;
  }
  if (!couponUserChoice) {
    var best = bestReachedMilestone(total);
    if (best && (!appliedCoupon || couponSaving(best, total) > couponSaving(appliedCoupon, total))) {
      appliedCoupon = best;
      couponError = '';
      if (lastAutoAppliedCoupon !== best.code) { milestoneJustApplied = true; lastAutoAppliedCoupon = best.code; }
    }
  }
}
function applyMilestone(code) {
  for (var i = 0; i < availableCoupons.length; i++) {
    if (availableCoupons[i].code === code) { appliedCoupon = availableCoupons[i]; couponError = ''; couponUserChoice = false; lastAutoAppliedCoupon = code; }
  }
  renderCart();
}
var lastMilestonePct = 0;          // bar slides from where it was to where it is now
var reachedMilestones = null;      // milestones already reached (pop only the new ones)
function milestoneCardHtml(total) {
  var list = milestoneCoupons();
  if (!list.length) return '';
  var cur = getCurrency();
  var next = null;
  for (var i = 0; i < list.length; i++) { if (total < list[i].minOrder) { next = list[i]; break; } }
  var top = list[list.length - 1].minOrder;
  var pct = Math.max(0, Math.min(100, total / top * 100));
  var firstPaint = reachedMilestones === null;
  if (firstPaint) reachedMilestones = {};
  var title = next
    ? 'Add <b>' + cur + Math.ceil(next.minOrder - total) + '</b> more to get <b>' + couponLabel(next) + '</b>'
    : 'Best offer unlocked';
  var sub = next
    ? '<div class="ms-sub">' + next.code + ' applies automatically · <a href="' + appendTableParam('<?php echo restaurantPageUrl('menu'); ?>') + '">Add items <i class="fa fa-arrow-right"></i></a></div>'
    : '<div class="ms-sub">The biggest discount is applied to this order</div>';
  var stops = list.map(function(c) {
    var on = total >= c.minOrder;
    var just = on && !firstPaint && !reachedMilestones[c.code];
    if (on) reachedMilestones[c.code] = 1; else delete reachedMilestones[c.code];
    return '<div class="ms-stop' + (on ? ' on' : '') + (just ? ' just' : '') + '" style="left:' + (c.minOrder / top * 100) + '%">' + (on ? '<i class="fa fa-check"></i>' : '') +
      '<span class="ms-off">' + couponLabel(c) + '</span><span class="ms-at">' + cur + c.minOrder.toFixed(0) + '</span></div>';
  }).join('');
  var pill = '';
  var best = bestReachedMilestone(total);
  // (the applied coupon itself is shown as the ticket in Offers & coupons)
  if (appliedCoupon && appliedCoupon.isMilestone) {
    pill = '';
  } else if (best && (!appliedCoupon || couponSaving(best, total) > couponSaving(appliedCoupon, total))) {
    pill = '<span class="ms-pill better">Save ' + cur + couponSaving(best, total).toFixed(0) + ' with ' + best.code + '<button type="button" class="ms-apply-btn" onclick="applyMilestone(\'' + best.code + '\')">Apply</button></span>';
  }
  var from = lastMilestonePct;
  lastMilestonePct = pct;
  setTimeout(function() { var f = document.getElementById('msFill'); if (f) f.style.width = pct + '%'; }, 40);
  return '<div class="ms-card">' +
      '<div class="ms-top"><div class="ms-icon' + (next ? '' : ' done') + '"><i class="fa fa-gift"></i></div>' +
        '<div><div class="ms-title">' + title + '</div>' + sub + '</div></div>' +
      '<div class="ms-track"><div class="ms-fill" id="msFill" style="width:' + (firstPaint ? 0 : from) + '%"></div>' + stops + '</div>' +
      pill +
    '</div>';
}

// Private codes (scratch card rewards) aren't in the public list: look one up once
var couponLookedUp = {};
function couponFromRow(c) {
  var until = c.valid_until || '';
  if (until) { var parts = until.split('-'); if (parts.length === 3) until = parts[2] + '/' + parts[1] + '/' + parts[0]; }
  return { code: c.coupon_code, discount: parseFloat(c.discount_value), type: c.discount_type, minOrder: parseFloat(c.minimum_order_amount || 0),
           description: c.description || '', validTill: until || 'No expiry', dbId: c.id, isMilestone: c.is_milestone == 1 };
}
function lookupCoupon(code, done) {
  var rid = document.querySelector('meta[name=restaurant-id]')?.content || '';
  fetch('../api/get_coupons.php?restaurant_id=' + encodeURIComponent(rid) + '&code=' + encodeURIComponent(code))
    .then(function(r) { return r.json(); })
    .then(function(d) { if (d.success && d.coupons && d.coupons.length) availableCoupons.push(couponFromRow(d.coupons[0])); done(); })
    .catch(function() { done(); });
}

function applyCoupon(codeArg) {
  var input = document.getElementById('couponInput');
  var code = typeof codeArg === 'string' ? codeArg : (input ? input.value.trim().toUpperCase() : '');
  if (!code) return;
  lastCouponTyped = code;

  couponError = '';
  var found = null;
  for (var i = 0; i < availableCoupons.length; i++) {
    if (availableCoupons[i].code === code) {
      found = availableCoupons[i];
      break;
    }
  }

  if (!found && !couponLookedUp[code]) {
    couponLookedUp[code] = true;
    lookupCoupon(code, function() { applyCoupon(code); });
    return;
  }

  if (!found) {
    couponError = 'Invalid coupon code';
    appliedCoupon = null;
    renderCart();
    setTimeout(function() {
      var inp = document.getElementById('couponInput');
      if (inp) inp.classList.add('error');
    }, 0);
    return;
  }

  var cart = getCartTotal();
  if (found.minOrder > 0 && cart.totalPrice < found.minOrder) {
    couponError = 'Minimum order ' + getCurrency() + found.minOrder.toFixed(2) + ' required';
    appliedCoupon = null;
    renderCart();
    setTimeout(function() {
      var inp = document.getElementById('couponInput');
      if (inp) inp.classList.add('error');
    }, 0);
    return;
  }

  appliedCoupon = found;
  couponError = '';
  couponUserChoice = true;
  lastCouponTyped = '';
  renderCart();
  setTimeout(function() {
    var inp = document.getElementById('couponInput');
    if (inp) inp.classList.remove('error');
  }, 0);
}

// A scratch card reward saved on the tracking page ("Use on my next order"), or the offer code
// from a poster / campaign link (?coupon=CODE, saved by header.php as rgPromoCode)
function applySavedReward() {
  applySavedCode('rgRewardCode', 'Your scratch card reward ');
  applySavedCode('rgPromoCode', 'Offer ');
}
function campaignSource() {
  try {
    var c = JSON.parse(localStorage.getItem('rgCampaign') || 'null');
    var rid = document.querySelector('meta[name=restaurant-id]')?.content || '';
    if (c && c.src && c.rid === rid && Date.now() - (c.at || 0) < 7 * 86400000) return c.src;
  } catch (e) {}
  return '';
}
function applySavedCode(key, label) {
  var saved = null;
  try { saved = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) {}
  var rid = document.querySelector('meta[name=restaurant-id]')?.content || '';
  if (!saved || !saved.code || saved.rid !== rid) return;
  if (appliedCoupon && appliedCoupon.code === saved.code) return;
  lookupCoupon(saved.code, function() {
    var c = null;
    for (var i = 0; i < availableCoupons.length; i++) if (availableCoupons[i].code === saved.code) c = availableCoupons[i];
    if (!c) { try { localStorage.removeItem(key); } catch (e) {} return; }   // used or expired
    var cart = getCartTotal();
    if (appliedCoupon && couponSaving(appliedCoupon, cart.totalPrice) >= couponSaving(c, cart.totalPrice)) return;
    if (c.minOrder > 0 && cart.totalPrice < c.minOrder) return;
    appliedCoupon = c; couponError = ''; couponUserChoice = true;
    renderCart();
    if (typeof showToast === 'function') showToast(label + c.code + ' is applied', 'success');
  });
}

function removeCoupon() {
  appliedCoupon = null;
  couponError = '';
  couponUserChoice = true;
  renderCart();
}

function openCouponSheet() {
  var existing = document.getElementById('couponSheetOverlay');
  if (existing) existing.remove();

  var overlay = document.createElement('div');
  overlay.id = 'couponSheetOverlay';
  overlay.className = 'coupon-overlay';
  overlay.onclick = function(e) { if (e.target === this) closeCouponSheet(); };

  var cart = getCartTotal();
  var listHtml = '';
  for (var i = 0; i < availableCoupons.length; i++) {
    var c = availableCoupons[i];
    var isActive = appliedCoupon && appliedCoupon.code === c.code;
    var discLabel = c.type === 'percent' ? c.discount + '% OFF' : (c.type === 'flat' ? getCurrency() + c.discount + ' OFF' : 'FREE DELIVERY');
    var minText = c.minOrder > 0 ? 'Min. order: ' + getCurrency() + c.minOrder.toFixed(2) : 'No minimum';
    var canUse = c.minOrder <= 0 || cart.totalPrice >= c.minOrder;
    listHtml +=
      '<div class="coupon-item ' + (isActive ? 'active' : '') + '" onclick="selectCoupon(\'' + c.code + '\')">' +
        '<div class="cpn-icon">%</div>' +
        '<div class="cpn-info">' +
          '<div class="cpn-code">' + escapeHtml(c.code) + '</div>' +
          '<div class="cpn-desc">' + discLabel + ' - ' + escapeHtml(c.description) + '</div>' +
          '<div class="cpn-expiry">Valid till: ' + c.validTill + '</div>' +
          '<div class="cpn-min">' + minText + '</div>' +
        '</div>' +
        '<button class="cpn-select">' + (isActive ? 'Applied' : (canUse ? 'Select' : 'Locked')) + '</button>' +
      '</div>';
  }

  overlay.innerHTML =
    '<div class="coupon-sheet">' +
      '<div class="coupon-sheet-header">' +
        '<h3>Available Coupons</h3>' +
        '<button class="coupon-sheet-close" onclick="closeCouponSheet()">&times;</button>' +
      '</div>' +
      '<div class="coupon-list">' + listHtml + '</div>' +
    '</div>';

  document.body.appendChild(overlay);
  requestAnimationFrame(function() { overlay.classList.add('open'); });
}

function closeCouponSheet() {
  var overlay = document.getElementById('couponSheetOverlay');
  if (overlay) {
    overlay.classList.remove('open');
    setTimeout(function() { overlay.remove(); }, 300);
  }
}

function selectCoupon(code) {
  if (appliedCoupon && appliedCoupon.code === code) return;
  for (var i = 0; i < availableCoupons.length; i++) {
    if (availableCoupons[i].code === code) {
      var cart = getCartTotal();
      if (availableCoupons[i].minOrder > 0 && cart.totalPrice < availableCoupons[i].minOrder) return;
      appliedCoupon = availableCoupons[i];
      couponError = '';
      couponUserChoice = true;
      break;
    }
  }
  closeCouponSheet();
  renderCart();
}
// --- End Coupon System ---

function getPrice(item, key) {
  if (key) {
    var raw = cartItems[key];
    if (raw && typeof raw === 'object' && raw.varPrice) return raw.varPrice;
  }
  return parseFloat(item.base_price || item.price || 0);
}

function isRestaurantOpen(hours) {
  if (!hours) return true;
  var days = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
  // Use restaurant's timezone if available, else local time
  var now = new Date();
  if (typeof window.restaurantTimezoneOffset === 'number') {
    var utc = now.getTime() + now.getTimezoneOffset() * 60000;
    now = new Date(utc + window.restaurantTimezoneOffset * 60000);
  }
  function toMins(t) {
    var p = String(t || '').split(' ');
    var tp = p[0].split(':');
    var h = parseInt(tp[0], 10), m = parseInt(tp[1], 10) || 0;
    if (p[1] === 'PM' && h !== 12) h += 12;
    if (p[1] === 'AM' && h === 12) h = 0;
    return h * 60 + m;
  }
  var cur = now.getHours() * 60 + now.getMinutes();
  // today's hours, or yesterday's shift that runs past midnight (same rule as config/opening_hours.php)
  var today = hours[days[now.getDay()]], yest = hours[days[(now.getDay() + 6) % 7]];
  if (today && today.open) {
    var o = toMins(today.opening || '09:00 AM'), c = toMins(today.closing || '10:00 PM');
    if (c <= o ? cur >= o : (cur >= o && cur < c)) return true;
  }
  if (yest && yest.open) {
    var yo = toMins(yest.opening || '09:00 AM'), yc = toMins(yest.closing || '10:00 PM');
    if (yc <= yo && cur < yc) return true;
  }
  return false;
}

function showClosedModal() {
  var overlay = document.createElement('div');
  overlay.className = 'closed-overlay';
  overlay.onclick = function(e) { if (e.target === this) this.remove(); };
  overlay.innerHTML = '<div class="closed-overlay-inner">' +
    '<span class="closed-icon">🔒</span>' +
    '<h2>We\'re Closed</h2>' +
    '<p>Our restaurant is currently closed.<br>Please come back during our opening hours!</p>' +
    '<button onclick="location.href=\'<?php echo restaurantPageUrl(); ?>\'">OK, Go Back</button>' +
    '</div>';
  document.body.appendChild(overlay);
}

function getCartTotal() {
  var keys = Object.keys(cartItems).filter(function(k) { return getCartQty(k) > 0; });
  var totalQty = 0, totalPrice = 0;
  for (var i = 0; i < keys.length; i++) {
    var key = keys[i];
    var raw = cartItems[key];
    if (raw && typeof raw === 'object' && raw.isAddon) {
      totalQty += raw.qty || 0;
      totalPrice += (raw.addonPrice || 0) * (raw.qty || 0);
      continue;
    }
    if (raw && typeof raw === 'object' && raw.isCombo) {
      totalQty += raw.qty || 0;
      totalPrice += parseFloat(raw.varPrice || 0) * (raw.qty || 0);
      continue;
    }
    var item = getItemByKey(key);
    if (item) {
      var qty = getCartQty(key);
      var basePrice = getCartPrice(key);
      var addonTotal = getAddonTotalForItem(key);
      totalQty += qty;
      totalPrice += (basePrice + addonTotal) * qty;   // add-ons per plate (process_website_order.php does the same)
    }
  }
  var discountAmount = 0;
  if (appliedCoupon) {
    if (appliedCoupon.type === 'percent') discountAmount = totalPrice * appliedCoupon.discount / 100;
    else if (appliedCoupon.type === 'flat') discountAmount = appliedCoupon.discount;
  }
  var remainingAfterCoupon = Math.max(0, totalPrice - discountAmount);
  var loyaltyMaxUsable = getLoyaltyMaxUsablePoints(remainingAfterCoupon);
  var loyaltyPointsRedeemed = (useLoyaltyPoints && loyaltyMaxUsable >= loyaltyMinRedeemPoints && loyaltyMaxUsable > 0) ? loyaltyMaxUsable : 0;
  var loyaltyDiscountAmount = loyaltyPointsRedeemed > 0 ? +(loyaltyPointsRedeemed * loyaltyRedeemValuePerPoint).toFixed(2) : 0;
  var vipDiscountAmount = vipCartDiscount(Math.max(0, remainingAfterCoupon - loyaltyDiscountAmount));
  var finalTotal = Math.max(0, remainingAfterCoupon - loyaltyDiscountAmount - vipDiscountAmount);
  return {
    totalQty: totalQty, totalPrice: totalPrice, keys: keys,
    discountAmount: discountAmount, appliedCoupon: appliedCoupon,
    loyaltyPointsRedeemed: loyaltyPointsRedeemed, loyaltyDiscountAmount: loyaltyDiscountAmount,
    vipDiscountAmount: vipDiscountAmount,
    finalTotal: finalTotal
  };
}

// --- Coupon celebration: a 3–4 second "you saved ₹X" moment when a coupon is on
// the order and the customer taps Checkout. Once per coupon per visit; tap
// anywhere to skip; then checkout carries on exactly as before.
function maybeCelebrateCoupon() {
  if (window._cpnCelebrating || !appliedCoupon) return false;
  var cart = getCartTotal();
  var saved = Math.round(cart.discountAmount || 0);
  if (saved <= 0) return false;
  var key = 'cpnCelebrated_' + appliedCoupon.code;
  try { if (sessionStorage.getItem(key)) return false; sessionStorage.setItem(key, '1'); } catch (e) {}
  showCouponCelebration(appliedCoupon.code, saved, function() {
    window._cpnCelebrating = true;   // let the next call go straight through
    proceedCheckout();
    window._cpnCelebrating = false;
  });
  return true;
}

function showCouponCelebration(code, saved, done) {
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var ms = reduce ? 1600 : 3500;
  var cur = getCurrency();
  var el = document.createElement('div');
  el.className = 'cpn-cele';
  el.setAttribute('role', 'dialog');
  el.setAttribute('aria-label', 'You saved ' + cur + saved);
  el.style.setProperty('--cele-ms', ms + 'ms');
  el.innerHTML = '<canvas></canvas>' +
    '<div class="cpn-cele-card">' +
      '<div class="cpn-cele-badge"><i class="fa fa-gift"></i></div>' +
      '<div class="cpn-cele-kicker">Congratulations</div>' +
      '<div class="cpn-cele-amount">You saved <span id="celeAmt">' + cur + '0</span></div>' +
      '<div style="font-size:13px;color:#6b7280;">on this order</div>' +
      '<div class="cpn-cele-code">' + escapeHtml(code) + ' applied</div>' +
      '<div class="cpn-cele-note">Tap anywhere to continue</div>' +
      '<div class="cpn-cele-timer"><i></i></div>' +
    '</div>';
  document.body.appendChild(el);
  requestAnimationFrame(function() { el.classList.add('show'); });

  // Count the saving up
  var amt = el.querySelector('#celeAmt'), t0 = performance.now(), dur = reduce ? 1 : 900;
  (function tick(now) {
    var p = Math.min(1, (now - t0) / dur);
    amt.textContent = cur + Math.round(saved * (1 - Math.pow(1 - p, 3)));
    if (p < 1) requestAnimationFrame(tick);
  })(t0);

  // Confetti: coloured squares only — two party-popper bursts from the bottom
  // corners, then a shower from the top, for the whole popup.
  var canvas = el.querySelector('canvas'), ctx = canvas.getContext && canvas.getContext('2d'), raf = 0;
  if (ctx && !reduce) {
    var dpr = window.devicePixelRatio || 1, W = innerWidth, H = innerHeight;
    canvas.width = W * dpr; canvas.height = H * dpr; ctx.scale(dpr, dpr);
    var brand = getComputedStyle(document.documentElement).getPropertyValue('--primary-red').trim() || '#e53935';
    var colors = [brand, '#fbbf24', '#f97316', '#22c55e', '#3b82f6', '#a855f7', '#ec4899', '#14b8a6'];
    var parts = [];
    function square(x, y, vx, vy) {
      parts.push({ x: x, y: y, vx: vx, vy: vy, s: 6 + Math.random() * 6, r: Math.random() * Math.PI,
        vr: (Math.random() - 0.5) * 0.35, flip: Math.random() * Math.PI, vf: 0.08 + Math.random() * 0.12,
        c: colors[(Math.random() * colors.length) | 0] });
    }
    function popper(fromLeft) {
      for (var i = 0; i < 90; i++) {
        var ang = (fromLeft ? -60 : -120) + (Math.random() - 0.5) * 40;   // up and inwards
        var sp = 11 + Math.random() * 9, rad = ang * Math.PI / 180;
        square(fromLeft ? -10 : W + 10, H - 40, Math.cos(rad) * sp, Math.sin(rad) * sp);
      }
    }
    popper(true); popper(false);
    var t0 = performance.now(), lastRain = t0;
    (function frame(now) {
      ctx.clearRect(0, 0, W, H);
      // keep a light shower going from the top until near the end
      if (now - t0 > 500 && now - t0 < ms - 700 && now - lastRain > 60) {
        lastRain = now;
        for (var k = 0; k < 4; k++) square(Math.random() * W, -12, (Math.random() - 0.5) * 2, 1 + Math.random() * 2);
      }
      for (var i = parts.length - 1; i >= 0; i--) {
        var p = parts[i];
        p.vy += 0.22; p.vx *= 0.985; p.vy = Math.min(p.vy, 5.5);
        p.x += p.vx + Math.sin(p.flip) * 0.6; p.y += p.vy; p.r += p.vr; p.flip += p.vf;
        if (p.y > H + 20) { parts.splice(i, 1); continue; }
        ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.r);
        ctx.scale(1, Math.cos(p.flip));                 // squares tumble as they fall
        ctx.fillStyle = p.c; ctx.fillRect(-p.s / 2, -p.s / 2, p.s, p.s);
        ctx.restore();
      }
      raf = requestAnimationFrame(frame);
    })(t0);
  }

  var finished = false;
  function finish() {
    if (finished) return;
    finished = true;
    el.classList.add('hide');
    setTimeout(function() { cancelAnimationFrame(raf); el.remove(); done(); }, 300);
  }
  el.addEventListener('click', finish);
  setTimeout(finish, ms);
}

function proceedCheckout() {
  // Use PHP-computed restaurant_open status (server-side IST check) as the source of truth
  // The JS isRestaurantOpen() is only used as fallback if PHP value is not available
  var isOpen = (window.restaurantOpen !== undefined) ? window.restaurantOpen : true;
  // Only do JS fallback if PHP value is truly not set (null/undefined)
  if (isOpen && window.openingHours && window.restaurantOpen === undefined) {
    isOpen = isRestaurantOpen(window.openingHours);
  }
  if (!isOpen) {
    showClosedModal();
    return;
  }
  var cart = getCartTotal();
  if (MIN_ORDER > 0 && cart.totalPrice < MIN_ORDER) {
    showModal('Minimum Order', getCurrency() + MIN_ORDER.toFixed(2) + ' minimum. Please add more items.');
    return;
  }
  // A coupon is on the order: celebrate the saving first (3–4 s), then continue
  if (maybeCelebrateCoupon()) return;
  // Gate checkout behind login/signup/guest, same pattern as profile.php:
  // if this browser session hasn't logged in or already chosen "Continue as
  // Guest", send them to the login screen first. Guests still land right
  // back here (login.php redirects to ?redirect=cart) and the checkout form
  // below asks for the same name/phone/email it always has.
  if (!window.loggedInCustomer) {
    var guestSessionActive = false;
    try { guestSessionActive = sessionStorage.getItem('guestSessionActive') === '1'; } catch(e) {}
    if (!guestSessionActive) {
      // Preserve ?table=X (dine-in QR scan) across the login/signup/guest
      // detour, otherwise login.php would bounce a QR'd-in customer back to
      // cart.php with no table context.
      window.location.href = appendTableParam(window.loginUrl);
      return;
    }
  }
  showCheckoutModal(cart);
}

function checkPincode(pincode) {
  var statusEl = document.getElementById('pincodeStatus');
  var infoEl = document.getElementById('deliveryInfo');
  var zoneIdEl = document.getElementById('deliveryZoneId');
  var chargeEl = document.getElementById('deliveryCharge');
  var feeRow = document.getElementById('deliveryFeeRow');
  var feeAmount = document.getElementById('deliveryFeeAmount');
  var totalDisplay = document.getElementById('finalTotalDisplay');
  var rid = document.querySelector('meta[name=restaurant-id]')?.content || 'RES001';

  if (!pincode || pincode.length < 4) {
    statusEl.style.display = 'none';
    infoEl.style.display = 'none';
    zoneIdEl.value = '';
    chargeEl.value = '0';
    feeRow.style.display = 'none';
    return;
  }

  statusEl.style.display = 'flex';
  statusEl.innerHTML = '<span class="material-symbols-rounded" style="font-size:18px;animation:spin 1s linear infinite;">refresh</span>';

  fetch('../api/check_pincode.php?pincode=' + encodeURIComponent(pincode) + '&restaurant_id=' + encodeURIComponent(rid))
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success && res.available && res.zone) {
        var z = res.zone;
        statusEl.style.display = 'flex';
        statusEl.innerHTML = '<span class="material-symbols-rounded" style="font-size:18px;color:#22c55e;">check_circle</span>';
        infoEl.style.display = 'block';
        infoEl.innerHTML = '<strong>' + esc(z.zone_name || 'Delivery') + '</strong><br>' +
          'Delivery Fee: <strong>' + getCurrency() + z.delivery_charge.toFixed(2) + '</strong> | ' +
          'Est. Time: <strong>' + z.estimated_time + ' min</strong>';
        zoneIdEl.value = z.id;
        chargeEl.value = z.delivery_charge;
        updateOrderTotal(z.delivery_charge);
      } else {
        statusEl.style.display = 'flex';
        statusEl.innerHTML = '<span class="material-symbols-rounded" style="font-size:18px;color:#ef4444;">cancel</span>';
        infoEl.style.display = 'block';
        infoEl.style.color = '#ef4444';
        infoEl.innerHTML = 'We do not deliver to this pincode yet';
        zoneIdEl.value = '';
        chargeEl.value = '0';
        feeRow.style.display = 'none';
      }
    })
    .catch(function() {
      statusEl.innerHTML = '<span class="material-symbols-rounded" style="font-size:18px;color:#ef4444;">error</span>';
      infoEl.style.display = 'block';
      infoEl.innerHTML = 'Could not verify pincode. Please try again.';
    });
}

var checkoutDeliveryCharge = 0;

function updateOrderTotal(charge) {
  checkoutDeliveryCharge = charge || 0;
  refreshCheckoutTotal();
}

function refreshCheckoutTotal() {
  var modal = document.getElementById('checkoutModal');
  if (!modal) return;
  var baseAmt = parseFloat(modal.dataset.baseTotal) || 0;
  var otRadio = document.querySelector('input[name="orderType"]:checked');
  var ot = otRadio ? otRadio.value : 'Delivery';
  var isDelivery = ot === 'Delivery' && (window.enableDelivery == 1 || window.enableDelivery === true);
  var isTakeaway = ot === 'Takeaway';
  var isDinein = ot === 'Dine-in';

  var hasPackaging = (isDelivery || isTakeaway) && PACKAGING_CHARGE > 0;
  var pkgCharge = hasPackaging ? PACKAGING_CHARGE : 0;
  var delCharge = isDelivery ? checkoutDeliveryCharge : 0;
  var vipFree = isDelivery && delCharge > 0 && vipFreeDelivery(getCartTotal().totalPrice);
  if (vipFree) delCharge = 0;

  var showGst = window.enableGst == 1 || window.enableGst === true;
  var taxable = baseAmt;
  var gst = showGst ? taxable * ((typeof window.taxPercent === 'number' ? window.taxPercent : 5) / 100) : 0;

  var gstRow = document.getElementById('gstRow');
  var gstAmount = document.getElementById('gstAmount');
  if (gstRow && gstAmount) {
    gstRow.style.display = showGst ? 'flex' : 'none';
    gstAmount.textContent = getCurrency() + gst.toFixed(2);
  }

  var packagingRow = document.getElementById('packagingChargeRow');
  if (packagingRow) {
    packagingRow.style.display = hasPackaging ? 'flex' : 'none';
    if (hasPackaging) {
      document.getElementById('packagingChargeAmount').textContent = getCurrency() + PACKAGING_CHARGE.toFixed(2);
    }
  }

  var feeRow = document.getElementById('deliveryFeeRow');
  var feeAmount = document.getElementById('deliveryFeeAmount');
  if (feeRow && feeAmount) {
    feeRow.style.display = isDelivery && (delCharge > 0 || vipFree) ? 'flex' : 'none';
    if (vipFree) feeAmount.innerHTML = '<s style="color:#9ca3af">' + getCurrency() + checkoutDeliveryCharge.toFixed(2) + '</s> <b style="color:#16a34a">Free</b>';
    else if (isDelivery && delCharge > 0) {
      feeAmount.textContent = getCurrency() + delCharge.toFixed(2);
    }
  }

  var finalTotal = taxable + gst + pkgCharge + delCharge;
  var finalDisplay = document.getElementById('finalTotalDisplay');
  if (finalDisplay) {
    finalDisplay.textContent = getCurrency() + finalTotal.toFixed(2);
  }
}

function showCheckoutModal(cartData) {
  var total = cartData.finalTotal;
  var qty = cartData.totalQty;
  var keys = cartData.keys;
  var existing = document.getElementById('checkoutModal');
  if (existing) existing.remove();

  var cd = loadCustomer();
  var modal = document.createElement('div');
  modal.id = 'checkoutModal';
  modal.className = 'modal-overlay';
  modal.onclick = function(e) { if (e.target === this) this.remove(); };

  // Read table param early so it's available for all conditionals below
  var urlParams = new URLSearchParams(window.location.search);
  var qrTable = urlParams.get('table') || sessionStorage.getItem('qrTable') || '';

  var html = '<div class="modal-box">';
  html += '<div class="modal-header">';
  html += '<h2>Checkout</h2>';
  html += '<button class="modal-close" onclick="this.closest(\'.modal-overlay\').remove()">&times;</button>';
  html += '</div>';
  html += '<div class="modal-body">';
  html += '<form id="checkoutForm">';

  // Once we already know who's ordering (a logged-in account, or details
  // saved from a previous guest checkout), show a compact read-only summary
  // instead of three empty-looking input boxes every single time - "Change"
  // reveals the real fields for the rare case they need editing.
  var hasContact = !!(cd && cd.name && cd.phone);
  html += '<div id="contactSummary" class="form-group" style="display:' + (hasContact ? 'flex' : 'none') + ';align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;background:#f7f7f8;border-radius:10px;">';
  html += '<div><div style="font-size:13px;font-weight:600;color:#1a1b1f;">' + esc((cd && cd.name) || '') + '</div><div style="font-size:12px;color:#777;margin-top:2px;">' + (window.restaurantDialCode || '+91') + ' ' + esc((cd && cd.phone) || '') + '</div></div>';
  html += '<span onclick="toggleContactEdit()" style="font-size:12px;color:var(--primary-red, #e17055);font-weight:600;cursor:pointer;white-space:nowrap;">Change</span>';
  html += '</div>';
  html += '<div id="contactFields" style="display:' + (hasContact ? 'none' : '') + '">';
  html += '<div class="form-group"><label>Full Name *</label><input type="text" id="chkName" required value="' + esc((cd && cd.name) || '') + '" placeholder="Your name"></div>';
  html += '<div class="form-group"><label>Phone Number *</label><div style="display:flex;align-items:center;gap:6px;"><span style="color:#666;font-size:14px;white-space:nowrap;">' + (window.restaurantDialCode || '+91') + '</span><input type="tel" id="chkPhone" required value="' + esc((cd && cd.phone) || '') + '" placeholder="Your phone" style="flex:1;"></div></div>';
  if (window.waOptinAsk) {
    var waSaved = false; try { waSaved = localStorage.getItem('rgWaOptin') === '1'; } catch (e) {}
    html += '<label class="wa-optin" style="display:flex;gap:10px;align-items:flex-start;margin:-4px 0 14px;padding:10px 12px;border-radius:12px;background:#f0fdf4;border:1px solid #bbf7d0;font-size:13px;color:#374151;cursor:pointer;line-height:1.4">' +
      '<input type="checkbox" id="chkWaOptin"' + (waSaved ? ' checked' : '') + ' style="width:17px;height:17px;margin-top:2px;accent-color:#16a34a;flex-shrink:0">' +
      '<span><b style="display:block;color:#111827">Get my rewards and offers on WhatsApp</b>Gift codes, birthday treats and the odd special. Stop anytime.</span></label>';
  }
  html += '<div class="form-group"><label>Email</label><input type="email" id="chkEmail" value="' + esc((cd && cd.email) || '') + '" placeholder="Your email (optional)"></div>';
  html += '</div>';

  // Determine default order type (used for initial address visibility)
  var savedOrderType = 'delivery';
  if (qrTable) {
    savedOrderType = 'dinein';
    sessionStorage.setItem('qrTable', qrTable);
  } else {
    savedOrderType = localStorage.getItem('dvaniOrderType') || 'delivery';
  }

  var deliveryEnabled = window.enableDelivery == 1 || window.enableDelivery === true;
  var pincodeDisplay = (savedOrderType === 'delivery' && deliveryEnabled) ? '' : 'none';
  html += '<div id="deliveryPincodeSection" class="form-group" style="display:' + pincodeDisplay + '">';
  html += '<label>Delivery Address *</label>';

  // Same "don't ask again" treatment as the contact fields: a saved address
  // from last time shows as a compact summary; "Change" reveals the full
  // location picker (current location / search / map) for the rare edit.
  var hasSavedAddress = !!(cd && cd.address);
  html += '<div id="addressSummary" style="display:' + (hasSavedAddress ? 'flex' : 'none') + ';align-items:flex-start;justify-content:space-between;gap:10px;padding:12px 14px;background:color-mix(in srgb, var(--primary-red, #e53935) 7%, #fff);border-radius:10px;border:2px solid #e0e0e0;">';
  html += '<div style="font-size:13px;color:#1a1b1f;line-height:1.4;"><i class="fa-solid fa-location-dot" style="color:var(--primary-red, #e53935);margin-right:6px"></i>' + esc((cd && cd.address) || '') + ((cd && cd.landmark) ? '<div style="color:#999;font-size:12px;margin-top:2px;">' + esc(cd.landmark) + '</div>' : '') + '</div>';
  html += '<span onclick="toggleAddressEdit()" style="font-size:12px;color:var(--primary-red, #e17055);font-weight:600;cursor:pointer;white-space:nowrap;flex-shrink:0;">Change</span>';
  html += '</div>';

  html += '<div id="addressPickerFields" style="display:' + (hasSavedAddress ? 'none' : '') + '">';
  html += '<button type="button" id="useCurrentLocationBtn" onclick="useCurrentLocationForDelivery()" style="width:100%;display:flex;align-items:center;justify-content:center;gap:6px;padding:12px 14px;border:none;border-radius:10px;background:var(--primary-red, #e53935);color:#fff;font-size:14px;font-weight:700;font-family:\'Poppins\',sans-serif;cursor:pointer;border-radius:12px;"><i class="fa-solid fa-location-crosshairs"></i> Use my current location</button>';
  html += '<div style="text-align:center;margin:8px 0;font-size:11px;color:#999;">— or —</div>';
  html += '<input type="text" id="google-places-autocomplete" autocomplete="off" placeholder="Search your delivery address..." style="width:100%;padding:12px 14px;border:2px solid #e0e0e0;border-radius:10px;font-size:13px;font-family:\'Poppins\',sans-serif;outline:none;box-sizing:border-box">';
  html += '<button type="button" onclick="openMapPicker()" style="margin-top:8px;width:100%;display:flex;align-items:center;justify-content:center;gap:6px;padding:11px 14px;border:2px solid var(--primary-red, #e53935);border-radius:10px;background:#fff;color:var(--primary-red, #e53935);font-size:13px;font-weight:600;font-family:\'Poppins\',sans-serif;cursor:pointer;"><i class="fa-regular fa-map"></i> Pick on map</button>';
  html += '<div id="deliveryMapPreview" style="display:none;margin-top:10px;width:100%;height:160px;border-radius:10px;overflow:hidden;border:2px solid #e0e0e0;"></div>';
  html += '<div id="deliveryInfo" style="display:none;margin-top:8px;padding:10px;background:color-mix(in srgb, var(--primary-red, #e53935) 7%, #fff);border-radius:8px;font-size:13px;"></div>';
  // Pincode manual fallback (hidden by default)
  html += '<div id="pincodeFallback" style="display:none;margin-top:8px;">';
  html += '<div style="display:flex;gap:8px;">';
  html += '<input type="text" id="chkPincode" class="form-control" placeholder="Or enter pincode manually" maxlength="10" style="flex:1;font-size:12px;" oninput="checkPincode(this.value)">';
  html += '<span id="pincodeStatus" style="display:none;align-items:center;padding:0 8px;"></span>';
  html += '</div>';
  html += '<span onclick="toggleAddressAutocomplete()" style="font-size:11px;color:var(--primary-red, #e17055);cursor:pointer;margin-top:4px;display:inline-block;">Use address search instead</span>';
  html += '</div>';
  html += '<span onclick="togglePincodeFallback()" id="togglePincodeLink" style="font-size:11px;color:var(--primary-red, #e17055);cursor:pointer;display:' + (pincodeDisplay === '' ? 'inline-block' : 'none') + ';margin-top:4px;">Enter pincode manually</span>';
  html += '<div style="margin-top:10px;">';
  html += '<label>Landmark <span style="color:#999;font-weight:400;">(optional)</span></label>';
  html += '<input type="text" id="chkLandmark" value="' + esc((cd && cd.landmark) || '') + '" placeholder="e.g. Near City Hospital, Opp. SBI Bank" style="width:100%;padding:12px 14px;border:2px solid #e0e0e0;border-radius:10px;font-size:13px;font-family:\'Poppins\',sans-serif;outline:none;box-sizing:border-box">';
  html += '</div>';
  html += '</div>';
  // Hidden fields for the selected address - pre-filled from the saved
  // address so a repeat order can submit without reopening the picker at all.
  html += '<input type="hidden" id="deliveryZoneId" value="">';
  html += '<input type="hidden" id="deliveryCharge" value="0">';
  html += '<input type="hidden" id="packagingChargeHidden" value="' + PACKAGING_CHARGE + '">';
  html += '<input type="hidden" id="chkAddressLat" value="' + esc((cd && cd.addressLat) || '') + '">';
  html += '<input type="hidden" id="chkAddressLng" value="' + esc((cd && cd.addressLng) || '') + '">';
  html += '<input type="hidden" id="chkAddressFormatted" value="' + esc((cd && cd.address) || '') + '">';
  // Hidden pincode from autocomplete
  html += '<input type="hidden" id="chkPincodeAuto" value="">';
  html += '</div>';

  html += '<div class="order-summary-box">';
  html += '<h3>Order Summary</h3>';
  for (var i = 0; i < keys.length; i++) {
    var key = keys[i];
    var item = getItemByKey(key);
    if (item) {
      var raw = cartItems[key];
      var itemName = item.item_name_translated || item.item_name_en || item.name;
      if (raw && typeof raw === 'object' && raw.varName) itemName += ' (' + raw.varName + ')';
      html += '<div class="summary-row">';
      html += '<span>' + esc(itemName) + ' x' + getCartQty(key) + '</span>';
      html += '<span>' + getCurrency() + (getCartPrice(key) * getCartQty(key)).toFixed(2) + '</span>';
      html += '</div>';
    }
  }
  if (cartData.discountAmount > 0) {
    html += '<div class="summary-discount">';
    html += '<span>Discount (' + cartData.appliedCoupon.code + ')</span><span>-' + getCurrency() + cartData.discountAmount.toFixed(2) + '</span>';
    html += '</div>';
  }
  if (cartData.vipDiscountAmount > 0) {
    html += '<div class="summary-discount">';
    html += '<span>' + esc(window.cartVip.name) + ' member (' + window.cartVip.pct + '% off)</span><span>-' + getCurrency() + cartData.vipDiscountAmount.toFixed(2) + '</span>';
    html += '</div>';
  }
  html += '<div id="packagingChargeRow" class="summary-row" style="display:none;">';
  html += '<span>Packaging Charge</span><span id="packagingChargeAmount">' + getCurrency() + '0.00</span>';
  html += '</div>';
  html += '<div id="deliveryFeeRow" class="summary-row" style="display:none;">';
  html += '<span>Delivery Fee</span><span id="deliveryFeeAmount">' + getCurrency() + '0.00</span>';
  html += '</div>';
  var enableGst = window.enableGst == 1 || window.enableGst === true;
  var taxLabel = (window.taxName || 'GST') + ' (' + parseFloat(typeof window.taxPercent === 'number' ? window.taxPercent : 5) + '%)';
  html += '<div id="gstRow" class="summary-row" style="display:' + (enableGst ? 'flex' : 'none') + ';">';
  html += '<span>' + taxLabel + '</span><span id="gstAmount">' + getCurrency() + '0.00</span>';
  html += '</div>';
  html += '<hr class="summary-divider">';
  html += '<div class="summary-total">';
  html += '<span>Total</span><span id="finalTotalDisplay">' + getCurrency() + total.toFixed(2) + '</span></div>';
  html += '</div>';

  // Always wrap in form-group (needed for spacing + DOM balance)
  html += '<div class="form-group">';
  // Show Order Type label only when not ordering from table QR
  if (!qrTable) {
    html += '<label>Order Type</label>';
  }
  // If a table is pre-selected from QR code, default to Dine-in
  // savedOrderType already set above
  // qrTable already defined above (before HTML building)
  html += '<div style="display:flex;gap:10px;margin-top:4px;flex-wrap:wrap">';
  // Only show order types enabled in backend settings
  var allOtTypes = [
    { key: 'delivery', label: '<i class="fa-solid fa-motorcycle"></i>Delivery', val: 'Delivery' },
    { key: 'takeaway', label: '<i class="fa-solid fa-bag-shopping"></i>Takeaway', val: 'Takeaway' },
    { key: 'dinein', label: '<i class="fa-solid fa-utensils"></i>Dine in', val: 'Dine-in' }
  ];
  var otOptions = [];
  // If ordering from a table QR code, only show Dine In
  // Use URL param ONLY (not sessionStorage) to avoid stale table locking order type
  var viaQrTable = urlParams.get('table') || '';
  for (var oi = 0; oi < allOtTypes.length; oi++) {
    var ot = allOtTypes[oi];
    if (viaQrTable) {
      if (ot.key === 'dinein' && (window.enableDinein == 1 || window.enableDinein === true)) {
        otOptions.push(ot);
      }
    } else {
      if ((ot.key === 'delivery' && (window.enableDelivery == 1 || window.enableDelivery === true)) ||
          (ot.key === 'takeaway' && (window.enableTakeaway == 1 || window.enableTakeaway === true)) ||
          (ot.key === 'dinein' && (window.enableDinein == 1 || window.enableDinein === true))) {
        otOptions.push(ot);
      }
    }
  }
  for (var oi = 0; oi < otOptions.length; oi++) {
    var opt = otOptions[oi];
    var active = savedOrderType === opt.key;
    html += '<label class="order-type-option" data-ot="' + opt.key + '" style="flex:1;padding:10px;border:2px solid ' + (active ? 'var(--primary-red, #e53935)' : '#e0e0e0') + ';border-radius:10px;text-align:center;cursor:pointer;background:' + (active ? 'color-mix(in srgb, var(--primary-red, #e53935) 7%, #fff)' : '#fff') + ';font-size:13px;min-width:80px">';
    html += '<input type="radio" name="orderType" value="' + opt.val + '" ' + (active ? 'checked' : '') + ' style="display:none"> ' + opt.label + '</label>';
  }
  html += '</div>';  // close flex wrapper
  // When QR table is set, hide the UI but keep select in DOM for order submission
  var tableSectionDisplay = (savedOrderType === 'dinein' && !qrTable) ? '' : 'none';
  html += '<div id="dineinTableSection" style="margin-top:10px;display:' + tableSectionDisplay + '">';
  html += '<label style="display:block;margin-bottom:6px;font-weight:500;color:#333;font-size:13px"><i class="fa-solid fa-chair" style="color:var(--primary-red, #e53935);margin-right:6px"></i>Select a table</label>';

  html += '<select id="chkTableId" style="width:100%;padding:12px 14px;border:2px solid #e0e0e0;border-radius:10px;font-size:13px;font-family:\'Poppins\',sans-serif;outline:none;box-sizing:border-box">';
  html += '<option value="">Select a table...</option></select>';
  html += '</div>';  // close dineinTableSection
  html += '</div>';  // close form-group

  // Order Now vs Schedule for Later
  html += '<div class="form-group">';
  html += '<label>When?</label>';
  html += '<div style="display:flex;gap:10px;margin-top:4px;">';
  html += '<label class="schedule-option" data-sched="now" style="flex:1;padding:10px;border:2px solid var(--primary-red, #e53935);border-radius:10px;text-align:center;cursor:pointer;background:color-mix(in srgb, var(--primary-red, #e53935) 7%, #fff);font-size:13px;">';
  html += '<input type="radio" name="scheduleType" value="now" checked style="display:none"><i class="fa-solid fa-bolt"></i>Order now</label>';
  html += '<label class="schedule-option" data-sched="later" style="flex:1;padding:10px;border:2px solid #e0e0e0;border-radius:10px;text-align:center;cursor:pointer;background:#fff;font-size:13px;">';
  html += '<input type="radio" name="scheduleType" value="later" style="display:none"><i class="fa-regular fa-calendar"></i>Schedule for later</label>';
  html += '</div>';
  html += '<input type="datetime-local" id="chkScheduledAt" style="display:none;margin-top:10px;width:100%;padding:12px 14px;border:2px solid #e0e0e0;border-radius:10px;font-size:13px;font-family:\'Poppins\',sans-serif;outline:none;box-sizing:border-box">';
  html += '<div id="scheduleHint" style="display:none;margin-top:6px;font-size:11px;color:#999;">Pick a time at least 15 minutes from now, within the next 7 days, during our opening hours.</div>';
  html += '</div>';

  // Payment method label depends on order type
  var isCounterOrder = (savedOrderType === 'takeaway' || savedOrderType === 'dinein');
  var cashLabel = isCounterOrder ? 'Pay at Counter' : 'Cash on Delivery';
  // COD can be turned off in restaurant settings, but never leave checkout with
  // zero payment options — if no online method is configured either, keep Cash.
  var codEnabled = (window.codEnabled == 1 || window.codEnabled === true || window.codEnabled === undefined);
  var hasOnlinePayment = !!window.phonepeConfigured || !!window.businessQrAvailable;
  // Wallet (Offers > Wallet): logged-in customers with money in it
  var cw = window.cartWallet;
  if (cw && cw.balance > 0) {
    var wPays = Math.min(cw.balance, total);
    html += '<label class="chk-wallet"><input type="checkbox" id="chkUseWallet" checked>' +
      '<span class="cw-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h14a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg></span>' +
      '<span class="cw-txt"><b>Pay from ' + escapeHtml(cw.name) + '</b><small>' + getCurrency() + cw.balance.toFixed(2) + ' available' +
      (cw.balance >= total ? ' \u00b7 covers this order' : ' \u00b7 pays ' + getCurrency() + wPays.toFixed(2) + ', the rest below') + '</small></span></label>';
  }
  html += '<div class="form-group"><label>Payment Method</label>';
  html += '<select id="chkPayment">';
  if (codEnabled || !hasOnlinePayment) {
    html += '<option value="Cash">' + cashLabel + '</option>';
  }
  if (window.phonepeConfigured) {
    html += '<option value="UPI / NetBanking">UPI / NetBanking</option>';
  }
  if (window.businessQrAvailable) {
    html += '<option value="QR Payment">Pay Online (Scan QR)</option>';
  }
  html += '</select></div>';

  if (window.businessQrAvailable) {
    html += '<div class="form-group" id="payOnlineQrSection" style="display:none;border:1px solid #e0e0e0;border-radius:10px;padding:14px;background:#fafafa;">';
    html += '<div style="text-align:center;">';
    html += '<img src="' + window.businessQrImageUrl + '" alt="Payment QR" style="width:180px;height:180px;object-fit:contain;border:1px solid #ddd;border-radius:8px;background:#fff;">';
    html += '<div style="margin-top:8px;"><a href="' + window.businessQrImageUrl + '" download="payment-qr.png" style="color:var(--primary-red, #e53935);font-weight:600;font-size:13px;text-decoration:none;">⬇ Download QR</a></div>';
    html += '</div>';
    html += '<label style="display:block;margin:14px 0 6px;font-weight:500;color:#333;font-size:13px">Upload payment screenshot</label>';
    html += '<input type="file" id="paymentProofInput" accept="image/*" style="width:100%;box-sizing:border-box;font-size:13px;">';
    html += '<div id="paymentProofPreviewWrap" style="display:none;margin-top:8px;text-align:center;">';
    html += '<img id="paymentProofPreview" style="max-width:140px;max-height:140px;border-radius:8px;border:1px solid #ddd;">';
    html += '</div>';
    html += '<div id="paymentProofError" style="display:none;color:#c0392b;font-size:12px;margin-top:6px;"></div>';
    html += '<div style="color:#777;font-size:12px;margin-top:6px;">Scan the QR, pay, then upload a screenshot of the successful payment. The restaurant will confirm it before your order is marked as paid.</div>';
    html += '</div>';
  }

  html += '<div class="btn-group">';
  html += '<button type="button" class="btn btn-secondary" onclick="this.closest(\'.modal-overlay\').remove()">Cancel</button>';
  html += '<button type="submit" class="btn btn-primary">Place Order</button>';
  html += '</div>';
  html += '</form>';
  html += '</div></div>';

  modal.innerHTML = html;
  modal.dataset.baseTotal = cartData.finalTotal;
  document.body.appendChild(modal);

  // Reset any payment-proof screenshot from a previous checkout attempt
  window.__paymentProofBase64 = '';

  // Pay Online (QR) — reveal the QR + screenshot upload panel only while selected
  var chkPaymentEl = document.getElementById('chkPayment');
  var payOnlineSection = document.getElementById('payOnlineQrSection');
  if (chkPaymentEl && payOnlineSection) {
    chkPaymentEl.addEventListener('change', function() {
      payOnlineSection.style.display = (chkPaymentEl.value === 'QR Payment') ? '' : 'none';
    });
  }

  var proofInput = document.getElementById('paymentProofInput');
  if (proofInput) {
    proofInput.addEventListener('change', function() {
      var errEl = document.getElementById('paymentProofError');
      var previewWrap = document.getElementById('paymentProofPreviewWrap');
      var previewImg = document.getElementById('paymentProofPreview');
      if (errEl) errEl.style.display = 'none';
      window.__paymentProofBase64 = '';
      if (previewWrap) previewWrap.style.display = 'none';

      var file = proofInput.files && proofInput.files[0];
      if (!file) return;

      var allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
      if (allowedTypes.indexOf(file.type) === -1) {
        if (errEl) { errEl.textContent = 'Please upload a JPG, PNG, WEBP, or GIF image.'; errEl.style.display = ''; }
        proofInput.value = '';
        return;
      }
      if (file.size > 5 * 1024 * 1024) {
        if (errEl) { errEl.textContent = 'Screenshot must be under 5MB.'; errEl.style.display = ''; }
        proofInput.value = '';
        return;
      }

      var reader = new FileReader();
      reader.onload = function(e) {
        window.__paymentProofBase64 = e.target.result;
        if (previewImg) previewImg.src = e.target.result;
        if (previewWrap) previewWrap.style.display = '';
      };
      reader.onerror = function() {
        if (errEl) { errEl.textContent = 'Could not read that file, please try another.'; errEl.style.display = ''; }
      };
      reader.readAsDataURL(file);
    });
  }

  // Init Google Places address autocomplete after modal is in DOM
  setTimeout(initGeoAutocomplete, 200);

  // A saved address skips the picker UI entirely, but the KM-based delivery
  // charge/total still needs computing from those coordinates the same way
  // picking it fresh would - otherwise the on-screen total would look wrong
  // (no delivery fee shown) even though the server bills it correctly.
  if (hasSavedAddress && cd.addressLat && cd.addressLng) {
    applySelectedAddress(cd.addressLat, cd.addressLng, cd.address, '');
  }

  // Order type click handler
  var otLabels = modal.querySelectorAll('.order-type-option');
  for (var oi = 0; oi < otLabels.length; oi++) {
    otLabels[oi].addEventListener('click', function() {
      var all = document.querySelectorAll('.order-type-option');
      for (var j = 0; j < all.length; j++) {
        all[j].style.borderColor = '#e0e0e0';
        all[j].style.background = '#fff';
      }
      this.style.borderColor = 'var(--primary-red, #e53935)';
      this.style.background = 'color-mix(in srgb, var(--primary-red, #e53935) 7%, #fff)';
      var radio = this.querySelector('input[name="orderType"]');
      if (radio) radio.checked = true;
      var ot = this.getAttribute('data-ot');
      var tableSec = document.getElementById('dineinTableSection');
      if (tableSec) {
        tableSec.style.display = (ot === 'dinein' && !qrTable) ? '' : 'none';
        if (ot === 'dinein') {
          loadTablesForCheckout();
        }
      }
      // Show/hide address for dine-in and takeaway
      var addrSec = document.getElementById('addressSection');
      if (addrSec) {
        addrSec.style.display = (ot === 'dinein' || ot === 'takeaway') ? 'none' : '';
      }
      // Show/hide pincode section for delivery
      var pincodeSec = document.getElementById('deliveryPincodeSection');
      if (pincodeSec) {
        var isDelivery = ot === 'delivery' && (window.enableDelivery == 1 || window.enableDelivery === true);
        pincodeSec.style.display = isDelivery ? '' : 'none';
        if (!isDelivery) {
          checkoutDeliveryCharge = 0;
          var zoneIdEl = document.getElementById('deliveryZoneId');
          if (zoneIdEl) zoneIdEl.value = '';
          var chargeEl = document.getElementById('deliveryCharge');
          if (chargeEl) chargeEl.value = '0';
          var statusEl = document.getElementById('pincodeStatus');
          if (statusEl) statusEl.style.display = 'none';
          var infoEl = document.getElementById('deliveryInfo');
          if (infoEl) infoEl.style.display = 'none';
        }
      }
      // Update payment label based on order type
      var payOpt = document.querySelector('#chkPayment option[value="Cash"]');
      if (payOpt) {
        payOpt.textContent = (ot === 'takeaway' || ot === 'dinein') ? 'Pay at Counter' : 'Cash on Delivery';
      }
      refreshCheckoutTotal();
    });
  }

  // Schedule for Later toggle
  var scheduledAtInput = document.getElementById('chkScheduledAt');
  var scheduleHint = document.getElementById('scheduleHint');
  if (scheduledAtInput) {
    // Earliest selectable slot: 15 minutes from now (matches server-side
    // minimum in process_website_order.php), rounded up to the next 5 min.
    var minDt = new Date(Date.now() + 15 * 60000);
    minDt.setMinutes(minDt.getMinutes() + (5 - (minDt.getMinutes() % 5 || 5)));
    var maxDt = new Date(Date.now() + 7 * 24 * 60 * 60000);
    var toLocalInputValue = function(d) {
      var pad = function(n) { return String(n).padStart(2, '0'); };
      return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    };
    scheduledAtInput.min = toLocalInputValue(minDt);
    scheduledAtInput.max = toLocalInputValue(maxDt);
  }
  var schedLabels = modal.querySelectorAll('.schedule-option');
  for (var si = 0; si < schedLabels.length; si++) {
    schedLabels[si].addEventListener('click', function() {
      var all = modal.querySelectorAll('.schedule-option');
      for (var j = 0; j < all.length; j++) {
        all[j].style.borderColor = '#e0e0e0';
        all[j].style.background = '#fff';
      }
      this.style.borderColor = 'var(--primary-red, #e53935)';
      this.style.background = 'color-mix(in srgb, var(--primary-red, #e53935) 7%, #fff)';
      var radio = this.querySelector('input[name="scheduleType"]');
      if (radio) radio.checked = true;
      var later = this.getAttribute('data-sched') === 'later';
      if (scheduledAtInput) scheduledAtInput.style.display = later ? '' : 'none';
      if (scheduleHint) scheduleHint.style.display = later ? '' : 'none';
    });
  }

  // Set initial pincode and address section visibility
  var savedOt = savedOrderType || 'delivery';
  var initAddrSec = document.getElementById('addressSection');
  if (initAddrSec) {
    initAddrSec.style.display = (savedOt === 'dinein') ? 'none' : '';
  }
  var initPincodeSec = document.getElementById('deliveryPincodeSection');
  if (initPincodeSec) {
    var initIsDelivery = savedOt === 'delivery' && (window.enableDelivery == 1 || window.enableDelivery === true);
    initPincodeSec.style.display = initIsDelivery ? '' : 'none';
  }
  refreshCheckoutTotal();

  document.getElementById('checkoutForm').addEventListener('submit', function(e) {
    e.preventDefault();
    processOrder(cartData);
  });

  // If Dine In is selected, pre-load tables
  if (savedOrderType === 'dinein') {
    loadTablesForCheckout();
  }
}

function loadTablesForCheckout() {
  var rid = document.querySelector('meta[name=restaurant-id]')?.content || window.websiteRestaurantId || '';
  if (!rid) return;
  var sel = document.getElementById('chkTableId');
  if (!sel) return;
  if (sel.options.length > 1) return;
  sel.innerHTML = '<option value="">Loading tables...</option>';
  fetch('../api/get_tables.php?restaurant_id=' + encodeURIComponent(rid))
    .then(function(r) { return r.json(); })
    .then(function(data) {
      var tables = data && data.data ? data.data : (data && data.tables ? data.tables : []);
      sel.innerHTML = '<option value="">Select a table...</option>';
      var autoTable = sessionStorage.getItem('qrTable') || '';
      for (var i = 0; i < tables.length; i++) {
        var t = tables[i];
        var selected = (autoTable && String(t.table_number) === String(autoTable)) ? ' selected' : '';
        sel.innerHTML += '<option value="' + t.id + '"' + selected + '>' + (t.area_name ? t.area_name + ' - ' : '') + 'Table ' + t.table_number + (t.capacity ? ' (Cap: ' + t.capacity + ')' : '') + '</option>';
      }
      // If auto-selected, also make sure Dine-in option is active
      if (autoTable) {
        var dineinLabel = document.querySelector('.order-type-option[data-ot="dinein"]');
        if (dineinLabel) dineinLabel.click();
      }
    })
    .catch(function() {
      sel.innerHTML = '<option value="">Failed to load tables</option>';
    });
}

function processOrder(cartData) {
  var total = cartData.finalTotal;
  var keys = cartData.keys;
  var name = document.getElementById('chkName').value.trim();
  var phone = document.getElementById('chkPhone').value.trim();
  try { var waBox = document.getElementById('chkWaOptin'); if (waBox) localStorage.setItem('rgWaOptin', waBox.checked ? '1' : '0'); } catch (e) {}
  var email = document.getElementById('chkEmail').value.trim();
  var addressEl = document.getElementById('chkAddressFormatted');
  var address = addressEl ? addressEl.value.trim() : '';
  var payment = document.getElementById('chkPayment').value;
  var orderTypeRadio = document.querySelector('input[name="orderType"]:checked');
  var orderType = orderTypeRadio ? orderTypeRadio.value : null;
  // If ordering from a table QR code, always use Dine-in (handle any edge cases)
  var qrTableCheck = sessionStorage.getItem('qrTable') || '';
  if (qrTableCheck) {
    orderType = 'Dine-in';
  }
  if (!orderType) orderType = 'Takeaway';
  var tableId = orderType === 'Dine-in' ? document.getElementById('chkTableId')?.value || '' : '';
  if (tableId === '') tableId = null;
  // For QR orders, also try to get table ID from sessionStorage if select hasn't loaded yet
  if (!tableId && qrTableCheck) {
    // Try to find the table ID from the select options by matching exact table number
    var sel = document.getElementById('chkTableId');
    if (sel) {
      for (var i = 0; i < sel.options.length; i++) {
        // Exact match: extract the number after 'Table ' to avoid substring matches
        var txt = sel.options[i].text;
        var idx = txt.indexOf('Table ');
        if (idx >= 0) {
          var tableNum = txt.substring(idx + 6).split(/[\s(]/)[0];
          if (tableNum === qrTableCheck) {
            tableId = sel.options[i].value;
            break;
          }
        }
      }
    }
  }

  var isDineInEnabled = window.enableDinein == 1 || window.enableDinein === true;
  if (orderType === 'Dine-in' && isDineInEnabled && !tableId) {
    showModal('Error', 'Please select a table');
    var btn = document.querySelector('#checkoutForm button[type=submit]');
    if (btn) { btn.textContent = 'Place Order'; btn.disabled = false; }
    return;
  }
  if (!name || !phone) { showModal('Error', 'Name and phone are required'); return; }
  var phoneMinLen = window.restaurantPhoneMin || 10;
  var phoneMaxLen = window.restaurantPhoneMax || 10;
  if (phone.length < phoneMinLen || phone.length > phoneMaxLen || !/^\d+$/.test(phone)) {
    var lenMsg = phoneMinLen === phoneMaxLen ? (phoneMinLen + '-digit') : (phoneMinLen + '-' + phoneMaxLen + ' digit');
    showModal('Error', 'Please enter a valid ' + lenMsg + ' phone number');
    return;
  }

  if (payment === 'QR Payment' && !window.__paymentProofBase64) {
    showModal('Payment Proof Required', 'Please upload a screenshot of your payment before placing the order.');
    var btn = document.querySelector('#checkoutForm button[type=submit]');
    if (btn) { btn.textContent = 'Place Order'; btn.disabled = false; }
    return;
  }

  var isDeliveryEnabled = window.enableDelivery == 1 || window.enableDelivery === true;
  if (orderType === 'Delivery' && isDeliveryEnabled) {
    // TEMPORARY: pincode/zone matching disabled - just require a saved address.
    // Re-enable the zoneId requirement once Google Maps based radius checking is wired up.
    if (!address) {
      showModal('Delivery Error', 'Please enter your delivery address');
      var btn = document.querySelector('#checkoutForm button[type=submit]');
      if (btn) { btn.textContent = 'Place Order'; btn.disabled = false; }
      return;
    }
  }

  if (MIN_ORDER > 0 && cartData.totalPrice < MIN_ORDER && !qrTableCheck) {
    showModal('Minimum Order', getCurrency() + MIN_ORDER.toFixed(2) + ' minimum. Please add more items.');
    var btn = document.querySelector('#checkoutForm button[type=submit]');
    if (btn) { btn.textContent = 'Place Order'; btn.disabled = false; }
    return;
  }

  var scheduleTypeRadio = document.querySelector('input[name="scheduleType"]:checked');
  var scheduledAtValue = '';
  if (scheduleTypeRadio && scheduleTypeRadio.value === 'later') {
    var scheduledAtRaw = document.getElementById('chkScheduledAt')?.value || '';
    if (!scheduledAtRaw) {
      showModal('Error', 'Please choose a date and time to schedule your order for.');
      return;
    }
    // datetime-local gives "YYYY-MM-DDTHH:MM" in the browser's local time —
    // the server re-validates this against the restaurant's own timezone.
    scheduledAtValue = scheduledAtRaw.replace('T', ' ') + ':00';
  }

  // Save to localStorage - including lat/lng + landmark, not just the display
  // text, so next checkout can skip straight to the address summary instead
  // of asking again.
  saveCustomer({
    name: name, phone: phone, email: email, address: address,
    addressLat: document.getElementById('chkAddressLat')?.value || '',
    addressLng: document.getElementById('chkAddressLng')?.value || '',
    landmark: document.getElementById('chkLandmark')?.value.trim() || ''
  });

  var items = [];
  var globalAddons = [];
  var combos = [];   // Offers > Combos & Deals: id, how many and the choices; the server sets the price
  for (var i = 0; i < keys.length; i++) {
    var key = keys[i];
    var raw = cartItems[key];
    if (raw && typeof raw === 'object' && raw.isCombo) {
      if (raw.comboId && raw.qty > 0) combos.push({ id: raw.comboId, quantity: raw.qty, picks: raw.picks || {} });
      continue;
    }
    // Handle standalone add-on items — collect into separate global_addons array
    if (raw && typeof raw === 'object' && raw.isAddon) {
      var addonId = parseInt(key.replace('addon_', ''));
      if (addonId > 0) {
        globalAddons.push({
          id: addonId,
          name: raw.addonName || 'Add-on',
          quantity: raw.qty || 0,
          price: parseFloat(raw.addonPrice || 0)
        });
      }
      continue;
    }
    var item = getItemByKey(key);
    if (item) {
      var itemName = item.item_name_translated || item.item_name_en || item.name;
      var variationName = '';
      if (raw && typeof raw === 'object' && raw.varName) {
        variationName = raw.varName;
        itemName += ' (' + raw.varName + ')';
      }
      items.push({
        id: item.id,
        name: itemName,
        variation_name: variationName,
        quantity: getCartQty(key),
        price: parseFloat(getCartPrice(key)),
        addons: getAddonsForItemKey(key),
        notes: getItemNote(key)
      });
      // Include add-on price in total
      var addonTotal = getAddonTotalForItem(key);
      if (addonTotal > 0) {
        items[items.length-1].price = parseFloat(getCartPrice(key)) + addonTotal;
      }
    }
  }

  // Get delivery zone data
  var deliveryZoneId = document.getElementById('deliveryZoneId')?.value || '';
  var deliveryCharge = parseFloat(document.getElementById('deliveryCharge')?.value || '0');
  if (orderType !== 'Delivery') { deliveryZoneId = ''; deliveryCharge = 0; }

  var btn = document.querySelector('#checkoutForm button[type=submit]');
  btn.textContent = 'Placing...';
  btn.disabled = true;

  var rid = document.querySelector('meta[name=restaurant-id]')?.content || 'RES001';

  fetchJsonWithRetry('../api/process_website_order.php?restaurant_id=' + encodeURIComponent(rid), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      customer_name: name,
      customer_phone: phone,
      whatsapp_optin: (document.getElementById('chkWaOptin') || {}).checked ? 1 : 0,
      customer_email: email,
      customer_address: address,
      landmark: document.getElementById('chkLandmark')?.value.trim() || '',
      address_lat: document.getElementById('chkAddressLat')?.value || '',
      address_lng: document.getElementById('chkAddressLng')?.value || '',
      address_formatted: document.getElementById('chkAddressFormatted')?.value || '',
      notes: savedInstruction || '',
      order_type: orderType,
      table_id: tableId,
      items: items,
      combos: combos,
      global_addons: globalAddons.length > 0 ? globalAddons : [],
      total: cartData.totalPrice,
      total_payable: total,
      payment_method: payment,
      payment_proof_base64: (payment === 'QR Payment' && window.__paymentProofBase64) ? window.__paymentProofBase64 : '',
      coupon_code: appliedCoupon ? appliedCoupon.code : '',
      campaign_src: campaignSource(),
      discount_amount: cartData.discountAmount || 0,
      redeem_points: cartData.loyaltyPointsRedeemed || 0,
      use_wallet: !!(document.getElementById('chkUseWallet') || {}).checked,
      delivery_zone_id: deliveryZoneId,
      delivery_charge: deliveryCharge,
      packaging_charge: PACKAGING_CHARGE,
      scheduled_at: scheduledAtValue
    })
  })
  .then(function(data) {
    if (data.success) {
      // Save WhatsApp info for redirect after success
      var waInfo = data.whatsapp_enabled && data.whatsapp_phone ? {
        phone: data.whatsapp_phone,
        number: data.order_number,
        name: name,
        customerPhone: phone,
        type: orderType,
        total: total,
        items: items
      } : null;
      if (waInfo) {
        sessionStorage.setItem('whatsapp_order_info', JSON.stringify(waInfo));
      }

      // Cashfree removed — PhonePe is the only online payment
      if (data.phonepe_required) {
        ppRemember(data.order_id, phone, data.order_number);
        var cm = document.getElementById('checkoutModal');
        if (cm) cm.remove();
        initiatePhonePePayment(data.order_id, phone);
        return;
      }
      clearPlacedOrder();
      var modal = document.getElementById('checkoutModal');
      if (modal) modal.remove();
      showSuccessModal(data.order_number || '', data.is_scheduled ? data.scheduled_at : null);
      // WhatsApp redirect after cash order success
      if (waInfo) {
        setTimeout(function() { redirectToWhatsApp(waInfo); }, 500);
      }
    } else {
      showModal('Error', data.message || 'Failed to place order');
      btn.textContent = 'Place Order';
      btn.disabled = false;
    }
  })
  .catch(function(err) {
    btn.textContent = 'Place Order';
    btn.disabled = false;
    showModal('Connection Problem', 'We could not reach the server after a few tries. Your order was NOT placed — please check your connection and retry.', function() {
      var form = document.getElementById('checkoutForm');
      if (form && typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else if (form) {
        form.dispatchEvent(new Event('submit', { cancelable: true }));
      }
    });
  });
}

// Cashfree removed — PhonePe is the only online payment

function initiatePhonePePayment(orderId, customerPhone) {
  var loading = document.createElement('div');
  loading.id = 'phonepeLoader';
  loading.className = 'modal-overlay';
  loading.innerHTML = '<div class="alert-box" style="text-align:center"><h3>Opening PhonePe…</h3><p style="margin-top:10px">Please wait a moment.</p><div style="margin-top:15px;font-size:24px">&#8987;</div></div>';
  document.body.appendChild(loading);

  // remembered for 30 minutes (cookies survive PhonePe's redirect back; sessionStorage may not)
  ppRemember(orderId, customerPhone, null);

  fetchJsonWithRetry('../api/phonepe_order_payment.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ order_id: orderId, customer_phone: customerPhone })
  })
  .then(function(data) {
    var loader = document.getElementById('phonepeLoader');
    if (loader) loader.remove();
    if (data.success && data.paid) { handlePaymentSuccess(ppGet('pp_order_number') || ''); return; }
    if (data.success && data.payment_url) { window.location.href = data.payment_url; return; }
    ppShowChoices(ppGet('pp_order_number') || '', 'Could not open PhonePe', data.message || 'Please try again, or choose another way to pay.', { can_retry: true, can_cash: true });
  })
  .catch(function() {
    var loader = document.getElementById('phonepeLoader');
    if (loader) loader.remove();
    // the order is saved (waiting for payment); only opening PhonePe failed
    ppShowChoices(ppGet('pp_order_number') || '', 'Connection problem', 'We could not open PhonePe. Your order is saved: try again, or choose another way to pay.', { can_retry: true, can_cash: true });
  });
}

/* ── PhonePe return: remember the order, check it, offer Pay again / Pay cash / Cancel ── */
function ppRemember(orderId, phone, orderNumber) {
  if (orderId) { sessionStorage.setItem('phonepe_pending', orderId); setCookie('pp_pending', orderId, 1800); }
  if (phone) { sessionStorage.setItem('phonepe_phone', phone); setCookie('pp_phone', phone, 1800); }
  if (orderNumber) { sessionStorage.setItem('phonepe_order_number', orderNumber); setCookie('pp_order_number', orderNumber, 1800); }
}
function ppGet(name) {
  var map = { pp_pending: 'phonepe_pending', pp_phone: 'phonepe_phone', pp_order_number: 'phonepe_order_number' };
  return sessionStorage.getItem(map[name]) || getCookie(name);
}
function ppForget() {
  ['phonepe_pending', 'phonepe_phone', 'phonepe_order_number'].forEach(function(k) { sessionStorage.removeItem(k); });
  ['pp_pending', 'pp_phone', 'pp_order_number'].forEach(deleteCookie);
}
function ppCleanUrl() {
  var url = new URL(window.location.href);
  ['order_id', 'transaction_id', 'transactionId', 'merchantTransactionId', 'code', 'amount', 'providerReferenceId', 'phonepe_demo'].forEach(function(p) { url.searchParams.delete(p); });
  window.history.replaceState({}, '', url.toString());
}
function ppStatusUrl(orderNumber) {
  return '../api/phonepe_order_payment.php?action=status&order_id=' + encodeURIComponent(orderNumber) + '&restaurant_id=' + encodeURIComponent(window.websiteRestaurantId || '');
}
function ppPost(action, extra) {
  return fetchJsonWithRetry('../api/phonepe_order_payment.php?action=' + action, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.assign({ order_id: ppGet('pp_pending'), customer_phone: ppGet('pp_phone') }, extra || {}))
  });
}
/** A clear choice when the payment did not go through (or is taking long). */
function ppShowChoices(orderNumber, title, msg, s) {
  var old = document.getElementById('ppChoice'); if (old) old.remove();
  var loader = document.getElementById('ppVerifyLoader'); if (loader) loader.remove();
  var canAct = !!(ppGet('pp_pending') && ppGet('pp_phone'));
  var m = document.createElement('div');
  m.id = 'ppChoice';
  m.className = 'modal-overlay';
  var btn = 'display:block;width:100%;margin-top:10px;padding:12px 14px;border-radius:10px;font-weight:700;font-size:0.95rem;cursor:pointer;';
  m.innerHTML = '<div class="alert-box" style="text-align:center;max-width:380px"><h3>' + esc(title) + '</h3><p style="margin-top:10px">' + esc(msg) + '</p>' +
    (canAct && s.can_retry ? '<button type="button" id="ppRetry" style="' + btn + 'background:#5f259f;color:#fff;border:0">Pay again with PhonePe</button>' : '') +
    (canAct && s.can_cash ? '<button type="button" id="ppCash" style="' + btn + 'background:#fff;color:#111;border:1px solid #d1d5db">Pay cash instead</button>' : '') +
    (canAct && s.can_retry ? '<button type="button" id="ppCancel" style="' + btn + 'background:none;color:#b91c1c;border:0;font-weight:600">Cancel this order</button>' : '') +
    '<button type="button" id="ppClose" style="' + btn + 'background:none;color:#6b7280;border:0;font-weight:600">Close</button></div>';
  document.body.appendChild(m);
  var q = function(id) { return document.getElementById(id); };
  if (q('ppRetry')) q('ppRetry').onclick = function() { m.remove(); initiatePhonePePayment(ppGet('pp_pending'), ppGet('pp_phone')); };
  if (q('ppCash')) q('ppCash').onclick = function() {
    this.disabled = true;
    ppPost('cash').then(function(d) {
      m.remove();
      if (d.success && d.paid) { handlePaymentSuccess(orderNumber); return; }
      if (!d.success) { showModal('Could not change', d.message || 'Please try again.'); return; }
      ppForget(); ppCleanUrl(); clearPlacedOrder();
      showSuccessModal(d.order_number || orderNumber);
    }).catch(function() { m.remove(); showModal('Connection problem', 'Please try again.'); });
  };
  if (q('ppCancel')) q('ppCancel').onclick = function() {
    this.disabled = true;
    ppPost('cancel').then(function(d) {
      m.remove();
      if (d.success && d.paid) { handlePaymentSuccess(orderNumber); return; }
      ppForget(); ppCleanUrl();
      showModal('Order cancelled', 'Nothing was charged. Your cart is still here if you want to order again.');
    }).catch(function() { m.remove(); showModal('Connection problem', 'Please try again.'); });
  };
  q('ppClose').onclick = function() { m.remove(); ppCleanUrl(); };
}

// The order is placed: empty the cart, and drop its notes, add-ons and coupon so
// they don't ride along unseen on the customer's next order.
function clearPlacedOrder() {
  cartItems = {};
  saveCart();
  savedInstruction = '';
  appliedCoupon = null;
  try { ['dvaniInstruction', '_itemNotes', '_itemAddons'].forEach(function(k) { localStorage.removeItem(k); }); } catch(e) {}
  renderCart();
}

function handlePaymentSuccess(orderNumber) {
  var loader = document.getElementById('ppVerifyLoader');
  if (loader) loader.remove();
  var ch = document.getElementById('ppChoice');
  if (ch) ch.remove();
  window._ppVerifyCancelled = true;
  ppForget();
  clearPlacedOrder();
  showSuccessModal(orderNumber);
  var url = new URL(window.location.href);
  url.searchParams.delete('order_id');
  window.history.replaceState({}, '', url.toString());
  var waInfoStr = sessionStorage.getItem('whatsapp_order_info');
  if (waInfoStr) {
    try {
      var waInfo = JSON.parse(waInfoStr);
      if (waInfo && waInfo.phone) {
        waInfo.number = orderNumber;
        setTimeout(function() { redirectToWhatsApp(waInfo); }, 500);
      }
    } catch(e) {}
    sessionStorage.removeItem('whatsapp_order_info');
  }
}

function checkPhonePeReturn() {
  var params = new URLSearchParams(window.location.search);
  var orderNumber = params.get('order_id');
  var hasPending = ppGet('pp_pending');
  // order_id comes back in PhonePe's redirect; after Back the remembered order is used
  if (!orderNumber) {
    orderNumber = ppGet('pp_order_number');
    if (!orderNumber || !hasPending) return;
  }
  if (document.getElementById('ppVerifyLoader') || document.getElementById('ppChoice')) return;

  // local computer without PhonePe keys: nothing is charged, the order stays unpaid (the server never trusts this)
  if (params.get('phonepe_demo') === '1') {
    ppCleanUrl();
    ppShowChoices(orderNumber, 'Test payment (this computer)', 'PhonePe is not connected on this computer, so nothing was charged and the order is still waiting for payment.', { can_retry: false, can_cash: true });
    return;
  }

  window._ppVerifyCancelled = false;
  window._ppStartedAt = Date.now();
  var loading = document.createElement('div');
  loading.id = 'ppVerifyLoader';
  loading.className = 'modal-overlay';
  loading.innerHTML = '<div class="alert-box" style="text-align:center"><h3>Checking your payment…</h3><p style="margin-top:10px">This usually takes a few seconds.</p><div style="margin-top:15px;font-size:24px">&#8987;</div>' +
    '<button type="button" onclick="cancelPhonePeVerification(\'' + String(orderNumber).replace(/[^A-Za-z0-9_-]/g, '') + '\')" style="margin-top:18px;background:none;border:1px solid #ccc;border-radius:6px;padding:8px 16px;color:#666;cursor:pointer;font-size:0.9rem;">I didn\'t pay / go back</button></div>';
  document.body.appendChild(loading);
  pollPaymentStatus(orderNumber, 12, 3000);
}

// Fast at first (UPI usually confirms in seconds), then slower. The server asks PhonePe each time, and a
// background check also settles it, so closing the page never loses a payment. Unpaid orders are cancelled
// automatically after 30 minutes (coupon, points and wallet money go back).
function pollPaymentStatus(orderNumber, retries, intervalMs) {
  if (window._ppVerifyCancelled) return;
  fetch(ppStatusUrl(orderNumber))
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (window._ppVerifyCancelled) return;
      if (data.success && data.payment_status === 'Success') { handlePaymentSuccess(orderNumber); return; }
      if (data.success && data.payment_status === 'Cash') { ppForget(); ppCleanUrl(); clearPlacedOrder(); var l = document.getElementById('ppVerifyLoader'); if (l) l.remove(); showSuccessModal(orderNumber); return; }
      if (data.success && data.payment_status === 'Failed') {
        if (data.cancelled) { ppForget(); ppCleanUrl(); var l2 = document.getElementById('ppVerifyLoader'); if (l2) l2.remove(); showModal('Order cancelled', 'This order was cancelled because the payment was not completed. Nothing was charged. Please place it again.'); return; }
        ppShowChoices(orderNumber, 'Payment didn\'t go through', 'No money was taken. You can try again or pick another way to pay.', data);
        return;
      }
      // Still pending after a few seconds: PhonePe keeps saying "pending" until its page expires even when the
      // customer came back without paying (or cancelled), so offer the choices now and keep checking behind
      // them. A payment that does arrive replaces them with "Order placed" (handlePaymentSuccess).
      if (data.success && !document.getElementById('ppChoice') && Date.now() - (window._ppStartedAt || 0) > 6000) {
        ppShowChoices(orderNumber, 'Payment not completed yet', 'Nothing has been charged. If you have just paid, stay on this page: it confirms by itself. Otherwise choose an option.', data);
      }
      if (retries > 0) { setTimeout(function() { pollPaymentStatus(orderNumber, retries - 1, intervalMs); }, intervalMs); return; }
      if (intervalMs < 10000) {
        var msgEl = document.querySelector('#ppVerifyLoader p');
        if (msgEl) msgEl.textContent = 'Still waiting for PhonePe. If you paid, keep this page open: it confirms by itself.';
        setTimeout(function() { pollPaymentStatus(orderNumber, 24, 10000); }, 10000);
        return;
      }
      if (!document.getElementById('ppChoice')) ppShowChoices(orderNumber, 'Payment not confirmed yet', 'If money was taken, your order confirms by itself within a few minutes and you get a message. If you did not pay, choose an option below.', data.success ? data : { can_retry: true, can_cash: false });
    })
    .catch(function() {
      if (!window._ppVerifyCancelled) setTimeout(function() { pollPaymentStatus(orderNumber, Math.max(0, retries - 1), intervalMs); }, intervalMs);
    });
}

// "I didn't pay / go back": stop waiting and show the choices right away (one last check first).
function cancelPhonePeVerification(orderNumber) {
  window._ppVerifyCancelled = true;
  fetch(ppStatusUrl(orderNumber)).then(function(r) { return r.json(); }).then(function(d) {
    if (d.success && d.payment_status === 'Success') { handlePaymentSuccess(orderNumber); return; }
    ppShowChoices(orderNumber, 'Payment not completed', 'No problem: nothing was charged. What would you like to do?', d.success ? d : { can_retry: true, can_cash: false });
  }).catch(function() { ppShowChoices(orderNumber, 'Payment not completed', 'What would you like to do?', { can_retry: true, can_cash: false }); });
}

// Back from PhonePe can restore this page from the browser's cache without reloading it: check again.
window.addEventListener('pageshow', function(e) { if (e.persisted) checkPhonePeReturn(); });


function showSuccessModal(orderNum, scheduledAt) {
  var existing = document.getElementById('successModal');
  if (existing) existing.remove();

  var modal = document.createElement('div');
  modal.id = 'successModal';
  modal.className = 'modal-overlay';

  var trackUrl = '';
  if (orderNum) {
    var storedPhone = '';
    try { var cd = JSON.parse(localStorage.getItem('customerDetails') || '{}'); storedPhone = cd.phone || ''; } catch (e) {}
    if (storedPhone) {
      trackUrl = <?php echo json_encode(restaurantPageUrl('track'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> + '?order_number=' + encodeURIComponent(orderNum) + '&customer_phone=' + encodeURIComponent(storedPhone);
    }
  }

  var scheduledText = '';
  if (scheduledAt) {
    var sd = new Date(String(scheduledAt).replace(' ', 'T'));
    scheduledText = '<p style="font-size:13px;color:#5b21b6;background:#ede9fe;padding:8px 12px;border-radius:8px;margin-bottom:10px;">\u{1F4C5} Scheduled for ' + sd.toLocaleString() + '</p>';
  }

  modal.innerHTML = '<div class="success-box">' +
    '<div class="check-icon">&#10003;</div>' +
    '<h2>' + (scheduledAt ? 'Order Scheduled!' : 'Order Placed!') + '</h2>' +
    '<p>' + (scheduledAt ? 'Your order has been scheduled and will be sent to the kitchen at the chosen time.' : 'Your order has been placed successfully.') + '</p>' +
    (orderNum ? '<p class="order-num">Order #' + orderNum + '</p>' : '<p class="order-num">&nbsp;</p>') +
    scheduledText +
    '<p style="font-size:12px;color:#6b7280;margin-bottom:12px;">After your order is delivered, rate your experience in your profile!</p>' +
    (trackUrl ? '<button class="btn-profile" style="margin-bottom:8px;" onclick="window.location.href=\'' + trackUrl + '\'">Track Order</button>' : '') +
    '<button class="btn-profile" onclick="goToProfile()">View My Profile</button>' +
  '</div>';

  document.body.appendChild(modal);
}

function redirectToWhatsApp(info) {
  if (!info || !info.phone) return;
  var itemsSummary = '';
  if (info.items && info.items.length > 0) {
    for (var i = 0; i < info.items.length; i++) {
      itemsSummary += info.items[i].name + ' x' + info.items[i].quantity + (info.items[i].notes ? ' (📝 ' + info.items[i].notes + ')' : '') + ', ';
    }
    if (itemsSummary) itemsSummary = itemsSummary.slice(0, -2);
  }
  var msg = 'New Order Received! 🎉\n\n' +
    'Order #' + info.number + '\n' +
    'Customer: ' + info.name + '\n' +
    'Phone: ' + info.customerPhone + '\n' +
    'Type: ' + info.type + '\n' +
    (itemsSummary ? 'Items: ' + itemsSummary + '\n' : '') +
    'Total: ' + getCurrency() + parseFloat(info.total).toFixed(2) + '\n\n' +
    'Check admin panel for full details.';
  window.open('https://wa.me/' + info.phone + '?text=' + encodeURIComponent(msg), '_blank');
}

function goToProfile() {
  window.location.href = '<?php echo restaurantPageUrl('profile'); ?>';
}

function showModal(title, msg, onRetry) {
  var existing = document.getElementById('alertModal');
  if (existing) existing.remove();

  var modal = document.createElement('div');
  modal.id = 'alertModal';
  modal.className = 'modal-overlay';
  modal.onclick = function(e) { if (e.target === this) this.remove(); };

  var retryBtnHtml = '';
  if (typeof onRetry === 'function') {
    retryBtnHtml = '<button class="btn-ok" id="alertModalRetryBtn" style="margin-right:8px;">Retry</button>';
  }

  modal.innerHTML = '<div class="alert-box">' +
    '<h3>' + esc(title) + '</h3>' +
    '<p>' + esc(msg) + '</p>' +
    '<div style="display:flex;justify-content:center;margin-top:10px;">' +
    retryBtnHtml +
    '<button class="btn-ok" onclick="this.closest(\'.modal-overlay\').remove()">OK</button>' +
    '</div>' +
  '</div>';

  document.body.appendChild(modal);

  if (typeof onRetry === 'function') {
    var retryBtn = document.getElementById('alertModalRetryBtn');
    if (retryBtn) {
      retryBtn.onclick = function() {
        modal.remove();
        onRetry();
      };
    }
  }
}

function loadCustomer() {
  var stored = null;
  try { var s = localStorage.getItem('customerDetails'); stored = s ? JSON.parse(s) : null; } catch(e) {}
  // Identity (name/phone/email) comes from the account when logged in -
  // that's the authoritative source. Address/landmark are device-local
  // (saved from whatever this browser last checked out with) and aren't
  // part of the account, so keep them from localStorage either way.
  if (window.loggedInCustomer) return Object.assign({}, stored, window.loggedInCustomer);
  return stored;
}
function saveCustomer(d) { localStorage.setItem('customerDetails', JSON.stringify(d)); }

function toggleContactEdit() {
  var summary = document.getElementById('contactSummary');
  var fields = document.getElementById('contactFields');
  if (summary) summary.style.display = 'none';
  if (fields) fields.style.display = '';
}
function toggleAddressEdit() {
  var summary = document.getElementById('addressSummary');
  var fields = document.getElementById('addressPickerFields');
  if (summary) summary.style.display = 'none';
  if (fields) fields.style.display = '';
}
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }

// Cookie helpers for PhonePe state persistence (cookies survive cross-domain redirects, sessionStorage does not)
function setCookie(name, value, seconds) {
  var d = new Date();
  d.setTime(d.getTime() + seconds * 1000);
  document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
}
function getCookie(name) {
  var v = document.cookie.match('(^|;)\s*' + name + '\s*=\s*([^;]+)');
  return v ? decodeURIComponent(v.pop()) : null;
}
function deleteCookie(name) {
  document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;SameSite=Lax';
}

function goBack() {
  if (document.referrer && document.referrer.includes(window.location.host)) {
    window.history.back();
  } else {
    window.location.href = appendTableParam('<?php echo restaurantPageUrl(); ?>');
  }
}

// ── Geocode restaurant address if lat/lng not set ──
function ensureRestaurantCoords(callback) {
  if (window.restaurantLat && window.restaurantLng) {
    if (callback) callback();
    return;
  }
  var radius = parseFloat(window.deliveryRadius) || 0;
  if (radius <= 0) {
    if (callback) callback();
    return;
  }
  // Try to geocode restaurant address (from window var or DOM)
  var address = window.restaurantAddress || '';
  if (!address) {
    if (callback) callback();
    return;
  }
  var apiKey = window.googleMapsApiKey;
  if (!apiKey) {
    if (callback) callback();
    return;
  }
  var url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' + encodeURIComponent(address) + '&key=' + apiKey;
  fetch(url)
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data && data.status === 'OK' && data.results && data.results.length > 0) {
        var loc = data.results[0].geometry.location;
        window.restaurantLat = loc.lat;
        window.restaurantLng = loc.lng;
      }
      if (callback) callback();
    })
    .catch(function() {
      if (callback) callback();
    });
}

// ── Haversine Distance Calculator ──
function haversineDistance(lat1, lon1, lat2, lon2) {
  var R = 6371; // Earth radius in km
  var dLat = (lat2 - lat1) * Math.PI / 180;
  var dLon = (lon2 - lon1) * Math.PI / 180;
  var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
          Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
          Math.sin(dLon / 2) * Math.sin(dLon / 2);
  var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  return R * c;
}

// ── Google Places Address Autocomplete ──
var googlePlacesAutocomplete = null;

function resetSelectedAddress() {
  document.getElementById('chkAddressFormatted').value = '';
  document.getElementById('chkAddressLat').value = '';
  document.getElementById('chkAddressLng').value = '';
  document.getElementById('chkPincodeAuto').value = '';
  var zoneIdEl = document.getElementById('deliveryZoneId');
  if (zoneIdEl) zoneIdEl.value = '';
  var chargeEl = document.getElementById('deliveryCharge');
  if (chargeEl) chargeEl.value = '0';
  var feeRow = document.getElementById('deliveryFeeRow');
  if (feeRow) feeRow.style.display = 'none';
  var infoEl = document.getElementById('deliveryInfo');
  if (infoEl) { infoEl.style.display = 'none'; }
  var previewEl = document.getElementById('deliveryMapPreview');
  if (previewEl) { previewEl.style.display = 'none'; }
}

// Shared by both the text-search autocomplete and the map picker
function applySelectedAddress(lat, lng, formatted, postcode) {
  var addrInput = document.getElementById('google-places-autocomplete');
  if (addrInput) addrInput.value = formatted || addrInput.value;
  document.getElementById('chkAddressFormatted').value = formatted || '';
  document.getElementById('chkAddressLat').value = lat;
  document.getElementById('chkAddressLng').value = lng;
  // TEMPORARY: pincode/zone matching is disabled for order placement (see
  // processOrder). Just stash the postcode for later - don't run the zone
  // lookup or force the manual pincode box open once an address is picked.
  document.getElementById('chkPincodeAuto').value = postcode || '';

  updateDeliveryMapPreview(lat, lng);

  // Show delivery info with selected address
  var infoEl = document.getElementById('deliveryInfo');
  if (infoEl) {
    var distanceHtml = '';
    var radius = parseFloat(window.deliveryRadius) || 0;
    var restLat = parseFloat(window.restaurantLat);
    var restLng = parseFloat(window.restaurantLng);
    var isKmDelivery = window.enableKmDelivery == 1 || window.enableKmDelivery === true;
    if (radius > 0 && restLat && restLng) {
      var dist = haversineDistance(restLat, restLng, lat, lng);
      if (dist <= radius) {
        if (isKmDelivery) {
          // Auto-calculated distance-based charge, shown to the customer up
          // front. The restaurant still confirms/edits the final amount when
          // they accept the order, since straight-line distance is only an
          // estimate of the real delivery route.
          var rate = parseFloat(window.deliveryRatePerKm) || 0;
          var kmCharge = Math.round(dist * rate * 100) / 100;
          var chargeEl = document.getElementById('deliveryCharge');
          if (chargeEl) chargeEl.value = kmCharge;
          if (typeof updateOrderTotal === 'function') updateOrderTotal(kmCharge);
          distanceHtml = '<div style="margin-top:6px;padding:6px 10px;background:#d4edda;color:#155724;border-radius:6px;font-size:13px;">✓ Within delivery area (' + dist.toFixed(1) + ' km / ' + radius + ' km max)<br>Delivery Charge: <strong>' + getCurrency() + kmCharge.toFixed(2) + '</strong> (' + dist.toFixed(1) + ' km × ' + getCurrency() + rate + '/km)</div>';
        } else {
          distanceHtml = '<div style="margin-top:6px;padding:6px 10px;background:#d4edda;color:#155724;border-radius:6px;font-size:13px;">✓ Within delivery area (' + dist.toFixed(1) + ' km / ' + radius + ' km max)</div>';
        }
      } else {
        if (isKmDelivery) {
          var chargeEl2 = document.getElementById('deliveryCharge');
          if (chargeEl2) chargeEl2.value = '0';
          if (typeof updateOrderTotal === 'function') updateOrderTotal(0);
        }
        distanceHtml = '<div style="margin-top:6px;padding:6px 10px;background:#f8d7da;color:#721c24;border-radius:6px;font-size:13px;"><strong>⚠ Sorry, we don\'t deliver here</strong><br>Distance: ' + dist.toFixed(1) + ' km (max ' + radius + ' km)</div>';
      }
    }
    infoEl.style.display = 'block';
    infoEl.innerHTML = '<strong>✓ Address selected</strong><br>' + esc(formatted || '') + '<br><span style="font-size:11px;color:#999">Lat: ' + String(lat).substring(0,8) + ', Lng: ' + String(lng).substring(0,8) + '</span>' + distanceHtml;
  }
}

// Small always-visible map preview below the address field, with a
// draggable pin so customers can fine-tune without opening the big picker.
var deliveryPreviewMap = null;
var deliveryPreviewMarker = null;
function updateDeliveryMapPreview(lat, lng) {
  var container = document.getElementById('deliveryMapPreview');
  if (!container || typeof google === 'undefined' || !google.maps) return;
  container.style.display = 'block';
  var pos = { lat: parseFloat(lat), lng: parseFloat(lng) };
  if (!deliveryPreviewMap) {
    deliveryPreviewMap = new google.maps.Map(container, {
      center: pos, zoom: 16,
      streetViewControl: false, mapTypeControl: false, fullscreenControl: false
    });
    deliveryPreviewMarker = new google.maps.Marker({ position: pos, map: deliveryPreviewMap, draggable: true });
    deliveryPreviewMarker.addListener('dragend', function() {
      var p = deliveryPreviewMarker.getPosition();
      reverseGeocode(p.lat(), p.lng(), function(formatted, postcode) {
        applySelectedAddress(p.lat(), p.lng(), formatted, postcode);
      });
    });
  } else {
    // trigger a resize in case the container was hidden (0x0) when the map was first created
    google.maps.event.trigger(deliveryPreviewMap, 'resize');
    deliveryPreviewMap.setCenter(pos);
    deliveryPreviewMarker.setPosition(pos);
  }
}

// Generic reverse geocode helper (lat/lng -> formatted address + postcode)
function reverseGeocode(lat, lng, callback) {
  var apiKey = window.googleMapsApiKey;
  if (!apiKey) {
    // No Google key: OpenStreetMap's free address lookup
    fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng), { headers: { 'Accept-Language': 'en' } })
      .then(function(r) { return r.json(); })
      .then(function(d) { callback((d && d.display_name) || '', (d && d.address && d.address.postcode) || ''); })
      .catch(function() { callback('', ''); });
    return;
  }
  var url = 'https://maps.googleapis.com/maps/api/geocode/json?latlng=' + lat + ',' + lng + '&key=' + apiKey;
  fetch(url)
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data && data.status === 'OK' && data.results && data.results.length > 0) {
        callback(data.results[0].formatted_address, extractPostcode(data.results[0].address_components));
      } else {
        callback('', '');
      }
    })
    .catch(function() { callback('', ''); });
}

// Triggered only by the "Use My Current Location" button, so the browser's
// permission popup appears right after a click the customer understands —
// not as a surprise prompt when the checkout form first opens.
function useCurrentLocationForDelivery() {
  var btn = document.getElementById('useCurrentLocationBtn');
  if (!navigator.geolocation) {
    var noGeoMsg = 'Location access is not available on this device. Please search your address or pick it on the map instead.';
    if (typeof showToast === 'function') { showToast(noGeoMsg, 'error'); } else { alert(noGeoMsg); }
    return;
  }
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Getting your location...'; }
  navigator.geolocation.getCurrentPosition(function(pos) {
    var lat = pos.coords.latitude, lng = pos.coords.longitude;
    reverseGeocode(lat, lng, function(formatted, postcode) {
      applySelectedAddress(lat, lng, formatted, postcode);
    });
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-location-crosshairs"></i> Use my current location'; }
  }, function(err) {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-location-crosshairs"></i> Use my current location'; }
    var msg = (err && err.code === 1)
      ? 'Location access was blocked. Please allow location access for this site in your browser settings, or search/pick your address on the map.'
      : 'Could not get your location. Please search your address or pick it on the map instead.';
    if (typeof showToast === 'function') { showToast(msg, 'error'); } else { alert(msg); }
  }, { enableHighAccuracy: true, timeout: 10000 });
}

function extractPostcode(addressComponents) {
  var postcode = '';
  (addressComponents || []).forEach(function(c) {
    if (c.types && c.types.indexOf('postal_code') !== -1) postcode = c.long_name;
  });
  return postcode;
}

function initGeoAutocomplete(retries) {
  retries = retries || 0;
  var input = document.getElementById('google-places-autocomplete');
  if (!input) return;
  if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
    // Google Maps script may still be loading — retry briefly instead of giving up.
    if (retries < 20) { setTimeout(function() { initGeoAutocomplete(retries + 1); }, 250); }
    return;
  }
  // Ensure restaurant coords are available for delivery radius check
  ensureRestaurantCoords();

  if (googlePlacesAutocomplete) {
    try { google.maps.event.clearInstanceListeners(input); } catch (e) {}
  }
  googlePlacesAutocomplete = new google.maps.places.Autocomplete(input, {
    fields: ['formatted_address', 'geometry', 'address_components'],
    types: ['geocode']
  });

  googlePlacesAutocomplete.addListener('place_changed', function() {
    var place = googlePlacesAutocomplete.getPlace();
    if (!place || !place.geometry || !place.geometry.location) {
      resetSelectedAddress();
      return;
    }
    var lat = place.geometry.location.lat();
    var lng = place.geometry.location.lng();
    var formatted = place.formatted_address || input.value;
    applySelectedAddress(lat, lng, formatted, extractPostcode(place.address_components));
  });

  // Google's widget has no native "clear" event — detect manual clearing ourselves.
  if (!input.dataset.clearBound) {
    input.dataset.clearBound = '1';
    input.addEventListener('input', function() {
      if (input.value.trim() === '') resetSelectedAddress();
    });
  }
}

// ── Map Picker: let the user drop/drag a pin to set their exact location ──
var mapPickerMap = null;
var mapPickerMarker = null;

// ── No Google Maps (no key, or it failed to load): the same picker on
// OpenStreetMap (Leaflet). mapPickerMap / mapPickerMarker get the few Google-
// style methods the rest of the picker uses (setCenter, setZoom, setPosition,
// getPosition), so useCurrentLocationOnMap / confirmMapLocation work unchanged.
var leafletLoading = null;
function loadLeafletOnce() {
  if (window.L) return Promise.resolve();
  if (leafletLoading) return leafletLoading;
  leafletLoading = new Promise(function(resolve, reject) {
    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    document.head.appendChild(css);
    var js = document.createElement('script');
    js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    js.onload = function() { resolve(); };
    js.onerror = function() { leafletLoading = null; reject(); };
    document.head.appendChild(js);
  });
  return leafletLoading;
}
function startOsmMapPicker(center) {
  var addrEl = document.getElementById('mapPickerAddress');
  if (addrEl) addrEl.textContent = 'Loading map...';
  loadLeafletOnce().then(function() {
    if (!document.getElementById('mapPickerCanvas')) return; // closed meanwhile
    var map = L.map('mapPickerCanvas').setView([center.lat, center.lng], 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
    var marker = L.marker([center.lat, center.lng], { draggable: true }).addTo(map);
    map.on('click', function(e) { marker.setLatLng(e.latlng); reverseGeocodeForPicker(e.latlng.lat, e.latlng.lng); });
    marker.on('dragend', function() { var p = marker.getLatLng(); reverseGeocodeForPicker(p.lat, p.lng); });
    mapPickerMap = {
      setCenter: function(p) { map.setView([p.lat, p.lng], map.getZoom()); },
      setZoom: function(z) { map.setZoom(z); }
    };
    mapPickerMarker = {
      setPosition: function(p) { marker.setLatLng([p.lat, p.lng]); },
      getPosition: function() { var p = marker.getLatLng(); return { lat: function() { return p.lat; }, lng: function() { return p.lng; } }; }
    };
    setTimeout(function() { map.invalidateSize(); }, 50);
    reverseGeocodeForPicker(center.lat, center.lng);
  }).catch(function() {
    if (addrEl) addrEl.textContent = 'The map could not load. Please type your address or use your current location.';
  });
}

function openMapPicker() {
  var useOsm = (typeof google === 'undefined' || !google.maps);
  if (document.getElementById('mapPickerOverlay')) return; // already open

  var restLat = parseFloat(window.restaurantLat);
  var restLng = parseFloat(window.restaurantLng);
  var existingLat = parseFloat(document.getElementById('chkAddressLat')?.value);
  var existingLng = parseFloat(document.getElementById('chkAddressLng')?.value);
  var startCenter = (!isNaN(existingLat) && !isNaN(existingLng)) ? { lat: existingLat, lng: existingLng }
    : (!isNaN(restLat) && !isNaN(restLng)) ? { lat: restLat, lng: restLng }
    : { lat: 20.5937, lng: 78.9629 }; // fallback: center of India

  var overlay = document.createElement('div');
  overlay.id = 'mapPickerOverlay';
  overlay.className = 'modal-overlay';
  overlay.style.zIndex = '10050';
  overlay.innerHTML =
    '<div class="modal-box" style="max-width:520px;width:100%;">' +
      '<div class="modal-header">' +
        '<h2>Select Your Location</h2>' +
        '<button class="modal-close" type="button" onclick="closeMapPicker()">&times;</button>' +
      '</div>' +
      '<div class="modal-body">' +
        '<p style="font-size:12px;color:#666;margin-bottom:8px;">Drag the pin, tap the map, or use your current location to set your exact delivery spot.</p>' +
        '<div id="mapPickerCanvas" style="width:100%;height:320px;border-radius:10px;overflow:hidden;background:#eee;"></div>' +
        '<div id="mapPickerAddress" style="margin-top:10px;font-size:13px;color:#333;min-height:18px;">Locating...</div>' +
        '<div class="btn-group" style="margin-top:14px;">' +
          '<button type="button" class="btn btn-secondary" onclick="useCurrentLocationOnMap()">📍 Use Current Location</button>' +
          '<button type="button" class="btn btn-primary" onclick="confirmMapLocation()">Use This Location</button>' +
        '</div>' +
      '</div>' +
    '</div>';
  document.body.appendChild(overlay);
  if (useOsm) { startOsmMapPicker(startCenter); return; }

  mapPickerMap = new google.maps.Map(document.getElementById('mapPickerCanvas'), {
    center: startCenter,
    zoom: 16,
    streetViewControl: false,
    mapTypeControl: false,
    fullscreenControl: false
  });
  mapPickerMarker = new google.maps.Marker({
    position: startCenter,
    map: mapPickerMap,
    draggable: true
  });

  mapPickerMap.addListener('click', function(e) {
    mapPickerMarker.setPosition(e.latLng);
    reverseGeocodeForPicker(e.latLng.lat(), e.latLng.lng());
  });
  mapPickerMarker.addListener('dragend', function() {
    var pos = mapPickerMarker.getPosition();
    reverseGeocodeForPicker(pos.lat(), pos.lng());
  });

  reverseGeocodeForPicker(startCenter.lat, startCenter.lng);
}

function closeMapPicker() {
  var overlay = document.getElementById('mapPickerOverlay');
  if (overlay) overlay.remove();
  mapPickerMap = null;
  mapPickerMarker = null;
}

function useCurrentLocationOnMap() {
  if (!navigator.geolocation) {
    document.getElementById('mapPickerAddress').textContent = 'Location access is not available on this device.';
    return;
  }
  document.getElementById('mapPickerAddress').textContent = 'Getting your current location...';
  navigator.geolocation.getCurrentPosition(function(pos) {
    var latLng = { lat: pos.coords.latitude, lng: pos.coords.longitude };
    if (mapPickerMap && mapPickerMarker) {
      mapPickerMap.setCenter(latLng);
      mapPickerMap.setZoom(17);
      mapPickerMarker.setPosition(latLng);
    }
    reverseGeocodeForPicker(latLng.lat, latLng.lng);
  }, function() {
    document.getElementById('mapPickerAddress').textContent = 'Could not get your location. Please allow location access, or drag the pin manually.';
  }, { enableHighAccuracy: true, timeout: 10000 });
}

function reverseGeocodeForPicker(lat, lng) {
  var addrEl = document.getElementById('mapPickerAddress');
  if (addrEl) addrEl.textContent = 'Looking up address...';
  reverseGeocode(lat, lng, function(formatted, postcode) {
    if (!addrEl) return;
    addrEl.dataset.formatted = formatted;
    addrEl.dataset.postcode = postcode;
    addrEl.textContent = formatted || 'Could not resolve an address for this spot, but you can still use it.';
  });
}

function confirmMapLocation() {
  if (!mapPickerMarker) { closeMapPicker(); return; }
  var pos = mapPickerMarker.getPosition();
  var addrEl = document.getElementById('mapPickerAddress');
  var formatted = (addrEl && addrEl.dataset.formatted) ? addrEl.dataset.formatted : (addrEl ? addrEl.textContent : '');
  var postcode = (addrEl && addrEl.dataset.postcode) ? addrEl.dataset.postcode : '';
  applySelectedAddress(pos.lat(), pos.lng(), formatted, postcode);
  closeMapPicker();
}
function togglePincodeFallback() {
  var autoEl = document.getElementById('google-places-autocomplete');
  var fallbackEl = document.getElementById('pincodeFallback');
  var toggleLink = document.getElementById('togglePincodeLink');
  if (autoEl && fallbackEl && toggleLink) {
    autoEl.style.display = 'none';
    fallbackEl.style.display = 'block';
    toggleLink.style.display = 'none';
  }
}
function toggleAddressAutocomplete() {
  var autoEl = document.getElementById('google-places-autocomplete');
  var fallbackEl = document.getElementById('pincodeFallback');
  var toggleLink = document.getElementById('togglePincodeLink');
  if (autoEl && fallbackEl && toggleLink) {
    autoEl.style.display = 'block';
    fallbackEl.style.display = 'none';
    toggleLink.style.display = 'inline-block';
    initGeoAutocomplete();
  }
}

// --- PWA Install Prompt ---
let deferredPrompt = null;

// Register Service Worker (silent fail if tracking prevention blocks it)
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    try {
      navigator.serviceWorker.register('<?php echo $website_base_href ?>sw.php', { scope: '<?php echo $site_root ?>' }).then(function(reg) {
      }).catch(function() {
        // Silently ignore - tracking prevention or other restrictions
      });
    } catch(e) {
      // Silently ignore
    }
  });
}

window.addEventListener('beforeinstallprompt', function(e) {
  // Don't preventDefault here — Chrome warns if preventDefault is called but
  // prompt() never fires. Save the event; prompt() is called only on user action.
  deferredPrompt = e;
});

window.addEventListener('appinstalled', function() {
  deferredPrompt = null;
});

function fitTextToContainer(containerSel, textSel, maxFontSize, minFontSize) {
  var el = document.querySelector(containerSel);
  if (!el) return;
  var textSpan = textSel ? el.querySelector(textSel) : el;
  if (!textSpan) return;
  var otherWidth = 0;
  for (var i = 0; i < el.children.length; i++) {
    if (el.children[i] !== textSpan) {
      otherWidth += el.children[i].offsetWidth || 0;
    }
  }
  var gapStyle = window.getComputedStyle(el).gap || '0px';
  var gap = parseFloat(gapStyle) || 0;
  var gapsWidth = (el.children.length - 1) * gap;
  var maxWidth = el.clientWidth - otherWidth - gapsWidth - 2;
  if (maxWidth <= 10) return;
  maxFontSize = maxFontSize || 16;
  minFontSize = minFontSize || 10;
  var fontSize = maxFontSize;
  textSpan.style.fontSize = fontSize + 'px';
  while (textSpan.scrollWidth > maxWidth && fontSize > minFontSize) {
    fontSize -= 0.5;
    textSpan.style.fontSize = fontSize + 'px';
  }
}

function fitCartBrand() {
  fitTextToContainer('.header-brand', null, 16, 10);
}

loadCart();
loadInstruction();
document.addEventListener('DOMContentLoaded', function() {
  renderCart();
  loadMenuItems();
  loadUpsell();
  loadCartWallet();
  loadCartVip();
  fetchAddons();
  loadCouponsFromServer();
  loadLoyaltyBalance();
  checkPhonePeReturn();
  setTimeout(fitCartBrand, 100);
  setTimeout(fitCartBrand, 500);
  var fitTimer = null;
  window.addEventListener('resize', function() {
    clearTimeout(fitTimer);
    fitTimer = setTimeout(fitCartBrand, 100);
  });
});
</script>
<script>
// Iframe embed auto-resize: when this page is loaded inside an embed iframe,
// report the exact content height to the parent so the iframe always fits perfectly.
(function(){
  if (window.parent === window) return;
  document.documentElement.classList.add('in-iframe');
  document.body.classList.add('in-iframe');
  function getContentHeight() {
    var frame = document.querySelector('.phone-frame');
    if (frame) return Math.max(frame.scrollHeight, frame.offsetHeight);
    return document.body.scrollHeight;
  }
  function sendHeight() {
    var h = getContentHeight();
    try { window.parent.postMessage({ type: 'rg-embed-resize', height: h }, '*'); } catch(e) {}
  }
  sendHeight();
  setTimeout(sendHeight, 300);
  setTimeout(sendHeight, 1000);
  if (typeof ResizeObserver !== 'undefined') {
    try {
      var ro = new ResizeObserver(function(){ sendHeight(); });
      ro.observe(document.body);
      ro.observe(document.querySelector('.phone-frame') || document.body);
    } catch(e){}
  }
})();
</script>
<script>window.RG_MENU_URL = <?php echo json_encode(restaurantPageUrl('menu')); ?>;</script>
<script src="rg-banners.js?v=<?php echo @filemtime(__DIR__ . '/rg-banners.js'); ?>" defer></script>
<script src="rg-drag-scroll.js?v=<?php echo @filemtime(__DIR__ . '/rg-drag-scroll.js'); ?>" defer></script>
<script src="rg-upsell.js?v=<?php echo @filemtime(__DIR__ . '/rg-upsell.js'); ?>" defer></script>
<?php include __DIR__ . '/call_waiter.php'; ?>
</body>
</html>
