<?php
/**
 * Animated bottom-navigation icons (Website Appearance > Bottom Navigation >
 * Icon style: "Lively" and "Cartoon"). Our own drawings in a lineal-colour
 * style, each a small scene with moving parts: smoke from the chimney, steam
 * from the bowl, a beating heart, a tick that draws itself, a cloche that
 * lifts, a parcel dropping into the cart, a blinking smiley, a bouncing
 * download arrow. No outside icon licence is needed.
 *
 * navAnimatedSvg($slot, $variant, $inNavItem) returns the inline <svg>;
 * navAnimatedSvgCss() the shared styles (website bar + dashboard picker).
 *   variant 'line' = outlined (Lively), 'flat' = no outlines (Cartoon)
 */
if (!function_exists('navAnimatedSvg')) {

    function navAnimatedSvgParts(): array {
        return [
            'home' =>
                '<circle class="a smk" cx="21.8" cy="6.6" r="1.5" fill="#cbd5e1"/>' .
                '<circle class="a smk s2" cx="23.2" cy="4" r="1.1" fill="#e2e8f0"/>' .
                '<path class="o f" d="M19.4 12.2V6.8h3.7v5.4" fill="#94a3b8"/>' .
                '<path class="o f" d="M7 14.4V26h18V14.4" fill="#fde68a"/>' .
                '<path class="o f" d="M3.8 15.6 16 5.4l12.2 10.2z" fill="#f87171"/>' .
                '<path class="o f" d="M13.4 26v-6.1a2.6 2.6 0 0 1 5.2 0V26z" fill="#f59e0b"/>' .
                '<path class="o" d="M4.6 26h22.8"/>',
            'menu' =>
                '<path class="o lt a stm" d="M11.4 10.6c-1.6-1.6 1.6-2.7 0-4.3"/>' .
                '<path class="o lt a stm s2" d="M16 9.6c-1.6-1.6 1.6-2.7 0-4.3"/>' .
                '<path class="o lt a stm s3" d="M20.6 10.6c-1.6-1.6 1.6-2.7 0-4.3"/>' .
                '<path class="o f" d="M5 15.3h22a11 10 0 0 1-22 0z" fill="#fb923c"/>' .
                '<ellipse class="o f" cx="16" cy="15.3" rx="11" ry="2.3" fill="#fde68a"/>' .
                '<circle cx="12.4" cy="15" r="1" fill="#22c55e"/><circle cx="18.8" cy="15.6" r="1" fill="#ef4444"/><circle cx="15.6" cy="14.6" r=".8" fill="#16a34a"/>' .
                '<path class="o" d="M11.6 25.8h8.8"/>',
            'social' =>
                '<g class="a spk"><path class="o gold" d="M5.4 7.2l1.7 1.7M26.6 7.2l-1.7 1.7M16 3v2.3"/></g>' .
                '<g class="a hb"><path class="o f" d="M16 26.2S5.2 19.4 5.2 12.5a5.3 5.3 0 0 1 10.8-2.4 5.3 5.3 0 0 1 10.8 2.4c0 6.9-10.8 13.7-10.8 13.7z" fill="#f472b6"/>' .
                '<path d="M9.4 12.4a2.9 2.9 0 0 1 2.4-2.4" stroke="#fff" stroke-width="1.6" stroke-linecap="round" fill="none"/></g>',
            'plans' =>
                '<rect class="o f" x="5" y="7" width="22" height="20" rx="3" fill="#ffffff"/>' .
                '<path class="o f" d="M5 13v-3a3 3 0 0 1 3-3h16a3 3 0 0 1 3 3v3z" fill="#60a5fa"/>' .
                '<path class="o" d="M11 4.8v4.6M21 4.8v4.6"/>' .
                '<path class="a chk" d="M11.4 19.6l3 3 6.2-6.2" fill="none" stroke="#22c55e" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="15"/>',
            'reservations' =>
                '<path class="o gold a spk2" d="M16 15.2v2.6M14.7 16.5h2.6"/>' .
                '<g class="a lid"><path class="o f" d="M6.5 22a9.5 9.5 0 0 1 19 0z" fill="#fbbf24"/>' .
                '<path class="o f" d="M14.4 12.7a1.6 1.6 0 0 1 3.2 0z" fill="#f59e0b"/>' .
                '<path d="M10.4 19.2a6.2 6.2 0 0 1 3-4.2" stroke="#fff" stroke-width="1.5" stroke-linecap="round" fill="none"/></g>' .
                '<path class="o" d="M4 22.4h24M9 25.6h14"/>',
            'cart' =>
                '<g class="a spd"><path class="o lt" d="M1.6 13.6h3M2.4 17.2h2.6"/></g>' .
                '<rect class="o f a box" x="14.6" y="3.6" width="5.6" height="5" rx="1" fill="#fbbf24"/>' .
                '<g class="a crt"><path class="o f" d="M9.4 10.6h16.8l-2.6 9.4H11.9z" fill="#34d399"/>' .
                '<path class="o" d="M4.4 7h3.3l3.6 13h12.4"/>' .
                '<circle class="o f whl" cx="12.8" cy="24.4" r="1.9" fill="#ffffff"/><circle class="o f whl" cx="22.2" cy="24.4" r="1.9" fill="#ffffff"/></g>',
            'profile' =>
                '<circle class="o f" cx="16" cy="16" r="11" fill="#fde047"/>' .
                '<g class="a eye"><ellipse cx="12.2" cy="14" rx="1.3" ry="1.9" fill="#273043"/><ellipse cx="19.8" cy="14" rx="1.3" ry="1.9" fill="#273043"/></g>' .
                '<circle cx="9.6" cy="18.2" r="1.5" fill="#fb7185" opacity=".55"/><circle cx="22.4" cy="18.2" r="1.5" fill="#fb7185" opacity=".55"/>' .
                '<path class="o" d="M11.8 19.2c2.3 2.9 6.1 2.9 8.4 0"/>',
            'install' =>
                '<g class="a arw"><path d="M16 5.5V18M10.8 13l5.2 5.2 5.2-5.2" fill="none" stroke="#3b82f6" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></g>' .
                '<path class="o f" d="M6 19.5v5a1.5 1.5 0 0 0 1.5 1.5h17a1.5 1.5 0 0 0 1.5-1.5v-5z" fill="#e0e7ff"/>',
        ];
    }

    function navAnimatedSvg(string $slot, string $variant = 'line', bool $inNavItem = true): string {
        $parts = navAnimatedSvgParts();
        $inner = $parts[$slot] ?? $parts['plans'];
        $variant = $variant === 'flat' ? 'flat' : 'line';
        $slotAttr = htmlspecialchars($slot, ENT_QUOTES, 'UTF-8');
        return '<svg class="nav-svg v-' . $variant . ($inNavItem ? ' nav-icon' : ' small') . '" data-nav-slot="' . $slotAttr . '" viewBox="0 0 32 32" aria-hidden="true" focusable="false">' . $inner . '</svg>';
    }

    /** Every slot's markup for one variant (for the dashboard picker). */
    function navAnimatedSvgAll(string $variant): array {
        $out = [];
        foreach (array_keys(navAnimatedSvgParts()) as $slot) $out[$slot] = navAnimatedSvg($slot, $variant);
        return $out;
    }

    function navAnimatedSvgCss(): string {
        return <<<'CSS'
/* Animated bottom-nav icons (Lively / Cartoon) */
.nav-svg { width: 28px; height: 28px; display: block; margin: 0 auto; overflow: visible; }
.nav-svg.small { width: 20px; height: 20px; display: inline-block; vertical-align: middle; }
.nav-svg * { transform-box: fill-box; transform-origin: center; }
.nav-svg .o { stroke: #273043; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
.nav-svg .o:not(.f) { fill: none; }
.nav-svg .lt { stroke: #94a3b8; }
.nav-svg .gold { stroke: #f59e0b; }
.nav-svg.v-flat .f { stroke: none; }
.nav-svg.v-flat .whl { fill: #475569; }
.nav-svg.v-flat .o:not(.f):not(.lt):not(.gold) { stroke: #475569; }
.bn-dark .nav-svg .o:not(.lt):not(.gold) { stroke: #e5e7eb; }

/* Every icon plays now and then (staggered along the bar); the current tab, a tapped
   icon and a picked style in the dashboard play continuously and faster */
.nav-svg .a { animation-duration: 5.5s; animation-iteration-count: infinite; animation-timing-function: ease-in-out; animation-delay: calc(var(--nvd, 0s) + var(--nvp, 0s)); }
:is(.nav-item.active, .nav-item:active, .nis-tile:hover, .nis-tile.on) .nav-svg .a { animation-duration: 2.6s; }
.bottom-nav .nav-item:nth-child(2) { --nvd: .7s; } .bottom-nav .nav-item:nth-child(3) { --nvd: 1.4s; }
.bottom-nav .nav-item:nth-child(4) { --nvd: 2.1s; } .bottom-nav .nav-item:nth-child(5) { --nvd: 2.8s; } .bottom-nav .nav-item:nth-child(6) { --nvd: 3.5s; }
.nav-svg .s2 { --nvp: .35s; } .nav-svg .s3 { --nvp: .7s; }

.nav-svg .smk { animation-name: nvSmoke; }
@keyframes nvSmoke { 0% { opacity: 0; transform: translateY(2px) scale(.6); } 14% { opacity: 1; } 40%, 100% { opacity: 0; transform: translateY(-6px) scale(1.5); } }
.nav-svg .stm { animation-name: nvSteam; }
@keyframes nvSteam { 0% { opacity: 0; transform: translateY(2px); } 14% { opacity: 1; } 40%, 100% { opacity: 0; transform: translateY(-3.5px); } }
.nav-svg .hb { animation-name: nvBeat; }
@keyframes nvBeat { 0%, 36%, 100% { transform: scale(1); } 8% { transform: scale(1.18); } 16% { transform: scale(.95); } 24% { transform: scale(1.12); } }
.nav-svg .spk { animation-name: nvSpark; }
@keyframes nvSpark { 0%, 6% { opacity: 0; transform: scale(.6); } 16% { opacity: 1; transform: scale(1.1); } 34%, 100% { opacity: 0; transform: scale(1.2); } }
.nav-svg .chk { animation-name: nvCheck; }
@keyframes nvCheck { 0% { stroke-dashoffset: 15; opacity: 1; } 22% { stroke-dashoffset: 0; } 72% { stroke-dashoffset: 0; opacity: 1; } 86% { opacity: 0; } 100% { stroke-dashoffset: 15; opacity: 0; } }
.nav-svg .lid { animation-name: nvLid; transform-origin: 0% 100%; }
@keyframes nvLid { 0%, 48%, 100% { transform: none; } 14%, 34% { transform: translate(-1px, -3px) rotate(-16deg); } }
.nav-svg .spk2 { animation-name: nvSpark2; }
@keyframes nvSpark2 { 0%, 10% { opacity: 0; transform: scale(.5); } 18%, 32% { opacity: 1; transform: scale(1.15); } 44%, 100% { opacity: 0; } }
.nav-svg .box { animation-name: nvBox; }
@keyframes nvBox { 0% { opacity: 0; transform: translateY(-4px); } 12% { opacity: 1; } 32% { opacity: 1; transform: translateY(7px); } 42%, 100% { opacity: 0; transform: translateY(7px); } }
.nav-svg .crt { animation-name: nvCart; }
@keyframes nvCart { 0%, 30%, 100% { transform: none; } 36% { transform: translateY(1px); } 42% { transform: none; } 56% { transform: translateX(1.6px); } 70% { transform: none; } }
.nav-svg .spd { animation-name: nvSpeed; }
@keyframes nvSpeed { 0%, 48% { opacity: 0; transform: none; } 58% { opacity: 1; transform: translateX(-1px); } 72%, 100% { opacity: 0; } }
.nav-svg .eye { animation-name: nvBlink; }
@keyframes nvBlink { 0%, 38%, 46%, 100% { transform: scaleY(1); } 42% { transform: scaleY(.1); } }
.nav-svg .arw { animation-name: nvArrow; }
@keyframes nvArrow { 0%, 40%, 100% { transform: none; } 14% { transform: translateY(3px); } 24% { transform: translateY(-1px); } }

@media (prefers-reduced-motion: reduce) { .nav-svg .a { animation: none !important; } }
CSS;
    }
}
