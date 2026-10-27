<?php
/**
 * The public demo restaurant: "Spice Garden Indiranagar" (login demo / demo).
 *
 * One place builds it, used by:
 *   - php main/database/seed_demo_restaurant.php        (local, from the command line)
 *   - Super Admin › Demo restaurant › "Reset demo"       (live server)
 *   - main/cron/reset_demo_restaurant.php                (optional; not scheduled, the owner resets from Super Admin)
 *
 * resetDemoRestaurant() deletes every row of the demo restaurant ID and builds it again:
 * settings, logo, 10 menus with ~55 dishes (photos, descriptions, sizes, breakfast hours),
 * tables, staff, customers, six weeks of orders, today's live orders and kitchen tickets,
 * bookings, coupons, bestsellers and today's specials.
 *
 * Inserts go through demoInsert(), which drops columns the database doesn't have, so an
 * older live schema still gets a working demo (see the live-vs-local schema notes).
 * Photos: main/assets/demo_restaurant/ (credits in /demo-credits.txt).
 */

const DEMO_RESTAURANT_ID   = 'RESSGI002';
const DEMO_USERNAME        = 'demo';
const DEMO_PASSWORD        = 'demo';
const DEMO_RESTAURANT_NAME = 'Spice Garden Indiranagar';
const DEMO_STAFF_PASSWORD  = 'demo';
// usernames the demo restaurant row may already have (the local multi-branch seed used sg_indiranagar)
const DEMO_KNOWN_USERNAMES = ['demo', 'sg_indiranagar'];
// The first demo, made by an older version of the Super Admin page. Removed on reset.
const DEMO_LEGACY_ID       = 'RESDEMO01';
const DEMO_LEGACY_USERNAME = 'demo_spicegarden';
// The demo's own email address domain: shown on the site like a real one, but mail_engine never sends to it
const DEMO_EMAIL_DOMAIN    = 'spicegardenindiranagar.com';
// Staff: key (used by /demo and the Staff app demo, ?as=) => [name, email name, role]. Log in with password DEMO_STAFF_PASSWORD.
const DEMO_STAFF = [
    'manager' => ['Neha Kapoor', 'neha', 'Manager'], 'waiter' => ['Ravi Kumar', 'ravi', 'Waiter'], 'waiter2' => ['Imran Khan', 'imran', 'Waiter'],
    'chef' => ['Suresh Gowda', 'suresh', 'Chef'], 'chef2' => ['Lakshmi Iyer', 'lakshmi', 'Chef'], 'bartender' => ['Tenzin Lama', 'tenzin', 'Bartender'],
    'rider' => ['Arjun Singh', 'arjun', 'Delivery'], 'rider2' => ['Faisal Ahmed', 'faisal', 'Delivery'], 'cashier' => ['Kavita Rao', 'kavita', 'Cashier'],
];
const DEMO_CUSTOMER_NAMES = ['Aarav Sharma', 'Diya Patel', 'Rohan Iyer', 'Ananya Reddy', 'Kabir Mehta', 'Isha Nair', 'Vivaan Gupta', 'Meera Joshi',
    'Arjun Rao', 'Saanvi Kulkarni', 'Aditya Menon', 'Priya Das', 'Karthik Subramanian', 'Nisha Hegde', 'Rahul Verma',
    'Sneha Pillai', 'Vikram Shetty', 'Pooja Bhat', 'Siddharth Jain', 'Kavya Murthy', 'Manish Agarwal', 'Ritika Sen',
    'Harsh Vardhan', 'Divya Krishnan', 'Nikhil Desai', 'Tanvi Saxena', 'Amit Chauhan', 'Shreya Ghosh', 'Varun Prasad',
    'Lavanya Rao', 'Farhan Ali', 'Neha Bansal', 'Gautam Naidu', 'Swati Mishra', 'Rajesh Gowda', 'Anjali Thomas',
    'Deepak Yadav', 'Zoya Shaikh', 'Sameer Kulkarni', 'Bhavana Reddy'];

/** True when the logged-in session belongs to the public demo restaurant. */
function isDemoRestaurantSession(): bool {
    return ($_SESSION['restaurant_id'] ?? null) === DEMO_RESTAURANT_ID;
}

/** A normal-looking 10-digit mobile number for a made-up person (same name, same number; WhatsApp never sends for the demo). */
function demoPhone(string $name): string {
    $starts = ['98', '99', '97', '96', '95', '94', '93', '91', '89', '88', '87', '86', '85', '81', '80', '79', '78', '77', '76', '74', '73', '70', '63', '62'];
    $h = crc32($name);
    return $starts[$h % count($starts)] . str_pad((string)(intdiv($h, 7) % 100000000), 8, '0', STR_PAD_LEFT);
}

/** Login email of a demo staff member (DEMO_STAFF key). */
function demoStaffEmail(string $key): string {
    return DEMO_STAFF[$key][1] . '@' . DEMO_EMAIL_DOMAIN;
}

/** A login with the demo's public credentials (owner demo/demo or a demo staff member with password demo). */
function isDemoLogin(string $username, string $password): bool {
    $u = strtolower(trim($username));
    if ($u === DEMO_USERNAME) return $password === DEMO_PASSWORD;
    if ($password !== DEMO_STAFF_PASSWORD) return false;
    foreach (array_keys(DEMO_STAFF) as $key) if ($u === demoStaffEmail($key)) return true;
    return false;
}

/** A normal-looking personal email for a made-up customer, or null (some customers never gave one). */
function demoCustomerEmail(string $name): ?string {
    $h = crc32('mail ' . $name);
    if ($h % 4 === 0) return null;
    [$first, $last] = array_pad(explode(' ', strtolower($name), 2), 2, '');
    $local = [$first . '.' . $last, $first . $last . ($h % 90 + 10), $first . '_' . substr($last, 0, 1) . ($h % 900 + 100)][$h % 3];
    return $local . '@' . ['gmail.com', 'gmail.com', 'gmail.com', 'yahoo.co.in', 'outlook.com'][intdiv($h, 3) % 5];
}

/** True for any address the demo restaurant made up (owner, staff, customers): mail_engine never sends to these. */
function demoOwnsEmail(string $email): bool {
    $email = strtolower(trim($email));
    if (substr((string)strrchr($email, '@'), 1) === DEMO_EMAIL_DOMAIN) return true;
    static $customers = null;
    if ($customers === null) $customers = array_filter(array_map('demoCustomerEmail', DEMO_CUSTOMER_NAMES));
    return in_array($email, $customers, true);
}

/** Website slug of the demo restaurant (same rule as main/website/router.php). */
function demoRestaurantSlug(): string {
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(DEMO_RESTAURANT_NAME)), '-');
}

/** Columns of a table, cached. Empty array when the table doesn't exist. */
function demoColumns(PDO $pdo, string $table): array {
    static $cache = [];
    if (!isset($cache[$table])) {
        try {
            $cache[$table] = array_flip($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            $cache[$table] = [];
        }
    }
    return $cache[$table];
}

/** INSERT that skips columns this database doesn't have. Returns the new id (0 if the table is missing). */
function demoInsert(PDO $pdo, string $table, array $row): int {
    $cols = demoColumns($pdo, $table);
    if (!$cols) return 0;
    $row = array_intersect_key($row, $cols);
    $names = implode(', ', array_map(fn($c) => "`$c`", array_keys($row)));
    $marks = implode(', ', array_fill(0, count($row), '?'));
    $pdo->prepare("INSERT INTO `$table` ($names) VALUES ($marks)")->execute(array_values($row));
    return (int)$pdo->lastInsertId();
}

/** Deletes every row that belongs to one restaurant ID, including child rows keyed by parent ids. */
function demoRemoveRestaurant(PDO $pdo, string $rid): void {
    $children = [
        'DELETE v FROM menu_item_variations v JOIN menu_items mi ON mi.id = v.menu_item_id WHERE mi.restaurant_id = ?',
        'DELETE s FROM subcategories s JOIN menu m ON m.id = s.menu_id WHERE m.restaurant_id = ?',
        'DELETE oia FROM order_item_addons oia JOIN order_items oi ON oi.id = oia.order_item_id JOIN orders o ON o.id = oi.order_id WHERE o.restaurant_id = ?',
        'DELETE oi FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.restaurant_id = ?',
        'DELETE ki FROM kot_items ki JOIN kot k ON k.id = ki.kot_id WHERE k.restaurant_id = ?',
        'DELETE l FROM inventory_po_lines l JOIN inventory_purchase_orders p ON p.id = l.po_id WHERE p.restaurant_id = ?',
    ];
    foreach ($children as $sql) {
        try { $pdo->prepare($sql)->execute([$rid]); } catch (PDOException $e) { /* table not present */ }
    }
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'restaurant_id'")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        if ($t === 'users' || $t === 'branch_restaurant_links') continue; // users last; branch links are kept
        try { $pdo->prepare("DELETE FROM `$t` WHERE restaurant_id = ?")->execute([$rid]); } catch (PDOException $e) {}
    }
    $pdo->prepare('DELETE FROM users WHERE restaurant_id = ?')->execute([$rid]);
}

/** Refuses to touch a restaurant row that isn't the demo (a real restaurant that happens to use the ID). */
function demoAssertSafe(PDO $pdo): void {
    $st = $pdo->prepare('SELECT username FROM users WHERE restaurant_id = ?');
    $st->execute([DEMO_RESTAURANT_ID]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) {
        if (!in_array($u, DEMO_KNOWN_USERNAMES, true)) {
            throw new RuntimeException('Restaurant ID ' . DEMO_RESTAURANT_ID . " belongs to \"$u\", not the demo. Nothing was changed.");
        }
    }
    $st = $pdo->prepare('SELECT restaurant_id FROM users WHERE username = ? AND restaurant_id <> ?');
    $st->execute([DEMO_USERNAME, DEMO_RESTAURANT_ID]);
    if ($rid = $st->fetchColumn()) {
        throw new RuntimeException('The username "' . DEMO_USERNAME . "\" is already used by restaurant $rid. Nothing was changed.");
    }
}

function demoMenuData(): array {
    // menu => [sort, photo for the category tile, hours [days, from, to] or null, subcategories, dishes]
    // dish: [name, type, price, description, calories, prep minutes, sizes [name => price], photo, subcategory]
    return [
        'Breakfast' => [7, 'masala-dosa.jpg', ['', '08:00:00', '11:30:00'], [], [
            ['Masala Dosa', 'Veg', 119, 'Crisp, golden rice-and-lentil crêpe folded over a spiced potato filling. Served with coconut chutney and hot sambar.', 380, 12, [], 'masala-dosa'],
            ['Idli Vada', 'Veg', 89, 'Two steamed rice idlis and one crunchy medu vada with sambar and coconut chutney. The Bengaluru breakfast.', 340, 8, [], 'idli-vada'],
            ['Kanda Poha', 'Veg', 79, 'Flattened rice tempered with mustard, curry leaves, onion and peanuts, finished with lemon, sev and coriander.', 270, 8, [], 'poha'],
            ['Egg Bhurji Pav', 'Egg', 149, 'Street-style spiced scrambled eggs with onion, tomato and green chilli, finished with butter. Comes with two toasted pav.', 410, 10, [], 'egg-bhurji'],
        ]],
        'Soups' => [1, 'tomato-shorba.jpg', null, [], [
            ['Tomato Shorba', 'Veg', 149, 'Slow-simmered tomato broth with roasted cumin, ginger and a touch of cream. Light, tangy and warming.', 140, 10, [], 'tomato-shorba'],
            ['Chicken Clear Soup', 'Non Veg', 169, 'Clear chicken broth with shredded chicken, fresh greens and cracked black pepper.', 160, 12, [], 'chicken-clear-soup'],
        ]],
        'Starters' => [2, 'cat-starters.webp', null, ['Veg Starters', 'Non-Veg Starters'], [
            ['Paneer Tikka', 'Veg', 249, 'Cottage cheese marinated overnight in hung curd, kashmiri chilli and ajwain, chargrilled in the tandoor with peppers and onion. With mint chutney.', 320, 18, [], 'paneer-tikka', 'Veg Starters'],
            ['Hara Bhara Kebab', 'Veg', 219, 'Pan-crisped patties of spinach, green peas and potato, spiced with ginger and green chilli, topped with a cashew.', 260, 15, [], 'hara-bhara-kebab', 'Veg Starters'],
            ['Chilli Paneer', 'Veg', 239, 'Crispy paneer tossed in a hot wok with capsicum, onion, soy and green chillies. Indo-Chinese favourite.', 360, 15, ['Dry' => 239, 'Gravy' => 259], 'chilli-paneer', 'Veg Starters'],
            ['Veg Spring Rolls', 'Veg', 189, 'Crackly rolls stuffed with stir-fried cabbage, carrot, spring onion and glass noodles, with sweet chilli dip.', 280, 12, ['4 pcs' => 189, '8 pcs' => 339], 'veg-spring-rolls', 'Veg Starters'],
            ['Punjabi Samosa', 'Veg', 69, 'Two flaky, hand-folded samosas filled with spiced potato and peas. Served with tamarind and mint chutney.', 310, 8, [], 'samosa', 'Veg Starters'],
            ['Chicken 65', 'Non Veg', 279, 'Crispy, fiery Chennai-style fried chicken tossed with curry leaves, green chillies and a squeeze of lime.', 410, 15, [], 'chicken-65', 'Non-Veg Starters'],
            ['Chicken Tikka', 'Non Veg', 299, 'Boneless chicken thigh in a smoky yogurt and red chilli marinade, skewered and roasted in the tandoor.', 380, 18, [], 'chicken-tikka', 'Non-Veg Starters'],
            ['Tandoori Chicken', 'Non Veg', 329, 'Bone-in chicken marinated in yogurt, ginger-garlic and house tandoori masala, roasted in the clay oven until charred at the edges.', 450, 25, ['Half' => 329, 'Full' => 599], 'tandoori-chicken', 'Non-Veg Starters'],
            ['Mutton Seekh Kebab', 'Non Veg', 349, 'Minced mutton with onion, ginger, green chilli and garam masala, hand-pressed on skewers and grilled. Served with onion rings.', 420, 20, [], 'seekh-kebab', 'Non-Veg Starters'],
            ['Amritsari Fish', 'Non Veg', 329, 'Basa fillet in a carom-spiced gram flour batter, fried crisp. Finished with chaat masala and lemon.', 390, 15, [], 'fish-amritsari', 'Non-Veg Starters'],
        ]],
        'Main Course' => [3, 'cat-main-course.webp', null, ['Veg Curries', 'Non-Veg Curries'], [
            ['Paneer Butter Masala', 'Veg', 299, 'Soft paneer in a rich, mildly sweet tomato-butter gravy finished with fresh cream. Best with garlic naan.', 480, 18, ['Half' => 299, 'Full' => 539], 'paneer-butter-masala', 'Veg Curries'],
            ['Kadai Paneer', 'Veg', 289, 'Paneer, capsicum and onion cooked in an iron kadai with freshly pounded coriander and red chilli.', 440, 18, [], 'kadai-paneer', 'Veg Curries'],
            ['Palak Paneer', 'Veg', 279, 'Paneer cubes in a smooth, garlicky spinach gravy finished with a little cream.', 390, 18, [], 'palak-paneer', 'Veg Curries'],
            ['Malai Kofta', 'Veg', 289, 'Paneer and potato dumplings stuffed with dry fruits, in a silky cashew and tomato gravy.', 520, 20, [], 'malai-kofta', 'Veg Curries'],
            ['Dal Makhani', 'Veg', 229, 'Whole black lentils and rajma slow-cooked overnight on tandoor embers, finished with butter and cream.', 390, 15, ['Half' => 229, 'Full' => 399], 'dal-makhani', 'Veg Curries'],
            ['Dal Tadka', 'Veg', 199, 'Yellow lentils tempered with ghee, cumin, garlic and dried red chillies.', 280, 12, [], 'dal-tadka', 'Veg Curries'],
            ['Pindi Chole', 'Veg', 219, 'Chickpeas simmered with tea leaves and whole spices for a dark, tangy Punjabi-style curry.', 340, 15, [], 'chole', 'Veg Curries'],
            ['Butter Chicken', 'Non Veg', 349, 'Our signature: tandoor-roasted chicken in a silky tomato, butter and cashew gravy with kasuri methi. Mild and creamy.', 540, 20, ['Half' => 349, 'Full' => 629], 'butter-chicken', 'Non-Veg Curries'],
            ['Chicken Tikka Masala', 'Non Veg', 359, 'Charred chicken tikka folded into a spiced onion-tomato masala with peppers.', 520, 20, [], 'chicken-tikka-masala', 'Non-Veg Curries'],
            ['Kadai Chicken', 'Non Veg', 339, 'Chicken tossed in a hot kadai with capsicum, tomato and coarsely ground kadai masala.', 480, 20, [], 'kadai-chicken', 'Non-Veg Curries'],
            ['Mutton Rogan Josh', 'Non Veg', 429, 'Kashmiri-style tender mutton braised with whole spices, fennel and ratan jot. Rich but not too hot.', 560, 30, [], 'mutton-rogan-josh', 'Non-Veg Curries'],
            ['Kerala Fish Curry', 'Non Veg', 379, 'Seer fish simmered in coconut milk with kokum, curry leaves and green chilli.', 430, 20, [], 'fish-curry', 'Non-Veg Curries'],
            ['Egg Curry', 'Egg', 219, 'Boiled eggs in a home-style onion-tomato gravy with a hint of garam masala.', 360, 15, [], 'egg-curry', 'Non-Veg Curries'],
        ]],
        'Biryani & Rice' => [4, 'chicken-biryani.jpg', null, [], [
            ['Chicken Dum Biryani', 'Non Veg', 319, 'Long-grain basmati layered with masala chicken, fried onions, mint and saffron, sealed and slow-cooked. With raita and salan.', 680, 25, ['Regular' => 319, 'Family pack (serves 3)' => 849], 'chicken-biryani'],
            ['Mutton Biryani', 'Non Veg', 399, 'Tender mutton and aged basmati dum-cooked with whole spices and saffron. With raita.', 720, 30, [], 'mutton-biryani'],
            ['Veg Dum Biryani', 'Veg', 259, 'Basmati dum-cooked with seasonal vegetables, paneer, whole spices and saffron. With raita.', 520, 25, ['Regular' => 259, 'Family pack (serves 3)' => 689], 'veg-biryani'],
            ['Rajma Chawal', 'Veg', 199, 'Home-style kidney bean curry with steamed basmati rice and onion salad.', 480, 10, [], 'rajma-chawal'],
            ['Jeera Rice', 'Veg', 149, 'Basmati rice tempered with ghee and cumin.', 300, 8, [], 'jeera-rice'],
            ['Curd Rice', 'Veg', 139, 'Soft rice in cool curd with a mustard, curry leaf and ginger tempering, topped with pomegranate.', 290, 5, [], 'curd-rice'],
        ]],
        'Breads' => [5, 'cat-breads.webp', null, [], [
            ['Butter Naan', 'Veg', 59, 'Soft, pillowy leavened bread baked in the tandoor and brushed with butter.', 260, 8, [], 'butter-naan'],
            ['Garlic Naan', 'Veg', 69, 'Tandoor-baked naan topped with chopped garlic and coriander, finished with butter.', 290, 8, [], 'garlic-naan'],
            ['Cheese Naan', 'Veg', 99, 'Naan stuffed with molten cheese and a little green chilli.', 380, 10, [], 'cheese-naan'],
            ['Tandoori Roti', 'Veg', 35, 'Whole-wheat flatbread from the clay oven. Light and slightly crisp.', 120, 6, [], 'tandoori-roti'],
            ['Laccha Paratha', 'Veg', 65, 'Flaky, layered whole-wheat paratha cooked in the tandoor with ghee.', 300, 8, [], 'laccha-paratha'],
            ['Amritsari Kulcha', 'Veg', 89, 'Crisp tandoor kulcha stuffed with spiced potato and onion, topped with butter.', 360, 10, [], 'kulcha'],
        ]],
        'Thalis' => [6, 'veg-thali.jpg', ['', '12:00:00', '15:30:00'], [], [
            ['Veg Thali', 'Veg', 299, 'Lunch thali: paneer of the day, dal makhani, seasonal sabzi, jeera rice, two rotis, raita, salad and a gulab jamun.', 880, 15, [], 'veg-thali'],
            ['Non-Veg Thali', 'Non Veg', 399, 'Lunch thali: butter chicken, dal, seasonal sabzi, jeera rice, two rotis, raita, salad and a gulab jamun.', 980, 15, [], 'nonveg-thali'],
        ]],
        'Beverages' => [8, 'cat-beverages.webp', null, [], [
            ['Masala Chai', 'Drink', 49, 'Strong Assam tea brewed with milk, fresh ginger, cardamom and our own chai masala.', 110, 5, [], 'masala-chai'],
            ['Filter Coffee', 'Drink', 59, 'South Indian decoction coffee with frothy hot milk, served in a davara-tumbler.', 120, 5, [], 'filter-coffee'],
            ['Sweet Lassi', 'Drink', 89, 'Thick Punjabi-style churned yogurt drink, lightly sweetened and topped with malai.', 240, 5, ['Regular' => 89, 'Large' => 139], 'sweet-lassi'],
            ['Masala Chaas', 'Drink', 69, 'Chilled spiced buttermilk with roasted cumin, ginger and coriander.', 70, 3, [], 'buttermilk'],
            ['Fresh Lime Soda', 'Drink', 79, 'Freshly squeezed lime with chilled soda. Sweet, salted or mixed.', 90, 5, [], 'fresh-lime-soda'],
            ['Cold Coffee', 'Drink', 129, 'Creamy blended cold coffee with a scoop of vanilla ice cream.', 320, 5, [], 'cold-coffee'],
        ]],
        'Desserts' => [9, 'cat-desserts.webp', null, [], [
            ['Gulab Jamun', 'Veg', 99, 'Two warm milk-solid dumplings soaked in cardamom and rose syrup.', 330, 5, [], 'gulab-jamun'],
            ['Rasmalai', 'Veg', 129, 'Soft chenna discs in chilled saffron-cardamom milk, topped with pistachio.', 290, 5, [], 'rasmalai'],
            ['Kesar Pista Kulfi', 'Veg', 109, 'Slow-reduced milk ice cream with saffron and pistachio, on a stick.', 250, 3, [], 'kulfi'],
            ['Gajar Halwa', 'Veg', 129, 'Grated carrots slow-cooked in milk and ghee with cardamom, cashews and raisins. Served warm.', 360, 5, [], 'gajar-halwa'],
            ['Phirni', 'Veg', 109, 'Chilled ground-rice pudding set in a clay pot, with cardamom and slivered almonds.', 240, 3, [], 'phirni'],
        ]],
    ];
}

/**
 * Nutrition per serving for every demo dish (dish details › "Nutritional info" and "What's inside").
 * dish => [protein g, fat g, carbs g, fibre g, health tags, ingredients (one per line, optional note after " - ")].
 * The grams add up to the calories in demoMenuData() (4 kcal per g of protein/carbs, 9 per g of fat).
 */
function demoNutritionData(): array {
    return [
        'Masala Dosa' => [8, 14, 56, 4, ['gluten_free'], "Rice & urad dal batter - fermented overnight, light on the stomach\nPotato masala - onion, mustard seeds, turmeric\nCoconut chutney - healthy fats from fresh coconut\nSambar - lentils and vegetables for protein and fibre\nCurry leaves"],
        'Idli Vada' => [11, 12, 47, 4, ['gluten_free'], "Steamed rice idli - no oil, easy to digest\nUrad dal vada - plant protein\nSambar - toor dal with drumstick and vegetables\nCoconut chutney"],
        'Kanda Poha' => [6, 8, 43, 3, ['vegan', 'gluten_free'], "Flattened rice - light, iron-fortified\nPeanuts - protein and good fats\nOnion\nCurry leaves & mustard seeds\nLemon - vitamin C"],
        'Egg Bhurji Pav' => [20, 20, 37, 2, ['high_protein'], "Farm eggs (3) - complete protein\nOnion, tomato & green chilli\nButter\nPav (2)"],
        'Tomato Shorba' => [3, 6, 18, 3, ['low_calorie', 'gluten_free'], "Ripe tomatoes - lycopene and vitamin C\nRoasted cumin\nFresh ginger - good for digestion\nA little cream"],
        'Chicken Clear Soup' => [20, 5, 8, 1, ['high_protein', 'low_calorie', 'low_carb', 'low_fat', 'gluten_free', 'dairy_free'], "Chicken broth - slow-simmered from bones\nShredded chicken breast - lean protein\nSeasonal greens\nCracked black pepper"],
        'Paneer Tikka' => [20, 22, 10, 2, ['high_protein', 'low_carb', 'keto', 'gluten_free'], "Paneer - excellent source of protein and calcium\nHung curd - probiotic marinade\nBell peppers & onion - vitamin C\nKashmiri chilli & ajwain\nMint chutney"],
        'Hara Bhara Kebab' => [7, 12, 31, 6, ['high_fibre'], "Spinach - rich in iron and fibre\nGreen peas - plant protein\nPotato\nGinger & green chilli\nCashew"],
        'Chilli Paneer' => [17, 24, 19, 2, ['high_protein'], "Paneer - protein and calcium\nCapsicum & onion\nSoy sauce\nGreen chillies\nCornflour coating"],
        'Veg Spring Rolls' => [5, 13, 36, 3, ['vegan', 'dairy_free'], "Cabbage & carrot\nSpring onion\nGlass noodles\nSpring roll pastry\nSweet chilli dip"],
        'Punjabi Samosa' => [5, 17, 35, 3, ['vegan'], "Potato & green peas\nWhole spices - cumin, coriander, amchur\nFlaky pastry\nTamarind & mint chutney"],
        'Chicken 65' => [30, 26, 14, 1, ['high_protein'], "Boneless chicken - lean protein\nCurry leaves\nGreen chillies & ginger-garlic\nRice flour coating\nLime"],
        'Chicken Tikka' => [42, 20, 7, 1, ['high_protein', 'low_carb', 'keto', 'gluten_free'], "Boneless chicken thigh - 42 g protein per plate\nYogurt marinade - probiotic\nKashmiri red chilli\nGinger-garlic\nMustard oil - cooked in the tandoor, not fried"],
        'Tandoori Chicken' => [48, 25, 8, 1, ['high_protein', 'low_carb', 'keto', 'gluten_free'], "Bone-in chicken - roasted, not fried\nYogurt - probiotic marinade\nGinger-garlic\nHouse tandoori masala\nLemon & onion rings"],
        'Mutton Seekh Kebab' => [32, 28, 10, 1, ['high_protein', 'low_carb', 'gluten_free', 'dairy_free'], "Minced mutton - protein, iron and B12\nOnion & green chilli\nFresh ginger\nGaram masala\nGrilled on skewers"],
        'Amritsari Fish' => [28, 20, 24, 2, ['high_protein', 'gluten_free', 'dairy_free'], "Basa fillet - lean protein\nGram flour batter - naturally gluten free\nCarom seeds (ajwain)\nChaat masala & lemon"],
        'Paneer Butter Masala' => [18, 36, 21, 3, ['high_protein', 'gluten_free'], "Paneer - protein and calcium\nTomato gravy\nButter & fresh cream\nCashew paste\nKasuri methi"],
        'Kadai Paneer' => [19, 32, 18, 4, ['high_protein', 'low_carb', 'gluten_free'], "Paneer - protein and calcium\nCapsicum & onion - vitamin C\nTomato\nFreshly pounded coriander & red chilli"],
        'Palak Paneer' => [19, 28, 14, 5, ['high_protein', 'high_fibre', 'low_carb', 'gluten_free'], "Spinach - rich in iron, folate and fibre\nPaneer - protein and calcium\nGarlic\nA little cream"],
        'Malai Kofta' => [12, 38, 33, 3, [], "Paneer & potato dumplings\nDry fruits - cashew and raisins\nCashew-tomato gravy\nFresh cream"],
        'Dal Makhani' => [14, 20, 38, 9, ['high_fibre', 'gluten_free'], "Whole black urad - plant protein and fibre\nRajma\nTomato\nButter & cream - slow-cooked overnight"],
        'Dal Tadka' => [13, 9, 36, 8, ['high_fibre', 'gluten_free'], "Yellow toor & moong dal - plant protein and fibre\nGhee tempering\nCumin & garlic\nDried red chillies"],
        'Pindi Chole' => [13, 12, 45, 11, ['high_fibre', 'vegan', 'gluten_free'], "Chickpeas - plant protein and 11 g fibre\nTea leaves - for the dark colour\nWhole spices\nAmchur & pomegranate seed"],
        'Butter Chicken' => [38, 36, 16, 2, ['high_protein', 'gluten_free'], "Tandoori chicken - 38 g protein\nTomato gravy\nButter & cashew\nKasuri methi\nFresh cream"],
        'Chicken Tikka Masala' => [40, 32, 17, 3, ['high_protein', 'gluten_free'], "Chargrilled chicken tikka - lean protein\nOnion-tomato masala\nBell peppers\nWhole spices"],
        'Kadai Chicken' => [40, 30, 12, 3, ['high_protein', 'low_carb', 'keto', 'gluten_free', 'dairy_free'], "Chicken - 40 g protein\nCapsicum & tomato\nCoarse kadai masala\nCooked in an iron kadai"],
        'Mutton Rogan Josh' => [38, 40, 12, 2, ['high_protein', 'low_carb', 'keto', 'gluten_free'], "Mutton - protein, iron and B12\nKashmiri chilli\nFennel & dry ginger\nRatan jot\nWhole spices"],
        'Kerala Fish Curry' => [32, 28, 12, 2, ['high_protein', 'low_carb', 'gluten_free', 'dairy_free'], "Seer fish - lean protein and omega-3\nCoconut milk\nKokum\nCurry leaves & green chilli"],
        'Egg Curry' => [17, 26, 14, 3, ['high_protein', 'low_carb', 'gluten_free', 'dairy_free'], "Boiled eggs (2) - complete protein\nOnion-tomato gravy\nGaram masala"],
        'Chicken Dum Biryani' => [38, 24, 78, 3, ['high_protein', 'gluten_free'], "Aged basmati rice\nMasala chicken - 38 g protein\nFried onions & mint\nSaffron\nRaita & salan on the side"],
        'Mutton Biryani' => [36, 28, 80, 3, ['high_protein', 'gluten_free'], "Aged basmati rice\nTender mutton - protein and iron\nWhole spices & saffron\nRaita on the side"],
        'Veg Dum Biryani' => [14, 18, 76, 6, ['gluten_free'], "Basmati rice\nSeasonal vegetables - fibre\nPaneer - protein\nWhole spices & saffron\nRaita on the side"],
        'Rajma Chawal' => [17, 10, 80, 13, ['high_fibre', 'vegan', 'gluten_free'], "Kidney beans - plant protein and 13 g fibre\nOnion-tomato masala\nSteamed basmati rice\nOnion salad"],
        'Jeera Rice' => [5, 7, 54, 1, ['gluten_free'], "Basmati rice\nCumin seeds\nGhee"],
        'Curd Rice' => [8, 8, 46, 1, ['gluten_free'], "Rice\nFresh curd - probiotic, cools the stomach\nMustard & curry leaf tempering\nGinger\nPomegranate"],
        'Butter Naan' => [7, 8, 40, 2, [], "Refined flour\nYogurt\nButter"],
        'Garlic Naan' => [8, 9, 44, 2, [], "Refined flour\nFresh garlic\nCoriander\nButter"],
        'Cheese Naan' => [13, 15, 48, 2, [], "Refined flour\nMozzarella & processed cheese - extra protein\nGreen chilli\nButter"],
        'Tandoori Roti' => [4, 1, 24, 4, ['vegan', 'high_fibre', 'low_fat', 'low_calorie'], "Whole wheat - fibre and slow carbs\nWater & salt\nNo oil - baked in the clay oven"],
        'Laccha Paratha' => [6, 12, 42, 4, [], "Whole wheat flour\nGhee - for the flaky layers"],
        'Amritsari Kulcha' => [8, 13, 53, 3, [], "Refined flour\nSpiced potato & onion\nButter"],
        'Veg Thali' => [26, 34, 118, 14, ['high_protein', 'high_fibre'], "Paneer of the day - protein\nDal makhani - fibre\nSeasonal sabzi\nJeera rice & 2 rotis\nRaita & salad\nGulab jamun"],
        'Non-Veg Thali' => [44, 38, 114, 11, ['high_protein', 'high_fibre'], "Butter chicken - 30 g protein\nDal - fibre\nSeasonal sabzi\nJeera rice & 2 rotis\nRaita & salad\nGulab jamun"],
        'Masala Chai' => [3, 4, 16, 0, ['low_calorie', 'gluten_free'], "Assam tea\nMilk\nFresh ginger & cardamom\nSugar"],
        'Filter Coffee' => [4, 5, 15, 0, ['low_calorie', 'gluten_free'], "Coffee-chicory decoction\nFrothy hot milk\nSugar"],
        'Sweet Lassi' => [8, 8, 34, 0, ['gluten_free'], "Churned yogurt - probiotic and protein\nSugar\nMalai"],
        'Masala Chaas' => [3, 3, 7, 0, ['low_calorie', 'low_fat', 'low_sugar', 'gluten_free'], "Buttermilk - probiotic, aids digestion\nRoasted cumin\nGinger & coriander"],
        'Fresh Lime Soda' => [0, 0, 23, 0, ['vegan', 'low_fat', 'gluten_free', 'dairy_free'], "Fresh lime - vitamin C\nChilled soda\nSugar or salt, as you like"],
        'Cold Coffee' => [8, 14, 41, 0, ['gluten_free'], "Coffee\nChilled milk\nVanilla ice cream"],
        'Gulab Jamun' => [5, 14, 46, 1, [], "Khoya (milk solids)\nCardamom & rose syrup"],
        'Rasmalai' => [9, 14, 32, 0, ['gluten_free'], "Chenna - milk protein\nSaffron-cardamom milk\nPistachio"],
        'Kesar Pista Kulfi' => [7, 14, 25, 0, ['gluten_free'], "Slow-reduced milk\nSaffron\nPistachio"],
        'Gajar Halwa' => [7, 18, 43, 4, ['gluten_free'], "Carrots - vitamin A and fibre\nMilk & ghee\nCashews & raisins\nCardamom"],
        'Phirni' => [6, 9, 34, 1, ['gluten_free'], "Ground rice\nMilk\nCardamom\nAlmonds"],
    ];
}

/**
 * Nutrition on every dish, and Meal plans (tiffin subscriptions) switched on with fitness plans,
 * a weekly menu and a few subscribers. Runs after the main build, outside its transaction (it may add
 * columns). The website look is not touched. $items: dish name => ['id' => ...].
 */
function demoHealthSetup(PDO $pdo, string $rid, string $assetDir, array $items): void {
    $read = fn(string $file) => @file_get_contents(rtrim($assetDir, '/\\') . '/' . $file) ?: null;

    // ── Nutritional info + What's inside ──
    require_once __DIR__ . '/ingredient_helpers.php';
    if (ensureMenuNutritionColumns($pdo) && ensureMenuIngredientsColumn($pdo)) {
        $up = $pdo->prepare('UPDATE menu_items SET protein_g = ?, fat_g = ?, carbs_g = ?, fibre_g = ?, health_tags = ?, ingredients = ? WHERE id = ? AND restaurant_id = ?');
        foreach (demoNutritionData() as $dish => [$protein, $fat, $carbs, $fibre, $tags, $ingr]) {
            if (!isset($items[$dish])) continue;
            $tags = array_values(array_intersect(array_keys(MENU_HEALTH_TAGS), $tags));
            $up->execute([$protein ?: null, $fat ?: null, $carbs ?: null, $fibre ?: null, $tags ? implode(',', $tags) : null,
                          cleanIngredients($ingr), $items[$dish]['id'], $rid]);
        }
    }

    // ── Meal plans ──
    require_once __DIR__ . '/meal_subscription_schema.php';
    ensureMealSubscriptionTables($pdo);
    $q = $pdo->query("SHOW COLUMNS FROM users LIKE 'meal_subscriptions_enabled'");
    if (!($q && $q->fetch())) $pdo->exec('ALTER TABLE users ADD COLUMN meal_subscriptions_enabled TINYINT(1) NOT NULL DEFAULT 0');
    $pdo->prepare('UPDATE users SET meal_subscriptions_enabled = 1 WHERE restaurant_id = ?')->execute([$rid]);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE p FROM meal_plan_payments p JOIN customer_meal_subscriptions s ON s.id = p.subscription_id WHERE s.restaurant_id = ?')->execute([$rid]);
        foreach (['customer_meal_subscriptions', 'meal_plan_weekly_menu_items', 'meal_plan_weekly_menu', 'meal_plans'] as $t) {
            $pdo->prepare("DELETE FROM `$t` WHERE restaurant_id = ?")->execute([$rid]);
        }

        // [name, description, scope, meals, price, bonus meals, photo]
        $plans = [];
        foreach ([
            ['High Protein Lunch', 'A fresh high-protein lunch box every day: tandoori chicken or paneer tikka, dal, salad and a whole-wheat roti. Around 40 g protein and 550 kcal a meal, with protein, carbs and fat on every dish.', 'lunch', 20, 4999, 2, 'tandoori-chicken.jpg'],
            ['Weight Loss Plan', 'Calorie-counted lunch and dinner under 500 kcal each: grilled tikkas, clear soups, dal and greens, no fried food and no refined flour. Ideal with a gym routine.', 'both', 24, 5499, 0, 'chicken-tikka.jpg'],
            ['Veg Fit Thali', 'Pure-veg balanced thali: paneer or chole, dal, sabzi, salad, curd and tandoori roti. 25 g+ plant and dairy protein, high in fibre.', 'lunch', 30, 6999, 3, 'veg-thali.jpg'],
            ['Muscle Gain Monthly', 'Lunch and dinner for the whole month for serious training: double chicken, eggs, paneer, rice and dal. 50 g+ protein and around 800 kcal a meal.', 'both', 60, 14999, 6, 'butter-chicken.jpg'],
        ] as $sort => [$name, $desc, $scope, $meals, $price, $bonus, $photo]) {
            $plans[$name] = ['id' => demoInsert($pdo, 'meal_plans', [
                'restaurant_id' => $rid, 'plan_name' => $name, 'description' => $desc, 'meal_scope' => $scope, 'total_meal_credits' => $meals,
                'price' => $price, 'bonus_credits' => $bonus, 'is_active' => 1, 'sort_order' => $sort,
                'image_data' => $read($photo), 'image_mime_type' => 'image/jpeg',
            ]), 'scope' => $scope, 'credits' => $meals + $bonus, 'price' => $price];
        }

        // Weekly menu, Monday (1) to Saturday (6); Sunday off. [lunch dishes, lunch note, dinner dishes, dinner note]
        $week = [
            1 => [['Tandoori Chicken', 'Dal Tadka', 'Tandoori Roti'], 'About 45 g protein · 560 kcal', ['Chicken Clear Soup', 'Palak Paneer', 'Tandoori Roti'], 'About 40 g protein · 520 kcal'],
            2 => [['Paneer Tikka', 'Pindi Chole', 'Curd Rice'], 'About 35 g protein · 590 kcal', ['Kerala Fish Curry', 'Jeera Rice'], 'About 37 g protein · 600 kcal'],
            3 => [['Chicken Tikka', 'Dal Makhani', 'Tandoori Roti'], 'About 52 g protein · 620 kcal', ['Tomato Shorba', 'Kadai Paneer', 'Tandoori Roti'], 'About 26 g protein · 520 kcal'],
            4 => [['Kadai Chicken', 'Rajma Chawal'], 'About 52 g protein · 680 kcal', ['Egg Curry', 'Dal Tadka', 'Tandoori Roti'], 'About 34 g protein · 520 kcal'],
            5 => [['Mutton Seekh Kebab', 'Dal Tadka', 'Tandoori Roti'], 'About 49 g protein · 600 kcal', ['Chicken Tikka Masala', 'Tandoori Roti'], 'About 44 g protein · 640 kcal'],
            6 => [['Chicken Dum Biryani', 'Masala Chaas'], 'Cheat-day biryani · about 41 g protein', ['Palak Paneer', 'Dal Tadka', 'Tandoori Roti'], 'About 36 g protein · 520 kcal'],
        ];
        $photoOf = [];
        foreach (demoMenuData() as [, , , , $dishes]) foreach ($dishes as $d) $photoOf[$d[0]] = $d[7] . '.jpg';
        foreach ($week as $day => [$lunch, $lunchNote, $dinner, $dinnerNote]) {
            foreach (['lunch' => [$lunch, $lunchNote], 'dinner' => [$dinner, $dinnerNote]] as $time => [$dishes, $note]) {
                demoInsert($pdo, 'meal_plan_weekly_menu', ['restaurant_id' => $rid, 'day_of_week' => $day, 'meal_time' => $time, 'menu_text' => $note, 'is_active' => 1]);
                foreach ($dishes as $p => $dish) {
                    demoInsert($pdo, 'meal_plan_weekly_menu_items', ['restaurant_id' => $rid, 'day_of_week' => $day, 'meal_time' => $time, 'item_name' => $dish,
                        'image_data' => $read($photoOf[$dish]), 'image_mime_type' => 'image/jpeg', 'sort_order' => $p, 'is_active' => 1]);
                }
            }
        }

        // Subscribers: [customer, plan, status, meals used, started days ago, payment method]
        $cust = $pdo->prepare('SELECT id, customer_name, phone FROM customers WHERE restaurant_id = ? AND customer_name = ?');
        $areas = ['HAL 2nd Stage', 'Domlur Layout', 'Indiranagar 1st Stage', 'Jeevan Bima Nagar', 'CMH Road', 'Defence Colony', 'Ulsoor', 'Kodihalli'];
        foreach ([
            ['Aarav Sharma', 'Muscle Gain Monthly', 'active', 22, 12, 'UPI'], ['Diya Patel', 'Weight Loss Plan', 'active', 9, 6, 'UPI'],
            ['Kabir Mehta', 'High Protein Lunch', 'active', 14, 17, 'Card'], ['Isha Nair', 'Veg Fit Thali', 'active', 11, 13, 'UPI'],
            ['Vivaan Gupta', 'High Protein Lunch', 'paused', 6, 10, 'Cash'], ['Meera Joshi', 'Weight Loss Plan', 'completed', 24, 20, 'UPI'],
            ['Rohan Iyer', 'Muscle Gain Monthly', 'active', 4, 2, 'Card'], ['Ananya Reddy', 'Veg Fit Thali', 'pending_payment', 0, 0, 'UPI'],
        ] as $n => [$cname, $plan, $status, $used, $ago, $method]) {
            $cust->execute([$rid, $cname]);
            $c = $cust->fetch(PDO::FETCH_ASSOC);
            if (!$c || empty($plans[$plan]['id'])) continue;
            $pl = $plans[$plan];
            $start = date('Y-m-d', strtotime("-$ago days"));
            $made = date('Y-m-d 10:15:00', strtotime('-' . ($ago + 1) . ' days'));
            $subId = demoInsert($pdo, 'customer_meal_subscriptions', [
                'restaurant_id' => $rid, 'customer_id' => $c['id'], 'meal_plan_id' => $pl['id'], 'plan_name_snapshot' => $plan,
                'meal_scope_snapshot' => $pl['scope'], 'credits_total' => $pl['credits'], 'credits_used' => $used, 'amount_paid' => $pl['price'],
                'delivery_address' => (101 + $n * 37) . ', ' . $areas[$n % count($areas)] . ', Bengaluru', 'delivery_phone' => $c['phone'],
                'status' => $status, 'paused_at' => $status === 'paused' ? date('Y-m-d 09:00:00', strtotime('-2 days')) : null,
                'start_date' => $status === 'pending_payment' ? date('Y-m-d', strtotime('+1 day')) : $start, 'created_at' => $made,
            ]);
            demoInsert($pdo, 'meal_plan_payments', [
                'restaurant_id' => $rid, 'subscription_id' => $subId, 'amount' => $pl['price'], 'payment_method' => $method,
                'payment_status' => $status === 'pending_payment' ? 'Pending' : 'Success',
                'reference_number' => $method === 'Cash' ? null : 'T' . date('ymd', strtotime($made)) . str_pad((string)(crc32($cname) % 1000000), 6, '0', STR_PAD_LEFT),
                'created_at' => $made,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * The demo's website offers and loyalty, run after the main build: offer banners, combos,
 * menu filter chips, "goes well with" suggestions, points + autopilot gifts, spin wheel,
 * challenges and the Spice Club VIP plans. $items: dish name => ['id' => ...].
 * Returns one callable(PDO, rid) per part.
 */
function demoOfferSetup(array $items): array {
    $id = fn(string $dish) => $items[$dish]['id'];
    $need = function (string $file, string ...$fns) {
        require_once __DIR__ . "/$file";
        foreach ($fns as $fn) if (!function_exists($fn)) throw new RuntimeException("$fn missing");
    };
    return [
        // animated offer banners on the home page, menu page and cart
        function (PDO $pdo, string $rid) use ($id, $need) {
            $need('website_theme_helpers.php', 'ensureOfferBannersTable');
            ensureOfferBannersTable($pdo);
            $n = 0;
            foreach ([
                ['countdown', 'Biryani Hour', 'Chicken Dum Biryani at ₹289 — today only', null, 'Order now', '#1b5e3d', 'Chicken Dum Biryani', 'home_top', ['kicker' => 'Today only', 'countdown' => 1, 'accent' => '#f4c95d']],
                ['ticket', 'Flat ₹150 OFF', 'On orders above ₹999 — applied automatically', 'FEAST150', null, '#b45309', null, 'home_top', ['kicker' => 'No code needed']],
                ['gold', 'Butter Chicken', 'Our No.1 bestseller — smoky, buttery, creamy', null, 'Add to cart', '#7c2d12', 'Butter Chicken', 'home_menu', ['kicker' => 'Chef’s pick', 'font' => 'serif']],
                ['marquee', 'Save more as you add', '₹40 off at ₹349 · ₹75 off at ₹599 · ₹150 off at ₹999', null, null, '#1b5e3d', 'Paneer Tikka', 'menu_top', ['speed' => 'calm']],
                ['stamp', 'Sweet ending?', 'Add warm Gulab Jamun for ₹99', null, 'Add', '#9d174d', 'Gulab Jamun', 'cart', []],
            ] as [$tpl, $title, $sub, $code, $cta, $color, $dish, $place, $opts]) {
                demoInsert($pdo, 'website_offer_banners', [
                    'restaurant_id' => $rid, 'template' => $tpl, 'title' => $title, 'subtitle' => $sub, 'code' => $code, 'cta' => $cta,
                    'color' => $color, 'menu_item_id' => $dish ? $id($dish) : null, 'placement' => $place, 'is_active' => 1,
                    'display_order' => $n++, 'opts' => $opts ? json_encode($opts) : null,
                ]);
            }
        },
        // combos
        function (PDO $pdo, string $rid) use ($id, $need) {
            $need('combo_helpers.php', 'saveCombo');
            $pick = fn(array $dishes) => array_map(fn($d) => ['menu_item_id' => $id($d)], $dishes);
            saveCombo($pdo, $rid, ['name' => 'Lunch Combo', 'price' => 249, 'badge' => 'Save ₹100', 'is_active' => 1,
                'description' => 'A curry, two breads and a cool drink — the easy weekday lunch.',
                'slots' => [['title' => 'Pick a curry', 'choices' => $pick(['Dal Makhani', 'Paneer Butter Masala', 'Pindi Chole'])],
                            ['title' => 'Breads', 'qty' => 2, 'choices' => $pick(['Butter Naan', 'Tandoori Roti'])],
                            ['title' => 'Drink', 'choices' => $pick(['Masala Chaas', 'Sweet Lassi'])]]]);
            saveCombo($pdo, $rid, ['name' => 'Biryani Party Pack', 'price' => 899, 'badge' => 'Serves 3', 'is_active' => 1,
                'description' => 'Two biryanis, a plate of Chicken 65 and two Gulab Jamun.',
                'slots' => [['title' => 'Biryanis', 'qty' => 2, 'choices' => $pick(['Chicken Dum Biryani', 'Veg Dum Biryani'])],
                            ['title' => 'Starter', 'choices' => $pick(['Chicken 65', 'Paneer Tikka'])],
                            ['title' => 'Dessert', 'qty' => 2, 'choices' => $pick(['Gulab Jamun'])]]]);
            saveCombo($pdo, $rid, ['name' => 'Chai & Samosa', 'price' => 99, 'badge' => 'Evening snack', 'is_active' => 1,
                'description' => 'Hot masala chai with two crisp Punjabi samosas.',
                'slots' => [['title' => 'Chai', 'choices' => $pick(['Masala Chai', 'Filter Coffee'])],
                            ['title' => 'Samosa', 'choices' => $pick(['Punjabi Samosa'])]]]);
        },
        // filter chips on the menu page
        function (PDO $pdo, string $rid) {
            $n = 0;
            foreach ([['Bestsellers', '⭐', 'bestsellers', null], ['Offers', '🏷️', 'any_offer', null], ['Under ₹199', '💸', 'price_under', 199], ['Pure veg', '🥗', 'veg', null]]
                     as [$title, $icon, $rule, $value]) {
                demoInsert($pdo, 'offer_filters', ['restaurant_id' => $rid, 'title' => $title, 'icon' => $icon, 'rule_type' => $rule,
                                                   'rule_value' => $value, 'is_active' => 1, 'sort_order' => $n++]);
            }
        },
        // "goes well with" suggestions in the cart
        function (PDO $pdo, string $rid) use ($id) {
            demoInsert($pdo, 'upsell_settings', ['restaurant_id' => $rid, 'enabled' => 1, 'mode' => 'both', 'title' => 'Goes well with your order']);
            foreach (['Butter Chicken' => ['Butter Naan', 'Garlic Naan', 'Jeera Rice'], 'Paneer Butter Masala' => ['Butter Naan', 'Laccha Paratha'],
                      'Dal Makhani' => ['Jeera Rice', 'Butter Naan'], 'Chicken Dum Biryani' => ['Masala Chaas', 'Gulab Jamun'],
                      'Mutton Biryani' => ['Masala Chaas', 'Phirni'], 'Masala Dosa' => ['Filter Coffee'], 'Chicken Tikka' => ['Fresh Lime Soda']] as $dish => $sugg) {
                foreach ($sugg as $p => $s) {
                    demoInsert($pdo, 'upsell_pairs', ['restaurant_id' => $rid, 'menu_item_id' => $id($dish), 'suggest_item_id' => $id($s), 'position' => $p]);
                }
            }
        },
        // loyalty: points + referrals, autopilot gifts, spin wheel, challenges, Spice Club VIP, phone card
        function (PDO $pdo, string $rid) use ($id, $need) {
            $need('growth_helpers.php', 'getGrowthSettings');
            $need('loyalty_autopilot.php', 'loyaltyAutopilotDefaults');
            $need('wallet_card.php', 'ensureWalletCardSchema');
            ensureWalletCardSchema($pdo);
            getGrowthSettings($pdo, $rid);
            $pdo->prepare("UPDATE growth_settings SET loyalty_enabled = 1, program_type = 'points', referral_enabled = 1, reward_valid_days = 30,
                vip_enabled = 1, vip_name = 'Spice Club', vip_tagline = 'Save on every order, all year', card_enabled = 1, card_color = '#1b5e3d', card_text_color = '#ffffff'
                WHERE restaurant_id = ?")->execute([$rid]);
            $defs = loyaltyAutopilotDefaults();
            $free = $id('Gulab Jamun');
            foreach (['welcome' => ['flat', 50, null, 0, 199, 30], 'birthday' => ['item', 0, $free, 7, 0, 14], 'anniversary' => ['percent', 15, null, 7, 499, 14],
                      'winback' => ['flat', 75, null, 30, 299, 14], 'tier_up' => ['flat', 100, null, 0, 399, 30], 'milestone' => ['percent', 20, null, 10, 0, 30],
                      'referral' => ['flat', 50, null, 0, 199, 30]] as $type => [$kind, $value, $dish, $param, $min, $days]) {
                demoInsert($pdo, 'loyalty_automations', ['restaurant_id' => $rid, 'type' => $type, 'enabled' => 1, 'reward_kind' => $kind, 'reward_value' => $value,
                    'reward_item_id' => $dish, 'param' => $param, 'min_order' => $min, 'valid_days' => $days, 'message' => $defs[$type][6] ?? null]);
            }
            demoInsert($pdo, 'scratch_settings', ['restaurant_id' => $rid, 'enabled' => 1, 'game_style' => 'wheel', 'valid_days' => 15, 'monthly_budget' => 5000]);
            foreach ([['flat', 50, 30, null], ['percent', 10, 15, null], ['flat', 100, 5, 599], ['none', 0, 50, null]] as $p => [$kind, $value, $weight, $min]) {
                demoInsert($pdo, 'scratch_prizes', ['restaurant_id' => $rid, 'type' => $kind, 'value' => $value, 'weight' => $weight, 'position' => $p, 'min_order' => $min]);
            }
            foreach ([['visits', 'Visit 3 times this month', 3, null, 'flat', 100, null, 299, 30], ['spend', null, 2000, null, 'percent', 15, null, 0, 30],
                      ['dish', null, 1, $id('Butter Chicken'), 'flat', 50, null, 199, 30], ['streak', null, 3, null, 'item', 0, $free, 0, 14]]
                     as [$kind, $title, $target, $item, $rk, $rv, $ri, $min, $days]) {
                demoInsert($pdo, 'loyalty_challenges', ['restaurant_id' => $rid, 'kind' => $kind, 'title' => $title, 'target' => $target, 'item_id' => $item,
                    'reward_kind' => $rk, 'reward_value' => $rv, 'reward_item_id' => $ri, 'min_order' => $min, 'valid_days' => $days]);
            }
            foreach ([['Monthly', 199, 30, 10, 100, 1.5, null, 0, null, 0], ['Quarterly', 499, 90, 10, 150, 2, 'flat', 100, null, 1],
                      ['Yearly', 1499, 365, 15, 200, 2, 'item', 0, $free, 0]] as $p => [$name, $price, $dur, $pct, $max, $boost, $gk, $gv, $gi, $hl]) {
                demoInsert($pdo, 'vip_plans', ['restaurant_id' => $rid, 'name' => $name, 'price' => $price, 'duration_days' => $dur, 'discount_pct' => $pct,
                    'discount_max' => $max, 'free_delivery' => 1, 'free_delivery_min' => 0, 'earn_boost' => $boost, 'gift_kind' => $gk, 'gift_value' => $gv,
                    'gift_item_id' => $gi, 'highlight' => $hl, 'position' => $p]);
            }
        },
    ];
}

/**
 * Builds the demo restaurant from scratch. $assetDir: main/assets/demo_restaurant. $mainUrl: the
 * absolute URL of main/ (for the website header photo). Returns a short summary.
 */
function resetDemoRestaurant(PDO $pdo, string $assetDir, string $mainUrl): array {
    $rid = DEMO_RESTAURANT_ID;
    demoAssertSafe($pdo);
    $read = function (string $file) use ($assetDir): string {
        $data = @file_get_contents(rtrim($assetDir, '/\\') . '/' . $file);
        if ($data === false || $data === '') throw new RuntimeException("Missing demo photo: $file");
        return $data;
    };
    $mime = fn(string $file) => str_ends_with($file, '.webp') ? 'image/webp' : (str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg');

    // Tables and columns some features add on first use — create them now, outside the transaction
    // (DDL commits implicitly in MySQL).
    foreach (['bestseller_helpers.php' => 'ensureBestsellersTable', 'menu_hours.php' => 'ensureMenuHoursColumns',
              'website_theme_helpers.php' => 'ensureWebsiteThemeSchema', 'business_id_helpers.php' => 'ensureBusinessIdColumns',
              'rider_engine.php' => 'riderEnsure', 'inventory_engine.php' => 'invEnsure', 'coupon_helpers.php' => 'ensureCouponMilestoneColumn',
              'combo_helpers.php' => 'ensureCombosTables', 'upsell_helpers.php' => 'ensureUpsellTables', 'offer_filter_helpers.php' => 'ensureOfferFiltersTable',
              'scratch_helpers.php' => 'ensureScratchTables', 'loyalty_autopilot.php' => 'ensureLoyaltyAutopilotSchema', 'vip_membership.php' => 'ensureVipSchema',
              'growth_helpers.php' => 'ensureGrowthSchema', 'rooms.php' => 'roomsEnsure'] as $file => $fn) {
        if (is_file(__DIR__ . "/$file")) {
            require_once __DIR__ . "/$file";
            if (function_exists($fn)) { try { $fn($pdo); } catch (Throwable $e) { /* optional feature */ } }
        }
    }

    // Keep the multi-branch link (local Spice Garden chain) across a reset
    $links = [];
    try {
        $st = $pdo->prepare('SELECT branch_admin_id, label FROM branch_restaurant_links WHERE restaurant_id = ?');
        $st->execute([$rid]);
        $links = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // Keep the website look the owner saved (Website Appearance) and the sections Super Admin
    // switched on/off (Restaurants › Access) across a reset
    $savedSite = $savedAccess = null;
    try {
        $st = $pdo->prepare('SELECT * FROM website_settings WHERE restaurant_id = ?');
        $st->execute([$rid]);
        $savedSite = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {}
    try {
        $st = $pdo->prepare('SELECT off_pages FROM restaurant_access WHERE restaurant_id = ?');
        $st->execute([$rid]);
        $savedAccess = $st->fetchColumn();
    } catch (PDOException $e) {}

    mt_srand(20261016); // the same demo every time
    date_default_timezone_set('Asia/Kolkata');
    $now = time();

    // the bar: Beverages print on a Bar Order Ticket, chai and filter coffee stay in the kitchen
    // (config/prep_stations.php; the columns are added here, before the transaction)
    require_once __DIR__ . '/prep_stations.php';
    stationEnsureSchema($pdo);
    require_once __DIR__ . '/staff_roles.php';   // the Bartender (and Cashier, Delivery) staff roles
    staffRolesEnsure($pdo);
    $barMenus = ['Beverages'];
    $kitchenDrinks = ['Masala Chai', 'Filter Coffee'];

    $pdo->beginTransaction();
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        demoRemoveRestaurant($pdo, $rid);
        $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE restaurant_id = ? AND username = ?');
        $st->execute([DEMO_LEGACY_ID, DEMO_LEGACY_USERNAME]);
        if ((int)$st->fetchColumn() > 0) demoRemoveRestaurant($pdo, DEMO_LEGACY_ID);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        // ── Restaurant & settings ──
        $time = fn($o, $c) => ['open' => true, 'opening' => $o, 'closing' => $c];
        $hours = [
            'monday' => $time('08:00 AM', '11:00 PM'), 'tuesday' => $time('08:00 AM', '11:00 PM'), 'wednesday' => $time('08:00 AM', '11:00 PM'),
            'thursday' => $time('08:00 AM', '11:00 PM'), 'friday' => $time('08:00 AM', '11:30 PM'), 'saturday' => $time('08:00 AM', '11:30 PM'),
            'sunday' => $time('08:00 AM', '11:00 PM'),
        ];
        $userId = demoInsert($pdo, 'users', [
            'username' => DEMO_USERNAME, 'email' => 'hello@' . DEMO_EMAIL_DOMAIN, 'phone' => '08041237788',
            'address' => '12, 100 Feet Road, HAL 2nd Stage, Indiranagar, Bengaluru, Karnataka 560038',
            'password' => password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT), 'restaurant_id' => $rid,
            'restaurant_name' => DEMO_RESTAURANT_NAME, 'owner_name' => 'Spice Garden Group',
            'currency_symbol' => '₹', 'timezone' => 'Asia/Kolkata', 'country' => 'India', 'language' => 'en',
            'is_active' => 1, 'subscription_status' => 'active', 'trial_end_date' => date('Y-m-d', strtotime('+5 years')),
            'description' => 'North Indian curries, smoky tandoor favourites, dum biryanis and South Indian breakfasts, cooked to order since 2014. '
                . 'Family recipes, fresh masalas ground every morning and a warm table on 100 Feet Road.',
            'description_format' => 'paragraph', 'opening_hours' => json_encode($hours),
            'enable_delivery' => 1, 'enable_takeaway' => 1, 'enable_dinein' => 1, 'enable_reservations' => 1, 'enable_rooms' => 1, 'cod_enabled' => 1,
            'enable_gst' => 1, 'tax_name' => 'GST', 'tax_percent' => 5,
            'show_business_id' => 1, 'business_id_label' => 'GSTIN', 'business_id_no' => '29AAKCS1234F1Z5',
            'minimum_order_value' => 199, 'packaging_charge' => 20, 'delivery_radius_km' => 7,
            'enable_km_delivery' => 1, 'delivery_rate_per_km' => 8,
            'restaurant_lat' => 12.9719, 'restaurant_lng' => 77.6412,
            'google_maps_link' => 'https://www.google.com/maps/search/?api=1&query=12.9719,77.6412',
            'restaurant_logo' => 'db:logo', 'logo_data' => $read('logo.png'), 'logo_mime_type' => 'image/png',
        ]);
        try { $pdo->prepare('DELETE FROM branch_restaurant_links WHERE restaurant_id = ?')->execute([$rid]); } catch (PDOException $e) {}
        foreach ($links as $l) {
            try {
                $pdo->prepare('INSERT INTO branch_restaurant_links (branch_admin_id, restaurant_id, label) VALUES (?, ?, ?)')
                    ->execute([$l['branch_admin_id'], $rid, $l['label']]);
            } catch (PDOException $e) {}
        }

        // ── Menus, subcategories, dishes ──
        $items = [];   // name => [id, price, menu, type]
        $sort = 0;
        $menus = demoMenuData();
        uasort($menus, fn($a, $b) => $a[0] <=> $b[0]); // dishes numbered in menu order
        foreach ($menus as $menuName => [$mSort, $mPhoto, $mHours, $subs, $dishes]) {
            $menuId = demoInsert($pdo, 'menu', [
                'restaurant_id' => $rid, 'menu_name' => $menuName, 'is_active' => 1, 'sort_order' => $mSort,
                'menu_image' => 'db:menu_' . uniqid(), 'menu_image_data' => $read($mPhoto), 'menu_image_mime_type' => $mime($mPhoto),
                'avail_days' => $mHours ? ($mHours[0] ?: null) : null, 'avail_from' => $mHours[1] ?? null, 'avail_to' => $mHours[2] ?? null,
                'prep_station' => in_array($menuName, $barMenus, true) ? 'bar' : 'kitchen',
            ]);
            $subIds = [];
            foreach ($subs as $i => $sub) {
                $subIds[$sub] = demoInsert($pdo, 'subcategories', ['menu_id' => $menuId, 'subcategory_name' => $sub, 'sort_order' => $i + 1]);
            }
            foreach ($dishes as $d) {
                [$name, $type, $price, $desc, $cal, $prep, $sizes, $photo] = $d;
                $sub = $d[8] ?? null;
                $base = $sizes ? min($sizes) : $price;
                $id = demoInsert($pdo, 'menu_items', [
                    'restaurant_id' => $rid, 'menu_id' => $menuId, 'item_name_en' => $name, 'item_description_en' => $desc,
                    'item_category' => $menuName, 'subcategory_id' => $sub ? ($subIds[$sub] ?: null) : null, 'item_type' => $type,
                    'preparation_time' => $prep, 'is_available' => 1, 'base_price' => $base, 'has_variations' => $sizes ? 1 : 0,
                    'item_image' => 'db:' . uniqid('', true), 'image_data' => $read("$photo.jpg"), 'image_mime_type' => 'image/jpeg',
                    'sort_order' => ++$sort, 'calories' => $cal, 'is_hidden' => 0, 'description_format' => 'paragraph',
                    'prep_station' => in_array($name, $kitchenDrinks, true) ? 'kitchen' : null,
                ]);
                $i = 0;
                foreach ($sizes as $vn => $vp) {
                    demoInsert($pdo, 'menu_item_variations', ['menu_item_id' => $id, 'variation_name' => $vn, 'price' => $vp, 'sort_order' => $i++, 'is_available' => 1]);
                }
                $items[$name] = ['id' => $id, 'price' => (float)$base, 'menu' => $menuName, 'type' => $type, 'sizes' => $sizes];
            }
        }
        // one dish sold out, so the stock switch has something to show
        $pdo->prepare('UPDATE menu_items SET is_available = 0 WHERE id = ?')->execute([$items['Amritsari Fish']['id']]);

        // Website "Customer favourites" (with a few offer prices) and today's specials
        $best = ['Butter Chicken' => 315, 'Paneer Tikka' => 224, 'Chicken Dum Biryani' => 289, 'Dal Makhani' => 206,
                 'Masala Dosa' => null, 'Mutton Rogan Josh' => null, 'Gulab Jamun' => null, 'Chicken Tikka' => null];
        $r = 0;
        foreach ($best as $name => $offer) {
            demoInsert($pdo, 'bestsellers', ['restaurant_id' => $rid, 'menu_item_id' => $items[$name]['id'], 'sort_order' => $r++, 'offer_price' => $offer]);
        }
        $specials = [['Kerala Fish Curry', 'Fresh seer fish today'], ['Gajar Halwa', 'Made this morning'], ['Chicken Tikka Masala', null]];
        for ($wd = 1; $wd <= 7; $wd++) {
            foreach ($specials as $p => [$name, $note]) {
                demoInsert($pdo, 'todays_specials', ['restaurant_id' => $rid, 'weekday' => $wd, 'position' => $p, 'menu_item_id' => $items[$name]['id'], 'note' => $note]);
            }
        }

        // Coupons
        // milestone coupons apply themselves in the cart as the order grows
        foreach ([
            ['TREAT40', 'flat', 40, 349, '₹40 off above ₹349 — applied automatically', 1],
            ['SAVE75', 'flat', 75, 599, '₹75 off above ₹599 — applied automatically', 1],
            ['FEAST150', 'flat', 150, 999, '₹150 off above ₹999 — applied automatically', 1],
            ['WELCOME50', 'flat', 50, 299, 'Flat ₹50 off your first order', 0],
            ['SPICE20', 'percent', 20, 599, '20% off on orders above ₹599', 0],
            ['WEEKEND15', 'percent', 15, 399, '15% off every weekend order', 0],
        ] as [$code, $ctype, $value, $min, $cdesc, $milestone]) {
            demoInsert($pdo, 'coupons', [
                'restaurant_id' => $rid, 'coupon_code' => $code, 'discount_type' => $ctype, 'discount_value' => $value,
                'minimum_order_amount' => $min, 'max_uses' => 0, 'current_uses' => mt_rand(3, 40),
                'valid_from' => date('Y-m-d', strtotime('-60 days')), 'valid_until' => date('Y-m-d', strtotime('+1 year')),
                'is_active' => 1, 'description' => $cdesc, 'is_milestone' => $milestone,
            ]);
        }

        // ── Floor: areas and tables ──
        $tables = [];
        $tn = 0;
        foreach ([['Ground Floor', 'T', 8], ['Rooftop', 'R', 5], ['Private Dining', 'P', 2]] as $ai => [$area, $prefix, $count]) {
            $areaId = demoInsert($pdo, 'areas', ['restaurant_id' => $rid, 'area_name' => $area, 'sort_order' => $ai + 1]);
            for ($i = 1; $i <= $count; $i++) {
                $cap = $prefix === 'P' ? 10 : ($i % 4 === 0 ? 6 : ($i % 3 === 0 ? 2 : 4));
                $tables[] = demoInsert($pdo, 'tables', ['restaurant_id' => $rid, 'area_id' => $areaId, 'table_number' => $prefix . $i,
                                                        'capacity' => $cap, 'is_available' => 1, 'sort_order' => ++$tn]);
            }
        }

        // ── Staff (log in with email + password DEMO_STAFF_PASSWORD) ──
        $staffHash = password_hash(DEMO_STAFF_PASSWORD, PASSWORD_DEFAULT);
        foreach (DEMO_STAFF as $key => [$sname, , $role]) {
            demoInsert($pdo, 'staff', ['restaurant_id' => $rid, 'member_name' => $sname, 'email' => demoStaffEmail($key),
                                       'phone' => demoPhone($sname),
                                       'password' => $staffHash, 'role' => $role, 'is_active' => 1]);
        }

        // the demo delivers with its own riders (Settings › Delivery)
        if (function_exists('riderSaveSettings')) {
            try { riderSaveSettings($pdo, $rid, ['own_riders' => 1]); } catch (Throwable $e) { /* optional feature */ }
        }

        // ── Customers ──
        $names = DEMO_CUSTOMER_NAMES;
        $customers = [];
        foreach ($names as $ci => $cname) {
            $phone = demoPhone($cname);
            $bday = date('Y-m-d', strtotime(sprintf('%d-%02d-%02d', mt_rand(1975, 2002), mt_rand(1, 12), mt_rand(1, 28))));
            $id = demoInsert($pdo, 'customers', [
                'restaurant_id' => $rid, 'customer_name' => $cname, 'phone' => $phone,
                'email' => demoCustomerEmail($cname),   // mail_engine never sends to these (demoOwnsEmail)
                'total_visits' => 0, 'total_spent' => 0, 'birthday' => $ci % 3 === 0 ? $bday : null, 'whatsapp_optin' => 0,
                'created_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(45, 400) . ' days')),
            ]);
            // a few regulars come much more often
            $customers[] = ['id' => $id, 'name' => $cname, 'phone' => $phone, 'weight' => $ci < 8 ? 5 : ($ci < 20 ? 2 : 1)];
        }
        $pool = [];
        foreach ($customers as $c) for ($w = 0; $w < $c['weight']; $w++) $pool[] = $c;

        // ── Orders: 42 days of history and today's live service ──
        $byMenu = [];
        foreach ($items as $name => $it) $byMenu[$it['menu']][] = $name;
        $pick = function (array $menus) use ($byMenu) {
            $m = $menus[mt_rand(0, count($menus) - 1)];
            return $byMenu[$m][mt_rand(0, count($byMenu[$m]) - 1)];
        };
        $basket = function (int $hour) use ($pick, $items) {
            // what people order depends on the time of day
            if ($hour < 12) $plan = [['Breakfast'], ['Breakfast'], ['Beverages']];
            elseif ($hour < 16) $plan = mt_rand(1, 4) === 1 ? [['Thalis'], ['Beverages']]
                : [['Main Course', 'Biryani & Rice'], ['Breads'], ['Starters'], ['Beverages', 'Desserts']];
            elseif ($hour < 19) $plan = [['Starters', 'Soups'], ['Beverages'], ['Desserts']];
            else $plan = [['Starters'], ['Main Course'], ['Main Course', 'Biryani & Rice'], ['Breads'], ['Breads'], ['Desserts', 'Beverages']];
            $n = max(1, min(count($plan), mt_rand(1, count($plan))));
            $lines = [];
            for ($i = 0; $i < $n; $i++) {
                $name = $pick($plan[$i]);
                if (isset($lines[$name])) { $lines[$name]['q']++; continue; }
                $it = $items[$name];
                $size = null;
                $price = $it['price'];
                if ($it['sizes'] && mt_rand(0, 2) === 0) { $size = array_key_last($it['sizes']); $price = (float)$it['sizes'][$size]; }
                $q = $it['menu'] === 'Breads' ? mt_rand(2, 4) : (mt_rand(1, 5) === 1 ? 2 : 1);
                $lines[$name] = ['id' => $it['id'], 'name' => $size ? "$name ($size)" : $name, 'price' => $price, 'q' => $q];
            }
            return array_values($lines);
        };
        $slots = [8, 9, 9, 10, 10, 11, 12, 13, 13, 13, 14, 14, 15, 16, 17, 18, 19, 20, 20, 20, 21, 21, 21, 22, 22];
        $areas = [
            'Indiranagar 2nd Stage', 'HAL 3rd Stage', 'Domlur Layout', 'Jeevan Bima Nagar', 'CMH Road', 'Defence Colony',
            'Old Airport Road', 'Ulsoor', 'Kodihalli', 'New Thippasandra',
        ];
        $seq = 0; $nOrders = 0; $todaySales = 0.0; $busy = [];
        $makeOrder = function (int $ts, string $type, string $status, string $source) use (&$seq, &$nOrders, &$todaySales, &$busy, $pdo, $rid, $pool, $tables, $areas, $basket, $now, $items, $barMenus, $kitchenDrinks) {
            $hour = (int)date('G', $ts);
            $cust = mt_rand(1, 10) <= 7 || $type === 'Delivery' ? $pool[mt_rand(0, count($pool) - 1)] : null;
            $tableId = $type === 'Dine-in' ? $tables[mt_rand(0, count($tables) - 1)] : null;
            $lines = $basket(max(8, $hour));
            $subtotal = 0.0;
            foreach ($lines as $l) $subtotal += $l['price'] * $l['q'];
            $coupon = null; $discount = 0.0;
            if ($source === 'website' && $subtotal >= 599 && mt_rand(1, 5) === 1) { $coupon = 'SPICE20'; $discount = round($subtotal * 0.2, 2); }
            elseif ($source === 'website' && $subtotal >= 299 && mt_rand(1, 6) === 1) { $coupon = 'WELCOME50'; $discount = 50.0; }
            $deliveryCharge = $type === 'Delivery' ? (float)(mt_rand(2, 7) * 8) : 0.0;
            $packaging = $type === 'Dine-in' ? 0.0 : 20.0;
            $tax = round(($subtotal - $discount) * 0.05, 2);
            $total = round($subtotal - $discount + $tax + $deliveryCharge + $packaging, 2);
            $paid = in_array($status, ['Completed', 'Served'], true) || ($source === 'website' && $status !== 'Pending' && mt_rand(0, 1));
            $method = $source === 'website' ? (mt_rand(1, 3) === 1 ? 'Cash' : 'Online') : ['UPI', 'UPI', 'Cash', 'Card'][mt_rand(0, 3)];
            $seq++;
            $created = date('Y-m-d H:i:s', $ts);
            $address = $type === 'Delivery' ? mt_rand(10, 999) . ', ' . $areas[mt_rand(0, count($areas) - 1)] . ', Bengaluru' : null;
            $orderId = demoInsert($pdo, 'orders', [
                'restaurant_id' => $rid, 'table_id' => $tableId, 'order_number' => 'ORD-' . date('Ymd', $ts) . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT),
                'customer_name' => $cust['name'] ?? 'Walk-in', 'customer_phone' => $cust['phone'] ?? null,
                'order_type' => $type, 'payment_method' => $method, 'payment_status' => $paid ? 'Paid' : 'Pending',
                'order_status' => $status, 'source' => $source, 'subtotal' => $subtotal, 'tax' => $tax, 'total' => $total,
                'delivery_charge' => $deliveryCharge, 'delivery_address' => $address, 'customer_address' => $address,
                'coupon_code' => $coupon, 'discount_amount' => $discount,
                'prep_minutes' => $status === 'Pending' ? null : 20,
                'estimated_ready_at' => $status === 'Pending' ? null : date('Y-m-d H:i:s', $ts + 20 * 60),
                'notes' => mt_rand(1, 8) === 1 ? ['Less spicy please', 'No onion, no garlic', 'Extra chutney', 'Please send cutlery'][mt_rand(0, 3)] : null,
                'created_at' => $created, 'updated_at' => $created,
            ]);
            foreach ($lines as $l) {
                demoInsert($pdo, 'order_items', ['order_id' => $orderId, 'menu_item_id' => $l['id'], 'item_name' => $l['name'], 'quantity' => $l['q'],
                                                 'unit_price' => $l['price'], 'total_price' => $l['price'] * $l['q'], 'created_at' => $created]);
            }
            // open orders: a kitchen ticket (and a bar ticket for the drinks), and a dine-in table shows as taken
            if (in_array($status, ['Preparing', 'Ready', 'Served'], true)) {
                $byStation = [];
                foreach ($lines as $dish => $l) {
                    $bar = in_array($items[$dish]['menu'] ?? '', $barMenus, true) && !in_array($dish, $kitchenDrinks, true);
                    $byStation[$bar ? 'bar' : 'kitchen'][] = $l;
                }
                foreach ($byStation as $station => $stLines) {
                    $stSub = 0.0;
                    foreach ($stLines as $l) $stSub += $l['price'] * $l['q'];
                    $share = $subtotal > 0 ? $stSub / $subtotal : 1;
                    $kotId = demoInsert($pdo, 'kot', [
                        'restaurant_id' => $rid, 'kot_number' => ($station === 'bar' ? 'BOT-' : 'KOT-') . date('Ymd', $ts) . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT),
                        'table_id' => $tableId, 'order_type' => $type, 'customer_name' => $cust['name'] ?? 'Walk-in',
                        'kot_status' => $status === 'Served' ? 'Completed' : $status, 'subtotal' => round($stSub, 2), 'tax' => round($tax * $share, 2), 'total' => round($stSub + $tax * $share, 2),
                        'created_at' => $created, 'order_id' => $orderId, 'station' => $station,
                    ]);
                    foreach ($stLines as $l) {
                        demoInsert($pdo, 'kot_items', ['kot_id' => $kotId, 'menu_item_id' => $l['id'], 'item_name' => $l['name'], 'quantity' => $l['q'],
                                                       'unit_price' => $l['price'], 'total_price' => $l['price'] * $l['q']]);
                    }
                }
                if ($tableId) $busy[$tableId] = true;
            }
            if ($status !== 'Cancelled' && $cust) {
                $date = date('Y-m-d', $ts);
                $pdo->prepare('UPDATE customers SET total_visits = total_visits + 1, total_spent = total_spent + ?, last_visit_date = GREATEST(COALESCE(last_visit_date, ?), ?) WHERE id = ?')
                    ->execute([$total, $date, $date, $cust['id']]);
            }
            if (date('Y-m-d', $ts) === date('Y-m-d', $now) && $status !== 'Cancelled') $todaySales += $total;
            $nOrders++;
        };
        $types = ['Dine-in', 'Dine-in', 'Dine-in', 'Dine-in', 'Takeaway', 'Takeaway', 'Delivery', 'Delivery', 'Delivery'];

        // finished orders: 42 days back up to an hour ago (busier at weekends, slowly growing)
        for ($day = 42; $day >= 0; $day--) {
            $date = date('Y-m-d', strtotime("-$day days", $now));
            $weekend = in_array((int)date('N', strtotime($date)), [5, 6, 7], true);
            $count = mt_rand(16, 24) + ($weekend ? mt_rand(10, 16) : 0) + (int)round((42 - $day) / 7);
            for ($k = 0; $k < $count; $k++) {
                $hour = $slots[mt_rand(0, count($slots) - 1)];
                $ts = strtotime(sprintf('%s %02d:%02d:%02d', $date, $hour, mt_rand(0, 59), mt_rand(0, 59)));
                if ($ts > $now - 3600) continue;
                $type = $types[mt_rand(0, 8)];
                $source = $type === 'Delivery' || ($type === 'Takeaway' && mt_rand(0, 1)) ? 'website' : 'pos';
                $makeOrder($ts, $type, mt_rand(1, 40) === 1 ? 'Cancelled' : 'Completed', $source);
            }
        }
        // live service right now, whatever time the demo was reset: new website orders waiting
        // to be accepted, food cooking, orders ready and tables being served
        foreach ([[3, 'Delivery', 'Pending', 'website'], [9, 'Takeaway', 'Pending', 'website'],
                  [12, 'Dine-in', 'Preparing', 'pos'], [16, 'Delivery', 'Preparing', 'website'], [21, 'Dine-in', 'Preparing', 'pos'],
                  [28, 'Takeaway', 'Ready', 'pos'], [33, 'Dine-in', 'Ready', 'pos'],
                  [41, 'Dine-in', 'Served', 'pos'], [52, 'Dine-in', 'Served', 'pos']] as [$mins, $type, $status, $source]) {
            $makeOrder($now - $mins * 60 - mt_rand(0, 50), $type, $status, $source);
        }

        if ($busy) $pdo->exec('UPDATE tables SET is_available = 0 WHERE id IN (' . implode(',', array_map('intval', array_keys($busy))) . ')');

        // ── Tables calling for a waiter (from the table QR) ──
        foreach ([[2, 'General', 'Need water and extra napkins'], [6, 'General', 'Please bring the bill'], [11, 'Order', 'Want to add 2 butter naan']] as [$ti, $rtype, $note]) {
            $at = date('Y-m-d H:i:s', $now - mt_rand(40, 240));
            demoInsert($pdo, 'waiter_requests', ['restaurant_id' => $rid, 'table_id' => $tables[$ti], 'request_type' => $rtype,
                                                 'status' => 'Pending', 'notes' => $note, 'created_at' => $at, 'updated_at' => $at]);
        }

        // ── Table bookings: a few past, more coming up ──
        $slotsBook = ['12:30 PM', '01:00 PM', '01:30 PM', '07:30 PM', '08:00 PM', '08:30 PM', '09:00 PM'];
        $requests = [null, null, 'Birthday — please arrange a small cake', 'Window seat if possible', 'High chair for a toddler', 'Anniversary dinner'];
        for ($i = 0; $i < 18; $i++) {
            $offset = $i < 5 ? -mt_rand(1, 10) : mt_rand(0, 7);
            $slot = $slotsBook[mt_rand(0, count($slotsBook) - 1)];
            $c = $customers[mt_rand(0, count($customers) - 1)];
            $status = $offset < 0 ? (mt_rand(1, 6) === 1 ? 'No Show' : 'Completed') : ($i % 3 === 0 ? 'Pending' : 'Confirmed');
            demoInsert($pdo, 'reservations', [
                'restaurant_id' => $rid, 'table_id' => $tables[mt_rand(0, count($tables) - 1)],
                'reservation_date' => date('Y-m-d', strtotime("$offset days", $now)), 'time_slot' => $slot,
                'no_of_guests' => mt_rand(2, 8), 'meal_type' => str_contains($slot, 'PM') && (int)$slot >= 7 && (int)$slot < 12 ? 'Dinner' : 'Lunch',
                'customer_name' => $c['name'], 'phone' => $c['phone'], 'email' => null,
                'special_request' => $requests[mt_rand(0, count($requests) - 1)], 'status' => $status,
                'created_at' => date('Y-m-d H:i:s', strtotime(($offset - 2) . ' days', $now)),
            ]);
        }

        // ── Rooms (config/rooms.php): two room types with photos (assets room-*.jpg, free Unsplash photos),
        // and stays around today: one guest in house, requests to confirm, arrivals coming up ──
        $roomDir = __DIR__ . '/../uploads/rooms';
        if (!is_dir($roomDir)) @mkdir($roomDir, 0755, true);
        foreach (glob($roomDir . '/room_' . $rid . '_*') ?: [] as $old) @unlink($old);   // the last reset's copies
        $roomTypeIds = [];
        foreach ([
            ['Deluxe Double', 3499, 2, 4, '101, 102, 103, 104', 'A calm room for two with a queen bed, warm lighting and a private bathroom with 24-hour hot water. Tea and coffee in the room, and our restaurant one floor down.',
             'WiFi, AC, TV, Hot water, Attached bathroom, Room service', 'deluxe'],
            ['Family Suite', 5999, 4, 2, '201, 202', 'Spacious suite for families: a king bed plus a sofa bed, a sitting corner by the window and a large bathroom. Breakfast for up to 4 guests is included.',
             'WiFi, AC, TV, Hot water, Attached bathroom, Balcony, Breakfast, Parking', 'family'],
        ] as $ri => [$rName, $rPrice, $rGuests, $rCount, $rNumbers, $rDesc, $rAmen, $rKey]) {
            $photos = [];
            for ($p = 1; $p <= 3; $p++) {
                $file = 'room_' . $rid . '_' . bin2hex(random_bytes(6)) . '.jpg';
                if (@file_put_contents($roomDir . '/' . $file, $read("room-$rKey-$p.jpg")) !== false) $photos[] = 'uploads/rooms/' . $file;
            }
            $roomTypeIds[] = [demoInsert($pdo, 'room_types', ['restaurant_id' => $rid, 'name' => $rName, 'description' => $rDesc, 'price_per_night' => $rPrice,
                'max_guests' => $rGuests, 'room_count' => $rCount, 'room_numbers' => $rNumbers, 'amenities' => $rAmen, 'photos' => json_encode($photos),
                'is_active' => 1, 'sort_order' => $ri + 1]), $rPrice];
        }
        // [type, from day, nights, rooms, guests, status, paid, room no., source]
        foreach ([[0, -1, 3, 1, 2, 'Checked in', 1, '101', 'website'], [1, 0, 2, 1, 4, 'Confirmed', 0, '', 'website'], [0, 1, 2, 1, 2, 'Pending', 0, '', 'website'],
                  [0, 3, 1, 2, 4, 'Confirmed', 0, '', 'dashboard'], [1, 5, 3, 1, 3, 'Pending', 0, '', 'website'], [0, -6, 2, 1, 2, 'Checked out', 1, '103', 'website']]
                 as $bi => [$ti, $from, $nights, $rooms, $guests, $bStatus, $paid, $no, $src]) {
            $c = $customers[($bi * 7 + 3) % count($customers)];
            [$typeId, $price] = $roomTypeIds[$ti];
            demoInsert($pdo, 'room_bookings', ['restaurant_id' => $rid, 'room_type_id' => $typeId, 'guest_name' => $c['name'], 'guest_phone' => $c['phone'],
                'check_in' => date('Y-m-d', strtotime("$from days", $now)), 'check_out' => date('Y-m-d', strtotime(($from + $nights) . ' days', $now)),
                'guests' => $guests, 'rooms' => $rooms, 'price_per_night' => $price, 'total' => $price * $nights * $rooms, 'status' => $bStatus, 'paid' => $paid,
                'room_numbers' => $no ?: null, 'notes' => $bi === 2 ? 'Arriving late, around 10 pm' : null, 'source' => $src,
                'created_at' => date('Y-m-d H:i:s', strtotime(($from - 3) . ' days', $now))]);
        }

        // ── Inventory: raw material, suppliers, recipes, purchases ──
        // Purchases and wastage become expenses (as Menu › Inventory does), so with rent, salaries
        // and gas below, Reports show sales minus real costs as the net profit.
        $supIds = [];
        foreach ([['veg', 'Fresh Farms Vegetables', 'Ramesh'], ['dairy', 'Nandini Dairy Distributors', 'Manjunath'],
                  ['meat', 'Royal Meat & Poultry', 'Salim'], ['dry', 'Metro Wholesale', 'Prakash']] as [$key, $sname, $person]) {
            $supIds[$key] = demoInsert($pdo, 'inventory_suppliers', ['restaurant_id' => $rid, 'name' => $sname, 'contact_person' => $person, 'phone' => demoPhone($sname)]);
        }
        // name => [unit, category, cost per unit, in stock, low-stock level, used per day, supplier, bought every N days]
        $stock = [
            'Onion' => ['kg', 'Vegetables', 32, 45, 15, 8, 'veg', 1], 'Tomato' => ['kg', 'Vegetables', 28, 30, 12, 7, 'veg', 1],
            'Potato' => ['kg', 'Vegetables', 26, 25, 10, 4, 'veg', 1], 'Ginger garlic paste' => ['kg', 'Vegetables', 120, 6, 2, 1, 'veg', 1],
            'Green chillies' => ['kg', 'Vegetables', 60, 3, 1, 0.6, 'veg', 1], 'Coriander' => ['bunch', 'Vegetables', 10, 25, 8, 8, 'veg', 1],
            'Capsicum' => ['kg', 'Vegetables', 70, 8, 3, 1.5, 'veg', 1], 'Spinach' => ['bunch', 'Vegetables', 15, 4, 10, 3, 'veg', 1],
            'Milk' => ['L', 'Dairy', 56, 40, 15, 6, 'dairy', 1], 'Paneer' => ['kg', 'Dairy', 380, 9, 5, 2, 'dairy', 1],
            'Butter' => ['kg', 'Dairy', 520, 6, 2, 0.7, 'dairy', 1], 'Fresh cream' => ['L', 'Dairy', 240, 5, 2, 0.8, 'dairy', 1],
            'Curd' => ['kg', 'Dairy', 70, 15, 5, 3, 'dairy', 1], 'Ghee' => ['L', 'Dairy', 620, 7, 2, 0.4, 'dairy', 7],
            'Chicken boneless' => ['kg', 'Meat & Fish', 280, 14, 5, 2, 'meat', 2], 'Chicken curry cut' => ['kg', 'Meat & Fish', 220, 18, 6, 2, 'meat', 2],
            'Mutton' => ['kg', 'Meat & Fish', 780, 6, 3, 0.6, 'meat', 2], 'Seer fish' => ['kg', 'Meat & Fish', 900, 2.5, 3, 0.4, 'meat', 2],
            'Eggs' => ['pcs', 'Meat & Fish', 7, 150, 48, 12, 'meat', 2],
            'Basmati rice' => ['kg', 'Grains & Dals', 120, 60, 20, 4, 'dry', 7], 'Atta' => ['kg', 'Grains & Dals', 42, 50, 15, 5, 'dry', 7],
            'Maida' => ['kg', 'Grains & Dals', 40, 25, 8, 1.5, 'dry', 7], 'Urad dal' => ['kg', 'Grains & Dals', 140, 18, 5, 1, 'dry', 7],
            'Rajma' => ['kg', 'Grains & Dals', 160, 10, 3, 0.5, 'dry', 7], 'Chickpeas' => ['kg', 'Grains & Dals', 110, 12, 4, 0.6, 'dry', 7],
            'Cooking oil' => ['L', 'Oil & Spices', 155, 40, 15, 2.5, 'dry', 7], 'Garam masala' => ['kg', 'Oil & Spices', 650, 4, 1, 0.15, 'dry', 7],
            'Sugar' => ['kg', 'Oil & Spices', 46, 20, 6, 1.5, 'dry', 7], 'Cashew' => ['kg', 'Oil & Spices', 950, 3, 1, 0.15, 'dry', 7],
            'Tea leaves' => ['kg', 'Beverages', 480, 3, 1, 0.15, 'dry', 7], 'Coffee powder' => ['kg', 'Beverages', 900, 2, 1, 0.05, 'dry', 7],
            'Takeaway containers' => ['pcs', 'Packaging', 6, 400, 150, 40, 'dry', 7], 'Carry bags' => ['pcs', 'Packaging', 2, 600, 200, 25, 'dry', 7],
        ];
        $inv = [];
        foreach ($stock as $iname => [$unit, $cat, $cost, $qty, $low, $perDay, $sup]) {
            $inv[$iname] = demoInsert($pdo, 'inventory_items', [
                'restaurant_id' => $rid, 'item_name' => $iname, 'unit' => $unit, 'category' => $cat, 'quantity_in_stock' => $qty,
                'low_stock_threshold' => $low, 'par_level' => round($perDay * 7, 2), 'cost_per_unit' => $cost, 'supplier_id' => $supIds[$sup],
            ]);
        }
        // what one plate uses (dishes not listed simply have no recipe yet)
        $recipes = [
            'Paneer Tikka' => [['Paneer', 200, 'g'], ['Curd', 50, 'g'], ['Capsicum', 50, 'g'], ['Onion', 50, 'g']],
            'Chilli Paneer' => [['Paneer', 180, 'g'], ['Capsicum', 60, 'g'], ['Onion', 60, 'g'], ['Cooking oil', 30, 'ml']],
            'Paneer Butter Masala' => [['Paneer', 180, 'g'], ['Tomato', 150, 'g'], ['Butter', 30, 'g'], ['Fresh cream', 30, 'ml'], ['Cashew', 15, 'g']],
            'Kadai Paneer' => [['Paneer', 180, 'g'], ['Capsicum', 60, 'g'], ['Tomato', 120, 'g'], ['Onion', 80, 'g']],
            'Palak Paneer' => [['Paneer', 150, 'g'], ['Spinach', 1, 'bunch'], ['Fresh cream', 20, 'ml']],
            'Dal Makhani' => [['Urad dal', 80, 'g'], ['Rajma', 20, 'g'], ['Butter', 30, 'g'], ['Fresh cream', 30, 'ml']],
            'Pindi Chole' => [['Chickpeas', 100, 'g'], ['Onion', 80, 'g'], ['Tomato', 80, 'g']],
            'Rajma Chawal' => [['Rajma', 80, 'g'], ['Basmati rice', 120, 'g'], ['Onion', 60, 'g']],
            'Butter Chicken' => [['Chicken boneless', 220, 'g'], ['Tomato', 150, 'g'], ['Butter', 40, 'g'], ['Fresh cream', 40, 'ml'], ['Cashew', 15, 'g']],
            'Chicken Tikka Masala' => [['Chicken boneless', 220, 'g'], ['Tomato', 120, 'g'], ['Onion', 100, 'g'], ['Fresh cream', 20, 'ml']],
            'Kadai Chicken' => [['Chicken curry cut', 250, 'g'], ['Capsicum', 60, 'g'], ['Tomato', 120, 'g'], ['Onion', 100, 'g']],
            'Chicken Tikka' => [['Chicken boneless', 250, 'g'], ['Curd', 60, 'g'], ['Ginger garlic paste', 20, 'g']],
            'Tandoori Chicken' => [['Chicken curry cut', 450, 'g'], ['Curd', 80, 'g'], ['Ginger garlic paste', 25, 'g']],
            'Chicken 65' => [['Chicken boneless', 220, 'g'], ['Cooking oil', 60, 'ml'], ['Green chillies', 15, 'g']],
            'Mutton Rogan Josh' => [['Mutton', 250, 'g'], ['Curd', 60, 'g'], ['Onion', 100, 'g'], ['Ghee', 20, 'ml']],
            'Mutton Seekh Kebab' => [['Mutton', 200, 'g'], ['Onion', 40, 'g'], ['Garam masala', 5, 'g']],
            'Kerala Fish Curry' => [['Seer fish', 220, 'g'], ['Tomato', 80, 'g'], ['Onion', 80, 'g']],
            'Egg Curry' => [['Eggs', 3, 'pcs'], ['Onion', 80, 'g'], ['Tomato', 80, 'g']],
            'Egg Bhurji Pav' => [['Eggs', 3, 'pcs'], ['Onion', 50, 'g'], ['Butter', 15, 'g']],
            'Chicken Dum Biryani' => [['Basmati rice', 180, 'g'], ['Chicken curry cut', 220, 'g'], ['Onion', 100, 'g'], ['Ghee', 20, 'ml'], ['Curd', 50, 'g']],
            'Mutton Biryani' => [['Basmati rice', 180, 'g'], ['Mutton', 200, 'g'], ['Onion', 100, 'g'], ['Ghee', 20, 'ml']],
            'Veg Dum Biryani' => [['Basmati rice', 180, 'g'], ['Potato', 60, 'g'], ['Onion', 80, 'g'], ['Ghee', 15, 'ml']],
            'Jeera Rice' => [['Basmati rice', 150, 'g'], ['Ghee', 10, 'ml']],
            'Curd Rice' => [['Basmati rice', 120, 'g'], ['Curd', 150, 'g']],
            'Masala Dosa' => [['Urad dal', 40, 'g'], ['Basmati rice', 80, 'g'], ['Potato', 120, 'g']],
            'Butter Naan' => [['Maida', 90, 'g'], ['Butter', 10, 'g']],
            'Garlic Naan' => [['Maida', 90, 'g'], ['Butter', 10, 'g'], ['Ginger garlic paste', 5, 'g']],
            'Tandoori Roti' => [['Atta', 80, 'g']],
            'Laccha Paratha' => [['Atta', 100, 'g'], ['Ghee', 10, 'ml']],
            'Veg Thali' => [['Paneer', 80, 'g'], ['Urad dal', 40, 'g'], ['Basmati rice', 120, 'g'], ['Atta', 80, 'g'], ['Curd', 80, 'g']],
            'Non-Veg Thali' => [['Chicken boneless', 150, 'g'], ['Urad dal', 40, 'g'], ['Basmati rice', 120, 'g'], ['Atta', 80, 'g'], ['Curd', 80, 'g']],
            'Masala Chai' => [['Milk', 120, 'ml'], ['Tea leaves', 5, 'g'], ['Sugar', 10, 'g']],
            'Filter Coffee' => [['Milk', 120, 'ml'], ['Coffee powder', 10, 'g'], ['Sugar', 10, 'g']],
            'Sweet Lassi' => [['Curd', 200, 'g'], ['Sugar', 25, 'g']],
            'Gulab Jamun' => [['Milk', 100, 'ml'], ['Sugar', 40, 'g'], ['Ghee', 15, 'ml']],
        ];
        foreach ($recipes as $dish => $lines) {
            foreach ($lines as [$iname, $rq, $ru]) {
                demoInsert($pdo, 'inventory_recipes', ['restaurant_id' => $rid, 'menu_item_id' => $items[$dish]['id'],
                                                       'inventory_item_id' => $inv[$iname], 'quantity' => $rq, 'unit' => $ru]);
            }
        }
        // sales use up stock from the reset onwards (dishes never go sold out on their own)
        try {
            $pdo->prepare('INSERT INTO inventory_settings (restaurant_id, auto_deduct, track_from, auto_soldout) VALUES (?, 1, ?, 0)')
                ->execute([$rid, date('Y-m-d H:i:s', $now)]);
        } catch (PDOException $e) {}

        $supName = ['veg' => 'Fresh Farms Vegetables', 'dairy' => 'Nandini Dairy Distributors', 'meat' => 'Royal Meat & Poultry', 'dry' => 'Metro Wholesale'];
        $buy = function (string $iname, float $qty, string $date, string $category = 'Inventory Purchase') use ($pdo, $rid, $stock, $inv, $supName) {
            [$unit, , $cost, , , , $sup] = $stock[$iname];
            $wasted = $category === 'Inventory Wastage';
            $total = round($qty * $cost, 2);
            $at = $date . ($wasted ? ' 22:30:00' : ' 07:30:00');
            $txId = demoInsert($pdo, 'inventory_transactions', [
                'restaurant_id' => $rid, 'inventory_item_id' => $inv[$iname], 'type' => $wasted ? 'wastage' : 'restock',
                'quantity' => $wasted ? -$qty : $qty, 'cost_per_unit' => $cost, 'total_cost' => $total,
                'notes' => $wasted ? 'Spoiled' : 'From ' . $supName[$sup], 'created_by' => 'Neha Kapoor', 'created_at' => $at,
            ]);
            demoInsert($pdo, 'expenses', [
                'restaurant_id' => $rid, 'category' => $category, 'amount' => $total,
                'description' => ($wasted ? 'Wastage: ' : 'Restock: ') . "$iname x" . round($qty, 2) . " $unit", 'expense_date' => $date,
                'source' => 'inventory', 'reference_id' => $txId, 'created_at' => $at,
            ]);
        };
        // fresh stock every morning, meat every other day, dry goods weekly — 42 days back up to today
        for ($day = 42; $day >= 0; $day--) {
            $date = date('Y-m-d', strtotime("-$day days", $now));
            foreach ($stock as $iname => [, , , , , $perDay, , $every]) {
                if ($every > 1 && $day % $every !== 1) continue;   // bulk buys never land on the reset day
                $qty = $perDay * $every * mt_rand(90, 112) / 100;
                $buy($iname, in_array($stock[$iname][0], ['pcs', 'bunch'], true) ? round($qty) : round($qty, 1), $date);
            }
        }
        foreach ([[33, 'Tomato', 3], [26, 'Fresh cream', 1], [19, 'Chicken curry cut', 1.5], [12, 'Spinach', 6], [6, 'Paneer', 0.8], [2, 'Milk', 4]] as [$ago, $iname, $wq]) {
            $buy($iname, $wq, date('Y-m-d', strtotime("-$ago days", $now)), 'Inventory Wastage');
        }
        // purchase orders: two received, one sent, one still a draft
        $poN = 0;
        foreach ([['meat', 'received', -9, [['Chicken boneless', 10], ['Mutton', 4], ['Eggs', 60]]],
                  ['dry', 'received', -3, [['Basmati rice', 25], ['Cooking oil', 15], ['Cashew', 1]]],
                  ['veg', 'sent', 1, [['Onion', 40], ['Tomato', 30], ['Capsicum', 8]]],
                  ['dairy', 'draft', 2, [['Paneer', 15], ['Fresh cream', 6], ['Butter', 5]]]] as [$sup, $pst, $off, $lines]) {
            $total = 0.0;
            foreach ($lines as [$iname, $lq]) $total += $lq * $stock[$iname][2];
            $made = date('Y-m-d H:i:s', strtotime(($off - 2) . ' days', $now));
            $poId = demoInsert($pdo, 'inventory_purchase_orders', [
                'restaurant_id' => $rid, 'po_number' => 'PO-' . str_pad((string)++$poN, 4, '0', STR_PAD_LEFT), 'supplier_id' => $supIds[$sup],
                'status' => $pst, 'expected_date' => date('Y-m-d', strtotime("$off days", $now)), 'total' => $total, 'created_at' => $made,
                'sent_at' => $pst === 'draft' ? null : $made, 'received_at' => $pst === 'received' ? date('Y-m-d H:i:s', strtotime("$off days", $now)) : null,
            ]);
            foreach ($lines as [$iname, $lq]) {
                demoInsert($pdo, 'inventory_po_lines', ['po_id' => $poId, 'inventory_item_id' => $inv[$iname], 'quantity' => $lq,
                                                        'cost_per_unit' => $stock[$iname][2], 'received_qty' => $pst === 'received' ? $lq : 0]);
            }
        }

        // ── Running costs: rent, salaries, bills, gas ──
        $spend = function (string $cat, float $amount, string $desc, int $ts) use ($pdo, $rid) {
            demoInsert($pdo, 'expenses', ['restaurant_id' => $rid, 'category' => $cat, 'amount' => $amount, 'description' => $desc,
                                          'expense_date' => date('Y-m-d', $ts), 'source' => 'manual', 'created_at' => date('Y-m-d 11:00:00', $ts)]);
        };
        for ($day = 42; $day >= 0; $day--) {
            $ts = strtotime("-$day days", $now);
            $dom = (int)date('j', $ts);
            $month = date('F', $ts);
            if ($day === 0) continue;   // the reset day only has the morning's fresh stock
            if ($dom === 1) {
                $spend('Rent', 85000, "Shop rent – $month", $ts);
                $spend('Salaries', 145000, "Staff salaries – $month (8 staff)", $ts);
            }
            if ($dom === 5) {
                $spend('Electricity', mt_rand(16500, 19500), "BESCOM electricity bill – $month", $ts);
                $spend('Water', 2500, "Water tanker and BWSSB bill – $month", $ts);
                $spend('Software & Internet', 2999, "Internet and POS – $month", $ts);
            }
            if ($dom === 15) $spend('Marketing', 6000, "Instagram and Google ads – $month", $ts);
            if ($day % 7 === 2) $spend('Gas', 11100, 'LPG commercial cylinders x6', $ts);
        }
        $spend('Repairs & Maintenance', 3200, 'Tandoor repair and deep-fryer service', strtotime('-17 days', $now));
        $spend('Repairs & Maintenance', 1450, 'Pest control', strtotime('-8 days', $now));

        // ── Website: header photo and layout (the owner's saved look wins) ──
        if ($savedSite) {
            demoInsert($pdo, 'website_settings', $savedSite);
        } else try {
            $pdo->prepare('INSERT INTO website_settings (restaurant_id, background_theme) VALUES (?, ?) ON DUPLICATE KEY UPDATE background_theme = VALUES(background_theme)')
                ->execute([$rid, rtrim($mainUrl, '/') . '/assets/demo_restaurant/hero.jpg']);
            $pdo->prepare('UPDATE website_settings SET menu_nav_position = ? WHERE restaurant_id = ?')->execute(['top', $rid]);
            // the ready-made Emerald theme (matches the green logo) with existing header and card styles
            $pdo->prepare("UPDATE website_settings SET theme_preset = 'emerald', primary_red = '#1B5E3D', dark_red = '#0F3D28', primary_yellow = '#F4E1C1',
                           font_family = 'Poppins', card_style = 'rounded', layout_style = 'grid', header_style = 'card',
                           home_card_style = 'delivery', menu_card_style = 'list', nav_bar_style = 'floating',
                           category_heading_bg = '#E8F1EC' WHERE restaurant_id = ?")->execute([$rid]);
        } catch (PDOException $e) {}

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $e2) {}
        throw $e;
    }

    // Offers and loyalty: each part is optional, so one missing feature never stops a reset
    foreach (demoOfferSetup($items) as $part) {
        try { $part($pdo, $rid); } catch (Throwable $e) { error_log('demo offers: ' . $e->getMessage()); }
    }

    // Nutrition on every dish + Meal plans (for showing healthy / fitness restaurants)
    try { demoHealthSetup($pdo, $rid, $assetDir, $items); } catch (Throwable $e) { error_log('demo health: ' . $e->getMessage()); }

    // Super Admin › Restaurants › Access: loyalty (studio, referrals, spin wheel) starts switched off;
    // whatever Super Admin set since is kept
    try {
        require_once __DIR__ . '/access_control.php';
        $off = is_string($savedAccess) ? (json_decode($savedAccess, true) ?: []) : ['growthSettingsPage', 'referralsPage', 'scratchPage'];
        accessSaveOffPages($pdo, $rid, $off);
    } catch (Throwable $e) { error_log('demo access: ' . $e->getMessage()); }

    return [
        'restaurant' => DEMO_RESTAURANT_NAME . ' (' . $rid . ')',
        'dishes' => count($items),
        'tables' => count($tables),
        'customers' => count($customers),
        'orders' => $nOrders,
        'today_sales' => round($todaySales, 2),
        'login' => DEMO_USERNAME . ' / ' . DEMO_PASSWORD,
    ];
}

/**
 * The Staff app's rider demo always has work (api/rider_app.php calls this for the demo restaurant).
 * The reset's orders age out of the rider screen (it shows the last 2 days) and visitors deliver them,
 * so top up: 2 deliveries on the way for this rider (one cash on delivery), 1 order waiting for a
 * rider and 2 delivered today. Returns true when it added something.
 */
function demoRiderTopUp(PDO $pdo, string $rid, int $staffId, int $mine, int $open, int $doneToday): bool {
    if ($rid !== DEMO_RESTAURANT_ID || $staffId <= 0) return false;
    if ($mine >= 2 && $open >= 1 && $doneToday >= 2) return false;
    // several visitors at once: one top-up at a time
    if ((int)$pdo->query("SELECT GET_LOCK('demo_rider_topup', 0)")->fetchColumn() !== 1) return false;
    try {
        $rider = $pdo->prepare("SELECT id, member_name, phone FROM staff WHERE id = ? AND restaurant_id = ? AND role = 'Delivery'");
        $rider->execute([$staffId, $rid]);
        $rider = $rider->fetch(PDO::FETCH_ASSOC);
        if (!$rider) return false;
        $dishes = $pdo->prepare('SELECT id, item_name_en, base_price FROM menu_items WHERE restaurant_id = ? AND base_price > 0 ORDER BY RAND() LIMIT 12');
        $dishes->execute([$rid]);
        $dishes = $dishes->fetchAll(PDO::FETCH_ASSOC);
        $people = $pdo->prepare('SELECT customer_name AS name, phone FROM customers WHERE restaurant_id = ? AND phone IS NOT NULL ORDER BY RAND() LIMIT 6');
        $people->execute([$rid]);
        $people = $people->fetchAll(PDO::FETCH_ASSOC);
        if (!$dishes || !$people) return false;
        $areas = ['Indiranagar 2nd Stage', 'HAL 3rd Stage', 'Domlur Layout', 'Jeevan Bima Nagar', 'CMH Road', 'Defence Colony', 'Ulsoor', 'Kodihalli'];

        // [delivery step, order status, payment, minutes ago]
        $plan = [];
        foreach (array_slice([['In_Transit', 'Ready', 'Cash', 18], ['Assigned', 'Ready', 'Online', 9]], 0, max(0, 2 - $mine)) as $p) $plan[] = $p;
        if ($open < 1) $plan[] = ['Waiting', 'Ready', 'Online', 6];
        foreach (array_slice([['Delivered', 'Completed', 'Online', 95], ['Delivered', 'Completed', 'Online', 160]], 0, max(0, 2 - $doneToday)) as $p) $plan[] = $p;

        $now = time();
        foreach ($plan as $n => [$step, $status, $method, $ago]) {
            $ts = $now - $ago * 60;
            // delivered ones stay inside today
            if ($step === 'Delivered' && date('Y-m-d', $ts) !== date('Y-m-d', $now)) $ts = strtotime('today') + 60 * ($n + 1);
            $created = date('Y-m-d H:i:s', $ts);
            $lines = array_slice($dishes, ($n * 2) % max(1, count($dishes) - 1), mt_rand(1, 3));
            // all or nothing: a half-built order would show up for every visitor
            $pdo->beginTransaction();
            try {
                $subtotal = 0.0;
                foreach ($lines as &$l) { $l['q'] = mt_rand(1, 2); $subtotal += (float)$l['base_price'] * $l['q']; }
                unset($l);
                $deliveryCharge = (float)(mt_rand(2, 6) * 8);
                $tax = round($subtotal * 0.05, 2);
                $total = round($subtotal + $tax + $deliveryCharge + 20, 2);
                $c = $people[$n % count($people)];
                $address = mt_rand(10, 999) . ', ' . $areas[mt_rand(0, count($areas) - 1)] . ', Bengaluru';
                $orderId = demoInsert($pdo, 'orders', [
                    'restaurant_id' => $rid, 'order_number' => 'ORD-' . date('Ymd', $ts) . '-R' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7)),
                    'customer_name' => $c['name'], 'customer_phone' => $c['phone'], 'order_type' => 'Delivery',
                    'payment_method' => $method, 'payment_status' => $method === 'Online' || $step === 'Delivered' ? 'Paid' : 'Pending',
                    'order_status' => $status, 'source' => 'website', 'subtotal' => $subtotal, 'tax' => $tax, 'total' => $total,
                    'delivery_charge' => $deliveryCharge, 'delivery_address' => $address, 'customer_address' => $address,
                    'table_id' => null, 'coupon_code' => null, 'discount_amount' => 0, 'notes' => null,
                    'prep_minutes' => 20, 'estimated_ready_at' => date('Y-m-d H:i:s', $ts + 20 * 60),
                    'created_at' => $created, 'updated_at' => $created,
                ]);
                if (!$orderId) { $pdo->rollBack(); continue; }
                foreach ($lines as $l) {
                    demoInsert($pdo, 'order_items', ['order_id' => $orderId, 'menu_item_id' => $l['id'], 'item_name' => $l['item_name_en'], 'quantity' => $l['q'],
                                                     'unit_price' => $l['base_price'], 'total_price' => (float)$l['base_price'] * $l['q'], 'created_at' => $created]);
                }
                if ($step === 'Waiting') { $pdo->commit(); continue; } // no rider yet: shows under "Waiting for a rider"
                $at = fn(int $min) => date('Y-m-d H:i:s', min($now, $ts + $min * 60));
                demoInsert($pdo, 'delivery_tracking', [
                    'restaurant_id' => $rid, 'order_id' => $orderId, 'delivery_address' => $address, 'delivery_status' => $step,
                    'rider_staff_id' => $rider['id'], 'rider_name' => $rider['member_name'], 'rider_phone' => $rider['phone'], 'assigned_at' => $at(4),
                    'picked_up_at' => in_array($step, ['In_Transit', 'Delivered'], true) ? $at(10) : null,
                    'in_transit_at' => in_array($step, ['In_Transit', 'Delivered'], true) ? $at(11) : null,
                    'delivered_at' => $step === 'Delivered' ? $at(35) : null,
                    'created_at' => $created,
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
        return (bool)$plan;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('demo_rider_topup')");
    }
}
