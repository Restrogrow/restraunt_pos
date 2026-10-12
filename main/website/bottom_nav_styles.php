<?php
// Bottom navigation bar looks (Website Appearance > Bottom Navigation > Bar style).
// Shared by bottom_nav.php and index.php (which draws its own bar).
if (defined('RG_NAV_BAR_STYLES_CSS')) return;
define('RG_NAV_BAR_STYLES_CSS', true);
?>
<style>
/* ── Bar styles (Website Appearance > Bottom Navigation > Bar style) ──
   "classic" is each page's own .bottom-nav CSS; the others build on it.
   .active = the page you're on. */
.bottom-nav .nav-item { transition: color .2s, background .25s, transform .25s; }
.bottom-nav[class*="bn-"]:not(.bn-classic):not(.bn-dark) .nav-item.active .nav-icon,
.bottom-nav[class*="bn-"]:not(.bn-classic):not(.bn-dark) .nav-item.active span { color: var(--primary-red, #e11d48); }
.bottom-nav[class*="bn-"]:not(.bn-classic) .nav-item.active span { font-weight: 700; }

/* Floating: a rounded bar hovering above the bottom edge */
.bottom-nav.bn-floating { bottom: 12px; width: calc(100% - 24px); max-width: 401px; border-radius: 22px; border: 1px solid rgba(0,0,0,.06); box-shadow: 0 14px 34px -10px rgba(0,0,0,.28); padding: 8px 12px; }

/* Glass: frosted, see-through */
.bottom-nav.bn-glass { background: rgba(255,255,255,.66); -webkit-backdrop-filter: blur(18px) saturate(1.7); backdrop-filter: blur(18px) saturate(1.7); border-top: 1px solid rgba(255,255,255,.7); box-shadow: 0 -8px 26px rgba(0,0,0,.08); }

/* Dark */
.bottom-nav.bn-dark { background: #111827; border-top: 0; box-shadow: 0 -6px 20px rgba(0,0,0,.25); }
.bottom-nav.bn-dark .nav-item span, .bottom-nav.bn-dark .nav-icon { color: #9ca3af; }
.bottom-nav.bn-dark .nav-item.active span, .bottom-nav.bn-dark .nav-item.active .nav-icon { color: #fff; }
.bottom-nav.bn-dark .nav-item.active::after { content: ""; position: absolute; bottom: -6px; width: 18px; height: 3px; border-radius: 3px; background: var(--primary-red, #e11d48); }

/* Pill: the current tab grows into a coloured pill with its label */
.bottom-nav.bn-pill { padding: 10px 12px; }
.bottom-nav.bn-pill .nav-item { flex-direction: row; gap: 6px; padding: 8px 12px; border-radius: 999px; }
.bottom-nav.bn-pill .nav-item span { display: none; }
.bottom-nav.bn-pill .nav-item.active { background: color-mix(in srgb, var(--primary-red, #e11d48) 13%, #fff); }
.bottom-nav.bn-pill .nav-item.active span { display: inline; font-size: 12.5px; }
.bottom-nav.bn-pill .nav-item .nav-icon { font-size: 21px; }
.bottom-nav.bn-pill .nav-item img { width: 24px; height: 24px; }
.bottom-nav.bn-pill .cart-badge { top: -2px; right: auto; left: 22px; transform: none; }

/* Bubble: the current tab pops up in a coloured circle */
.bottom-nav.bn-bubble { padding-top: 10px; }
.bottom-nav.bn-bubble .nav-item.active > :first-child { width: 50px; height: 50px; display: grid; place-items: center; margin-top: -30px; border-radius: 50%; background: var(--primary-red, #e11d48); color: #fff !important; border: 4px solid #fff; box-shadow: 0 8px 18px -6px var(--primary-red, #e11d48); }
.bottom-nav.bn-bubble .nav-item.active img:first-child { padding: 9px; object-fit: contain; }
.bottom-nav.bn-bubble .nav-item.active > .nav-icon { font-size: 22px; }

/* Top line: a short accent line over the current tab */
.bottom-nav.bn-line .nav-item.active::before { content: ""; position: absolute; top: -9px; left: 50%; width: 28px; height: 3px; margin-left: -14px; border-radius: 0 0 4px 4px; background: var(--primary-red, #e11d48); }

/* Raised cart: the cart is a big round button sitting above the bar */
.bottom-nav.bn-fab #navCartItem > :first-child { width: 54px; height: 54px; display: grid; place-items: center; margin-top: -30px; border-radius: 50%; background: var(--primary-red, #e11d48); color: #fff !important; border: 4px solid #fff; box-shadow: 0 10px 22px -8px var(--primary-red, #e11d48); font-size: 22px; }
.bottom-nav.bn-fab #navCartItem img:first-child { padding: 10px; object-fit: contain; }
.bottom-nav.bn-fab #navCartItem .cart-badge { top: -34px; transform: translateX(24px); border: 2px solid #fff; background: #111827; }

/* Icons only: no labels, a dot under the current tab */
.bottom-nav.bn-minimal { padding: 12px 14px 14px; }
.bottom-nav.bn-minimal .nav-item span { display: none; }
.bottom-nav.bn-minimal .nav-item.active::after { content: ""; width: 5px; height: 5px; margin-top: 4px; border-radius: 50%; background: var(--primary-red, #e11d48); }
/* ── Animated icon styles (Bottom Navigation > Icon style: Playful, Chef, Café,
   Fresh, Spicy, Sweet). Icons pop in on load, the current page's icon keeps a
   gentle motion, and any icon reacts when tapped. ── */
.bottom-nav[class*=" ni-"] .nav-item > .nav-icon { display: inline-block; animation: niIn .55s cubic-bezier(.2,1.5,.4,1) both; }
.bottom-nav[class*=" ni-"] .nav-item:nth-child(2) > .nav-icon { animation-delay: .06s; }
.bottom-nav[class*=" ni-"] .nav-item:nth-child(3) > .nav-icon { animation-delay: .12s; }
.bottom-nav[class*=" ni-"] .nav-item:nth-child(4) > .nav-icon { animation-delay: .18s; }
.bottom-nav[class*=" ni-"] .nav-item:nth-child(5) > .nav-icon { animation-delay: .24s; }
.bottom-nav[class*=" ni-"] .nav-item:nth-child(6) > .nav-icon { animation-delay: .3s; }
@keyframes niIn { from { opacity: 0; transform: translateY(10px) scale(.6); } to { opacity: 1; transform: none; } }

.bottom-nav.ni-bounce .nav-item.active > .nav-icon { animation: niBounce 2.6s ease-in-out .7s infinite; }
.bottom-nav.ni-bounce .nav-item:active > .nav-icon { animation: niBounce .5s ease-out; }
@keyframes niBounce { 0%, 40%, 100% { transform: translateY(0); } 12% { transform: translateY(-7px); } 24% { transform: translateY(0); } 32% { transform: translateY(-3px); } }

.bottom-nav.ni-swing .nav-item > .nav-icon { transform-origin: 50% 10%; }
.bottom-nav.ni-swing .nav-item.active > .nav-icon { animation: niSwing 3s ease-in-out .7s infinite; }
.bottom-nav.ni-swing .nav-item:active > .nav-icon { animation: niSwing .7s ease-out; }
@keyframes niSwing { 0%, 50%, 100% { transform: rotate(0); } 10% { transform: rotate(16deg); } 20% { transform: rotate(-12deg); } 30% { transform: rotate(7deg); } 40% { transform: rotate(-4deg); } }

.bottom-nav.ni-pulse .nav-item.active > .nav-icon { animation: niPulse 2.2s ease-in-out .7s infinite; }
.bottom-nav.ni-pulse .nav-item:active > .nav-icon { animation: niPulse .45s ease-out; }
@keyframes niPulse { 0%, 100% { transform: scale(1); filter: drop-shadow(0 0 0 transparent); } 50% { transform: scale(1.18); filter: drop-shadow(0 0 6px color-mix(in srgb, var(--primary-red, #e11d48) 55%, transparent)); } }

.bottom-nav.ni-float .nav-item.active > .nav-icon { animation: niFloat 2.8s ease-in-out .7s infinite; }
.bottom-nav.ni-float .nav-item:active > .nav-icon { animation: niFloat .6s ease-out; }
@keyframes niFloat { 0%, 100% { transform: translateY(0) rotate(0); } 50% { transform: translateY(-5px) rotate(-6deg); } }

.bottom-nav.ni-pop .nav-item.active > .nav-icon { animation: niPop 2.4s cubic-bezier(.2,1.6,.4,1) .7s infinite; }
.bottom-nav.ni-pop .nav-item:active > .nav-icon { animation: niPop .45s cubic-bezier(.2,1.6,.4,1); }
@keyframes niPop { 0%, 45%, 100% { transform: scale(1) rotate(0); } 15% { transform: scale(1.32) rotate(-10deg); } 30% { transform: scale(.94) rotate(4deg); } }

.bottom-nav.ni-wiggle .nav-item.active > .nav-icon { animation: niWiggle 2.6s ease-in-out .7s infinite; }
.bottom-nav.ni-wiggle .nav-item:active > .nav-icon { animation: niWiggle .5s ease-in-out; }
@keyframes niWiggle { 0%, 40%, 100% { transform: rotate(0) scale(1); } 8% { transform: rotate(-14deg) scale(1.1); } 16% { transform: rotate(12deg) scale(1.1); } 24% { transform: rotate(-8deg); } 32% { transform: rotate(4deg); } }

/* ── Colourful icon styles (Rainbow, Candy, App tiles, Neon, Fiesta, Garden):
   each icon carries --ic / --ic2 (its colours) ── */
.bottom-nav.np-solid .nav-item > .nav-icon { color: var(--ic) !important; }
.bottom-nav.np-gradient .nav-item > .nav-icon { background: linear-gradient(135deg, var(--ic), var(--ic2)); -webkit-background-clip: text; background-clip: text; color: transparent !important; }
.bottom-nav.np-tile .nav-item > .nav-icon { width: 34px; height: 34px; display: grid !important; place-items: center; margin: 0 auto 2px; border-radius: 11px; background: linear-gradient(135deg, var(--ic), var(--ic2)); color: #fff !important; font-size: 15px; box-shadow: 0 5px 12px -4px var(--ic2); }
.bottom-nav.np-tile .nav-item.active > .nav-icon { box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--ic), 0 6px 14px -4px var(--ic2); }
.bottom-nav.np-glow .nav-item > .nav-icon { color: var(--ic) !important; filter: drop-shadow(0 0 4px color-mix(in srgb, var(--ic) 70%, transparent)); }
.bottom-nav.np-glow .nav-item.active > .nav-icon { filter: drop-shadow(0 0 8px var(--ic)); }
.bottom-nav.np-pastel .nav-item > .nav-icon { width: 40px; height: 30px; display: grid !important; place-items: center; margin: 0 auto 2px; border-radius: 999px; background: color-mix(in srgb, var(--ic) 15%, #fff); color: var(--ic) !important; font-size: 16px; }
.bottom-nav.np-pastel .nav-item.active > .nav-icon { background: color-mix(in srgb, var(--ic) 28%, #fff); }
.bottom-nav.np-solid .nav-item.active span, .bottom-nav.np-glow .nav-item.active span, .bottom-nav.np-pastel .nav-item.active span { color: var(--ink, #111827); }
.bottom-nav.bn-dark.np-pastel .nav-item > .nav-icon { background: color-mix(in srgb, var(--ic) 26%, #111827); }

@media (prefers-reduced-motion: reduce) { .bottom-nav[class*=" ni-"] .nav-item > .nav-icon { animation: none !important; } }
</style>
