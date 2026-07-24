import sys

path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# === 1. Remove the per-item JS block ===
# Find "// --- Cart Add-ons Selection ---" and remove everything until "// --- End Cart Add-ons Selection ---"
start_marker = '// --- Cart Add-ons Selection ---\r\n'
end_marker = '// --- End Cart Add-ons Selection ---\r\n'

start_pos = content.find(start_marker)
if start_pos >= 0:
    end_pos = content.find(end_marker, start_pos)
    if end_pos >= 0:
        # Remove from start to after the end marker line
        block_end = content.find('\n', end_pos) + 1
        if block_end == 0:
            block_end = end_pos + len(end_marker)
        old_block = content[start_pos:block_end]
        content = content.replace(old_block, '', 1)
        sys.stdout.write('OK1: removed per-item JS block\n')
    else:
        # Try without \r
        start_marker2 = '// --- Cart Add-ons Selection ---\n'
        end_marker2 = '// --- End Cart Add-ons Selection ---\n'
        start_pos2 = content.find(start_marker2)
        if start_pos2 >= 0:
            end_pos2 = content.find(end_marker2, start_pos2)
            if end_pos2 >= 0:
                block_end2 = content.find('\n', end_pos2) + 1
                if block_end2 == 0:
                    block_end2 = end_pos2 + len(end_marker2)
                old_block = content[start_pos2:block_end2]
                content = content.replace(old_block, '', 1)
                sys.stdout.write('OK1b: removed per-item JS block (no CR)\n')
            else:
                sys.stdout.write('FAIL1: end marker not found\n')
        else:
            sys.stdout.write('FAIL1: start marker not found\n')
else:
    sys.stdout.write('FAIL1: start marker not found with CR\n')

# === 2. Add simplified global functions ===
# Find the end of // --- End Add-ons Helpers --- and insert after it
helpers_end = '// --- End Add-ons Helpers ---\r\n'
new_funcs = '''// --- End Add-ons Helpers ---

var addonsList = [];

function fetchAddons() {
  var rid = window.websiteRestaurantId || document.querySelector('meta[name="restaurant-id"]')?.content || 'RES001';
  fetch('api.php?restaurant_id=' + encodeURIComponent(rid) + '&action=getAddons')
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success && data.data) {
        addonsList = data.data;
        renderGlobalAddons();
      }
    })
    .catch(function() {});
}

function renderGlobalAddons() {
  var container = document.getElementById('cartAddonChips');
  if (!container) return;
  if (addonsList.length === 0) {
    container.innerHTML = '<div style="font-size:12px;color:#aaa;padding:4px 0">No add-ons available</div>';
    return;
  }
  var sym = getCurrency();
  var html = '';
  for (var i = 0; i < addonsList.length; i++) {
    var a = addonsList[i];
    if (a.is_available == 0) continue;
    var aName = a.addon_name || a.name || 'Add-on';
    var aPrice = parseFloat(a.addon_price || a.price || 0);
    html += '<button onclick="addGlobalAddon(' + a.id + ',\\'' + aName.replace(/'/g, "\\\\'") + '\\',' + aPrice + ')" style="padding:8px 14px;border:1.5px solid #e0d8d0;border-radius:20px;background:#fff;color:#555;font-size:12px;font-weight:500;font-family:Poppins,sans-serif;cursor:pointer;white-space:nowrap">' +
      escapeHtml(aName) + ' <span style="font-weight:400;opacity:0.8">+' + sym + aPrice.toFixed(2) + '</span></button>';
  }
  container.innerHTML = html;
}

function addGlobalAddon(addonId, addonName, addonPrice) {
  var key = 'addon_' + addonId;
  if (cartItems[key]) {
    if (typeof cartItems[key] === 'object') {
      cartItems[key].qty = (cartItems[key].qty || 0) + 1;
    } else {
      cartItems[key] = { qty: 1, addonName: addonName, addonPrice: addonPrice, isAddon: true };
    }
  } else {
    cartItems[key] = { qty: 1, addonName: addonName, addonPrice: addonPrice, isAddon: true };
  }
  saveCart();
  renderCart();
}

'''

if helpers_end in content:
    content = content.replace(helpers_end, new_funcs, 1)
    sys.stdout.write('OK2: added simplified global functions\n')
else:
    # Try without \r
    helpers_end2 = '// --- End Add-ons Helpers ---\n'
    if helpers_end2 in content:
        content = content.replace(helpers_end2, new_funcs.replace('\r\n', '\n'), 1)
        sys.stdout.write('OK2b: added simplified global functions (no CR)\n')
    else:
        sys.stdout.write('FAIL2: helpers end not found\n')

# Write
with open(path, 'w', encoding='utf-8') as f:
    f.write(content)
sys.stdout.write('DONE: file written\n')
