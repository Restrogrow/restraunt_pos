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
</style>
