<?php
/**
 * The restaurant website for the API and AI apps (mcp/tools.php loads this): theme and colours, public profile
 * (about, phone, address, opening hours, social links, order types), logo, photo banners, offer banners, policy pages
 * and SEO. Same tables and checks as the dashboard (website/theme_api.php, api/app_settings.php → config/
 * app_setting_fields.php, admin/auth.php uploadRestaurantLogo, website/policy_pages_api.php, api/seo.php).
 * Theme saves change only the fields sent (the dashboard's save rewrites the whole row). The demo restaurant keeps its
 * name and logo, as in the dashboard.
 */

const WS_FONTS = ['Poppins', 'Playfair Display', 'Roboto', 'Montserrat', 'Nunito', 'Lora'];
const WS_POLICIES = ['privacy', 'terms', 'refund', 'shipping', 'cookie'];
// public profile fields the API may change (payment and tax settings are left out on purpose)
const WS_PROFILE_FIELDS = ['restaurant_name', 'description', 'phone', 'email', 'address', 'google_maps_link', 'opening_hours',
    'enable_delivery', 'enable_takeaway', 'enable_dinein', 'enable_reservations', 'minimum_order_value', 'packaging_charge',
    'instagram_link', 'facebook_link', 'twitter_link', 'youtube_link', 'linkedin_link', 'call_waiter_enabled'];
const WS_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

function websiteTools(): array
{
    require_once __DIR__ . '/../../config/website_theme_helpers.php';
    $t = fn(array $def, string $perm, ?string $page, ?array $route) => ['def' => $def, 'perm' => $perm, 'page' => $page, 'route' => $route ? $route + [2 => 'Website'] : null];
    $hex = ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$'];
    $img = ['type' => 'string', 'description' => 'The picture: a data URL (data:image/jpeg;base64,…) or a public https link.'];
    $day = ['type' => 'object', 'properties' => ['open' => ['type' => 'boolean'], 'opening' => ['type' => 'string', 'description' => 'e.g. 09:00 AM'], 'closing' => ['type' => 'string', 'description' => 'e.g. 11:00 PM (after midnight is fine: 01:00 AM)']]];
    return [
        $t(mcpDef('get_website', 'Website settings', 'Everything the restaurant website shows: theme and colours, public profile (about, phone, address, opening hours, social links, order types, minimum order), photo banners, offer banners, which policy pages are customised, and SEO.',
            [], [], 'read', 'Reading the website…', 'Website loaded'), 'website:read', null, ['GET', 'website']),
        $t(mcpDef('update_theme', 'Change the website theme', 'Change the website look; only the fields you pass change. preset applies a ready colour scheme.',
            ['primary_color' => $hex, 'dark_color' => $hex, 'accent_color' => $hex, 'checkout_color' => ['type' => 'string', 'description' => '#rrggbb, or empty to follow the primary colour.'],
             'font' => ['type' => 'string', 'enum' => WS_FONTS], 'card_style' => ['type' => 'string', 'enum' => THEME_CARD_STYLES], 'layout_style' => ['type' => 'string', 'enum' => THEME_LAYOUT_STYLES],
             'header_style' => ['type' => 'string', 'enum' => THEME_HEADER_STYLES], 'menu_card_style' => ['type' => 'string', 'enum' => THEME_MENU_CARD_STYLES], 'home_card_style' => ['type' => 'string', 'enum' => THEME_HOME_CARD_STYLES],
             'nav_bar_style' => ['type' => 'string', 'enum' => THEME_NAV_BAR_STYLES], 'menu_nav_position' => ['type' => 'string', 'enum' => THEME_MENU_NAV_POSITIONS],
             'preset' => ['type' => 'string', 'enum' => array_keys(THEME_PRESETS)], 'site_name' => ['type' => 'string', 'maxLength' => 191, 'description' => 'Name shown on the site; empty = the restaurant name.']],
            [], 'write', 'Saving the theme…', 'Theme saved'), 'website:write', 'websiteThemePage', ['POST', 'website/theme']),
        $t(mcpDef('update_profile', 'Change the public profile', 'Change what the website shows about the restaurant; only the fields you pass change. opening_hours: any of monday … sunday, each {open, opening, closing} with times like "09:00 AM". At least one of delivery, takeaway or dine-in must stay on.',
            ['restaurant_name' => ['type' => 'string', 'maxLength' => 190], 'description' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'About the restaurant.'],
             'phone' => ['type' => 'string'], 'email' => ['type' => 'string'], 'address' => ['type' => 'string', 'maxLength' => 2000], 'google_maps_link' => ['type' => 'string'],
             'opening_hours' => ['type' => 'object', 'properties' => array_fill_keys(WS_DAYS, $day)],
             'enable_delivery' => ['type' => 'boolean'], 'enable_takeaway' => ['type' => 'boolean'], 'enable_dinein' => ['type' => 'boolean'], 'enable_reservations' => ['type' => 'boolean'],
             'minimum_order_value' => ['type' => 'number', 'minimum' => 0], 'packaging_charge' => ['type' => 'number', 'minimum' => 0], 'call_waiter_enabled' => ['type' => 'boolean'],
             'instagram_link' => ['type' => 'string'], 'facebook_link' => ['type' => 'string'], 'twitter_link' => ['type' => 'string'], 'youtube_link' => ['type' => 'string'], 'linkedin_link' => ['type' => 'string']],
            [], 'write', 'Saving the profile…', 'Profile saved'), 'website:write', null, ['POST', 'website/profile']),
        $t(mcpDef('set_logo', 'Change the logo', 'Set the restaurant logo (JPEG, PNG, GIF or WebP, at most 2 MB). A square picture works best.',
            ['image' => $img], ['image'], 'write', 'Saving the logo…', 'Logo saved'), 'website:write', null, ['POST', 'website/logo']),
        $t(mcpDef('add_banner', 'Add a photo banner', 'Add a photo banner to the website (JPEG, PNG, GIF or WebP, at most 5 MB). placement: home_top (slider at the top of the home page), home_menu, menu_top, cart, or story (up to ' . STORY_PHOTOS_MAX . ' photos).',
            ['image' => $img, 'placement' => ['type' => 'string', 'enum' => BANNER_PLACEMENTS, 'default' => 'home_top']], ['image'], 'create', 'Adding the banner…', 'Banner added'), 'website:write', 'websiteThemePage', ['POST', 'website/banners']),
        $t(mcpDef('update_banner', 'Move a photo banner', 'Change where a photo banner shows (placement).',
            ['banner_id' => ['type' => 'integer'], 'placement' => ['type' => 'string', 'enum' => BANNER_PLACEMENTS]], ['banner_id', 'placement'], 'write', 'Saving the banner…', 'Banner saved'), 'website:write', 'websiteThemePage', ['PATCH', 'website/banners/{banner_id}']),
        $t(mcpDef('reorder_banners', 'Reorder photo banners', 'Set the order of photo banners: give banner ids in the wanted order.',
            ['banner_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1, 'maxItems' => 100]], ['banner_ids'], 'write', 'Reordering…', 'Order saved'), 'website:write', 'websiteThemePage', ['POST', 'website/banners/order']),
        $t(mcpDef('delete_banner', 'Delete a photo banner', 'Delete a photo banner for good.',
            ['banner_id' => ['type' => 'integer']], ['banner_id'], 'delete', 'Deleting the banner…', 'Banner deleted'), 'website:write', 'websiteThemePage', ['POST', 'website/banners/{banner_id}/delete']),
        $t(mcpDef('save_offer_banner', 'Create or edit an offer banner', 'An animated text banner (no photo needed), e.g. "50% OFF" with a coupon code. No offer_banner_id = new. Only title is required for a new one.',
            ['offer_banner_id' => ['type' => 'integer'], 'title' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Headline, e.g. 50% OFF'], 'subtitle' => ['type' => 'string', 'maxLength' => 90],
             'cta' => ['type' => 'string', 'maxLength' => 24, 'description' => 'Button text.'], 'code' => ['type' => 'string', 'maxLength' => 30, 'description' => 'Coupon code to show.'],
             'template' => ['type' => 'string', 'enum' => OFFER_BANNER_TEMPLATES], 'color' => $hex, 'placement' => ['type' => 'string', 'enum' => BANNER_PLACEMENTS],
             'menu_item_id' => ['type' => 'integer', 'description' => 'Dish the banner opens.'], 'active' => ['type' => 'boolean', 'default' => true],
             'ends_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM: shows a countdown and hides after.']],
            [], 'create', 'Saving the offer banner…', 'Offer banner saved'), 'website:write', 'websiteThemePage', ['POST', 'website/offer-banners']),
        $t(mcpDef('delete_offer_banner', 'Delete an offer banner', 'Delete an offer banner for good.',
            ['offer_banner_id' => ['type' => 'integer']], ['offer_banner_id'], 'delete', 'Deleting…', 'Offer banner deleted'), 'website:write', 'websiteThemePage', ['POST', 'website/offer-banners/{offer_banner_id}/delete']),
        $t(mcpDef('get_policy_page', 'Read a policy page', 'The text of one policy page (privacy, terms, refund, shipping, cookie): the restaurant\'s own text, or the ready-made default if not customised.',
            ['policy' => ['type' => 'string', 'enum' => WS_POLICIES]], ['policy'], 'read', 'Reading the page…', 'Page loaded'), 'website:read', 'policyPagesPage', ['GET', 'website/policies/{policy}']),
        $t(mcpDef('set_policy_page', 'Change a policy page', 'Replace the text of a policy page with your own (HTML allowed, no scripts). reset=true goes back to the ready-made default text.',
            ['policy' => ['type' => 'string', 'enum' => WS_POLICIES], 'content' => ['type' => 'string', 'maxLength' => 100000], 'reset' => ['type' => 'boolean', 'default' => false]], ['policy'], 'write', 'Saving the page…', 'Page saved'), 'website:write', 'policyPagesPage', ['POST', 'website/policies/{policy}']),
        $t(mcpDef('update_seo', 'Change SEO settings', 'Change how the website shows on Google; only the fields you pass change. pages: any of home, menu, about, contact, each {title up to 90, description up to 300}.',
            ['pages' => ['type' => 'object', 'properties' => array_fill_keys(['home', 'menu', 'about', 'contact'], ['type' => 'object', 'properties' => ['title' => ['type' => 'string', 'maxLength' => 90], 'description' => ['type' => 'string', 'maxLength' => 300]]])],
             'keywords' => ['type' => 'string', 'maxLength' => 300], 'cuisine' => ['type' => 'string', 'maxLength' => 120], 'price_range' => ['type' => 'string', 'enum' => ['', '₹', '₹₹', '₹₹₹', '₹₹₹₹']],
             'google_verification' => ['type' => 'string', 'description' => 'Search Console HTML tag code.'], 'ga_id' => ['type' => 'string', 'description' => 'G-XXXXXXXXXX'],
             'meta_pixel_id' => ['type' => 'string'], 'hide_from_google' => ['type' => 'boolean']],
            [], 'write', 'Saving SEO…', 'SEO saved'), 'website:write', 'seoPage', ['POST', 'website/seo']),
    ];
}

function websiteRun(PDO $conn, array $ctx, string $name, array $a): array
{
    require_once __DIR__ . '/../../config/website_theme_helpers.php';
    require_once __DIR__ . '/../../config/schema_cache.php';
    $rid = $ctx['rid'];
    $log = fn(string $action, string $summary) => logActivity($conn, $rid, $action, $summary, ['via' => $ctx['app']], null, null, mcpActor($ctx));
    $isDemo = function () use ($rid): bool {
        require_once __DIR__ . '/../../config/demo_restaurant.php';
        return $rid === DEMO_RESTAURANT_ID;
    };

    switch ($name) {
        case 'get_website':
            rgEnsureOnce('ensureWebsiteThemeSchema', $conn);
            $th = wsTheme($conn, $rid);
            require_once __DIR__ . '/../../config/app_setting_fields.php';
            $prof = wsProfile($conn, $ctx['user_id']);
            $banners = [];
            try {
                ensureBannerPlacementColumn($conn);
                $st = $conn->prepare('SELECT id, banner_path, display_order, placement FROM website_banners WHERE restaurant_id = ? ORDER BY display_order, id');
                $st->execute([$rid]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) $banners[] = ['banner_id' => (int)$b['id'], 'placement' => $b['placement'] ?: 'home_top', 'position' => (int)$b['display_order'],
                    'image_url' => 'https://restrogrow.com/main/website/image.php?type=banner&id=' . (int)$b['id']];
            } catch (PDOException $e) { /* no banners table on this database */ }
            ensureOfferBannersTable($conn);
            $offers = array_map(fn($o) => ['offer_banner_id' => (int)$o['id'], 'title' => $o['title'], 'subtitle' => $o['subtitle'] ?? null, 'code' => $o['code'] ?? null, 'cta' => $o['cta'] ?? null,
                'template' => $o['template'], 'color' => $o['color'], 'placement' => $o['placement'], 'active' => !empty($o['is_active']), 'ends_at' => !empty($o['ends_ts']) ? date('Y-m-d H:i', (int)$o['ends_ts']) : null],
                fetchOfferBanners($conn, $rid, false));
            wsEnsurePolicies($conn);
            $pp = $conn->prepare('SELECT policy_type FROM policy_pages WHERE restaurant_id = ? AND content IS NOT NULL');
            $pp->execute([$rid]);
            $custom = $pp->fetchAll(PDO::FETCH_COLUMN);
            require_once __DIR__ . '/../../config/seo_helpers.php';
            seoEnsure($conn);
            $seo = wsSeoOut(seoGet($conn, $rid));
            $data = ['theme' => $th, 'profile' => $prof, 'photo_banners' => $banners, 'offer_banners' => $offers,
                'policy_pages' => array_map(fn($p) => ['policy' => $p, 'customised' => in_array($p, $custom, true)], WS_POLICIES), 'seo' => $seo];
            return ['Website: ' . ($th['site_name'] ?: ($prof['restaurant_name'] ?? '')) . ' · colour ' . $th['primary_color'] . ' · font ' . $th['font'] . ' · ' . count($banners) . ' photo banner(s), ' . count($offers) . ' offer banner(s).', $data];

        case 'update_theme':
            rgEnsureOnce('ensureWebsiteThemeSchema', $conn);
            $set = []; $said = [];
            $color = function ($k, $col) use ($a, &$set, &$said) {
                if (!array_key_exists($k, $a)) return;
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a[$k])) throw new McpToolError("$k must be a colour like #e11d48.");
                $set[$col] = $a[$k]; $said[] = "$k {$a[$k]}";
            };
            $pick = function ($k, $col, array $allowed) use ($a, &$set, &$said) {
                if (!array_key_exists($k, $a)) return;
                if (!in_array($a[$k], $allowed, true)) throw new McpToolError("$k must be one of: " . implode(', ', $allowed) . '.');
                $set[$col] = $a[$k]; $said[] = "$k {$a[$k]}";
            };
            if (isset($a['preset'])) {
                if (!isset(THEME_PRESETS[$a['preset']])) throw new McpToolError('preset must be one of: ' . implode(', ', array_keys(THEME_PRESETS)) . '.');
                $p = THEME_PRESETS[$a['preset']];
                $set['theme_preset'] = $a['preset'];
                foreach (['primary_red', 'dark_red', 'primary_yellow', 'font_family', 'card_style', 'layout_style', 'header_style'] as $c) if (isset($p[$c])) $set[$c] = $p[$c];
                $said[] = 'preset ' . $a['preset'];
            }
            $color('primary_color', 'primary_red'); $color('dark_color', 'dark_red'); $color('accent_color', 'primary_yellow');
            if (array_key_exists('checkout_color', $a)) {
                $cc = trim((string)$a['checkout_color']);
                if ($cc !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $cc)) throw new McpToolError('checkout_color must be a colour like #16a34a, or empty.');
                $set['checkout_color'] = $cc ?: null; $said[] = 'checkout colour ' . ($cc ?: 'follows primary');
            }
            $pick('font', 'font_family', WS_FONTS); $pick('card_style', 'card_style', THEME_CARD_STYLES); $pick('layout_style', 'layout_style', THEME_LAYOUT_STYLES);
            $pick('header_style', 'header_style', THEME_HEADER_STYLES); $pick('menu_card_style', 'menu_card_style', THEME_MENU_CARD_STYLES);
            $pick('home_card_style', 'home_card_style', THEME_HOME_CARD_STYLES); $pick('nav_bar_style', 'nav_bar_style', THEME_NAV_BAR_STYLES); $pick('menu_nav_position', 'menu_nav_position', THEME_MENU_NAV_POSITIONS);
            if (array_key_exists('site_name', $a)) {
                $sn = mb_substr(str_replace(["\0", "\r", "\n", "\t"], '', trim((string)$a['site_name'])), 0, 191);
                $set['site_name'] = $sn !== '' ? $sn : null; $said[] = 'site name ' . ($sn ?: '(restaurant name)');
            }
            if (!$set) throw new McpToolError('Nothing to change: pass a colour, font, style, preset or site_name.');
            wsThemeRow($conn, $rid);
            $conn->prepare('UPDATE website_settings SET ' . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set))) . ' WHERE restaurant_id = ?')->execute(array_merge(array_values($set), [$rid]));
            $log('website.theme', 'Changed the website theme: ' . mb_substr(implode(', ', $said), 0, 300));
            return ['✅ Website theme: ' . implode(', ', $said) . '.', ['changed' => array_keys($set), 'theme' => wsTheme($conn, $rid)]];

        case 'update_profile':
            require_once __DIR__ . '/../../config/app_setting_fields.php';
            require_once __DIR__ . '/../../config/waiter_call.php';
            waiterCallEnsureSchema($conn);
            $have = array_flip($conn->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN));
            $in = array_intersect_key($a, array_flip(WS_PROFILE_FIELDS));
            if ($isDemo()) unset($in['restaurant_name']);
            if (isset($in['opening_hours'])) $in['opening_hours'] = json_encode(wsHours($conn, (int)$ctx['user_id'], (array)$in['opening_hours']));
            $sets = []; $vals = [];
            foreach ($in as $f => $v) {
                if (!isset($have[$f], APP_SETTING_FIELDS[$f])) continue;
                if (is_bool($v)) $v = $v ? '1' : '0';
                $sets[] = "`$f` = ?";
                $vals[] = appSettingValue($f, APP_SETTING_FIELDS[$f], $v);
            }
            if (!$sets) throw new McpToolError('Nothing to change: pass description, phone, address, opening_hours, links or order types.');
            $types = array_intersect(['enable_delivery', 'enable_takeaway', 'enable_dinein'], array_keys($in));
            if ($types) {
                $cur = $conn->prepare('SELECT enable_delivery, enable_takeaway, enable_dinein FROM users WHERE id = ?');
                $cur->execute([$ctx['user_id']]);
                $proj = $cur->fetch(PDO::FETCH_ASSOC) ?: [];
                foreach ($types as $c) $proj[$c] = appSettingValue($c, 'bool', is_bool($in[$c]) ? ($in[$c] ? '1' : '0') : $in[$c]);
                if (!((int)$proj['enable_delivery'] || (int)$proj['enable_takeaway'] || (int)$proj['enable_dinein'])) throw new McpToolError('At least one of delivery, takeaway or dine-in must stay on.');
            }
            $before = activityUserRow($conn, (int)$ctx['user_id']);
            if (isset($have['updated_at'])) $sets[] = 'updated_at = NOW()';
            $conn->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute(array_merge($vals, [$ctx['user_id']]));
            $changed = activitySettingsChanged($before, activityUserRow($conn, (int)$ctx['user_id']));
            if ($changed) logActivity($conn, $rid, 'settings.restaurant', 'Changed settings via ' . $ctx['app'] . ': ' . implode(', ', $changed), ['via' => $ctx['app']], null, null, mcpActor($ctx));
            return ['✅ Saved: ' . (implode(', ', $changed) ?: 'no change') . '.', ['changed' => $changed, 'profile' => wsProfile($conn, $ctx['user_id'])]];

        case 'set_logo':
            if ($isDemo()) throw new McpToolError('The demo restaurant keeps its logo.');
            require_once __DIR__ . '/../../config/api_media.php';
            $img = apiImageFrom((string)($a['image'] ?? ''), 2 * 1048576, 'Logo');
            $ref = 'db:' . uniqid();
            $st = $conn->prepare('UPDATE users SET restaurant_logo = ?, logo_data = ?, logo_mime_type = ?, updated_at = NOW() WHERE id = ?');
            $st->bindValue(1, $ref);
            $st->bindValue(2, $img['bytes'], PDO::PARAM_LOB);
            $st->bindValue(3, $img['mime']);
            $st->bindValue(4, (int)$ctx['user_id'], PDO::PARAM_INT);
            $st->execute();
            $log('settings.logo', 'Changed the restaurant logo');
            return ["✅ New logo saved ({$img['width']}×{$img['height']}).", ['logo' => $ref]];

        case 'add_banner':
            require_once __DIR__ . '/../../config/api_media.php';
            ensureBannerPlacementColumn($conn);
            $place = $a['placement'] ?? 'home_top';
            if (!in_array($place, BANNER_PLACEMENTS, true)) throw new McpToolError('placement must be one of: ' . implode(', ', BANNER_PLACEMENTS) . '.');
            if ($place === 'story') {
                $c = $conn->prepare("SELECT COUNT(*) FROM website_banners WHERE restaurant_id = ? AND placement = 'story'");
                $c->execute([$rid]);
                if ((int)$c->fetchColumn() >= STORY_PHOTOS_MAX) throw new McpToolError('Story can have up to ' . STORY_PHOTOS_MAX . ' photos. Delete one first.');
            }
            $img = apiImageFrom((string)($a['image'] ?? ''), 5 * 1048576, 'Banner');
            $next = $conn->prepare('SELECT COALESCE(MAX(display_order), 0) + 1 FROM website_banners WHERE restaurant_id = ?');
            $next->execute([$rid]);
            $st = $conn->prepare('INSERT INTO website_banners (restaurant_id, banner_path, banner_data, banner_mime_type, display_order, placement) VALUES (?, ?, ?, ?, ?, ?)');
            $st->bindValue(1, $rid);
            $st->bindValue(2, 'db:' . uniqid());
            $st->bindValue(3, $img['bytes'], PDO::PARAM_LOB);
            $st->bindValue(4, $img['mime']);
            $st->bindValue(5, (int)$next->fetchColumn(), PDO::PARAM_INT);
            $st->bindValue(6, $place);
            $st->execute();
            $id = (int)$conn->lastInsertId();
            $log('website.banner', "Added a photo banner ($place)");
            return ["✅ Banner added ($place, {$img['width']}×{$img['height']}).", ['banner_id' => $id, 'placement' => $place]];

        case 'update_banner':
            ensureBannerPlacementColumn($conn);
            if (!in_array($a['placement'] ?? '', BANNER_PLACEMENTS, true)) throw new McpToolError('placement must be one of: ' . implode(', ', BANNER_PLACEMENTS) . '.');
            $st = $conn->prepare('UPDATE website_banners SET placement = ? WHERE id = ? AND restaurant_id = ?');
            $st->execute([$a['placement'], (int)($a['banner_id'] ?? 0), $rid]);
            $own = $conn->prepare('SELECT COUNT(*) FROM website_banners WHERE id = ? AND restaurant_id = ?');
            $own->execute([(int)($a['banner_id'] ?? 0), $rid]);
            if (!(int)$own->fetchColumn()) throw new McpToolError('That banner does not exist. Use get_website.');
            return ['✅ Banner moved to ' . $a['placement'] . '.', ['banner_id' => (int)$a['banner_id'], 'placement' => $a['placement']]];

        case 'reorder_banners':
            require_once __DIR__ . '/menu_extras.php';
            $ids = mxIds($a['banner_ids'] ?? [], 100);
            $up = $conn->prepare('UPDATE website_banners SET display_order = ? WHERE id = ? AND restaurant_id = ?');
            $conn->beginTransaction();
            foreach ($ids as $i => $id) $up->execute([$i, $id, $rid]);
            $conn->commit();
            return ['✅ Saved the banner order.', ['ordered' => count($ids)]];

        case 'delete_banner':
            $st = $conn->prepare('SELECT banner_path FROM website_banners WHERE id = ? AND restaurant_id = ?');
            $st->execute([(int)($a['banner_id'] ?? 0), $rid]);
            $path = $st->fetchColumn();
            if ($path === false) throw new McpToolError('That banner does not exist. Use get_website.');
            $conn->prepare('DELETE FROM website_banners WHERE id = ? AND restaurant_id = ?')->execute([(int)$a['banner_id'], $rid]);
            if ($path && strpos($path, 'db:') !== 0 && strpos($path, 'uploads/banners/') !== false) @unlink(__DIR__ . '/../../' . ltrim(preg_replace('#^.*?(uploads/banners/)#', '$1', $path), '/'));
            $log('website.banner', 'Deleted a photo banner');
            return ['🗑️ Banner deleted.', ['banner_id' => (int)$a['banner_id'], 'deleted' => true]];

        case 'save_offer_banner':
            ensureOfferBannersTable($conn);
            $id = (int)($a['offer_banner_id'] ?? 0);
            $cur = ['title' => '', 'subtitle' => null, 'code' => null, 'cta' => null, 'template' => 'burst', 'color' => '#e11d48', 'placement' => 'home_top', 'menu_item_id' => null, 'is_active' => 1, 'opts' => null, 'ends_ts' => null];
            if ($id) {
                $st = $conn->prepare('SELECT * FROM website_offer_banners WHERE id = ? AND restaurant_id = ?');
                $st->execute([$id, $rid]);
                $cur = $st->fetch(PDO::FETCH_ASSOC);
                if (!$cur) throw new McpToolError('That offer banner does not exist. Use get_website.');
            }
            $clean = fn($v, $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$v))), 0, $max);
            $title = array_key_exists('title', $a) ? $clean($a['title'], 40) : (string)$cur['title'];
            if ($title === '') throw new McpToolError('Add a headline (title), e.g. 50% OFF.');
            $template = $a['template'] ?? $cur['template'];
            if (!in_array($template, OFFER_BANNER_TEMPLATES, true)) throw new McpToolError('template must be one of: ' . implode(', ', OFFER_BANNER_TEMPLATES) . '.');
            $placement = $a['placement'] ?? $cur['placement'];
            if (!in_array($placement, BANNER_PLACEMENTS, true)) throw new McpToolError('placement must be one of: ' . implode(', ', BANNER_PLACEMENTS) . '.');
            $color = $a['color'] ?? $cur['color'];
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$color)) throw new McpToolError('color must be like #e11d48.');
            $code = array_key_exists('code', $a) ? substr(strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$a['code'])), 0, 30) : (string)$cur['code'];
            $item = array_key_exists('menu_item_id', $a) ? (int)$a['menu_item_id'] : (int)$cur['menu_item_id'];
            if ($item > 0) {
                $chk = $conn->prepare('SELECT id FROM menu_items WHERE id = ? AND restaurant_id = ?');
                $chk->execute([$item, $rid]);
                if (!$chk->fetchColumn()) throw new McpToolError("Dish $item is not on this menu.");
            }
            $ends = $cur['ends_ts'];
            if (array_key_exists('ends_at', $a)) {
                $ends = null;
                if (trim((string)$a['ends_at']) !== '') {
                    require_once __DIR__ . '/../../config/owner_manager.php';
                    $ts = strtotime((string)$a['ends_at']);
                    if (!$ts || $ts < time()) throw new McpToolError('ends_at must be a future date and time like 2026-12-31 23:00.');
                    $ends = $ts;
                }
            }
            $row = [$template, $title, array_key_exists('subtitle', $a) ? ($clean($a['subtitle'], 90) ?: null) : $cur['subtitle'], $code !== '' ? $code : null,
                array_key_exists('cta', $a) ? ($clean($a['cta'], 24) ?: null) : $cur['cta'], $color, $item ?: null, $placement,
                array_key_exists('active', $a) ? (!empty($a['active']) ? 1 : 0) : (int)$cur['is_active'], $cur['opts'] ?: json_encode(cleanOfferBannerOpts([]), JSON_UNESCAPED_UNICODE), $ends];
            if ($id) {
                $conn->prepare('UPDATE website_offer_banners SET template=?, title=?, subtitle=?, code=?, cta=?, color=?, menu_item_id=?, placement=?, is_active=?, opts=?, ends_ts=? WHERE id=? AND restaurant_id=?')
                    ->execute(array_merge($row, [$id, $rid]));
            } else {
                $next = $conn->prepare('SELECT COALESCE(MAX(display_order), -1) + 1 FROM website_offer_banners WHERE restaurant_id = ?');
                $next->execute([$rid]);
                $conn->prepare('INSERT INTO website_offer_banners (template, title, subtitle, code, cta, color, menu_item_id, placement, is_active, opts, ends_ts, restaurant_id, display_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute(array_merge($row, [$rid, (int)$next->fetchColumn()]));
                $id = (int)$conn->lastInsertId();
            }
            $log('website.offer_banner', 'Saved offer banner "' . $title . '"');
            return ["✅ Offer banner \"$title\" saved ($template, $placement).", ['offer_banner_id' => $id]];

        case 'delete_offer_banner':
            ensureOfferBannersTable($conn);
            $st = $conn->prepare('SELECT title FROM website_offer_banners WHERE id = ? AND restaurant_id = ?');
            $st->execute([(int)($a['offer_banner_id'] ?? 0), $rid]);
            $title = $st->fetchColumn();
            if ($title === false) throw new McpToolError('That offer banner does not exist. Use get_website.');
            $conn->prepare('DELETE FROM website_offer_banners WHERE id = ? AND restaurant_id = ?')->execute([(int)$a['offer_banner_id'], $rid]);
            $log('website.offer_banner', 'Deleted offer banner "' . $title . '"');
            return ["🗑️ Deleted offer banner \"$title\".", ['offer_banner_id' => (int)$a['offer_banner_id'], 'deleted' => true]];

        case 'get_policy_page':
        case 'set_policy_page':
            $pol = (string)($a['policy'] ?? '');
            if (!in_array($pol, WS_POLICIES, true)) throw new McpToolError('policy must be one of: ' . implode(', ', WS_POLICIES) . '.');
            wsEnsurePolicies($conn);
            if ($name === 'set_policy_page') {
                if (!empty($a['reset'])) {
                    $conn->prepare('DELETE FROM policy_pages WHERE restaurant_id = ? AND policy_type = ?')->execute([$rid, $pol]);
                    $log('website.policy', "Reset the $pol page to the default text");
                    return ["✅ The $pol page shows the ready-made default text again.", ['policy' => $pol, 'customised' => false]];
                }
                $content = trim((string)($a['content'] ?? ''));
                if ($content === '') throw new McpToolError('Send content (the page text), or reset=true for the default text.');
                // owners write HTML here; scripts and inline handlers never belong on a policy page
                $content = preg_replace(['#<script\b[^>]*>.*?</script>#is', '#<(iframe|object|embed)\b[^>]*>.*?</\1>#is', '#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '#javascript:#i'], '', $content);
                $conn->prepare('INSERT INTO policy_pages (restaurant_id, policy_type, content) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content)')->execute([$rid, $pol, $content]);
                $log('website.policy', "Changed the $pol page");
                return ["✅ The $pol page is saved (" . mb_strlen(strip_tags($content)) . ' characters).', ['policy' => $pol, 'customised' => true]];
            }
            $st = $conn->prepare('SELECT content FROM policy_pages WHERE restaurant_id = ? AND policy_type = ?');
            $st->execute([$rid, $pol]);
            $own = $st->fetchColumn();
            if ($own) return [mb_substr(trim(strip_tags($own)), 0, 4000), ['policy' => $pol, 'customised' => true, 'content' => $own]];
            require_once __DIR__ . '/../../website/policy_defaults.php';
            $u = $conn->prepare('SELECT restaurant_name, owner_name, email, phone FROM users WHERE id = ?');
            $u->execute([$ctx['user_id']]);
            $ur = $u->fetch(PDO::FETCH_ASSOC) ?: [];
            $fn = ['privacy' => 'getDefaultPrivacyPolicyHtml', 'terms' => 'getDefaultTermsOfServiceHtml', 'refund' => 'getDefaultRefundPolicyHtml',
                'shipping' => 'getDefaultShippingPolicyHtml', 'cookie' => 'getDefaultCookiePolicyHtml'][$pol];
            $def = (string)$fn($ur['restaurant_name'] ?? '', $ur['owner_name'] ?? '', $ur['email'] ?? '', $ur['phone'] ?? '');
            return [$def ? mb_substr(trim(strip_tags($def)), 0, 4000) : "The $pol page uses the ready-made default text.", ['policy' => $pol, 'customised' => false, 'content' => $def ?: null]];

        case 'update_seo':
            require_once __DIR__ . '/../../config/seo_helpers.php';
            seoEnsure($conn);
            $cur = seoGet($conn, $rid);
            $clean = fn($v, $n) => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$v))), 0, $n);
            $pages = $cur['pages'] ?? [];
            if (isset($a['pages'])) {
                foreach (array_keys(SEO_PAGES) as $p) {
                    if (!isset($a['pages'][$p])) continue;
                    $t = array_key_exists('title', $a['pages'][$p]) ? $clean($a['pages'][$p]['title'], 90) : ($pages[$p]['title'] ?? '');
                    $ds = array_key_exists('description', $a['pages'][$p]) ? $clean($a['pages'][$p]['description'], 300) : ($pages[$p]['description'] ?? '');
                    if ($t !== '' || $ds !== '') $pages[$p] = ['title' => $t, 'description' => $ds]; else unset($pages[$p]);
                }
            }
            $gsc = array_key_exists('google_verification', $a) ? trim((string)$a['google_verification']) : (string)($cur['gsc_verification'] ?? '');
            if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $gsc, $mm)) $gsc = $mm[1];
            if ($gsc !== '' && !preg_match('/^[A-Za-z0-9_\-]{10,100}$/', $gsc)) throw new McpToolError('That Search Console code does not look right. Copy the code from the HTML tag option.');
            $ga = array_key_exists('ga_id', $a) ? strtoupper(trim((string)$a['ga_id'])) : (string)($cur['ga_id'] ?? '');
            if ($ga !== '' && !preg_match('/^G-[A-Z0-9]{4,15}$/', $ga)) throw new McpToolError('Google Analytics ID should look like G-XXXXXXXXXX.');
            $px = array_key_exists('meta_pixel_id', $a) ? preg_replace('/\s+/', '', (string)$a['meta_pixel_id']) : (string)($cur['meta_pixel_id'] ?? '');
            if ($px !== '' && !preg_match('/^\d{8,20}$/', $px)) throw new McpToolError('Meta Pixel ID is a number, 15–16 digits long.');
            $price = array_key_exists('price_range', $a) ? (string)$a['price_range'] : (string)($cur['price_range'] ?? '');
            if (!in_array($price, ['', '₹', '₹₹', '₹₹₹', '₹₹₹₹'], true)) throw new McpToolError('price_range must be empty or ₹ to ₹₹₹₹.');
            $f = ['pages_json' => json_encode($pages, JSON_UNESCAPED_UNICODE), 'keywords' => array_key_exists('keywords', $a) ? $clean($a['keywords'], 300) : (string)($cur['keywords'] ?? ''),
                'cuisine' => array_key_exists('cuisine', $a) ? $clean($a['cuisine'], 120) : (string)($cur['cuisine'] ?? ''), 'price_range' => $price ?: null, 'gsc_verification' => $gsc ?: null,
                'ga_id' => $ga ?: null, 'meta_pixel_id' => $px ?: null, 'noindex' => array_key_exists('hide_from_google', $a) ? (!empty($a['hide_from_google']) ? 1 : 0) : (int)($cur['noindex'] ?? 0)];
            $cols = array_keys($f);
            $conn->prepare('INSERT INTO seo_settings (restaurant_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ') ON DUPLICATE KEY UPDATE '
                . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols)))->execute(array_merge([$rid], array_values($f)));
            $log('website.seo', 'Changed SEO settings');
            return ['✅ SEO settings saved.' . ($f['noindex'] ? ' The site is hidden from Google.' : ''), wsSeoOut(seoGet($conn, $rid))];
    }
    throw new McpToolError("Unknown tool: $name");
}

/** The website_settings row exists (with the dashboard's defaults), so updates can change single columns. */
function wsThemeRow(PDO $conn, string $rid): void
{
    $st = $conn->prepare('SELECT 1 FROM website_settings WHERE restaurant_id = ?');
    $st->execute([$rid]);
    if ($st->fetchColumn()) return;
    $conn->prepare("INSERT INTO website_settings (restaurant_id, primary_red, dark_red, primary_yellow, layout_columns, logo_shape, logo_size, font_family, card_style, layout_style, header_style, nav_icon_style)
        VALUES (?, '#F70000', '#DA020E', '#FFD100', 2, 'circle', 90, 'Poppins', 'rounded', 'grid', 'hero', 'classic')")->execute([$rid]);
}

function wsTheme(PDO $conn, string $rid): array
{
    $st = $conn->prepare('SELECT * FROM website_settings WHERE restaurant_id = ?');
    $st->execute([$rid]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['preset' => $r['theme_preset'] ?? null, 'primary_color' => $r['primary_red'] ?? '#F70000', 'dark_color' => $r['dark_red'] ?? '#DA020E', 'accent_color' => $r['primary_yellow'] ?? '#FFD100',
        'checkout_color' => $r['checkout_color'] ?? null, 'font' => $r['font_family'] ?? 'Poppins', 'card_style' => $r['card_style'] ?? 'rounded', 'layout_style' => $r['layout_style'] ?? 'grid',
        'header_style' => $r['header_style'] ?? 'hero', 'menu_card_style' => $r['menu_card_style'] ?? null, 'home_card_style' => $r['home_card_style'] ?? null,
        'nav_bar_style' => $r['nav_bar_style'] ?? null, 'menu_nav_position' => $r['menu_nav_position'] ?? null, 'site_name' => $r['site_name'] ?? null];
}

function wsProfile(PDO $conn, int $userId): array
{
    require_once __DIR__ . '/../../config/app_setting_fields.php';
    $have = array_flip($conn->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN));
    $fields = array_values(array_filter(WS_PROFILE_FIELDS, fn($f) => isset($have[$f])));
    $st = $conn->prepare('SELECT ' . implode(', ', array_map(fn($f) => "`$f`", $fields)) . ' FROM users WHERE id = ?');
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($row as $k => $v) {
        $type = APP_SETTING_FIELDS[$k] ?? 'text';
        if ($type === 'bool') $row[$k] = (int)$v === 1;
        elseif ($type === 'money') $row[$k] = $v === null ? null : (float)$v;
        elseif ($type === 'hours') $row[$k] = $v ? json_decode($v, true) : null;
    }
    return $row;
}

/** Merge the days sent into the saved opening hours (days not sent stay as they are). Times must look like 09:00 AM. */
function wsHours(PDO $conn, int $userId, array $in): array
{
    $st = $conn->prepare('SELECT opening_hours FROM users WHERE id = ?');
    $st->execute([$userId]);
    $cur = json_decode((string)$st->fetchColumn(), true) ?: [];
    $re = '/^(0?[1-9]|1[0-2]):[0-5][0-9] (AM|PM)$/';
    foreach ($in as $day => $h) {
        $day = strtolower((string)$day);
        if (!in_array($day, WS_DAYS, true)) throw new McpToolError("opening_hours: \"$day\" is not a day (monday … sunday).");
        $h = (array)$h;
        $now = $cur[$day] ?? ['open' => false, 'opening' => '09:00 AM', 'closing' => '11:00 PM'];
        foreach (['opening', 'closing'] as $k) {
            if (!isset($h[$k])) continue;
            $tm = strtoupper(trim((string)$h[$k]));
            if (preg_match('/^(\d{1,2}):(\d{2})$/', $tm, $mm) && (int)$mm[1] < 24) $tm = date('h:i A', mktime((int)$mm[1], (int)$mm[2]));   // 24-hour time is fine too
            if (!preg_match($re, $tm)) throw new McpToolError("opening_hours $day $k: use a time like 09:00 AM.");
            $now[$k] = $tm;
        }
        if (array_key_exists('open', $h)) $now['open'] = !empty($h['open']);
        $cur[$day] = $now;
    }
    return $cur;
}

function wsEnsurePolicies(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS policy_pages (
        id INT AUTO_INCREMENT PRIMARY KEY, restaurant_id VARCHAR(20) NOT NULL, policy_type VARCHAR(20) NOT NULL, content LONGTEXT DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_restaurant_policy (restaurant_id, policy_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function wsSeoOut(array $s): array
{
    return ['pages' => ($s['pages'] ?? []) ?: new stdClass(), 'keywords' => $s['keywords'] ?? '', 'cuisine' => $s['cuisine'] ?? '', 'price_range' => $s['price_range'] ?? '',
        'google_verification' => $s['gsc_verification'] ?? null, 'ga_id' => $s['ga_id'] ?? null, 'meta_pixel_id' => $s['meta_pixel_id'] ?? null, 'hide_from_google' => !empty($s['noindex'])];
}
