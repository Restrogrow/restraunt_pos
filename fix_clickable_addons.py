path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Replace renderGlobalAddons with clickable version using addEventListener (no inline onclick)
old_func = '''function renderGlobalAddons() {
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
    html += '<div style="display:flex;align-items:center;gap:6px;padding:4px 0;font-size:13px;color:#555">' +
      '<span style="color:#e17055;font-weight:500">+ ' + escapeHtml(aName) + '</span>' +
      '<span style="color:#999;font-size:12px">(' + sym + aPrice.toFixed(2) + ')</span>' +
      '</div>';
  }
  container.innerHTML = html;
}'''

new_func = '''function renderGlobalAddons() {
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
    var id = a.id;
    html += '<div class="addon-row" data-addon-id="' + id + '" style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;margin:2px 0;border-radius:8px;cursor:pointer;transition:background 0.15s;font-size:13px">' +
      '<span style="font-weight:500;color:#444">' + escapeHtml(aName) + '</span>' +
      '<span style="color:#e17055;font-weight:600">+' + sym + aPrice.toFixed(2) + '</span>' +
      '</div>';
  }
  container.innerHTML = html;
  var rows = container.querySelectorAll('.addon-row');
  for (var ri = 0; ri < rows.length; ri++) {
    rows[ri].addEventListener('click', function() {
      var id = parseInt(this.getAttribute('data-addon-id'));
      var a = null;
      for (var j = 0; j < addonsList.length; j++) {
        if (addonsList[j].id == id) { a = addonsList[j]; break; }
      }
      if (!a) return;
      var aName = a.addon_name || a.name || 'Add-on';
      var aPrice = parseFloat(a.addon_price || a.price || 0);
      addGlobalAddon(id, aName, aPrice);
    });
  }
}'''

if old_func in content:
    content = content.replace(old_func, new_func)
else:
    print("FAILED: Could not find renderGlobalAddons function text")
    # Try finding by function name
    import re
    idx = content.find("function renderGlobalAddons()")
    if idx >= 0:
        next_fn = content.find("\nfunction renderCart()", idx)
        if next_fn >= 0:
            # Find the exact text to replace
            old_text = content[idx:next_fn]
            print("Found renderGlobalAddons at", idx, "to", next_fn)
            content = content[:idx] + new_func + "\n\n" + content[next_fn:]

# Also add addGlobalAddon function if it's not already there
if 'function addGlobalAddon' not in content:
    print("addGlobalAddon not found, adding it...")
    addon_fn = """
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
  showToast('Added ' + addonName + ' to cart', 'success');
}
"""
    # Insert before renderCart function
    idx = content.find("\nfunction renderCart()")
    if idx >= 0:
        content = content[:idx] + addon_fn + "\n" + content[idx:]

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)
print("DONE: renderGlobalAddons updated with clickable rows")
