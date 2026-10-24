<?php
/**
 * The brand's outlet picker: brand.com/ or restrogrow.com/<brand>/ (website/brand_router.php, config/brand.php).
 * Expects $conn, $brand and optionally $brandNext (the page they were going to, e.g. "menu").
 * The outlet they used last is offered first ("Continue at …"); "Find the nearest" asks for their location and
 * highlights the closest outlet (also done by itself when the browser already allows location).
 */
require_once __DIR__ . '/../config/brand.php';
require_once __DIR__ . '/brand_router.php';
if (!isset($brand, $conn) || !is_array($brand)) { http_response_code(404); exit; }   // only ever included by the router

$brandNext = isset($brandNext) && isset(BRAND_PAGES[$brandNext]) ? $brandNext : '';
$outlets = brandOutlets($conn, (int)$brand['id'], true);
if (!$outlets) { http_response_code(404); include __DIR__ . '/404.php'; exit; }
if (count($outlets) === 1) { header('Location: ' . brandOutletUrl($brand, $outlets[0], $brandNext) . brandKeepQuery(), true, 302); exit; }
$remembered = brandRememberedOutlet($conn, $brand);

// look: the main outlet's logo and colour (Website Appearance)
$main = null;
foreach ($outlets as $o) if ($o['restaurant_id'] === $brand['default_restaurant_id']) $main = $o;
$main = $main ?: $outlets[0];
$color = '#e11d48';
try {
    $st = $conn->prepare('SELECT primary_red FROM website_settings WHERE restaurant_id = ? LIMIT 1');
    $st->execute([$main['restaurant_id']]);
    $c = (string)$st->fetchColumn();
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $c)) $color = $c;
} catch (Throwable $e) { /* no theme saved */ }
$imgBase = brandSiteRoot() . '/main/website/image.php';
$logo = $main['has_logo'] ? $imgBase . '?type=logo&id=' . $main['user_id'] : '';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$q = brandKeepQuery();
$cards = array_map(fn($o) => ['key' => $o['url_key'], 'label' => $o['label'], 'address' => (string)$o['address'], 'phone' => (string)$o['phone'], 'lat' => $o['lat'], 'lng' => $o['lng'],
    'open' => $o['open_now'], 'url' => brandOutletUrl($brand, $o, $brandNext) . $q], $outlets);
$title = $brand['name'] . ' · Choose your outlet';
$canonical = brandPickerUrl($brand, true);
header('Cache-Control: private, no-cache, max-age=0');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($title) ?></title>
<meta name="description" content="<?= $h('Order from ' . $brand['name'] . ': ' . implode(', ', array_column($cards, 'label')) . '.') ?>">
<link rel="canonical" href="<?= $h($canonical) ?>">
<meta property="og:title" content="<?= $h($brand['name']) ?>">
<meta property="og:url" content="<?= $h($canonical) ?>">
<?php if ($logo): ?><link rel="icon" href="<?= $h($logo) ?>"><meta property="og:image" content="<?= $h($logo) ?>"><?php endif; ?>
<?php if (!empty($brand['gsc_verification'])): ?><meta name="google-site-verification" content="<?= $h($brand['gsc_verification']) ?>"><?php endif; ?>
<?php if (!empty($brand['ga_id'])): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= $h($brand['ga_id']) ?>"></script>
<script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date()); gtag('config', <?= json_encode($brand['ga_id']) ?>);</script>
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root { --c: <?= $h($color) ?>; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Poppins, system-ui, sans-serif; background: #f7f7f8; color: #111827; }
  .bp-top { background: linear-gradient(160deg, var(--c), #111827); color: #fff; padding: 36px 16px 64px; text-align: center; }
  .bp-logo { width: 84px; height: 84px; border-radius: 50%; object-fit: cover; background: #fff; border: 3px solid rgba(255,255,255,.7); }
  .bp-top h1 { margin: 12px 0 4px; font-size: 1.6rem; }
  .bp-top p { margin: 0; opacity: .85; }
  .bp-wrap { max-width: 640px; margin: -40px auto 40px; padding: 0 16px; }
  .bp-card { background: #fff; border-radius: 18px; box-shadow: 0 6px 24px rgba(17,24,39,.08); padding: 16px; margin-bottom: 12px; display: flex; gap: 12px; align-items: center; border: 2px solid transparent; }
  .bp-card.near { border-color: var(--c); }
  .bp-card .bp-t { flex: 1; min-width: 0; }
  .bp-card b { font-size: 1.05rem; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .bp-card small { display: block; color: #6b7280; margin-top: 2px; line-height: 1.4; }
  .bp-tag { font-size: .7rem; font-weight: 600; padding: 2px 8px; border-radius: 999px; background: #dcfce7; color: #166534; }
  .bp-tag.closed { background: #fee2e2; color: #991b1b; }
  .bp-tag.near { background: var(--c); color: #fff; }
  .bp-go { text-decoration: none; background: var(--c); color: #fff; font-weight: 600; padding: 10px 16px; border-radius: 12px; white-space: nowrap; }
  .bp-continue { display: block; text-decoration: none; text-align: center; background: #111827; color: #fff; font-weight: 600; padding: 14px; border-radius: 14px; margin-bottom: 14px; }
  .bp-find { display: block; width: 100%; border: 1.5px dashed #d1d5db; background: #fff; color: #374151; padding: 12px; border-radius: 14px; font: inherit; font-weight: 500; cursor: pointer; margin-bottom: 14px; }
  .bp-note { text-align: center; color: #9ca3af; font-size: .8rem; }
  .bp-note a { color: inherit; }
</style>
</head>
<body>
  <header class="bp-top">
    <?php if ($logo): ?><img class="bp-logo" src="<?= $h($logo) ?>" alt="<?= $h($brand['name']) ?>"><?php endif; ?>
    <h1><?= $h($brand['name']) ?></h1>
    <p><?= $h($brand['tagline'] ?: 'Choose your outlet to see its menu and order') ?></p>
  </header>
  <main class="bp-wrap">
    <?php if ($remembered): ?>
      <a class="bp-continue" href="<?= $h(brandOutletUrl($brand, $remembered, $brandNext) . $q) ?>">Continue at <?= $h($remembered['label']) ?> →</a>
    <?php endif; ?>
    <button class="bp-find" type="button" id="bpFind">📍 Find the outlet nearest to me</button>
    <div id="bpList">
      <?php foreach ($cards as $c): ?>
        <div class="bp-card" data-key="<?= $h($c['key']) ?>" data-lat="<?= $h($c['lat']) ?>" data-lng="<?= $h($c['lng']) ?>">
          <div class="bp-t">
            <b><?= $h($c['label']) ?> <span class="bp-tag<?= $c['open'] ? '' : ' closed' ?>"><?= $c['open'] ? 'Open now' : 'Closed now' ?></span><span class="bp-dist"></span></b>
            <?php if ($c['address'] !== ''): ?><small><?= $h($c['address']) ?></small><?php endif; ?>
          </div>
          <a class="bp-go" href="<?= $h($c['url']) ?>">Order here</a>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="bp-note">Powered by <a href="https://restrogrow.com" target="_blank" rel="noopener">RestroGrow</a></p>
  </main>
<script>
(function () {
  var list = document.getElementById('bpList'), btn = document.getElementById('bpFind');
  function km(a, b, c, d) { var r = Math.PI / 180, x = Math.sin((c - a) * r / 2), y = Math.sin((d - b) * r / 2); return 12742 * Math.asin(Math.sqrt(x * x + Math.cos(a * r) * Math.cos(c * r) * y * y)); }
  function nearest(pos) {
    var me = pos.coords, cards = Array.prototype.slice.call(list.querySelectorAll('.bp-card')), best = null;
    cards.forEach(function (c) {
      var lat = parseFloat(c.dataset.lat), lng = parseFloat(c.dataset.lng);
      c.dist = isNaN(lat) || isNaN(lng) ? Infinity : km(me.latitude, me.longitude, lat, lng);
      if (c.dist !== Infinity) c.querySelector('.bp-dist').innerHTML = ' <span class="bp-tag" style="background:#f3f4f6;color:#374151">' + (c.dist < 1 ? Math.round(c.dist * 1000) + ' m' : c.dist.toFixed(1) + ' km') + '</span>';
      if (c.dist !== Infinity && (!best || c.dist < best.dist)) best = c;
    });
    cards.sort(function (a, b) { return a.dist - b.dist; }).forEach(function (c) { list.appendChild(c); });
    if (best) { best.classList.add('near'); best.querySelector('.bp-dist').insertAdjacentHTML('beforeend', ' <span class="bp-tag near">Nearest</span>'); }
    btn.textContent = best ? '📍 Sorted by distance from you' : '📍 Could not work out distances';
  }
  function ask() {
    if (!navigator.geolocation) { btn.textContent = 'Location is not available on this device'; return; }
    btn.textContent = 'Finding you…';
    navigator.geolocation.getCurrentPosition(nearest, function () { btn.textContent = '📍 Location not allowed: pick your outlet below'; }, { timeout: 8000, maximumAge: 600000 });
  }
  btn.addEventListener('click', ask);
  // already allowed before: sort right away, without a prompt
  if (navigator.permissions && navigator.permissions.query) navigator.permissions.query({ name: 'geolocation' }).then(function (p) { if (p.state === 'granted') ask(); }).catch(function () {});
})();
</script>
</body>
</html>
