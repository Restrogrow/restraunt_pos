const sidebar = document.querySelector(".sidebar");
const sidebarToggler = document.querySelector(".sidebar-toggler");
const menuToggler = document.querySelector(".menu-toggler");

// Ensure these heights match the CSS sidebar height values
let collapsedSidebarHeight = "56px"; // Height in mobile view (collapsed)
let fullSidebarHeight = "calc(100vh - 32px)"; // Height in larger screen

// Global subscription status
let subscriptionStatus = null;
let subscriptionData = null;

// Currency symbol from database (set by PHP in dashboard.php)
// This is loaded server-side to prevent flash of default currency
let globalCurrencySymbol = window.globalCurrencySymbol || '₹';

// Sort mode state
let menuSortMode = false;
let menuItemSortMode = false;
let menuSortData = [];
let menuItemSortData = [];

// Toast fallback when Swal is not available
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

// SweetAlert helper for consistent modals
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
      window.location.href = '../admin/login.php';
    });
  } else {
    showToastFallback('Session expired. Please login again.', 'warning');
    setTimeout(function() { window.location.href = '../admin/login.php'; }, 1500);
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

// Auto-intercept ALL fetch calls to check session expiry (no manual checkSessionExpired needed)
(function() {
  var origFetch = window.fetch;
  window.fetch = function() {
    return origFetch.apply(this, arguments).then(function(response) {
      if (response.status === 401) {
        handleSessionExpired();
      } else if (response.headers && response.headers.get('content-type') && 
                 response.headers.get('content-type').includes('application/json')) {
        var clone = response.clone();
        clone.json().then(function(data) {
          if (data && data.success === false && data.message && 
              (data.message.toLowerCase().includes('session expired') ||
               data.message.toLowerCase().includes('please login again') ||
               data.message.toLowerCase().includes('login again'))) {
            handleSessionExpired();
          }
        }).catch(function() {});
      }
      return response;
    }).catch(function(err) {
      console.warn('Network request failed:', err);
      return Promise.reject(err);
    });
  };
})();

// Friendly error messages for common scenarios
var friendlyMessages = {
  network: [
    "Hmm, looks like your internet took a coffee break. Let's try again!",
    "Oops! The server is playing hide and seek. Give it a moment.",
    "Connection hiccup! These things happen sometimes. Retrying might help.",
    "Looks like the Wi-Fi gremlins are at it again. Please try again."
  ],
  timeout: [
    "Taking longer than usual — like waiting for food at a busy restaurant! Hang tight and try again.",
    "The kitchen's backed up! Let's try that request again.",
  ],
  generic: [
    "Something went sideways for a second. Try again and it should work!",
    "Don't worry, it's not you — it's us. Give it another shot!",
    "A tiny hiccup occurred. These usually fix themselves on retry."
  ]
};

function getRandomFriendlyMessage(type) {
  var msgs = friendlyMessages[type] || friendlyMessages.generic;
  return msgs[Math.floor(Math.random() * msgs.length)];
}

function showFriendlyError(type, title) {
  var msg = getRandomFriendlyMessage(type);
  showSweetAlert(msg, 'warning', { title: title || 'Oh no!' });
}

function showFriendlyNetworkError() {
  showFriendlyError('network', 'Connection Issue');
}

/* ===== UI State Helpers ===== */
// Show loading skeleton in a container
function showLoading(containerId, message) {
  var el = document.getElementById(containerId);
  if (!el) return;
  el.innerHTML = '<div class="loading-spinner-modern"><div class="spinner-ring"></div><p>' + (message || 'Loading...') + '</p></div>';
}

// Show empty state in a container
function showEmptyState(containerId, icon, title, desc, actionBtn) {
  var el = document.getElementById(containerId);
  if (!el) return;
  var html = '<div class="empty-state"><div class="empty-icon"><span class="material-symbols-rounded">' + (icon || 'info') + '</span></div><h3>' + (title || 'No data found') + '</h3><p>' + (desc || '') + '</p>';
  if (actionBtn) html += actionBtn;
  html += '</div>';
  el.innerHTML = html;
}

// Show error state in a container
function showErrorState(containerId, message) {
  var el = document.getElementById(containerId);
  if (!el) return;
  el.innerHTML = '<div class="empty-state"><div class="empty-icon"><span class="material-symbols-rounded">error_outline</span></div><h3>Something went wrong</h3><p>' + (message || 'Please try again later.') + '</p></div>';
}

// Payment method selector with clickable buttons - uses default methods
// Make showPaymentMethodSelector globally available
window.showPaymentMethodSelector = async function showPaymentMethodSelector() {
  if (window.Swal) {
    return new Promise((resolve) => {
      let resolved = false;
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
              resolved = true;
              Swal.close();
              resolve(method);
            });
          });
        },
        didClose: () => {
          if (!resolved) {
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

// Split-payment "Choose Pay Method" modal (Cash / UPI / Card breakdown, return/due, Save & Print / Save & eBill)
window.showSplitPaymentModal = function(total) {
  return new Promise((resolve) => {
    const currency = window.globalCurrencySymbol || '₹';
    const grandTotal = parseFloat(total) || 0;
    let resolved = false;

    const existing = document.getElementById('splitPaymentOverlay');
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.id = 'splitPaymentOverlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px;';

    const modal = document.createElement('div');
    modal.style.cssText = 'background:#fff;border-radius:16px;width:100%;max-width:420px;max-height:92vh;overflow-y:auto;box-shadow:0 25px 80px rgba(0,0,0,0.35);padding:24px;';

    modal.innerHTML = `
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
        <h2 style="margin:0;font-size:1.15rem;font-weight:700;color:#111827;">Choose Pay Method (${currency}${grandTotal.toFixed(2)})</h2>
        <button id="splitPayCloseBtn" style="width:34px;height:34px;flex-shrink:0;border:none;border-radius:50%;background:#10b981;color:#fff;font-size:18px;cursor:pointer;display:grid;place-items:center;">&times;</button>
      </div>

      <div style="margin-bottom:16px;">
        <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">Enter CASH Amount</label>
        <input type="number" id="splitPayCash" min="0" step="0.01" value="0" style="width:100%;padding:12px 14px;border:1.5px solid #d1d5db;border-radius:10px;font-size:1rem;box-sizing:border-box;">
      </div>
      <div style="margin-bottom:16px;">
        <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">Enter UPI Amount</label>
        <input type="number" id="splitPayUpi" min="0" step="0.01" value="0" style="width:100%;padding:12px 14px;border:1.5px solid #d1d5db;border-radius:10px;font-size:1rem;box-sizing:border-box;">
      </div>
      <div style="margin-bottom:16px;">
        <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">Enter CARD Amount</label>
        <input type="number" id="splitPayCard" min="0" step="0.01" value="0" style="width:100%;padding:12px 14px;border:1.5px solid #d1d5db;border-radius:10px;font-size:1rem;box-sizing:border-box;">
      </div>

      <div id="splitPayBalanceText" style="text-align:center;font-weight:700;font-size:1.05rem;margin-bottom:12px;"></div>
      <div id="splitPayConfirmBanner" style="display:flex;align-items:center;gap:8px;padding:12px 16px;border-radius:10px;font-weight:600;margin-bottom:16px;"></div>

      <div style="display:flex;border-radius:10px;overflow:hidden;margin-bottom:20px;border:1px solid #e5e7eb;">
        <div id="splitChipCash" style="flex:1;min-width:0;text-align:center;padding:10px 2px;font-size:0.72rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
        <div id="splitChipCard" style="flex:1;min-width:0;text-align:center;padding:10px 2px;font-size:0.72rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
        <div id="splitChipUpi" style="flex:1;min-width:0;text-align:center;padding:10px 2px;font-size:0.72rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
        <div id="splitChipDue" style="flex:1;min-width:0;text-align:center;padding:10px 2px;font-size:0.72rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
      </div>

      <div style="display:flex;gap:10px;">
        <button id="splitPaySaveBillBtn" style="flex:1;padding:13px;border:none;border-radius:10px;background:#10b981;color:#fff;font-weight:700;font-size:0.95rem;cursor:pointer;">Save & eBill</button>
        <button id="splitPaySavePrintBtn" style="flex:1;padding:13px;border:none;border-radius:10px;background:#10b981;color:#fff;font-weight:700;font-size:0.95rem;cursor:pointer;">Save & Print</button>
      </div>
    `;

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    const cashInput = modal.querySelector('#splitPayCash');
    const upiInput = modal.querySelector('#splitPayUpi');
    const cardInput = modal.querySelector('#splitPayCard');
    const balanceText = modal.querySelector('#splitPayBalanceText');
    const confirmBanner = modal.querySelector('#splitPayConfirmBanner');
    const chipCash = modal.querySelector('#splitChipCash');
    const chipCard = modal.querySelector('#splitChipCard');
    const chipUpi = modal.querySelector('#splitChipUpi');
    const chipDue = modal.querySelector('#splitChipDue');

    const chipBaseStyle = 'flex:1;min-width:0;text-align:center;padding:10px 2px;font-size:0.72rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
    const activeChipStyle = 'background:#0f4c5c;color:#fff;';
    const inactiveChipStyle = 'background:#fff;color:#111827;';

    function setChip(el, label, active) {
      el.style.cssText = chipBaseStyle + (active ? activeChipStyle : inactiveChipStyle);
      el.title = (active ? '✓ ' : '') + label;
      el.textContent = (active ? '✓' : '') + label;
    }

    function getAmounts() {
      const cash = Math.max(parseFloat(cashInput.value) || 0, 0);
      const upi = Math.max(parseFloat(upiInput.value) || 0, 0);
      const card = Math.max(parseFloat(cardInput.value) || 0, 0);
      return { cash, upi, card, entered: cash + upi + card };
    }

    function recalc() {
      const { cash, upi, card, entered } = getAmounts();
      const returnAmt = Math.max(entered - grandTotal, 0);
      const dueAmt = Math.max(grandTotal - entered, 0);

      if (returnAmt > 0) {
        balanceText.style.color = '#059669';
        balanceText.textContent = `Return Amount: ${currency}${returnAmt.toFixed(2)}`;
      } else if (dueAmt > 0 && entered > 0) {
        balanceText.style.color = '#d97706';
        balanceText.textContent = `Due Amount: ${currency}${dueAmt.toFixed(2)}`;
      } else {
        balanceText.textContent = '';
      }

      if (dueAmt <= 0 && entered > 0) {
        confirmBanner.style.background = '#10b981';
        confirmBanner.style.color = '#fff';
        confirmBanner.innerHTML = '✓ Payment confirmed 👍';
      } else if (entered > 0) {
        confirmBanner.style.background = '#fef3c7';
        confirmBanner.style.color = '#92400e';
        confirmBanner.innerHTML = `⚠️ Partial payment - ${currency}${dueAmt.toFixed(2)} due`;
      } else {
        confirmBanner.style.background = '#f3f4f6';
        confirmBanner.style.color = '#6b7280';
        confirmBanner.innerHTML = 'Enter amount to proceed, or mark as Due';
      }

      setChip(chipCash, 'CASH', cash > 0);
      setChip(chipCard, 'CARD', card > 0);
      setChip(chipUpi, 'UPI', upi > 0);
      setChip(chipDue, 'DUE', dueAmt > 0);
    }

    [cashInput, upiInput, cardInput].forEach((inp) => {
      inp.addEventListener('input', recalc);
      inp.addEventListener('focus', () => { if (inp.value === '0') inp.value = ''; });
    });
    recalc();

    function finish(action) {
      if (resolved) return;
      resolved = true;
      const { cash, upi, card, entered } = getAmounts();
      const returnAmt = Math.max(entered - grandTotal, 0);
      const dueAmt = Math.max(grandTotal - entered, 0);
      const methods = [];
      if (cash > 0) methods.push('Cash');
      if (card > 0) methods.push('Card');
      if (upi > 0) methods.push('UPI');
      const paymentMethod = methods.length ? methods.join('+') : 'Due';
      const paymentStatus = entered <= 0 ? 'Pending' : (dueAmt > 0 ? 'Partially Paid' : 'Paid');
      overlay.remove();
      resolve({ action, cash, upi, card, entered, returnAmount: returnAmt, dueAmount: dueAmt, paymentMethod, paymentStatus });
    }

    modal.querySelector('#splitPayCloseBtn').addEventListener('click', () => {
      if (resolved) return;
      resolved = true;
      overlay.remove();
      resolve(null);
    });
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay && !resolved) {
        resolved = true;
        overlay.remove();
        resolve(null);
      }
    });
    modal.querySelector('#splitPaySavePrintBtn').addEventListener('click', () => finish('print'));
    modal.querySelector('#splitPaySaveBillBtn').addEventListener('click', () => finish('ebill'));
  });
};

// Update global currency symbol when it changes
function updateCurrencySymbol(newSymbol) {
  globalCurrencySymbol = newSymbol;
  window.globalCurrencySymbol = newSymbol;
  localStorage.setItem('system_currency', newSymbol);
}

// Enhanced fetch wrapper with timeout, retry, and better error handling
async function fetchWithRetry(url, options = {}, retries = 2, timeout = 30000) {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeout);
  
  const fetchOptions = {
    ...options,
    signal: controller.signal,
  };
  
  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      const response = await fetch(url, fetchOptions);
      clearTimeout(timeoutId);
      
      // Check if response is ok
      if (!response.ok) {
        // If it's a server error (5xx), retry
        if (response.status >= 500 && attempt < retries) {
          await new Promise(resolve => setTimeout(resolve, 1000 * (attempt + 1)));
          continue;
        }
      }
      
      return response;
      
    } catch (error) {
      clearTimeout(timeoutId);
      
      // Handle different error types
      if (error.name === 'AbortError') {
        if (attempt < retries) {
          // Timeout - retry with longer timeout
          await new Promise(resolve => setTimeout(resolve, 1000 * (attempt + 1)));
          continue;
        }
        throw new Error('Request timeout. The server is taking too long to respond. Please try again.');
      }
      
      // Network errors - retry
      if (error.message.includes('Failed to fetch') || 
          error.message.includes('NetworkError') ||
          error.message.includes('Network request failed')) {
        if (attempt < retries) {
          await new Promise(resolve => setTimeout(resolve, 1000 * (attempt + 1)));
          continue;
        }
        throw new Error('Network error. Please check your internet connection and try again.');
      }
      
      // Other errors - don't retry
      throw error;
    }
  }
  
  throw new Error('Request failed after multiple attempts. Please try again.');
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


// Submenu toggle functionality
document.addEventListener("DOMContentLoaded", () => {
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
    // Enable zoom only for website appearance section
    const viewport = document.querySelector('meta[name="viewport"]');
    if (pageId === 'websiteThemePage') {
      // Allow zoom in and out in website appearance section
      if (viewport) {
        viewport.setAttribute('content', 'width=device-width, initial-scale=1.0, minimum-scale=0.5, maximum-scale=5.0, user-scalable=yes');
      }
      setTimeout(() => {
        initWebsiteThemeEditor();
      }, 100);
    } else {
      // Disable zoom for all other pages
      if (viewport) {
        viewport.setAttribute('content', 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no');
      }
    }
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
      
      try {
        localStorage.setItem('admin_active_page', pageId);
      } catch (err) {
        console.warn('Unable to persist active page', err);
      }
      
      // Load dashboard stats if it's the dashboard page
      if (pageId === "dashboardPage") {
        console.log('Switching to dashboard, loading stats...');
        setTimeout(() => loadDashboardStats(), 100);
      }
      
      // Load menus if it's the menu page
      if (pageId === "menuPage") {
        exitMenuSort();
        loadMenus();
      }
      
      // Load menu items if it's the menu items page
      if (pageId === "menuItemsPage") {
        exitMenuItemSort();
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
        // Set up menu items filter listeners
        setTimeout(() => {
          const menuFilterEl = document.getElementById("menuFilter");
          const categoryFilterEl = document.getElementById("categoryFilter");
          const typeFilterEl = document.getElementById("typeFilter");
          if (menuFilterEl && !menuFilterEl.dataset.listenerAttached) {
            menuFilterEl.addEventListener("change", loadMenuItems);
            menuFilterEl.dataset.listenerAttached = 'true';
          }
          if (categoryFilterEl && !categoryFilterEl.dataset.listenerAttached) {
            categoryFilterEl.addEventListener("change", loadMenuItems);
            categoryFilterEl.dataset.listenerAttached = 'true';
          }
          if (typeFilterEl && !typeFilterEl.dataset.listenerAttached) {
            typeFilterEl.addEventListener("change", loadMenuItems);
            typeFilterEl.dataset.listenerAttached = 'true';
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
      
      // Load Table Map if it's the table map page
      if (pageId === "tableMapPage") {
        renderTableMap();
      }
      
      // Load addons if it's the addons page
      if (pageId === "addonsPage") {
        if (typeof window.loadAdminAddons === 'function') {
          setTimeout(function() { loadAdminAddons(); }, 50);
        }
      }
      
      // Load delivery zones if it's the delivery page
      if (pageId === "deliveryPage") {
        if (typeof window.loadDeliveryZones === 'function') {
          setTimeout(function() { loadDeliveryZones(); }, 50);
        }
      }
      
      // Load delivery map if it's the delivery map page
      if (pageId === "deliveryMapPage") {
        if (typeof window.initDeliveryMap === 'function') {
          setTimeout(function() { initDeliveryMap(); }, 50);
        }
      }
      
      // Load reservations if it's the reservations page
      if (pageId === "reservationsPage") {
        loadReservations();
        // Set up reservation search and filter listeners
        setTimeout(() => {
          const reservationSearch = document.getElementById('reservationSearch');
          const reservationStatusFilter = document.getElementById('reservationStatusFilter');
          const reservationDateFrom = document.getElementById('reservationDateFrom');
          const reservationDateTo = document.getElementById('reservationDateTo');
          if (reservationSearch && !reservationSearch.dataset.listenerAttached) {
            reservationSearch.addEventListener('input', filterReservations);
            reservationSearch.dataset.listenerAttached = 'true';
          }
          if (reservationStatusFilter && !reservationStatusFilter.dataset.listenerAttached) {
            reservationStatusFilter.addEventListener('change', filterReservations);
            reservationStatusFilter.dataset.listenerAttached = 'true';
          }
          if (reservationDateFrom && !reservationDateFrom.dataset.listenerAttached) {
            reservationDateFrom.addEventListener('change', filterReservations);
            reservationDateFrom.addEventListener('blur', function() {
              this.style.borderColor = '#e5e7eb';
              this.style.boxShadow = 'none';
            });
            reservationDateFrom.dataset.listenerAttached = 'true';
            // Ensure it's not focused on page load
            reservationDateFrom.blur();
          }
          if (reservationDateTo && !reservationDateTo.dataset.listenerAttached) {
            reservationDateTo.addEventListener('change', filterReservations);
            reservationDateTo.addEventListener('blur', function() {
              this.style.borderColor = '#e5e7eb';
              this.style.boxShadow = 'none';
            });
            reservationDateTo.dataset.listenerAttached = 'true';
            // Ensure it's not focused on page load
            reservationDateTo.blur();
          }
          // Ensure no inputs are focused when page loads
          if (reservationSearch) reservationSearch.blur();
          if (reservationStatusFilter) reservationStatusFilter.blur();
        }, 100);
      }
      
      // Load embed settings if it's the custom domain / embed page
      if (pageId === "embedPage") {
        if (typeof window.loadEmbedSettings === 'function') {
          setTimeout(function() { loadEmbedSettings(); }, 50);
        }
      }
      
      // Load feedback if it's the feedback page
      if (pageId === "feedbackPage") {
        if (typeof window.loadFeedback === 'function') {
          setTimeout(function() { loadFeedback(); }, 50);
        }
      }
      
      // Load customers if it's the customers page
      if (pageId === "customersPage") {
        loadCustomers();
        // Set up customer search and filter listeners
        setTimeout(() => {
          const customerSearch = document.getElementById('customerSearch');
          const customerSortBy = document.getElementById('customerSortBy');
          if (customerSearch && !customerSearch.dataset.listenerAttached) {
            customerSearch.addEventListener('input', filterCustomers);
            customerSearch.dataset.listenerAttached = 'true';
          }
          if (customerSortBy && !customerSortBy.dataset.listenerAttached) {
            customerSortBy.addEventListener('change', filterCustomers);
            customerSortBy.dataset.listenerAttached = 'true';
          }
        }, 100);
      }
      
      // Load staff if it's the staff page
      if (pageId === "staffPage") {
        loadStaff();
        // Set up staff search and filter listeners
        setTimeout(() => {
          const staffSearch = document.getElementById('staffSearch');
          const staffSortBy = document.getElementById('staffSortBy');
          if (staffSearch && !staffSearch.dataset.listenerAttached) {
            staffSearch.addEventListener('input', filterStaff);
            staffSearch.dataset.listenerAttached = 'true';
          }
          if (staffSortBy && !staffSortBy.dataset.listenerAttached) {
            staffSortBy.addEventListener('change', filterStaff);
            staffSortBy.dataset.listenerAttached = 'true';
          }
        }, 100);
      }
      
      // Load POS if it's the POS page
      if (pageId === "posPage") {
        if (typeof window.loadPOSMenuItems === 'function') {
          loadPOSMenuItems();
        }
        if (typeof window.loadTablesForPOS === 'function') {
          loadTablesForPOS();
        }
        if (typeof window.loadMenusForPOSFilters === 'function') {
          loadMenusForPOSFilters();
        }
        if (typeof window.loadCategoriesForPOSFilters === 'function') {
          loadCategoriesForPOSFilters();
        }
        
        // Show/hide mobile add item button based on screen size
        setTimeout(() => {
          checkMobileView();
          window.addEventListener('resize', checkMobileView);
        }, 100);
        
        // Set up POS filter listeners
        setTimeout(() => {
          const posMenuFilter = document.getElementById("posMenuFilter");
          const posCategoryFilter = document.getElementById("posCategoryFilter");
          const posTypeFilter = document.getElementById("posTypeFilter");
          if (posMenuFilter && !posMenuFilter.dataset.listenerAttached) {
            posMenuFilter.addEventListener("change", loadPOSMenuItems);
            posMenuFilter.dataset.listenerAttached = 'true';
          }
          if (posCategoryFilter && !posCategoryFilter.dataset.listenerAttached) {
            posCategoryFilter.addEventListener("change", loadPOSMenuItems);
            posCategoryFilter.dataset.listenerAttached = 'true';
          }
          if (posTypeFilter && !posTypeFilter.dataset.listenerAttached) {
            posTypeFilter.addEventListener("change", loadPOSMenuItems);
            posTypeFilter.dataset.listenerAttached = 'true';
          }
        }, 100);
      }
      
      // Load KOT if it's the KOT page
      if (pageId === "kotPage") {
        loadKOTOrders();
        loadTablesForKOT();
        // Set up KOT filter listeners
        setTimeout(() => {
          const kotStatusFilter = document.getElementById('kotStatusFilter');
          const kotTableFilter = document.getElementById('kotTableFilter');
          if (kotStatusFilter && !kotStatusFilter.dataset.listenerAttached) {
            kotStatusFilter.addEventListener('change', loadKOTOrders);
            kotStatusFilter.dataset.listenerAttached = 'true';
          }
          if (kotTableFilter && !kotTableFilter.dataset.listenerAttached) {
            kotTableFilter.addEventListener('change', loadKOTOrders);
            kotTableFilter.dataset.listenerAttached = 'true';
          }
        }, 100);
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
      
      // Load Online Orders if it's the Online Orders page
      if (pageId === "onlineOrdersPage") {
        loadOnlineOrders();
        // Set up search with debounce
        setTimeout(() => {
          const search = document.getElementById('onlineOrdersSearch');
          if (search && !search.dataset.listenerAttached) {
            let timeout;
            search.addEventListener('input', () => {
              clearTimeout(timeout);
              timeout = setTimeout(() => loadOnlineOrders(), 500);
            });
            search.dataset.listenerAttached = 'true';
          }
        }, 100);
        // Start auto-refresh
        if (window.onlineOrdersAutoRefresh) {
          clearInterval(window.onlineOrdersAutoRefresh);
        }
        window.onlineOrdersAutoRefresh = setInterval(() => {
          const page = document.getElementById('onlineOrdersPage');
          if (page?.classList.contains('active') && !document.hidden) {
            loadOnlineOrders();
          }
        }, 15000);
      } else {
        if (window.onlineOrdersAutoRefresh) {
          clearInterval(window.onlineOrdersAutoRefresh);
          window.onlineOrdersAutoRefresh = null;
        }
      }

      // Background badge poll for pending order count (always runs, updates nav badges)
      if (!window.pendingOrderBadgePoll) {
        async function fetchPendingCount() {
          try {
            const r = await fetch('../api/get_pending_online_count.php', { cache: 'no-store' });
            const d = await r.json();
            if (d.success) {
              if (prevOnlineOrderCount > 0 && d.count > prevOnlineOrderCount && window.onlineOrdersSoundEnabled) {
                playNotificationSound();
              }
              prevOnlineOrderCount = d.count;
              updatePendingOrderBadge(d.count);
            }
          } catch(e) {}
        }
        fetchPendingCount();
        window.pendingOrderBadgePoll = setInterval(fetchPendingCount, 30000);
      }

      // New order popup polling - every 10 seconds
      if (!window._newOrderPollInterval) {
        window._lastSeenOrderId = 0;
        window._currentNotifOrderId = null;
        window._overlayProcessing = false;

        async function checkNewPendingOrder() {
          try {
            const overlay = document.getElementById('newOrderOverlay');
            if (overlay && overlay.classList.contains('show')) return;
            if (window._overlayProcessing) return;

            const r = await fetch('../api/get_latest_pending_order.php', { cache: 'no-store' });
            if (!r.ok) return;
            const d = await r.json();
            if (d.success && d.order) {
              const orderId = parseInt(d.order.id);
              if (orderId > window._lastSeenOrderId) {
                window._lastSeenOrderId = orderId;
                window._currentNotifOrderId = orderId;
                showNewOrderPopup(d.order);
              }
            } else if (d.success && !d.order) {
              window._lastSeenOrderId = 0;
            }
          } catch(e) {}
        }

        console.log('[Notif] Polling for new orders started, will check in 2s...');
        setTimeout(checkNewPendingOrder, 2000);
        window._newOrderPollInterval = setInterval(checkNewPendingOrder, 10000);
      }

      function showNewOrderPopup(order) {
        var overlay = document.getElementById('newOrderOverlay');
        if (!overlay) return;

        try { playNotificationSound(); } catch(e) {}

        var e = document.getElementById('notifOrderNumber');
        if (e) e.textContent = 'Order #' + (order.order_number || order.id);

        e = document.getElementById('notifCustomerName');
        if (e) e.textContent = order.customer_name || 'Guest';

        e = document.getElementById('notifCustomerPhone');
        if (e) e.textContent = order.customer_phone || '-';

        var addrRow = document.getElementById('notifAddressRow');
        var addrEl = document.getElementById('notifCustomerAddress');
        if (addrRow && addrEl) {
          if (order.customer_address && order.order_type === 'delivery') {
            addrRow.style.display = 'flex';
            addrEl.textContent = order.customer_address;
          } else {
            addrRow.style.display = 'none';
          }
        }

        e = document.getElementById('notifOrderType');
        if (e) {
          var s = (order.order_type || 'N/A').replace(/_/g, ' ');
          e.textContent = s.charAt(0).toUpperCase() + s.slice(1);
        }

        e = document.getElementById('notifPaymentInfo');
        if (e) e.textContent = (order.payment_method || 'N/A') + ' (' + (order.payment_status || 'N/A') + ')';

        var itemsEl = document.getElementById('notifItemsList');
        if (itemsEl) {
          var html = '';
          if (order.items && order.items.length > 0) {
            for (var i = 0; i < order.items.length; i++) {
              var item = order.items[i];
              var n = item.item_name || '';
              var d = document.createElement('div');
              d.textContent = n;
              html += '<div class="order-item-row"><div><span class="item-name">' + d.innerHTML + '</span> <span class="item-qty">x' + (item.quantity || 1) + '</span></div><span class="item-price">' + (window.globalCurrencySymbol || '\u20b9') + parseFloat(item.total_price || 0).toFixed(2) + '</span></div>';
            }
          } else {
            html = '<div style="text-align:center;color:#999;padding:8px;">' + (order.item_count || 0) + ' item(s)</div>';
          }
          itemsEl.innerHTML = html;
        }

        e = document.getElementById('notifTotalAmount');
        if (e) e.textContent = (window.globalCurrencySymbol || '\u20b9') + parseFloat(order.total || order.subtotal || 0).toFixed(2);

        e = document.getElementById('notifOrderTime');
        if (e) {
          try {
            var dt = new Date(order.created_at);
            if (!isNaN(dt.getTime())) { var tz = window.userTimezone || Intl.DateTimeFormat().resolvedOptions().timeZone; e.textContent = 'Received ' + dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: tz }); }
          } catch(ee) {}
        }

        var accBtn = document.getElementById('notifAcceptBtn');
        var rejBtn = document.getElementById('notifRejectBtn');
        if (accBtn) { accBtn.disabled = false; accBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size:18px;">check_circle</span> Accept'; }
        if (rejBtn) { rejBtn.disabled = false; rejBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size:18px;">cancel</span> Reject'; }

        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
      }

      window.showNewOrderPopup = showNewOrderPopup;

            window.closeNewOrderOverlay = function() {
        var overlay = document.getElementById('newOrderOverlay');
        if (overlay) {
          overlay.classList.remove('show');
          document.body.style.overflow = '';
        }
        window._currentNotifOrderId = null;
      };

      window.acceptNewOrder = async function() {
        var orderId = window._currentNotifOrderId;
        if (!orderId) return;
        window._overlayProcessing = true;
        var btn = document.getElementById('notifAcceptBtn');
        var rejBtn = document.getElementById('notifRejectBtn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="loading-spinner"></span> Accepting...'; }
        if (rejBtn) rejBtn.disabled = true;
        try {
          var r = await fetch('../api/update_order_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'orderId=' + orderId + '&status=Accepted'
          });
          var d = await r.json();
          window.closeNewOrderOverlay();
          if (typeof showNotification === 'function') showNotification(d.success ? 'Order accepted!' : (d.message || 'Failed'), d.success ? 'success' : 'error');
          if (typeof showPage === 'function') showPage('onlineOrdersPage');
        } catch(e) {
          if (typeof showNotification === 'function') showNotification('Network error', 'error');
        } finally {
          window._overlayProcessing = false;
        }
      };

      window.rejectNewOrder = async function() {
        var orderId = window._currentNotifOrderId;
        if (!orderId) return;

        // Close the order notification popup immediately, then ask for a reason
        window.closeNewOrderOverlay();

        var reason = '';
        if (window.Swal) {
          var result = await Swal.fire({
            title: 'Reject Order',
            text: 'Why are you rejecting this order?',
            input: 'textarea',
            inputPlaceholder: 'e.g. Item unavailable, Restaurant closed...',
            showCancelButton: true,
            confirmButtonText: 'Reject',
            confirmButtonColor: '#ef4444',
            cancelButtonText: 'Cancel',
            inputValidator: function(v) { if (!v) return 'Please enter a reason'; }
          });
          if (!result.isConfirmed) return;
          reason = result.value;
        } else {
          reason = (window.prompt('Why are you rejecting this order?', '') || 'Rejected');
        }
        window._overlayProcessing = true;
        try {
          var r = await fetch('../api/update_order_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'orderId=' + orderId + '&status=Rejected&reason=' + encodeURIComponent(reason)
          });
          var d = await r.json();
          if (typeof showNotification === 'function') showNotification(d.success ? 'Order rejected' : (d.message || 'Failed'), d.success ? 'info' : 'error');
          if (typeof showPage === 'function') showPage('onlineOrdersPage');
        } catch(e) {
          if (typeof showNotification === 'function') showNotification('Network error', 'error');
        } finally {
          window._overlayProcessing = false;
        }
      };
      
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
          const ordersStatusFilter = document.getElementById('ordersStatusFilter');
          const ordersPaymentFilter = document.getElementById('ordersPaymentFilter');
          const ordersTypeFilter = document.getElementById('ordersTypeFilter');
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
          
          if (ordersStatusFilter && !ordersStatusFilter.dataset.listenerAttached) {
            ordersStatusFilter.addEventListener('change', () => loadOrders());
            ordersStatusFilter.dataset.listenerAttached = 'true';
          }
          if (ordersPaymentFilter && !ordersPaymentFilter.dataset.listenerAttached) {
            ordersPaymentFilter.addEventListener('change', () => loadOrders());
            ordersPaymentFilter.dataset.listenerAttached = 'true';
          }
          if (ordersTypeFilter && !ordersTypeFilter.dataset.listenerAttached) {
            ordersTypeFilter.addEventListener('change', () => loadOrders());
            ordersTypeFilter.dataset.listenerAttached = 'true';
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
        // Initialize change password form handler
        setTimeout(() => {
          setupSettingsForms();
        }, 100);
      }
      
      // Load payments if it's the payments page
      if (pageId === "paymentsPage") {
        loadPayments();
        // Set up payment filter listeners
        setTimeout(() => {
          const paymentSearch = document.getElementById('paymentSearch');
          const paymentMethodFilter = document.getElementById('paymentMethodFilter');
          const paymentStatusFilter = document.getElementById('paymentStatusFilter');
          if (paymentSearch && !paymentSearch.dataset.listenerAttached) {
            paymentSearch.addEventListener('input', debounce(() => {
              loadPayments();
            }, 300));
            paymentSearch.dataset.listenerAttached = 'true';
          }
          if (paymentMethodFilter && !paymentMethodFilter.dataset.listenerAttached) {
            paymentMethodFilter.addEventListener('change', () => {
              loadPayments();
            });
            paymentMethodFilter.dataset.listenerAttached = 'true';
          }
          if (paymentStatusFilter && !paymentStatusFilter.dataset.listenerAttached) {
            paymentStatusFilter.addEventListener('change', () => {
              loadPayments();
            });
            paymentStatusFilter.dataset.listenerAttached = 'true';
          }
        }, 100);
      }
      
      // Load coupons if it's the coupons page
      if (pageId === "couponsPage") {
        loadAdminCoupons();
        const couponModal = document.getElementById('couponModal');
        if (couponModal && !couponModal.dataset.couponListener) {
          couponModal.addEventListener('hidden', function() { loadAdminCoupons(); });
          couponModal.dataset.couponListener = 'true';
        }
      }
      // Load deals if it's the deals page
      if (pageId === "dealsPage") {
        setTimeout(function() {
          if (typeof loadDealMenus === 'function') loadDealMenus();
          if (typeof loadDeals === 'function') loadDeals();
        }, 100);
      }

      // Load settings data if it's the settings page
      if (pageId === "settingsPage") {
        loadSettingsData();
      }
      
      // Setup reports auto-reload if it's the reports page
      if (pageId === "reportsPage") {
        setTimeout(() => {
          setupReportsAutoReload();
        }, 100);
      }
      
      // Load analytics if it's the analytics page
      if (pageId === "analyticsPage") {
        setTimeout(() => loadAnalytics(), 100);
      }
    }
  }
  
  // Make showPage globally accessible for onclick handlers
  window.showPage = showPage;

  // Initialize page restoration after DOM is fully loaded and all functions are defined
  function initializePageRestoration() {
    // Restore previously active page (if available), unless forced to dashboard after login
    try {
      const ordersListContainer = document.getElementById('ordersList');
      if (ordersListContainer) {
        ordersListContainer.innerHTML = '<div class="loading">Refreshing orders...</div>';
      }

      const forceDashboard = sessionStorage.getItem('forceDashboard');
      if (forceDashboard) {
        sessionStorage.removeItem('forceDashboard');
        try {
          localStorage.removeItem('admin_active_page');
        } catch (storageErr) {
          console.warn('Unable to clear saved admin page', storageErr);
        }
        showPage('dashboardPage');
      } else {
        const savedPageId = localStorage.getItem('admin_active_page');
        if (savedPageId && document.getElementById(savedPageId)) {
          showPage(savedPageId);
        } else {
          showPage('dashboardPage');
        }
      }
    } catch (err) {
      console.warn('Unable to restore saved page, defaulting to dashboard', err);
      showPage('dashboardPage');
    }
  }

  // Call initialization after DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializePageRestoration);
  } else {
    // DOM already loaded, run immediately
    setTimeout(initializePageRestoration, 0);
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
  let currentMenuImage = null;

  // Open modal for adding new menu (only if button exists)
  if (addMenuBtn) {
    addMenuBtn.addEventListener("click", (e) => {
      e.preventDefault();
      openMenuModal();
    });
  }

  // Sort menus button
  const sortMenusBtn = document.getElementById("sortMenusBtn");
  if (sortMenusBtn) {
    sortMenusBtn.addEventListener("click", () => {
      if (menuSortMode) return;
      menuSortMode = true;
      const bar = document.getElementById('menuSortBar');
      if (bar) bar.style.display = 'flex';
      loadMenus();
    });
  }

  // Cancel menu sort
  const cancelMenuSortBtn = document.getElementById("cancelMenuSortBtn");
  if (cancelMenuSortBtn) {
    cancelMenuSortBtn.addEventListener("click", () => {
      exitMenuSort();
      loadMenus();
    });
  }

  // Save menu sort
  const saveMenuSortBtn = document.getElementById("saveMenuSortBtn");
  if (saveMenuSortBtn) {
    saveMenuSortBtn.addEventListener("click", function() { window.saveMenuSort(); });
  }
  
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
  window.editMenu = function(menuId, menuName, menuImage = null) {
    currentMenuId = menuId;
    currentMenuName = menuName;
    currentMenuImage = menuImage;
    openMenuModal(true);
  };

  function openMenuModal(isEdit = false) {
    // Reset crop state
    menuCropApplied = false;
    const base64Input = document.getElementById('menuImageBase64');
    if (base64Input) base64Input.value = '';
    
    // Reset subcategories section
    hideSubcategoriesSection();
    if (menuImageCropper) {
      menuImageCropper.destroy();
      menuImageCropper = null;
    }
    const cropperSection = document.getElementById("menuImageCropperSection");
    if (cropperSection) cropperSection.style.display = "none";

    if (isEdit) {
      modalTitle.textContent = "Edit Category";
      menuIdInput.value = currentMenuId;
      menuNameInput.value = currentMenuName;
      saveBtn.textContent = "Update Category";
      
      // Show subcategories section and load subcategories
      showSubcategoriesSection();
      setTimeout(() => loadSubcategories(currentMenuId), 100);
      // Load existing image if available
      if (currentMenuImage) {
        const preview = document.getElementById("menuImagePreview");
        const previewImg = document.getElementById("menuImagePreviewImg");
        if (preview && previewImg) {
          previewImg.src = currentMenuImage;
          preview.style.display = "block";
        }
      }
    } else {
      modalTitle.textContent = "Add New Category";
      menuIdInput.value = "";
      menuNameInput.value = "";
      saveBtn.textContent = "Save Category";
      // Clear image preview
      const preview = document.getElementById("menuImagePreview");
      const previewImg = document.getElementById("menuImagePreviewImg");
      if (preview) preview.style.display = "none";
      if (previewImg) previewImg.src = "";
      const menuImageInput = document.getElementById("menuImage");
      if (menuImageInput) menuImageInput.value = "";
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
    currentMenuImage = null;
    
    // Clean up cropper
    if (menuImageCropper) {
      menuImageCropper.destroy();
      menuImageCropper = null;
    }
    menuCropApplied = false;
    
    // Clear image preview
    const preview = document.getElementById("menuImagePreview");
    const previewImg = document.getElementById("menuImagePreviewImg");
    if (preview) preview.style.display = "none";
    if (previewImg) previewImg.src = "";
    
    // Hide cropper section
    const cropperSection = document.getElementById("menuImageCropperSection");
    if (cropperSection) cropperSection.style.display = "none";
    
    // Clear file name
    const fileName = document.getElementById("menuImageFileName");
    if (fileName) fileName.textContent = "No file chosen";
    
    // Clear base64 input
    const base64Input = document.getElementById("menuImageBase64");
    if (base64Input) base64Input.value = "";
    
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

  const cancelBtn = document.getElementById("cancelBtn");
  const deleteCancelBtn = document.getElementById("deleteCancelBtn");
  
  if (cancelBtn) cancelBtn.addEventListener("click", closeMenuModal);
  if (deleteCancelBtn) deleteCancelBtn.addEventListener("click", closeDeleteModal);

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
  if (menuForm) {
    menuForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    
    const menuName = menuNameInput.value.trim();
    const menuId = menuIdInput.value;
    const menuImageInput = document.getElementById("menuImage");
    const isEdit = menuId !== "";
    
    if (!menuName) {
      showMessage("Please enter a category name.", "error");
      return;
    }

    // Disable save button and show loading
    saveBtn.disabled = true;
    saveBtn.textContent = isEdit ? "Updating..." : "Saving...";

    try {
      const formData = new FormData();
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
      // Handle image - use base64 if cropped, otherwise use file
      const menuImageBase64 = document.getElementById('menuImageBase64');
      if (menuImageBase64 && menuImageBase64.value) {
        formData.append('menuImageBase64', menuImageBase64.value);
      } else if (menuImageInput && menuImageInput.files.length > 0) {
        formData.append('menuImage', menuImageInput.files[0]);
      }

      const response = await fetch("../controllers/menu_operations.php", {
        method: "POST",
        body: formData
      });

      const result = await response.json();

      if (result.success) {
        showMessage(result.message, "success");
        setTimeout(() => {
          closeMenuModal();
          loadMenus(); // Refresh the menu list
          // If menu was updated with new image, reload page to clear cache
          if (isEdit && menuImageBase64 && menuImageBase64.value) {
            setTimeout(function() { location.reload(); }, 500);
          }
        }, 1500);
      } else {
        showMessage(result.message || "Error processing request. Please try again.", "error");
      }
    } catch (error) {
      console.error("Error:", error);
      showMessage("Network error. Please check your connection and try again.", "error");
    } finally {
      // Re-enable save button
      if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.textContent = isEdit ? "Update Category" : "Save Category";
      }
    }
    });
  }
  
  // Category image upload and cropper functionality
  let menuImageCropper = null;
  let menuCropApplied = false;
  
  const menuImageInput = document.getElementById("menuImage");
  const menuImageFileName = document.getElementById("menuImageFileName");
  const menuImageCropperSection = document.getElementById("menuImageCropperSection");
  const menuImageToCrop = document.getElementById("menuImageToCrop");
  const menuImageBase64Input = document.getElementById("menuImageBase64");
  
  if (menuImageInput) {
    menuImageInput.addEventListener('change', function(e) {
      const file = e.target.files[0];
      
      if (file) {
        menuImageFileName.textContent = file.name;
        
        // Clean up existing cropper
        if (menuImageCropper) {
          menuImageCropper.destroy();
          menuImageCropper = null;
        }
        
        // Reset crop applied flag
        menuCropApplied = false;
        if (menuImageBase64Input) menuImageBase64Input.value = '';
        
        // Reset crop button appearance
        const cropBtn = document.getElementById('cropMenuImageBtn');
        if (cropBtn) {
          cropBtn.style.background = '';
          cropBtn.style.color = '';
          cropBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size: 1.2rem; vertical-align: middle;">crop</span> Apply Crop';
        }
        
        // Show preview and initialize cropper
        const reader = new FileReader();
        reader.onload = function(e) {
          const imageUrl = e.target.result;
          
          // Set image source for cropper
          menuImageToCrop.src = imageUrl;
          menuImageCropperSection.style.display = 'block';
          
          // Initialize Cropper.js with square aspect ratio for categories
          menuImageCropper = new Cropper(menuImageToCrop, {
            aspectRatio: 1, // Square for categories
            viewMode: 1,
            dragMode: 'move',
            autoCropArea: 0.8,
            restore: false,
            guides: true,
            center: true,
            highlight: false,
            cropBoxMovable: true,
            cropBoxResizable: true,
            toggleDragModeOnDblclick: false,
            ready: function() {
              // Update preview when cropper is ready
              updateMenuCategoryPreview();
              // Initialize crop buttons
              initializeMenuCropButtons();
            },
            crop: function() {
              // Update preview on crop
              updateMenuCategoryPreview();
            }
          });
          
          // Hide old preview
          const oldPreview = document.getElementById("menuImagePreview");
          if (oldPreview) oldPreview.style.display = 'none';
        };
        reader.readAsDataURL(file);
      } else {
        menuImageFileName.textContent = 'No file chosen';
        const oldPreview = document.getElementById("menuImagePreview");
        if (oldPreview) oldPreview.style.display = 'none';
        menuImageCropperSection.style.display = 'none';
        if (menuImageCropper) {
          menuImageCropper.destroy();
          menuImageCropper = null;
        }
        if (menuImageBase64Input) menuImageBase64Input.value = '';
      }
    });
  }
  
  // Update category preview with cropped image
  function updateMenuCategoryPreview() {
    if (!menuImageCropper) return;
    
    const croppedCanvas = menuImageCropper.getCroppedCanvas({
      width: 600,
      height: 600,
      imageSmoothingEnabled: true,
      imageSmoothingQuality: 'high'
    });
    
    if (croppedCanvas) {
      const croppedDataUrl = croppedCanvas.toDataURL('image/jpeg', 0.9);
      const croppedPreviewImg = document.getElementById('croppedMenuPreviewImg');
      const categoryPreviewImage = document.getElementById('menuCategoryPreviewImage');
      
      if (croppedPreviewImg) {
        croppedPreviewImg.src = croppedDataUrl;
        croppedPreviewImg.style.display = 'block';
      }
      
      // Hide placeholder emoji
      if (categoryPreviewImage) {
        const placeholder = categoryPreviewImage.querySelector('span');
        if (placeholder) placeholder.style.display = 'none';
      }
      
      // Update preview category name if available
      const categoryNameInput = document.getElementById('menuName');
      if (categoryNameInput && categoryNameInput.value) {
        const previewName = document.getElementById('previewCategoryName');
        if (previewName) previewName.textContent = categoryNameInput.value;
      }
    }
  }
  
  // Initialize menu crop buttons
  function initializeMenuCropButtons() {
    const cropBtn = document.getElementById('cropMenuImageBtn');
    const resetBtn = document.getElementById('resetMenuCropBtn');
    
    if (cropBtn) {
      // Remove existing listener if any
      const newCropBtn = cropBtn.cloneNode(true);
      cropBtn.parentNode.replaceChild(newCropBtn, cropBtn);
      
      newCropBtn.addEventListener('click', function() {
        if (!menuImageCropper) return;
        
        // Show loading state
        const originalBtnHTML = newCropBtn.innerHTML;
        newCropBtn.disabled = true;
        newCropBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size: 1.2rem; vertical-align: middle;">hourglass_empty</span> Processing...';
        
        const croppedCanvas = menuImageCropper.getCroppedCanvas({
          width: 1200,
          height: 1200,
          imageSmoothingEnabled: true,
          imageSmoothingQuality: 'high'
        });
        
        if (croppedCanvas) {
          const croppedDataUrl = croppedCanvas.toDataURL('image/jpeg', 0.9);
          
          // Store cropped image as base64
          if (menuImageBase64Input) menuImageBase64Input.value = croppedDataUrl;
          menuCropApplied = true;
          
          // Update preview
          updateMenuCategoryPreview();
          
          // Show success state on button
          newCropBtn.disabled = false;
          newCropBtn.style.background = '#10b981';
          newCropBtn.style.color = 'white';
          newCropBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size: 1.2rem; vertical-align: middle;">check_circle</span> Crop Applied!';
          
          // Show success message
          showMessage('Image cropped successfully!', 'success');
          
          // Reset button after 3 seconds
          setTimeout(() => {
            newCropBtn.style.background = '';
            newCropBtn.style.color = '';
            newCropBtn.innerHTML = originalBtnHTML;
          }, 3000);
        } else {
          // Error state
          newCropBtn.disabled = false;
          newCropBtn.innerHTML = originalBtnHTML;
          showMessage('Failed to crop image. Please try again.', 'error');
        }
      });
    }
    
    if (resetBtn) {
      // Remove existing listener if any
      const newResetBtn = resetBtn.cloneNode(true);
      resetBtn.parentNode.replaceChild(newResetBtn, resetBtn);
      
      newResetBtn.addEventListener('click', function() {
        if (!menuImageCropper) return;
        
        // Reset cropper to initial state
        menuImageCropper.reset();
        menuCropApplied = false;
        if (menuImageBase64Input) menuImageBase64Input.value = '';
        
        // Reset crop button
        const cropBtn = document.getElementById('cropMenuImageBtn');
        if (cropBtn) {
          cropBtn.style.background = '';
          cropBtn.style.color = '';
          cropBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size: 1.2rem; vertical-align: middle;">crop</span> Apply Crop';
        }
        
        // Update preview
        updateMenuCategoryPreview();
      });
    }
  }

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
  
  // Make showMessage globally accessible
  window.showMessage = showMessage;

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
    // Guard to prevent concurrent API calls
    if (window._loadingDashboardStats) {
      console.log('Dashboard stats already loading, skipping...');
      return;
    }
    window._loadingDashboardStats = true;
    console.log('Loading dashboard stats...');
    try {
      const response = await fetch('../api/get_dashboard_stats.php');
      const data = await response.json();
      
      console.log('Dashboard API response:', data);
      
      if (data.success) {
        console.log('Stats data received:', data.stats);
        // Update main stats
        document.getElementById('todayRevenue').textContent = formatCurrencyNoDecimals(data.stats.todayRevenue);
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
        
        // Update time using user's timezone
        const timeElement = document.getElementById('dashboardTime');
        if (timeElement) {
          const now = new Date();
          var tz = window.userTimezone || Intl.DateTimeFormat().resolvedOptions().timeZone;
          try {
            timeElement.textContent = 'Last updated: ' + now.toLocaleTimeString([], { timeZone: tz, hour: '2-digit', minute: '2-digit' });
          } catch (e) {
            timeElement.textContent = 'Last updated: ' + now.toLocaleTimeString();
          }
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
    } finally {
      window._loadingDashboardStats = false;
    }
  }
  
  // Expose for inline scripts (dashboard.php) that call this on window load
  window.loadDashboardStats = loadDashboardStats;

  // Analytics page: load analytics data from get_analytics.php
  async function loadAnalytics() {
    var container = document.getElementById('analyticsContent');
    if (!container) return;
    
    var periodSelect = document.getElementById('analyticsPeriod');
    var days = periodSelect ? periodSelect.value : 30;
    
    // Show loading state
    container.innerHTML = '<div style="text-align:center;padding:60px 20px;"><div class="loading-spinner" style="width:32px;height:32px;border-width:3px;margin:0 auto 16px;border-color:#e5e7eb;border-top-color:#3b82f6;"></div><h3 style="margin:0 0 8px;color:#1f2937;font-size:1.1rem;">Loading Analytics...</h3><p style="color:#9ca3af;margin:0;font-size:0.9rem;">Fetching your data for the last ' + days + ' days.</p></div>';
    
    try {
      var resp = await fetch('../api/get_analytics.php?days=' + days, { cache: 'no-store' });
      var data = await resp.json();
      
      if (!data.success) {
        container.innerHTML = '<div style="text-align:center;padding:60px 20px;"><span class="material-symbols-rounded" style="font-size:48px;color:#ef4444;margin-bottom:12px;display:block;">error_outline</span><h3 style="margin:0 0 8px;color:#1f2937;">Failed to Load</h3><p style="color:#9ca3af;margin:0;">' + (data.message || 'Please try again.') + '</p></div>';
        return;
      }
      
      renderAnalytics(container, data);
    } catch (e) {
      container.innerHTML = '<div style="text-align:center;padding:60px 20px;"><span class="material-symbols-rounded" style="font-size:48px;color:#ef4444;margin-bottom:12px;display:block;">error_outline</span><h3 style="margin:0 0 8px;color:#1f2937;">Network Error</h3><p style="color:#9ca3af;margin:0;">Could not connect to the server. Please try again.</p></div>';
    }
  }
  window.loadAnalytics = loadAnalytics;

  // Render analytics HTML
  function renderAnalytics(container, data) {
    var cur = window.globalCurrencySymbol || '₹';
    var html = '';
    
    // Stats cards row
    html += '<div class="analytics-stats">';
    html += '  <div class="analytics-stat-card"><div class="stat-icon" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);"><span class="material-symbols-rounded">language</span></div><div class="stat-info"><div class="stat-label">Total Page Visits</div><div class="stat-value">' + (data.total_visits || 0) + '</div><div class="stat-sub">' + (data.unique_visitors || 0) + ' unique visitors</div></div></div>';
    html += '  <div class="analytics-stat-card"><div class="stat-icon" style="background:linear-gradient(135deg,#10b981,#059669);"><span class="material-symbols-rounded">receipt_long</span></div><div class="stat-info"><div class="stat-label">Total Orders</div><div class="stat-value">' + (data.total_orders || 0) + '</div><div class="stat-sub">' + (data.total_customers || 0) + ' customers</div></div></div>';
    html += '  <div class="analytics-stat-card"><div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);"><span class="material-symbols-rounded">payments</span></div><div class="stat-info"><div class="stat-label">Total Revenue</div><div class="stat-value">' + cur + (data.total_revenue || 0).toFixed(2) + '</div><div class="stat-sub">Last ' + data.period_days + ' days</div></div></div>';
    html += '  <div class="analytics-stat-card"><div class="stat-icon" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed);"><span class="material-symbols-rounded">restaurant_menu</span></div><div class="stat-info"><div class="stat-label">Active KOT</div><div class="stat-value">' + (data.active_kot || 0) + '</div><div class="stat-sub">In progress</div></div></div>';
    html += '</div>';
    
    // Two-column grid
    html += '<div class="analytics-grid-2">';
    
    // Column 1: Page Visits Breakdown
    html += '  <div class="section-card">';
    html += '    <h3><span class="material-symbols-rounded">bar_chart</span> Page Visits Breakdown</h3>';
    var pageVisits = data.page_visits || [];
    var maxPageCount = 0;
    for (var i = 0; i < pageVisits.length; i++) {
      if (pageVisits[i].count > maxPageCount) maxPageCount = parseInt(pageVisits[i].count);
    }
    if (pageVisits.length === 0) {
      html += '<div style="text-align:center;padding:30px;color:#9ca3af;font-size:13px;">No page visit data yet.</div>';
    } else {
      for (var i = 0; i < pageVisits.length; i++) {
        var pct = maxPageCount > 0 ? (parseInt(pageVisits[i].count) / maxPageCount * 100) : 0;
        html += '<div class="analytics-bar"><span class="bar-label">' + escapeHtml(pageVisits[i].page_name) + '</span><div class="bar-track"><div class="bar-fill" style="width:' + pct + '%;"><span class="bar-count">' + pageVisits[i].count + '</span></div></div><span class="bar-pct">' + (pct > 0 ? Math.round(pct) : 0) + '%</span></div>';
      }
    }
    html += '  </div>';
    
    // Column 2: Peak Hours
    html += '  <div class="section-card">';
    html += '    <h3><span class="material-symbols-rounded">schedule</span> Peak Visit Hours</h3>';
    var hours = data.visits_by_hour || [];
    var maxHourCount = 1;
    for (var i = 0; i < hours.length; i++) {
      if (parseInt(hours[i].count) > maxHourCount) maxHourCount = parseInt(hours[i].count);
    }
    if (hours.length === 0) {
      html += '<div style="text-align:center;padding:30px;color:#9ca3af;font-size:13px;">No data yet.</div>';
    } else {
      html += '<div class="hour-grid">';
      for (var h = 0; h < 24; h++) {
        var found = null;
        for (var j = 0; j < hours.length; j++) {
          if (parseInt(hours[j].hour) === h) { found = hours[j]; break; }
        }
        var count = found ? parseInt(found.count) : 0;
        var intensity = maxHourCount > 0 ? Math.round(count / maxHourCount * 100) : 0;
        var bgColor = count > 0 ? 'rgba(59, 130, 246, ' + (0.15 + (intensity / 100) * 0.75) + ')' : '#f9fafb';
        var txtColor = count > 0 ? '#1e40af' : '#9ca3af';
        html += '<div class="hour-block" style="background:' + bgColor + ';color:' + txtColor + ';"><div class="hour-label">' + (h === 0 ? '12a' : h < 12 ? h + 'a' : h === 12 ? '12p' : (h - 12) + 'p') + '</div><div class="hour-count">' + count + '</div></div>';
      }
      html += '</div>';
    }
    html += '  </div>';
    
    html += '</div>'; // close grid-2
    
    // Second row: Popular Items + Recent Visits
    html += '<div class="analytics-grid-2">';
    
    // Popular Items
    html += '  <div class="section-card">';
    html += '    <h3><span class="material-symbols-rounded">local_fire_department</span> Popular Items</h3>';
    var items = data.popular_items || [];
    if (items.length === 0) {
      html += '<div style="text-align:center;padding:30px;color:#9ca3af;font-size:13px;">No orders yet in this period.</div>';
    } else {
      html += '<table class="analytics-table"><thead><tr><th>Item</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Revenue</th></tr></thead><tbody>';
      for (var i = 0; i < items.length; i++) {
        html += '<tr><td>' + escapeHtml(items[i].item_name) + '</td><td style="text-align:center;font-weight:600;">' + items[i].total_qty + '</td><td style="text-align:right;font-weight:600;">' + cur + parseFloat(items[i].total_rev).toFixed(2) + '</td></tr>';
      }
      html += '</tbody></table>';
    }
    html += '  </div>';
    
    // Recent Visits
    html += '  <div class="section-card">';
    html += '    <h3><span class="material-symbols-rounded">history</span> Recent Page Visits</h3>';
    var visits = data.recent_visits || [];
    if (visits.length === 0) {
      html += '<div style="text-align:center;padding:30px;color:#9ca3af;font-size:13px;">No visits recorded yet.</div>';
    } else {
      for (var i = 0; i < Math.min(visits.length, 10); i++) {
        var v = visits[i];
        var dateStr = '';
        try {
          var d = new Date(v.visited_at);
          var now = new Date();
          var diffMs = now - d;
          var diffMins = Math.floor(diffMs / 60000);
          if (diffMins < 1) dateStr = 'Just now';
          else if (diffMins < 60) dateStr = diffMins + 'm ago';
          else if (diffMins < 1440) dateStr = Math.floor(diffMins / 60) + 'h ago';
          else dateStr = d.toLocaleDateString();
        } catch(e) { dateStr = v.visited_at || ''; }
        html += '<div class="visit-row"><span class="visit-page">' + escapeHtml(v.page_name || 'Unknown') + '</span><span class="visit-ip">' + (v.visitor_ip || '') + '</span><span class="visit-time">' + dateStr + '</span></div>';
      }
    }
    html += '  </div>';
    
    html += '</div>'; // close grid-2
    
    // Summary section
    html += '<div class="section-card">';
    html += '  <h3><span class="material-symbols-rounded">summarize</span> Quick Summary</h3>';
    var orderTypes = data.order_types || [];
    var statuses = data.order_statuses || [];
    if (orderTypes.length === 0 && statuses.length === 0) {
      html += '<div style="text-align:center;padding:20px;color:#9ca3af;font-size:13px;">No order data available for this period yet.</div>';
    } else {
      html += '  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;">';
      for (var i = 0; i < orderTypes.length; i++) {
        var typeColors = {dinein:'#8b5cf6',delivery:'#3b82f6',takeaway:'#f59e0b'};
        var color = typeColors[(orderTypes[i].order_type || '').toLowerCase()] || '#6b7280';
        html += '<div style="text-align:center;padding:12px;background:#f9fafb;border-radius:10px;"><div style="font-size:18px;font-weight:800;color:' + color + ';">' + orderTypes[i].count + '</div><div style="font-size:11px;color:#6b7280;text-transform:capitalize;">' + (orderTypes[i].order_type || 'Unknown') + '</div></div>';
      }
      for (var i = 0; i < statuses.length; i++) {
        var statusColors = {pending:'#f59e0b',accepted:'#3b82f6',preparing:'#8b5cf6',ready:'#10b981',served:'#059669',completed:'#6b7280',rejected:'#ef4444',cancelled:'#9ca3af'};
        var sc = statusColors[(statuses[i].order_status || '').toLowerCase()] || '#6b7280';
        html += '<div style="text-align:center;padding:12px;background:#f9fafb;border-radius:10px;"><div style="font-size:18px;font-weight:800;color:' + sc + ';">' + statuses[i].count + '</div><div style="font-size:11px;color:#6b7280;">' + (statuses[i].order_status || 'Unknown') + '</div></div>';
      }
      html += '  </div>';
    }
    html += '</div>';
    
    container.innerHTML = html;
  }

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
      const response = await fetch("../api/get_menus.php");
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

    if (menuSortMode) {
      if (menus.length > 0) {
        menuSortData = menus.map(m => ({ ...m }));
      }
      menuList.classList.add('sort-mode');
    } else {
      menuList.classList.remove('sort-mode');
    }

    if (!menuSortMode && menus.length === 0) {
      menuList.innerHTML = `
        <div class="empty-state">
          <span class="material-symbols-rounded">menu</span>
          <h3>No menus found</h3>
          <p>Create your first menu to get started.</p>
        </div>
      `;
      return;
    }

    const data = menuSortMode ? menuSortData : menus;

    menuList.innerHTML = data.map((menu, idx) => {
      const menuImageUrl = menu.menu_image && menu.menu_image.startsWith('db:') 
        ? `../api/image.php?type=menu&id=${menu.id}` 
        : (menu.menu_image || '');
      const imageHtml = menuImageUrl 
        ? `<div class="menu-image" style="width: 100%; height: 150px; overflow: hidden; border-radius: 8px; margin-bottom: 1rem;"><img src="${menuImageUrl}" alt="${escapeHtml(menu.menu_name)}" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none'"></div>`
        : '';

      if (menuSortMode) {
        const first = idx === 0;
        const last = idx === data.length - 1;
        return `
        <div class="menu-card" data-menu-id="${menu.id}" data-index="${idx}">
          <div class="sort-handle">
            <button onclick="moveMenuUp(${idx})" ${first ? 'disabled' : ''} title="Move up"><span class="material-symbols-rounded">keyboard_arrow_up</span></button>
            <button onclick="moveMenuDown(${idx})" ${last ? 'disabled' : ''} title="Move down"><span class="material-symbols-rounded">keyboard_arrow_down</span></button>
          </div>
          ${imageHtml}
          <h3>${escapeHtml(menu.menu_name)}</h3>
          <div class="menu-date">Created: ${formatDate(menu.created_at)}</div>
        </div>`;
      }

      return `
      <div class="menu-card" data-menu-id="${menu.id}">
        ${imageHtml}
        <h3>${escapeHtml(menu.menu_name)}</h3>
        <div class="menu-date">Created: ${formatDate(menu.created_at)}</div>
        <div class="menu-actions-card">
          <button class="btn-edit" onclick="editMenu(${menu.id}, '${escapeHtml(menu.menu_name)}', '${menuImageUrl || ''}')">
            <span class="material-symbols-rounded">edit</span>
            Edit
          </button>
          <button class="btn-delete" onclick="deleteMenu(${menu.id}, '${escapeHtml(menu.menu_name)}')">
            <span class="material-symbols-rounded">delete</span>
            Delete
          </button>
        </div>
      </div>
    `;
    }).join('');
  }

  window.moveMenuUp = function(idx) {
    if (idx <= 0) return;
    const tmp = menuSortData[idx - 1];
    menuSortData[idx - 1] = menuSortData[idx];
    menuSortData[idx] = tmp;
    displayMenus([]);
  };

  window.moveMenuDown = function(idx) {
    if (idx >= menuSortData.length - 1) return;
    const tmp = menuSortData[idx + 1];
    menuSortData[idx + 1] = menuSortData[idx];
    menuSortData[idx] = tmp;
    displayMenus([]);
  };

  window.saveMenuSort = async function() {
    const bar = document.getElementById('menuSortBar');
    const saveBtn = document.getElementById('saveMenuSortBtn');
    if (!saveBtn) return;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="loading-spinner"></span> Saving...';
    try {
      const order = menuSortData.map((menu, idx) => ({ id: menu.id, sort_order: idx }));
      const formData = new FormData();
      formData.append('action', 'reorder');
      formData.append('order', JSON.stringify(order));
      const res = await fetch('../controllers/menu_operations.php', { method: 'POST', body: formData });
      const result = await res.json();
      if (result.success) {
        showSweetAlert('Category order saved!', 'success', { timer: 2000, showConfirmButton: false });
        exitMenuSort();
        loadMenus();
      } else {
        showSweetAlert('Failed to save order: ' + (result.message || 'Unknown error'), 'error');
      }
    } catch (err) {
      showSweetAlert('Network error while saving order', 'error');
    } finally {
      if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<span class="material-symbols-rounded">save</span> Save Order';
      }
    }
  };

  function exitMenuSort() {
    menuSortMode = false;
    menuSortData = [];
    const bar = document.getElementById('menuSortBar');
    if (bar) bar.style.display = 'none';
    const list = document.getElementById('menuList');
    if (list) list.classList.remove('sort-mode');
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
  const deleteConfirmBtn = document.getElementById("deleteConfirmBtn");
  if (deleteConfirmBtn) {
    deleteConfirmBtn.addEventListener("click", async () => {
      if (!currentMenuId) return;
      
      const deleteBtn = document.getElementById("deleteConfirmBtn");
      if (deleteBtn) {
        deleteBtn.disabled = true;
        deleteBtn.textContent = "Deleting...";
      }

    try {
      const formData = new URLSearchParams();
      formData.append('action', 'delete');
      formData.append('menuId', currentMenuId);

      const response = await fetch("../controllers/menu_operations.php", {
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
      const deleteBtn = document.getElementById("deleteConfirmBtn");
      if (deleteBtn) {
        deleteBtn.disabled = false;
        deleteBtn.textContent = "Delete";
      }
    }
    });
  }

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
      const response = await fetch("../api/get_menus.php");
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
                <span style="font-weight: 500; color: #374151; font-size: 0.9rem;">${escapeHtml(sub.subcategory_name)}</span>
              </div>
              <div style="display: flex; gap: 0.25rem;">
                <button type="button" onclick="editSubcategory(${sub.id}, '${escapeHtml(sub.subcategory_name)}', ${menuId})" style="padding: 4px 8px; background: transparent; color: #6b7280; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85rem;" title="Edit">
                  <span class="material-symbols-rounded" style="font-size: 1.1rem;">edit</span>
                </button>
                <button type="button" onclick="deleteSubcategory(${sub.id}, '${escapeHtml(sub.subcategory_name)}')" style="padding: 4px 8px; background: transparent; color: #ef4444; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85rem;" title="Delete">
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
      
      const response = await fetch("../controllers/menu_operations.php", {
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
      
      const response = await fetch("../controllers/menu_operations.php", {
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
      const response = await fetch("../api/get_menus.php");
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
          opt.textContent = sub.subcategory_name;
          dropdown.appendChild(opt);
        });
        
        formGroup.style.display = "block";
        
        // Set selected value if provided (e.g., when editing)
        if (selectedValue !== undefined && selectedValue !== null) {
          dropdown.value = selectedValue;
        }
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
    fetch("../api/get_menus.php")
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
            opt.textContent = sub.subcategory_name;
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

  // Description format toggle
  var descFormatToggle = document.getElementById("descFormatToggle");
  var descFormatInput = document.getElementById("descriptionFormat");
  if (descFormatToggle) {
    descFormatToggle.addEventListener("click", function() {
      var current = descFormatInput.value;
      if (current === "paragraph") {
        descFormatInput.value = "br";
        descFormatToggle.classList.add("active");
        descFormatToggle.querySelector("#descFormatLabel").textContent = "Line Break";
        descFormatToggle.querySelector(".material-symbols-rounded").textContent = "format_line_spacing";
      } else {
        descFormatInput.value = "paragraph";
        descFormatToggle.classList.remove("active");
        descFormatToggle.querySelector("#descFormatLabel").textContent = "Paragraph";
        descFormatToggle.querySelector(".material-symbols-rounded").textContent = "format_align_left";
      }
    });
  }

  // Open modal for adding new menu item
  if (addMenuItemBtn) {
    addMenuItemBtn.addEventListener("click", (e) => {
      e.preventDefault();
      openMenuItemModal();
    });
  }

  // Sort menu items button
  const sortMenuItemsBtn = document.getElementById("sortMenuItemsBtn");
  if (sortMenuItemsBtn) {
    sortMenuItemsBtn.addEventListener("click", () => {
      if (menuItemSortMode) return;
      menuItemSortMode = true;
      const bar = document.getElementById('menuItemSortBar');
      if (bar) bar.style.display = 'flex';
      loadMenuItems();
    });
  }

  // Cancel menu item sort
  const cancelMenuItemSortBtn = document.getElementById("cancelMenuItemSortBtn");
  if (cancelMenuItemSortBtn) {
    cancelMenuItemSortBtn.addEventListener("click", () => {
      exitMenuItemSort();
      loadMenuItems();
    });
  }

  // Save menu item sort
  const saveMenuItemSortBtn = document.getElementById("saveMenuItemSortBtn");
  if (saveMenuItemSortBtn) {
    saveMenuItemSortBtn.addEventListener("click", function() { window.saveMenuItemSort(); });
  }
  
  // Add event listener for menu item cancel button
  if (menuItemCancelBtn) {
    menuItemCancelBtn.addEventListener("click", closeMenuItemModal);
  }

  // Open modal for editing existing menu item
  window.editMenuItem = function(menuItemId, menuItemData) {
    currentMenuItemId = menuItemId;
    currentMenuItemData = menuItemData;
    openMenuItemModal(true);
  };

  function openMenuItemModal(isEdit = false) {
    console.log("Opening menu item modal, isEdit:", isEdit);

    // Reset image state for fresh edit/add session
    cropApplied = false;
    const base64Reset = document.getElementById('itemImageBase64');
    if (base64Reset) {
      base64Reset.value = '';
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
        
        // Reset item type buttons - default to Other
        document.querySelectorAll('.type-btn').forEach(btn => btn.classList.remove('active'));
        const noneBtn = document.querySelector('.type-btn[data-type="Other"]');
        if (noneBtn) noneBtn.classList.add('active');
        document.getElementById('itemType').value = 'Other';
        
        // Reset checkbox
        const hasVariationsCheckbox = document.getElementById("hasVariations");
        if (hasVariationsCheckbox) {
          hasVariationsCheckbox.checked = false;
          toggleVariationsSection(); // Hide variations section
        }
        
        // Clear variations
        clearVariations();
        
        // Hide image preview
        const imagePreview = document.getElementById('imagePreview');
        if (imagePreview) {
          imagePreview.style.display = 'none';
        }
        
        // Clean up cropper
        const cropperSection = document.getElementById('imageCropperSection');
        if (cropperSection) {
          cropperSection.style.display = 'none';
        }
        if (imageCropper) {
          imageCropper.destroy();
          imageCropper = null;
        }
        
        // Reset file input
        const fileInput = document.getElementById('itemImage');
        if (fileInput) {
          fileInput.value = '';
        }
        
        // Reset crop applied flag
        cropApplied = false;
        
        // Reset base64 data
        const base64Input = document.getElementById('itemImageBase64');
        if (base64Input) {
          base64Input.value = '';
        }
        
        // Reset crop button appearance
        const cropBtn = document.getElementById('cropImageBtn');
        if (cropBtn) {
          cropBtn.style.background = '';
          cropBtn.style.color = '';
          cropBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size: 1.2rem; vertical-align: middle;">crop</span> Apply Crop';
        }
        
        // Reset file name display
        const fileNameSpan = document.querySelector('.file-name');
        if (fileNameSpan) {
          fileNameSpan.textContent = 'No file chosen';
        }
        
        // Reset description format toggle to paragraph
        if (descFormatInput) {
          descFormatInput.value = "paragraph";
        }
        if (descFormatToggle) {
          descFormatToggle.classList.remove("active");
          var lbl = descFormatToggle.querySelector("#descFormatLabel");
          var ico = descFormatToggle.querySelector(".material-symbols-rounded");
          if (lbl) lbl.textContent = "Paragraph";
          if (ico) ico.textContent = "format_align_left";
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
    // Set description format toggle
    var descFmt = data.description_format || 'paragraph';
    if (descFormatInput) descFormatInput.value = descFmt;
    if (descFormatToggle) {
      if (descFmt === 'br') {
        descFormatToggle.classList.add("active");
        var lbl = descFormatToggle.querySelector("#descFormatLabel");
        var ico = descFormatToggle.querySelector(".material-symbols-rounded");
        if (lbl) lbl.textContent = "Line Break";
        if (ico) ico.textContent = "format_line_spacing";
      } else {
        descFormatToggle.classList.remove("active");
        var lbl = descFormatToggle.querySelector("#descFormatLabel");
        var ico = descFormatToggle.querySelector(".material-symbols-rounded");
        if (lbl) lbl.textContent = "Paragraph";
        if (ico) ico.textContent = "format_align_left";
      }
    }
    // Subcategory: load and set based on selected menu
    if (data.menu_id) {
      loadSubcategoryDropdown(data.menu_id, data.subcategory_id);
    }
    document.getElementById("preparationTime").value = data.preparation_time || 0;
    document.getElementById("calories").value = data.calories || 0;
    document.getElementById("isAvailable").value = data.is_available ? '1' : '0';
    document.getElementById("basePrice").value = data.base_price || '0.00';
    
    // Fix checkbox handling
    const hasVariationsCheckbox = document.getElementById("hasVariations");
    if (hasVariationsCheckbox) {
      hasVariationsCheckbox.checked = data.has_variations == 1 || data.has_variations === true;
      toggleVariationsSection(); // Show/hide variations section
    }
    
    // Load variations if they exist
    if (data.variations && Array.isArray(data.variations) && data.variations.length > 0) {
      loadVariations(data.variations);
    } else {
      clearVariations();
    }
    
    // Set item type
    document.querySelectorAll('.type-btn').forEach(btn => btn.classList.remove('active'));
    const typeBtn = document.querySelector(`.type-btn[data-type="${data.item_type}"]`);
    if (typeBtn) {
      typeBtn.classList.add('active');
      document.getElementById('itemType').value = data.item_type;
    }
    
    // Show existing image if available with cache-busting
    if (data.item_image) {
      const imagePreview = document.getElementById('imagePreview');
      const previewImg = document.getElementById('previewImg');
      if (imagePreview && previewImg) {
        const timestamp = Date.now();
        let imageUrl;
        if (data.item_image.startsWith('db:')) {
          imageUrl = `../api/image.php?path=${encodeURIComponent(data.item_image)}&t=${timestamp}`;
        } else if (data.item_image.startsWith('http')) {
          imageUrl = data.item_image + (data.item_image.includes('?') ? '&' : '?') + `t=${timestamp}`;
        } else {
          imageUrl = `../api/image.php?path=${encodeURIComponent(data.item_image)}&t=${timestamp}`;
        }
        previewImg.src = imageUrl;
        imagePreview.style.display = 'block';
      }
    }

    // Set image URL field if it's a URL-based image
    const itemImageUrl = document.getElementById('itemImageUrl');
    if (itemImageUrl && data.item_image && data.item_image.startsWith('http')) {
      itemImageUrl.value = data.item_image;
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
    cropApplied = false;
    const base64Input = document.getElementById('itemImageBase64');
    if (base64Input) {
      base64Input.value = '';
    }
    
    // Clean up cropper
    if (imageCropper) {
      imageCropper.destroy();
      imageCropper = null;
    }
    
    // Hide cropper section
    const cropperSection = document.getElementById('imageCropperSection');
    if (cropperSection) {
      cropperSection.style.display = 'none';
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
  // Image cropper instance
  let imageCropper = null;
  
  const itemImageInput = document.getElementById('itemImage');
  if (itemImageInput) {
    itemImageInput.addEventListener('change', function(e) {
    const file = e.target.files[0];
    const fileNameSpan = document.querySelector('.file-name');
    const imagePreview = document.getElementById('imagePreview');
    const previewImg = document.getElementById('previewImg');
    const cropperSection = document.getElementById('imageCropperSection');
    const imageToCrop = document.getElementById('imageToCrop');
    const croppedPreviewImg = document.getElementById('croppedPreviewImg');
    const websitePreviewImage = document.getElementById('websitePreviewImage');
    
    if (file) {
      fileNameSpan.textContent = file.name;
      
      // Destroy existing cropper if any
      if (imageCropper) {
        imageCropper.destroy();
        imageCropper = null;
      }
      
      // Reset crop applied flag when new image is selected
      cropApplied = false;
      document.getElementById('itemImageBase64').value = '';
      
      // Reset crop button appearance
      const cropBtn = document.getElementById('cropImageBtn');
      if (cropBtn) {
        cropBtn.style.background = '';
        cropBtn.style.color = '';
        cropBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size: 1.2rem; vertical-align: middle;">crop</span> Apply Crop';
      }
      
      // Show preview and initialize cropper
      const reader = new FileReader();
      reader.onload = function(e) {
        const imageUrl = e.target.result;
        
        // Set image source for cropper
        imageToCrop.src = imageUrl;
        cropperSection.style.display = 'block';
        
        // Initialize Cropper.js
        // Match the website preview aspect ratio (width:height = ~1.5:1 for menu cards)
        imageCropper = new Cropper(imageToCrop, {
          aspectRatio: 1.5, // Match website card image ratio (wider than tall)
          viewMode: 1,
          dragMode: 'move',
          autoCropArea: 0.8,
          restore: false,
          guides: true,
          center: true,
          highlight: false,
          cropBoxMovable: true,
          cropBoxResizable: true,
          toggleDragModeOnDblclick: false,
          ready: function() {
            // Update preview when cropper is ready
            updateWebsitePreview();
            // Initialize crop buttons
            initializeCropButtons();
          },
          crop: function() {
            // Update preview on crop
            updateWebsitePreview();
          }
        });
        
        // Hide old preview
        imagePreview.style.display = 'none';
      };
      reader.readAsDataURL(file);
    } else {
      fileNameSpan.textContent = 'No file chosen';
      imagePreview.style.display = 'none';
      cropperSection.style.display = 'none';
      if (imageCropper) {
        imageCropper.destroy();
        imageCropper = null;
      }
      document.getElementById('itemImageBase64').value = '';
    }
    });
  }
  
  // Update website preview with cropped image
  function updateWebsitePreview() {
    if (!imageCropper) return;
    
    const croppedCanvas = imageCropper.getCroppedCanvas({
      width: 600,
      height: 400,
      imageSmoothingEnabled: true,
      imageSmoothingQuality: 'high'
    });
    
    if (croppedCanvas) {
      const croppedDataUrl = croppedCanvas.toDataURL('image/jpeg', 0.9);
      const croppedPreviewImg = document.getElementById('croppedPreviewImg');
      const websitePreviewImage = document.getElementById('websitePreviewImage');
      
      croppedPreviewImg.src = croppedDataUrl;
      croppedPreviewImg.style.display = 'block';
      
      // Hide placeholder emoji
      const placeholder = websitePreviewImage.querySelector('span');
      if (placeholder) placeholder.style.display = 'none';
      
      // Update preview item name if available
      const itemNameInput = document.getElementById('itemNameEn');
      if (itemNameInput && itemNameInput.value) {
        document.getElementById('previewItemName').textContent = itemNameInput.value;
      }
    }
  }
  
  // Track if crop has been applied
  let cropApplied = false;
  
  // Apply crop button (initialize when DOM is ready)
  function initializeCropButtons() {
    const cropBtn = document.getElementById('cropImageBtn');
    const resetBtn = document.getElementById('resetCropBtn');
    
    if (cropBtn) {
      // Remove existing listener if any
      const newCropBtn = cropBtn.cloneNode(true);
      cropBtn.parentNode.replaceChild(newCropBtn, cropBtn);
      
      newCropBtn.addEventListener('click', function() {
    if (!imageCropper) return;
        
        // Show loading state
        const originalBtnHTML = newCropBtn.innerHTML;
        newCropBtn.disabled = true;
        newCropBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size: 1.2rem; vertical-align: middle;">hourglass_empty</span> Processing...';
    
    const croppedCanvas = imageCropper.getCroppedCanvas({
      width: 1200,
      height: 800,
      imageSmoothingEnabled: true,
      imageSmoothingQuality: 'high'
    });
    
    if (croppedCanvas) {
      const croppedDataUrl = croppedCanvas.toDataURL('image/jpeg', 0.9);
      
      // Store cropped image as base64
      document.getElementById('itemImageBase64').value = croppedDataUrl;
          cropApplied = true; // Mark that crop has been applied
      
      // Update preview
      updateWebsitePreview();
          
          // Show success state on button
          newCropBtn.disabled = false;
          newCropBtn.style.background = '#10b981';
          newCropBtn.style.color = 'white';
          newCropBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size: 1.2rem; vertical-align: middle;">check_circle</span> Crop Applied!';
      
      // Show success message
        showMessage('Image cropped successfully!', 'success');
          
          // Reset button after 3 seconds
          setTimeout(() => {
            newCropBtn.style.background = '';
            newCropBtn.style.color = '';
            newCropBtn.innerHTML = originalBtnHTML;
          }, 3000);
        } else {
          // Error state
          newCropBtn.disabled = false;
          newCropBtn.innerHTML = originalBtnHTML;
          showMessage('Failed to crop image. Please try again.', 'error');
      }
    });
    }
    
    if (resetBtn) {
      // Remove existing listener if any
      const newResetBtn = resetBtn.cloneNode(true);
      resetBtn.parentNode.replaceChild(newResetBtn, resetBtn);
      
      newResetBtn.addEventListener('click', function() {
        if (imageCropper) {
          imageCropper.reset();
          updateWebsitePreview();
          // Clear cropped image and reset crop applied flag
          document.getElementById('itemImageBase64').value = '';
          cropApplied = false;
          
          // Reset crop button appearance
          const cropBtn = document.getElementById('cropImageBtn');
          if (cropBtn) {
            cropBtn.style.background = '';
            cropBtn.style.color = '';
            cropBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size: 1.2rem; vertical-align: middle;">crop</span> Apply Crop';
          }
        }
      });
    }
  }
  
  
  // Update preview when item name changes
  const itemNameInput = document.getElementById('itemNameEn');
  if (itemNameInput) {
    itemNameInput.addEventListener('input', function() {
      const previewItemName = document.getElementById('previewItemName');
      if (previewItemName) {
        previewItemName.textContent = this.value || 'Item Name';
      }
    });
  }

  // Handle menu item form submission
  if (menuItemForm) {
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
    
    // Handle image: if crop was not applied, use original file
    const itemImageBase64 = document.getElementById('itemImageBase64');
    const itemImageInput = document.getElementById('itemImage');
    
    // If crop was not applied and there's a file, use the original file
    if (!cropApplied && itemImageInput && itemImageInput.files && itemImageInput.files.length > 0) {
      // Remove base64 if it exists (from previous crop)
      if (itemImageBase64) {
        itemImageBase64.value = '';
      }
      // The original file will be sent via FormData automatically
    } else if (cropApplied && itemImageBase64 && itemImageBase64.value) {
      // Crop was applied, use the cropped base64 image
      // Remove the file input so it doesn't override the base64
      if (itemImageInput) {
        itemImageInput.value = '';
      }
    }
    
    // Add variations data if has variations is checked
    const hasVariations = document.getElementById("hasVariations").checked;
    if (hasVariations) {
      const variations = getVariationsData();
      formData.append('variations', JSON.stringify(variations));
    }
    
    // Disable save button and show loading
    menuItemSaveBtn.disabled = true;
    menuItemSaveBtn.textContent = isEdit ? "Updating..." : "Saving...";

    try {
      const response = await fetch("../controllers/menu_items_operations_base64.php", {
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
      if (menuItemSaveBtn) {
        menuItemSaveBtn.disabled = false;
        menuItemSaveBtn.textContent = isEdit ? "Update Menu Item" : "Save Menu Item";
      }
    }
    });
  }

  // Variations Management Functions
  function toggleVariationsSection() {
    const hasVariations = document.getElementById("hasVariations").checked;
    const variationsSection = document.getElementById("variationsSection");
    if (variationsSection) {
      variationsSection.style.display = hasVariations ? 'block' : 'none';
      if (!hasVariations) {
        clearVariations();
      } else if (document.getElementById("variationsList").children.length === 0) {
        // Add a default variation row if none exist
        addVariationRow();
      }
    }
  }

  function addVariationRow(variationData = null) {
    const variationsList = document.getElementById("variationsList");
    const noVariationsMessage = document.getElementById("noVariationsMessage");
    
    if (!variationsList) return;
    
    // Hide "no variations" message
    if (noVariationsMessage) {
      noVariationsMessage.style.display = 'none';
    }
    
    const rowId = 'variation_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    const row = document.createElement('div');
    row.id = rowId;
    row.className = 'variation-row';
    row.style.cssText = 'display: flex; gap: 0.75rem; align-items: center; padding: 0.75rem; background: white; border: 1px solid #e5e7eb; border-radius: 8px;';
    
    const currencySymbol = window.globalCurrencySymbol || '₹';
    
    row.innerHTML = `
      <input type="text" class="variation-name" placeholder="e.g., Small, Medium, Large" 
             value="${variationData ? (variationData.variation_name || '') : ''}" 
             style="flex: 1; padding: 0.625rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem;" required>
      <div style="position: relative; flex: 1;">
        <span style="position: absolute; left: 0.625rem; top: 50%; transform: translateY(-50%); color: #6b7280; font-weight: 600;">${currencySymbol}</span>
        <input type="number" class="variation-price" placeholder="0.00" min="0" step="0.01" 
               value="${variationData ? (variationData.price || '0.00') : ''}" 
               style="width: 100%; padding: 0.625rem 0.625rem 0.625rem 2rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem;" required>
      </div>
      <button type="button" onclick="removeVariationRow('${rowId}')" 
              style="padding: 0.625rem; background: #fee2e2; color: #dc2626; border: none; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; min-width: 36px; height: 36px;" 
              title="Remove variation">
        <span class="material-symbols-rounded" style="font-size: 1.2rem;">delete</span>
      </button>
    `;
    
    variationsList.appendChild(row);
  }

  window.removeVariationRow = function(rowId) {
    const row = document.getElementById(rowId);
    if (row) {
      row.remove();
    }
    
    // Show "no variations" message if list is empty
    const variationsList = document.getElementById("variationsList");
    const noVariationsMessage = document.getElementById("noVariationsMessage");
    if (variationsList && variationsList.children.length === 0 && noVariationsMessage) {
      noVariationsMessage.style.display = 'block';
    }
  }

  function getVariationsData() {
    const variations = [];
    const rows = document.querySelectorAll('.variation-row');
    
    rows.forEach(row => {
      const nameInput = row.querySelector('.variation-name');
      const priceInput = row.querySelector('.variation-price');
      
      if (nameInput && priceInput && nameInput.value.trim() && priceInput.value) {
        variations.push({
          variation_name: nameInput.value.trim(),
          price: parseFloat(priceInput.value) || 0.00
        });
      }
    });
    
    return variations;
  }

  function loadVariations(variations) {
    clearVariations();
    
    if (variations && variations.length > 0) {
      variations.forEach(variation => {
        addVariationRow(variation);
      });
    }
  }

  function clearVariations() {
    const variationsList = document.getElementById("variationsList");
    const noVariationsMessage = document.getElementById("noVariationsMessage");
    
    if (variationsList) {
      variationsList.innerHTML = '';
    }
    
    if (noVariationsMessage) {
      noVariationsMessage.style.display = 'block';
    }
  }

  // Make functions globally available
  window.toggleVariationsSection = toggleVariationsSection;
  window.addVariationRow = addVariationRow;

  // Load menus for dropdown
  async function loadMenusForDropdown() {
    try {
      const response = await fetch("../api/get_menus.php");
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
          option.textContent = menu.menu_name;
          menuSelect.appendChild(option);
        });
        
        // Add change listener to load subcategories
        menuSelect.onchange = function() {
          loadSubcategoryDropdown(this.value);
        };
        
        // Load subcategories for current selection if editing
        if (menuSelect.value) {
          loadSubcategoryDropdown(menuSelect.value);
        }
        
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
      const response = await fetch("../api/get_menus.php");
      const result = await response.json();
      
      if (result.success) {
        const menuFilter = document.getElementById("menuFilter");
        menuFilter.innerHTML = '<option value="">All Menus</option>';
        
        result.data.forEach(menu => {
          const option = document.createElement('option');
          option.value = menu.id;
          option.textContent = menu.menu_name;
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
      
      const response = await fetch(`../api/get_menu_items.php?${params}`);
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

    if (menuItemSortMode) {
      if (menuItems.length > 0) {
        menuItemSortData = menuItems.map(m => ({ ...m }));
      }
      menuItemsList.classList.add('sort-mode');
    } else {
      menuItemsList.classList.remove('sort-mode');
    }

    if (!menuItemSortMode && menuItems.length === 0) {
      menuItemsList.innerHTML = `
        <div class="empty-state">
          <span class="material-symbols-rounded">restaurant_menu</span>
          <h3>No menu items found</h3>
          <p>Create your first menu item to get started.</p>
        </div>
      `;
      return;
    }

    const data = menuItemSortMode ? menuItemSortData : menuItems;

    menuItemsList.innerHTML = data.map((item, idx) => {
      if (menuItemSortMode) {
        const first = idx === 0;
        const last = idx === data.length - 1;
        return `
        <div class="menu-item-card" data-item-id="${item.id}" data-index="${idx}">
          <div class="sort-handle">
            <button onclick="moveMenuItemUp(${idx})" ${first ? 'disabled' : ''} title="Move up"><span class="material-symbols-rounded">keyboard_arrow_up</span></button>
            <button onclick="moveMenuItemDown(${idx})" ${last ? 'disabled' : ''} title="Move down"><span class="material-symbols-rounded">keyboard_arrow_down</span></button>
          </div>
          <div class="item-image">
            ${item.item_image ? `<img src="../api/image.php?path=${encodeURIComponent(item.item_image)}" alt="${escapeHtml(item.item_name_translated || item.item_name_en)}" loading="lazy" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
              <div class="no-image" style="display:none;"><span class="material-symbols-rounded">image</span></div>` : '<div class="no-image"><span class="material-symbols-rounded">image</span></div>'}
          </div>
          <div class="item-details">
            <h3>${escapeHtml(item.item_name_translated || item.item_name_en)}</h3>
            <p class="item-description">${item.description_format === 'br' ? escapeHtml(item.item_description_translated || item.item_description_en || 'No description').replace(/\n/g, '<br>') : escapeHtml(item.item_description_translated || item.item_description_en || 'No description')}</p>
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
        </div>`;
      }

      return `
      <div class="menu-item-card" data-item-id="${item.id}">
        <div class="item-image">
          ${item.item_image ? `<img src="../api/image.php?path=${encodeURIComponent(item.item_image)}" alt="${escapeHtml(item.item_name_translated || item.item_name_en)}" loading="lazy" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <div class="no-image" style="display:none;"><span class="material-symbols-rounded">image</span></div>` : '<div class="no-image"><span class="material-symbols-rounded">image</span></div>'}
        </div>
        <div class="item-details">
          <h3>${escapeHtml(item.item_name_translated || item.item_name_en)}</h3>
          <p class="item-description">${item.description_format === 'br' ? escapeHtml(item.item_description_translated || item.item_description_en || 'No description').replace(/\n/g, '<br>') : escapeHtml(item.item_description_translated || item.item_description_en || 'No description')}</p>
          <div class="item-meta">
            <span class="item-category">${escapeHtml(item.item_category || 'Uncategorized')}</span>
            <span class="item-type ${item.item_type.toLowerCase().replace(' ', '-')}">${item.item_type}</span>
            <span class="item-price">${formatCurrency(item.base_price)}</span>
          </div>
          <div class="item-info">
            <span class="menu-name">${escapeHtml(item.menu_name)}</span>
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
    `;
    }).join('');
  }

  window.moveMenuItemUp = function(idx) {
    if (idx <= 0) return;
    const tmp = menuItemSortData[idx - 1];
    menuItemSortData[idx - 1] = menuItemSortData[idx];
    menuItemSortData[idx] = tmp;
    displayMenuItems([]);
  };

  window.moveMenuItemDown = function(idx) {
    if (idx >= menuItemSortData.length - 1) return;
    const tmp = menuItemSortData[idx + 1];
    menuItemSortData[idx + 1] = menuItemSortData[idx];
    menuItemSortData[idx] = tmp;
    displayMenuItems([]);
  };

  window.saveMenuItemSort = async function() {
    const bar = document.getElementById('menuItemSortBar');
    const saveBtn = document.getElementById('saveMenuItemSortBtn');
    if (!saveBtn) return;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="loading-spinner"></span> Saving...';
    try {
      const order = menuItemSortData.map((item, idx) => ({ id: item.id, sort_order: idx }));
      const formData = new FormData();
      formData.append('action', 'reorder');
      formData.append('order', JSON.stringify(order));
      const res = await fetch('../controllers/menu_items_operations_base64.php', { method: 'POST', body: formData });
      const result = await res.json();
      if (result.success) {
        showSweetAlert('Menu item order saved!', 'success', { timer: 2000, showConfirmButton: false });
        exitMenuItemSort();
        loadMenuItems();
      } else {
        showSweetAlert('Failed to save order: ' + (result.message || 'Unknown error'), 'error');
      }
    } catch (err) {
      showSweetAlert('Network error while saving order', 'error');
    } finally {
      if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<span class="material-symbols-rounded">save</span> Save Order';
      }
    }
  };

  function exitMenuItemSort() {
    menuItemSortMode = false;
    menuItemSortData = [];
    const bar = document.getElementById('menuItemSortBar');
    if (bar) bar.style.display = 'none';
    const list = document.getElementById('menuItemsList');
    if (list) list.classList.remove('sort-mode');
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
  const deleteMenuItemConfirmBtn = document.getElementById("deleteConfirmBtn");
  if (deleteMenuItemConfirmBtn) {
    deleteMenuItemConfirmBtn.addEventListener("click", async () => {
      if (!currentMenuItemId) return;
      
      const deleteBtn = document.getElementById("deleteConfirmBtn");
      if (deleteBtn) {
        deleteBtn.disabled = true;
        deleteBtn.textContent = "Deleting...";
      }

    try {
      const formData = new URLSearchParams();
      formData.append('action', 'delete');
      formData.append('menuItemId', currentMenuItemId);

      const response = await fetch("../controllers/menu_items_operations_base64.php", {
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
      const deleteBtn = document.getElementById("deleteConfirmBtn");
      if (deleteBtn) {
        deleteBtn.disabled = false;
        deleteBtn.textContent = "Delete";
      }
    }
    });
  }

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

        const response = await fetch("../controllers/area_operations.php", {
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
        showMessage("Network error. Please check your connection and try again.", "error");
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
      const response = await fetch("../api/get_areas.php");
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
    if (await showSweetConfirm(`Are you sure you want to delete "${areaName}"? This action cannot be undone.`, 'Delete Area')) {
      // Call delete API
      const formData = new URLSearchParams();
      formData.append('action', 'delete');
      formData.append('areaId', areaId);
      
      fetch("../controllers/area_operations.php", {
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
        showMessage("Network error. Please check your connection and try again.", "error");
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
      const response = await fetch("../api/get_areas.php");
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

        const response = await fetch("../controllers/table_operations.php", {
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
        showMessage("Network error. Please check your connection and try again.", "error");
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
      const response = await fetch("../api/get_tables.php");
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
      const response = await fetch("../api/get_tables.php");
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
    
        // Get restaurant ID from the dashboard context
    var rid = document.querySelector('meta[name="restaurant-id"]')?.content || window.restaurant_id || document.getElementById('restaurantId')?.textContent || '';
    
    // Determine base URL - use custom domain if available
    var baseUrl;
    if (window.restaurantCustomDomain && window.restaurantEmbedEnabled) {
      var domain = window.restaurantCustomDomain.replace(/^https?:\/\//, '');
      var scheme = window.location.protocol + '//';
      baseUrl = scheme + domain + '/main/website/index.php?restaurant_id=' + encodeURIComponent(rid);
    } else {
      var basePath = window.location.origin + window.location.pathname.substring(0, window.location.pathname.indexOf('/main/') + 6) + 'website/index.php';
      baseUrl = basePath + '?restaurant_id=' + encodeURIComponent(rid);
    }
    
    qrGrid.innerHTML = tables.map(table => {
      const tableUrl = `${baseUrl}&table=${encodeURIComponent(table.table_number)}`;
      const qrCodeUrl = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(tableUrl)}`;
      
      return `        <div class="qr-code-card">
          <div class="qr-code-image">
            <img src="${qrCodeUrl}" alt="QR Code for ${escapeHtml(table.table_number)}" >
          </div>
          <div class="qr-code-info">
            <div class="qr-code-table-name">${escapeHtml(table.table_number)}</div>
            <div class="qr-code-area">${escapeHtml(table.area_name)}</div>
            <div class="qr-code-actions">
              <button class="qr-open-btn" onclick="window.open('${tableUrl}', '_blank')" title="Open in new tab">
                <span class="material-symbols-rounded">open_in_new</span>
                Visit
              </button>
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
        </div>`;
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
    if (await showSweetConfirm(`Are you sure you want to delete table "${tableNumber}"? This action cannot be undone.`, 'Delete Table')) {
      // Call delete API
      const formData = new URLSearchParams();
      formData.append('action', 'delete');
      formData.append('tableId', tableId);
      
      fetch("../controllers/table_operations.php", {
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
        showMessage("Network error. Please check your connection and try again.", "error");
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
    if (!reservationModal) {
      console.error('Reservation modal not found');
      return;
    }
    
    // Clear all errors when opening modal
    clearReservationFormErrors();
    
    if (isEdit) {
      if (reservationModalTitle) reservationModalTitle.textContent = "Edit Reservation";
      if (reservationSaveBtn) reservationSaveBtn.textContent = "Update Reservation";
    } else {
      if (reservationModalTitle) reservationModalTitle.textContent = "New Reservation";
      if (reservationForm) reservationForm.reset();
      if (reservationIdInput) reservationIdInput.value = "";
      const today = new Date().toISOString().split('T')[0];
      if (reservationDateInput) reservationDateInput.value = today;
      if (noOfGuestsInput) noOfGuestsInput.value = 1;
      if (mealTypeSelect) mealTypeSelect.value = "Lunch";
      selectedTimeSlot = null;
      if (selectTableSelect) selectTableSelect.value = "";
      if (reservationSaveBtn) reservationSaveBtn.textContent = "Reserve Now";
    }
    
    loadTablesForDropdown();
    generateTimeSlots();
    
    reservationModal.style.display = "block";
    document.body.style.overflow = "hidden";
    
    setTimeout(() => {
      if (customerNameInput) customerNameInput.focus();
    }, 150);
  }
  
  function closeReservationModal() {
    if (reservationModal) {
      reservationModal.style.display = "none";
    }
    document.body.style.overflow = "auto";
    if (reservationForm) reservationForm.reset();
    clearReservationFormErrors();
    currentReservationId = null;
    selectedTimeSlot = null;
  }
  
  // Add real-time validation for all fields
  if (reservationForm) {
    // Phone number validation - must be 10 digits
    if (phoneInput) {
      phoneInput.addEventListener('input', function(e) {
        const phone = e.target.value.trim();
        if (phone === '') {
          clearFieldError('phone');
        } else {
          const phoneDigits = phone.replace(/\D/g, '');
          if (phoneDigits.length !== 10) {
            showFieldError('phone', 'Phone number must be exactly 10 digits. Please enter a valid 10-digit phone number.');
          } else {
            clearFieldError('phone');
          }
        }
      });
      
      phoneInput.addEventListener('blur', function(e) {
        const phone = e.target.value.trim();
        if (phone === '') {
          // Phone is optional, clear any errors
          clearFieldError('phone');
        } else {
          const phoneDigits = phone.replace(/\D/g, '');
          if (phoneDigits.length !== 10) {
            showFieldError('phone', 'Phone number must be exactly 10 digits. Please enter a valid 10-digit phone number.');
          } else {
            clearFieldError('phone');
          }
        }
      });
    }
    
    // Customer name validation
    if (customerNameInput) {
      customerNameInput.addEventListener('blur', function(e) {
        const name = e.target.value.trim();
        if (name === '') {
          showFieldError('customerName', 'Customer name is required');
        } else if (name.length < 2) {
          showFieldError('customerName', 'Customer name must be at least 2 characters long.');
        } else if (name.length > 100) {
          showFieldError('customerName', 'Customer name must be less than 100 characters.');
        } else {
          clearFieldError('customerName');
        }
      });
      
      customerNameInput.addEventListener('input', function(e) {
        const name = e.target.value.trim();
        if (name.length > 100) {
          showFieldError('customerName', 'Customer name must be less than 100 characters.');
        } else if (name.length >= 2 || name === '') {
          clearFieldError('customerName');
        }
      });
    }
    
    // Email validation
    if (emailInput) {
      emailInput.addEventListener('blur', function(e) {
        const email = e.target.value.trim();
        if (email !== '') {
          const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
          if (!emailRegex.test(email)) {
            showFieldError('email', 'Invalid email format. Please enter a valid email address.');
          } else {
            clearFieldError('email');
          }
        } else {
          clearFieldError('email');
        }
      });
    }
    
    // Number of guests validation
    if (noOfGuestsInput) {
      noOfGuestsInput.addEventListener('blur', function(e) {
        const guests = parseInt(e.target.value) || 0;
        if (guests < 1) {
          showFieldError('noOfGuests', 'Number of guests must be at least 1.');
        } else if (guests > 50) {
          showFieldError('noOfGuests', 'Number of guests cannot exceed 50.');
        } else {
          clearFieldError('noOfGuests');
        }
      });
      
      noOfGuestsInput.addEventListener('input', function(e) {
        const guests = parseInt(e.target.value) || 0;
        if (guests > 50) {
          showFieldError('noOfGuests', 'Number of guests cannot exceed 50.');
        } else if (guests >= 1 || e.target.value === '') {
          clearFieldError('noOfGuests');
        }
      });
    }
    
    // Date validation
    if (reservationDateInput) {
      reservationDateInput.addEventListener('change', function(e) {
        const date = e.target.value;
        if (!date) {
          showFieldError('reservationDate', 'Reservation date is required');
        } else {
          const selectedDate = new Date(date);
          const today = new Date();
          today.setHours(0, 0, 0, 0);
          if (selectedDate < today) {
            showFieldError('reservationDate', 'Reservation date cannot be in the past.');
          } else {
            clearFieldError('reservationDate');
          }
        }
      });
    }
    
    // Special request validation
    if (specialRequestInput) {
      specialRequestInput.addEventListener('input', function(e) {
        const text = e.target.value.trim();
        if (text.length > 500) {
          showFieldError('specialRequest', 'Special request must be less than 500 characters.');
        } else {
          clearFieldError('specialRequest');
        }
      });
    }
    
    // Meal type validation
    if (mealTypeSelect) {
      mealTypeSelect.addEventListener('change', function(e) {
        if (!e.target.value) {
          showFieldError('mealType', 'Meal type is required');
        } else {
          clearFieldError('mealType');
        }
      });
    }
    
    // General input/change handlers to clear errors when user types
    reservationForm.addEventListener('input', function(e) {
      if (e.target.id && !['phone', 'customerName', 'email', 'noOfGuests', 'specialRequest'].includes(e.target.id)) {
        clearFieldError(e.target.id);
      }
    });
    
    reservationForm.addEventListener('change', function(e) {
      if (e.target.id && !['phone', 'customerName', 'email', 'noOfGuests', 'mealType', 'reservationDate'].includes(e.target.id)) {
        clearFieldError(e.target.id);
      }
    });
    
    // Initialize autocomplete for reservation form customer fields
    if (customerNameInput && phoneInput) {
      // Autocomplete for customer name field
      initCustomerAutocomplete(customerNameInput, {
        nameField: customerNameInput,
        phoneField: phoneInput,
        emailField: emailInput,
        onSelect: (customer) => {
          // Customer selected, refresh customers tab
          if (typeof loadCustomers === 'function') {
            setTimeout(() => loadCustomers(), 500);
          }
        }
      });
      
      // Autocomplete for phone field
      initCustomerAutocomplete(phoneInput, {
        nameField: customerNameInput,
        phoneField: phoneInput,
        emailField: emailInput,
        onSelect: (customer) => {
          // Customer selected, refresh customers tab
          if (typeof loadCustomers === 'function') {
            setTimeout(() => loadCustomers(), 500);
          }
        }
      });
    }
  }
  
  // Clear time slot error when a slot is selected
  document.addEventListener('click', function(e) {
    if (e.target.classList.contains('time-slot-btn') || e.target.closest('.time-slot-btn')) {
      clearFieldError('timeSlot');
    }
  });
  
  // Clear custom time when a predefined slot is selected
  document.addEventListener('click', function(e) {
    if (e.target.classList.contains('time-slot-btn') && !e.target.classList.contains('custom-time-btn')) {
      const customContainer = document.getElementById('customTimeSlotContainer');
      const customInput = document.getElementById('customTimeSlot');
      if (customContainer) customContainer.style.display = 'none';
      if (customInput) customInput.value = '';
    }
  });
  
  if (reservationCancelBtn) {
    reservationCancelBtn.addEventListener("click", closeReservationModal);
  }
  
  // Convert 24-hour format to 12-hour format
  function convertTo12Hour(time24) {
    if (!time24) return '';
    // Handle formats like "13:00", "13:00:00", "01:00 PM", etc.
    if (time24.includes('PM') || time24.includes('AM')) {
      return time24; // Already in 12-hour format
    }
    const [hours, minutes] = time24.split(':');
    const hour = parseInt(hours);
    if (hour === 0) return `12:${minutes || '00'} AM`;
    if (hour === 12) return `12:${minutes || '00'} PM`;
    if (hour < 12) return `${hour}:${minutes || '00'} AM`;
    return `${hour - 12}:${minutes || '00'} PM`;
  }
  
  // Convert 12-hour format to 24-hour format
  function convertTo24Hour(time12) {
    if (!time12) return '';
    const timeStr = time12.trim();
    
    // Handle formats like "13:00", "13:00:00" (already 24-hour)
    if (!/AM|PM/i.test(timeStr)) {
      // Already in 24-hour format, just ensure it's in HH:MM format
      const parts = timeStr.split(':');
      if (parts.length >= 2) {
        const hour = parseInt(parts[0]) || 0;
        const minute = parseInt(parts[1]) || 0;
        return `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}`;
      }
      return timeStr;
    }
    
    // Extract AM/PM
    const isPM = /PM/i.test(timeStr);
    const time = timeStr.replace(/\s*(AM|PM)/i, '').trim();
    const [hours, minutes] = time.split(':');
    let hour = parseInt(hours) || 0;
    const minute = parseInt(minutes) || 0;
    
    // Convert to 24-hour format
    if (isPM && hour !== 12) {
      hour += 12;
    } else if (!isPM && hour === 12) {
      hour = 0;
    }
    
    return `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}`;
  }
  
  // Generate time slots
  function generateTimeSlots() {
    const slots = ['12:00 PM', '01:00 PM', '02:00 PM', '03:00 PM', '04:00 PM', '05:00 PM', '06:00 PM', '07:00 PM', '08:00 PM', '09:00 PM', '10:00 PM'];
    
    if (!timeSlotsDiv) {
      console.error('Time slots div not found');
      return;
    }
    
    timeSlotsDiv.innerHTML = slots.map(slot => `
      <button type="button" class="time-slot-btn" data-slot="${slot}">${slot}</button>
    `).join('') + `
      <button type="button" class="time-slot-btn custom-time-btn" data-slot="custom" style="background: #f3f4f6; border: 2px dashed #9ca3af;">
        <span class="material-symbols-rounded" style="font-size: 1.2rem; vertical-align: middle;">schedule</span>
        Custom Time
      </button>
    `;
    
    // Add click handlers for predefined slots
    document.querySelectorAll('.time-slot-btn:not(.custom-time-btn)').forEach(btn => {
      btn.addEventListener('click', function() {
        document.querySelectorAll('.time-slot-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        selectedTimeSlot = this.dataset.slot;
        
        // Hide custom time input
        const customContainer = document.getElementById('customTimeSlotContainer');
        const customInput = document.getElementById('customTimeSlot');
        if (customContainer) customContainer.style.display = 'none';
        if (customInput) customInput.value = '';
        clearFieldError('timeSlot');
      });
    });
    
    // Add click handler for custom time button
    const customBtn = document.querySelector('.custom-time-btn');
    if (customBtn) {
      customBtn.addEventListener('click', function() {
        document.querySelectorAll('.time-slot-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        // Show custom time input
        const customContainer = document.getElementById('customTimeSlotContainer');
        const customInput = document.getElementById('customTimeSlot');
        if (customContainer) {
          customContainer.style.display = 'block';
          setTimeout(() => {
            if (customInput) customInput.focus();
          }, 100);
        }
        
        // Clear selected time slot - will be set from custom input
        selectedTimeSlot = null;
        clearFieldError('timeSlot');
      });
    }
    
    // Handle custom time input
    const customTimeInput = document.getElementById('customTimeSlot');
    if (customTimeInput) {
      customTimeInput.addEventListener('change', function() {
        const timeValue = this.value; // Format: HH:MM (24-hour)
        if (timeValue) {
          // Validate time format
          if (!/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(timeValue)) {
            showFieldError('timeSlot', 'Invalid time format. Please enter a valid time (HH:MM).');
            return;
          }
          
          // Convert 24-hour format to 12-hour format for display
          const [hours, minutes] = timeValue.split(':');
          const hour = parseInt(hours);
          const minute = minutes || '00';
          
          let time12Hour = '';
          if (hour === 0) {
            time12Hour = `12:${minute} AM`;
          } else if (hour === 12) {
            time12Hour = `12:${minute} PM`;
          } else if (hour < 12) {
            time12Hour = `${hour}:${minute} AM`;
          } else {
            time12Hour = `${hour - 12}:${minute} PM`;
          }
          
          selectedTimeSlot = time12Hour;
          clearFieldError('timeSlot');
        } else {
          showFieldError('timeSlot', 'Please enter a custom time.');
        }
      });
      
      customTimeInput.addEventListener('blur', function() {
        if (this.value) {
          const timeValue = this.value;
          if (!/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(timeValue)) {
            showFieldError('timeSlot', 'Invalid time format. Please enter a valid time (HH:MM).');
          } else {
            clearFieldError('timeSlot');
          }
        }
      });
      
      customTimeInput.addEventListener('input', function() {
        if (this.value) {
          clearFieldError('timeSlot');
        }
      });
    }
    
    // Set previously selected slot if editing (convert from 24-hour to 12-hour if needed)
    if (selectedTimeSlot) {
      const slot12Hour = convertTo12Hour(selectedTimeSlot);
      const btn = document.querySelector(`.time-slot-btn[data-slot="${slot12Hour}"]`);
      if (btn) {
        btn.classList.add('active');
        selectedTimeSlot = slot12Hour; // Update to 12-hour format
      } else {
        // If not found in predefined slots, it's a custom time
        const customBtn = document.querySelector('.custom-time-btn');
        const customContainer = document.getElementById('customTimeSlotContainer');
        const customInput = document.getElementById('customTimeSlot');
        
        if (customBtn && customContainer && customInput) {
          customBtn.classList.add('active');
          customContainer.style.display = 'block';
          
          // Convert 12-hour to 24-hour for the time input
          const time24 = convertTo24Hour(selectedTimeSlot);
          if (time24) {
            customInput.value = time24;
          }
        }
      }
    }
  }
  
  // Load tables for dropdown
  async function loadTablesForDropdown() {
    try {
      const response = await fetch("../api/get_tables.php");
      const result = await response.json();
      
      if (result.success && selectTableSelect) {
        // Store current value if editing
        const currentValue = selectTableSelect.value;
        
        selectTableSelect.innerHTML = '<option value="">-- Select Table --</option>';
        
        if (result.data && Array.isArray(result.data)) {
          result.data.forEach(table => {
            const option = document.createElement('option');
            option.value = table.id;
            option.textContent = `${table.table_number} - ${table.area_name} (${table.capacity} seats)`;
            selectTableSelect.appendChild(option);
          });
        }
        
        // Restore previous value if it exists
        if (currentValue && currentValue !== '') {
          selectTableSelect.value = currentValue;
        }
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
      let timeSlot = selectedTimeSlot;
      const noOfGuests = parseInt(noOfGuestsInput.value) || 1;
      const mealType = mealTypeSelect.value;
      const customerName = customerNameInput.value.trim();
      const phone = phoneInput.value.trim();
      const email = emailInput.value.trim();
      const specialRequest = specialRequestInput.value.trim();
      const tableId = parseInt(selectTableSelect.value) || null;
      const reservationId = reservationIdInput.value;
      const isEdit = reservationId !== "";
      
      // Clear previous errors
      clearReservationFormErrors();
      
      // Validate all fields
      const errors = [];
      
      if (!reservationDate) {
        errors.push("Reservation date is required");
        showFieldError('reservationDate', 'Date is required');
      } else {
        clearFieldError('reservationDate');
      }
      
      if (!noOfGuests || noOfGuests < 1) {
        errors.push("Number of guests must be at least 1");
        showFieldError('noOfGuests', 'Number of guests must be at least 1');
      } else {
        clearFieldError('noOfGuests');
      }
      
      if (!mealType) {
        errors.push("Meal type is required");
        showFieldError('mealType', 'Meal type is required');
      } else {
        clearFieldError('mealType');
      }
      
      // Check if custom time is selected
      const customTimeInput = document.getElementById('customTimeSlot');
      const customContainer = document.getElementById('customTimeSlotContainer');
      const isCustomTimeSelected = customContainer && customContainer.style.display === 'block' && customTimeInput && customTimeInput.value;
      
      if (!selectedTimeSlot && !isCustomTimeSelected) {
        errors.push("Please select a time slot or enter a custom time");
        showFieldError('timeSlot', 'Time slot is required. Please select a time slot or enter a custom time.');
      } else {
        // If custom time is selected, use it
        if (isCustomTimeSelected && customTimeInput.value) {
          const timeValue = customTimeInput.value; // Already in 24-hour format (HH:MM)
          timeSlot = timeValue;
          selectedTimeSlot = convertTo12Hour(timeValue); // For display purposes
        } else if (selectedTimeSlot) {
          // Convert selected time slot to 24-hour format
          timeSlot = convertTo24Hour(selectedTimeSlot);
        }
        clearFieldError('timeSlot');
      }
      
      if (!customerName || customerName.trim() === '') {
        errors.push("Customer name is required");
        showFieldError('customerName', 'Customer name is required');
      } else {
        clearFieldError('customerName');
      }
      
      // Validate phone number (optional - only validate format if provided)
      if (phone && phone.trim() !== '') {
        // Remove all non-digit characters for validation
        const phoneDigits = phone.replace(/\D/g, '');
        if (phoneDigits.length !== 10) {
          errors.push("Phone number must be exactly 10 digits");
          showFieldError('phone', 'Phone number must be exactly 10 digits. Please enter a valid 10-digit phone number.');
        } else {
          clearFieldError('phone');
        }
      } else {
        clearFieldError('phone');
      }
      
      // Validate email format if provided
      if (email && email.trim() !== '') {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
          errors.push("Invalid email format");
          showFieldError('email', 'Invalid email format. Please enter a valid email address.');
        } else {
          clearFieldError('email');
        }
      } else {
        clearFieldError('email');
      }
      
      // Validate customer name length
      if (customerName && customerName.trim() !== '') {
        if (customerName.trim().length < 2) {
          errors.push("Customer name must be at least 2 characters");
          showFieldError('customerName', 'Customer name must be at least 2 characters long.');
        } else if (customerName.trim().length > 100) {
          errors.push("Customer name must be less than 100 characters");
          showFieldError('customerName', 'Customer name must be less than 100 characters.');
        } else {
          clearFieldError('customerName');
        }
      }
      
      // Validate number of guests
      if (noOfGuests) {
        if (noOfGuests < 1) {
          errors.push("Number of guests must be at least 1");
          showFieldError('noOfGuests', 'Number of guests must be at least 1.');
        } else if (noOfGuests > 50) {
          errors.push("Number of guests cannot exceed 50");
          showFieldError('noOfGuests', 'Number of guests cannot exceed 50.');
        } else {
          clearFieldError('noOfGuests');
        }
      }
      
      // Validate special request length
      if (specialRequest && specialRequest.trim() !== '') {
        if (specialRequest.trim().length > 500) {
          errors.push("Special request must be less than 500 characters");
          showFieldError('specialRequest', 'Special request must be less than 500 characters.');
        } else {
          clearFieldError('specialRequest');
        }
      }
      
      // Show errors if any
      if (errors.length > 0) {
        showReservationFormErrors(errors);
        showMessage("Please fix the errors in the form", "error");
        return;
      }
      
      // Validate time slot after conversion
      if (!timeSlot || !/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(timeSlot)) {
        console.error("Invalid time slot after conversion:", timeSlot);
        errors.push("Invalid time slot format. Please select a time slot or enter a valid custom time.");
        showFieldError('timeSlot', 'Invalid time slot format. Please select a time slot or enter a valid custom time.');
        showReservationFormErrors(errors);
        showMessage("Invalid time slot format. Please select a time slot or enter a valid custom time.", "error");
        return;
      }

      // Disable save button and show loading
      reservationSaveBtn.disabled = true;
      reservationSaveBtn.textContent = isEdit ? "Updating..." : "Reserving...";

      try {
        // Log form data before sending
        console.log("=== Reservation Form Submission ===");
        console.log("Action:", isEdit ? 'update' : 'add');
        console.log("Reservation Date:", reservationDate);
        console.log("Time Slot (before conversion):", selectedTimeSlot);
        console.log("Time Slot (after conversion):", timeSlot);
        console.log("Number of Guests:", noOfGuests);
        console.log("Meal Type:", mealType);
        console.log("Customer Name:", customerName);
        console.log("Phone:", phone);
        console.log("Email:", email);
        console.log("Special Request:", specialRequest);
        console.log("Table ID:", tableId);
        console.log("Reservation ID:", reservationId);
        
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

        console.log("Form Data:", formData.toString());
        console.log("Sending request to: ../controllers/reservation_operations.php");

        const response = await fetch("../controllers/reservation_operations.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
          },
          body: formData
        });

        console.log("Response Status:", response.status, response.statusText);
        console.log("Response OK:", response.ok);
        console.log("Response Headers:", Object.fromEntries(response.headers.entries()));

        let result;
        let responseText = '';
        try {
          responseText = await response.text();
          console.log("Raw Response Text Length:", responseText.length);
          console.log("Raw Response Text:", responseText);
          
          // Check if response is empty
          if (!responseText || responseText.trim() === '') {
            console.error("=== Empty Response ===");
            console.error("Response Status:", response.status);
            showMessage("Server returned empty response. Please check server logs.", "error");
            return;
          }
          
          // Try to parse as JSON
          try {
            result = JSON.parse(responseText);
            console.log("Parsed Response:", result);
          } catch (parseError) {
            console.error("=== JSON Parse Error ===");
            console.error("Parse Error:", parseError);
            console.error("Response Text (first 500 chars):", responseText.substring(0, 500));
            console.error("Response Status:", response.status);
            
            // Check if it's an HTML error page
            if (responseText.includes('<html') || responseText.includes('<!DOCTYPE')) {
              console.error("Server returned HTML instead of JSON - likely a PHP error page");
              showMessage("Server error: PHP error page returned. Check server logs for details.", "error");
            } else {
              showMessage("Server returned invalid JSON. Check console for details.", "error");
            }
            return;
          }
        } catch (textError) {
          console.error("=== Response Text Read Error ===");
          console.error("Error:", textError);
          console.error("Response Status:", response.status);
          showMessage("Failed to read server response. Check console for details.", "error");
          return;
        }

        if (!response.ok) {
          console.error("=== HTTP Error ===");
          console.error("Status:", response.status);
          console.error("Status Text:", response.statusText);
          console.error("Response:", result);
          const errorMsg = result.message || result.error || `Server error (${response.status}). Please try again.`;
          console.error("Error Message:", errorMsg);
          showMessage(errorMsg, "error");
          return;
        }

        if (result.success) {
          console.log("=== Success ===");
          console.log("Message:", result.message);
          clearReservationFormErrors();
          showMessage(result.message, "success");
          setTimeout(() => {
            closeReservationModal();
            loadReservations(); // Refresh the reservation list
          }, 1500);
        } else {
          console.error("=== Request Failed ===");
          console.error("Response:", result);
          const errorMsg = result.message || result.error || "Error processing request. Please try again.";
          console.error("Error Message:", errorMsg);
          
          // Show error in form
          const errors = [errorMsg];
          if (result.errors && Array.isArray(result.errors)) {
            errors.push(...result.errors);
          }
          showReservationFormErrors(errors);
          showMessage(errorMsg, "error");
          
          // Show field-specific errors if provided
          if (result.field_errors && typeof result.field_errors === 'object') {
            Object.keys(result.field_errors).forEach(fieldId => {
              showFieldError(fieldId, result.field_errors[fieldId]);
            });
          }
        }
        
      } catch (error) {
        console.error("=== Network/Request Error ===");
        console.error("Error Type:", error.constructor.name);
        console.error("Error Message:", error.message);
        console.error("Error Stack:", error.stack);
        console.error("Full Error Object:", error);
        showMessage("Network error: " + error.message + ". Check console for details.", "error");
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
      const response = await fetch("../api/get_reservations.php");
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
    
    // Store for filtering
    window.currentReservationsData = reservations;
    
    if (reservations.length === 0) {
      reservationList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">inbox</span><h3>No reservations found</h3><p>Create your first reservation to get started.</p></div>';
      return;
    }

    refreshReservationList();
  }
  
  function refreshReservationList() {
    const reservationList = document.getElementById("reservationList");
    const reservations = window.currentReservationsData || [];
    
    if (reservations.length === 0) {
      reservationList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">inbox</span><h3>No reservations found</h3><p>Create your first reservation to get started.</p></div>';
      return;
    }
    
    // Get filter values
    const searchTerm = (document.getElementById('reservationSearch')?.value || '').toLowerCase().trim();
    const statusFilter = document.getElementById('reservationStatusFilter')?.value || '';
    const dateFrom = document.getElementById('reservationDateFrom')?.value || '';
    const dateTo = document.getElementById('reservationDateTo')?.value || '';
    
    // Filter reservations
    let filtered = reservations.filter(reservation => {
      // Search filter
      if (searchTerm) {
        const name = (reservation.customer_name || '').toLowerCase();
        const phone = (reservation.phone || '').toLowerCase();
        const email = (reservation.email || '').toLowerCase();
        if (!name.includes(searchTerm) && !phone.includes(searchTerm) && !email.includes(searchTerm)) {
          return false;
        }
      }
      
      // Status filter
      if (statusFilter && reservation.status !== statusFilter) {
        return false;
      }
      
      // Date range filter
      if (dateFrom || dateTo) {
        const resDate = new Date(reservation.reservation_date);
        resDate.setHours(0, 0, 0, 0);
        
        if (dateFrom) {
          const fromDate = new Date(dateFrom);
          fromDate.setHours(0, 0, 0, 0);
          if (resDate < fromDate) {
            return false;
          }
        }
        
        if (dateTo) {
          const toDate = new Date(dateTo);
          toDate.setHours(23, 59, 59, 999);
          if (resDate > toDate) {
            return false;
          }
        }
      }
      
      return true;
    });
    
    if (filtered.length === 0) {
      reservationList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">search_off</span><h3>No reservations match your filters</h3><p>Try adjusting your search or filter criteria.</p></div>';
      return;
    }
    
    // Sort by date (most recent first)
    filtered.sort((a, b) => {
      const dateA = new Date(a.reservation_date + ' ' + a.time_slot);
      const dateB = new Date(b.reservation_date + ' ' + b.time_slot);
      return dateB - dateA;
    });

    reservationList.innerHTML = filtered.map(reservation => {
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
        <div class="reservation-card" data-reservation-id="${reservation.id}" style="background: white; border-radius: 16px; padding: 1.5rem; box-shadow: 0 2px 12px rgba(0,0,0,0.08); transition: transform 0.2s, box-shadow 0.2s; border: 1px solid #e5e7eb;">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #f3f4f6;">
            <div style="flex: 1;">
              <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem;">
                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, ${statusColors[reservation.status] || '#6c757d'}, ${statusColors[reservation.status] || '#6c757d'}dd); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1.2rem;">
                  ${escapeHtml(reservation.customer_name).charAt(0).toUpperCase()}
                </div>
                <div style="flex: 1;">
                  <div style="font-size: 1.1rem; font-weight: 700; color: #111827; margin-bottom: 0.25rem;">${escapeHtml(reservation.customer_name)}</div>
                  <div style="display: inline-block; padding: 0.25rem 0.75rem; background: ${statusColors[reservation.status] || '#6c757d'}; color: white; border-radius: 20px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">
                    ${reservation.status}
                  </div>
                </div>
              </div>
              ${reservation.table_number ? `
                <div style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.75rem; background: #e7f5ff; border-radius: 8px; color: #0066cc; font-size: 0.875rem; font-weight: 600; margin-top: 0.5rem;">
                  <span class="material-symbols-rounded" style="font-size: 1.1rem;">table_restaurant</span>
                  Table ${reservation.table_number}${reservation.area_name ? ' - ' + escapeHtml(reservation.area_name) : ''}
                </div>
              ` : `
                <button onclick="assignTable(${reservation.id})" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 8px; color: #374151; font-size: 0.875rem; font-weight: 600; cursor: pointer; margin-top: 0.5rem; transition: all 0.2s;" onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">
                  <span class="material-symbols-rounded" style="font-size: 1.1rem;">table_chart</span>
                  Assign Table
                </button>
              `}
            </div>
            <div style="text-align: right;">
              <div style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.75rem; background: #fef3c7; border-radius: 8px; color: #92400e; font-size: 0.875rem; font-weight: 600;">
                <span class="material-symbols-rounded" style="font-size: 1.1rem;">people</span>
                ${reservation.no_of_guests} Guest${reservation.no_of_guests !== 1 ? 's' : ''}
              </div>
            </div>
          </div>
          
          <div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; background: #f9fafb; border-radius: 10px; margin-bottom: 1rem;">
            <span class="material-symbols-rounded" style="color: var(--primary-red); font-size: 1.3rem;">schedule</span>
            <div>
              <div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.25rem;">Date & Time</div>
              <div style="font-size: 1rem; font-weight: 600; color: #111827;">${formattedDate}</div>
            </div>
          </div>
          
          <div style="margin-bottom: 1rem;">
            <div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0; border-bottom: 1px solid #f3f4f6;">
              <span class="material-symbols-rounded" style="color: #6b7280; font-size: 1.1rem;">phone</span>
              <div style="flex: 1; font-size: 0.95rem; color: #374151;">${escapeHtml(reservation.phone)}</div>
            </div>
            ${reservation.email ? `
              <div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0; border-bottom: 1px solid #f3f4f6;">
                <span class="material-symbols-rounded" style="color: #6b7280; font-size: 1.1rem;">email</span>
                <div style="flex: 1; font-size: 0.95rem; color: #374151;">${escapeHtml(reservation.email)}</div>
              </div>
            ` : ''}
            ${reservation.meal_type ? `
              <div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0;">
                <span class="material-symbols-rounded" style="color: #6b7280; font-size: 1.1rem;">restaurant</span>
                <div style="flex: 1; font-size: 0.95rem; color: #374151; font-weight: 600;">${escapeHtml(reservation.meal_type)}</div>
              </div>
            ` : ''}
          </div>
          
          ${reservation.special_request ? `
            <div style="padding: 0.75rem; background: #fef3c7; border-left: 3px solid #f59e0b; border-radius: 8px; margin-bottom: 1rem;">
              <div style="display: flex; align-items: flex-start; gap: 0.5rem;">
                <span class="material-symbols-rounded" style="color: #92400e; font-size: 1.1rem; margin-top: 0.1rem;">note</span>
                <div style="flex: 1;">
                  <div style="font-size: 0.75rem; color: #92400e; font-weight: 600; margin-bottom: 0.25rem; text-transform: uppercase;">Special Request</div>
                  <div style="font-size: 0.875rem; color: #78350f; line-height: 1.5;">${escapeHtml(reservation.special_request)}</div>
                </div>
              </div>
            </div>
          ` : ''}
          
          <div style="display: flex; gap: 0.5rem;">
            <select class="status-select" onchange="updateReservationStatus(${reservation.id}, this.value)" style="flex: 1; padding: 0.75rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.9rem; font-weight: 600; background: white; color: #374151; cursor: pointer; transition: all 0.2s;" onfocus="this.style.borderColor='var(--primary-red)'" onblur="this.style.borderColor='#e5e7eb'">
              <option value="Pending" ${reservation.status === 'Pending' ? 'selected' : ''}>Pending</option>
              <option value="Confirmed" ${reservation.status === 'Confirmed' ? 'selected' : ''}>Confirmed</option>
              <option value="Checked In" ${reservation.status === 'Checked In' ? 'selected' : ''}>Checked In</option>
              <option value="No Show" ${reservation.status === 'No Show' ? 'selected' : ''}>No Show</option>
              <option value="Completed" ${reservation.status === 'Completed' ? 'selected' : ''}>Completed</option>
              <option value="Cancelled" ${reservation.status === 'Cancelled' ? 'selected' : ''}>Cancelled</option>
            </select>
            <button onclick="editReservation(${reservation.id}, '${escapeHtml(reservation.customer_name).replace(/'/g, "\\'")}', '${reservation.reservation_date}', '${reservation.time_slot}', ${reservation.no_of_guests}, '${reservation.meal_type || ''}', '${reservation.phone}', '${reservation.email || ''}', '${escapeHtml(reservation.special_request || '').replace(/'/g, "\\'")}', '${reservation.table_id || ''}')" style="padding: 0.75rem 1rem; background: #f3f4f6; border: 2px solid #e5e7eb; border-radius: 8px; cursor: pointer; color: #374151; font-weight: 600; transition: all 0.2s;" onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'" title="Edit Reservation">
              <span class="material-symbols-rounded" style="font-size: 1.2rem;">edit</span>
            </button>
          </div>
        </div>
      `;
    }).join('');
  }
  
  // Filter reservations
  function filterReservations() {
    refreshReservationList();
  }
  
  // Clear date range
  window.clearDateRange = function() {
    const dateFrom = document.getElementById('reservationDateFrom');
    const dateTo = document.getElementById('reservationDateTo');
    if (dateFrom) dateFrom.value = '';
    if (dateTo) dateTo.value = '';
    filterReservations();
  }
  
  // Make functions globally accessible
  window.editReservation = function(reservationId, customerName, reservationDate, timeSlot, noOfGuests, mealType, phone, email, specialRequest, tableId) {
    currentReservationId = reservationId;
    // Convert time slot to 12-hour format for display (if in 24-hour format)
    selectedTimeSlot = convertTo12Hour(timeSlot);
    
    // Clear any previous errors
    clearReservationFormErrors();
    
    // Set form values
    if (reservationIdInput) reservationIdInput.value = reservationId || '';
    if (reservationDateInput) reservationDateInput.value = reservationDate || '';
    if (noOfGuestsInput) noOfGuestsInput.value = noOfGuests || 1;
    if (mealTypeSelect) mealTypeSelect.value = mealType || 'Lunch';
    if (customerNameInput) customerNameInput.value = customerName || '';
    if (phoneInput) phoneInput.value = phone || '';
    if (emailInput) emailInput.value = email || '';
    if (specialRequestInput) specialRequestInput.value = specialRequest || '';
    
    // Open modal first
    openReservationModal(true);
    
    // Load tables and set the selected table after loading
    loadTablesForDropdown().then(() => {
      // Wait a bit for the select to be populated
      setTimeout(() => {
        if (tableId && tableId !== 'null' && tableId !== '' && selectTableSelect) {
          // Try to set the value
          selectTableSelect.value = tableId;
          
          // If value didn't set (option doesn't exist), try to find and select it
          if (selectTableSelect.value !== tableId) {
            const option = Array.from(selectTableSelect.options).find(opt => opt.value == tableId);
            if (option) {
              selectTableSelect.value = tableId;
            } else {
              console.warn('Table ID', tableId, 'not found in dropdown');
            }
          }
        }
      }, 100);
    });
  };
  
  // Function to clear all form errors
  function clearReservationFormErrors() {
    const errorContainer = document.getElementById('reservationFormErrors');
    const errorList = document.getElementById('reservationErrorList');
    if (errorContainer) errorContainer.style.display = 'none';
    if (errorList) errorList.innerHTML = '';
    
    // Clear all field errors
    document.querySelectorAll('.field-error').forEach(el => {
      el.style.display = 'none';
      el.textContent = '';
    });
    
    // Remove error styling from inputs
    document.querySelectorAll('#reservationForm input, #reservationForm select, #reservationForm textarea').forEach(el => {
      el.style.borderColor = '';
      el.style.borderWidth = '';
    });
  }
  
  // Function to show form errors
  function showReservationFormErrors(errors) {
    const errorContainer = document.getElementById('reservationFormErrors');
    const errorList = document.getElementById('reservationErrorList');
    
    if (!errorContainer || !errorList) return;
    
    if (errors && errors.length > 0) {
      errorContainer.style.display = 'block';
      errorList.innerHTML = errors.map(err => `<li>${escapeHtml(err)}</li>`).join('');
    } else {
      errorContainer.style.display = 'none';
      errorList.innerHTML = '';
    }
  }
  
  // Function to show field-specific error
  function showFieldError(fieldId, message) {
    const errorEl = document.getElementById(fieldId + 'Error');
    const inputEl = document.getElementById(fieldId);
    
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.style.display = 'block';
    }
    
    if (inputEl) {
      inputEl.style.borderColor = '#c33';
      inputEl.style.borderWidth = '2px';
    }
  }
  
  // Function to clear field-specific error
  function clearFieldError(fieldId) {
    const errorEl = document.getElementById(fieldId + 'Error');
    const inputEl = document.getElementById(fieldId);
    
    if (errorEl) {
      errorEl.style.display = 'none';
      errorEl.textContent = '';
    }
    
    if (inputEl) {
      inputEl.style.borderColor = '';
      inputEl.style.borderWidth = '';
    }
  }
  
  window.deleteReservation = async function(reservationId, customerName) {
    if (await showSweetConfirm(`Are you sure you want to delete reservation for "${customerName}"? This action cannot be undone.`, 'Delete Reservation')) {
      // Call delete API
      const formData = new URLSearchParams();
      formData.append('action', 'delete');
      formData.append('reservationId', reservationId);
      
      fetch("../controllers/reservation_operations.php", {
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
        showMessage("Network error. Please check your connection and try again.", "error");
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
    fetch("../api/get_reservations.php")
      .then(response => response.json())
      .then(result => {
        if (result.success && result.data) {
          const reservation = result.data.find(r => r.id == reservationId);
          if (reservation) {
            formData.append('reservationDate', reservation.reservation_date);
            formData.append('timeSlot', reservation.time_slot);
            formData.append('noOfGuests', reservation.no_of_guests);
            formData.append('mealType', reservation.meal_type || 'Lunch');
            formData.append('customerName', reservation.customer_name);
            formData.append('phone', reservation.phone);
            formData.append('email', reservation.email || '');
            formData.append('specialRequest', reservation.special_request || '');
            if (reservation.table_id) {
              formData.append('selectTable', reservation.table_id);
            }
            
            fetch("../controllers/reservation_operations.php", {
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
              showMessage("Network error. Please check your connection and try again.", "error");
            });
          }
        }
      });
  };
  
  // Assign table to reservation
  window.assignTable = async function(reservationId) {
    // Load tables for dropdown
    fetch("../api/get_tables.php")
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
            let resolved = false;
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
                    resolved = true;
                    Swal.close();
                    resolve({ tableId, tableNumber });
                  });
                });
              },
              didClose: () => {
                if (!resolved) {
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
    fetch("../api/get_reservations.php")
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
            formData.append('mealType', reservation.meal_type || 'Lunch');
            formData.append('customerName', reservation.customer_name);
            formData.append('phone', reservation.phone);
            formData.append('email', reservation.email || '');
            formData.append('specialRequest', reservation.special_request || '');
            formData.append('status', reservation.status || 'Pending');
            
            fetch("../controllers/reservation_operations.php", {
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
              showMessage("Network error. Please check your connection and try again.", "error");
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
  
  // ========== CUSTOMER AUTCOMPLETE FUNCTIONALITY ==========
  
  // Create autocomplete dropdown for customer fields
  function createAutocompleteDropdown(inputElement, container) {
    // Remove existing dropdown if any
    const existing = container.querySelector('.customer-autocomplete-dropdown');
    if (existing) {
      existing.remove();
    }
    
    const dropdown = document.createElement('div');
    dropdown.className = 'customer-autocomplete-dropdown';
    dropdown.style.cssText = 'position: absolute !important; background: white !important; border: 1px solid #d1d5db !important; border-radius: 8px !important; max-height: 250px !important; overflow-y: auto !important; z-index: 99999 !important; box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important; display: none; width: 100% !important; margin-top: 4px !important; visibility: visible !important; opacity: 1 !important;';
    
    // Ensure container has relative positioning
    if (getComputedStyle(container).position === 'static') {
      container.style.position = 'relative';
    }
    
    container.appendChild(dropdown);
    return dropdown;
  }
  
  // Initialize autocomplete for customer name or phone field
  function initCustomerAutocomplete(inputElement, options = {}) {
    if (!inputElement) {
      console.warn('initCustomerAutocomplete: inputElement is null');
      return;
    }
    
    // Check if autocomplete is already initialized
    if (inputElement.dataset.autocompleteInitialized === 'true') {
      console.log('Autocomplete already initialized for:', inputElement.id);
      return;
    }
    
    const {
      nameField = null,      // Field to autofill name
      phoneField = null,     // Field to autofill phone
      emailField = null,     // Field to autofill email
      addressField = null,   // Field to autofill address
      onSelect = null        // Callback when customer is selected
    } = options;
    
    const container = inputElement.parentElement;
    if (!container) {
      console.warn('initCustomerAutocomplete: container is null');
      return;
    }
    
    const dropdown = createAutocompleteDropdown(inputElement, container);
    let searchTimeout = null;
    let selectedIndex = -1;
    let currentSuggestions = [];
    
    // Mark as initialized
    inputElement.dataset.autocompleteInitialized = 'true';
    
    // Determine search type based on input field
    const isPhoneField = inputElement.id === 'customerPhoneInput' || 
                         inputElement.id === 'phone' || 
                         inputElement.id === 'takeawayCustomerPhone';
    const searchType = isPhoneField ? 'phone' : 'name';
    
    console.log('Initializing autocomplete for:', inputElement.id, 'searchType:', searchType);
    
    // Search customers
    async function searchCustomers(query) {
      if (!query || query.length < 1) {
        dropdown.style.display = 'none';
        currentSuggestions = [];
        return;
      }
      
      try {
        // Try different API paths
        const apiPaths = [
          '../api/search_customers.php',
          'api/search_customers.php',
          '/main/api/search_customers.php'
        ];
        
        let response = null;
        let result = null;
        let lastError = null;
        
        for (const apiPath of apiPaths) {
          try {
            const url = `${apiPath}?q=${encodeURIComponent(query)}&type=${searchType}`;
            console.log('Trying API path:', url);
            
            response = await fetch(url, {
              credentials: 'same-origin',
              headers: {
                'Accept': 'application/json'
              }
            });
            
            if (!response.ok) {
              console.warn('API response not OK:', response.status, response.statusText);
              const text = await response.text();
              console.warn('Response text:', text.substring(0, 200));
              continue;
            }
            
            result = await response.json();
            console.log('Search result:', result);
            
            if (result.success !== undefined) {
              break; // Success, exit loop
            }
          } catch (err) {
            console.warn('Error with API path:', apiPath, err);
            lastError = err;
            continue;
          }
        }
        
        if (!result) {
          throw lastError || new Error('All API paths failed');
        }
        
        if (result.success && result.customers && result.customers.length > 0) {
          currentSuggestions = result.customers;
          displaySuggestions(result.customers, query);
        } else {
          console.log('No customers found or empty result');
          dropdown.style.display = 'none';
          currentSuggestions = [];
        }
      } catch (error) {
        console.error('Error searching customers:', error);
        dropdown.style.display = 'none';
        currentSuggestions = [];
        
        // Show error in dropdown
        dropdown.innerHTML = `<div style="padding: 12px; color: #dc2626; font-size: 0.875rem;">Error loading suggestions. Please check console.</div>`;
        dropdown.style.display = 'block';
        setTimeout(() => {
          dropdown.style.display = 'none';
        }, 3000);
      }
    }
    
    // Display suggestions
    function displaySuggestions(customers, query = '') {
      if (!dropdown) {
        console.warn('displaySuggestions: dropdown is null');
        return;
      }
      
      dropdown.innerHTML = '';
      selectedIndex = -1;
      
      if (customers.length === 0) {
        dropdown.style.display = 'none';
        return;
      }
      
      console.log('Displaying', customers.length, 'suggestions');
      
      // Add header
      const header = document.createElement('div');
      header.style.cssText = 'padding: 8px 12px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 0.75rem; color: #6b7280; font-weight: 500; text-transform: uppercase;';
      header.textContent = `Previous Customers (${customers.length})`;
      dropdown.appendChild(header);
      
      customers.forEach((customer, index) => {
        const item = document.createElement('div');
        item.className = 'autocomplete-item';
        item.style.cssText = 'padding: 12px; cursor: pointer; border-bottom: 1px solid #f3f4f6; transition: background 0.2s;';
        
        // Highlight matching text
        const highlightText = (text, query) => {
          if (!query || !text) return escapeHtml(text || '');
          const regex = new RegExp(`(${escapeHtml(query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))})`, 'gi');
          return escapeHtml(text).replace(regex, '<strong style="color: #dc2626; background: #fef2f2;">$1</strong>');
        };
        
        item.innerHTML = `
          <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
            <span class="material-symbols-rounded" style="font-size: 20px; color: #6b7280;">person</span>
            <div style="flex: 1;">
              <div style="font-weight: 500; color: #1f2937; font-size: 0.95rem;">
                ${highlightText(customer.name, query)}
              </div>
            </div>
          </div>
          <div style="font-size: 0.875rem; color: #6b7280; display: flex; align-items: center; gap: 8px; margin-left: 28px;">
            ${customer.phone ? `
              <span class="material-symbols-rounded" style="font-size: 16px; vertical-align: middle;">phone</span>
              <span>${highlightText(customer.phone, query)}</span>
            ` : '<span style="color: #9ca3af;">No phone</span>'}
            ${customer.email ? `
              <span style="margin-left: 8px; color: #9ca3af;">•</span>
              <span class="material-symbols-rounded" style="font-size: 16px; vertical-align: middle;">email</span>
              <span>${escapeHtml(customer.email)}</span>
            ` : ''}
          </div>
        `;
        
        item.addEventListener('mouseenter', () => {
          item.style.background = '#f3f4f6';
          selectedIndex = index;
        });
        
        item.addEventListener('mouseleave', () => {
          item.style.background = '';
        });
        
        item.addEventListener('click', () => {
          selectCustomer(customer);
        });
        
        dropdown.appendChild(item);
      });
      
      dropdown.style.display = 'block';
      dropdown.style.visibility = 'visible';
      dropdown.style.opacity = '1';
      
      // Position dropdown below input
      const rect = inputElement.getBoundingClientRect();
      dropdown.style.top = `${inputElement.offsetHeight + 4}px`;
      dropdown.style.left = '0px';
      dropdown.style.width = `${inputElement.offsetWidth}px`;
      dropdown.style.minWidth = `${inputElement.offsetWidth}px`;
      
      console.log('Dropdown displayed at:', dropdown.style.top, dropdown.style.left, dropdown.style.width);
    }
    
    // Select customer and autofill fields
    function selectCustomer(customer) {
      if (nameField) nameField.value = customer.name || '';
      if (phoneField) phoneField.value = customer.phone || '';
      if (emailField) emailField.value = customer.email || '';
      if (addressField) addressField.value = customer.address || '';
      
      // Set the input value
      if (searchType === 'name') {
        inputElement.value = customer.name || '';
      } else {
        inputElement.value = customer.phone || '';
      }
      
      dropdown.style.display = 'none';
      currentSuggestions = [];
      
      // Callback if provided
      if (onSelect) {
        onSelect(customer);
      }
      
      // Refresh customers tab if it exists
      if (typeof loadCustomers === 'function') {
        setTimeout(() => loadCustomers(), 500);
      }
    }
    
    // Handle input - trigger search immediately for better UX
    const inputHandler = (e) => {
      const query = e.target.value.trim();
      console.log('Input event triggered, query:', query);
      
      clearTimeout(searchTimeout);
      
      // Search immediately if query is 1 character or more
      if (query.length >= 1) {
        searchTimeout = setTimeout(() => {
          console.log('Executing search for:', query);
          searchCustomers(query);
        }, 150); // Reduced delay for faster response
      } else {
        dropdown.style.display = 'none';
        currentSuggestions = [];
      }
    };
    
    inputElement.addEventListener('input', inputHandler);
    inputElement.addEventListener('keyup', inputHandler); // Backup trigger
    
    // Also trigger search on focus if there's already text
    inputElement.addEventListener('focus', (e) => {
      const query = e.target.value.trim();
      if (query.length >= 1) {
        searchCustomers(query);
      }
    });
    
    // Handle keyboard navigation
    inputElement.addEventListener('keydown', (e) => {
      if (dropdown.style.display === 'none' || currentSuggestions.length === 0) return;
      
      const items = dropdown.querySelectorAll('.autocomplete-item');
      
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
        items[selectedIndex]?.scrollIntoView({ block: 'nearest' });
        items.forEach((item, idx) => {
          item.style.background = idx === selectedIndex ? '#f3f4f6' : '';
        });
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedIndex = Math.max(selectedIndex - 1, -1);
        if (selectedIndex >= 0) {
          items[selectedIndex]?.scrollIntoView({ block: 'nearest' });
        }
        items.forEach((item, idx) => {
          item.style.background = idx === selectedIndex ? '#f3f4f6' : '';
        });
      } else if (e.key === 'Enter' && selectedIndex >= 0 && currentSuggestions[selectedIndex]) {
        e.preventDefault();
        selectCustomer(currentSuggestions[selectedIndex]);
      } else if (e.key === 'Escape') {
        dropdown.style.display = 'none';
        selectedIndex = -1;
      }
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
      if (!container.contains(e.target)) {
        dropdown.style.display = 'none';
      }
    });
    
    // Close dropdown on blur (with delay to allow click events)
    inputElement.addEventListener('blur', () => {
      setTimeout(() => {
        dropdown.style.display = 'none';
      }, 200);
    });
  }
  
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
    
    // Re-initialize autocomplete when modal opens (in case elements were recreated)
    setTimeout(() => {
      if (customerNameInputField && customerPhoneInputField) {
        // Remove existing autocomplete dropdowns if any
        const existingDropdowns = customerModal.querySelectorAll('.customer-autocomplete-dropdown');
        existingDropdowns.forEach(d => d.remove());
        
        // Reset initialization flags
        customerNameInputField.dataset.autocompleteInitialized = 'false';
        customerPhoneInputField.dataset.autocompleteInitialized = 'false';
        
        // Initialize autocomplete for customer name field
        initCustomerAutocomplete(customerNameInputField, {
          nameField: customerNameInputField,
          phoneField: customerPhoneInputField,
          emailField: customerEmailInputField,
          onSelect: (customer) => {
            if (typeof loadCustomers === 'function') {
              setTimeout(() => loadCustomers(), 500);
            }
          }
        });
        
        // Initialize autocomplete for customer phone field
        initCustomerAutocomplete(customerPhoneInputField, {
          nameField: customerNameInputField,
          phoneField: customerPhoneInputField,
          emailField: customerEmailInputField,
          onSelect: (customer) => {
            if (typeof loadCustomers === 'function') {
              setTimeout(() => loadCustomers(), 500);
            }
          }
        });
      }
      if (customerNameInputField) {
      customerNameInputField.focus();
      }
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
    if (await showSweetConfirm(`Are you sure you want to delete customer "${customerName}"? This action cannot be undone.`, 'Delete Customer')) {
      const formData = new URLSearchParams();
      formData.append('action', 'delete');
      formData.append('customerId', customerId);
      
      fetch("../controllers/customer_operations.php", {
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
        showMessage("Network error. Please check your connection and try again.", "error");
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
        const response = await fetch("../controllers/customer_operations.php", {
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
        showMessage("Network error. Please check your connection and try again.", "error");
      }
    });
  }
  
  // Note: Autocomplete is now initialized when the modal opens (in openCustomerModal function)
  // This ensures the elements exist and are ready for autocomplete
  
  // Load customers
  async function loadCustomers() {
    const customerList = document.getElementById("customerList");
    showLoading('customerList', 'Loading customers...');
    
    try {
      const response = await fetch("../api/get_customers.php");
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
        <tr data-customer-id="${customer.id || 'order-' + Math.random()}">
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
    showLoading('waiterRequestsList', 'Loading requests...');
    
    try {
      const response = await fetch("../api/get_waiter_requests.php");
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
    
    fetch("../controllers/waiter_request_operations.php", {
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
  
  // Show full order details in modal (from Orders page)
  window.showFullOrderDetails = async function(orderId) {
    try {
      const response = await fetch(`../api/get_order_details_by_id.php?id=${orderId}`);
      const data = await response.json();
      
      if (data.success && data.order) {
        const order = data.order;
        const items = Array.isArray(order.items) ? order.items : [];
        
        // Remove existing modal if any
        const existingModal = document.getElementById('orderDetailsModal');
        if (existingModal) {
          existingModal.remove();
        }
        
        // Create modal HTML
        const modalHTML = `
          <div class="modal-overlay" id="orderDetailsModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 10000;">
            <div class="modal-content" style="background: white; border-radius: 12px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
              <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 1.5rem; border-bottom: 1px solid #e5e7eb; background: #1f2937;">
                <h2 style="margin: 0; font-size: 1.5rem; color: #ffffff; font-weight: 600;">Order Details</h2>
                <button onclick="this.closest('.modal-overlay').remove()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #ffffff; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 4px; transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.2)'; this.style.color='#ffffff'" onmouseout="this.style.background='none'; this.style.color='#ffffff'">&times;</button>
              </div>
              <div class="modal-body" style="padding: 1.5rem; background: white;">
                <div style="margin-bottom: 1.5rem;">
                  <p style="margin: 0.5rem 0; color: #1f2937; font-size: 0.95rem;"><strong style="color: #374151;">Order #:</strong> <span style="color: #111827; font-weight: 600;">${order.order_number}</span></p>
                  <p style="margin: 0.5rem 0; color: #1f2937; font-size: 0.95rem;"><strong style="color: #374151;">Table:</strong> <span style="color: #111827;">${order.table_name || order.table_number || 'Walk-in'}</span></p>
                  <p style="margin: 0.5rem 0; color: #1f2937; font-size: 0.95rem;"><strong style="color: #374151;">Customer:</strong> <span style="color: #111827;">${order.customer_name || 'N/A'}</span></p>
                  <p style="margin: 0.5rem 0; color: #1f2937; font-size: 0.95rem;"><strong style="color: #374151;">Type:</strong> <span style="color: #111827;">${order.order_type || 'N/A'}</span></p>
                  <p style="margin: 0.5rem 0; color: #1f2937; font-size: 0.95rem;"><strong style="color: #374151;">Status:</strong> <span class="status-badge ${order.order_status.toLowerCase()}">${order.order_status}</span></p>
                  <p style="margin: 0.5rem 0; color: #1f2937; font-size: 0.95rem;"><strong style="color: #374151;">Payment:</strong> <span class="status-badge ${order.payment_status.toLowerCase().replace(' ', '-')}">${order.payment_status}</span></p>
                  <p style="margin: 0.5rem 0; color: #1f2937; font-size: 0.95rem;"><strong style="color: #374151;">Time:</strong> <span style="color: #111827;">${new Date(order.created_at).toLocaleString()}</span></p>
                </div>
                
                <div style="margin-bottom: 1.5rem;">
                  <h3 style="margin: 0 0 1rem 0; color: #1f2937; font-size: 1.1rem; font-weight: 600;">Items (${items.length})</h3>
                  <table style="width: 100%; border-collapse: collapse;">
                    <thead style="background: #f3f4f6;">
                      <tr>
                        <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: #1f2937; font-size: 0.9rem;">Item</th>
                        <th style="padding: 0.75rem; text-align: center; font-weight: 600; color: #1f2937; font-size: 0.9rem;">Qty</th>
                        <th style="padding: 0.75rem; text-align: right; font-weight: 600; color: #1f2937; font-size: 0.9rem;">Price</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${items.length ? items.map(item => `
                        <tr style="border-bottom: 1px solid #e5e7eb;">
                          <td style="padding: 0.75rem;">
                            <div style="font-weight: 500; color: #111827; font-size: 0.95rem;">${item.item_name}</div>
                            ${item.notes ? `<div style="font-size: 0.875rem; color: #d97706; margin-top: 4px; font-weight: 500;">Note: ${item.notes}</div>` : ''}
                          </td>
                          <td style="padding: 0.75rem; text-align: center; color: #374151; font-weight: 500; font-size: 0.95rem;">${item.quantity || 1}</td>
                          <td style="padding: 0.75rem; text-align: right; font-weight: 600; color: #111827; font-size: 0.95rem;">${formatCurrency(item.total_price || 0)}</td>
                        </tr>
                      `).join('') : '<tr><td colspan="3" style="padding: 1rem; text-align: center; color: #6b7280;">No items found</td></tr>'}
                    </tbody>
                  </table>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1rem; padding-top: 1rem; border-top: 2px solid #d1d5db;">
                  <div>
                    <div style="font-size: 0.875rem; color: #4b5563; margin-bottom: 0.25rem; font-weight: 500;">Subtotal</div>
                    <div style="font-weight: 600; color: #111827; font-size: 1rem;">${formatCurrency(order.subtotal || 0)}</div>
                  </div>
                  <div>
                    <div style="font-size: 0.875rem; color: #4b5563; margin-bottom: 0.25rem; font-weight: 500;">Tax</div>
                    <div style="font-weight: 600; color: #111827; font-size: 1rem;">${formatCurrency(order.tax || 0)}</div>
                  </div>
                  <div>
                    <div style="font-size: 0.875rem; color: #4b5563; margin-bottom: 0.25rem; font-weight: 500;">Total</div>
                    <div style="font-weight: 700; font-size: 1.2rem; color: #1f2937;">${formatCurrency(order.total || 0)}</div>
                  </div>
                </div>
                
                ${order.payment_method === 'QR Payment' ? `
                  <div id="paymentProofSection-${order.id}" style="margin-top: 1rem; padding: 1rem; background: ${order.payment_proof_status === 'Confirmed' ? '#f0fdf4' : order.payment_proof_status === 'Rejected' ? '#fef2f2' : '#fffbeb'}; border-radius: 8px; border: 1px solid ${order.payment_proof_status === 'Confirmed' ? '#86efac' : order.payment_proof_status === 'Rejected' ? '#fca5a5' : '#fde68a'};">
                    <strong style="color: #78350f;">Payment Proof (Pay Online / QR)</strong>
                    ${order.payment_proof_status ? `
                      <div style="margin-top:0.6rem;">
                        <img src="../api/image.php?type=payment_proof&id=${order.id}" alt="Payment screenshot" style="max-width:220px;max-height:220px;border-radius:8px;border:1px solid #ddd;cursor:zoom-in;" onclick="window.open(this.src, '_blank')">
                      </div>
                      <div style="margin-top:0.5rem;">
                        <span style="display:inline-block;padding:3px 10px;border-radius:12px;font-weight:600;font-size:0.8rem;background:${order.payment_proof_status === 'Confirmed' ? '#dcfce7' : order.payment_proof_status === 'Rejected' ? '#fee2e2' : '#fef3c7'};color:${order.payment_proof_status === 'Confirmed' ? '#166534' : order.payment_proof_status === 'Rejected' ? '#991b1b' : '#92400e'};">${order.payment_proof_status}</span>
                      </div>
                      ${order.payment_proof_status === 'Pending' ? `
                        <div style="margin-top:0.75rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
                          <button class="btn btn-success" onclick="reviewPaymentProof(${order.id}, 'confirm', this)" style="background:#16a34a;color:white;border:none;padding:0.5rem 1rem;border-radius:6px;cursor:pointer;">✔ Confirm Payment</button>
                          <button class="btn btn-danger" onclick="reviewPaymentProof(${order.id}, 'reject', this)" style="background:#dc2626;color:white;border:none;padding:0.5rem 1rem;border-radius:6px;cursor:pointer;">✘ Reject</button>
                        </div>
                      ` : ''}
                    ` : `<p style="margin:0.5rem 0 0 0;color:#92400e;">Customer hasn't uploaded a payment screenshot yet.</p>`}
                  </div>
                ` : ''}
                ${order.order_type === 'Delivery' ? `
                  <div id="qrSection-${order.id}" style="margin-top: 1rem; padding: 1rem; background: #f0fdf4; border-radius: 8px; border: 1px solid #86efac;">
                    <strong style="color: #166534;">Delivery QR</strong>
                    <div style="margin-top: 0.5rem;">
                      <button class="btn btn-success" onclick="generateDeliveryQR(${order.id})" style="background: #16a34a; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                        <span class="material-symbols-rounded" style="font-size: 1.1rem;">qr_code</span>
                        Generate QR for Rider
                      </button>
                      <div id="qrDisplay-${order.id}" style="display:none; margin-top: 0.75rem; text-align: center;"></div>
                    </div>
                  </div>
                ` : ''}
                ${order.notes ? `
                  <div style="margin-top: 1rem; padding: 1rem; background: #fff7ed; border-radius: 8px; border: 1px solid #fdba74;">
                    <strong style="color: #92400e;">Notes:</strong>
                    <p style="margin: 0.5rem 0 0 0; color: #78350f;">${order.notes}</p>
                  </div>
                ` : ''}
              </div>
            </div>
          </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        
        // Close modal when clicking outside
        const modal = document.getElementById('orderDetailsModal');
        modal.addEventListener('click', function(e) {
          if (e.target === modal) {
            modal.remove();
          }
        });
      } else {
        await showSweetAlert('Order not found', 'The order you are looking for could not be found.', 'error');
      }
    } catch (error) {
      console.error('Error loading order details:', error);
      await showSweetAlert('Error', 'Failed to load order details. Please try again.', 'error');
    }
  };

  // Confirm or reject a customer-submitted payment screenshot. Only a
  // confirmed payment ever flips the order to Paid / counts toward revenue.
  window.reviewPaymentProof = async function(orderId, action, btn) {
    const verb = action === 'confirm' ? 'confirm this payment' : 'reject this payment proof';
    const ok = await showSweetConfirm(`Are you sure you want to ${verb}? This cannot be undone.`, 'Please Confirm');
    if (!ok) return;

    if (btn) btn.disabled = true;
    try {
      const response = await fetch('../api/confirm_payment_proof.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order_id: orderId, action: action })
      });
      const data = await response.json();
      if (data.success) {
        showNotification(data.message || 'Updated', 'success');
        showFullOrderDetails(orderId);
        if (typeof loadOnlineOrders === 'function') loadOnlineOrders();
      } else {
        showNotification(data.message || 'Failed to update payment proof', 'error');
        if (btn) btn.disabled = false;
      }
    } catch (error) {
      console.error('Error reviewing payment proof:', error);
      showNotification('Network error, please try again.', 'error');
      if (btn) btn.disabled = false;
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
      const response = await fetch("../controllers/staff_operations.php", {
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
      const response = await fetch("../api/get_staff.php");
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
    if (await showSweetConfirm(`Are you sure you want to delete staff member "${memberName}"? This action cannot be undone.`, 'Delete Staff')) {
      const formData = new URLSearchParams();
      formData.append('action', 'delete');
      formData.append('staffId', staffId);
      
      fetch("../controllers/staff_operations.php", {
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
        showMessage("Network error. Please check your connection and try again.", "error");
      });
    }
  };
  
  // POS functionality
  let posCart = [];
  
  // Load POS menu items
  // Make POS functions globally accessible
  window.loadPOSMenuItems = async function() {
    const posMenuItemsContainer = document.getElementById("posMenuItems");
    
    // Get filter values
    const menuFilter = document.getElementById("posMenuFilter")?.value || '';
    const categoryFilter = document.getElementById("posCategoryFilter")?.value || '';
    const typeFilter = document.getElementById("posTypeFilter")?.value || '';
    
    // Build URL with filters
    let url = "../api/get_menu_items.php";
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
  
  // Store all POS items for mobile modal
  let allPOSItems = [];
  
  // Display POS menu items
  function displayPOSMenuItems(items) {
    const posMenuItemsContainer = document.getElementById("posMenuItems");
    
    // Store items for mobile modal
    allPOSItems = items;
    
    if (items.length === 0) {
      posMenuItemsContainer.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">menu</span><h3>No menu items found</h3><p>Add menu items to start selling.</p></div>';
      return;
    }
    
    posMenuItemsContainer.innerHTML = items.map(item => {
      const hasVariations = item.has_variations && item.variations && item.variations.length > 0;
      const priceDisplay = hasVariations ? 
        (item.variations.length > 0 ? `${formatCurrency(item.variations[0].price)} - ${formatCurrency(item.variations[item.variations.length - 1].price)}` : formatCurrency(item.base_price)) :
        formatCurrency(item.base_price);
      
      // Escape variations JSON for data attribute
      const variationsJson = hasVariations ? encodeURIComponent(JSON.stringify(item.variations || [])) : '';
      
      return `
      <div class="pos-menu-item" 
           data-item-id="${item.id}"
           data-item-name="${escapeHtml(item.item_name_translated || item.item_name_en)}"
           data-item-price="${item.base_price}"
           data-item-image="${escapeHtml(item.item_image || '')}"
           data-has-variations="${hasVariations ? '1' : '0'}"
           data-variations="${variationsJson}"
           style="cursor:pointer;">
        <div class="item-image">
          ${item.item_image ? `<img src="../api/image.php?path=${encodeURIComponent(item.item_image)}" alt="${escapeHtml(item.item_name_translated || item.item_name_en)}" loading="lazy">` : '<span class="material-symbols-rounded">restaurant</span>'}
        </div>
        <div class="item-name">${escapeHtml(item.item_name_translated || item.item_name_en)}</div>
        <div class="item-category">${escapeHtml(item.item_category || '')}</div>
        <div class="item-price">${priceDisplay}${hasVariations ? ' <span style="font-size:0.75rem;color:#6b7280;">(Variations)</span>' : ''}</div>
      </div>
    `;
    }).join('');
    
    // Add click event listeners to POS menu items
    posMenuItemsContainer.querySelectorAll('.pos-menu-item').forEach(itemEl => {
      itemEl.addEventListener('click', function() {
        const itemId = parseInt(this.dataset.itemId);
        const itemName = this.dataset.itemName;
        const basePrice = parseFloat(this.dataset.itemPrice);
        const image = this.dataset.itemImage;
        const hasVariations = this.dataset.hasVariations === '1';
        let variations = [];
        
        if (hasVariations && this.dataset.variations) {
          try {
            variations = JSON.parse(decodeURIComponent(this.dataset.variations));
          } catch (e) {
            console.error('Error parsing variations:', e);
            variations = [];
          }
        }
        
        handlePOSItemClick(itemId, itemName, basePrice, image, hasVariations, variations);
      });
    });
    
    // Update mobile modal items list
    updateMobileItemsList(items);
  }
  
  // Check if mobile view and show/hide add item button, bill summary, and bottom actions
  function checkMobileView() {
    const mobileBtn = document.getElementById('mobileAddItemBtn');
    const mobileBillSummary = document.getElementById('mobilePosBillSummary');
    const mobileBottomActions = document.getElementById('mobilePosBottomActions');
    
    if (window.innerWidth <= 768) {
      if (mobileBtn) mobileBtn.style.display = 'flex';
      if (mobileBillSummary) mobileBillSummary.style.display = 'block';
      if (mobileBottomActions) mobileBottomActions.style.display = 'flex';
    } else {
      if (mobileBtn) mobileBtn.style.display = 'none';
      if (mobileBillSummary) mobileBillSummary.style.display = 'none';
      if (mobileBottomActions) mobileBottomActions.style.display = 'none';
    }
  }
  
  // Make checkMobileView globally available
  window.checkMobileView = checkMobileView;
  
  // Call on window resize
  window.addEventListener('resize', checkMobileView);
  
  // Update mobile items list
  function updateMobileItemsList(items) {
    const mobileItemsList = document.getElementById('mobileItemsList');
    if (!mobileItemsList) return;
    
    if (items.length === 0) {
      mobileItemsList.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:2rem;color:#6b7280;">No items found</div>';
      return;
    }
    
    mobileItemsList.innerHTML = items.map(item => {
      const hasVariations = item.has_variations && item.variations && item.variations.length > 0;
      const priceDisplay = hasVariations && item.variations && item.variations.length > 0 ? 
        `${formatCurrency(item.variations[0].price)} - ${formatCurrency(item.variations[item.variations.length - 1].price)}` : 
        formatCurrency(item.base_price);
      
      // Escape variations JSON for data attribute
      const variationsJson = hasVariations ? encodeURIComponent(JSON.stringify(item.variations || [])) : '';
      
      return `
      <div class="mobile-item-card" 
           data-item-id="${item.id}"
           data-item-name="${escapeHtml(item.item_name_translated || item.item_name_en)}"
           data-item-price="${item.base_price}"
           data-item-image="${escapeHtml(item.item_image || '')}"
           data-has-variations="${hasVariations ? '1' : '0'}"
           data-variations="${variationsJson}"
           style="background:white;border:2px solid #e5e7eb;border-radius:6px;padding:0.5rem;cursor:pointer;transition:all 0.2s;">
        <div style="width:100%;height:60px;border-radius:4px;overflow:hidden;margin-bottom:0.4rem;background:#f5f5f5;display:flex;align-items:center;justify-content:center;">
          ${item.item_image ? `<img src="../api/image.php?path=${encodeURIComponent(item.item_image)}" alt="${escapeHtml(item.item_name_translated || item.item_name_en)}" loading="lazy" style="width:100%;height:100%;object-fit:cover;">` : '<span class="material-symbols-rounded" style="font-size:1.5rem;color:#9ca3af;">restaurant</span>'}
        </div>
        <div style="font-weight:600;color:#111827;font-size:0.75rem;margin-bottom:0.2rem;line-height:1.2;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">${escapeHtml(item.item_name_translated || item.item_name_en)}</div>
        <div style="font-size:0.65rem;color:#6b7280;margin-bottom:0.3rem;line-height:1.2;">${escapeHtml(item.item_category || '')}</div>
        <div style="font-weight:700;color:#f70000;font-size:0.8rem;line-height:1.2;">
          ${priceDisplay}${hasVariations ? ' <span style="font-size:0.6rem;color:#6b7280;">(Var)</span>' : ''}
      </div>
      </div>
    `;
    }).join('');
    
    // Add click event listeners to mobile item cards
    mobileItemsList.querySelectorAll('.mobile-item-card').forEach(itemEl => {
      itemEl.addEventListener('click', function(event) {
        const itemId = parseInt(this.dataset.itemId);
        const itemName = this.dataset.itemName;
        const basePrice = parseFloat(this.dataset.itemPrice);
        const image = this.dataset.itemImage;
        const hasVariations = this.dataset.hasVariations === '1';
        let variations = [];
        
        if (hasVariations && this.dataset.variations) {
          try {
            variations = JSON.parse(decodeURIComponent(this.dataset.variations));
          } catch (e) {
            console.error('Error parsing variations:', e);
            variations = [];
          }
        }
        
        handleMobileItemClick(itemId, itemName, basePrice, image, hasVariations, variations, event);
      });
    });
  }
  
  // Filter mobile items
  window.filterMobileItems = function() {
    const searchInput = document.getElementById('mobileItemSearch');
    const searchTerm = (searchInput?.value || '').toLowerCase().trim();
    
    if (!searchTerm) {
      updateMobileItemsList(allPOSItems);
      return;
    }
    
    const filtered = allPOSItems.filter(item => {
      const name = (item.item_name_translated || item.item_name_en || '').toLowerCase();
      const category = (item.item_category || '').toLowerCase();
      const menu = (item.menu_name_translated || item.menu_name || '').toLowerCase();
      const type = (item.item_type || '').toLowerCase();
      return name.includes(searchTerm) || 
             category.includes(searchTerm) || 
             menu.includes(searchTerm) ||
             type.includes(searchTerm);
    });
    
    updateMobileItemsList(filtered);
  };
  
  // Setup search input with Enter key support
  function setupMobileItemSearch() {
    const searchInput = document.getElementById('mobileItemSearch');
    if (searchInput) {
      // Remove existing listeners to avoid duplicates
      const newSearchInput = searchInput.cloneNode(true);
      searchInput.parentNode.replaceChild(newSearchInput, searchInput);
      
      // Add event listeners
      newSearchInput.addEventListener('input', filterMobileItems);
      newSearchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          filterMobileItems();
        }
      });
    }
  }
  
  // Open mobile add item modal
  window.openMobileAddItemModal = function() {
    const modal = document.getElementById('mobileAddItemModal');
    if (modal) {
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
      // Setup search functionality
      setupMobileItemSearch();
      // Focus search input
      setTimeout(() => {
        const searchInput = document.getElementById('mobileItemSearch');
        if (searchInput) {
          searchInput.focus();
          // Clear any previous search
          searchInput.value = '';
          updateMobileItemsList(allPOSItems);
        }
      }, 100);
    }
  };
  
  // Close mobile add item modal
  window.closeMobileAddItemModal = function() {
    const modal = document.getElementById('mobileAddItemModal');
    if (modal) {
      modal.style.display = 'none';
      document.body.style.overflow = 'auto';
      // Clear search
      const searchInput = document.getElementById('mobileItemSearch');
      if (searchInput) searchInput.value = '';
      updateMobileItemsList(allPOSItems);
    }
  };
  
  // Add item from mobile modal
  window.handleMobileItemClick = function(itemId, itemName, basePrice, image, hasVariations, variations, event) {
    if (event) {
      event.stopPropagation();
    }
    
    if (hasVariations && variations && variations.length > 0) {
      // Show variation selection modal
      showPOSVariationModal(itemId, itemName, basePrice, image, variations);
    } else {
      // Add directly to cart
      addToPOSCart(itemId, itemName, basePrice, image, null);
    
      // Show visual feedback
    if (event) {
      const itemCard = event.target?.closest('.mobile-item-card');
      if (itemCard) {
        itemCard.style.transform = 'scale(0.95)';
        itemCard.style.backgroundColor = '#d1fae5';
        setTimeout(() => {
          itemCard.style.transform = '';
          itemCard.style.backgroundColor = '';
        }, 300);
      }
    }
    
      // Show brief success message
    const successMsg = document.createElement('div');
    successMsg.textContent = '✓ Added to cart';
    successMsg.style.cssText = 'position:fixed;top:20px;right:20px;background:#10b981;color:white;padding:0.75rem 1.25rem;border-radius:8px;font-weight:600;z-index:10001;box-shadow:0 4px 12px rgba(16,185,129,0.4);animation:slideInRight 0.3s ease;';
    document.body.appendChild(successMsg);
    setTimeout(() => {
      successMsg.style.animation = 'slideOutRight 0.3s ease';
      setTimeout(() => successMsg.remove(), 300);
    }, 2000);
    }
  };
    
  // Legacy function for backward compatibility
  window.addItemFromMobile = function(itemId, itemName, price, image, event) {
    handleMobileItemClick(itemId, itemName, price, image, false, [], event);
  };
  
  // Load tables for POS
  window.loadTablesForPOS = async function() {
    const selectPosTable = document.getElementById("selectPosTable");
    
    // Get restaurant_id from window or session
    let restaurantId = window.restaurant_id || '';
    if (!restaurantId) {
      try {
        const sessRes = await fetch('../admin/get_session.php');
        const sess = await sessRes.json().catch(()=>null);
        if (checkSessionExpired(sessRes, sess)) return;
        if (sess && sess.success && sess.data?.restaurant_id) {
          restaurantId = sess.data.restaurant_id;
          window.restaurant_id = restaurantId;
        }
      } catch (e) {
        console.warn('Could not get restaurant_id from session');
      }
    }
    
    let url = "../api/get_tables.php";
    if (restaurantId) {
      url += '?restaurant_id=' + encodeURIComponent(restaurantId);
    }
    
    try {
      const response = await fetch(url);
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
  window.loadMenusForPOSFilters = async function() {
    const posMenuFilter = document.getElementById("posMenuFilter");
    
    // Get restaurant_id from window or session
    let restaurantId = window.restaurant_id || '';
    if (!restaurantId) {
      try {
        const sessRes = await fetch('../admin/get_session.php');
        const sess = await sessRes.json().catch(()=>null);
        if (checkSessionExpired(sessRes, sess)) return;
        if (sess && sess.success && sess.data?.restaurant_id) {
          restaurantId = sess.data.restaurant_id;
          window.restaurant_id = restaurantId;
        }
      } catch (e) {
        console.warn('Could not get restaurant_id from session');
      }
    }
    
    let url = "../api/get_menus.php";
    if (restaurantId) {
      url += '?restaurant_id=' + encodeURIComponent(restaurantId);
    }
    
    try {
      const response = await fetch(url);
      const result = await response.json();
      
      if (result.success && posMenuFilter) {
        posMenuFilter.innerHTML = '<option value="">All Menus</option>' + 
          result.data.map(menu => 
            `<option value="${menu.id}">${escapeHtml(menu.menu_name)}</option>`
          ).join('');
      }
    } catch (error) {
      console.error("Error loading menus for POS filters:", error);
    }
  }
  
  // Load categories for POS filters
  window.loadCategoriesForPOSFilters = async function() {
    const posCategoryFilter = document.getElementById("posCategoryFilter");
    
    // Get restaurant_id from window or session
    let restaurantId = window.restaurant_id || '';
    if (!restaurantId) {
      try {
        const sessRes = await fetch('../admin/get_session.php');
        const sess = await sessRes.json().catch(()=>null);
        if (checkSessionExpired(sessRes, sess)) return;
        if (sess && sess.success && sess.data?.restaurant_id) {
          restaurantId = sess.data.restaurant_id;
          window.restaurant_id = restaurantId;
        }
      } catch (e) {
        console.warn('Could not get restaurant_id from session');
      }
    }
    
    let url = "../api/get_menu_items.php";
    if (restaurantId) {
      url += '?restaurant_id=' + encodeURIComponent(restaurantId);
    }
    
    try {
      const response = await fetch(url);
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
  
  // Add item to POS cart
  // Handle POS item click - check for variations
  window.handlePOSItemClick = function(itemId, itemName, basePrice, image, hasVariations, variations) {
    playClickSound();
    if (hasVariations && variations && variations.length > 0) {
      // Show variation selection modal
      showPOSVariationModal(itemId, itemName, basePrice, image, variations);
    } else {
      // Add directly to cart
      addToPOSCart(itemId, itemName, basePrice, image, null);
    }
  };

  // Show variation selection modal
  function showPOSVariationModal(itemId, itemName, basePrice, image, variations) {
    const modal = document.getElementById('posVariationModal');
    const itemNameEl = document.getElementById('posVariationItemName');
    const optionsEl = document.getElementById('posVariationOptions');
    
    if (!modal || !itemNameEl || !optionsEl) return;
    
    itemNameEl.textContent = `Select a variation for: ${itemName}`;
    
    optionsEl.innerHTML = variations.map((variation, index) => `
      <button class="variation-option-btn" 
              data-variation-index="${index}"
              style="padding:1rem;border:2px solid #e5e7eb;border-radius:12px;background:white;cursor:pointer;transition:all 0.2s;text-align:left;display:flex;justify-content:space-between;align-items:center;width:100%;">
        <div>
          <div style="font-weight:600;color:#1f2937;font-size:1rem;">${escapeHtml(variation.variation_name)}</div>
        </div>
        <div style="font-weight:700;color:#f70000;font-size:1.1rem;">${formatCurrency(variation.price)}</div>
      </button>
    `).join('');
    
    // Add click event listeners to variation buttons
    optionsEl.querySelectorAll('.variation-option-btn').forEach((btn, index) => {
      btn.addEventListener('click', function() {
        const variation = variations[index];
        if (variation) {
          selectPOSVariation(itemId, itemName, variation.price, image, variation.variation_name);
        }
      });
    });
    
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    // Store current item data for selection
    window.currentPOSItemData = { itemId, itemName, basePrice, image };
  }

  window.closePOSVariationModal = function() {
    const modal = document.getElementById('posVariationModal');
    if (modal) {
      modal.style.display = 'none';
      document.body.style.overflow = 'auto';
    }
    window.currentPOSItemData = null;
  };

  window.selectPOSVariation = function(itemId, itemName, price, image, variationName) {
    // Add to cart with variation
    addToPOSCart(itemId, itemName, price, image, variationName);
    closePOSVariationModal();
  };

  window.addToPOSCart = function(itemId, itemName, price, image, variationName) {
    // Create unique key for cart item (itemId + variationName)
    const cartKey = variationName ? `${itemId}_${variationName}` : itemId.toString();
    const displayName = variationName ? `${itemName} (${variationName})` : itemName;
    
    const existingItem = posCart.find(item => {
      const itemKey = item.variationName ? `${item.id}_${item.variationName}` : item.id.toString();
      return itemKey === cartKey;
    });
    
    if (existingItem) {
      existingItem.quantity += 1;
    } else {
      posCart.push({
        id: itemId,
        cartKey: cartKey,
        name: displayName,
        originalName: itemName,
        price: price,
        image: image,
        variationName: variationName || null,
        quantity: 1
      });
    }
    
    updatePOSCart();
  };
  
  // Update POS cart display
  function updatePOSCart() {
    const cartItemsContainer = document.getElementById("posCartItems");

    if (posCart.length === 0) {
      window._kotSentForCurrentOrder = false;
      cartItemsContainer.innerHTML = `
        <div class="empty-cart">
          <span class="material-symbols-rounded">shopping_cart</span>
          <p>Cart is empty</p>
          <p class="empty-subtext">Add items from the menu</p>
        </div>
      `;
    } else {
      cartItemsContainer.innerHTML = posCart.map(item => {
        const key = item.cartKey || item.id.toString();
        return `
        <div class="cart-item">
          <div class="cart-item-info">
            <div class="cart-item-name">${escapeHtml(item.name)}</div>
            <div class="cart-item-price">${formatCurrency(item.price)} each</div>
          </div>
          <div class="cart-item-controls">
            <button class="cart-qty-btn" onclick="updatePOSCartQty('${key.replace(/'/g, "\\'")}', -1)">-</button>
            <span class="cart-qty-value">${item.quantity}</span>
            <button class="cart-qty-btn" onclick="updatePOSCartQty('${key.replace(/'/g, "\\'")}', 1)">+</button>
          </div>
          <div class="cart-item-total">${formatCurrency(parseFloat(item.price) * item.quantity)}</div>
        </div>`;
      }).join('');
    }
    
    updatePOSCartSummary();
  }
  
  // Update cart quantity
  window.updatePOSCartQty = function(cartKey, change) {
    const item = posCart.find(item => (item.cartKey || item.id.toString()) === cartKey);
    
    if (item) {
      item.quantity += change;
      
      if (item.quantity <= 0) {
        posCart = posCart.filter(cartItem => (cartItem.cartKey || cartItem.id.toString()) !== cartKey);
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
    
    // Update desktop cart summary
    const cartSubtotalEl = document.getElementById("cartSubtotal");
    const cartCGSTEl = document.getElementById("cartCGST");
    const cartSGSTEl = document.getElementById("cartSGST");
    const cartTaxEl = document.getElementById("cartTax");
    const cartTotalEl = document.getElementById("cartTotal");
    
    if (cartSubtotalEl) cartSubtotalEl.textContent = formatCurrency(subtotal);
    // Show/hide GST rows based on enableGst setting
    if (cartCGSTEl && cartCGSTEl.parentElement) {
      cartCGSTEl.parentElement.style.display = showGst ? "" : "none";
    }
    if (cartSGSTEl && cartSGSTEl.parentElement) {
      cartSGSTEl.parentElement.style.display = showGst ? "" : "none";
    }
    if (cartTaxEl && cartTaxEl.parentElement) {
      cartTaxEl.parentElement.style.display = showGst ? "" : "none";
    }
    if (cartCGSTEl) cartCGSTEl.textContent = formatCurrency(showGst ? cgst : 0);
    if (cartSGSTEl) cartSGSTEl.textContent = formatCurrency(showGst ? sgst : 0);
    if (cartTaxEl) cartTaxEl.textContent = formatCurrency(showGst ? tax : 0);
    if (cartTotalEl) cartTotalEl.textContent = formatCurrency(total);
    
    // Update mobile bill summary (POS page)
    const mobilePosBillSubtotalEl = document.getElementById("mobilePosBillSubtotal");
    const mobilePosBillCGSTEl = document.getElementById("mobilePosBillCGST");
    const mobilePosBillSGSTEl = document.getElementById("mobilePosBillSGST");
    const mobilePosBillTaxEl = document.getElementById("mobilePosBillTax");
    const mobilePosBillTotalEl = document.getElementById("mobilePosBillTotal");
    
    if (mobilePosBillSubtotalEl) mobilePosBillSubtotalEl.textContent = formatCurrency(subtotal);
        if (mobilePosBillCGSTEl && mobilePosBillCGSTEl.parentElement) {
      mobilePosBillCGSTEl.parentElement.style.display = showGst ? "flex" : "none";
    }
    if (mobilePosBillSGSTEl && mobilePosBillSGSTEl.parentElement) {
      mobilePosBillSGSTEl.parentElement.style.display = showGst ? "flex" : "none";
    }
    if (mobilePosBillTaxEl && mobilePosBillTaxEl.parentElement) {
      mobilePosBillTaxEl.parentElement.style.display = showGst ? "flex" : "none";
    }
    if (mobilePosBillCGSTEl) mobilePosBillCGSTEl.textContent = formatCurrency(cgst);
    if (mobilePosBillSGSTEl) mobilePosBillSGSTEl.textContent = formatCurrency(sgst);
    if (mobilePosBillTaxEl) mobilePosBillTaxEl.textContent = formatCurrency(tax);
    if (mobilePosBillTotalEl) mobilePosBillTotalEl.textContent = formatCurrency(total);
  }
  
  // Toggle mobile bill details (POS page)
  window.toggleMobileBillDetails = function() {
    const details = document.getElementById('mobilePosBillDetails');
    const arrow = document.getElementById('mobilePosBillSummaryArrow');
    const addItemBtn = document.getElementById('mobileAddItemBtn');
    
    if (details && arrow) {
      const isVisible = details.style.display !== 'none';
      details.style.display = isVisible ? 'none' : 'block';
      arrow.style.transform = isVisible ? 'rotate(0deg)' : 'rotate(90deg)';
      
      // Hide/show Add Item button based on bill summary state
      if (addItemBtn) {
        if (isVisible) {
          // Bill summary is closing, show the button
          addItemBtn.style.display = 'flex';
        } else {
          // Bill summary is opening, hide the button
          addItemBtn.style.display = 'none';
        }
      }
    }
  };
  
  // Clear cart
  const clearCartBtn = document.getElementById("clearCartBtn");
  if (clearCartBtn) {
    clearCartBtn.addEventListener("click", async () => {
      // Support both posCart (admin) and window.posCart (waiter/chef/manager)
      const cart = typeof posCart !== 'undefined' ? posCart : (window.posCart || []);
      const updateCartFn = typeof updatePOSCart !== 'undefined' ? updatePOSCart : (window.updateWaiterPOSCart || window.updatePOSCart || (() => {}));
      
      if (cart.length > 0 && await showSweetConfirm("Are you sure you want to clear the cart?", 'Clear Cart')) {
        // Clear cart - support both local and window.posCart
        if (typeof posCart !== 'undefined') {
          posCart = [];
        } else if (window.posCart) {
          window.posCart = [];
        }
        updateCartFn();
        window._kotSentForCurrentOrder = false;
      }
    });
  }
  
  // Hold order function
  async function holdOrder() {
    // Support both posCart (admin) and window.posCart (waiter/chef/manager)
    const cart = typeof posCart !== 'undefined' ? posCart : (window.posCart || []);
    const updateCartFn = typeof updatePOSCart !== 'undefined' ? updatePOSCart : (window.updateWaiterPOSCart || window.updatePOSCart || (() => {}));
    
    if (cart.length === 0) {
      const showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
      showMsg("Cart is empty", "error");
      return;
    }
    
      const subtotal = cart.reduce((sum, item) => sum + (parseFloat(item.price) * item.quantity), 0);
      const showGst = window.enableGst == 1 || window.enableGst === true;
      const tax = showGst ? subtotal * 0.05 : 0;
      const total = subtotal + tax;
      const selectPosTable = document.getElementById("selectPosTable");
      const selectedTable = selectPosTable ? selectPosTable.value : '';
      
      const formData = new URLSearchParams();
      formData.append('action', 'hold_order');
      formData.append('tableId', selectedTable || '');
      formData.append('cartItems', JSON.stringify(cart));
      formData.append('subtotal', subtotal.toFixed(2));
      formData.append('tax', tax.toFixed(2));
      formData.append('total', total.toFixed(2));
      
      try {
        const response = await fetch("../controllers/pos_operations.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
          },
          body: formData
        });
        
        // Get response text first
        const responseText = await response.text();
        console.log('Response status:', response.status);
        console.log('Response text:', responseText.substring(0, 500)); // First 500 chars
        
        // Check if response is ok
        if (!response.ok) {
          console.error('Server error response (full):', responseText);
          // Try to parse as JSON for error message
          try {
            const errorResult = JSON.parse(responseText);
            throw new Error(errorResult.message || `Server error: ${response.status} ${response.statusText}`);
          } catch (e) {
            // Not JSON, show raw error
            throw new Error(`Server error (${response.status}): ${responseText.substring(0, 200)}`);
          }
        }
        
        // Parse JSON response
        let result;
        try {
          result = JSON.parse(responseText);
        } catch (e) {
          console.error('Invalid JSON response:', responseText);
          throw new Error('Invalid response from server. Response: ' + responseText.substring(0, 200));
        }
        
        if (result.success) {
          const showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
          showMsg(result.message + " - Order #" + result.order_number, "success");
          // Clear cart - support both local and window.posCart
          if (typeof posCart !== 'undefined') {
            posCart = [];
          } else if (window.posCart) {
            window.posCart = [];
          }
          updateCartFn();
          if (selectPosTable) {
            selectPosTable.value = "";
          }
        } else {
          const showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
          showMsg(result.message || "Error holding order.", "error");
        }
      } catch (error) {
        console.error("Error:", error);
        const showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
        showMsg("Network error. Please check your connection and try again.", "error");
      }
  }
  
  // Make holdOrder globally available
  window.holdOrder = holdOrder;
  
  // Hold order button listeners
  const holdOrderBtn = document.getElementById("holdOrderBtn");
  if (holdOrderBtn) {
    holdOrderBtn.addEventListener("click", holdOrder);
  }
  
  // Mobile hold order button
  const mobileHoldOrderBtn = document.getElementById("mobileHoldOrderBtn");
  if (mobileHoldOrderBtn) {
    mobileHoldOrderBtn.addEventListener("click", holdOrder);
  }
  
  // Show customer details modal for all orders (takeaway and dine-in)
  function showTakeawayCustomerModal(isTakeaway = true) {
    return new Promise((resolve, reject) => {
      console.log('showTakeawayCustomerModal called with isTakeaway:', isTakeaway);
      
      // Remove existing modal if it exists
      const existingModal = document.getElementById('takeawayCustomerModal');
      if (existingModal) {
        existingModal.remove();
      }
      
      const orderType = isTakeaway ? 'Takeaway' : 'Dine-in';
      console.log('Creating modal for order type:', orderType);
      
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
              <input type="text" id="takeawayCustomerName" required placeholder="Enter customer name" autocomplete="off" style="width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem;">
            </div>
            <div style="margin-bottom: 1rem;">
              <label style="display: block; margin-bottom: 0.5rem; color: #374151; font-weight: 500;">Phone Number</label>
              <input type="tel" id="takeawayCustomerPhone" placeholder="Enter phone number (optional)" autocomplete="off" style="width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem;">
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
      console.log('Modal appended to body');
      
      // Wait a tiny bit for DOM to be ready
      setTimeout(() => {
        // Close modal handlers
        const closeModal = () => {
          console.log('Modal closed');
          modal.remove();
          reject(new Error('Cancelled'));
        };
        
        const closeBtn = document.getElementById('closeTakeawayModal');
        const cancelBtn = document.getElementById('cancelTakeawayModal');
        const form = document.getElementById('takeawayCustomerForm');
        
        if (closeBtn) {
          closeBtn.addEventListener('click', closeModal);
        } else {
          console.error('Close button not found!');
        }
        
        if (cancelBtn) {
          cancelBtn.addEventListener('click', closeModal);
        } else {
          console.error('Cancel button not found!');
        }
        
        if (modal) {
          modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
          });
        }
        
        // Form submission
        if (form) {
          form.addEventListener('submit', (e) => {
            e.preventDefault();
            const customerData = {
              name: document.getElementById('takeawayCustomerName').value.trim(),
              phone: document.getElementById('takeawayCustomerPhone').value.trim(),
              email: document.getElementById('takeawayCustomerEmail').value.trim(),
              address: document.getElementById('takeawayCustomerAddress').value.trim()
            };
            console.log('Form submitted with data:', customerData);
            modal.remove();
            resolve(customerData);
          });
        } else {
          console.error('Form not found!');
        }
        
        // Initialize autocomplete for takeaway customer fields
        const nameInput = document.getElementById('takeawayCustomerName');
        const phoneInput = document.getElementById('takeawayCustomerPhone');
        const emailInput = document.getElementById('takeawayCustomerEmail');
        const addressInput = document.getElementById('takeawayCustomerAddress');
        
        if (nameInput && phoneInput) {
          console.log('Initializing autocomplete for takeaway modal');
          
          // Initialize autocomplete for customer name field
          initCustomerAutocomplete(nameInput, {
            nameField: nameInput,
            phoneField: phoneInput,
            emailField: emailInput,
            addressField: addressInput,
            onSelect: (customer) => {
              console.log('Customer selected from autocomplete:', customer);
            }
          });
          
          // Initialize autocomplete for phone field
          initCustomerAutocomplete(phoneInput, {
            nameField: nameInput,
            phoneField: phoneInput,
            emailField: emailInput,
            addressField: addressInput,
            onSelect: (customer) => {
              console.log('Customer selected from autocomplete:', customer);
            }
          });
        }
        
        // Focus on first input
        if (nameInput) {
          nameInput.focus();
        }
      }, 50);
      
      // Returning customer check - when phone number is entered (attach after DOM is ready)
      setTimeout(() => {
        const phoneInput = document.getElementById('takeawayCustomerPhone');
        if (!phoneInput) {
          console.error('Phone input not found!');
          return;
        }
        
        let checkTimeout;
        phoneInput.addEventListener('input', async () => {
          clearTimeout(checkTimeout);
          const phone = phoneInput.value.trim();
          
          // Wait for user to finish typing (500ms delay)
          checkTimeout = setTimeout(async () => {
            if (phone.length >= 10) {
              try {
                const response = await fetch('../api/get_customer_by_phone.php', {
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
                    const msgDiv = document.getElementById('returningCustomerMsg');
                    if (msgDiv) {
                      msgDiv.style.display = 'block';
                      setTimeout(() => {
                        msgDiv.style.display = 'none';
                      }, 5000);
                    }
                  }
                }
              } catch (error) {
                console.error('Error checking returning customer:', error);
              }
            }
          }, 500);
        });
      }, 50);
    });
  }
  
  // Pay button: real submission, same as the original "Process Payment" flow,
  // just using the new split-payment (Cash/UPI/Card) modal to pick the method.

  // Builds the customer-facing bill receipt HTML from cart + payment breakdown
  async function buildBillPreviewHtml(cartSnapshot, subtotal, tax, showGst, total, payResult, tableLabel, kotNumber) {
    let restaurantInfo = { name: 'Restaurant Name', logo: '', address: '', phone: '', user_id: '' };
    try {
      const infoRes = await fetch('../admin/get_session.php');
      const infoData = await infoRes.json();
      if (infoData.success && infoData.data) {
        restaurantInfo.name = infoData.data.restaurant_name || restaurantInfo.name;
        restaurantInfo.logo = infoData.data.restaurant_logo || '';
        restaurantInfo.user_id = infoData.data.user_id || infoData.data.id || '';
        restaurantInfo.address = infoData.data.address || '';
        restaurantInfo.phone = infoData.data.phone || '';
      }
    } catch (e) {
      console.warn('Could not load restaurant info:', e);
    }

    const currency = window.globalCurrencySymbol || '₹';
    const now = new Date();
    const dateStr = now.toLocaleDateString('en-IN', { day: '2-digit', month: '2-digit', year: 'numeric' });
    const timeStr = now.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });

    const itemsHtml = cartSnapshot.map((it) => `
      <tr>
        <td style="padding:6px 0;border-bottom:1px dashed #d1d5db;font-size:13px;">${escapeHtml(it.name)}${it.variationName ? ` <span style="color:#6b7280;">(${escapeHtml(it.variationName)})</span>` : ''}</td>
        <td style="padding:6px 0;border-bottom:1px dashed #d1d5db;font-size:13px;text-align:center;">${it.quantity}</td>
        <td style="padding:6px 0;border-bottom:1px dashed #d1d5db;font-size:13px;text-align:right;">${currency}${(parseFloat(it.price) * it.quantity).toFixed(2)}</td>
      </tr>
    `).join('');

    let logoHtml = '';
    if (restaurantInfo.logo) {
      let logoPath;
      if (restaurantInfo.logo.startsWith('db:')) {
        logoPath = `../api/image.php?type=logo&id=${restaurantInfo.user_id}`;
      } else if (restaurantInfo.logo.startsWith('http')) {
        logoPath = restaurantInfo.logo;
      } else if (restaurantInfo.logo.startsWith('uploads/')) {
        logoPath = '../' + restaurantInfo.logo;
      } else {
        logoPath = '../uploads/' + restaurantInfo.logo;
      }
      logoHtml = `<img src="${logoPath}" alt="Logo" style="max-width:70px;max-height:70px;object-fit:contain;margin-bottom:6px;" onerror="this.style.display='none';">`;
    }

    const payRows = [];
    if (payResult.cash > 0) payRows.push(['Cash', payResult.cash]);
    if (payResult.upi > 0) payRows.push(['UPI', payResult.upi]);
    if (payResult.card > 0) payRows.push(['Card', payResult.card]);
    const payRowsHtml = payRows.map(([label, amt]) => `
      <div style="display:flex;justify-content:space-between;font-size:12px;"><span>${label}</span><span>${currency}${amt.toFixed(2)}</span></div>
    `).join('');

    return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Bill #${kotNumber}</title>
      <style>
        @media print { @page { margin: 8mm; size: 80mm auto; } body { margin:0; padding:0; } }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Courier New', Courier, monospace; padding:12px; max-width:280px; margin:0 auto; background:#fff; color:#111827; }
        .header { text-align:center; border-bottom:3px double #059669; padding-bottom:10px; margin-bottom:12px; }
        .restaurant-name { font-size:18px; font-weight:800; color:#059669; text-transform:uppercase; letter-spacing:0.5px; font-family:'Segoe UI',Tahoma,sans-serif; }
        .restaurant-details { font-size:10px; color:#6b7280; line-height:1.4; margin-top:4px; font-family:'Segoe UI',Tahoma,sans-serif; }
        .bill-tag { font-size:12px; font-weight:700; color:#059669; margin-top:6px; }
        .info-box { background:#f9fafb; padding:10px; margin-bottom:12px; border-left:4px solid #059669; font-size:12px; }
        .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:4px 12px; }
        .info-item { display:flex; justify-content:space-between; }
        .info-label { color:#6b7280; font-weight:600; }
        .info-value { color:#111827; font-weight:700; text-align:right; }
        table { width:100%; border-collapse:collapse; margin:10px 0; }
        th { font-size:11px; text-transform:uppercase; text-align:left; border-bottom:2px solid #111827; padding-bottom:4px; }
        .totals { margin-top:10px; font-size:13px; }
        .totals div { display:flex; justify-content:space-between; padding:2px 0; }
        .totals .grand { font-size:15px; font-weight:800; border-top:2px dashed #d1d5db; margin-top:6px; padding-top:6px; }
        .pay-box { margin-top:12px; padding-top:10px; border-top:2px dashed #d1d5db; }
        .footer { text-align:center; margin-top:14px; padding-top:10px; border-top:2px dashed #e5e7eb; }
        .footer-text { font-size:10px; color:#6b7280; margin-bottom:2px; }
      </style>
    </head><body>
      <div class="header">
        ${logoHtml}
        <div class="restaurant-name">${escapeHtml(restaurantInfo.name)}</div>
        ${restaurantInfo.address ? `<div class="restaurant-details">${escapeHtml(restaurantInfo.address)}</div>` : ''}
        ${restaurantInfo.phone ? `<div class="restaurant-details">Ph: ${escapeHtml(restaurantInfo.phone)}</div>` : ''}
        <div class="bill-tag">BILL #${escapeHtml(String(kotNumber))}</div>
        <div style="font-size:11px;color:#6b7280;margin-top:4px;">${dateStr} | ${timeStr}</div>
      </div>

      <div class="info-box">
        <div class="info-grid">
          <div class="info-item"><span class="info-label">TABLE</span><span class="info-value">${escapeHtml(tableLabel)}</span></div>
          <div class="info-item"><span class="info-label">STATUS</span><span class="info-value">${escapeHtml(payResult.paymentStatus)}</span></div>
        </div>
      </div>

      <table>
        <thead><tr><th>Item</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Amt</th></tr></thead>
        <tbody>${itemsHtml}</tbody>
      </table>

      <div class="totals">
        <div><span>Subtotal</span><span>${currency}${subtotal.toFixed(2)}</span></div>
        ${showGst ? `<div><span>Tax (5%)</span><span>${currency}${tax.toFixed(2)}</span></div>` : ''}
        <div class="grand"><span>Total</span><span>${currency}${total.toFixed(2)}</span></div>
      </div>

      <div class="pay-box">
        ${payRowsHtml}
        ${payResult.returnAmount > 0 ? `<div style="display:flex;justify-content:space-between;font-size:12px;color:#059669;font-weight:700;"><span>Return</span><span>${currency}${payResult.returnAmount.toFixed(2)}</span></div>` : ''}
        ${payResult.dueAmount > 0 ? `<div style="display:flex;justify-content:space-between;font-size:12px;color:#b45309;font-weight:700;"><span>Due</span><span>${currency}${payResult.dueAmount.toFixed(2)}</span></div>` : ''}
      </div>

      <div class="footer">
        <div class="footer-text">Thank you!</div>
        <div class="footer-text">Powered by Restro Grow</div>
      </div>
    </body></html>`;
  }

  async function processPayment() {
    // Support both posCart (admin) and window.posCart (waiter/chef/manager)
    const cart = typeof posCart !== 'undefined' ? posCart : (window.posCart || []);
    const updateCartFn = typeof updatePOSCart !== 'undefined' ? updatePOSCart : (window.updateWaiterPOSCart || window.updatePOSCart || (() => {}));

    if (cart.length === 0) {
      const showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
      showMsg("Cart is empty", "error");
      return;
    }

    const subtotal = cart.reduce((sum, item) => sum + (parseFloat(item.price) * item.quantity), 0);
    const showGst = window.enableGst == 1 || window.enableGst === true;
    const tax = showGst ? subtotal * 0.05 : 0;
    const total = subtotal + tax;
    const selectPosTable = document.getElementById("selectPosTable");
    const selectedTable = selectPosTable ? selectPosTable.value : '';
    const isTakeaway = !selectedTable;
    const tableLabel = (selectPosTable && selectedTable && selectPosTable.selectedIndex >= 0)
      ? selectPosTable.options[selectPosTable.selectedIndex].text
      : 'Takeaway';

    // Show the split pay-method modal directly - no customer details prompt
    const payResult = await showSplitPaymentModal(total);
    if (!payResult) {
      return; // User cancelled - cart untouched
    }

    const currency = window.globalCurrencySymbol || '₹';
    const formData = new URLSearchParams();
    formData.append('action', 'create_kot');
    formData.append('tableId', selectedTable || '');
    formData.append('orderType', isTakeaway ? 'Takeaway' : 'Dine-in');
    formData.append('customerName', isTakeaway ? 'Takeaway' : 'Table Customer');
    formData.append('paymentMethod', payResult.paymentMethod);
    formData.append('notes', `Cash: ${currency}${payResult.cash.toFixed(2)}, UPI: ${currency}${payResult.upi.toFixed(2)}, Card: ${currency}${payResult.card.toFixed(2)}${payResult.dueAmount > 0 ? `, Due: ${currency}${payResult.dueAmount.toFixed(2)}` : ''}`);
    formData.append('cartItems', JSON.stringify(cart));
    formData.append('subtotal', subtotal.toFixed(2));
    formData.append('tax', tax.toFixed(2));
    formData.append('total', total.toFixed(2));

    try {
      const response = await fetch("../controllers/pos_operations.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: formData
      });

      // Get response text first
      const responseText = await response.text();
      console.log('Response status:', response.status);
      console.log('Response text:', responseText.substring(0, 500)); // First 500 chars

      // Check if response is ok
      if (!response.ok) {
        console.error('Server error response (full):', responseText);
        // Try to parse as JSON for error message
        try {
          const errorResult = JSON.parse(responseText);
          throw new Error(errorResult.message || `Server error: ${response.status} ${response.statusText}`);
        } catch (e) {
          // Not JSON, show raw error
          throw new Error(`Server error (${response.status}): ${responseText.substring(0, 200)}`);
        }
      }

      // Parse JSON response
      let result;
      try {
        result = JSON.parse(responseText);
      } catch (e) {
        console.error('Invalid JSON response:', responseText);
        throw new Error('Invalid response from server. Response: ' + responseText.substring(0, 200));
      }

      if (result.success) {
        // Snapshot the cart for the bill preview before clearing it
        const cartSnapshot = cart.slice();
        const kotWasAlreadySent = !!window._kotSentForCurrentOrder;

        // Clear cart - support both local and window.posCart
        if (typeof posCart !== 'undefined') {
          posCart = [];
        } else if (window.posCart) {
          window.posCart = [];
        }
        updateCartFn();
        if (selectPosTable) {
          selectPosTable.value = "";
        }
        window._kotSentForCurrentOrder = false;

        const showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
        showMsg(`Payment saved (${payResult.paymentStatus}) - KOT #${result.kot_number}`, "success");

        const showBill = async () => {
          const billHtml = await buildBillPreviewHtml(cartSnapshot, subtotal, tax, showGst, total, payResult, tableLabel, result.kot_number);
          showPrintPreview('Bill #' + result.kot_number, billHtml);
        };

        if (kotWasAlreadySent) {
          // Kitchen already saw the KOT (via the KOT button) - just show the bill
          showBill();
        } else {
          // Pay clicked directly - kitchen hasn't seen this order yet, so show
          // the KOT preview first, then the bill preview once it's closed
          if (window.printKOT) {
            window.printKOT(result.kot_id, showBill);
          } else {
            showBill();
          }
        }

        // Reload orders if on the orders page
        const ordersPage = document.getElementById('ordersPage');
        if (ordersPage && ordersPage.classList.contains('active') && typeof loadOrders !== 'undefined') {
          loadOrders();
        }
      } else {
        const showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
        showMsg(result.message || "Error creating KOT.", "error");
      }
    } catch (error) {
      console.error("Error:", error);
      const showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
      showMsg("Network error. Please check your connection and try again.", "error");
    }
  }
  
  // Make processPayment globally available
  window.processPayment = processPayment;
  
  // Process payment button listeners
  const processPaymentBtn = document.getElementById("processPaymentBtn");
  if (processPaymentBtn) {
    processPaymentBtn.addEventListener("click", processPayment);
  }
  
  // Mobile process payment button
  const mobileProcessPaymentBtn = document.getElementById("mobileProcessPaymentBtn");
  if (mobileProcessPaymentBtn) {
    mobileProcessPaymentBtn.addEventListener("click", processPayment);
  }

  // Send to kitchen (KOT only, no payment collected)
  // KOT button: preview-only for now. Nothing is sent to the server and the
  // cart is left untouched (whether the user prints or just closes the preview).
  async function sendToKitchen() {
    // Support both posCart (admin) and window.posCart (waiter/chef/manager)
    const cart = typeof posCart !== 'undefined' ? posCart : (window.posCart || []);

    if (cart.length === 0) {
      const showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
      showMsg("Cart is empty", "error");
      return;
    }

    // Mark that the kitchen has already seen this order, so Pay (afterwards)
    // won't show the KOT preview again - just the bill.
    window._kotSentForCurrentOrder = true;

    const selectPosTable = document.getElementById("selectPosTable");
    const selectedTable = selectPosTable ? selectPosTable.value : '';
    const tableLabel = (selectPosTable && selectedTable && selectPosTable.selectedIndex >= 0)
      ? selectPosTable.options[selectPosTable.selectedIndex].text
      : 'Takeaway';

    // Fetch restaurant info for the receipt header (best-effort)
    let restaurantInfo = { name: 'Restaurant Name', logo: '', address: '', phone: '', user_id: '' };
    try {
      const infoRes = await fetch('../admin/get_session.php');
      const infoData = await infoRes.json();
      if (infoData.success && infoData.data) {
        restaurantInfo.name = infoData.data.restaurant_name || restaurantInfo.name;
        restaurantInfo.logo = infoData.data.restaurant_logo || '';
        restaurantInfo.user_id = infoData.data.user_id || infoData.data.id || '';
        restaurantInfo.address = infoData.data.address || '';
        restaurantInfo.phone = infoData.data.phone || '';
      }
    } catch (e) {
      console.warn('Could not load restaurant info:', e);
    }

    const now = new Date();
    const dateStr = now.toLocaleDateString('en-IN', { day: '2-digit', month: '2-digit', year: 'numeric' });
    const timeStr = now.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });

    const itemsHtml = cart.map((it) => `
      <tr>
        <td style="padding: 6px 0; border-bottom: 1px dashed #d1d5db; font-size: 13px;">
          <table style="width:100%;"><tr>
            <td style="width:40px; text-align:center; font-weight:700; font-size:14px;">${it.quantity}</td>
            <td style="padding-left:6px;">
              <div style="font-weight:600; font-size:14px; color:#111827;">${escapeHtml(it.name)}${it.variationName ? ` <span style="color:#6b7280; font-weight:400;">(${escapeHtml(it.variationName)})</span>` : ''}</div>
            </td>
          </tr></table>
        </td>
      </tr>
    `).join('');

    let logoHtml = '';
    if (restaurantInfo.logo) {
      let logoPath;
      if (restaurantInfo.logo.startsWith('db:')) {
        logoPath = `../api/image.php?type=logo&id=${restaurantInfo.user_id}`;
      } else if (restaurantInfo.logo.startsWith('http')) {
        logoPath = restaurantInfo.logo;
      } else if (restaurantInfo.logo.startsWith('uploads/')) {
        logoPath = '../' + restaurantInfo.logo;
      } else {
        logoPath = '../uploads/' + restaurantInfo.logo;
      }
      logoHtml = `<img src="${logoPath}" alt="Logo" style="max-width:70px; max-height:70px; object-fit:contain; margin-bottom:6px;" onerror="this.style.display='none';">`;
    }

    const itemsCount = cart.length;

    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>KOT Preview</title>
      <style>
        @media print { @page { margin: 8mm; size: 80mm auto; } body { margin:0; padding:0; } }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Courier New', Courier, monospace; padding:12px; max-width:280px; margin:0 auto; background:#fff; color:#111827; }
        .header { text-align:center; border-bottom:3px double #dc2626; padding-bottom:10px; margin-bottom:12px; }
        .logo { margin-bottom:6px; }
        .restaurant-name { font-size:18px; font-weight:800; color:#dc2626; text-transform:uppercase; letter-spacing:0.5px; font-family:'Segoe UI',Tahoma,sans-serif; }
        .restaurant-details { font-size:10px; color:#6b7280; line-height:1.4; margin-top:4px; font-family:'Segoe UI',Tahoma,sans-serif; }
        .kot-banner h2 { font-size:28px; font-weight:900; color:#dc2626; letter-spacing:3px; margin-bottom:2px; font-family:'Segoe UI',Tahoma,sans-serif; }
        .kot-number { font-size:14px; font-weight:700; color:#b45309; }
        .info-box { background:#f9fafb; padding:10px; margin-bottom:12px; border-left:4px solid #dc2626; font-size:12px; }
        .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:4px 12px; }
        .info-item { display:flex; justify-content:space-between; }
        .info-label { color:#6b7280; font-weight:600; }
        .info-value { color:#111827; font-weight:700; text-align:right; }
        .items-section { margin:12px 0; }
        .items-header { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:6px 0; border-bottom:2px solid #111827; margin-bottom:4px; display:flex; justify-content:space-between; }
        table { width:100%; border-collapse:collapse; }
        .divider { border:none; border-top:2px dashed #d1d5db; margin:10px 0; }
        .footer { text-align:center; margin-top:14px; padding-top:10px; border-top:2px dashed #e5e7eb; }
        .footer-text { font-size:10px; color:#6b7280; margin-bottom:2px; }
        .total-items { font-size:12px; font-weight:700; color:#111827; margin-top:6px; }
        .sep-line { border:none; border-top:1px dashed #d1d5db; margin:6px 0; }
      </style>
    </head><body>
      <div class="header">
        ${logoHtml}
        <div class="restaurant-name">${escapeHtml(restaurantInfo.name)}</div>
        ${restaurantInfo.address ? `<div class="restaurant-details">${escapeHtml(restaurantInfo.address)}</div>` : ''}
        ${restaurantInfo.phone ? `<div class="restaurant-details">Ph: ${escapeHtml(restaurantInfo.phone)}</div>` : ''}
      </div>
        <h2>KOT</h2>
        <div class="kot-number">PREVIEW - NOT SENT</div>
        <hr class="sep-line">
        <div style="font-size:11px; color:#6b7280;">${dateStr} | ${timeStr}</div>
      </div>

      <div class="info-box">
        <div class="info-grid">
          <div class="info-item"><span class="info-label">TABLE</span><span class="info-value">${escapeHtml(tableLabel)}</span></div>
          <div class="info-item"><span class="info-label">STATUS</span><span class="info-value">Preview</span></div>
        </div>
      </div>

      <div class="items-section">
        <div class="items-header">
          <span>QTY</span>
          <span>ITEM</span>
        </div>
        <table>${itemsHtml}</table>
      </div>

      <hr class="divider">

      <div class="footer">
        <div style="font-size:11px; font-weight:600; margin-bottom:4px;">Total Items: ${itemsCount}</div>
        <div class="footer-text">Thank you!</div>
        <div class="footer-text">Powered by Restro Grow</div>
      </div>
    </body></html>`;

    showPrintPreview('KOT Preview', html);
  }

  // Make sendToKitchen globally available
  window.sendToKitchen = sendToKitchen;

  // Send to kitchen button listeners
  const sendKotBtn = document.getElementById("sendKotBtn");
  if (sendKotBtn) {
    sendKotBtn.addEventListener("click", sendToKitchen);
  }

  const mobileSendKotBtn = document.getElementById("mobileSendKotBtn");
  if (mobileSendKotBtn) {
    mobileSendKotBtn.addEventListener("click", sendToKitchen);
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
  
  // POS Clear Cart Modal functions (for backward compatibility with old modal)
  window.closePOSClearCartModal = function() {
    const modal = document.getElementById('posClearCartModal');
    if (modal) {
      modal.style.display = 'none';
    }
  };
  
  // POS Payment Method Modal functions (for backward compatibility with old modal)
  window.closePOSPaymentMethodModal = function() {
    const modal = document.getElementById('posPaymentMethodModal');
    if (modal) {
      modal.style.display = 'none';
    }
  };
  
  window.selectPaymentMethod = function(method) {
    // Close the modal
    closePOSPaymentMethodModal();
    // Note: This function is for the old modal. The new implementation uses SweetAlert.
    // If you want to use the old modal, you'll need to handle the payment processing here.
    console.warn('selectPaymentMethod called - consider using SweetAlert implementation instead');
  };
  
  // Setup clear cart confirm button if modal exists
  const posClearCartConfirmBtn = document.getElementById('posClearCartConfirmBtn');
  if (posClearCartConfirmBtn) {
    posClearCartConfirmBtn.addEventListener('click', async () => {
      if (posCart.length > 0 && await showSweetConfirm("Are you sure you want to clear the cart?", 'Clear Cart')) {
        posCart = [];
        updatePOSCart();
        closePOSClearCartModal();
      }
    });
  }
});

// Session management and logout functionality
document.addEventListener("DOMContentLoaded", () => {
  // Load restaurant info from session
  loadRestaurantInfo();
});

async function loadRestaurantInfo() {
  try {
    const response = await fetch("../admin/get_session.php");
    const result = await response.json();
    
    if (checkSessionExpired(response, result)) return;
    
    if (result.success) {
      const restaurantNameEl = document.getElementById("restaurantName");
      const restaurantIdEl = document.getElementById("restaurantId");
      if (restaurantNameEl) restaurantNameEl.textContent = result.data.restaurant_name;
      if (restaurantIdEl) restaurantIdEl.textContent = result.data.restaurant_id;
      
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
  // Payment API is disabled - show contact message instead
  showNotification('Payment integration is currently unavailable. Please contact us to renew your subscription.', 'info');
  return;
}

// Make function globally available
window.initiateRenewal = initiateRenewal;

async function logout() {
  if (await showSweetConfirm("Are you sure you want to logout?", 'Logout')) {
    try {
      const response = await fetch("../admin/auth.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: "action=logout"
      });
      
      const result = await response.json();
      
      if (result.success) {
        // Redirect to login page (correct path from views/ folder)
        window.location.href = "../admin/login.php";
      } else {
        showNotification("Error logging out. Please try again.", "error");
      }
    } catch (error) {
      console.error("Error logging out:", error);
      showNotification("Network error. Please try again.", "error");
    }
  }
}

// Make logout globally accessible
window.logout = logout;

  // Load Online Orders
  // Track previous order count for new-order notification
  let prevOnlineOrderCount = 0;
  window.onlineOrdersSoundEnabled = true;
  let prevKotOrderCount = 0;
  window.kotOrdersSoundEnabled = true;

  async function loadOnlineOrders() {
    showLoading('onlineOrdersList', 'Loading orders...');
    try {
      const statusFilter = document.getElementById('onlineOrdersStatusFilter')?.value || '';
      const searchTerm = document.getElementById('onlineOrdersSearch')?.value.trim() || '';

      const params = new URLSearchParams();
      if (statusFilter) params.append('status', statusFilter);
      if (searchTerm) params.append('search', searchTerm);

      const response = await fetch(`../api/get_online_orders.php?${params.toString()}`, { cache: 'no-store' });
      const data = await response.json();

      if (data.success) {
        const pendingCount = (data.orders || []).filter(o => o.order_status === 'Pending').length;
        if (prevOnlineOrderCount > 0 && pendingCount > prevOnlineOrderCount && window.onlineOrdersSoundEnabled) {
          playNotificationSound();
        }
        prevOnlineOrderCount = pendingCount;
        updatePendingOrderBadge(pendingCount);
        window.whatsappEnabled = data.whatsapp_enabled || false;
        window.whatsappPhone = data.whatsapp_phone || '';
        window.restaurantLat = data.restaurant_lat || null;
        window.restaurantLng = data.restaurant_lng || null;
        window.restaurantAddressText = data.restaurant_address || '';
        displayOnlineOrders(data.orders);
      } else {
        document.getElementById('onlineOrdersList').innerHTML = '<div class="error">Failed to load online orders</div>';
      }
    } catch (error) {
      console.error('Error loading online orders:', error);
      document.getElementById('onlineOrdersList').innerHTML = '<div class="error">Error loading online orders</div>';
    }
  }

  function playNotificationSound() {
    try {
      const audio = new Audio('../../assets/sounds/notification.wav');
      audio.volume = 0.5;
      audio.play().catch(() => {});
    } catch(e) {}
  }

  function playClickSound() {
    try {
      const audio = new Audio('../../assets/sounds/click.wav');
      audio.volume = 0.5;
      audio.play().catch(() => {});
    } catch(e) {}
  }

  function updatePendingOrderBadge(count) {
    const badges = ['onlineOrdersBadge', 'ordersBadge'];
    badges.forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      if (count > 0) {
        el.textContent = count;
        el.style.display = '';
        if (el.dataset.prev !== undefined && el.dataset.prev !== count.toString()) {
          el.classList.remove('pulse');
          void el.offsetWidth;
          el.classList.add('pulse');
        }
        el.dataset.prev = count;
      } else {
        el.style.display = 'none';
        el.dataset.prev = 0;
      }
    });
  }

  function haversineKmOrders(lat1, lon1, lat2, lon2) {
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  // Builds a map + distance + "Open Route" block for a Delivery-type order.
  // Uses stored customer lat/lng when available (precise pin + straight-line
  // distance); otherwise falls back to letting Google geocode the typed
  // address text, which still works for the route/embed, just less precisely.
  function buildDeliveryMapHtml(order) {
    if (order.order_type !== 'Delivery' || !order.customer_address) return '';

    const rLat = window.restaurantLat ? parseFloat(window.restaurantLat) : null;
    const rLng = window.restaurantLng ? parseFloat(window.restaurantLng) : null;
    const cLat = order.address_lat ? parseFloat(order.address_lat) : null;
    const cLng = order.address_lng ? parseFloat(order.address_lng) : null;
    const hasCoords = !!(rLat && rLng && cLat && cLng);

    const destination = hasCoords ? `${cLat},${cLng}` : encodeURIComponent(order.customer_address);
    const origin = hasCoords ? `${rLat},${rLng}` : (window.restaurantAddressText ? encodeURIComponent(window.restaurantAddressText) : '');
    const directionsUrl = `https://www.google.com/maps/dir/?api=1${origin ? '&origin=' + origin : ''}&destination=${destination}&travelmode=driving`;

    const distanceLabel = hasCoords
      ? `≈ ${haversineKmOrders(rLat, rLng, cLat, cLng).toFixed(1)} km from restaurant (straight-line)`
      : 'Delivery route';

    let embedHtml = '';
    if (window.googleMapsApiKey && origin) {
      const embedSrc = `https://www.google.com/maps/embed/v1/directions?key=${encodeURIComponent(window.googleMapsApiKey)}&origin=${origin}&destination=${destination}&mode=driving`;
      embedHtml = `<iframe src="${embedSrc}" width="100%" height="200" style="border:0;border-radius:8px;margin-top:6px;display:block;" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>`;
    }

    return `
      <div class="delivery-map-block" style="margin-top:8px;padding:10px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
          <span style="font-size:0.85rem;color:#0369a1;">🗺️ ${distanceLabel}</span>
          <a href="${directionsUrl}" target="_blank" rel="noopener" style="background:#0ea5e9;color:white;border:none;padding:4px 10px;font-size:0.8rem;text-decoration:none;border-radius:6px;white-space:nowrap;">Open Route in Google Maps</a>
        </div>
        ${embedHtml}
      </div>
    `;
  }

  function displayOnlineOrders(orders) {
    const container = document.getElementById('onlineOrdersList');

    if (orders.length === 0) {
      container.innerHTML = '<div class="empty-state">No online orders found</div>';
      return;
    }

    container.innerHTML = orders.map(order => {
      const status = order.order_status || 'Pending';
      const paymentBadgeClass = (order.payment_status || '').toLowerCase().replace(' ', '-');
      const paymentLabel = order.payment_method === 'Cash' && order.payment_status === 'Pending' ? 'Pending (Cash)' : (order.payment_status || 'N/A');

      // STATUS-SPECIFIC RENDERING
      if (status === 'Pending') {
        // Pending — only show Accept/Reject, no items/actions
        return `
          <div class="order-card" style="border-left: 4px solid #f59e0b;">
            <div class="order-header">
              <h3>Order #${order.order_number}</h3>
              <div class="order-badges">
                <span class="status-badge pending" style="background:#fef3c7;color:#92400e;padding:4px 10px;border-radius:12px;font-weight:600;font-size:0.8rem">PENDING</span>
                <span class="payment-badge ${paymentBadgeClass}">${paymentLabel}</span>
              </div>
            </div>
            <div class="order-details">
              <p><strong>${order.order_type || 'N/A'}</strong> &middot; ${formatCurrency(order.total)}</p>
              <p><strong>${escapeHtml(order.customer_name || 'N/A')}</strong> &middot; ${order.customer_phone || ''}</p>
              ${order.customer_address ? `<p style="color:#6b7280;font-size:0.85rem">📍 ${escapeHtml(order.customer_address)}</p>` : ''}
              <p style="color:#6b7280;font-size:0.85rem">${new Date(order.created_at).toLocaleString()}</p>
            </div>
            ${buildDeliveryMapHtml(order)}
            ${order.notes ? `<div class="order-notes" style="background:#fefce8;padding:8px 12px;border-radius:8px;margin:8px 0;font-size:0.85rem;color:#92400e"><strong>Notes:</strong> ${escapeHtml(order.notes)}</div>` : ''}
            <div class="order-actions" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 12px;">
              <button class="btn btn-success" onclick="updateOrderStatus(${order.id}, 'Accepted', this)" style="background: #10b981; color: white; border: none; display: flex; align-items: center; gap: 4px; flex: 1; justify-content: center;">
                <span class="material-symbols-rounded" style="font-size: 1.1rem;">check</span>
                Accept
                <span class="loading-spinner" style="display:none"></span>
              </button>
              <button class="btn btn-danger" onclick="rejectOnlineOrder(${order.id}, '${order.order_number}', this)" style="background: #ef4444; color: white; border: none; display: flex; align-items: center; gap: 4px; flex: 1; justify-content: center;">
                <span class="material-symbols-rounded" style="font-size: 1.1rem;">close</span>
                Reject
                <span class="loading-spinner" style="display:none"></span>
              </button>
              ${window.whatsappEnabled && window.whatsappPhone ? `
                <a href="https://wa.me/${window.whatsappPhone}?text=${encodeURIComponent('New Order #' + order.order_number + '\nCustomer: ' + order.customer_name + '\nPhone: ' + order.customer_phone + '\nType: ' + order.order_type + '\nTotal: ' + formatCurrency(order.total) + '\n\nCheck the admin panel for full details.')}" target="_blank" class="btn" style="background: #25D366; color: white; border: none; display: flex; align-items: center; gap: 4px; flex: 1; justify-content: center; text-decoration: none;">
                  <span style="font-weight:bold;font-size:1.1rem;">📱</span>
                  WhatsApp
                </a>
              ` : ''}
            </div>
          </div>
        `;
      } else if (status === 'Rejected') {
        // Rejected — dimmed card with rejected badge
        return `
          <div class="order-card" style="border-left: 4px solid #ef4444; opacity: 0.7;">
            <div class="order-header">
              <h3>Order #${order.order_number}</h3>
              <div class="order-badges">
                <span class="status-badge rejected" style="background:#fee2e2;color:#dc2626;padding:4px 10px;border-radius:12px;font-weight:600;font-size:0.8rem">REJECTED</span>
                <span class="payment-badge ${paymentBadgeClass}">${paymentLabel}</span>
              </div>
            </div>
            <div class="order-details">
              <p><strong>${order.order_type || 'N/A'}</strong> &middot; ${formatCurrency(order.total)}</p>
              <p><strong>${escapeHtml(order.customer_name || 'N/A')}</strong> &middot; ${order.customer_phone || ''}</p>
              <p style="color:#6b7280;font-size:0.85rem">${new Date(order.created_at).toLocaleString()}</p>
            </div>
            ${order.notes ? `<div class="order-notes" style="background:#fefce8;padding:8px 12px;border-radius:8px;margin:8px 0;font-size:0.85rem;color:#92400e"><strong>Notes:</strong> ${escapeHtml(order.notes)}</div>` : ''}
          </div>
        `;
      } else if (status === 'Accepted') {
        // Accepted — show full details with items + action to start cooking
        return `
          <div class="order-card" style="border-left: 4px solid #3b82f6;">
            <div class="order-header">
              <h3>Order #${order.order_number}</h3>
              <div class="order-badges">
                <span class="status-badge accepted" style="background:#dbeafe;color:#1e40af;padding:4px 10px;border-radius:12px;font-weight:600;font-size:0.8rem">ACCEPTED</span>
                <span class="payment-badge ${paymentBadgeClass}">${paymentLabel}</span>
              </div>
            </div>
            <div class="order-details">
              <p><strong>Type:</strong> ${order.order_type || 'N/A'}</p>
              <p><strong>Customer:</strong> ${escapeHtml(order.customer_name || 'N/A')}</p>
              <p><strong>Phone:</strong> ${order.customer_phone || 'N/A'}</p>
              ${order.customer_email ? `<p><strong>Email:</strong> ${escapeHtml(order.customer_email)}</p>` : ''}
              ${order.customer_address ? `<p><strong>Address:</strong> ${escapeHtml(order.customer_address)}</p>` : ''}
              <p><strong>Time:</strong> ${new Date(order.created_at).toLocaleString()}</p>
              <p><strong>Total:</strong> ${formatCurrency(order.total)}</p>
            </div>
            ${buildDeliveryMapHtml(order)}
            ${order.notes ? `<div class="order-notes" style="background:#fefce8;padding:8px 12px;border-radius:8px;margin:8px 0;font-size:0.85rem;color:#92400e"><strong>Notes:</strong> ${escapeHtml(order.notes)}</div>` : ''}
            <div class="order-items">
              <h4>Items:</h4>
              ${(order.items || []).map(item => `
                <div class="order-item">
                  <span class="item-name">${escapeHtml(item.item_name)}</span>
                  <span class="item-qty">x${item.quantity}</span>
                  <span class="item-price">${formatCurrency(item.total_price)}</span>
                  ${item.notes ? `<div style="font-size:0.8rem;color:#92400e;margin-top:2px">📝 ${escapeHtml(item.notes)}</div>` : ''}
                </div>
              `).join('')}
            </div>
            <div class="order-actions" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 12px;">
              <button class="btn btn-primary" onclick="updateOrderStatus(${order.id}, 'Preparing')" style="display: flex; align-items: center; gap: 4px;">
                <span class="material-symbols-rounded" style="font-size: 1.1rem;">restaurant</span>
                Start Cooking
              </button>
              <button class="btn btn-info" onclick="showFullOrderDetails(${order.id})" style="background: #667eea; color: white; border: none; display: flex; align-items: center; gap: 4px;">
                <span class="material-symbols-rounded" style="font-size: 1.1rem;">visibility</span>
                Show Order
              </button>
              <button class="btn btn-print" onclick="printOrder(${order.id})" style="background: #6b7280; color: white; border: none; display: flex; align-items: center; gap: 4px;">
                <span class="material-symbols-rounded" style="font-size: 1.1rem;">print</span>
                Print
              </button>
              <button class="btn btn-danger" onclick="updateOrderStatus(${order.id}, 'Cancelled')">Cancel Order</button>
              ${window.whatsappEnabled && window.whatsappPhone ? `
                <a href="https://wa.me/${window.whatsappPhone}?text=${encodeURIComponent('Order #' + order.order_number + '\nCustomer: ' + order.customer_name + '\nPhone: ' + order.customer_phone + '\nType: ' + order.order_type + '\nTotal: ' + formatCurrency(order.total))}" target="_blank" class="btn" style="background: #25D366; color: white; border: none; display: flex; align-items: center; gap: 4px; text-decoration: none;">
                  <span style="font-weight:bold;font-size:1.1rem;">📱</span>
                  WhatsApp
                </a>
              ` : ''}
            </div>
          </div>
        `;
      }

      // OTHER STATUSES (Preparing, Ready, Served, Completed, Cancelled)
      const nextBtn = status === 'Preparing'
        ? { label: 'Mark as Ready', next: 'Ready', color: '#10b981', icon: 'check' }
        : status === 'Ready'
        ? { label: 'Mark as Served', next: 'Served', color: '#10b981', icon: 'check' }
        : status === 'Served'
        ? { label: 'Mark as Completed', next: 'Completed', color: '#10b981', icon: 'check' }
        : null;
      return `
        <div class="order-card">
          <div class="order-header">
            <h3>Order #${order.order_number}</h3>
            <div class="order-badges">
              <span class="status-badge ${status.toLowerCase()}">${status}</span>
              <span class="payment-badge ${paymentBadgeClass}">${paymentLabel}</span>
            </div>
          </div>
          <div class="order-details">
            <p><strong>Type:</strong> ${order.order_type || 'N/A'}</p>
            <p><strong>Customer:</strong> ${escapeHtml(order.customer_name || 'N/A')}</p>
            <p><strong>Phone:</strong> ${order.customer_phone || 'N/A'}</p>
            ${order.customer_email ? `<p><strong>Email:</strong> ${escapeHtml(order.customer_email)}</p>` : ''}
            ${order.customer_address ? `<p><strong>Address:</strong> ${escapeHtml(order.customer_address)}</p>` : ''}
            <p><strong>Time:</strong> ${new Date(order.created_at).toLocaleString()}</p>
            <p><strong>Total:</strong> ${formatCurrency(order.total)}</p>
          </div>
          ${buildDeliveryMapHtml(order)}
          ${order.notes ? `<div class="order-notes" style="background:#fefce8;padding:8px 12px;border-radius:8px;margin:8px 0;font-size:0.85rem;color:#92400e"><strong>Notes:</strong> ${escapeHtml(order.notes)}</div>` : ''}
          <div class="order-items">
            <h4>Items:</h4>
            ${(order.items || []).map(item => `
              <div class="order-item">
                <span class="item-name">${escapeHtml(item.item_name)}</span>
                <span class="item-qty">x${item.quantity}</span>
                <span class="item-price">${formatCurrency(item.total_price)}</span>
                ${item.notes ? `<div style="font-size:0.8rem;color:#92400e;margin-top:2px">📝 ${escapeHtml(item.notes)}</div>` : ''}
              </div>
            `).join('')}
          </div>
          <div class="order-actions" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            ${nextBtn ? `
              <button class="btn btn-success" onclick="updateOrderStatus(${order.id}, '${nextBtn.next}')" style="background: ${nextBtn.color}; color: white; border: none; display: flex; align-items: center; gap: 4px;">
                <span class="material-symbols-rounded" style="font-size: 1.1rem;">${nextBtn.icon}</span>
                ${nextBtn.label}
              </button>
            ` : ''}
            <button class="btn btn-info" onclick="showFullOrderDetails(${order.id})" style="background: #667eea; color: white; border: none;">
              <span class="material-symbols-rounded" style="font-size: 1rem; vertical-align: middle;">visibility</span>
              Show Order
            </button>
            <button class="btn btn-print" onclick="printOrder(${order.id})" style="background: #6b7280; color: white; border: none; display: flex; align-items: center; gap: 0.25rem;">
              <span class="material-symbols-rounded" style="font-size: 1rem; vertical-align: middle;">print</span>
              Print
            </button>
            ${status !== 'Cancelled' && status !== 'Completed' ? `
              <button class="btn btn-danger" onclick="updateOrderStatus(${order.id}, 'Cancelled')">Cancel Order</button>
            ` : ''}
            ${window.whatsappEnabled && window.whatsappPhone ? `
              <a href="https://wa.me/${window.whatsappPhone}?text=${encodeURIComponent('Order #' + order.order_number + '\nCustomer: ' + order.customer_name + '\nPhone: ' + order.customer_phone + '\nType: ' + order.order_type + '\nTotal: ' + formatCurrency(order.total))}" target="_blank" class="btn" style="background: #25D366; color: white; border: none; display: flex; align-items: center; gap: 4px; text-decoration: none;">
                <span style="font-weight:bold;font-size:1.1rem;">📱</span>
                WhatsApp
              </a>
            ` : ''}
          </div>
        </div>
      `;
    }).join('');
  }

  function rejectOnlineOrder(orderId, orderNumber, btn) {
    Swal.fire({
      icon: 'info',
      title: 'Rejection Reason',
      text: 'Enter reason for rejecting Order ' + orderNumber,
      input: 'textarea',
      inputPlaceholder: 'Type your reason here...',
      showCancelButton: true,
      confirmButtonText: 'Reject Order',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#d33',
      preConfirm: (reason) => {
        if (!reason || !reason.trim()) {
          Swal.showValidationMessage('Please enter a rejection reason');
        }
        return reason;
      }
    }).then((result) => {
      if (result.isConfirmed && result.value) {
        const spinner = btn && btn.querySelector('.loading-spinner');
        if (spinner) spinner.style.display = '';
        btn.disabled = true;
        updateOrderStatusWithReason(orderId, 'Rejected', result.value.trim())
          .finally(() => {
            if (spinner) spinner.style.display = 'none';
            btn.disabled = false;
          });
      }
    });
  }

  async function updateOrderStatusWithReason(orderId, status, reason) {
    try {
      const response = await fetch('../api/update_order_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `orderId=${orderId}&status=${status}&reason=${encodeURIComponent(reason)}`
      });
      const data = await response.json();
      if (data.success) {
        await loadOnlineOrders();
      } else {
        showSweetAlert('Failed: ' + (data.message || 'Unknown error'));
      }
    } catch (error) {
      console.error('Error updating order status:', error);
      showSweetAlert('Error updating order status');
    }
  }

  window.generateDeliveryQR = async function(orderId) {
    const btn = document.querySelector(`#qrSection-${orderId} button`);
    const display = document.getElementById(`qrDisplay-${orderId}`);
    if (!display) return;
    try {
      if (btn) { btn.disabled = true; btn.textContent = 'Generating...'; }
      const r = await fetch('../api/generate_delivery_qr.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `order_id=${orderId}`
      });
      const data = await r.json();
      if (data.success) {
        display.style.display = '';
        display.innerHTML = `
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(data.qr_url)}" alt="QR Code" style="border-radius:8px;">
          <p style="margin:0.5rem 0;font-size:0.85rem;color:#166534;word-break:break-all;">${data.qr_url}</p>
          <button onclick="navigator.clipboard.writeText('${data.qr_url}')" style="background:#166534;color:white;border:none;padding:4px 12px;border-radius:4px;cursor:pointer;">Copy Link</button>
        `;
      } else {
        display.style.display = '';
        display.innerHTML = `<p style="color:#dc2626;">${data.message || 'Failed to generate QR'}</p>`;
      }
    } catch(e) {
      if (display) { display.style.display = ''; display.innerHTML = '<p style="color:#dc2626;">Error generating QR</p>'; }
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = '<span class="material-symbols-rounded" style="font-size:1.1rem;">qr_code</span> Generate QR for Rider'; }
    }
  };

  // Load KOT Orders
  async function loadKOTOrders() {
    showLoading('kotOrdersList', 'Loading KOT orders...');
    try {
      const statusFilter = document.getElementById('kotStatusFilter')?.value || '';
      const tableFilter = document.getElementById('kotTableFilter')?.value || '';
      
      let url = '../api/get_kot.php';
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
        const pendingKotCount = (data.kots || []).filter(k => (k.kot_status || k.status || 'Pending') === 'Pending').length;
        if (prevKotOrderCount > 0 && pendingKotCount > prevKotOrderCount && window.kotOrdersSoundEnabled) {
          playClickSound();
        }
        prevKotOrderCount = pendingKotCount;
        displayKOTOrders(data.kots);
      } else {
        const kotList = document.getElementById('kotList');
        if (kotList) {
          kotList.innerHTML = '<div class="error">Failed to load KOT orders</div>';
        }
      }
    } catch (error) {
      console.error('Error loading KOT orders:', error);
      const kotList = document.getElementById('kotList');
      if (kotList) {
        kotList.innerHTML = '<div class="error">Error loading KOT orders</div>';
      }
    }
  }
  
  // Make loadKOTOrders globally accessible
  window.loadKOTOrders = loadKOTOrders;

// Display KOT Orders
function displayKOTOrders(kots) {
  const kotList = document.getElementById('kotList');
  
  if (!kots || kots.length === 0) {
    kotList.innerHTML = '<div class="empty-state">No KOT orders found</div>';
    return;
  }
  
  var _kotSerialCounter = 0;
  kotList.innerHTML = kots.map(kot => {
    _kotSerialCounter++;
    const kotStatus = kot.kot_status || kot.status || 'Pending';
    const statusClass = kotStatus.toLowerCase().replace(' ', '-');
    
    // Extract payment method from KOT notes
    var kotPaymentMethod = 'Cash';
    if (kot.notes) {
      var pmMatch = kot.notes.match(/\[Payment:\s*([^\]]+)\]/);
      if (pmMatch) {
        kotPaymentMethod = pmMatch[1].trim();
      }
    }
    
    return `
    <div class="kot-order-card" style="border-left: 4px solid ${kotStatus === 'Pending' ? '#f59e0b' : kotStatus === 'Preparing' ? '#3b82f6' : kotStatus === 'Ready' ? '#10b981' : '#6b7280'};">
      <div class="kot-header" style="display:flex;flex-direction:column;gap:8px;">
        <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;">
          <span class="kot-status ${statusClass}" style="padding: 6px 12px; border-radius: 20px; font-weight: 600; font-size: 0.875rem; white-space:nowrap; background: ${kotStatus === 'Pending' ? '#fef3c7' : kotStatus === 'Preparing' ? '#dbeafe' : kotStatus === 'Ready' ? '#d1fae5' : '#f3f4f6'}; color: ${kotStatus === 'Pending' ? '#92400e' : kotStatus === 'Preparing' ? '#1e40af' : kotStatus === 'Ready' ? '#065f46' : '#6b7280'};">
            ${kotStatus === 'Preparing' ? 'IN KITCHEN' : kotStatus === 'Pending' ? 'PENDING' : kotStatus}
          </span>
          <p style="margin: 0; color: #6b7280; font-size: 0.8rem; white-space:nowrap;">${new Date(kot.created_at).toLocaleString()}</p>
        </div>
        <div class="kot-title">
          <div style="display:flex;align-items:center;gap:8px;min-width:0;margin-bottom:6px;">
            <span style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:#dc2626;color:#fff;font-weight:800;font-size:0.8rem;flex-shrink:0;">${_kotSerialCounter}</span>
            <h3 style="color: #dc2626; margin: 0; font-size: 1.05rem; min-width:0; white-space: nowrap; overflow:hidden; text-overflow:ellipsis;">KOT #${kot.kot_number || kot.id}</h3>
          </div>
          <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:12px;font-size:0.75rem;font-weight:600;background:${kotPaymentMethod === 'Cash' ? '#d1fae5' : '#dbeafe'};color:${kotPaymentMethod === 'Cash' ? '#065f46' : '#1e40af'};white-space:nowrap;max-width:100%;overflow:hidden;text-overflow:ellipsis;">
            <span class="material-symbols-rounded" style="font-size:14px;flex-shrink:0;">${kotPaymentMethod === 'Cash' ? 'payments' : 'phone_android'}</span>
            ${kotPaymentMethod}
          </span>
          <p style="margin: 5px 0; color: #6b7280; font-size: 0.9rem;">${kot.item_count || (kot.items?.length || 0)} Item(s)</p>
          <p style="margin: 5px 0; color: #6b7280; font-size: 0.85rem;">Table: ${kot.table_number || 'Takeaway'} | ${kot.area_name || ''}</p>
          ${kot.customer_name && kot.customer_name !== 'Table Customer' && kot.customer_name !== 'Takeaway' ? `
            <div style="margin-top: 8px; padding: 8px; background: #f3f4f6; border-radius: 6px;">
              <p style="margin: 2px 0; color: #1f2937; font-size: 0.85rem; font-weight: 600;">Customer: ${escapeHtml(kot.customer_name)}</p>
              ${kot.customer_phone ? `<p style="margin: 2px 0; color: #6b7280; font-size: 0.8rem;">📞 ${kot.customer_phone}</p>` : ''}
              ${kot.customer_email ? `<p style="margin: 2px 0; color: #6b7280; font-size: 0.8rem;">✉️ ${escapeHtml(kot.customer_email)}</p>` : ''}
              ${kot.customer_address ? `<p style="margin: 2px 0; color: #6b7280; font-size: 0.8rem;">📍 ${escapeHtml(kot.customer_address)}</p>` : ''}
            </div>
          ` : ''}
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
  showLoading('ordersList', 'Loading orders...');
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
    
    const response = await fetch(`../api/get_orders.php?${params.toString()}`, { cache: 'no-store' });
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
    showEmptyState('ordersList', 'receipt_long', 'No orders found', 'Orders will appear here once customers start ordering. Try adjusting your filters.', '<button class="empty-action-btn" onclick="resetAllOrdersFilters();"><span class="material-symbols-rounded" style="font-size:16px;">refresh</span> Reset Filters</button>');
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
        <button class="btn btn-print" onclick="printOrder(${order.id})" style="background: #6b7280; color: white; border: none; display: flex; align-items: center; gap: 0.25rem;">
          <span class="material-symbols-rounded" style="font-size: 1rem; vertical-align: middle;">print</span>
          Print
        </button>
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
    const response = await fetch('../api/get_tables.php');
    const data = await response.json();
    
    if (data.success) {
      const tables = data.tables || data.data || [];
      const tableFilter = document.getElementById('kotTableFilter');
      tableFilter.innerHTML = '<option value="">All Tables</option>' + 
        tables.map(table => `<option value="${table.id}">${table.table_name || table.table_number}</option>`).join('');
    }
  } catch (error) {
    console.error('Error loading tables for KOT:', error);
  }
}

// Load tables for Orders filter
async function loadTablesForOrders() {
  try {
    const response = await fetch('../api/get_tables.php');
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
    const response = await fetch('../controllers/kot_operations.php', {
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
    const response = await fetch('../controllers/kot_operations.php', {
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
      if (typeof window.renderTableMap === 'function' && document.getElementById('tableMapContainer')) {
        setTimeout(renderTableMap, 500);
      }
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
  if (await showSweetConfirm("Are you sure you want to cancel this KOT?", 'Cancel KOT')) {
    try {
      const response = await fetch('../controllers/kot_operations.php', {
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
window.printKOT = async function(kotId, onClose) {
  try {
    // Fetch KOT data for specific KOT
    const res = await fetch('../api/get_kot.php' + (kotId ? '?kot_id=' + kotId : ''));
    const data = await res.json();
    if (!data.success) { showSweetAlert('Unable to load KOT'); return; }
    const kot = data.kot || (data.kots || []).find(k => String(k.id) === String(kotId));
    if (!kot) { showSweetAlert('KOT not found'); return; }

    // Fetch restaurant info
    let restaurantInfo = {
      name: 'Restaurant Name',
      logo: '',
      address: '',
      phone: '',
      email: ''
    };
    
    try {
      const infoRes = await fetch('../admin/get_session.php');
      const infoData = await infoRes.json();
      if (infoData.success && infoData.data) {
        restaurantInfo.name = infoData.data.restaurant_name || restaurantInfo.name;
        restaurantInfo.logo = infoData.data.restaurant_logo || '';
        restaurantInfo.user_id = infoData.data.user_id || infoData.data.id || '';
        restaurantInfo.address = infoData.data.address || '';
        restaurantInfo.phone = infoData.data.phone || '';
        restaurantInfo.email = infoData.data.email || '';
      }
    } catch (e) {
      console.warn('Could not load restaurant info:', e);
    }

    // Build printable HTML with professional KOT design
    const now = new Date(kot.created_at);
    const dateStr = now.toLocaleDateString('en-IN', { day: '2-digit', month: '2-digit', year: 'numeric' });
    const timeStr = now.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });
    
    // Items with quantity and any special instructions
    const itemsHtml = (kot.items || []).map((it, idx) => `
      <tr>
        <td style="padding: 6px 0; border-bottom: 1px dashed #d1d5db; font-size: 13px;">
          <table style="width:100%;"><tr>
            <td style="width:40px; text-align:center; font-weight:700; font-size:14px;">${it.quantity}</td>
            <td style="padding-left:6px;">
              <div style="font-weight:600; font-size:14px; color:#111827;">${escapeHtml(it.item_name || it.name)}</div>
              ${(it.notes || it.special_instructions) ? `<div style="color:#b45309; font-size:11px; font-style:italic; margin-top:2px;">* ${escapeHtml(it.notes || it.special_instructions)}</div>` : ''}
            </td>
          </tr></table>
        </td>
      </tr>
    `).join('');
    
    const tableOrType = kot.table_number ? `Table ${kot.table_number}${kot.area_name ? ' - ' + escapeHtml(kot.area_name) : ''}` : (kot.order_type || 'Takeaway');
    
    // Logo HTML
    let logoHtml = '';
    if (restaurantInfo.logo) {
      let logoPath;
      if (restaurantInfo.logo.startsWith('db:')) {
        const userId = restaurantInfo.user_id || restaurantInfo.id || '';
        logoPath = `../api/image.php?type=logo&id=${userId}`;
      } else if (restaurantInfo.logo.startsWith('http')) {
        logoPath = restaurantInfo.logo;
      } else if (restaurantInfo.logo.startsWith('uploads/')) {
        logoPath = '../' + restaurantInfo.logo;
      } else {
        logoPath = '../uploads/' + restaurantInfo.logo;
      }
      logoHtml = `<img src="${logoPath}" alt="Logo" style="max-width:70px; max-height:70px; object-fit:contain; margin-bottom:6px;" onerror="this.style.display='none';">`;
    }

    const itemsCount = (kot.items || []).length;

    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>KOT #${kot.kot_number}</title>
      <style>
        @media print { @page { margin: 8mm; size: 80mm auto; } body { margin:0; padding:0; } }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Courier New', Courier, monospace; padding:12px; max-width:280px; margin:0 auto; background:#fff; color:#111827; }
        .header { text-align:center; border-bottom:3px double #dc2626; padding-bottom:10px; margin-bottom:12px; }
        .logo { margin-bottom:6px; }
        .restaurant-name { font-size:18px; font-weight:800; color:#dc2626; text-transform:uppercase; letter-spacing:0.5px; font-family:'Segoe UI',Tahoma,sans-serif; }
        .restaurant-details { font-size:10px; color:#6b7280; line-height:1.4; margin-top:4px; font-family:'Segoe UI',Tahoma,sans-serif; }
        .kot-banner { text-align:center; margin:12px 0; padding:10px; background:#fef2f2; border:2px solid #dc2626; }
        .kot-banner h2 { font-size:28px; font-weight:900; color:#dc2626; letter-spacing:3px; margin-bottom:2px; font-family:'Segoe UI',Tahoma,sans-serif; }
        .kot-banner .kot-number { font-size:22px; font-weight:700; color:#111827; }
        .kot-banner .kot-ser { font-size:10px; font-weight:600; color:#dc2626; margin-bottom:2px; }
        .info-box { background:#f9fafb; padding:10px; margin-bottom:12px; border-left:4px solid #dc2626; font-size:12px; }
        .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:4px 12px; }
        .info-item { display:flex; justify-content:space-between; }
        .info-label { color:#6b7280; font-weight:600; }
        .info-value { color:#111827; font-weight:700; text-align:right; }
        .items-section { margin:12px 0; }
        .items-header { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:6px 0; border-bottom:2px solid #111827; margin-bottom:4px; display:flex; justify-content:space-between; }
        table { width:100%; border-collapse:collapse; }
        .divider { border:none; border-top:2px dashed #d1d5db; margin:10px 0; }
        .footer { text-align:center; margin-top:14px; padding-top:10px; border-top:2px dashed #e5e7eb; }
        .footer-text { font-size:10px; color:#6b7280; margin-bottom:2px; }
        .total-items { font-size:12px; font-weight:700; color:#111827; margin-top:6px; }
        .sep-line { border:none; border-top:1px dashed #d1d5db; margin:6px 0; }
      </style>
    </head><body>
      <div class="header">
        ${logoHtml}
        <div class="restaurant-name">${escapeHtml(restaurantInfo.name)}</div>
        ${restaurantInfo.address ? `<div class="restaurant-details">${escapeHtml(restaurantInfo.address)}</div>` : ''}
        ${restaurantInfo.phone ? `<div class="restaurant-details">Ph: ${escapeHtml(restaurantInfo.phone)}</div>` : ''}
      </div>
        <h2>KOT</h2>
        <div class="kot-number">#${escapeHtml(kot.kot_number || kot.id)}</div>
        <hr class="sep-line">
        <div style="font-size:11px; color:#6b7280;">${dateStr} | ${timeStr}</div>
      </div>
      
      <div class="info-box">
        <div class="info-grid">
          <div class="info-item"><span class="info-label">TABLE</span><span class="info-value">${escapeHtml(tableOrType)}</span></div>
          <div class="info-item"><span class="info-label">STATUS</span><span class="info-value">${escapeHtml(kot.kot_status || kot.status || 'Pending')}</span></div>
          ${kot.order_number ? `<div class="info-item" style="grid-column:1/-1;"><span class="info-label">ORDER #</span><span class="info-value">${escapeHtml(kot.order_number)}</span></div>` : ''}
        </div>
      </div>
      
      <div class="items-section">
        <div class="items-header">
          <span>QTY</span>
          <span>ITEM</span>
        </div>
        <table>${itemsHtml}</table>
      </div>
      
      <hr class="divider">
      
      <div class="footer">
        <div style="font-size:11px; font-weight:600; margin-bottom:4px;">Total Items: ${itemsCount}</div>
        <div class="footer-text">Thank you!</div>
        <div class="footer-text">Powered by Restro Grow</div>
      </div>
    </body></html>`;

    showPrintPreview('KOT #' + kot.kot_number, html, onClose);
  } catch (e) {
    console.error('Print error', e);
    showSweetAlert('Failed to print KOT');
  }
};


  // --- Print Preview Helper ---
  // Shows an on-screen preview of the printable content before actually printing
  window.showPrintPreview = function(title, htmlContent, onClose) {
    var existing = document.getElementById('printPreviewOverlay');
    if (existing) existing.remove();

    var closed = false;
    function doClose() {
      if (closed) return;
      closed = true;
      overlay.remove();
      if (typeof onClose === 'function') onClose();
    }

    var overlay = document.createElement('div');
    overlay.id = 'printPreviewOverlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.65);z-index:99999;display:flex;align-items:center;justify-content:center;animation:fadeIn 0.2s;';
    overlay.onclick = function(e) { if (e.target === overlay) doClose(); };

    var modal = document.createElement('div');
    modal.style.cssText = 'background:#fff;border-radius:16px;width:92%;max-width:640px;height:100vh;max-height:100vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 25px 80px rgba(0,0,0,0.35);';

    // Header
    var header = document.createElement('div');
    header.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb;background:#f9fafb;';
    var titleEl = document.createElement('h3');
    titleEl.textContent = title || 'Print Preview';
    titleEl.style.cssText = 'margin:0;font-size:16px;font-weight:700;color:#111827;';
    var closeBtn = document.createElement('button');
    closeBtn.innerHTML = '✕';
    closeBtn.style.cssText = 'width:32px;height:32px;border:none;border-radius:8px;background:#e5e7eb;cursor:pointer;font-size:16px;display:grid;place-items:center;color:#6b7280;transition:background 0.15s;';
    closeBtn.onmouseover = function() { closeBtn.style.background = '#d1d5db'; };
    closeBtn.onmouseout = function() { closeBtn.style.background = '#e5e7eb'; };
    closeBtn.onclick = function() { doClose(); };
    header.appendChild(titleEl);
    header.appendChild(closeBtn);

    // Content area with iframe
    var content = document.createElement('div');
    content.style.cssText = 'flex:1;overflow:auto;padding:20px;background:#f3f4f6;display:flex;align-items:flex-start;justify-content:center;';

    var iframe = document.createElement('iframe');
    iframe.style.cssText = 'width:100%;height:100%;border:none;border-radius:8px;background:#fff;display:block;';
    content.appendChild(iframe);

    // Footer with buttons
    var footer = document.createElement('div');
    footer.style.cssText = 'display:flex;gap:10px;justify-content:flex-end;padding:14px 20px;border-top:1px solid #e5e7eb;background:#f9fafb;';

    var cancelBtn2 = document.createElement('button');
    cancelBtn2.textContent = 'Cancel';
    cancelBtn2.style.cssText = 'padding:10px 20px;border:1.5px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;font-weight:600;font-size:14px;color:#374151;transition:all 0.15s;';
    cancelBtn2.onmouseover = function() { cancelBtn2.style.background = '#f3f4f6'; };
    cancelBtn2.onmouseout = function() { cancelBtn2.style.background = '#fff'; };
    cancelBtn2.onclick = function() { doClose(); };

    var printBtn = document.createElement('button');
    printBtn.innerHTML = '🖐 Print';
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
      doClose();
    };

    footer.appendChild(cancelBtn2);
    footer.appendChild(printBtn);
    
    modal.appendChild(header);
    modal.appendChild(content);
    modal.appendChild(footer);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    
    // Write HTML to iframe and inject preview-friendly CSS
    var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
    iframeDoc.open();
    iframeDoc.write(htmlContent);
    iframeDoc.close();
    
    // Fit receipt into preview at optimum zoom (no scrolling needed)
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
      } catch(e) { /* ignore if iframe not ready */ }
    }, 200);
  };
  
// Print Order
window.printOrder = async function(orderId) {
  try {
    // Fetch order details
    const response = await fetch(`../api/get_order_details_by_id.php?id=${orderId}`);
    const data = await response.json();
    
    if (!data.success || !data.order) {
      showSweetAlert('Unable to load order details');
      return;
    }
    
    const order = data.order;
    const items = Array.isArray(order.items) ? order.items : [];

    // Fetch restaurant info with GST settings
    let restaurantInfo = {
      name: 'Restaurant Name',
      logo: '',
      address: '',
      phone: '',
      email: '',
      business_qr_code_path: '',
      enable_gst: true
    };
    
    try {
      const infoRes = await fetch('../admin/get_session.php');
      const infoData = await infoRes.json();
      
      if (checkSessionExpired(infoRes, infoData)) return;
      
      if (infoData.success && infoData.data) {
        restaurantInfo.name = infoData.data.restaurant_name || restaurantInfo.name;
        restaurantInfo.logo = infoData.data.restaurant_logo || '';
        restaurantInfo.user_id = infoData.data.user_id || infoData.data.id || '';
        restaurantInfo.address = infoData.data.address || '';
        restaurantInfo.phone = infoData.data.phone || '';
        restaurantInfo.email = infoData.data.email || '';
        restaurantInfo.business_qr_code_path = infoData.data.business_qr_code_path || '';
        restaurantInfo.enable_gst = infoData.data.enable_gst !== undefined ? Boolean(Number(infoData.data.enable_gst)) : true;
      }
    } catch (e) {
      console.warn('Could not load restaurant info:', e);
    }

    const gstEnabled = restaurantInfo.enable_gst;

    // Build printable HTML with professional GST invoice design
    const now = new Date(order.created_at);
    const dateStr = now.toLocaleDateString('en-IN', { day: '2-digit', month: '2-digit', year: 'numeric' });
    const timeStr = now.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });
    
    const subtotal = parseFloat(order.subtotal || 0);
    const tax = parseFloat(order.tax || 0);
    const discount = parseFloat(order.discount || 0);
    const total = parseFloat(order.total || 0);
    
    // Calculate CGST / SGST (5% GST = 2.5% + 2.5%)
    const cgst = gstEnabled ? (tax / 2) : 0;
    const sgst = gstEnabled ? (tax / 2) : 0;
    
    // Professional items table: # | Item | Qty | Rate | Amount
    const itemsHtml = items.map((it, idx) => {
      const itemTotal = (parseFloat(it.total_price) || 0);
      const itemRate = (parseFloat(it.price) || 0);
      const qty = it.quantity || 1;
      return `
        <tr>
          <td style="text-align:center; padding:4px 2px; font-size:11px; border-bottom:1px dashed #d1d5db; color:#6b7280; width:18px;">${idx + 1}</td>
          <td style="padding:4px 2px; font-size:12px; border-bottom:1px dashed #d1d5db;">
            <div style="font-weight:600; color:#111827;">${escapeHtml(it.item_name || it.name)}</div>
            ${(it.notes || it.special_instructions) ? `<div style="color:#b45309; font-size:10px; font-style:italic;">${escapeHtml(it.notes || it.special_instructions)}</div>` : ''}
          </td>
          <td style="text-align:center; padding:4px 2px; font-size:11px; border-bottom:1px dashed #d1d5db; width:22px;">${qty}</td>
          <td style="text-align:right; padding:4px 2px; font-size:11px; border-bottom:1px dashed #d1d5db; width:55px;">${formatCurrency(itemRate)}</td>
          <td style="text-align:right; padding:4px 2px; font-weight:600; font-size:12px; border-bottom:1px dashed #d1d5db; width:60px;">${formatCurrency(itemTotal)}</td>
        </tr>
      `;
    }).join('');
    
    const tableOrType = order.table_name || order.table_number ? `Table ${order.table_name || order.table_number}${order.area_name ? ' - ' + escapeHtml(order.area_name) : ''}` : (order.order_type || 'Walk-in');
    
    // Logo HTML
    let logoHtml = '';
    if (restaurantInfo.logo) {
      let logoPath;
      if (restaurantInfo.logo.startsWith('db:')) {
        const userId = restaurantInfo.user_id || restaurantInfo.id || '';
        logoPath = `../api/image.php?type=logo&id=${userId}`;
      } else if (restaurantInfo.logo.startsWith('http')) {
        logoPath = restaurantInfo.logo;
      } else if (restaurantInfo.logo.startsWith('uploads/')) {
        logoPath = '../' + restaurantInfo.logo;
      } else {
        logoPath = '../uploads/' + restaurantInfo.logo;
      }
      logoHtml = `<img src="${logoPath}" alt="Logo" style="max-width:70px; max-height:70px; object-fit:contain; margin-bottom:6px;" onerror="this.style.display='none';">`;
    }
    
    // Payment method display
    const paymentMethod = order.payment_method || 'Cash';
    const paymentStatus = order.payment_status || 'Pending';
    
    // Business QR Code HTML - only show if QR code exists
    let businessQRHtml = '';
    if (restaurantInfo.business_qr_code_path) {
      const userId = restaurantInfo.user_id || restaurantInfo.id || '';
      const qrCodeUrl = `../api/image.php?type=business_qr&id=${userId}`;
      businessQRHtml = `
        <div style="margin-top:12px; padding-top:12px; border-top:1px dashed #d1d5db; text-align:center;">
          <div style="font-size:9px; color:#6b7280; margin-bottom:6px; font-weight:700; text-transform:uppercase; letter-spacing:1px;">Scan to Pay</div>
          <img src="${qrCodeUrl}" alt="Payment QR" style="max-width:100px; max-height:100px; object-fit:contain; border:1px solid #e5e7eb; padding:4px; background:#fff;" onerror="this.style.display='none';">
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
        .divider { border:none; border-top:2px dashed #9ca3af; margin:8px 0; }
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
  if (await showSweetConfirm("Are you sure you want to cancel this order?", 'Cancel Order')) {
    updateKOTStatus(orderId, 'Cancelled');
  }
};

// Update Order Status
async function updateOrderStatus(orderId, status, btn) {
  // Show confirmation for destructive status changes (Cancelled)
  if (status === 'Cancelled') {
    const confirmed = await showSweetConfirm(
      'Are you sure you want to cancel this order? This action cannot be undone.',
      'Cancel Order'
    );
    if (!confirmed) return;
  }
  const spinner = btn && btn.querySelector('.loading-spinner');
  if (spinner) spinner.style.display = '';
  if (btn) btn.disabled = true;
  try {
    const response = await fetch('../api/update_order_status.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: `orderId=${orderId}&status=${status}`
    });
    
    const data = await response.json();
    
    if (data.success) {
      await loadOrders(); // Reload orders
    } else {
      showSweetAlert('Failed to update order status');
    }
  } catch (error) {
    console.error('Error updating order status:', error);
    showSweetAlert('Error updating order status');
  } finally {
    if (spinner) spinner.style.display = 'none';
    if (btn) btn.disabled = false;
  }
}

// (Optional code): Adjust sidebar height on window resize
window.addEventListener("resize", () => {
  if (!sidebar) return;
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
  const rows = document.querySelectorAll('#customerList tr[data-customer-id]');
  const container = document.getElementById('customerList');
  
  if (!container) return;
  
  rows.forEach(row => {
    const cells = row.querySelectorAll('td');
    if (cells.length < 6) return;
    
    const name = (cells[1]?.textContent || '').toLowerCase();
    const phone = (cells[2]?.textContent || '').toLowerCase();
    const email = (cells[3]?.textContent || '').toLowerCase();
    
    if (!searchTerm || name.includes(searchTerm) || phone.includes(searchTerm) || email.includes(searchTerm)) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });
  
  // Sort visible rows
  const visibleRows = Array.from(rows).filter(row => row.style.display !== 'none');
  
  visibleRows.sort((a, b) => {
    const cellsA = a.querySelectorAll('td');
    const cellsB = b.querySelectorAll('td');
    
    if (sortBy === 'name') {
      const nameA = (cellsA[1]?.textContent || '').toLowerCase();
      const nameB = (cellsB[1]?.textContent || '').toLowerCase();
      return nameA.localeCompare(nameB);
    } else if (sortBy === 'visits') {
      const visitsA = parseInt(cellsA[4]?.textContent) || 0;
      const visitsB = parseInt(cellsB[4]?.textContent) || 0;
      return visitsB - visitsA;
    } else if (sortBy === 'recent') {
      // For recent, we'd need to store the date in data attribute or parse from display
      // For now, just sort by name
      const nameA = (cellsA[1]?.textContent || '').toLowerCase();
      const nameB = (cellsB[1]?.textContent || '').toLowerCase();
      return nameA.localeCompare(nameB);
    }
    return 0;
  });
  
  // Reorder rows in DOM
  visibleRows.forEach(row => container.appendChild(row));
}

// Filter staff
function filterStaff() {
  const searchTerm = document.getElementById('staffSearch')?.value.toLowerCase() || '';
  const sortBy = document.getElementById('staffSortBy')?.value || 'name';
  const rows = document.querySelectorAll('#staffList tr[data-staff-id]');
  const container = document.getElementById('staffList');
  
  if (!container) return;
  
  rows.forEach(row => {
    const cells = row.querySelectorAll('td');
    if (cells.length < 6) return;
    
    const name = (cells[1]?.textContent || '').toLowerCase();
    const email = (cells[2]?.textContent || '').toLowerCase();
    const phone = (cells[3]?.textContent || '').toLowerCase();
    const role = (cells[4]?.textContent || '').toLowerCase();
    
    if (!searchTerm || name.includes(searchTerm) || email.includes(searchTerm) || phone.includes(searchTerm) || role.includes(searchTerm)) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });
  
  // Sort visible rows
  const visibleRows = Array.from(rows).filter(row => row.style.display !== 'none');
  
  visibleRows.sort((a, b) => {
    const cellsA = a.querySelectorAll('td');
    const cellsB = b.querySelectorAll('td');
    
    if (sortBy === 'name') {
      const nameA = (cellsA[1]?.textContent || '').toLowerCase();
      const nameB = (cellsB[1]?.textContent || '').toLowerCase();
      return nameA.localeCompare(nameB);
    } else if (sortBy === 'role') {
      const roleA = (cellsA[4]?.textContent || '').toLowerCase();
      const roleB = (cellsB[4]?.textContent || '').toLowerCase();
      return roleA.localeCompare(roleB);
    }
    return 0;
  });
  
  // Reorder rows in DOM
  visibleRows.forEach(row => container.appendChild(row));
}
// Load Profile Data
async function loadProfileData() {
  try {
    const response = await fetch('../admin/get_session.php');
    const result = await response.json();
    
    if (checkSessionExpired(response, result)) return;
    
    if (result.success) {
      const user = result.data;
      const username = user.username || 'User';
      const initials = username.split(' ').map(w => w.charAt(0).toUpperCase()).join('').substring(0, 2);
      const formatReadableDate = (dateStr) => {
        if (!dateStr) return '--';
        const date = new Date(dateStr);
        if (Number.isNaN(date.getTime())) return '--';
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
      };
      
      // Basic profile elements
      const profileInitialsEl = document.getElementById('profileInitials');
      const profileNameEl = document.getElementById('profileName');
      const profileRoleEl = document.getElementById('profileRole');
      const profileEmailEl = document.getElementById('profileEmail');
      const profileEmailValueEl = document.getElementById('profileEmailValue');
      
      if (profileInitialsEl) profileInitialsEl.textContent = initials;
      if (profileNameEl) profileNameEl.textContent = username;
      if (profileRoleEl) profileRoleEl.textContent = user.role || 'Administrator';
      if (profileEmailEl) profileEmailEl.textContent = user.email || 'No email';
      if (profileEmailValueEl) profileEmailValueEl.textContent = user.email || 'Not added';
      
      // Restaurant logo in profile
      const profileRestaurantLogoEl = document.getElementById('profileRestaurantLogo');
      if (profileRestaurantLogoEl && user.restaurant_logo) {
        let logoPath;
        const timestamp = Date.now(); // Cache-busting timestamp
        if (user.restaurant_logo.startsWith('db:')) {
          // Database-stored image - add cache-busting
          logoPath = `../api/image.php?type=logo&id=${user.id || user.user_id || ''}&t=${timestamp}`;
        } else if (user.restaurant_logo.startsWith('http')) {
          // External URL
          logoPath = user.restaurant_logo + (user.restaurant_logo.includes('?') ? '&' : '?') + `t=${timestamp}`;
        } else if (user.restaurant_logo.startsWith('uploads/')) {
          // File-based image
          logoPath = `../${user.restaurant_logo}?t=${timestamp}`;
        } else if (!user.restaurant_logo.startsWith('../')) {
          // Relative path
          logoPath = `../uploads/${user.restaurant_logo}?t=${timestamp}`;
        } else {
          logoPath = `${user.restaurant_logo}?t=${timestamp}`;
        }
        profileRestaurantLogoEl.src = logoPath;
        profileRestaurantLogoEl.style.display = 'block';
        if (profileInitialsEl) profileInitialsEl.style.display = 'none';
      }
      
      // Restaurant name and member since date
      const profileRestaurantNameEl = document.getElementById('profileRestaurantName');
      const profileMemberSinceDateEl = document.getElementById('profileMemberSinceDate');
      const profileMemberSinceHighlightEl = document.getElementById('profileMemberSinceHighlight');
      const profileRestaurantIdHighlightEl = document.getElementById('profileRestaurantIdHighlight');
      
      if (profileRestaurantNameEl) {
        profileRestaurantNameEl.textContent = user.restaurant_id || 'N/A';
      }
      if (profileRestaurantIdHighlightEl) {
        profileRestaurantIdHighlightEl.textContent = user.restaurant_id || 'N/A';
      }
      
      if (profileMemberSinceDateEl && user.created_at) {
        const joinDate = formatReadableDate(user.created_at);
        profileMemberSinceDateEl.textContent = joinDate;
        if (profileMemberSinceHighlightEl) {
          profileMemberSinceHighlightEl.textContent = joinDate;
        }
      }
      
      // Optional info elements (may not exist in all dashboard versions)
      const infoUsernameEl = document.getElementById('infoUsername');
      const infoEmailEl = document.getElementById('infoEmail');
      const infoRoleEl = document.getElementById('infoRole');
      const infoMemberSinceEl = document.getElementById('infoMemberSince');
      const profilePhoneInlineEl = document.getElementById('profilePhoneValueInline');
      const profilePhoneValueEl = document.getElementById('profilePhoneValue');
      const profileAddressValueEl = document.getElementById('profileAddressValue');
      const profileTimezoneTextEl = document.getElementById('profileTimezoneText');
      const profileTimezoneTextInlineEl = document.getElementById('profileTimezoneTextInline');
      const profileSubscriptionStatusTextEl = document.getElementById('profileSubscriptionStatusText');
      const profileSubscriptionStatusBadgeEl = document.getElementById('profileSubscriptionStatusBadge');
      const profileRenewalDateTextEl = document.getElementById('profileRenewalDateText');
      const profileTrialEndTextEl = document.getElementById('profileTrialEndText');
      
      if (infoUsernameEl) infoUsernameEl.textContent = username;
      if (infoEmailEl) infoEmailEl.textContent = user.email || 'N/A';
      if (infoRoleEl) infoRoleEl.textContent = user.role || 'N/A';
      if (infoMemberSinceEl && user.created_at) {
        const joinDate = new Date(user.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long' });
        infoMemberSinceEl.textContent = joinDate;
      }

      const phoneValue = user.phone || 'Add a phone number';
      if (profilePhoneInlineEl) profilePhoneInlineEl.textContent = phoneValue;
      if (profilePhoneValueEl) profilePhoneValueEl.textContent = phoneValue;
      if (profileAddressValueEl) profileAddressValueEl.textContent = user.address || 'Add your restaurant address';

      const timezoneValue = user.timezone || 'Asia/Kolkata';
      if (profileTimezoneTextEl) profileTimezoneTextEl.textContent = timezoneValue;
      if (profileTimezoneTextInlineEl) profileTimezoneTextInlineEl.textContent = timezoneValue;

      const subscriptionRaw = (user.subscription_status || 'Active').toString().replace(/_/g, ' ');
      const subscriptionFormatted = subscriptionRaw.replace(/\b\w/g, (char) => char.toUpperCase());
      if (profileSubscriptionStatusTextEl) profileSubscriptionStatusTextEl.textContent = subscriptionFormatted;
      if (profileSubscriptionStatusBadgeEl) {
        profileSubscriptionStatusBadgeEl.textContent = subscriptionFormatted;
        const normalized = subscriptionFormatted.toLowerCase();
        let statusClass = 'active';
        if (normalized.includes('trial')) statusClass = 'trial';
        if (normalized.includes('expired') || normalized.includes('cancel')) statusClass = 'expired';
        profileSubscriptionStatusBadgeEl.className = `profile-status-pill ${statusClass}`;
      }

      if (profileRenewalDateTextEl) {
        profileRenewalDateTextEl.textContent = user.renewal_date ? formatReadableDate(user.renewal_date) : '--';
      }
      if (profileTrialEndTextEl) {
        profileTrialEndTextEl.textContent = user.trial_end_date ? formatReadableDate(user.trial_end_date) : '--';
      }
    }
  } catch (error) {
    console.error('Error loading profile data:', error);
  }
}

let paymentMethodsCache = [];
let paymentMethodsLoading = false;

function getCachedPaymentMethod(id) {
  return paymentMethodsCache.find(method => Number(method.id) === Number(id));
}

async function loadPaymentMethods(force = false) {
  const listEl = document.getElementById('paymentMethodsList');
  const filterEl = document.getElementById('paymentMethodFilter');

  if (paymentMethodsLoading && !force) {
    return;
  }

  if (listEl && (!paymentMethodsCache.length || force)) {
    listEl.innerHTML = '<div style="text-align: center; padding: 2rem; color: #6b7280;">Loading payment methods...</div>';
  }

  paymentMethodsLoading = true;

  try {
    const response = await fetch(`../api/get_payment_methods.php?ts=${Date.now()}`);
    const data = await response.json();

    if (!data.success) {
      throw new Error(data.message || 'Failed to load payment methods');
    }

    paymentMethodsCache = Array.isArray(data.data) ? data.data : [];
    renderPaymentMethods();
    updatePaymentMethodFilterOptions(filterEl);
  } catch (error) {
    console.error('Error loading payment methods:', error);
    if (listEl) {
      listEl.innerHTML = `<div style="text-align: center; padding: 2rem; color: #ef4444;">${escapeHtml(error.message || 'Unable to load payment methods')}</div>`;
    }
  } finally {
    paymentMethodsLoading = false;
  }
}

function renderPaymentMethods() {
  const listEl = document.getElementById('paymentMethodsList');
  if (!listEl) return;

  if (!paymentMethodsCache.length) {
    listEl.innerHTML = '<div style="text-align: center; padding: 2rem; color: #6b7280;"><span class="material-symbols-rounded" style="font-size: 3rem; display: block; margin-bottom: 1rem; opacity: 0.5;">payment</span><p>No payment methods found. Click "Add Method" to create one.</p></div>';
    return;
  }

  listEl.innerHTML = paymentMethodsCache.map(method => {
    const methodName = (method.method_name || 'Unnamed Method').trim();
    const emoji = (method.emoji || '💳').trim();
    const displayOrder = method.display_order ?? 0;
    const isActive = method.is_active == 1 || method.is_active === true;
    const qrUrl = method.qr_code_url || '';
    
    return `
    <div class="payment-method-card" style="border: 1px solid #e5e7eb; border-radius: 12px; padding: 1rem; background: white; display: flex; flex-direction: column; gap: 0.75rem; position: relative; box-shadow: 0 5px 15px rgba(15,23,42,0.08);">
      <div style="display: flex; align-items: center; gap: 0.75rem;">
        <div style="font-size: 2rem;">${emoji}</div>
        <div style="flex: 1;">
          <div style="font-weight: 700; font-size: 1rem;">${escapeHtml(methodName)}</div>
          <div style="font-size: 0.85rem; color: #6b7280;">Display order: ${displayOrder}</div>
        </div>
      </div>
      ${qrUrl ? `
        <div style="margin: 0.5rem 0; padding: 0.5rem; background: #f3f4f6; border-radius: 8px; text-align: center;">
          <img src="${qrUrl}" alt="QR Code" style="max-width: 150px; max-height: 150px; border-radius: 4px;" onerror="this.style.display='none';" />
          <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">Payment QR Code</div>
        </div>
      ` : ''}
      <div style="display: flex; align-items: center; justify-content: space-between;">
        <span style="padding: 0.25rem 0.75rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; background: ${isActive ? '#d1fae5' : '#fee2e2'}; color: ${isActive ? '#065f46' : '#b91c1c'};">
          ${isActive ? 'Active' : 'Inactive'}
        </span>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
          <button onclick="editPaymentMethodEntry(${method.id})" style="padding: 0.4rem 0.75rem; border-radius: 8px; border: none; background: #e5e7eb; cursor: pointer; font-weight: 600; color: #111827; font-size: 0.85rem;">Edit</button>
          <button onclick="uploadPaymentMethodQR(${method.id})" style="padding: 0.4rem 0.75rem; border-radius: 8px; border: none; background: #dbeafe; cursor: pointer; font-weight: 600; color: #1e40af; font-size: 0.85rem;">${qrUrl ? 'Update QR' : 'Upload QR'}</button>
          <button onclick="togglePaymentMethodStatus(${method.id})" style="padding: 0.4rem 0.75rem; border-radius: 8px; border: none; background: ${isActive ? '#fef3c7' : '#dcfce7'}; cursor: pointer; font-weight: 600; color: #92400e; font-size: 0.85rem;">
            ${isActive ? 'Disable' : 'Enable'}
          </button>
          <button onclick="deletePaymentMethodEntry(${method.id})" style="padding: 0.4rem 0.75rem; border-radius: 8px; border: none; background: #fee2e2; cursor: pointer; font-weight: 600; color: #b91c1c; font-size: 0.85rem;">Delete</button>
        </div>
      </div>
    </div>
  `;
  }).join('');
}

// Payment method filter options - using default methods
function updatePaymentMethodFilterOptions(filterEl = document.getElementById('paymentMethodFilter')) {
  if (!filterEl) return;

  const previousValue = filterEl.value;
  filterEl.innerHTML = '<option value="">All Methods</option>';
  
  // Default payment methods
  const defaultMethods = ['Cash', 'Card', 'UPI', 'Online', 'Wallet'];
  defaultMethods.forEach(method => {
    const option = document.createElement('option');
    option.value = method;
    option.textContent = method;
    filterEl.appendChild(option);
  });

  if (previousValue && Array.from(filterEl.options).some(opt => opt.value === previousValue)) {
    filterEl.value = previousValue;
  }
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
    
    const url = '../api/get_payments.php' + (params.toString() ? '?' + params.toString() : '');
    const response = await fetch(url);
    const data = await response.json();
    
    // Store for export
    window.currentPaymentsData = data.payments || [];
    
    console.log('Payments response:', data);
    
    if (data.success) {
      if (data.payments && data.payments.length > 0) {
        tbody.innerHTML = data.payments.map(payment => {
          const paymentMethod = (payment.payment_method || 'Unknown').trim();
          
          // Get color based on payment method name (case-insensitive)
          const methodLower = paymentMethod.toLowerCase();
          let bgColor = '#764ba2'; // Default purple
          
          if (methodLower === 'cash') {
            bgColor = '#48bb78'; // Green
          } else if (methodLower === 'upi') {
            bgColor = '#667eea'; // Purple/Blue
          } else if (methodLower === 'card') {
            bgColor = '#f6ad55'; // Orange
          } else if (methodLower === 'online') {
            bgColor = '#764ba2'; // Purple
          } else if (methodLower === 'wallet') {
            bgColor = '#f59e0b'; // Amber
          } else if (methodLower === 'bank transfer' || methodLower === 'bank') {
            bgColor = '#3b82f6'; // Blue
          } else {
            // For custom payment methods, use a color based on hash of the name
            const colors = ['#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1'];
            const hash = paymentMethod.split('').reduce((acc, char) => acc + char.charCodeAt(0), 0);
            bgColor = colors[hash % colors.length];
          }
          
          return `
          <tr>
            <td>${payment.id}</td>
            <td><strong style="color: #48bb78;">${formatCurrency(payment.amount)}</strong></td>
            <td>
              <span style="background: ${bgColor}; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem; display: inline-block; min-width: 60px; text-align: center;">
                ${escapeHtml(paymentMethod)}
              </span>
            </td>
            <td style="color: #666;">${payment.transaction_id || '-'}</td>
            <td>
              <a href="#" onclick="showFullOrderDetails(${payment.order_id}); event.preventDefault();" style="color: #667eea; text-decoration: underline; cursor: pointer;">
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
        `;
        }).join('');
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
    updatePaymentMethodFilterOptions(paymentMethodFilter);
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
    const response = await fetch('../admin/get_session.php');
    const result = await response.json();
    
    if (checkSessionExpired(response, result)) return;
    
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
      if (document.getElementById('ownerName')) document.getElementById('ownerName').value = user.owner_name || '';
      if (restaurantAddress) restaurantAddress.value = user.address || '';
      const restaurantDescription = document.getElementById('restaurantDescription');
      if (restaurantDescription) restaurantDescription.value = user.description || '';
      // Load description format toggle
      const descFormatToggleSettings = document.getElementById('descFormatToggleSettings');
      const descFormatInputSettings = document.getElementById('descriptionFormatSettings');
      const descFmt = user.description_format || 'paragraph';
      if (descFormatInputSettings) descFormatInputSettings.value = descFmt;
      if (descFormatToggleSettings) {
        const lbl = descFormatToggleSettings.querySelector('#descFormatLabelSettings');
        const ico = descFormatToggleSettings.querySelector('.material-symbols-rounded');
        if (descFmt === 'br') {
          descFormatToggleSettings.classList.add('active');
          if (lbl) lbl.textContent = 'Line Break';
          if (ico) ico.textContent = 'format_line_spacing';
        } else {
          descFormatToggleSettings.classList.remove('active');
          if (lbl) lbl.textContent = 'Paragraph';
          if (ico) ico.textContent = 'format_align_left';
        }
      }
      
      // Opening Hours
      if (user.opening_hours) {
        try {
          const hoursData = typeof user.opening_hours === 'string' ? JSON.parse(user.opening_hours) : user.opening_hours;
          const days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
          days.forEach(day => {
            const dayData = hoursData[day];
            if (dayData) {
              const toggle = document.getElementById(`day_${day}_open`);
              if (toggle) toggle.checked = dayData.open;
              ['opening','closing'].forEach(type => {
                if (dayData[type]) {
                  const parts = dayData[type].split(' ');
                  const timeParts = parts[0].split(':');
                  const hourEl = document.getElementById(`${type}_hour_${day}`);
                  const minEl = document.getElementById(`${type}_min_${day}`);
                  const ampmEl = document.getElementById(`${type}_ampm_${day}`);
                  if (hourEl) hourEl.value = timeParts[0] || '09';
                  if (minEl) minEl.value = timeParts[1] || '00';
                  if (ampmEl) ampmEl.value = parts[1] || 'AM';
                }
              });

            }
          });
        } catch (e) {
          console.error('Error parsing opening hours:', e);
        }
      } else {
        // No opening hours saved yet - auto-fill with normal hours as default
        setTimeout(fillNormalHours, 100);
      }

      // Function to fill all days with normal restaurant hours (9 AM - 10 PM)
      window.fillNormalHours = function() {
        const days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        days.forEach(day => {
          const toggle = document.getElementById(`day_${day}_open`);
          if (toggle) toggle.checked = true;

          const openHour = document.getElementById(`opening_hour_${day}`);
          const openMin = document.getElementById(`opening_min_${day}`);
          const openAmpm = document.getElementById(`opening_ampm_${day}`);
          const closeHour = document.getElementById(`closing_hour_${day}`);
          const closeMin = document.getElementById(`closing_min_${day}`);
          const closeAmpm = document.getElementById(`closing_ampm_${day}`);

          if (openHour) openHour.value = '09';
          if (openMin) openMin.value = '00';
          if (openAmpm) openAmpm.value = 'AM';
          if (closeHour) closeHour.value = '10';
          if (closeMin) closeMin.value = '00';
          if (closeAmpm) closeAmpm.value = 'PM';
        });
        // Show a brief confirmation
        if (typeof showNotification === 'function') {
          showNotification('All days set to 9:00 AM - 10:00 PM', 'success');
        }
      };
      
      // Cashfree removed — use PhonePe only
      // PhonePe Payment Gateway
      const phonepeMerchantId = document.getElementById('phonepeMerchantId');
      const phonepeSaltKey = document.getElementById('phonepeSaltKey');
      const phonepeEnvironment = document.getElementById('phonepeEnvironment');
      if (phonepeMerchantId) phonepeMerchantId.value = user.phonepe_merchant_id || '';
      if (phonepeSaltKey) phonepeSaltKey.value = user.phonepe_salt_key || '';
      if (phonepeEnvironment) phonepeEnvironment.value = user.phonepe_environment || 'SANDBOX';
      // Payment gateway visibility handled server-side via PHP
      
      // Minimum Order Value
      const minimumOrderValue = document.getElementById('minimumOrderValue');
      if (minimumOrderValue) minimumOrderValue.value = parseFloat(user.minimum_order_value || 350).toFixed(2);
      
      // Packaging Charge
      const packagingCharge = document.getElementById('packagingCharge');
      if (packagingCharge) packagingCharge.value = parseFloat(user.packaging_charge || 0).toFixed(2);

      // Delivery Radius + saved restaurant coordinates
      const deliveryRadiusEl = document.getElementById('deliveryRadius');
      if (deliveryRadiusEl) deliveryRadiusEl.value = user.delivery_radius_km ? parseFloat(user.delivery_radius_km) : '';
      const restaurantAddressLat = document.getElementById('restaurantAddressLat');
      const restaurantAddressLng = document.getElementById('restaurantAddressLng');
      if (user.restaurant_lat && user.restaurant_lng) {
        if (restaurantAddressLat) restaurantAddressLat.value = user.restaurant_lat;
        if (restaurantAddressLng) restaurantAddressLng.value = user.restaurant_lng;
        if (typeof updateRestaurantMapPreview === 'function') {
          updateRestaurantMapPreview(user.restaurant_lat, user.restaurant_lng);
        }
      }
      if (typeof initRestaurantAddressAutocomplete === 'function') {
        initRestaurantAddressAutocomplete();
      }

      // Google Maps Link
      const restaurantGoogleMapsLink = document.getElementById('restaurantGoogleMapsLink');
      if (restaurantGoogleMapsLink) restaurantGoogleMapsLink.value = user.google_maps_link || '';
      
      // Social Media Links
      const instagramLink = document.getElementById('instagramLink');
      const facebookLink = document.getElementById('facebookLink');
      const twitterLink = document.getElementById('twitterLink');
      const youtubeLink = document.getElementById('youtubeLink');
      const linkedinLink = document.getElementById('linkedinLink');
      if (instagramLink) instagramLink.value = user.instagram_link || '';
      if (facebookLink) facebookLink.value = user.facebook_link || '';
      if (twitterLink) twitterLink.value = user.twitter_link || '';
      if (youtubeLink) youtubeLink.value = user.youtube_link || '';
      if (linkedinLink) linkedinLink.value = user.linkedin_link || '';
      
      // Profile Settings
      const usernameSetting = document.getElementById('usernameSetting');
      const profileEmailSetting = document.getElementById('profileEmailSetting');
      const emailNotifications = document.getElementById('emailNotifications');
      
      if (usernameSetting) usernameSetting.value = user.username || '';
      if (profileEmailSetting) profileEmailSetting.value = user.email || '';
      if (emailNotifications) {
        emailNotifications.checked = localStorage.getItem('emailNotifications') === 'true';
      }
      
      // System Settings - Currency and timezone are already set by PHP, but update if needed
      const currencySymbolSelect = document.getElementById('currencySymbolSelect');
      const currencySymbolInput = document.getElementById('currencySymbol');
      const timezone = document.getElementById('timezone');
      const autoSync = document.getElementById('autoSync');
      const notifications = document.getElementById('notifications');
      
      // Handle currency symbol dropdown
      if (currencySymbolSelect && user.currency_symbol) {
        const majorCurrencies = ['₹', '$', '€', '£', '¥', 'A$', 'C$', 'CHF', 'CN¥', 'HK$', 'NZ$', 'S$', '₽', '₩', 'R', '₦', '₨', '৳', 'Rs'];
        const currentSymbol = user.currency_symbol.trim();
        
        if (majorCurrencies.includes(currentSymbol)) {
          // Set dropdown to the matching currency
          currencySymbolSelect.value = currentSymbol;
          if (currencySymbolInput) {
            currencySymbolInput.style.display = 'none';
            currencySymbolInput.value = currentSymbol;
          }
        } else {
          // Set to Custom and show input
          currencySymbolSelect.value = 'Custom';
          if (currencySymbolInput) {
            currencySymbolInput.style.display = 'block';
            currencySymbolInput.value = currentSymbol;
          }
        }
      }
      
      // Timezone is already set by PHP, but update if not set
      if (timezone && (!timezone.value || timezone.value === 'Asia/Kolkata')) {
        timezone.value = user.timezone || localStorage.getItem('system_timezone') || 'Asia/Kolkata';
      }
      
      if (autoSync) {
        autoSync.checked = localStorage.getItem('system_autoSync') === 'true';
      }
      if (notifications) {
        notifications.checked = localStorage.getItem('system_notifications') === 'true';
      }
      
      // Set order type toggles from saved database settings
      var enableDeliveryCb = document.getElementById('enableDeliveryToggle');
      var enableTakeawayCb = document.getElementById('enableTakeawayToggle');
      var enableDineinCb = document.getElementById('enableDineinToggle');
      if (enableDeliveryCb && user.enable_delivery !== undefined) {
        enableDeliveryCb.checked = user.enable_delivery == 1 || user.enable_delivery === '1';
      }
      if (enableTakeawayCb && user.enable_takeaway !== undefined) {
        enableTakeawayCb.checked = user.enable_takeaway == 1 || user.enable_takeaway === '1';
      }
      if (enableDineinCb && user.enable_dinein !== undefined) {
        enableDineinCb.checked = user.enable_dinein == 1 || user.enable_dinein === '1';
      }
      
      // Set GST toggle from saved database settings
      if (user.enable_gst !== undefined) {
        var enableGstCheckbox = document.getElementById('enableGstToggle');
        var enableGstLabelEl = document.getElementById('enableGstLabel');
        if (enableGstCheckbox) {
          var gstEnabled = user.enable_gst == 1 || user.enable_gst === '1';
          enableGstCheckbox.checked = gstEnabled;
          if (enableGstLabelEl) {
            enableGstLabelEl.textContent = gstEnabled ? 'Enabled' : 'Disabled';
          }
          window.enableGst = gstEnabled;
        }
      }
      
      // Set Language Support toggle from saved database settings
      if (user.enable_language !== undefined) {
        var enableLangCheckbox = document.getElementById('enableLanguageToggle');
        var enableLangLabelEl = document.getElementById('enableLanguageLabel');
        if (enableLangCheckbox) {
          var langEnabled = user.enable_language == 1 || user.enable_language === '1';
          enableLangCheckbox.checked = langEnabled;
          if (enableLangLabelEl) {
            enableLangLabelEl.textContent = langEnabled ? 'Enabled' : 'Disabled';
          }
          window.enableLanguage = langEnabled;
        }
      }
      
      // Load business QR code if exists
      if (user.business_qr_code_path) {
        const qrPreview = document.getElementById('businessQRPreview');
        const qrPreviewImg = document.getElementById('businessQRPreviewImg');
        if (qrPreview && qrPreviewImg) {
          qrPreviewImg.src = '../api/image.php?type=business_qr&id=' + user.id;
          qrPreview.style.display = 'block';
        }
      }
    }
    
    setupSettingsForms();
  } catch (error) {
    console.error('Error loading settings data:', error);
  }
}

// Setup Settings Forms
function setupSettingsForms() {
  // Currency symbol dropdown handler
  const currencySymbolSelect = document.getElementById('currencySymbolSelect');
  const currencySymbolInput = document.getElementById('currencySymbol');
  
  if (currencySymbolSelect && currencySymbolInput) {
    currencySymbolSelect.addEventListener('change', function() {
      if (this.value === 'Custom') {
        currencySymbolInput.style.display = 'block';
        currencySymbolInput.focus();
        currencySymbolInput.value = '';
      } else {
        currencySymbolInput.style.display = 'none';
        currencySymbolInput.value = this.value;
      }
    });
    
    // Initialize on page load
    if (currencySymbolSelect.value === 'Custom') {
      currencySymbolInput.style.display = 'block';
    }
  }
  
  // Restaurant Settings Form
  const restaurantSettingsForm = document.getElementById('restaurantSettingsForm');
  if (restaurantSettingsForm && !restaurantSettingsForm.dataset.handlerAttached) {
    restaurantSettingsForm.dataset.handlerAttached = 'true';
    // Description format toggle for settings page
    var descToggleS = document.getElementById('descFormatToggleSettings');
    var descInputS = document.getElementById('descriptionFormatSettings');
    if (descToggleS && descInputS) {
      descToggleS.addEventListener('click', function() {
        var cur = descInputS.value;
        var lbl = descToggleS.querySelector('#descFormatLabelSettings');
        var ico = descToggleS.querySelector('.material-symbols-rounded');
        if (cur === 'paragraph') {
          descInputS.value = 'br';
          descToggleS.classList.add('active');
          if (lbl) lbl.textContent = 'Line Break';
          if (ico) ico.textContent = 'format_line_spacing';
        } else {
          descInputS.value = 'paragraph';
          descToggleS.classList.remove('active');
          if (lbl) lbl.textContent = 'Paragraph';
          if (ico) ico.textContent = 'format_align_left';
        }
      });
    }
    restaurantSettingsForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const restaurantName = document.getElementById('restaurantNameSetting')?.value.trim();
      const restaurantEmail = document.getElementById('restaurantEmail')?.value.trim();
      const restaurantPhone = document.getElementById('restaurantPhone')?.value.trim();
      const restaurantAddress = document.getElementById('restaurantAddress')?.value.trim();
      const restaurantDescription = document.getElementById('restaurantDescription')?.value.trim();
      
      if (!restaurantName) {
        showNotification('Restaurant name is required', 'error');
        return;
      }
      
      // Build opening hours JSON
      const openingHours = {};
      const days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
      days.forEach(day => {
        const toggle = document.getElementById(`day_${day}_open`);
        if (toggle) {
          const openingHour = document.getElementById(`opening_hour_${day}`)?.value || '09';
          const openingMin = document.getElementById(`opening_min_${day}`)?.value || '00';
          const openingAmpm = document.getElementById(`opening_ampm_${day}`)?.value || 'AM';
          const closingHour = document.getElementById(`closing_hour_${day}`)?.value || '05';
          const closingMin = document.getElementById(`closing_min_${day}`)?.value || '00';
          const closingAmpm = document.getElementById(`closing_ampm_${day}`)?.value || 'PM';
          openingHours[day] = {
            open: toggle.checked,
            opening: `${openingHour}:${openingMin} ${openingAmpm}`,
            closing: `${closingHour}:${closingMin} ${closingAmpm}`
          };
        }
      });
      
      // Disable submit button
      const submitBtn = restaurantSettingsForm.querySelector('button[type="submit"]');
      const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Saving...';
      }
      
      try {
        const response = await fetch('../admin/auth.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: `action=updateRestaurantSettings&restaurant_name=${encodeURIComponent(restaurantName)}&email=${encodeURIComponent(restaurantEmail || '')}&phone=${encodeURIComponent(restaurantPhone || '')}&address=${encodeURIComponent(restaurantAddress || '')}&description=${encodeURIComponent(restaurantDescription || '')}&description_format=${encodeURIComponent(document.getElementById('descriptionFormatSettings')?.value || 'paragraph')}&opening_hours=${encodeURIComponent(JSON.stringify(openingHours))}&minimum_order_value=${encodeURIComponent(document.getElementById('minimumOrderValue')?.value || '350')}&packaging_charge=${encodeURIComponent(document.getElementById('packagingCharge')?.value || '0')}&delivery_radius_km=${encodeURIComponent(document.getElementById('deliveryRadius')?.value || '0')}&restaurant_lat=${encodeURIComponent(document.getElementById('restaurantAddressLat')?.value || '')}&restaurant_lng=${encodeURIComponent(document.getElementById('restaurantAddressLng')?.value || '')}&enable_gst=${encodeURIComponent(document.getElementById('enableGstToggle')?.checked ? '1' : '0')}&enable_language=${encodeURIComponent(document.getElementById('enableLanguageToggle')?.checked ? '1' : '0')}&google_maps_link=${encodeURIComponent(document.getElementById('restaurantGoogleMapsLink')?.value || '')}&owner_name=${encodeURIComponent(document.getElementById('ownerName')?.value || '')}&instagram_link=${encodeURIComponent(document.getElementById('instagramLink')?.value || '')}&facebook_link=${encodeURIComponent(document.getElementById('facebookLink')?.value || '')}&twitter_link=${encodeURIComponent(document.getElementById('twitterLink')?.value || '')}&youtube_link=${encodeURIComponent(document.getElementById('youtubeLink')?.value || '')}&linkedin_link=${encodeURIComponent(document.getElementById('linkedinLink')?.value || '')}&enable_delivery=${encodeURIComponent(document.getElementById('enableDeliveryToggle')?.checked ? '1' : '0')}&enable_takeaway=${encodeURIComponent(document.getElementById('enableTakeawayToggle')?.checked ? '1' : '0')}&enable_dinein=${encodeURIComponent(document.getElementById('enableDineinToggle')?.checked ? '1' : '0')}`
        });
        
        const result = await response.json();
        
        if (result.success) {
          showNotification('Restaurant settings updated successfully', 'success');
          // Update session data
          if (typeof loadRestaurantInfo === 'function') {
            await loadRestaurantInfo();
          }
        } else {
          showNotification(result.message || 'Error updating restaurant settings', 'error');
        }
      } catch (error) {
        console.error('Error updating restaurant settings:', error);
        showNotification('Error updating restaurant settings', 'error');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalBtnText;
        }
      }
    });
  }

  // Restaurant address: Google Places Autocomplete + map picker
  const restaurantMapPickerBtn = document.getElementById('restaurantMapPickerBtn');
  if (restaurantMapPickerBtn && !restaurantMapPickerBtn.dataset.handlerAttached) {
    restaurantMapPickerBtn.dataset.handlerAttached = 'true';
    restaurantMapPickerBtn.addEventListener('click', openRestaurantMapPicker);
  }
  const restaurantAddressInputEl = document.getElementById('restaurantAddress');
  if (restaurantAddressInputEl && !restaurantAddressInputEl.dataset.clearBound) {
    restaurantAddressInputEl.dataset.clearBound = '1';
    restaurantAddressInputEl.addEventListener('input', function() {
      if (restaurantAddressInputEl.value.trim() === '') {
        var latEl = document.getElementById('restaurantAddressLat');
        var lngEl = document.getElementById('restaurantAddressLng');
        if (latEl) latEl.value = '';
        if (lngEl) lngEl.value = '';
        var previewEl = document.getElementById('restaurantMapPreview');
        if (previewEl) previewEl.style.display = 'none';
      }
    });
  }

  // Profile Settings Form
  const profileSettingsForm = document.getElementById('profileSettingsForm');
  if (profileSettingsForm && !profileSettingsForm.dataset.handlerAttached) {
    profileSettingsForm.dataset.handlerAttached = 'true';
    profileSettingsForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const username = document.getElementById('usernameSetting')?.value.trim();
      const email = document.getElementById('profileEmailSetting')?.value.trim();
      const emailNotifications = document.getElementById('emailNotifications')?.checked;
      
      if (!username || !email) {
        showNotification('Username and email are required', 'error');
        return;
      }
      
      // Save email notifications preference
      if (emailNotifications !== undefined) {
        localStorage.setItem('emailNotifications', emailNotifications ? 'true' : 'false');
      }
      
      // Disable submit button
      const submitBtn = profileSettingsForm.querySelector('button[type="submit"]');
      const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Saving...';
      }
      
      try {
        const response = await fetch('../admin/auth.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: `action=updateProfile&username=${encodeURIComponent(username)}&email=${encodeURIComponent(email)}`
        });
        
        const result = await response.json();
        
        if (result.success) {
          showNotification('Profile settings updated successfully', 'success');
          // Reload profile data
          if (typeof loadProfileData === 'function') {
            await loadProfileData();
          }
        } else {
          showNotification(result.message || 'Error updating profile settings', 'error');
        }
      } catch (error) {
        console.error('Error updating profile settings:', error);
        showNotification('Error updating profile settings', 'error');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalBtnText;
        }
      }
    });
  }
  
  // Business QR Code Upload Handler
  const uploadBusinessQRBtn = document.getElementById('uploadBusinessQRBtn');
  const businessQRUpload = document.getElementById('businessQRUpload');
  
  if (uploadBusinessQRBtn && businessQRUpload) {
    uploadBusinessQRBtn.addEventListener('click', async () => {
      if (!businessQRUpload.files || businessQRUpload.files.length === 0) {
        showNotification('Please select a QR code image file', 'error');
        return;
      }
      
      const file = businessQRUpload.files[0];
      
      // Validate file type
      const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
      if (!allowedTypes.includes(file.type)) {
        showNotification('Invalid file type. Please upload JPEG, PNG, GIF, or WebP image', 'error');
        return;
      }
      
      // Validate file size (5MB max)
      if (file.size > 5 * 1024 * 1024) {
        showNotification('File too large. Maximum size is 5MB', 'error');
        return;
      }
      
      const formData = new FormData();
      formData.append('action', 'uploadBusinessQR');
      formData.append('business_qr', file);
      
      uploadBusinessQRBtn.disabled = true;
      uploadBusinessQRBtn.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Uploading...';
      
      try {
        const response = await fetch('../admin/auth.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          showNotification(result.message || 'Business QR code uploaded successfully', 'success');
          
          // Update preview
          const qrPreview = document.getElementById('businessQRPreview');
          const qrPreviewImg = document.getElementById('businessQRPreviewImg');
          if (qrPreview && qrPreviewImg && result.data && result.data.qr_code_url) {
            qrPreviewImg.src = '../' + result.data.qr_code_url;
            qrPreview.style.display = 'block';
          }
          
          // Clear file input
          businessQRUpload.value = '';
        } else {
          showNotification(result.message || 'Error uploading QR code', 'error');
        }
      } catch (error) {
        console.error('Error uploading business QR code:', error);
        showNotification('Error uploading QR code', 'error');
      } finally {
        uploadBusinessQRBtn.disabled = false;
        uploadBusinessQRBtn.innerHTML = '<span class="material-symbols-rounded">upload</span> Upload QR Code';
      }
    });
  }
  
  // Remove Business QR Code Handler
  window.removeBusinessQR = async function() {
    if (!await showSweetConfirm('Are you sure you want to remove the business QR code?', 'Remove QR Code')) {
      return;
    }
    
    try {
      const formData = new FormData();
      formData.append('action', 'removeBusinessQR');
      
      const response = await fetch('../admin/auth.php', {
        method: 'POST',
        body: formData
      });
      
      const result = await response.json();
      
      if (result.success) {
        showNotification(result.message || 'Business QR code removed successfully', 'success');
        
        // Hide preview
        const qrPreview = document.getElementById('businessQRPreview');
        if (qrPreview) {
          qrPreview.style.display = 'none';
        }
        
        // Clear file input
        const businessQRUpload = document.getElementById('businessQRUpload');
        if (businessQRUpload) {
          businessQRUpload.value = '';
        }
      } else {
        showNotification(result.message || 'Error removing QR code', 'error');
      }
    } catch (error) {
      console.error('Error removing business QR code:', error);
      showNotification('Error removing QR code', 'error');
    }
  };
  
  // System Settings Form
  const systemSettingsForm = document.getElementById('systemSettingsForm');
  if (systemSettingsForm && !systemSettingsForm.dataset.handlerAttached) {
    systemSettingsForm.dataset.handlerAttached = 'true';
    systemSettingsForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      // Get currency symbol from dropdown or custom input
      const currencySymbolSelect = document.getElementById('currencySymbolSelect');
      const currencySymbolInput = document.getElementById('currencySymbol');
      let currencySymbol = '';
      
      if (currencySymbolSelect && currencySymbolSelect.value === 'Custom') {
        currencySymbol = currencySymbolInput?.value.trim() || '₹';
      } else {
        currencySymbol = currencySymbolSelect?.value || currencySymbolInput?.value.trim() || '₹';
      }
      const timezone = document.getElementById('timezone')?.value.trim();
      const language = document.getElementById('language')?.value || 'en';
      const autoSync = document.getElementById('autoSync')?.checked;
      const notifications = document.getElementById('notifications')?.checked;
      
      // Save to localStorage
      if (timezone) localStorage.setItem('system_timezone', timezone);
      if (autoSync !== undefined) localStorage.setItem('system_autoSync', autoSync ? 'true' : 'false');
      if (notifications !== undefined) localStorage.setItem('system_notifications', notifications ? 'true' : 'false');
      
      // Disable submit button
      const submitBtn = systemSettingsForm.querySelector('button[type="submit"]');
      const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Saving...';
      }
      
      try {
        // Save to database
        const response = await fetch('../admin/auth.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: `action=updateSystemSettings&currency_symbol=${encodeURIComponent(currencySymbol || '₹')}&timezone=${encodeURIComponent(timezone || 'Asia/Kolkata')}&language=${encodeURIComponent(language || 'en')}`
        });
        
        const result = await response.json();
        
        if (result.success) {
          showNotification('System settings saved successfully', 'success');
          // Update currency symbol display if it exists
          const currencyDisplay = document.getElementById('currencySymbolDisplay');
          if (currencyDisplay && currencySymbol) {
            currencyDisplay.textContent = currencySymbol;
            // Update global currency symbol
            updateCurrencySymbol(currencySymbol);
          }
          // Reload page to apply timezone changes
          setTimeout(() => {
            window.location.reload();
          }, 1000);
        } else {
          showNotification(result.message || 'Error saving system settings', 'error');
        }
      } catch (error) {
        console.error('Error saving system settings:', error);
        showNotification('Error saving system settings', 'error');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalBtnText;
        }
      }
    });
  }
  
  // Change Password Form Handler
  const changePasswordForm = document.getElementById('changePasswordForm');
  if (changePasswordForm && !changePasswordForm.dataset.handlerAttached) {
    changePasswordForm.dataset.handlerAttached = 'true';
    
    const currentPasswordInput = document.getElementById('currentPassword');
    const newPasswordInput = document.getElementById('newPassword');
    const confirmPasswordInput = document.getElementById('confirmPassword');
    const passwordCriteria = document.getElementById('passwordCriteria');
    const passwordMatchStatus = document.getElementById('passwordMatchStatus');
    
    // Real-time password criteria validation
    if (newPasswordInput) {
      newPasswordInput.addEventListener('input', function() {
        validatePasswordCriteria(this.value);
        checkPasswordMatch();
      });
    }
    
    // Real-time password match checking
    if (confirmPasswordInput) {
      confirmPasswordInput.addEventListener('input', function() {
        checkPasswordMatch();
      });
    }
    
    // Password criteria validation function
    function validatePasswordCriteria(password) {
      if (!passwordCriteria) return;
      
      const lengthItem = passwordCriteria.querySelector('[data-criteria="length"]');
      if (lengthItem) {
        const icon = lengthItem.querySelector('.criteria-icon');
        const isValid = password.length >= 6;
        if (isValid) {
          icon.textContent = 'check_circle';
          icon.style.color = '#10b981';
          lengthItem.style.color = '#10b981';
        } else {
          icon.textContent = 'close';
          icon.style.color = '#ef4444';
          lengthItem.style.color = '#6b7280';
        }
      }
    }
    
    // Password match checking function
    function checkPasswordMatch() {
      if (!newPasswordInput || !confirmPasswordInput || !passwordMatchStatus) return;
      
      const newPassword = newPasswordInput.value;
      const confirmPassword = confirmPasswordInput.value;
      
      if (confirmPassword.length === 0) {
        passwordMatchStatus.style.display = 'none';
        return;
      }
      
      if (newPassword === confirmPassword && newPassword.length >= 6) {
        passwordMatchStatus.textContent = '✓ Passwords match';
        passwordMatchStatus.style.color = '#10b981';
        passwordMatchStatus.style.display = 'block';
      } else if (newPassword.length > 0) {
        passwordMatchStatus.textContent = '✗ Passwords do not match';
        passwordMatchStatus.style.color = '#ef4444';
        passwordMatchStatus.style.display = 'block';
      }
    }
    
    // Form submission handler
    changePasswordForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      // Clear previous errors
      document.getElementById('currentPasswordError')?.style.setProperty('display', 'none');
      document.getElementById('newPasswordError')?.style.setProperty('display', 'none');
      document.getElementById('confirmPasswordError')?.style.setProperty('display', 'none');
      
      const currentPassword = currentPasswordInput?.value.trim();
      const newPassword = newPasswordInput?.value.trim();
      const confirmPassword = confirmPasswordInput?.value.trim();
      
      // Validation
      let hasError = false;
      
      if (!currentPassword) {
        showFieldError('currentPasswordError', 'Current password is required');
        hasError = true;
      }
      
      if (!newPassword) {
        showFieldError('newPasswordError', 'New password is required');
        hasError = true;
      } else if (newPassword.length < 6) {
        showFieldError('newPasswordError', 'Password must be at least 6 characters long');
        hasError = true;
      }
      
      if (!confirmPassword) {
        showFieldError('confirmPasswordError', 'Please confirm your new password');
        hasError = true;
      } else if (newPassword !== confirmPassword) {
        showFieldError('confirmPasswordError', 'Passwords do not match');
        hasError = true;
      }
      
      if (hasError) {
        return;
      }
      
      // Disable submit button
      const submitBtn = document.getElementById('changePasswordBtn');
      const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Changing...';
      }
      
      try {
        const response = await fetch('../admin/auth.php', {
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
          throw new Error('Server error. Please try again.');
        }
        
        const result = await response.json();
        
        if (result.success) {
          await showSweetAlert('Success!', 'Your password has been changed successfully.', 'success');
          changePasswordForm.reset();
          if (passwordMatchStatus) passwordMatchStatus.style.display = 'none';
          validatePasswordCriteria('');
        } else {
          // Handle specific error messages
          const errorMsg = result.message || 'Error changing password';
          if (errorMsg.toLowerCase().includes('incorrect') || errorMsg.toLowerCase().includes('current password')) {
            showFieldError('currentPasswordError', errorMsg);
            await showSweetAlert('Incorrect Password', errorMsg, 'error');
          } else {
            await showSweetAlert('Error', errorMsg, 'error');
          }
        }
      } catch (error) {
        console.error('Error changing password:', error);
        await showSweetAlert('Error', 'An error occurred while changing your password. Please try again.', 'error');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalBtnText;
        }
      }
    });
    
    // Helper function to show field errors
    function showFieldError(errorId, message) {
      const errorEl = document.getElementById(errorId);
      if (errorEl) {
        errorEl.textContent = message;
        errorEl.style.display = 'block';
      }
    }
  }
  
  // Security Settings Form (if exists - for backward compatibility)
  const securitySettingsForm = document.getElementById('securitySettingsForm');
  if (securitySettingsForm && !securitySettingsForm.dataset.handlerAttached) {
    securitySettingsForm.dataset.handlerAttached = 'true';
    securitySettingsForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const currentPassword = document.getElementById('currentPassword')?.value.trim();
      const newPassword = document.getElementById('newPassword')?.value.trim();
      const confirmPassword = document.getElementById('confirmPassword')?.value.trim();
      
      if (!currentPassword || !newPassword || !confirmPassword) {
        await showSweetAlert('Validation Error', 'Please fill in all fields', 'error');
        return;
      }
      
      if (newPassword.length < 6) {
        await showSweetAlert('Validation Error', 'New password must be at least 6 characters', 'error');
        return;
      }
      
      if (newPassword !== confirmPassword) {
        await showSweetAlert('Validation Error', 'New passwords do not match', 'error');
        return;
      }
      
      // Disable submit button
      const submitBtn = securitySettingsForm.querySelector('button[type="submit"]');
      const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Changing...';
      }
      
      try {
        const response = await fetch('../admin/auth.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: `action=changePassword&currentPassword=${encodeURIComponent(currentPassword)}&newPassword=${encodeURIComponent(newPassword)}`
        });
        
        const result = await response.json();
        
        if (result.success) {
          await showSweetAlert('Success!', 'Password changed successfully', 'success');
          securitySettingsForm.reset();
        } else {
          await showSweetAlert('Error', result.message || 'Error changing password', 'error');
        }
      } catch (error) {
        console.error('Error changing password:', error);
        await showSweetAlert('Error', 'Error changing password', 'error');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalBtnText;
        }
      }
    });
  }
}

// ── Restaurant address: Google Places Autocomplete + map picker ──
// (used by the Settings page "Address" field, see setupSettingsForms() above)

var restaurantPlacesAutocomplete = null;

function applySelectedRestaurantAddress(lat, lng, formatted) {
  var addrInput = document.getElementById('restaurantAddress');
  if (addrInput && formatted) addrInput.value = formatted;
  var latEl = document.getElementById('restaurantAddressLat');
  var lngEl = document.getElementById('restaurantAddressLng');
  if (latEl) latEl.value = lat;
  if (lngEl) lngEl.value = lng;
  updateRestaurantMapPreview(lat, lng);
}

// Small draggable-pin map preview shown below the address field
var restaurantPreviewMap = null;
var restaurantPreviewMarker = null;
function updateRestaurantMapPreview(lat, lng) {
  var container = document.getElementById('restaurantMapPreview');
  if (!container || typeof google === 'undefined' || !google.maps) return;
  container.style.display = 'block';
  var pos = { lat: parseFloat(lat), lng: parseFloat(lng) };
  if (!restaurantPreviewMap) {
    restaurantPreviewMap = new google.maps.Map(container, {
      center: pos, zoom: 16,
      streetViewControl: false, mapTypeControl: false, fullscreenControl: false
    });
    restaurantPreviewMarker = new google.maps.Marker({ position: pos, map: restaurantPreviewMap, draggable: true });
    restaurantPreviewMarker.addListener('dragend', function() {
      var p = restaurantPreviewMarker.getPosition();
      reverseGeocode(p.lat(), p.lng(), function(formatted) {
        applySelectedRestaurantAddress(p.lat(), p.lng(), formatted);
      });
    });
  } else {
    google.maps.event.trigger(restaurantPreviewMap, 'resize');
    restaurantPreviewMap.setCenter(pos);
    restaurantPreviewMarker.setPosition(pos);
  }
}

// Generic reverse geocode helper (lat/lng -> formatted address)
function reverseGeocode(lat, lng, callback) {
  var apiKey = window.googleMapsApiKey;
  if (!apiKey) { callback(''); return; }
  var url = 'https://maps.googleapis.com/maps/api/geocode/json?latlng=' + lat + ',' + lng + '&key=' + apiKey;
  fetch(url)
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data && data.status === 'OK' && data.results && data.results.length > 0) {
        callback(data.results[0].formatted_address);
      } else {
        callback('');
      }
    })
    .catch(function() { callback(''); });
}

function initRestaurantAddressAutocomplete(retries) {
  retries = retries || 0;
  var input = document.getElementById('restaurantAddress');
  if (!input) return;
  if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
    if (retries < 20) { setTimeout(function() { initRestaurantAddressAutocomplete(retries + 1); }, 250); }
    return;
  }
  if (restaurantPlacesAutocomplete) {
    try { google.maps.event.clearInstanceListeners(input); } catch (e) {}
  }
  restaurantPlacesAutocomplete = new google.maps.places.Autocomplete(input, {
    fields: ['formatted_address', 'geometry'],
    types: ['geocode']
  });
  restaurantPlacesAutocomplete.addListener('place_changed', function() {
    var place = restaurantPlacesAutocomplete.getPlace();
    if (!place || !place.geometry || !place.geometry.location) return;
    var lat = place.geometry.location.lat();
    var lng = place.geometry.location.lng();
    applySelectedRestaurantAddress(lat, lng, place.formatted_address || input.value);
  });
}

// ── Map picker modal: drop/drag a pin to set the restaurant's exact location ──
var restaurantMapPickerMap = null;
var restaurantMapPickerMarker = null;

function openRestaurantMapPicker() {
  if (typeof google === 'undefined' || !google.maps) {
    if (typeof showNotification === 'function') { showNotification('Map is still loading, please try again in a moment', 'error'); }
    return;
  }
  var modal = document.getElementById('restaurantMapPickerModal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'restaurantMapPickerModal';
    modal.className = 'modal';
    modal.innerHTML =
      '<div class="modal-content" style="max-width:520px;">' +
        '<div class="modal-header">' +
          '<h2>Select Restaurant Location</h2>' +
          '<span class="modal-close" onclick="closeRestaurantMapPicker()">&times;</span>' +
        '</div>' +
        '<div class="modal-body">' +
          '<p style="font-size:12px;color:#666;margin-bottom:8px;">Drag the pin, tap the map, or use your current location to set your restaurant\'s exact spot.</p>' +
          '<div id="restaurantMapPickerCanvas" style="width:100%;height:320px;border-radius:10px;overflow:hidden;background:#eee;"></div>' +
          '<div id="restaurantMapPickerAddress" style="margin-top:10px;font-size:13px;color:#333;min-height:18px;">Locating...</div>' +
          '<div style="display:flex;gap:10px;margin-top:14px;">' +
            '<button type="button" class="btn btn-secondary" onclick="useCurrentLocationOnRestaurantMap()" style="flex:1;">📍 Use Current Location</button>' +
            '<button type="button" class="btn btn-primary" onclick="confirmRestaurantMapLocation()" style="flex:1;">Use This Location</button>' +
          '</div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);
  }
  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';

  var existingLat = parseFloat(document.getElementById('restaurantAddressLat')?.value);
  var existingLng = parseFloat(document.getElementById('restaurantAddressLng')?.value);
  var startCenter = (!isNaN(existingLat) && !isNaN(existingLng)) ? { lat: existingLat, lng: existingLng }
    : { lat: 20.5937, lng: 78.9629 }; // fallback: center of India

  restaurantMapPickerMap = new google.maps.Map(document.getElementById('restaurantMapPickerCanvas'), {
    center: startCenter, zoom: 16,
    streetViewControl: false, mapTypeControl: false, fullscreenControl: false
  });
  restaurantMapPickerMarker = new google.maps.Marker({ position: startCenter, map: restaurantMapPickerMap, draggable: true });

  restaurantMapPickerMap.addListener('click', function(e) {
    restaurantMapPickerMarker.setPosition(e.latLng);
    reverseGeocodeForRestaurantPicker(e.latLng.lat(), e.latLng.lng());
  });
  restaurantMapPickerMarker.addListener('dragend', function() {
    var pos = restaurantMapPickerMarker.getPosition();
    reverseGeocodeForRestaurantPicker(pos.lat(), pos.lng());
  });

  reverseGeocodeForRestaurantPicker(startCenter.lat, startCenter.lng);
}

function closeRestaurantMapPicker() {
  var modal = document.getElementById('restaurantMapPickerModal');
  if (modal) modal.style.display = 'none';
  document.body.style.overflow = '';
  restaurantMapPickerMap = null;
  restaurantMapPickerMarker = null;
}

function useCurrentLocationOnRestaurantMap() {
  var addrEl = document.getElementById('restaurantMapPickerAddress');
  if (!navigator.geolocation) {
    if (addrEl) addrEl.textContent = 'Location access is not available on this device.';
    return;
  }
  if (addrEl) addrEl.textContent = 'Getting your current location...';
  navigator.geolocation.getCurrentPosition(function(pos) {
    var latLng = { lat: pos.coords.latitude, lng: pos.coords.longitude };
    if (restaurantMapPickerMap && restaurantMapPickerMarker) {
      restaurantMapPickerMap.setCenter(latLng);
      restaurantMapPickerMap.setZoom(17);
      restaurantMapPickerMarker.setPosition(latLng);
    }
    reverseGeocodeForRestaurantPicker(latLng.lat, latLng.lng);
  }, function() {
    if (addrEl) addrEl.textContent = 'Could not get your location. Please allow location access, or drag the pin manually.';
  }, { enableHighAccuracy: true, timeout: 10000 });
}

function reverseGeocodeForRestaurantPicker(lat, lng) {
  var addrEl = document.getElementById('restaurantMapPickerAddress');
  if (!window.googleMapsApiKey) return;
  if (addrEl) addrEl.textContent = 'Looking up address...';
  reverseGeocode(lat, lng, function(formatted) {
    if (!addrEl) return;
    addrEl.dataset.formatted = formatted;
    addrEl.textContent = formatted || 'Could not resolve an address for this spot, but you can still use it.';
  });
}

function confirmRestaurantMapLocation() {
  if (!restaurantMapPickerMarker) { closeRestaurantMapPicker(); return; }
  var pos = restaurantMapPickerMarker.getPosition();
  var addrEl = document.getElementById('restaurantMapPickerAddress');
  var formatted = (addrEl && addrEl.dataset.formatted) ? addrEl.dataset.formatted : (addrEl ? addrEl.textContent : '');
  applySelectedRestaurantAddress(pos.lat(), pos.lng(), formatted);
  closeRestaurantMapPicker();
}

// Load Reports Data
async function loadReports() {
  try {
    let period = document.getElementById('reportPeriod')?.value || 'today';
    const reportType = document.getElementById('reportType')?.value || 'sales';
    
    // Handle custom date range
    let startDate = '';
    let endDate = '';
    if (period === 'custom') {
      startDate = document.getElementById('reportStartDate')?.value || '';
      endDate = document.getElementById('reportEndDate')?.value || '';
      if (!startDate || !endDate) {
        showNotification('Please select both start and end dates for custom range.', 'error');
        return;
      }
      if (new Date(startDate) > new Date(endDate)) {
        showNotification('Start date cannot be after end date.', 'error');
        return;
      }
    }
    
    // Get payment method filter
    const paymentMethod = document.getElementById('filterPaymentMethod')?.value || 'all';
    
    console.log('Loading reports for period:', period, 'type:', reportType);
    
    // Show loading state
    const totalSalesEl = document.getElementById('reportTotalSales');
    const totalOrdersEl = document.getElementById('reportTotalOrders');
    const totalItemsEl = document.getElementById('reportTotalItems');
    const totalCustomersEl = document.getElementById('reportTotalCustomers');
    const salesTable = document.getElementById('reportSalesTable');
    const topItemsDiv = document.getElementById('reportTopItems');
    const paymentMethodsDiv = document.getElementById('reportPaymentMethods');
    
    if (totalSalesEl) totalSalesEl.textContent = 'Loading...';
    if (totalOrdersEl) totalOrdersEl.textContent = 'Loading...';
    if (totalItemsEl) totalItemsEl.textContent = 'Loading...';
    if (totalCustomersEl) totalCustomersEl.textContent = 'Loading...';
    if (salesTable) salesTable.innerHTML = '<tr><td colspan="6" style="padding: 2rem; text-align: center; color: #666;">Loading sales data...</td></tr>';
    if (topItemsDiv) topItemsDiv.innerHTML = '<div style="text-align: center; padding: 2rem; color: #666;">Loading...</div>';
    if (paymentMethodsDiv) paymentMethodsDiv.innerHTML = '<div style="text-align: center; padding: 2rem; color: #666;">Loading...</div>';
    
    // Build URL with parameters
    let url = `../api/get_sales_report.php?period=${period}&type=${reportType}`;
    if (period === 'custom' && startDate && endDate) {
      url += `&start_date=${startDate}&end_date=${endDate}`;
    }
    if (paymentMethod !== 'all' && reportType === 'sales') {
      url += `&payment_method=${encodeURIComponent(paymentMethod)}`;
    }
    
    const response = await fetch(url);
    
    // Get response text first to check for errors
    const responseText = await response.text();
    console.log('Reports API response:', responseText);
    
    let data;
    try {
      data = JSON.parse(responseText);
    } catch (parseError) {
      console.error('Failed to parse JSON response:', parseError);
      console.error('Response text:', responseText);
      throw new Error('Invalid response from server');
    }
    
    if (!data.success) {
      console.error('Reports API error:', data);
      throw new Error(data.message || 'Failed to load reports');
    }
    
    console.log('Reports data received:', data);
    
    // Store data for export
    window.currentReportData = data;
    
    // Update report table title based on type
    const reportTableTitle = document.getElementById('reportTableTitle');
    const currentReportType = data.report_type || 'sales';
    if (reportTableTitle) {
      const titles = {
        'sales': 'Sales Details',
        'customers': 'Top Customers',
        'items': 'Top Items Report',
        'payment': 'Payment Methods Report',
        'hourly': 'Hourly Sales Report',
        'staff': 'Staff Performance Report'
      };
      reportTableTitle.textContent = titles[currentReportType] || 'Report Details';
    }
    
    // Update summary cards (use the elements we already got)
    if (totalSalesEl) {
      totalSalesEl.textContent = formatCurrencyNoDecimals(data.summary?.total_sales || 0);
    }
    if (totalOrdersEl) {
      totalOrdersEl.textContent = data.summary?.total_orders || 0;
    }
    if (totalItemsEl) {
      totalItemsEl.textContent = data.summary?.total_items || 0;
    }
    if (totalCustomersEl) {
      totalCustomersEl.textContent = data.summary?.total_customers || 0;
    }
    
    // Update sales table based on report type
    if (salesTable) {
      const tableReportType = data.report_type || 'sales';
      const tableHeader = salesTable.parentElement.querySelector('thead');
      
      if (tableReportType === 'customers') {
        // Customer Report
        if (tableHeader) {
          tableHeader.innerHTML = `
            <tr style="background: var(--light-gray);">
              <th style="padding: 1rem; text-align: left; font-weight: 600;">Customer Name</th>
              <th style="padding: 1rem; text-align: left; font-weight: 600;">Phone</th>
              <th style="padding: 1rem; text-align: left; font-weight: 600;">Total Orders</th>
              <th style="padding: 1rem; text-align: left; font-weight: 600;">Last Order</th>
              <th style="padding: 1rem; text-align: right; font-weight: 600;">Total Spent</th>
            </tr>
          `;
        }
        if (data.top_customers && data.top_customers.length > 0) {
          salesTable.innerHTML = data.top_customers.map(customer => `
            <tr style="border-bottom: 1px solid #eee;">
              <td style="padding: 1rem; font-weight: 600;">${customer.customer_name || 'N/A'}</td>
              <td style="padding: 1rem;">${customer.phone || '-'}</td>
              <td style="padding: 1rem;">${customer.total_orders}</td>
              <td style="padding: 1rem;">${customer.last_order_date ? new Date(customer.last_order_date).toLocaleDateString('en-IN') : '-'}</td>
              <td style="padding: 1rem; text-align: right; font-weight: 600; color: var(--primary-red);">${formatCurrencyNoDecimals(customer.total_spent)}</td>
            </tr>
          `).join('');
        } else {
          salesTable.innerHTML = '<tr><td colspan="5" style="padding: 2rem; text-align: center; color: #666;">No customer data found</td></tr>';
        }
      } else if (tableReportType === 'hourly') {
        // Hourly Sales Report
        if (tableHeader) {
          tableHeader.innerHTML = `
            <tr style="background: var(--light-gray);">
              <th style="padding: 1rem; text-align: left; font-weight: 600;">Hour</th>
              <th style="padding: 1rem; text-align: left; font-weight: 600;">Orders</th>
              <th style="padding: 1rem; text-align: right; font-weight: 600;">Sales</th>
            </tr>
          `;
        }
        if (data.hourly_sales && data.hourly_sales.length > 0) {
          salesTable.innerHTML = data.hourly_sales.map(hour => {
            const hourLabel = hour.hour < 12 ? `${hour.hour}:00 AM` : hour.hour === 12 ? '12:00 PM' : `${hour.hour - 12}:00 PM`;
            return `
              <tr style="border-bottom: 1px solid #eee;">
                <td style="padding: 1rem; font-weight: 600;">${hourLabel}</td>
                <td style="padding: 1rem;">${hour.order_count}</td>
                <td style="padding: 1rem; text-align: right; font-weight: 600; color: var(--primary-red);">${formatCurrencyNoDecimals(hour.total_sales)}</td>
              </tr>
            `;
          }).join('');
        } else {
          salesTable.innerHTML = '<tr><td colspan="3" style="padding: 2rem; text-align: center; color: #666;">Hourly data only available for today. Please select "Today" period.</td></tr>';
        }
      } else if (tableReportType === 'staff') {
        // Staff Performance Report
        if (tableHeader) {
          tableHeader.innerHTML = `
            <tr style="background: var(--light-gray);">
              <th style="padding: 1rem; text-align: left; font-weight: 600;">Staff Name</th>
              <th style="padding: 1rem; text-align: left; font-weight: 600;">Total Orders</th>
              <th style="padding: 1rem; text-align: right; font-weight: 600;">Total Sales</th>
            </tr>
          `;
        }
        if (data.staff_performance && data.staff_performance.length > 0) {
          salesTable.innerHTML = data.staff_performance.map(staff => `
            <tr style="border-bottom: 1px solid #eee;">
              <td style="padding: 1rem; font-weight: 600;">${staff.staff_name || 'Unknown'}</td>
              <td style="padding: 1rem;">${staff.total_orders}</td>
              <td style="padding: 1rem; text-align: right; font-weight: 600; color: var(--primary-red);">${formatCurrencyNoDecimals(staff.total_sales)}</td>
            </tr>
          `).join('');
        } else {
          salesTable.innerHTML = '<tr><td colspan="3" style="padding: 2rem; text-align: center; color: #666;">No staff performance data found</td></tr>';
        }
      } else {
        // Default Sales Report
        if (tableHeader) {
          tableHeader.innerHTML = `
            <tr style="background: var(--light-gray);">
              <th style="padding: 1rem; text-align: left; font-weight: 600;">Date</th>
              <th style="padding: 1rem; text-align: left; font-weight: 600;">Order #</th>
              <th style="padding: 1rem; text-align: left; font-weight: 600;">Customer</th>
              <th style="padding: 1rem; text-align: left; font-weight: 600;">Items</th>
              <th style="padding: 1rem; text-align: left; font-weight: 600;">Payment</th>
              <th style="padding: 1rem; text-align: right; font-weight: 600;">Amount</th>
            </tr>
          `;
        }
      if (data.sales_details && data.sales_details.length > 0) {
        salesTable.innerHTML = data.sales_details.map(order => `
          <tr style="border-bottom: 1px solid #eee;">
            <td style="padding: 1rem;">${new Date(order.created_at).toLocaleDateString('en-IN')}</td>
            <td style="padding: 1rem; font-weight: 600;">${order.order_number}</td>
            <td style="padding: 1rem;">${order.customer_name}</td>
            <td style="padding: 1rem;">${order.item_count}</td>
            <td style="padding: 1rem;"><span style="background: #e5f3ff; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">${order.payment_method}</span></td>
            <td style="padding: 1rem; text-align: right; font-weight: 600; color: var(--primary-red);">${formatCurrencyNoDecimals(order.total)}</td>
          </tr>
        `).join('');
      } else {
        salesTable.innerHTML = '<tr><td colspan="6" style="padding: 2rem; text-align: center; color: #666;">No sales data found</td></tr>';
        }
      }
    }
    
    // Update top items (use the element we already got)
    if (topItemsDiv) {
      if (data.top_items && data.top_items.length > 0) {
        topItemsDiv.innerHTML = data.top_items.map((item, index) => `
          <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; border-bottom: 1px solid #eee;">
            <div>
              <div style="font-weight: 600;">${index + 1}. ${item.item_name}</div>
              <div style="font-size: 0.85rem; color: #666;">Qty: ${item.total_quantity}</div>
            </div>
            <div style="font-weight: 700; color: var(--primary-red);">${formatCurrencyNoDecimals(item.total_revenue)}</div>
          </div>
        `).join('');
      } else {
        topItemsDiv.innerHTML = '<div style="text-align: center; padding: 2rem; color: #666;">No items found</div>';
      }
    }
    
    // Update payment methods (use the element we already got)
    if (paymentMethodsDiv) {
      if (data.payment_methods && data.payment_methods.length > 0) {
        paymentMethodsDiv.innerHTML = data.payment_methods.map(method => `
          <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; border-bottom: 1px solid #eee;">
            <div>
              <div style="font-weight: 600;">${method.payment_method}</div>
              <div style="font-size: 0.85rem; color: #666;">${method.count} orders</div>
            </div>
            <div style="font-weight: 700; color: var(--primary-red);">${formatCurrencyNoDecimals(method.amount)}</div>
          </div>
        `).join('');
      } else {
        paymentMethodsDiv.innerHTML = '<div style="text-align: center; padding: 2rem; color: #666;">No payment data found</div>';
      }
    }
    
    console.log('Reports loaded successfully');
    
  } catch (error) {
    console.error('Error loading reports:', error);
    
    // Show error state
    const totalSalesEl = document.getElementById('reportTotalSales');
    const totalOrdersEl = document.getElementById('reportTotalOrders');
    const totalItemsEl = document.getElementById('reportTotalItems');
    const totalCustomersEl = document.getElementById('reportTotalCustomers');
    const salesTable = document.getElementById('reportSalesTable');
    const topItemsDiv = document.getElementById('reportTopItems');
    const paymentMethodsDiv = document.getElementById('reportPaymentMethods');
    
    if (totalSalesEl) totalSalesEl.textContent = formatCurrencyNoDecimals(0);
    if (totalOrdersEl) totalOrdersEl.textContent = '0';
    if (totalItemsEl) totalItemsEl.textContent = '0';
    if (totalCustomersEl) totalCustomersEl.textContent = '0';
    if (salesTable) salesTable.innerHTML = '<tr><td colspan="6" style="padding: 2rem; text-align: center; color: #ef4444;">Error loading data. Please try again.</td></tr>';
    if (topItemsDiv) topItemsDiv.innerHTML = '<div style="text-align: center; padding: 2rem; color: #ef4444;">Error loading data</div>';
    if (paymentMethodsDiv) paymentMethodsDiv.innerHTML = '<div style="text-align: center; padding: 2rem; color: #ef4444;">Error loading data</div>';
    
    showNotification('Error loading reports: ' + error.message, 'error');
  }
}

// Export Reports to CSV
function exportReportsToCSV() {
  if (!window.currentReportData) {
    showNotification('No data to export. Please load reports first.', 'error');
    return;
  }
  
  const data = window.currentReportData;
  const reportType = data.report_type || 'sales';
  const period = data.period || 'today';
  
  // Get actual date range
  let dateRange = '';
  const periodSelect = document.getElementById('reportPeriod');
  if (periodSelect && periodSelect.value === 'custom') {
    const startDate = document.getElementById('reportStartDate')?.value || '';
    const endDate = document.getElementById('reportEndDate')?.value || '';
    if (startDate && endDate) {
      dateRange = `${formatDateForExport(startDate)} to ${formatDateForExport(endDate)}`;
    }
  } else {
    dateRange = getActualDateRange(period);
  }
  
  // Get currency symbol
  const currencySymbol = globalCurrencySymbol || window.globalCurrencySymbol || '₹';
  
  // Helper to escape CSV (handle quotes and commas)
  const escapeCsv = (str) => {
    if (str == null) return '';
    const strValue = String(str);
    // If contains comma, quote, or newline, wrap in quotes and escape quotes
    if (strValue.includes(',') || strValue.includes('"') || strValue.includes('\n')) {
      return '"' + strValue.replace(/"/g, '""') + '"';
    }
    return strValue;
  };
  
  // Helper to format currency for CSV (keep symbol and number together)
  const formatCurrencyForCsv = (amount) => {
    const formatted = parseFloat(amount || 0).toLocaleString('en-IN', {maximumFractionDigits: 0});
    return currencySymbol + formatted;
  };
  
  // Start CSV with UTF-8 BOM for proper Unicode support
  let csv = '\uFEFF'; // UTF-8 BOM
  // Add header with report info
  csv += escapeCsv(getReportTypeName(reportType)) + '\n';
  csv += 'Date Range: ' + escapeCsv(dateRange) + '\n';
  csv += 'Generated on: ' + escapeCsv(new Date().toLocaleString('en-IN', { dateStyle: 'long', timeStyle: 'short' })) + '\n';
  csv += '\n'; // Empty row
  
  // Add summary
  csv += 'Summary\n';
  csv += 'Total Sales,' + escapeCsv(formatCurrencyForCsv(data.summary?.total_sales || 0)) + '\n';
  csv += 'Total Orders,' + (data.summary?.total_orders || 0) + '\n';
  csv += 'Items Sold,' + (data.summary?.total_items || 0) + '\n';
  csv += 'Total Customers,' + (data.summary?.total_customers || 0) + '\n';
  csv += '\n'; // Empty row
  
  // Add report-specific data
  if (reportType === 'customers' && data.top_customers && data.top_customers.length > 0) {
    csv += 'Top Customers\n';
    csv += 'Customer Name,Phone,Total Orders,Last Order Date,Total Spent\n';
    data.top_customers.forEach(customer => {
      const lastOrderDate = customer.last_order_date ? new Date(customer.last_order_date).toLocaleDateString('en-IN') : '-';
      csv += escapeCsv(customer.customer_name || 'N/A') + ',';
      csv += escapeCsv(customer.phone || '-') + ',';
      csv += customer.total_orders + ',';
      csv += escapeCsv(lastOrderDate) + ',';
      csv += escapeCsv(formatCurrencyForCsv(customer.total_spent)) + '\n';
    });
  } else if (reportType === 'items' && data.top_items && data.top_items.length > 0) {
    csv += 'Top Items\n';
    csv += 'Item Name,Quantity Sold,Total Revenue\n';
    data.top_items.forEach(item => {
      csv += escapeCsv(item.item_name) + ',';
      csv += item.total_quantity + ',';
      csv += escapeCsv(formatCurrencyForCsv(item.total_revenue)) + '\n';
    });
  } else if (reportType === 'payment' && data.payment_methods && data.payment_methods.length > 0) {
    csv += 'Payment Methods Breakdown\n';
    csv += 'Payment Method,Order Count,Total Amount\n';
    data.payment_methods.forEach(method => {
      csv += escapeCsv(method.payment_method) + ',';
      csv += method.count + ',';
      csv += escapeCsv(formatCurrencyForCsv(method.amount)) + '\n';
    });
  } else if (reportType === 'hourly' && data.hourly_sales && data.hourly_sales.length > 0) {
    csv += 'Hourly Sales\n';
    csv += 'Hour,Order Count,Total Sales\n';
    data.hourly_sales.forEach(hour => {
      const hourLabel = hour.hour < 12 ? `${hour.hour}:00 AM` : hour.hour === 12 ? '12:00 PM' : `${hour.hour - 12}:00 PM`;
      csv += escapeCsv(hourLabel) + ',';
      csv += hour.order_count + ',';
      csv += escapeCsv(formatCurrencyForCsv(hour.total_sales)) + '\n';
    });
  } else if (reportType === 'staff' && data.staff_performance && data.staff_performance.length > 0) {
    csv += 'Staff Performance\n';
    csv += 'Staff Name,Total Orders,Total Sales\n';
    data.staff_performance.forEach(staff => {
      csv += escapeCsv(staff.staff_name || 'Unknown') + ',';
      csv += staff.total_orders + ',';
      csv += escapeCsv(formatCurrencyForCsv(staff.total_sales)) + '\n';
    });
  } else if (data.sales_details && data.sales_details.length > 0) {
  csv += 'Order Details\n';
    csv += 'Date,Order Number,Customer,Items,Payment Method,Amount\n';
    data.sales_details.forEach(order => {
      const orderDate = new Date(order.created_at).toLocaleDateString('en-IN');
      csv += escapeCsv(orderDate) + ',';
      csv += escapeCsv(order.order_number) + ',';
      csv += escapeCsv(order.customer_name || 'N/A') + ',';
      csv += (order.item_count || 0) + ',';
      csv += escapeCsv(order.payment_method || 'N/A') + ',';
      csv += escapeCsv(formatCurrencyForCsv(order.total)) + '\n';
    });
  }
  
  // Download CSV file
  const filename = `${reportType}_report_${new Date().toISOString().split('T')[0]}.csv`;
  
  // Create blob with UTF-8 encoding and BOM
  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
  const link = document.createElement('a');
  const url = URL.createObjectURL(blob);
  link.setAttribute('href', url);
  link.setAttribute('download', filename);
  link.style.visibility = 'hidden';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
  
  showNotification('Report exported successfully!', 'success');
}

// Helper function to get report type name
function getReportTypeName(type) {
  const names = {
    'sales': 'Sales Report',
    'customers': 'Customer Report',
    'items': 'Top Items Report',
    'payment': 'Payment Methods Report',
    'hourly': 'Hourly Sales Report',
    'staff': 'Staff Performance Report'
  };
  return names[type] || 'Report';
}

// Helper function to get period name
function getPeriodName(period) {
  const names = {
    'today': 'Today',
    'week': 'This Week',
    'month': 'This Month',
    'year': 'This Year',
    'custom': 'Custom Range'
  };
  return names[period] || period;
}

// Helper function to get actual date range
function getActualDateRange(period) {
  const today = new Date();
  let startDate, endDate;
  
  switch (period) {
    case 'today':
      startDate = new Date(today);
      endDate = new Date(today);
      break;
    case 'week':
      startDate = new Date(today);
      startDate.setDate(today.getDate() - 7);
      endDate = new Date(today);
      break;
    case 'month':
      startDate = new Date(today);
      startDate.setDate(today.getDate() - 30);
      endDate = new Date(today);
      break;
    case 'year':
      startDate = new Date(today);
      startDate.setDate(today.getDate() - 365);
      endDate = new Date(today);
      break;
    default:
      startDate = new Date(today);
      endDate = new Date(today);
  }
  
  return `${formatDateForExport(startDate.toISOString().split('T')[0])} to ${formatDateForExport(endDate.toISOString().split('T')[0])}`;
}

// Helper function to format date for export
function formatDateForExport(dateString) {
  if (!dateString) return '';
  const date = new Date(dateString + 'T00:00:00');
  return date.toLocaleDateString('en-IN', { 
    year: 'numeric', 
    month: 'long', 
    day: 'numeric' 
  });
}

// Setup reports page listener
document.addEventListener('DOMContentLoaded', function() {
  const reportsLink = document.querySelector('[data-page="reportsPage"]');
  if (reportsLink) {
    reportsLink.addEventListener('click', function() {
      setTimeout(() => {
        if (document.getElementById('reportsPage')?.classList.contains('active')) {
          setupReportsAutoReload();
          loadReports();
        }
      }, 100);
    });
  }
  
  // Setup auto-reload when reports page is shown
  setupReportsAutoReload();
  
  // Load reports if page is already active on page load
  setTimeout(() => {
    if (document.getElementById('reportsPage')?.classList.contains('active')) {
      loadReports();
    }
  }, 200);
});

// Setup auto-reload for reports when period or type changes
function setupReportsAutoReload() {
  const reportPeriod = document.getElementById('reportPeriod');
  const reportType = document.getElementById('reportType');
  
  // Add event listeners if elements exist and don't already have listeners
  if (reportPeriod && !reportPeriod.dataset.autoReloadAttached) {
    reportPeriod.dataset.autoReloadAttached = 'true';
    reportPeriod.addEventListener('change', function() {
      loadReports();
    });
  }
  
  if (reportType && !reportType.dataset.autoReloadAttached) {
    reportType.dataset.autoReloadAttached = 'true';
    reportType.addEventListener('change', function() {
      loadReports();
    });
  }
}

// Website theme (DB-based via API)
let websiteThemeInitialized = false;


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
    const sessRes = await fetch('../admin/get_session.php');
    const sess = await sessRes.json().catch(()=>null);
    if (checkSessionExpired(sessRes, sess)) return;
    const rid = (sess && sess.success && sess.data?.restaurant_id) ? sess.data.restaurant_id : '';
    const pr = document.getElementById('primaryRed');
    const dr = document.getElementById('darkRed');
    const py = document.getElementById('primaryYellow');
    const bannerUpload = document.getElementById('bannerUpload');
    const uploadBannerBtn = document.getElementById('uploadBannerBtn');
    
    // Function to render banners grid
    const renderBanners = (banners) => {
      const bannersGrid = document.getElementById('bannersGrid');
      if (!bannersGrid) {
        setTimeout(() => {
          const retryGrid = document.getElementById('bannersGrid');
          if (retryGrid) {
            renderBanners(banners);
          }
        }, 300);
        return;
      }
      
      bannersGrid.innerHTML = '';
      
      if (!banners || banners.length === 0) {
        bannersGrid.innerHTML = '<p style="color:#666;grid-column:1/-1;text-align:center;padding:20px;">No banners uploaded yet</p>';
        return;
      }
      
      banners.forEach((banner, index) => {
        let imagePath;
        const timestamp = Date.now(); // Cache-busting timestamp
        if (banner.banner_path.startsWith('db:')) {
          // Database-stored banner - add cache-busting
          imagePath = `../api/image.php?type=banner&id=${banner.id}&t=${timestamp}`;
        } else if (banner.banner_path.startsWith('http')) {
          // External URL
          imagePath = banner.banner_path + (banner.banner_path.includes('?') ? '&' : '?') + `t=${timestamp}`;
        } else {
          // File-based image (backward compatibility)
          imagePath = `../${banner.banner_path}?t=${timestamp}`;
        }
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
          <img src="${imagePath}" alt="Banner" loading="lazy" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" style="width:100%;height:auto;display:block;max-height:300px;min-height:200px;object-fit:cover;background:#f0f0f0;pointer-events:none;">
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
          e.stopPropagation();
          const bannerId = e.currentTarget.getAttribute('data-id');
          if (!(await showSweetConfirm('Are you sure you want to delete this banner?', 'Delete Banner'))) return;
          
          try {
            e.currentTarget.disabled = true;
            const sq = rid ? `?action=delete_banner&banner_id=${bannerId}&restaurant_id=${encodeURIComponent(rid)}` : `?action=delete_banner&banner_id=${bannerId}`;
            const formData = new FormData();
            formData.append('banner_id', bannerId);
            const res = await fetch(`../website/theme_api.php${sq}`, { method:'POST', body: formData });
            const data = await res.json();
            
            if (data.success) {
              showNotification('Banner deleted successfully', 'success');
              loadBanners();

      // Initialize logo shape/size UI from saved settings
      var savedLogoShape = data.settings.logo_shape || 'circle';
      var savedLogoSize = data.settings.logo_size || 90;
      var shapeBtns = document.querySelectorAll('.logo-shape-btn');
      shapeBtns.forEach(function(btn) {
        var shape = btn.getAttribute('data-shape');
        if (shape === savedLogoShape) {
          btn.style.borderColor = '#dc2626';
          btn.style.background = '#dc2626';
          btn.style.color = '#fff';
          // Check the radio
          var radio = btn.querySelector('input[name="logoShape"]');
          if (radio) radio.checked = true;
        } else {
          btn.style.borderColor = '#e5e7eb';
          btn.style.background = '#fff';
          btn.style.color = '#374151';
        }
      });
      // Init size slider
      var sizeSlider = document.getElementById('logoSizeSlider');
      var sizeDisplay = document.getElementById('logoSizeDisplay');
      if (sizeSlider) {
        sizeSlider.value = savedLogoSize;
        sizeSlider.style.background = 'linear-gradient(to right, #dc2626 0%, #dc2626 ' + ((savedLogoSize - 50) / 100 * 100) + '%, #e5e7eb ' + ((savedLogoSize - 50) / 100 * 100) + '%, #e5e7eb 100%)';
      }
      if (sizeDisplay) {
        sizeDisplay.textContent = savedLogoSize;
      }
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
      
      document.querySelectorAll('.banner-card-draggable').forEach((card, index) => {
        card.addEventListener('dragstart', (e) => {
          if (e.target.closest('.delete-banner-btn')) {
            e.preventDefault();
            return;
          }
          draggedElement = card;
          card.style.opacity = '0.5';
          e.dataTransfer.effectAllowed = 'move';
        });
        
        card.addEventListener('dragend', (e) => {
          card.style.opacity = '1';
          document.querySelectorAll('.banner-card-draggable').forEach(c => {
            c.style.border = '2px solid #ddd';
            c.style.transform = 'scale(1)';
          });
        });
        
        card.addEventListener('dragover', (e) => {
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';
          
          if (draggedElement && card !== draggedElement) {
            const cards = Array.from(bannersGrid.querySelectorAll('.banner-card-draggable'));
            const draggedIndex = cards.indexOf(draggedElement);
            const targetIndex = cards.indexOf(card);
            
            if (draggedIndex < targetIndex) {
              card.style.borderTop = '3px solid #4CAF50';
              card.style.borderBottom = '2px solid #ddd';
            } else {
              card.style.borderBottom = '3px solid #4CAF50';
              card.style.borderTop = '2px solid #ddd';
            }
            card.style.transform = 'scale(1.02)';
          }
        });
        
        card.addEventListener('dragleave', (e) => {
          card.style.border = '2px solid #ddd';
          card.style.transform = 'scale(1)';
        });
        
        card.addEventListener('drop', async (e) => {
          e.preventDefault();
          e.stopPropagation();
          
          if (draggedElement && card !== draggedElement) {
            const cards = Array.from(bannersGrid.querySelectorAll('.banner-card-draggable'));
            const draggedIndex = cards.indexOf(draggedElement);
            const targetIndex = cards.indexOf(card);
            
            if (draggedIndex < targetIndex) {
              bannersGrid.insertBefore(draggedElement, card.nextSibling);
            } else {
              bannersGrid.insertBefore(draggedElement, card);
            }
            
            const newOrder = Array.from(bannersGrid.querySelectorAll('.banner-card-draggable')).map(c => 
              parseInt(c.getAttribute('data-banner-id'))
            );
            
            try {
              const sq = rid ? `?action=reorder_banners&restaurant_id=${encodeURIComponent(rid)}` : '?action=reorder_banners';
              const res = await fetch(`../website/theme_api.php${sq}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ banner_ids: newOrder })
              });
              const data = await res.json();
              
              if (data.success) {
                showNotification('Banner order updated successfully', 'success');
                const updatedCards = Array.from(bannersGrid.querySelectorAll('.banner-card-draggable'));
                updatedCards.forEach((c, idx) => {
                  const orderDiv = c.querySelector('div[style*="Order:"]');
                  if (orderDiv) {
                    orderDiv.textContent = `Order: ${idx + 1}`;
                  }
                });
              } else {
                showNotification(data.message || 'Failed to update banner order', 'error');
                loadBanners();
              }
            } catch (err) {
              showNotification('Network error. Please try again.', 'error');
              loadBanners();
            }
          }
          
          card.style.border = '2px solid #ddd';
          card.style.transform = 'scale(1)';
        });
      });
    };
    
    // Function to load banners
    const loadBanners = async () => {
      try {
        const q = rid ? `?action=get_banners&restaurant_id=${encodeURIComponent(rid)}` : '?action=get_banners';
        const res = await fetch(`../website/theme_api.php${q}`);
        const data = await res.json();
        if (data.success) {
          renderBanners(data.banners || []);
        } else {
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


function populateBgThemeGrid(selectedUrl) {
  var grid = document.getElementById('backgroundThemeGrid');
  if (!grid) return;
  
  // Start with default (komododecks) as the first option so people can reselect it
  var themes = [
    { name: "Default Image", url: "https://plain-apac-prod-public.komododecks.com/202607/06/B1IA4E4QcKAeMAwsaYkq/image.png" },
    { name: "Food Platter", url: "https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=800&q=80" },
    { name: "Dark Kitchen", url: "https://images.unsplash.com/photo-1555396273-367ea4eb4db5?w=800&q=80" },
    { name: "Fresh Greens", url: "https://images.unsplash.com/photo-1498837167922-ddd27525d352?w=800&q=80" },
    { name: "Warm Interior", url: "https://images.unsplash.com/photo-1414235077428-338989a2e8c0?w=800&q=80" },
    { name: "Coffee Shop", url: "https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=800&q=80" },
    { name: "Burger", url: "https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=800&q=80" },
    { name: "Pizza", url: "https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=800&q=80" },
    { name: "Sweets", url: "https://images.unsplash.com/photo-1551024601-bec78aea704b?w=800&q=80" },
  ];
  
  // Add any custom themes that were added via "Apply" (stored in window.customBgThemes)
  if (window.customBgThemes && window.customBgThemes.length > 0) {
    window.customBgThemes.forEach(function(custom) {
      // Don't add duplicates
      var exists = themes.some(function(t) { return t.url === custom.url; });
      if (!exists) {
        themes.push(custom);
      }
    });
  }
  
  // Add "Clear (None)" at the end
  themes.push({ name: "Clear (None)", url: "" });
  
  grid.innerHTML = themes.map(function(t) {
    var isSelected = t.url && t.url === selectedUrl;
    var isNone = t.url === "";
    var previewBg = isNone ? "linear-gradient(135deg, #2d3436, #636e72)" : "url(" + t.url + ")";
    var borderColor = isSelected ? "#8b5cf6" : "#e5e7eb";
    var boxShadow = isSelected ? "0 0 0 2px #8b5cf6, 0 4px 12px rgba(139,92,246,0.3)" : "0 2px 4px rgba(0,0,0,0.06)";
    var shortUrl = t.url ? t.url.substring(0, 40) + (t.url.length > 40 ? '...' : '') : '';
    return "<div data-url='" + t.url + "' title='" + (t.url || 'No background') + "' style='cursor:pointer;border-radius:10px;overflow:hidden;border:3px solid " + borderColor + ";transition:all 0.2s;box-shadow:" + boxShadow + ";'>" +
      "<div style='height:70px;background:" + previewBg + ";background-size:cover;background-position:center'></div>" +
      "<div style='padding:6px 8px;font-size:11px;font-weight:600;color:#374151;text-align:center;background:#fff'>" + (isNone ? "No Background" : t.name) + "</div>" +
      (t.url ? "<div style='padding:2px 8px 4px;font-size:8px;color:#9ca3af;text-align:center;background:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap' title='" + t.url + "'>" + shortUrl + "</div>" : "") +
    "</div>";
  }).join("");
  
  // Attach click handlers via event delegation
  grid.onclick = function(e) {
    var target = e.target.closest('[data-url]');
    if (target) {
      var url = target.getAttribute('data-url');
      window.selectBgTheme(url);
    }
  };
}

window.selectBgTheme = function(url) {
  var input = document.getElementById('backgroundThemeInput');
  var customInput = document.getElementById('customBackgroundUrl');
  if (input) input.value = url;
  if (customInput) customInput.value = url;
  if (typeof populateBgThemeGrid === 'function') {
    populateBgThemeGrid(url);
  }
};

// Initialize custom themes array
if (!window.customBgThemes) {
  window.customBgThemes = [];
}

// Load current theme settings from server and populate the background grid
(async () => {
  try {
    const q = rid ? '?action=get&restaurant_id=' + encodeURIComponent(rid) : '?action=get';
    const res = await fetch('../website/theme_api.php' + q);
    const data = await res.json();
    if (data.success && data.settings) {
      // Set color picker values from saved settings
      if (pr && data.settings.primary_red) pr.value = data.settings.primary_red;
      if (dr && data.settings.dark_red) dr.value = data.settings.dark_red;
      if (py && data.settings.primary_yellow) py.value = data.settings.primary_yellow;
      
      // Update color previews
      updateColorPreviews();
      
      // Set background theme URL
      const bgUrl = data.settings.background_theme || '';
      const bgInput = document.getElementById('backgroundThemeInput');
      if (bgInput) bgInput.value = bgUrl;
      const customBgInput = document.getElementById('customBackgroundUrl');
      if (customBgInput) customBgInput.value = bgUrl;
      
      // Add loaded theme to customBgThemes so it appears in grid after refresh
      if (bgUrl && bgUrl.indexOf('data:') === 0) {
        if (!window.customBgThemes) window.customBgThemes = [];
        var exists = window.customBgThemes.some(function(t) { return t.url === bgUrl; });
        if (!exists) {
          window.customBgThemes.push({ name: 'Uploaded Background', url: bgUrl });
        }
      }
      
      // Populate the background grid with presets + current selection
      populateBgThemeGrid(bgUrl);
      
      // Load banners
      loadBanners();
    } else {
      // API returned but no settings - still show the grid with defaults
      populateBgThemeGrid('');
    }
  } catch (e) {
    console.error('Error loading theme settings:', e);
    // Even if API fails, show the grid with default presets
    populateBgThemeGrid('');
  }
})();

// Handle Upload Background button click: upload image and save directly
  var uploadBtn = document.getElementById('uploadBackgroundImageBtn');
  var bgFileInput = document.getElementById('backgroundImageUpload');
  var statusEl = document.getElementById('bgUploadStatus');
  var initKey = '_bgUploadInit2';
  
  if (uploadBtn && bgFileInput && !window[initKey]) {
    window[initKey] = true;
    uploadBtn.addEventListener('click', async function() {
      if (!bgFileInput.files || bgFileInput.files.length === 0) {
        showToastFallback('Please select an image file first.', 'error');
        return;
      }
      var file = bgFileInput.files[0];
      if (file.size > 5 * 1024 * 1024) {
        showToastFallback('File too large (max 5MB).', 'error');
        return;
      }
      if (statusEl) statusEl.textContent = 'Uploading...';
      uploadBtn.disabled = true;
      uploadBtn.textContent = 'Uploading...';
      
      var formData = new FormData();
      formData.append('background_image', file);
      var sq = '?action=upload_background&restaurant_id=' + (rid || 'RES001');
      
      try {
        var res = await fetch('../website/theme_api.php' + sq, { method: 'POST', body: formData });
        var data = await res.json();
        if (data.success) {
          var url = data.background_url;
          if (!window.customBgThemes) window.customBgThemes = [];
          if (!window.customBgThemes.some(function(t) { return t.url === url; })) {
            window.customBgThemes.push({ name: 'Uploaded', url: url });
          }
          window.selectBgTheme(url);
          if (statusEl) statusEl.textContent = 'Background saved!';
          showToastFallback('Background image uploaded & saved!', 'success');
          bgFileInput.value = '';
        } else {
          if (statusEl) statusEl.textContent = 'Upload failed: ' + (data.message || 'Unknown error');
          showToastFallback('Upload failed: ' + (data.message || 'Unknown error'), 'error');
        }
      } catch (e) {
        if (statusEl) statusEl.textContent = 'Upload error: ' + e.message;
        showToastFallback('Upload error: ' + e.message, 'error');
      }
      uploadBtn.disabled = false;
      uploadBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size: 1rem; vertical-align: middle;">upload</span> Upload & Save';
    });
  }
    // Upload banners
    if (uploadBannerBtn && bannerUpload && uploadBannerBtn.parentNode) {
      // Clone button to remove old listeners
      const newUploadBtn = uploadBannerBtn.cloneNode(true);
      uploadBannerBtn.parentNode.replaceChild(newUploadBtn, uploadBannerBtn);
      
      newUploadBtn.addEventListener('click', async () => {
        if (!bannerUpload || !bannerUpload.files || bannerUpload.files.length === 0) {
          showNotification('Please select at least one image file', 'error');
          return;
        }
        
        const formData = new FormData();
        Array.from(bannerUpload.files).forEach((file) => {
          formData.append('banners[]', file);
        });
        
        const sq = rid ? `?action=upload_banner&restaurant_id=${encodeURIComponent(rid)}` : '?action=upload_banner';
        
        try {
          newUploadBtn.disabled = true;
          newUploadBtn.innerHTML = '<span class="material-symbols-rounded">upload</span>Uploading...';
          const res = await fetch(`../website/theme_api.php${sq}`, { method:'POST', body: formData });
          
          if (!res.ok) {
            throw new Error(`HTTP error! status: ${res.status}`);
          }
          
          const data = await res.json();
          
          if (data.success) {
            const count = data.banners ? data.banners.length : 1;
            let message = `${count} banner(s) uploaded successfully`;
            
            // Show warnings if any files failed
            if (data.warnings && data.warnings.length > 0) {
              message += `. Some files failed: ${data.warnings.join(', ')}`;
              showNotification(message, 'warning');
            } else {
              showNotification(message, 'success');
            }
            
            bannerUpload.value = '';
            loadBanners();
          } else {
            showNotification(data.message || 'Upload failed', 'error');
          }
        } catch (e) {
          console.error('Banner upload error:', e);
          if (e.message && e.message.includes('HTTP error')) {
            showNotification('Server error. Please check file size and try again.', 'error');
          } else {
            showNotification('Network error. Please try again.', 'error');
          }
        } finally {
          newUploadBtn.disabled = false;
          newUploadBtn.innerHTML = '<span class="material-symbols-rounded">upload</span>Upload Banners';
        }
      });
    }
    
    // Mark as initialized
    // Save theme settings (colors, layout, logo shape/size)
    var saveThemeBtn = document.getElementById('saveWebsiteThemeBtn');
    if (saveThemeBtn && !saveThemeBtn.dataset.handlerAttached) {
      saveThemeBtn.dataset.handlerAttached = '1';
      saveThemeBtn.addEventListener('click', async function() {
        var btn = this;
        var origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="loading-spinner"></span> Saving...';

        try {
          var pr = document.getElementById('primaryRed');
          var dr = document.getElementById('darkRed');
          var py = document.getElementById('primaryYellow');
          var bgInput = document.getElementById('backgroundThemeInput');
          var lcRadios = document.querySelectorAll('input[name="layoutColumns"]');
          var lcVal = 2;
          for (var ri = 0; ri < lcRadios.length; ri++) {
            if (lcRadios[ri].checked) { lcVal = parseInt(lcRadios[ri].value); break; }
          }
          // Get logo shape
          var logoShape = 'circle';
          var shapeRadios = document.querySelectorAll('input[name="logoShape"]');
          for (var si = 0; si < shapeRadios.length; si++) {
            if (shapeRadios[si].checked) { logoShape = shapeRadios[si].value; break; }
          }
          // Get logo size
          var sizeSlider = document.getElementById('logoSizeSlider');
          var logoSize = sizeSlider ? parseInt(sizeSlider.value) : 90;

          var rid = document.querySelector('meta[name=restaurant-id]') ? 
            document.querySelector('meta[name=restaurant-id]').content : null;
          if (!rid) {
            rid = document.getElementById('restaurantId') ? document.getElementById('restaurantId').textContent.trim() : null;
          }
          var sq = rid ? '?action=save&restaurant_id=' + encodeURIComponent(rid) : '?action=save';

          var payload = {
            primary_red: pr ? pr.value : '#F70000',
            dark_red: dr ? dr.value : '#DA020E',
            primary_yellow: py ? py.value : '#FFD100',
            banner_image: null,
            layout_columns: lcVal,
            background_theme: bgInput ? bgInput.value : null,
            logo_shape: logoShape,
            logo_size: logoSize
          };

          var res = await fetch('../website/theme_api.php' + sq, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
          });
          var data = await res.json();

          if (data.success) {
            if (window.showNotification) {
              window.showNotification('Theme settings saved!', 'success');
            } else if (window.showSweetAlert) {
              window.showSweetAlert('Theme settings saved!', 'success', { timer: 2000, showConfirmButton: false });
            } else {
              alert('Theme settings saved!');
            }
          } else {
            var errMsg = data.message || 'Save failed';
            if (window.showNotification) {
              window.showNotification(errMsg, 'error');
            } else {
              alert(errMsg);
            }
          }
        } catch (e) {
          console.error('Save theme error:', e);
          if (window.showNotification) {
            window.showNotification('Error saving theme settings', 'error');
          } else {
            alert('Error saving theme settings');
          }
        } finally {
          btn.disabled = false;
          btn.innerHTML = origHtml;
        }
      });
    }

    // Mark as initialized
  } catch (e) { 
    console.error('Theme init error', e); 
  }
}

document.addEventListener('DOMContentLoaded', function(){
  // Check if websiteThemePage is already active on load
  const websiteThemePage = document.getElementById('websiteThemePage');
  if (websiteThemePage && websiteThemePage.classList.contains('active')) {
    // Enable zoom if website appearance page is active on load
    const viewport = document.querySelector('meta[name="viewport"]');
    if (viewport) {
      viewport.setAttribute('content', 'width=device-width, initial-scale=1.0, minimum-scale=0.5, maximum-scale=5.0, user-scalable=yes');
    }
    setTimeout(() => {
      initWebsiteThemeEditor();
    }, 300);
  }
  
  // Also listen for navigation clicks
  const themeNav = document.querySelector('[data-page="websiteThemePage"]');
  if (themeNav) {
    themeNav.addEventListener('click', () => {
      setTimeout(() => {
        initWebsiteThemeEditor();
      }, 120);
    });
  }
});

// Copy Restaurant Website Link
function copyRestaurantLink() {
  const linkInput = document.getElementById('restaurantWebsiteLink');
  if (linkInput) {
    linkInput.select();
    linkInput.setSelectionRange(0, 99999); // For mobile devices
    try {
      document.execCommand('copy');
      showNotification('Restaurant link copied to clipboard!', 'success');
    } catch (err) {
      // Fallback for modern browsers
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(linkInput.value).then(() => {
          showNotification('Restaurant link copied to clipboard!', 'success');
        }).catch(() => {
          showNotification('Failed to copy link. Please copy manually.', 'error');
        });
      } else {
        showNotification('Failed to copy link. Please copy manually.', 'error');
      }
    }
  }
}

// Toggle Profile Edit
function toggleProfileEdit() {
  const editCard = document.getElementById('editProfileCard');
  const editBtn = document.getElementById('editProfileBtn');
  
  if (!editCard || !editBtn) return;
  
  if (editCard.style.display === 'none' || !editCard.style.display) {
    editCard.style.display = 'block';
    editBtn.innerHTML = '<span class="material-symbols-rounded">close</span> Cancel Edit';
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
  
  if (!editCard || !editBtn) return;
  
  editCard.style.display = 'none';
  editBtn.innerHTML = '<span class="material-symbols-rounded">edit</span> Edit Profile';
  editBtn.onclick = toggleProfileEdit;
  editBtn.classList.remove('btn-cancel');
  editBtn.classList.add('btn-primary');
  
  // Reset form values
  loadProfileData();
}

// Open Logo Upload Modal
function openLogoUploadModal() {
  const modal = document.getElementById('logoUploadModal');
  if (modal) {
    modal.style.display = 'block';
    // Load current logo if available
    const currentLogo = document.getElementById('profileRestaurantLogo');
    if (currentLogo && currentLogo.src) {
      const preview = document.getElementById('logoPreview');
      if (preview) {
        preview.innerHTML = `<img src="${currentLogo.src}" alt="Current Logo" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`;
      }
    }
  }
}

// Close Logo Upload Modal
function closeLogoUploadModal() {
  const modal = document.getElementById('logoUploadModal');
  if (modal) {
    modal.style.display = 'none';
    // Reset file input
    const fileInput = document.getElementById('logoFileInput');
    if (fileInput) fileInput.value = '';
    // Reset preview
    const preview = document.getElementById('logoPreview');
    if (preview) {
      preview.innerHTML = '<span class="material-symbols-rounded" style="font-size:3rem;color:#9ca3af;">image</span>';
    }
    // Reset save button
    const saveBtn = document.getElementById('saveLogoBtn');
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.innerHTML = '<span class="material-symbols-rounded">save</span> Save Logo';
    }
    selectedLogoFile = null;
  }
}

// Global variable to store selected logo file
let selectedLogoFile = null;

// Handle Logo File Select
function handleLogoFileSelect(event) {
  const file = event.target.files[0];
  if (!file) return;
  
  // Validate file type
  if (!file.type.startsWith('image/')) {
    showNotification('Please select an image file', 'error');
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
    if (preview) {
      preview.innerHTML = `<img src="${e.target.result}" alt="Preview" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`;
    }
    // Enable save button
    const saveBtn = document.getElementById('saveLogoBtn');
    if (saveBtn) {
      saveBtn.disabled = false;
    }
  };
  
  reader.readAsDataURL(file);
}

// Upload Restaurant Logo
async function uploadRestaurantLogo() {
  if (!selectedLogoFile) {
    showNotification('Please select an image first', 'error');
    return;
  }
  
  const formData = new FormData();
  formData.append('action', 'uploadRestaurantLogo');
  formData.append('logo', selectedLogoFile);
  
  const saveBtn = document.getElementById('saveLogoBtn');
  const originalText = saveBtn ? saveBtn.innerHTML : '';
  if (saveBtn) {
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Uploading...';
  }
  
  try {
    const response = await fetch('../admin/auth.php', {
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
      if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
      }
    }
  } catch (error) {
    console.error('Error uploading logo:', error);
    showNotification('Network error. Please try again.', 'error');
    if (saveBtn) {
      saveBtn.disabled = false;
      saveBtn.innerHTML = originalText;
    }
  }
}

// Setup Edit Profile Form Handler
document.addEventListener('DOMContentLoaded', function() {
  const editProfileForm = document.getElementById('editProfileForm');
  if (editProfileForm) {
    editProfileForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      
      const username = document.getElementById('editUsername')?.value.trim();
      const email = document.getElementById('editEmail')?.value.trim();
      
      if (!username || !email) {
        showNotification('Please fill in all fields', 'error');
        return;
      }
      
      try {
        const response = await fetch('../admin/auth.php', {
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
  
  // Close logo modal when clicking outside
  const logoUploadModal = document.getElementById('logoUploadModal');
  if (logoUploadModal) {
    logoUploadModal.addEventListener('click', function(e) {
      if (e.target === logoUploadModal) {
        closeLogoUploadModal();
      }
    });
  }
});

/* ============ Coupon Management ============ */

function closeModal(modalId) {
  const el = document.getElementById(modalId);
  if (el) { el.style.display = 'none'; document.body.style.overflow = ''; }
}

async function loadAdminCoupons() {
  const tbody = document.getElementById('couponsTbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>';
  try {
    const r = await fetch('../api/get_admin_coupons.php');
    const d = await r.json();
    if (!d.success) { tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#e74c3c;">' + d.message + '</td></tr>'; return; }
    const coupons = d.coupons || [];
    if (!coupons.length) { tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;color:#999;">No coupons found. Click "+ New Coupon" to create one.</td></tr>'; return; }
    tbody.innerHTML = coupons.map(c => {
      const now = new Date();
      const validFrom = c.valid_from ? new Date(c.valid_from + 'T00:00:00') : null;
      const validUntil = c.valid_until ? new Date(c.valid_until + 'T23:59:59') : null;
      let expired = false;
      if (validUntil && validUntil < now) expired = true;
      if (validFrom && validFrom > now) expired = true;

      const typeLabel = c.discount_type === 'percent' ? '%' : 'Flat';
      const valueDisplay = c.discount_type === 'percent' ? c.discount_value + '%' : globalCurrencySymbol + parseFloat(c.discount_value).toFixed(2);
      const minDisplay = parseFloat(c.minimum_order_amount) > 0 ? globalCurrencySymbol + parseFloat(c.minimum_order_amount).toFixed(2) : '-';
      const usageDisplay = c.max_uses > 0 ? c.current_uses + '/' + c.max_uses : c.current_uses;
      const validDisplay = (c.valid_from || '-') + ' → ' + (c.valid_until || '-');
      const statusClass = c.is_active == 1 && !expired ? 'badge badge-success' : 'badge badge-danger';
      const statusText = c.is_active == 1 ? (expired ? 'Expired' : 'Active') : 'Inactive';
      return '<tr>' +
        '<td><strong>' + escHtml(c.coupon_code) + '</strong></td>' +
        '<td>' + typeLabel + '</td>' +
        '<td>' + valueDisplay + '</td>' +
        '<td>' + minDisplay + '</td>' +
        '<td>' + usageDisplay + '</td>' +
        '<td style="font-size:0.85em;">' + validDisplay + '</td>' +
        '<td>' + (c.created_at ? c.created_at.split(' ')[0] : '-') + '</td>' +
        '<td><span class="' + statusClass + '">' + statusText + '</span></td>' +
        '<td class="actions-cell">' +
          '<button class="btn btn-sm btn-secondary" onclick="editCoupon(' + c.id + ')" title="Edit">✏️</button> ' +
          '<button class="btn btn-sm btn-' + (c.is_active == 1 ? 'warning' : 'success') + '" onclick="toggleCoupon(' + c.id + ',' + (c.is_active == 1 ? 0 : 1) + ')" title="' + (c.is_active == 1 ? 'Deactivate' : 'Activate') + '">' + (c.is_active == 1 ? '🚫' : '✅') + '</button> ' +
          '<button class="btn btn-sm btn-danger" onclick="deleteCoupon(' + c.id + ')" title="Delete">🗑️</button>' +
        '</td>' +
        '</tr>';
    }).join('');
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#e74c3c;">Error loading coupons</td></tr>';
  }
}

function openCouponModal(data) {
  const modal = document.getElementById('couponModal');
  const title = document.getElementById('couponModalTitle');
  const idInput = document.getElementById('couponId');
  const codeInput = document.getElementById('cpnCode');
  const typeSelect = document.getElementById('cpnType');
  const valueInput = document.getElementById('cpnValue');
  const descInput = document.getElementById('cpnDesc');
  const minOrderInput = document.getElementById('cpnMinOrder');
  const maxUsesInput = document.getElementById('cpnMaxUses');
  const validFromInput = document.getElementById('cpnValidFrom');
  const validUntilInput = document.getElementById('cpnValidUntil');
  const saveBtn = document.getElementById('cpnSaveBtn');
  if (!modal) return;
  if (data && data.id) {
    title.textContent = 'Edit Coupon';
    idInput.value = data.id;
    codeInput.value = data.coupon_code || '';
    typeSelect.value = data.discount_type || 'percent';
    valueInput.value = data.discount_value || '';
    descInput.value = data.description || '';
    minOrderInput.value = data.minimum_order_amount || 0;
    maxUsesInput.value = data.max_uses || 0;
    validFromInput.value = data.valid_from || '';
    validUntilInput.value = data.valid_until || '';
    saveBtn.textContent = 'Update Coupon';
  } else {
    title.textContent = 'New Coupon';
    idInput.value = '';
    codeInput.value = '';
    typeSelect.value = 'percent';
    valueInput.value = '';
    descInput.value = '';
    minOrderInput.value = 0;
    maxUsesInput.value = 0;
    validFromInput.value = '';
    validUntilInput.value = '';
    saveBtn.textContent = 'Save Coupon';
  }
  modal.style.display = 'block';
  document.body.style.overflow = 'hidden';
  setTimeout(() => { codeInput.focus(); }, 150);
}

async function saveCoupon(event) {
  event.preventDefault();
  const id = document.getElementById('couponId').value;
  const code = document.getElementById('cpnCode').value.trim().toUpperCase();
  const type = document.getElementById('cpnType').value;
  const value = document.getElementById('cpnValue').value;
  const desc = document.getElementById('cpnDesc').value.trim();
  const minOrder = document.getElementById('cpnMinOrder').value;
  const maxUses = document.getElementById('cpnMaxUses').value;
  const validFrom = document.getElementById('cpnValidFrom').value;
  const validUntil = document.getElementById('cpnValidUntil').value;
  const saveBtn = document.getElementById('cpnSaveBtn');
  if (!code) { showSweetAlert('Please enter a coupon code.', 'warning'); return; }
  if (!value || parseFloat(value) <= 0) { showSweetAlert('Please enter a valid discount value.', 'warning'); return; }
  const action = id ? 'update' : 'add';
  saveBtn.disabled = true; saveBtn.textContent = 'Saving...';
  const formData = new FormData();
  formData.append('action', action);
  formData.append('coupon_code', code);
  formData.append('discount_type', type);
  formData.append('discount_value', value);
  formData.append('description', desc);
  formData.append('minimum_order_amount', minOrder);
  formData.append('max_uses', maxUses);
  formData.append('valid_from', validFrom);
  formData.append('valid_until', validUntil);
  if (id) formData.append('id', id);
  try {
    const r = await fetch('../controllers/coupon_operations.php', { method: 'POST', body: formData });
    const d = await r.json();
    if (d.success) {
      showSweetAlert(d.message, 'success');
      closeModal('couponModal');
      loadAdminCoupons();
    } else {
      showSweetAlert(d.message, 'error');
    }
  } catch (e) {
    showSweetAlert('Network error. Please try again.', 'error');
  } finally {
    saveBtn.disabled = false; saveBtn.textContent = id ? 'Update Coupon' : 'Save Coupon';
  }
  return false;
}

async function editCoupon(id) {
  try {
    const r = await fetch('../api/get_admin_coupons.php');
    const d = await r.json();
    if (d.success) {
      const coupon = (d.coupons || []).find(c => c.id == id);
      if (coupon) openCouponModal(coupon);
      else showSweetAlert('Coupon not found.', 'error');
    }
  } catch (e) { showSweetAlert('Error loading coupon.', 'error'); }
}

async function toggleCoupon(id, newStatus) {
  const formData = new FormData();
  formData.append('action', 'toggle');
  formData.append('id', id);
  formData.append('is_active', newStatus);
  try {
    const r = await fetch('../controllers/coupon_operations.php', { method: 'POST', body: formData });
    const d = await r.json();
    if (d.success) { showSweetAlert(d.message, 'success'); loadAdminCoupons(); }
    else showSweetAlert(d.message, 'error');
  } catch (e) { showSweetAlert('Network error.', 'error'); }
}

async function deleteCoupon(id) {
  const result = await Swal.fire({ title: 'Delete Coupon?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', cancelButtonText: 'Cancel', confirmButtonColor: '#e74c3c' });
  if (!result.isConfirmed) return;
  const formData = new FormData();
  formData.append('action', 'delete');
  formData.append('id', id);
  try {
    const r = await fetch('../controllers/coupon_operations.php', { method: 'POST', body: formData });
    const d = await r.json();
    if (d.success) { showSweetAlert(d.message, 'success'); loadAdminCoupons(); }
    else showSweetAlert(d.message, 'error');
  } catch (e) { showSweetAlert('Network error.', 'error'); }
}

function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

// Outside-click handler for coupon modal
document.addEventListener('DOMContentLoaded', function() {
  const couponModal = document.getElementById('couponModal');
  if (couponModal) {
    couponModal.addEventListener('click', function(e) {
      if (e.target === couponModal) closeModal('couponModal');
    });
  }
});




// ========== New Order Notification Test ==========
// Call window.testNewOrderPopup() from browser console to test the overlay
(function() {
  window.testNewOrderPopup = function() {
    console.log('[Notif] Testing new order popup...');
    var overlay = document.getElementById('newOrderOverlay');
    if (!overlay) {
      console.error('[Notif] ERROR: #newOrderOverlay element not found in DOM!');
      alert('ERROR: Overlay element not found. The HTML was not added to dashboard.php properly.');
      return;
    }
    console.log('[Notif] Overlay element found:', overlay);
    
    // Populate with sample data
    var mockOrder = {
      id: 999,
      order_number: 'TEST-999',
      customer_name: 'Test Customer',
      customer_phone: '+91 98765 43210',
      customer_address: '123 Test Street, Bangalore',
      order_type: 'delivery',
      payment_method: 'UPI',
      payment_status: 'Pending',
      total: '499.00',
      subtotal: '450.00',
      created_at: new Date().toISOString(),
      item_count: 3,
      items: [
        { item_name: 'Butter Chicken', quantity: 2, total_price: 340 },
        { item_name: 'Naan', quantity: 3, total_price: 90 },
        { item_name: 'Gulab Jamun', quantity: 2, total_price: 69 }
      ]
    };
    
    // Try to call the real showNewOrderPopup function (via window)
    if (typeof window.showNewOrderPopup === 'function') {
      window.showNewOrderPopup(mockOrder);
    } else if (typeof showNewOrderPopup === 'function') {
      showNewOrderPopup(mockOrder);
    } else {
      var el = document.getElementById('notifOrderNumber');
      if (el) el.textContent = 'Order #TEST-999';
      el = document.getElementById('notifCustomerName');
      if (el) el.textContent = 'Test Customer';
      el = document.getElementById('notifCustomerPhone');
      if (el) el.textContent = '+91 98765 43210';
      el = document.getElementById('notifOrderType');
      if (el) el.textContent = 'Delivery';
      el = document.getElementById('notifPaymentInfo');
      if (el) el.textContent = 'UPI (Pending)';
      el = document.getElementById('notifTotalAmount');
      if (el) el.textContent = (window.globalCurrencySymbol || '\u20b9') + '499.00';
      if (overlay) {
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
      }
    }
  };
  
  // Also check if the overlay is in the DOM on load
  window.addEventListener('DOMContentLoaded', function() {
    var el = document.getElementById('newOrderOverlay');
    if (el) {
      console.log('[Notif] ✓ Overlay element found in DOM on load');
    } else {
      console.warn('[Notif] ✗ Overlay element NOT found in DOM on load');
    }
    
    // Check if our polling code compiled
    if (typeof window._newOrderPollInterval !== 'undefined') {
      console.log('[Notif] ✓ Polling interval is set up');
    } else {
      console.log('[Notif] Polling not set up yet (will be when showPage runs)');
    }
  });
  
  console.log('[Notif] Test function available: window.testNewOrderPopup()');

window.showTableMapDetail = function(tableId) {
  if (window.Swal) {
    var tableData = null;
    // Try to get table data from the API response cached in window
    var allTables = window._tableMapData || [];
    var table = allTables.find(function(t) { return t.id === tableId; });
    if (!table) return;
    
    var statusColors = { free: '#22c55e', occupied: '#ef4444', reserved: '#eab308' };
    var statusIcons = { free: 'check_circle', occupied: 'block', reserved: 'event_busy' };
    
    Swal.fire({
      title: 'Table ' + table.table_number,
      html: '<div style="text-align:left;font-size:14px;">'
        + '<div style="display:flex;gap:12px;margin-bottom:16px;">'
        + '<div style="width:60px;height:60px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#fff;background:' + (table.status === 'free' ? 'linear-gradient(135deg,#22c55e,#16a34a)' : table.status === 'occupied' ? 'linear-gradient(135deg,#ef4444,#dc2626)' : 'linear-gradient(135deg,#eab308,#ca8a04)') + ';">' + escapeHtml(table.table_number) + '</div>'
        + '<div style="flex:1;">'
        + '<div style="font-weight:700;font-size:18px;margin-bottom:4px;">Table ' + escapeHtml(table.table_number) + '</div>'
        + '<div style="color:#6b7280;font-size:13px;">' + escapeHtml(table.area_name) + ' &middot; Capacity: ' + table.capacity + '</div>'
        + '</div></div>'
        + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;">'
        + '<div style="background:#f9fafb;border-radius:8px;padding:10px;text-align:center;"><div style="font-size:18px;font-weight:700;color:' + (statusColors[table.status] || '#6b7280') + ';">' + table.status.charAt(0).toUpperCase() + table.status.slice(1) + '</div><div style="font-size:11px;color:#6b7280;margin-top:2px;">Status</div></div>'
        + '<div style="background:#f9fafb;border-radius:8px;padding:10px;text-align:center;"><div style="font-size:18px;font-weight:700;">' + table.capacity + '</div><div style="font-size:11px;color:#6b7280;margin-top:2px;">Seats</div></div>'
        + '</div>'
        + (table.customer_name ? '<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px;margin-bottom:12px;text-align:center;font-size:13px;"><strong>Customer:</strong> ' + escapeHtml(table.customer_name) + '</div>' : '')
        + (table.order_timer ? '<div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:10px;margin-bottom:12px;text-align:center;font-size:13px;color:#b91c1c;"><strong>⏱ Active for:</strong> ' + escapeHtml(table.order_timer) + '</div>' : '')
        + '</div>',
      showCancelButton: table.status === 'free',
      confirmButtonText: table.status === 'free' ? '<span class="material-symbols-rounded" style="font-size:16px;vertical-align:middle;">add_shopping_cart</span> Start Order' : 'OK',
      confirmButtonColor: '#22c55e',
      cancelButtonText: 'Cancel',
      cancelButtonColor: '#6b7280',
      reverseButtons: true,
      didOpen: function() {
        // Optional: add custom listeners
      }
    }).then(function(result) {
      if (result.isConfirmed && table.status === 'free') {
        // Navigate to POS with this table selected
        showPage('posPage');
        // Try to set the table in POS
        var tableSelect = document.getElementById('posTableSelect');
        if (tableSelect) {
          for (var i = 0; i < tableSelect.options.length; i++) {
            if (tableSelect.options[i].value == table.id) {
              tableSelect.value = table.id;
              tableSelect.dispatchEvent(new Event('change'));
              break;
            }
          }
        }
      }
    });
  }
};

// Cache table data for popup use
window._tableMapData = null;
window.renderTableMap = async function() {
  var container = document.getElementById('tableMapContainer');
  if (!container) return;
  
  container.innerHTML = '<div class="loading" style="color:#94a3b8;text-align:center;padding:60px 20px;"><div style="font-size:40px;margin-bottom:16px;">🗺️</div><div style="font-size:18px;font-weight:600;margin-bottom:8px;">Loading Table Map...</div></div>';
  
  try {
    var res = await fetch('../api/get_table_status.php');
    var data = await res.json();
    if (!data.success || !data.data) {
      container.innerHTML = '<div class="empty-state" style="color:#94a3b8;padding:40px;"><span class="material-symbols-rounded" style="font-size:48px;display:block;margin-bottom:12px;">error</span><h3>Failed to load table data</h3></div>';
      return;
    }
    
    // Cache the data for the detail popup
    window._tableMapData = data.data;
    
    var tables = data.data;
    var areas = data.areas || [];
    
    if (tables.length === 0) {
      container.innerHTML = '<div class="empty-state" style="color:#94a3b8;padding:60px;"><span class="material-symbols-rounded" style="font-size:48px;display:block;margin-bottom:12px;">table_restaurant</span><h3>No tables found</h3><p style="margin-top:8px;">Add tables in the Tables section first.</p></div>';
      return;
    }
    
    // Count stats
    var free = 0, occupied = 0, reserved = 0;
    tables.forEach(function(t) {
      if (t.status === 'free') free++;
      else if (t.status === 'occupied') occupied++;
      else if (t.status === 'reserved') reserved++;
    });
    
    // Update stats in header if they exist
    var els = document.querySelectorAll('.table-map-stat');
    // Simple inline stats at top of the map
    var statsHtml = '<div style="display:flex;gap:16px;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid #334155;">';
    statsHtml += '<span style="display:flex;align-items:center;gap:6px;font-size:13px;color:#94a3b8;"><span style="width:10px;height:10px;border-radius:50%;background:#22c55e;display:inline-block;"></span> Free: <strong style="color:#e2e8f0;">' + free + '</strong></span>';
    statsHtml += '<span style="display:flex;align-items:center;gap:6px;font-size:13px;color:#94a3b8;"><span style="width:10px;height:10px;border-radius:50%;background:#ef4444;display:inline-block;"></span> Occupied: <strong style="color:#e2e8f0;">' + occupied + '</strong></span>';
    statsHtml += '<span style="display:flex;align-items:center;gap:6px;font-size:13px;color:#94a3b8;"><span style="width:10px;height:10px;border-radius:50%;background:#eab308;display:inline-block;"></span> Reserved: <strong style="color:#e2e8f0;">' + reserved + '</strong></span>';
    statsHtml += '<span style="display:flex;align-items:center;gap:6px;font-size:13px;color:#94a3b8;margin-left:auto;">Total: <strong style="color:#e2e8f0;">' + tables.length + '</strong></span>';
    statsHtml += '</div>';
    
    var floorHtml = '';
    
    // Build floor plan by area
    areas.forEach(function(area) {
      var areaTables = tables.filter(function(t) { return parseInt(t.area_id) === parseInt(area.id); });
      if (areaTables.length === 0) return;
      
      floorHtml += '<div class="area-section" style="margin-bottom:28px;">';
      floorHtml += '<div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#64748b;margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid #334155;display:flex;align-items:center;gap:8px;">';
      var areaIcons = ['table_restaurant','meeting_room','outdoor_garden','star','deck','balcony','room'];
      var areaIcon = areaIcons[(area.id - 1) % areaIcons.length];
      floorHtml += '<span class="material-symbols-rounded" style="font-size:16px;">' + areaIcon + '</span>';
      floorHtml += escapeHtml(area.area_name);
      floorHtml += '<span style="font-weight:400;color:#475569;font-size:11px;margin-left:4px;">(' + areaTables.length + ' tables)</span>';
      floorHtml += '</div>';
      floorHtml += '<div style="display:flex;flex-wrap:wrap;gap:16px;padding:4px;">';
      
      areaTables.forEach(function(table) {
        var st = table.status;
        var bg, border, shadow, bBg, bText;
        if (st === 'free') { bg = 'linear-gradient(145deg,#052e16,#064e3b)'; border = '#22c55e'; shadow = 'rgba(34,197,94,0.2)'; bBg = '#22c55e'; bText = '#052e16'; }
        else if (st === 'occupied') { bg = 'linear-gradient(145deg,#450a0a,#7f1d1d)'; border = '#ef4444'; shadow = 'rgba(239,68,68,0.2)'; bBg = '#ef4444'; bText = '#fff'; }
        else { bg = 'linear-gradient(145deg,#422006,#713f12)'; border = '#eab308'; shadow = 'rgba(234,179,8,0.2)'; bBg = '#eab308'; bText = '#422006'; }
        
        floorHtml += '<div onclick="showTableMapDetail(' + table.id + ')" style="position:relative;width:110px;height:110px;border-radius:14px;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;transition:all 0.2s;border:2px solid ' + border + ';background:' + bg + ';box-shadow:0 4px 12px ' + shadow + ';"';
        floorHtml += ' onmouseover="this.style.transform=\'translateY(-4px) scale(1.04)\';this.style.boxShadow=\'0 8px 24px ' + shadow.replace('0.2','0.35') + '\'"';
        floorHtml += ' onmouseout="this.style.transform=\'\';this.style.boxShadow=\'0 4px 12px ' + shadow + '\'">';
        floorHtml += '<div style="position:absolute;top:-6px;right:-6px;padding:2px 8px;border-radius:20px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.3px;background:' + bBg + ';color:' + bText + ';">' + st + '</div>';
        floorHtml += '<div style="font-size:26px;font-weight:800;color:#f1f5f9;line-height:1;margin-bottom:2px;">' + escapeHtml(table.table_number) + '</div>';
        floorHtml += '<div style="font-size:11px;color:#94a3b8;display:flex;align-items:center;gap:4px;"><span class="material-symbols-rounded" style="font-size:13px;">group</span>' + table.capacity + '</div>';
        if (table.customer_name) {
          floorHtml += '<div style="font-size:9px;color:#94a3b8;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:90px;">' + escapeHtml(table.customer_name) + '</div>';
        }
        if (table.order_timer) {
          floorHtml += '<div style="position:absolute;bottom:-6px;left:50%;transform:translateX(-50%);background:#0f172a;border:1px solid #334155;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:600;color:#fbbf24;white-space:nowrap">⏱ ' + escapeHtml(table.order_timer) + '</div>';
        }
        floorHtml += '</div>';
      });
      
      floorHtml += '</div></div>';
    });
    
    container.innerHTML = statsHtml + floorHtml;
    
  } catch (e) {
    console.error('Table map error', e);
    container.innerHTML = '<div class="empty-state" style="color:#94a3b8;padding:40px;"><span class="material-symbols-rounded" style="font-size:48px;display:block;margin-bottom:12px;">error</span><h3>Error loading table map</h3><p style="margin-top:8px;">' + escapeHtml(e.message || 'Please try again') + '</p></div>';
  }
  
  // Auto-refresh every 10 seconds while this view is active
  if (window._tableMapTimer) clearTimeout(window._tableMapTimer);
  window._tableMapTimer = setTimeout(function() {
    if (document.getElementById('tableMapContainer')) {
      renderTableMap();
    }
  }, 10000);
};

})();
