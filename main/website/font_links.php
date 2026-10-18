<?php
/**
 * Self-hosted fonts and icon sets for the customer website.
 *
 * Poppins / Inter / Material Symbols, Font Awesome 6.5.0 and Bootstrap Icons
 * 1.11.3 live in main/assets/fonts instead of Google Fonts / cdnjs / jsDelivr.
 * Loading them from our own server (cached for a year, see
 * assets/fonts/.htaccess) is what stops the "page shows unstyled for 1-2s /
 * icons pop in" glitch on refresh and on slow connections: the browser no
 * longer waits on three third-party servers before it can draw icons and text.
 *
 * Usage inside <head>:  <?php echo websiteFontLinks(['poppins', 'fontawesome']); ?>
 */

if (!function_exists('websiteFontsBaseUrl')) {
    /** Absolute URL path of main/assets/fonts (works locally, on restrogrow.com and on custom domains). */
    function websiteFontsBaseUrl(): string {
        // Every customer page runs from main/website/, so two levels up is main/.
        $mainDir = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/main/website/index.php'))), '/');
        return $mainDir . '/assets/fonts';
    }

    /**
     * <link> tags for the requested font/icon sets, plus preloads for the font
     * files every page needs immediately (Poppins regular/semibold, FA solid).
     *
     * @param string[] $sets any of: poppins, inter, material-symbols, fontawesome, bootstrap-icons
     */
    function websiteFontLinks(array $sets): string {
        static $files = [
            'poppins'          => 'google/poppins.css',
            'inter'            => 'google/inter.css',
            'material-symbols' => 'google/material-symbols-rounded.css',
            'fontawesome'      => 'fontawesome/css/all.min.css',
            'bootstrap-icons'  => 'bootstrap-icons/bootstrap-icons.min.css',
        ];
        static $preloads = [
            'poppins'          => ['google/files/poppins-e6a077c34d.woff2', 'google/files/poppins-93818c3798.woff2'],
            'inter'            => ['google/files/inter-6ab57b19c6.woff2'],
            // Material Symbols (373 KB) and Bootstrap Icons (130 KB) are not preloaded: pages use only a few of
            // their icons, and a font that isn't preloaded is downloaded only when the page really shows one
            'fontawesome'      => ['fontawesome/webfonts/fa-solid-900.woff2'],
        ];
        $base = websiteFontsBaseUrl();
        $dir  = __DIR__ . '/../assets/fonts/';
        $out  = '';
        foreach ($sets as $set) {
            foreach ($preloads[$set] ?? [] as $font) {
                $out .= '<link rel="preload" href="' . htmlspecialchars("$base/$font") . '" as="font" type="font/woff2" crossorigin>' . "\n";
            }
        }
        foreach ($sets as $set) {
            if (!isset($files[$set])) continue;
            $v = @filemtime($dir . $files[$set]) ?: 1;
            $out .= '<link rel="stylesheet" href="' . htmlspecialchars("$base/{$files[$set]}?v=$v") . '">' . "\n";
        }
        return $out;
    }
}
