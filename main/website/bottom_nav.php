<?php
require_once __DIR__ . '/../config/meal_subscription_schema.php';
$bottomNavShowPlans = isset($conn, $restaurant_id) && mealSubscriptionsFeatureEnabled($conn, $restaurant_id);
?>
<div class="bottom-nav">
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl(); ?>'">
    <i class="fa fa-home nav-icon"></i>
    <span>Home</span>
  </div>
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl('menu'); ?>'">
    <i class="fa fa-utensils nav-icon"></i>
    <span>Menu</span>
  </div>
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl(); ?>#socialSection'">
    <i class="fa fa-share-alt nav-icon"></i>
    <span>Social</span>
  </div>
  <?php if ($bottomNavShowPlans): ?>
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl('plans'); ?>'">
    <i class="fa fa-calendar-check nav-icon"></i>
    <span>Plans</span>
  </div>
  <?php endif; ?>
  <div class="nav-item" onclick="window.location.href='<?php echo restaurantPageUrl('cart'); ?>'">
    <i class="fa fa-shopping-cart nav-icon"></i>
    <span>Cart</span>
    <div class="cart-badge" id="navCartBadge">0</div>
  </div>
  <button class="login-btn" onclick="window.location.href='<?php echo restaurantPageUrl('profile'); ?>'" title="Profile" style="font-size:16px;padding:6px 10px;"><i class="fa fa-user"></i></button>
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
