<?php
/**
 * Website theme presets: bundles a color palette + font + card/button shape
 * into a one-click "theme" so restaurants don't all end up looking like the
 * same site with a different accent color. Layered on top of the existing
 * website_settings color/font columns — a preset just pre-fills them.
 */

if (defined('WEBSITE_THEME_HELPERS_LOADED')) {
    return;
}
define('WEBSITE_THEME_HELPERS_LOADED', true);

const THEME_PRESETS = [
    'classic' => [
        'label' => 'Classic Red',
        'primary_red' => '#F70000',
        'dark_red' => '#DA020E',
        'primary_yellow' => '#FFD100',
        'font_family' => 'Poppins',
        'card_style' => 'rounded',
        'layout_style' => 'grid',
        'header_style' => 'hero',
    ],
    'midnight' => [
        'label' => 'Midnight',
        'primary_red' => '#16213E',
        'dark_red' => '#0F1730',
        'primary_yellow' => '#00C2A8',
        'font_family' => 'Montserrat',
        'card_style' => 'sharp',
        'layout_style' => 'list',
        'header_style' => 'minimal',
    ],
    'sunset' => [
        'label' => 'Sunset',
        'primary_red' => '#FF6B35',
        'dark_red' => '#C2410C',
        'primary_yellow' => '#FFC145',
        'font_family' => 'Nunito',
        'card_style' => 'pill',
        'layout_style' => 'magazine',
        'header_style' => 'hero',
    ],
    'emerald' => [
        'label' => 'Emerald',
        'primary_red' => '#1B5E3D',
        'dark_red' => '#0F3D28',
        'primary_yellow' => '#F4E1C1',
        'font_family' => 'Playfair Display',
        'card_style' => 'rounded',
        'layout_style' => 'list',
        'header_style' => 'hero',
    ],
    'ocean' => [
        'label' => 'Ocean',
        'primary_red' => '#0B6E99',
        'dark_red' => '#08526F',
        'primary_yellow' => '#FF7E5F',
        'font_family' => 'Roboto',
        'card_style' => 'rounded',
        'layout_style' => 'magazine',
        'header_style' => 'minimal',
    ],
];

const THEME_CARD_STYLES = ['rounded', 'sharp', 'pill'];
const THEME_LAYOUT_STYLES = ['grid', 'list', 'magazine'];
const THEME_HEADER_STYLES = ['hero', 'minimal'];

/**
 * Bottom-nav icon sets: swaps the Font Awesome classes used for the Home /
 * Menu / Social / Plans / Cart / Profile icons in the customer website's
 * bottom navigation bar, so restaurants aren't all stuck with the same
 * fa-home/fa-utensils/... icons. Icon names are Font Awesome 6 Free Solid
 * (loaded site-wide via the "fa fa-*" classes already used in index.php).
 */
const NAV_ICON_STYLES = [
    'classic' => [
        'label' => 'Classic',
        'home' => 'home', 'menu' => 'utensils', 'social' => 'share-alt',
        'plans' => 'calendar-check', 'cart' => 'shopping-cart', 'profile' => 'user',
    ],
    'storefront' => [
        'label' => 'Storefront',
        'home' => 'store', 'menu' => 'utensils', 'social' => 'comments',
        'plans' => 'calendar-check', 'cart' => 'shopping-bag', 'profile' => 'user-circle',
    ],
    'foodie' => [
        'label' => 'Foodie',
        'home' => 'home', 'menu' => 'pizza-slice', 'social' => 'share-alt',
        'plans' => 'calendar-check', 'cart' => 'cart-plus', 'profile' => 'user',
    ],
    'minimal' => [
        'label' => 'Minimal',
        'home' => 'house', 'menu' => 'list', 'social' => 'comment-dots',
        'plans' => 'calendar-check', 'cart' => 'bag-shopping', 'profile' => 'circle-user',
    ],
    'bold' => [
        'label' => 'Bold',
        'home' => 'home', 'menu' => 'bowl-food', 'social' => 'share-nodes',
        'plans' => 'calendar-days', 'cart' => 'cart-shopping', 'profile' => 'user-circle',
    ],
];

/**
 * Self-healing schema addition, matching the pattern used by
 * ensureGrowthSchema() — safe to call on every request.
 */
function ensureWebsiteThemeSchema(PDO $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $conn->query("SELECT theme_preset FROM website_settings LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE website_settings ADD COLUMN theme_preset VARCHAR(30) DEFAULT NULL"); } catch (PDOException $e2) {}
    }

    try {
        $conn->query("SELECT card_style FROM website_settings LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE website_settings ADD COLUMN card_style VARCHAR(10) DEFAULT 'rounded'"); } catch (PDOException $e2) {}
    }

    // checkout_color — NULL means "use the restaurant's Primary Color",
    // matching the Checkout button's pre-existing look so nothing changes
    // visually until an admin explicitly picks a different Checkout Color.
    try {
        $conn->query("SELECT checkout_color FROM website_settings LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE website_settings ADD COLUMN checkout_color VARCHAR(20) DEFAULT NULL"); } catch (PDOException $e2) {}
    }

    // layout_style — how the menu page's product cards are arranged
    // (grid/list/magazine). header_style — hero banner vs a compact minimal
    // bar on the homepage. Both default to the site's original look.
    try {
        $conn->query("SELECT layout_style FROM website_settings LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE website_settings ADD COLUMN layout_style VARCHAR(10) DEFAULT 'grid'"); } catch (PDOException $e2) {}
    }
    try {
        $conn->query("SELECT header_style FROM website_settings LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE website_settings ADD COLUMN header_style VARCHAR(10) DEFAULT 'hero'"); } catch (PDOException $e2) {}
    }

    // site_name — optional override of the restaurant's account name for
    // customer-facing display only (title, hero heading, footer). NULL means
    // "use the account restaurant_name", matching the checkout_color pattern
    // above so nothing changes until an admin explicitly sets one.
    try {
        $conn->query("SELECT site_name FROM website_settings LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE website_settings ADD COLUMN site_name VARCHAR(191) DEFAULT NULL"); } catch (PDOException $e2) {}
    }
    // nav_icon_style — id of a NAV_ICON_STYLES preset for the bottom nav's
    // icon set. Defaults to 'classic' (today's fixed icons), so nothing
    // changes until an admin explicitly picks another style.
    try {
        $conn->query("SELECT nav_icon_style FROM website_settings LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE website_settings ADD COLUMN nav_icon_style VARCHAR(20) DEFAULT 'classic'"); } catch (PDOException $e2) {}
    }
}

/**
 * Darken (negative percent) or lighten (positive percent) a #RRGGBB hex
 * color. Mirrors the client-side shadeColor() in script.js, used there to
 * auto-derive the Primary Color's gradient shade — used here server-side to
 * do the same for the Checkout Color's gradient shade without needing a
 * separate stored column for it.
 */
function shadeHexColor(string $hex, int $percent): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        return '#DA020E';
    }
    $num = hexdec($hex);
    $amt = (int)round(2.55 * $percent);
    $r = max(0, min(255, ($num >> 16) + $amt));
    $g = max(0, min(255, (($num >> 8) & 0xFF) + $amt));
    $b = max(0, min(255, ($num & 0xFF) + $amt));
    return sprintf('#%02X%02X%02X', $r, $g, $b);
}

/**
 * Map a card_style value to the CSS custom property values that control
 * card corner radius and button/pill shape across the customer site.
 * Unknown/missing values fall back to 'rounded' (matches the site's
 * original look, so existing restaurants see no visual change).
 */
function getCardStyleRadii(?string $cardStyle): array {
    switch ($cardStyle) {
        case 'sharp':
            return ['card_radius' => '4px', 'btn_radius' => '4px'];
        case 'pill':
            return ['card_radius' => '20px', 'btn_radius' => '999px'];
        case 'rounded':
        default:
            return ['card_radius' => '16px', 'btn_radius' => '12px'];
    }
}
