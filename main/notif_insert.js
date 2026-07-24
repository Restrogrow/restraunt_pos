const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, 'assets', 'js', 'script.js');
let content = fs.readFileSync(filePath, 'utf8');
const originalLen = content.length;

// Find insertion point: after the line that sets badge poll and its closing brace
const searchStr = 'window.pendingOrderBadgePoll = setInterval(fetchPendingCount, 30000);';

const idx = content.indexOf(searchStr);
if (idx === -1) {
  console.log('✗ Could not find insertion point');
  process.exit(1);
}

// Find the end of the if block - look for "      }" that comes after our marker
// and before "      // Load Orders if it's the Orders page"
const afterMarker = content.substring(idx);
const loadOrdersIdx = afterMarker.indexOf('      // Load Orders if it\'s the Orders page');
if (loadOrdersIdx === -1) {
  console.log('✗ Could not find Load Orders marker');
  process.exit(1);
}

// The closing brace should be a line with just spaces and "}" before Load Orders
// Find the last occurrence of "      }" before Load Orders
const blockToSearch = afterMarker.substring(0, loadOrdersIdx);
const closeBraceIdx = blockToSearch.lastIndexOf('\n      }\n');

if (closeBraceIdx === -1) {
  console.log('✗ Could not find closing brace');
  process.exit(1);
}

// Insert point is AFTER the closing brace + newlines
const insertPoint = idx + closeBraceIdx + '\n      }\n'.length;
const before = content.substring(0, insertPoint);
const after = content.substring(insertPoint);

const jsCode = `      // New order popup polling - every 10 seconds, independent of badge poll\n      if (!window._newOrderPollInterval) {\n        window._lastSeenOrderId = 0;\n        window._currentNotifOrderId = null;\n        window._overlayProcessing = false;\n        \n        async function checkNewPendingOrder() {\n          try {\n            const overlay = document.getElementById('newOrderOverlay');\n            if (overlay && overlay.classList.contains('show')) return;\n            if (window._overlayProcessing) return;\n            \n            const r = await fetch('../api/get_latest_pending_order.php', { cache: 'no-store' });\n            if (!r.ok) return;\n            const d = await r.json();\n            if (d.success && d.order) {\n              const orderId = parseInt(d.order.id);\n              if (orderId > window._lastSeenOrderId) {\n                window._lastSeenOrderId = orderId;\n                window._currentNotifOrderId = orderId;\n                showNewOrderPopup(d.order);\n              }\n            } else if (d.success && !d.order) {\n              window._lastSeenOrderId = 0;\n            }\n          } catch(e) {}\n        }\n        \n        setTimeout(checkNewPendingOrder, 2000);\n        window._newOrderPollInterval = setInterval(checkNewPendingOrder, 10000);\n      }\n\n      function showNewOrderPopup(order) {\n        var overlay = document.getElementById('newOrderOverlay');\n        if (!overlay) return;\n        \n        try { playNotificationSound(); } catch(e) {}\n        \n        var e = document.getElementById('notifOrderNumber');\n        if (e) e.textContent = 'Order #' + (order.order_number || order.id);\n        \n        e = document.getElementById('notifCustomerName');\n        if (e) e.textContent = order.customer_name || 'Guest';\n        \n        e = document.getElementById('notifCustomerPhone');\n        if (e) e.textContent = order.customer_phone || '-';\n        \n        var addrRow = document.getElementById('notifAddressRow');\n        var addrEl = document.getElementById('notifCustomerAddress');\n        if (addrRow && addrEl) {\n          if (order.customer_address && order.order_type === 'delivery') {\n            addrRow.style.display = 'flex';\n            addrEl.textContent = order.customer_address;\n          } else {\n            addrRow.style.display = 'none';\n          }\n        }\n        \n        e = document.getElementById('notifOrderType');\n        if (e) {\n          var s = (order.order_type || 'N/A').replace(/_/g, ' ');\n          e.textContent = s.charAt(0).toUpperCase() + s.slice(1);\n        }\n        \n        e = document.getElementById('notifPaymentInfo');\n        if (e) e.textContent = (order.payment_method || 'N/A') + ' (' + (order.payment_status || 'N/A') + ')';\n        \n        var itemsEl = document.getElementById('notifItemsList');\n        if (itemsEl) {\n          var html = '';\n          if (order.items && order.items.length > 0) {\n            for (var i = 0; i < order.items.length; i++) {\n              var item = order.items[i];\n              var n = item.item_name || '';\n              var d = document.createElement('div');\n              d.textContent = n;\n              html += '<div class=\"order-item-row\"><div><span class=\"item-name\">' + d.innerHTML + '</span> <span class=\"item-qty\">x' + (item.quantity || 1) + '</span></div><span class=\"item-price\">' + (window.globalCurrencySymbol || '₹') + parseFloat(item.total_price || 0).toFixed(2) + '</span></div>';\n            }\n          } else {\n            html = '<div style=\"text-align:center;color:#999;padding:8px;\">' + (order.item_count || 0) + ' item(s)</div>';\n          }\n          itemsEl.innerHTML = html;\n        }\n        \n        e = document.getElementById('notifTotalAmount');\n        if (e) e.textContent = (window.globalCurrencySymbol || '₹') + parseFloat(order.total || order.subtotal || 0).toFixed(2);\n        \n        e = document.getElementById('notifOrderTime');\n        if (e) {\n          try {\n            var dt = new Date(order.created_at);\n            if (!isNaN(dt.getTime())) e.textContent = 'Received ' + dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });\n          } catch(ee) {}\n        }\n        \n        var accBtn = document.getElementById('notifAcceptBtn');\n        var rejBtn = document.getElementById('notifRejectBtn');\n        if (accBtn) { accBtn.disabled = false; accBtn.innerHTML = '<span class=\"material-symbols-rounded\" style=\"font-size:18px;\">check_circle</span> Accept'; }\n        if (rejBtn) { rejBtn.disabled = false; rejBtn.innerHTML = '<span class=\"material-symbols-rounded\" style=\"font-size:18px;\">cancel</span> Reject'; }\n        \n        overlay.classList.add('show');\n        document.body.style.overflow = 'hidden';\n      }\n\n      window.closeNewOrderOverlay = function() {\n        var overlay = document.getElementById('newOrderOverlay');\n        if (overlay) {\n          overlay.classList.remove('show');\n          document.body.style.overflow = '';\n        }\n        window._currentNotifOrderId = null;\n      };\n\n      window.acceptNewOrder = async function() {\n        var orderId = window._currentNotifOrderId;\n        if (!orderId) return;\n        window._overlayProcessing = true;\n        var btn = document.getElementById('notifAcceptBtn');\n        var rejBtn = document.getElementById('notifRejectBtn');\n        if (btn) { btn.disabled = true; btn.innerHTML = '<span class=\"loading-spinner\"></span> Accepting...'; }\n        if (rejBtn) rejBtn.disabled = true;\n        try {\n          var r = await fetch('../api/update_order_status.php', {\n            method: 'POST',\n            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },\n            body: 'orderId=' + orderId + '&status=Accepted'\n          });\n          var d = await r.json();\n          window.closeNewOrderOverlay();\n          if (typeof showNotification === 'function') showNotification(d.success ? 'Order accepted!' : (d.message || 'Failed'), d.success ? 'success' : 'error');\n          if (typeof showPage === 'function') showPage('onlineOrdersPage');\n        } catch(e) {\n          if (typeof showNotification === 'function') showNotification('Network error', 'error');\n        } finally {\n          window._overlayProcessing = false;\n        }\n      };\n\n      window.rejectNewOrder = async function() {\n        var orderId = window._currentNotifOrderId;\n        if (!orderId) return;\n        var reason = '';\n        if (window.Swal) {\n          var result = await Swal.fire({\n            title: 'Reject Order',\n            text: 'Why are you rejecting this order?',\n            input: 'textarea',\n            inputPlaceholder: 'e.g. Item unavailable, Restaurant closed...',\n            showCancelButton: true,\n            confirmButtonText: 'Reject',\n            confirmButtonColor: '#ef4444',\n            cancelButtonText: 'Cancel',\n            inputValidator: function(v) { if (!v) return 'Please enter a reason'; }\n          });\n          if (!result.isConfirmed) return;\n          reason = result.value;\n        } else {\n          reason = (window.prompt('Why are you rejecting this order?', '') || 'Rejected');\n        }\n        window._overlayProcessing = true;\n        var btn = document.getElementById('notifRejectBtn');\n        var accBtn = document.getElementById('notifAcceptBtn');\n        if (btn) { btn.disabled = true; btn.innerHTML = '<span class=\"loading-spinner\"></span> Rejecting...'; }\n        if (accBtn) accBtn.disabled = true;\n        try {\n          var r = await fetch('../api/update_order_status.php', {\n            method: 'POST',\n            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },\n            body: 'orderId=' + orderId + '&status=Rejected&reason=' + encodeURIComponent(reason)\n          });\n          var d = await r.json();\n          window.closeNewOrderOverlay();\n          if (typeof showNotification === 'function') showNotification(d.success ? 'Order rejected' : (d.message || 'Failed'), d.success ? 'info' : 'error');\n          if (typeof showPage === 'function') showPage('onlineOrdersPage');\n        } catch(e) {\n          if (typeof showNotification === 'function') showNotification('Network error', 'error');\n        } finally {\n          window._overlayProcessing = false;\n        }\n      };\n`;

// Insert the code at the right position
const result = before + '\n' + jsCode + after;
fs.writeFileSync(filePath, result, 'utf8');

// Verify
const newLen = result.length;
console.log('Original size: ' + originalLen);
console.log('New size: ' + newLen);
console.log('Added: ' + (newLen - originalLen) + ' chars');

// Check for syntax errors
try {
  new Function(result);
  console.log('✓ No syntax errors');
} catch(e) {
  console.log('✗ Syntax error:', e.message);
  // Try to find the approximate location
  var m = e.stack.match(/eval:(\d+)/);
  if (m) {
    var lineNum = parseInt(m[1]);
    var lines = result.split('\n');
    console.log('Around line ' + lineNum + ':');
    for (var i = Math.max(0, lineNum - 3); i < Math.min(lines.length, lineNum + 3); i++) {
      console.log((i+1) + ': ' + lines[i].substring(0, 130));
    }
  }
}

if (result.includes('_newOrderPollInterval') && result.includes('showNewOrderPopup') && result.includes('closeNewOrderOverlay') && result.includes('acceptNewOrder')) {
  console.log('✓ All functions present');
} else {
  console.log('✗ Some functions missing');
}
