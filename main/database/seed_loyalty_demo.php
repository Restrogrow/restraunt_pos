<?php
/**
 * Demo setup for Growth: switches the whole loyalty system on for the demo
 * restaurant so every part can be tried end to end. Safe to run again (it
 * updates in place).
 *
 *   php main/database/seed_loyalty_demo.php [RESTAURANT_ID]     (default RESSGI002)
 *
 * Sets up: points program with levels (kept), Autopilot gifts, Games (spin
 * wheel + four challenges), Spice Club VIP plans, the phone card, and
 * birthdays / anniversaries on the seeded demo customers.
 *
 * WhatsApp and the demo customers' WhatsApp opt-ins are only switched on when
 * this runs on a local computer, where nothing is ever sent. On a server the
 * script leaves WhatsApp alone, so no real number gets a message.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("Run from the command line.\n"); }

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/loyalty_autopilot.php';
require_once __DIR__ . '/../config/vip_membership.php';
require_once __DIR__ . '/../config/wallet_card.php';
require_once __DIR__ . '/../config/whatsapp_engine.php';
require_once __DIR__ . '/../config/scratch_helpers.php';

$rid = $argv[1] ?? 'RESSGI002';
$conn = getConnection();
$own = $conn->prepare('SELECT restaurant_name FROM users WHERE restaurant_id = ?');
$own->execute([$rid]);
$name = $own->fetchColumn();
if (!$name) exit("Restaurant $rid not found.\n");
echo "Setting up the loyalty demo for $name ($rid)\n";

ensureLoyaltyAutopilotSchema($conn);
ensureVipSchema($conn);
ensureWalletCardSchema($conn);
ensureWhatsappSchema($conn);
ensureScratchTables($conn);
getGrowthSettings($conn, $rid);   // makes sure the row exists

// A dish for "free dish" rewards: a dessert if there is one, else the cheapest dish
$dish = $conn->prepare("SELECT mi.id FROM menu_items mi JOIN menu m ON m.id = mi.menu_id WHERE mi.restaurant_id = ? AND mi.base_price > 0
    ORDER BY (m.menu_name LIKE '%essert%' OR mi.item_name_en LIKE '%Jamun%') DESC, mi.base_price ASC LIMIT 1");
$dish->execute([$rid]);
$freeDish = (int)$dish->fetchColumn();
$star = $conn->prepare("SELECT oi.menu_item_id FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.restaurant_id = ? AND oi.menu_item_id IS NOT NULL
    GROUP BY oi.menu_item_id ORDER BY SUM(oi.quantity) DESC LIMIT 1");
$star->execute([$rid]);
$tryDish = (int)$star->fetchColumn() ?: $freeDish;

// 1. Program: points (levels and free-dish rewards stay as they are), referrals on
$conn->prepare("UPDATE growth_settings SET loyalty_enabled = 1, program_type = 'points', referral_enabled = 1, reward_valid_days = 30,
    autopilot_last_run = NULL, vip_enabled = 1, vip_name = 'Spice Club', vip_tagline = 'Save on every order, all year', card_enabled = 1, card_color = '#1c1230', card_text_color = '#ffffff'
    WHERE restaurant_id = ?")->execute([$rid]);
echo "  Program: points, referrals on\n";

// 2. Autopilot gifts
$auto = [
    'welcome'     => [1, 'flat', 50, null, 0, 199, 30],
    'birthday'    => [1, 'item', 0, $freeDish ?: null, 7, 0, 14],
    'anniversary' => [1, 'percent', 15, null, 7, 499, 14],
    'winback'     => [1, 'flat', 75, null, 30, 299, 14],
    'tier_up'     => [1, 'flat', 100, null, 0, 399, 30],
    'milestone'   => [1, 'percent', 20, null, 10, 0, 30],
    'referral'    => [1, 'flat', 50, null, 0, 199, 30],
];
$defs = loyaltyAutopilotDefaults();
$ins = $conn->prepare('INSERT INTO loyalty_automations (restaurant_id, type, enabled, reward_kind, reward_value, reward_item_id, param, min_order, valid_days, message)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), reward_kind = VALUES(reward_kind), reward_value = VALUES(reward_value),
    reward_item_id = VALUES(reward_item_id), param = VALUES(param), min_order = VALUES(min_order), valid_days = VALUES(valid_days), message = COALESCE(message, VALUES(message))');
foreach ($auto as $type => $a) {
    if ($a[1] === 'item' && !$a[3]) { $a[1] = 'flat'; $a[2] = 100; }
    $ins->execute([$rid, $type, $a[0], $a[1], $a[2], $a[3], $a[4], $a[5], $a[6], $defs[$type][6]]);
}
echo "  Autopilot: welcome, birthday, anniversary, we miss you, level up, milestone, referral\n";

// 3. Games: spin the wheel after checkout (prizes kept if already set), four challenges
$conn->prepare("INSERT INTO scratch_settings (restaurant_id, enabled, game_style, valid_days, monthly_budget) VALUES (?, 1, 'wheel', 15, 5000)
    ON DUPLICATE KEY UPDATE enabled = 1, game_style = 'wheel'")->execute([$rid]);
$hasPrizes = $conn->prepare('SELECT COUNT(*) FROM scratch_prizes WHERE restaurant_id = ?');
$hasPrizes->execute([$rid]);
if (!(int)$hasPrizes->fetchColumn()) {
    $p = $conn->prepare('INSERT INTO scratch_prizes (restaurant_id, type, value, weight, position, min_order) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ([['flat', 50, 30, 0, null], ['percent', 10, 15, 1, null], ['flat', 100, 5, 2, 599], ['none', 0, 50, 3, null]] as $x) $p->execute([$rid, $x[0], $x[1], $x[2], $x[3], $x[4]]);
}
$conn->prepare('DELETE FROM loyalty_challenges WHERE restaurant_id = ?')->execute([$rid]);
$ch = $conn->prepare('INSERT INTO loyalty_challenges (restaurant_id, kind, title, target, item_id, reward_kind, reward_value, reward_item_id, min_order, valid_days) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$ch->execute([$rid, 'visits', 'Visit 3 times this month', 3, null, 'flat', 100, null, 299, 30]);
$ch->execute([$rid, 'spend', null, 2000, null, 'percent', 15, null, 0, 30]);
if ($tryDish) $ch->execute([$rid, 'dish', null, 1, $tryDish, 'flat', 50, null, 199, 30]);
$ch->execute([$rid, 'streak', null, 3, null, $freeDish ? 'item' : 'flat', $freeDish ? 0 : 100, $freeDish ?: null, 0, 14]);
echo "  Games: spin the wheel, 4 challenges (visits, spend, try a dish, 3-week streak)\n";

// 4. VIP: Spice Club plans (kept if the owner already made some)
$hasPlans = $conn->prepare('SELECT COUNT(*) FROM vip_plans WHERE restaurant_id = ?');
$hasPlans->execute([$rid]);
if (!(int)$hasPlans->fetchColumn()) {
    $vp = $conn->prepare('INSERT INTO vip_plans (restaurant_id, name, price, duration_days, discount_pct, discount_max, free_delivery, free_delivery_min, earn_boost, gift_kind, gift_value, gift_item_id, highlight, position)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)');
    $vp->execute([$rid, 'Monthly', 199, 30, 10, 100, 1, 1.5, null, 0, null, 0, 0]);
    $vp->execute([$rid, 'Quarterly', 499, 90, 10, 150, 1, 2, 'flat', 100, null, 1, 1]);
    $vp->execute([$rid, 'Yearly', 1499, 365, 15, 200, 1, 2, $freeDish ? 'item' : 'flat', $freeDish ? 0 : 150, $freeDish ?: null, 0, 2]);
}
echo "  VIP: Spice Club (monthly, quarterly, yearly)\n";
echo "  Phone card: on\n";

// 5. Demo customers (the seeded 99000001xx numbers only): birthdays and anniversaries spread
//    over the coming weeks, so the birthday and anniversary gifts have someone to go to
$demo = $conn->prepare("SELECT id FROM customers WHERE restaurant_id = ? AND phone LIKE '99000001%' ORDER BY id");
$demo->execute([$rid]);
$ids = $demo->fetchAll(PDO::FETCH_COLUMN);
$set = $conn->prepare('UPDATE customers SET birthday = ?, anniversary = ? WHERE id = ?');
foreach ($ids as $i => $id) {
    $bday = date('Y-m-d', strtotime((1985 + $i) . '-' . date('m-d', strtotime('+' . (2 + $i * 4) . ' days'))));
    $anniv = $i % 3 === 0 ? date('Y-m-d', strtotime((2015 + $i) . '-' . date('m-d', strtotime('+' . (5 + $i * 3) . ' days')))) : null;
    $set->execute([$bday, $anniv, $id]);
}
echo '  Demo customers: birthdays and anniversaries for ' . count($ids) . " customers\n";

// 6. WhatsApp: only on a local computer (nothing is sent there)
if (waDryRun()) {
    $conn->prepare("INSERT INTO wa_settings (restaurant_id, enabled, mode, api_key, account_name, quiet_start, quiet_end, weekly_cap) VALUES (?, 1, 'own', 'local_test_key_0000', 'Local test account', 22, 9, 2)
        ON DUPLICATE KEY UPDATE enabled = 1, mode = 'own', api_key = COALESCE(api_key, VALUES(api_key)), account_name = COALESCE(account_name, VALUES(account_name))")->execute([$rid]);
    $slug = substr(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), 0, 20);
    $wa = $conn->prepare('INSERT INTO wa_auto (restaurant_id, kind, enabled, template_name, language) VALUES (?, ?, 1, ?, ?) ON DUPLICATE KEY UPDATE enabled = 1, template_name = COALESCE(template_name, VALUES(template_name))');
    foreach (array_keys(waKinds()) as $k) $wa->execute([$rid, $k, trim($slug, '_') . '_' . $k . '_gift', 'en']);
    $conn->prepare("UPDATE customers SET whatsapp_optin = 1, optin_at = COALESCE(optin_at, NOW()) WHERE restaurant_id = ? AND phone LIKE '99000001%'")->execute([$rid]);
    echo "  WhatsApp: on in test mode, demo customers opted in (local computer: nothing is sent)\n";
} else {
    echo "  WhatsApp: left as it is (on a server it would message real numbers)\n";
}
echo "Done. Open Growth > Loyalty Studio and Growth > WhatsApp.\n";
