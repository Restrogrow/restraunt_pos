<?php require_once __DIR__ . '/header.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<base href="<?php echo $website_base_href; ?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="restaurant-id" content="<?php echo htmlspecialchars($restaurant_id ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<meta name="restaurant-slug" content="<?php echo htmlspecialchars($restaurant_slug ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Cart - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?>">
<link rel="manifest" href="manifest.php<?php echo $restaurant_id ? '?restaurant_id=' . urlencode($restaurant_id) : ''; ?>">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars($restaurant_logo ?? $local_placeholder_svg, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? $local_favicon_svg, ENT_QUOTES, 'UTF-8'); ?>">
<title>Cart - <?php echo htmlspecialchars($restaurant_name ?? 'Dvani Cafe & Grill', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700<?php echo $font_family_google_param ? '&family=' . $font_family_google_param . ':wght@300;400;500;600;700' : ''; ?>&display=swap" rel="stylesheet">
<link rel="preload" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0"></noscript>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<!-- Google Maps Places Autocomplete (Delivery address) -->
<?php $googleMapsApiKey = env('GOOGLE_MAPS_API_KEY', ''); ?>
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
  background: #1a3934;
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
  border-top-color: #1a3934;
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
  background: #1a3934;
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
      $gwMode = $gwRow['payment_gateway_mode'] ?? 'own';
      if ($gwMode === 'own' && !empty($gwRow['phonepe_merchant_id'])) {
        $phonepe_configured = true;
      } elseif ($gwMode === 'platform') {
        $phonepe_configured = true;
      }
      if (!empty($gwRow['has_business_qr'])) {
        $business_qr_available = true;
        $business_qr_image_url = '../api/image.php?type=business_qr&id=' . urlencode($gwRow['id']);
      }
    }
  } catch (Exception $e) {}
}
?>
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

function getImageUrl(img) {
  if (!img || img === '' || img === 'no-image') return 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22150%22 height=%22150%22 viewBox=%220 0 150 150%22%3E%3Crect width=%22100%25%22 height=%22100%25%22 fill=%22%23f0f0f0%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 font-family=%22Arial%22 font-size=%2212%22 fill=%22%23999%22 text-anchor=%22middle%22 dy=%22.3em%22%3ENo Image%3C/text%3E%3C/svg%3E';
  if (img.indexOf('http://') === 0 || img.indexOf('https://') === 0) return img;
  if (img.indexOf('db:') === 0) return 'image.php?id=' + encodeURIComponent(img.substring(3));
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
    if (k.indexOf('addon_') === 0) continue;
    var raw = cartItems[k];
    if (typeof raw !== 'number' && (!raw || typeof raw !== 'object' || raw.isAddon)) continue;
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
  showToast('Added ' + addonName + ' to cart', 'success');
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
    
    var info = getItemByKey(key);
    if (!info) continue;
    var price = getCartPrice(key);
    regQty += qty;

    var varDisplay = '';
    if (raw && typeof raw === 'object' && raw.varName) {
      varDisplay = '<div class="item-variant">' + escapeHtml(raw.varName) + '</div>';
    }
    
    // Show add-ons for this item
    var addonNames = getAddonNamesString(key);
    if (addonNames) {
      varDisplay += '<div class="item-variant" style="color:#888;font-size:11px">' + escapeHtml(addonNames) + '</div>';
    }
    var addonTotal = getAddonTotalForItem(key);
    if (addonTotal > 0) {
      price = price + (addonTotal / qty);
    }
    regPrice += price * qty;

    cartHtml +=
      '<div class="card card-pad" id="cartCard-' + key.replace('.', '_') + '">' +
        '<div class="cart-item">' +
          '<img src="' + getImageUrl(info.item_image || info.image) + '" alt="' + escapeHtml(info.item_name_translated || info.item_name_en || info.name) + '" loading="lazy">' +
          '<div class="item-info">' +
            '<div class="item-name">' + escapeHtml(info.item_name_translated || info.item_name_en || info.name) + '</div>' +
            varDisplay +
            '<div class="item-price"><span>QTY: ' + qty + '&nbsp;</span>' + getCurrency() + (price * qty).toFixed(2) + '</div>' +
            '<div class="qty-ctrl">' +
              '<button onclick="changeQty(\'' + key + '\', -1)">−</button>' +
              '<span>' + qty + '</span>' +
              '<button onclick="changeQty(\'' + key + '\', 1)">+</button>' +
            '</div>' +
          '</div>' +
          '<button class="delete-btn" onclick="deleteItem(\'' + key + '\')" title="Remove">🗑</button>' +
        '</div>' +
      '</div>';
  }

  // Combine totals
  totalQty = regQty + addonQty;
  totalPrice = regPrice + addonPrice;

  var couponStatusHtml = '';
  if (appliedCoupon) {
    var discLabel = appliedCoupon.type === 'percent' ? appliedCoupon.discount + '% OFF' : (appliedCoupon.type === 'flat' ? getCurrency() + appliedCoupon.discount + ' OFF' : 'FREE DELIVERY');
    couponStatusHtml = '<div class="coupon-status success"><span class="cpn-status-text">✅ ' + appliedCoupon.code + ' applied (' + discLabel + ')</span><span class="remove-coupon" onclick="removeCoupon()">Remove</span></div>';
  } else if (couponError) {
    couponStatusHtml = '<div class="coupon-status error">❌ ' + couponError + '</div>';
  }

  cartHtml +=
    '<div class="card card-pad" id="couponCard">' +
      '<div class="coupon-header">' +
        '<div class="icon-wrap">%</div>' +
        '<div class="header-text">' +
          '<strong>Have a coupon code?</strong>' +
          '<span>Enter below or browse available offers</span>' +
        '</div>' +
      '</div>' +
      '<div class="coupon-input-row">' +
        '<input type="text" id="couponInput" placeholder="Enter coupon code" maxlength="20" ' + (appliedCoupon ? 'disabled' : '') + ' onkeydown="if(event.key===\'Enter\')applyCoupon()">' +
        '<button class="apply-coupon-btn" id="applyCouponBtn" onclick="applyCoupon()" ' + (appliedCoupon ? 'disabled' : '') + '>Apply</button>' +
      '</div>' +
      '<div id="couponStatus">' + couponStatusHtml + '</div>' +
      '<div class="coupon-browse" onclick="openCouponSheet()">' +
        '<div class="badge">%</div>' +
        '<span>Browse all coupons</span>' +
        '<span class="arrow">→</span>' +
      '</div>' +      '</div>' +
      '<div class="instr-card">' +
      '<div class="instr-header" id="instrHeader" onclick="toggleInstruction()">' +
        '<div class="label-wrap">' +
          '<div class="label-icon"><i class="fa fa-pen"></i></div>' +
          '<span class="label-text">Add special instructions</span>' +
        '</div>' +
        '<span class="chevron">&#8964;</span>' +
      '</div>' +
      '<div class="instr-body-inner" id="instrBody">' +
        '<textarea id="instrText" placeholder="Write your instruction here - e.g. dietary preferences, gifting notes, delivery instructions..." oninput="updateCount()">' + (savedInstruction || '') + '</textarea>' +
        '<div class="char-count"><span id="charCount">' + (savedInstruction ? savedInstruction.length : 0) + '</span> / 500</div>' +
        '<button class="save-btn" onclick="saveInstruction()">Save Instruction</button>' +
      '</div>' +
      
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
    var finalTotal = Math.max(0, remainingAfterCoupon - loyaltyDiscountAmount);

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
        '<h3>Price Details</h3>' +
        '<div class="price-row">' +
          '<span>Total Items (' + regQty + ' Items)</span>' +
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
        (PACKAGING_CHARGE > 0 ? '<div class="price-row">' +
          '<span>Packaging Charge</span>' +
          '<span class="val">' + getCurrency() + PACKAGING_CHARGE.toFixed(2) + '</span>' +
        '</div>' : '') +
        '<div class="price-row">' +
          '<span>Delivery Fee</span>' +
          '<span class="note">Delivery fee is calculated after login</span>' +
        '</div>' +
        '<hr class="divider">' +
        '<div class="total-row">' +
          '<span>Total Amount</span>' +
          '<span>' + getCurrency() + finalTotal.toFixed(2) + '</span>' +
        '</div>' +
        (MIN_ORDER > 0 ? '<div class="min-order-warning" id="minOrderWarning">' +
          '<i class="bi bi-exclamation-circle"></i> Minimum Order Value Is ' + getCurrency() + MIN_ORDER.toFixed(2) + ' Please Add More Items To Proceed.' +
        '</div>' +
        '<div class="min-order-bar"><div class="fill" style="width:' + Math.min(100, (totalPrice / MIN_ORDER) * 100) + '%"></div></div>' : '') +
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
  var sym = getCurrency();
  document.getElementById('checkoutLabel').textContent = sym + finalTotal.toFixed(2) + ' (' + regQty + ' Items)';
  document.getElementById('checkoutBtn').disabled = true;

  if (MIN_ORDER <= 0 || totalPrice >= MIN_ORDER) {
    var warningEl = document.getElementById('minOrderWarning');
    if (warningEl) {
      warningEl.style.display = 'none';
    }
    document.getElementById('checkoutBtn').disabled = false;
  }
}

function changeQty(key, delta) {
  if (!cartItems[key]) return;
  var raw = cartItems[key];
  if (typeof raw === 'number') {
    cartItems[key] = Math.max(0, raw + delta);
    if (cartItems[key] === 0) delete cartItems[key];
  } else if (typeof raw === 'object') {
    raw.qty = Math.max(0, (raw.qty || 0) + delta);
    if (raw.qty === 0) delete cartItems[key];
  }
  saveCart();
  renderCart();
}

function deleteItem(key) {
  clearAddonsForItem(key);
  delete cartItems[key];
  saveCart();
  renderCart();
}

var savedInstruction = '';

function toggleInstruction() {
  var header = document.getElementById('instrHeader');
  var body = document.getElementById('instrBody');
  if (!header || !body) return;
  header.classList.toggle('open');
  body.classList.toggle('visible');
}

function updateCount() {
  var t = document.getElementById('instrText');
  var countEl = document.getElementById('charCount');
  if (t && countEl) countEl.textContent = t.value.length;
}

function saveInstruction() {
  var t = document.getElementById('instrText');
  if (!t) return;
  savedInstruction = t.value;
  try { localStorage.setItem('dvaniInstruction', savedInstruction); } catch(e) {}
  showToast('Instruction saved! ✓');
}

function loadInstruction() {
  try {
    var saved = localStorage.getItem('dvaniInstruction');
    if (saved) savedInstruction = saved;
  } catch(e) {}
}

function showToast(msg, type) {
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
            dbId: c.id
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
      }
    })
    .catch(function() {});
}

function applyCoupon() {
  var input = document.getElementById('couponInput');
  var code = input ? input.value.trim().toUpperCase() : '';
  if (!code) return;

  couponError = '';
  var found = null;
  for (var i = 0; i < availableCoupons.length; i++) {
    if (availableCoupons[i].code === code) {
      found = availableCoupons[i];
      break;
    }
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
  renderCart();
  setTimeout(function() {
    var inp = document.getElementById('couponInput');
    if (inp) inp.classList.remove('error');
  }, 0);
}

function removeCoupon() {
  appliedCoupon = null;
  couponError = '';
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
          '<div class="cpn-code">' + c.code + '</div>' +
          '<div class="cpn-desc">' + discLabel + ' - ' + c.description + '</div>' +
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
  if (window.restaurantTimezoneOffset) {
    var utc = now.getTime() + now.getTimezoneOffset() * 60000;
    now = new Date(utc + window.restaurantTimezoneOffset * 60000);
  }
  var day = days[now.getDay()];
  var dayData = hours[day];
  if (!dayData || !dayData.open) return false;
  function toMins(t) {
    var p = t.split(' ');
    var tp = p[0].split(':');
    var h = parseInt(tp[0], 10), m = parseInt(tp[1], 10);
    if (p[1] === 'PM' && h !== 12) h += 12;
    if (p[1] === 'AM' && h === 12) h = 0;
    return h * 60 + m;
  }
  var cur = now.getHours() * 60 + now.getMinutes();
  var open = toMins(dayData.opening);
  var close = toMins(dayData.closing);
  if (close <= open) return cur >= open || cur < close;
  return cur >= open && cur < close;
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
    var item = getItemByKey(key);
    if (item) {
      var qty = getCartQty(key);
      var basePrice = getCartPrice(key);
      var addonTotal = getAddonTotalForItem(key);
      totalQty += qty;
      totalPrice += (basePrice * qty) + addonTotal;
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
  var finalTotal = Math.max(0, remainingAfterCoupon - loyaltyDiscountAmount);
  return {
    totalQty: totalQty, totalPrice: totalPrice, keys: keys,
    discountAmount: discountAmount, appliedCoupon: appliedCoupon,
    loyaltyPointsRedeemed: loyaltyPointsRedeemed, loyaltyDiscountAmount: loyaltyDiscountAmount,
    finalTotal: finalTotal
  };
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

  var showGst = window.enableGst == 1 || window.enableGst === true;
  var taxable = baseAmt;
  var gst = showGst ? taxable * ((window.taxPercent || 5) / 100) : 0;

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
    feeRow.style.display = isDelivery && delCharge > 0 ? 'flex' : 'none';
    if (isDelivery && delCharge > 0) {
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
  html += '<div id="addressSummary" style="display:' + (hasSavedAddress ? 'flex' : 'none') + ';align-items:flex-start;justify-content:space-between;gap:10px;padding:12px 14px;background:#f0f7f5;border-radius:10px;border:2px solid #e0e0e0;">';
  html += '<div style="font-size:13px;color:#1a1b1f;line-height:1.4;">📍 ' + esc((cd && cd.address) || '') + ((cd && cd.landmark) ? '<div style="color:#999;font-size:12px;margin-top:2px;">' + esc(cd.landmark) + '</div>' : '') + '</div>';
  html += '<span onclick="toggleAddressEdit()" style="font-size:12px;color:var(--primary-red, #e17055);font-weight:600;cursor:pointer;white-space:nowrap;flex-shrink:0;">Change</span>';
  html += '</div>';

  html += '<div id="addressPickerFields" style="display:' + (hasSavedAddress ? 'none' : '') + '">';
  html += '<button type="button" id="useCurrentLocationBtn" onclick="useCurrentLocationForDelivery()" style="width:100%;display:flex;align-items:center;justify-content:center;gap:6px;padding:12px 14px;border:none;border-radius:10px;background:#1a3934;color:#fff;font-size:14px;font-weight:600;font-family:\'Poppins\',sans-serif;cursor:pointer;">📍 Use My Current Location</button>';
  html += '<div style="text-align:center;margin:8px 0;font-size:11px;color:#999;">— or —</div>';
  html += '<input type="text" id="google-places-autocomplete" autocomplete="off" placeholder="Search your delivery address..." style="width:100%;padding:12px 14px;border:2px solid #e0e0e0;border-radius:10px;font-size:13px;font-family:\'Poppins\',sans-serif;outline:none;box-sizing:border-box">';
  html += '<button type="button" onclick="openMapPicker()" style="margin-top:8px;width:100%;display:flex;align-items:center;justify-content:center;gap:6px;padding:11px 14px;border:2px solid #1a3934;border-radius:10px;background:#fff;color:#1a3934;font-size:13px;font-weight:600;font-family:\'Poppins\',sans-serif;cursor:pointer;">🗺️ Pick Location on Map</button>';
  html += '<div id="deliveryMapPreview" style="display:none;margin-top:10px;width:100%;height:160px;border-radius:10px;overflow:hidden;border:2px solid #e0e0e0;"></div>';
  html += '<div id="deliveryInfo" style="display:none;margin-top:8px;padding:10px;background:#f0f7f5;border-radius:8px;font-size:13px;"></div>';
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
  html += '<div id="packagingChargeRow" class="summary-row" style="display:none;">';
  html += '<span>Packaging Charge</span><span id="packagingChargeAmount">' + getCurrency() + '0.00</span>';
  html += '</div>';
  html += '<div id="deliveryFeeRow" class="summary-row" style="display:none;">';
  html += '<span>Delivery Fee</span><span id="deliveryFeeAmount">' + getCurrency() + '0.00</span>';
  html += '</div>';
  var enableGst = window.enableGst == 1 || window.enableGst === true;
  var taxLabel = (window.taxName || 'GST') + ' (' + parseFloat(window.taxPercent || 5) + '%)';
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
    { key: 'delivery', label: '🚚 Delivery', val: 'Delivery' },
    { key: 'takeaway', label: '🥡 Takeaway', val: 'Takeaway' },
    { key: 'dinein', label: '🍽️ Dine In', val: 'Dine-in' }
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
    html += '<label class="order-type-option" data-ot="' + opt.key + '" style="flex:1;padding:10px;border:2px solid ' + (active ? '#1a3934' : '#e0e0e0') + ';border-radius:10px;text-align:center;cursor:pointer;background:' + (active ? '#f0f7f5' : '#fff') + ';font-size:13px;min-width:80px">';
    html += '<input type="radio" name="orderType" value="' + opt.val + '" ' + (active ? 'checked' : '') + ' style="display:none"> ' + opt.label + '</label>';
  }
  html += '</div>';  // close flex wrapper
  // When QR table is set, hide the UI but keep select in DOM for order submission
  var tableSectionDisplay = (savedOrderType === 'dinein' && !qrTable) ? '' : 'none';
  html += '<div id="dineinTableSection" style="margin-top:10px;display:' + tableSectionDisplay + '">';
  html += '<label style="display:block;margin-bottom:6px;font-weight:500;color:#333;font-size:13px">🍽️ Select a Table</label>';

  html += '<select id="chkTableId" style="width:100%;padding:12px 14px;border:2px solid #e0e0e0;border-radius:10px;font-size:13px;font-family:\'Poppins\',sans-serif;outline:none;box-sizing:border-box">';
  html += '<option value="">Select a table...</option></select>';
  html += '</div>';  // close dineinTableSection
  html += '</div>';  // close form-group

  // Order Now vs Schedule for Later
  html += '<div class="form-group">';
  html += '<label>When?</label>';
  html += '<div style="display:flex;gap:10px;margin-top:4px;">';
  html += '<label class="schedule-option" data-sched="now" style="flex:1;padding:10px;border:2px solid #1a3934;border-radius:10px;text-align:center;cursor:pointer;background:#f0f7f5;font-size:13px;">';
  html += '<input type="radio" name="scheduleType" value="now" checked style="display:none"> Order Now</label>';
  html += '<label class="schedule-option" data-sched="later" style="flex:1;padding:10px;border:2px solid #e0e0e0;border-radius:10px;text-align:center;cursor:pointer;background:#fff;font-size:13px;">';
  html += '<input type="radio" name="scheduleType" value="later" style="display:none"> \u{1F4C5} Schedule for Later</label>';
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
    html += '<div style="margin-top:8px;"><a href="' + window.businessQrImageUrl + '" download="payment-qr.png" style="color:#1a3934;font-weight:600;font-size:13px;text-decoration:none;">⬇ Download QR</a></div>';
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
      this.style.borderColor = '#1a3934';
      this.style.background = '#f0f7f5';
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
      this.style.borderColor = '#1a3934';
      this.style.background = '#f0f7f5';
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
  for (var i = 0; i < keys.length; i++) {
    var key = keys[i];
    var raw = cartItems[key];
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
        addons: getAddonsForItemKey(key)
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
      global_addons: globalAddons.length > 0 ? globalAddons : [],
      total: cartData.totalPrice,
      total_payable: total,
      payment_method: payment,
      payment_proof_base64: (payment === 'QR Payment' && window.__paymentProofBase64) ? window.__paymentProofBase64 : '',
      coupon_code: appliedCoupon ? appliedCoupon.code : '',
      discount_amount: cartData.discountAmount || 0,
      redeem_points: cartData.loyaltyPointsRedeemed || 0,
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
        sessionStorage.setItem('phonepe_order_number', data.order_number);
        setCookie('pp_order_number', data.order_number, 30);
        document.getElementById('checkoutModal').remove();
        initiatePhonePePayment(data.order_id, phone);
        return;
      }
      cartItems = {};
      saveCart();
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
  loading.innerHTML = '<div class="alert-box" style="text-align:center"><h3>Redirecting to PhonePe...</h3><p style="margin-top:10px">Please wait while we redirect you to PhonePe payment page.</p><div style="margin-top:15px;font-size:24px">&#8987;</div></div>';
  document.body.appendChild(loading);

  // Store in sessionStorage AND cookies (cookies survive cross-domain redirects)
  sessionStorage.setItem('phonepe_pending', orderId);
  setCookie('pp_pending', orderId, 30);

  fetchJsonWithRetry('../api/phonepe_order_payment.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ order_id: orderId, customer_phone: customerPhone })
  })
  .then(function(data) {
    var loader = document.getElementById('phonepeLoader');
    if (loader) loader.remove();

    if (data.success && data.payment_url) {
      window.location.href = data.payment_url;
    } else {
      sessionStorage.removeItem('phonepe_pending');
      deleteCookie('pp_pending');
      showModal('Payment Error', data.message || 'Failed to initiate PhonePe payment.', function() {
        initiatePhonePePayment(orderId, customerPhone);
      });
    }
  })
  .catch(function(err) {
    var loader = document.getElementById('phonepeLoader');
    if (loader) loader.remove();
    sessionStorage.removeItem('phonepe_order_number');
    sessionStorage.removeItem('phonepe_pending');
    deleteCookie('pp_pending');
    deleteCookie('pp_order_number');
    // Note: the order itself was already created and saved server-side
    // (Pending payment state) before this payment step ran — only the
    // payment session creation failed. Retrying re-uses the same orderId
    // (initiatePhonePePayment re-sets the pending markers at its top)
    // rather than creating a new order.
    showModal('Connection Problem', 'We could not start the payment after a few tries. Your order is saved — please check your connection and retry payment.', function() {
      initiatePhonePePayment(orderId, customerPhone);
    });
  });
}

function handlePaymentSuccess(orderNumber) {
  var loader = document.getElementById('ppVerifyLoader');
  if (loader) loader.remove();
  sessionStorage.removeItem('phonepe_order_number');
  sessionStorage.removeItem('phonepe_pending');
  deleteCookie('pp_pending');
  deleteCookie('pp_order_number');
  cartItems = {};
  saveCart();
  renderCart();
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
  var isDemo = params.get('phonepe_demo');
  
  // Log all URL params for debugging
  var allParams = {};
  for (var _pair of params.entries()) { allParams[_pair[0]] = _pair[1]; }

  // Check sessionStorage first, then fall back to cookies (which survive cross-domain redirects)
  var hasPending = sessionStorage.getItem('phonepe_pending') || getCookie('pp_pending');

  // If order_id is in URL, proceed regardless of hasPending (cross-domain redirect clears storage)
  if (!orderNumber) {
    orderNumber = sessionStorage.getItem('phonepe_order_number') || getCookie('pp_order_number');
    if (!orderNumber || !hasPending) {
      return;
    }
  }


  if (isDemo === '1') {
    sessionStorage.removeItem('phonepe_order_number');
    sessionStorage.removeItem('phonepe_pending');
    deleteCookie('pp_pending');
    deleteCookie('pp_order_number');
    cartItems = {};
    saveCart();
    renderCart();
    showSuccessModal(orderNumber);
    var url = new URL(window.location.href);
    url.searchParams.delete('order_id');
    url.searchParams.delete('phonepe_demo');
    url.searchParams.delete('transaction_id');
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
    return;
  }

  // Clean PhonePe-added params from URL (keep order_id and restaurant_id)
  var url = new URL(window.location.href);
  var cleanParams = ['transaction_id', 'transactionId', 'merchantTransactionId', 'code', 'amount', 'providerReferenceId', 'phonepe_demo'];
  cleanParams.forEach(function(p) { url.searchParams.delete(p); });
  window.history.replaceState({}, '', url.toString());

  // Show loading overlay. Includes a "didn't complete payment" escape hatch —
  // someone who backed out of PhonePe without paying knows immediately that
  // nothing was charged, and shouldn't have to sit through the full polling
  // window (previously up to 5 minutes) before getting a clear answer.
  window._ppVerifyCancelled = false;
  var loading = document.createElement('div');
  loading.id = 'ppVerifyLoader';
  loading.className = 'modal-overlay';
  loading.innerHTML = '<div class="alert-box" style="text-align:center"><h3>Verifying Payment...</h3><p style="margin-top:10px">Please wait while we verify your payment.</p><div style="margin-top:15px;font-size:24px">&#8987;</div><button type="button" onclick="cancelPhonePeVerification(\'' + orderNumber.replace(/'/g, "\\'") + '\')" style="margin-top:18px;background:none;border:1px solid #ccc;border-radius:6px;padding:8px 16px;color:#666;cursor:pointer;font-size:0.9rem;">I didn\'t complete this payment</button></div>';
  document.body.appendChild(loading);

  // Build the status check URL (only pass code=PAYMENT_SUCCESS if PhonePe actually sent it)
  var statusUrl = '../api/phonepe_order_payment.php?action=status&order_id=' + encodeURIComponent(orderNumber);
  var successCode = params.get('code');
  if (successCode === 'PAYMENT_SUCCESS') {
    statusUrl += '&code=PAYMENT_SUCCESS';
  }

  fetch(statusUrl)
    .then(function(r) { 
      return r.json(); 
    })
    .then(function(data) {
      if (data.success && data.payment_status === 'Success') {
        handlePaymentSuccess(orderNumber);
      } else if (!window._ppVerifyCancelled) {
        // Fall back to regular polling
        pollPaymentStatus(orderNumber, 24, 5000);
      }
    })
    .catch(function(err) {
      // Network error, fall back to polling
      if (!window._ppVerifyCancelled) pollPaymentStatus(orderNumber, 24, 5000);
    });
}

// No webhook is configured yet, so this browser poll is the ONLY automatic
// way a payment gets confirmed — there's nothing else watching in the
// background. So it deliberately keeps checking for a while (~2 min fast,
// then ~10 min slower — UPI confirmations can occasionally take that long)
// instead of giving up quickly. This is safe to do because someone who knows
// they didn't pay already has the "I didn't complete this payment" button on
// the overlay to bail out immediately — extending the automatic ceiling only
// helps the people who stay and wait for a real (if slow) confirmation.
// Once a webhook is wired up later, it becomes a redundant safety net instead
// of the only path, and this can be trimmed back down.
function pollPaymentStatus(orderNumber, retries, intervalMs) {
  intervalMs = intervalMs || 5000;

  if (retries <= 0) {
    if (intervalMs < 15000) {
      // Fast phase exhausted — switch to slower background polling rather
      // than giving up on a payment that might still be genuinely processing.
      var msgEl = document.querySelector('#ppVerifyLoader p');
      if (msgEl) msgEl.textContent = "This is taking longer than usual, but we're still checking. Please keep this tab open.";
      pollPaymentStatus(orderNumber, 40, 15000);
      return;
    }
    var loader = document.getElementById('ppVerifyLoader');
    if (loader) loader.remove();
    // Both phases exhausted (~12 minutes) — check the DB one more time directly
    fetch('../api/phonepe_order_payment.php?action=status&order_id=' + encodeURIComponent(orderNumber))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success && data.payment_status === 'Success') {
          handlePaymentSuccess(orderNumber);
        } else {          sessionStorage.removeItem('phonepe_order_number');
          sessionStorage.removeItem('phonepe_pending');
          deleteCookie('pp_pending');
          deleteCookie('pp_order_number');
          showModal('Payment Not Confirmed', "We couldn't confirm this payment. If you completed it, it may take a few minutes to reflect — check Order Status in your profile. If you didn't complete it, you're free to try again.");
          var url = new URL(window.location.href);
          url.searchParams.delete('order_id');
          window.history.replaceState({}, '', url.toString());
        }
      })
      .catch(function() {
        sessionStorage.removeItem('phonepe_order_number');
        sessionStorage.removeItem('phonepe_pending');
        deleteCookie('pp_pending');
        deleteCookie('pp_order_number');
        showModal('Payment Not Confirmed', "We couldn't confirm this payment. If you completed it, it may take a few minutes to reflect — check Order Status in your profile. If you didn't complete it, you're free to try again.");
          var url = new URL(window.location.href);
          url.searchParams.delete('order_id');
          window.history.replaceState({}, '', url.toString());
      });
    return;
  }

  fetch('../api/phonepe_order_payment.php?action=status&order_id=' + encodeURIComponent(orderNumber))
    .then(function(r) {
      return r.json();
    })
    .then(function(data) {
      if (data.success && data.payment_status === 'Success') {
        handlePaymentSuccess(orderNumber);
      } else if (data.success && data.payment_status === 'Pending') {
        if (!window._ppVerifyCancelled) setTimeout(function() { pollPaymentStatus(orderNumber, retries - 1, intervalMs); }, intervalMs);
      } else {
        var loader = document.getElementById('ppVerifyLoader');
        if (loader) loader.remove();
        sessionStorage.removeItem('phonepe_order_number');
        sessionStorage.removeItem('phonepe_pending');
        deleteCookie('pp_pending');
        deleteCookie('pp_order_number');
        showModal('Payment Failed', 'Your payment could not be completed.');
        var url = new URL(window.location.href);
        url.searchParams.delete('order_id');
        window.history.replaceState({}, '', url.toString());
      }
    })
    .catch(function(err) {
      if (!window._ppVerifyCancelled) setTimeout(function() { pollPaymentStatus(orderNumber, retries - 1, intervalMs); }, intervalMs);
    });
}

// Lets someone who backed out of PhonePe without paying dismiss the
// "Verifying Payment..." overlay immediately instead of waiting through the
// full polling window — stops further polling and clears the pending order
// markers so a retry starts clean. If the payment actually does succeed via
// an in-flight request that resolves after this, handlePaymentSuccess() below
// still overrides this and shows the real outcome.
function cancelPhonePeVerification(orderNumber) {
  window._ppVerifyCancelled = true;
  var loader = document.getElementById('ppVerifyLoader');
  if (loader) loader.remove();
  sessionStorage.removeItem('phonepe_order_number');
  sessionStorage.removeItem('phonepe_pending');
  deleteCookie('pp_pending');
  deleteCookie('pp_order_number');
  var url = new URL(window.location.href);
  url.searchParams.delete('order_id');
  window.history.replaceState({}, '', url.toString());
  showModal('Payment Cancelled', "No problem — your order is saved as Pending payment. You can try paying again from your order details, or place a new order.");
}


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
      trackUrl = 'track.php?order_number=' + encodeURIComponent(orderNum) + '&customer_phone=' + encodeURIComponent(storedPhone);
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
      itemsSummary += info.items[i].name + ' x' + info.items[i].quantity + ', ';
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
  if (!apiKey) { callback('', ''); return; }
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
  if (btn) { btn.disabled = true; btn.innerHTML = '📍 Getting your location...'; }
  navigator.geolocation.getCurrentPosition(function(pos) {
    var lat = pos.coords.latitude, lng = pos.coords.longitude;
    reverseGeocode(lat, lng, function(formatted, postcode) {
      applySelectedAddress(lat, lng, formatted, postcode);
    });
    if (btn) { btn.disabled = false; btn.innerHTML = '📍 Use My Current Location'; }
  }, function(err) {
    if (btn) { btn.disabled = false; btn.innerHTML = '📍 Use My Current Location'; }
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

function openMapPicker() {
  if (typeof google === 'undefined' || !google.maps) {
    if (typeof showToast === 'function') { showToast('Map is still loading, please try again in a moment', 'error'); }
    else { alert('Map is still loading, please try again in a moment'); }
    return;
  }
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
  if (!window.googleMapsApiKey) return;
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
  e.preventDefault();
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
</body>
</html>
