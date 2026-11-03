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
