<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

// Require login and permission to view dashboard (Manager role)
requireLogin();
requirePermission(PERMISSION_VIEW_DASHBOARD);

// Verify user is a Manager
if (!isset($_SESSION['staff_id']) || !isset($_SESSION['restaurant_id']) || $_SESSION['role'] !== ROLE_MANAGER) {
    header('Location: ../admin/login.php');
    exit();
}

$restaurant_id = $_SESSION['restaurant_id'];
$currency_symbol = $_SESSION['currency_symbol'] ?? null;
$timezone = $_SESSION['timezone'] ?? null;
$tax_name = $_SESSION['tax_name'] ?? null;
$tax_percent = $_SESSION['tax_percent'] ?? null;
$enable_gst = $_SESSION['enable_gst'] ?? null;
$conn = null;

// Settings the POS needs (currency, GST) + the restaurant's timezone for the live timers
if (!$currency_symbol || $timezone === null || $tax_name === null || $tax_percent === null || $enable_gst === null) {
    try {
        if (file_exists(__DIR__ . '/../db_connection.php')) {
            require_once __DIR__ . '/../db_connection.php';
            $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
            if ($conn) {
                $settingsStmt = $conn->prepare("SELECT currency_symbol, enable_gst, tax_name, tax_percent, timezone FROM users WHERE restaurant_id = ? LIMIT 1");
                $settingsStmt->execute([$restaurant_id]);
                $settingsRow = $settingsStmt->fetch(PDO::FETCH_ASSOC);
                if ($settingsRow && !empty($settingsRow['currency_symbol'])) {
                    require_once __DIR__ . '/../config/unicode_utils.php';
                    $db_currency = fixCurrencySymbol($settingsRow['currency_symbol']);
                    $currency_symbol = htmlspecialchars($db_currency, ENT_QUOTES, 'UTF-8');
                    $_SESSION['currency_symbol'] = $currency_symbol;
                }
                $enable_gst = $settingsRow ? (int)($settingsRow['enable_gst'] ?? 1) : 1;
                $tax_name = !empty($settingsRow['tax_name']) ? htmlspecialchars($settingsRow['tax_name'], ENT_QUOTES, 'UTF-8') : 'GST';
                $tax_percent = isset($settingsRow['tax_percent']) ? (float)$settingsRow['tax_percent'] : 5.00;
                $timezone = !empty($settingsRow['timezone']) ? $settingsRow['timezone'] : 'Asia/Kolkata';
                $_SESSION['enable_gst'] = $enable_gst;
                $_SESSION['tax_name'] = $tax_name;
                $_SESSION['tax_percent'] = $tax_percent;
                $_SESSION['timezone'] = $timezone;
            }
        }
    } catch (Exception $e) {
        // Ignore and fallback to default below
    }
}

if (!$currency_symbol) {
    $currency_symbol = '₹';
}
if ($timezone === null) {
    $timezone = 'Asia/Kolkata';
}
if ($tax_name === null) {
    $tax_name = 'GST';
}
if ($tax_percent === null) {
    $tax_percent = 5.00;
}
if ($enable_gst === null) {
    $enable_gst = 1;
}
require_once __DIR__ . '/../config/timezone_utils.php';
applyRestaurantTimezone($timezone, $conn);

$restaurant_name = $_SESSION['restaurant_name'] ?? 'Restaurant';
$staff_name = $_SESSION['username'] ?? 'Manager';
$name_parts = preg_split('/\s+/', trim($staff_name));
$first_name = $name_parts[0] ?? 'there';
$initials = strtoupper(mb_substr($name_parts[0] ?? 'M', 0, 1) . (count($name_parts) > 1 ? mb_substr(end($name_parts), 0, 1) : ''));
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$v = function ($f) { return @filemtime(__DIR__ . '/../assets/' . $f); };
?>
<!DOCTYPE html>
<html lang="en" class="st">
<head>
    <meta charset="UTF-8">
  <script src="../assets/js/quiet-console.js?v=<?php echo $v('js/quiet-console.js'); ?>"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <title>Manager – <?php echo htmlspecialchars($restaurant_name); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- style.css draws the POS (same as the owner dashboard); staff-ui.css draws everything around it -->
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo $v('css/style.css'); ?>">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
    <link rel="preload" href="<?php require_once __DIR__ . '/../config/icon_font.php'; echo htmlspecialchars(rgIconFontUrl(), ENT_QUOTES); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(rgIconFontUrl(), ENT_QUOTES); ?>"></noscript>
    <link rel="stylesheet" href="../assets/css/staff-ui.css?v=<?php echo $v('css/staff-ui.css'); ?>">

    <script>
        window.globalCurrencySymbol = <?php echo json_encode($currency_symbol, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
        window.enableGst = <?php echo json_encode((bool)$enable_gst, JSON_HEX_TAG); ?>;
        window.taxName = <?php echo json_encode($tax_name, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE); ?>;
        window.taxPercent = <?php echo json_encode((float)$tax_percent, JSON_HEX_TAG); ?>;
        window.restaurant_id = <?php echo json_encode($restaurant_id); ?>;
        window.STAFF = {
            role: 'Manager',
            restaurantId: <?php echo json_encode($restaurant_id); ?>,
            serverNow: <?php echo json_encode(date('Y-m-d H:i:s')); ?>,
            currency: window.globalCurrencySymbol
        };
    </script>
    <script src="../assets/js/sweetalert2.all.min.js" defer></script>
    <script src="../website/rg-notify.js?v=<?php echo @filemtime(__DIR__ . '/../website/rg-notify.js'); ?>"></script>
    <script>RGNotify.setup({ toast: 'card', watchSaves: true });</script>
    <script src="../assets/js/utils/currency.js?v=<?php echo $v('js/utils/currency.js'); ?>"></script>
    <script src="../assets/js/escpos.js?v=<?php echo $v('js/escpos.js'); ?>"></script>
    <script src="../assets/js/direct-printer.js?v=<?php echo $v('js/direct-printer.js'); ?>" defer></script>
    <script src="../assets/js/script.js?v=<?php echo $v('js/script.js'); ?>" defer></script>
    <script src="../assets/js/pos-discount.js?v=<?php echo $v('js/pos-discount.js'); ?>" defer></script>
    <style>
        html, body { -webkit-touch-callout: none; -webkit-user-select: none; user-select: none; }
        input, textarea, select { -webkit-user-select: text; user-select: text; }
    </style>
</head>
<body class="st-body st-has-tabbar">
    <header class="st-top">
        <div class="st-top-in">
            <div class="st-brand">
                <span class="st-logo mgr" aria-hidden="true"><span class="material-symbols-rounded">storefront</span></span>
                <div class="st-brand-t">
                    <b><?php echo htmlspecialchars($restaurant_name); ?></b>
                    <small>Manager · <?php echo htmlspecialchars($first_name); ?></small>
                </div>
            </div>
            <div class="st-top-r">
                <span class="st-live" id="stLive"><i></i><span>Connecting…</span></span>
                <button type="button" class="st-ibtn on" id="stSoundBtn" aria-pressed="true" aria-label="Sound on"><span class="material-symbols-rounded">campaign</span></button>
                <button type="button" class="st-alerts" id="enableAlertsBtn"><span class="material-symbols-rounded">notifications</span><span class="st-alerts-l">Turn on alerts</span></button>
                <div class="st-user">
                    <span class="st-av" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></span>
                    <div class="st-user-t"><b><?php echo htmlspecialchars($staff_name); ?></b><small>Manager</small></div>
                </div>
                <a class="st-ibtn" id="stLogout" href="../controllers/staff_logout.php" aria-label="Log out" title="Log out"><span class="material-symbols-rounded">logout</span></a>
            </div>
        </div>
    </header>

    <main class="st-wrap">
        <nav class="st-tabs" role="tablist" aria-label="Manager screens">
            <button type="button" class="st-tab" role="tab" data-tab="home"><span class="material-symbols-rounded">space_dashboard</span>Overview</button>
            <button type="button" class="st-tab" role="tab" data-tab="orders"><span class="material-symbols-rounded">receipt_long</span>Orders<em data-badge="orders"></em></button>
            <button type="button" class="st-tab" role="tab" data-tab="kitchen"><span class="material-symbols-rounded">skillet</span>Kitchen<em data-badge="kitchen"></em></button>
            <button type="button" class="st-tab" role="tab" data-tab="calls"><span class="material-symbols-rounded">notifications_active</span>Table calls<em data-badge="calls"></em></button>
            <button type="button" class="st-tab" role="tab" data-tab="tables"><span class="material-symbols-rounded">table_restaurant</span>Tables</button>
            <button type="button" class="st-tab" role="tab" data-tab="pos"><span class="material-symbols-rounded">point_of_sale</span>POS</button>
            <button type="button" class="st-tab" role="tab" data-tab="customers"><span class="material-symbols-rounded">group</span>Customers</button>
            <button type="button" class="st-tab" role="tab" data-tab="payments"><span class="material-symbols-rounded">payments</span>Payments</button>
            <button type="button" class="st-tab" role="tab" data-tab="reports"><span class="material-symbols-rounded">bar_chart</span>Reports</button>
        </nav>

        <!-- Overview -->
        <section id="view-home" class="st-view" aria-labelledby="homeTitle">
            <div class="st-head">
                <div class="st-head-l">
                    <span class="st-hello"><?php echo $greeting . ', ' . htmlspecialchars($first_name); ?> 👋</span>
                    <h1 id="homeTitle">Today at a glance</h1>
                </div>
                <div class="st-head-r"><button type="button" class="st-btn ghost" data-refresh><span class="material-symbols-rounded">refresh</span>Refresh</button></div>
            </div>
            <div class="st-stats">
                <button type="button" class="st-stat" data-tab="reports"><span class="st-ic" style="--c:#2563eb"><span class="material-symbols-rounded">payments</span></span><b id="todayRevenue"><?php echo htmlspecialchars($currency_symbol); ?>0</b><small>Today's sales</small><span class="st-delta" id="revDelta">&nbsp;</span></button>
                <button type="button" class="st-stat" data-tab="orders"><span class="st-ic" style="--c:#7c3aed"><span class="material-symbols-rounded">receipt_long</span></span><b id="todayOrders">0</b><small>Today's orders</small></button>
                <button type="button" class="st-stat" data-tab="orders"><span class="st-ic" style="--c:#f59e0b"><span class="material-symbols-rounded">hourglass_top</span></span><b id="inProgress">0</b><small>In progress</small></button>
                <button type="button" class="st-stat" data-tab="kitchen"><span class="st-ic" style="--c:#f97316"><span class="material-symbols-rounded">skillet</span></span><b id="activeKot">0</b><small>Kitchen tickets</small></button>
                <button type="button" class="st-stat" data-tab="tables"><span class="st-ic" style="--c:#0f766e"><span class="material-symbols-rounded">table_restaurant</span></span><b id="activeTables">0</b><small>Tables occupied</small></button>
                <button type="button" class="st-stat" data-tab="calls"><span class="st-ic" style="--c:#dc2626"><span class="material-symbols-rounded">notifications_active</span></span><b id="pendingRequests">0</b><small>Table calls waiting</small></button>
            </div>
            <div class="mg-grid">
                <div class="mg-col">
                    <div class="st-card">
                        <div class="st-card-h"><div><b>Orders in progress</b><small>Still being cooked, served or paid</small></div><button type="button" class="st-link" data-tab="orders">All orders<span class="material-symbols-rounded">chevron_right</span></button></div>
                        <div id="mgLive"><div class="st-skel h-row"></div><br><div class="st-skel h-row"></div></div>
                    </div>
                    <div class="st-card">
                        <div class="st-card-h"><div><b>Last 7 days</b><small>Sales <span id="mgWeekTotal"></span></small></div><button type="button" class="st-link" data-tab="reports">Reports<span class="material-symbols-rounded">chevron_right</span></button></div>
                        <div class="mg-bars" id="mgBars"></div>
                    </div>
                </div>
                <div class="mg-col">
                    <div class="st-card">
                        <div class="st-card-h"><div><b>Needs attention</b><small>Live from the floor</small></div></div>
                        <div id="mgAttention"><div class="st-skel h-row"></div></div>
                    </div>
                    <div class="st-card">
                        <div class="st-card-h"><div><b>Quick links</b><small>Everything a manager can do</small></div></div>
                        <div class="mg-links">
                            <button type="button" data-tab="orders"><span class="st-ic" style="--c:#7c3aed"><span class="material-symbols-rounded">receipt_long</span></span>Orders<em data-badge="orders"></em></button>
                            <button type="button" data-tab="kitchen"><span class="st-ic" style="--c:#f97316"><span class="material-symbols-rounded">skillet</span></span>Kitchen<em data-badge="kitchen"></em></button>
                            <button type="button" data-tab="calls"><span class="st-ic" style="--c:#dc2626"><span class="material-symbols-rounded">notifications_active</span></span>Table calls<em data-badge="calls"></em></button>
                            <button type="button" data-tab="tables"><span class="st-ic" style="--c:#0f766e"><span class="material-symbols-rounded">table_restaurant</span></span>Tables</button>
                            <button type="button" data-tab="pos"><span class="st-ic" style="--c:#2563eb"><span class="material-symbols-rounded">point_of_sale</span></span>POS</button>
                            <button type="button" data-tab="customers"><span class="st-ic" style="--c:#db2777"><span class="material-symbols-rounded">group</span></span>Customers</button>
                            <button type="button" data-tab="payments"><span class="st-ic" style="--c:#16a34a"><span class="material-symbols-rounded">payments</span></span>Payments</button>
                            <button type="button" data-tab="reports"><span class="st-ic" style="--c:#0891b2"><span class="material-symbols-rounded">bar_chart</span></span>Reports</button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Orders -->
        <section id="view-orders" class="st-view" aria-labelledby="ordersTitle">
            <div class="st-head">
                <div class="st-head-l"><span class="st-hello">Every order placed today</span><h1 id="ordersTitle">Orders</h1></div>
                <div class="st-head-r">
                    <label class="st-search"><span class="material-symbols-rounded">search</span><input type="search" id="orderSearch" placeholder="Order #, table or customer" aria-label="Search orders"></label>
                    <button type="button" class="st-btn ghost" data-refresh><span class="material-symbols-rounded">refresh</span>Refresh</button>
                </div>
            </div>
            <div class="st-chips" id="orderChips" style="margin-bottom:12px"></div>
            <div class="st-card">
                <table class="mg-table" id="ordersTable">
                    <thead><tr><th>Order</th><th>Table</th><th class="r">Items</th><th class="r">Amount</th><th>Status</th><th class="r"><span class="st-sr">Action</span></th></tr></thead>
                    <tbody id="ordersTbody"></tbody>
                </table>
            </div>
        </section>

        <!-- Kitchen -->
        <section id="view-kitchen" class="st-view" aria-labelledby="kitchenTitle">
            <div class="st-head">
                <div class="st-head-l"><span class="st-hello">Live kitchen tickets – same board the chef sees</span><h1 id="kitchenTitle">Kitchen</h1></div>
                <div class="st-head-r"><span class="st-updated" id="kitchenUpdated"></span><button type="button" class="st-btn ghost" data-refresh><span class="material-symbols-rounded">refresh</span>Refresh</button></div>
            </div>
            <nav class="kds-stages" id="mgStages" aria-label="Show tickets"></nav>
            <div class="kds-board" id="mgBoard"></div>
        </section>

        <!-- Table calls -->
        <section id="view-calls" class="st-view" aria-labelledby="callsTitle">
            <div class="st-head">
                <div class="st-head-l"><span class="st-hello">Guests who tapped “Call waiter”</span><h1 id="callsTitle">Table calls</h1></div>
                <div class="st-head-r"><span class="st-updated" id="callsUpdated"></span><button type="button" class="st-btn ghost" data-refresh><span class="material-symbols-rounded">refresh</span>Refresh</button></div>
            </div>
            <div class="st-call-sum" id="callsSummary" aria-live="polite"></div>
            <div id="mgCallsList"></div>
        </section>

        <!-- Tables -->
        <section id="view-tables" class="st-view" aria-labelledby="tablesTitle">
            <div class="st-head">
                <div class="st-head-l"><span class="st-hello">Who is sitting where</span><h1 id="tablesTitle">Tables</h1></div>
                <div class="st-head-r"><span class="st-call-sum" id="tablesSummary" style="margin:0"></span><button type="button" class="st-btn ghost" data-refresh><span class="material-symbols-rounded">refresh</span>Refresh</button></div>
            </div>
            <div class="st-card" id="mgTables"><div class="st-skel h-card"></div></div>
        </section>

        <!-- POS (same markup as the waiter screen; script.js drives it) -->
        <section id="view-pos" class="st-view" aria-labelledby="posTitle">
            <div id="posTab">
            <div class="st-head">
                <div class="st-head-l"><span class="st-hello">Process orders and manage transactions</span><h1 id="posTitle">Point of sale</h1></div>
            </div>
            <div class="st-pos">
                <div class="pos-content">
                    <div class="pos-container">
                        <!-- Left Side - Menu Items -->
                        <div class="pos-menu-section">
                            <div class="pos-filters">
                                <select id="posMenuFilter" class="filter-select">
                                    <option value="">All Menus</option>
                                </select>
                                <select id="posCategoryFilter" class="filter-select">
                                    <option value="">All Categories</option>
                                </select>
                                <select id="posTypeFilter" class="filter-select">
                                    <option value="">All Types</option>
                                    <option value="Veg">Veg</option>
                                    <option value="Non Veg">Non Veg</option>
                                    <option value="Egg">Egg</option>
                                    <option value="Drink">Drink</option>
                                    <option value="Dessert">Dessert</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="pos-menu-items" id="posMenuItems">
                                <!-- Menu items will be loaded here -->
                                <div class="loading">Loading menu items...</div>
                            </div>
                        </div>
                        
                        <!-- Mobile Sticky Add Item Button -->
                        <button id="mobileAddItemBtn" class="mobile-add-item-btn" onclick="if(typeof openMobileAddItemModal === 'function') openMobileAddItemModal();" style="display: none;">
                            <span class="material-symbols-rounded">add</span>
                            <span>Add Item</span>
                        </button>

                        <!-- Right Side - Cart -->
                        <div class="pos-cart-section">
                            <div class="pos-cart-header">
                                <h3>Order Cart</h3>
                                <button class="btn-clear-cart" id="clearCartBtn">
                                    <span class="material-symbols-rounded">delete</span>
                                    Clear Cart
                                </button>
                            </div>
                            
                            <div class="pos-table-select">
                                <label for="selectPosTable">Select Table:</label>
                                <select id="selectPosTable" class="filter-select">
                                    <option value="">Walk-in</option>
                                </select>
                            </div>

                            <div class="pos-cart-items" id="posCartItems">
                                <div class="empty-cart">
                                    <span class="material-symbols-rounded">shopping_cart</span>
                                    <p>Cart is empty</p>
                                    <p class="empty-subtext">Add items from the menu</p>
                                </div>
                            </div>

                            <div class="pos-cart-summary">
                                <div class="cart-summary-row">
                                    <span>Subtotal:</span>
                                    <span id="cartSubtotal"><?php echo htmlspecialchars($currency_symbol); ?>0.00</span>
                                </div>
                                <?php if ($enable_gst): ?>
                                <div class="cart-summary-row">
                                    <span><?php echo htmlspecialchars($tax_name); ?> (<?php echo rtrim(rtrim(number_format((float)$tax_percent, 2), '0'), '.'); ?>%):</span>
                                    <span id="cartTax"><?php echo htmlspecialchars($currency_symbol); ?>0.00</span>
                                </div>
                                <?php endif; ?>
                                <div class="cart-summary-row total">
                                    <span>Total:</span>
                                    <span id="cartTotal"><?php echo htmlspecialchars($currency_symbol); ?>0.00</span>
                                </div>
                            </div>

                            <div class="pos-cart-actions pos-cart-actions-3">
                                <button class="btn btn-primary" id="processPaymentBtn">
                                    <span class="material-symbols-rounded">payment</span>
                                    Pay
                                </button>
                                <button class="btn btn-secondary" id="holdOrderBtn">
                                    <span class="material-symbols-rounded">pause</span>
                                    Hold
                                </button>
                                <button class="btn btn-kot" id="sendKotBtn">
                                    <span class="material-symbols-rounded">soup_kitchen</span>
                                    KOT
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Mobile Bill Summary (Above Buttons) -->
                <div id="mobilePosBillSummary" class="mobile-pos-bill-summary" style="display: none;">
                    <div class="mobile-bill-summary-card">
                        <div class="mobile-bill-summary-header" onclick="toggleMobileBillDetails()">
                            <div>
                                <div style="font-size:0.75rem;color:#6b7280;margin-bottom:0.125rem;">Bill Summary</div>
                                <div style="font-size:1.1rem;font-weight:700;color:#111827;">
                                    Total: <span id="mobilePosBillTotal"><?php echo htmlspecialchars($currency_symbol); ?>0.00</span>
                                </div>
                            </div>
                            <span class="material-symbols-rounded" id="mobilePosBillSummaryArrow" style="font-size:1.25rem;color:#6b7280;transition:transform 0.3s;">chevron_right</span>
                        </div>
                        <div id="mobilePosBillDetails" style="display:none;margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid #e5e7eb;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:0.5rem;font-size:0.85rem;">
                                <span style="color:#6b7280;">Subtotal:</span>
                                <span style="font-weight:600;color:#111827;" id="mobilePosBillSubtotal"><?php echo htmlspecialchars($currency_symbol); ?>0.00</span>
                            </div>
                            <div style="display:flex;justify-content:space-between;font-size:0.85rem;">
                                <span style="color:#6b7280;"><?php echo htmlspecialchars($tax_name); ?> (<?php echo rtrim(rtrim(number_format((float)$tax_percent, 2), '0'), '.'); ?>%):</span>
                                <span style="font-weight:600;color:#111827;" id="mobilePosBillTax"><?php echo htmlspecialchars($currency_symbol); ?>0.00</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Mobile Sticky Bottom Buttons -->
                <div id="mobilePosBottomActions" class="mobile-pos-bottom-actions mobile-pos-bottom-actions-3" style="display: none;">
                    <button class="mobile-pos-btn mobile-pos-btn-bill" id="mobileProcessPaymentBtn">
                        <span class="material-symbols-rounded">payment</span>
                        <span>Pay</span>
                    </button>
                    <button class="mobile-pos-btn mobile-pos-btn-hold" id="mobileHoldOrderBtn">
                        <span class="material-symbols-rounded">pause</span>
                        <span>Hold</span>
                    </button>
                    <button class="mobile-pos-btn mobile-pos-btn-kot" id="mobileSendKotBtn">
                        <span class="material-symbols-rounded">soup_kitchen</span>
                        <span>KOT</span>
                    </button>
                </div>
                
                <!-- Mobile Add Item Modal -->
                <div id="mobileAddItemModal" class="modal" style="display:none;z-index:10000;" onclick="if(event.target===this) closeMobileAddItemModal();">
                    <div class="modal-content" style="max-width:100%;height:90vh;margin:5vh auto;display:flex;flex-direction:column;background:white;border-radius:16px 16px 0 0;" onclick="event.stopPropagation();">
                        <div class="modal-header" style="flex-shrink:0;border-bottom:2px solid #f3f4f6;padding:1rem;">
                            <h2 style="font-size:1.25rem;margin:0;">
                                <span class="material-symbols-rounded" style="vertical-align:middle;margin-right:0.5rem;">add_circle</span>
                                Add Item
                            </h2>
                            <span class="close" onclick="closeMobileAddItemModal()" style="font-size:1.5rem;">&times;</span>
                        </div>
                        <div class="modal-body" style="flex:1;overflow-y:auto;padding:1rem;">
                            <div style="margin-bottom:1rem;position:relative;">
                                <input type="text" id="mobileItemSearch" placeholder="🔍 Search items by name, category, menu, or type..." style="width:100%;padding:0.75rem 3rem 0.75rem 0.75rem;border:2px solid #e5e7eb;border-radius:8px;font-size:0.95rem;box-sizing:border-box;" oninput="filterMobileItems()">
                                <button type="button" onclick="filterMobileItems()" style="position:absolute;right:0.5rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:0.5rem;display:flex;align-items:center;justify-content:center;color:#6b7280;transition:color 0.2s;" onmouseover="this.style.color='#f70000'" onmouseout="this.style.color='#6b7280'">
                                    <span class="material-symbols-rounded" style="font-size:1.5rem;">search</span>
                                </button>
                            </div>
                            <div id="mobileItemsList" style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.75rem;">
                                <!-- Items will be loaded here -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </div>
        </section>

        <!-- Customers -->
        <section id="view-customers" class="st-view" aria-labelledby="custTitle">
            <div class="st-head">
                <div class="st-head-l"><span class="st-hello">Regulars and recent guests</span><h1 id="custTitle">Customers <span class="st-pill muted" id="custCount"></span></h1></div>
                <div class="st-head-r">
                    <label class="st-search"><span class="material-symbols-rounded">search</span><input type="search" id="custSearch" placeholder="Name, phone or email" aria-label="Search customers"></label>
                    <button type="button" class="st-btn ghost" data-refresh><span class="material-symbols-rounded">refresh</span>Refresh</button>
                </div>
            </div>
            <div class="st-card">
                <table class="mg-table" id="customersTable">
                    <thead><tr><th>Name</th><th>Phone</th><th class="r">Visits</th><th class="r">Total spent</th><th class="r">Last visit</th></tr></thead>
                    <tbody id="customersTbody"></tbody>
                </table>
            </div>
        </section>

        <!-- Payments -->
        <section id="view-payments" class="st-view" aria-labelledby="payTitle">
            <div class="st-head">
                <div class="st-head-l"><span class="st-hello">Money taken, newest first</span><h1 id="payTitle">Payments</h1></div>
                <div class="st-head-r"><button type="button" class="st-btn ghost" data-refresh><span class="material-symbols-rounded">refresh</span>Refresh</button></div>
            </div>
            <div class="st-chips" id="payChips" style="margin-bottom:12px"></div>
            <div class="st-card">
                <table class="mg-table" id="paymentsTable">
                    <thead><tr><th>Order</th><th class="r">Amount</th><th>Method</th><th>Status</th><th class="r">Time</th></tr></thead>
                    <tbody id="paymentsTbody"></tbody>
                </table>
            </div>
        </section>

        <!-- Reports -->
        <section id="view-reports" class="st-view" aria-labelledby="repTitle">
            <div class="st-head">
                <div class="st-head-l"><span class="st-hello">Today so far</span><h1 id="repTitle">Sales report</h1></div>
                <div class="st-head-r"><button type="button" class="st-btn ghost" data-refresh><span class="material-symbols-rounded">refresh</span>Refresh</button></div>
            </div>
            <div id="salesReport"><div class="st-stats"><div class="st-skel h-stat"></div><div class="st-skel h-stat"></div><div class="st-skel h-stat"></div><div class="st-skel h-stat"></div></div></div>
            <div class="st-card">
                <div class="st-card-h"><div><b>How guests paid</b><small>Today, by payment method</small></div></div>
                <div id="payMix"></div>
            </div>
        </section>
    </main>

    <!-- phones: bottom tab bar + "More" sheet -->
    <nav class="st-tabbar" aria-label="Manager screens">
        <button type="button" class="st-tb" data-tab="home"><span class="material-symbols-rounded">space_dashboard</span>Home</button>
        <button type="button" class="st-tb" data-tab="orders"><span class="material-symbols-rounded">receipt_long</span>Orders<em data-badge="orders"></em></button>
        <button type="button" class="st-tb" data-tab="kitchen"><span class="material-symbols-rounded">skillet</span>Kitchen<em data-badge="kitchen"></em></button>
        <button type="button" class="st-tb" data-tab="calls"><span class="material-symbols-rounded">notifications_active</span>Calls<em data-badge="calls"></em></button>
        <button type="button" class="st-tb" data-more aria-haspopup="dialog"><span class="material-symbols-rounded">apps</span>More</button>
    </nav>
    <div class="st-modal" id="mgMore" hidden>
        <div class="st-sheet" role="dialog" aria-modal="true" aria-labelledby="mgMoreTitle">
            <div class="st-sheet-h"><b id="mgMoreTitle">More</b><button type="button" class="st-ibtn" data-more-close aria-label="Close"><span class="material-symbols-rounded">close</span></button></div>
            <div class="st-menu">
                <button type="button" data-tab="tables"><span class="st-ic" style="--c:#0f766e"><span class="material-symbols-rounded">table_restaurant</span></span><span>Tables<small>Who is sitting where</small></span></button>
                <button type="button" data-tab="pos"><span class="st-ic" style="--c:#2563eb"><span class="material-symbols-rounded">point_of_sale</span></span><span>POS<small>Take an order or a payment</small></span></button>
                <button type="button" data-tab="customers"><span class="st-ic" style="--c:#db2777"><span class="material-symbols-rounded">group</span></span><span>Customers<small>Regulars and recent guests</small></span></button>
                <button type="button" data-tab="payments"><span class="st-ic" style="--c:#16a34a"><span class="material-symbols-rounded">payments</span></span><span>Payments<small>Money taken today</small></span></button>
                <button type="button" data-tab="reports"><span class="st-ic" style="--c:#0891b2"><span class="material-symbols-rounded">bar_chart</span></span><span>Reports<small>Today's sales report</small></span></button>
            </div>
        </div>
    </div>

    <script src="../assets/js/staff-common.js?v=<?php echo $v('js/staff-common.js'); ?>"></script>
    <script src="../assets/js/staff-calls.js?v=<?php echo $v('js/staff-calls.js'); ?>"></script>
    <script src="../assets/js/staff-kds.js?v=<?php echo $v('js/staff-kds.js'); ?>"></script>
    <script>
        // POS helpers script.js looks for on staff screens
        function showNotification(message, type = 'info', duration = 4000) {
            return StaffUI.toast(message, type, { html: true, duration: duration });
        }
        function showConfirmModal(message, onConfirm, onCancel = null) {
            StaffUI.confirm(message, { title: 'Confirm action', html: true, confirmText: 'Confirm', cancelText: 'Cancel' })
                .then(ok => { if (ok) { if (onConfirm) onConfirm(); } else if (onCancel) onCancel(); });
        }
        window.showNotification = showNotification;
        window.showConfirmModal = showConfirmModal;

        // POS: load menu, tables and filters the first time the tab opens
        window.initManagerPOS = function (firstTime) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => window.initManagerPOS(firstTime), { once: true });
                return;
            }
            if (typeof window.posCart === 'undefined') window.posCart = [];
            setTimeout(() => {
                if (firstTime) {
                    if (typeof window.loadPOSMenuItems === 'function') window.loadPOSMenuItems();
                    if (typeof window.loadMenusForPOSFilters === 'function') window.loadMenusForPOSFilters();
                    if (typeof window.loadCategoriesForPOSFilters === 'function') window.loadCategoriesForPOSFilters();
                }
                if (typeof window.loadTablesForPOS === 'function') window.loadTablesForPOS();
                setTimeout(() => { if (typeof window.checkMobileView === 'function') window.checkMobileView(); }, 300);
            }, 100);
        };
    </script>
    <script src="../assets/js/staff-manager.js?v=<?php echo $v('js/staff-manager.js'); ?>"></script>
</body>
</html>
