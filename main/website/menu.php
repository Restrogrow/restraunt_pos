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
<meta name="apple-mobile-web-app-title" content="Menu - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?>">
<link rel="manifest" href="manifest.php<?php echo $restaurant_id ? '?restaurant_id=' . urlencode($restaurant_id) : ''; ?>">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars($restaurant_logo ?? $local_placeholder_svg, ENT_QUOTES, 'UTF-8'); ?>">
<title>Menu - <?php echo htmlspecialchars($restaurant_name ?? 'Dvani Cafe & Grill', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
  height: 100%; overflow: hidden;
}
body {
  font-family: 'Poppins', sans-serif;
  background: #e8ecf2;
  color: #1a1b1f;
}
.phone-frame {
  width: 100%; max-width: 425px;
  margin: 0 auto;
  height: 100dvh;
  background: #fff;
  position: relative;
  overflow: hidden;
  box-shadow: 0 0 40px rgba(0,0,0,0.08);
}
@media (min-width: 768px) {
  .phone-frame { margin: 20px auto; height: calc(100dvh - 40px); border-radius: 28px; }
}

.bg-wrapper {
  background: #fff;
  display: flex;
  flex-direction: column;
  height: 100%;
}

.pr-share-header {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 16px 8px 12px;
  border-bottom: 1.5px solid #ccc;
}
.back-btn-cmon {
  display: flex; align-items: center; justify-content: center;
  width: 40px; height: 40px; border-radius: 8px;
  background: linear-gradient(135deg, #e17055, #d63031);
  color: #fff; border: none; cursor: pointer; font-size: 22px;
  flex-shrink: 0;
}
.category-breadcrumb {
  display: flex; align-items: center;
  background: linear-gradient(135deg, #e17055, #d63031);
  border-radius: 8px;
  font-size: 11px; font-weight: 700; color: #fff;
  min-height: 30px; padding: 0 10px;
  white-space: nowrap;
}
.header-actions {
  display: flex; align-items: center; gap: 8px;
  margin-left: auto;
}
.header-action-btn {
  display: flex; align-items: center; justify-content: center;
  width: 40px; height: 40px; border-radius: 8px;
  background: linear-gradient(135deg, #e17055, #d63031);
  color: #fff; border: none; cursor: pointer; font-size: 18px;
  flex-shrink: 0;
}


.layout-container {
  display: flex;
  flex: 1;
  overflow: hidden;
  min-height: 0;
}

.side-nav {
  width: 86px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 6px 4px 20px;
  border-right: 1.5px solid #ccc;
  overflow-y: scroll;
  -ms-overflow-style: none;
  scrollbar-width: none;
  height: 100%;
  min-height: 0;
}
.side-nav::-webkit-scrollbar { display: none; }
.sub-views { display: flex; }
.side-nav-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  width: 72px;
  padding: 10px 4px;
  border: none;
  background: transparent;
  border-radius: 8px;
  cursor: pointer;
  font-size: 11px;
  font-family: 'Poppins', sans-serif;
  color: #1a1b1f;
  font-weight: 500;
  line-height: 1.2;
  text-align: center;
  transition: all 0.2s;
}
.side-nav-btn img {
  width: 42px; height: 42px;
  border-radius: 50%;
  object-fit: cover;
}
.side-nav-btn.active {
  background: linear-gradient(135deg, #e17055, #d63031);
  color: #fff;
}

.main-content {
  flex: 1;
  min-width: 0;
  min-height: 0;
  overflow-y: scroll;
  -ms-overflow-style: none;
  scrollbar-width: none;
  padding: 12px 12px 16px;
  background: #f5f5f5;
  transition: padding-bottom 0.2s;
}
.main-content.has-cart {
  padding-bottom: 100px;
}
.main-content::-webkit-scrollbar { display: none; }

.category-overview {
  background: #fff;
  padding: 8px 12px 6px;
  border-radius: 0 0 14px 14px;
}
.category-overview h2 {
  font-size: 16px;
  font-weight: 700;
  color: #1a1b1f;
  margin: 0;
  padding: 0 4px;
}

.search-rapper {
  display: flex; padding: 10px 16px;
}
.search-container {
  display: flex; align-items: center;
  width: 100%;
  background: #fff;
  border: 1px solid #2d3436;
  border-radius: 999px;
  overflow: hidden;
}
.search-input {
  flex: 1; border: none; outline: none;
  padding: 6px 14px;
  font-size: 14px; font-family: 'Poppins', sans-serif;
  height: 32px;
  background: transparent;
  color: #111;
}
.search-input::placeholder {
  color: #999; font-size: 13px;
}
.search-btn {
  width: 39px; height: 38px;
  border: none; border-left: 1px solid #ddd;
  background: transparent;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: #333; font-size: 16px;
}

.product-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  padding-top: 10px;
}

@media (max-width: 480px) {
  .product-grid.cols-1 {
    grid-template-columns: 1fr;
  }
}

.card {
  background: #fff;
  border-radius: 14px;
  overflow: hidden;
  border: 1.5px solid #000;
  display: flex;
  flex-direction: column;
  transition: transform 0.2s, box-shadow 0.2s;
  cursor: pointer;
}
.card:hover {
  transform: translateY(-3px);
  box-shadow: 0 6px 20px rgba(0,0,0,0.12);
}

.card-img-wrap {
  position: relative;
}

.card-img-wrap img {
  width: 100%;
  height: 120px;
  object-fit: cover;
  display: block;
}

.veg-dot {
  position: absolute;
  top: 6px;
  left: 6px;
  width: 18px;
  height: 18px;
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 3px;
}

.veg-dot img {
  width: 14px;
  height: 14px;
  border-radius: 0;
}

.card-body {
  padding: 10px 12px 12px;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.card-name {
  font-size: 14px;
  font-weight: 600;
  color: #1a1b1f;
  line-height: 1.3;
}

.card-price {
  font-size: 14px;
  font-weight: 600;
  color: #2d3436;
}

.card.oos { opacity: 0.55; pointer-events: none; }
.card.oos .card-img-wrap img { filter: grayscale(1); }
.card-oos-badge { font-size: 10px; font-weight: 600; color: #e74c3c; background: #fdecea; padding: 2px 8px; border-radius: 4px; display: inline-block; text-transform: uppercase; letter-spacing: 0.3px; }

.add-btn {
  width: 100%;
  padding: 6px 12px;
  border: none;
  border-radius: 8px;
  background: linear-gradient(135deg, #e17055, #d63031);
  color: #fff;
  font-size: 12px;
  font-family: 'Poppins', sans-serif;
  font-weight: 600;
  cursor: pointer;
  letter-spacing: 0.3px;
  min-height: 32px;
}

.qty-inline {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0;
  background: linear-gradient(135deg, #e17055, #d63031);
  border-radius: 8px;
  overflow: hidden;
}
.qty-inline button {
  width: 30px;
  height: 32px;
  border: none;
  background: transparent;
  color: #fff;
  font-size: 14px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'Poppins', sans-serif;
}
.qty-inline .qty-num {
  min-width: 24px;
  text-align: center;
  color: #fff;
  font-size: 14px;
  font-weight: 600;
}


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
.skel-grid { display: grid; grid-template-columns: 1fr; gap: 14px; padding: 12px; }
.skel-card { background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,0.06); display: flex; flex-direction: row; }
.skel-card-img { width: 130px; height: 130px; flex-shrink: 0; margin: 0; border-radius: 0; }
.skel-card-body { padding: 16px; display: flex; flex-direction: column; gap: 12px; flex: 1; justify-content: center; }
.skel-card-title { height: 18px; width: 80%; border-radius: 6px; }
.skel-card-price { height: 18px; width: 50%; border-radius: 6px; }
.skel-circle { width: 42px; height: 42px; border-radius: 50%; }
.skel-nav-label { height: 10px; width: 50px; border-radius: 4px; margin: 4px auto 0; }
.skel-title-bar { display: inline-block; width: 140px; height: 22px; border-radius: 6px; vertical-align: middle; }

.checkout-bar {
  position: fixed; bottom: 0; left: 50%; transform: translateX(-50%);
  width: 100%; max-width: 425px;
  background: linear-gradient(135deg, #e17055, #d63031);
  display: none; justify-content: space-between; align-items: center;
  padding: 14px 18px; z-index: 100;
  box-shadow: 0 -2px 12px rgba(0,0,0,0.15);
}
@media (min-width: 768px) {
  .checkout-bar { border-radius: 0 0 28px 28px; }
}
.checkout-bar.show { display: flex; }
.checkout-bar .total-label { color: #fff; font-weight: 700; font-size: 15px; }
.checkout-bar .checkout-btn {
  color: #fff; font-weight: 700; font-size: 15px;
  background: none; border: none; cursor: pointer;
  display: flex; align-items: center; gap: 6px;
  font-family: 'Poppins', sans-serif;
}
.checkout-bar .checkout-btn:hover { opacity: .85; }
.checkout-bar .checkout-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.veg-switch { position:relative; display:inline-block; width:40px; height:22px; }
.veg-switch input { opacity:0; width:0; height:0; }
.veg-slider { position:absolute; top:0; left:0; right:0; bottom:0; background:#ccc; border-radius:22px; transition:.3s; cursor:pointer; }
.veg-slider:before { content:""; position:absolute; width:18px; height:18px; left:2px; bottom:2px; background:#fff; border-radius:50%; transition:.3s; box-shadow:0 1px 3px rgba(0,0,0,0.2); }
.veg-switch input:checked + .veg-slider { background:#22c55e; }
.veg-switch input:checked + .veg-slider:before { transform:translateX(18px); }

/* Filter Dropdown */
.filter-dropdown {
  position: relative;
}
.filter-menu {
  display: none;
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  z-index: 200;
  background: #fff;
  border: 1.5px solid #e0e0e0;
  border-radius: 12px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.12);
  min-width: 200px;
  overflow: hidden;
  animation: fadeIn 0.15s ease;
}
.filter-menu.show {
  display: block;
}
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}
.filter-option {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  font-size: 13px;
  font-weight: 500;
  color: #1a1b1f;
  cursor: pointer;
  transition: background 0.15s;
  border-bottom: 1px solid #f0f0f0;
  font-family: 'Poppins', sans-serif;
}
.filter-option:last-child {
  border-bottom: none;
}
.filter-option:hover {
  background: #f8f8f8;
}
.filter-option.active {
  background: #fdf2f2;
  color: #2d3436;
  font-weight: 600;
}
.filter-option i {
  width: 16px;
  text-align: center;
  font-size: 12px;
}

/* Bottom Sheet Product Detail Modal */
.product-sheet-overlay {
  display: none;
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.5);
  z-index: 6000;
  align-items: flex-end;
  justify-content: center;
}
.product-sheet-overlay.active {
  display: flex;
}
.product-sheet {
  background: #fff;
  width: 100%;
  max-width: 425px;
  max-height: 90vh;
  border-radius: 24px 24px 0 0;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  animation: sheetSlideUp 0.35s ease;
  position: relative;
}
@keyframes sheetSlideUp {
  from { transform: translateY(100%); }
  to { transform: translateY(0); }
}
.product-sheet-drag {
  width: 40px;
  height: 4px;
  background: #ddd;
  border-radius: 2px;
  margin: 8px auto;
  flex-shrink: 0;
}
.product-sheet-close {
  position: absolute;
  top: 12px;
  right: 12px;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: rgba(0,0,0,0.4);
  color: #fff;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  z-index: 10;
}
.product-sheet-img {
  width: 100%;
  height: 240px;
  object-fit: contain;
  background: #f5f5f5;
  flex-shrink: 0;
  display: block;
}
.product-sheet-body {
  padding: 16px 20px;
  overflow-y: auto;
  flex: 1;
}
.product-sheet-veg {
  width: 20px; height: 20px;
  border: 2px solid #2ecc40;
  border-radius: 4px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.product-sheet-veg-inner {
  width: 10px; height: 10px;
  background: #2ecc40;
  border-radius: 50%;
}
.product-sheet-name-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.product-sheet-name {
  font-size: 20px;
  font-weight: 700;
  color: #1a1b1f;
}
.product-sheet-price {
  font-size: 24px;
  font-weight: 800;
  color: #2d3436;
  margin: 10px 0 6px;
}
.product-sheet-desc {
  font-size: 13px;
  color: #666;
  line-height: 1.7;
  margin: 8px 0 16px;
}
.product-sheet-section-title {
  font-size: 11px;
  font-weight: 700;
  color: #999;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 10px;
}
.variant-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 20px;
}
.variant-chip {
  padding: 8px 18px;
  border-radius: 20px;
  border: 1.5px solid #ddd;
  background: #fff;
  font-size: 13px;
  font-weight: 500;
  color: #1a1b1f;
  cursor: pointer;
  transition: all 0.2s ease;
  font-family: 'Poppins', sans-serif;
}
.variant-chip.selected {
  border-color: #2d3436;
  background: #fdf2f2;
  color: #2d3436;
  font-weight: 600;
  box-shadow: 0 2px 8px rgba(45,52,54,0.15);
}
.variant-chip.disabled { opacity: 0.4; cursor: not-allowed; background: #f5f5f5; }
.product-sheet-footer {
  padding: 12px 20px 20px;
  border-top: 1px solid #eee;
  flex-shrink: 0;
}
.sheet-add-btn {
  width: 100%;
  padding: 14px;
  border: none;
  border-radius: 12px;
  background: linear-gradient(135deg, #e17055, #d63031);
  color: #fff;
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;
  font-family: 'Poppins', sans-serif;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: opacity 0.2s;
}
.sheet-add-btn:active {
  opacity: 0.85;
}
.sheet-qty-row {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
}
.sheet-qty-btn {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  border: none;
  background: linear-gradient(135deg, #e17055, #d63031);
  color: #fff;
  font-size: 20px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'Poppins', sans-serif;
}
.sheet-qty-btn:active {
  opacity: 0.8;
}
.sheet-qty-num {
  font-size: 22px;
  font-weight: 700;
  color: #1a1b1f;
  min-width: 40px;
  text-align: center;
}
.no-scroll {
  overflow: hidden !important;
}

@media (max-width: 425px) {
  .product-sheet { max-height: 92vh; border-radius: 20px 20px 0 0; }
  .product-sheet-img { height: 200px; }
  .product-sheet-body { padding: 14px 16px; }
  .product-sheet-name { font-size: 18px; }
  .product-sheet-price { font-size: 20px; }
}

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
  background: #2d3436;
  color: #fff;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  font-family: 'Poppins', sans-serif;
  transition: opacity 0.2s;
}
.closed-overlay-inner button:hover { opacity: 0.9; }
.toast-notification {
  position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%);
  padding: 12px 24px; border-radius: 12px; font-size: 13px; font-weight: 500;
  z-index: 99999; font-family: 'Poppins', sans-serif;
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

/* Subcategory Tabs */
.subcategory-tabs {
  display: flex;
  gap: 8px;
  padding: 8px 2px 4px;
  overflow-x: auto;
  -ms-overflow-style: none;
  scrollbar-width: none;
  white-space: nowrap;
}
.subcategory-tabs::-webkit-scrollbar { display: none; }
.subcategory-tab {
  flex-shrink: 0;
  padding: 6px 16px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
  font-family: 'Poppins', sans-serif;
  border: 1.5px solid #ddd;
  background: #fff;
  color: #1a1b1f;
  cursor: pointer;
  transition: all 0.2s;
  white-space: nowrap;
}
.subcategory-tab:hover {
  border-color: #846241;
  color: #e17055;
}
.subcategory-tab.active {
  background: linear-gradient(135deg, #e17055, #d63031);
  color: #fff;
  border-color: transparent;
}
</style>
<script>
window.websiteRestaurantId = <?php echo json_encode($restaurant_id ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.websiteRestaurantSlug = <?php echo json_encode($restaurant_slug ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.websiteTableNumber = <?php echo json_encode($qr_table ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
// Restore table from sessionStorage if URL param missing but previously set (e.g., coming back from cart)
(function() {
  if (!window.websiteTableNumber) {
    var savedTable = sessionStorage.getItem('qrTable') || '';
    if (savedTable) {
      window.websiteTableNumber = savedTable;
    }
  }
})();

function goBack() {
  var baseUrl = '<?php echo restaurantPageUrl(); ?>';
  var tbl = window.websiteTableNumber || sessionStorage.getItem('qrTable') || '';
  if (tbl) {
    baseUrl += (baseUrl.indexOf('?') > -1 ? '&' : '?') + 'table=' + encodeURIComponent(tbl);
  }
  window.location.href = baseUrl;
}
window.globalCurrencySymbol = <?php echo json_encode($currency_symbol ?? '₹', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
window.restaurantCountry = <?php echo json_encode($country ?? 'India', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
window.restaurantDialCode = <?php echo json_encode($phone_dial_code ?? '+91', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.restaurantPhoneMin = <?php echo json_encode((int)($phone_min_digits ?? 10), JSON_HEX_TAG); ?>;
window.restaurantPhoneMax = <?php echo json_encode((int)($phone_max_digits ?? 10), JSON_HEX_TAG); ?>;
window.restaurantOpen = <?php echo json_encode($restaurant_open, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.openingHours = <?php echo json_encode($opening_hours ? json_decode($opening_hours, true) : null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
window.minimumOrderValue = <?php echo json_encode((float)$minimum_order_value, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.packagingCharge = <?php echo json_encode((float)$packaging_charge, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.showInstallApp = <?php echo json_encode($show_install_app, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.restaurantTimezoneOffset = <?php echo json_encode($timezone_offset_minutes ?? 330, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
</head>
<body>
<div class="phone-frame">
  <div class="bg-wrapper">
    <div class="pr-share-header">
      <button class="back-btn-cmon" onclick="goBack()"><i class="fa fa-arrow-circle-left"></i></button>
      <?php if ($restaurant_logo): ?>
      <img src="<?php echo htmlspecialchars($restaurant_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" style="width:36px;height:36px;border-radius:<?php echo ($logo_shape ?? 'circle') === 'square' ? '8' : '50'; ?>%;object-fit:cover;flex-shrink:0">
      <?php endif; ?>
      <div class="header-actions">
        <span style="display:flex;align-items:center;gap:6px;flex-shrink:0;cursor:pointer;background:#f0fdf4;padding:2px 10px 2px 8px;border-radius:20px;border:1px solid #bbf7d0">
          <span style="font-size:12px;font-weight:600;color:#16a34a">Veg</span>
          <label class="veg-switch"><input type="checkbox" id="vegToggle" onchange="toggleVeg()"><span class="veg-slider"></span></label>
        </span>
        <div class="filter-dropdown">
          <button class="header-action-btn" id="filterBtn" onclick="toggleFilterDropdown(event)"><i class="fa-solid fa-arrow-up-wide-short"></i></button>
          <div class="filter-menu" id="filterMenu">
            <div class="filter-option" data-sort="" onclick="setSortOrder('')"><i class="fa fa-times" style="color:#999"></i> Clear Filter</div>
            <div class="filter-option" data-sort="low-to-high" onclick="setSortOrder('low-to-high')"><i class="fa fa-arrow-up" style="color:#1a3934"></i> Price: Low to High</div>
            <div class="filter-option" data-sort="high-to-low" onclick="setSortOrder('high-to-low')"><i class="fa fa-arrow-down" style="color:#1a3934"></i> Price: High to Low</div>
            <div class="filter-divider" style="display:none;height:1px;background:#eee;margin:6px 10px"></div>
            <div class="filter-option deal-filter" data-deal="combo" onclick="setDealFilter('combo')" style="display:none"><i class="fa fa-fire" style="color:#e53935"></i> Combo Deals</div>
            <div class="filter-option deal-filter" data-deal="new" onclick="setDealFilter('new')" style="display:none"><i class="fa fa-star" style="color:#f59e0b"></i> New Deals</div>
            <div class="filter-option deal-filter" data-deal="clear" onclick="setDealFilter('clear')" style="display:none"><i class="fa fa-times" style="color:#999"></i> Clear Deal Filter</div>
          </div>
        </div>
        <button class="header-action-btn" onclick="shareRestaurant()"><i class="fa-solid fa-share-nodes"></i></button>
      </div>
    </div>

    <div class="search-rapper">
      <div class="search-container">
        <input type="text" class="search-input" placeholder="Search your favorite food fast ⚡">
        <button type="button" class="search-btn"><i class="bi bi-search"></i></button>
      </div>
    </div>

    <div class="layout-container">
      <div class="side-nav" id="sideNav">
        <div class="sub-views"><button class="side-nav-btn" disabled><div class="skel-circle skeleton"></div><div class="skel-nav-label skeleton"></div></button></div>
        <div class="sub-views"><button class="side-nav-btn" disabled><div class="skel-circle skeleton"></div><div class="skel-nav-label skeleton"></div></button></div>
        <div class="sub-views"><button class="side-nav-btn" disabled><div class="skel-circle skeleton"></div><div class="skel-nav-label skeleton"></div></button></div>
        <div class="sub-views"><button class="side-nav-btn" disabled><div class="skel-circle skeleton"></div><div class="skel-nav-label skeleton"></div></button></div>
        <div class="sub-views"><button class="side-nav-btn" disabled><div class="skel-circle skeleton"></div><div class="skel-nav-label skeleton"></div></button></div>
      </div>
      <div class="main-content">
        <div class="category-overview">
          <h2 id="categoryTitle"><span class="skel-title-bar skeleton"></span></h2>
          <div class="subcategory-tabs" id="subcategoryTabs" style="display:none"></div>
        </div>
        <div class="product-grid cols-2" id="productGrid">
          <div class="skel-grid">
            <div class="skel-card"><div class="skel-card-img skeleton"></div><div class="skel-card-body"><div class="skel-card-title skeleton"></div><div class="skel-card-price skeleton"></div></div></div>
            <div class="skel-card"><div class="skel-card-img skeleton"></div><div class="skel-card-body"><div class="skel-card-title skeleton"></div><div class="skel-card-price skeleton"></div></div></div>
            <div class="skel-card"><div class="skel-card-img skeleton"></div><div class="skel-card-body"><div class="skel-card-title skeleton"></div><div class="skel-card-price skeleton"></div></div></div>
            <div class="skel-card"><div class="skel-card-img skeleton"></div><div class="skel-card-body"><div class="skel-card-title skeleton"></div><div class="skel-card-price skeleton"></div></div></div>
          </div>
        </div>
      </div>
    </div>

  <div class="checkout-bar" id="checkoutBar">
    <span class="total-label" id="checkoutLabel"><?php echo htmlspecialchars($currency_symbol ?? '₹'); ?>0.00</span>
    <button class="checkout-btn" id="checkoutBtn" onclick="goToCheckout()">Checkout <i class="fa fa-arrow-right"></i></button>
  </div>
</div>

</div>

<!-- Product Detail Bottom Sheet -->
<div class="product-sheet-overlay" id="productSheetOverlay" onclick="closeProductSheet(event)">
  <div class="product-sheet" id="productSheet" onclick="event.stopPropagation()">
    <div class="product-sheet-drag"></div>
    <button class="product-sheet-close" onclick="closeProductSheet()"><i class="fa fa-times"></i></button>
    <div id="productSheetContent"></div>
  </div>
</div>

<script>
var cartItems = {};
var menus = [];
var menuItems = [];
var rawMenuItems = [];
var vegOnly = false;
var currentMenuIdx = 0;
var currentSort = '';
var currentSubcategoryId = null;
var addonsList = [];

// --- Add-on System ---
function fetchAddons() {
  var rid = window.websiteRestaurantId || 'RES001';
  fetch(apiUrl('getAddons')).then(function(r){return r.json()}).then(function(data){
    if (data.success && data.data) {
      addonsList = data.data;
    }
  }).catch(function(){});
}

function getSelectedAddons() {
  try { var s = localStorage.getItem('_itemAddons'); return s ? JSON.parse(s) : {}; } catch(e){return {};}
}

function saveSelectedAddons(addonMap) {
  try { localStorage.setItem('_itemAddons', JSON.stringify(addonMap)); } catch(e){}
}

function getAddonsForItemKey(key) {
  var all = getSelectedAddons();
  return all[key] || [];
}

function getAddonTotalForItem(key) {
  var addons = getAddonsForItemKey(key);
  var total = 0;
  for (var a = 0; a < addons.length; a++) {
    total += parseFloat(addons[a].price || 0);
  }
  return total;
}

function getAddonNamesString(key) {
  var addons = getAddonsForItemKey(key);
  var names = [];
  for (var a = 0; a < addons.length; a++) {
    names.push(addons[a].name);
  }
  return names.length ? '+ ' + names.join(', ') : '';
}

function clearAddonsForItem(key) {
  var all = getSelectedAddons();
  delete all[key];
  saveSelectedAddons(all);
}

function updateAddonForItem(key, addonId, addonName, addonPrice, selected) {
  var all = getSelectedAddons();
  if (!all[key]) all[key] = [];
  if (selected) {
    // Check if already exists
    var found = false;
    for (var i = 0; i < all[key].length; i++) {
      if (all[key][i].id == addonId) { found = true; break; }
    }
    if (!found) {
      all[key].push({id: addonId, name: addonName, price: addonPrice});
    }
  } else {
    all[key] = all[key].filter(function(a){ return a.id != addonId; });
    if (all[key].length === 0) delete all[key];
  }
  saveSelectedAddons(all);
}

var API_BASE = 'api.php';

function apiUrl(action, extra) {
  var p = API_BASE + '?action=' + action + '&restaurant_id=' + encodeURIComponent(window.websiteRestaurantId || 'RES001');
  if (extra) p += '&' + extra;
  return p;
}

function getImageUrl(img) {
  if (!img || img === '' || img === 'no-image') return 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22150%22 height=%22150%22 viewBox=%220 0 150 150%22%3E%3Crect width=%22100%25%22 height=%22100%25%22 fill=%22%23f0f0f0%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 font-family=%22Arial%22 font-size=%2212%22 fill=%22%23999%22 text-anchor=%22middle%22 dy=%22.3em%22%3ENo Image%3C/text%3E%3C/svg%3E';
  if (img.indexOf('http://') === 0 || img.indexOf('https://') === 0) return img;
  var ts = Date.now(); // cache-bust
  if (img.indexOf('db:') === 0) return 'image.php?id=' + encodeURIComponent(img.substring(3)) + '&_=' + ts;
  return 'image.php?id=' + encodeURIComponent(img) + '&_=' + ts;
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

function formatPrice(price) {
  return getCurrency() + parseFloat(price).toFixed(2);
}

function getQty(itemId) {
  return cartItems[itemId] || 0;
}

function toggleFilterDropdown(e) {
  e.stopPropagation();
  var menu = document.getElementById('filterMenu');
  menu.classList.toggle('show');
}

document.addEventListener('click', function(e) {
  var menu = document.getElementById('filterMenu');
  var btn = document.getElementById('filterBtn');
  if (menu && btn && !menu.contains(e.target) && !btn.contains(e.target)) {
    menu.classList.remove('show');
  }
});

function setSortOrder(order) {
  currentSort = order;
  // Update active state on filter options
  var options = document.querySelectorAll('.filter-option');
  for (var i = 0; i < options.length; i++) {
    options[i].classList.toggle('active', options[i].dataset.sort === order);
  }
  // Close dropdown
  document.getElementById('filterMenu').classList.remove('show');
  // Re-render current category with sort
  renderProducts(currentMenuIdx);
}

function getItemTotalQty(itemId) {
  var total = 0;
  var item = getItemModelById(itemId);
  var hasVar = item && item.has_variations && item.variations && item.variations.length > 0;
  for (var k in cartItems) {
    var id = parseInt(k.split('_v')[0]);
    if (id === itemId) {
      var v = cartItems[k];
      if (typeof v === 'number') {
        // Skip stale numeric entries for variation items (left before the menu fix)
        if (!hasVar) total += v;
      } else {
        total += v.qty || 0;
      }
    }
  }
  return total;
}

function getMinVariantPrice(item) {
  if (!item.variations || !item.variations.length) return null;
  var min = Infinity;
  for (var v = 0; v < item.variations.length; v++) {
    if (item.variations[v].is_available == 0) continue;
    var p = parseFloat(item.variations[v].variation_price || item.variations[v].price || 0);
    if (p < min) min = p;
  }
  return min === Infinity ? null : min;
}

function getItemModelById(itemId) {
  for (var m = 0; m < menuItems.length; m++) {
    var items = menuItems[m].items || [];
    for (var i = 0; i < items.length; i++) {
      if (items[i].id == itemId) return items[i];
    }
  }
  return null;
}

function renderCartBtn(itemId, item) {
  if (item && item.is_available == 0) return '';
  var hasVar = item && item.has_variations && item.variations && item.variations.length > 0;
  var qty = getItemTotalQty(itemId);
  if (qty === 0 || hasVar) {
    return '<button class="add-btn" onclick="event.stopPropagation();showItemDetail(' + itemId + ')">Add To Cart</button>';
  }
  return '<div class="qty-inline">' +
    '<button onclick="event.stopPropagation();removeFromCart(' + itemId + ')"><i class="fa fa-minus"></i></button>' +
    '<span class="qty-num">' + qty + '</span>' +
    '<button onclick="event.stopPropagation();addToCart(' + itemId + ')"><i class="fa fa-plus"></i></button>' +
  '</div>';
}

function addToCart(itemId, varInfo) {
  var item = getItemModelById(itemId);
  if (item && item.is_available == 0) return;
  if (varInfo) {
    var key = itemId + '_v' + varInfo.varIdx;
    var existing = cartItems[key];
    if (existing && typeof existing === 'object') {
      existing.qty = (existing.qty || 0) + 1;
    } else {
      cartItems[key] = { qty: 1, varName: varInfo.varName, varPrice: varInfo.varPrice, varIdx: varInfo.varIdx };
    }
  } else {
    cartItems[itemId] = (cartItems[itemId] || 0) + 1;
  }
  var el = document.getElementById('btn-' + itemId);
  if (el) el.innerHTML = renderCartBtn(itemId, getItemModelById(itemId));
  saveCart();
  updateCheckoutBar();
}

function removeFromCart(itemId, varIdx) {
  if (varIdx !== undefined && varIdx !== null) {
    var key = itemId + '_v' + varIdx;
    if (cartItems[key]) {
      if (typeof cartItems[key] === 'object') {
        cartItems[key].qty = (cartItems[key].qty || 0) - 1;
        if (cartItems[key].qty <= 0) delete cartItems[key];
      } else {
        delete cartItems[key];
      }
    }
  } else {
    if (cartItems[itemId] > 0) {
      if (typeof cartItems[itemId] === 'number') {
        cartItems[itemId]--;
        if (cartItems[itemId] <= 0) delete cartItems[itemId];
      } else {
        delete cartItems[itemId];
      }
    }
  }
  var el = document.getElementById('btn-' + itemId);
  if (el) el.innerHTML = renderCartBtn(itemId, getItemModelById(itemId));
  saveCart();
  updateCheckoutBar();
}

function saveCart() {
  try { localStorage.setItem('dvaniCart', JSON.stringify(cartItems)); } catch(e) {}
}

function loadCart() {
  try {
    var saved = localStorage.getItem('dvaniCart');
    if (saved) { cartItems = JSON.parse(saved); }
  } catch(e) {}
}

function cleanCart() {
  // Remove cart entries for items that no longer exist in the loaded menu
  var changed = false;
  for (var k in cartItems) {
    if (k.indexOf('addon_') === 0) continue;
    var raw = cartItems[k];
    if (typeof raw !== 'number' && (!raw || typeof raw !== 'object' || raw.isAddon)) continue;
    var item = getItemByKey(k);
    if (!item) {
      delete cartItems[k];
      changed = true;
    }
  }
  if (changed) saveCart();
}

function updateCheckoutBar() {
  var bar = document.getElementById('checkoutBar');
  var label = document.getElementById('checkoutLabel');
  var btn = document.getElementById('checkoutBtn');
  var content = document.querySelector('.main-content');
  if (!bar || !label || !btn) return;
  var totalQty = 0, totalPrice = 0;
  var keys = Object.keys(cartItems).filter(function(k) { return typeof cartItems[k] === 'object' || cartItems[k] > 0; });
  for (var i = 0; i < keys.length; i++) {
    var key = keys[i];
    var raw = cartItems[key];
    if (typeof raw === 'number') {
      var item = getItemByKey(key);
      if (item) {
        // For items with variations, skip stale numeric entries (left over from before fix)
        var hasVar = item && item.has_variations && item.variations && item.variations.length > 0;
        if (!hasVar) {
          totalQty += raw;
          totalPrice += parseFloat(item.base_price || item.price || 0) * raw;
        }
      }
    } else if (raw && typeof raw === 'object') {
      if (raw.isAddon) {
        // Skip add-on items for the count, include their price in total
        totalPrice += (raw.addonPrice || 0) * (raw.qty || 0);
      } else {
        totalQty += raw.qty || 0;
        totalPrice += (raw.varPrice || 0) * (raw.qty || 0);
      }
    }
  }
  if (totalQty > 0) {
    bar.classList.add('show');
    if (content) content.classList.add('has-cart');
    label.textContent = (window.globalCurrencySymbol || '₹') + totalPrice.toFixed(2) + ' (' + totalQty + ' Items)';
    btn.disabled = false;
  } else {
    bar.classList.remove('show');
    if (content) content.classList.remove('has-cart');
  }
}

function goToCheckout() {
  var url = '<?php echo restaurantPageUrl('cart'); ?>';
  var tbl = window.websiteTableNumber || '';
  if (tbl) {
    url += (url.indexOf('?') > -1 ? '&' : '?') + 'table=' + encodeURIComponent(tbl);
  }
  window.location.href = url;
}

function getItemByKey(key) {
  var itemId = parseInt(key.split('_v')[0]);
  for (var i = 0; i < menuItems.length; i++) {
    var items = menuItems[i].items || [];
    for (var j = 0; j < items.length; j++) {
      if (items[j].id == itemId) return items[j];
    }
  }
  return null;
}

function getTypeIcon(type) {
  if (!type) return '';
  if (type === 'Veg') {
    return '<div class="veg-dot"><img src="https://knowwu.s3.us-east-2.amazonaws.com/staging/main/restaurant/veg.png" alt="Veg"></div>';
  }
  if (type === 'Non Veg') {
    return '<div class="veg-dot"><svg width="14" height="14" viewBox="0 0 14 14"><rect x="1" y="1" width="12" height="12" fill="#fff" stroke="#e53935" stroke-width="0.8"/><circle cx="7" cy="7" r="4" fill="#e53935"/></svg></div>';
  }
  if (type === 'Egg') {
    return '<div class="veg-dot"><svg width="14" height="14" viewBox="0 0 14 14"><rect x="1" y="1" width="12" height="12" fill="#fff" stroke="#ff9800" stroke-width="0.8"/><circle cx="7" cy="7" r="4" fill="#ff9800"/></svg></div>';
  }
  return '';
}

function renderProducts(menuIdx) {
  var items = menuItems[menuIdx] ? [].concat(menuItems[menuIdx].items) : [];
  var menuName = menuItems[menuIdx] ? menuItems[menuIdx].name : 'Menu';
  var el = document.getElementById('productGrid');
  var titleEl = document.getElementById('categoryTitle');

  // Render subcategory tabs
  renderSubcategoryTabs(menuIdx);

  // Filter by subcategory if one is selected
  if (currentSubcategoryId !== null) {
    items = items.filter(function(item) {
      return item.subcategory_id == currentSubcategoryId;
    });
  }

  // Apply sorting
  if (currentSort === 'low-to-high') {
    items.sort(function(a, b) {
      return parseFloat(a.base_price || a.price || 0) - parseFloat(b.base_price || b.price || 0);
    });
  } else if (currentSort === 'high-to-low') {
    items.sort(function(a, b) {
      return parseFloat(b.base_price || b.price || 0) - parseFloat(a.base_price || a.price || 0);
    });
  }

  var label = menuName;
  if (currentSubcategoryId !== null) {
    // Find the subcategory name for the title
    for (var i = 0; i < menus.length; i++) {
      if (menus[i].id == menuItems[menuIdx]?.id && menus[i].subcategories) {
        for (var j = 0; j < menus[i].subcategories.length; j++) {
          if (menus[i].subcategories[j].id == currentSubcategoryId) {
            label = escapeHtml(menus[i].subcategories[j].subcategory_name_translated || menus[i].subcategories[j].subcategory_name);
            break;
          }
        }
        break;
      }
    }
  }
  titleEl.textContent = label + ' (' + items.length + ')';
    var html = '';
  for (var i = 0; i < items.length; i++) {
    var item = items[i];
    var hasVar = item.has_variations && item.variations && item.variations.length > 0;
    var displayPrice = hasVar ? 'From ' + formatPrice(getMinVariantPrice(item)) : formatPrice(item.base_price || item.price || 0);
    var oos = item.is_available == 0;
    html += '<div class="card' + (oos ? ' oos' : '') + '" data-itemId="' + item.id + '" onclick="if(!' + oos + ')showItemDetail(' + item.id + ')">' +
      '<div class="card-img-wrap">' +
        '<img src="' + getImageUrl(item.item_image || item.image) + '" alt="' + (item.item_name_translated || item.item_name_en || item.name) + '" loading="lazy">' +
        getTypeIcon(item.item_type) +
      '</div>' +
      '<div class="card-body">' +
        '<div class="card-name">' + (item.item_name_translated || item.item_name_en || item.name) + (oos ? ' <span class="card-oos-badge">Out of Stock</span>' : '') + '</div>' +
        '<div class="card-price">' + displayPrice + '</div>' +
        (!oos ? '<span id="btn-' + item.id + '">' + renderCartBtn(item.id, item) + '</span>' : '') +
      '</div>' +
    '</div>';
  }
  el.innerHTML = html;
  updateNavActive(menuIdx);
}

function showItemDetail(itemId) {
  var item = null;
  for (var m = 0; m < menuItems.length && !item; m++) {
    for (var i = 0; i < (menuItems[m] ? menuItems[m].items.length : 0); i++) {
      if (menuItems[m].items[i].id == itemId) {
        item = menuItems[m].items[i];
        break;
      }
    }
  }
  if (!item) return;

  sheetItemId = item.id;
  sheetQty = getQty(item.id) || 1;
  selectedVariant = null;

  var imgUrl = getImageUrl(item.item_image || item.image);
  var itemName = item.item_name_translated || item.item_name_en || item.name;
  var itemDesc = item.item_description_en || item.desc || '';
  var descFmt = item.description_format || 'paragraph';
  var itemPrice = parseFloat(item.base_price || item.price || 0);
  var hasVariants = item.has_variations && item.variations && item.variations.length > 0;
  var minVarPrice = hasVariants ? getMinVariantPrice(item) : null;
  if (hasVariants && minVarPrice !== null) itemPrice = minVarPrice;
  var itemType = item.item_type || '';
  var prepTime = item.preparation_time || '';
  var itemCalories = item.calories || 0;
  var menuName = item.menu_name_translated || item.menu_name || '';

  var html = '';

  // Image
  html += '<img class="product-sheet-img" src="' + imgUrl + '" alt="' + itemName + '" onerror="this.style.display=\'none\'">';

  // Body
  html += '<div class="product-sheet-body">';

  // Name with dynamic type icon (Veg / Non-Veg / Egg)
  var typeColor = itemType ? '#2ecc40' : '';
  if (itemType.toLowerCase().indexOf('non') !== -1) {
    typeColor = '#e53935';
  } else if (itemType.toLowerCase().indexOf('egg') !== -1) {
    typeColor = '#ff9800';
  }
  html += '<div class="product-sheet-name-row">';
  if (itemType) {
    var typeColor = itemType ? '#2ecc40' : '';
    if (itemType.toLowerCase().indexOf('non') !== -1) {
      typeColor = '#e53935';
    } else if (itemType.toLowerCase().indexOf('egg') !== -1) {
      typeColor = '#ff9800';
    }
    html += '<div class="product-sheet-veg" style="border-color:' + typeColor + '"><div class="product-sheet-veg-inner" style="background:' + typeColor + '"></div></div>';
  }
  html += '<span class="product-sheet-name">' + itemName + '</span>' +
'</div>';

  // Badge row: prep time, type label, menu name
  var badges = [];
  if (prepTime) {
    badges.push('<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;background:#fdf2f2;font-size:11px;font-weight:500;color:#e17055"><i class="fa fa-clock" style="font-size:10px"></i> ' + prepTime + ' min</span>');
  }
  if (itemCalories > 0) {
    badges.push('<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;background:#fef3c7;font-size:11px;font-weight:500;color:#d97706"><i class="fa fa-fire" style="font-size:10px"></i> ' + itemCalories + ' kcal</span>');
  }
  if (itemType) {
    var typeBadgeColor = '#2ecc40';
    if (itemType.toLowerCase().indexOf('non') !== -1) typeBadgeColor = '#e53935';
    else if (itemType.toLowerCase().indexOf('egg') !== -1) typeBadgeColor = '#ff9800';
    badges.push('<span style="display:inline-flex;align-items:center;gap:3px;padding:4px 10px;border-radius:6px;background:' + typeBadgeColor + '20;font-size:11px;font-weight:500;color:' + typeBadgeColor + '">' + itemType + '</span>');
  }
  if (menuName) {
    badges.push('<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;background:#e8f0fe;font-size:11px;font-weight:500;color:#1a73e8"><i class="fa fa-tag" style="font-size:10px"></i> ' + menuName + '</span>');
  }
  if (badges.length > 0) {
    html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 6px">' + badges.join('') + '</div>';
  }

  html += '<div class="product-sheet-price" id="sheetPrice">' + formatPrice(itemPrice) + '</div>';

  if (itemDesc) {
    var safeDesc = escapeHtml(itemDesc);
    var displayDesc = descFmt === 'br' ? safeDesc.replace(/\n/g, '<br>') : safeDesc;
    html += '<div class="product-sheet-desc">' + displayDesc + '</div>';
  }

  // Variants
  if (hasVariants) {
    html += '<div class="product-sheet-section-title">Choose a Variation</div>';
    html += '<div class="variant-chips" id="variantChips">';
    for (var v = 0; v < item.variations.length; v++) {
      var varItem = item.variations[v];
      var varName = varItem.variation_name || varItem.name || 'Option ' + (v + 1);
      var varPrice = parseFloat(varItem.variation_price || varItem.price || 0);
      var varOos = varItem.is_available == 0;
      html += '<div class="variant-chip' + (varOos ? ' disabled' : '') + '" data-idx="' + v + '"' + (varOos ? '' : ' onclick="selectVariant(' + v + ')') + '\">' +
        escapeHtml(varName) +
        (varOos ? ' <span style="color:#999;font-weight:400;font-size:10px">(Unavailable)</span>' : ' <span style="color:#e17055;font-weight:600">' + formatPrice(varPrice) + '</span>') +
      '</div>';
    }
    html += '</div>';
  }

  html += '</div>'; // end body

  // Footer with Add to Cart / Qty
  html += '<div class="product-sheet-footer">';
  var oos = item.is_available == 0;
  if (oos) {
    html += '<div style="width:100%;padding:14px 0;text-align:center;color:#e74c3c;font-weight:600;font-size:14px">Currently Out of Stock</div>';
  } else {
    var existingQty = getItemTotalQty(item.id);
    if (existingQty > 0) {
      html += '<div class="sheet-qty-row">' +
        '<button class="sheet-qty-btn" onclick="sheetChangeQty(' + item.id + ', -1)"><i class="fa fa-minus"></i></button>' +
        '<span class="sheet-qty-num" id="sheetQtyNum">' + existingQty + '</span>' +
        '<button class="sheet-qty-btn" onclick="sheetChangeQty(' + item.id + ', 1)"><i class="fa fa-plus"></i></button>' +
      '</div>';
    } else {
      html += '<button class="sheet-add-btn" onclick="sheetAddToCart(' + item.id + ')">Add to Cart · <span id="sheetBtnPrice">' + formatPrice(itemPrice) + '</span></button>';
    }
  }
  html += '</div>';

  document.getElementById('productSheetContent').innerHTML = html;
  document.getElementById('productSheetOverlay').classList.add('active');
  document.body.classList.add('no-scroll');
}

// --- Bottom Sheet Helpers ---
var selectedVariant = null;
var sheetQty = 1;
var sheetItemId = null;

function closeProductSheet(e) {
  if (e && e.target !== e.currentTarget) return;
  document.getElementById('productSheetOverlay').classList.remove('active');
  document.body.classList.remove('no-scroll');
  selectedVariant = null;
  sheetQty = 1;
  sheetItemId = null;
}

function getSheetItem() {
  if (!sheetItemId) return null;
  for (var m = 0; m < menuItems.length; m++) {
    for (var i = 0; i < (menuItems[m] ? menuItems[m].items.length : 0); i++) {
      if (menuItems[m].items[i].id == sheetItemId) {
        return menuItems[m].items[i];
      }
    }
  }
  return null;
}

function selectVariant(idx) {
  var item = getSheetItem();
  if (!item || !item.variations || !item.variations[idx]) return;

  selectedVariant = idx;
  var chips = document.querySelectorAll('.variant-chip');
  for (var i = 0; i < chips.length; i++) {
    chips[i].classList.toggle('selected', i === idx);
  }

  var varPrice = parseFloat(item.variations[idx].variation_price || item.variations[idx].price || 0);
  updateSheetPrice(varPrice, item.id);
}

function toggleAddonChip(itemId, addonId, addonName, addonPrice) {
  var key = String(itemId);
  // Determine the actual cart key for this item
  var item = getSheetItem();
  if (item && item.has_variations && item.variations && item.variations.length > 0) {
    if (selectedVariant !== null) {
      key = itemId + '_v' + selectedVariant;
    } else {
      // No variant selected yet, can't add add-ons
      return;
    }
  }
  
  var chip = document.querySelector('.variant-chip[data-addon-id="' + addonId + '"]');
  if (!chip) return;
  var currentlySelected = chip.classList.contains('selected');
  
  if (currentlySelected) {
    chip.classList.remove('selected');
    updateAddonForItem(key, addonId, addonName, addonPrice, false);
  } else {
    chip.classList.add('selected');
    updateAddonForItem(key, addonId, addonName, addonPrice, true);
  }
  
  // Update the displayed price to include add-ons
  var itemPrice = 0;
  if (item && item.has_variations && item.variations && item.variations.length > 0 && selectedVariant !== null) {
    itemPrice = parseFloat(item.variations[selectedVariant].variation_price || item.variations[selectedVariant].price || 0);
  } else if (item) {
    itemPrice = parseFloat(item.base_price || item.price || 0);
  }
  updateSheetPrice(itemPrice, itemId);
}

function updateSheetPrice(basePrice, itemId) {
  var key = String(itemId);
  var item = getSheetItem();
  if (item && item.has_variations && item.variations && item.variations.length > 0 && selectedVariant !== null) {
    key = itemId + '_v' + selectedVariant;
  }
  var addonTotal = getAddonTotalForItem(key);
  var totalPrice = basePrice + addonTotal;
  document.getElementById('sheetPrice').textContent = formatPrice(totalPrice);
  var btnPrice = document.getElementById('sheetBtnPrice');
  if (btnPrice) btnPrice.textContent = formatPrice(totalPrice);
}

function sheetChangeQty(itemId, delta) {
  var item = getSheetItem();
  var hasVar = item && item.has_variations && item.variations && item.variations.length > 0;

  if (hasVar && selectedVariant !== null) {
    var key = itemId + '_v' + selectedVariant;
    var existing = cartItems[key];
    var newQty = Math.max(0, (existing && typeof existing === 'object' ? existing.qty || 0 : 0) + delta);
    if (newQty === 0) {
      delete cartItems[key];
    } else {
      cartItems[key] = { qty: newQty, varName: item.variations[selectedVariant].variation_name, varPrice: parseFloat(item.variations[selectedVariant].variation_price || item.variations[selectedVariant].price || 0), varIdx: selectedVariant };
    }
  } else {
    var currentQty = getItemTotalQty(itemId);
    var newQty = Math.max(0, currentQty + delta);
    if (newQty === 0) {
      delete cartItems[itemId];
    } else {
      cartItems[itemId] = newQty;
    }
  }
  saveCart();
  document.getElementById('sheetQtyNum').textContent = getItemTotalQty(itemId);
  var listEl = document.getElementById('btn-' + itemId);
  if (listEl) listEl.innerHTML = renderCartBtn(itemId, item);
  if (getItemTotalQty(itemId) === 0) closeProductSheet();
  updateCheckoutBar();
}

function sheetAddToCart(itemId) {
  var item = getSheetItem();
  if (!item) return;
  var hasVar = item.has_variations && item.variations && item.variations.length > 0;
  if (hasVar && selectedVariant === null) return;
  if (hasVar) {
    var v = item.variations[selectedVariant];
    addToCart(itemId, { varIdx: selectedVariant, varName: v.variation_name, varPrice: parseFloat(v.variation_price || v.price || 0) });
  } else {
    addToCart(itemId);
  }
  closeProductSheet();
}

function updateNavActive(menuIdx) {
  var btns = document.querySelectorAll('.side-nav-btn');
  for (var i = 0; i < btns.length; i++) {
    btns[i].classList.remove('active');
    if (parseInt(btns[i].dataset.index) === menuIdx) {
      btns[i].classList.add('active');
    }
  }
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function renderSubcategoryTabs(menuIdx) {
  var container = document.getElementById('subcategoryTabs');
  if (!container) return;
  var menuGroup = menuItems[menuIdx];
  if (!menuGroup) { container.style.display = 'none'; return; }
  var menuData = null;
  for (var i = 0; i < menus.length; i++) {
    if (menus[i].id == menuGroup.id) { menuData = menus[i]; break; }
  }
  var subs = (menuData && menuData.subcategories) || [];
  if (subs.length === 0) {
    container.style.display = 'none';
    return;
  }
  var html = '<button class="subcategory-tab"' + (currentSubcategoryId === null ? ' active' : '') + ' onclick="switchSubcategory(null)">All</button>';
  for (var i = 0; i < subs.length; i++) {
    var active = (subs[i].id == currentSubcategoryId) ? ' active' : '';
    html += '<button class="subcategory-tab' + active + '" onclick="switchSubcategory(' + subs[i].id + ')">' + escapeHtml(subs[i].subcategory_name_translated || subs[i].subcategory_name) + '</button>';
  }
  container.innerHTML = html;
  container.style.display = '';
}

function switchSubcategory(id) {
  currentSubcategoryId = id;
  renderProducts(currentMenuIdx);
}

function getMenuImage(cat) {
  if (cat.image) return cat.image;
  if (cat.items && cat.items.length > 0) {
    var first = cat.items[0];
    return first.item_image || first.image || '';
  }
  return '';
}

function renderSideNav() {
  var el = document.getElementById('sideNav');
  var html = '';
  for (var i = 0; i < menuItems.length; i++) {
    var c = menuItems[i];
    var active = i === 0 ? ' active' : '';
    var displayName = c.name.replace(/ \(.*\)/, '');
    var img = getMenuImage(c) || 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2242%22 height=%2242%22 viewBox=%220 0 42 42%22%3E%3Crect width=%22100%25%22 height=%22100%25%22 fill=%22%23f0f0f0%22/%3E%3C/svg%3E';
    html += '<div class="sub-views">' +
      '<button class="side-nav-btn' + active + '" data-index="' + i + '">' +
        '<img src="' + getImageUrl(img) + '" alt="' + displayName + '" loading="lazy"><br>' +
        displayName +
      '</button>' +
    '</div>';
  }
  // Add-ons side nav button
  html += '<div class="sub-views">' +
    '<button class="side-nav-btn" data-id="addons">' +
      '<img src="https://cdn-icons-png.flaticon.com/512/3144/3144456.png" alt="Add-ons" loading="lazy"><br>' +
      'Add-ons' +
    '</button>' +
  '</div>';
  el.innerHTML = html;
}

// --- Add-ons Grid Functions ---
function renderAddonsGrid() {
  var el = document.getElementById('productGrid');
  var titleEl = document.getElementById('categoryTitle');
  var availableAddons = addonsList.filter(function(a){ return a.is_available != 0; });
  titleEl.textContent = 'Add-ons (' + availableAddons.length + ')';
  document.getElementById('subcategoryTabs').style.display = 'none';
  
  var html = '';
  for (var i = 0; i < availableAddons.length; i++) {
    var addon = availableAddons[i];
    var addonName = addon.addon_name || addon.name;
    var addonPrice = parseFloat(addon.addon_price || addon.price || 0);
    var addonImage = addon.addon_image || '';
    var key = 'addon_' + addon.id;
    var qty = 0;
    if (cartItems[key] && typeof cartItems[key] === 'object') {
      qty = cartItems[key].qty || 0;
    }
    
    html += '<div class="card" data-addon-id="' + addon.id + '">' +
      '<div class="card-img-wrap">' +
        '<img src="' + getImageUrl(addonImage) + '" alt="' + escapeHtml(addonName) + '" loading="lazy">' +
      '</div>' +
      '<div class="card-body">' +
        '<div class="card-name">' + escapeHtml(addonName) + '</div>' +
        '<div class="card-price">' + formatPrice(addonPrice) + '</div>' +
        '<span id="addon-btn-' + addon.id + '">' + renderAddonBtn(addon) + '</span>' +
      '</div>' +
    '</div>';
  }
  el.innerHTML = html || '<div style="text-align:center;padding:40px;color:#999">No add-ons available</div>';
  
  // Update nav to show addons as active
  var btns = document.querySelectorAll('.side-nav-btn');
  for (var i = 0; i < btns.length; i++) {
    btns[i].classList.remove('active');
    if (btns[i].dataset.id === 'addons') {
      btns[i].classList.add('active');
    }
  }
}

function renderAddonBtn(addon) {
  var key = 'addon_' + addon.id;
  var qty = 0;
  if (cartItems[key] && typeof cartItems[key] === 'object') {
    qty = cartItems[key].qty || 0;
  }
  if (qty === 0) {
    return '<button class="add-btn" onclick="event.stopPropagation();addAddonToCart(' + addon.id + ')">Add</button>';
  }
  return '<div class="qty-inline">' +
    '<button onclick="event.stopPropagation();removeAddonFromCart(' + addon.id + ')"><i class="fa fa-minus"></i></button>' +
    '<span class="qty-num">' + qty + '</span>' +
    '<button onclick="event.stopPropagation();addAddonToCart(' + addon.id + ')"><i class="fa fa-plus"></i></button>' +
  '</div>';
}

function addAddonToCart(addonId) {
  var addon = null;
  for (var i = 0; i < addonsList.length; i++) {
    if (addonsList[i].id == addonId) { addon = addonsList[i]; break; }
  }
  if (!addon) return;
  var key = 'addon_' + addonId;
  var existing = cartItems[key];
  if (existing && typeof existing === 'object') {
    existing.qty = (existing.qty || 0) + 1;
  } else {
    cartItems[key] = { qty: 1, addonName: addon.addon_name || addon.name, addonPrice: parseFloat(addon.addon_price || addon.price || 0), isAddon: true };
  }
  var el = document.getElementById('addon-btn-' + addonId);
  if (el) el.innerHTML = renderAddonBtn(addon);
  saveCart();
  updateCheckoutBar();
}

function removeAddonFromCart(addonId) {
  var key = 'addon_' + addonId;
  if (cartItems[key]) {
    if (typeof cartItems[key] === 'object') {
      cartItems[key].qty = (cartItems[key].qty || 0) - 1;
      if (cartItems[key].qty <= 0) delete cartItems[key];
    } else {
      delete cartItems[key];
    }
  }
  var addon = null;
  for (var i = 0; i < addonsList.length; i++) {
    if (addonsList[i].id == addonId) { addon = addonsList[i]; break; }
  }
  if (addon) {
    var el = document.getElementById('addon-btn-' + addonId);
    if (el) el.innerHTML = renderAddonBtn(addon);
  }
  saveCart();
  updateCheckoutBar();
}

function rebuildMenuItems() {
  var menuMap = {};
  // First pass: build lookup by menu_id and by menu_name
  var menuOrder = [];
  for (var i = 0; i < menus.length; i++) {
    menuMap[menus[i].id] = { id: menus[i].id, name: menus[i].menu_name_translated || menus[i].menu_name, image: menus[i].menu_image, items: [] };
    menuOrder.push(menus[i].id);
  }
  for (var i = 0; i < rawMenuItems.length; i++) {
    var item = rawMenuItems[i];
    if (vegOnly && item.item_type && item.item_type !== 'Veg') continue;
    var mid = item.menu_id;
    if (menuMap[mid]) {
      menuMap[mid].items.push(item);
    } else {
      var catName = item.item_category || 'General';
      if (!menuMap[catName]) {
        menuMap[catName] = { name: catName, image: null, items: [] };
        menuOrder.push(catName);
      }
      menuMap[catName].items.push(item);
    }
  }
  // Build menuItems respecting the menus array order (which is sorted by sort_order)
  menuItems = [];
  for (var i = 0; i < menuOrder.length; i++) {
    var key = menuOrder[i];
    if (menuMap[key] && menuMap[key].items.length > 0) {
      menuItems.push(menuMap[key]);
    }
  }
}

function toggleVeg() {
  vegOnly = document.getElementById('vegToggle').checked;
  rebuildMenuItems();
  renderSideNav();
  if (currentMenuIdx >= menuItems.length) currentMenuIdx = 0;
  currentSubcategoryId = null;
  renderProducts(currentMenuIdx);
}

function switchCategory(menuIdx) {
  currentMenuIdx = menuIdx;
  currentSubcategoryId = null;
  renderProducts(menuIdx);
}

function switchToMenu(menuIdx) {
  switchCategory(menuIdx);
}

function getQueryParam(name) {
  var url = new URL(window.location.href);
  return url.searchParams.get(name);
}

function fetchAndRender() {
  if (!window.websiteRestaurantId) {
    document.getElementById('productGrid').innerHTML = '<div style="text-align:center;padding:40px;color:#999">No restaurant selected</div>';
    return;
  }

  fetch(apiUrl('getMenus'))
    .then(function(r) { return r.json(); })
    .then(function(menusData) {
      menus = menusData || [];
      return fetch(apiUrl('getMenuItems'));
    })
    .then(function(r) { return r.json(); })
    .then(function(itemsData) {
      rawMenuItems = itemsData || [];
      rebuildMenuItems();
      loadCart();
      cleanCart();
      updateCheckoutBar();
      renderSideNav();
      var catParam = getQueryParam('cat');
      var startIdx = 0;
      if (catParam) {
        for (var i = 0; i < menuItems.length; i++) {
          if (menuItems[i].name === catParam) { startIdx = i; break; }
        }
      }
      currentMenuIdx = startIdx;
      renderProducts(startIdx);

      // Load layout columns setting
      var themeQ = 'action=get&restaurant_id=' + encodeURIComponent(window.websiteRestaurantId);
      return fetch('theme_api.php?' + themeQ).then(function(r) { return r.json(); });
    })
    .then(function(themeData) {
      if (themeData && themeData.success && themeData.settings) {
        var cols = themeData.settings.layout_columns || 2;
        var grid = document.getElementById('productGrid');
        grid.classList.remove('cols-1', 'cols-2');
        grid.classList.add('cols-' + cols);
      }
    })
    .catch(function(err) {
      console.error('Failed to load menu data:', err);
      document.getElementById('productGrid').innerHTML = '<div style="text-align:center;padding:40px;color:#999">Failed to load menu items</div>';
    });
}

// --- PWA Install Prompt ---
let deferredPrompt = null;

// Register Service Worker (silent fail if tracking prevention blocks it)
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    try {
      navigator.serviceWorker.register('<?php echo $website_base_href ?>sw.php', { scope: '<?php echo $site_root ?>' }).then(function(reg) {
        // SW registered successfully
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
  // App was installed successfully
});

loadCart();
updateCheckoutBar();

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
    '<button onclick="this.closest(\'.closed-overlay\').remove()">OK, Got it!</button>' +
    '</div>';
  document.body.appendChild(overlay);
}

// --- Deal Filters ---
var dealItems = null;
var activeDealFilter = "";

function loadDealFilters() {
  var rid = window.websiteRestaurantId || "RES001";
  fetch(API_BASE + "?action=getDeals&restaurant_id=" + encodeURIComponent(rid))
    .then(function(r) { return r.json(); })
    .then(function(deals) {
      if (!deals || deals.error || !Array.isArray(deals) || deals.length === 0) {
        document.querySelectorAll(".deal-filter").forEach(function(el) { el.style.display = "none"; });
        dealItems = {combo: [], new: []};
        return;
      }
      dealItems = {combo: [], new: []};
      var hasCombo = false, hasNew = false;
      for (var i = 0; i < deals.length; i++) {
        var d = deals[i];
        if (d.deal_type === "combo") {
          dealItems.combo.push(parseInt(d.menu_id));
          hasCombo = true;
        } else if (d.deal_type === "new") {
          dealItems.new.push(parseInt(d.menu_id));
          hasNew = true;
        }
      }
      var els = document.querySelectorAll(".deal-filter");
      for (var i = 0; i < els.length; i++) {
        var dt = els[i].dataset.deal;
        if (dt === "combo") els[i].style.display = hasCombo ? "" : "none";
        else if (dt === "new") els[i].style.display = hasNew ? "" : "none";
        else if (dt === "clear") els[i].style.display = activeDealFilter ? "" : "none";
      }
      var divider = document.querySelector(".filter-divider");
      if (divider) divider.style.display = (hasCombo || hasNew) ? "" : "none";
    })
    .catch(function() {
      document.querySelectorAll(".deal-filter").forEach(function(el) { el.style.display = "none"; });
    });
}

function setDealFilter(dealType) {
  if (dealType === "clear") {
    activeDealFilter = "";
    document.querySelectorAll(".deal-filter").forEach(function(el) { el.classList.remove("active"); });
    document.querySelectorAll(".card").forEach(function(el) { el.style.display = ""; });
    document.querySelector(".deal-filter[data-deal=clear]").style.display = "none";
    document.getElementById("filterMenu").classList.remove("show");
    return;
  }
  activeDealFilter = dealType;
  var els = document.querySelectorAll(".deal-filter");
  for (var i = 0; i < els.length; i++) {
    els[i].classList.toggle("active", els[i].dataset.deal === dealType);
  }
  document.querySelector(".deal-filter[data-deal=clear]").style.display = "";
  document.getElementById("filterMenu").classList.remove("show");
  applyDealFilter();
}

function applyDealFilter() {
  if (!activeDealFilter || !dealItems) return;
  var allowedMenuIds = dealItems[activeDealFilter] || [];
  if (allowedMenuIds.length === 0) return;
  for (var i = 0; i < menuItems.length; i++) {
    if (allowedMenuIds.indexOf(menuItems[i].id) !== -1) {
      if (currentMenuIdx !== i) switchCategory(i);
      return;
    }
  }
}

// Hook into existing renderProducts to re-apply deal filter after rendering
// This must run AFTER renderProducts is defined in the script
function setupDealFilterHook() {
  if (window.renderProducts && !window._dealFilterHooked) {
    window._dealFilterHooked = true;
    var origRender = window.renderProducts;
    window.renderProducts = function(menuIdx) {
      origRender(menuIdx);
      if (activeDealFilter) {
        setTimeout(function() { applyDealFilter(); }, 50);
      }
    };
  }
}

function shareRestaurant() {
  var url = window.location.href;
  var title = window.restaurantName || 'Restaurant';
  if (navigator.share) {
    navigator.share({ title: title, url: url });
  } else {
    var temp = document.createElement('input');
    temp.value = url;
    document.body.appendChild(temp);
    temp.select();
    document.execCommand('copy');
    document.body.removeChild(temp);
    showToast('Link copied to clipboard!');
  }
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

document.addEventListener('click', function(e) {
  var menu = document.getElementById('filterMenu');
  if (menu && menu.classList.contains('show') && !e.target.closest('.filter-dropdown')) {
    menu.classList.remove('show');
  }
});

document.addEventListener('DOMContentLoaded', function() {
  fetchAndRender();
  fetchAddons();
  loadDealFilters();
  setTimeout(setupDealFilterHook, 200);
  if (window.openingHours && !isRestaurantOpen(window.openingHours)) {
    showClosedModal();
  }

  // Search across all menus
  var sideNav = document.getElementById('sideNav');
  if (sideNav) {
    sideNav.addEventListener('click', function(e) {
      var btn = e.target.closest('.side-nav-btn');
      if (btn) {
        if (btn.dataset.id === 'addons') {
          currentSubcategoryId = null;
          renderAddonsGrid();
        } else {
          currentSubcategoryId = null;
          switchCategory(parseInt(btn.dataset.index));
        }
      }
    });
  }
  var searchInput = document.querySelector('.search-input');
  if (searchInput) {
    var searchTimer = null;
    var searchReqId = 0;
    searchInput.addEventListener('input', function() {
      var q = this.value.toLowerCase().trim();
      if (!q) {
        currentSubcategoryId = null;
        renderProducts(currentMenuIdx);
        return;
      }
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function() {
        var reqId = ++searchReqId;
        fetch(apiUrl('searchItems', 'q=' + encodeURIComponent(q)))
          .then(function(r) { return r.json(); })
          .then(function(items) {
            if (reqId !== searchReqId) return;
            if (!items || items.length === 0) {
              document.getElementById('productGrid').innerHTML = '<div style="text-align:center;padding:40px;color:#999">No items found</div>';
              return;
            }
            var grouped = {};
            for (var i = 0; i < items.length; i++) {
              var menuName = items[i].menu_name_translated || items[i].menu_name || 'Other';
              if (!grouped[menuName]) grouped[menuName] = [];
              grouped[menuName].push(items[i]);
            }
            var menuNames = Object.keys(grouped);
            if (menuNames.length === 1) {
              for (var j = 0; j < menuItems.length; j++) {
                if (menuItems[j].name === menuNames[0]) {
                  switchToMenu(j);
                  return;
                }
              }
            }
            var html = '';
            document.getElementById('categoryTitle').textContent = 'Search Results (' + items.length + ')';
            for (var m = 0; m < menuNames.length; m++) {
              var list = grouped[menuNames[m]];
              var menuIdx = -1;
              for (var j = 0; j < menuItems.length; j++) {
                if (menuItems[j].name === menuNames[m]) { menuIdx = j; break; }
              }
              if (menuIdx === -1) continue;
              html += '<div style="padding:8px 16px 4px;font-size:13px;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:0.5px;cursor:pointer" onclick="switchToMenu(' + menuIdx + ')">' + menuNames[m] + ' <span style="font-size:11px;color:#aaa">↗</span></div>';
              for (var i = 0; i < list.length; i++) {
                var item = list[i];
                var hasVar = item.has_variations && item.variations && item.variations.length > 0;
                var displayPrice = hasVar ? 'From ' + formatPrice(getMinVariantPrice(item)) : formatPrice(item.base_price || 0);
                html += '<div class="card" data-itemId="' + item.id + '" onclick="showItemDetail(' + item.id + ')">' +
                  '<div class="card-img-wrap"><img src="' + getImageUrl(item.item_image) + '" alt="' + escapeHtml(item.item_name_translated || item.item_name_en) + '" loading="lazy">' +
                  getTypeIcon(item.item_type) + '</div>' +
                  '<div class="card-body">' +
                  '<div class="card-name">' + escapeHtml(item.item_name_translated || item.item_name_en) + '</div>' +
                  '<div class="card-price">' + displayPrice + '</div>' +
                  '<span id="btn-' + item.id + '">' + renderCartBtn(item.id, item) + '</span></div></div>';
              }
            }
            document.getElementById('productGrid').innerHTML = html;
          })
          .catch(function(err) { console.error('Search failed:', err); });
      }, 400);
    });
  }
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
