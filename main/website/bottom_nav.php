<?php
require_once __DIR__ . '/../config/meal_subscription_schema.php';
require_once __DIR__ . '/../config/reservation_helpers.php';
$bottomNavShowPlans = isset($conn, $restaurant_id) && mealSubscriptionsFeatureEnabled($conn, $restaurant_id);
$bottomNavShowReservations = isset($conn, $restaurant_id) && reservationsFeatureEnabled($conn, $restaurant_id);
$navLabels = $navLabels ?? DEFAULT_NAV_LABELS;
$navIcons = $navIcons ?? NAV_ICON_STYLES['classic'];
$navIconOverrides = $navIconOverrides ?? [];

// Each nav item is captured as a string (instead of echoed straight into the
// page) so that when "Install App" is on, it can be spliced into the middle
// of the row — rather than always landing right after Menu, which drifts
// away from center as soon as Plans/Reservations are also enabled.
ob_start(); ?>
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl(); ?>'">
    <?php echo renderNavIconTag('home', $navIcons, $navIconOverrides); ?>
    <span><?php echo htmlspecialchars($navLabels['home'], ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
<?php $navHomeHtml = ob_get_clean();

ob_start(); ?>
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl('menu'); ?>'">
    <?php echo renderNavIconTag('menu', $navIcons, $navIconOverrides); ?>
    <span><?php echo htmlspecialchars($navLabels['menu'], ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
<?php $navMenuHtml = ob_get_clean();

ob_start(); ?>
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl(); ?>#socialSection'">
    <?php echo renderNavIconTag('social', $navIcons, $navIconOverrides); ?>
    <span><?php echo htmlspecialchars($navLabels['social'], ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
<?php $navSocialHtml = ob_get_clean();

ob_start(); ?>
  <div class="nav-item" id="installNavBtn" onclick="promptInstall()">
    <?php echo renderNavIconTag('install', $navIcons, $navIconOverrides); ?>
    <span><?php echo htmlspecialchars($navLabels['install'], ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
<?php $navInstallHtml = ob_get_clean();

ob_start(); ?>
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl('plans'); ?>'">
    <?php echo renderNavIconTag('plans', $navIcons, $navIconOverrides); ?>
    <span><?php echo htmlspecialchars($navLabels['plans'], ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
<?php $navPlansHtml = ob_get_clean();

ob_start(); ?>
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl('reservations'); ?>'">
    <?php echo renderNavIconTag('reservations', array_merge($navIcons, ['reservations' => 'calendar-check']), $navIconOverrides); ?>
    <span><?php echo htmlspecialchars($navLabels['reservations'], ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
<?php $navReservationsHtml = ob_get_clean();

ob_start(); ?>
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl('cart'); ?>'">
    <?php echo renderNavIconTag('cart', $navIcons, $navIconOverrides); ?>
    <span><?php echo htmlspecialchars($navLabels['cart'], ENT_QUOTES, 'UTF-8'); ?></span>
    <div class="cart-badge" id="navCartBadge">0</div>
  </div>
<?php $navCartHtml = ob_get_clean();

if (!empty($show_install_app)) {
    // Install replaces Social's slot but is re-centered: everything else
    // (Home, Menu, Plans?, Reservations?, Cart) stays in its usual order,
    // and Install is inserted at the midpoint of that row so it stays
    // visually centered regardless of which optional items are enabled.
    $otherItems = [$navHomeHtml, $navMenuHtml];
    if ($bottomNavShowPlans) $otherItems[] = $navPlansHtml;
    if ($bottomNavShowReservations) $otherItems[] = $navReservationsHtml;
    $otherItems[] = $navCartHtml;
    array_splice($otherItems, (int) ceil(count($otherItems) / 2), 0, [$navInstallHtml]);
    $navItemsHtml = implode('', $otherItems);
} else {
    $navItemsHtml = $navHomeHtml . $navMenuHtml . $navSocialHtml
        . ($bottomNavShowPlans ? $navPlansHtml : '')
        . ($bottomNavShowReservations ? $navReservationsHtml : '')
        . $navCartHtml;
}
?>
<div class="bottom-nav">
  <?php echo $navItemsHtml; ?>
  <button class="login-btn" onclick="window.location.href='<?php echo restaurantPageUrl('profile'); ?>'" title="<?php echo htmlspecialchars($navLabels['profile'], ENT_QUOTES, 'UTF-8'); ?>" style="font-size:16px;padding:6px 10px;"><?php echo renderNavIconTag('profile', $navIcons, $navIconOverrides, false); ?></button>
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
<script>
(function(){
  try {
    var saved = localStorage.getItem('dvaniCart');
    var items = saved ? JSON.parse(saved) : {};
    var total = 0;
    for (var k in items) {
      var v = items[k];
      total += typeof v === 'number' ? v : (v.qty || 0);
    }
    var badge = document.getElementById('navCartBadge');
    if (badge) badge.textContent = total;
  } catch(e) {}
})();
</script>
