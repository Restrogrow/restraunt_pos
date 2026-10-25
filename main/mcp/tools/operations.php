<?php
/**
 * Operations for the API and AI apps (mcp/tools.php loads this): tables and areas, waiter calls, reservations, staff,
 * stock, delivery zones and catering. Same tables and checks as the dashboard (api/tables_studio.php, areas_studio.php,
 * controllers/reservation_operations.php, staff_operations.php, api/staff_overview.php, api/inventory_studio.php,
 * api/save_delivery_zone.php, controllers/catering_operations.php, waiter_request_operations.php).
 * Safety beyond the dashboard: a busy table is never deleted, an area with tables needs move_to, reservations refuse a
 * table already booked for that slot, staff can't be made Admin and passwords are never returned or changed.
 */

const OP_RES_STATUSES = ['Pending', 'Confirmed', 'Checked In', 'Completed', 'Cancelled', 'No Show'];
const OP_STAFF_ROLES = ['Manager', 'Waiter', 'Chef', 'Bartender', 'Cashier', 'Delivery'];   // Admin only from the dashboard
const OP_UNITS = ['kg', 'g', 'L', 'ml', 'pcs', 'dozen', 'box', 'packet', 'bottle', 'can', 'tray', 'bunch', 'unit'];
const OP_CATERING_STATUSES = ['new', 'contacted', 'confirmed', 'completed', 'cancelled'];

function opsTools(): array
{
    $t = fn(array $def, string $perm, string $page, array $route) => ['def' => $def, 'perm' => $perm, 'page' => $page, 'route' => $route];
    $date = ['type' => 'string', 'description' => 'YYYY-MM-DD'];
    return [
        // tables & areas
        $t(mcpDef('list_tables', 'Tables', 'Areas and their tables with seats and live status: busy (open order), reserved (confirmed booking today), free or off.',
            [], [], 'read', 'Reading tables…', 'Tables loaded'), 'tables:read', 'tablesPage', ['GET', 'tables', 'Tables']),
        $t(mcpDef('save_table', 'Add or edit a table', 'Add a table (no table_id: number and area_id) or change one. Table names are unique in the restaurant; renaming a table makes its old QR code stop working.',
            ['table_id' => ['type' => 'integer'], 'number' => ['type' => 'string', 'maxLength' => 50, 'description' => 'Table name, e.g. T7'], 'area_id' => ['type' => 'integer'],
             'seats' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], 'available' => ['type' => 'boolean', 'description' => 'false = off (its QR takes no orders).']],
            [], 'create', 'Saving the table…', 'Table saved'), 'tables:write', 'tablesPage', ['POST', 'tables', 'Tables']),
        $t(mcpDef('delete_table', 'Delete a table', 'Delete a table for good. A table with an open order or a ticket in the kitchen is never deleted.',
            ['table_id' => ['type' => 'integer']], ['table_id'], 'delete', 'Deleting the table…', 'Table deleted'), 'tables:write', 'tablesPage', ['POST', 'tables/{table_id}/delete', 'Tables']),
        $t(mcpDef('save_area', 'Add or edit an area', 'Add an area (e.g. Rooftop, AC Hall) or rename one. color like #22c55e.',
            ['area_id' => ['type' => 'integer'], 'name' => ['type' => 'string', 'maxLength' => 100], 'color' => ['type' => 'string'], 'note' => ['type' => 'string', 'maxLength' => 120]],
            ['name'], 'create', 'Saving the area…', 'Area saved'), 'tables:write', 'areaPage', ['POST', 'tables/areas', 'Tables']),
        $t(mcpDef('delete_area', 'Delete an area', 'Delete an area. If it has tables, give move_to (another area id): its tables move there (clashing names get the old area\'s initial, e.g. R-T1).',
            ['area_id' => ['type' => 'integer'], 'move_to' => ['type' => 'integer']], ['area_id'], 'delete', 'Deleting the area…', 'Area deleted'), 'tables:write', 'areaPage', ['POST', 'tables/areas/{area_id}/delete', 'Tables']),
        $t(mcpDef('list_waiter_calls', 'Waiter calls', 'Open waiter calls from tables (oldest first) and those answered today.',
            [], [], 'read', 'Reading waiter calls…', 'Waiter calls loaded'), 'tables:read', 'waiterRequestsPage', ['GET', 'tables/waiter-calls', 'Tables']),
        $t(mcpDef('answer_waiter_call', 'Mark a waiter call answered', 'Mark a waiter call as attended.',
            ['call_id' => ['type' => 'integer']], ['call_id'], 'write', 'Updating…', 'Call answered'), 'tables:write', 'waiterRequestsPage', ['POST', 'tables/waiter-calls/{call_id}/answer', 'Tables']),
        // reservations
        $t(mcpDef('list_reservations', 'Reservations', 'Table bookings for a day (default today) or a date range, optionally one status.',
            ['date' => $date, 'from' => $date, 'to' => $date, 'status' => ['type' => 'string', 'enum' => OP_RES_STATUSES]], [], 'read', 'Reading bookings…', 'Bookings loaded'), 'reservations:read', 'reservationsPage', ['GET', 'reservations', 'Reservations']),
        $t(mcpDef('add_reservation', 'Add a reservation', 'Book a table. time_slot like "07:30 PM". A table already booked for that date and slot is refused. The guest gets an email if an email is given.',
            ['date' => $date, 'time_slot' => ['type' => 'string', 'maxLength' => 20], 'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100], 'phone' => ['type' => 'string'],
             'email' => ['type' => 'string'], 'guests' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], 'table_id' => ['type' => 'integer'],
             'meal_type' => ['type' => 'string', 'maxLength' => 30, 'default' => 'Dinner'], 'note' => ['type' => 'string', 'maxLength' => 500], 'confirmed' => ['type' => 'boolean', 'default' => true]],
            ['date', 'time_slot', 'name', 'guests'], 'create', 'Booking…', 'Booked'), 'reservations:write', 'reservationsPage', ['POST', 'reservations', 'Reservations']),
        $t(mcpDef('update_reservation', 'Update a reservation', 'Change a booking\'s status: Confirmed (approve), Checked In (seated), Completed, Cancelled (decline), No Show, or Pending. Confirming or cancelling emails the guest if they gave an email.',
            ['reservation_id' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => OP_RES_STATUSES]], ['reservation_id', 'status'], 'write', 'Updating the booking…', 'Booking updated'),
            'reservations:write', 'reservationsPage', ['POST', 'reservations/{reservation_id}/status', 'Reservations']),
        // staff
        $t(mcpDef('list_staff', 'Staff', 'Staff members with role, email, phone and whether they can log in. Never passwords.',
            [], [], 'read', 'Reading staff…', 'Staff loaded'), 'staff:read', 'staffPage', ['GET', 'staff', 'Staff']),
        $t(mcpDef('add_staff', 'Add a staff member', 'Add a staff login. role: ' . implode(', ', OP_STAFF_ROLES) . ' (Admin only from the dashboard). They log in with email and the password given (6+ characters); tell them to change it.',
            ['name' => ['type' => 'string', 'maxLength' => 100], 'email' => ['type' => 'string'], 'phone' => ['type' => 'string', 'description' => 'With country code, e.g. +91-9876543210'],
             'role' => ['type' => 'string', 'enum' => OP_STAFF_ROLES], 'password' => ['type' => 'string', 'minLength' => 6]],
            ['name', 'email', 'phone', 'role', 'password'], 'create', 'Adding staff…', 'Staff added'), 'staff:write', 'staffPage', ['POST', 'staff', 'Staff']),
        $t(mcpDef('update_staff', 'Change a staff member', 'Change name, phone or role, or pause / resume their login (active). Passwords are changed only in the dashboard.',
            ['staff_id' => ['type' => 'integer'], 'name' => ['type' => 'string', 'maxLength' => 100], 'phone' => ['type' => 'string'], 'role' => ['type' => 'string', 'enum' => OP_STAFF_ROLES], 'active' => ['type' => 'boolean']],
            ['staff_id'], 'write', 'Saving…', 'Staff saved'), 'staff:write', 'staffPage', ['PATCH', 'staff/{staff_id}', 'Staff']),
        $t(mcpDef('remove_staff', 'Remove a staff member', 'Delete a staff login for good (pausing with update_staff active=false keeps it).',
            ['staff_id' => ['type' => 'integer']], ['staff_id'], 'delete', 'Removing…', 'Staff removed'), 'staff:write', 'staffPage', ['POST', 'staff/{staff_id}/delete', 'Staff']),
        // stock
        $t(mcpDef('list_stock', 'Stock', 'Stock items with quantity, unit, low-stock level, cost and status (ok, low, out).',
            ['only_low' => ['type' => 'boolean', 'default' => false]], [], 'read', 'Reading stock…', 'Stock loaded'), 'stock:read', 'inventoryPage', ['GET', 'stock', 'Stock']),
        $t(mcpDef('save_stock_item', 'Add or edit a stock item', 'Add a stock item (no stock_id: name and unit; opening_stock counts what is on the shelf now) or change one. unit: ' . implode(', ', OP_UNITS) . '.',
            ['stock_id' => ['type' => 'integer'], 'name' => ['type' => 'string', 'maxLength' => 150], 'unit' => ['type' => 'string', 'enum' => OP_UNITS], 'category' => ['type' => 'string', 'maxLength' => 100],
             'low_stock_level' => ['type' => 'number', 'minimum' => 0], 'par_level' => ['type' => 'number', 'minimum' => 0], 'cost_per_unit' => ['type' => 'number', 'minimum' => 0], 'opening_stock' => ['type' => 'number', 'minimum' => 0]],
            [], 'create', 'Saving the item…', 'Item saved'), 'stock:write', 'inventoryPage', ['POST', 'stock', 'Stock']),
        $t(mcpDef('restock', 'Record stock coming in', 'Add stock that came in (a purchase). The cost is recorded as an expense.',
            ['stock_id' => ['type' => 'integer'], 'quantity' => ['type' => 'number', 'exclusiveMinimum' => 0], 'cost_per_unit' => ['type' => 'number', 'minimum' => 0], 'note' => ['type' => 'string', 'maxLength' => 200]],
            ['stock_id', 'quantity'], 'create', 'Adding stock…', 'Stock added'), 'stock:write', 'inventoryPage', ['POST', 'stock/{stock_id}/restock', 'Stock']),
        $t(mcpDef('adjust_stock', 'Correct stock', 'kind wastage: quantity thrown away (recorded as an expense); set: the quantity on hand now; adjustment: + or − correction.',
            ['stock_id' => ['type' => 'integer'], 'kind' => ['type' => 'string', 'enum' => ['wastage', 'set', 'adjustment']], 'quantity' => ['type' => 'number'], 'reason' => ['type' => 'string', 'maxLength' => 200]],
            ['stock_id', 'kind', 'quantity'], 'write', 'Saving…', 'Stock corrected'), 'stock:write', 'inventoryPage', ['POST', 'stock/{stock_id}/adjust', 'Stock']),
        $t(mcpDef('delete_stock_item', 'Remove a stock item', 'Remove a stock item (its history stays in reports; it leaves any recipes).',
            ['stock_id' => ['type' => 'integer']], ['stock_id'], 'delete', 'Removing…', 'Item removed'), 'stock:write', 'inventoryPage', ['POST', 'stock/{stock_id}/delete', 'Stock']),
        // delivery zones
        $t(mcpDef('list_delivery_zones', 'Delivery zones', 'Delivery areas by pincode with delivery charge, estimated minutes and on/off.',
            [], [], 'read', 'Reading zones…', 'Zones loaded'), 'delivery:read', 'deliveryPage', ['GET', 'delivery/zones', 'Delivery']),
        $t(mcpDef('save_delivery_zone', 'Add or edit a delivery zone', 'Add a zone (no zone_id: pincode needed) or change one.',
            ['zone_id' => ['type' => 'integer'], 'pincode' => ['type' => 'string', 'maxLength' => 10], 'name' => ['type' => 'string', 'maxLength' => 100], 'charge' => ['type' => 'number', 'minimum' => 0],
             'minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 300], 'active' => ['type' => 'boolean']],
            [], 'create', 'Saving the zone…', 'Zone saved'), 'delivery:write', 'deliveryPage', ['POST', 'delivery/zones', 'Delivery']),
        $t(mcpDef('delete_delivery_zone', 'Delete a delivery zone', 'Delete a delivery zone (orders from that pincode stop).',
            ['zone_id' => ['type' => 'integer']], ['zone_id'], 'delete', 'Deleting…', 'Zone deleted'), 'delivery:write', 'deliveryPage', ['POST', 'delivery/zones/{zone_id}/delete', 'Delivery']),
        // catering
        $t(mcpDef('list_catering', 'Catering', 'Catering requests (newest first, with contact, event date, guests, quote, status) and the price tiers.',
            ['status' => ['type' => 'string', 'enum' => OP_CATERING_STATUSES]], [], 'read', 'Reading catering…', 'Catering loaded'), 'catering:read', 'cateringPage', ['GET', 'catering', 'Catering']),
        $t(mcpDef('update_catering_request', 'Answer a catering request', 'Move a request on: contacted, confirmed (approve), completed or cancelled (decline). Optionally set the quoted price and a note.',
            ['request_id' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => OP_CATERING_STATUSES], 'quoted_price' => ['type' => 'number', 'minimum' => 0], 'note' => ['type' => 'string', 'maxLength' => 1000]],
            ['request_id', 'status'], 'write', 'Updating…', 'Request updated'), 'catering:write', 'cateringPage', ['POST', 'catering/requests/{request_id}', 'Catering']),
        $t(mcpDef('save_catering_tier', 'Add or edit a catering price tier', 'A price per guest for a guest range, e.g. 20–50 guests at 450. No tier_id = new.',
            ['tier_id' => ['type' => 'integer'], 'label' => ['type' => 'string', 'maxLength' => 100], 'min_guests' => ['type' => 'integer', 'minimum' => 1], 'max_guests' => ['type' => 'integer', 'minimum' => 1],
             'price' => ['type' => 'number', 'minimum' => 0], 'active' => ['type' => 'boolean']],
            [], 'create', 'Saving the tier…', 'Tier saved'), 'catering:write', 'cateringPage', ['POST', 'catering/tiers', 'Catering']),
        $t(mcpDef('delete_catering_tier', 'Delete a catering price tier', 'Delete a catering price tier.',
            ['tier_id' => ['type' => 'integer']], ['tier_id'], 'delete', 'Deleting…', 'Tier deleted'), 'catering:write', 'cateringPage', ['POST', 'catering/tiers/{tier_id}/delete', 'Catering']),
    ];
}

function opsRun(PDO $conn, array $ctx, string $name, array $a): array
{
    $rid = $ctx['rid'];
    $m = fn($n) => mcpMoney($ctx, $n);
    $log = fn(string $action, string $summary, $entity = null, $id = null) => logActivity($conn, $rid, $action, $summary, ['via' => $ctx['app']], $entity, $id, mcpActor($ctx));
    $row = function (string $sql, array $args, string $missing) use ($conn): array {
        $st = $conn->prepare($sql);
        $st->execute($args);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new McpToolError($missing);
        return $r;
    };
    $date = function ($v, string $label) {
        $v = trim((string)$v);
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) throw new McpToolError("$label must be a date like 2026-12-31.");
        return $v;
    };

    switch ($name) {
        // ── tables & areas ──
        case 'list_tables':
            opEnsureAreas($conn);
            require_once __DIR__ . '/../../config/table_busy.php';
            $busy = rgBusyTables($conn, $rid);
            $res = $conn->prepare("SELECT table_id, time_slot FROM reservations WHERE restaurant_id = ? AND reservation_date = CURDATE() AND status = 'Confirmed' AND table_id IS NOT NULL");
            $res->execute([$rid]);
            $reserved = [];
            foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $r) $reserved[(int)$r['table_id']] = $r['time_slot'];
            $ar = $conn->prepare('SELECT id, area_name, color, note FROM areas WHERE restaurant_id = ? ORDER BY sort_order, id');
            $ar->execute([$rid]);
            $tb = $conn->prepare('SELECT id, area_id, table_number, capacity, is_available FROM tables WHERE restaurant_id = ? ORDER BY sort_order, id');
            $tb->execute([$rid]);
            $byArea = [];
            foreach ($tb->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $id = (int)$t['id'];
                $status = isset($busy[$id]) ? 'busy' : (isset($reserved[$id]) ? 'reserved' : ((int)$t['is_available'] ? 'free' : 'off'));
                $byArea[(int)$t['area_id']][] = ['table_id' => $id, 'number' => $t['table_number'], 'seats' => (int)$t['capacity'], 'status' => $status,
                    'bill' => isset($busy[$id]) ? (float)$busy[$id]['total'] : null, 'reserved_at' => $reserved[$id] ?? null];
            }
            $areas = array_map(fn($x) => ['area_id' => (int)$x['id'], 'name' => $x['area_name'], 'color' => $x['color'], 'note' => $x['note'], 'tables' => $byArea[(int)$x['id']] ?? []], $ar->fetchAll(PDO::FETCH_ASSOC));
            $all = array_merge(...array_map(fn($x) => $x['tables'], $areas ?: [['tables' => []]]));
            $count = fn($s) => count(array_filter($all, fn($t) => $t['status'] === $s));
            return [count($all) . ' tables: ' . $count('busy') . ' busy, ' . $count('reserved') . ' reserved, ' . $count('free') . ' free, ' . $count('off') . ' off.', ['areas' => $areas]];

        case 'save_table':
            opEnsureAreas($conn);
            $id = (int)($a['table_id'] ?? 0);
            $cur = $id ? $row('SELECT table_number, capacity, area_id, is_available FROM tables WHERE id = ? AND restaurant_id = ?', [$id, $rid], 'That table does not exist. Use list_tables.')
                : ['table_number' => '', 'capacity' => 4, 'area_id' => 0, 'is_available' => 1];
            $number = array_key_exists('number', $a) ? trim(preg_replace('/\s+/', ' ', mb_substr((string)$a['number'], 0, 50))) : $cur['table_number'];
            if ($number === '') throw new McpToolError('Give the table a name, e.g. T7.');
            $area = (int)($a['area_id'] ?? $cur['area_id']);
            $row('SELECT id FROM areas WHERE id = ? AND restaurant_id = ?', [$area, $rid], 'Pick an area (area_id from list_tables).');
            $dup = $conn->prepare('SELECT COUNT(*) FROM tables WHERE restaurant_id = ? AND table_number = ? AND id <> ?');
            $dup->execute([$rid, $number, $id]);
            if ((int)$dup->fetchColumn()) throw new McpToolError("There is already a table called $number.");
            $seats = max(1, min(50, (int)($a['seats'] ?? $cur['capacity'])));
            $avail = array_key_exists('available', $a) ? (!empty($a['available']) ? 1 : 0) : (int)$cur['is_available'];
            if ($id) {
                $conn->prepare('UPDATE tables SET table_number = ?, capacity = ?, area_id = ?, is_available = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?')->execute([$number, $seats, $area, $avail, $id, $rid]);
                $renamed = $number !== $cur['table_number'];
            } else {
                $max = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM tables WHERE area_id = ?');
                $max->execute([$area]);
                $conn->prepare('INSERT INTO tables (restaurant_id, area_id, table_number, capacity, is_available, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())')->execute([$rid, $area, $number, $seats, $avail, (int)$max->fetchColumn()]);
                $id = (int)$conn->lastInsertId();
                $renamed = false;
            }
            $log('table.save', "Saved table $number ($seats seats)", 'table', $id);
            return ["✅ Table $number saved ($seats seats" . ($avail ? '' : ', off') . ').' . ($renamed ? ' Renamed: print its new QR code, the old one no longer works.' : ''), ['table_id' => $id, 'renamed' => $renamed]];

        case 'delete_table':
            $t = $row('SELECT id, table_number FROM tables WHERE id = ? AND restaurant_id = ?', [(int)($a['table_id'] ?? 0), $rid], 'That table does not exist. Use list_tables.');
            require_once __DIR__ . '/../../config/table_busy.php';
            if (isset(rgBusyTables($conn, $rid)[(int)$t['id']])) throw new McpToolError('Table ' . $t['table_number'] . ' has an open order or a ticket in the kitchen. Finish it first.');
            $conn->prepare('DELETE FROM tables WHERE id = ? AND restaurant_id = ?')->execute([$t['id'], $rid]);
            $log('table.delete', 'Deleted table ' . $t['table_number'], 'table', $t['id']);
            return ['🗑️ Deleted table ' . $t['table_number'] . '.', ['table_id' => (int)$t['id'], 'deleted' => true]];

        case 'save_area':
            opEnsureAreas($conn);
            $id = (int)($a['area_id'] ?? 0);
            $nm = trim(mb_substr((string)($a['name'] ?? ''), 0, 100));
            if ($nm === '') throw new McpToolError('Give the area a name.');
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($a['color'] ?? '')) ? $a['color'] : null;
            $note = mb_substr(trim((string)($a['note'] ?? '')), 0, 120) ?: null;
            $dup = $conn->prepare('SELECT COUNT(*) FROM areas WHERE restaurant_id = ? AND area_name = ? AND id <> ?');
            $dup->execute([$rid, $nm, $id]);
            if ((int)$dup->fetchColumn()) throw new McpToolError("You already have an area called $nm.");
            if ($id) {
                $row('SELECT id FROM areas WHERE id = ? AND restaurant_id = ?', [$id, $rid], 'That area does not exist. Use list_tables.');
                $conn->prepare('UPDATE areas SET area_name = ?, color = COALESCE(?, color), note = COALESCE(?, note), updated_at = NOW() WHERE id = ? AND restaurant_id = ?')->execute([$nm, $color, $note, $id, $rid]);
            } else {
                $max = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM areas WHERE restaurant_id = ?');
                $max->execute([$rid]);
                $conn->prepare('INSERT INTO areas (restaurant_id, area_name, color, note, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())')->execute([$rid, $nm, $color, $note, (int)$max->fetchColumn()]);
                $id = (int)$conn->lastInsertId();
            }
            $log('area.save', "Saved area $nm", 'area', $id);
            return ["✅ Area $nm saved.", ['area_id' => $id]];

        case 'delete_area':
            $ar = $row('SELECT id, area_name FROM areas WHERE id = ? AND restaurant_id = ?', [(int)($a['area_id'] ?? 0), $rid], 'That area does not exist. Use list_tables.');
            $id = (int)$ar['id'];
            $n = $conn->prepare('SELECT COUNT(*) FROM tables WHERE area_id = ? AND restaurant_id = ?');
            $n->execute([$id, $rid]);
            $count = (int)$n->fetchColumn();
            $moveTo = (int)($a['move_to'] ?? 0);
            $conn->beginTransaction();
            try {
                if ($count) {
                    if (!$moveTo || $moveTo === $id) throw new McpToolError($ar['area_name'] . " has $count table(s): give move_to, the area they should move to.");
                    $row('SELECT id FROM areas WHERE id = ? AND restaurant_id = ?', [$moveTo, $rid], 'move_to is not one of your areas.');
                    $clash = $conn->prepare('SELECT t.id, t.table_number FROM tables t WHERE t.area_id = ? AND t.restaurant_id = ? AND t.table_number IN (SELECT table_number FROM (SELECT table_number FROM tables WHERE area_id = ? AND restaurant_id = ?) x)');
                    $clash->execute([$id, $rid, $moveTo, $rid]);
                    $prefix = strtoupper(mb_substr($ar['area_name'], 0, 1)) ?: 'X';
                    foreach ($clash->fetchAll(PDO::FETCH_ASSOC) as $c) $conn->prepare('UPDATE tables SET table_number = ? WHERE id = ?')->execute([$prefix . '-' . $c['table_number'], $c['id']]);
                    $conn->prepare('UPDATE tables SET area_id = ?, updated_at = NOW() WHERE area_id = ? AND restaurant_id = ?')->execute([$moveTo, $id, $rid]);
                }
                $conn->prepare('DELETE FROM areas WHERE id = ? AND restaurant_id = ?')->execute([$id, $rid]);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                throw $e;
            }
            $log('area.delete', 'Deleted area ' . $ar['area_name'] . ($count ? " ($count tables moved)" : ''), 'area', $id);
            return ['🗑️ Deleted area ' . $ar['area_name'] . ($count ? "; its $count table(s) moved." : '.'), ['area_id' => $id, 'moved_tables' => $count]];

        case 'list_waiter_calls':
            $st = $conn->prepare("SELECT w.id, w.request_type, w.status, w.notes, w.created_at, w.attended_at, t.table_number FROM waiter_requests w JOIN tables t ON t.id = w.table_id
                WHERE t.restaurant_id = ? AND (w.status = 'Pending' OR (w.status <> 'Pending' AND DATE(COALESCE(w.attended_at, w.created_at)) = CURDATE())) ORDER BY w.status = 'Pending' DESC, w.created_at");
            $st->execute([$rid]);
            $rows = array_map(fn($r) => ['call_id' => (int)$r['id'], 'table' => $r['table_number'], 'type' => $r['request_type'], 'status' => $r['status'], 'note' => $r['notes'], 'time' => $r['created_at'], 'answered_at' => $r['attended_at']], $st->fetchAll(PDO::FETCH_ASSOC));
            $open = array_filter($rows, fn($r) => $r['status'] === 'Pending');
            return [count($open) ? count($open) . ' open call(s): ' . implode(', ', array_map(fn($r) => 'table ' . $r['table'] . ' (' . $r['type'] . ')', $open)) : 'No open waiter calls.', ['calls' => $rows]];

        case 'answer_waiter_call':
            $c = $row('SELECT w.id, t.table_number FROM waiter_requests w JOIN tables t ON t.id = w.table_id WHERE w.id = ? AND t.restaurant_id = ?', [(int)($a['call_id'] ?? 0), $rid], 'That waiter call does not exist. Use list_waiter_calls.');
            $conn->prepare("UPDATE waiter_requests SET status = 'Attended', attended_at = NOW() WHERE id = ?")->execute([$c['id']]);
            return ['✅ Call from table ' . $c['table_number'] . ' marked answered.', ['call_id' => (int)$c['id'], 'status' => 'Attended']];

        // ── reservations ──
        case 'list_reservations':
            $where = 'r.restaurant_id = ?';
            $args = [$rid];
            if (!empty($a['from']) || !empty($a['to'])) {
                $where .= ' AND r.reservation_date BETWEEN ? AND ?';
                $args[] = $date($a['from'] ?? $a['to'], 'from');
                $args[] = $date($a['to'] ?? $a['from'], 'to');
            } else {
                $where .= ' AND r.reservation_date = ' . (!empty($a['date']) ? '?' : 'CURDATE()');
                if (!empty($a['date'])) $args[] = $date($a['date'], 'date');
            }
            if (!empty($a['status'])) {
                if (!in_array($a['status'], OP_RES_STATUSES, true)) throw new McpToolError('status must be one of: ' . implode(', ', OP_RES_STATUSES) . '.');
                $where .= ' AND r.status = ?'; $args[] = $a['status'];
            }
            $st = $conn->prepare("SELECT r.id, r.reservation_date, r.time_slot, r.no_of_guests, r.meal_type, r.customer_name, r.phone, r.email, r.special_request, r.status, t.table_number
                FROM reservations r LEFT JOIN tables t ON t.id = r.table_id WHERE $where ORDER BY r.reservation_date, STR_TO_DATE(r.time_slot, '%h:%i %p'), r.id LIMIT 200");
            $st->execute($args);
            $rows = array_map(fn($r) => ['reservation_id' => (int)$r['id'], 'date' => $r['reservation_date'], 'time_slot' => $r['time_slot'], 'guests' => (int)$r['no_of_guests'], 'meal_type' => $r['meal_type'],
                'name' => html_entity_decode((string)$r['customer_name'], ENT_QUOTES), 'phone' => $r['phone'], 'email' => $r['email'], 'note' => html_entity_decode((string)$r['special_request'], ENT_QUOTES), 'status' => $r['status'], 'table' => $r['table_number']], $st->fetchAll(PDO::FETCH_ASSOC));
            return [$rows ? count($rows) . " booking(s):\n" . implode("\n", array_map(fn($r) => '#' . $r['reservation_id'] . ' ' . $r['date'] . ' ' . $r['time_slot'] . ' · ' . $r['name'] . ' · ' . $r['guests'] . ' guests · ' . $r['status'] . ($r['table'] ? ' · ' . $r['table'] : ''), $rows)) : 'No bookings.', ['reservations' => $rows]];

        case 'add_reservation':
            $d = $date($a['date'] ?? '', 'date');
            $slot = trim((string)($a['time_slot'] ?? ''));
            if (!preg_match('/^[0-9: APMapm-]{3,20}$/', $slot)) throw new McpToolError('time_slot must look like 07:30 PM.');
            $nm = trim(mb_substr((string)($a['name'] ?? ''), 0, 100));
            if (mb_strlen($nm) < 2) throw new McpToolError('Give the guest name.');
            $phone = preg_replace('/[^0-9+]/', '', (string)($a['phone'] ?? ''));
            if ($phone !== '' && !preg_match('/^\+?\d{7,15}$/', $phone)) throw new McpToolError('phone must be 7 to 15 digits.');
            $email = trim((string)($a['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new McpToolError('That email does not look right.');
            $guests = (int)($a['guests'] ?? 0);
            if ($guests < 1 || $guests > 50) throw new McpToolError('guests must be 1 to 50.');
            $table = (int)($a['table_id'] ?? 0) ?: null;
            if ($table) {
                $t = $row('SELECT id, table_number, capacity FROM tables WHERE id = ? AND restaurant_id = ?', [$table, $rid], 'That table does not exist. Use list_tables.');
                $clash = $conn->prepare("SELECT id FROM reservations WHERE restaurant_id = ? AND table_id = ? AND reservation_date = ? AND time_slot = ? AND status NOT IN ('Cancelled','Completed','No Show') LIMIT 1");
                $clash->execute([$rid, $table, $d, $slot]);
                if ($cid = $clash->fetchColumn()) throw new McpToolError('Table ' . $t['table_number'] . " is already booked on $d at $slot (booking #$cid).");
            }
            $status = array_key_exists('confirmed', $a) && !$a['confirmed'] ? 'Pending' : 'Confirmed';
            $conn->prepare('INSERT INTO reservations (restaurant_id, table_id, reservation_date, time_slot, no_of_guests, meal_type, customer_name, phone, email, special_request, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
                ->execute([$rid, $table, $d, $slot, $guests, mb_substr(trim((string)($a['meal_type'] ?? 'Dinner')), 0, 30) ?: 'Dinner', $nm, $phone, $email, mb_substr(trim((string)($a['note'] ?? '')), 0, 500), $status]);
            $id = (int)$conn->lastInsertId();
            try { require_once __DIR__ . '/../../config/mail_engine.php'; mailBooking($conn, $id, $status === 'Confirmed' ? 'Confirmed' : 'received'); } catch (Throwable $e) { error_log('booking email: ' . $e->getMessage()); }
            if ($phone !== '') {
                // the dashboard keeps the guest list's visit count in step with bookings
                try {
                    $c = $conn->prepare('SELECT id FROM customers WHERE restaurant_id = ? AND phone = ?');
                    $c->execute([$rid, $phone]);
                    if ($c->fetchColumn()) $conn->prepare('UPDATE customers SET total_visits = total_visits + 1, last_visit_date = ? WHERE restaurant_id = ? AND phone = ?')->execute([$d, $rid, $phone]);
                    else $conn->prepare('INSERT INTO customers (restaurant_id, customer_name, phone, email, total_visits, last_visit_date) VALUES (?, ?, ?, ?, 1, ?)')->execute([$rid, $nm, $phone, $email ?: null, $d]);
                } catch (Throwable $e) { error_log('reservation customer: ' . $e->getMessage()); }
            }
            $log('reservation.add', "Booked $nm, $guests guests, $d $slot", 'reservation', $id);
            return ["✅ Booked $nm: $guests guests on $d at $slot" . ($table ? ', table ' . $t['table_number'] : '') . " ($status).", ['reservation_id' => $id, 'status' => $status]];

        case 'update_reservation':
            $status = (string)($a['status'] ?? '');
            if (!in_array($status, OP_RES_STATUSES, true)) throw new McpToolError('status must be one of: ' . implode(', ', OP_RES_STATUSES) . '.');
            $r = $row('SELECT id, status, customer_name, reservation_date, time_slot FROM reservations WHERE id = ? AND restaurant_id = ?', [(int)($a['reservation_id'] ?? 0), $rid], 'That booking does not exist. Use list_reservations.');
            $conn->prepare('UPDATE reservations SET status = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?')->execute([$status, $r['id'], $rid]);
            if ($status !== $r['status'] && in_array($status, ['Confirmed', 'Cancelled'], true)) {
                try { require_once __DIR__ . '/../../config/mail_engine.php'; mailBooking($conn, (int)$r['id'], $status); } catch (Throwable $e) { error_log('booking email: ' . $e->getMessage()); }
            }
            $who = html_entity_decode((string)$r['customer_name'], ENT_QUOTES);
            $log('reservation.status', "Booking #{$r['id']} ($who): {$r['status']} → $status", 'reservation', (int)$r['id']);
            return ["✅ Booking #{$r['id']} ($who, {$r['reservation_date']} {$r['time_slot']}) is now $status.", ['reservation_id' => (int)$r['id'], 'status' => $status]];

        // ── staff ──
        case 'list_staff':
            $st = $conn->prepare('SELECT id, member_name, email, phone, role, is_active, created_at FROM staff WHERE restaurant_id = ? ORDER BY is_active DESC, member_name');
            $st->execute([$rid]);
            $rows = array_map(fn($r) => ['staff_id' => (int)$r['id'], 'name' => $r['member_name'], 'email' => $r['email'], 'phone' => $r['phone'], 'role' => $r['role'], 'active' => (int)$r['is_active'] === 1, 'added' => $r['created_at']], $st->fetchAll(PDO::FETCH_ASSOC));
            return [$rows ? implode("\n", array_map(fn($r) => $r['staff_id'] . '. ' . $r['name'] . ' · ' . $r['role'] . ($r['active'] ? '' : ' (paused)'), $rows)) : 'No staff yet.', ['staff' => $rows]];

        case 'add_staff':
            $nm = trim(mb_substr((string)($a['name'] ?? ''), 0, 100));
            $email = trim((string)($a['email'] ?? ''));
            $phone = trim(mb_substr((string)($a['phone'] ?? ''), 0, 30));
            $role = (string)($a['role'] ?? '');
            $pw = (string)($a['password'] ?? '');
            if ($nm === '') throw new McpToolError('Give the name.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new McpToolError('Give a valid email (they log in with it).');
            if (!preg_match('/^\+?[0-9\-\s()]{6,25}$/', $phone)) throw new McpToolError('Give a phone number with country code, e.g. +91-9876543210.');
            if (!in_array($role, OP_STAFF_ROLES, true)) throw new McpToolError('role must be one of: ' . implode(', ', OP_STAFF_ROLES) . '. Admin staff are added in the dashboard.');
            if (strlen($pw) < 6) throw new McpToolError('The password needs at least 6 characters.');
            opStaffRole($conn, $rid, $role);
            $dup = $conn->prepare('SELECT id FROM staff WHERE restaurant_id = ? AND email = ?');
            $dup->execute([$rid, $email]);
            if ($dup->fetchColumn()) throw new McpToolError('A staff member with this email already exists.');
            $conn->prepare('INSERT INTO staff (restaurant_id, member_name, email, phone, password, role) VALUES (?, ?, ?, ?, ?, ?)')->execute([$rid, $nm, $email, $phone, password_hash($pw, PASSWORD_DEFAULT), $role]);
            $id = (int)$conn->lastInsertId();
            $log('staff.add', "Added staff member $nm as $role", 'staff', $id);
            return ["✅ Added $nm as $role. They log in with $email and the password you set; ask them to change it.", ['staff_id' => $id]];

        case 'update_staff':
            $s = $row('SELECT id, member_name, phone, role, is_active FROM staff WHERE id = ? AND restaurant_id = ?', [(int)($a['staff_id'] ?? 0), $rid], 'That staff member does not exist. Use list_staff.');
            $set = []; $vals = []; $said = [];
            if (isset($a['name'])) { $nm = trim(mb_substr((string)$a['name'], 0, 100)); if ($nm === '') throw new McpToolError('The name cannot be empty.'); $set[] = 'member_name = ?'; $vals[] = $nm; $said[] = "name $nm"; }
            if (isset($a['phone'])) { $ph = trim(mb_substr((string)$a['phone'], 0, 30)); if (!preg_match('/^\+?[0-9\-\s()]{6,25}$/', $ph)) throw new McpToolError('That phone number does not look right.'); $set[] = 'phone = ?'; $vals[] = $ph; $said[] = 'phone'; }
            if (isset($a['role'])) {
                if ($s['role'] === 'Admin') throw new McpToolError('Admin staff are changed only in the dashboard.');
                if (!in_array($a['role'], OP_STAFF_ROLES, true)) throw new McpToolError('role must be one of: ' . implode(', ', OP_STAFF_ROLES) . '.');
                opStaffRole($conn, $rid, $a['role']);
                $set[] = 'role = ?'; $vals[] = $a['role']; $said[] = 'role ' . $a['role'];
            }
            if (array_key_exists('active', $a)) { $set[] = 'is_active = ?'; $vals[] = !empty($a['active']) ? 1 : 0; $said[] = !empty($a['active']) ? 'resumed' : 'paused'; }
            if (!$set) throw new McpToolError('Nothing to change: pass name, phone, role or active.');
            $conn->prepare('UPDATE staff SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = ? AND restaurant_id = ?')->execute(array_merge($vals, [$s['id'], $rid]));
            $log(array_key_exists('active', $a) && count($set) === 1 ? (!empty($a['active']) ? 'staff.resume' : 'staff.pause') : 'staff.edit', 'Staff ' . $s['member_name'] . ': ' . implode(', ', $said), 'staff', (int)$s['id']);
            return ['✅ ' . $s['member_name'] . ': ' . implode(', ', $said) . '.', ['staff_id' => (int)$s['id'], 'changes' => $said]];

        case 'remove_staff':
            $s = $row('SELECT id, member_name, role FROM staff WHERE id = ? AND restaurant_id = ?', [(int)($a['staff_id'] ?? 0), $rid], 'That staff member does not exist. Use list_staff.');
            if ($s['role'] === 'Admin') throw new McpToolError('Admin staff are removed only in the dashboard.');
            $conn->prepare('DELETE FROM staff WHERE id = ? AND restaurant_id = ?')->execute([$s['id'], $rid]);
            $log('staff.delete', 'Removed staff member ' . $s['member_name'], 'staff', (int)$s['id']);
            return ['🗑️ Removed ' . $s['member_name'] . '.', ['staff_id' => (int)$s['id'], 'deleted' => true]];

        // ── stock ──
        case 'list_stock':
            opInv($conn);
            $st = $conn->prepare('SELECT id, item_name, unit, category, quantity_in_stock, low_stock_threshold, par_level, cost_per_unit FROM inventory_items WHERE restaurant_id = ? AND is_active = 1 ORDER BY category, item_name');
            $st->execute([$rid]);
            $rows = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $q = (float)$r['quantity_in_stock']; $low = (float)$r['low_stock_threshold'];
                $status = $q < 0 ? 'negative' : ($q == 0 ? 'out' : ($low > 0 && $q <= $low ? 'low' : 'ok'));
                if (!empty($a['only_low']) && $status === 'ok') continue;
                $rows[] = ['stock_id' => (int)$r['id'], 'name' => $r['item_name'], 'unit' => $r['unit'], 'category' => $r['category'], 'quantity' => round($q, 3), 'low_stock_level' => $low,
                    'par_level' => (float)$r['par_level'], 'cost_per_unit' => (float)$r['cost_per_unit'], 'status' => $status];
            }
            $low = array_filter($rows, fn($r) => $r['status'] !== 'ok');
            return [count($rows) . ' item(s)' . ($low ? ', running low: ' . implode(', ', array_map(fn($r) => $r['name'] . ' (' . $r['quantity'] . ' ' . $r['unit'] . ')', array_slice($low, 0, 15))) : ', none low') . '.', ['items' => $rows]];

        case 'save_stock_item':
            opInv($conn);
            $id = (int)($a['stock_id'] ?? 0);
            $cur = $id ? $row('SELECT * FROM inventory_items WHERE id = ? AND restaurant_id = ? AND is_active = 1', [$id, $rid], 'That stock item does not exist. Use list_stock.')
                : ['item_name' => '', 'unit' => 'unit', 'category' => '', 'low_stock_threshold' => 0, 'par_level' => 0, 'cost_per_unit' => 0];
            $nm = array_key_exists('name', $a) ? trim(mb_substr((string)$a['name'], 0, 150)) : $cur['item_name'];
            if ($nm === '') throw new McpToolError('Give the item a name.');
            $unit = $a['unit'] ?? $cur['unit'];
            if (!in_array($unit, OP_UNITS, true)) throw new McpToolError('unit must be one of: ' . implode(', ', OP_UNITS) . '.');
            $dup = $conn->prepare('SELECT id FROM inventory_items WHERE restaurant_id = ? AND item_name = ? AND is_active = 1 AND id <> ?');
            $dup->execute([$rid, $nm, $id]);
            if ($dup->fetchColumn()) throw new McpToolError("You already have a stock item called $nm.");
            $low = max(0, (float)($a['low_stock_level'] ?? $cur['low_stock_threshold']));
            $par = max(0, (float)($a['par_level'] ?? $cur['par_level']));
            $cost = max(0, (float)($a['cost_per_unit'] ?? $cur['cost_per_unit']));
            $cat = mb_substr(trim((string)($a['category'] ?? $cur['category'])), 0, 100);
            if ($id && $unit !== $cur['unit']) {
                [$fa, $fm] = invUnitBase($cur['unit']);
                [$ta, $tm] = invUnitBase($unit);
                if ($fa === $ta) {
                    $f = $fm / $tm;
                    $conn->prepare('UPDATE inventory_items SET quantity_in_stock = quantity_in_stock * ? WHERE id = ? AND restaurant_id = ?')->execute([$f, $id, $rid]);
                    if (!isset($a['low_stock_level'])) $low *= $f;
                    if (!isset($a['par_level'])) $par *= $f;
                    if (!isset($a['cost_per_unit'])) $cost = round($cost / $f, 4);
                } else {
                    $rc = $conn->prepare('SELECT COUNT(*) FROM inventory_recipes WHERE inventory_item_id = ? AND restaurant_id = ?');
                    $rc->execute([$id, $rid]);
                    if ((int)$rc->fetchColumn()) throw new McpToolError("Recipes measure this item in {$cur['unit']}. Remove it from those recipes before changing it to $unit.");
                }
            }
            if ($id) {
                $conn->prepare('UPDATE inventory_items SET item_name = ?, unit = ?, category = ?, low_stock_threshold = ?, par_level = ?, cost_per_unit = ? WHERE id = ? AND restaurant_id = ?')->execute([$nm, $unit, $cat, $low, $par, $cost, $id, $rid]);
            } else {
                $conn->beginTransaction();
                $conn->prepare('INSERT INTO inventory_items (restaurant_id, item_name, unit, category, quantity_in_stock, low_stock_threshold, par_level, cost_per_unit) VALUES (?, ?, ?, ?, 0, ?, ?, ?)')->execute([$rid, $nm, $unit, $cat, $low, $par, $cost]);
                $id = (int)$conn->lastInsertId();
                $open = max(0, (float)($a['opening_stock'] ?? 0));
                if ($open > 0) opMove($conn, $ctx, $id, 'opening', $open, $cost, 'Opening stock');   // already paid for: no expense
                $conn->commit();
            }
            invKick($conn, $rid);
            $log('stock.save', "Saved stock item $nm", 'inventory_item', $id);
            return ["✅ Stock item $nm saved ($unit).", ['stock_id' => $id]];

        case 'restock':
            opInv($conn);
            $it = $row('SELECT * FROM inventory_items WHERE id = ? AND restaurant_id = ? AND is_active = 1', [(int)($a['stock_id'] ?? 0), $rid], 'That stock item does not exist. Use list_stock.');
            $qty = (float)($a['quantity'] ?? 0);
            if ($qty <= 0) throw new McpToolError('Give how much came in (quantity).');
            $cost = isset($a['cost_per_unit']) ? max(0, (float)$a['cost_per_unit']) : (float)$it['cost_per_unit'];
            $conn->beginTransaction();
            opMove($conn, $ctx, (int)$it['id'], 'restock', $qty, $cost, (string)($a['note'] ?? ''), ['Inventory Purchase', 'Restock: ' . $it['item_name'] . ' x' . round($qty, 2) . ' ' . $it['unit']]);
            $conn->prepare('UPDATE inventory_items SET cost_per_unit = ? WHERE id = ? AND restaurant_id = ?')->execute([$cost, $it['id'], $rid]);
            $conn->commit();
            invKick($conn, $rid);
            $log('stock.restock', 'Restocked ' . $it['item_name'] . ' +' . round($qty, 2) . ' ' . $it['unit'], 'inventory_item', (int)$it['id']);
            return ['✅ Added ' . round($qty, 2) . ' ' . $it['unit'] . ' of ' . $it['item_name'] . ($qty * $cost > 0 ? ' (' . $m($qty * $cost) . ' recorded as an expense)' : '') . '.', ['stock_id' => (int)$it['id'], 'quantity' => round((float)$it['quantity_in_stock'] + $qty, 3)]];

        case 'adjust_stock':
            opInv($conn);
            $it = $row('SELECT * FROM inventory_items WHERE id = ? AND restaurant_id = ? AND is_active = 1', [(int)($a['stock_id'] ?? 0), $rid], 'That stock item does not exist. Use list_stock.');
            $kind = (string)($a['kind'] ?? '');
            if (!in_array($kind, ['wastage', 'set', 'adjustment'], true)) throw new McpToolError('kind must be wastage, set or adjustment.');
            $qty = (float)($a['quantity'] ?? 0);
            $have = (float)$it['quantity_in_stock'];
            if ($kind === 'set') {
                if ($qty < 0) throw new McpToolError('set: give the quantity on hand now (0 or more).');
                $delta = round($qty - $have, 3);
                if (abs($delta) < 0.0005) return ['Stock already shows ' . round($qty, 2) . ' ' . $it['unit'] . '.', ['stock_id' => (int)$it['id'], 'quantity' => $qty]];
                $kind = 'adjustment';
            } else {
                if ($qty == 0) throw new McpToolError('Give a quantity.');
                $delta = $kind === 'wastage' ? -abs($qty) : $qty;
            }
            if ($delta < 0 && $have + $delta < -0.0005) throw new McpToolError('That is more than you have (' . round(max(0, $have), 2) . ' ' . $it['unit'] . '). Use kind set for a recount.');
            $reason = trim(mb_substr((string)($a['reason'] ?? ''), 0, 200));
            $conn->beginTransaction();
            opMove($conn, $ctx, (int)$it['id'], $kind, $delta, (float)$it['cost_per_unit'], $reason,
                $kind === 'wastage' ? ['Inventory Wastage', 'Wastage: ' . $it['item_name'] . ' x' . round(abs($delta), 2) . ' ' . $it['unit'] . ($reason ? " ($reason)" : '')] : null);
            $conn->commit();
            invKick($conn, $rid);
            $log('stock.adjust', $it['item_name'] . ' ' . ($delta > 0 ? '+' : '') . round($delta, 2) . ' ' . $it['unit'] . " ($kind)", 'inventory_item', (int)$it['id']);
            return ['✅ ' . $it['item_name'] . ': ' . ($delta > 0 ? '+' : '') . round($delta, 2) . ' ' . $it['unit'] . ", now " . round($have + $delta, 2) . '.', ['stock_id' => (int)$it['id'], 'quantity' => round($have + $delta, 3)]];

        case 'delete_stock_item':
            opInv($conn);
            $it = $row('SELECT id, item_name FROM inventory_items WHERE id = ? AND restaurant_id = ? AND is_active = 1', [(int)($a['stock_id'] ?? 0), $rid], 'That stock item does not exist. Use list_stock.');
            $conn->beginTransaction();
            $conn->prepare('UPDATE inventory_items SET is_active = 0 WHERE id = ? AND restaurant_id = ?')->execute([$it['id'], $rid]);
            $conn->prepare('DELETE FROM inventory_recipes WHERE inventory_item_id = ? AND restaurant_id = ?')->execute([$it['id'], $rid]);
            $conn->commit();
            $log('stock.delete', 'Removed stock item ' . $it['item_name'], 'inventory_item', (int)$it['id']);
            return ['🗑️ Removed ' . $it['item_name'] . ' (history stays in reports).', ['stock_id' => (int)$it['id'], 'deleted' => true]];

        // ── delivery zones ──
        case 'list_delivery_zones':
            opEnsureZones($conn);
            $st = $conn->prepare('SELECT id, pincode, zone_name, delivery_charge, estimated_time, is_active FROM delivery_zones WHERE restaurant_id = ? ORDER BY pincode');
            $st->execute([$rid]);
            $rows = array_map(fn($z) => ['zone_id' => (int)$z['id'], 'pincode' => $z['pincode'], 'name' => $z['zone_name'], 'charge' => (float)$z['delivery_charge'], 'minutes' => (int)$z['estimated_time'], 'active' => (int)$z['is_active'] === 1], $st->fetchAll(PDO::FETCH_ASSOC));
            return [$rows ? implode("\n", array_map(fn($z) => $z['pincode'] . ($z['name'] ? ' ' . $z['name'] : '') . ' · ' . $m($z['charge']) . ' · ' . $z['minutes'] . ' min' . ($z['active'] ? '' : ' (OFF)'), $rows)) : 'No delivery zones yet.', ['currency' => $ctx['settings']['currency'], 'zones' => $rows]];

        case 'save_delivery_zone':
            opEnsureZones($conn);
            $id = (int)($a['zone_id'] ?? 0);
            $cur = $id ? $row('SELECT * FROM delivery_zones WHERE id = ? AND restaurant_id = ?', [$id, $rid], 'That zone does not exist. Use list_delivery_zones.')
                : ['pincode' => '', 'zone_name' => '', 'delivery_charge' => 0, 'estimated_time' => 30, 'is_active' => 1];
            $pin = array_key_exists('pincode', $a) ? strtoupper(preg_replace('/\s+/', '', (string)$a['pincode'])) : $cur['pincode'];
            if (!preg_match('/^[A-Z0-9-]{3,10}$/', $pin)) throw new McpToolError('Give the pincode (3 to 10 letters or digits).');
            $dup = $conn->prepare('SELECT id FROM delivery_zones WHERE restaurant_id = ? AND pincode = ? AND id <> ?');
            $dup->execute([$rid, $pin, $id]);
            if ($dup->fetchColumn()) throw new McpToolError("Pincode $pin already has a zone.");
            $charge = (float)($a['charge'] ?? $cur['delivery_charge']);
            if ($charge < 0) throw new McpToolError('charge cannot be negative.');
            $vals = [$pin, mb_substr(trim((string)($a['name'] ?? $cur['zone_name'])), 0, 100), round($charge, 2), max(1, min(300, (int)($a['minutes'] ?? $cur['estimated_time']))),
                array_key_exists('active', $a) ? (!empty($a['active']) ? 1 : 0) : (int)$cur['is_active']];
            if ($id) $conn->prepare('UPDATE delivery_zones SET pincode = ?, zone_name = ?, delivery_charge = ?, estimated_time = ?, is_active = ? WHERE id = ? AND restaurant_id = ?')->execute(array_merge($vals, [$id, $rid]));
            else { $conn->prepare('INSERT INTO delivery_zones (pincode, zone_name, delivery_charge, estimated_time, is_active, restaurant_id) VALUES (?, ?, ?, ?, ?, ?)')->execute(array_merge($vals, [$rid])); $id = (int)$conn->lastInsertId(); }
            $log('delivery.zone', "Saved delivery zone $pin (" . $m($charge) . ')', 'delivery_zone', $id);
            return ["✅ Delivery zone $pin: " . $m($charge) . ', ' . $vals[3] . ' min' . ($vals[4] ? '' : ' (off)') . '.', ['zone_id' => $id]];

        case 'delete_delivery_zone':
            opEnsureZones($conn);
            $z = $row('SELECT id, pincode FROM delivery_zones WHERE id = ? AND restaurant_id = ?', [(int)($a['zone_id'] ?? 0), $rid], 'That zone does not exist. Use list_delivery_zones.');
            $conn->prepare('DELETE FROM delivery_zones WHERE id = ? AND restaurant_id = ?')->execute([$z['id'], $rid]);
            $log('delivery.zone', 'Deleted delivery zone ' . $z['pincode'], 'delivery_zone', (int)$z['id']);
            return ['🗑️ Deleted delivery zone ' . $z['pincode'] . '.', ['zone_id' => (int)$z['id'], 'deleted' => true]];

        // ── catering ──
        case 'list_catering':
            opEnsureCatering($conn);
            $where = 'r.restaurant_id = ?'; $args = [$rid];
            if (!empty($a['status'])) {
                if (!in_array($a['status'], OP_CATERING_STATUSES, true)) throw new McpToolError('status must be one of: ' . implode(', ', OP_CATERING_STATUSES) . '.');
                $where .= ' AND r.status = ?'; $args[] = $a['status'];
            }
            $st = $conn->prepare("SELECT r.id, r.contact_name, r.contact_phone, r.event_date, r.guest_count, r.quoted_price, r.notes, r.status, r.created_at, t.tier_label FROM catering_requests r
                LEFT JOIN catering_price_tiers t ON t.id = r.tier_id WHERE $where ORDER BY r.created_at DESC LIMIT 100");
            $st->execute($args);
            $req = array_map(fn($r) => ['request_id' => (int)$r['id'], 'name' => $r['contact_name'], 'phone' => $r['contact_phone'], 'event_date' => $r['event_date'], 'guests' => (int)$r['guest_count'],
                'tier' => $r['tier_label'], 'quoted_price' => $r['quoted_price'] !== null ? (float)$r['quoted_price'] : null, 'note' => $r['notes'], 'status' => $r['status'], 'received' => $r['created_at']], $st->fetchAll(PDO::FETCH_ASSOC));
            $tq = $conn->prepare('SELECT id, tier_label, min_guests, max_guests, price, is_active FROM catering_price_tiers WHERE restaurant_id = ? ORDER BY sort_order, min_guests');
            $tq->execute([$rid]);
            $tiers = array_map(fn($t) => ['tier_id' => (int)$t['id'], 'label' => $t['tier_label'], 'min_guests' => (int)$t['min_guests'], 'max_guests' => (int)$t['max_guests'], 'price' => (float)$t['price'], 'active' => (int)$t['is_active'] === 1], $tq->fetchAll(PDO::FETCH_ASSOC));
            $new = count(array_filter($req, fn($r) => $r['status'] === 'new'));
            return [count($req) . " catering request(s), $new new · " . count($tiers) . ' price tier(s).', ['currency' => $ctx['settings']['currency'], 'requests' => $req, 'tiers' => $tiers]];

        case 'update_catering_request':
            opEnsureCatering($conn);
            $status = (string)($a['status'] ?? '');
            if (!in_array($status, OP_CATERING_STATUSES, true)) throw new McpToolError('status must be one of: ' . implode(', ', OP_CATERING_STATUSES) . '.');
            $r = $row('SELECT id, contact_name, status FROM catering_requests WHERE id = ? AND restaurant_id = ?', [(int)($a['request_id'] ?? 0), $rid], 'That request does not exist. Use list_catering.');
            $quote = isset($a['quoted_price']) ? round(max(0, (float)$a['quoted_price']), 2) : null;
            $note = isset($a['note']) ? mb_substr(trim((string)$a['note']), 0, 1000) : null;
            $conn->prepare('UPDATE catering_requests SET status = ?, quoted_price = COALESCE(?, quoted_price), notes = COALESCE(?, notes) WHERE id = ? AND restaurant_id = ?')->execute([$status, $quote, $note, $r['id'], $rid]);
            $log('catering.request', 'Catering request from ' . $r['contact_name'] . ": {$r['status']} → $status" . ($quote !== null ? ' (quote ' . $m($quote) . ')' : ''), 'catering_request', (int)$r['id']);
            return ['✅ Catering request from ' . $r['contact_name'] . " is now $status" . ($quote !== null ? ', quoted ' . $m($quote) : '') . '.', ['request_id' => (int)$r['id'], 'status' => $status]];

        case 'save_catering_tier':
            opEnsureCatering($conn);
            $id = (int)($a['tier_id'] ?? 0);
            $cur = $id ? $row('SELECT * FROM catering_price_tiers WHERE id = ? AND restaurant_id = ?', [$id, $rid], 'That tier does not exist. Use list_catering.')
                : ['tier_label' => '', 'min_guests' => 0, 'max_guests' => 0, 'price' => 0, 'is_active' => 1];
            $label = array_key_exists('label', $a) ? trim(mb_substr((string)$a['label'], 0, 100)) : $cur['tier_label'];
            $min = (int)($a['min_guests'] ?? $cur['min_guests']);
            $max = (int)($a['max_guests'] ?? $cur['max_guests']);
            $price = (float)($a['price'] ?? $cur['price']);
            if ($label === '') throw new McpToolError('Give the tier a label, e.g. 20–50 guests.');
            if ($min <= 0 || $max <= 0 || $max < $min) throw new McpToolError('min_guests and max_guests must be more than 0, and max at least min.');
            if ($price < 0) throw new McpToolError('price cannot be negative.');
            $active = array_key_exists('active', $a) ? (!empty($a['active']) ? 1 : 0) : (int)$cur['is_active'];
            if ($id) $conn->prepare('UPDATE catering_price_tiers SET tier_label = ?, min_guests = ?, max_guests = ?, price = ?, is_active = ? WHERE id = ? AND restaurant_id = ?')->execute([$label, $min, $max, $price, $active, $id, $rid]);
            else { $conn->prepare('INSERT INTO catering_price_tiers (restaurant_id, tier_label, min_guests, max_guests, price, is_active) VALUES (?, ?, ?, ?, ?, ?)')->execute([$rid, $label, $min, $max, $price, $active]); $id = (int)$conn->lastInsertId(); }
            $log('catering.tier', "Saved catering tier $label ({$min}–{$max} guests, " . $m($price) . ')', 'catering_tier', $id);
            return ["✅ Catering tier $label: {$min}–{$max} guests at " . $m($price) . '.', ['tier_id' => $id]];

        case 'delete_catering_tier':
            opEnsureCatering($conn);
            $t = $row('SELECT id, tier_label FROM catering_price_tiers WHERE id = ? AND restaurant_id = ?', [(int)($a['tier_id'] ?? 0), $rid], 'That tier does not exist. Use list_catering.');
            $conn->prepare('DELETE FROM catering_price_tiers WHERE id = ? AND restaurant_id = ?')->execute([$t['id'], $rid]);
            $log('catering.tier', 'Deleted catering tier ' . $t['tier_label'], 'catering_tier', (int)$t['id']);
            return ['🗑️ Deleted catering tier ' . $t['tier_label'] . '.', ['tier_id' => (int)$t['id'], 'deleted' => true]];
    }
    throw new McpToolError("Unknown tool: $name");
}

/** The area look columns the studios add (tables_studio.php / areas_studio.php). */
function opEnsureAreas(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['icon' => 'VARCHAR(30) NULL', 'color' => 'VARCHAR(7) NULL', 'note' => 'VARCHAR(120) NULL'] as $col => $def) {
        $q = $conn->query("SHOW COLUMNS FROM areas LIKE '$col'");
        if (!($q && $q->fetch())) $conn->exec("ALTER TABLE areas ADD COLUMN `$col` $def");
    }
}

/** Delivery / Cashier / Bartender roles exist in the staff ENUM; Delivery needs own riders switched on. */
function opStaffRole(PDO $conn, string $rid, string $role): void
{
    if (in_array($role, ['Delivery', 'Cashier', 'Bartender'], true)) {
        require_once __DIR__ . '/../../config/staff_roles.php';
        staffRolesEnsure($conn);
    }
    if ($role === 'Delivery') {
        require_once __DIR__ . '/../../config/rider_engine.php';
        if (!riderOwnRidersOn($conn, $rid)) throw new McpToolError('Turn on "We deliver with our own riders" in Settings › Delivery first.');
    }
}

function opInv(PDO $conn): void
{
    require_once __DIR__ . '/../../config/inventory_engine.php';
    invEnsure($conn);
}

/** One stock movement, as inventory_studio.php's $move: the transaction row, the stock change and (optionally) an expense. */
function opMove(PDO $conn, array $ctx, int $iid, string $type, float $qty, float $cost, string $notes, ?array $expense = null): int
{
    $rid = $ctx['rid'];
    $total = round(abs($qty) * $cost, 2);
    $conn->prepare('INSERT INTO inventory_transactions (restaurant_id, inventory_item_id, type, quantity, cost_per_unit, total_cost, notes, reference_type, reference_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?)')
        ->execute([$rid, $iid, $type, $qty, $cost, $total, mb_substr($notes, 0, 255), mb_substr($ctx['app'], 0, 100)]);
    $tx = (int)$conn->lastInsertId();
    $conn->prepare('UPDATE inventory_items SET quantity_in_stock = quantity_in_stock + ? WHERE id = ? AND restaurant_id = ?')->execute([$qty, $iid, $rid]);
    if ($expense && $total > 0) {
        $conn->prepare("INSERT INTO expenses (restaurant_id, category, amount, description, expense_date, source, reference_id) VALUES (?, ?, ?, ?, CURDATE(), 'inventory', ?)")
            ->execute([$rid, $expense[0], $total, mb_substr($expense[1], 0, 255), $tx]);
    }
    return $tx;
}

function opEnsureZones(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS delivery_zones (
        id INT AUTO_INCREMENT PRIMARY KEY, restaurant_id VARCHAR(10) NOT NULL, pincode VARCHAR(10) NOT NULL, zone_name VARCHAR(100) DEFAULT '',
        delivery_charge DECIMAL(10,2) DEFAULT 0.00, estimated_time INT DEFAULT 30, is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_pincode_restaurant (restaurant_id, pincode)
    )");
}

/** Same tables as ensureCateringTables() in controllers/catering_operations.php. */
function opEnsureCatering(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS catering_price_tiers (
        id INT AUTO_INCREMENT PRIMARY KEY, restaurant_id VARCHAR(50) NOT NULL, tier_label VARCHAR(100) NOT NULL, min_guests INT NOT NULL, max_guests INT NOT NULL,
        price DECIMAL(10, 2) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_ctiers_restaurant (restaurant_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->exec("CREATE TABLE IF NOT EXISTS catering_requests (
        id INT AUTO_INCREMENT PRIMARY KEY, restaurant_id VARCHAR(50) NOT NULL, customer_id INT DEFAULT NULL, contact_name VARCHAR(100) NOT NULL, contact_phone VARCHAR(20) NOT NULL,
        event_date DATE NOT NULL, guest_count INT NOT NULL, tier_id INT DEFAULT NULL, quoted_price DECIMAL(10, 2) DEFAULT NULL, notes TEXT DEFAULT NULL,
        status ENUM('new','contacted','confirmed','completed','cancelled') NOT NULL DEFAULT 'new',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_creq_restaurant (restaurant_id, status), INDEX idx_creq_event_date (event_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
