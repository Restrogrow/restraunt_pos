<?php require_once __DIR__ . '/header.php';
// If table parameter is present (from QR code), redirect to menu
if (isset($_GET['table']) && trim($_GET['table']) !== '') {
    $menuUrl = restaurantPageUrl('menu');
    $tableRedirect = $menuUrl . (strpos($menuUrl, '?') !== false ? '&' : '?') . 'table=' . urlencode(trim($_GET['table']));
    header('Location: ' . $tableRedirect);
    exit;
}
?>
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
<meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?>">
<link rel="manifest" href="manifest.php<?php echo $restaurant_id ? '?restaurant_id=' . urlencode($restaurant_id) : ''; ?>">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars($restaurant_logo ?? $local_placeholder_svg, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? $local_favicon_svg, ENT_QUOTES, 'UTF-8'); ?>">
<title><?php echo htmlspecialchars($restaurant_name ?? 'Dvani Cafe & Grill', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700<?php echo $font_family_google_param ? '&family=' . $font_family_google_param . ':wght@300;400;500;600;700' : ''; ?>&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
  /* TEMPORARY (PhonePe approval, added 2026-08-18 — remove in ~2 days) */
  .phone-frame { max-width: 100%; margin: 0; border-radius: 0; }
<?php endif; ?>
}

.theme-bg {
  background-size: cover;
  background-position: center;
  background-color: #2d3436;
  color: #fff;
  min-height: 280px;
  position: relative;
  padding-bottom: 20px;
}

/* Header variant: Minimal — compact single bar (logo + name + location)
   instead of a tall background-image hero. */
.theme-bg.header-minimal {
  min-height: 0;
  padding: 14px 56px 12px 16px;
  background: linear-gradient(135deg, var(--primary-red, #F70000), var(--dark-red, #DA020E)) !important;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
}
.theme-bg.header-minimal .top-bar {
  position: absolute;
  top: 8px; right: 8px;
  padding: 0;
  width: auto;
}
.theme-bg.header-minimal .profile-section {
  flex-direction: row;
  order: 1;
}
.theme-bg.header-minimal .profile-img-wrap {
  width: 46px !important; height: 46px !important;
  margin-bottom: 0;
}
.theme-bg.header-minimal .rest-name {
  order: 2;
  justify-content: flex-start;
  text-align: left;
  font-size: 16px;
  padding: 0 0 0 10px;
  flex: 1;
  min-width: 0;
}
.theme-bg.header-minimal .loc-container {
  order: 3;
  width: 100%;
  margin: 8px 0 0;
  padding: 6px 10px;
  background: rgba(255,255,255,0.15);
}
.theme-bg.header-minimal .location-txt {
  text-align: left;
  font-size: 12px;
}

.top-bar {
  display: flex;
  justify-content: flex-end;
  padding: 12px 16px;
}
.more-btn {
  width: 36px; height: 36px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff;
  cursor: pointer; font-size: 20px;
  border: none; position: relative;
}
.more-dropdown {
  position: absolute; top: 44px; right: 0; z-index: 100;
  background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);
  min-width: 180px; overflow: hidden; display: none;
}
.more-dropdown.show { display: block; }
.more-dropdown a {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; color: #1a1b1f; text-decoration: none;
  font-size: 14px; font-family: var(--site-font); cursor: pointer;
  transition: background 0.15s;
}
.more-dropdown a:hover { background: #f5f5f5; }
.more-dropdown a i { width: 20px; text-align: center; color: var(--primary-red, #e17055); }
.more-dropdown .divider { height: 1px; background: #eee; margin: 4px 0; }

.profile-section {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0;
}
.profile-img-wrap {
  width: 90px; height: 90px; border-radius: 50%;
  overflow: hidden; border: 3px solid rgba(255,255,255,0.6);
  margin-bottom: 8px;
  cursor: pointer;
  transition: all 0.3s ease;
}
.profile-img-wrap.logo-square {
  border-radius: 12px !important;
}
.profile-img-wrap img {
  width: 100%; height: 100%; object-fit: cover;
}
.verified-badge {
  position: absolute;
  bottom: 4px; right: 4px;
  width: 22px; height: 22px;
}

.rest-name {
  font-size: 22px; font-weight: 600; color: #fff;
  display: flex; align-items: center; justify-content: center;
  gap: 6px;
  padding: 0 16px;
  white-space: nowrap;
  overflow: hidden;
  max-width: 100%;
}
.rest-name .rest-name-text {
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
  flex-shrink: 1;
  white-space: nowrap;
}
.rest-name .verify-icon, .rest-name .open-icon {
  display: inline-block; width: 20px; height: 20px;
  flex-shrink: 0;
}
.rest-name .verify-icon i, .rest-name .open-icon i {
  vertical-align: middle;
}
.rest-name .closed-badge {
  white-space: nowrap;
}

.ceo-details { padding: 2px 16px; }
.loc-container {
  margin: 6px 16px 4px;
  background: rgba(0,0,0,0.25);
  border-radius: 10px;
  padding: 8px 14px;
  backdrop-filter: blur(2px);
}
.location-txt {
  text-align: center; padding: 0;
  font-size: 14px; font-weight: 500; color: #fff;
  font-family: var(--site-font); margin: 0;
}
.location-txt i { font-size: 15px; vertical-align: middle; margin-right: 6px; color: rgba(255,255,255,0.85); }

.bio-container {
  margin: 8px 16px;
  background: rgba(0,0,0,0.3);
  border-radius: 12px;
  padding: 12px 16px;
  backdrop-filter: blur(2px);
}
.bio-web {
  text-align: center;
  font-size: 17px; font-weight: 400; color: #fff;
  font-family: var(--site-font);
  line-height: 24px;
  margin: 0;
}

.count-rapper {
  display: flex; justify-content: center; gap: 12px;
  padding: 6px 0 10px;
}
.count-box {
  background: #f4f8fc; border-radius: 10px;
  text-align: center; padding: 6px 14px; min-width: 60px;
  border-radius: 0 0 10px 10px;
}
.count-box .num { font-size: 14px; font-weight: 600; color: #2d3436; }
.count-box .lbl { font-size: 10px; color: #666; }

.action-btn-row {
  display: flex; justify-content: center; gap: 8px;
  padding: 0 16px 14px;
}
.action-btn-row button {
  padding: 6px 14px; border: none; border-radius: 8px;
  font-size: 12px; font-family: var(--site-font);
  cursor: pointer; display: flex; align-items: center; gap: 4px;
}
.btn-fill {
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff;
}
.btn-outline {
  background: transparent; border: 1px solid rgba(255,255,255,0.5);
  color: #fff;
}

.order-tabs {
  display: flex; background: #eef2f7; margin: 4px 16px 10px;
  border-radius: 10px; overflow: hidden;
  box-shadow: inset 0 1px 3px rgba(0,0,0,0.06);
}
.order-tab {
  flex: 1; text-align: center; padding: 10px 0;
  font-size: 14px; font-weight: 500; cursor: pointer;
  color: #888; transition: all 0.25s;
  background: transparent; border: none; font-family: var(--site-font);
  position: relative;
}
.order-tab.active {
  background: #fff;
  color: #2d3436;
  font-weight: 600;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1), 0 0 0 1px rgba(45,52,54,0.08);
  border-radius: 10px;
}

/* Coupon Card Carousel */
.coupon-carousel {
  padding: 6px 16px 0;
}
.coupon-carousel-inner {
  display: flex;
  gap: 12px;
  overflow-x: auto;
  overflow-y: hidden;
  scroll-snap-type: x proximity;
  -webkit-overflow-scrolling: touch;
  touch-action: pan-x;
  padding-bottom: 4px;
  cursor: grab;
  -ms-overflow-style: none;
  scrollbar-width: none;
}
.coupon-carousel-inner:active { cursor: grabbing; }
.coupon-carousel-inner::-webkit-scrollbar { height: 0; }
.coupon-card {
  flex: 0 0 88%;
  scroll-snap-align: start;
  border-radius: 12px;
  overflow: hidden;
  position: relative;
  cursor: pointer;
  transition: transform 0.2s;
  display: flex;
  height: 80px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.12);
}
.coupon-card:active { transform: scale(0.98); }
.coupon-card-barcode {
  width: 48px;
  background: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1px;
  padding: 8px 4px;
  flex-shrink: 0;
  position: relative;
}
.coupon-card-barcode::after {
  content: '';
  position: absolute;
  right: -6px;
  top: 50%;
  transform: translateY(-50%);
  width: 12px;
  height: 12px;
  background: #fff;
  border-radius: 50%;
}
.coupon-barcode-line {
  background: #333;
  border-radius: 0.5px;
}
.coupon-card-content {
  flex: 1;
  padding: 8px 16px 8px 18px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  position: relative;
  overflow: hidden;
  min-width: 0;
}
.coupon-card-discount {
  font-size: 16px;
  font-weight: 800;
  line-height: 1.1;
  color: #fff;
  text-shadow: 0 1px 2px rgba(0,0,0,0.12);
  padding-right: 28px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.coupon-card-divider {
  width: 100%;
  height: 1px;
  background: rgba(255,255,255,0.3);
  margin: 3px 0;
}
.coupon-card-code-row {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  font-weight: 600;
  color: #fff;
  flex-wrap: nowrap;
  overflow: hidden;
}
.coupon-card-code-row .use-label {
  opacity: 0.9;
  font-size: 10px;
  font-weight: 600;
  flex-shrink: 0;
}
.coupon-card-code-row .coupon-code-val {
  font-weight: 800;
  letter-spacing: 0.5px;
  font-size: 12px;
  flex-shrink: 0;
}
.coupon-card-code-row .copy-icon {
  font-size: 11px;
  opacity: 0.85;
  cursor: pointer;
  flex-shrink: 0;
}
.coupon-card-code-row .cpn-separator {
  opacity: 0.5;
  margin: 0 2px;
  flex-shrink: 0;
}
.coupon-card-code-row .cpn-desc-tag {
  font-size: 9px;
  opacity: 0.8;
  font-weight: 500;
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.coupon-card-deco {
  position: absolute;
  right: 8px;
  top: 8px;
  font-size: 24px;
  opacity: 0.22;
  line-height: 1;
  pointer-events: none;
}
.coupon-card-gif {
  position: absolute;
  right: 6px;
  top: 50%;
  transform: translateY(-50%);
  width: 32px;
  height: 32px;
  object-fit: contain;
  opacity: 0.8;
  pointer-events: none;
  filter: drop-shadow(0 1px 3px rgba(0,0,0,0.25));
}

/* Toast Notification */
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

.grid-btn-rapper {
  display: flex; padding: 14px 16px 10px;
}
.gradient-search-container {
  display: flex; align-items: center;
  width: 100%;
  background: #fff;
  border: 1px solid #2d3436;
  border-radius: 999px;
  overflow: hidden;
}
.gradient-search-input {
  flex: 1; border: none; outline: none;
  padding: 6px 14px;
  font-size: 14px; font-family: var(--site-font);
  height: 29px;
  background: transparent;
  color: #111;
}
.gradient-search-input::placeholder {
  color: #999; font-size: 13px;
}
.gradient-search-button {
  width: 39px; height: 35px;
  border: none; border-left: 1px solid #ddd;
  background: transparent;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: #333; font-size: 16px;
}

.menu-list { padding: 0 16px 100px; }

.category-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 0 8px; border-bottom: 1px solid #fff;
  margin-top: 8px;
}
.category-header h3 {
  font-size: 16px; font-weight: 600; color: #1a1b1f;
  display: flex; align-items: center; gap: 8px;
}
.category-header h3 .count-badge {
  font-size: 12px; color: var(--primary-red, #e17055); font-weight: 500;
}
.category-header .collapse-icon {
  color: #999; cursor: pointer; font-size: 14px;
}

.menu-item {
  display: flex; gap: 12px;
  padding: 10px 0; border-bottom: 1px solid #eee;
  align-items: stretch;
}
.menu-item-img {
  width: 80px; height: 80px; border-radius: 8px;
  overflow: hidden; position: relative; flex-shrink: 0;
}
.menu-item-img img {
  width: 100%; height: 100%; object-fit: cover;
}
.veg-icon {
  position: absolute; top: 4px; left: 4px;
  width: 16px; height: 16px; border-radius: 2px;
  background: #fff; display: flex; align-items: center; justify-content: center;
}
.veg-icon img { width: 14px; height: 14px; }

.menu-item-content { flex: 1; min-width: 0; display: flex; flex-direction: column; padding: 2px 0; }
.menu-item-name { font-size: 14px; font-weight: 600; color: #1a1b1f; }
.menu-item-desc { font-size: 11px; color: #777; margin-top: 2px; line-height: 1.4; }
.menu-item-price { font-size: 14px; font-weight: 600; color: #2d3436; margin-top: auto; }
.menu-item-footer { display: flex; align-items: center; justify-content: space-between; margin-top: auto; }
.menu-item-variant { font-size: 10px; color: var(--primary-red, #e17055); font-weight: 500; background: #fdf2f2; padding: 2px 8px; border-radius: 4px; }
.menu-item.oos { opacity: 0.55; pointer-events: none; }
.menu-item.oos .menu-item-img { filter: grayscale(1); }
.menu-item-oos-badge { font-size: 10px; font-weight: 600; color: #e74c3c; background: #fdecea; padding: 2px 8px; border-radius: 4px; display: inline-block; text-transform: uppercase; letter-spacing: 0.3px; }
.oos-btn { width: 32px; height: 32px; border-radius: 8px; border: none; background: #ccc; color: #999; font-size: 18px; cursor: not-allowed; display: flex; align-items: center; justify-content: center; }

.add-btn {
  width: 32px; height: 32px; border-radius: var(--btn-radius, 8px);
  border: none; cursor: pointer; display: flex; align-items: center; justify-content: center;
  background: var(--primary-yellow, #FFD100);
  color: #1a1b1f; font-size: 18px;
}
.qty-control {
  display: flex; align-items: center; gap: 0;
  background: var(--primary-yellow, #FFD100);
  border-radius: 8px; overflow: hidden;
}
.qty-control button {
  width: 30px; height: 32px; border: none;
  background: transparent; color: #1a1b1f;
  font-size: 16px; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-family: var(--site-font);
}
.qty-control .qty-num {
  min-width: 24px; text-align: center;
  color: #fff; font-size: 14px; font-weight: 600;
}

.links-grid { padding: 0 16px; }
.links-grid h2 { font-size: 18px; font-weight: 600; margin: 20px 0 12px; color: #1a1b1f; }
.link-card {
  display: flex; align-items: center; gap: 12px;
  padding: 12px; border-radius: 12px;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff; margin-bottom: 8px; cursor: pointer;
}
.link-card .link-icon { width: 36px; height: 36px; border-radius: 6px; overflow: hidden; }
.link-card .link-icon img { width: 100%; height: 100%; object-fit: cover; }
.link-icon-i { display: flex !important; align-items: center; justify-content: center; width: 100%; height: 100%; font-size: 18px; color: #fff !important; }
.link-card .link-name { flex: 1; font-size: 14px; font-weight: 500; }
.link-card .link-desc { font-size: 11px; opacity: 0.8; }

.follow-section {
  text-align: center;
  padding: 0 16px;
  margin-top: 20px;
}
.follow-label {
  font-size: 13px;
  color: #888;
  margin-bottom: 10px;
  font-weight: 500;
  letter-spacing: 0.3px;
  text-transform: uppercase;
}
.social-icons-row {
  display: flex;
  justify-content: center;
  gap: 12px;
  flex-wrap: wrap;
}
.social-icon-link {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border-radius: 50%;
  background: #f4f4f4;
  color: #555;
  text-decoration: none;
  font-size: 18px;
  transition: all 0.25s ease;
  box-shadow: 0 2px 6px rgba(0,0,0,0.06);
}
.social-icon-link:hover {
  transform: translateY(-3px) scale(1.08);
  box-shadow: 0 6px 16px rgba(0,0,0,0.12);
}
.social-icon-link:active {
  transform: translateY(-1px) scale(1.02);
}
.social-icon-link[title="Instagram"]:hover { background: #E4405F; color: #fff; }
.social-icon-link[title="Facebook"]:hover { background: #1877F2; color: #fff; }
.social-icon-link[title="Twitter / X"]:hover { background: #000; color: #fff; }
.social-icon-link[title="YouTube"]:hover { background: #FF0000; color: #fff; }
.social-icon-link[title="LinkedIn"]:hover { background: #0A66C2; color: #fff; }
.social-icons-row:empty { display: none; }

.share-section { padding: 0 16px; text-align: center; margin-top: 20px; }
.share-section h2 { font-size: 18px; font-weight: 600; margin-bottom: 12px; color: #1a1b1f; }
.share-section .qr-text { font-size: 13px; color: #555; margin-bottom: 12px; }
.qr-placeholder {
  width: 180px; height: 180px; margin: 0 auto;
  background: #f4f4f4; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  border: 2px dashed #ccc; position: relative;
}
.qr-placeholder canvas { width: 100%; height: 100%; }
.qr-center-logo {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  width: 50px; height: 50px; border-radius: 50%;
  background: #fff; overflow: hidden;
}
.qr-center-logo img { width: 100%; height: 100%; object-fit: cover; }
.share-btn {
  margin-top: 14px; padding: 10px 40px; border: none; border-radius: 8px;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff; font-size: 14px; font-family: var(--site-font);
  cursor: pointer; font-weight: 500;
}

.footer-section {
  text-align: center;
  padding: 24px 16px 90px;
}
.footer-icons { display: flex; justify-content: center; gap: 16px; margin-bottom: 12px; }
.footer-icons a {
  display: flex; align-items: center; justify-content: center;
  width: 40px; height: 40px; border-radius: 50%;
  background: #f4f4f4;
}
.footer-icons a img { width: 24px; height: 24px; }
.footer-brand { font-size: 13px; color: #888; }
.footer-brand strong { color: #2d3436; }
.footer-links {
  display: flex; justify-content: center; flex-wrap: wrap; gap: 6px 12px;
  margin-top: 12px;
}
.footer-links a { font-size: 11px; color: #888; text-decoration: none; }
.footer-links span { color: #ccc; }

.bottom-nav {
  position: fixed; bottom: 0; left: 50%; transform: translateX(-50%);
  width: 100%; max-width: <?php echo ($host === 'triposhsymmetry.in') ? '100%' : '425px'; ?>;
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

/* Order History styles */
.order-history-list { max-height: 350px; overflow-y: auto; }
.order-history-item {
  background: #fff; border: 2px solid #f0f0f0; border-radius: 12px;
  padding: 14px; margin-bottom: 10px;
}
.order-history-header { display: flex; justify-content: space-between; align-items: flex-start; }
.order-history-header strong { font-size: 13px; color: #2d3436; }
.order-history-items { margin-top: 8px; padding-top: 8px; border-top: 1px solid #eee; font-size: 12px; color: #666; }

.hidden { display: none !important; }

/* Order Type Switch Modal */
.order-type-modal {
  display: none;
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.55);
  z-index: 5000;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.order-type-modal.active { display: flex; }
.order-type-modal-content {
  background: #fff;
  border-radius: 20px;
  max-width: 370px;
  width: 100%;
  box-shadow: 0 25px 60px rgba(0,0,0,0.3);
  overflow: hidden;
}
.order-type-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 18px;
  border-bottom: 1px solid #f0f0f0;
}
.order-type-modal-header h2 {
  font-size: 15px;
  font-weight: 600;
  color: #1a1b1f;
  margin: 0;
}
.order-type-close {
  width: 30px; height: 30px;
  border-radius: 8px;
  background: #1a1b1f;
  color: #fff;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  font-weight: 700;
  font-family: var(--site-font);
}
.order-type-modal-body {
  padding: 20px 18px 18px;
  text-align: center;
}
.order-type-graphic {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 2px;
  margin-bottom: 12px;
}
.order-type-modal-body h3 {
  font-size: 17px;
  font-weight: 700;
  color: #1a1b1f;
  margin: 0 0 3px;
}
.order-type-subheading {
  font-size: 12px;
  color: #999;
  margin: 0 0 16px;
}
.order-type-cards {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  margin-bottom: 16px;
}
.order-type-card {
  flex: 1;
  max-width: 125px;
  padding: 14px 10px;
  border-radius: 12px;
  text-align: center;
}
.order-type-card svg {
  display: block;
  margin: 0 auto 6px;
  width: 48px;
  height: 48px;
}
.order-type-card .card-label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: #1a1b1f;
}
.card-takeaway { background: #f5f5f5; }
.card-delivery { background: #fce4ec; }
.card-dinein { background: #e8f5e9; }
.order-type-arrow {
  font-size: 22px;
  color: #1a1b1f;
  font-weight: 700;
  flex-shrink: 0;
}
.order-type-warning {
  font-size: 11.5px;
  color: #e53935;
  margin: 0 0 16px;
  line-height: 1.5;
}
.order-type-buttons {
  display: flex;
  gap: 10px;
}
.order-type-btn {
  flex: 1;
  padding: 11px;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  border: none;
  cursor: pointer;
  font-family: var(--site-font);
  transition: all 0.2s;
}
.order-type-btn.btn-cancel {
  background: #fce4ec;
  color: #e53935;
}
.order-type-btn.btn-cancel:hover { background: #f8bbd0; }
.order-type-btn.btn-confirm {
  background: var(--dark-red, #d63031);
  color: #fff;
}
.order-type-btn.btn-confirm:hover { background: #c0392b; }

@media (max-width: 400px) {
  .order-type-card { max-width: 110px; padding: 10px 8px; }
  .order-type-card svg { width: 40px; height: 40px; }
  .order-type-modal-body h3 { font-size: 15px; }
}

.skeleton {
  background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
  background-size: 200% 100%;
  animation: shimmer 1.5s ease-in-out infinite;
  border-radius: 6px;
}
@keyframes shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
.skel-cat-header { height: 20px; width: 120px; margin: 16px 0 12px; }
.skel-item { display: flex; gap: 12px; padding: 10px 0; align-items: center; border-bottom: 1px solid #eee; }
.skel-img { width: 80px; height: 80px; border-radius: 8px; flex-shrink: 0; }
.skel-text { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.skel-title { height: 14px; width: 65%; }
.skel-desc { height: 10px; width: 90%; }
.skel-price { height: 14px; width: 25%; margin-top: 4px; }

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
  font-family: var(--site-font);
}
.variant-chip.selected {
  border-color: #2d3436;
  background: #f0f7f5;
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
  background: var(--primary-yellow, #FFD100);
  color: #1a1b1f;
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;
  font-family: var(--site-font);
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
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff;
  font-size: 20px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: var(--site-font);
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
  font-family: var(--site-font);
  transition: opacity 0.2s;
}
.closed-overlay-inner button:hover { opacity: 0.9; }
.closed-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #fee2e2;
  color: #dc2626;
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 600;
  margin-top: 8px;
}

@media (max-width: 425px) {
  .product-sheet { max-height: 92vh; border-radius: 20px 20px 0 0; }
  .product-sheet-img { height: 200px; }
  .product-sheet-body { padding: 14px 16px; }
  .product-sheet-name { font-size: 18px; }
  .product-sheet-price { font-size: 20px; }
}
</style>
<script>
window.websiteRestaurantId = <?php echo json_encode($restaurant_id ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.websiteRestaurantSlug = <?php echo json_encode($restaurant_slug ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.globalCurrencySymbol = <?php echo json_encode($currency_symbol ?? '₹', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
window.restaurantCountry = <?php echo json_encode($country ?? 'India', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
window.restaurantDialCode = <?php echo json_encode($phone_dial_code ?? '+91', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.restaurantPhoneMin = <?php echo json_encode((int)($phone_min_digits ?? 10), JSON_HEX_TAG); ?>;
window.restaurantPhoneMax = <?php echo json_encode((int)($phone_max_digits ?? 10), JSON_HEX_TAG); ?>;
window.restaurantName = <?php echo json_encode($restaurant_name ?? 'Restaurant', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.restaurantLogo = <?php echo json_encode($restaurant_logo, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.restaurantPhone = <?php echo json_encode($restaurant_phone ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.restaurantEmail = <?php echo json_encode($restaurant_email ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.restaurantAddress = <?php echo json_encode($restaurant_address ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.restaurantOpen = <?php echo json_encode($restaurant_open, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.openingHours = <?php echo json_encode($opening_hours ? json_decode($opening_hours, true) : null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
window.minimumOrderValue = <?php echo json_encode((float)$minimum_order_value, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.packagingCharge = <?php echo json_encode((float)$packaging_charge, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.showInstallApp = <?php echo json_encode($show_install_app, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.enableGst = <?php echo json_encode($enable_gst ?? 1, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.backgroundTheme = <?php echo json_encode($background_theme, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
window.restaurantTimezoneOffset = <?php echo json_encode($timezone_offset_minutes ?? 330, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.enableDelivery = <?php echo json_encode((int)$enable_delivery, JSON_HEX_TAG); ?>;
window.enableTakeaway = <?php echo json_encode((int)$enable_takeaway, JSON_HEX_TAG); ?>;
window.enableDinein = <?php echo json_encode((int)$enable_dinein, JSON_HEX_TAG); ?>;
window.socialLinks = {
  instagram: <?php echo json_encode($instagram_link, JSON_HEX_TAG | JSON_HEX_AMP); ?>,
  facebook: <?php echo json_encode($facebook_link, JSON_HEX_TAG | JSON_HEX_AMP); ?>,
  twitter: <?php echo json_encode($twitter_link, JSON_HEX_TAG | JSON_HEX_AMP); ?>,
  youtube: <?php echo json_encode($youtube_link, JSON_HEX_TAG | JSON_HEX_AMP); ?>,
  linkedin: <?php echo json_encode($linkedin_link, JSON_HEX_TAG | JSON_HEX_AMP); ?>
};
</script>
</head>
<body>
<div class="phone-frame">

  <div class="theme-bg<?php echo $header_style === 'minimal' ? ' header-minimal' : ''; ?>" id="homeSection" style="<?php
    if ($header_style !== 'minimal') {
      echo $background_theme ? 'background-image: url(' . htmlspecialchars($background_theme, ENT_QUOTES, 'UTF-8') . ');' : 'background: linear-gradient(135deg, #2d3436, #636e72);';
    }
  ?>">
    <div class="top-bar">
      <div style="position:relative">
        <button class="more-btn" onclick="toggleDropdown()"><i class="fa fa-ellipsis-v"></i></button>
        <div class="more-dropdown" id="moreDropdown">
          <a onclick="closeDropdown();window.location.href='tel:<?php echo htmlspecialchars($restaurant_phone ?? '', ENT_QUOTES, 'UTF-8'); ?>'"><i class="fa fa-phone"></i> Call</a>
          <a onclick="closeDropdown();window.location.href='mailto:<?php echo htmlspecialchars($restaurant_email ?? '', ENT_QUOTES, 'UTF-8'); ?>'"><i class="fa fa-envelope"></i> Email</a>
          <div class="divider"></div>
          <a onclick="closeDropdown();scrollToSection('homeSection',document.querySelector('.nav-item'))"><i class="fa fa-home"></i> Home</a>
          <a onclick="closeDropdown();scrollToSection('socialSection',document.querySelector('.nav-item'))"><i class="fa fa-share-alt"></i> Social</a>
          <a onclick="closeDropdown();window.location.href='<?php echo restaurantPageUrl('about'); ?>'"><i class="fa fa-info-circle"></i> About Us</a>
          <a onclick="closeDropdown();window.location.href='<?php echo restaurantPageUrl('contact'); ?>'"><i class="fa fa-address-card"></i> Contact Us</a>
          <div class="divider"></div>
          <?php if ($show_install_app): ?><a onclick="closeDropdown();promptInstall()" id="installDropdownLink" style="display:none"><i class="fa fa-download"></i> Install App</a><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="profile-section">
      <div class="profile-img-wrap<?php echo ($logo_shape ?? 'circle') === 'square' ? ' logo-square' : ''; ?>" style="width:<?php echo $logo_size ?? 90; ?>px;height:<?php echo $logo_size ?? 90; ?>px">
        <img src="<?php echo htmlspecialchars($restaurant_logo ?? $local_placeholder_svg, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?>">
      </div>
    </div>

    <h2 class="rest-name" id="restaurantNameHeader">
      <span class="rest-name-text"><?php echo htmlspecialchars($restaurant_name ?? 'Dvani Cafe & Grill', ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="verify-icon"><i class="fa fa-check-circle" style="color:#2196F3;font-size:18px;vertical-align:middle"></i></span>
      <span class="open-icon" id="openStatusIcon"><?php if ($restaurant_open): ?><i class="fa fa-store-alt" style="color:#4CAF50;font-size:20px;vertical-align:middle"></i><?php else: ?><span class="closed-badge"><i class="fa fa-clock"></i> Closed</span><?php endif; ?></span>
    </h2>

    <div class="loc-container">
      <p class="location-txt"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($restaurant_address ?? 'Address not set', ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

<div class="bio-container">
  <p class="bio-web"><?php
    $desc = htmlspecialchars($restaurant_description ?? 'Serving handcrafted pizzas, delicious pastas, gourmet burgers, tasty starters, and refreshing beverages.', ENT_QUOTES, 'UTF-8');
    $descFmt = $restaurant_description_format ?? 'paragraph';
    echo $descFmt === 'br' ? nl2br($desc) : $desc;
  ?></p>
</div>

  </div>

  <div class="coupon-carousel" id="couponCarousel">
    <div class="coupon-carousel-inner" id="couponCarouselInner">
      <div class="skeleton" style="flex:0 0 88%;height:80px;border-radius:12px;scroll-snap-align:start"></div>
      <div class="skeleton" style="flex:0 0 88%;height:80px;border-radius:12px;scroll-snap-align:start"></div>
    </div>
  </div>

  <div class="grid-btn-rapper">
    <div class="gradient-search-container">
      <input type="text" class="gradient-search-input" id="searchInput" placeholder="Search your favorite food fast ⚡">
      <button type="button" class="gradient-search-button"><i class="bi bi-search"></i></button>
    </div>
  </div>

  <div class="order-tabs" id="menuSection">
    <?php if ($enable_delivery): ?><button class="order-tab <?php echo $enable_delivery ? 'active' : ''; ?>" onclick="switchOrder(this, 'delivery')">🚚 Delivery</button><?php endif; ?>
    <?php if ($enable_takeaway): ?><button class="order-tab <?php echo !$enable_delivery && $enable_takeaway ? 'active' : ''; ?>" onclick="switchOrder(this, 'takeaway')">🥡 Take Away</button><?php endif; ?>
    <?php if ($enable_dinein): ?><button class="order-tab <?php echo !$enable_delivery && !$enable_takeaway && $enable_dinein ? 'active' : ''; ?>" onclick="switchOrder(this, 'dinein')">🍽️ Dine In</button><?php endif; ?>
  </div>

  <div class="menu-list" id="menuList">
    <div class="skel-cat-header skeleton"></div>
    <div class="skel-item"><div class="skel-img skeleton"></div><div class="skel-text"><div class="skel-title skeleton"></div><div class="skel-desc skeleton"></div><div class="skel-price skeleton"></div></div></div>
    <div class="skel-item"><div class="skel-img skeleton"></div><div class="skel-text"><div class="skel-title skeleton"></div><div class="skel-desc skeleton"></div><div class="skel-price skeleton"></div></div></div>
    <div class="skel-item"><div class="skel-img skeleton"></div><div class="skel-text"><div class="skel-title skeleton"></div><div class="skel-desc skeleton"></div><div class="skel-price skeleton"></div></div></div>
    <div class="skel-cat-header skeleton"></div>
    <div class="skel-item"><div class="skel-img skeleton"></div><div class="skel-text"><div class="skel-title skeleton"></div><div class="skel-desc skeleton"></div><div class="skel-price skeleton"></div></div></div>
    <div class="skel-item"><div class="skel-img skeleton"></div><div class="skel-text"><div class="skel-title skeleton"></div><div class="skel-desc skeleton"></div><div class="skel-price skeleton"></div></div></div>
    <div class="skel-item"><div class="skel-img skeleton"></div><div class="skel-text"><div class="skel-title skeleton"></div><div class="skel-desc skeleton"></div><div class="skel-price skeleton"></div></div></div>
  </div>

  <div class="links-grid" id="socialSection">
    <h2>Social</h2>
    <?php if ($show_install_app): ?><div class="link-card" id="installCardLink" onclick="promptInstall()" style="display:none">
      <div class="link-icon" style="background:var(--primary-red, #e17055)"><i class="fa fa-download link-icon-i"></i></div>
      <div><div class="link-name">Install App</div><div class="link-desc">Add to your home screen</div></div>
    </div><?php endif; ?>
    <div class="link-card" onclick="window.location.href='<?php echo restaurantPageUrl('about'); ?>'">
      <div class="link-icon" style="background:var(--dark-red, #d63031)"><i class="fa fa-info link-icon-i"></i></div>
      <div><div class="link-name">About Us</div><div class="link-desc">Our story & values</div></div>
    </div>

    <div class="link-card" onclick="window.location.href='mailto:<?php echo htmlspecialchars($restaurant_email ?? '', ENT_QUOTES, 'UTF-8'); ?>'">
      <div class="link-icon" style="background:#3b82f6"><i class="fa fa-envelope link-icon-i"></i></div>
      <div><div class="link-name">Email</div><div class="link-desc"><?php echo htmlspecialchars($restaurant_email ?? 'Send us an email', ENT_QUOTES, 'UTF-8'); ?></div></div>
    </div>
    <div class="link-card" onclick="window.location.href='tel:<?php echo htmlspecialchars($restaurant_phone ?? '', ENT_QUOTES, 'UTF-8'); ?>'">
      <div class="link-icon" style="background:#10b981"><i class="fa fa-phone link-icon-i"></i></div>
      <div><div class="link-name">Call</div><div class="link-desc"><?php echo htmlspecialchars($restaurant_phone ?? 'Tap to call', ENT_QUOTES, 'UTF-8'); ?></div></div>
    </div>
    <div class="link-card" onclick="showQR()">
      <div class="link-icon" style="background:#8b5cf6"><i class="fa fa-qrcode link-icon-i"></i></div>
      <div><div class="link-name">QR</div><div class="link-desc">My QR</div></div>
    </div>
    <div class="link-card" onclick="window.location.href='<?php echo restaurantPageUrl('contact'); ?>'">
      <div class="link-icon" style="background:#f59e0b"><i class="fa fa-address-card link-icon-i"></i></div>
      <div><div class="link-name">Contact Us</div><div class="link-desc">Get in touch</div></div>
    </div>
  </div>

  <?php if ($instagram_link || $facebook_link || $twitter_link || $youtube_link || $linkedin_link): ?>
  <div class="follow-section" id="followSection">
    <p class="follow-label">Follow us on</p>
    <div class="social-icons-row" id="socialIconsRow">
      <?php if ($instagram_link): ?>
      <a href="<?php echo htmlspecialchars($instagram_link, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="social-icon-link" title="Instagram">
        <i class="fab fa-instagram"></i>
      </a>
      <?php endif; ?>
      <?php if ($facebook_link): ?>
      <a href="<?php echo htmlspecialchars($facebook_link, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="social-icon-link" title="Facebook">
        <i class="fab fa-facebook"></i>
      </a>
      <?php endif; ?>
      <?php if ($twitter_link): ?>
      <a href="<?php echo htmlspecialchars($twitter_link, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="social-icon-link" title="Twitter / X">
        <i class="fab fa-x-twitter"></i>
      </a>
      <?php endif; ?>
      <?php if ($youtube_link): ?>
      <a href="<?php echo htmlspecialchars($youtube_link, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="social-icon-link" title="YouTube">
        <i class="fab fa-youtube"></i>
      </a>
      <?php endif; ?>
      <?php if ($linkedin_link): ?>
      <a href="<?php echo htmlspecialchars($linkedin_link, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="social-icon-link" title="LinkedIn">
        <i class="fab fa-linkedin"></i>
      </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="share-section">
    <h2>Share</h2>
    <p class="qr-text">Scan below QR to open profile</p>
    <div class="qr-placeholder">
      <canvas id="qrCanvas" width="180" height="180"></canvas>
      <div class="qr-center-logo">
        <img src="<?php echo htmlspecialchars($restaurant_logo ?? $local_placeholder_svg, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo">
      </div>
    </div>
    <button class="share-btn" onclick="shareProfile()">Share</button>
  </div>

  <div id="cartSection" style="padding:0 16px;display:none">
    <h2 style="font-size:18px;font-weight:600;margin:20px 0;color:#1a1b1f">Cart</h2>
    <p style="text-align:center;color:#999;padding:40px 0">Your cart is empty</p>
  </div>

  <div class="footer-section">
    <div class="footer-links">
      <a href="<?php echo restaurantPageUrl('privacy-policy'); ?>">Privacy Policy</a>
      <span>|</span>
      <a href="<?php echo restaurantPageUrl('terms-of-service'); ?>">Terms of Service</a>
      <span>|</span>
      <a href="<?php echo restaurantPageUrl('refund-policy'); ?>">Refund Policy</a>
      <span>|</span>
      <a href="<?php echo restaurantPageUrl('shipping-policy'); ?>">Shipping Policy</a>
      <span>|</span>
      <a href="<?php echo restaurantPageUrl('cookie-policy'); ?>">Cookie Policy</a>
    </div>
    <div class="footer-brand">&copy; <?php echo date('Y'); ?> <strong><?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></strong>. All rights reserved.</div>
  </div>

  <div style="height:80px"></div>

  <?php
  require_once __DIR__ . '/../config/meal_subscription_schema.php';
  $indexNavShowPlans = isset($conn, $restaurant_id) && mealSubscriptionsFeatureEnabled($conn, $restaurant_id);
  require_once __DIR__ . '/../config/reservation_helpers.php';
  $indexNavShowReservations = isset($conn, $restaurant_id) && reservationsFeatureEnabled($conn, $restaurant_id);
  ?>
  <?php
  // Same approach as bottom_nav.php: capture each item as a string so
  // "Install App" (when on) can be spliced into the middle of the row
  // instead of always sitting right after Menu.
  ob_start(); ?>
    <div class="nav-item active" onclick="scrollToSection('homeSection', this)">
      <?php echo renderNavIconTag('home', $navIcons, $navIconOverrides); ?>
      <span data-nav-label="home"><?php echo htmlspecialchars($navLabels['home'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
  <?php $indexNavHomeHtml = ob_get_clean();

  ob_start(); ?>
    <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl('menu'); ?>'">
      <?php echo renderNavIconTag('menu', $navIcons, $navIconOverrides); ?>
      <span data-nav-label="menu"><?php echo htmlspecialchars($navLabels['menu'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
  <?php $indexNavMenuHtml = ob_get_clean();

  ob_start(); ?>
    <div class="nav-item" onclick="scrollToSection('socialSection', this)">
      <?php echo renderNavIconTag('social', $navIcons, $navIconOverrides); ?>
      <span data-nav-label="social"><?php echo htmlspecialchars($navLabels['social'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
  <?php $indexNavSocialHtml = ob_get_clean();

  ob_start(); ?>
    <div class="nav-item" id="installNavBtn" onclick="promptInstall()">
      <?php echo renderNavIconTag('install', $navIcons, $navIconOverrides); ?>
      <span data-nav-label="install"><?php echo htmlspecialchars($navLabels['install'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
  <?php $indexNavInstallHtml = ob_get_clean();

  ob_start(); ?>
    <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl('plans'); ?>'">
      <?php echo renderNavIconTag('plans', $navIcons, $navIconOverrides); ?>
      <span data-nav-label="plans"><?php echo htmlspecialchars($navLabels['plans'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
  <?php $indexNavPlansHtml = ob_get_clean();

  ob_start(); ?>
    <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl('reservations'); ?>'">
      <?php echo renderNavIconTag('reservations', array_merge($navIcons, ['reservations' => 'calendar-check']), $navIconOverrides); ?>
      <span data-nav-label="reservations"><?php echo htmlspecialchars($navLabels['reservations'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
  <?php $indexNavReservationsHtml = ob_get_clean();

  ob_start(); ?>
    <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl('cart'); ?>'">
      <?php echo renderNavIconTag('cart', $navIcons, $navIconOverrides); ?>
      <span data-nav-label="cart"><?php echo htmlspecialchars($navLabels['cart'], ENT_QUOTES, 'UTF-8'); ?></span>
      <div class="cart-badge">0</div>
    </div>
  <?php $indexNavCartHtml = ob_get_clean();

  if (!empty($show_install_app)) {
      $indexOtherItems = [$indexNavHomeHtml, $indexNavMenuHtml];
      if ($indexNavShowPlans) $indexOtherItems[] = $indexNavPlansHtml;
      if ($indexNavShowReservations) $indexOtherItems[] = $indexNavReservationsHtml;
      $indexOtherItems[] = $indexNavCartHtml;
      array_splice($indexOtherItems, (int) ceil(count($indexOtherItems) / 2), 0, [$indexNavInstallHtml]);
      $indexNavItemsHtml = implode('', $indexOtherItems);
  } else {
      $indexNavItemsHtml = $indexNavHomeHtml . $indexNavMenuHtml . $indexNavSocialHtml
          . ($indexNavShowPlans ? $indexNavPlansHtml : '')
          . ($indexNavShowReservations ? $indexNavReservationsHtml : '')
          . $indexNavCartHtml;
  }
  ?>
  <div class="bottom-nav">
    <?php echo $indexNavItemsHtml; ?>
    <button class="login-btn" onclick="window.location.href='<?php echo restaurantPageUrl('profile'); ?>'" title="<?php echo htmlspecialchars($navLabels['profile'], ENT_QUOTES, 'UTF-8'); ?>" style="font-size:16px;padding:6px 10px;"><?php echo renderNavIconTag('profile', $navIcons, $navIconOverrides, false); ?></button>
  </div>
</div>
<style>
/* Draws the eye to "Install App" without using color — bigger icon, bolder
   label, and a slow, gentle scale pulse. */
#installNavBtn .nav-icon { font-size: 30px; }
#installNavBtn span { font-weight: 700; font-size: 12px; }
#installNavBtn { animation: installNavPulse 2.2s ease-in-out infinite; }
@keyframes installNavPulse {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.12); }
}
</style>

<!-- Order Type Switch Modal -->
<div class="order-type-modal" id="orderTypeModal">
  <div class="order-type-modal-content">
    <div class="order-type-modal-header">
      <h2>Select Order Type</h2>
      <button class="order-type-close" onclick="closeOrderTypeModal()">&#10005;</button>
    </div>
    <div class="order-type-modal-body">
      <div class="order-type-graphic">
        <svg width="130" height="32" viewBox="0 0 130 32">
          <path d="M12 26 L18 6 L24 26 Z" fill="#FF6B35" opacity=".85"/>
          <path d="M18 6 Q16 2 14 0" stroke="#4CAF50" stroke-width="2" fill="none" stroke-linecap="round"/>
          <path d="M18 6 Q20 2 22 0" stroke="#4CAF50" stroke-width="2" fill="none" stroke-linecap="round"/>
          <circle cx="46" cy="20" r="7" fill="#FF4081" opacity=".85"/>
          <path d="M46 13 Q45 8 46 4" stroke="#4CAF50" stroke-width="1.5" fill="none" stroke-linecap="round"/>
          <path d="M46 13 Q47 8 46 4" stroke="#4CAF50" stroke-width="1.5" fill="none" stroke-linecap="round"/>
          <path d="M43 13 Q42 10 41 7" stroke="#4CAF50" stroke-width="1.5" fill="none" stroke-linecap="round"/>
          <circle cx="76" cy="20" r="7" fill="#FFD600" opacity=".85"/>
          <circle cx="76" cy="20" r="4" fill="none" stroke="#F9A825" stroke-width=".8"/>
          <line x1="76" y1="13" x2="76" y2="27" stroke="#F9A825" stroke-width=".8"/>
          <line x1="69" y1="20" x2="83" y2="20" stroke="#F9A825" stroke-width=".8"/>
          <path d="M108 20 C108 14 103 12 101 14 C99 12 94 14 94 20 C94 25 101 28 101 28 C101 28 108 25 108 20 Z" fill="#e53935" opacity=".85"/>
        </svg>
      </div>
      <h3>Confirm Order Type Change</h3>
      <p class="order-type-subheading">You're switching your order from</p>
      <div class="order-type-cards">
        <div class="order-type-card" id="fromCard"></div>
        <div class="order-type-arrow">&#8594;</div>
        <div class="order-type-card" id="toCard"></div>
      </div>
      <p class="order-type-warning">This may affect delivery charges, availability, or applied offers. Please confirm to continue.</p>
      <div class="order-type-buttons">
        <button class="order-type-btn btn-cancel" onclick="closeOrderTypeModal()">Cancel</button>
        <button class="order-type-btn btn-confirm" onclick="confirmOrderSwitch()">Yes, Switch</button>
      </div>
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

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>
<script>
let cartItems = {};
let menus = [];
let menuItems = [];
let menuCategories = [];

const API_BASE = 'api.php';

function apiUrl(action, extra) {
  let p = API_BASE + '?action=' + action + '&restaurant_id=' + encodeURIComponent(window.websiteRestaurantId || 'RES001');
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

function getCurrencySymbol() {
  return window.globalCurrencySymbol || '₹';
}

function formatPrice(price) {
  return getCurrencySymbol() + parseFloat(price).toFixed(2);
}

function getItemTotalQty(itemId) {
  var total = 0;
  for (var k in cartItems) {
    var id = parseInt(k.split('_v')[0]);
    if (id === itemId) {
      var v = cartItems[k];
      total += typeof v === 'number' ? v : (v.qty || 0);
    }
  }
  return total;
}

function getItemById(itemId) {
  for (var ci = 0; ci < menuCategories.length; ci++) {
    var items = menuCategories[ci].items || [];
    for (var ii = 0; ii < items.length; ii++) {
      if (items[ii].id == itemId) return items[ii];
    }
  }
  return null;
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

function getCartItem(itemId, varIdx) {
  var key = varIdx !== undefined && varIdx !== null ? itemId + '_v' + varIdx : String(itemId);
  var val = cartItems[key];
  if (val === undefined) return { qty: 0, varName: null, varPrice: null };
  if (typeof val === 'number') return { qty: val, varName: null, varPrice: null };
  return { qty: val.qty || 0, varName: val.varName || null, varPrice: val.varPrice || null };
}

function getQty(itemId) {
  return getItemTotalQty(itemId);
}

function renderQtyControl(itemId, item) {
  if (item && item.is_available == 0) return '';
  var hasVar = item && item.has_variations && item.variations && item.variations.length > 0;
  var qty = getItemTotalQty(itemId);
  if (qty === 0) {
    if (hasVar) {
      return '<button class="add-btn" onclick="event.stopPropagation();showItemDetail(' + item._ci + ',' + item._ii + ')"><i class="fa fa-plus"></i></button>';
    }
    return '<button class="add-btn" onclick="event.stopPropagation();addToCart(' + itemId + ')"><i class="fa fa-plus"></i></button>';
  }
  if (hasVar) {
    return '<button class="add-btn" onclick="event.stopPropagation();showItemDetail(' + item._ci + ',' + item._ii + ')"><span>' + qty + '</span></button>';
  }
  return '<div class="qty-control">' +
    '<button onclick="event.stopPropagation();removeFromCart(' + itemId + ')"><i class="fa fa-minus"></i></button>' +
    '<span class="qty-num">' + qty + '</span>' +
    '<button onclick="event.stopPropagation();addToCart(' + itemId + ')"><i class="fa fa-plus"></i></button>' +
  '</div>';
}

function addToCart(itemId, varInfo) {
  var item = getItemById(itemId);
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
  updateCartBadge();
  var el = document.getElementById('qty-' + itemId);
  if (el) el.innerHTML = renderQtyControl(itemId, getItemById(itemId));
  saveCart();
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
  updateCartBadge();
  var el = document.getElementById('qty-' + itemId);
  if (el) el.innerHTML = renderQtyControl(itemId, getItemById(itemId));
  saveCart();
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

function updateCartBadge() {
  var total = 0;
  for (var k in cartItems) {
    var v = cartItems[k];
    total += typeof v === 'number' ? v : (v.qty || 0);
  }
  var badge = document.querySelector('.cart-badge');
  if (badge) badge.textContent = total;
}

function renderMenu() {
  var el = document.getElementById('menuList');
  var html = '';
  for (var ci = 0; ci < menuCategories.length; ci++) {
    var cat = menuCategories[ci];
    var items = cat.items || [];
    var catImg = cat.image;
    if (!catImg && cat.items && cat.items.length > 0) {
      catImg = cat.items[0].item_image || cat.items[0].image;
    }
    html += '<div class="category-header" style="cursor:pointer">' +
      '<h3>' + (catImg ? '<img src="' + getImageUrl(catImg) + '" alt="' + cat.name + '" style="width:24px;height:24px;border-radius:4px;object-fit:cover"> ' : '') + cat.name + ' <span class="count-badge">' + items.length + '</span></h3>' +
      '<span class="collapse-icon" onclick="event.stopPropagation();toggleCategory(' + ci + ')">' +
        '<i class="fa ' + (cat.collapsed ? 'fa-chevron-right' : 'fa-chevron-down') + '"></i>' +
      '</span>' +
    '</div>' +
    '<div id="catItems' + ci + '"' + (cat.collapsed ? ' style="display:none"' : '') + '>';
    for (var ii = 0; ii < items.length; ii++) {
      var item = items[ii];
      item._ci = ci; item._ii = ii;
      var hasVar = item.has_variations && item.variations && item.variations.length > 0;
      var displayPrice = hasVar ? 'From ' + formatPrice(getMinVariantPrice(item)) : formatPrice(item.base_price || item.price || 0);
      var oos = item.is_available == 0;
      html += '<div class="menu-item' + (oos ? ' oos' : '') + '" onclick="if(!' + oos + ')showItemDetail(' + ci + ',' + ii + ')">' +
        '<div class="menu-item-img">' +
          '<img src="' + getImageUrl(item.item_image || item.image) + '" alt="' + (item.item_name_translated || item.item_name_en || item.name) + '" loading="lazy">' +
          (item.item_type && (item.item_type === 'Veg' || item.item_type === 'Non Veg' || item.item_type === 'Egg') ? '<div class="veg-icon"><svg width="14" height="14" viewBox="0 0 14 14"><rect x="1" y="1" width="12" height="12" fill="#fff" stroke="' + (item.item_type === 'Veg' ? '#2ecc40' : item.item_type === 'Non Veg' ? '#e53935' : '#ff9800') + '" stroke-width="1.5" rx="2"/><circle cx="7" cy="7" r="3" fill="' + (item.item_type === 'Veg' ? '#2ecc40' : item.item_type === 'Non Veg' ? '#e53935' : '#ff9800') + '"/></svg></div>' : '') +
        '</div>' +
        '<div class="menu-item-content">' +
          '<div class="menu-item-name">' + (item.item_name_translated || item.item_name_en || item.name) + '</div>' +
          '<div class="menu-item-desc">' + ((item.item_description_en || item.desc || '').replace(/\n/g, item.description_format === 'br' ? '<br>' : ' ')) + '</div>' +
          '<div class="menu-item-footer">' +
            '<div class="menu-item-price">' + displayPrice + '</div>' +
            '<div style="display:flex;align-items:center;gap:6px">' +
              (oos ? '<span class="menu-item-oos-badge">Out of Stock</span>' : '') +
              (!oos && item.has_variations ? '<span class="menu-item-variant">Variants</span>' : '') +
              (!oos ? '<span id="qty-' + item.id + '">' + renderQtyControl(item.id, item) + '</span>' : '') +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>';
    }
    html += '</div>';
  }
  el.innerHTML = html;
}

function toggleCategory(ci) {
  menuCategories[ci].collapsed = !menuCategories[ci].collapsed;
  renderMenu();
}

function toggleDropdown() {
  var d = document.getElementById('moreDropdown');
  d.classList.toggle('show');
}
function closeDropdown() {
  document.getElementById('moreDropdown').classList.remove('show');
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.more-btn') && !e.target.closest('.more-dropdown')) {
    closeDropdown();
  }
});

function scrollToSection(id, el) {
  document.querySelectorAll('.nav-item').forEach(function(n) { n.classList.remove('active'); });
  if (el) el.classList.add('active');
  var target = document.getElementById(id);
  if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
  closeDropdown();
}

function getDefaultOrderType() {
  var activeTab = document.querySelector('.order-tab.active');
  if (activeTab) {
    var onclick = activeTab.getAttribute('onclick') || '';
    if (onclick.includes("'delivery'")) return 'delivery';
    if (onclick.includes("'takeaway'")) return 'takeaway';
    if (onclick.includes("'dinein'")) return 'dinein';
  }
  var tabs = document.querySelectorAll('.order-tab');
  for (var i = 0; i < tabs.length; i++) {
    var oc = tabs[i].getAttribute('onclick') || '';
    if (oc.includes("'delivery'")) return 'delivery';
    if (oc.includes("'takeaway'")) return 'takeaway';
    if (oc.includes("'dinein'")) return 'dinein';
  }
  return 'delivery';
}
let currentOrderType = getDefaultOrderType();
let pendingSwitch = null;

function switchOrder(el, type) {
  if (type === currentOrderType) return;
  pendingSwitch = { el, type };
  showOrderTypeModal(currentOrderType, type);
}

function showOrderTypeModal(fromType, toType) {
  var fromCard = document.getElementById('fromCard');
  var toCard = document.getElementById('toCard');

  function setCard(el, type) {
    if (type === 'takeaway') {
      el.className = 'order-type-card card-takeaway';
      el.innerHTML = takeawaySVG() + '<span class="card-label">TakeAway</span>';
    } else if (type === 'dinein') {
      el.className = 'order-type-card card-dinein';
      el.innerHTML = dineinSVG() + '<span class="card-label">Dine In</span>';
    } else {
      el.className = 'order-type-card card-delivery';
      el.innerHTML = deliverySVG() + '<span class="card-label">Delivery</span>';
    }
  }

  setCard(fromCard, fromType);
  setCard(toCard, toType);

  document.getElementById('orderTypeModal').classList.add('active');
}

function closeOrderTypeModal() {
  document.getElementById('orderTypeModal').classList.remove('active');
  pendingSwitch = null;
}

function confirmOrderSwitch() {
  if (!pendingSwitch) return;
  currentOrderType = pendingSwitch.type;
  document.querySelectorAll('.order-tab').forEach(function(t) { t.classList.remove('active'); });
  pendingSwitch.el.classList.add('active');
  pendingSwitch = null;
  closeOrderTypeModal();
  // Save order type to localStorage so cart.php can use it
  try { localStorage.setItem('dvaniOrderType', currentOrderType); } catch(e) {}
}

function takeawaySVG() {
  return '<svg width="48" height="48" viewBox="0 0 48 48" fill="none"><rect x="9" y="15" width="30" height="28" rx="4" fill="#e0e0e0" stroke="#bbb" stroke-width="1.5"/><path d="M9 21 Q14 9 24 9 Q34 9 39 21" fill="none" stroke="#bbb" stroke-width="1.5"/><line x1="20" y1="23" x2="20" y2="35" stroke="#e53935" stroke-width="1.5" stroke-linecap="round"/><line x1="17" y1="25" x2="17" y2="32" stroke="#e53935" stroke-width="1.5" stroke-linecap="round"/><line x1="23" y1="25" x2="23" y2="32" stroke="#e53935" stroke-width="1.5" stroke-linecap="round"/><ellipse cx="30" cy="25" rx="3" ry="4" fill="none" stroke="#e53935" stroke-width="1.5"/><line x1="30" y1="29" x2="30" y2="35" stroke="#e53935" stroke-width="1.5" stroke-linecap="round"/></svg>';
}

function deliverySVG() {
  return '<svg width="48" height="48" viewBox="0 0 48 48" fill="none"><rect x="3" y="18" width="14" height="13" rx="2" fill="#FFC107" stroke="#FF8F00" stroke-width="1.2"/><line x1="10" y1="18" x2="10" y2="31" stroke="#FF8F00" stroke-width="1"/><line x1="3" y1="25" x2="17" y2="25" stroke="#FF8F00" stroke-width="1"/><circle cx="17" cy="38" r="6" fill="none" stroke="#444" stroke-width="1.8"/><circle cx="39" cy="38" r="6" fill="none" stroke="#444" stroke-width="1.8"/><line x1="17" y1="38" x2="39" y2="38" stroke="#444" stroke-width="2"/><line x1="21" y1="38" x2="21" y2="28" stroke="#444" stroke-width="1.5"/><ellipse cx="21" cy="26" rx="4" ry="2" fill="#444"/><line x1="35" y1="38" x2="35" y2="26" stroke="#444" stroke-width="1.5"/><line x1="31" y1="26" x2="40" y2="26" stroke="#444" stroke-width="1.5"/><circle cx="31" cy="19" r="4.5" fill="#e53935"/></svg>';
}

function dineinSVG() {
  return '<svg width="48" height="48" viewBox="0 0 48 48" fill="none"><rect x="6" y="10" width="36" height="28" rx="6" fill="#e8f5e9" stroke="#2e7d32" stroke-width="1.5"/><circle cx="24" cy="20" r="6" fill="none" stroke="#2e7d32" stroke-width="1.5"/><path d="M16 35 C16 30, 20 28, 24 28 C28 28, 32 30, 32 35" fill="none" stroke="#2e7d32" stroke-width="1.5" stroke-linecap="round"/><line x1="8" y1="10" x2="8" y2="6" stroke="#2e7d32" stroke-width="2" stroke-linecap="round"/><line x1="40" y1="10" x2="40" y2="6" stroke="#2e7d32" stroke-width="2" stroke-linecap="round"/><line x1="10" y1="10" x2="38" y2="10" stroke="#2e7d32" stroke-width="3" stroke-linecap="round"/></svg>';
}

function generateQR() {
  if (typeof QRious !== 'undefined') {
    new QRious({
      element: document.getElementById('qrCanvas'),
      value: window.location.href,
      size: 180,
      background: '#fff',
      foreground: '#2d3436',
      level: 'M'
    });
  }
}

function shareProfile() {
  if (navigator.share) {
    navigator.share({ title: window.restaurantName || 'Restaurant', url: window.location.href });
  } else {
    navigator.clipboard.writeText(window.location.href);
    showToast('Link copied to clipboard!');
  }
}

function showQR() {
  var section = document.getElementById('cartSection');
  section.style.display = 'block';
  section.innerHTML = '<h2 style="font-size:18px;font-weight:600;margin:20px 0;color:#1a1b1f">QR Code</h2><div style="text-align:center"><canvas id="qrBig" width="250" height="250" style="margin:0 auto"></canvas><div style="width:60px;height:60px;border-radius:50%;background:#fff;overflow:hidden;margin:-35px auto 0;position:relative;z-index:2"><img src="' + (window.restaurantLogo || '') + '" style="width:100%;height:100%;object-fit:cover"></div></div>';
  if (typeof QRious !== 'undefined') {
    new QRious({ element: document.getElementById('qrBig'), value: window.location.href, size: 250, background: '#fff', foreground: '#2d3436', level: 'M' });
  }
  setTimeout(function() { document.getElementById('cartSection').scrollIntoView({ behavior: 'smooth' }); }, 100);
}

var searchPhrases = [
  'Search your favorite food fast',
  'What are you hungry for?',
  'Find something tasty in seconds',
  'Your next meal is calling...',
];
var phraseIdx = 0, charIdx = 0, isDeleting = false;
function typeEffect() {
  var inp = document.getElementById('searchInput');
  if (!inp) { setTimeout(typeEffect, 500); return; }
  var current = searchPhrases[phraseIdx];
  if (!isDeleting) {
    charIdx++;
    inp.placeholder = current.substring(0, charIdx) + '|';
    if (charIdx === current.length) {
      isDeleting = true;
      setTimeout(typeEffect, 2000);
      return;
    }
  } else {
    charIdx--;
    inp.placeholder = current.substring(0, charIdx) + '|';
    if (charIdx === 0) {
      isDeleting = false;
      phraseIdx = (phraseIdx + 1) % searchPhrases.length;
    }
  }
  setTimeout(typeEffect, isDeleting ? 40 : 70);
}

function fetchAndRender() {
  if (!window.websiteRestaurantId) {
    document.getElementById('menuList').innerHTML = '<div style="text-align:center;padding:40px;color:#999">No restaurant selected</div>';
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
      menuItems = itemsData || [];
      buildCategories();
      loadCart();
      renderMenu();
      updateCartBadge();
      generateQR();
    })
    .catch(function(err) {
      console.error('Failed to load menu data:', err);
      document.getElementById('menuList').innerHTML = '<div style="text-align:center;padding:40px;color:#999">Failed to load menu items</div>';
    });
}

function buildCategories() {
  var catMap = {};
  // First pass: group items by their menu category
  for (var i = 0; i < menuItems.length; i++) {
    var item = menuItems[i];
    var catName = item.menu_name_translated || item.menu_name || item.item_category || 'General';
    if (!catMap[catName]) {
      catMap[catName] = { name: catName, image: null, collapsed: false, items: [] };
      for (var m = 0; m < menus.length; m++) {
        if ((menus[m].menu_name_translated || menus[m].menu_name) === catName || menus[m].id == item.menu_id) {
          catMap[catName].image = menus[m].menu_image;
          break;
        }
      }
    }
    catMap[catName].items.push(item);
  }
  // Build categories respecting the sort_order from the menus API response
  menuCategories = [];
  // First, add categories in the order they appear in the menus array (already sorted by sort_order)
  for (var m = 0; m < menus.length; m++) {
    var menuName = menus[m].menu_name_translated || menus[m].menu_name;
    if (catMap[menuName]) {
      catMap[menuName].image = menus[m].menu_image; // ensure image is up to date
      menuCategories.push(catMap[menuName]);
      delete catMap[menuName];
    }
  }
  // Add any remaining categories that weren't in the menus list
  for (var key in catMap) {
    menuCategories.push(catMap[key]);
  }
}

document.querySelector('.gradient-search-input')?.addEventListener('input', function() {
  var q = this.value.toLowerCase().trim();
  document.querySelectorAll('.category-header, [id^="catItems"]').forEach(function(el) {
    if (!q) { el.style.display = ''; return; }
    if (el.classList.contains('category-header')) {
      el.style.display = '';
    } else if (el.id && el.id.startsWith('catItems')) {
      var items = el.querySelectorAll('.menu-item');
      var hasVisible = false;
      items.forEach(function(item) {
        var match = item.textContent.toLowerCase().includes(q);
        item.style.display = match ? '' : 'none';
        if (match) hasVisible = true;
      });
      el.style.display = hasVisible ? '' : 'none';
      var header = el.previousElementSibling;
      if (header && header.classList.contains('category-header')) {
        header.style.display = hasVisible ? '' : 'none';
      }
    }
  });
});

// --- Product Detail Bottom Sheet ---
var selectedVariant = null;
var sheetQty = 1;
var sheetCatIdx = -1;
var sheetItemIdx = -1;

function showItemDetail(ci, ii) {
  var cat = menuCategories[ci];
  if (!cat || !cat.items[ii]) return;
  var item = cat.items[ii];
  sheetCatIdx = ci;
  sheetItemIdx = ii;
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
  var itemType = item.item_type || ''; // Veg, Non Veg, Egg
  var prepTime = item.preparation_time || '';
  var itemCalories = item.calories || 0;
  var itemCategory = item.item_category || '';
  var menuName = item.menu_name_translated || item.menu_name || '';

  var html = '';

  // Image
  html += '<img class="product-sheet-img" src="' + imgUrl + '" alt="' + itemName + '" onerror="this.style.display=\'none\'">';

  // Body
  html += '<div class="product-sheet-body">';

  // Name with dynamic type icon (Veg / Non-Veg / Egg)
  var typeColor = itemType ? '#2ecc40' : ''; // green for veg
  if (itemType.toLowerCase().indexOf('non') !== -1 || itemType.toLowerCase().indexOf('non-veg') !== -1) {
    typeColor = '#e53935'; // red for non-veg
  } else if (itemType.toLowerCase().indexOf('egg') !== -1) {
    typeColor = '#ff9800'; // orange for egg
  }
  html += '<div class="product-sheet-name-row">';
  if (itemType) {
    html += '<div class="product-sheet-veg" style="border-color:' + typeColor + '"><div class="product-sheet-veg-inner" style="background:' + typeColor + '"></div></div>';
  }
  html += '<span class="product-sheet-name">' + itemName + '</span>' +
'</div>';

  // Badge row: prep time, type label, category
  var badges = [];
  if (prepTime) {
    badges.push('<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;background:#f5f0eb;font-size:11px;font-weight:500;color:var(--primary-red, #e17055)"><i class="fa fa-clock" style="font-size:10px"></i> ' + prepTime + ' min</span>');
  }
  if (itemCalories > 0) {
    badges.push('<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;background:#fef3c7;font-size:11px;font-weight:500;color:#d97706"><i class="fa fa-fire" style="font-size:10px"></i> ' + itemCalories + ' kcal</span>');
  }
  if (itemType) {
    var typeLabel = itemType;
    var typeBadgeColor = '#2ecc40';
    if (itemType.toLowerCase().indexOf('non') !== -1) typeBadgeColor = '#e53935';
    else if (itemType.toLowerCase().indexOf('egg') !== -1) typeBadgeColor = '#ff9800';
    badges.push('<span style="display:inline-flex;align-items:center;gap:3px;padding:4px 10px;border-radius:6px;background:' + typeBadgeColor + '20;font-size:11px;font-weight:500;color:' + typeBadgeColor + '">' + typeLabel + '</span>');
  }
  if (menuName) {
    badges.push('<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;background:#e8f0fe;font-size:11px;font-weight:500;color:#1a73e8"><i class="fa fa-tag" style="font-size:10px"></i> ' + menuName + '</span>');
  }
  if (badges.length > 0) {
    html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 6px">' + badges.join('') + '</div>';
  }

  // Price (will update if variant selected)
  html += '<div class="product-sheet-price" id="sheetPrice">' + formatPrice(itemPrice) + '</div>';

  // Description (only if saved in DB)
  if (itemDesc) {
    var displayDesc = descFmt === 'br' ? itemDesc.replace(/\n/g, '<br>') : itemDesc;
    html += '<div class="product-sheet-desc">' + displayDesc + '</div>';
  }

  // Variants (only if has_variations flag is true and variations data exists)
  if (hasVariants) {
    html += '<div class="product-sheet-section-title">Choose a Variation</div>';
    html += '<div class="variant-chips" id="variantChips">';
    for (var v = 0; v < item.variations.length; v++) {
      var varItem = item.variations[v];
      var varName = varItem.variation_name || varItem.name || 'Option ' + (v + 1);
      var varPrice = parseFloat(varItem.variation_price || varItem.price || 0);
      var varOos = varItem.is_available == 0;
      html += '<div class="variant-chip' + (varOos ? ' disabled' : '') + '" data-idx="' + v + '"' + (varOos ? '' : ' onclick="selectVariant(' + v + ')') + '">' +
        varName +
        (varOos ? ' <span style="color:#999;font-weight:400;font-size:10px">(Unavailable)</span>' : ' <span style="color:var(--primary-red, #e17055);font-weight:600">' + formatPrice(varPrice) + '</span>') +
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
        '<button class="sheet-qty-btn" onclick="sheetChangeQty(-1)"><i class="fa fa-minus"></i></button>' +
        '<span class="sheet-qty-num" id="sheetQtyNum">' + existingQty + '</span>' +
        '<button class="sheet-qty-btn" onclick="sheetChangeQty(1)"><i class="fa fa-plus"></i></button>' +
      '</div>';
    } else {
      html += '<button class="sheet-add-btn" onclick="sheetAddToCart()">Add to Cart · <span id="sheetBtnPrice">' + formatPrice(itemPrice) + '</span></button>';
    }
  }
  html += '</div>';

  document.getElementById('productSheetContent').innerHTML = html;
  document.getElementById('productSheetOverlay').classList.add('active');
  document.body.classList.add('no-scroll');
}

function closeProductSheet(e) {
  if (e && e.target !== e.currentTarget) return;
  document.getElementById('productSheetOverlay').classList.remove('active');
  document.body.classList.remove('no-scroll');
  selectedVariant = null;
  sheetQty = 1;
}

function selectVariant(idx) {
  var cat = menuCategories[sheetCatIdx];
  if (!cat) return;
  var item = cat.items[sheetItemIdx];
  if (!item || !item.variations || !item.variations[idx]) return;

  selectedVariant = idx;

  // Update chip UI
  var chips = document.querySelectorAll('.variant-chip');
  for (var i = 0; i < chips.length; i++) {
    chips[i].classList.toggle('selected', i === idx);
  }

  // Update price - REPLACE with variant price, don't add to base
  var varPrice = parseFloat(item.variations[idx].variation_price || item.variations[idx].price || 0);
  document.getElementById('sheetPrice').textContent = formatPrice(varPrice);
  var btnPrice = document.getElementById('sheetBtnPrice');
  if (btnPrice) btnPrice.textContent = formatPrice(varPrice);
}

function sheetChangeQty(delta) {
  var itemId = getSheetItemId();
  if (!itemId) return;
  var cat = menuCategories[sheetCatIdx];
  var item = cat ? cat.items[sheetItemIdx] : null;
  var hasVar = item && item.has_variations && item.variations && item.variations.length > 0;

  if (hasVar && selectedVariant !== null) {
    var key = itemId + '_v' + selectedVariant;
    var ci = getCartItem(itemId, selectedVariant);
    var newQty = Math.max(0, ci.qty + delta);
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
  updateCartBadge();
  document.getElementById('sheetQtyNum').textContent = getItemTotalQty(itemId);
  var listEl = document.getElementById('qty-' + itemId);
  if (listEl) listEl.innerHTML = renderQtyControl(itemId, item);
  if (getItemTotalQty(itemId) === 0) closeProductSheet();
}

function sheetAddToCart() {
  var itemId = getSheetItemId();
  if (!itemId) return;
  var cat = menuCategories[sheetCatIdx];
  if (!cat) return;
  var item = cat.items[sheetItemIdx];
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

function getSheetItemId() {
  var cat = menuCategories[sheetCatIdx];
  if (!cat || !cat.items[sheetItemIdx]) return null;
  return cat.items[sheetItemIdx].id;
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
  if (window.showInstallApp) showInstallButtons();
});

window.addEventListener('appinstalled', function() {
  deferredPrompt = null;
  hideInstallButtons();
  // App was installed successfully
});

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

function checkRestaurantStatus() {
  if (window.openingHours && !isRestaurantOpen(window.openingHours)) {
    showClosedModal();
    var badge = document.getElementById('openStatusIcon');
    if (badge) badge.innerHTML = '<span class="closed-badge"><i class="fa fa-clock"></i> Closed</span>';
  }
}

function showInstallButtons() {
  var dl = document.getElementById('installDropdownLink');
  var cl = document.getElementById('installCardLink');
  if (dl) dl.style.display = '';
  if (cl) cl.style.display = '';
}

function hideInstallButtons() {
  var dl = document.getElementById('installDropdownLink');
  var cl = document.getElementById('installCardLink');
  if (dl) dl.style.display = 'none';
  if (cl) cl.style.display = 'none';
}

function promptInstall() {
  if (!deferredPrompt) {
    showInstallGuide();
    return;
  }
  deferredPrompt.prompt();
  deferredPrompt.userChoice.then(function(choiceResult) {
    if (choiceResult.outcome === 'accepted') {
      // User accepted install prompt
    } else {
      // User dismissed install prompt
    }
    deferredPrompt = null;
    hideInstallButtons();
  });
}

function showInstallGuide() {
  var overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px';
  overlay.onclick = function(e) { if (e.target === overlay) overlay.remove(); };
  overlay.innerHTML = '<div style="background:#fff;border-radius:20px;max-width:360px;width:100%;padding:32px 24px;text-align:center;animation:fadeIn 0.2s">' +
    '<div style="font-size:48px;margin-bottom:12px">📱</div>' +
    '<h3 style="font-size:18px;font-weight:600;color:#1a1b1f;margin:0 0 6px">Install This App</h3>' +
    '<p style="font-size:13px;color:#666;margin:0 0 20px;line-height:1.5">' +
      'To install, open your browser menu and select<br><strong>"Add to Home Screen"</strong>' +
    '</p>' +
    '<div style="display:flex;flex-direction:column;gap:6px;font-size:12px;color:#888;margin-bottom:20px">' +
      '<div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:#f5f5f5;border-radius:8px">' +
        '<span style="background:#2d3436;color:#fff;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0">1</span>' +
        '<span>Tap the <strong>⋮</strong> or <strong>Share</strong> icon</span>' +
      '</div>' +
      '<div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:#f5f5f5;border-radius:8px">' +
        '<span style="background:#2d3436;color:#fff;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0">2</span>' +
        '<span>Scroll and select <strong>"Add to Home Screen"</strong></span>' +
      '</div>' +
      '<div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:#f5f5f5;border-radius:8px">' +
        '<span style="background:#2d3436;color:#fff;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0">3</span>' +
        '<span>Tap <strong>"Add"</strong> to confirm</span>' +
      '</div>' +
    '</div>' +
    '<button onclick="this.closest(\'div[style]\').remove()" style="padding:10px 32px;border:none;border-radius:10px;background:linear-gradient(135deg,var(--primary-red, #e17055),var(--dark-red, #d63031));color:#fff;font-size:14px;font-weight:600;cursor:pointer;font-family:var(--site-font)">Got it!</button>' +
  '</div>';
  document.body.appendChild(overlay);
}

function fitTextToContainer(containerSel, textSel, maxFontSize, minFontSize) {
  var el = document.querySelector(containerSel);
  if (!el) return;
  var textSpan = textSel ? el.querySelector(textSel) : el;
  if (!textSpan) return;
  // Calculate space taken by non-text children (icons, etc.)
  var otherWidth = 0;
  for (var i = 0; i < el.children.length; i++) {
    if (el.children[i] !== textSpan) {
      otherWidth += el.children[i].offsetWidth || 0;
    }
  }
  // Account for CSS gaps between flex items
  var gapStyle = window.getComputedStyle(el).gap || '6px';
  var gap = parseFloat(gapStyle) || 6;
  var gapsWidth = (el.children.length - 1) * gap;
  var maxWidth = el.clientWidth - otherWidth - gapsWidth - 2;
  if (maxWidth <= 10) return;
  maxFontSize = maxFontSize || 22;
  minFontSize = minFontSize || 10;
  var fontSize = maxFontSize;
  textSpan.style.fontSize = fontSize + 'px';
  while (textSpan.scrollWidth > maxWidth && fontSize > minFontSize) {
    fontSize -= 0.5;
    textSpan.style.fontSize = fontSize + 'px';
  }
}

function fitRestaurantName() {
  fitTextToContainer('.rest-name', '.rest-name-text', 22, 10);
}

document.addEventListener('DOMContentLoaded', function() {
  setTimeout(typeEffect, 500);
  fetchAndRender();
  checkRestaurantStatus();
  loadCouponCarousel();
  setTimeout(fitRestaurantName, 100);
  setTimeout(fitRestaurantName, 500);
  var fitTimer = null;
  window.addEventListener('resize', function() {
    clearTimeout(fitTimer);
    fitTimer = setTimeout(fitRestaurantName, 100);
  });
  // Apply background theme from database
  if (window.backgroundTheme) {
    var bgEl = document.getElementById('homeSection');
    if (bgEl) {
      bgEl.style.backgroundImage = 'url(' + window.backgroundTheme + ')';
      bgEl.style.backgroundSize = 'cover';
      bgEl.style.backgroundPosition = 'center';
    }
  }
  // Save initial order type to localStorage for cart.php
  try { localStorage.setItem('dvaniOrderType', currentOrderType); } catch(e) {}
});

function loadCouponCarousel() {
  var rid = document.querySelector('meta[name=restaurant-id]')?.content || '';
  if (!rid) return;
  fetch('../api/get_coupons.php?restaurant_id=' + encodeURIComponent(rid))
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.success || !data.coupons || data.coupons.length === 0) {
        var carousel = document.getElementById('couponCarousel');
        if (carousel) carousel.style.display = 'none';
        return;
      }
      var inner = document.getElementById('couponCarouselInner');
      if (!inner) return;
      var gradients = [
        'linear-gradient(135deg, #ff4081, #e91e63)',
        'linear-gradient(135deg, #f50057, #c2185b)',
        'linear-gradient(135deg, #e91e63, #ad1457)',
        'linear-gradient(135deg, #ff1744, #d50000)',
        'linear-gradient(135deg, #f06292, #e91e63)'
      ];
      var decos = ['🎁', '🎉', '✨', '🎊', '💫'];
      var barcodeH = [12, 18, 8, 16, 12, 20, 8, 14, 18, 10, 16, 12, 16, 20, 10];
      inner.innerHTML = data.coupons.map(function(c, i) {
        var gradient = gradients[i % gradients.length];
        var deco = decos[i % decos.length];
        var discountLabel = c.discount_type === 'percent'
          ? 'Flat ' + c.discount_value + '% OFF'
          : 'Flat ' + getCurrencySymbol() + parseFloat(c.discount_value).toFixed(0) + ' OFF';
        var desc = c.description || '';
        var bcLines = barcodeH.map(function(h, j) {
          var w = ((i * 7 + j * 3) % 2 === 0) ? 3 : 2;
          return '<div class="coupon-barcode-line" style="width:' + w + 'px;height:' + h + 'px"></div>';
        }).join('');
        return '<div class="coupon-card" style="background:' + gradient + '" onclick="copyCoupon(\'' + c.coupon_code + '\')">' +
          '<div class="coupon-card-barcode">' + bcLines + '</div>' +
          '<div class="coupon-card-content">' +
            '' +
            '<div class="coupon-card-discount">' + discountLabel + '</div>' +
            '<div class="coupon-card-divider"></div>' +
            '<div class="coupon-card-code-row">' +
              '<span class="use-label">USE</span>' +
              '<span class="coupon-code-val">' + c.coupon_code + '</span>' +
              '<span class="copy-icon" onclick="event.stopPropagation();copyCoupon(\'' + c.coupon_code + '\')">📋</span>' +
              (desc ? '<span class="cpn-separator">|</span><span class="cpn-desc-tag">' + desc.substring(0, 25) + '</span>' : '') +
            '</div>' +
          '</div>' +
        '</div>';
      }).join('');
      // Start auto-scroll after coupons are rendered
      startCouponAutoScroll();
    })
    .catch(function() {});
}

// Coupon carousel: touch/mouse + smooth continuous auto-scroll (requestAnimationFrame)
(function() {
  var carousel = document.getElementById('couponCarouselInner');
  if (!carousel) return;

  var isUserInteracting = false;
  var rafId = null;
  var scrollPaused = false;
  var scrollSpeed = 0.6; // px per frame (~36px/sec)
  var pauseAfterScroll = 2000; // pause 2s at each end before reversing
  var pauseTimer = null;
  var direction = 1; // 1 = right, -1 = left

  function autoScrollLoop() {
    if (scrollPaused || isUserInteracting) {
      rafId = requestAnimationFrame(autoScrollLoop);
      return;
    }
    var maxScroll = carousel.scrollWidth - carousel.clientWidth;
    if (maxScroll <= 0) return; // nothing to scroll

    carousel.scrollLeft += scrollSpeed * direction;

    // Reached right end - pause then reverse
    if (carousel.scrollLeft >= maxScroll - 1) {
      carousel.scrollLeft = maxScroll;
      direction = -1;
      scrollPaused = true;
      pauseTimer = setTimeout(function() { scrollPaused = false; }, pauseAfterScroll);
      return;
    }
    // Reached left end - pause then reverse
    if (carousel.scrollLeft <= 0) {
      carousel.scrollLeft = 0;
      direction = 1;
      scrollPaused = true;
      pauseTimer = setTimeout(function() { scrollPaused = false; }, pauseAfterScroll);
      return;
    }

    rafId = requestAnimationFrame(autoScrollLoop);
  }

  // --- Touch handling ---
  var startX = 0, startY = 0;
  carousel.addEventListener('touchstart', function(e) {
    startX = e.touches[0].clientX;
    startY = e.touches[0].clientY;
    isUserInteracting = true;
    if (pauseTimer) clearTimeout(pauseTimer);
  }, { passive: true });
  carousel.addEventListener('touchmove', function(e) {
    var dx = Math.abs(e.touches[0].clientX - startX);
    var dy = Math.abs(e.touches[0].clientY - startY);
    if (dx > 8 && dx > dy) e.preventDefault();
  }, { passive: false });
  carousel.addEventListener('touchend', function() {
    isUserInteracting = false;
    // Detect direction from final scroll position
    direction = 1;
  }, { passive: true });

  // --- Mouse drag handling ---
  var isDragging = false, dragStartX = 0, dragScrollLeft = 0;
  carousel.addEventListener('mousedown', function(e) {
    isDragging = true;
    isUserInteracting = true;
    dragStartX = e.pageX;
    dragScrollLeft = carousel.scrollLeft;
    carousel.style.cursor = 'grabbing';
    carousel.style.userSelect = 'none';
    e.preventDefault();
  });
  document.addEventListener('mousemove', function(e) {
    if (!isDragging) return;
    var dx = e.pageX - dragStartX;
    carousel.scrollLeft = dragScrollLeft - dx;
  });
  document.addEventListener('mouseup', function() {
    if (!isDragging) return;
    isDragging = false;
    isUserInteracting = false;
    carousel.style.cursor = '';
    carousel.style.userSelect = '';
    // Determine direction based on where we ended up
    var maxScroll = carousel.scrollWidth - carousel.clientWidth;
    direction = carousel.scrollLeft >= maxScroll - 10 ? -1 : 1;
  });

  // Pause on hover (desktop)
  carousel.addEventListener('mouseenter', function() { isUserInteracting = true; });
  carousel.addEventListener('mouseleave', function() {
    if (!isDragging) isUserInteracting = false;
  });

  // Expose start for loadCouponCarousel to call after coupons render
  window.startCouponAutoScroll = function() {
    if (rafId) cancelAnimationFrame(rafId);
    if (pauseTimer) clearTimeout(pauseTimer);
    scrollPaused = false;
    direction = 1;
    carousel.scrollLeft = 0;
    rafId = requestAnimationFrame(autoScrollLoop);
  };
})();

function copyCoupon(code) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(code).then(function() {
      showToast('Coupon ' + code + ' copied! Apply it at checkout.');
    }).catch(function() {
      fallbackCopy(code);
    });
  } else {
    fallbackCopy(code);
  }
}

function fallbackCopy(code) {
  var ta = document.createElement('textarea');
  ta.value = code;
  ta.style.position = 'fixed';
  ta.style.left = '-9999px';
  document.body.appendChild(ta);
  ta.select();
  try {
    document.execCommand('copy');
    showToast('Coupon ' + code + ' copied! Apply it at checkout.');
  } catch(e) {}
  document.body.removeChild(ta);
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
