const sidebar = document.querySelector(".sidebar");
const sidebarToggler = document.querySelector(".sidebar-toggler");
const menuToggler = document.querySelector(".menu-toggler");

// Ensure these heights match the CSS sidebar height values
let collapsedSidebarHeight = "56px"; // Height in mobile view (collapsed)
let fullSidebarHeight = "calc(100vh - 32px)"; // Height in larger screen

// Global subscription status
let subscriptionStatus = null;
let subscriptionData = null;

function showToastFallback(msg, type) {
  var existing = document.querySelector('.toast-fallback');
  if (existing) existing.remove();
  var icons = { success: '✓', error: '✕', info: 'ℹ', warning: '⚠' };
  var t = document.createElement('div');
  t.className = 'toast-fallback';
  var bg = type === 'error' ? '#ef4444' : type === 'warning' ? '#f59e0b' : type === 'info' ? '#3b82f6' : '#10b981';
  t.style.cssText = 'position:fixed;bottom:30px;left:50%;transform:translateX(-50%);padding:12px 24px;border-radius:12px;color:#fff;font-size:14px;font-weight:500;z-index:99999;font-family:Poppins,sans-serif;background:' + bg + ';box-shadow:0 4px 20px rgba(0,0,0,0.3);display:flex;align-items:center;gap:8px;opacity:0;transition:opacity 0.3s';
  t.innerHTML = '<span style="font-size:16px;font-weight:700">' + (icons[type] || '') + '</span>' + msg;
  document.body.appendChild(t);
  requestAnimationFrame(function() { t.style.opacity = '1'; });
  setTimeout(function() { t.style.opacity = '0'; setTimeout(function() { t.remove(); }, 300); }, 3000);
}

function showSweetAlert(message, type = 'info', options = {}) {
  if (window.Swal) {
    return Swal.fire({
      icon: type,
      text: message,
      confirmButtonColor: '#d33',
      ...options
    });
  }
  return showToastFallback(message, type);
}

// SweetAlert confirm helper
async function showSweetConfirm(message, title = 'Confirm') {
  if (window.Swal) {
    const result = await Swal.fire({
      title: title,
      text: message,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#d33',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes, proceed',
      cancelButtonText: 'Cancel'
    });
    return result.isConfirmed;
  }
  return window.confirm(message);
}

// SweetAlert prompt helper
async function showSweetPrompt(message, title = 'Input', defaultValue = '') {
  if (window.Swal) {
    const { value } = await Swal.fire({
      title: title,
      text: message,
      input: 'text',
      inputValue: defaultValue,
      showCancelButton: true,
      confirmButtonColor: '#d33',
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
}

// Payment method selector with clickable buttons
async function showPaymentMethodSelector() {
  if (window.Swal) {
    return new Promise((resolve) => {
      Swal.fire({
        title: 'Select Payment Method',
        html: `
          <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin: 20px 0;">
            <button class="payment-method-btn" data-method="Cash" style="padding: 20px; border: 2px solid #e5e7eb; border-radius: 12px; background: white; cursor: pointer; transition: all 0.2s; font-size: 16px; font-weight: 600; color: #111827;">
              <div style="font-size: 32px; margin-bottom: 8px;">💵</div>
              <div>Cash</div>
            </button>
            <button class="payment-method-btn" data-method="Card" style="padding: 20px; border: 2px solid #e5e7eb; border-radius: 12px; background: white; cursor: pointer; transition: all 0.2s; font-size: 16px; font-weight: 600; color: #111827;">
              <div style="font-size: 32px; margin-bottom: 8px;">💳</div>
              <div>Card</div>
            </button>
            <button class="payment-method-btn" data-method="UPI" style="padding: 20px; border: 2px solid #e5e7eb; border-radius: 12px; background: white; cursor: pointer; transition: all 0.2s; font-size: 16px; font-weight: 600; color: #111827;">
              <div style="font-size: 32px; margin-bottom: 8px;">📱</div>
              <div>UPI</div>
            </button>
            <button class="payment-method-btn" data-method="Online" style="padding: 20px; border: 2px solid #e5e7eb; border-radius: 12px; background: white; cursor: pointer; transition: all 0.2s; font-size: 16px; font-weight: 600; color: #111827;">
              <div style="font-size: 32px; margin-bottom: 8px;">🌐</div>
              <div>Online</div>
            </button>
          </div>
          <style>
            .payment-method-btn:hover {
              border-color: #10b981 !important;
              background: #f0fdf4 !important;
              transform: translateY(-2px);
              box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
            }
            .payment-method-btn:active {
              transform: translateY(0);
            }
          </style>
        `,
        showConfirmButton: false,
        showCancelButton: true,
        cancelButtonText: 'Cancel',
        cancelButtonColor: '#6b7280',
        didOpen: () => {
          const buttons = Swal.getHtmlContainer().querySelectorAll('.payment-method-btn');
          buttons.forEach(btn => {
            btn.addEventListener('click', () => {
              const method = btn.getAttribute('data-method');
              Swal.close();
              resolve(method);
            });
          });
        },
        didClose: () => {
          // If closed without selection, resolve with null
          if (!Swal.isConfirmed()) {
            resolve(null);
          }
        }
      });
    });
  }
  // Fallback to prompt if SweetAlert not available
  const method = window.prompt("Select payment method:\n1. Cash\n2. Card\n3. UPI\n4. Online\n\nEnter number:", "1");
  const methods = { '1': 'Cash', '2': 'Card', '3': 'UPI', '4': 'Online' };
  return methods[method] || 'Cash';
}

// Toggle sidebar's collapsed state (only if elements exist)
if (sidebarToggler && sidebar) {
  sidebarToggler.addEventListener("click", () => {
    sidebar.classList.toggle("collapsed");
    updateToggleButton();
  });
}

// Update toggle button visibility
function updateToggleButton() {
  if (!sidebar) return;
  
  const primaryNav = document.querySelector('.primary-nav');
  const existingToggle = document.querySelector('.toggle-menu-item');
  
  if (sidebar.classList.contains('collapsed')) {
    // Remove existing toggle if any
    if (existingToggle) {
      existingToggle.remove();
    }
    
    // Add toggle as first menu item
    if (primaryNav) {
      const toggleItem = document.createElement('div');
      toggleItem.className = 'toggle-menu-item';
      toggleItem.innerHTML = '>';
      toggleItem.addEventListener('click', () => {
        sidebar.classList.remove('collapsed');
        updateToggleButton();
      });
      
      primaryNav.insertBefore(toggleItem, primaryNav.firstChild);
    }
  } else {
    // Remove toggle menu item when expanded
    if (existingToggle) {
      existingToggle.remove();
    }
  }
}

// Update sidebar height and menu toggle text
const toggleMenu = (isMenuActive) => {
  if (!sidebar) return;
  
  // Check if we're on mobile
  if (window.innerWidth < 1024) {
    // On mobile, use a fixed large height for the menu to show all items
    sidebar.style.height = isMenuActive ? 'calc(100vh - 26px)' : collapsedSidebarHeight;
  } else {
    // On desktop, use scrollHeight
    sidebar.style.height = isMenuActive ? `${sidebar.scrollHeight}px` : collapsedSidebarHeight;
  }
  
  if (menuToggler) {
    const span = menuToggler.querySelector("span");
    if (span) {
      span.innerText = isMenuActive ? "close" : "menu";
    }
  }
}

// Toggle menu-active class and adjust height
if (menuToggler && sidebar) {
  menuToggler.addEventListener("click", () => {
    toggleMenu(sidebar.classList.toggle("menu-active"));
  });
}

// Global currency symbol - will be loaded from database
// Check if already set by inline script in dashboard.php (prevents FOUC)
let globalCurrencySymbol = window.globalCurrencySymbol || localStorage.getItem('system_currency') || '₹';

// Get currency symbol from database/session
async function loadCurrencySymbol() {
  // If already set by inline script from PHP, use that value and DO NOT update DOM
  if (window.globalCurrencySymbol) {
    globalCurrencySymbol = window.globalCurrencySymbol;
    // DO NOT update any DOM elements - PHP already set them correctly
    return;
  }
  
  // Only fetch if not set by PHP (shouldn't happen, but fallback)
  try {
    const response = await fetch('admin/get_session.php');
    const result = await response.json();
    
    if (result.success && result.data.currency_symbol) {
      globalCurrencySymbol = result.data.currency_symbol;
      window.globalCurrencySymbol = globalCurrencySymbol;
      // Also save to localStorage as backup
      localStorage.setItem('system_currency', globalCurrencySymbol);
      // DO NOT update DOM - only set the variable for formatCurrency() function
    } else {
      // Fallback to localStorage
      globalCurrencySymbol = localStorage.getItem('system_currency') || '₹';
      window.globalCurrencySymbol = globalCurrencySymbol;
    }
  } catch (error) {
    console.error('Error loading currency symbol:', error);
    // Fallback to localStorage
    globalCurrencySymbol = localStorage.getItem('system_currency') || '₹';
    window.globalCurrencySymbol = globalCurrencySymbol;
  }
  // DO NOT update any DOM elements - they're already set by PHP
}

// Format currency helper function
function formatCurrency(amount) {
  const symbol = globalCurrencySymbol || '₹';
  return `${symbol}${parseFloat(amount).toFixed(2)}`;
}

// Format currency with locale formatting
function formatCurrencyLocale(amount) {
  const symbol = globalCurrencySymbol || '₹';
  return symbol + parseFloat(amount).toLocaleString('en-IN', {maximumFractionDigits: 2});
}

// Handle session expiration - show message and redirect to login
function handleSessionExpired() {
  // Prevent multiple popups
  if (window.sessionExpiredShown) {
    return;
  }
  window.sessionExpiredShown = true;
  
  if (window.Swal) {
    Swal.fire({
      icon: 'warning',
      title: 'Session Expired',
      html: '<p style="font-size: 1rem; color: #374151; margin-bottom: 1rem;">Your session has expired. Please login again.</p>',
      confirmButtonText: 'Go to Login',
      confirmButtonColor: '#dc2626',
      allowOutsideClick: false,
      allowEscapeKey: false,
      showCancelButton: false,
      focusConfirm: true
    }).then(() => {
      // Redirect to login page
      window.location.href = 'admin/login.php';
    });
  } else {
    showToastFallback('Session expired. Please login again.', 'warning');
    setTimeout(function() { window.location.href = 'admin/login.php'; }, 1500);
  }
}

// Check API response for session expiration
function checkSessionExpired(response, data) {
  if (!response) return false;
  
  // Check HTTP status codes first
  if (response.status === 401) {
    handleSessionExpired();
    return true;
  }
  
  // Check response data if available
  if (data) {
    // Check if response indicates session expired
    if (data.success === false && 
        data.message && (
          data.message.toLowerCase().includes('session expired') ||
          data.message.toLowerCase().includes('please login again') ||
          data.message.toLowerCase().includes('login again')
        )) {
      handleSessionExpired();
      return true;
    }
  }
  
  return false;
}

// Submenu toggle functionality
document.addEventListener("DOMContentLoaded", () => {
  // Currency symbol is already set by PHP server-side (like restaurant logo/name)
  // Only load if not already set by inline script
  if (!window.globalCurrencySymbol) {
    loadCurrencySymbol();
  } else {
    // Use the server-loaded value
    globalCurrencySymbol = window.globalCurrencySymbol;
  }
  
  // DO NOT update currency displays on page load - they're already correct from PHP
  // Only update dynamically added elements or when user changes settings
  const submenuToggles = document.querySelectorAll(".submenu-toggle");
  
  submenuToggles.forEach(toggle => {
    toggle.addEventListener("click", (e) => {
      e.preventDefault();
      const navItem = toggle.closest(".nav-item.has-submenu");
      navItem.classList.toggle("active");
    });
  });
  
  // Add auto-close for submenu links without data-page
  const submenuLinks = document.querySelectorAll(".submenu-link");
  submenuLinks.forEach(link => {
    link.addEventListener("click", (e) => {
      // Auto-close sidebar on mobile after selecting an option
      if (sidebar && window.innerWidth < 1024 && sidebar.classList.contains("menu-active")) {
        sidebar.classList.remove("menu-active");
        // Reset height to collapsed state
        sidebar.style.height = collapsedSidebarHeight;
        if (menuToggler) {
          const span = menuToggler.querySelector("span");
          if (span) span.innerText = "menu";
        }
      }
    });
  });

  // Page navigation functionality
  const navLinks = document.querySelectorAll(".nav-link[data-page]");
  const pages = document.querySelectorAll(".page");

  navLinks.forEach(link => {
    link.addEventListener("click", (e) => {
      e.preventDefault();
      const targetPage = link.getAttribute("data-page");
      showPage(targetPage);
      
      // Close any open submenus
      document.querySelectorAll(".nav-item.has-submenu.active").forEach(item => {
        item.classList.remove("active");
      });
      
      // Auto-close sidebar on mobile after selecting an option
      if (sidebar && window.innerWidth < 1024 && sidebar.classList.contains("menu-active")) {
        sidebar.classList.remove("menu-active");
        // Reset height to collapsed state
        sidebar.style.height = collapsedSidebarHeight;
        if (menuToggler) {
          const span = menuToggler.querySelector("span");
          if (span) span.innerText = "menu";
        }
      }
    });
  });

  function showPage(pageId) {
    // Check subscription status before allowing access (except dashboard and settings)
    if (pageId !== "dashboardPage" && pageId !== "settingsPage") {
      if (subscriptionStatus === 'disabled' || subscriptionStatus === 'expired') {
        // Show renewal modal
        const renewalModal = document.getElementById('renewalModal');
        if (renewalModal) {
          renewalModal.style.display = 'flex';
        }
        // Keep dashboard active
        const dashboardPage = document.getElementById('dashboardPage');
        if (dashboardPage) {
          dashboardPage.classList.add('active');
        }
        return; // Block access to other pages
      }
    }
    
    // Hide all pages
    pages.forEach(page => {
      page.classList.remove("active");
    });
    
    // Show target page
    const targetPage = document.getElementById(pageId);
    if (targetPage) {
      targetPage.classList.add("active");
      
      // Load dashboard stats if it's the dashboard page
      if (pageId === "dashboardPage") {
        console.log('Switching to dashboard, loading stats...');
        setTimeout(() => loadDashboardStats(), 100);
      }
      
      // Load menus if it's the menu page
      if (pageId === "menuPage") {
        loadMenus();
      }
      
      // Load menu items if it's the menu items page
      if (pageId === "menuItemsPage") {
        loadMenuItems();
        loadMenusForFilter();
        // Set up subcategory filter change listener
        setTimeout(() => {
          const subFilterEl = document.getElementById("subcategoryFilter");
          if (subFilterEl && !subFilterEl.dataset.listenerAttached) {
            subFilterEl.addEventListener("change", loadMenuItems);
            subFilterEl.dataset.listenerAttached = "true";
          }
          const menuFilterEl = document.getElementById("menuFilter");
          if (menuFilterEl && !menuFilterEl.dataset.subFilterAttached) {
            menuFilterEl.addEventListener("change", function() { updateSubcategoryFilter(); });
            menuFilterEl.dataset.subFilterAttached = "true";
          }
        }, 100);
      }
      
      // Load areas if it's the area page
      if (pageId === "areaPage") {
        loadAreas();
      }
      
      // Load tables if it's the tables page
      if (pageId === "tablesPage") {
        loadTables();
      }
      
      // Load QR codes if it's the QR codes page
      if (pageId === "qrCodesPage") {
        loadQRCodes();
      }
      
      // Load reservations if it's the reservations page
      if (pageId === "reservationsPage") {
        loadReservations();
      }
      
      // Load customers if it's the customers page
      if (pageId === "customersPage") {
        loadCustomers();
      }
      
      // Load staff if it's the staff page
      if (pageId === "staffPage") {
        loadStaff();
      }
      
      // Load POS if it's the POS page
      if (pageId === "posPage") {
        loadPOSMenuItems();
        loadTablesForPOS();
        loadMenusForPOSFilters();
        loadCategoriesForPOSFilters();
      }
      
      // Load KOT if it's the KOT page
      if (pageId === "kotPage") {
        loadKOTOrders();
        loadTablesForKOT();
        // Start auto-refresh when KOT page is active (optimized: 10 seconds to reduce DB load)
        // Uses intelligent polling: faster when active, slower when idle
        if (window.kotAutoRefresh) {
          clearInterval(window.kotAutoRefresh);
        }
        let kotRefreshInterval = 10000; // Start with 10 seconds
        let kotLastUpdate = Date.now();
        let kotNoChangeCount = 0;
        
        const kotRefreshFunction = () => {
          // Only refresh if page is active AND visible
          const kotPage = document.getElementById('kotPage');
          if (kotPage?.classList.contains('active') && !document.hidden) {
            const beforeTime = Date.now();
            loadKOTOrders().then(() => {
              // Intelligent polling: if no changes detected, slow down
              const timeSinceLastUpdate = Date.now() - kotLastUpdate;
              if (timeSinceLastUpdate > 30000) { // No updates in 30 seconds
                kotNoChangeCount++;
                if (kotNoChangeCount > 3) {
                  kotRefreshInterval = Math.min(30000, kotRefreshInterval * 1.5); // Max 30 seconds
                }
              } else {
                kotNoChangeCount = 0;
                kotRefreshInterval = 10000; // Reset to 10 seconds when active
              }
              kotLastUpdate = Date.now();
              
              // Restart with new interval
              if (window.kotAutoRefresh) {
                clearInterval(window.kotAutoRefresh);
              }
              window.kotAutoRefresh = setInterval(kotRefreshFunction, kotRefreshInterval);
            }).catch(() => {
              // On error, slow down polling
              kotRefreshInterval = Math.min(30000, kotRefreshInterval * 1.2);
              if (window.kotAutoRefresh) {
                clearInterval(window.kotAutoRefresh);
              }
              window.kotAutoRefresh = setInterval(kotRefreshFunction, kotRefreshInterval);
            });
          }
        };
        
        window.kotAutoRefresh = setInterval(kotRefreshFunction, kotRefreshInterval);
        
        // Pause auto-refresh when page is hidden, resume when visible
        if (!window.kotVisibilityHandler) {
          window.kotVisibilityHandler = function() {
            if (document.hidden) {
              if (window.kotAutoRefresh) {
                clearInterval(window.kotAutoRefresh);
                window.kotAutoRefresh = null;
              }
            } else if (document.getElementById('kotPage')?.classList.contains('active')) {
              if (!window.kotAutoRefresh) {
                // Resume with optimized interval
                kotRefreshInterval = 10000;
                window.kotAutoRefresh = setInterval(kotRefreshFunction, kotRefreshInterval);
              }
            }
          };
          document.addEventListener('visibilitychange', window.kotVisibilityHandler);
        }
      } else {
        // Stop auto-refresh when leaving KOT page
        if (window.kotAutoRefresh) {
          clearInterval(window.kotAutoRefresh);
          window.kotAutoRefresh = null;
        }
      }
      
      // Load Orders if it's the Orders page
      if (pageId === "ordersPage") {
        // Set default date to today
        const dateFilter = document.getElementById('ordersDateFilter');
        if (dateFilter && !dateFilter.value) {
          const today = new Date().toISOString().split('T')[0];
          dateFilter.value = today;
        }
        loadOrders();
        loadTablesForOrders();
        // Set up orders filter listeners
        setTimeout(() => {
          const ordersSearch = document.getElementById('ordersSearch');
          const ordersDateFilter = document.getElementById('ordersDateFilter');
          
          // Search with debounce (wait 500ms after user stops typing)
          if (ordersSearch && !ordersSearch.dataset.listenerAttached) {
            let searchTimeout;
            ordersSearch.addEventListener('input', () => {
              clearTimeout(searchTimeout);
              searchTimeout = setTimeout(() => {
                loadOrders();
              }, 500); // Wait 500ms after user stops typing
            });
            ordersSearch.dataset.listenerAttached = 'true';
          }
          
          if (ordersDateFilter && !ordersDateFilter.dataset.listenerAttached) {
            ordersDateFilter.addEventListener('change', () => loadOrders());
            ordersDateFilter.dataset.listenerAttached = 'true';
          }
        }, 100);
      }
      
      // Load waiter requests if it's the waiter requests page
      if (pageId === "waiterRequestsPage") {
        loadWaiterRequests();
      }
      
      // Load profile data if it's the profile page
      if (pageId === "profilePage") {
        loadProfileData();
        setupPasswordFormHandler();
      }
      
      // Load payments if it's the payments page
      if (pageId === "paymentsPage") {
        loadPayments();
      }
      
      // Load settings data if it's the settings page
      if (pageId === "settingsPage") {
        loadSettingsData();
      }
      
      // Initialize website theme editor if it's the website theme page
      if (pageId === "websiteThemePage") {
        // Reset initialization flag when page is shown
        websiteThemeInitialized = false;
        setTimeout(() => {
          initWebsiteThemeEditor();
        }, 200);
      }
    }
  }

  // Modal functionality
  const menuModal = document.getElementById("menuModal");
  const deleteModal = document.getElementById("deleteModal");
  const addMenuBtn = document.getElementById("addMenuBtn");
  const menuForm = document.getElementById("menuForm");
  const modalTitle = document.getElementById("modalTitle");
  const menuIdInput = document.getElementById("menuId");
  const menuNameInput = document.getElementById("menuName");
  const saveBtn = document.getElementById("saveBtn");
  
  let currentMenuId = null;
  let currentMenuName = null;

  // Open modal for adding new menu
  addMenuBtn.addEventListener("click", (e) => {
    e.preventDefault();
    openMenuModal();
  });
  
  // Add Subcategory button click handler
  window.setupAddSubcategoryBtn = function() {
    var addSubBtn = document.getElementById("addSubcategoryBtn");
    if (addSubBtn) {
      var newBtn = addSubBtn.cloneNode(true);
      addSubBtn.parentNode.replaceChild(newBtn, addSubBtn);
      newBtn.addEventListener("click", function(e) {
        e.preventDefault();
        var menuId = document.getElementById("menuId").value;
        if (menuId) {
          openSubcategoryModal(menuId, null, null);
        } else {
          showMessage("Please save the category first before adding subcategories.", "info");
        }
      });
    }
  };
  window.setupAddSubcategoryBtn();

  // Open modal for editing existing menu
  window.editMenu = function(menuId, menuName) {
    currentMenuId = menuId;
    currentMenuName = menuName;
    openMenuModal(true);
  };

  function openMenuModal(isEdit = false) {
    if (isEdit) {
      modalTitle.textContent = "Edit Menu";
      menuIdInput.value = currentMenuId;
      menuNameInput.value = currentMenuName;
      saveBtn.textContent = "Update Menu";
    } else {
      modalTitle.textContent = "Add New Menu";
      menuIdInput.value = "";
      menuNameInput.value = "";
      saveBtn.textContent = "Save Menu";
    }
    
    menuModal.style.display = "block";
    document.body.style.overflow = "hidden";
    
    // Clear any existing messages
    const existingMessage = document.querySelector(".message");
    if (existingMessage) {
      existingMessage.remove();
    }
    
    // Focus the input field after a short delay to ensure modal is fully rendered
    setTimeout(() => {
      menuNameInput.focus();
      menuNameInput.select(); // Select all text for easy editing
      
      // Ensure the input is editable
      menuNameInput.readOnly = false;
      menuNameInput.disabled = false;
      
      // Force focus and selection
      menuNameInput.focus();
      if (isEdit && menuNameInput.value) {
        menuNameInput.setSelectionRange(0, menuNameInput.value.length);
      }
    }, 150);
  }

  // Close modal functions
  function closeMenuModal() {
    menuModal.style.display = "none";
    document.body.style.overflow = "auto";
    menuForm.reset();
    currentMenuId = null;
    currentMenuName = null;
    hideSubcategoriesSection();
    
    // Clear any existing messages
    const existingMessage = document.querySelector(".message");
    if (existingMessage) {
      existingMessage.remove();
    }
  }

  function closeDeleteModal() {
    deleteModal.style.display = "none";
    document.body.style.overflow = "auto";
    currentMenuId = null;
    currentMenuName = null;
  }

  // Close modal event listeners
  document.querySelectorAll(".close").forEach(closeBtn => {
    closeBtn.addEventListener("click", (e) => {
      if (e.target.closest("#menuModal")) {
        closeMenuModal();
      } else if (e.target.closest("#menuItemModal")) {
        closeMenuItemModal();
      } else if (e.target.closest("#deleteModal")) {
        closeDeleteModal();
      } else if (e.target.closest("#areaModal")) {
        closeAreaModal();
      } else if (e.target.closest("#tableModal")) {
        closeTableModal();
      } else if (e.target.closest("#reservationModal")) {
        closeReservationModal();
      } else if (e.target.closest("#customerModal")) {
        closeCustomerModal();
      }
    });
  });

  document.getElementById("cancelBtn").addEventListener("click", closeMenuModal);
  document.getElementById("menuItemCancelBtn").addEventListener("click", closeMenuItemModal);
  document.getElementById("deleteCancelBtn").addEventListener("click", closeDeleteModal);

  // Close modal when clicking outside
  window.addEventListener("click", (e) => {
    if (e.target === menuModal) {
      closeMenuModal();
    } else if (e.target === menuItemModal) {
      closeMenuItemModal();
    } else if (e.target === deleteModal) {
      closeDeleteModal();
    } else if (e.target === areaModal) {
      closeAreaModal();
    } else if (e.target === tableModal) {
      closeTableModal();
    } else if (e.target === reservationModal) {
      closeReservationModal();
    } else if (e.target === customerModal) {
      closeCustomerModal();
    }
  });

  // Handle menu form submission (add/edit)
  menuForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    
    const menuName = menuNameInput.value.trim();
    const menuId = menuIdInput.value;
    const isEdit = menuId !== "";
    
    if (!menuName) {
      showMessage("Please enter a menu name.", "error");
      return;
    }

    // Disable save button and show loading
    saveBtn.disabled = true;
    saveBtn.textContent = isEdit ? "Updating..." : "Saving...";

    try {
      const formData = new URLSearchParams();
      formData.append('action', isEdit ? 'update' : 'add');
    // Add subcategory ID if available
    const subcatDropdown = document.getElementById('subcategoryDropdown');
    if (subcatDropdown && subcatDropdown.value) {
      formData.append('subcategoryId', subcatDropdown.value);
    }
      formData.append('menuName', menuName);
      if (isEdit) {
        formData.append('menuId', menuId);
      }

      const response = await fetch("menu_operations.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: formData
      });

      const result = await response.json();

      if (result.success) {
        showMessage(result.message, "success");
        setTimeout(() => {
          closeMenuModal();
          loadMenus(); // Refresh the menu list
        }, 1500);
      } else {
        showMessage(result.message || "Error processing request. Please try again.", "error");
      }
    } catch (error) {
      console.error("Error:", error);
      showMessage("Network error. Please check your connection and try again.", "error");
    } finally {
      // Re-enable save button
      saveBtn.disabled = false;
      saveBtn.textContent = isEdit ? "Update Menu" : "Save Menu";
    }
  });

  // Function to show messages
  function showMessage(message, type) {
    // Remove existing message
    const existingMessage = document.querySelector(".message");
    if (existingMessage) {
      existingMessage.remove();
    }

    // Create new message
    const messageDiv = document.createElement("div");
    messageDiv.className = `message ${type}`;
    messageDiv.textContent = message;

    // Insert message after form group
    const formGroup = document.querySelector(".form-group");
    if (formGroup) {
      formGroup.insertAdjacentElement("afterend", messageDiv);
    }

    // Auto remove success messages after 3 seconds
    if (type === "success") {
      setTimeout(() => {
        messageDiv.remove();
      }, 3000);
    }
  }

  // Function to show messages in menu item modal
  function showMenuItemMessage(message, type) {
    // Remove existing message
    const existingMessage = document.querySelector("#menuItemModal .message");
    if (existingMessage) {
      existingMessage.remove();
    }

    // Create new message
    const messageDiv = document.createElement("div");
    messageDiv.className = `message ${type}`;
    messageDiv.textContent = message;

    // Insert message after first form group in menu item modal
    const formGroup = document.querySelector("#menuItemModal .form-group");
    if (formGroup) {
      formGroup.insertAdjacentElement("afterend", messageDiv);
    }

    // Auto remove success messages after 3 seconds
    if (type === "success") {
      setTimeout(() => {
        messageDiv.remove();
      }, 3000);
    }
  }

  // Load Dashboard Stats
  async function loadDashboardStats() {
    console.log('Loading dashboard stats...');
    try {
      const response = await fetch('get_dashboard_stats.php');
      const data = await response.json();
      
      console.log('Dashboard API response:', data);
      
      if (data.success) {
        console.log('Stats data received:', data.stats);
        // Update main stats
        document.getElementById('todayRevenue').textContent = formatCurrencyLocale(data.stats.todayRevenue);
        document.getElementById('todayOrders').textContent = data.stats.todayOrders;
        document.getElementById('activeKOT').textContent = data.stats.activeKOT;
        document.getElementById('totalCustomers').textContent = data.stats.totalCustomers;
        document.getElementById('tableInfo').textContent = data.stats.availableTables + '/' + data.stats.totalTables;
        document.getElementById('totalItems').textContent = data.stats.totalItems;
        document.getElementById('pendingOrders').textContent = data.stats.pendingOrders;
        
        // Display recent orders
        const recentOrdersDiv = document.getElementById('recentOrders');
        if (data.recentOrders.length === 0) {
          recentOrdersDiv.innerHTML = `
            <div style="text-align: center; padding: 3rem; color: #999;">
              <span class="material-symbols-rounded" style="font-size: 4rem; display: block; margin-bottom: 1rem; opacity: 0.3;">receipt_long</span>
              <p>No orders today</p>
            </div>
          `;
        } else {
          recentOrdersDiv.innerHTML = '<ul class="order-item-list">' + 
            data.recentOrders.map(order => `
              <li style="border-left: 4px solid #667eea;">
                <div>
                  <div style="font-weight: 700; color: #151A2D; margin-bottom: 0.25rem;">${order.order_number}</div>
                  <div style="font-size: 0.85rem; color: #666;">${order.table_number ? 'Table ' + order.table_number : 'Walk-in'}</div>
                  <div style="margin-top: 0.5rem;">
                    <span style="background: #f8f9fa; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; color: #667eea;">${order.order_status}</span>
                  </div>
                </div>
                <div style="text-align: right;">
                  <div style="color: #48bb78; font-weight: 800; font-size: 1.3rem;">${formatCurrency(order.total)}</div>
                  <div style="font-size: 0.8rem; color: #999;">${new Date(order.created_at).toLocaleTimeString()}</div>
                </div>
              </li>
            `).join('') + '</ul>';
        }
        
        // Display popular items
        const popularItemsDiv = document.getElementById('popularItems');
        if (data.popularItems.length === 0) {
          popularItemsDiv.innerHTML = `
            <div style="text-align: center; padding: 3rem; color: #999;">
              <span class="material-symbols-rounded" style="font-size: 4rem; display: block; margin-bottom: 1rem; opacity: 0.3;">restaurant</span>
              <p>No items sold today</p>
            </div>
          `;
        } else {
          popularItemsDiv.innerHTML = '<ul class="order-item-list">' + 
            data.popularItems.map((item, index) => `
              <li class="item-list-item" style="border-left: 4px solid ${index === 0 ? '#48bb78' : index === 1 ? '#667eea' : '#f6ad55'};">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                  <div style="width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, #667eea, #764ba2); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem;">${index + 1}</div>
                  <span style="font-weight: 600;">${item.item_name}</span>
                </div>
                <span style="font-weight: 800; color: #48bb78; font-size: 1.1rem;">${item.total_qty} sold</span>
              </li>
            `).join('') + '</ul>';
        }
        
        // Update time
        const timeElement = document.getElementById('dashboardTime');
        if (timeElement) {
          const now = new Date();
          timeElement.textContent = `Last updated: ${now.toLocaleTimeString()}`;
        }
        
        console.log('Dashboard stats loaded successfully');
      } else {
        console.error('Dashboard API returned error:', data.message);
      }
    } catch (error) {
      console.error('Error loading dashboard stats:', error);
      // Show error on dashboard
      document.getElementById('todayRevenue').textContent = 'Error';
      document.getElementById('todayOrders').textContent = 'Error';
      document.getElementById('recentOrders').innerHTML = '<p style="color: red;">Error loading data. Check console.</p>';
    }
  }
  
  // Expose for inline scripts (dashboard.php) that call this on window load
  window.loadDashboardStats = loadDashboardStats;

  // Auto-load dashboard on page load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      const dashboardPage = document.getElementById('dashboardPage');
      if (dashboardPage && dashboardPage.classList.contains('active')) {
        console.log('Dashboard page is active on load, loading stats...');
        setTimeout(() => loadDashboardStats(), 500);
      }
    });
  } else {
    // Already loaded
    const dashboardPage = document.getElementById('dashboardPage');
    if (dashboardPage && dashboardPage.classList.contains('active')) {
      console.log('Dashboard page is active, loading stats...');
      setTimeout(() => loadDashboardStats(), 500);
    }
  }

  // Menu management functions
  async function loadMenus() {
    const menuList = document.getElementById("menuList");
    menuList.innerHTML = '<div class="loading">Loading menus...</div>';

    try {
      const response = await fetch("get_menus.php");
      const result = await response.json();

      if (result.success) {
        displayMenus(result.data);
      } else {
        menuList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Error loading menus</h3><p>Please try again later.</p></div>';
      }
    } catch (error) {
      console.error("Error loading menus:", error);
      menuList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Network Error</h3><p>Please check your connection and try again.</p></div>';
    }
  }

  function displayMenus(menus) {
    const menuList = document.getElementById("menuList");
    
    if (menus.length === 0) {
      menuList.innerHTML = `
        <div class="empty-state">
          <span class="material-symbols-rounded">menu</span>
          <h3>No menus found</h3>
          <p>Create your first menu to get started.</p>
        </div>
      `;
      return;
    }

    menuList.innerHTML = menus.map(menu => `
      <div class="menu-card" data-menu-id="${menu.id}">
        <h3>${escapeHtml(menu.menu_name_translated || menu.menu_name)}</h3>
        <div class="menu-date">Created: ${formatDate(menu.created_at)}</div>
        <div class="menu-actions-card">
          <button class="btn-edit" onclick="editMenu(${menu.id}, '${escapeHtml(menu.menu_name_translated || menu.menu_name)}')">
            <span class="material-symbols-rounded">edit</span>
            Edit
          </button>
          <button class="btn-delete" onclick="deleteMenu(${menu.id}, '${escapeHtml(menu.menu_name_translated || menu.menu_name)}')">
            <span class="material-symbols-rounded">delete</span>
            Delete
          </button>
        </div>
      </div>
    `).join('');
  }

  // Delete menu function
  window.deleteMenu = function(menuId, menuName) {
    currentMenuId = menuId;
    currentMenuName = menuName;
    
    document.getElementById("deleteMessage").textContent = 
      `Are you sure you want to delete "${menuName}"? This action cannot be undone.`;
    
    deleteModal.style.display = "block";
    document.body.style.overflow = "hidden";
  };

  // Handle delete confirmation
  document.getElementById("deleteConfirmBtn").addEventListener("click", async () => {
    if (!currentMenuId) return;
    
    const deleteBtn = document.getElementById("deleteConfirmBtn");
    deleteBtn.disabled = true;
    deleteBtn.textContent = "Deleting...";

    try {
      const formData = new URLSearchParams();
      formData.append('action', 'delete');
      formData.append('menuId', currentMenuId);

      const response = await fetch("menu_operations.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: formData
      });

      const result = await response.json();

      if (result.success) {
        showNotification("Menu deleted successfully!", "success");
        closeDeleteModal();
        loadMenus();
      } else {
        showNotification(result.message || "Error deleting menu.", "error");
      }
    } catch (error) {
      console.error("Error deleting menu:", error);
      showNotification("Network error. Please try again.", "error");
    } finally {
      deleteBtn.disabled = false;
      deleteBtn.textContent = "Delete";
    }
  });

  // Utility functions
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
  }

  function showNotification(message, type) {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.textContent = message;
    notification.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 15px 20px;
      border-radius: 8px;
      color: white;
      font-weight: 500;
      z-index: 10000;
      animation: slideInRight 0.3s ease-out;
      ${type === 'success' ? 'background: #28a745;' : 'background: #dc3545;'}
    `;

    document.body.appendChild(notification);

    // Remove notification after 3 seconds
    setTimeout(() => {
      notification.style.animation = 'slideOutRight 0.3s ease-in';
      setTimeout(() => {
        document.body.removeChild(notification);
      }, 300);
    }, 3000);
  }
  
  // Make showNotification globally available
  window.showNotification = showNotification;
  // ========== Subcategory Management Functions ==========
  
  // Load subcategories for a menu and display them in the modal
  async function loadSubcategories(menuId) {
    const subcategoriesList = document.getElementById("subcategoriesList");
    const noMsg = document.getElementById("noSubcategoriesMsg");
    if (!subcategoriesList) return;
    
    try {
      const response = await fetch("get_menus.php");
      const result = await response.json();
      
      if (result.success) {
        const menu = result.data.find(m => m.id == menuId);
        const subcategories = menu ? (menu.subcategories || []) : [];
        
        if (subcategories.length === 0) {
          subcategoriesList.innerHTML = `
            <div id="noSubcategoriesMsg" style="text-align: center; padding: 1.5rem; color: #9ca3af; background: #f9fafb; border-radius: 8px; font-size: 0.9rem;">
              <span class="material-symbols-rounded" style="font-size: 1.5rem; display: block; margin-bottom: 0.25rem;">folder_off</span>
              No subcategories yet. Click "Add Subcategory" to create one.
            </div>
          `;
        } else {
          subcategoriesList.innerHTML = subcategories.map(sub => `
            <div class="subcategory-item" data-sub-id="${sub.id}" style="display: flex; align-items: center; justify-content: space-between; padding: 0.625rem 0.75rem; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; transition: all 0.2s;">
              <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span class="material-symbols-rounded" style="font-size: 1.1rem; color: #6b7280;">subdirectory_arrow_right</span>
                <span style="font-weight: 500; color: #374151; font-size: 0.9rem;">${escapeHtml(sub.subcategory_name_translated || sub.subcategory_name)}</span>
              </div>
              <div style="display: flex; gap: 0.25rem;">
                <button type="button" onclick="editSubcategory(${sub.id}, '${escapeHtml(sub.subcategory_name_translated || sub.subcategory_name)}', ${menuId})" style="padding: 4px 8px; background: transparent; color: #6b7280; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85rem;" title="Edit">
                  <span class="material-symbols-rounded" style="font-size: 1.1rem;">edit</span>
                </button>
                <button type="button" onclick="deleteSubcategory(${sub.id}, '${escapeHtml(sub.subcategory_name_translated || sub.subcategory_name)}')" style="padding: 4px 8px; background: transparent; color: #ef4444; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85rem;" title="Delete">
                  <span class="material-symbols-rounded" style="font-size: 1.1rem;">delete</span>
                </button>
              </div>
            </div>
          `).join("");
        }
      }
    } catch (error) {
      console.error("Error loading subcategories:", error);
    }
  }
  
  // Show subcategories section (called when editing a menu)
  function showSubcategoriesSection() {
    const section = document.getElementById("subcategoriesSection");
    if (section) {
      section.style.display = "block";
    }
  }
  
  // Hide subcategories section (called when creating new menu)
  function hideSubcategoriesSection() {
    const section = document.getElementById("subcategoriesSection");
    if (section) {
      section.style.display = "none";
    }
  }
  
  // Open subcategory modal
  function openSubcategoryModal(menuId, editId, editName) {
    const modal = document.getElementById("subcategoryModal");
    const title = document.getElementById("subcategoryModalTitle");
    const idInput = document.getElementById("subcategoryId");
    const menuIdInput = document.getElementById("subcategoryMenuId");
    const nameInput = document.getElementById("subcategoryName");
    const saveBtn = document.getElementById("subcategorySaveBtn");
    
    if (!modal) return;
    
    if (editId) {
      title.textContent = "Edit Subcategory";
      idInput.value = editId;
      menuIdInput.value = menuId;
      nameInput.value = editName;
      saveBtn.textContent = "Update Subcategory";
    } else {
      title.textContent = "Add Subcategory";
      idInput.value = "";
      menuIdInput.value = menuId;
      nameInput.value = "";
      saveBtn.textContent = "Save Subcategory";
    }
    
    modal.style.display = "block";
    document.body.style.overflow = "hidden";
    
    setTimeout(() => {
      nameInput.focus();
      if (editName) nameInput.select();
    }, 150);
  }
  
  // Close subcategory modal
  window.closeSubcategoryModal = function() {
    const modal = document.getElementById("subcategoryModal");
    if (modal) {
      modal.style.display = "none";
      document.body.style.overflow = "auto";
    }
  };
  
  // Save subcategory (add/update)
  window.saveSubcategory = async function(event) {
    event.preventDefault();
    
    const id = document.getElementById("subcategoryId").value;
    const menuId = document.getElementById("subcategoryMenuId").value;
    const name = document.getElementById("subcategoryName").value.trim();
    const saveBtn = document.getElementById("subcategorySaveBtn");
    const isEdit = id !== "";
    
    if (!name) {
      showMessage("Please enter a subcategory name.", "error");
      return;
    }
    
    saveBtn.disabled = true;
    saveBtn.textContent = isEdit ? "Updating..." : "Saving...";
    
    try {
      const formData = new FormData();
      formData.append("action", isEdit ? "update_subcategory" : "add_subcategory");
      formData.append("subcategoryName", name);
      formData.append("menuId", menuId);
      if (isEdit) {
        formData.append("subcategoryId", id);
      }
      
      const response = await fetch("menu_operations.php", {
        method: "POST",
        body: formData
      });
      
      const result = await response.json();
      
      if (result.success) {
        showMessage(result.message, "success");
        setTimeout(() => {
          window.closeSubcategoryModal();
          loadSubcategories(menuId);
        }, 1000);
      } else {
        showMessage(result.message || "Error processing request.", "error");
      }
    } catch (error) {
      console.error("Error:", error);
      showMessage("Network error. Please try again.", "error");
    } finally {
      saveBtn.disabled = false;
      saveBtn.textContent = isEdit ? "Update Subcategory" : "Save Subcategory";
    }
  };
  
  // Edit subcategory
  window.editSubcategory = function(id, name, menuId) {
    openSubcategoryModal(menuId, id, name);
  };
  
  // Delete subcategory
  window.deleteSubcategory = async function(id, name) {
    const confirmed = await showSweetConfirm(`Are you sure you want to delete subcategory "${name}"? Items assigned to this subcategory will be unassigned.`);
    if (!confirmed) return;
    
    try {
      const formData = new FormData();
      formData.append("action", "delete_subcategory");
      formData.append("subcategoryId", id);
      
      const response = await fetch("menu_operations.php", {
        method: "POST",
        body: formData
      });
      
      const result = await response.json();
      
      if (result.success) {
        showNotification("Subcategory deleted successfully!", "success");
        const menuId = document.getElementById("subcategoryMenuId").value;
        if (menuId) loadSubcategories(menuId);
      } else {
        showNotification(result.message || "Error deleting subcategory.", "error");
      }
    } catch (error) {
      console.error("Error:", error);
      showNotification("Network error. Please try again.", "error");
    }
  };
  
  // Load subcategories for the menu item form dropdown
  async function loadSubcategoryDropdown(menuId, selectedValue) {
    const dropdown = document.getElementById("subcategoryDropdown");
    const formGroup = document.getElementById("subcategoryFormGroup");
    if (!dropdown || !formGroup) return;
    
    if (!menuId) {
      formGroup.style.display = "none";
      return;
    }
    
    try {
      const response = await fetch("get_menus.php");
      const result = await response.json();
      
      if (result.success) {
        const menu = result.data.find(m => m.id == menuId);
        const subcategories = menu ? (menu.subcategories || []) : [];
        
        if (subcategories.length === 0) {
          formGroup.style.display = "none";
          return;
        }
        
        dropdown.innerHTML = '<option value="">None</option>';
        subcategories.forEach(sub => {
          const opt = document.createElement("option");
          opt.value = sub.id;
          opt.textContent = sub.subcategory_name_translated || sub.subcategory_name;
          dropdown.appendChild(opt);
        });
        
        formGroup.style.display = "block";\n\n        // Set selected value if provided (e.g., when editing)\n        if (selectedValue !== undefined && selectedValue !== null) {\n          dropdown.value = selectedValue;\n        }
      }
    } catch (error) {
      console.error("Error loading subcategory dropdown:", error);
    }
  }
  
  // Update subcategory filter based on selected menu (for menu items page)
  function updateSubcategoryFilter() {
    const menuFilter = document.getElementById("menuFilter");
    const subcategoryFilter = document.getElementById("subcategoryFilter");
    if (!menuFilter || !subcategoryFilter) return;
    
    const menuId = menuFilter.value;
    
    if (!menuId) {
      subcategoryFilter.style.display = "none";
      subcategoryFilter.innerHTML = '<option value="">All Subcategories</option>';
      return;
    }
    
    // Fetch menus with subcategories
    fetch("get_menus.php")
      .then(r => r.json())
      .then(result => {
        if (result.success) {
          const menu = result.data.find(m => m.id == menuId);
          const subcategories = menu ? (menu.subcategories || []) : [];
          
          if (subcategories.length === 0) {
            subcategoryFilter.style.display = "none";
            return;
          }
          
          subcategoryFilter.style.display = "inline-block";
          subcategoryFilter.innerHTML = '<option value="">All Subcategories</option>';
          subcategories.forEach(sub => {
            const opt = document.createElement("option");
            opt.value = sub.id;
            opt.textContent = sub.subcategory_name_translated || sub.subcategory_name;
            subcategoryFilter.appendChild(opt);
          });
        }
      })
      .catch(err => console.error("Error updating subcategory filter:", err));
  }
  

  // Menu Items functionality
  const menuItemModal = document.getElementById("menuItemModal");
  const menuItemForm = document.getElementById("menuItemForm");
  const addMenuItemBtn = document.getElementById("addMenuItemBtn");
  const menuItemModalTitle = document.getElementById("menuItemModalTitle");
  const menuItemIdInput = document.getElementById("menuItemId");
  const menuItemSaveBtn = document.getElementById("menuItemSaveBtn");
  const menuItemCancelBtn = document.getElementById("menuItemCancelBtn");
  
  let currentMenuItemId = null;
  let currentMenuItemData = null;

  // Open modal for adding new menu item
  addMenuItemBtn.addEventListener("click", (e) => {
    e.preventDefault();
    openMenuItemModal();
  });

  // Open modal for editing existing menu item
  window.editMenuItem = function(menuItemId, menuItemData) {
    currentMenuItemId = menuItemId;
    currentMenuItemData = menuItemData;
    openMenuItemModal(true);
  };

  function openMenuItemModal(isEdit = false) {
    console.log("Opening menu item modal, isEdit:", isEdit);

    // Reset image state for fresh edit/add session
    const base64Input = document.getElementById('itemImageBase64');
    if (base64Input) {
      base64Input.value = '';
    }
    const imagePreviewReset = document.getElementById('imagePreview');
    if (imagePreviewReset) {
      imagePreviewReset.style.display = 'none';
    }

    menuItemModal.style.display = "block";
    document.body.style.overflow = "hidden";
    
    // Load menus for dropdown first
    loadMenusForDropdown().then(() => {
      if (isEdit) {
        menuItemModalTitle.textContent = "Edit Menu Item";
        populateMenuItemForm(currentMenuItemData);
        menuItemSaveBtn.textContent = "Update Menu Item";
      } else {
        menuItemModalTitle.textContent = "Add New Menu Item";
        menuItemForm.reset();
        menuItemIdInput.value = "";
        menuItemSaveBtn.textContent = "Save Menu Item";
        
        // Reset item type buttons - no default selection
        document.querySelectorAll('.type-btn').forEach(btn => btn.classList.remove('active'));
        document.getElementById('itemType').value = '';
        
        // Reset checkbox
        const hasVariationsCheckbox = document.getElementById("hasVariations");
        if (hasVariationsCheckbox) {
          hasVariationsCheckbox.checked = false;
        }
        
        // Hide image preview
        const imagePreview = document.getElementById('imagePreview');
        if (imagePreview) {
          imagePreview.style.display = 'none';
        }
        
        // Reset file input
        const fileInput = document.getElementById('itemImage');
        if (fileInput) {
          fileInput.value = '';
        }
        
        // Reset base64 data
        const base64Input = document.getElementById('itemImageBase64');
        if (base64Input) {
          base64Input.value = '';
        }
        
        // Reset file name display
        const fileNameSpan = document.querySelector('.file-name');
        if (fileNameSpan) {
          fileNameSpan.textContent = 'No file chosen';
        }
      }
      
      // Clear any existing messages
      const existingMessage = document.querySelector("#menuItemModal .message");
      if (existingMessage) {
        existingMessage.remove();
      }
      
      // Focus the first input field
      setTimeout(() => {
        const firstInput = document.getElementById("itemNameEn");
        if (firstInput) {
          firstInput.focus();
        }
      }, 150);
    });
  }

  function populateMenuItemForm(data) {
    console.log("Populating form with data:", data);
    
    menuItemIdInput.value = data.id;
    document.getElementById("itemNameEn").value = data.item_name_en || '';
    
    // Set menu selection with debugging
    const menuSelect = document.getElementById("chooseMenu");
    console.log("Setting menu_id:", data.menu_id, "Available options:", menuSelect.options.length);
    menuSelect.value = data.menu_id || '';
    console.log("Menu select value after setting:", menuSelect.value);
    
    document.getElementById("itemDescriptionEn").value = data.item_description_en || '';
    // Subcategory: load and set based on selected menu\n    if (data.menu_id) {\n      loadSubcategoryDropdown(data.menu_id, data.subcategory_id);\n    }
    document.getElementById("preparationTime").value = data.preparation_time || 0;
    document.getElementById("isAvailable").value = data.is_available ? '1' : '0';
    document.getElementById("basePrice").value = data.base_price || '0.00';
    
    // Fix checkbox handling
    const hasVariationsCheckbox = document.getElementById("hasVariations");
    if (hasVariationsCheckbox) {
      hasVariationsCheckbox.checked = data.has_variations == 1 || data.has_variations === true;
    }
    
    // Set item type
    document.querySelectorAll('.type-btn').forEach(btn => btn.classList.remove('active'));
    const typeBtn = document.querySelector(`.type-btn[data-type="${data.item_type}"]`);
    if (typeBtn) {
      typeBtn.classList.add('active');
      document.getElementById('itemType').value = data.item_type;
    }
    
    // Show existing image if available, otherwise hide preview
    const imagePreview = document.getElementById('imagePreview');
    const previewImg = document.getElementById('previewImg');
    if (data.item_image) {
      if (imagePreview && previewImg) {
        previewImg.src = `image.php?path=${encodeURIComponent(data.item_image)}`;
        imagePreview.style.display = 'block';
      }
    } else {
      if (imagePreview) {
        imagePreview.style.display = 'none';
      }
      if (previewImg) {
        previewImg.src = '';
      }
    }
  }

  // Close menu item modal
  function closeMenuItemModal() {
    menuItemModal.style.display = "none";
    document.body.style.overflow = "auto";
    menuItemForm.reset();
    currentMenuItemId = null;
    currentMenuItemData = null;
    
    // Reset image state
    const base64Input = document.getElementById('itemImageBase64');
    if (base64Input) {
      base64Input.value = '';
    }
    const imagePreview = document.getElementById('imagePreview');
    const previewImg = document.getElementById('previewImg');
    if (imagePreview) {
      imagePreview.style.display = 'none';
    }
    if (previewImg) {
      previewImg.src = '';
    }
    var fileNameSpan = document.querySelector('.file-name');
    if (fileNameSpan) {
      fileNameSpan.textContent = 'No file chosen';
    }
    
    // Clear any existing messages
    const existingMessage = document.querySelector(".message");
    if (existingMessage) {
      existingMessage.remove();
    }
  }


  // Item type button functionality
  document.querySelectorAll('.type-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('active'));
      this.classList.add('active');
      document.getElementById('itemType').value = this.dataset.type;
    });
  });

  // File upload preview with base64 conversion
  document.getElementById('itemImage').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const fileNameSpan = document.querySelector('.file-name');
    const imagePreview = document.getElementById('imagePreview');
    const previewImg = document.getElementById('previewImg');
    
    if (file) {
      fileNameSpan.textContent = file.name;
      
      // Show preview and convert to base64
      const reader = new FileReader();
      reader.onload = function(e) {
        previewImg.src = e.target.result;
        imagePreview.style.display = 'block';
        
        // Store base64 data for form submission
        document.getElementById('itemImageBase64').value = e.target.result;
      };
      reader.readAsDataURL(file);
    } else {
      fileNameSpan.textContent = 'No file chosen';
      imagePreview.style.display = 'none';
      document.getElementById('itemImageBase64').value = '';
    }
  });

  // Handle menu item form submission
  menuItemForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    
    const formData = new FormData(menuItemForm);
    const isEdit = menuItemIdInput.value !== "";
    
    formData.append('action', isEdit ? 'update' : 'add');
    // Add subcategory ID if available
    const subcatDropdown = document.getElementById('subcategoryDropdown');
    if (subcatDropdown && subcatDropdown.value) {
      formData.append('subcategoryId', subcatDropdown.value);
    }
    
    // Disable save button and show loading
    menuItemSaveBtn.disabled = true;
    menuItemSaveBtn.textContent = isEdit ? "Updating..." : "Saving...";

    try {
      const response = await fetch("menu_items_operations_base64.php", {
        method: "POST",
        body: formData
      });

      const result = await response.json();

      if (result.success) {
        showMenuItemMessage(result.message, "success");
        setTimeout(() => {
          closeMenuItemModal();
          loadMenuItems(); // Refresh the menu items list
        }, 1500);
      } else {
        showMenuItemMessage(result.message || "Error processing request. Please try again.", "error");
      }
    } catch (error) {
      console.error("Error:", error);
      showMenuItemMessage("Network error. Please check your connection and try again.", "error");
    } finally {
      // Re-enable save button
      menuItemSaveBtn.disabled = false;
      menuItemSaveBtn.textContent = isEdit ? "Update Menu Item" : "Save Menu Item";
    }
  });

  // Load menus for dropdown
  async function loadMenusForDropdown() {
    try {
      const response = await fetch("get_menus.php");
      const result = await response.json();
      
      if (result.success) {
        const menuSelect = document.getElementById("chooseMenu");
        if (!menuSelect) {
          console.error("Menu select element not found");
          return;
        }
        
        menuSelect.innerHTML = '<option value="">--</option>';
        
        result.data.forEach(menu => {
          const option = document.createElement('option');
          option.value = menu.id;
          option.textContent = menu.menu_name_translated || menu.menu_name;
          menuSelect.appendChild(option);
        });
        
        console.log("Menus loaded for dropdown:", result.data.length);
      } else {
        console.error("Failed to load menus:", result.message);
      }
    } catch (error) {
      console.error("Error loading menus:", error);
    }
  }

  // Load menus for filter dropdown
  async function loadMenusForFilter() {
    try {
      const response = await fetch("get_menus.php");
      const result = await response.json();
      
      if (result.success) {
        const menuFilter = document.getElementById("menuFilter");
        menuFilter.innerHTML = '<option value="">All Menus</option>';
        
        result.data.forEach(menu => {
          const option = document.createElement('option');
          option.value = menu.id;
          option.textContent = menu.menu_name_translated || menu.menu_name;
          menuFilter.appendChild(option);
        });
      }
    } catch (error) {
      console.error("Error loading menus for filter:", error);
    }
  }

  // Load menu items
  async function loadMenuItems() {
    const menuItemsList = document.getElementById("menuItemsList");
    menuItemsList.innerHTML = '<div class="loading">Loading menu items...</div>';

    try {
      const menuFilter = document.getElementById("menuFilter").value;
      const categoryFilter = document.getElementById("categoryFilter").value;
      const typeFilter = document.getElementById("typeFilter").value;
      
      const params = new URLSearchParams();
      if (menuFilter && menuFilter !== '') params.append('menu', menuFilter);
      if (categoryFilter && categoryFilter !== '') params.append('category', categoryFilter);
      if (typeFilter && typeFilter !== '') params.append('type', typeFilter);
      
      // Add subcategory filter
      const subcategoryFilter = document.getElementById("subcategoryFilter")?.value || '';
      if (subcategoryFilter && subcategoryFilter !== '') params.append('subcategory', subcategoryFilter);
      
      const response = await fetch(`get_menu_items.php?${params}`);
      const result = await response.json();

      if (result.success) {
        displayMenuItems(result.data);
        updateCategoryFilter(result.categories);
      } else {
        menuItemsList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Error loading menu items</h3><p>Please try again later.</p></div>';
      }
    } catch (error) {
      console.error("Error loading menu items:", error);
      menuItemsList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Network Error</h3><p>Please check your connection and try again.</p></div>';
    }
  }

  function displayMenuItems(menuItems) {
    const menuItemsList = document.getElementById("menuItemsList");
    
    if (menuItems.length === 0) {
      menuItemsList.innerHTML = `
        <div class="empty-state">
          <span class="material-symbols-rounded">restaurant_menu</span>
          <h3>No menu items found</h3>
          <p>Create your first menu item to get started.</p>
        </div>
      `;
      return;
    }

    menuItemsList.innerHTML = menuItems.map(item => `
      <div class="menu-item-card" data-item-id="${item.id}">
        <div class="item-image">
          ${item.item_image ? `<img src="image.php?path=${encodeURIComponent(item.item_image)}" alt="${escapeHtml(item.item_name_translated || item.item_name_en)}" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <div class="no-image" style="display:none;"><span class="material-symbols-rounded">image</span></div>` : '<div class="no-image"><span class="material-symbols-rounded">image</span></div>'}
        </div>
        <div class="item-details">
          <h3>${escapeHtml(item.item_name_translated || item.item_name_en)}</h3>
          <p class="item-description">${item.description_format === 'br' ? escapeHtml(item.item_description_en || 'No description').replace(/\n/g, '<br>') : escapeHtml(item.item_description_en || 'No description')}</p>
          <div class="item-meta">
            <span class="item-category">${escapeHtml(item.item_category || 'Uncategorized')}</span>
            <span class="item-type ${item.item_type.toLowerCase().replace(' ', '-')}">${item.item_type}</span>
            <span class="item-price">${formatCurrency(item.base_price)}</span>
          </div>
          <div class="item-info">
            <span class="menu-name">${escapeHtml(item.menu_name_translated || item.menu_name)}</span>
            <span class="prep-time">${item.preparation_time} min</span>
            <span class="availability ${item.is_available ? 'available' : 'unavailable'}">${item.is_available ? 'In Stock' : 'Out of Stock'}</span>
          </div>
        </div>
        <div class="item-actions">
          <button class="btn-edit" onclick="editMenuItem(${item.id}, ${JSON.stringify(item).replace(/"/g, '&quot;')})">
            <span class="material-symbols-rounded">edit</span>
            Edit
          </button>
          <button class="btn-delete" onclick="deleteMenuItem(${item.id}, '${escapeHtml(item.item_name_translated || item.item_name_en)}')">
            <span class="material-symbols-rounded">delete</span>
            Delete
          </button>
        </div>
      </div>
    `).join('');
  }

  function updateCategoryFilter(categories) {
    const categoryFilter = document.getElementById("categoryFilter");
    categoryFilter.innerHTML = '<option value="">All Categories</option>';
    
    categories.forEach(category => {
      const option = document.createElement('option');
      option.value = category;
      option.textContent = category;
      categoryFilter.appendChild(option);
    });
  }

  // Delete menu item function
  window.deleteMenuItem = function(menuItemId, menuItemName) {
    currentMenuItemId = menuItemId;
    currentMenuItemData = { item_name_en: menuItemName };
    
    document.getElementById("deleteMessage").textContent = 
      `Are you sure you want to delete "${menuItemName}"? This action cannot be undone.`;
    
    deleteModal.style.display = "block";
    document.body.style.overflow = "hidden";
  };

  // Handle delete confirmation for menu items
  document.getElementById("deleteConfirmBtn").addEventListener("click", async () => {
    if (!currentMenuItemId) return;
    
    const deleteBtn = document.getElementById("deleteConfirmBtn");
    deleteBtn.disabled = true;
    deleteBtn.textContent = "Deleting...";

    try {
      const formData = new URLSearchParams();
      formData.append('action', 'delete');
      formData.append('menuItemId', currentMenuItemId);

      const response = await fetch("menu_items_operations_base64.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: formData
      });

      const result = await response.json();

      if (result.success) {
        showNotification("Menu item deleted successfully!", "success");
        closeDeleteModal();
        loadMenuItems();
      } else {
        showNotification(result.message || "Error deleting menu item.", "error");
      }
    } catch (error) {
      console.error("Error deleting menu item:", error);
      showNotification("Network error. Please try again.", "error");
    } finally {
      deleteBtn.disabled = false;
      deleteBtn.textContent = "Delete";
    }
  });

  // Filter functionality
  const menuFilterEl = document.getElementById("menuFilter");
  const categoryFilterEl = document.getElementById("categoryFilter");
  const typeFilterEl = document.getElementById("typeFilter");
  
  if (menuFilterEl) menuFilterEl.addEventListener("change", function() { updateSubcategoryFilter(); loadMenuItems(); });
  if (categoryFilterEl) categoryFilterEl.addEventListener("change", loadMenuItems);
  if (typeFilterEl) typeFilterEl.addEventListener("change", loadMenuItems);
  
  // Subcategory filter listener
  const subcategoryFilterEl = document.getElementById("subcategoryFilter");
  if (subcategoryFilterEl) {
    subcategoryFilterEl.addEventListener("change", loadMenuItems);
  }
  
  // Area functionality
  const areaModal = document.getElementById("areaModal");
  const areaForm = document.getElementById("areaForm");
  const addAreaBtn = document.getElementById("addAreaBtn");
  const areaModalTitle = document.getElementById("areaModalTitle");
  const areaIdInput = document.getElementById("areaId");
  const areaNameInput = document.getElementById("areaName");
  const areaSaveBtn = document.getElementById("areaSaveBtn");
  const areaCancelBtn = document.getElementById("areaCancelBtn");
  
  let currentAreaId = null;
  let currentAreaName = null;
  
  // Open area modal
  if (addAreaBtn) {
    addAreaBtn.addEventListener("click", () => {
      currentAreaId = null;
      currentAreaName = null;
      openAreaModal(false);
    });
  }
  
  function openAreaModal(isEdit = false) {
    if (isEdit) {
      areaModalTitle.textContent = "Edit Area";
      areaIdInput.value = currentAreaId;
      areaNameInput.value = currentAreaName;
      areaSaveBtn.textContent = "Update Area";
    } else {
      areaModalTitle.textContent = "Add New Area";
      areaIdInput.value = "";
      areaNameInput.value = "";
      areaSaveBtn.textContent = "Save Area";
    }
    
    areaModal.style.display = "block";
    document.body.style.overflow = "hidden";
    
    setTimeout(() => {
      areaNameInput.focus();
      areaNameInput.select();
    }, 150);
  }
  
  function closeAreaModal() {
    areaModal.style.display = "none";
    document.body.style.overflow = "auto";
    areaForm.reset();
    currentAreaId = null;
    currentAreaName = null;
  }
  
  if (areaCancelBtn) {
    areaCancelBtn.addEventListener("click", closeAreaModal);
  }
  
  // Close area modal when clicking outside
  window.addEventListener("click", (e) => {
    if (e.target === areaModal) {
      closeAreaModal();
    }
  });
  
  // Handle area form submission
  if (areaForm) {
    areaForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      
      const areaName = areaNameInput.value.trim();
      const areaId = areaIdInput.value;
      const isEdit = areaId !== "";
      
      if (!areaName) {
        showMessage("Please enter an area name.", "error");
        return;
      }

      // Disable save button and show loading
      areaSaveBtn.disabled = true;
      areaSaveBtn.textContent = isEdit ? "Updating..." : "Saving...";

      try {
        const formData = new URLSearchParams();
        formData.append('action', isEdit ? 'update' : 'add');
    // Add subcategory ID if available
    const subcatDropdown = document.getElementById('subcategoryDropdown');
    if (subcatDropdown && subcatDropdown.value) {
      formData.append('subcategoryId', subcatDropdown.value);
    }
        formData.append('areaName', areaName);
        if (isEdit) {
          formData.append('areaId', areaId);
        }

        const response = await fetch("area_operations.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
          },
          body: formData
        });

        const result = await response.json();

        if (result.success) {
          showMessage(result.message, "success");
          setTimeout(() => {
            closeAreaModal();
            loadAreas(); // Refresh the area list
          }, 1500);
        } else {
          showMessage(result.message || "Error processing request. Please try again.", "error");
        }
        
      } catch (error) {
        console.error("Error:", error);
        showNotification("Network error. Please check your connection and try again.", "error");
      } finally {
        // Re-enable save button
        areaSaveBtn.disabled = false;
        areaSaveBtn.textContent = isEdit ? "Update Area" : "Save Area";
      }
    });
  }
  
  // Load areas
  async function loadAreas() {
    const areaList = document.getElementById("areaList");
    areaList.innerHTML = '<div class="loading">Loading areas...</div>';

    try {
      const response = await fetch("get_areas.php");
      const result = await response.json();

      if (result.success) {
        displayAreas(result.data);
      } else {
        areaList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Error loading areas</h3><p>Please try again later.</p></div>';
      }
    } catch (error) {
      console.error("Error loading areas:", error);
      areaList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Network Error</h3><p>Please check your connection and try again.</p></div>';
    }
  }
  
  function displayAreas(areas) {
    const areaList = document.getElementById("areaList");
    
    if (areas.length === 0) {
      areaList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">inbox</span><h3>No areas found</h3><p>Create your first area to get started.</p></div>';
      return;
    }

    areaList.innerHTML = areas.map(area => `
      <div class="menu-card" data-area-id="${area.id}">
        <div class="menu-card-header">
          <h3>${escapeHtml(area.area_name)}</h3>
          <div class="menu-card-actions">
            <button class="btn-edit" onclick="editArea(${area.id}, '${escapeHtml(area.area_name)}')">
              <span class="material-symbols-rounded">edit</span>
            </button>
            <button class="btn-delete" onclick="deleteArea(${area.id}, '${escapeHtml(area.area_name)}')">
              <span class="material-symbols-rounded">delete</span>
            </button>
          </div>
        </div>
        <div class="menu-card-footer">
          <span class="menu-date">Tables: ${area.no_of_tables} | Created: ${formatDate(area.created_at)}</span>
        </div>
      </div>
    `).join('');
  }
  
  // Make functions globally accessible
  window.editArea = function(areaId, areaName) {
    currentAreaId = areaId;
    currentAreaName = areaName;
    openAreaModal(true);
  };
  
  window.deleteArea = async function(areaId, areaName) {
    if (await showSweetConfirm(`Are you sure you want to delete "${areaName}"? This action cannot be undone.`)) {
      // Call delete API
      const formData = new URLSearchParams();
      formData.append('action', 'delete');
      formData.append('areaId', areaId);
      
      fetch("area_operations.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: formData
      })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          showMessage(result.message, "success");
          loadAreas();
        } else {
          showMessage(result.message || "Error deleting area.", "error");
        }
      })
      .catch(error => {
        console.error("Error:", error);
        showNotification("Network error. Please check your connection and try again.", "error");
      });
    }
  };
  
  // Table functionality
  const tableModal = document.getElementById("tableModal");
  const tableForm = document.getElementById("tableForm");
  const addTableBtn = document.getElementById("addTableBtn");
  const tableModalTitle = document.getElementById("tableModalTitle");
  const tableIdInput = document.getElementById("tableId");
  const tableNumberInput = document.getElementById("tableNumber");
  const capacityInput = document.getElementById("capacity");
  const chooseAreaSelect = document.getElementById("chooseArea");
  const tableSaveBtn = document.getElementById("tableSaveBtn");
  const tableCancelBtn = document.getElementById("tableCancelBtn");
  
  let currentTableId = null;
  let currentTableNumber = null;
  let currentCapacity = null;
  let currentTableAreaId = null;
  
  // Open table modal
  if (addTableBtn) {
    addTableBtn.addEventListener("click", () => {
      currentTableId = null;
      currentTableNumber = null;
      currentCapacity = null;
      currentTableAreaId = null;
      openTableModal(false);
    });
  }
  
  function openTableModal(isEdit = false) {
    // Load areas for dropdown first
    loadAreasForDropdown().then(() => {
      if (isEdit) {
        tableModalTitle.textContent = "Edit Table";
        tableIdInput.value = currentTableId;
        tableNumberInput.value = currentTableNumber;
        capacityInput.value = currentCapacity;
        chooseAreaSelect.value = currentTableAreaId;
        tableSaveBtn.textContent = "Update Table";
      } else {
        tableModalTitle.textContent = "Add New Table";
        tableIdInput.value = "";
        tableNumberInput.value = "";
        capacityInput.value = 4;
        chooseAreaSelect.value = "";
        tableSaveBtn.textContent = "Save Table";
      }
      
      tableModal.style.display = "block";
      document.body.style.overflow = "hidden";
      
      setTimeout(() => {
        tableNumberInput.focus();
        tableNumberInput.select();
      }, 150);
    });
  }
  
  function closeTableModal() {
    tableModal.style.display = "none";
    document.body.style.overflow = "auto";
    tableForm.reset();
    currentTableId = null;
    currentTableNumber = null;
    currentCapacity = null;
    currentTableAreaId = null;
  }
  
  if (tableCancelBtn) {
    tableCancelBtn.addEventListener("click", closeTableModal);
  }
  
  // Close table modal when clicking outside
  window.addEventListener("click", (e) => {
    if (e.target === tableModal) {
      closeTableModal();
    }
  });
  
  // Load areas for dropdown
  async function loadAreasForDropdown() {
    try {
      const response = await fetch("get_areas.php");
      const result = await response.json();
      
      if (result.success) {
        chooseAreaSelect.innerHTML = '<option value="">--</option>';
        
        result.data.forEach(area => {
          const option = document.createElement('option');
          option.value = area.id;
          option.textContent = area.area_name;
          chooseAreaSelect.appendChild(option);
        });
        
        console.log("Areas loaded for dropdown:", result.data.length);
      } else {
        console.error("Failed to load areas:", result.message);
      }
    } catch (error) {
      console.error("Error loading areas for dropdown:", error);
    }
  }
  
  // Handle table form submission
  if (tableForm) {
    tableForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      
      const tableNumber = tableNumberInput.value.trim();
      const capacity = parseInt(capacityInput.value) || 4;
      const areaId = chooseAreaSelect.value;
      const tableId = tableIdInput.value;
      const isEdit = tableId !== "";
      
      if (!tableNumber) {
        showMessage("Please enter a table number.", "error");
        return;
      }
      
      if (parseInt(areaId) <= 0) {
        showMessage("Please select an area.", "error");
        return;
      }

      // Disable save button and show loading
      tableSaveBtn.disabled = true;
      tableSaveBtn.textContent = isEdit ? "Updating..." : "Saving...";

      try {
        const formData = new URLSearchParams();
        formData.append('action', isEdit ? 'update' : 'add');
    // Add subcategory ID if available
    const subcatDropdown = document.getElementById('subcategoryDropdown');
    if (subcatDropdown && subcatDropdown.value) {
      formData.append('subcategoryId', subcatDropdown.value);
    }
        formData.append('tableNumber', tableNumber);
        formData.append('capacity', capacity);
        formData.append('chooseArea', areaId);
        if (isEdit) {
          formData.append('tableId', tableId);
        }

        const response = await fetch("table_operations.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
          },
          body: formData
        });

        const result = await response.json();

        if (result.success) {
          showMessage(result.message, "success");
          setTimeout(() => {
            closeTableModal();
            loadTables(); // Refresh the table list
          }, 1500);
        } else {
          showMessage(result.message || "Error processing request. Please try again.", "error");
        }
        
      } catch (error) {
        console.error("Error:", error);
        showNotification("Network error. Please check your connection and try again.", "error");
      } finally {
        // Re-enable save button
        tableSaveBtn.disabled = false;
        tableSaveBtn.textContent = isEdit ? "Update Table" : "Save Table";
      }
    });
  }
  
  // Load tables
  async function loadTables() {
    const tableList = document.getElementById("tableList");
    tableList.innerHTML = '<div class="loading">Loading tables...</div>';

    try {
      const response = await fetch("get_tables.php");
      const result = await response.json();

      if (result.success) {
        displayTables(result.data);
      } else {
        tableList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Error loading tables</h3><p>Please try again later.</p></div>';
      }
    } catch (error) {
      console.error("Error loading tables:", error);
      tableList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Network Error</h3><p>Please check your connection and try again.</p></div>';
    }
  }
  
  // Load QR Codes
  async function loadQRCodes() {
    const qrGrid = document.getElementById("qrCodesGrid");
    qrGrid.innerHTML = '<div class="loading">Generating QR codes...</div>';

    try {
      const response = await fetch("get_tables.php");
      const result = await response.json();

      if (result.success) {
        const tables = result.data || result.tables || [];
        displayQRCodes(tables);
      } else {
        qrGrid.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Error loading tables</h3><p>Please try again later.</p></div>';
      }
    } catch (error) {
      console.error("Error loading QR codes:", error);
      qrGrid.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Error</h3><p>Failed to load tables.</p></div>';
    }
  }
  
  // Display QR Codes
  function displayQRCodes(tables) {
    const qrGrid = document.getElementById("qrCodesGrid");
    
    if (tables.length === 0) {
      qrGrid.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">qr_code_2</span><h3>No Tables Found</h3><p>Add tables to generate QR codes.</p></div>';
      return;
    }
    
    const baseUrl = window.location.origin + '/menu/website/index.php';
    
    qrGrid.innerHTML = tables.map(table => {
      const tableUrl = `${baseUrl}?table=${encodeURIComponent(table.table_number)}`;
      const qrCodeUrl = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(tableUrl)}`;
      
      return `
        <div class="qr-code-card">
          <div class="qr-code-image">
            <img src="${qrCodeUrl}" alt="QR Code for ${escapeHtml(table.table_number)}">
          </div>
          <div class="qr-code-info">
            <div class="qr-code-table-name">${escapeHtml(table.table_number)}</div>
            <div class="qr-code-area">${escapeHtml(table.area_name)}</div>
            <div class="qr-code-actions">
              <button class="qr-download-btn" onclick="downloadQRCode('${qrCodeUrl}', '${escapeHtml(table.table_number)}')">
                <span class="material-symbols-rounded">download</span>
                Download
              </button>
              <button class="qr-print-btn" onclick="printQRCode('${qrCodeUrl}', '${escapeHtml(table.table_number)}')">
                <span class="material-symbols-rounded">print</span>
                Print
              </button>
            </div>
          </div>
        </div>
      `;
    }).join('');
  }
  
  // Download QR Code
  window.downloadQRCode = function(qrUrl, tableName) {
    const a = document.createElement('a');
    a.href = qrUrl;
    a.download = `QR-${tableName}.png`;
    a.click();
  }
  
  // Print QR Code
  window.printQRCode = function(qrUrl, tableName) {
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
      <!DOCTYPE html>
      <html>
        <head>
          <title>QR Code - ${tableName}</title>
          <meta charset="UTF-8">
          <style>
            @page {
              margin: 0;
              size: A4 landscape;
            }
            
            * {
              margin: 0;
              padding: 0;
              box-sizing: border-box;
            }
            
            html, body {
              width: 100%;
              height: 100%;
              overflow: hidden;
            }
            
            body { 
              font-family: 'Arial', sans-serif;
              background: white;
              display: flex;
              align-items: center;
              justify-content: center;
              padding: 2rem;
            }
            
            .print-container {
              width: 100%;
              max-width: 800px;
              text-align: center;
            }
            
            .qr-title {
              font-size: 2.5rem;
              font-weight: 700;
              color: #151A2D;
              margin-bottom: 0.5rem;
            }
            
            .qr-image {
              width: 250px;
              height: 250px;
              border: 4px solid #151A2D;
              padding: 15px;
              background: white;
              margin: 1rem auto;
              display: flex;
              align-items: center;
              justify-content: center;
            }
            
            .qr-image img {
              width: 100%;
              height: 100%;
              object-fit: contain;
            }
            
            .qr-instructions {
              margin-top: 1rem;
              font-size: 1.2rem;
              color: #333;
              font-weight: 600;
            }
            
            .qr-logo {
              font-size: 3rem;
              margin-bottom: 0.5rem;
            }
            
            .qr-subtitle {
              font-size: 0.95rem;
              color: #666;
              margin-top: 0.5rem;
            }
            
            @media print {
              @page {
                size: A4 landscape;
                margin: 15mm;
              }
              
              html, body {
                height: 100%;
                overflow: visible;
              }
              
              .print-container {
                height: auto;
                page-break-inside: avoid;
              }
              
              body {
                padding: 0;
              }
            }
          </style>
        </head>
        <body>
          <div class="print-container">
            <div class="qr-logo">🍽️</div>
            <h1 class="qr-title">Table ${tableName}</h1>
            <div class="qr-image">
              <img src="${qrUrl}" alt="QR Code for Table ${tableName}">
            </div>
            <div class="qr-instructions">
              <p><strong>Scan to view our menu</strong></p>
              <p class="qr-subtitle">Use your phone camera to scan this code</p>
            </div>
          </div>
        </body>
      </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
      printWindow.print();
    }, 250);
  }
  
  // Generate all QR codes
  window.generateAllQRCodes = function() {
    showMessage('QR codes are displayed below. Click Download or Print on each card.', 'success');
  }
  
  function displayTables(tables) {
    const tableList = document.getElementById("tableList");
    
    if (tables.length === 0) {
      tableList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">inbox</span><h3>No tables found</h3><p>Create your first table to get started.</p></div>';
      return;
    }

    tableList.innerHTML = tables.map(table => `
      <div class="menu-card" data-table-id="${table.id}">
        <div class="menu-card-header">
          <h3>${escapeHtml(table.table_number)}</h3>
          <div class="menu-card-actions">
            <button class="btn-edit" onclick="editTable(${table.id}, '${escapeHtml(table.table_number)}', ${table.capacity}, ${table.area_id})">
              <span class="material-symbols-rounded">edit</span>
            </button>
            <button class="btn-delete" onclick="deleteTable(${table.id}, '${escapeHtml(table.table_number)}')">
              <span class="material-symbols-rounded">delete</span>
            </button>
          </div>
        </div>
        <div class="menu-card-footer">
          <span class="menu-date">Capacity: ${table.capacity} | Area: ${escapeHtml(table.area_name)} | Created: ${formatDate(table.created_at)}</span>
        </div>
      </div>
    `).join('');
  }
  
  // Make functions globally accessible
  window.editTable = function(tableId, tableNumber, capacity, areaId) {
    currentTableId = tableId;
    currentTableNumber = tableNumber;
    currentCapacity = capacity;
    currentTableAreaId = areaId;
    loadAreasForDropdown().then(() => {
      openTableModal(true);
    });
  };
  
  window.deleteTable = async function(tableId, tableNumber) {
    if (await showSweetConfirm(`Are you sure you want to delete table "${tableNumber}"? This action cannot be undone.`)) {
      // Call delete API
      const formData = new URLSearchParams();
      formData.append('action', 'delete');
      formData.append('tableId', tableId);
      
      fetch("table_operations.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: formData
      })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          showMessage(result.message, "success");
          loadTables();
        } else {
          showMessage(result.message || "Error deleting table.", "error");
        }
      })
      .catch(error => {
        console.error("Error:", error);
        showNotification("Network error. Please check your connection and try again.", "error");
      });
    }
  };
  
  // Reservation functionality
  const reservationModal = document.getElementById("reservationModal");
  const reservationForm = document.getElementById("reservationForm");
  const addReservationBtn = document.getElementById("addReservationBtn");
  const reservationModalTitle = document.getElementById("reservationModalTitle");
  const reservationIdInput = document.getElementById("reservationId");
  const reservationDateInput = document.getElementById("reservationDate");
  const noOfGuestsInput = document.getElementById("noOfGuests");
  const mealTypeSelect = document.getElementById("mealType");
  const timeSlotsDiv = document.getElementById("timeSlots");
  const specialRequestInput = document.getElementById("specialRequest");
  const customerNameInput = document.getElementById("customerName");
  const phoneInput = document.getElementById("phone");
  const emailInput = document.getElementById("email");
  const selectTableSelect = document.getElementById("selectTable");
  const reservationSaveBtn = document.getElementById("reservationSaveBtn");
  const reservationCancelBtn = document.getElementById("reservationCancelBtn");
  
  let currentReservationId = null;
  let selectedTimeSlot = null;
  
  // Set default date to today
  if (reservationDateInput) {
    const today = new Date().toISOString().split('T')[0];
    reservationDateInput.value = today;
  }
  
  // Open reservation modal
  if (addReservationBtn) {
    addReservationBtn.addEventListener("click", () => {
      currentReservationId = null;
      selectedTimeSlot = null;
      openReservationModal(false);
    });
  }
  
  function openReservationModal(isEdit = false) {
    if (isEdit) {
      reservationModalTitle.textContent = "Edit Reservation";
      reservationSaveBtn.textContent = "Update Reservation";
    } else {
      reservationModalTitle.textContent = "New Reservation";
      reservationForm.reset();
      reservationIdInput.value = "";
      const today = new Date().toISOString().split('T')[0];
      reservationDateInput.value = today;
      noOfGuestsInput.value = 1;
      mealTypeSelect.value = "Lunch";
      selectedTimeSlot = null;
      reservationSaveBtn.textContent = "Reserve Now";
    }
    
    loadTablesForDropdown();
    generateTimeSlots();
    
    reservationModal.style.display = "block";
    document.body.style.overflow = "hidden";
    
    setTimeout(() => {
      customerNameInput.focus();
    }, 150);
  }
  
  function closeReservationModal() {
    reservationModal.style.display = "none";
    document.body.style.overflow = "auto";
    reservationForm.reset();
    currentReservationId = null;
    selectedTimeSlot = null;
  }
  
  if (reservationCancelBtn) {
    reservationCancelBtn.addEventListener("click", closeReservationModal);
  }
  
  // Generate time slots
  function generateTimeSlots() {
    const slots = ['12:00 PM', '01:00 PM', '02:00 PM', '03:00 PM', '04:00 PM', '05:00 PM', '06:00 PM', '07:00 PM', '08:00 PM'];
    
    timeSlotsDiv.innerHTML = slots.map(slot => `
      <button type="button" class="time-slot-btn" data-slot="${slot}">${slot}</button>
    `).join('');
    
    // Add click handlers
    document.querySelectorAll('.time-slot-btn').forEach(btn => {
      btn.addEventListener('click', function() {
        document.querySelectorAll('.time-slot-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        selectedTimeSlot = this.dataset.slot;
      });
    });
    
    // Set previously selected slot if editing
    if (selectedTimeSlot) {
      const btn = document.querySelector(`.time-slot-btn[data-slot="${selectedTimeSlot}"]`);
      if (btn) {
        btn.classList.add('active');
      }
    }
  }
  
  // Load tables for dropdown
  async function loadTablesForDropdown() {
    try {
      const response = await fetch("get_tables.php");
      const result = await response.json();
      
      if (result.success) {
        selectTableSelect.innerHTML = '<option value="">-- Select Table --</option>';
        
        result.data.forEach(table => {
          const option = document.createElement('option');
          option.value = table.id;
          option.textContent = `${table.table_number} - ${table.area_name} (${table.capacity} seats)`;
          selectTableSelect.appendChild(option);
        });
      }
    } catch (error) {
      console.error("Error loading tables for dropdown:", error);
    }
  }
  
  // Handle reservation form submission
  if (reservationForm) {
    reservationForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      
      const reservationDate = reservationDateInput.value;
      const timeSlot = selectedTimeSlot;
      const noOfGuests = parseInt(noOfGuestsInput.value) || 1;
      const mealType = mealTypeSelect.value;
      const customerName = customerNameInput.value.trim();
      const phone = phoneInput.value.trim();
      const email = emailInput.value.trim();
      const specialRequest = specialRequestInput.value.trim();
      const tableId = parseInt(selectTableSelect.value) || null;
      const reservationId = reservationIdInput.value;
      const isEdit = reservationId !== "";
      
      if (!customerName) {
        showMessage("Please enter customer name.", "error");
        return;
      }
      
      if (!phone) {
        showMessage("Please enter phone number.", "error");
        return;
      }
      
      if (!selectedTimeSlot) {
        showMessage("Please select a time slot.", "error");
        return;
      }

      // Disable save button and show loading
      reservationSaveBtn.disabled = true;
      reservationSaveBtn.textContent = isEdit ? "Updating..." : "Reserving...";

      try {
        const formData = new URLSearchParams();
        formData.append('action', isEdit ? 'update' : 'add');
    // Add subcategory ID if available
    const subcatDropdown = document.getElementById('subcategoryDropdown');
    if (subcatDropdown && subcatDropdown.value) {
      formData.append('subcategoryId', subcatDropdown.value);
    }
        formData.append('reservationDate', reservationDate);
        formData.append('timeSlot', timeSlot);
        formData.append('noOfGuests', noOfGuests);
        formData.append('mealType', mealType);
        formData.append('customerName', customerName);
        formData.append('phone', phone);
        formData.append('email', email);
        formData.append('specialRequest', specialRequest);
        if (tableId) {
          formData.append('selectTable', tableId);
        }
        if (isEdit) {
          formData.append('reservationId', reservationId);
        }

        const response = await fetch("reservation_operations.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
          },
          body: formData
        });

        const result = await response.json();

        if (result.success) {
          showMessage(result.message, "success");
          setTimeout(() => {
            closeReservationModal();
            loadReservations(); // Refresh the reservation list
          }, 1500);
        } else {
          showMessage(result.message || "Error processing request. Please try again.", "error");
        }
        
      } catch (error) {
        console.error("Error:", error);
        showNotification("Network error. Please check your connection and try again.", "error");
      } finally {
        // Re-enable save button
        reservationSaveBtn.disabled = false;
        reservationSaveBtn.textContent = isEdit ? "Update Reservation" : "Reserve Now";
      }
    });
  }
  
  // Load reservations
  async function loadReservations() {
    const reservationList = document.getElementById("reservationList");
    reservationList.innerHTML = '<div class="loading">Loading reservations...</div>';

    try {
      const response = await fetch("get_reservations.php");
      const result = await response.json();
      
      console.log("Reservations API response:", result);

      if (result.success) {
        displayReservations(result.data);
      } else {
        reservationList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Error loading reservations</h3><p>Please try again later.</p></div>';
      }
    } catch (error) {
      console.error("Error loading reservations:", error);
      reservationList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Network Error</h3><p>Please check your connection and try again.</p></div>';
    }
  }
  
  function displayReservations(reservations) {
    const reservationList = document.getElementById("reservationList");
    
    if (reservations.length === 0) {
      reservationList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">inbox</span><h3>No reservations found</h3><p>Create your first reservation to get started.</p></div>';
      return;
    }

    reservationList.innerHTML = reservations.map(reservation => {
      // Format date
      const date = new Date(reservation.reservation_date);
      const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
      const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
      const formattedDate = `${dayNames[date.getDay()]}, ${date.getDate()} ${monthNames[date.getMonth()]}, ${reservation.time_slot}`;
      
      // Status colors
      const statusColors = {
        'Pending': '#ffc107',
        'Confirmed': '#28a745',
        'Checked In': '#007bff',
        'Completed': '#6c757d',
        'Cancelled': '#dc3545',
        'No Show': '#ff6b6b'
      };
      
      return `
        <div class="reservation-card" data-reservation-id="${reservation.id}">
          <div class="reservation-card-header">
            <div>
              <button class="btn-assign-table" onclick="assignTable(${reservation.id})">
                <span class="material-symbols-rounded">table_chart</span>
                Assign Table
              </button>
              ${reservation.table_number ? `
                <div class="assigned-table-info" style="margin-top: 10px; padding: 8px 12px; background: #e7f5ff; border-radius: 8px; color: #0066cc; font-size: 0.9rem; font-weight: 500;">
                  <span class="material-symbols-rounded" style="font-size: 1rem;">table_restaurant</span>
                  Table ${reservation.table_number} - ${reservation.area_name}
                </div>
              ` : ''}
              <div class="reservation-status" style="background-color: ${statusColors[reservation.status] || '#6c757d'}; margin-top: 10px;">
                ${reservation.status.toUpperCase()}
              </div>
            </div>
            <div class="reservation-header-right">
              <div class="reservation-guests">
                <span class="material-symbols-rounded">people</span>
                ${reservation.no_of_guests} Guests
              </div>
            </div>
          </div>
          <div class="reservation-datetime">
            <span class="material-symbols-rounded">schedule</span>
            ${formattedDate}
          </div>
          <div class="reservation-info-box">
            <div class="reservation-guest-info">
              <div class="guest-name">
                <span class="material-symbols-rounded">person</span>
                ${escapeHtml(reservation.customer_name)}
              </div>
              <div class="guest-phone">
                <span class="material-symbols-rounded">phone</span>
                ${escapeHtml(reservation.phone)}
              </div>
              <div class="guest-email">
                <span class="material-symbols-rounded">email</span>
                ${escapeHtml(reservation.email || 'N/A')}
              </div>
            </div>
          </div>
          ${reservation.special_request ? `
            <div class="reservation-notes">
              <span class="material-symbols-rounded">note</span>
              ${escapeHtml(reservation.special_request)}
            </div>
          ` : ''}
          <div class="reservation-status-select">
            <select class="status-select" onchange="updateReservationStatus(${reservation.id}, this.value)">
              <option value="Pending" ${reservation.status === 'Pending' ? 'selected' : ''}>Pending</option>
              <option value="Confirmed" ${reservation.status === 'Confirmed' ? 'selected' : ''}>Confirmed</option>
              <option value="Checked In" ${reservation.status === 'Checked In' ? 'selected' : ''}>Checked In</option>
              <option value="No Show" ${reservation.status === 'No Show' ? 'selected' : ''}>No Show</option>
              <option value="Completed" ${reservation.status === 'Completed' ? 'selected' : ''}>Completed</option>
              <option value="Cancelled" ${reservation.status === 'Cancelled' ? 'selected' : ''}>Cancelled</option>
            </select>
          </div>
        </div>
      `;
    }).join('');
  }
  
  // Make functions globally accessible
  window.editReservation = function(reservationId, customerName, reservationDate, timeSlot, noOfGuests, mealType, phone, email, specialRequest, tableId) {
    currentReservationId = reservationId;
    selectedTimeSlot = timeSlot;
    
    // Set form values
    reservationIdInput.value = reservationId;
    reservationDateInput.value = reservationDate;
    noOfGuestsInput.value = noOfGuests;
    mealTypeSelect.value = mealType;
    customerNameInput.value = customerName;
    phoneInput.value = phone;
    emailInput.value = email;
    specialRequestInput.value = specialRequest;
    
    // Open modal
    loadTablesForDropdown().then(() => {
      if (tableId && tableId !== 'null') {
        selectTableSelect.value = tableId;
      }
      openReservationModal(true);
    });
  };
  
  window.deleteReservation = async function(reservationId, customerName) {
    if (await showSweetConfirm(`Are you sure you want to delete reservation for "${customerName}"? This action cannot be undone.`)) {
      // Call delete API
      const formData = new URLSearchParams();
      formData.append('action', 'delete');
      formData.append('reservationId', reservationId);
      
      fetch("reservation_operations.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: formData
      })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          showMessage(result.message, "success");
          loadReservations();
        } else {
          showMessage(result.message || "Error deleting reservation.", "error");
        }
      })
      .catch(error => {
        console.error("Error:", error);
        showNotification("Network error. Please check your connection and try again.", "error");
      });
    }
  };
  
  // Update reservation status
  window.updateReservationStatus = function(reservationId, newStatus) {
    const formData = new URLSearchParams();
    formData.append('action', 'update');
    formData.append('reservationId', reservationId);
    formData.append('status', newStatus);
    
    // Get current reservation data
    fetch("get_reservations.php")
      .then(response => response.json())
      .then(result => {
        if (result.success && result.data) {
          const reservation = result.data.find(r => r.id == reservationId);
          if (reservation) {
            formData.append('reservationDate', reservation.reservation_date);
            formData.append('timeSlot', reservation.time_slot);
            formData.append('noOfGuests', reservation.no_of_guests);
            formData.append('mealType', reservation.meal_type);
            formData.append('customerName', reservation.customer_name);
            formData.append('phone', reservation.phone);
            formData.append('email', reservation.email || '');
            formData.append('specialRequest', reservation.special_request || '');
            if (reservation.table_id) {
              formData.append('selectTable', reservation.table_id);
            }
            
            fetch("reservation_operations.php", {
              method: "POST",
              headers: {
                "Content-Type": "application/x-www-form-urlencoded",
              },
              body: formData
            })
            .then(response => response.json())
            .then(result => {
              if (result.success) {
                showMessage("Status updated successfully", "success");
                loadReservations();
              } else {
                showMessage(result.message || "Error updating status.", "error");
              }
            })
            .catch(error => {
              console.error("Error:", error);
              showNotification("Network error. Please check your connection and try again.", "error");
            });
          }
        }
      });
  };
  
  // Assign table to reservation
  window.assignTable = async function(reservationId) {
    // Load tables for dropdown
    fetch("get_tables.php")
      .then(response => response.json())
      .then(async result => {
        if (result.success && result.data && result.data.length > 0) {
          // Create clickable buttons for each table
          const tableButtons = result.data.map(table => {
            const label = `${table.table_number} - ${table.area_name} (${table.capacity} seats)`;
            return `<button class="table-select-btn" data-table-number="${table.table_number}" data-table-id="${table.id}" style="width: 100%; padding: 16px; margin: 8px 0; border: 2px solid #e5e7eb; border-radius: 12px; background: white; cursor: pointer; transition: all 0.2s; text-align: left; font-size: 15px; font-weight: 600; color: #111827;">
              <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 24px;">🪑</span>
                <div>
                  <div style="font-weight: 700; color: #111827;">${table.table_number}</div>
                  <div style="font-size: 13px; color: #6b7280; font-weight: 400;">${table.area_name} • ${table.capacity} seats</div>
                </div>
              </div>
            </button>`;
          }).join('');
          
          return new Promise((resolve) => {
            Swal.fire({
              title: 'Assign Table',
              html: `
                <div style="max-height: 400px; overflow-y: auto; margin: 20px 0;">
                  ${tableButtons}
                </div>
                <style>
                  .table-select-btn:hover {
                    border-color: #10b981 !important;
                    background: #f0fdf4 !important;
                    transform: translateX(4px);
                    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
                  }
                  .table-select-btn:active {
                    transform: translateX(0);
                  }
                </style>
              `,
              showConfirmButton: false,
              showCancelButton: true,
              cancelButtonText: 'Cancel',
              cancelButtonColor: '#6b7280',
              didOpen: () => {
                const buttons = Swal.getHtmlContainer().querySelectorAll('.table-select-btn');
                buttons.forEach(btn => {
                  btn.addEventListener('click', () => {
                    const tableId = btn.getAttribute('data-table-id');
                    const tableNumber = btn.getAttribute('data-table-number');
                    Swal.close();
                    resolve({ tableId, tableNumber });
                  });
                });
              },
              didClose: () => {
                if (!Swal.isConfirmed()) {
                  resolve(null);
                }
              }
            });
          }).then(selected => {
            if (selected && selected.tableId) {
              updateReservationTable(reservationId, selected.tableId);
            }
          });
        } else {
          showSweetAlert('No tables available', 'error');
        }
      })
      .catch(error => {
        console.error('Error loading tables:', error);
        showSweetAlert('Failed to load tables', 'error');
      });
  };
  
  function updateReservationTable(reservationId, tableId) {
    // Get current reservation data
    fetch("get_reservations.php")
      .then(response => response.json())
      .then(result => {
        if (result.success && result.data) {
          const reservation = result.data.find(r => r.id == reservationId);
          if (reservation) {
            const formData = new URLSearchParams();
            formData.append('action', 'update');
            formData.append('reservationId', reservationId);
            formData.append('selectTable', tableId);
            formData.append('reservationDate', reservation.reservation_date);
            formData.append('timeSlot', reservation.time_slot);
            formData.append('noOfGuests', reservation.no_of_guests);
            formData.append('mealType', reservation.meal_type);
            formData.append('customerName', reservation.customer_name);
            formData.append('phone', reservation.phone);
            formData.append('email', reservation.email || '');
            formData.append('specialRequest', reservation.special_request || '');
            formData.append('status', reservation.status);
            
            fetch("reservation_operations.php", {
              method: "POST",
              headers: {
                "Content-Type": "application/x-www-form-urlencoded",
              },
              body: formData
            })
            .then(response => response.json())
            .then(result => {
              if (result.success) {
                showMessage("Table assigned successfully", "success");
                loadReservations();
              } else {
                showMessage(result.message || "Error assigning table.", "error");
              }
            })
            .catch(error => {
              console.error("Error:", error);
              showNotification("Network error. Please check your connection and try again.", "error");
            });
          }
        }
      });
  }

  // ========== CUSTOMER MANAGEMENT ==========
  
  // Customer modal elements
  const customerModal = document.getElementById("customerModal");
  const customerModalTitle = document.getElementById("customerModalTitle");
  const customerForm = document.getElementById("customerForm");
  const customerIdInput = document.getElementById("customerId");
  const customerNameInputField = document.getElementById("customerNameInput");
  const customerPhoneInputField = document.getElementById("customerPhoneInput");
  const customerEmailInputField = document.getElementById("customerEmailInput");
  const addCustomerBtn = document.getElementById("addCustomerBtn");
  const customerCancelBtn = document.getElementById("customerCancelBtn");
  const customerSaveBtn = document.getElementById("customerSaveBtn");
  
  let currentCustomerId = null;
  
  // Add customer button event listener
  if (addCustomerBtn) {
    addCustomerBtn.addEventListener("click", () => openCustomerModal(false));
  }
  
  // Customer modal close
  function closeCustomerModal() {
    customerModal.style.display = "none";
    document.body.style.overflow = "auto";
  }
  
  if (customerCancelBtn) {
    customerCancelBtn.addEventListener("click", closeCustomerModal);
  }
  
  // Open customer modal
  function openCustomerModal(isEdit = false) {
    if (isEdit) {
      customerModalTitle.textContent = "Edit Customer";
      customerSaveBtn.textContent = "Update Customer";
    } else {
      customerModalTitle.textContent = "Add New Customer";
      customerForm.reset();
      customerIdInput.value = "";
      currentCustomerId = null;
      customerSaveBtn.textContent = "Save Customer";
    }
    
    customerModal.style.display = "block";
    document.body.style.overflow = "hidden";
    
    setTimeout(() => {
      customerNameInputField.focus();
    }, 150);
  }
  
  // Edit customer
  window.editCustomer = function(customerId, customerName, phone, email) {
    currentCustomerId = customerId;
    customerIdInput.value = customerId;
    customerNameInputField.value = customerName;
    customerPhoneInputField.value = phone;
    customerEmailInputField.value = email || '';
    openCustomerModal(true);
  };
  
  // Delete customer
  window.deleteCustomer = async function(customerId, customerName) {
    if (await showSweetConfirm(`Are you sure you want to delete customer "${customerName}"? This action cannot be undone.`)) {
      const formData = new URLSearchParams();
      formData.append('action', 'delete');
      formData.append('customerId', customerId);
      
      fetch("customer_operations.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: formData
      })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          showMessage(result.message, "success");
          loadCustomers();
        } else {
          showMessage(result.message || "Error deleting customer.", "error");
        }
      })
      .catch(error => {
        console.error("Error:", error);
        showNotification("Network error. Please check your connection and try again.", "error");
      });
    }
  };
  
  // Customer form submission
  if (customerForm) {
    customerForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      
      const formData = new URLSearchParams();
      formData.append('action', currentCustomerId ? 'update' : 'add');
      formData.append('customerId', customerIdInput.value);
      formData.append('customerName', customerNameInputField.value.trim());
      formData.append('phone', customerPhoneInputField.value.trim());
      formData.append('email', customerEmailInputField.value.trim());
      
      try {
        const response = await fetch("customer_operations.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
          },
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          showMessage(result.message, "success");
          closeCustomerModal();
          loadCustomers();
        } else {
          showMessage(result.message || "Error saving customer.", "error");
        }
      } catch (error) {
        console.error("Error:", error);
        showNotification("Network error. Please check your connection and try again.", "error");
      }
    });
  }
  
  // Load customers
  async function loadCustomers() {
    const customerList = document.getElementById("customerList");
    
    try {
      const response = await fetch("get_customers.php");
      const result = await response.json();
      
      if (result.success) {
        displayCustomers(result.data);
      } else {
        customerList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Error</h3><p>' + escapeHtml(result.message) + '</p></div>';
      }
    } catch (error) {
      console.error("Error loading customers:", error);
      customerList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Network Error</h3><p>Please check your connection and try again.</p></div>';
    }
  }
  
  // Display customers
  function displayCustomers(customers) {
    const customerList = document.getElementById("customerList");
    
    // Store for export
    window.currentCustomersData = customers;
    
    if (customers.length === 0) {
      customerList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">people</span><h3>No customers found</h3><p>Add your first customer to get started.</p></div>';
      return;
    }
    
    const initials = (name) => name.split(' ').map(w => w.charAt(0).toUpperCase()).join('').substring(0, 2);
    
    customerList.innerHTML = customers.map(customer => {
      const totalSpent = customer.total_spent ? formatCurrency(customer.total_spent) : formatCurrency(0);
      
      return `
        <tr data-customer-id="${customer.id}">
          <td class="avatar-cell">
            <div class="avatar-small">${initials(customer.customer_name)}</div>
          </td>
          <td>${escapeHtml(customer.customer_name)}</td>
          <td>${customer.phone || '-'}</td>
          <td>${escapeHtml(customer.email || '-')}</td>
          <td>${escapeHtml(customer.address || '-')}</td>
          <td>${customer.total_visits || 0}</td>
          <td>${totalSpent}</td>
          <td class="action-cell">
            <button class="btn-action-small edit-btn" onclick="editCustomer(${customer.id}, '${escapeHtml(customer.customer_name)}', '${customer.phone}', '${escapeHtml(customer.email || '')}')">
              <span class="material-symbols-rounded">edit</span>
              <span>Update</span>
            </button>
            <button class="btn-action-small delete-btn" onclick="deleteCustomer(${customer.id}, '${escapeHtml(customer.customer_name)}')">
              <span class="material-symbols-rounded">delete</span>
              <span>Delete</span>
            </button>
          </td>
        </tr>
      `;
    }).join('');
  }
  
  // ========== WAITER REQUESTS MANAGEMENT ==========
  
  // Load waiter requests
  async function loadWaiterRequests() {
    const requestsList = document.getElementById("waiterRequestsList");
    
    try {
      const response = await fetch("get_waiter_requests.php");
      const result = await response.json();
      
      console.log('Waiter requests response:', result);
      
      if (result.success) {
        displayWaiterRequestsByArea(result.requests_by_area || {});
      } else {
        requestsList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Error</h3><p>' + escapeHtml(result.message) + '</p></div>';
      }
    } catch (error) {
      console.error("Error loading waiter requests:", error);
      requestsList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Network Error</h3><p>Please check your connection and try again.</p></div>';
    }
  }
  
  // Display waiter requests grouped by area
  function displayWaiterRequestsByArea(requestsByArea) {
    const requestsList = document.getElementById("waiterRequestsList");
    
    if (Object.keys(requestsByArea).length === 0) {
      requestsList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">notifications_off</span><h3>No waiter requests</h3><p>All requests have been attended to.</p></div>';
      return;
    }
    
    let html = '';
    
    for (const [areaName, requests] of Object.entries(requestsByArea)) {
      const requestCount = requests.length;
      
      html += `
        <div style="margin-bottom: 3rem;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="color: #151A2D; font-size: 1.5rem; font-weight: 700;">${escapeHtml(areaName)}</h2>
            <span style="background: #f0f0f0; padding: 0.5rem 1rem; border-radius: 20px; font-size: 0.9rem; color: #666;">${requestCount} Table${requestCount !== 1 ? 's' : ''}</span>
          </div>
          
          ${requestCount > 0 ? `
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem;">
              ${requests.map(request => {
                const timeAgo = request.minutes_ago + ' minute' + (request.minutes_ago !== 1 ? 's' : '') + ' ago';
                return `
                  <div style="background: white; border-radius: 15px; padding: 1.5rem; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); display: flex; gap: 1.5rem; align-items: center;">
                    <!-- Left Section: Table Badge and Mark Attended Button -->
                    <div style="display: flex; flex-direction: column; gap: 1rem; align-items: flex-start;">
                      <div style="background: #e6f7ff; color: #1890ff; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 700; font-size: 1rem;">
                        ${escapeHtml(request.table_number)}
                      </div>
                      <button class="btn" style="background: white; border: 1px solid #d9d9d9; color: #333; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem;" onclick="markAttended(${request.id})">
                        <span style="color: #52c41a; font-weight: bold;">✓</span>
                        Mark Attended
                      </button>
                    </div>
                    
                    <!-- Right Section: Time, Waiter, and Show Order Button -->
                    <div style="display: flex; flex-direction: column; gap: 1rem; align-items: flex-end; flex: 1;">
                      <div style="display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end;">
                        <div style="display: flex; align-items: center; gap: 0.25rem; color: #666; font-size: 0.9rem;">
                          <span class="material-symbols-rounded" style="font-size: 1rem;">schedule</span>
                          <span>${timeAgo}</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.25rem; color: #666; font-size: 0.9rem;">
                          <span class="material-symbols-rounded" style="font-size: 1rem;">room_service</span>
                          <span>Waiter ${request.id % 3 + 1}</span>
                        </div>
                      </div>
                      ${request.request_type === 'Order' || request.notes?.includes('Order request:') ? `
                        <button class="btn" style="background: white; border: 1px solid #d9d9d9; color: #333; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem;" onclick="showOrderItems('${escapeHtml(request.notes || '')}')">
                          <span class="material-symbols-rounded" style="font-size: 1rem;">receipt_long</span>
                          Show Order
                        </button>
                      ` : ''}
                    </div>
                  </div>
                `;
              }).join('')}
            </div>
          ` : `
            <div style="text-align: center; padding: 3rem; color: #999;">
              <span class="material-symbols-rounded" style="font-size: 4rem; opacity: 0.3; display: block; margin-bottom: 1rem;">notifications_off</span>
              <p style="font-size: 1.1rem;">No waiter request found in this area.</p>
            </div>
          `}
        </div>
      `;
    }
    
    requestsList.innerHTML = html;
  }
  
  // Get time ago
  function getTimeAgo(dateString) {
    const now = new Date();
    const date = new Date(dateString);
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    const diffWeeks = Math.floor(diffDays / 7);
    const diffMonths = Math.floor(diffDays / 30);
    
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`;
    if (diffHours < 24) return `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`;
    if (diffDays < 7) return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`;
    if (diffWeeks < 4) return `${diffWeeks} week${diffWeeks !== 1 ? 's' : ''} ago`;
    if (diffMonths < 12) return `${diffMonths} month${diffMonths !== 1 ? 's' : ''} ago`;
    return `${Math.floor(diffMonths / 12)} year${Math.floor(diffMonths / 12) !== 1 ? 's' : ''} ago`;
  }
  
  // Mark request as attended
  window.markAttended = function(requestId) {
    const formData = new URLSearchParams();
    formData.append('action', 'mark_attended');
    formData.append('requestId', requestId);
    
    fetch("waiter_request_operations.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: formData
    })
    .then(response => response.json())
    .then(result => {
      if (result.success) {
        showMessage(result.message, "success");
        loadWaiterRequests();
      } else {
        showMessage(result.message || "Error marking request.", "error");
      }
    })
    .catch(error => {
      console.error("Error:", error);
      showMessage("Network error. Please check your connection and try again.", "error");
    });
  };
  
  // Show order items from notes
  window.showOrderItems = function(orderNotes) {
    // Extract order items from notes
    let orderItems = [];
    if (orderNotes && orderNotes.includes('Order request:')) {
      const itemsText = orderNotes.split('Order request:')[1]?.trim();
      if (itemsText) {
        orderItems = itemsText.split(', ').map(item => {
          const match = item.match(/(\d+)x\s*(.+)/);
          return match ? { quantity: parseInt(match[1]), name: match[2] } : null;
        }).filter(item => item !== null);
      }
    }
    
    if (orderItems.length === 0) {
      showSweetAlert('No order items found');
      return;
    }
    
    // Create modal HTML
    const modalHTML = `
      <div class="modal-overlay" id="orderItemsModal">
        <div class="modal-content" style="max-width: 500px;">
          <div class="modal-header">
            <h2>Order Items</h2>
            <button onclick="document.getElementById('orderItemsModal').remove()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
          </div>
          <div class="modal-body" style="padding: 2rem;">
            <table style="width: 100%; border-collapse: collapse;">
              <thead style="background: #f5f5f5;">
                <tr>
                  <th style="padding: 0.75rem; text-align: left;">Item</th>
                  <th style="padding: 0.75rem; text-align: center;">Qty</th>
                </tr>
              </thead>
              <tbody>
                ${orderItems.map(item => `
                  <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 0.75rem;">${item.name}</td>
                    <td style="padding: 0.75rem; text-align: center; font-weight: 600;">${item.quantity}</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
  };
  
  // Show full order details modal (from Orders page)
  window.showFullOrderDetails = async function(orderId) {
    try {
      const response = await fetch(`get_order_details_by_id.php?id=${orderId}`);
      const data = await response.json();
      
      if (data.success && data.order) {
        const order = data.order;
        
        // Create modal HTML
        const isDelivery = order.order_type === 'Delivery';
        const modalHTML = `
          <div class="modal-overlay" id="orderDetailsModal">
            <div class="modal-content" style="max-width: 600px;">
              <div class="modal-header">
                <h2>Order Details</h2>
                <button onclick="this.closest('.modal-overlay').remove()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
              </div>
              <div class="modal-body" style="padding: 2rem;">
                <div style="margin-bottom: 1.5rem;">
                  <p><strong>Order #:</strong> ${order.order_number}</p>
                  <p><strong>Table:</strong> ${order.table_name || order.table_number || 'Walk-in'}</p>
                  <p><strong>Customer:</strong> ${order.customer_name || 'N/A'}</p>
                  <p><strong>Type:</strong> ${order.order_type}</p>
                  <p><strong>Status:</strong> <span class="status-badge ${order.order_status.toLowerCase()}">${order.order_status}</span></p>
                  <p><strong>Payment:</strong> <span class="status-badge ${order.payment_status.toLowerCase().replace(' ', '-')}">${order.payment_status}</span></p>
                </div>
                
                ${isDelivery ? `
                <div id="deliveryInfoBlock" style="margin-bottom: 1.5rem; padding: 1rem; background: #f9fafb; border-radius: 10px; border: 1px solid #e5e7eb;">
                  <h3 style="margin-bottom: 0.75rem; font-size: 14px;">Delivery Tracking</h3>
                  <div id="deliveryTrackingContent">
                    <p style="font-size: 13px; color: #6b7280;">Loading delivery info...</p>
                  </div>
                  <div id="generateQrSection" style="margin-top: 12px; display: none;">
                    <button class="btn btn-primary" style="font-size: 13px; padding: 10px 16px; width: 100%;" onclick="generateDeliveryQr(${order.id})">
                      <span class="material-symbols-rounded" style="font-size: 18px; vertical-align: middle;">qr_code</span> Generate QR for Rider
                    </button>
                    <div id="qrCodeDisplay" style="display: none; text-align: center; margin-top: 12px;"></div>
                    <div id="qrUrlDisplay" style="display: none; text-align: center; margin-top: 8px;">
                      <a id="qrLink" href="#" target="_blank" style="font-size: 12px; color: #3b82f6; word-break: break-all;"></a>
                    </div>
                  </div>
                </div>
                ` : ''}
                
                <div style="margin-bottom: 1.5rem;">
                  <h3 style="margin-bottom: 1rem;">Items (${order.items.length})</h3>
                  <table style="width: 100%; border-collapse: collapse;">
                    <thead style="background: #f5f5f5;">
                      <tr>
                        <th style="padding: 0.75rem; text-align: left;">Item</th>
                        <th style="padding: 0.75rem;">Qty</th>
                        <th style="padding: 0.75rem; text-align: right;">Price</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${order.items.map(item => `
                        <tr style="border-bottom: 1px solid #eee;">
                          <td style="padding: 0.75rem;">${item.item_name}</td>
                          <td style="padding: 0.75rem; text-align: center;">${item.quantity}</td>
                          <td style="padding: 0.75rem; text-align: right;">${formatCurrency(item.total_price)}</td>
                        </tr>
                      `).join('')}
                    </tbody>
                  </table>
                </div>
                
                <div style="display: flex; justify-content: space-between; font-size: 1.2rem; font-weight: 700; padding-top: 1rem; border-top: 2px solid #ddd;">
                  <span>Total:</span>
                  <span>${formatCurrency(order.total)}</span>
                </div>
              </div>
            </div>
          </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // Load delivery tracking info if delivery order
        if (isDelivery) {
          loadDeliveryTracking(${order.id});
        }
      } else {
        showSweetAlert('Order not found');
      }
    } catch (error) {
      console.error('Error loading order details:', error);
      showSweetAlert('Failed to load order details');
    }
  };
  
  // Show order details modal
  window.showOrder = async function(tableId) {
    try {
      const response = await fetch(`get_order_details.php?table_id=${tableId}`);
      const data = await response.json();
      
      if (data.success && data.order) {
        const order = data.order;
        
        // Create modal HTML
        const modalHTML = `
          <div class="modal-overlay" id="orderDetailsModal">
            <div class="modal-content" style="max-width: 600px;">
              <div class="modal-header">
                <h2>Order Details</h2>
                <button onclick="this.closest('.modal-overlay').remove()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
              </div>
              <div class="modal-body" style="padding: 2rem;">
                <div style="margin-bottom: 1.5rem;">
                  <p><strong>Order #:</strong> ${order.order_number}</p>
                  <p><strong>Table:</strong> ${order.table_name || 'Walk-in'}</p>
                  <p><strong>Customer:</strong> ${order.customer_name || 'N/A'}</p>
                  <p><strong>Status:</strong> <span class="status-badge ${order.order_status.toLowerCase()}">${order.order_status}</span></p>
                  <p><strong>Payment:</strong> <span class="status-badge ${order.payment_status.toLowerCase().replace(' ', '-')}">${order.payment_status}</span></p>
                </div>
                
                <div style="margin-bottom: 1.5rem;">
                  <h3 style="margin-bottom: 1rem;">Items (${order.items.length})</h3>
                  <table style="width: 100%; border-collapse: collapse;">
                    <thead style="background: #f5f5f5;">
                      <tr>
                        <th style="padding: 0.75rem; text-align: left;">Item</th>
                        <th style="padding: 0.75rem;">Qty</th>
                        <th style="padding: 0.75rem; text-align: right;">Price</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${order.items.map(item => `
                        <tr style="border-bottom: 1px solid #eee;">
                          <td style="padding: 0.75rem;">${item.item_name}</td>
                          <td style="padding: 0.75rem; text-align: center;">${item.quantity}</td>
                          <td style="padding: 0.75rem; text-align: right;">${formatCurrency(item.total_price)}</td>
                        </tr>
                      `).join('')}
                    </tbody>
                  </table>
                </div>
                
                <div style="display: flex; justify-content: space-between; font-size: 1.2rem; font-weight: 700; padding-top: 1rem; border-top: 2px solid #ddd;">
                  <span>Total:</span>
                  <span>${formatCurrency(order.total)}</span>
                </div>
              </div>
            </div>
          </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
      } else {
        showMessage('Order not found', 'error');
      }
    } catch (error) {
      console.error('Error loading order details:', error);
      showMessage('Failed to load order details', 'error');
    }
  };
  
  // Helper: escape HTML for safe interpolation
  window.escHtml = function(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  };
  
  // Delivery tracking functions
  window.loadDeliveryTracking = async function(orderId) {
    try {
      const response = await fetch(`../api/get_delivery_tracking.php?order_id=${orderId}`);
      const data = await response.json();
      const container = document.getElementById('deliveryTrackingContent');
      const qrSection = document.getElementById('generateQrSection');
      if (!container) return;
      
      if (data.success && data.tracking) {
        const t = data.tracking;
        const statusLabels = {'Pending':'Pending','Preparing':'Preparing','Assigned':'Assigned','Picked_Up':'Picked Up','In_Transit':'In Transit','Delivered':'Delivered'};
        const label = statusLabels[t.delivery_status] || t.delivery_status;
        container.innerHTML = `
          <div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 13px;">
            <span style="color: #6b7280;">Delivery Status</span>
            <span class="status-badge" style="font-size: 11px; padding: 3px 10px;">${label}</span>
          </div>
          ${t.delivery_charge > 0 ? `<div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 13px;"><span style="color: #6b7280;">Delivery Fee</span><span style="font-weight: 600;">${formatCurrency(t.delivery_charge)}</span></div>` : ''}
          ${t.delivery_address ? `<div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 13px;"><span style="color: #6b7280;">Address</span><span style="font-weight: 600; text-align: right; max-width: 250px;">${escHtml(t.delivery_address)}</span></div>` : ''}
          ${t.rider_name ? `<div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 13px;"><span style="color: #6b7280;">Rider</span><span style="font-weight: 600;">${escHtml(t.rider_name)}</span></div>` : ''}
        `;
        if (t.delivery_status !== 'Delivered') {
          qrSection.style.display = 'block';
        }
      } else {
        // No tracking yet - show generate QR button
        container.innerHTML = '<p style="font-size: 13px; color: #6b7280;">Not yet assigned for delivery.</p>';
        qrSection.style.display = 'block';
      }
    } catch (e) {
      console.error('Error loading delivery tracking:', e);
    }
  };
  
  window.generateDeliveryQr = async function(orderId) {
    const btn = document.querySelector('#generateQrSection .btn');
    const display = document.getElementById('qrCodeDisplay');
    const urlDisplay = document.getElementById('qrUrlDisplay');
    const link = document.getElementById('qrLink');
    
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-rounded" style="font-size: 18px; vertical-align: middle;">sync</span> Generating...';
    
    try {
      const formData = new FormData();
      formData.append('order_id', orderId);
      const response = await fetch('../api/generate_delivery_qr.php', { method: 'POST', body: formData });
      const data = await response.json();
      
      if (data.success) {
        display.style.display = 'block';
        display.innerHTML = `<img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(data.qr_url)}" alt="QR Code" style="border-radius: 8px;">`;
        urlDisplay.style.display = 'block';
        link.href = data.qr_url;
        link.textContent = data.qr_url;
        btn.innerHTML = '<span class="material-symbols-rounded" style="font-size: 18px; vertical-align: middle;">check_circle</span> QR Generated';
        btn.style.background = '#059669';
        showSweetAlert('QR code generated! Show this to the rider.', 'success');
        // Reload tracking info
        setTimeout(() => loadDeliveryTracking(orderId), 500);
      } else {
        showSweetAlert(data.message || 'Failed to generate QR', 'error');
        btn.innerHTML = '<span class="material-symbols-rounded" style="font-size: 18px; vertical-align: middle;">qr_code</span> Generate QR for Rider';
        btn.disabled = false;
      }
    } catch (e) {
      showSweetAlert('Network error', 'error');
      btn.innerHTML = '<span class="material-symbols-rounded" style="font-size: 18px; vertical-align: middle;">qr_code</span> Generate QR for Rider';
      btn.disabled = false;
    }
  };
  
  // Staff modal functionality
  const staffModal = document.getElementById("staffModal");
  const staffForm = document.getElementById("staffForm");
  const addStaffBtn = document.getElementById("addStaffBtn");
  const staffCancelBtn = document.getElementById("staffCancelBtn");
  const staffSaveBtn = document.getElementById("staffSaveBtn");
  
  // Open staff modal
  if (addStaffBtn) {
    addStaffBtn.addEventListener("click", (e) => {
      e.preventDefault();
      // Reset form
      staffForm.reset();
      document.querySelector('#staffModal h2').textContent = 'Add Member';
      document.getElementById('staffSaveBtn').textContent = 'Save';
      document.getElementById('memberPassword').required = true;
      // Remove staffId if exists
      const staffIdField = document.getElementById('staffId');
      if (staffIdField) {
        staffIdField.value = '';
      }
      staffModal.style.display = "block";
      document.body.style.overflow = "hidden";
    });
  }
  
  // Close staff modal
  function closeStaffModal() {
    staffModal.style.display = "none";
    document.body.style.overflow = "auto";
    staffForm.reset();
    // Reset modal title and button text
    document.querySelector('#staffModal h2').textContent = 'Add Member';
    document.getElementById('staffSaveBtn').textContent = 'Save';
    document.getElementById('memberPassword').required = true;
    // Remove hidden staffId field if it exists
    const staffIdField = document.getElementById('staffId');
    if (staffIdField) {
      staffIdField.value = '';
    }
  }
  
  if (staffCancelBtn) {
    staffCancelBtn.addEventListener("click", closeStaffModal);
  }
  
  // Close modal when clicking outside
  if (staffModal) {
    window.addEventListener("click", (e) => {
      if (e.target === staffModal) {
        closeStaffModal();
      }
    });
  }
  
  // Close modal with X button
  const staffModalClose = staffModal?.querySelector(".close");
  if (staffModalClose) {
    staffModalClose.addEventListener("click", closeStaffModal);
  }
  
  // Staff form submission handler
  const handleStaffSubmit = async (e) => {
    e.preventDefault();
    
    const staffId = document.getElementById('staffId').value;
    const action = staffId ? 'update' : 'add';
    
    const formData = new URLSearchParams();
    formData.append('action', action);
    if (staffId) {
      formData.append('staffId', staffId);
    }
    formData.append('memberName', document.getElementById('memberName').value.trim());
    formData.append('memberEmail', document.getElementById('memberEmail').value.trim());
    formData.append('countryCode', document.getElementById('countryCode').value);
    formData.append('restaurantPhone', document.getElementById('staffPhone').value.trim());
    formData.append('memberPassword', document.getElementById('memberPassword').value);
    formData.append('memberRole', document.getElementById('memberRole').value);
    
    try {
      const response = await fetch("staff_operations.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: formData
      });
      
      const result = await response.json();
      
      if (result.success) {
        showMessage(result.message, "success");
        closeStaffModal();
        loadStaff();
      } else {
        showMessage(result.message || "Error saving staff member.", "error");
      }
    } catch (error) {
      console.error("Error:", error);
      showMessage("Network error. Please check your connection and try again.", "error");
    }
  };
  
  // Attach submit handler
  if (staffForm) {
    staffForm.addEventListener("submit", handleStaffSubmit);
  }
  
  // Load staff
  async function loadStaff() {
    const staffList = document.getElementById("staffList");
    
    try {
      const response = await fetch("get_staff.php");
      const result = await response.json();
      
      if (result.success) {
        displayStaff(result.data);
      } else {
        staffList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Error</h3><p>' + escapeHtml(result.message) + '</p></div>';
      }
    } catch (error) {
      console.error("Error loading staff:", error);
      staffList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Network Error</h3><p>Please check your connection and try again.</p></div>';
    }
  }
  
  // Display staff
  function displayStaff(staff) {
    const staffList = document.getElementById("staffList");
    
    // Store for export
    window.currentStaffData = staff;
    
    if (staff.length === 0) {
      staffList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">person</span><h3>No staff members found</h3><p>Add your first staff member to get started.</p></div>';
      return;
    }
    
    const initials = (name) => name.split(' ').map(w => w.charAt(0).toUpperCase()).join('').substring(0, 2);
    
    staffList.innerHTML = staff.map(member => {
      return `
        <tr data-staff-id="${member.id}">
          <td class="avatar-cell">
            <div class="avatar-small">${initials(member.member_name)}</div>
          </td>
          <td>${escapeHtml(member.member_name)}</td>
          <td>${escapeHtml(member.email)}</td>
          <td>${escapeHtml(member.phone)}</td>
          <td>${escapeHtml(member.role)}</td>
          <td class="action-cell">
            <button class="btn-action-small edit-btn" onclick="editStaff(${member.id}, '${escapeHtml(member.member_name)}', '${escapeHtml(member.email)}', '${member.phone}', '${member.role}')">
              <span class="material-symbols-rounded">edit</span>
              <span>Update</span>
            </button>
            <button class="btn-action-small delete-btn" onclick="deleteStaff(${member.id}, '${escapeHtml(member.member_name)}')">
              <span class="material-symbols-rounded">delete</span>
              <span>Delete</span>
            </button>
          </td>
        </tr>
      `;
    }).join('');
  }
  
  // Edit staff
  window.editStaff = function(staffId, memberName, email, phone, role) {
    document.getElementById('staffId').value = staffId;
    document.getElementById('memberName').value = memberName;
    document.getElementById('memberEmail').value = email;
    
    // Parse phone number (split country code and phone)
    const phoneParts = phone.split('-');
    if (phoneParts.length >= 2) {
      document.getElementById('countryCode').value = phoneParts[0];
      document.getElementById('staffPhone').value = phoneParts.slice(1).join('-');
    } else {
      document.getElementById('staffPhone').value = phone;
    }
    
    document.getElementById('memberRole').value = role;
    document.getElementById('memberPassword').required = false;
    
    // Update modal title
    document.querySelector('#staffModal h2').textContent = 'Edit Staff Member';
    document.getElementById('staffSaveBtn').textContent = 'Update';
    
    staffModal.style.display = "block";
    document.body.style.overflow = "hidden";
  };
  
  // Delete staff
  window.deleteStaff = async function(staffId, memberName) {
    if (await showSweetConfirm(`Are you sure you want to delete staff member "${memberName}"? This action cannot be undone.`)) {
      const formData = new URLSearchParams();
      formData.append('action', 'delete');
      formData.append('staffId', staffId);
      
      fetch("staff_operations.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: formData
      })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          showMessage(result.message, "success");
          loadStaff();
        } else {
          showMessage(result.message || "Error deleting staff member.", "error");
        }
      })
      .catch(error => {
        console.error("Error:", error);
        showNotification("Network error. Please check your connection and try again.", "error");
      });
    }
  };
  
  // POS functionality
  let posCart = [];
  
  // Load POS menu items
  async function loadPOSMenuItems() {
    const posMenuItemsContainer = document.getElementById("posMenuItems");
    
    // Get filter values
    const menuFilter = document.getElementById("posMenuFilter")?.value || '';
    const categoryFilter = document.getElementById("posCategoryFilter")?.value || '';
    const typeFilter = document.getElementById("posTypeFilter")?.value || '';
    
    // Build URL with filters
    let url = "get_menu_items.php";
    const params = new URLSearchParams();
    if (menuFilter) params.append('menu', menuFilter);
    if (categoryFilter) params.append('category', categoryFilter);
    if (typeFilter) params.append('type', typeFilter);
    if (params.toString()) url += '?' + params.toString();
    
    try {
      const response = await fetch(url);
      const result = await response.json();
      
      if (result.success) {
        displayPOSMenuItems(result.data);
      } else {
        posMenuItemsContainer.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Error</h3><p>' + escapeHtml(result.message) + '</p></div>';
      }
    } catch (error) {
      console.error("Error loading POS menu items:", error);
      posMenuItemsContainer.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Network Error</h3><p>Please check your connection and try again.</p></div>';
    }
  }
  
  // Display POS menu items
  function displayPOSMenuItems(items) {
    const posMenuItemsContainer = document.getElementById("posMenuItems");
    
    if (items.length === 0) {
      posMenuItemsContainer.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">menu</span><h3>No menu items found</h3><p>Add menu items to start selling.</p></div>';
      return;
    }
    
    posMenuItemsContainer.innerHTML = items.map(item => `
      <div class="pos-menu-item" onclick="addToPOSCart(${item.id}, '${escapeHtml(item.item_name_translated || item.item_name_en)}', ${item.base_price}, '${escapeHtml(item.item_image || '')}')">
        <div class="item-image">
          ${item.item_image ? `<img src="image.php?path=${encodeURIComponent(item.item_image)}" alt="${escapeHtml(item.item_name_translated || item.item_name_en)}">` : '<span class="material-symbols-rounded">restaurant</span>'}
        </div>
        <div class="item-name">${escapeHtml(item.item_name_translated || item.item_name_en)}</div>
        <div class="item-category">${escapeHtml(item.item_category || '')}</div>
        <div class="item-price">${formatCurrency(item.base_price)}</div>
      </div>
    `).join('');
  }
  
  // Load tables for POS
  async function loadTablesForPOS() {
    const selectPosTable = document.getElementById("selectPosTable");
    
    try {
      const response = await fetch("get_tables.php");
      const result = await response.json();
      
      if (result.success) {
        const tables = result.data || result.tables || [];
        if (selectPosTable) {
          selectPosTable.innerHTML = '<option value="">Takeaway</option>' + 
            tables.map(table => 
              `<option value="${table.id}">${escapeHtml(table.table_number)} - ${escapeHtml(table.area_name)}</option>`
            ).join('');
        }
      }
    } catch (error) {
      console.error("Error loading tables for POS:", error);
    }
  }
  
  // Add event listeners to POS filters
  const posMenuFilter = document.getElementById("posMenuFilter");
  const posCategoryFilter = document.getElementById("posCategoryFilter");
  const posTypeFilter = document.getElementById("posTypeFilter");
  
  if (posMenuFilter) {
    posMenuFilter.addEventListener("change", loadPOSMenuItems);
  }
  if (posCategoryFilter) {
    posCategoryFilter.addEventListener("change", loadPOSMenuItems);
  }
  if (posTypeFilter) {
    posTypeFilter.addEventListener("change", loadPOSMenuItems);
  }
  
  // Load menus for POS filters
  async function loadMenusForPOSFilters() {
    const posMenuFilter = document.getElementById("posMenuFilter");
    
    try {
      const response = await fetch("get_menus.php");
      const result = await response.json();
      
      if (result.success && posMenuFilter) {
        posMenuFilter.innerHTML = '<option value="">All Menus</option>' + 
          result.data.map(menu => 
            `<option value="${menu.id}">${escapeHtml(menu.menu_name_translated || menu.menu_name)}</option>`
          ).join('');
      }
    } catch (error) {
      console.error("Error loading menus for POS filters:", error);
    }
  }
  
  // Load categories for POS filters
  async function loadCategoriesForPOSFilters() {
    const posCategoryFilter = document.getElementById("posCategoryFilter");
    
    try {
      const response = await fetch("get_menu_items.php");
      const result = await response.json();
      
      if (result.success && posCategoryFilter && result.categories) {
        posCategoryFilter.innerHTML = '<option value="">All Categories</option>' + 
          result.categories.map(category => 
            `<option value="${escapeHtml(category)}">${escapeHtml(category)}</option>`
          ).join('');
      }
    } catch (error) {
      console.error("Error loading categories for POS filters:", error);
    }
  }
  
  function playClickSound() {
    try {
      const audio = new Audio('../../assets/sounds/click.wav');
      audio.volume = 0.5;
      audio.play().catch(() => {});
    } catch(e) {}
  }

  // Add item to POS cart
  window.addToPOSCart = function(itemId, itemName, price, image) {
    playClickSound();
    const existingItem = posCart.find(item => item.id === itemId);
    
    if (existingItem) {
      existingItem.quantity += 1;
    } else {
      posCart.push({
        id: itemId,
        name: itemName,
        price: price,
        image: image,
        quantity: 1
      });
    }
    
    updatePOSCart();
  };
  
  // Update POS cart display
  function updatePOSCart() {
    const cartItemsContainer = document.getElementById("posCartItems");
    
    if (posCart.length === 0) {
      cartItemsContainer.innerHTML = `
        <div class="empty-cart">
          <span class="material-symbols-rounded">shopping_cart</span>
          <p>Cart is empty</p>
          <p class="empty-subtext">Add items from the menu</p>
        </div>
      `;
    } else {
      cartItemsContainer.innerHTML = posCart.map(item => `
        <div class="cart-item">
          <div class="cart-item-info">
            <div class="cart-item-name">${escapeHtml(item.name)}</div>
            <div class="cart-item-price">${formatCurrency(item.price)} each</div>
          </div>
          <div class="cart-item-controls">
            <button class="cart-qty-btn" onclick="updatePOSCartQty(${item.id}, -1)">-</button>
            <span class="cart-qty-value">${item.quantity}</span>
            <button class="cart-qty-btn" onclick="updatePOSCartQty(${item.id}, 1)">+</button>
          </div>
          <div class="cart-item-total">${formatCurrency(parseFloat(item.price) * item.quantity)}</div>
        </div>
      `).join('');
    }
    
    updatePOSCartSummary();
  }
  
  // Update cart quantity
  window.updatePOSCartQty = function(itemId, change) {
    const item = posCart.find(item => item.id === itemId);
    
    if (item) {
      item.quantity += change;
      
      if (item.quantity <= 0) {
        posCart = posCart.filter(cartItem => cartItem.id !== itemId);
      }
      
      updatePOSCart();
    }
  };
  
  // Update cart summary
  function updatePOSCartSummary() {
    const subtotal = posCart.reduce((sum, item) => sum + (parseFloat(item.price) * item.quantity), 0);
    const showGst = window.enableGst == 1 || window.enableGst === true;
    const cgst = showGst ? subtotal * 0.025 : 0;
    const sgst = showGst ? subtotal * 0.025 : 0;
    const tax = cgst + sgst;
    const total = showGst ? (subtotal + tax) : subtotal;
    
    document.getElementById("cartSubtotal").textContent = formatCurrency(subtotal);
    const cgstEl = document.getElementById("cartCGST");
    const sgstEl = document.getElementById("cartSGST");
    if (cgstEl) cgstEl.textContent = formatCurrency(cgst);
    if (sgstEl) sgstEl.textContent = formatCurrency(sgst);
    const taxEl = document.getElementById("cartTax");
    if (taxEl) taxEl.textContent = formatCurrency(tax);
    document.getElementById("cartTotal").textContent = formatCurrency(total);
  }
  
  // Clear cart
  const clearCartBtn = document.getElementById("clearCartBtn");
  if (clearCartBtn) {
    clearCartBtn.addEventListener("click", () => {
      if (posCart.length > 0) {
        openPOSClearCartModal();
      } else {
        showNotification('Cart is already empty', 'error');
      }
    });
  }
  
  // POS Clear Cart Modal Functions
  function openPOSClearCartModal() {
    const modal = document.getElementById('posClearCartModal');
    if (modal) {
      modal.style.display = 'flex';
    }
  }
  
  function closePOSClearCartModal() {
    const modal = document.getElementById('posClearCartModal');
    if (modal) {
      modal.style.display = 'none';
    }
  }
  
  // Make functions globally available
  window.openPOSClearCartModal = openPOSClearCartModal;
  window.closePOSClearCartModal = closePOSClearCartModal;
  
  // Handle clear cart confirmation
  const posClearCartConfirmBtn = document.getElementById('posClearCartConfirmBtn');
  if (posClearCartConfirmBtn) {
    posClearCartConfirmBtn.addEventListener('click', () => {
        posCart = [];
        updatePOSCart();
      closePOSClearCartModal();
      showNotification('Cart cleared successfully', 'success');
    });
  }
  
  // Close modal when clicking outside
  const posClearCartModal = document.getElementById('posClearCartModal');
  if (posClearCartModal) {
    posClearCartModal.addEventListener('click', (e) => {
      if (e.target === posClearCartModal) {
        closePOSClearCartModal();
      }
    });
  }
  
  // Hold order
  const holdOrderBtn = document.getElementById("holdOrderBtn");
  if (holdOrderBtn) {
    holdOrderBtn.addEventListener("click", async () => {
      if (posCart.length === 0) {
        showNotification("Cart is empty", "error");
        return;
      }
      
      const subtotal = posCart.reduce((sum, item) => sum + (parseFloat(item.price) * item.quantity), 0);
      const showGst = window.enableGst == 1 || window.enableGst === true;
      const tax = showGst ? subtotal * 0.05 : 0;
      const total = subtotal + tax;
      const selectedTable = document.getElementById("selectPosTable").value;
      
      const formData = new URLSearchParams();
      formData.append('action', 'hold_order');
      formData.append('tableId', selectedTable || '');
      formData.append('cartItems', JSON.stringify(posCart));
      formData.append('subtotal', subtotal.toFixed(2));
      formData.append('tax', tax.toFixed(2));
      formData.append('total', total.toFixed(2));
      
      try {
        const response = await fetch("pos_operations.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
          },
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          showMessage(result.message + " - Order #" + result.order_number, "success");
          posCart = [];
          updatePOSCart();
          document.getElementById("selectPosTable").value = "";
        } else {
          showNotification(result.message || "Error holding order.", "error");
        }
      } catch (error) {
        console.error("Error:", error);
        showNotification("Network error. Please check your connection and try again.", "error");
      }
    });
  }
  
  // Process payment
  const processPaymentBtn = document.getElementById("processPaymentBtn");
  if (processPaymentBtn) {
    processPaymentBtn.addEventListener("click", async () => {
      if (posCart.length === 0) {
        showNotification("Cart is empty", "error");
        return;
      }
      
      const subtotal = posCart.reduce((sum, item) => sum + (parseFloat(item.price) * item.quantity), 0);
      const showGst = window.enableGst == 1 || window.enableGst === true;
      const tax = showGst ? subtotal * 0.05 : 0;
      const total = subtotal + tax;
      const selectedTable = document.getElementById("selectPosTable").value;
      
      // Store payment data for later use
      window.pendingPaymentData = {
        subtotal: subtotal,
        tax: tax,
        total: total,
        selectedTable: selectedTable
      };
      
      // Show payment method selection modal
      openPOSPaymentMethodModal();
    });
  }
  
  // Show customer details modal for all orders (takeaway and dine-in)
  function showTakeawayCustomerModal(isTakeaway = true) {
    return new Promise((resolve, reject) => {
      // Remove existing modal if it exists
      const existingModal = document.getElementById('takeawayCustomerModal');
      if (existingModal) {
        existingModal.remove();
      }
      
      const orderType = isTakeaway ? 'Takeaway' : 'Dine-in';
      
      // Create modal
      const modal = document.createElement('div');
      modal.id = 'takeawayCustomerModal';
      modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;';
      modal.innerHTML = `
        <div style="background: white; border-radius: 12px; padding: 2rem; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="margin: 0; color: #1f2937; font-size: 1.5rem;">Customer Details (${orderType})</h2>
            <button id="closeTakeawayModal" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6b7280;">&times;</button>
          </div>
          <form id="takeawayCustomerForm">
            <div style="margin-bottom: 1rem;">
              <label style="display: block; margin-bottom: 0.5rem; color: #374151; font-weight: 500;">Customer Name <span style="color: red;">*</span></label>
              <input type="text" id="takeawayCustomerName" required placeholder="Enter customer name" style="width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem;">
            </div>
            <div style="margin-bottom: 1rem;">
              <label style="display: block; margin-bottom: 0.5rem; color: #374151; font-weight: 500;">Phone Number <span style="color: red;">*</span></label>
              <input type="tel" id="takeawayCustomerPhone" required placeholder="Enter phone number" style="width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem;">
              <div id="returningCustomerMsg" style="margin-top: 0.5rem; padding: 0.5rem; background: #dbeafe; color: #1e40af; border-radius: 4px; font-size: 0.875rem; display: none;">
                <span class="material-symbols-rounded" style="vertical-align: middle; font-size: 1rem;">info</span>
                Returning customer found! Details auto-filled.
              </div>
            </div>
            <div style="margin-bottom: 1rem;">
              <label style="display: block; margin-bottom: 0.5rem; color: #374151; font-weight: 500;">Email Address</label>
              <input type="email" id="takeawayCustomerEmail" placeholder="Enter email (optional)" style="width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem;">
            </div>
            <div style="margin-bottom: 1.5rem;">
              <label style="display: block; margin-bottom: 0.5rem; color: #374151; font-weight: 500;">Address</label>
              <textarea id="takeawayCustomerAddress" rows="3" placeholder="Enter address (optional)" style="width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem; resize: vertical;"></textarea>
            </div>
            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
              <button type="button" id="cancelTakeawayModal" style="padding: 0.75rem 1.5rem; background: #e5e7eb; color: #374151; border: none; border-radius: 8px; font-weight: 500; cursor: pointer;">Cancel</button>
              <button type="submit" style="padding: 0.75rem 1.5rem; background: #dc2626; color: white; border: none; border-radius: 8px; font-weight: 500; cursor: pointer;">Continue</button>
            </div>
          </form>
        </div>
      `;
      document.body.appendChild(modal);
      
      // Close modal handlers
      const closeModal = () => {
        modal.remove();
        reject(new Error('Cancelled'));
      };
      
      document.getElementById('closeTakeawayModal').addEventListener('click', closeModal);
      document.getElementById('cancelTakeawayModal').addEventListener('click', closeModal);
      modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
      });
      
      // Returning customer check - when phone number is entered
      const phoneInput = document.getElementById('takeawayCustomerPhone');
      let checkTimeout;
      phoneInput.addEventListener('input', async () => {
        clearTimeout(checkTimeout);
        const phone = phoneInput.value.trim();
        
        // Wait for user to finish typing (500ms delay)
        checkTimeout = setTimeout(async () => {
          if (phone.length >= 10) {
            try {
              const response = await fetch('api/get_customer_by_phone.php', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `phone=${encodeURIComponent(phone)}`
              });
              
              if (response.ok) {
                const result = await response.json();
                if (result.success && result.customer) {
                  // Auto-fill customer details
                  document.getElementById('takeawayCustomerName').value = result.customer.customer_name || '';
                  document.getElementById('takeawayCustomerEmail').value = result.customer.email || '';
                  document.getElementById('takeawayCustomerAddress').value = result.customer.address || '';
                  
                  // Show returning customer message
                  document.getElementById('returningCustomerMsg').style.display = 'block';
                  setTimeout(() => {
                    document.getElementById('returningCustomerMsg').style.display = 'none';
                  }, 5000);
                }
              }
            } catch (error) {
              console.error('Error checking returning customer:', error);
            }
          }
        }, 500);
      });
      
      // Form submission
      document.getElementById('takeawayCustomerForm').addEventListener('submit', (e) => {
        e.preventDefault();
        const customerData = {
          name: document.getElementById('takeawayCustomerName').value.trim(),
          phone: document.getElementById('takeawayCustomerPhone').value.trim(),
          email: document.getElementById('takeawayCustomerEmail').value.trim(),
          address: document.getElementById('takeawayCustomerAddress').value.trim()
        };
        modal.remove();
        resolve(customerData);
      });
      
      // Focus on first input
      setTimeout(() => document.getElementById('takeawayCustomerName').focus(), 100);
    });
  }

  // POS Payment Method Modal Functions
  let selectedPaymentMethod = null;
  
  function openPOSPaymentMethodModal() {
    const modal = document.getElementById('posPaymentMethodModal');
    if (modal) {
      modal.style.display = 'flex';
      selectedPaymentMethod = null;
      // Reset button styles
      document.querySelectorAll('.payment-method-btn').forEach(btn => {
        btn.classList.remove('selected');
      });
    }
  }
  
  function closePOSPaymentMethodModal() {
    const modal = document.getElementById('posPaymentMethodModal');
    if (modal) {
      modal.style.display = 'none';
      selectedPaymentMethod = null;
    }
  }
  
  function selectPaymentMethod(method) {
    selectedPaymentMethod = method;
    // Update button styles
    document.querySelectorAll('.payment-method-btn').forEach(btn => {
      btn.classList.remove('selected');
      if (btn.dataset.method === method) {
        btn.classList.add('selected');
      }
    });
    
    // Process payment after short delay to show selection
    setTimeout(() => {
      processPOSPaymentWithMethod(method);
    }, 300);
  }
  
  // Make functions globally available
  window.openPOSPaymentMethodModal = openPOSPaymentMethodModal;
  window.closePOSPaymentMethodModal = closePOSPaymentMethodModal;
  window.selectPaymentMethod = selectPaymentMethod;
  
  async function processPOSPaymentWithMethod(paymentMethodStr) {
    if (!window.pendingPaymentData) {
      showNotification('Payment data not found. Please try again.', 'error');
      closePOSPaymentMethodModal();
      return;
    }
    
    const { subtotal, tax, total, selectedTable } = window.pendingPaymentData;
    const isTakeaway = !selectedTable;
    
    // For all orders (takeaway and dine-in), collect customer details
    let customerData = null;
    closePOSPaymentMethodModal(); // Close payment modal first
    try {
      customerData = await showTakeawayCustomerModal(isTakeaway);
    } catch (e) {
      // User cancelled
      return;
    }
      
      const formData = new URLSearchParams();
      formData.append('action', 'create_kot');
      formData.append('tableId', selectedTable || '');
      
      formData.append('orderType', isTakeaway ? 'Takeaway' : 'Dine-in');
      formData.append('customerName', customerData ? customerData.name : (isTakeaway ? 'Takeaway' : 'Table Customer'));
      if (customerData) {
        formData.append('customerPhone', customerData.phone || '');
        formData.append('customerEmail', customerData.email || '');
        formData.append('customerAddress', customerData.address || '');
      }
      formData.append('paymentMethod', paymentMethodStr);
      formData.append('cartItems', JSON.stringify(posCart));
      formData.append('subtotal', subtotal.toFixed(2));
      formData.append('tax', tax.toFixed(2));
      formData.append('total', total.toFixed(2));
      
      try {
        const response = await fetch("pos_operations.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
          },
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          posCart = [];
          updatePOSCart();
          const selectPosTableEl = document.getElementById("selectPosTable");
          if (selectPosTableEl) {
            selectPosTableEl.value = "";
          }
          
          // Show success alert with print option
          const hasKOT = result.kot_id && result.kot_number;
          const hasOrder = result.order_id && result.order_number;
          
          let title = '';
          let message = '';
          let printButtons = '';
          
          if (hasKOT && hasOrder) {
            title = 'KOT & Order Created Successfully!';
            message = `KOT #${result.kot_number} and Order #${result.order_number} have been created.`;
            printButtons = `
              <div style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
                <button id="printKOTBtn" style="padding: 10px 20px; background: #dc2626; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">
                  Print KOT
                </button>
                <button id="printOrderBtn" style="padding: 10px 20px; background: #059669; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">
                  Print Order
                </button>
              </div>
            `;
          } else if (hasKOT) {
            title = 'KOT Created Successfully!';
            message = `KOT #${result.kot_number} has been created.`;
            printButtons = `
              <div style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
                <button id="printKOTBtn" style="padding: 10px 20px; background: #dc2626; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">
                  Print KOT
                </button>
              </div>
            `;
          } else if (hasOrder) {
            title = 'Order Created Successfully!';
            message = `Order #${result.order_number} has been created.`;
            printButtons = `
              <div style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
                <button id="printOrderBtn" style="padding: 10px 20px; background: #059669; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">
                  Print Order
                </button>
              </div>
            `;
          } else {
            title = 'Success!';
            message = result.message || 'Payment processed successfully.';
          }
          
          if (window.Swal) {
            Swal.fire({
              icon: 'success',
              title: title,
              html: `
                <p style="margin-bottom: 0;">${message}</p>
                ${printButtons}
              `,
              showConfirmButton: true,
              confirmButtonText: 'OK',
              confirmButtonColor: '#dc2626',
              allowOutsideClick: false,
              didOpen: () => {
                // Add click handlers for print buttons
                if (hasKOT) {
                  const printKOTBtn = document.getElementById('printKOTBtn');
                  if (printKOTBtn && window.printKOT) {
                    printKOTBtn.addEventListener('click', () => {
                      Swal.close();
                      window.printKOT(result.kot_id);
                    });
                  }
                }
                
                if (hasOrder) {
                  const printOrderBtn = document.getElementById('printOrderBtn');
                  if (printOrderBtn && window.printOrder) {
                    printOrderBtn.addEventListener('click', () => {
                      Swal.close();
                      window.printOrder(result.order_id);
                    });
                  }
                }
              }
            });
          } else {
            const msg = result.kot_number
              ? (result.message + " - KOT #" + result.kot_number + " - Order #" + result.order_number)
              : (result.message + " - Order #" + result.order_number);
            showNotification(msg, "success");
            
            // If no SweetAlert, try to print directly
            if (hasKOT && window.printKOT) {
              setTimeout(() => window.printKOT(result.kot_id), 500);
            } else if (hasOrder && window.printOrder) {
              setTimeout(() => window.printOrder(result.order_id), 500);
            }
          }
          
          // Reload orders if on the orders page
          const ordersPage = document.getElementById('ordersPage');
          if (ordersPage && ordersPage.classList.contains('active') && typeof loadOrders !== 'undefined') {
            loadOrders();
          }
        } else {
          showNotification(result.message || "Error creating KOT.", "error");
        }
      } catch (error) {
        console.error("Error:", error);
        showNotification("Network error. Please check your connection and try again.", "error");
      } finally {
        // Re-enable button
        const processBtn = document.getElementById('processPaymentBtn');
        if (processBtn) {
          processBtn.disabled = false;
          processBtn.innerHTML = '<span class="material-symbols-rounded">payment</span> Process Payment';
        }
        // Clear pending payment data
        window.pendingPaymentData = null;
      }
    }
  
  // Close payment method modal when clicking outside
  const posPaymentMethodModal = document.getElementById('posPaymentMethodModal');
  if (posPaymentMethodModal) {
    posPaymentMethodModal.addEventListener('click', (e) => {
      if (e.target === posPaymentMethodModal) {
        closePOSPaymentMethodModal();
        window.pendingPaymentData = null;
      }
    });
  }
  
  // Add CSS for notifications
  const style = document.createElement('style');
  style.textContent = `
    @keyframes slideInRight {
      from { transform: translateX(100%); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOutRight {
      from { transform: translateX(0); opacity: 1; }
      to { transform: translateX(100%); opacity: 0; }
    }
  `;
  document.head.appendChild(style);
});

// Session management and logout functionality
document.addEventListener("DOMContentLoaded", () => {
  // Load restaurant info from session
  loadRestaurantInfo();
});

async function loadRestaurantInfo() {
  try {
    const response = await fetch("admin/get_session.php");
    const result = await response.json();
    
    if (result.success) {
      document.getElementById("restaurantName").textContent = result.data.restaurant_name;
      document.getElementById("restaurantId").textContent = result.data.restaurant_id;
      
      // Update dashboard logo with cache-busting
      const dashboardLogo = document.getElementById("dashboardRestaurantLogo");
      if (dashboardLogo && result.data.restaurant_logo) {
        let logoPath;
        const timestamp = Date.now(); // Cache-busting timestamp
        if (result.data.restaurant_logo.startsWith('db:')) {
          // Database-stored image
          logoPath = `image.php?type=logo&id=${result.data.id || result.data.user_id || ''}&t=${timestamp}`;
        } else if (result.data.restaurant_logo.startsWith('http')) {
          // External URL
          logoPath = result.data.restaurant_logo + (result.data.restaurant_logo.includes('?') ? '&' : '?') + `t=${timestamp}`;
        } else if (result.data.restaurant_logo.startsWith('uploads/')) {
          // File-based image
          logoPath = `${result.data.restaurant_logo}?t=${timestamp}`;
        } else {
          // Relative path
          logoPath = `uploads/${result.data.restaurant_logo}?t=${timestamp}`;
        }
        // Force image reload
        dashboardLogo.src = '';
        setTimeout(() => {
          dashboardLogo.src = logoPath;
        }, 10);
        dashboardLogo.style.borderRadius = '50%';
        dashboardLogo.style.objectFit = 'cover';
        dashboardLogo.onerror = function() {
          // Fallback to default logo if restaurant logo fails to load
          this.src = 'assets/images/logo.png';
          this.style.borderRadius = '50%';
          this.style.objectFit = 'cover';
        };
      } else if (dashboardLogo) {
        // Reset to default logo if no restaurant logo
        dashboardLogo.src = 'assets/images/logo.png';
        dashboardLogo.style.borderRadius = '50%';
        dashboardLogo.style.objectFit = 'cover';
      }
      
      // Store subscription data globally
      subscriptionData = result.data;
      const status = result.data.subscription_status;
      const endDate = result.data.renewal_date || result.data.trial_end_date;
      
      // Determine if trial is expired
      let finalStatus = status || 'unknown';
      
      // Check if status is already 'expired' or 'disabled' from database
      if (status === 'expired' || status === 'disabled') {
        finalStatus = status;
      } 
      // Check if trial has expired by date
      else if (status === 'trial' && endDate) {
        const end = new Date(endDate);
        const today = new Date();
        const days = Math.ceil((end - new Date(today.toDateString())) / (1000 * 60 * 60 * 24));
        if (days <= 0) {
          finalStatus = 'expired';
        }
      }
      
      // Store subscription status globally
      subscriptionStatus = finalStatus;
      
      // Trial/renewal info
      const tInfo = document.getElementById('trialInfo');
      if (tInfo) {
        if (endDate) {
          const end = new Date(endDate);
          const today = new Date();
          const days = Math.max(0, Math.ceil((end - new Date(today.toDateString()))/ (1000*60*60*24)));
          if (status === 'disabled') {
            tInfo.style.display = 'block';
            tInfo.style.color = '#92400e';
            tInfo.textContent = 'Account disabled';
          } else if (days > 0) {
            tInfo.style.display = 'block';
            tInfo.style.color = '#92400e';
            tInfo.textContent = `Trial ends in ${days} day${days>1?'s':''} (on ${end.toLocaleDateString()})`;
          } else {
            tInfo.style.display = 'block';
            tInfo.style.color = '#991b1b';
            tInfo.textContent = `Trial expired on ${end.toLocaleDateString()}`;
          }
        }
      }
    }
  } catch (error) {
    console.error("Error loading restaurant info:", error);
  }
}

async function initiateRenewal() {
  try {
    const renewButton = document.getElementById('renewButton');
    if (renewButton) {
      renewButton.disabled = true;
      renewButton.innerHTML = '<span style="font-size:1.2rem;">⏳</span> Processing...';
    }
    
    const amount = 999; // 999 for monthly subscription
    
    const formData = new URLSearchParams();
    formData.append('amount', amount);
    formData.append('subscription_type', 'monthly');
    
    const response = await fetch('phonepe_payment.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: formData.toString()
    });
    
    const result = await response.json();
    
    if (result.success && result.payment_url) {
      // Mark that we're processing payment (only for real PhonePe, not demo)
      if (!result.demo_mode) {
        sessionStorage.setItem('payment_processing', 'true');
      }
      // Redirect to payment page (PhonePe or Demo)
      window.location.href = result.payment_url;
    } else {
      showNotification(result.message || 'Error initiating payment. Please try again.', 'error');
      if (renewButton) {
        renewButton.disabled = false;
        renewButton.innerHTML = '<span style="font-size:1.2rem;">💳</span> Renew Now (' + formatCurrency(999) + ')';
      }
    }
  } catch (error) {
    console.error('Error initiating renewal:', error);
    showNotification('Network error. Please try again.', 'error');
    const renewButton = document.getElementById('renewButton');
    if (renewButton) {
      renewButton.disabled = false;
      renewButton.innerHTML = '<span style="font-size:1.2rem;">💳</span> Renew Now (₹999)';
    }
  }
}

// Make function globally available
window.initiateRenewal = initiateRenewal;

async function logout() {
  if (await showSweetConfirm("Are you sure you want to logout?")) {
    try {
      const response = await fetch("admin/auth.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: "action=logout"
      });
      
      const result = await response.json();
      
      if (result.success) {
        // Redirect to login page
        window.location.href = "admin/login.php";
      } else {
        showNotification("Error logging out. Please try again.", "error");
      }
    } catch (error) {
      console.error("Error logging out:", error);
      showNotification("Network error. Please try again.", "error");
    }
  }
}

  // Load KOT Orders
  async function loadKOTOrders() {
    try {
      const statusFilter = document.getElementById('kotStatusFilter')?.value || '';
      const tableFilter = document.getElementById('kotTableFilter')?.value || '';
      
      let url = 'get_kot.php';
      const params = [];
      if (statusFilter) params.push(`status=${encodeURIComponent(statusFilter)}`);
      if (tableFilter) params.push(`table=${encodeURIComponent(tableFilter)}`);
      if (params.length > 0) url += '?' + params.join('&');
      
      const response = await fetch(url);
      const data = await response.json();
      
      const kotLastRefresh = document.getElementById('kotLastRefresh');
      if (kotLastRefresh) {
        kotLastRefresh.textContent = 'Last updated: ' + new Date().toLocaleTimeString();
      }
      
      if (data.success) {
        displayKOTOrders(data.kots);
      } else {
        document.getElementById('kotList').innerHTML = '<div class="error">Failed to load KOT orders</div>';
      }
    } catch (error) {
      console.error('Error loading KOT orders:', error);
      document.getElementById('kotList').innerHTML = '<div class="error">Error loading KOT orders</div>';
    }
  }

// Display KOT Orders
function displayKOTOrders(kots) {
  const kotList = document.getElementById('kotList');
  
  if (!kots || kots.length === 0) {
    kotList.innerHTML = '<div class="empty-state">No KOT orders found</div>';
    return;
  }
  
  kotList.innerHTML = kots.map(kot => {
    const kotStatus = kot.kot_status || kot.status || 'Pending';
    const statusClass = kotStatus.toLowerCase().replace(' ', '-');
    
    return `
    <div class="kot-order-card" style="border-left: 4px solid ${kotStatus === 'Pending' ? '#f59e0b' : kotStatus === 'Preparing' ? '#3b82f6' : kotStatus === 'Ready' ? '#10b981' : '#6b7280'};">
      <div class="kot-header">
        <div class="kot-title">
          <h3 style="color: #dc2626; margin: 0; font-size: 1.2rem;">KOT #${kot.kot_number || kot.id}</h3>
          <p style="margin: 5px 0; color: #6b7280; font-size: 0.9rem;">${kot.item_count || (kot.items?.length || 0)} Item(s)</p>
          <p style="margin: 5px 0; color: #6b7280; font-size: 0.85rem;">Table: ${kot.table_number || 'Takeaway'} | ${kot.area_name || ''}</p>
        </div>
        <div class="kot-order-info">
          <span class="kot-status ${statusClass}" style="padding: 6px 12px; border-radius: 20px; font-weight: 600; font-size: 0.875rem; background: ${kotStatus === 'Pending' ? '#fef3c7' : kotStatus === 'Preparing' ? '#dbeafe' : kotStatus === 'Ready' ? '#d1fae5' : '#f3f4f6'}; color: ${kotStatus === 'Pending' ? '#92400e' : kotStatus === 'Preparing' ? '#1e40af' : kotStatus === 'Ready' ? '#065f46' : '#6b7280'};">
            ${kotStatus === 'Preparing' ? 'IN KITCHEN' : kotStatus === 'Pending' ? 'PENDING' : kotStatus}
          </span>
          <p style="margin: 5px 0 0 0; color: #6b7280; font-size: 0.8rem; text-align: right;">${new Date(kot.created_at).toLocaleString()}</p>
        </div>
      </div>
      
      <div class="kot-items" style="margin: 16px 0;">
        <h4 style="margin: 15px 0 10px 0; color: #1f2937; font-size: 0.9rem; text-transform: uppercase;">ITEMS</h4>
        ${(kot.items || []).map(item => `
          <div class="kot-item" style="display: flex; justify-content: space-between; padding: 12px; background: #f9fafb; border-radius: 8px; margin-bottom: 8px;">
            <div>
              <strong>${item.item_name || item.name}</strong>
              <div style="color: #6b7280; font-size: 0.875rem;">Qty: ${item.quantity}</div>
            </div>
            ${(item.notes || item.special_instructions) ? `<div style="color: #f59e0b; font-size: 0.875rem;">Note: ${item.notes || item.special_instructions}</div>` : ''}
          </div>
        `).join('')}
      </div>
      
      <div class="kot-actions">
        <button class="btn btn-print" onclick="printKOT(${kot.id})">
          <span class="material-symbols-rounded">print</span>
          Print
        </button>
        ${(kot.kot_status || kot.status) === 'Pending' ? `
          <button class="btn btn-primary" onclick="updateKOTStatus(${kot.id}, 'Preparing')">
            <span class="material-symbols-rounded">restaurant</span>
            Start Cooking
          </button>
        ` : (kot.kot_status || kot.status) === 'Preparing' ? `
          <button class="btn btn-success" onclick="updateKOTStatus(${kot.id}, 'Ready')">
            <span class="material-symbols-rounded">check</span>
            Mark as Ready
          </button>
        ` : (kot.kot_status || kot.status) === 'Ready' ? `
          <button class="btn btn-warning" onclick="completeKOT(${kot.id})">
            <span class="material-symbols-rounded">done_all</span>
            Complete Order
          </button>
        ` : ''}
        ${(kot.kot_status || kot.status) !== 'Completed' ? `
          <button class="btn btn-danger" onclick="cancelKOT(${kot.id})">
            <span class="material-symbols-rounded">delete</span>
            Cancel
          </button>
        ` : ''}
      </div>
    </div>
  `;
  }).join('');
}

// Load Orders
async function loadOrders() {
  try {
    // Get filter values
    const searchTerm = document.getElementById('ordersSearch')?.value.trim() || '';
    const statusFilter = document.getElementById('ordersStatusFilter')?.value || '';
    const paymentFilter = document.getElementById('ordersPaymentFilter')?.value || '';
    const typeFilter = document.getElementById('ordersTypeFilter')?.value || '';
    const dateFilter = document.getElementById('ordersDateFilter')?.value || '';
    
    // Build query string
    const params = new URLSearchParams();
    if (searchTerm) params.append('search', searchTerm);
    if (statusFilter) params.append('status', statusFilter);
    if (paymentFilter) params.append('payment_status', paymentFilter);
    if (typeFilter) params.append('order_type', typeFilter);
    if (dateFilter) params.append('date', dateFilter);
    
    const response = await fetch(`get_orders.php?${params.toString()}`);
    const data = await response.json();
    
    console.log('Orders loaded:', data.count, 'orders found');
    
    if (data.success) {
      displayOrders(data.orders);
      // Store data for export
      window.currentOrdersData = data.orders;
    } else {
      document.getElementById('ordersList').innerHTML = '<div class="error">Failed to load orders</div>';
    }
  } catch (error) {
    console.error('Error loading orders:', error);
    document.getElementById('ordersList').innerHTML = '<div class="error">Error loading orders</div>';
  }
}

// Setup order filter listeners
document.addEventListener('DOMContentLoaded', function() {
  const ordersStatusFilter = document.getElementById('ordersStatusFilter');
  const ordersPaymentFilter = document.getElementById('ordersPaymentFilter');
  const ordersTypeFilter = document.getElementById('ordersTypeFilter');
  
  if (ordersStatusFilter) {
    ordersStatusFilter.addEventListener('change', () => loadOrders());
  }
  if (ordersPaymentFilter) {
    ordersPaymentFilter.addEventListener('change', () => loadOrders());
  }
  if (ordersTypeFilter) {
    ordersTypeFilter.addEventListener('change', () => loadOrders());
  }
});

// HTML escape utility used across UI rendering and printing
function escapeHtml(str) {
  if (str === undefined || str === null) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// Export Orders to CSV
function exportOrdersToCSV() {
  if (!window.currentOrdersData || window.currentOrdersData.length === 0) {
    showSweetAlert('No orders to export.');
    return;
  }
  
  let csv = 'Order Management Report\n\n';
  csv += 'Order Number,Table,Customer,Type,Status,Payment Status,Payment Method,Total,Date\n';
  
  window.currentOrdersData.forEach(order => {
    csv += `"${order.order_number}","${order.table_number || 'Walk-in'}","${order.customer_name || 'N/A'}","${order.order_type}","${order.order_status}","${order.payment_status}","${order.payment_method}",${order.total},"${new Date(order.created_at).toLocaleDateString()}"\n`;
  });
  
  downloadCSV(csv, `orders_${new Date().toISOString().split('T')[0]}.csv`);
}

// Generic CSV download function
function downloadCSV(csv, filename) {
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const link = document.createElement('a');
  const url = URL.createObjectURL(blob);
  link.setAttribute('href', url);
  link.setAttribute('download', filename);
  link.style.visibility = 'hidden';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  showNotification('File downloaded successfully!', 'success');
}

// Export Customers to CSV
function exportCustomersToCSV() {
  const customers = window.currentCustomersData || [];
  if (customers.length === 0) {
    showSweetAlert('No customers to export.');
    return;
  }
  
  let csv = 'Customers Report\n\n';
  csv += 'Name,Phone,Email,Total Visits,Total Spent,Last Visit\n';
  
  customers.forEach(customer => {
    csv += `"${customer.customer_name || 'N/A'}","${customer.phone || 'N/A'}","${customer.email || 'N/A'}",${customer.total_visits || 0},${customer.total_spent || 0},"${customer.last_visit_date || 'N/A'}"\n`;
  });
  
  downloadCSV(csv, `customers_${new Date().toISOString().split('T')[0]}.csv`);
}

// Export Staff to CSV
function exportStaffToCSV() {
  const staff = window.currentStaffData || [];
  if (staff.length === 0) {
    showSweetAlert('No staff to export.');
    return;
  }
  
  let csv = 'Staff Report\n\n';
  csv += 'Name,Phone,Email,Role,Status\n';
  
  staff.forEach(member => {
    csv += `"${member.staff_name || 'N/A'}","${member.phone || 'N/A'}","${member.email || 'N/A'}","${member.role || 'N/A'}","${member.is_active ? 'Active' : 'Inactive'}"\n`;
  });
  
  downloadCSV(csv, `staff_${new Date().toISOString().split('T')[0]}.csv`);
}

// Export Payments to CSV
function exportPaymentsToCSV() {
  const payments = window.currentPaymentsData || [];
  if (payments.length === 0) {
    showSweetAlert('No payments to export.');
    return;
  }
  
  let csv = 'Payments Report\n\n';
  csv += 'ID,Amount,Payment Method,Transaction ID,Order,Status,Date\n';
  
  payments.forEach(payment => {
    csv += `${payment.id},${payment.amount},"${payment.payment_method || 'N/A'}","${payment.transaction_id || 'N/A'}","${payment.order_number || 'N/A'}","${payment.payment_status}","${new Date(payment.created_at).toLocaleDateString()}"\n`;
  });
  
  downloadCSV(csv, `payments_${new Date().toISOString().split('T')[0]}.csv`);
}

// Display Orders
function displayOrders(orders) {
  const ordersList = document.getElementById('ordersList');
  
  if (!orders || orders.length === 0) {
    ordersList.innerHTML = '<div class="empty-state" style="text-align:center;padding:40px 20px;"><span class="material-symbols-rounded" style="font-size:48px;color:#9ca3af;">receipt_long</span><h3 style="margin:12px 0 4px;color:#374151;">No orders found</h3><p style="color:#6b7280;font-size:14px;max-width:300px;margin:0 auto;">Orders will appear here once customers start ordering. Try adjusting your filters.</p><button onclick="resetAllOrdersFilters()" style="margin-top:16px;padding:8px 20px;background:#667eea;color:white;border:none;border-radius:8px;cursor:pointer;font-weight:600;"><span class="material-symbols-rounded" style="font-size:16px;vertical-align:middle;">refresh</span> Reset Filters</button></div>';
    return;
  }
  
  ordersList.innerHTML = orders.map(order => `
    <div class="order-card">
      <div class="order-header">
        <h3>Order #${order.order_number}</h3>
        <div class="order-badges">
          <span class="status-badge ${order.order_status.toLowerCase()}">${order.order_status}</span>
          <span class="payment-badge ${order.payment_status.toLowerCase().replace(' ', '-')}">${order.payment_status}</span>
        </div>
      </div>
      <div class="order-details">
        <p><strong>Type:</strong> ${order.order_type}</p>
        <p><strong>Table:</strong> ${order.table_name || 'Walk-in'}</p>
        <p><strong>Customer:</strong> ${order.customer_name || 'N/A'}</p>
        <p><strong>Time:</strong> ${new Date(order.created_at).toLocaleString()}</p>
        <p><strong>Total:</strong> ${formatCurrency(order.total)}</p>
      </div>
      <div class="order-items">
        <h4>Items:</h4>
        ${order.items.map(item => `
          <div class="order-item">
            <span class="item-name">${item.item_name}</span>
            <span class="item-qty">x${item.quantity}</span>
            <span class="item-price">${formatCurrency(item.total_price)}</span>
          </div>
        `).join('')}
      </div>
      <div class="order-actions" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <button class="btn btn-info" onclick="showFullOrderDetails(${order.id})" style="background: #667eea; color: white; border: none;">
          <span class="material-symbols-rounded" style="font-size: 1rem; vertical-align: middle;">visibility</span>
          Show Order
        </button>
        <button class="btn btn-primary" onclick="updateOrderStatus(${order.id}, 'Preparing')">Start Preparing</button>
        <button class="btn btn-success" onclick="updateOrderStatus(${order.id}, 'Ready')">Mark Ready</button>
        <button class="btn btn-warning" onclick="updateOrderStatus(${order.id}, 'Served')">Mark Served</button>
        <button class="btn btn-danger" onclick="updateOrderStatus(${order.id}, 'Cancelled')">Cancel Order</button>
      </div>
    </div>
  `).join('');
}

// Reset all orders filters
window.resetAllOrdersFilters = function() {
  const searchEl = document.getElementById('ordersSearch');
  const statusEl = document.getElementById('ordersStatusFilter');
  const paymentEl = document.getElementById('ordersPaymentFilter');
  const typeEl = document.getElementById('ordersTypeFilter');
  const tableEl = document.getElementById('ordersTableFilter');
  const dateEl = document.getElementById('ordersDateFilter');
  
  if (searchEl) searchEl.value = '';
  if (statusEl) statusEl.value = '';
  if (paymentEl) paymentEl.value = '';
  if (typeEl) typeEl.value = '';
  if (tableEl) tableEl.value = '';
  if (dateEl) {
    const today = new Date().toISOString().split('T')[0];
    dateEl.value = today;
  }
  
  loadOrders();
};

// Load tables for KOT filter
async function loadTablesForKOT() {
  try {
    const response = await fetch('get_tables.php');
    const data = await response.json();
    
    if (data.success) {
      const tableFilter = document.getElementById('kotTableFilter');
      tableFilter.innerHTML = '<option value="">All Tables</option>' + 
        data.tables.map(table => `<option value="${table.id}">${table.table_name}</option>`).join('');
    }
  } catch (error) {
    console.error('Error loading tables for KOT:', error);
  }
}

// Load tables for Orders filter
async function loadTablesForOrders() {
  try {
    const response = await fetch('get_tables.php');
    const data = await response.json();
    
    if (data.success) {
      const tableFilter = document.getElementById('ordersTableFilter');
      if (tableFilter) {
        tableFilter.innerHTML = '<option value="">All Tables</option>' + 
          data.tables.map(table => `<option value="${table.id}">${table.table_name}</option>`).join('');
      }
    }
  } catch (error) {
    console.error('Error loading tables for Orders:', error);
  }
}

// Update KOT Status
async function updateKOTStatus(kotId, status) {
  try {
    const response = await fetch('kot_operations.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: `action=update_kot_status&kotId=${kotId}&status=${status}`
    });
    
    const data = await response.json();
    
    if (data.success) {
      showNotification('KOT status updated successfully', 'success');
      loadKOTOrders(); // Reload KOT orders immediately
    } else {
      showNotification(data.message || 'Failed to update KOT status', 'error');
    }
  } catch (error) {
    console.error('Error updating KOT status:', error);
    showNotification('Error updating KOT status', 'error');
  }
}

// Make updateKOTStatus globally available
window.updateKOTStatus = updateKOTStatus;

// Complete KOT (move to Orders)
async function completeKOT(kotId) {
  try {
    const response = await fetch('kot_operations.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: `action=complete_kot&kotId=${kotId}`
    });
    
    const data = await response.json();
    
    if (data.success) {
      showNotification(`KOT completed! Order #${data.order_number} created.`, 'success');
      loadKOTOrders(); // Reload KOT orders immediately
    } else {
      showNotification(data.message || 'Failed to complete KOT', 'error');
    }
  } catch (error) {
    console.error('Error completing KOT:', error);
    showNotification('Error completing KOT', 'error');
  }
}

// Make completeKOT globally available
window.completeKOT = completeKOT;

// Cancel KOT
async function cancelKOT(kotId) {
  if (await showSweetConfirm("Are you sure you want to cancel this KOT?")) {
    try {
      const response = await fetch('kot_operations.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=update_kot_status&kotId=${kotId}&status=Cancelled`
      });
      
      const data = await response.json();
      
      if (data.success) {
        showNotification('KOT cancelled successfully', 'success');
        loadKOTOrders(); // Reload KOT orders immediately
      } else {
        showNotification(data.message || 'Failed to cancel KOT', 'error');
      }
    } catch (error) {
      console.error('Error cancelling KOT:', error);
      showNotification('Error cancelling KOT', 'error');
    }
  }
}

// Make cancelKOT globally available
window.cancelKOT = cancelKOT;

// Print KOT
window.printKOT = async function(kotId) {
  try {
    const res = await fetch('get_kot.php');
    const data = await res.json();
    if (!data.success) { showSweetAlert('Unable to load KOT'); return; }
    const kot = (data.kots || []).find(k => String(k.id) === String(kotId));
    if (!kot) { showSweetAlert('KOT not found'); return; }

    // Build printable HTML
    const now = new Date(kot.created_at);
    const dateStr = now.toLocaleString();
    const itemsHtml = (kot.items || []).map(it => `<tr><td><strong>${it.quantity}</strong> x ${escapeHtml(it.item_name)}${it.price ? ' @ ' + formatCurrency(parseFloat(it.price)) : ''}</td></tr>`).join('');
    const tableOrType = kot.table_name ? kot.table_name : kot.order_type;

    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>KOT ${kot.kot_number}</title>
      <style>
        body{font-family: Arial, sans-serif; padding: 8px;}
        .center{text-align:center}
        h2{margin:6px 0}
        .meta{font-size:12px;margin:4px 0}
        table{width:100%; border-collapse:collapse; margin-top:8px}
        td{padding:4px 0; font-size:14px}
        hr{border:none;border-top:1px dashed #333;margin:8px 0}
      </style>
    </head><body>
      <div class="center">
        <h2>KOT</h2>
        <div class="meta">${escapeHtml(kot.kot_number)}</div>
      </div>
      <div class="meta">${escapeHtml(tableOrType || '')}</div>
      <div class="meta">${dateStr}</div>
      <hr>
      <table>${itemsHtml}</table>
      <hr>
      <div class="meta">Items: ${(kot.items||[]).length}</div>
      <hr>
      <div class="meta" style="font-size:10px;color:#999;margin-top:12px;">Powered by Restro Grow</div></div>
    </body></html>`;

    showPrintPreview('KOT #' + kot.kot_number, html);
  } catch (e) {
    console.error('Print error', e);
    showSweetAlert('Failed to print KOT');
  }
};

  // --- Print Preview Helper ---
  // Shows an on-screen preview of the printable content before actually printing
  window.showPrintPreview = function(title, htmlContent) {
    var existing = document.getElementById('printPreviewOverlay');
    if (existing) existing.remove();
    
    var overlay = document.createElement('div');
    overlay.id = 'printPreviewOverlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.65);z-index:99999;display:flex;align-items:center;justify-content:center;animation:fadeIn 0.2s;';
    overlay.onclick = function(e) { if (e.target === overlay) overlay.remove(); };
    
    var modal = document.createElement('div');
    modal.style.cssText = 'background:#fff;border-radius:16px;width:92%;max-width:640px;height:100vh;max-height:100vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 25px 80px rgba(0,0,0,0.35);';
    
    var header = document.createElement('div');
    header.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb;background:#f9fafb;';
    var titleEl = document.createElement('h3');
    titleEl.textContent = title || 'Print Preview';
    titleEl.style.cssText = 'margin:0;font-size:16px;font-weight:700;color:#111827;';
    var closeBtn = document.createElement('button');
    closeBtn.innerHTML = 'X';
    closeBtn.style.cssText = 'width:32px;height:32px;border:none;border-radius:8px;background:#e5e7eb;cursor:pointer;font-size:16px;display:grid;place-items:center;color:#6b7280;transition:background 0.15s;';
    closeBtn.onmouseover = function() { closeBtn.style.background = '#d1d5db'; };
    closeBtn.onmouseout = function() { closeBtn.style.background = '#e5e7eb'; };
    closeBtn.onclick = function() { overlay.remove(); };
    header.appendChild(titleEl);
    header.appendChild(closeBtn);
    
    var content = document.createElement('div');
    content.style.cssText = 'flex:1;overflow:auto;padding:20px;background:#f3f4f6;display:flex;align-items:flex-start;justify-content:center;';
    
    var iframe = document.createElement('iframe');
    iframe.style.cssText = 'width:100%;height:100%;border:none;border-radius:8px;background:#fff;display:block;';
    content.appendChild(iframe);
    
    var footer = document.createElement('div');
    footer.style.cssText = 'display:flex;gap:10px;justify-content:flex-end;padding:14px 20px;border-top:1px solid #e5e7eb;background:#f9fafb;';
    
    var cancelBtn2 = document.createElement('button');
    cancelBtn2.textContent = 'Cancel';
    cancelBtn2.style.cssText = 'padding:10px 20px;border:1.5px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;font-weight:600;font-size:14px;color:#374151;transition:all 0.15s;';
    cancelBtn2.onmouseover = function() { cancelBtn2.style.background = '#f3f4f6'; };
    cancelBtn2.onmouseout = function() { cancelBtn2.style.background = '#fff'; };
    cancelBtn2.onclick = function() { overlay.remove(); };
    
    var printBtn = document.createElement('button');
    printBtn.innerHTML = 'Print';
    printBtn.style.cssText = 'padding:10px 24px;border:none;border-radius:8px;background:#059669;color:#fff;cursor:pointer;font-weight:600;font-size:14px;display:flex;align-items:center;transition:all 0.15s;';
    printBtn.onmouseover = function() { printBtn.style.background = '#047857'; };
    printBtn.onmouseout = function() { printBtn.style.background = '#059669'; };
    printBtn.onclick = function() {
      var pw = window.open('', '_blank', 'height=700,width=400');
      if (!pw) { showSweetAlert('Popup blocked. Allow popups to print.'); return; }
      pw.document.write(htmlContent);
      pw.document.close();
      pw.focus();
      setTimeout(function() { pw.print(); }, 300);
      overlay.remove();
    };
    
    footer.appendChild(cancelBtn2);
    footer.appendChild(printBtn);
    
    modal.appendChild(header);
    modal.appendChild(content);
    modal.appendChild(footer);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    
    var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
    iframeDoc.open();
    iframeDoc.write(htmlContent);
    iframeDoc.close();    // Fit receipt into preview at optimum zoom (no scrolling needed)
    setTimeout(function() {
      try {
        var bodyEl = iframeDoc.body;
        if (!bodyEl) return;
        
        var contentH = bodyEl.scrollHeight;
        var contentW = bodyEl.scrollWidth;
        var availW = iframe.clientWidth - 10;
        
        if (contentW > 0 && contentH > 0 && availH > 0) {
          var zoom = (availW / contentW) * 0.92;
          zoom = Math.max(0.6, Math.min(zoom, 2.0));
          
          var ps = iframeDoc.createElement('style');
          ps.textContent = 'body { zoom: ' + zoom + '; margin:8px auto; border:1px solid #d1d5db; box-shadow:0 4px 24px rgba(0,0,0,0.12); } @media print { body { zoom:1; border:none; box-shadow:none; margin:0 auto; } }';
          iframeDoc.head.appendChild(ps);
        }
      } catch(e) {}
    }, 200);
  };
  
// Print Order
window.printOrder = async function(orderId) {
  try {
    // Fetch order details
    const response = await fetch(`get_order_details_by_id.php?id=${orderId}`);
    const data = await response.json();
    
    if (!data.success || !data.order) {
      showSweetAlert('Unable to load order details');
      return;
    }
    
    const order = data.order;
    const items = Array.isArray(order.items) ? order.items : [];

    // Fetch restaurant info
    let restaurantInfo = {
      name: 'Restaurant Name',
      logo: '',
      address: '',
      phone: '',
      email: '',
      business_qr_code_path: ''
    };
    
    try {
      const infoRes = await fetch('admin/get_session.php');
      const infoData = await infoRes.json();
      if (infoData.success && infoData.data) {
        restaurantInfo.name = infoData.data.restaurant_name || restaurantInfo.name;
        restaurantInfo.logo = infoData.data.restaurant_logo || '';
        restaurantInfo.user_id = infoData.data.user_id || infoData.data.id || '';
        restaurantInfo.address = infoData.data.address || '';
        restaurantInfo.phone = infoData.data.phone || '';
        restaurantInfo.email = infoData.data.email || '';
        restaurantInfo.business_qr_code_path = infoData.data.business_qr_code_path || '';
      }
    } catch (e) {
      console.warn('Could not load restaurant info:', e);
    }

    // Build printable HTML
    const now = new Date(order.created_at);
    const dateStr = now.toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
    const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    
    const itemsHtml = items.map((it, idx) => {
      const itemTotal = (parseFloat(it.total_price) || 0).toFixed(2);
      return `
        <tr>
          <td style="padding: 8px 0; border-bottom: 1px dashed #e5e7eb;">
            <div style="font-weight: 600; font-size: 15px; color: #111827; margin-bottom: 4px;">${escapeHtml(it.item_name || it.name)}</div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <span style="color: #6b7280; font-size: 13px;">Qty: <strong style="color: #111827;">${it.quantity}</strong> × ${formatCurrency(parseFloat(it.price) || 0)}</span>
              <span style="font-weight: 600; color: #111827; font-size: 14px;">${formatCurrency(itemTotal)}</span>
            </div>
          </td>
        </tr>
      `;
    }).join('');
    
    const subtotal = parseFloat(order.subtotal || order.total || 0);
    const tax = parseFloat(order.tax || 0);
    const discount = parseFloat(order.discount || 0);
    const total = parseFloat(order.total || 0);
    
    const tableOrType = order.table_name || order.table_number ? `Table ${order.table_name || order.table_number}${order.area_name ? ' - ' + escapeHtml(order.area_name) : ''}` : (order.order_type || 'Walk-in');
    
    // Logo HTML
    let logoHtml = '';
    if (restaurantInfo.logo) {
      let logoPath;
      if (restaurantInfo.logo.startsWith('db:')) {
        const userId = restaurantInfo.user_id || restaurantInfo.id || '';
        logoPath = `api/image.php?type=logo&id=${userId}`;
      } else if (restaurantInfo.logo.startsWith('http')) {
        logoPath = restaurantInfo.logo;
      } else if (restaurantInfo.logo.startsWith('uploads/')) {
        logoPath = '../' + restaurantInfo.logo;
      } else {
        logoPath = '../uploads/' + restaurantInfo.logo;
      }
      logoHtml = `<img src="${logoPath}" alt="Logo" style="max-width: 80px; max-height: 80px; object-fit: contain; margin-bottom: 10px;" onerror="this.style.display='none';">`;
    }
    
    // Business QR Code HTML - only show if QR code exists
    let businessQRHtml = '';
    if (restaurantInfo.business_qr_code_path) {
      const userId = restaurantInfo.user_id || restaurantInfo.id || '';
      const qrCodeUrl = `api/image.php?type=business_qr&id=${userId}`;
      businessQRHtml = `
        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #e5e7eb; text-align: center;">
          <div style="font-size: 10px; color: #6b7280; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">Scan to Pay</div>
          <img src="${qrCodeUrl}" alt="Payment QR Code" style="max-width: 120px; max-height: 120px; object-fit: contain; border: 1px solid #e5e7eb; padding: 5px; background: white; display: block; margin: 0 auto;" onerror="this.style.display='none';">
        </div>
      `;
    }

    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Invoice #${order.order_number}</title>
      <style>
        @media print { @page { margin:6mm; size:80mm auto; } body { margin:0; padding:0; } }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Courier New', Courier, monospace; padding:10px; max-width:280px; margin:0 auto; background:#fff; color:#111827; font-size:11px; }
        .header { text-align:center; border-bottom:2px solid #111827; padding-bottom:8px; margin-bottom:8px; }
        .restaurant-name { font-size:16px; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; font-family:'Segoe UI',Tahoma,sans-serif; }
        .restaurant-details { font-size:9px; color:#6b7280; line-height:1.3; font-family:'Segoe UI',Tahoma,sans-serif; }
        .bill-meta { font-size:10px; margin:6px 0; }
        .info-line { display:flex; justify-content:space-between; padding:2px 0; font-size:10px; }
        .info-line .label { color:#6b7280; }
        .info-line .value { font-weight:700; color:#111827; text-align:right; }
        .pay-to { text-align:center; margin:6px 0; padding:4px 8px; border:1px dashed #9ca3af; font-size:10px; }
        .pay-to .label { color:#6b7280; font-size:9px; }
        .pay-to .name { font-weight:700; font-size:12px; color:#111827; }
        .items-section { margin:8px 0; }
        .items-header { display:flex; font-size:9px; font-weight:700; text-transform:uppercase; padding:4px 0; border-bottom:2px solid #111827; color:#111827; letter-spacing:0.5px; }
        .items-header .col-item { flex:1; }
        .items-header .col-qty-rate { width:80px; text-align:center; }
        .items-header .col-disc { width:45px; text-align:center; }
        .items-header .col-total { width:60px; text-align:right; }
        .item-row { display:flex; font-size:10px; padding:4px 0; border-bottom:1px dashed #d1d5db; }
        .item-row .col-item { flex:1; }
        .item-row .col-item .item-name { font-weight:600; font-size:11px; }
        .item-row .col-qty-rate { width:80px; text-align:center; font-size:9px; color:#6b7280; }
        .item-row .col-disc { width:45px; text-align:center; font-size:9px; color:#6b7280; }
        .item-row .col-total { width:60px; text-align:right; font-weight:600; font-size:11px; }
        .sep-line { border:none; border-top:1px dashed #9ca3af; margin:4px 0; }
        .totals { margin-top:4px; }
        .total-row { display:flex; justify-content:space-between; padding:2px 0; font-size:10px; }
        .total-row .label { color:#6b7280; }
        .total-row .value { font-weight:600; }
        .total-row.gross { border-top:1px solid #9ca3af; padding-top:3px; font-weight:700; font-size:11px; }
        .total-row.grand { font-size:14px; font-weight:800; border-top:2px solid #111827; padding-top:4px; margin-top:2px; }
        .payment-info { text-align:center; margin:6px 0; padding:4px; border:1px dashed #d1d5db; font-size:9px; }
        .footer { text-align:center; margin-top:8px; padding-top:6px; border-top:1px dashed #d1d5db; }
        .footer-text { font-size:9px; color:#6b7280; margin-bottom:1px; }
        .footer .powered { font-size:8px; color:#9ca3af; margin-top:3px; }
        .asterisk { text-align:center; color:#9ca3af; font-size:10px; margin:6px 0; }
      </style>
    </head><body>
      <div class="header">
        ${logoHtml}
        <div class="restaurant-name">${escapeHtml(restaurantInfo.name)}</div>
        ${restaurantInfo.address ? `<div class="restaurant-details">${escapeHtml(restaurantInfo.address)}</div>` : ''}
        ${restaurantInfo.phone ? `<div class="restaurant-details">Mob: ${escapeHtml(restaurantInfo.phone)}</div>` : ''}
      </div>

      <div class="bill-meta">
        <div class="info-line"><span class="label">${tableOrType.includes('Table') ? 'DineIn' : 'Order'}</span><span class="value">${escapeHtml(tableOrType)}</span></div>
        <div class="info-line"><span class="label">Date</span><span class="value">${dateStr} | ${timeStr}</span></div>
        <div class="info-line"><span class="label">Bill</span><span class="value">#${escapeHtml(order.order_number || order.id)}</span></div>
        <div class="info-line"><span class="label">Invoice</span><span class="value">#INV-${new Date(order.created_at).toISOString().split("T")[0].replace(/-/g, "")}-${escapeHtml(String(order.order_number || order.id))}</span></div>
        <div class="info-line"><span class="label">Order No</span><span class="value">#${escapeHtml(order.id || order.order_number)}</span></div>
      </div>

      <div class="pay-to">
        <div class="label">Pay To</div>
        <div class="name">${escapeHtml(restaurantInfo.name)}</div>
      </div>

      <hr class="sep-line">

      <div class="items-section">
        <div class="items-header">
          <span class="col-item">Items</span>
          <span class="col-qty-rate">Qty &amp; (Rate)</span>
          <span class="col-disc">Disc</span>
          <span class="col-total">Total</span>
        </div>
        ${itemsHtml}
      </div>

      <hr class="sep-line">

      <div class="totals">
        <div class="total-row gross">
          <span class="label">GROSS AMOUNT</span>
          <span class="value">${formatCurrency(subtotal + tax)}</span>
        </div>
        ${discount > 0 ? `
        <div class="total-row">
          <span class="label">Discount</span>
          <span class="value">-${formatCurrency(discount)}</span>
        </div>
        ` : ''}
        <div class="total-row grand">
          <span class="label">TOTAL AMOUNT</span>
          <span class="value">${formatCurrency(total)}</span>
        </div>
      </div>

      ${restaurantInfo.phone ? `
      <div class="asterisk">* * * * * * * * * * * * *</div>
      <div class="payment-info">
        <strong>Need help? Contact us:</strong><br>
        ${escapeHtml(restaurantInfo.phone)}
      </div>
      ` : ''}

      ${businessQRHtml}

      <div class="footer">
        <div class="footer-text">Thank you for visiting</div>
        <div class="powered">Powered by Restro Grow</div>
      </div>
    </body></html>`

    showPrintPreview('Invoice #' + order.order_number, html);
  } catch (e) {
    console.error('Print error', e);
    showSweetAlert('Failed to print order');
  }
};

// Cancel Order
window.cancelOrder = async function(orderId) {
  if (await showSweetConfirm("Are you sure you want to cancel this order?")) {
    updateKOTStatus(orderId, 'Cancelled');
  }
};

// Update Order Status
async function updateOrderStatus(orderId, status) {
  try {
    const response = await fetch('update_order_status.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: `orderId=${orderId}&status=${status}`
    });
    
    const data = await response.json();
    
    if (data.success) {
      loadOrders(); // Reload orders
    } else {
      showSweetAlert('Failed to update order status');
    }
  } catch (error) {
    console.error('Error updating order status:', error);
    showSweetAlert('Error updating order status');
  }
}

// (Optional code): Adjust sidebar height on window resize
window.addEventListener("resize", () => {
  if (window.innerWidth >= 1024) {
    sidebar.style.height = fullSidebarHeight;
  } else {
    sidebar.classList.remove("collapsed");
    sidebar.style.height = "auto";
    toggleMenu(sidebar.classList.contains("menu-active"));
  }
});

// Customer and Staff page filters and view toggles
document.addEventListener('DOMContentLoaded', function() {
  // Customer search
  const customerSearch = document.getElementById('customerSearch');
  if (customerSearch) {
    customerSearch.addEventListener('input', filterCustomers);
  }
  
  // Customer sort
  const customerSortBy = document.getElementById('customerSortBy');
  if (customerSortBy) {
    customerSortBy.addEventListener('change', filterCustomers);
  }
  
  // Customer view removed - using table layout only
  
  // Staff search
  const staffSearch = document.getElementById('staffSearch');
  if (staffSearch) {
    staffSearch.addEventListener('input', filterStaff);
  }
  
  // Staff sort
  const staffSortBy = document.getElementById('staffSortBy');
  if (staffSortBy) {
    staffSortBy.addEventListener('change', filterStaff);
  }
  
  // Staff view removed - using table layout only
});

// Filter customers
function filterCustomers() {
  const searchTerm = document.getElementById('customerSearch')?.value.toLowerCase() || '';
  const sortBy = document.getElementById('customerSortBy')?.value || 'name';
  const cards = document.querySelectorAll('#customerList .modern-card');
  
  cards.forEach(card => {
    const name = card.querySelector('h3')?.textContent.toLowerCase() || '';
    const phone = card.querySelector('.detail-row span:last-child')?.textContent.toLowerCase() || '';
    
    if (name.includes(searchTerm) || phone.includes(searchTerm)) {
      card.style.display = '';
    } else {
      card.style.display = 'none';
    }
  });
  
  // Sort cards
  const visibleCards = Array.from(cards).filter(card => card.style.display !== 'none');
  const container = document.getElementById('customerList');
  
  visibleCards.sort((a, b) => {
    if (sortBy === 'name') {
      const nameA = a.querySelector('h3')?.textContent || '';
      const nameB = b.querySelector('h3')?.textContent || '';
      return nameA.localeCompare(nameB);
    } else if (sortBy === 'visits') {
      const visitsA = parseInt(a.querySelector('.stat-value')?.textContent) || 0;
      const visitsB = parseInt(b.querySelector('.stat-value')?.textContent) || 0;
      return visitsB - visitsA;
    }
    return 0;
  });
  
  visibleCards.forEach(card => container.appendChild(card));
}

// Filter staff
function filterStaff() {
  const searchTerm = document.getElementById('staffSearch')?.value.toLowerCase() || '';
  const sortBy = document.getElementById('staffSortBy')?.value || 'name';
  const cards = document.querySelectorAll('#staffList .modern-card');
  
  cards.forEach(card => {
    const name = card.querySelector('h3')?.textContent.toLowerCase() || '';
    const email = card.querySelector('.detail-row span:last-child')?.textContent.toLowerCase() || '';
    const role = card.querySelectorAll('.detail-row')[2]?.querySelector('span:last-child')?.textContent.toLowerCase() || '';
    
    if (name.includes(searchTerm) || email.includes(searchTerm) || role.includes(searchTerm)) {
      card.style.display = '';
    } else {
      card.style.display = 'none';
    }
  });
  
  // Sort cards
  const visibleCards = Array.from(cards).filter(card => card.style.display !== 'none');
  const container = document.getElementById('staffList');
  
  visibleCards.sort((a, b) => {
    if (sortBy === 'name') {
      const nameA = a.querySelector('h3')?.textContent || '';
      const nameB = b.querySelector('h3')?.textContent || '';
      return nameA.localeCompare(nameB);
    } else if (sortBy === 'role') {
      const roleA = a.querySelectorAll('.detail-row')[2]?.querySelector('span:last-child')?.textContent || '';
      const roleB = b.querySelectorAll('.detail-row')[2]?.querySelector('span:last-child')?.textContent || '';
      return roleA.localeCompare(roleB);
    }
    return 0;
  });
  
  visibleCards.forEach(card => container.appendChild(card));
}
// Load Profile Data
async function loadProfileData() {
  try {
    // Load user session data
    const response = await fetch('admin/get_session.php');
    const result = await response.json();
    
    if (result.success) {
      const user = result.data;
      const username = user.username || 'User';
      const initials = username.split(' ').map(w => w.charAt(0).toUpperCase()).join('').substring(0, 2);
      
      // Update profile header
      document.getElementById('profileInitials').textContent = initials;
      document.getElementById('profileName').textContent = username;
      document.getElementById('profileRole').textContent = user.role || 'Administrator';
      document.getElementById('profileEmail').textContent = user.email || 'No email';
      document.getElementById('profileRestaurantName').textContent = user.restaurant_id || 'N/A';
      
      const joinDate = new Date(user.created_at || Date.now()).toLocaleDateString('en-US', { year: 'numeric', month: 'long' });
      document.getElementById('profileMemberSinceDate').textContent = joinDate;
      
      // Update restaurant logo if available
      if (user.restaurant_logo) {
        const logoImg = document.getElementById('profileRestaurantLogo');
        const initialsSpan = document.getElementById('profileInitials');
        let logoPath;
        if (user.restaurant_logo.startsWith('db:')) {
          // Database-stored image
          logoPath = `image.php?type=logo&id=${user.id || user.user_id || ''}`;
        } else if (user.restaurant_logo.startsWith('http')) {
          // External URL
          logoPath = user.restaurant_logo;
        } else if (user.restaurant_logo.startsWith('uploads/')) {
          // File-based image
          logoPath = user.restaurant_logo;
        } else {
          // Relative path
          logoPath = 'uploads/' + user.restaurant_logo;
        }
        logoImg.src = logoPath;
        logoImg.style.display = 'block';
        initialsSpan.style.display = 'none';
        
        // Also update dashboard logo
        const dashboardLogo = document.getElementById("dashboardRestaurantLogo");
        if (dashboardLogo) {
          dashboardLogo.src = logoPath;
          dashboardLogo.style.borderRadius = '50%';
          dashboardLogo.style.objectFit = 'cover';
          dashboardLogo.onerror = function() {
            this.src = 'assets/images/logo.png';
            this.style.borderRadius = '50%';
            this.style.objectFit = 'cover';
          };
        }
      } else {
        document.getElementById('profileRestaurantLogo').style.display = 'none';
        document.getElementById('profileInitials').style.display = 'block';
      }
      
      // Set form values
      document.getElementById('editUsername').value = username;
      document.getElementById('editEmail').value = user.email || '';
    }
  } catch (error) {
    console.error('Error loading profile data:', error);
    showNotification('Error loading profile data', 'error');
  }
}

// Toggle Profile Edit
function toggleProfileEdit() {
  const editCard = document.getElementById('editProfileCard');
  const editBtn = document.getElementById('editProfileBtn');
  
  if (editCard.style.display === 'none') {
    editCard.style.display = 'block';
    editBtn.textContent = 'Cancel Edit';
    editBtn.onclick = cancelProfileEdit;
    editBtn.classList.remove('btn-primary');
    editBtn.classList.add('btn-cancel');
  } else {
    cancelProfileEdit();
  }
}

// Cancel Profile Edit
function cancelProfileEdit() {
  const editCard = document.getElementById('editProfileCard');
  const editBtn = document.getElementById('editProfileBtn');
  
  editCard.style.display = 'none';
  editBtn.innerHTML = '<span class="material-symbols-rounded">edit</span> Edit Profile';
  editBtn.onclick = toggleProfileEdit;
  editBtn.classList.remove('btn-cancel');
  editBtn.classList.add('btn-primary');
  
  // Reset form values
  loadProfileData();
}

// Handle Edit Profile Form Submission
document.addEventListener('DOMContentLoaded', function() {
  const editProfileForm = document.getElementById('editProfileForm');
  if (editProfileForm) {
    editProfileForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      
      const username = document.getElementById('editUsername').value.trim();
      const email = document.getElementById('editEmail').value.trim();
      
      if (!username || !email) {
        showNotification('Please fill in all fields', 'error');
        return;
      }
      
      try {
        const response = await fetch('admin/auth.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: `action=updateProfile&username=${encodeURIComponent(username)}&email=${encodeURIComponent(email)}`
        });
        
        const result = await response.json();
        
        if (result.success) {
          showNotification('Profile updated successfully', 'success');
          cancelProfileEdit();
          loadProfileData();
        } else {
          showNotification(result.message || 'Error updating profile', 'error');
        }
      } catch (error) {
        console.error('Error updating profile:', error);
        showNotification('Error updating profile', 'error');
      }
    });
  }
  
});

// Setup Password Form Handler
function setupPasswordFormHandler() {
  const changePasswordForm = document.getElementById('changePasswordForm');
  if (!changePasswordForm) {
    return;
  }
  
  // Check if handler already attached
  if (changePasswordForm.dataset.handlerAttached === 'true') {
    return;
  }
  
  // Mark as attached
  changePasswordForm.dataset.handlerAttached = 'true';
  
  // Add listener
  changePasswordForm.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    // Get inputs from the form
    const currentPasswordInput = changePasswordForm.querySelector('#currentPassword');
    const newPasswordInput = changePasswordForm.querySelector('#newPassword');
    const confirmPasswordInput = changePasswordForm.querySelector('#confirmPassword');
    
    if (!currentPasswordInput || !newPasswordInput || !confirmPasswordInput) {
      showNotification('Form fields not found. Please refresh the page.', 'error');
      return;
    }
    
    const currentPassword = currentPasswordInput.value.trim();
    const newPassword = newPasswordInput.value.trim();
    const confirmPassword = confirmPasswordInput.value.trim();
    
    // Validate all fields are filled
    if (currentPassword === '' || newPassword === '' || confirmPassword === '') {
      showNotification('Please fill in all fields', 'error');
      // Highlight empty fields
      if (currentPassword === '') currentPasswordInput.focus();
      else if (newPassword === '') newPasswordInput.focus();
      else if (confirmPassword === '') confirmPasswordInput.focus();
      return;
    }
    
    if (newPassword.length < 6) {
      showNotification('New password must be at least 6 characters', 'error');
      newPasswordInput.focus();
      return;
    }
    
    if (newPassword !== confirmPassword) {
      showNotification('New passwords do not match', 'error');
      confirmPasswordInput.focus();
      return;
    }
    
    // Disable submit button to prevent double submission
    const submitBtn = changePasswordForm.querySelector('button[type="submit"]');
    const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Changing...';
    }
    
    try {
      const response = await fetch('admin/auth.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=changePassword&currentPassword=${encodeURIComponent(currentPassword)}&newPassword=${encodeURIComponent(newPassword)}`
      });
      
      // Check if response is JSON
      const contentType = response.headers.get('content-type');
      if (!contentType || !contentType.includes('application/json')) {
        const text = await response.text();
        throw new Error('Expected JSON but got: ' + text.substring(0, 100));
      }
      
      const result = await response.json();
      
      if (result.success) {
        showNotification('Password changed successfully', 'success');
        changePasswordForm.reset();
      } else {
        showNotification(result.message || 'Error changing password', 'error');
      }
    } catch (error) {
      console.error('Error changing password:', error);
      showNotification('Error changing password: ' + error.message, 'error');
    } finally {
      // Re-enable submit button
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
      }
    }
  });
}

// Load Payments
async function loadPayments() {
  console.log('Loading payments...');
  const tbody = document.getElementById('paymentsTableBody');
  if (!tbody) return;
  
  tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 2rem;"><div class="loading">Loading payments...</div></td></tr>';
  
  try {
    const search = document.getElementById('paymentSearch')?.value || '';
    const method = document.getElementById('paymentMethodFilter')?.value || '';
    const status = document.getElementById('paymentStatusFilter')?.value || '';
    
    const params = new URLSearchParams();
    if (search) params.append('search', search);
    if (method) params.append('method', method);
    if (status) params.append('status', status);
    
    const url = 'get_payments.php' + (params.toString() ? '?' + params.toString() : '');
    const response = await fetch(url);
    const data = await response.json();
    
    // Store for export
    window.currentPaymentsData = data.payments || [];
    
    console.log('Payments response:', data);
    
    if (data.success) {
      if (data.payments && data.payments.length > 0) {
        tbody.innerHTML = data.payments.map(payment => `
          <tr>
            <td>${payment.id}</td>
            <td><strong style="color: #48bb78;">${formatCurrency(payment.amount)}</strong></td>
            <td>
              <span style="background: ${
                payment.payment_method === 'Cash' ? '#48bb78' :
                payment.payment_method === 'UPI' ? '#667eea' :
                payment.payment_method === 'Card' ? '#f6ad55' : '#764ba2'
              }; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem;">
                ${payment.payment_method}
              </span>
            </td>
            <td style="color: #666;">${payment.transaction_id || '-'}</td>
            <td>
              <a href="#" onclick="showPage('ordersPage'); event.preventDefault();" style="color: #667eea; text-decoration: underline;">
                ${payment.order_number}
              </a>
            </td>
            <td>
              <span style="background: ${
                payment.payment_status === 'Success' ? '#48bb78' :
                payment.payment_status === 'Failed' ? '#f56565' :
                payment.payment_status === 'Pending' ? '#f6ad55' : '#999'
              }; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem;">
                ${payment.payment_status}
              </span>
            </td>
            <td style="color: #666;">${new Date(payment.created_at).toLocaleString('en-IN')}</td>
          </tr>
        `).join('');
      } else {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 2rem; color: #666;">No payments found</td></tr>';
        window.currentPaymentsData = [];
      }
    } else {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 2rem; color: red;">Error loading payments</td></tr>';
    }
  } catch (error) {
    console.error('Error loading payments:', error);
    tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 2rem; color: red;">Error loading payments</td></tr>';
  }
}

// Add payment filters
document.addEventListener('DOMContentLoaded', function() {
  const paymentSearch = document.getElementById('paymentSearch');
  const paymentMethodFilter = document.getElementById('paymentMethodFilter');
  const paymentStatusFilter = document.getElementById('paymentStatusFilter');
  
  if (paymentSearch) {
    paymentSearch.addEventListener('input', debounce(() => {
      loadPayments();
    }, 300));
  }
  
  if (paymentMethodFilter) {
    paymentMethodFilter.addEventListener('change', () => {
      loadPayments();
    });
  }
  
  if (paymentStatusFilter) {
    paymentStatusFilter.addEventListener('change', () => {
      loadPayments();
    });
  }
});

function debounce(func, wait) {
  let timeout;
  return function(...args) {
    clearTimeout(timeout);
    timeout = setTimeout(() => func.apply(this, args), wait);
  };
}

// Load Settings Data
async function loadSettingsData() {
  try {
    const response = await fetch('admin/get_session.php');
    const result = await response.json();
    
    if (result.success) {
      const user = result.data;
      
      // Restaurant Settings
      const restaurantNameSetting = document.getElementById('restaurantNameSetting');
      const restaurantIdSetting = document.getElementById('restaurantIdSetting');
      const restaurantEmail = document.getElementById('restaurantEmail');
      const restaurantPhone = document.getElementById('restaurantPhone');
      const restaurantAddress = document.getElementById('restaurantAddress');
      
      if (restaurantNameSetting) restaurantNameSetting.value = user.restaurant_name || '';
      if (restaurantIdSetting) restaurantIdSetting.value = user.restaurant_id || '';
      if (restaurantEmail) restaurantEmail.value = user.email || '';
      if (restaurantPhone) restaurantPhone.value = user.phone || '';
      if (restaurantAddress) restaurantAddress.value = user.address || '';
      const restaurantDescription = document.getElementById('restaurantDescription');
      if (restaurantDescription) restaurantDescription.value = user.description || '';
      // Load description format toggle
      const descFormatToggleSettings = document.getElementById('descFormatToggleSettings');
      const descFormatInputSettings = document.getElementById('descriptionFormatSettings');
      const descFmtPub = user.description_format || 'paragraph';
      if (descFormatInputSettings) descFormatInputSettings.value = descFmtPub;
      if (descFormatToggleSettings) {
        const lblPub = descFormatToggleSettings.querySelector('#descFormatLabelSettings');
        const icoPub = descFormatToggleSettings.querySelector('.material-symbols-rounded');
        if (descFmtPub === 'br') {
          descFormatToggleSettings.classList.add('active');
          if (lblPub) lblPub.textContent = 'Line Break';
          if (icoPub) icoPub.textContent = 'format_line_spacing';
        } else {
          descFormatToggleSettings.classList.remove('active');
          if (lblPub) lblPub.textContent = 'Paragraph';
          if (icoPub) icoPub.textContent = 'format_align_left';
        }
      }
      
      // Profile Settings
      const usernameSetting = document.getElementById('usernameSetting');
      const profileEmailSetting = document.getElementById('profileEmailSetting');
      
      if (usernameSetting) usernameSetting.value = user.username || '';
      if (profileEmailSetting) profileEmailSetting.value = user.email || '';
      
      // System Settings - Load from database (with localStorage fallback)
      // Only update if value is different from what PHP already set
      const currencySymbol = document.getElementById('currencySymbol');
      const timezone = document.getElementById('timezone');
      const autoSync = document.getElementById('autoSync');
      const notifications = document.getElementById('notifications');
      
      if (currencySymbol) {
        // DO NOT update - PHP already set it correctly (like restaurant logo/name)
        // The value is rendered server-side, so we never update it on page load
        // Only update if explicitly changed by user in settings form
      }
      if (timezone) {
        // Only update if not already set correctly
        const currentValue = timezone.value;
        if (!currentValue || currentValue === 'Asia/Kolkata') {
          timezone.value = user.timezone || localStorage.getItem('system_timezone') || 'Asia/Kolkata';
        }
      }
      if (autoSync) {
        autoSync.checked = localStorage.getItem('system_autoSync') === 'true';
      }
      if (notifications) {
        notifications.checked = localStorage.getItem('system_notifications') === 'true';
      }
    }
    
    setupSettingsForms();
  } catch (error) {
    console.error('Error loading settings data:', error);
  }
}

// Setup Settings Forms
function setupSettingsForms() {
  // Restaurant Settings Form
  const restaurantSettingsForm = document.getElementById('restaurantSettingsForm');
  if (restaurantSettingsForm && !restaurantSettingsForm.dataset.handlerAttached) {
    restaurantSettingsForm.dataset.handlerAttached = 'true';
    // Description format toggle for settings page
    var descToggleP = document.getElementById('descFormatToggleSettings');
    var descInputP = document.getElementById('descriptionFormatSettings');
    if (descToggleP && descInputP) {
      descToggleP.addEventListener('click', function() {
        var cur = descInputP.value;
        var lbl = descToggleP.querySelector('#descFormatLabelSettings');
        var ico = descToggleP.querySelector('.material-symbols-rounded');
        if (cur === 'paragraph') {
          descInputP.value = 'br';
          descToggleP.classList.add('active');
          if (lbl) lbl.textContent = 'Line Break';
          if (ico) ico.textContent = 'format_line_spacing';
        } else {
          descInputP.value = 'paragraph';
          descToggleP.classList.remove('active');
          if (lbl) lbl.textContent = 'Paragraph';
          if (ico) ico.textContent = 'format_align_left';
        }
      });
    }
    restaurantSettingsForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const restaurantName = document.getElementById('restaurantNameSetting').value.trim();
      const restaurantEmail = document.getElementById('restaurantEmail').value.trim();
      const restaurantPhone = document.getElementById('restaurantPhone').value.trim();
      const restaurantAddress = document.getElementById('restaurantAddress').value.trim();
      
      if (!restaurantName) {
        showNotification('Restaurant name is required', 'error');
        return;
      }
      
      // Disable submit button
      const submitBtn = restaurantSettingsForm.querySelector('button[type="submit"]');
      const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Saving...';
      }
      
      try {
        const response = await fetch('admin/auth.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: `action=updateRestaurantSettings&restaurant_name=${encodeURIComponent(restaurantName)}&email=${encodeURIComponent(restaurantEmail)}&phone=${encodeURIComponent(restaurantPhone)}&address=${encodeURIComponent(restaurantAddress)}&description=${encodeURIComponent(document.getElementById('restaurantDescription')?.value.trim() || '')}&description_format=${encodeURIComponent(document.getElementById('descriptionFormatSettings')?.value || 'paragraph')}`
        });
        
        const result = await response.json();
        
        if (result.success) {
          showNotification('Restaurant settings updated successfully!', 'success');
          // Reload restaurant info to update sidebar
          if (typeof loadRestaurantInfo === 'function') {
            loadRestaurantInfo();
          }
        } else {
          showNotification(result.message || 'Error updating restaurant settings', 'error');
        }
      } catch (error) {
        console.error('Error updating restaurant settings:', error);
        showNotification('Error updating restaurant settings', 'error');
      } finally {
        // Re-enable submit button
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalBtnText;
        }
      }
    });
  }
  
  // System Settings Form
  const systemSettingsForm = document.getElementById('systemSettingsForm');
  if (systemSettingsForm && !systemSettingsForm.dataset.handlerAttached) {
    systemSettingsForm.dataset.handlerAttached = 'true';
    systemSettingsForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const currencySymbol = document.getElementById('currencySymbol').value.trim();
      const timezone = document.getElementById('timezone').value.trim();
      const autoSync = document.getElementById('autoSync').checked;
      const notifications = document.getElementById('notifications').checked;
      
      // Disable submit button
      const submitBtn = systemSettingsForm.querySelector('button[type="submit"]');
      const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Saving...';
      }
      
      try {
        // Save to database
        const response = await fetch('admin/auth.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: `action=updateSystemSettings&currency_symbol=${encodeURIComponent(currencySymbol)}&timezone=${encodeURIComponent(timezone)}&auto_sync=${autoSync ? 1 : 0}&notifications=${notifications ? 1 : 0}`
        });
        
        const result = await response.json();
        
        if (result.success) {
          // Also save to localStorage as backup
          localStorage.setItem('system_currency', currencySymbol);
          localStorage.setItem('system_timezone', timezone);
          localStorage.setItem('system_autoSync', autoSync);
          localStorage.setItem('system_notifications', notifications);
          
          // Update global currency symbol
          globalCurrencySymbol = currencySymbol;
          
          // Update currency display in menu item form (only when user changes settings, not on page load)
          // PHP already sets this correctly, so only update if user explicitly changed it
          const currencyDisplay = document.getElementById('currencySymbolDisplay');
          if (currencyDisplay && !window.currencyFromServer) {
            // Only update if not loaded from server (shouldn't happen, but safety check)
            currencyDisplay.textContent = globalCurrencySymbol;
          }
          
          // Reload dashboard stats to update currency display
          if (typeof loadDashboardStats === 'function') {
            loadDashboardStats();
          }
          
          showNotification('System settings saved successfully!', 'success');
        } else {
          showNotification(result.message || 'Error updating system settings', 'error');
        }
      } catch (error) {
        console.error('Error updating system settings:', error);
        showNotification('Error updating system settings', 'error');
      } finally {
        // Re-enable submit button
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalBtnText;
        }
      }
    });
  }
  
  // Profile Settings Form
  const profileSettingsForm = document.getElementById('profileSettingsForm');
  if (profileSettingsForm && !profileSettingsForm.dataset.handlerAttached) {
    profileSettingsForm.dataset.handlerAttached = 'true';
    profileSettingsForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const username = document.getElementById('usernameSetting').value.trim();
      const email = document.getElementById('profileEmailSetting').value.trim();
      const emailNotifications = document.getElementById('emailNotifications').checked;
      
      if (!username || !email) {
        showNotification('Username and email are required', 'error');
        return;
      }
      
      // Disable submit button
      const submitBtn = profileSettingsForm.querySelector('button[type="submit"]');
      const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Updating...';
      }
      
      try {
        const response = await fetch('admin/auth.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: `action=updateProfile&username=${encodeURIComponent(username)}&email=${encodeURIComponent(email)}`
        });
        
        const result = await response.json();
        
        if (result.success) {
          showNotification('Profile updated successfully!', 'success');
          // Reload restaurant info to update sidebar
          if (typeof loadRestaurantInfo === 'function') {
            loadRestaurantInfo();
          }
        } else {
          showNotification(result.message || 'Error updating profile', 'error');
        }
      } catch (error) {
        console.error('Error updating profile:', error);
        showNotification('Error updating profile', 'error');
      } finally {
        // Re-enable submit button
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalBtnText;
        }
      }
    });
  }
}

// Load Reports Data
async function loadReports() {
  try {
    const period = document.getElementById('reportPeriod')?.value || 'today';
    const reportType = document.getElementById('reportType')?.value || 'sales';
    console.log('Loading reports for period:', period, 'type:', reportType);
    
    const response = await fetch(`get_sales_report.php?period=${period}&type=${reportType}`);
    const data = await response.json();
    
    if (!data.success) {
      throw new Error(data.message || 'Failed to load reports');
    }
    
    console.log('Reports data received:', data);
    
    // Store data for export
    window.currentReportData = data;
    
    // Update summary cards
    const totalSalesEl = document.getElementById('reportTotalSales');
    const totalOrdersEl = document.getElementById('reportTotalOrders');
    const totalItemsEl = document.getElementById('reportTotalItems');
    const totalCustomersEl = document.getElementById('reportTotalCustomers');
    
    if (totalSalesEl) {
      totalSalesEl.textContent = formatCurrencyLocale(data.summary.total_sales);
    }
    if (totalOrdersEl) {
      totalOrdersEl.textContent = data.summary.total_orders;
    }
    if (totalItemsEl) {
      totalItemsEl.textContent = data.summary.total_items;
    }
    if (totalCustomersEl) {
      totalCustomersEl.textContent = data.summary.total_customers;
    }
    
    // Update sales table
    const salesTable = document.getElementById('reportSalesTable');
    if (salesTable) {
      if (data.sales_details && data.sales_details.length > 0) {
        salesTable.innerHTML = data.sales_details.map(order => `
          <tr style="border-bottom: 1px solid #eee;">
            <td style="padding: 1rem;">${new Date(order.created_at).toLocaleDateString('en-IN')}</td>
            <td style="padding: 1rem; font-weight: 600;">${order.order_number}</td>
            <td style="padding: 1rem;">${order.customer_name}</td>
            <td style="padding: 1rem;">${order.item_count}</td>
            <td style="padding: 1rem;"><span style="background: #e5f3ff; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">${order.payment_method}</span></td>
            <td style="padding: 1rem; text-align: right; font-weight: 600; color: var(--primary-red);">₹${parseFloat(order.total).toLocaleString('en-IN', {maximumFractionDigits: 2})}</td>
          </tr>
        `).join('');
      } else {
        salesTable.innerHTML = '<tr><td colspan="6" style="padding: 2rem; text-align: center; color: #666;">No sales data found</td></tr>';
      }
    }
    
    // Update top items
    const topItemsDiv = document.getElementById('reportTopItems');
    if (topItemsDiv) {
      if (data.top_items && data.top_items.length > 0) {
        topItemsDiv.innerHTML = data.top_items.map((item, index) => `
          <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; border-bottom: 1px solid #eee;">
            <div>
              <div style="font-weight: 600;">${index + 1}. ${item.item_name}</div>
              <div style="font-size: 0.85rem; color: #666;">Qty: ${item.total_quantity}</div>
            </div>
            <div style="font-weight: 700; color: var(--primary-red);">₹${parseFloat(item.total_revenue).toLocaleString('en-IN', {maximumFractionDigits: 2})}</div>
          </div>
        `).join('');
      } else {
        topItemsDiv.innerHTML = '<div style="text-align: center; padding: 2rem; color: #666;">No items found</div>';
      }
    }
    
    // Update payment methods
    const paymentMethodsDiv = document.getElementById('reportPaymentMethods');
    if (paymentMethodsDiv) {
      if (data.payment_methods && data.payment_methods.length > 0) {
        paymentMethodsDiv.innerHTML = data.payment_methods.map(method => `
          <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; border-bottom: 1px solid #eee;">
            <div>
              <div style="font-weight: 600;">${method.payment_method}</div>
              <div style="font-size: 0.85rem; color: #666;">${method.count} orders</div>
            </div>
            <div style="font-weight: 700; color: var(--primary-red);">₹${parseFloat(method.amount).toLocaleString('en-IN', {maximumFractionDigits: 2})}</div>
          </div>
        `).join('');
      } else {
        paymentMethodsDiv.innerHTML = '<div style="text-align: center; padding: 2rem; color: #666;">No payment data found</div>';
      }
    }
    
  console.log('Reports loaded successfully');
  
} catch (error) {
  console.error('Error loading reports:', error);
  showSweetAlert('Failed to load reports: ' + error.message);
}
}

// Export Reports to CSV
function exportReportsToCSV() {
  if (!window.currentReportData) {
    showSweetAlert('No data to export. Please load reports first.');
    return;
  }
  
  const data = window.currentReportData;
  let csv = '';
  
  // Add summary
  csv += 'Sales Report Summary\n';
  csv += `Total Sales,${data.summary.total_sales}\n`;
  csv += `Total Orders,${data.summary.total_orders}\n`;
  csv += `Items Sold,${data.summary.total_items}\n`;
  csv += `Total Customers,${data.summary.total_customers}\n\n`;
  
  // Add sales details
  csv += 'Order Details\n';
  csv += 'Order Number,Customer,Items,Payment Method,Amount,Date\n';
  
  if (data.sales_details && data.sales_details.length > 0) {
    data.sales_details.forEach(order => {
      csv += `"${order.order_number}","${order.customer_name}",${order.item_count},"${order.payment_method}",${order.total},"${new Date(order.created_at).toLocaleDateString()}"\n`;
    });
  }
  
  // Download CSV
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const link = document.createElement('a');
  const url = URL.createObjectURL(blob);
  link.setAttribute('href', url);
  link.setAttribute('download', `sales_report_${new Date().toISOString().split('T')[0]}.csv`);
  link.style.visibility = 'hidden';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  
  showNotification('Report exported successfully!', 'success');
}

// Setup reports page listener
document.addEventListener('DOMContentLoaded', function() {
  const reportsLink = document.querySelector('[data-page="reportsPage"]');
  if (reportsLink) {
    reportsLink.addEventListener('click', function() {
      setTimeout(() => {
        if (document.getElementById('reportsPage')?.classList.contains('active')) {
          loadReports();
        }
      }, 100);
    });
  }
});

// Website theme (DB-based via API)
let websiteThemeInitialized = false; // Flag to prevent duplicate initialization


// Live preview device toggle and refresh (defined globally so always available)
window.setPreviewDevice = function(device) {
  const wrapper = document.getElementById('previewFrameWrapper');
  const btns = document.querySelectorAll('.device-btn');
  btns.forEach(b => {
    b.style.borderColor = '#e5e7eb';
    b.style.background = '#fff';
    b.style.color = '#111827';
  });
  const active = document.querySelector(`.device-btn[data-device="${device}"]`);
  if (active) {
    active.style.borderColor = '#dc2626';
    active.style.background = '#dc2626';
    active.style.color = '#fff';
  }
  if (wrapper) {
    if (device === 'mobile') {
      wrapper.classList.add('mobile-view');
    } else {
      wrapper.classList.remove('mobile-view');
    }
  }
};
window.refreshPreview = function() {
  const iframe = document.getElementById('livePreview');
  if (iframe) {
    const src = iframe.src;
    iframe.src = '';
    setTimeout(() => { iframe.src = src; }, 100);
  }
};

async function initWebsiteThemeEditor() {
  // Prevent duplicate initialization
  if (websiteThemeInitialized) {
    console.log('Website theme editor already initialized, skipping...');
    return;
  }
  
  try {
    const sess = await fetch('admin/get_session.php').then(r=>r.json()).catch(()=>null);
    const rid = (sess && sess.success && sess.data?.restaurant_id) ? sess.data.restaurant_id : '';
    const pr = document.getElementById('primaryRed');
    const dr = document.getElementById('darkRed');
    const py = document.getElementById('primaryYellow');
    const bannerUpload = document.getElementById('bannerUpload');
    const uploadBannerBtn = document.getElementById('uploadBannerBtn');
    
    // Function to render banners grid
    const renderBanners = (banners) => {
      console.log('renderBanners called with:', banners);
      // Always get fresh reference to element
      const bannersGrid = document.getElementById('bannersGrid');
      if (!bannersGrid) {
        console.warn('BannersGrid element not found, retrying...');
        // Retry after a short delay
        setTimeout(() => {
          const retryGrid = document.getElementById('bannersGrid');
          if (retryGrid) {
            console.log('BannersGrid found on retry, rendering...');
            renderBanners(banners); // Recursive call once element is found
          } else {
            console.error('BannersGrid element still not found after retry');
          }
        }, 300);
        return;
      }
      
      console.log('BannersGrid found, rendering banners...');
      bannersGrid.innerHTML = '';
      
      if (!banners || banners.length === 0) {
        bannersGrid.innerHTML = '<p style="color:#666;grid-column:1/-1;text-align:center;padding:20px;">No banners uploaded yet</p>';
        console.log('No banners to render');
        return;
      }
      
      console.log('Rendering', banners.length, 'banners');
      
      banners.forEach((banner, index) => {
        console.log('Rendering banner:', banner);
        let imagePath;
        if (banner.banner_path.startsWith('db:')) {
          // Database-stored banner
          imagePath = `image.php?type=banner&id=${banner.id}`;
        } else if (banner.banner_path.startsWith('http')) {
          // External URL
          imagePath = banner.banner_path;
        } else {
          // File-based image (backward compatibility)
          imagePath = banner.banner_path;
        }
        console.log('Image path:', imagePath);
        const bannerCard = document.createElement('div');
        bannerCard.setAttribute('draggable', 'true');
        bannerCard.setAttribute('data-banner-id', banner.id);
        bannerCard.setAttribute('data-banner-index', index);
        bannerCard.className = 'banner-card-draggable';
        bannerCard.style.cssText = 'position:relative;border:2px solid #ddd;border-radius:12px;overflow:hidden;background:#f9f9f9;box-shadow:0 2px 8px rgba(0,0,0,0.1);cursor:move;transition:all 0.3s ease;';
        bannerCard.innerHTML = `
          <div style="position:absolute;top:8px;left:8px;background:rgba(0,0,0,0.6);color:white;padding:4px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;z-index:10;pointer-events:none;">
            <span class="material-symbols-rounded" style="font-size:16px;vertical-align:middle;">drag_indicator</span>
            Drag to reorder
          </div>
          <img src="${imagePath}" alt="Banner" onerror="console.error('Image failed to load:', '${imagePath}'); this.style.display='none'; this.nextElementSibling.style.display='flex';" onload="console.log('Image loaded successfully:', '${imagePath}');" style="width:100%;height:auto;display:block;max-height:300px;min-height:200px;object-fit:cover;background:#f0f0f0;pointer-events:none;">
          <div style="display:none;width:100%;height:200px;align-items:center;justify-content:center;background:#f0f0f0;color:#999;flex-direction:column;">
            <span class="material-symbols-rounded" style="font-size:48px;margin-bottom:10px;">image_not_supported</span>
            <span>Image not found</span>
            <small style="margin-top:5px;color:#bbb;">${imagePath}</small>
          </div>
          <button class="delete-banner-btn" data-id="${banner.id}" draggable="false" style="position:absolute;top:8px;right:8px;background:rgba(220,38,38,0.9);color:white;border:none;border-radius:50%;width:36px;height:36px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background 0.3s;box-shadow:0 2px 6px rgba(0,0,0,0.2);z-index:10;">
            <span class="material-symbols-rounded" style="font-size:20px;">delete</span>
          </button>
          <div style="padding:12px;font-size:0.9rem;color:#666;text-align:center;background:#fff;border-top:1px solid #eee;">Order: ${banner.display_order !== null && banner.display_order !== undefined ? banner.display_order : index + 1}</div>
        `;
        bannersGrid.appendChild(bannerCard);
      });
      
      // Add delete button listeners
      document.querySelectorAll('.delete-banner-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
          e.stopPropagation(); // Prevent drag when clicking delete
          const bannerId = e.currentTarget.getAttribute('data-id');
          if (!(await showSweetConfirm('Are you sure you want to delete this banner?'))) return;
          
          try {
            e.currentTarget.disabled = true;
            const sq = rid ? `?action=delete_banner&banner_id=${bannerId}&restaurant_id=${encodeURIComponent(rid)}` : `?action=delete_banner&banner_id=${bannerId}`;
            const formData = new FormData();
            formData.append('banner_id', bannerId);
            const res = await fetch(`website/theme_api.php${sq}`, { method:'POST', body: formData });
            const data = await res.json();
            
            if (data.success) {
              showNotification('Banner deleted successfully', 'success');
              loadBanners();
            } else {
              showNotification(data.message || 'Failed to delete banner', 'error');
            }
          } catch (err) {
            showNotification('Network error. Please try again.', 'error');
          }
        });
      });
      
      // Add drag and drop functionality
      let draggedElement = null;
      let draggedIndex = null;
      
      document.querySelectorAll('.banner-card-draggable').forEach((card, index) => {
        // Drag start
        card.addEventListener('dragstart', (e) => {
          // Don't start drag if clicking on delete button
          if (e.target.closest('.delete-banner-btn')) {
            e.preventDefault();
            return;
          }
          draggedElement = card;
          draggedIndex = index;
          card.style.opacity = '0.5';
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/html', card.innerHTML);
        });
        
        // Drag end
        card.addEventListener('dragend', (e) => {
          card.style.opacity = '1';
          // Remove all drag-over classes
          document.querySelectorAll('.banner-card-draggable').forEach(c => {
            c.style.border = '2px solid #ddd';
            c.style.transform = 'scale(1)';
          });
        });
        
        // Drag over
        card.addEventListener('dragover', (e) => {
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';
          
          if (draggedElement && card !== draggedElement) {
            const cards = Array.from(bannersGrid.querySelectorAll('.banner-card-draggable'));
            const draggedIndex = cards.indexOf(draggedElement);
            const targetIndex = cards.indexOf(card);
            
            if (draggedIndex < targetIndex) {
              // Dragging down
              card.style.borderTop = '3px solid #4CAF50';
              card.style.borderBottom = '2px solid #ddd';
            } else {
              // Dragging up
              card.style.borderBottom = '3px solid #4CAF50';
              card.style.borderTop = '2px solid #ddd';
            }
            card.style.transform = 'scale(1.02)';
          }
        });
        
        // Drag leave
        card.addEventListener('dragleave', (e) => {
          card.style.border = '2px solid #ddd';
          card.style.transform = 'scale(1)';
        });
        
        // Drop
        card.addEventListener('drop', async (e) => {
          e.preventDefault();
          e.stopPropagation();
          
          if (draggedElement && card !== draggedElement) {
            const cards = Array.from(bannersGrid.querySelectorAll('.banner-card-draggable'));
            const draggedIndex = cards.indexOf(draggedElement);
            const targetIndex = cards.indexOf(card);
            
            // Reorder in DOM
            if (draggedIndex < targetIndex) {
              bannersGrid.insertBefore(draggedElement, card.nextSibling);
            } else {
              bannersGrid.insertBefore(draggedElement, card);
            }
            
            // Get new order of banner IDs
            const newOrder = Array.from(bannersGrid.querySelectorAll('.banner-card-draggable')).map(c => 
              parseInt(c.getAttribute('data-banner-id'))
            );
            
            // Update order in database
            try {
              const sq = rid ? `?action=reorder_banners&restaurant_id=${encodeURIComponent(rid)}` : '?action=reorder_banners';
              const res = await fetch(`website/theme_api.php${sq}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ banner_ids: newOrder })
              });
              const data = await res.json();
              
              if (data.success) {
                showNotification('Banner order updated successfully', 'success');
                // Update display order numbers
                const updatedCards = Array.from(bannersGrid.querySelectorAll('.banner-card-draggable'));
                updatedCards.forEach((c, idx) => {
                  const orderDiv = c.querySelector('div[style*="Order:"]');
                  if (orderDiv) {
                    orderDiv.textContent = `Order: ${idx + 1}`;
                  }
                });
              } else {
                showNotification(data.message || 'Failed to update banner order', 'error');
                // Reload to restore original order
                loadBanners();
              }
            } catch (err) {
              showNotification('Network error. Please try again.', 'error');
              // Reload to restore original order
              loadBanners();
            }
          }
          
          // Reset styles
          card.style.border = '2px solid #ddd';
          card.style.transform = 'scale(1)';
        });
      });
    };
    
    // Function to load banners
    const loadBanners = async () => {
      try {
        const q = rid ? `?action=get_banners&restaurant_id=${encodeURIComponent(rid)}` : '?action=get_banners';
        console.log('Loading banners from:', `website/theme_api.php${q}`);
        const res = await fetch(`website/theme_api.php${q}`);
        const data = await res.json();
        console.log('Banners API response:', data);
        if (data.success) {
          console.log('Banners found:', data.banners?.length || 0);
          renderBanners(data.banners || []);
        } else {
          console.warn('Banners API returned success=false:', data);
          renderBanners([]);
        }
      } catch (e) {
        console.error('Error loading banners:', e);
        renderBanners([]);
      }
    };
    
    // Ensure preview section is visible
    const bannersPreview = document.getElementById('bannersPreview');
    if (bannersPreview) {
      bannersPreview.style.display = 'block';
    }
    
    // Function to update color previews
    const updateColorPreviews = () => {
      const primaryRedVal = pr ? pr.value : '#F70000';
      const darkRedVal = dr ? dr.value : '#DA020E';
      const primaryYellowVal = py ? py.value : '#FFD100';
      
      // Update color value displays
      const primaryRedDisplay = document.getElementById('primaryRedDisplay');
      const darkRedDisplay = document.getElementById('darkRedDisplay');
      const primaryYellowDisplay = document.getElementById('primaryYellowDisplay');
      
      if (primaryRedDisplay) primaryRedDisplay.textContent = primaryRedVal;
      if (darkRedDisplay) darkRedDisplay.textContent = darkRedVal;
      if (primaryYellowDisplay) primaryYellowDisplay.textContent = primaryYellowVal;
      
      // Update hero section gradient
      const heroPreview = document.getElementById('heroPreview');
      if (heroPreview) {
        heroPreview.style.background = `linear-gradient(135deg, ${primaryRedVal} 0%, ${darkRedVal} 100%)`;
      }
      
      // Update category button
      const categoryButtonPreview = document.getElementById('categoryButtonPreview');
      if (categoryButtonPreview) {
        categoryButtonPreview.style.borderColor = primaryRedVal;
        categoryButtonPreview.style.color = primaryRedVal;
      }
      
      // Update add to cart button
      const addToCartPreview = document.getElementById('addToCartPreview');
      if (addToCartPreview) {
        addToCartPreview.style.background = primaryYellowVal;
      }
      
      // Update checkout button
      const checkoutPreview = document.getElementById('checkoutPreview');
      if (checkoutPreview) {
        checkoutPreview.style.background = primaryRedVal;
      }
    };
    
    // Add event listeners to color inputs for real-time preview
    if (pr) pr.addEventListener('input', updateColorPreviews);
    if (dr) dr.addEventListener('input', updateColorPreviews);
    if (py) py.addEventListener('input', updateColorPreviews);
    
    // Load theme settings and banners
    const q = rid ? `?action=get&restaurant_id=${encodeURIComponent(rid)}` : '?action=get';
    const theme = await fetch(`website/theme_api.php${q}`).then(r=>r.json()).catch(()=>null);
    if (theme && theme.success && theme.settings) {
      if (pr) pr.value = theme.settings.primary_red || '#F70000';
      if (dr) dr.value = theme.settings.dark_red || '#DA020E';
      if (py) py.value = theme.settings.primary_yellow || '#FFD100';
      
      // Load layout columns setting
      var lcRadios = document.querySelectorAll('input[name="layoutColumns"]');
      var lcVal = parseInt(theme.settings.layout_columns) || 2;
      for (var ri = 0; ri < lcRadios.length; ri++) {
        if (parseInt(lcRadios[ri].value) === lcVal) lcRadios[ri].checked = true;
      }
      
      // Update previews with loaded values
      
      // Set logo shape
      if (theme.settings.logo_shape) {
        var shapeRadios = document.querySelectorAll('input[name="logoShape"]');
        for (var si = 0; si < shapeRadios.length; si++) {
          if (shapeRadios[si].value === theme.settings.logo_shape) {
            shapeRadios[si].checked = true;
            // Update parent label styling
            var parentLabels = document.querySelectorAll('.logo-shape-btn');
            for (var pi = 0; pi < parentLabels.length; pi++) {
              if (parentLabels[pi].dataset.shape === theme.settings.logo_shape) {
                parentLabels[pi].style.borderColor = '#dc2626';
                parentLabels[pi].style.background = '#dc2626';
                parentLabels[pi].style.color = '#fff';
              } else {
                parentLabels[pi].style.borderColor = '#e5e7eb';
                parentLabels[pi].style.background = '#fff';
                parentLabels[pi].style.color = '#374151';
              }
            }
            break;
          }
        }
      }
      // Set logo size
      if (theme.settings.logo_size) {
        var sizeSlider = document.getElementById('logoSizeSlider');
        var sizeDisplay = document.getElementById('logoSizeDisplay');
        if (sizeSlider) {
          sizeSlider.value = theme.settings.logo_size;
          var pct = ((theme.settings.logo_size - 50) / 100) * 100;
          sizeSlider.style.background = 'linear-gradient(to right, #dc2626 0%, #dc2626 ' + pct + '%, #e5e7eb ' + pct + '%, #e5e7eb 100%)';
        }
        if (sizeDisplay) {
          sizeDisplay.textContent = theme.settings.logo_size;
        }
      }
      updateColorPreviews();
      
      // Always load banners from API to ensure latest data
      // Small delay to ensure page is fully rendered
      setTimeout(() => {
        loadBanners();
      }, 100);
    } else {
      // Update previews with default values
      updateColorPreviews();
      
      // Even if theme fails, try to load banners
      setTimeout(() => {
        loadBanners();
      }, 100);
    }
    
    // Save colors
    const saveBtn = document.getElementById('saveWebsiteThemeBtn');
    if (saveBtn) saveBtn.addEventListener('click', async () => {
      var lcRadios = document.querySelectorAll('input[name="layoutColumns"]');
      var lcVal = 2;
      for (var ri = 0; ri < lcRadios.length; ri++) {
        if (lcRadios[ri].checked) { lcVal = parseInt(lcRadios[ri].value); break; }
      }
      // Get logo shape
      var lsRadios = document.querySelectorAll('input[name="logoShape"]');
      var logoShapeVal = 'circle';
      for (var si = 0; si < lsRadios.length; si++) {
        if (lsRadios[si].checked) { logoShapeVal = lsRadios[si].value; break; }
      }
      // Get logo size
      var logoSizeSlider = document.getElementById('logoSizeSlider');
      var logoSizeVal = logoSizeSlider ? parseInt(logoSizeSlider.value) : 90;
      const payload = { primary_red: pr.value, dark_red: dr.value, primary_yellow: py.value, layout_columns: lcVal, logo_shape: logoShapeVal, logo_size: logoSizeVal };
      const sq = rid ? `?action=save&restaurant_id=${encodeURIComponent(rid)}` : '?action=save';
      const res = await fetch(`website/theme_api.php${sq}`, { method:'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
      const data = await res.json();
      if (data.success) {
        showNotification('Theme saved', 'success');
        // Update previews after saving
        updateColorPreviews();
      } else {
        showNotification(data.message||'Error','error');
      }
    });
    
    // Upload banners - Remove old listener if exists and add new one
    if (uploadBannerBtn) {
      // Clone button to remove all event listeners
      const newUploadBtn = uploadBannerBtn.cloneNode(true);
      uploadBannerBtn.parentNode.replaceChild(newUploadBtn, uploadBannerBtn);
      
      newUploadBtn.addEventListener('click', async () => {
      if (!bannerUpload || !bannerUpload.files || bannerUpload.files.length === 0) {
        showNotification('Please select at least one image file', 'error');
        return;
      }
      
      const formData = new FormData();
      // Handle multiple files - append each file with same name, PHP will create array
      Array.from(bannerUpload.files).forEach((file) => {
        formData.append('banners[]', file);
      });
      
      const sq = rid ? `?action=upload_banner&restaurant_id=${encodeURIComponent(rid)}` : '?action=upload_banner';
      
      try {
        uploadBannerBtn.disabled = true;
        uploadBannerBtn.innerHTML = '<span class="material-symbols-rounded">upload</span>Uploading...';
        const res = await fetch(`website/theme_api.php${sq}`, { method:'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
          const count = data.banners ? data.banners.length : 1;
          showNotification(`${count} banner(s) uploaded successfully`, 'success');
          bannerUpload.value = '';
          loadBanners();
        } else {
          showNotification(data.message || 'Upload failed', 'error');
        }
      } catch (e) {
        showNotification('Network error. Please try again.', 'error');
      } finally {
        newUploadBtn.disabled = false;
        newUploadBtn.innerHTML = '<span class="material-symbols-rounded">upload</span>Upload Banners';
      }
      });
    }
    
    // Mark as initialized
    websiteThemeInitialized = true;
  } catch (e) { console.error('Theme init error', e); }
}

document.addEventListener('DOMContentLoaded', function(){
  // Check if websiteThemePage is already active on load (e.g., if page is refreshed)
  const websiteThemePage = document.getElementById('websiteThemePage');
  if (websiteThemePage && websiteThemePage.classList.contains('active')) {
    setTimeout(() => {
      initWebsiteThemeEditor();
    }, 300);
  }
});

// Restaurant Logo Upload Functions
let selectedLogoFile = null;

function openLogoUploadModal() {
  const modal = document.getElementById('logoUploadModal');
  if (modal) {
    modal.style.display = 'flex';
    selectedLogoFile = null;
    document.getElementById('logoFileInput').value = '';
    document.getElementById('saveLogoBtn').disabled = true;
    // Reset preview
    const preview = document.getElementById('logoPreview');
    preview.innerHTML = '<span class="material-symbols-rounded" style="font-size:3rem;color:#9ca3af;">image</span>';
    preview.style.background = '#f3f4f6';
    preview.style.border = '3px dashed #d1d5db';
  }
}

function closeLogoUploadModal() {
  const modal = document.getElementById('logoUploadModal');
  if (modal) {
    modal.style.display = 'none';
    selectedLogoFile = null;
    document.getElementById('logoFileInput').value = '';
  }
}

function handleLogoFileSelect(event) {
  const file = event.target.files[0];
  if (!file) return;
  
  // Validate file type
  if (!file.type.startsWith('image/')) {
    showNotification('Please select a valid image file', 'error');
    return;
  }
  
  // Validate file size (2MB max)
  if (file.size > 2 * 1024 * 1024) {
    showNotification('Image size must be less than 2MB', 'error');
    return;
  }
  
  selectedLogoFile = file;
  
  // Show preview
  const reader = new FileReader();
  reader.onload = function(e) {
    const preview = document.getElementById('logoPreview');
    preview.innerHTML = `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" alt="Logo Preview">`;
    preview.style.background = 'transparent';
    preview.style.border = 'none';
    document.getElementById('saveLogoBtn').disabled = false;
  };
  reader.readAsDataURL(file);
}

async function uploadRestaurantLogo() {
  if (!selectedLogoFile) {
    showNotification('Please select an image first', 'error');
    return;
  }
  
  const formData = new FormData();
  formData.append('action', 'uploadRestaurantLogo');
  formData.append('logo', selectedLogoFile);
  
  const saveBtn = document.getElementById('saveLogoBtn');
  const originalText = saveBtn.innerHTML;
  saveBtn.disabled = true;
  saveBtn.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Uploading...';
  
  try {
    const response = await fetch('admin/auth.php', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      showNotification('Restaurant logo updated successfully', 'success');
      closeLogoUploadModal();
      // Refresh the page after a short delay to show the new logo
      setTimeout(() => {
        window.location.reload();
      }, 1000);
    } else {
      showNotification(result.message || 'Failed to upload logo', 'error');
      saveBtn.disabled = false;
      saveBtn.innerHTML = originalText;
    }
  } catch (error) {
    console.error('Error uploading logo:', error);
    showNotification('Network error. Please try again.', 'error');
    saveBtn.disabled = false;
    saveBtn.innerHTML = originalText;
  }
}

// Close logo modal when clicking outside
const logoUploadModal = document.getElementById('logoUploadModal');
if (logoUploadModal) {
  logoUploadModal.addEventListener('click', (e) => {
    if (e.target === logoUploadModal) {
      closeLogoUploadModal();
    }
  });
}

// Make functions globally available
window.openLogoUploadModal = openLogoUploadModal;
window.closeLogoUploadModal = closeLogoUploadModal;
window.handleLogoFileSelect = handleLogoFileSelect;
window.uploadRestaurantLogo = uploadRestaurantLogo;
