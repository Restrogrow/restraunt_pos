<?php require_once __DIR__ . '/header.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<base href="<?php echo $website_base_href; ?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="restaurant-id" content="<?php echo htmlspecialchars($restaurant_id ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="About Us - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?>">
<link rel="manifest" href="manifest.php<?php echo $restaurant_id ? '?restaurant_id=' . urlencode($restaurant_id) : ''; ?>">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars($restaurant_logo ?? $local_placeholder_svg, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? $local_favicon_svg, ENT_QUOTES, 'UTF-8'); ?>">
<title>About Us - <?php echo htmlspecialchars($restaurant_name ?? 'Dvani Cafe & Grill', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Poppins', sans-serif;
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
  .phone-frame { max-width: 720px; }
<?php endif; ?>
}
.bg-wrapper {
  background: #fff;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}
.header-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px 12px 12px;
  border-bottom: 1.5px solid #ccc;
}
.header-bar h1 {
  font-size: 20px;
  font-weight: 600;
  color: #1a1b1f;
  flex: 1;
}
.back-btn {
  width: 36px; height: 36px;
  border-radius: 50%;
  border: none;
  background: #f0f0f0;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  color: #333;
}
.content { padding: 20px 16px; flex: 1; }

.hero {
  background: linear-gradient(135deg, #2d3436 0%, #4a4a4a 100%);
  border-radius: 20px;
  padding: 32px 24px;
  text-align: center;
  margin-bottom: 20px;
  color: #fff;
  position: relative;
  overflow: hidden;
}
.hero::before {
  content: '';
  position: absolute;
  top: -50%; right: -50%;
  width: 100%; height: 100%;
  background: radial-gradient(circle, rgba(255,255,255,0.06) 0%, transparent 70%);
  pointer-events: none;
}
.hero-img {
  width: 88px;
  height: 88px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid rgba(255,255,255,0.35);
  margin-bottom: 14px;
  box-shadow: 0 4px 16px rgba(0,0,0,0.2);
}
.hero h2 {
  font-size: 24px;
  font-weight: 700;
  margin-bottom: 4px;
}
.hero .tagline {
  font-size: 14px;
  opacity: 0.8;
  font-weight: 400;
}

.story-card {
  background: #f9f9f9;
  border-radius: 16px;
  padding: 24px 20px;
  margin-bottom: 16px;
}
.story-card h3 {
  font-size: 15px;
  font-weight: 600;
  color: #2d3436;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.story-card h3 i {  color: #e17055; }
.story-card p {
  font-size: 14px;
  color: #4b5563;
  line-height: 1.8;
}

.quick-info {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 16px;
}
.quick-info-item {
  background: #f9f9f9;
  border-radius: 14px;
  padding: 18px 14px;
  text-align: center;
}
.quick-info-item i {
  font-size: 22px;
  color: #2d3436;
  margin-bottom: 6px;
  display: block;
}
.quick-info-item .qi-label {
  font-size: 11px;
  color: #999;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.quick-info-item .qi-value {
  font-size: 13px;
  font-weight: 600;
  color: #1a1b1f;
  margin-top: 2px;
}

.feature-list { margin-bottom: 16px; }
.feature-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 18px;
  background: #f9f9f9;
  border-radius: 14px;
  margin-bottom: 10px;
}
.feature-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  color: #fff;
  flex-shrink: 0;
}
.feature-icon.green { background: #10b981; }
.feature-icon.blue { background: #3b82f6; }
.feature-icon.orange { background: #f59e0b; }
.feature-icon.purple { background: #8b5cf6; }
.feature-text { flex: 1; }
.feature-text strong { display: block; font-size: 14px; color: #1a1b1f; }
.feature-text span { display: block; font-size: 12px; color: #6b7280; margin-top: 1px; }

.map-section { margin-bottom: 20px; }
.map-container { border-radius: 14px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
.map-container iframe { display: block; }
.map-address {
  display: flex; align-items: center; gap: 8px;
  margin-top: 10px; padding: 12px 16px;
  background: #f9f9f9; border-radius: 12px;
  font-size: 13px; color: #4b5563; line-height: 1.5;
}
.map-address i { color: #e17055; font-size: 16px; flex-shrink: 0; }

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
.bottom-nav {
  position: fixed; bottom: 0; left: 50%; transform: translateX(-50%);
  width: 100%; max-width: <?php echo ($host === 'triposhsymmetry.in') ? '720px' : '425px'; ?>;
  background: #fff; border-top: 1px solid #e8e8e8;
  display: flex; align-items: center; padding: 8px 12px 10px;
  z-index: 100;
  box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
}
.nav-item {
  flex: 1; display: flex; flex-direction: column; align-items: center;
  gap: 2px; cursor: pointer; position: relative;
  border: none; background: none; font-family: 'Poppins', sans-serif;
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
  background: linear-gradient(135deg, #e17055, #d63031);
  color: #fff; border: none; border-radius: 8px;
  padding: 6px 14px; font-size: 11px; font-family: 'Poppins', sans-serif;
  cursor: pointer; font-weight: 500;
}
</style>
</head>
<body>
<div class="phone-frame">
  <div class="bg-wrapper">
    <div class="header-bar">
      <button class="back-btn" onclick="goBack()"><i class="fa fa-arrow-left"></i></button>
      <h1>About Us</h1>
    </div>

    <div class="content">
      <div class="hero">
        <img class="hero-img" src="<?php echo htmlspecialchars($restaurant_logo ?? $local_placeholder_svg, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?>">
        <h2><?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="tagline"><?php echo htmlspecialchars($restaurant_address ?? 'Good food, great vibes', ENT_QUOTES, 'UTF-8'); ?></p>
      </div>

      <div class="story-card">
        <h3><i class="fa fa-book-open"></i> Our Story</h3>
        <p><?php echo htmlspecialchars($restaurant_description ?? 'We are passionate about serving delicious food made with fresh ingredients. Every dish is crafted with care to bring you an unforgettable dining experience.', ENT_QUOTES, 'UTF-8'); ?></p>
      </div>

      <div class="quick-info">
        <div class="quick-info-item">
          <i class="fa fa-phone"></i>
          <div class="qi-label">Phone</div>
          <div class="qi-value"><?php echo htmlspecialchars($restaurant_phone ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <div class="quick-info-item">
          <i class="fa fa-envelope"></i>
          <div class="qi-label">Email</div>
          <div class="qi-value" style="font-size:11px;word-break:break-all"><?php echo htmlspecialchars($restaurant_email ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <div class="quick-info-item" style="grid-column:1/-1">
          <i class="fa fa-map-marker-alt"></i>
          <div class="qi-label">Address</div>
          <div class="qi-value"><?php echo htmlspecialchars($restaurant_address ?? 'Address not set', ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
      </div>

      <?php
      // Works for any Google Maps link format the owner pastes in Settings,
      // including short links like share.google/... or maps.app.goo.gl/...
      // Resolution is done locally (no outbound HTTP call) so it stays fast
      // and doesn't depend on the host allowing external requests.
      $map_query = '';
      $map_final_link = $google_maps_link ?? '';
      if (!empty($google_maps_link)) {
        $parts = parse_url($google_maps_link);
        if ($parts && isset($parts['query'])) {
          parse_str($parts['query'], $qp);
          if (!empty($qp['q'])) $map_query = $qp['q'];
        }
        if (empty($map_query) && !empty($parts['path']) && preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $parts['path'], $m)) {
          $map_query = $m[1] . ',' . $m[2];
        }
        if (empty($map_query) && preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $google_maps_link, $m)) {
          $map_query = $m[1] . ',' . $m[2];
        }
      }
      if (empty($map_query) && !empty($restaurant_address)) $map_query = $restaurant_address;
      ?>
      <?php if (!empty($map_query)): ?>
      <div class="map-section">
        <h3 style="font-size:15px;font-weight:600;color:#1a3934;margin:0 0 12px;display:flex;align-items:center;gap:8px"><i class="fa fa-map-marked-alt" style="color:#e17055"></i> Find Us</h3>
        <div class="map-container" onclick="openMapLink()" style="cursor:pointer">
          <iframe
            width="100%"
            height="220"
            style="border:0;border-radius:14px;display:block;pointer-events:none"
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
            src="https://maps.google.com/maps?q=<?php echo urlencode($map_query); ?>&output=embed">
          </iframe>
        </div>
        <div class="map-address" onclick="openMapLink()" style="cursor:pointer">
          <i class="fa fa-map-pin"></i>
          <?php echo htmlspecialchars($restaurant_address ?? $map_query, ENT_QUOTES, 'UTF-8'); ?>
        </div>
      </div>
      <?php endif; ?>

      <h3 style="font-size:15px;font-weight:600;color:#1a3934;margin:0 0 12px;display:flex;align-items:center;gap:8px"><i class="fa fa-star" style="color:#846241"></i> Why Choose Us</h3>
      <div class="feature-list">
        <div class="feature-item">
          <div class="feature-icon green"><i class="fa fa-leaf"></i></div>
          <div class="feature-text"><strong>Fresh Ingredients</strong><span>We source the finest and freshest ingredients daily</span></div>
        </div>
        <div class="feature-item">
          <div class="feature-icon orange"><i class="fa fa-fire"></i></div>
          <div class="feature-text"><strong>Authentic Recipes</strong><span>Time-honored recipes passed down through generations</span></div>
        </div>
        <div class="feature-item">
          <div class="feature-icon blue"><i class="fa fa-clock"></i></div>
          <div class="feature-text"><strong>Timely Service</strong><span>Quick preparation and on-time delivery guaranteed</span></div>
        </div>
        <div class="feature-item">
          <div class="feature-icon purple"><i class="fa fa-heart"></i></div>
          <div class="feature-text"><strong>Loved by Many</strong><span>Join thousands of satisfied customers</span></div>
        </div>
      </div>

      <div class="story-card" style="text-align:center;padding:20px">
        <p style="font-size:13px;color:#6b7280;margin-bottom:14px">Have questions? We'd love to hear from you!</p>
        <a href="<?php echo restaurantPageUrl('contact'); ?>" style="display:inline-flex;align-items:center;gap:8px;padding:12px 28px;border-radius:12px;background:linear-gradient(135deg,#e17055,#d63031);color:#fff;text-decoration:none;font-size:13px;font-weight:600;font-family:'Poppins',sans-serif;border:none;cursor:pointer"><i class="fa fa-paper-plane"></i> Contact Us</a>
      </div>
    </div>
  </div>
</div>

<script>
function goBack() {
  if (document.referrer && document.referrer.includes(window.location.host)) {
    window.history.back();
  } else {
    window.location.href = '<?php echo restaurantPageUrl(); ?>';
  }
}
function openMapLink() {
  var link = <?php echo json_encode($google_maps_link ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  var addr = <?php echo json_encode($restaurant_address ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  if (link) { window.open(link, '_blank'); }
  else if (addr) { window.open('https://maps.google.com/maps?q=' + encodeURIComponent(addr), '_blank'); }
}
</script>
<div style="height:80px"></div>
<?php require_once __DIR__ . '/bottom_nav.php'; ?>
</body>
</html>
