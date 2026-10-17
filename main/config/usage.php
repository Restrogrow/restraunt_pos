<?php
/**
 * Feature usage per restaurant (Super Admin › Usage): what each restaurant really uses, what it only
 * opened and what it never touched.
 *
 * Two sources:
 *  - the restaurant's own data (orders by source, KOTs, dishes, loyalty points, WhatsApp sent, …): usFeatures()
 *    says which table and rows count for each feature, run as one grouped query per feature for all restaurants;
 *  - dashboard page opens, sent by the dashboard (assets/js/onboarding.js → api/usage_ping.php) into
 *    dashboard_usage (one row per restaurant, page, day and who: owner / staff).
 * A feature is "using" (data in the last 30 days), "used" (older data), "opened" (page opened, no data),
 * "never" or "off" (switched off in Access). Missing tables or columns on a database count as no data.
 */

if (defined('USAGE_LOADED')) return;
define('USAGE_LOADED', true);

function usEnsure(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS dashboard_usage (
            restaurant_id VARCHAR(50) NOT NULL,
            page VARCHAR(60) NOT NULL,
            day DATE NOT NULL,
            who VARCHAR(10) NOT NULL DEFAULT 'owner',
            opens INT NOT NULL DEFAULT 0,
            last_at DATETIME NOT NULL,
            PRIMARY KEY (restaurant_id, page, day, who),
            KEY idx_du_day (day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) { error_log('[usage] table: ' . $e->getMessage()); }
}

/** One dashboard page opened (a beacon from the dashboard). */
function usPing(PDO $conn, string $rid, string $page, string $who): void
{
    if (!preg_match('/^[a-zA-Z]{3,50}Page$/', $page)) return;
    usEnsure($conn);
    $conn->prepare("INSERT INTO dashboard_usage (restaurant_id, page, day, who, opens, last_at) VALUES (?, ?, CURDATE(), ?, 1, NOW())
        ON DUPLICATE KEY UPDATE opens = opens + 1, last_at = NOW()")->execute([$rid, $page, $who === 'staff' ? 'staff' : 'owner']);
}

/**
 * key => [label, group, icon, dashboard page ids (opens count for it), data: [table, date column, extra WHERE, what one row is]]
 * data null = only page opens tell (e.g. Reports, which stores nothing).
 */
function usFeatures(): array
{
    return [
        // selling
        'pos'          => ['POS billing', 'Selling', 'point_of_sale', ['posPage'], ['orders', 'created_at', "source = 'pos'", 'bills']],
        'website_ord'  => ['Website orders', 'Selling', 'shopping_bag', ['onlineOrdersPage'], ['orders', 'created_at', "source = 'website'", 'orders']],
        'qr_orders'    => ['Table QR orders', 'Selling', 'qr_code_2', ['qrCodesPage'], ['orders', 'created_at', "source = 'website' AND table_id IS NOT NULL", 'orders']],
        'wa_orders'    => ['WhatsApp orders (AI)', 'Selling', 'chat', ['waOrderPage'], ['wa_order_log', 'created_at', "direction = 'in'", 'messages']],
        'kot'          => ['Kitchen tickets (KOT)', 'Selling', 'soup_kitchen', ['kotPage'], ['kot', 'created_at', '', 'tickets']],
        'payments'     => ['Payments recorded', 'Selling', 'payments', ['paymentsPage', 'gatewayPage'], ['payments', 'created_at', '', 'payments']],
        'delivery'     => ['Deliveries', 'Selling', 'delivery_dining', ['deliveryPage', 'deliveryMapPage', 'ridersPage'], ['delivery_tracking', 'created_at', '', 'deliveries']],
        'catering'     => ['Catering requests', 'Selling', 'celebration', ['cateringPage'], ['catering_requests', 'created_at', '', 'requests']],
        'meal_plans'   => ['Meal plans', 'Selling', 'lunch_dining', ['mealPlansPage', 'mealSubscriptionsPage'], ['customer_meal_subscriptions', 'created_at', '', 'subscriptions']],
        // menu
        'menu'         => ['Menu dishes', 'Menu', 'restaurant_menu', ['menuItemsPage', 'menuPage'], ['menu_items', 'created_at', '', 'dishes']],
        'inventory'    => ['Inventory / stock', 'Menu', 'inventory_2', ['inventoryPage'], ['inventory_transactions', 'created_at', '', 'stock moves']],
        'combos'       => ['Combos', 'Menu', 'fastfood', ['dealsPage'], ['combos', 'created_at', '', 'combos']],
        'bestsellers'  => ['Bestsellers', 'Menu', 'star', ['bestsellersPage'], ['bestsellers', 'created_at', '', 'dishes']],
        'specials'     => ["Today's special", 'Menu', 'local_fire_department', ['todaysSpecialPage'], ['todays_specials', 'updated_at', '', 'specials']],
        'upsell'       => ['Complete your meal (upsell)', 'Menu', 'add_shopping_cart', ['upsellPage'], ['upsell_stats', 'day', 'adds > 0', 'days with add-ons']],
        'offer_filters'=> ['Offer filters', 'Menu', 'filter_alt', ['offerFiltersPage'], ['offer_filters', 'created_at', '', 'filters']],
        // tables
        'tables'       => ['Tables', 'Dine-in', 'table_restaurant', ['tablesPage', 'areaPage', 'tableMapPage'], ['tables', 'created_at', '', 'tables']],
        'reservations' => ['Table bookings', 'Dine-in', 'event_seat', ['reservationsPage'], ['reservations', 'created_at', '', 'bookings']],
        'waiter_calls' => ['Waiter calls', 'Dine-in', 'notifications_active', ['waiterRequestsPage'], ['waiter_requests', 'created_at', '', 'calls']],
        // customers and growth
        'customers'    => ['Customers', 'Customers & growth', 'group', ['customersPage'], ['customers', 'created_at', '', 'customers']],
        'loyalty'      => ['Loyalty points', 'Customers & growth', 'loyalty', ['growthSettingsPage'], ['loyalty_points_ledger', 'created_at', '', 'point entries']],
        'stamps'       => ['Stamp card', 'Customers & growth', 'approval', ['growthSettingsPage'], ['loyalty_stamp_ledger', 'created_at', '', 'stamps']],
        'rewards'      => ['Automatic gifts', 'Customers & growth', 'redeem', ['growthSettingsPage'], ['loyalty_rewards_issued', 'created_at', '', 'gifts']],
        'referrals'    => ['Referrals', 'Customers & growth', 'share', ['referralsPage'], ['referrals', 'created_at', '', 'referrals']],
        'vip'          => ['VIP membership', 'Customers & growth', 'workspace_premium', ['growthSettingsPage'], ['vip_members', 'created_at', '', 'members']],
        'scratch'      => ['Scratch card', 'Customers & growth', 'confirmation_number', ['scratchPage'], ['scratch_cards', 'created_at', '', 'cards']],
        'wallet'       => ['Wallet', 'Customers & growth', 'account_balance_wallet', ['walletPage'], ['wallet_ledger', 'created_at', '', 'entries']],
        'phone_card'   => ['Phone wallet card', 'Customers & growth', 'smartphone', [], ['wallet_cards', 'created_at', '', 'cards']],
        'coupons'      => ['Coupons', 'Customers & growth', 'sell', ['couponsPage', 'offersHomePage'], ['coupons', 'created_at', "COALESCE(owner_phone, '') = ''", 'coupons']],
        'wa_messages'  => ['WhatsApp messages', 'Customers & growth', 'forum', ['whatsappPage'], ['wa_outbox', 'created_at', "status = 'sent'", 'sent']],
        'wa_campaigns' => ['WhatsApp campaigns', 'Customers & growth', 'campaign', ['whatsappPage'], ['wa_campaigns', 'created_at', '', 'campaigns']],
        'ai_manager'   => ['AI manager report', 'Customers & growth', 'auto_awesome', ['ownerManagerPage'], ['om_briefs', 'created_at', '', 'reports']],
        'segments'     => ['Customer segments', 'Customers & growth', 'category', ['customerSegmentsPage'], null],
        // marketing and website
        'website'      => ['Website visits', 'Website & marketing', 'language', [], ['page_visits', 'visited_at', '', 'visits']],
        'theme'        => ['Website design', 'Website & marketing', 'palette', ['websiteThemePage'], ['website_settings', 'updated_at', '', 'saves']],
        'policies'     => ['Policy pages', 'Website & marketing', 'gavel', ['policyPagesPage'], ['policy_pages', 'updated_at', '', 'pages']],
        'marketing'    => ['Marketing designs', 'Website & marketing', 'image', ['marketingPostersPage', 'marketingVideosPage', 'marketingCaptionsPage', 'marketingCalendarPage', 'marketingDesignsPage'], ['marketing_designs', 'created_at', '', 'designs']],
        'gbp'          => ['Google Business', 'Website & marketing', 'storefront', ['googleBusinessPage'], ['gbp_connections', 'connected_at', '', 'connection']],
        'seo'          => ['SEO settings', 'Website & marketing', 'search', ['seoPage'], ['seo_settings', 'updated_at', '', 'saves']],
        'feedback'     => ['Customer feedback', 'Website & marketing', 'reviews', ['feedbackPage'], ['order_feedback', 'created_at', '', 'reviews']],
        // team and money
        'staff'        => ['Staff accounts', 'Team & money', 'badge', ['staffPage'], ['staff', 'created_at', '', 'staff']],
        'staff_logins' => ['Staff logins (Staff app / web)', 'Team & money', 'login', [], ['login_logs', 'created_at', "user_type = 'staff' AND outcome = 'success'", 'logins']],
        'expenses'     => ['Expenses', 'Team & money', 'receipt_long', [], ['expenses', 'created_at', "COALESCE(source, '') NOT IN ('inventory', 'purchase')", 'expenses']],
        'reports'      => ['Reports', 'Team & money', 'monitoring', ['reportsPage'], null],
        'analytics'    => ['Analytics', 'Team & money', 'insights', ['analyticsPage'], null],
    ];
}

/** Which dashboard page decides "switched off" for a feature (config/access_control.php). */
function usAccessPage(array $f): string { return $f[3][0] ?? ''; }

/**
 * Counts for every restaurant (or one): [rid => [feature => [n, n30, last]]], one grouped query per feature.
 * Restaurant ids in $only limit the work for the detail view.
 */
function usData(PDO $conn, ?string $only = null): array
{
    $out = [];
    foreach (usFeatures() as $key => $f) {
        if (!$f[4]) continue;
        [$table, $date, $where] = $f[4];
        $w = ['1 = 1'];
        $args = [];
        if ($where !== '') $w[] = $where;
        if ($only !== null) { $w[] = 'restaurant_id = ?'; $args[] = $only; }
        $sql = "SELECT restaurant_id, COUNT(*) n, SUM(`$date` >= NOW() - INTERVAL 30 DAY) n30, MAX(`$date`) last FROM `$table` WHERE " . implode(' AND ', $w) . ' GROUP BY restaurant_id';
        try {
            $st = $conn->prepare($sql);
            $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['restaurant_id']][$key] = [(int)$r['n'], (int)$r['n30'], $r['last']];
        } catch (Throwable $e) {
            // a table or column this database doesn't have: no data for that feature
        }
    }
    return $out;
}

/** Page opens: [rid => [page => [opens, opens30, last, staff opens]]]. */
function usOpens(PDO $conn, ?string $only = null): array
{
    usEnsure($conn);
    $out = [];
    $sql = 'SELECT restaurant_id, page, SUM(opens) n, SUM(CASE WHEN day >= CURDATE() - INTERVAL 30 DAY THEN opens ELSE 0 END) n30, MAX(last_at) last,
                   SUM(CASE WHEN who = \'staff\' THEN opens ELSE 0 END) staff
            FROM dashboard_usage' . ($only !== null ? ' WHERE restaurant_id = ?' : '') . ' GROUP BY restaurant_id, page';
    $st = $conn->prepare($sql);
    $st->execute($only !== null ? [$only] : []);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['restaurant_id']][$r['page']] = [(int)$r['n'], (int)$r['n30'], $r['last'], (int)$r['staff']];
    return $out;
}

/** Status of every feature for one restaurant, from usData() / usOpens() rows and its Access list. */
function usStatus(array $data, array $opens, array $off): array
{
    $rows = [];
    foreach (usFeatures() as $key => $f) {
        $d = $data[$key] ?? null;
        $o = [0, 0, null, 0];
        foreach ($f[3] as $pg) if (isset($opens[$pg])) {
            $o[0] += $opens[$pg][0]; $o[1] += $opens[$pg][1]; $o[3] += $opens[$pg][3];
            if ($opens[$pg][2] && (!$o[2] || $opens[$pg][2] > $o[2])) $o[2] = $opens[$pg][2];
        }
        $isOff = usAccessPage($f) !== '' && in_array(usAccessPage($f), $off, true);
        if ($isOff) $state = 'off';
        elseif ($d && $d[1] > 0) $state = 'using';
        elseif ($d && $d[0] > 0) $state = 'used';
        elseif (!$f[4] && $o[1] > 0) $state = 'using';      // pages that keep no data (reports): opened lately = using
        elseif ($o[0] > 0) $state = $f[4] ? 'opened' : 'used';
        else $state = 'never';
        $rows[$key] = ['key' => $key, 'label' => $f[0], 'group' => $f[1], 'icon' => $f[2], 'state' => $state,
            'n' => $d[0] ?? 0, 'n30' => $d[1] ?? 0, 'last' => $d[2] ?? null, 'unit' => $f[4][3] ?? '',
            'opens' => $o[0], 'opens30' => $o[1], 'opened_last' => $o[2], 'staff_opens' => $o[3]];
    }
    return $rows;
}

/** Share of available features used (using + used), 0–100. */
function usScore(array $status): int
{
    $avail = array_filter($status, fn($s) => $s['state'] !== 'off');
    if (!$avail) return 0;
    $used = count(array_filter($avail, fn($s) => in_array($s['state'], ['using', 'used'], true)));
    return (int)round($used / count($avail) * 100);
}
