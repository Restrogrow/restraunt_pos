<?php
require_once __DIR__ . '/../config/meal_subscription_schema.php';
require_once __DIR__ . '/../config/reservation_helpers.php';
$bottomNavShowPlans = isset($conn, $restaurant_id) && mealSubscriptionsFeatureEnabled($conn, $restaurant_id);
$bottomNavShowReservations = isset($conn, $restaurant_id) && reservationsFeatureEnabled($conn, $restaurant_id);
$navLabels = $navLabels ?? DEFAULT_NAV_LABELS;
$navIcons = $navIcons ?? NAV_ICON_STYLES['classic'];
$navIconOverrides = $navIconOverrides ?? [];
?>
<div class="bottom-nav">
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl(); ?>'">
    <?php echo renderNavIconTag('home', $navIcons, $navIconOverrides); ?>
    <span><?php echo htmlspecialchars($navLabels['home'], ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl('menu'); ?>'">
    <?php echo renderNavIconTag('menu', $navIcons, $navIconOverrides); ?>
    <span><?php echo htmlspecialchars($navLabels['menu'], ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl(); ?>#socialSection'">
    <?php echo renderNavIconTag('social', $navIcons, $navIconOverrides); ?>
    <span><?php echo htmlspecialchars($navLabels['social'], ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
  <?php if (!empty($show_install_app)): ?>
  <div class="nav-item" id="installNavBtn" onclick="promptInstall()">
    <?php echo renderNavIconTag('install', $navIcons, $navIconOverrides); ?>
    <span><?php echo htmlspecialchars($navLabels['install'], ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
  <?php endif; ?>
  <?php if ($bottomNavShowPlans): ?>
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl('plans'); ?>'">
    <?php echo renderNavIconTag('plans', $navIcons, $navIconOverrides); ?>
    <span><?php echo htmlspecialchars($navLabels['plans'], ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
  <?php endif; ?>
  <?php if ($bottomNavShowReservations): ?>
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl('reservations'); ?>'">
    <?php echo renderNavIconTag('reservations', array_merge($navIcons, ['reservations' => 'calendar-check']), $navIconOverrides); ?>
    <span><?php echo htmlspecialchars($navLabels['reservations'], ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
  <?php endif; ?>
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl('cart'); ?>'">
    <?php echo renderNavIconTag('cart', $navIcons, $navIconOverrides); ?>
    <span><?php echo htmlspecialchars($navLabels['cart'], ENT_QUOTES, 'UTF-8'); ?></span>
    <div class="cart-badge" id="navCartBadge">0</div>
  </div>
  <button class="login-btn" onclick="window.location.href='<?php echo restaurantPageUrl('profile'); ?>'" title="<?php echo htmlspecialchars($navLabels['profile'], ENT_QUOTES, 'UTF-8'); ?>" style="font-size:16px;padding:6px 10px;"><?php echo renderNavIconTag('profile', $navIcons, $navIconOverrides, false); ?></button>
</div>
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
