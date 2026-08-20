<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../config/meal_subscription_schema.php';
$meal_subscriptions_feature_enabled = mealSubscriptionsFeatureEnabled($conn, $restaurant_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<base href="<?php echo $website_base_href; ?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="restaurant-id" content="<?php echo htmlspecialchars($restaurant_id ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<meta name="restaurant-slug" content="<?php echo htmlspecialchars($restaurant_slug ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Profile - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?>">
<link rel="manifest" href="manifest.php<?php echo $restaurant_id ? '?restaurant_id=' . urlencode($restaurant_id) : ''; ?>">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars($restaurant_logo ?? '', ENT_QUOTES, 'UTF-8') ?: 'data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>👤</text></svg>'; ?>">
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? $local_favicon_svg, ENT_QUOTES, 'UTF-8'); ?>">
<title>My Profile - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></title>
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
  .phone-frame { max-width: 100%; margin: 0; border-radius: 0; }
<?php endif; ?>
}
.bg-wrapper {
  background: #fff;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

/* ── Header ── */
.pr-share-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px 12px 12px;
  border-bottom: 1.5px solid #ccc;
}
.pr-share-header h1 {
  font-size: 18px;
  font-weight: 700;
  color: #1a1b1f;
  flex: 1;
}
.back-btn {
  display: flex; align-items: center; justify-content: center;
  width: 40px; height: 40px; border-radius: 8px;
  background: linear-gradient(135deg, #e17055, #d63031);
  color: #fff; border: none; cursor: pointer; font-size: 20px;
  flex-shrink: 0;
  transition: opacity 0.2s, transform 0.2s;
}
.back-btn:hover { opacity: 0.9; }
.back-btn:active { transform: scale(0.93); }

.content {
  padding: 12px;
  flex: 1;
  max-width: 100%;
  animation: fadeSlideUp 0.4s ease;
}
@keyframes fadeSlideUp {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}

/* ── Profile Avatar Hero ── */
.profile-hero {
  background: linear-gradient(135deg, #d63031 0%, #e17055 50%, #c0392b 100%);
  border-radius: 16px;
  padding: 20px 16px 16px;
  margin-bottom: 12px;
  position: relative;
  overflow: hidden;
}
.profile-hero::before {
  content: '';
  position: absolute;
  top: -40%; right: -20%;
  width: 200px; height: 200px;
  background: rgba(255,255,255,0.06);
  border-radius: 50%;
}
.profile-hero::after {
  content: '';
  position: absolute;
  bottom: -30%; left: -10%;
  width: 150px; height: 150px;
  background: rgba(255,255,255,0.04);
  border-radius: 50%;
}
.profile-hero-top {
  display: flex;
  align-items: center;
  gap: 16px;
  position: relative;
  z-index: 1;
}
.avatar-container {
  width: 64px; height: 64px;
  border-radius: 50%;
  background: rgba(255,255,255,0.2);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  font-weight: 700;
  color: #fff;
  flex-shrink: 0;
  border: 3px solid rgba(255,255,255,0.3);
  box-shadow: 0 4px 15px rgba(0,0,0,0.15);
  transition: transform 0.3s;
}
.avatar-container:hover { transform: scale(1.05); }
.avatar-image {
  width: 100%; height: 100%;
  border-radius: 50%;
  object-fit: cover;
}
.hero-user-info { flex: 1; min-width: 0; }
.hero-user-name {
  font-size: 18px;
  font-weight: 700;
  color: #fff;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.hero-user-phone {
  font-size: 13px;
  color: rgba(255,255,255,0.8);
  margin-top: 2px;
}
.hero-edit-btn {
  width: 40px; height: 40px;
  border-radius: 12px;
  border: 1.5px solid rgba(255,255,255,0.25);
  background: rgba(255,255,255,0.1);
  color: #fff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  transition: all 0.2s;
  flex-shrink: 0;
  backdrop-filter: blur(4px);
}
.hero-edit-btn:hover { background: rgba(255,255,255,0.2); }
.hero-edit-btn:active { transform: scale(0.9); }

/* ── Stats Grid ── */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  margin-top: 16px;
  position: relative;
  z-index: 1;
}
.stat-card {
  background: rgba(255,255,255,0.12);
  border-radius: 12px;
  padding: 12px 8px;
  text-align: center;
  backdrop-filter: blur(4px);
  border: 1px solid rgba(255,255,255,0.08);
  transition: all 0.2s;
}
.stat-card:hover { background: rgba(255,255,255,0.18); transform: translateY(-2px); }
.stat-number {
  font-size: 18px;
  font-weight: 700;
  color: #fff;
}
.stat-label {
  font-size: 10px;
  color: rgba(255,255,255,0.75);
  margin-top: 2px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-weight: 500;
}

/* ── Section Cards ── */
.section-card {
  background: #fff;
  border-radius: 14px;
  padding: 16px 14px;
  margin-bottom: 12px;
  border: 1.5px solid #000;
  box-shadow: 0 1px 4px rgba(0,0,0,0.04);
  transition: box-shadow 0.2s;
}
.section-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.section-title {
  font-size: 15px;
  font-weight: 600;
  color: #1a1b1f;
  display: flex;
  align-items: center;
  gap: 8px;
}
.section-title i { color: #e17055; font-size: 14px; }
.section-badge {
  font-size: 10px;
  font-weight: 600;
  color: #e17055;
  background: #fdf0ed;
  padding: 3px 10px;
  border-radius: 20px;
}

/* ── Profile Info Rows ── */
.profile-info-row {
  display: flex;
  align-items: center;
  padding: 10px 0;
  border-bottom: 1px solid #eee;
  gap: 12px;
}
.profile-info-row:last-child { border-bottom: none; }
.profile-icon {
  width: 32px; height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  flex-shrink: 0;
}
.profile-icon.name-icon { background: #fdf0ed; color: #e17055; }
.profile-icon.phone-icon { background: #fff3e0; color: #e65100; }
.profile-icon.email-icon { background: #e3f2fd; color: #1565c0; }
.profile-icon.address-icon { background: #fce4ec; color: #c62828; }
.profile-label-text {
  font-size: 11px;
  color: #9ca3af;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-weight: 500;
}
.profile-value-text {
  font-size: 13px;
  color: #1a1b1f;
  font-weight: 500;
  word-break: break-word;
}
.profile-label-col { min-width: 0; flex: 1; }

/* ── Empty State ── */
.empty-state {
  text-align: center;
  padding: 32px 16px;
}
.empty-state-icon {
  font-size: 48px;
  display: block;
  margin-bottom: 12px;
}
.empty-state-title {
  font-size: 15px;
  font-weight: 600;
  color: #1a1b1f;
  margin-bottom: 6px;
}
.empty-state-desc {
  font-size: 12px;
  color: #999;
  line-height: 1.5;
}

/* ── Order Filter Tabs ── */
.order-filter-tabs {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding-bottom: 4px;
  margin-bottom: 12px;
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.order-filter-tabs::-webkit-scrollbar { display: none; }
.filter-tab {
  font-size: 11px;
  font-weight: 500;
  padding: 6px 14px;
  border-radius: 20px;
  border: 1.5px solid #ddd;
  background: #fff;
  color: #666;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.2s;
  font-family: 'Poppins', sans-serif;
}
.filter-tab:hover { border-color: #e17055; color: #e17055; }
.filter-tab.active {
  background: linear-gradient(135deg, #e17055, #d63031);
  border-color: transparent;
  color: #fff;
}
.filter-tab:active { transform: scale(0.95); }

/* ── Order Cards (Zomato/Swiggy style) ── */
.order-card {
  background: #fff;
  border: 1.5px solid #eee;
  border-radius: 14px;
  margin-bottom: 12px;
  overflow: hidden;
  transition: all 0.2s;
  cursor: pointer;
}
.order-card:hover { border-color: #ccc; box-shadow: 0 4px 16px rgba(0,0,0,0.06); }
.order-card:active { transform: scale(0.99); }
.order-card-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 14px 8px;
}
.order-card-id {
  font-size: 13px;
  font-weight: 700;
  color: #d63031;
}
.order-card-date {
  font-size: 10px;
  color: #999;
  margin-top: 1px;
}
.order-card-status {
  font-size: 10px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 20px;
  display: inline-block;
}
.order-card-status.completed { background: #fdf0ed; color: #e17055; }
.order-card-status.preparing { background: #e3f2fd; color: #1565c0; }
.order-card-status.pending { background: #fff8e1; color: #f57f17; }
.order-card-status.rejected,
.order-card-status.cancelled { background: #fbe9e7; color: #c62828; }
.order-card-status.accepted { background: #e3f2fd; color: #1565c0; }
.order-card-status.ready { background: #f3e5f5; color: #7b1fa2; }

.order-card-items {
  padding: 4px 14px 10px;
}
.order-card-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 0;
}
.order-card-item + .order-card-item {
  border-top: 1px dashed #f0f0f0;
}
.order-item-thumb {
  width: 44px; height: 44px;
  border-radius: 10px;
  object-fit: cover;
  flex-shrink: 0;
  background: #f5f5f5;
  border: 1px solid #eee;
}
.order-item-thumb-placeholder {
  width: 44px; height: 44px;
  border-radius: 10px;
  flex-shrink: 0;
  background: #f5f5f5;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  color: #ccc;
  border: 1px solid #eee;
}
.veg-indicator {
  width: 10px; height: 10px;
  border-radius: 2px;
  flex-shrink: 0;
  display: inline-block;
  vertical-align: middle;
}
.veg-indicator.veg { background: #2ecc40; }
.veg-indicator.nonveg { background: #e53935; }
.veg-indicator.egg { background: #ff9800; }
.order-item-info {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 6px;
}
.order-item-name {
  font-size: 13px;
  font-weight: 500;
  color: #1a1b1f;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.order-item-qty {
  font-size: 12px;
  color: #999;
  flex-shrink: 0;
}
.order-item-total {
  font-size: 12px;
  font-weight: 600;
  color: #1a1b1f;
  flex-shrink: 0;
}

.order-card-divider {
  height: 1px;
  background: #f0f0f0;
  margin: 0 14px;
}

.order-card-bottom {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 14px;
}
.order-card-total-label {
  font-size: 11px;
  color: #999;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.order-card-total {
  font-size: 15px;
  font-weight: 700;
  color: #1a1b1f;
}
.order-card-actions {
  display: flex;
  gap: 8px;
  padding: 10px 14px 14px;
}
.order-card-actions .action-btn {
  flex: 1;
  padding: 10px 12px;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 600;
  font-family: 'Poppins', sans-serif;
  cursor: pointer;
  transition: all 0.2s;
  text-align: center;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border: none;
}
.order-card-actions .action-btn:active { transform: scale(0.95); }
.order-card-actions .action-btn.rate {
  background: linear-gradient(135deg, #e17055, #d63031);
  color: #fff;
}
.order-card-actions .action-btn.rate:hover { opacity: 0.9; box-shadow: 0 4px 12px rgba(214,48,49,0.25); }
.order-card-actions .action-btn.rate.submitted {
  background: #f5f5f5;
  color: #999;
  cursor: default;
  border: 1.5px solid #eee;
}
.order-card-actions .action-btn.reorder {
  background: #fff;
  color: #e17055;
  border: 1.5px solid #e17055;
}
.order-card-actions .action-btn.reorder:hover { background: #fdf0ed; }
.order-card-actions .action-btn.rated-stars {
  background: #fff8e1;
  color: #f59e0b;
  border: 1.5px solid #fcd34d;
  cursor: default;
}

/* ── Order Notes ── */
.order-card-note {
  margin: 0 14px 10px;
  padding: 10px 12px;
  background: #fefce8;
  border-radius: 10px;
  font-size: 11px;
  color: #92400e;
  display: flex;
  align-items: flex-start;
  gap: 6px;
}
.action-btn {
  flex: 1;
  min-width: 80px;
  padding: 8px 12px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 600;
  font-family: 'Poppins', sans-serif;
  cursor: pointer;
  transition: all 0.2s;
  text-align: center;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}
.action-btn:active { transform: scale(0.95); }
.action-btn.rate {
  background: #fef3c7;
  border: 1.5px solid #fcd34d;
  color: #92400e;
}
.action-btn.rate:hover { background: #fde68a; }
.action-btn.rate.submitted {
  background: #f3f4f6;
  border-color: #d1d5db;
  color: #9ca3af;
  cursor: default;
}
.action-btn.reorder {
  background: #fdf0ed;
  border: 1.5px solid #e17055;
  color: #d63031;
}
.action-btn.reorder:hover { background: #fce4dc; }

/* ── Edit Form ── */
.edit-form-card {
  background: #fff;
  border-radius: 14px;
  padding: 18px;
  border: 1.5px solid #000;
  box-shadow: 0 1px 4px rgba(0,0,0,0.04);
  animation: fadeSlideUp 0.35s ease;
}
.edit-form-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 18px;
}
.edit-form-header h3 {
  font-size: 16px;
  font-weight: 600;
  color: #1a1b1f;
}
.edit-form-header .back-edit-btn {
  display: flex; align-items: center; justify-content: center;
  width: 36px; height: 36px; border-radius: 8px;
  background: linear-gradient(135deg, #e17055, #d63031);
  color: #fff; border: none; cursor: pointer; font-size: 16px;
  flex-shrink: 0; transition: opacity 0.2s;
}
.edit-form-header .back-edit-btn:hover { opacity: 0.9; }
.edit-form-header .back-edit-btn:active { transform: scale(0.9); }
.form-group { margin-bottom: 14px; }
.form-group label {
  display: block;
  margin-bottom: 6px;
  font-weight: 500;
  color: #555;
  font-size: 12px;
}
.form-group input,
.form-group textarea {
  width: 100%;
  padding: 10px 12px;
  border: 1.5px solid #ddd;
  border-radius: 8px;
  font-size: 13px;
  font-family: 'Poppins', sans-serif;
  transition: all 0.2s;
  background: #fff;
  color: #1a1b1f;
}
.form-group input:focus,
.form-group textarea:focus {
  outline: none;
  border-color: #e17055;
  box-shadow: 0 0 0 3px rgba(225,112,85,0.1);
}
.form-group input::placeholder,
.form-group textarea::placeholder { color: #aaa; }
.form-group input.error,
.form-group textarea.error {
  border-color: #ef4444;
  background: #fef2f2;
}
.form-actions {
  display: flex;
  gap: 10px;
  margin-top: 20px;
}
.btn-save {
  flex: 1;
  padding: 12px;
  border: none;
  border-radius: 8px;
  background: linear-gradient(135deg, #e17055, #d63031);
  color: #fff;
  font-weight: 600;
  cursor: pointer;
  font-family: 'Poppins', sans-serif;
  font-size: 13px;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}
.btn-save:hover { opacity: 0.9; box-shadow: 0 4px 12px rgba(214,48,49,0.3); }
.btn-save:active { transform: scale(0.97); }
.btn-save:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; }
.btn-cancel {
  flex: 1;
  padding: 12px;
  border: 1.5px solid #ddd;
  border-radius: 8px;
  background: #fff;
  color: #999;
  font-weight: 500;
  cursor: pointer;
  font-family: 'Poppins', sans-serif;
  font-size: 13px;
  transition: all 0.2s;
}
.btn-cancel:hover { background: #f5f5f5; border-color: #ccc; }
.btn-cancel:active { transform: scale(0.97); }

/* ── Quick Actions ── */
.quick-actions {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
  margin-bottom: 12px;
}
.quick-action-btn {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 10px;
  background: #fff;
  border: 1.5px solid #eee;
  border-radius: 12px;
  cursor: pointer;
  transition: all 0.2s;
  font-family: 'Poppins', sans-serif;
  text-align: left;
}
.quick-action-btn:hover { border-color: #ccc; box-shadow: 0 4px 12px rgba(0,0,0,0.06); transform: translateY(-2px); }
.quick-action-btn:active { transform: scale(0.97); }
.quick-action-icon {
  width: 36px; height: 36px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  flex-shrink: 0;
}
.quick-action-icon.qa-menu { background: #fdf0ed; color: #e17055; }
.quick-action-icon.qa-cart { background: #fff3e0; color: #e65100; }
.quick-action-icon.qa-contact { background: #e3f2fd; color: #1565c0; }
.quick-action-icon.qa-orders { background: #fce4ec; color: #c62828; }
.quick-action-icon.qa-subscription { background: #eef2ff; color: #4338ca; }
.quick-action-text { font-size: 12px; color: #1a1b1f; font-weight: 500; }
.quick-action-sub {
  font-size: 10px;
  color: #999;
  margin-top: 1px;
}

/* ── Toast ── */
.toast-notification {
  position: fixed;
  bottom: 100px;
  left: 50%;
  transform: translateX(-50%);
  padding: 14px 24px;
  border-radius: 14px;
  font-size: 13px;
  font-weight: 500;
  z-index: 99999;
  font-family: 'Poppins', sans-serif;
  box-shadow: 0 8px 30px rgba(0,0,0,0.2);
  opacity: 0;
  transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
  transform: translateX(-50%) translateY(24px);
  pointer-events: none;
  max-width: 90%;
  text-align: center;
  display: flex;
  align-items: center;
  gap: 10px;
}
.toast-notification.show { opacity: 1; transform: translateX(-50%) translateY(0); }
.toast-notification.success { background: #10b981; color: #fff; }
.toast-notification.error { background: #ef4444; color: #fff; }
.toast-notification.info { background: #3b82f6; color: #fff; }
.toast-notification.warning { background: #f59e0b; color: #fff; }
.toast-icon { font-size: 16px; flex-shrink: 0; }

/* ── Skeleton ── */
.skeleton {
  background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite;
  border-radius: 8px;
  height: 14px;
  margin-bottom: 8px;
}
@keyframes shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
.skeleton-card-el {
  background: #fff;
  border: 1.5px solid #eee;
  border-radius: 14px;
  padding: 14px;
  margin-bottom: 10px;
}
.skeleton-line { height: 12px; margin-bottom: 8px; }
.skeleton-line-sm { height: 10px; width: 60%; }
.skeleton-line-lg { height: 14px; width: 40%; }

/* ── Feedback Modal ── */
.feedback-modal-overlay {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
  animation: fadeIn 0.2s ease;
}
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
.feedback-modal {
  background: #fff;
  border-radius: 24px;
  padding: 32px 24px 24px;
  max-width: 380px;
  width: 90%;
  position: relative;
  box-shadow: 0 16px 48px rgba(0,0,0,0.2);
  animation: scaleIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes scaleIn {
  from { opacity: 0; transform: scale(0.9); }
  to { opacity: 1; transform: scale(1); }
}
.feedback-close {
  position: absolute;
  top: 14px; right: 18px;
  background: none;
  border: none;
  font-size: 22px;
  color: #9ca3af;
  cursor: pointer;
  width: 32px; height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
}
.feedback-close:hover { background: #f3f4f6; color: #374151; }
.feedback-title {
  font-size: 18px;
  font-weight: 700;
  color: #1a1a2e;
  margin-bottom: 4px;
}
.feedback-subtitle {
  font-size: 13px;
  color: #6b7280;
  margin-bottom: 20px;
}
.star-rating-container {
  text-align: center;
  margin-bottom: 20px;
}
.star-rating {
  font-size: 40px;
  cursor: pointer;
  user-select: none;
  letter-spacing: 6px;
  display: flex;
  justify-content: center;
  gap: 4px;
}
.star-rating span {
  transition: all 0.15s;
  display: inline-block;
  color: #d1d5db;
}
.star-rating span:hover { transform: scale(1.2); }
.star-rating span.active { color: #f59e0b; }
.rating-label {
  font-size: 13px;
  font-weight: 500;
  color: #6b7280;
  margin-top: 6px;
}
.rating-label.highlight { color: #f59e0b; font-weight: 600; }
.feedback-textarea {
  width: 100%;
  border: 2px solid #e5e7eb;
  border-radius: 12px;
  padding: 12px;
  font-size: 13px;
  font-family: 'Poppins', sans-serif;
  resize: none;
  height: 90px;
  box-sizing: border-box;
  margin-bottom: 16px;
  transition: border-color 0.2s;
}
.feedback-textarea:focus {
  outline: none;
  border-color: #e17055;
}
.feedback-error {
  color: #ef4444;
  font-size: 12px;
  margin-bottom: 10px;
  display: none;
}
.submit-feedback-btn {
  width: 100%;
  padding: 14px;
  border: none;
  border-radius: 8px;
  background: linear-gradient(135deg, #e17055, #d63031);
  color: #fff;
  font-weight: 600;
  font-size: 14px;
  font-family: 'Poppins', sans-serif;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.submit-feedback-btn:hover { opacity: 0.9; box-shadow: 0 4px 12px rgba(214,48,49,0.3); }
.submit-feedback-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.submit-feedback-btn:active:not(:disabled) { transform: scale(0.97); }
.feedback-success {
  text-align: center;
  padding: 20px;
  animation: scaleIn 0.3s ease;
}
.feedback-success-icon {
  font-size: 56px;
  display: block;
  margin-bottom: 12px;
  animation: heartBeat 0.6s ease;
}
@keyframes heartBeat {
  0% { transform: scale(1); }
  25% { transform: scale(1.3); }
  50% { transform: scale(1); }
  75% { transform: scale(1.2); }
  100% { transform: scale(1); }
}
.feedback-success-title {
  font-size: 20px;
  font-weight: 700;
  color: #1a1a2e;
  margin-bottom: 6px;
}
.feedback-success-msg {
  font-size: 14px;
  color: #6b7280;
  line-height: 1.5;
}

/* ── Utility ── */
.hidden { display: none !important; }
.fade-in { animation: fadeSlideUp 0.3s ease; }
.bottom-nav {
  position: fixed; bottom: 0; left: 50%; transform: translateX(-50%);
  width: 100%; max-width: <?php echo ($host === 'triposhsymmetry.in') ? '100%' : '425px'; ?>;
  background: #fff; border-top: 1px solid #e8e8e8;
  display: flex; align-items: center; justify-content: space-between; padding: 8px 12px 10px;
  z-index: 100;
  box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
}
.nav-item {
  display: flex; flex-direction: column; align-items: center;
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
    <!-- Header -->
    <div class="pr-share-header">
      <button class="back-btn" onclick="goBack()" aria-label="Back"><i class="fa fa-arrow-left"></i></button>
      <h1>My Profile</h1>
    </div>

    <div class="content">
      <!-- Profile View -->
      <div id="profileInfo">
        <!-- Hero -->
        <div class="profile-hero fade-in">
          <div class="profile-hero-top">
            <div class="avatar-container" id="avatarContainer">
              <span id="avatarText">?</span>
            </div>
            <div class="hero-user-info">
              <div class="hero-user-name" id="heroName">Guest</div>
              <div class="hero-user-phone" id="heroPhone">Add your phone number</div>
            </div>
            <button class="hero-edit-btn" id="editProfileBtn" aria-label="Edit Profile"><i class="fa fa-pen"></i></button>
          </div>
          <div class="stats-grid">
            <div class="stat-card">
              <div class="stat-number" id="statOrders">0</div>
              <div class="stat-label">Orders</div>
            </div>
            <div class="stat-card">
              <div class="stat-number" id="statSpent"><span id="statCurrency"><?php echo htmlspecialchars($currency_symbol ?? '₹'); ?></span>0</div>
              <div class="stat-label">Total Spent</div>
            </div>
            <div class="stat-card">
              <div class="stat-number" id="statMember">-</div>
              <div class="stat-label">Member Since</div>
            </div>
          </div>
        </div>

        <!-- Personal Details -->
        <div class="section-card fade-in" style="animation-delay:0.1s">
          <div class="section-header">
            <div class="section-title"><i class="fa fa-user"></i> Personal Details</div>
            <span class="section-badge"><i class="fa fa-check-circle"></i> Saved</span>
          </div>
          <div class="profile-info-row">
            <div class="profile-icon name-icon"><i class="fa fa-user"></i></div>
            <div class="profile-label-col">
              <div class="profile-label-text">Name</div>
              <div class="profile-value-text" id="profileName">-</div>
            </div>
          </div>
          <div class="profile-info-row">
            <div class="profile-icon phone-icon"><i class="fa fa-phone"></i></div>
            <div class="profile-label-col">
              <div class="profile-label-text">Phone</div>
              <div class="profile-value-text" id="profilePhone">-</div>
            </div>
          </div>
          <div class="profile-info-row">
            <div class="profile-icon email-icon"><i class="fa fa-envelope"></i></div>
            <div class="profile-label-col">
              <div class="profile-label-text">Email</div>
              <div class="profile-value-text" id="profileEmail">-</div>
            </div>
          </div>
          <div class="profile-info-row">
            <div class="profile-icon address-icon"><i class="fa fa-map-marker-alt"></i></div>
            <div class="profile-label-col">
              <div class="profile-label-text">Address</div>
              <div class="profile-value-text" id="profileAddress">-</div>
            </div>
          </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions fade-in">
          <button class="quick-action-btn" onclick="goToMenu()">
            <div class="quick-action-icon qa-menu"><i class="fa fa-utensils"></i></div>
            <div><div class="quick-action-text">Browse Menu</div><div class="quick-action-sub">View dishes</div></div>
          </button>
          <button class="quick-action-btn" onclick="goToCart()">
            <div class="quick-action-icon qa-cart"><i class="fa fa-shopping-bag"></i></div>
            <div><div class="quick-action-text">My Cart</div><div class="quick-action-sub">View cart</div></div>
          </button>
          <button class="quick-action-btn" onclick="goToOrders()">
            <div class="quick-action-icon qa-orders"><i class="fa fa-clipboard-list"></i></div>
            <div><div class="quick-action-text">All Orders</div><div class="quick-action-sub">Order history</div></div>
          </button>
          <?php if ($meal_subscriptions_feature_enabled): ?>
          <button class="quick-action-btn" onclick="goToSubscription()">
            <div class="quick-action-icon qa-subscription"><i class="fa fa-utensils"></i></div>
            <div><div class="quick-action-text">My Subscription</div><div class="quick-action-sub">Tiffin plan</div></div>
          </button>
          <?php endif; ?>
          <button class="quick-action-btn" onclick="goToLoyalty()">
            <div class="quick-action-icon" style="background:#fff8e1;color:#f57f17;"><i class="fa fa-gift"></i></div>
            <div><div class="quick-action-text">Loyalty & Rewards</div><div class="quick-action-sub">Points & referrals</div></div>
          </button>
          <button class="quick-action-btn" onclick="goToContact()">
            <div class="quick-action-icon qa-contact"><i class="fa fa-headset"></i></div>
            <div><div class="quick-action-text">Contact Us</div><div class="quick-action-sub">Get help</div></div>
          </button>
          <button class="quick-action-btn" id="accountActionBtn" onclick="handleAccountAction()">
            <div class="quick-action-icon" id="accountActionIcon" style="background:#e8f5e9;color:#2e7d32;"><i class="fa fa-right-to-bracket"></i></div>
            <div><div class="quick-action-text" id="accountActionText">Login / Sign Up</div><div class="quick-action-sub" id="accountActionSub">Track orders faster</div></div>
          </button>
        </div>

        <!-- Order History -->
        <div class="section-card fade-in" style="animation-delay:0.2s">
          <div class="section-header">
            <div class="section-title"><i class="fa fa-history"></i> Order History</div>
          </div>
          <!-- Filter Tabs -->
          <div class="order-filter-tabs" id="filterTabs">
            <button class="filter-tab active" data-filter="all" onclick="filterOrders('all')">All</button>
            <button class="filter-tab" data-filter="Pending" onclick="filterOrders('Pending')">⏳ Pending</button>
            <button class="filter-tab" data-filter="Accepted,Preparing,Ready" onclick="filterOrders('Accepted,Preparing,Ready')">👨‍🍳 In Progress</button>
            <button class="filter-tab" data-filter="Completed" onclick="filterOrders('Completed')">✅ Completed</button>
            <button class="filter-tab" data-filter="Cancelled,Rejected" onclick="filterOrders('Cancelled,Rejected')">❌ Cancelled</button>
          </div>
          <div id="orderHistory" style="min-height:80px">
            <!-- Skeletons loaded by JS -->
          </div>
        </div>
      </div>

      <!-- Profile Edit -->
      <div id="profileEdit" class="hidden">
        <div class="edit-form-card">
          <div class="edit-form-header">
            <button class="back-edit-btn" onclick="cancelProfileEdit()"><i class="fa fa-arrow-left"></i></button>
            <h3><i class="fa fa-pen" style="color:#2d6a4f;font-size:14px"></i> Edit Profile</h3>
          </div>
          <form id="profileForm" novalidate>
            <div class="form-group">
              <label>Full Name <span style="color:#ef4444">*</span></label>
              <input type="text" id="editName" required placeholder="Enter your full name">
            </div>
            <div class="form-group">
              <label>Phone Number <span style="color:#ef4444">*</span></label>
              <div style="display:flex;align-items:center;gap:6px;">
                <span style="color:#666;font-size:14px;white-space:nowrap;"><?php echo htmlspecialchars($phone_dial_code ?? '+91'); ?></span>
                <input type="tel" id="editPhone" required placeholder="Enter your phone number" style="flex:1;">
              </div>
            </div>
            <div class="form-group">
              <label>Email Address</label>
              <input type="email" id="editEmail" placeholder="your@email.com">
            </div>
            <div class="form-group">
              <label>Delivery Address</label>
              <textarea id="editAddress" rows="2" placeholder="Street, city, pincode..."></textarea>
            </div>
            <div class="form-actions">
              <button type="button" class="btn-cancel" onclick="cancelProfileEdit()">Cancel</button>
              <button type="submit" class="btn-save" id="saveBtn"><i class="fa fa-check"></i> Save Profile</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Feedback Modal -->
<div id="feedbackModalContainer"></div>

<script>
window.globalCurrencySymbol = <?php echo json_encode($currency_symbol ?? '₹', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
window.restaurantCountry = <?php echo json_encode($country ?? 'India', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
window.restaurantDialCode = <?php echo json_encode($phone_dial_code ?? '+91', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.restaurantPhoneMin = <?php echo json_encode((int)($phone_min_digits ?? 10), JSON_HEX_TAG); ?>;
window.restaurantPhoneMax = <?php echo json_encode((int)($phone_max_digits ?? 10), JSON_HEX_TAG); ?>;
window.loggedInCustomer = <?php echo $logged_in_customer ? json_encode([
    'name' => $logged_in_customer['customer_name'],
    'phone' => $logged_in_customer['phone'],
    'email' => $logged_in_customer['email'],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) : 'null'; ?>;
window.loginUrl = <?php
$loginUrlBase = restaurantPageUrl('login');
$loginUrlSep = strpos($loginUrlBase, '?') !== false ? '&' : '?';
echo json_encode($loginUrlBase . $loginUrlSep . 'redirect=profile', JSON_HEX_TAG | JSON_HEX_AMP);
?>;
</script>
<script>
/* ═══════════════════════════════════════════════════════════════
   PROFILE PAGE — JavaScript
   ═══════════════════════════════════════════════════════════════ */

var selectedRating = 0;
var allOrders = [];
var currentFilter = 'all';

/* ── Avatar ── */
function getInitials(name) {
  if (!name || name === 'Guest') return '?';
  var parts = name.trim().split(/\s+/);
  if (parts.length >= 2) return (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
  return parts[0][0].toUpperCase();
}
function getAvatarBgColor(name) {
  var colors = ['#2d6a4f','#1a3934','#0d4f3c','#3a7d5c','#4a9e6e','#1b5e3f'];
  if (!name) return colors[0];
  var hash = 0;
  for (var i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
  return colors[Math.abs(hash) % colors.length];
}
function updateAvatar(name) {
  var avatarText = document.getElementById('avatarText');
  var container = document.getElementById('avatarContainer');
  if (!avatarText || !container) return;
  avatarText.textContent = getInitials(name);
  container.style.background = getAvatarBgColor(name);
}

/* ── Navigation ── */
function goBack() {
  window.location.href = '<?php echo restaurantPageUrl(); ?>';
}
function goToMenu() {
  window.location.href = '<?php echo restaurantPageUrl('menu'); ?>';
}
function goToCart() {
  window.location.href = '<?php echo restaurantPageUrl('cart'); ?>';
}
function goToLoyalty() {
  window.location.href = '<?php echo restaurantPageUrl('loyalty'); ?>';
}
function goToOrders() {
  document.getElementById('orderHistory')?.scrollIntoView({ behavior: 'smooth' });
  filterOrders('all');
}
function goToContact() {
  window.location.href = '<?php echo restaurantPageUrl('contact'); ?>';
}
function goToSubscription() {
  window.location.href = '<?php echo restaurantPageUrl('my-subscription'); ?>';
}

/* ── Storage ── */
function saveCustomerToStorage(d) {
  localStorage.setItem('customerDetails', JSON.stringify(d));
}
function loadCustomerFromStorage() {
  try { var s = localStorage.getItem('customerDetails'); return s ? JSON.parse(s) : null; } catch(e) { return null; }
}

/* ── Account (login/logout) ── */
function handleAccountAction() {
  if (window.loggedInCustomer) {
    logoutCustomer();
  } else {
    window.location.href = window.loginUrl;
  }
}
function logoutCustomer() {
  var fd = new FormData();
  fd.append('action', 'logout');
  fetch('customer_auth.php', { method: 'POST', body: fd })
    .then(function() {
      window.loggedInCustomer = null;
      try { localStorage.removeItem('customerDetails'); } catch(e) {}
      showToast('Logged out', 'success');
      loadProfileData();
      loadOrderHistory();
    })
    .catch(function() { showToast('Could not log out. Please try again.', 'error'); });
}
function updateAccountActionUI() {
  var icon = document.getElementById('accountActionIcon');
  var text = document.getElementById('accountActionText');
  var sub = document.getElementById('accountActionSub');
  var editBtn = document.getElementById('editProfileBtn');
  if (!icon || !text || !sub) return;
  if (window.loggedInCustomer) {
    icon.innerHTML = '<i class="fa fa-right-from-bracket"></i>';
    icon.style.background = '#fce4ec'; icon.style.color = '#c62828';
    text.textContent = 'Logout';
    sub.textContent = 'End your session';
    if (editBtn) editBtn.style.display = 'none';
  } else {
    icon.innerHTML = '<i class="fa fa-right-to-bracket"></i>';
    icon.style.background = '#e8f5e9'; icon.style.color = '#2e7d32';
    text.textContent = 'Login / Sign Up';
    sub.textContent = 'Track orders faster';
    if (editBtn) editBtn.style.display = '';
  }
}

/* ── Load Profile ── */
function loadProfileData() {
  updateAccountActionUI();
  var c = window.loggedInCustomer || loadCustomerFromStorage();
  var name = c ? (c.name || 'Guest') : 'Guest';
  var phone = c ? (c.phone || '') : '';
  var email = c ? (c.email || '') : '';
  var address = c ? (c.address || '') : '';

  // Avatar
  updateAvatar(name);

  // Hero
  document.getElementById('heroName').textContent = name;
  document.getElementById('heroPhone').textContent = phone || 'Add your phone number';

  // Details
  document.getElementById('profileName').textContent = name;
  document.getElementById('profilePhone').textContent = phone || '-';
  document.getElementById('profileEmail').textContent = email || '-';
  document.getElementById('profileAddress').textContent = address || '-';

  // Edit form
  document.getElementById('editName').value = name === 'Guest' ? '' : name;
  document.getElementById('editPhone').value = phone;
  document.getElementById('editEmail').value = email;
  document.getElementById('editAddress').value = address;

  var badge = document.querySelector('.section-badge');
  if (window.loggedInCustomer) {
    badge.textContent = ' Logged In';
    var icon = document.createElement('i');
    icon.className = 'fa fa-shield-halved';
    badge.prepend(icon);
    badge.style.background = '#e8f5e9';
    badge.style.color = '#2e7d32';
  } else if (!c || !phone) {
    badge.textContent = ' Incomplete';
    var icon = document.createElement('i');
    icon.className = 'fa fa-info-circle';
    badge.prepend(icon);
    badge.style.background = '#fef3c7';
    badge.style.color = '#92400e';
  } else {
    badge.textContent = ' Saved';
    var icon = document.createElement('i');
    icon.className = 'fa fa-check-circle';
    badge.prepend(icon);
    badge.style.background = '#fdf0ed';
    badge.style.color = '#e17055';
  }
}

/* ── Profile Edit ── */
function toggleProfileEdit() {
  var info = document.getElementById('profileInfo');
  var edit = document.getElementById('profileEdit');
  if (!info || !edit) return;
  info.classList.toggle('hidden');
  edit.classList.toggle('hidden');
  if (!edit.classList.contains('hidden')) {
    document.getElementById('editName')?.focus();
  }
}
function cancelProfileEdit() {
  var info = document.getElementById('profileInfo');
  var edit = document.getElementById('profileEdit');
  if (info && edit) {
    info.classList.remove('hidden');
    edit.classList.add('hidden');
  }
  loadProfileData();
}
function setupProfileForm() {
  var f = document.getElementById('profileForm');
  if (!f) return;
  f.addEventListener('submit', function(e) {
    e.preventDefault();
    var nameEl = document.getElementById('editName');
    var phoneEl = document.getElementById('editPhone');
    var emailEl = document.getElementById('editEmail');
    var addrEl = document.getElementById('editAddress');
    var name = nameEl.value.trim();
    var phone = phoneEl.value.trim();
    var email = emailEl.value.trim();
    var address = addrEl.value.trim();

    // Reset errors
    [nameEl, phoneEl].forEach(function(el) { el.classList.remove('error'); });

    if (!name) { nameEl.classList.add('error'); nameEl.focus(); showToast('Please enter your name', 'warning'); return; }
    if (!phone) { phoneEl.classList.add('error'); phoneEl.focus(); showToast('Please enter your phone number', 'warning'); return; }
    var phoneMinLen = window.restaurantPhoneMin || 10;
    var phoneMaxLen = window.restaurantPhoneMax || 10;
    if (phone.length < phoneMinLen || phone.length > phoneMaxLen) { phoneEl.classList.add('error'); phoneEl.focus(); showToast('Phone number must be ' + (phoneMinLen === phoneMaxLen ? phoneMinLen + ' digits' : phoneMinLen + '-' + phoneMaxLen + ' digits'), 'warning'); return; }

    var btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';
    setTimeout(function() {
      saveCustomerToStorage({ name: name, phone: phone, email: email, address: address });
      loadProfileData();
      cancelProfileEdit();
      showToast('Profile saved successfully! ✅', 'success');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa fa-check"></i> Save Profile';
    }, 300);
  });
}

/* ── Orders ── */
function getRestaurantId() {
  return document.querySelector('meta[name=restaurant-id]')?.content || 'RES001';
}

function showSkeletons(container, count) {
  if (!container) return;
  var html = '';
  for (var i = 0; i < count; i++) {
    html += '<div class="skeleton-card-el"><div class="skeleton skeleton-line skeleton-line-lg"></div><div class="skeleton skeleton-line skeleton-line-sm"></div><div class="skeleton skeleton-line" style="width:85%"></div><div class="skeleton skeleton-line" style="width:45%"></div></div>';
  }
  container.innerHTML = html;
}

function sortOrders(orders) {
  return orders.sort(function(a, b) {
    return new Date(b.created_at || 0) - new Date(a.created_at || 0);
  });
}

async function loadOrderHistory() {
  var el = document.getElementById('orderHistory');
  if (!el) return;
  var c = window.loggedInCustomer || loadCustomerFromStorage();
  if (!c || !c.phone) {
    el.innerHTML = '<div class="empty-state"><span class="empty-state-icon">📋</span><div class="empty-state-title">No Orders Yet</div><div class="empty-state-desc">Save your phone number in your profile to see your order history here.</div></div>';
    return;
  }
  showSkeletons(el, 3);
  try {
    var rid = getRestaurantId();
    var r = await fetch('api.php?action=getCustomerOrders&restaurant_id=' + encodeURIComponent(rid) + '&phone=' + encodeURIComponent(c.phone));
    var data = await r.json();
    allOrders = sortOrders(Array.isArray(data) ? data : (data.orders || []));
    updateStats(allOrders);
    renderOrders(allOrders, el);

    var hasCompletedOrder = allOrders.some(function(o) { return o.order_status === 'Completed'; });
    if (hasCompletedOrder) {
      checkGoogleReviewPrompt(rid, c.phone);
    }
  } catch(e) {
    el.innerHTML = '<div class="empty-state"><span class="empty-state-icon">⚠️</span><div class="empty-state-title">Could Not Load Orders</div><div class="empty-state-desc">Please check your connection and try again.</div></div>';
  }
}

/* ── "Rate us on Google" prompt ── */
function checkGoogleReviewPrompt(rid, phone) {
  fetch('google_review_actions.php?action=check&restaurant_id=' + encodeURIComponent(rid) + '&phone=' + encodeURIComponent(phone))
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success && data.show && data.review_link) {
        showGoogleReviewPrompt(data.review_link, rid, phone);
      }
    })
    .catch(function() {});
}

function showGoogleReviewPrompt(reviewLink, rid, phone) {
  var existing = document.getElementById('googleReviewModal');
  if (existing) existing.remove();

  var modal = document.createElement('div');
  modal.id = 'googleReviewModal';
  modal.className = 'modal-overlay';
  modal.innerHTML = '<div class="success-box" style="position:relative;">' +
    '<button onclick="respondGoogleReview(\'later\')" aria-label="Close" style="position:absolute;top:10px;right:12px;border:none;background:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>' +
    '<div style="font-size:36px;margin-bottom:8px;">⭐</div>' +
    '<h2>Enjoying your orders?</h2>' +
    '<p style="margin:8px 0 16px;color:#374151;font-size:14px;">Would you like to rate our restaurant on Google?</p>' +
    '<button class="btn-profile" style="margin-bottom:8px;" onclick="respondGoogleReview(\'rate\')">⭐ Rate Us</button>' +
    '<button class="btn-profile" style="background:#e5e7eb;color:#374151;" onclick="respondGoogleReview(\'never\')">Don\'t ask again</button>' +
  '</div>';
  document.body.appendChild(modal);

  window._googleReviewLink = reviewLink;
  window._googleReviewRid = rid;
  window._googleReviewPhone = phone;
}

function respondGoogleReview(choice) {
  var modal = document.getElementById('googleReviewModal');
  if (modal) modal.remove();

  var rid = window._googleReviewRid;
  var phone = window._googleReviewPhone;
  var reviewLink = window._googleReviewLink;
  if (!rid || !phone) return;

  if (choice === 'never') {
    fetch('google_review_actions.php?action=dismiss&restaurant_id=' + encodeURIComponent(rid) + '&phone=' + encodeURIComponent(phone), { method: 'POST' }).catch(function() {});
    return;
  }

  // Both 'rate' and 'later' (closed via ×) count as "asked at this visit
  // count" — only permanent opt-out ('never') is a stronger signal that
  // suppresses future prompts entirely.
  fetch('google_review_actions.php?action=mark_prompted&restaurant_id=' + encodeURIComponent(rid) + '&phone=' + encodeURIComponent(phone), { method: 'POST' }).catch(function() {});

  if (choice === 'rate' && reviewLink) {
    window.open(reviewLink, '_blank');
  }
}

function updateStats(orders) {
  var totalOrders = orders.length;
  var totalSpent = 0;
  var firstOrder = null;
  orders.forEach(function(o) {
    totalSpent += parseFloat(o.total || 0);
    var d = new Date(o.created_at);
    if (!firstOrder || d < firstOrder) firstOrder = d;
  });
  document.getElementById('statOrders').textContent = totalOrders;
  document.getElementById('statSpent').textContent = (window.globalCurrencySymbol || '\u20B9') + totalSpent.toFixed(0);
  if (firstOrder) {
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    document.getElementById('statMember').textContent = months[firstOrder.getMonth()] + ' ' + firstOrder.getFullYear();
  } else {
    document.getElementById('statMember').textContent = '-';
  }
}

function filterOrders(filter) {
  currentFilter = filter;
  // Update tabs
  document.querySelectorAll('.filter-tab').forEach(function(t) {
    t.classList.toggle('active', t.getAttribute('data-filter') === filter);
  });
  var el = document.getElementById('orderHistory');
  if (!el) return;
  renderOrders(allOrders, el);
}

function renderOrders(orders, el) {
  var filtered = orders;
  if (currentFilter !== 'all') {
    var statuses = currentFilter.split(',');
    filtered = orders.filter(function(o) {
      return statuses.includes(o.order_status);
    });
  }
  if (filtered.length === 0) {
    var msg = '';
    if (currentFilter === 'all') msg = 'No orders yet. Browse our menu and place your first order!';
    else if (currentFilter === 'Completed') msg = 'No completed orders yet.';
    else if (currentFilter === 'Pending') msg = 'No pending orders.';
    else if (currentFilter === 'Cancelled,Rejected') msg = 'No cancelled orders.';
    else if (currentFilter === 'Accepted,Preparing,Ready') msg = 'No orders in progress.';
    el.innerHTML = '<div class="empty-state"><span class="empty-state-icon">📦</span><div class="empty-state-title">' + msg.split('.')[0] + '</div><div class="empty-state-desc">' + (msg.split('.').slice(1).join('.') || '') + '</div></div>';
    return;
  }
  el.innerHTML = filtered.map(function(o) {
    return renderOrderCard(o);
  }).join('');
}

function statusIcon(status) {
  var map = {
    'Completed': '✅',
    'Accepted': '👨‍🍳',
    'Preparing': '👨‍🍳',
    'Ready': '🍽️',
    'Pending': '⏳',
    'Rejected': '❌',
    'Cancelled': '🚫'
  };
  return map[status] || '⏳';
}

function formatDate(dateStr) {
  try {
    // No explicit timeZone here: the server sends a naive local timestamp
    // (already in the restaurant's own configured timezone), and the browser
    // parses/renders naive date strings in its own local zone by default.
    // Forcing timeZone: 'Asia/Kolkata' here used to shift every non-Indian
    // customer's order time (e.g. Nepal, UTC+5:45) by the gap to IST.
    var d = new Date(dateStr);
    return d.toLocaleDateString('en-IN', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
  } catch(e) { return dateStr; }
}

function getItemImageUrl(src) {
  if (!src || src === 'placeholder') return '';
  if (src.indexOf('http') === 0 || src.indexOf('data:') === 0) return src;
  return '../api/image.php?type=file&path=' + encodeURIComponent('../' + src);
}

function vegLabel(t) {
  if (t === 'veg') return '<span class="veg-indicator veg"></span>';
  if (t === 'egg') return '<span class="veg-indicator egg"></span>';
  if (t === 'non-veg') return '<span class="veg-indicator nonveg"></span>';
  return '';
}

function renderOrderCard(o) {
  var date = formatDate(o.created_at);
  var status = o.order_status || 'Pending';
  var statusClass = status.toLowerCase();
  var items = o.items || [];
  var hasFeedback = o.has_feedback || false;
  var orderNum = o.order_number || o.id;
  var isCompleted = status === 'Completed';
  var isRatable = isCompleted && !hasFeedback;

  var html = '<div class="order-card">';
  // ── Top: Order ID + Status ──
  html += '<div class="order-card-top">';
  html += '<div><div class="order-card-id">Order #' + escapeHtml(orderNum) + '</div>';
  html += '<div class="order-card-date">' + date + '</div></div>';
  html += '<span class="order-card-status ' + statusClass + '">' + status + '</span>';
  html += '</div>';

  // ── Items ──
  if (items.length > 0) {
    html += '<div class="order-card-items">';
    items.forEach(function(item) {
      var imgSrc = getItemImageUrl(item.item_image);
      var thumb = imgSrc
        ? '<img class="order-item-thumb" src="' + imgSrc + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">'
        : '';
      html += '<div class="order-card-item">';
      if (imgSrc) {
        html += thumb;
      }
      html += '<div class="order-item-info">';
      html += vegLabel(item.item_type);
      html += '<span class="order-item-name">' + escapeHtml(item.item_name || 'Item') + '</span>';
      html += '</div>';
      html += '<span class="order-item-qty">× ' + item.quantity + '</span>';
      html += '<span class="order-item-total">' + (window.globalCurrencySymbol || '\u20B9') + parseFloat(item.total_price || 0).toFixed(2) + '</span>';
      html += '</div>';
    });
    html += '</div>';
  }

  // ── Notes ──
  if (o.notes) {
    html += '<div class="order-card-note"><span>📝</span> ' + escapeHtml(o.notes) + '</div>';
  }

  // ── Divider ──
  html += '<div class="order-card-divider"></div>';

  // ── Bottom: Total ──
  html += '<div class="order-card-bottom">';
  html += '<div><div class="order-card-total-label">Total</div><div class="order-card-total">' + (window.globalCurrencySymbol || '\u20B9') + parseFloat(o.total || 0).toFixed(2) + '</div></div>';
  var itemLabel = items.length + ' item' + (items.length > 1 ? 's' : '');
  html += '<div style="font-size:11px;color:#999">' + itemLabel + '</div>';
  html += '</div>';

  // ── Action buttons ──
  var isActiveOrder = ['Completed', 'Cancelled', 'Rejected'].indexOf(status) === -1;
  html += '<div class="order-card-actions">';
  if (isActiveOrder) {
    var trackCustomer = window.loggedInCustomer || loadCustomerFromStorage();
    var trackPhone = trackCustomer ? (trackCustomer.phone || '') : '';
    if (trackPhone) {
      html += '<button class="action-btn reorder" onclick="window.location.href=\'track.php?order_number=' + encodeURIComponent(orderNum) + '&customer_phone=' + encodeURIComponent(trackPhone) + '\'">📍 Track Order</button>';
    }
  }
  if (isRatable) {
    html += '<button class="action-btn rate" onclick="openFeedback(\'' + escapeHtml(orderNum) + '\')">★ Share your feedback</button>';
    html += '<button class="action-btn reorder" onclick="reorderOrder(\'' + escapeHtml(orderNum) + '\')">🔄 Reorder</button>';
  } else if (isCompleted && hasFeedback) {
    html += '<button class="action-btn rated-stars">★ Rated</button>';
    html += '<button class="action-btn reorder" onclick="reorderOrder(\'' + escapeHtml(orderNum) + '\')">🔄 Reorder</button>';
  } else {
    html += '<button class="action-btn reorder" onclick="reorderOrder(\'' + escapeHtml(orderNum) + '\')">🔄 Reorder</button>';
  }
  html += '</div>';

  html += '</div>';
  return html;
}

function reorderOrder(orderNumber) {
  var o = allOrders.find(function(x) { return (x.order_number || x.id) == orderNumber; });
  if (!o || !o.items) return;
  var cart = JSON.parse(localStorage.getItem('dvaniCart')) || {};
  o.items.forEach(function(item) {
    var itemId = item.menu_item_id;
    var qty = parseInt(item.quantity, 10) || 1;
    if (cart[itemId] != null) {
      if (typeof cart[itemId] === 'number') {
        cart[itemId] += qty;
      } else if (typeof cart[itemId] === 'object' && !cart[itemId].isAddon) {
        cart[itemId].qty = (cart[itemId].qty || 0) + qty;
      } else {
        cart[itemId] = qty;
      }
    } else {
      cart[itemId] = qty;
    }
  });
  localStorage.setItem('dvaniCart', JSON.stringify(cart));
  // Trigger cart update event for other tabs
  localStorage.setItem('cartUpdated', Date.now());
  showToast('Items added to cart! Redirecting...', 'success');
  setTimeout(function() { window.location.href = '<?php echo restaurantPageUrl('menu'); ?>'; }, 800);
}

/* ── Feedback ── */
function openFeedback(orderNumber) {
  selectedRating = 0;
  var container = document.getElementById('feedbackModalContainer');
  container.innerHTML =
    '<div class="feedback-modal-overlay" id="feedbackOverlay" onclick="if(event.target===this)closeFeedback()">' +
    '<div class="feedback-modal">' +
    '<button class="feedback-close" onclick="closeFeedback()">&times;</button>' +
    '<div class="feedback-title">Rate Your Experience</div>' +
    '<div class="feedback-subtitle">Order #' + escapeHtml(orderNumber) + '</div>' +
    '<input type="hidden" id="feedbackOrderNumber" value="' + escapeHtml(orderNumber) + '">' +
    '<div class="star-rating-container">' +
    '<div class="star-rating" id="starRating">' +
    '<span data-star="1" onclick="setRating(1)" onmouseenter="hoverRating(1)" onmouseleave="resetHoverRating()">★</span>' +
    '<span data-star="2" onclick="setRating(2)" onmouseenter="hoverRating(2)" onmouseleave="resetHoverRating()">★</span>' +
    '<span data-star="3" onclick="setRating(3)" onmouseenter="hoverRating(3)" onmouseleave="resetHoverRating()">★</span>' +
    '<span data-star="4" onclick="setRating(4)" onmouseenter="hoverRating(4)" onmouseleave="resetHoverRating()">★</span>' +
    '<span data-star="5" onclick="setRating(5)" onmouseenter="hoverRating(5)" onmouseleave="resetHoverRating()">★</span>' +
    '</div>' +
    '<div class="rating-label" id="ratingLabel">Tap a star to rate</div>' +
    '</div>' +
    '<textarea class="feedback-textarea" id="feedbackReview" placeholder="Share your experience (optional)" maxlength="500"></textarea>' +
    '<div class="feedback-error" id="feedbackError"></div>' +
    '<button class="submit-feedback-btn" id="feedbackSubmitBtn" onclick="submitFeedback()"><i class="fa fa-star"></i> Submit Review</button>' +
    '</div></div>';
}

function closeFeedback() {
  var container = document.getElementById('feedbackModalContainer');
  container.innerHTML = '';
}

function setRating(r) {
  selectedRating = r;
  var stars = document.querySelectorAll('#starRating span');
  stars.forEach(function(s) {
    var star = parseInt(s.getAttribute('data-star'), 10);
    s.classList.toggle('active', star <= r);
  });
  var labels = ['', 'Poor', 'Fair', 'Good', 'Very Good', 'Excellent'];
  document.getElementById('ratingLabel').textContent = labels[r] || '';
  document.getElementById('ratingLabel').classList.toggle('highlight', r > 0);
}

function hoverRating(r) {
  var stars = document.querySelectorAll('#starRating span');
  stars.forEach(function(s) {
    var star = parseInt(s.getAttribute('data-star'), 10);
    s.style.color = star <= r ? '#f59e0b' : '#d1d5db';
  });
}

function resetHoverRating() {
  var stars = document.querySelectorAll('#starRating span');
  stars.forEach(function(s) {
    var star = parseInt(s.getAttribute('data-star'), 10);
    s.style.color = star <= selectedRating ? '#f59e0b' : '#d1d5db';
  });
}

function submitFeedback() {
  if (selectedRating === 0) {
    document.getElementById('feedbackError').textContent = 'Please select a rating ⭐';
    document.getElementById('feedbackError').style.display = 'block';
    return;
  }
  var btn = document.getElementById('feedbackSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting...';
  document.getElementById('feedbackError').style.display = 'none';

  var c = window.loggedInCustomer || loadCustomerFromStorage();
  var formData = new FormData();
  formData.append('order_number', document.getElementById('feedbackOrderNumber').value);
  formData.append('customer_phone', c ? c.phone : '');
  formData.append('customer_name', c ? c.name : '');
  formData.append('rating', selectedRating);
  formData.append('review', document.getElementById('feedbackReview').value);

  fetch('../api/submit_feedback.php', { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      var modal = document.querySelector('.feedback-modal');
      if (res.success) {
        modal.innerHTML =
          '<div class="feedback-success">' +
          '<span class="feedback-success-icon">🎉</span>' +
          '<div class="feedback-success-title">Thank You!</div>' +
          '<div class="feedback-success-msg">' + (res.message || 'Your feedback helps us serve you better!') + '</div>' +
          '<button class="submit-feedback-btn" style="margin-top:20px" onclick="closeFeedback();window.loadOrderHistory();"><i class="fa fa-check"></i> Done</button>' +
          '</div>';
      } else {
        document.getElementById('feedbackError').textContent = res.message || 'Failed to submit review';
        document.getElementById('feedbackError').style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-star"></i> Try Again';
      }
    })
    .catch(function() {
      document.getElementById('feedbackError').textContent = 'Network error. Please try again.';
      document.getElementById('feedbackError').style.display = 'block';
      btn.disabled = false;
      btn.innerHTML = '<i class="fa fa-star"></i> Try Again';
    });
}

/* ── Utilities ── */
function escapeHtml(str) {
  if (!str) return '';
  var d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

function showToast(msg, type) {
  type = type || 'success';
  var existing = document.querySelector('.toast-notification');
  if (existing) existing.remove();
  var icons = { success: '✅', error: '❌', info: 'ℹ️', warning: '⚠️' };
  var toast = document.createElement('div');
  toast.className = 'toast-notification ' + type;
  toast.innerHTML = '<span class="toast-icon">' + (icons[type] || icons.success) + '</span>' + msg;
  document.body.appendChild(toast);
  requestAnimationFrame(function() { setTimeout(function() { toast.classList.add('show'); }, 10); });
  setTimeout(function() {
    toast.classList.remove('show');
    setTimeout(function() { toast.remove(); }, 350);
  }, 2800);
}

/* ── PWA Install ── */
let deferredPrompt = null;
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    try {
      navigator.serviceWorker.register('<?php echo $website_base_href ?>sw.php', { scope: '<?php echo $site_root ?>' }).then(function(reg) {
        console.log('SW registered:', reg.scope);
      }).catch(function() {});
    } catch(e) {}
  });
}
window.addEventListener('beforeinstallprompt', function(e) {
  e.preventDefault();
  deferredPrompt = e;
});
window.addEventListener('appinstalled', function() {
  deferredPrompt = null;
});

/* ── Init ── */
document.addEventListener('DOMContentLoaded', function() {
  // Gate: if not logged in and this browser session hasn't already chosen
  // "Continue as Guest", show the Login / Sign Up / Guest screen first
  // instead of jumping straight to the (possibly stale) guest details.
  if (!window.loggedInCustomer) {
    var guestSessionActive = false;
    try { guestSessionActive = sessionStorage.getItem('guestSessionActive') === '1'; } catch(e) {}
    if (!guestSessionActive) {
      window.location.href = window.loginUrl;
      return;
    }
  }
  loadProfileData();
  loadOrderHistory();
  setupProfileForm();
  var editBtn = document.getElementById('editProfileBtn');
  if (editBtn) editBtn.addEventListener('click', toggleProfileEdit);
  if (window.location.hash === '#edit') toggleProfileEdit();
  // Auto-show edit form for first-time guests with no saved data
  // (skipped for logged-in accounts - their details already come from the account)
  if (!window.loggedInCustomer) {
    var c = loadCustomerFromStorage();
    if (!c || !c.phone) {
      setTimeout(toggleProfileEdit, 500);
    }
  }
});
</script>
<div style="height:80px"></div>
<?php require_once __DIR__ . '/bottom_nav.php'; ?>
</body>
</html>
