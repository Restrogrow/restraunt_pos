<?php
/**
 * Offers for the API and AI apps (mcp/tools.php loads this): coupons, combos, today's special, scratch card and
 * wallet settings. Same tables and checks as the dashboard (controllers/coupon_operations.php, config/combo_helpers.php
 * saveCombo, api/todays_special.php, api/scratch_admin.php, api/wallet_admin.php). Customer wallets and private
 * scratch-card coupons (owner_phone) are never shown or changed here.
 */

const OF_COUPON_RE = '/^[A-Z0-9_-]{2,30}$/';

function offersTools(): array
{
    $t = fn(array $def, string $perm, string $page, ?array $route) => ['def' => $def, 'perm' => $perm, 'page' => $page, 'route' => $route ? $route + [2 => 'Offers'] : null];
    $date = ['type' => 'string', 'description' => 'YYYY-MM-DD, empty for no limit.'];
    $slot = ['type' => 'object', 'properties' => [
        'title' => ['type' => 'string', 'maxLength' => 80, 'description' => 'e.g. "Pick a main". Default: Included / Pick one.'],
        'qty' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 1],
        'choices' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 30, 'items' => ['type' => 'object', 'properties' => [
            'menu_item_id' => ['type' => 'integer'], 'size' => ['type' => 'string', 'description' => 'Size name for dishes with sizes.'], 'extra_price' => ['type' => 'number', 'minimum' => 0]], 'required' => ['menu_item_id']]]],
        'required' => ['choices']];
    return [
        $t(mcpDef('list_coupons', 'Coupons', 'Coupon codes with discount, minimum order, uses, dates and whether they are on.',
            [], [], 'read', 'Reading coupons…', 'Coupons loaded'), 'offers:read', 'couponsPage', ['GET', 'offers/coupons']),
        $t(mcpDef('save_coupon', 'Create or edit a coupon', 'Create a coupon (no coupon_id: code, type and value needed) or change one (coupon_id; only the fields you pass change). type percent or flat. A milestone coupon is applied automatically when the cart reaches min_order (needs min_order).',
            ['coupon_id' => ['type' => 'integer'], 'code' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{2,30}$'], 'type' => ['type' => 'string', 'enum' => ['percent', 'flat']],
             'value' => ['type' => 'number', 'exclusiveMinimum' => 0], 'min_order' => ['type' => 'number', 'minimum' => 0], 'max_uses' => ['type' => 'integer', 'minimum' => 0, 'description' => '0 = unlimited'],
             'valid_from' => $date, 'valid_until' => $date, 'description' => ['type' => 'string', 'maxLength' => 255], 'milestone' => ['type' => 'boolean'], 'active' => ['type' => 'boolean']],
            [], 'create', 'Saving the coupon…', 'Coupon saved'), 'offers:write', 'couponsPage', ['POST', 'offers/coupons']),
        $t(mcpDef('delete_coupon', 'Delete a coupon', 'Delete a coupon for good. To stop it for now, save it with active=false instead.',
            ['coupon_id' => ['type' => 'integer']], ['coupon_id'], 'delete', 'Deleting the coupon…', 'Coupon deleted'), 'offers:write', 'couponsPage', ['POST', 'offers/coupons/{coupon_id}/delete']),
        $t(mcpDef('list_combos', 'Combos', 'Combo deals with price, parts (dishes to pick from), days, times, dates and whether they are on.',
            [], [], 'read', 'Reading combos…', 'Combos loaded'), 'offers:read', 'dealsPage', ['GET', 'offers/combos']),
        $t(mcpDef('save_combo', 'Create or edit a combo', 'Create a combo (no combo_id) or replace one (combo_id; send the whole combo). Each part is a slot the customer fills with qty dishes from its choices.',
            ['combo_id' => ['type' => 'integer'], 'name' => ['type' => 'string', 'maxLength' => 120], 'price' => ['type' => 'number', 'exclusiveMinimum' => 0],
             'parts' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 12, 'items' => $slot], 'description' => ['type' => 'string', 'maxLength' => 300], 'badge' => ['type' => 'string', 'maxLength' => 40],
             'active' => ['type' => 'boolean', 'default' => true], 'valid_from' => $date, 'valid_until' => $date,
             'days' => ['type' => 'string', 'description' => 'Weekdays it runs: digits 1-7, Monday = 1 (default 1234567, every day).'],
             'time_from' => ['type' => 'string', 'description' => 'HH:MM, with time_to, e.g. lunch only.'], 'time_to' => ['type' => 'string']],
            ['name', 'price', 'parts'], 'create', 'Saving the combo…', 'Combo saved'), 'offers:write', 'dealsPage', ['POST', 'offers/combos']),
        $t(mcpDef('set_combo_active', 'Switch a combo on or off', 'Switch a combo on or off.',
            ['combo_id' => ['type' => 'integer'], 'active' => ['type' => 'boolean']], ['combo_id', 'active'], 'write', 'Updating the combo…', 'Combo updated'), 'offers:write', 'dealsPage', ['POST', 'offers/combos/{combo_id}/active']),
        $t(mcpDef('delete_combo', 'Delete a combo', 'Delete a combo for good.',
            ['combo_id' => ['type' => 'integer']], ['combo_id'], 'delete', 'Deleting the combo…', 'Combo deleted'), 'offers:write', 'dealsPage', ['POST', 'offers/combos/{combo_id}/delete']),
        $t(mcpDef('get_todays_special', "Today's special", "The dishes set as today's special, per weekday (0 = Sunday … 6 = Saturday, 7 = every day), with notes and daily limits.",
            [], [], 'read', 'Reading specials…', 'Specials loaded'), 'offers:read', 'todaysSpecialPage', ['GET', 'offers/todays-special']),
        $t(mcpDef('set_todays_special', "Set today's special", 'Replace the whole special list. Each entry: weekday (0 = Sunday … 6 = Saturday, 7 = every day), menu_item_id, optional note and daily_limit. At most ' . (defined('TODAYS_SPECIAL_MAX') ? TODAYS_SPECIAL_MAX : 6) . ' dishes per day. An empty list clears it.',
            ['specials' => ['type' => 'array', 'maxItems' => 60, 'items' => ['type' => 'object', 'properties' => ['weekday' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 7], 'menu_item_id' => ['type' => 'integer'],
                'note' => ['type' => 'string', 'maxLength' => 160], 'daily_limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9999]], 'required' => ['weekday', 'menu_item_id']]]],
            ['specials'], 'write', 'Saving specials…', 'Specials saved'), 'offers:write', 'todaysSpecialPage', ['POST', 'offers/todays-special']),
        $t(mcpDef('get_scratch_card', 'Scratch card settings', 'Scratch card (prize after an order) settings and prizes.',
            [], [], 'read', 'Reading scratch card…', 'Scratch card loaded'), 'offers:read', 'scratchPage', ['GET', 'offers/scratch-card']),
        $t(mcpDef('set_scratch_card', 'Change the scratch card', 'Change scratch card settings; only the fields you pass change. prizes (if given) replaces all prizes: type flat (money off), percent or none (try again), value, weight (chance, 1-1000), optional min_order. Up to 8 prizes; switching it on needs at least one.',
            ['enabled' => ['type' => 'boolean'], 'title' => ['type' => 'string', 'maxLength' => 60], 'min_order' => ['type' => 'number', 'minimum' => 0, 'description' => 'Order total that earns a card.'],
             'next_min_order' => ['type' => 'number', 'minimum' => 0, 'description' => 'Minimum next order to use the prize.'], 'valid_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90],
             'monthly_budget' => ['type' => 'number', 'minimum' => 0, 'description' => '0 = no limit.'],
             'prizes' => ['type' => 'array', 'maxItems' => 8, 'items' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['flat', 'percent', 'none']], 'value' => ['type' => 'number', 'minimum' => 0],
                'weight' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000], 'min_order' => ['type' => 'number', 'minimum' => 0]], 'required' => ['type', 'weight']]]],
            [], 'write', 'Saving the scratch card…', 'Scratch card saved'), 'offers:write', 'scratchPage', ['POST', 'offers/scratch-card']),
        $t(mcpDef('get_wallet_settings', 'Wallet settings', 'Prepaid wallet settings: on/off, name, top-up limits, quick amounts, bonus tiers and cashback. No customer balances.',
            [], [], 'read', 'Reading wallet settings…', 'Wallet settings loaded'), 'offers:read', 'walletPage', ['GET', 'offers/wallet']),
        $t(mcpDef('set_wallet_settings', 'Change wallet settings', 'Change prepaid wallet settings; only the fields you pass change. bonus_tiers: [{amount, bonus}] (top up amount, get bonus extra; bonus below amount). quick_amounts: up to 6 top-up buttons.',
            ['enabled' => ['type' => 'boolean'], 'name' => ['type' => 'string', 'maxLength' => 40], 'min_topup' => ['type' => 'number', 'minimum' => 1], 'max_topup' => ['type' => 'number', 'minimum' => 1],
             'max_balance' => ['type' => 'number', 'minimum' => 1], 'quick_amounts' => ['type' => 'array', 'maxItems' => 6, 'items' => ['type' => 'integer', 'minimum' => 1]],
             'bonus_tiers' => ['type' => 'array', 'maxItems' => 6, 'items' => ['type' => 'object', 'properties' => ['amount' => ['type' => 'number'], 'bonus' => ['type' => 'number']], 'required' => ['amount', 'bonus']]],
             'cashback_pct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 50], 'cashback_max' => ['type' => 'number', 'minimum' => 0]],
            [], 'write', 'Saving wallet settings…', 'Wallet settings saved'), 'offers:write', 'walletPage', ['POST', 'offers/wallet']),
    ];
}

function offersRun(PDO $conn, array $ctx, string $name, array $a): array
{
    $rid = $ctx['rid'];
    $m = fn($n) => mcpMoney($ctx, $n);
    $log = fn(string $action, string $summary, $entity = null, $id = null) => logActivity($conn, $rid, $action, $summary, ['via' => $ctx['app']], $entity, $id, mcpActor($ctx));
    $date = function ($v, string $label): ?string {
        $v = trim((string)$v);
        if ($v === '') return null;
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) throw new McpToolError("$label must be a date like 2026-12-31.");
        return $v;
    };

    switch ($name) {
        case 'list_coupons':
            ofEnsureCoupons($conn);
            $st = $conn->prepare("SELECT id, coupon_code, discount_type, discount_value, minimum_order_amount, max_uses, current_uses, valid_from, valid_until, is_active, description, is_milestone
                FROM coupons WHERE restaurant_id = ? AND (owner_phone IS NULL OR owner_phone = '') ORDER BY id DESC");
            $st->execute([$rid]);
            $rows = array_map(fn($r) => ['coupon_id' => (int)$r['id'], 'code' => $r['coupon_code'], 'type' => $r['discount_type'], 'value' => (float)$r['discount_value'],
                'min_order' => (float)$r['minimum_order_amount'], 'max_uses' => (int)$r['max_uses'], 'used' => (int)$r['current_uses'], 'valid_from' => $r['valid_from'], 'valid_until' => $r['valid_until'],
                'active' => (int)$r['is_active'] === 1, 'milestone' => (int)$r['is_milestone'] === 1, 'description' => (string)$r['description']], $st->fetchAll(PDO::FETCH_ASSOC));
            $txt = $rows ? implode("\n", array_map(fn($c) => $c['coupon_id'] . '. ' . $c['code'] . ' · ' . ($c['type'] === 'percent' ? $c['value'] . '%' : $m($c['value'])) . ' off'
                . ($c['min_order'] > 0 ? ' over ' . $m($c['min_order']) : '') . ' · used ' . $c['used'] . ($c['max_uses'] ? '/' . $c['max_uses'] : '') . ($c['valid_until'] ? ' · until ' . $c['valid_until'] : '') . ($c['active'] ? '' : ' (OFF)'), $rows)) : 'No coupons yet.';
            return [$txt, ['currency' => $ctx['settings']['currency'], 'coupons' => $rows]];

        case 'save_coupon':
            ofEnsureCoupons($conn);
            $id = (int)($a['coupon_id'] ?? 0);
            $cur = ['coupon_code' => '', 'discount_type' => 'percent', 'discount_value' => 0, 'minimum_order_amount' => 0, 'max_uses' => 0, 'valid_from' => null, 'valid_until' => null, 'description' => '', 'is_milestone' => 0, 'is_active' => 1];
            if ($id) {
                $st = $conn->prepare("SELECT * FROM coupons WHERE id = ? AND restaurant_id = ? AND (owner_phone IS NULL OR owner_phone = '')");
                $st->execute([$id, $rid]);
                $cur = $st->fetch(PDO::FETCH_ASSOC);
                if (!$cur) throw new McpToolError('That coupon does not exist. Use list_coupons.');
            }
            $code = array_key_exists('code', $a) ? strtoupper(trim((string)$a['code'])) : $cur['coupon_code'];
            if (!preg_match(OF_COUPON_RE, $code)) throw new McpToolError('Coupon code: use 2–30 letters, numbers, - or _ (no spaces).');
            $type = $a['type'] ?? $cur['discount_type'];
            if (!in_array($type, ['percent', 'flat'], true)) throw new McpToolError('type must be percent or flat.');
            $value = (float)($a['value'] ?? $cur['discount_value']);
            if ($value <= 0) throw new McpToolError('Give the discount value (more than 0).');
            if ($type === 'percent' && $value > 100) throw new McpToolError('A percentage discount cannot be more than 100%.');
            $min = (float)($a['min_order'] ?? $cur['minimum_order_amount']);
            $uses = (int)($a['max_uses'] ?? $cur['max_uses']);
            if ($min < 0 || $uses < 0) throw new McpToolError('Minimum order and max uses cannot be negative.');
            $from = array_key_exists('valid_from', $a) ? $date($a['valid_from'], 'valid_from') : $cur['valid_from'];
            $until = array_key_exists('valid_until', $a) ? $date($a['valid_until'], 'valid_until') : $cur['valid_until'];
            if ($from && $until && $from > $until) throw new McpToolError('valid_from must be on or before valid_until.');
            $milestone = array_key_exists('milestone', $a) ? (!empty($a['milestone']) ? 1 : 0) : (int)$cur['is_milestone'];
            if ($milestone && $min <= 0) throw new McpToolError('A milestone coupon needs a minimum order (min_order).');
            $desc = array_key_exists('description', $a) ? mb_substr(trim((string)$a['description']), 0, 255) : (string)$cur['description'];
            $active = array_key_exists('active', $a) ? (!empty($a['active']) ? 1 : 0) : (int)$cur['is_active'];
            $dup = $conn->prepare('SELECT id FROM coupons WHERE restaurant_id = ? AND coupon_code = ? AND id <> ?');
            $dup->execute([$rid, $code, $id]);
            if ($dup->fetchColumn()) throw new McpToolError("Coupon code $code already exists.");
            $vals = [$code, $type, $value, $min, $uses, $from, $until, $desc, $milestone, $active];
            if ($id) {
                $conn->prepare('UPDATE coupons SET coupon_code=?, discount_type=?, discount_value=?, minimum_order_amount=?, max_uses=?, valid_from=?, valid_until=?, description=?, is_milestone=?, is_active=? WHERE id=? AND restaurant_id=?')
                    ->execute(array_merge($vals, [$id, $rid]));
            } else {
                $conn->prepare('INSERT INTO coupons (coupon_code, discount_type, discount_value, minimum_order_amount, max_uses, valid_from, valid_until, description, is_milestone, is_active, restaurant_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute(array_merge($vals, [$rid]));
                $id = (int)$conn->lastInsertId();
            }
            $off = ($type === 'percent' ? $value . '%' : $m($value)) . ' off' . ($min > 0 ? ' over ' . $m($min) : '');
            $log(empty($a['coupon_id']) ? 'coupon.add' : 'coupon.edit', (empty($a['coupon_id']) ? 'Created' : 'Edited') . " coupon $code ($off)", 'coupon', $id);
            return ["✅ Coupon $code: $off" . ($until ? ", until $until" : '') . ($active ? '' : ' (switched off)') . '.', ['coupon_id' => $id, 'code' => $code]];

        case 'delete_coupon':
            ofEnsureCoupons($conn);
            $st = $conn->prepare("SELECT coupon_code FROM coupons WHERE id = ? AND restaurant_id = ? AND (owner_phone IS NULL OR owner_phone = '')");
            $st->execute([(int)($a['coupon_id'] ?? 0), $rid]);
            $code = $st->fetchColumn();
            if ($code === false) throw new McpToolError('That coupon does not exist. Use list_coupons.');
            $conn->prepare('DELETE FROM coupons WHERE id = ? AND restaurant_id = ?')->execute([(int)$a['coupon_id'], $rid]);
            $log('coupon.delete', "Deleted coupon $code", 'coupon', (int)$a['coupon_id']);
            return ["🗑️ Deleted coupon $code.", ['coupon_id' => (int)$a['coupon_id'], 'deleted' => true]];

        case 'list_combos':
            require_once __DIR__ . '/../../config/combo_helpers.php';
            $combos = array_map(fn($c) => ofComboOut($c), getCombos($conn, $rid));
            $txt = $combos ? implode("\n", array_map(fn($c) => $c['combo_id'] . '. ' . $c['name'] . ' ' . $m($c['price']) . ' · ' . count($c['parts']) . ' part(s)' . ($c['active'] ? '' : ' (OFF)'), $combos)) : 'No combos yet.';
            return [$txt, ['currency' => $ctx['settings']['currency'], 'combos' => $combos]];

        case 'save_combo':
            require_once __DIR__ . '/../../config/combo_helpers.php';
            $in = ['id' => (int)($a['combo_id'] ?? 0), 'name' => $a['name'] ?? '', 'price' => $a['price'] ?? 0, 'description' => $a['description'] ?? '', 'badge' => $a['badge'] ?? '',
                'is_active' => array_key_exists('active', $a) ? !empty($a['active']) : true, 'valid_from' => $a['valid_from'] ?? '', 'valid_until' => $a['valid_until'] ?? '',
                'days' => $a['days'] ?? '1234567', 'time_from' => $a['time_from'] ?? '', 'time_to' => $a['time_to'] ?? '',
                'slots' => array_map(fn($s) => ['title' => $s['title'] ?? '', 'qty' => $s['qty'] ?? 1, 'choices' => array_map(fn($o) => ['menu_item_id' => $o['menu_item_id'] ?? 0, 'variation' => $o['size'] ?? '', 'extra' => $o['extra_price'] ?? 0], (array)($s['choices'] ?? []))], (array)($a['parts'] ?? []))];
            if ($in['id']) {
                // keep the photo set in the dashboard
                $img = $conn->prepare('SELECT image_path FROM combos WHERE id = ? AND restaurant_id = ?');
                $img->execute([$in['id'], $rid]);
                $in['image'] = (string)$img->fetchColumn();
            }
            $id = saveCombo($conn, $rid, $in);
            $log('combo.save', (empty($a['combo_id']) ? 'Created' : 'Edited') . ' combo ' . trim((string)$in['name']) . ' (' . $m($in['price']) . ')', 'combo', $id);
            return ['✅ Combo ' . trim((string)$in['name']) . ' saved (' . $m($in['price']) . ').', ['combo_id' => $id]];

        case 'set_combo_active':
        case 'delete_combo':
            require_once __DIR__ . '/../../config/combo_helpers.php';
            ensureCombosTables($conn);
            $st = $conn->prepare('SELECT id, name FROM combos WHERE id = ? AND restaurant_id = ?');
            $st->execute([(int)($a['combo_id'] ?? 0), $rid]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) throw new McpToolError('That combo does not exist. Use list_combos.');
            if ($name === 'set_combo_active') {
                $conn->prepare('UPDATE combos SET is_active = ? WHERE id = ? AND restaurant_id = ?')->execute([!empty($a['active']) ? 1 : 0, $c['id'], $rid]);
                $log('combo.toggle', 'Combo ' . $c['name'] . (!empty($a['active']) ? ' switched on' : ' switched off'), 'combo', $c['id']);
                return ['✅ Combo ' . $c['name'] . (!empty($a['active']) ? ' is on.' : ' is off.'), ['combo_id' => (int)$c['id'], 'active' => !empty($a['active'])]];
            }
            $conn->beginTransaction();
            try {
                $slots = $conn->prepare('SELECT id FROM combo_slots WHERE combo_id = ?');
                $slots->execute([$c['id']]);
                $ids = $slots->fetchAll(PDO::FETCH_COLUMN);
                if ($ids) $conn->prepare('DELETE FROM combo_slot_options WHERE slot_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
                $conn->prepare('DELETE FROM combo_slots WHERE combo_id = ?')->execute([$c['id']]);
                $conn->prepare('DELETE FROM combos WHERE id = ? AND restaurant_id = ?')->execute([$c['id'], $rid]);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                throw $e;
            }
            $log('combo.delete', 'Deleted combo ' . $c['name'], 'combo', $c['id']);
            return ['🗑️ Deleted combo ' . $c['name'] . '.', ['combo_id' => (int)$c['id'], 'deleted' => true]];

        case 'get_todays_special':
            require_once __DIR__ . '/../../config/todays_special_helpers.php';
            ensureTodaysSpecialTable($conn);
            $st = $conn->prepare('SELECT t.weekday, t.menu_item_id, t.note, t.daily_limit, mi.item_name_en FROM todays_specials t JOIN menu_items mi ON mi.id = t.menu_item_id AND mi.restaurant_id = t.restaurant_id
                WHERE t.restaurant_id = ? ORDER BY t.weekday, t.position, t.id');
            $st->execute([$rid]);
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Every day'];
            $rows = array_map(fn($r) => ['weekday' => (int)$r['weekday'], 'day' => $days[(int)$r['weekday']] ?? '', 'menu_item_id' => (int)$r['menu_item_id'], 'dish' => $r['item_name_en'],
                'note' => (string)($r['note'] ?? ''), 'daily_limit' => $r['daily_limit'] ? (int)$r['daily_limit'] : null], $st->fetchAll(PDO::FETCH_ASSOC));
            return [$rows ? implode("\n", array_map(fn($r) => $r['day'] . ': ' . $r['dish'] . ($r['note'] ? ' (' . $r['note'] . ')' : ''), $rows)) : "No today's special set.", ['specials' => $rows]];

        case 'set_todays_special':
            require_once __DIR__ . '/../../config/todays_special_helpers.php';
            ensureTodaysSpecialTable($conn);
            $own = $conn->prepare('SELECT id FROM menu_items WHERE restaurant_id = ?');
            $own->execute([$rid]);
            $ownIds = array_flip(array_map('intval', $own->fetchAll(PDO::FETCH_COLUMN)));
            $rows = []; $perDay = [];
            foreach ((array)($a['specials'] ?? []) as $s) {
                $wd = (int)($s['weekday'] ?? -1);
                $item = (int)($s['menu_item_id'] ?? 0);
                if ($wd < 0 || $wd > 7) throw new McpToolError('weekday must be 0 (Sunday) to 6 (Saturday), or 7 for every day.');
                if (!isset($ownIds[$item])) throw new McpToolError("Dish $item is not on this menu. Use list_menu.");
                if (isset($perDay[$wd][$item])) continue;
                if (count($perDay[$wd] ?? []) >= TODAYS_SPECIAL_MAX) throw new McpToolError('At most ' . TODAYS_SPECIAL_MAX . ' special dishes per day.');
                $perDay[$wd][$item] = true;
                $note = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)($s['note'] ?? '')))), 0, 160);
                $limit = (int)($s['daily_limit'] ?? 0);
                $rows[] = [$wd, count($perDay[$wd]) - 1, $item, $note !== '' ? $note : null, $limit > 0 ? min($limit, 9999) : null];
            }
            $conn->beginTransaction();
            $conn->prepare('DELETE FROM todays_specials WHERE restaurant_id = ?')->execute([$rid]);
            $ins = $conn->prepare('INSERT INTO todays_specials (restaurant_id, weekday, position, menu_item_id, note, daily_limit) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($rows as $r) $ins->execute(array_merge([$rid], $r));
            $conn->commit();
            $log('offers.todays_special', "Set today's special (" . count($rows) . ' dish(es))');
            return ['✅ Saved ' . count($rows) . " today's special dish(es).", ['saved' => count($rows)]];

        case 'get_scratch_card':
            require_once __DIR__ . '/../../config/scratch_helpers.php';
            ensureScratchTables($conn);
            $s = getScratchSettings($conn, $rid);
            $prizes = array_map(fn($p) => ['type' => $p['type'], 'value' => (float)$p['value'], 'weight' => (int)$p['weight'], 'min_order' => $p['min_order'] !== null ? (float)$p['min_order'] : null], getScratchPrizes($conn, $rid));
            $out = ['enabled' => !empty($s['enabled']), 'title' => $s['title'] ?? null, 'min_order' => (float)($s['min_order'] ?? 0), 'next_min_order' => (float)($s['next_min_order'] ?? 0),
                'valid_days' => (int)($s['valid_days'] ?? 15), 'monthly_budget' => (float)($s['monthly_budget'] ?? 0), 'prizes' => $prizes];
            return ['Scratch card is ' . ($out['enabled'] ? 'on' : 'off') . ' with ' . count($prizes) . ' prize(s).', $out];

        case 'set_scratch_card':
            require_once __DIR__ . '/../../config/scratch_helpers.php';
            ensureScratchTables($conn);
            $s = getScratchSettings($conn, $rid);
            $prizes = null;
            if (array_key_exists('prizes', $a)) {
                $prizes = [];
                foreach ((array)$a['prizes'] as $i => $p) {
                    $type = $p['type'] ?? '';
                    $value = round((float)($p['value'] ?? 0), 2);
                    $weight = (int)($p['weight'] ?? 0);
                    if (!in_array($type, ['flat', 'percent', 'none'], true)) throw new McpToolError('Prize ' . ($i + 1) . ': type must be flat, percent or none.');
                    if ($weight < 1 || $weight > 1000) throw new McpToolError('Prize ' . ($i + 1) . ': weight must be 1 to 1000.');
                    if ($type === 'flat' && ($value <= 0 || $value > 100000)) throw new McpToolError('Prize ' . ($i + 1) . ': money off must be more than 0.');
                    if ($type === 'percent' && ($value <= 0 || $value > 100)) throw new McpToolError('Prize ' . ($i + 1) . ': percent must be 1 to 100.');
                    if ($type === 'none') $value = 0;
                    $min = round((float)($p['min_order'] ?? 0), 2);
                    $prizes[] = [$type, $value, $weight, $type !== 'none' && $min > 0 ? min($min, 1000000) : null];
                }
                if (count($prizes) > 8) throw new McpToolError('At most 8 prizes.');
            }
            $enabled = array_key_exists('enabled', $a) ? !empty($a['enabled']) : !empty($s['enabled']);
            $havePrizes = $prizes !== null ? count($prizes) : count(getScratchPrizes($conn, $rid));
            if ($enabled && !$havePrizes) throw new McpToolError('Add at least one prize to switch the scratch card on.');
            $num = fn($k, $def, $lo, $hi) => max($lo, min($hi, (float)($a[$k] ?? $s[$k] ?? $def)));
            $vals = [$enabled ? 1 : 0, array_key_exists('title', $a) ? (mb_substr(trim(strip_tags((string)$a['title'])), 0, 60) ?: null) : ($s['title'] ?? null),
                $num('min_order', 0, 0, 1000000), $num('next_min_order', 0, 0, 1000000), (int)$num('valid_days', 15, 1, 90), $num('monthly_budget', 0, 0, 10000000)];
            $conn->beginTransaction();
            // an upsert of these columns only: REPLACE (as the dashboard does) would reset game_style
            $conn->prepare('INSERT INTO scratch_settings (restaurant_id, enabled, title, min_order, next_min_order, valid_days, monthly_budget) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), title = VALUES(title), min_order = VALUES(min_order), next_min_order = VALUES(next_min_order), valid_days = VALUES(valid_days), monthly_budget = VALUES(monthly_budget)')
                ->execute(array_merge([$rid], $vals));
            if ($prizes !== null) {
                $conn->prepare('DELETE FROM scratch_prizes WHERE restaurant_id = ?')->execute([$rid]);
                $ins = $conn->prepare('INSERT INTO scratch_prizes (restaurant_id, type, value, weight, min_order, position) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($prizes as $i => $p) $ins->execute([$rid, $p[0], $p[1], $p[2], $p[3], $i]);
            }
            $conn->commit();
            $log('offers.scratch', 'Changed scratch card settings' . ($prizes !== null ? ' and prizes' : ''));
            return ['✅ Scratch card saved: ' . ($enabled ? 'on' : 'off') . ', ' . $havePrizes . ' prize(s).', ['enabled' => $enabled, 'prizes' => $havePrizes]];

        case 'get_wallet_settings':
            require_once __DIR__ . '/../../config/wallet_helpers.php';
            ensureWalletTables($conn);
            $w = ofWalletOut(getWalletSettings($conn, $rid));
            return ['Wallet is ' . ($w['enabled'] ? 'on' : 'off') . ': top-up ' . $m($w['min_topup']) . '–' . $m($w['max_topup']) . ($w['cashback_pct'] ? ', ' . $w['cashback_pct'] . '% cashback' : '') . '.', $w];

        case 'set_wallet_settings':
            require_once __DIR__ . '/../../config/wallet_helpers.php';
            ensureWalletTables($conn);
            $w = ofWalletOut(getWalletSettings($conn, $rid));
            $num = fn($v, $lo, $hi) => max($lo, min($hi, round((float)$v, 2)));
            $tiers = $w['bonus_tiers'];
            if (array_key_exists('bonus_tiers', $a)) {
                $tiers = [];
                foreach ((array)$a['bonus_tiers'] as $t) {
                    $am = $num($t['amount'] ?? 0, 0, 1000000); $b = $num($t['bonus'] ?? 0, 0, 100000);
                    if (!($am > 0 && $b > 0 && $b < $am)) throw new McpToolError('Each bonus tier needs amount > bonus > 0, e.g. {"amount":1000,"bonus":100}.');
                    $tiers[] = ['amount' => $am, 'bonus' => $b];
                }
                if (count($tiers) > 6) throw new McpToolError('At most 6 bonus tiers.');
            }
            $quick = array_key_exists('quick_amounts', $a) ? array_slice(array_values(array_unique(array_filter(array_map('intval', (array)$a['quick_amounts']), fn($v) => $v > 0))), 0, 6) : $w['quick_amounts'];
            $min = $num($a['min_topup'] ?? $w['min_topup'], 1, 100000);
            $max = max($min, $num($a['max_topup'] ?? $w['max_topup'], 1, 1000000));
            $bal = max($max, $num($a['max_balance'] ?? $w['max_balance'], 1, 10000000));
            $enabled = array_key_exists('enabled', $a) ? !empty($a['enabled']) : $w['enabled'];
            $wname = array_key_exists('name', $a) ? (mb_substr(trim(strip_tags((string)$a['name'])), 0, 40) ?: null) : $w['name'];
            $conn->prepare('REPLACE INTO wallet_settings (restaurant_id, enabled, name, min_topup, max_topup, max_balance, quick_amounts, bonus_tiers, cashback_pct, cashback_max) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$rid, $enabled ? 1 : 0, $wname, $min, $max, $bal, implode(',', $quick) ?: null, json_encode($tiers),
                    $num($a['cashback_pct'] ?? $w['cashback_pct'], 0, 50), $num($a['cashback_max'] ?? $w['cashback_max'], 0, 100000)]);
            $log('wallet.settings', 'Changed wallet settings');
            return ['✅ Wallet settings saved (' . ($enabled ? 'on' : 'off') . ').', ofWalletOut(getWalletSettings($conn, $rid))];
    }
    throw new McpToolError("Unknown tool: $name");
}

/** Coupons table and columns, as controllers/coupon_operations.php makes them. */
function ofEnsureCoupons(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try { $conn->query('SELECT id FROM coupons LIMIT 1'); } catch (PDOException $e) {
        $conn->exec("CREATE TABLE IF NOT EXISTS coupons (
            id INT AUTO_INCREMENT PRIMARY KEY, restaurant_id VARCHAR(10) NOT NULL, coupon_code VARCHAR(50) NOT NULL,
            discount_type ENUM('percent','flat') NOT NULL DEFAULT 'percent', discount_value DECIMAL(10,2) NOT NULL,
            minimum_order_amount DECIMAL(10,2) DEFAULT 0.00, max_uses INT DEFAULT 0, current_uses INT DEFAULT 0,
            valid_from DATE DEFAULT NULL, valid_until DATE DEFAULT NULL, is_active TINYINT(1) DEFAULT 1, description VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY unique_coupon (restaurant_id, coupon_code), INDEX idx_restaurant (restaurant_id)
        )");
    }
    require_once __DIR__ . '/../../config/coupon_helpers.php';
    require_once __DIR__ . '/../../config/schema_cache.php';
    rgEnsureOnce('ensureCouponColumns', $conn);
    ensureCouponMilestoneColumn($conn);
    ensureCouponOwnerColumn($conn);
}

function ofComboOut(array $c): array
{
    return ['combo_id' => (int)$c['id'], 'name' => $c['name'], 'price' => (float)$c['price'], 'description' => $c['description'] ?? null, 'badge' => $c['badge'] ?? null,
        'active' => !empty($c['is_active']), 'valid_from' => $c['valid_from'] ?? null, 'valid_until' => $c['valid_until'] ?? null, 'days' => $c['days'] ?? '1234567',
        'time_from' => isset($c['time_from']) ? substr((string)$c['time_from'], 0, 5) : null, 'time_to' => isset($c['time_to']) ? substr((string)$c['time_to'], 0, 5) : null,
        'parts' => array_map(fn($s) => ['title' => $s['title'] ?? '', 'qty' => (int)($s['qty'] ?? 1),
            'choices' => array_map(fn($o) => ['menu_item_id' => (int)($o['menu_item_id'] ?? 0), 'dish' => $o['name'] ?? null, 'size' => $o['variation'] ?? null, 'extra_price' => (float)($o['extra'] ?? 0)], (array)($s['choices'] ?? []))],
            (array)($c['slots'] ?? []))];
}

function ofWalletOut(array $w): array
{
    $quick = $w['quick_amounts'] ?? [];
    if (is_string($quick)) $quick = array_values(array_filter(array_map('intval', explode(',', $quick))));
    $tiers = $w['bonus_tiers'] ?? [];
    if (is_string($tiers)) $tiers = json_decode($tiers, true) ?: [];
    return ['enabled' => !empty($w['enabled']), 'name' => $w['name'] ?? null, 'min_topup' => (float)($w['min_topup'] ?? 100), 'max_topup' => (float)($w['max_topup'] ?? 10000),
        'max_balance' => (float)($w['max_balance'] ?? 20000), 'quick_amounts' => array_values(array_map('intval', (array)$quick)),
        'bonus_tiers' => array_values(array_map(fn($t) => ['amount' => (float)$t['amount'], 'bonus' => (float)$t['bonus']], (array)$tiers)),
        'cashback_pct' => (float)($w['cashback_pct'] ?? 0), 'cashback_max' => (float)($w['cashback_max'] ?? 0)];
}
