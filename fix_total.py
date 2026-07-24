path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Find where the first pass ends and add a loop to calculate addon totals
# The first pass ends just before "var couponStatusHtml"
old = """  }

  var couponStatusHtml = '';"""

new = """  }

  // Add addon item prices to total
  for (var ai = 0; ai < addonKeys.length; ai++) {
    var key = addonKeys[ai];
    var qty = getCartQty(key);
    if (qty <= 0) continue;
    var raw = cartItems[key];
    var price = raw.addonPrice || 0;
    totalQty += qty;
    totalPrice += price * qty;
    // Also render the addon row in the cart
    cartHtml +=
      '<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1.5px solid #f0e0d8;border-radius:14px;margin-bottom:8px">' +
        '<div style="width:36px;height:36px;border-radius:8px;background:#fef3f0;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px;color:#e17055">+</div>' +
        '<div style="flex:1;min-width:0">' +
          '<div style="font-weight:500;font-size:13px;color:#444">' + escapeHtml(raw.addonName || 'Add-on') + '</div>' +
          '<div style="font-size:11px;color:#e17055;font-weight:500;margin-top:1px">Extras</div>' +
        '</div>' +
        '<div style="text-align:right;flex-shrink:0">' +
          '<div style="font-weight:600;font-size:13px;color:#2d3436">' + getCurrency() + (price * qty).toFixed(2) + '</div>' +
          '<div class="qty-ctrl" style="margin-top:4px;display:inline-flex">' +
            '<button onclick="changeQty(\\'' + key + '\\', -1)" style="width:24px;height:24px;font-size:14px">-</button>' +
            '<span style="min-width:18px;font-size:12px">' + qty + '</span>' +
            '<button onclick="changeQty(\\'' + key + '\\', 1)" style="width:24px;height:24px;font-size:14px">+</button>' +
          '</div>' +
        '</div>' +
        '<button onclick="deleteItem(\\'' + key + '\\')" style="width:30px;height:30px;border:none;border-radius:6px;background:#fef2f2;color:#e74c3c;cursor:pointer;font-size:14px;flex-shrink:0;display:grid;place-items:center">\\u2715</button>' +
      '</div>';
  }

  var couponStatusHtml = '';"""

if old in content:
    content = content.replace(old, new, 1)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print("SUCCESS: Addon totals and cart rendering restored")
else:
    print("FAILED: Could not find the target string")
