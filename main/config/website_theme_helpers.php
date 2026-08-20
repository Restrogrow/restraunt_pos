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
        'install' => 'download',
    ],
    'storefront' => [
        'label' => 'Storefront',
        'home' => 'store', 'menu' => 'utensils', 'social' => 'comments',
        'plans' => 'calendar-check', 'cart' => 'shopping-bag', 'profile' => 'user-circle',
        'install' => 'download',
    ],
    'foodie' => [
        'label' => 'Foodie',
        'home' => 'home', 'menu' => 'pizza-slice', 'social' => 'share-alt',
        'plans' => 'calendar-check', 'cart' => 'cart-plus', 'profile' => 'user',
        'install' => 'download',
    ],
    'minimal' => [
        'label' => 'Minimal',
        'home' => 'house', 'menu' => 'list', 'social' => 'comment-dots',
        'plans' => 'calendar-check', 'cart' => 'bag-shopping', 'profile' => 'circle-user',
        'install' => 'arrow-down-to-line',
    ],
    'bold' => [
        'label' => 'Bold',
        'home' => 'home', 'menu' => 'bowl-food', 'social' => 'share-nodes',
        'plans' => 'calendar-days', 'cart' => 'cart-shopping', 'profile' => 'user-circle',
        'install' => 'download',
    ],
];

/**
 * Default bottom-nav text labels (Home / Menu / Social / Plans / Reserve /
 * Cart / Profile). Restaurants can override any of these in Website
 * Appearance (e.g. rename "Menu" to "Items") — stored as JSON in the
 * nav_labels column, merged over these defaults so missing/blank keys fall
 * back to the original wording.
 */
const DEFAULT_NAV_LABELS = [
    'home' => 'Home',
    'menu' => 'Menu',
    'social' => 'Social',
    'plans' => 'Plans',
    'reservations' => 'Reserve',
    'cart' => 'Cart',
    'profile' => 'Profile',
    'install' => 'Install',
];

/**
 * Merge a stored nav_labels JSON string (may be null/invalid) over
 * DEFAULT_NAV_LABELS, so every slot always has a usable label.
 */
function getNavLabels(?string $json): array {
    $labels = DEFAULT_NAV_LABELS;
    if ($json) {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            foreach (DEFAULT_NAV_LABELS as $slot => $default) {
                if (!empty($decoded[$slot]) && is_string($decoded[$slot])) {
                    $labels[$slot] = $decoded[$slot];
                }
            }
        }
    }
    return $labels;
}

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
    // nav_labels — JSON override of the bottom nav's text labels (Home/Menu/
    // Social/Plans/Reserve/Cart/Profile). NULL means "use the defaults",
    // matching the nav_icon_style pattern above.
    try {
        $conn->query("SELECT nav_labels FROM website_settings LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE website_settings ADD COLUMN nav_labels TEXT DEFAULT NULL"); } catch (PDOException $e2) {}
    }
    // favicon_url — optional override of the browser-tab icon (and PWA/
    // apple-touch icon) shown for the customer website. NULL means "use the
    // uploaded restaurant logo, or the built-in placeholder if none".
    try {
        $conn->query("SELECT favicon_url FROM website_settings LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE website_settings ADD COLUMN favicon_url VARCHAR(500) DEFAULT NULL"); } catch (PDOException $e2) {}
    }
    // nav_icons_custom — JSON map of per-slot uploaded icon images (data URLs)
    // for the bottom nav, e.g. {"menu":"data:image/png;base64,..."}. A slot
    // with no entry here just uses the picked NAV_ICON_STYLES icon as before;
    // this is a per-slot override on top of that, not a replacement for it.
    try {
        $conn->query("SELECT nav_icons_custom FROM website_settings LIMIT 1");
    } catch (PDOException $e) {
        try { $conn->exec("ALTER TABLE website_settings ADD COLUMN nav_icons_custom LONGTEXT DEFAULT NULL"); } catch (PDOException $e2) {}
    }
}

/**
 * Decode a stored nav_icons_custom JSON string (may be null/invalid) into an
 * associative array of slot => image URL, filtered to known nav slots only.
 */
function getNavIconOverrides(?string $json): array {
    if (!$json) return [];
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach (DEFAULT_NAV_LABELS as $slot => $default) {
        if (!empty($decoded[$slot]) && is_string($decoded[$slot])) {
            $out[$slot] = $decoded[$slot];
        }
    }
    return $out;
}

/**
 * Sanitize a favicon/image URL: only allow http(s):// or data:image/* URLs,
 * strip control characters, and cap length. Returns null for anything else
 * (including blank input), which callers treat as "no override".
 * Mirrors theme_api.php's sanitizeImageUrl() but kept here so header.php
 * (which doesn't load theme_api.php) can reuse the same validation.
 */
function sanitizeFaviconUrl(?string $url): ?string {
    if ($url === null) return null;
    $url = trim($url);
    if ($url === '') return null;
    $url = str_replace(["\0", "\r", "\n", "\t"], '', $url);
    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0 || strpos($url, 'data:image/') === 0) {
        return mb_substr($url, 0, 500);
    }
    return null;
}

/**
 * Render one bottom-nav icon: an uploaded custom image if the restaurant set
 * one for this slot (via nav_icons_custom), otherwise the Font Awesome icon
 * from the picked NAV_ICON_STYLES set. Both cases carry the same
 * data-nav-slot attribute so applyLivePreview()'s live-preview swap and any
 * CSS targeting [data-nav-slot] keep working regardless of which is shown.
 */
function renderNavIconTag(string $slot, array $navIcons, array $navIconOverrides, bool $navItemLayout = true): string {
    $imgSize = $navItemLayout ? 24 : 16;
    $imgLayoutStyle = $navItemLayout ? 'display:block;margin:0 auto;' : 'display:inline-block;vertical-align:middle;';
    if (!empty($navIconOverrides[$slot])) {
        $src = htmlspecialchars($navIconOverrides[$slot], ENT_QUOTES, 'UTF-8');
        return '<img src="' . $src . '" class="' . ($navItemLayout ? 'nav-icon' : '') . '" data-nav-slot="' . $slot . '" alt="" style="width:' . $imgSize . 'px;height:' . $imgSize . 'px;object-fit:contain;' . $imgLayoutStyle . '">';
    }
    $iconName = htmlspecialchars($navIcons[$slot] ?? '', ENT_QUOTES, 'UTF-8');
    $iconClass = 'fa fa-' . $iconName . ($navItemLayout ? ' nav-icon' : '');
    return '<i class="' . $iconClass . '" data-nav-slot="' . $slot . '"></i>';
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
