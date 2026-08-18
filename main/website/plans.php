<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../config/meal_subscription_schema.php';
if (!mealSubscriptionsFeatureEnabled($conn, $restaurant_id)) {
    header('Location: ' . restaurantPageUrl('menu'));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<base href="<?php echo $website_base_href; ?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="restaurant-id" content="<?php echo htmlspecialchars($restaurant_id ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<title>Meal Plans - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? $local_favicon_svg, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: #e8ecf2; color: #1a1b1f; min-height: 100vh; overflow-x: hidden; }
.phone-frame { max-width: 425px; margin: 0 auto; min-height: 100vh; background: #fff; position: relative; box-shadow: 0 0 40px rgba(0,0,0,0.08); }
@media (min-width: 768px) { .phone-frame { margin: 20px auto; min-height: calc(100vh - 40px); border-radius: 28px; overflow: hidden; } <?php if ($host === 'triposhsymmetry.in'): ?>.phone-frame { max-width: 100%; margin: 0; border-radius: 0; }<?php endif; ?> }

.pr-share-header { display: flex; align-items: center; gap: 12px; padding: 16px 12px 12px; border-bottom: 1.5px solid #eee; }
.pr-share-header h1 { font-size: 18px; font-weight: 700; flex: 1; }
.back-btn { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 8px; background: linear-gradient(135deg, #e17055, #d63031); color: #fff; border: none; cursor: pointer; font-size: 20px; flex-shrink: 0; }

.content { padding: 14px; }
.hero { text-align: center; margin-bottom: 18px; }
.hero h2 { font-size: 19px; font-weight: 700; margin-bottom: 4px; }
.hero p { font-size: 13px; color: #999; }

.loading { text-align: center; padding: 40px; color: #999; }
.empty-state { text-align: center; padding: 50px 20px; color: #999; }
.empty-state i { font-size: 44px; margin-bottom: 14px; opacity: 0.4; }

.plan-card { border: 2px solid #e8e0d8; border-radius: 16px; padding: 18px; margin-bottom: 16px; background: linear-gradient(135deg, #fff, #faf8f6); position: relative; overflow: hidden; }
.plan-card .plan-name { font-size: 17px; font-weight: 700; margin-bottom: 4px; }
.plan-card .plan-desc { font-size: 12.5px; color: #888; margin-bottom: 10px; }
.plan-card .plan-price { font-size: 26px; font-weight: 800; color: #d63031; }
.plan-card .plan-price small { font-size: 13px; font-weight: 500; color: #999; }
.plan-tags { display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0; }
.plan-tag { background: #f0ebe5; color: #8a4a2f; padding: 4px 12px; border-radius: 20px; font-size: 11.5px; font-weight: 600; }
.plan-tag.bonus { background: #fef3c7; color: #92400e; }
.subscribe-btn { width: 100%; margin-top: 8px; padding: 12px; border: none; border-radius: 10px; background: linear-gradient(135deg, #e17055, #d63031); color: #fff; font-weight: 700; font-size: 14px; font-family: 'Poppins', sans-serif; cursor: pointer; }
.subscribe-btn:hover { opacity: 0.92; }

.menu-section { margin-top: 24px; }
.menu-section h3 { font-size: 15px; font-weight: 700; margin-bottom: 10px; }
.week-row { display: grid; grid-template-columns: 78px 1fr; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
.week-row:last-child { border-bottom: none; }
.week-row .day { font-weight: 700; font-size: 12.5px; color: #1a1b1f; }
.week-row .meals { font-size: 12px; color: #666; line-height: 1.6; }
.week-row .meals .mt-label { font-weight: 600; color: #e17055; }
.week-row .meals div { margin-bottom: 2px; }
</style>
</head>
<body>
<div class="phone-frame">
  <div class="pr-share-header">
    <button class="back-btn" onclick="window.location.href='<?php echo restaurantPageUrl('menu'); ?>'" aria-label="Back"><i class="fa fa-arrow-left"></i></button>
    <h1>Meal Plans</h1>
    <?php if ($logged_in_customer): ?>
    <button class="back-btn" style="background:#1a3934;font-size:16px;" onclick="window.location.href='<?php echo restaurantPageUrl('my-subscription'); ?>'" title="My Subscription"><i class="fa fa-user-clock"></i></button>
    <?php endif; ?>
  </div>

  <div class="content">
    <div class="hero">
      <h2>Subscribe &amp; Save</h2>
      <p>Prepaid tiffin bundles - a fresh weekly menu, delivered to your door</p>
    </div>

    <div id="plansList"><div class="loading"><i class="fa fa-spinner fa-spin"></i> Loading plans...</div></div>

    <div class="menu-section">
      <h3>This Week's Menu</h3>
      <div id="weekMenu"><div class="loading"><i class="fa fa-spinner fa-spin"></i> Loading menu...</div></div>
    </div>
  </div>
</div>

<script>
window.restaurantId = <?php echo json_encode($restaurant_id ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.loginUrl = <?php
$plansLoginBase = restaurantPageUrl('login');
$plansLoginSep = strpos($plansLoginBase, '?') !== false ? '&' : '?';
echo json_encode($plansLoginBase . $plansLoginSep . 'redirect=plans', JSON_HEX_TAG | JSON_HEX_AMP);
?>;
window.loggedInCustomer = <?php echo $logged_in_customer ? 'true' : 'false'; ?>;
var CURRENCY = <?php echo json_encode($currency_symbol ?? '₹', JSON_UNESCAPED_UNICODE); ?>;
var DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
var scopeLabels = { lunch: 'Lunch Only', dinner: 'Dinner Only', both: 'Lunch + Dinner' };

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function subscribeToPlan(planId) {
  if (!window.loggedInCustomer) {
    window.location.href = window.loginUrl;
    return;
  }
  window.location.href = 'subscribe.php?plan_id=' + encodeURIComponent(planId);
}

function renderPlans(plans) {
  var el = document.getElementById('plansList');
  if (!plans.length) {
    el.innerHTML = '<div class="empty-state"><i class="fa fa-utensils"></i><h3>No plans available right now</h3><p>Check back soon!</p></div>';
    return;
  }
  var html = '';
  for (var i = 0; i < plans.length; i++) {
    var p = plans[i];
    html += '<div class="plan-card">';
    html += '<div class="plan-name">' + escapeHtml(p.plan_name) + '</div>';
    if (p.description) html += '<div class="plan-desc">' + escapeHtml(p.description) + '</div>';
    html += '<div class="plan-price">' + CURRENCY + parseFloat(p.price).toFixed(2) + '</div>';
    html += '<div class="plan-tags">';
    html += '<span class="plan-tag">' + p.total_meal_credits + ' meals</span>';
    html += '<span class="plan-tag">' + (scopeLabels[p.meal_scope] || p.meal_scope) + '</span>';
    if (p.bonus_credits > 0) html += '<span class="plan-tag bonus">+' + p.bonus_credits + ' bonus meals free</span>';
    html += '</div>';
    html += '<button class="subscribe-btn" onclick="subscribeToPlan(' + p.id + ')">Subscribe Now</button>';
    html += '</div>';
  }
  el.innerHTML = html;
}

function renderWeekMenu(weeklyMenu) {
  var el = document.getElementById('weekMenu');
  if (!weeklyMenu || !weeklyMenu.length) {
    el.innerHTML = '<div class="empty-state" style="padding:20px;"><p>Weekly menu coming soon</p></div>';
    return;
  }
  var byDay = {};
  weeklyMenu.forEach(function(row) {
    byDay[row.day_of_week] = byDay[row.day_of_week] || {};
    byDay[row.day_of_week][row.meal_time] = row.menu_text;
  });
  var html = '';
  for (var d = 0; d < 7; d++) {
    if (!byDay[d]) continue;
    html += '<div class="week-row"><div class="day">' + DAY_NAMES[d] + '</div><div class="meals">';
    if (byDay[d].lunch) html += '<div><span class="mt-label">Lunch:</span> ' + escapeHtml(byDay[d].lunch) + '</div>';
    if (byDay[d].dinner) html += '<div><span class="mt-label">Dinner:</span> ' + escapeHtml(byDay[d].dinner) + '</div>';
    html += '</div></div>';
  }
  el.innerHTML = html || '<div class="empty-state" style="padding:20px;"><p>Weekly menu coming soon</p></div>';
}

fetch('../api/get_meal_plans.php?restaurant_id=' + encodeURIComponent(window.restaurantId) + '&include_menu=1')
  .then(function(r) { return r.json(); })
  .then(function(data) {
    renderPlans(data.success ? (data.data || []) : []);
    renderWeekMenu(data.success ? (data.weekly_menu || []) : []);
  })
  .catch(function() {
    document.getElementById('plansList').innerHTML = '<div class="empty-state"><i class="fa fa-exclamation-circle"></i><h3>Failed to load plans</h3></div>';
    document.getElementById('weekMenu').innerHTML = '';
  });
</script>
</body>
</html>
