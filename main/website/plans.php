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
<?php include __DIR__ . '/cart_scope.php'; ?>
<title>Meal Plans - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? $local_favicon_svg, ENT_QUOTES, 'UTF-8'); ?>">
<?php require_once __DIR__ . '/font_links.php'; echo websiteFontLinks(['poppins', 'fontawesome']); ?>
<?php if (!empty($font_family_google_param)): ?><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=<?php echo $font_family_google_param; ?>:wght@300;400;500;600;700&display=swap" rel="stylesheet"><?php endif; ?>
<style>
:root {
  --primary-red: <?php echo htmlspecialchars($primary_red, ENT_QUOTES, 'UTF-8'); ?>;
  --dark-red: <?php echo htmlspecialchars($dark_red, ENT_QUOTES, 'UTF-8'); ?>;
  --site-font: <?php echo $font_family_css; ?>;
  --ink: #1a1b1f; --soft: #6b6f78; --faint: #9aa0a8; --line: #ece7e1; --paper: #faf8f5;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--site-font); background: #e8ecf2; color: var(--ink); min-height: 100vh; overflow-x: hidden; }
.phone-frame { max-width: 425px; margin: 0 auto; min-height: 100vh; background: #fff; position: relative; box-shadow: 0 0 40px rgba(0,0,0,0.08); padding-bottom: 24px; }
@media (min-width: 768px) { .phone-frame { margin: 20px auto; min-height: calc(100vh - 40px); border-radius: 28px; overflow: hidden; } <?php if ($host === 'triposhsymmetry.in'): ?>.phone-frame { max-width: 100%; margin: 0; border-radius: 0; }<?php endif; ?> }

/* header */
.pl-head { position: sticky; top: 0; z-index: 20; display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(255,255,255,.94); backdrop-filter: blur(10px); border-bottom: 1px solid var(--line); }
.pl-head h1 { font-size: 17px; font-weight: 700; flex: 1; }
.icon-btn { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); color: #fff; border: none; cursor: pointer; font-size: 17px; flex-shrink: 0; }
.my-plan-btn { display: inline-flex; align-items: center; gap: 7px; height: 38px; padding: 0 14px; border-radius: 12px; border: 1.5px solid var(--line); background: #fff; color: var(--ink); font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; }
.my-plan-btn i { color: var(--dark-red, #d63031); }

/* hero */
.pl-hero { position: relative; margin: 14px; padding: 22px 20px 20px; border-radius: 22px; color: #fff; overflow: hidden;
  background: radial-gradient(260px 180px at 100% 0%, rgba(255,255,255,.22), transparent 70%), linear-gradient(140deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); }
.pl-hero::after { content: '🍱'; position: absolute; right: -6px; bottom: -14px; font-size: 92px; opacity: .2; transform: rotate(-12deg); }
.pl-hero small { display: inline-block; padding: 4px 10px; border-radius: 99px; background: rgba(255,255,255,.2); font-size: 10.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
.pl-hero h2 { margin: 10px 0 6px; font-size: 23px; line-height: 1.2; font-weight: 800; max-width: 15em; }
.pl-hero p { font-size: 13px; line-height: 1.5; opacity: .92; max-width: 26em; }
.pl-perks { position: relative; z-index: 1; display: flex; flex-wrap: wrap; gap: 6px; margin-top: 14px; }
.pl-perks span { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 99px; background: rgba(255,255,255,.95); color: var(--ink); font-size: 11.5px; font-weight: 600; }
.pl-perks i { color: var(--dark-red, #d63031); font-size: 11px; }

/* how it works */
.pl-steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 0 14px 18px; }
.pl-step { padding: 12px 8px; border-radius: 14px; background: var(--paper); text-align: center; }
.pl-step b { display: grid; place-items: center; width: 26px; height: 26px; margin: 0 auto 6px; border-radius: 50%; background: #fff; color: var(--dark-red, #d63031); font-size: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.pl-step span { display: block; font-size: 11.5px; font-weight: 600; line-height: 1.3; color: var(--ink); }

/* section titles + filters */
.pl-title { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; margin: 6px 14px 10px; }
.pl-title h3 { font-size: 17px; font-weight: 800; }
.pl-title span { font-size: 12px; color: var(--faint); }
.pl-filters { display: flex; gap: 6px; margin: 0 14px 12px; overflow-x: auto; scrollbar-width: none; }
.pl-filters::-webkit-scrollbar { display: none; }
.pl-chip { flex: none; padding: 8px 14px; border-radius: 99px; border: 1.5px solid var(--line); background: #fff; font: inherit; font-size: 12.5px; font-weight: 600; color: var(--soft); cursor: pointer; }
.pl-chip.on { background: var(--ink); border-color: var(--ink); color: #fff; }

/* plan cards */
.plans { display: grid; gap: 14px; padding: 0 14px; }
.plan { border-radius: 20px; background: #fff; border: 1.5px solid var(--line); overflow: hidden; box-shadow: 0 6px 20px -12px rgba(0,0,0,.18); }
.plan.best { border-color: var(--primary-red, #e17055); box-shadow: 0 10px 28px -14px var(--primary-red, #e17055); }
.plan-pic { position: relative; height: 158px; background: var(--paper); }
.plan-pic img { width: 100%; height: 100%; object-fit: cover; display: block; }
.plan-pic .noimg { display: grid; place-items: center; height: 100%; font-size: 46px; background: linear-gradient(140deg, #fdf3ea, #f6efe7); }
.plan-pic::after { content: ''; position: absolute; inset: 0; background: linear-gradient(transparent 55%, rgba(0,0,0,.35)); pointer-events: none; }
.badge { position: absolute; z-index: 1; display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; border-radius: 99px; font-size: 11px; font-weight: 700; }
.badge.scope { left: 10px; bottom: 10px; background: rgba(255,255,255,.95); color: var(--ink); }
.badge.bonus { right: 10px; bottom: 10px; background: #fef3c7; color: #92400e; }
.badge.best { left: 10px; top: 10px; background: var(--dark-red, #d63031); color: #fff; }
.plan-body { padding: 14px 16px 16px; }
.plan-name { font-size: 17px; font-weight: 800; line-height: 1.25; }
.plan-desc { margin-top: 6px; font-size: 12.5px; line-height: 1.55; color: var(--soft); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.plan-desc.open { -webkit-line-clamp: unset; display: block; }
.more { background: none; border: 0; padding: 0; margin-top: 2px; font: inherit; font-size: 12px; font-weight: 700; color: var(--dark-red, #d63031); cursor: pointer; }
.plan-facts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin: 12px 0; }
.plan-facts div { padding: 8px 6px; border-radius: 12px; background: var(--paper); text-align: center; }
.plan-facts b { display: block; font-size: 14px; font-weight: 800; }
.plan-facts span { display: block; margin-top: 1px; font-size: 10.5px; color: var(--faint); font-weight: 600; }
.plan-buy { display: flex; align-items: center; gap: 12px; }
.plan-price { flex: 1; }
.plan-price b { display: block; font-size: 23px; font-weight: 800; line-height: 1.1; color: var(--ink); }
.plan-price span { font-size: 11.5px; color: var(--faint); font-weight: 600; }
.sub-btn { display: inline-flex; align-items: center; gap: 8px; padding: 13px 18px; border: 0; border-radius: 14px; background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); color: #fff; font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; box-shadow: 0 8px 18px -8px var(--dark-red, #d63031); }
.sub-btn:active { transform: scale(.98); }

/* weekly menu */
.menu-wrap { margin-top: 26px; }
.days { display: flex; gap: 6px; padding: 0 14px 4px; overflow-x: auto; scrollbar-width: none; }
.days::-webkit-scrollbar { display: none; }
.day { flex: none; width: 52px; padding: 8px 0; border-radius: 14px; border: 1.5px solid var(--line); background: #fff; font: inherit; text-align: center; cursor: pointer; color: var(--ink); }
.day small { display: block; font-size: 10.5px; font-weight: 700; color: var(--faint); text-transform: uppercase; }
.day b { display: block; margin-top: 2px; font-size: 15px; font-weight: 800; }
.day.today small { color: var(--dark-red, #d63031); }
.day.on { background: var(--ink); border-color: var(--ink); color: #fff; }
.day.on small { color: rgba(255,255,255,.7); }
.day.off { opacity: .45; }
.day-card { margin: 10px 14px 0; padding: 14px; border-radius: 18px; background: var(--paper); }
.meal-tabs { display: flex; gap: 4px; padding: 4px; border-radius: 12px; background: #fff; margin-bottom: 12px; }
.meal-tab { flex: 1; padding: 8px; border: 0; border-radius: 9px; background: transparent; font: inherit; font-size: 12.5px; font-weight: 700; color: var(--soft); cursor: pointer; }
.meal-tab.active { background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); color: #fff; }
.dish-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.dish { border-radius: 14px; background: #fff; overflow: hidden; }
.dish img, .dish .noimg { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; display: block; }
.dish .noimg { display: grid; place-items: center; background: #f3ede6; color: #c8b8a8; font-size: 22px; }
.dish span { display: block; padding: 8px 10px; font-size: 12.5px; font-weight: 600; line-height: 1.3; }
.meal-notes { margin-top: 10px; font-size: 12.5px; color: var(--soft); line-height: 1.5; }
.day-off { padding: 18px 10px; text-align: center; color: var(--soft); font-size: 13px; }
.day-off b { display: block; font-size: 15px; color: var(--ink); margin-bottom: 2px; }

/* faq */
.faq { margin: 26px 14px 0; display: grid; gap: 8px; }
.faq details { padding: 13px 14px; border-radius: 14px; border: 1.5px solid var(--line); }
.faq summary { list-style: none; display: flex; justify-content: space-between; gap: 10px; font-size: 13.5px; font-weight: 700; cursor: pointer; }
.faq summary::-webkit-details-marker { display: none; }
.faq summary::after { content: '+'; color: var(--dark-red, #d63031); font-size: 17px; line-height: 1; }
.faq details[open] summary::after { content: '−'; }
.faq p { margin-top: 8px; font-size: 12.5px; line-height: 1.55; color: var(--soft); }

.loading { text-align: center; padding: 40px; color: var(--faint); }
.empty-state { text-align: center; padding: 40px 20px; color: var(--faint); }
.empty-state i { font-size: 40px; margin-bottom: 12px; opacity: .4; }
.empty-state h3 { color: var(--ink); font-size: 15px; margin-bottom: 4px; }
.skel { height: 300px; border-radius: 20px; background: linear-gradient(90deg, #f3efea 25%, #faf7f3 50%, #f3efea 75%); background-size: 200% 100%; animation: sk 1.2s infinite; }
@keyframes sk { to { background-position: -200% 0; } }
@media (prefers-reduced-motion: reduce) { .skel { animation: none; } }
</style>
</head>
<body>
<div class="phone-frame">
  <div class="pl-head">
    <button class="icon-btn" onclick="window.location.href='<?php echo restaurantPageUrl('menu'); ?>'" aria-label="Back"><i class="fa fa-arrow-left"></i></button>
    <h1>Meal Plans</h1>
    <?php if ($logged_in_customer): ?>
    <button class="my-plan-btn" onclick="window.location.href='<?php echo restaurantPageUrl('my-subscription'); ?>'"><i class="fa fa-user-clock"></i> My plan</button>
    <?php endif; ?>
  </div>

  <div class="pl-hero">
    <small>Monthly tiffin</small>
    <h2>Fresh meals every day. Pay once, eat all month.</h2>
    <p>From <?php echo htmlspecialchars($restaurant_name ?? 'our kitchen', ENT_QUOTES, 'UTF-8'); ?>, with a menu that changes through the week, delivered to your door.</p>
    <div class="pl-perks">
      <span><i class="fa fa-calendar-xmark"></i> Skip any day</span>
      <span><i class="fa fa-pause"></i> Pause anytime</span>
      <span><i class="fa fa-truck"></i> Track deliveries</span>
    </div>
  </div>

  <div class="pl-steps" aria-label="How it works">
    <div class="pl-step"><b>1</b><span>Pick a plan</span></div>
    <div class="pl-step"><b>2</b><span>Choose start date &amp; address</span></div>
    <div class="pl-step"><b>3</b><span>Meals arrive daily</span></div>
  </div>

  <div class="pl-title"><h3>Choose your plan</h3><span id="planCount"></span></div>
  <div class="pl-filters" id="filters" hidden></div>
  <div class="plans" id="plansList"><div class="skel"></div></div>

  <div class="menu-wrap" id="menuWrap">
    <div class="pl-title"><h3>This week's menu</h3><span>Tap a day</span></div>
    <div class="days" id="days"></div>
    <div id="weekMenu"><div class="loading"><i class="fa fa-spinner fa-spin"></i> Loading menu...</div></div>
  </div>

  <div class="pl-title" style="margin-top:26px"><h3>Good to know</h3></div>
  <div class="faq" style="margin-top:0">
    <details><summary>Can I skip a day?</summary><p>Yes. Open My plan and pick the day before its meal goes to the kitchen. The meal stays in your plan for later.</p></details>
    <details><summary>Can I pause my plan?</summary><p>Yes, pause and resume from My plan whenever you like. Nothing is lost while it is paused.</p></details>
    <details><summary>How are meals counted?</summary><p>One meal is counted for each delivery day (on Lunch + Dinner plans, one day covers both). Skipped days and days without a menu are not counted.</p></details>
    <details><summary>Can I change my delivery address?</summary><p>Yes, from My plan. Today's meal still waiting in the kitchen follows the new address too.</p></details>
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
var DAY_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
var DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
var scopeLabels = { lunch: 'Lunch', dinner: 'Dinner', both: 'Lunch + Dinner' };
var scopeIcons = { lunch: '☀️', dinner: '🌙', both: '☀️🌙' };

function escapeHtml(str) {
  if (!str && str !== 0) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
// ₹4,999 (Indian grouping), decimals only when there are paise
function money(v) {
  var n = parseFloat(v) || 0;
  return CURRENCY + n.toLocaleString('en-IN', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 });
}

function subscribeToPlan(planId) {
  if (!window.loggedInCustomer) {
    window.location.href = window.loginUrl;
    return;
  }
  window.location.href = 'subscribe.php?plan_id=' + encodeURIComponent(planId);
}

/* Plans. One credit is one delivery day (the generator counts a Lunch + Dinner day once), so a
   "both" plan's credits are days with two meals each: the per-meal price counts both. */
var allPlans = [];
var scopeFilter = '';

function planNumbers(p) {
  var days = (parseInt(p.total_meal_credits, 10) || 0) + (parseInt(p.bonus_credits, 10) || 0);
  var meals = p.meal_scope === 'both' ? days * 2 : days;
  return { days: days, meals: meals, perMeal: meals ? (parseFloat(p.price) || 0) / meals : 0 };
}

function renderFilters() {
  var scopes = [];
  allPlans.forEach(function(p) { if (scopes.indexOf(p.meal_scope) < 0) scopes.push(p.meal_scope); });
  var el = document.getElementById('filters');
  if (scopes.length < 2) { el.hidden = true; return; }
  el.hidden = false;
  var html = '<button type="button" class="pl-chip' + (!scopeFilter ? ' on' : '') + '" onclick="setScope(\'\')">All</button>';
  ['lunch', 'dinner', 'both'].forEach(function(s) {
    if (scopes.indexOf(s) >= 0) html += '<button type="button" class="pl-chip' + (scopeFilter === s ? ' on' : '') + '" onclick="setScope(\'' + s + '\')">' + scopeIcons[s] + ' ' + scopeLabels[s] + '</button>';
  });
  el.innerHTML = html;
}
function setScope(s) { scopeFilter = s; renderFilters(); renderPlans(); }

function renderPlans() {
  var el = document.getElementById('plansList');
  if (!allPlans.length) {
    el.innerHTML = '<div class="empty-state"><i class="fa fa-utensils"></i><h3>No plans available right now</h3><p>Check back soon!</p></div>';
    document.getElementById('planCount').textContent = '';
    return;
  }
  // "Best value": the lowest price per meal, only worth saying when there's a choice
  var bestId = null, best = Infinity;
  if (allPlans.length > 1) allPlans.forEach(function(p) { var n = planNumbers(p); if (n.perMeal && n.perMeal < best) { best = n.perMeal; bestId = p.id; } });

  var shown = allPlans.filter(function(p) { return !scopeFilter || p.meal_scope === scopeFilter; });
  document.getElementById('planCount').textContent = shown.length + (shown.length === 1 ? ' plan' : ' plans');
  var html = '';
  shown.forEach(function(p) {
    var n = planNumbers(p), isBest = p.id === bestId;
    var bonus = parseInt(p.bonus_credits, 10) || 0;
    html += '<article class="plan' + (isBest ? ' best' : '') + '">';
    html += '<div class="plan-pic">' + (p.image_url ? '<img src="' + escapeHtml(p.image_url) + '" alt="' + escapeHtml(p.plan_name) + '" loading="lazy">' : '<div class="noimg">🍱</div>');
    if (isBest) html += '<span class="badge best"><i class="fa fa-star"></i> Best value</span>';
    html += '<span class="badge scope">' + scopeIcons[p.meal_scope] + ' ' + (scopeLabels[p.meal_scope] || escapeHtml(p.meal_scope)) + '</span>';
    if (bonus > 0) html += '<span class="badge bonus">+' + bonus + (p.meal_scope === 'both' ? ' days' : ' meals') + ' free</span>';
    html += '</div><div class="plan-body">';
    html += '<div class="plan-name">' + escapeHtml(p.plan_name) + '</div>';
    if (p.description) {
      html += '<div class="plan-desc" id="desc-' + p.id + '">' + escapeHtml(p.description) + '</div>';
      if (p.description.length > 90) html += '<button type="button" class="more" onclick="toggleDesc(' + p.id + ', this)">Read more</button>';
    }
    html += '<div class="plan-facts">';
    if (p.meal_scope === 'both') {
      html += '<div><b>' + n.days + '</b><span>days</span></div><div><b>' + n.meals + '</b><span>meals</span></div>';
    } else {
      html += '<div><b>' + n.meals + '</b><span>meals</span></div><div><b>' + (scopeLabels[p.meal_scope] || '') + '</b><span>every day</span></div>';
    }
    html += '<div><b>' + money(Math.round(n.perMeal)) + '</b><span>per meal</span></div>';
    html += '</div>';
    html += '<div class="plan-buy"><div class="plan-price"><b>' + money(p.price) + '</b><span>one-time payment</span></div>' +
      '<button type="button" class="sub-btn" onclick="subscribeToPlan(' + p.id + ')">Subscribe <i class="fa fa-arrow-right"></i></button></div>';
    html += '</div></article>';
  });
  el.innerHTML = html || '<div class="empty-state"><p>No plans for this meal time.</p></div>';
}
function toggleDesc(id, btn) {
  var d = document.getElementById('desc-' + id);
  var open = d.classList.toggle('open');
  btn.textContent = open ? 'Show less' : 'Read more';
}

/* Weekly menu: day chips (today first in focus), then Lunch / Dinner for that day. */
var byDay = {};
var activeDay = new Date().getDay();
var activeMealTimeByDay = {};

function buildWeeklyMenuByDay(weeklyMenu, weeklyMenuItems) {
  var out = {};
  for (var d = 0; d < 7; d++) out[d] = { lunch: { items: [], notes: '' }, dinner: { items: [], notes: '' } };
  (weeklyMenu || []).forEach(function(row) { if (out[row.day_of_week]) out[row.day_of_week][row.meal_time].notes = row.menu_text || ''; });
  (weeklyMenuItems || []).forEach(function(item) { if (out[item.day_of_week]) out[item.day_of_week][item.meal_time].items.push(item); });
  return out;
}
function hasMeal(m) { return m.items.length > 0 || !!m.notes; }
function dayHasContent(dd) { return hasMeal(dd.lunch) || hasMeal(dd.dinner); }

function renderWeekMenu() {
  var any = false;
  for (var d = 0; d < 7; d++) if (dayHasContent(byDay[d])) any = true;
  if (!any) { document.getElementById('menuWrap').style.display = 'none'; return; }

  // start the strip at today so "what's coming" reads left to right
  var today = new Date().getDay(), chips = '';
  for (var i = 0; i < 7; i++) {
    var dd = (today + i) % 7, date = new Date(); date.setDate(date.getDate() + i);
    var cls = 'day' + (dd === activeDay ? ' on' : '') + (i === 0 ? ' today' : '') + (dayHasContent(byDay[dd]) ? '' : ' off');
    chips += '<button type="button" class="' + cls + '" onclick="pickDay(' + dd + ')"><small>' + (i === 0 ? 'Today' : DAY_SHORT[dd]) + '</small><b>' + date.getDate() + '</b></button>';
  }
  document.getElementById('days').innerHTML = chips;

  var day = byDay[activeDay], html = '<div class="day-card">';
  if (!dayHasContent(day)) {
    html += '<div class="day-off"><b>' + DAY_NAMES[activeDay] + ' is a day off</b>No meals are delivered, and none are counted.</div>';
  } else {
    var mt = activeMealTimeByDay[activeDay] || (hasMeal(day.lunch) ? 'lunch' : 'dinner');
    if (!hasMeal(day[mt])) mt = mt === 'lunch' ? 'dinner' : 'lunch';
    activeMealTimeByDay[activeDay] = mt;
    if (hasMeal(day.lunch) && hasMeal(day.dinner)) {
      html += '<div class="meal-tabs">' +
        '<button type="button" class="meal-tab' + (mt === 'lunch' ? ' active' : '') + '" onclick="switchMealTime(\'lunch\')">☀️ Lunch</button>' +
        '<button type="button" class="meal-tab' + (mt === 'dinner' ? ' active' : '') + '" onclick="switchMealTime(\'dinner\')">🌙 Dinner</button></div>';
    } else {
      html += '<div style="font-size:12.5px;font-weight:700;color:var(--soft);margin-bottom:10px;">' + (mt === 'lunch' ? '☀️ Lunch' : '🌙 Dinner') + ' · ' + DAY_NAMES[activeDay] + '</div>';
    }
    var meal = day[mt];
    if (meal.items.length) {
      html += '<div class="dish-grid">';
      meal.items.forEach(function(item) {
        html += '<div class="dish">' + (item.image_url ? '<img src="' + escapeHtml(item.image_url) + '" alt="" loading="lazy">' : '<div class="noimg"><i class="fa fa-utensils"></i></div>') +
          '<span>' + escapeHtml(item.item_name) + '</span></div>';
      });
      html += '</div>';
    }
    if (meal.notes) html += '<div class="meal-notes">' + escapeHtml(meal.notes) + '</div>';
  }
  html += '</div>';
  document.getElementById('weekMenu').innerHTML = html;
}
function pickDay(d) { activeDay = d; renderWeekMenu(); }
function switchMealTime(mt) { activeMealTimeByDay[activeDay] = mt; renderWeekMenu(); }

fetch('../api/get_meal_plans.php?restaurant_id=' + encodeURIComponent(window.restaurantId) + '&include_menu=1')
  .then(function(r) { return r.json(); })
  .then(function(data) {
    allPlans = data.success ? (data.data || []) : [];
    renderFilters();
    renderPlans();
    byDay = buildWeeklyMenuByDay(data.success ? data.weekly_menu : [], data.success ? data.weekly_menu_items : []);
    renderWeekMenu();
  })
  .catch(function() {
    document.getElementById('plansList').innerHTML = '<div class="empty-state"><i class="fa fa-exclamation-circle"></i><h3>Failed to load plans</h3><p>Please check your connection and try again.</p></div>';
    document.getElementById('menuWrap').style.display = 'none';
  });
</script>
</body>
</html>
