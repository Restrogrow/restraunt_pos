<?php require_once __DIR__ . '/header.php';
// Preview links (layout gallery): ?preview_card=<design> shows that design without saving it
if (isset($_GET['preview_card']) && in_array($_GET['preview_card'], THEME_MENU_CARD_STYLES, true)) { $menu_card_style = $_GET['preview_card']; }
?>
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
<meta name="apple-mobile-web-app-title" content="Menu - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?>">
<link rel="manifest" href="manifest.php<?php echo $restaurant_id ? '?restaurant_id=' . urlencode($restaurant_id) : ''; ?>">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars($restaurant_logo ?? $local_placeholder_svg, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? $local_favicon_svg, ENT_QUOTES, 'UTF-8'); ?>">
<title>Menu - <?php echo htmlspecialchars($restaurant_name ?? 'Dvani Cafe & Grill', ENT_QUOTES, 'UTF-8'); ?></title>
<?php require_once __DIR__ . '/font_links.php'; echo websiteFontLinks(['poppins', 'fontawesome', 'bootstrap-icons']); ?>
<?php if (!empty($font_family_google_param)): ?><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=<?php echo $font_family_google_param; ?>:wght@300;400;500;600;700&display=swap" rel="stylesheet"><?php endif; ?>
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
  /* Rating/OFF chip + price colours: same Website Appearance > Bestsellers settings as the home page */
  --bsl-badge: <?php echo htmlspecialchars($bestseller_style['badge_color'], ENT_QUOTES, 'UTF-8'); ?>;
  --bsl-price: <?php echo htmlspecialchars($bestseller_style['price_color'], ENT_QUOTES, 'UTF-8'); ?>;
  --bsl-add: <?php echo htmlspecialchars($bestseller_style['add_color'], ENT_QUOTES, 'UTF-8'); ?>;
<?php if (!empty($category_heading_bg)): ?>  --cat-heading-bg: <?php echo htmlspecialchars($category_heading_bg, ENT_QUOTES, 'UTF-8'); ?>;
<?php endif; ?>}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
  height: 100%; overflow: hidden;
}
body {
  font-family: var(--site-font);
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
<?php if ($host === 'triposhsymmetry.in'): ?>
  /* TEMPORARY (PhonePe approval, added 2026-08-18 — remove in ~2 days) */
  .phone-frame { max-width: 100%; margin: 0; border-radius: 0; }
<?php endif; ?>
}

.bg-wrapper {
  background: #fff;
  display: flex;
  flex-direction: column;
  height: 100%;
}
/* Scrolling down the dishes slides the top bar (veg toggle, filter...) up out of view
   (scrolling up brings them back); see initMenuChromeAutoHide() */
.pr-share-header { transition: margin-top 0.28s ease; }
/* no automatic scroll-position nudging when the bar resizes the scroll area */
.main-content { overflow-anchor: none; }
/* Category buttons: press feedback and a small pop when one is selected */
.side-nav-btn { transition: transform 0.18s ease, background 0.25s ease, color 0.25s ease, box-shadow 0.25s ease; }
.side-nav-btn:active { transform: scale(0.94); }
.side-nav-btn img { transition: transform 0.25s ease, box-shadow 0.25s ease; }
.side-nav-btn.active { animation: catPop 0.38s ease; box-shadow: 0 6px 14px color-mix(in srgb, var(--primary-red, #e17055) 35%, transparent); }
.side-nav-btn.active img { transform: scale(1.06); box-shadow: 0 0 0 2px rgba(255,255,255,0.9); }
@keyframes catPop { 0% { transform: scale(0.9); } 60% { transform: scale(1.06); } 100% { transform: scale(1); } }
/* Cut-out category pictures (Website Appearance > Category Pictures: Cut-out):
   the whole picture (transparent PNG of the dish) floating with a soft shadow,
   no tile; the selected category gets a coloured, underlined label */
.layout-container.cat-cutout:not(.nav-top) .side-nav .side-nav-btn { background: transparent; color: #1f2937; box-shadow: none; border-radius: 10px; }
.layout-container.cat-cutout:not(.nav-top) .side-nav .side-nav-btn img { width: 62px; height: 62px; border-radius: 0; object-fit: contain; box-shadow: none; filter: drop-shadow(0 4px 5px rgba(0,0,0,0.18)); }
.layout-container.cat-cutout:not(.nav-top) .side-nav .side-nav-btn.active { background: transparent; color: var(--primary-red, #e53935); font-weight: 700; box-shadow: inset 0 -3px 0 var(--primary-red, #e53935); }
.layout-container.cat-cutout:not(.nav-top) .side-nav .side-nav-btn.active img { transform: scale(1.08); box-shadow: none; }
@media (prefers-reduced-motion: reduce) {
  .pr-share-header, .side-nav-btn, .side-nav-btn img { transition: none; }
  .side-nav-btn.active { animation: none; }
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
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff; border: none; cursor: pointer; font-size: 22px;
  flex-shrink: 0;
}
.category-breadcrumb {
  display: flex; align-items: center;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
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
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
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
  gap: 1px; /* icon and label sit close; the room goes to a bigger icon */
  width: 72px;
  padding: 7px 4px;
  border: none;
  background: transparent;
  border-radius: 8px;
  cursor: pointer;
  font-size: 11px;
  font-family: var(--site-font);
  color: #1a1b1f;
  font-weight: 500;
  line-height: 1.2;
  text-align: center;
  transition: all 0.2s;
}
.side-nav-btn img {
  width: 48px; height: 48px;
  border-radius: 50%;
  object-fit: cover;
}
.side-nav-btn.active {
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff;
}

/* Website Appearance > Menu categories: "Top" turns the left sidebar into a
   horizontally scrolling bar above the dishes (same buttons and behaviour) */
.layout-container.nav-top { flex-direction: column; }
.layout-container.nav-top .side-nav { width: auto; height: auto; flex-direction: row; flex-shrink: 0; gap: 6px; padding: 6px 10px; border-right: 0; border-bottom: 1.5px solid #ccc; overflow-x: auto; overflow-y: hidden; }
.layout-container.nav-top .side-nav { overscroll-behavior-x: contain; -webkit-overflow-scrolling: touch; }
.layout-container.nav-top .side-nav.is-dragging { scroll-behavior: auto; cursor: grabbing; }
.layout-container.nav-top .side-nav.is-dragging .side-nav-btn { pointer-events: none; }
.layout-container.nav-top .sub-views { flex-shrink: 0; }
.layout-container.nav-top .side-nav-btn { width: auto; min-width: 68px; padding: 3px 10px; gap: 1px; white-space: nowrap; }
.layout-container.nav-top .side-nav-btn img { width: 44px; height: 44px; }

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
  padding-bottom: 110px;
}
body.has-ms-hint .main-content.has-cart { padding-bottom: 160px; }
.main-content::-webkit-scrollbar { display: none; }

.category-overview {
  /* Automatic: very light shade of the ADD button colour (Website Appearance >
     Bestsellers: ADD colour); Category Heading Colour can override it */
  background: var(--cat-heading-bg, color-mix(in srgb, var(--bsl-add) 12%, #fff));
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
  display: flex; align-items: center; gap: 10px;
  width: 100%; height: 44px;
  padding: 0 6px 0 14px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  box-shadow: 0 2px 10px rgba(0,0,0,0.05);
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.search-container:focus-within {
  border-color: var(--primary-red, #e53935);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary-red, #e53935) 14%, transparent);
}
.search-lead { color: var(--primary-red, #e53935); font-size: 17px; flex-shrink: 0; }
.search-field { position: relative; flex: 1; min-width: 0; height: 100%; }
.search-input {
  width: 100%; height: 100%;
  border: none; outline: none; padding: 0;
  font-size: 14px; font-family: var(--site-font);
  background: transparent;
  color: #111;
}
.search-input::-webkit-search-cancel-button { display: none; }
/* "Search for 'Paneer Tikka'" — dish names from this menu roll by */
.search-hint {
  position: absolute; inset: 0;
  display: flex; align-items: center; gap: 4px;
  font-size: 14px; color: #9ca3af;
  white-space: nowrap; overflow: hidden;
  pointer-events: none;
}
.search-container.has-value .search-hint { display: none; }
.search-hint-word { position: relative; display: inline-block; min-width: 0; overflow: hidden; text-overflow: ellipsis; color: #4b5563; font-weight: 600; }
.search-hint-word.roll { animation: searchHintRoll 0.45s ease; }
@keyframes searchHintRoll { from { transform: translateY(100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
@media (prefers-reduced-motion: reduce) { .search-hint-word.roll { animation: none; } }
.search-clear {
  display: none; flex-shrink: 0;
  width: 30px; height: 30px;
  border: 0; border-radius: 50%;
  background: #f3f4f6; color: #6b7280;
  font-size: 16px; line-height: 1; cursor: pointer;
  align-items: center; justify-content: center;
}
.search-container.has-value .search-clear { display: flex; }

.product-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  padding-top: 10px;
}

/* Layout variant: List — single-column horizontal rows (image left, details right) */
.product-grid.layout-list {
  display: flex;
  flex-direction: column;
  grid-template-columns: none;
}
.product-grid.layout-list .card {
  flex-direction: row;
  align-items: stretch;
}
.product-grid.layout-list .card-img-wrap {
  width: 110px;
  flex-shrink: 0;
}
.product-grid.layout-list .card-img-wrap img {
  width: 110px;
  height: 100%;
  min-height: 96px;
}
.product-grid.layout-list .card-body {
  padding: 10px 14px;
  justify-content: center;
}

/* Layout variant: Magazine — single-column, large editorial-style images */
.product-grid.layout-magazine {
  display: flex;
  flex-direction: column;
  grid-template-columns: none;
  gap: 20px;
}
.product-grid.layout-magazine .card {
  flex-direction: column;
}
.product-grid.layout-magazine .card-img-wrap img {
  height: 220px;
}
.product-grid.layout-magazine .card-body {
  padding: 14px 16px 16px;
}
.product-grid.layout-magazine .card-name {
  font-size: 17px;
}
.product-grid.layout-magazine .card-price {
  font-size: 16px;
}

@media (max-width: 480px) {
  .product-grid.cols-1 {
    grid-template-columns: 1fr;
  }
}

.card {
  background: #fff;
  border-radius: var(--card-radius, 14px);
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
  height: auto;
  aspect-ratio: 5 / 4; /* a bit shorter than square, whatever the card width */
  border-radius: var(--card-radius, 14px); /* rounded at the bottom too, not only the top */
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
/* Rating + "₹X OFF" chips under the dish name (same as the home page list) */
/* Chips on the dish photo: "₹X OFF" bottom-left, rating bottom-right */
.img-chip { position: absolute; bottom: 3px; z-index: 1; box-shadow: 0 1px 4px rgba(0,0,0,0.25); }
.img-chip-save { left: 3px; display: inline-flex; align-items: center; padding: 2px 7px; border-radius: 999px; background: #fff; color: var(--bsl-badge); font-size: 10.5px; font-weight: 800; line-height: 1.3; }
.img-chip-rate { right: 3px; }
/* Cards line up whether or not a dish has an offer: the name takes only the
   space it needs, and price + button sit at the bottom */
.card-name { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; line-clamp: 2; overflow: hidden; }
.card-price { min-height: 28px; margin-top: auto; display: flex; align-items: center; }
.card-body > span[id^="btn-"] { display: block; }
.card-body .add-btn { white-space: nowrap; padding-left: 6px; padding-right: 6px; }
/* Narrow cards (small phones, 2 columns): smaller chips, rating count
   hidden, so the two chips never overlap on the photo */
.card-img-wrap { container-type: inline-size; }
@container (max-width: 150px) {
  .img-chip { bottom: 2px; }
  .img-chip-save { left: 2px; padding: 1px 5px; font-size: 9.5px; }
  .img-chip-rate { right: 2px; padding: 1px 5px; font-size: 9.5px; }
  .img-chip-rate .bsl-rating-count { display: none; }
}
/* ...and a more compact price pill so price + struck price still fit */
.card-body { container-type: inline-size; }
@container (max-width: 130px) {
  .card-price .mrp-now { padding: 3px 6px; font-size: 12.5px; }
  .card-price .mrp-was { padding: 2px 5px 2px 4px; }
  .card-price .mrp-was s { font-size: 10px; }
}
.bsl-rating { display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; border-radius: 999px; background: var(--bsl-badge); color: #fff; font-size: 11px; font-weight: 800; line-height: 1.3; }
.bsl-rating i { font-size: 9px; }
.bsl-rating-count { margin-left: 2px; font-weight: 600; opacity: 0.9; }
/* Price pill: current price in a coloured block; with an offer, the struck
   regular price sits on the right of the same pill */
.mrp-price { display: inline-flex; align-items: stretch; border: 1.5px solid var(--bsl-price); border-radius: 10px; overflow: hidden; background: #fff; line-height: 1.1; vertical-align: middle; }
.mrp-now { display: flex; align-items: center; padding: 4px 9px; background: var(--bsl-price); color: #fff; font-size: 14px; font-weight: 800; }
.mrp-price.has-mrp .mrp-now { border-radius: 0 9px 9px 0; }
.mrp-was { display: flex; flex-direction: column; justify-content: center; align-items: flex-end; padding: 2px 8px 2px 7px; color: #6b7280; }
.mrp-was small { font-size: 8.5px; font-weight: 600; letter-spacing: 0.05em; }
.mrp-was s { font-size: 11px; font-weight: 700; }
[data-price-style="outline"] .mrp-now { background: #fff; color: var(--bsl-price); }
[data-price-style="soft"] .mrp-now { background: color-mix(in srgb, var(--bsl-price) 14%, #fff); color: var(--bsl-price); }
[data-price-style="outline"] .mrp-price.has-mrp .mrp-now, [data-price-style="soft"] .mrp-price.has-mrp .mrp-now { border-right: 1.5px solid var(--bsl-price); }

.card.oos { pointer-events: none; }
.card.oos .card-img-wrap img { filter: grayscale(1); opacity: 0.6; }
.card.oos .card-body, .card.oos .img-chip { opacity: 0.55; }
/* "Out of Stock" label centred on the dish photo: red text, red border */
.img-oos { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 2; padding: 4px 10px; border: 1.5px solid #dc2626; border-radius: 6px; background: #fef2f2; color: #dc2626; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap; }

.add-btn {
  width: 100%;
  padding: 6px 12px;
  border: none;
  border-radius: var(--btn-radius, 8px);
  background: var(--primary-yellow, #FFD100);
  color: #1a1b1f;
  font-size: 12px;
  font-family: var(--site-font);
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
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  border-radius: var(--btn-radius, 8px);
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
  font-family: var(--site-font);
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

/* Floating "View Cart" bar: rounded on every side, lifted off the edges */
.checkout-bar {
  position: fixed; bottom: 12px; left: 50%; transform: translateX(-50%);
  width: calc(100% - 24px); max-width: <?php echo ($host === 'triposhsymmetry.in') ? 'calc(100% - 24px)' : '401px'; ?>;
  background: linear-gradient(135deg, var(--checkout-color, #F70000), var(--checkout-color-dark, #DA020E));
  display: none; justify-content: space-between; align-items: center; gap: 12px;
  padding: 10px 10px 10px 18px; z-index: 100;
  border-radius: 16px;
  box-shadow: 0 12px 28px color-mix(in srgb, var(--checkout-color, #F70000) 35%, transparent), 0 4px 10px rgba(0,0,0,0.12);
}
@media (min-width: 768px) {
  .checkout-bar { bottom: 32px; }
}
.checkout-bar.show { display: flex; animation: cartBarIn 0.35s cubic-bezier(.22,1,.36,1); }
@keyframes cartBarIn { from { transform: translate(-50%, 24px); opacity: 0; } to { transform: translate(-50%, 0); opacity: 1; } }
.checkout-bar .total-label { color: #fff; font-weight: 700; font-size: 14.5px; white-space: nowrap; }
.checkout-bar .total-label .sep { opacity: 0.55; margin: 0 6px; font-weight: 400; }
.checkout-bar .checkout-btn {
  color: var(--checkout-color, #F70000); font-weight: 800; font-size: 14px;
  background: #fff; border: none; border-radius: 11px; padding: 10px 14px; cursor: pointer;
  display: flex; align-items: center; gap: 8px; white-space: nowrap;
  font-family: var(--site-font); box-shadow: 0 2px 6px rgba(0,0,0,0.12);
  transition: transform 0.15s ease;
}
.checkout-bar .checkout-btn:active { transform: scale(0.96); }
.checkout-bar .checkout-btn:hover { opacity: .92; }
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
  font-family: var(--site-font);
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
  /* sits on top of the photo, so no white strip above it */
  position: absolute;
  top: 8px;
  left: 50%;
  transform: translateX(-50%);
  width: 40px;
  height: 4px;
  background: rgba(255,255,255,0.85);
  box-shadow: 0 0 4px rgba(0,0,0,0.25);
  border-radius: 2px;
  z-index: 11;
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
/* Same-size photo frame for every dish (5:4, the dashboard crop shape).
   The photo fills it edge to edge, so there are no bars or blurred edges. */
.product-sheet-media {
  position: relative;
  width: 100%;
  aspect-ratio: 5 / 4; /* same shape as the dashboard crop */
  max-height: 45vh;
  overflow: hidden;
  background: #f5f5f5;
  flex-shrink: 0;
}
.product-sheet-img {
  position: relative;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center;
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
  background: #fdf2f2;
  color: #2d3436;
  font-weight: 600;
  box-shadow: 0 2px 8px rgba(45,52,54,0.15);
}
.variant-chip.disabled { opacity: 0.4; cursor: not-allowed; background: #f5f5f5; }
/* Offers bubble (Offers > Offer Filters in the dashboard) */
.offer-bubble {
  position: fixed; z-index: 90;
  right: max(16px, calc(50% - 212px + 16px));
  bottom: 92px;
  width: 56px; height: 56px; border: 0; border-radius: 50%;
  background: linear-gradient(135deg, var(--primary-red, #e53935), #ff7a45);
  color: #fff; font-size: 22px; cursor: pointer;
  display: none; align-items: center; justify-content: center;
  box-shadow: 0 8px 20px color-mix(in srgb, var(--primary-red, #e53935) 40%, transparent);
  animation: offerBubbleIn 0.4s ease;
}
.offer-bubble.show { display: flex; }
.offer-bubble::before {
  content: ""; position: absolute; inset: -6px; border-radius: 50%;
  border: 2px solid color-mix(in srgb, var(--primary-red, #e53935) 45%, transparent);
  animation: offerBubblePulse 2.2s ease-out infinite;
}
.offer-bubble:active { transform: scale(0.94); }
.offer-bubble-count {
  position: absolute; top: -2px; right: -2px;
  min-width: 20px; height: 20px; padding: 0 5px; border-radius: 999px;
  background: #fff; color: var(--primary-red, #e53935);
  font-size: 11px; font-weight: 800; line-height: 20px; text-align: center;
  box-shadow: 0 1px 4px rgba(0,0,0,0.2);
}
.offer-bubble.active { background: #1f2937; box-shadow: 0 8px 20px rgba(0,0,0,0.3); }
.offer-bubble.active::before { display: none; }
@keyframes offerBubbleIn { from { transform: scale(0.4); opacity: 0; } to { transform: scale(1); opacity: 1; } }
@keyframes offerBubblePulse { 0% { transform: scale(0.9); opacity: 1; } 100% { transform: scale(1.35); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .offer-bubble, .offer-bubble::before { animation: none; } }
.offer-sheet { padding: 18px 18px 22px; }
.offer-sheet h3 { margin: 4px 0 2px; font-size: 18px; font-weight: 800; color: #1a1b1f; }
.offer-sheet p { margin: 0 0 14px; font-size: 12.5px; color: #6b7280; }
.offer-tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.offer-tile {
  display: flex; align-items: center; gap: 10px; text-align: left;
  padding: 12px; border: 1px solid #fed7aa; border-radius: 14px;
  background: linear-gradient(135deg, #fff7ed, #fff);
  font-family: var(--site-font); cursor: pointer;
}
.offer-tile.on { border-color: var(--primary-red, #e53935); box-shadow: 0 0 0 2px color-mix(in srgb, var(--primary-red, #e53935) 25%, transparent); }
.offer-tile-icon { font-size: 24px; line-height: 1; }
.offer-tile b { display: block; font-size: 13.5px; color: #1f2937; line-height: 1.25; }
.offer-tile small { font-size: 11.5px; color: #9a3412; font-weight: 600; }
.offer-active-bar {
  grid-column: 1 / -1; display: flex; align-items: center; justify-content: space-between; gap: 10px;
  padding: 8px 10px 8px 12px; border-radius: 12px;
  background: color-mix(in srgb, var(--primary-red, #e53935) 8%, #fff);
  font-size: 13px; font-weight: 700; color: #1f2937;
}
.offer-active-bar button { border: 0; border-radius: 999px; padding: 6px 12px; background: #fff; color: var(--primary-red, #e53935); font: inherit; font-size: 12px; cursor: pointer; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
/* Milestone offer hint, just above the checkout bar */
.ms-hint { position: absolute; left: 0; right: 0; bottom: calc(100% + 8px); display: none; align-items: center; justify-content: center; gap: 7px; padding: 9px 12px 11px; background: #fff; border-radius: 12px; border: 1px solid #f1f1f1; box-shadow: 0 6px 18px rgba(0,0,0,0.08); color: #374151; font-size: 12.5px; font-weight: 600; text-align: center; overflow: hidden; }
.ms-hint.show { display: flex; animation: msHintIn 0.35s ease; }
.ms-hint i { color: var(--primary-red, #e53935); }
.ms-hint b { color: var(--primary-red, #e53935); font-weight: 800; }
.ms-hint.done i, .ms-hint.done b { color: #16a34a; }
.ms-hint-bar { position: absolute; left: 0; bottom: 0; height: 3px; background: linear-gradient(90deg, #fbbf24, var(--primary-red, #e53935)); transition: width 0.8s cubic-bezier(.22,1,.36,1); }
.ms-hint.done .ms-hint-bar { background: #16a34a; }
.ms-hint .ms-hint-text.swap { animation: msHintSwap 0.4s ease; }
@keyframes msHintIn { from { transform: translateY(100%); opacity: 0; } to { transform: none; opacity: 1; } }
@keyframes msHintSwap { from { transform: translateY(8px); opacity: 0; } to { transform: none; opacity: 1; } }
@media (prefers-reduced-motion: reduce) { .ms-hint.show, .ms-hint .ms-hint-text.swap { animation: none; } .ms-hint-bar { transition: none; } }
body.has-ms-hint .offer-bubble { bottom: 140px; }
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
/* ── Ingredients: small button in the dish details → compact popup ── */
.ingr-btn { display: inline-flex; align-items: center; gap: 8px; margin: 2px 0 12px; padding: 7px 12px 7px 8px; border: 1px solid #d1fae5; border-radius: 999px; background: #f0fdf4; color: #166534; font-family: var(--site-font); font-size: 12.5px; font-weight: 700; cursor: pointer; transition: transform 0.15s ease, background 0.2s; }
.ingr-btn:active { transform: scale(0.96); }
.ingr-btn .ic { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: #16a34a; color: #fff; font-size: 11px; }
.ingr-btn .n { color: #15803d; font-weight: 600; opacity: 0.8; }
.ingr-btn .fa-chevron-right { font-size: 10px; opacity: 0.7; }
.ingr-pop { position: fixed; inset: 0; z-index: 7000; display: flex; align-items: center; justify-content: center; padding: 24px; background: rgba(17,24,39,0.45); opacity: 0; transition: opacity 0.2s ease; }
.ingr-pop.show { opacity: 1; }
.ingr-card { position: relative; width: 100%; max-width: 330px; max-height: 70vh; overflow: auto; padding: 20px 18px 18px; border-radius: 22px; background: #fff; box-shadow: 0 24px 60px rgba(0,0,0,0.25); transform: translateY(16px) scale(0.96); transition: transform 0.3s cubic-bezier(.34,1.56,.64,1); }
.ingr-pop.show .ingr-card { transform: none; }
.ingr-head { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; padding-right: 34px; }
.ingr-head .ic { width: 36px; height: 36px; flex-shrink: 0; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #dcfce7; color: #16a34a; font-size: 15px; }
.ingr-head b { display: block; font-size: 16px; font-weight: 800; color: #111827; }
.ingr-head span { display: block; font-size: 12px; color: #6b7280; }
.ingr-x { position: absolute; top: 14px; right: 14px; width: 32px; height: 32px; border: 0; border-radius: 50%; background: #f3f4f6; color: #374151; font-size: 14px; cursor: pointer; }
.ingr-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
.ingr-chip { padding: 7px 12px; border-radius: 999px; background: #f9fafb; border: 1px solid #eef0f3; font-size: 12.5px; font-weight: 600; color: #374151; animation: ingrIn 0.3s ease both; }
.ingr-note { margin-top: 14px; font-size: 11px; color: #9ca3af; }
@keyframes ingrIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .ingr-card, .ingr-chip { transition: none; animation: none; } }
/* Size chooser (opens from Add to Cart on dishes with sizes) */
.sheet-choose { padding: 18px 20px 8px; }
.sheet-choose-head { display: flex; align-items: center; gap: 12px; padding-right: 44px; }
.sheet-back { width: 34px; height: 34px; flex-shrink: 0; border: 0; border-radius: 50%; background: #f3f4f6; color: #374151; font-size: 15px; cursor: pointer; }
.sheet-choose-name { font-size: 17px; font-weight: 700; color: #1a1b1f; line-height: 1.25; }
.sheet-choose-sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
.sheet-choose-list { margin: 16px 0 6px; border: 1px solid #eee; border-radius: 14px; overflow: hidden; }
.sheet-opt { display: flex; align-items: center; gap: 12px; padding: 14px; border-top: 1px solid #f1f1f1; font-size: 14px; cursor: pointer; }
.sheet-opt:first-child { border-top: 0; }
.sheet-opt input { width: 18px; height: 18px; margin: 0; accent-color: var(--primary-red, #e53935); }
.sheet-opt-name { flex: 1; font-weight: 600; color: #1f2937; }
.sheet-opt-price { font-weight: 700; color: #111827; }
.sheet-opt-price s { margin-right: 6px; color: #9ca3af; font-weight: 500; font-size: 12px; }
.sheet-opt-off { font-size: 12px; color: #9ca3af; }
.sheet-opt.disabled { opacity: 0.5; cursor: not-allowed; }
.sheet-add-btn:disabled { opacity: 0.5; cursor: not-allowed; }
/* Price + ADD on one line in the dish details */
.sheet-price-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 10px 0 6px; }
.sheet-price-row .product-sheet-price { margin: 0; }
.sheet-add-wrap { display: flex; flex-direction: column; align-items: center; gap: 4px; flex-shrink: 0; }
.sheet-add { position: relative; display: inline-flex; align-items: center; justify-content: center; min-width: 112px; height: 42px; padding: 0 22px; border: 0; border-radius: 12px; background: linear-gradient(180deg, #ffe14d 0%, var(--primary-yellow, #FFD100) 100%); color: #1a1b1f; font-family: var(--site-font); font-size: 16px; font-weight: 800; letter-spacing: 0.06em; box-shadow: 0 4px 12px rgba(255, 196, 0, 0.45), inset 0 -2px 0 rgba(0,0,0,0.08); cursor: pointer; transition: transform 0.12s ease, box-shadow 0.12s ease; }
.sheet-add:active { transform: scale(0.96); box-shadow: 0 2px 6px rgba(255, 196, 0, 0.4), inset 0 -1px 0 rgba(0,0,0,0.08); }
.sheet-add .plus { position: absolute; top: 2px; right: 8px; font-size: 14px; font-weight: 800; }
.sheet-add.sold { background: #f3f4f6; color: #9ca3af; font-size: 14px; letter-spacing: 0; box-shadow: none; cursor: not-allowed; }
.sheet-step { justify-content: space-between; padding: 0 4px; }
.sheet-step button { width: 32px; height: 100%; border: 0; background: none; color: inherit; font-size: 20px; font-weight: 800; cursor: pointer; }
.sheet-step span { font-size: 16px; }
.sheet-add-note { font-size: 11px; color: #9ca3af; }
/* Bar that slides up after tapping ADD: [ − qty + ] [ Add item · ₹ ] */
.sheet-add-bar { animation: sheetBarUp 0.25s ease; }
.sheet-add-row { display: flex; align-items: center; gap: 12px; }
/* Optional cooking instruction for this dish (shown to the kitchen, admin and rider) */
.sheet-bestseller { position: relative; overflow: hidden; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 8px; padding: 4px 10px 4px 8px; border-radius: 999px; background: linear-gradient(135deg, #fff7e6, #ffedd5); border: 1px solid #fcd9a8; color: #b45309; font-size: 10.5px; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
.sheet-bestseller i { font-size: 11px; color: #f59e0b; }
.sheet-bestseller::after { content: ""; position: absolute; top: 0; bottom: 0; width: 40%; left: -60%; background: linear-gradient(100deg, transparent, rgba(255,255,255,0.85), transparent); animation: bsShine 3.2s ease-in-out 0.6s infinite; }
@keyframes bsShine { 0% { left: -60%; } 40%, 100% { left: 130%; } }
@media (prefers-reduced-motion: reduce) { .sheet-bestseller::after { animation: none; display: none; } }
.sheet-note { margin-bottom: 10px; }
.sheet-note-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.sheet-cutlery { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 600; color: #374151; cursor: pointer; white-space: nowrap; }
.sheet-cutlery input { width: 15px; height: 15px; margin: 0; accent-color: var(--primary-red, #e53935); }
.sheet-note-toggle { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; padding: 0; border: 0; background: none; color: #374151; font-family: var(--site-font); font-size: 13px; font-weight: 600; cursor: pointer; }
.sheet-note-toggle i { color: var(--primary-red, #e53935); }
.sheet-note-toggle span { color: #9ca3af; font-weight: 500; }
.sheet-note-box { position: relative; margin-top: 8px; }
.sheet-note-box textarea { width: 100%; box-sizing: border-box; min-height: 58px; padding: 10px 12px 18px; border: 1.5px solid #e5e7eb; border-radius: 12px; font-family: var(--site-font); font-size: 13px; resize: none; outline: none; }
.sheet-note-box textarea:focus { border-color: var(--primary-red, #e53935); }
.sheet-note-count { position: absolute; right: 10px; bottom: 6px; font-size: 10.5px; color: #9ca3af; }
.sheet-add-bar .sheet-add-btn { flex: 1; width: auto; }
.sheet-bar-step { display: flex; align-items: center; height: 48px; flex-shrink: 0; border: 1.5px solid #e5e7eb; border-radius: 12px; background: #fff; }
.sheet-bar-step button { width: 40px; height: 100%; border: 0; background: none; color: var(--primary-red, #e53935); font-size: 22px; font-weight: 800; cursor: pointer; }
.sheet-bar-step span { min-width: 22px; text-align: center; font-size: 16px; font-weight: 800; color: #1a1b1f; }
@keyframes sheetBarUp { from { transform: translateY(100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
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

@media (max-width: 425px) {
  .product-sheet { max-height: 92vh; border-radius: 20px 20px 0 0; }
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
  font-family: var(--site-font);
  transition: opacity 0.2s;
}
.closed-overlay-inner button:hover { opacity: 0.9; }
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
  font-family: var(--site-font);
  border: 1.5px solid #ddd;
  background: #fff;
  color: #1a1b1f;
  cursor: pointer;
  transition: all 0.2s;
  white-space: nowrap;
}
.subcategory-tab:hover {
  border-color: #846241;
  color: var(--primary-red, #e17055);
}
.subcategory-tab.active {
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031));
  color: #fff;
  border-color: transparent;
}
/* ── Modern menu card (Website Appearance > Menu Card Design: Modern) ── */
.product-grid .card.mc-card { flex-direction: column; border: 0; border-radius: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
.product-grid .card.mc-card .card-img-wrap { width: 100%; }
.product-grid .card.mc-card .card-img-wrap img { width: 100%; height: auto; min-height: 0; aspect-ratio: 5 / 4; /* a bit shorter than square */ border-radius: 16px; /* rounded on all four corners */ }
.mc-card .veg-dot { top: 8px; left: 8px; width: 22px; height: 22px; border-radius: 5px; }
.mc-card .veg-dot img, .mc-card .veg-dot svg { width: 16px; height: 16px; object-fit: contain; }
.mc-fav { position: absolute; top: 6px; right: 6px; z-index: 2; width: 30px; height: 30px; border-radius: 50%; border: 0; background: rgba(0,0,0,0.35); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 14px; cursor: pointer; }
/* ══ Menu card designs: Minimal / Photo / List / Elegant (Website Appearance) ══ */
.dc-card { position: relative; cursor: pointer; }
.dc-name { font-weight: 700; color: #111827; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.dc-price { display: flex; align-items: baseline; gap: 6px; font-weight: 800; color: #111827; }
.dc-price s { font-size: 11.5px; font-weight: 500; color: #9ca3af; }
.dc-desc { font-size: 12px; color: #6b7280; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.dc-veg { display: inline-flex; width: 14px; height: 14px; border: 1.5px solid #16a34a; border-radius: 3px; align-items: center; justify-content: center; flex-shrink: 0; }
.dc-veg i { width: 6px; height: 6px; border-radius: 50%; background: #16a34a; }
.dc-veg.nonveg { border-color: #dc2626; } .dc-veg.nonveg i { background: #dc2626; }
.dc-veg.egg { border-color: #f59e0b; } .dc-veg.egg i { background: #f59e0b; }
/* shared ADD / stepper */
.dc-add { display: inline-flex; align-items: center; justify-content: center; gap: 4px; height: 34px; min-width: 84px; padding: 0 14px; border-radius: 10px; border: 1px solid #e5e7eb; background: #fff; color: var(--primary-red, #e53935); font-family: var(--site-font); font-size: 13.5px; font-weight: 800; letter-spacing: 0.03em; cursor: pointer; box-shadow: 0 3px 10px rgba(0,0,0,0.10); transition: transform 0.15s ease; }
.dc-add:active { transform: scale(0.94); }
.dc-add small { font-size: 9.5px; font-weight: 600; color: #9ca3af; letter-spacing: 0; }
.dc-step { display: inline-flex; align-items: center; justify-content: space-between; height: 34px; min-width: 84px; border-radius: 10px; background: var(--primary-red, #e53935); color: #fff; box-shadow: 0 3px 10px rgba(0,0,0,0.12); animation: dcIn 0.25s ease; }
.dc-step button { width: 28px; height: 100%; border: 0; background: none; color: #fff; font-size: 15px; font-weight: 800; cursor: pointer; }
.dc-step span { min-width: 16px; text-align: center; font-size: 14px; font-weight: 800; }
@keyframes dcIn { from { transform: scale(0.85); opacity: 0; } to { transform: none; opacity: 1; } }
.dc-card.oos .dc-ctrl { display: none; }
.dc-card .mc-fav { top: 8px; right: 8px; }

/* Minimal */
.product-grid .dc-minimal { background: none; border: 0; box-shadow: none; border-radius: 0; overflow: visible; }
.dc-minimal .dc-img { position: relative; border-radius: 18px; overflow: hidden; aspect-ratio: 1 / 1; background: #f3f4f6; }
.dc-minimal .dc-img img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.4s ease; }
.dc-minimal:hover .dc-img img { transform: scale(1.04); }
.dc-minimal .dc-ctrl { position: absolute; right: 8px; bottom: 8px; z-index: 3; }
.dc-minimal .dc-add { min-width: 0; width: 36px; height: 36px; padding: 0; border: 0; border-radius: 50%; background: #fff; font-size: 18px; }
.dc-minimal .dc-add .lbl { display: none; }
.dc-minimal .dc-add small { display: none; }
.dc-minimal .dc-step { min-width: 90px; border-radius: 999px; }
.dc-minimal .dc-info { padding: 8px 2px 4px; }
.dc-minimal .dc-name { font-size: 13.5px; }
.dc-minimal .dc-price { margin-top: 3px; font-size: 14px; }
.dc-minimal .dc-top { display: flex; align-items: flex-start; gap: 6px; }
.dc-minimal .dc-top .dc-veg { margin-top: 2px; }

/* Photo */
.product-grid .dc-photo { border: 0; border-radius: 20px; overflow: hidden; aspect-ratio: 4 / 5; background: #111; box-shadow: 0 8px 20px rgba(0,0,0,0.12); }
.dc-photo .dc-img, .dc-photo .dc-img img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.dc-photo .dc-img img { transition: transform 0.5s ease; }
.dc-photo:hover .dc-img img { transform: scale(1.05); }
.dc-photo::after { content: ""; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,0) 35%, rgba(0,0,0,0.78) 100%); pointer-events: none; }
.dc-photo .dc-info { position: absolute; left: 0; right: 0; bottom: 0; z-index: 2; padding: 10px 10px 10px 12px; color: #fff; }
.dc-photo .dc-name { color: #fff; font-size: 14px; text-shadow: 0 1px 4px rgba(0,0,0,0.4); }
.dc-photo .dc-bottom { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-top: 6px; }
.dc-photo .dc-price { color: #fff; font-size: 14.5px; }
.dc-photo .dc-price s { color: rgba(255,255,255,0.65); }
.dc-photo .dc-add { min-width: 0; height: 30px; padding: 0 12px; border: 1px solid rgba(255,255,255,0.35); background: rgba(255,255,255,0.2); color: #fff; backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); box-shadow: none; font-size: 12px; }
.dc-photo .dc-add small { display: none; }
.dc-photo .dc-step { min-width: 76px; height: 30px; }
.dc-photo .dc-veg { position: absolute; top: 10px; left: 10px; z-index: 3; background: #fff; }

/* List */
.layout-container.dc-list-mode .product-grid { grid-template-columns: 1fr !important; gap: 0; }
.product-grid .dc-list { display: flex; gap: 14px; padding: 16px 4px 22px; border: 0; border-bottom: 1px dashed #e5e7eb; border-radius: 0; box-shadow: none; background: none; overflow: visible; }
.product-grid .dc-list:hover { transform: none; box-shadow: none; }
.dc-list .dc-info { flex: 1; min-width: 0; }
.dc-list .dc-tags { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
.dc-list .dc-best { font-size: 10px; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #b45309; }
.dc-list .dc-name { font-size: 15.5px; }
.dc-list .dc-price { margin: 3px 0 6px; font-size: 14.5px; }
.dc-list .dc-rate { display: inline-flex; align-items: center; gap: 3px; margin-bottom: 6px; font-size: 11.5px; font-weight: 700; color: #15803d; }
.dc-list .dc-side { position: relative; width: 124px; flex-shrink: 0; }
.dc-list .dc-img { width: 124px; height: 120px; border-radius: 16px; overflow: hidden; background: #f3f4f6; }
.dc-list .dc-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
.dc-list .dc-ctrl { position: absolute; left: 50%; bottom: -14px; transform: translateX(-50%); z-index: 3; }
.product-grid .card.dc-list { flex-direction: row; align-items: flex-start; }
.dc-list .dc-add { position: relative; min-width: 98px; }
.dc-list .dc-add small { position: absolute; top: 100%; left: 0; right: 0; margin-top: 4px; text-align: center; }
.dc-list .dc-step { min-width: 98px; }
.dc-list .mc-fav { top: 6px; right: 6px; width: 28px; height: 28px; font-size: 13px; }
.dc-list .card-img-wrap-chips { display: none; }

/* Compact: 3 per row, small square tiles */
.layout-container.dc-mode-compact .product-grid { grid-template-columns: repeat(3, 1fr) !important; gap: 10px 8px; }
.product-grid .dc-compact { border: 0; border-radius: 14px; background: #fff; box-shadow: 0 1px 6px rgba(0,0,0,0.06); overflow: hidden; }
.dc-compact .dc-img { position: relative; aspect-ratio: 1 / 1; background: #f3f4f6; }
.dc-compact .dc-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
.dc-compact .dc-info { padding: 6px 7px 8px; }
.dc-compact .dc-name { font-size: 11.5px; line-height: 1.25; min-height: 2.5em; }
.dc-compact .dc-bottom { display: flex; align-items: center; justify-content: space-between; margin-top: 4px; }
.dc-compact .dc-price { font-size: 12px; gap: 3px; }
.dc-compact .dc-price s { display: none; }
.dc-compact .dc-add { min-width: 0; width: 26px; height: 26px; padding: 0; border: 0; border-radius: 8px; background: var(--primary-red, #e53935); color: #fff; box-shadow: none; }
.dc-compact .dc-add .lbl, .dc-compact .dc-add small { display: none; }
.dc-compact .dc-step { min-width: 0; height: 26px; border-radius: 8px; }
.dc-compact .dc-step button { width: 20px; font-size: 13px; }
.dc-compact .dc-step span { min-width: 12px; font-size: 12px; }
.dc-compact .dc-veg { position: absolute; top: 5px; left: 5px; width: 12px; height: 12px; background: #fff; }
.dc-compact .mc-fav { top: 4px; right: 4px; width: 24px; height: 24px; font-size: 11px; }
.dc-compact .img-oos { font-size: 9px; padding: 2px 6px; }

/* Midnight: dark card, glowing price */
.product-grid .dc-midnight { border: 1px solid #262b36; border-radius: 18px; background: linear-gradient(180deg, #171a21, #0f1115); box-shadow: 0 10px 24px rgba(0,0,0,0.25); overflow: hidden; }
.dc-midnight .dc-img { position: relative; aspect-ratio: 5 / 4; }
.dc-midnight .dc-img img { width: 100%; height: 100%; object-fit: cover; display: block; filter: saturate(1.1); }
.dc-midnight .dc-img::after { content: ""; position: absolute; inset: auto 0 0 0; height: 40%; background: linear-gradient(180deg, transparent, #13161c); }
.dc-midnight .dc-info { padding: 4px 12px 12px; }
.dc-midnight .dc-name { color: #f3f4f6; font-size: 14px; }
.dc-midnight .dc-bottom { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-top: 8px; }
.dc-midnight .dc-price { color: var(--primary-red, #e53935); font-size: 15px; text-shadow: 0 0 14px color-mix(in srgb, var(--primary-red, #e53935) 60%, transparent); }
.dc-midnight .dc-price s { color: #6b7280; text-shadow: none; }
.dc-midnight .dc-add { min-width: 0; height: 30px; padding: 0 12px; border: 1px solid color-mix(in srgb, var(--primary-red, #e53935) 60%, transparent); background: color-mix(in srgb, var(--primary-red, #e53935) 15%, #111); color: #fff; box-shadow: 0 0 16px color-mix(in srgb, var(--primary-red, #e53935) 35%, transparent); font-size: 12px; }
.dc-midnight .dc-add small { display: none; }
.dc-midnight .dc-step { min-width: 78px; height: 30px; box-shadow: 0 0 16px color-mix(in srgb, var(--primary-red, #e53935) 45%, transparent); }
.dc-midnight .dc-veg { position: absolute; top: 9px; left: 9px; z-index: 2; background: #fff; }
.dc-midnight .mc-fav { background: rgba(255,255,255,0.12); }
.dc-midnight .mc-fav.on { background: #fff; }

/* Showcase: one big dish per row */
.layout-container.dc-mode-showcase .product-grid { grid-template-columns: 1fr !important; gap: 16px; }
.product-grid .dc-showcase { border: 0; border-radius: 22px; background: #fff; box-shadow: 0 8px 24px rgba(17,24,39,0.08); overflow: hidden; }
.dc-showcase .dc-img { position: relative; aspect-ratio: 16 / 9; overflow: hidden; }
.dc-showcase .dc-img img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.6s ease; }
.dc-showcase:hover .dc-img img { transform: scale(1.04); }
.dc-showcase .dc-chips { position: absolute; left: 12px; bottom: 12px; display: flex; gap: 6px; }
.dc-showcase .dc-chip { padding: 4px 9px; border-radius: 999px; background: rgba(255,255,255,0.92); font-size: 11px; font-weight: 800; color: #111827; }
.dc-showcase .dc-chip.best { color: #b45309; }
.dc-showcase .dc-chip.rate { color: #15803d; }
.dc-showcase .dc-info { padding: 12px 14px 14px; }
.dc-showcase .dc-top { display: flex; align-items: center; gap: 8px; }
.dc-showcase .dc-name { font-size: 16.5px; -webkit-line-clamp: 1; }
.dc-showcase .dc-desc { margin-top: 4px; }
.dc-showcase .dc-bottom { display: flex; align-items: center; justify-content: space-between; margin-top: 10px; }
.dc-showcase .dc-price { font-size: 17px; }
.dc-showcase .dc-add { min-width: 104px; height: 38px; }
.dc-showcase .dc-step { min-width: 104px; height: 38px; }
@media (prefers-reduced-motion: reduce) { .dc-showcase .dc-img img { transition: none; } }
/* Elegant */
.product-grid .dc-elegant { padding: 16px 12px 14px; border: 1px solid #f1e7d6; border-radius: 22px; background: linear-gradient(180deg, #fffaf2, #fff); box-shadow: 0 6px 18px rgba(120, 80, 20, 0.07); text-align: center; overflow: visible; }
.dc-elegant .dc-img { position: relative; width: 112px; height: 112px; margin: 0 auto 10px; border-radius: 50%; overflow: hidden; box-shadow: 0 10px 22px rgba(0,0,0,0.16), 0 0 0 5px #fff; transition: transform 0.5s ease; }
.dc-elegant:hover .dc-img { transform: rotate(8deg); }
.dc-elegant .dc-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
.dc-elegant .dc-name { font-family: Georgia, 'Times New Roman', serif; font-size: 15px; font-weight: 700; color: #3f2d1d; }
.dc-elegant .dc-rule { width: 46px; margin: 8px auto; border-top: 2px dotted #e2c79c; }
.dc-elegant .dc-price { justify-content: center; font-size: 15px; color: #7c2d12; }
.dc-elegant .dc-ctrl { margin-top: 10px; }
.dc-elegant .dc-add { height: 32px; min-width: 96px; border: 1.5px solid #e2c79c; border-radius: 999px; background: #fff; color: #7c2d12; box-shadow: none; }
.dc-elegant .dc-add small { display: none; }
.dc-elegant .dc-step { min-width: 96px; height: 32px; border-radius: 999px; background: #7c2d12; }
.dc-elegant .dc-veg { position: absolute; top: 12px; left: 12px; background: #fff; }
.dc-elegant .mc-fav { top: 10px; right: 10px; background: #fff; color: #b45309; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
.dc-elegant .mc-fav.on { color: #ef4444; }
@media (prefers-reduced-motion: reduce) { .dc-card img, .dc-elegant .dc-img, .dc-step { transition: none; animation: none; } }
.mc-fav.on { background: #fff; color: #ef4444; }
.mc-fav.beat i { animation: favBeat 0.45s cubic-bezier(.34,1.56,.64,1); }
@keyframes favBeat { 0% { transform: scale(0.6); } 60% { transform: scale(1.3); } 100% { transform: scale(1); } }
.fav-nav-wrap { position: relative; display: inline-block; line-height: 0; }
.layout-container.nav-top .side-nav .side-nav-btn.fav-nav-btn { width: calc(var(--cat-size) + 34px); }
.fav-nav-count { position: absolute; top: -4px; right: -6px; min-width: 16px; height: 16px; padding: 0 4px; border-radius: 999px; background: #ef4444; color: #fff; font-size: 9.5px; font-weight: 800; line-height: 16px; text-align: center; box-shadow: 0 0 0 2px #fff; }
.fav-empty { grid-column: 1 / -1; padding: 40px 20px; text-align: center; color: #6b7280; }
.fav-empty i { display: block; margin: 0 auto 12px; width: 56px; height: 56px; line-height: 56px; border-radius: 50%; background: #fff1f2; color: #ef4444; font-size: 24px; }
.fav-empty b { display: block; margin-bottom: 4px; font-size: 15px; color: #111827; }
@media (prefers-reduced-motion: reduce) { .mc-fav.beat i { animation: none; } }
.mc-card .img-chip-save { background: var(--primary-red, #e53935); color: #fff; }
.mc-card .card-body { padding: 8px 10px 10px; gap: 3px; }
.mc-name { font-size: 14px; font-weight: 700; color: #111827; line-height: 1.25; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; line-clamp: 2; overflow: hidden; }
.mc-price { margin-top: auto; padding-top: 2px; display: flex; align-items: baseline; gap: 6px; }
.mc-now { font-size: 15px; font-weight: 800; color: var(--primary-red, #e53935); }
.mc-was { font-size: 12px; font-weight: 600; color: #9ca3af; }
.mc-row { display: flex; align-items: center; gap: 6px; margin-top: 5px; }
.mc-step { display: flex; align-items: center; justify-content: space-between; height: 30px; min-width: 66px; padding: 0 2px; border: 1.5px solid #e5e7eb; border-radius: 10px; background: #fff; }
.mc-step button { width: 22px; height: 30px; border: 0; background: none; color: #6b7280; font-size: 12px; cursor: pointer; }
.mc-step button:last-child { color: var(--primary-red, #e53935); }
.mc-step span { min-width: 14px; text-align: center; font-size: 14px; font-weight: 700; }
.mc-add { flex: 1; height: 30px; border: 0; border-radius: 9px; background: var(--primary-red, #e53935); color: #fff; font-family: var(--site-font); font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 6px; white-space: nowrap; cursor: pointer; }
.mc-add-full { width: 100%; margin-top: 5px; }
.mc-added { background: color-mix(in srgb, var(--primary-red, #e53935) 12%, #fff); color: var(--primary-red, #e53935); cursor: default; }
/* Narrow cards (categories on the left): picker above a full-width Add */
@container (max-width: 140px) {
  .mc-row { flex-direction: column; align-items: stretch; gap: 6px; }
  .mc-step { width: 100%; }
  .mc-add { flex: none; width: 100%; }
}
/* ── Category row along the top (Zomato-style) ──────────────────────────────
   Perfect circles of one size, names centred underneath, one horizontally
   scrolling row (swipe / drag / mouse wheel). The selected category gets a
   coloured, underlined name instead of a filled tile. Round pictures fill the
   circle (cover); Cut-out pictures (Website Appearance > Category Pictures)
   show whole on a transparent background (contain). Sizes (compact, Zomato scale): 42px phones,
   66px tablets, 72px desktops. */
.layout-container.nav-top { --cat-size: 36px; }
@media (min-width: 600px) { .layout-container.nav-top { --cat-size: 58px; } }
@media (min-width: 1024px) { .layout-container.nav-top { --cat-size: 64px; } }
.layout-container.nav-top .side-nav { gap: 10px; padding: 5px 12px 3px; background: #fff; scrollbar-width: none; overscroll-behavior-x: contain; touch-action: pan-x pan-y; }
.layout-container.nav-top .side-nav::-webkit-scrollbar { display: none; }
.layout-container.nav-top .side-nav .sub-views { flex: 0 0 auto; }
.layout-container.nav-top .side-nav .side-nav-btn {
  width: calc(var(--cat-size) + 16px); min-width: 0; padding: 0 0 5px; gap: 4px;
  background: transparent; color: #374151; box-shadow: none; border-radius: 0;
  font-size: 11px; font-weight: 500; line-height: 1.2; white-space: normal; animation: none;
}
.layout-container.nav-top .side-nav .side-nav-btn br { display: none; }
.layout-container.nav-top .side-nav .side-nav-btn img {
  width: var(--cat-size); height: var(--cat-size); border-radius: 50%;
  object-fit: cover; object-position: center; background: #f3f4f6;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08); transform: none; filter: none;
}
/* name: one line, neat ellipsis */
.layout-container.nav-top .side-nav .side-nav-btn .cat-name { display: block; width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.layout-container.nav-top .side-nav .side-nav-btn:active img { transform: scale(0.95); }
.layout-container.nav-top .side-nav .side-nav-btn.active {
  background: transparent; color: var(--primary-red, #e53935); font-weight: 700;
  box-shadow: inset 0 -2px 0 var(--primary-red, #e53935);
}
.layout-container.nav-top .side-nav .side-nav-btn.active img { transform: scale(1.04); box-shadow: 0 0 0 1.5px var(--primary-red, #e53935); }
/* Cut-out pictures: whole dish on a transparent background, soft shadow */
.layout-container.nav-top.cat-cutout .side-nav .side-nav-btn img { object-fit: contain; background: transparent; box-shadow: none; filter: drop-shadow(0 4px 5px rgba(0,0,0,0.16)); }
.layout-container.nav-top.cat-cutout .side-nav .side-nav-btn.active img { box-shadow: none; }
@media (prefers-reduced-motion: reduce) { .layout-container.nav-top .side-nav .side-nav-btn img { transition: none; } }
/* Modern design: rounded-square category pictures and a bigger heading */
.mc-mode .side-nav-btn img { border-radius: 12px; }
.mc-mode .category-overview h2 { font-size: 18px; }
</style>
<script>
window.websiteRestaurantId = <?php echo json_encode($restaurant_id ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
// Website Appearance > Menu Card Design: 'classic' (original cards) or 'modern'
window.menuCardStyle = <?php echo json_encode($menu_card_style ?? 'classic'); ?>;
window.__previewTheme = <?php echo json_encode($preview_theme ?? null); ?>;
// Custom picture for the "All" category button (Website Appearance), or null
window.allCategoryIcon = <?php echo json_encode($all_category_icon ?? null); ?>;
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
          <span style="font-size:12px;font-weight:600;color:#16a34a"><?php echo ($menu_card_style ?? 'classic') === 'modern' ? 'Veg Only' : 'Veg'; ?></span>
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
        <i class="bi bi-search search-lead" aria-hidden="true"></i>
        <div class="search-field">
          <input type="search" class="search-input" aria-label="Search dishes" autocomplete="off" enterkeyhint="search">
          <div class="search-hint" aria-hidden="true">Search for <span class="search-hint-word" id="searchHintWord">your favourite dish</span></div>
        </div>
        <button type="button" class="search-clear" aria-label="Clear search"><i class="fa fa-times"></i></button>
      </div>
    </div>

    <div class="layout-container<?php echo ($menu_nav_position ?? 'left') === 'top' ? ' nav-top' : ''; ?><?php echo ($menu_card_style ?? 'classic') === 'modern' ? ' mc-mode' : ''; ?><?php echo ($menu_card_style ?? '') === 'list' ? ' dc-list-mode' : ''; ?><?php echo in_array($menu_card_style ?? '', ['compact', 'showcase'], true) ? ' dc-mode-' . $menu_card_style : ''; ?><?php echo ($category_pic_style ?? 'round') === 'cutout' ? ' cat-cutout' : ''; ?>">
      <div class="side-nav" id="sideNav">
        <div class="sub-views"><button class="side-nav-btn" disabled><div class="skel-circle skeleton"></div><div class="skel-nav-label skeleton"></div></button></div>
        <div class="sub-views"><button class="side-nav-btn" disabled><div class="skel-circle skeleton"></div><div class="skel-nav-label skeleton"></div></button></div>
        <div class="sub-views"><button class="side-nav-btn" disabled><div class="skel-circle skeleton"></div><div class="skel-nav-label skeleton"></div></button></div>
        <div class="sub-views"><button class="side-nav-btn" disabled><div class="skel-circle skeleton"></div><div class="skel-nav-label skeleton"></div></button></div>
        <div class="sub-views"><button class="side-nav-btn" disabled><div class="skel-circle skeleton"></div><div class="skel-nav-label skeleton"></div></button></div>
      </div>
      <div class="main-content">
        <!-- Banners: Menu page — above the dishes -->
        <div class="rg-banners" data-placement="menu_top" style="padding:10px 4px 12px"></div>
        <div class="category-overview">
          <h2 id="categoryTitle"><span class="skel-title-bar skeleton"></span></h2>
          <div class="subcategory-tabs" id="subcategoryTabs" style="display:none"></div>
        </div>
        <div class="product-grid cols-2 layout-<?php echo htmlspecialchars($layout_style, ENT_QUOTES, 'UTF-8'); ?>" id="productGrid" data-price-style="<?php echo htmlspecialchars($bestseller_style['price_style'], ENT_QUOTES, 'UTF-8'); ?>">
          <div class="skel-grid">
            <div class="skel-card"><div class="skel-card-img skeleton"></div><div class="skel-card-body"><div class="skel-card-title skeleton"></div><div class="skel-card-price skeleton"></div></div></div>
            <div class="skel-card"><div class="skel-card-img skeleton"></div><div class="skel-card-body"><div class="skel-card-title skeleton"></div><div class="skel-card-price skeleton"></div></div></div>
            <div class="skel-card"><div class="skel-card-img skeleton"></div><div class="skel-card-body"><div class="skel-card-title skeleton"></div><div class="skel-card-price skeleton"></div></div></div>
            <div class="skel-card"><div class="skel-card-img skeleton"></div><div class="skel-card-body"><div class="skel-card-title skeleton"></div><div class="skel-card-price skeleton"></div></div></div>
          </div>
        </div>
      </div>
    </div>

<button type="button" class="offer-bubble" id="offerBubble" onclick="openOfferSheet()" aria-label="Offers"><i class="fa-solid fa-percent"></i><span class="offer-bubble-count" id="offerBubbleCount"></span></button>
<div class="product-sheet-overlay" id="offerSheetOverlay" onclick="closeOfferSheet(event)">
  <div class="product-sheet" onclick="event.stopPropagation()">
    <button class="product-sheet-close" onclick="closeOfferSheet()" aria-label="Close"><i class="fa fa-times"></i></button>
    <div class="offer-sheet">
      <h3>Offers for you</h3>
      <p>Pick one to see the matching dishes.</p>
      <div class="offer-tiles" id="offerTiles"></div>
    </div>
  </div>
</div>
  <div class="checkout-bar" id="checkoutBar">
    <div class="ms-hint" id="msHint"></div>
    <span class="total-label" id="checkoutLabel"><?php echo htmlspecialchars($currency_symbol ?? '₹'); ?>0.00</span>
    <button class="checkout-btn" id="checkoutBtn" onclick="goToCheckout()">View Cart <i class="fa fa-arrow-right"></i></button>
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
      updateAddonsNavButton();
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
  if (img.indexOf('http://') === 0 || img.indexOf('https://') === 0 || img.indexOf('data:') === 0) return img;
  // No per-load cache-buster: every upload gets a new id (db:<uniqid>), so a
  // changed photo already has a new URL. Busting on each load made every refresh
  // re-download all dish photos (they popped in one by one).
  if (img.indexOf('db:') === 0) return 'image.php?id=' + encodeURIComponent(img.substring(3));
  return 'image.php?id=' + encodeURIComponent(img);
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

// Price pill (see .mrp-price): current price, plus the struck
// regular price when the dish has an offer. "₹224", not "₹224.00".
function mrpPriceHtml(now, was) {
  var f = function(n) { return formatPrice(n).replace(/\.00$/, ''); };
  return '<span class="mrp-price' + (was ? ' has-mrp' : '') + '"><span class="mrp-now">' + f(now) + '</span>' +
    (was ? '<span class="mrp-was"><s>' + f(was) + '</s></span>' : '') + '</span>';
}
function cardPriceHtml(item) {
  var hasVar = item.has_variations && item.variations && item.variations.length > 0;
  return mrpPriceHtml(itemNowPrice(item), itemWasPrice(item));
}
// Chips on the photo: "₹X OFF" bottom-left, genuine rating bottom-right (same rules as the home page)
function cardBadgesHtml(item) {
  var hasVar = item.has_variations && item.variations && item.variations.length > 0;
  var was = itemWasPrice(item);
  var save = was ? Math.round(was - itemNowPrice(item)) : 0;
  var rating = item.rating ? parseFloat(item.rating) : 0;
  return (save > 0 ? '<span class="img-chip img-chip-save">' + formatPrice(save).replace(/\.00$/, '') + ' OFF</span>' : '') +
    (rating > 0 ? '<span class="img-chip img-chip-rate bsl-rating" title="Rated ' + rating.toFixed(1) + ' out of 5 from ' + (item.rating_count || 0) + ' rated orders">' +
      rating.toFixed(1) + ' <i class="fa fa-star" aria-hidden="true"></i>' +
      (item.rating_count ? '<span class="bsl-rating-count">(' + parseInt(item.rating_count, 10) + ')</span>' : '') + '</span>' : '');
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

// Card price for a dish: its price, or for dishes with sizes the cheapest
// size. itemWasPrice() is the struck regular price when that price is an
// offer (Offers > Bestsellers, per size for dishes with sizes), else 0.
function itemNowPrice(item) {
  var hasVar = item.has_variations && item.variations && item.variations.length > 0;
  return hasVar ? getMinVariantPrice(item) : parseFloat(item.base_price || item.price || 0);
}
function itemWasPrice(item) {
  var hasVar = item.has_variations && item.variations && item.variations.length > 0;
  if (!hasVar) return item.original_price ? parseFloat(item.original_price) : 0;
  var now = getMinVariantPrice(item);
  for (var v = 0; v < item.variations.length; v++) {
    var vr = item.variations[v];
    if (vr.is_available == 0 || !vr.original_price) continue;
    if (parseFloat(vr.variation_price || vr.price || 0) === now) return parseFloat(vr.original_price);
  }
  return 0;
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

// ── Modern menu card (Website Appearance > Menu Card Design: Modern) ──
// Wide photo, veg mark + favourite heart, OFF/rating chips, name (description
// only in the dish sheet), price + struck price, and a "− 1 +" picker with Add.
var modernPick = {}; // quantity chosen on a card before it's added to the cart
function modernCardHtml(item) {
  var oos = item.is_available == 0;
  var name = escapeHtml(item.item_name_translated || item.item_name_en || item.name || '');
  var hasVar = item.has_variations && item.variations && item.variations.length > 0;
  var now = itemNowPrice(item);
  var was = itemWasPrice(item);
  var f = function(n) { return formatPrice(n).replace(/\.00$/, ''); };
  var fav = isFavItem(item.id);
  return '<div class="card mc-card' + (oos ? ' oos' : '') + '" data-itemId="' + item.id + '" onclick="if(!' + oos + ')showItemDetail(' + item.id + ')">' +
    '<div class="card-img-wrap">' +
      '<img src="' + getImageUrl(item.item_image || item.image) + '" alt="' + name + '" loading="lazy">' +
      getTypeIcon(item.item_type) +
      '<button type="button" class="mc-fav' + (fav ? ' on' : '') + '" aria-label="Favourite" aria-pressed="' + fav + '" onclick="event.stopPropagation();toggleFavItem(' + item.id + ', this)"><i class="fa-' + (fav ? 'solid' : 'regular') + ' fa-heart"></i></button>' +
      cardBadgesHtml(item) +
      (oos ? '<span class="img-oos">Out of Stock</span>' : '') +
    '</div>' +
    '<div class="card-body">' +
      '<div class="mc-name">' + name + '</div>' +
      '<div class="mc-price"><span class="mc-now">' + f(now) + '</span>' + (was ? '<s class="mc-was">' + f(was) + '</s>' : '') + '</div>' +
      (!oos ? '<span id="btn-' + item.id + '">' + renderCartBtn(item.id, item) + '</span>' : '') +
    '</div>' +
  '</div>';
}
function renderModernCartRow(itemId, item) {
  var hasVar = item && item.has_variations && item.variations && item.variations.length > 0;
  // Dishes with sizes: Add opens the dish sheet to pick a size
  if (hasVar) return '<button type="button" class="mc-add mc-add-full" onclick="event.stopPropagation();showItemDetail(' + itemId + ')"><i class="fa-solid fa-cart-shopping"></i> Add</button>';
  var inCart = getItemTotalQty(itemId);
  var q = inCart || modernPick[itemId] || 1;
  return '<div class="mc-row">' +
    '<div class="mc-step">' +
      '<button type="button" aria-label="Decrease" onclick="event.stopPropagation();modernStep(' + itemId + ', -1)"><i class="fa fa-minus"></i></button>' +
      '<span>' + q + '</span>' +
      '<button type="button" aria-label="Increase" onclick="event.stopPropagation();modernStep(' + itemId + ', 1)"><i class="fa fa-plus"></i></button>' +
    '</div>' +
    (inCart
      ? '<button type="button" class="mc-add mc-added" onclick="event.stopPropagation()"><i class="fa fa-check"></i> Added</button>'
      : '<button type="button" class="mc-add" onclick="event.stopPropagation();modernAdd(' + itemId + ')"><i class="fa-solid fa-cart-shopping"></i> Add</button>') +
  '</div>';
}
// Before adding: − / + just choose the quantity. Once in the cart they change the cart.
function modernStep(itemId, delta) {
  if (getItemTotalQty(itemId) > 0) {
    if (delta > 0) addToCart(itemId); else removeFromCart(itemId);
    return;
  }
  modernPick[itemId] = Math.min(20, Math.max(1, (modernPick[itemId] || 1) + delta));
  var el = document.getElementById('btn-' + itemId);
  if (el) el.innerHTML = renderCartBtn(itemId, getItemModelById(itemId));
}
function modernAdd(itemId) {
  var n = modernPick[itemId] || 1;
  delete modernPick[itemId];
  for (var i = 0; i < n; i++) addToCart(itemId);
}
// Favourite hearts: remembered on this device, per restaurant
function favKey() { return 'rgFavs_' + (window.websiteRestaurantId || ''); }
function getFavItems() { try { return JSON.parse(localStorage.getItem(favKey()) || '[]'); } catch (e) { return []; } }
function isFavItem(itemId) { return getFavItems().indexOf(itemId) !== -1; }
function toggleFavItem(itemId, btn) {
  var favs = getFavItems(), i = favs.indexOf(itemId);
  if (i === -1) favs.push(itemId); else favs.splice(i, 1);
  try { localStorage.setItem(favKey(), JSON.stringify(favs)); } catch (e) {}
  var on = i === -1;
  btn.classList.toggle('on', on);
  btn.setAttribute('aria-pressed', on);
  btn.innerHTML = '<i class="fa-' + (on ? 'solid' : 'regular') + ' fa-heart"></i>';
  btn.classList.remove('beat'); void btn.offsetWidth; if (on) btn.classList.add('beat');
  refreshFavNav();
  // Viewing Favourites: an un-hearted dish leaves the list
  if (!on && currentMenuIdx === FAV_CATEGORY && !activeOfferFilter) setTimeout(function() { renderProducts(FAV_CATEGORY); }, 250);
}
function refreshFavNav() {
  var el = document.getElementById('favNavCount');
  if (!el) return;
  var n = getFavItems().length;
  el.textContent = n > 99 ? '99+' : n;
  el.style.display = n ? '' : 'none';
}
function favHeartHtml(item) {
  var fav = isFavItem(item.id);
  return '<button type="button" class="mc-fav' + (fav ? ' on' : '') + '" aria-label="Favourite" aria-pressed="' + fav + '" onclick="event.stopPropagation();toggleFavItem(' + item.id + ', this)"><i class="fa-' + (fav ? 'solid' : 'regular') + ' fa-heart"></i></button>';
}

// ── Menu card designs beyond Classic / Modern (Website Appearance > Menu Card Design)
var DESIGN_CARD_STYLES = { minimal: 1, photo: 1, list: 1, elegant: 1, compact: 1, midnight: 1, showcase: 1 };
function isDesignCardStyle() { return !!DESIGN_CARD_STYLES[window.menuCardStyle]; }
function dcVegHtml(item) {
  var t = String(item.item_type || '').toLowerCase();
  if (!t || t === 'drink' || t === 'beverage') return '';
  var cls = t.indexOf('non') !== -1 ? ' nonveg' : (t.indexOf('egg') !== -1 ? ' egg' : '');
  return '<span class="dc-veg' + cls + '"><i></i></span>';
}
// ADD (or "− n +" once in the cart); dishes with sizes open the dish sheet
function designAddCtrl(itemId, item) {
  if (item && item.is_available == 0) return '';
  var hasVar = item && item.has_variations && item.variations && item.variations.length > 0;
  var qty = getItemTotalQty(itemId);
  if (hasVar) {
    return '<button type="button" class="dc-add" onclick="event.stopPropagation();showItemDetail(' + itemId + ')"><span class="lbl">ADD</span><i class="fa fa-plus" style="font-size:11px"></i>' +
      (window.menuCardStyle === 'list' ? '<small>customisable</small>' : '') + '</button>';
  }
  if (qty > 0) {
    return '<div class="dc-step" onclick="event.stopPropagation()">' +
      '<button type="button" aria-label="One less" onclick="event.stopPropagation();removeFromCart(' + itemId + ')">−</button>' +
      '<span>' + qty + '</span>' +
      '<button type="button" aria-label="One more" onclick="event.stopPropagation();addToCart(' + itemId + ')">+</button></div>';
  }
  return '<button type="button" class="dc-add" onclick="event.stopPropagation();addToCart(' + itemId + ')"><span class="lbl">ADD</span><i class="fa fa-plus" style="font-size:11px"></i></button>';
}
function designCardHtml(item) {
  var style = window.menuCardStyle;
  var oos = item.is_available == 0;
  var name = escapeHtml(item.item_name_translated || item.item_name_en || item.name || '');
  var f = function(n) { return formatPrice(n).replace(/\.00$/, ''); };
  var now = itemNowPrice(item), was = itemWasPrice(item);
  var price = '<div class="dc-price">' + f(now) + (was ? '<s>' + f(was) + '</s>' : '') + '</div>';
  var img = '<img src="' + getImageUrl(item.item_image || item.image) + '" alt="' + name + '" loading="lazy">';
  var ctrl = '<span class="dc-ctrl" id="btn-' + item.id + '">' + (oos ? '' : designAddCtrl(item.id, item)) + '</span>';
  var oosTag = oos ? '<span class="img-oos">Out of Stock</span>' : '';
  var open = ' data-itemId="' + item.id + '" onclick="if(!' + oos + ')showItemDetail(' + item.id + ')"';
  var cls = 'card dc-card dc-' + style + (oos ? ' oos' : '');
  var desc = escapeHtml(item.item_description_translated || item.item_description_en || item.desc || '');
  if (style === 'minimal') {
    return '<div class="' + cls + '"' + open + '>' +
      '<div class="dc-img">' + img + favHeartHtml(item) + oosTag + ctrl + '</div>' +
      '<div class="dc-info"><div class="dc-top">' + dcVegHtml(item) + '<div class="dc-name">' + name + '</div></div>' + price + '</div></div>';
  }
  if (style === 'photo') {
    return '<div class="' + cls + '"' + open + '>' +
      '<div class="dc-img">' + img + '</div>' + dcVegHtml(item) + favHeartHtml(item) + oosTag +
      '<div class="dc-info"><div class="dc-name">' + name + '</div><div class="dc-bottom">' + price + ctrl + '</div></div></div>';
  }
  if (style === 'list') {
    var rating = item.rating ? '<div class="dc-rate"><i class="fa-solid fa-star"></i>' + parseFloat(item.rating).toFixed(1) + (item.rating_count ? ' (' + item.rating_count + ')' : '') + '</div>' : '';
    return '<div class="' + cls + '"' + open + '>' +
      '<div class="dc-info"><div class="dc-tags">' + dcVegHtml(item) + (item.is_bestseller ? '<span class="dc-best">Bestseller</span>' : '') + '</div>' +
        '<div class="dc-name">' + name + '</div>' + price + rating + (desc ? '<div class="dc-desc">' + desc + '</div>' : '') + '</div>' +
      '<div class="dc-side"><div class="dc-img">' + img + '</div>' + favHeartHtml(item) + oosTag + ctrl + '</div></div>';
  }
  if (style === 'compact') {
    return '<div class="' + cls + '"' + open + '>' +
      '<div class="dc-img">' + img + dcVegHtml(item) + favHeartHtml(item) + oosTag + '</div>' +
      '<div class="dc-info"><div class="dc-name">' + name + '</div><div class="dc-bottom">' + price + ctrl + '</div></div></div>';
  }
  if (style === 'midnight') {
    return '<div class="' + cls + '"' + open + '>' +
      '<div class="dc-img">' + img + dcVegHtml(item) + favHeartHtml(item) + oosTag + '</div>' +
      '<div class="dc-info"><div class="dc-name">' + name + '</div><div class="dc-bottom">' + price + ctrl + '</div></div></div>';
  }
  if (style === 'showcase') {
    var chips = (item.is_bestseller ? '<span class="dc-chip best"><i class="fa-solid fa-award"></i> Bestseller</span>' : '') +
      (item.rating ? '<span class="dc-chip rate"><i class="fa-solid fa-star"></i> ' + parseFloat(item.rating).toFixed(1) + '</span>' : '');
    return '<div class="' + cls + '"' + open + '>' +
      '<div class="dc-img">' + img + favHeartHtml(item) + oosTag + (chips ? '<div class="dc-chips">' + chips + '</div>' : '') + '</div>' +
      '<div class="dc-info"><div class="dc-top">' + dcVegHtml(item) + '<div class="dc-name">' + name + '</div></div>' +
        (desc ? '<div class="dc-desc">' + desc + '</div>' : '') +
        '<div class="dc-bottom">' + price + ctrl + '</div></div></div>';
  }
  // elegant
  return '<div class="' + cls + '"' + open + '>' + dcVegHtml(item) + favHeartHtml(item) +
    '<div class="dc-img">' + img + '</div>' + oosTag +
    '<div class="dc-name">' + name + '</div><div class="dc-rule"></div>' + price + ctrl + '</div>';
}

function renderCartBtn(itemId, item) {
  if (item && item.is_available == 0) return '';
  if (isDesignCardStyle()) return designAddCtrl(itemId, item);
  if (window.menuCardStyle === 'modern') return renderModernCartRow(itemId, item);
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
    label.innerHTML = totalQty + (totalQty === 1 ? ' item' : ' items') + '<span class="sep">|</span>' + (window.globalCurrencySymbol || '₹') + totalPrice.toFixed(2);
    btn.disabled = false;
  } else {
    bar.classList.remove('show');
    if (content) content.classList.remove('has-cart');
  }
  lastCartTotal = totalPrice;
  updateMilestoneHint(totalPrice, totalQty > 0);
}

// ── Milestone offers hint (coupons marked "Milestone offer" in the dashboard)
var milestoneOffers = [];
function loadMilestoneOffers() {
  fetch('../api/get_coupons.php?restaurant_id=' + encodeURIComponent(window.websiteRestaurantId || ''))
    .then(function(r) { return r.json(); })
    .then(function(d) {
      milestoneOffers = ((d && d.coupons) || []).filter(function(c) { return c.is_milestone == 1 && parseFloat(c.minimum_order_amount) > 0; })
        .map(function(c) { return { code: c.coupon_code, type: c.discount_type, value: parseFloat(c.discount_value), min: parseFloat(c.minimum_order_amount) }; })
        .sort(function(a, b) { return a.min - b.min; });
      updateCheckoutBar();
    })
    .catch(function() {});
}
document.addEventListener('DOMContentLoaded', loadMilestoneOffers);
function updateMilestoneHint(total, hasItems) {
  var el = document.getElementById('msHint');
  if (!el) return;
  var cur = window.globalCurrencySymbol || '₹';
  var label = function(c) { return c.type === 'percent' ? c.value + '% OFF' : cur + c.value + ' OFF'; };
  var next = null, reached = null;
  milestoneOffers.forEach(function(c) { if (total >= c.min) reached = c; else if (!next) next = c; });
  var show = hasItems && milestoneOffers.length > 0;
  el.classList.toggle('show', show);
  document.body.classList.toggle('has-ms-hint', show);
  if (!show) return;
  el.classList.toggle('done', !next);
  // Progress toward the next offer (from the last one reached)
  var from = reached ? reached.min : 0;
  var pct = next ? Math.max(0, Math.min(100, (total - from) / (next.min - from) * 100)) : 100;
  var text = next
    ? '<i class="fa fa-gift"></i><span class="ms-hint-text">Add <b>' + cur + Math.ceil(next.min - total) + '</b> more to get <b>' + label(next) + '</b></span>'
    : '<i class="fa fa-circle-check"></i><span class="ms-hint-text"><b>' + label(reached) + '</b> unlocked · applied at checkout</span>';
  var key = next ? next.code : 'done';
  if (el.getAttribute('data-key') !== key || !el.querySelector('.ms-hint-bar')) {
    var oldBar = el.querySelector('.ms-hint-bar');
    var oldW = oldBar ? oldBar.style.width : '0%';
    el.innerHTML = text + '<span class="ms-hint-bar" style="width:' + oldW + '"></span>';
    if (el.getAttribute('data-key')) el.querySelector('.ms-hint-text').classList.add('swap');
    el.setAttribute('data-key', key);
  } else {
    el.querySelector('.ms-hint-text').innerHTML = text.replace(/^.*?<span class="ms-hint-text">/, '').replace(/<\/span>$/, '');
  }
  var bar = el.querySelector('.ms-hint-bar');
  setTimeout(function() { if (bar) bar.style.width = pct + '%'; }, 30);
}

// View Cart from the menu with a milestone offer unlocked: celebrate the saving
// (3–4 s, square confetti) on the way to the cart — every time.
var lastCartTotal = 0;
function bestUnlockedOffer(total) {
  var best = null, bestSave = 0;
  milestoneOffers.forEach(function(c) {
    if (total < c.min) return;
    var save = c.type === 'percent' ? total * c.value / 100 : c.value;
    save = Math.min(save, total);
    if (save > bestSave) { best = c; bestSave = save; }
  });
  return best ? { code: best.code, saved: Math.round(bestSave) } : null;
}
function showCouponCelebration(code, saved, done) {
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var ms = reduce ? 1600 : 3500;
  var cur = window.globalCurrencySymbol || '₹';
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

function goToCheckout() {
  var offer = bestUnlockedOffer(lastCartTotal);
  // Every tap on View Cart with an offer unlocked plays the celebration
  // (the cart page then skips its own, since this coupon was just shown)
  if (offer && !window._menuCelebrating) {
    try { sessionStorage.setItem('cpnCelebrated_' + offer.code, '1'); } catch (e) {}
    window._menuCelebrating = true;
    showCouponCelebration(offer.code, offer.saved, function() { goToCheckout(); window._menuCelebrating = false; });
    return;
  }
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

// menuIdx -1 = the "All" category: every dish from every category, in menu order
var ALL_CATEGORY = -1;
var FAV_CATEGORY = -2;   // dishes hearted on this device
function renderProducts(menuIdx) {
  var items, menuName;
  if (menuIdx === ALL_CATEGORY) {
    items = [];
    for (var g = 0; g < menuItems.length; g++) items = items.concat(menuItems[g].items || []);
    menuName = 'All';
  } else if (menuIdx === FAV_CATEGORY) {
    var favIds = getFavItems();
    items = [];
    for (var fg = 0; fg < menuItems.length; fg++) {
      (menuItems[fg].items || []).forEach(function(it) { if (favIds.indexOf(parseInt(it.id, 10)) !== -1 || favIds.indexOf(it.id) !== -1) items.push(it); });
    }
    menuName = 'Favourites';
  } else {
    items = menuItems[menuIdx] ? [].concat(menuItems[menuIdx].items) : [];
    menuName = menuItems[menuIdx] ? menuItems[menuIdx].name : 'Menu';
  }
  // An active offer filter shows its dishes from the whole menu
  if (activeOfferFilter) {
    items = allMenuItemsFlat().filter(function(it) { return offerFilterMatch(activeOfferFilter, it); });
    menuName = activeOfferFilter.title;
  }
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
  if (activeOfferFilter) {
    html += '<div class="offer-active-bar"><span>' + escapeHtml(activeOfferFilter.icon || '🏷️') + ' Showing: ' + escapeHtml(activeOfferFilter.title) + '</span>' +
      '<button type="button" onclick="clearOfferFilter()">✕ Clear</button></div>';
  }
  for (var i = 0; i < items.length; i++) {
    var item = items[i];
    var oos = item.is_available == 0;
    if (isDesignCardStyle()) { html += designCardHtml(item); continue; }
    if (window.menuCardStyle === 'modern') { html += modernCardHtml(item); continue; }
    html += '<div class="card' + (oos ? ' oos' : '') + '" data-itemId="' + item.id + '" onclick="if(!' + oos + ')showItemDetail(' + item.id + ')">' +
      '<div class="card-img-wrap">' +
        '<img src="' + getImageUrl(item.item_image || item.image) + '" alt="' + (item.item_name_translated || item.item_name_en || item.name) + '" loading="lazy">' +
        getTypeIcon(item.item_type) + cardBadgesHtml(item) + favHeartHtml(item) +
        (oos ? '<span class="img-oos">Out of Stock</span>' : '') +
      '</div>' +
      '<div class="card-body">' +
        '<div class="card-name">' + (item.item_name_translated || item.item_name_en || item.name) + '</div>' +
        '<div class="card-price">' + cardPriceHtml(item) + '</div>' +
        (!oos ? '<span id="btn-' + item.id + '">' + renderCartBtn(item.id, item) + '</span>' : '') +
      '</div>' +
    '</div>';
  }
  if (menuIdx === FAV_CATEGORY && !items.length && !activeOfferFilter) {
    html = '<div class="fav-empty"><i class="fa-regular fa-heart"></i><b>No favourites yet</b>Tap the heart on any dish to save it here</div>';
  }
  el.innerHTML = html;
  updateNavActive(menuIdx);
}

// Ingredients popup (from the dish details' "Ingredients" button)
function itemIngredientList(item) {
  return String((item && item.ingredients) || '').split(',').map(function(x) { return x.trim(); }).filter(Boolean);
}
function openIngredients(itemId) {
  var item = getItemModelById(itemId);
  var list = itemIngredientList(item);
  if (!list.length) return;
  var name = escapeHtml(item.item_name_translated || item.item_name_en || item.name || '');
  var pop = document.createElement('div');
  pop.className = 'ingr-pop';
  pop.setAttribute('role', 'dialog');
  pop.setAttribute('aria-label', 'Ingredients of ' + name);
  pop.innerHTML = '<div class="ingr-card">' +
      '<button type="button" class="ingr-x" aria-label="Close"><i class="fa fa-times"></i></button>' +
      '<div class="ingr-head"><div class="ic"><i class="fa-solid fa-leaf"></i></div><div><b>Ingredients</b><span>' + name + '</span></div></div>' +
      '<div class="ingr-chips">' + list.map(function(x, i) { return '<span class="ingr-chip" style="animation-delay:' + (i * 0.03) + 's">' + escapeHtml(x) + '</span>'; }).join('') + '</div>' +
      '<div class="ingr-note">Ask the restaurant if you have an allergy.</div>' +
    '</div>';
  function close() { pop.classList.remove('show'); setTimeout(function() { pop.remove(); }, 220); }
  pop.addEventListener('click', function(e) { if (e.target === pop || e.target.closest('.ingr-x')) close(); });
  document.body.appendChild(pop);
  requestAnimationFrame(function() { pop.classList.add('show'); });
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
  html += '<div class="product-sheet-media">' +
    '<img class="product-sheet-img" src="' + imgUrl + '" alt="' + itemName + '" onerror="this.parentNode.style.display=\'none\'"></div>';

  // Body
  html += '<div class="product-sheet-body">';

  // Name with dynamic type icon (Veg / Non-Veg / Egg)
  var typeColor = itemType ? '#2ecc40' : '';
  if (itemType.toLowerCase().indexOf('non') !== -1) {
    typeColor = '#e53935';
  } else if (itemType.toLowerCase().indexOf('egg') !== -1) {
    typeColor = '#ff9800';
  }
  // ★ Bestseller on its own line, just above the dish name
  if (item.is_bestseller) {
    html += '<div class="sheet-bestseller"><i class="fa-solid fa-award"></i><span>Bestseller</span></div>';
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
    badges.push('<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;background:#fdf2f2;font-size:11px;font-weight:500;color:var(--primary-red, #e17055)"><i class="fa fa-clock" style="font-size:10px"></i> ' + prepTime + ' min</span>');
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

  // Price with the ADD button right next to it (Swiggy-style)
  html += '<div class="sheet-price-row">' +
    '<div class="product-sheet-price" id="sheetPrice">' + formatPrice(itemPrice) + '</div>' +
    '<div class="sheet-add-wrap" id="sheetAddWrap">' + sheetAddControlHtml(item) + '</div>' +
  '</div>';

  if (itemDesc) {
    var safeDesc = escapeHtml(itemDesc);
    var displayDesc = descFmt === 'br' ? safeDesc.replace(/\n/g, '<br>') : safeDesc;
    html += '<div class="product-sheet-desc">' + displayDesc + '</div>';
  }
  // Ingredients: a small button; the list opens in its own popup
  var ingr = itemIngredientList(item);
  if (ingr.length) {
    html += '<button type="button" class="ingr-btn" onclick="openIngredients(' + item.id + ')"><span class="ic"><i class="fa-solid fa-leaf"></i></span>Ingredients <span class="n">(' + ingr.length + ')</span><i class="fa fa-chevron-right"></i></button>';
  }

  html += '</div>'; // end body

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
  syncSheetCutlery(item);
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
  var priceEl = document.getElementById('sheetPrice');
  if (priceEl) priceEl.textContent = formatPrice(totalPrice);
  refreshSheetBarPrice();
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

// ADD control next to the price: Sold out / ADD / − qty +
// ADD next to the price. Tapping it slides up the bar below:
// [ − qty + ]  [ Add item · ₹total ]   (dishes with sizes go to the size step first)
function sheetAddControlHtml(item) {
  if (item.is_available == 0) return '<button type="button" class="sheet-add sold" disabled>Sold out</button>';
  var hasVar = item.has_variations && item.variations && item.variations.length > 0;
  var inCart = getItemTotalQty(item.id);
  var note = inCart > 0 ? inCart + ' in cart' : (hasVar ? 'Customisable' : '');
  return '<button type="button" class="sheet-add" onclick="' + (hasVar ? 'openVariantChooser' : 'openSheetAddBar') + '(' + item.id + ')">ADD<span class="plus">+</span></button>' +
    (note ? '<div class="sheet-add-note">' + note + '</div>' : '');
}

function sheetAddBarHtml(itemId, disabled) {
  return '<div class="product-sheet-footer sheet-add-bar" id="sheetAddBar">' +
    (disabled ? '' :
      '<div class="sheet-note">' +
        '<div class="sheet-note-row">' +
          '<button type="button" class="sheet-note-toggle" onclick="toggleSheetNote()"><i class="fa-regular fa-note-sticky"></i> Add instructions <span>(optional)</span></button>' +
          '<label class="sheet-cutlery"><input type="checkbox" id="sheetCutlery"> 🍴 Cutlery</label>' +
        '</div>' +
        '<div class="sheet-note-box" id="sheetNoteBox" hidden>' +
          '<textarea id="sheetNote" maxlength="130" rows="2" placeholder="e.g. Less spicy, no onion, extra crispy" oninput="document.getElementById(\'sheetNoteCount\').textContent = this.value.length"></textarea>' +
          '<div class="sheet-note-count"><span id="sheetNoteCount">0</span>/130</div>' +
        '</div>' +
      '</div>') +
    '<div class="sheet-add-row">' +
    (disabled ? '' :
      '<div class="sheet-bar-step">' +
        '<button type="button" onclick="sheetBarQty(-1)" aria-label="One less">−</button>' +
        '<span id="sheetBarQty">' + sheetQty + '</span>' +
        '<button type="button" onclick="sheetBarQty(1)" aria-label="One more">+</button>' +
      '</div>') +
    '<button class="sheet-add-btn" id="sheetChooseAdd" onclick="sheetAddToCart(' + itemId + ')"' + (disabled ? ' disabled' : '') + '>' +
      (disabled ? 'Currently unavailable' : 'Add item · <span id="sheetBtnPrice"></span>') + '</button>' +
    '</div>' +
  '</div>';
}

// --- Cooking instructions: one per cart line (dish, or dish + size), saved with the cart
function getItemNotes() {
  try { return JSON.parse(localStorage.getItem('_itemNotes') || '{}') || {}; } catch (e) { return {}; }
}
// The cutlery tick box travels inside the note ("Less spicy · 🍴 Cutlery needed"),
// so the kitchen, admin, rider and WhatsApp all see it with the instruction.
var CUTLERY_TAG = '🍴 Cutlery needed';
function splitItemNote(note) {
  note = String(note || '');
  var cutlery = note.indexOf(CUTLERY_TAG) !== -1;
  var text = note.replace(' · ' + CUTLERY_TAG, '').replace(CUTLERY_TAG, '').trim();
  return { text: text, cutlery: cutlery };
}
function setItemNote(key, text, cutlery) {
  var all = getItemNotes();
  text = String(text || '').replace(/\s+/g, ' ').trim().slice(0, 130);
  if (cutlery) text = text ? text + ' · ' + CUTLERY_TAG : CUTLERY_TAG;
  if (text) all[key] = text; else delete all[key];
  try { localStorage.setItem('_itemNotes', JSON.stringify(all)); } catch (e) {}
}
function syncSheetCutlery(item) {
  var cb = document.getElementById('sheetCutlery');
  if (cb && item) cb.checked = splitItemNote(getItemNotes()[sheetCartKey(item)]).cutlery;
}
function sheetCartKey(item) {
  var hasVar = item.has_variations && item.variations && item.variations.length > 0;
  return hasVar && selectedVariant !== null ? item.id + '_v' + selectedVariant : String(item.id);
}
function toggleSheetNote() {
  var box = document.getElementById('sheetNoteBox');
  if (!box) return;
  box.hidden = !box.hidden;
  if (!box.hidden) {
    var ta = document.getElementById('sheetNote'), item = getSheetItem();
    if (ta && !ta.value && item) {
      ta.value = splitItemNote(getItemNotes()[sheetCartKey(item)]).text;
      document.getElementById('sheetNoteCount').textContent = ta.value.length;
    }
    if (ta) ta.focus();
  }
}

function openSheetAddBar(itemId) {
  if (document.getElementById('sheetAddBar')) return;
  sheetQty = 1;
  document.getElementById('productSheetContent').insertAdjacentHTML('beforeend', sheetAddBarHtml(itemId, false));
  syncSheetCutlery(getSheetItem());
  refreshSheetBarPrice();
}

function sheetBarQty(delta) {
  sheetQty = Math.max(1, Math.min(99, sheetQty + delta));
  var q = document.getElementById('sheetBarQty');
  if (q) q.textContent = sheetQty;
  refreshSheetBarPrice();
}

function refreshSheetBarPrice() {
  var item = getSheetItem();
  var btnPrice = document.getElementById('sheetBtnPrice');
  if (!item || !btnPrice) return;
  var hasVar = item.has_variations && item.variations && item.variations.length > 0;
  var key = String(item.id), unit;
  if (hasVar && selectedVariant !== null) {
    var v = item.variations[selectedVariant];
    unit = parseFloat(v.variation_price || v.price || 0);
    key = item.id + '_v' + selectedVariant;
  } else {
    unit = parseFloat(item.base_price || item.price || 0);
  }
  btnPrice.textContent = formatPrice((unit + getAddonTotalForItem(key)) * sheetQty);
}

// Second step for dishes with sizes: pick one, then "Add item"
function openVariantChooser(itemId) {
  var item = getSheetItem();
  if (!item || !item.variations || !item.variations.length) return;
  sheetQty = 1;
  var name = item.item_name_translated || item.item_name_en || item.name;
  var first = null;
  var html = '<div class="sheet-choose">' +
    '<div class="sheet-choose-head">' +
      '<button type="button" class="sheet-back" onclick="showItemDetail(' + item.id + ')" aria-label="Back"><i class="fa fa-arrow-left"></i></button>' +
      '<div><div class="sheet-choose-name">' + escapeHtml(name) + '</div><div class="sheet-choose-sub">Choose a size</div></div>' +
    '</div><div class="sheet-choose-list">';
  for (var v = 0; v < item.variations.length; v++) {
    var vi = item.variations[v];
    var vName = vi.variation_name || vi.name || 'Option ' + (v + 1);
    var vPrice = parseFloat(vi.variation_price || vi.price || 0);
    var off = vi.is_available == 0;
    if (!off && first === null) first = v;
    html += '<label class="sheet-opt' + (off ? ' disabled' : '') + '">' +
      '<input type="radio" name="sheetVar" value="' + v + '"' + (off ? ' disabled' : ' onchange="selectVariant(' + v + ')"') + '>' +
      '<span class="sheet-opt-name">' + escapeHtml(vName) + '</span>' +
      (off ? '<span class="sheet-opt-off">Unavailable</span>'
           : '<span class="sheet-opt-price">' + (vi.original_price ? '<s>' + formatPrice(vi.original_price) + '</s>' : '') + formatPrice(vPrice) + '</span>') +
    '</label>';
  }
  html += '</div></div>' +
    sheetAddBarHtml(item.id, first === null);
  document.getElementById('productSheetContent').innerHTML = html;
  if (first !== null) {
    var radio = document.querySelector('.sheet-opt input[value="' + first + '"]');
    if (radio) radio.checked = true;
    selectVariant(first);
  }
}

function sheetAddToCart(itemId) {
  var item = getSheetItem();
  if (!item) return;
  var hasVar = item.has_variations && item.variations && item.variations.length > 0;
  if (hasVar && selectedVariant === null) return;
  var noteBox = document.getElementById('sheetNoteBox');
  var noteKey = sheetCartKey(item), prevNote = splitItemNote(getItemNotes()[noteKey]);
  var cutEl = document.getElementById('sheetCutlery');
  setItemNote(noteKey,
    noteBox && !noteBox.hidden ? document.getElementById('sheetNote').value : prevNote.text,
    cutEl ? cutEl.checked : prevNote.cutlery);
  var times = Math.max(1, sheetQty || 1);
  for (var t = 0; t < times; t++) {
    if (hasVar) {
      var v = item.variations[selectedVariant];
      addToCart(itemId, { varIdx: selectedVariant, varName: v.variation_name, varPrice: parseFloat(v.variation_price || v.price || 0) });
    } else {
      addToCart(itemId);
    }
  }
  closeProductSheet();
}

// ── Offers bubble: quick filters set up in the dashboard (Offers > Offer Filters)
var offerFilters = [];
var activeOfferFilter = null;

function allMenuItemsFlat() {
  var out = [];
  for (var g = 0; g < menuItems.length; g++) out = out.concat(menuItems[g].items || []);
  return out;
}
// Price the customer pays (cheapest available size for dishes with sizes)
function offerItemPrice(item) {
  var hasVar = item.has_variations && item.variations && item.variations.length > 0;
  var p = hasVar ? getMinVariantPrice(item) : null;
  return p !== null ? p : parseFloat(item.base_price || item.price || 0);
}
// Biggest real discount on the dish, in % (0 when it has no offer)
function offerItemDiscount(item) {
  var best = 0;
  function pct(now, was) { now = parseFloat(now); was = parseFloat(was); return was > now && was > 0 ? (was - now) / was * 100 : 0; }
  if (item.original_price) best = pct(item.base_price, item.original_price);
  (item.variations || []).forEach(function(v) {
    if (v.original_price) best = Math.max(best, pct(v.variation_price || v.price, v.original_price));
  });
  return best;
}
function offerFilterMatch(f, item) {
  if (item.is_available == 0) return false;
  var type = String(item.item_type || '').toLowerCase();
  switch (f.rule_type) {
    case 'price_under':  return offerItemPrice(item) <= f.rule_value;
    case 'discount_min': return offerItemDiscount(item) + 0.001 >= f.rule_value;
    case 'any_offer':    return offerItemDiscount(item) > 0;
    case 'bestsellers':  return !!item.is_bestseller;
    case 'veg':          return type === 'veg' || (type.indexOf('veg') !== -1 && type.indexOf('non') === -1);
    case 'handpicked':   return (f.item_ids || []).indexOf(parseInt(item.id, 10)) !== -1;
  }
  return false;
}
function offerFilterCount(f) {
  return allMenuItemsFlat().filter(function(it) { return offerFilterMatch(f, it); }).length;
}
// Only filters with at least one matching dish are offered
function visibleOfferFilters() {
  return offerFilters.filter(function(f) { return offerFilterCount(f) > 0; });
}
function refreshOfferBubble() {
  var b = document.getElementById('offerBubble');
  if (!b) return;
  var n = visibleOfferFilters().length;
  b.classList.toggle('show', n > 0);
  b.classList.toggle('active', !!activeOfferFilter);
  document.getElementById('offerBubbleCount').textContent = activeOfferFilter ? '✓' : n;
}
function openOfferSheet() {
  var tiles = document.getElementById('offerTiles');
  tiles.innerHTML = visibleOfferFilters().map(function(f) {
    var n = offerFilterCount(f);
    return '<button type="button" class="offer-tile' + (activeOfferFilter && activeOfferFilter.id === f.id ? ' on' : '') + '" onclick="applyOfferFilter(' + f.id + ')">' +
      '<span class="offer-tile-icon">' + escapeHtml(f.icon || '🏷️') + '</span>' +
      '<span><b>' + escapeHtml(f.title) + '</b><small>' + n + ' dish' + (n === 1 ? '' : 'es') + '</small></span></button>';
  }).join('');
  document.getElementById('offerSheetOverlay').classList.add('active');
  document.body.classList.add('no-scroll');
}
function closeOfferSheet(e) {
  if (e && e.target !== e.currentTarget) return;
  document.getElementById('offerSheetOverlay').classList.remove('active');
  document.body.classList.remove('no-scroll');
}
function applyOfferFilter(id) {
  activeOfferFilter = null;
  for (var i = 0; i < offerFilters.length; i++) if (offerFilters[i].id === id) activeOfferFilter = offerFilters[i];
  var search = document.querySelector('.search-input');
  if (search && search.value) { search.value = ''; var box = search.closest('.search-container'); if (box) box.classList.remove('has-value'); }
  closeOfferSheet();
  renderProducts(currentMenuIdx);
  refreshOfferBubble();
  var sc = document.querySelector('.main-content');
  if (sc) sc.scrollTo({ top: 0, behavior: 'smooth' });
}
function clearOfferFilter() {
  activeOfferFilter = null;
  renderProducts(currentMenuIdx);
  refreshOfferBubble();
}
function initOfferFilters() {
  fetch(apiUrl('getOfferFilters'))
    .then(function(r) { return r.json(); })
    .then(function(list) {
      if (!Array.isArray(list) || !list.length) return;
      offerFilters = list;
      var tries = 0;
      (function wait() {                                     // dishes load after the page
        if (menuItems.length) refreshOfferBubble();
        else if (++tries < 60) setTimeout(wait, 250);
      })();
    })
    .catch(function() {});
}
document.addEventListener('DOMContentLoaded', initOfferFilters);
// Search box hint: "Search for 'Paneer Tikka'", rolling through random dishes
// from this restaurant's own menu every few seconds.
function initSearchHints() {
  var word = document.getElementById('searchHintWord');
  if (!word) return;
  var names = [], i = 0, tries = 0;
  function collect() {
    var seen = {};
    for (var m = 0; m < (menuItems || []).length; m++) {
      var list = menuItems[m] && menuItems[m].items || [];
      for (var k = 0; k < list.length; k++) {
        var it = list[k];
        var n = (it.item_name_translated || it.item_name_en || it.name || '').trim();
        if (n && it.is_available != 0 && !seen[n]) { seen[n] = 1; names.push(n); }
      }
    }
    for (var j = names.length - 1; j > 0; j--) {          // shuffle
      var r = Math.floor(Math.random() * (j + 1)); var t = names[j]; names[j] = names[r]; names[r] = t;
    }
    names = names.slice(0, 15);
  }
  function show() {
    word.textContent = '‘' + names[i % names.length] + '’';
    word.classList.remove('roll'); void word.offsetWidth; word.classList.add('roll');
    i++;
  }
  (function wait() {                                       // dishes load after the page
    if (typeof menuItems !== 'undefined' && menuItems.length) {
      collect();
      if (!names.length) return;
      show();
      setInterval(function() { if (!document.hidden) show(); }, 2600);
    } else if (++tries < 60) {
      setTimeout(wait, 250);
    }
  })();
}
// Scrolling down the dishes hides the top bar (back, logo, Veg Only, filter,
// share) for more room; the search box and categories stay.
// Scrolling back up, or reaching the top, brings it back.
function initMenuChromeAutoHide() {
  var scroller = document.querySelector('.main-content');
  var header = document.querySelector('.pr-share-header');
  var search = document.querySelector('.search-rapper');
  if (!scroller || !header) return;
  // Hiding/showing the bar resizes the scroll area, and the browser then moves
  // the scroll position by itself (most of all in short categories, where the
  // list barely scrolls once the bar is gone). Reacting to those moves made the
  // bar flicker or pop back down. So: react only to the customer's own scrolling
  // (30px in one direction), ignore everything while the bar slides, only hide
  // when there's enough list to keep scrolling, and force it back only at the
  // very top.
  var THRESHOLD = 30, SLIDE_MS = 350;
  var last = scroller.scrollTop, travel = 0, hidden = false, lockedUntil = 0;
  // Only the top bar (back, logo, Veg Only, filter, share) hides; search stays
  function barHeight() { return header.offsetHeight; }
  function setHidden(h) {
    if (h === hidden) return;
    var searchInput = search && search.querySelector('input');
    if (h && searchInput && document.activeElement === searchInput) return; // keep it while typing
    hidden = h;
    var menu = document.getElementById('filterMenu');
    if (h && menu) menu.classList.remove('show');
    header.style.marginTop = h ? -barHeight() + 'px' : '';
    lockedUntil = Date.now() + SLIDE_MS;
    travel = 0;
  }
  scroller.addEventListener('scroll', function() {
    var st = scroller.scrollTop;
    var d = st - last;
    last = st;
    if (d === 0) return;                                      // no movement
    if (Date.now() < lockedUntil) return;                     // bar is sliding: ignore
    if (st <= 4) { setHidden(false); travel = 0; return; }     // at the very top: show
    // Accumulate movement in one direction; a change of direction restarts it
    travel = (d > 0) === (travel > 0) ? travel + d : d;
    if (travel > THRESHOLD) {
      // Only hide when the list is long enough to keep scrolling without the bar
      var room = scroller.scrollHeight - scroller.clientHeight;
      if (room > barHeight() + 80) setHidden(true);
    } else if (travel < -THRESHOLD) {
      setHidden(false);
    }
  }, { passive: true });
}
document.addEventListener('DOMContentLoaded', initMenuChromeAutoHide);

// Category bar along the top: touch swipes natively; with a mouse it can be
// dragged and glides to a stop where it's let go (same feel as Bestsellers).
// A drag never counts as tapping a category.
function initCategoryBarDrag() {
  var bar = document.getElementById('sideNav');
  if (!bar || !bar.parentNode.classList.contains('nav-top')) return;
  var down = false, dragging = false, startX = 0, startLeft = 0, lastX = 0, lastT = 0, v = 0, glideId = 0, suppress = false;
  function stop() { if (glideId) { cancelAnimationFrame(glideId); glideId = 0; } }
  function glide() {
    v *= 0.94;
    if (Math.abs(v) < 0.02) { glideId = 0; return; }
    bar.scrollLeft -= v * 16;
    glideId = requestAnimationFrame(glide);
  }
  bar.addEventListener('pointerdown', function(e) {
    if (e.pointerType !== 'mouse' || e.button !== 0) return;
    stop();
    down = true; dragging = false;
    startX = lastX = e.clientX; startLeft = bar.scrollLeft; lastT = performance.now(); v = 0;
  });
  bar.addEventListener('pointermove', function(e) {
    if (!down) return;
    var dx = e.clientX - startX;
    if (!dragging && Math.abs(dx) > 5) {
      dragging = true;
      bar.classList.add('is-dragging');
      try { bar.setPointerCapture(e.pointerId); } catch (err) {}
    }
    if (dragging) {
      bar.scrollLeft = startLeft - dx;
      var now = performance.now(), dt = now - lastT;
      if (dt > 0) v = 0.8 * ((e.clientX - lastX) / dt) + 0.2 * v;
      lastX = e.clientX; lastT = now;
    }
  });
  function end() {
    if (!down) return;
    down = false;
    if (!dragging) return;
    dragging = false;
    suppress = true;
    setTimeout(function() { suppress = false; }, 0);
    bar.classList.remove('is-dragging');
    if (performance.now() - lastT > 80) v = 0;
    if (Math.abs(v) > 0.05) glideId = requestAnimationFrame(glide);
  }
  bar.addEventListener('pointerup', end);
  bar.addEventListener('pointercancel', end);
  bar.addEventListener('lostpointercapture', end);
  // Mouse wheel over the row scrolls it sideways; at either end the page
  // scrolls normally instead (never traps the wheel)
  bar.addEventListener('wheel', function(e) {
    if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return; // trackpad sideways swipe: native
    var max = bar.scrollWidth - bar.clientWidth;
    if (max <= 0) return;
    if ((e.deltaY < 0 && bar.scrollLeft <= 0) || (e.deltaY > 0 && bar.scrollLeft >= max - 1)) return;
    e.preventDefault();
    stop();
    bar.scrollLeft = Math.max(0, Math.min(max, bar.scrollLeft + e.deltaY));
  }, { passive: false });
  bar.addEventListener('click', function(e) {
    if (suppress) { e.stopPropagation(); e.preventDefault(); suppress = false; }
  }, true);
}
document.addEventListener('DOMContentLoaded', initCategoryBarDrag);

function updateNavActive(menuIdx) {
  var btns = document.querySelectorAll('.side-nav-btn');
  for (var i = 0; i < btns.length; i++) {
    btns[i].classList.remove('active');
    if (parseInt(btns[i].dataset.index) === menuIdx) {
      btns[i].classList.add('active');
      // Top bar: keep the selected category visible (centred) in the scrolling row
      var nav = document.getElementById('sideNav');
      if (nav && nav.parentNode.classList.contains('nav-top')) {
        var b = btns[i].closest('.sub-views') || btns[i];
        nav.scrollTo({ left: b.offsetLeft - (nav.clientWidth - b.offsetWidth) / 2, behavior: 'smooth' });
      }
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
  // "All" first: shows every dish from every category
  var allIcon = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 48 48%22%3E%3Crect width=%2248%22 height=%2248%22 rx=%2212%22 fill=%22%23fff1ec%22/%3E%3Cg fill=%22%23ea580c%22%3E%3Crect x=%2215%22 y=%2215%22 width=%228%22 height=%228%22 rx=%222%22/%3E%3Crect x=%2225%22 y=%2215%22 width=%228%22 height=%228%22 rx=%222%22/%3E%3Crect x=%2215%22 y=%2225%22 width=%228%22 height=%228%22 rx=%222%22/%3E%3Crect x=%2225%22 y=%2225%22 width=%228%22 height=%228%22 rx=%222%22/%3E%3C/g%3E%3C/svg%3E';
  var html = menuItems.length ? '<div class="sub-views">' +
      '<button class="side-nav-btn' + (currentMenuIdx === ALL_CATEGORY ? ' active' : '') + '" data-index="' + ALL_CATEGORY + '">' +
        '<img src="' + (window.allCategoryIcon || allIcon) + '" alt="All dishes" width="36" height="36"><br><span class="cat-name">All</span>' +
      '</button>' +
    '</div>' : '';
  for (var i = 0; i < menuItems.length; i++) {
    var c = menuItems[i];
    var active = i === currentMenuIdx ? ' active' : '';
    var displayName = c.name.replace(/ \(.*\)/, '');
    var img = getMenuImage(c) || 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2242%22 height=%2242%22 viewBox=%220 0 42 42%22%3E%3Crect width=%22100%25%22 height=%22100%25%22 fill=%22%23f0f0f0%22/%3E%3C/svg%3E';
    html += '<div class="sub-views">' +
      '<button class="side-nav-btn' + active + '" data-index="' + i + '">' +
        '<img src="' + getImageUrl(img) + '" alt="' + escapeHtml(displayName) + '" width="36" height="36" loading="' + (i < 5 ? 'eager' : 'lazy') + '" decoding="async"><br>' +
        '<span class="cat-name">' + escapeHtml(displayName) + '</span>' +
      '</button>' +
    '</div>';
  }
  // Favourites last: every dish hearted on this device
  if (menuItems.length) html +=
    '<div class="sub-views">' +
      '<button class="side-nav-btn fav-nav-btn' + (currentMenuIdx === FAV_CATEGORY ? ' active' : '') + '" data-index="' + FAV_CATEGORY + '">' +
        '<span class="fav-nav-wrap"><img src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 48 48%22%3E%3Crect width=%2248%22 height=%2248%22 rx=%2212%22 fill=%22%23fff1f2%22/%3E%3Cpath fill=%22%23ef4444%22 d=%22M24 35.5l-1.9-1.7C15.3 27.7 11 23.8 11 19c0-3.9 3.1-7 7-7 2.2 0 4.3 1 5.7 2.7L24 15l.3-.3C25.7 13 27.8 12 30 12c3.9 0 7 3.1 7 7 0 4.8-4.3 8.7-11.1 14.8L24 35.5z%22/%3E%3C/svg%3E" alt="Favourites" width="36" height="36"><span class="fav-nav-count" id="favNavCount"></span></span><br><span class="cat-name">Favourites</span>' +
      '</button>' +
    '</div>';
  // Add-ons side nav button - only when this restaurant actually has
  // add-ons configured. addonsList is usually still empty here (its fetch
  // is a separate async call from menu load), so this correctly hides it
  // on first paint; updateAddonsNavButton() adds it in afterward without
  // touching the rest of the nav if the fetch turns up any add-ons.
  if (addonsList.length > 0) {
    html += '<div class="sub-views">' +
      '<button class="side-nav-btn" data-id="addons">' +
        '<img src="https://cdn-icons-png.flaticon.com/512/3144/3144456.png" alt="Add-ons" loading="lazy"><br>' +
        'Add-ons' +
      '</button>' +
    '</div>';
  }
  el.innerHTML = html;
  refreshFavNav();
}

// Adds/removes just the Add-ons nav button in place once fetchAddons()
// resolves, instead of re-rendering the whole side nav (which would wipe
// out whatever category the customer already has selected).
function updateAddonsNavButton() {
  var sideNav = document.getElementById('sideNav');
  if (!sideNav) return;
  var existingBtn = sideNav.querySelector('.side-nav-btn[data-id="addons"]');
  if (addonsList.length > 0) {
    if (!existingBtn) {
      var wrap = document.createElement('div');
      wrap.className = 'sub-views';
      wrap.innerHTML = '<button class="side-nav-btn" data-id="addons">' +
        '<img src="https://cdn-icons-png.flaticon.com/512/3144/3144456.png" alt="Add-ons" loading="lazy"><br>' +
        'Add-ons' +
        '</button>';
      sideNav.appendChild(wrap);
    }
  } else if (existingBtn) {
    var container = existingBtn.closest('.sub-views');
    (container || existingBtn).remove();
  }
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
  if (activeOfferFilter) { activeOfferFilter = null; refreshOfferBubble(); }
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
      var startIdx = ALL_CATEGORY; // "All" by default; ?cat=Name opens that category
      if (catParam === 'favourites') {
        startIdx = FAV_CATEGORY;           // ?cat=favourites (e.g. from the profile page)
      } else if (catParam) {
        for (var i = 0; i < menuItems.length; i++) {
          if (menuItems[i].name === catParam) { startIdx = i; break; }
        }
      }
      currentMenuIdx = startIdx;
      renderProducts(startIdx);
      if (startIdx === FAV_CATEGORY) { renderSideNav(); updateNavActive(FAV_CATEGORY); }

      // Load layout columns setting
      var themeQ = 'action=get&restaurant_id=' + encodeURIComponent(window.websiteRestaurantId);
      return fetch('theme_api.php?' + themeQ).then(function(r) { return r.json(); });
    })
    .then(function(themeData) {
      if (themeData && themeData.success && themeData.settings) {
        var cols = themeData.settings.layout_columns || 2;
        // Dashboard "Current" preview: unsaved column count
        if (window.__previewTheme && window.__previewTheme.layout_columns) cols = window.__previewTheme.layout_columns;
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
    var searchBox = searchInput.closest('.search-container');
    var clearBtn = searchBox && searchBox.querySelector('.search-clear');
    if (clearBtn) clearBtn.addEventListener('click', function() {
      searchInput.value = '';
      searchInput.dispatchEvent(new Event('input'));
      searchInput.focus();
    });
    initSearchHints();
    searchInput.addEventListener('input', function() {
      if (searchBox) searchBox.classList.toggle('has-value', this.value !== '');
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
              html += '<div style="grid-column:1/-1;padding:8px 4px 2px;font-size:13px;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:0.5px;cursor:pointer" onclick="switchToMenu(' + menuIdx + ')">' + menuNames[m] + ' <span style="font-size:11px;color:#aaa">↗</span></div>';
              for (var i = 0; i < list.length; i++) {
                var item = list[i];
                var oos = item.is_available == 0;
                if (isDesignCardStyle()) { html += designCardHtml(item); continue; }
    if (window.menuCardStyle === 'modern') { html += modernCardHtml(item); continue; }
                html += '<div class="card' + (oos ? ' oos' : '') + '" data-itemId="' + item.id + '" onclick="if(!' + oos + ')showItemDetail(' + item.id + ')">' +
                  '<div class="card-img-wrap"><img src="' + getImageUrl(item.item_image) + '" alt="' + escapeHtml(item.item_name_translated || item.item_name_en) + '" loading="lazy">' +
                  getTypeIcon(item.item_type) + cardBadgesHtml(item) + favHeartHtml(item) + (oos ? '<span class="img-oos">Out of Stock</span>' : '') + '</div>' +
                  '<div class="card-body">' +
                  '<div class="card-name">' + escapeHtml(item.item_name_translated || item.item_name_en) + '</div>' +
                  '<div class="card-price">' + cardPriceHtml(item) + '</div>' +
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
<script>window.RG_MENU_URL = <?php echo json_encode(restaurantPageUrl('menu')); ?>;</script>
<script src="rg-banners.js?v=<?php echo @filemtime(__DIR__ . '/rg-banners.js'); ?>" defer></script>
</body>
</html>
