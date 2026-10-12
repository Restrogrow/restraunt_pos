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
const THEME_HEADER_STYLES = ['hero', 'minimal', 'glass', 'cover', 'card', 'brand', 'poster', 'split', 'arch', 'story', 'neon', 'paper', 'profile', 'aurora', 'stack'];
// Header styles that use the card layout (index.php .hx): photo/colour + logo, name, tagline, hours, rating
const THEME_HEADER_CARD_STYLES = ['glass', 'cover', 'card', 'brand', 'poster', 'split', 'arch', 'story', 'neon', 'paper', 'profile', 'aurora', 'stack'];
// Card headers on a light background (dark text), and ones that don't use the photo at all
const THEME_HEADER_LIGHT_STYLES = ['card', 'split', 'arch', 'paper', 'profile', 'stack'];
const THEME_HEADER_NO_PHOTO_STYLES = ['brand', 'paper', 'aurora'];
// Menu page category navigation: left sidebar or a scrolling bar across the top
const THEME_MENU_NAV_POSITIONS = ['left', 'top'];
// Menu page dish card design: the original cards, or the modern card (wide photo,
// description, quantity picker + Add button)
// Animated offer banners (Website Appearance > Offer Banners): made in HTML/CSS, no photo needed
const OFFER_BANNER_TEMPLATES = ['burst', 'ticket', 'neon', 'confetti', 'marquee', 'split', 'countdown', 'aurora', 'stamp', 'bubbles', 'stripes',
    'spotlight', 'gift', 'tag', 'wave', 'orbit', 'typewriter', 'gold', 'pulse', 'bounce', 'polaroid', 'festive',
    'flash', 'scratch', 'hearts', 'snow', 'sunrise', 'cinema'];
// Per-banner look options (stored as JSON in website_offer_banners.opts)
const OFFER_BANNER_SPEEDS = ['calm', 'normal', 'lively', 'off'];
const OFFER_BANNER_FONTS = ['modern', 'serif', 'rounded', 'condensed'];
const OFFER_BANNER_SHAPES = ['circle', 'rounded', 'square'];

/** Keeps only known option keys with valid values. */
function cleanOfferBannerOpts($in) {
    $in = is_array($in) ? $in : [];
    $o = [];
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $in['accent'] ?? '')) $o['accent'] = $in['accent'];
    $k = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($in['kicker'] ?? ''))));
    if ($k !== '') $o['kicker'] = mb_substr($k, 0, 30);
    if (in_array($in['speed'] ?? '', OFFER_BANNER_SPEEDS, true)) $o['speed'] = $in['speed'];
    if (in_array($in['font'] ?? '', OFFER_BANNER_FONTS, true)) $o['font'] = $in['font'];
    if (in_array($in['shape'] ?? '', OFFER_BANNER_SHAPES, true)) $o['shape'] = $in['shape'];
    if (in_array($in['text'] ?? '', ['light', 'dark'], true)) $o['text'] = $in['text'];
    if (!empty($in['countdown'])) $o['countdown'] = 1;
    return $o;
}

/** website_offer_banners table, created once. */
function ensureOfferBannersTable($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS website_offer_banners (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            template VARCHAR(20) NOT NULL DEFAULT 'burst',
            title VARCHAR(40) NOT NULL,
            subtitle VARCHAR(90) NULL,
            code VARCHAR(30) NULL,
            cta VARCHAR(24) NULL,
            color VARCHAR(7) NOT NULL DEFAULT '#e11d48',
            menu_item_id INT NULL,
            placement VARCHAR(20) NOT NULL DEFAULT 'home_top',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            display_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_offer_banners_rid (restaurant_id)
        )");
        // Look options + optional end time (unix seconds; the banner hides itself after it)
        foreach (['opts' => 'TEXT NULL', 'ends_ts' => 'INT NULL'] as $col => $def) {
            $has = $conn->query("SHOW COLUMNS FROM website_offer_banners LIKE '$col'");
            if (!($has && $has->fetch())) $conn->exec("ALTER TABLE website_offer_banners ADD COLUMN $col $def");
        }
    } catch (PDOException $e) {
        error_log('ensureOfferBannersTable: ' . $e->getMessage());
    }
}

/** Offer banners with the chosen dish's photo reference; $activeOnly for the customer website. */
function fetchOfferBanners($conn, $restaurant_id, $activeOnly) {
    $sql = 'SELECT ob.id, ob.template, ob.title, ob.subtitle, ob.code, ob.cta, ob.color, ob.menu_item_id, ob.placement, ob.is_active, ob.display_order,
                   ob.opts, ob.ends_ts, mi.item_image AS image
            FROM website_offer_banners ob
            LEFT JOIN menu_items mi ON mi.id = ob.menu_item_id AND mi.restaurant_id = ob.restaurant_id
            WHERE ob.restaurant_id = :rid' . ($activeOnly ? ' AND ob.is_active = 1 AND (ob.ends_ts IS NULL OR ob.ends_ts > UNIX_TIMESTAMP())' : '') . '
            ORDER BY ob.display_order ASC, ob.id ASC';
    $st = $conn->prepare($sql);
    $st->execute([':rid' => $restaurant_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        // Flatten the look options onto the banner (accent, kicker, speed, font, shape, text, countdown)
        $r = array_merge($r, cleanOfferBannerOpts(json_decode((string)$r['opts'], true)));
        unset($r['opts']);
        $r['ends_ts'] = $r['ends_ts'] !== null ? (int)$r['ends_ts'] : null;
    }
    unset($r);
    return $rows;
}

// Where a website banner shows (Website Appearance > Banner Images)
// 'story' = photos for the Story header style (not shown as a slideshow)
const BANNER_PLACEMENTS = ['home_top', 'home_menu', 'menu_top', 'cart', 'story'];
const STORY_PHOTOS_MAX = 10;

/** website_banners.placement, added once (default: Home page top). */
function ensureBannerPlacementColumn($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $col = $conn->query("SHOW COLUMNS FROM website_banners LIKE 'placement'");
        if ($col && $col->fetch()) return;
        $conn->exec("ALTER TABLE website_banners ADD COLUMN placement VARCHAR(20) NOT NULL DEFAULT 'home_top'");
    } catch (PDOException $e) {
        error_log('ensureBannerPlacementColumn: ' . $e->getMessage());
    }
}

// Home page dish list look (Website Appearance > Home Page Dishes)
// Customer profile page look (Website Appearance > Profile Page)
const THEME_PROFILE_STYLES = ['midnight', 'brand', 'soft', 'centered', 'cover'];
const THEME_HOME_CARD_STYLES = ['classic', 'grid', 'big', 'compact', 'carousel', 'magazine', 'printed', 'tiles', 'delivery', 'dark'];
const THEME_MENU_CARD_STYLES = ['classic', 'modern', 'minimal', 'photo', 'list', 'elegant', 'compact', 'midnight', 'showcase'];
// Menu page category pictures: round photos, or cut-outs (whole picture, no crop,
// for transparent PNGs of the dish) with the label highlighted when selected
const THEME_CATEGORY_PIC_STYLES = ['round', 'cutout'];

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
    // Column definitions added over time. Previously each was probed with its
    // own "SELECT col FROM website_settings" on every page view (~11 queries
    // per visit); now one SHOW COLUMNS lists them and only missing ones are
    // added. Semantics of each column:
    //   theme_preset / card_style   — preset id, card corner style
    //   checkout_color              — NULL = follow the Primary Color
    //   layout_style / header_style — menu card layout, hero vs minimal header
    //   site_name                   — customer-facing name override (NULL = account name)
    //   nav_icon_style / nav_labels — bottom-nav icon set and label overrides
    //   favicon_url                 — browser-tab icon override (NULL = logo)
    //   nav_icons_custom            — per-slot uploaded nav icons (JSON of data URLs)
    //   bestseller_style            — Bestsellers section look (see getBestsellerStyle())
    //   menu_nav_position           — Menu page categories: left sidebar or top bar
    //   menu_card_style             — Menu page dish cards: classic or modern
    //   category_heading_bg         — category heading colour (NULL = light shade of the ADD button colour)
    //   category_pic_style          — Menu page category pictures: round or cutout
    //   all_category_icon           — custom picture for the Menu page "All" button (data URL, NULL = grid icon)
    $columns = [
        'theme_preset'     => "VARCHAR(30) DEFAULT NULL",
        'card_style'       => "VARCHAR(10) DEFAULT 'rounded'",
        'checkout_color'   => "VARCHAR(20) DEFAULT NULL",
        'layout_style'     => "VARCHAR(10) DEFAULT 'grid'",
        'header_style'     => "VARCHAR(10) DEFAULT 'hero'",
        'site_name'        => "VARCHAR(191) DEFAULT NULL",
        'nav_icon_style'   => "VARCHAR(20) DEFAULT 'classic'",
        'nav_labels'       => "TEXT DEFAULT NULL",
        'favicon_url'      => "VARCHAR(500) DEFAULT NULL",
        'nav_icons_custom' => "LONGTEXT DEFAULT NULL",
        'bestseller_style' => "TEXT DEFAULT NULL",
        'menu_nav_position' => "VARCHAR(10) DEFAULT 'left'",
        'menu_card_style' => "VARCHAR(10) DEFAULT 'classic'",
        'home_card_style' => "VARCHAR(10) DEFAULT 'classic'",
        'profile_style' => "VARCHAR(10) DEFAULT 'midnight'",
        'category_heading_bg' => "VARCHAR(20) DEFAULT NULL",
        'category_pic_style' => "VARCHAR(10) DEFAULT 'round'",
        'all_category_icon' => "LONGTEXT DEFAULT NULL",
    ];
    try {
        $existing = []; $types = [];
        foreach ($conn->query("SHOW COLUMNS FROM website_settings")->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $existing[strtolower($col['Field'])] = true;
            $types[strtolower($col['Field'])] = strtolower($col['Type']);
        }
    } catch (PDOException $e) {
        return; // table not there yet — nothing to extend; try again next request
    }
    $missing = array_diff_key($columns, $existing);
    // background_theme was created as VARCHAR(50), which silently cut off most
    // image URLs (the hero then showed no background) — widen it once.
    $widenBg = isset($types['background_theme']) && preg_match('/^varchar\((\d+)\)/', $types['background_theme'], $m) && (int)$m[1] < 500;
    // ALTER TABLE implicitly commits an open transaction — never do it mid-transaction
    if (($missing || $widenBg) && $conn->inTransaction()) {
        return;
    }
    foreach ($missing as $name => $definition) {
        try { $conn->exec("ALTER TABLE website_settings ADD COLUMN $name $definition"); } catch (PDOException $e) {}
    }
    if ($widenBg) {
        try { $conn->exec("ALTER TABLE website_settings MODIFY background_theme VARCHAR(500) DEFAULT NULL"); } catch (PDOException $e) {}
    }
    $checked = true;
}

const BESTSELLER_ADD_ANIMATIONS = ['glow', 'bounce', 'ripple', 'none'];
const BESTSELLER_ADD_STYLES = ['outline', 'filled', 'soft'];
const BESTSELLER_ADD_SHAPES = ['rounded', 'pill', 'square'];
const BESTSELLER_STYLE_DEFAULTS = [
    'show_section'   => true,
    'title'          => 'Bestsellers',
    'eyebrow'        => 'Customer favourites',
    'title_color'    => '#1f2a44',
    'badge_color'    => '#1f7a3a',   // "₹X OFF" tab + rating pill
    'add_color'      => '#e53935',   // ADD button outline/text + qty selector
    'add_animation'  => 'glow',     // older saved values (pulse/shine/wiggle) fall back to this
    'add_style'      => 'outline',  // outline | filled | soft
    'add_shape'      => 'rounded',  // rounded | pill | square
    'add_label'      => 'ADD',
    'add_plus'       => false,      // show a "+" icon after the label
    'price_color'    => '#ea580c',  // box around the current price
    'price_style'    => 'filled',   // outline | filled | soft
    'show_rating'    => true,
    'show_offer'     => true,
];

/**
 * Website Appearance > Bestsellers settings. Accepts the stored JSON string
 * or a decoded array (from the admin save request) and returns a complete,
 * validated settings array — unknown keys dropped, bad values replaced by
 * the defaults, so it's safe to print into the customer page.
 */
function getBestsellerStyle($raw): array {
    $in = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : []);
    $out = BESTSELLER_STYLE_DEFAULTS;
    foreach (['show_section', 'show_rating', 'show_offer', 'add_plus'] as $k) {
        if (array_key_exists($k, $in)) $out[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);
    }
    if (isset($in['add_style']) && in_array($in['add_style'], BESTSELLER_ADD_STYLES, true)) $out['add_style'] = $in['add_style'];
    if (isset($in['add_shape']) && in_array($in['add_shape'], BESTSELLER_ADD_SHAPES, true)) $out['add_shape'] = $in['add_shape'];
    // The label is drawn with CSS `content`, so allow only plain text
    // characters — no quotes, backslashes or angle brackets that could
    // break out of the CSS string or the <style> block.
    if (isset($in['add_label'])) {
        $label = trim(preg_replace('/[^\p{L}\p{M}\p{N} +&!.\-]/u', '', (string)$in['add_label']));
        $out['add_label'] = $label !== '' ? mb_substr($label, 0, 12) : BESTSELLER_STYLE_DEFAULTS['add_label'];
    }
    foreach (['title' => 40, 'eyebrow' => 40] as $k => $max) {
        if (isset($in[$k])) {
            $v = trim(str_replace(["\0", "\r", "\n", "\t"], '', (string)$in[$k]));
            $out[$k] = $v !== '' ? mb_substr($v, 0, $max) : BESTSELLER_STYLE_DEFAULTS[$k];
        }
    }
    if (isset($in['price_style']) && in_array($in['price_style'], BESTSELLER_ADD_STYLES, true)) $out['price_style'] = $in['price_style'];
    foreach (['title_color', 'badge_color', 'add_color', 'price_color'] as $k) {
        if (isset($in[$k]) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$in[$k])) $out[$k] = strtolower($in[$k]);
    }
    if (isset($in['add_animation']) && in_array($in['add_animation'], BESTSELLER_ADD_ANIMATIONS, true)) {
        $out['add_animation'] = $in['add_animation'];
    }
    return $out;
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
