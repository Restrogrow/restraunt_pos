<?php
/**
 * What ChatGPT / Claude can do with a connected restaurant (main/mcp/server.php).
 *
 * Every tool works on the one restaurant the token belongs to. Numbers come from the same SQL as the AI manager
 * (config/owner_manager.php); changes go through the same rules as the dashboard (order state machine,
 * restaurant-scoped updates, activity log). Read tools need the "read" scope, changes need "write"; with an API key
 * each tool needs its own permission instead (API_TOOL_PERMS in config/api_keys.php). The REST API (api/v1) runs these too.
 * Reports follow Super Admin › Restaurants › Access (reportsPage); menu and orders are always on.
 */
require_once __DIR__ . '/../config/owner_manager.php';
require_once __DIR__ . '/../config/access_control.php';
require_once __DIR__ . '/../config/activity_log.php';

const MCP_ORDER_STATUSES = ['Pending', 'Accepted', 'Preparing', 'Ready', 'Served', 'Completed', 'Cancelled', 'Rejected'];
const MCP_DISH_TYPES = ['Veg', 'Non Veg', 'Egg', 'Drink', 'Dessert', 'Other'];   // the dashboard's own list
const MCP_REPORTS = ['top_dishes', 'slow_dishes', 'dish', 'peak_hours', 'payments', 'channels', 'customers', 'cancellations', 'lapsed', 'today_so_far', 'advice'];

class McpToolError extends Exception {}

/** The tool list for tools/list. */
function mcpToolDefs(): array
{
    $period = ['type' => 'string', 'enum' => OM_PERIODS, 'description' => 'Time range, in the restaurant\'s own timezone.'];
    $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    $rw = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    $t = fn(string $name, string $title, string $desc, array $props, array $req, array $ann, string $scope, string $busy, string $done) => [
        'name' => $name, 'title' => $title, 'description' => $desc,
        'inputSchema' => ['type' => 'object', 'properties' => (object)$props] + ($req ? ['required' => $req] : []) + ['additionalProperties' => false],
        'annotations' => $ann + ['title' => $title],
        'securitySchemes' => [['type' => 'oauth2', 'scopes' => [$scope]]],
        '_meta' => ['securitySchemes' => [['type' => 'oauth2', 'scopes' => [$scope]]], 'openai/toolInvocation/invoking' => $busy, 'openai/toolInvocation/invoked' => $done],
    ];
    return [
        $t('get_restaurant', 'Restaurant details', 'Name, currency, timezone and menu sections of the connected restaurant. Call this first if you need the currency or the menu section names.',
            [], [], $ro, 'read', 'Opening your restaurant…', 'Restaurant loaded'),
        $t('get_sales_summary', 'Sales summary', 'Sales (money), number of orders, average bill and orders by channel (counter, website, WhatsApp) for a period, compared with the period before. Use for "how much did I sell", "sales today", "is this week better than last week".',
            ['period' => $period + ['default' => 'today']], [], $ro, 'read', 'Adding up sales…', 'Sales ready'),
        $t('get_report', 'Business report', 'A ready-made report from the restaurant\'s own data: top_dishes (best sellers), slow_dishes (least sold), dish (how much one named dish sold; set dish), peak_hours (busiest hours and day), payments (cash / UPI / card split), channels (counter vs website vs WhatsApp), customers (new vs returning), cancellations, lapsed (regulars who stopped coming), today_so_far, advice (what to do next, from the numbers).',
            ['report' => ['type' => 'string', 'enum' => MCP_REPORTS], 'period' => $period + ['default' => 'last_7_days'], 'dish' => ['type' => 'string', 'description' => 'Dish name, only for report=dish.']],
            ['report'], $ro, 'read', 'Preparing the report…', 'Report ready'),
        $t('list_orders', 'List orders', 'Orders in a period, newest first. status "active" = everything still open (Pending, Accepted, Preparing, Ready).',
            ['period' => $period + ['default' => 'today'], 'status' => ['type' => 'string', 'enum' => array_merge(['active'], MCP_ORDER_STATUSES)], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20]],
            [], $ro, 'read', 'Fetching orders…', 'Orders loaded'),
        $t('get_order', 'Order details', 'One order with its dishes, customer, payment and the statuses it can move to next. Give order_id, or the order_number shown on the bill.',
            ['order_id' => ['type' => 'integer'], 'order_number' => ['type' => 'string']], [], $ro, 'read', 'Opening the order…', 'Order loaded'),
        $t('list_menu', 'Menu', 'Dishes on the menu with id, section, veg type, price, sizes (variations) and whether they are switched on. Use the ids with set_dish_availability and update_dish.',
            ['search' => ['type' => 'string', 'description' => 'Part of a dish name.'], 'section' => ['type' => 'string', 'description' => 'Menu section name, e.g. Starters.'],
             'only_switched_off' => ['type' => 'boolean', 'default' => false], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 300, 'default' => 100]],
            [], $ro, 'read', 'Reading the menu…', 'Menu loaded'),
        $t('set_dish_availability', 'Switch dishes on or off', 'Switch one or more dishes on (available to order) or off (sold out / not today). Get the ids from list_menu.',
            ['item_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1, 'maxItems' => 50], 'available' => ['type' => 'boolean']],
            ['item_ids', 'available'], $rw, 'write', 'Updating the menu…', 'Menu updated'),
        $t('update_dish', 'Edit a dish', 'Change a dish\'s name, description, price, veg type or the prices of its sizes. Only the fields you pass change. A dish with sizes has its prices in sizes (e.g. Half / Full), not in price.',
            ['item_id' => ['type' => 'integer'], 'name' => ['type' => 'string', 'maxLength' => 150], 'description' => ['type' => 'string', 'maxLength' => 1000],
             'price' => ['type' => 'number', 'minimum' => 0], 'type' => ['type' => 'string', 'enum' => MCP_DISH_TYPES],
             'sizes' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'price' => ['type' => 'number', 'minimum' => 0]], 'required' => ['name', 'price']]]],
            ['item_id'], $rw, 'write', 'Saving the dish…', 'Dish saved'),
        $t('add_dish', 'Add a dish', 'Add a new dish to the menu. The section (e.g. Starters, Main Course) is created if it does not exist yet. Photos and sizes are added later from the RestroGrow dashboard.',
            ['name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 150], 'price' => ['type' => 'number', 'minimum' => 0], 'section' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
             'type' => ['type' => 'string', 'enum' => MCP_DISH_TYPES, 'default' => 'Veg'], 'description' => ['type' => 'string', 'maxLength' => 1000],
             'prep_minutes' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 180], 'available' => ['type' => 'boolean', 'default' => true]],
            ['name', 'price', 'section'], ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false], 'write', 'Adding the dish…', 'Dish added'),
        $t('update_order_status', 'Update an order', 'Move an order to its next status: Accepted, Preparing, Ready, Served, Completed, or Cancelled / Rejected (give a reason, the customer sees it). Only allowed moves work; get_order lists them. Cancelling or rejecting cannot be undone.',
            ['order_id' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => array_values(array_diff(MCP_ORDER_STATUSES, ['Pending']))],
             'reason' => ['type' => 'string', 'maxLength' => 200], 'prep_minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 300, 'description' => 'When accepting: how long the kitchen needs.']],
            ['order_id', 'status'], ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false], 'write', 'Updating the order…', 'Order updated'),
    ];
}

/** Run a tool → [text for the model, structured data]. Throws McpToolError with a message for the owner. */
function mcpRunTool(PDO $conn, array $ctx, string $name, array $a): array
{
    $rid = $ctx['rid'];
    $s = $ctx['settings'];
    $defs = array_column(mcpToolDefs(), null, 'name');
    if (!isset($defs[$name])) throw new McpToolError("Unknown tool: $name");
    if (isset($ctx['perms'])) {
        // an API key (config/api_keys.php): the owner ticked what it may do
        require_once __DIR__ . '/../config/api_keys.php';
        $perm = API_TOOL_PERMS[$name] ?? '';
        if (!in_array($perm, $ctx['perms'], true)) throw new McpToolError("This API key has no \"$perm\" permission. The owner can make a key with it in the RestroGrow dashboard › API keys.");
    } else {
        $scope = $defs[$name]['securitySchemes'][0]['scopes'][0];
        if (!in_array($scope, $ctx['scopes'], true)) throw new McpToolError('RestroGrow was connected without permission to make changes. Disconnect and connect again, then press Allow.');
    }
    $m = fn($n) => ownerMgrMoney($s, (float)$n);
    $period = in_array($a['period'] ?? '', OM_PERIODS, true) ? $a['period'] : null;
    $reports = function () use ($conn, $rid) {
        if (!accessPageOn($conn, $rid, 'reportsPage')) throw new McpToolError('Reports are switched off for this restaurant. Ask RestroGrow support to switch them on.');
    };

    switch ($name) {
        case 'get_restaurant':
            $st = $conn->prepare('SELECT m.id, m.menu_name name, COUNT(mi.id) dishes FROM menu m LEFT JOIN menu_items mi ON mi.menu_id = m.id AND mi.restaurant_id = m.restaurant_id
                WHERE m.restaurant_id = ? GROUP BY m.id, m.menu_name ORDER BY m.id');
            $st->execute([$rid]);
            $sections = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'dishes' => (int)$r['dishes']], $st->fetchAll(PDO::FETCH_ASSOC));
            $data = ['restaurant' => $s['restaurant'], 'currency' => $s['currency'], 'timezone' => $s['tz'], 'menu_sections' => $sections];
            return [$s['restaurant'] . ' · currency ' . $s['currency'] . ' · ' . count($sections) . ' menu sections: ' . (implode(', ', array_map(fn($x) => $x['name'] . ' (' . $x['dishes'] . ')', $sections)) ?: 'none yet'), $data];

        case 'get_sales_summary':
            $reports();
            $period ??= 'today';
            [$from, $to, $label] = ownerMgrPeriod($s, $period);
            [$pf, $pt, $pl] = ownerMgrPrevPeriod($s, $period);
            $x = ownerMgrSales($conn, $rid, $from, $to);
            $p = ownerMgrSales($conn, $rid, $pf, $pt);
            $chg = $p['amount'] > 0 ? (int)round(($x['amount'] - $p['amount']) / $p['amount'] * 100) : null;
            $data = ['period' => $label, 'currency' => $s['currency'], 'sales' => $x['amount'], 'orders' => $x['orders'], 'average_bill' => $x['avg'],
                'counter_orders' => $x['pos'], 'website_orders' => $x['web'], 'whatsapp_orders' => $x['wa'],
                'compared_with' => ['period' => $pl, 'sales' => $p['amount'], 'orders' => $p['orders']], 'change_percent' => $chg];
            return [ucfirst($label) . ': ' . $m($x['amount']) . ' from ' . $x['orders'] . ' orders' . ($x['orders'] ? ' (average bill ' . $m($x['avg']) . ')' : '')
                . '. Counter ' . $x['pos'] . ', website ' . $x['web'] . ', WhatsApp ' . $x['wa'] . '.'
                . ($chg !== null ? ' ' . ($chg >= 0 ? '+' : '') . $chg . '% vs ' . $pl . ' (' . $m($p['amount']) . ').' : ''), $data];

        case 'get_report':
            $reports();
            $r = (string)($a['report'] ?? '');
            if (!in_array($r, MCP_REPORTS, true)) throw new McpToolError('Pick a report: ' . implode(', ', MCP_REPORTS));
            $text = ownerMgrAnswer($conn, $rid, $r === 'today_so_far' ? 'report' : $r, $period ?? 'last_7_days', ['dish' => mb_substr(trim((string)($a['dish'] ?? '')), 0, 60)]);
            return [$text, ['report' => $r, 'period' => $period ?? 'last_7_days', 'text' => $text]];

        case 'list_orders':
            $period ??= 'today';
            [$from, $to, $label] = ownerMgrPeriod($s, $period);
            $limit = max(1, min(50, (int)($a['limit'] ?? 20)));
            $status = (string)($a['status'] ?? '');
            $where = 'o.restaurant_id = ? AND o.deleted_at IS NULL AND o.created_at >= ? AND o.created_at < ?';
            $params = [$rid, $from, $to];
            if ($status === 'active') $where .= " AND o.order_status IN ('Pending','Accepted','Preparing','Ready')";
            elseif (in_array($status, MCP_ORDER_STATUSES, true)) { $where .= ' AND o.order_status = ?'; $params[] = $status; }
            $st = $conn->prepare("SELECT o.id, o.order_number, o.customer_name, o.order_type, o.source, o.total, o.order_status, o.payment_status, o.payment_method, o.created_at
                FROM orders o WHERE $where ORDER BY o.created_at DESC LIMIT $limit");
            $st->execute($params);
            $rows = array_map(fn($r) => ['order_id' => (int)$r['id'], 'order_number' => $r['order_number'], 'customer' => $r['customer_name'], 'type' => $r['order_type'],
                'source' => $r['source'], 'total' => (float)$r['total'], 'status' => $r['order_status'], 'payment' => $r['payment_status'], 'payment_method' => $r['payment_method'], 'time' => $r['created_at']],
                $st->fetchAll(PDO::FETCH_ASSOC));
            $text = $rows ? count($rows) . ' order(s) ' . $label . ":\n" . implode("\n", array_map(fn($o) => '#' . $o['order_number'] . ' (id ' . $o['order_id'] . ') · ' . $o['status'] . ' · ' . $m($o['total']) . ' · ' . ($o['customer'] ?: 'walk-in') . ' · ' . substr((string)$o['time'], 11, 5), $rows))
                : 'No ' . ($status !== '' ? $status . ' ' : '') . 'orders ' . $label . '.';
            return [$text, ['period' => $label, 'currency' => $s['currency'], 'orders' => $rows]];

        case 'get_order':
            $o = mcpFindOrder($conn, $rid, $a);
            $it = $conn->prepare('SELECT item_name, quantity, total_price FROM order_items WHERE order_id = ?');
            $it->execute([$o['id']]);
            $items = array_map(fn($r) => ['dish' => $r['item_name'], 'qty' => (int)$r['quantity'], 'amount' => (float)$r['total_price']], $it->fetchAll(PDO::FETCH_ASSOC));
            require_once __DIR__ . '/../config/order_state_machine.php';
            $next = getNextOrderStatuses((string)$o['order_status']);
            $data = ['order_id' => (int)$o['id'], 'order_number' => $o['order_number'], 'status' => $o['order_status'], 'can_move_to' => $next,
                'type' => $o['order_type'], 'source' => $o['source'], 'customer' => $o['customer_name'], 'phone' => $o['customer_phone'],
                'total' => (float)$o['total'], 'payment' => $o['payment_status'], 'payment_method' => $o['payment_method'], 'notes' => $o['notes'], 'time' => $o['created_at'], 'items' => $items];
            return ['Order #' . $o['order_number'] . ' · ' . $o['order_status'] . ' · ' . $m($o['total']) . ' · ' . ($o['customer_name'] ?: 'walk-in') . "\n"
                . implode("\n", array_map(fn($i) => '• ' . $i['qty'] . ' × ' . $i['dish'] . ' (' . $m($i['amount']) . ')', $items))
                . "\nCan move to: " . ($next ? implode(', ', $next) : 'nothing (closed)'), $data];

        case 'list_menu':
            $limit = max(1, min(300, (int)($a['limit'] ?? 100)));
            $where = 'mi.restaurant_id = ?';
            $params = [$rid];
            foreach (preg_split('/\s+/u', trim((string)($a['search'] ?? ''))) as $w) if (mb_strlen($w) >= 2) { $where .= ' AND mi.item_name_en LIKE ?'; $params[] = '%' . $w . '%'; }
            if (trim((string)($a['section'] ?? '')) !== '') { $where .= ' AND m.menu_name LIKE ?'; $params[] = '%' . trim((string)$a['section']) . '%'; }
            if (!empty($a['only_switched_off'])) $where .= ' AND mi.is_available = 0';
            $st = $conn->prepare("SELECT mi.id, mi.item_name_en, m.menu_name, mi.item_type, mi.base_price, mi.has_variations, mi.is_available
                FROM menu_items mi LEFT JOIN menu m ON m.id = mi.menu_id WHERE $where ORDER BY m.id, mi.id LIMIT $limit");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $sizes = [];
            $withSizes = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => (int)$r['has_variations'] === 1));
            if ($withSizes) {
                $v = $conn->prepare('SELECT menu_item_id, variation_name, price FROM menu_item_variations WHERE menu_item_id IN (' . implode(',', $withSizes) . ') ORDER BY sort_order, id');
                $v->execute();
                foreach ($v->fetchAll(PDO::FETCH_ASSOC) as $x) $sizes[(int)$x['menu_item_id']][] = ['name' => $x['variation_name'], 'price' => (float)$x['price']];
            }
            $dishes = array_map(fn($r) => ['item_id' => (int)$r['id'], 'name' => $r['item_name_en'], 'section' => $r['menu_name'], 'type' => $r['item_type'],
                'price' => (float)$r['base_price'], 'sizes' => $sizes[(int)$r['id']] ?? [], 'switched_on' => (int)$r['is_available'] === 1], $rows);
            $text = $dishes ? count($dishes) . " dish(es):\n" . implode("\n", array_map(fn($d) => $d['item_id'] . '. ' . $d['name'] . ' [' . $d['section'] . ', ' . $d['type'] . '] '
                    . ($d['sizes'] ? implode(' / ', array_map(fn($z) => $z['name'] . ' ' . $m($z['price']), $d['sizes'])) : $m($d['price'])) . ($d['switched_on'] ? '' : ' (OFF)'), $dishes))
                : 'No dishes found.';
            return [$text, ['currency' => $s['currency'], 'dishes' => $dishes]];

        case 'set_dish_availability':
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($a['item_ids'] ?? [])), fn($i) => $i > 0)));
            if (!$ids || count($ids) > 50) throw new McpToolError('Give 1 to 50 dish ids (from list_menu).');
            $on = !empty($a['available']) ? 1 : 0;
            $in = implode(',', array_fill(0, count($ids), '?'));
            $names = $conn->prepare("SELECT id, item_name_en FROM menu_items WHERE restaurant_id = ? AND id IN ($in)");
            $names->execute(array_merge([$rid], $ids));
            $found = $names->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!$found) throw new McpToolError('None of those dishes are on this menu.');
            $conn->prepare("UPDATE menu_items SET is_available = ?, updated_at = NOW() WHERE restaurant_id = ? AND id IN ($in)")->execute(array_merge([$on, $rid], $ids));
            $list = implode(', ', $found);
            logActivity($conn, $rid, 'menu.availability', ($on ? 'Switched on: ' : 'Switched off: ') . mb_substr($list, 0, 300), ['ids' => array_keys($found), 'via' => $ctx['app']], 'menu_item', null, mcpActor($ctx));
            $missing = array_values(array_diff($ids, array_keys($found)));
            return [($on ? '✅ Switched on: ' : '⛔ Switched off: ') . $list . '.' . ($missing ? ' Not found: ' . implode(', ', $missing) . '.' : ''), ['switched_on' => (bool)$on, 'dishes' => $found, 'not_found' => $missing]];

        case 'update_dish':
            $id = (int)($a['item_id'] ?? 0);
            $st = $conn->prepare('SELECT id, item_name_en, base_price, has_variations FROM menu_items WHERE id = ? AND restaurant_id = ?');
            $st->execute([$id, $rid]);
            $d = $st->fetch(PDO::FETCH_ASSOC);
            if (!$d) throw new McpToolError('That dish is not on this menu. Use list_menu to find its id.');
            $set = [];
            $vals = [];
            $said = [];
            if (isset($a['name']) && trim((string)$a['name']) !== '') { $set[] = 'item_name_en = ?'; $vals[] = mb_substr(trim((string)$a['name']), 0, 150); $said[] = 'name → ' . end($vals); }
            if (isset($a['description'])) { $set[] = 'item_description_en = ?'; $vals[] = mb_substr(trim((string)$a['description']), 0, 1000); $said[] = 'description'; }
            if (isset($a['type'])) {
                if (!in_array($a['type'], MCP_DISH_TYPES, true)) throw new McpToolError('type must be one of: ' . implode(', ', MCP_DISH_TYPES) . '.');
                $set[] = 'item_type = ?'; $vals[] = $a['type']; $said[] = 'type → ' . $a['type'];
            }
            if (isset($a['price'])) {
                if ((int)$d['has_variations'] === 1) throw new McpToolError($d['item_name_en'] . ' has sizes: change their prices with sizes, e.g. [{"name":"Half","price":180}].');
                $price = mcpPrice($a['price']);
                $set[] = 'base_price = ?'; $vals[] = $price; $said[] = 'price ' . $m($d['base_price']) . ' → ' . $m($price);
            }
            $sizeChanges = [];
            if (!empty($a['sizes'])) {
                $vs = $conn->prepare('SELECT id, variation_name, price FROM menu_item_variations WHERE menu_item_id = ?');
                $vs->execute([$id]);
                $byName = [];
                foreach ($vs->fetchAll(PDO::FETCH_ASSOC) as $v) $byName[mb_strtolower(trim($v['variation_name']))] = $v;
                foreach ((array)$a['sizes'] as $z) {
                    $v = $byName[mb_strtolower(trim((string)($z['name'] ?? '')))] ?? null;
                    if (!$v) throw new McpToolError('Size "' . ($z['name'] ?? '') . '" not found. Sizes: ' . (implode(', ', array_column($byName, 'variation_name')) ?: 'none'));
                    $sizeChanges[] = [(int)$v['id'], mcpPrice($z['price'] ?? null), $v['variation_name'], (float)$v['price']];
                }
            }
            if (!$set && !$sizeChanges) throw new McpToolError('Nothing to change: pass name, description, price, type or sizes.');
            $conn->beginTransaction();
            if ($set) $conn->prepare('UPDATE menu_items SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = ? AND restaurant_id = ?')->execute(array_merge($vals, [$id, $rid]));
            $up = $conn->prepare('UPDATE menu_item_variations SET price = ? WHERE id = ? AND menu_item_id = ?');
            foreach ($sizeChanges as [$vid, $price, $vn, $old]) { $up->execute([$price, $vid, $id]); $said[] = $vn . ' ' . $m($old) . ' → ' . $m($price); }
            if ($sizeChanges) $conn->prepare('UPDATE menu_items SET base_price = (SELECT MIN(price) FROM menu_item_variations WHERE menu_item_id = ?) WHERE id = ? AND restaurant_id = ?')->execute([$id, $id, $rid]);
            $conn->commit();
            logActivity($conn, $rid, 'menu.update', 'Edited ' . $d['item_name_en'] . ': ' . mb_substr(implode(', ', $said), 0, 300), ['via' => $ctx['app']], 'menu_item', $id, mcpActor($ctx));
            return ['✅ ' . $d['item_name_en'] . ' updated: ' . implode(', ', $said) . '.', ['item_id' => $id, 'changes' => $said]];

        case 'add_dish':
            $dish = mb_substr(trim((string)($a['name'] ?? '')), 0, 150);
            $section = mb_substr(trim((string)($a['section'] ?? '')), 0, 80);
            if (mb_strlen($dish) < 2 || mb_strlen($section) < 2) throw new McpToolError('A dish needs a name and a menu section.');
            $price = mcpPrice($a['price'] ?? null);
            $type = in_array($a['type'] ?? '', MCP_DISH_TYPES, true) ? $a['type'] : 'Veg';
            $ms = $conn->prepare('SELECT id, menu_name FROM menu WHERE restaurant_id = ? AND LOWER(TRIM(menu_name)) = LOWER(?) LIMIT 1');
            $ms->execute([$rid, $section]);
            $menu = $ms->fetch(PDO::FETCH_ASSOC);
            $newSection = false;
            if ($menu) {
                $dup = $conn->prepare('SELECT id FROM menu_items WHERE restaurant_id = ? AND menu_id = ? AND LOWER(TRIM(item_name_en)) = LOWER(?) LIMIT 1');
                $dup->execute([$rid, $menu['id'], $dish]);
                if ($dupId = $dup->fetchColumn()) throw new McpToolError("$dish is already in {$menu['menu_name']} (item id $dupId). Use update_dish to change it.");
            } else {
                $conn->prepare('INSERT INTO menu (restaurant_id, menu_name, created_at, updated_at) VALUES (?, ?, NOW(), NOW())')->execute([$rid, $section]);
                $menu = ['id' => (int)$conn->lastInsertId(), 'menu_name' => $section];
                $newSection = true;
            }
            $conn->prepare('INSERT INTO menu_items (restaurant_id, menu_id, item_name_en, item_description_en, item_category, item_type, preparation_time, is_available, base_price, has_variations, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())')
                ->execute([$rid, $menu['id'], $dish, mb_substr(trim((string)($a['description'] ?? '')), 0, 1000), $menu['menu_name'], $type,
                    max(0, min(180, (int)($a['prep_minutes'] ?? 0))), array_key_exists('available', $a) && !$a['available'] ? 0 : 1, $price]);
            $newId = (int)$conn->lastInsertId();
            logActivity($conn, $rid, 'menu.add', 'Added ' . $dish . ' (' . $m($price) . ') to ' . $menu['menu_name'], ['via' => $ctx['app']], 'menu_item', $newId, mcpActor($ctx));
            return ['✅ Added ' . $dish . ' (' . $type . ', ' . $m($price) . ') to ' . $menu['menu_name'] . ($newSection ? ' (new section)' : '') . '. Add a photo from the RestroGrow dashboard › Menu.',
                ['item_id' => $newId, 'section' => $menu['menu_name'], 'new_section' => $newSection]];

        case 'update_order_status':
            return mcpUpdateOrderStatus($conn, $ctx, $a);
    }
    throw new McpToolError("Unknown tool: $name");
}

function mcpPrice($v): float
{
    if (!is_numeric($v) || (float)$v < 0 || (float)$v > 1000000) throw new McpToolError('Price must be a number from 0 to 1,000,000.');
    return round((float)$v, 2);
}

/** Who made a change, for the owner's activity log: "ChatGPT (owner)". */
function mcpActor(array $ctx): array
{
    return ['name' => $ctx['app'], 'role' => isset($ctx['perms']) ? 'API key' : 'AI app', 'staff_id' => null];
}

function mcpFindOrder(PDO $conn, string $rid, array $a): array
{
    $cols = 'id, order_number, customer_name, customer_phone, order_type, source, total, order_status, payment_status, payment_method, notes, created_at';
    if (!empty($a['order_id'])) {
        $st = $conn->prepare("SELECT $cols FROM orders WHERE id = ? AND restaurant_id = ? AND deleted_at IS NULL");
        $st->execute([(int)$a['order_id'], $rid]);
    } else {
        $num = ltrim(trim((string)($a['order_number'] ?? '')), '#');
        if ($num === '') throw new McpToolError('Give order_id or order_number.');
        $st = $conn->prepare("SELECT $cols FROM orders WHERE order_number = ? AND restaurant_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
        $st->execute([$num, $rid]);
    }
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) throw new McpToolError('Order not found. Use list_orders to see recent orders.');
    return $o;
}

/** Same rules as api/update_order_status.php: state machine, row lock, deleted orders closed, reason in the notes. */
function mcpUpdateOrderStatus(PDO $conn, array $ctx, array $a): array
{
    require_once __DIR__ . '/../config/order_state_machine.php';
    $rid = $ctx['rid'];
    $orderId = (int)($a['order_id'] ?? 0);
    $status = (string)($a['status'] ?? '');
    if (!in_array($status, MCP_ORDER_STATUSES, true) || $status === 'Pending') throw new McpToolError('Status must be one of: Accepted, Preparing, Ready, Served, Completed, Cancelled, Rejected.');
    $reason = trim(mb_substr((string)($a['reason'] ?? ''), 0, 200));
    if (in_array($status, ['Cancelled', 'Rejected'], true) && $reason === '') throw new McpToolError('Give a reason for cancelling or rejecting; the customer sees it.');
    $prep = isset($a['prep_minutes']) ? max(1, min(300, (int)$a['prep_minutes'])) : null;
    $extraSet = [];
    $extraParams = [];
    if ($status === 'Accepted' && $prep) {
        $extraSet = ['prep_minutes = ?', 'estimated_ready_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)'];
        $extraParams = [$prep, $prep];
    }
    $notes = $reason !== '' && in_array($status, ['Cancelled', 'Rejected'], true) ? '[' . $status . ': ' . str_replace(['[', ']'], ['(', ')'], $reason) . ']' : null;

    $conn->beginTransaction();
    try {
        $chk = $conn->prepare('SELECT deleted_at, order_number, total FROM orders WHERE id = ? AND restaurant_id = ? FOR UPDATE');
        $chk->execute([$orderId, $rid]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new McpToolError('Order not found. Use list_orders to see recent orders.');
        if (!empty($row['deleted_at'])) throw new McpToolError('This order is deleted and can no longer be updated.');
        $res = validateAndUpdateOrderStatus($conn, $orderId, $status, $extraSet, $extraParams, $rid, $notes);
        if (!$res['success']) throw new McpToolError($res['message']);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    if ($status === 'Cancelled' || $status === 'Rejected') {
        logActivity($conn, $rid, $status === 'Cancelled' ? 'order.cancel' : 'order.reject', $status . ' order ' . ($row['order_number'] ?? '#' . $orderId) . ': ' . $reason,
            ['reason' => $reason, 'total' => $row['total'] ?? null, 'via' => $ctx['app']], 'order', $orderId, mcpActor($ctx));
    }
    if ($status === 'Ready') {
        try {
            require_once __DIR__ . '/../config/push_notification.php';
            notifyWaitersOrderReady($conn, $rid, $orderId);
        } catch (Throwable $e) { error_log('mcp: waiter notification failed: ' . $e->getMessage()); }
    }
    return ['✅ Order #' . $row['order_number'] . ' is now ' . $status . ($prep ? " (ready in about $prep minutes)" : '') . '.', ['order_id' => $orderId, 'order_number' => $row['order_number'], 'status' => $status]];
}
