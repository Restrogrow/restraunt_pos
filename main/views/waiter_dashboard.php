<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/env_loader.php';

// Require login and permission to view dashboard (Waiter role)
requireLogin();
requirePermission(PERMISSION_VIEW_DASHBOARD);

// Verify user is a Waiter
if (!isset($_SESSION['staff_id']) || !isset($_SESSION['restaurant_id']) || $_SESSION['role'] !== ROLE_WAITER) {
    header('Location: ../admin/login.php');
    exit();
}

$restaurant_id = $_SESSION['restaurant_id'];
$currency_symbol = $_SESSION['currency_symbol'] ?? null;
$tax_name = $_SESSION['tax_name'] ?? null;
$tax_percent = $_SESSION['tax_percent'] ?? null;
$timezone = $_SESSION['timezone'] ?? null;
$conn = null;

if (!$currency_symbol || $tax_name === null || $tax_percent === null || $timezone === null) {
    try {
        if (file_exists(__DIR__ . '/../db_connection.php')) {
            require_once __DIR__ . '/../db_connection.php';

            // Get connection from db_connection.php
            // Get connection using getConnection() for lazy connection support
    if (function_exists('getConnection')) {
        $conn = getConnection();
    } else {
        // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
        $conn = $pdo ?? null;
        if (!$conn) {
            throw new Exception('Database connection not available');
        }
    }

            $settingsStmt = $conn->prepare("SELECT currency_symbol, enable_gst, tax_name, tax_percent, timezone FROM users WHERE restaurant_id = ? LIMIT 1");
            $settingsStmt->execute([$restaurant_id]);
            $settingsRow = $settingsStmt->fetch(PDO::FETCH_ASSOC);

            if ($settingsRow && !empty($settingsRow['currency_symbol'])) {
                // Use centralized Unicode fix function
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
    } catch (Exception $e) {
        // Ignore and fallback to default below
    }
}

if (!$currency_symbol) {
    $currency_symbol = '₹';
}
if (!isset($enable_gst)) {
    $enable_gst = isset($_SESSION['enable_gst']) ? (int)$_SESSION['enable_gst'] : 1;
}
if ($tax_name === null) {
    $tax_name = 'GST';
}
if ($tax_percent === null) {
    $tax_percent = 5.00;
}
if ($timezone === null) {
    $timezone = 'Asia/Kolkata';
}
require_once __DIR__ . '/../config/timezone_utils.php';
applyRestaurantTimezone($timezone, $conn);
?>
<!DOCTYPE html>
<html lang="en" class="st">
<head>
<?php /* network safety net (retries data requests, offline bar) + loading skeletons and Try again: assets/js/rg-load.js */
require_once __DIR__ . '/../website/rg_net.php'; echo rgNetScript(false); ?>
<script src="../assets/js/rg-load.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/rg-load.js'); ?>"></script>
    <meta charset="UTF-8">
<?php require_once __DIR__ . '/../config/sentry_browser.php'; echo rgSentryBrowserTag('waiter'); ?>
  <script src="../assets/js/quiet-console.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/quiet-console.js'); ?>"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <title>Waiter – <?php echo htmlspecialchars($_SESSION['restaurant_name'] ?? 'Restaurant'); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- style.css draws the POS (same as the owner dashboard); staff-ui.css draws everything around it -->
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
    <link rel="preload" href="<?php require_once __DIR__ . '/../config/icon_font.php'; echo htmlspecialchars(rgIconFontUrl(), ENT_QUOTES); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(rgIconFontUrl(), ENT_QUOTES); ?>"></noscript>
    <link rel="stylesheet" href="../assets/css/staff-ui.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/staff-ui.css'); ?>">

    <script>
        window.globalCurrencySymbol = <?php echo json_encode($currency_symbol, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
        window.enableGst = <?php echo json_encode((bool)($enable_gst ?? 1), JSON_HEX_TAG); ?>;
        window.taxName = <?php echo json_encode($tax_name, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE); ?>;
        window.taxPercent = <?php echo json_encode((float)$tax_percent, JSON_HEX_TAG); ?>;
        const waiterCurrencySymbol = window.globalCurrencySymbol || '₹';
        window.STAFF = {
            role: 'Waiter',
            restaurantId: <?php echo json_encode($restaurant_id); ?>,
            serverNow: <?php echo json_encode(date('Y-m-d H:i:s')); ?>,
            currency: window.globalCurrencySymbol
        };
    </script>

    <!-- Scripts - Defer non-critical -->
    <script src="../assets/js/sweetalert2.all.min.js" defer></script>
    <script src="../website/rg-notify.js?v=<?php echo @filemtime(__DIR__ . '/../website/rg-notify.js'); ?>"></script>
    <script>RGNotify.setup({ toast: 'card', watchSaves: true });</script>
  <script src="../assets/js/utils/currency.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/utils/currency.js'); ?>"></script>
    <script src="../assets/js/escpos.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/escpos.js'); ?>"></script>
    <script src="../assets/js/direct-printer.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/direct-printer.js'); ?>" defer></script>
    <script src="../assets/js/script.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/script.js'); ?>" defer></script>
    <script src="../assets/js/pos-discount.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/pos-discount.js'); ?>" defer></script>
    <style>
        /* Allow text selection in input fields only (stops long-press menus on the floor) */
        html, body { -webkit-touch-callout: none; -webkit-user-select: none; user-select: none; }
        input, textarea, select { -webkit-user-select: text; user-select: text; }
    </style>
</head>
<?php
$staff_name = $_SESSION['username'] ?? 'Waiter';
$name_parts = preg_split('/\s+/', trim($staff_name));
$initials = strtoupper(mb_substr($name_parts[0] ?? 'W', 0, 1) . (count($name_parts) > 1 ? mb_substr(end($name_parts), 0, 1) : ''));
?>
<body class="st-body st-has-tabbar">
    <header class="st-top">
        <div class="st-top-in">
            <div class="st-brand">
                <span class="st-logo" aria-hidden="true"><span class="material-symbols-rounded">room_service</span></span>
                <div class="st-brand-t">
                    <b><?php echo htmlspecialchars($_SESSION['restaurant_name'] ?? 'Restaurant'); ?></b>
                    <small>Waiter · <?php echo htmlspecialchars($name_parts[0] ?? 'Waiter'); ?></small>
                </div>
            </div>
            <div class="st-top-r">
                <span class="st-live" id="stLive"><i></i><span>Connecting…</span></span>
                <button type="button" class="st-ibtn on" id="stSoundBtn" aria-pressed="true" aria-label="Sound on"><span class="material-symbols-rounded">campaign</span></button>
                <button type="button" class="st-alerts" id="enableAlertsBtn"><span class="material-symbols-rounded">notifications</span><span class="st-alerts-l">Turn on alerts</span></button>
                <div class="st-user">
                    <span class="st-av" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></span>
                    <div class="st-user-t"><b><?php echo htmlspecialchars($staff_name); ?></b><small>Waiter</small></div>
                </div>
                <a class="st-ibtn" id="stLogout" href="../controllers/staff_logout.php" aria-label="Log out" title="Log out"><span class="material-symbols-rounded">logout</span></a>
            </div>
        </div>
    </header>

    <main class="st-wrap">
        <nav class="st-tabs" role="tablist" aria-label="Waiter screens">
            <button type="button" class="st-tab" role="tab" data-tab="calls"><span class="material-symbols-rounded">notifications_active</span>Table calls<em data-badge="calls"></em></button>
            <button type="button" class="st-tab" role="tab" data-tab="orders"><span class="material-symbols-rounded">room_service</span>Ready to serve<em data-badge="orders"></em></button>
            <button type="button" class="st-tab" role="tab" data-tab="pos"><span class="material-symbols-rounded">point_of_sale</span>POS</button>
        </nav>

        <!-- Table calls -->
        <section id="view-calls" class="st-view" aria-labelledby="callsTitle">
            <div class="st-head">
                <div class="st-head-l">
                    <span class="st-hello">Hi <?php echo htmlspecialchars($name_parts[0] ?? 'there'); ?> 👋</span>
                    <h1 id="callsTitle">Table calls</h1>
                </div>
                <div class="st-head-r">
                    <span class="st-updated" id="lastRefresh">Auto-refreshing…</span>
                    <button type="button" class="st-btn ghost" id="callsRefresh"><span class="material-symbols-rounded">refresh</span>Refresh</button>
                </div>
            </div>
            <div class="st-call-sum" id="callsSummary" aria-live="polite"></div>
            <div id="waiterRequestsList"></div>
        </section>

        <!-- Ready to serve -->
        <section id="view-orders" class="st-view" aria-labelledby="ordersTitle">
            <div class="st-head">
                <div class="st-head-l">
                    <span class="st-hello">Food the kitchen has finished</span>
                    <h1 id="ordersTitle">Ready to serve</h1>
                </div>
                <div class="st-head-r">
                    <span class="st-updated" id="ordersUpdated"></span>
                    <button type="button" class="st-btn ghost" id="ordersRefresh"><span class="material-symbols-rounded">refresh</span>Refresh</button>
                </div>
            </div>
            <div id="activeOrdersList"></div>
        </section>

        <!-- POS (markup unchanged: script.js drives it) -->
        <section id="view-pos" class="st-view" aria-labelledby="posTitle">
            <div id="posTab">
            <div class="st-head">
                <div class="st-head-l">
                    <span class="st-hello">Process orders and manage transactions</span>
                    <h1 id="posTitle">Point of sale</h1>
                </div>
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
    </main>

    <nav class="st-tabbar" aria-label="Waiter screens">
        <button type="button" class="st-tb" data-tab="calls"><span class="material-symbols-rounded">notifications_active</span>Calls<em data-badge="calls"></em></button>
        <button type="button" class="st-tb" data-tab="orders"><span class="material-symbols-rounded">room_service</span>Serve<em data-badge="orders"></em></button>
        <button type="button" class="st-tb" data-tab="pos"><span class="material-symbols-rounded">point_of_sale</span>POS</button>
    </nav>

    <script src="../assets/js/staff-common.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/staff-common.js'); ?>"></script>
    <script src="../assets/js/staff-calls.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/staff-calls.js'); ?>"></script>
    <script>
        const waiterRestaurantId = <?php echo json_encode($restaurant_id); ?>;
        const waiterRestaurantIdQuery = waiterRestaurantId ? encodeURIComponent(waiterRestaurantId) : '';
        // Set restaurant_id for POS functions in script.js
        if (typeof window !== 'undefined') {
            window.restaurant_id = waiterRestaurantId;
        }

        // Toasts + confirm: the shared RestroGrow look (RGNotify), same as the owner dashboard
        function showNotification(message, type = 'info', duration = 4000) {
            return StaffUI.toast(message, type, { html: true, duration: duration });
        }
        function showConfirmModal(message, onConfirm, onCancel = null) {
            StaffUI.confirm(message, { title: 'Confirm action', html: true, confirmText: 'Confirm', cancelText: 'Cancel' })
                .then(ok => { if (ok) { if (onConfirm) onConfirm(); } else if (onCancel) onCancel(); });
        }
        window.showNotification = showNotification;
        window.showConfirmModal = showConfirmModal;

        // POS: load menu, tables and filters the first time the tab opens (script.js does the work;
        // the *ForWaiter functions below are the fallback if it is missing)
        function initWaiterPOS(firstTime) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => initWaiterPOS(firstTime), { once: true });
                return;
            }
            if (typeof window.posCart === 'undefined') {
                window.posCart = [];
            }
            if (typeof window.checkMobileView === 'function') {
                window.checkMobileView();
            }
            setTimeout(() => {
                if (firstTime) {
                    if (typeof window.loadPOSMenuItems === 'function') {
                        window.loadPOSMenuItems();
                    } else if (typeof loadPOSDataForWaiter === 'function') {
                        loadPOSDataForWaiter();
                    }
                    if (typeof window.loadTablesForPOS === 'function') {
                        window.loadTablesForPOS();
                    } else if (typeof loadTablesForWaiterPOS === 'function') {
                        loadTablesForWaiterPOS();
                    }
                    if (typeof window.loadMenusForPOSFilters === 'function') {
                        window.loadMenusForPOSFilters();
                    } else if (typeof loadMenusForWaiterPOS === 'function') {
                        loadMenusForWaiterPOS();
                    }
                    if (typeof window.loadCategoriesForPOSFilters === 'function') {
                        window.loadCategoriesForPOSFilters();
                    } else if (typeof loadCategoriesForWaiterPOS === 'function') {
                        loadCategoriesForWaiterPOS();
                    }
                } else if (typeof window.loadTablesForPOS === 'function') {
                    // tables change through the shift (occupied / free) - keep the picker fresh
                    window.loadTablesForPOS();
                }
                setTimeout(() => {
                    if (typeof window.checkMobileView === 'function') {
                        window.checkMobileView();
                    } else {
                        const isMobile = window.innerWidth <= 768;
                        const mobileBtn = document.getElementById('mobileAddItemBtn');
                        const mobileBillSummary = document.getElementById('mobilePosBillSummary');
                        const mobileBottomActions = document.getElementById('mobilePosBottomActions');
                        if (mobileBtn) mobileBtn.style.display = isMobile ? 'flex' : 'none';
                        if (mobileBillSummary) mobileBillSummary.style.display = isMobile ? 'block' : 'none';
                        if (mobileBottomActions) mobileBottomActions.style.display = isMobile ? 'flex' : 'none';
                    }
                }, 300);
            }, 100);
        }
        window.initWaiterPOS = initWaiterPOS;

        // Store all menu items for filtering
        let allPOSItems = [];
        
        // POS Functions for Waiter Dashboard (if script.js functions not available)
        async function loadPOSDataForWaiter() {
            try {
                const response = await fetch(`../api/get_menu_items.php?restaurant_id=${waiterRestaurantIdQuery}`);
                const result = await response.json();
                
                if (result.success && result.data && result.data.length > 0) {
                    // Store all items for filtering
                    allPOSItems = result.data;
                    displayPOSItems(allPOSItems);
                } else {
                    document.getElementById('posMenuItems').innerHTML = '<div class="loading">No menu items found</div>';
                }
            } catch (error) {
                console.error('Error loading POS menu items:', error);
                document.getElementById('posMenuItems').innerHTML = '<div class="loading">Error loading menu items</div>';
            }
        }
        
        // Display POS items
        function displayPOSItems(items) {
            const container = document.getElementById('posMenuItems');
            if (!container) return;
            
            if (items.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 40px; color: #6b7280;">No items match your search</div>';
                return;
            }
            
            container.innerHTML = items.map(item => {
                // a name like 12" Pizza or Chef's Special must not break the onclick attribute
                const rawName = item.item_name_translated || item.item_name_en || item.item_name || '';
                const jsArg = s => JSON.stringify(String(s)).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
                const itemName = String(rawName).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                return `
                    <div class="pos-menu-item" onclick="addToWaiterPOSCart(${item.id}, ${jsArg(rawName)}, ${parseFloat(item.base_price || item.price) || 0}, ${jsArg(item.item_image || '')})" style="background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; cursor: pointer; transition: all 0.2s; text-align: center;">
                        <div class="item-image" style="width: 100%; height: 120px; background: #f9fafb; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; overflow: hidden;">
                            ${(() => {
                                if (!item.item_image) return '<span class="material-symbols-rounded" style="font-size: 3rem; color: #9ca3af;">restaurant</span>';
                                let imageUrl;
                                if (item.item_image.startsWith('db:')) {
                                    imageUrl = `../api/image.php?path=${encodeURIComponent(item.item_image)}`;
                                } else if (item.item_image.startsWith('http')) {
                                    imageUrl = item.item_image;
                                } else {
                                    imageUrl = `../api/image.php?path=${encodeURIComponent(item.item_image)}`;
                                }
                                return `<img src="${imageUrl}" alt="${itemName}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px;">`;
                            })()}
                        </div>
                        <div class="item-name" style="font-weight: 600; margin-bottom: 4px; color: #111827;">${itemName}</div>
                        <div class="item-category" style="font-size: 0.875rem; color: #6b7280; margin-bottom: 8px;">${item.item_category || ''}</div>
                        <div class="item-price" style="font-weight: 700; color: #3b82f6; font-size: 1.1rem;">${waiterCurrencySymbol}${parseFloat(item.base_price || item.price || 0).toFixed(2)}</div>
                    </div>
                `;
            }).join('');
        }
        
        // Filter POS items by search and filters
        function filterPOSItems() {
            const searchTerm = document.getElementById('posSearchBar')?.value.toLowerCase() || '';
            const menuFilter = document.getElementById('posMenuFilter')?.value || '';
            const categoryFilter = document.getElementById('posCategoryFilter')?.value || '';
            const typeFilter = document.getElementById('posTypeFilter')?.value || '';
            
            let filtered = allPOSItems.filter(item => {
                const matchesSearch = !searchTerm || 
                    (item.item_name_translated || item.item_name_en || item.item_name || '').toLowerCase().includes(searchTerm) ||
                    (item.item_category || '').toLowerCase().includes(searchTerm);
                
                const matchesMenu = !menuFilter || item.menu_id == menuFilter;
                const matchesCategory = !categoryFilter || (item.item_category || '').toLowerCase() === categoryFilter.toLowerCase();
                const matchesType = !typeFilter || (item.item_type || '').toLowerCase() === typeFilter.toLowerCase();
                
                return matchesSearch && matchesMenu && matchesCategory && matchesType;
            });
            
            displayPOSItems(filtered);
        }
        
        // Make filter function globally available
        window.filterPOSItems = filterPOSItems;
        
        // Also filter when dropdowns change
        document.addEventListener('DOMContentLoaded', function() {
            const menuFilter = document.getElementById('posMenuFilter');
            const categoryFilter = document.getElementById('posCategoryFilter');
            const typeFilter = document.getElementById('posTypeFilter');
            
            if (menuFilter) menuFilter.addEventListener('change', filterPOSItems);
            if (categoryFilter) categoryFilter.addEventListener('change', filterPOSItems);
            if (typeFilter) typeFilter.addEventListener('change', filterPOSItems);
        });
        
        async function loadTablesForWaiterPOS() {
            try {
                const response = await fetch(`../api/get_tables.php?restaurant_id=${waiterRestaurantIdQuery}`);
                const result = await response.json();
                
                const select = document.getElementById('selectPosTable');
                if (select && result.success && result.data && result.data.length > 0) {
                    select.innerHTML = '<option value="">Takeaway</option>' + 
                        result.data.map(table => 
                            `<option value="${table.id}">${table.table_number} - ${table.area_name || ''}</option>`
                        ).join('');
                }
            } catch (error) {
                console.error('Error loading tables:', error);
            }
        }
        
        async function loadMenusForWaiterPOS() {
            try {
                const response = await fetch(`../api/get_menus.php?restaurant_id=${waiterRestaurantIdQuery}`);
                const result = await response.json();
                
                const select = document.getElementById('posMenuFilter');
                if (select && result.success && result.data && result.data.length > 0) {
                    select.innerHTML = '<option value="">All Menus</option>' + 
                        result.data.map(menu => 
                            `<option value="${menu.id}">${menu.menu_name}</option>`
                        ).join('');
                }
            } catch (error) {
                console.error('Error loading menus:', error);
            }
        }
        
        async function loadCategoriesForWaiterPOS() {
            try {
                const response = await fetch(`../api/get_menu_items.php?restaurant_id=${waiterRestaurantIdQuery}`);
                const result = await response.json();
                
                const select = document.getElementById('posCategoryFilter');
                if (select && result.success && result.categories && result.categories.length > 0) {
                    select.innerHTML = '<option value="">All Categories</option>' + 
                        result.categories.map(category => 
                            `<option value="${category}">${category}</option>`
                        ).join('');
                }
            } catch (error) {
                console.error('Error loading categories:', error);
            }
        }
        
        // Cart functions for waiter POS
        if (typeof window.posCart === 'undefined') {
            window.posCart = [];
        }
        
        function playClickSound() {
            try {
                const audio = new Audio('../../assets/sounds/click.wav');
                audio.volume = 0.5;
                audio.play().catch(function(){});
            } catch(e) {}
        }

        function addToWaiterPOSCart(itemId, itemName, price, image) {
            playClickSound();
            if (!window.posCart) {
                window.posCart = [];
            }
            const existingItem = window.posCart.find(item => item.id === itemId);
            
            if (existingItem) {
                existingItem.quantity += 1;
            } else {
                window.posCart.push({
                    id: itemId,
                    name: itemName,
                    price: price,
                    image: image,
                    quantity: 1
                });
            }
            
            updateWaiterPOSCart();
        }
        
        function updateWaiterPOSCart() {
            const container = document.getElementById('posCartItems');
            if (!container) return; // Exit if container doesn't exist
            
            if (!window.posCart || window.posCart.length === 0) {
                window._kotSentForCurrentOrder = false;
                container.innerHTML = `
                    <div class="empty-cart" style="text-align: center; color: #6b7280; padding: 40px;">
                        <span class="material-symbols-rounded" style="font-size: 3rem; opacity: 0.3; display: block; margin-bottom: 8px;">shopping_cart</span>
                        <p style="margin: 0;">Cart is empty</p>
                        <p class="empty-subtext" style="margin: 4px 0 0 0; font-size: 0.875rem;">Add items from the menu</p>
                    </div>
                `;
            } else {
                container.innerHTML = window.posCart.map(item => `
                    <div class="cart-item" style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: white; border-radius: 8px; margin-bottom: 8px; border: 1px solid #e5e7eb;">
                        <div class="cart-item-info" style="flex: 1;">
                            <div class="cart-item-name" style="font-weight: 600; margin-bottom: 4px;">${String(item.name || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>
                            <div class="cart-item-price" style="font-size: 0.875rem; color: #6b7280;">${waiterCurrencySymbol}${parseFloat(item.price).toFixed(2)} each</div>
                        </div>
                        <div class="cart-item-controls" style="display: flex; align-items: center; gap: 12px; margin: 0 16px;">
                            <button onclick="updateWaiterPOSCartQty(${item.id}, -1)" style="padding: 6px 12px; border: 1px solid #e5e7eb; background: white; border-radius: 6px; cursor: pointer; font-weight: 600;">-</button>
                            <span style="min-width: 30px; text-align: center; font-weight: 600;">${item.quantity}</span>
                            <button onclick="updateWaiterPOSCartQty(${item.id}, 1)" style="padding: 6px 12px; border: 1px solid #e5e7eb; background: white; border-radius: 6px; cursor: pointer; font-weight: 600;">+</button>
                        </div>
                        <div class="cart-item-total" style="font-weight: 700; color: #111827; min-width: 80px; text-align: right;">${waiterCurrencySymbol}${(parseFloat(item.price) * item.quantity).toFixed(2)}</div>
                    </div>
                `).join('');
            }
            
            updateWaiterPOSCartSummary();
        }
        
        function updateWaiterPOSCartQty(itemId, change) {
            if (!window.posCart) return;
            const item = window.posCart.find(item => item.id === itemId);
            
            if (item) {
                item.quantity += change;
                
                if (item.quantity <= 0) {
                    window.posCart = window.posCart.filter(cartItem => cartItem.id !== itemId);
                }
                
                updateWaiterPOSCart();
            }
        }
        
        // Make functions globally available
        window.addToWaiterPOSCart = addToWaiterPOSCart;
        window.updateWaiterPOSCart = updateWaiterPOSCart;
        window.updateWaiterPOSCartQty = updateWaiterPOSCartQty;
        
        function updateWaiterPOSCartSummary() {
            if (!window.posCart) return;
            // Use the same money maths as the main POS, so a manual discount / GST line up
            const T = window.posComputeTotals ? window.posComputeTotals(window.posCart) : null;
            const subtotal = T ? T.subtotal : window.posCart.reduce((sum, item) => sum + (parseFloat(item.price) * item.quantity), 0);
            const showGst = T ? T.showGst : (window.enableGst == 1 || window.enableGst === true);
            const tax = T ? T.tax : (showGst ? subtotal * ((window.taxPercent || 5) / 100) : 0);
            const total = T ? T.total : (showGst ? (subtotal + tax) : subtotal);
            if (window.rgPosDiscount) window.rgPosDiscount.paintSummary(subtotal, T ? T.taken : 0);

            const subtotalEl = document.getElementById('cartSubtotal');
            const taxEl = document.getElementById('cartTax');
            const totalEl = document.getElementById('cartTotal');

            if (subtotalEl) subtotalEl.textContent = `${waiterCurrencySymbol}${subtotal.toFixed(2)}`;
            if (taxEl) taxEl.textContent = `${waiterCurrencySymbol}${tax.toFixed(2)}`;
            if (totalEl) totalEl.textContent = `${waiterCurrencySymbol}${total.toFixed(2)}`;
        }
        
        // Make functions globally available
        window.addToWaiterPOSCart = addToWaiterPOSCart;
        window.updateWaiterPOSCartQty = updateWaiterPOSCartQty;
        
        // Clear cart button
        // POS buttons will use the global functions from script.js
        // The functions in script.js now support both posCart and window.posCart
        
        // Check mobile view when POS tab is opened
        if (typeof window.checkMobileView === 'function') {
            window.checkMobileView();
        }
        
        // Also check on window resize
        window.addEventListener('resize', () => {
            if (typeof window.checkMobileView === 'function') {
                window.checkMobileView();
            }
        });
    </script>
    <script src="../assets/js/staff-waiter.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/staff-waiter.js'); ?>"></script>
</body>
</html>
