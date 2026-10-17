<?php
/**
 * Section access per restaurant, set by Super Admin (Restaurants › Access).
 *
 * Every dashboard section that can be switched off is listed in accessModules(), grouped the way the
 * sidebar groups them. A restaurant stores only what is OFF (restaurant_access.off_pages, a JSON list of
 * page ids), so a new restaurant, and every restaurant from before this existed, has everything on.
 *
 * - Dashboard (views/dashboard.php): switched-off pages vanish from the sidebar and can't be opened.
 * - Server: the APIs listed under a page answer 403 when that page is off (accessGuardRequest(), run by
 *   session_config.php). Only APIs used by that page alone are listed; shared ones (orders, menu,
 *   customers, KOT) stay open so other sections keep working.
 * Always on, never listed: Dashboard home, Menu, Orders, Settings, Profile, Help.
 */

if (defined('ACCESS_CONTROL_LOADED')) return;
define('ACCESS_CONTROL_LOADED', true);

/** key => [label, icon, what it is, [pageId => [label, [api file basenames]]]] */
function accessModules(): array {
    return [
        'pos' => ['POS billing', 'point_of_sale', 'Counter billing, held orders, POS customers', [
            'posPage' => ['POS', []],
        ]],
        'kitchen' => ['Kitchen (KOT)', 'soup_kitchen', 'Kitchen order tickets screen', [
            'kotPage' => ['KOT', []],
        ]],
        'online' => ['Online orders', 'shopping_bag', 'Website and app orders inbox', [
            'onlineOrdersPage' => ['Online orders', []],
        ]],
        'tables' => ['Tables & QR', 'table_restaurant', 'Areas, tables, QR codes, table map, waiter calls', [
            'areaPage'           => ['Areas', ['areas_studio.php']],
            'tablesPage'         => ['Tables', ['tables_studio.php']],
            'qrCodesPage'        => ['QR codes', []],
            'tableMapPage'       => ['Table map', []],
            'waiterRequestsPage' => ['Waiter requests', ['waiter_requests_overview.php']],
        ]],
        'reservations' => ['Reservations', 'event_seat', 'Table bookings', [
            'reservationsPage' => ['Reservations', ['reservation_operations.php']],
        ]],
        'inventory' => ['Inventory', 'inventory_2', 'Stock, recipes, purchases', [
            'inventoryPage' => ['Inventory', ['inventory_studio.php', 'inventory_operations.php', 'get_inventory.php', 'get_inventory_transactions.php']],
        ]],
        'customers' => ['Customers', 'group', 'Customer list and history', [
            'customersPage' => ['Customers', []],
        ]],
        'staff' => ['Staff', 'badge', 'Staff members and roles', [
            'staffPage' => ['Staff', ['staff_overview.php']],
        ]],
        'payments' => ['Payments', 'payments', 'Payment records and gateway setup', [
            'paymentsPage' => ['Payments', ['payments_overview.php']],
            'gatewayPage'  => ['Payment gateway', []],
        ]],
        'reports' => ['Reports & analytics', 'monitoring', 'Sales reports and Analytics Studio', [
            'reportsPage'   => ['Reports', []],
            'analyticsPage' => ['Analytics', ['analytics_studio.php']],
        ]],
        'loyalty' => ['Loyalty & Growth', 'loyalty', 'Loyalty Studio and the whole Growth menu', [
            'growthSettingsPage'    => ['Loyalty Studio', ['loyalty_admin.php', 'growth_operations.php']],
            'whatsappPage'          => ['WhatsApp', ['whatsapp_admin.php']],
            'waOrderPage'           => ['WhatsApp ordering', ['wa_order_admin.php']],
            'ownerManagerPage'      => ['AI manager', ['owner_manager_admin.php']],
            'customerSegmentsPage'  => ['Customer segments', ['get_customer_segments.php']],
            'referralsPage'         => ['Referrals', ['get_referrals.php']],
            'growthReportsPage'     => ['Growth reports', ['get_growth_reports.php', 'get_growth_overview.php', 'get_growth_clv.php']],
        ]],
        'offers' => ['Offers', 'local_offer', 'Coupons, combos, specials, scratch card, wallet, catering', [
            'offersHomePage'    => ['All offers', ['offers_studio.php']],
            'couponsPage'       => ['Coupons', []],
            'offerFiltersPage'  => ['Offer filters', ['offer_filters.php']],
            'dealsPage'         => ['Combos & deals', []],
            'bestsellersPage'   => ['Bestsellers', []],
            'todaysSpecialPage' => ["Today's special", []],
            'upsellPage'        => ['Complete your meal', []],
            'scratchPage'       => ['Scratch card', ['scratch_admin.php']],
            'walletPage'        => ['Wallet', ['wallet_admin.php']],
            'cateringPage'      => ['Catering', []],
        ]],
        'marketing' => ['Marketing', 'campaign', 'Posters, videos, captions, calendar, designs', [
            'marketingPostersPage'  => ['Posters', ['marketing_studio.php']],
            'marketingVideosPage'   => ['Videos', []],
            'marketingCaptionsPage' => ['Captions', []],
            'marketingCalendarPage' => ['Calendar', []],
            'marketingDesignsPage'  => ['Designs', []],
        ]],
        'google' => ['Google & SEO', 'search', 'Google Business profile and SEO', [
            'googleBusinessPage' => ['Google Business', ['gbp.php']],
            'seoPage'            => ['SEO', ['seo.php', 'seo_sc.php']],
        ]],
        'delivery' => ['Delivery', 'delivery_dining', 'Deliveries, map, own riders', [
            'deliveryPage'    => ['Deliveries', []],
            'deliveryMapPage' => ['Delivery map', []],
            'ridersPage'      => ['Riders', ['delivery_dispatch.php']],
        ]],
        'feedback' => ['Feedback', 'reviews', 'Customer feedback', [
            'feedbackPage' => ['Feedback', ['get_feedback.php']],
        ]],
        'website' => ['Website design', 'web', 'Theme, policy pages, embed on another site', [
            'websiteThemePage' => ['Website theme', []],
            'policyPagesPage'  => ['Policy pages', []],
            'embedPage'        => ['Embed', ['save_embed_settings.php']],
        ]],
    ];
}

/** Ready-made sets for the Super Admin editor: key => [label, modules kept ON] (null = everything). */
function accessPresets(): array {
    return [
        'all'     => ['Everything', null],
        'pos'     => ['POS only', ['pos', 'kitchen', 'tables', 'customers', 'staff', 'payments', 'reports']],
        'online'  => ['Online ordering', ['online', 'kitchen', 'customers', 'payments', 'reports', 'offers', 'delivery', 'feedback', 'website', 'google']],
        'loyalty' => ['Loyalty & Growth only', ['loyalty', 'customers', 'reports', 'offers', 'feedback']],
    ];
}

/** Every page id that can be switched off. */
function accessAllPages(): array {
    $all = [];
    foreach (accessModules() as $m) foreach ($m[3] as $page => $_) $all[] = $page;
    return $all;
}

function accessEnsureTable(PDO $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS restaurant_access (
            restaurant_id VARCHAR(50) NOT NULL PRIMARY KEY,
            off_pages TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {}
}

/** Page ids switched OFF for a restaurant (restaurants.restaurant_id string). Empty = everything on. */
function accessOffPages(PDO $conn, string $restaurantId): array {
    static $cache = [];
    if ($restaurantId === '') return [];
    if (isset($cache[$restaurantId])) return $cache[$restaurantId];
    accessEnsureTable($conn);
    $off = [];
    try {
        $st = $conn->prepare('SELECT off_pages FROM restaurant_access WHERE restaurant_id = ?');
        $st->execute([$restaurantId]);
        $raw = $st->fetchColumn();
        $list = $raw ? json_decode((string)$raw, true) : [];
        if (is_array($list)) $off = array_values(array_intersect(accessAllPages(), $list));
    } catch (PDOException $e) {}
    return $cache[$restaurantId] = $off;
}

/**
 * Is this page on for the restaurant? Customer-facing features use it too, so a section Super Admin
 * switched off stops for customers as well (loyalty points, scratch card, wallet, WhatsApp gifts, …).
 */
function accessPageOn(PDO $conn, string $restaurantId, string $page): bool {
    try {
        return !in_array($page, accessOffPages($conn, $restaurantId), true);
    } catch (Throwable $e) {
        return true;
    }
}

/** Save the OFF list (unknown ids dropped). An empty list removes the row: everything on. */
function accessSaveOffPages(PDO $conn, string $restaurantId, array $off): array {
    accessEnsureTable($conn);
    $off = array_values(array_intersect(accessAllPages(), array_map('strval', $off)));
    if (!$off) {
        $conn->prepare('DELETE FROM restaurant_access WHERE restaurant_id = ?')->execute([$restaurantId]);
    } else {
        $conn->prepare('INSERT INTO restaurant_access (restaurant_id, off_pages) VALUES (?, ?) ON DUPLICATE KEY UPDATE off_pages = VALUES(off_pages)')
            ->execute([$restaurantId, json_encode($off)]);
    }
    return $off;
}

/** Which page an API file belongs to ('' when it is shared or not listed). */
function accessPageForApi(string $file): string {
    foreach (accessModules() as $m) foreach ($m[3] as $page => $p) if (in_array($file, $p[1], true)) return $page;
    return '';
}

/**
 * Run for every request through session_config.php: an admin/staff API whose page is switched off
 * for the logged-in restaurant answers 403 JSON. Cheap for everything else (no DB unless the file is listed).
 */
function accessGuardRequest(): void {
    if (PHP_SAPI === 'cli' || empty($_SESSION['restaurant_id'])) return;
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if (!preg_match('#/main/(api|controllers)/([a-z0-9_]+\.php)$#i', $script, $m)) return;
    $page = accessPageForApi($m[2]);
    if ($page === '' || !function_exists('getConnection')) return;
    try {
        $off = accessOffPages(getConnection(), (string)$_SESSION['restaurant_id']);
    } catch (Throwable $e) {
        return; // never lock a restaurant out because this check failed
    }
    if (!in_array($page, $off, true)) return;
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'access_off' => $page,
        'message' => 'This section is not switched on for your restaurant. Contact RestroGrow to turn it on.']);
    exit;
}
