<?php
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../config/countries.php';
// Session already started in auth.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
<?php require_once __DIR__ . '/../config/sentry_browser.php'; echo rgSentryBrowserTag('superadmin'); ?>
  <script src="../assets/js/quiet-console.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/quiet-console.js'); ?>"></script>
  <script>
(function(){
  var msg=function(a){
    if(typeof a==='string')return a;
    if(a&&a.message)return a.message;
    try{return String(a)}catch(e){return''}
  };
  window.addEventListener('unhandledrejection',function(e){
    var m=msg(e.reason);
    if(m.indexOf('Could not establish connection')!==-1||m.indexOf('runtime.lastError')!==-1){e.preventDefault()}
  });
  window.addEventListener('error',function(e){
    if(e.message&&e.message.indexOf('Could not establish connection')!==-1){e.preventDefault();return true}
  });
  ['error','warn'].forEach(function(m){
    var orig=console[m];
    console[m]=function(){
      for(var i=0;i<arguments.length;i++){
        var a=arguments[i];
        var s=msg(a);
        if(s.indexOf('runtime.lastError')!==-1||s.indexOf('Could not establish connection')!==-1)return;
      }
      return orig.apply(console,arguments);
    };
  });
})();
</script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RestroGrow · Superadmin</title>
  
  <!-- Resource Hints for Performance -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="dns-prefetch" href="https://fonts.googleapis.com">
  <link rel="dns-prefetch" href="https://fonts.gstatic.com">
  
  <!-- Optimized Font Loading -->
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap"></noscript>
  <link rel="preload" href="<?php require_once __DIR__ . '/../config/icon_font.php'; echo htmlspecialchars(rgIconFontUrl(), ENT_QUOTES); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="<?php require_once __DIR__ . '/../config/icon_font.php'; echo htmlspecialchars(rgIconFontUrl(), ENT_QUOTES); ?>"></noscript>
  
  <!-- Scripts - Defer non-critical -->
  <script src="../assets/js/sweetalert2.all.min.js" defer></script>
  <!-- Error Monitor interceptor -->
  <script src="../public/error-monitor.js" defer></script>
  <style>
    :root{ --bg:#f4f6fb; --card:#fff; --border:#e5e7eb; --text:#111827; --muted:#6b7280; --primary:#151A2D; --primary-light:#1f2937; --green:#10b981; --red:#ef4444; --orange:#f59e0b; --blue:#3b82f6; --radius:12px; --shadow:0 2px 8px rgba(0,0,0,.05); }
    *{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:Inter,system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);line-height:1.5;}
    .layout{display:flex;min-height:100vh;}
    .sidebar{width:260px;background:var(--primary);color:#fff;overflow-y:auto;position:fixed;top:0;left:0;height:100vh;z-index:100;transition:transform .3s ease;}
    .sidebar-header{padding:20px;border-bottom:1px solid rgba(255,255,255,.1);}
    .sidebar-header h2{font-size:1.25rem;font-weight:700;letter-spacing:-.02em;}
    .sidebar-menu{padding:10px 0;}
    .menu-item{padding:12px 20px;cursor:pointer;display:flex;align-items:center;gap:12px;transition:all .2s;position:relative;}
    .menu-item:hover{background:rgba(255,255,255,.1);}
    .menu-item.active{background:rgba(255,255,255,.15);}
    .menu-item.active::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--green);border-radius:0 3px 3px 0;}
    .menu-item .icon{font-size:20px;flex-shrink:0;}
    .menu-item .text{flex:1;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    /* plain links to separate pages (not in-page tabs, so the tab switcher ignores them) */
    .menu-link{padding:12px 20px;display:flex;align-items:center;gap:12px;color:inherit;text-decoration:none;transition:background .2s;}
    .menu-link:hover{background:rgba(255,255,255,.1);}
    .menu-link .icon{font-size:20px;flex-shrink:0;}
    .menu-link .text{flex:1;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .sidebar-footer{padding:20px;border-top:1px solid rgba(255,255,255,.1);margin-top:auto;}
    .badge-new{display:inline-block;font-size:10px;font-weight:700;padding:2px 6px;border-radius:10px;background:#10b981;color:#fff;text-transform:uppercase;letter-spacing:.3px;vertical-align:middle;margin-left:4px;}
    .main-content{flex:1;margin-left:260px;padding:24px;min-height:100vh;transition:margin-left .3s ease;}
    .page-header{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:24px;}
    .page-header h1{font-size:1.5rem;font-weight:700;letter-spacing:-.02em;}
    .header-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
    .header-user{color:var(--muted);font-size:.875rem;}
    .hamburger{display:none;background:none;border:none;color:var(--text);font-size:28px;cursor:pointer;padding:4px;line-height:1;}
    .sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:99;opacity:0;transition:opacity .3s ease;}
    .sidebar-overlay.active{opacity:1;}
    .btn{padding:10px 16px;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:.875rem;transition:all .2s;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;}
    .btn-primary{background:var(--primary);color:#fff;}
    .btn-primary:hover{background:var(--primary-light);}
    .btn-outline{background:#fff;border:2px solid var(--border);color:var(--text);}
    .btn-outline:hover{background:#f9fafb;}
    .btn-sm{padding:6px 12px;font-size:.8rem;}
    .btn-success{background:var(--green);color:#fff;}
    .btn-danger{background:var(--red);color:#fff;}
    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:20px;}
    .card-header{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--border);}
    .card-body{padding:20px;}
    .card-title{font-weight:700;font-size:1.05rem;}
    .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;}
    .stat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:20px;transition:box-shadow .2s;}
    .stat-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.08);}
    .stat-label{color:var(--muted);font-size:.8rem;font-weight:500;margin-bottom:4px;text-transform:uppercase;letter-spacing:.03em;}
    .stat-value{font-size:1.75rem;font-weight:800;color:var(--primary);}
    .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:8px;}
    table{width:100%;border-collapse:collapse;font-size:.875rem;}
    th,td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap;}
    th{background:#f9fafb;color:#374151;text-transform:uppercase;font-size:.75rem;font-weight:600;letter-spacing:.03em;}
    tr:hover{background:#fafbfc;}
    .badge{padding:3px 10px;border-radius:999px;font-weight:600;font-size:.75rem;display:inline-block;}
    .badge-success{background:#d1fae5;color:#065f46;}
    .badge-warning{background:#fde68a;color:#92400e;}
    .badge-danger{background:#fee2e2;color:#991b1b;}
    .badge-info{background:#dbeafe;color:#1e40af;}
    input,select,textarea{padding:10px 12px;border:2px solid var(--border);border-radius:8px;font-size:.875rem;font-family:inherit;transition:border-color .2s;background:#fff;}
    input:focus,select:focus,textarea:focus{outline:none;border-color:var(--primary);}
    .form-group{margin-bottom:16px;}
    .form-group label{display:block;margin-bottom:6px;font-weight:600;font-size:.875rem;color:#374151;}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
    .page{display:none;}
    .page.active{display:block;}
    .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);align-items:center;justify-content:center;z-index:1000;padding:16px;}
    .modal.active{display:flex;}
    .modal-content{background:#fff;border-radius:var(--radius);max-width:520px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);}
    .modal-header{padding:20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
    .modal-body{padding:20px;}
    .modal-footer{padding:16px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;}
    .empty-state{padding:40px 20px;text-align:center;color:var(--muted);}
    .empty-state .icon{font-size:48px;margin-bottom:12px;opacity:.4;}
    .toast{position:fixed;bottom:24px;right:24px;z-index:9999;padding:14px 20px;border-radius:10px;color:#fff;font-weight:600;font-size:.875rem;box-shadow:0 8px 24px rgba(0,0,0,.15);animation:slideUp .3s ease;}
    @keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}

    @media (max-width:900px){
      .sidebar{transform:translateX(-100%);}
      .sidebar.open{transform:translateX(0);}
      .sidebar-overlay.active{display:block;}
      .main-content{margin-left:0;padding:16px;}
      .hamburger{display:block;}
      .page-header h1{font-size:1.25rem;}
      .stats-grid{grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;}
      .stat-value{font-size:1.5rem;}
      .card-header{flex-direction:column;align-items:stretch;}
      .card-body{padding:16px;}
      .header-actions{width:100%;}
    }
    @media (max-width:600px){
      .main-content{padding:12px;}
      .stats-grid{grid-template-columns:1fr 1fr;gap:10px;}
      .stat-card{padding:14px;}
      .stat-value{font-size:1.35rem;}
      .page-header{flex-direction:column;align-items:stretch;}
      .page-header h1{font-size:1.15rem;}
      .card-body{padding:12px;}
      th,td{padding:8px 10px;font-size:.8rem;}
      .modal-content{margin:auto 8px;}
      .toast{left:16px;right:16px;bottom:16px;text-align:center;}
    }
    @media (max-width:400px){
      .stats-grid{grid-template-columns:1fr;}
      .stat-card{padding:12px;}
      .menu-item{padding:10px 16px;}
    }
    /* ═══ Error Monitor Styles ═══ */
    #errorMonitorPage .badge {
      font-size: 11px;
      padding: 2px 10px;
    }
    #errorMonitorPage table tr:not(:hover) td {
      border-bottom: 1px solid var(--border);
    }
    #errorMonitorPage .error-critical td { border-left: 3px solid #dc2626; }
    #errorMonitorPage .error-error td { border-left: 3px solid #ef4444; }
    #errorMonitorPage .error-warning td { border-left: 3px solid #f59e0b; }
    .em-unread { background: #fffbeb !important; }
    .em-unread td:first-child { border-left: 3px solid #f59e0b; }
    #saErrorBadge {
      background: #ef4444;
      font-size: 9px;
      padding: 1px 5px;
      border-radius: 6px;
    }
  </style>
  <link rel="stylesheet" href="superadmin.css?v=<?php echo @filemtime(__DIR__ . '/superadmin.css'); ?>">
  <script src="sa-platform.js?v=<?php echo @filemtime(__DIR__ . '/sa-platform.js'); ?>" defer></script>
  <link rel="stylesheet" href="sa-invoice-gen.css?v=<?php echo @filemtime(__DIR__ . '/sa-invoice-gen.css'); ?>">
  <script src="sa-invoice-gen.js?v=<?php echo @filemtime(__DIR__ . '/sa-invoice-gen.js'); ?>" defer></script>
</head>
<body>
  <div class="layout">
    <!-- Sidebar -->
    <div class="sidebar">
      <div class="sidebar-header">
        <span class="sa-logo">RG</span>
        <h2>RestroGrow<small>Superadmin</small></h2>
      </div>
      <div class="sidebar-menu">
        <div class="sa-nav-h">Overview</div>
        <div class="menu-item active" data-page="dashboard">
          <span class="material-symbols-rounded icon">space_dashboard</span>
          <span class="text">Dashboard</span>
        </div>
        <div class="menu-item" data-page="analytics">
          <span class="material-symbols-rounded icon">monitoring</span>
          <span class="text">Analytics</span>
        </div>

        <div class="sa-nav-h">Restaurants</div>
        <div class="menu-item" data-page="restaurants">
          <span class="material-symbols-rounded icon">storefront</span>
          <span class="text">Restaurants</span>
        </div>
        <div class="menu-item" data-page="renewals">
          <span class="material-symbols-rounded icon">event_repeat</span>
          <span class="text">Renewals <span id="spRenewBadge" class="badge-new" style="display:none;">0</span></span>
        </div>
        <div class="menu-item" data-page="menus">
          <span class="material-symbols-rounded icon">menu_book</span>
          <span class="text">Menus from photos</span>
        </div>
        <div class="menu-item" data-page="branchAdmins">
          <span class="material-symbols-rounded icon">groups</span>
          <span class="text">Branch admins</span>
        </div>
        <div class="menu-item" data-page="coupons">
          <span class="material-symbols-rounded icon">sell</span>
          <span class="text">Coupons</span>
        </div>

        <div class="sa-nav-h">Growth</div>
        <div class="menu-item" data-page="leads">
          <span class="material-symbols-rounded icon">person_search</span>
          <span class="text">Leads <span id="spLeadBadge" class="badge-new" style="display:none;">0</span></span>
        </div>
        <div class="menu-item" data-page="chatLeads">
          <span class="material-symbols-rounded icon">forum</span>
          <span class="text">Chat leads <span id="spChatLeadBadge" class="badge-new" style="display:none;">0</span></span>
        </div>
        <div class="menu-item" data-page="support">
          <span class="material-symbols-rounded icon">support_agent</span>
          <span class="text">Support <span id="spTicketBadge" class="badge-new" style="display:none;">0</span></span>
        </div>
        <div class="menu-item" data-page="announcements">
          <span class="material-symbols-rounded icon">campaign</span>
          <span class="text">Announcements</span>
        </div>

        <div class="sa-nav-h">Money</div>
        <div class="menu-item" data-page="subscriptions">
          <span class="material-symbols-rounded icon">workspace_premium</span>
          <span class="text">Subscriptions</span>
        </div>
        <div class="menu-item" data-page="payments">
          <span class="material-symbols-rounded icon">receipt_long</span>
          <span class="text">Payments</span>
        </div>
        <div class="menu-item" data-page="paymentLinks">
          <span class="material-symbols-rounded icon">link</span>
          <span class="text">Payment links</span>
        </div>
        <div class="menu-item" data-page="settlements">
          <span class="material-symbols-rounded icon">account_balance</span>
          <span class="text">Settlements</span>
        </div>
        <div class="menu-item" data-page="invoices">
          <span class="material-symbols-rounded icon">request_quote</span>
          <span class="text">Invoices & GST</span>
        </div>
        <div class="menu-item" data-page="invoiceGen">
          <span class="material-symbols-rounded icon">post_add</span>
          <span class="text">Invoice generator</span>
        </div>

        <div class="sa-nav-h">System</div>
        <div class="menu-item" data-page="security">
          <span class="material-symbols-rounded icon">shield_person</span>
          <span class="text">Security & activity</span>
        </div>
        <div class="menu-item" data-page="health">
          <span class="material-symbols-rounded icon">monitor_heart</span>
          <span class="text">Platform health</span>
        </div>
        <div class="menu-item" data-page="settings">
          <span class="material-symbols-rounded icon">settings</span>
          <span class="text">Settings</span>
        </div>
        <div class="menu-item" data-page="errorMonitor">
          <span class="material-symbols-rounded icon">bug_report</span>
          <span class="text">Error monitor <span id="saErrorBadge" class="badge-new" style="display:none;">0</span></span>
        </div>

        <div class="sa-nav-h">Tools</div>
        <?php $saTodoOpen = 0; try { require_once __DIR__ . '/../db_connection.php'; $saTodoOpen = (int)getConnection()->query("SELECT COUNT(*) FROM sa_todos WHERE done = 0")->fetchColumn(); } catch (Throwable $e) { $saTodoOpen = 0; } ?>
        <a class="menu-link" href="todo.php">
          <span class="material-symbols-rounded icon">task_alt</span>
          <span class="text">My to-do<?php if ($saTodoOpen): ?> <span class="badge-new" style="background:#f97316"><?php echo $saTodoOpen; ?></span><?php endif; ?></span>
        </a>
        <?php $saMailFailed = 0; try { $saMailFailed = (int)getConnection()->query("SELECT COUNT(*) FROM email_outbox WHERE status = 'failed' AND created_at > NOW() - INTERVAL 7 DAY")->fetchColumn(); } catch (Throwable $e) { $saMailFailed = 0; } ?>
        <a class="menu-link" href="email.php">
          <span class="material-symbols-rounded icon">mail</span>
          <span class="text">Email<?php if ($saMailFailed): ?> <span class="badge-new" style="background:#dc2626" title="Failed in the last 7 days"><?php echo $saMailFailed; ?></span><?php endif; ?></span>
        </a>
        <a class="menu-link" href="blog.php">
          <span class="material-symbols-rounded icon">edit_note</span>
          <span class="text">Blog</span>
        </a>
        <a class="menu-link" href="email_book.php">
          <span class="material-symbols-rounded icon">auto_stories</span>
          <span class="text">Email book</span>
        </a>
        <a class="menu-link" href="handbook.php">
          <span class="material-symbols-rounded icon">menu_book</span>
          <span class="text">Admin handbook</span>
        </a>
        <a class="menu-link" href="usage.php">
          <span class="material-symbols-rounded icon">insights</span>
          <span class="text">Usage &amp; sign-ups</span>
        </a>
        <a class="menu-link" href="site_analytics.php">
          <span class="material-symbols-rounded icon">monitoring</span>
          <span class="text">Website analytics</span>
        </a>
        <a class="menu-link" href="undo_designs.php">
          <span class="material-symbols-rounded icon">undo</span>
          <span class="text">Undo design</span>
        </a>
        <a class="menu-link" href="loading_screens.php">
          <span class="material-symbols-rounded icon">progress_activity</span>
          <span class="text">Loading screens</span>
        </a>
        <a class="menu-link" href="features.php">
          <span class="material-symbols-rounded icon">checklist</span>
          <span class="text">Features</span>
        </a>
        <a class="menu-link" href="../../demo/#team" target="_blank" rel="noopener">
          <span class="material-symbols-rounded icon">apps</span>
          <span class="text">All demos</span>
        </a>
        <a class="menu-link" href="demo_restaurant.php">
          <span class="material-symbols-rounded icon">storefront</span>
          <span class="text">Demo restaurant</span>
        </a>
      </div>
      <div class="sa-user">
        <div class="sa-user-row"><span class="sa-avatar"><?php echo htmlspecialchars(strtoupper(substr($_SESSION['superadmin_username'] ?? 'S', 0, 1))); ?></span><span><?php echo htmlspecialchars($_SESSION['superadmin_username'] ?? ''); ?><small>Superadmin</small></span></div>
        <a href="logout.php" class="sa-logout"><span class="material-symbols-rounded">logout</span>Log out</a>
      </div>
    </div>
    <div class="sidebar-overlay"></div>

    <!-- Main Content -->
    <div class="main-content">
      <!-- Dashboard Page -->
      <div id="dashboardPage" class="page active">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Good to see you, <?php echo htmlspecialchars($_SESSION['superadmin_username'] ?? ''); ?></h1><div class="sa-sub">Everything across all restaurants, today</div></div>
          </div>
        </div>
        <div class="stats-grid">
          <div class="stat-card" data-icon="storefront" style="--c:#2563eb"><div class="stat-label">Restaurants</div><div class="stat-value" id="statRestaurants">0</div></div>
          <div class="stat-card" data-icon="check_circle" style="--c:#16a34a"><div class="stat-label">Active</div><div class="stat-value" id="statActive">0</div></div>
          <div class="stat-card" data-icon="payments" style="--c:#7c3aed"><div class="stat-label">Sales today (all restaurants)</div><div class="stat-value" id="statRevenue">₹0</div></div>
          <div class="stat-card" data-icon="receipt_long" style="--c:#0891b2"><div class="stat-label">Orders today</div><div class="stat-value" id="statOrders">0</div></div>
        </div>
        <div class="sa-quick">
          <button type="button" data-sa-quick="newRestaurant" style="--c:#2563eb"><span class="material-symbols-rounded">add_business</span>New restaurant</button>
          <button type="button" data-sa-quick="paymentLink" style="--c:#7c3aed"><span class="material-symbols-rounded">add_link</span>New payment link</button>
          <button type="button" data-sa-go="subscriptions" style="--c:#16a34a"><span class="material-symbols-rounded">workspace_premium</span>Subscriptions</button>
          <button type="button" data-sa-go="settlements" style="--c:#0891b2"><span class="material-symbols-rounded">account_balance</span>Settle money</button>
          <button type="button" data-sa-go="menus" style="--c:#d97706"><span class="material-symbols-rounded">photo_camera</span>Menu from photos</button>
          <button type="button" data-sa-go="errorMonitor" style="--c:#dc2626"><span class="material-symbols-rounded">bug_report</span>Errors</button>
        </div>
        <div class="card">
          <div class="card-header">
            <div class="card-title">Newest restaurants</div>
            <button type="button" class="btn btn-outline btn-sm" data-sa-go="restaurants">All restaurants</button>
          </div>
          <div class="card-body">
            <div id="recentActivity" class="sa-recent">Loading…</div>
          </div>
        </div>
      </div>

      <!-- Restaurants Page -->
      <div id="restaurantsPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Restaurants</h1><div class="sa-sub">Every restaurant on RestroGrow: open it, switch features, manage logins</div></div>
          </div>
          <div class="header-actions">
            <button class="btn btn-outline" id="btnExport"><span class="material-symbols-rounded">download</span>Export CSV</button>
            <button class="btn btn-primary" id="btnNew"><span class="material-symbols-rounded">add</span>New restaurant</button>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <div class="sa-filters"><div class="sa-seg" id="saRestFilter"><button type="button" class="on" data-sa-rf="all">All</button><button type="button" data-sa-rf="active">Active</button><button type="button" data-sa-rf="inactive">Disabled</button><button type="button" data-sa-rf="trial">On trial</button><button type="button" data-sa-rf="expired">Trial expired</button></div></div>
            <div class="sa-search"><span class="material-symbols-rounded">search</span><input id="saSearch" placeholder="Name, login or ID"></div>
          </div>
          <div class="card-body">
            <div id="spBulk" class="sp-bulk" hidden></div>
            <div style="overflow-x:auto;">
              <table id="restaurantsTable">
                <thead>
                  <tr>
                    <th class="sp-ck"><input type="checkbox" id="spCkAll" aria-label="Select all on this page"></th><th>Restaurant</th><th>Features (click to switch)</th><th>Trial</th><th>Status</th><th></th>
                  </tr>
                </thead>
                <tbody id="restaurantsTbody"></tbody>
              </table>
            </div>
            <div class="sa-pager">
              <button class="btn btn-outline" id="prevPage" disabled><span class="material-symbols-rounded">chevron_left</span>Previous</button>
              <div id="pageInfo" style="color:var(--muted)">Page 1</div>
              <button class="btn btn-outline" id="nextPage" disabled>Next<span class="material-symbols-rounded">chevron_right</span></button>
            </div>
          </div>
        </div>
      </div>

      <!-- Menus Page -->
      <div id="menusPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Menu from photos</h1><div class="sa-sub">Turn photos of a printed menu into a restaurant’s categories and dishes</div></div>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <div class="card-title">Select Restaurant</div>
          </div>
          <div class="card-body">
            <div class="form-group">
              <label>Restaurant</label>
              <select id="menuRestaurantSelect" style="width:100%;max-width:400px;">
                <option value="">Select a restaurant...</option>
              </select>
            </div>
          </div>
        </div>
        <div id="menuCategoriesSection" class="card" style="display:none;">
          <div class="card-header">
            <div class="card-title">Menu Categories for <span id="selectedRestaurantName"></span></div>
          </div>
          <div class="card-body">
            <div class="form-group">
              <label>Categories & Subcategories</label>
              <textarea id="menuCategoriesInput" rows="3" style="width:100%;" placeholder="Pizza (Margherita, Pepperoni, BBQ Chicken), Burger (Classic, Cheese, Veg), Beverages"></textarea>
            </div>
            <button class="btn btn-primary" id="saveMenuCategories">Save Categories & Subcategories</button>
            <hr style="margin:16px 0;">
            <div id="parsedMenuPreview"></div>

            <div style="margin-top:16px;padding:14px;border:1px solid #16a34a;border-radius:8px;background:#f0fdf4;">
              <div style="font-size:0.95rem;font-weight:600;margin-bottom:4px;">✨ Generate Menu Items from Photos or PDF (AI)</div>
              <div style="font-size:0.8rem;color:var(--muted);margin-bottom:10px;">Select the restaurant above, upload photos of the menu or a menu PDF, and AI will read it and fill in the items below automatically. You still review before saving.</div>
              <input type="file" id="menuImagesInput" accept="image/png,image/jpeg,image/webp,image/gif,application/pdf,.pdf" multiple style="width:100%;margin-bottom:10px;">
              <button class="btn btn-primary" id="generateFromImagesBtn" type="button">Generate Items with AI</button>
              <div id="aiGenerateStatus" style="font-size:0.8rem;margin-top:8px;"></div>
            </div>

            <details style="margin-top:16px;">
              <summary style="cursor:pointer;font-size:0.85rem;color:var(--muted);">Prefer to use ChatGPT / another AI manually instead?</summary>
              <div style="margin-top:10px;padding:12px;border:1px dashed var(--border);border-radius:8px;background:#f9fafb;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                  <strong style="font-size:0.9rem;">AI Prompt — Copy & paste with your menu screenshot</strong>
                  <button class="btn btn-sm btn-outline" onclick="copyPrompt()">Copy</button>
                </div>
                <textarea id="aiPromptText" rows="4" style="width:100%;font-size:0.8rem;font-family:monospace;background:#fff;" readonly></textarea>
              </div>
            </details>
            <hr style="margin:20px 0;">
            <div class="form-group">
              <label>Bulk Menu Items</label>
              <textarea id="menuItemsInput" rows="4" style="width:100%;font-family:monospace;" placeholder="Category[ &gt; Subcategory] | Item Name | Price | Type | Description | PrepTime | Calories | Stock | Variations(Name:Price) | ImageURL&#10;Pizza &gt; Margherita | Classic Margherita | 199 | Veg | Classic pizza | 15 | 300 | 1 | Small:149,Medium:199 | https://example.com/pizza.jpg&#10;Burger | Veg Burger | 129 | Veg | Crispy patty | 10 | 380 | 1 | Single:129 |"></textarea>
              <small style="color:var(--muted);font-size:0.8rem;">One per line. Fields: <code>Category[ &gt; Subcategory] | Item Name | Price | Type | Description | PrepTime | Calories | Stock | Variations(Name:Price,...) | ImageURL</code></small>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <button class="btn btn-primary" id="saveMenuItemsReplace">Replace All Items</button>
              <button class="btn btn-outline" id="saveMenuItemsUpdate">Add / Update Only</button>
            </div>
            <div id="parsedItemsPreview" style="margin-top:12px;"></div>
          </div>
        </div>
      </div>

      <!-- Subscriptions Page -->
      <div id="subscriptionsPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Subscriptions</h1><div class="sa-sub">Plans, trials and renewals for every restaurant</div></div>
          </div>
        </div>
        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-label">Active Subscriptions</div>
            <div class="stat-value" id="subActive">0</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Trial Users</div>
            <div class="stat-value" id="subTrial">0</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Expired</div>
            <div class="stat-value" id="subExpired">0</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Total Revenue (Monthly)</div>
            <div class="stat-value" id="subRevenue">₹0</div>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <div class="card-title">Subscription Details</div>
            <input id="subSearch" placeholder="Search restaurants..." style="width:300px;">
          </div>
          <div class="card-body">
            <div style="overflow-x:auto;">
              <table>
                <thead>
                  <tr>
                    <th>Restaurant</th><th>Status</th><th>Trial Ends</th><th>Renewal Date</th><th>Actions</th>
                  </tr>
                </thead>
                <tbody id="subscriptionsTbody"></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Payments Page -->
      <div id="paymentsPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Payments</h1><div class="sa-sub">Every subscription payment received</div></div>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <div class="card-title">All Payments</div>
            <div class="row">
              <input id="paymentSearch" placeholder="Search..." style="width:200px;">
              <select id="paymentStatusFilter" style="width:150px;">
                <option value="">All Status</option>
                <option value="success">Success</option>
                <option value="failed">Failed</option>
                <option value="pending">Pending</option>
              </select>
            </div>
          </div>
          <div class="card-body">
            <div style="overflow-x:auto;">
              <table>
                <thead>
                  <tr>
                    <th>Transaction ID</th><th>Restaurant</th><th>Amount</th><th>Type</th><th>Status</th><th>Date</th>
                  </tr>
                </thead>
                <tbody id="paymentsTbody"></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Payment Links Page -->
      <div id="paymentLinksPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Payment links</h1><div class="sa-sub">Send a restaurant a link to pay for its plan</div></div>
          </div>
          <div class="header-actions" style="display:flex;gap:8px;align-items:center;">
            <span id="phonepeModeLabel" style="font-size:0.85rem;font-weight:600;padding:4px 12px;border-radius:20px;"></span>
            <button class="btn btn-outline" id="btnTogglePhonePeMode" style="font-size:0.8rem;">Switch Mode</button>
            <button class="btn btn-primary" id="btnCreatePaymentLink">+ New Payment Link</button>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <div class="card-title">All Payment Links</div>
            <div class="row">
              <select id="plRestaurantFilter" style="width:200px;">
                <option value="">All Restaurants</option>
              </select>
              <select id="plStatusFilter" style="width:150px;">
                <option value="">All Status</option>
                <option value="Pending">Pending</option>
                <option value="Success">Success</option>
                <option value="Failed">Failed</option>
              </select>
            </div>
          </div>
          <div class="card-body">
            <div style="overflow-x:auto;">
              <table id="paymentLinksTable">
                <thead>
                  <tr>
                    <th>ID</th><th>Restaurant</th><th>Amount</th><th>Transaction ID</th><th>Status</th><th>Payment Link</th><th>Notes</th><th>Created</th><th>Actions</th>
                  </tr>
                </thead>
                <tbody id="paymentLinksTbody">
                  <tr><td colspan="9" style="text-align:center;padding:20px;color:var(--muted);">Loading...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Create Payment Link Modal -->
      <div id="createPaymentLinkModal" class="modal">
        <div class="modal-content" style="max-width:450px;">
          <div class="card-header">
            <div class="card-title">Create Payment Link</div>
            <button id="cplmClose" class="btn btn-outline">Close</button>
          </div>
          <div class="card-body">
            <div class="form-group">
              <label>Restaurant</label>
              <select id="cplmRestaurant" style="width:100%;">
                <option value="">Select restaurant...</option>
              </select>
            </div>
            <div class="form-group">
              <label>Amount (₹)</label>
              <input id="cplmAmount" type="number" step="0.01" min="1" placeholder="Enter amount" style="width:100%;">
            </div>
            <div class="form-group">
              <label>Notes (optional)</label>
              <input id="cplmNotes" placeholder="e.g. Monthly subscription" style="width:100%;">
            </div>
            <button id="cplmCreate" type="button" class="btn btn-primary" style="width:100%;">Generate Payment Link</button>
          </div>
        </div>
      </div>

      <!-- Settlements Page -->
      <div id="settlementsPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Settlements</h1><div class="sa-sub">Money paid out to restaurants</div></div>
          </div>
          <div class="header-actions">
            <span id="settlementModeLabel" style="font-size:0.85rem;font-weight:600;padding:4px 12px;border-radius:20px;"></span>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <div class="card-title">New Settlement</div>
          </div>
          <div class="card-body">
            <div class="row" style="gap:12px;">
              <div class="form-group" style="flex:2;min-width:200px;">
                <label>UPI ID</label>
                <input id="stlUpi" type="text" placeholder="e.g. example@paytm" style="width:100%;">
              </div>
              <div class="form-group" style="flex:1;min-width:120px;">
                <label>Amount (₹)</label>
                <input id="stlAmount" type="number" step="0.01" min="1" placeholder="0" style="width:100%;">
              </div>
              <div class="form-group" style="flex:1;min-width:120px;">
                <label>Notes (optional)</label>
                <input id="stlNotes" placeholder="e.g. Weekly payout" style="width:100%;">
              </div>
              <div class="form-group" style="display:flex;align-items:flex-end;">
                <button id="btnSettle" class="btn btn-primary" style="padding:10px 24px;">Settle Now</button>
              </div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <div class="card-title">Settlement History</div>
          </div>
          <div class="card-body">
            <div style="overflow-x:auto;">
              <table>
                <thead>
                  <tr>
                    <th>ID</th><th>UPI ID</th><th>Amount</th><th>Transaction ID</th><th>Status</th><th>Created By</th><th>Date</th>
                  </tr>
                </thead>
                <tbody id="settlementsTbody">
                  <tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted);">Loading...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Analytics Page -->
      <div id="analyticsPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Analytics</h1><div class="sa-sub">Revenue, growth, trials and which restaurants need attention</div></div>
          </div>
        </div>
        <div id="spAnalytics" class="sp-body"><div class="sp-loading">Loading…</div></div>
      </div>

      <!-- Branch Admins Page -->
      <div id="branchAdminsPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Branch admins</h1><div class="sa-sub">Owners who manage several outlets from one login</div></div>
          </div>
          <div class="header-actions">
            <button class="btn btn-primary" id="btnNewBranchAdmin">New Branch Admin</button>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <div class="card-title">All Branch Admins</div>
          </div>
          <div class="card-body">
            <div style="overflow-x:auto;">
              <table>
                <thead>
                  <tr>
                    <th>ID</th><th>Username</th><th>Display Name</th><th>Linked Restaurants</th><th>Status</th><th>Created</th><th>Actions</th>
                  </tr>
                </thead>
                <tbody id="branchAdminsTbody">
                  <tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted);">Loading...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Create Branch Admin Modal -->
      <div id="createBranchAdminModal" class="modal">
        <div class="modal-content">
          <div class="card-header">
            <div class="card-title">Create Branch Admin</div>
            <button id="cbamClose" class="btn btn-outline">Close</button>
          </div>
          <div class="card-body">
            <div class="form-group">
              <label>Username</label>
              <input id="cbamUser" placeholder="Branch admin username">
            </div>
            <div class="form-group">
              <label>Display Name</label>
              <input id="cbamName" placeholder="Display name (optional)">
            </div>
            <div class="form-group">
              <label>Password</label>
              <input id="cbamPass" type="password" placeholder="Password">
            </div>
            <div class="form-group">
              <label>Linked Restaurants</label>
              <div id="cbamRestaurants" style="max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;padding:8px;">
                <div style="color:var(--muted);font-size:0.85rem;">Loading restaurants...</div>
              </div>
            </div>
            <button id="cbamCreate" class="btn btn-primary">Create Branch Admin</button>
          </div>
        </div>
      </div>

      <!-- Settings Page -->
      <div id="settingsPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Settings</h1><div class="sa-sub">Trial length, plan price, renewals and password resets</div></div>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <div class="card-title">System Settings</div>
          </div>
          <div class="card-body">
            <div class="form-group">
              <label>Trial Period (Days)</label>
              <input type="number" id="trialDays" value="30" min="1" max="365">
            </div>
            <div class="form-group">
              <label>Subscription Price (₹)</label>
              <input type="number" id="subscriptionPrice" value="999" min="1">
            </div>
            <div class="form-group">
              <label>Auto-renewal</label>
              <select id="autoRenewal">
                <option value="1">Enabled</option>
                <option value="0">Disabled</option>
              </select>
            </div>
            <div class="form-group">
              <label>Yearly price (₹)</label>
              <input type="number" id="subscriptionPriceYearly" value="9999" min="0">
            </div>
            <div class="form-group">
              <label>Show in Renewals this many days before the end</label>
              <input type="number" id="renewalNoticeDays" value="7" min="1" max="60">
            </div>
            <div class="sp-sep">Your business details (printed on invoices)</div>
            <div class="sp-grid2">
              <div class="form-group"><label>Business name</label><input id="bizName" maxlength="160"></div>
              <div class="form-group"><label>GSTIN</label><input id="bizGstin" maxlength="15" placeholder="e.g. 29ABCDE1234F1Z5"></div>
              <div class="form-group"><label>Email</label><input id="bizEmail" type="email" maxlength="160"></div>
              <div class="form-group"><label>Phone</label><input id="bizPhone" maxlength="20"></div>
            </div>
            <div class="form-group"><label>Address</label><textarea id="bizAddress" rows="2" maxlength="500" style="width:100%"></textarea></div>
            <div class="sp-grid2">
              <div class="form-group"><label>GST %</label><input type="number" id="gstPercent" value="18" min="0" max="40" step="0.01"></div>
              <div class="form-group"><label>Plan prices</label><select id="pricesIncludeGst"><option value="1">Include GST</option><option value="0">GST added on top</option></select></div>
              <div class="form-group"><label>Invoice number prefix</label><input id="invoicePrefix" maxlength="10" value="RG"></div>
            </div>
            <div class="sp-grid2">
              <div class="form-group"><label>UPI ID (QR code on unpaid invoices)</label><input id="bizUpi" maxlength="80" placeholder="e.g. restrogrow@okaxis"></div>
              <div class="form-group"><label>Bank details (on unpaid invoices)</label><textarea id="bizBank" rows="3" maxlength="400" style="width:100%" placeholder="Account name&#10;A/c no. · IFSC · Bank"></textarea></div>
            </div>
            <button class="btn btn-primary" id="saveSettings">Save Settings</button>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><div class="card-title">Email check</div><small class="sp-muted">Sends a fun “Offer accepted” email using the email settings in main/.env</small></div>
          <div class="card-body">
            <div class="row" style="gap:8px">
              <select id="spTestEmailFrom" aria-label="Send from" style="min-width:240px"><option value="">Default sender</option></select>
              <input type="email" id="spTestEmailTo" value="asfluencernandkishor@gmail.com" style="flex:1;min-width:240px;max-width:420px" aria-label="Send the test email to">
              <button type="button" class="btn btn-primary" id="spTestEmailBtn"><span class="material-symbols-rounded">send</span>Send test email</button>
            </div>
            <div id="spTestEmailResult" class="sp-muted" style="margin-top:10px;font-size:.88rem"></div>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <div class="card-title">Password Reset Data</div>
          </div>
          <div class="card-body">
            <div style="overflow-x:auto;">
              <table id="passwordResetTable">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Restaurant</th>
                    <th>Email</th>
                    <th>Token</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Expires</th>
                  </tr>
                </thead>
                <tbody id="passwordResetTbody">
                  <tr><td colspan="8" style="text-align:center;padding:20px;color:var(--muted);">Loading...</td></tr>
                </tbody>
              </table>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;">
              <button class="btn btn-outline" id="prevResetPage">Prev</button>
              <div id="resetPageInfo" style="color:var(--muted)">Page 1</div>
              <button class="btn btn-outline" id="nextResetPage">Next</button>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <div class="card-title">Database Tools</div>
          </div>
          <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;">
              <a href="../admin/run_indexes_both_dbs.php" target="_blank" class="btn btn-outline" style="text-decoration:none;display:block;text-align:center;">
                <span class="material-symbols-rounded" style="vertical-align:middle;margin-right:8px;">speed</span>
                Run Database Indexes
              </a>
              <a href="../admin/run_password_reset_table_both_dbs.php" target="_blank" class="btn btn-outline" style="text-decoration:none;display:block;text-align:center;">
                <span class="material-symbols-rounded" style="vertical-align:middle;margin-right:8px;">lock_reset</span>
                Password Reset Tokens Migration
              </a>
            </div>
          </div>
        </div>
      </div>

      <div id="renewalsPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Renewals</h1><div class="sa-sub">Plans and trials ending soon: send a payment link, remind, or extend</div></div>
          </div>
          <div class="header-actions" id="spRenewalsActs"></div>
        </div>
        <div id="spRenewals" class="sp-body"><div class="sp-loading">Loading…</div></div>
      </div>

      <div id="couponsPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Coupons</h1><div class="sa-sub">Discount codes of every restaurant</div></div>
          </div>
          <div class="header-actions" id="spCouponsActs"></div>
        </div>
        <div id="spCoupons" class="sp-body"><div class="sp-loading">Loading…</div></div>
      </div>

      <div id="leadsPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Leads</h1><div class="sa-sub">Enquiries from the RestroGrow website and the ones you add</div></div>
          </div>
          <div class="header-actions" id="spLeadsActs"></div>
        </div>
        <div id="spLeads" class="sp-body"><div class="sp-loading">Loading…</div></div>
      </div>

      <div id="chatLeadsPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Chat leads</h1><div class="sa-sub">Contact details and complete conversations from the website chatbot</div></div>
          </div>
          <div class="header-actions" id="spChatLeadsActs"></div>
        </div>
        <div id="spChatLeads" class="sp-body"><div class="sp-loading">Loading…</div></div>
        <div id="spChatConversation" class="sp-body"></div>
      </div>

      <div id="supportPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Support</h1><div class="sa-sub">Requests sent from restaurant dashboards</div></div>
          </div>
          <div class="header-actions" id="spSupportActs"></div>
        </div>
        <div id="spSupport" class="sp-body"><div class="sp-loading">Loading…</div></div>
      </div>

      <div id="announcementsPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Announcements</h1><div class="sa-sub">A notice shown at the top of restaurant dashboards</div></div>
          </div>
          <div class="header-actions" id="spAnnouncementsActs"></div>
        </div>
        <div id="spAnnouncements" class="sp-body"><div class="sp-loading">Loading…</div></div>
      </div>

      <div id="invoicesPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Invoices & GST</h1><div class="sa-sub">An invoice for every subscription payment, and a monthly export for your accountant</div></div>
          </div>
          <div class="header-actions" id="spInvoicesActs"></div>
        </div>
        <div id="spInvoices" class="sp-body"><div class="sp-loading">Loading…</div></div>
      </div>

      <div id="invoiceGenPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Invoice generator</h1><div class="sa-sub">Pick the restaurant, plan and extras. The number, dates, GST and PDF are done for you</div></div>
          </div>
          <div class="header-actions" id="spInvoiceGenActs"></div>
        </div>
        <div id="spInvoiceGen" class="sp-body"><div class="sp-loading">Loading…</div></div>
      </div>

      <div id="securityPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Security & activity</h1><div class="sa-sub">Logins, blocked IPs and everything done in this panel</div></div>
          </div>
          <div class="header-actions" id="spSecurityActs"></div>
        </div>
        <div id="spSecurity" class="sp-body"><div class="sp-loading">Loading…</div></div>
      </div>

      <div id="healthPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Platform health</h1><div class="sa-sub">Database, storage, WhatsApp queue, notifications and errors</div></div>
          </div>
          <div class="header-actions" id="spHealthActs"></div>
        </div>
        <div id="spHealth" class="sp-body"><div class="sp-loading">Loading…</div></div>
      </div>

      <!-- ═══════════════════════════════════════════════════════════
           ERROR MONITOR PAGE
           ═══════════════════════════════════════════════════════════ -->
      <div id="errorMonitorPage" class="page">
        <div class="page-header">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <div><h1>Error monitor <span id="saErrorNewBadge" class="sa-h-badge" style="display:none;">New</span></h1><div class="sa-sub">Errors from every restaurant’s site and dashboard</div></div>
          </div>
          <div class="header-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
            <select id="saErrorSource" style="width:130px;padding:6px 10px;border-radius:6px;border:1.5px solid var(--border);font-size:13px;">
              <option value="all">All Sources</option>
              <option value="php">PHP</option>
              <option value="js">JS</option>
              <option value="api">API</option>
              <option value="auth">Auth</option>
              <option value="db">DB</option>
              <option value="state_machine">State</option>
              <option value="custom">Custom</option>
            </select>
            <select id="saErrorSeverity" style="width:130px;padding:6px 10px;border-radius:6px;border:1.5px solid var(--border);font-size:13px;">
              <option value="all">All Severity</option>
              <option value="critical">Critical</option>
              <option value="error">Error</option>
              <option value="warning">Warning</option>
              <option value="info">Info</option>
            </select>
            <input type="text" id="saErrorSearch" placeholder="Search..." style="width:160px;padding:6px 10px;border-radius:6px;border:1.5px solid var(--border);font-size:13px;">
            <button class="btn btn-outline btn-sm" onclick="saLoadErrorLogs()">Refresh</button>
            <button class="btn btn-outline btn-sm" onclick="saMarkAllRead()">Mark Read</button>
          </div>
        </div>
        <!-- Sentry (config/sentry.php + config/sentry_browser.php) -->
        <div class="stat-card" id="saSentryCard" style="padding:14px;margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
          <span class="material-symbols-rounded" style="font-size:28px;color:#7c3aed;">monitoring</span>
          <div style="flex:1 1 240px;min-width:0;">
            <div style="font-weight:700;">Sentry error monitoring <span id="saSentryState" class="badge" style="margin-left:6px;">Checking…</span></div>
            <div class="sa-sub" id="saSentryInfo" style="margin-top:2px;">Server errors, failed requests (500) and dashboard JavaScript errors from every restaurant go to Sentry.</div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-outline btn-sm" onclick="saSentryTest()">Send server test error</button>
            <button class="btn btn-outline btn-sm" onclick="saSentryBrowserTest()">Send browser test error</button>
            <a class="btn btn-outline btn-sm" href="https://sentry.io/issues/" target="_blank" rel="noopener">Open Sentry</a>
          </div>
        </div>
        <!-- Summary cards -->
        <div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px;">
          <div class="stat-card" style="padding:14px;">
            <div class="stat-label">Critical</div>
            <div class="stat-value" id="saCritCount" style="font-size:1.4rem;color:#dc2626;">0</div>
          </div>
          <div class="stat-card" style="padding:14px;">
            <div class="stat-label">Errors</div>
            <div class="stat-value" id="saErrCount" style="font-size:1.4rem;color:#ef4444;">0</div>
          </div>
          <div class="stat-card" style="padding:14px;">
            <div class="stat-label">Warnings</div>
            <div class="stat-value" id="saWarnCount" style="font-size:1.4rem;color:#f59e0b;">0</div>
          </div>
          <div class="stat-card" style="padding:14px;">
            <div class="stat-label">Unread</div>
            <div class="stat-value" id="saUnreadCount" style="font-size:1.4rem;color:#3b82f6;">0</div>
          </div>
        </div>
        <!-- Error table -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Error Logs</div>
            <span id="saErrorTotal" style="font-size:13px;color:var(--muted);"></span>
          </div>
          <div class="card-body" id="saErrorList" style="padding:12px;">
            <div class="loading">Loading...</div>
          </div>
        </div>
      </div>
    </div>

  <!-- Create Restaurant Modal -->
  <!-- Restaurants › Access: which dashboard sections this restaurant gets (config/access_control.php) -->
  <div id="accessModal" class="modal">
    <div class="modal-content ac-box">
      <div class="card-header">
        <div><div class="card-title">Access <span id="acName" class="ac-name"></span></div>
          <div class="ac-sub">Switch whole sections or single pages. Off = gone from their dashboard and apps, and stopped for their customers too (e.g. Loyalty off = no points or rewards on their website). Their own settings are kept for when you switch it back on. Always on: Dashboard, Menu, Orders, Settings, Profile.</div></div>
        <button id="acClose" type="button" class="btn btn-outline">Close</button>
      </div>
      <div class="card-body">
        <div class="ac-presets" id="acPresets"></div>
        <div class="ac-grid" id="acGrid"><div class="ac-sub">Loading…</div></div>
      </div>
      <div class="ac-foot"><span id="acCount" class="ac-sub"></span><button id="acSave" type="button" class="btn btn-primary">Save access</button></div>
    </div>
  </div>
  <style>
    .ac-box { max-width: 880px; width: 100%; max-height: 92vh; display: flex; flex-direction: column; }
    .ac-box .card-body { overflow: auto; }
    .ac-box .card-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
    .ac-name { color: #6b7280; font-weight: 700; }
    .ac-sub { color: #6b7280; font-size: .8rem; margin-top: 4px; line-height: 1.4; }
    .ac-presets { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
    .ac-presets button { border: 1px solid #e5e7eb; background: #fff; border-radius: 999px; padding: 6px 12px; font: inherit; font-size: .8rem; font-weight: 700; cursor: pointer; }
    .ac-presets button:hover { border-color: #93c5fd; background: #eff6ff; }
    .ac-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; }
    .ac-mod { border: 1px solid #e5e7eb; border-radius: 14px; padding: 12px; background: #fff; }
    .ac-mod.off { background: #f9fafb; }
    .ac-mod.off .ac-mt { color: #9ca3af; }
    .ac-mh { display: flex; align-items: flex-start; gap: 8px; }
    .ac-mh .material-symbols-rounded { font-size: 20px; color: #4f46e5; }
    .ac-mod.off .ac-mh .material-symbols-rounded { color: #9ca3af; }
    .ac-mt { font-weight: 800; font-size: .9rem; flex: 1; }
    .ac-mt small { display: block; font-weight: 500; color: #6b7280; font-size: .74rem; margin-top: 2px; }
    .ac-sw { position: relative; width: 38px; height: 22px; flex: none; }
    .ac-sw input { opacity: 0; width: 100%; height: 100%; margin: 0; position: absolute; cursor: pointer; z-index: 1; }
    .ac-sw i { position: absolute; inset: 0; border-radius: 999px; background: #d1d5db; transition: .15s; }
    .ac-sw i::after { content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: .15s; }
    .ac-sw input:checked + i { background: #16a34a; }
    .ac-sw input:checked + i::after { left: 19px; }
    .ac-sw input:indeterminate + i { background: #f59e0b; }
    .ac-sw input:indeterminate + i::after { left: 11px; }
    .ac-sw input:focus-visible + i { outline: 2px solid #2563eb; outline-offset: 2px; }
    .ac-pages { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 10px; }
    .ac-pages label { display: inline-flex; align-items: center; gap: 4px; font-size: .74rem; font-weight: 700; padding: 3px 9px 3px 5px; border-radius: 999px; border: 1px solid #bbf7d0; background: #f0fdf4; color: #15803d; cursor: pointer; }
    .ac-pages label.off { border-color: #e5e7eb; background: #fff; color: #9ca3af; text-decoration: line-through; }
    .ac-pages input { margin: 0; accent-color: #16a34a; }
    .ac-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 20px 16px; border-top: 1px solid #e5e7eb; }
    @media (max-width: 560px) { .ac-grid { grid-template-columns: 1fr; } .ac-foot { flex-direction: column; align-items: stretch; } }
  </style>

  <div id="createModal" class="modal">
    <div class="modal-content">
      <div class="card-header">
        <div class="card-title">Create New Restaurant</div>
        <button id="cmClose" class="btn btn-outline">Close</button>
      </div>
      <div class="card-body">
        <div class="form-group">
          <label>Restaurant Name</label>
          <input id="cmName" placeholder="Enter restaurant name">
        </div>
        <div class="form-group">
          <label>Admin Username</label>
          <input id="cmUser" placeholder="Enter username">
        </div>
        <div class="form-group">
          <label>Admin Password</label>
          <input id="cmPass" type="password" placeholder="Enter password">
        </div>
        <div class="form-group">
          <label>Admin Email (optional)</label>
          <input id="cmEmail" type="email" placeholder="owner@restaurant.com">
          <small style="color:var(--muted);display:block;margin-top:4px;">If left blank, this restaurant's "Forgot Password" won't work until an email is added later in Settings.</small>
        </div>
        <div class="form-group">
          <label>Country</label>
          <select id="cmCountry">
            <?php foreach (getCountryData() as $iso2 => $c): ?>
            <option value="<?php echo htmlspecialchars($c['name']); ?>" <?php echo $iso2 === 'IN' ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <small style="color:var(--muted);display:block;margin-top:4px;">Sets the restaurant's default currency and menu display — this is not RestroGrow's own subscription billing, which stays in INR.</small>
        </div>
        <small style="color:var(--muted);display:block;margin-bottom:16px;">Restaurant ID will be generated automatically.</small>
        <button id="cmCreate" class="btn btn-primary">Create Restaurant</button>
      </div>
    </div>
  </div>

  <script>
    // ── Error Monitor Functions ────────────────────────────────────────────
    var saErrorPollTimer = null;
    
    // ── Sentry card on the Error monitor page ──
    function saSentrySay(text, ok) {
      var s = document.getElementById('saSentryInfo');
      if (s) { s.textContent = text; s.style.color = ok === false ? '#dc2626' : ok ? '#047857' : ''; }
    }
    async function saSentryStatus() {
      var b = document.getElementById('saSentryState');
      try {
        var d = await (await fetch('api.php?action=sentryStatus')).json();
        if (b) { b.textContent = d.on ? 'On · ' + d.environment : 'Off'; b.style.background = d.on ? '#dcfce7' : '#fee2e2'; b.style.color = d.on ? '#047857' : '#b91c1c'; }
      } catch (e) { if (b) b.textContent = 'Unknown'; }
    }
    async function saSentryTest() {
      saSentrySay('Sending a test error from the server…');
      try {
        var d = await (await fetch('api.php?action=sentryTest', { method: 'POST' })).json();
        saSentrySay(d.success ? d.message + '. Check Sentry → Issues for "RestroGrow Sentry test".' : (d.message || 'Sending failed'), !!d.success);
      } catch (e) { saSentrySay('Sending failed: ' + e.message, false); }
    }
    function saSentryBrowserTest() {
      if (!window.Sentry || !Sentry.onLoad) { saSentrySay('Sentry script did not load in this browser (an ad blocker can stop it).', false); return; }
      saSentrySay('Sending a test error from this browser…');
      Sentry.onLoad(function () {
        Sentry.captureException(new Error('RestroGrow Sentry test from the superadmin browser (safe to ignore)'));
        Sentry.flush(5000).then(function (ok) {
          saSentrySay(ok ? 'Browser test error sent. Check Sentry → Issues for "RestroGrow Sentry test".' : 'Sentry did not confirm the browser test.', ok);
        });
      });
    }

    function saLoadErrorLogs() {
      saSentryStatus();
      var container = document.getElementById('saErrorList');
      if (!container) return;
      container.innerHTML = '<div class="loading">Loading...</div>';
      
      var src = (document.getElementById('saErrorSource')||{}).value || 'all';
      var sev = (document.getElementById('saErrorSeverity')||{}).value || 'all';
      var q = (document.getElementById('saErrorSearch')||{}).value || '';
      
      var url = '../api/get_error_logs.php?limit=100';
      if (src !== 'all') url += '&source=' + encodeURIComponent(src);
      if (sev !== 'all') url += '&severity=' + encodeURIComponent(sev);
      if (q) url += '&search=' + encodeURIComponent(q);
      
      fetch(url, { cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (!d.success) { container.innerHTML = '<div class="empty-state"><p>Failed</p></div>'; return; }
          var crit=0, errs=0, warns=0;
          for (var i=0; i<d.errors.length; i++) {
            var e = d.errors[i];
            if (e.severity === 'critical') crit++;
            else if (e.severity === 'error') errs++;
            else if (e.severity === 'warning') warns++;
          }
          document.getElementById('saCritCount').textContent = crit;
          document.getElementById('saErrCount').textContent = errs;
          document.getElementById('saWarnCount').textContent = warns;
          document.getElementById('saUnreadCount').textContent = d.unread_count;
          document.getElementById('saErrorTotal').textContent = 'Total: ' + d.total;
          
          // Badge
          var badge = document.getElementById('saErrorBadge');
          if (badge) {
            if (d.unread_count > 0) { badge.style.display = 'inline-block'; badge.textContent = d.unread_count > 99 ? '99+' : d.unread_count; }
            else { badge.style.display = 'none'; }
          }
          var nb = document.getElementById('saErrorNewBadge');
          if (nb) { nb.style.display = d.unread_count > 0 ? 'inline-flex' : 'none'; nb.textContent = (d.unread_count > 99 ? '99+' : d.unread_count) + ' new'; }
          
          if (d.errors.length === 0) {
            container.innerHTML = '<div class="empty-state"><div class="icon">✓</div><h3>All Clear!</h3></div>';
            return;
          }
          
          var html = '<div style="overflow-x:auto;"><table><thead><tr><th>Time</th><th>Source</th><th>Severity</th><th>Message</th><th>File</th><th></th></tr></thead><tbody>';
          for (var i=0; i<d.errors.length; i++) {
            var e = d.errors[i];
            var sc = '#6b7280', sb = '#f3f4f6';
            if (e.severity === 'critical') { sc='#fff'; sb='#dc2626'; }
            else if (e.severity === 'error') { sc='#fff'; sb='#ef4444'; }
            else if (e.severity === 'warning') { sc='#92400e'; sb='#fef3c7'; }
            else if (e.severity === 'info') { sc='#1e40af'; sb='#dbeafe'; }
            var unreadClass = e.is_read ? '' : 'em-unread';
            var msg = (e.message_short || e.message || '').slice(0, 200);
            html += '<tr class="' + unreadClass + '"><td style="white-space:nowrap;font-size:12px;color:var(--muted);">' + (e.created_at_formatted||'') + '</td>';
            html += '<td><span class="badge badge-info" style="font-size:10px;">' + (e.source||'').toUpperCase() + '</span></td>';
            html += '<td><span class="badge" style="background:'+sb+';color:'+sc+';font-size:10px;">' + (e.severity||'').toUpperCase() + '</span></td>';
            html += '<td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;">' + saEsc(msg) + '</td>';
            html += '<td style="font-size:11px;color:var(--muted);font-family:monospace;">' + saEsc((e.file||'') + ':' + (e.line||'')) + '</td>';
            html += '<td><button class="btn btn-sm btn-outline" onclick="saViewError('+e.id+')">View</button></td></tr>';
          }
          html += '</tbody></table></div>';
          container.innerHTML = html;
        })
        .catch(function() { container.innerHTML = '<div class="empty-state"><p>Connection error</p></div>'; });
    }
    
    function saEsc(s) { if (!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    
    function saViewError(id) {
      fetch('../api/get_error_logs.php?limit=1&since_id=' + (id-1), { cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (!d.success || !d.errors || d.errors.length === 0 || parseInt(d.errors[0].id) !== id) {
            showSuperAlert('Error not found', 'error'); return;
          }
          var e = d.errors[0];
          var ctxHtml = '';
          if (e.context) {
            try { var ctx = typeof e.context === 'string' ? JSON.parse(e.context) : e.context;
              ctxHtml = '<pre style="background:#1f2937;color:#e5e7eb;padding:12px;border-radius:8px;font-size:12px;overflow:auto;max-height:200px;text-align:left;margin-top:8px;">' + saEsc(JSON.stringify(ctx, null, 2)) + '</pre>'; } catch(ex) {}
          }
          var tr = '';
          if (e.trace) tr = '<div style="margin-top:8px;"><strong>Trace:</strong><pre style="background:#1f2937;color:#e5e7eb;padding:12px;border-radius:8px;font-size:11px;overflow:auto;max-height:200px;text-align:left;margin-top:4px;">' + saEsc(e.trace) + '</pre></div>';
          
          if (window.Swal) {
            Swal.fire({
              title: 'Error #' + e.id,
              html: '<div style="text-align:left;font-size:13px;"><div style="margin-bottom:6px;"><strong>Message:</strong><br>' + saEsc(e.message) + '</div>'
                + '<div style="margin-bottom:6px;"><strong>Source:</strong> ' + (e.source||'N/A') + ' | <strong>Severity:</strong> ' + (e.severity||'N/A') + '</div>'
                + '<div style="margin-bottom:6px;"><strong>File:</strong> ' + saEsc(e.file||'N/A') + ':' + (e.line||'N/A') + '</div>'
                + '<div style="margin-bottom:6px;"><strong>Time:</strong> ' + (e.created_at_formatted||e.created_at) + '</div>'
                + (e.url ? '<div style="margin-bottom:6px;"><strong>URL:</strong> ' + saEsc(e.url) + '</div>' : '')
                + (e.ip_address ? '<div><strong>IP:</strong> ' + e.ip_address + '</div>' : '')
                + ctxHtml + tr + '</div>',
              confirmButtonText: 'Acknowledge',
              confirmButtonColor: '#111827',
              showCancelButton: true,
              cancelButtonText: 'Close',
            }).then(function(r) { if (r.isConfirmed) saMarkRead(id); });
          }
          saMarkRead(id, true);
        }).catch(function() { showSuperAlert('Failed', 'error'); });
    }
    
    function saMarkRead(id, silent) {
      var fd = new URLSearchParams(); fd.append('action','mark_read'); fd.append('id',id);
      fetch('../api/clear_error_log.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:fd.toString() })
        .then(function(r) { return r.json(); })
        .then(function(d) { if (d.success) { if (!silent) saLoadErrorLogs(); else setTimeout(saCheckBadge, 500); } })
        .catch(function() {});
    }
    
    function saMarkAllRead() {
      if (!window.Swal) return;
      Swal.fire({ title:'Mark all read?', icon:'question', showCancelButton:true, confirmButtonColor:'#111827', cancelButtonColor:'#6b7280', confirmButtonText:'Yes' })
        .then(function(r) {
          if (!r.isConfirmed) return;
          var fd = new URLSearchParams(); fd.append('action','mark_all_read');
          fetch('../api/clear_error_log.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:fd.toString() })
            .then(function(r) { return r.json(); })
            .then(function(d) { if (d.success) { saLoadErrorLogs(); showSuperAlert('All marked read','success'); } })
            .catch(function() {});
        });
    }
    
    function saCheckBadge() {
      if (document.hidden) return;
      fetch('../api/get_error_logs.php?limit=1&unread_only=1', { cache:'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (!d.success) return;
          var badge = document.getElementById('saErrorBadge');
          if (badge) {
            if (d.unread_count > 0) { badge.style.display = 'inline-block'; badge.textContent = d.unread_count > 99 ? '99+' : d.unread_count; }
            else { badge.style.display = 'none'; }
          }
          var pageBadge = document.getElementById('saErrorNewBadge');
          if (pageBadge) { pageBadge.style.display = d.unread_count > 0 ? 'inline-flex' : 'none'; pageBadge.textContent = (d.unread_count > 99 ? '99+' : d.unread_count) + ' new'; }
        })
        .catch(function() {});
    }
    
    // Auto-badge polling
    document.addEventListener('DOMContentLoaded', function() {
      setTimeout(saCheckBadge, 2000);
      if (saErrorPollTimer) clearInterval(saErrorPollTimer);
      saErrorPollTimer = setInterval(saCheckBadge, 30000);
      document.addEventListener('visibilitychange', function() {
        if (!document.hidden) saCheckBadge();
      });

      setTimeout(function() {
        var src = document.getElementById('saErrorSource');
        var sev = document.getElementById('saErrorSeverity');
        var search = document.getElementById('saErrorSearch');
        if (src && !src.dataset.saListener) { src.addEventListener('change', saLoadErrorLogs); src.dataset.saListener = '1'; }
        if (sev && !sev.dataset.saListener) { sev.addEventListener('change', saLoadErrorLogs); sev.dataset.saListener = '1'; }
        if (search && !search.dataset.saListener) { var to; search.addEventListener('input', function() { clearTimeout(to); to = setTimeout(saLoadErrorLogs, 500); }); search.dataset.saListener = '1'; }
      }, 200);
    });
    
    // Navigation
    document.querySelectorAll('.menu-item').forEach(item => {
      item.addEventListener('click', function() {
        document.querySelectorAll('.menu-item').forEach(m => m.classList.remove('active'));
        document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        const pageId = this.getAttribute('data-page') + 'Page';
        document.getElementById(pageId)?.classList.add('active');
        localStorage.setItem('saCurrentPage', this.getAttribute('data-page'));
        // Load page-specific data
        if(pageId === 'restaurantsPage') fetchRestaurants();
        else if(pageId === 'subscriptionsPage') loadSubscriptions();
        else if(pageId === 'paymentsPage') loadPaymentHistory();   // plan payments (one loader; loadPayments filled the same table with order payments)
        else if(pageId === 'paymentLinksPage') { loadPaymentLinks(); populateRestaurantFilter(); }
        else if(pageId === 'dashboardPage') { loadStats(); loadRecentActivity(); }
        else if(pageId === 'branchAdminsPage') loadBranchAdmins();
        else if(pageId === 'menusPage') loadMenuRestaurants();
        else if(pageId === 'settlementsPage') { loadSettlements(); updateSettlementModeUI(); }
        else if(pageId === 'errorMonitorPage') { saLoadErrorLogs(); }
      });
    });

    // Global variables
    let saPage = 1, saLimit = 10, saQuery = '', saPages = 1;
    const saRestaurantsMap = {};
    let paymentSearchTerm = '', paymentStatus = '';
    const showSuperAlert = (message, type = 'info') => {
      if (window.Swal) {
        Swal.fire({
          icon: type,
          text: message,
          confirmButtonColor: '#111827'
        });
      } else {
        var t = document.createElement('div'); t.style.cssText = 'position:fixed;top:20px;right:20px;padding:12px 24px;border-radius:8px;color:#fff;font-weight:500;z-index:99999;box-shadow:0 4px 12px rgba(0,0,0,0.2);background:' + (type === 'error' ? '#ef4444' : type === 'warning' ? '#f59e0b' : '#3b82f6'); t.textContent = message; document.body.appendChild(t); setTimeout(function() { t.remove(); }, 3000);
      }
    };
    const showSuperPrompt = async (message, title = 'Input', defaultValue = '') => {
      if (window.Swal) {
        const { value } = await Swal.fire({
          title: title,
          text: message,
          input: 'text',
          inputValue: defaultValue,
          showCancelButton: true,
          confirmButtonColor: '#111827',
          cancelButtonColor: '#6b7280',
          confirmButtonText: 'OK',
          cancelButtonText: 'Cancel',
          inputValidator: (value) => {
            if (!value) {
              return 'Please enter a value';
            }
          }
        });
        return value || null;
      }
      return window.prompt(message, defaultValue);
    };
    // Plain yes/no confirm - unlike showSuperPrompt (a text-input dialog
    // meant for things like Reset Password), this never asks the admin to
    // type anything. The toggle handlers below were previously misusing
    // showSuperPrompt for this, which showed a text box with a mandatory
    // "Please enter a value" validator on a simple on/off confirmation.
    const showSuperConfirm = async (message, title = 'Confirm') => {
      if (window.Swal) {
        const { isConfirmed } = await Swal.fire({
          title: title,
          text: message,
          icon: 'question',
          showCancelButton: true,
          confirmButtonColor: '#111827',
          cancelButtonColor: '#6b7280',
          confirmButtonText: 'OK',
          cancelButtonText: 'Cancel'
        });
        return isConfirmed;
      }
      return window.confirm(message);
    };

    // Dashboard Stats
    async function loadStats(){
      try {
        const res = await fetch('api.php?action=getStats');
        const d = await res.json();
        if(d.success){
          document.getElementById('statRestaurants').textContent = d.stats.restaurants;
          document.getElementById('statActive').textContent = d.stats.active;
          document.getElementById('statRevenue').textContent = '₹' + Number(d.stats.todayRevenue).toLocaleString('en-IN');
          document.getElementById('statOrders').textContent = d.stats.todayOrders;
        }
      } catch(e) { console.error('Error loading stats:', e); }
    }

    // Recent Activity
    async function loadRecentActivity(){
      try {
        const res = await fetch('api.php?action=getRestaurants&limit=5');
        const d = await res.json();
        if(d.success && d.restaurants){
          const html = d.restaurants.map(r => `
            <div class="sa-recent-row"><span class="sa-ini">${saEsc((r.restaurant_name || '?').trim().charAt(0).toUpperCase())}</span>
              <div><b>${saEsc(r.restaurant_name)}</b><small>${saEsc(r.restaurant_id)} · added ${r.created_at?.split(' ')[0] || ''}</small></div>
              ${Number(r.is_active) === 1 ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Disabled</span>'}
              <button class="btn btn-outline btn-sm" onclick="openAdminPanel(${r.id})">Open</button>
            </div>
          `).join('');
          document.getElementById('recentActivity').innerHTML = html || '<div style="padding:20px;text-align:center;color:var(--muted);">No recent activity</div>';
        }
      } catch(e) { console.error('Error loading activity:', e); }
    }

    // Restaurants: rows drawn by saRenderRestaurants() (filter chips work on the loaded page)
    let saLastRows = [], saRestFilter = 'all';
    function saRenderRestaurants() {
      const tbody = document.getElementById('restaurantsTbody');
      const rows = saLastRows.filter(r => {
        const active = Number(r.is_active) === 1;
        if (saRestFilter === 'active') return active;
        if (saRestFilter === 'inactive') return !active;
        if (saRestFilter === 'trial') return r.trial_status === 'Active';
        if (saRestFilter === 'expired') return r.trial_status === 'Expired';
        return true;
      });
      if (!rows.length) { tbody.innerHTML = `<tr><td colspan="6"><div class="empty-state"><div class="material-symbols-rounded icon">storefront</div>${saLastRows.length ? 'No restaurant on this page matches this filter.' : 'No restaurants found.'}</div></td></tr>`; return; }
      const feat = (on, icon, label, call, title) => `<button type="button" class="sa-feat${on ? ' on' : ''}" onclick="${call}" title="${title} · click to switch ${on ? 'off' : 'on'}"><span class="material-symbols-rounded">${icon}</span>${label}</button>`;
      tbody.innerHTML = rows.map(r => {
        const isActive = Number(r.is_active) === 1;
        const pgType = r.payment_gateway_type || 'cash_only';
        const online = pgType !== 'cash_only';
        const showInstall = Number(r.show_install_app) === 1;
        const whatsappOn = Number(r.whatsapp_orders) === 1;
        const galleryOn = Number(r.photo_gallery_enabled) === 1;
        const mealSubsOn = Number(r.meal_subscriptions_enabled) === 1;
        const trial = r.trial_status === 'Active' ? `<span class="badge ${Number(r.days_left) <= 7 ? 'badge-warning' : 'badge-success'}">${r.days_left} days left</span>`
          : r.trial_status === 'Disabled' ? '<span class="badge badge-info">Paid / no trial</span>' : '<span class="badge badge-danger">Expired</span>';
        const ini = (r.restaurant_name || '?').trim().charAt(0).toUpperCase();
        return `
          <tr>
            <td class="sp-ck"><input type="checkbox" class="sp-ck-row" value="${r.id}" aria-label="Select ${saEsc(r.restaurant_name)}"></td>
            <td><div class="sa-rest sp-open360" data-sp360="${r.id}" title="Open everything about this restaurant"><span class="sa-ini">${saEsc(ini)}</span><div><b>${saEsc(r.restaurant_name)}</b><small>${saEsc(r.username)} · <code>${saEsc(r.restaurant_id)}</code></small><small>Added ${r.created_at?.split(' ')[0] || ''}</small></div></div></td>
            <td><div class="sa-feats">
              ${feat(online, 'credit_card', online ? 'Online pay' : 'Cash only', `togglePaymentGateway(${r.id}, '${pgType}')`, 'Payment gateway')}
              ${feat(showInstall, 'install_mobile', 'Install app', `toggleInstallApp(${r.id}, ${showInstall})`, 'Install app button')}
              ${feat(whatsappOn, 'chat', 'WhatsApp orders', `toggleWhatsappOrders(${r.id}, ${whatsappOn})`, 'WhatsApp orders')}
              ${feat(galleryOn, 'photo_library', 'Gallery', `toggleGalleryEnabled(${r.id}, ${galleryOn})`, 'Photo gallery')}
              ${feat(mealSubsOn, 'lunch_dining', 'Meal plans', `toggleMealSubscriptionsEnabled(${r.id}, ${mealSubsOn})`, 'Meal subscriptions')}
              ${feat(!Number(r.access_off), 'tune', Number(r.access_off) ? `Access: ${r.access_off} off` : 'Access: all', `openAccess(${r.id})`, 'Which dashboard sections this restaurant gets')}
            </div></td>
            <td>${trial}</td>
            <td>${isActive ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Disabled</span>'}</td>
            <td><div class="sa-acts">
              <button class="sa-more-btn" onclick="openWebsite('${r.restaurant_id}')" title="Open the restaurant's website" aria-label="Website"><span class="material-symbols-rounded">language</span></button>
              <button class="btn btn-primary btn-sm" onclick="openAdminPanel(${r.id})" title="Open its dashboard"><span class="material-symbols-rounded">dashboard</span>Admin</button>
              <div class="sa-more"><button type="button" class="sa-more-btn" data-sa-more aria-label="More actions"><span class="material-symbols-rounded">more_vert</span></button>
                <div class="sa-more-menu">
                  <button type="button" data-sp360="${r.id}"><span class="material-symbols-rounded">info</span>View everything</button>
                  <button type="button" onclick="location.href='usage.php?rid=' + encodeURIComponent('${r.restaurant_id}')"><span class="material-symbols-rounded">insights</span>What they use</button>
                  ${isActive ? `<button type="button" class="danger" onclick="toggleRestaurant(${r.id}, 0)"><span class="material-symbols-rounded">block</span>Disable restaurant</button>` : `<button type="button" onclick="toggleRestaurant(${r.id}, 1)"><span class="material-symbols-rounded">check_circle</span>Enable restaurant</button>`}
                  <button type="button" onclick="resetPassword(${r.id})"><span class="material-symbols-rounded">key</span>Reset password</button>
                  <button type="button" onclick="unlockOtp(${r.id})" title="Clear OTP lockout and forget trusted devices"><span class="material-symbols-rounded">lock_open</span>Unlock OTP</button>
                  <button type="button" onclick="sendWhatsappWelcome(${r.id})"><span class="material-symbols-rounded">chat</span>Send login on WhatsApp</button>
                </div></div>
            </div></td>
          </tr>`;
      }).join('');
    }
    // a fixed-position More menu would float away on scroll: close it
    window.addEventListener('scroll', () => document.querySelectorAll('.sa-more.open').forEach(x => x.classList.remove('open')), true);
    // filter chips, the More menus, dashboard shortcuts
    document.addEventListener('click', function (e) {
      const f = e.target.closest('[data-sa-rf]');
      if (f) { saRestFilter = f.dataset.saRf; document.querySelectorAll('[data-sa-rf]').forEach(b => b.classList.toggle('on', b === f)); saRenderRestaurants(); return; }
      const m = e.target.closest('[data-sa-more]');
      document.querySelectorAll('.sa-more.open').forEach(x => { if (!m || x !== m.parentElement) x.classList.remove('open'); });
      if (m) { const box = m.parentElement; box.classList.toggle('open'); if (box.classList.contains('open')) { const rc = m.getBoundingClientRect(), mn = box.querySelector('.sa-more-menu'); mn.style.top = Math.min(rc.bottom + 6, innerHeight - mn.offsetHeight - 8) + 'px'; mn.style.left = Math.max(8, rc.right - mn.offsetWidth) + 'px'; } return; }
      if (e.target.closest('.sa-more-menu button')) { e.target.closest('.sa-more').classList.remove('open'); }
      const go = e.target.closest('[data-sa-go]');
      if (go) { const mi = document.querySelector(`.menu-item[data-page="${go.dataset.saGo}"]`); if (mi) mi.click(); return; }
      const q = e.target.closest('[data-sa-quick]');
      if (q) {
        const page = q.dataset.saQuick === 'newRestaurant' ? 'restaurants' : 'paymentLinks';
        const btn = q.dataset.saQuick === 'newRestaurant' ? 'btnNew' : 'btnCreatePaymentLink';
        const mi = document.querySelector(`.menu-item[data-page="${page}"]`); if (mi) mi.click();
        setTimeout(() => { const b = document.getElementById(btn); if (b) b.click(); }, 150);
      }
    });

    // Restaurants
    async function fetchRestaurants(){
      try {
        const qs = `action=getRestaurants&page=${saPage}&limit=${saLimit}${saQuery?`&q=${encodeURIComponent(saQuery)}`:''}`;
        const res = await fetch('api.php?'+qs);
        const data = await res.json();

        if (data.session_expired) {
          showSuperAlert('Your session expired. Please log in again.', 'warning');
          window.location.href = 'login.php';
          return;
        }

        // Self-correct if a rapid double-click on Next (or a stale page number)
        // requested a page beyond what actually exists — refetch the real last
        // page instead of leaving the table empty.
        const requestedPages = Math.max(1, Math.ceil((data.total||0)/saLimit));
        if (saPage > requestedPages) {
          saPage = requestedPages;
          return fetchRestaurants();
        }

        (data.restaurants||[]).forEach(r => { saRestaurantsMap[r.id] = r; });
        const tbody = document.getElementById('restaurantsTbody');
        saLastRows = data.restaurants || [];
        saRenderRestaurants();
        const total = data.total||0;
        const pages = Math.max(1, Math.ceil(total/saLimit));
        saPages = pages;
        document.getElementById('pageInfo').textContent = `Page ${saPage} of ${pages}`;
        document.getElementById('prevPage').disabled = saPage<=1;
        document.getElementById('nextPage').disabled = saPage>=pages;
      } catch(e) {
        console.error('Error:', e);
        // Buttons are born `disabled` in the HTML and only get re-enabled
        // on the success path above — without this, any failed fetch here
        // (network hiccup, non-JSON response, etc.) left them permanently
        // stuck disabled for the rest of the session. Restore them to match
        // the last known-good page/pages instead of leaving Prev/Next dead.
        document.getElementById('prevPage').disabled = saPage<=1;
        document.getElementById('nextPage').disabled = saPage>=saPages;
        showSuperAlert('Could not load restaurants. Please try again.', 'error');
      }
    }


    // Payments
    async function loadPayments(){
      try {
        const params = new URLSearchParams();
        if (paymentSearchTerm) params.append('search', paymentSearchTerm);
        if (paymentStatus) params.append('status', paymentStatus);
        const query = params.toString();
        const res = await fetch(`api.php?action=getPayments${query ? '&'+query : ''}`);
        const d = await res.json();
        if(d.success){
          const tbody = document.getElementById('paymentsTbody');
          tbody.innerHTML = (d.payments || []).map(p => `
            <tr>
              <td>${p.transaction_id || 'N/A'}</td>
              <td>${saEsc(p.restaurant_name) || p.restaurant_id || 'N/A'}</td>
              <td>₹${Number(p.amount).toLocaleString('en-IN')}</td>
              <td>${p.payment_method || 'N/A'}</td>
              <td>${p.payment_status === 'Success' ? '<span class="badge badge-success">Success</span>' : 
                  p.payment_status === 'Failed' ? '<span class="badge badge-danger">Failed</span>' : 
                  '<span class="badge badge-warning">Pending</span>'}</td>
              <td>${p.created_at ? new Date(p.created_at).toLocaleDateString() : 'N/A'}</td>
            </tr>
          `).join('') || '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--muted);">No payments found</td></tr>';
        }
      } catch(e) { 
        document.getElementById('paymentsTbody').innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--muted);">Error loading payments</td></tr>';
        console.error('Error loading payments:', e); 
      }
    }

    // Branch Admin functions
    window.loadBranchAdmins = async function() {
      try {
        const res = await fetch('api.php?action=getBranchAdmins');
        const d = await res.json();
        const tbody = document.getElementById('branchAdminsTbody');
        if (!d.success || !d.admins) {
          tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted);">Error loading</td></tr>';
          return;
        }
        tbody.innerHTML = d.admins.map(a => {
          const links = (a.links || []).map(l => `<span class="badge badge-info" style="margin:2px;font-size:0.7rem">${saEsc(l.label || l.restaurant_name || l.restaurant_id)}</span>`).join('') || '<span style="color:var(--muted)">None</span>';
          return `<tr>
            <td>${a.id}</td>
            <td>${saEsc(a.username)}</td>
            <td>${a.display_name || '-'}</td>
            <td>${links}</td>
            <td>${a.is_active ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Inactive</span>'}</td>
            <td>${a.created_at ? a.created_at.split(' ')[0] : ''}</td>
            <td style="display:flex;gap:8px;flex-wrap:wrap;">
              <button class="btn btn-outline" onclick="toggleBranchAdmin(${a.id}, ${a.is_active ? 0 : 1})">${a.is_active ? 'Disable' : 'Enable'}</button>
              <button class="btn btn-outline" onclick="editBranchLinks(${a.id})">Edit Links</button>
            </td>
          </tr>`;
        }).join('');
      } catch(e) { console.error('Error loading branch admins:', e); }
    };

    window.toggleBranchAdmin = async function(id, active) {
      const res = await fetch('api.php?action=toggleBranchAdmin', { method:'POST', body: JSON.stringify({id, is_active: active}), headers: {'Content-Type': 'application/json'} });
      const d = await res.json();
      if (d.success) { loadBranchAdmins(); showSuperAlert(d.message || 'Updated', 'success'); }
      else showSuperAlert(d.message || 'Error', 'error');
    };

    window.editBranchLinks = async function(id) {
      const res = await fetch('api.php?action=getLinkedRestaurants&id=' + id);
      const d = await res.json();
      if (!d.success) { showSuperAlert(d.message || 'Error', 'error'); return; }
      
      const links = d.links || [];
      const allRes = await fetch('api.php?action=getRestaurants&limit=200');
      const allData = await allRes.json();
      const restaurants = allData.restaurants || [];

      const html = restaurants.map(r => {
        const checked = links.some(l => l.restaurant_id === r.restaurant_id);
        return `<label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-bottom:1px solid var(--border);cursor:pointer;font-size:0.9rem;">
          <input type="checkbox" class="link-restaurant" value="${r.restaurant_id}" ${checked ? 'checked' : ''}>
          ${saEsc(r.restaurant_name)} (${r.restaurant_id})
        </label>`;
      }).join('');

      const container = document.createElement('div');
      container.innerHTML = `<div style="max-height:300px;overflow-y:auto;margin-bottom:16px;">${html}</div>`;

      if (window.Swal) {
        const { value: ok } = await Swal.fire({
          title: 'Edit Linked Restaurants',
          html: container,
          showCancelButton: true,
          confirmButtonText: 'Save',
          cancelButtonText: 'Cancel',
          confirmButtonColor: '#111827',
          preConfirm: () => {
            return Array.from(container.querySelectorAll('.link-restaurant:checked')).map(cb => cb.value);
          }
        });
        if (ok) {
          const saveRes = await fetch('api.php?action=updateBranchLinks', { method:'POST', body: JSON.stringify({id, restaurant_ids: ok}), headers: {'Content-Type': 'application/json'} });
          const saveData = await saveRes.json();
          if (saveData.success) { loadBranchAdmins(); showSuperAlert('Links updated', 'success'); }
          else showSuperAlert(saveData.message || 'Error', 'error');
        }
      }
    };

    // Create Branch Admin Modal
    const cbamModal = document.getElementById('createBranchAdminModal');
    document.getElementById('btnNewBranchAdmin')?.addEventListener('click', async () => {
      const container = document.getElementById('cbamRestaurants');
      container.innerHTML = '<div style="color:var(--muted);font-size:0.85rem;">Loading...</div>';
      cbamModal.classList.add('active');
      
      const allRes = await fetch('api.php?action=getRestaurants&limit=200');
      const allData = await allRes.json();
      const restaurants = allData.restaurants || [];
      container.innerHTML = restaurants.map(r => 
        `<label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-bottom:1px solid var(--border);cursor:pointer;font-size:0.9rem;">
          <input type="checkbox" class="new-link-rest" value="${r.restaurant_id}">
          ${saEsc(r.restaurant_name)} (${r.restaurant_id})
        </label>`
      ).join('') || '<div style="color:var(--muted)">No restaurants found</div>';
    });
    document.getElementById('cbamClose')?.addEventListener('click', () => cbamModal.classList.remove('active'));
    document.getElementById('cbamCreate')?.addEventListener('click', async () => {
      const username = document.getElementById('cbamUser').value.trim();
      const displayName = document.getElementById('cbamName').value.trim();
      const password = document.getElementById('cbamPass').value;
      const restaurant_ids = Array.from(document.querySelectorAll('.new-link-rest:checked')).map(cb => cb.value);
      
      if (!username || !password) { showSuperAlert('Username and password are required'); return; }
      if (restaurant_ids.length === 0) { showSuperAlert('Select at least one restaurant'); return; }

      const res = await fetch('api.php?action=createBranchAdmin', { method:'POST', body: JSON.stringify({username, password, display_name: displayName, restaurant_ids}), headers: {'Content-Type': 'application/json'} });
      const d = await res.json();
      if (d.success) {
        cbamModal.classList.remove('active');
        document.getElementById('cbamUser').value = '';
        document.getElementById('cbamName').value = '';
        document.getElementById('cbamPass').value = '';
        loadBranchAdmins();
        showSuperAlert('Branch admin created', 'success');
      } else showSuperAlert(d.message || 'Error', 'error');
    });

    // Create Restaurant Modal
    const createModal = document.getElementById('createModal');
    document.getElementById('btnNew').addEventListener('click', () => createModal.classList.add('active'));
    document.getElementById('cmClose').addEventListener('click', () => createModal.classList.remove('active'));
    document.getElementById('cmCreate').addEventListener('click', async () => {
      const payload = {
        username: document.getElementById('cmUser').value.trim(),
        password: document.getElementById('cmPass').value,
        restaurant_name: document.getElementById('cmName').value.trim(),
        email: document.getElementById('cmEmail').value.trim(),
        country: document.getElementById('cmCountry').value,
      };
      if(!payload.username || !payload.password || !payload.restaurant_name){ showSuperAlert('All fields are required'); return; }
      const res = await fetch('api.php?action=createRestaurant', { method:'POST', body: JSON.stringify(payload), headers: {'Content-Type': 'application/json'} });
      const data = await res.json();
      if (data.success) { 
        createModal.classList.remove('active');
        document.getElementById('cmUser').value = '';
        document.getElementById('cmPass').value = '';
        document.getElementById('cmName').value = '';
        document.getElementById('cmEmail').value = '';
        fetchRestaurants();
        showSuperAlert('Created. Restaurant ID: '+data.restaurant_id); 
      } else showSuperAlert(data.message||'Error');
    });

    // Restaurant actions
    window.toggleRestaurant = async function(id, active){
      const res = await fetch('api.php?action=toggleRestaurant', { method:'POST', body: JSON.stringify({id, is_active: active}), headers: {'Content-Type': 'application/json'} });
      const data = await res.json();
      if (data.success) { fetchRestaurants(); if(document.getElementById('subscriptionsPage').classList.contains('active')) loadSubscriptions(); }
      else showSuperAlert(data.message||'Error');
    }

    window.togglePaymentGateway = async function(id, currentType){
      const newType = currentType === 'cash_only' ? 'cash_and_gateway' : 'cash_only';
      const label = newType === 'cash_only' ? 'Cash' : 'Payment Gateway';
      const ok = await showSuperConfirm(`Switch to "${label}" mode for this restaurant?`, 'Confirm');
      if(!ok) return;
      const res = await fetch('api.php?action=updatePaymentGateway', { method:'POST', body: JSON.stringify({id, type: newType}), headers: {'Content-Type': 'application/json'} });
      const data = await res.json();
      if (data.success) { fetchRestaurants(); showSuperAlert('Switched to '+label, 'success'); }
      else showSuperAlert(data.message||'Error', 'error');
    }

    window.toggleInstallApp = async function(id, current){
      const newVal = current ? 0 : 1;
      const label = newVal ? 'Show' : 'Hide';
      const ok = await showSuperConfirm(`Set Install App to "${label}" for this restaurant?`, 'Confirm');
      if(!ok) return;
      const res = await fetch('api.php?action=updateInstallApp', { method:'POST', body: JSON.stringify({id, show_install_app: newVal}), headers: {'Content-Type': 'application/json'} });
      const data = await res.json();
      if (data.success) { fetchRestaurants(); showSuperAlert('Install App set to '+label, 'success'); }
      else showSuperAlert(data.message||'Error', 'error');
    }

    window.toggleWhatsappOrders = async function(id, current){
      const newVal = current ? 0 : 1;
      const label = newVal ? 'On' : 'Off';
      const ok = await showSuperConfirm(`Set WhatsApp Orders to "${label}" for this restaurant?`, 'Confirm');
      if(!ok) return;
      const res = await fetch('api.php?action=updateWhatsappOrders', { method:'POST', body: JSON.stringify({id, whatsapp_orders: newVal}), headers: {'Content-Type': 'application/json'} });
      const data = await res.json();
      if (data.success) { fetchRestaurants(); showSuperAlert('WhatsApp Orders set to '+label, 'success'); }
      else showSuperAlert(data.message||'Error', 'error');
    }

    window.toggleGalleryEnabled = async function(id, current){
      const newVal = current ? 0 : 1;
      const label = newVal ? 'Allowed' : 'Blocked';
      const ok = await showSuperConfirm(`Set Photo Gallery to "${label}" for this restaurant?`, 'Confirm');
      if(!ok) return;
      const res = await fetch('api.php?action=updatePhotoGalleryEnabled', { method:'POST', body: JSON.stringify({id, photo_gallery_enabled: newVal}), headers: {'Content-Type': 'application/json'} });
      const data = await res.json();
      if (data.success) { fetchRestaurants(); showSuperAlert('Photo Gallery set to '+label, 'success'); }
      else showSuperAlert(data.message||'Error', 'error');
    }

    // Restaurants › Access: whole sections (switch) or single pages (chips); saves the list of what's OFF
    let acId = 0, acData = null;
    const acModal = document.getElementById('accessModal');
    function acOff() { return [...acModal.querySelectorAll('.ac-pages input')].filter(x => !x.checked).map(x => x.value); }
    function acSync() {
      acModal.querySelectorAll('.ac-mod').forEach(m => {
        const boxes = [...m.querySelectorAll('.ac-pages input')], on = boxes.filter(b => b.checked).length;
        const sw = m.querySelector('.ac-sw input');
        sw.checked = on > 0; sw.indeterminate = on > 0 && on < boxes.length;
        m.classList.toggle('off', on === 0);
        boxes.forEach(b => b.closest('label').classList.toggle('off', !b.checked));
      });
      const all = acModal.querySelectorAll('.ac-pages input').length, off = acOff().length;
      document.getElementById('acCount').textContent = off ? `${all - off} of ${all} pages on · ${off} off` : `Everything on (${all} pages)`;
    }
    function acRender(off) {
      document.getElementById('acPresets').innerHTML = acData.presets.map(p => `<button type="button" data-ac-preset="${p.key}">${saEsc(p.label)}</button>`).join('');
      document.getElementById('acGrid').innerHTML = acData.modules.map(m => `
        <div class="ac-mod" data-ac-mod="${m.key}">
          <div class="ac-mh"><span class="material-symbols-rounded">${m.icon}</span>
            <div class="ac-mt">${saEsc(m.label)}<small>${saEsc(m.desc)}</small></div>
            <span class="ac-sw"><input type="checkbox" aria-label="${saEsc(m.label)} on or off"><i></i></span></div>
          <div class="ac-pages"${m.pages.length > 1 ? '' : ' hidden'}>${m.pages.map(p => `<label><input type="checkbox" value="${p.id}"${off.includes(p.id) ? '' : ' checked'}>${saEsc(p.label)}</label>`).join('')}</div>
        </div>`).join('');
      acSync();
    }
    window.openAccess = async function (id) {
      acId = id; acData = null;
      document.getElementById('acName').textContent = '';
      document.getElementById('acGrid').innerHTML = '<div class="ac-sub">Loading…</div>';
      acModal.classList.add('active');
      try {
        const d = await (await fetch('api.php?action=getAccess&id=' + id)).json();
        if (!d.success) throw new Error(d.message || 'Could not load');
        acData = d;
        document.getElementById('acName').textContent = '· ' + d.restaurant;
        acRender(d.off || []);
      } catch (e) { document.getElementById('acGrid').innerHTML = `<div class="ac-sub">${saEsc(e.message)}</div>`; }
    };
    acModal.addEventListener('change', e => {
      const sw = e.target.closest('.ac-sw input');
      if (sw) sw.closest('.ac-mod').querySelectorAll('.ac-pages input').forEach(b => { b.checked = sw.checked; });
      acSync();
    });
    acModal.addEventListener('click', e => {
      const p = e.target.closest('[data-ac-preset]');
      if (p && acData) {
        const keep = acData.presets.find(x => x.key === p.dataset.acPreset).modules;
        acModal.querySelectorAll('.ac-mod').forEach(m => m.querySelectorAll('.ac-pages input').forEach(b => { b.checked = !keep || keep.includes(m.dataset.acMod); }));
        acSync();
      }
      if (e.target === acModal) acModal.classList.remove('active');
    });
    document.getElementById('acClose').onclick = () => acModal.classList.remove('active');
    document.getElementById('acSave').onclick = async function () {
      if (!acData) return;
      this.disabled = true;
      try {
        const d = await (await fetch('api.php?action=saveAccess', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: acId, off: acOff() }) })).json();
        if (!d.success) throw new Error(d.message || 'Could not save');
        acModal.classList.remove('active');
        showSuperAlert(d.message + '. They see it on their next page load.', 'success');
        fetchRestaurants();
      } catch (e) { showSuperAlert(e.message, 'error'); }
      finally { this.disabled = false; }
    };

    window.toggleMealSubscriptionsEnabled = async function(id, current){
      const newVal = current ? 0 : 1;
      const label = newVal ? 'Allowed' : 'Blocked';
      const ok = await showSuperConfirm(`Set Meal Subscriptions to "${label}" for this restaurant?`, 'Confirm');
      if(!ok) return;
      const res = await fetch('api.php?action=updateMealSubscriptionsEnabled', { method:'POST', body: JSON.stringify({id, meal_subscriptions_enabled: newVal}), headers: {'Content-Type': 'application/json'} });
      const data = await res.json();
      if (data.success) { fetchRestaurants(); showSuperAlert('Meal Subscriptions set to '+label, 'success'); }
      else showSuperAlert(data.message||'Error', 'error');
    }

    window.resetPassword = async function(id){
      const p = await showSuperPrompt('New password for user id '+id+':', 'Reset Password');
      if(!p) return;
      const res = await fetch('api.php?action=resetPassword', { method:'POST', body: JSON.stringify({id, password: p}), headers: {'Content-Type': 'application/json'} });
      const data = await res.json();
      if (data.success) {
        // The prompt dialog is easy to lose track of once it closes — show
        // the password back so it isn't only ever visible for a moment in
        // an input box, which was locking restaurant owners out with no
        // record of what was set.
        showSuperAlert('Password reset successfully. New password: ' + p, 'success');
      } else {
        showSuperAlert(data.message||'Error','error');
      }
    }

    window.unlockOtp = async function(id){
      const ok = await showSuperConfirm('Clear OTP lockouts and forget trusted devices for this account? Use this while testing the WhatsApp OTP login/reset flow.', 'Unlock OTP');
      if (!ok) return;
      try {
        const res = await fetch('api.php?action=unlockOtp', { method:'POST', body: JSON.stringify({id}), headers: {'Content-Type': 'application/json'} });
        const data = await res.json();
        if (data.success) {
          showSuperAlert(data.message || 'OTP lockouts cleared.', 'success');
        } else {
          showSuperAlert(data.message || 'Error', 'error');
        }
      } catch(e) {
        showSuperAlert('Error: ' + e.message, 'error');
      }
    }

    window.openAdminPanel = async function(id){
      try {
        const res = await fetch('api.php?action=generateAutoLogin', { method:'POST', body: JSON.stringify({id}), headers: {'Content-Type': 'application/json'} });
        const data = await res.json();
        if (data.success && data.url) {
          window.open(data.url, '_blank');
        } else {
          showSuperAlert(data.message || 'Failed to generate login link', 'error');
        }
      } catch(e) {
        showSuperAlert('Error: ' + e.message, 'error');
      }
    }

    window.openWebsite = function(restaurantId){
      const baseUrl = window.location.protocol + '//' + window.location.host + window.location.pathname.replace(/\/main\/superadmin\/dashboard\.php.*$/, '');
      window.open(baseUrl + '/main/website/index.php?restaurant_id=' + encodeURIComponent(restaurantId), '_blank');
    }

    window.sendWhatsappWelcome = async function(id){
      const r = saRestaurantsMap[id];
      if (!r) { showSuperAlert('Restaurant data not loaded yet', 'error'); return; }
      if (!r.phone) { showSuperAlert('No phone number on file for this restaurant', 'error'); return; }

      const password = await showSuperPrompt('Enter the password for "' + r.username + '" to include in the WhatsApp message:', 'Send WhatsApp Welcome');
      if (!password) return;

      const baseUrl = window.location.protocol + '//' + window.location.host + window.location.pathname.replace(/\/main\/superadmin\/dashboard\.php.*$/, '');
      const loginUrl = baseUrl + '/main/admin/login.php';
      const dialDigits = (r.dial_code || '+91').replace(/\D/g, '');
      const phoneDigits = String(r.phone).replace(/\D/g, '');
      // Phone numbers on file are inconsistently stored - some already include
      // the country code (e.g. "+91 98765..."), some don't. Prepending the
      // dial code unconditionally doubles it up for the ones that already
      // have it. A plain startsWith isn't enough either - a bare 10-digit
      // Indian mobile number can coincidentally start with "91" - so also
      // require enough digits left over for a real local number.
      const alreadyHasCode = phoneDigits.startsWith(dialDigits) && (phoneDigits.length - dialDigits.length) >= 8;
      const fullPhone = alreadyHasCode ? phoneDigits : dialDigits + phoneDigits;

      const message = `Hey ${r.restaurant_name}! 🎉\n\n`
        + `Great news - your restaurant website is built and live! 🚀\n\n`
        + `Here's how to log in:\n`
        + `🔗 ${loginUrl}\n`
        + `👤 Username: ${r.username}\n`
        + `🔑 Password: ${password}\n\n`
        + `Hop in and check out your dashboard - manage your menu, track orders, and watch your restaurant grow, all from one place.\n\n`
        + `Want to change anything or have a question? We're just a message away:\n`
        + `📧 restrogrow@gmail.com\n\n`
        + `Thanks for choosing us - excited to see your restaurant thrive! 🍽️✨`;

      window.open('https://wa.me/' + fullPhone + '?text=' + encodeURIComponent(message), '_blank');
    }

    // Search and pagination
    document.getElementById('saSearch')?.addEventListener('input', (e)=>{ saQuery = e.target.value.trim(); saPage = 1; fetchRestaurants(); });
    document.getElementById('prevPage')?.addEventListener('click', ()=>{ if(saPage>1){ saPage--; fetchRestaurants(); }});
        document.getElementById('nextPage')?.addEventListener('click', ()=>{ if(saPage<saPages){ saPage++; fetchRestaurants(); }});
    // Export CSV: every restaurant that matches the search (all pages), from the API rather than the table on screen
    document.getElementById('btnExport')?.addEventListener('click', async ()=>{
      const cell = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
      const rows = []; let page = 1, total = 0;
      do {
        const r = await fetch(`api.php?action=getRestaurants&page=${page}&limit=500${saQuery?`&q=${encodeURIComponent(saQuery)}`:''}`).then(x=>x.json()).catch(()=>null);
        if (!r || !r.success) { alert('Could not load the restaurants for the export.'); return; }
        total = r.total; r.restaurants.forEach(x => rows.push(x)); page++;
      } while (rows.length < total);
      const header = ['Restaurant ID','Name','Username','Country','Phone','Plan','Plan status','Days left','Online pay','Active','Created'];
      const lines = rows.map(x => [x.restaurant_id, x.restaurant_name, x.username, x.country, (x.dial_code || '') + ' ' + (x.phone || ''), x.subscription_status, x.trial_status, x.days_left, x.payment_gateway_type === 'cash_and_gateway' ? 'yes' : 'no', x.is_active == 1 ? 'yes' : 'no', x.created_at].map(cell).join(','));
      const blob = new Blob(['﻿' + [header.map(cell).join(','), ...lines].join('\n')], {type:'text/csv;charset=utf-8;'});
      const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'restaurants.csv'; a.click();
    });

    // Password Reset Data
    let resetPage = 1, resetLimit = 10;
    async function loadPasswordResetData(){
      const tbody = document.getElementById('passwordResetTbody');
      if (!tbody) return;
      
      try {
        const res = await fetch('api.php?action=getPasswordResetData&page='+resetPage+'&limit='+resetLimit);
        
        if (!res.ok) {
          throw new Error('HTTP error: ' + res.status);
        }
        
        const text = await res.text();
        let data;
        try {
          data = JSON.parse(text);
        } catch(e) {
          console.error('JSON parse error:', text);
          tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--muted);">Invalid response from server. Check console for details.</td></tr>';
          return;
        }
        
        if(data.success && data.tokens){
          if (data.tokens.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--muted);">No password reset tokens found</td></tr>';
          } else {
            tbody.innerHTML = data.tokens.map(t => {
              const isUsed = Number(t.used) === 1;
              const isExpired = new Date(t.expires_at) < new Date();
              const status = isUsed ? 'Used' : (isExpired ? 'Expired' : 'Active');
              const statusClass = isUsed ? 'badge-warning' : (isExpired ? 'badge-danger' : 'badge-success');
              return `
                <tr>
                  <td>${t.id}</td>
                  <td>${saEsc(t.username) || 'N/A'}</td>
                  <td>${saEsc(t.restaurant_name) || 'N/A'}</td>
                  <td>${t.email || 'N/A'}</td>
                  <td style="font-family:monospace;font-size:0.8rem;">${(t.token || '').substring(0,16)}...</td>
                  <td><span class="badge ${statusClass}">${status}</span></td>
                  <td>${t.created_at ? new Date(t.created_at).toLocaleString() : 'N/A'}</td>
                  <td>${t.expires_at ? new Date(t.expires_at).toLocaleString() : 'N/A'}</td>
                </tr>
              `;
            }).join('');
          }
          const total = data.total || 0;
          const pages = Math.max(1, Math.ceil(total/resetLimit));
          const pageInfo = document.getElementById('resetPageInfo');
          if (pageInfo) {
            pageInfo.textContent = `Page ${resetPage} of ${pages}`;
          }
          const prevBtn = document.getElementById('prevResetPage');
          const nextBtn = document.getElementById('nextResetPage');
          if (prevBtn) prevBtn.disabled = resetPage <= 1;
          if (nextBtn) nextBtn.disabled = resetPage >= pages;
        } else {
          const message = data.message || 'Error loading data';
          tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--muted);">' + message + '</td></tr>';
        }
      } catch(e) {
        console.error('Error loading password reset data:', e);
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--muted);">Error: ' + e.message + '</td></tr>';
      }
    }
    document.getElementById('prevResetPage')?.addEventListener('click', ()=>{ if(resetPage>1){ resetPage--; loadPasswordResetData(); }});
    document.getElementById('nextResetPage')?.addEventListener('click', ()=>{ resetPage++; loadPasswordResetData(); });

    // ==================== PAYMENT LINKS ====================
    let plRestaurantFilter = '', plStatusFilter = '';

    async function loadPaymentLinks() {
      const tbody = document.getElementById('paymentLinksTbody');
      if (!tbody) return;
      try {
        const params = new URLSearchParams({ action: 'getPaymentLinks', limit: 100 });
        if (plRestaurantFilter) params.append('restaurant_id', plRestaurantFilter);
        if (plStatusFilter) params.append('status', plStatusFilter);
        const res = await fetch('api.php?' + params.toString());
        const d = await res.json();
        if (d.success) {
          tbody.innerHTML = (d.links || []).map(pl => {
            const isPending = pl.payment_status === 'Pending';
            const isSuccess = pl.payment_status === 'Success';
            const isFailed = pl.payment_status === 'Failed';
            const statusClass = isSuccess ? 'badge-success' : (isFailed ? 'badge-danger' : 'badge-warning');
            return `<tr>
              <td>${pl.id}</td>
              <td><strong>${saEsc(pl.restaurant_name) || pl.restaurant_id}</strong></td>
              <td>₹${Number(pl.amount).toLocaleString('en-IN')}</td>
              <td style="font-family:monospace;font-size:0.8rem;">${pl.transaction_id || '-'}</td>
              <td><span class="badge ${statusClass}">${pl.payment_status}</span></td>
              <td>
                ${pl.payment_url ? `<a href="${pl.payment_url}" target="_blank" class="btn btn-outline" style="padding:4px 10px;font-size:0.8rem;">Open Link</a>
                <button class="btn btn-outline" style="padding:4px 10px;font-size:0.8rem;" onclick="copyToClipboard('${pl.payment_url.replace(/'/g, "\\'")}')">Copy</button>` : '-'}
              </td>
              <td style="font-size:0.85rem;color:var(--muted);">${pl.notes || '-'}</td>
              <td style="font-size:0.85rem;">${pl.created_at ? new Date(pl.created_at).toLocaleDateString() : '-'}</td>
              <td>
                ${isPending ? `<button class="btn btn-outline" style="padding:4px 10px;font-size:0.8rem;" onclick="refreshPaymentLinkStatus(${pl.id})">Refresh</button>` : ''}
                ${isPending ? `<button class="btn btn-outline" style="padding:4px 10px;font-size:0.8rem;" onclick="markPaymentLink(${pl.id},'Success')">Mark Paid</button>` : ''}
                ${isPending ? `<button class="btn btn-outline" style="padding:4px 10px;font-size:0.8rem;" onclick="markPaymentLink(${pl.id},'Failed')">Mark Failed</button>` : ''}
              </td>
            </tr>`;
          }).join('') || '<tr><td colspan="9" style="text-align:center;padding:20px;color:var(--muted);">No payment links found</td></tr>';
        }
      } catch(e) {
        console.error('Error loading payment links:', e);
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:20px;color:var(--muted);">Error loading</td></tr>';
      }
    }

    async function populateRestaurantFilter() {
      try {
        const res = await fetch('api.php?action=getRestaurants&limit=200');
        const d = await res.json();
        if (d.success) {
          const select = document.getElementById('plRestaurantFilter');
          if (!select) return;
          const currentVal = select.value;
          select.innerHTML = '<option value="">All Restaurants</option>' +
            (d.restaurants || []).map(r => `<option value="${r.restaurant_id}">${saEsc(r.restaurant_name)} (${r.restaurant_id})</option>`).join('');
          if (currentVal) select.value = currentVal;

          // Also populate create modal dropdown
          const cplmSelect = document.getElementById('cplmRestaurant');
          if (cplmSelect) {
            cplmSelect.innerHTML = '<option value="">Select restaurant...</option>' +
              (d.restaurants || []).map(r => `<option value="${r.restaurant_id}">${saEsc(r.restaurant_name)} (${r.restaurant_id})</option>`).join('');
          }
        }
      } catch(e) {}
    }

    document.getElementById('plRestaurantFilter')?.addEventListener('change', (e) => {
      plRestaurantFilter = e.target.value;
      loadPaymentLinks();
    });
    document.getElementById('plStatusFilter')?.addEventListener('change', (e) => {
      plStatusFilter = e.target.value;
      loadPaymentLinks();
    });

    // PhonePe Mode Toggle
    async function updatePhonePeModeUI(mode) {
      const label = document.getElementById('phonepeModeLabel');
      const btn = document.getElementById('btnTogglePhonePeMode');
      if (!label) return;
      const modes = { demo: ['Demo', '#f59e0b', 'No real payment'], sandbox: ['Sandbox', '#3b82f6', 'PhonePe test API'], production: ['Production', '#10b981', 'Real payments'] };
      const [text, color, tip] = modes[mode] || ['Unknown', '#6b7280', ''];
      label.textContent = text;
      label.style.background = color + '20';
      label.style.color = color;
      label.title = tip;
      btn.textContent = mode === 'production' ? 'Switch to Test' : 'Switch to Live';
    }

    async function loadPhonePeMode() {
      try {
        const res = await fetch('api.php?action=getPhonePeMode');
        const d = await res.json();
        if (d.success) updatePhonePeModeUI(d.mode);
      } catch(e) {}
    }

    document.getElementById('btnTogglePhonePeMode')?.addEventListener('click', async () => {
      const label = document.getElementById('phonepeModeLabel');
      const current = label?.textContent?.toLowerCase() || 'demo';
      const next = current === 'demo' ? 'sandbox' : (current === 'sandbox' ? 'production' : 'demo');
      try {
        const f = new URLSearchParams(); f.append('mode', next);
        const res = await fetch('api.php?action=setPhonePeMode', { method: 'POST', body: f, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
        const d = await res.json();
        if (d.success) updatePhonePeModeUI(d.mode);
      } catch(e) {}
    });

    // Create Payment Link Modal
    const cplmModal = document.getElementById('createPaymentLinkModal');
    document.getElementById('btnCreatePaymentLink')?.addEventListener('click', (e) => {
      e.preventDefault();
      populateRestaurantFilter();
      cplmModal.classList.add('active');
    });
    document.getElementById('cplmClose')?.addEventListener('click', () => cplmModal.classList.remove('active'));
    document.getElementById('cplmCreate')?.addEventListener('click', async (e) => {
      e.preventDefault();
      const restaurant_id = document.getElementById('cplmRestaurant').value;
      const amount = parseFloat(document.getElementById('cplmAmount').value);
      const notes = document.getElementById('cplmNotes').value.trim();

      if (!restaurant_id) { showSuperAlert('Please select a restaurant', 'warning'); return; }
      if (!amount || amount <= 0) { showSuperAlert('Please enter a valid amount', 'warning'); return; }

      const btn = document.getElementById('cplmCreate');
      btn.disabled = true;
      btn.textContent = 'Creating...';

      try {
        const formData = new URLSearchParams();
        formData.append('restaurant_id', restaurant_id);
        formData.append('amount', amount);
        formData.append('notes', notes);
        const res = await fetch('api.php?action=createPaymentLink', {
          method: 'POST',
          body: formData,
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        });
        const d = await res.json();

        if (d.success) {
          cplmModal.classList.remove('active');
          document.getElementById('cplmAmount').value = '';
          document.getElementById('cplmNotes').value = '';
          loadPaymentLinks();

          if (d.demo_mode) {
            showSuperAlert('Demo link created! Copy and open in browser to simulate payment.', 'info');
          } else if (d.payment_url) {
            if (window.Swal) {
              Swal.fire({
                icon: 'success',
                title: 'Payment Link Created!',
                html: `<div style="word-break:break-all;margin:10px 0;">
                  <p style="margin-bottom:12px;">Amount: <strong>₹${Number(amount).toLocaleString('en-IN')}</strong></p>
                  <a href="${d.payment_url}" target="_blank" class="btn btn-primary" style="display:inline-block;text-decoration:none;margin-bottom:10px;">Open Payment Page</a>
                  <br>
                  <button class="btn btn-outline" onclick="copyToClipboard('${d.payment_url.replace(/'/g, "\\'")}');Swal.close();" style="margin-top:4px;">Copy Link</button>
                </div>`,
                confirmButtonColor: '#111827',
                confirmButtonText: 'Done'
              });
            } else {
              window.open(d.payment_url, '_blank');
              showSuperAlert('Payment link created!', 'success');
            }
          }
        } else {
          showSuperAlert(d.message || 'Failed to create payment link', 'error');
        }
      } catch(e) {
        showSuperAlert('Error: ' + e.message, 'error');
      } finally {
        btn.disabled = false;
        btn.textContent = 'Generate Payment Link';
      }
    });

    // Copy to clipboard utility
    window.copyToClipboard = function(text) {
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
          showSuperAlert('Link copied to clipboard!', 'success');
        }).catch(() => {
          fallbackCopy(text);
        });
      } else {
        fallbackCopy(text);
      }
    };

    function fallbackCopy(text) {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
      showSuperAlert('Link copied to clipboard!', 'success');
    }

    // Refresh payment link status from PhonePe API
    window.refreshPaymentLinkStatus = async function(id, code) {
      try {
        const url = 'api.php?action=refreshPaymentLinkStatus&id=' + id + (code ? '&code=' + encodeURIComponent(code) : '');
        const res = await fetch(url);
        const d = await res.json();
        if (d.success) {
          showSuperAlert(d.message || 'Status: ' + d.payment_status, d.payment_status === 'Success' ? 'success' : 'info');
          loadPaymentLinks();
        } else {
          showSuperAlert(d.message || 'Error', 'error');
        }
      } catch(e) {
        showSuperAlert('Error: ' + e.message, 'error');
      }
    };

    // Manually mark payment link as paid/failed
    window.markPaymentLink = async function(id, status) {
      const label = status === 'Success' ? 'paid' : 'failed';
      const ok = await showSuperConfirm(`Mark this payment link as "${label}"?`, 'Confirm');
      if (!ok) return;

      try {
        const updateRes = await fetch('api.php?action=updatePaymentLinkStatus', {
          method: 'POST',
          body: JSON.stringify({ id, status }),
          headers: { 'Content-Type': 'application/json' }
        });
        const d = await updateRes.json();
        if (d.success) {
          showSuperAlert('Marked as ' + label, 'success');
          loadPaymentLinks();
        } else {
          showSuperAlert(d.message || 'Error', 'error');
        }
      } catch(e) {
        showSuperAlert('Error: ' + e.message, 'error');
      }
    };

    // ==================== SETTLEMENTS ====================
    async function loadSettlements() {
      const tbody = document.getElementById('settlementsTbody');
      if (!tbody) return;
      try {
        const res = await fetch('api.php?action=getSettlements&limit=50');
        const d = await res.json();
        if (d.success) {
          tbody.innerHTML = (d.settlements || []).map(s => {
            const statusClass = s.status === 'Success' ? 'badge-success' : (s.status === 'Failed' ? 'badge-danger' : 'badge-warning');
            return `<tr>
              <td>${s.id}</td>
              <td><span style="font-family:monospace;font-size:0.85rem;">${s.upi_id}</span></td>
              <td>₹${Number(s.amount).toLocaleString('en-IN')}</td>
              <td style="font-family:monospace;font-size:0.8rem;">${s.transaction_id || '-'}</td>
              <td><span class="badge ${statusClass}">${s.status}</span></td>
              <td style="font-size:0.85rem;">${s.created_by_username || 'Superadmin'}</td>
              <td style="font-size:0.85rem;">${s.created_at ? new Date(s.created_at).toLocaleDateString() : '-'}</td>
            </tr>`;
          }).join('') || '<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted);">No settlements found</td></tr>';
        }
      } catch(e) {
        console.error('Error loading settlements:', e);
        if (tbody) tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted);">Error loading settlements</td></tr>';
      }
    }

    async function updateSettlementModeUI() {
      try {
        const res = await fetch('api.php?action=getPhonePeMode');
        const d = await res.json();
        if (d.success) {
          const label = document.getElementById('settlementModeLabel');
          if (!label) return;
          const modes = { demo: ['Demo', '#f59e0b'], sandbox: ['Sandbox', '#3b82f6'], production: ['Production', '#10b981'] };
          const [text, color] = modes[d.mode] || ['Unknown', '#6b7280'];
          label.textContent = 'Mode: ' + text;
          label.style.background = color + '20';
          label.style.color = color;
        }
      } catch(e) {}
    }

    document.getElementById('btnSettle')?.addEventListener('click', async () => {
      const upi = document.getElementById('stlUpi').value.trim();
      const amount = parseFloat(document.getElementById('stlAmount').value);
      const notes = document.getElementById('stlNotes').value.trim();

      if (!upi) { showSuperAlert('Please enter a UPI ID', 'warning'); return; }
      if (!amount || amount <= 0) { showSuperAlert('Please enter a valid amount', 'warning'); return; }
      if (!/^[\w\.\-]+@[\w\.\-]+$/.test(upi)) { showSuperAlert('Invalid UPI ID format (e.g. name@bank)', 'warning'); return; }

      const confirmMsg = `Send ₹${amount.toLocaleString('en-IN')} to ${upi}?`;
      if (!confirm(confirmMsg)) return;

      const btn = document.getElementById('btnSettle');
      btn.disabled = true;
      btn.textContent = 'Processing...';

      try {
        const res = await fetch('api.php?action=createSettlement', {
          method: 'POST',
          body: JSON.stringify({ upi_id: upi, amount, notes }),
          headers: { 'Content-Type': 'application/json' }
        });
        const d = await res.json();
        if (d.success) {
          showSuperAlert('Settlement successful: ₹' + amount.toLocaleString('en-IN') + ' sent to ' + upi, 'success');
          document.getElementById('stlUpi').value = '';
          document.getElementById('stlAmount').value = '';
          document.getElementById('stlNotes').value = '';
          loadSettlements();
        } else {
          showSuperAlert(d.message || 'Settlement failed', 'error');
          loadSettlements();
        }
      } catch(e) {
        showSuperAlert('Error: ' + e.message, 'error');
      } finally {
        btn.disabled = false;
        btn.textContent = 'Settle Now';
      }
    });

    // ==================== MENUS ====================
    let selectedRestaurantId = '';

    async function loadMenuRestaurants() {
      try {
        const res = await fetch('api.php?action=getRestaurants&limit=200');
        const d = await res.json();
        const select = document.getElementById('menuRestaurantSelect');
        if (!select) return;
        select.innerHTML = '<option value="">Select a restaurant...</option>' +
          (d.restaurants || []).map(r => `<option value="${r.restaurant_id}">${saEsc(r.restaurant_name)} (${r.restaurant_id})</option>`).join('');
        document.getElementById('menuCategoriesSection').style.display = 'none';
      } catch(e) { console.error('loadMenuRestaurants:', e); }
    }

    document.getElementById('menuRestaurantSelect')?.addEventListener('change', async (e) => {
      selectedRestaurantId = e.target.value;
      if (!selectedRestaurantId) {
        document.getElementById('menuCategoriesSection').style.display = 'none';
        return;
      }
      document.getElementById('selectedRestaurantName').textContent =
        e.target.options[e.target.selectedIndex].text;
      document.getElementById('menuCategoriesSection').style.display = 'block';

      try {
        const res = await fetch(`api.php?action=getMenus&restaurant_id=${encodeURIComponent(selectedRestaurantId)}`);
        const d = await res.json();
        if (d.success) {
          const formatted = d.menus.map(m => {
            const subs = (m.subcategories || []).map(s => s.subcategory_name).join(', ');
            return subs ? `${m.menu_name} (${subs})` : m.menu_name;
          }).join(', ');
          document.getElementById('menuCategoriesInput').value = formatted;
          renderMenuPreview();
        }
      } catch(e) { console.error('load menus:', e); }
    });

    document.getElementById('menuCategoriesInput')?.addEventListener('input', renderMenuPreview);

    document.getElementById('saveMenuCategories')?.addEventListener('click', async () => {
      const input = document.getElementById('menuCategoriesInput').value.trim();
      if (!selectedRestaurantId || !input) {
        showSuperAlert('Select a restaurant and enter at least one category', 'warning');
        return;
      }
      try {
        const res = await fetch('api.php?action=saveFullMenu', {
          method: 'POST',
          body: JSON.stringify({ restaurant_id: selectedRestaurantId, text: input }),
          headers: { 'Content-Type': 'application/json' }
        });
        const d = await res.json();
        if (d.success) {
          showSuperAlert('Menu categories & subcategories saved!', 'success');
          document.getElementById('menuRestaurantSelect').dispatchEvent(new Event('change'));
        } else {
          showSuperAlert(d.message || 'Error', 'error');
        }
      } catch(e) { showSuperAlert('Error: ' + e.message, 'error'); }
    });

    function renderMenuPreview() {
      const input = document.getElementById('menuCategoriesInput').value.trim();
      const container = document.getElementById('parsedMenuPreview');
      if (!input) { container.innerHTML = ''; return; }
      const items = input.match(/(?:[^,(]|\([^)]*\))+/g) || [];
      let html = '<h3 style="margin-bottom:12px;font-size:0.95rem;">Preview</h3>';
      items.forEach(raw => {
        raw = raw.trim();
        if (!raw) return;
        const match = raw.match(/^(.+?)\s*\((.+)\)\s*$/);
        if (match) {
          const cat = match[1].trim();
          const subs = match[2].split(',').map(s => s.trim()).filter(s => s);
          html += `<div style="margin-bottom:10px;padding:10px;border:1px solid var(--border);border-radius:8px;background:#fafbfc;"><strong style="color:var(--primary);">${cat}</strong><div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px;">${subs.map(s => `<span class="badge badge-info">${s}</span>`).join('')}</div></div>`;
        } else {
          html += `<div style="margin-bottom:10px;padding:10px;border:1px solid var(--border);border-radius:8px;background:#fafbfc;"><strong style="color:var(--primary);">${raw}</strong></div>`;
        }
      });
      container.innerHTML = html;
      generatePrompt();
    }

    function generatePrompt() {
      const input = document.getElementById('menuCategoriesInput').value.trim();
      const el = document.getElementById('aiPromptText');
      const items = input ? input.match(/(?:[^,(]|\([^)]*\))+/g) || [] : [];
      let cats = [];
      items.forEach(raw => {
        raw = raw.trim();
        if (!raw) return;
        const m = raw.match(/^(.+?)\s*\((.+)\)\s*$/);
        cats.push(m ? m[1].trim() : raw);
      });
      el.value = `You are a restaurant menu data converter. Convert the given menu into the exact format below. Follow ALL rules precisely.

=== STEP 1: CATEGORIES & SUBCATEGORIES ===
Format: Comma-separated, subcategories in parentheses
Example: Pizza (Margherita, Pepperoni, BBQ Chicken), Burger (Classic, Cheese), Beverages, Desserts

Rules:
- Categories are the main sections of the menu (e.g., Breakfast, Pizza, Burgers, Beverages)
- Subcategories are groups WITHIN a category (e.g., under Pizza you might have Margherita, Pepperoni)
- Only create subcategories when the menu actually groups items that way
- If all items are directly under a category with no subgroups, skip subcategories entirely

=== STEP 2: MENU ITEMS ===
Format (one item per line):
Category > Subcategory | Item Name | Price | Type | Description | PrepTime | Calories | Stock | Variations(Name:Price) | ImageURL

RULES (strictly follow these):
1. SEPARATOR: Use | (pipe) between fields, never commas
2. CATEGORY: Must match exactly one of the categories from Step 1
3. SUBCATEGORY: Use > only if that category actually has subcategories. For items directly under a category, write just "Category |" with no >
4. NEVER create a subcategory with the same name as the category — "Breakfast > Breakfast" is WRONG, write "Breakfast |" instead
5. PRICE: Numbers only (e.g., 199, 12.99). No currency symbols
6. TYPE: One of: Veg, Non Veg, Egg, Drink, Dessert, Other
7. DESCRIPTION: Generate a short, realistic description for each item based on its name and type (e.g., "Wood-fired crust with fresh mozzarella and basil" for Margherita Pizza). Keep it 5-15 words
8. PREPTIME: Estimate based on item type (appetizers 5-10min, main courses 15-25min, desserts 5-10min, drinks 2-5min)
9. CALORIES: Estimate a realistic calorie count based on the item (leave blank if unsure)
10. STOCK: 1 = available (default for all items)
11. VARIATIONS: If the menu shows sizes (Small/Medium/Large) or options with different prices, use "Small:99,Medium:149,Large:199" format. Otherwise leave blank
12. IMAGEURL: Search online for a matching stock photo for each item and provide the direct image URL. For popular dishes like pizza, burger, pasta etc. use well-known CDN URLs. If you cannot find a real URL, leave blank

=== EXAMPLES ===
Correct:
Pizza > Margherita | Classic Margherita | 199 | Veg | Fresh mozzarella and basil | 15 | 350 | 1 | Small:149,Medium:199 |
Pizza | Cheese Burst | 279 | Veg | Cheese-filled crust | 20 | 610 | 1 | |
Breakfast | Pancakes | 149 | Veg | With maple syrup | 10 | 420 | 1 | |
Beverages | Coke | 49 | Drink | 330ml can | 2 | 140 | 1 | |

Wrong (do NOT do this):
Breakfast > Breakfast | Pancakes | 149 | Veg |  ← BAD: subcategory matches category name
Pizza, Cheese Burst, 279, Veg  ← BAD: using commas instead of |

=== OUTPUT ===
First output Step 1 (categories), then a blank line, then Step 2 (items).
Use the exact pipe-delimited format so it can be copy-pasted directly.

Current categories already in this system: ${cats.length > 0 ? cats.join(', ') : '(none yet)'}`;
    }

    function copyPrompt() {
      const el = document.getElementById('aiPromptText');
      el.select();
      navigator.clipboard.writeText(el.value).then(() => showSuperAlert('Prompt copied!', 'success')).catch(() => { document.execCommand('copy'); showSuperAlert('Prompt copied!', 'success'); });
    }

    async function confirmSuper(message, title = 'Confirm') {
      if (window.Swal) {
        const { isConfirmed } = await Swal.fire({
          title, text: message, icon: 'question', showCancelButton: true,
          confirmButtonColor: '#111827', cancelButtonColor: '#6b7280',
          confirmButtonText: 'Yes', cancelButtonText: 'Cancel'
        });
        return isConfirmed;
      }
      return window.confirm(message);
    }

    document.getElementById('generateFromImagesBtn')?.addEventListener('click', async () => {
      const restaurantId = document.getElementById('menuRestaurantSelect').value;
      if (!restaurantId) { showSuperAlert('Select a restaurant first.', 'error'); return; }

      const filesInput = document.getElementById('menuImagesInput');
      const files = filesInput.files;
      if (!files || files.length === 0) { showSuperAlert('Choose at least one menu photo or PDF first.', 'error'); return; }
      if (files.length > 6) { showSuperAlert('Please upload at most 6 files at a time.', 'error'); return; }

      const existingText = document.getElementById('menuItemsInput').value.trim();
      if (existingText) {
        const ok = await confirmSuper('This will replace the current content of "Bulk Menu Items" below with the AI-generated items. Continue?');
        if (!ok) return;
      }

      const btn = document.getElementById('generateFromImagesBtn');
      const status = document.getElementById('aiGenerateStatus');
      btn.disabled = true;
      btn.textContent = 'Analyzing...';
      status.style.color = 'var(--muted)';
      status.textContent = 'This can take up to a minute, especially with multiple photos or a multi-page PDF.';

      try {
        const fd = new FormData();
        fd.append('restaurant_id', restaurantId);
        fd.append('categories', document.getElementById('menuCategoriesInput').value.trim());
        for (const f of files) fd.append('images[]', f);

        const res = await fetch('api.php?action=generateMenuItemsFromImages', { method: 'POST', body: fd });
        const d = await res.json();

        if (d.success) {
          document.getElementById('menuItemsInput').value = d.text;
          renderItemsPreview();
          status.style.color = '#16a34a';
          status.textContent = `Found ${d.count} item(s). Review below, then click "Add / Update Only" or "Replace All Items" to save.`;
          filesInput.value = '';
        } else {
          status.style.color = '#dc2626';
          status.textContent = d.message || 'Failed to generate items from these photos.';
        }
      } catch (e) {
        status.style.color = '#dc2626';
        status.textContent = 'Network error while contacting AI. Please try again.';
      } finally {
        btn.disabled = false;
        btn.textContent = 'Generate Items with AI';
      }
    });

    function previewImageSrc(img) {
      if (!img) return '';
      if (img.indexOf('http://') === 0 || img.indexOf('https://') === 0) return img;
      return '../api/image.php?id=' + encodeURIComponent(img) + '&_=' + Date.now();
    }

    function renderItemsPreview() {
      const input = document.getElementById('menuItemsInput').value.trim();
      const container = document.getElementById('parsedItemsPreview');
      if (!input) { container.innerHTML = ''; return; }
      const lines = input.split('\n').map(s => s.trim()).filter(s => s);
      let html = '<h3 style="margin-bottom:12px;font-size:0.95rem;">Items Preview</h3>';
      lines.forEach((line, idx) => {
        const parts = line.split('|').map(s => s.trim());
        if (parts.length < 2) { return; }
        const catPart = parts[0];
        const itemName = parts[1];
        const price = parts[2] || '0';
        const type = parts[3] || 'Veg';
        const desc = parts[4] || '';
        const prep = parts[5] || '';
        const cal = parts[6] || '';
        const stock = parts[7] || '1';
        const variations = parts[8] || '';
        const imageUrl = parts[9] || '';
        const catMatch = catPart.match(/^(.+?)\s*>\s*(.+)$/);
        const category = catMatch ? catMatch[1].trim() : catPart;
        const subcategory = catMatch ? catMatch[2].trim() : null;
        let varsHtml = '';
        if (variations) {
          const vars = variations.split(',').map(s => s.trim()).filter(s => s);
          varsHtml = vars.map(v => {
            const vp = v.split(':');
            return vp.length === 2 ? `<span style="font-size:0.8rem;background:#e8e8e8;padding:2px 8px;border-radius:4px;margin-right:4px;">${vp[0].trim()}: ₹${vp[1].trim()}</span>` : '';
          }).join('');
        }
        html += `<div style="margin-bottom:6px;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:#fafbfc;font-size:0.85rem;">
          <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:4px;">
            ${imageUrl ? `<img src="${previewImageSrc(imageUrl)}" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:6px;border:1px solid var(--border);">` : ''}
            <span class="badge badge-info">${category}</span>${subcategory ? `<span class="badge badge-warning">${subcategory}</span>` : ''}
            <span style="font-weight:600;">${itemName}</span>
            <span style="color:var(--muted);">₹${price}</span>
            <span class="badge ${type === 'Veg' ? 'badge-success' : type === 'Non Veg' ? 'badge-danger' : 'badge-info'}">${type}</span>
            ${stock === '0' ? '<span class="badge badge-danger">Out of Stock</span>' : '<span class="badge badge-success">In Stock</span>'}
          </div>
          ${desc ? `<div style="color:var(--muted);font-size:0.8rem;">${desc}</div>` : ''}
          ${prep || cal ? `<div style="color:var(--muted);font-size:0.8rem;margin-top:2px;">Prep: ${prep || '-'}min | Calories: ${cal || '-'}</div>` : ''}
          ${varsHtml ? `<div style="margin-top:4px;">${varsHtml}</div>` : ''}
          ${imageUrl ? `<div style="margin-top:4px;font-size:0.8rem;color:var(--blue);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">🖼 ${imageUrl.indexOf('local:') === 0 ? 'Auto-matched: ' + imageUrl.substring(6) : imageUrl}</div>` : ''}
        </div>`;
      });
      container.innerHTML = html;
    }

    document.getElementById('menuItemsInput')?.addEventListener('input', renderItemsPreview);

    function saveMenuItems(mode) {
      const input = document.getElementById('menuItemsInput').value.trim();
      if (!selectedRestaurantId || !input) { showSuperAlert('Select a restaurant and enter items', 'warning'); return; }
      fetch('api.php?action=saveMenuItems', {
        method: 'POST',
        body: JSON.stringify({ restaurant_id: selectedRestaurantId, text: input, mode }),
        headers: { 'Content-Type': 'application/json' }
      })
      .then(r => r.json())
      .then(d => {
        if (d.success) {
          showSuperAlert(d.message || 'Done!', 'success');
          document.getElementById('menuItemsInput').value = '';
          renderItemsPreview();
        } else {
          showSuperAlert(d.message || 'Error', 'error');
        }
      })
      .catch(e => showSuperAlert('Error: ' + e.message, 'error'));
    }

    document.getElementById('saveMenuItemsReplace')?.addEventListener('click', () => saveMenuItems('replace'));
    document.getElementById('saveMenuItemsUpdate')?.addEventListener('click', () => saveMenuItems('update'));

    // Sidebar toggle for mobile
    function toggleSidebar() {
      document.querySelector('.sidebar').classList.toggle('open');
      document.querySelector('.sidebar-overlay').classList.toggle('active');
    }
    document.querySelector('.sidebar-overlay')?.addEventListener('click', toggleSidebar);

    // Subscriptions page
    let subAllRestaurants = [];
    let subSearchQuery = '';

    async function loadSubscriptions() {
      try {
        let all = [];
        let page = 1;
        const limit = 50;
        while (true) {
          const res = await fetch(`api.php?action=getRestaurants&limit=${limit}&page=${page}`);
          const data = await res.json();
          all = all.concat(data.restaurants || []);
          const total = data.total || 0;
          if (all.length >= total || !(data.restaurants || []).length) break;
          page++;
        }
        subAllRestaurants = all;
        renderSubscriptions();
      } catch(e) {
        console.error('Load subscriptions error:', e);
      }
    }

    function renderSubscriptions() {
      const tbody = document.getElementById('subscriptionsTbody');
      if (!tbody) return;
      const q = subSearchQuery.toLowerCase();
      const filtered = q ? subAllRestaurants.filter(r =>
        (r.restaurant_name || '').toLowerCase().includes(q) ||
        (r.restaurant_id || '').toLowerCase().includes(q) ||
        (r.username || '').toLowerCase().includes(q)
      ) : subAllRestaurants;
      tbody.innerHTML = filtered.map(r => {
        const status = r.trial_status || 'Unknown';
        const subStatus = r.subscription_status || 'unknown';
        const statusClass = status === 'Active' ? 'badge-success' : (status === 'Expired' ? 'badge-danger' : 'badge-warning');
        const statusIcon = status === 'Active' ? '✅' : (status === 'Expired' ? '❌' : '⏳');
        return `<tr>
          <td><strong>${saEsc(r.restaurant_name)}</strong><br><small style="color:#6b7280;">${r.restaurant_id}</small></td>
          <td><span class="badge ${statusClass}">${statusIcon} ${status}</span><br><small style="color:#6b7280;">${subStatus}</small></td>
          <td>${r.trial_end_date || '--'}</td>
          <td>${r.renewal_date || '--'}<br><small style="color:#6b7280;">${r.days_left || 0} days left</small></td>
          <td style="white-space:nowrap;">
            <button class="btn btn-sm btn-success" onclick="activateSubscription(${r.id})" title="Activate 30 days">✓ Activate</button>
            <button class="btn btn-sm btn-primary" onclick="customDuration(${r.id})" title="Custom duration">📅 Custom</button>
            <button class="btn btn-sm btn-danger" onclick="expireSubscription(${r.id})" title="Mark expired">✗ Expire</button>
          </td>
        </tr>`;
      }).join('') || '<tr><td colspan="5" style="text-align:center;padding:30px;color:#999;">No restaurants found</td></tr>';
    }

    document.getElementById('subSearch')?.addEventListener('input', (e) => {
      subSearchQuery = e.target.value.trim();
      renderSubscriptions();
    });

    async function loadSubscriptionStats() {
      try {
        const res = await fetch('api.php?action=getSubscriptionStats');
        const data = await res.json();
        if (data.success && data.stats) {
          document.getElementById('subActive').textContent = data.stats.active;
          document.getElementById('subTrial').textContent = data.stats.trial;
          document.getElementById('subExpired').textContent = data.stats.expired;
          document.getElementById('subRevenue').textContent = '₹' + Number(data.stats.monthly_revenue).toLocaleString();
        }
      } catch(e) {}
    }

    async function activateSubscription(id) {
      if (!confirm('Activate subscription for 30 days?')) return;
      try {
        const res = await fetch('api.php?action=updateSubscription', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({id, action_type: 'activate', notes: 'Activated by superadmin'})
        });
        const data = await res.json();
        if (data.success) {
          alert('✓ ' + data.message);
          loadSubscriptions();
          loadSubscriptionStats();
        } else {
          alert('✗ ' + (data.message || 'Failed'));
        }
      } catch(e) {
        alert('Error: ' + e.message);
      }
    }

    async function expireSubscription(id) {
      if (!confirm('Mark this subscription as expired?')) return;
      try {
        const res = await fetch('api.php?action=updateSubscription', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({id, action_type: 'expire', notes: 'Expired by superadmin'})
        });
        const data = await res.json();
        if (data.success) {
          alert('✓ ' + data.message);
          loadSubscriptions();
          loadSubscriptionStats();
        } else {
          alert('✗ ' + (data.message || 'Failed'));
        }
      } catch(e) {
        alert('Error: ' + e.message);
      }
    }

    function customDuration(id) {
      // Looked up from the already-loaded list rather than passed through
      // the onclick attribute — embedding restaurant_name directly in an
      // HTML attribute string let a crafted restaurant name break out of
      // the attribute and run arbitrary JS (escaping alone doesn't survive
      // the browser's attribute-decode-then-eval step for inline handlers).
      const found = subAllRestaurants.find(r => r.id === id);
      const name = found ? found.restaurant_name : '';
      const input = prompt('Enter duration in months for "' + name + '":\n(Cancel to abort, or type 1-60)', '12');
      if (input === null) return; // user clicked Cancel
      if (input.trim() === '') return;
      const m = parseInt(input);
      if (isNaN(m) || m < 1 || m > 60) {
        alert('Please enter a valid number between 1 and 60');
        return;
      }
      const notesInput = prompt('Add notes (e.g. "Paid via bank transfer for 1 year"):', 'Yearly renewal');
      const notes = (notesInput !== null && notesInput.trim() !== '') ? notesInput.trim() : 'Custom duration by superadmin';
      fetch('api.php?action=updateSubscription', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id, action_type: 'custom', duration_months: m, notes: notes})
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          alert('✓ ' + data.message);
          loadSubscriptions();
          loadSubscriptionStats();
        } else {
          alert('✗ ' + (data.message || 'Failed'));
        }
      })
      .catch(e => alert('Error: ' + e.message));
    }

    // Payment history page
    async function loadPaymentHistory() {
      try {
        const search = document.getElementById('paymentSearch')?.value || '';
        const status = document.getElementById('paymentStatusFilter')?.value || '';
        let url = 'api.php?action=getSubscriptionPayments&limit=100';
        if (search) url += '&search=' + encodeURIComponent(search);
        if (status) url += '&status=' + encodeURIComponent(status);
        const res = await fetch(url);
        const data = await res.json();
        const tbody = document.getElementById('paymentsTbody');
        if (!tbody) return;
        tbody.innerHTML = (data.payments||[]).map(p => {
          const statusClass = p.payment_status === 'success' ? 'badge-success' : (p.payment_status === 'failed' ? 'badge-danger' : 'badge-warning');
          return `<tr>
            <td><small>${p.transaction_id || '--'}</small></td>
            <td>${saEsc(p.restaurant_name) || p.restaurant_id || '--'}</td>
            <td>₹${Number(p.amount || 0).toLocaleString()}</td>
            <td>${p.subscription_type || '--'}</td>
            <td><span class="badge ${statusClass}">${p.payment_status || '--'}</span></td>
            <td><small>${p.created_at || '--'}</small></td>
          </tr>`;
        }).join('') || '<tr><td colspan="6" style="text-align:center;padding:30px;color:#999;">No payments found</td></tr>';
      } catch(e) {
        console.error('Load payments error:', e);
      }
    }

    // Initialize
    loadPhonePeMode();
    loadStats();
    loadRecentActivity();
    fetchRestaurants();
    loadPasswordResetData();
    loadSubscriptions();
    loadSubscriptionStats();
    loadPaymentHistory();

    // Page switch handlers
    document.getElementById('paymentSearch')?.addEventListener('input', loadPaymentHistory);
    document.getElementById('paymentStatusFilter')?.addEventListener('change', loadPaymentHistory);

    // Restore last visited page
    const savedPage = localStorage.getItem('saCurrentPage');
    if (savedPage) {
      const target = document.querySelector(`.menu-item[data-page="${savedPage}"]`);
      if (target) target.click();
    }
  </script>
</body>
</html>
<?php
// after the page is sent: add any speed indexes the database is missing (once; config/db_indexes.php)
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); elseif (function_exists('litespeed_finish_request')) litespeed_finish_request(); else flush();   // Hostinger runs LiteSpeed
try {
    require_once __DIR__ . '/../db_connection.php';
    require_once __DIR__ . '/../config/db_indexes.php';
    dbEnsureIndexes(getConnection());
} catch (Throwable $e) {
    error_log('superadmin indexes: ' . $e->getMessage());
}
