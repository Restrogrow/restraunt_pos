import re

path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

changes = 0

# 1. Add addon helper functions after getCartQty function
old1 = """function getCartQty(key) {
  var raw = cartItems[key];
  if (typeof raw === 'number') return raw;
  if (raw && typeof raw === 'object') return raw.qty || 0;
  return 0;
}

function renderCart()"""

new1 = """function getCartQty(key) {
  var raw = cartItems[key];
  if (typeof raw === 'number') return raw;
  if (raw && typeof raw === 'object') return raw.qty || 0;
  return 0;
}

// --- Add-ons Helper Functions ---
function getAddonsForItemKey(key) {
  try { var s = localStorage.getItem('_itemAddons'); var all = s ? JSON.parse(s) : {}; return all[key] || []; } catch(e){return [];}
}
function getAddonTotalForItem(key) {
  var addons = getAddonsForItemKey(key);
  var total = 0;
  for (var a = 0; a < addons.length; a++) { total += parseFloat(addons[a].price || 0); }
  return total;
}
function getAddonNamesString(key) {
  var addons = getAddonsForItemKey(key);
  var names = [];
  for (var a = 0; a < addons.length; a++) { names.push(addons[a].name); }
  return names.length ? '+ ' + names.join(', ') : '';
}
function clearAddonsForItem(key) {
  try { var s = localStorage.getItem('_itemAddons'); var all = s ? JSON.parse(s) : {}; delete all[key]; localStorage.setItem('_itemAddons', JSON.stringify(all)); } catch(e){}
}
// --- End Add-ons Helpers ---

function renderCart()"""

if old1 in content:
    content = content.replace(old1, new1)
    changes += 1
    print(f"1. Added add-on helpers - YES")
else:
    print(f"1. Added add-on helpers - NO (pattern not found)")

# 2. Add clearAddonsForItem call in deleteItem
old2 = """function deleteItem(key) {
  delete cartItems[key];
  saveCart();
  renderCart();
}"""

new2 = """function deleteItem(key) {
  clearAddonsForItem(key);
  delete cartItems[key];
  saveCart();
  renderCart();
}"""

if old2 in content:
    content = content.replace(old2, new2)
    changes += 1
    print(f"2. Fixed deleteItem - YES")
else:
    print(f"2. Fixed deleteItem - NO")

# 3. Add addon display in renderCart item loop
old3 = """    if (raw && typeof raw === 'object' && raw.varName) {
      varDisplay = '<div class="item-variant">' + escapeHtml(raw.varName) + '</div>';
    }

    cartHtml +="""

new3 = """    if (raw && typeof raw === 'object' && raw.varName) {
      varDisplay = '<div class="item-variant">' + escapeHtml(raw.varName) + '</div>';
    }
    
    // Show add-ons for this item
    var addonNames = getAddonNamesString(key);
    if (addonNames) {
      varDisplay += '<div class="item-variant" style="color:#888;font-size:11px">' + escapeHtml(addonNames) + '</div>';
    }
    var addonTotal = getAddonTotalForItem(key);
    if (addonTotal > 0) {
      price = price + (addonTotal / qty);
    }

    cartHtml +="""

if old3 in content:
    content = content.replace(old3, new3)
    changes += 1
    print(f"3. Addon display in renderCart - YES")
else:
    print(f"3. Addon display in renderCart - NO")
    # Try with \r\n endings
    old3_b = """    if (raw && typeof raw === 'object' && raw.varName) {\r\n      varDisplay = '<div class="item-variant">' + escapeHtml(raw.varName) + '</div>';\r\n    }\r\n\r\n    cartHtml +="""
    new3_b = """    if (raw && typeof raw === 'object' && raw.varName) {\r\n      varDisplay = '<div class="item-variant">' + escapeHtml(raw.varName) + '</div>';\r\n    }\r\n    \r\n    // Show add-ons for this item\r\n    var addonNames = getAddonNamesString(key);\r\n    if (addonNames) {\r\n      varDisplay += '<div class="item-variant" style="color:#888;font-size:11px">' + escapeHtml(addonNames) + '</div>';\r\n    }\r\n    var addonTotal = getAddonTotalForItem(key);\r\n    if (addonTotal > 0) {\r\n      price = price + (addonTotal / qty);\r\n    }\r\n\r\n    cartHtml +="""
    if old3_b in content:
        content = content.replace(old3_b, new3_b)
        changes += 1
        print(f"3b. Addon display in renderCart (CRLF) - YES")
    else:
        print(f"3b. Addon display in renderCart (CRLF) - NO")

with open(path, 'w', encoding='utf-8', newline='') as f:
    f.write(content)

print(f"\nTotal changes: {changes}")
