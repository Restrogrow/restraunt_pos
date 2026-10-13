<?php
/**
 * Icon font link for every dashboard (Material Symbols Rounded).
 *
 * Only the icons RestroGrow uses are downloaded (about 40 KB instead of 373 KB), so the dashboard
 * opens fast on slow networks and Chrome no longer warns "Slow network is detected".
 * The names are in config/icon_names.txt (comma separated, alphabetical).
 *
 * Adding a new icon? Put its name in icon_names.txt (keep the list alphabetical). On localhost the
 * console warns about any icon on the page that is missing from the list. Google accepts about 450
 * names in one link.
 */
function rgIconFontUrl(): string
{
    static $url = null;
    if ($url !== null) return $url;
    $names = @file_get_contents(__DIR__ . '/icon_names.txt');
    $list = $names ? array_values(array_unique(array_filter(array_map('trim', explode(',', $names))))) : [];
    sort($list, SORT_STRING);
    $url = 'https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0'
        . ($list ? '&icon_names=' . implode(',', $list) : '') . '&display=swap';
    return $url;
}
