<?php
/**
 * Demo restaurant setup (Super Admin only).
 *
 * Creates "Spice Garden Demo" — a complete sample restaurant (22 dishes with
 * photos, descriptions, sizes, bestseller offers, coupons, tables, logo and
 * header photo) so the customer website can be shown on the live server.
 * Assets live in main/assets/demo_restaurant/ (photo credits: /demo-credits.txt).
 *
 * - Only a logged-in Super Admin can open it; actions are POST + CSRF token.
 * - "Create" runs only while the demo doesn't exist, and generates a random
 *   admin password that is shown once (only its hash is stored).
 * - "Remove" deletes every row belonging to the demo restaurant ID, including
 *   any test orders placed on it, so it can be cleaned up completely.
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';

const DEMO_RID      = 'RESDEMO01';
const DEMO_USERNAME = 'demo_spicegarden';
const DEMO_NAME     = 'Spice Garden Demo';
const DEMO_SLUG     = 'spice-garden-demo';

$conn = getConnection();
$assetDir = realpath(__DIR__ . '/../assets/demo_restaurant');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$mainUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
$siteRoot = rtrim(str_replace('\\', '/', dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])))), '/');
$publicUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $siteRoot . '/' . DEMO_SLUG;

if (empty($_SESSION['demo_setup_csrf'])) $_SESSION['demo_setup_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['demo_setup_csrf'];

function demoExists(PDO $conn): bool {
    $s = $conn->prepare('SELECT COUNT(*) FROM users WHERE restaurant_id = ? OR username = ?');
    $s->execute([DEMO_RID, DEMO_USERNAME]);
    return (int)$s->fetchColumn() > 0;
}

/** Delete every row that belongs to the demo restaurant (any table with a restaurant_id column). */
function removeDemo(PDO $conn): int {
    $rid = DEMO_RID;
    $deleted = 0;
    // Child tables keyed by parent ids rather than restaurant_id
    $children = [
        "DELETE v FROM menu_item_variations v JOIN menu_items mi ON mi.id = v.menu_item_id WHERE mi.restaurant_id = ?",
        "DELETE oia FROM order_item_addons oia JOIN order_items oi ON oi.id = oia.order_item_id JOIN orders o ON o.id = oi.order_id WHERE o.restaurant_id = ?",
        "DELETE oi FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.restaurant_id = ?",
        "DELETE ki FROM kot_items ki JOIN kot k ON k.id = ki.kot_id WHERE k.restaurant_id = ?",
    ];
    foreach ($children as $sql) {
        try { $st = $conn->prepare($sql); $st->execute([$rid]); $deleted += $st->rowCount(); } catch (PDOException $e) { /* table not present */ }
    }
    $tables = $conn->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'restaurant_id'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        if ($t === 'users') continue; // last
        try { $st = $conn->prepare("DELETE FROM `$t` WHERE restaurant_id = ?"); $st->execute([$rid]); $deleted += $st->rowCount(); } catch (PDOException $e) {}
    }
    $st = $conn->prepare('DELETE FROM users WHERE restaurant_id = ?'); $st->execute([$rid]); $deleted += $st->rowCount();
    return $deleted;
}

function createDemo(PDO $conn, string $assetDir, string $mainUrl, string $creditsUrl): string {
    // [name, category, type, price, description, calories, prep minutes, sizes [name => price], photo]
    $dishes = [
        ['Paneer Tikka', 'Starters', 'Veg', 249, 'Chunks of fresh cottage cheese marinated overnight in hung curd, kashmiri chilli and ajwain, chargrilled in the tandoor with peppers and onions. Served with mint chutney.', 320, 18, [], 'paneer-tikka'],
        ['Chicken 65', 'Starters', 'Non Veg', 279, 'Crispy, fiery Chennai-style fried chicken tossed with curry leaves, green chillies and a squeeze of lime. A bar-snack classic.', 410, 15, [], 'chicken-65'],
        ['Veg Spring Rolls', 'Starters', 'Veg', 189, 'Golden, crackly rolls stuffed with stir-fried cabbage, carrot, spring onion and glass noodles, served with sweet chilli dip.', 280, 12, ['4 pcs' => 189, '8 pcs' => 339], 'veg-spring-rolls'],
        ['Tandoori Chicken', 'Starters', 'Non Veg', 329, 'Bone-in chicken marinated in yogurt, ginger-garlic and our house tandoori masala, roasted in a clay oven until smoky and charred at the edges.', 450, 25, ['Half' => 329, 'Full' => 599], 'tandoori-chicken'],
        ['Egg Bhurji', 'Starters', 'Egg', 149, 'Street-style spiced scrambled eggs with onion, tomato, green chilli and fresh coriander, finished with a knob of butter. Comes with 2 pav.', 310, 10, [], 'egg-bhurji'],
        ['Butter Chicken', 'Main Course', 'Non Veg', 349, 'Our signature: tandoor-roasted chicken simmered in a silky tomato, butter and cashew gravy with a hint of kasuri methi. Mildly spiced, very creamy.', 540, 20, [], 'butter-chicken'],
        ['Paneer Butter Masala', 'Main Course', 'Veg', 299, 'Soft paneer cubes in a rich, mildly sweet tomato-butter gravy finished with fresh cream. Best with garlic naan.', 480, 18, ['Half' => 299, 'Full' => 539], 'paneer-butter-masala'],
        ['Dal Makhani', 'Main Course', 'Veg', 229, 'Whole black lentils and rajma slow-cooked overnight on the tandoor embers, finished with butter and cream. Smoky, velvety, comforting.', 390, 15, [], 'dal-makhani'],
        ['Mutton Rogan Josh', 'Main Course', 'Non Veg', 429, 'Kashmiri-style tender mutton braised with whole spices, fennel and ratan jot for that deep red colour. Rich but not too hot.', 560, 30, [], 'mutton-rogan-josh'],
        ['Chicken Biryani', 'Main Course', 'Non Veg', 319, 'Long-grain basmati layered with masala chicken, fried onions, mint and saffron, sealed and slow-cooked dum style. Served with raita and salan.', 680, 25, [], 'chicken-biryani'],
        ['Veg Biryani', 'Main Course', 'Veg', 259, 'Fragrant basmati dum-cooked with seasonal vegetables, paneer, whole spices and saffron. Served with raita.', 520, 25, ['Regular' => 259, 'Family pack (serves 3)' => 689], 'veg-biryani'],
        ['Butter Naan', 'Breads', 'Veg', 59, 'Soft, pillowy leavened bread baked fresh in the tandoor and brushed generously with butter.', 260, 8, [], 'butter-naan'],
        ['Garlic Naan', 'Breads', 'Veg', 69, 'Tandoor-baked naan topped with chopped garlic and coriander, finished with butter.', 290, 8, [], 'garlic-naan'],
        ['Tandoori Roti', 'Breads', 'Veg', 35, 'Whole-wheat flatbread slapped on the walls of our clay oven. Light, slightly crisp, perfect with dal.', 120, 6, [], 'tandoori-roti'],
        ['Laccha Paratha', 'Breads', 'Veg', 65, 'Flaky, multi-layered whole-wheat paratha cooked in the tandoor with ghee. Pull the layers apart!', 300, 8, [], 'laccha-paratha'],
        ['Masala Chai', 'Beverages', 'Drink', 49, 'Strong Assam tea brewed with milk, fresh ginger, cardamom and our own chai masala.', 110, 5, [], 'masala-chai'],
        ['Sweet Lassi', 'Beverages', 'Drink', 89, 'Thick Punjabi-style churned yogurt drink, lightly sweetened and topped with malai.', 240, 5, ['Regular' => 89, 'Large' => 139], 'sweet-lassi'],
        ['Fresh Lime Soda', 'Beverages', 'Drink', 79, 'Freshly squeezed lime with chilled soda. Choose sweet, salted or mixed.', 90, 5, [], 'fresh-lime-soda'],
        ['Cold Coffee', 'Beverages', 'Drink', 129, 'Creamy blended cold coffee with a scoop of vanilla ice cream.', 320, 5, [], 'cold-coffee'],
        ['Gulab Jamun', 'Desserts', 'Veg', 99, 'Two warm milk-solid dumplings soaked in cardamom and rose sugar syrup.', 330, 5, [], 'gulab-jamun'],
        ['Rasmalai', 'Desserts', 'Veg', 129, 'Soft chenna discs soaked in chilled saffron-cardamom milk, topped with pistachio.', 290, 5, [], 'rasmalai'],
        ['Kulfi', 'Desserts', 'Veg', 109, 'Traditional slow-reduced milk ice cream on a stick, flavoured with kesar and pista.', 250, 5, [], 'kulfi'],
    ];
    $categoryPhoto = ['Starters' => 'paneer-tikka', 'Main Course' => 'butter-chicken', 'Breads' => 'garlic-naan', 'Beverages' => 'sweet-lassi', 'Desserts' => 'gulab-jamun'];
    // Bestsellers in display order => offer price (null = no offer)
    $bestsellers = ['Butter Chicken' => 300, 'Paneer Tikka' => 224, 'Chicken Biryani' => 234, 'Dal Makhani' => 206,
                    'Mutton Rogan Josh' => null, 'Gulab Jamun' => null, 'Tandoori Chicken' => null, 'Egg Bhurji' => null, 'Chicken 65' => null];
    $coupons = [
        ['WELCOME50', 'flat', 50, 299, 'Flat ₹50 off your first order'],
        ['SPICE20', 'percent', 20, 599, '20% off on orders above ₹599'],
        ['FEAST100', 'flat', 100, 999, '₹100 off on orders above ₹999'],
        ['WEEKEND15', 'percent', 15, 399, '15% off every weekend order'],
    ];
    $read = function (string $file) use ($assetDir): string {
        $data = @file_get_contents("$assetDir/$file");
        if ($data === false || $data === '') throw new RuntimeException("Missing demo asset: $file");
        return $data;
    };

    // DDL implicitly commits in MySQL — make sure tables/columns exist before the transaction
    require_once __DIR__ . '/../config/bestseller_helpers.php';
    ensureBestsellersTable($conn);
    require_once __DIR__ . '/../config/website_theme_helpers.php';
    ensureWebsiteThemeSchema($conn);

    $password = 'Demo-' . substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 12);

    $conn->beginTransaction();
    try {
        $conn->prepare("INSERT INTO users (username, email, phone, address, password, restaurant_id, restaurant_name, owner_name,
                            currency_symbol, timezone, is_active, subscription_status, trial_end_date,
                            enable_delivery, enable_takeaway, enable_dinein, enable_gst, description, description_format)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'RestroGrow Demo', '₹', 'Asia/Kolkata', 1, 'active', ?, 1, 1, 1, 1, ?, 'paragraph')")
            ->execute([DEMO_USERNAME, 'demo@restrogrow.invalid', '9800000000', '100 Feet Rd, Indiranagar, Bengaluru, Karnataka 560038',
                       password_hash($password, PASSWORD_DEFAULT), DEMO_RID, DEMO_NAME, date('Y-m-d', strtotime('+5 years')),
                       'Demo restaurant by RestroGrow. Orders here are test orders and are not prepared or delivered. '
                       . 'Food photos: Wikimedia Commons contributors, credits at ' . $creditsUrl]);

        $hours = json_encode(array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            ['open' => true, 'opening' => '12:00 AM', 'closing' => '11:59 PM']));
        // Optional columns, one at a time: a column missing on an older schema must not block the rest
        $extras = [
            'opening_hours' => $hours, 'logo_data' => $read('logo.png'), 'logo_mime_type' => 'image/png', 'restaurant_logo' => 'db:logo',
            'country' => 'India', 'tax_name' => 'GST', 'tax_percent' => 5, 'cod_enabled' => 1, 'enable_reservations' => 1,
            'minimum_order_value' => 199, 'packaging_charge' => 20, 'delivery_radius_km' => 7,
            'restaurant_lat' => 12.9719, 'restaurant_lng' => 77.6412,
            'google_maps_link' => 'https://www.google.com/maps/search/?api=1&query=12.9719,77.6412',
        ];
        foreach ($extras as $col => $val) {
            try { $conn->prepare("UPDATE users SET `$col` = ? WHERE restaurant_id = ?")->execute([$val, DEMO_RID]); } catch (PDOException $e) {}
        }

        $insMenu = $conn->prepare("INSERT INTO menu (restaurant_id, menu_name, is_active, sort_order, menu_image, menu_image_data, menu_image_mime_type) VALUES (?, ?, 1, ?, ?, ?, 'image/jpeg')");
        $insItem = $conn->prepare("INSERT INTO menu_items (restaurant_id, menu_id, item_name_en, item_description_en, item_category, item_type, preparation_time,
                                       is_available, base_price, has_variations, item_image, image_data, image_mime_type, sort_order)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, 'image/jpeg', ?)");
        $insVar = $conn->prepare('INSERT INTO menu_item_variations (menu_item_id, variation_name, price, sort_order, is_available) VALUES (?, ?, ?, ?, 1)');
        $setCal = $conn->prepare('UPDATE menu_items SET calories = ? WHERE id = ?');
        $menuIds = []; $itemIds = []; $sort = 0;
        foreach ($dishes as [$name, $cat, $type, $price, $desc, $cal, $prep, $sizes, $photo]) {
            if (!isset($menuIds[$cat])) {
                $insMenu->execute([DEMO_RID, $cat, count($menuIds) + 1, 'db:menu_' . uniqid(), $read($categoryPhoto[$cat] . '.jpg')]);
                $menuIds[$cat] = (int)$conn->lastInsertId();
                $sort = 0;
            }
            $base = $sizes ? min($sizes) : $price;
            $insItem->execute([DEMO_RID, $menuIds[$cat], $name, $desc, $cat, $type, $prep, $base, $sizes ? 1 : 0, 'db:' . uniqid(), $read("$photo.jpg"), ++$sort]);
            $id = (int)$conn->lastInsertId();
            $itemIds[$name] = $id;
            try { $setCal->execute([$cal, $id]); } catch (PDOException $e) {}
            $i = 0;
            foreach ($sizes as $vn => $vp) $insVar->execute([$id, $vn, $vp, $i++]);
        }

        $insBs = $conn->prepare('INSERT INTO bestsellers (restaurant_id, menu_item_id, sort_order, offer_price) VALUES (?, ?, ?, ?)');
        $rank = 0;
        foreach ($bestsellers as $name => $offer) $insBs->execute([DEMO_RID, $itemIds[$name], $rank++, $offer]);

        $insCoupon = $conn->prepare("INSERT INTO coupons (restaurant_id, coupon_code, discount_type, discount_value, minimum_order_amount,
                                         max_uses, current_uses, valid_from, valid_until, is_active, description)
                                     VALUES (?, ?, ?, ?, ?, 0, 0, CURDATE(), CURDATE() + INTERVAL 1 YEAR, 1, ?)");
        foreach ($coupons as [$code, $ctype, $value, $min, $cdesc]) $insCoupon->execute([DEMO_RID, $code, $ctype, $value, $min, $cdesc]);

        $insArea = $conn->prepare('INSERT INTO areas (restaurant_id, area_name, sort_order) VALUES (?, ?, ?)');
        $insTable = $conn->prepare('INSERT INTO tables (restaurant_id, area_id, table_number, capacity, is_available, sort_order) VALUES (?, ?, ?, ?, 1, ?)');
        $tn = 0;
        foreach (['Ground Floor' => ['T', 6], 'Rooftop' => ['R', 4]] as $area => [$prefix, $count]) {
            $insArea->execute([DEMO_RID, $area, $prefix === 'T' ? 1 : 2]);
            $areaId = (int)$conn->lastInsertId();
            for ($i = 1; $i <= $count; $i++) $insTable->execute([DEMO_RID, $areaId, $prefix . $i, $i % 3 === 0 ? 6 : 4, ++$tn]);
        }

        // Header photo: background_theme must be an absolute http(s) URL
        $conn->prepare('INSERT INTO website_settings (restaurant_id, background_theme) VALUES (?, ?) ON DUPLICATE KEY UPDATE background_theme = VALUES(background_theme)')
            ->execute([DEMO_RID, $mainUrl . '/assets/demo_restaurant/hero.jpg']);
        // Categories in a bar across the top, so dish cards are half-screen wide
        try { $conn->prepare('UPDATE website_settings SET menu_nav_position = ? WHERE restaurant_id = ?')->execute(['top', DEMO_RID]); } catch (PDOException $e) {}

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    return $password;
}

$message = ''; $error = ''; $newPassword = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session changed — please try again.';
    } elseif (($_POST['action'] ?? '') === 'create') {
        if (demoExists($conn)) {
            $error = 'The demo restaurant already exists. Remove it first to create it again.';
        } elseif (!$assetDir || !is_file("$assetDir/hero.jpg")) {
            $error = 'Demo assets are missing on the server (main/assets/demo_restaurant). Deploy them and try again.';
        } else {
            try {
                $newPassword = createDemo($conn, $assetDir, $mainUrl, $_SERVER['HTTP_HOST'] . $siteRoot . '/demo-credits.txt');
                $message = 'Demo restaurant created.';
            } catch (Throwable $e) {
                error_log('Demo restaurant setup failed: ' . $e->getMessage());
                $error = 'Could not create the demo restaurant: ' . $e->getMessage() . ' (nothing was saved).';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'remove') {
        try {
            removeDemo($conn);
            $message = 'Demo restaurant removed, including any test orders placed on it.';
        } catch (Throwable $e) {
            error_log('Demo restaurant removal failed: ' . $e->getMessage());
            $error = 'Could not remove the demo restaurant: ' . $e->getMessage();
        }
    }
}
$exists = demoExists($conn);
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Demo restaurant · Super Admin</title>
<style>
  body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #f3f4f6; color: #111827; margin: 0; padding: 24px 16px; }
  .box { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
  h1 { font-size: 20px; margin: 0 0 6px; }
  p, li { font-size: 14px; line-height: 1.55; color: #374151; }
  .msg { padding: 12px 14px; border-radius: 10px; margin: 14px 0; font-size: 14px; }
  .ok { background: #ecfdf5; color: #065f46; } .err { background: #fef2f2; color: #991b1b; }
  .cred { background: #fffbeb; border: 1px solid #f59e0b; border-radius: 10px; padding: 12px 14px; margin: 14px 0; font-size: 14px; }
  code { background: #f3f4f6; padding: 2px 6px; border-radius: 5px; font-size: 13px; }
  .btn { display: inline-block; border: 0; border-radius: 10px; padding: 11px 18px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; }
  .primary { background: #ea580c; color: #fff; } .danger { background: #fff; color: #b91c1c; border: 1.5px solid #fca5a5; }
  .row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
  a { color: #c2410c; }
</style>
</head>
<body>
<div class="box">
  <h1>Demo restaurant</h1>
  <p>Creates <b><?php echo $h(DEMO_NAME); ?></b>: 22 dishes with photos, descriptions and sizes, bestseller offers,
     4 coupons, 10 tables, a logo and a header photo. It's labelled as a demo on the website, and orders placed on it
     are test orders.</p>

  <?php if ($message): ?><div class="msg ok"><?php echo $h($message); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?php echo $h($error); ?></div><?php endif; ?>

  <?php if ($newPassword): ?>
  <div class="cred">
    <b>Admin login for the demo (shown only once — save it now):</b><br>
    Username: <code><?php echo $h(DEMO_USERNAME); ?></code><br>
    Password: <code><?php echo $h($newPassword); ?></code><br>
    Admin login: <a href="<?php echo $h($mainUrl . '/admin/login.php'); ?>" target="_blank" rel="noopener"><?php echo $h($mainUrl . '/admin/login.php'); ?></a>
  </div>
  <?php endif; ?>

  <?php if ($exists): ?>
    <p>Status: <b>created</b>. Customer website:
       <a href="<?php echo $h($publicUrl); ?>" target="_blank" rel="noopener"><?php echo $h($publicUrl); ?></a></p>
    <form method="post" class="row" onsubmit="return confirm('Remove the demo restaurant and everything placed on it (including test orders)?');">
      <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
      <input type="hidden" name="action" value="remove">
      <button class="btn danger" type="submit">Remove demo restaurant</button>
      <a class="btn" style="background:#f3f4f6;color:#111827" href="dashboard.php">Back to dashboard</a>
    </form>
  <?php else: ?>
    <p>Status: <b>not created</b>.</p>
    <form method="post" class="row">
      <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
      <input type="hidden" name="action" value="create">
      <button class="btn primary" type="submit">Create demo restaurant</button>
      <a class="btn" style="background:#f3f4f6;color:#111827" href="dashboard.php">Back to dashboard</a>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
