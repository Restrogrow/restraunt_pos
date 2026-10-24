<?php
/**
 * Menu extras for the API and AI apps (mcp/tools.php loads this): dish photos, deleting dishes, sizes, dish order,
 * menu sections and add-ons. Same tables and rules as the dashboard (controllers/menu_items_operations_base64.php,
 * menu_operations.php, addon_operations.php), with two safety rules the dashboard doesn't have:
 *  - order_items / kot_items cascade on menu_items, so a dish that was ever ordered is hidden, never deleted
 *    (its sales history stays); only never-ordered dishes are deleted for real.
 *  - menu_items cascade on menu, so a section is deleted only when it has no dishes left.
 */

const MX_SIZES_MAX = 12;

function menuExtrasTools(): array
{
    $id = ['type' => 'integer', 'description' => 'Dish id from list_menu.'];
    $img = ['type' => 'string', 'description' => 'The picture: a data URL (data:image/jpeg;base64,…) or a public https link to a JPEG, PNG, GIF or WebP, at most 5 MB.'];
    $t = fn(array $def, string $perm, ?array $route) => ['def' => $def, 'perm' => $perm, 'page' => null, 'route' => $route ? $route + [2 => 'Menu'] : null];
    return [
        $t(mcpDef('set_dish_photo', 'Set a dish photo', 'Give a dish a new photo, or remove its photo with remove=true. A landscape picture about 5:4 looks best.',
            ['item_id' => $id, 'image' => $img, 'remove' => ['type' => 'boolean', 'default' => false]], ['item_id'], 'write', 'Saving the photo…', 'Photo saved'),
            'menu:write', ['POST', 'menu/items/{item_id}/photo']),
        $t(mcpDef('delete_dish', 'Delete a dish', 'Remove a dish from the menu. A dish that was ever ordered is hidden instead (it disappears everywhere, but its sales history stays; show_dish brings it back). A dish never ordered is deleted for good.',
            ['item_id' => $id], ['item_id'], 'delete', 'Removing the dish…', 'Dish removed'),
            'menu:write', ['POST', 'menu/items/{item_id}/delete']),
        $t(mcpDef('show_dish', 'Show or hide a dish', 'Hide a dish from the menu everywhere (POS, waiter app, website) without deleting it, or show a hidden dish again. Different from switching it off: switched-off dishes still show as "sold out".',
            ['item_id' => $id, 'visible' => ['type' => 'boolean']], ['item_id', 'visible'], 'write', 'Updating the dish…', 'Dish updated'),
            'menu:write', ['POST', 'menu/items/{item_id}/visibility']),
        $t(mcpDef('set_dish_sizes', 'Set dish sizes', 'Replace all sizes of a dish (e.g. Half / Full, Regular / Large) with this list, in this order. An empty list removes the sizes and the dish keeps one price (give price). The dish price shown becomes the cheapest size.',
            ['item_id' => $id, 'sizes' => ['type' => 'array', 'maxItems' => MX_SIZES_MAX, 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 100], 'price' => ['type' => 'number', 'minimum' => 0]], 'required' => ['name', 'price']]],
             'price' => ['type' => 'number', 'minimum' => 0, 'description' => 'Single price, when removing all sizes.']], ['item_id', 'sizes'], 'write', 'Saving sizes…', 'Sizes saved'),
            'menu:write', ['POST', 'menu/items/{item_id}/sizes']),
        $t(mcpDef('move_dish', 'Move a dish to another section', 'Move a dish to another menu section.',
            ['item_id' => $id, 'section_id' => ['type' => 'integer', 'description' => 'Section id from list_sections.']], ['item_id', 'section_id'], 'write', 'Moving the dish…', 'Dish moved'),
            'menu:write', ['POST', 'menu/items/{item_id}/move']),
        $t(mcpDef('reorder_dishes', 'Reorder dishes', 'Set the order dishes appear in: give dish ids in the wanted order (usually all dishes of one section).',
            ['item_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1, 'maxItems' => 300]], ['item_ids'], 'write', 'Reordering…', 'Order saved'),
            'menu:write', ['POST', 'menu/items/order']),
        $t(mcpDef('list_sections', 'Menu sections', 'Menu sections (categories) with id, name, position, number of dishes, and whether they are on.',
            [], [], 'read', 'Reading sections…', 'Sections loaded'),
            'menu:read', ['GET', 'menu/sections']),
        $t(mcpDef('add_section', 'Add a menu section', 'Create a new menu section (e.g. Starters). It goes to the end of the menu.',
            ['name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100]], ['name'], 'create', 'Adding the section…', 'Section added'),
            'menu:write', ['POST', 'menu/sections']),
        $t(mcpDef('update_section', 'Rename or switch a section', 'Rename a menu section, or switch the whole section on or off.',
            ['section_id' => ['type' => 'integer'], 'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100], 'active' => ['type' => 'boolean']], ['section_id'], 'write', 'Saving the section…', 'Section saved'),
            'menu:write', ['PATCH', 'menu/sections/{section_id}']),
        $t(mcpDef('reorder_sections', 'Reorder menu sections', 'Set the order of the menu sections: give section ids in the wanted order.',
            ['section_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1, 'maxItems' => 100]], ['section_ids'], 'write', 'Reordering…', 'Order saved'),
            'menu:write', ['POST', 'menu/sections/order']),
        $t(mcpDef('delete_section', 'Delete a menu section', 'Delete an empty menu section. Move or delete its dishes first; a section with dishes is never deleted (that would delete the dishes and their sales history).',
            ['section_id' => ['type' => 'integer']], ['section_id'], 'delete', 'Deleting the section…', 'Section deleted'),
            'menu:write', ['POST', 'menu/sections/{section_id}/delete']),
        $t(mcpDef('list_addons', 'Add-ons', 'Add-ons (extras like Extra Cheese) with id, name, price and whether they are on. Add-ons apply to the whole menu.',
            [], [], 'read', 'Reading add-ons…', 'Add-ons loaded'),
            'menu:read', ['GET', 'menu/addons']),
        $t(mcpDef('save_addon', 'Add or edit an add-on', 'Create an add-on (no addon_id) or change one (with addon_id): name, price, on/off.',
            ['addon_id' => ['type' => 'integer'], 'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255], 'price' => ['type' => 'number', 'minimum' => 0], 'available' => ['type' => 'boolean']], [], 'create', 'Saving the add-on…', 'Add-on saved'),
            'menu:write', ['POST', 'menu/addons']),
        $t(mcpDef('delete_addon', 'Delete an add-on', 'Delete an add-on for good.',
            ['addon_id' => ['type' => 'integer']], ['addon_id'], 'delete', 'Deleting the add-on…', 'Add-on deleted'),
            'menu:write', ['POST', 'menu/addons/{addon_id}/delete']),
    ];
}

function menuExtrasRun(PDO $conn, array $ctx, string $name, array $a): array
{
    $rid = $ctx['rid'];
    $log = fn(string $action, string $summary, $entity = null, $id = null) => logActivity($conn, $rid, $action, $summary, ['via' => $ctx['app']], $entity, $id, mcpActor($ctx));
    $m = fn($n) => mcpMoney($ctx, $n);
    $dish = function () use ($conn, $rid, $a): array {
        $st = $conn->prepare('SELECT id, item_name_en, menu_id, base_price, has_variations, item_image FROM menu_items WHERE id = ? AND restaurant_id = ?');
        $st->execute([(int)($a['item_id'] ?? 0), $rid]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) throw new McpToolError('That dish is not on this menu. Use list_menu to find its id.');
        return $d;
    };
    $section = function (int $id) use ($conn, $rid): array {
        $st = $conn->prepare('SELECT id, menu_name, is_active FROM menu WHERE id = ? AND restaurant_id = ?');
        $st->execute([$id, $rid]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) throw new McpToolError('That section is not on this menu. Use list_sections to find its id.');
        return $s;
    };

    switch ($name) {
        case 'set_dish_photo':
            $d = $dish();
            if (!empty($a['remove'])) {
                $conn->prepare("UPDATE menu_items SET item_image = NULL, image_data = NULL, image_mime_type = NULL, updated_at = NOW() WHERE id = ? AND restaurant_id = ?")->execute([$d['id'], $rid]);
                $log('menu.edit', 'Removed the photo of ' . $d['item_name_en'], 'menu_item', $d['id']);
                return ['✅ Removed the photo of ' . $d['item_name_en'] . '.', ['item_id' => (int)$d['id'], 'photo' => null]];
            }
            require_once __DIR__ . '/../../config/api_media.php';
            $img = apiImageFrom((string)($a['image'] ?? ''), 5 * 1048576, 'Photo');
            $ref = 'db:' . uniqid();
            $st = $conn->prepare('UPDATE menu_items SET item_image = ?, image_data = ?, image_mime_type = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?');
            $st->bindValue(1, $ref);
            $st->bindValue(2, $img['bytes'], PDO::PARAM_LOB);
            $st->bindValue(3, $img['mime']);
            $st->bindValue(4, (int)$d['id'], PDO::PARAM_INT);
            $st->bindValue(5, $rid);
            $st->execute();
            $log('menu.edit', 'New photo for ' . $d['item_name_en'], 'menu_item', $d['id']);
            return ['✅ New photo saved for ' . $d['item_name_en'] . " ({$img['width']}×{$img['height']}).", ['item_id' => (int)$d['id'], 'photo' => $ref]];

        case 'delete_dish':
            $d = $dish();
            $used = 0;
            foreach (['order_items', 'kot_items'] as $tbl) {
                try {
                    $st = $conn->prepare("SELECT COUNT(*) FROM $tbl WHERE menu_item_id = ?");
                    $st->execute([$d['id']]);
                    $used += (int)$st->fetchColumn();
                } catch (PDOException $e) { /* table missing on this database */ }
            }
            if ($used) {
                mxEnsureHidden($conn);
                $conn->prepare('UPDATE menu_items SET is_hidden = 1, is_available = 0, updated_at = NOW() WHERE id = ? AND restaurant_id = ?')->execute([$d['id'], $rid]);
                $log('menu.hide', 'Hid ' . $d['item_name_en'] . ' (was ordered before, so its history is kept)', 'menu_item', $d['id']);
                return ['🙈 ' . $d['item_name_en'] . ' was ordered before, so it is hidden instead of deleted: gone from the menu everywhere, but its sales history stays. show_dish brings it back.',
                    ['item_id' => (int)$d['id'], 'deleted' => false, 'hidden' => true]];
            }
            $conn->beginTransaction();
            try {
                // rows that point at the dish without a foreign key
                foreach (['bestsellers' => 'menu_item_id', 'todays_specials' => 'menu_item_id', 'upsell_pairs' => 'menu_item_id', 'combo_slot_options' => 'menu_item_id'] as $tbl => $col) {
                    try { $conn->prepare("DELETE FROM $tbl WHERE $col = ?")->execute([$d['id']]); } catch (PDOException $e) { /* not on this database */ }
                }
                try { $conn->prepare('DELETE FROM upsell_pairs WHERE suggest_item_id = ?')->execute([$d['id']]); } catch (PDOException $e) {}
                $conn->prepare('DELETE FROM menu_items WHERE id = ? AND restaurant_id = ?')->execute([$d['id'], $rid]);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                throw $e;
            }
            $log('menu.delete', 'Deleted dish ' . $d['item_name_en'] . ' (price ' . $m($d['base_price']) . ')', 'menu_item', $d['id']);
            return ['🗑️ Deleted ' . $d['item_name_en'] . ' (it was never ordered).', ['item_id' => (int)$d['id'], 'deleted' => true, 'hidden' => false]];

        case 'show_dish':
            $d = $dish();
            mxEnsureHidden($conn);
            $show = !empty($a['visible']);
            $conn->prepare('UPDATE menu_items SET is_hidden = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?')->execute([$show ? 0 : 1, $d['id'], $rid]);
            $log('menu.hide', ($show ? 'Showed ' : 'Hid ') . $d['item_name_en'], 'menu_item', $d['id']);
            return [($show ? '👁️ ' . $d['item_name_en'] . ' is on the menu again' . ' (switch it on with set_dish_availability if it is still off).' : '🙈 ' . $d['item_name_en'] . ' is hidden from the menu.'),
                ['item_id' => (int)$d['id'], 'visible' => $show]];

        case 'set_dish_sizes':
            $d = $dish();
            $sizes = [];
            $seen = [];
            foreach ((array)($a['sizes'] ?? []) as $z) {
                $n = mb_substr(trim((string)($z['name'] ?? '')), 0, 100);
                if ($n === '') throw new McpToolError('Every size needs a name, e.g. Half or Full.');
                if (isset($seen[mb_strtolower($n)])) throw new McpToolError("Size \"$n\" is in the list twice.");
                $seen[mb_strtolower($n)] = true;
                $sizes[] = [$n, mcpPrice($z['price'] ?? null)];
            }
            if (count($sizes) === 1) throw new McpToolError('Give at least 2 sizes, or an empty list with price for a single price.');
            if (count($sizes) > MX_SIZES_MAX) throw new McpToolError('At most ' . MX_SIZES_MAX . ' sizes.');
            if (!$sizes && !isset($a['price'])) throw new McpToolError('Removing all sizes: give price, the single price the dish will have.');
            mxEnsureVariations($conn);
            $conn->beginTransaction();
            try {
                $conn->prepare('DELETE FROM menu_item_variations WHERE menu_item_id = ?')->execute([$d['id']]);
                $ins = $conn->prepare('INSERT INTO menu_item_variations (menu_item_id, variation_name, price, sort_order) VALUES (?, ?, ?, ?)');
                foreach ($sizes as $i => [$n, $p]) $ins->execute([$d['id'], $n, $p, $i]);
                $base = $sizes ? min(array_column($sizes, 1)) : mcpPrice($a['price']);
                $conn->prepare('UPDATE menu_items SET has_variations = ?, base_price = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?')->execute([$sizes ? 1 : 0, $base, $d['id'], $rid]);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                throw $e;
            }
            $said = $sizes ? implode(' / ', array_map(fn($z) => $z[0] . ' ' . $m($z[1]), $sizes)) : 'one price ' . $m($base);
            $log('menu.price', $d['item_name_en'] . ' sizes: ' . mb_substr($said, 0, 300), 'menu_item', $d['id']);
            return ['✅ ' . $d['item_name_en'] . ': ' . $said . '.', ['item_id' => (int)$d['id'], 'sizes' => array_map(fn($z) => ['name' => $z[0], 'price' => $z[1]], $sizes), 'price' => $base]];

        case 'move_dish':
            $d = $dish();
            $s = $section((int)($a['section_id'] ?? 0));
            $conn->prepare('UPDATE menu_items SET menu_id = ?, item_category = ?, subcategory_id = NULL, updated_at = NOW() WHERE id = ? AND restaurant_id = ?')->execute([$s['id'], $s['menu_name'], $d['id'], $rid]);
            $log('menu.edit', 'Moved ' . $d['item_name_en'] . ' to ' . $s['menu_name'], 'menu_item', $d['id']);
            return ['✅ Moved ' . $d['item_name_en'] . ' to ' . $s['menu_name'] . '.', ['item_id' => (int)$d['id'], 'section_id' => (int)$s['id']]];

        case 'reorder_dishes':
            $ids = mxIds($a['item_ids'] ?? [], 300);
            $n = mxReorder($conn, 'menu_items', $rid, $ids);
            if (!$n) throw new McpToolError('None of those dishes are on this menu.');
            return ["✅ Saved the order of $n dish(es).", ['ordered' => $n]];

        case 'list_sections':
            $st = $conn->prepare('SELECT m.id, m.menu_name, m.sort_order, m.is_active, COUNT(mi.id) dishes FROM menu m LEFT JOIN menu_items mi ON mi.menu_id = m.id AND mi.restaurant_id = m.restaurant_id
                WHERE m.restaurant_id = ? GROUP BY m.id, m.menu_name, m.sort_order, m.is_active ORDER BY m.sort_order, m.id');
            $st->execute([$rid]);
            $rows = array_map(fn($r) => ['section_id' => (int)$r['id'], 'name' => $r['menu_name'], 'position' => (int)$r['sort_order'], 'active' => (int)$r['is_active'] === 1, 'dishes' => (int)$r['dishes']], $st->fetchAll(PDO::FETCH_ASSOC));
            return [$rows ? implode("\n", array_map(fn($r) => $r['section_id'] . '. ' . $r['name'] . ' (' . $r['dishes'] . ' dishes)' . ($r['active'] ? '' : ' (OFF)'), $rows)) : 'No sections yet.', ['sections' => $rows]];

        case 'add_section':
            $nm = mxSectionName($a['name'] ?? '');
            mxSectionUnique($conn, $rid, $nm, 0);
            $next = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM menu WHERE restaurant_id = ?');
            $next->execute([$rid]);
            $conn->prepare('INSERT INTO menu (restaurant_id, menu_name, sort_order, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())')->execute([$rid, $nm, (int)$next->fetchColumn()]);
            $id = (int)$conn->lastInsertId();
            $log('menu.category_add', 'Added menu section ' . $nm, 'menu', $id);
            return ["✅ Added section $nm.", ['section_id' => $id, 'name' => $nm]];

        case 'update_section':
            $s = $section((int)($a['section_id'] ?? 0));
            $set = []; $vals = []; $said = [];
            if (isset($a['name'])) {
                $nm = mxSectionName($a['name']);
                mxSectionUnique($conn, $rid, $nm, (int)$s['id']);
                $set[] = 'menu_name = ?'; $vals[] = $nm; $said[] = 'renamed to ' . $nm;
            }
            if (isset($a['active'])) { $set[] = 'is_active = ?'; $vals[] = $a['active'] ? 1 : 0; $said[] = $a['active'] ? 'switched on' : 'switched off'; }
            if (!$set) throw new McpToolError('Nothing to change: pass name or active.');
            $conn->beginTransaction();
            $conn->prepare('UPDATE menu SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = ? AND restaurant_id = ?')->execute(array_merge($vals, [$s['id'], $rid]));
            // dishes keep the section name in item_category too
            if (isset($nm)) $conn->prepare('UPDATE menu_items SET item_category = ? WHERE menu_id = ? AND restaurant_id = ?')->execute([$nm, $s['id'], $rid]);
            $conn->commit();
            $log('menu.category_edit', 'Section ' . $s['menu_name'] . ': ' . implode(', ', $said), 'menu', $s['id']);
            return ['✅ ' . $s['menu_name'] . ': ' . implode(', ', $said) . '.', ['section_id' => (int)$s['id'], 'changes' => $said]];

        case 'reorder_sections':
            $ids = mxIds($a['section_ids'] ?? [], 100);
            $n = mxReorder($conn, 'menu', $rid, $ids);
            if (!$n) throw new McpToolError('None of those sections are on this menu.');
            return ["✅ Saved the order of $n section(s).", ['ordered' => $n]];

        case 'delete_section':
            $s = $section((int)($a['section_id'] ?? 0));
            $c = $conn->prepare('SELECT COUNT(*) FROM menu_items WHERE menu_id = ? AND restaurant_id = ?');
            $c->execute([$s['id'], $rid]);
            if ($n = (int)$c->fetchColumn()) throw new McpToolError($s['menu_name'] . " still has $n dish(es). Move them (move_dish) or delete them (delete_dish) first.");
            $conn->beginTransaction();
            try { $conn->prepare('DELETE FROM deals WHERE menu_id = ? AND restaurant_id = ?')->execute([$s['id'], $rid]); } catch (PDOException $e) { /* no deals table */ }
            $conn->prepare('DELETE FROM menu WHERE id = ? AND restaurant_id = ?')->execute([$s['id'], $rid]);
            $conn->commit();
            $log('menu.category_delete', 'Deleted empty menu section ' . $s['menu_name'], 'menu', $s['id']);
            return ['🗑️ Deleted the empty section ' . $s['menu_name'] . '.', ['section_id' => (int)$s['id'], 'deleted' => true]];

        case 'list_addons':
            mxEnsureAddons($conn);
            $st = $conn->prepare('SELECT id, addon_name, addon_price, is_available FROM meal_addons WHERE restaurant_id = ? ORDER BY addon_name');
            $st->execute([$rid]);
            $rows = array_map(fn($r) => ['addon_id' => (int)$r['id'], 'name' => $r['addon_name'], 'price' => (float)$r['addon_price'], 'available' => (int)$r['is_available'] === 1], $st->fetchAll(PDO::FETCH_ASSOC));
            return [$rows ? implode("\n", array_map(fn($r) => $r['addon_id'] . '. ' . $r['name'] . ' ' . $m($r['price']) . ($r['available'] ? '' : ' (OFF)'), $rows)) : 'No add-ons yet.', ['currency' => $ctx['settings']['currency'], 'addons' => $rows]];

        case 'save_addon':
            mxEnsureAddons($conn);
            $aid = (int)($a['addon_id'] ?? 0);
            if ($aid) {
                $st = $conn->prepare('SELECT id, addon_name FROM meal_addons WHERE id = ? AND restaurant_id = ?');
                $st->execute([$aid, $rid]);
                $old = $st->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new McpToolError('That add-on does not exist. Use list_addons.');
                $set = []; $vals = [];
                if (isset($a['name'])) { $nm = mb_substr(trim((string)$a['name']), 0, 255); if ($nm === '') throw new McpToolError('The add-on needs a name.'); $set[] = 'addon_name = ?'; $vals[] = $nm; }
                if (isset($a['price'])) { $set[] = 'addon_price = ?'; $vals[] = mcpPrice($a['price']); }
                if (isset($a['available'])) { $set[] = 'is_available = ?'; $vals[] = $a['available'] ? 1 : 0; }
                if (!$set) throw new McpToolError('Nothing to change: pass name, price or available.');
                $conn->prepare('UPDATE meal_addons SET ' . implode(', ', $set) . ' WHERE id = ? AND restaurant_id = ?')->execute(array_merge($vals, [$aid, $rid]));
                $log('menu.addon_edit', 'Edited add-on ' . ($nm ?? $old['addon_name']), 'addon', $aid);
                return ['✅ Add-on ' . ($nm ?? $old['addon_name']) . ' saved.', ['addon_id' => $aid]];
            }
            $nm = mb_substr(trim((string)($a['name'] ?? '')), 0, 255);
            if ($nm === '') throw new McpToolError('A new add-on needs a name and a price.');
            $price = mcpPrice($a['price'] ?? null);
            $conn->prepare('INSERT INTO meal_addons (restaurant_id, addon_name, addon_price, is_available) VALUES (?, ?, ?, ?)')->execute([$rid, $nm, $price, array_key_exists('available', $a) && !$a['available'] ? 0 : 1]);
            $aid = (int)$conn->lastInsertId();
            $log('menu.addon_add', 'Added add-on ' . $nm . ' (' . $m($price) . ')', 'addon', $aid);
            return ['✅ Added add-on ' . $nm . ' (' . $m($price) . ').', ['addon_id' => $aid]];

        case 'delete_addon':
            mxEnsureAddons($conn);
            $st = $conn->prepare('SELECT addon_name FROM meal_addons WHERE id = ? AND restaurant_id = ?');
            $st->execute([(int)($a['addon_id'] ?? 0), $rid]);
            $nm = $st->fetchColumn();
            if ($nm === false) throw new McpToolError('That add-on does not exist. Use list_addons.');
            $conn->prepare('DELETE FROM meal_addons WHERE id = ? AND restaurant_id = ?')->execute([(int)$a['addon_id'], $rid]);
            $log('menu.addon_delete', 'Deleted add-on ' . $nm, 'addon', (int)$a['addon_id']);
            return ["🗑️ Deleted add-on $nm.", ['addon_id' => (int)$a['addon_id'], 'deleted' => true]];
    }
    throw new McpToolError("Unknown tool: $name");
}

function mxIds($list, int $max): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$list), fn($i) => $i > 0)));
    if (!$ids || count($ids) > $max) throw new McpToolError("Give 1 to $max ids.");
    return $ids;
}

/** sort_order = position in $ids, only for this restaurant's rows. Returns how many were found. */
function mxReorder(PDO $conn, string $table, string $rid, array $ids): int
{
    $up = $conn->prepare("UPDATE $table SET sort_order = ? WHERE id = ? AND restaurant_id = ?");
    $n = 0;
    $conn->beginTransaction();
    foreach ($ids as $i => $id) { $up->execute([$i + 1, $id, $rid]); $n += $up->rowCount() > 0 ? 1 : 0; }
    $conn->commit();
    // rowCount is 0 when the position didn't change: count the rows that exist instead
    $in = implode(',', array_fill(0, count($ids), '?'));
    $c = $conn->prepare("SELECT COUNT(*) FROM $table WHERE restaurant_id = ? AND id IN ($in)");
    $c->execute(array_merge([$rid], $ids));
    return (int)$c->fetchColumn();
}

function mxSectionName($v): string
{
    $nm = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v)), 0, 100);
    if (mb_strlen($nm) < 2) throw new McpToolError('A section needs a name of at least 2 letters.');
    return $nm;
}

function mxSectionUnique(PDO $conn, string $rid, string $nm, int $exceptId): void
{
    $st = $conn->prepare('SELECT id FROM menu WHERE restaurant_id = ? AND LOWER(TRIM(menu_name)) = LOWER(?) AND id <> ?');
    $st->execute([$rid, $nm, $exceptId]);
    if ($st->fetchColumn()) throw new McpToolError("There is already a section called $nm.");
}

/** Same column the dashboard adds when hiding a dish (menu_items_operations_base64.php toggle_hidden). */
function mxEnsureHidden(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (!$conn->query("SHOW COLUMNS FROM menu_items LIKE 'is_hidden'")->fetch()) $conn->exec('ALTER TABLE menu_items ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0');
}

/** Same table as saveMenuItemVariations() makes. */
function mxEnsureVariations(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS menu_item_variations (
        id INT AUTO_INCREMENT PRIMARY KEY, menu_item_id INT NOT NULL, variation_name VARCHAR(100) NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00, sort_order INT DEFAULT 0, is_available BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE, INDEX idx_menu_item_id (menu_item_id),
        UNIQUE KEY unique_variation_per_item (menu_item_id, variation_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Same table as controllers/addon_operations.php makes. */
function mxEnsureAddons(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS meal_addons (
        id INT AUTO_INCREMENT PRIMARY KEY, restaurant_id VARCHAR(50) NOT NULL, addon_name VARCHAR(255) NOT NULL,
        addon_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00, addon_image VARCHAR(500) DEFAULT NULL, is_available TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_addon_restaurant (restaurant_id), INDEX idx_addon_available (restaurant_id, is_available)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
