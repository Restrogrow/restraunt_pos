<?php
/**
 * Five extra animated icon packs for the bottom navigation, drawn in a bold
 * "lineal colour" style: thick navy outlines, pastel two-tone fills, gentle
 * motion. Used by nav_animated_icons.php (navAnimatedSvg). Our own drawings.
 *
 *   cozy    house (door swings), cloche, heart in hands, calendar, candle-lit table, shopping bag
 *   bistro  awning shop, burger, chat bubbles, clipboard, clinking glasses, veggie basket
 *   cafe    coffee cup, cake slice, megaphone, alarm clock, service bell, paper bag
 *   fresh   potted plant, salad bowl, thumbs up, calendar, flower vase table, apple basket
 *   fiesta  food truck, pizza slice, star, ticket, balloons, cart
 *
 * Markup conventions: class "ol" = navy outline (drawn last, on top of fills);
 * class "a k-<motion>" = an animated part (motions in navAnimatedSvgCss()).
 */
if (!function_exists('navIconPackParts')) {

    function navIconPackNames(): array {
        return ['cozy', 'bistro', 'cafe', 'fresh', 'fiesta'];
    }

    function navIconPackParts(string $pack): array {
        $N = '#13306e';
        // Shared by every pack: the profile smiley and the install arrow
        $shared = [
            'profile' =>
                '<circle cx="16" cy="16" r="11" fill="#ffd34d"/><path d="M16 5a11 11 0 0 0 0 22 13 13 0 0 1 0-22z" fill="#ffb020"/>' .
                '<g class="a k-blink"><ellipse cx="12.4" cy="14.2" rx="1.4" ry="2" fill="' . $N . '"/><ellipse cx="19.6" cy="14.2" rx="1.4" ry="2" fill="' . $N . '"/></g>' .
                '<circle cx="9.6" cy="18.4" r="1.6" fill="#ff7fa0" opacity=".6"/><circle cx="22.4" cy="18.4" r="1.6" fill="#ff7fa0" opacity=".6"/>' .
                '<path class="ol" d="M12 19.4c2.2 2.8 5.8 2.8 8 0"/><circle class="ol" cx="16" cy="16" r="11"/>',
            'install' =>
                '<path d="M5.5 19v5.2A2.3 2.3 0 0 0 7.8 26.5h16.4a2.3 2.3 0 0 0 2.3-2.3V19z" fill="#d6defa"/>' .
                '<path class="ol" d="M5.5 19v5.2A2.3 2.3 0 0 0 7.8 26.5h16.4a2.3 2.3 0 0 0 2.3-2.3V19"/>' .
                '<g class="a k-bob"><path d="M16 4.5v13M10.8 12.6l5.2 5.2 5.2-5.2" fill="none" stroke="#2f80ed" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></g>',
        ];

        $packs = [];

        $packs['cozy'] = [
            'home' =>
                '<path d="M20 11.5V5.5h3.4V14" fill="#d9675a"/><path class="ol" d="M20 11V5.5h3.4V14"/>' .
                '<path d="M7 14.5V26h18V14.5L16 7.4z" fill="#fde0c4"/><path d="M7 14.5V26h5c-2.6-3.6-2.6-8.6.6-12.6L16 7.4z" fill="#f6c39a"/>' .
                '<rect x="9.4" y="17.8" width="5.6" height="8.2" fill="#ffc845"/>' .
                '<g class="a k-door" style="transform-origin:0 50%"><rect x="9.4" y="17.8" width="5.6" height="8.2" fill="#ff8c1a"/><circle cx="13.5" cy="22.2" r=".8" fill="' . $N . '"/></g>' .
                '<rect class="ol" x="9.4" y="17.8" width="5.6" height="8.2"/>' .
                '<rect x="18" y="17.8" width="4.6" height="3.8" fill="#5ec8d0"/><rect class="ol" x="18" y="17.8" width="4.6" height="3.8"/>' .
                '<path class="ol" d="M7 15.6V26h18V15.6"/>' .
                '<path d="M3.4 14.6 16 4.4l12.6 10.2-1.9 2.3L16 8.4 5.3 16.9z" fill="#ff7fa0"/><path class="ol" d="M3.4 14.6 16 4.4l12.6 10.2-1.9 2.3L16 8.4 5.3 16.9z"/>' .
                '<rect x="4.6" y="26" width="22.8" height="2.8" rx="1.2" fill="#d6defa"/><rect class="ol" x="4.6" y="26" width="22.8" height="2.8" rx="1.2"/>',
            'menu' =>
                '<path class="a k-twinkle" d="M16 15.6l.8 1.8 1.8.8-1.8.8-.8 1.8-.8-1.8-1.8-.8 1.8-.8z" fill="#ffc845"/>' .
                '<g class="a k-lift" style="transform-origin:0 100%"><path d="M6.4 22.4a9.6 9.6 0 0 1 19.2 0z" fill="#ffd6e2"/>' .
                '<path d="M6.4 22.4a9.6 9.6 0 0 1 6.2-9 13 13 0 0 0-1.8 9z" fill="#ff9fba"/>' .
                '<path d="M19.4 15.6a6 6 0 0 1 2.6 3.4" stroke="#fff" stroke-width="1.6" stroke-linecap="round" fill="none"/>' .
                '<path class="ol" d="M6.4 22.4a9.6 9.6 0 0 1 19.2 0z"/><circle cx="16" cy="11.4" r="1.7" fill="#ff7fa0"/><circle class="ol" cx="16" cy="11.4" r="1.7"/></g>' .
                '<rect x="3.6" y="22.4" width="24.8" height="3" rx="1.5" fill="#d6defa"/><rect class="ol" x="3.6" y="22.4" width="24.8" height="3" rx="1.5"/>',
            'social' =>
                '<g class="a k-float"><path d="M16 12.8s-4.8-3-4.8-6a2.5 2.5 0 0 1 4.8-1 2.5 2.5 0 0 1 4.8 1c0 3-4.8 6-4.8 6z" fill="#ff7fa0"/>' .
                '<path class="ol" d="M16 12.8s-4.8-3-4.8-6a2.5 2.5 0 0 1 4.8-1 2.5 2.5 0 0 1 4.8 1c0 3-4.8 6-4.8 6z"/></g>' .
                '<g class="a k-swayl" style="transform-origin:100% 100%"><path d="M4.6 11v8.4c0 2 1 3.8 2.6 5l3.4 2.8v2.4h4.6v-3.4c0-1.6-.7-3-1.9-4l-3.8-3.4V11.6a2.2 2.2 0 0 0-4.4-.6z" fill="#f8c08c"/>' .
                '<path d="M4.6 11v8.4c0 2 1 3.8 2.6 5l1.2 1c-1-2.6-1.6-5-1.6-8V10z" fill="#f2a46a"/>' .
                '<path class="ol" d="M4.6 11v8.4c0 2 1 3.8 2.6 5l3.4 2.8v2.4h4.6v-3.4c0-1.6-.7-3-1.9-4l-3.8-3.4V11.6a2.2 2.2 0 0 0-4.4-.6z"/></g>' .
                '<g class="a k-swayr" style="transform-origin:0 100%"><path d="M27.4 11v8.4c0 2-1 3.8-2.6 5l-3.4 2.8v2.4h-4.6v-3.4c0-1.6.7-3 1.9-4l3.8-3.4V11.6a2.2 2.2 0 0 1 4.4-.6z" fill="#f8c08c"/>' .
                '<path class="ol" d="M27.4 11v8.4c0 2-1 3.8-2.6 5l-3.4 2.8v2.4h-4.6v-3.4c0-1.6.7-3 1.9-4l3.8-3.4V11.6a2.2 2.2 0 0 1 4.4-.6z"/></g>',
            'plans' =>
                '<rect x="5" y="7" width="22" height="20" rx="3" fill="#ffffff"/><path d="M5 21h22v3a3 3 0 0 1-3 3H8a3 3 0 0 1-3-3z" fill="#eef1fd"/>' .
                '<path d="M5 13v-3a3 3 0 0 1 3-3h16a3 3 0 0 1 3 3v3z" fill="#ff7fa0"/>' .
                '<path class="a k-tick" d="M11.4 19.4l3 3 6.2-6.2" fill="none" stroke="#20b2a6" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="15"/>' .
                '<rect class="ol" x="5" y="7" width="22" height="20" rx="3"/><path class="ol" d="M5 13h22M11 4.6v4.8M21 4.6v4.8"/>',
            'reservations' =>
                '<g class="a k-flick" style="transform-origin:50% 100%"><path d="M16 4.4c2 2.4 2.6 4.4.8 6.2h-1.6c-1.8-1.8-1.2-3.8.8-6.2z" fill="#ffb020"/><path d="M16 7.6c.9 1.2 1 2.2.3 3h-.6c-.7-.8-.6-1.8.3-3z" fill="#ffe08a"/></g>' .
                '<rect x="14.2" y="10.6" width="3.6" height="7.4" rx="1" fill="#ffffff"/><rect class="ol" x="14.2" y="10.6" width="3.6" height="7.4" rx="1"/>' .
                '<path d="M4.4 18h23.2l-1.6 3.8H6z" fill="#ff9fba"/><path d="M4.4 18h6l-1 3.8H6z" fill="#ff7fa0"/><path class="ol" d="M4.4 18h23.2l-1.6 3.8H6z"/>' .
                '<path class="ol" d="M8.6 21.8v6M23.4 21.8v6"/>',
            'cart' =>
                '<g class="a k-bob"><path class="ol" d="M12.2 11.4V9.2a3.8 3.8 0 0 1 7.6 0v2.2"/>' .
                '<path d="M7.6 11.4h16.8l-1.3 15.2H8.9z" fill="#ffd6e2"/><path d="M7.6 11.4h4l-.6 15.2H8.9z" fill="#ff9fba"/>' .
                '<path class="ol" d="M7.6 11.4h16.8l-1.3 15.2H8.9z"/>' .
                '<path class="a k-pop s2" d="M16 23s-3.4-2.1-3.4-4.3a1.8 1.8 0 0 1 3.4-.7 1.8 1.8 0 0 1 3.4.7c0 2.2-3.4 4.3-3.4 4.3z" fill="#ff7fa0"/></g>',
        ];

        $packs['bistro'] = [
            'home' =>
                '<rect x="6" y="12.6" width="20" height="13.6" fill="#fff3d6"/><path d="M6 12.6h4.4v13.6H6z" fill="#ffe2a8"/>' .
                '<rect x="9.4" y="17.6" width="5" height="8.6" fill="#4aa3df"/><rect class="ol" x="9.4" y="17.6" width="5" height="8.6"/>' .
                '<rect x="17.2" y="17.6" width="6" height="4.6" fill="#9fd8f5"/><rect class="ol" x="17.2" y="17.6" width="6" height="4.6"/>' .
                '<path class="ol" d="M6 13v13.2h20V13"/>' .
                '<g class="a k-squash" style="transform-origin:50% 0">' .
                '<path d="M5 7.6h4.4v5H5zM13.8 7.6h4.4v5h-4.4zM22.6 7.6H27v5h-4.4z" fill="#ff5a5f"/><path d="M9.4 7.6h4.4v5H9.4zM18.2 7.6h4.4v5h-4.4z" fill="#ffffff"/>' .
                '<path d="M5 12.6a2.2 2.2 0 0 0 4.4 0zM13.8 12.6a2.2 2.2 0 0 0 4.4 0zM22.6 12.6a2.2 2.2 0 0 0 4.4 0z" fill="#ff5a5f"/><path d="M9.4 12.6a2.2 2.2 0 0 0 4.4 0zM18.2 12.6a2.2 2.2 0 0 0 4.4 0z" fill="#ffffff"/>' .
                '<path class="ol" d="M5 12.6V7.6h22v5a2.2 2.2 0 0 1-4.4 0 2.2 2.2 0 0 1-4.4 0 2.2 2.2 0 0 1-4.4 0 2.2 2.2 0 0 1-4.4 0 2.2 2.2 0 0 1-4.4 0z"/></g>' .
                '<rect x="4.6" y="26.2" width="22.8" height="2.6" rx="1.2" fill="#d6defa"/><rect class="ol" x="4.6" y="26.2" width="22.8" height="2.6" rx="1.2"/>',
            'menu' =>
                '<path d="M7 23h18v1.4a2.6 2.6 0 0 1-2.6 2.6H9.6A2.6 2.6 0 0 1 7 24.4z" fill="#ffb347"/><path class="ol" d="M7 23h18v1.4a2.6 2.6 0 0 1-2.6 2.6H9.6A2.6 2.6 0 0 1 7 24.4z"/>' .
                '<rect x="6.4" y="19.4" width="19.2" height="3.6" rx="1.8" fill="#8b4a2b"/><rect class="ol" x="6.4" y="19.4" width="19.2" height="3.6" rx="1.8"/>' .
                '<path d="M7.4 19.4h17.2l-3 2.4-2.2-1.6-2.4 1.6-2.4-1.6-2.2 1.6z" fill="#ffd34d"/>' .
                '<path d="M6.6 17.6c1.4-1.2 2.8 1.2 4.2 0s2.8 1.2 4.2 0 2.8 1.2 4.2 0 2.8 1.2 4.2 0" stroke="#5cc45c" stroke-width="2.4" stroke-linecap="round" fill="none"/>' .
                '<g class="a k-hop"><path d="M7 15.6a9 7.4 0 0 1 18 0z" fill="#ffb347"/><path d="M7 15.6a9 7.4 0 0 1 5-6.6 12 12 0 0 0-1.6 6.6z" fill="#f2952f"/>' .
                '<ellipse cx="13" cy="11.6" rx=".9" ry=".5" fill="#fff3d6"/><ellipse cx="17.4" cy="10.6" rx=".9" ry=".5" fill="#fff3d6"/><ellipse cx="20.2" cy="12.6" rx=".9" ry=".5" fill="#fff3d6"/>' .
                '<path class="ol" d="M7 15.6a9 7.4 0 0 1 18 0z"/></g>',
            'social' =>
                '<g class="a k-pop"><path d="M3.6 7.6a3 3 0 0 1 3-3h10.2a3 3 0 0 1 3 3v5.2a3 3 0 0 1-3 3H10l-3.6 3v-3a3 3 0 0 1-2.8-3z" fill="#4aa3df"/>' .
                '<circle cx="8.4" cy="10.2" r="1" fill="#fff"/><circle cx="11.7" cy="10.2" r="1" fill="#fff"/><circle cx="15" cy="10.2" r="1" fill="#fff"/>' .
                '<path class="ol" d="M3.6 7.6a3 3 0 0 1 3-3h10.2a3 3 0 0 1 3 3v5.2a3 3 0 0 1-3 3H10l-3.6 3v-3a3 3 0 0 1-2.8-3z"/></g>' .
                '<g class="a k-pop s2"><path d="M12.6 16.6a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v5a3 3 0 0 1-3 3h-.4v3.2l-3.6-3.2h-6a3 3 0 0 1-3-3z" fill="#ffd34d"/>' .
                '<path class="ol" d="M12.6 16.6a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v5a3 3 0 0 1-3 3h-.4v3.2l-3.6-3.2h-6a3 3 0 0 1-3-3z"/>' .
                '<path d="M17.4 19.4h6.4" stroke="' . $N . '" stroke-width="2" stroke-linecap="round"/></g>',
            'plans' =>
                '<rect x="6.6" y="6" width="18.8" height="22" rx="2.2" fill="#fff3d6"/><path d="M6.6 6h4.6v22H8.8a2.2 2.2 0 0 1-2.2-2.2z" fill="#ffe2a8"/>' .
                '<rect class="ol" x="6.6" y="6" width="18.8" height="22" rx="2.2"/>' .
                '<rect x="11.6" y="3.8" width="8.8" height="4.4" rx="1.6" fill="#ff5a5f"/><rect class="ol" x="11.6" y="3.8" width="8.8" height="4.4" rx="1.6"/>' .
                '<path class="a k-tick" d="M9.6 13.6l1.6 1.6 3-3" fill="none" stroke="#20b2a6" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="8"/>' .
                '<path class="a k-tick s2" d="M9.6 20.6l1.6 1.6 3-3" fill="none" stroke="#20b2a6" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="8"/>' .
                '<path class="ol" d="M16.6 13.4h5.4M16.6 20.4h5.4"/>',
            'reservations' =>
                '<path class="a k-twinkle" d="M16 3.4l.8 1.8 1.8.8-1.8.8-.8 1.8-.8-1.8-1.8-.8 1.8-.8z" fill="#ffd34d"/>' .
                '<g class="a k-clinkl" style="transform-origin:50% 100%"><path d="M6.6 8.4h8l-.6 5.6a3.4 3.4 0 0 1-6.8 0z" fill="#ffffff"/><path d="M7.4 11.2h6.4l-.2 2.8a3.2 3.2 0 0 1-6 0z" fill="#ff5a5f"/>' .
                '<path class="ol" d="M6.6 8.4h8l-.6 5.6a3.4 3.4 0 0 1-6.8 0zM10.6 17.4v7.4M7.4 26.6h6.4"/></g>' .
                '<g class="a k-clinkr" style="transform-origin:50% 100%"><path d="M17.4 8.4h8l-.6 5.6a3.4 3.4 0 0 1-6.8 0z" fill="#ffffff"/><path d="M18.2 11.2h6.4l-.2 2.8a3.2 3.2 0 0 1-6 0z" fill="#ff5a5f"/>' .
                '<path class="ol" d="M17.4 8.4h8l-.6 5.6a3.4 3.4 0 0 1-6.8 0zM21.4 17.4v7.4M18.2 26.6h6.4"/></g>',
            'cart' =>
                '<g class="a k-bob s2"><circle cx="11.6" cy="11.4" r="3" fill="#ff5a5f"/><path d="M11.6 8.6c-.3-1 .2-2 1.2-2.4" stroke="#3a9a3a" stroke-width="1.6" stroke-linecap="round" fill="none"/><circle class="ol" cx="11.6" cy="11.4" r="3"/></g>' .
                '<g class="a k-bob"><path d="M18.6 6.6l3.4 7.6" stroke="#ff8c1a" stroke-width="3.2" stroke-linecap="round"/><path d="M18 6.4l-1.2-2M18.6 6.2l.2-2.4" stroke="#5cc45c" stroke-width="1.6" stroke-linecap="round"/></g>' .
                '<path d="M4.4 14.4h23.2l-2.4 12.2H6.8z" fill="#ffb347"/><path d="M4.4 14.4h5l-.8 12.2H6.8z" fill="#f2952f"/>' .
                '<path d="M8.6 18.4h14.8M9.2 22.4h13.6" stroke="#f2952f" stroke-width="1.6" stroke-linecap="round"/>' .
                '<path class="ol" d="M4.4 14.4h23.2l-2.4 12.2H6.8z"/>',
        ];

        $packs['cafe'] = [
            'home' =>
                '<path class="a k-rise" d="M12 9.4c-1.4-1.4 1.4-2.4 0-3.8" stroke="#a0aec0" stroke-width="1.8" stroke-linecap="round" fill="none"/>' .
                '<path class="a k-rise s2" d="M16 8.6c-1.4-1.4 1.4-2.4 0-3.8" stroke="#a0aec0" stroke-width="1.8" stroke-linecap="round" fill="none"/>' .
                '<path class="a k-rise s3" d="M20 9.4c-1.4-1.4 1.4-2.4 0-3.8" stroke="#a0aec0" stroke-width="1.8" stroke-linecap="round" fill="none"/>' .
                '<path d="M24 14.6h1.6a3.4 3.4 0 0 1 0 6.8H23" fill="none" stroke="' . $N . '" stroke-width="2.2"/>' .
                '<path d="M6.6 12h17.4v9.6a6 6 0 0 1-6 6H12.6a6 6 0 0 1-6-6z" fill="#ffffff"/><path d="M6.6 12h4.4v15.4a6 6 0 0 1-4.4-5.8z" fill="#eef1fd"/>' .
                '<path d="M6.6 16.6h17.4v4.6H6.6z" fill="#c98b5a"/><path d="M13 18.8l2.3-1 2.3 1-2.3 1z" fill="#fff3d6"/>' .
                '<path class="ol" d="M6.6 12h17.4v9.6a6 6 0 0 1-6 6H12.6a6 6 0 0 1-6-6z"/>',
            'menu' =>
                '<g class="a k-bob"><circle cx="17.4" cy="7.2" r="2.4" fill="#e3342f"/><path d="M17.4 4.8c.4-1.6 1.6-2.4 3-2.4" stroke="#3a9a3a" stroke-width="1.5" stroke-linecap="round" fill="none"/><circle class="ol" cx="17.4" cy="7.2" r="2.4"/></g>' .
                '<path d="M5 15.4 25.6 9.6l1.4 4.6L5 20.2z" fill="#ffe3ec"/>' .
                '<path d="M5 15.4v11.2h22V13.8z" fill="#f7c98b"/><path d="M5 19.4l22-4.2v3.6L5 22.6z" fill="#ff9fba"/>' .
                '<path d="M5 15.4v11.2h5V14z" fill="#e8ad6a"/>' .
                '<path class="ol" d="M5 15.4v11.2h22V13.8L25.6 9.6 5 15.4h22"/>',
            'social' =>
                '<path d="M5 13.4h4.4l9.8-6v17.2l-9.8-6H5z" fill="#ffd34d"/><path d="M5 13.4h4.4v5.2H5z" fill="#ffb020"/>' .
                '<path d="M8.2 18.6l1.6 6.4h3.4l-1.4-6.4" fill="#ff8c1a"/><path class="ol" d="M8.2 18.6l1.6 6.4h3.4l-1.4-6.4"/>' .
                '<path class="ol" d="M5 13.4h4.4l9.8-6v17.2l-9.8-6H5z"/>' .
                '<path class="a k-wave" d="M22.6 12.6a5 5 0 0 1 0 6.8" stroke="' . $N . '" stroke-width="2" stroke-linecap="round" fill="none"/>' .
                '<path class="a k-wave s2" d="M25.4 10a9 9 0 0 1 0 12" stroke="' . $N . '" stroke-width="2" stroke-linecap="round" fill="none"/>',
            'plans' =>
                '<g class="a k-shake">' .
                '<circle cx="9.4" cy="7.6" r="3" fill="#ff7fa0"/><circle cx="22.6" cy="7.6" r="3" fill="#ff7fa0"/><circle class="ol" cx="9.4" cy="7.6" r="3"/><circle class="ol" cx="22.6" cy="7.6" r="3"/>' .
                '<circle cx="16" cy="17.4" r="10" fill="#ffffff"/><path d="M16 7.4a10 10 0 0 0 0 20 13 13 0 0 1 0-20z" fill="#eef1fd"/>' .
                '<path class="ol" d="M16 11.4v6l3.6 2.4"/><circle class="ol" cx="16" cy="17.4" r="10"/>' .
                '<path class="ol" d="M9.4 26l-2 2.4M22.6 26l2 2.4"/></g>',
            'reservations' =>
                '<path class="a k-twinkle" d="M7.4 8.4l1.8 1.8M24.6 8.4l-1.8 1.8M16 4v2.4" stroke="#ffb020" stroke-width="2" stroke-linecap="round" fill="none"/>' .
                '<g class="a k-shake s2"><path d="M7.4 21.6a8.6 8.6 0 0 1 17.2 0z" fill="#ffd34d"/><path d="M7.4 21.6a8.6 8.6 0 0 1 5.4-8 12 12 0 0 0-1.4 8z" fill="#ffb020"/>' .
                '<path class="ol" d="M7.4 21.6a8.6 8.6 0 0 1 17.2 0zM16 13v-1.8M14.2 11.2h3.6"/></g>' .
                '<rect x="4.6" y="21.6" width="22.8" height="3.6" rx="1.4" fill="#c98b5a"/><rect class="ol" x="4.6" y="21.6" width="22.8" height="3.6" rx="1.4"/>',
            'cart' =>
                '<g class="a k-swayr" style="transform-origin:50% 100%"><path d="M17.6 4.2c2-1 3.8 0 4 1.8l-1.8 11h-5z" fill="#f2b56b"/><path d="M18.4 7.4l2 .4M17.8 10.4l2 .4M17.2 13.4l2 .4" stroke="#c98b5a" stroke-width="1.4" stroke-linecap="round"/>' .
                '<path class="ol" d="M17.6 4.2c2-1 3.8 0 4 1.8l-1.8 11h-5z"/></g>' .
                '<path d="M6.6 12.6h18.8l-1 14H7.6z" fill="#e8c39e"/><path d="M6.6 12.6h5l-.4 14H7.6z" fill="#d6a679"/>' .
                '<path d="M8.6 12.6l1.2 2.4 1.2-2.4M14.2 12.6l1.2 2.4 1.2-2.4M19.8 12.6l1.2 2.4 1.2-2.4" stroke="' . $N . '" stroke-width="1.4" fill="none" stroke-linejoin="round"/>' .
                '<path class="ol" d="M6.6 12.6h18.8l-1 14H7.6z"/>',
        ];

        $packs['fresh'] = [
            'home' =>
                '<g class="a k-swayl" style="transform-origin:100% 100%"><path d="M15.6 15.4C11.4 15 8.4 12 8 7.6c4.4.4 7.4 3.4 7.6 7.8z" fill="#5cc45c"/><path class="ol" d="M15.6 15.4C11.4 15 8.4 12 8 7.6c4.4.4 7.4 3.4 7.6 7.8zM15.4 15.2 11 10.6"/></g>' .
                '<g class="a k-swayr" style="transform-origin:0 100%"><path d="M16.4 15.4c4.2-.4 7.2-3.4 7.6-7.8-4.4.4-7.4 3.4-7.6 7.8z" fill="#3aa83a"/><path class="ol" d="M16.4 15.4c4.2-.4 7.2-3.4 7.6-7.8-4.4.4-7.4 3.4-7.6 7.8zM16.6 15.2l4.4-4.6"/></g>' .
                '<g class="a k-swayl s2" style="transform-origin:50% 100%"><path d="M16 15.6c-1.6-3-1.4-6.6 0-10 1.4 3.4 1.6 7 0 10z" fill="#8edc6a"/><path class="ol" d="M16 15.6c-1.6-3-1.4-6.6 0-10 1.4 3.4 1.6 7 0 10z"/></g>' .
                '<path d="M8.4 16h15.2l-1.8 11.4H10.2z" fill="#e98c5a"/><path d="M8.4 16h4.4l-.8 11.4h-1.8z" fill="#d06e3e"/>' .
                '<rect x="7.2" y="15.6" width="17.6" height="3.4" rx="1.2" fill="#f2a46a"/><rect class="ol" x="7.2" y="15.6" width="17.6" height="3.4" rx="1.2"/>' .
                '<path class="ol" d="M8.6 19l1.6 8.4h11.6l1.6-8.4"/>',
            'menu' =>
                '<g class="a k-hop"><path d="M9 14.6c-.6-2.6 1.2-4.6 3.6-4.2.8-2.6 4.2-3.2 5.6-.8 2.4-.8 4.8 1.2 4 4.4z" fill="#5cc45c"/>' .
                '<circle cx="13.4" cy="12.6" r="1.8" fill="#ff5a5f"/><circle cx="19.4" cy="12.2" r="1.4" fill="#ffd34d"/>' .
                '<path class="ol" d="M9 14.6c-.6-2.6 1.2-4.6 3.6-4.2.8-2.6 4.2-3.2 5.6-.8 2.4-.8 4.8 1.2 4 4.4"/></g>' .
                '<path d="M4.6 14.8h22.8a11.4 10.8 0 0 1-22.8 0z" fill="#ffffff"/><path d="M4.6 14.8h5c0 5 2 8.6 5.2 10.4A11.4 10.8 0 0 1 4.6 14.8z" fill="#eef1fd"/>' .
                '<path class="ol" d="M4.6 14.8h22.8a11.4 10.8 0 0 1-22.8 0zM11.6 27.4h8.8"/>',
            'social' =>
                '<g class="a k-pop"><path d="M10.6 14.4h-4v12h4z" fill="#5cc45c"/><path class="ol" d="M10.6 14.4h-4v12h4z"/>' .
                '<path d="M10.6 14.4l4.4-8a2.6 2.6 0 0 1 3.8 2.4l-.6 4.6h6.4a2.6 2.6 0 0 1 2.6 3l-1.4 7.4a2.6 2.6 0 0 1-2.6 2.2H10.6z" fill="#ffd8b0"/>' .
                '<path d="M10.6 14.4l4.4-8a2.6 2.6 0 0 1 1.6-1.2c-1 3-2.4 6-2.4 9.2v12H10.6z" fill="#f8c08c"/>' .
                '<path class="ol" d="M10.6 14.4l4.4-8a2.6 2.6 0 0 1 3.8 2.4l-.6 4.6h6.4a2.6 2.6 0 0 1 2.6 3l-1.4 7.4a2.6 2.6 0 0 1-2.6 2.2H10.6z"/></g>' .
                '<path class="a k-twinkle" d="M24.6 4.6l.7 1.5 1.5.7-1.5.7-.7 1.5-.7-1.5-1.5-.7 1.5-.7z" fill="#ffd34d"/>',
            'plans' =>
                '<rect x="5" y="7" width="22" height="20" rx="3" fill="#ffffff"/><path d="M5 21h22v3a3 3 0 0 1-3 3H8a3 3 0 0 1-3-3z" fill="#eaf8e4"/>' .
                '<path d="M5 13v-3a3 3 0 0 1 3-3h16a3 3 0 0 1 3 3v3z" fill="#5cc45c"/>' .
                '<g class="a k-swayl" style="transform-origin:0 100%"><path d="M13.2 22.6c0-3.6 2.8-6 6.6-6-.2 3.8-2.8 6.2-6.6 6z" fill="#8edc6a"/><path class="ol" d="M13.2 22.6c0-3.6 2.8-6 6.6-6-.2 3.8-2.8 6.2-6.6 6zM13.2 22.6l3.4-3.4"/></g>' .
                '<rect class="ol" x="5" y="7" width="22" height="20" rx="3"/><path class="ol" d="M5 13h22M11 4.6v4.8M21 4.6v4.8"/>',
            'reservations' =>
                '<g class="a k-swayl" style="transform-origin:50% 100%"><circle cx="16" cy="6.8" r="2.4" fill="#ff7fa0"/><circle cx="13.4" cy="8.4" r="1.8" fill="#ff9fba"/><circle cx="18.6" cy="8.4" r="1.8" fill="#ff9fba"/><circle cx="16" cy="7.6" r="1.2" fill="#ffd34d"/>' .
                '<path class="ol" d="M16 10.4v3.4"/></g>' .
                '<path d="M12.6 13.6h6.8l-.8 4.6h-5.2z" fill="#9fd8f5"/><path class="ol" d="M12.6 13.6h6.8l-.8 4.6h-5.2z"/>' .
                '<path d="M4.4 18.2h23.2l-1.6 3.6H6z" fill="#8edc6a"/><path d="M4.4 18.2h6l-1 3.6H6z" fill="#5cc45c"/><path class="ol" d="M4.4 18.2h23.2l-1.6 3.6H6z"/>' .
                '<path class="ol" d="M8.6 21.8v6M23.4 21.8v6"/>',
            'cart' =>
                '<g class="a k-drop"><path d="M16 4.4c-3.2-1.6-6 .6-5.4 3.8.4 2.6 2.6 4.4 5.4 3.6 2.8.8 5-1 5.4-3.6.6-3.2-2.2-5.4-5.4-3.8z" fill="#ff5a5f"/>' .
                '<path d="M16 4.4c-.2-1.4.6-2.4 2-2.6" stroke="' . $N . '" stroke-width="1.6" stroke-linecap="round" fill="none"/>' .
                '<path class="ol" d="M16 4.4c-3.2-1.6-6 .6-5.4 3.8.4 2.6 2.6 4.4 5.4 3.6 2.8.8 5-1 5.4-3.6.6-3.2-2.2-5.4-5.4-3.8z"/></g>' .
                '<path class="ol" d="M8.6 14.6c0-3.6 3.2-5.2 7.4-5.2s7.4 1.6 7.4 5.2"/>' .
                '<path d="M4.4 14.6h23.2l-2.4 12H6.8z" fill="#c98b5a"/><path d="M4.4 14.6h5l-.8 12H6.8z" fill="#a8704a"/>' .
                '<path d="M8.4 18.6h15.2M9 22.6h14" stroke="#a8704a" stroke-width="1.6" stroke-linecap="round"/>' .
                '<path class="ol" d="M4.4 14.6h23.2l-2.4 12H6.8z"/>',
        ];

        $packs['fiesta'] = [
            'home' =>
                '<g class="a k-bob"><path d="M3.6 9.4h15v13.2H3.6z" fill="#ffb020"/><path d="M3.6 9.4h4v13.2h-4z" fill="#ff8c1a"/>' .
                '<path d="M18.6 13.4h5.2l4.6 4.8v4.4H18.6z" fill="#ff5a5f"/><path d="M19.8 14.6h3.4l3 3.4h-6.4z" fill="#9fd8f5"/>' .
                '<rect x="6.6" y="12" width="8.8" height="5" rx="1" fill="#fff3d6"/><path d="M6.6 12h8.8v1.8H6.6z" fill="#ff5a5f"/>' .
                '<path class="ol" d="M3.6 22.6V9.4h15v13.2M18.6 13.4h5.2l4.6 4.8v4.4H3.6M19.8 14.6h3.4l3 3.4h-6.4z"/><rect class="ol" x="6.6" y="12" width="8.8" height="5" rx="1"/></g>' .
                '<circle cx="9" cy="24.6" r="2.6" fill="#ffffff"/><circle cx="23.2" cy="24.6" r="2.6" fill="#ffffff"/><circle class="ol" cx="9" cy="24.6" r="2.6"/><circle class="ol" cx="23.2" cy="24.6" r="2.6"/>',
            'menu' =>
                '<path d="M5 8.6 16 27.4 27 8.6z" fill="#ffd34d"/><path d="M5 8.6l11 18.8V11.8z" fill="#ffc31a"/>' .
                '<path d="M4 6.4a28 28 0 0 1 24 0l-1 2.4a26 26 0 0 0-22 0z" fill="#e98c5a"/><path class="ol" d="M4 6.4a28 28 0 0 1 24 0l-1 2.4a26 26 0 0 0-22 0z"/>' .
                '<circle cx="12.4" cy="13" r="1.8" fill="#e3342f"/><circle cx="19" cy="14.6" r="1.8" fill="#e3342f"/><circle cx="15.4" cy="19.6" r="1.6" fill="#e3342f"/>' .
                '<path class="a k-drip" style="transform-origin:50% 0" d="M20.6 15.6h2.4v3a1.2 1.2 0 0 1-2.4 0z" fill="#ffd34d"/>' .
                '<path class="ol" d="M5 8.6 16 27.4 27 8.6"/>',
            'social' =>
                '<g class="a k-pop"><path d="M16 4.4l3.4 7 7.6 1.1-5.5 5.4 1.3 7.6L16 21.9l-6.8 3.6 1.3-7.6L5 12.5l7.6-1.1z" fill="#ffd34d"/>' .
                '<path d="M16 4.4v17.5l-6.8 3.6 1.3-7.6L5 12.5l7.6-1.1z" fill="#ffc31a"/>' .
                '<path class="ol" d="M16 4.4l3.4 7 7.6 1.1-5.5 5.4 1.3 7.6L16 21.9l-6.8 3.6 1.3-7.6L5 12.5l7.6-1.1z"/></g>' .
                '<path class="a k-twinkle" d="M26.4 3.4l.6 1.3 1.3.6-1.3.6-.6 1.3-.6-1.3-1.3-.6 1.3-.6z" fill="#ff7fa0"/>' .
                '<path class="a k-twinkle s2" d="M5 23.6l.6 1.3 1.3.6-1.3.6-.6 1.3-.6-1.3-1.3-.6 1.3-.6z" fill="#9b7bff"/>',
            'plans' =>
                '<g class="a k-swayl" style="transform-origin:50% 50%"><path d="M4 9.6h24v4a2.4 2.4 0 0 0 0 4.8v4a0 0 0 0 1 0 0H4v-4a2.4 2.4 0 0 0 0-4.8z" fill="#9b7bff"/>' .
                '<path d="M20.6 9.6H28v4a2.4 2.4 0 0 0 0 4.8v4h-7.4z" fill="#7c5cf0"/>' .
                '<path d="M20.6 10.6v11" stroke="#fff" stroke-width="1.6" stroke-dasharray="1.6 1.6"/>' .
                '<path d="M12.2 12.6l1.1 2.3 2.5.4-1.8 1.7.4 2.5-2.2-1.2-2.2 1.2.4-2.5-1.8-1.7 2.5-.4z" fill="#ffd34d"/>' .
                '<path class="ol" d="M4 9.6h24v4a2.4 2.4 0 0 0 0 4.8v4H4v-4a2.4 2.4 0 0 0 0-4.8z"/></g>',
            'reservations' =>
                '<g class="a k-float"><ellipse cx="11.4" cy="10" rx="4.6" ry="5.6" fill="#ff5a5f"/><path d="M9.2 7.6a2.6 2.6 0 0 1 2-1.6" stroke="#fff" stroke-width="1.4" stroke-linecap="round" fill="none"/>' .
                '<ellipse class="ol" cx="11.4" cy="10" rx="4.6" ry="5.6"/><path class="ol" d="M11.4 15.6c-.8 3 1.2 5-.4 8.6"/></g>' .
                '<g class="a k-float s2"><ellipse cx="20.6" cy="12" rx="4.6" ry="5.6" fill="#9b7bff"/><path d="M18.4 9.6a2.6 2.6 0 0 1 2-1.6" stroke="#fff" stroke-width="1.4" stroke-linecap="round" fill="none"/>' .
                '<ellipse class="ol" cx="20.6" cy="12" rx="4.6" ry="5.6"/><path class="ol" d="M20.6 17.6c.8 3-1.2 5 .4 8.6"/></g>',
            'cart' =>
                '<rect class="a k-drop" x="14.4" y="3.4" width="6" height="5.4" rx="1" fill="#9b7bff"/>' .
                '<g class="a k-roll"><path d="M9.4 10.6h17l-2.6 9.6H12z" fill="#ffd34d"/><path d="M9.4 10.6h4.4l-.4 9.6H12z" fill="#ffc31a"/>' .
                '<path class="ol" d="M4.4 6.6h3.4l4.2 13.6h12.2M9.4 10.6h17l-2.6 9.6"/>' .
                '<circle cx="13" cy="24.6" r="2" fill="#ffffff"/><circle cx="22.4" cy="24.6" r="2" fill="#ffffff"/><circle class="ol" cx="13" cy="24.6" r="2"/><circle class="ol" cx="22.4" cy="24.6" r="2"/></g>',
        ];

        return isset($packs[$pack]) ? $packs[$pack] + $shared : [];
    }
}
