import sys

path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

edits = []

# === Fix the renderGlobalAddons function ===
# Current code:
# function renderGlobalAddons() {
#   var container = document.getElementById('cartAddonChips');
#   if (!container) return;
#   if (addonsList.length === 0) { ... return; }
#   var sym = getCurrency();
#   var html = '';
#   for (var i = 0; i < addonsList.length; i++) {
#     var a = addonsList[i];
#     if (a.is_available == 0) continue;
#     var aName = a.addon_name || a.name || 'Add-on';
#     var aPrice = parseFloat(a.addon_price || a.price || 0);
#     html += '<button onclick="addGlobalAddon(' + a.id + ',\\'' + aName.replace(/'/g, "\\\'") + '\\',' + aPrice + ')" ...>' +
#       escapeHtml(aName) + ' ...</button>';
#   }
#   container.innerHTML = html;
# }

# Replace with version that uses addEventListener:

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
    html += '<button onclick="addGlobalAddon(' + a.id + ',\\'' + aName.replace(/'/g, "\\\\'") + '\\',' + aPrice + ')" style="padding:8px 14px;border:1.5px solid #e0d8d0;border-radius:20px;background:#fff;color:#555;font-size:12px;font-weight:500;font-family:Poppins,sans-serif;cursor:pointer;white-space:nowrap">' +
      escapeHtml(aName) + ' <span style="font-weight:400;opacity:0.8">+' + sym + aPrice.toFixed(2) + '</span></button>';
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
    html += '<button class="addon-chip-btn" data-id="' + a.id + '" data-name="' + aName.replace(/"/g, '&quot;') + '" data-price="' + aPrice + '" style="padding:8px 14px;border:1.5px solid #e0d8d0;border-radius:20px;background:#fff;color:#555;font-size:12px;font-weight:500;font-family:Poppins,sans-serif;cursor:pointer;white-space:nowrap">' +
      escapeHtml(aName) + ' <span style="font-weight:400;opacity:0.8">+' + sym + aPrice.toFixed(2) + '</span></button>';
  }
  container.innerHTML = html;
  var btns = container.querySelectorAll('.addon-chip-btn');
  for (var bi = 0; bi < btns.length; bi++) {
    (function(btn) {
      btn.addEventListener('click', function() {
        var id = parseInt(this.getAttribute('data-id'));
        var name = this.getAttribute('data-name');
        var price = parseFloat(this.getAttribute('data-price'));
        addGlobalAddon(id, name, price);
      });
    })(btns[bi]);
  }
}'''

if old_func in content:
    content = content.replace(old_func, new_func, 1)
    edits.append('Fixed chip buttons to use addEventListener')
    sys.stdout.write('OK: replaced renderGlobalAddons\n')
else:
    # Try to find the function by a shorter unique string
    idx = content.find('function renderGlobalAddons()')
    if idx >= 0:
        sys.stdout.write('Found function at ' + str(idx) + '\n')
        # Show the first 150 chars after it
        sys.stdout.write('Content: ' + repr(content[idx:idx+150]) + '\n')
    else:
        sys.stdout.write('FAIL: renderGlobalAddons not found\n')

# Write
if edits:
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    sys.stdout.write('DONE: ' + str(len(edits)) + ' edits\n')
else:
    sys.stdout.write('FAIL: no edits\n')
