<?php
/**
 * Loyalty programme settings for the API and AI apps (mcp/tools.php loads this): the programme (points, cashback or
 * stamps) and its rules, referral rewards, the rewards catalogue and tiers. Same tables and checks as Loyalty Studio
 * (api/loyalty_admin.php section program, controllers/growth_operations.php). No customer data here: balances,
 * redemptions, segments and the autopilot "run" are left out on purpose.
 */

function loyaltyTools(): array
{
    $t = fn(array $def, string $perm, ?array $route) => ['def' => $def, 'perm' => $perm, 'page' => 'growthSettingsPage', 'route' => $route ? $route + [2 => 'Loyalty'] : null];
    return [
        $t(mcpDef('get_loyalty', 'Loyalty programme', 'The loyalty programme: on/off, type (points, cashback or stamps) and its rules, referral rewards, rewards catalogue and tiers. No customer data.',
            [], [], 'read', 'Reading the loyalty programme…', 'Loyalty loaded'), 'loyalty:read', ['GET', 'loyalty']),
        $t(mcpDef('update_loyalty', 'Change the loyalty programme', 'Change programme settings; only the fields you pass change. program_type: points (earn points per amount spent, redeem for money off), cashback (a % back into the wallet) or stamps (one stamp per order, a reward when the card is full).',
            ['enabled' => ['type' => 'boolean'], 'program_type' => ['type' => 'string', 'enum' => ['points', 'cashback', 'stamps']],
             'earn_points_per_amount' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Points earned for every earn_amount_threshold spent.'], 'earn_amount_threshold' => ['type' => 'number', 'minimum' => 0],
             'redeem_value_per_point' => ['type' => 'number', 'minimum' => 0, 'description' => 'Money one point is worth.'], 'min_redeem_points' => ['type' => 'integer', 'minimum' => 0],
             'points_expiry_days' => ['type' => 'integer', 'minimum' => 0, 'description' => '0 = never expire.'],
             'cashback_pct' => ['type' => 'number', 'minimum' => 0.5, 'maximum' => 50], 'cashback_max' => ['type' => 'number', 'minimum' => 0, 'description' => 'Most cashback per order, 0 = no limit.'],
             'stamp_goal' => ['type' => 'integer', 'minimum' => 2, 'maximum' => 20], 'stamp_min_order' => ['type' => 'number', 'minimum' => 0],
             'stamp_reward_kind' => ['type' => 'string', 'enum' => ['item', 'flat', 'percent']], 'stamp_reward_item_id' => ['type' => 'integer', 'description' => 'Free dish when kind is item.'],
             'stamp_reward_value' => ['type' => 'number', 'minimum' => 0, 'description' => 'Money or % off when kind is flat or percent.'], 'reward_valid_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365],
             'referral_enabled' => ['type' => 'boolean'], 'referrer_reward_points' => ['type' => 'integer', 'minimum' => 0], 'referred_reward_points' => ['type' => 'integer', 'minimum' => 0]],
            [], 'write', 'Saving the programme…', 'Programme saved'), 'loyalty:write', ['POST', 'loyalty']),
        $t(mcpDef('save_reward', 'Add or edit a reward', 'Rewards customers buy with points. New: menu_item_id and points_cost. Edit (reward_id): points_cost and/or active.',
            ['reward_id' => ['type' => 'integer'], 'menu_item_id' => ['type' => 'integer'], 'points_cost' => ['type' => 'integer', 'minimum' => 1], 'active' => ['type' => 'boolean']],
            [], 'create', 'Saving the reward…', 'Reward saved'), 'loyalty:write', ['POST', 'loyalty/rewards']),
        $t(mcpDef('delete_reward', 'Delete a reward', 'Delete a reward from the catalogue.',
            ['reward_id' => ['type' => 'integer']], ['reward_id'], 'delete', 'Deleting the reward…', 'Reward deleted'), 'loyalty:write', ['POST', 'loyalty/rewards/{reward_id}/delete']),
        $t(mcpDef('save_tier', 'Add or edit a tier', 'Customer tiers by total spend (e.g. Silver from 5,000, Gold from 15,000) with a points multiplier. No tier_id = new.',
            ['tier_id' => ['type' => 'integer'], 'name' => ['type' => 'string', 'maxLength' => 50], 'min_total_spent' => ['type' => 'number', 'minimum' => 0],
             'points_multiplier' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 9.99], 'icon' => ['type' => 'string', 'maxLength' => 30], 'position' => ['type' => 'integer', 'minimum' => 0]],
            [], 'create', 'Saving the tier…', 'Tier saved'), 'loyalty:write', ['POST', 'loyalty/tiers']),
        $t(mcpDef('delete_tier', 'Delete a tier', 'Delete a customer tier.',
            ['tier_id' => ['type' => 'integer']], ['tier_id'], 'delete', 'Deleting the tier…', 'Tier deleted'), 'loyalty:write', ['POST', 'loyalty/tiers/{tier_id}/delete']),
    ];
}

function loyaltyRun(PDO $conn, array $ctx, string $name, array $a): array
{
    require_once __DIR__ . '/../../config/growth_helpers.php';
    require_once __DIR__ . '/../../config/loyalty_engine.php';
    ensureGrowthSchema($conn);
    ensureLoyaltyEngineSchema($conn);
    $rid = $ctx['rid'];
    $m = fn($n) => mcpMoney($ctx, $n);
    $log = fn(string $action, string $summary) => logActivity($conn, $rid, $action, $summary, ['via' => $ctx['app']], null, null, mcpActor($ctx));

    switch ($name) {
        case 'get_loyalty':
            $out = lyOut($conn, $rid);
            $p = $out['program'];
            $how = $p['program_type'] === 'cashback' ? $p['cashback_pct'] . '% cashback' : ($p['program_type'] === 'stamps' ? $p['stamp_goal'] . ' stamps for a reward' : $p['earn_points_per_amount'] . ' point(s) per ' . $m($p['earn_amount_threshold']));
            return ['Loyalty is ' . ($p['enabled'] ? 'on' : 'off') . ": $how · " . count($out['rewards']) . ' reward(s), ' . count($out['tiers']) . ' tier(s).', $out];

        case 'update_loyalty':
            $g = getGrowthSettings($conn, $rid);   // also makes the row if it isn't there yet
            $raw = $conn->prepare('SELECT * FROM growth_settings WHERE restaurant_id = ?');
            $raw->execute([$rid]);
            $g = $raw->fetch(PDO::FETCH_ASSOC) ?: $g;
            $num = fn($k, $def, $lo, $hi) => max($lo, min($hi, round((float)($a[$k] ?? $g[$k] ?? $def), 2)));
            $type = $a['program_type'] ?? ($g['program_type'] ?? 'points');
            if (!in_array($type, LOYALTY_PROGRAM_TYPES, true)) throw new McpToolError('program_type must be points, cashback or stamps.');
            $kind = $a['stamp_reward_kind'] ?? ($g['stamp_reward_kind'] ?? 'item');
            if (!in_array($kind, ['item', 'flat', 'percent'], true)) throw new McpToolError('stamp_reward_kind must be item, flat or percent.');
            $item = (int)($a['stamp_reward_item_id'] ?? $g['stamp_reward_item_id'] ?? 0);
            if (array_key_exists('stamp_reward_item_id', $a) && $item) {
                $own = $conn->prepare('SELECT 1 FROM menu_items WHERE id = ? AND restaurant_id = ?');
                $own->execute([$item, $rid]);
                if (!$own->fetchColumn()) throw new McpToolError("Dish $item is not on this menu.");
            }
            if (isset($a['cashback_pct']) && ((float)$a['cashback_pct'] < 0.5 || (float)$a['cashback_pct'] > 50)) throw new McpToolError('cashback_pct must be 0.5 to 50.');
            $value = $num('stamp_reward_value', 0, 0, 100000);
            if ($type === 'stamps' && $kind === 'item' && !$item) throw new McpToolError('Stamps with a free dish: give stamp_reward_item_id.');
            if ($type === 'stamps' && $kind !== 'item' && $value <= 0) throw new McpToolError('Stamps with money or % off: give stamp_reward_value.');
            $enabled = array_key_exists('enabled', $a) ? (!empty($a['enabled']) ? 1 : 0) : (int)($g['loyalty_enabled'] ?? 0);
            $conn->prepare('UPDATE growth_settings SET loyalty_enabled = ?, program_type = ?, cashback_pct = ?, cashback_max = ?, stamp_goal = ?, stamp_min_order = ?,
                    stamp_reward_kind = ?, stamp_reward_item_id = ?, stamp_reward_value = ?, reward_valid_days = ? WHERE restaurant_id = ?')
                ->execute([$enabled, $type, $num('cashback_pct', 5, 0.5, 50), $num('cashback_max', 0, 0, 100000), (int)$num('stamp_goal', 6, 2, 20), $num('stamp_min_order', 0, 0, 100000),
                    $kind, $item ?: null, $value, (int)$num('reward_valid_days', 30, 1, 365), $rid]);
            // the points and referral fields: saveGrowthSettings() writes only the keys given
            $pts = [];
            foreach (['earn_points_per_amount' => 'int', 'earn_amount_threshold' => 'float', 'redeem_value_per_point' => 'float', 'min_redeem_points' => 'int', 'points_expiry_days' => 'int',
                      'referrer_reward_points' => 'int', 'referred_reward_points' => 'int'] as $k => $ty) {
                if (!array_key_exists($k, $a)) continue;
                if ((float)$a[$k] < 0) throw new McpToolError("$k cannot be negative.");
                $pts[$k] = $ty === 'int' ? (int)$a[$k] : (float)$a[$k];
            }
            if (array_key_exists('referral_enabled', $a)) $pts['referral_enabled'] = !empty($a['referral_enabled']) ? 1 : 0;
            if (array_key_exists('enabled', $a)) $pts['loyalty_enabled'] = $enabled;
            if ($pts) saveGrowthSettings($conn, $rid, $pts);
            loyaltySyncCashbackToWallet($conn, $rid, getGrowthSettings($conn, $rid));
            $log('loyalty.program', 'Changed the loyalty programme (' . $type . ($enabled ? ', on' : ', off') . ')');
            return ['✅ Loyalty programme saved: ' . $type . ', ' . ($enabled ? 'on' : 'off') . '.', lyOut($conn, $rid)['program']];

        case 'save_reward':
            $id = (int)($a['reward_id'] ?? 0);
            if ($id) {
                $st = $conn->prepare('SELECT item_name, points_cost, is_active FROM loyalty_rewards WHERE id = ? AND restaurant_id = ?');
                $st->execute([$id, $rid]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if (!$r) throw new McpToolError('That reward does not exist. Use get_loyalty.');
                $cost = (int)($a['points_cost'] ?? $r['points_cost']);
                if ($cost <= 0) throw new McpToolError('points_cost must be more than 0.');
                $active = array_key_exists('active', $a) ? (!empty($a['active']) ? 1 : 0) : (int)$r['is_active'];
                $conn->prepare('UPDATE loyalty_rewards SET points_cost = ?, is_active = ? WHERE id = ? AND restaurant_id = ?')->execute([$cost, $active, $id, $rid]);
                $log('loyalty.reward', 'Edited reward ' . $r['item_name'] . " ($cost points)");
                return ['✅ Reward ' . $r['item_name'] . ": $cost points" . ($active ? '' : ' (off)') . '.', ['reward_id' => $id]];
            }
            $cost = (int)($a['points_cost'] ?? 0);
            if ($cost <= 0) throw new McpToolError('A new reward needs menu_item_id and points_cost.');
            $st = $conn->prepare('SELECT item_name_en FROM menu_items WHERE id = ? AND restaurant_id = ?');
            $st->execute([(int)($a['menu_item_id'] ?? 0), $rid]);
            $dish = $st->fetchColumn();
            if (!$dish) throw new McpToolError('That dish is not on this menu. Use list_menu.');
            $conn->prepare('INSERT INTO loyalty_rewards (restaurant_id, menu_item_id, item_name, points_cost, is_active) VALUES (?, ?, ?, ?, ?)')
                ->execute([$rid, (int)$a['menu_item_id'], $dish, $cost, array_key_exists('active', $a) && !$a['active'] ? 0 : 1]);
            $id = (int)$conn->lastInsertId();
            $log('loyalty.reward', "Added reward $dish ($cost points)");
            return ["✅ Added reward $dish for $cost points.", ['reward_id' => $id]];

        case 'delete_reward':
            $st = $conn->prepare('SELECT item_name FROM loyalty_rewards WHERE id = ? AND restaurant_id = ?');
            $st->execute([(int)($a['reward_id'] ?? 0), $rid]);
            $nm = $st->fetchColumn();
            if ($nm === false) throw new McpToolError('That reward does not exist. Use get_loyalty.');
            $conn->prepare('DELETE FROM loyalty_rewards WHERE id = ? AND restaurant_id = ?')->execute([(int)$a['reward_id'], $rid]);
            $log('loyalty.reward', "Deleted reward $nm");
            return ["🗑️ Deleted reward $nm.", ['reward_id' => (int)$a['reward_id'], 'deleted' => true]];

        case 'save_tier':
            $id = (int)($a['tier_id'] ?? 0);
            $cur = ['tier_name' => '', 'min_total_spent' => 0, 'icon' => 'star', 'sort_order' => 0, 'points_multiplier' => 1];
            if ($id) {
                $st = $conn->prepare('SELECT tier_name, min_total_spent, icon, sort_order, points_multiplier FROM loyalty_tiers WHERE id = ? AND restaurant_id = ?');
                $st->execute([$id, $rid]);
                $cur = $st->fetch(PDO::FETCH_ASSOC);
                if (!$cur) throw new McpToolError('That tier does not exist. Use get_loyalty.');
            }
            $nm = array_key_exists('name', $a) ? mb_substr(trim((string)$a['name']), 0, 50) : $cur['tier_name'];
            if ($nm === '') throw new McpToolError('The tier needs a name.');
            $min = (float)($a['min_total_spent'] ?? $cur['min_total_spent']);
            if ($min < 0) throw new McpToolError('min_total_spent cannot be negative.');
            $mult = (float)($a['points_multiplier'] ?? $cur['points_multiplier']);
            if ($mult < 0.1 || $mult > 9.99) throw new McpToolError('points_multiplier must be 0.1 to 9.99.');
            $vals = [$nm, $min, mb_substr(trim((string)($a['icon'] ?? $cur['icon'])), 0, 30) ?: 'star', (int)($a['position'] ?? $cur['sort_order']), $mult];
            if ($id) $conn->prepare('UPDATE loyalty_tiers SET tier_name=?, min_total_spent=?, icon=?, sort_order=?, points_multiplier=? WHERE id=? AND restaurant_id=?')->execute(array_merge($vals, [$id, $rid]));
            else { $conn->prepare('INSERT INTO loyalty_tiers (tier_name, min_total_spent, icon, sort_order, points_multiplier, restaurant_id) VALUES (?,?,?,?,?,?)')->execute(array_merge($vals, [$rid])); $id = (int)$conn->lastInsertId(); }
            $log('loyalty.tier', "Saved tier $nm (from " . $m($min) . ", ×$mult points)");
            return ["✅ Tier $nm: from " . $m($min) . " spent, ×$mult points.", ['tier_id' => $id]];

        case 'delete_tier':
            $st = $conn->prepare('SELECT tier_name FROM loyalty_tiers WHERE id = ? AND restaurant_id = ?');
            $st->execute([(int)($a['tier_id'] ?? 0), $rid]);
            $nm = $st->fetchColumn();
            if ($nm === false) throw new McpToolError('That tier does not exist. Use get_loyalty.');
            $conn->prepare('DELETE FROM loyalty_tiers WHERE id = ? AND restaurant_id = ?')->execute([(int)$a['tier_id'], $rid]);
            $log('loyalty.tier', "Deleted tier $nm");
            return ["🗑️ Deleted tier $nm.", ['tier_id' => (int)$a['tier_id'], 'deleted' => true]];
    }
    throw new McpToolError("Unknown tool: $name");
}

function lyOut(PDO $conn, string $rid): array
{
    getGrowthSettings($conn, $rid);
    $st = $conn->prepare('SELECT * FROM growth_settings WHERE restaurant_id = ?');
    $st->execute([$rid]);
    $g = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $f = fn($k, $d = 0) => isset($g[$k]) ? (float)$g[$k] : $d;
    $i = fn($k, $d = 0) => isset($g[$k]) ? (int)$g[$k] : $d;
    $program = ['enabled' => !empty($g['loyalty_enabled']), 'program_type' => $g['program_type'] ?? 'points',
        'earn_points_per_amount' => $i('earn_points_per_amount'), 'earn_amount_threshold' => $f('earn_amount_threshold'), 'redeem_value_per_point' => $f('redeem_value_per_point'),
        'min_redeem_points' => $i('min_redeem_points'), 'points_expiry_days' => $i('points_expiry_days'),
        'cashback_pct' => $f('cashback_pct', 5), 'cashback_max' => $f('cashback_max'),
        'stamp_goal' => $i('stamp_goal', 6), 'stamp_min_order' => $f('stamp_min_order'), 'stamp_reward_kind' => $g['stamp_reward_kind'] ?? 'item',
        'stamp_reward_item_id' => !empty($g['stamp_reward_item_id']) ? (int)$g['stamp_reward_item_id'] : null, 'stamp_reward_value' => $f('stamp_reward_value'), 'reward_valid_days' => $i('reward_valid_days', 30),
        'referral_enabled' => !empty($g['referral_enabled']), 'referrer_reward_points' => $i('referrer_reward_points'), 'referred_reward_points' => $i('referred_reward_points')];
    $rw = $conn->prepare('SELECT id, menu_item_id, item_name, points_cost, is_active FROM loyalty_rewards WHERE restaurant_id = ? ORDER BY points_cost');
    $rw->execute([$rid]);
    $tr = $conn->prepare('SELECT id, tier_name, min_total_spent, icon, sort_order, points_multiplier FROM loyalty_tiers WHERE restaurant_id = ? ORDER BY min_total_spent');
    $tr->execute([$rid]);
    return ['program' => $program,
        'rewards' => array_map(fn($r) => ['reward_id' => (int)$r['id'], 'menu_item_id' => (int)$r['menu_item_id'], 'dish' => $r['item_name'], 'points_cost' => (int)$r['points_cost'], 'active' => (int)$r['is_active'] === 1], $rw->fetchAll(PDO::FETCH_ASSOC)),
        'tiers' => array_map(fn($t) => ['tier_id' => (int)$t['id'], 'name' => $t['tier_name'], 'min_total_spent' => (float)$t['min_total_spent'], 'points_multiplier' => (float)$t['points_multiplier'], 'icon' => $t['icon'], 'position' => (int)$t['sort_order']], $tr->fetchAll(PDO::FETCH_ASSOC))];
}
