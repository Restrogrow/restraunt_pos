// Cropped image -> data URL, keeping transparency. JPEG has no alpha channel,
// so a transparent PNG saved as JPEG came out with a black background. Use
// JPEG only when every pixel is opaque (keeps photos small); otherwise WebP
// (alpha + small), or PNG on browsers that can't encode WebP.
function canvasToImageDataUrl(canvas, quality) {
  var q = quality || 0.9;
  var hasAlpha = false;
  try {
    var px = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
    for (var i = 3; i < px.length; i += 4) { if (px[i] < 255) { hasAlpha = true; break; } }
  } catch (e) { /* tainted canvas: fall back to JPEG as before */ }
  if (!hasAlpha) return canvas.toDataURL('image/jpeg', q);
  var webp = canvas.toDataURL('image/webp', q);
  return webp.indexOf('data:image/webp') === 0 ? webp : canvas.toDataURL('image/png');
}

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
  if (window.RGNotify) return RGNotify.toast(msg, type);
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

// Split-payment "Choose Pay Method" modal. Payment methods (built-ins
// Cash/Card/UPI plus any custom extras the admin has added) are tap-to-select
// buttons rather than always-visible amount fields: tapping exactly one
// method auto-fills the full bill amount into it (single-tap checkout — no
// typing needed for the common case), while selecting 2-3 methods reveals a
// blank amount input for each so the cashier can manually split the total.
// The list of methods is loaded from paymentMethodsCache (see
// loadPaymentMethods()) rather than hardcoded, so adding a payment method in
// Settings makes it usable at POS immediately without a code change.
window.showSplitPaymentModal = async function(total) {
  if (!paymentMethodsCache.length) {
    await loadPaymentMethods();
  }
  let methods = paymentMethodsCache.filter(m => m.is_active == 1 || m.is_active === true);
  if (!methods.length) {
    // Absolute fallback if the API/cache is unavailable for some reason —
    // POS must still be usable.
    methods = [{ id: 'cash', method_name: 'Cash', emoji: '💵' }, { id: 'card', method_name: 'Card', emoji: '💳' }, { id: 'upi', method_name: 'UPI', emoji: '📱' }];
  }

  return new Promise((resolve) => {
    const currency = window.globalCurrencySymbol || '₹';
    const grandTotal = parseFloat(total) || 0;
    let resolved = false;
    let selectedIdxs = [];
    const amountsByIdx = methods.map(() => 0);

    const existing = document.getElementById('splitPaymentOverlay');
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.id = 'splitPaymentOverlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px;';

    const modal = document.createElement('div');
    modal.style.cssText = 'background:#fff;border-radius:16px;width:100%;max-width:420px;max-height:92vh;overflow-y:auto;box-shadow:0 25px 80px rgba(0,0,0,0.35);padding:24px;';

    const buttonsHtml = methods.map((m, i) => `
      <button type="button" class="splitPayMethodBtn" data-method-idx="${i}" style="flex:1;min-width:90px;padding:14px 8px;border-radius:10px;font-weight:700;font-size:0.85rem;cursor:pointer;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHtml((m.emoji ? m.emoji + ' ' : '') + m.method_name)}</button>`).join('');

    modal.innerHTML = `
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
        <h2 style="margin:0;font-size:1.15rem;font-weight:700;color:#111827;">Choose Pay Method (${currency}${grandTotal.toFixed(2)})</h2>
        <button id="splitPayCloseBtn" style="width:34px;height:34px;flex-shrink:0;border:none;border-radius:50%;background:#10b981;color:#fff;font-size:18px;cursor:pointer;display:grid;place-items:center;">&times;</button>
      </div>

      <p style="margin:0 0 10px;font-size:0.8rem;color:#6b7280;">Tap a method to pay the full amount instantly. Tap 2 or more to split the bill.</p>
      <div id="splitPayMethodBtns" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
        ${buttonsHtml}
      </div>

      <div id="splitPayInputsContainer"></div>

      <div id="splitPayBalanceText" style="text-align:center;font-weight:700;font-size:1.05rem;margin-bottom:12px;"></div>
      <div id="splitPayConfirmBanner" style="display:flex;align-items:center;gap:8px;padding:12px 16px;border-radius:10px;font-weight:600;margin-bottom:16px;"></div>

      <div style="display:flex;gap:10px;">
        <button id="splitPaySaveBillBtn" style="flex:1;padding:13px;border:none;border-radius:10px;background:#10b981;color:#fff;font-weight:700;font-size:0.95rem;cursor:pointer;">Save & eBill</button>
        <button id="splitPaySavePrintBtn" style="flex:1;padding:13px;border:none;border-radius:10px;background:#10b981;color:#fff;font-weight:700;font-size:0.95rem;cursor:pointer;">Save & Print</button>
      </div>
    `;

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    const methodBtns = Array.from(modal.querySelectorAll('.splitPayMethodBtn'));
    const inputsContainer = modal.querySelector('#splitPayInputsContainer');
    const balanceText = modal.querySelector('#splitPayBalanceText');
    const confirmBanner = modal.querySelector('#splitPayConfirmBanner');

    const btnBaseStyle = 'flex:1;min-width:90px;padding:14px 8px;border-radius:10px;font-weight:700;font-size:0.85rem;cursor:pointer;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
    const activeBtnStyle = 'background:#0f4c5c;color:#fff;border:2px solid #0f4c5c;';
    const inactiveBtnStyle = 'background:#fff;color:#111827;border:2px solid #e5e7eb;';

    function updateButtonStyles() {
      methodBtns.forEach((btn, i) => {
        const active = selectedIdxs.includes(i);
        btn.style.cssText = btnBaseStyle + (active ? activeBtnStyle : inactiveBtnStyle);
      });
    }

    function renderInputs() {
      inputsContainer.innerHTML = selectedIdxs.map((i) => {
        const m = methods[i];
        return `<div style="margin-bottom:16px;">
          <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">Enter ${escapeHtml((m.emoji ? m.emoji + ' ' : '') + m.method_name.toUpperCase())} Amount</label>
          <input type="number" class="splitPayInput" data-method-idx="${i}" min="0" step="0.01" value="${amountsByIdx[i]}" style="width:100%;padding:12px 14px;border:1.5px solid #d1d5db;border-radius:10px;font-size:1rem;box-sizing:border-box;">
        </div>`;
      }).join('');
      Array.from(inputsContainer.querySelectorAll('.splitPayInput')).forEach((inp) => {
        inp.addEventListener('input', () => {
          amountsByIdx[parseInt(inp.getAttribute('data-method-idx'))] = Math.max(parseFloat(inp.value) || 0, 0);
          recalc();
        });
        inp.addEventListener('focus', () => { if (parseFloat(inp.value) === 0) inp.value = ''; });
      });
    }

    function selectMethod(idx) {
      const pos = selectedIdxs.indexOf(idx);
      if (pos !== -1) {
        selectedIdxs.splice(pos, 1);
        amountsByIdx[idx] = 0;
      } else {
        selectedIdxs.push(idx);
      }
      // Single method selected: auto-fill the full bill amount (no typing
      // needed). Two or more selected: leave blank for manual splitting.
      if (selectedIdxs.length === 1) {
        amountsByIdx[selectedIdxs[0]] = grandTotal;
      } else if (selectedIdxs.length >= 2) {
        selectedIdxs.forEach((i) => { amountsByIdx[i] = 0; });
      }
      updateButtonStyles();
      renderInputs();
      recalc();
    }

    methodBtns.forEach((btn, i) => {
      btn.addEventListener('click', () => selectMethod(i));
    });

    function getAmounts() {
      const amounts = {};
      let entered = 0;
      methods.forEach((m, i) => {
        const amt = selectedIdxs.includes(i) ? Math.max(amountsByIdx[i] || 0, 0) : 0;
        amounts[m.method_name] = amt;
        entered += amt;
      });
      return { amounts, entered };
    }

    function recalc() {
      const { entered } = getAmounts();
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
        confirmBanner.innerHTML = 'Tap a payment method above, or leave blank to mark as Due';
      }
    }

    updateButtonStyles();
    renderInputs();
    recalc();

    function finish(action) {
      if (resolved) return;
      resolved = true;
      const { amounts, entered } = getAmounts();
      const returnAmt = Math.max(entered - grandTotal, 0);
      const dueAmt = Math.max(grandTotal - entered, 0);
      const usedMethods = methods.map(m => m.method_name).filter(name => amounts[name] > 0);
      const paymentMethod = usedMethods.length ? usedMethods.join('+') : 'Due';
      const paymentStatus = entered <= 0 ? 'Pending' : (dueAmt > 0 ? 'Partially Paid' : 'Paid');
      overlay.remove();
      resolve({
        action,
        amounts,
        entered,
        returnAmount: returnAmt,
        dueAmount: dueAmt,
        paymentMethod,
        paymentStatus,
        // Backward-compat convenience fields for the built-in names, so
        // existing callers reading .cash/.upi/.card keep working untouched.
        cash: amounts['Cash'] || 0,
        upi: amounts['UPI'] || 0,
        card: amounts['Card'] || 0,
      });
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
      const wasActive = navItem.classList.contains("active");
      document.querySelectorAll(".nav-item.has-submenu.active").forEach(item => {
        if (item !== navItem) item.classList.remove("active");
      });
      navItem.classList.toggle("active", !wasActive);
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
    // a section Super Admin switched off for this restaurant (views/dashboard.php sets the list)
    if (window.RG_ACCESS_OFF && window.RG_ACCESS_OFF.indexOf(pageId) !== -1) pageId = 'dashboardPage';
    // Super Admin › Usage: which pages a restaurant opens (assets/js/onboarding.js)
    if (window.rgUsagePing) window.rgUsagePing(pageId);
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
    
    // Stop the delivery map's 30s refresh loop when navigating away from it —
    // it previously kept polling in the background indefinitely once opened.
    const previousActivePage = document.querySelector('.page.active');
    if (previousActivePage && previousActivePage.id === 'deliveryMapPage' && pageId !== 'deliveryMapPage') {
      if (typeof window.stopDeliveryMapRefresh === 'function') {
        window.stopDeliveryMapRefresh();
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
        if (typeof window.loadAreasStudio === 'function') window.loadAreasStudio(); else loadAreas();   // Tables › Areas (areas-studio.js)
      }
      
      // Load tables if it's the tables page
      if (pageId === "tablesPage") {
        loadTables();
      }
      
      // Load QR codes if it's the QR codes page
      if (pageId === "qrCodesPage") {
        loadQRCodes();
      }

      // Offers > Bestsellers (assets/js/bestsellers-admin.js)
      if (pageId === "bestsellersPage" && typeof window.loadBestsellersAdmin === 'function') {
        window.loadBestsellersAdmin();
      }

      // Offers > Today's Special (assets/js/todays-special-admin.js)
      if (pageId === "todaysSpecialPage" && typeof window.loadTodaysSpecialAdmin === 'function') {
        window.loadTodaysSpecialAdmin();
      }
      // Offers > Complete your meal (assets/js/upsell-admin.js)
      if (pageId === "upsellPage" && typeof window.loadUpsellAdmin === 'function') {
        window.loadUpsellAdmin();
      }
      // Offers > Scratch card (assets/js/scratch-admin.js)
      if (pageId === "scratchPage" && typeof window.loadScratchAdmin === 'function') {
        window.loadScratchAdmin();
      }
      // Offers > Wallet (assets/js/wallet-admin.js)
      if (pageId === "walletPage" && typeof window.loadWalletAdmin === 'function') {
        window.loadWalletAdmin();
      }
      // Offers > Offer Filters (assets/js/offer-filters-admin.js)
      if (pageId === "offerFiltersPage" && typeof window.loadOfferFiltersAdmin === 'function') {
        window.loadOfferFiltersAdmin();
      }

      // Load photo gallery if it's the gallery page
      if (pageId === "galleryPage") {
        if (typeof window.loadPhotoGallery === 'function') {
          loadPhotoGallery();
        }
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
          const posSearchInput = document.getElementById("posSearchInput");
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
          if (posSearchInput && !posSearchInput.dataset.listenerAttached) {
            posSearchInput.addEventListener("input", handlePOSSearchInput);
            posSearchInput.dataset.listenerAttached = 'true';
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
      async function fetchPendingCount() {
        if (document.hidden) return;
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
      if (!window.pendingOrderBadgePoll) {
        fetchPendingCount();
        window.pendingOrderBadgePoll = setInterval(fetchPendingCount, 30000);
      }

      // New order popup polling - every 10 seconds
      if (!window._newOrderPollInterval) {
        window._lastSeenOrderId = 0;
        window._currentNotifOrderId = null;
        window._overlayProcessing = false;

        async function checkNewPendingOrder() {
          // keeps checking while the tab is in the background: that is exactly when the owner
          // needs the ring and the notification (order-alerts.js)
          try {
            const overlay = document.getElementById('newOrderOverlay');
            if (overlay && overlay.classList.contains('show')) return;
            if (window._overlayProcessing) return;

            const r = await fetch('../api/get_latest_pending_order.php', { cache: 'no-store' });
            if (!r.ok) return;
            const d = await r.json();
            if (d.success && d.order) {
              // Keep these in sync from whichever endpoint responds first —
              // the Online Orders list may not have loaded yet if this popup
              // fires while the admin is on another page.
              if (d.restaurant_lat) window.restaurantLat = d.restaurant_lat;
              if (d.restaurant_lng) window.restaurantLng = d.restaurant_lng;
              if (d.enable_km_delivery !== undefined) window.enableKmDelivery = d.enable_km_delivery;
              if (d.delivery_rate_per_km !== undefined) window.deliveryRatePerKm = d.delivery_rate_per_km;
              const orderId = parseInt(d.order.id);
              if (orderId > window._lastSeenOrderId) {
                window._lastSeenOrderId = orderId;
                window._currentNotifOrderId = orderId;
                showNewOrderPopup(d.order);
                // The popup alone doesn't touch the Dashboard's stat cards /
                // Recent Orders list — refresh them too so a new website order
                // shows up there immediately instead of only after a manual
                // page reload, if the admin currently has Dashboard open.
                var dashboardPageEl = document.getElementById('dashboardPage');
                if (dashboardPageEl && dashboardPageEl.classList.contains('active') && typeof loadDashboardStats === 'function') {
                  loadDashboardStats();
                }
              }
            } else if (d.success && !d.order) {
              window._lastSeenOrderId = 0;
            }
          } catch(e) {}
        }

        console.log('[Notif] Polling for new orders started, will check in 2s...');
        setTimeout(checkNewPendingOrder, 2000);
        window._newOrderPollInterval = setInterval(checkNewPendingOrder, 10000);

        // Both the badge count and new-order polls above now skip their work
        // while the tab is hidden (see the "if (document.hidden) return"
        // guards) instead of hammering the server in a backgrounded tab —
        // catch up immediately as soon as the tab becomes visible again so
        // the badge/notification isn't stale for up to 30s after switching back.
        if (!window.badgeVisibilityHandler) {
          window.badgeVisibilityHandler = function() {
            if (!document.hidden) {
              fetchPendingCount();
              checkNewPendingOrder();
            }
          };
          document.addEventListener('visibilitychange', window.badgeVisibilityHandler);
        }
      }

      function showNewOrderPopup(order) {
        // the redesigned popup (photos, payment, bill, ready-in time, reasons): assets/js/new-order-popup.js
        if (window.RGNewOrder) return window.RGNewOrder.show(order);
        var overlay = document.getElementById('newOrderOverlay');
        if (!overlay) return;

        window._currentNotifOrder = order;

        startNewOrderRing();
        if (typeof window.rgOrderAlert === 'function') window.rgOrderAlert(order);   // background: system notification + tab title

        var e = document.getElementById('notifOrderNumber');
        if (e) e.textContent = 'Order #' + (order.order_number || order.id);

        e = document.getElementById('notifCustomerName');
        if (e) e.textContent = order.customer_name || 'Guest';

        e = document.getElementById('notifCustomerPhone');
        if (e) e.textContent = order.customer_phone || '-';

        var isDelivery = (order.order_type || '').toLowerCase() === 'delivery';

        var addrRow = document.getElementById('notifAddressRow');
        var addrEl = document.getElementById('notifCustomerAddress');
        if (addrRow && addrEl) {
          if (order.customer_address && isDelivery) {
            addrRow.style.display = 'flex';
            addrEl.textContent = order.customer_address;
          } else {
            addrRow.style.display = 'none';
          }
        }

        var landmarkRow = document.getElementById('notifLandmarkRow');
        var landmarkEl = document.getElementById('notifLandmark');
        if (landmarkRow && landmarkEl) {
          if (order.landmark && isDelivery) {
            landmarkRow.style.display = 'flex';
            landmarkEl.textContent = order.landmark;
          } else {
            landmarkRow.style.display = 'none';
          }
        }

        var mapContainer = document.getElementById('notifMapContainer');
        if (mapContainer) {
          mapContainer.innerHTML = (isDelivery && typeof buildDeliveryMapHtml === 'function') ? buildDeliveryMapHtml(order) : '';
        }

        e = document.getElementById('notifOrderType');
        if (e) {
          var s = (order.order_type || 'N/A').replace(/_/g, ' ');
          e.textContent = s.charAt(0).toUpperCase() + s.slice(1);
        }

        e = document.getElementById('notifPaymentInfo');
        if (e) e.textContent = (order.payment_method || 'N/A') + ' (' + (order.payment_status || 'N/A') + ')';

        // QR-payment orders need a review before the restaurant can trust the
        // money actually landed, so surface the customer's screenshot right
        // here rather than making the admin dig into the full order details.
        var proofContainer = document.getElementById('notifPaymentProofContainer');
        if (proofContainer) {
          proofContainer.innerHTML = (typeof buildPaymentProofHtml === 'function') ? buildPaymentProofHtml(order, 160) : '';
        }

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
        if (window.RGNewOrder) return window.RGNewOrder.close();
        var overlay = document.getElementById('newOrderOverlay');
        if (overlay) {
          overlay.classList.remove('show');
          document.body.style.overflow = '';
        }
        window._currentNotifOrderId = null;
        stopNewOrderRing();
      };

      window.acceptNewOrder = async function() {
        if (window.RGNewOrder) return window.RGNewOrder.accept();
        var orderId = window._currentNotifOrderId;
        var order = window._currentNotifOrder;
        if (!orderId) return;
        window._overlayProcessing = true;

        var isKmDelivery = order && order.order_type === 'Delivery' && (window.enableKmDelivery == 1 || window.enableKmDelivery === true);
        if (isKmDelivery) {
          // Close the popup first so the delivery-charge prompt isn't stacked behind it.
          window.closeNewOrderOverlay();
          try {
            var kd = await acceptOrderWithDeliveryCharge(orderId, order.order_type, order.address_lat, order.address_lng);
            if (kd && kd.cancelled) { window._overlayProcessing = false; return; }
            if (typeof showNotification === 'function') showNotification(kd.success ? 'Order accepted!' : (kd.message || 'Failed'), kd.success ? 'success' : 'error');
            if (typeof showPage === 'function') showPage('onlineOrdersPage');
          } catch(e) {
            if (typeof showNotification === 'function') showNotification('Network error', 'error');
          } finally {
            window._overlayProcessing = false;
          }
          return;
        }

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
        if (window.RGNewOrder) return window.RGNewOrder.reject();
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
          const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().split('T')[0];
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
        loadPaymentMethods();
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

        // Keep the order-type toggles live while this page stays open — the
        // owner might flip Delivery/Dine-in from the POS app (or another
        // browser tab) while this one is sitting open on Settings. Only
        // touches these 3 checkboxes, not loadSettingsData()'s full
        // re-population, so it can't stomp text fields mid-edit.
        if (window.orderTypeTogglePoll) {
          clearInterval(window.orderTypeTogglePoll);
        }
        window.orderTypeTogglePoll = setInterval(async () => {
          const page = document.getElementById('settingsPage');
          if (!page?.classList.contains('active') || document.hidden) return;
          try {
            const res = await fetch('../admin/get_session.php');
            const data = await res.json();
            if (!data.success) return;
            const user = data.data;
            const deliveryCb = document.getElementById('enableDeliveryToggle');
            const takeawayCb = document.getElementById('enableTakeawayToggle');
            const dineinCb = document.getElementById('enableDineinToggle');
            if (deliveryCb && document.activeElement !== deliveryCb && user.enable_delivery !== undefined) {
              deliveryCb.checked = user.enable_delivery == 1;
            }
            if (takeawayCb && document.activeElement !== takeawayCb && user.enable_takeaway !== undefined) {
              takeawayCb.checked = user.enable_takeaway == 1;
            }
            if (dineinCb && document.activeElement !== dineinCb && user.enable_dinein !== undefined) {
              dineinCb.checked = user.enable_dinein == 1;
            }
          } catch (e) {}
        }, 15000);
      } else if (window.orderTypeTogglePoll) {
        clearInterval(window.orderTypeTogglePoll);
        window.orderTypeTogglePoll = null;
      }
      
      // Setup reports auto-reload if it's the reports page
      if (pageId === "reportsPage") {
        setTimeout(() => {
          setupReportsAutoReload();
          // load here too, so the report also fills after a browser refresh (not only from a menu click)
          const p = document.getElementById('reportPeriod');
          if (!p || p.value !== 'custom') loadReports();
        }, 100);
        loadPaymentMethods().then(() => {
          updatePaymentMethodFilterOptions(document.getElementById('filterPaymentMethod'), 'All Methods', 'all');
        });
        setTimeout(() => {
          const reportTypeEl = document.getElementById('reportType');
          const wrapperEl = document.getElementById('paymentMethodFilterWrapper');
          if (reportTypeEl && wrapperEl && !reportTypeEl.dataset.paymentFilterListenerAttached) {
            const syncWrapper = () => { wrapperEl.style.display = (reportTypeEl.value === 'payment') ? '' : 'none'; };
            reportTypeEl.addEventListener('change', syncWrapper);
            reportTypeEl.dataset.paymentFilterListenerAttached = 'true';
            syncWrapper();
          }
        }, 100);
      }

      // Load payment methods if it's the payment gateway settings page
      if (pageId === "gatewayPage") {
        loadPaymentMethods();
      }

      // Load analytics if it's the analytics page
      if (pageId === "analyticsPage") {
        setTimeout(() => (window.loadAnalytics || loadAnalytics)(), 100);   // analytics-studio.js replaces it
      }

      // Sections that used to load only from their menu link's onclick. Loading them here means
      // they also fill in when the page is reopened after a refresh, or opened from a button.
      const sectionLoaders = {
        inventoryPage: ['loadInventory'],
        mealPlansPage: ['loadAdminMealPlans'],
        mealSubscriptionsPage: ['loadAdminMealSubscriptions'],
        blocklistPage: ['loadAdminBlocklist'],
        cateringPage: ['loadAdminCatering'],
        marketingPostersPage: ['initPosterStudio'],
        marketingVideosPage: ['initVideoStudio'],
        marketingCaptionsPage: ['initCaptionStudio'],
        marketingCalendarPage: ['initCampaignCalendar'],
        marketingDesignsPage: ['initMarketingDesigns'],
        growthSettingsPage: ['loadGrowthSettings', 'loadLoyaltyStudio'],
        whatsappPage: ['loadWhatsappAdmin'],
        waOrderPage: ['loadWaOrderStudio'],          // Growth › WhatsApp ordering (wa-order-studio.js)
        ownerManagerPage: ['loadOwnerManagerStudio'], // Growth › AI manager (owner-manager-studio.js)
        aiAppsPage: ['loadAiApps'],                   // Growth › ChatGPT & Claude (ai-apps-studio.js)
        apiKeysPage: ['loadApiKeys'],                 // API keys (api-keys-studio.js)
        ridersPage: ['loadRidersBoard'],              // Delivery › Riders (riders-board.js)
        customerSegmentsPage: ['loadCustomerSegments'],
        referralsPage: ['loadReferrals'],
        growthReportsPage: ['loadGrowthReports', ['loadClvReport', '']],
        policyPagesPage: ['initPolicyPagesEditor'],
        offersHomePage: ['loadOffersHome'],
        seoPage: ['loadSeo'],                          // dashboard › SEO (seo-studio.js)
        googleBusinessPage: ['loadGoogleBusiness'],    // dashboard › Google Business (gbp-studio.js)
      };
      if (sectionLoaders[pageId]) {
        const pageEl = document.getElementById(pageId);
        const loaderFns = sectionLoaders[pageId];
        setTimeout(() => {
          let completed = 0;
          const total = loaderFns.length;
          const markDone = () => { completed++; };
          loaderFns.forEach((f) => {
            const [name, ...args] = Array.isArray(f) ? f : [f];
            try {
              if (typeof window[name] === 'function') {
                const result = window[name](...args);
                if (result && typeof result.then === 'function') {
                  result.catch(function (err) {
                    console.error('[showPage] Loader failed:', name, err);
                    showPageError(pageId, name, err);
                  });
                }
              }
            } catch (err) {
              console.error('[showPage] Loader threw:', name, err);
              showPageError(pageId, name, err);
            }
            markDone();
          });
          // If no content appeared after 8 seconds, show error
          setTimeout(function () {
            if (pageEl && pageEl.querySelector('.page-content')) {
              var content = pageEl.querySelector('.page-content');
              // Meal plans, subscribers, blocklist and catering open in a frame: it has no text of its own here
              if (content.querySelector('iframe')) return;
              if (!content.children.length || content.textContent.trim() === '' || content.innerHTML.indexOf('Loading') > -1) {
                showPageError(pageId, 'timeout', new Error('Content did not load within 8 seconds'));
              }
            }
          }, 8000);
        }, 50);
      }
      // How to use: its menu link opens the guide for the page you came from; after a refresh, open it plainly
      if (pageId === "howToPage") {
        const frame = document.getElementById('howToFrame');
        if (frame && !frame.getAttribute('src') && typeof window.openHowTo === 'function') window.openHowTo(null);
      }
    }
  }
  
  // Make showPage globally accessible for onclick handlers
  window.showPage = showPage;

  // Show a friendly error message when a page's content fails to load
  function showPageError(pageId, loaderName, err) {
    var pageEl = document.getElementById(pageId);
    if (!pageEl) return;
    var content = pageEl.querySelector('.page-content');
    if (!content) return;
    // Don't replace if there's already meaningful content
    if (content.children.length > 1 || (content.textContent.trim() && content.textContent.trim().length > 50 && content.textContent.trim().indexOf('Loading') === -1)) return;
    var msg = 'This page could not load. ';
    if (err && err.message) msg += err.message + ' ';
    msg += 'Try refreshing, or contact support if it keeps happening.';
    content.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:400px;padding:2rem;text-align:center;">' +
      '<span class="material-symbols-rounded" style="font-size:48px;color:#ef4444;margin-bottom:16px;">error_outline</span>' +
      '<h3 style="margin:0 0 8px;color:#1f2937;font-size:1.1rem;">Couldn\u2019t load ' + (pageId.replace('Page', '')) + '</h3>' +
      '<p style="margin:0 0 16px;color:#6b7280;max-width:400px;">' + msg + '</p>' +
      '<button type="button" class="btn btn-primary" onclick="showPage(\'' + pageId + '\')"><span class="material-symbols-rounded" style="font-size:16px;">refresh</span>Try again</button>' +
      '</div>';
    console.error('[showPage] Error on page', pageId, 'loader:', loaderName, err);
  }

  // Initialize page restoration after DOM is fully loaded and all functions are defined
  function initializePageRestoration() {
    // Restore previously active page (if available), unless forced to dashboard after login
    try {
      const ordersListContainer = document.getElementById('ordersList');
      if (ordersListContainer) {
        ordersListContainer.innerHTML = '<div class="loading">Refreshing orders...</div>';
      }

      const forceDashboard = sessionStorage.getItem('forceDashboard');
      // ?page=inventoryPage (e.g. from a low-stock notification) opens that section
      const askedPage = new URLSearchParams(window.location.search).get('page');
      if (askedPage && /^[a-zA-Z]+Page$/.test(askedPage) && document.getElementById(askedPage)) {
        showPage(askedPage);
        try { history.replaceState(null, '', window.location.pathname); } catch (e) {}
      } else if (forceDashboard) {
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

  // Global loading timeout: if any [data-loading-fallback] element is still
  // showing loading text after 10 seconds, replace it with a helpful error
  // message and a retry button instead of leaving a blank screen.
  setTimeout(function () {
    document.querySelectorAll('[data-loading-fallback]').forEach(function (el) {
      var text = el.textContent.trim();
      if (!text || text.indexOf('Loading') === -1) return;
      var pageEl = el.closest('.page');
      var pageId = pageEl ? pageEl.id : '';
      var label = text.replace(/Loading[.\s]*/i, '').replace(/\.\.\./g, '').trim();
      el.outerHTML = '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:300px;padding:2rem;text-align:center;">' +
        '<span class="material-symbols-rounded" style="font-size:48px;color:#f59e0b;margin-bottom:16px;">hourglass_empty</span>' +
        '<h3 style="margin:0 0 8px;color:#1f2937;font-size:1.1rem;">Still loading' + (label ? ' ' + label : '') + '…</h3>' +
        '<p style="margin:0 0 16px;color:#6b7280;max-width:400px;">This is taking longer than expected. Check your internet connection, then try again.</p>' +
        (pageId ? '<button type="button" class="btn btn-primary" onclick="showPage(\'' + pageId + '\')"><span class="material-symbols-rounded" style="font-size:16px;">refresh</span> Try again</button>' : '') +
        '</div>';
    });
  }, 10000);

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
      // A new category picture is processed on the server (background, centring)
      if (saveBtn && formData.has('menuImageBase64') || saveBtn && formData.has('menuImage')) {
        saveBtn.textContent = 'Saving & processing picture…';
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
      const croppedDataUrl = canvasToImageDataUrl(croppedCanvas, 0.9);
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
          const croppedDataUrl = canvasToImageDataUrl(croppedCanvas, 0.9);
          
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

    // Insert message after form group; if that form is hidden the message would never be seen, so toast it
    const formGroup = document.querySelector(".form-group");
    if (window.RGNotify && (!formGroup || !formGroup.offsetParent)) return RGNotify.toast(message, type);
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
  async function loadDashboardStats(force) {
    // the redesigned home screen (assets/js/dashboard-home.js) loads its own data
    if (typeof window.loadDashboardHome === 'function') return window.loadDashboardHome(force === true);
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
  if (!window.loadAnalytics) window.loadAnalytics = loadAnalytics;   // analytics-studio.js (loaded first) provides the new page

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

  window.loadMenus = loadMenus;
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

      // menus by time of day (assets/js/menu-hours.js)
      const hoursData = escapeHtml(JSON.stringify({ id: menu.id, menu_name: menu.menu_name, avail_days: menu.avail_days || '', avail_from: menu.avail_from || '', avail_to: menu.avail_to || '' })).replace(/"/g, '&quot;');   // this escapeHtml leaves quotes
      const hoursChip = menu.hours_label
        ? `<div class="mh-chip${menu.served_now === false ? ' off' : ''}"><span class="material-symbols-rounded">schedule</span>${escapeHtml(menu.hours_label)}${menu.served_now === false ? ' · not now' : ''}</div>`
        : '';
      // kitchen or bar (assets/js/menu-stations.js): bar dishes print on a Bar Order Ticket
      const isBarMenu = menu.prep_station === 'bar';
      const stationData = escapeHtml(JSON.stringify({ id: menu.id, menu_name: menu.menu_name })).replace(/"/g, '&quot;');
      const stationChip = isBarMenu ? `<div class="mh-chip ms-bar"><span class="material-symbols-rounded">local_bar</span>Made at the bar · BOT</div>` : '';
      return `
      <div class="menu-card" data-menu-id="${menu.id}">
        ${imageHtml}
        <h3>${escapeHtml(menu.menu_name)}</h3>
        <div class="menu-date">Created: ${formatDate(menu.created_at)}</div>
        ${hoursChip}${stationChip}
        <div class="menu-actions-card">
          <button class="btn-edit mh-btn" type="button" data-menu-hours="${hoursData}" title="When this category is served">
            <span class="material-symbols-rounded">schedule</span>
            Hours
          </button>
          <button class="btn-edit mh-btn" type="button" data-menu-station="${stationData}" title="Made in the kitchen (KOT) or at the bar (BOT)">
            <span class="material-symbols-rounded">${isBarMenu ? 'local_bar' : 'soup_kitchen'}</span>
            ${isBarMenu ? 'Bar' : 'Kitchen'}
          </button>
          <button class="btn-edit" onclick="editMenu(${menu.id}, ${jsArg(menu.menu_name)}, '${menuImageUrl || ''}')">
            <span class="material-symbols-rounded">edit</span>
            Edit
          </button>
          <button class="btn-delete" onclick="deleteMenu(${menu.id}, ${jsArg(menu.menu_name)})">
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
    // no "are you sure": it goes away at once with Undo for a few seconds (assets/js/rg-undo.js)
    rgUndo({
      html: rgUndo.label('Deleted menu', menuName),
      hide: rgUndo.rows('.menu-card[data-menu-id="' + menuId + '"]'),
      request: { url: '../controllers/menu_operations.php', body: { action: 'delete', menuId: menuId } },
      // dishes that were ordered before move to the hidden "Removed dishes" section: say so
      onDone: function (d) { loadMenus(); if (d && d.kept) showNotification(d.message, 'info'); },
      onError: function (msg) { showNotification(msg, "error"); }
    });
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
    if (window.RGNotify) return RGNotify.toast(message, type);
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
                <button type="button" onclick="editSubcategory(${sub.id}, ${jsArg(sub.subcategory_name)}, ${menuId})" style="padding: 4px 8px; background: transparent; color: #6b7280; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85rem;" title="Edit">
                  <span class="material-symbols-rounded" style="font-size: 1.1rem;">edit</span>
                </button>
                <button type="button" onclick="deleteSubcategory(${sub.id}, ${jsArg(sub.subcategory_name)})" style="padding: 4px 8px; background: transparent; color: #ef4444; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85rem;" title="Delete">
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
    // no "are you sure": it goes away at once with Undo for a few seconds (assets/js/rg-undo.js)
    const menuId = document.getElementById("subcategoryMenuId") ? document.getElementById("subcategoryMenuId").value : null;
    rgUndo({
      html: rgUndo.label('Deleted subcategory', name),
      hide: rgUndo.rows('.subcategory-item[data-sub-id="' + id + '"]'),
      request: { url: '../controllers/menu_operations.php', body: { action: 'delete_subcategory', subcategoryId: id } },
      onDone: function () { if (menuId) loadSubcategories(menuId); },
      onError: function (msg) { showNotification(msg, "error"); }
    });
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
  // Health tags dropdown: show which are ticked in its header
  window.updateHealthTagsSummary = function() {
    var el = document.getElementById('healthTagsSummary');
    if (!el) return;
    var on = Array.prototype.filter.call(document.querySelectorAll('.health-tag-cb'), function(cb) { return cb.checked; }).map(function(cb) { return cb.parentNode.textContent.trim(); });
    el.textContent = on.length ? on.join(', ') : 'none';
  };
  document.addEventListener('change', function(e) { if (e.target.classList && e.target.classList.contains('health-tag-cb')) window.updateHealthTagsSummary(); });
  document.addEventListener('reset', function() { setTimeout(window.updateHealthTagsSummary, 0); }, true);
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
    if (document.getElementById("ingredients")) document.getElementById("ingredients").value = data.ingredients || '';
    ['protein_g', 'fat_g', 'carbs_g', 'fibre_g'].forEach(function(k) { var el = document.getElementById(k); if (el) el.value = data[k] != null && parseFloat(data[k]) > 0 ? parseFloat(data[k]) : ''; });
    var tagList = String(data.health_tags || '').split(',');
    document.querySelectorAll('.health-tag-cb').forEach(function(cb) { cb.checked = tagList.indexOf(cb.value) !== -1; });
    if (window.updateHealthTagsSummary) window.updateHealthTagsSummary();
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
        
        // Same 5:4 shape the website uses for dish photos (menu cards and the detail view)
        imageCropper = new Cropper(imageToCrop, {
          aspectRatio: 5 / 4, // a bit wider than tall, like the website dish photos
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
      height: 480,
      imageSmoothingEnabled: true,
      imageSmoothingQuality: 'high'
    });
    
    if (croppedCanvas) {
      const croppedDataUrl = canvasToImageDataUrl(croppedCanvas, 0.9);
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
      width: 1000,
      height: 800,
      imageSmoothingEnabled: true,
      imageSmoothingQuality: 'high'
    });
    
    if (croppedCanvas) {
      const croppedDataUrl = canvasToImageDataUrl(croppedCanvas, 0.9);
      
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

      // This management list should still show hidden items (so staff can
      // find and unhide them) even though POS/website exclude them by default.
      params.append('include_hidden', '1');

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
              <span class="availability ${Number(item.is_available) === 1 ? 'available' : 'unavailable'}">${Number(item.is_available) === 1 ? 'In Stock' : 'Out of Stock'}</span>
            </div>
          </div>
        </div>`;
      }

      const isHidden = Number(item.is_hidden) === 1;
      return `
      <div class="menu-item-card${isHidden ? ' item-hidden' : ''}" data-item-id="${item.id}">
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
            <span class="availability ${Number(item.is_available) === 1 ? 'available' : 'unavailable'}">${Number(item.is_available) === 1 ? 'In Stock' : 'Out of Stock'}</span>
            ${isHidden ? '<span class="availability unavailable">Hidden from POS/Website</span>' : ''}
          </div>
        </div>
        <div class="item-actions">
          <button class="btn-edit" onclick="editMenuItem(${item.id}, ${JSON.stringify(item).replace(/&/g, '&amp;').replace(/"/g, '&quot;')})">
            <span class="material-symbols-rounded">edit</span>
            Edit
          </button>
          <button class="btn-edit" onclick="toggleMenuItemHidden(${item.id}, ${isHidden})">
            <span class="material-symbols-rounded">${isHidden ? 'visibility' : 'visibility_off'}</span>
            ${isHidden ? 'Unhide' : 'Hide'}
          </button>
          <button class="btn-edit" onclick="toggleMenuItemStock(${item.id}, ${Number(item.is_available) === 1})">
            <span class="material-symbols-rounded">${Number(item.is_available) === 1 ? 'remove_shopping_cart' : 'add_shopping_cart'}</span>
            ${Number(item.is_available) === 1 ? 'Out of stock' : 'In stock'}
          </button>
          <button class="btn-delete" onclick="deleteMenuItem(${item.id}, ${jsArg(item.item_name_translated || item.item_name_en)})">
            <span class="material-symbols-rounded">delete</span>
            Delete
          </button>
        </div>
      </div>
    `;
    }).join('');
  }

  // Hide/unhide a menu item: hidden items stay in this admin list (so staff
  // can find and unhide them) but drop out of POS, the waiter screen, and
  // the customer website — see toggle_hidden in menu_items_operations_base64.php.
  window.toggleMenuItemHidden = async function(itemId, currentlyHidden) {
    try {
      const formData = new FormData();
      formData.append('action', 'toggle_hidden');
      formData.append('menuItemId', itemId);
      const response = await fetch('../controllers/menu_items_operations_base64.php', {
        method: 'POST',
        body: formData
      });
      const result = await response.json();
      if (result.success) {
        showNotification(currentlyHidden ? 'Item unhidden' : 'Item hidden from POS and website', 'success');
        loadMenuItems();
      } else {
        showNotification(result.message || 'Failed to update item visibility', 'error');
      }
    } catch (error) {
      console.error('Error toggling menu item visibility:', error);
      showNotification('Error updating item visibility', 'error');
    }
  };

  // Mark a dish in / out of stock straight from its card (set_available).
  window.toggleMenuItemStock = async function(itemId, currentlyAvailable) {
    try {
      const formData = new FormData();
      formData.append('action', 'set_available');
      formData.append('menuItemId', itemId);
      formData.append('isAvailable', currentlyAvailable ? 0 : 1);
      const response = await fetch('../controllers/menu_items_operations_base64.php', {
        method: 'POST',
        body: formData
      });
      const result = await response.json();
      if (result.success) {
        showNotification(currentlyAvailable ? 'Marked out of stock' : 'Back in stock', 'success');
        loadMenuItems();
      } else {
        showNotification(result.message || 'Failed to update stock', 'error');
      }
    } catch (error) {
      console.error('Error toggling menu item stock:', error);
      showNotification('Error updating stock', 'error');
    }
  };

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
    // no "are you sure": it goes away at once with Undo for a few seconds (assets/js/rg-undo.js)
    rgUndo({
      html: rgUndo.label('Deleted', menuItemName),
      hide: rgUndo.rows('.menu-item-card[data-item-id="' + menuItemId + '"]'),
      request: { url: '../controllers/menu_items_operations_base64.php', body: { action: 'delete', menuItemId: menuItemId } },
      // a dish that was ordered before is hidden, not deleted (its sales history stays): say so
      onDone: function (d) { loadMenuItems(); if (d && d.hidden) showNotification(d.message, 'info'); },
      onError: function (msg) { showNotification(msg, "error"); }
    });
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
            <button class="btn-edit" onclick="editArea(${area.id}, ${jsArg(area.area_name)})">
              <span class="material-symbols-rounded">edit</span>
            </button>
            <button class="btn-delete" onclick="deleteArea(${area.id}, ${jsArg(area.area_name)})">
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
    // no "are you sure": it goes away at once with Undo for a few seconds (assets/js/rg-undo.js)
    const ev = window.event;
    rgUndo({
      html: rgUndo.label('Deleted area', areaName),
      hide: rgUndo.rowOf(ev),
      request: { url: '../controllers/area_operations.php', body: { action: 'delete', areaId: areaId } },
      onDone: function () { loadAreas(); },
      onError: function (msg) { showMessage(msg, "error"); }
    });
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
    // Tables › Tables is drawn by tables-studio.js now
    if (typeof window.loadTablesStudio === 'function' && !document.getElementById("tableList")) { window.loadTablesStudio(); return; }
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
    var restaurantName = document.getElementById('restaurantName')?.textContent?.trim() || '';

    // Determine base URL - prefer a clean, readable link over the raw
    // restaurant_id: a connected custom domain needs no identifier at all,
    // and otherwise the restaurant-name-based slug ("/my-restaurant/menu")
    // that the customer website already supports is used. Only fall back to
    // "?restaurant_id=" when neither is available.
    var baseUrl;
    if (window.restaurantBrandUrl) {
      baseUrl = window.rgBrandPageUrl('menu');   // a brand outlet: brand.com/<outlet>/menu (config/brand.php)
    } else if (window.restaurantCustomDomain && window.restaurantEmbedEnabled) {
      var domain = window.restaurantCustomDomain.replace(/^https?:\/\//, '');
      var scheme = window.location.protocol + '//';
      baseUrl = scheme + domain + '/menu';
    } else if (window.restaurantWebsiteSlug) {
      var siteRoot = window.location.origin + window.location.pathname.substring(0, window.location.pathname.indexOf('/main/'));
      baseUrl = siteRoot + '/' + encodeURIComponent(window.restaurantWebsiteSlug) + '/menu';
    } else {
      var basePath = window.location.origin + window.location.pathname.substring(0, window.location.pathname.indexOf('/main/') + 6) + 'website/menu.php';
      baseUrl = basePath + '?restaurant_id=' + encodeURIComponent(rid);
    }

    qrGrid.innerHTML = tables.map(table => {
      const tableUrl = baseUrl + (baseUrl.indexOf('?') > -1 ? '&' : '?') + 'table=' + encodeURIComponent(table.table_number);
      const qrCodeUrl = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(tableUrl)}`;
      
      return `        <div class="qr-code-card">
          <div class="qr-code-image">
            <img src="${qrCodeUrl}" alt="QR Code for ${escapeHtml(table.table_number)}" >
          </div>
          <div class="qr-code-info">
            ${restaurantName ? `<div class="qr-code-restaurant-name">${escapeHtml(restaurantName)}</div>` : ''}
            <div class="qr-code-table-name">${escapeHtml(table.table_number)}</div>
            <div class="qr-code-area">${escapeHtml(table.area_name)}</div>
            <div class="qr-code-actions">
              <button class="qr-standee-btn" data-table="${escapeHtml(table.table_number).replace(/"/g, '&quot;')}" data-url="${escapeHtml(tableUrl).replace(/"/g, '&quot;')}" onclick="openQRStandee(this)">
                <span class="material-symbols-rounded">palette</span>
                Design standee
              </button>
              <button class="qr-open-btn" onclick="window.open('${tableUrl}', '_blank')" title="Open in new tab">
                <span class="material-symbols-rounded">open_in_new</span>
                Visit
              </button>
              <button class="qr-download-btn" onclick="downloadQRCode('${qrCodeUrl}', ${jsArg(table.table_number)})">
                <span class="material-symbols-rounded">download</span>
                Download
              </button>
              <button class="qr-print-btn" onclick="printQRCode('${qrCodeUrl}', ${jsArg(table.table_number)})">
                <span class="material-symbols-rounded">print</span>
                Print
              </button>
            </div>
          </div>
        </div>`;
    }).join('');
  }
  
  // Download QR Code
  // qrUrl points at a cross-origin QR generator (api.qrserver.com), and
  // browsers silently ignore the `download` attribute on cross-origin links
  // - it just opens the image instead of saving it. Fetching the image
  // ourselves and downloading it as a same-origin blob makes it actually work.
  window.downloadQRCode = async function(qrUrl, tableName) {
    try {
      const response = await fetch(qrUrl);
      if (!response.ok) throw new Error('Failed to fetch QR code image');
      const blob = await response.blob();
      const objectUrl = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = objectUrl;
      a.download = `QR-${tableName}.png`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(objectUrl);
    } catch (e) {
      console.error('QR download failed', e);
      // Fall back to opening it directly so the user can still save it manually.
      window.open(qrUrl, '_blank');
      if (typeof showSweetAlert === 'function') {
        showSweetAlert('Could not auto-download - opened the QR code in a new tab instead. Right-click it and choose "Save image as...".');
      }
    }
  }
  
  // Print QR Code
  window.printQRCode = function(qrUrl, tableName) {
    const restaurantName = document.getElementById('restaurantName')?.textContent?.trim() || '';
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
      <!DOCTYPE html>
      <html>
        <head>
          <title>${restaurantName ? escapeHtml(restaurantName) + ' - ' : ''}QR Code - ${tableName}</title>
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
            
            .qr-restaurant-name {
              font-size: 1.4rem;
              font-weight: 700;
              color: #1a3934;
              text-transform: uppercase;
              letter-spacing: 1px;
              margin-bottom: 0.5rem;
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
            <div class="qr-logo"><span class="material-symbols-rounded">restaurant</span></div>
            ${restaurantName ? `<div class="qr-restaurant-name">${escapeHtml(restaurantName)}</div>` : ''}
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

  // ===== Photo Gallery =====
  var galleryCategoriesCache = null;
  var currentGalleryImages = [];
  var currentGalleryIndex = -1;

  window.loadPhotoGallery = function() {
    showGalleryCategories();
    if (galleryCategoriesCache) {
      renderGalleryCategories(galleryCategoriesCache);
      return;
    }
    var grid = document.getElementById('galleryCategoryGrid');
    if (grid) grid.innerHTML = '<div class="loading">Loading photo gallery...</div>';
    fetch('../api/get_image_library.php')
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (!d.success) {
          if (grid) grid.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Error</h3><p>' + escapeHtml(d.message || 'Failed to load gallery.') + '</p></div>';
          return;
        }
        galleryCategoriesCache = d.categories || [];
        renderGalleryCategories(galleryCategoriesCache);
      })
      .catch(function() {
        if (grid) grid.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Error</h3><p>Failed to load gallery.</p></div>';
      });
  }

  function renderGalleryCategories(categories) {
    var grid = document.getElementById('galleryCategoryGrid');
    if (!grid) return;
    if (!categories.length) {
      grid.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">photo_library</span><h3>No Photos Found</h3><p>The image library is empty.</p></div>';
      return;
    }
    grid.innerHTML = categories.map(function(cat) {
      var thumbUrl = '../api/image.php?path=' + encodeURIComponent(cat.sample_thumbnail);
      var safeName = escapeHtml(cat.name);
      return '<div class="gallery-card" data-search="' + safeName.toLowerCase() + '" onclick="showGalleryCategory(\'' + encodeURIComponent(cat.name) + '\')">'
        + '<div class="gallery-card-thumb"><img src="' + thumbUrl + '" alt="' + safeName + '" loading="lazy" onerror="this.style.display=\'none\'"></div>'
        + '<div class="gallery-card-info">'
        + '<div class="gallery-card-name">' + safeName + '</div>'
        + '<div class="gallery-card-count">' + cat.count + ' photo' + (cat.count === 1 ? '' : 's') + '</div>'
        + '</div>'
        + '</div>';
    }).join('');
    filterGalleryItems(document.getElementById('gallerySearchInput') ? document.getElementById('gallerySearchInput').value : '');
  }

  window.showGalleryCategory = function(encodedName) {
    var name = decodeURIComponent(encodedName);
    var catGrid = document.getElementById('galleryCategoryGrid');
    var photoGrid = document.getElementById('galleryPhotoGrid');
    var backBtn = document.getElementById('galleryBackBtn');
    var title = document.getElementById('galleryPageTitle');
    var subtitle = document.getElementById('galleryPageSubtitle');
    if (catGrid) catGrid.style.display = 'none';
    if (photoGrid) { photoGrid.style.display = 'grid'; photoGrid.innerHTML = '<div class="loading">Loading photos...</div>'; }
    if (backBtn) backBtn.style.display = 'inline-flex';
    if (title) title.textContent = name;
    if (subtitle) subtitle.textContent = 'Click a photo to preview it full-size';
    var searchInput = document.getElementById('gallerySearchInput');
    if (searchInput) searchInput.value = '';
    var noResults = document.getElementById('galleryNoResults');
    if (noResults) noResults.style.display = 'none';

    fetch('../api/get_image_library.php?category=' + encodeURIComponent(name))
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (!photoGrid) return;
        if (!d.success || !d.images || !d.images.length) {
          photoGrid.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">photo_library</span><h3>No Photos</h3><p>This category has no photos.</p></div>';
          return;
        }
        currentGalleryImages = d.images;
        photoGrid.innerHTML = d.images.map(function(img, i) {
          var url = '../api/image.php?path=' + encodeURIComponent(img.src);
          var safeFile = escapeHtml(img.file);
          return '<div class="gallery-card" data-search="' + safeFile.toLowerCase() + '" onclick="openGalleryLightbox(\'' + encodeURIComponent(img.src) + '\', \'' + encodeURIComponent(img.file) + '\', ' + i + ')">'
            + '<div class="gallery-card-thumb"><img src="' + url + '" alt="' + safeFile + '" loading="lazy" onerror="this.style.display=\'none\'"></div>'
            + '</div>';
        }).join('');
        filterGalleryItems(document.getElementById('gallerySearchInput') ? document.getElementById('gallerySearchInput').value : '');
      })
      .catch(function() {
        if (photoGrid) photoGrid.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">error</span><h3>Error</h3><p>Failed to load photos.</p></div>';
      });
  }

  window.showGalleryCategories = function() {
    var catGrid = document.getElementById('galleryCategoryGrid');
    var photoGrid = document.getElementById('galleryPhotoGrid');
    var backBtn = document.getElementById('galleryBackBtn');
    var title = document.getElementById('galleryPageTitle');
    var subtitle = document.getElementById('galleryPageSubtitle');
    var searchInput = document.getElementById('gallerySearchInput');
    if (catGrid) catGrid.style.display = 'grid';
    if (photoGrid) photoGrid.style.display = 'none';
    if (backBtn) backBtn.style.display = 'none';
    if (title) title.textContent = 'Photo Gallery';
    if (subtitle) subtitle.textContent = 'Browse high-quality stock photos, organized by category';
    if (searchInput) searchInput.value = '';
    filterGalleryItems('');
  }

  // Client-side search: filters whichever grid (categories or photos) is currently
  // visible by matching against each card's data-search attribute, no extra
  // network call needed since both are already loaded/cached.
  window.filterGalleryItems = function(term) {
    var catGrid = document.getElementById('galleryCategoryGrid');
    var photoGrid = document.getElementById('galleryPhotoGrid');
    var noResults = document.getElementById('galleryNoResults');
    var activeGrid = (photoGrid && photoGrid.style.display !== 'none') ? photoGrid : catGrid;
    if (!activeGrid) return;
    var needle = (term || '').trim().toLowerCase();
    var cards = activeGrid.querySelectorAll('.gallery-card');
    var visibleCount = 0;
    cards.forEach(function(card) {
      var haystack = card.getAttribute('data-search') || '';
      var match = !needle || haystack.indexOf(needle) !== -1;
      card.style.display = match ? '' : 'none';
      if (match) visibleCount++;
    });
    if (noResults) noResults.style.display = (needle && cards.length && visibleCount === 0) ? 'block' : 'none';
  }

  window.openGalleryLightbox = function(encodedSrc, encodedFile, idx) {
    var src = decodeURIComponent(encodedSrc);
    var file = decodeURIComponent(encodedFile);
    currentGalleryIndex = (typeof idx === 'number') ? idx : -1;
    var modal = document.getElementById('galleryLightboxModal');
    var img = document.getElementById('galleryLightboxImg');
    var title = document.getElementById('galleryLightboxTitle');
    if (!modal || !img) return;
    img.src = '../api/image.php?path=' + encodeURIComponent(src);
    img.alt = file;
    if (title) title.textContent = file;
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    updateGalleryNavVisibility();
  }

  function updateGalleryNavVisibility() {
    var show = currentGalleryImages.length > 1;
    document.querySelectorAll('#galleryLightboxModal .gallery-lightbox-nav').forEach(function(btn) {
      btn.style.display = show ? 'flex' : 'none';
    });
  }

  window.navigateGalleryLightbox = function(direction) {
    if (!currentGalleryImages.length || currentGalleryIndex < 0) return;
    currentGalleryIndex = (currentGalleryIndex + direction + currentGalleryImages.length) % currentGalleryImages.length;
    var imgData = currentGalleryImages[currentGalleryIndex];
    var img = document.getElementById('galleryLightboxImg');
    var title = document.getElementById('galleryLightboxTitle');
    if (img) img.src = '../api/image.php?path=' + encodeURIComponent(imgData.src);
    if (title) title.textContent = imgData.file;
  }

  window.downloadGalleryImage = function() {
    var img = document.getElementById('galleryLightboxImg');
    var title = document.getElementById('galleryLightboxTitle');
    if (!img || !img.src) return;
    var filename = (title && title.textContent) ? title.textContent : 'photo.jpg';
    fetch(img.src)
      .then(function(r) { return r.blob(); })
      .then(function(blob) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function() { URL.revokeObjectURL(url); }, 1000);
      })
      .catch(function() { showMessage('Could not download photo.', 'error'); });
  }

  window.openGalleryImageFullSize = function() {
    var img = document.getElementById('galleryLightboxImg');
    if (img && img.src) window.open(img.src, '_blank');
  }

  window.copyGalleryImage = function() {
    var img = document.getElementById('galleryLightboxImg');
    if (!img || !img.src) return;
    if (!navigator.clipboard || !window.ClipboardItem) {
      showMessage('Copying images is not supported in this browser.', 'error');
      return;
    }
    fetch(img.src)
      .then(function(r) { return r.blob(); })
      .then(function(blob) {
        return navigator.clipboard.write([new ClipboardItem({ [blob.type]: blob })]);
      })
      .then(function() { showMessage('Photo copied to clipboard.', 'success'); })
      .catch(function() { showMessage('Could not copy photo.', 'error'); });
  }

  var galleryLightboxModalEl = document.getElementById('galleryLightboxModal');
  if (galleryLightboxModalEl) {
    galleryLightboxModalEl.addEventListener('click', function(e) {
      if (e.target === galleryLightboxModalEl) closeModal('galleryLightboxModal');
    });
  }

  document.addEventListener('keydown', function(e) {
    var modal = document.getElementById('galleryLightboxModal');
    if (!modal || modal.style.display !== 'flex') return;
    if (e.key === 'Escape') closeModal('galleryLightboxModal');
    else if (e.key === 'ArrowLeft') navigateGalleryLightbox(-1);
    else if (e.key === 'ArrowRight') navigateGalleryLightbox(1);
  });


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
            <button class="btn-edit" onclick="editTable(${table.id}, ${jsArg(table.table_number)}, ${table.capacity}, ${table.area_id})">
              <span class="material-symbols-rounded">edit</span>
            </button>
            <button class="btn-delete" onclick="deleteTable(${table.id}, ${jsArg(table.table_number)})">
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
    // no "are you sure": it goes away at once with Undo for a few seconds (assets/js/rg-undo.js)
    const ev = window.event;
    rgUndo({
      html: rgUndo.label('Deleted table', tableNumber),
      hide: rgUndo.rowOf(ev),
      request: { url: '../controllers/table_operations.php', body: { action: 'delete', tableId: tableId } },
      onDone: function () { loadTables(); },
      onError: function (msg) { showMessage(msg, "error"); }
    });
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
    const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().split('T')[0];
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
      const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().split('T')[0];
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
          const phoneMinLen = window.restaurantPhoneMin || 10;
          const phoneMaxLen = window.restaurantPhoneMax || 10;
          if (phoneDigits.length < phoneMinLen || phoneDigits.length > phoneMaxLen) {
            showFieldError('phone', 'Please enter a valid phone number.');
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
          const phoneMinLen = window.restaurantPhoneMin || 10;
          const phoneMaxLen = window.restaurantPhoneMax || 10;
          if (phoneDigits.length < phoneMinLen || phoneDigits.length > phoneMaxLen) {
            showFieldError('phone', 'Please enter a valid phone number.');
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
        const phoneMinLen = window.restaurantPhoneMin || 10;
        const phoneMaxLen = window.restaurantPhoneMax || 10;
        if (phoneDigits.length < phoneMinLen || phoneDigits.length > phoneMaxLen) {
          errors.push("Please enter a valid phone number");
          showFieldError('phone', 'Please enter a valid phone number.');
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
  window.loadReservations = function () { return loadReservations(); };
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
    if (window.RGResv) return window.RGResv.render(reservations);
    
    if (reservations.length === 0) {
      reservationList.innerHTML = '<div class="empty-state"><span class="material-symbols-rounded">inbox</span><h3>No reservations found</h3><p>Create your first reservation to get started.</p></div>';
      return;
    }

    refreshReservationList();
  }
  
  function refreshReservationList() {
    // the redesigned list (day strip, timeline, one-tap actions): assets/js/reservations-studio.js
    if (window.RGResv) return window.RGResv.render(window.currentReservationsData || []);
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
    // no "are you sure": it goes away at once with Undo for a few seconds (assets/js/rg-undo.js)
    const ev = window.event;
    rgUndo({
      html: rgUndo.label('Deleted booking for', customerName),
      hide: rgUndo.rowOf(ev, 'tr, .rsv-card, .rs-card, [data-res-id], .card'),
      request: { url: '../controllers/reservation_operations.php', body: { action: 'delete', reservationId: reservationId } },
      onDone: function () { loadReservations(); },
      onError: function (msg) { showMessage(msg, "error"); }
    });
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
                <span class="material-symbols-rounded" style="font-size: 24px; color: #6b7280;">table_restaurant</span>
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
    // no "are you sure": it goes away at once with Undo for a few seconds (assets/js/rg-undo.js)
    const ev = window.event;
    rgUndo({
      html: rgUndo.label('Deleted customer', customerName),
      hide: rgUndo.rowOf(ev, 'tr'),
      request: { url: '../controllers/customer_operations.php', body: { action: 'delete', customerId: customerId } },
      onDone: function () { loadCustomers(); },
      onError: function (msg) { showMessage(msg, "error"); }
    });
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
    
    // "Neha (Koramangala)" -> "NE": leave out the branch in brackets and stray symbols
    const initials = (name) => { const w = String(name || '').replace(/\(.*?\)/g, ' ').replace(/[^\p{L}\p{N} ]/gu, ' ').trim().split(/\s+/).filter(Boolean); return w.length ? (w.length > 1 ? w[0].charAt(0) + w[1].charAt(0) : w[0].slice(0, 2)).toUpperCase() : '?'; };
    
    customerList.innerHTML = customers.map(customer => {
      const totalSpent = customer.total_spent ? formatCurrency(customer.total_spent) : formatCurrency(0);
      
      return `
        <tr data-customer-id="${customer.id || 'order-' + Math.random()}">
          <td class="avatar-cell">
            <div class="avatar-small">${initials(customer.customer_name)}</div>
          </td>
          <td>${escapeHtml(customer.customer_name)}${customer.has_account ? ' <span class="pc-tag" title="Has a website account">Online</span>' : ''}</td>
          <td>${escapeHtml(customer.phone || '-')}</td>
          <td>${escapeHtml(customer.email || '-')}</td>
          <td>${escapeHtml(customer.address || '-')}</td>
          <td>${customer.total_visits || 0}</td>
          <td>${totalSpent}</td>
          <td>${parseInt(customer.loyalty_points_balance, 10) > 0 ? parseInt(customer.loyalty_points_balance, 10).toLocaleString('en-IN') : '-'}</td>
          <td class="action-cell">
            ${customer.id && /^\d+$/.test(String(customer.id)) && !customer.has_account && customer.phone ? `<button class="btn-action-small" title="Show a QR code: the customer scans it to make their website account with these points" onclick="rgCustomerAccountQR(${customer.id}, ${JSON.stringify(customer.customer_name).replace(/&/g, '&amp;').replace(/"/g, '&quot;')})">
              <span class="material-symbols-rounded">qr_code_2</span>
              <span>Account QR</span>
            </button>` : ''}
            <button class="btn-action-small edit-btn" onclick="editCustomer(${customer.id}, ${JSON.stringify(customer.customer_name).replace(/&/g, '&amp;').replace(/"/g, '&quot;')}, ${JSON.stringify(customer.phone || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;')}, ${JSON.stringify(customer.email || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;')})">
              <span class="material-symbols-rounded">edit</span>
              <span>Update</span>
            </button>
            <button class="btn-action-small delete-btn" onclick="deleteCustomer(${customer.id}, ${JSON.stringify(customer.customer_name).replace(/&/g, '&amp;').replace(/"/g, '&quot;')})">
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
                        <button class="btn" style="background: white; border: 1px solid #d9d9d9; color: #333; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem;" onclick="showOrderItems(${JSON.stringify(request.notes || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;')})">
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
                    <td style="padding: 0.75rem;">${escapeHtml(item.name)}</td>
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
      const response = await rgFetchRetry(`../api/get_order_details_by_id.php?id=${orderId}`);
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
                  <p style="margin: 0.5rem 0; color: #1f2937; font-size: 0.95rem;"><strong style="color: #374151;">Customer:</strong> <span style="color: #111827;">${escapeHtml(order.customer_name) || 'N/A'}</span></p>
                  <p style="margin: 0.5rem 0; color: #1f2937; font-size: 0.95rem;"><strong style="color: #374151;">Type:</strong> <span style="color: #111827;">${order.order_type || 'N/A'}</span></p>
                  ${order.customer_address ? `<p style="margin: 0.5rem 0; color: #1f2937; font-size: 0.95rem;"><strong style="color: #374151;">Address:</strong> <span style="color: #111827;">${escapeHtml(order.customer_address)}</span></p>` : ''}
                  ${order.landmark ? `<p style="margin: 0.5rem 0; color: #1f2937; font-size: 0.95rem;"><strong style="color: #374151;">Landmark:</strong> <span style="color: #111827;">${escapeHtml(order.landmark)}</span></p>` : ''}
                  <p style="margin: 0.5rem 0; color: #1f2937; font-size: 0.95rem;"><strong style="color: #374151;">Status:</strong> <span class="status-badge ${order.order_status.toLowerCase()}">${order.order_status}</span></p>
                  <p style="margin: 0.5rem 0; color: #1f2937; font-size: 0.95rem;"><strong style="color: #374151;">Payment:</strong> <span class="status-badge ${order.payment_status.toLowerCase().replace(' ', '-')}">${order.payment_status}</span></p>
                  <p style="margin: 0.5rem 0; color: #1f2937; font-size: 0.95rem;"><strong style="color: #374151;">Time:</strong> <span style="color: #111827;">${new Date(order.created_at).toLocaleString()}</span></p>
                  ${typeof buildDeliveryMapHtml === 'function' ? buildDeliveryMapHtml(order) : ''}
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
                            <div style="font-weight: 500; color: #111827; font-size: 0.95rem;">${escapeHtml(item.item_name)}</div>
                            ${item.notes ? `<div style="font-size: 0.875rem; color: #d97706; margin-top: 4px; font-weight: 500;">Note: ${escapeHtml(item.notes)}</div>` : ''}
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
                
                ${typeof buildPaymentProofHtml === 'function' ? buildPaymentProofHtml(order, 220) : ''}
                ${order.order_type === 'Delivery' ? `
                  <div id="qrSection-${order.id}" style="margin-top: 1rem; padding: 1rem; background: #f0fdf4; border-radius: 8px; border: 1px solid #86efac;">
                    <strong style="color: #166534;">Delivery</strong>
                    <div class="rg-rider-assign" data-order="${order.id}" style="margin-top: 0.5rem;"></div>
                    <div style="margin-top: 0.5rem;">
                      <button class="btn btn-success" onclick="generateDeliveryQR(${order.id})" style="background: #16a34a; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                        <span class="material-symbols-rounded" style="font-size: 1.1rem;">qr_code</span>
                        Generate QR for Rider
                      </button>
                      <div id="qrDisplay-${order.id}" style="display:none; margin-top: 0.75rem; text-align: center;"></div>
                    </div>
                    <div class="rg-book-delivery" data-order="${order.id}"></div>
                  </div>
                ` : ''}
                ${order.notes ? `
                  <div style="margin-top: 1rem; padding: 1rem; background: #fff7ed; border-radius: 8px; border: 1px solid #fdba74;">
                    <strong style="color: #92400e;">Notes:</strong>
                    <p style="margin: 0.5rem 0 0 0; color: #78350f;">${escapeHtml(order.notes)}</p>
                  </div>
                ` : ''}
              </div>
            </div>
          </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        if (window.rgBookDeliveryInit) window.rgBookDeliveryInit(order);   // Uber / Rapido / Porter… (assets/js/delivery-partners.js)

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
                  <p><strong>Customer:</strong> ${escapeHtml(order.customer_name) || 'N/A'}</p>
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
                          <td style="padding: 0.75rem;">${escapeHtml(item.item_name)}${item.notes ? `<div style="font-size:0.8rem;color:#92400e;margin-top:2px"><span class="material-symbols-rounded" style="font-size:15px;vertical-align:-3px;margin-right:3px">edit_note</span>${escapeHtml(item.notes)}</div>` : ''}</td>
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
    
    // "Neha (Koramangala)" -> "NE": leave out the branch in brackets and stray symbols
    const initials = (name) => { const w = String(name || '').replace(/\(.*?\)/g, ' ').replace(/[^\p{L}\p{N} ]/gu, ' ').trim().split(/\s+/).filter(Boolean); return w.length ? (w.length > 1 ? w[0].charAt(0) + w[1].charAt(0) : w[0].slice(0, 2)).toUpperCase() : '?'; };
    
    staffList.innerHTML = staff.map(member => {
      return `
        <tr data-staff-id="${member.id}">
          <td class="avatar-cell">
            <div class="avatar-small">${initials(member.member_name)}</div>
          </td>
          <td>${escapeHtml(member.member_name)}</td>
          <td>${escapeHtml(member.email)}</td>
          <td>${escapeHtml(member.phone)}</td>
          <td>${escapeHtml(member.role === 'Delivery' ? 'Delivery rider' : member.role)}</td>
          <td class="action-cell">
            <button class="btn-action-small edit-btn" onclick="editStaff(${member.id}, ${jsArg(member.member_name)}, ${jsArg(member.email)}, '${member.phone}', '${member.role}')">
              <span class="material-symbols-rounded">edit</span>
              <span>Update</span>
            </button>
            <button class="btn-action-small delete-btn" onclick="deleteStaff(${member.id}, ${jsArg(member.member_name)})">
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
    // no "are you sure": it goes away at once with Undo for a few seconds (assets/js/rg-undo.js)
    const ev = window.event;
    rgUndo({
      html: rgUndo.label('Deleted staff member', memberName),
      hide: rgUndo.rows('[data-staff-id="' + staffId + '"]').concat(rgUndo.rowOf(ev, '.stf-card, tr') || []),
      request: { url: '../controllers/staff_operations.php', body: { action: 'delete', staffId: staffId } },
      onDone: function () { loadStaff(); if (window.loadStaffStudio) window.loadStaffStudio(); },
      onError: function (msg) { showMessage(msg, "error"); }
    });
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
  // menu item id => 'kitchen' | 'bar' (api/get_menu_items.php): bar dishes go on a BOT, not the KOT
  const posStation = {};
  // The send button says what it will make: KOT, BOT, or KOT + BOT (config/prep_stations.php)
  function updatePOSSendLabel() {
    const delta = window.posKitchenDelta ? window.posKitchenDelta(posCart) : { items: [], addons: false, combos: [] };
    const bar = delta.items.some(i => posStation[i.id] === 'bar');
    const kitchen = delta.items.some(i => posStation[i.id] !== 'bar') || delta.addons || (delta.combos || []).length > 0;
    const label = bar && kitchen ? 'KOT + BOT' : (bar ? 'BOT' : 'KOT');
    const icon = bar && !kitchen ? 'local_bar' : 'soup_kitchen';
    const btn = document.getElementById('sendKotBtn');
    if (btn) btn.innerHTML = `<span class="material-symbols-rounded">${icon}</span>${label}`;
    const mob = document.getElementById('mobileSendKotBtn');
    if (mob) mob.innerHTML = `<span class="material-symbols-rounded">${icon}</span><span>${label}</span>`;
  }

  // Debounced re-filter/re-render for the POS search box, so we don't
  // re-render the grid on every keystroke while the user is still typing.
  let posSearchDebounceTimer = null;
  function handlePOSSearchInput() {
    clearTimeout(posSearchDebounceTimer);
    posSearchDebounceTimer = setTimeout(() => {
      renderPOSMenuGrid(filterPOSItemsBySearch(allPOSItems));
    }, 250);
  }

  // Display POS menu items
  function displayPOSMenuItems(items) {
    // Store items for mobile modal
    allPOSItems = items;
    items.forEach(it => { posStation[it.id] = it.station; });

    renderPOSMenuGrid(filterPOSItemsBySearch(items));

    // Update mobile modal items list
    updateMobileItemsList(items);
  }

  // Filter POS menu items by the desktop search box
  function filterPOSItemsBySearch(items) {
    const searchInput = document.getElementById('posSearchInput');
    const q = (searchInput?.value || '').toLowerCase().trim();
    if (!q) return items;
    return items.filter(item => {
      const name = (item.item_name_translated || item.item_name_en || '').toLowerCase();
      const category = (item.item_category || '').toLowerCase();
      return name.includes(q) || category.includes(q);
    });
  }

  // Render the POS menu item grid (desktop)
  function renderPOSMenuGrid(items) {
    const posMenuItemsContainer = document.getElementById("posMenuItems");

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
           data-menu="${item.menu_id || ''}" data-menu-name="${escapeHtml(item.menu_name || '')}" data-type="${escapeHtml(item.item_type || '')}"
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
  const posSearchInput = document.getElementById("posSearchInput");

  if (posMenuFilter) {
    posMenuFilter.addEventListener("change", loadPOSMenuItems);
  }
  if (posCategoryFilter) {
    posCategoryFilter.addEventListener("change", loadPOSMenuItems);
  }
  if (posTypeFilter) {
    posTypeFilter.addEventListener("change", loadPOSMenuItems);
  }
  if (posSearchInput) {
    posSearchInput.addEventListener("input", handlePOSSearchInput);
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
            `<option value="${menu.id}" data-hours="${escapeHtml(menu.hours_label || '')}" data-served="${menu.served_now === false ? '0' : '1'}">${escapeHtml(menu.menu_name)}</option>`
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
  
  // A combo from the POS combos strip (assets/js/pos-combos.js): one line per combo + choices
  //   { isCombo, comboId, cartKey 'combo_<id>_<choice ids>', name, price (one combo), quantity, picks, summary }
  window.addComboToPOSCart = function (line) {
    const existing = posCart.find(item => item.cartKey === line.cartKey);
    if (existing) existing.quantity += line.quantity || 1;
    else posCart.push(Object.assign({ id: 'c' + line.comboId, quantity: 1 }, line, { isCombo: true }));
    updatePOSCart();
  };

  // A cart line's total: the dish plus its add-ons, per unit (assets/js/pos-addons.js)
  window.posLineTotal = function (it) {
    const extra = (it.addons || []).reduce((s, a) => s + (parseFloat(a.price) || 0), 0);
    return (parseFloat(it.price) + extra) * it.quantity;
  };
  // What the server gets: dishes (with their add-on ids) and order add-ons, separately
  window.posCartPayload = function (cart) {
    return {
      items: cart.filter(i => !i.isAddon && !i.isCombo).map(i => Object.assign({}, i, { addons: (i.addons || []).map(a => ({ id: a.id })) })),
      globalAddons: cart.filter(i => i.isAddon).map(i => ({ id: i.addonId, quantity: i.quantity })),
      combos: cart.filter(i => i.isCombo).map(i => ({ id: i.comboId, quantity: i.quantity, picks: i.picks || {} }))
    };
  };

  // Service charge, tax and total after discounts, by the restaurant's bill rule: tax added on top, or
  // already inside the menu price (Nepal), plus an optional service charge. Same maths as billMath() in
  // config/bill_rules.php, which checks every bill. Settings: window.pricesIncludeTax, window.serviceChargePercent.
  window.rgBillMath = function (taxable) {
    const r2 = n => Math.round((n + Number.EPSILON) * 100) / 100;
    const showGst = window.enableGst == 1 || window.enableGst === true;
    const t = showGst ? (parseFloat(window.taxPercent ?? 5) || 0) / 100 : 0;
    const s = Math.max(0, Math.min(30, parseFloat(window.serviceChargePercent) || 0)) / 100;
    taxable = r2(Math.max(0, taxable));
    if (window.pricesIncludeTax == 1 || window.pricesIncludeTax === true) {
      const net = r2(taxable / ((1 + s) * (1 + t)));
      const sc = r2(net * s);
      return { net, serviceCharge: sc, tax: r2(taxable - net - sc), total: taxable, inclusive: true };
    }
    const sc = r2(taxable * s);
    const tax = r2((taxable + sc) * t);
    return { net: taxable, serviceCharge: sc, tax, total: r2(taxable + sc + tax), inclusive: false };
  };

  // One place where a POS bill's money is worked out, so the cart summary, the
  // Pay window and the printed bill can never disagree. Every discount — the
  // cashier's manual discount (assets/js/pos-discount.js), the customer's reward
  // code and their points (assets/js/pos-customer.js) — comes off the subtotal
  // BEFORE tax, and the bill can never fall below zero.
  window.posComputeTotals = function (cart) {
    cart = Array.isArray(cart) ? cart : (cart ? [].slice.call(cart) : []);
    const subtotal = cart.reduce((sum, item) => sum + window.posLineTotal(item), 0);
    const showGst = window.enableGst == 1 || window.enableGst === true;
    const pcPrice = window.rgPosCustomer ? window.rgPosCustomer.pricing(subtotal) : null;
    const taken = pcPrice ? (pcPrice.coupon + pcPrice.pointsDiscount) : 0;   // customer's reward + points first
    const manual = window.rgPosDiscount ? window.rgPosDiscount.pricing(subtotal, taken) : null;   // then the cashier's own
    const taxable = Math.max(0, subtotal - taken - (manual ? manual.amount : 0));
    const bill = window.rgBillMath(taxable);
    const tax = bill.tax, total = bill.total, serviceCharge = bill.serviceCharge, inclusive = bill.inclusive;
    return {
      subtotal, showGst, pcPrice, manual,
      coupon: pcPrice ? pcPrice.coupon : 0,
      couponCode: pcPrice ? pcPrice.couponCode : '',
      points: pcPrice ? pcPrice.points : 0,
      pointsDiscount: pcPrice ? pcPrice.pointsDiscount : 0,
      taken,
      manualAmount: manual ? manual.amount : 0,
      manualType: manual ? manual.type : 'amount',
      manualValue: manual ? manual.value : 0,
      manualLabel: manual ? manual.label : '',
      taxable, tax, total, serviceCharge, inclusive
    };
  };

  // Update POS cart display
  function updatePOSCart() {
    const cartItemsContainer = document.getElementById("posCartItems");

    if (posCart.length === 0) {
      if (window.posResetBill) window.posResetBill(); else window._kotSentForCurrentOrder = false;   // an open bill stays in Orders; new dishes start a new one
      if (window.rgPosDiscount) window.rgPosDiscount.reset();   // a manual discount belongs to one bill
      cartItemsContainer.innerHTML = `
        <div class="empty-cart">
          <span class="material-symbols-rounded">shopping_cart</span>
          <p>Cart is empty</p>
          <p class="empty-subtext">Add items from the menu</p>
        </div>
      `;
    } else {
      const unsent = window.posKitchenDelta ? window.posKitchenDelta(posCart) : { items: [], addons: false };
      // where the sent dishes went (kitchen, bar or both) and what the button will send next
      const sentBar = posCart.some(it => posStation[it.id] === 'bar' && (window.posSentQty ? window.posSentQty(it.cartKey || String(it.id)) : 0) > 0);
      const sentKitchen = posCart.some(it => posStation[it.id] !== 'bar' && (window.posSentQty ? window.posSentQty(it.cartKey || String(it.id)) : 0) > 0);
      const sentTo = sentBar && sentKitchen ? 'the kitchen and the bar' : (sentBar ? 'the bar' : 'the kitchen');
      const unsentBar = unsent.items.some(i => posStation[i.id] === 'bar');
      const unsentKitchen = unsent.items.some(i => posStation[i.id] !== 'bar') || unsent.addons || (unsent.combos || []).length > 0;
      const nextBtn = unsentBar && unsentKitchen ? 'KOT + BOT' : (unsentBar ? 'BOT' : 'KOT');
      const banner = window.posOpenBill ? `<div class="pk-bill"><span class="material-symbols-rounded">${sentBar && !sentKitchen ? 'local_bar' : 'soup_kitchen'}</span><span><b>Bill ${escapeHtml(window.posOpenBill.number)}</b> is open: sent to ${sentTo}, not paid yet.${unsentBar || unsentKitchen ? ` <em>New dishes: press ${nextBtn} to send them.</em>` : ''}</span></div>` : '';
      cartItemsContainer.innerHTML = banner + posCart.map(item => {
        const key = item.cartKey || item.id.toString();
        const sentQ = window.posSentQty ? window.posSentQty(key) : 0;
        return `
        <div class="cart-item">
          <div class="cart-item-info">
            <div class="cart-item-name">${escapeHtml(item.name)}${item.isAddon ? ' <span class="pa-tag">Add-on</span>' : ''}${item.isCombo ? ' <span class="pa-tag pc-tag">Combo</span>' : ''}${posStation[item.id] === 'bar' ? ' <span class="pa-tag" title="Made at the bar: goes on the BOT">Bar</span>' : ''}${sentQ > 0 ? (posStation[item.id] === 'bar' ? ` <span class="pk-sent" title="Already sent to the bar">${sentQ >= item.quantity ? 'At bar' : sentQ + ' at bar'}</span>` : ` <span class="pk-sent" title="Already sent to the kitchen">${sentQ >= item.quantity ? 'In kitchen' : sentQ + ' in kitchen'}</span>`) : ''}</div>
            <div class="cart-item-price">${formatCurrency(item.price)} each</div>
            ${item.isCombo ? '<div class="pc-what">' + escapeHtml(item.summary || '') + '</div>' : (window.rgPosAddons ? window.rgPosAddons.lineHtml(item, key) : '')}
          </div>
          <div class="cart-item-controls">
            <button class="cart-qty-btn" onclick="updatePOSCartQty('${key.replace(/'/g, "\\'")}', -1)">-</button>
            <span class="cart-qty-value">${item.quantity}</span>
            <button class="cart-qty-btn" onclick="updatePOSCartQty('${key.replace(/'/g, "\\'")}', 1)">+</button>
          </div>
          <div class="cart-item-total">${formatCurrency(window.posLineTotal(item))}</div>
        </div>`;
      }).join('');
    }
    
    updatePOSCartSummary();
    updatePOSSendLabel();
    // the menu grid shows how many of each dish are in the cart (assets/js/pos-studio.js)
    try { window.dispatchEvent(new CustomEvent('rg-pos-cart', { detail: posCart.map(function (i) { return { id: i.id, q: i.quantity, k: i.cartKey || String(i.id) }; }) })); } catch (e) {}
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
    // the shared money function: manual discount + reward code + points, all before tax
    const T = window.posComputeTotals(posCart);
    const subtotal = T.subtotal, showGst = T.showGst, tax = T.tax, total = T.total;
    // the customer rows go in first, then the manual discount lands directly under the subtotal
    if (window.rgPosCustomer) window.rgPosCustomer.paintSummary(T.pcPrice, total);
    if (window.rgPosDiscount) window.rgPosDiscount.paintSummary(subtotal, T.taken);

    // Update desktop cart summary
    const cartSubtotalEl = document.getElementById("cartSubtotal");
    const cartTaxEl = document.getElementById("cartTax");
    const cartTotalEl = document.getElementById("cartTotal");

    if (cartSubtotalEl) cartSubtotalEl.textContent = formatCurrency(subtotal);
    // Show/hide the tax row based on the enableGst setting
    if (cartTaxEl && cartTaxEl.parentElement) {
      cartTaxEl.parentElement.style.display = showGst ? "" : "none";
    }
    if (cartTaxEl) cartTaxEl.textContent = formatCurrency(showGst ? tax : 0);
    if (cartTotalEl) cartTotalEl.textContent = formatCurrency(total);

    // Update mobile bill summary (POS page)
    const mobilePosBillSubtotalEl = document.getElementById("mobilePosBillSubtotal");
    const mobilePosBillTaxEl = document.getElementById("mobilePosBillTax");
    const mobilePosBillTotalEl = document.getElementById("mobilePosBillTotal");

    if (mobilePosBillSubtotalEl) mobilePosBillSubtotalEl.textContent = formatCurrency(subtotal);
    if (mobilePosBillTaxEl && mobilePosBillTaxEl.parentElement) {
      mobilePosBillTaxEl.parentElement.style.display = showGst ? "flex" : "none";
    }
    if (mobilePosBillTaxEl) mobilePosBillTaxEl.textContent = formatCurrency(tax);
    if (mobilePosBillTotalEl) mobilePosBillTotalEl.textContent = formatCurrency(total);
  }
  
  window.updatePOSCartSummaryHook = updatePOSCartSummary;
  // the cart for pos-addons.js (add-ons on a line, order add-ons)
  window.rgPosCartApi = { get: function () { return posCart; }, set: function (c) { posCart = c; updatePOSCart(); }, refresh: function () { updatePOSCart(); } };

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
      
      const clearMsg = window.posOpenBill
        ? 'Bill ' + window.posOpenBill.number + ' is already in the kitchen. Clearing only empties this screen: the bill stays open (unpaid) in Orders, where you can mark it paid.'
        : "Are you sure you want to clear the cart?";
      if (cart.length > 0 && await showSweetConfirm(clearMsg, 'Clear Cart')) {
        if (window.rgPosCustomer) window.rgPosCustomer.reset();
        if (window.rgPosDiscount) window.rgPosDiscount.reset();
        window.posResetBill();
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
  
  // Hold order function (one at a time: a double tap would put the same cart on hold twice)
  let posHolding = false;
  async function holdOrder() {
    if (posHolding) return;
    posHolding = true;
    try { return await holdOrderOnce(); } finally { posHolding = false; }
  }
  async function holdOrderOnce() {
    // Support both posCart (admin) and window.posCart (waiter/chef/manager)
    const cart = typeof posCart !== 'undefined' ? posCart : (window.posCart || []);
    if (window.posOpenBill) {
      const say = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
      say('Bill ' + window.posOpenBill.number + ' is already saved and in the kitchen. Use KOT for new dishes and Pay when the guest leaves.', 'info');
      return;
    }
    const updateCartFn = typeof updatePOSCart !== 'undefined' ? updatePOSCart : (window.updateWaiterPOSCart || window.updatePOSCart || (() => {}));
    
    if (cart.length === 0) {
      const showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
      showMsg("Cart is empty", "error");
      return;
    }
    
      const subtotal = cart.reduce((sum, item) => sum + window.posLineTotal(item), 0);
      const { tax, total } = window.rgBillMath(subtotal);
      const selectPosTable = document.getElementById("selectPosTable");
      const selectedTable = selectPosTable ? selectPosTable.value : '';

      const formData = new URLSearchParams();
      if (cart.some(i => i.isCombo)) {
        const showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
        showMsg("Bills with a combo can't go on hold yet. Press KOT instead: the bill stays open until Pay.", "info");
        return;
      }
      formData.append('action', 'hold_order');
      formData.append('tableId', selectedTable || '');
      const payload = window.posCartPayload(cart);
      formData.append('cartItems', JSON.stringify(payload.items));
      formData.append('globalAddons', JSON.stringify(payload.globalAddons));
      const holdCust = window.rgPosCustomer ? window.rgPosCustomer.get() : null;
      if (holdCust) formData.append('customerId', holdCust.id);
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
          if (window.refreshHeldCount) window.refreshHeldCount();
          if (window.rgPosCustomer) window.rgPosCustomer.reset();
          if (window.rgPosDiscount) window.rgPosDiscount.reset();   // a discount is not held with the order
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

  // ── Held orders: list / resume / discard ──────────────────────────────────
  // Resuming loads the held items back into the cart and retires the held
  // order on the server, so paying for it records the sale exactly once.
  async function heldOrdersRequest(action, orderId) {
    const body = new URLSearchParams({ action });
    if (orderId) body.append('orderId', orderId);
    const res = await fetch("../controllers/pos_operations.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body
    });
    return res.json();
  }
  function heldMsg(msg, type) {
    const showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
    showMsg(msg, type);
  }
  async function refreshHeldCount() {
    const badge = document.getElementById('heldOrdersCount');
    if (!badge) return [];
    try {
      const d = await heldOrdersRequest('list_held');
      const list = (d && d.success && d.orders) || [];
      badge.textContent = list.length;
      badge.style.display = list.length ? 'inline-block' : 'none';
      return list;
    } catch (e) { return []; }
  }
  window.refreshHeldCount = refreshHeldCount;

  function closeHeldOrders() {
    const m = document.getElementById('heldOrdersModal');
    if (m) m.remove();
  }
  window.closeHeldOrders = closeHeldOrders;

  async function openHeldOrders() {
    closeHeldOrders();
    const cur = window.globalCurrencySymbol || '₹';
    const modal = document.createElement('div');
    modal.id = 'heldOrdersModal';
    modal.style.cssText = 'position:fixed;inset:0;z-index:10050;background:rgba(0,0,0,0.45);display:flex;align-items:center;justify-content:center;padding:16px;';
    modal.innerHTML = '<div style="background:#fff;border-radius:14px;width:100%;max-width:440px;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,0.25);">' +
      '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #eee;">' +
        '<strong style="font-size:1.05rem;">Held orders</strong>' +
        '<button type="button" onclick="closeHeldOrders()" style="border:0;background:none;font-size:22px;cursor:pointer;line-height:1;">&times;</button></div>' +
      '<div id="heldOrdersList" style="overflow:auto;padding:10px 16px 16px;"><p style="color:#6b7280;">Loading…</p></div></div>';
    modal.addEventListener('click', (e) => { if (e.target === modal) closeHeldOrders(); });
    document.body.appendChild(modal);
    const list = await refreshHeldCount();
    const box = document.getElementById('heldOrdersList');
    if (!box) return;
    if (!list.length) { box.innerHTML = '<p style="color:#6b7280;text-align:center;padding:18px 0;">No orders on hold.</p>'; return; }
    box.innerHTML = list.map(o => {
      const when = new Date(String(o.created_at).replace(' ', 'T'));
      const time = isNaN(when) ? '' : when.toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
      return '<div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f3f4f6;">' +
        '<div style="flex:1;min-width:0;">' +
          '<div style="font-weight:600;">' + escapeHtml(o.order_number) + ' · ' + (o.table_number ? 'Table ' + escapeHtml(o.table_number) : 'Takeaway') + '</div>' +
          '<div style="font-size:0.8rem;color:#6b7280;">' + (parseInt(o.item_count, 10) || 0) + ((parseInt(o.item_count, 10) || 0) === 1 ? ' item · ' : ' items · ') + cur + parseFloat(o.total).toFixed(2) + (time ? ' · ' + time : '') + '</div></div>' +
        '<button type="button" class="btn btn-primary" style="padding:6px 12px;width:auto;flex:0 0 auto;" onclick="resumeHeldOrder(' + parseInt(o.id, 10) + ')">Resume</button>' +
        '<button type="button" class="btn btn-secondary" style="padding:6px 8px;width:40px;min-width:0;flex:0 0 40px;" title="Discard" onclick="discardHeldOrder(' + parseInt(o.id, 10) + ')"><span class="material-symbols-rounded" style="font-size:1.1rem;vertical-align:middle;">delete</span></button>' +
      '</div>';
    }).join('');
  }
  window.openHeldOrders = openHeldOrders;

  async function resumeHeldOrder(orderId) {
    const cart = typeof posCart !== 'undefined' ? posCart : (window.posCart || []);
    const updateCartFn = typeof updatePOSCart !== 'undefined' ? updatePOSCart : (window.updateWaiterPOSCart || window.updatePOSCart || (() => {}));
    if (cart.length) { heldMsg('Finish, hold or clear the current cart first.', 'error'); return; }
    let d;
    try { d = await heldOrdersRequest('resume_held', orderId); } catch (e) { heldMsg('Network error. Please try again.', 'error'); return; }
    if (!d || !d.success) { heldMsg((d && d.message) || 'Could not resume this order.', 'error'); openHeldOrders(); return; }
    (d.items || []).forEach(it => {
      if (!it.menu_item_id && it.variation_name === 'Add-on') {   // an order add-on line
        const aid = parseInt(it.addon_id, 10) || 0;
        cart.push({ id: 'addon-' + aid, isAddon: true, addonId: aid, cartKey: 'addon_' + aid, name: it.item_name, originalName: it.item_name, price: parseFloat(it.unit_price), image: null, quantity: parseInt(it.quantity, 10) || 1 });
        return;
      }
      const v = it.variation_name || null;
      const baseName = String(it.item_name || '');
      cart.push({
        id: parseInt(it.menu_item_id, 10),
        cartKey: v ? it.menu_item_id + '_' + v : String(it.menu_item_id) + (cart.some(c => String(c.id) === String(it.menu_item_id)) ? '_' + baseName : ''),
        name: v ? baseName + ' (' + v + ')' : baseName,
        originalName: baseName,
        price: parseFloat(it.unit_price),
        image: null,
        variationName: v,
        addons: (it.addons || []).map(a => ({ id: a.id, name: a.name, price: parseFloat(a.price) || 0 })),
        quantity: parseInt(it.quantity, 10) || 1
      });
      const last = cart[cart.length - 1];
      if (last.addons.length) last.cartKey += '_a' + last.addons.map(a => a.id).sort().join('-');
    });
    const sel = document.getElementById('selectPosTable');
    if (sel) sel.value = d.table_id ? String(d.table_id) : '';
    updateCartFn();
    if (d.customer_id && window.rgPosCustomer && window.rgPosCustomer.load) window.rgPosCustomer.load(d.customer_id);
    closeHeldOrders();
    refreshHeldCount();
    heldMsg('Order ' + d.order_number + ' is back in the cart.', 'success');
  }
  window.resumeHeldOrder = resumeHeldOrder;

  async function discardHeldOrder(orderId) {
    if (!(await (window.rgConfirm ? rgConfirm('It will not be billed.', { title: 'Discard this held order?', confirmText: 'Discard' }) : Promise.resolve(window.confirm('Discard this held order? It will not be billed.'))))) return;
    let d;
    try { d = await heldOrdersRequest('discard_held', orderId); } catch (e) { heldMsg('Network error. Please try again.', 'error'); return; }
    heldMsg(d && d.success ? 'Held order ' + d.order_number + ' discarded.' : ((d && d.message) || 'Could not discard.'), d && d.success ? 'success' : 'error');
    openHeldOrders();
  }
  window.discardHeldOrder = discardHeldOrder;
  if (document.getElementById('heldOrdersBtn')) refreshHeldCount();
  
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
            if (phone.length >= (window.restaurantPhoneMin || 10)) {
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
  // "You earned 120 points · Balance 1,340" for the bill (POS sales with a customer)
  function billLoyaltyLine(payResult) {
    const L = payResult && payResult.loyalty;
    if (!L || !L.customer) return '';
    const e = L.earned || {}, c = L.customer, bits = [];
    if (e.points) bits.push('You earned ' + e.points + ' points');
    if (e.stamp) bits.push('Stamp added: ' + c.loyalty.stamps + ' of ' + c.loyalty.stamp_goal);
    if (e.cashback) bits.push('Cashback ' + (window.globalCurrencySymbol || '') + Number(e.cashback).toFixed(2));
    if (e.reward) bits.push('Reward: ' + e.reward.label + ' (code ' + e.reward.code + ')');
    if (c.loyalty.enabled && c.loyalty.type === 'points') bits.push('Balance ' + c.loyalty.points + ' points');
    return bits.join(' · ');
  }

  async function buildBillPreviewHtml(cartSnapshot, subtotal, tax, showGst, total, payResult, tableLabel, kotNumber) {
    let restaurantInfo = { name: 'Restaurant Name', logo: '', address: '', phone: '', user_id: '', showBusinessId: false, businessIdLabel: '', businessIdNo: '' };
    try {
      const infoRes = await rgFetchRetry('../admin/get_session.php');
      const infoData = await infoRes.json();
      if (infoData.success && infoData.data) {
        restaurantInfo.name = infoData.data.restaurant_name || restaurantInfo.name;
        restaurantInfo.logo = infoData.data.restaurant_logo || '';
        restaurantInfo.user_id = infoData.data.user_id || infoData.data.id || '';
        restaurantInfo.address = infoData.data.address || '';
        restaurantInfo.phone = infoData.data.phone || '';
        restaurantInfo.showBusinessId = infoData.data.show_business_id == 1 || infoData.data.show_business_id === '1';
        restaurantInfo.businessIdLabel = infoData.data.business_id_label || '';
        restaurantInfo.businessIdNo = infoData.data.business_id_no || '';
        restaurantInfo.business_qr_code_path = infoData.data.business_qr_code_path || '';
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
        <td style="padding:6px 0;border-bottom:1px dashed #d1d5db;font-size:13px;word-break:break-word;overflow-wrap:anywhere;">${escapeHtml(it.name)}${it.variationName ? ` <span style="color:#6b7280;">(${escapeHtml(it.variationName)})</span>` : ''}${(it.addons || []).length ? `<div style="font-size:11px;color:#6b7280;">+ ${it.addons.map(a => escapeHtml(a.name) + ' ' + currency + parseFloat(a.price).toFixed(2)).join(', ')}</div>` : ''}</td>
        <td style="padding:6px 0;border-bottom:1px dashed #d1d5db;font-size:13px;text-align:center;width:40px;">${it.quantity}</td>
        <td style="padding:6px 0;border-bottom:1px dashed #d1d5db;font-size:13px;text-align:right;width:70px;">${currency}${window.posLineTotal(it).toFixed(2)}</td>
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

    // Nepal VAT bill: "Tax Invoice", the Bikram Sambat date, and the taxable amount / service charge lines
    const isNepal = window.restaurantCountryCode === 'NP';
    const discTotal = (payResult.discounts || []).reduce((s, d) => s + (+d[1] || 0), 0);
    const bm = window.rgBillMath ? window.rgBillMath(Math.max(0, subtotal - discTotal)) : { net: subtotal, serviceCharge: 0, inclusive: false };
    const billExtraRows = [];
    if (bm.inclusive && (showGst || bm.serviceCharge > 0)) billExtraRows.push(['Taxable amount', bm.net]);
    if (bm.serviceCharge > 0) billExtraRows.push(['Service charge (' + (parseFloat(window.serviceChargePercent) || 0) + '%)', bm.serviceCharge]);
    const billHtml = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Bill #${kotNumber}</title>
      <style>
        ${window.getThermalReceiptCSS()}
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
        ${restaurantInfo.showBusinessId && restaurantInfo.businessIdNo ? `<div class="restaurant-details">${escapeHtml(restaurantInfo.businessIdLabel || 'PAN')}: ${escapeHtml(restaurantInfo.businessIdNo)}</div>` : ''}
        ${isNepal ? '<div class="bill-tag">TAX INVOICE</div>' : ''}
        <div class="bill-tag">BILL #${escapeHtml(String(kotNumber))}</div>
        <div style="font-size:11px;color:#6b7280;margin-top:4px;">${payResult.billDateBs ? escapeHtml(payResult.billDateBs) + ' BS | ' : ''}${dateStr} | ${timeStr}</div>
      </div>

      <div class="info-box">
        <div class="info-grid">
          <div class="info-item" style="grid-column:1/-1;"><span class="info-label">TABLE</span><span class="info-value">${escapeHtml(tableLabel)}</span></div>
          <div class="info-item" style="grid-column:1/-1;"><span class="info-label">STATUS</span><span class="info-value">${escapeHtml(payResult.paymentStatus)}</span></div>
          ${payResult.customer ? `<div class="info-item" style="grid-column:1/-1;"><span class="info-label">CUSTOMER</span><span class="info-value">${escapeHtml(payResult.customer.name)}<br>${escapeHtml(payResult.customer.phone)}</span></div>` : ''}
        </div>
      </div>

      <table>
        <thead><tr><th style="text-align:left;">Item</th><th style="text-align:center; width:40px;">Qty</th><th style="text-align:right; width:70px;">Amt</th></tr></thead>
        <tbody>${itemsHtml}</tbody>
      </table>

      <div class="totals">
        <div><span>Subtotal</span><span>${currency}${subtotal.toFixed(2)}</span></div>
        ${(payResult.discounts || []).map(([l, a]) => `<div><span>${escapeHtml(l)}</span><span>-${currency}${a.toFixed(2)}</span></div>`).join('')}
        ${billExtraRows.map(([l, a]) => `<div><span>${escapeHtml(l)}</span><span>${currency}${a.toFixed(2)}</span></div>`).join('')}
        ${showGst ? `<div><span>${escapeHtml(window.taxName || 'Tax')} (${window.taxPercent ?? 5}%)</span><span>${currency}${tax.toFixed(2)}</span></div>` : ''}
        <div class="grand"><span>Total</span><span>${currency}${total.toFixed(2)}</span></div>
        ${bm.inclusive ? '<div style="font-size:10px;color:#6b7280;justify-content:center;">Prices include ' + escapeHtml(window.taxName || 'tax') + (bm.serviceCharge > 0 ? ' and service charge' : '') + '</div>' : ''}
        ${payResult.walletPaid > 0 ? `<div><span>Paid from wallet</span><span>-${currency}${payResult.walletPaid.toFixed(2)}</span></div>` : ''}
      </div>

      <div class="pay-box">
        ${payRowsHtml}
        ${payResult.returnAmount > 0 ? `<div style="display:flex;justify-content:space-between;font-size:12px;color:#059669;font-weight:700;"><span>Return</span><span>${currency}${payResult.returnAmount.toFixed(2)}</span></div>` : ''}
        ${payResult.dueAmount > 0 ? `<div style="display:flex;justify-content:space-between;font-size:12px;color:#b45309;font-weight:700;"><span>Due</span><span>${currency}${payResult.dueAmount.toFixed(2)}</span></div>` : ''}
      </div>

      ${billLoyaltyLine(payResult) ? `<div style="margin-top:10px;padding:8px;border:1px dashed #059669;text-align:center;font-size:12px;font-weight:700;color:#065f46;">${escapeHtml(billLoyaltyLine(payResult))}</div>` : ''}
      ${restaurantInfo.business_qr_code_path ? `<div style="margin-top:12px;padding-top:12px;border-top:1px dashed #d1d5db;text-align:center;"><div style="font-size:9px;color:#6b7280;margin-bottom:6px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">Scan to Pay</div><img src="../api/image.php?type=business_qr&id=${encodeURIComponent(restaurantInfo.user_id || '')}" alt="Payment QR" style="max-width:100px;max-height:100px;object-fit:contain;border:1px solid #e5e7eb;padding:4px;background:#fff;" onerror="this.style.display='none';"></div>` : ''}
      <div class="footer">
        <div class="footer-text">Thank you!</div>
        <div class="footer-text">Powered by Restro Grow</div>
      </div>
    </body></html>`;

    const billPrinterSettings = window.getPrinterSettings();
    if (window.isDirectPrintMode(billPrinterSettings.mode)) {
      // the phone app's bill layout (escpos.js buildBillEscPos)
      const billImages = await window.rgReceiptImages(restaurantInfo, billPrinterSettings.widthMM);
      const afterTotal = [];
      if (payResult.walletPaid > 0) afterTotal.push(['Paid from wallet', '-' + payResult.walletPaid.toFixed(2)]);
      if (payResult.dueAmount > 0) afterTotal.push(['Due', payResult.dueAmount.toFixed(2)]);
      if (payResult.returnAmount > 0) afterTotal.push(['Return', payResult.returnAmount.toFixed(2)]);
      const escBuilder = window.buildBillEscPos({
        charWidth: window.getPrinterCharWidth(billPrinterSettings.widthMM),
        restaurantName: restaurantInfo.name,
        address: restaurantInfo.address,
        phone: restaurantInfo.phone,
        businessIdLabel: restaurantInfo.businessIdLabel,
        businessIdNo: restaurantInfo.showBusinessId ? restaurantInfo.businessIdNo : '',
        billNo: kotNumber,
        createdAt: now,
        billTo: payResult.customer ? payResult.customer.name : tableLabel,
        items: cartSnapshot.map(it => ({
          name: it.name + (it.variationName ? ' (' + it.variationName + ')' : ''),
          sub: it.isCombo ? '' : ((it.addons || []).length ? '+ ' + it.addons.map(a => a.name).join(', ') : ''),
          quantity: it.quantity,
          rate: window.posLineTotal(it) / it.quantity,
          amount: window.posLineTotal(it)
        })),
        subtotal: subtotal,
        discounts: payResult.discounts || [],
        taxLabel: (window.taxName || 'Tax') + ' (' + (window.taxPercent ?? 5) + '%)',
        tax: showGst ? tax : 0,
        total: total,
        afterTotal: afterTotal,
        paymentMethod: payResult.paymentMethod,
        notes: [].concat(isNepal ? ['TAX INVOICE' + (payResult.billDateBs ? ' · ' + payResult.billDateBs + ' BS' : '')] : [],
          billExtraRows.map(([l, a]) => l + ': ' + a.toFixed(2)),
          billLoyaltyLine(payResult) ? [billLoyaltyLine(payResult)] : []),
        logoRaster: billImages.logoRaster,
        qrRaster: billImages.qrRaster,
        qrLabel: 'Scan to Pay'
      });
      // printed, or printDirect's onFail already showed the preview: either way nothing more to show
      await window.printDirect(escBuilder, () => showPrintPreview('Bill #' + kotNumber, billHtml));
      return null;
    }

    return billHtml;
  }

  // one payment at a time: a second tap on Pay while the first bill is saving would charge the same cart twice
  // (the lock lets go by itself after a minute, so a stuck save can never block billing for good)
  let posPaying = 0;
  async function processPayment() {
    if (posPaying && Date.now() - posPaying < 60000) return;
    const mine = posPaying = Date.now();
    try { return await processPaymentOnce(); } finally { if (posPaying === mine) posPaying = 0; }
  }

  async function processPaymentOnce() {
    // Support both posCart (admin) and window.posCart (waiter/chef/manager)
    const cart = typeof posCart !== 'undefined' ? posCart : (window.posCart || []);
    const updateCartFn = typeof updatePOSCart !== 'undefined' ? updatePOSCart : (window.updateWaiterPOSCart || window.updatePOSCart || (() => {}));

    if (cart.length === 0) {
      const showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
      showMsg("Cart is empty", "error");
      return;
    }

    // the shared money function: subtotal, then manual discount + reward code + points off before tax
    const T = window.posComputeTotals(cart);
    const subtotal = T.subtotal, showGst = T.showGst, tax = T.tax, total = T.total;
    const pcPrice = T.pcPrice;
    const pcf = window.rgPosCustomer ? window.rgPosCustomer.fields(subtotal, total) : null;
    const walletPart = pcf ? pcf.walletAmount : 0;
    const selectPosTable = document.getElementById("selectPosTable");
    const selectedTable = selectPosTable ? selectPosTable.value : '';
    const isTakeaway = !selectedTable;
    const tableLabel = (selectPosTable && selectedTable && selectPosTable.selectedIndex >= 0)
      ? selectPosTable.options[selectPosTable.selectedIndex].text
      : 'Takeaway';

    // The wallet pays first; the payment window takes the rest (nothing left: no window)
    const toCollect = Math.round((total - walletPart) * 100) / 100;
    const payResult = toCollect <= 0.004
      ? { paymentMethod: 'Wallet', paymentStatus: 'Paid', amounts: {}, dueAmount: 0, cash: 0, upi: 0, card: 0, returnAmount: 0 }
      : await showSplitPaymentModal(toCollect);
    if (!payResult) {
      return; // User cancelled - cart untouched
    }

    const currency = window.globalCurrencySymbol || '₹';
    const formData = new URLSearchParams();
    formData.append('action', 'create_kot');
    formData.append('tableId', selectedTable || '');
    formData.append('orderType', isTakeaway ? 'Takeaway' : 'Dine-in');
    formData.append('customerName', pcf ? pcf.customerName : (isTakeaway ? 'Takeaway' : 'Table Customer'));
    if (pcf) {
      formData.append('customerId', pcf.customerId);
      formData.append('customerPhone', pcf.customerPhone);
      if (pcf.couponCode) formData.append('couponCode', pcf.couponCode);
      if (pcf.redeemPoints) formData.append('redeemPoints', pcf.redeemPoints);
      if (walletPart > 0) formData.append('walletAmount', walletPart.toFixed(2));
    }
    // the cashier's manual discount, separate from the customer's reward code / points
    if (T.manualAmount > 0) {
      formData.append('manualDiscount', T.manualAmount.toFixed(2));
      formData.append('manualDiscountType', T.manualType);
      formData.append('manualDiscountValue', String(T.manualValue));
    }
    formData.append('paymentMethod', payResult.paymentMethod);
    formData.append('paymentStatus', payResult.paymentStatus || 'Paid');
    formData.append('paidAmount', Math.max(0, total - (parseFloat(payResult.dueAmount) || 0)).toFixed(2));
    // Built from whichever payment methods were actually used (built-in or
    // custom) instead of a hardcoded Cash/UPI/Card list, so a custom method
    // like "Wallet" shows up correctly in the KOT/bill notes too.
    const paidBreakdown = (walletPart > 0 ? [`Wallet: ${currency}${walletPart.toFixed(2)}`] : []).concat(Object.keys(payResult.amounts || {})
      .filter(name => payResult.amounts[name] > 0)
      .map(name => `${name}: ${currency}${payResult.amounts[name].toFixed(2)}`))
      .join(', ');
    formData.append('notes', `${paidBreakdown}${payResult.dueAmount > 0 ? `${paidBreakdown ? ', ' : ''}Due: ${currency}${payResult.dueAmount.toFixed(2)}` : ''}`);
    const payload = window.posCartPayload(cart);
    formData.append('cartItems', JSON.stringify(payload.items));
    formData.append('globalAddons', JSON.stringify(payload.globalAddons));
    if (payload.combos.length) formData.append('combos', JSON.stringify(payload.combos));
    if (window.posOpenBill) {   // already sent with KOT: settle that bill, and send the kitchen only what's new
      const delta = window.posKitchenDelta(cart);
      formData.append('openOrderId', window.posOpenBill.id);
      formData.append('kitchenItems', JSON.stringify(delta.items));
      if (delta.addons) formData.append('kitchenAddons', '1');
      if (delta.combos.length) formData.append('kitchenCombos', JSON.stringify(delta.combos));
    }
    formData.append('subtotal', subtotal.toFixed(2));
    formData.append('tax', tax.toFixed(2));
    formData.append('total', total.toFixed(2));

    try {
      // online: straight to the server; no internet: kept on this device and synced later (pos-offline.js)
      const result = await window.rgPosSend('pay', formData, { total, tableLabel, cart: cart.slice() });

      if (result.success) {
        // Snapshot the cart for the bill preview before clearing it
        const cartSnapshot = cart.slice();
        // what the bill shows about the customer, discounts and loyalty
        payResult.discounts = [];
        if (T.manualAmount > 0) payResult.discounts.push([T.manualLabel, T.manualAmount]);
        if (pcPrice && pcPrice.coupon > 0) payResult.discounts.push(['Reward ' + pcPrice.couponCode, pcPrice.coupon]);
        if (pcPrice && pcPrice.pointsDiscount > 0) payResult.discounts.push([pcPrice.points + ' points', pcPrice.pointsDiscount]);
        payResult.walletPaid = walletPart;
        payResult.customer = pcf ? { name: pcf.customerName, phone: pcf.customerPhone } : null;
        payResult.loyalty = result.loyalty || null;
        if (window.rgPosCustomer) window.rgPosCustomer.afterSale(result.loyalty);
        if (window.rgPosDiscount) window.rgPosDiscount.reset();   // the discount was for this bill only

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
        // bill_no counts up 1, 2, 3 per restaurant; Nepal prints it per fiscal year ("2083/84-0142") with the BS date
        const billNo = result.bill_label || result.bill_no || result.kot_number || result.order_number;
        payResult.billDateBs = result.bill_date_bs || '';
        if (result.offline) showMsg(`Saved offline as bill ${result.order_number}. It syncs when the internet is back.`, "info");
        else showMsg(`Payment saved (${payResult.paymentStatus}) - ${[result.kot_number ? 'KOT #' + result.kot_number : '', result.bot_number ? 'BOT #' + result.bot_number : ''].filter(Boolean).join(', ') || 'Bill ' + result.order_number}`, "success");
        window.posResetBill();

        const showBill = async () => {
          const billHtml = await buildBillPreviewHtml(cartSnapshot, subtotal, tax, showGst, total, payResult, tableLabel, billNo);
          if (billHtml === null) return; // already sent straight to the network printer
          showPrintPreview('Bill #' + billNo, billHtml);
        };

        // new tickets (dishes not sent before): the KOT and the bar's BOT first, then the bill
        if ((result.kot_id || result.bot_id) && window.printSentTickets) {
          window.printSentTickets(result, showBill);
        } else if (result.offline && result.localKot && result.localKot.length && window.rgPosOffline) {
          window.rgPosOffline.printLocalKot(result.localKot, result.order_number, tableLabel, showBill);
        } else {
          showBill();
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
      showMsg(error && error.message ? error.message : "Network error. Please check your connection and try again.", "error");
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
  // KOT button: send the dishes not sent yet to the kitchen, and keep the bill open (unpaid) until Pay.
  // The first KOT makes the order (marked [POS open bill] on the server); later KOTs add to it; Pay
  // settles it (controllers/pos_operations.php, openOrderId / kitchenItems).
  //   window.posOpenBill = { id, number }   the open bill for this cart (null when there isn't one)
  //   posSent[cartKey] = how many of that line the kitchen already has
  window.posOpenBill = null;
  let posSent = {};
  // what the kitchen doesn't have yet: dishes (by line) and whether order add-ons changed
  window.posKitchenDelta = function (cart) {
    const items = [];
    const combos = [];
    let addons = false;
    cart.forEach(it => {
      const key = it.cartKey || String(it.id);
      const d = it.quantity - (posSent[key] || 0);
      if (d <= 0) return;
      if (it.isAddon) { addons = true; return; }
      if (it.isCombo) { combos.push({ id: it.comboId, quantity: d, picks: it.picks || {} }); return; }
      items.push({ id: it.id, name: it.name, quantity: d, addons: (it.addons || []).map(a => ({ id: a.id })) });
    });
    return { items, addons, combos };
  };
  window.posMarkAllSent = function (cart) { posSent = {}; cart.forEach(it => { posSent[it.cartKey || String(it.id)] = it.quantity; }); };
  window.posResetBill = function () { window.posOpenBill = null; posSent = {}; window._kotSentForCurrentOrder = false; };
  window.posSentQty = function (key) { return posSent[key] || 0; };

  async function sendToKitchen() {
    const cart = typeof posCart !== 'undefined' ? posCart : (window.posCart || []);
    const updateCartFn = typeof updatePOSCart !== 'undefined' ? updatePOSCart : (window.updateWaiterPOSCart || window.updatePOSCart || (() => {}));
    const showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
    if (cart.length === 0) { showMsg("Cart is empty", "error"); return; }

    const delta = window.posKitchenDelta(cart);
    if (!delta.items.length && !delta.addons && !delta.combos.length) { showMsg("Everything on this bill is already in the kitchen.", "info"); return; }

    const subtotal = cart.reduce((sum, item) => sum + window.posLineTotal(item), 0);
    const { tax, total } = window.rgBillMath(subtotal);
    const selectPosTable = document.getElementById("selectPosTable");
    const selectedTable = selectPosTable ? selectPosTable.value : '';
    const pcust = window.rgPosCustomer ? window.rgPosCustomer.get() : null;

    const formData = new URLSearchParams();
    formData.append('action', 'create_kot');
    formData.append('tableId', selectedTable || '');
    formData.append('orderType', selectedTable ? 'Dine-in' : 'Takeaway');
    formData.append('customerName', pcust ? pcust.name : (selectedTable ? 'Table Customer' : 'Takeaway'));
    if (pcust) { formData.append('customerId', pcust.id); formData.append('customerPhone', pcust.phone); }
    formData.append('paymentMethod', 'Due');
    const payload = window.posCartPayload(cart);
    formData.append('cartItems', JSON.stringify(payload.items));
    formData.append('globalAddons', JSON.stringify(payload.globalAddons));
    formData.append('kitchenItems', JSON.stringify(delta.items));
    if (delta.addons) formData.append('kitchenAddons', '1');
    if (payload.combos.length) formData.append('combos', JSON.stringify(payload.combos));
    if (delta.combos.length) formData.append('kitchenCombos', JSON.stringify(delta.combos));
    if (window.posOpenBill) formData.append('openOrderId', window.posOpenBill.id);
    formData.append('subtotal', subtotal.toFixed(2));
    formData.append('tax', tax.toFixed(2));
    formData.append('total', total.toFixed(2));

    const btns = [document.getElementById('sendKotBtn'), document.getElementById('mobileSendKotBtn')].filter(Boolean);
    btns.forEach(b => b.disabled = true);
    try {
      const tableLabel = selectedTable && selectPosTable.selectedIndex >= 0 ? selectPosTable.options[selectPosTable.selectedIndex].text : 'Takeaway';
      const result = await window.rgPosSend('kot', formData, { total, tableLabel, cart: cart.slice() });
      if (!result.success) { showMsg(result.message || "Could not send to the kitchen.", "error"); if (/already paid or cancelled/.test(result.message || '')) { window.posResetBill(); updateCartFn(); } return; }
      window.posOpenBill = { id: result.order_id, number: result.order_number };
      window.posMarkAllSent(cart);
      window._kotSentForCurrentOrder = true;
      updateCartFn();
      if (result.offline) {
        showMsg(`Offline: kitchen ticket printed here. Bill ${result.order_number} stays open and syncs when the internet is back.`, "info");
        if (window.rgPosOffline) window.rgPosOffline.printLocalKot(result.localKot, result.order_number, tableLabel);
      } else showMsg(`Sent${result.kot_number ? ` to the kitchen (KOT #${result.kot_number})` : ''}${result.kot_number && result.bot_number ? ' and' : ''}${result.bot_number ? ` to the bar (BOT #${result.bot_number})` : ''}. Bill ${result.order_number} stays open until it's paid.`, "success");
      if ((result.kot_id || result.bot_id) && window.printSentTickets) window.printSentTickets(result);
      const ordersPage = document.getElementById('ordersPage');
      if (ordersPage && ordersPage.classList.contains('active') && typeof loadOrders !== 'undefined') loadOrders();
    } catch (e) {
      console.error('KOT error:', e);
      showMsg("Network error. Please check your connection and try again.", "error");
    } finally {
      btns.forEach(b => b.disabled = false);
    }
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
  // offline POS bills not synced yet stay on this device (pos-offline.js) and sync at the next login here
  const unsynced = window.rgPosOffline ? window.rgPosOffline.queue().length : 0;
  if (await showSweetConfirm(unsynced ? `${unsynced} offline bill${unsynced === 1 ? ' has' : 's have'} not synced yet. They stay on this device and sync the next time you log in here. Log out anyway?` : "Are you sure you want to logout?", 'Logout')) {
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
        // the dashboard kept for opening offline (website/sw.js) goes with the login
        try { if (window.caches) await caches.delete('rg-shell'); } catch (e) {}
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
        window.enableKmDelivery = data.enable_km_delivery || 0;
        window.deliveryRatePerKm = data.delivery_rate_per_km || 0;
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
      // notification.wav runs several seconds long (not a short beep), and
      // several independent pollers (badge count, new-order popup, KOT) can
      // each call this for what's effectively the same event. Reusing one
      // Audio instance and restarting it from 0 - rather than creating a new
      // one every call - stops those triggers from stacking into overlapping,
      // seemingly-endless playback.
      if (!window._notificationAudio) {
        window._notificationAudio = new Audio('../../assets/sounds/notification.wav');
        window._notificationAudio.volume = 0.5;
      }
      var audio = window._notificationAudio;
      audio.pause();
      audio.currentTime = 0;
      audio.play().catch(() => {});
    } catch(e) {}
  }

  function stopNotificationSound() {
    try {
      if (window._notificationAudio) {
        window._notificationAudio.pause();
        window._notificationAudio.currentTime = 0;
      }
    } catch(e) {}
  }
  window.stopNotificationSound = stopNotificationSound;

  // Rings like an incoming call for a brand-new order — loops continuously
  // instead of the single short beep above, since a new order is easy to
  // miss with one chime if the admin's away from the screen. Capped at 10
  // minutes so it can't ring forever if the tab is left unattended; stops
  // immediately once the owner opens/accepts/rejects it via closeNewOrderOverlay().
  const NEW_ORDER_RING_MAX_MS = 10 * 60 * 1000;

  function startNewOrderRing() {
    try {
      if (!window._newOrderRingAudio) {
        window._newOrderRingAudio = new Audio('../../assets/sounds/telephone-ring.mp3');
        window._newOrderRingAudio.loop = true;
        window._newOrderRingAudio.volume = 0.7;
      }
      const audio = window._newOrderRingAudio;
      audio.currentTime = 0;
      // blocked until the first click after a page load (browser rule): order-alerts.js starts it on that click
      audio.play().then(() => { window._ringBlocked = false; }).catch(() => { window._ringBlocked = true; });

      if (window._newOrderRingTimeout) clearTimeout(window._newOrderRingTimeout);
      window._newOrderRingTimeout = setTimeout(stopNewOrderRing, NEW_ORDER_RING_MAX_MS);
    } catch(e) {}
  }

  function stopNewOrderRing() {
    try {
      if (window._newOrderRingAudio) {
        window._newOrderRingAudio.pause();
        window._newOrderRingAudio.currentTime = 0;
      }
      if (window._newOrderRingTimeout) {
        clearTimeout(window._newOrderRingTimeout);
        window._newOrderRingTimeout = null;
      }
    } catch(e) {}
  }
  window.stopNewOrderRing = stopNewOrderRing;
  window.startNewOrderRing = startNewOrderRing;

  // Browsers refuse to autoplay audio until the page has seen a real user
  // gesture. playClickSound() always fires from an actual click so it's
  // fine, but the ring and notification chime fire from background polling
  // (no click in the call stack) — on a dashboard left open and untouched
  // while waiting for orders, that first play() would silently get blocked
  // and swallowed by the .catch(()=>{}) below, so the ring would never be
  // heard. Priming both Audio elements on the very first click/keydown/tap
  // anywhere unlocks them for the rest of the tab's life.
  function primeAlertAudio() {
    try {
      if (!window._newOrderRingAudio) {
        window._newOrderRingAudio = new Audio('../../assets/sounds/telephone-ring.mp3');
        window._newOrderRingAudio.loop = true;
        window._newOrderRingAudio.volume = 0.7;
      }
      if (!window._notificationAudio) {
        window._notificationAudio = new Audio('../../assets/sounds/notification.wav');
        window._notificationAudio.volume = 0.5;
      }
      [window._newOrderRingAudio, window._notificationAudio].forEach(function(a) {
        var p = a.play();
        if (p && typeof p.catch === 'function') p.catch(function(){});
        a.pause();
        a.currentTime = 0;
      });
    } catch(e) {}
  }
  ['click', 'keydown', 'touchstart'].forEach(function(evt) {
    document.addEventListener(evt, primeAlertAudio, { once: true, capture: true });
  });

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

  // Shared by the Online Orders list "Accept" button and the new-order popup's
  // Accept button. When KM-based delivery pricing is on, the actual delivery
  // charge depends on each customer's distance, so the admin confirms/edits
  // the auto-calculated amount here before the order is accepted. For every
  // other case this just accepts immediately, unchanged from before.
  // Returns a Promise resolving to the API response, or {cancelled: true} if
  // the admin dismissed the prompt without confirming.
  function acceptOrderWithDeliveryCharge(orderId, orderType, addressLat, addressLng, prepMinutes, deliveryMinutes) {
    const isKmDelivery = orderType === 'Delivery' && (window.enableKmDelivery == 1 || window.enableKmDelivery === true);
    const prepPart = (prepMinutes > 0 ? '&prep_minutes=' + encodeURIComponent(prepMinutes) : '')   // optional "ready in" from the new-order popup
      + (deliveryMinutes > 0 ? '&delivery_minutes=' + encodeURIComponent(deliveryMinutes) : '');
    if (!isKmDelivery) {
      return fetch('../api/update_order_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'orderId=' + orderId + '&status=Accepted' + prepPart
      }).then(r => r.json());
    }

    const rLat = window.restaurantLat ? parseFloat(window.restaurantLat) : null;
    const rLng = window.restaurantLng ? parseFloat(window.restaurantLng) : null;
    const rate = parseFloat(window.deliveryRatePerKm) || 0;
    let suggested = '';
    let noteHtml;
    if (rLat && rLng && addressLat && addressLng) {
      const dist = haversineKmOrders(rLat, rLng, parseFloat(addressLat), parseFloat(addressLng));
      suggested = (dist * rate).toFixed(2);
      noteHtml = '<p style="margin:4px 0 12px;font-size:0.85rem;color:#6b7280;">≈ ' + dist.toFixed(1) + ' km × ' + formatCurrency(rate) + '/km</p>';
    } else {
      noteHtml = '<p style="margin:4px 0 12px;font-size:0.85rem;color:#ef4444;">Could not auto-calculate distance — please enter the delivery charge manually.</p>';
    }

    return Swal.fire({
      icon: 'question',
      title: 'Confirm Delivery Charge',
      html: noteHtml,
      input: 'number',
      inputValue: suggested,
      inputAttributes: { min: 0, step: '0.01' },
      showCancelButton: true,
      confirmButtonText: 'Accept Order',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#10b981',
      preConfirm: (value) => {
        if (value === '' || value === null || isNaN(value) || parseFloat(value) < 0) {
          Swal.showValidationMessage('Please enter a valid delivery charge');
        }
        return value;
      }
    }).then((result) => {
      if (!result.isConfirmed) return { success: false, cancelled: true };
      return fetch('../api/update_order_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'orderId=' + orderId + '&status=Accepted&delivery_charge=' + encodeURIComponent(result.value) + prepPart
      }).then(r => r.json());
    });
  }

  // Wired to the Pending card's "Accept" button in place of the generic
  // updateOrderStatus() — this one may show the delivery-charge prompt first.
  function handleAcceptOrderClick(orderId, orderType, btn, addressLat, addressLng) {
    const spinner = btn && btn.querySelector('.loading-spinner');
    if (spinner) spinner.style.display = '';
    if (btn) btn.disabled = true;
    acceptOrderWithDeliveryCharge(orderId, orderType, addressLat, addressLng).then((data) => {
      if (data && data.cancelled) return;
      if (data && data.success) {
        if (document.getElementById('ordersList')) loadOrders();
        if (document.getElementById('onlineOrdersList') && typeof loadOnlineOrders === 'function') loadOnlineOrders();
      } else {
        showSweetAlert((data && data.message) || 'Failed to accept order');
      }
    }).catch(() => {
      showSweetAlert('Error accepting order');
    }).finally(() => {
      if (spinner) spinner.style.display = 'none';
      if (btn) btn.disabled = false;
    });
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
          <span style="font-size:0.85rem;color:#0369a1;display:inline-flex;align-items:center;gap:4px;"><span class="material-symbols-rounded" style="font-size:16px;">route</span>${distanceLabel}</span>
          <a href="${directionsUrl}" target="_blank" rel="noopener" style="background:#0ea5e9;color:white;border:none;padding:4px 10px;font-size:0.8rem;text-decoration:none;border-radius:6px;white-space:nowrap;">Open Route in Google Maps</a>
        </div>
        ${embedHtml}
      </div>
    `;
  }

  // Builds the payment-screenshot review block for a "QR Payment" order -
  // shared by the new-order popup, the inline online-orders list, and the
  // full order details modal so all three stay in sync.
  function buildPaymentProofHtml(order, maxImgSize) {
    if (order.payment_method !== 'QR Payment') return '';
    maxImgSize = maxImgSize || 200;
    const statusColors = {
      Confirmed: { bg: '#f0fdf4', border: '#86efac', badgeBg: '#dcfce7', badgeText: '#166534' },
      Rejected: { bg: '#fef2f2', border: '#fca5a5', badgeBg: '#fee2e2', badgeText: '#991b1b' },
      Pending: { bg: '#fffbeb', border: '#fde68a', badgeBg: '#fef3c7', badgeText: '#92400e' }
    };
    const c = statusColors[order.payment_proof_status] || statusColors.Pending;
    const body = order.payment_proof_status
      ? `
        <div style="margin-top:8px;"><img src="../api/image.php?type=payment_proof&id=${order.id}" alt="Payment screenshot" style="max-width:${maxImgSize}px;max-height:${maxImgSize}px;border-radius:8px;border:1px solid #ddd;cursor:zoom-in;" onclick="window.open(this.src, '_blank')"></div>
        <div style="margin-top:6px;"><span style="display:inline-block;padding:2px 8px;border-radius:10px;font-weight:600;font-size:0.75rem;background:${c.badgeBg};color:${c.badgeText};">${order.payment_proof_status}</span></div>
        ${order.payment_proof_status === 'Pending' ? `
          <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
            <button onclick="reviewPaymentProof(${order.id}, 'confirm', this)" style="background:#16a34a;color:#fff;border:none;padding:6px 10px;border-radius:6px;cursor:pointer;font-size:0.8rem;">Confirm Payment</button>
            <button onclick="reviewPaymentProof(${order.id}, 'reject', this)" style="background:#dc2626;color:#fff;border:none;padding:6px 10px;border-radius:6px;cursor:pointer;font-size:0.8rem;">Reject</button>
          </div>
        ` : ''}
      `
      : `<p style="margin:6px 0 0 0;font-size:0.8rem;color:#92400e;">Customer hasn't uploaded a payment screenshot yet.</p>`;
    return `
      <div style="margin-top:10px;padding:10px;background:${c.bg};border:1px solid ${c.border};border-radius:8px;">
        <strong style="font-size:0.85rem;color:#78350f;">Payment Proof (Pay Online / QR)</strong>
        ${body}
      </div>
    `;
  }

  function displayOnlineOrders(orders) {
    if (window.rgOrderTabs) orders = window.rgOrderTabs('online', orders, displayOnlineOrders);   // Ongoing / Completed tabs (order-tabs.js)
    const container = document.getElementById('onlineOrdersList');

    if (orders.length === 0) {
      container.innerHTML = '<div class="empty-state">No online orders found</div>';
      return;
    }

    // every card has the same layout (assets/js/order-card.js)
    container.innerHTML = orders.map(order => window.rgOrderCard(order, 'online')).join('');
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
        window.rgKotHasBar = !!data.has_bar;   // Kitchen / Bar tabs (kot-studio.js)
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
  if (window.rgKotRender) return window.rgKotRender(kots);   // ticket cards (assets/js/kot-studio.js)
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
          <p style="margin: 5px 0; color: #6b7280; font-size: 0.85rem;">Table: ${kot.table_number || 'Takeaway'}${kot.area_name ? ' · ' + escapeHtml(kot.area_name) : ''}</p>
          ${kot.customer_name && kot.customer_name !== 'Table Customer' && kot.customer_name !== 'Takeaway' ? `
            <div style="margin-top: 8px; padding: 8px; background: #f3f4f6; border-radius: 6px;">
              <p style="margin: 2px 0; color: #1f2937; font-size: 0.85rem; font-weight: 600;">Customer: ${escapeHtml(kot.customer_name)}</p>
              ${kot.customer_phone ? `<p style="margin: 2px 0; color: #6b7280; font-size: 0.8rem;"><span class="material-symbols-rounded" style="font-size:14px;vertical-align:-3px;margin-right:3px">call</span>${escapeHtml(kot.customer_phone)}</p>` : ''}
              ${kot.customer_email ? `<p style="margin: 2px 0; color: #6b7280; font-size: 0.8rem;"><span class="material-symbols-rounded" style="font-size:14px;vertical-align:-3px;margin-right:3px">mail</span>${escapeHtml(kot.customer_email)}</p>` : ''}
              ${kot.customer_address ? `<p style="margin: 2px 0; color: #6b7280; font-size: 0.8rem;"><span class="material-symbols-rounded" style="font-size:14px;vertical-align:-3px;margin-right:3px">location_on</span>${escapeHtml(kot.customer_address)}</p>` : ''}
            </div>
          ` : ''}
        </div>
      </div>

      <div class="kot-items" style="margin: 16px 0;">
        <h4 style="margin: 15px 0 10px 0; color: #1f2937; font-size: 0.9rem; text-transform: uppercase;">ITEMS</h4>
        ${(kot.items || []).map(item => `
          <div class="kot-item" style="display: flex; justify-content: space-between; padding: 12px; background: #f9fafb; border-radius: 8px; margin-bottom: 8px;">
            <div>
              <strong>${escapeHtml(item.item_name || item.name)}</strong>
              <div style="color: #6b7280; font-size: 0.875rem;">Qty: ${item.quantity}</div>
            </div>
            ${(item.notes || item.special_instructions) ? `<div style="color: #f59e0b; font-size: 0.875rem;">Note: ${escapeHtml(item.notes || item.special_instructions)}</div>` : ''}
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
// a string argument inside an inline onclick="fn(...)": quotes and & stay intact through the HTML attribute
function jsArg(v) {
  return JSON.stringify(v == null ? '' : String(v)).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
}

function escapeHtml(str) {
  if (str === undefined || str === null) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// --- Printer Settings ---
// Printer choice (paper width / connection mode) is tied to the physical
// printer wired to this PC/browser, not to the restaurant account, so it's
// kept in localStorage rather than the database.
window.getPrinterSettings = function () {
  var defaults = { widthMM: 80, mode: 'browser', networkIp: '', networkPort: 9100, serialBaud: 9600, barPrint: 'same', barNetworkIp: '', barNetworkPort: 9100 };
  try {
    var saved = JSON.parse(localStorage.getItem('printerSettings') || '{}');
    var merged = Object.assign({}, defaults, saved);
    merged.widthMM = (parseInt(merged.widthMM, 10) === 58) ? 58 : 80;
    merged.mode = window.normalizePrinterMode(merged.mode);
    merged.networkPort = parseInt(merged.networkPort, 10) || 9100;
    merged.serialBaud = parseInt(merged.serialBaud, 10) || 9600;
    merged.barPrint = ['same', 'network', 'none'].indexOf(merged.barPrint) !== -1 ? merged.barPrint : 'same';
    merged.barNetworkPort = parseInt(merged.barNetworkPort, 10) || 9100;
    return merged;
  } catch (e) {
    return defaults;
  }
};

// merged into what's saved: a form that saves only its own fields keeps the others (the bar printer)
window.savePrinterSettings = function (settings) {
  var saved = {};
  try { saved = JSON.parse(localStorage.getItem('printerSettings') || '{}') || {}; } catch (e) {}
  localStorage.setItem('printerSettings', JSON.stringify(Object.assign(saved, settings)));
};

// Where a Bar Order Ticket (BOT) prints, set on Settings › Printer › Bar tickets:
// 'same' = the printer above, 'network' = the bar's own network printer, 'none' = not printed (bar screen only)
window.getBarPrintRoute = function () {
  var s = window.getPrinterSettings();
  if (s.barPrint === 'network' && s.barNetworkIp) return 'network';
  return s.barPrint === 'none' ? 'none' : 'same';
};

// browser = print dialog; network / bluetooth / serial (USB) print straight away
window.normalizePrinterMode = function (mode) {
  return ['network', 'bluetooth', 'serial'].indexOf(mode) !== -1 ? mode : 'browser';
};
window.printerModeLabel = function (mode) {
  return { network: 'Network Printer', bluetooth: 'Bluetooth Printer', serial: 'USB Printer' }[mode] || 'Browser / System';
};

// Character width of a monospace thermal font per paper size (Font A, the
// default on essentially every 58mm/80mm ESC/POS printer).
window.getPrinterCharWidth = function (widthMM) {
  return widthMM === 58 ? 32 : 48;
};

// Returns the <style> rules that size a receipt for the printer configured
// in Printer Settings. Shared by every print template so the paper width
// only has to be handled correctly in one place. Pass widthMMOverride to
// size for a specific width regardless of the saved setting (used by Test
// Print, which previews whatever is currently selected, saved or not).
window.getThermalReceiptCSS = function (widthMMOverride) {
  var widthMM = (widthMMOverride === 58 || widthMMOverride === 80)
    ? widthMMOverride
    : window.getPrinterSettings().widthMM;
  var contentMM = widthMM - 4; // printable area after a small safety margin
  var baseFontPx = widthMM === 58 ? 11 : 12;
  return (
    '@media print { @page { size: ' + widthMM + 'mm auto; margin: 0; } body { margin:0; padding:0; } }' +
    '* { margin:0; padding:0; box-sizing:border-box; }' +
    'html, body { background:#fff; }' +
    'body { font-family: "Courier New", Courier, monospace; width:' + contentMM + 'mm; max-width:' + contentMM + 'mm; margin:0; padding:2mm; color:#111827; font-size:' + baseFontPx + 'px; }' +
    // Thermal heads reproduce solid black reliably but wash out light
    // greys/tints, so force pure black text and flat white fills when the
    // job actually goes to a printer (on-screen preview keeps its colors).
    '@media print { *, *::before, *::after { color:#000 !important; background:#fff !important; border-color:#000 !important; text-shadow:none !important; box-shadow:none !important; } }'
  );
};

// Loads the restaurant logo and payment QR as ESC/POS raster commands so the
// direct print modes (network / Bluetooth / USB) can print them - thermal text
// mode has no way to draw either. Returns { logoRaster, qrRaster }; a value is
// null when that image is absent or fails to load, and the builder simply skips
// it. Pass { qr: false } for documents that don't show a QR.
window.rgReceiptImages = async function (restaurantInfo, widthMM, opts) {
  opts = opts || {};
  var dotWidth = widthMM === 58 ? 384 : 576;
  var out = { logoRaster: null, qrRaster: null };
  try {
    var lp = restaurantInfo && restaurantInfo.logo;
    if (lp) {
      var logoUrl;
      if (lp.indexOf('db:') === 0) logoUrl = '../api/image.php?type=logo&id=' + (restaurantInfo.user_id || restaurantInfo.id || '');
      else if (lp.indexOf('http') === 0) logoUrl = lp;
      else if (lp.indexOf('uploads/') === 0) logoUrl = '../' + lp;
      else logoUrl = '../uploads/' + lp;
      out.logoRaster = await window.rgImageUrlToRaster(logoUrl, Math.round(dotWidth * 0.42), 200);
    }
  } catch (e) { /* logo is optional */ }
  try {
    if (opts.qr !== false && restaurantInfo && restaurantInfo.business_qr_code_path) {
      var uid = restaurantInfo.user_id || restaurantInfo.id || '';
      out.qrRaster = await window.rgImageUrlToRaster('../api/image.php?type=business_qr&id=' + uid, 192, 190);
    }
  } catch (e) { /* QR is optional */ }
  return out;
};

// Sends raw ESC/POS bytes to a LAN thermal printer via the server-side
// relay (browsers can't open raw TCP sockets themselves). Returns true on
// success so callers can fall back to the browser print preview on failure.
/**
 * Fetch for printing: the live host sometimes answers a busy moment with an empty 503 / 502 / 429,
 * which used to end in "Unexpected end of JSON input" and no print. Retries up to 3 times, and keeps
 * the restaurant details (get_session.php) for 3 minutes so a print needs one request, not two.
 * Returns a normal Response (so .json() / .ok / .status work as before).
 */
window.rgFetchRetry = async function (url, opts) {
  const isSession = String(url).indexOf("get_session.php") !== -1;
  const c = window._rgSessionCache;
  if (isSession && c && Date.now() - c.at < 180000) return new Response(c.text, { status: 200, headers: { "Content-Type": "application/json" } });
  let last = null;
  for (let i = 0; i < 3; i++) {
    try {
      const res = await fetch(url, Object.assign({ cache: "no-store" }, opts || {}));
      const text = await res.text();
      const busy = res.status === 429 || res.status >= 500 || !text.trim();
      if (!busy) {
        if (isSession && res.ok) { try { if (JSON.parse(text).success) window._rgSessionCache = { at: Date.now(), text: text }; } catch (e) {} }
        return new Response(text, { status: res.status, headers: { "Content-Type": "application/json" } });
      }
      last = new Error("Server busy (" + res.status + ")");
    } catch (e) { last = e; }
    await new Promise(r => setTimeout(r, 700 * (i + 1)));
  }
  // nothing came back: an old copy of the restaurant details is still better than a failed print
  if (isSession && c) return new Response(c.text, { status: 200, headers: { "Content-Type": "application/json" } });
  throw last || new Error("Request failed");
};

// target: another network printer than the one in settings ({ ip, port, name }), e.g. the bar's
window.printViaNetwork = async function (escPosBuilder, target) {
  var settings = target ? { networkIp: target.ip, networkPort: parseInt(target.port, 10) || 9100 } : window.getPrinterSettings();
  if (!settings.networkIp) {
    showSweetAlert('No network printer address configured. Add one in Settings > Printer Settings.');
    return false;
  }
  try {
    const res = await fetch('../api/print_network.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        ip: settings.networkIp,
        port: settings.networkPort,
        data: window.rgEscPosBase64(escPosBuilder)
      })
    });
    const data = await res.json();
    if (!data.success) {
      showSweetAlert(data.message || 'Failed to reach network printer. Showing preview instead.');
      return false;
    }
    return true;
  } catch (e) {
    console.error('Network print error', e);
    showSweetAlert('Failed to reach network printer. Showing preview instead.');
    return false;
  }
};

// Printer Settings form (Settings page) - populate from localStorage and
// wire up the mode toggle. Guarded so it no-ops on pages without this form
// (e.g. the waiter dashboard, which shares this script file).
document.addEventListener('DOMContentLoaded', function () {
  var widthSel = document.getElementById('printerWidthSelect');
  var modeSel = document.getElementById('printerModeSelect');
  var ipInput = document.getElementById('printerNetworkIp');
  var portInput = document.getElementById('printerNetworkPort');
  var networkFields = document.getElementById('printerNetworkFields');
  var deviceFields = document.getElementById('printerDeviceFields');
  var baudSel = document.getElementById('printerSerialBaud');
  if (!widthSel || !modeSel || !ipInput || !portInput || !networkFields) return;

  var settings = window.getPrinterSettings();
  widthSel.value = String(settings.widthMM);
  modeSel.value = settings.mode;
  ipInput.value = settings.networkIp || '';
  portInput.value = settings.networkPort || 9100;
  if (baudSel) baudSel.value = String(settings.serialBaud);

  var showFields = function () {
    var m = modeSel.value;
    networkFields.style.display = m === 'network' ? 'block' : 'none';
    if (deviceFields) deviceFields.style.display = (m === 'bluetooth' || m === 'serial') ? 'block' : 'none';
    window.paintPrinterDeviceCard();
  };
  showFields();
  modeSel.addEventListener('change', showFields);

  // Bar tickets (BOT): the same printer, the bar's own network printer, or not printed
  var barSel = document.getElementById('barPrintSelect');
  if (barSel) {
    window.fillBarPrinterFields(settings);
    barSel.addEventListener('change', window.showBarPrinterFields);
  }
  if (window.rgPrinter) window.rgPrinter.onChange(window.paintPrinterDeviceCard);
});

window.fillBarPrinterFields = function (s) {
  var barSel = document.getElementById('barPrintSelect');
  if (!barSel) return;
  barSel.value = s.barPrint;
  document.getElementById('barNetworkIp').value = s.barNetworkIp || '';
  document.getElementById('barNetworkPort').value = s.barNetworkPort || 9100;
  window.showBarPrinterFields();
};
window.showBarPrinterFields = function () {
  var barSel = document.getElementById('barPrintSelect'), box = document.getElementById('barNetworkFields');
  if (barSel && box) box.style.display = barSel.value === 'network' ? 'block' : 'none';
};

// The "Connect printer" card for Bluetooth / USB on the Printer settings pane.
window.paintPrinterDeviceCard = function () {
  var modeSel = document.getElementById('printerModeSelect');
  var card = document.getElementById('printerDeviceFields');
  if (!modeSel || !card || !window.rgPrinter) return;
  var mode = modeSel.value, bt = mode === 'bluetooth', p = window.rgPrinter, info = p.info();
  var mine = info.savedKind === mode, on = mine && p.isConnected(mode);
  var set = function (id, fn) { var el = document.getElementById(id); if (el) fn(el); };
  set('printerDevIcon', function (el) { el.textContent = bt ? 'bluetooth' : 'usb'; el.parentNode.classList.toggle('on', on); });
  set('printerDevName', function (el) { el.textContent = mine && info.name ? info.name : 'No printer connected yet'; });
  set('printerDevState', function (el) {
    el.textContent = !p.supported[mode]
      ? (bt ? 'This browser can\'t reach Bluetooth printers. Open the dashboard in Chrome or Edge (Android, Windows, Mac). Not on iPhone / iPad.' : 'Needs Chrome or Edge on a computer.')
      : on ? 'Connected. Bills and KOTs print straight away.' + (p.route() && p.route().of > 1 ? ' (Way ' + p.route().at + ' of ' + p.route().of + ')' : '')
      : info.lastError ? 'Last try: ' + info.lastError
      : mine && info.state === 'connecting' ? 'Connecting…'
      : mine ? 'Saved, but not connected right now. Turn the printer on, then tap Reconnect.'
      : (bt ? 'Turn the printer on, tap Connect and pick it from the list.' : 'Plug the printer in (or pair it in Windows Bluetooth settings), tap Connect and pick its port.');
  });
  set('printerConnectBtn', function (el) {
    el.disabled = !p.supported[mode];
    el.innerHTML = '<span class="material-symbols-rounded">' + (on ? 'swap_horiz' : 'link') + '</span>' + (on ? 'Change printer' : mine ? 'Reconnect' : 'Connect printer');
  });
  set('printerForgetBtn', function (el) { el.style.display = mine ? '' : 'none'; });
  set('printerNextWayBtn', function (el) { el.style.display = on && p.route() && p.route().of > 1 ? '' : 'none'; });
  set('printerBaudField', function (el) { el.style.display = bt ? 'none' : ''; });
};

window.connectPrinterFromSettings = async function () {
  var modeSel = document.getElementById('printerModeSelect');
  if (!modeSel || !window.rgPrinter) return;
  var mode = modeSel.value, p = window.rgPrinter;
  // the printer is picked for the mode on screen, so keep that mode
  window.savePrinterSettings(getLivePrinterSettings());
  // straight to the browser's list: it only opens right after the tap, so nothing may come first
  await p.pick(mode);
  window.paintPrinterDeviceCard();
};

window.copyPrinterLog = function (btn) {
  var text = window.rgPrinter ? window.rgPrinter.logText() : '';
  if (!text) return;
  var done = function () { var i = btn.querySelector('.material-symbols-rounded'); if (i) { i.textContent = 'check'; setTimeout(function () { i.textContent = 'content_copy'; }, 1400); } };
  if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done);
};

window.forgetPrinterFromSettings = async function () {
  if (!window.rgPrinter) return;
  await window.rgPrinter.forget();
  window.paintPrinterDeviceCard();
};

window.savePrinterSettingsForm = function () {
  var widthSel = document.getElementById('printerWidthSelect');
  var modeSel = document.getElementById('printerModeSelect');
  var ipInput = document.getElementById('printerNetworkIp');
  var portInput = document.getElementById('printerNetworkPort');
  var baudSel = document.getElementById('printerSerialBaud');
  if (!widthSel || !modeSel) return;

  window.savePrinterSettings({
    widthMM: parseInt(widthSel.value, 10) === 58 ? 58 : 80,
    mode: window.normalizePrinterMode(modeSel.value),
    networkIp: ipInput.value.trim(),
    networkPort: parseInt(portInput.value, 10) || 9100,
    serialBaud: (baudSel && parseInt(baudSel.value, 10)) || 9600
  });
  var barSel = document.getElementById('barPrintSelect');
  if (barSel) {
    var barIp = document.getElementById('barNetworkIp').value.trim();
    // the bar's own printer needs its address; without one its tickets print on the printer above
    window.savePrinterSettings({ barPrint: barSel.value === 'network' && !barIp ? 'same' : barSel.value, barNetworkIp: barIp, barNetworkPort: parseInt(document.getElementById('barNetworkPort').value, 10) || 9100 });
    if (barSel.value === 'network' && !barIp) { barSel.value = 'same'; window.showBarPrinterFields(); }
  }
  // a Bluetooth / USB printer picked before: open it now rather than at the first bill
  if (window.rgPrinter) window.rgPrinter.reconnect();

  var showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
  showMsg('Printer settings saved', 'success');
};

// Reads the printer settings form's current values directly, so Test Print
// reflects whatever is selected right now even if "Save" hasn't been
// clicked yet. Falls back to the saved settings if the form isn't on the
// current page.
function getLivePrinterSettings() {
  var widthSel = document.getElementById('printerWidthSelect');
  var modeSel = document.getElementById('printerModeSelect');
  if (!widthSel || !modeSel) return window.getPrinterSettings();

  var ipInput = document.getElementById('printerNetworkIp');
  var portInput = document.getElementById('printerNetworkPort');
  var baudSel = document.getElementById('printerSerialBaud');
  return {
    widthMM: parseInt(widthSel.value, 10) === 58 ? 58 : 80,
    mode: window.normalizePrinterMode(modeSel.value),
    networkIp: ipInput ? ipInput.value.trim() : '',
    networkPort: (portInput && parseInt(portInput.value, 10)) || 9100,
    serialBaud: (baudSel && parseInt(baudSel.value, 10)) || 9600
  };
}

// Prints (or previews) a sample receipt using whatever printer settings are
// currently selected in the form, so paper width / connection mode can be
// verified without a real order - and without owning a printer, via the
// browser preview or the bundled virtual_printer_server.php for Network mode.
window.testPrintReceipt = async function () {
  var settings = getLivePrinterSettings();
  window.savePrinterSettings(settings); // Test Print always reflects (and locks in) what's on screen right now
  var currency = window.globalCurrencySymbol || '₹';
  var restaurantNameEl = document.getElementById('profileRestaurantName');
  var restaurantName = (restaurantNameEl && restaurantNameEl.textContent && restaurantNameEl.textContent !== 'Loading...')
    ? restaurantNameEl.textContent
    : 'Your Restaurant';

  // a sample bill in the same layout real bills print in (the phone app's)
  var builder = window.buildBillEscPos({
    charWidth: window.getPrinterCharWidth(settings.widthMM),
    restaurantName: restaurantName,
    address: '123 Sample Street, City',
    phone: '+91 98765 43210',
    billNo: 'TEST',
    createdAt: new Date(),
    billTo: 'Test print (' + settings.widthMM + 'mm, ' + window.printerModeLabel(settings.mode) + ')',
    items: [
      { name: 'Paneer Butter Masala', quantity: 2, rate: 100 },
      { name: 'Butter Naan', quantity: 1, rate: 50 }
    ],
    subtotal: 250,
    total: 250,
    paymentMethod: 'Cash',
    notes: ['If this lines up, you are ready to print!']
  });
  var showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);

  // The preview renders the exact bytes a real printer would receive as
  // monospace text, at the configured paper width - the most accurate
  // preview possible without physical hardware.
  var plainText = builder.toPlainText();
  var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Test Print</title><style>' +
    window.getThermalReceiptCSS(settings.widthMM) +
    'pre { font-family:"Courier New",Courier,monospace; white-space:pre-wrap; word-break:break-word; }' +
    '</style></head><body><pre>' + escapeHtml(plainText) + '</pre></body></html>';
  var preview = function () { showPrintPreview('Test Print (' + settings.widthMM + 'mm)', html); };

  if (settings.mode === 'network') {
    const sent = await window.printDirect(builder);
    if (sent) showMsg('Test print sent to ' + settings.networkIp + ':' + settings.networkPort + '. Check your printer (or the virtual printer terminal).', 'success');
    else preview();
    return;
  }

  // Bluetooth / USB: the preview opens at once; the test also goes to the printer when it's
  // connected. Test print never opens the pairing window (that's the Connect printer button).
  if (settings.mode === 'bluetooth' || settings.mode === 'serial') {
    preview();
    var p = window.rgPrinter;
    if (p && p.isConnected(settings.mode)) {
      window.printDirect(builder, null, { wait: true }).then(function (ok) {
        var r = p.route();
        if (ok) showMsg('Test sent to ' + (p.info().name || 'your printer') + (r && r.of > 1 ? '. Nothing came out? Tap "Try another way".' : '.'), 'success');
        else { var box = document.getElementById('printerLogBox'); if (box) box.open = true; }
        if (window.paintPrinterDeviceCard) window.paintPrinterDeviceCard();
      });
    } else {
      showMsg('Printer not connected. Tap "Connect printer" first, then Test print.', 'info');
      if (p) p.reconnect();
    }
    return;
  }

  preview();
};

// "Try another way": the test went out but nothing printed. Printers differ in how they take
// data, so send it the next way; the way that prints is remembered for this printer.
window.tryNextPrinterWay = async function () {
  var p = window.rgPrinter;
  if (!p) return;
  var showMsg = typeof showMessage !== 'undefined' ? showMessage : (window.showNotification || alert);
  if (!(await p.nextRoute())) {
    p.firstRoute();
    var box = document.getElementById('printerLogBox');
    if (box) { box.open = true; box.scrollIntoView({ block: 'center' }); }
    showMsg('Tried every way this printer offers. Check the paper roll (shiny side to the print head), then tap Copy log under Printer log and send it to support.', 'error');
    window.paintPrinterDeviceCard();
    return;
  }
  window.paintPrinterDeviceCard();
  window.testPrintReceipt();
};

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
  if (window.rgOrderTabs) orders = window.rgOrderTabs('orders', orders, displayOrders);   // Ongoing / Completed tabs (order-tabs.js)
  const ordersList = document.getElementById('ordersList');
  
  if (!orders || orders.length === 0) {
    showEmptyState('ordersList', 'receipt_long', 'No orders found', 'Orders will appear here once customers start ordering. Try adjusting your filters.', '<button class="empty-action-btn" onclick="resetAllOrdersFilters();"><span class="material-symbols-rounded" style="font-size:16px;">refresh</span> Reset Filters</button>');
    return;
  }
  
  // every card has the same layout (assets/js/order-card.js)
  ordersList.innerHTML = orders.map(order => window.rgOrderCard(order, 'orders')).join('');
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
    const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().split('T')[0];
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
      showNotification(data.order_number ? `KOT completed! Order #${data.order_number} created.` : 'KOT completed. The order is in Orders.', 'success');
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
// add-ons for the whole order (not on a dish) travel in the ticket note: [Add-ons: Raita x2, ...]
function kotOrderAddons(kot) {
  const m = String((kot && kot.notes) || '').match(/\[Add-ons:\s*([^\]]+)\]/);
  return m ? m[1].trim() : '';
}

// opts.auto: printed by itself after a POS send (not the Print button)
window.printKOT = async function(kotId, onClose, opts) {
  try {
    // Fetch KOT data for specific KOT
    const res = await rgFetchRetry('../api/get_kot.php' + (kotId ? '?kot_id=' + kotId : ''));
    const data = await res.json();
    if (!data.success) { showSweetAlert('Unable to load KOT'); return; }
    const kot = data.kot || (data.kots || []).find(k => String(k.id) === String(kotId));
    if (!kot) { showSweetAlert('KOT not found'); return; }
    // a Bar Order Ticket (config/prep_stations.php): headed BOT, and sent to the bar printer
    const isBot = kot.station === 'bar';
    const label = isBot ? 'BOT' : 'KOT';
    const accent = isBot ? '#6d28d9' : '#dc2626';
    let barRoute = isBot ? window.getBarPrintRoute() : 'same';
    if (barRoute === 'none') {
      // the bar reads it on screen: nothing prints by itself, but the Print button still does
      if (opts && opts.auto) { if (typeof onClose === 'function') onClose(); return; }
      barRoute = 'same';
    }

    // Fetch restaurant info
    let restaurantInfo = {
      name: 'Restaurant Name',
      logo: '',
      address: '',
      phone: '',
      email: ''
    };
    
    try {
      const infoRes = await rgFetchRetry('../admin/get_session.php');
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
              <div style="font-weight:600; font-size:14px; color:#111827;">${escapeHtml(it.item_name || it.name)}${it.addon_name ? ' <span style="font-size:11px; color:#b45309;">(add-on)</span>' : ''}</div>
              ${(it.addons || []).length ? `<div style="color:#b45309; font-size:12px; font-weight:700; margin-top:2px;">+ ${it.addons.map(a => escapeHtml(a.name)).join(', ')}</div>` : ''}
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

    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${label} #${kot.kot_number}</title>
      <style>
        ${window.getThermalReceiptCSS()}
        .header { text-align:center; border-bottom:3px double ${accent}; padding-bottom:10px; margin-bottom:12px; }
        .logo { margin-bottom:6px; }
        .restaurant-name { font-size:18px; font-weight:800; color:${accent}; text-transform:uppercase; letter-spacing:0.5px; font-family:'Segoe UI',Tahoma,sans-serif; }
        .restaurant-details { font-size:10px; color:#6b7280; line-height:1.4; margin-top:4px; font-family:'Segoe UI',Tahoma,sans-serif; }
        .kot-banner { text-align:center; margin:12px 0; padding:10px; background:${isBot ? '#f5f3ff' : '#fef2f2'}; border:2px solid ${accent}; }
        .kot-banner h2 { font-size:28px; font-weight:900; color:${accent}; letter-spacing:3px; margin-bottom:2px; font-family:'Segoe UI',Tahoma,sans-serif; }
        .kot-banner .kot-number { font-size:22px; font-weight:700; color:#111827; }
        .kot-banner .kot-ser { font-size:10px; font-weight:600; color:${accent}; margin-bottom:2px; }
        .info-box { background:#f9fafb; padding:10px; margin-bottom:12px; border-left:4px solid ${accent}; font-size:12px; }
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
      <div class="kot-banner">
        ${isBot ? '<div class="kot-ser">BAR ORDER TICKET</div>' : ''}
        <h2>${label}</h2>
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
        <table style="width:100%; border-collapse:collapse;">
          <tr>
            <td style="width:40px; text-align:center; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:6px 0 4px; border-bottom:2px solid #111827; color:#111827;">QTY</td>
            <td style="padding-left:6px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding-top:6px; border-bottom:2px solid #111827; color:#111827;">ITEM</td>
          </tr>
        </table>
        <table>${itemsHtml}</table>
        ${kotOrderAddons(kot) ? `<div style="margin:6px 0; padding:6px 8px; border:1px dashed #b45309; font-size:13px; font-weight:700; color:#b45309;">Add-ons: ${escapeHtml(kotOrderAddons(kot))}</div>` : ''}
      </div>
      
      <hr class="divider">
      
      <div class="footer">
        <div style="font-size:11px; font-weight:600; margin-bottom:4px;">Total Items: ${itemsCount}</div>
        <div class="footer-text">Thank you!</div>
        <div class="footer-text">Powered by Restro Grow</div>
      </div>
    </body></html>`;

    const kotPrinterSettings = window.getPrinterSettings();
    if (barRoute === 'network' || window.isDirectPrintMode(kotPrinterSettings.mode)) {
      // the phone app's KOT layout (escpos.js buildKotEscPos)
      const escBuilder = window.buildKotEscPos({
        charWidth: window.getPrinterCharWidth(kotPrinterSettings.widthMM),
        bar: isBot,
        kotNumber: kot.kot_number || kot.id,
        createdAt: kot.created_at,
        table: kot.table_number ? 'Table ' + kot.table_number + (kot.area_name ? ' - ' + kot.area_name : '') : (kot.order_type || 'Takeaway'),
        items: (kot.items || []).map(it => ({
          name: (it.item_name || it.name || '') + (it.addon_name ? ' (add-on)' : ''),
          quantity: it.quantity,
          sub: [((it.addons || []).length ? '+ ' + it.addons.map(a => a.name).join(', ') : ''), ((it.notes || it.special_instructions) ? '* ' + (it.notes || it.special_instructions) : '')].filter(Boolean).join(' ')
        })),
        notes: kotOrderAddons(kot) ? ['Add-ons: ' + kotOrderAddons(kot)] : []
      });
      // onFail shows the preview (and the bill after it, via onClose) when the printer isn't reachable
      let next = typeof onClose === 'function' ? onClose : null;
      const once = () => { const f = next; next = null; if (f) f(); };
      const preview = () => showPrintPreview(label + ' #' + kot.kot_number, html, once);
      // the bar's own network printer (Settings › Printer › Bar tickets), whatever prints the bills
      const sent = barRoute === 'network'
        ? await window.printViaNetwork(escBuilder, { ip: kotPrinterSettings.barNetworkIp, port: kotPrinterSettings.barNetworkPort, name: 'bar printer' })
        : await window.printDirect(escBuilder, preview);
      if (sent) once(); else if (barRoute === 'network') preview();
      return;
    }

    showPrintPreview(label + ' #' + kot.kot_number, html, onClose);
  } catch (e) {
    console.error('Print error', e);
    showSweetAlert('Failed to print KOT');
  }
};

// Prints what a POS send made: the KOT, then the BOT (drinks, config/prep_stations.php), then `then`
// (the bill). Either ticket may be missing (no food / no drinks this time).
window.printSentTickets = function (result, then) {
  const ids = [result && result.kot_id, result && result.bot_id].filter(Boolean);
  const next = () => {
    const id = ids.shift();
    if (!id) { if (typeof then === 'function') then(); return; }
    window.printKOT(id, next, { auto: true });
  };
  next();
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
    content.style.cssText = 'flex:1;overflow:auto;padding:16px;background:#f3f4f6;display:flex;align-items:flex-start;justify-content:flex-start;';

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
    
    // Write HTML to iframe and inject preview-friendly CSS.
    // The receipt templates only turn monochrome/paper-sized inside
    // "@media print"; the preview should show the bill exactly as it will
    // come off the printer, so those rules are re-pointed at "all" here
    // (the actual print popup still gets the untouched HTML).
    var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
    iframeDoc.open();
    iframeDoc.write(String(htmlContent).replace(/@media\s+print/g, '@media all'));
    iframeDoc.close();
    
    // Fit receipt into preview at optimum zoom (no scrolling needed)
    setTimeout(function() {
      try {
        var bodyEl = iframeDoc.body;
        if (!bodyEl) return;
        
        var contentH = bodyEl.scrollHeight;
        var contentW = bodyEl.scrollWidth;
        var availW = iframe.clientWidth - 10;
        var availH = iframe.clientHeight - 10;

        if (contentW > 0 && contentH > 0 && availH > 0) {
          var zoom = (availW / contentW) * 0.92;
          zoom = Math.max(0.6, Math.min(zoom, 2.0));
          
          var ps = iframeDoc.createElement('style');
          ps.textContent = 'body { zoom: ' + zoom + '; margin:8px; border:1px solid #d1d5db; box-shadow:0 4px 24px rgba(0,0,0,0.12); } @media print { body { zoom:1; border:none; box-shadow:none; margin:0; } }';
          iframeDoc.head.appendChild(ps);
        }
      } catch(e) { /* ignore if iframe not ready */ }
    }, 200);
  };
  
// Print Order
window.printOrder = async function(orderId) {
  try {
    // Fetch order details
    const response = await rgFetchRetry(`../api/get_order_details_by_id.php?id=${orderId}`);
    const data = await response.json();
    
    if (!data.success || !data.order) {
      showSweetAlert('Unable to load order details');
      return;
    }
    
    const order = data.order;
    // Combos are stored as one priced line ("Family Combo") plus a 0-price line per
    // dish that makes it up, noted "Combo: <name>". On the bill we show only the combo
    // name, so drop those ingredient lines (the kitchen ticket still lists them).
    const items = (Array.isArray(order.items) ? order.items : []).filter(it =>
      !(/^Combo:\s/i.test(String(it.notes || '')) && !(parseFloat(it.total_price) || 0)));

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
      const infoRes = await rgFetchRetry('../admin/get_session.php');
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
    // every discount on the order: coupon / manual (discount_amount), points (loyalty_discount_amount), VIP
    const discount = order.discount != null ? parseFloat(order.discount || 0)
      : (parseFloat(order.discount_amount) || 0) + (parseFloat(order.loyalty_discount_amount) || 0) + (parseFloat(order.vip_discount) || 0);
    const total = parseFloat(order.total || 0);
    
    // Professional items table: # | Item | Qty | Rate | Amount
    const itemsHtml = items.map((it, idx) => {
      const itemTotal = (parseFloat(it.total_price) || 0);
      const qty = it.quantity || 1;
      // the order API sends unit_price (price is only on older payloads)
      const itemRate = parseFloat(it.price != null ? it.price : it.unit_price) || (itemTotal / qty) || 0;
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
        ${window.getThermalReceiptCSS()}
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
        .items-tbl { width:100%; border-collapse:collapse; }
        .items-tbl th { font-size:9px; font-weight:700; text-transform:uppercase; padding:4px 2px; border-bottom:2px solid #111827; color:#111827; letter-spacing:0.5px; white-space:nowrap; }
        .items-tbl th.col-idx { width:18px; text-align:center; color:#6b7280; }
        .items-tbl th.col-item { text-align:left; }
        .items-tbl th.col-qty { width:26px; text-align:center; }
        .items-tbl th.col-rate { width:55px; text-align:right; }
        .items-tbl th.col-total { width:60px; text-align:right; }
        .items-tbl td { vertical-align:top; overflow-wrap:break-word; }
        .items-tbl tbody tr:last-child td { border-bottom:1px dashed #d1d5db; }
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
        ${window.restaurantCountryCode === 'NP' ? '<div class="info-line"><span class="label">TAX INVOICE</span><span class="value">' + (order.bill_date_bs ? escapeHtml(order.bill_date_bs) + ' BS' : '') + '</span></div>' : ''}
        <div class="info-line"><span class="label">Bill</span><span class="value">#${escapeHtml(order.bill_label || order.bill_no || order.order_number || order.id)}</span></div>
        <div class="info-line"><span class="label">Invoice</span><span class="value">#INV-${String(order.created_at || "").slice(0, 10).replace(/-/g, "")}-${escapeHtml(String(order.order_number || order.id))}</span></div>
        <div class="info-line"><span class="label">Order No</span><span class="value">#${escapeHtml(order.id || order.order_number)}</span></div>
      </div>

      <div class="pay-to">
        <div class="label">Pay To</div>
        <div class="name">${escapeHtml(restaurantInfo.name)}</div>
      </div>

      <hr class="sep-line">

      <div class="items-section">
        <table class="items-tbl">
          <thead>
            <tr>
              <th class="col-idx">#</th>
              <th class="col-item">Items</th>
              <th class="col-qty">Qty</th>
              <th class="col-rate">Rate</th>
              <th class="col-total">Amount</th>
            </tr>
          </thead>
          <tbody>${itemsHtml}</tbody>
        </table>
      </div>

      <hr class="sep-line">

      <div class="totals">
        <div class="total-row gross">
          <span class="label">SUBTOTAL</span>
          <span class="value">${formatCurrency(subtotal)}</span>
        </div>
        ${discount > 0 ? `
        <div class="total-row">
          <span class="label">Discount</span>
          <span class="value">-${formatCurrency(discount)}</span>
        </div>
        ` : ''}
        ${(parseFloat(order.service_charge) || 0) > 0 ? `
        <div class="total-row">
          <span class="label">Service charge</span>
          <span class="value">${formatCurrency(parseFloat(order.service_charge))}</span>
        </div>
        ` : ''}
        ${tax > 0 ? `
        <div class="total-row">
          <span class="label">${escapeHtml(window.taxName || 'Tax')}${window.pricesIncludeTax ? ' (included)' : ''}</span>
          <span class="value">${formatCurrency(tax)}</span>
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

    const orderPrinterSettings = window.getPrinterSettings();
    if (window.isDirectPrintMode(orderPrinterSettings.mode)) {
      // the phone app's bill layout (escpos.js buildBillEscPos)
      const orderImages = await window.rgReceiptImages(restaurantInfo, orderPrinterSettings.widthMM);
      const escBuilder = window.buildBillEscPos({
        charWidth: window.getPrinterCharWidth(orderPrinterSettings.widthMM),
        restaurantName: restaurantInfo.name,
        address: restaurantInfo.address,
        phone: restaurantInfo.phone,
        email: restaurantInfo.email,
        billNo: order.bill_label || order.bill_no || order.order_number || order.id,
        createdAt: order.created_at,
        billTo: order.customer_name || (order.table_name || order.table_number ? 'Table ' + (order.table_name || order.table_number) + (order.area_name ? ' - ' + order.area_name : '') : (order.order_type || 'Walk-in')),
        items: items.map(it => ({
          // A combo header line carries variation_name 'Combo' (an internal marker);
          // print just the combo name, not "Family Combo (Combo)".
          name: (it.item_name || it.name || '') + (it.variation_name && it.variation_name !== 'Combo' ? ' (' + it.variation_name + ')' : ''),
          quantity: it.quantity || 1,
          rate: parseFloat(it.price != null ? it.price : it.unit_price) || 0,
          amount: parseFloat(it.total_price) || 0
        })),
        subtotal: subtotal,
        discounts: discount > 0 ? [['Discount', discount]] : [],
        taxLabel: window.taxName || 'Tax',
        tax: tax,
        total: total,
        paymentMethod: paymentMethod,
        logoRaster: orderImages.logoRaster,
        qrRaster: orderImages.qrRaster,
        qrLabel: 'Scan to Pay'
      });
      const sent = await window.printDirect(escBuilder, () => showPrintPreview('Invoice #' + order.order_number, html));
      return; // printDirect's onFail already showed the preview when sent is false
    }

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
// "Mark paid" for orders whose bill is settled later (waiter tables, cash on
// delivery, part payments). Uses update_payment_status.php (state machine,
// manage-orders permission). Hidden once paid, cancelled or rejected.
function markPaidButtonHtml(order) {
  const pay = order.payment_status || 'Pending';
  const st = order.order_status || '';
  if (pay === 'Paid' || pay === 'Refunded' || st === 'Cancelled' || st === 'Rejected') return '';
  return `<button class="btn" onclick="markOrderPaid(${parseInt(order.id, 10)}, this)" style="background:#16a34a;color:#fff;border:none;display:flex;align-items:center;gap:0.25rem;">
      <span class="material-symbols-rounded" style="font-size:1rem;vertical-align:middle;">payments</span>Mark paid</button>`;
}
window.markPaidButtonHtml = markPaidButtonHtml;

async function markOrderPaid(orderId, btn) {
  if (!(await (window.rgConfirm ? rgConfirm('The full amount has been received for this order.', { title: 'Mark as paid?', confirmText: 'Mark paid', icon: 'payments', danger: false }) : Promise.resolve(window.confirm('Mark this order as fully paid?'))))) return;
  if (btn) btn.disabled = true;
  const notify = (m, t) => (typeof showMessage === 'function' ? showMessage(m, t) : (window.showNotification || alert)(m, t));
  try {
    const res = await fetch('../api/update_payment_status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ orderId: String(orderId), status: 'Paid' })
    });
    const d = await res.json();
    if (!d.success) throw new Error(d.message || 'Could not update payment');
    notify('Payment recorded — order marked as paid.', 'success');
    if (document.getElementById('ordersList') && typeof loadOrders === 'function') await loadOrders();
    if (document.getElementById('onlineOrdersList') && typeof loadOnlineOrders === 'function') await loadOnlineOrders();
  } catch (e) {
    notify(e.message, 'error');
    if (btn) btn.disabled = false;
  }
}
window.markOrderPaid = markOrderPaid;

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
      // This function is shared by both the regular Orders tab and the
      // Online Orders tab — refresh whichever one(s) are actually present
      // instead of only the regular tab's list.
      if (document.getElementById('ordersList')) await loadOrders();
      if (document.getElementById('onlineOrdersList') && typeof loadOnlineOrders === 'function') await loadOnlineOrders();
    } else {
      showSweetAlert(data.message || 'Failed to update order status');
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
    listEl.innerHTML = '<div class="sx-empty"><span class="material-symbols-rounded">payments</span><b>No payment methods yet</b><span>Click “Add method” to add one.</span></div>';
    return;
  }

  // an icon from the method's name (the emoji stays for the POS, which shows it at checkout)
  const iconFor = (name) => {
    const n = String(name || '').toLowerCase();
    if (/cash/.test(n)) return ['payments', '#16a34a'];
    if (/card|visa|master|rupay/.test(n)) return ['credit_card', '#2563eb'];
    if (/upi|gpay|google pay|paytm|phonepe|bhim/.test(n)) return ['qr_code_2', '#7c3aed'];
    if (/wallet/.test(n)) return ['account_balance_wallet', '#d97706'];
    if (/bank|neft|imps|rtgs|transfer/.test(n)) return ['account_balance', '#0891b2'];
    if (/cheque|check/.test(n)) return ['edit_note', '#6b7280'];
    if (/online|net/.test(n)) return ['language', '#0891b2'];
    if (/gift|voucher|coupon/.test(n)) return ['redeem', '#db2777'];
    return ['payments', '#6b7280'];
  };

  listEl.innerHTML = paymentMethodsCache.map(method => {
    const methodName = (method.method_name || 'Unnamed Method').trim();
    const isActive = method.is_active == 1 || method.is_active === true;
    const isBuiltin = method.is_builtin == 1 || method.is_builtin === true;
    const qrUrl = method.qr_code_url || '';
    const ic = iconFor(methodName);
    return `
    <div class="payment-method-card gw-pm${isActive ? '' : ' off'}">
      <div class="gw-pm-top">
        <span class="sx-ic material-symbols-rounded" style="--c:${ic[1]}">${ic[0]}</span>
        <div class="gw-pm-name"><b>${escapeHtml(methodName)}</b><small>${isBuiltin ? 'Built in' : 'Added by you'} · position ${escapeHtml(String(method.display_order ?? 0))}</small></div>
        <span class="sx-pill ${isActive ? 'ok' : 'muted'}">${isActive ? 'On' : 'Off'}</span>
      </div>
      ${qrUrl ? `<div class="gw-pm-qr"><img src="${escapeHtml(qrUrl)}" alt="Payment QR for ${escapeHtml(methodName)}" onerror="this.parentElement.style.display='none';"><small>Shown to the customer when they pay by ${escapeHtml(methodName)}</small></div>` : ''}
      <div class="gw-pm-acts">
        <button type="button" class="btn-action-small" onclick="editPaymentMethodEntry(${method.id})"><span class="material-symbols-rounded">edit</span>Edit</button>
        <button type="button" class="btn-action-small" onclick="uploadPaymentMethodQR(${method.id})"><span class="material-symbols-rounded">qr_code_2</span>${qrUrl ? 'Change QR' : 'Add QR'}</button>
        <button type="button" class="btn-action-small" onclick="togglePaymentMethodStatus(${method.id})"><span class="material-symbols-rounded">${isActive ? 'toggle_off' : 'toggle_on'}</span>${isActive ? 'Turn off' : 'Turn on'}</button>
        ${isBuiltin ? '' : `<button type="button" class="btn-action-small delete-btn" onclick="deletePaymentMethodEntry(${method.id})" title="Delete"><span class="material-symbols-rounded">delete</span></button>`}
      </div>
    </div>`;
  }).join('');
}

// Payment method filter options — built from this restaurant's actual
// payment_methods list (built-ins + custom extras) instead of a hardcoded
// array, so a newly added method shows up here without a code change.
function updatePaymentMethodFilterOptions(filterEl = document.getElementById('paymentMethodFilter'), allLabel = 'All Methods', allValue = '') {
  if (!filterEl) return;

  const previousValue = filterEl.value;
  filterEl.innerHTML = '';
  const allOption = document.createElement('option');
  allOption.value = allValue;
  allOption.textContent = allLabel;
  filterEl.appendChild(allOption);

  const methods = paymentMethodsCache.length
    ? paymentMethodsCache.filter(m => m.is_active == 1 || m.is_active === true)
    : [{ method_name: 'Cash' }, { method_name: 'Card' }, { method_name: 'UPI' }];

  methods.forEach(method => {
    const option = document.createElement('option');
    option.value = method.method_name;
    option.textContent = method.method_name;
    filterEl.appendChild(option);
  });

  if (previousValue && Array.from(filterEl.options).some(opt => opt.value === previousValue)) {
    filterEl.value = previousValue;
  }
}

// ─── Payment Methods admin CRUD (modal + list) ──────────────────────────────

const PAYMENT_METHOD_EMOJI_DEFAULT = '💳';

function openPaymentMethodModal(data) {
  const modal = document.getElementById('paymentMethodModal');
  const title = document.getElementById('paymentMethodModalTitle');
  const idInput = document.getElementById('paymentMethodId');
  const nameInput = document.getElementById('paymentMethodName');
  const emojiInput = document.getElementById('paymentMethodEmoji');
  const activeInput = document.getElementById('paymentMethodActive');
  if (!modal) return;

  if (data && data.id) {
    title.textContent = 'Edit Payment Method';
    idInput.value = data.id;
    nameInput.value = data.method_name || '';
    emojiInput.value = data.emoji || PAYMENT_METHOD_EMOJI_DEFAULT;
    activeInput.checked = (data.is_active == 1 || data.is_active === true);
    // Built-in methods (Cash/Card/UPI) keep their name locked — renaming
    // them would desync every place that already matches on that exact
    // string (KOT notes, order filters, PhonePe routing checks upstream).
    nameInput.disabled = !!(data.is_builtin == 1 || data.is_builtin === true);
  } else {
    title.textContent = 'Add Payment Method';
    idInput.value = '';
    nameInput.value = '';
    nameInput.disabled = false;
    emojiInput.value = '';
    activeInput.checked = true;
  }

  modal.style.display = 'block';
  document.body.style.overflow = 'hidden';
  setTimeout(() => { if (!nameInput.disabled) nameInput.focus(); }, 150);
}

function closePaymentMethodModal() {
  closeModal('paymentMethodModal');
}

function selectEmoji(emoji) {
  const emojiInput = document.getElementById('paymentMethodEmoji');
  if (emojiInput) emojiInput.value = emoji;
}

async function savePaymentMethod(event) {
  event.preventDefault();
  const id = document.getElementById('paymentMethodId').value;
  const name = document.getElementById('paymentMethodName').value.trim();
  const emoji = document.getElementById('paymentMethodEmoji').value.trim();
  const isActive = document.getElementById('paymentMethodActive').checked ? 1 : 0;
  const saveBtn = event.target.querySelector('button[type=submit]');

  if (!name) { (window.showNotification || alert)('Please enter a method name.', 'error'); return false; }

  const formData = new FormData();
  formData.append('action', id ? 'update' : 'create');
  if (id) formData.append('id', id);
  formData.append('method_name', name);
  formData.append('emoji', emoji);
  formData.append('is_active', isActive);

  const origText = saveBtn ? saveBtn.textContent : '';
  if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving...'; }

  try {
    const r = await fetch('../api/save_payment_method.php', { method: 'POST', body: formData });
    const d = await r.json();
    const notify = window.showNotification || function(m, t) { alert(m); };
    if (d.success) {
      notify(d.message || 'Saved', 'success');
      closePaymentMethodModal();
      loadPaymentMethods(true);
    } else {
      notify(d.message || 'Failed to save payment method', 'error');
    }
  } catch (e) {
    (window.showNotification || alert)('Network error. Please try again.', 'error');
  } finally {
    if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = origText || 'Save Method'; }
  }
  return false;
}

function editPaymentMethodEntry(id) {
  const method = getCachedPaymentMethod(id);
  if (!method) { (window.showNotification || alert)('Payment method not found.', 'error'); return; }
  openPaymentMethodModal(method);
}

async function togglePaymentMethodStatus(id) {
  const formData = new FormData();
  formData.append('action', 'toggle');
  formData.append('id', id);
  try {
    const r = await fetch('../api/save_payment_method.php', { method: 'POST', body: formData });
    const d = await r.json();
    const notify = window.showNotification || function(m, t) { alert(m); };
    if (d.success) { notify(d.message, 'success'); loadPaymentMethods(true); }
    else notify(d.message || 'Failed to update status', 'error');
  } catch (e) {
    (window.showNotification || alert)('Network error. Please try again.', 'error');
  }
}

async function deletePaymentMethodEntry(id) {
  const method = getCachedPaymentMethod(id);
  if (method && (method.is_builtin == 1 || method.is_builtin === true)) {
    (window.showNotification || alert)('Built-in payment methods can be disabled but not deleted.', 'error');
    return;
  }
  // no "are you sure": it goes away at once with Undo for a few seconds (assets/js/rg-undo.js)
  rgUndo({
    html: rgUndo.label('Deleted payment method', ''),
    hide: rgUndo.rowOf(window.event, '.payment-method-card'),
    request: { url: '../api/save_payment_method.php', body: { action: 'delete', id: id } },
    onDone: function () { loadPaymentMethods(true); },
    onError: function (msg) { (window.showNotification || alert)(msg, 'error'); }
  });
}

function uploadPaymentMethodQR(id) {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/png,image/jpeg,image/webp,image/gif';
  input.style.display = 'none';
  input.addEventListener('change', async () => {
    const file = input.files && input.files[0];
    if (!file) return;
    if (file.size > 3 * 1024 * 1024) {
      (window.showNotification || alert)('Image must be under 3MB.', 'error');
      return;
    }
    const formData = new FormData();
    formData.append('action', 'uploadQR');
    formData.append('id', id);
    formData.append('qr_image', file);
    try {
      const r = await fetch('../api/save_payment_method.php', { method: 'POST', body: formData });
      const d = await r.json();
      const notify = window.showNotification || function(m, t) { alert(m); };
      if (d.success) { notify(d.message, 'success'); loadPaymentMethods(true); }
      else notify(d.message || 'Upload failed', 'error');
    } catch (e) {
      (window.showNotification || alert)('Network error. Please try again.', 'error');
    } finally {
      input.remove();
    }
  });
  document.body.appendChild(input);
  input.click();
}

// Outside-click / submit wiring for the Payment Method modal
document.addEventListener('DOMContentLoaded', function() {
  const paymentMethodModal = document.getElementById('paymentMethodModal');
  if (paymentMethodModal) {
    paymentMethodModal.addEventListener('click', function(e) {
      if (e.target === paymentMethodModal) closePaymentMethodModal();
    });
  }
  const paymentMethodForm = document.getElementById('paymentMethodForm');
  if (paymentMethodForm) {
    paymentMethodForm.addEventListener('submit', savePaymentMethod);
  }
});

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

      // KM-based delivery charge toggle + rate
      if (user.enable_km_delivery !== undefined) {
        const enableKmDeliveryCb = document.getElementById('enableKmDeliveryToggle');
        const enableKmDeliveryLabelEl = document.getElementById('enableKmDeliveryLabel');
        const deliveryRatePerKmGroup = document.getElementById('deliveryRatePerKmGroup');
        if (enableKmDeliveryCb) {
          const kmDeliveryEnabled = user.enable_km_delivery == 1 || user.enable_km_delivery === '1';
          enableKmDeliveryCb.checked = kmDeliveryEnabled;
          if (enableKmDeliveryLabelEl) enableKmDeliveryLabelEl.textContent = kmDeliveryEnabled ? 'Enabled' : 'Disabled';
          if (deliveryRatePerKmGroup) deliveryRatePerKmGroup.style.display = kmDeliveryEnabled ? 'block' : 'none';
        }
      }
      const deliveryRatePerKmEl = document.getElementById('deliveryRatePerKm');
      if (deliveryRatePerKmEl) deliveryRatePerKmEl.value = user.delivery_rate_per_km ? parseFloat(user.delivery_rate_per_km) : '';
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
      var enableReservationsCb = document.getElementById('enableReservationsToggle');
      if (enableReservationsCb && user.enable_reservations !== undefined) {
        enableReservationsCb.checked = user.enable_reservations == 1 || user.enable_reservations === '1';
      }

      // Set Cash on Delivery toggle from saved database settings
      if (user.cod_enabled !== undefined) {
        var enableCodCheckbox = document.getElementById('enableCodToggle');
        var enableCodLabelEl = document.getElementById('enableCodLabel');
        if (enableCodCheckbox) {
          var codEnabled = user.cod_enabled == 1 || user.cod_enabled === '1';
          enableCodCheckbox.checked = codEnabled;
          if (enableCodLabelEl) {
            enableCodLabelEl.textContent = codEnabled ? 'Enabled' : 'Disabled';
          }
          window.codEnabled = codEnabled;
        }
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
      if (user.tax_name !== undefined || user.tax_percent !== undefined) {
        var taxNameInput = document.getElementById('taxName');
        var taxPercentInput = document.getElementById('taxPercentInput');
        if (taxNameInput && user.tax_name) taxNameInput.value = user.tax_name;
        if (taxPercentInput && user.tax_percent !== undefined && user.tax_percent !== null) taxPercentInput.value = user.tax_percent;
        window.taxName = user.tax_name || 'GST';
        window.taxPercent = user.tax_percent !== undefined && user.tax_percent !== null ? parseFloat(user.tax_percent) : 5;
        // prices include tax / service charge (config/bill_rules.php)
        if (user.prices_include_tax !== undefined) {
          window.pricesIncludeTax = user.prices_include_tax == 1;
          window.serviceChargePercent = parseFloat(user.service_charge_percent) || 0;
          var pitEl = document.getElementById('pricesIncludeTaxToggle'), scEl = document.getElementById('serviceChargeInput');
          if (pitEl) pitEl.checked = window.pricesIncludeTax;
          if (scEl) scEl.value = window.serviceChargePercent || '';
        }
      }

      // Set Show Business ID toggle from saved database settings
      if (user.show_business_id !== undefined) {
        var showBusinessIdCheckbox = document.getElementById('showBusinessIdToggle');
        var showBusinessIdLabelEl = document.getElementById('showBusinessIdLabel');
        if (showBusinessIdCheckbox) {
          var businessIdShown = user.show_business_id == 1 || user.show_business_id === '1';
          showBusinessIdCheckbox.checked = businessIdShown;
          if (showBusinessIdLabelEl) {
            showBusinessIdLabelEl.textContent = businessIdShown ? 'Enabled' : 'Disabled';
          }
          window.showBusinessId = businessIdShown;
        }
      }
      if (user.business_id_label !== undefined) {
        var businessIdLabelInput = document.getElementById('businessIdLabelInput');
        // Server already resolves this to a country-based default (GSTIN in
        // India, PAN No in Nepal, PAN elsewhere) when the owner hasn't
        // customized it — see get_session.php.
        if (businessIdLabelInput && user.business_id_label) businessIdLabelInput.value = user.business_id_label;
        window.businessIdLabel = user.business_id_label || 'PAN';
      }
      if (user.business_id_no !== undefined) {
        var businessIdNoInput = document.getElementById('businessIdNoInput');
        if (businessIdNoInput && user.business_id_no) businessIdNoInput.value = user.business_id_no;
        window.businessIdNo = user.business_id_no || '';
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

    // KM-based delivery charge toggle — show/hide the rate field and flip the label live
    const enableKmDeliveryToggleEl = document.getElementById('enableKmDeliveryToggle');
    if (enableKmDeliveryToggleEl) {
      enableKmDeliveryToggleEl.addEventListener('change', function() {
        const labelEl = document.getElementById('enableKmDeliveryLabel');
        const rateGroup = document.getElementById('deliveryRatePerKmGroup');
        if (labelEl) labelEl.textContent = this.checked ? 'Enabled' : 'Disabled';
        if (rateGroup) rateGroup.style.display = this.checked ? 'block' : 'none';
      });
    }

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
          body: `action=updateRestaurantSettings&restaurant_name=${encodeURIComponent(restaurantName)}&email=${encodeURIComponent(restaurantEmail || '')}&phone=${encodeURIComponent(restaurantPhone || '')}&address=${encodeURIComponent(restaurantAddress || '')}&description=${encodeURIComponent(restaurantDescription || '')}&description_format=${encodeURIComponent(document.getElementById('descriptionFormatSettings')?.value || 'paragraph')}&opening_hours=${encodeURIComponent(JSON.stringify(openingHours))}&minimum_order_value=${encodeURIComponent(document.getElementById('minimumOrderValue')?.value || '350')}&packaging_charge=${encodeURIComponent(document.getElementById('packagingCharge')?.value || '0')}&delivery_radius_km=${encodeURIComponent(document.getElementById('deliveryRadius')?.value || '0')}&enable_km_delivery=${encodeURIComponent(document.getElementById('enableKmDeliveryToggle')?.checked ? '1' : '0')}&delivery_rate_per_km=${encodeURIComponent(document.getElementById('deliveryRatePerKm')?.value || '0')}&restaurant_lat=${encodeURIComponent(document.getElementById('restaurantAddressLat')?.value || '')}&restaurant_lng=${encodeURIComponent(document.getElementById('restaurantAddressLng')?.value || '')}&enable_gst=${encodeURIComponent(document.getElementById('enableGstToggle')?.checked ? '1' : '0')}&tax_name=${encodeURIComponent(document.getElementById('taxName')?.value || 'GST')}&tax_percent=${encodeURIComponent(document.getElementById('taxPercentInput')?.value || '5')}&enable_language=${encodeURIComponent(document.getElementById('enableLanguageToggle')?.checked ? '1' : '0')}&google_maps_link=${encodeURIComponent(document.getElementById('restaurantGoogleMapsLink')?.value || '')}&owner_name=${encodeURIComponent(document.getElementById('ownerName')?.value || '')}&instagram_link=${encodeURIComponent(document.getElementById('instagramLink')?.value || '')}&facebook_link=${encodeURIComponent(document.getElementById('facebookLink')?.value || '')}&twitter_link=${encodeURIComponent(document.getElementById('twitterLink')?.value || '')}&youtube_link=${encodeURIComponent(document.getElementById('youtubeLink')?.value || '')}&linkedin_link=${encodeURIComponent(document.getElementById('linkedinLink')?.value || '')}&enable_delivery=${encodeURIComponent(document.getElementById('enableDeliveryToggle')?.checked ? '1' : '0')}&enable_takeaway=${encodeURIComponent(document.getElementById('enableTakeawayToggle')?.checked ? '1' : '0')}&enable_dinein=${encodeURIComponent(document.getElementById('enableDineinToggle')?.checked ? '1' : '0')}&enable_reservations=${encodeURIComponent(document.getElementById('enableReservationsToggle')?.checked ? '1' : '0')}&cod_enabled=${encodeURIComponent(document.getElementById('enableCodToggle')?.checked ? '1' : '0')}&show_business_id=${encodeURIComponent(document.getElementById('showBusinessIdToggle')?.checked ? '1' : '0')}&business_id_label=${encodeURIComponent(document.getElementById('businessIdLabelInput')?.value || '')}&business_id_no=${encodeURIComponent(document.getElementById('businessIdNoInput')?.value || '')}` +
            (document.getElementById('pricesIncludeTaxToggle') ? `&prices_include_tax=${document.getElementById('pricesIncludeTaxToggle').checked ? '1' : '0'}&service_charge_percent=${encodeURIComponent(document.getElementById('serviceChargeInput')?.value || '0')}` : '')
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
  
  if (uploadBusinessQRBtn && businessQRUpload && !uploadBusinessQRBtn.dataset.handlerAttached) {
    uploadBusinessQRBtn.dataset.handlerAttached = 'true';
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
  
  // System Settings Form is handled by the inline submit listener in
  // dashboard.php (includes country/show_install_app, which this duplicate
  // legacy handler omitted — the two competing requests raced and this one's
  // country-less request kept overwriting the country back to the default).

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
      resolvePinnedLocation(p.lat(), p.lng(), function(formatted) {
        applySelectedRestaurantAddress(p.lat(), p.lng(), formatted);
      });
    });
  } else {
    google.maps.event.trigger(restaurantPreviewMap, 'resize');
    restaurantPreviewMap.setCenter(pos);
    restaurantPreviewMarker.setPosition(pos);
  }
}

// Shared Places service for looking up the business/POI at a pinned location
var restaurantPlacesService = null;
function getRestaurantPlacesService() {
  if (!restaurantPlacesService && typeof google !== 'undefined' && google.maps && google.maps.places) {
    restaurantPlacesService = new google.maps.places.PlacesService(document.createElement('div'));
  }
  return restaurantPlacesService;
}

function distanceMetersBetween(lat1, lng1, lat2, lng2) {
  var R = 6371000;
  var toRad = function(d) { return d * Math.PI / 180; };
  var dLat = toRad(lat2 - lat1);
  var dLng = toRad(lng2 - lng1);
  var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
    Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

// Resolve a dropped pin to the exact business/place name if one sits right there
// (plain reverse-geocoding only ever returns the nearest street address, never
// a business name), falling back to the street address otherwise.
function resolvePinnedLocation(lat, lng, callback) {
  var service = getRestaurantPlacesService();
  if (!service) { reverseGeocode(lat, lng, callback); return; }
  service.nearbySearch({ location: { lat: lat, lng: lng }, radius: 40 }, function(results, status) {
    var best = null, bestDist = Infinity;
    if (status === google.maps.places.PlacesServiceStatus.OK && results) {
      results.forEach(function(r) {
        var loc = r.geometry && r.geometry.location;
        if (!loc) return;
        var d = distanceMetersBetween(lat, lng, loc.lat(), loc.lng());
        if (d < bestDist) { bestDist = d; best = r; }
      });
    }
    if (best && bestDist <= 40) {
      callback(best.name + (best.vicinity ? ', ' + best.vicinity : ''));
    } else {
      reverseGeocode(lat, lng, callback);
    }
  });
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
    fields: ['formatted_address', 'geometry']
    // No `types` filter: 'geocode' would exclude business/establishment names
    // (e.g. "Royal Sekuwa & Khaja Ghar"), so we allow all place types to match.
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
  resolvePinnedLocation(lat, lng, function(formatted) {
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
    const totalExpensesEl = document.getElementById('reportTotalExpenses');
    const netProfitEl = document.getElementById('reportNetProfit');
    const salesTable = document.getElementById('reportSalesTable');
    const topItemsDiv = document.getElementById('reportTopItems');
    const paymentMethodsDiv = document.getElementById('reportPaymentMethods');

    if (totalSalesEl) totalSalesEl.textContent = 'Loading...';
    if (totalOrdersEl) totalOrdersEl.textContent = 'Loading...';
    if (totalItemsEl) totalItemsEl.textContent = 'Loading...';
    if (totalCustomersEl) totalCustomersEl.textContent = 'Loading...';
    if (totalExpensesEl) totalExpensesEl.textContent = 'Loading...';
    if (netProfitEl) netProfitEl.textContent = 'Loading...';
    if (salesTable) salesTable.innerHTML = '<tr><td colspan="6" style="padding: 2rem; text-align: center; color: #666;">Loading sales data...</td></tr>';
    if (topItemsDiv) topItemsDiv.innerHTML = '<div style="text-align: center; padding: 2rem; color: #666;">Loading...</div>';
    if (paymentMethodsDiv) paymentMethodsDiv.innerHTML = '<div style="text-align: center; padding: 2rem; color: #666;">Loading...</div>';
    
    // Build URL with parameters
    let url = `../api/get_sales_report.php?period=${period}&type=${reportType}`;
    if (period === 'custom' && startDate && endDate) {
      url += `&start_date=${startDate}&end_date=${endDate}`;
    }
    // the Payment Method filter is shown on the "Payment Methods" report, so it applies there (and to Sales)
    if (paymentMethod && paymentMethod !== 'all' && (reportType === 'sales' || reportType === 'payment')) {
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
    if (totalExpensesEl) {
      totalExpensesEl.textContent = formatCurrencyNoDecimals(data.summary?.total_expenses || 0);
    }
    if (netProfitEl) {
      const netProfit = data.summary?.net_profit || 0;
      netProfitEl.textContent = formatCurrencyNoDecimals(netProfit);
      netProfitEl.style.color = netProfit < 0 ? '#e74c3c' : '#28a745';
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
              <td style="padding: 1rem; font-weight: 600;">${escapeHtml(customer.customer_name) || 'N/A'}</td>
              <td style="padding: 1rem;">${escapeHtml(customer.phone) || '-'}</td>
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
            <td style="padding: 1rem;">${escapeHtml(order.customer_name)}</td>
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
    
    // Kitchen vs bar: sales by where the dish is made (shown only when the restaurant has a bar)
    const stationsCard = document.getElementById('reportStationsCard');
    const stationsDiv = document.getElementById('reportStations');
    if (stationsCard && stationsDiv) {
      const st = data.stations;
      stationsCard.hidden = !st;
      if (st) {
        const all = (st.kitchen.sales + st.bar.sales) || 1;
        stationsDiv.innerHTML = [['kitchen', 'Kitchen (KOT)', 'soup_kitchen'], ['bar', 'Bar (BOT)', 'local_bar']].map(([k, name, icon]) => `
          <div style="padding: 0.75rem; border-bottom: 1px solid #eee;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600;"><span class="material-symbols-rounded">${icon}</span>${name}</div>
              <div style="font-weight: 700; color: var(--primary-red);">${formatCurrencyNoDecimals(st[k].sales)}</div>
            </div>
            <div style="font-size: 0.85rem; color: #666; margin-top: 0.25rem;">${st[k].qty} sold · in ${st[k].orders} orders · ${Math.round(st[k].sales * 100 / all)}% of item sales</div>
            <div style="height: 6px; border-radius: 3px; background: #f1f1f1; margin-top: 0.5rem;"><div style="height: 100%; border-radius: 3px; width: ${Math.round(st[k].sales * 100 / all)}%; background: ${k === 'bar' ? '#7c3aed' : 'var(--primary-red, #ea580c)'};"></div></div>
          </div>
        `).join('') + '<div style="font-size: 0.8rem; color: #888; padding: 0.75rem 0.75rem 0;">Dish prices before tax and discounts, by where each dish is made today (Menu › Kitchen / Bar).</div>';
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
    const totalExpensesEl = document.getElementById('reportTotalExpenses');
    const netProfitEl = document.getElementById('reportNetProfit');
    const salesTable = document.getElementById('reportSalesTable');
    const topItemsDiv = document.getElementById('reportTopItems');
    const paymentMethodsDiv = document.getElementById('reportPaymentMethods');

    if (totalSalesEl) totalSalesEl.textContent = formatCurrencyNoDecimals(0);
    if (totalOrdersEl) totalOrdersEl.textContent = '0';
    if (totalItemsEl) totalItemsEl.textContent = '0';
    if (totalCustomersEl) totalCustomersEl.textContent = '0';
    if (totalExpensesEl) totalExpensesEl.textContent = formatCurrencyNoDecimals(0);
    if (netProfitEl) netProfitEl.textContent = formatCurrencyNoDecimals(0);
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

// Export Reports to PDF — mirrors exportReportsToCSV()'s data handling,
// rendered as tables via jsPDF's autotable plugin instead of a CSV string.
function exportReportsToPDF() {
  if (!window.currentReportData) {
    showNotification('No data to export. Please load reports first.', 'error');
    return;
  }
  if (!window.jspdf || !window.jspdf.jsPDF) {
    showNotification('PDF export is unavailable right now.', 'error');
    return;
  }

  const data = window.currentReportData;
  const reportType = data.report_type || 'sales';
  const period = data.period || 'today';

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

  const currencySymbol = globalCurrencySymbol || window.globalCurrencySymbol || '₹';
  const formatCurrency = (amount) => currencySymbol + parseFloat(amount || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });

  const doc = new window.jspdf.jsPDF();
  doc.setFontSize(14);
  doc.text(getReportTypeName(reportType), 14, 15);
  doc.setFontSize(10);
  doc.setTextColor(120);
  doc.text('Date Range: ' + dateRange, 14, 21);
  doc.text('Generated on: ' + new Date().toLocaleString('en-IN', { dateStyle: 'long', timeStyle: 'short' }), 14, 26);

  doc.autoTable({
    startY: 32,
    body: [
      ['Total Sales', formatCurrency(data.summary?.total_sales || 0)],
      ['Total Orders', String(data.summary?.total_orders || 0)],
      ['Items Sold', String(data.summary?.total_items || 0)],
      ['Total Customers', String(data.summary?.total_customers || 0)],
    ],
    theme: 'plain',
    styles: { fontSize: 10 },
  });

  let nextY = doc.lastAutoTable.finalY + 10;

  if (reportType === 'customers' && data.top_customers?.length) {
    doc.autoTable({
      startY: nextY, head: [['Customer Name', 'Phone', 'Total Orders', 'Last Order Date', 'Total Spent']],
      body: data.top_customers.map(c => [
        c.customer_name || 'N/A', c.phone || '-', String(c.total_orders),
        c.last_order_date ? new Date(c.last_order_date).toLocaleDateString('en-IN') : '-',
        formatCurrency(c.total_spent),
      ]),
      styles: { fontSize: 9 }, headStyles: { fillColor: [220, 38, 38] },
    });
  } else if (reportType === 'items' && data.top_items?.length) {
    doc.autoTable({
      startY: nextY, head: [['Item Name', 'Quantity Sold', 'Total Revenue']],
      body: data.top_items.map(i => [i.item_name, String(i.total_quantity), formatCurrency(i.total_revenue)]),
      styles: { fontSize: 9 }, headStyles: { fillColor: [220, 38, 38] },
    });
  } else if (reportType === 'payment' && data.payment_methods?.length) {
    doc.autoTable({
      startY: nextY, head: [['Payment Method', 'Order Count', 'Total Amount']],
      body: data.payment_methods.map(m => [m.payment_method, String(m.count), formatCurrency(m.amount)]),
      styles: { fontSize: 9 }, headStyles: { fillColor: [220, 38, 38] },
    });
  } else if (reportType === 'hourly' && data.hourly_sales?.length) {
    doc.autoTable({
      startY: nextY, head: [['Hour', 'Order Count', 'Total Sales']],
      body: data.hourly_sales.map(h => [
        h.hour < 12 ? `${h.hour}:00 AM` : h.hour === 12 ? '12:00 PM' : `${h.hour - 12}:00 PM`,
        String(h.order_count), formatCurrency(h.total_sales),
      ]),
      styles: { fontSize: 9 }, headStyles: { fillColor: [220, 38, 38] },
    });
  } else if (reportType === 'staff' && data.staff_performance?.length) {
    doc.autoTable({
      startY: nextY, head: [['Staff Name', 'Total Orders', 'Total Sales']],
      body: data.staff_performance.map(s => [s.staff_name || 'Unknown', String(s.total_orders), formatCurrency(s.total_sales)]),
      styles: { fontSize: 9 }, headStyles: { fillColor: [220, 38, 38] },
    });
  } else if (data.sales_details?.length) {
    doc.autoTable({
      startY: nextY, head: [['Date', 'Order Number', 'Customer', 'Items', 'Payment Method', 'Amount']],
      body: data.sales_details.map(o => [
        new Date(o.created_at).toLocaleDateString('en-IN'), o.order_number, o.customer_name || 'N/A',
        String(o.item_count || 0), o.payment_method || 'N/A', formatCurrency(o.total),
      ]),
      styles: { fontSize: 9 }, headStyles: { fillColor: [220, 38, 38] },
    });
  }

  doc.save(`${reportType}_report_${new Date().toISOString().split('T')[0]}.pdf`);
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
  
  return `${formatDateForExport(new Date(startDate.getTime() - startDate.getTimezoneOffset() * 60000).toISOString().split('T')[0])} to ${formatDateForExport(new Date(endDate.getTime() - endDate.getTimezoneOffset() * 60000).toISOString().split('T')[0])}`;
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
          setupReportsAutoReload();   // showPage() loads the report
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
    // "Custom Date Range" shows the two date boxes; the report loads once both are picked
    const syncCustom = function () {
      const custom = reportPeriod.value === 'custom';
      ['customDateRange', 'customDateRangeEnd'].forEach(function (id) { const el = document.getElementById(id); if (el) el.style.display = custom ? '' : 'none'; });
      return custom;
    };
    reportPeriod.addEventListener('change', function() {
      if (syncCustom()) {
        const s = document.getElementById('reportStartDate'), e = document.getElementById('reportEndDate');
        if (!s || !e || !s.value || !e.value) return;
      }
      loadReports();
    });
    ['reportStartDate', 'reportEndDate'].forEach(function (id) {
      const el = document.getElementById(id);
      if (el) el.addEventListener('change', function () {
        const s = document.getElementById('reportStartDate'), e = document.getElementById('reportEndDate');
        if (reportPeriod.value === 'custom' && s.value && e.value) loadReports();
      });
    });
    syncCustom();
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
  var box = document.getElementById('previewFrameBox');
  if (box) box.classList.toggle('is-mobile', device === 'mobile');
  window.fitLivePreview();
};

// Size the live preview: Desktop fills the height left in the (sticky) preview
// card; Mobile renders a real 390x844 phone screen, scaled down to fit, so the
// customer site uses its true phone layout.
window.fitLivePreview = function() {
  var sec = document.getElementById('livePreviewSection');
  var wrap = document.getElementById('previewFrameWrapper');
  var frame = document.getElementById('livePreview');
  var box = document.getElementById('previewFrameBox');
  if (!sec || !wrap || !frame || !box || !sec.offsetParent) return; // page not visible yet
  var mobile = wrap.classList.contains('mobile-view');
  // Height of everything in the card except the preview box
  var others = sec.scrollHeight - box.offsetHeight;
  var avail = window.innerWidth >= 1000 ? window.innerHeight - 32 - others - 4 : 640;
  avail = Math.max(mobile ? 360 : 420, avail - (mobile ? 24 : 0)); // mobile box has 12px padding top+bottom
  if (mobile) {
    var W = 390, H = 844;
    var s = Math.min(1, avail / H, (box.clientWidth - 24) / W);
    wrap.style.width = Math.round(W * s) + 'px';
    wrap.style.height = Math.round(H * s) + 'px';
    frame.style.width = W + 'px';
    frame.style.height = H + 'px';
    frame.style.transform = 'scale(' + s + ')';
    frame.style.transformOrigin = '0 0';
  } else {
    wrap.style.width = '';
    wrap.style.height = '';
    frame.style.width = '100%';
    frame.style.height = avail + 'px';
    frame.style.transform = '';
  }
};
window.addEventListener('resize', function() { window.fitLivePreview(); });
document.addEventListener('DOMContentLoaded', function() {
  // Phone view by default: the customer website is mostly used on phones
  if (document.getElementById('previewFrameWrapper')) window.setPreviewDevice('mobile');
  var sec = document.getElementById('livePreviewSection');
  // Refit when the Website Appearance page becomes visible or the card resizes
  if (sec && typeof ResizeObserver !== 'undefined') {
    // Resize on the next frame: fitting the preview changes its own size, which inside the
    // observer callback triggers the browser's "ResizeObserver loop" error
    var fitRaf = 0;
    new ResizeObserver(function() { cancelAnimationFrame(fitRaf); fitRaf = requestAnimationFrame(window.fitLivePreview); }).observe(sec);
  }
});
window.refreshPreview = function() {
  const iframe = document.getElementById('livePreview');
  if (iframe && window.swapPreviewTo) {
    var u = iframe.src;
    try { if (iframe.contentWindow.location.href.indexOf('http') === 0) u = iframe.contentWindow.location.href; } catch (e) {}
    window.swapPreviewTo(u);
    return;
  }
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
    const prHex = document.getElementById('primaryRedHex');
    const dr = document.getElementById('darkRed');
    const py = document.getElementById('primaryYellow');
    const pyHex = document.getElementById('primaryYellowHex');
    const cc = document.getElementById('checkoutColor');
    const ccHex = document.getElementById('checkoutColorHex');
    const fontSelect = document.getElementById('siteFontSelect');

    function applyLayoutHeaderStyleUI(layoutVal, headerVal) {
      document.querySelectorAll('.layout-style-opt').forEach(function(el) {
        var active = el.getAttribute('data-value') === layoutVal;
        el.style.borderColor = active ? '#7c3aed' : '#e5e7eb';
        var icon = el.querySelector('.material-symbols-rounded');
        if (icon) icon.style.color = active ? '#7c3aed' : '#6b7280';
        var radio = el.querySelector('input[name="layoutStyle"]');
        if (radio) radio.checked = active;
      });
      document.querySelectorAll('.header-style-opt').forEach(function(el) {
        var active = el.getAttribute('data-value') === headerVal;
        el.style.borderColor = active ? '#7c3aed' : '#e5e7eb';
        var icon = el.querySelector('.material-symbols-rounded');
        if (icon) icon.style.color = active ? '#7c3aed' : '#6b7280';
        var radio = el.querySelector('input[name="headerStyle"]');
        if (radio) radio.checked = active;
      });
    }
    // Menu page category position (Left sidebar / Top bar)
    function applyMenuNavPositionUI(val) {
      document.querySelectorAll('.menu-nav-pos-opt').forEach(function(el) {
        var active = el.getAttribute('data-value') === val;
        el.style.borderColor = active ? '#7c3aed' : '#e5e7eb';
        var icon = el.querySelector('.material-symbols-rounded');
        if (icon) icon.style.color = active ? '#7c3aed' : '#6b7280';
        var radio = el.querySelector('input[name="menuNavPosition"]');
        if (radio) radio.checked = active;
      });
    }
    // Category heading colour: Automatic (light shade of the ADD button colour) or a picked colour
    function lightAddShade() {
      var pc = document.getElementById('bsAddColor');
      var hex = (pc && /^#[0-9a-f]{6}$/i.test(pc.value)) ? pc.value : '#e53935';
      var mix = function (i) { return Math.round(parseInt(hex.substr(i, 2), 16) * 0.12 + 255 * 0.88); };
      return '#' + [1, 3, 5].map(function (i) { return ('0' + mix(i).toString(16)).slice(-2); }).join('');
    }
    function applyCategoryHeadingUI(val) {
      var auto = document.getElementById('categoryHeadingAuto'), inp = document.getElementById('categoryHeadingBg'), prev = document.getElementById('categoryHeadingPreview');
      if (!auto || !inp) return;
      if (val !== undefined) { auto.checked = !val; if (val) inp.value = val; }
      if (auto.checked) inp.value = lightAddShade();
      inp.disabled = auto.checked;
      if (prev) prev.style.background = inp.value;
    }
    function currentCategoryHeadingVal() {
      var auto = document.getElementById('categoryHeadingAuto'), inp = document.getElementById('categoryHeadingBg');
      if (!auto || !inp) return undefined;
      return auto.checked ? '' : inp.value;
    }
    ['categoryHeadingAuto', 'categoryHeadingBg', 'bsAddColor'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('input', function () { applyCategoryHeadingUI(); applyLivePreview(); });
      if (el) el.addEventListener('change', function () { applyCategoryHeadingUI(); applyLivePreview(); });
    });
    // Bestsellers style colours also update the live preview as they change
    ['input', 'change'].forEach(function (ev) {
      document.addEventListener(ev, function (e) {
        if (e.target && e.target.closest && e.target.closest('.bs-style-card')) applyLivePreview();
      });
    });
    // Menu page category pictures (Round / Cut-out)
    function applyCategoryPicStyleUI(val) {
      document.querySelectorAll('.cat-pic-style-opt').forEach(function(el) {
        var active = el.getAttribute('data-value') === val;
        el.style.borderColor = active ? '#7c3aed' : '#e5e7eb';
        var icon = el.querySelector('.material-symbols-rounded');
        if (icon) icon.style.color = active ? '#7c3aed' : '#6b7280';
        var radio = el.querySelector('input[name="categoryPicStyle"]');
        if (radio) radio.checked = active;
      });
    }
    function currentCategoryPicStyleVal() {
      var r = document.querySelector('input[name="categoryPicStyle"]:checked');
      return r ? r.value : 'round';
    }
    document.querySelectorAll('.cat-pic-style-opt').forEach(function(el) {
      el.addEventListener('click', function() { applyCategoryPicStyleUI(el.getAttribute('data-value')); });
    });
    // Custom picture for the Menu page "All" category button (saved immediately)
    (function () {
      var input = document.getElementById('allCategoryIconInput');
      var removeBtn = document.getElementById('allCategoryIconRemove');
      var prev = document.getElementById('allCategoryIconPreview');
      var status = document.getElementById('allCategoryIconStatus');
      if (!input || !prev) return;
      function setStatus(t) { if (status) status.textContent = t; }
      input.addEventListener('change', async function () {
        var file = input.files && input.files[0];
        input.value = '';
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) { setStatus('File too large (max 2MB).'); return; }
        setStatus('Uploading & processing picture…');
        var fd = new FormData();
        fd.append('icon_image', file);
        try {
          var res = await fetch('../website/theme_api.php?action=upload_all_category_icon&restaurant_id=' + encodeURIComponent(currentRestaurantId() || ''), { method: 'POST', body: fd });
          var d = await res.json();
          if (!d.success) throw new Error(d.message || 'Upload failed');
          prev.src = d.icon_url;
          setStatus('Saved. ' + (d.message || ''));
          if (window.refreshPreview) window.refreshPreview();
        } catch (e) { setStatus('Upload failed: ' + e.message); }
      });
      if (removeBtn) removeBtn.addEventListener('click', async function () {
        try {
          var res = await fetch('../website/theme_api.php?action=remove_all_category_icon&restaurant_id=' + encodeURIComponent(currentRestaurantId() || ''), { method: 'POST' });
          var d = await res.json();
          if (!d.success) throw new Error(d.message || 'Failed');
          prev.src = prev.getAttribute('data-default');
          setStatus('Back to the default grid icon.');
          if (window.refreshPreview) window.refreshPreview();
        } catch (e) { setStatus('Error: ' + e.message); }
      });
    })();
    // Menu page card design (Classic / Modern)
    function applyMenuCardStyleUI(val) {
      document.querySelectorAll('.menu-card-style-opt').forEach(function(el) {
        var active = el.getAttribute('data-value') === val;
        el.style.borderColor = active ? '#7c3aed' : '#e5e7eb';
        var icon = el.querySelector('.material-symbols-rounded');
        if (icon) icon.style.color = active ? '#7c3aed' : '#6b7280';
        var radio = el.querySelector('input[name="menuCardStyle"]');
        if (radio) radio.checked = active;
      });
    }
    // Home page dishes look (Classic / Grid / Big photo / Compact / Carousel)
    function applyHomeCardStyleUI(val) {
      document.querySelectorAll('.home-card-style-opt').forEach(function(el) {
        var active = el.getAttribute('data-value') === val;
        el.style.borderColor = active ? '#7c3aed' : '#e5e7eb';
        var icon = el.querySelector('.material-symbols-rounded');
        if (icon) icon.style.color = active ? '#7c3aed' : '#6b7280';
        var radio = el.querySelector('input[name="homeCardStyle"]');
        if (radio) radio.checked = active;
      });
    }
    function applyProfileStyleUI(val) {
      document.querySelectorAll('.profile-style-opt').forEach(function(el) {
        var active = el.getAttribute('data-value') === val;
        el.style.borderColor = active ? '#7c3aed' : '#e5e7eb';
        var radio = el.querySelector('input[name="profileStyle"]');
        if (radio) radio.checked = active;
      });
    }
    // Profile page options (sections below, avatar, corners, stats, colour)
    function currentProfileOpts() {
      var o = {};
      document.querySelectorAll('#profileDesignBox input[type="radio"][name^="pfOpt_"]:checked').forEach(function(r) { o[r.name.slice(6)] = r.value; });
      var on = document.getElementById('pfAccentOn'), col = document.getElementById('pfAccent');
      o.accent = on && on.checked && col ? col.value : '';
      return o;
    }
    function applyProfileOptsUI(o) {
      o = o || {};
      document.querySelectorAll('#profileDesignBox .pf-opt').forEach(function(el) {
        var g = el.getAttribute('data-group');
        var radio = el.querySelector('input');
        var first = el.parentNode.querySelector('.pf-opt') === el;
        var active = o[g] ? o[g] === el.getAttribute('data-value') : first;
        if (radio) radio.checked = active;
        el.style.background = active ? '#fff' : 'transparent';
        el.style.color = active ? '#7c3aed' : '#4b5563';
        el.style.boxShadow = active ? '0 1px 3px rgba(0,0,0,.12)' : 'none';
      });
      var on = document.getElementById('pfAccentOn'), col = document.getElementById('pfAccent');
      if (on) on.checked = !!o.accent;
      if (col && o.accent) col.value = o.accent;
    }
    document.querySelectorAll('#profileDesignBox .pf-opt').forEach(function(el) {
      el.addEventListener('click', function() { var o = currentProfileOpts(); o[el.getAttribute('data-group')] = el.getAttribute('data-value'); applyProfileOptsUI(o); });
    });
    // Bottom navigation bar look (Website Appearance > Bottom Navigation > Bar style)
    function applyNavBarStyleUI(val) {
      document.querySelectorAll('.nav-bar-style-opt').forEach(function(el) {
        var active = el.getAttribute('data-value') === val;
        el.style.borderColor = active ? '#7c3aed' : '#e5e7eb';
        var radio = el.querySelector('input[name="navBarStyle"]');
        if (radio) radio.checked = active;
      });
    }
    function currentNavBarStyleVal() {
      var r = document.querySelector('input[name="navBarStyle"]:checked');
      return r ? r.value : 'classic';
    }
    document.querySelectorAll('.nav-bar-style-opt').forEach(function(el) {
      el.addEventListener('click', function() { applyNavBarStyleUI(el.getAttribute('data-value')); });
    });
    function currentProfileStyleVal() {
      var r = document.querySelector('input[name="profileStyle"]:checked');
      return r ? r.value : 'midnight';
    }
    document.querySelectorAll('.profile-style-opt').forEach(function(el) {
      el.addEventListener('click', function() { applyProfileStyleUI(el.getAttribute('data-value')); });
    });
    function currentHomeCardStyleVal() {
      var r = document.querySelector('input[name="homeCardStyle"]:checked');
      return r ? r.value : 'classic';
    }
    document.querySelectorAll('.home-card-style-opt').forEach(function(el) {
      el.addEventListener('click', function() { applyHomeCardStyleUI(el.getAttribute('data-value')); });
    });
    // ── Live preview: "Current" (unsaved choices) vs "Live" (saved website) ──
    function collectPreviewTheme() {
      var val = function(sel) { var el = document.querySelector(sel); return el ? el.value : undefined; };
      var t = {
        primary_red: pr ? pr.value : undefined,
        dark_red: dr ? dr.value : undefined,
        primary_yellow: py ? py.value : undefined,
        checkout_color: cc && cc.value ? cc.value : undefined,
        font_family: val('#siteFontSelect'),
        card_style: val('#cardStyleInput'),
        layout_columns: parseInt(val('input[name="layoutColumns"]:checked') || '0', 10) || undefined,
        logo_shape: val('input[name="logoShape"]:checked')
      };
      try { t.layout_style = currentLayoutStyleVal(); } catch (e) {}
      try { t.header_style = currentHeaderStyleVal(); } catch (e) {}
      try { t.menu_nav_position = currentMenuNavPositionVal(); } catch (e) {}
      try { t.menu_card_style = currentMenuCardStyleVal(); } catch (e) {}
      try { t.home_card_style = currentHomeCardStyleVal(); } catch (e) {}
      try { t.profile_style = currentProfileStyleVal(); } catch (e) {}
      try { t.nav_bar_style = currentNavBarStyleVal(); } catch (e) {}
      // Typed text and the icon set follow the preview (and the phone) before saving too
      var nis = document.getElementById('navIconStyleInput'); if (nis && nis.value) t.nav_icon_style = nis.value;
      var sn = document.getElementById('siteNameInput'); if (sn) t.site_name = sn.value.trim();
      try { var nl = {}; NAV_LABEL_SLOTS.forEach(function(slot) { var el = navLabelInputEl(slot); if (el && el.value.trim()) nl[slot] = el.value.trim(); }); t.nav_labels = nl; } catch (e) {}
      try { t.profile_opts = currentProfileOpts(); } catch (e) {}
      try { t.category_pic_style = currentCategoryPicStyleVal(); } catch (e) {}
      try { var hb = currentCategoryHeadingVal(); t.category_heading_bg = hb || null; } catch (e) {}
      var ls = document.getElementById('logoSizeSlider'); if (ls) t.logo_size = parseInt(ls.value, 10) || 90;
      try { if (window.readBestsellerStyleForm) t.bestseller_style = window.readBestsellerStyleForm(); } catch (e) {}
      // an uploaded picture (data:) is too big for the address; applyLivePreview puts it in
      var bg = document.getElementById('backgroundThemeInput'); if (bg && bg.value.indexOf('data:') !== 0) t.background_theme = bg.value;
      return t;
    }
    var previewSaved = null; // what's saved (JSON), to show the "unsaved" dot
    function previewUrlFor(raw) {
      try {
        var u = new URL(raw, window.location.href);
        u.searchParams.delete('preview_theme');
        if (window.previewMode !== 'live') u.searchParams.set('preview_theme', JSON.stringify(collectPreviewTheme()));
        return u.toString();
      } catch (e) { return raw; }
    }
    function reloadPreviewForMode() {
      var frame = document.getElementById('livePreview');
      if (!frame) return;
      var cur = frame.getAttribute('data-base') || frame.src;
      try { if (frame.contentWindow && frame.contentWindow.location.href.indexOf('http') === 0) cur = frame.contentWindow.location.href; } catch (e) {}
      // A choice for another page (e.g. Profile Page) asks for that page first
      // (kept for a few seconds rather than used once: the settings screen can have more
      // than one of these listeners, and a second reload must not undo the first)
      var want = frame.getAttribute('data-want');
      if (want && Date.now() < +(frame.getAttribute('data-want-until') || 0)) cur = want;
      swapPreviewTo(previewUrlFor(cur));
      if (window.updateThemeDirty) window.updateThemeDirty();
    }
    // A reload goes into a hidden second preview: once it has loaded and is scrolled to the
    // same spot, it replaces the old one. No white flash, and the preview stays where you were.
    // shared on window: the editor can be set up twice, and both copies must use one preview
    var pvSwap = window.__pvSwap || (window.__pvSwap = { next: null, seq: 0 });
    function swapPreviewTo(url) {
      var cur = document.getElementById('livePreview');
      if (!cur) return;
      // the same page is already on its way (two listeners can ask for one change)
      if (pvSwap.next && pvSwap.url === url) return;
      pvSwap.url = url;
      var wrap = cur.parentNode;
      var seq = ++pvSwap.seq;
      var y = 0, anchor = null;
      try {
        // same page: stay where you were; another page (home -> menu): start at the top
        var samePage = new URL(url, location.href).pathname.replace(/\/$/, '') === cur.contentWindow.location.pathname.replace(/\/$/, '');
        if (samePage) { y = cur.contentWindow.scrollY || 0; anchor = previewAnchor(cur.contentDocument); }
      } catch (e) {}
      if (pvSwap.next && pvSwap.next.parentNode) pvSwap.next.parentNode.removeChild(pvSwap.next);
      if (getComputedStyle(wrap).position === 'static') wrap.style.position = 'relative';
      var nx = document.createElement('iframe');
      nx.title = cur.title || 'Website preview';
      nx.setAttribute('data-base', cur.getAttribute('data-base') || '');
      nx.style.cssText = cur.style.cssText;
      nx.style.position = 'absolute';
      nx.style.left = '0';
      nx.style.top = '0';
      nx.style.visibility = 'hidden';
      nx.style.pointerEvents = 'none';
      pvSwap.next = nx;
      nx.addEventListener('load', function onFirstLoad() {
        nx.removeEventListener('load', onFirstLoad);
        if (seq !== pvSwap.seq) return;
        var start = Date.now(), lastH = -1, stableSince = 0;
        // the menu fills in after the page loads: wait until the page stops growing (or 1.5 s),
        // then scroll to the same spot, so nothing moves after the swap
        (function settle() {
          if (seq !== pvSwap.seq) return;
          try {
            var w = nx.contentWindow, d = nx.contentDocument;
            var h = d.documentElement.scrollHeight, now = Date.now();
            if (h !== lastH) { lastH = h; stableSince = now; }
            if (now - start < 1500 && (now - stableSince < 180 || y > h - w.innerHeight)) { setTimeout(settle, 50); return; }
            scrollToAnchor(nx, anchor, y);
          } catch (e) {}
          requestAnimationFrame(function () {
            if (seq !== pvSwap.seq) return;
            var live = document.getElementById('livePreview') || cur;
            nx.style.cssText = live.style.cssText;
            ['data-want', 'data-want-until'].forEach(function (a) { if (live.hasAttribute(a)) nx.setAttribute(a, live.getAttribute(a)); });
            live.removeAttribute('id');
            nx.id = 'livePreview';
            if (live.parentNode) live.parentNode.removeChild(live);
            pvSwap.next = null;
            keepPreviewChoices(nx);
            // put the instant-only changes (uploaded background, logo size…) on the new page too
            try { applyLivePreview(); } catch (e) {}
            // parts of the page (today's specials, combos…) fill in a moment later: keep the
            // same section in view for a little while, unless you scroll the preview yourself
            var touched = false, until = Date.now() + 2500;
            try { ['wheel', 'touchstart', 'mousedown', 'keydown'].forEach(function (ev) { nx.contentWindow.addEventListener(ev, function () { touched = true; }, { passive: true }); }); } catch (e) {}
            (function hold() {
              if (touched || Date.now() > until || document.getElementById('livePreview') !== nx) return;
              scrollToAnchor(nx, anchor, y);
              setTimeout(hold, 120);
            })();
          });
        })();
      });
      nx.src = url;
      wrap.appendChild(nx);
    }
    window.swapPreviewTo = swapPreviewTo;
    // The part of the page at the top of the preview: the smallest element with an id that
    // crosses the top edge, and how far above the edge it starts
    function previewAnchor(doc) {
      if (!doc || !doc.body) return null;
      var best = null, bestH = Infinity;
      doc.querySelectorAll('body [id]').forEach(function (el) {
        var r = el.getBoundingClientRect();
        if (r.height > 40 && r.top <= 8 && r.bottom > 8 && r.height < bestH) { best = el; bestH = r.height; }
      });
      return best ? { id: best.id, off: best.getBoundingClientRect().top } : null;
    }
    function scrollToAnchor(frame, anchor, y) {
      var w = frame.contentWindow, d = frame.contentDocument;
      var el = anchor && d.getElementById(anchor.id);
      var target = el ? w.scrollY + el.getBoundingClientRect().top - anchor.off : y;
      target = Math.max(0, Math.min(target, d.documentElement.scrollHeight - w.innerHeight));
      if (Math.abs(w.scrollY - target) > 1) w.scrollTo(0, target);
    }
    window.setPreviewMode = function(mode) {
      window.previewMode = mode === 'live' ? 'live' : 'current';
      var ind = document.getElementById('previewModeInd');
      if (ind) ind.style.transform = window.previewMode === 'live' ? 'translateX(100%)' : 'none';
      document.querySelectorAll('.pv-mode-btn').forEach(function(b) {
        b.style.color = b.getAttribute('data-pv') === window.previewMode ? (window.previewMode === 'live' ? '#15803d' : '#7c3aed') : '#6b7280';
      });
      reloadPreviewForMode();
    };
    window.previewMode = window.previewMode || 'current';
    var previewReloadTimer = 0;
    function schedulePreviewReload() {
      if (window.previewMode === 'live') return;
      clearTimeout(previewReloadTimer);
      previewReloadTimer = setTimeout(reloadPreviewForMode, 250);
    }
    window.schedulePreviewReload = schedulePreviewReload;
    // Typing (name, bottom bar labels…) refreshes the preview once you pause
    var previewTypeTimer = 0;
    document.addEventListener('input', function(e) {
      if (!e.target.closest || !e.target.closest('#websiteThemePage') || e.target.type !== 'text') return;
      if (window.previewMode === 'live') return;
      showPreviewPageFor(e.target);
      clearTimeout(previewTypeTimer);
      previewTypeTimer = setTimeout(reloadPreviewForMode, 500);
    });
    // Show the page a setting is for: Menu page settings open the menu, Home page ones the home
    // page, Profile Page options the profile (with a sample customer); the rest keep the page
    function showPreviewPageFor(el) {
      var frame = document.getElementById('livePreview');
      if (!frame || !el || !el.closest) return;
      var home = (frame.getAttribute('data-base') || frame.src).split('?')[0].replace(/\/$/, '');
      var href = '';
      try { href = frame.contentWindow.location.href.split('?')[0].replace(/\/$/, ''); } catch (err) {}
      var want = null;
      if (el.closest('#profileDesignBox')) want = home + '/profile?preview_demo=1';
      else if (el.closest('#wtg-menu')) want = /\/menu$/.test(href) ? null : home + '/menu';
      else if (el.closest('#wtg-home') || /\/profile$/.test(href)) want = href === home ? null : home;
      if (!want) return;
      frame.setAttribute('data-want', want);
      frame.setAttribute('data-want-until', Date.now() + 3000);
      if (window.previewMode === 'live') window.setPreviewMode('current');
    }
    // Any layout choice in Website Appearance refreshes the Current preview
    document.addEventListener('click', function(e) {
      var opt = e.target.closest && e.target.closest('#websiteThemePage label[class$="-opt"], #websiteThemePage label[class*="-opt "], #websiteThemePage input[type="radio"]');
      if (!opt) return;
      showPreviewPageFor(opt);
      schedulePreviewReload();
    });
    document.addEventListener('change', function(e) {
      if (e.target.closest && e.target.closest('#websiteThemePage') && (e.target.tagName === 'SELECT' || e.target.type === 'radio' || e.target.type === 'color' || e.target.type === 'checkbox')) { showPreviewPageFor(e.target); schedulePreviewReload(); }
    });
    // Keep the unsaved choices while clicking around inside the Current preview
    function keepPreviewChoices(frame) {
      frame.addEventListener('load', function() {
        if (window.previewMode === 'live') return;
        try {
          var href = frame.contentWindow.location.href;
          if (href.indexOf('http') === 0 && href.indexOf('preview_theme=') === -1) frame.contentWindow.location.replace(previewUrlFor(href));
        } catch (e) {}
      });
    }
    (function() {
      var frame = document.getElementById('livePreview');
      if (!frame) return;
      if (!frame.getAttribute('data-base')) frame.setAttribute('data-base', frame.getAttribute('src'));
      keepPreviewChoices(frame);
      setTimeout(function() { previewSaved = JSON.stringify(collectPreviewTheme()); window.setPreviewMode(window.previewMode); }, 1500);
    })();
    window.markPreviewSaved = function() { previewSaved = JSON.stringify(collectPreviewTheme()); if (window.setThemeBaseline) window.setThemeBaseline(); };

    // ── Live preview on a phone: a private link (QR); the unsaved choices are sent
    // whenever they change, and the phone reloads itself (website/theme_live.php) ──
    var lp = { token: null, sent: '', timer: 0, busy: false };
    function lpApi(body) {
      return fetch('../api/theme_live_preview.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function(r) { return r.json(); });
    }
    function lpPhoneUrl() {
      var frame = document.getElementById('livePreview');
      var base = frame ? (frame.getAttribute('data-base') || frame.src).split('?')[0] : location.origin;
      return base + '?live_preview=' + lp.token;
    }
    function lpPush() {
      if (!lp.token || lp.busy) return;
      var now = JSON.stringify(collectPreviewTheme());
      if (now === lp.sent) return;
      lp.busy = true;
      lpApi({ action: 'push', token: lp.token, theme: JSON.parse(now) }).then(function(d) {
        lp.busy = false;
        if (d && d.success) { lp.sent = now; return; }
        if (d && d.ended) { lpEnded(); }
      }).catch(function() { lp.busy = false; });
    }
    function lpEnded() {
      clearInterval(lp.timer); lp.token = null;
      var s = document.getElementById('lpStatus'), dot = document.getElementById('lpDot');
      if (s) s.textContent = 'Preview ended. Click "Phone" to start again';
      if (dot) dot.style.background = '#9ca3af';
    }
    window.toggleLivePhone = function() {
      var panel = document.getElementById('lpPanel');
      if (lp.token) { panel.style.display = panel.style.display === 'none' ? '' : 'none'; return; }
      var btn = document.getElementById('lpBtn');
      btn.disabled = true;
      var theme = collectPreviewTheme();
      lpApi({ action: 'start', theme: theme }).then(function(d) {
        btn.disabled = false;
        if (!d || !d.success) { if (typeof showNotification === 'function') showNotification((d && d.message) || 'Could not start the phone preview', 'error'); return; }
        lp.token = d.token; lp.sent = JSON.stringify(theme);
        var url = lpPhoneUrl();
        document.getElementById('lpLink').value = url;
        document.getElementById('lpQr').src = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&margin=0&data=' + encodeURIComponent(url);
        document.getElementById('lpLocalHint').style.display = /\/\/(localhost|127\.0\.0\.1)[:/]/.test(url) ? '' : 'none';
        document.getElementById('lpStatus').textContent = 'Live on your phone';
        document.getElementById('lpDot').style.background = '#22c55e';
        panel.style.display = '';
        clearInterval(lp.timer);
        lp.timer = setInterval(lpPush, 900);
      }).catch(function() { btn.disabled = false; });
    };
    window.copyLivePhoneLink = function() {
      var el = document.getElementById('lpLink');
      (navigator.clipboard ? navigator.clipboard.writeText(el.value) : Promise.reject()).then(function() {
        if (typeof showNotification === 'function') showNotification('Link copied', 'success');
      }, function() { el.select(); document.execCommand('copy'); });
    };
    window.stopLivePhone = function() {
      if (lp.token) lpApi({ action: 'stop', token: lp.token });
      clearInterval(lp.timer); lp.token = null;
      document.getElementById('lpPanel').style.display = 'none';
    };

    function currentMenuCardStyleVal() {
      var r = document.querySelector('input[name="menuCardStyle"]:checked');
      return r ? r.value : 'classic';
    }
    document.querySelectorAll('.menu-card-style-opt').forEach(function(el) {
      el.addEventListener('click', function() { applyMenuCardStyleUI(el.getAttribute('data-value')); });
    });
    function currentMenuNavPositionVal() {
      var r = document.querySelector('input[name="menuNavPosition"]:checked');
      return r ? r.value : 'left';
    }
    document.querySelectorAll('.menu-nav-pos-opt').forEach(function(el) {
      el.addEventListener('click', function() { applyMenuNavPositionUI(el.getAttribute('data-value')); });
    });
    function currentLayoutStyleVal() {
      var r = document.querySelector('input[name="layoutStyle"]:checked');
      return r ? r.value : 'grid';
    }
    function currentHeaderStyleVal() {
      var r = document.querySelector('input[name="headerStyle"]:checked');
      return r ? r.value : 'hero';
    }
    document.querySelectorAll('.layout-style-opt').forEach(function(el) {
      el.addEventListener('click', function() {
        applyLayoutHeaderStyleUI(el.getAttribute('data-value'), currentHeaderStyleVal());
        applyLivePreview();
      });
    });
    document.querySelectorAll('.header-style-opt').forEach(function(el) {
      el.addEventListener('click', function() {
        applyLayoutHeaderStyleUI(currentLayoutStyleVal(), el.getAttribute('data-value'));
        applyLivePreview();
      });
    });
    const bannerUpload = document.getElementById('bannerUpload');
    const uploadBannerBtn = document.getElementById('uploadBannerBtn');

    const FONT_STACKS = {
      'Poppins': "'Poppins', sans-serif",
      'Playfair Display': "'Playfair Display', serif",
      'Roboto': "'Roboto', sans-serif",
      'Montserrat': "'Montserrat', sans-serif",
      'Nunito': "'Nunito', sans-serif",
      'Lora': "'Lora', serif"
    };
    const DEFAULT_THEME = {
      primary_red: '#F70000',
      primary_yellow: '#FFD100',
      font_family: 'Poppins',
      layout_columns: 2,
      logo_shape: 'circle',
      logo_size: 90,
      background_theme: '',
      card_style: 'rounded',
      checkout_color: '#F70000'
    };

    // Mirrors THEME_PRESETS in main/config/website_theme_helpers.php — kept here
    // too so picking a preset updates the form instantly, no round trip needed.
    const THEME_PRESETS = {
      classic: { label: 'Classic Red', primary_red: '#F70000', dark_red: '#DA020E', primary_yellow: '#FFD100', font_family: 'Poppins', card_style: 'rounded', layout_style: 'grid', header_style: 'hero' },
      midnight: { label: 'Midnight', primary_red: '#16213E', dark_red: '#0F1730', primary_yellow: '#00C2A8', font_family: 'Montserrat', card_style: 'sharp', layout_style: 'list', header_style: 'minimal' },
      sunset: { label: 'Sunset', primary_red: '#FF6B35', dark_red: '#C2410C', primary_yellow: '#FFC145', font_family: 'Nunito', card_style: 'pill', layout_style: 'magazine', header_style: 'hero' },
      emerald: { label: 'Emerald', primary_red: '#1B5E3D', dark_red: '#0F3D28', primary_yellow: '#F4E1C1', font_family: 'Playfair Display', card_style: 'rounded', layout_style: 'list', header_style: 'hero' },
      ocean: { label: 'Ocean', primary_red: '#0B6E99', dark_red: '#08526F', primary_yellow: '#FF7E5F', font_family: 'Roboto', card_style: 'rounded', layout_style: 'magazine', header_style: 'minimal' }
    };

    // Mirrors NAV_ICON_STYLES in main/config/website_theme_helpers.php — Font
    // Awesome icon suffixes for the bottom nav's Home/Menu/Social/Plans/Cart/Profile icons.
    const NAV_ICON_STYLES = {
      classic: { label: 'Classic', home: 'home', menu: 'utensils', social: 'share-alt', plans: 'calendar-check', cart: 'shopping-cart', profile: 'user' },
      storefront: { label: 'Storefront', home: 'store', menu: 'utensils', social: 'comments', plans: 'calendar-check', cart: 'shopping-bag', profile: 'user-circle' },
      foodie: { label: 'Foodie', home: 'home', menu: 'pizza-slice', social: 'share-alt', plans: 'calendar-check', cart: 'cart-plus', profile: 'user' },
      minimal: { label: 'Minimal', home: 'house', menu: 'list', social: 'comment-dots', plans: 'calendar-check', cart: 'bag-shopping', profile: 'circle-user' },
      bold: { label: 'Bold', home: 'home', menu: 'bowl-food', social: 'share-nodes', plans: 'calendar-days', cart: 'cart-shopping', profile: 'user-circle' },
      lively: { label: 'Lively', svg: 'line', home: 'home', menu: 'bowl-food', social: 'heart', plans: 'calendar-check', cart: 'cart-shopping', profile: 'face-smile' },
      cartoon: { label: 'Cartoon', svg: 'flat', home: 'home', menu: 'bowl-food', social: 'heart', plans: 'calendar-check', cart: 'cart-shopping', profile: 'face-smile' },
      cozy: { label: 'Cozy', svg: 'cozy', home: 'home', menu: 'utensils', social: 'heart', plans: 'calendar-check', cart: 'cart-shopping', profile: 'face-smile' },
      bistro: { label: 'Bistro', svg: 'bistro', home: 'home', menu: 'utensils', social: 'heart', plans: 'calendar-check', cart: 'cart-shopping', profile: 'face-smile' },
      cafe: { label: 'Caf\u00e9', svg: 'cafe', home: 'home', menu: 'utensils', social: 'heart', plans: 'calendar-check', cart: 'cart-shopping', profile: 'face-smile' },
      fresh: { label: 'Fresh', svg: 'fresh', home: 'home', menu: 'utensils', social: 'heart', plans: 'calendar-check', cart: 'cart-shopping', profile: 'face-smile' },
      fiesta: { label: 'Fiesta', svg: 'fiesta', home: 'home', menu: 'utensils', social: 'heart', plans: 'calendar-check', cart: 'cart-shopping', profile: 'face-smile' }
    };
    // Picker tiles for the drawn animated icons
    function nisIconHtml(st, slot) {
      if (st.svg) return (window.NAV_ANIM_SVGS && window.NAV_ANIM_SVGS[st.svg] && window.NAV_ANIM_SVGS[st.svg][slot]) || '';
      return "<i class='fa fa-" + st[slot] + "' style='font-size:14px;color:#6b7280;'></i>";
    }
    const NAV_ICON_SLOTS = ['home', 'menu', 'social', 'cart', 'profile'];

    // Mirrors DEFAULT_NAV_LABELS in main/config/website_theme_helpers.php
    const DEFAULT_NAV_LABELS = { home: 'Home', menu: 'Menu', social: 'Social', plans: 'Plans', reservations: 'Reserve', cart: 'Cart', profile: 'Profile' };
    const NAV_LABEL_SLOTS = ['home', 'menu', 'social', 'plans', 'reservations', 'cart', 'profile'];
    function navLabelInputEl(slot) {
      return document.getElementById('navLabel' + slot.charAt(0).toUpperCase() + slot.slice(1) + 'Input');
    }

    function renderNavIconStyleGrid(selectedId) {
      var grid = document.getElementById('navIconStyleGrid');
      if (!grid) return;
      grid.innerHTML = Object.keys(NAV_ICON_STYLES).map(function(id) {
        var st = NAV_ICON_STYLES[id];
        var isSelected = id === selectedId;
        var borderColor = isSelected ? '#dc2626' : '#e5e7eb';
        var boxShadow = isSelected ? '0 0 0 2px #dc2626' : '0 2px 4px rgba(0,0,0,0.06)';
        var icons = NAV_ICON_SLOTS.map(function(slot) { return nisIconHtml(st, slot); }).join('');
        return "<div data-nav-icon='" + id + "' class='nis-tile" + (isSelected ? ' on' : '') + "' style='cursor:pointer;text-align:center;padding:10px 6px;border-radius:10px;border:3px solid " + borderColor + ";background:#fff;box-shadow:" + boxShadow + ";transition:all 0.2s;'>" +
          "<div class='nis-ic' style='display:flex;justify-content:center;gap:6px;margin-bottom:6px;'>" + icons + "</div>" +
          "<div style='font-size:11px;font-weight:600;color:#374151;'>" + st.label + (st.svg ? "<span class='nis-badge'>Animated</span>" : '') + "</div>" +
        "</div>";
      }).join('');
      grid.onclick = function(e) {
        var target = e.target.closest('[data-nav-icon]');
        if (target) window.selectNavIconStyle(target.getAttribute('data-nav-icon'));
      };
    }
    renderNavIconStyleGrid('classic');

    window.selectNavIconStyle = function(id) {
      var input = document.getElementById('navIconStyleInput');
      if (input) input.value = id || 'classic';
      renderNavIconStyleGrid(id || 'classic');
      applyLivePreview();
      if (window.schedulePreviewReload) window.schedulePreviewReload();
    };

    // Per-slot custom nav icon uploads — overrides the picked icon style above
    // for just that one slot. navIconOverridesCache mirrors what's saved server-side
    // (nav_icons_custom), populated on load and kept in sync after each upload/remove.
    var navIconOverridesCache = {};

    function currentRestaurantId() {
      var meta = document.querySelector('meta[name=restaurant-id]');
      return meta ? meta.content : (document.getElementById('restaurantId') ? document.getElementById('restaurantId').textContent.trim() : '');
    }

    // Bottom Navigation rows: fills each item's icon thumb + Upload/Remove next to its label box
    function renderNavIconUploadGrid() {
      var grid = document.getElementById('navIconUploadGrid');
      if (!grid) return;
      NAV_LABEL_SLOTS.forEach(function(slot) {
        var thumb = grid.querySelector('[data-nav-icon-thumb="' + slot + '"]');
        var actions = grid.querySelector('[data-nav-icon-actions="' + slot + '"]');
        if (!thumb || !actions) return;
        var url = navIconOverridesCache[slot];
        thumb.innerHTML = url
          ? "<img src='" + url + "' alt='' style='width:30px;height:30px;object-fit:contain;border-radius:8px;border:1px solid #e5e7eb;background:#fff;'>"
          : "<span title='Uses the icon style' style='width:30px;height:30px;border-radius:8px;border:1px dashed #d1d5db;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:12px;'>&mdash;</span>";
        actions.innerHTML =
          "<input type='file' accept='image/png,image/jpeg,image/gif,image/webp,image/svg+xml' data-nav-icon-upload-slot='" + slot + "' style='display:none;'>" +
          "<button type='button' data-nav-icon-upload-trigger='" + slot + "' title='Upload your own icon' style='font-size:11px;padding:5px 9px;border-radius:7px;border:1px solid #d1d5db;background:#fff;cursor:pointer;'>" + (url ? 'Change' : 'Upload') + "</button>" +
          (url ? "<button type='button' data-nav-icon-remove='" + slot + "' title='Remove custom icon' style='font-size:11px;padding:5px 7px;border-radius:7px;border:1px solid #fecaca;background:#fef2f2;color:#dc2626;cursor:pointer;'>&times;</button>" : '');
        var input = actions.querySelector('input[type=file]');
        actions.querySelector('[data-nav-icon-upload-trigger]').addEventListener('click', function() { input.click(); });
        input.addEventListener('change', function() { if (input.files && input.files[0]) uploadNavIcon(slot, input.files[0]); });
        var rm = actions.querySelector('[data-nav-icon-remove]');
        if (rm) rm.addEventListener('click', function() { removeNavIcon(slot); });
      });
    }
    async function uploadNavIcon(slot, file) {
      if (file.size > 1 * 1024 * 1024) {
        showToastFallback('Icon file too large (max 1MB).', 'error');
        return;
      }
      var formData = new FormData();
      formData.append('icon_image', file);
      formData.append('slot', slot);
      var sq = '?action=upload_nav_icon&restaurant_id=' + encodeURIComponent(currentRestaurantId() || 'RES001');
      try {
        var res = await fetch('../website/theme_api.php' + sq, { method: 'POST', body: formData });
        var data = await res.json();
        if (data.success) {
          navIconOverridesCache[slot] = data.icon_url;
          renderNavIconUploadGrid();
          applyLivePreview();
          showToastFallback('Icon uploaded & saved!', 'success');
        } else {
          showToastFallback('Upload failed: ' + (data.message || 'Unknown error'), 'error');
        }
      } catch (e) {
        showToastFallback('Upload error: ' + e.message, 'error');
      }
    }

    async function removeNavIcon(slot) {
      var sq = '?action=remove_nav_icon&restaurant_id=' + encodeURIComponent(currentRestaurantId() || 'RES001');
      try {
        var res = await fetch('../website/theme_api.php' + sq, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ slot: slot }) });
        var data = await res.json();
        if (data.success) {
          delete navIconOverridesCache[slot];
          renderNavIconUploadGrid();
          applyLivePreview();
          showToastFallback('Custom icon removed.', 'success');
        } else {
          showToastFallback('Failed to remove icon: ' + (data.message || 'Unknown error'), 'error');
        }
      } catch (e) {
        showToastFallback('Error: ' + e.message, 'error');
      }
    }

    renderNavIconUploadGrid();

    const siteNameInputEl = document.getElementById('siteNameInput');
    if (siteNameInputEl) siteNameInputEl.addEventListener('input', applyLivePreview);

    NAV_LABEL_SLOTS.forEach(function(slot) {
      var el = navLabelInputEl(slot);
      if (el) el.addEventListener('input', applyLivePreview);
    });

    function updateFaviconPreview() {
      var input = document.getElementById('faviconUrlInput');
      var img = document.getElementById('faviconPreviewImg');
      if (!input || !img) return;
      var url = input.value.trim();
      if (url) {
        img.src = url;
        img.style.display = 'inline-block';
      } else {
        img.style.display = 'none';
      }
    }
    const faviconUrlInputEl = document.getElementById('faviconUrlInput');
    if (faviconUrlInputEl) faviconUrlInputEl.addEventListener('input', updateFaviconPreview);

    function renderThemePresetGrid(selectedId) {
      var grid = document.getElementById('themePresetGrid');
      if (!grid) return;
      grid.innerHTML = Object.keys(THEME_PRESETS).map(function(id) {
        var t = THEME_PRESETS[id];
        var isSelected = id === selectedId;
        var radiusPreview = t.card_style === 'pill' ? '999px' : (t.card_style === 'sharp' ? '4px' : '10px');
        var borderColor = isSelected ? '#7c3aed' : '#e5e7eb';
        var boxShadow = isSelected ? '0 0 0 2px #7c3aed' : '0 2px 4px rgba(0,0,0,0.06)';
        return "<div data-preset='" + id + "' style='cursor:pointer;border-radius:10px;overflow:hidden;border:3px solid " + borderColor + ";transition:all 0.2s;box-shadow:" + boxShadow + ";background:#fff;'>" +
          "<div style='height:50px;background:linear-gradient(135deg," + t.primary_red + "," + t.dark_red + ");display:flex;align-items:center;justify-content:center;'>" +
            "<span style='display:inline-block;padding:4px 10px;background:" + t.primary_yellow + ";border-radius:" + radiusPreview + ";font-size:10px;font-weight:700;color:#1a1a1a;'>Aa</span>" +
          "</div>" +
          "<div style='padding:6px 8px;font-size:11px;font-weight:600;color:#374151;text-align:center;'>" + t.label + "</div>" +
        "</div>";
      }).join('');
      grid.onclick = function(e) {
        var target = e.target.closest('[data-preset]');
        if (target) window.applyThemePreset(target.getAttribute('data-preset'));
      };
    }
    renderThemePresetGrid('');

    // Darken a #RRGGBB hex color by a percentage (used to auto-derive the gradient shade from the Primary Color)
    function shadeColor(hex, percent) {
      hex = (hex || '#F70000').replace('#', '');
      if (hex.length === 3) hex = hex.split('').map(function(c){ return c + c; }).join('');
      var num = parseInt(hex, 16);
      if (isNaN(num)) return '#DA020E';
      var amt = Math.round(2.55 * percent);
      var r = Math.max(Math.min(((num >> 16) & 0xFF) + amt, 255), 0);
      var g = Math.max(Math.min(((num >> 8) & 0xFF) + amt, 255), 0);
      var b = Math.max(Math.min((num & 0xFF) + amt, 255), 0);
      return '#' + (0x1000000 + r * 0x10000 + g * 0x100 + b).toString(16).slice(1).toUpperCase();
    }
    function isValidHex(v) { return /^#[0-9A-Fa-f]{6}$/.test(v); }

    // Apply the currently selected (not-yet-saved) settings directly into the same-origin
    // live preview iframe, so the preview updates instantly without Save + Refresh.
    function applyLivePreview() {
      if (window.previewMode === 'live') return; // Live = the saved website, untouched
      var frame = document.getElementById('livePreview');
      if (!frame) return;
      try {
        var doc = frame.contentDocument;
        if (!doc || !doc.documentElement) return;
        var root = doc.documentElement;
        var primaryVal = pr ? pr.value : DEFAULT_THEME.primary_red;
        var darkVal = dr ? dr.value : shadeColor(primaryVal, -12);
        var accentVal = py ? py.value : DEFAULT_THEME.primary_yellow;
        root.style.setProperty('--primary-red', primaryVal);
        root.style.setProperty('--dark-red', darkVal);
        root.style.setProperty('--primary-yellow', accentVal);

        var checkoutVal = cc ? cc.value : DEFAULT_THEME.checkout_color;
        root.style.setProperty('--checkout-color', checkoutVal);
        root.style.setProperty('--checkout-color-dark', shadeColor(checkoutVal, -12));

        var cardStyleEl = document.getElementById('cardStyleInput');
        var cardStyleVal = cardStyleEl ? cardStyleEl.value : 'rounded';
        var radii = cardStyleVal === 'pill' ? { card: '20px', btn: '999px' } : (cardStyleVal === 'sharp' ? { card: '4px', btn: '4px' } : { card: '16px', btn: '12px' });
        root.style.setProperty('--card-radius', radii.card);
        root.style.setProperty('--btn-radius', radii.btn);

        // Bestsellers colours (ADD / price / badge / title) and the category heading colour
        var bsVars = { bsAddColor: '--bsl-add', bsPriceColor: '--bsl-price', bsBadgeColor: '--bsl-badge', bsTitleColor: '--bsl-title' };
        Object.keys(bsVars).forEach(function (id) {
          var el = document.getElementById(id);
          if (el && /^#[0-9a-f]{6}$/i.test(el.value)) root.style.setProperty(bsVars[id], el.value);
        });
        var chb = typeof currentCategoryHeadingVal === 'function' ? currentCategoryHeadingVal() : undefined;
        if (chb !== undefined) {
          // Automatic = light shade of the ADD colour (overrides any saved custom colour in the preview)
          root.style.setProperty('--cat-heading-bg', chb || 'color-mix(in srgb, var(--bsl-add) 12%, #fff)');
        }

        // Header style: toggle the compact "minimal" layout on the preview's home section
        var previewHomeSection = doc.getElementById('homeSection');
        if (previewHomeSection && typeof currentHeaderStyleVal === 'function') {
          previewHomeSection.classList.toggle('header-minimal', currentHeaderStyleVal() === 'minimal');
        }

        var fontName = fontSelect ? fontSelect.value : DEFAULT_THEME.font_family;
        root.style.setProperty('--site-font', FONT_STACKS[fontName] || FONT_STACKS['Poppins']);
        // Make sure the chosen font is actually loaded inside the iframe for an accurate preview
        var fontLinkId = 'livePreviewFontLink';
        var existing = doc.getElementById(fontLinkId);
        var googleParam = fontName.replace(/ /g, '+');
        var href = 'https://fonts.googleapis.com/css2?family=' + googleParam + ':wght@300;400;500;600;700&display=swap';
        if (!existing) {
          var link = doc.createElement('link');
          link.id = fontLinkId;
          link.rel = 'stylesheet';
          link.href = href;
          doc.head.appendChild(link);
        } else if (existing.href !== href) {
          existing.href = href;
        }

        // Background theme
        var bgInput = document.getElementById('backgroundThemeInput');
        var bgUrl = bgInput ? bgInput.value : '';
        var homeSection = doc.getElementById('homeSection');
        if (homeSection) {
          homeSection.style.backgroundImage = bgUrl ? "url('" + bgUrl + "')" : 'none';
          if (!bgUrl) homeSection.style.background = 'linear-gradient(135deg, #2d3436, #636e72)';
        }

        // Logo shape & size
        var logoWrap = doc.querySelector('.profile-img-wrap');
        if (logoWrap) {
          var shapeRadios = document.querySelectorAll('input[name="logoShape"]');
          var logoShape = 'circle';
          for (var si = 0; si < shapeRadios.length; si++) { if (shapeRadios[si].checked) { logoShape = shapeRadios[si].value; break; } }
          logoWrap.classList.toggle('logo-square', logoShape === 'square');
          var sizeSliderEl = document.getElementById('logoSizeSlider');
          var logoSize = sizeSliderEl ? parseInt(sizeSliderEl.value) : 90;
          logoWrap.style.width = logoSize + 'px';
          logoWrap.style.height = logoSize + 'px';
        }

        // Site name
        var siteNameEl = document.getElementById('siteNameInput');
        var displayName = (siteNameEl && siteNameEl.value.trim()) ? siteNameEl.value.trim() : (window.restaurantName || '');
        var nameSpan = doc.querySelector('.rest-name-text');
        if (nameSpan && displayName) nameSpan.textContent = displayName;
        if (displayName) doc.title = displayName;

        // Bottom nav icon style (+ per-slot custom icon upload overrides)
        var navIconStyleEl = document.getElementById('navIconStyleInput');
        var navStyleId = (navIconStyleEl && NAV_ICON_STYLES[navIconStyleEl.value]) ? navIconStyleEl.value : 'classic';
        var navStyle = NAV_ICON_STYLES[navStyleId];
        doc.querySelectorAll('[data-nav-slot]').forEach(function(el) {
          // Drawn animated icons are swapped by the preview's reload, not here
          if (navStyle.svg || el.tagName.toLowerCase() === 'svg') return;
          var slot = el.getAttribute('data-nav-slot');
          var overrideUrl = (typeof navIconOverridesCache !== 'undefined') ? navIconOverridesCache[slot] : null;
          var navItemLayout = slot !== 'profile';
          var size = navItemLayout ? 24 : 16;
          if (overrideUrl) {
            if (el.tagName === 'IMG') {
              if (el.src !== overrideUrl) el.src = overrideUrl;
            } else {
              var img = doc.createElement('img');
              img.src = overrideUrl;
              img.alt = '';
              img.setAttribute('data-nav-slot', slot);
              if (navItemLayout) img.className = 'nav-icon';
              img.style.cssText = 'width:' + size + 'px;height:' + size + 'px;object-fit:contain;' + (navItemLayout ? 'display:block;margin:0 auto;' : 'display:inline-block;vertical-align:middle;');
              el.parentNode.replaceChild(img, el);
            }
          } else {
            var iconName = navStyle[slot] || (slot === 'reservations' ? 'calendar-check' : null);
            if (!iconName) return;
            if (el.tagName === 'IMG') {
              var icon = doc.createElement('i');
              icon.className = 'fa fa-' + iconName + (navItemLayout ? ' nav-icon' : '');
              icon.setAttribute('data-nav-slot', slot);
              el.parentNode.replaceChild(icon, el);
            } else {
              Array.from(el.classList).forEach(function(cls) {
                if (cls.indexOf('fa-') === 0 && cls !== 'fa-') el.classList.remove(cls);
              });
              el.classList.add('fa-' + iconName);
            }
          }
        });

        // Bottom nav text labels
        NAV_LABEL_SLOTS.forEach(function(slot) {
          var inputEl = navLabelInputEl(slot);
          var labelVal = (inputEl && inputEl.value.trim()) ? inputEl.value.trim() : DEFAULT_NAV_LABELS[slot];
          var labelSpan = doc.querySelector('[data-nav-label="' + slot + '"]');
          if (labelSpan) labelSpan.textContent = labelVal;
          var profileBtn = doc.querySelector('[data-nav-slot="profile"]');
          if (slot === 'profile' && profileBtn && profileBtn.parentElement) profileBtn.parentElement.title = labelVal;
        });
      } catch (err) {
        // Cross-origin or not-yet-loaded iframe - fail silently, preview just won't be live this tick
      }
    }
    
    // Function to render banners grid
    const renderBanners = (banners) => {
      // Story header photos are managed under Header Style, not in this slideshow list
      banners = (banners || []).filter(b => b.placement !== 'story');
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
          <div style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#fff;border-top:1px solid #eee;" draggable="false">
            <span class="material-symbols-rounded" style="font-size:18px;color:#6b7280;">place</span>
            <select class="banner-placement" data-id="${banner.id}" draggable="false" title="Where this banner shows on your website" style="flex:1;padding:6px 8px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:0.85rem;cursor:pointer;">
              ${[['home_top','Home page — top'],['home_menu','Home page — above the menu'],['menu_top','Menu page — above the dishes'],['cart','Cart page — top']].map(([v,l]) => `<option value="${v}"${(banner.placement || 'home_top') === v ? ' selected' : ''}>${l}</option>`).join('')}
            </select>
            <span style="font-size:0.8rem;color:#9ca3af;white-space:nowrap;">#${banner.display_order !== null && banner.display_order !== undefined ? banner.display_order + 1 : index + 1}</span>
          </div>
        `;
        bannersGrid.appendChild(bannerCard);
      });
      
      // Where each banner shows: saved straight away
      document.querySelectorAll('.banner-placement').forEach(sel => {
        ['mousedown', 'click', 'dragstart'].forEach(ev => sel.addEventListener(ev, e => e.stopPropagation()));
        sel.addEventListener('change', async () => {
          const fd = new FormData();
          fd.append('banner_id', sel.getAttribute('data-id'));
          fd.append('placement', sel.value);
          const sq = rid ? `?action=set_banner_placement&restaurant_id=${encodeURIComponent(rid)}` : '?action=set_banner_placement';
          try {
            const res = await fetch(`../website/theme_api.php${sq}`, { method: 'POST', body: fd });
            const data = await res.json();
            showNotification(data.success ? 'Banner placement saved' : (data.message || 'Could not save placement'), data.success ? 'success' : 'error');
            if (data.success && window.refreshPreview) window.refreshPreview();
          } catch (err) { showNotification('Network error. Please try again.', 'error'); }
        });
      });

      // Add delete button listeners
      document.querySelectorAll('.delete-banner-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
          e.stopPropagation();
          // keep the button now: after the confirm popup e.currentTarget is null
          const delBtn = e.currentTarget;
          const bannerId = delBtn.getAttribute('data-id');
          if (!(await showSweetConfirm('Are you sure you want to delete this banner?', 'Delete Banner'))) return;
          
          try {
            delBtn.disabled = true;
            const sq = rid ? `?action=delete_banner&banner_id=${bannerId}&restaurant_id=${encodeURIComponent(rid)}` : `?action=delete_banner&banner_id=${bannerId}`;
            const formData = new FormData();
            formData.append('banner_id', bannerId);
            const res = await fetch(`../website/theme_api.php${sq}`, { method:'POST', body: formData });
            const data = await res.json();
            
            if (data.success) {
              showNotification('Banner deleted successfully', 'success');
              loadBanners();
              if (window.refreshPreview) window.refreshPreview();
            } else {
              delBtn.disabled = false;
              showNotification(data.message || 'Failed to delete banner', 'error');
            }
          } catch (err) {
            delBtn.disabled = false;
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
    
    // Function to update color previews (mini preview + hex fields + gradient shade + live iframe)
    const updateColorPreviews = () => {
      const primaryRedVal = (pr && isValidHex(pr.value)) ? pr.value : DEFAULT_THEME.primary_red;
      const primaryYellowVal = (py && isValidHex(py.value)) ? py.value : DEFAULT_THEME.primary_yellow;
      const darkRedVal = shadeColor(primaryRedVal, -12);
      if (dr) dr.value = darkRedVal;

      // Keep hex text fields in sync with the color swatches
      if (prHex && prHex.value.toUpperCase() !== primaryRedVal.toUpperCase()) prHex.value = primaryRedVal.toUpperCase();
      if (pyHex && pyHex.value.toUpperCase() !== primaryYellowVal.toUpperCase()) pyHex.value = primaryYellowVal.toUpperCase();

      // Update mini preview inside the dashboard
      const heroPreview = document.getElementById('heroPreview');
      if (heroPreview) {
        heroPreview.style.background = `linear-gradient(135deg, ${primaryRedVal} 0%, ${darkRedVal} 100%)`;
      }
      const categoryButtonPreview = document.getElementById('categoryButtonPreview');
      if (categoryButtonPreview) {
        categoryButtonPreview.style.borderColor = primaryRedVal;
        categoryButtonPreview.style.color = primaryRedVal;
      }
      // Add to Cart uses the Accent color; Checkout has its own independent color
      const addToCartPreview = document.getElementById('addToCartPreview');
      if (addToCartPreview) addToCartPreview.style.background = primaryYellowVal;
      const checkoutColorVal = (cc && isValidHex(cc.value)) ? cc.value : DEFAULT_THEME.checkout_color;
      const checkoutColorDarkVal = shadeColor(checkoutColorVal, -12);
      if (ccHex && ccHex.value.toUpperCase() !== checkoutColorVal.toUpperCase()) ccHex.value = checkoutColorVal.toUpperCase();
      const checkoutPreview = document.getElementById('checkoutPreview');
      if (checkoutPreview) checkoutPreview.style.background = `linear-gradient(135deg, ${checkoutColorVal}, ${checkoutColorDarkVal})`;
      const heroName = document.getElementById('heroPreviewName');
      if (heroName) {
        if (fontSelect) heroName.style.fontFamily = FONT_STACKS[fontSelect.value] || FONT_STACKS['Poppins'];
        const siteNameEl = document.getElementById('siteNameInput');
        const nameVal = (siteNameEl && siteNameEl.value.trim()) ? siteNameEl.value.trim() : (window.restaurantName || 'Restaurant Name');
        heroName.textContent = nameVal;
      }

      // Push the unsaved changes straight into the live preview iframe
      applyLivePreview();
    };

    window.applyThemePreset = function(id) {
      var t = THEME_PRESETS[id];
      if (!t) return;
      if (pr) pr.value = t.primary_red;
      if (py) py.value = t.primary_yellow;
      if (cc) cc.value = t.primary_red;
      if (fontSelect) fontSelect.value = t.font_family;
      var cardStyleInput = document.getElementById('cardStyleInput');
      if (cardStyleInput) cardStyleInput.value = t.card_style;
      var themePresetInput = document.getElementById('themePresetInput');
      if (themePresetInput) themePresetInput.value = id;
      applyLayoutHeaderStyleUI(t.layout_style, t.header_style);
      updateColorPreviews();
      applyLivePreview();
      renderThemePresetGrid(id);
    };

    // Two-way sync: color swatch <-> hex text field, both trigger live updates
    if (pr) pr.addEventListener('input', updateColorPreviews);
    if (py) py.addEventListener('input', updateColorPreviews);
    if (cc) cc.addEventListener('input', updateColorPreviews);
    if (ccHex) {
      ccHex.addEventListener('input', () => {
        const v = ccHex.value.trim();
        if (isValidHex(v) && cc) { cc.value = v; updateColorPreviews(); }
      });
      ccHex.addEventListener('blur', () => { ccHex.value = (cc ? cc.value : DEFAULT_THEME.checkout_color).toUpperCase(); });
    }
    if (prHex) {
      prHex.addEventListener('input', () => {
        const v = prHex.value.trim();
        if (isValidHex(v) && pr) { pr.value = v; updateColorPreviews(); }
      });
      prHex.addEventListener('blur', () => { prHex.value = (pr ? pr.value : DEFAULT_THEME.primary_red).toUpperCase(); });
    }
    if (pyHex) {
      pyHex.addEventListener('input', () => {
        const v = pyHex.value.trim();
        if (isValidHex(v) && py) { py.value = v; updateColorPreviews(); }
      });
      pyHex.addEventListener('blur', () => { pyHex.value = (py ? py.value : DEFAULT_THEME.primary_yellow).toUpperCase(); });
    }
    if (fontSelect) fontSelect.addEventListener('change', updateColorPreviews);

    // Logo shape/size and layout controls also feed the live preview (in addition to their own existing UI handlers)
    document.querySelectorAll('input[name="logoShape"]').forEach(r => r.addEventListener('change', () => setTimeout(applyLivePreview, 0)));
    const logoSizeSliderEl = document.getElementById('logoSizeSlider');
    if (logoSizeSliderEl) logoSizeSliderEl.addEventListener('input', applyLivePreview);

    // Reset to Default: restores shipped defaults in the form (Save Theme still required to persist)
    const resetBtn = document.getElementById('resetWebsiteThemeBtn');
    if (resetBtn && !resetBtn.dataset.handlerAttached) {
      resetBtn.dataset.handlerAttached = '1';
      resetBtn.addEventListener('click', async () => {
        if (!(await showSweetConfirm('Reset all appearance settings to default? This will not be saved until you click Save Theme.', 'Reset to Default'))) return;
        if (pr) pr.value = DEFAULT_THEME.primary_red;
        if (py) py.value = DEFAULT_THEME.primary_yellow;
        if (cc) cc.value = DEFAULT_THEME.checkout_color;
        if (fontSelect) fontSelect.value = DEFAULT_THEME.font_family;
        const lcRadios = document.querySelectorAll('input[name="layoutColumns"]');
        lcRadios.forEach(r => { r.checked = (parseInt(r.value) === DEFAULT_THEME.layout_columns); });
        const shapeRadios = document.querySelectorAll('input[name="logoShape"]');
        shapeRadios.forEach(r => { r.checked = (r.value === DEFAULT_THEME.logo_shape); });
        document.querySelectorAll('.logo-shape-btn').forEach(btn => {
          const active = btn.getAttribute('data-shape') === DEFAULT_THEME.logo_shape;
          btn.style.borderColor = active ? '#dc2626' : '#e5e7eb';
          btn.style.background = active ? '#dc2626' : '#fff';
          btn.style.color = active ? '#fff' : '#374151';
        });
        const sizeSliderEl = document.getElementById('logoSizeSlider');
        const sizeDisplayEl = document.getElementById('logoSizeDisplay');
        if (sizeSliderEl) {
          sizeSliderEl.value = DEFAULT_THEME.logo_size;
          sizeSliderEl.style.background = 'linear-gradient(to right, #dc2626 0%, #dc2626 40%, #e5e7eb 40%, #e5e7eb 100%)';
        }
        if (sizeDisplayEl) sizeDisplayEl.textContent = DEFAULT_THEME.logo_size;
        const bgInput = document.getElementById('backgroundThemeInput');
        if (bgInput) bgInput.value = DEFAULT_THEME.background_theme;
        if (typeof populateBgThemeGrid === 'function') populateBgThemeGrid(DEFAULT_THEME.background_theme);
        const cardStyleInputReset = document.getElementById('cardStyleInput');
        if (cardStyleInputReset) cardStyleInputReset.value = DEFAULT_THEME.card_style;
        const themePresetInputReset = document.getElementById('themePresetInput');
        if (themePresetInputReset) themePresetInputReset.value = 'classic';
        renderThemePresetGrid('classic');
        applyLayoutHeaderStyleUI('grid', 'hero');
        const siteNameResetEl = document.getElementById('siteNameInput');
        if (siteNameResetEl) siteNameResetEl.value = '';
        const navIconStyleResetEl = document.getElementById('navIconStyleInput');
        if (navIconStyleResetEl) navIconStyleResetEl.value = 'classic';
        renderNavIconStyleGrid('classic');
        NAV_LABEL_SLOTS.forEach(function(slot) {
          var el = navLabelInputEl(slot);
          if (el) el.value = '';
        });
        const faviconUrlResetEl = document.getElementById('faviconUrlInput');
        if (faviconUrlResetEl) faviconUrlResetEl.value = '';
        updateFaviconPreview();
        updateColorPreviews();
        applyLivePreview();
        showNotification('Defaults restored. Click "Save Theme" to make it permanent.', 'success');
      });
    }


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
  if (typeof applyLivePreview === 'function') {
    applyLivePreview();
  }
};

function applyLogoShapeSizeUI(shape, size) {
  var shapeBtns = document.querySelectorAll('.logo-shape-btn');
  shapeBtns.forEach(function(btn) {
    var isActive = btn.getAttribute('data-shape') === shape;
    btn.style.borderColor = isActive ? '#dc2626' : '#e5e7eb';
    btn.style.background = isActive ? '#dc2626' : '#fff';
    btn.style.color = isActive ? '#fff' : '#374151';
    var radio = btn.querySelector('input[name="logoShape"]');
    if (radio) radio.checked = isActive;
  });
  var sizeSlider = document.getElementById('logoSizeSlider');
  var sizeDisplay = document.getElementById('logoSizeDisplay');
  if (sizeSlider) {
    sizeSlider.value = size;
    sizeSlider.style.background = 'linear-gradient(to right, #dc2626 0%, #dc2626 ' + ((size - 50) / 100 * 100) + '%, #e5e7eb ' + ((size - 50) / 100 * 100) + '%, #e5e7eb 100%)';
  }
  if (sizeDisplay) sizeDisplay.textContent = size;
}

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
      // Set color picker values from saved settings (dark_red is now auto-derived, not saved separately)
      if (pr && data.settings.primary_red) pr.value = data.settings.primary_red;
      if (py && data.settings.primary_yellow) py.value = data.settings.primary_yellow;
      if (cc) cc.value = data.settings.checkout_color || data.settings.primary_red || DEFAULT_THEME.checkout_color;
      if (fontSelect && data.settings.font_family) fontSelect.value = data.settings.font_family;
      // Bestsellers section look (assets/js/bestsellers-admin.js)
      if (window.fillBestsellerStyleForm) window.fillBestsellerStyleForm(data.settings.bestseller_style);

      // Website Name & Bottom Nav Icons
      const siteNameLoadEl = document.getElementById('siteNameInput');
      if (siteNameLoadEl) siteNameLoadEl.value = data.settings.site_name || '';
      const navIconStyleLoadEl = document.getElementById('navIconStyleInput');
      if (navIconStyleLoadEl) navIconStyleLoadEl.value = data.settings.nav_icon_style || 'classic';
      renderNavIconStyleGrid(data.settings.nav_icon_style || 'classic');

      const savedNavLabels = data.settings.nav_labels || {};
      NAV_LABEL_SLOTS.forEach(function(slot) {
        var el = navLabelInputEl(slot);
        if (el) el.value = (savedNavLabels[slot] && savedNavLabels[slot] !== DEFAULT_NAV_LABELS[slot]) ? savedNavLabels[slot] : '';
      });

      const faviconUrlLoadEl = document.getElementById('faviconUrlInput');
      if (faviconUrlLoadEl) faviconUrlLoadEl.value = data.settings.favicon_url || '';
      updateFaviconPreview();

      navIconOverridesCache = data.settings.nav_icons_custom || {};
      renderNavIconUploadGrid();

      // Initialize logo shape/size UI from saved settings
      applyLogoShapeSizeUI(data.settings.logo_shape || 'circle', data.settings.logo_size || 90);

      // Initialize layout columns radios from saved settings
      const savedLayoutCols = data.settings.layout_columns || 2;
      document.querySelectorAll('input[name="layoutColumns"]').forEach(r => {
        r.checked = (parseInt(r.value) === parseInt(savedLayoutCols));
      });

      // Update color previews
      updateColorPreviews();

      // Initialize card style + theme preset selection from saved settings
      var cardStyleInput = document.getElementById('cardStyleInput');
      if (cardStyleInput) cardStyleInput.value = data.settings.card_style || 'rounded';
      var themePresetInput = document.getElementById('themePresetInput');
      if (themePresetInput) themePresetInput.value = data.settings.theme_preset || '';
      renderThemePresetGrid(data.settings.theme_preset || '');
      applyLayoutHeaderStyleUI(data.settings.layout_style || 'grid', data.settings.header_style || 'hero');
      applyMenuNavPositionUI(data.settings.menu_nav_position || 'left');
      applyMenuCardStyleUI(data.settings.menu_card_style || 'classic');
      applyHomeCardStyleUI(data.settings.home_card_style || 'classic');
      applyProfileStyleUI(data.settings.profile_style || 'midnight');
      applyNavBarStyleUI(data.settings.nav_bar_style || 'classic');
      applyProfileOptsUI(data.settings.profile_opts || {});
      applyCategoryHeadingUI(data.settings.category_heading_bg || '');
      applyCategoryPicStyleUI(data.settings.category_pic_style || 'round');
      var allPrev = document.getElementById('allCategoryIconPreview');
      if (allPrev) allPrev.src = data.settings.all_category_icon || allPrev.getAttribute('data-default');

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
      // what's saved now = the starting point for "unsaved changes" (after the form settles)
      setTimeout(function () { if (window.setThemeBaseline) window.setThemeBaseline(); }, 300);
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
        const placeSel = document.getElementById('bannerPlacementNew');
        if (placeSel) formData.append('placement', placeSel.value);
        
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
    
    // Everything the Save button sends, in one place: Save uses it, and comparing it with what
    // was last saved/loaded tells whether there are unsaved changes.
    function collectThemePayload() {
            var pr = document.getElementById('primaryRed');
            var dr = document.getElementById('darkRed');
            var py = document.getElementById('primaryYellow');
            var cc = document.getElementById('checkoutColor');
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

            var siteFontEl = document.getElementById('siteFontSelect');
            var cardStyleEl = document.getElementById('cardStyleInput');
            var themePresetEl = document.getElementById('themePresetInput');
            var siteNameSaveEl = document.getElementById('siteNameInput');
            var navIconStyleSaveEl = document.getElementById('navIconStyleInput');
            var navLabelsPayload = {};
            NAV_LABEL_SLOTS.forEach(function(slot) {
              var el = navLabelInputEl(slot);
              if (el && el.value.trim()) navLabelsPayload[slot] = el.value.trim();
            });
            var faviconUrlSaveEl = document.getElementById('faviconUrlInput');
            var payload = {
              site_name: siteNameSaveEl ? siteNameSaveEl.value.trim() : '',
              nav_icon_style: navIconStyleSaveEl ? navIconStyleSaveEl.value : 'classic',
              nav_labels: navLabelsPayload,
              favicon_url: faviconUrlSaveEl ? faviconUrlSaveEl.value.trim() : '',
              primary_red: pr ? pr.value : '#F70000',
              dark_red: dr ? dr.value : '#DA020E',
              primary_yellow: py ? py.value : '#FFD100',
              banner_image: null,
              layout_columns: lcVal,
              background_theme: bgInput ? bgInput.value : null,
              logo_shape: logoShape,
              logo_size: logoSize,
              font_family: siteFontEl ? siteFontEl.value : 'Poppins',
              card_style: cardStyleEl ? cardStyleEl.value : 'rounded',
              theme_preset: themePresetEl && themePresetEl.value ? themePresetEl.value : null,
              checkout_color: cc ? cc.value : null,
              layout_style: currentLayoutStyleVal(),
              header_style: currentHeaderStyleVal(),
              menu_nav_position: currentMenuNavPositionVal(),
              menu_card_style: currentMenuCardStyleVal(),
              home_card_style: currentHomeCardStyleVal(),
              profile_style: currentProfileStyleVal(),
              nav_bar_style: currentNavBarStyleVal(),
              profile_opts: currentProfileOpts(),
              category_heading_bg: currentCategoryHeadingVal(),
              category_pic_style: currentCategoryPicStyleVal(),
              bestseller_style: window.readBestsellerStyleForm ? window.readBestsellerStyleForm() : undefined
            };
      return { payload: payload, sq: sq };
    }

    // ── Unsaved changes: Save is greyed out until something changes; leaving asks first ──
    var themeBaseline = null;
    function themeSnapshot() {
      try { return JSON.stringify(collectThemePayload().payload); } catch (e) { return null; }
    }
    window.setThemeBaseline = function () { themeBaseline = themeSnapshot(); window.updateThemeDirty(); };
    window.themeIsDirty = function () { return themeBaseline !== null && themeSnapshot() !== themeBaseline; };
    window.updateThemeDirty = function () {
      var btn = document.getElementById('saveWebsiteThemeBtn');
      if (!btn || btn.dataset.saving === '1') return;
      var dirty = window.themeIsDirty();
      if (themeBaseline === null) { // settings didn't load: leave Save usable
        btn.disabled = false;
        btn.classList.remove('wt-clean');
        return;
      }
      btn.disabled = !dirty;
      btn.classList.toggle('wt-clean', !dirty);
      // the button itself says it: an amber dot + "Save changes", or a grey "Saved"
      btn.innerHTML = dirty ? '<i class="wt-dot" aria-hidden="true"></i>Save changes' : '<span class="material-symbols-rounded">check</span>Saved';
      btn.title = dirty ? 'You have unsaved changes' : 'Nothing to save';
      var dot = document.getElementById('pvUnsavedDot');
      if (dot) dot.style.display = dirty ? 'inline-block' : 'none';
    };
    (function () {
      var page = document.getElementById('websiteThemePage');
      if (!page || page.dataset.dirtyWatch) return;
      page.dataset.dirtyWatch = '1';
      var t = 0;
      // after the page's own handlers have run (some choices set hidden inputs on click)
      var later = function () { clearTimeout(t); t = setTimeout(window.updateThemeDirty, 60); };
      ['input', 'change', 'click'].forEach(function (ev) { page.addEventListener(ev, later, true); });
      window.addEventListener('beforeunload', function (e) {
        if (window.themeIsDirty && window.themeIsDirty()) { e.preventDefault(); e.returnValue = ''; }
      });
    })();

    // Mark as initialized
    // Save theme settings (colors, layout, logo shape/size)
    var saveThemeBtn = document.getElementById('saveWebsiteThemeBtn');
    if (saveThemeBtn && !saveThemeBtn.dataset.handlerAttached) {
      saveThemeBtn.dataset.handlerAttached = '1';
      saveThemeBtn.addEventListener('click', async function() {
        var btn = this;
        var origHtml = btn.innerHTML;
        btn.dataset.saving = '1';
        btn.disabled = true;
        btn.innerHTML = '<span class="loading-spinner"></span> Saving...';

        try {
          var built = collectThemePayload();
          var payload = built.payload, sq = built.sq;

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
            // Reload the live preview so layout options (card design, categories,
            // header) show too; colours/fonts already update as you change them
            if (window.markPreviewSaved) window.markPreviewSaved();
            if (window.refreshPreview) window.refreshPreview();
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
          btn.dataset.saving = '';
          btn.disabled = false;
          btn.innerHTML = origHtml;
          window.updateThemeDirty();
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
        '<td><strong>' + escHtml(c.coupon_code) + '</strong>' + (c.is_milestone == 1 ? ' <span title="Milestone offer" style="font-size:0.75em;background:#fef3c7;color:#b45309;padding:2px 6px;border-radius:6px;">Milestone</span>' : '') + '</td>' +
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
    if (document.getElementById('cpnMilestone')) document.getElementById('cpnMilestone').checked = data.is_milestone == 1;
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
    if (document.getElementById('cpnMilestone')) document.getElementById('cpnMilestone').checked = false;
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
  const isMilestone = !!(document.getElementById('cpnMilestone') && document.getElementById('cpnMilestone').checked);
  if (isMilestone && !(parseFloat(minOrder) > 0)) { showSweetAlert('A milestone offer needs a minimum order amount (e.g. 200).', 'warning'); return; }
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
  formData.append('is_milestone', isMilestone ? '1' : '0');
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
    // no "are you sure": it goes away at once with Undo for a few seconds (assets/js/rg-undo.js)
  rgUndo({
    html: rgUndo.label('Deleted coupon', ''),
    hide: rgUndo.rowOf(window.event, 'tr, .ofx-coupon'),
    request: { url: '../controllers/coupon_operations.php', body: { action: 'delete', id: id } },
    onDone: function () { loadAdminCoupons(); },
    onError: function (msg) { showSweetAlert(msg, 'error'); }
  });
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

// ========== Inventory ==========
let inventoryItemsCache = [];

async function loadInventory() {
  const tbody = document.getElementById('inventoryTbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>';
  try {
    const r = await fetch('../api/get_inventory.php');
    const d = await r.json();
    if (!d.success) { tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#e74c3c;">' + escHtml(d.message || 'Error') + '</td></tr>'; return; }
    inventoryItemsCache = d.items || [];

    const totalItemsEl = document.getElementById('invTotalItems');
    const lowStockEl = document.getElementById('invLowStockCount');
    const totalValueEl = document.getElementById('invTotalValue');
    if (totalItemsEl) totalItemsEl.textContent = d.summary?.total_items || 0;
    if (lowStockEl) lowStockEl.textContent = d.summary?.low_stock_count || 0;
    if (totalValueEl) totalValueEl.textContent = globalCurrencySymbol + parseFloat(d.summary?.total_stock_value || 0).toFixed(2);

    if (!inventoryItemsCache.length) {
      tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;color:#999;">No inventory items yet. Click "+ New Item" to add one.</td></tr>';
    } else {
      tbody.innerHTML = inventoryItemsCache.map(item => {
        const qty = parseFloat(item.quantity_in_stock);
        const cost = parseFloat(item.cost_per_unit);
        const value = parseFloat(item.stock_value);
        const isLow = item.is_low_stock == true || item.is_low_stock == 1;
        const statusClass = isLow ? 'badge badge-danger' : 'badge badge-success';
        const statusText = isLow ? 'Low Stock' : 'OK';
        return '<tr>' +
          '<td><strong>' + escHtml(item.item_name) + '</strong>' + (item.notes ? '<br><span style="font-size:0.8em;color:#888;">' + escHtml(item.notes) + '</span>' : '') + '</td>' +
          '<td>' + escHtml(item.category || '-') + '</td>' +
          '<td>' + escHtml(item.unit) + '</td>' +
          '<td>' + qty.toFixed(2) + '</td>' +
          '<td>' + parseFloat(item.low_stock_threshold).toFixed(2) + '</td>' +
          '<td>' + globalCurrencySymbol + cost.toFixed(2) + '</td>' +
          '<td>' + globalCurrencySymbol + value.toFixed(2) + '</td>' +
          '<td><span class="' + statusClass + '">' + statusText + '</span></td>' +
          '<td class="actions-cell">' +
            '<button class="btn-action-small" onclick="openRestockModal(' + item.id + ')" title="Add stock"><span class="material-symbols-rounded">add_box</span>Restock</button>' +
            '<button class="btn-action-small" onclick="openAdjustModal(' + item.id + ')" title="Adjust or record wastage"><span class="material-symbols-rounded">tune</span>Adjust</button>' +
            '<button class="btn-action-small" onclick="editInventoryItem(' + item.id + ')" title="Edit"><span class="material-symbols-rounded">edit</span></button>' +
            '<button class="btn-action-small delete-btn" onclick="deleteInventoryItem(' + item.id + ')" title="Delete"><span class="material-symbols-rounded">delete</span></button>' +
          '</td>' +
          '</tr>';
      }).join('');
    }
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#e74c3c;">Error loading inventory</td></tr>';
  }
  loadInventoryHistory();
}

async function loadInventoryHistory() {
  const tbody = document.getElementById('inventoryHistoryTbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#999;">Loading...</td></tr>';
  try {
    const r = await fetch('../api/get_inventory_transactions.php');
    const d = await r.json();
    if (!d.success) { tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#e74c3c;">' + escHtml(d.message || 'Error') + '</td></tr>'; return; }
    const txs = d.transactions || [];
    if (!txs.length) { tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#999;">No stock movements yet.</td></tr>'; return; }
    const typeLabels = { restock: 'Restock', wastage: 'Wastage', adjustment: 'Correction' };
    const typeColors = { restock: '#28a745', wastage: '#e74c3c', adjustment: '#f59e0b' };
    tbody.innerHTML = txs.map(tx => {
      const qty = parseFloat(tx.quantity);
      const qtyDisplay = (qty > 0 ? '+' : '') + qty.toFixed(2) + ' ' + escHtml(tx.unit);
      return '<tr>' +
        '<td>' + (tx.created_at ? escHtml(tx.created_at.split(' ')[0]) : '-') + '</td>' +
        '<td>' + escHtml(tx.item_name) + '</td>' +
        '<td><span style="color:' + (typeColors[tx.type] || '#666') + ';font-weight:600;">' + (typeLabels[tx.type] || tx.type) + '</span></td>' +
        '<td>' + qtyDisplay + '</td>' +
        '<td>' + globalCurrencySymbol + parseFloat(tx.cost_per_unit).toFixed(2) + '</td>' +
        '<td>' + globalCurrencySymbol + parseFloat(tx.total_cost).toFixed(2) + '</td>' +
        '<td>' + escHtml(tx.notes || '-') + '</td>' +
        '</tr>';
    }).join('');
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#e74c3c;">Error loading history</td></tr>';
  }
}

function openInventoryModal(data) {
  const modal = document.getElementById('inventoryModal');
  const title = document.getElementById('inventoryModalTitle');
  const idInput = document.getElementById('invId');
  const nameInput = document.getElementById('invName');
  const unitSelect = document.getElementById('invUnit');
  const categoryInput = document.getElementById('invCategory');
  const thresholdInput = document.getElementById('invThreshold');
  const costInput = document.getElementById('invCost');
  const notesInput = document.getElementById('invNotes');
  const saveBtn = document.getElementById('invSaveBtn');
  const hint = document.getElementById('invNewItemHint');
  if (!modal) return;
  if (data && data.id) {
    title.textContent = 'Edit Inventory Item';
    idInput.value = data.id;
    nameInput.value = data.item_name || '';
    unitSelect.value = data.unit || 'unit';
    categoryInput.value = data.category || '';
    thresholdInput.value = data.low_stock_threshold || 0;
    costInput.value = data.cost_per_unit || 0;
    notesInput.value = data.notes || '';
    saveBtn.textContent = 'Update Item';
    if (hint) hint.style.display = 'none';
  } else {
    title.textContent = 'New Inventory Item';
    idInput.value = '';
    nameInput.value = '';
    unitSelect.value = 'kg';
    categoryInput.value = '';
    thresholdInput.value = 0;
    costInput.value = 0;
    notesInput.value = '';
    saveBtn.textContent = 'Save Item';
    if (hint) hint.style.display = 'block';
  }
  modal.style.display = 'block';
  document.body.style.overflow = 'hidden';
  setTimeout(() => { nameInput.focus(); }, 150);
}

async function saveInventoryItem(event) {
  event.preventDefault();
  const id = document.getElementById('invId').value;
  const name = document.getElementById('invName').value.trim();
  const unit = document.getElementById('invUnit').value;
  const category = document.getElementById('invCategory').value.trim();
  const threshold = document.getElementById('invThreshold').value;
  const cost = document.getElementById('invCost').value;
  const notes = document.getElementById('invNotes').value.trim();
  const saveBtn = document.getElementById('invSaveBtn');
  if (!name) { showSweetAlert('Please enter an item name.', 'warning'); return false; }
  const action = id ? 'update' : 'add';
  saveBtn.disabled = true; saveBtn.textContent = 'Saving...';
  const formData = new FormData();
  formData.append('action', action);
  formData.append('item_name', name);
  formData.append('unit', unit);
  formData.append('category', category);
  formData.append('low_stock_threshold', threshold);
  formData.append('cost_per_unit', cost);
  formData.append('notes', notes);
  if (id) formData.append('id', id);
  try {
    const r = await fetch('../controllers/inventory_operations.php', { method: 'POST', body: formData });
    const d = await r.json();
    if (d.success) {
      showSweetAlert(d.message, 'success');
      closeModal('inventoryModal');
      loadInventory();
    } else {
      showSweetAlert(d.message, 'error');
    }
  } catch (e) {
    showSweetAlert('Network error. Please try again.', 'error');
  } finally {
    saveBtn.disabled = false; saveBtn.textContent = id ? 'Update Item' : 'Save Item';
  }
  return false;
}

function editInventoryItem(id) {
  const item = inventoryItemsCache.find(i => i.id == id);
  if (item) openInventoryModal(item);
  else showSweetAlert('Item not found.', 'error');
}

async function deleteInventoryItem(id) {
    // no "are you sure": it goes away at once with Undo for a few seconds (assets/js/rg-undo.js)
  rgUndo({
    html: rgUndo.label('Deleted stock item', ''),
    hide: rgUndo.rowOf(window.event, 'tr'),
    request: { url: '../controllers/inventory_operations.php', body: { action: 'delete', id: id } },
    onDone: function () { loadInventory(); },
    onError: function (msg) { showSweetAlert(msg, 'error'); }
  });
}

function openRestockModal(id) {
  const item = inventoryItemsCache.find(i => i.id == id);
  if (!item) { showSweetAlert('Item not found.', 'error'); return; }
  document.getElementById('rsItemId').value = id;
  document.getElementById('rsItemLabel').textContent = item.item_name + ' (currently ' + parseFloat(item.quantity_in_stock).toFixed(2) + ' ' + item.unit + ' in stock)';
  document.getElementById('rsQuantity').value = '';
  document.getElementById('rsCost').value = item.cost_per_unit || 0;
  document.getElementById('rsDate').value = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().split('T')[0];
  document.getElementById('rsNotes').value = '';
  const modal = document.getElementById('restockModal');
  modal.style.display = 'block';
  document.body.style.overflow = 'hidden';
  setTimeout(() => { document.getElementById('rsQuantity').focus(); }, 150);
}

async function saveRestock(event) {
  event.preventDefault();
  const id = document.getElementById('rsItemId').value;
  const quantity = document.getElementById('rsQuantity').value;
  const cost = document.getElementById('rsCost').value;
  const date = document.getElementById('rsDate').value;
  const notes = document.getElementById('rsNotes').value.trim();
  const saveBtn = document.getElementById('rsSaveBtn');
  if (!quantity || parseFloat(quantity) <= 0) { showSweetAlert('Please enter a valid quantity.', 'warning'); return false; }
  saveBtn.disabled = true; saveBtn.textContent = 'Saving...';
  const formData = new FormData();
  formData.append('action', 'restock');
  formData.append('id', id);
  formData.append('quantity', quantity);
  formData.append('cost_per_unit', cost);
  formData.append('expense_date', date);
  formData.append('notes', notes);
  try {
    const r = await fetch('../controllers/inventory_operations.php', { method: 'POST', body: formData });
    const d = await r.json();
    if (d.success) {
      showSweetAlert(d.message, 'success');
      closeModal('restockModal');
      loadInventory();
    } else {
      showSweetAlert(d.message, 'error');
    }
  } catch (e) {
    showSweetAlert('Network error. Please try again.', 'error');
  } finally {
    saveBtn.disabled = false; saveBtn.textContent = 'Add Stock';
  }
  return false;
}

function openAdjustModal(id) {
  const item = inventoryItemsCache.find(i => i.id == id);
  if (!item) { showSweetAlert('Item not found.', 'error'); return; }
  document.getElementById('adjItemId').value = id;
  document.getElementById('adjItemLabel').textContent = item.item_name + ' (currently ' + parseFloat(item.quantity_in_stock).toFixed(2) + ' ' + item.unit + ' in stock)';
  document.getElementById('adjType').value = 'wastage';
  document.getElementById('adjQuantity').value = '';
  document.getElementById('adjNotes').value = '';
  updateAdjustLabel();
  const modal = document.getElementById('adjustModal');
  modal.style.display = 'block';
  document.body.style.overflow = 'hidden';
  setTimeout(() => { document.getElementById('adjQuantity').focus(); }, 150);
}

function updateAdjustLabel() {
  const type = document.getElementById('adjType').value;
  const label = document.getElementById('adjQtyLabel');
  const qtyInput = document.getElementById('adjQuantity');
  if (!label || !qtyInput) return;
  if (type === 'wastage') {
    label.textContent = 'Quantity Lost *';
    qtyInput.min = '0.01';
  } else {
    label.textContent = 'Quantity Change (+/-) *';
    qtyInput.removeAttribute('min');
  }
}
document.addEventListener('DOMContentLoaded', function() {
  const adjTypeSelect = document.getElementById('adjType');
  if (adjTypeSelect) adjTypeSelect.addEventListener('change', updateAdjustLabel);
  const restockModal = document.getElementById('restockModal');
  if (restockModal) restockModal.addEventListener('click', function(e) { if (e.target === restockModal) closeModal('restockModal'); });
  const adjustModal = document.getElementById('adjustModal');
  if (adjustModal) adjustModal.addEventListener('click', function(e) { if (e.target === adjustModal) closeModal('adjustModal'); });
  const inventoryModal = document.getElementById('inventoryModal');
  if (inventoryModal) inventoryModal.addEventListener('click', function(e) { if (e.target === inventoryModal) closeModal('inventoryModal'); });
});

async function saveAdjust(event) {
  event.preventDefault();
  const id = document.getElementById('adjItemId').value;
  const type = document.getElementById('adjType').value;
  const quantity = document.getElementById('adjQuantity').value;
  const notes = document.getElementById('adjNotes').value.trim();
  const saveBtn = document.getElementById('adjSaveBtn');
  if (!quantity || parseFloat(quantity) === 0) { showSweetAlert('Please enter a quantity.', 'warning'); return false; }
  saveBtn.disabled = true; saveBtn.textContent = 'Saving...';
  const formData = new FormData();
  formData.append('action', 'adjust');
  formData.append('id', id);
  formData.append('adjust_type', type);
  formData.append('quantity', quantity);
  formData.append('notes', notes);
  try {
    const r = await fetch('../controllers/inventory_operations.php', { method: 'POST', body: formData });
    const d = await r.json();
    if (d.success) {
      showSweetAlert(d.message, 'success');
      closeModal('adjustModal');
      loadInventory();
    } else {
      showSweetAlert(d.message, 'error');
    }
  } catch (e) {
    showSweetAlert('Network error. Please try again.', 'error');
  } finally {
    saveBtn.disabled = false; saveBtn.textContent = 'Save';
  }
  return false;
}


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
        + (table.order_timer ? '<div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:10px;margin-bottom:12px;text-align:center;font-size:13px;color:#b91c1c;"><strong>Busy for:</strong> ' + escapeHtml(table.order_timer) + '</div>' : '')
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
window.renderTableMap = async function(force) {
  // Tables › Table map: each area as a floor (its own icon and colour), tables drawn by
  // size (round for 2, square for 4, long for 6+) with chairs, live status, how long a
  // table has been busy, and reserved guests. Refreshes every 15 s while the page is open.
  var container = document.getElementById('tableMapContainer');
  if (!container) return;
  var SXh = window.SX || { esc: escapeHtml, look: function (a, i) { return { icon: 'table_restaurant', color: '#7c3aed' }; } };
  if (!container.children.length || force === true) container.innerHTML = '<div class="sx-loading"><span></span><span></span><span></span></div>';
  if (window._tableMapTimer) clearTimeout(window._tableMapTimer);
  try {
    var res = await fetch('../api/get_table_status.php', { cache: 'no-store' });
    var data = await res.json();
    if (!data.success || !data.data) {
      container.innerHTML = '<div class="sx-empty"><span class="material-symbols-rounded">error</span><b>Could not load the table map</b><span>Please try again.</span></div>';
      return;
    }
    window._tableMapData = data.data;
    var tables = data.data, areas = (data.areas || []).slice();
    if (!tables.length) {
      container.innerHTML = '<div class="sx-empty"><span class="material-symbols-rounded">table_restaurant</span><b>No tables yet</b><span>Add areas and tables first, then your floor shows here.</span>' +
        '<button type="button" class="btn btn-primary" onclick="document.querySelector(\'[data-page=areaPage]\').click()">Open Areas</button></div>';
      return;
    }
    var known = {};
    areas.forEach(function (a) { known[a.id] = 1; });
    if (tables.some(function (t) { return !known[t.area_id]; })) areas.push({ id: -1, area_name: 'No area' });
    var chairs = function (cap, shape) {
      var n = Math.min(cap, 8), out = '';
      for (var i = 0; i < n; i++) {
        var st;
        if (shape === 's2') { var ang = (i / n) * 2 * Math.PI - Math.PI / 2; st = 'left:calc(50% + ' + (Math.cos(ang) * 50).toFixed(1) + '% - 7px);top:calc(50% + ' + (Math.sin(ang) * 50).toFixed(1) + '% - 3.5px);transform:rotate(' + (ang * 180 / Math.PI + 90).toFixed(0) + 'deg)'; }
        else { var half = Math.ceil(n / 2), top = i < half, k = top ? i : i - half, cnt = top ? half : n - half; st = 'left:calc(' + ((k + 1) * 100 / (cnt + 1)).toFixed(1) + '% - 7px);' + (top ? 'top:0' : 'bottom:0'); }
        out += '<i style="' + st + '"></i>';
      }
      return '<span class="tm-chairs">' + out + '</span>';
    };
    var html = areas.map(function (area, ai) {
      var list = tables.filter(function (t) { return area.id === -1 ? !known[t.area_id] : parseInt(t.area_id, 10) === parseInt(area.id, 10); });
      if (!list.length) return '';
      var L = SXh.look({ name: area.area_name, icon: area.icon, color: area.color }, ai);
      var busy = list.filter(function (t) { return t.status === 'occupied'; }).length, free = list.filter(function (t) { return t.status === 'free'; }).length;
      return '<section class="tm-area" style="--c:' + L.color + ';animation-delay:' + (ai * 60) + 'ms"><div class="tm-area-h"><span class="sx-ic material-symbols-rounded">' + L.icon + '</span><b>' + SXh.esc(area.area_name) + '</b>' +
        '<small>' + list.length + ' table' + (list.length > 1 ? 's' : '') + ' · ' + busy + ' busy · ' + free + ' free</small></div><div class="tm-floor">' +
        list.map(function (t) {
          var cap = parseInt(t.capacity, 10) || 4, shape = cap <= 2 ? 's2' : cap <= 4 ? 's4' : cap <= 6 ? 's6' : 's8';
          var st = t.status === 'free' && t.is_available === false ? 'off' : t.status;
          var hours = /(\d+)h/.exec(t.order_timer || ''), stale = st === 'occupied' && hours && +hours[1] >= 6;
          var label = 'Table ' + t.table_number + ', ' + cap + ' seats, ' + (st === 'occupied' ? 'busy ' + (t.order_timer || '') : st === 'reserved' ? 'reserved' + (t.customer_name ? ' for ' + t.customer_name : '') : st === 'off' ? 'switched off' : 'free');
          return '<button type="button" class="tm-t ' + shape + ' ' + st + (stale ? ' stale' : '') + '" onclick="showTableMapDetail(' + t.id + ')" title="' + SXh.esc(label) + (stale ? '. Open for over 6 hours: close its old kitchen ticket.' : '') + '" aria-label="' + SXh.esc(label) + '">' +
            chairs(cap, shape) + (st === 'occupied' ? '<span class="tm-dot"></span>' : '') +
            '<b>' + SXh.esc(t.table_number) + '</b><small><span class="material-symbols-rounded">person</span>' + cap + '</small>' +
            (st === 'occupied' && t.order_timer ? '<span class="tm-badge"><span class="material-symbols-rounded">schedule</span>' + SXh.esc(t.order_timer) + '</span>'
              : st === 'reserved' && t.customer_name ? '<span class="tm-badge"><span class="material-symbols-rounded">event</span>' + SXh.esc(String(t.customer_name).split(' ')[0]) + '</span>' : '') +
          '</button>';
        }).join('') + '</div></section>';
    }).join('');
    container.innerHTML = html;
    var up = document.getElementById('tmUpdated');
    if (up) up.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  } catch (e) {
    console.error('Table map error', e);
    if (!container.querySelector('.tm-area')) container.innerHTML = '<div class="sx-empty"><span class="material-symbols-rounded">wifi_off</span><b>Could not load the table map</b><span>' + escapeHtml(e.message || 'Please try again') + '</span></div>';
  }
  // refresh while the Table map is the open page (and the tab is visible)
  window._tableMapTimer = setTimeout(function tick() {
    var pg = document.getElementById('tableMapPage');
    if (pg && pg.classList.contains('active') && !document.hidden) renderTableMap(); else window._tableMapTimer = setTimeout(tick, 15000);
  }, 15000);
};

})();
