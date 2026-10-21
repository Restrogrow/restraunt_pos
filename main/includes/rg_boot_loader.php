<?php
/**
 * Full-screen loading screen (the Bouncing G from Super Admin › Tools › Loading screens).
 *
 * Put  <?php require __DIR__ . '/../includes/rg_boot_loader.php'; ?>  right after <body>.
 *  - Covers the page from the first moment it starts drawing, so the half-built dashboard (empty lists,
 *    jumping cards) is never seen; it fades out once everything has loaded and at least RG_BOOT_MIN_MS
 *    has passed (so it never flashes for a split second).
 *  - Shows again on refresh and when leaving for another page, and hides by itself after 5 s if the
 *    page stays (a download or a phone / WhatsApp link opens without leaving).
 *  - window.rgLoader.show() / .hide() for long jobs on a page.
 *  - Switched off with "Show loading screens" in Super Admin › Tools › Loading screens; speed from there too.
 * Styles: assets/loaders/rg-loaders.css (.l-bounce).
 */
if (!defined('RG_BOOT_MIN_MS')) define('RG_BOOT_MIN_MS', 1200);

$rgBootCfg = ['enabled' => true, 'speed' => 1];
try {
    if (function_exists('getConnection')) {
        $rgBootRow = getConnection()->query("SELECT v FROM sa_settings WHERE k = 'loader_settings'")->fetchColumn();
        $rgBootSaved = $rgBootRow ? json_decode((string)$rgBootRow, true) : null;
        if (is_array($rgBootSaved)) $rgBootCfg = array_merge($rgBootCfg, $rgBootSaved);
    }
} catch (Throwable $e) { /* no settings table yet: defaults */ }

if (!empty($rgBootCfg['enabled'])):
    $rgBootCss = __DIR__ . '/../assets/loaders/rg-loaders.css';
    $rgBootSpd = max(0.5, min(2, (float)($rgBootCfg['speed'] ?? 1)));
?>
<link rel="preload" as="image" href="../assets/loaders/logo-ink.png" fetchpriority="high" crossorigin="anonymous">
<link rel="preload" as="image" href="../assets/loaders/logo-g.png" fetchpriority="high" crossorigin="anonymous">
<link rel="preload" as="image" href="../assets/loaders/g-mark.png" fetchpriority="high" crossorigin="anonymous">
<link rel="stylesheet" href="../assets/loaders/rg-loaders.css?v=<?php echo (int)@filemtime($rgBootCss); ?>">
<style>
  #rgBoot { position: fixed; inset: 0; z-index: 2147483000; display: grid; place-items: center; background: var(--stage-bg);
    transition: opacity .28s ease, visibility .28s ease; --spd: <?php echo $rgBootSpd; ?>; }
  #rgBoot.rg-off { opacity: 0; visibility: hidden; pointer-events: none; }
  #rgBoot .rgb-box { width: min(300px, 70vw); display: grid; place-items: center; gap: 22px; }
  #rgBoot .l-bounce .lg { width: 100%; }
  #rgBoot .rgb-cap { font: 500 12.5px "Poppins", "Segoe UI", system-ui, sans-serif; color: var(--stage-ink); opacity: .55; letter-spacing: .02em; }
  /* phone / computer in dark mode: the colours go on .stage too (rg-loaders.css sets the light ones there,
     which used to leave the black wordmark on a black screen); warm glow, cream letters, glowing G */
  @media (prefers-color-scheme: dark) {
    #rgBoot, #rgBoot .stage { --stage-bg: #121016; --stage-ink: #FBF6F1; --stage-dim: #26222B; --stage-dim-2: #1B1820; }
    #rgBoot { background: radial-gradient(60% 42% at 50% 47%, rgba(252, 83, 1, .16), rgba(252, 83, 1, 0) 72%), linear-gradient(180deg, #19141D 0%, #0E0C12 100%); }
    #rgBoot .gmark { filter: drop-shadow(0 0 12px rgba(252, 83, 1, .55)); }
    #rgBoot .shadow { background: #FC5301; filter: blur(2px); }
    #rgBoot .rgb-cap { color: #FBF6F1; opacity: .62; }
  }
  @media (prefers-reduced-motion: reduce) { #rgBoot .gpos, #rgBoot .shadow { animation: none !important; } }
</style>
<div id="rgBoot" class="rgl-root" role="status" aria-live="polite" aria-label="Loading">
  <div class="rgb-box">
    <div class="stage l-bounce" style="background:none;width:100%;display:grid;place-items:center">
      <div class="lg"><i class="mask-ink"></i><i class="gpos gmark"></i><i class="shadow"></i></div>
    </div>
    <span class="rgb-cap">Loading…</span>
  </div>
</div>
<script>
(function () {
  var el = document.getElementById('rgBoot');
  if (!el) return;
  var MIN = <?php echo (int)RG_BOOT_MIN_MS; ?>, shownAt = 0, hideTimer = null, leaveTimer = null;
  function now() { return (window.performance && performance.now) ? performance.now() : Date.now(); }
  function show() {
    clearTimeout(hideTimer);
    shownAt = now();
    el.classList.remove('rg-off');
  }
  function hide(minMs) {
    var wait = Math.max(0, (minMs == null ? 0 : minMs) - (now() - shownAt));
    clearTimeout(hideTimer);
    hideTimer = setTimeout(function () { el.classList.add('rg-off'); }, wait);
  }
  window.rgLoader = { show: show, hide: function () { hide(400); } };
  // first load: from the very start of the page (performance.now() counts from navigation)
  shownAt = 0;
  var done = function () { hide(MIN); };
  if (document.readyState === 'complete') done(); else window.addEventListener('load', done);
  setTimeout(function () { el.classList.add('rg-off'); }, 10000);   // never stuck, even if something never finishes
  // refresh / going to another page: show it at once; if the page doesn't actually leave, hide again
  window.addEventListener('beforeunload', function () {
    show();
    clearTimeout(leaveTimer);
    leaveTimer = setTimeout(function () { hide(0); }, 5000);
  });
  // back / forward from the browser's cache: the page is already there
  window.addEventListener('pageshow', function (e) { if (e.persisted) hide(0); });
})();
</script>
<?php endif; ?>
