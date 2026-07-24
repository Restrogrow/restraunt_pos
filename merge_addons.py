path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Step 1: Remove the separate Extras/Add-ons card (second pass in renderCart)
old_second_pass_start = "  // Second pass: render add-on items in a separate section"
old_second_pass_end = "  var couponStatusHtml = '';"

start_idx = content.find(old_second_pass_start)
end_idx = content.find(old_second_pass_end)

if start_idx >= 0 and end_idx >= 0 and end_idx > start_idx:
    # Remove from "// Second pass..." to just before "var couponStatusHtml"
    before = content[:start_idx]
    after = content[end_idx:]
    content = before + after
    print("SUCCESS: Removed separate Extras card from renderCart()")
else:
    print("FAILED: Could not find the second pass")
    print(f"start_idx={start_idx}, end_idx={end_idx}")

# Step 2: Update renderGlobalAddons to show both available AND selected addons
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

new_func = '''function renderGlobalAddons() {
  var container = document.getElementById('cartAddonChips');
  if (!container) return;
  var sym = getCurrency();
  var html = '';
  // Show available add-ons to click
  if (addonsList.length > 0) {
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
  }
  // Show already selected addons
  var addedAddons = [];
  var allKeys = Object.keys(cartItems);
  for (var ki = 0; ki < allKeys.length; ki++) {
    var raw = cartItems[allKeys[ki]];
    if (raw && typeof raw === "object" && raw.isAddon) {
      addedAddons.push({ key: allKeys[ki], raw: raw });
    }
  }
  if (addedAddons.length > 0) {
    html += '<div style="border-top:1px solid #f0e8e0;margin:6px 0;padding-top:6px"></div>';
    for (var si = 0; si < addedAddons.length; si++) {
      var item = addedAddons[si];
      var key = item.key;
      var raw = item.raw;
      var qty = raw.qty || 0;
      var price = raw.addonPrice || 0;
      html += '<div style="display:flex;align-items:center;gap:8px;padding:6px 4px;font-size:13px">' +
        '<span style="font-weight:500;color:#e17055;flex:1">' + escapeHtml(raw.addonName || 'Add-on') + '</span>' +
        '<span style="color:#999;font-size:12px">x' + qty + '</span>' +
        '<span style="font-weight:600;color:#2d3436">' + sym + (price * qty).toFixed(2) + '</span>' +
        '<button onclick="deleteItem(\\'' + key + '\\')" style="width:24px;height:24px;border:none;border-radius:4px;background:#fef2f2;color:#e74c3c;cursor:pointer;font-size:12px;display:grid;place-items:center;flex-shrink:0">\\u2715</button>' +
        '</div>';
    }
  }
  if (!html) {
    container.innerHTML = '<div style="font-size:12px;color:#aaa;padding:4px 0">No add-ons available</div>';
    return;
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
    print("SUCCESS: Updated renderGlobalAddons() to show both available and selected addons")
else:
    print("FAILED: Could not find old renderGlobalAddons()")

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)
print("DONE: File written")
