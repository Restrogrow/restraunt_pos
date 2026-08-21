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
:root {
  --primary-red: <?php echo htmlspecialchars($primary_red, ENT_QUOTES, 'UTF-8'); ?>;
  --dark-red: <?php echo htmlspecialchars($dark_red, ENT_QUOTES, 'UTF-8'); ?>;
  --site-font: <?php echo $font_family_css; ?>;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--site-font); background: #e8ecf2; color: #1a1b1f; min-height: 100vh; overflow-x: hidden; }
.phone-frame { max-width: 425px; margin: 0 auto; min-height: 100vh; background: #fff; position: relative; box-shadow: 0 0 40px rgba(0,0,0,0.08); }
@media (min-width: 768px) { .phone-frame { margin: 20px auto; min-height: calc(100vh - 40px); border-radius: 28px; overflow: hidden; } <?php if ($host === 'triposhsymmetry.in'): ?>.phone-frame { max-width: 100%; margin: 0; border-radius: 0; }<?php endif; ?> }

.pr-share-header { display: flex; align-items: center; gap: 12px; padding: 16px 12px 12px; border-bottom: 1.5px solid #eee; }
.pr-share-header h1 { font-size: 18px; font-weight: 700; flex: 1; }
.back-btn { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 8px; background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); color: #fff; border: none; cursor: pointer; font-size: 20px; flex-shrink: 0; }

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
.plan-card .plan-price { font-size: 26px; font-weight: 800; color: var(--dark-red, #d63031); }
.plan-card .plan-price small { font-size: 13px; font-weight: 500; color: #999; }
.plan-tags { display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0; }
.plan-tag { background: #f0ebe5; color: #8a4a2f; padding: 4px 12px; border-radius: 20px; font-size: 11.5px; font-weight: 600; }
.plan-tag.bonus { background: #fef3c7; color: #92400e; }
.subscribe-btn { width: 100%; margin-top: 8px; padding: 12px; border: none; border-radius: 10px; background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); color: #fff; font-weight: 700; font-size: 14px; font-family: var(--site-font); cursor: pointer; }
.subscribe-btn:hover { opacity: 0.92; }

.plan-card .plan-photo { width: 100%; height: 140px; object-fit: cover; border-radius: 12px; margin-bottom: 12px; }

.menu-section { margin-top: 24px; }
.menu-section h3 { font-size: 15px; font-weight: 700; margin-bottom: 10px; }

.week-day { border: 1.5px solid #eee; border-radius: 12px; margin-bottom: 8px; overflow: hidden; }
.week-day-header { width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border: none; background: #faf8f6; font-family: var(--site-font); font-size: 13.5px; font-weight: 700; color: #1a1b1f; cursor: pointer; }
.week-day-header i { color: #999; font-size: 12px; }
.week-day.open .week-day-header { background: #f0ebe5; }
.week-day-body { padding: 12px 14px 14px; }

.meal-tabs { display: flex; gap: 8px; margin-bottom: 12px; }
.meal-tab { flex: 1; padding: 8px 10px; border: 1.5px solid #e8e0d8; border-radius: 8px; background: #fff; font-family: var(--site-font); font-size: 12.5px; font-weight: 600; color: #666; cursor: pointer; }
.meal-tab.active { background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); border-color: transparent; color: #fff; }

.meal-items { display: flex; flex-direction: column; gap: 8px; }
.meal-item-row { display: flex; align-items: center; gap: 10px; }
.meal-item-row img { width: 40px; height: 40px; object-fit: cover; border-radius: 8px; flex-shrink: 0; }
.meal-item-noimg { width: 40px; height: 40px; border-radius: 8px; background: #f0ebe5; color: #c8b8a8; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
.meal-item-row span { font-size: 13px; color: #1a1b1f; font-weight: 500; }
.meal-notes { font-size: 12px; color: #888; margin-top: 6px; line-height: 1.5; }
.meal-empty { font-size: 12px; color: #aaa; font-style: italic; padding: 4px 0; }
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
    if (p.image_url) html += '<img class="plan-photo" src="' + escapeHtml(p.image_url) + '" alt="">';
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

/* Weekly menu is a two-level accordion: tap a day to expand it, then tap
   Lunch/Dinner to switch which meal's dishes are shown - instead of the old
   flat "Lunch: text / Dinner: text" list for every day at once. */
var expandedDay = null;
var activeMealTimeByDay = {};
var lastWeeklyMenu = [];
var lastWeeklyMenuItems = [];

function buildWeeklyMenuByDay(weeklyMenu, weeklyMenuItems) {
  var byDay = {};
  for (var d = 0; d < 7; d++) {
    byDay[d] = { lunch: { items: [], notes: '' }, dinner: { items: [], notes: '' } };
  }
  (weeklyMenu || []).forEach(function(row) {
    if (byDay[row.day_of_week]) byDay[row.day_of_week][row.meal_time].notes = row.menu_text || '';
  });
  (weeklyMenuItems || []).forEach(function(item) {
    if (byDay[item.day_of_week]) byDay[item.day_of_week][item.meal_time].items.push(item);
  });
  return byDay;
}

function dayHasContent(dayData) {
  return dayData.lunch.items.length > 0 || dayData.dinner.items.length > 0 || !!dayData.lunch.notes || !!dayData.dinner.notes;
}

function renderWeekMenu(weeklyMenu, weeklyMenuItems) {
  lastWeeklyMenu = weeklyMenu || [];
  lastWeeklyMenuItems = weeklyMenuItems || [];
  var el = document.getElementById('weekMenu');
  var byDay = buildWeeklyMenuByDay(lastWeeklyMenu, lastWeeklyMenuItems);

  var html = '';
  var anyContent = false;
  for (var d = 0; d < 7; d++) {
    var dayData = byDay[d];
    if (!dayHasContent(dayData)) continue;
    anyContent = true;
    var isOpen = expandedDay === d;
    html += '<div class="week-day' + (isOpen ? ' open' : '') + '">';
    html += '<button type="button" class="week-day-header" onclick="toggleWeekDay(' + d + ')">' +
      '<span>' + DAY_NAMES[d] + '</span><i class="fa fa-chevron-' + (isOpen ? 'up' : 'down') + '"></i></button>';
    if (isOpen) {
      html += '<div class="week-day-body">' + renderDayBody(d, dayData) + '</div>';
    }
    html += '</div>';
  }
  el.innerHTML = anyContent ? html : '<div class="empty-state" style="padding:20px;"><p>Weekly menu coming soon</p></div>';
}

function renderDayBody(d, dayData) {
  var hasLunch = dayData.lunch.items.length > 0 || !!dayData.lunch.notes;
  var hasDinner = dayData.dinner.items.length > 0 || !!dayData.dinner.notes;
  var activeMt = activeMealTimeByDay[d] || (hasLunch ? 'lunch' : 'dinner');
  activeMealTimeByDay[d] = activeMt;

  var html = '<div class="meal-tabs">';
  if (hasLunch) html += '<button type="button" class="meal-tab' + (activeMt === 'lunch' ? ' active' : '') + '" onclick="switchMealTime(' + d + ',\'lunch\')">☀️ Lunch</button>';
  if (hasDinner) html += '<button type="button" class="meal-tab' + (activeMt === 'dinner' ? ' active' : '') + '" onclick="switchMealTime(' + d + ',\'dinner\')">🌙 Dinner</button>';
  html += '</div>';

  var mealData = dayData[activeMt];
  html += '<div class="meal-items">';
  mealData.items.forEach(function(item) {
    html += '<div class="meal-item-row">' +
      (item.image_url ? '<img src="' + escapeHtml(item.image_url) + '" alt="">' : '<div class="meal-item-noimg"><i class="fa fa-utensils"></i></div>') +
      '<span>' + escapeHtml(item.item_name) + '</span></div>';
  });
  if (mealData.notes) html += '<div class="meal-notes">' + escapeHtml(mealData.notes) + '</div>';
  if (!mealData.items.length && !mealData.notes) html += '<div class="meal-empty">Nothing set for this meal yet</div>';
  html += '</div>';
  return html;
}

function toggleWeekDay(d) {
  expandedDay = (expandedDay === d) ? null : d;
  renderWeekMenu(lastWeeklyMenu, lastWeeklyMenuItems);
}

function switchMealTime(d, mt) {
  activeMealTimeByDay[d] = mt;
  renderWeekMenu(lastWeeklyMenu, lastWeeklyMenuItems);
}

fetch('../api/get_meal_plans.php?restaurant_id=' + encodeURIComponent(window.restaurantId) + '&include_menu=1')
  .then(function(r) { return r.json(); })
  .then(function(data) {
    renderPlans(data.success ? (data.data || []) : []);
    renderWeekMenu(data.success ? (data.weekly_menu || []) : [], data.success ? (data.weekly_menu_items || []) : []);
  })
  .catch(function() {
    document.getElementById('plansList').innerHTML = '<div class="empty-state"><i class="fa fa-exclamation-circle"></i><h3>Failed to load plans</h3></div>';
    document.getElementById('weekMenu').innerHTML = '';
  });
</script>
</body>
</html>
