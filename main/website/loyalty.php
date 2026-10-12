<?php
require_once __DIR__ . '/header.php';
$loyaltyLoginBase = restaurantPageUrl('login');
$loyaltyLoginUrl = $loyaltyLoginBase . (strpos($loyaltyLoginBase, '?') !== false ? '&' : '?') . 'redirect=loyalty';
$loyaltyFromQr = isset($_GET['src']) && $_GET['src'] === 'qr';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<base href="<?php echo $website_base_href; ?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="restaurant-id" content="<?php echo htmlspecialchars($restaurant_id ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<meta name="theme-color" content="#1c1230">
<?php include __DIR__ . '/cart_scope.php'; ?>
<title>Rewards - <?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="description" content="Earn points every time you order at <?php echo htmlspecialchars($restaurant_name ?? 'our restaurant', ENT_QUOTES, 'UTF-8'); ?> and redeem them for discounts and free dishes.">
<link rel="icon" href="<?php echo htmlspecialchars($favicon_href ?? $local_favicon_svg, ENT_QUOTES, 'UTF-8'); ?>">
<?php require_once __DIR__ . '/font_links.php'; echo websiteFontLinks(['poppins', 'fontawesome']); ?>
<style>
:root {
  --brand: <?php echo htmlspecialchars($primary_red ?? '#F70000', ENT_QUOTES, 'UTF-8'); ?>;
  --brand-dark: <?php echo htmlspecialchars($dark_red ?? '#DA020E', ENT_QUOTES, 'UTF-8'); ?>;
  --gold: #f5c451; --gold-deep: #d99a1e;
  --ink: #15111f; --muted: #6b7280; --line: #eef0f3; --bg: #f6f5fa;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: #e9e7f1; color: var(--ink); min-height: 100vh; overflow-x: hidden; -webkit-font-smoothing: antialiased; }
.phone-frame { max-width: 425px; margin: 0 auto; min-height: 100vh; background: var(--bg); position: relative; box-shadow: 0 0 40px rgba(0,0,0,0.08); overflow: hidden; }
@media (min-width: 768px) { .phone-frame { margin: 20px auto; min-height: calc(100vh - 40px); border-radius: 28px; } <?php if ($host === 'triposhsymmetry.in'): ?>.phone-frame { max-width: 100%; margin: 0; border-radius: 0; }<?php endif; ?> }
button { font-family: inherit; }
.hidden { display: none !important; }

/* ── Hero ── */
.lx-hero { position: relative; padding: 14px 18px 26px; color: #fff; overflow: hidden;
  background: radial-gradient(120% 90% at 85% 0%, #4c2a85 0%, rgba(76,42,133,0) 60%), radial-gradient(90% 70% at 0% 100%, #7a2048 0%, rgba(122,32,72,0) 60%), #1c1230; }
.lx-hero::after { content: ""; position: absolute; inset: 0; background-image: radial-gradient(rgba(255,255,255,.07) 1px, transparent 1px); background-size: 18px 18px; pointer-events: none; }
.lx-top { position: relative; z-index: 2; display: flex; align-items: center; gap: 10px; }
.lx-round { width: 38px; height: 38px; flex-shrink: 0; display: grid; place-items: center; border: 1px solid rgba(255,255,255,.18); border-radius: 12px; background: rgba(255,255,255,.08); color: #fff; font-size: 15px; cursor: pointer; text-decoration: none; }
.lx-brand { flex: 1; min-width: 0; display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; opacity: .9; }
.lx-brand img { width: 26px; height: 26px; border-radius: 50%; object-fit: cover; background: #fff; }
.lx-brand span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lx-coins { position: absolute; inset: 0; pointer-events: none; z-index: 1; }
.lx-coin { position: absolute; width: 22px; height: 22px; border-radius: 50%; background: radial-gradient(circle at 35% 30%, #fff3c4, var(--gold) 45%, var(--gold-deep)); box-shadow: inset 0 -2px 0 rgba(0,0,0,.15), 0 4px 10px rgba(0,0,0,.25); opacity: .9; animation: lxFloat 6s ease-in-out infinite; }
.lx-coin::after { content: ""; position: absolute; inset: 5px; border-radius: 50%; border: 1.5px solid rgba(120,70,0,.35); }
@keyframes lxFloat { 0%, 100% { transform: translateY(0) rotate(0); } 50% { transform: translateY(-12px) rotate(14deg); } }
.lx-kicker { position: relative; z-index: 2; display: inline-flex; align-items: center; gap: 6px; margin-top: 22px; padding: 5px 11px; border-radius: 999px; background: rgba(245,196,81,.14); border: 1px solid rgba(245,196,81,.35); color: var(--gold); font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.lx-title { position: relative; z-index: 2; margin-top: 12px; font-size: 30px; line-height: 1.12; font-weight: 800; letter-spacing: -.02em; }
.lx-title em { font-style: normal; background: linear-gradient(90deg, var(--gold), #ffe6a3); -webkit-background-clip: text; background-clip: text; color: transparent; }
.lx-sub { position: relative; z-index: 2; margin-top: 10px; font-size: 14px; line-height: 1.55; color: rgba(255,255,255,.78); }
.lx-ctas { position: relative; z-index: 2; display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }
.lx-btn { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 15px 18px; border: 0; border-radius: 16px; font-size: 15px; font-weight: 800; text-decoration: none; cursor: pointer; transition: transform .15s; }
.lx-btn:active { transform: scale(.97); }
.lx-btn.gold { background: linear-gradient(135deg, #ffe08a, var(--gold) 50%, var(--gold-deep)); color: #3b2604; box-shadow: 0 12px 24px -10px rgba(245,196,81,.7); }
.lx-btn.ghost { background: rgba(255,255,255,.08); color: #fff; border: 1px solid rgba(255,255,255,.2); }
.lx-btn.brand { background: var(--brand); color: #fff; box-shadow: 0 12px 24px -12px var(--brand); }
.lx-btn.light { background: #fff; color: var(--ink); border: 1.5px solid var(--line); }

/* member card */
.lx-card { position: relative; z-index: 2; margin-top: 18px; padding: 18px; border-radius: 22px; overflow: hidden;
  background: linear-gradient(135deg, rgba(255,255,255,.16), rgba(255,255,255,.04)); border: 1px solid rgba(255,255,255,.18); -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); }
.lx-card-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.lx-hello { font-size: 13px; color: rgba(255,255,255,.75); }
.lx-hello b { display: block; font-size: 17px; color: #fff; }
.lx-tier { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; background: linear-gradient(135deg, #ffe08a, var(--gold-deep)); color: #3b2604; font-size: 12px; font-weight: 800; white-space: nowrap; }
.lx-tier.none { background: rgba(255,255,255,.12); color: #fff; }
.lx-pts { margin-top: 16px; display: flex; align-items: flex-end; gap: 8px; }
.lx-pts b { font-size: 44px; line-height: 1; font-weight: 800; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
.lx-pts span { padding-bottom: 6px; font-size: 13px; color: rgba(255,255,255,.7); }
.lx-worth { margin-top: 4px; font-size: 13px; color: var(--gold); font-weight: 600; }
.lx-prog { margin-top: 14px; }
.lx-prog-bar { height: 8px; border-radius: 999px; background: rgba(255,255,255,.14); overflow: hidden; }
.lx-prog-bar i { display: block; height: 100%; width: 0; border-radius: 999px; background: linear-gradient(90deg, var(--gold-deep), var(--gold), #fff1bf); transition: width 1.2s cubic-bezier(.2,.8,.2,1); }
.lx-prog p { margin-top: 7px; font-size: 12px; color: rgba(255,255,255,.75); }
.lx-prog p b { color: #fff; }
.lx-mini { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 14px; }
.lx-mini div { padding: 9px 6px; border-radius: 12px; background: rgba(255,255,255,.07); text-align: center; }
.lx-mini b { display: block; font-size: 15px; }
.lx-mini span { font-size: 10.5px; color: rgba(255,255,255,.6); }

/* ── Body ── */
.lx-body { position: relative; z-index: 3; margin-top: -12px; padding: 0 14px 120px; }
.lx-facts { display: flex; gap: 8px; overflow-x: auto; padding: 0 2px 4px; scrollbar-width: none; }
.lx-facts::-webkit-scrollbar { display: none; }
.lx-fact { flex: 0 0 auto; display: flex; align-items: center; gap: 8px; padding: 10px 14px 10px 10px; border-radius: 14px; background: #fff; box-shadow: 0 6px 18px -8px rgba(28,18,48,.25); font-size: 12px; color: var(--muted); }
.lx-fact i { width: 30px; height: 30px; display: grid; place-items: center; border-radius: 10px; background: #f3efff; color: #6d28d9; font-size: 13px; }
.lx-fact b { display: block; font-size: 13px; color: var(--ink); }
.lx-sec { margin-top: 16px; padding: 18px 16px; border-radius: 22px; background: #fff; box-shadow: 0 4px 16px -10px rgba(28,18,48,.2); opacity: 0; transform: translateY(14px); transition: opacity .5s ease, transform .5s ease; }
.lx-sec.in { opacity: 1; transform: none; }
.lx-h { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 14px; }
.lx-h h2 { font-size: 17px; font-weight: 800; letter-spacing: -.01em; }
.lx-h small { font-size: 12px; color: var(--muted); }

/* steps */
.lx-steps { display: grid; gap: 12px; }
.lx-step { display: flex; align-items: flex-start; gap: 12px; }
.lx-step i { position: relative; width: 44px; height: 44px; flex-shrink: 0; display: grid; place-items: center; border-radius: 14px; font-size: 17px; color: #fff; }
.lx-step:nth-child(1) i { background: linear-gradient(135deg, #f97316, var(--brand)); }
.lx-step:nth-child(2) i { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
.lx-step:nth-child(3) i { background: linear-gradient(135deg, #10b981, #047857); }
.lx-step i::after { content: attr(data-n); position: absolute; top: -6px; right: -6px; width: 19px; height: 19px; border-radius: 50%; background: var(--ink); color: #fff; font: 700 10px/19px 'Poppins', sans-serif; text-align: center; font-style: normal; }
.lx-step b { display: block; font-size: 14px; }
.lx-step span { font-size: 12.5px; color: var(--muted); line-height: 1.45; }

/* rewards */
.lx-rewards { display: flex; gap: 12px; overflow-x: auto; scroll-snap-type: x mandatory; margin: 0 -16px; padding: 2px 16px 6px; scrollbar-width: none; }
.lx-rewards::-webkit-scrollbar { display: none; }
.lx-rw { flex: 0 0 158px; scroll-snap-align: start; border-radius: 18px; overflow: hidden; border: 1px solid var(--line); background: #fff; display: flex; flex-direction: column; }
.lx-rw-img { position: relative; height: 104px; background: #f3f4f6 center/cover no-repeat; }
.lx-rw-img .cost { position: absolute; left: 8px; bottom: 8px; display: inline-flex; align-items: center; gap: 5px; padding: 4px 9px; border-radius: 999px; background: rgba(28,18,48,.85); color: var(--gold); font-size: 11.5px; font-weight: 800; }
.lx-rw-img .cost::before { content: ""; width: 10px; height: 10px; border-radius: 50%; background: radial-gradient(circle at 35% 30%, #fff3c4, var(--gold) 45%, var(--gold-deep)); }
.lx-rw-b { padding: 10px; display: flex; flex-direction: column; gap: 6px; flex: 1; }
.lx-rw-b b { font-size: 13.5px; line-height: 1.25; }
.lx-rw-b small { font-size: 11px; color: var(--muted); }
.lx-rw-bar { height: 6px; border-radius: 999px; background: #f1f1f4; overflow: hidden; }
.lx-rw-bar i { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #a78bfa, #6d28d9); }
.lx-rw button { margin-top: auto; padding: 9px; border: 0; border-radius: 11px; background: var(--ink); color: #fff; font-size: 12.5px; font-weight: 700; cursor: pointer; }
.lx-rw button:disabled { background: #f1f1f4; color: #9ca3af; cursor: default; }

/* tiers */
.lx-tiers { position: relative; display: grid; gap: 10px; }
.lx-tiers::before { content: ""; position: absolute; left: 21px; top: 22px; bottom: 22px; width: 2px; background: linear-gradient(#e9d5ff, #fde68a); }
.lx-tr { position: relative; display: flex; align-items: center; gap: 12px; padding: 10px 12px 10px 0; border-radius: 16px; }
.lx-tr i { position: relative; z-index: 1; width: 44px; height: 44px; flex-shrink: 0; display: grid; place-items: center; border-radius: 50%; background: #fff; border: 2px solid #e9d5ff; color: #7c3aed; font-size: 16px; }
.lx-tr.reached i { background: linear-gradient(135deg, #ffe08a, var(--gold-deep)); border-color: transparent; color: #3b2604; }
.lx-tr.here { background: #faf7ff; box-shadow: inset 0 0 0 1.5px #ddd6fe; padding-left: 0; }
.lx-tr div { flex: 1; min-width: 0; }
.lx-tr b { display: block; font-size: 14.5px; }
.lx-tr span { font-size: 12px; color: var(--muted); }
.lx-tr em { font-style: normal; flex-shrink: 0; padding: 4px 10px; border-radius: 999px; background: #f3efff; color: #6d28d9; font-size: 11.5px; font-weight: 800; }
.lx-tr .you { position: absolute; right: 10px; top: -8px; padding: 2px 8px; border-radius: 999px; background: var(--ink); color: #fff; font-size: 10px; font-weight: 700; }

/* referral */
.lx-ref { position: relative; overflow: hidden; border-radius: 18px; padding: 16px; background: linear-gradient(135deg, #fff7ed, #fdf2f8); border: 1px solid #fde4cf; }
.lx-ref-gift { display: flex; gap: 10px; }
.lx-ref-gift div { flex: 1; padding: 10px; border-radius: 14px; background: #fff; text-align: center; }
.lx-ref-gift b { display: block; font-size: 20px; color: var(--brand-dark); }
.lx-ref-gift span { font-size: 11.5px; color: var(--muted); }
.lx-code { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 12px; padding: 10px 10px 10px 16px; border-radius: 14px; border: 2px dashed #fdba74; background: #fff; }
.lx-code b { font-size: 20px; letter-spacing: .14em; color: var(--ink); }
.lx-code button { padding: 8px 12px; border: 0; border-radius: 10px; background: var(--ink); color: #fff; font-size: 12px; font-weight: 700; cursor: pointer; }
.lx-ref-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
.lx-ref-actions .lx-btn { padding: 12px; font-size: 13.5px; border-radius: 13px; }
.lx-wa { background: #16a34a; color: #fff; }
.lx-ref-qr { display: flex; align-items: center; gap: 12px; margin-top: 12px; padding: 10px; border-radius: 14px; background: #fff; }
.lx-ref-qr img { width: 76px; height: 76px; border-radius: 8px; }
.lx-ref-qr span { font-size: 12px; color: var(--muted); line-height: 1.45; }

/* redeem */
.lx-redeem-q { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.lx-redeem-q button { padding: 11px 6px; border: 1.5px solid var(--line); border-radius: 13px; background: #fff; font-size: 14px; font-weight: 800; cursor: pointer; }
.lx-redeem-q button small { display: block; font-size: 11px; font-weight: 600; color: #16a34a; }
.lx-redeem-q button.on { border-color: #6d28d9; background: #faf7ff; box-shadow: 0 0 0 3px rgba(109,40,217,.12); }
.lx-redeem-q button:disabled { opacity: .45; cursor: default; }
.lx-note { margin-top: 10px; font-size: 12px; color: var(--muted); line-height: 1.5; }
.lx-codebox { margin-top: 12px; padding: 14px; border-radius: 16px; background: #1c1230; color: #fff; text-align: center; animation: lxPop .5s cubic-bezier(.2,1.6,.4,1); }
.lx-codebox small { font-size: 11.5px; color: rgba(255,255,255,.7); }
.lx-codebox b { display: block; margin-top: 4px; font-size: 26px; letter-spacing: .22em; color: var(--gold); }
@keyframes lxPop { from { transform: scale(.9); opacity: 0; } to { transform: none; opacity: 1; } }
.lx-pending { display: grid; gap: 8px; }
.lx-pending div { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 12px; border-radius: 14px; background: #faf7ff; border: 1px dashed #c4b5fd; }
.lx-pending b { font-size: 16px; letter-spacing: .16em; }
.lx-pending span { font-size: 12px; color: var(--muted); }

/* activity */
.lx-toggle { width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 0; border: 0; background: none; cursor: pointer; }
.lx-act { margin-top: 10px; }
.lx-act div { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--line); }
.lx-act div:last-child { border-bottom: 0; }
.lx-act i { width: 36px; height: 36px; flex-shrink: 0; display: grid; place-items: center; border-radius: 12px; background: #f0fdf4; color: #16a34a; font-size: 14px; }
.lx-act i.out { background: #fef2f2; color: #dc2626; }
.lx-act p { flex: 1; min-width: 0; font-size: 13.5px; font-weight: 600; }
.lx-act p small { display: block; font-size: 11.5px; font-weight: 400; color: #9ca3af; }
.lx-act strong { font-size: 14px; color: #16a34a; }
.lx-act strong.out { color: #dc2626; }
.lx-fine { margin-top: 18px; text-align: center; font-size: 11.5px; color: #9ca3af; line-height: 1.6; }

/* states */
.lx-skel { margin: 18px 14px; height: 140px; border-radius: 22px; background: linear-gradient(90deg, #ececf2 25%, #f6f6fa 50%, #ececf2 75%); background-size: 200% 100%; animation: lxShim 1.4s infinite; }
@keyframes lxShim { from { background-position: 200% 0; } to { background-position: -200% 0; } }
.lx-off { margin: 18px 14px 120px; padding: 30px 20px; border-radius: 22px; background: #fff; text-align: center; }
.lx-off i { font-size: 34px; color: #c4b5fd; }
.lx-off b { display: block; margin-top: 12px; font-size: 17px; }
.lx-off p { margin: 6px 0 16px; font-size: 13px; color: var(--muted); }

/* sticky action */
.lx-sticky { position: fixed; left: 50%; bottom: 74px; z-index: 90; width: calc(100% - 28px); max-width: 397px; transform: translate(-50%, 140%); transition: transform .4s cubic-bezier(.2,.8,.2,1); }
.lx-sticky.show { transform: translate(-50%, 0); }
.lx-sticky .lx-btn { box-shadow: 0 16px 30px -10px rgba(28,18,48,.55); }

.lx-toast { position: fixed; left: 50%; bottom: 140px; z-index: 9999; transform: translate(-50%, 20px); opacity: 0; padding: 12px 18px; border-radius: 14px; background: var(--ink); color: #fff; font-size: 13px; font-weight: 600; transition: all .3s; max-width: 90%; text-align: center; }
.lx-toast.show { opacity: 1; transform: translate(-50%, 0); }
.lx-toast.error { background: #b91c1c; }

@media (prefers-reduced-motion: reduce) { .lx-coin, .lx-codebox { animation: none; } .lx-sec { opacity: 1; transform: none; transition: none; } .lx-prog-bar i { transition: none; } }

/* bottom nav (shared include) */
.bottom-nav { position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); width: 100%; max-width: <?php echo ($host === 'triposhsymmetry.in') ? '100%' : '425px'; ?>; background: #fff; border-top: 1px solid #e8e8e8; display: flex; align-items: center; justify-content: space-between; padding: 8px 12px 10px; z-index: 100; box-shadow: 0 -2px 10px rgba(0,0,0,0.05); }
.nav-item { display: flex; flex-direction: column; align-items: center; gap: 2px; cursor: pointer; position: relative; border: none; background: none; font-family: 'Poppins', sans-serif; }
.nav-item img { width: 30px; height: 30px; }
.nav-item span { font-size: 11px; color: #555; }
.nav-item.active span { color: #2d3436; font-weight: 600; }
.nav-icon { font-size: 24px; color: #888; display: block; margin: 0 auto; }
.nav-item.active .nav-icon { color: #2d3436; }
.cart-badge { position: absolute; top: -5px; right: 50%; transform: translateX(17px); background: #ff4444; color: #fff; font-size: 10px; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; }
.login-btn { background: linear-gradient(135deg, #e17055, #d63031); color: #fff; border: none; border-radius: 8px; padding: 6px 14px; font-size: 11px; font-family: 'Poppins', sans-serif; cursor: pointer; font-weight: 500; }
</style>
</head>
<body>
<div class="phone-frame">
  <header class="lx-hero" id="lxHero">
    <div class="lx-coins" aria-hidden="true">
      <span class="lx-coin" style="right:22px;top:250px;animation-delay:-1s"></span>
      <span class="lx-coin" style="right:64px;top:300px;width:14px;height:14px;animation-delay:-3s"></span>
      <span class="lx-coin" style="left:18px;bottom:30px;width:16px;height:16px;animation-delay:-4.5s"></span>
    </div>
    <div class="lx-top">
      <a class="lx-round" href="<?php echo htmlspecialchars(restaurantPageUrl(), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Home"><i class="fa fa-arrow-left"></i></a>
      <div class="lx-brand">
        <?php if (!empty($restaurant_logo)): ?><img src="<?php echo htmlspecialchars($restaurant_logo, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php endif; ?>
        <span><?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <a class="lx-round" href="<?php echo htmlspecialchars(restaurantPageUrl('menu'), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Menu"><i class="fa fa-utensils"></i></a>
    </div>
    <div id="lxHeroBody"></div>
  </header>

  <div id="lxSkel"><div class="lx-skel"></div><div class="lx-skel" style="height:200px"></div></div>
  <main class="lx-body hidden" id="lxBody"></main>
  <div class="lx-off hidden" id="lxOff">
    <i class="fa fa-gift"></i>
    <b>Rewards are coming soon</b>
    <p><?php echo htmlspecialchars($restaurant_name ?? 'This restaurant', ENT_QUOTES, 'UTF-8'); ?> hasn't started its rewards program yet.</p>
    <a class="lx-btn brand" href="<?php echo htmlspecialchars(restaurantPageUrl('menu'), ENT_QUOTES, 'UTF-8'); ?>">See the menu</a>
  </div>
  <div class="lx-sticky" id="lxSticky"></div>
</div>

<script>
window.restaurantId = <?php echo json_encode($restaurant_id ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.currencySymbol = <?php echo json_encode($currency_symbol ?? '₹', JSON_HEX_TAG); ?>;
window.restaurantName = <?php echo json_encode($restaurant_name ?? '', JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.lxLoginUrl = <?php echo json_encode($loyaltyLoginUrl, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.lxMenuUrl = <?php echo json_encode(restaurantPageUrl('menu'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.lxFromQr = <?php echo $loyaltyFromQr ? 'true' : 'false'; ?>;

(function () {
  'use strict';
  var CUR = window.currencySymbol, P = null, M = null, pickPts = 0, showAct = false;

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(n) { n = Math.round(n * 100) / 100; return CUR + (n % 1 ? n.toFixed(2) : n.toLocaleString('en-IN')); }
  function num(n) { return Math.round(n).toLocaleString('en-IN'); }
  function img(src) {
    if (!src) return '';
    if (/^https?:\/\//.test(src)) return src;
    return 'image.php?id=' + encodeURIComponent(src.indexOf('db:') === 0 ? src.substring(3) : src);
  }
  function toast(msg, type) {
    var t = document.querySelector('.lx-toast'); if (t) t.remove();
    t = document.createElement('div'); t.className = 'lx-toast' + (type === 'error' ? ' error' : ''); t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('show'); });
    setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 2800);
  }
  function storedPhone() { try { var c = JSON.parse(localStorage.getItem('customerDetails') || 'null'); return c && c.phone ? c.phone : ''; } catch (e) { return ''; } }
  // Tier icons the page can draw; anything else gets one by level
  var TIER_ICONS = ['star', 'medal', 'crown', 'gem', 'trophy', 'award', 'fire', 'heart'];
  function tierIcon(t) { var i = P.tiers.indexOf(t); return TIER_ICONS.indexOf(t.icon) !== -1 ? t.icon : ['star', 'medal', 'crown', 'gem'][Math.min(3, Math.max(0, i))]; }
  function earnLine() { return P.earn_points_per_amount + ' point' + (P.earn_points_per_amount === 1 ? '' : 's') + ' for every ' + money(P.earn_amount_threshold); }
  function refLink(code) { var b = window.lxMenuUrl, s = b.indexOf('?') === -1 ? '?' : '&'; return location.origin + (b.charAt(0) === '/' ? '' : '/') + b.replace(/^\//, '') + s + 'ref=' + encodeURIComponent(code); }

  /* ── hero ── */
  function heroGuest() {
    return '<div class="lx-kicker"><i class="fa fa-crown"></i> ' + (window.lxFromQr ? 'Welcome! You scanned our rewards card' : esc(window.restaurantName) + ' Rewards') + '</div>' +
      '<h1 class="lx-title">Every order earns you <em>free food</em></h1>' +
      '<p class="lx-sub">Join free and earn ' + esc(earnLine()) + '. Swap points for discounts' + (P.rewards.length ? ' and free dishes' : '') + (P.tiers.length ? ', and unlock bigger rewards as you level up.' : '.') + '</p>' +
      '<div class="lx-ctas"><a class="lx-btn gold" href="' + esc(window.lxLoginUrl) + '"><i class="fa fa-user-plus"></i> Join free in 30 seconds</a>' +
      '<a class="lx-btn ghost" href="' + esc(window.lxLoginUrl) + '">I’m already a member, log in</a></div>';
  }
  function heroMember() {
    var t = M.tier, n = M.next_tier, pct = 100;
    if (n) { var floor = t ? t.min_total_spent : 0; pct = Math.max(3, Math.min(100, (M.total_spent - floor) / Math.max(1, n.min_total_spent - floor) * 100)); }
    var first = (M.name || '').split(' ')[0] || 'there';
    return '<div class="lx-card">' +
      '<div class="lx-card-row"><div class="lx-hello">Welcome back<b>' + esc(first) + '</b></div>' +
        '<span class="lx-tier' + (t ? '' : ' none') + '"><i class="fa fa-' + (t ? tierIcon(P.tiers.filter(function (x) { return x.tier_name === t.tier_name; })[0] || t) : 'seedling') + '"></i> ' + esc(t ? t.tier_name : 'Member') + '</span></div>' +
      '<div class="lx-pts"><b id="lxPts">0</b><span>points</span></div>' +
      '<div class="lx-worth">Worth ' + money(M.points_balance * P.redeem_value_per_point) + ' off your next order</div>' +
      (P.tiers.length ? '<div class="lx-prog"><div class="lx-prog-bar"><i id="lxProg" data-w="' + pct + '"></i></div>' +
        '<p>' + (n ? 'Spend <b>' + money(n.amount_needed) + '</b> more to reach <b>' + esc(n.tier_name) + '</b>' + (n.points_multiplier > 1 ? ' and earn ' + n.points_multiplier + 'x points' : '') : 'You’re at the <b>top tier</b>. Enjoy the best rewards.') + '</p></div>' : '') +
      '<div class="lx-mini"><div><b>' + num(M.visits) + '</b><span>Orders</span></div><div><b>' + money(M.total_spent) + '</b><span>Spent</span></div><div><b>' + (t && t.points_multiplier > 1 ? t.points_multiplier + 'x' : '1x') + '</b><span>Points rate</span></div></div>' +
    '</div>';
  }

  /* ── sections ── */
  function facts() {
    var f = [['fa-coins', earnLine(), 'Earn on every order'], ['fa-tag', '1 point = ' + money(P.redeem_value_per_point), 'Use from ' + num(P.min_redeem_points) + ' points']];
    if (P.referral_enabled) f.push(['fa-user-group', '+' + num(P.referrer_reward_points) + ' points', 'For every friend you invite']);
    f.push(['fa-hourglass-half', P.points_expiry_days ? 'Valid ' + P.points_expiry_days + ' days' : 'Never expire', P.points_expiry_days ? 'Points last while you keep ordering' : 'Your points are safe']);
    return '<div class="lx-facts">' + f.map(function (x) { return '<div class="lx-fact"><i class="fa ' + x[0] + '"></i><div><b>' + esc(x[1]) + '</b>' + esc(x[2]) + '</div></div>'; }).join('') + '</div>';
  }
  function steps() {
    return '<section class="lx-sec"><div class="lx-h"><h2>How it works</h2></div><div class="lx-steps">' +
      '<div class="lx-step"><i class="fa fa-bag-shopping" data-n="1"></i><div><b>Order as usual</b><span>Online or at the restaurant, using your phone number.</span></div></div>' +
      '<div class="lx-step"><i class="fa fa-coins" data-n="2"></i><div><b>Collect points</b><span>' + esc(earnLine()) + (P.tiers.length ? ', more as you move up tiers' : '') + '.</span></div></div>' +
      '<div class="lx-step"><i class="fa fa-gift" data-n="3"></i><div><b>Treat yourself</b><span>Swap points for money off' + (P.rewards.length ? ' or a free dish' : '') + ' whenever you like.</span></div></div>' +
    '</div></section>';
  }
  function rewards() {
    if (!P.rewards.length) return '';
    var bal = M ? M.points_balance : 0;
    return '<section class="lx-sec"><div class="lx-h"><h2>Free dishes</h2><small>' + (M ? 'Tap to redeem' : 'Join to start collecting') + '</small></div><div class="lx-rewards">' +
      P.rewards.map(function (r) {
        var pct = M ? Math.min(100, bal / r.points_cost * 100) : 0, left = r.points_cost - bal;
        return '<div class="lx-rw"><div class="lx-rw-img" style="background-image:url(\'' + esc(img(r.image)) + '\')"><span class="cost">' + num(r.points_cost) + ' pts</span></div>' +
          '<div class="lx-rw-b"><b>' + esc(r.item_name) + '</b>' + (r.price ? '<small>Worth ' + money(r.price) + '</small>' : '') +
          (M ? '<div class="lx-rw-bar"><i style="width:' + pct + '%"></i></div><small>' + (left > 0 ? num(left) + ' more points' : 'Ready to redeem') + '</small>' +
            '<button type="button" data-item="' + r.id + '"' + (left > 0 ? ' disabled' : '') + '>' + (left > 0 ? 'Keep collecting' : 'Redeem') + '</button>' : '') +
          '</div></div>';
      }).join('') + '</div></section>';
  }
  function tiers() {
    if (!P.tiers.length) return '';
    var spent = M ? M.total_spent : -1, here = M && M.tier ? M.tier.tier_name : null;
    return '<section class="lx-sec"><div class="lx-h"><h2>Member levels</h2><small>Spend more, earn faster</small></div><div class="lx-tiers">' +
      P.tiers.map(function (t) {
        var reached = spent >= t.min_total_spent, isHere = here === t.tier_name;
        return '<div class="lx-tr' + (reached ? ' reached' : '') + (isHere ? ' here' : '') + '">' + (isHere ? '<span class="you">You</span>' : '') +
          '<i class="fa fa-' + tierIcon(t) + '"></i><div><b>' + esc(t.tier_name) + '</b><span>' + (t.min_total_spent > 0 ? 'From ' + money(t.min_total_spent) + ' spent' : 'From your first order') + '</span></div>' +
          '<em>' + (t.points_multiplier > 1 ? t.points_multiplier + 'x points' : 'Points on every order') + '</em></div>';
      }).join('') + '</div></section>';
  }
  function referral() {
    if (!P.referral_enabled) return '';
    var gift = '<div class="lx-ref-gift"><div><b>+' + num(P.referred_reward_points) + '</b><span>points for your friend</span></div><div><b>+' + num(P.referrer_reward_points) + '</b><span>points for you</span></div></div>';
    if (!M || !M.referral_code) {
      return '<section class="lx-sec"><div class="lx-h"><h2>Invite friends</h2></div><div class="lx-ref">' + gift +
        '<p class="lx-note">Join to get your own invite code. When a friend orders with it, you both get points.</p></div></section>';
    }
    var link = refLink(M.referral_code);
    return '<section class="lx-sec"><div class="lx-h"><h2>Invite friends</h2><small>You both get points</small></div><div class="lx-ref">' + gift +
      '<div class="lx-code"><b>' + esc(M.referral_code) + '</b><button type="button" data-act="copy">Copy link</button></div>' +
      '<div class="lx-ref-actions"><a class="lx-btn lx-wa" target="_blank" rel="noopener" href="https://wa.me/?text=' + encodeURIComponent('Try ' + window.restaurantName + '! Use my code ' + M.referral_code + ' and we both get reward points: ' + link) + '"><i class="fab fa-whatsapp"></i> WhatsApp</a>' +
        '<button type="button" class="lx-btn light" data-act="share"><i class="fa fa-share-nodes"></i> Share</button></div>' +
      '<div class="lx-ref-qr"><img alt="Invite QR code" loading="lazy" src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&margin=0&data=' + encodeURIComponent(link) + '"><span>Friends can scan this at your table to join with your code.</span></div>' +
    '</div></section>';
  }
  function redeem() {
    if (!M) return '';
    var bal = M.points_balance, min = P.min_redeem_points, v = P.redeem_value_per_point;
    var opts = [min, Math.max(min, Math.floor(bal / 2)), bal].filter(function (x, i, a) { return x > 0 && a.indexOf(x) === i; }).slice(0, 3);
    if (!pickPts || pickPts > bal) pickPts = bal >= min ? opts[opts.length - 1] : 0;
    var pending = (M.pending_codes || []).length ? '<div class="lx-h" style="margin-top:16px"><h2 style="font-size:15px">Codes to show staff</h2></div><div class="lx-pending">' +
      M.pending_codes.map(function (c) { return '<div><b>' + esc(c.code) + '</b><span>' + esc(c.note || (Math.abs(c.points) + ' points')) + '</span></div>'; }).join('') + '</div>' : '';
    return '<section class="lx-sec"><div class="lx-h"><h2>Use your points</h2><small>' + num(bal) + ' available</small></div>' +
      (bal >= min
        ? '<div class="lx-redeem-q">' + opts.map(function (o) { return '<button type="button" data-pts="' + o + '"' + (o > bal ? ' disabled' : '') + (o === pickPts ? ' class="on"' : '') + '>' + num(o) + '<small>' + money(o * v) + ' off</small></button>'; }).join('') + '</div>' +
          '<button type="button" class="lx-btn brand" style="width:100%;margin-top:12px" data-act="redeem">Get a ' + money(pickPts * v) + ' discount code</button>' +
          '<p class="lx-note">You get a code to show at the counter. Ordering online? Tick “use points” in your cart instead.</p>'
        : '<p class="lx-note" style="margin-top:0">Collect <b>' + num(min - bal) + ' more points</b> to start using them (from ' + num(min) + ' points, worth ' + money(min * v) + ').</p>') +
      '<div id="lxCode"></div>' + pending + '</section>';
  }
  function activity() {
    if (!M) return '';
    var L = M.ledger || [];
    return '<section class="lx-sec"><button type="button" class="lx-toggle" data-act="act"><h2 style="font-size:17px;font-weight:800">Points history</h2><small style="color:var(--muted);font-size:12px">' + (showAct ? 'Hide' : 'Show') + ' <i class="fa fa-chevron-' + (showAct ? 'up' : 'down') + '"></i></small></button>' +
      (showAct ? '<div class="lx-act">' + (L.length ? L.map(function (l) {
        var plus = l.points_change > 0, d = new Date(String(l.created_at).replace(' ', 'T'));
        return '<div><i class="fa fa-' + (plus ? 'arrow-down' : 'arrow-up') + (plus ? '' : ' out') + '"></i><p>' + esc(l.note || l.type) + '<small>' + (isNaN(d) ? '' : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })) + '</small></p>' +
          '<strong class="' + (plus ? '' : 'out') + '">' + (plus ? '+' : '') + num(l.points_change) + '</strong></div>';
      }).join('') : '<p class="lx-note" style="margin:0">Nothing yet. Your first order starts the count.</p>') + '</div>' : '') + '</section>';
  }

  function render(bodyOnly) {
    $('lxSkel').classList.add('hidden');
    if (!P.loyalty_enabled) { $('lxHeroBody').innerHTML = '<div class="lx-kicker"><i class="fa fa-crown"></i> Rewards</div><h1 class="lx-title">Something <em>tasty</em> is coming</h1>'; $('lxOff').classList.remove('hidden'); return; }
    if (!bodyOnly) $('lxHeroBody').innerHTML = M ? heroMember() : heroGuest();
    $('lxBody').innerHTML = facts() + (M ? redeem() + rewards() + tiers() + referral() + activity() : steps() + rewards() + tiers() + referral()) +
      '<p class="lx-fine">Points are added when your order is completed and come off if it’s cancelled.' + (P.points_expiry_days ? ' Points expire after ' + P.points_expiry_days + ' days without an order.' : '') + '</p>';
    $('lxBody').classList.remove('hidden');
    $('lxSticky').innerHTML = M ? '<a class="lx-btn brand" href="' + esc(window.lxMenuUrl) + '"><i class="fa fa-utensils"></i> Order now and earn points</a>'
                                : '<a class="lx-btn gold" href="' + esc(window.lxLoginUrl) + '"><i class="fa fa-user-plus"></i> Join free and start earning</a>';
    reveal();
    if (bodyOnly) { document.querySelectorAll('.lx-sec').forEach(function (s) { s.classList.add('in'); }); return; }
    if (M) { countUp(); setTimeout(function () { var p = $('lxProg'); if (p) p.style.width = p.getAttribute('data-w') + '%'; }, 250); }
  }

  function countUp() {
    var el = $('lxPts'), to = M.points_balance;
    if (!el) return;
    if (!to || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) { el.textContent = num(to); return; }
    var t0 = performance.now();
    (function step(t) { var p = Math.min(1, (t - t0) / 1100), e = 1 - Math.pow(1 - p, 3); el.textContent = num(to * e); if (p < 1) requestAnimationFrame(step); })(t0);
  }
  // Sections slide in as they come into view; the join / order button appears once the hero scrolls away
  function reveal() {
    var secs = document.querySelectorAll('.lx-sec');
    if (!('IntersectionObserver' in window)) { secs.forEach(function (s) { s.classList.add('in'); }); return; }
    var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }); }, { rootMargin: '0px 0px -40px 0px' });
    secs.forEach(function (s) { io.observe(s); });
    var io2 = new IntersectionObserver(function (es) { $('lxSticky').classList.toggle('show', !es[0].isIntersecting); }, { threshold: 0.15 });
    io2.observe($('lxHero'));
  }

  function load() {
    fetch('loyalty_actions.php?action=page&restaurant_id=' + encodeURIComponent(window.restaurantId) + '&phone=' + encodeURIComponent(storedPhone()), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) { toast(d.message || 'Could not load rewards', 'error'); return; }
        P = d.program; M = d.member; render();
      })
      .catch(function () { $('lxSkel').classList.add('hidden'); toast('Could not load rewards right now', 'error'); });
  }

  function post(fd) { return fetch('loyalty_actions.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }); }
  function showCode(code, label) {
    var box = $('lxCode') || document.querySelector('.lx-sec');
    if (box) box.innerHTML = '<div class="lx-codebox"><small>' + esc(label) + '</small><b>' + esc(code) + '</b></div>';
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-pts],[data-act],[data-item]');
    if (!t) return;
    if (t.hasAttribute('data-pts')) { pickPts = +t.getAttribute('data-pts'); render(true); return; }
    var a = t.getAttribute('data-act');
    if (a === 'act') { showAct = !showAct; render(true); return; }
    if (a === 'copy') { var link = refLink(M.referral_code); (navigator.clipboard ? navigator.clipboard.writeText(link) : Promise.reject()).then(function () { toast('Invite link copied'); }, function () { toast(link); }); return; }
    if (a === 'share') {
      var l = refLink(M.referral_code);
      if (navigator.share) navigator.share({ title: window.restaurantName, text: 'Use my code ' + M.referral_code + ' and we both get reward points', url: l }).catch(function () {});
      else { (navigator.clipboard ? navigator.clipboard.writeText(l) : Promise.reject()).then(function () { toast('Invite link copied'); }, function () {}); }
      return;
    }
    if (a === 'redeem') {
      if (!pickPts) return;
      t.disabled = true;
      var fd = new FormData(); fd.append('action', 'redeem'); fd.append('restaurant_id', window.restaurantId); fd.append('phone', storedPhone()); fd.append('points', pickPts);
      post(fd).then(function (r) {
        t.disabled = false;
        if (!r.success) { toast(r.message || 'Could not redeem', 'error'); return; }
        M.points_balance -= pickPts; pickPts = 0; render(); showCode(r.code, 'Show this code at the counter for ' + money(r.discount_value) + ' off');
        $('lxCode').scrollIntoView({ behavior: 'smooth', block: 'center' }); load();
      }).catch(function () { t.disabled = false; toast('Something went wrong', 'error'); });
      return;
    }
    if (t.hasAttribute('data-item')) {
      t.disabled = true;
      var fd2 = new FormData(); fd2.append('action', 'redeem_item'); fd2.append('restaurant_id', window.restaurantId); fd2.append('phone', storedPhone()); fd2.append('reward_id', t.getAttribute('data-item'));
      post(fd2).then(function (r) {
        if (!r.success) { t.disabled = false; toast(r.message || 'Could not redeem', 'error'); return; }
        toast('Redeemed! Show code ' + r.code + ' to staff');
        load();
      }).catch(function () { t.disabled = false; toast('Something went wrong', 'error'); });
    }
  });

  document.addEventListener('DOMContentLoaded', load);
})();
</script>
<?php require_once __DIR__ . '/bottom_nav.php'; ?>
</body>
</html>
