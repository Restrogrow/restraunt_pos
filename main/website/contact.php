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
<meta name="apple-mobile-web-app-title" content="Contact Us - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?>">
<link rel="manifest" href="manifest.php<?php echo $restaurant_id ? '?restaurant_id=' . urlencode($restaurant_id) : ''; ?>">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars($restaurant_logo ?? $local_placeholder_svg, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? $local_favicon_svg, ENT_QUOTES, 'UTF-8'); ?>">
<title>Contact Us - <?php echo htmlspecialchars($restaurant_name ?? 'Dvani Cafe & Grill', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
  .phone-frame { max-width: 100%; margin: 0; border-radius: 0; }
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

.hero-card {
  background: linear-gradient(135deg, #2d3436 0%, #4a4a4a 100%);
  border-radius: 20px;
  padding: 28px 24px;
  text-align: center;
  margin-bottom: 24px;
  color: #fff;
}
.hero-card img {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid rgba(255,255,255,0.4);
  margin-bottom: 12px;
}
.hero-card h2 {
  font-size: 22px;
  font-weight: 700;
  margin-bottom: 4px;
}
.hero-card p {
  font-size: 14px;
  opacity: 0.85;
  line-height: 1.5;
}

.contact-card {
  background: #f9f9f9;
  border-radius: 16px;
  padding: 6px 0;
  margin-bottom: 16px;
}
.contact-item {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 16px 20px;
  border-bottom: 1px solid #eee;
  cursor: pointer;
  transition: background 0.15s;
}
.contact-item:last-child { border-bottom: none; }
.contact-item:hover { background: #f0f0f0; }
.contact-icon {
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
.contact-icon.phone { background: #10b981; }
.contact-icon.email { background: #3b82f6; }
.contact-icon.location { background: #f59e0b; }
.contact-icon.clock { background: #8b5cf6; }
.contact-detail { flex: 1; min-width: 0; }
.contact-label {
  font-size: 12px;
  color: #999;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.contact-value {
  font-size: 15px;
  font-weight: 500;
  color: #1a1b1f;
  margin-top: 2px;
  word-break: break-word;
}
.contact-arrow {
  color: #ccc;
  font-size: 14px;
  flex-shrink: 0;
}

.map-placeholder {
  background: #f0f0f0;
  border-radius: 16px;
  height: 160px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #999;
  font-size: 14px;
  gap: 8px;
  cursor: pointer;
  overflow: hidden;
  margin-bottom: 16px;
}
.map-placeholder i { font-size: 24px; color: #f59e0b; }

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
  width: 100%; max-width: <?php echo ($host === 'triposhsymmetry.in') ? '100%' : '425px'; ?>;
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
      <h1>Contact Us</h1>
    </div>

    <div class="content">
      <div class="hero-card">
        <img src="<?php echo htmlspecialchars($restaurant_logo ?? $local_placeholder_svg, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?>">
        <h2><?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></h2>
        <p>We'd love to hear from you! Reach out to us through any of the channels below.</p>
      </div>

      <div class="contact-card">
        <a class="contact-item" href="tel:<?php echo htmlspecialchars($restaurant_phone ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          <div class="contact-icon phone"><i class="fa fa-phone"></i></div>
          <div class="contact-detail">
            <div class="contact-label">Phone</div>
            <div class="contact-value"><?php echo htmlspecialchars($restaurant_phone ?? 'Not available', ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <div class="contact-arrow"><i class="fa fa-chevron-right"></i></div>
        </a>
        <a class="contact-item" href="mailto:<?php echo htmlspecialchars($restaurant_email ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          <div class="contact-icon email"><i class="fa fa-envelope"></i></div>
          <div class="contact-detail">
            <div class="contact-label">Email</div>
            <div class="contact-value"><?php echo htmlspecialchars($restaurant_email ?? 'Not available', ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <div class="contact-arrow"><i class="fa fa-chevron-right"></i></div>
        </a>
        <div class="contact-item" onclick="copyAddress()">
          <div class="contact-icon location"><i class="fa fa-map-marker-alt"></i></div>
          <div class="contact-detail">
            <div class="contact-label">Address</div>
            <div class="contact-value"><?php echo htmlspecialchars($restaurant_address ?? 'Address not set', ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <div class="contact-arrow"><i class="fa fa-copy"></i></div>
        </div>
      </div>

      <?php if (!empty($google_maps_link) || !empty($restaurant_address)): ?>
      <div class="map-placeholder" id="mapPlaceholder" onclick="openMap()">
        <i class="fa fa-map-marked-alt"></i>
        <span>Open in Google Maps</span>
      </div>
      <?php endif; ?>

      <div class="contact-card">
        <div class="contact-item">
          <div class="contact-icon clock"><i class="fa fa-clock"></i></div>
          <div class="contact-detail">
            <div class="contact-label">Opening Hours</div>
            <div class="contact-value" id="hoursDisplay">Check back soon</div>
          </div>
        </div>
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

function showToast(msg, type) {
  type = type || 'success';
  var existing = document.querySelector('.toast-notification');
  if (existing) existing.remove();
  var icons = { success: '✓', error: '✕', info: 'ℹ', warning: '⚠' };
  var toast = document.createElement('div');
  toast.className = 'toast-notification ' + type;
  toast.innerHTML = '<span class="toast-icon">' + (icons[type] || icons.success) + '</span>';
  toast.appendChild(document.createTextNode(msg));
  document.body.appendChild(toast);
  requestAnimationFrame(function() { toast.classList.add('show'); });
  setTimeout(function() {
    toast.classList.remove('show');
    setTimeout(function() { toast.remove(); }, 300);
  }, 2500);
}

function copyAddress() {
  var addr = <?php echo json_encode($restaurant_address ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  if (!addr) { showToast('No address available', 'warning'); return; }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(addr).then(function() {
      showToast('Address copied!');
    }).catch(function() {
      fallbackCopy(addr);
    });
  } else {
    fallbackCopy(addr);
  }
}

function fallbackCopy(text) {
  var ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.left = '-9999px';
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand('copy'); showToast('Address copied!'); } catch(e) {}
  document.body.removeChild(ta);
}

function openMap() {
  var mapLink = <?php echo json_encode($google_maps_link ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  var addr = <?php echo json_encode($restaurant_address ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  var url;
  if (mapLink) {
    url = mapLink;
  } else if (addr) {
    url = 'https://maps.google.com/maps?q=' + encodeURIComponent(addr);
  } else {
    showToast('No location available', 'warning');
    return;
  }
  window.open(url, '_blank');
}

function formatHours(hours) {
  if (!hours) return 'Check back soon';
  var days = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
  var today = days[new Date().getDay()];
  var dayData = hours[today];
  if (!dayData || !dayData.open) return 'Closed today';
  return dayData.opening + ' - ' + dayData.closing;
}

document.addEventListener('DOMContentLoaded', function() {
  var hoursEl = document.getElementById('hoursDisplay');
  if (window.openingHours && hoursEl) {
    hoursEl.textContent = formatHours(window.openingHours);
  }
});
</script>
<div style="height:80px"></div>
<?php require_once __DIR__ . '/bottom_nav.php'; ?>
</body>
</html>
