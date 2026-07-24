import re

path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Remove the CSS block
old_css = "/* Global Add-ons */\r\n#cartAddonChips button:hover {\r\n  border-color: #e17055 !important;\r\n  color: #e17055 !important;\r\n}"
new_css = ""
content = content.replace(old_css, new_css)

# 2. Replace the renderGlobalAddons function (buttons -> plain text)
old_func_start = "function renderGlobalAddons() {\r\n  var container = document.getElementById('cartAddonChips');\r\n  if (!container) return;\r\n  if (addonsList.length === 0) {\r\n    container.innerHTML = '<div style=\"font-size:12px;color:#aaa;padding:4px 0\">No add-ons available</div>';\r\n    return;\r\n  }\r\n  var sym = getCurrency();\r\n  var html = '';\r\n  for (var i = 0; i < addonsList.length; i++) {\r\n    var a = addonsList[i];\r\n    if (a.is_available == 0) continue;\r\n    var aName = a.addon_name || a.name || 'Add-on';\r\n    var aPrice = parseFloat(a.addon_price || a.price || 0);\r\n    html += '<button class=\"addon-chip-btn\" data-id=\"' + a.id + '\" data-name=\"' + aName.replace(/\"/g, '&quot;') + '\" data-price=\"' + aPrice + '\" style=\"padding:8px 14px;border:1.5px solid #e0d8d0;border-radius:20px;background:#fff;color:#555;font-size:12px;font-weight:500;font-family:Poppins,sans-serif;cursor:pointer;white-space:nowrap\">' +\r\n      escapeHtml(aName) + ' <span style=\"font-weight:400;opacity:0.8\">+' + sym + aPrice.toFixed(2) + '</span></button>';\r\n  }\r\n  container.innerHTML = html;\r\n  var btns = container.querySelectorAll('.addon-chip-btn');\r\n  for (var bi = 0; bi < btns.length; bi++) {\r\n    (function(btn) {\r\n      btn.addEventListener('click', function() {\r\n        var id = parseInt(this.getAttribute('data-id'));\r\n        var name = this.getAttribute('data-name');\r\n        var price = parseFloat(this.getAttribute('data-price'));\r\n        addGlobalAddon(id, name, price);\r\n      });\r\n    })(btns[bi]);\r\n  }\r\n}\r\n\r\nfunction addGlobalAddon(addonId, addonName, addonPrice) {\r\n  var key = 'addon_' + addonId;\r\n  if (cartItems[key]) {\r\n    if (typeof cartItems[key] === 'object') {\r\n      cartItems[key].qty = (cartItems[key].qty || 0) + 1;\r\n    } else {\r\n      cartItems[key] = { qty: 1, addonName: addonName, addonPrice: addonPrice, isAddon: true };\r\n    }\r\n  } else {\r\n    cartItems[key] = { qty: 1, addonName: addonName, addonPrice: addonPrice, isAddon: true };\r\n  }\r\n  saveCart();\r\n  renderCart();\r\n}"

new_func = """function renderGlobalAddons() {
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
}"""

count = content.count(old_func_start)
print(f"Found renderGlobalAddons+addGlobalAddon block {count} time(s)")

if count > 0:
    content = content.replace(old_func_start, new_func)
    print("Replaced successfully")
else:
    print("Could not find the exact block. Trying alternative approach...")
    # Try to find just the renderGlobalAddons function
    # Find the function definition
    idx = content.find("function renderGlobalAddons()")
    if idx >= 0:
        # Find where addGlobalAddon function ends by looking for "function renderCart()" after it
        next_fn = content.find("\nfunction renderCart()", idx)
        if next_fn >= 0:
            # Remove from renderGlobalAddons to just before renderCart
            before = content[:idx]
            after = content[next_fn:]
            content = before + new_func + "\n\n" + after
            print("Applied via alternative method")

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Done!")
