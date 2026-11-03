<?php
/**
 * Icon font link for every dashboard (Material Symbols Rounded).
 *
 * Only the icons RestroGrow uses are downloaded (about 40 KB instead of 373 KB), so the dashboard
 * opens fast on slow networks and Chrome no longer warns "Slow network is detected".
 * The names are in config/icon_names.txt (comma separated, alphabetical).
 *
 * Adding a new icon? Put its name in icon_names.txt (keep the list alphabetical). On localhost the
 * console warns about any icon on the page that is missing from the list. Google only subsets when
 * the link stays under about 4,200 characters (~425 names); past that it silently sends the full
 * 373 KB font, so reuse an icon already in the list when you can.
 */
function rgIconFontUrl(): string
{
    static $url = null;
    if ($url !== null) return $url;
    $names = @file_get_contents(__DIR__ . '/icon_names.txt');
    $list = $names ? array_values(array_unique(array_filter(array_map('trim', explode(',', $names))))) : [];
    sort($list, SORT_STRING);
    // display=block, not swap: an icon here is the word inside the span ("space_dashboard"), so
    // swap paints that word in the page font until the icon font lands — nav items read like
    // variable names for as long as the download takes. block keeps the space empty instead.
    // (main/assets/fonts/google/material-symbols-rounded.css, the customer site's copy, does the same.)
    $url = 'https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0'
        . ($list ? '&icon_names=' . implode(',', $list) : '') . '&display=block';
    return $url;
}

/**
 * The icon font, and the guard that stops its names being read as words.
 *
 * Every icon is the word inside its span — <span class="material-symbols-rounded">space_dashboard</span>.
 * The stylesheet above is loaded without holding up the first paint, so until it arrives the span has
 * no icon font on it at all and the browser draws that word in the page font: the dashboard fills with
 * point_of_sale, receipt_long, account_balance_wallet, local_offer… which read like variable names.
 * font-display only helps once the stylesheet is there, so it can't cover this gap; on a blocked or
 * very slow fonts.googleapis.com the words simply stay.
 *
 * So hide the text until the font is really in use, and leave it hidden if it never arrives: an icon
 * that didn't load shows as a gap next to its label, never as a word. The guard is added from script,
 * so a browser with JavaScript off never gets it and keeps today's behaviour.
 */
function rgIconFontTags(): string
{
    $url = htmlspecialchars(rgIconFontUrl(), ENT_QUOTES);
    return '<link rel="preload" href="' . $url . '" as="style" onload="this.onload=null;this.rel=\'stylesheet\'">'
        . '<noscript><link rel="stylesheet" href="' . $url . '"></noscript>'
        . '<script>(function(){'
        . 'if(!document.fonts||!document.fonts.load)return;'
        . 'var g=document.createElement("style");'
        . 'g.textContent=".material-symbols-rounded,.rgnav-ms{visibility:hidden}";'
        . 'document.head.appendChild(g);'
        . 'var n=0,f="24px \'Material Symbols Rounded\'";'
        . '(function check(){document.fonts.load(f).then(function(a){'
        . 'if(a.length){if(g.parentNode)g.parentNode.removeChild(g);return;}'   // font in use: show the icons
        . 'if(++n<100)setTimeout(check,100);'                                   // stylesheet still on its way
        . '}).catch(function(){if(++n<100)setTimeout(check,100);});})();'
        . '})();</script>';
}
