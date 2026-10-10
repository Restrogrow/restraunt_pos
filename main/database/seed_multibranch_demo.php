<?php
/**
 * Multi-branch demo data (LOCAL ONLY).
 *
 *   php main/database/seed_multibranch_demo.php
 *
 * Creates a 3-branch chain ("Spice Garden") with menus, tables, staff,
 * customers and ~2 weeks of orders, plus a multi-branch owner login that
 * lands on views/branches.php and can switch between the branches.
 *
 * Re-runnable: it first removes only the demo rows it created (the fixed
 * restaurant IDs / usernames below), then inserts fresh data.
 *
 * Logins (all created by this script):
 *   Multi-branch owner : sg_owner        / Owner@123
 *   Branch admins      : sg_koramangala  / Branch@123
 *                        sg_indiranagar  / Branch@123
 *                        sg_hsr          / Branch@123
 *   Staff (per branch) : <role>.<code>@spicegarden.test / Staff@123
 *                        role = manager | waiter | chef, code = kor | ind | hsr
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../db_connection.php';
$pdo = getConnection();

$host = (string)$pdo->query('SELECT @@hostname')->fetchColumn();
if (stripos($host, 'hstgr') !== false || stripos(gethostname(), 'hstgr') !== false) {
    exit("Refusing to seed demo data on a Hostinger/production server.\n");
}

date_default_timezone_set('Asia/Kolkata');
mt_srand(20261010); // deterministic demo data

$OWNER = ['username' => 'sg_owner', 'password' => 'Owner@123', 'display' => 'Spice Garden Group'];
$BRANCHES = [
    ['id' => 'RESSGK001', 'code' => 'kor', 'user' => 'sg_koramangala', 'name' => 'Spice Garden Koramangala',
     'label' => 'Koramangala', 'phone' => '9800000001', 'address' => '80 Feet Rd, Koramangala, Bengaluru', 'scale' => 1.3],
    ['id' => 'RESSGI002', 'code' => 'ind', 'user' => 'sg_indiranagar', 'name' => 'Spice Garden Indiranagar',
     'label' => 'Indiranagar', 'phone' => '9800000002', 'address' => '100 Feet Rd, Indiranagar, Bengaluru', 'scale' => 1.0],
    ['id' => 'RESSGH003', 'code' => 'hsr', 'user' => 'sg_hsr', 'name' => 'Spice Garden HSR Layout',
     'label' => 'HSR Layout', 'phone' => '9800000003', 'address' => '27th Main, HSR Layout, Bengaluru', 'scale' => 0.7],
];
$BRANCH_PASSWORD = 'Branch@123';
$STAFF_PASSWORD = 'Staff@123';

$MENU = [
    'Starters' => [
        ['Paneer Tikka', 'Veg', 249], ['Chicken 65', 'Non Veg', 279], ['Veg Spring Rolls', 'Veg', 189],
        ['Tandoori Chicken (Half)', 'Non Veg', 329], ['Egg Bhurji', 'Egg', 149],
    ],
    'Main Course' => [
        ['Butter Chicken', 'Non Veg', 349], ['Paneer Butter Masala', 'Veg', 299], ['Dal Makhani', 'Veg', 229],
        ['Mutton Rogan Josh', 'Non Veg', 429], ['Chicken Biryani', 'Non Veg', 319], ['Veg Biryani', 'Veg', 259],
    ],
    'Breads' => [
        ['Butter Naan', 'Veg', 59], ['Garlic Naan', 'Veg', 69], ['Tandoori Roti', 'Veg', 35], ['Laccha Paratha', 'Veg', 65],
    ],
    'Beverages' => [
        ['Masala Chai', 'Drink', 49], ['Sweet Lassi', 'Drink', 89], ['Fresh Lime Soda', 'Drink', 79], ['Cold Coffee', 'Drink', 129],
    ],
    'Desserts' => [
        ['Gulab Jamun', 'Other', 99], ['Rasmalai', 'Other', 129], ['Kulfi', 'Other', 109],
    ],
];
$AREAS = ['Ground Floor' => 6, 'Rooftop' => 4];
$CUSTOMERS = ['Aarav Sharma', 'Diya Patel', 'Rohan Iyer', 'Ananya Reddy', 'Kabir Mehta', 'Isha Nair',
              'Vivaan Gupta', 'Meera Joshi', 'Arjun Rao', 'Saanvi Kulkarni', 'Aditya Menon', 'Priya Das'];
$TAX_RATE = 0.05;
// Bestsellers in display order: [item name, offer % off (0 = no offer)]
$BESTSELLERS = [
    ['Butter Chicken', 10], ['Paneer Tikka', 10], ['Chicken Biryani', 0],
    ['Dal Makhani', 10], ['Mutton Rogan Josh', 0], ['Gulab Jamun', 0],
];
// [code, type, value, minimum order, description] — valid for 3 months from seeding
$COUPONS = [
    ['WELCOME50', 'flat', 50, 299, 'Flat ₹50 off your first order'],
    ['SPICE20', 'percent', 20, 599, '20% off on orders above ₹599'],
    ['FEAST100', 'flat', 100, 999, '₹100 off on orders above ₹999'],
    ['WEEKEND15', 'percent', 15, 399, '15% off every weekend order'],
];

$ids = array_column($BRANCHES, 'id');
$in = implode(',', array_fill(0, count($ids), '?'));

// DDL implicitly commits in MySQL, so create tables before the transaction
require_once __DIR__ . '/../config/bestseller_helpers.php';
ensureBestsellersTable($pdo);
// Same definition as api/submit_feedback.php (customer order ratings)
$pdo->exec("CREATE TABLE IF NOT EXISTS order_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    order_id INT NOT NULL,
    order_number VARCHAR(50) NOT NULL,
    customer_name VARCHAR(100) DEFAULT NULL,
    customer_phone VARCHAR(20) DEFAULT NULL,
    rating TINYINT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    review TEXT DEFAULT NULL,
    is_approved TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_restaurant (restaurant_id),
    INDEX idx_order (order_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->beginTransaction();
try {
    // ── Clean previous demo rows ──
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->prepare("DELETE ki FROM kot_items ki JOIN kot k ON k.id = ki.kot_id WHERE k.restaurant_id IN ($in)")->execute($ids);
    $pdo->prepare("DELETE oi FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.restaurant_id IN ($in)")->execute($ids);
    foreach (['order_feedback', 'kot', 'orders', 'bestsellers', 'menu_items', 'menu', 'tables', 'areas', 'staff', 'customers', 'coupons', 'users'] as $t) {
        $pdo->prepare("DELETE FROM `$t` WHERE restaurant_id IN ($in)")->execute($ids);
    }
    $pdo->prepare("DELETE FROM branch_restaurant_links WHERE restaurant_id IN ($in)")->execute($ids);
    $pdo->prepare("DELETE FROM branch_admins WHERE username = ?")->execute([$OWNER['username']]);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    // ── Multi-branch owner ──
    $pdo->prepare("INSERT INTO branch_admins (username, password_hash, display_name, is_active) VALUES (?, ?, ?, 1)")
        ->execute([$OWNER['username'], password_hash($OWNER['password'], PASSWORD_DEFAULT), $OWNER['display']]);
    $ownerId = (int)$pdo->lastInsertId();

    $insUser = $pdo->prepare("
        INSERT INTO users (username, email, phone, address, password, restaurant_id, restaurant_name, owner_name,
                           currency_symbol, timezone, is_active, subscription_status, trial_end_date,
                           enable_delivery, enable_takeaway, enable_dinein, enable_gst, description, description_format)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Spice Garden Group', '₹', 'Asia/Kolkata', 1, 'active', ?, 1, 1, 1, 1,
                'North Indian curries, smoky tandoor favourites, biryanis and fresh breads — cooked to order.', 'paragraph')");
    $insLink = $pdo->prepare("INSERT INTO branch_restaurant_links (branch_admin_id, restaurant_id, label) VALUES (?, ?, ?)");
    $insMenu = $pdo->prepare("INSERT INTO menu (restaurant_id, menu_name, is_active, sort_order) VALUES (?, ?, 1, ?)");
    $insItem = $pdo->prepare("
        INSERT INTO menu_items (restaurant_id, menu_id, item_name_en, item_category, item_type, preparation_time,
                                is_available, base_price, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)");
    $insArea = $pdo->prepare("INSERT INTO areas (restaurant_id, area_name, sort_order) VALUES (?, ?, ?)");
    $insTable = $pdo->prepare("INSERT INTO tables (restaurant_id, area_id, table_number, capacity, is_available, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    $insStaff = $pdo->prepare("INSERT INTO staff (restaurant_id, member_name, email, phone, password, role, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
    $insCust = $pdo->prepare("INSERT INTO customers (restaurant_id, customer_name, phone, email, total_visits, last_visit_date, total_spent) VALUES (?, ?, ?, ?, 0, NULL, 0)");
    $insOrder = $pdo->prepare("
        INSERT INTO orders (restaurant_id, table_id, order_number, customer_name, customer_phone, order_type,
                            payment_method, payment_status, order_status, source, subtotal, tax, total, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insOrderItem = $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, unit_price, total_price, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $insKot = $pdo->prepare("
        INSERT INTO kot (restaurant_id, kot_number, table_id, order_type, customer_name, kot_status, subtotal, tax, total, created_at, order_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insKotItem = $pdo->prepare("INSERT INTO kot_items (kot_id, menu_item_id, item_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
    $insFeedback = $pdo->prepare("INSERT INTO order_feedback (restaurant_id, order_id, order_number, customer_name, customer_phone, rating, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $insBestseller = $pdo->prepare("INSERT INTO bestsellers (restaurant_id, menu_item_id, sort_order, offer_price) VALUES (?, ?, ?, ?)");
    $insCoupon = $pdo->prepare("
        INSERT INTO coupons (restaurant_id, coupon_code, discount_type, discount_value, minimum_order_amount,
                             max_uses, current_uses, valid_from, valid_until, is_active, description)
        VALUES (?, ?, ?, ?, ?, 0, 0, CURDATE(), CURDATE() + INTERVAL 3 MONTH, 1, ?)");
    $updCust = $pdo->prepare("UPDATE customers SET total_visits = total_visits + 1, total_spent = total_spent + ?, last_visit_date = GREATEST(COALESCE(last_visit_date, '2000-01-01'), ?) WHERE id = ?");

    $branchPwHash = password_hash($BRANCH_PASSWORD, PASSWORD_DEFAULT);
    $staffPwHash = password_hash($STAFF_PASSWORD, PASSWORD_DEFAULT);
    $summary = [];
    $orderSeq = 0;

    foreach ($BRANCHES as $bi => $b) {
        $rid = $b['id'];
        $insUser->execute([$b['user'], "{$b['user']}@spicegarden.test", $b['phone'], $b['address'], $branchPwHash,
                           $rid, $b['name'], date('Y-m-d', strtotime('+1 year'))]);
        $insLink->execute([$ownerId, $rid, $b['label']]);

        // Menu
        $items = [];
        $mi = 0;
        foreach ($MENU as $cat => $list) {
            $insMenu->execute([$rid, $cat, ++$mi]);
            $menuId = (int)$pdo->lastInsertId();
            foreach ($list as $si => [$name, $type, $price]) {
                // small per-branch price differences
                $p = round($price * (1 + ($bi - 1) * 0.05));
                $insItem->execute([$rid, $menuId, $name, $cat, $type, $cat === 'Beverages' ? 5 : 15, $p, $si + 1]);
                $items[] = ['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'price' => $p];
            }
        }

        // Areas & tables
        $tables = [];
        $ai = 0; $tn = 0;
        foreach ($AREAS as $area => $count) {
            $insArea->execute([$rid, $area, ++$ai]);
            $areaId = (int)$pdo->lastInsertId();
            for ($i = 1; $i <= $count; $i++) {
                $tn++;
                $insTable->execute([$rid, $areaId, ($ai === 1 ? 'T' : 'R') . $i, $i % 3 === 0 ? 6 : 4, 1, $tn]);
                $tables[] = (int)$pdo->lastInsertId();
            }
        }

        // Staff
        $staffNames = ['manager' => ['Manager', 'Neha'], 'waiter' => ['Waiter', 'Ravi'], 'chef' => ['Chef', 'Suresh']];
        $si = 0;
        foreach ($staffNames as $key => [$role, $first]) {
            $si++;
            $insStaff->execute([$rid, "$first ({$b['label']})", "$key.{$b['code']}@spicegarden.test",
                                '97' . str_pad((string)($bi * 10 + $si), 8, '0', STR_PAD_LEFT), $staffPwHash, $role]);
        }

        // Customers
        $custIds = [];
        foreach ($CUSTOMERS as $ci => $cname) {
            $phone = '99' . str_pad((string)($bi * 100 + $ci), 8, '0', STR_PAD_LEFT);
            $insCust->execute([$rid, $cname, $phone, strtolower(str_replace(' ', '.', $cname)) . '@example.com']);
            $custIds[] = ['id' => (int)$pdo->lastInsertId(), 'name' => $cname, 'phone' => $phone];
        }

        // Bestsellers (website "Customer favourites" row; some with an offer price)
        $byName = [];
        foreach ($items as $it) $byName[$it['name']] = $it;
        foreach ($BESTSELLERS as $rank => [$itemName, $pctOff]) {
            if (!isset($byName[$itemName])) continue;
            $offer = $pctOff > 0 ? round($byName[$itemName]['price'] * (100 - $pctOff) / 100) : null;
            $insBestseller->execute([$rid, $byName[$itemName]['id'], $rank, $offer]);
        }

        // Coupons (shown on the customer website, applied at checkout)
        foreach ($COUPONS as [$code, $type, $value, $minOrder, $desc]) {
            $insCoupon->execute([$rid, $code, $type, $value, $minOrder, $desc]);
        }

        // Orders: 13 past days (completed) + today (mix of statuses)
        $nOrders = 0; $todayRevenue = 0;
        $busyTables = [];
        for ($day = 13; $day >= 0; $day--) {
            $perDay = (int)round(mt_rand(6, 12) * $b['scale']);
            for ($k = 0; $k < $perDay; $k++) {
                $hour = [12, 13, 13, 14, 19, 20, 20, 21, 21, 22][mt_rand(0, 9)];
                $ts = strtotime(date('Y-m-d', strtotime("-$day days")) . sprintf(' %02d:%02d:%02d', $hour, mt_rand(0, 59), mt_rand(0, 59)));
                if ($day === 0 && $ts > time()) $ts = time() - mt_rand(300, 7200);
                $created = date('Y-m-d H:i:s', $ts);

                $type = ['Dine-in', 'Dine-in', 'Dine-in', 'Takeaway', 'Delivery'][mt_rand(0, 4)];
                $cust = $custIds[mt_rand(0, count($custIds) - 1)];
                $tableId = $type === 'Dine-in' ? $tables[mt_rand(0, count($tables) - 1)] : null;

                $status = 'Completed';
                if ($day === 0) {
                    $r = mt_rand(1, 10);
                    $status = $r <= 5 ? 'Completed' : ($r <= 6 ? 'Pending' : ($r <= 8 ? 'Preparing' : ($r <= 9 ? 'Ready' : 'Cancelled')));
                } elseif (mt_rand(1, 25) === 1) {
                    $status = 'Cancelled';
                }

                $lines = [];
                $subtotal = 0;
                $nLines = mt_rand(1, 4);
                for ($l = 0; $l < $nLines; $l++) {
                    $it = $items[mt_rand(0, count($items) - 1)];
                    $q = mt_rand(1, 3);
                    $lines[] = [$it, $q];
                    $subtotal += $it['price'] * $q;
                }
                $tax = round($subtotal * $TAX_RATE, 2);
                $total = $subtotal + $tax;
                $paid = $status === 'Completed';
                $orderSeq++;
                $orderNo = 'ORD-' . date('Ymd', $ts) . '-' . strtoupper($b['code']) . str_pad((string)$orderSeq, 4, '0', STR_PAD_LEFT);

                $insOrder->execute([$rid, $tableId, $orderNo, $cust['name'], $cust['phone'], $type,
                                    ['Cash', 'UPI', 'Card'][mt_rand(0, 2)], $paid ? 'Paid' : 'Pending', $status,
                                    $type === 'Delivery' ? 'website' : 'pos', $subtotal, $tax, $total, $created, $created]);
                $orderId = (int)$pdo->lastInsertId();
                foreach ($lines as [$it, $q]) {
                    $insOrderItem->execute([$orderId, $it['id'], $it['name'], $q, $it['price'], $it['price'] * $q, $created]);
                }

                // Open orders today get a live KOT and occupy their table
                if ($day === 0 && in_array($status, ['Pending', 'Preparing', 'Ready'], true)) {
                    $kotStatus = $status === 'Ready' ? 'Ready' : ($status === 'Preparing' ? 'Preparing' : 'Pending');
                    $insKot->execute([$rid, 'KOT-' . date('Ymd', $ts) . '-' . strtoupper($b['code']) . str_pad((string)$orderSeq, 4, '0', STR_PAD_LEFT),
                                      $tableId, $type, $cust['name'], $kotStatus, $subtotal, $tax, $total, $created, $orderId]);
                    $kotId = (int)$pdo->lastInsertId();
                    foreach ($lines as [$it, $q]) {
                        $insKotItem->execute([$kotId, $it['id'], $it['name'], $q, $it['price'], $it['price'] * $q]);
                    }
                    if ($tableId) $busyTables[$tableId] = true;
                }

                if ($status !== 'Cancelled') {
                    $updCust->execute([$total, date('Y-m-d', $ts), $cust['id']]);
                    if ($day === 0) $todayRevenue += $total;
                }
                // Demo customer feedback: ~45% of past completed orders get a 1–5 rating
                if ($status === 'Completed' && $day > 0 && mt_rand(1, 100) <= 45) {
                    $rating = [5, 5, 5, 4, 4, 4, 4, 3, 5, 4, 2][mt_rand(0, 10)];
                    $insFeedback->execute([$rid, $orderId, $orderNo, $cust['name'], $cust['phone'], $rating,
                                           date('Y-m-d H:i:s', $ts + mt_rand(1800, 7200))]);
                }
                $nOrders++;
            }
        }
        if ($busyTables) {
            $pdo->exec('UPDATE tables SET is_available = 0 WHERE id IN (' . implode(',', array_map('intval', array_keys($busyTables))) . ')');
        }
        $summary[] = sprintf("  %-26s %s  %3d orders, today ₹%s", $b['name'], $rid, $nOrders, number_format($todayRevenue, 2));
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seeding failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Multi-branch demo data created:\n" . implode("\n", $summary) . "\n\n";
echo "Logins (http://localhost/menuwebsite/main/admin/login.php):\n";
echo "  Multi-branch owner : {$OWNER['username']} / {$OWNER['password']}\n";
foreach ($BRANCHES as $b) {
    echo "  Branch admin       : {$b['user']} / $BRANCH_PASSWORD   ({$b['name']})\n";
}
foreach ($BRANCHES as $b) {
    foreach (['manager', 'waiter', 'chef'] as $r) {
        echo "  Staff              : $r.{$b['code']}@spicegarden.test / $STAFF_PASSWORD\n";
    }
}
