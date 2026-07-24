path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Find the cart rendering loop that handles addon items vs regular items
# The current code renders both in the same flow. We need to split them.

import re

# Find the section in renderCart that handles isAddon items
# We need to separate the for loop into two passes

old_render_block = """  var totalQty = 0, totalPrice = 0;
  var cartHtml = '';

  for (var k = 0; k < keys.length; k++) {
    var key = keys[k];
    var qty = getCartQty(key);
    if (qty <= 0) continue;
    var raw = cartItems[key];
    
    // Check if this is a standalone add-on item
    if (raw && typeof raw === 'object' && raw.isAddon) {
      var price = raw.addonPrice || 0;
      totalQty += qty;
      totalPrice += price * qty;
      cartHtml +=
        '<div class=\"card card-pad\" id=\"cartCard-' + key.replace('.', '_') + '\">' +
          '<div class=\"cart-item\">' +
            '<div style=\"width:70px;height:70px;border-radius:10px;background:#fdf2f2;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:28px\">\u2795</div>' +
            '<div class=\"item-info\">' +
              '<div class=\"item-name\">' + escapeHtml(raw.addonName || 'Add-on') + '</div>' +
              '<div class=\"item-variant\" style=\"color:#e17055;font-size:11px\">Add-on</div>' +
              '<div class=\"item-price\"><span>QTY: ' + qty + '&nbsp;</span>' + getCurrency() + (price * qty).toFixed(2) + '</div>' +
              '<div class=\"qty-ctrl\">' +
                '<button onclick=\"changeQty(\\'' + key + '\\', -1)\">\u2212</button>' +
                '<span>' + qty + '</span>' +
                '<button onclick=\"changeQty(\\'' + key + '\\', 1)\">+</button>' +
              '</div>' +
            '</div>' +
            '<button class=\"delete-btn\" onclick=\"deleteItem(\\'' + key + '\\')\" title=\"Remove\">\U0001f5d1</button>' +
          '</div>' +
        '</div>';
      continue;
    }
    
    var info = getItemByKey(key);
    if (!info) continue;
    var price = getCartPrice(key);
    totalQty += qty;
    totalPrice += price * qty;

    var varDisplay = '';
    if (raw && typeof raw === 'object' && raw.varName) {
      varDisplay = '<div class=\"item-variant\">' + escapeHtml(raw.varName) + '</div>';
    }
    
    // Show add-ons for this item
    var addonNames = getAddonNamesString(key);
    if (addonNames) {
      varDisplay += '<div class=\"item-variant\" style=\"color:#888;font-size:11px\">' + escapeHtml(addonNames) + '</div>';
    }
    var addonTotal = getAddonTotalForItem(key);
    if (addonTotal > 0) {
      price = price + (addonTotal / qty);
    }

    cartHtml +=
      '<div class=\"card card-pad\" id=\"cartCard-' + key.replace('.', '_') + '\">' +
        '<div class=\"cart-item\">' +
          '<img src=\"' + getImageUrl(info.item_image || info.image) + '\" alt=\"' + escapeHtml(info.item_name_translated || info.item_name_en || info.name) + '\" loading=\"lazy\">' +
          '<div class=\"item-info\">' +
            '<div class=\"item-name\">' + escapeHtml(info.item_name_translated || info.item_name_en || info.name) + '</div>' +
            varDisplay +
            '<div class=\"item-price\"><span>QTY: ' + qty + '&nbsp;</span>' + getCurrency() + (price * qty).toFixed(2) + '</div>' +
            '<div class=\"qty-ctrl\">' +
              '<button onclick=\"changeQty(\\'' + key + '\\', -1)\">\u2212</button>' +
              '<span>' + qty + '</span>' +
              '<button onclick=\"changeQty(\\'' + key + '\\', 1)\">+</button>' +
            '</div>' +
          '</div>' +
          '<button class=\"delete-btn\" onclick=\"deleteItem(\\'' + key + '\\')\" title=\"Remove\">\U0001f5d1</button>' +
        '</div>' +
      '</div>';
  }"""

new_render_block = """  var totalQty = 0, totalPrice = 0;
  var cartHtml = '';
  var addonKeys = [];

  // First pass: render regular items + collect addon keys
  for (var k = 0; k < keys.length; k++) {
    var key = keys[k];
    var qty = getCartQty(key);
    if (qty <= 0) continue;
    var raw = cartItems[key];
    
    // Skip add-on items - they get their own section
    if (raw && typeof raw === 'object' && raw.isAddon) {
      addonKeys.push(key);
      continue;
    }
    
    var info = getItemByKey(key);
    if (!info) continue;
    var price = getCartPrice(key);
    totalQty += qty;
    totalPrice += price * qty;

    var varDisplay = '';
    if (raw && typeof raw === 'object' && raw.varName) {
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

    cartHtml +=
      '<div class="card card-pad" id="cartCard-' + key.replace('.', '_') + '">' +
        '<div class="cart-item">' +
          '<img src="' + getImageUrl(info.item_image || info.image) + '" alt="' + escapeHtml(info.item_name_translated || info.item_name_en || info.name) + '" loading="lazy">' +
          '<div class="item-info">' +
            '<div class="item-name">' + escapeHtml(info.item_name_translated || info.item_name_en || info.name) + '</div>' +
            varDisplay +
            '<div class="item-price"><span>QTY: ' + qty + '&nbsp;</span>' + getCurrency() + (price * qty).toFixed(2) + '</div>' +
            '<div class="qty-ctrl">' +
              '<button onclick="changeQty(\\'' + key + '\\', -1)">\u2212</button>' +
              '<span>' + qty + '</span>' +
              '<button onclick="changeQty(\\'' + key + '\\', 1)">+</button>' +
            '</div>' +
          '</div>' +
          '<button class="delete-btn" onclick="deleteItem(\\'' + key + '\\')" title="Remove">\U0001f5d1</button>' +
        '</div>' +
      '</div>';
  }

  // Second pass: render add-on items in a separate section
  if (addonKeys.length > 0) {
    cartHtml += '<div class="card card-pad" style="border:1.5px solid #f0e0d8">' +
      '<div class="coupon-header" style="background:#fdf8f5;border-bottom:1px solid #f0e0d0">' +
        '<div class="icon-wrap" style="background:linear-gradient(135deg,#e17055,#d63031);">+</div>' +
        '<div class="header-text">' +
          '<strong>Extras / Add-ons</strong>' +
          '<span>Items added from add-ons</span>' +
        '</div>' +
      '</div>';
    for (var ai = 0; ai < addonKeys.length; ai++) {
      var key = addonKeys[ai];
      var qty = getCartQty(key);
      if (qty <= 0) continue;
      var raw = cartItems[key];
      var price = raw.addonPrice || 0;
      totalQty += qty;
      totalPrice += price * qty;
      cartHtml +=
        '<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #f5f0ec">' +
          '<div style="width:36px;height:36px;border-radius:8px;background:#fef3f0;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px;color:#e17055">+</div>' +
          '<div style="flex:1;min-width:0">' +
            '<div style="font-weight:500;font-size:13px;color:#444">' + escapeHtml(raw.addonName || 'Add-on') + '</div>' +
            '<div style="font-size:11px;color:#e17055;font-weight:500;margin-top:1px">Extras</div>' +
          '</div>' +
          '<div style="text-align:right;flex-shrink:0">' +
            '<div style="font-weight:600;font-size:13px;color:#2d3436">' + getCurrency() + (price * qty).toFixed(2) + '</div>' +
            '<div class="qty-ctrl" style="margin-top:4px;display:inline-flex">' +
              '<button onclick="changeQty(\\'' + key + '\\', -1)" style="width:24px;height:24px;font-size:14px">\u2212</button>' +
              '<span style="min-width:18px;font-size:12px">' + qty + '</span>' +
              '<button onclick="changeQty(\\'' + key + '\\', 1)" style="width:24px;height:24px;font-size:14px">+</button>' +
            '</div>' +
          '</div>' +
          '<button onclick="deleteItem(\\'' + key + '\\')" style="width:30px;height:30px;border:none;border-radius:6px;background:#fef2f2;color:#e74c3c;cursor:pointer;font-size:14px;flex-shrink:0;display:grid;place-items:center">\u2715</button>' +
        '</div>';
    }
    cartHtml += '</div>';
  }"""

if old_render_block in content:
    content = content.replace(old_render_block, new_render_block)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print("SUCCESS: Add-on items now render in separate section")
else:
    print("FAILED: Could not find the exact render block")
    # Debug: find approximate location
    idx1 = content.find("if (raw && typeof raw === 'object' && raw.isAddon)")
    idx2 = content.find("cartHtml += '</div>';\n  }")
    if idx1 >= 0 and idx2 >= 0 and idx2 > idx1:
        print(f"Found isAddon at {idx1}, found close at {idx2}")
        # Print surrounding text for debugging
        print("Context around isAddon:")
        print(repr(content[idx1-50:idx1+100]))
        print("Context around block end:")
        print(repr(content[idx2-50:idx2+100]))
