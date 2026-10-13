<?php
/**
 * Inventory engine: the tables behind Menu › Inventory and the stock that sales use up.
 *
 *  - invEnsure($conn)            creates / upgrades the inventory tables (safe to call on every request)
 *  - invSyncSales($conn, $rid)   stock used by orders: once an order is being made (Preparing, Ready,
 *                                Served or Completed) each dish's recipe is taken out of stock, once.
 *                                If that order is later cancelled, rejected or deleted the stock goes back.
 *                                Only orders placed after recipes were first saved count (track_from).
 *  - invConvert($qty, $from, $to) recipe units to stock units (g ↔ kg, ml ↔ L)
 *  - invRefresh($conn, $rid)    sales sync + dishes sold out / back on sale + low-stock alerts
 *  - invKick($conn, $rid)       runs invRefresh once the current request has finished (after its commit).
 *                                Called by POS, KOT, website orders and every order status change, so stock,
 *                                "sold out" on the POS / website and low-stock notifications follow orders live.
 *
 * Purchases still create an "Inventory Purchase" expense and wastage an "Inventory Wastage" expense,
 * the same as controllers/inventory_operations.php, so Reports keep adding up.
 */
require_once __DIR__ . '/inventory_tables.php';

const INV_SCHEMA = 4;
const INV_USED_STATUSES = ['Preparing', 'Ready', 'Served', 'Completed'];
const INV_RETURN_STATUSES = ['Cancelled', 'Rejected'];

function invCol(PDO $conn, string $table, string $col): bool
{
    return (bool)$conn->query("SHOW COLUMNS FROM `$table` LIKE " . $conn->quote($col))->fetch();
}

function invEnsure(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    ensureInventoryTables($conn);
    $conn->exec("CREATE TABLE IF NOT EXISTS inventory_meta (k VARCHAR(40) PRIMARY KEY, v VARCHAR(255) NOT NULL)");
    $v = (int)$conn->query("SELECT v FROM inventory_meta WHERE k = 'schema'")->fetchColumn();
    if ($v >= INV_SCHEMA) return;

    // stock items: supplier, reorder level, code, use-by date
    foreach ([
        'supplier_id' => 'INT NULL DEFAULT NULL',
        'par_level' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
        'sku' => "VARCHAR(60) NOT NULL DEFAULT ''",
        'expiry_date' => 'DATE NULL DEFAULT NULL',
    ] as $c => $def) {
        if (!invCol($conn, 'inventory_items', $c)) $conn->exec("ALTER TABLE inventory_items ADD COLUMN `$c` $def");
    }
    // movements: more kinds (sale, return, count, opening) and what caused them
    $conn->exec("ALTER TABLE inventory_transactions MODIFY COLUMN type VARCHAR(20) NOT NULL DEFAULT 'restock'");
    foreach ([
        'reference_type' => 'VARCHAR(20) NULL DEFAULT NULL',
        'reference_id' => 'INT NULL DEFAULT NULL',
        'created_by' => "VARCHAR(100) NOT NULL DEFAULT ''",
    ] as $c => $def) {
        if (!invCol($conn, 'inventory_transactions', $c)) $conn->exec("ALTER TABLE inventory_transactions ADD COLUMN `$c` $def");
    }
    try { $conn->exec("ALTER TABLE inventory_transactions ADD INDEX idx_invtx_ref (reference_type, reference_id)"); } catch (Throwable $e) {}

    $conn->exec("CREATE TABLE IF NOT EXISTS inventory_suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(10) NOT NULL,
        name VARCHAR(150) NOT NULL,
        contact_person VARCHAR(100) NOT NULL DEFAULT '',
        phone VARCHAR(30) NOT NULL DEFAULT '',
        email VARCHAR(150) NOT NULL DEFAULT '',
        notes VARCHAR(255) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_invsup_restaurant (restaurant_id)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS inventory_recipes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(10) NOT NULL,
        menu_item_id INT NOT NULL,
        inventory_item_id INT NOT NULL,
        quantity DECIMAL(12,4) NOT NULL DEFAULT 0,
        unit VARCHAR(20) NOT NULL DEFAULT '',
        UNIQUE KEY uq_recipe_line (menu_item_id, inventory_item_id),
        INDEX idx_recipe_restaurant (restaurant_id)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS inventory_order_usage (
        order_id INT PRIMARY KEY,
        restaurant_id VARCHAR(10) NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'used',
        used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        returned_at DATETIME NULL DEFAULT NULL,
        INDEX idx_usage_restaurant (restaurant_id, status)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS inventory_purchase_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(10) NOT NULL,
        po_number VARCHAR(30) NOT NULL,
        supplier_id INT NULL DEFAULT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'draft',
        expected_date DATE NULL DEFAULT NULL,
        notes VARCHAR(255) NOT NULL DEFAULT '',
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL DEFAULT NULL,
        received_at DATETIME NULL DEFAULT NULL,
        INDEX idx_po_restaurant (restaurant_id, status)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS inventory_po_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        po_id INT NOT NULL,
        inventory_item_id INT NOT NULL,
        quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
        cost_per_unit DECIMAL(10,2) NOT NULL DEFAULT 0,
        received_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
        INDEX idx_poline_po (po_id)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS inventory_settings (
        restaurant_id VARCHAR(10) PRIMARY KEY,
        auto_deduct TINYINT(1) NOT NULL DEFAULT 1,
        track_from DATETIME NULL DEFAULT NULL
    )");
    // 2: three decimals, so a recipe’s 5 g of a kg item isn’t rounded away
    $conn->exec("ALTER TABLE inventory_items MODIFY quantity_in_stock DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY low_stock_threshold DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY par_level DECIMAL(12,3) NOT NULL DEFAULT 0");
    $conn->exec("ALTER TABLE inventory_transactions MODIFY quantity DECIMAL(12,3) NOT NULL");
    $conn->exec("ALTER TABLE inventory_po_lines MODIFY quantity DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY received_qty DECIMAL(12,3) NOT NULL DEFAULT 0");
    // 3: four decimals on cost, so ₹7 a kg is still right per gram
    $conn->exec("ALTER TABLE inventory_items MODIFY cost_per_unit DECIMAL(12,4) NOT NULL DEFAULT 0");
    $conn->exec("ALTER TABLE inventory_transactions MODIFY cost_per_unit DECIMAL(12,4) NOT NULL DEFAULT 0");
    $conn->exec("ALTER TABLE inventory_po_lines MODIFY cost_per_unit DECIMAL(12,4) NOT NULL DEFAULT 0");
    // 4: when orders take stock out, dishes sold out automatically, low-stock alerts
    foreach ([
        'deduct_on' => "VARCHAR(10) NOT NULL DEFAULT 'kitchen'",
        'auto_soldout' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'low_alert' => 'TINYINT(1) NOT NULL DEFAULT 1',
    ] as $c => $def) {
        if (!invCol($conn, 'inventory_settings', $c)) $conn->exec("ALTER TABLE inventory_settings ADD COLUMN `$c` $def");
    }
    if (!invCol($conn, 'inventory_items', 'low_alerted')) $conn->exec('ALTER TABLE inventory_items ADD COLUMN low_alerted TINYINT(1) NOT NULL DEFAULT 0');
    // dishes this feature switched off (so only those are switched back on); override = the owner switched it on again
    $conn->exec("CREATE TABLE IF NOT EXISTS inventory_auto_off (
        menu_item_id INT PRIMARY KEY,
        restaurant_id VARCHAR(10) NOT NULL,
        override TINYINT(1) NOT NULL DEFAULT 0,
        off_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_autooff_restaurant (restaurant_id)
    )");
    $conn->prepare("REPLACE INTO inventory_meta (k, v) VALUES ('schema', ?)")->execute([(string)INV_SCHEMA]);
}

function invSettings(PDO $conn, string $rid): array
{
    $st = $conn->prepare('SELECT * FROM inventory_settings WHERE restaurant_id = ?');
    $st->execute([$rid]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['auto_deduct' => isset($r['auto_deduct']) ? (int)$r['auto_deduct'] : 1, 'track_from' => $r['track_from'] ?? null,
        'deduct_on' => ($r['deduct_on'] ?? 'kitchen') === 'placed' ? 'placed' : 'kitchen',
        'auto_soldout' => (int)($r['auto_soldout'] ?? 0), 'low_alert' => isset($r['low_alert']) ? (int)$r['low_alert'] : 1];
}

/** Statuses at which an order has taken its stock: from the kitchen starting it, or from the moment it's placed. */
function invUsedStatuses(array $set): array
{
    return $set['deduct_on'] === 'placed' ? ['Pending', 'Accepted', 'Preparing', 'Ready', 'Served', 'Completed'] : INV_USED_STATUSES;
}

/** Unit families: weight in grams, volume in millilitres; anything else only matches itself. */
function invUnitBase(string $u): array
{
    $u = strtolower(trim($u));
    $map = ['kg' => ['w', 1000], 'g' => ['w', 1], 'l' => ['v', 1000], 'ml' => ['v', 1]];
    return $map[$u] ?? ['u:' . $u, 1];
}

/** $qty in $from expressed in $to; units from different families are taken as-is. */
function invConvert(float $qty, string $from, string $to): float
{
    if ($from === '' || $to === '' || strcasecmp($from, $to) === 0) return $qty;
    [$fa, $fm] = invUnitBase($from);
    [$ta, $tm] = invUnitBase($to);
    if ($fa !== $ta) return $qty;
    return $qty * $fm / $tm;
}

/**
 * Take each new order's recipe out of stock, and put back stock for orders that were cancelled.
 * Returns ['used' => n orders, 'returned' => n orders].
 */
function invSyncSales(PDO $conn, string $rid, int $limit = 300): array
{
    $out = ['used' => 0, 'returned' => 0];
    invEnsure($conn);
    $set = invSettings($conn, $rid);
    if (!$set['auto_deduct'] || !$set['track_from']) return $out;

    // recipes of this restaurant, by dish
    $st = $conn->prepare('SELECT r.menu_item_id, r.inventory_item_id, r.quantity, r.unit, i.unit AS stock_unit, i.cost_per_unit
        FROM inventory_recipes r JOIN inventory_items i ON i.id = r.inventory_item_id AND i.is_active = 1
        WHERE r.restaurant_id = ?');
    $st->execute([$rid]);
    $recipes = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $recipes[(int)$r['menu_item_id']][] = $r;

    // 1. orders now being made that haven't used stock yet
    $used = invUsedStatuses($set);
    $in = implode(',', array_fill(0, count($used), '?'));
    $st = $conn->prepare("SELECT o.id, o.order_number, o.created_at FROM orders o
        LEFT JOIN inventory_order_usage u ON u.order_id = o.id
        WHERE o.restaurant_id = ? AND o.created_at >= ? AND o.order_status IN ($in) AND o.deleted_at IS NULL AND u.order_id IS NULL
        ORDER BY o.id LIMIT " . (int)$limit);
    $st->execute(array_merge([$rid, $set['track_from']], $used));
    $orders = $st->fetchAll(PDO::FETCH_ASSOC);
    $itemsSt = $conn->prepare('SELECT menu_item_id, quantity FROM order_items WHERE order_id = ?');
    // what one order should have taken out: item id => ['q' => quantity in stock units, 'c' => cost]
    $need = function (int $orderId) use ($itemsSt, $recipes) {
        $itemsSt->execute([$orderId]);
        $use = [];
        foreach ($itemsSt->fetchAll(PDO::FETCH_ASSOC) as $li) {
            foreach ($recipes[(int)$li['menu_item_id']] ?? [] as $r) {
                $k = (int)$r['inventory_item_id'];
                if (!isset($use[$k])) $use[$k] = ['q' => 0, 'c' => (float)$r['cost_per_unit']];
                $use[$k]['q'] += invConvert((float)$r['quantity'], (string)$r['unit'], (string)$r['stock_unit']) * (int)$li['quantity'];
            }
        }
        return $use;
    };
    $claim = $conn->prepare("INSERT IGNORE INTO inventory_order_usage (order_id, restaurant_id, status) VALUES (?, ?, 'used')");
    // first use is dated at the order, so daily reports show when stock was really used
    $tx = $conn->prepare("INSERT INTO inventory_transactions (restaurant_id, inventory_item_id, type, quantity, cost_per_unit, total_cost, notes, reference_type, reference_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'order', ?, ?)");
    // later changes (dishes added, order cancelled) are dated now, by the database clock
    $txNow = $conn->prepare("INSERT INTO inventory_transactions (restaurant_id, inventory_item_id, type, quantity, cost_per_unit, total_cost, notes, reference_type, reference_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'order', ?)");
    $upd = $conn->prepare('UPDATE inventory_items SET quantity_in_stock = quantity_in_stock + ? WHERE id = ? AND restaurant_id = ?');
    foreach ($orders as $o) {
        $use = $need((int)$o['id']);
        $conn->beginTransaction();
        try {
            $claim->execute([$o['id'], $rid]);
            if ($claim->rowCount() === 1) {   // another request may have taken this order already
                foreach ($use as $iid => $u) {
                    if ($u['q'] <= 0) continue;
                    $q = round($u['q'], 3);
                    $tx->execute([$rid, $iid, 'sale', -$q, $u['c'], round($q * $u['c'], 2), 'Order #' . $o['order_number'], $o['id'], $o['created_at']]);
                    $upd->execute([-$q, $iid, $rid]);
                }
                $out['used']++;
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            error_log('invSyncSales use ' . $o['id'] . ': ' . $e->getMessage());
        }
    }

    // 2. orders changed after they used stock (a table adds dishes, a dish is removed):
    //    take out or put back only the difference
    $st = $conn->prepare("SELECT o.id, o.order_number, u.used_at FROM inventory_order_usage u JOIN orders o ON o.id = u.order_id
        WHERE u.restaurant_id = ? AND u.status = 'used' AND o.updated_at > u.used_at AND o.order_status IN ($in) AND o.deleted_at IS NULL LIMIT " . (int)$limit);
    $st->execute(array_merge([$rid], $used));
    $net = $conn->prepare("SELECT inventory_item_id, -SUM(quantity) q, MAX(cost_per_unit) c FROM inventory_transactions
        WHERE restaurant_id = ? AND reference_type = 'order' AND reference_id = ? AND type IN ('sale', 'return') GROUP BY inventory_item_id");
    $touch = $conn->prepare('UPDATE inventory_order_usage SET used_at = NOW() WHERE order_id = ? AND used_at = ?');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $want = $need((int)$o['id']);
        $conn->beginTransaction();
        try {
            $touch->execute([$o['id'], $o['used_at']]);
            if ($touch->rowCount() === 1) {   // nobody else is checking this order right now
                $net->execute([$rid, $o['id']]);
                $have = [];
                foreach ($net->fetchAll(PDO::FETCH_ASSOC) as $h) $have[(int)$h['inventory_item_id']] = ['q' => (float)$h['q'], 'c' => (float)$h['c']];
                foreach (array_unique(array_merge(array_keys($want), array_keys($have))) as $iid) {
                    $d = round(($want[$iid]['q'] ?? 0) - ($have[$iid]['q'] ?? 0), 3);
                    if (abs($d) < 0.001) continue;
                    $c = $want[$iid]['c'] ?? $have[$iid]['c'];
                    $txNow->execute([$rid, $iid, $d > 0 ? 'sale' : 'return', -$d, $c, round(abs($d) * $c, 2), 'Order #' . $o['order_number'] . ($d > 0 ? ' (dishes added)' : ' (dishes removed)'), $o['id']]);
                    $upd->execute([-$d, $iid, $rid]);
                }
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            error_log('invSyncSales change ' . $o['id'] . ': ' . $e->getMessage());
        }
    }

    // 3. orders that used stock and were then cancelled, rejected or deleted: put the stock back
    $in = implode(',', array_fill(0, count(INV_RETURN_STATUSES), '?'));
    $st = $conn->prepare("SELECT o.id, o.order_number FROM inventory_order_usage u JOIN orders o ON o.id = u.order_id
        WHERE u.restaurant_id = ? AND u.status = 'used' AND (o.order_status IN ($in) OR o.deleted_at IS NOT NULL) LIMIT " . (int)$limit);
    $st->execute(array_merge([$rid], INV_RETURN_STATUSES));
    $mark = $conn->prepare("UPDATE inventory_order_usage SET status = 'returned', returned_at = NOW() WHERE order_id = ? AND status = 'used'");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $conn->beginTransaction();
        try {
            $mark->execute([$o['id']]);
            if ($mark->rowCount() === 1) {
                $net->execute([$rid, $o['id']]);
                foreach ($net->fetchAll(PDO::FETCH_ASSOC) as $b) {
                    $q = round((float)$b['q'], 3);   // what's still out for this order
                    if ($q <= 0) continue;
                    $txNow->execute([$rid, (int)$b['inventory_item_id'], 'return', $q, (float)$b['c'], round($q * (float)$b['c'], 2), 'Order #' . $o['order_number'] . ' cancelled', $o['id']]);
                    $upd->execute([$q, (int)$b['inventory_item_id'], $rid]);
                }
                $out['returned']++;
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            error_log('invSyncSales return ' . $o['id'] . ': ' . $e->getMessage());
        }
    }

    return $out;
}

/**
 * Dishes whose recipe can't be made any more (an ingredient at 0) are switched off, so the POS and the
 * website show them as unavailable; they come back on by themselves when stock is added. Only dishes this
 * switched off are switched back on, and if the owner switches one on by hand it's left alone until restocked.
 * Returns ['off' => [names], 'on' => [names]].
 */
function invAutoAvailability(PDO $conn, string $rid, array $set): array
{
    $out = ['off' => [], 'on' => []];
    $q = function (string $sql, array $a = []) use ($conn) { $st = $conn->prepare($sql); $st->execute($a); return $st; };
    $rows = $q('SELECT r.menu_item_id, r.quantity, r.unit, i.unit AS stock_unit, i.quantity_in_stock FROM inventory_recipes r
        JOIN inventory_items i ON i.id = r.inventory_item_id AND i.is_active = 1 WHERE r.restaurant_id = ?', [$rid])->fetchAll(PDO::FETCH_ASSOC);
    $can = [];
    foreach ($rows as $r) {
        $need = invConvert((float)$r['quantity'], (string)$r['unit'], (string)$r['stock_unit']);
        if ($need <= 0) continue;
        $plates = (int)floor(max(0, (float)$r['quantity_in_stock']) / $need + 1e-9);
        $id = (int)$r['menu_item_id'];
        $can[$id] = isset($can[$id]) ? min($can[$id], $plates) : $plates;
    }
    $off = [];
    foreach ($q('SELECT menu_item_id, override FROM inventory_auto_off WHERE restaurant_id = ?', [$rid])->fetchAll(PDO::FETCH_ASSOC) as $r) $off[(int)$r['menu_item_id']] = (int)$r['override'];
    $dish = [];
    foreach ($q('SELECT id, item_name_en, is_available FROM menu_items WHERE restaurant_id = ?', [$rid])->fetchAll(PDO::FETCH_ASSOC) as $d) $dish[(int)$d['id']] = $d;

    if ($set['auto_soldout']) {
        foreach ($can as $id => $n) {
            if (!isset($dish[$id])) continue;
            $avail = (int)$dish[$id]['is_available'] === 1;
            if ($n === 0) {
                if (!isset($off[$id])) {
                    if ($avail) {
                        $q('UPDATE menu_items SET is_available = 0 WHERE id = ? AND restaurant_id = ?', [$id, $rid]);
                        $q('INSERT IGNORE INTO inventory_auto_off (menu_item_id, restaurant_id) VALUES (?, ?)', [$id, $rid]);
                        $out['off'][] = $dish[$id]['item_name_en'];
                    }
                } elseif ($avail && !$off[$id]) {
                    $q('UPDATE inventory_auto_off SET override = 1 WHERE menu_item_id = ?', [$id]);   // owner switched it back on
                }
            }
        }
    }
    // back in stock (or the recipe / the feature is gone): switch on what we switched off
    foreach ($off as $id => $override) {
        $still = $set['auto_soldout'] && isset($can[$id]) && $can[$id] === 0;
        if ($still) continue;
        if (!$override && isset($dish[$id]) && (int)$dish[$id]['is_available'] === 0) {
            $q('UPDATE menu_items SET is_available = 1 WHERE id = ? AND restaurant_id = ?', [$id, $rid]);
            $out['on'][] = $dish[$id]['item_name_en'];
        }
        $q('DELETE FROM inventory_auto_off WHERE menu_item_id = ? AND restaurant_id = ?', [$id, $rid]);
    }
    return $out;
}

/**
 * Low-stock alerts. An item is low when it is at or below its "Reorder when below" level, or has run out.
 * Each item notifies once when it becomes low (push notification to the owner's devices) and again only
 * after it has been restocked above that level. Returns the items that just became low.
 */
function invLowAlerts(PDO $conn, string $rid, array $set): array
{
    $low = "is_active = 1 AND (quantity_in_stock <= 0 OR (low_stock_threshold > 0 AND quantity_in_stock <= low_stock_threshold))";
    $st = $conn->prepare("SELECT id, item_name, unit, quantity_in_stock FROM inventory_items WHERE restaurant_id = ? AND low_alerted = 0 AND $low");
    $st->execute([$rid]);
    $new = $st->fetchAll(PDO::FETCH_ASSOC);
    // back above the level: ready to alert again next time
    $conn->prepare("UPDATE inventory_items SET low_alerted = 0 WHERE restaurant_id = ? AND low_alerted = 1 AND NOT ($low)")->execute([$rid]);
    if (!$new) return [];
    $conn->prepare('UPDATE inventory_items SET low_alerted = 1 WHERE id IN (' . implode(',', array_map('intval', array_column($new, 'id'))) . ')')->execute();
    if ($set['low_alert']) {
        $fmt = function ($r) { $q = (float)$r['quantity_in_stock']; return $r['item_name'] . ($q <= 0 ? ' (out)' : ' (' . rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.') . ' ' . $r['unit'] . ' left)'); };
        $names = array_map($fmt, $new);
        $title = count($new) === 1 ? 'Low stock: ' . $new[0]['item_name'] : count($new) . ' items running low';
        $body = implode(', ', array_slice($names, 0, 4)) . (count($names) > 4 ? ' and ' . (count($names) - 4) . ' more' : '') . '. Tap to reorder.';
        try {
            require_once __DIR__ . '/push_notification.php';
            sendPushNotification($conn, $rid, $title, $body, '../views/dashboard.php?page=inventoryPage');
        } catch (Throwable $e) {
            error_log('invLowAlerts push: ' . $e->getMessage());
        }
    }
    return $new;
}

/** Everything that follows a stock change: orders, sold-out dishes, low-stock alerts. Never throws. */
function invRefresh(PDO $conn, string $rid, int $limit = 300): array
{
    $r = ['synced' => ['used' => 0, 'returned' => 0], 'availability' => ['off' => [], 'on' => []], 'alerts' => []];
    try {
        invEnsure($conn);
        $set = invSettings($conn, $rid);
        $r['synced'] = invSyncSales($conn, $rid, $limit);
        $r['availability'] = invAutoAvailability($conn, $rid, $set);
        $r['alerts'] = invLowAlerts($conn, $rid, $set);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('invRefresh ' . $rid . ': ' . $e->getMessage());
    }
    return $r;
}

/**
 * Bring stock up to date once this request is done (its order is committed by then). Cheap no-op for
 * restaurants that don't use inventory. Safe to call many times per request.
 */
function invKick(?PDO $conn, $rid): void
{
    static $queued = [];
    $rid = (string)$rid;
    if ($rid === '' || isset($queued[$rid])) return;
    $queued[$rid] = true;
    register_shutdown_function(function () use ($conn, $rid) {
        try {
            if (!$conn && function_exists('getConnection')) $conn = getConnection();
            if (!$conn || $conn->inTransaction()) return;   // the request failed half-way: leave it to the next refresh
            if (!$conn->query("SHOW TABLES LIKE 'inventory_settings'")->fetchColumn()) return;
            $st = $conn->prepare('SELECT 1 FROM inventory_items WHERE restaurant_id = ? AND is_active = 1 LIMIT 1');
            $st->execute([$rid]);
            if (!$st->fetchColumn()) return;
            invRefresh($conn, $rid, 50);
        } catch (Throwable $e) {
            error_log('invKick ' . $rid . ': ' . $e->getMessage());
        }
    });
}
